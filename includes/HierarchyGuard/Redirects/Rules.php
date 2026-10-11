<?php
/**
 * The rules that rewrite an old path to the page it leads to now.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Redirects;

use HierarchyGuard\Paths;
use WP_Post;

/**
 * Longest-prefix rewriting with a bounded number of rounds, and the test a
 * page must pass to be redirected to. The one copy of these rules: the
 * server's resolver reads its facts live, a list of pairs reads them ahead.
 * Also a model of whether WordPress answers a request with a 404, which only
 * a list of pairs uses: the server asks WordPress itself.
 */
final class Rules {

	public const MAX_ROUNDS = 5;

	/**
	 * What Ledger_View::find() answers for a path in the root list.
	 */
	public const ROOT = 0;

	/**
	 * The publicly viewable post an old path now leads to, or null.
	 *
	 * @param Ledger_View                                 $view      Facts.
	 * @param string                                      $post_type Post type name.
	 * @param string                                      $path      Normalized path.
	 * @param bool                                        $temporary Set to true when a prefix used is held by a page in the trash.
	 * @param array<int,array{0:string,1:int,2:string}>|null $trace  Each round's prefix, holder and rewritten path is added.
	 * @return WP_Post|null
	 */
	public static function resolve( Ledger_View $view, string $post_type, string $path, bool &$temporary, ?array &$trace = null ): ?WP_Post {
		$seen = [];

		for ( $round = 0; $round < self::MAX_ROUNDS; $round++ ) {
			if ( '' === $path || isset( $seen[ $path ] ) ) {
				return null;
			}
			$seen[ $path ] = true;

			$candidate = self::rewrite_once( $view, $post_type, $path, $temporary, $trace );
			if ( null === $candidate ) {
				return null;
			}

			$target = $view->page_by_path( $candidate, $post_type );
			if ( self::accepts( $view, $target, $post_type ) ) {
				return $target;
			}

			$path = $candidate;
		}

		return null;
	}

	/**
	 * Replaces the longest prefix the ledger knows with the location it now maps to.
	 * Ledger hits on trashed posts, or on posts under a trashed ancestor, are
	 * skipped so a shorter prefix can match. An exact old parent path whose page
	 * is only in the trash resolves to nothing: a 301 to the grandparent would
	 * stay in browsers after the page is restored.
	 *
	 * @param Ledger_View                                 $view      Facts.
	 * @param string                                      $post_type Post type name.
	 * @param string                                      $path      Normalized path.
	 * @param bool                                        $temporary Set to true when the prefix used is held by a page in the trash.
	 * @param array<int,array{0:string,1:int,2:string}>|null $trace  The prefix, its holder and the rewritten path are added.
	 * @return string|null The rewritten path, or null when no prefix matched.
	 */
	public static function rewrite_once( Ledger_View $view, string $post_type, string $path, bool &$temporary, ?array &$trace = null ): ?string {
		$segments = explode( '/', $path );

		for ( $length = count( $segments ); $length >= 1; $length-- ) {
			$prefix = implode( '/', array_slice( $segments, 0, $length ) );
			$hit    = $view->find( $post_type, $prefix );
			if ( null === $hit ) {
				continue;
			}

			$base = '';
			if ( $hit > 0 ) {
				$post = $view->post( $hit );
				if ( ! $post instanceof WP_Post || 'trash' === $post->post_status ) {
					continue;
				}
				$base = $view->uri( $post );
				if ( Paths::has_trashed_segment( $base ) ) {
					continue;
				}
				if ( count( $segments ) === $length && $view->held_in_trash( $post_type, $prefix ) ) {
					return null;
				}
			}

			$remainder = implode( '/', array_slice( $segments, $length ) );

			if ( ! $temporary && $view->held_in_trash( $post_type, $prefix ) ) {
				$temporary = true;
			}

			$candidate = trim( $base . '/' . $remainder, '/' );
			$trace[]   = [ $prefix, $hit, $candidate ];
			return $candidate;
		}

		return null;
	}

	/**
	 * Whether a post found at a rewritten path is one to redirect to: of the
	 * type, viewable by anyone, and not under the trash.
	 *
	 * @param Ledger_View  $view      Facts.
	 * @param WP_Post|null $target    Post found, or null.
	 * @param string       $post_type Post type name.
	 * @return bool
	 */
	public static function accepts( Ledger_View $view, ?WP_Post $target, string $post_type ): bool {
		return $target instanceof WP_Post
			&& $target->post_type === $post_type
			&& is_post_publicly_viewable( $target )
			&& ! Paths::has_trashed_segment( $view->uri( $target ) );
	}

	/**
	 * Whether WP's main query answers a request for a path with a 404: only
	 * then does the resolver run. $at is the post the page rule finds,
	 * get_page_by_path( $path, OBJECT, $post_type ), which serves the request
	 * when anyone can view it. Under /%postname%/ the page rule only matches
	 * a post whose status lets it, and the post rules come next: a blog post
	 * named as a one-segment path, and an attachment named as the last segment
	 * of a two-segment path, wherever it is attached, are served there. Other
	 * rewrite rules are not looked at.
	 *
	 * @param WP_Post|null $at        The post at the path, as the page rule finds it.
	 * @param string       $path      Normalized path.
	 * @param string       $post_type Post type the request is for.
	 * @param callable     $named     Whether anyone can view a post of a type with a slug: fn( string $type, string $name ): bool.
	 * @return bool
	 */
	public static function is_404( ?WP_Post $at, string $path, string $post_type, callable $named ): bool {
		$postname = 'page' === $post_type && self::posts_at_the_root();
		if ( $at instanceof WP_Post && ( ! $postname || self::page_rule_matches( $at ) ) ) {
			return ! is_post_publicly_viewable( $at );
		}
		if ( ! $postname ) {
			return true;
		}
		$segments = explode( '/', $path );
		if ( 1 === count( $segments ) ) {
			return ! $named( 'post', $segments[0] );
		}
		if ( 2 === count( $segments ) ) {
			return ! $named( 'attachment', $segments[1] );
		}
		return true;
	}

	/**
	 * Whether blog posts live at the top level, /%postname%/, where a request
	 * the page rule passes over goes to a post or an attachment.
	 *
	 * @return bool
	 */
	public static function posts_at_the_root(): bool {
		$struct = trim( (string) get_option( 'permalink_structure' ), '/' );
		if ( str_starts_with( $struct, 'index.php/' ) ) {
			$struct = substr( $struct, strlen( 'index.php/' ) );
		}
		return '%postname%' === $struct;
	}

	/**
	 * Whether the page rule matches a post found at the path: WP::parse_request()
	 * passes over one whose status is neither public, protected nor private
	 * and is left out of search, as a post in the trash.
	 *
	 * @param WP_Post $at The post found.
	 * @return bool
	 */
	private static function page_rule_matches( WP_Post $at ): bool {
		$status = get_post_status_object( (string) $at->post_status );
		return ! ( null !== $status && ! $status->public && ! $status->protected && ! $status->private && $status->exclude_from_search );
	}
}
