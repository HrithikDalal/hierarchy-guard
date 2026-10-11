<?php
/**
 * The rules that rewrite an old path to the page it leads to now.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Redirects;

use HierarchyGuard\Paths;
use WP_MatchesMapRegex;
use WP_Post;
use WP_Rewrite;

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
	 * How WP::parse_request() routes a request for a path of a type: the
	 * first of $wp_rewrite->wp_rewrite_rules() that matches it, in their
	 * order, matched as WordPress matches them. A verbose page rule only
	 * matches when a page with a status it shows is at the path: whether one
	 * is there is the request lookup's to tell, so that rule is noted and the
	 * rules after it are walked on. The rule found is named by what its query
	 * asks for:
	 *
	 * - `own`: the post of the type at the path: the page rule with no check,
	 *   or the type's own rule;
	 * - `post`: a blog post, by its slug alone: a category or a date the rule
	 *   names as well does not change which post the query finds, or only
	 *   narrows it;
	 * - `attachment`: an attachment, by its slug;
	 * - `category`, `tag`: the archive of a category or tag, by its slug;
	 * - `none`: no rule matches, so the request is a 404;
	 * - `other`: any other rule, as another type's, a taxonomy's, an
	 *   author's, a date's, a feed's, a verbose page rule for part of the path
	 *   or one this type cannot answer for. What it shows is not looked into.
	 *
	 * @param string $post_type Post type the request is for.
	 * @param string $path      Normalized path, without the type's rewrite prefix.
	 * @return array{0:bool,1:string,2:string} Whether the page rule for the whole path comes first, then the kind of the first other rule that matches and the slug it names.
	 */
	public static function route( string $post_type, string $path ): array {
		global $wp_rewrite;

		$other = [ false, 'other', '' ];
		$rules = $wp_rewrite instanceof WP_Rewrite ? $wp_rewrite->wp_rewrite_rules() : [];
		if ( ! is_array( $rules ) || [] === $rules ) {
			// With no rules WordPress reads no permalink at all.
			return $other;
		}
		$prefix  = Server::prefix( $post_type );
		$request = '' !== $prefix ? $prefix . '/' . $path : $path;
		// On PATH_INFO permalinks, from the first rule that starts with the index file on, the rules match it in front of the path.
		$file    = $wp_rewrite->using_index_permalinks() ? $wp_rewrite->index : $request;
		$subject = $request;
		$page    = false;
		foreach ( $rules as $match => $query ) {
			$match = (string) $match;
			if ( $file !== $request && str_starts_with( $match, $file ) ) {
				$subject = $file . '/' . $request;
			}
			if ( 1 !== preg_match( '#^' . $match . '#', $subject, $matches ) && 1 !== preg_match( '#^' . $match . '#', urldecode( $subject ), $matches ) ) {
				continue;
			}
			if ( $wp_rewrite->use_verbose_page_rules && 1 === preg_match( '/pagename=\$matches\[([0-9]+)\]/', (string) $query, $index ) ) {
				// Only a page request for the whole path has its check read.
				$checked = (string) ( $matches[ (int) $index[1] ] ?? '' );
				if ( 'page' !== $post_type || $checked !== $path ) {
					return $other;
				}
				$page = true;
				continue;
			}
			return array_merge( [ $page ], self::kind( self::vars( (string) $query, $matches ), $post_type, $path ) );
		}
		return [ $page, 'none', '' ];
	}

	/**
	 * Whether WP's main query answers a request for a path with a 404, by
	 * the rule route() found: only then does the resolver run. $at is the
	 * post at the path, get_page_by_path( $path, OBJECT, $post_type ), as the
	 * page rule and a type's own rule find it: it is shown when anyone can
	 * view it. A verbose page rule passes over it when its status does not
	 * let it match, and the next rule decides. A rule this model does not
	 * look into is taken to show something.
	 *
	 * @param array{0:bool,1:string,2:string} $route  From route().
	 * @param WP_Post|null                    $at     The post at the path, as the page rule finds it.
	 * @param callable                        $exists Whether what a rule asks for is there: fn( string $kind, string $slug ): bool, for a kind of `post`, `attachment`, `category` or `tag`, a post anyone can view or a term.
	 * @return bool
	 */
	public static function is_404( array $route, ?WP_Post $at, callable $exists ): bool {
		[ $page, $kind, $slug ] = $route;
		if ( $page && $at instanceof WP_Post && self::page_rule_matches( $at ) ) {
			return ! is_post_publicly_viewable( $at );
		}
		switch ( $kind ) {
			case 'own':
				return ! ( $at instanceof WP_Post && is_post_publicly_viewable( $at ) );
			case 'none':
				return true;
			case 'post':
			case 'attachment':
			case 'category':
			case 'tag':
				return ! $exists( $kind, $slug );
		}
		return false;
	}

	/**
	 * A matched rule's query variables, as WP::parse_request() reads them:
	 * the matches put into the query, then the query parsed. Empty ones are
	 * left out.
	 *
	 * @param string   $query   The rule's query.
	 * @param string[] $matches What the rule matched.
	 * @return array<string,string>
	 */
	private static function vars( string $query, array $matches ): array {
		parse_str( WP_MatchesMapRegex::apply( (string) preg_replace( '!^.+\?!', '', $query ), $matches ), $vars );
		return array_filter( $vars, static fn( $value ): bool => is_string( $value ) && '' !== $value );
	}

	/**
	 * What a rule's query asks for; see route().
	 *
	 * @param array<string,string> $vars      The rule's query variables, none empty.
	 * @param string               $post_type Post type the request is for.
	 * @param string               $path      Normalized path, without the type's rewrite prefix.
	 * @return array{0:string,1:string} Kind and slug.
	 */
	private static function kind( array $vars, string $post_type, string $path ): array {
		$keys  = array_keys( $vars );
		$only  = static fn( string ...$allowed ): bool => [] === array_diff( $keys, $allowed );
		$other = [ 'other', '' ];
		if ( isset( $vars['pagename'] ) ) {
			// The page rule with no verbose check, or a hierarchical type's own rule when the type has no query variable.
			$type = $vars['post_type'] ?? 'page';
			$own  = $type === $post_type && $only( 'pagename', 'post_type' );
			return $own && $path === $vars['pagename'] ? [ 'own', '' ] : $other;
		}
		$object = 'page' !== $post_type ? get_post_type_object( $post_type ) : null;
		$var    = null !== $object && is_string( $object->query_var ) ? $object->query_var : '';
		if ( '' !== $var && isset( $vars[ $var ] ) ) {
			return $only( $var ) && $path === $vars[ $var ] ? [ 'own', '' ] : $other;
		}
		if ( isset( $vars['attachment'] ) ) {
			return $only( 'attachment' ) ? [ 'attachment', $vars['attachment'] ] : $other;
		}
		if ( isset( $vars['name'] ) ) {
			return $only( 'name', 'category_name', 'year', 'monthnum', 'day', 'hour', 'minute', 'second', 'author_name' ) ? [ 'post', $vars['name'] ] : $other;
		}
		if ( isset( $vars['category_name'] ) ) {
			// The query reads a category path by its last slug.
			return $only( 'category_name' ) ? [ 'category', wp_basename( $vars['category_name'] ) ] : $other;
		}
		if ( isset( $vars['tag'] ) ) {
			return $only( 'tag' ) ? [ 'tag', $vars['tag'] ] : $other;
		}
		return $other;
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
