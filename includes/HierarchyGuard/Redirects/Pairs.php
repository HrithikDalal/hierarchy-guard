<?php
/**
 * Old and new URL pairs for the redirects a change needs.
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
 * Lists the old URLs a change left behind and where each leads now.
 *
 * A block starts at one old path. Its head is the post that path leads to,
 * or none. Below it come its first-level pages, each at its old path
 * segment, and the published pages under them, depth first in menu order.
 * Every block of a change is walked together, one query pair per level, and
 * no walk goes past the count cap. Nothing is stored: the collected pairs
 * are cached for a few minutes, keyed on the posts' last change.
 */
final class Pairs {

	public const PER_PAGE  = 20;
	public const CACHE_TTL = 5 * MINUTE_IN_SECONDS;

	/**
	 * Every pair behind an old path recorded on a post: the old path leads to
	 * the post, and each old subpage path to the same subpage today.
	 *
	 * @param WP_Post $post     Post the old path is recorded on.
	 * @param string  $old_path The old path, any form.
	 * @return array{post_type:string,pairs:array<int,array{old:string,id:int}>,unresolved:array<int,array{old:string,reason:string}>,capped:bool}
	 */
	public function collect_post( WP_Post $post, string $old_path ): array {
		$old = Paths::normalize( $old_path );
		return $this->cached(
			'post:' . (int) $post->ID . ':' . md5( $old ),
			fn(): array => $this->collect( [ self::post_block( $post, $old ) ], (string) $post->post_type )
		);
	}

	/**
	 * Every pair of a trash change: one block per root whose subpages moved up.
	 *
	 * @param array<string,mixed> $change A change from Change_Log.
	 * @return array{post_type:string,pairs:array<int,array{old:string,id:int}>,unresolved:array<int,array{old:string,reason:string}>,capped:bool}
	 */
	public function collect_change( array $change ): array {
		return $this->cached(
			'change:' . (string) ( $change['id'] ?? '' ) . ':' . (int) ( $change['updated'] ?? 0 ),
			fn(): array => $this->collect( self::change_blocks( $change ), (string) ( $change['post_type'] ?? 'page' ) )
		);
	}

	/**
	 * The block for an old path recorded on a post. When the page that held
	 * the old path is only in the trash, the path has no new URL: the head is
	 * none and the reason is `trash`, while its subpages still redirect.
	 *
	 * @param WP_Post $post     Post the old path is recorded on.
	 * @param string  $old_path The old path, any form.
	 * @return array<string,mixed> Block.
	 */
	public static function post_block( WP_Post $post, string $old_path ): array {
		$old      = Paths::normalize( $old_path );
		$in_trash = Ledger::held_in_trash( (string) $post->post_type, $old );
		return [
			'old'        => $old,
			'head'       => $in_trash ? 0 : (int) $post->ID,
			'reason'     => $in_trash ? 'trash' : '',
			'base'       => Paths::uri( $post ),
			'from'       => (int) $post->ID,
			'children'   => [],
			'incomplete' => false,
		];
	}

	/**
	 * The blocks for a trash change. A root restored since has no block,
	 * because its URLs work again. While a root is in the trash its old path
	 * leads nowhere and its first-level pages are the moved markers, at their
	 * original slugs. Once it is deleted for good its old path leads to the
	 * page its subpages moved under, and its first-level pages are the IDs its
	 * change entry kept that still live there, at their slugs from before the move.
	 *
	 * @param array<string,mixed> $change A change from Change_Log.
	 * @return array<int,array<string,mixed>> Blocks.
	 */
	public static function change_blocks( array $change ): array {
		if ( 'trash' !== ( $change['action'] ?? '' ) ) {
			return [];
		}

		$type  = (string) $change['post_type'];
		$roots = [];
		foreach ( (array) $change['roots'] as $root ) {
			if ( Settings::POLICY_REPARENT === ( $root['policy'] ?? '' ) && '' !== (string) ( $root['path'] ?? '' ) ) {
				$roots[ (int) $root['root'] ] = $root;
			}
		}
		if ( [] === $roots ) {
			return [];
		}

		$statuses = self::statuses( array_keys( $roots ) );
		$trashed  = array_keys( array_filter( $statuses, static fn( string $status ): bool => 'trash' === $status ) );
		$moved    = self::moved_children( $trashed, $type );
		$kept     = self::kept_children( array_diff_key( $roots, $statuses ), $type );

		$blocks = [];
		foreach ( $roots as $root_id => $root ) {
			$status = $statuses[ $root_id ] ?? '';
			if ( '' !== $status && 'trash' !== $status ) {
				continue;
			}
			$to       = (int) $root['to'];
			$parent   = $to > 0 ? get_post( $to ) : null;
			$blocks[] = [
				'old'        => Paths::normalize( (string) $root['path'] ),
				'head'       => '' === $status ? $to : 0,
				'reason'     => 'trash' === $status ? 'trash' : '',
				'base'       => $parent instanceof WP_Post ? Paths::uri( $parent ) : '',
				'from'       => 0,
				'children'   => 'trash' === $status ? ( $moved[ $root_id ] ?? [] ) : ( $kept[ $root_id ] ?? [] ),
				'incomplete' => '' === $status && (int) ( $root['counts']['moved'] ?? 0 ) > count( (array) ( $root['ids'] ?? [] ) ),
			];
		}

		return $blocks;
	}

	/**
	 * Every pair of the blocks, up to the count cap.
	 *
	 * A block is skipped when its old path has no URL (plain permalinks, or a
	 * type without a permastruct), when it leads where it already is, or when
	 * its pages now sit under the trash. A block whose old path a live page
	 * uses now is unresolved: redirect plugins redirect before WordPress
	 * looks, so listing it would hide that page.
	 *
	 * @param array<int,array<string,mixed>> $blocks    Blocks.
	 * @param string                         $post_type Post type name.
	 * @return array{post_type:string,pairs:array<int,array{old:string,id:int}>,unresolved:array<int,array{old:string,reason:string}>,capped:bool}
	 */
	public function collect( array $blocks, string $post_type ): array {
		$cap        = Subtree::count_cap();
		$unresolved = [];
		$capped     = false;
		$live       = [];

		foreach ( $blocks as $index => $block ) {
			$old  = (string) $block['old'];
			$base = (string) $block['base'];
			if ( '' === $old || '' === Paths::url( $post_type, $old ) || $old === $base || Paths::has_trashed_segment( $base ) ) {
				continue;
			}
			$owner = get_page_by_path( $old, OBJECT, $post_type );
			if ( $owner instanceof WP_Post && (int) $owner->ID !== (int) $block['head'] && is_post_publicly_viewable( $owner ) ) {
				$unresolved[] = [
					'old'    => $old,
					'reason' => 'in_use',
				];
				continue;
			}
			$live[ $index ] = $block;
			$capped         = $capped || ! empty( $block['incomplete'] );
		}

		$tree = $this->walk( $live, $post_type, $cap );

		$pairs = [];
		foreach ( $live as $index => $block ) {
			$old  = (string) $block['old'];
			$head = (int) $block['head'];
			if ( $head > 0 && is_post_publicly_viewable( $head ) ) {
				$pairs[] = [
					'old' => $old,
					'id'  => $head,
				];
			} else {
				$reason       = (string) $block['reason'];
				$unresolved[] = [
					'old'    => $old,
					'reason' => '' !== $reason ? $reason : ( $head > 0 ? 'unpublished' : 'none' ),
				];
			}

			$stack = array_reverse(
				array_map(
					static fn( array $node ): array => [ $node, $old ],
					$tree['tops'][ $index ] ?? []
				)
			);
			while ( [] !== $stack ) {
				if ( count( $pairs ) >= $cap ) {
					$capped = true;
					break 2;
				}
				[ $node, $path_base ] = array_pop( $stack );
				// Core skips an empty slug when it builds a path, so the old path does too.
				$path = '' !== $node['segment'] ? $path_base . '/' . $node['segment'] : $path_base;
				if ( 'publish' === $node['status'] ) {
					$pairs[] = [
						'old' => $path,
						'id'  => $node['id'],
					];
				}
				foreach ( array_reverse( $tree['kids'][ $node['id'] ] ?? [] ) as $kid ) {
					$stack[] = [ $kid, $path ];
				}
			}
		}

		return [
			'post_type'  => $post_type,
			'pairs'      => $pairs,
			'unresolved' => $unresolved,
			'capped'     => $capped || $tree['capped'],
		];
	}

	/**
	 * One page of pairs as URLs relative to the home URL.
	 *
	 * @param array<string,mixed> $collected From collect_post() or collect_change().
	 * @param int                 $page      Page number, from 1.
	 * @param int                 $per_page  Pairs per page.
	 * @return array{pairs:array<int,array{old:string,new:string}>,unresolved:array<int,array{old:string,reason:string}>,total:int,capped:bool,page:int,pages:int}
	 */
	public function page( array $collected, int $page, int $per_page ): array {
		$type     = (string) $collected['post_type'];
		$per_page = max( 1, $per_page );
		$total    = count( $collected['pairs'] );
		$pages    = max( 1, (int) ceil( $total / $per_page ) );
		$page     = min( max( 1, $page ), $pages );

		return [
			'pairs'      => $this->urls( array_slice( $collected['pairs'], ( $page - 1 ) * $per_page, $per_page ), $type ),
			'unresolved' => array_map(
				static fn( array $row ): array => [
					'old'    => Paths::relative( Paths::url( $type, $row['old'] ) ),
					'reason' => $row['reason'],
				],
				$collected['unresolved']
			),
			'total'      => $total,
			'capped'     => (bool) $collected['capped'],
			'page'       => $page,
			'pages'      => $pages,
		];
	}

	/**
	 * Every pair as URLs relative to the home URL, for a download.
	 *
	 * @param array<string,mixed> $collected From collect_post() or collect_change().
	 * @return array<int,array{old:string,new:string}>
	 */
	public function all( array $collected ): array {
		$out = [];
		foreach ( array_chunk( $collected['pairs'], 100 ) as $chunk ) {
			foreach ( $this->urls( $chunk, (string) $collected['post_type'] ) as $row ) {
				$out[] = $row;
			}
		}
		return $out;
	}

	/**
	 * The number of pairs, stopping at the cap.
	 *
	 * @param array<string,mixed> $collected From collect_post() or collect_change().
	 * @return array{count:int,capped:bool}
	 */
	public function count( array $collected ): array {
		return [
			'count'  => count( $collected['pairs'] ),
			'capped' => (bool) $collected['capped'],
		];
	}

	/**
	 * Reads the collected pairs from the cache, or builds and caches them.
	 *
	 * @param string   $key   Source key.
	 * @param callable $build Builds the collected pairs.
	 * @return array<string,mixed>
	 */
	private function cached( string $key, callable $build ): array {
		$key = 'pairs:' . $key . ':' . wp_cache_get_last_changed( 'posts' );
		$hit = wp_cache_get( $key, Ledger::CACHE_GROUP );
		if ( is_array( $hit ) ) {
			return $hit;
		}
		$value = $build();
		// phpcs:ignore WordPressVIPMinimum.Performance.LowExpiryCacheTime.CacheTimeUndetermined -- CACHE_TTL is 5 minutes.
		wp_cache_set( $key, $value, Ledger::CACHE_GROUP, self::CACHE_TTL );
		return $value;
	}

	/**
	 * Every block's pages, level by level for all blocks at once, within the budget.
	 *
	 * @param array<int,array<string,mixed>> $blocks Blocks to walk, by index.
	 * @param string                         $type   Post type name.
	 * @param int                            $cap    Most pages to read.
	 * @return array{tops:array<int,array<int,array<string,mixed>>>,kids:array<int,array<int,array<string,mixed>>>,capped:bool}
	 */
	private function walk( array $blocks, string $type, int $cap ): array {
		$tops    = [];
		$froms   = [];
		$visited = 0;
		foreach ( $blocks as $index => $block ) {
			foreach ( (array) $block['children'] as $node ) {
				$tops[ $index ][] = $node;
				++$visited;
			}
			if ( (int) $block['from'] > 0 ) {
				$froms[ (int) $block['from'] ] = $index;
			}
		}
		if ( [] !== $froms && $visited <= $cap ) {
			$rows = Subtree::ordered_children( array_keys( $froms ), $type, $cap + 1 - $visited );
			foreach ( $rows as $row ) {
				$tops[ $froms[ (int) $row['post_parent'] ] ][] = self::node( $row, (string) $row['post_name'] );
			}
			$visited += count( $rows );
		}

		$kids  = [];
		$level = [];
		foreach ( $tops as $nodes ) {
			foreach ( $nodes as $node ) {
				$level[] = (int) $node['id'];
			}
		}
		$depth = 0;
		while ( [] !== $level && $visited <= $cap && $depth < 50 ) {
			++$depth;
			$rows  = Subtree::ordered_children( $level, $type, $cap + 1 - $visited );
			$level = [];
			foreach ( $rows as $row ) {
				$node                      = self::node( $row, (string) $row['post_name'] );
				$kids[ $node['parent'] ][] = $node;
				$level[]                   = $node['id'];
			}
			$visited += count( $rows );
		}

		return [
			'tops'   => $tops,
			'kids'   => $kids,
			'capped' => $visited > $cap,
		];
	}

	/**
	 * Old and new URLs for pairs, priming the posts first.
	 *
	 * @param array<int,array{old:string,id:int}> $pairs Pairs.
	 * @param string                              $type  Post type name.
	 * @return array<int,array{old:string,new:string}>
	 */
	private function urls( array $pairs, string $type ): array {
		_prime_post_caches( array_map( static fn( array $pair ): int => $pair['id'], $pairs ), false, false );

		$out = [];
		foreach ( $pairs as $pair ) {
			$old = Paths::url( $type, $pair['old'] );
			$new = get_permalink( $pair['id'] );
			if ( '' === $old || ! is_string( $new ) || '' === $new ) {
				continue;
			}
			$out[] = [
				'old' => Paths::relative( $old ),
				'new' => Paths::relative( $new ),
			];
		}
		return $out;
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
	 * @param int[]  $root_ids Roots in the trash.
	 * @param string $type     Post type name.
	 * @return array<int,array<int,array{id:int,parent:int,segment:string,status:string}>>
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

		$root_of  = [];
		$original = [];
		foreach ( (array) $markers as $marker ) {
			$id              = (int) $marker['post_id'];
			$root_of[ $id ]  = $keys[ (string) $marker['meta_key'] ];
			$original[ $id ] = Markers::decode_moved( (string) $marker['meta_value'] )['original'];
		}
		if ( [] === $root_of ) {
			return [];
		}

		$out = [];
		foreach ( self::rows( array_keys( $root_of ), $type ) as $row ) {
			$id                       = (int) $row['ID'];
			$slug                     = '' !== $original[ $id ] ? $original[ $id ] : (string) $row['post_name'];
			$out[ $root_of[ $id ] ][] = self::node( $row, $slug );
		}
		return $out;
	}

	/**
	 * The subpages each deleted root's entry kept, by root, where they still live under its target.
	 *
	 * @param array<int,array<string,mixed>> $roots Root entries of deleted roots, by root ID.
	 * @param string                         $type  Post type name.
	 * @return array<int,array<int,array{id:int,parent:int,segment:string,status:string}>>
	 */
	private static function kept_children( array $roots, string $type ): array {
		$owner = [];
		foreach ( $roots as $root_id => $root ) {
			foreach ( (array) ( $root['ids'] ?? [] ) as $id ) {
				$owner[ (int) $id ] = (int) $root_id;
			}
		}
		if ( [] === $owner ) {
			return [];
		}

		$out = [];
		foreach ( self::rows( array_keys( $owner ), $type ) as $row ) {
			$root_id = $owner[ (int) $row['ID'] ];
			if ( (int) $row['post_parent'] === (int) $roots[ $root_id ]['to'] ) {
				$slug              = $roots[ $root_id ]['slugs'][ (int) $row['ID'] ] ?? '';
				$out[ $root_id ][] = self::node( $row, is_string( $slug ) && '' !== $slug ? $slug : (string) $row['post_name'] );
			}
		}
		return $out;
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

	/**
	 * A tree node from a posts row.
	 *
	 * @param array<string,mixed> $row     Row with ID, post_parent, post_name, post_status.
	 * @param string              $segment The node's segment in the old path.
	 * @return array{id:int,parent:int,segment:string,status:string}
	 */
	private static function node( array $row, string $segment ): array {
		return [
			'id'      => (int) $row['ID'],
			'parent'  => (int) $row['post_parent'],
			'segment' => Paths::normalize( $segment ),
			'status'  => (string) $row['post_status'],
		];
	}
}
