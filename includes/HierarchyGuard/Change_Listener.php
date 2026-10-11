<?php
/**
 * Turns core post hooks into ledger writes.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard;

use HierarchyGuard\Trash\Change_Log;
use HierarchyGuard\Trash\Markers;
use WP_Post;

/**
 * Records URL changes. Every handler returns as early and as cheaply as it
 * can. A save that records an old path is also the current user's latest
 * change, so the redirects it needs can be offered right after it.
 */
final class Change_Listener {

	/**
	 * When true, nothing is recorded: for code that changes many posts and records their paths itself.
	 *
	 * @var bool
	 */
	private static bool $suspended = false;

	/**
	 * Ledger.
	 *
	 * @var Ledger
	 */
	private Ledger $ledger;

	/**
	 * The change log the redirects a save needs are offered from.
	 *
	 * @var Change_Log
	 */
	private Change_Log $log;

	/**
	 * Posts being deleted that hold ledger entries, by ID: core deletes their meta before deleted_post.
	 *
	 * @var array<int,true>
	 */
	private array $deleting = [];

	/**
	 * Posts a status change took into or out of the trash in this request, by
	 * ID: core's trash hooks that follow it have nothing left to drop.
	 *
	 * @var array<int,true>
	 */
	private array $crossed = [];

	/**
	 * Constructor.
	 *
	 * @param Ledger     $ledger Ledger.
	 * @param Change_Log $log    Change log.
	 */
	public function __construct( Ledger $ledger, Change_Log $log ) {
		$this->ledger = $ledger;
		$this->log    = $log;
	}

	/**
	 * Hooks into core.
	 */
	public function register(): void {
		add_action( 'post_updated', [ $this, 'on_post_updated' ], 10, 3 );
		add_action( 'before_delete_post', [ $this, 'on_before_delete' ], 10, 2 );
		add_action( 'transition_post_status', [ $this, 'on_status_changed' ], 10, 3 );
		add_action( 'trashed_post', [ $this, 'on_holder_changed' ] );
		add_action( 'untrashed_post', [ $this, 'on_holder_changed' ] );
		add_action( 'deleted_post', [ $this, 'on_deleted' ] );
	}

	/**
	 * Suspends or resumes recording.
	 *
	 * @param bool $on True to suspend.
	 */
	public static function suspend( bool $on ): void {
		self::$suspended = $on;
	}

	/**
	 * Whether recording is suspended.
	 *
	 * @return bool
	 */
	public static function is_suspended(): bool {
		return self::$suspended;
	}

	/**
	 * Records the old path of a post whose slug or parent changed, and, once
	 * the ledger holds that path on the post, the save as the current user's
	 * latest change: the post at its old path under its new parent. With no
	 * user, as in cron, nothing is shown.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $after   Post after the update.
	 * @param WP_Post $before  Post before the update.
	 */
	public function on_post_updated( int $post_id, WP_Post $after, WP_Post $before ): void {
		if ( self::$suspended ) {
			return;
		}
		if ( ! Post_Types::is_supported( (string) $after->post_type ) ) {
			return;
		}
		if ( $after->post_name === $before->post_name && (int) $after->post_parent === (int) $before->post_parent ) {
			return;
		}
		if ( 'trash' === $before->post_status || 'trash' === $after->post_status ) {
			return;
		}
		if ( ! Paths::is_recordable( $before ) ) {
			return;
		}

		$path = Paths::uri( $before );
		$this->ledger->record( $post_id, $path );
		$this->ledger->prune( $post_id );

		// Only an old path the ledger now holds on the post is redirected to it; anything else is no change to offer.
		$user = get_current_user_id();
		if ( $user > 0 && metadata_exists( 'post', $post_id, Ledger::key( (string) $after->post_type, Paths::normalize( $path ) ) ) ) {
			$this->log->record(
				$user,
				'save',
				(string) $after->post_type,
				[
					'root'   => $post_id,
					'to'     => (int) $after->post_parent,
					'path'   => $path,
					'policy' => '',
					'counts' => Change_Log::empty_counts(),
				]
			);
		}
	}

	/**
	 * Before core re-parents a deleted post's children, records the paths they
	 * used to live under on their new parent, or in the root list.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post being deleted.
	 */
	public function on_before_delete( int $post_id, WP_Post $post ): void {
		if ( ! Post_Types::is_supported( (string) $post->post_type ) ) {
			return;
		}
		if ( Ledger::has_entries( $post_id ) ) {
			$this->deleting[ $post_id ] = true;
		}
		if ( self::$suspended ) {
			return;
		}
		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			return;
		}
		if ( ! $this->has_children( $post_id, (string) $post->post_type ) ) {
			return;
		}

		// Subpages trashed together with a parent are deleted with it, so their own
		// paths would be carried onto pages about to be deleted too.
		if ( Markers::has_trashed_row( $post_id ) ) {
			return;
		}

		$paths = array_merge( [ Paths::uri( $post ), Paths::untrashed_uri( $post ) ], $this->ledger->paths_for( $post_id ) );
		$paths = array_unique(
			array_filter(
				$paths,
				static fn( string $path ): bool => '' !== $path && ! Paths::has_trashed_segment( $path )
			)
		);

		foreach ( $paths as $path ) {
			$this->ledger->record_alias( (string) $post->post_type, (int) $post->post_parent, $path );
		}
	}

	/**
	 * A post went into or out of the trash by a status change, as
	 * wp_update_post(), WP-CLI's `post update` and the REST API make it with
	 * no wp_trash_post() or wp_untrash_post(): see on_holder_changed(). Core's
	 * own trash and restore make the same change first.
	 *
	 * @param string  $new_status New status.
	 * @param string  $old_status Old status.
	 * @param WP_Post $post       Post.
	 */
	public function on_status_changed( string $new_status, string $old_status, WP_Post $post ): void {
		if ( $new_status === $old_status || ( 'trash' !== $new_status && 'trash' !== $old_status ) ) {
			return;
		}
		$this->crossed[ (int) $post->ID ] = true;
		$this->drop_lookups( (int) $post->ID, (string) $post->post_type );
	}

	/**
	 * A post that holds ledger entries went to the trash or came back: the
	 * ledger's cached lookups may name it, or a holder it hid, so they are
	 * dropped. Trashing a page with no subpages writes nothing to the ledger.
	 * When the status change before this hook dropped them, nothing is left to do.
	 *
	 * @param int $post_id Post ID.
	 */
	public function on_holder_changed( int $post_id ): void {
		if ( isset( $this->crossed[ $post_id ] ) ) {
			unset( $this->crossed[ $post_id ] );
			return;
		}
		$type = get_post_type( $post_id );
		if ( false !== $type ) {
			$this->drop_lookups( $post_id, $type );
		}
	}

	/**
	 * A post that held ledger entries is gone: the cached lookups that name it are dropped.
	 *
	 * @param int $post_id Post ID.
	 */
	public function on_deleted( int $post_id ): void {
		if ( isset( $this->deleting[ $post_id ] ) ) {
			unset( $this->deleting[ $post_id ] );
			$this->ledger->invalidate();
		}
	}

	/**
	 * Drops the ledger's cached lookups when a supported post holds any entry.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $post_type Post type name.
	 */
	private function drop_lookups( int $post_id, string $post_type ): void {
		if ( Post_Types::is_supported( $post_type ) && Ledger::has_entries( $post_id ) ) {
			$this->ledger->invalidate();
		}
	}

	/**
	 * Whether a post has at least one child of the same type, in any status:
	 * core re-parents trashed and auto-draft children too. One indexed query.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $post_type Post type name.
	 */
	private function has_children( int $post_id, string $post_type ): bool {
		$children = get_posts(
			[
				'post_parent'            => $post_id,
				'post_type'              => $post_type,
				'post_status'            => array_keys( get_post_stati() ),
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			]
		);

		return ! empty( $children );
	}
}
