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
 * server reads its facts live, a list of pairs reads them ahead.
 */
final class Rules {

	public const MAX_ROUNDS = 5;

	/**
	 * What Ledger_View::find() answers for a path in the root list.
	 */
	public const ROOT = 0;

	/**
	 * The page a request for an old path is redirected to: only a 404 reaches
	 * the resolver.
	 *
	 * @param Ledger_View                                 $view      Facts.
	 * @param string                                      $post_type Post type the request is for.
	 * @param string                                      $path      Normalized path.
	 * @param array<int,array{0:string,1:int,2:string}>|null $trace  Set to each round's prefix, holder and rewritten path.
	 * @return WP_Post|null
	 */
	public static function serves( Ledger_View $view, string $post_type, string $path, ?array &$trace = null ): ?WP_Post {
		$trace = [];
		if ( ! $view->is_404( $path, $post_type ) ) {
			return null;
		}
		$temporary = false;
		return self::resolve( $view, $post_type, $path, $temporary, $trace );
	}

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
}
