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
	 * the post, and each old subpage path, at the slug the subpage had under
	 * it, to the same subpage today.
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
	 * none and the reason is `trash`, while its subpages still redirect. Its
	 * first-level pages are then the subpages the trash moved off that page,
	 * at their slugs from before the move, not the post's own children.
	 *
	 * @param WP_Post $post     Post the old path is recorded on.
	 * @param string  $old_path The old path, any form.
	 * @return array<string,mixed> Block.
	 */
	public static function post_block( WP_Post $post, string $old_path ): array {
		$old    = Paths::normalize( $old_path );
		$type   = (string) $post->post_type;
		$holder = Ledger::trash_holder( $type, $old );
		if ( $holder > 0 ) {
			return [
				'old'        => $old,
				'head'       => 0,
				'reason'     => 'trash',
				'base'       => Paths::uri( $post ),
				'from'       => 0,
				'children'   => self::moved_children( [ $holder ], $type )[ $holder ] ?? [],
				'incomplete' => false,
			];
		}
		return [
			'old'        => $old,
			'head'       => (int) $post->ID,
			'reason'     => '',
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
	 * When a later root of the same bulk action took that page to the trash and
	 * moved the subpages up again, they sit under its nearest ancestor not in
	 * the trash: that is where their new URLs start. The old path itself still
	 * leads to the page in the trash, as the resolver sees it.
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

		$parents = [];
		$under   = [];
		foreach ( $roots as $root_id => $root ) {
			$status = $statuses[ $root_id ] ?? '';
			if ( '' !== $status && 'trash' !== $status ) {
				unset( $roots[ $root_id ] );
				continue;
			}
			$to     = (int) $root['to'];
			$parent = $to > 0 ? get_post( $to ) : null;
			if ( $parent instanceof WP_Post && 'trash' === $parent->post_status ) {
				// A root trashed later in the same bulk action moved these subpages up again.
				$to     = Subtree::nearest_live_ancestor( $parent );
				$parent = $to > 0 ? get_post( $to ) : null;
			}
			$under[ $root_id ]   = array_merge( $root, [ 'to' => $to ] );
			$parents[ $root_id ] = $parent instanceof WP_Post ? $parent : null;
		}
		$kept = self::kept_children( array_diff_key( $under, $statuses ), $type );

		$blocks = [];
		foreach ( $roots as $root_id => $root ) {
			$status   = $statuses[ $root_id ] ?? '';
			$parent   = $parents[ $root_id ];
			$blocks[] = [
				'old'        => Paths::normalize( (string) $root['path'] ),
				'head'       => '' === $status ? (int) $root['to'] : 0,
				'reason'     => 'trash' === $status ? 'trash' : '',
				'base'       => null !== $parent ? Paths::uri( $parent ) : '',
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
	 * looks, so listing it would hide that page. When the page there now is
	 * hidden, an old subpage path one of its published subpages uses is
	 * dropped for the same reason.
	 *
	 * A page renamed under its parent is listed at the slug it had there, read
	 * from its own old paths. An old path the ledger gives to another live post
	 * leads to that post, so it is dropped here with the pages walked below
	 * it: that post's own list has them.
	 *
	 * @param array<int,array<string,mixed>> $blocks    Blocks.
	 * @param string                         $post_type Post type name.
	 * @return array{post_type:string,pairs:array<int,array{old:string,id:int}>,unresolved:array<int,array{old:string,reason:string}>,capped:bool}
	 */
	public function collect( array $blocks, string $post_type ): array {
		$cap        = Subtree::count_cap();
		$unresolved = [];
		$capped     = false;
		$open       = [];
		$live       = [];
		$hidden     = [];

		foreach ( $blocks as $index => $block ) {
			$old  = (string) $block['old'];
			$base = (string) $block['base'];
			if ( '' === $old || '' === Paths::url( $post_type, $old ) || $old === $base || Paths::has_trashed_segment( $base ) ) {
				continue;
			}
			$open[ $index ] = $block;
		}

		$owners = self::owners( array_map( static fn( array $block ): string => (string) $block['old'], $open ), $post_type );
		foreach ( $open as $index => $block ) {
			$old   = (string) $block['old'];
			$owner = $owners[ $old ] ?? 0;
			if ( $owner > 0 && $owner !== (int) $block['head'] ) {
				if ( is_post_publicly_viewable( $owner ) ) {
					$unresolved[] = [
						'old'    => $old,
						'reason' => 'in_use',
					];
					continue;
				}
				// The page there now is hidden, but a published subpage of it may use an old subpage path.
				$hidden[ $index ] = true;
			}
			$live[ $index ] = $block;
			$capped         = $capped || ! empty( $block['incomplete'] );
		}

		$tree   = $this->walk( $live, $post_type, $cap );
		$former = self::ledger_paths( $tree['ids'] );

		$pairs    = [];
		$lineage  = [];
		$block_of = [];
		foreach ( $live as $index => $block ) {
			$old  = (string) $block['old'];
			$head = (int) $block['head'];
			$post = $head > 0 ? get_post( $head ) : null;
			$top  = [];
			if ( $post instanceof WP_Post && is_post_publicly_viewable( $post ) ) {
				$top                         = [ count( $pairs ) ];
				$block_of[ count( $pairs ) ] = $index;
				$pairs[]                     = [
					'old' => $old,
					'id'  => $head,
				];
			} else {
				$reason       = (string) $block['reason'];
				$unresolved[] = [
					'old'    => $old,
					// A target deleted since is no page at all, not an unpublished one.
					'reason' => '' !== $reason ? $reason : ( $post instanceof WP_Post ? 'unpublished' : 'none' ),
				];
			}

			// Each entry: the node, its parent's old and current paths, and the pairs above it.
			$stack = array_reverse(
				array_map(
					static fn( array $node ): array => [ $node, $old, (string) $block['base'], $top ],
					$tree['tops'][ $index ] ?? []
				)
			);
			while ( [] !== $stack ) {
				if ( count( $pairs ) >= $cap ) {
					$capped = true;
					break 2;
				}
				[ $node, $old_parent, $current_parent, $above ] = array_pop( $stack );

				// Core skips an empty slug when it builds a path, so the old path does too.
				$segment = self::old_segment( $node, $former[ $node['id'] ] ?? [], $old_parent, $current_parent );
				$path    = '' !== $segment ? $old_parent . '/' . $segment : $old_parent;
				$current = '' !== $node['slug'] ? ltrim( $current_parent . '/' . $node['slug'], '/' ) : $current_parent;
				if ( 'publish' === $node['status'] ) {
					$lineage[ count( $pairs ) ]  = $above;
					$block_of[ count( $pairs ) ] = $index;
					$above[]                     = count( $pairs );
					$pairs[]                     = [
						'old' => $path,
						'id'  => $node['id'],
					];
				}
				foreach ( array_reverse( $tree['kids'][ $node['id'] ] ?? [] ) as $kid ) {
					$stack[] = [ $kid, $path, $current, $above ];
				}
			}
		}

		// An old path another live post holds leads to that post, and its own
		// panel lists what sits under it: drop it here, with the pages walked below it.
		$holders = self::holders( array_column( $pairs, 'old' ), $post_type );
		$dropped = [];
		foreach ( $pairs as $i => $pair ) {
			$holder = $holders[ $pair['old'] ] ?? 0;
			if ( ( $holder > 0 && $holder !== $pair['id'] ) || [] !== array_intersect_key( array_flip( $lineage[ $i ] ?? [] ), $dropped ) ) {
				$dropped[ $i ] = true;
			}
		}
		// Under a page that is there but hidden, a published subpage of it can use an old subpage path.
		foreach ( $pairs as $i => $pair ) {
			if ( isset( $dropped[ $i ] ) || ! isset( $hidden[ $block_of[ $i ] ?? -1 ] ) ) {
				continue;
			}
			$there = get_page_by_path( $pair['old'], OBJECT, $post_type );
			if ( $there instanceof WP_Post && (int) $there->ID !== $pair['id'] && is_post_publicly_viewable( $there ) ) {
				$dropped[ $i ] = true;
			}
		}
		$pairs = array_values( array_diff_key( $pairs, $dropped ) );

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
		// The pairs depend on the permalink structure, which a change of it does not bump.
		$key = 'pairs:' . $key . ':' . md5( (string) get_option( 'permalink_structure' ) ) . ':' . wp_cache_get_last_changed( 'posts' );
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
	 * @return array{tops:array<int,array<int,array<string,mixed>>>,kids:array<int,array<int,array<string,mixed>>>,ids:int[],capped:bool}
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

		$ids = [];
		foreach ( array_merge( array_merge( [], ...array_values( $tops ) ), array_merge( [], ...array_values( $kids ) ) ) as $node ) {
			$ids[] = (int) $node['id'];
		}

		return [
			'tops'   => $tops,
			'kids'   => $kids,
			'ids'    => $ids,
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
			$out[ $root_of[ $id ] ][] = self::node( $row, $slug );
		}
		return $out;
	}

	/**
	 * The subpages each deleted root's entry kept, by root, where they still live under its target.
	 *
	 * @param array<int,array<string,mixed>> $roots Root entries of deleted roots, by root ID.
	 * @param string                         $type  Post type name.
	 * @return array<int,array<int,array{id:int,parent:int,segment:string,slug:string,status:string}>>
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
		// The walk reads no more than the cap, whatever an older change stored.
		$owner = array_slice( $owner, 0, Subtree::count_cap() + 1, true );

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
	 * @return array{id:int,parent:int,segment:string,slug:string,status:string}
	 */
	private static function node( array $row, string $segment ): array {
		return [
			'id'      => (int) $row['ID'],
			'parent'  => (int) $row['post_parent'],
			'segment' => Paths::normalize( $segment ),
			'slug'    => Paths::normalize( (string) $row['post_name'] ),
			'status'  => (string) $row['post_status'],
		];
	}

	/**
	 * The segment a page had under its parent's old path. A page renamed while
	 * under its parent, before or after the parent moved, holds an old path one
	 * segment below the parent's old or current path: that segment, newest
	 * first. Otherwise the node's own segment.
	 *
	 * @param array<string,mixed> $node           Tree node.
	 * @param string[]            $paths          The page's old paths, newest first.
	 * @param string              $old_parent     The parent's old path.
	 * @param string              $current_parent The parent's current path, empty at top level.
	 * @return string
	 */
	private static function old_segment( array $node, array $paths, string $old_parent, string $current_parent ): string {
		foreach ( $paths as $path ) {
			foreach ( [ $old_parent, $current_parent ] as $parent ) {
				if ( '' === $parent ) {
					$rest = $path;
				} elseif ( str_starts_with( $path, $parent . '/' ) ) {
					$rest = substr( $path, strlen( $parent ) + 1 );
				} else {
					continue;
				}
				if ( '' !== $rest && ! str_contains( $rest, '/' ) ) {
					return $rest;
				}
			}
		}
		return (string) $node['segment'];
	}

	/**
	 * The post at each path now, the way get_page_by_path() finds it, for every
	 * path in one query: rows whose slug is one of the path's segments, matched
	 * from the last segment up through their parents to the top level, a post
	 * of the type winning over an attachment. The posts found are primed.
	 *
	 * @param string[] $paths     Normalized paths.
	 * @param string   $post_type Post type name.
	 * @return array<string,int> Post ID by path; a path no post uses now is absent.
	 */
	private static function owners( array $paths, string $post_type ): array {
		global $wpdb;

		$parts = [];
		$names = [];
		foreach ( array_unique( array_map( 'strval', $paths ) ) as $path ) {
			$parts[ $path ] = explode( '/', $path );
			foreach ( $parts[ $path ] as $name ) {
				$names[ $name ] = true;
			}
		}
		if ( [] === $names ) {
			return [];
		}

		$names        = array_map( 'strval', array_keys( $names ) );
		$placeholders = implode( ',', array_fill( 0, count( $names ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $placeholders holds only %s, one per segment of the blocks' old paths; core's get_page_by_path() query for every block at once, on the post_name index.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_name, post_parent, post_type FROM {$wpdb->posts} WHERE post_name IN ( {$placeholders} ) AND post_type IN ( %s, 'attachment' )", ...array_merge( $names, [ $post_type ] ) ), OBJECT_K );

		$out = [];
		foreach ( $parts as $path => $segments ) {
			$pages    = array_filter( (array) $rows, static fn( object $row ): bool => in_array( (string) $row->post_name, $segments, true ) );
			$reversed = array_reverse( $segments );
			$found    = 0;
			foreach ( $pages as $page ) {
				if ( (string) $page->post_name !== $reversed[0] ) {
					continue;
				}
				$count = 0;
				$up    = $page;
				while ( 0 !== (int) $up->post_parent && isset( $pages[ (int) $up->post_parent ] ) ) {
					++$count;
					$parent = $pages[ (int) $up->post_parent ];
					if ( ! isset( $reversed[ $count ] ) || (string) $parent->post_name !== $reversed[ $count ] ) {
						break;
					}
					$up = $parent;
				}
				if ( 0 === (int) $up->post_parent && count( $reversed ) === $count + 1 && (string) $up->post_name === $reversed[ $count ] ) {
					$found = (int) $page->ID;
					if ( $post_type === (string) $page->post_type ) {
						break;
					}
				}
			}
			if ( $found > 0 ) {
				$out[ (string) $path ] = $found;
			}
		}

		if ( [] !== $out ) {
			_prime_post_caches( array_values( $out ), false, false );
		}
		return $out;
	}

	/**
	 * The old paths recorded on the given pages, newest first, by page.
	 *
	 * @param int[] $ids Page IDs the walk read, at most the count cap plus one.
	 * @return array<int,string[]>
	 */
	private static function ledger_paths( array $ids ): array {
		global $wpdb;

		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		if ( [] === $ids ) {
			return [];
		}
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $placeholders holds only %d, one per page the walk read; a range on the post_id index, bounded by the count cap.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key LIKE %s AND post_id IN ( {$placeholders} ) ORDER BY meta_id DESC", ...array_merge( [ $wpdb->esc_like( Ledger::KEY_PREFIX ) . '%' ], $ids ) ), ARRAY_A );

		$out = [];
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['post_id'] ][] = (string) $row['meta_value'];
		}
		return $out;
	}

	/**
	 * The live post each old path leads to in the ledger, the one the resolver
	 * picks: the newest entry among posts not in the trash.
	 *
	 * @param string[] $paths     Normalized old paths, at most the count cap.
	 * @param string   $post_type Post type name.
	 * @return array<string,int> Holder by path; a path no live post holds is absent.
	 */
	private static function holders( array $paths, string $post_type ): array {
		global $wpdb;

		$keys = [];
		foreach ( $paths as $path ) {
			$keys[ Ledger::key( $post_type, (string) $path ) ] = (string) $path;
		}
		if ( [] === $keys ) {
			return [];
		}
		$placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $placeholders holds only %s, one per listed old path, at most the count cap; a range on the meta_key index.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT pm.meta_key, pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key IN ( {$placeholders} ) AND p.post_type = %s AND p.post_status NOT IN ( 'trash', 'auto-draft', 'inherit' ) ORDER BY pm.meta_id DESC", ...array_merge( array_keys( $keys ), [ $post_type ] ) ), ARRAY_A );

		$out = [];
		foreach ( (array) $rows as $row ) {
			$path = $keys[ (string) $row['meta_key'] ] ?? '';
			if ( '' !== $path && ! isset( $out[ $path ] ) ) {
				$out[ $path ] = (int) $row['post_id'];
			}
		}
		return $out;
	}
}
