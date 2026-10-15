<?php
/**
 * What the server serves, for many old paths at once, as a model of it tells.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Redirects;

use HierarchyGuard\Ledger;
use HierarchyGuard\Paths;
use HierarchyGuard\Trash\Markers;
use OutOfBoundsException;
use UnexpectedValueException;
use WP_Post;

/**
 * Runs Rules for every path of a list of pairs on facts read ahead: the
 * ledger for every prefix in one query a round, get_page_by_path() for every
 * path in one query a round, and the posts found primed a level at a time.
 * No query grows with the number of paths. What WordPress shows at a path is
 * Rules::is_404()'s model of it, not a main query.
 */
final class Batch_Resolver {

	/**
	 * What served() answers for a path WordPress shows something at, by
	 * Rules::is_404(): a published page, a blog post, an attachment, an
	 * archive, or what a rule the model does not look into shows. No
	 * redirect reaches it.
	 */
	public const NOT_404 = -1;

	/**
	 * What lookup() answers for a path several posts share when core could
	 * not be asked any more. As a request it is taken as one of them showing
	 * there; as a rewritten path it ends the run, which is then not served.
	 */
	public const UNKNOWN = -2;

	/**
	 * What served() answers for a path a record's URL target answers: the
	 * URL is in the $chosen it sets.
	 */
	public const URL = -3;

	/**
	 * Most paths several posts share that core's own get_page_by_path() is asked about, per batch.
	 */
	public const FALLBACKS = 20;

	/**
	 * The page each path is redirected to, for every path at once, as far as
	 * a model of the server can tell it: the request must be a 404 by
	 * Rules::is_404(), and Rules::resolve() must lead to the page, on facts
	 * read ahead. Where the model cannot tell for sure, the path is not
	 * served. A path with more segments than the server's cap is not served.
	 * The server tries a type whose rewrite prefix starts the request before
	 * this one, longest prefix first, so a path one of those resolves is that
	 * post's. A path redirected to a post of this type is temporary when the
	 * server would answer it with a 302: a restore from the trash could still
	 * undo the move the redirect follows. A record's override is honoured as
	 * the server honours it: a URL target answers its exact old path with
	 * URL, and a chosen code is set in $chosen. The record the rules use
	 * first for each path this type answers or passes on is set in $used, so
	 * a caller can tell whether a given record is the one that answers it.
	 *
	 * @param string[]                                $paths     Normalized old paths, at most the count cap.
	 * @param string                                  $post_type Post type name.
	 * @param array<string,array{live:int,held:bool,newest:bool,trashed:int[],target?:array{url:string,code:int}|null}> $facts     Ledger facts read so far, by path; more are added, for every path.
	 * @param array<string,true>|null                 $temporary Set to the paths redirected by this type's records that are temporary.
	 * @param array<string,array{url:string,code:int}>|null $chosen Set to the URL target and the chosen code of each path this type's records answer with an override.
	 * @param array<string,array{0:string,1:int}>|null $used Set to the prefix and the holder (Rules::ROOT for the root list) of the first record the rules use for each path, where no type the server tries first takes the path; a path the rules use no record for is absent.
	 * @return array<string,int> Post ID by path, a post of another type when the server tries that type first; 0 for none, NOT_404 when something is known to be shown there, URL for a URL target.
	 */
	public static function served( array $paths, string $post_type, array &$facts, ?array &$temporary = null, ?array &$chosen = null, ?array &$used = null ): array {
		$prefix = Server::prefix( $post_type );
		$room   = self::room( $post_type );
		$out    = [];
		$within = [];
		foreach ( $paths as $path ) {
			$path = (string) $path;
			// The server refuses a longer request before it looks anything up.
			if ( substr_count( $path, '/' ) + 1 > $room ) {
				$out[ $path ] = 0;
				continue;
			}
			$within[ $path ] = true;
		}
		$held    = [];
		$answers = [];
		$firsts  = [];
		$found   = self::run( array_map( 'strval', array_keys( $within ) ), $post_type, $facts, true, $held, $answers, $firsts );
		$ours    = $found;

		// The candidates the server tries before this type's own, by path.
		$before = [];
		$asks   = [];
		foreach ( $found as $start => $target ) {
			if ( $target < 0 && self::URL !== $target ) {
				continue;
			}
			$start   = (string) $start;
			$list    = [];
			$own     = false;
			$request = '' !== $prefix ? $prefix . '/' . $start : $start;
			foreach ( Server::candidates( $request ) as [ $type, $path ] ) {
				if ( $type === $post_type && $path === $start ) {
					$own = true;
					break;
				}
				$list[]                 = [ $type, $path ];
				$asks[ $type ][ $path ] = true;
			}
			if ( [] !== $list || ! $own ) {
				$before[ $start ] = [ $list, $own ];
			}
		}
		$resolved = [];
		foreach ( $asks as $type => $by_path ) {
			$other             = [];
			$resolved[ $type ] = self::run( array_map( 'strval', array_keys( $by_path ) ), (string) $type, $other, false );
		}
		foreach ( $before as $start => [ $list, $own ] ) {
			$target = $own ? $found[ $start ] : 0;
			foreach ( $list as [ $type, $path ] ) {
				$first = $resolved[ $type ][ $path ] ?? self::UNKNOWN;
				if ( 0 !== $first ) {
					// The server redirects there first, or may: not here.
					$target = $first > 0 ? $first : 0;
					break;
				}
			}
			$found[ $start ] = $target;
		}

		$chains = [];
		$chosen = [];
		$urls   = [];
		$used   = [];
		foreach ( $found as $start => $target ) {
			$start         = (string) $start;
			$out[ $start ] = self::UNKNOWN === $target ? 0 : $target;
			if ( $target !== $ours[ $start ] ) {
				continue;
			}
			if ( isset( $firsts[ $start ] ) ) {
				$used[ $start ] = $firsts[ $start ];
			}
			if ( isset( $answers[ $start ] ) ) {
				$chosen[ $start ] = $answers[ $start ];
			}
			if ( $target > 0 ) {
				$chains[ $start ] = array_merge( [ $target ], array_map( 'intval', get_post_ancestors( $target ) ) );
			} elseif ( self::URL === $target && isset( $held[ $start ] ) ) {
				$urls[ $start ] = true;
			}
		}
		$temporary = self::temporary( $chains, $held ) + $urls;
		return $out;
	}

	/**
	 * The paths the server answers with a 302, as Resolver::resolve() tells
	 * it: a prefix the rules used is held by a page in the trash, or the
	 * target or a page above it sits where the trash flow moved it. One query.
	 *
	 * @param array<string,int[]> $chains Each path's target and the pages above it, by path.
	 * @param array<string,true>  $held   Paths whose rules used a prefix a page in the trash holds.
	 * @return array<string,true>
	 */
	private static function temporary( array $chains, array $held ): array {
		$moved = Markers::moved_among( array_merge( [], ...array_values( $chains ) ) );
		$out   = [];
		foreach ( $chains as $path => $ids ) {
			if ( isset( $held[ $path ] ) || [] !== array_intersect_key( array_flip( $ids ), $moved ) ) {
				$out[ (string) $path ] = true;
			}
		}
		return $out;
	}

	/**
	 * The most segments an old path of a type may have for the server to
	 * look it up: its cap, less the segments of the type's rewrite prefix.
	 *
	 * @param string $post_type Post type name.
	 * @return int
	 */
	public static function room( string $post_type ): int {
		$prefix = Server::prefix( $post_type );
		return Server::max_segments() - ( '' !== $prefix ? substr_count( $prefix, '/' ) + 1 : 0 );
	}

	/**
	 * Rules::resolve() for every path at once, after Rules::is_404() when
	 * $requests: each run of the rules that asks for a fact not read yet
	 * stops; the facts every run asked for are read in one query, ledger
	 * facts or path lookups, and the runs start again. The paths go round by
	 * round together, so each round costs one ledger query, one
	 * get_page_by_path() lookup for every rewritten path, the request lookups
	 * with the first, and the posts found primed a level at a time. A run
	 * that reaches a rewritten path core could not be asked about any more
	 * ends UNKNOWN, as does one the waves do not finish.
	 *
	 * @param string[]                                $paths     Normalized old paths.
	 * @param string                                  $post_type Post type name.
	 * @param array<string,array{live:int,held:bool,newest:bool,trashed:int[]}> $facts     Ledger facts read so far, by path; more are added.
	 * @param bool                                    $requests  Whether each path is a request that must be a 404 first.
	 * @param array<string,true>|null                 $held      Set to the paths led to a post or URL through a prefix a page in the trash holds.
	 * @param array<string,array{url:string,code:int}>|null $answers Set to each answered path's URL target and chosen code, where it has either.
	 * @param array<string,array{0:string,1:int}>|null $firsts Set to the prefix and holder of the first record the rules used for each path, where they used one.
	 * @return array<string,int> Post ID by path; 0 for none, NOT_404 when something is shown there, URL for a URL target, UNKNOWN when it cannot be told.
	 */
	private static function run( array $paths, string $post_type, array &$facts, bool $requests, ?array &$held = null, ?array &$answers = null, ?array &$firsts = null ): array {
		$held    = [];
		$answers = [];
		$firsts  = [];
		$view    = new Prefetched_View( $post_type, $facts );
		$out     = [];
		$pending = [];
		foreach ( $paths as $path ) {
			$pending[ $path ] = $path;
		}
		$first = array_values( array_filter( self::prefixes( $paths ), static fn( string $path ): bool => ! isset( $facts[ $path ] ) ) );
		if ( [] !== $first ) {
			$facts += self::ledger_facts( $first, $post_type );
		}

		$budget = self::FALLBACKS;
		for ( $wave = 0; [] !== $pending && $wave < 2 * ( Rules::MAX_ROUNDS + 2 ); $wave++ ) {
			$needs = $view->take_needs();
			if ( [] !== $needs['facts'] ) {
				$facts += self::ledger_facts( $needs['facts'], $post_type );
			}
			$found = [];
			if ( [] !== $needs['requests'] || [] !== $needs['candidates'] ) {
				[ $at, $there, $routes, $shown ] = self::lookup( $needs['requests'], $needs['candidates'], $post_type, $budget );
				$view->add_lookups( $needs['requests'], $at, $needs['candidates'], $there, $routes, $shown );
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
				$code      = 0;
				$view->begin();
				try {
					// Only a 404 reaches the resolver.
					$target = ! $requests || $view->is_404( $start, $post_type ) ? Rules::resolve( $view, $post_type, $start, $temporary, $trace, $code ) : null;
					if ( [] !== $trace && ( null === $target || ! $view->guessed() ) ) {
						$firsts[ $start ] = [ (string) $trace[0][0], (int) $trace[0][1] ];
					}
					if ( null !== $target && ! $view->guessed() ) {
						$url           = is_string( $target ) ? $target : '';
						$out[ $start ] = '' !== $url ? self::URL : (int) $target->ID;
						if ( '' !== $url || $code > 0 ) {
							$answers[ $start ] = [
								'url'  => $url,
								'code' => $code,
							];
						}
						if ( $temporary ) {
							$held[ $start ] = true;
						}
						continue;
					}
					if ( null === $target ) {
						$out[ $start ] = $view->knows( $start ) && ! $view->is_404( $start, $post_type ) ? self::NOT_404 : 0;
						continue;
					}
				} catch ( OutOfBoundsException $unread ) {
					// The facts it asked for are read next; it runs again then.
					unset( $unread );
				} catch ( UnexpectedValueException $unknown ) {
					// Which of the posts at a rewritten path core finds is not known: that ends it.
					unset( $unknown );
					$out[ $start ] = self::UNKNOWN;
					continue;
				}
				$view->keep();
				$next[ $start ] = [] !== $trace ? (string) $trace[ count( $trace ) - 1 ][2] : $start;
			}
			$pending = $next;
		}

		foreach ( array_keys( $pending ) as $start ) {
			$out[ (string) $start ] = self::UNKNOWN;
		}
		return $out;
	}

	/**
	 * What the ledger says about each path, the way Ledger::find(),
	 * Ledger::held_in_trash(), Ledger::newest_in_trash() and
	 * Ledger::target() read it: the newest post of the type not in the trash
	 * that holds it, whether a post in the trash holds it, whether the newest
	 * entry is one, which posts in the trash hold it, and the override beside
	 * the live holder's record. One query.
	 *
	 * @param string[] $paths     Normalized paths, at most a few per pair listed.
	 * @param string   $post_type Post type name.
	 * @return array<string,array{live:int,held:bool,newest:bool,trashed:int[],target:array{url:string,code:int}|null}> Facts by path, for every path asked about.
	 */
	public static function ledger_facts( array $paths, string $post_type ): array {
		global $wpdb;

		$out     = [];
		$keys    = [];
		$targets = [];
		foreach ( $paths as $path ) {
			$out[ (string) $path ]                                       = [
				'live'    => 0,
				'held'    => false,
				'newest'  => false,
				'trashed' => [],
				'target'  => null,
			];
			$keys[ Ledger::key( $post_type, (string) $path ) ]           = (string) $path;
			$targets[ Ledger::target_key( $post_type, (string) $path ) ] = (string) $path;
		}
		if ( [] === $keys ) {
			return [];
		}
		$placeholders = implode( ',', array_fill( 0, count( $keys ) + count( $targets ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $placeholders holds only %s, two per path listed or prefix of one (its entry and its override), bounded by the count cap; a range on the meta_key index.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT pm.meta_key, pm.post_id, pm.meta_value, p.post_status FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key IN ( {$placeholders} ) AND p.post_type = %s ORDER BY pm.meta_id DESC", ...array_merge( array_keys( $keys ), array_keys( $targets ), [ $post_type ] ) ), ARRAY_A );

		$seen      = [];
		$overrides = [];
		foreach ( (array) $rows as $row ) {
			if ( isset( $targets[ (string) $row['meta_key'] ] ) ) {
				$overrides[ $targets[ (string) $row['meta_key'] ] ][ (int) $row['post_id'] ] = maybe_unserialize( (string) $row['meta_value'] );
				continue;
			}
			$path = $keys[ (string) $row['meta_key'] ] ?? '';
			if ( '' === $path ) {
				continue;
			}
			if ( ! isset( $seen[ $path ] ) ) {
				$seen[ $path ]          = true;
				$out[ $path ]['newest'] = 'trash' === (string) $row['post_status'];
			}
			if ( 'trash' === (string) $row['post_status'] ) {
				$out[ $path ]['held']      = true;
				$out[ $path ]['trashed'][] = (int) $row['post_id'];
			} elseif ( 0 === $out[ $path ]['live'] ) {
				$out[ $path ]['live'] = (int) $row['post_id'];
			}
		}
		foreach ( $overrides as $path => $by_holder ) {
			$live = $out[ $path ]['live'];
			if ( $live > 0 && isset( $by_holder[ $live ] ) ) {
				$out[ $path ]['target'] = Ledger::clean_target( $by_holder[ $live ] );
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
	 * @param array<string,array{live:int,held:bool,newest:bool,trashed:int[]}> $facts From ledger_facts(), for these paths.
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
		return self::lookup( $paths, [], $post_type, $budget, false )[0];
	}

	/**
	 * get_page_by_path() for many paths in one query, two ways: as the main
	 * query looks a request up, for the type and attachments with the first
	 * post of the type winning; and as the resolver looks a candidate up, for
	 * the type alone, where core keeps the last match. The rows are taken in
	 * the order core's query reads them on the post_name index, by ID within
	 * a slug. Where that order decides, between two posts at one full path,
	 * core's own get_page_by_path() is asked, at most $budget times; past
	 * that the path counts as UNKNOWN, as a request and as a candidate.
	 * Each request path is routed by the rewrite rules (Rules::route()), and
	 * what the rule after the page rule asks for is read too: blog posts and
	 * attachments by slug in the same query, categories and tags in one more.
	 * The posts found are primed.
	 *
	 * @param string[] $paths      Normalized paths, looked up as a request is.
	 * @param string[] $candidates Normalized paths, looked up as the resolver does.
	 * @param string   $post_type  Post type name.
	 * @param int      $budget     How many more times core may be asked; lowered.
	 * @param bool     $route      Whether the request paths are routed, as a 404 check needs.
	 * @return array{0:array<string,int>,1:array<string,int>,2:array<string,array{0:bool,1:string,2:string}>,3:array<string,array<string,bool>>} Post ID by path for each, a path no post uses now absent; each request path's route; and whether what the routes ask for is there, by kind and slug.
	 */
	private static function lookup( array $paths, array $candidates, string $post_type, int &$budget, bool $route = true ): array {
		global $wpdb;

		$routes = [];
		$asked  = [];
		foreach ( $route ? array_unique( array_map( 'strval', $paths ) ) : [] as $path ) {
			$routes[ $path ]   = Rules::route( $post_type, $path );
			[ , $kind, $slug ] = $routes[ $path ];
			if ( '' !== $slug ) {
				$asked[ $kind ][ $slug ] = true;
			}
		}

		$names = [];
		foreach ( array_merge( $paths, $candidates ) as $path ) {
			foreach ( explode( '/', (string) $path ) as $name ) {
				$names[ $name ] = true;
			}
		}
		foreach ( [ 'post', 'attachment' ] as $kind ) {
			$names += $asked[ $kind ] ?? [];
		}
		unset( $names[''] );
		if ( [] === $names ) {
			return [ [], [], $routes, [] ];
		}

		$names        = array_map( 'strval', array_keys( $names ) );
		$placeholders = implode( ',', array_fill( 0, count( $names ), '%s' ) );
		$types        = [] !== $paths ? ( isset( $asked['post'] ) ? "%s, 'attachment', 'post'" : "%s, 'attachment'" ) : '%s';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $placeholders holds only %s, one per segment of the paths and slug a rule asks for; $types is a fixed placeholder list; core's get_page_by_path() query for every path at once, on the post_name index.
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
			if ( $budget-- > 0 ) {
				$post = get_page_by_path( $path, OBJECT, [ $post_type ] );
				unset( $there[ $path ] );
				if ( $post instanceof WP_Post ) {
					$there[ $path ] = (int) $post->ID;
				}
			} else {
				$there[ $path ] = self::UNKNOWN;
			}
		}

		// The blog posts and attachments the rules ask for, by slug.
		$shown = [];
		foreach ( [ 'post', 'attachment' ] as $kind ) {
			foreach ( array_keys( $asked[ $kind ] ?? [] ) as $slug ) {
				$shown[ $kind ][ (string) $slug ] = [];
				foreach ( $named[ (string) $slug ] ?? [] as $id => $row ) {
					if ( $kind === (string) $row->post_type ) {
						$shown[ $kind ][ (string) $slug ][] = (int) $id;
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
		foreach ( $shown as $by_slug ) {
			foreach ( $by_slug as $list ) {
				$others = array_merge( $others, $list );
			}
		}
		self::prime_with_ancestors( array_values( array_unique( $others ) ) );

		$there_now = [];
		foreach ( $shown as $kind => $by_slug ) {
			foreach ( $by_slug as $slug => $list ) {
				$there_now[ $kind ][ $slug ] = [] !== array_filter( $list, 'is_post_publicly_viewable' );
			}
		}
		return [ $at, $there, $routes, $there_now + self::terms( $asked ) ];
	}

	/**
	 * Whether the categories and tags the rules ask for exist, in one query:
	 * an archive is shown for a term with no posts too. When they cannot be
	 * read, each is taken to exist.
	 *
	 * @param array<string,array<string,true>> $asked Slugs by kind.
	 * @return array<string,array<string,bool>> By kind and slug.
	 */
	private static function terms( array $asked ): array {
		$taxonomies = [
			'category' => 'category',
			'tag'      => 'post_tag',
		];
		$out        = [];
		$slugs      = [];
		foreach ( $taxonomies as $kind => $taxonomy ) {
			foreach ( array_keys( $asked[ $kind ] ?? [] ) as $slug ) {
				$out[ $kind ][ (string) $slug ] = false;
				$slugs[ (string) $slug ]        = true;
			}
		}
		if ( [] === $slugs ) {
			return [];
		}
		$terms = get_terms(
			[
				'taxonomy'               => array_values( $taxonomies ),
				'slug'                   => array_map( 'strval', array_keys( $slugs ) ),
				'hide_empty'             => false,
				'update_term_meta_cache' => false,
			]
		);
		if ( ! is_array( $terms ) ) {
			return array_map( static fn( array $by_slug ): array => array_fill_keys( array_keys( $by_slug ), true ), $out );
		}
		foreach ( $terms as $term ) {
			$kind = array_search( $term->taxonomy, $taxonomies, true );
			if ( false !== $kind && isset( $out[ $kind ][ $term->slug ] ) ) {
				$out[ $kind ][ $term->slug ] = true;
			}
		}
		return $out;
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
