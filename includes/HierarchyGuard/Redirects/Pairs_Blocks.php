<?php
/**
 * Where the blocks of a list of pairs start.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Redirects;

use HierarchyGuard\Ledger;
use HierarchyGuard\Paths;
use HierarchyGuard\Settings;
use HierarchyGuard\Subtree;
use HierarchyGuard\Trash\Markers;
use WP_Post;

/**
 * The block sources: one block for an old path recorded on a post, one per
 * root of a trash change. A block names its old path, its head, the page its
 * new URLs start under, the first-level pages its walk starts from, and the
 * pages in the trash that moved them up.
 */
final class Pairs_Blocks {

	/**
	 * The block for an old path recorded on a post. When a page in the trash
	 * holds the old path, the path has no new URL: the head is none and the
	 * reason is `trash`, while its subpages still redirect. The subpages that
	 * pages in the trash moved up to this post are first-level pages, at their
	 * slugs from before the move, and so are those that subpages of theirs in
	 * the trash since moved up here. The post's own subpages are too, unless
	 * one of those pages wrote this old path on the post as its alias: then the
	 * post never lived there.
	 *
	 * @param WP_Post $post     Post the old path is recorded on.
	 * @param string  $old_path The old path, any form.
	 * @return array<string,mixed> Block.
	 */
	public static function post_block( WP_Post $post, string $old_path ): array {
		$old                 = Paths::normalize( $old_path );
		$type                = (string) $post->post_type;
		$id                  = (int) $post->ID;
		$holders             = Ledger::trash_holders( $type, $old );
		[ $moved, $trashed ] = self::moved_tree( $holders, $type, $id );
		$children            = [];
		$virtual             = $holders;
		foreach ( $holders as $holder ) {
			$children = array_merge( $children, $moved[ $holder ] ?? [] );
			$virtual  = array_merge( $virtual, $trashed[ $holder ] ?? [] );
		}
		$in_trash = [] !== $holders;

		return [
			'old'         => $old,
			'head'        => $in_trash ? 0 : $id,
			'reason'      => $in_trash ? 'trash' : '',
			'base'        => Paths::uri( $post ),
			'from'        => $in_trash && self::wrote_alias( $holders, $id, $old ) ? 0 : $id,
			'children'    => $children,
			// The subpages moved up here are this post's children now, and are walked once.
			'skip'        => array_column( $children, 'id' ),
			// Pages in the trash whose trash moved those subpages up here, where they lived before.
			'virtual'     => array_values( array_unique( array_map( 'intval', $virtual ) ) ),
			'incomplete'  => false,
			'recorded_on' => $id,
			'rebuild'     => true,
			'target'      => $id,
		];
	}

	/**
	 * Whether one of the pages in the trash wrote this old path on the post
	 * as its alias, which the trash flow remembers on that page.
	 *
	 * @param int[]  $holders Pages in the trash that hold the path.
	 * @param int    $post_id The post.
	 * @param string $path    Normalized old path.
	 * @return bool
	 */
	private static function wrote_alias( array $holders, int $post_id, string $path ): bool {
		update_meta_cache( 'post', $holders );
		foreach ( $holders as $holder ) {
			$aliases = get_post_meta( (int) $holder, Markers::ALIASES, true );
			foreach ( is_array( $aliases ) ? $aliases : [] as $alias ) {
				$to  = (int) ( $alias['to'] ?? 0 );
				$was = Paths::normalize( (string) ( $alias['path'] ?? '' ) );
				if ( $to === $post_id && $was === $path ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * The blocks for a trash change. A root restored since has no block,
	 * because its URLs work again. While a root is in the trash its old path
	 * leads nowhere and its first-level pages are the moved markers, at their
	 * original slugs, each listed with its subpages at the paths they had until
	 * the trash. Once it is deleted for good its old path leads to the
	 * page its subpages moved under, and its first-level pages are the IDs its
	 * change entry kept that still live there, at their slugs from before the move.
	 * When a later root of the same bulk action took that page to the trash, or
	 * it was deleted since, and the subpages moved up again, they sit under the
	 * nearest page above it that is neither: that is where their new URLs
	 * start. The old path itself still leads to the page it was recorded on, as
	 * the resolver sees it, so a deleted root's old path leads anywhere only
	 * while a live page holds it.
	 *
	 * @param array<string,mixed> $change A change from Change_Log.
	 * @return array<int,array<string,mixed>> Blocks.
	 */
	public static function change_blocks( array $change ): array {
		if ( 'trash' !== ( $change['action'] ?? '' ) ) {
			return [];
		}

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
		$statuses           = self::statuses( array_keys( $all ) );
		$trashed            = array_keys( array_filter( array_intersect_key( $statuses, $roots ), static fn( string $status ): bool => 'trash' === $status ) );
		[ $moved, $nested ] = self::moved_tree( $trashed, $type, null );

		$parents = [];
		$under   = [];
		$targets = [];
		foreach ( $roots as $root_id => $root ) {
			$status = $statuses[ $root_id ] ?? '';
			if ( '' !== $status && 'trash' !== $status ) {
				unset( $roots[ $root_id ] );
				continue;
			}
			// A root trashed later in the same bulk action may have moved these subpages up again, and either may be deleted since.
			$to                  = self::live_target( (int) $root['to'], $all, $statuses, $targets );
			$parent              = $to > 0 ? get_post( $to ) : null;
			$under[ $root_id ]   = array_merge( $root, [ 'to' => $to ] );
			$parents[ $root_id ] = $parent instanceof WP_Post ? $parent : null;
		}
		$kept = self::kept_children( array_diff_key( $under, $statuses ), $all, $type );

		$blocks = [];
		foreach ( $roots as $root_id => $root ) {
			$status   = $statuses[ $root_id ] ?? '';
			$parent   = $parents[ $root_id ];
			$to       = (int) $root['to'];
			$trash    = 'trash' === $status;
			$blocks[] = [
				'old'         => Paths::normalize( (string) $root['path'] ),
				// A target that was itself a root of the change and is gone now is no page: nothing needs reading.
				'head'        => '' === $status && ! ( isset( $all[ $to ] ) && ! isset( $statuses[ $to ] ) ) ? $to : 0,
				'reason'      => $trash ? 'trash' : '',
				'base'        => null !== $parent ? Paths::uri( $parent ) : '',
				'from'        => 0,
				'children'    => $trash ? ( $moved[ $root_id ] ?? [] ) : ( $kept[ $root_id ] ?? [] ),
				'incomplete'  => '' === $status && (int) ( $root['counts']['moved'] ?? 0 ) > count( (array) ( $root['ids'] ?? [] ) ),
				'recorded_on' => 0,
				// While the root is in the trash its markers date the move, so each subpage is listed at the URL it had until the trash.
				// Once it is deleted they are gone, and each keeps the slug it had before the move, with nothing rebased.
				'rebuild'     => $trash,
				'post'        => null !== $parent ? (int) $parent->ID : 0,
				'virtual'     => $trash ? array_merge( [ (int) $root_id ], $nested[ $root_id ] ?? [] ) : [],
				'target'      => (int) $under[ $root_id ]['to'],
				// A deleted root's old path leads to its target only through a live page that holds it.
				'held'        => '' === $status,
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
	 *
	 * @param int[]    $root_ids   Roots in the trash.
	 * @param string   $type       Post type name.
	 * @param int|null $to         Only moves up to this page, or null for every move.
	 * @param bool     $with_trash Whether subpages in the trash since are included.
	 * @return array<int,array<int,array{id:int,parent:int,segment:string,slug:string,status:string}>>
	 */
	private static function moved_children( array $root_ids, string $type, ?int $to = null, bool $with_trash = false ): array {
		global $wpdb;

		if ( [] === $root_ids ) {
			return [];
		}
		$keys = [];
		foreach ( $root_ids as $root_id ) {
			$keys[ Markers::moved_key( (int) $root_id ) ] = (int) $root_id;
		}
		$placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		// A marker's value starts with the page its subpage moved up to, so the moves up to one page are filtered before the LIMIT.
		$args  = array_keys( $keys );
		$where = '';
		if ( null !== $to ) {
			$where  = ' AND meta_value LIKE %s';
			$args[] = $wpdb->esc_like( $to . '|' ) . '%';
		}
		$args[] = Subtree::count_cap() + 1;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Range on the meta_key index, one placeholder per key, the value matched on the rows of those keys only, bounded by LIMIT.
		$markers = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key IN ( {$placeholders} ){$where} LIMIT %d", ...$args ), ARRAY_A );

		$found = [];
		foreach ( (array) $markers as $marker ) {
			$moved = Markers::decode_moved( (string) $marker['meta_value'] );
			if ( null === $to || $to === $moved['to'] ) {
				$found[ (int) $marker['post_id'] ][ $keys[ (string) $marker['meta_key'] ] ] = $moved;
			}
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
		foreach ( self::rows( array_keys( $root_of ), $type, $with_trash ) as $row ) {
			$id                       = (int) $row['ID'];
			$slug                     = '' !== $original[ $id ] ? $original[ $id ] : (string) $row['post_name'];
			$out[ $root_of[ $id ] ][] = Pairs::node( $row, $slug );
		}
		return $out;
	}

	/**
	 * The subpages pages in the trash moved up, and those that subpages of
	 * theirs in the trash since moved up in turn, each with the page in the
	 * trash it first came from as its `holder`.
	 *
	 * @param int[]    $root_ids Pages in the trash.
	 * @param string   $type     Post type name.
	 * @param int|null $to       Only their moves up to this page, or null for every move.
	 * @return array{0:array<int,array<int,array<string,mixed>>>,1:array<int,int[]>} The live subpages by root, and the subpages in the trash since by root.
	 */
	private static function moved_tree( array $root_ids, string $type, ?int $to ): array {
		$live  = [];
		$trash = [];
		$roots = array_fill_keys( array_map( 'intval', $root_ids ), 0 );
		for ( $depth = 0; [] !== $roots && $depth < 5; $depth++ ) {
			$moved = self::moved_children( array_keys( $roots ), $type, 0 === $depth ? $to : null, true );
			$next  = [];
			foreach ( $roots as $root => $holder ) {
				$holder = $holder > 0 ? $holder : (int) $root;
				foreach ( $moved[ $root ] ?? [] as $node ) {
					if ( 'trash' === $node['status'] ) {
						$next[ $node['id'] ] = $holder;
						$trash[ $holder ][]  = $node['id'];
					} else {
						$live[ $holder ][] = $node + [ 'holder' => $holder ];
					}
				}
			}
			$roots = $next;
		}
		return [ $live, $trash ];
	}

	/**
	 * The subpages each deleted root's entry kept, by root, where they still live under its target.
	 *
	 * In a chained bulk trash every root a subpage moved up from keeps it. It
	 * belongs to the root it left first: the one whose move took it up to
	 * another root of the change and that no other of those moves led to.
	 *
	 * @param array<int,array<string,mixed>> $roots Root entries of deleted roots, by root ID, each `to` the page its subpages live under now.
	 * @param array<int,array<string,mixed>> $all   Every root entry of the change as recorded, by root ID.
	 * @param string                         $type  Post type name.
	 * @return array<int,array<int,array{id:int,parent:int,segment:string,slug:string,status:string}>>
	 */
	private static function kept_children( array $roots, array $all, string $type ): array {
		$listed = [];
		foreach ( $roots as $root_id => $root ) {
			foreach ( (array) ( $root['ids'] ?? [] ) as $id ) {
				$listed[ (int) $id ][] = (int) $root_id;
			}
		}
		if ( [] === $listed ) {
			return [];
		}

		$owner = [];
		foreach ( $listed as $id => $candidates ) {
			$owner[ $id ] = (int) end( $candidates );
			if ( count( $candidates ) < 2 ) {
				continue;
			}
			$tos = array_map( static fn( int $candidate ): int => (int) ( $all[ $candidate ]['to'] ?? 0 ), $candidates );
			foreach ( $candidates as $candidate ) {
				if ( isset( $all[ (int) ( $all[ $candidate ]['to'] ?? 0 ) ] ) && ! in_array( $candidate, $tos, true ) ) {
					$owner[ $id ] = $candidate;
					break;
				}
			}
		}
		// The walk reads no more than the cap, whatever an older change stored.
		$owner = array_slice( $owner, 0, Subtree::count_cap() + 1, true );

		$out = [];
		foreach ( self::rows( array_keys( $owner ), $type ) as $row ) {
			$root_id = $owner[ (int) $row['ID'] ];
			if ( (int) $row['post_parent'] === (int) $roots[ $root_id ]['to'] ) {
				$slug              = $roots[ $root_id ]['slugs'][ (int) $row['ID'] ] ?? '';
				$out[ $root_id ][] = Pairs::node( $row, is_string( $slug ) && '' !== $slug ? $slug : (string) $row['post_name'] );
			}
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
	 * @param int[]  $ids        Post IDs, at most the count cap plus one.
	 * @param string $type       Post type name.
	 * @param bool   $with_trash Whether posts in the trash are included too.
	 * @return array<int,array<string,string>>
	 */
	private static function rows( array $ids, string $type, bool $with_trash = false ): array {
		global $wpdb;

		$in       = implode( ',', array_map( 'intval', $ids ) );
		$statuses = $with_trash ? "'auto-draft', 'inherit'" : "'trash', 'auto-draft', 'inherit'";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in holds integers only, bounded by the cap, and $statuses is one of two fixed lists; a primary-key range.
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_parent, post_name, post_status FROM {$wpdb->posts} WHERE ID IN ( {$in} ) AND post_type = %s AND post_status NOT IN ( {$statuses} ) ORDER BY menu_order ASC, post_title ASC, ID ASC", $type ), ARRAY_A );
	}
}
