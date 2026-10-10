<?php
/**
 * Resolves an old path to the post it now leads to.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Redirects;

use HierarchyGuard\Ledger;
use HierarchyGuard\Paths;
use WP_Post;

/**
 * Longest-prefix resolution with a bounded number of rewrite rounds.
 */
final class Resolver {

	public const MAX_ROUNDS = 5;

	/**
	 * Ledger.
	 *
	 * @var Ledger
	 */
	private Ledger $ledger;

	/**
	 * Constructor.
	 *
	 * @param Ledger $ledger Ledger.
	 */
	public function __construct( Ledger $ledger ) {
		$this->ledger = $ledger;
	}

	/**
	 * The publicly viewable post an old path now leads to, or null.
	 *
	 * @param string $post_type Post type the request was for.
	 * @param string $path      Requested path, any form.
	 */
	public function resolve( string $post_type, string $path ): ?WP_Post {
		$path = Paths::normalize( $path );
		$seen = [];

		for ( $round = 0; $round < self::MAX_ROUNDS; $round++ ) {
			if ( '' === $path || isset( $seen[ $path ] ) ) {
				return null;
			}
			$seen[ $path ] = true;

			$candidate = $this->rewrite_once( $post_type, $path );
			if ( null === $candidate ) {
				return null;
			}

			$target = get_page_by_path( $candidate, OBJECT, [ $post_type ] );
			if (
				$target instanceof WP_Post
				&& $target->post_type === $post_type
				&& is_post_publicly_viewable( $target )
				&& ! self::has_trashed_segment( Paths::uri( $target ) )
			) {
				return $target;
			}

			$path = $candidate;
		}

		return null;
	}

	/**
	 * Whether a path runs through a trashed page. Such a URL stops working when
	 * the trash is emptied, so it must never become a cached 301 target.
	 *
	 * @param string $path Normalized path.
	 */
	private static function has_trashed_segment( string $path ): bool {
		foreach ( explode( '/', $path ) as $segment ) {
			if ( str_ends_with( $segment, '__trashed' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Replaces the longest prefix the ledger knows with the location it now maps to.
	 * Ledger hits on trashed posts are skipped so a shorter prefix can match.
	 *
	 * @param string $post_type Post type name.
	 * @param string $path      Normalized path.
	 * @return string|null The rewritten path, or null when no prefix matched.
	 */
	private function rewrite_once( string $post_type, string $path ): ?string {
		$segments = explode( '/', $path );

		for ( $length = count( $segments ); $length >= 1; $length-- ) {
			$prefix = implode( '/', array_slice( $segments, 0, $length ) );
			$hit    = $this->ledger->find( $post_type, $prefix );
			if ( null === $hit ) {
				continue;
			}

			$base = '';
			if ( $hit > 0 ) {
				$post = get_post( $hit );
				if ( ! $post instanceof WP_Post || 'trash' === $post->post_status ) {
					continue;
				}
				$base = Paths::uri( $post );
			}

			$remainder = implode( '/', array_slice( $segments, $length ) );

			return trim( $base . '/' . $remainder, '/' );
		}

		return null;
	}
}
