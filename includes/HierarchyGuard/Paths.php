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
	 * Core's trashed-slug suffix: `__trashed`, or `__trashed-2` and up when
	 * wp_unique_post_slug() had to disambiguate it from a trashed sibling.
	 */
	private const TRASHED_SUFFIX = '/__trashed(?:-\d+)?$/';

	/**
	 * Normalizes a path the way get_page_by_path() does before matching.
	 * Unlike core, empty segments are dropped.
	 *
	 * @param string $path Raw or decoded path, with or without slashes.
	 * @return string Normalized path without leading, trailing or empty segments.
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
	 * @return string Normalized path, for example `company/about`.
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
	 * @return bool
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
		if ( '' === $name || self::is_trashed_slug( $name ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Whether a slug carries core's trashed suffix.
	 *
	 * @param string $slug Post slug or path segment.
	 * @return bool
	 */
	public static function is_trashed_slug( string $slug ): bool {
		return 1 === preg_match( self::TRASHED_SUFFIX, $slug );
	}

	/**
	 * Removes core's trashed suffix from a slug.
	 *
	 * @param string $slug Post slug.
	 * @return string The slug without `__trashed` or `__trashed-N`.
	 */
	public static function strip_trashed_suffix( string $slug ): string {
		return (string) preg_replace( self::TRASHED_SUFFIX, '', $slug );
	}

	/**
	 * Whether any segment of a path is a trashed slug. Such a URL stops working
	 * when the trash is emptied, so it must never become a cached 301 target.
	 *
	 * @param string $path Normalized path.
	 * @return bool
	 */
	public static function has_trashed_segment( string $path ): bool {
		foreach ( explode( '/', $path ) as $segment ) {
			if ( self::is_trashed_slug( $segment ) ) {
				return true;
			}
		}
		return false;
	}
}
