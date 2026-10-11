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
use OutOfBoundsException;
use WP_Post;

/**
 * Lists the old URLs a change left behind and where each leads now.
 *
 * A block starts at one old path. Its head is the post that path leads to,
 * or none. Below it come the published pages that lived under the old path
 * when it was left, each at the path it had then, depth first in menu order.
 * Every block of a change is walked together, one query pair per level, and
 * no walk goes past the count cap. Last, every pair is checked the way the
 * resolver serves it, so only what the site redirects is listed. Nothing is
 * stored: the collected pairs are cached for a few minutes, keyed on the
 * posts' last change.
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
	 * Every pair of the blocks, up to the count cap.
	 *
	 * A block is skipped when its old path has no URL (plain permalinks, or a
	 * type without a permastruct), when it leads where it already is, or when
	 * its pages now sit under the trash. A block whose old path a live page
	 * uses now is unresolved: redirect plugins redirect before WordPress
	 * looks, so listing it would hide that page. Its old subpage paths no
	 * published page uses are still listed, as the resolver serves them.
	 *
	 * On a post's old path, and on the old path of a root still in the trash,
	 * each page is listed at the path it had when the old path was left, rebuilt
	 * by listed_then(). A deleted root lists each subpage at its slug from
	 * before the move. An old path the ledger gives to another live post leads
	 * to that post, so it is dropped here with the pages walked below it: that
	 * post's own list has them. A deleted root's old path leads to its target
	 * only while that target holds it, so with no live holder it is
	 * unresolved, while its subpages are still listed. When the newest live
	 * holder of a block's own old path is not the page it leads to, or sits
	 * under the trash, the whole block is unresolved. Last, each pair is
	 * checked against what the resolver serves.
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

		foreach ( $blocks as $index => $block ) {
			$old  = (string) $block['old'];
			$base = (string) $block['base'];
			if ( '' === $old || '' === Paths::url( $post_type, $old ) || $old === $base || Paths::has_trashed_segment( $base ) ) {
				continue;
			}
			$open[ $index ] = $block;
		}

		$owners = self::owners( array_map( static fn( array $block ): string => (string) $block['old'], $open ), $post_type );
		$in_use = [];
		foreach ( $open as $index => $block ) {
			$old   = (string) $block['old'];
			$owner = $owners[ $old ] ?? 0;
			// The page there now is reached before any redirect; the old subpage paths no page uses still redirect.
			if ( $owner > 0 && $owner !== (int) $block['head'] && is_post_publicly_viewable( $owner ) ) {
				$in_use[ $index ] = true;
				$unresolved[]     = [
					'old'    => $old,
					'reason' => 'in_use',
				];
			}
			$live[ $index ] = $block;
			$capped         = $capped || ! empty( $block['incomplete'] );
		}

		$tree = $this->walk( $live, $post_type, $cap );
		// The ledger entries and trash markers of every page walked and every post
		// a block is recorded on, and of the pages in the trash those markers name.
		$others = [];
		foreach ( $live as $block ) {
			if ( ! empty( $block['rebuild'] ) ) {
				$others = array_merge( $others, array_filter( [ (int) ( $block['post'] ?? $block['recorded_on'] ?? 0 ) ] ), array_map( 'intval', (array) ( $block['virtual'] ?? [] ) ) );
			}
		}
		if ( [] !== $others ) {
			// The pages above the subpages walked that are not walked themselves, as where a moved subpage lives now.
			$walked = array_flip( $tree['ids'] );
			foreach ( array_merge( array_merge( [], ...array_values( $tree['tops'] ) ), array_merge( [], ...array_values( $tree['kids'] ) ) ) as $node ) {
				if ( (int) $node['parent'] > 0 && ! isset( $walked[ (int) $node['parent'] ] ) ) {
					$others[] = (int) $node['parent'];
				}
			}
		}
		$history = [] !== $others ? self::history( $tree['ids'], $others, $post_type ) : [];

		$pairs    = [];
		$lineage  = [];
		$block_of = [];
		$held     = [];
		$headless = [];
		foreach ( $live as $index => $block ) {
			$old  = (string) $block['old'];
			$head = (int) $block['head'];
			$post = $head > 0 ? get_post( $head ) : null;
			$at   = [];
			if ( isset( $in_use[ $index ] ) ) {
				$headless[ $index ] = true;
			} elseif ( $post instanceof WP_Post && is_post_publicly_viewable( $post ) ) {
				$at[ $old ]                  = count( $pairs );
				$block_of[ count( $pairs ) ] = $index;
				if ( ! empty( $block['held'] ) ) {
					$held[ count( $pairs ) ] = true;
				}
				$pairs[] = [
					'old' => $old,
					'id'  => $head,
				];
			} else {
				$reason             = (string) $block['reason'];
				$headless[ $index ] = true;
				$unresolved[]       = [
					'old'    => $old,
					// A target deleted since is no page at all, not an unpublished one.
					'reason' => '' !== $reason ? $reason : ( $post instanceof WP_Post ? 'unpublished' : 'none' ),
				];
			}

			$listed = ! empty( $block['rebuild'] )
				? self::listed_then( $block + [ 'index' => $index ], $tree, $history )
				: self::listed_by_segment( $block, $tree['tops'][ $index ] ?? [], $tree['kids'] );
			foreach ( $listed as [ $path, $id ] ) {
				if ( count( $pairs ) >= $cap ) {
					$capped = true;
					break 2;
				}
				// The pairs of this block above it: those its old path lies under.
				$above    = [];
				$segments = explode( '/', $path );
				for ( $length = count( $segments ) - 1; $length >= 1; $length-- ) {
					$prefix = implode( '/', array_slice( $segments, 0, $length ) );
					if ( isset( $at[ $prefix ] ) ) {
						$above[] = $at[ $prefix ];
					}
				}
				$at[ $path ]                 = count( $pairs );
				$lineage[ count( $pairs ) ]  = $above;
				$block_of[ count( $pairs ) ] = $index;
				$pairs[]                     = [
					'old' => $path,
					'id'  => $id,
				];
			}
		}

		// The ledger as the resolver reads it, for every prefix of every path
		// listed, in one query.
		$olds    = array_merge( array_column( $pairs, 'old' ), array_map( static fn( array $block ): string => (string) $block['old'], $live ) );
		$facts   = self::ledger_facts( self::prefixes( $olds ), $post_type );
		$holders = self::holders( $olds, $facts );

		// The resolver rewrites an old path through the newest live post that
		// holds its longest known prefix. When that post for a block's own old
		// path is not the page the block leads to, or sits under the trash, so
		// the resolver passes over it, nothing in the block leads where it says.
		$void = [];
		foreach ( $live as $index => $block ) {
			$holder = $holders[ (string) $block['old'] ] ?? null;
			$target = (int) ( $block['target'] ?? 0 );
			if ( null === $holder || ( ! $holder['trashed'] && $holder['id'] === $target ) ) {
				continue;
			}
			$void[ $index ] = true;
			if ( ! isset( $headless[ $index ] ) ) {
				$unresolved[] = [
					'old'    => (string) $block['old'],
					'reason' => $holder['trashed'] ? 'trash' : 'in_use',
				];
			}
		}

		// An old path another live post holds leads to that post, and its own
		// panel lists what sits under it: drop it here, with the pages walked
		// below it. A holder under the trash holds nothing for the resolver.
		$dropped = [];
		$unheld  = [];
		foreach ( $pairs as $i => $pair ) {
			$found  = $holders[ $pair['old'] ] ?? null;
			$holder = null !== $found && ! $found['trashed'] ? $found['id'] : 0;
			if ( isset( $void[ $block_of[ $i ] ?? -1 ] ) || ( $holder > 0 && $holder !== $pair['id'] ) || [] !== array_intersect_key( array_flip( $lineage[ $i ] ?? [] ), $dropped ) ) {
				$dropped[ $i ] = true;
			} elseif ( isset( $held[ $i ] ) && 0 === $holder ) {
				// No live page holds a deleted root's old path, so it leads nowhere; its subpages may still redirect.
				$unheld[ $i ] = true;
				$unresolved[] = [
					'old'    => $pair['old'],
					'reason' => 'none',
				];
			}
		}

		// Last, each pair left is checked the way the resolver serves it: a 404
		// at the old path, rewritten round by round to that page. A published
		// page at the old path, as under a hidden page there now, is reached
		// before any redirect. What the resolver does not serve is not listed;
		// a block's own old path it serves nowhere, or elsewhere, has no new URL here.
		$left   = array_diff_key( $pairs, $dropped, $unheld );
		$served = self::served( array_values( array_unique( array_column( $left, 'old' ) ) ), $post_type, $facts );
		foreach ( $left as $i => $pair ) {
			$target = $served[ $pair['old'] ] ?? 0;
			if ( $target === $pair['id'] ) {
				continue;
			}
			$dropped[ $i ] = true;
			if ( ! isset( $lineage[ $i ] ) ) {
				$unresolved[] = [
					'old'    => $pair['old'],
					'reason' => $target > 0 ? 'in_use' : 'none',
				];
			}
		}
		$pairs = array_values( array_diff_key( $pairs, $dropped, $unheld ) );

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
		$skip    = [];
		$visited = 0;
		foreach ( $blocks as $index => $block ) {
			foreach ( (array) $block['children'] as $node ) {
				$tops[ $index ][] = $node;
				++$visited;
			}
			if ( (int) $block['from'] > 0 ) {
				$froms[ (int) $block['from'] ] = $index;
				$skip[ $index ]                = array_flip( array_map( 'intval', (array) ( $block['skip'] ?? [] ) ) );
			}
		}
		if ( [] !== $froms && $visited <= $cap ) {
			$rows = Subtree::ordered_children( array_keys( $froms ), $type, $cap + 1 - $visited );
			foreach ( $rows as $row ) {
				$index = $froms[ (int) $row['post_parent'] ];
				if ( ! isset( $skip[ $index ][ (int) $row['ID'] ] ) ) {
					$tops[ $index ][] = self::node( $row, (string) $row['post_name'] );
					++$visited;
				}
			}
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
			$out[ $root_of[ $id ] ][] = self::node( $row, $slug );
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
				$out[ $root_id ][] = self::node( $row, is_string( $slug ) && '' !== $slug ? $slug : (string) $row['post_name'] );
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
	 * Every pair of a trash change's block: each subpage at its old segment
	 * under the root's old path, its subpages below it, depth first.
	 *
	 * @param array<string,mixed>                          $block Block.
	 * @param array<int,array<string,mixed>>               $tops  The block's first-level pages.
	 * @param array<int,array<int,array<string,mixed>>>    $kids  Walked subpages by parent.
	 * @return array<int,array{0:string,1:int}> Old path and page ID, published pages only.
	 */
	private static function listed_by_segment( array $block, array $tops, array $kids ): array {
		$out   = [];
		$stack = array_map( static fn( array $node ): array => [ $node, (string) $block['old'] ], array_reverse( $tops ) );
		while ( [] !== $stack ) {
			[ $node, $parent ] = array_pop( $stack );
			// Core skips an empty slug when it builds a path, so the old path does too.
			$path = '' !== $node['segment'] ? $parent . '/' . $node['segment'] : $parent;
			if ( 'publish' === $node['status'] ) {
				$out[] = [ $path, (int) $node['id'] ];
			}
			foreach ( array_reverse( $kids[ $node['id'] ] ?? [] ) as $kid ) {
				$stack[] = [ $kid, $path ];
			}
		}
		return $out;
	}

	/**
	 * Every pair behind an old path recorded on a post: each page walked that
	 * lived under the old path when it was left, at the path it had then.
	 *
	 * The tree as it was then is rebuilt from what happened since, newest
	 * first: each ledger entry of a page is where it was before it moved or
	 * was renamed, and each trash marker puts a subpage back under the page in
	 * the trash it left. An entry below the page's own path is an alias for
	 * subpages that moved up to it, not a place it was, and so is one a page
	 * in the trash says it wrote there. The post's own old path dates its own
	 * subpages; each page in the trash that left the path dates the subpages
	 * its trash moved up here, from its first move or its entry for the path.
	 *
	 * @param array<string,mixed>                                                                                $block   Block.
	 * @param array{tops:array<int,array<int,array<string,mixed>>>,kids:array<int,array<int,array<string,mixed>>>} $tree    The walk.
	 * @param array<string,array<int,mixed>>                                                                     $history From history().
	 * @return array<int,array{0:string,1:int}> Old path and page ID, published pages only, depth first in menu order.
	 */
	private static function listed_then( array $block, array $tree, array $history ): array {
		$old   = (string) $block['old'];
		$post  = (int) ( $block['post'] ?? $block['recorded_on'] );
		$index = (int) $block['index'];
		$base  = (string) $block['base'];
		$nodes = [];
		$order = [];
		$stack = array_reverse( $tree['tops'][ $index ] ?? [] );
		$dates = [];
		while ( [] !== $stack ) {
			$node                 = array_pop( $stack );
			$nodes[ $node['id'] ] = $node;
			$order[]              = $node['id'];
			if ( isset( $node['holder'] ) ) {
				$dates[ (int) $node['holder'] ] = true;
			}
			foreach ( array_reverse( $tree['kids'][ $node['id'] ] ?? [] ) as $kid ) {
				$stack[] = $kid;
			}
		}

		// Where each page is now: its parent and slug, up to the top level.
		$places = $history['places'];
		foreach ( $nodes as $id => $node ) {
			$places[ $id ] = [
				'parent' => (int) $node['parent'],
				'above'  => '',
				'slug'   => (string) $node['slug'],
			];
		}
		if ( $post > 0 && ! isset( $places[ $post ] ) ) {
			$places[ $post ] = self::place( $base );
		}
		foreach ( $places as $id => $place ) {
			if ( $place['parent'] > 0 && ! isset( $places[ $place['parent'] ] ) ) {
				$places[ $id ]['above']  = self::above( $place['parent'] );
				$places[ $id ]['parent'] = 0;
			}
		}

		// The dates: the post's own entry for the path for its own subpages; for
		// each page in the trash that left it, its first move up and, on a post's
		// old path, its own entry for the path, since it may have left the path
		// before its trash too.
		$passes = [];
		if ( (int) $block['from'] > 0 && $post === (int) $block['from'] ) {
			$entry    = self::entry_id( $history['entries'][ $post ] ?? [], $old );
			$passes[] = [ $post, $entry > 0 ? $entry : PHP_INT_MAX ];
		}
		foreach ( array_keys( $dates ) as $holder ) {
			$first = PHP_INT_MAX;
			foreach ( $history['markers'] as $marks ) {
				foreach ( $marks as $mark ) {
					if ( (int) $holder === $mark['root'] ) {
						$first = min( $first, $mark['id'] );
					}
				}
			}
			if ( PHP_INT_MAX !== $first ) {
				$passes[] = [ (int) $holder, $first ];
			}
			$entry = (int) ( $block['recorded_on'] ?? 0 ) > 0 ? self::entry_id( $history['entries'][ $holder ] ?? [], $old ) : 0;
			if ( $entry > 0 && $entry < $first ) {
				$passes[] = [ (int) $holder, $entry ];
			}
		}

		$out  = [];
		$seen = [];
		foreach ( $passes as [ $anchor, $date ] ) {
			$then = self::rewind( $places, $history, $date, $anchor, $old );
			foreach ( $order as $id ) {
				$path = $then[ $id ] ?? '';
				if ( 'publish' === $nodes[ $id ]['status'] && str_starts_with( $path, $old . '/' ) && ! isset( $seen[ $path ] ) ) {
					$seen[ $path ] = true;
					$out[]         = [ $path, (int) $id ];
				}
			}
		}
		return $out;
	}

	/**
	 * A place for a page at a path whose parent is not tracked.
	 *
	 * @param string $path Normalized path.
	 * @return array{parent:int,above:string,slug:string}
	 */
	private static function place( string $path ): array {
		$cut = strrpos( $path, '/' );
		return [
			'parent' => 0,
			'above'  => false === $cut ? '' : substr( $path, 0, $cut ),
			'slug'   => false === $cut ? $path : substr( $path, $cut + 1 ),
		];
	}

	/**
	 * Each tracked page's path just before a date, from its place now and what
	 * happened since, newest first. The anchor ends at the old path itself.
	 *
	 * @param array<int,array{parent:int,above:string,slug:string}> $places  Each page's place now, by ID.
	 * @param array<string,array<int,mixed>>                        $history From history().
	 * @param int                                                   $date    Meta ID; everything from it on is taken back.
	 * @param int                                                   $anchor  The page that left the old path then.
	 * @param string                                                $old     The old path.
	 * @return array<int,string> Path by page ID.
	 */
	private static function rewind( array $places, array $history, int $date, int $anchor, string $old ): array {
		// A page in the trash cannot move: an entry it got after its first move up says where it was then.
		$trashed = [];
		foreach ( $history['markers'] as $marks ) {
			foreach ( $marks as $mark ) {
				$trashed[ $mark['root'] ] = min( $trashed[ $mark['root'] ] ?? PHP_INT_MAX, $mark['id'] );
			}
		}
		$events = [];
		foreach ( $places as $id => $place ) {
			foreach ( $history['entries'][ $id ] ?? [] as $entry ) {
				if ( $entry['id'] >= $date ) {
					$fact     = isset( $history['virtual'][ $id ] ) && $entry['id'] > ( $trashed[ $id ] ?? PHP_INT_MAX );
					$events[] = [ $entry['id'], $id, $entry['path'], $fact ? -1 : 0 ];
				}
			}
			foreach ( $history['markers'][ $id ] ?? [] as $mark ) {
				if ( $mark['id'] >= $date && isset( $places[ $mark['root'] ], $history['virtual'][ $mark['root'] ] ) ) {
					$events[] = [ $mark['id'], $id, $mark['original'], $mark['root'] ];
				}
			}
		}
		usort( $events, static fn( array $a, array $b ): int => $b[0] <=> $a[0] );

		$path  = static function ( int $id ) use ( &$places ): string {
			$segments = [];
			for ( $depth = 0; $id > 0 && isset( $places[ $id ] ) && $depth < 50; $depth++ ) {
				if ( '' !== $places[ $id ]['slug'] ) {
					array_unshift( $segments, $places[ $id ]['slug'] );
				}
				if ( 0 === $places[ $id ]['parent'] ) {
					if ( '' !== $places[ $id ]['above'] ) {
						array_unshift( $segments, $places[ $id ]['above'] );
					}
					break;
				}
				$id = $places[ $id ]['parent'];
			}
			return implode( '/', $segments );
		};
		$below = static function ( int $id, int $of ) use ( &$places ): bool {
			for ( $depth = 0; $id > 0 && isset( $places[ $id ] ) && $depth < 50; $depth++ ) {
				if ( $id === $of ) {
					return true;
				}
				$id = $places[ $id ]['parent'];
			}
			return false;
		};
		$wrote = [];
		foreach ( $history['virtual'] as $root ) {
			foreach ( $root['aliases'] as $alias ) {
				$wrote[ (int) $alias['to'] ][ (string) $alias['path'] ] = true;
			}
		}
		// The pages by the last segment of their slug, so a path is looked up among a few.
		$named = [];
		foreach ( $places as $id => $place ) {
			$named[ basename( $place['slug'] ) ][ $id ] = true;
		}
		$set = static function ( int $id, array $place ) use ( &$places, &$named ): void {
			unset( $named[ basename( $places[ $id ]['slug'] ) ][ $id ] );
			$places[ $id ]                              = $place;
			$named[ basename( $place['slug'] ) ][ $id ] = true;
		};

		// A page was at a path before a time: where its parent was too, if it was only somewhere else.
		$was_at = static function ( int $id, string $was, bool $fact ) use ( &$was_at, &$places, &$named, $set, $path, $below ): void {
			$now = $path( $id );
			if ( $was === $now ) {
				return;
			}
			$place = self::place( $was );
			$up    = $places[ $id ]['parent'];
			if ( $fact ) {
				// It did not move, so its parent was where the path says, unless the
				// parent then was another page: core moves a page in the trash up
				// when its parent is deleted for good.
				$above = basename( $place['above'] );
				if ( $up > 0 && ( $places[ $up ]['slug'] === $above || 1 === preg_match( '/^' . preg_quote( $above, '/' ) . '-\d+$/', $places[ $up ]['slug'] ) ) ) {
					$was_at( $up, $place['above'], true );
					$set( $id, [ 'slug' => $place['slug'] ] + $places[ $id ] );
				} else {
					$set( $id, $place );
				}
				return;
			}
			$slug = $places[ $id ]['slug'];
			// Renamed where it was: under the same parent or, when its slug changed
			// other than by a move making it unique, under a parent whose path then
			// the ledger lost, as when a page moved back to a path prunes its entry.
			if ( ( $up > 0 ? $path( $up ) : $places[ $id ]['above'] ) === $place['above'] || ( $slug !== $place['slug'] && 1 !== preg_match( '/^' . preg_quote( $place['slug'], '/' ) . '-\d+$/', $slug ) ) ) {
				$set( $id, [ 'slug' => $place['slug'] ] + $places[ $id ] );
				return;
			}
			// Moved: under the tracked page at that path then or, when the page
			// between is not tracked, under the nearest tracked page above it.
			$set( $id, $place );
			$segments = '' !== $place['above'] ? explode( '/', $place['above'] ) : [];
			for ( $length = count( $segments ); $length >= 1; $length-- ) {
				$prefix = implode( '/', array_slice( $segments, 0, $length ) );
				foreach ( array_keys( $named[ $segments[ $length - 1 ] ] ?? [] ) as $other ) {
					if ( $other !== $id && $path( $other ) === $prefix && ! $below( $other, $id ) ) {
						$set(
							$id,
							[
								'parent' => $other,
								'above'  => '',
								'slug'   => ltrim( implode( '/', array_slice( $segments, $length ) ) . '/' . $place['slug'], '/' ),
							]
						);
						return;
					}
				}
			}
		};

		foreach ( $events as [ , $id, $was, $root ] ) {
			if ( $root > 0 ) {
				// The trash of that page moved this subpage up: before, it lived under it with its old slug.
				if ( ! $below( $root, $id ) ) {
					$set(
						$id,
						[
							'parent' => $root,
							'above'  => '',
							'slug'   => '' !== $was ? $was : $places[ $id ]['slug'],
						]
					);
				}
				continue;
			}
			// An entry below the page's own path is an alias for subpages that moved up to it; the old path itself is where the anchor was.
			$now = $path( $id );
			if ( isset( $wrote[ $id ][ $was ] ) || ( ( $id !== $anchor || $was !== $old ) && str_starts_with( $was, $now . '/' ) ) ) {
				continue;
			}
			$was_at( $id, $was, $root < 0 );
		}
		if ( $path( $anchor ) !== $old ) {
			$set( $anchor, self::place( $old ) );
		}

		$out = [];
		foreach ( array_keys( $places ) as $id ) {
			$out[ $id ] = $path( $id );
		}
		return $out;
	}

	/**
	 * The ledger entries and trash markers of the given pages, of the pages
	 * in the trash those markers name, and of the pages above them all, with
	 * where each of those pages is now: one query for the pages, then one per
	 * new set of pages in the trash and pages above them.
	 *
	 * @param int[]  $walked    Pages walked, whose places the walk knows.
	 * @param int[]  $others    Posts the blocks are recorded on, pages in the trash the blocks name, and the pages above them.
	 * @param string $post_type Post type name.
	 * @return array{entries:array<int,array<int,array{id:int,path:string}>>,markers:array<int,array<int,array{id:int,root:int,original:string}>>,virtual:array<int,array{aliases:array<int,array{to:int,path:string}>}>,places:array<int,array{parent:int,above:string,slug:string}>}
	 */
	private static function history( array $walked, array $others, string $post_type ): array {
		global $wpdb;

		$out    = [
			'entries' => [],
			'markers' => [],
			'virtual' => [],
			'places'  => [],
		];
		$walked = array_flip( array_map( 'intval', $walked ) );
		$read   = [];
		$new    = array_values( array_unique( array_filter( array_map( 'intval', $others ) ) ) );
		$ask    = array_values( array_unique( array_merge( array_keys( $walked ), $new ) ) );
		for ( $round = 0; [] !== $ask && $round < 6; $round++ ) {
			// The pages above the pages new here are tracked too, so a rename up there is taken back.
			self::prime_with_ancestors( $new );
			foreach ( $new as $id ) {
				$post = get_post( $id );
				for ( $depth = 0; $post instanceof WP_Post && (int) $post->post_parent > 0 && $depth < 50; $depth++ ) {
					$parent = (int) $post->post_parent;
					if ( isset( $read[ $parent ] ) || isset( $walked[ $parent ] ) || in_array( $parent, $ask, true ) ) {
						break;
					}
					$ask[] = $parent;
					$new[] = $parent;
					$post  = get_post( $parent );
				}
			}
			foreach ( $ask as $id ) {
				$read[ $id ] = true;
			}

			$placeholders = implode( ',', array_fill( 0, count( $ask ), '%d' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $placeholders holds only %d, one per page asked about, bounded by the count cap; a range on the post_id index.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_id, post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ( {$placeholders} ) AND ( meta_key LIKE %s OR meta_key = '_wp_desired_post_slug' ) ORDER BY meta_id ASC", ...array_merge( $ask, [ $wpdb->esc_like( '_hierarchy_guard_' ) . '%' ] ) ), ARRAY_A );

			$roots   = [];
			$desired = [];
			$aliases = [];
			foreach ( (array) $rows as $row ) {
				$id  = (int) $row['post_id'];
				$key = (string) $row['meta_key'];
				if ( str_starts_with( $key, Ledger::KEY_PREFIX ) ) {
					$out['entries'][ $id ][] = [
						'id'   => (int) $row['meta_id'],
						'path' => (string) $row['meta_value'],
					];
				} elseif ( str_starts_with( $key, Markers::MOVED_PREFIX ) ) {
					$root                    = (int) substr( $key, strlen( Markers::MOVED_PREFIX ) );
					$moved                   = Markers::decode_moved( (string) $row['meta_value'] );
					$out['markers'][ $id ][] = [
						'id'       => (int) $row['meta_id'],
						'root'     => $root,
						'original' => Paths::normalize( $moved['original'] ),
					];
					$roots[ $root ]          = true;
				} elseif ( Markers::ALIASES === $key ) {
					$value          = maybe_unserialize( (string) $row['meta_value'] );
					$aliases[ $id ] = is_array( $value ) ? $value : [];
				} elseif ( '_wp_desired_post_slug' === $key ) {
					$desired[ $id ] = (string) $row['meta_value'];
				}
			}

			// Where each page new here is now; a page in the trash has its slug from before, and the aliases it wrote.
			foreach ( $new as $id ) {
				$post = get_post( $id );
				if ( ! $post instanceof WP_Post || $post_type !== $post->post_type ) {
					continue;
				}
				$trash                = 'trash' === $post->post_status;
				$slug                 = $trash && '' !== ( $desired[ $id ] ?? '' ) ? $desired[ $id ] : (string) $post->post_name;
				$out['places'][ $id ] = [
					'parent' => (int) $post->post_parent,
					'above'  => '',
					'slug'   => Paths::normalize( $trash ? Paths::strip_trashed_suffix( $slug ) : $slug ),
				];
				if ( $trash ) {
					$out['virtual'][ $id ] = [
						'aliases' => array_map(
							static fn( array $alias ): array => [
								'to'   => (int) ( $alias['to'] ?? 0 ),
								'path' => Paths::normalize( (string) ( $alias['path'] ?? '' ) ),
							],
							array_filter( $aliases[ $id ] ?? [], 'is_array' )
						),
					];
				}
			}

			$new = array_values( array_diff( array_keys( $roots ), array_keys( $read ), array_keys( $walked ) ) );
			$ask = $new;
		}
		return $out;
	}

	/**
	 * The meta ID of a post's ledger entry for a path, or 0 when it holds none.
	 *
	 * @param array<int,array{id:int,path:string}> $entries The post's old paths, oldest first.
	 * @param string                               $path    Normalized path.
	 * @return int
	 */
	private static function entry_id( array $entries, string $path ): int {
		foreach ( $entries as $entry ) {
			if ( $path === $entry['path'] ) {
				return $entry['id'];
			}
		}
		return 0;
	}

	/**
	 * The path above a page that is not tracked, with the trash suffix taken
	 * off each slug: where its subpages lived before anything went to the trash.
	 *
	 * @param int $parent_id Its parent, 0 for top level.
	 * @return string
	 */
	private static function above( int $parent_id ): string {
		$segments = [];
		for ( $depth = 0; $parent_id > 0 && $depth < 50; $depth++ ) {
			$parent = get_post( $parent_id );
			if ( ! $parent instanceof WP_Post ) {
				break;
			}
			$slug = Paths::strip_trashed_suffix( (string) $parent->post_name );
			if ( '' !== $slug ) {
				array_unshift( $segments, $slug );
			}
			$parent_id = (int) $parent->post_parent;
		}
		return Paths::normalize( implode( '/', $segments ) );
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
		return self::lookup( $paths, [], $post_type )[0];
	}

	/**
	 * get_page_by_path() for many paths in one query, two ways: as the main
	 * query looks a request up, for the type and attachments with the first
	 * post of the type winning; and as the resolver looks a candidate up, for
	 * the type alone, where core keeps the last match. The posts found are primed.
	 *
	 * @param string[] $paths      Normalized paths, looked up as a request is.
	 * @param string[] $candidates Normalized paths, looked up as the resolver does.
	 * @param string   $post_type  Post type name.
	 * @return array{0:array<string,int>,1:array<string,int>} Post ID by path for each; a path no post uses now is absent.
	 */
	private static function lookup( array $paths, array $candidates, string $post_type ): array {
		global $wpdb;

		$names = [];
		foreach ( array_merge( $paths, $candidates ) as $path ) {
			foreach ( explode( '/', (string) $path ) as $name ) {
				$names[ $name ] = true;
			}
		}
		unset( $names[''] );
		if ( [] === $names ) {
			return [ [], [] ];
		}

		$names        = array_map( 'strval', array_keys( $names ) );
		$placeholders = implode( ',', array_fill( 0, count( $names ), '%s' ) );
		$types        = [] !== $paths ? "%s, 'attachment'" : '%s';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $placeholders holds only %s, one per segment of the paths; $types is a fixed placeholder list; core's get_page_by_path() query for every path at once, on the post_name index.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_name, post_parent, post_type FROM {$wpdb->posts} WHERE post_name IN ( {$placeholders} ) AND post_type IN ( {$types} )", ...array_merge( $names, [ $post_type ] ) ), OBJECT_K );

		// Rows by slug, each in the order the query returned them, which decides between duplicates.
		$named = [];
		foreach ( $rows as $id => $row ) {
			$named[ (string) $row->post_name ][ (int) $id ] = $row;
		}
		$found = [
			self::match( $paths, $rows, $named, $post_type, true ),
			self::match( $candidates, $rows, $named, $post_type, false ),
		];

		$ids = array_values( array_unique( array_merge( array_values( $found[0] ), array_values( $found[1] ) ) ) );
		if ( [] !== $ids ) {
			_prime_post_caches( $ids, false, false );
		}
		return $found;
	}

	/**
	 * The matching loop of get_page_by_path() for each path, on rows already read.
	 *
	 * @param string[]                      $paths       Normalized paths.
	 * @param array<int,object>             $rows        Rows by ID.
	 * @param array<string,array<int,object>> $named     The same rows by slug.
	 * @param string                        $post_type   Post type name.
	 * @param bool                          $attachments Whether attachments take part, with the first post of the type winning; otherwise the last match does.
	 * @return array<string,int>
	 */
	private static function match( array $paths, array $rows, array $named, string $post_type, bool $attachments ): array {
		$out = [];
		foreach ( array_unique( array_map( 'strval', $paths ) ) as $path ) {
			$segments = explode( '/', $path );
			$reversed = array_reverse( $segments );
			$found    = 0;
			foreach ( $named[ $reversed[0] ] ?? [] as $page ) {
				if ( ! $attachments && $post_type !== (string) $page->post_type ) {
					continue;
				}
				$count = 0;
				$up    = $page;
				while ( 0 !== (int) $up->post_parent ) {
					$parent = $rows[ (int) $up->post_parent ] ?? null;
					// Core only climbs through rows of the path's own slugs and types.
					if ( null === $parent || ! in_array( (string) $parent->post_name, $segments, true ) || ( ! $attachments && $post_type !== (string) $parent->post_type ) ) {
						break;
					}
					++$count;
					if ( ! isset( $reversed[ $count ] ) || (string) $parent->post_name !== $reversed[ $count ] ) {
						break;
					}
					$up = $parent;
				}
				if ( 0 === (int) $up->post_parent && count( $reversed ) === $count + 1 && (string) $up->post_name === $reversed[ $count ] ) {
					$found = (int) $page->ID;
					if ( $attachments && $post_type === (string) $page->post_type ) {
						break;
					}
				}
			}
			if ( $found > 0 ) {
				$out[ $path ] = $found;
			}
		}
		return $out;
	}

	/**
	 * The live post each old path leads to in the ledger, the one the resolver
	 * picks: the newest entry among posts not in the trash, and whether it sits
	 * under a page in the trash, which makes the resolver pass over it.
	 *
	 * @param string[]                                $paths Normalized old paths.
	 * @param array<string,array{live:int,held:bool}> $facts From ledger_facts(), for these paths.
	 * @return array<string,array{id:int,trashed:bool}> Holder by path; a path no live post holds is absent.
	 */
	private static function holders( array $paths, array $facts ): array {
		$found = [];
		foreach ( $paths as $path ) {
			$live = $facts[ (string) $path ]['live'] ?? 0;
			if ( $live > 0 ) {
				$found[ (string) $path ] = $live;
			}
		}
		if ( [] === $found ) {
			return [];
		}

		self::prime_with_ancestors( array_values( array_unique( $found ) ) );
		$out = [];
		foreach ( $found as $path => $holder_id ) {
			$holder       = get_post( $holder_id );
			$out[ $path ] = [
				'id'      => $holder_id,
				'trashed' => ! $holder instanceof WP_Post || Paths::has_trashed_segment( Paths::uri( $holder ) ),
			];
		}
		return $out;
	}

	/**
	 * Every path and each of its shorter prefixes, once.
	 *
	 * @param string[] $paths Normalized paths.
	 * @return string[]
	 */
	private static function prefixes( array $paths ): array {
		$out = [];
		foreach ( $paths as $path ) {
			$segments = explode( '/', (string) $path );
			for ( $length = count( $segments ); $length >= 1; $length-- ) {
				$prefix = implode( '/', array_slice( $segments, 0, $length ) );
				if ( isset( $out[ $prefix ] ) ) {
					break;
				}
				$out[ $prefix ] = true;
			}
		}
		unset( $out[''] );
		return array_map( 'strval', array_keys( $out ) );
	}

	/**
	 * What the ledger says about each path, the way Ledger::find() and
	 * Ledger::held_in_trash() read it: the newest post of the type not in the
	 * trash that holds it, and whether a post in the trash holds it. One query.
	 *
	 * @param string[] $paths     Normalized paths, at most a few per pair listed.
	 * @param string   $post_type Post type name.
	 * @return array<string,array{live:int,held:bool}> Facts by path, for every path asked about.
	 */
	private static function ledger_facts( array $paths, string $post_type ): array {
		global $wpdb;

		$out  = [];
		$keys = [];
		foreach ( $paths as $path ) {
			$out[ (string) $path ]                             = [
				'live' => 0,
				'held' => false,
			];
			$keys[ Ledger::key( $post_type, (string) $path ) ] = (string) $path;
		}
		if ( [] === $keys ) {
			return [];
		}
		$placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $placeholders holds only %s, one per path listed or prefix of one, bounded by the count cap; a range on the meta_key index.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT pm.meta_key, pm.post_id, p.post_status FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key IN ( {$placeholders} ) AND p.post_type = %s ORDER BY pm.meta_id DESC", ...array_merge( array_keys( $keys ), [ $post_type ] ) ), ARRAY_A );

		foreach ( (array) $rows as $row ) {
			$path = $keys[ (string) $row['meta_key'] ] ?? '';
			if ( '' === $path ) {
				continue;
			}
			if ( 'trash' === (string) $row['post_status'] ) {
				$out[ $path ]['held'] = true;
			} elseif ( 0 === $out[ $path ]['live'] ) {
				$out[ $path ]['live'] = (int) $row['post_id'];
			}
		}
		return $out;
	}

	/**
	 * The page the server redirects each path to, or 0, for every path at once:
	 * Rules::serves() on facts read ahead. Each run of the rules that asks for
	 * a fact not read yet stops; the facts every run asked for are read in one
	 * query, ledger facts or path lookups, and the runs start again. The paths
	 * go round by round together, so each round costs one ledger query, one
	 * get_page_by_path() lookup for every rewritten path, the request lookups
	 * with the first, and the posts found primed a level at a time.
	 *
	 * @param string[]                                $paths     Normalized old paths, at most the count cap.
	 * @param string                                  $post_type Post type name.
	 * @param array<string,array{live:int,held:bool}> $facts     Ledger facts read so far, by path; more are added.
	 * @return array<string,int> Page ID by path, 0 for none.
	 */
	private static function served( array $paths, string $post_type, array &$facts ): array {
		$view    = new Prefetched_View( $post_type, $facts );
		$out     = [];
		$pending = [];
		foreach ( $paths as $path ) {
			$pending[ (string) $path ] = (string) $path;
		}

		for ( $wave = 0; [] !== $pending && $wave < 2 * ( Rules::MAX_ROUNDS + 1 ); $wave++ ) {
			$needs = $view->take_needs();
			if ( 0 === $wave ) {
				$needs['facts'] = array_values( array_filter( self::prefixes( array_values( $pending ) ), static fn( string $prefix ): bool => ! isset( $facts[ $prefix ] ) ) );
			}
			if ( [] !== $needs['facts'] ) {
				$facts += self::ledger_facts( $needs['facts'], $post_type );
			}
			$found = [];
			if ( [] !== $needs['requests'] || [] !== $needs['candidates'] ) {
				[ $at, $there ] = self::lookup( $needs['requests'], $needs['candidates'], $post_type );
				$view->add_lookups( $needs['requests'], $at, $needs['candidates'], $there );
				$found = array_merge( array_values( $at ), array_values( $there ) );
			}
			// The live posts that hold a prefix of the path each run rewrites next, and the posts found, with the pages above them.
			$live = [];
			foreach ( $pending as $current ) {
				foreach ( self::prefixes( [ $current ] ) as $prefix ) {
					if ( ( $facts[ $prefix ]['live'] ?? 0 ) > 0 ) {
						$live[] = $facts[ $prefix ]['live'];
					}
				}
			}
			self::prime_with_ancestors( array_values( array_unique( array_merge( $live, $found ) ) ) );

			$next = [];
			foreach ( array_keys( $pending ) as $start ) {
				$start = (string) $start;
				$trace = [];
				$view->begin();
				try {
					$target = Rules::serves( $view, $post_type, $start, $trace );
					if ( null === $target || ! $view->guessed() ) {
						$out[ $start ] = null !== $target ? (int) $target->ID : 0;
						continue;
					}
				} catch ( OutOfBoundsException $unread ) {
					// The facts it asked for are read next; it runs again then.
					unset( $unread );
				}
				$view->keep();
				$next[ $start ] = [] !== $trace ? (string) $trace[ count( $trace ) - 1 ][2] : $start;
			}
			$pending = $next;
		}

		foreach ( array_keys( $pending ) as $start ) {
			$out[ (string) $start ] = 0;
		}
		return $out;
	}

	/**
	 * Primes posts and their ancestors, one query per level at most.
	 *
	 * @param int[] $ids Post IDs.
	 */
	private static function prime_with_ancestors( array $ids ): void {
		for ( $depth = 0; [] !== $ids && $depth < 50; $depth++ ) {
			_prime_post_caches( $ids, false, false );
			$parents = [];
			foreach ( $ids as $id ) {
				$post = get_post( $id );
				if ( $post instanceof WP_Post && (int) $post->post_parent > 0 ) {
					$parents[ (int) $post->post_parent ] = true;
				}
			}
			$ids = array_keys( $parents );
		}
	}
}
