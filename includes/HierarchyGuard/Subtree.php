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

	/**
	 * Live direct-child counts for a screenful of posts, each stopping at the cap.
	 * One statement: a capped subquery per post, joined with UNION ALL.
	 *
	 * @param int[]  $ids       Post IDs, at most one list-table page of them.
	 * @param string $post_type Post type name.
	 * @param int    $cap       Stop counting each post's children at this number.
	 * @return array<int,int> Count by post ID; posts without live children are 0.
	 */
	public static function child_counts( array $ids, string $post_type, int $cap ): array {
		global $wpdb;

		$ids    = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		$counts = array_fill_keys( $ids, 0 );
		if ( [] === $ids ) {
			return $counts;
		}

		$cap   = max( 1, $cap );
		$parts = [];
		foreach ( $ids as $id ) {
			$parts[] = $wpdb->prepare(
				"( SELECT %d AS parent_id, COUNT(*) AS children FROM ( SELECT ID FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = %s AND post_status NOT IN ( 'trash', 'auto-draft', 'inherit' ) LIMIT %d ) AS c%d )",
				$id,
				$id,
				$post_type,
				$cap,
				$id
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Every part was prepared above; each is an index range on post_parent bounded by LIMIT.
		$rows = $wpdb->get_results( implode( ' UNION ALL ', $parts ), ARRAY_A );
		foreach ( (array) $rows as $row ) {
			$counts[ (int) $row['parent_id'] ] = (int) $row['children'];
		}

		return $counts;
	}

	/**
	 * Live children of the given parents, at most $limit, in menu order then
	 * title then ID. The first query follows the post_parent index in its own
	 * order and stops at the limit; the second sorts only that bounded set. No
	 * query reads every child of a large parent.
	 *
	 * @param int[]  $parent_ids Parent post IDs; 0 stands for top level.
	 * @param string $post_type  Post type name.
	 * @param int    $limit      Most children to read.
	 * @return array<int,array{ID:string,post_parent:string,post_name:string,post_status:string}>
	 */
	public static function ordered_children( array $parent_ids, string $post_type, int $limit ): array {
		global $wpdb;

		$parent_ids = array_values( array_unique( array_map( 'intval', $parent_ids ) ) );
		if ( [] === $parent_ids || $limit < 1 ) {
			return [];
		}

		$in = implode( ',', $parent_ids );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in holds integers only; the index's own order lets LIMIT stop the read.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_parent IN ( {$in} ) AND post_type = %s AND post_status NOT IN ( 'trash', 'auto-draft', 'inherit' ) ORDER BY post_parent ASC, ID ASC LIMIT %d", $post_type, $limit ) );
		if ( [] === $ids ) {
			return [];
		}

		$ids          = array_map( 'intval', $ids );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $placeholders holds only %d, one per ID read above, at most $limit of them.
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_parent, post_name, post_status FROM {$wpdb->posts} WHERE ID IN ( {$placeholders} ) ORDER BY menu_order ASC, post_title ASC, ID ASC", $ids ), ARRAY_A );
	}

	/**
	 * Live descendants level by level, stopping at the cap.
	 *
	 * @param int    $root_id   Root post ID.
	 * @param string $post_type Post type name.
	 * @param int    $cap       Most IDs to return.
	 * @return array{ids:int[],capped:bool} IDs root-near first, each level in menu order; capped when more exist.
	 */
	public static function descendant_ids( int $root_id, string $post_type, int $cap ): array {
		$cap   = max( 1, $cap );
		$ids   = [];
		$found = 0;
		$level = [ $root_id ];
		$depth = 0;

		while ( [] !== $level && $found <= $cap && $depth < 50 ) {
			++$depth;
			$level  = array_map( 'intval', array_column( self::ordered_children( $level, $post_type, $cap + 1 - $found ), 'ID' ) );
			$ids    = array_merge( $ids, $level );
			$found += count( $level );
		}

		return [
			'ids'    => array_slice( $ids, 0, $cap ),
			'capped' => $found > $cap,
		];
	}

	/**
	 * A count for display: "1,000", or "1,000+" when more exist.
	 *
	 * @param int  $count  Count, already at most the cap.
	 * @param bool $capped Whether more exist.
	 * @return string
	 */
	public static function format_count( int $count, bool $capped = false ): string {
		$number = number_format_i18n( $count );
		/* translators: %s: a number that is a lower bound, such as 1,000. */
		return $capped ? sprintf( __( '%s+', 'hierarchy-guard' ), $number ) : $number;
	}
}
