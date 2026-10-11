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
use HierarchyGuard\Subtree;
use WP_Post;

/**
 * Lists the old URLs a change left behind and where each leads now.
 *
 * A block starts at one old path (Pairs_Blocks). Its head is the post that
 * path leads to, or none. Below it come the published pages that lived under
 * the old path when it was left, each at the path it had then (Rebuild),
 * depth first in menu order. Every block of a change is walked together, one
 * query pair per level, and no walk goes past the count cap or the server's
 * segment cap. Last, every pair is checked against a model of the server
 * (Batch_Resolver): the request must be a 404 by the site's rewrite rules as
 * Rules::is_404() reads them, and the resolver's rules must lead to the
 * pair's page. A pair the model cannot vouch for is left out. Nothing is
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
			fn(): array => $this->collect( [ Pairs_Blocks::post_block( $post, $old ) ], (string) $post->post_type )
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
			fn(): array => $this->collect( Pairs_Blocks::change_blocks( $change ), (string) ( $change['post_type'] ?? 'page' ) )
		);
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
	 * by Rebuild::listed_then(). A deleted root lists each subpage at its slug from
	 * before the move. An old path the ledger gives to another live post leads
	 * to that post, so it is dropped here with the pages walked below it: that
	 * post's own list has them. A deleted root's old path leads to its target
	 * only while that target holds it, so with no live holder it is
	 * unresolved, while its subpages are still listed. When the newest live
	 * holder of a block's own old path is another page outside the trash, the
	 * whole block is unresolved. Last, each pair is checked against what the
	 * resolver serves.
	 *
	 * @param array<int,array<string,mixed>> $blocks    Blocks.
	 * @param string                         $post_type Post type name.
	 * @return array{post_type:string,pairs:array<int,array{old:string,id:int}>,unresolved:array<int,array{old:string,reason:string}>,capped:bool}
	 */
	public function collect( array $blocks, string $post_type ): array {
		$cap        = Subtree::count_cap();
		$unresolved = [];
		$open       = self::open_blocks( $blocks, $post_type );
		$in_use     = self::in_use( $open, $post_type, $unresolved );
		$tree       = $this->walk( $open, $post_type, $cap );
		$history    = self::history_of( $open, $tree, $post_type );
		$listing    = self::listing( $open, $in_use, $tree, $history, $cap, $unresolved );

		// The ledger as the resolver reads it, for every prefix of every path
		// listed, in one query.
		$olds  = array_merge( array_column( $listing['pairs'], 'old' ), array_map( static fn( array $block ): string => (string) $block['old'], $open ) );
		$facts = Batch_Resolver::ledger_facts( Batch_Resolver::prefixes( $olds ), $post_type );
		$gone  = self::dropped( $open, $listing, Batch_Resolver::holders( $olds, $facts ) );

		return [
			'post_type'  => $post_type,
			'pairs'      => self::served_only( $open, $listing, $gone, $post_type, $facts, $unresolved ),
			'unresolved' => $unresolved,
			'capped'     => $listing['capped'] || $tree['capped'] || ! empty( $history['capped'] ),
		];
	}

	/**
	 * The blocks that can list anything: their old path has a URL, leads
	 * somewhere else than where it is, and their pages are not under the trash.
	 *
	 * @param array<int,array<string,mixed>> $blocks    Blocks.
	 * @param string                         $post_type Post type name.
	 * @return array<int,array<string,mixed>> Blocks by index.
	 */
	private static function open_blocks( array $blocks, string $post_type ): array {
		$open = [];
		foreach ( $blocks as $index => $block ) {
			$old  = (string) $block['old'];
			$base = (string) $block['base'];
			if ( '' === $old || '' === Paths::url( $post_type, $old ) || $old === $base || Paths::has_trashed_segment( $base ) ) {
				continue;
			}
			$open[ $index ] = $block;
		}
		return $open;
	}

	/**
	 * The blocks whose old path a live page uses now, each added as unresolved.
	 *
	 * @param array<int,array<string,mixed>>             $open       Blocks by index.
	 * @param string                                     $post_type  Post type name.
	 * @param array<int,array{old:string,reason:string}> $unresolved Unresolved rows; one is added per block.
	 * @return array<int,true> By block index.
	 */
	private static function in_use( array $open, string $post_type, array &$unresolved ): array {
		$owners = Batch_Resolver::owners( array_map( static fn( array $block ): string => (string) $block['old'], $open ), $post_type );
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
		}
		return $in_use;
	}

	/**
	 * What the rebuild reads for the blocks that rebuild: the ledger entries and
	 * trash markers of every page walked and every post a block is recorded on,
	 * and of the pages in the trash those markers name.
	 *
	 * @param array<int,array<string,mixed>>                                                                                              $open      Blocks by index.
	 * @param array{tops:array<int,array<int,array<string,mixed>>>,kids:array<int,array<int,array<string,mixed>>>,ids:int[],capped:bool} $tree      The walk.
	 * @param string                                                                                                                      $post_type Post type name.
	 * @return array<string,array<int,mixed>> From Rebuild::history(), or empty when no block rebuilds.
	 */
	private static function history_of( array $open, array $tree, string $post_type ): array {
		$others = [];
		foreach ( $open as $block ) {
			if ( ! empty( $block['rebuild'] ) ) {
				$others = array_merge( $others, array_filter( [ (int) ( $block['post'] ?? $block['recorded_on'] ?? 0 ) ] ), array_map( 'intval', (array) ( $block['virtual'] ?? [] ) ) );
			}
		}
		if ( [] === $others ) {
			return [];
		}
		// The pages above the subpages walked that are not walked themselves, as where a moved subpage lives now.
		$walked = array_flip( $tree['ids'] );
		foreach ( array_merge( array_merge( [], ...array_values( $tree['tops'] ) ), array_merge( [], ...array_values( $tree['kids'] ) ) ) as $node ) {
			if ( (int) $node['parent'] > 0 && ! isset( $walked[ (int) $node['parent'] ] ) ) {
				$others[] = (int) $node['parent'];
			}
		}
		return Rebuild::history( $tree['ids'], $others, $post_type );
	}

	/**
	 * Each block's head and the pages listed below it, up to the cap, with the
	 * pairs of the block above each pair.
	 *
	 * @param array<int,array<string,mixed>>             $open       Blocks by index.
	 * @param array<int,true>                            $in_use     Blocks whose old path a live page uses, by index.
	 * @param array<string,mixed>                        $tree       The walk.
	 * @param array<string,array<int,mixed>>             $history    From history_of().
	 * @param int                                        $cap        Most pairs.
	 * @param array<int,array{old:string,reason:string}> $unresolved Unresolved rows; a head with no new URL is added.
	 * @return array{pairs:array<int,array{old:string,id:int}>,lineage:array<int,int[]>,block_of:array<int,int>,held:array<int,true>,headless:array<int,true>,capped:bool}
	 */
	private static function listing( array $open, array $in_use, array $tree, array $history, int $cap, array &$unresolved ): array {
		$capped   = false;
		$pairs    = [];
		$lineage  = [];
		$block_of = [];
		$held     = [];
		$headless = [];
		foreach ( $open as $block ) {
			$capped = $capped || ! empty( $block['incomplete'] );
		}
		foreach ( $open as $index => $block ) {
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

			$alone  = false;
			$listed = ! empty( $block['rebuild'] )
				? Rebuild::listed_then( $block + [ 'index' => $index ], $tree, $history, null, $alone )
				: self::listed_by_segment( $block, $tree['tops'][ $index ] ?? [], $tree['kids'] );
			$capped = $capped || $alone;
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

		return [
			'pairs'    => $pairs,
			'lineage'  => $lineage,
			'block_of' => $block_of,
			'held'     => $held,
			'headless' => $headless,
			'capped'   => $capped,
		];
	}

	/**
	 * The pairs the ledger gives to another post, with the pages listed below
	 * them, and the deleted roots' old paths no live page holds.
	 *
	 * The resolver rewrites an old path through the newest live post that
	 * holds its longest known prefix. When that post for a block's own old path
	 * is another page outside the trash, the block leads there: it is void, and
	 * that page's own list has what sits under it. When that post sits under
	 * the trash the resolver passes over it to a shorter prefix, so the served
	 * check decides, and an old path that leads nowhere is in the trash.
	 *
	 * @param array<int,array<string,mixed>>           $open    Blocks by index.
	 * @param array<string,mixed>                      $listing From listing().
	 * @param array<string,array{id:int,trashed:bool}> $holders From Batch_Resolver::holders().
	 * @return array{void:array<int,true>,trashed:array<int,true>,dropped:array<int,true>,unheld:array<int,true>} Blocks by index, then pairs by index.
	 */
	private static function dropped( array $open, array $listing, array $holders ): array {
		$void    = [];
		$trashed = [];
		foreach ( $open as $index => $block ) {
			$holder = $holders[ (string) $block['old'] ] ?? null;
			if ( null === $holder ) {
				continue;
			}
			if ( $holder['trashed'] ) {
				$trashed[ $index ] = true;
			} elseif ( (int) ( $block['target'] ?? 0 ) !== $holder['id'] ) {
				$void[ $index ] = true;
			}
		}

		// An old path another live post holds leads to that post, and its own
		// panel lists what sits under it: drop it here, with the pages walked
		// below it. A holder under the trash holds nothing for the resolver.
		$dropped = [];
		$unheld  = [];
		foreach ( $listing['pairs'] as $i => $pair ) {
			$found  = $holders[ $pair['old'] ] ?? null;
			$holder = null !== $found && ! $found['trashed'] ? $found['id'] : 0;
			if ( isset( $void[ $listing['block_of'][ $i ] ?? -1 ] ) || ( $holder > 0 && $holder !== $pair['id'] ) || [] !== array_intersect_key( array_flip( $listing['lineage'][ $i ] ?? [] ), $dropped ) ) {
				$dropped[ $i ] = true;
			} elseif ( isset( $listing['held'][ $i ] ) && 0 === $holder ) {
				// No live page holds a deleted root's old path, so it leads nowhere; its subpages may still redirect.
				$unheld[ $i ] = true;
			}
		}

		return [
			'void'    => $void,
			'trashed' => $trashed,
			'dropped' => $dropped,
			'unheld'  => $unheld,
		];
	}

	/**
	 * The pairs left that the server serves, and a row for each block's own
	 * old path that has no new URL here.
	 *
	 * Each pair is checked by Batch_Resolver::served(): a 404 at the old path
	 * by the 404 model, rewritten round by round to that page, with no type
	 * the server tries first taking it. A published page at the old path, as
	 * under a hidden page there now, is reached before any redirect.
	 * What the resolver does not serve is not listed; a block's own old path it
	 * serves elsewhere, or that something else is shown at, is in use, and one
	 * it serves nowhere leads nowhere, or is in the trash when the newest live
	 * page that held it sits under the trash.
	 *
	 * @param array<int,array<string,mixed>>             $open       Blocks by index.
	 * @param array<string,mixed>                        $listing    From listing().
	 * @param array<string,array<int,true>>              $gone       From dropped().
	 * @param string                                     $post_type  Post type name.
	 * @param array<string,array{live:int,held:bool}>    $facts      Ledger facts read so far, by path.
	 * @param array<int,array{old:string,reason:string}> $unresolved Unresolved rows; one is added per old path with no new URL.
	 * @return array<int,array{old:string,id:int}>
	 */
	private static function served_only( array $open, array $listing, array $gone, string $post_type, array $facts, array &$unresolved ): array {
		$pairs = $listing['pairs'];
		$left  = array_diff_key( $pairs, $gone['dropped'], $gone['unheld'] );
		$heads = [];
		foreach ( array_keys( $gone['void'] ) as $index ) {
			if ( ! isset( $listing['headless'][ $index ] ) ) {
				$heads[ $index ] = (string) $open[ $index ]['old'];
			}
		}
		$served = Batch_Resolver::served( array_values( array_unique( array_merge( array_column( $left, 'old' ), array_values( $heads ) ) ) ), $post_type, $facts );
		$reason = static fn( int $target, bool $trashed ): string => 0 !== $target ? 'in_use' : ( $trashed ? 'trash' : 'none' );

		foreach ( $heads as $old ) {
			$unresolved[] = [
				'old'    => $old,
				'reason' => $reason( $served[ $old ] ?? 0, false ),
			];
		}
		foreach ( array_keys( $gone['unheld'] ) as $i ) {
			$unresolved[] = [
				'old'    => $pairs[ $i ]['old'],
				'reason' => isset( $gone['trashed'][ $listing['block_of'][ $i ] ?? -1 ] ) ? 'trash' : 'none',
			];
		}
		$dropped = $gone['dropped'];
		foreach ( $left as $i => $pair ) {
			$target = $served[ $pair['old'] ] ?? 0;
			if ( $target === $pair['id'] ) {
				continue;
			}
			$dropped[ $i ] = true;
			if ( ! isset( $listing['lineage'][ $i ] ) ) {
				$unresolved[] = [
					'old'    => $pair['old'],
					'reason' => $reason( $target, isset( $gone['trashed'][ $listing['block_of'][ $i ] ?? -1 ] ) ),
				];
			}
		}
		return array_values( array_diff_key( $pairs, $dropped, $gone['unheld'] ) );
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
	 * Every block's pages, level by level for all blocks at once, within the
	 * budget. Under a block the walk goes no deeper than the server's segment
	 * cap leaves room for below its old path: a deeper old path is never
	 * looked up, so it can list nothing.
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
		$room    = Batch_Resolver::room( $type );
		// How many levels below its old path each block's pages may sit.
		$levels = [];
		foreach ( $blocks as $index => $block ) {
			$levels[ $index ] = $room - ( substr_count( (string) $block['old'], '/' ) + 1 );
		}
		foreach ( $blocks as $index => $block ) {
			foreach ( (array) $block['children'] as $node ) {
				$tops[ $index ][] = $node;
				++$visited;
			}
			if ( (int) $block['from'] > 0 && $levels[ $index ] >= 1 ) {
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

		// The levels each page read may still have below it.
		$kids  = [];
		$below = [];
		foreach ( $tops as $index => $nodes ) {
			foreach ( $nodes as $node ) {
				$below[ (int) $node['id'] ] = max( $below[ (int) $node['id'] ] ?? PHP_INT_MIN, $levels[ $index ] - 1 );
			}
		}
		$level = array_keys( array_filter( $below, static fn( int $left ): bool => $left >= 1 ) );
		$depth = 0;
		while ( [] !== $level && $visited <= $cap && $depth < 50 ) {
			++$depth;
			$rows = Subtree::ordered_children( $level, $type, $cap + 1 - $visited );
			$next = [];
			foreach ( $rows as $row ) {
				$node                      = self::node( $row, (string) $row['post_name'] );
				$kids[ $node['parent'] ][] = $node;
				$next[ $node['id'] ]       = max( $next[ $node['id'] ] ?? PHP_INT_MIN, $below[ $node['parent'] ] - 1 );
			}
			$visited += count( $rows );
			$below    = $next;
			$level    = array_keys( array_filter( $below, static fn( int $left ): bool => $left >= 1 ) );
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
	 * A tree node from a posts row.
	 *
	 * @param array<string,mixed> $row     Row with ID, post_parent, post_name, post_status.
	 * @param string              $segment The node's segment in the old path.
	 * @return array{id:int,parent:int,segment:string,slug:string,status:string}
	 */
	public static function node( array $row, string $segment ): array {
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
}
