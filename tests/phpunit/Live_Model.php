<?php
/**
 * The 404 model on live lookups, for the tests' oracles.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Redirects\Rules;
use WP_Post;

/**
 * Rules::is_404() with each fact looked up when it asks, as a list of pairs
 * reads them ahead: what a test checks the lists and old URL states against.
 */
final class Live_Model {

	/**
	 * Whether the 404 model says a request for a path of a type is a 404.
	 *
	 * @param string $path      Normalized path.
	 * @param string $post_type Post type name.
	 * @return bool
	 */
	public static function is_404( string $path, string $post_type = 'page' ): bool {
		$there = get_page_by_path( $path, OBJECT, $post_type );
		return Rules::is_404(
			$there instanceof WP_Post ? $there : null,
			$path,
			$post_type,
			static function ( string $type, string $name ): bool {
				global $wpdb;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- A test oracle's point lookup on the post_name index.
				foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_type = %s", $name, $type ) ) as $id ) {
					if ( is_post_publicly_viewable( (int) $id ) ) {
						return true;
					}
				}
				return false;
			}
		);
	}
}
