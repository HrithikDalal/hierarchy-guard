<?php
/**
 * Path helpers.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard;

use WP_Post;
use WP_Rewrite;

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
	 * Uses the object's own slug and post_parent, so a $post_before object
	 * passed by post_updated yields its own place before the save; each
	 * ancestor is read as it is now, unless $as_before holds it. Ancestors
	 * with an empty slug are skipped, matching get_page_uri().
	 *
	 * @param WP_Post             $post      Post object.
	 * @param array<int,WP_Post>  $as_before Posts to read as these objects instead of as they are now, by ID.
	 * @return string Normalized path, for example `company/about`.
	 */
	public static function uri( WP_Post $post, array $as_before = [] ): string {
		return self::chain( $post, static fn( WP_Post $item ): string => (string) $item->post_name, $as_before );
	}

	/**
	 * The path a post had before it or any ancestor was trashed: each segment
	 * carrying core's trashed suffix gets back the slug core kept in
	 * _wp_desired_post_slug, or the slug with the suffix stripped.
	 *
	 * @param WP_Post $post Post object.
	 * @return string Normalized path.
	 */
	public static function untrashed_uri( WP_Post $post ): string {
		return self::chain( $post, static fn( WP_Post $item ): string => self::untrashed_slug( $item ) );
	}

	/**
	 * Joins the slugs of a post and its ancestors, root first.
	 *
	 * @param WP_Post            $post      Post object.
	 * @param callable           $slug_of   Returns the slug to use for one post.
	 * @param array<int,WP_Post> $as_before Ancestors to read as these objects, by ID.
	 * @return string Normalized path.
	 */
	private static function chain( WP_Post $post, callable $slug_of, array $as_before = [] ): string {
		$segments  = [ (string) $slug_of( $post ) ];
		$parent_id = (int) $post->post_parent;
		$guard     = 0;

		while ( $parent_id > 0 && $guard < 50 ) {
			++$guard;
			$parent = $as_before[ $parent_id ] ?? get_post( $parent_id );
			if ( ! $parent instanceof WP_Post ) {
				break;
			}
			$slug = (string) $slug_of( $parent );
			if ( '' !== $slug ) {
				array_unshift( $segments, $slug );
			}
			$parent_id = (int) $parent->post_parent;
		}

		return self::normalize( implode( '/', $segments ) );
	}

	/**
	 * A post's slug as it was before core trashed it.
	 *
	 * @param WP_Post $post Post object.
	 * @return string
	 */
	private static function untrashed_slug( WP_Post $post ): string {
		$slug = (string) $post->post_name;
		if ( ! self::is_trashed_slug( $slug ) ) {
			return $slug;
		}
		$desired = (string) get_post_meta( $post->ID, '_wp_desired_post_slug', true );
		return '' !== $desired ? $desired : self::strip_trashed_suffix( $slug );
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

	/**
	 * The URL core would build for a post of this type at this path, the way
	 * get_page_link() and get_post_permalink() do.
	 *
	 * @param string $post_type Post type name.
	 * @param string $path      Path without the type's rewrite prefix, such as `about/team`.
	 * @return string Absolute URL, or an empty string without pretty permalinks.
	 */
	public static function url( string $post_type, string $path ): string {
		global $wp_rewrite;

		if ( ! $wp_rewrite instanceof WP_Rewrite || '' === (string) get_option( 'permalink_structure' ) ) {
			return '';
		}
		$path = self::normalize( $path );

		if ( 'page' === $post_type ) {
			$struct = (string) $wp_rewrite->get_page_permastruct();
			return user_trailingslashit( home_url( str_replace( '%pagename%', $path, $struct ) ), 'page' );
		}

		$struct = $wp_rewrite->get_extra_permastruct( $post_type );
		if ( ! is_string( $struct ) || '' === $struct ) {
			return '';
		}
		return home_url( user_trailingslashit( str_replace( '%' . $post_type . '%', $path, $struct ) ) );
	}

	/**
	 * A URL on this site as a path relative to the home URL, such as `/about/team/`.
	 * This is the form redirect plugins expect for the old and the new URL.
	 *
	 * @param string $url Absolute URL on this site.
	 * @return string Path starting with a slash, with any query string kept.
	 */
	public static function relative( string $url ): string {
		$home  = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$path  = (string) wp_parse_url( $url, PHP_URL_PATH );
		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );

		// The home path in any case, as WP::parse_request() strips it.
		if ( '' !== $home && '/' !== $home && 0 === stripos( $path, $home ) ) {
			$path = '/' . substr( $path, strlen( $home ) );
		}
		if ( '' === $path ) {
			$path = '/';
		}

		return $path . ( '' !== $query ? '?' . $query : '' );
	}
}
