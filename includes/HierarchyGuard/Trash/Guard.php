<?php
/**
 * Re-entrancy guard for the plugin's own trash and restore calls.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Trash;

/**
 * While the plugin trashes or restores a subpage itself, its own hooks must
 * not treat that call as a new editor action. The guard covers only the post
 * IDs the plugin is acting on, so a trash another plugin starts from inside
 * the same request still gets its policy.
 */
final class Guard {

	/**
	 * Covered post IDs and how many nested runs cover each.
	 *
	 * @var array<int,int>
	 */
	private static array $covered = [];

	/**
	 * Runs a callback with the given post IDs covered.
	 *
	 * @param int[]    $ids      Post IDs the callback acts on.
	 * @param callable $callback Work to run.
	 * @return mixed The callback's return value.
	 */
	public static function run( array $ids, callable $callback ) {
		$ids = array_map( 'intval', $ids );
		foreach ( $ids as $id ) {
			self::$covered[ $id ] = ( self::$covered[ $id ] ?? 0 ) + 1;
		}
		try {
			return $callback();
		} finally {
			foreach ( $ids as $id ) {
				if ( --self::$covered[ $id ] <= 0 ) {
					unset( self::$covered[ $id ] );
				}
			}
		}
	}

	/**
	 * Whether the plugin is acting on this post right now.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function covers( int $post_id ): bool {
		return isset( self::$covered[ $post_id ] );
	}
}
