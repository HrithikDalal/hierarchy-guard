<?php
/**
 * The trash flow's units of work.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Trash;

use HierarchyGuard\Ledger;
use HierarchyGuard\Paths;
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
			case self::TYPE_TRASH:
				return $this->step_trash_children( $job, $moves, $calls );
			case self::TYPE_RESTORE:
				return $this->step_restore( $job, $moves, $calls );
			case self::TYPE_CLEANUP:
				return $this->step_cleanup( $job, $moves );
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
	 * Trashes the trashed root's live descendants, depth first.
	 *
	 * @param array<string,mixed> $job   Job.
	 * @param int                 $moves Most subpages to look at.
	 * @param int                 $calls Most core trash calls.
	 * @return array<string,mixed>
	 */
	private function step_trash_children( array $job, int $moves, int $calls ): array {
		$root = (int) $job['root'];
		// With the trash turned off, wp_trash_post() would delete the subpages for good.
		if ( 'trash' !== Subtree::status( $root ) || ! EMPTY_TRASH_DAYS ) {
			$job['finished'] = true;
			return $job;
		}

		$type    = (string) $job['post_type'];
		$core    = Request::core_ids();
		$special = array_values( array_filter( array_map( 'intval', [ get_option( 'page_on_front' ), get_option( 'page_for_posts' ), get_option( 'wp_page_for_privacy_policy' ) ] ) ) );
		$stack   = (array) $job['stack'];
		$seen    = 0;
		$used    = 0;
		$trashed = [];

		while ( [] !== $stack && $used < $calls && $seen < $moves ) {
			$top    = count( $stack ) - 1;
			$parent = (int) $stack[ $top ][0];
			$kids   = Subtree::live_children( $parent, $type, min( $calls - $used, $moves - $seen ), (int) $stack[ $top ][1] );
			if ( [] === $kids ) {
				array_pop( $stack );
				continue;
			}
			_prime_post_caches( $kids, false, false );

			$now_trashed = [];
			foreach ( $kids as $kid ) {
				$stack[ $top ][1] = $kid;
				++$seen;
				if ( in_array( $kid, $core, true ) ) {
					continue;
				}
				if ( in_array( $kid, $special, true ) ) {
					// Trashing the front page, posts page or privacy page would change site settings.
					$job = $this->keep( $job, $kid, false );
					continue;
				}
				if ( ! $this->can( (int) $job['user'], 'delete_post', $kid ) ) {
					$job = $this->keep( $job, $kid, true );
					continue;
				}

				++$used;
				// Marked first, so the row exists even if this request dies right after the trash.
				Markers::add( $kid, Markers::trashed_key( $root ), '1' );
				Guard::run( [ $kid ], static fn() => wp_trash_post( $kid ) );
				// A pre_trash_post filter can answer true without trashing, so the status decides.
				if ( 'trash' === Subtree::status( $kid ) ) {
					++$job['counts']['trashed'];
					$now_trashed[] = $kid;
					continue;
				}

				Markers::remove_key( $kid, Markers::trashed_key( $root ) );
				$job = $this->keep( $job, $kid, false );
			}

			foreach ( array_reverse( Subtree::with_live_children( $now_trashed, $type ) ) as $parent_id ) {
				$stack[] = [ $parent_id, 0 ];
			}
			$trashed = array_merge( $trashed, $now_trashed );
		}

		$job['stack']    = $stack;
		$job['work']     = [
			'moves' => $seen,
			'calls' => $used,
		];
		$job['finished'] = [] === $stack;

		return $this->remember( $job, $trashed );
	}

	/**
	 * Takes back the moves and trashes for a restored root: moved subpages
	 * first, then subpages trashed with it.
	 *
	 * @param array<string,mixed> $job   Job.
	 * @param int                 $moves Most moved subpages to handle.
	 * @param int                 $calls Most core untrash calls.
	 * @return array<string,mixed>
	 */
	private function step_restore( array $job, int $moves, int $calls ): array {
		if ( in_array( Subtree::status( (int) $job['root'] ), [ '', 'trash' ], true ) ) {
			$job['finished'] = true;
			return $job;
		}

		if ( 'moved' === $job['phase'] ) {
			$job = $this->restore_moved( $job, $moves );
			if ( 'moved' === $job['phase'] ) {
				return $job;
			}
		}

		$job = $this->restore_trashed( $job, $calls );
		if ( ! empty( $job['finished'] ) ) {
			$this->finish_restore( $job );
		}
		return $job;
	}

	/**
	 * Moves subpages back under the restored root.
	 *
	 * @param array<string,mixed> $job   Job.
	 * @param int                 $moves Most rows to handle.
	 * @return array<string,mixed>
	 */
	private function restore_moved( array $job, int $moves ): array {
		if ( $moves < 1 ) {
			return $job;
		}

		$root     = (int) $job['root'];
		$rows     = Markers::rows( Markers::moved_key( $root ), $moves, (int) $job['cursor'] );
		$restored = [];
		$now      = time();
		_prime_post_caches( array_column( $rows, 'post_id' ), false, false );

		foreach ( $rows as $row ) {
			$job['cursor'] = $row['meta_id'];
			$id            = $row['post_id'];
			$moved         = Markers::decode_moved( $row['value'] );
			$post          = get_post( $id );
			if ( ! $post instanceof WP_Post ) {
				continue;
			}
			if ( (int) $post->post_parent !== $moved['to'] ) {
				// An editor moved it somewhere else since: leave it there.
				++$job['counts']['left'];
				continue;
			}

			if ( 'trash' === $post->post_status ) {
				// Moved up, then trashed on its own: put it back under the root, still in the trash.
				$this->mover->reparent( $id, $root );
				if ( $moved['original'] !== $moved['assigned'] && get_post_meta( $id, '_wp_desired_post_slug', true ) === $moved['assigned'] ) {
					update_post_meta( $id, '_wp_desired_post_slug', $moved['original'] );
				}
				++$job['counts']['moved'];
				$restored[] = $id;
				continue;
			}

			// The original slug comes back only if the editor hasn't renamed the subpage since.
			$preferred = ( '' !== $moved['original'] && (string) $post->post_name === $moved['assigned'] ) ? $moved['original'] : '';
			$interim   = Paths::uri( $post );
			$result    = $this->mover->move( $id, $root, $preferred );
			if ( ! $result['moved'] ) {
				++$job['counts']['left'];
				continue;
			}

			if ( $result['recordable'] && $this->recording() && $now - $moved['time'] >= self::INTERIM_AGE ) {
				// Links made while the parent was in the trash keep working.
				$this->ledger->record( $id, $interim );
			}
			$this->ledger->prune( $id );
			if ( ( '' !== $preferred && $result['slug'] !== $preferred ) || ( '' === $preferred && $result['renamed'] ) ) {
				++$job['counts']['renamed'];
			}
			++$job['counts']['moved'];
			$restored[] = $id;
		}

		Markers::remove( $rows );
		$job['work']['moves'] = count( $rows );
		if ( count( $rows ) < $moves ) {
			$job['phase']  = 'trashed';
			$job['cursor'] = 0;
		}

		return $this->remember( $job, $restored );
	}

	/**
	 * Restores subpages trashed together with the root. Their marker row makes
	 * core give them their previous status.
	 *
	 * @param array<string,mixed> $job   Job.
	 * @param int                 $calls Most rows to handle.
	 * @return array<string,mixed>
	 */
	private function restore_trashed( array $job, int $calls ): array {
		if ( $calls < 1 ) {
			return $job;
		}

		$root     = (int) $job['root'];
		$core     = Request::core_ids();
		$rows     = Markers::rows( Markers::trashed_key( $root ), $calls, (int) $job['cursor'] );
		$done     = [];
		$restored = [];
		$used     = 0;
		_prime_post_caches( array_column( $rows, 'post_id' ), false, false );

		foreach ( $rows as $row ) {
			$job['cursor'] = $row['meta_id'];
			$id            = $row['post_id'];
			if ( in_array( $id, $core, true ) ) {
				// Core restores it next in its bulk loop; the row gives it its previous status.
				continue;
			}
			$done[] = $row;
			if ( 'trash' !== get_post_status( $id ) ) {
				continue;
			}

			++$used;
			Guard::run( [ $id ], static fn() => wp_untrash_post( $id ) );
			// A pre_untrash_post filter can answer true without restoring, so the status decides.
			if ( in_array( Subtree::status( $id ), [ '', 'trash' ], true ) ) {
				++$job['counts']['left'];
			} else {
				++$job['counts']['restored'];
				$restored[] = $id;
			}
		}

		Markers::remove( $done );
		$job['work']['calls'] += $used;
		$job['finished']       = count( $rows ) < $calls;

		return $this->remember( $job, $restored );
	}

	/**
	 * Removes the aliases the trash recorded, now that the subpages are back.
	 * The page's own entry for the path it is back at goes too; an entry for a
	 * path it no longer has stays and leads to it.
	 *
	 * @param array<string,mixed> $job Finished restore job.
	 */
	private function finish_restore( array $job ): void {
		$root    = (int) $job['root'];
		$aliases = get_post_meta( $root, Markers::ALIASES, true );
		foreach ( is_array( $aliases ) ? $aliases : [] as $alias ) {
			$this->ledger->forget_alias( (string) $job['post_type'], (int) ( $alias['to'] ?? 0 ), (string) ( $alias['path'] ?? '' ) );
		}
		delete_post_meta( $root, Markers::ALIASES );
		$this->ledger->prune( $root );
	}

	/**
	 * Deletes marker rows that point at a permanently deleted root.
	 *
	 * @param array<string,mixed> $job   Job.
	 * @param int                 $moves Most rows to delete.
	 * @return array<string,mixed>
	 */
	private function step_cleanup( array $job, int $moves ): array {
		if ( $moves < 1 ) {
			return $job;
		}

		$root = (int) $job['root'];
		$rows = Markers::rows( Markers::moved_key( $root ), $moves );
		if ( count( $rows ) < $moves ) {
			$rows = array_merge( $rows, Markers::rows( Markers::trashed_key( $root ), $moves - count( $rows ) ) );
		}
		Markers::remove( $rows );

		$job['work']['moves'] = count( $rows );
		$job['finished']      = count( $rows ) < $moves;
		return $job;
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
