<?php
/**
 * Moves a post under a new parent.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Trash;

use HierarchyGuard\Paths;
use WP_Post;

/**
 * Re-parents the way wp_delete_post() does: a direct update, so content is not
 * re-filtered and the modified date stays, then a cache clear. The slug is
 * made unique under the new parent first.
 */
final class Mover {

	/**
	 * Moves a post and makes its slug unique under the new parent.
	 *
	 * @param int    $post_id        Post to move.
	 * @param int    $new_parent_id  New parent, 0 for top level.
	 * @param string $preferred_slug Slug to try instead of the current one, such as the one it had before an earlier move.
	 * @param string $reserved_slug  A slug the move must not take under the new parent, such as the one a trashed parent will reclaim.
	 * @return array{moved:bool,old_path:string,old_slug:string,slug:string,renamed:bool,recordable:bool}
	 */
	public function move( int $post_id, int $new_parent_id, string $preferred_slug = '', string $reserved_slug = '' ): array {
		global $wpdb;

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return [
				'moved'      => false,
				'old_path'   => '',
				'old_slug'   => '',
				'slug'       => '',
				'renamed'    => false,
				'recordable' => false,
			];
		}

		$old_path   = Paths::untrashed_uri( $post );
		$recordable = Paths::is_recordable( $post );
		$old_slug   = (string) $post->post_name;
		$slug       = '' !== $preferred_slug ? $preferred_slug : $old_slug;
		if ( '' !== $slug ) {
			// Core skips the uniqueness check for drafts and pending posts. A duplicate
			// path would let an older draft hide a published page from visitors.
			$status  = in_array( $post->post_status, [ 'draft', 'pending' ], true ) ? 'publish' : (string) $post->post_status;
			$reserve = static function ( $is_bad, $candidate, $post_type, $post_parent ) use ( $reserved_slug, $new_parent_id ) {
				return (bool) $is_bad || ( '' !== $reserved_slug && (int) $post_parent === $new_parent_id && $candidate === $reserved_slug );
			};
			add_filter( 'wp_unique_post_slug_is_bad_hierarchical_slug', $reserve, 10, 4 );
			try {
				$slug = wp_unique_post_slug( $slug, $post_id, $status, (string) $post->post_type, $new_parent_id );
			} finally {
				remove_filter( 'wp_unique_post_slug_is_bad_hierarchical_slug', $reserve, 10 );
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Mirrors wp_delete_post()'s re-parent; the post cache is cleared next.
		$updated = $wpdb->update(
			$wpdb->posts,
			[
				'post_parent' => $new_parent_id,
				'post_name'   => $slug,
			],
			[ 'ID' => $post_id ]
		);
		clean_post_cache( $post_id );

		return [
			'moved'      => false !== $updated,
			'old_path'   => $old_path,
			'old_slug'   => $old_slug,
			'slug'       => $slug,
			'renamed'    => $slug !== $old_slug,
			'recordable' => $recordable,
		];
	}

	/**
	 * Changes only the parent, for a post that sits in the trash.
	 *
	 * @param int $post_id       Post to move.
	 * @param int $new_parent_id New parent.
	 * @return bool Whether the update ran.
	 */
	public function reparent( int $post_id, int $new_parent_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Mirrors wp_delete_post()'s re-parent; the post cache is cleared next.
		$updated = $wpdb->update( $wpdb->posts, [ 'post_parent' => $new_parent_id ], [ 'ID' => $post_id ] );
		clean_post_cache( $post_id );

		return false !== $updated;
	}
}
