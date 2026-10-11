<?php
/**
 * What the server serves, for many old paths at once.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Redirects;

use HierarchyGuard\Ledger;
use HierarchyGuard\Paths;
use OutOfBoundsException;
use WP_Post;

/**
 * Runs Rules for every path of a list of pairs on facts read ahead: the
 * ledger for every prefix in one query a round, get_page_by_path() for every
 * path in one query a round, and the posts found primed a level at a time.
 * No query grows with the number of paths.
 */
final class Batch_Resolver {

	/**
	 * What served() answers for a path a published page, a blog post or an
	 * attachment uses now: WordPress shows it, so no redirect reaches it.
	 */
	public const NOT_404 = -1;

	/**
	 * What lookup() answers for a request path several posts share when core
	 * could not be asked any more: taken as one of them showing there.
	 */
	public const UNKNOWN = -2;

	/**
	 * Most paths several posts share that core's own get_page_by_path() is asked about, per batch.
	 */
	public const FALLBACKS = 20;

	/**
	 * The page the server redirects each path to, for every path at once:
	 * Rules::resolve() on a 404, on facts read ahead. Each run of the rules that asks for
	 * a fact not read yet stops; the facts every run asked for are read in one
	 * query, ledger facts or path lookups, and the runs start again. The paths
	 * go round by round together, so each round costs one ledger query, one
	 * get_page_by_path() lookup for every rewritten path, the request lookups
	 * with the first, and the posts found primed a level at a time. A path with
	 * more segments than the server's cap is not served.
	 *
	 * @param string[]                                $paths         Normalized old paths, at most the count cap.
	 * @param string                                  $post_type     Post type name.
	 * @param array<string,array{live:int,held:bool}> $facts         Ledger facts read so far, by path; more are added.
	 * @param bool                                    $every_request Whether every path is looked up as a request, so each answer that is no redirect says whether WordPress shows something there.
	 * @return array<string,int> Page ID by path; 0 for none, NOT_404 when something is shown there (always told with $every_request, otherwise when known).
	 */
	public static function served( array $paths, string $post_type, array &$facts, bool $every_request = false ): array {
		$view    = new Prefetched_View( $post_type, $facts );
		$out     = [];
		$pending = [];
		$prefix  = Server::prefix( $post_type );
		$room    = Server::max_segments() - ( '' !== $prefix ? substr_count( $prefix, '/' ) + 1 : 0 );
		foreach ( $paths as $path ) {
			$path = (string) $path;
			// The server refuses a longer request before it looks anything up.
			if ( substr_count( $path, '/' ) + 1 > $room ) {
				$out[ $path ] = 0;
				continue;
			}
			$pending[ $path ] = $path;
		}

		$budget = self::FALLBACKS;
		for ( $wave = 0; [] !== $pending && $wave < 2 * ( Rules::MAX_ROUNDS + 2 ); $wave++ ) {
			$needs = $view->take_needs();
			if ( 0 === $wave ) {
				$needs['facts'] = array_values( array_filter( self::prefixes( array_values( $pending ) ), static fn( string $prefix ): bool => ! isset( $facts[ $prefix ] ) ) );
			}
			if ( [] !== $needs['facts'] ) {
				$facts += self::ledger_facts( $needs['facts'], $post_type );
			}
			$found = [];
			if ( [] !== $needs['requests'] || [] !== $needs['candidates'] ) {
				[ $at, $there, $named ] = self::lookup( $needs['requests'], $needs['candidates'], $post_type, $budget );
				$view->add_lookups( $needs['requests'], $at, $needs['candidates'], $there, $named );
				$found = array_filter( array_merge( array_values( $at ), array_values( $there ) ), static fn( int $id ): bool => $id > 0 );
			}
			// The live posts that hold a prefix of the path each run rewrites next, and the posts found, with the pages above them.
			$live = [];
			foreach ( $pending as $current ) {
				foreach ( self::prefixes( [ $current ] ) as $prefix ) {
					if ( ( $facts[ $prefix ]['live'] ?? 0 ) > 0 ) {
						$live[] = $facts[ $prefix ]['live'];
					}
				}
			}
			self::prime_with_ancestors( array_values( array_unique( array_merge( $live, $found ) ) ) );

			$next = [];
			foreach ( array_keys( $pending ) as $start ) {
				$start     = (string) $start;
				$trace     = [];
				$temporary = false;
				$view->begin();
				try {
					// Only a 404 reaches the resolver.
					$target = $view->is_404( $start, $post_type ) ? Rules::resolve( $view, $post_type, $start, $temporary, $trace ) : null;
					if ( null !== $target && ! $view->guessed() ) {
						$out[ $start ] = (int) $target->ID;
						continue;
					}
					if ( null === $target && ( ! $every_request || ! $view->guessed() ) ) {
						$out[ $start ] = $view->knows( $start ) && ! $view->is_404( $start, $post_type ) ? self::NOT_404 : 0;
						continue;
					}
				} catch ( OutOfBoundsException $unread ) {
					// The facts it asked for are read next; it runs again then.
					unset( $unread );
				}
				$view->keep();
				$next[ $start ] = [] !== $trace ? (string) $trace[ count( $trace ) - 1 ][2] : $start;
			}
			$pending = $next;
		}

		foreach ( array_keys( $pending ) as $start ) {
			$out[ (string) $start ] = 0;
		}
		return $out;
	}

	/**
	 * What the ledger says about each path, the way Ledger::find() and
	 * Ledger::held_in_trash() read it: the newest post of the type not in the
	 * trash that holds it, and whether a post in the trash holds it. One query.
	 *
	 * @param string[] $paths     Normalized paths, at most a few per pair listed.
	 * @param string   $post_type Post type name.
	 * @return array<string,array{live:int,held:bool}> Facts by path, for every path asked about.
	 */
	public static function ledger_facts( array $paths, string $post_type ): array {
		global $wpdb;

		$out  = [];
		$keys = [];
		foreach ( $paths as $path ) {
			$out[ (string) $path ]                             = [
				'live' => 0,
				'held' => false,
			];
			$keys[ Ledger::key( $post_type, (string) $path ) ] = (string) $path;
		}
		if ( [] === $keys ) {
			return [];
		}
		$placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $placeholders holds only %s, one per path listed or prefix of one, bounded by the count cap; a range on the meta_key index.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT pm.meta_key, pm.post_id, p.post_status FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key IN ( {$placeholders} ) AND p.post_type = %s ORDER BY pm.meta_id DESC", ...array_merge( array_keys( $keys ), [ $post_type ] ) ), ARRAY_A );

		foreach ( (array) $rows as $row ) {
			$path = $keys[ (string) $row['meta_key'] ] ?? '';
			if ( '' === $path ) {
				continue;
			}
			if ( 'trash' === (string) $row['post_status'] ) {
				$out[ $path ]['held'] = true;
			} elseif ( 0 === $out[ $path ]['live'] ) {
				$out[ $path ]['live'] = (int) $row['post_id'];
			}
		}
		return $out;
	}

	/**
	 * Every path and each of its shorter prefixes, once.
	 *
	 * @param string[] $paths Normalized paths.
	 * @return string[]
	 */
	public static function prefixes( array $paths ): array {
		$out = [];
		foreach ( $paths as $path ) {
			$segments = explode( '/', (string) $path );
			for ( $length = count( $segments ); $length >= 1; $length-- ) {
				$prefix = implode( '/', array_slice( $segments, 0, $length ) );
				if ( isset( $out[ $prefix ] ) ) {
					break;
				}
				$out[ $prefix ] = true;
			}
		}
		unset( $out[''] );
		return array_map( 'strval', array_keys( $out ) );
	}

	/**
	 * The live post each old path leads to in the ledger, the one the resolver
	 * picks: the newest entry among posts not in the trash, and whether it sits
	 * under a page in the trash, which makes the resolver pass over it.
	 *
	 * @param string[]                                $paths Normalized old paths.
	 * @param array<string,array{live:int,held:bool}> $facts From ledger_facts(), for these paths.
	 * @return array<string,array{id:int,trashed:bool}> Holder by path; a path no live post holds is absent.
	 */
	public static function holders( array $paths, array $facts ): array {
		$found = [];
		foreach ( $paths as $path ) {
			$live = $facts[ (string) $path ]['live'] ?? 0;
			if ( $live > 0 ) {
				$found[ (string) $path ] = $live;
			}
		}
		if ( [] === $found ) {
			return [];
		}

		self::prime_with_ancestors( array_values( array_unique( $found ) ) );
		$out = [];
		foreach ( $found as $path => $holder_id ) {
			$holder       = get_post( $holder_id );
			$out[ $path ] = [
				'id'      => $holder_id,
				'trashed' => ! $holder instanceof WP_Post || Paths::has_trashed_segment( Paths::uri( $holder ) ),
			];
		}
		return $out;
	}

	/**
	 * The post at each path now, the way get_page_by_path() finds it, for every
	 * path in one query: rows whose slug is one of the path's segments, matched
	 * from the last segment up through their parents to the top level, a post
	 * of the type winning over an attachment. The posts found are primed.
	 *
	 * @param string[] $paths     Normalized paths.
	 * @param string   $post_type Post type name.
	 * @return array<string,int> Post ID by path, UNKNOWN past the fallbacks; a path no post uses now is absent.
	 */
	public static function owners( array $paths, string $post_type ): array {
		$budget = self::FALLBACKS;
		return self::lookup( $paths, [], $post_type, $budget )[0];
	}

	/**
	 * get_page_by_path() for many paths in one query, two ways: as the main
	 * query looks a request up, for the type and attachments with the first
	 * post of the type winning; and as the resolver looks a candidate up, for
	 * the type alone, where core keeps the last match. The rows are taken in
	 * the order core's query reads them on the post_name index, by ID within
	 * a slug. Where that order decides, between two posts at one full path,
	 * core's own get_page_by_path() is asked, at most $budget times; past
	 * that a request path counts as UNKNOWN and a candidate leads nowhere.
	 * Under /%postname%/ the request lookups also read the blog posts and
	 * attachments the post rules would show at one- and two-segment paths.
	 * The posts found are primed.
	 *
	 * @param string[] $paths      Normalized paths, looked up as a request is.
	 * @param string[] $candidates Normalized paths, looked up as the resolver does.
	 * @param string   $post_type  Post type name.
	 * @param int      $budget     How many more times core may be asked; lowered.
	 * @return array{0:array<string,int>,1:array<string,int>,2:array<string,array<string,bool>>} Post ID by path for each, a path no post uses now absent; and whether anyone can view a post of a type with a slug.
	 */
	private static function lookup( array $paths, array $candidates, string $post_type, int &$budget ): array {
		global $wpdb;

		$names = [];
		foreach ( array_merge( $paths, $candidates ) as $path ) {
			foreach ( explode( '/', (string) $path ) as $name ) {
				$names[ $name ] = true;
			}
		}
		unset( $names[''] );
		if ( [] === $names ) {
			return [ [], [], [] ];
		}

		$posts        = [] !== $paths && 'page' === $post_type && Rules::posts_at_the_root();
		$names        = array_map( 'strval', array_keys( $names ) );
		$placeholders = implode( ',', array_fill( 0, count( $names ), '%s' ) );
		$types        = [] !== $paths ? ( $posts ? "%s, 'attachment', 'post'" : "%s, 'attachment'" ) : '%s';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $placeholders holds only %s, one per segment of the paths; $types is a fixed placeholder list; core's get_page_by_path() query for every path at once, on the post_name index.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_name, post_parent, post_type FROM {$wpdb->posts} WHERE post_name IN ( {$placeholders} ) AND post_type IN ( {$types} )", ...array_merge( $names, [ $post_type ] ) ), OBJECT_K );
		// Core's own query reads one slug's rows by ID on the post_name index, and its loop keeps the first or the last.
		ksort( $rows );

		$named = [];
		foreach ( $rows as $id => $row ) {
			$named[ (string) $row->post_name ][ (int) $id ] = $row;
		}
		[ $at, $unsure_at ]       = self::match( $paths, $rows, $named, $post_type, true );
		[ $there, $unsure_there ] = self::match( $candidates, $rows, $named, $post_type, false );
		foreach ( $unsure_at as $path ) {
			if ( $budget-- > 0 ) {
				$post = get_page_by_path( $path, OBJECT, $post_type );
				unset( $at[ $path ] );
				if ( $post instanceof WP_Post ) {
					$at[ $path ] = (int) $post->ID;
				}
			} else {
				$at[ $path ] = self::UNKNOWN;
			}
		}
		foreach ( $unsure_there as $path ) {
			$post = $budget-- > 0 ? get_page_by_path( $path, OBJECT, [ $post_type ] ) : null;
			unset( $there[ $path ] );
			if ( $post instanceof WP_Post ) {
				$there[ $path ] = (int) $post->ID;
			}
		}

		// The blog post a one-segment request path names, and the attachment the last segment of a two-segment one names.
		$shown = [];
		if ( $posts ) {
			foreach ( array_unique( array_map( 'strval', $paths ) ) as $path ) {
				$segments = explode( '/', $path );
				$type     = 1 === count( $segments ) ? 'post' : ( 2 === count( $segments ) ? 'attachment' : '' );
				$name     = (string) end( $segments );
				foreach ( '' !== $type ? $named[ $name ] ?? [] : [] as $id => $row ) {
					if ( $type === (string) $row->post_type ) {
						$shown[ $type ][ $name ][] = (int) $id;
					}
				}
			}
		}

		$ids = array_values( array_unique( array_filter( array_merge( array_values( $at ), array_values( $there ) ), static fn( int $id ): bool => $id > 0 ) ) );
		if ( [] !== $ids ) {
			_prime_post_caches( $ids, false, false );
		}
		// An attachment is viewable as its parent is.
		$others = [];
		foreach ( $shown as $by_name ) {
			foreach ( $by_name as $list ) {
				$others = array_merge( $others, $list );
			}
		}
		self::prime_with_ancestors( array_values( array_unique( $others ) ) );

		$viewable = [];
		foreach ( $shown as $type => $by_name ) {
			foreach ( $by_name as $name => $list ) {
				$viewable[ $type ][ $name ] = [] !== array_filter( $list, 'is_post_publicly_viewable' );
			}
		}
		return [ $at, $there, $viewable ];
	}

	/**
	 * The matching loop of get_page_by_path() for each path, on rows already read.
	 *
	 * @param string[]                        $paths       Normalized paths.
	 * @param array<int,object>               $rows        Rows by ID.
	 * @param array<string,array<int,object>> $named       The same rows by slug, by ID within a slug.
	 * @param string                          $post_type   Post type name.
	 * @param bool                            $attachments Whether attachments take part, with the first post of the type winning; otherwise the last match does.
	 * @return array{0:array<string,int>,1:string[]} Post ID by path, and the paths where the row order decides between full matches.
	 */
	private static function match( array $paths, array $rows, array $named, string $post_type, bool $attachments ): array {
		$out    = [];
		$unsure = [];
		$types  = $attachments ? [ $post_type, 'attachment' ] : [ $post_type ];
		foreach ( array_unique( array_map( 'strval', $paths ) ) as $path ) {
			$segments = explode( '/', $path );
			$reversed = array_reverse( $segments );
			$found    = 0;
			$matches  = [];
			foreach ( $named[ $reversed[0] ] ?? [] as $page ) {
				if ( ! in_array( (string) $page->post_type, $types, true ) ) {
					continue;
				}
				$count = 0;
				$up    = $page;
				while ( 0 !== (int) $up->post_parent ) {
					$parent = $rows[ (int) $up->post_parent ] ?? null;
					// Core only climbs through rows of the path's own slugs and types.
					if ( null === $parent || ! in_array( (string) $parent->post_name, $segments, true ) || ! in_array( (string) $parent->post_type, $types, true ) ) {
						break;
					}
					++$count;
					if ( ! isset( $reversed[ $count ] ) || (string) $parent->post_name !== $reversed[ $count ] ) {
						break;
					}
					$up = $parent;
				}
				if ( 0 === (int) $up->post_parent && count( $reversed ) === $count + 1 && (string) $up->post_name === $reversed[ $count ] ) {
					$matches[ (string) $page->post_type ][] = (int) $page->ID;
				}
			}
			// As a request the first post of the type wins, or else the last attachment; as a candidate the last match.
			// Either way two of those leave it to the order.
			$deciding = $attachments && isset( $matches[ $post_type ] ) ? $matches[ $post_type ] : array_merge( [], ...array_values( $matches ) );
			if ( [] !== $deciding ) {
				$found = $attachments && isset( $matches[ $post_type ] ) ? $deciding[0] : (int) end( $deciding );
			}
			if ( count( $deciding ) > 1 ) {
				$unsure[] = $path;
			}
			if ( $found > 0 ) {
				$out[ $path ] = $found;
			}
		}
		return [ $out, $unsure ];
	}

	/**
	 * Primes posts and their ancestors, one query per level at most.
	 *
	 * @param int[] $ids Post IDs.
	 */
	public static function prime_with_ancestors( array $ids ): void {
		for ( $depth = 0; [] !== $ids && $depth < 50; $depth++ ) {
			_prime_post_caches( $ids, false, false );
			$parents = [];
			foreach ( $ids as $id ) {
				$post = get_post( $id );
				if ( $post instanceof WP_Post && (int) $post->post_parent > 0 ) {
					$parents[ (int) $post->post_parent ] = true;
				}
			}
			$ids = array_keys( $parents );
		}
	}
}
