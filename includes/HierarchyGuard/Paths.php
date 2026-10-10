<?php
/**
 * Path helpers.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard;

use WP_Post;

/**
 * Builds and normalizes page paths from slug chains.
 *
 * A "path" here is the page URI without the post type's rewrite prefix,
 * for example `about/team`, normalized the way get_page_by_path() does.
 */
final class Paths {

	/**
	 * Normalizes a path the way get_page_by_path() does before matching.
	 *
	 * @param string $path Raw or decoded path, with or without slashes.
	 */
	public static function normalize( string $path ): string {
		$path  = rawurlencode( urldecode( $path ) );
		$path  = str_replace( [ '%2F', '%20' ], [ '/', ' ' ], $path );
		$parts = explode( '/', trim( $path, '/' ) );
		$parts = array_map( 'sanitize_title_for_query', $parts );
		$parts = array_filter( $parts, static fn( string $part ): bool => '' !== $part );

		return implode( '/', $parts );
	}

	/**
	 * The path of a post built from its slug and its ancestors' slugs.
	 *
	 * Uses the object's own post_parent, so a $post_before object passed by
	 * post_updated yields the chain as it was before the save. Ancestors with
	 * an empty slug are skipped, matching get_page_uri().
	 *
	 * @param WP_Post $post Post object.
	 */
	public static function uri( WP_Post $post ): string {
		$segments  = [ (string) $post->post_name ];
		$parent_id = (int) $post->post_parent;
		$guard     = 0;

		while ( $parent_id > 0 && $guard < 50 ) {
			++$guard;
			$parent = get_post( $parent_id );
			if ( ! $parent instanceof WP_Post ) {
				break;
			}
			if ( '' !== (string) $parent->post_name ) {
				array_unshift( $segments, (string) $parent->post_name );
			}
			$parent_id = (int) $parent->post_parent;
		}

		return self::normalize( implode( '/', $segments ) );
	}

	/**
	 * Whether this post's URL is publicly reachable and worth recording.
	 *
	 * @param WP_Post $post Post object, usually the before-state.
	 */
	public static function is_recordable( WP_Post $post ): bool {
		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			return false;
		}
		if ( ! Post_Types::is_supported( (string) $post->post_type ) ) {
			return false;
		}
		if ( 'publish' !== $post->post_status ) {
			return false;
		}
		$name = (string) $post->post_name;
		if ( '' === $name || str_ends_with( $name, '__trashed' ) ) {
			return false;
		}
		return true;
	}
}
