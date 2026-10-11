<?php
/**
 * The tree under an old path as it was when the path was left.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Redirects;

use HierarchyGuard\Ledger;
use HierarchyGuard\Paths;
use HierarchyGuard\Subtree;
use HierarchyGuard\Trash\Markers;
use WP_Post;

/**
 * Rebuilds where each walked page was at a date from what happened since,
 * newest first: each ledger entry of a page is where it was before it moved
 * or was renamed, and each trash marker puts a subpage back under the page in
 * the trash it left. history() reads those facts; everything else works on
 * arrays alone.
 */
final class Rebuild {

	/**
	 * Ledger entries, markers and desired slugs read per page the count cap allows.
	 */
	public const ROWS_PER_PAGE = 20;

	/**
	 * Most passes one sweep rebuilds on their own, each by a rewind over every
	 * event from its date: past them the list is incomplete.
	 */
	public const ALONE_PASSES = 10;

	/**
	 * Each tracked page's place, during one rewind.
	 *
	 * @var array<int,array{parent:int,above:string,slug:string}>
	 */
	private array $places;

	/**
	 * The tracked pages by the last segment of their slug, during one rewind.
	 *
	 * @var array<string,array<int,true>>
	 */
	private array $named = [];

	/**
	 * The tracked pages by parent, during one rewind.
	 *
	 * @var array<int,array<int,true>>
	 */
	private array $kids = [];

	/**
	 * The pages in the trash, by ID, during one rewind.
	 *
	 * @var array<int,mixed>
	 */
	private array $virtual = [];

	/**
	 * The pages put at a new place since moved() was last asked.
	 *
	 * @var array<int,true>
	 */
	private array $moved = [];

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
	 * Every date is rebuilt in one sweep, newest first; see sweep().
	 *
	 * @param array<string,mixed>                                                                                $block   Block.
	 * @param array{tops:array<int,array<int,array<string,mixed>>>,kids:array<int,array<int,array<string,mixed>>>} $tree    The walk.
	 * @param array<string,array<int,mixed>>                                                                     $history From history().
	 * @param callable|null                                                                                      $above   The path above an untracked parent, by its ID; above() when null.
	 * @param bool                                                                                               $capped  Set to true when more passes than ALONE_PASSES were to be rebuilt on their own.
	 * @return array<int,array{0:string,1:int}> Old path and page ID, published pages only, depth first in menu order.
	 */
	public static function listed_then( array $block, array $tree, array $history, ?callable $above = null, bool &$capped = false ): array {
		[ $nodes, $order ] = self::walked( $block, $tree );
		$places            = self::places( $block, $nodes, $history, $above );
		return self::sweep( $places, $history, self::passes( $block, $nodes, $history ), (string) $block['old'], $nodes, $order, $capped );
	}

	/**
	 * The pages a block's walk reached, depth first in menu order.
	 *
	 * @param array<string,mixed> $block Block.
	 * @param array<string,mixed> $tree  The walk.
	 * @return array{0:array<int,array<string,mixed>>,1:int[]} The nodes by ID, and their IDs in order.
	 */
	public static function walked( array $block, array $tree ): array {
		$nodes = [];
		$order = [];
		$stack = array_reverse( $tree['tops'][ (int) $block['index'] ] ?? [] );
		while ( [] !== $stack ) {
			$node                 = array_pop( $stack );
			$nodes[ $node['id'] ] = $node;
			$order[]              = $node['id'];
			foreach ( array_reverse( $tree['kids'][ $node['id'] ] ?? [] ) as $kid ) {
				$stack[] = $kid;
			}
		}
		return [ $nodes, $order ];
	}

	/**
	 * Where each page is now: its parent and slug, up to the top level. A
	 * parent nobody tracks becomes the path above the page.
	 *
	 * @param array<string,mixed>            $block   Block.
	 * @param array<int,array<string,mixed>> $nodes   The pages walked, by ID.
	 * @param array<string,array<int,mixed>> $history From history().
	 * @param callable|null                  $above   The path above an untracked parent, by its ID; above() when null.
	 * @return array<int,array{parent:int,above:string,slug:string}>
	 */
	public static function places( array $block, array $nodes, array $history, ?callable $above = null ): array {
		$above  = $above ?? static fn( int $parent_id ): string => self::above( $parent_id );
		$post   = (int) ( $block['post'] ?? $block['recorded_on'] );
		$places = $history['places'];
		foreach ( $nodes as $id => $node ) {
			$places[ $id ] = [
				'parent' => (int) $node['parent'],
				'above'  => '',
				'slug'   => (string) $node['slug'],
			];
		}
		if ( $post > 0 && ! isset( $places[ $post ] ) ) {
			$places[ $post ] = self::place( (string) $block['base'] );
		}
		foreach ( $places as $id => $place ) {
			if ( $place['parent'] > 0 && ! isset( $places[ $place['parent'] ] ) ) {
				$places[ $id ]['above']  = $above( $place['parent'] );
				$places[ $id ]['parent'] = 0;
			}
		}
		return $places;
	}

	/**
	 * The dates to rebuild at, each with the page that left the old path then:
	 * the post's own entry for the path for its own subpages; for each page in
	 * the trash that left it, its first move up and, on a post's old path, its
	 * own entry for the path, since it may have left the path before its trash too.
	 *
	 * @param array<string,mixed>            $block   Block.
	 * @param array<int,array<string,mixed>> $nodes   The pages walked, by ID.
	 * @param array<string,array<int,mixed>> $history From history().
	 * @return array<int,array{0:int,1:int}> Anchor and meta ID, in the order their pairs are listed.
	 */
	public static function passes( array $block, array $nodes, array $history ): array {
		$old    = (string) $block['old'];
		$post   = (int) ( $block['post'] ?? $block['recorded_on'] );
		$passes = [];
		if ( (int) $block['from'] > 0 && $post === (int) $block['from'] ) {
			$entry    = self::entry_id( $history['entries'][ $post ] ?? [], $old );
			$passes[] = [ $post, $entry > 0 ? $entry : PHP_INT_MAX ];
		}
		$first = self::first_moves( $history );
		$seen  = [];
		foreach ( $nodes as $node ) {
			$holder = (int) ( $node['holder'] ?? 0 );
			if ( $holder <= 0 || isset( $seen[ $holder ] ) ) {
				continue;
			}
			$seen[ $holder ] = true;
			$date            = $first[ $holder ] ?? PHP_INT_MAX;
			if ( PHP_INT_MAX !== $date ) {
				$passes[] = [ $holder, $date ];
			}
			$entry = (int) ( $block['recorded_on'] ?? 0 ) > 0 ? self::entry_id( $history['entries'][ $holder ] ?? [], $old ) : 0;
			if ( $entry > 0 && $entry < $date ) {
				$passes[] = [ $holder, $entry ];
			}
		}
		return $passes;
	}

	/**
	 * A place for a page at a path whose parent is not tracked.
	 *
	 * @param string $path Normalized path.
	 * @return array{parent:int,above:string,slug:string}
	 */
	public static function place( string $path ): array {
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
	public static function rewind( array $places, array $history, int $date, int $anchor, string $old ): array {
		[ $events, $wrote ] = self::events( $places, $history, $date );
		$state              = new self( $places, $history['virtual'] );
		$none               = [];
		foreach ( $events as $event ) {
			$state->take_back( $event, $wrote, $old, $anchor, $none );
		}
		if ( $state->path( $anchor ) !== $old ) {
			$state->set( $anchor, self::place( $old ) );
		}

		$out = [];
		foreach ( array_keys( $state->places ) as $id ) {
			$out[ $id ] = $state->path( $id );
		}
		return $out;
	}

	/**
	 * What rewind() lists for each date, in one sweep.
	 *
	 * The dates are taken newest first, and each takes back the events the one
	 * before took back, then more: so every event is taken back once. At each
	 * date only the walked pages under a page that moved since the date before
	 * get their path again. A pass lists each published page under the old path,
	 * its anchor at the old path itself; the first pass, in their own order, to
	 * list a path keeps it, with the first page in walk order there. One event
	 * reads differently for the pass whose anchor it belongs to: the anchor's
	 * entry for the old path below its own path then. Such a pass is rebuilt
	 * on its own by rewind(), over every event from its date: the first
	 * ALONE_PASSES of them in their own order are, and past that the list is
	 * capped.
	 *
	 * @param array<int,array{parent:int,above:string,slug:string}> $places  Each page's place now, by ID.
	 * @param array<string,array<int,mixed>>                        $history From history().
	 * @param array<int,array{0:int,1:int}>                         $passes  From passes().
	 * @param string                                                $old     The old path.
	 * @param array<int,array<string,mixed>>                        $nodes   The pages walked, by ID.
	 * @param int[]                                                 $order   Their IDs, depth first in menu order.
	 * @param bool                                                  $capped  Set to true when passes past ALONE_PASSES were left out.
	 * @return array<int,array{0:string,1:int}> Old path and page ID, published pages only.
	 */
	private static function sweep( array $places, array $history, array $passes, string $old, array $nodes, array $order, bool &$capped = false ): array {
		if ( [] === $passes ) {
			return [];
		}
		[ $events, $wrote ] = self::events( $places, $history, min( array_column( $passes, 1 ) ) );
		// Newest date first; equal dates in their own order.
		$sweep = array_keys( $passes );
		usort( $sweep, static fn( int $a, int $b ): int => [ $passes[ $b ][1], $a ] <=> [ $passes[ $a ][1], $b ] );
		$pending = [];
		foreach ( $passes as $k => [ $anchor ] ) {
			$pending[ $anchor ][ $k ] = true;
		}

		$state = new self( $places, $history['virtual'] );
		// A page the walk reached twice ranks where it was first reached.
		$rank = [];
		foreach ( $order as $at => $id ) {
			$rank[ $id ] = $rank[ $id ] ?? $at;
		}
		$held   = [];
		$spans  = [];
		$points = [];
		$alone  = [];
		foreach ( $order as $id ) {
			$held[ $id ] = [ $state->path( $id ), 0 ];
		}
		$next = 0;
		$last = count( $events );
		foreach ( $sweep as $s => $k ) {
			[ $anchor, $date ] = $passes[ $k ];
			for ( ; $next < $last && $events[ $next ][0] >= $date; $next++ ) {
				$state->take_back( $events[ $next ], $wrote, $old, null, $pending, $alone );
			}
			foreach ( $state->moved( $rank ) as $id ) {
				$path = $state->path( $id );
				if ( $path !== $held[ $id ][0] ) {
					$spans[]     = [ $id, $held[ $id ][0], $held[ $id ][1], $s - 1 ];
					$held[ $id ] = [ $path, $s ];
				}
			}
			if ( $state->path( $anchor ) !== $old ) {
				// The anchor ends at the old path itself, for this date only.
				foreach ( $state->under( $anchor, $old, $rank ) as $id => $path ) {
					$spans[]        = [ $id, $held[ $id ][0], $held[ $id ][1], $s - 1 ];
					$points[]       = [ $id, $path, $s ];
					$held[ $id ][1] = $s + 1;
				}
			}
			unset( $pending[ $anchor ][ $k ] );
		}
		foreach ( $held as $id => [ $path, $from ] ) {
			$spans[] = [ $id, $path, $from, count( $sweep ) - 1 ];
		}

		// The pass each date belongs to; a pass rebuilt on its own lists nothing from the sweep.
		$ranks = [];
		foreach ( $sweep as $s => $k ) {
			$ranks[ $s ] = isset( $alone[ $k ] ) ? PHP_INT_MAX : $k;
		}
		$lowest = self::lowest( $ranks );
		$best   = [];
		$keep   = static function ( string $path, int $pass, int $id ) use ( &$best, $nodes, $rank, $old ): void {
			if ( PHP_INT_MAX === $pass || 'publish' !== $nodes[ $id ]['status'] || ! str_starts_with( $path, $old . '/' ) ) {
				return;
			}
			$at = [ $pass, $rank[ $id ] ];
			if ( ! isset( $best[ $path ] ) || $at < [ $best[ $path ][0], $best[ $path ][1] ] ) {
				$best[ $path ] = [ $pass, $rank[ $id ], $id ];
			}
		};
		foreach ( $spans as [ $id, $path, $from, $to ] ) {
			if ( $from <= $to ) {
				$keep( $path, $lowest( $from, $to ), $id );
			}
		}
		foreach ( $points as [ $id, $path, $s ] ) {
			$keep( $path, $ranks[ $s ], $id );
		}
		// The passes listed first keep their paths first, so they are the ones rebuilt.
		$alone = array_keys( $alone );
		sort( $alone );
		if ( count( $alone ) > self::ALONE_PASSES ) {
			$capped = true;
			$alone  = array_slice( $alone, 0, self::ALONE_PASSES );
		}
		foreach ( $alone as $k ) {
			$then = self::rewind( $places, $history, $passes[ $k ][1], $passes[ $k ][0], $old );
			foreach ( $order as $id ) {
				$keep( $then[ $id ] ?? '', $k, $id );
			}
		}

		uasort( $best, static fn( array $a, array $b ): int => [ $a[0], $a[1] ] <=> [ $b[0], $b[1] ] );
		$out = [];
		foreach ( $best as $path => [ , , $id ] ) {
			$out[] = [ (string) $path, (int) $id ];
		}
		return $out;
	}

	/**
	 * The lowest value in any run of a list, from a table of the lowest in
	 * each run whose length is a power of two.
	 *
	 * @param int[] $values Values by position from 0.
	 * @return callable(int,int):int The lowest value from one position to another, both included.
	 */
	private static function lowest( array $values ): callable {
		$table = [ array_values( $values ) ];
		$count = count( $values );
		for ( $level = 1; ( 1 << $level ) <= $count; $level++ ) {
			$half = 1 << ( $level - 1 );
			for ( $i = 0; $i + ( 1 << $level ) <= $count; $i++ ) {
				$table[ $level ][ $i ] = min( $table[ $level - 1 ][ $i ], $table[ $level - 1 ][ $i + $half ] );
			}
		}
		return static function ( int $from, int $to ) use ( $table ): int {
			$level = 0;
			while ( ( 2 << $level ) <= $to - $from + 1 ) {
				++$level;
			}
			return min( $table[ $level ][ $from ], $table[ $level ][ $to - ( 1 << $level ) + 1 ] );
		};
	}

	/**
	 * The events from a date on, newest first, and the aliases pages in the
	 * trash wrote. An entry is where a page was before it moved or was renamed;
	 * one a page in the trash got after its first move up is a fact, since it
	 * could not move. A marker puts a subpage back under the page in the trash
	 * it left.
	 *
	 * @param array<int,array{parent:int,above:string,slug:string}> $places  Each page's place now, by ID.
	 * @param array<string,array<int,mixed>>                        $history From history().
	 * @param int                                                   $date    Meta ID; the events from it on.
	 * @return array{0:array<int,array{0:int,1:int,2:string,3:int}>,1:array<int,array<string,true>>} Events as meta ID, page, path and kind (0 an entry, -1 a fact, else the page in the trash), and the aliases by page and path.
	 */
	private static function events( array $places, array $history, int $date ): array {
		$trashed = self::first_moves( $history );
		$events  = [];
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

		$wrote = [];
		foreach ( $history['virtual'] as $root ) {
			foreach ( $root['aliases'] as $alias ) {
				$wrote[ (int) $alias['to'] ][ (string) $alias['path'] ] = true;
			}
		}
		return [ $events, $wrote ];
	}

	/**
	 * Each page in the trash's first move up, by page: its earliest marker.
	 *
	 * @param array<string,array<int,mixed>> $history From history().
	 * @return array<int,int> Meta ID by page in the trash.
	 */
	private static function first_moves( array $history ): array {
		$first = [];
		foreach ( $history['markers'] as $marks ) {
			foreach ( $marks as $mark ) {
				$first[ $mark['root'] ] = min( $first[ $mark['root'] ] ?? PHP_INT_MAX, $mark['id'] );
			}
		}
		return $first;
	}

	/**
	 * A rewind's pages, each at its place now.
	 *
	 * @param array<int,array{parent:int,above:string,slug:string}> $places  Each page's place now, by ID.
	 * @param array<int,mixed>                                      $virtual The pages in the trash, by ID.
	 */
	private function __construct( array $places, array $virtual = [] ) {
		$this->places  = $places;
		$this->virtual = $virtual;
		foreach ( $places as $id => $place ) {
			// The pages by the last segment of their slug, so a path is looked up among a few.
			$this->named[ basename( $place['slug'] ) ][ $id ] = true;
			if ( $place['parent'] > 0 ) {
				$this->kids[ $place['parent'] ][ $id ] = true;
			}
		}
	}

	/**
	 * Takes one event back.
	 *
	 * @param array{0:int,1:int,2:string,3:int} $event   From events().
	 * @param array<int,array<string,true>>     $wrote   The aliases pages in the trash wrote, by page and path.
	 * @param string                            $old     The old path.
	 * @param int|null                          $anchor  The page that left the old path then, or null in a sweep.
	 * @param array<int,array<int,true>>        $pending In a sweep, the passes not listed yet, by anchor.
	 * @param array<int,true>                   $alone   In a sweep, the passes to rebuild on their own; added to.
	 */
	private function take_back( array $event, array $wrote, string $old, ?int $anchor, array $pending, array &$alone = [] ): void {
		[ , $id, $was, $root ] = $event;
		if ( $root > 0 ) {
			// The trash of that page moved this subpage up: before, it lived under it with its old slug.
			if ( ! $this->below( $root, $id ) ) {
				$this->set(
					$id,
					[
						'parent' => $root,
						'above'  => '',
						'slug'   => '' !== $was ? $was : $this->places[ $id ]['slug'],
					]
				);
			}
			return;
		}
		if ( isset( $wrote[ $id ][ $was ] ) ) {
			return;
		}
		// An entry below the page's own path is an alias for subpages that moved up to it; the old path itself is where the anchor was.
		if ( str_starts_with( $was, $this->path( $id ) . '/' ) ) {
			if ( $was !== $old ) {
				return;
			}
			if ( null === $anchor ) {
				foreach ( array_keys( $pending[ $id ] ?? [] ) as $k ) {
					$alone[ $k ] = true;
				}
				return;
			}
			if ( $id !== $anchor ) {
				return;
			}
		}
		$this->was_at( $id, $was, $root < 0 );
	}

	/**
	 * The walked pages under a page that moved since the last call.
	 *
	 * @param array<int,int> $walked Rank by ID of the pages walked.
	 * @return int[]
	 */
	private function moved( array $walked ): array {
		$out   = [];
		$seen  = [];
		$queue = array_keys( $this->moved );
		while ( [] !== $queue ) {
			$id = (int) array_pop( $queue );
			if ( isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;
			if ( isset( $walked[ $id ] ) ) {
				$out[] = $id;
			}
			foreach ( array_keys( $this->kids[ $id ] ?? [] ) as $kid ) {
				$queue[] = $kid;
			}
		}
		$this->moved = [];
		return $out;
	}

	/**
	 * The walked pages at and under a page, each at its path with that page at
	 * the old path.
	 *
	 * @param int            $anchor The page.
	 * @param string         $old    The old path.
	 * @param array<int,int> $walked Rank by ID of the pages walked.
	 * @return array<int,string> Path by ID.
	 */
	private function under( int $anchor, string $old, array $walked ): array {
		$out   = [];
		$seen  = [];
		$queue = [ [ $anchor, $old ] ];
		while ( [] !== $queue ) {
			[ $id, $path ] = array_pop( $queue );
			if ( isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;
			if ( isset( $walked[ $id ] ) ) {
				$out[ $id ] = $path;
			}
			foreach ( array_keys( $this->kids[ $id ] ?? [] ) as $kid ) {
				$slug    = $this->places[ $kid ]['slug'];
				$queue[] = [ $kid, '' !== $slug ? $path . '/' . $slug : $path ];
			}
		}
		return $out;
	}

	/**
	 * A tracked page's path, from its place and the places above it.
	 *
	 * @param int $id Page ID.
	 * @return string
	 */
	private function path( int $id ): string {
		$segments = [];
		for ( $depth = 0; $id > 0 && isset( $this->places[ $id ] ) && $depth < 50; $depth++ ) {
			if ( '' !== $this->places[ $id ]['slug'] ) {
				array_unshift( $segments, $this->places[ $id ]['slug'] );
			}
			if ( 0 === $this->places[ $id ]['parent'] ) {
				if ( '' !== $this->places[ $id ]['above'] ) {
					array_unshift( $segments, $this->places[ $id ]['above'] );
				}
				break;
			}
			$id = $this->places[ $id ]['parent'];
		}
		return implode( '/', $segments );
	}

	/**
	 * Whether a tracked page is a page or sits under it.
	 *
	 * @param int $id Page ID.
	 * @param int $of The page it may sit under.
	 * @return bool
	 */
	private function below( int $id, int $of ): bool {
		for ( $depth = 0; $id > 0 && isset( $this->places[ $id ] ) && $depth < 50; $depth++ ) {
			if ( $id === $of ) {
				return true;
			}
			$id = $this->places[ $id ]['parent'];
		}
		return false;
	}

	/**
	 * Puts a tracked page at a place.
	 *
	 * @param int                                        $id    Page ID.
	 * @param array{parent:int,above:string,slug:string} $place Its place.
	 */
	private function set( int $id, array $place ): void {
		unset( $this->named[ basename( $this->places[ $id ]['slug'] ) ][ $id ], $this->kids[ $this->places[ $id ]['parent'] ][ $id ] );
		$this->places[ $id ]                              = $place;
		$this->named[ basename( $place['slug'] ) ][ $id ] = true;
		if ( $place['parent'] > 0 ) {
			$this->kids[ $place['parent'] ][ $id ] = true;
		}
		$this->moved[ $id ] = true;
	}

	/**
	 * The tracked page at a path, other than a page or one under it, or 0.
	 *
	 * @param string $path Normalized path.
	 * @param int    $id   The page.
	 * @return int
	 */
	private function at( string $path, int $id ): int {
		$trashed = 0;
		foreach ( array_keys( $this->named[ basename( $path ) ] ?? [] ) as $other ) {
			if ( $other !== $id && $this->path( $other ) === $path && ! $this->below( $other, $id ) ) {
				// A page in the trash stands where it was before its trash; a page outside it at the same path was there then.
				if ( ! isset( $this->virtual[ $other ] ) ) {
					return $other;
				}
				$trashed = 0 !== $trashed ? $trashed : $other;
			}
		}
		return $trashed;
	}

	/**
	 * A page was at a path before a time: where its parent was too, if it was only somewhere else.
	 *
	 * @param int    $id   Page ID.
	 * @param string $was  The path it had.
	 * @param bool   $fact Whether it could not have moved then, being in the trash.
	 */
	private function was_at( int $id, string $was, bool $fact ): void {
		$now = $this->path( $id );
		if ( $was === $now ) {
			return;
		}
		$place = self::place( $was );
		$up    = $this->places[ $id ]['parent'];
		if ( $fact ) {
			// It did not move, so its parent was where the path says, unless the
			// parent then was another page: core moves a page in the trash up
			// when its parent is deleted for good.
			$above = basename( $place['above'] );
			if ( $up > 0 && ( $this->places[ $up ]['slug'] === $above || 1 === preg_match( '/^' . preg_quote( $above, '/' ) . '-\d+$/', $this->places[ $up ]['slug'] ) ) ) {
				$this->was_at( $up, $place['above'], true );
				$this->set( $id, [ 'slug' => $place['slug'] ] + $this->places[ $id ] );
			} else {
				$this->set( $id, $place );
			}
			return;
		}
		// Renamed where it was: under the same parent.
		if ( ( $up > 0 ? $this->path( $up ) : $this->places[ $id ]['above'] ) === $place['above'] ) {
			$this->set( $id, [ 'slug' => $place['slug'] ] + $this->places[ $id ] );
			return;
		}
		// Moved, and maybe renamed in the same save: to the top level, under the
		// tracked page at the parent path then or, when the pages between are
		// not tracked, under the nearest tracked page above it.
		$parent = '' !== $place['above'] ? $this->at( $place['above'], $id ) : 0;
		if ( '' === $place['above'] || $parent > 0 ) {
			$this->set(
				$id,
				[
					'parent' => $parent,
					'above'  => '',
					'slug'   => $place['slug'],
				]
			);
			return;
		}
		$this->set( $id, $place );
		$segments = explode( '/', $place['above'] );
		for ( $length = count( $segments ) - 1; $length >= 1; $length-- ) {
			$other = $this->at( implode( '/', array_slice( $segments, 0, $length ) ), $id );
			if ( $other > 0 ) {
				$this->set(
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

	/**
	 * The ledger entries and trash markers of the given pages, of the pages
	 * in the trash those markers name, and of the pages above them all, with
	 * where each of those pages is now: one query for the pages, then one per
	 * new set of pages in the trash and pages above them. No more rows are read
	 * than twenty per page the count cap allows, the newest kept; past that
	 * the history is capped.
	 *
	 * @param int[]  $walked    Pages walked, whose places the walk knows.
	 * @param int[]  $others    Posts the blocks are recorded on, pages in the trash the blocks name, and the pages above them.
	 * @param string $post_type Post type name.
	 * @return array{entries:array<int,array<int,array{id:int,path:string}>>,markers:array<int,array<int,array{id:int,root:int,original:string}>>,virtual:array<int,array{aliases:array<int,array{to:int,path:string}>}>,places:array<int,array{parent:int,above:string,slug:string}>,capped:bool}
	 */
	public static function history( array $walked, array $others, string $post_type ): array {
		global $wpdb;

		$out    = [
			'entries' => [],
			'markers' => [],
			'virtual' => [],
			'places'  => [],
			'capped'  => false,
		];
		$budget = Subtree::count_cap() * self::ROWS_PER_PAGE;
		$walked = array_flip( array_map( 'intval', $walked ) );
		$read   = [];
		$new    = array_values( array_unique( array_filter( array_map( 'intval', $others ) ) ) );
		$ask    = array_values( array_unique( array_merge( array_keys( $walked ), $new ) ) );
		for ( $round = 0; [] !== $ask && $round < 6; $round++ ) {
			if ( $budget <= 0 ) {
				$out['capped'] = true;
				break;
			}
			// The pages above the pages new here are tracked too, so a rename up there is taken back.
			Batch_Resolver::prime_with_ancestors( $new );
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
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $placeholders holds only %d, one per page asked about, bounded by the count cap; a range on the post_id index, the newest rows first, bounded by LIMIT.
			$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT meta_id, post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ( {$placeholders} ) AND ( meta_key LIKE %s OR meta_key = '_wp_desired_post_slug' ) ORDER BY meta_id DESC LIMIT %d", ...array_merge( $ask, [ $wpdb->esc_like( '_hierarchy_guard_' ) . '%', $budget + 1 ] ) ), ARRAY_A );
			if ( count( $rows ) > $budget ) {
				$out['capped'] = true;
				$rows          = array_slice( $rows, 0, $budget );
			}
			$budget -= count( $rows );
			$rows    = array_reverse( $rows );

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
}
