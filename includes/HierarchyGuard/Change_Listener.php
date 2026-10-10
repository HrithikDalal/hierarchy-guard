<?php
/**
 * Turns core post hooks into ledger writes.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard;

use HierarchyGuard\Trash\Markers;
use WP_Post;

/**
 * Records URL changes. Every handler returns as early and as cheaply as it can.
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
	 * Constructor.
	 *
	 * @param Ledger $ledger Ledger.
	 */
	public function __construct( Ledger $ledger ) {
		$this->ledger = $ledger;
	}

	/**
	 * Hooks into core.
	 */
	public function register(): void {
		add_action( 'post_updated', [ $this, 'on_post_updated' ], 10, 3 );
		add_action( 'before_delete_post', [ $this, 'on_before_delete' ], 10, 2 );
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
	 * Records the old path of a post whose slug or parent changed.
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

		$this->ledger->record( $post_id, Paths::uri( $before ) );
		$this->ledger->prune( $post_id );
	}

	/**
	 * Before core re-parents a deleted post's children, records the paths they
	 * used to live under on their new parent, or in the root list.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post being deleted.
	 */
	public function on_before_delete( int $post_id, WP_Post $post ): void {
		if ( self::$suspended ) {
			return;
		}
		if ( ! Post_Types::is_supported( (string) $post->post_type ) ) {
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
