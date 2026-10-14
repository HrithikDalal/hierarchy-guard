<?php
/**
 * What the current admin request is about to do.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Trash;

/**
 * Core's Pages list trashes and restores bulk selections one ID at a time and
 * stops with wp_die() when an ID is already in, or already out of, the trash.
 * The plugin leaves those IDs to core instead of handling them as subpages.
 * Its Bulk Edit saves a selection one page at a time too.
 */
final class Request {

	/**
	 * Post IDs that core's bulk trash or untrash in edit.php will process in this request.
	 *
	 * @return int[]
	 */
	public static function core_ids(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read only; core verified the bulk-posts nonce before it calls wp_trash_post() or wp_untrash_post().
		if ( 'edit.php' !== ( $GLOBALS['pagenow'] ?? '' ) || ! empty( $_REQUEST['filter_action'] ) ) {
			return [];
		}
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
		if ( ! in_array( $action, [ 'trash', 'untrash' ], true ) ) {
			return [];
		}

		if ( isset( $_REQUEST['ids'] ) ) {
			$raw = explode( ',', sanitize_text_field( wp_unslash( $_REQUEST['ids'] ) ) );
		} elseif ( isset( $_REQUEST['post'] ) ) {
			$raw = array_map( 'sanitize_text_field', (array) wp_unslash( $_REQUEST['post'] ) );
		} else {
			$raw = [];
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return array_values( array_unique( array_filter( array_map( 'absint', $raw ) ) ) );
	}

	/**
	 * Post IDs core's Bulk Edit in edit.php saves in this request, in the
	 * list's order, which bulk_edit_posts() keeps.
	 *
	 * @return int[]
	 */
	public static function bulk_edit_ids(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read only; core verified the bulk-posts nonce before bulk_edit_posts() saves anything.
		if ( 'edit.php' !== ( $GLOBALS['pagenow'] ?? '' ) || ! isset( $_REQUEST['bulk_edit'], $_REQUEST['post'] ) ) {
			return [];
		}
		$raw = array_map( 'sanitize_text_field', (array) wp_unslash( $_REQUEST['post'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return array_values( array_unique( array_filter( array_map( 'absint', $raw ) ) ) );
	}
}
