<?php
/**
 * The trash policy the Pages list dialog sends with a trash request.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Admin;

use HierarchyGuard\Settings;
use WP_Post;

/**
 * The trash dialog adds the editor's choice to core's own trash link or bulk
 * form. Core checks its nonce before it trashes anything; this checks the
 * same nonce again and applies the choice only to the pages the request names.
 */
final class Trash_Choice {

	public const PARAM = 'hierarchy_guard_policy';

	/**
	 * Hooks into core.
	 */
	public function register(): void {
		// Late, so an editor's explicit choice wins over other filters.
		add_filter( 'hierarchy_guard_trash_policy', [ $this, 'filter' ], 99, 2 );
	}

	/**
	 * Uses the dialog's choice for a page this request trashes.
	 *
	 * @param mixed $policy Policy so far.
	 * @param mixed $post   The page being trashed.
	 * @return mixed
	 */
	public function filter( $policy, $post ) {
		if ( ! $post instanceof WP_Post ) {
			return $policy;
		}
		$chosen = self::chosen( (int) $post->ID );
		return null !== $chosen ? $chosen : $policy;
	}

	/**
	 * The policy the current request chose for a page, or null.
	 *
	 * @param int $post_id Page being trashed.
	 * @return string|null
	 */
	public static function chosen( int $post_id ): ?string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Each branch verifies core's own nonce for the request below.
		if ( ! is_admin() || ! isset( $_REQUEST[ self::PARAM ], $_REQUEST['_wpnonce'] ) ) {
			return null;
		}
		$policy = sanitize_key( wp_unslash( $_REQUEST[ self::PARAM ] ) );
		$nonce  = sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) );
		if ( ! in_array( $policy, Settings::POLICIES, true ) ) {
			return null;
		}

		$page = (string) ( $GLOBALS['pagenow'] ?? '' );
		if ( 'post.php' === $page ) {
			$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
			$target = isset( $_REQUEST['post'] ) ? absint( wp_unslash( $_REQUEST['post'] ) ) : 0;
			$valid  = 'trash' === $action && $target === $post_id && false !== wp_verify_nonce( $nonce, 'trash-post_' . $post_id );
		} elseif ( 'edit.php' === $page ) {
			// Core's list table reads only `action`; its script keeps both selects in step.
			$action  = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
			$targets = isset( $_REQUEST['post'] ) ? array_map( 'absint', (array) wp_unslash( $_REQUEST['post'] ) ) : [];
			$valid   = 'trash' === $action && in_array( $post_id, $targets, true ) && false !== wp_verify_nonce( $nonce, 'bulk-posts' );
		} else {
			$valid = false;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return $valid ? $policy : null;
	}
}
