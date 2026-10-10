<?php
/**
 * The trash flow's units of work.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Trash;

use HierarchyGuard\Ledger;
use HierarchyGuard\Subtree;
use WP_Post;

/**
 * Moves, trashes and restores subpages in bounded steps. A job is a plain
 * array, so the same step runs inline for small subtrees and from the batch
 * queue for large ones. Each step takes two budgets: raw moves, which are
 * cheap, and core trash or untrash calls, which run every plugin's save hooks.
 */
final class Operations {

	public const TYPE_REPARENT = 'reparent';
	public const TYPE_TRASH    = 'trash_children';
	public const TYPE_RESTORE  = 'restore';
	public const TYPE_CLEANUP  = 'cleanup';

	public const DEFAULT_THRESHOLD      = 200;
	public const DEFAULT_CORE_THRESHOLD = 25;
	public const MAX_REMEMBERED         = 200;

	/**
	 * A move younger than this is taken back without recording its interim URL:
	 * nobody has had time to link to it.
	 */
	public const INTERIM_AGE = HOUR_IN_SECONDS;

	/**
	 * Ledger.
	 *
	 * @var Ledger
	 */
	private Ledger $ledger;

	/**
	 * Mover.
	 *
	 * @var Mover
	 */
	private Mover $mover;

	/**
	 * Constructor.
	 *
	 * @param Ledger $ledger Ledger.
	 * @param Mover  $mover  Mover.
	 */
	public function __construct( Ledger $ledger, Mover $mover ) {
		$this->ledger = $ledger;
		$this->mover  = $mover;
	}

	/**
	 * Raw moves per step.
	 *
	 * @return int
	 */
	public static function threshold(): int {
		/**
		 * Filters how many subpages one step moves with a direct update.
		 *
		 * @param int $threshold Moves per step, 200 by default.
		 */
		return max( 1, (int) apply_filters( 'hierarchy_guard_batch_threshold', self::DEFAULT_THRESHOLD ) );
	}

	/**
	 * Core trash and untrash calls per step.
	 *
	 * @return int
	 */
	public static function core_threshold(): int {
		/**
		 * Filters how many subpages one step trashes or restores through core,
		 * which runs every plugin's save hooks for each of them.
		 *
		 * @param int $threshold Core calls per step, 25 by default.
		 */
		return max( 1, (int) apply_filters( 'hierarchy_guard_core_call_threshold', self::DEFAULT_CORE_THRESHOLD ) );
	}

	/**
	 * A fresh job.
	 *
	 * @param string              $type      One of the TYPE_ constants.
	 * @param int                 $root_id   The page being trashed, restored or deleted.
	 * @param string              $post_type Post type of the subtree.
	 * @param int                 $user_id   User whose capabilities apply, 0 for system contexts.
	 * @param array<string,mixed> $args      Optional: to, policy, fallback, paths, urls, total.
	 * @return array<string,mixed>
	 */
	public static function new_job( string $type, int $root_id, string $post_type, int $user_id, array $args = [] ): array {
		$urls = (array) ( $args['urls'] ?? [] );

		return [
			'id'        => wp_generate_uuid4(),
			'type'      => $type,
			'root'      => $root_id,
			'post_type' => $post_type,
			'user'      => $user_id,
			'change'    => '',
			'to'        => (int) ( $args['to'] ?? 0 ),
			'policy'    => (string) ( $args['policy'] ?? '' ),
			'fallback'  => (bool) ( $args['fallback'] ?? false ),
			'paths'     => array_values( array_map( 'strval', (array) ( $args['paths'] ?? [] ) ) ),
			'aliased'   => false,
			'urls'      => [
				'count'  => (int) ( $urls['count'] ?? 0 ),
				'capped' => (bool) ( $urls['capped'] ?? false ),
			],
			'total'     => (int) ( $args['total'] ?? 0 ),
			'cursor'    => 0,
			'phase'     => 'moved',
			'stack'     => [ [ $root_id, 0 ] ],
			'counts'    => Change_Log::empty_counts(),
			'kept_ids'  => [],
			'ids'       => [],
			'work'      => [
				'moves' => 0,
				'calls' => 0,
			],
			'created'   => time(),
			'touched'   => time(),
			'finished'  => false,
		];
	}

	/**
	 * Runs one bounded step of a job.
	 *
	 * @param array<string,mixed> $job   Job.
	 * @param int                 $moves Most subpages this step may move or look at.
	 * @param int                 $calls Most core trash or untrash calls this step may make.
	 * @return array<string,mixed> The updated job; `work` says what this step spent.
	 */
	public function step( array $job, int $moves, int $calls ): array {
		$job['work'] = [
			'moves' => 0,
			'calls' => 0,
		];
		$moves       = max( 0, $moves );
		$calls       = max( 0, $calls );

		switch ( $job['type'] ?? '' ) {
			case self::TYPE_REPARENT:
				return $this->step_reparent( $job, $moves );
		}

		$job['finished'] = true;
		return $job;
	}

	/**
	 * Runs a job to completion in this process with the default budgets.
	 *
	 * @param array<string,mixed> $job Job.
	 * @return array<string,mixed> The finished job.
	 */
	public function run( array $job ): array {
		for ( $i = 0; $i < 10000 && empty( $job['finished'] ); $i++ ) {
			$job = $this->step( $job, self::threshold(), self::core_threshold() );
		}
		return $job;
	}

	/**
	 * Moves the trashed root's live children to the nearest live ancestor.
	 *
	 * @param array<string,mixed> $job   Job.
	 * @param int                 $moves Most children to look at.
	 * @return array<string,mixed>
	 */
	private function step_reparent( array $job, int $moves ): array {
		$root = (int) $job['root'];
		if ( 'trash' !== Subtree::status( $root ) ) {
			// The page left the trash; its restore takes back what was already moved.
			$job['finished'] = true;
			return $job;
		}
		if ( $moves < 1 ) {
			return $job;
		}

		$to = (int) $job['to'];
		if ( $to > 0 && in_array( Subtree::status( $to ), [ '', 'trash' ], true ) ) {
			// The target went to the trash or away since the last step: move up past it.
			$post           = get_post( $root );
			$to             = $post instanceof WP_Post ? Subtree::nearest_live_ancestor( $post ) : 0;
			$job['to']      = $to;
			$job['aliased'] = false;
		}

		$type     = (string) $job['post_type'];
		$children = Subtree::live_children( $root, $type, $moves, (int) $job['cursor'] );
		_prime_post_caches( $children, false, false );
		$core  = Request::core_ids();
		$moved = [];
		$now   = time();

		foreach ( $children as $child_id ) {
			$job['cursor'] = $child_id;
			if ( in_array( $child_id, $core, true ) ) {
				// Core trashes this one next in its bulk loop, with its own policy.
				continue;
			}
			if ( ! $this->can( (int) $job['user'], 'edit_post', $child_id ) ) {
				$job = $this->keep( $job, $child_id, true );
				continue;
			}

			$result = $this->mover->move( $child_id, $to );
			if ( ! $result['moved'] ) {
				$job = $this->keep( $job, $child_id, false );
				continue;
			}

			Markers::add( $child_id, Markers::moved_key( $root ), Markers::encode_moved( $to, $result['old_slug'], $result['slug'], $now ) );
			if ( $result['renamed'] ) {
				++$job['counts']['renamed'];
				if ( $result['recordable'] && $this->recording() ) {
					// The alias on the new parent can't reach a renamed slug, so record it here.
					$this->ledger->record( $child_id, $result['old_path'] );
				}
			}
			++$job['counts']['moved'];
			$moved[] = $child_id;
		}

		$job['work']['moves'] = count( $children );
		if ( [] !== $moved ) {
			if ( empty( $job['aliased'] ) ) {
				$job = $this->record_aliases( $job );
			}

			/**
			 * Fires after subpages were moved to a new parent with a direct update.
			 * No save_post runs for them, so search indexes and SEO plugins that
			 * cache permalinks can re-sync these IDs here.
			 *
			 * @param int[] $ids  Moved post IDs.
			 * @param int   $from The trashed parent.
			 * @param int   $to   The new parent, 0 for top level.
			 */
			do_action( 'hierarchy_guard_children_reparented', $moved, $root, $to );
		}

		$job['finished'] = count( $children ) < $moves;
		return $this->remember( $job, $moved );
	}

	/**
	 * Records the old parent paths on the new parent, once per job and only
	 * after a subpage actually moved, and on the root itself. Remembers the
	 * aliases on the root so the restore can remove them.
	 *
	 * @param array<string,mixed> $job Job.
	 * @return array<string,mixed>
	 */
	private function record_aliases( array $job ): array {
		$job['aliased'] = true;
		if ( ! $this->recording() ) {
			return $job;
		}

		$root    = (int) $job['root'];
		$to      = (int) $job['to'];
		$written = [];
		foreach ( (array) $job['paths'] as $path ) {
			if ( $this->ledger->record_alias( (string) $job['post_type'], $to, (string) $path ) ) {
				$written[] = [
					'to'   => $to,
					'path' => (string) $path,
				];
			}
			// Held by the page in the trash, the path tells the resolver not to redirect it.
			$this->ledger->record( $root, (string) $path );
		}

		if ( [] !== $written ) {
			$stored = get_post_meta( $root, Markers::ALIASES, true );
			update_post_meta( $root, Markers::ALIASES, array_merge( is_array( $stored ) ? $stored : [], $written ) );
		}
		return $job;
	}

	/**
	 * Counts a subpage left where it is.
	 *
	 * @param array<string,mixed> $job     Job.
	 * @param int                 $post_id Subpage.
	 * @param bool                $denied  Whether the user lacked the capability.
	 * @return array<string,mixed>
	 */
	private function keep( array $job, int $post_id, bool $denied ): array {
		++$job['counts']['kept'];
		if ( $denied ) {
			++$job['counts']['denied'];
		}
		if ( count( $job['kept_ids'] ) < Change_Log::MAX_KEPT ) {
			$job['kept_ids'][] = $post_id;
		}
		return $job;
	}

	/**
	 * Whether the job's user may act on a post. User 0 is a system context and trusted.
	 *
	 * @param int    $user_id    User ID.
	 * @param string $capability Meta capability.
	 * @param int    $post_id    Post ID.
	 * @return bool
	 */
	private function can( int $user_id, string $capability, int $post_id ): bool {
		if ( $user_id <= 0 ) {
			return true;
		}
		if ( get_current_user_id() === $user_id ) {
			return current_user_can( $capability, $post_id );
		}
		return user_can( $user_id, $capability, $post_id );
	}

	/**
	 * Whether URL changes are worth recording (pretty permalinks are on).
	 *
	 * @return bool
	 */
	private function recording(): bool {
		return '' !== (string) get_option( 'permalink_structure' );
	}

	/**
	 * Adds affected IDs to the job, up to MAX_REMEMBERED.
	 *
	 * @param array<string,mixed> $job Job.
	 * @param int[]               $ids Affected IDs.
	 * @return array<string,mixed>
	 */
	private function remember( array $job, array $ids ): array {
		$job['ids'] = array_slice( array_merge( (array) $job['ids'], $ids ), 0, self::MAX_REMEMBERED );
		return $job;
	}
}
