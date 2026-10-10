<?php
/**
 * Bounded queries over a post's subtree.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard;

use WP_Post;

/**
 * Every query here is bounded by a limit or a cap, never by the size of the
 * subtree. "Live" means any status except trash and core's internal
 * auto-draft and inherit. Statuses are read straight from the database
 * where a long-running batch must not trust a cached post.
 */
final class Subtree {

	public const COUNT_CAP = 1000;

	/**
	 * The most a subtree count reports exactly.
	 *
	 * @return int
	 */
	public static function count_cap(): int {
		/**
		 * Filters the cap on subtree counts.
		 *
		 * @param int $cap Largest count reported exactly, 1,000 by default.
		 */
		return max( 1, (int) apply_filters( 'hierarchy_guard_count_cap', self::COUNT_CAP ) );
	}

	/**
	 * A post's status read from the database, bypassing the object cache.
	 *
	 * @param int $post_id Post ID.
	 * @return string The status, or an empty string when the post does not exist.
	 */
	public static function status( int $post_id ): string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- A batch must see a restore that happened in another request.
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT post_status FROM {$wpdb->posts} WHERE ID = %d", $post_id ) );
	}

	/**
	 * Whether a post has at least one live child of its type.
	 *
	 * @param int    $parent_id Parent post ID.
	 * @param string $post_type Post type name.
	 * @return bool
	 */
	public static function has_live_children( int $parent_id, string $post_type ): bool {
		return [] !== self::live_children( $parent_id, $post_type, 1 );
	}

	/**
	 * Live direct children, lowest ID first, after a cursor.
	 *
	 * @param int    $parent_id Parent post ID.
	 * @param string $post_type Post type name.
	 * @param int    $limit     Maximum IDs to return.
	 * @param int    $after_id  Only IDs above this one.
	 * @return int[]
	 */
	public static function live_children( int $parent_id, string $post_type, int $limit, int $after_id = 0 ): array {
		global $wpdb;

		if ( $limit < 1 ) {
			return [];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Cursor over the post_parent index; every batch writes, so a cache would serve stale pages.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = %s AND post_status NOT IN ( 'trash', 'auto-draft', 'inherit' ) AND ID > %d ORDER BY ID ASC LIMIT %d",
				$parent_id,
				$post_type,
				$after_id,
				$limit
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Number of live direct children, stopping at a cap.
	 *
	 * @param int    $parent_id Parent post ID.
	 * @param string $post_type Post type name.
	 * @param int    $cap       Stop counting at this number.
	 * @return int
	 */
	public static function count_live_children( int $parent_id, string $post_type, int $cap ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Index range on post_parent, bounded by LIMIT.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM ( SELECT ID FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = %s AND post_status NOT IN ( 'trash', 'auto-draft', 'inherit' ) LIMIT %d ) AS children",
				$parent_id,
				$post_type,
				max( 1, $cap )
			)
		);
	}

	/**
	 * Counts live descendants level by level, stopping once the count passes the cap.
	 *
	 * @param int    $root_id   Root post ID.
	 * @param string $post_type Post type name.
	 * @param int    $cap       Stop counting past this number.
	 * @return int The count, or $cap + 1 when there are more.
	 */
	public static function count_live_descendants( int $root_id, string $post_type, int $cap ): int {
		global $wpdb;

		$cap   = max( 1, $cap );
		$count = 0;
		$level = [ $root_id ];
		$depth = 0;

		while ( [] !== $level && $count <= $cap && $depth < 50 ) {
			++$depth;
			$in = implode( ',', array_map( 'intval', $level ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in holds integers only; LIMIT bounds each level by the remaining cap.
			$level  = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_parent IN ( {$in} ) AND post_type = %s AND post_status NOT IN ( 'trash', 'auto-draft', 'inherit' ) LIMIT %d", $post_type, $cap + 1 - $count ) ) );
			$count += count( $level );
		}

		return min( $count, $cap + 1 );
	}

	/**
	 * Which of the given posts have at least one live child.
	 *
	 * @param int[]  $ids       Post IDs, at most a batch.
	 * @param string $post_type Post type name.
	 * @return int[]
	 */
	public static function with_live_children( array $ids, string $post_type ): array {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( [] === $ids ) {
			return [];
		}

		$in = implode( ',', $ids );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in holds integers only, at most one batch of them.
		$parents = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_parent FROM {$wpdb->posts} WHERE post_parent IN ( {$in} ) AND post_type = %s AND post_status NOT IN ( 'trash', 'auto-draft', 'inherit' )", $post_type ) );

		$parents = array_map( 'intval', $parents );
		return array_values( array_filter( $ids, static fn( int $id ): bool => in_array( $id, $parents, true ) ) );
	}

	/**
	 * The closest ancestor that is not in the trash, or 0 for top level.
	 *
	 * @param WP_Post $post Post object.
	 * @return int
	 */
	public static function nearest_live_ancestor( WP_Post $post ): int {
		foreach ( get_post_ancestors( $post ) as $ancestor_id ) {
			$status = self::status( (int) $ancestor_id );
			if ( '' !== $status && 'trash' !== $status ) {
				return (int) $ancestor_id;
			}
		}
		return 0;
	}
}
