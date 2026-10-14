<?php
/**
 * Where the blocks of a list of pairs start.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Redirects;

use HierarchyGuard\Paths;
use HierarchyGuard\Settings;
use HierarchyGuard\Subtree;
use HierarchyGuard\Trash\Markers;
use WP_Post;

/**
 * The block sources: one per page a save moved or renamed, one per root of a
 * trash change that is still in the trash. A block names its old path, its
 * head, the page its new URLs start under, and the first-level pages its walk
 * starts from. Once a page a change lists redirects for is deleted for good,
 * the change has no blocks: its list is gone.
 */
final class Pairs_Blocks {

	/**
	 * The blocks for a change: a save's, a trash's, or none for a restore.
	 *
	 * @param array<string,mixed> $change A change from Change_Log.
	 * @param bool                $gone   Set to whether a page the change lists redirects for was deleted for good; then there are no blocks.
	 * @return array<int,array<string,mixed>> Blocks.
	 */
	public static function change_blocks( array $change, bool &$gone = false ): array {
		$gone = false;
		switch ( $change['action'] ?? '' ) {
			case 'save':
				return self::save_blocks( $change, $gone );
			case 'trash':
				return self::trash_blocks( $change, $gone );
		}
		return [];
	}

	/**
	 * The blocks for a save: each page it saved, at the path it had right
	 * before the save, leads to the page, and its subtree is walked as it is
	 * now, at current slugs. That is exact while the change is fresh; the
	 * check against the server drops whatever it no longer serves. Once a
	 * page it saved is deleted for good, the list is gone.
	 *
	 * @param array<string,mixed> $change A save change from Change_Log.
	 * @param bool                $gone   Set when a page it saved was deleted for good.
	 * @return array<int,array<string,mixed>> Blocks.
	 */
	private static function save_blocks( array $change, bool &$gone ): array {
		$ids = array_map( 'intval', array_keys( (array) $change['roots'] ) );
		Batch_Resolver::prime_with_ancestors( $ids );

		$blocks = [];
		foreach ( (array) $change['roots'] as $root ) {
			$id   = (int) $root['root'];
			$post = get_post( $id );
			if ( '' === (string) ( $root['path'] ?? '' ) ) {
				continue;
			}
			if ( ! $post instanceof WP_Post ) {
				$gone = true;
				return [];
			}
			$blocks[] = [
				'old'      => Paths::normalize( (string) $root['path'] ),
				'head'     => $id,
				'reason'   => '',
				'base'     => Paths::uri( $post ),
				'from'     => $id,
				'children' => [],
				// The save recorded the old path on the page, so the resolver leads it there.
				'target'   => $id,
			];
		}
		return $blocks;
	}

	/**
	 * The blocks for a trash change, one per root still in the trash. A root
	 * restored since has no block, because its URLs work again. Once a root
	 * whose subpages moved up is deleted for good, the list is gone: its
	 * moved markers, which kept its subpages' slugs from before the move,
	 * went with it. A root deleted for good that moved nothing had nothing to
	 * list. While a root is in the trash its old path leads nowhere and its
	 * first-level pages are the subpages its moved markers name, at their
	 * original slugs, each with its subpages below it at their current slugs.
	 * When a later root of the same bulk action took the page they moved
	 * under to the trash, or it was deleted since, and the subpages moved up
	 * again, they sit under the nearest page above it that is neither: that
	 * is where their new URLs start.
	 *
	 * @param array<string,mixed> $change A trash change from Change_Log.
	 * @param bool                $gone   Set when a root whose subpages moved up was deleted for good.
	 * @return array<int,array<string,mixed>> Blocks.
	 */
	private static function trash_blocks( array $change, bool &$gone ): array {
		$type  = (string) $change['post_type'];
		$all   = [];
		$roots = [];
		foreach ( (array) $change['roots'] as $root ) {
			$all[ (int) $root['root'] ] = $root;
			if ( Settings::POLICY_REPARENT === ( $root['policy'] ?? '' ) && '' !== (string) ( $root['path'] ?? '' ) ) {
				$roots[ (int) $root['root'] ] = $root;
			}
		}
		if ( [] === $roots ) {
			return [];
		}

		// Every root's status, in one query: a root missing here was deleted for good.
		$statuses = self::statuses( array_keys( $all ) );
		foreach ( $roots as $root_id => $root ) {
			if ( ! isset( $statuses[ $root_id ] ) && (int) ( $root['counts']['moved'] ?? 0 ) > 0 ) {
				$gone = true;
				return [];
			}
		}
		$roots = array_filter( $roots, static fn( int $root_id ): bool => 'trash' === ( $statuses[ $root_id ] ?? '' ), ARRAY_FILTER_USE_KEY );
		$moved = self::moved_children( array_keys( $roots ), $type );

		$blocks  = [];
		$targets = [];
		foreach ( $roots as $root_id => $root ) {
			// A root trashed later in the same bulk action may have moved these subpages up again, and either may be deleted since.
			$to       = self::live_target( (int) $root['to'], $all, $statuses, $targets );
			$parent   = $to > 0 ? get_post( $to ) : null;
			$blocks[] = [
				'old'      => Paths::normalize( (string) $root['path'] ),
				'head'     => 0,
				'reason'   => 'trash',
				'base'     => $parent instanceof WP_Post ? Paths::uri( $parent ) : '',
				'from'     => 0,
				// The markers keep each subpage's slug from before the move.
				'children' => $moved[ $root_id ] ?? [],
				'target'   => $to,
			];
		}

		return $blocks;
	}

	/**
	 * Statuses of the given posts, read past the object cache. A missing ID was deleted.
	 *
	 * @param int[] $ids Post IDs.
	 * @return array<int,string> Status by ID.
	 */
	private static function statuses( array $ids ): array {
		global $wpdb;

		$ids          = array_map( 'intval', $ids );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $placeholders holds only %d, one per root of the change; a batch may have moved them since.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_status FROM {$wpdb->posts} WHERE ID IN ( {$placeholders} )", $ids ), ARRAY_A );

		$out = [];
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['ID'] ] = (string) $row['post_status'];
		}
		return $out;
	}

	/**
	 * Subpages the trash moved up, by root, with the slug each had under it.
	 * A subpage in the trash since is left out.
	 *
	 * @param int[]  $root_ids Roots in the trash.
	 * @param string $type     Post type name.
	 * @return array<int,array<int,array{id:int,parent:int,segment:string,slug:string,status:string}>>
	 */
	private static function moved_children( array $root_ids, string $type ): array {
		global $wpdb;

		if ( [] === $root_ids ) {
			return [];
		}
		$keys = [];
		foreach ( $root_ids as $root_id ) {
			$keys[ Markers::moved_key( (int) $root_id ) ] = (int) $root_id;
		}
		$placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Range on the meta_key index, one placeholder per key, bounded by LIMIT.
		$markers = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key IN ( {$placeholders} ) LIMIT %d", ...array_merge( array_keys( $keys ), [ Subtree::count_cap() + 1 ] ) ), ARRAY_A );

		$found = [];
		foreach ( (array) $markers as $marker ) {
			$found[ (int) $marker['post_id'] ][ $keys[ (string) $marker['meta_key'] ] ] = Markers::decode_moved( (string) $marker['meta_value'] );
		}
		if ( [] === $found ) {
			return [];
		}

		$root_ids = array_map( 'intval', $root_ids );
		$root_of  = [];
		$original = [];
		foreach ( $found as $id => $moves ) {
			$root = (int) array_key_first( $moves );
			if ( count( $moves ) > 1 ) {
				// Two roots of one bulk trash moved it: it left the first, whose
				// move took it up to another root and which no other move led to.
				$tos = array_map( static fn( array $moved ): int => (int) $moved['to'], $moves );
				foreach ( $moves as $candidate => $moved ) {
					if ( in_array( (int) $moved['to'], $root_ids, true ) && ! in_array( (int) $candidate, $tos, true ) ) {
						$root = (int) $candidate;
						break;
					}
				}
			}
			$root_of[ $id ]  = $root;
			$original[ $id ] = (string) $moves[ $root ]['original'];
		}

		$out = [];
		foreach ( self::rows( array_keys( $root_of ), $type ) as $row ) {
			$id                       = (int) $row['ID'];
			$slug                     = '' !== $original[ $id ] ? $original[ $id ] : (string) $row['post_name'];
			$out[ $root_of[ $id ] ][] = Pairs::node( $row, $slug );
		}
		return $out;
	}

	/**
	 * The page a root's subpages live under now: the one they moved up to or,
	 * when that page went to the trash since, its nearest ancestor that did
	 * not. When it was deleted and was itself a root of the change, its own
	 * target is followed, since core moves a deleted page's children up to
	 * its parent. A root of the change missing from the statuses was deleted,
	 * so it is followed without a read, and each target is worked out once.
	 *
	 * @param int                            $to       The root's recorded target, 0 for top level.
	 * @param array<int,array<string,mixed>> $all      Every root entry of the change as recorded, by root ID.
	 * @param array<int,string>              $statuses Status of every root of the change still in the posts table, by root ID.
	 * @param array<int,int>                 $memo     Targets worked out so far in this change, by recorded target.
	 * @return int Page ID, 0 for top level; a deleted page the change knows nothing more of stays as it is.
	 */
	private static function live_target( int $to, array $all, array $statuses, array &$memo ): int {
		$start = $to;
		if ( isset( $memo[ $start ] ) ) {
			return $memo[ $start ];
		}
		$seen = [];
		while ( $to > 0 && ! isset( $seen[ $to ] ) ) {
			$seen[ $to ] = true;
			if ( $to !== $start && isset( $memo[ $to ] ) ) {
				$to = $memo[ $to ];
				break;
			}
			if ( isset( $all[ $to ] ) && ! isset( $statuses[ $to ] ) ) {
				$to = (int) $all[ $to ]['to'];
				continue;
			}
			$page = get_post( $to );
			if ( $page instanceof WP_Post ) {
				$to = 'trash' === $page->post_status ? Subtree::nearest_live_ancestor( $page ) : $to;
				break;
			}
			if ( ! isset( $all[ $to ] ) ) {
				break;
			}
			$to = (int) $all[ $to ]['to'];
		}
		$memo[ $start ] = $to;
		return $to;
	}

	/**
	 * Live posts of a type among the given IDs, in menu order then title.
	 *
	 * @param int[]  $ids  Post IDs, at most the count cap plus one.
	 * @param string $type Post type name.
	 * @return array<int,array<string,string>>
	 */
	private static function rows( array $ids, string $type ): array {
		global $wpdb;

		$in = implode( ',', array_map( 'intval', $ids ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in holds integers only, bounded by the cap; a primary-key range.
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_parent, post_name, post_status FROM {$wpdb->posts} WHERE ID IN ( {$in} ) AND post_type = %s AND post_status NOT IN ( 'trash', 'auto-draft', 'inherit' ) ORDER BY menu_order ASC, post_title ASC, ID ASC", $type ), ARRAY_A );
	}
}
