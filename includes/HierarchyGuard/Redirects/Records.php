<?php
/**
 * The recorded redirects, as the Redirects tab lists and edits them.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Redirects;

use HierarchyGuard\Ledger;
use HierarchyGuard\Paths;
use HierarchyGuard\Post_Types;
use HierarchyGuard\Settings;
use HierarchyGuard\Subtree;
use WP_Error;
use WP_Post;

/**
 * One record per ledger entry: a post's _hierarchy_guard_path_{md5} row, or
 * one root-list entry. A listing reads the newest Subtree::count_cap() post
 * entries, which the ledger keeps a list of per type, and the root list,
 * which holds at most Ledger::ROOT_CAP paths per type; it sorts and searches
 * those, and reads targets and statuses for one page of rows only. A
 * record's ID is its holder's post ID, 0 for the root list, then a dot and
 * its hash.
 */
final class Records {

	public const PER_PAGE = 20;

	public const STATUS_ACTIVE      = 'active';
	public const STATUS_IN_USE      = 'in_use';
	public const STATUS_UNPUBLISHED = 'unpublished';
	public const STATUS_OFF         = 'off';

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
	 * A record's ID: its holder, 0 for the root list, a dot, and the hash its key carries.
	 *
	 * @param int    $holder    Post that holds the record, 0 for the root list.
	 * @param string $post_type Post type name.
	 * @param string $path      Normalized path.
	 * @return string
	 */
	public static function id( int $holder, string $post_type, string $path ): string {
		return $holder . '.' . md5( $post_type . ':' . $path );
	}

	/**
	 * A record's old URL relative to the home URL, as people read it: such
	 * as `/about/team/`, or `/café/` for a path stored percent-encoded.
	 *
	 * @param string $post_type Post type name.
	 * @param string $path      Normalized path.
	 * @return string
	 */
	public static function old_url( string $post_type, string $path ): string {
		$url = Paths::url( $post_type, $path );
		return rawurldecode( '' !== $url ? Paths::relative( $url ) : '/' . $path . '/' );
	}

	/**
	 * One page of records sorted by old URL, how many match, the page shown,
	 * which is the last one when the page asked for is past it, and whether
	 * older post entries exist than the newest ones listed. A search also
	 * finds, by its hashed key, any entry at the exact old URL it names.
	 *
	 * @param string $post_type A supported type to list alone, or '' for every supported type.
	 * @param string $search    Text the old URL contains, or ''.
	 * @param int    $page      Page number, from 1.
	 * @return array{rows:array<int,array{id:string,holder:int,post_type:string,path:string,old:string,target:array{url:string,code:int}|null}>,total:int,page:int,capped:bool}
	 */
	public function page( string $post_type, string $search, int $page ): array {
		$types = Post_Types::supported();
		if ( '' !== $post_type ) {
			$types = in_array( $post_type, $types, true ) ? [ $post_type ] : [];
		}
		if ( [] === $types ) {
			return [
				'rows'   => [],
				'total'  => 0,
				'page'   => 1,
				'capped' => false,
			];
		}

		[ $rows, $capped ] = $this->window( $types );
		$rows             += $this->roots( $types );
		$search            = trim( $search );
		if ( '' !== $search ) {
			// A full URL is matched as the path the Old URL column shows.
			$needle = '' !== (string) wp_parse_url( $search, PHP_URL_HOST ) ? rawurldecode( Paths::relative( $search ) ) : $search;
			$rows   = array_filter( $rows, static fn( array $row ): bool => false !== stripos( $row['old'], $needle ) );
			// Any entry is found by its exact old URL, whether the newest list holds it or not.
			$rows += $this->exact( $types, $search );
		}

		uasort( $rows, [ self::class, 'compare' ] );
		$page  = min( max( 1, $page ), max( 1, (int) ceil( count( $rows ) / self::PER_PAGE ) ) );
		$slice = array_slice( array_values( $rows ), ( $page - 1 ) * self::PER_PAGE, self::PER_PAGE );

		return [
			'rows'   => $this->with_targets( $slice ),
			'total'  => count( $rows ),
			'page'   => $page,
			'capped' => $capped,
		];
	}

	/**
	 * The record an ID names, with its override, while it exists and its type is supported.
	 *
	 * @param string $id Record ID.
	 * @return array{id:string,holder:int,post_type:string,path:string,old:string,target:array{url:string,code:int}|null}|null
	 */
	public function find( string $id ): ?array {
		global $wpdb;

		if ( 1 !== preg_match( '/^(\d{1,20})\.([0-9a-f]{32})$/', $id, $parts ) ) {
			return null;
		}
		$holder = (int) $parts[1];
		$hash   = $parts[2];

		if ( $holder > 0 ) {
			$post = get_post( $holder );
			if ( ! $post instanceof WP_Post || ! Post_Types::is_supported( (string) $post->post_type ) ) {
				return null;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Point lookup on the post_id index, one row, on an admin action.
			$path = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1", $holder, Ledger::KEY_PREFIX . $hash ) );
			$path = Paths::normalize( (string) $path );
			if ( '' === $path || md5( $post->post_type . ':' . $path ) !== $hash ) {
				return null;
			}
			return $this->with_targets( [ self::row( $holder, (string) $post->post_type, $path ) ] )[0];
		}

		foreach ( $this->roots( Post_Types::supported() ) as $row ) {
			if ( $row['id'] === $id ) {
				return $this->with_targets( [ $row ] )[0];
			}
		}
		return null;
	}

	/**
	 * Each row's status, from what the server's own model answers for the
	 * row's exact old path (Batch_Resolver::served(), the rules the server
	 * runs), the first that applies:
	 *
	 * - in use: something is shown at the old URL, a blog post or an archive
	 *   included, so the server never runs; or another record answers the
	 *   path instead: a newer holder, a page in the Trash that holds the
	 *   newest entry, a post entry over a root-list entry, or another type's
	 *   record the server tries first;
	 * - unpublished: the page the record points to is not published, or its
	 *   holder is in the Trash, which the resolver passes over;
	 * - off: built-in redirects are off, the site has plain permalinks, or
	 *   the server refuses the request before it looks: a path deeper than
	 *   its segment cap, or a URL target at the old URL itself, a loop;
	 * - active: the server serves the row's own record at its old URL. A
	 *   top-level root-list entry leads nowhere at its own old URL, only for
	 *   the URLs under it, so it is active when its record is the one the
	 *   rules use there.
	 *
	 * A row the model cannot vouch for is in use: the model leaves a path
	 * several posts share unanswered. Under plain permalinks the model does
	 * not run. One served() call per post type, for these rows only.
	 *
	 * @param array<int,array{id:string,holder:int,post_type:string,path:string,old:string,target:array{url:string,code:int}|null}> $rows Rows, one page at most.
	 * @return array<string,string> One of the STATUS_ constants by row ID.
	 */
	public function statuses( array $rows ): array {
		$pretty  = '' !== (string) get_option( 'permalink_structure' );
		$serving = Settings::serve_redirects() && $pretty;
		$paths   = [];
		foreach ( $rows as $row ) {
			$paths[ $row['post_type'] ][ $row['path'] ] = true;
		}
		$served = [];
		$used   = [];
		$chosen = [];
		foreach ( $pretty ? $paths : [] as $type => $list ) {
			$facts           = [];
			$temporary       = [];
			$served[ $type ] = Batch_Resolver::served( array_map( 'strval', array_keys( $list ) ), (string) $type, $facts, $temporary, $chosen[ $type ], $used[ $type ] );
		}
		Batch_Resolver::prime_with_ancestors( array_values( array_filter( array_column( $rows, 'holder' ) ) ) );

		$out = [];
		foreach ( $rows as $row ) {
			$type   = $row['post_type'];
			$path   = $row['path'];
			$holder = $row['holder'] > 0 ? get_post( $row['holder'] ) : null;
			$url    = null !== $row['target'] ? $row['target']['url'] : '';
			$answer = $served[ $type ][ $path ] ?? 0;
			$first  = $used[ $type ][ $path ] ?? null;
			$own    = null !== $first && $first[0] === $path && $first[1] === $row['holder'];
			if ( ! $pretty ) {
				$status = $holder instanceof WP_Post && ! self::reachable( $holder, $url ) ? self::STATUS_UNPUBLISHED : self::STATUS_OFF;
			} elseif ( Batch_Resolver::NOT_404 === $answer || ( 0 !== $answer && ! $own ) ) {
				$status = self::STATUS_IN_USE;
			} elseif ( $holder instanceof WP_Post && ! self::reachable( $holder, $url ) ) {
				$status = self::STATUS_UNPUBLISHED;
			} elseif ( ! $serving || substr_count( $path, '/' ) + 1 > Batch_Resolver::room( $type ) || ( Batch_Resolver::URL === $answer && self::loops( $type, $path, (string) ( $chosen[ $type ][ $path ]['url'] ?? '' ) ) ) ) {
				$status = self::STATUS_OFF;
			} elseif ( ! $own || ( 0 === $answer && ! self::is_top_level( $row ) ) ) {
				$status = self::STATUS_IN_USE;
			} else {
				$status = self::STATUS_ACTIVE;
			}
			$out[ $row['id'] ] = $status;
		}
		return $out;
	}

	/**
	 * Whether the server refuses a URL target as a loop: on this host, at
	 * the old URL's own path, as Server::target_for_current_request() compares them.
	 *
	 * @param string $post_type Post type name.
	 * @param string $path      Normalized old path.
	 * @param string $url       The URL target.
	 * @return bool
	 */
	private static function loops( string $post_type, string $path, string $url ): bool {
		$old = Paths::url( $post_type, $path );
		if ( '' === $url || '' === $old || strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) !== strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ) {
			return false;
		}
		return untrailingslashit( (string) wp_parse_url( $url, PHP_URL_PATH ) ) === untrailingslashit( (string) wp_parse_url( $old, PHP_URL_PATH ) );
	}

	/**
	 * The URL the Edit view offers for a record: its URL target, or its
	 * page's URL. A root-list entry without a URL target, whose subpages are
	 * top level, offers nothing: the field starts empty.
	 *
	 * @param array{holder:int,target:array{url:string,code:int}|null} $record Record.
	 * @return string
	 */
	public static function target_url( array $record ): string {
		$url = null !== $record['target'] ? $record['target']['url'] : '';
		if ( '' !== $url ) {
			return $url;
		}
		if ( $record['holder'] > 0 ) {
			$link = get_permalink( $record['holder'] );
			return is_string( $link ) ? $link : '';
		}
		return '';
	}

	/**
	 * Whether a record is a root-list entry whose subpages are top level: no
	 * holder, and no URL target.
	 *
	 * @param array{holder:int,target:array{url:string,code:int}|null} $record Record.
	 * @return bool
	 */
	public static function is_top_level( array $record ): bool {
		return 0 === $record['holder'] && ( null === $record['target'] || '' === $record['target']['url'] );
	}

	/**
	 * Saves an edit. A path starting with one slash is a URL on this site,
	 * resolved against the home URL. The URL the Edit view offered, unchanged,
	 * keeps the target and saves the code; so does an empty field for a
	 * top-level root-list entry, whose field starts empty. A URL that
	 * url_to_postid() resolves to a page of the record's own type makes a page
	 * target: the record moves onto that page, out of the root list or off
	 * its old holder, and its code goes with it. The holder's own URL makes it
	 * a page target again. Any other http(s) URL with a host makes a URL
	 * target, stored beside the record, unless it is the old URL itself,
	 * which the server would refuse as a loop. Anything else is refused and
	 * nothing is written.
	 *
	 * @param array{id:string,holder:int,post_type:string,path:string,old:string,target:array{url:string,code:int}|null} $record Record from find().
	 * @param string $url  The URL or path entered.
	 * @param int    $code 301, 302, or 0 for the automatic code.
	 * @return true|WP_Error
	 */
	public function save( array $record, string $url, int $code ) {
		$url  = trim( $url );
		$code = in_array( $code, [ 301, 302 ], true ) ? $code : 0;
		$type = $record['post_type'];
		$path = $record['path'];
		if ( '' === $url && self::is_top_level( $record ) ) {
			$this->ledger->set_target( $type, $path, 0, '', $code );
			return true;
		}
		if ( '' === $url ) {
			return new WP_Error( 'hierarchy_guard_no_url', __( 'Enter the URL or path to redirect to.', 'hierarchy-guard' ) );
		}
		if ( str_starts_with( $url, '/' ) && ! str_starts_with( $url, '//' ) ) {
			$url = home_url( $url );
		}

		$kept    = null !== $record['target'] ? $record['target']['url'] : '';
		$offered = self::target_url( $record );
		// An empty offer never matches: esc_url_raw() also returns '' for a refused protocol.
		if ( '' !== $offered && untrailingslashit( esc_url_raw( $url ) ) === untrailingslashit( esc_url_raw( $offered ) ) ) {
			$this->ledger->set_target( $type, $path, $record['holder'], $kept, $code );
			return true;
		}

		// esc_url_raw() would read `company/about` as http://company/about: the scheme must be typed.
		$typed = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		$clean = esc_url_raw( $url, [ 'http', 'https' ] );
		// A URL with no host, such as http:/path, would be sent as it is.
		if ( '' === $clean || ! in_array( $typed, [ 'http', 'https' ], true ) || ! in_array( strtolower( (string) wp_parse_url( $clean, PHP_URL_SCHEME ) ), [ 'http', 'https' ], true ) || '' === (string) wp_parse_url( $clean, PHP_URL_HOST ) ) {
			return new WP_Error( 'hierarchy_guard_not_http', __( 'Enter a URL that starts with http:// or https://, or a path on this site that starts with /.', 'hierarchy-guard' ) );
		}

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.url_to_postid_url_to_postid -- Once per save on an admin screen, never on the front end.
		$id   = url_to_postid( $clean );
		$page = $id > 0 ? get_post( $id ) : null;
		if ( ! $page instanceof WP_Post || $page->post_type !== $type ) {
			// The server refuses a redirect to the URL being requested, as a loop.
			if ( self::loops( $type, $path, $clean ) ) {
				return new WP_Error( 'hierarchy_guard_own_url', __( 'That URL is the old URL itself.', 'hierarchy-guard' ) );
			}
			// Another type's page, or any other URL: the record stays where it is.
			$this->ledger->set_target( $type, $path, $record['holder'], $clean, $code );
			return true;
		}
		if ( (int) $page->ID === $record['holder'] ) {
			$this->ledger->set_target( $type, $path, $record['holder'], '', $code );
			return true;
		}
		if ( Paths::uri( $page ) === $path ) {
			/* translators: %s: the old URL, such as /about/team/. */
			return new WP_Error( 'hierarchy_guard_same_path', sprintf( __( 'That page is at %s, so it needs no redirect.', 'hierarchy-guard' ), $record['old'] ) );
		}

		$this->ledger->record( (int) $page->ID, $path );
		if ( ! metadata_exists( 'post', (int) $page->ID, Ledger::key( $type, $path ) ) ) {
			return new WP_Error( 'hierarchy_guard_not_saved', __( 'The redirect could not be saved.', 'hierarchy-guard' ) );
		}
		$this->delete( $record );
		$this->ledger->set_target( $type, $path, (int) $page->ID, '', $code );
		return true;
	}

	/**
	 * Deletes a record and its override.
	 *
	 * @param array{holder:int,post_type:string,path:string} $record Record from find().
	 * @return bool Whether it was there to delete.
	 */
	public function delete( array $record ): bool {
		if ( $record['holder'] > 0 ) {
			return $this->ledger->forget( $record['holder'], $record['path'] );
		}
		return $this->ledger->forget_root( $record['post_type'], $record['path'] );
	}

	/**
	 * The listing's order: old URL, then post type, then holder.
	 *
	 * @param array{old:string,post_type:string,holder:int} $a A row.
	 * @param array{old:string,post_type:string,holder:int} $b Another row.
	 * @return int
	 */
	private static function compare( array $a, array $b ): int {
		$order = strcmp( $a['old'], $b['old'] );
		if ( 0 === $order ) {
			$order = strcmp( $a['post_type'], $b['post_type'] );
		}
		return 0 !== $order ? $order : $a['holder'] <=> $b['holder'];
	}

	/**
	 * The post entries a listing reads: the newest of the listed types, at
	 * most the count cap, from the ledger's list, in one primary-key lookup
	 * joined to their posts. An entry deleted since the list named it is
	 * simply not found. Rows whose type is not listed, or whose key does not
	 * carry their own type's hash, are left out.
	 *
	 * @param string[] $types Post types to list.
	 * @return array{0:array<string,array<string,mixed>>,1:bool} Rows by ID, and whether older entries exist than were read.
	 */
	private function window( array $types ): array {
		global $wpdb;

		$recent = $this->ledger->recent( $types );
		if ( [] === $recent['ids'] ) {
			return [ [], $recent['more'] ];
		}
		$in = implode( ',', array_fill( 0, count( $recent['ids'] ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $in holds only %d, one per meta ID the list names, at most the count cap; primary-key lookups, then each row's post by its primary key.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT pm.post_id, pm.meta_key, pm.meta_value, p.post_type FROM {$wpdb->postmeta} pm STRAIGHT_JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_id IN ( {$in} )", $recent['ids'] ), ARRAY_A );

		return [ self::rows( $rows, $types ), $recent['more'] ];
	}

	/**
	 * The entries at the exact old URL a search names, for every listed type,
	 * a page of them at most: one query.
	 *
	 * @param string[] $types  Post types to list.
	 * @param string   $search An old URL, absolute or relative to the home URL.
	 * @return array<string,array<string,mixed>> Rows by ID.
	 */
	private function exact( array $types, string $search ): array {
		global $wpdb;

		$path = Paths::normalize( (string) wp_parse_url( Paths::relative( $search ), PHP_URL_PATH ) );
		$keys = [];
		foreach ( $types as $type ) {
			$prefix = Server::prefix( $type );
			if ( '' === $prefix ) {
				$keys[] = Ledger::key( $type, $path );
			} elseif ( str_starts_with( $path, $prefix . '/' ) ) {
				$keys[] = Ledger::key( $type, substr( $path, strlen( $prefix ) + 1 ) );
			}
		}
		if ( '' === $path || [] === $keys ) {
			return [];
		}
		$placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $placeholders holds only %s, one per supported type; seeks on the meta_key index, bounded by LIMIT.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT pm.post_id, pm.meta_key, pm.meta_value, p.post_type FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key IN ( {$placeholders} ) LIMIT %d", ...array_merge( $keys, [ self::PER_PAGE ] ) ), ARRAY_A );

		return self::rows( $rows, $types );
	}

	/**
	 * Rows from post entries read: a listed type, and a key with its own type's hash.
	 *
	 * @param array<int,array<string,string>> $entries Entries with post_id, meta_key, meta_value and post_type.
	 * @param string[]                        $types   Post types to list.
	 * @return array<string,array<string,mixed>> Rows by ID.
	 */
	private static function rows( array $entries, array $types ): array {
		$out = [];
		foreach ( $entries as $entry ) {
			$type = (string) $entry['post_type'];
			$path = Paths::normalize( (string) $entry['meta_value'] );
			if ( '' === $path || ! in_array( $type, $types, true ) || Ledger::key( $type, $path ) !== (string) $entry['meta_key'] ) {
				continue;
			}
			$row               = self::row( (int) $entry['post_id'], $type, $path );
			$out[ $row['id'] ] = $row;
		}
		return $out;
	}

	/**
	 * The root list's entries for the listed types.
	 *
	 * @param string[] $types Post types to list.
	 * @return array<string,array<string,mixed>> Rows by ID.
	 */
	private function roots( array $types ): array {
		$out   = [];
		$roots = $this->ledger->root_paths();
		foreach ( $types as $type ) {
			foreach ( (array) ( $roots[ $type ] ?? [] ) as $path ) {
				$row               = self::row( 0, $type, (string) $path );
				$out[ $row['id'] ] = $row;
			}
		}
		return $out;
	}

	/**
	 * A row without its override.
	 *
	 * @param int    $holder    Post that holds the record, 0 for the root list.
	 * @param string $post_type Post type name.
	 * @param string $path      Normalized path.
	 * @return array{id:string,holder:int,post_type:string,path:string,old:string,target:null}
	 */
	private static function row( int $holder, string $post_type, string $path ): array {
		return [
			'id'        => self::id( $holder, $post_type, $path ),
			'holder'    => $holder,
			'post_type' => $post_type,
			'path'      => $path,
			'old'       => self::old_url( $post_type, $path ),
			'target'    => null,
		];
	}

	/**
	 * The rows with their overrides: one query for the post entries' sibling
	 * rows, the root list's from its option, and the holders primed.
	 *
	 * @param array<int,array<string,mixed>> $rows Rows, one page at most.
	 * @return array<int,array{id:string,holder:int,post_type:string,path:string,old:string,target:array{url:string,code:int}|null}>
	 */
	private function with_targets( array $rows ): array {
		global $wpdb;

		$holders = array_values( array_unique( array_filter( array_map( static fn( array $row ): int => (int) $row['holder'], $rows ) ) ) );
		$found   = [];
		if ( [] !== $holders ) {
			_prime_post_caches( $holders, false, false );
			$keys = array_values( array_unique( array_map( static fn( array $row ): string => Ledger::target_key( (string) $row['post_type'], (string) $row['path'] ), $rows ) ) );
			$ids  = implode( ',', array_map( 'intval', $holders ) );
			$in   = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $ids holds integers only and $in only %s, one per row of the page.
			$overrides = (array) $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ( {$ids} ) AND meta_key IN ( {$in} )", $keys ), ARRAY_A );
			foreach ( $overrides as $override ) {
				$found[ (int) $override['post_id'] ][ (string) $override['meta_key'] ] = maybe_unserialize( (string) $override['meta_value'] );
			}
		}

		$out = [];
		foreach ( $rows as $row ) {
			$row['target'] = $row['holder'] > 0
				? Ledger::clean_target( $found[ $row['holder'] ][ Ledger::target_key( $row['post_type'], $row['path'] ) ] ?? null )
				: $this->ledger->target( $row['post_type'], $row['path'], 0 );
			$out[]         = $row;
		}
		return $out;
	}

	/**
	 * Whether the resolver can serve a record through its holder: never from
	 * the trash; for a page target, only to a page anyone can view that is
	 * not under the trash.
	 *
	 * @param WP_Post $holder The record's holder.
	 * @param string  $url    The record's URL target, or ''.
	 * @return bool
	 */
	private static function reachable( WP_Post $holder, string $url ): bool {
		if ( 'trash' === $holder->post_status ) {
			return false;
		}
		if ( '' !== $url ) {
			return true;
		}
		return is_post_publicly_viewable( $holder ) && ! Paths::has_trashed_segment( Paths::uri( $holder ) );
	}
}
