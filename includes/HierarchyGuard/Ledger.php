<?php
/**
 * The old-path ledger.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard;

use WP_Post;

/**
 * Stores the paths a post (or its moved children) used to live at.
 *
 * One repeatable-by-key post meta row per old path, with the post type and
 * path hashed into the key so a lookup is a single index seek. Paths whose
 * children became top level are kept in a small option instead.
 */
final class Ledger {

	public const KEY_PREFIX    = '_hierarchy_guard_path_';
	public const TARGET_PREFIX = '_hierarchy_guard_target_';
	public const ROOT_OPTION   = 'hierarchy_guard_root_paths';
	public const ROOT_TARGETS  = 'hierarchy_guard_root_targets';
	public const RECENT        = 'hierarchy_guard_recent_records';
	public const RECENT_STATE  = 'hierarchy_guard_recent_state';
	public const REBUILD_HOOK  = 'hierarchy_guard_rebuild_recent';
	public const CACHE_GROUP   = 'hierarchy_guard';
	public const ROOT_CAP      = 200;

	private const CACHE_MISS = -1;
	private const CACHE_TTL  = 12 * HOUR_IN_SECONDS;

	/**
	 * Nesting depth of deferred() calls.
	 *
	 * @var int
	 */
	private static int $defer_depth = 0;

	/**
	 * Whether a write happened inside deferred().
	 *
	 * @var bool
	 */
	private static bool $bump_pending = false;

	/**
	 * Entries recorded inside deferred(), meta IDs by post type, oldest first.
	 *
	 * @var array<string,int[]>
	 */
	private static array $recent_pending = [];

	/**
	 * Posts being deleted for good, from before_delete_post to deleted_post:
	 * their entries left the newest-records list at once.
	 *
	 * @var array<int,true>
	 */
	private static array $deleting = [];

	/**
	 * Meta key for a (post type, path) pair.
	 *
	 * @param string $post_type Post type name.
	 * @param string $path      Normalized path.
	 * @return string
	 */
	public static function key( string $post_type, string $path ): string {
		return self::KEY_PREFIX . md5( $post_type . ':' . $path );
	}

	/**
	 * Meta key of the override beside a record: the same hash as its entry.
	 *
	 * @param string $post_type Post type name.
	 * @param string $path      Normalized path.
	 * @return string
	 */
	public static function target_key( string $post_type, string $path ): string {
		return self::TARGET_PREFIX . md5( $post_type . ':' . $path );
	}

	/**
	 * Records that $old_path used to lead to $post_id (or to children now under it).
	 *
	 * @param int    $post_id  Post that the path now maps to.
	 * @param string $old_path Old path, any form; normalized here.
	 * @return bool True when a new entry was written.
	 */
	public function record( int $post_id, string $old_path ): bool {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return false;
		}

		$old_path = Paths::normalize( $old_path );
		if ( '' === $old_path || Paths::uri( $post ) === $old_path ) {
			return false;
		}

		/**
		 * Filters whether an old path is recorded for a post.
		 *
		 * @param bool    $record   Whether to record.
		 * @param WP_Post $post     The post the path maps to.
		 * @param string  $old_path The normalized old path.
		 */
		if ( ! apply_filters( 'hierarchy_guard_should_record', true, $post, $old_path ) ) {
			return false;
		}

		$key = self::key( (string) $post->post_type, $old_path );
		if ( metadata_exists( 'post', $post_id, $key ) ) {
			return false;
		}

		$meta_id = add_post_meta( $post_id, $key, $old_path, true );
		if ( is_int( $meta_id ) ) {
			self::remember( (string) $post->post_type, [ $meta_id ] );
		}
		$this->bump();

		return true;
	}

	/**
	 * Records an old parent path whose children became top level.
	 *
	 * @param string $post_type Post type name.
	 * @param string $old_path  Old path, any form; normalized here.
	 * @return bool True when a new entry was written.
	 */
	public function record_root( string $post_type, string $old_path ): bool {
		$old_path = Paths::normalize( $old_path );
		if ( '' === $old_path ) {
			return false;
		}

		$roots = $this->roots();
		$list  = $roots[ $post_type ] ?? [];
		if ( in_array( $old_path, $list, true ) ) {
			return false;
		}

		$list[] = $old_path;
		if ( count( $list ) > self::ROOT_CAP ) {
			$this->drop_root_targets( $post_type, array_slice( $list, 0, count( $list ) - self::ROOT_CAP ) );
			$list = array_slice( $list, -self::ROOT_CAP );
		}
		$roots[ $post_type ] = array_values( $list );

		update_option( self::ROOT_OPTION, $roots, false );
		$this->bump();

		return true;
	}

	/**
	 * Records that children which lived under $old_path now live under a new
	 * parent, or became top level when it is 0.
	 *
	 * @param string $post_type     Post type name.
	 * @param int    $new_parent_id New parent ID, 0 for top level.
	 * @param string $old_path      The old parent path, any form.
	 * @return bool True when a new entry was written.
	 */
	public function record_alias( string $post_type, int $new_parent_id, string $old_path ): bool {
		if ( $new_parent_id > 0 ) {
			return $this->record( $new_parent_id, $old_path );
		}
		return $this->record_root( $post_type, $old_path );
	}

	/**
	 * A published page took its path: the URL is its now. Other live posts'
	 * entries for that exact path, and the root list's, are dropped; if the
	 * page moves on later, it records the path itself. A post in the trash
	 * keeps its entry, so a redirect stays temporary while a restore could
	 * take the path back. One seek on the meta_key index, plus the root list's
	 * option read: a query the first time a request reads it, as it is not autoloaded.
	 *
	 * @param WP_Post $post The page at its new path.
	 * @return bool True when an entry was dropped.
	 */
	public function claim( WP_Post $post ): bool {
		global $wpdb;

		if ( ! Paths::is_recordable( $post ) ) {
			return false;
		}
		$path = Paths::uri( $post );
		if ( Paths::has_trashed_segment( $path ) ) {
			return false;
		}

		$type = (string) $post->post_type;
		$key  = self::key( $type, $path );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Point lookup on the meta_key index; once per page published, moved or renamed.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND pm.post_id <> %d AND p.post_status <> 'trash'", $key, (int) $post->ID ) );

		$dropped = false;
		foreach ( array_unique( array_map( 'intval', $ids ) ) as $id ) {
			$this->drop_target( $id, $type, $path );
			$dropped = delete_post_meta( $id, $key ) || $dropped;
		}
		if ( $dropped ) {
			$this->bump();
		}

		$roots = $this->roots();
		if ( in_array( $path, $roots[ $type ] ?? [], true ) ) {
			$dropped = $this->forget_root( $type, $path ) || $dropped;
		}

		return $dropped;
	}

	/**
	 * Removes an entry from a post.
	 *
	 * @param int    $post_id  Post the entry is on.
	 * @param string $old_path Old path, any form.
	 * @return bool True when an entry was removed.
	 */
	public function forget( int $post_id, string $old_path ): bool {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return false;
		}

		$old_path = Paths::normalize( $old_path );
		$key      = self::key( (string) $post->post_type, $old_path );
		if ( ! metadata_exists( 'post', $post_id, $key ) ) {
			return false;
		}

		$this->drop_target( $post_id, (string) $post->post_type, $old_path );
		delete_post_meta( $post_id, $key );
		$this->bump();

		return true;
	}

	/**
	 * Removes a path from the root list.
	 *
	 * @param string $post_type Post type name.
	 * @param string $old_path  Old path, any form.
	 * @return bool True when an entry was removed.
	 */
	public function forget_root( string $post_type, string $old_path ): bool {
		$old_path = Paths::normalize( $old_path );
		$roots    = $this->roots();
		$list     = $roots[ $post_type ] ?? [];
		if ( ! in_array( $old_path, $list, true ) ) {
			return false;
		}

		$roots[ $post_type ] = array_values( array_diff( $list, [ $old_path ] ) );
		update_option( self::ROOT_OPTION, $roots, false );
		$this->drop_root_targets( $post_type, [ $old_path ] );
		$this->bump();

		return true;
	}

	/**
	 * Removes what record_alias() wrote.
	 *
	 * @param string $post_type     Post type name.
	 * @param int    $new_parent_id The parent the alias was recorded on, 0 for the root list.
	 * @param string $old_path      The old parent path.
	 * @return bool True when an entry was removed.
	 */
	public function forget_alias( string $post_type, int $new_parent_id, string $old_path ): bool {
		if ( $new_parent_id > 0 ) {
			return $this->forget( $new_parent_id, $old_path );
		}
		return $this->forget_root( $post_type, $old_path );
	}

	/**
	 * Runs a callback with cache invalidation held back, then invalidates once.
	 * Batches write many entries; each would otherwise drop every cached lookup.
	 *
	 * @param callable $callback Work that writes to the ledger.
	 * @return mixed The callback's return value.
	 */
	public static function deferred( callable $callback ) {
		++self::$defer_depth;
		try {
			return $callback();
		} finally {
			--self::$defer_depth;
			if ( 0 === self::$defer_depth && [] !== self::$recent_pending ) {
				$pending              = self::$recent_pending;
				self::$recent_pending = [];
				foreach ( $pending as $type => $ids ) {
					self::remember( (string) $type, $ids );
				}
			}
			if ( 0 === self::$defer_depth && self::$bump_pending ) {
				self::$bump_pending = false;
				wp_cache_set_last_changed( self::CACHE_GROUP );
			}
		}
	}

	/**
	 * Drops every cached lookup, as a ledger write does: for a change to a
	 * post that holds entries, which find() would otherwise keep answering for.
	 */
	public function invalidate(): void {
		$this->bump();
	}

	/**
	 * Whether a post holds any ledger entry.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function has_entries( int $post_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Range on the post_id index that stops at the first entry; once per post trashed, restored or deleted.
		return null !== $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key LIKE %s LIMIT 1", $post_id, $wpdb->esc_like( self::KEY_PREFIX ) . '%' ) );
	}

	/**
	 * Old paths recorded on a post, for display.
	 *
	 * @param int $post_id Post ID.
	 * @return string[]
	 */
	public function paths_for( int $post_id ): array {
		$paths = [];
		foreach ( (array) get_post_meta( $post_id ) as $key => $values ) {
			if ( str_starts_with( (string) $key, self::KEY_PREFIX ) && isset( $values[0] ) ) {
				$paths[] = (string) $values[0];
			}
		}
		return $paths;
	}

	/**
	 * Removes the entry on a post that equals its current path.
	 *
	 * @param int $post_id Post ID.
	 * @return int Number of entries removed (0 or 1).
	 */
	public function prune( int $post_id ): int {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return 0;
		}

		$path = Paths::uri( $post );
		$key  = self::key( (string) $post->post_type, $path );
		if ( ! metadata_exists( 'post', $post_id, $key ) ) {
			return 0;
		}

		$this->drop_target( $post_id, (string) $post->post_type, $path );
		delete_post_meta( $post_id, $key );
		$this->bump();

		return 1;
	}

	/**
	 * Finds what a path used to lead to.
	 *
	 * @param string $post_type Post type name.
	 * @param string $path      Path, any form; normalized here.
	 * @return int|null Post ID, 0 when the path is in the root list, null when unknown.
	 */
	public function find( string $post_type, string $path ): ?int {
		$path = Paths::normalize( $path );
		if ( '' === $path ) {
			return null;
		}

		$cache_key = 'find:' . md5( $post_type . ':' . $path ) . ':' . wp_cache_get_last_changed( self::CACHE_GROUP );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			$found = self::CACHE_MISS === (int) $cached ? null : (int) $cached;
		} else {
			$found = $this->seek( $post_type, $path );
			if ( null === $found ) {
				$roots = $this->roots();
				if ( in_array( $path, $roots[ $post_type ] ?? [], true ) ) {
					$found = 0;
				}
			}

			// phpcs:ignore WordPressVIPMinimum.Performance.LowExpiryCacheTime.CacheTimeUndetermined -- CACHE_TTL is 12 hours, well above 300 seconds.
			wp_cache_set( $cache_key, null === $found ? self::CACHE_MISS : $found, self::CACHE_GROUP, self::CACHE_TTL );
		}

		// The cache keeps the holder; its status is read on every call, because
		// trashing, restoring or deleting the holder does not touch the ledger.
		if ( null !== $found && $found > 0 && in_array( get_post_status( $found ), [ false, 'trash' ], true ) ) {
			$roots = $this->roots();
			return in_array( $path, $roots[ $post_type ] ?? [], true ) ? 0 : null;
		}

		return $found;
	}

	/**
	 * Whether a post in the trash holds this path. The trash flow records a
	 * trashed page's own paths on it, so a hit here means the page at an old
	 * parent path is only in the trash.
	 *
	 * @param string $post_type Post type name.
	 * @param string $path      Path, any form; normalized here.
	 * @return bool
	 */
	public static function held_in_trash( string $post_type, string $path ): bool {
		global $wpdb;

		$path = Paths::normalize( $path );
		if ( '' === $path ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Seek on the meta_key index that stops at the first page in the trash; only for a 404.
		return null !== $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND p.post_type = %s AND p.post_status = 'trash' LIMIT 1",
				self::key( $post_type, $path ),
				$post_type
			)
		);
	}

	/**
	 * The posts in the trash that hold a path: one seek on the meta_key
	 * index, as many rows as posts hold that one path.
	 *
	 * @param string $post_type Post type name.
	 * @param string $path      Path, any form; normalized here.
	 * @return int[]
	 */
	public static function trashed_holders( string $post_type, string $path ): array {
		global $wpdb;

		$path = Paths::normalize( $path );
		if ( '' === $path ) {
			return [];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Seek on the meta_key index, bounded by the holders of one path; only for a 404.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND p.post_type = %s AND p.post_status = 'trash'",
				self::key( $post_type, $path ),
				$post_type
			)
		);

		return array_values( array_unique( array_map( 'intval', $ids ) ) );
	}

	/**
	 * Whether the newest entry for a path is held by a post in the trash. A
	 * newer entry on a live post that is not an ancestor of that post is
	 * another page's that held the path since; an alias on an ancestor can be
	 * newer too, and Rules tells it apart by the parent chain.
	 *
	 * @param string $post_type Post type name.
	 * @param string $path      Path, any form; normalized here.
	 * @return bool
	 */
	public static function newest_in_trash( string $post_type, string $path ): bool {
		global $wpdb;

		$path = Paths::normalize( $path );
		if ( '' === $path ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Seek on the meta_key index that stops at the newest entry; only for a 404.
		return 'trash' === $wpdb->get_var(
			$wpdb->prepare(
				"SELECT p.post_status FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND p.post_type = %s ORDER BY pm.meta_id DESC LIMIT 1",
				self::key( $post_type, $path ),
				$post_type
			)
		);
	}

	/**
	 * The override an administrator set on a record from the Redirects tab:
	 * a URL target, a chosen code, or both. A post's record keeps it in the
	 * sibling meta row with the entry's hash, read with one seek and cached
	 * until the ledger changes; a root-list entry keeps it in the
	 * ROOT_TARGETS option, by post type then path.
	 *
	 * @param string $post_type Post type name.
	 * @param string $path      Path, any form; normalized here.
	 * @param int    $holder    The post that holds the record, 0 for the root list.
	 * @return array{url:string,code:int}|null Null when the record has no override.
	 */
	public function target( string $post_type, string $path, int $holder ): ?array {
		global $wpdb;

		$path = Paths::normalize( $path );
		if ( $holder <= 0 ) {
			return self::clean_target( $this->root_targets()[ $post_type ][ $path ] ?? null );
		}

		$cache_key = 'target:' . $holder . ':' . md5( $post_type . ':' . $path ) . ':' . wp_cache_get_last_changed( self::CACHE_GROUP );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return self::clean_target( $cached['target'] ?? null );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Point lookup on the post_id index, one row; cached below.
		$raw    = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1", $holder, self::target_key( $post_type, $path ) ) );
		$target = null === $raw ? null : maybe_unserialize( (string) $raw );
		// phpcs:ignore WordPressVIPMinimum.Performance.LowExpiryCacheTime.CacheTimeUndetermined -- CACHE_TTL is 12 hours, well above 300 seconds.
		wp_cache_set( $cache_key, [ 'target' => $target ], self::CACHE_GROUP, self::CACHE_TTL );

		return self::clean_target( $target );
	}

	/**
	 * Stores a record's override, or removes it when it has neither a URL
	 * nor a chosen code. Every call drops the cached lookups.
	 *
	 * @param string $post_type Post type name.
	 * @param string $path      Path, any form; normalized here.
	 * @param int    $holder    The post that holds the record, 0 for the root list.
	 * @param string $url       An http(s) URL target, or '' for none.
	 * @param int    $code      301 or 302, or 0 for the automatic code.
	 */
	public function set_target( string $post_type, string $path, int $holder, string $url, int $code ): void {
		$path   = Paths::normalize( $path );
		$target = self::clean_target(
			[
				'url'  => $url,
				'code' => $code,
			]
		);

		if ( $holder > 0 ) {
			$key = self::target_key( $post_type, $path );
			if ( null === $target ) {
				delete_post_meta( $holder, $key );
			} else {
				update_post_meta( $holder, $key, $target );
			}
		} else {
			$all = $this->root_targets();
			if ( null === $target ) {
				unset( $all[ $post_type ][ $path ] );
			} else {
				$all[ $post_type ][ $path ] = $target;
			}
			$this->save_root_targets( $all );
		}

		$this->bump();
	}

	/**
	 * An override as stored, checked: an http(s) URL with a host or none,
	 * and 301, 302 or 0. Null when nothing is left.
	 *
	 * @param mixed $value Stored value.
	 * @return array{url:string,code:int}|null
	 */
	public static function clean_target( $value ): ?array {
		if ( ! is_array( $value ) ) {
			return null;
		}
		$url    = isset( $value['url'] ) && is_string( $value['url'] ) ? trim( $value['url'] ) : '';
		$code   = isset( $value['code'] ) && is_numeric( $value['code'] ) ? (int) $value['code'] : 0;
		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		if ( '' !== $url && ( ! in_array( $scheme, [ 'http', 'https' ], true ) || '' === (string) wp_parse_url( $url, PHP_URL_HOST ) ) ) {
			$url = '';
		}
		if ( ! in_array( $code, [ 301, 302 ], true ) ) {
			$code = 0;
		}
		if ( '' === $url && 0 === $code ) {
			return null;
		}
		return [
			'url'  => $url,
			'code' => $code,
		];
	}

	/**
	 * The root list: old parent paths whose children became top level, by post type.
	 *
	 * @return array<string,string[]>
	 */
	public function root_paths(): array {
		return $this->roots();
	}

	/**
	 * The newest post entries of the given types, newest first: the meta IDs
	 * the RECENT option keeps per type as entries are recorded, at most
	 * Subtree::count_cap() in all. `more` says older entries exist that the
	 * list no longer holds; when deletes take a full list below the cap,
	 * the rebuild reads the older entries back in and reads `more` afresh.
	 * One option read; an entry deleted since may still be named, and a
	 * reader skips it.
	 *
	 * @param string[] $types Post type names.
	 * @return array{ids:int[],more:bool}
	 */
	public function recent( array $types ): array {
		$all  = get_option( self::RECENT, [] );
		$all  = is_array( $all ) ? $all : [];
		$ids  = [];
		$more = false;
		foreach ( $types as $type ) {
			$list = is_array( $all[ $type ] ?? null ) ? $all[ $type ] : [];
			$ids  = array_merge( $ids, array_map( 'intval', (array) ( $list['ids'] ?? [] ) ) );
			$more = $more || ! empty( $list['more'] );
		}
		rsort( $ids );
		$cap = Subtree::count_cap();
		return [
			'ids'  => array_slice( $ids, 0, $cap ),
			'more' => $more || count( $ids ) > $cap,
		];
	}

	/**
	 * Makes sure the daily rebuild of the newest-records list is scheduled,
	 * on activation and on admin_init: one single event, which schedules
	 * the next one a day later when it runs, so on a site where the plugin
	 * is no longer active nothing schedules it again. Until a first build
	 * has completed, the list reads as being built and the rebuild runs on
	 * the next cron run. A type supported later is built by the next daily
	 * run, with no notice. It never runs in a web request, and once built a
	 * page load only reads autoloaded options here.
	 */
	public static function schedule_rebuild(): void {
		$state = (string) get_option( self::RECENT_STATE );
		if ( str_starts_with( $state, 'done' ) ) {
			// Missing, or a recurring event from an earlier build: one single run, a day ahead.
			if ( false === wp_next_scheduled( self::REBUILD_HOOK ) || false !== wp_get_schedule( self::REBUILD_HOOK ) ) {
				self::clear_leftovers();
				wp_schedule_single_event( time() + DAY_IN_SECONDS, self::REBUILD_HOOK );
			}
			return;
		}
		if ( 'building' !== $state ) {
			update_option( self::RECENT_STATE, 'building', true );
		}
		self::rebuild_soon();
	}

	/**
	 * Brings the rebuild forward to the next cron run, the one event moved
	 * rather than another added, unless it is due within a minute already.
	 */
	private static function rebuild_soon(): void {
		$next  = wp_next_scheduled( self::REBUILD_HOOK );
		$older = false !== $next && false !== wp_get_schedule( self::REBUILD_HOOK );
		if ( false !== $next && ! $older && $next <= time() + MINUTE_IN_SECONDS ) {
			return;
		}
		if ( false === $next || $older ) {
			self::clear_leftovers();
		}
		wp_clear_scheduled_hook( self::REBUILD_HOOK );
		wp_schedule_single_event( time(), self::REBUILD_HOOK );
	}

	/**
	 * Schedules the next daily run, from the rebuild itself, unless one is
	 * scheduled already.
	 */
	private static function schedule_next(): void {
		if ( false === wp_next_scheduled( self::REBUILD_HOOK ) ) {
			wp_schedule_single_event( time() + DAY_IN_SECONDS, self::REBUILD_HOOK );
		}
	}

	/**
	 * Upgrade cleanup, when the rebuild is first scheduled on a site: the
	 * one-time backfill's event, the list's lock and queue rows, and a
	 * recurring rebuild event, which development builds before the single
	 * daily event left behind.
	 */
	private static function clear_leftovers(): void {
		wp_clear_scheduled_hook( 'hierarchy_guard_backfill_recent' );
		wp_clear_scheduled_hook( self::REBUILD_HOOK );
		delete_option( 'hierarchy_guard_recent_lock' );
		delete_option( 'hierarchy_guard_recent_queue' );
	}

	/**
	 * The state that says the list is built, for the types it was built for.
	 *
	 * @param string[] $types Post type names.
	 * @return string
	 */
	private static function done_state( array $types ): string {
		$types = array_map( 'strval', $types );
		sort( $types );
		return 'done:' . implode( ',', $types );
	}

	/**
	 * Clears the daily rebuild on deactivation; a first build not finished
	 * is started again on the next activation.
	 */
	public static function unschedule_rebuild(): void {
		wp_clear_scheduled_hook( self::REBUILD_HOOK );
		if ( 'building' === get_option( self::RECENT_STATE ) ) {
			delete_option( self::RECENT_STATE );
		}
	}

	/**
	 * Whether the newest-records list is still being built for the first
	 * time: no first build has completed, whatever types it covered.
	 *
	 * @return bool
	 */
	public static function building(): bool {
		return 'building' === get_option( self::RECENT_STATE );
	}

	/**
	 * The rebuild, on its daily WP-Cron event: each supported type's list
	 * is read again from the database, the newest Subtree::count_cap()
	 * entries in one query per type ordered by meta ID, LIMIT the cap plus
	 * one, which also tells whether older entries exist. So an entry a lost
	 * update dropped comes back, and one whose row was deleted without its
	 * hook goes, within a day; meanwhile a search for a full old URL finds
	 * any record. Entries recorded while the queries ran are kept, and a
	 * write another request made while the rebuild wrote is merged with it.
	 * Running it again changes nothing. It first schedules the next daily
	 * run. The first build marks the list as built, for those types; when
	 * a query fails, that type's list is left as it was and the list is
	 * not marked built.
	 */
	public static function rebuild_recent(): void {
		global $wpdb;

		self::schedule_next();

		$cap    = Subtree::count_cap();
		$types  = Post_Types::supported();
		$found  = [];
		$failed = false;
		foreach ( $types as $type ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- From WP-Cron only: the newest entries of one type, LIMIT the cap plus one.
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT pm.meta_id FROM {$wpdb->postmeta} pm STRAIGHT_JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key LIKE %s AND p.post_type = %s ORDER BY pm.meta_id DESC LIMIT %d", $wpdb->esc_like( self::KEY_PREFIX ) . '%', $type, $cap + 1 ) );
			if ( '' !== (string) $wpdb->last_error ) {
				$failed = true;
				continue;
			}
			$found[ $type ] = array_map( 'intval', (array) $ids );
		}

		$all  = self::fresh_recent();
		$kept = [];
		foreach ( $types as $type ) {
			$list = is_array( $all[ $type ] ?? null ) ? $all[ $type ] : null;
			if ( ! isset( $found[ $type ] ) ) {
				// Its query failed: left as it was.
				if ( null !== $list ) {
					$kept[ $type ] = $list;
				}
				continue;
			}
			$ids = $found[ $type ];
			// Entries recorded after the query read the newest one, while their rows exist.
			$top   = [] !== $ids ? max( $ids ) : 0;
			$newer = self::existing( array_filter( array_map( 'intval', (array) ( $list['ids'] ?? [] ) ), static fn( int $id ): bool => $id > $top ) );
			$ids   = array_values( array_unique( array_merge( $newer, $ids ) ) );
			rsort( $ids );

			$kept[ $type ] = [
				'ids'  => array_slice( $ids, 0, $cap ),
				'more' => count( $ids ) > $cap,
			];
		}
		update_option( self::RECENT, $kept, false );
		self::merge_back( $kept, array_keys( $found ), $cap );
		if ( ! $failed ) {
			update_option( self::RECENT_STATE, self::done_state( $types ), true );
		}
	}

	/**
	 * After the rebuild wrote the list: when another request read the list
	 * before that write and wrote it after, the rebuilt entries are merged
	 * back into what it wrote, and the list written once more. Entries both
	 * lists hold stay; any other is kept while its row exists, in one
	 * primary-key lookup; newest first, at most the cap. Whether older
	 * entries exist is the rebuild's own reading, or the merged list
	 * overflowing the cap: the other request's may be stale.
	 *
	 * @param array<string,array{ids:int[],more:bool}> $built What the rebuild wrote.
	 * @param string[]                                 $types The types it read.
	 * @param int                                      $cap   Most entries per type.
	 */
	private static function merge_back( array $built, array $types, int $cap ): void {
		$exists = false;
		$now    = self::fresh_recent( $exists );
		if ( $now === $built ) {
			return;
		}
		$fresh = $now;
		foreach ( $types as $type ) {
			$mine   = array_map( 'intval', (array) ( $built[ $type ]['ids'] ?? [] ) );
			$theirs = array_map( 'intval', (array) ( $now[ $type ]['ids'] ?? [] ) );
			$both   = array_values( array_intersect( $mine, $theirs ) );
			$ids    = array_values( array_unique( array_merge( $both, self::existing( array_diff( array_merge( $mine, $theirs ), $both ) ) ) ) );
			rsort( $ids );

			$now[ $type ] = [
				'ids'  => array_slice( $ids, 0, $cap ),
				// The other request's `more` may be stale: the rebuild's own reading decides.
				'more' => ! empty( $built[ $type ]['more'] ) || count( $ids ) > $cap,
			];
		}
		// The cache still holds what the rebuild wrote; with the row as it is now, update_option() writes even a list equal to that.
		if ( $exists ) {
			wp_cache_set( self::RECENT, $fresh, 'options' );
		} else {
			// Another request deleted the row: update_option() adds it again once the cache no longer says it is there.
			wp_cache_delete( self::RECENT, 'options' );
		}
		update_option( self::RECENT, $now, false );
	}

	/**
	 * The meta IDs whose rows exist: one prepared query on the primary key,
	 * none for no IDs. From WP-Cron only; it can take up to twice the cap,
	 * from the merge-back.
	 *
	 * @param int[] $ids Meta IDs, up to twice Subtree::count_cap().
	 * @return int[]
	 */
	private static function existing( array $ids ): array {
		global $wpdb;

		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		if ( [] === $ids ) {
			return [];
		}
		$in = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $in holds only %d, one per ID; primary-key lookups, from WP-Cron only.
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_id IN ( {$in} )", $ids ) ) );
	}

	/**
	 * Takes entries out of the newest-records list when their rows are
	 * deleted, by forget(), prune() or claim(): hooked on deleted_post_meta.
	 * Other keys cost a prefix check. The rows of a post being deleted for
	 * good left the list already, all at once (on_delete_post()).
	 *
	 * @param int[]  $meta_ids  Meta IDs deleted.
	 * @param int    $object_id Post ID.
	 * @param string $meta_key  Meta key.
	 */
	public static function on_deleted_meta( $meta_ids, $object_id, $meta_key ): void {
		if ( ! str_starts_with( (string) $meta_key, self::KEY_PREFIX ) || isset( self::$deleting[ (int) $object_id ] ) ) {
			return;
		}
		self::drop_recent( array_map( 'intval', (array) $meta_ids ) );
	}

	/**
	 * Takes a supported post's entries out of the newest-records list at
	 * once when it is deleted for good: hooked on before_delete_post, last,
	 * which runs before core deletes the post's meta one row at a time (core
	 * fires delete_post only after that). One query for the post's entry
	 * IDs, and the list read and written once whatever their number, rather
	 * than once per row; on_deleted_meta() then passes over the post's rows
	 * until deleted_post.
	 *
	 * @param int          $post_id Post ID.
	 * @param WP_Post|null $post    The post.
	 */
	public static function on_delete_post( $post_id, $post = null ): void {
		global $wpdb;

		$post_id = (int) $post_id;
		if ( ! $post instanceof WP_Post || ! Post_Types::is_supported( (string) $post->post_type ) ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Range on the post_id index, once per post deleted for good.
		$ids = (array) $wpdb->get_col( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key LIKE %s", $post_id, $wpdb->esc_like( self::KEY_PREFIX ) . '%' ) );

		self::$deleting[ $post_id ] = true;
		if ( [] !== $ids ) {
			self::drop_recent( array_map( 'intval', $ids ) );
		}
	}

	/**
	 * Ends what on_delete_post() began, on deleted_post.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function on_deleted_post( $post_id ): void {
		unset( self::$deleting[ (int) $post_id ] );
	}

	/**
	 * Takes deleted entries out of the newest-records list: from what
	 * deferred() holds, and, when the list names any, from the list as the
	 * database holds it, written once.
	 *
	 * @param int[] $meta_ids Meta IDs of the entries deleted.
	 */
	private static function drop_recent( array $meta_ids ): void {
		$gone = array_flip( $meta_ids );

		// Entries recorded inside deferred() wait in memory: a row deleted in the same batch never reaches the list.
		foreach ( self::$recent_pending as $type => $ids ) {
			self::$recent_pending[ $type ] = array_values( array_filter( $ids, static fn( int $id ): bool => ! isset( $gone[ $id ] ) ) );
			if ( [] === self::$recent_pending[ $type ] ) {
				unset( self::$recent_pending[ $type ] );
			}
		}

		// Most deletes name no entry the list holds: one cached read.
		$listed = get_option( self::RECENT, [] );
		$named  = false;
		foreach ( is_array( $listed ) ? $listed : [] as $list ) {
			if ( [] !== array_intersect_key( array_flip( array_map( 'intval', (array) ( $list['ids'] ?? [] ) ) ), $gone ) ) {
				$named = true;
				break;
			}
		}
		if ( ! $named ) {
			return;
		}

		$all       = self::fresh_recent();
		$cap       = Subtree::count_cap();
		$supported = Post_Types::supported();
		$changed   = false;
		$short     = false;
		foreach ( $all as $type => $list ) {
			$had  = array_map( 'intval', (array) ( $list['ids'] ?? [] ) );
			$left = array_values( array_filter( $had, static fn( int $id ): bool => ! isset( $gone[ $id ] ) ) );
			if ( count( $left ) !== count( $had ) ) {
				$all[ $type ]['ids'] = $left;
				$changed             = true;
				$short               = $short || ( ! empty( $list['more'] ) && count( $left ) < $cap && in_array( (string) $type, $supported, true ) );
			}
		}
		if ( $changed ) {
			update_option( self::RECENT, $all, false );
		}
		// Older entries the full list left out are read back in by the next cron run, quietly: the tab says nothing for it.
		if ( $short ) {
			self::rebuild_soon();
		}
	}

	/**
	 * Single index seek on the hashed key: a live holder first, newest entry first.
	 * A post in the trash comes back only when no live post holds the key; find()
	 * then answers as if it held nothing.
	 *
	 * @param string $post_type Post type name.
	 * @param string $path      Normalized path.
	 */
	private function seek( string $post_type, string $path ): ?int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Point lookup on the meta_key index; cached by the caller.
		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND p.post_type = %s ORDER BY ( p.post_status = 'trash' ) ASC, pm.meta_id DESC LIMIT 1",
				self::key( $post_type, $path ),
				$post_type
			)
		);

		return $id ? (int) $id : null;
	}

	/**
	 * The root-paths option as an array keyed by post type.
	 *
	 * @return array<string,string[]>
	 */
	private function roots(): array {
		$roots = get_option( self::ROOT_OPTION, [] );
		return is_array( $roots ) ? $roots : [];
	}

	/**
	 * Puts entries just recorded at the front of their type's newest-records
	 * list, keeping at most Subtree::count_cap(); inside deferred() they wait
	 * and are written once at its end. The option is not autoloaded.
	 *
	 * @param string $post_type Post type name.
	 * @param int[]  $meta_ids  Meta IDs, oldest first.
	 */
	private static function remember( string $post_type, array $meta_ids ): void {
		if ( self::$defer_depth > 0 ) {
			self::$recent_pending[ $post_type ] = array_merge( self::$recent_pending[ $post_type ] ?? [], $meta_ids );
			return;
		}
		$all  = self::fresh_recent();
		$list = is_array( $all[ $post_type ] ?? null ) ? $all[ $post_type ] : [];
		$ids  = array_values( array_unique( array_merge( array_map( 'intval', $meta_ids ), array_map( 'intval', (array) ( $list['ids'] ?? [] ) ) ) ) );
		// Newest first, so the cap's slice keeps the newest entries whatever order the list was in.
		rsort( $ids );
		$cap = Subtree::count_cap();

		$all[ $post_type ] = [
			'ids'  => array_slice( $ids, 0, $cap ),
			'more' => ! empty( $list['more'] ) || count( $ids ) > $cap,
		];
		update_option( self::RECENT, $all, false );
	}

	/**
	 * The newest-records list as the database holds it now, past this
	 * request's cache, read once right before a write, so an entry another
	 * request wrote meanwhile is kept. Two writes at the very same moment
	 * can still lose one; the daily rebuild puts it back.
	 *
	 * @param bool|null $exists Set to whether the option's row exists.
	 * @return array<string,array{ids:int[],more:bool}>
	 */
	private static function fresh_recent( ?bool &$exists = null ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One read of one option row, right before it is written.
		$row    = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::RECENT ) );
		$exists = null !== $row;
		$all    = maybe_unserialize( (string) $row );
		return is_array( $all ) ? $all : [];
	}

	/**
	 * The root list's overrides, by post type then path.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function root_targets(): array {
		$all = get_option( self::ROOT_TARGETS, [] );
		return is_array( $all ) ? $all : [];
	}

	/**
	 * Saves the root list's overrides, not autoloaded; removes the option once it is empty.
	 *
	 * @param array<string,array<string,mixed>> $all Overrides by post type then path.
	 */
	private function save_root_targets( array $all ): void {
		$all = array_filter( $all, static fn( $by_path ): bool => is_array( $by_path ) && [] !== $by_path );
		if ( [] === $all ) {
			delete_option( self::ROOT_TARGETS );
			return;
		}
		update_option( self::ROOT_TARGETS, $all, false );
	}

	/**
	 * Removes the overrides of root-list entries that are going.
	 *
	 * @param string   $post_type Post type name.
	 * @param string[] $paths     Normalized paths.
	 */
	private function drop_root_targets( string $post_type, array $paths ): void {
		$all = $this->root_targets();
		if ( ! isset( $all[ $post_type ] ) || ! is_array( $all[ $post_type ] ) ) {
			return;
		}
		$left = array_diff_key( $all[ $post_type ], array_flip( $paths ) );
		if ( count( $left ) === count( $all[ $post_type ] ) ) {
			return;
		}
		$all[ $post_type ] = $left;
		$this->save_root_targets( $all );
	}

	/**
	 * Removes the override beside a post's record that is going. From
	 * forget() and prune() the post's meta is in the cache by then, as
	 * metadata_exists() read it, so a record without one costs no query;
	 * claim() finds its holders by one query, and loads each holder's meta
	 * once here.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $post_type Post type name.
	 * @param string $path      Normalized path.
	 */
	private function drop_target( int $post_id, string $post_type, string $path ): void {
		$key = self::target_key( $post_type, $path );
		if ( metadata_exists( 'post', $post_id, $key ) ) {
			delete_post_meta( $post_id, $key );
		}
	}

	/**
	 * Invalidates every cached lookup, or notes that it is due inside deferred().
	 */
	private function bump(): void {
		if ( self::$defer_depth > 0 ) {
			self::$bump_pending = true;
			return;
		}
		wp_cache_set_last_changed( self::CACHE_GROUP );
	}
}
