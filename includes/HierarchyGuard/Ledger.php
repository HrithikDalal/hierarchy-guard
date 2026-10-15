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
	public const RECENT_LOCK   = 'hierarchy_guard_recent_lock';
	public const RECENT_QUEUE  = 'hierarchy_guard_recent_queue';
	public const LOCK_TTL      = 10;
	public const BACKFILL_HOOK = 'hierarchy_guard_backfill_recent';
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
	 * the backfill reads the older entries back in and reads `more` afresh.
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
	 * Schedules the backfill of the newest-records list, on activation and
	 * on admin_init, while the list was never built for the types supported
	 * now: records made before the list existed, or before their type was
	 * supported, reach it from WP-Cron, never in a web request. Once built,
	 * this is one read of an autoloaded option.
	 */
	public static function schedule_backfill(): void {
		if ( self::done_state( Post_Types::supported() ) === get_option( self::RECENT_STATE ) ) {
			return;
		}
		self::rebuild();
	}

	/**
	 * Marks the newest-records list as being built and schedules the backfill once.
	 */
	private static function rebuild(): void {
		if ( 'building' !== get_option( self::RECENT_STATE ) ) {
			update_option( self::RECENT_STATE, 'building', true );
		}
		if ( false === wp_next_scheduled( self::BACKFILL_HOOK ) ) {
			wp_schedule_single_event( time(), self::BACKFILL_HOOK );
		}
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
	 * Clears the backfill on deactivation; one not finished is scheduled
	 * again on the next activation.
	 */
	public static function unschedule_backfill(): void {
		wp_clear_scheduled_hook( self::BACKFILL_HOOK );
		if ( 'building' === get_option( self::RECENT_STATE ) ) {
			delete_option( self::RECENT_STATE );
		}
	}

	/**
	 * Whether the newest-records list is still being built.
	 *
	 * @return bool
	 */
	public static function backfilling(): bool {
		return 'building' === get_option( self::RECENT_STATE );
	}

	/**
	 * The backfill, on its WP-Cron event: each supported type's list gets
	 * the newest Subtree::count_cap() entries of that type, in one query per
	 * type ordered by meta ID, merged with what the list already holds, so
	 * running it again changes nothing. Whether older entries exist is read
	 * afresh. Then it marks itself done for those types; when a query
	 * fails, the list stays being built and the next admin load schedules
	 * the backfill again.
	 */
	public static function backfill(): void {
		global $wpdb;

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

		// Entries recorded while the queries ran are kept.
		unset( $cap );
		$ops = [];
		foreach ( $found as $type => $ids ) {
			$ops[] = [ 'fill', (string) $type, $ids ];
		}
		self::write_recent( $ops );
		if ( ! $failed ) {
			update_option( self::RECENT_STATE, self::done_state( $types ), true );
		}
	}

	/**
	 * Takes entries out of the newest-records list when their rows are
	 * deleted, by forget(), prune(), claim() or core deleting their post:
	 * hooked on deleted_post_meta. Other keys cost a prefix check.
	 *
	 * @param int[]  $meta_ids  Meta IDs deleted.
	 * @param int    $object_id Post ID.
	 * @param string $meta_key  Meta key.
	 */
	public static function on_deleted_meta( $meta_ids, $object_id, $meta_key ): void {
		unset( $object_id );
		if ( ! str_starts_with( (string) $meta_key, self::KEY_PREFIX ) ) {
			return;
		}
		$gone = array_flip( array_map( 'intval', (array) $meta_ids ) );

		// Entries recorded inside deferred() wait in memory: a row deleted in the same batch never reaches the list.
		foreach ( self::$recent_pending as $type => $ids ) {
			self::$recent_pending[ $type ] = array_values( array_filter( $ids, static fn( int $id ): bool => ! isset( $gone[ $id ] ) ) );
			if ( [] === self::$recent_pending[ $type ] ) {
				unset( self::$recent_pending[ $type ] );
			}
		}

		// Most deletes name no entry the list holds: one cached read, no lock.
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

		self::write_recent( [ [ 'drop', '', array_map( 'intval', array_keys( $gone ) ) ] ] );
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
		self::write_recent( [ [ 'add', $post_type, array_map( 'intval', $meta_ids ) ] ] );
	}

	/**
	 * Applies changes to the newest-records list without losing any, and
	 * without waiting: under an option-row lock (an INSERT, which only one
	 * request can make, expiring after LOCK_TTL seconds, as the job queue's
	 * locks do), on a fresh read of the row. A request that finds the lock
	 * held appends its changes to a queue row in one atomic statement
	 * instead, and whichever request holds the lock next applies them; the
	 * holder looks at the queue again after it lets go. The lock's INSERT
	 * comes before every read here, so a read/write-split database drop-in
	 * sends the reads to the primary.
	 *
	 * @param array<int,array{0:string,1:string,2:int[]}> $ops Changes: add (a type's IDs just recorded, oldest first), drop (IDs deleted, any type) or fill (a type's newest IDs, from the backfill).
	 */
	private static function write_recent( array $ops ): void {
		if ( [] === $ops ) {
			return;
		}
		$token = self::lock_recent();
		if ( 0 === $token ) {
			self::queue_recent( $ops );
			// The holder may have let go meanwhile; if so, apply what waits now.
			$token = self::lock_recent();
			if ( 0 === $token ) {
				return;
			}
			$ops = [];
		}
		for ( $round = 0; $round < 3 && 0 !== $token; $round++ ) {
			self::apply_recent( $ops );
			self::unlock_recent( $token );
			$ops   = [];
			$token = '' !== self::read_row( self::RECENT_QUEUE ) ? self::lock_recent() : 0;
		}
		if ( 0 !== $token ) {
			self::unlock_recent( $token );
		}
	}

	/**
	 * Applies the queued changes and these, under the lock: one fresh read
	 * of the list and the queue, one write of the list, and the queue's
	 * applied part taken off its front. A supported type's list that drops
	 * below the cap while older entries exist is filled again quietly, by
	 * the backfill's cron event, without marking the list as being built.
	 *
	 * @param array<int,array{0:string,1:string,2:int[]}> $ops Changes.
	 */
	private static function apply_recent( array $ops ): void {
		global $wpdb;

		// What waited in the queue happened first.
		$queued = self::read_row( self::RECENT_QUEUE );
		$first  = [];
		foreach ( '' !== $queued ? explode( "\n", trim( $queued ) ) : [] as $line ) {
			$op = json_decode( $line, true );
			if ( is_array( $op ) && 3 === count( $op ) ) {
				$first[] = [ (string) $op[0], (string) $op[1], array_map( 'intval', (array) $op[2] ) ];
			}
		}
		$ops = array_merge( $first, $ops );
		$all = maybe_unserialize( self::read_row( self::RECENT ) );
		$all = is_array( $all ) ? $all : [];

		$cap       = Subtree::count_cap();
		$supported = Post_Types::supported();
		$short     = false;
		foreach ( $ops as [ $kind, $type, $ids ] ) {
			if ( 'drop' === $kind ) {
				$gone = array_flip( $ids );
				foreach ( $all as $listed => $list ) {
					$had  = array_map( 'intval', (array) ( $list['ids'] ?? [] ) );
					$left = array_values( array_filter( $had, static fn( int $id ): bool => ! isset( $gone[ $id ] ) ) );
					if ( count( $left ) !== count( $had ) ) {
						$all[ $listed ]['ids'] = $left;
						$short                 = $short || ( ! empty( $list['more'] ) && count( $left ) < $cap && in_array( (string) $listed, $supported, true ) );
					}
				}
				continue;
			}
			$list = is_array( $all[ $type ] ?? null ) ? $all[ $type ] : [];
			$had  = array_map( 'intval', (array) ( $list['ids'] ?? [] ) );
			if ( 'add' === $kind ) {
				$merged = array_merge( array_reverse( $ids ), $had );
				$more   = ! empty( $list['more'] ) || count( $merged ) > $cap;
			} else {
				$merged = array_values( array_unique( array_merge( $ids, $had ) ) );
				rsort( $merged );
				$more = count( $merged ) > $cap;
			}
			$all[ $type ] = [
				'ids'  => array_slice( $merged, 0, $cap ),
				'more' => $more,
			];
		}

		$data = maybe_serialize( $all );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Written under the lock, on the fresh read above; the cache is cleared below.
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'off' ) ON DUPLICATE KEY UPDATE option_value = %s", self::RECENT, $data, $data ) );
		if ( '' !== $queued ) {
			// Only the part read is taken off: what was appended since stays queued.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Under the lock; the queue is never cached.
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = SUBSTRING( option_value, %d ) WHERE option_name = %s", strlen( $queued ) + 1, self::RECENT_QUEUE ) );
		}
		self::forget_cached( self::RECENT );

		// Older entries the full list left out are read back in, from WP-Cron.
		if ( $short && false === wp_next_scheduled( self::BACKFILL_HOOK ) ) {
			wp_schedule_single_event( time(), self::BACKFILL_HOOK );
		}
	}

	/**
	 * Appends changes to the queue row in one statement, which no other
	 * request's append can interleave with.
	 *
	 * @param array<int,array{0:string,1:string,2:int[]}> $ops Changes.
	 */
	private static function queue_recent( array $ops ): void {
		global $wpdb;

		$lines = '';
		foreach ( $ops as $op ) {
			$lines .= wp_json_encode( $op ) . "\n";
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- An atomic append; the queue is never cached.
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'off' ) ON DUPLICATE KEY UPDATE option_value = CONCAT( option_value, %s )", self::RECENT_QUEUE, $lines, $lines ) );
	}

	/**
	 * Takes the list's lock: inserts its row, or takes over one whose time ran out.
	 *
	 * @return int The lock's expiry, its token; 0 when another request holds it.
	 */
	private static function lock_recent(): int {
		global $wpdb;

		$now   = time();
		$token = $now + self::LOCK_TTL;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- add_option() is an upsert, so it cannot serve as a mutex.
		if ( 1 === $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'off' )", self::RECENT_LOCK, (string) $token ) ) ) {
			return $token;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic takeover of a lock whose request died.
		$taken = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND CAST( option_value AS UNSIGNED ) < %d", (string) $token, self::RECENT_LOCK, $now ) );
		return 1 === $taken ? $token : 0;
	}

	/**
	 * Lets go of the list's lock, unless another request took it over since.
	 *
	 * @param int $token Token lock_recent() returned.
	 */
	private static function unlock_recent( int $token ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Releases only this request's own lock.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::RECENT_LOCK, (string) $token ) );
	}

	/**
	 * An option row as the database holds it now, past any cache.
	 *
	 * @param string $name Option name.
	 * @return string The raw value, '' when there is no row.
	 */
	private static function read_row( string $name ): string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- A fresh read, after the lock's write, so from the primary.
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
	}

	/**
	 * Drops an option written directly from every request's cache, the
	 * persistent object cache included, so the next get_option() reads it.
	 *
	 * @param string $name Option name.
	 */
	private static function forget_cached( string $name ): void {
		wp_cache_delete( $name, 'options' );
		$missing = wp_cache_get( 'notoptions', 'options' );
		if ( is_array( $missing ) && isset( $missing[ $name ] ) ) {
			unset( $missing[ $name ] );
			wp_cache_set( 'notoptions', $missing, 'options' );
		}
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
