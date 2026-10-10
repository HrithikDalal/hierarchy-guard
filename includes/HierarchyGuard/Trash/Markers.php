<?php
/**
 * Markers on subpages the plugin moved or trashed.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Trash;

/**
 * Post meta that lets a restore undo exactly what the trash flow did.
 *
 * Each marker key carries the ID of the page whose trash caused it, so a
 * restore reads only its own rows with a seek on the meta_key index, and each
 * row is deleted once it has been handled. Rows are written and deleted with
 * direct queries: they are internal, and going through add_post_meta() would
 * bump the site-wide posts cache key once more per subpage. The values are
 * post IDs, which mean nothing on another site, so they never leave in a WXR
 * export.
 */
final class Markers {

	public const MOVED_PREFIX   = '_hierarchy_guard_moved_';
	public const TRASHED_PREFIX = '_hierarchy_guard_trashed_';
	public const ALIASES        = '_hierarchy_guard_aliases';

	/**
	 * Key of the marker set on a subpage moved when $root_id was trashed.
	 *
	 * @param int $root_id The trashed page.
	 * @return string
	 */
	public static function moved_key( int $root_id ): string {
		return self::MOVED_PREFIX . $root_id;
	}

	/**
	 * Key of the marker set on a subpage trashed together with $root_id.
	 *
	 * @param int $root_id The trashed page.
	 * @return string
	 */
	public static function trashed_key( int $root_id ): string {
		return self::TRASHED_PREFIX . $root_id;
	}

	/**
	 * Hooks into core.
	 */
	public function register(): void {
		add_filter( 'wxr_export_skip_postmeta', [ $this, 'skip_on_export' ], 10, 2 );
		add_filter( 'wp_untrash_post_status', [ $this, 'previous_status' ], 20, 3 );
	}

	/**
	 * Gives a subpage trashed together with its parent back the status it had,
	 * however it leaves the trash. Core alone would make it a draft. Core's own
	 * wp_untrash_post_set_previous_status() callback is never added or removed.
	 *
	 * @param mixed $new_status      Status core is about to assign.
	 * @param mixed $post_id         Post being untrashed.
	 * @param mixed $previous_status Status it had before the trash.
	 * @return string
	 */
	public function previous_status( $new_status, $post_id, $previous_status ): string {
		if ( '' !== (string) $previous_status && self::has_trashed_row( (int) $post_id ) ) {
			return (string) $previous_status;
		}
		return (string) $new_status;
	}

	/**
	 * Leaves the markers out of WXR exports. Old-path ledger entries stay in.
	 *
	 * @param mixed $skip     Whether an earlier callback skips the row.
	 * @param mixed $meta_key Meta key of the row.
	 * @return bool
	 */
	public function skip_on_export( $skip, $meta_key ): bool {
		$key = (string) $meta_key;
		return (bool) $skip
			|| str_starts_with( $key, self::MOVED_PREFIX )
			|| str_starts_with( $key, self::TRASHED_PREFIX )
			|| self::ALIASES === $key;
	}

	/**
	 * The value of a moved marker.
	 *
	 * @param int    $to       New parent, 0 for top level.
	 * @param string $original Slug before the move.
	 * @param string $assigned Slug the move gave it.
	 * @param int    $time     Unix time of the move.
	 * @return string
	 */
	public static function encode_moved( int $to, string $original, string $assigned, int $time ): string {
		return implode( '|', [ $to, $original, $assigned, $time ] );
	}

	/**
	 * Reads a moved marker's value. Slugs never contain a pipe.
	 *
	 * @param string $value Stored value.
	 * @return array{to:int,original:string,assigned:string,time:int}
	 */
	public static function decode_moved( string $value ): array {
		$parts = array_pad( explode( '|', $value, 4 ), 4, '' );
		return [
			'to'       => (int) $parts[0],
			'original' => (string) $parts[1],
			'assigned' => (string) $parts[2],
			'time'     => (int) $parts[3],
		];
	}

	/**
	 * Adds a marker row.
	 *
	 * @param int    $post_id Subpage.
	 * @param string $key     Marker key.
	 * @param string $value   Marker value.
	 */
	public static function add( int $post_id, string $key, string $value ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Internal marker row; the post's meta cache is cleared next.
		$wpdb->insert(
			$wpdb->postmeta,
			[
				'post_id'    => $post_id,
				'meta_key'   => $key,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- An insert, not a query on the value.
				'meta_value' => $value,
			],
			[ '%d', '%s', '%s' ]
		);
		wp_cache_delete( $post_id, 'post_meta' );
	}

	/**
	 * Marker rows with a key, oldest first, after a meta_id cursor.
	 *
	 * @param string $key           Marker key.
	 * @param int    $limit         Maximum rows.
	 * @param int    $after_meta_id Only rows above this meta_id.
	 * @return array<int,array{meta_id:int,post_id:int,value:string}>
	 */
	public static function rows( string $key, int $limit, int $after_meta_id = 0 ): array {
		global $wpdb;

		if ( $limit < 1 ) {
			return [];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Seek on the meta_key index, bounded by LIMIT; every step deletes what it read.
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_id, post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_id > %d ORDER BY meta_id ASC LIMIT %d",
				$key,
				$after_meta_id,
				$limit
			),
			ARRAY_A
		);

		$rows = [];
		foreach ( (array) $results as $row ) {
			$rows[] = [
				'meta_id' => (int) $row['meta_id'],
				'post_id' => (int) $row['post_id'],
				'value'   => (string) $row['meta_value'],
			];
		}
		return $rows;
	}

	/**
	 * Deletes handled rows.
	 *
	 * @param array<int,array{meta_id:int,post_id:int,value:string}> $rows Rows from rows().
	 */
	public static function remove( array $rows ): void {
		global $wpdb;

		if ( [] === $rows ) {
			return;
		}

		$meta_ids = implode( ',', array_map( static fn( array $row ): int => (int) $row['meta_id'], $rows ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $meta_ids holds integers only; the meta caches are cleared next.
		$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_id IN ( {$meta_ids} )" );

		$post_ids = array_values( array_unique( array_map( static fn( array $row ): int => (int) $row['post_id'], $rows ) ) );
		wp_cache_delete_multiple( $post_ids, 'post_meta' );
	}

	/**
	 * Deletes one post's rows with a key.
	 *
	 * @param int    $post_id Subpage.
	 * @param string $key     Marker key.
	 */
	public static function remove_key( int $post_id, string $key ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Internal marker row; the post's meta cache is cleared next.
		$wpdb->delete(
			$wpdb->postmeta,
			[
				'post_id'  => $post_id,
				'meta_key' => $key,
			],
			[ '%d', '%s' ]
		);
		wp_cache_delete( $post_id, 'post_meta' );
	}

	/**
	 * How many marker rows point at a root, stopping at a cap.
	 *
	 * @param int $root_id Root page.
	 * @param int $cap     Stop counting at this number.
	 * @return int
	 */
	public static function count( int $root_id, int $cap ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Two seeks on the meta_key index, bounded by LIMIT.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM ( SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key IN ( %s, %s ) LIMIT %d ) AS markers",
				self::moved_key( $root_id ),
				self::trashed_key( $root_id ),
				max( 1, $cap )
			)
		);
	}

	/**
	 * Whether a post was trashed together with some root page. Reads the meta
	 * cache, which core has already loaded whenever a post is being untrashed.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function has_trashed_row( int $post_id ): bool {
		return [] !== self::trashed_keys( $post_id );
	}

	/**
	 * Deletes a post's own trashed-with rows, for when it leaves the trash on its own.
	 *
	 * @param int $post_id Post ID.
	 * @return int Rows deleted.
	 */
	public static function clear_trashed_rows( int $post_id ): int {
		$keys = self::trashed_keys( $post_id );
		foreach ( $keys as $key ) {
			self::remove_key( $post_id, $key );
		}
		return count( $keys );
	}

	/**
	 * A post's trashed-with marker keys.
	 *
	 * @param int $post_id Post ID.
	 * @return string[]
	 */
	private static function trashed_keys( int $post_id ): array {
		$keys = [];
		foreach ( array_keys( (array) get_post_meta( $post_id ) ) as $key ) {
			if ( str_starts_with( (string) $key, self::TRASHED_PREFIX ) ) {
				$keys[] = (string) $key;
			}
		}
		return $keys;
	}
}
