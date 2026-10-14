<?php
/**
 * Applies the trash policy to a page's subpages and reverses it on restore.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Trash;

use HierarchyGuard\Batch\CLI_Command;
use HierarchyGuard\Batch\Queue;
use HierarchyGuard\Ledger;
use HierarchyGuard\Paths;
use HierarchyGuard\Post_Types;
use HierarchyGuard\Settings;
use HierarchyGuard\Subtree;
use WP_Error;
use WP_Post;

/**
 * Snapshots in wp_trash_post, acts in trashed_post once core has succeeded,
 * and takes back its own moves after any save that brings a page out of the
 * trash. Work beyond the request's budget goes to the batch queue.
 */
final class Trash_Handler {

	/**
	 * Snapshots taken in wp_trash_post, keyed by post ID.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $snapshots = [];

	/**
	 * Pages that left the trash in this request, keyed by ID, valued by post type.
	 *
	 * @var array<int,string>
	 */
	private array $pending = [];

	/**
	 * Pages undo() is restoring right now.
	 *
	 * @var array<int,true>
	 */
	private array $undo_ids = [];

	/**
	 * Inline work done in this request, across every page it trashed or restored.
	 *
	 * @var array{moves:int,calls:int}
	 */
	private array $spent = [
		'moves' => 0,
		'calls' => 0,
	];

	/**
	 * Ledger.
	 *
	 * @var Ledger
	 */
	private Ledger $ledger;

	/**
	 * Operations.
	 *
	 * @var Operations
	 */
	private Operations $operations;

	/**
	 * Queue.
	 *
	 * @var Queue
	 */
	private Queue $queue;

	/**
	 * Change log.
	 *
	 * @var Change_Log
	 */
	private Change_Log $log;

	/**
	 * Constructor.
	 *
	 * @param Ledger     $ledger     Ledger.
	 * @param Operations $operations Operations.
	 * @param Queue      $queue      Queue.
	 * @param Change_Log $log        Change log.
	 */
	public function __construct( Ledger $ledger, Operations $operations, Queue $queue, Change_Log $log ) {
		$this->ledger     = $ledger;
		$this->operations = $operations;
		$this->queue      = $queue;
		$this->log        = $log;
	}

	/**
	 * Hooks into core.
	 */
	public function register(): void {
		add_action( 'wp_trash_post', [ $this, 'before_trash' ] );
		add_action( 'trashed_post', [ $this, 'after_trash' ] );
		add_action( 'transition_post_status', [ $this, 'on_transition' ], 10, 3 );
		add_action( 'wp_after_insert_post', [ $this, 'after_save' ], 20 );
		add_action( 'shutdown', [ $this, 'flush_pending' ] );
		add_action( 'deleted_post', [ $this, 'on_deleted' ], 10, 2 );
		add_filter( 'wp_untrash_post_status', [ $this, 'undo_status' ], 20, 3 );
	}

	/**
	 * Starts a fresh inline budget. The plugin never calls this; long-running
	 * processes and tests do.
	 */
	public function reset_request_budget(): void {
		$this->spent = [
			'moves' => 0,
			'calls' => 0,
		];
	}

	/**
	 * The policy for one page: the setting, then the per-page filter.
	 *
	 * @param WP_Post $post Page being trashed.
	 * @return string One of Settings::POLICIES.
	 */
	public static function policy_for( WP_Post $post ): string {
		$default = Settings::trash_policy();

		/**
		 * Filters the trash policy for one page with subpages.
		 *
		 * @param string  $policy One of Settings::POLICIES.
		 * @param WP_Post $post   The page being trashed.
		 */
		$policy = (string) apply_filters( 'hierarchy_guard_trash_policy', $default, $post );

		return in_array( $policy, Settings::POLICIES, true ) ? $policy : $default;
	}

	/**
	 * Resolves the policy and snapshots paths. Writes nothing: the trash can still fail.
	 *
	 * @param mixed $post_id Post being trashed.
	 */
	public function before_trash( $post_id ): void {
		$id = (int) $post_id;
		unset( $this->snapshots[ $id ] );
		if ( Guard::covers( $id ) ) {
			return;
		}
		$post = get_post( $id );
		if ( ! $post instanceof WP_Post || ! Post_Types::is_supported( (string) $post->post_type ) ) {
			return;
		}
		$type = (string) $post->post_type;
		if ( ! Subtree::has_live_children( $id, $type ) ) {
			return;
		}

		$policy      = self::policy_for( $post );
		$cap         = Subtree::count_cap();
		$descendants = Subtree::count_live_descendants( $id, $type, $cap );
		// One click must never fill the trash beyond what core can empty in one request.
		$fallback = Settings::POLICY_TRASH_CHILDREN === $policy && $descendants > $cap;
		if ( $fallback ) {
			$policy = Settings::POLICY_REPARENT;
		}

		$paths = array_values(
			array_unique(
				array_filter(
					array_merge( [ Paths::untrashed_uri( $post ) ], $this->ledger->paths_for( $id ) ),
					static fn( string $path ): bool => '' !== $path && ! Paths::has_trashed_segment( $path )
				)
			)
		);

		$this->snapshots[ $id ] = [
			'policy'    => $policy,
			'fallback'  => $fallback,
			'to'        => Subtree::nearest_live_ancestor( $post ),
			'paths'     => $paths,
			'post_type' => $type,
			'urls'      => [
				'count'  => min( $descendants, $cap ),
				'capped' => $descendants > $cap,
			],
		];
	}

	/**
	 * Applies the snapshot's policy now that core has trashed the post.
	 *
	 * @param mixed $post_id Trashed post.
	 */
	public function after_trash( $post_id ): void {
		$id = (int) $post_id;
		if ( Guard::covers( $id ) || ! isset( $this->snapshots[ $id ] ) ) {
			return;
		}
		$snapshot = $this->snapshots[ $id ];
		unset( $this->snapshots[ $id ] );

		$type = (string) $snapshot['post_type'];
		$user = get_current_user_id();
		$cap  = Subtree::count_cap();

		if ( Settings::POLICY_KEEP === $snapshot['policy'] ) {
			$kept = Subtree::count_live_children( $id, $type, $cap );
			$this->log->record(
				$user,
				'trash',
				$type,
				[
					'root'   => $id,
					'to'     => (int) $snapshot['to'],
					'policy' => Settings::POLICY_KEEP,
					'counts' => [ 'kept' => $kept ],
					'urls'   => $snapshot['urls'],
				]
			);
			$this->cli( [ sprintf( 'Left %s of page %d where %s.', CLI_Command::subpages( $kept ), $id, 1 === $kept ? 'it is' : 'they are' ) ] );
			return;
		}

		$reparent = Settings::POLICY_REPARENT === $snapshot['policy'];
		$job      = Operations::new_job(
			$reparent ? Operations::TYPE_REPARENT : Operations::TYPE_TRASH,
			$id,
			$type,
			$user,
			[
				'to'       => (int) $snapshot['to'],
				'policy'   => (string) $snapshot['policy'],
				'fallback' => (bool) $snapshot['fallback'],
				'paths'    => $snapshot['paths'],
				'urls'     => $snapshot['urls'],
				'total'    => $reparent ? Subtree::count_live_children( $id, $type, $cap ) : (int) $snapshot['urls']['count'],
			]
		);

		$this->dispatch( 'trash', $job );
	}

	/**
	 * Notes a page leaving the trash by any route. The restore itself waits
	 * until the page's own save has finished.
	 *
	 * @param mixed $new_status New status.
	 * @param mixed $old_status Old status.
	 * @param mixed $post       Post.
	 */
	public function on_transition( $new_status, $old_status, $post ): void {
		// Runs on every status change on the site: the cheapest checks come first.
		if ( 'trash' !== $old_status || 'trash' === $new_status || ! $post instanceof WP_Post ) {
			return;
		}
		$id = (int) $post->ID;
		if ( Guard::covers( $id ) || ! Post_Types::is_supported( (string) $post->post_type ) ) {
			return;
		}

		// A subpage restored on its own no longer comes back with its parent.
		Markers::clear_trashed_rows( $id );
		$this->pending[ $id ] = (string) $post->post_type;
	}

	/**
	 * Restores a page's subpages once its own save is complete.
	 *
	 * @param mixed $post_id Saved post.
	 */
	public function after_save( $post_id ): void {
		$id = (int) $post_id;
		if ( ! isset( $this->pending[ $id ] ) ) {
			return;
		}
		$type = $this->pending[ $id ];
		unset( $this->pending[ $id ] );

		$this->start_restore( $id, $type, true );
	}

	/**
	 * Queues the restores of pages whose save never reached wp_after_insert_post.
	 */
	public function flush_pending(): void {
		foreach ( $this->pending as $id => $type ) {
			unset( $this->pending[ $id ] );
			$this->start_restore( (int) $id, $type, false );
		}
	}

	/**
	 * Cleans up after a root page that was deleted for good.
	 *
	 * @param mixed $post_id Deleted post.
	 * @param mixed $post    Its object, as core passes it.
	 */
	public function on_deleted( $post_id, $post ): void {
		$id = (int) $post_id;
		if ( ! $post instanceof WP_Post || ! Post_Types::is_supported( (string) $post->post_type ) ) {
			return;
		}
		if ( [] !== Markers::rows( Markers::moved_key( $id ), 1 ) ) {
			$this->dispatch( 'cleanup', Operations::new_job( Operations::TYPE_CLEANUP, $id, (string) $post->post_type, 0 ) );
			return;
		}
		if ( null === $this->queue->load( $id ) && null === $this->queue->offered( $id ) ) {
			return;
		}
		$token = $this->queue->lock( $id );
		if ( 0 === $token ) {
			// A run holds the lock; it finds the page gone at its next step and ends the job.
			return;
		}
		$this->queue->delete( $id );
		$this->queue->discard_offer( $id );
		$this->queue->unlock( $id, $token );
	}

	/**
	 * Restores a trashed page with the status it had, which brings its subpages
	 * back too. Core's Restore link makes the page a draft instead; either way
	 * its subpages return with their own statuses.
	 *
	 * @param int $root_id Trashed page.
	 * @return true|WP_Error
	 */
	public function undo( int $root_id ) {
		$post = get_post( $root_id );
		if ( ! $post instanceof WP_Post || 'trash' !== $post->post_status ) {
			return new WP_Error(
				'hierarchy_guard_not_in_trash',
				$post instanceof WP_Post
					/* translators: %s: page title. */
					? sprintf( __( '“%s” is no longer in the trash.', 'hierarchy-guard' ), self::title( $post ) )
					: __( 'This page is no longer in the trash.', 'hierarchy-guard' )
			);
		}
		if ( is_user_logged_in() && ! current_user_can( 'delete_post', $root_id ) ) {
			return new WP_Error( 'hierarchy_guard_forbidden', __( 'Sorry, you are not allowed to restore this page from the trash.', 'hierarchy-guard' ) );
		}

		$this->undo_ids[ $root_id ] = true;
		try {
			wp_untrash_post( $root_id );
		} finally {
			unset( $this->undo_ids[ $root_id ] );
		}

		// A pre_untrash_post filter can answer true without restoring, so the status decides.
		if ( 'trash' === Subtree::status( $root_id ) ) {
			/* translators: %s: page title. */
			return new WP_Error( 'hierarchy_guard_restore_failed', sprintf( __( '“%s” could not be restored from the trash.', 'hierarchy-guard' ), self::title( $post ) ) );
		}
		return true;
	}

	/**
	 * Gives the page undo() restores the status it had before the trash.
	 *
	 * @param mixed $new_status      Status core is about to assign.
	 * @param mixed $post_id         Post being untrashed.
	 * @param mixed $previous_status Status it had before the trash.
	 * @return string
	 */
	public function undo_status( $new_status, $post_id, $previous_status ): string {
		if ( isset( $this->undo_ids[ (int) $post_id ] ) && '' !== (string) $previous_status ) {
			return (string) $previous_status;
		}
		return (string) $new_status;
	}

	/**
	 * WP-CLI lines about a trash or restore.
	 *
	 * @param string              $action trash or restore.
	 * @param array<string,mixed> $job    Job after its inline step.
	 * @param bool                $queued Whether work is left for the queue.
	 * @return string[]
	 */
	public static function cli_lines( string $action, array $job, bool $queued ): array {
		$root   = (int) $job['root'];
		$counts = (array) $job['counts'];
		$lines  = [];

		if ( 'trash' === $action ) {
			if ( (int) $counts['moved'] > 0 ) {
				$lines[] = (int) $job['to'] > 0
					? sprintf( 'Moved %s of page %d up under page %d.', CLI_Command::subpages( (int) $counts['moved'] ), $root, (int) $job['to'] )
					: sprintf( 'Moved %s of page %d to the top level.', CLI_Command::subpages( (int) $counts['moved'] ), $root );
			}
			if ( (int) $counts['trashed'] > 0 ) {
				$lines[] = sprintf( 'Trashed %s of page %d with it.', CLI_Command::subpages( (int) $counts['trashed'] ), $root );
			}
		} elseif ( 'restore' === $action ) {
			if ( (int) $counts['moved'] > 0 ) {
				$lines[] = sprintf( 'Moved %s back under page %d.', CLI_Command::subpages( (int) $counts['moved'] ), $root );
			}
			if ( (int) $counts['restored'] > 0 ) {
				$lines[] = sprintf( 'Restored %s of page %d from the trash.', CLI_Command::subpages( (int) $counts['restored'] ), $root );
			}
		}
		if ( (int) $counts['denied'] > 0 ) {
			$lines[] = sprintf( 'Skipped %s the user cannot change.', CLI_Command::subpages( (int) $counts['denied'] ) );
		}

		if ( $queued ) {
			$done    = (int) $counts['moved'] + (int) $counts['trashed'] + (int) $counts['restored'] + (int) $counts['kept'] + (int) $counts['left'];
			$more    = (int) $job['total'] - $done;
			$lines[] = $more > 0 && (int) $job['total'] < Subtree::count_cap()
				? sprintf( '%s more are queued. Run `wp hierarchy-guard process-jobs` to finish now.', number_format( $more ) )
				: 'The rest are queued. Run `wp hierarchy-guard process-jobs` to finish now.';
		}

		return $lines;
	}

	/**
	 * Starts the restore of a page that left the trash, if the trash flow changed anything under it.
	 *
	 * @param int    $root_id Restored page.
	 * @param string $type    Its post type.
	 * @param bool   $inline  Whether to do the first batch in this request.
	 */
	private function start_restore( int $root_id, string $type, bool $inline ): void {
		$size = Markers::count( $root_id, Subtree::count_cap() );
		if ( 0 === $size ) {
			return;
		}
		$this->dispatch( 'restore', Operations::new_job( Operations::TYPE_RESTORE, $root_id, $type, get_current_user_id(), [ 'total' => $size ] ), $inline );
	}

	/**
	 * Runs the first batch of a job inline, within what is left of this
	 * request's budget, and queues the rest. A job for a page whose batch is
	 * running in another process is handed to that process instead. Before a
	 * job is queued or handed over, the user's change is written: the run
	 * that updates it reads it from storage, maybe before this request ends.
	 *
	 * @param string              $action trash, restore or cleanup.
	 * @param array<string,mixed> $job    Job.
	 * @param bool                $inline Whether to do any work in this request.
	 */
	private function dispatch( string $action, array $job, bool $inline = true ): void {
		$root  = (int) $job['root'];
		$token = $this->queue->lock( $root );
		if ( 0 === $token ) {
			$job['change'] = $this->summarize( $action, $job, true );
			$this->log->write_now( (int) $job['user'] );
			$this->queue->offer( $job );
			$this->queue->schedule( $root );
			$this->cli( 'cleanup' === $action ? [] : self::cli_lines( $action, $job, true ) );
			return;
		}

		$running = true;
		try {
			$this->supersede( $root );
			// Saved before any work, so a request that dies mid-step leaves the job for the watchdog.
			$this->queue->save( $job );

			$moves = $inline ? max( 0, Operations::threshold() - $this->spent['moves'] ) : 0;
			$calls = $inline ? max( 0, Operations::core_threshold() - $this->spent['calls'] ) : 0;
			if ( $moves > 0 || $calls > 0 ) {
				$job                   = Ledger::deferred( fn() => $this->operations->step( $job, $moves, $calls ) );
				$this->spent['moves'] += (int) $job['work']['moves'];
				$this->spent['calls'] += (int) $job['work']['calls'];
			}

			$running       = empty( $job['finished'] );
			$job['change'] = $this->summarize( $action, $job, $running );
			if ( $running ) {
				// A run can start as soon as the lock is released.
				$this->log->write_now( (int) $job['user'] );
				$this->queue->save( $job );
			} else {
				$this->queue->delete( $root );
			}
		} finally {
			$this->queue->unlock( $root, $token );
		}

		// Scheduled after the unlock; an offer that arrived during the inline step needs a run too.
		if ( $running || null !== $this->queue->offered( $root ) ) {
			$this->log->write_now( (int) $job['user'] );
			$this->queue->schedule( $root );
		}
		$this->cli( 'cleanup' === $action ? [] : self::cli_lines( $action, $job, $running ) );
	}

	/**
	 * Closes the summaries of the job and offer this one replaces.
	 *
	 * @param int $root_id Root page.
	 */
	private function supersede( int $root_id ): void {
		foreach ( [ $this->queue->load( $root_id ), $this->queue->take_offer( $root_id ) ] as $old ) {
			if ( null !== $old ) {
				$this->log->update( (int) $old['user'], (string) $old['change'], $root_id, (array) $old['counts'], (array) $old['kept_ids'], (array) $old['ids'], (array) ( $old['slugs'] ?? [] ), false );
			}
		}
	}

	/**
	 * Records the job's root in the user's change summary.
	 *
	 * @param string              $action  trash, restore or cleanup.
	 * @param array<string,mixed> $job     Job.
	 * @param bool                $running Whether work is left for the queue.
	 * @return string Change ID, or an empty string when nothing was recorded.
	 */
	private function summarize( string $action, array $job, bool $running ): string {
		if ( 'cleanup' === $action ) {
			return '';
		}
		return $this->log->record(
			(int) $job['user'],
			$action,
			(string) $job['post_type'],
			[
				'root'     => (int) $job['root'],
				'to'       => (int) $job['to'],
				'policy'   => (string) $job['policy'],
				'fallback' => (bool) $job['fallback'],
				// The root's own old path: the redirects modal rebuilds the pairs from it.
				'path'     => (string) ( $job['paths'][0] ?? '' ),
				'counts'   => (array) $job['counts'],
				'kept_ids' => (array) $job['kept_ids'],
				'ids'      => (array) $job['ids'],
				'slugs'    => (array) ( $job['slugs'] ?? [] ),
				'urls'     => (array) $job['urls'],
				'job'      => $running,
			],
			(array) $job['ids']
		);
	}

	/**
	 * Prints lines when running under WP-CLI.
	 *
	 * @param string[] $lines Lines.
	 */
	private function cli( array $lines ): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}
		foreach ( $lines as $line ) {
			\WP_CLI::log( $line );
		}
	}

	/**
	 * A post's title for a message.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	private static function title( WP_Post $post ): string {
		$title = get_the_title( $post );
		return '' !== $title ? $title : __( '(no title)', 'hierarchy-guard' );
	}
}
