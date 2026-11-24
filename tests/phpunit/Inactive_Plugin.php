<?php
/**
 * Deletes a post as WordPress does while the plugin is inactive.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

/**
 * For test cases about what a deletion the plugin never saw leaves behind.
 */
trait Inactive_Plugin {

	/**
	 * Deletes a post for good with none of the plugin's callbacks hooked,
	 * then hooks them back exactly as they were.
	 *
	 * @param int $post_id Post ID.
	 */
	private function delete_for_good_while_inactive( int $post_id ): void {
		global $wp_filter;
		$saved = [];
		foreach ( $wp_filter as $name => $hook ) {
			$saved[ $name ] = clone $hook;
		}
		foreach ( $wp_filter as $name => $hook ) {
			foreach ( $hook->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$function = $callback['function'];
					$owner    = is_array( $function ) ? ( is_object( $function[0] ) ? get_class( $function[0] ) : (string) $function[0] ) : ( is_string( $function ) ? $function : '' );
					if ( str_starts_with( $owner, 'HierarchyGuard\\' ) && ! str_starts_with( $owner, 'HierarchyGuard\\Tests\\' ) ) {
						remove_filter( $name, $function, $priority );
					}
				}
			}
		}
		try {
			wp_delete_post( $post_id, true );
		} finally {
			foreach ( $saved as $name => $hook ) {
				$wp_filter[ $name ] = $hook; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Puts back the hooks taken off above.
			}
		}
	}
}
