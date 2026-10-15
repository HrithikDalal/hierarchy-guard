<?php
/**
 * Serves redirects for old hierarchical URLs.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Redirects;

use HierarchyGuard\Paths;
use HierarchyGuard\Post_Types;
use HierarchyGuard\Settings;
use WP;
use WP_Post;
use WP_Rewrite;

/**
 * Runs on 404s at template_redirect priority 9, before redirect_canonical()
 * and its fuzzy guess, and before wp_old_slug_redirect().
 */
final class Server {

	public const PRIORITY = 9;

	/**
	 * Default cap on request path segments.
	 */
	public const MAX_SEGMENTS = 20;

	/**
	 * Resolver.
	 *
	 * @var Resolver
	 */
	private Resolver $resolver;

	/**
	 * Constructor.
	 *
	 * @param Resolver $resolver Resolver.
	 */
	public function __construct( Resolver $resolver ) {
		$this->resolver = $resolver;
	}

	/**
	 * Hooks into core.
	 */
	public function register(): void {
		add_action( 'template_redirect', [ $this, 'maybe_redirect' ], self::PRIORITY );
	}

	/**
	 * Redirects and exits when the current 404 maps to a page.
	 */
	public function maybe_redirect(): void {
		$target = $this->target_for_current_request();
		if ( null === $target ) {
			return;
		}

		// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- A page target passed wp_validate_redirect() in target_for_current_request(); a URL target, which only an administrator can set, passed esc_url_raw() as http(s).
		if ( wp_redirect( $target['url'], $target['status'], 'Hierarchy Guard' ) ) {
			exit;
		}
	}

	/**
	 * The redirect for the current request, or null when there is none.
	 *
	 * @return array{url:string,status:int}|null
	 */
	public function target_for_current_request(): ?array {
		global $wp;

		if ( ! is_404() || ! Settings::serve_redirects() ) {
			return null;
		}
		if ( ! in_array( $this->request_method(), [ 'GET', 'HEAD' ], true ) ) {
			return null;
		}
		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			return null;
		}
		if ( ! $wp instanceof WP ) {
			return null;
		}

		$request = $this->parse_request( (string) $wp->request );
		if ( '' === $request['path'] ) {
			return null;
		}

		if ( substr_count( $request['path'], '/' ) + 1 > self::max_segments() ) {
			return null;
		}

		$target    = null;
		$temporary = false;
		$code      = 0;
		foreach ( self::candidates( $request['path'] ) as [ $post_type, $path ] ) {
			$target = $this->resolver->resolve( $post_type, $path, $temporary, $code );
			if ( null !== $target ) {
				break;
			}
		}
		if ( null === $target ) {
			return null;
		}

		// A URL target is sent as it was set: the suffixes are re-applied to a page only.
		$post = $target instanceof WP_Post ? $target : null;
		if ( null !== $post ) {
			$url = get_permalink( $post );
			if ( ! is_string( $url ) || '' === $url ) {
				return null;
			}
			$url = $this->reapply_suffixes( $url, $post, $request );
		} else {
			$url = (string) $target;
		}
		$url = $this->append_query_string( $url );

		/**
		 * Filters the HTTP status used for a hierarchy redirect.
		 *
		 * @param int          $status Status code: the 301 or 302 chosen on the Redirects tab, or else 301, or 302 while a restore from the trash could still undo the move the redirect follows.
		 * @param WP_Post|null $target The post being redirected to, null for a URL target.
		 * @param string       $path   The requested path, normalized, suffixes removed.
		 */
		$status = (int) apply_filters( 'hierarchy_guard_redirect_status', $code > 0 ? $code : ( $temporary ? 302 : 301 ), $post, $request['path'] );
		if ( ! in_array( $status, [ 301, 302, 307, 308 ], true ) ) {
			$status = 301;
		}

		/**
		 * Filters the URL a hierarchy redirect sends visitors to. A page
		 * target's URL still passes wp_validate_redirect(); a URL target's is
		 * sent only while it is http(s).
		 *
		 * @param string       $url    Target URL, with the request's query string appended.
		 * @param WP_Post|null $target The post being redirected to, null for a URL target.
		 * @param string       $path   The requested path, normalized, suffixes removed.
		 */
		$url = (string) apply_filters( 'hierarchy_guard_redirect_url', $url, $post, $request['path'] );
		$url = null !== $post ? wp_validate_redirect( $url, '' ) : self::external( $url );
		if ( '' === $url ) {
			return null;
		}

		// Never redirect to the URL being requested; that would loop. Compare the
		// raw request path: on PATH_INFO permalinks $wp->request omits index.php
		// while the target keeps it. The query string is carried over, so it is
		// left out of the comparison. A URL target on another host never loops.
		$requested = untrailingslashit( (string) wp_parse_url( $this->request_uri(), PHP_URL_PATH ) );
		$here      = null !== $post || strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) === strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( $here && untrailingslashit( (string) wp_parse_url( $url, PHP_URL_PATH ) ) === $requested ) {
			return null;
		}

		return [
			'url'    => $url,
			'status' => $status,
		];
	}

	/**
	 * A URL target as it is sent: through esc_url_raw(), and only while it
	 * is http(s), typed so: esc_url_raw() would read `example.org/x` as
	 * http://example.org/x.
	 *
	 * @param string $url URL.
	 * @return string The URL, or '' when it is not http(s).
	 */
	private static function external( string $url ): string {
		if ( ! in_array( strtolower( (string) wp_parse_url( trim( $url ), PHP_URL_SCHEME ) ), [ 'http', 'https' ], true ) ) {
			return '';
		}
		$url = esc_url_raw( $url, [ 'http', 'https' ] );
		return in_array( strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ), [ 'http', 'https' ], true ) ? $url : '';
	}

	/**
	 * The most path segments a request may have to be resolved.
	 *
	 * @return int
	 */
	public static function max_segments(): int {
		/**
		 * Filters the maximum number of path segments a request may have to be resolved.
		 *
		 * @param int $max Segment cap.
		 */
		return (int) apply_filters( 'hierarchy_guard_max_segments', self::MAX_SEGMENTS );
	}

	/**
	 * The rewrite prefix a post type's URLs start with, normalized: empty for
	 * pages and for a type without a permastruct.
	 *
	 * @param string $type Post type name.
	 * @return string
	 */
	public static function prefix( string $type ): string {
		global $wp_rewrite;

		if ( 'page' === $type || ! $wp_rewrite instanceof WP_Rewrite ) {
			return '';
		}
		$struct = $wp_rewrite->get_extra_permastruct( $type );
		if ( ! is_string( $struct ) || '' === $struct ) {
			return '';
		}
		$prefix = trim( str_replace( '%' . $type . '%', '', $struct ), '/' );
		if ( str_starts_with( $prefix, $wp_rewrite->index . '/' ) ) {
			$prefix = substr( $prefix, strlen( $wp_rewrite->index ) + 1 );
		}
		return Paths::normalize( $prefix );
	}

	/**
	 * Splits $wp->request into the page path and the suffixes core appends to it.
	 *
	 * @param string $request Home path removed, slashes trimmed, still percent-encoded.
	 * @return array{path:string,page:int,paged:int,cpage:int,trackback:bool,feed:string,embed:bool}
	 */
	private function parse_request( string $request ): array {
		global $wp_rewrite;

		$index = $wp_rewrite instanceof WP_Rewrite ? $wp_rewrite->index : 'index.php';
		$feeds = $wp_rewrite instanceof WP_Rewrite ? (array) $wp_rewrite->feeds : [ 'feed', 'rdf', 'rss', 'rss2', 'atom' ];
		$base  = $wp_rewrite instanceof WP_Rewrite ? $wp_rewrite->pagination_base : 'page';
		$cbase = $wp_rewrite instanceof WP_Rewrite ? $wp_rewrite->comments_pagination_base : 'comment-page';
		$parts = [
			'path'      => '',
			'page'      => 0,
			'paged'     => 0,
			'cpage'     => 0,
			'trackback' => false,
			'feed'      => '',
			'embed'     => false,
		];

		$request = trim( $request, '/' );
		if ( str_starts_with( $request, $index . '/' ) ) {
			$request = substr( $request, strlen( $index ) + 1 );
		}

		$segments = array_values( array_filter( explode( '/', $request ), static fn( string $segment ): bool => '' !== $segment ) );
		$last     = (string) end( $segments );

		if ( 'embed' === $last ) {
			array_pop( $segments );
			$parts['embed'] = true;
		} elseif ( 'trackback' === $last && count( $segments ) > 1 ) {
			array_pop( $segments );
			$parts['trackback'] = true;
		} elseif ( count( $segments ) > 1 && 1 === preg_match( '/^' . preg_quote( $cbase, '/' ) . '-(\d+)$/', $last, $cpage ) ) {
			array_pop( $segments );
			$parts['cpage'] = (int) $cpage[1];
		} elseif ( in_array( $last, $feeds, true ) ) {
			array_pop( $segments );
			$parts['feed'] = 'feed' === $last ? get_default_feed() : $last;
			if ( 'feed' === end( $segments ) ) {
				array_pop( $segments );
			}
		} elseif ( '' !== $last && ctype_digit( $last ) ) {
			array_pop( $segments );
			if ( end( $segments ) === $base ) {
				array_pop( $segments );
				$parts['paged'] = (int) $last;
			} else {
				$parts['page'] = (int) $last;
			}
		}

		$parts['path'] = Paths::normalize( implode( '/', $segments ) );

		return $parts;
	}

	/**
	 * The (post type, path) pairs a request path could stand for, most specific first:
	 * hierarchical custom types whose rewrite prefix the path starts with, then pages.
	 *
	 * @param string $path Normalized request path, suffixes removed.
	 * @return array<int,array{0:string,1:string}>
	 */
	public static function candidates( string $path ): array {
		global $wp_rewrite;

		$typed = [];
		if ( $wp_rewrite instanceof WP_Rewrite ) {
			foreach ( Post_Types::supported() as $type ) {
				if ( 'page' === $type ) {
					continue;
				}
				$prefix = self::prefix( $type );
				if ( '' !== $prefix && str_starts_with( $path, $prefix . '/' ) ) {
					$typed[] = [ strlen( $prefix ), $type, substr( $path, strlen( $prefix ) + 1 ) ];
				}
			}
		}

		usort( $typed, static fn( array $a, array $b ): int => $b[0] <=> $a[0] );

		$candidates = array_map( static fn( array $entry ): array => [ $entry[1], $entry[2] ], $typed );
		if ( Post_Types::is_supported( 'page' ) ) {
			$candidates[] = [ 'page', $path ];
		}

		return $candidates;
	}

	/**
	 * Re-applies the suffixes parse_request() set aside, in the shapes core builds them.
	 *
	 * @param string                                                       $url     Target permalink.
	 * @param WP_Post                                                      $target  Target post.
	 * @param array{path:string,page:int,paged:int,cpage:int,trackback:bool,feed:string,embed:bool} $request Parsed request.
	 */
	private function reapply_suffixes( string $url, WP_Post $target, array $request ): string {
		global $wp_rewrite;

		if ( $request['embed'] ) {
			return user_trailingslashit( trailingslashit( $url ) . 'embed', 'embed' );
		}
		if ( '' !== $request['feed'] ) {
			$feed = (string) get_post_comments_feed_link( $target->ID, $request['feed'] );
			return '' !== $feed ? $feed : $url;
		}
		if ( $request['trackback'] ) {
			return user_trailingslashit( trailingslashit( $url ) . 'trackback', 'single_trackback' );
		}
		if ( $request['cpage'] > 0 ) {
			$base = $wp_rewrite instanceof WP_Rewrite ? $wp_rewrite->comments_pagination_base : 'comment-page';
			return user_trailingslashit( trailingslashit( $url ) . $base . '-' . $request['cpage'], 'commentpaged' );
		}
		if ( $request['paged'] > 1 ) {
			return user_trailingslashit( trailingslashit( $url ) . 'page/' . $request['paged'], 'paged' );
		}
		if ( $request['page'] > 1 ) {
			return user_trailingslashit( trailingslashit( $url ) . $request['page'], 'single_paged' );
		}
		return $url;
	}

	/**
	 * Carries the current request's query string over to the target URL,
	 * before any #fragment a URL target has. The query is read from the
	 * request URI, as redirect_canonical() does, because some servers
	 * rewrite QUERY_STRING (nginx's `/index.php?q=$uri&$args`).
	 *
	 * @param string $url Target URL.
	 * @return string
	 */
	private function append_query_string( string $url ): string {
		$query = (string) wp_parse_url( $this->request_uri(), PHP_URL_QUERY );
		if ( '' === $query ) {
			return $url;
		}
		// A URL target may carry a #fragment: the query goes before it, or the browser never sends it.
		$parts    = explode( '#', $url, 2 );
		$parts[0] = $parts[0] . ( str_contains( $parts[0], '?' ) ? '&' : '?' ) . $query;
		return implode( '#', $parts );
	}

	/**
	 * The raw request URI, empty when there is none (WP-CLI, cron).
	 *
	 * @return string
	 */
	private function request_uri(): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Kept verbatim (sanitize_text_field() strips percent-encoding). Only its path is compared, and its query reaches the redirect through wp_validate_redirect(), which runs wp_sanitize_redirect(), for a page target, or through external(), esc_url_raw() and http(s) only, for a URL target.
		return isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
	}

	/**
	 * The request method, GET when unknown (WP-CLI, tests).
	 */
	private function request_method(): string {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( (string) wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'get';
		return strtoupper( $method );
	}
}
