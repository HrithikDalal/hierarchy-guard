<?php
/**
 * The rebuild of a tree at a date, on arrays alone.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Redirects\Rebuild;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Each row is a walk, the history the rebuild reads and the pairs it lists,
 * with no database: page 1 is Company, 2 About, 3 Team, 4 History.
 *
 * @covers \HierarchyGuard\Redirects\Rebuild
 */
final class RebuildTest extends TestCase {

	/**
	 * The rows.
	 *
	 * @return array<string,array{0:array<string,mixed>,1:array<string,mixed>,2:array<string,mixed>,3:array<int,array{0:string,1:int}>}>
	 */
	public function rows(): array {
		$company = self::place( 0, 'company' );
		return [
			'a subpage of a renamed page'                 => [
				self::block( 'company/about', 2, 'company/about-us' ),
				self::tree( [ self::node( 3, 2, 'team' ) ] ),
				self::history(
					[
						1 => $company,
						2 => self::place( 1, 'about-us' ),
					],
					[ 2 => [ self::entry( 10, 'company/about' ) ] ]
				),
				[ [ 'company/about/team', 3 ] ],
			],
			'a subpage renamed since'                     => [
				self::block( 'company/about', 2, 'company/about-us' ),
				self::tree( [ self::node( 3, 2, 'crew' ) ] ),
				self::history(
					[
						1 => $company,
						2 => self::place( 1, 'about-us' ),
					],
					[
						2 => [ self::entry( 10, 'company/about' ) ],
						3 => [ self::entry( 20, 'company/about-us/team' ) ],
					]
				),
				[ [ 'company/about/team', 3 ] ],
			],
			'a subpage renamed before'                    => [
				self::block( 'company/about', 2, 'company/about-us' ),
				self::tree( [ self::node( 3, 2, 'crew' ) ] ),
				self::history(
					[
						1 => $company,
						2 => self::place( 1, 'about-us' ),
					],
					[
						2 => [ self::entry( 10, 'company/about' ) ],
						3 => [ self::entry( 5, 'company/about/team' ) ],
					]
				),
				[ [ 'company/about/crew', 3 ] ],
			],
			'a subpage a page in the trash moved up'      => [
				self::block( 'company/about', 1, 'company', 0 ),
				self::tree( [ self::node( 8, 1, 'kid' ) + [ 'holder' => 9 ] ] ),
				self::history(
					[
						1 => $company,
						9 => self::place( 1, 'about' ),
					],
					[
						1 => [ self::entry( 32, 'company/about' ) ],
						9 => [ self::entry( 31, 'company/about' ) ],
					],
					[ 8 => [ self::marker( 30, 9, 'kid' ) ] ],
					[
						9 => [
							'aliases' => [
								[
									'to'   => 1,
									'path' => 'company/about',
								],
							],
						],
					]
				),
				[ [ 'company/about/kid', 8 ] ],
			],
			'a parent above the walk that is not tracked' => [
				self::block( 'company/about', 2, 'company/about-us' ),
				self::tree( [ self::node( 3, 2, 'team' ) ] ),
				self::history(
					[ 2 => self::place( 1, 'about-us' ) ],
					[ 2 => [ self::entry( 10, 'company/about' ) ] ]
				),
				[ [ 'company/about/team', 3 ] ],
			],
			'a subpage moved and renamed in one save'     => [
				self::block( 'company/about', 2, 'company/about-us' ),
				self::tree( [ self::node( 4, 2, 'history' ) ], [ 4 => [ self::node( 3, 4, 'crew' ) ] ] ),
				self::history(
					[
						1 => $company,
						2 => self::place( 1, 'about-us' ),
					],
					[
						2 => [ self::entry( 10, 'company/about' ) ],
						// Team was at about-us/team when one save gave it History as parent and crew as slug.
						3 => [ self::entry( 20, 'company/about-us/team' ) ],
					]
				),
				[ [ 'company/about/history', 4 ], [ 'company/about/team', 3 ] ],
			],
			'an old path below the page\'s own path now'  => [
				self::block( 'company/about', 2, 'company' ),
				self::tree( [ self::node( 3, 2, 'team' ) ] ),
				self::history(
					[ 2 => self::place( 0, 'company' ) ],
					// About was at company/about, and is the top-level company now.
					[ 2 => [ self::entry( 10, 'company/about' ) ] ]
				),
				[ [ 'company/about/team', 3 ] ],
			],
			'a subpage moved and renamed in one save, from under a page nobody tracks' => [
				self::block( 'company/about', 5, 'alpha/fresh-1' ),
				self::tree( [ self::node( 8, 5, 'history' ), self::node( 7, 5, 'fresh-4' ) ] ),
				self::history(
					[
						4 => self::place( 0, 'alpha' ),
						5 => self::place( 4, 'fresh-1' ),
					],
					[
						4 => [ self::entry( 4, 'company' ), self::entry( 5, 'studio-2' ) ],
						5 => [ self::entry( 3, 'company/about' ) ],
						// Leadership left Team, which is not tracked, for About under a new slug in one save.
						7 => [ self::entry( 6, 'alpha/fresh-1/team/leadership' ), self::entry( 12, 'alpha/fresh-1/fresh-2' ) ],
					]
				),
				[ [ 'company/about/history', 8 ], [ 'company/about/team/leadership', 7 ] ],
			],
			'a page in the trash at the path a live page has now' => [
				self::block( 'company/about', 10, 'studio', 0 ),
				self::tree(
					[
						self::node( 9, 10, 'services' ) + [ 'holder' => 4 ],
						[ 'segment' => 'team' ] + self::node( 6, 10, 'alpha' ) + [ 'holder' => 4 ],
					],
					[ 9 => [ self::node( 7, 9, 'leadership' ) ] ]
				),
				self::history(
					[
						10 => self::place( 0, 'studio' ),
						// Company is in the trash, at its slug from before: Team was renamed to that slug since.
						4  => self::place( 10, 'alpha' ),
						5  => self::place( 4, 'about' ),
					],
					[
						4  => [ self::entry( 13, 'company/about' ), self::entry( 16, 'company' ), self::entry( 22, 'studio/alpha' ) ],
						5  => [ self::entry( 14, 'company/about' ) ],
						10 => [ self::entry( 21, 'studio/alpha' ), self::entry( 23, 'company/about' ), self::entry( 24, 'company' ) ],
						6  => [ self::entry( 27, 'studio/team' ) ],
						7  => [ self::entry( 28, 'studio/alpha/leadership' ) ],
					],
					[
						6 => [ self::marker( 12, 5, 'team' ), self::marker( 20, 4, 'team' ) ],
						9 => [ self::marker( 26, 4, 'services' ) ],
					],
					[
						4 => [
							'aliases' => [
								[
									'to'   => 10,
									'path' => 'studio/alpha',
								],
								[
									'to'   => 10,
									'path' => 'company/about',
								],
								[
									'to'   => 10,
									'path' => 'company',
								],
							],
						],
						5 => [
							'aliases' => [
								[
									'to'   => 4,
									'path' => 'company/about',
								],
							],
						],
					]
				),
				[ [ 'company/about/services', 9 ], [ 'company/about/team/leadership', 7 ], [ 'company/about/team', 6 ] ],
			],
		];
	}

	/**
	 * Each row lists what it says.
	 *
	 * @dataProvider rows
	 *
	 * @param array<string,mixed>              $block   Block.
	 * @param array<string,mixed>              $tree    Walk.
	 * @param array<string,mixed>              $history History.
	 * @param array<int,array{0:string,1:int}> $listed  Expected pairs.
	 */
	public function test_a_rebuild_lists_each_page_at_the_url_it_had( array $block, array $tree, array $history, array $listed ): void {
		$above = static fn( int $parent_id ): string => 1 === $parent_id ? 'company' : '';

		$this->assertSame( $listed, Rebuild::listed_then( $block, $tree, $history, $above ) );
	}

	/**
	 * Tables with several dates: the post's own and those of pages in the trash
	 * that left the path, some before their trash.
	 *
	 * @return array<string,array{0:array<string,mixed>,1:array<string,mixed>,2:array<string,mixed>}>
	 */
	public function dated(): array {
		$out = [];
		foreach ( $this->rows() as $name => [ $block, $tree, $history ] ) {
			$out[ $name ] = [ $block, $tree, $history ];
		}
		$tops    = [ self::node( 3, 2, 'team' ) ];
		$places  = [
			1 => self::place( 0, 'company' ),
			2 => self::place( 1, 'about-us' ),
		];
		$entries = [ 2 => [ self::entry( 5, 'company/about' ) ] ];
		$markers = [];
		$virtual = [];
		for ( $i = 1; $i <= 6; $i++ ) {
			$tops[]             = self::node( 50 + $i, 2, 'kid-' . ( $i % 3 ) ) + [ 'holder' => 20 + $i ];
			$places[ 20 + $i ]  = self::place( 1, 0 === $i % 2 ? 'about' : 'about-' . $i );
			$entries[ 20 + $i ] = [ self::entry( 10 * $i + ( 0 === $i % 3 ? -8 : 2 ), 'company/about' ) ];
			$markers[ 50 + $i ] = [ self::marker( 10 * $i + 1, 20 + $i, 'kid-' . ( $i % 3 ) ) ];
			$virtual[ 20 + $i ] = [ 'aliases' => [] ];
			$entries[3][]       = self::entry( 10 * $i + 3, 'company/about-us/team-' . $i );
		}
		$out['six pages in the trash and the post, with renames between'] = [
			self::block( 'company/about', 2, 'company/about-us' ),
			self::tree( $tops ),
			self::history( $places, $entries, $markers, $virtual ),
		];
		// A subpage a page in the trash moved up is also walked under another: it ranks where it is first reached.
		$out['a page the walk reaches twice'] = [
			self::block( 'company/about', 2, 'company/about-us' ),
			self::tree( $tops, [ 51 => [ $tops[3] ] ] ),
			self::history( $places, $entries, $markers, $virtual ),
		];
		return $out;
	}

	/**
	 * The sweep lists what one rewind per date lists, the first date to list a path keeping it.
	 *
	 * @dataProvider dated
	 *
	 * @param array<string,mixed> $block   Block.
	 * @param array<string,mixed> $tree    Walk.
	 * @param array<string,mixed> $history History.
	 */
	public function test_the_sweep_lists_what_a_rewind_per_date_lists( array $block, array $tree, array $history ): void {
		$above             = static fn( int $parent_id ): string => 1 === $parent_id ? 'company' : '';
		[ $nodes, $order ] = Rebuild::walked( $block, $tree );
		$places            = Rebuild::places( $block, $nodes, $history, $above );
		$old               = (string) $block['old'];
		$expected          = [];
		$seen              = [];
		foreach ( Rebuild::passes( $block, $nodes, $history ) as [ $anchor, $date ] ) {
			$then = Rebuild::rewind( $places, $history, $date, $anchor, $old );
			foreach ( $order as $id ) {
				$path = $then[ $id ] ?? '';
				if ( 'publish' === $nodes[ $id ]['status'] && str_starts_with( $path, $old . '/' ) && ! isset( $seen[ $path ] ) ) {
					$seen[ $path ] = true;
					$expected[]    = [ $path, (int) $id ];
				}
			}
		}

		$this->assertNotSame( [], $expected );
		$this->assertSame( $expected, Rebuild::listed_then( $block, $tree, $history, $above ) );
	}

	public function test_many_pages_in_the_trash_at_one_old_path_rebuild_in_linear_time(): void {
		$holders = 1000;
		$tops    = [];
		$places  = [ 1 => self::place( 0, 'company' ) ];
		$entries = [ 1 => [ self::entry( 1, 'company/about' ) ] ];
		$markers = [];
		$virtual = [];
		$listed  = [];
		for ( $i = 1; $i <= $holders; $i++ ) {
			// Page 1000 + i held company/about, had subpage 5000 + i and went to the trash, which moved it up to Company.
			$tops[]               = self::node( 5000 + $i, 1, 'kid-' . $i ) + [ 'holder' => 1000 + $i ];
			$places[ 1000 + $i ]  = self::place( 1, 'about' );
			$entries[ 1000 + $i ] = [ self::entry( 10 * $i + 2, 'company/about' ) ];
			$markers[ 5000 + $i ] = [ self::marker( 10 * $i + 1, 1000 + $i, 'kid-' . $i ) ];
			$virtual[ 1000 + $i ] = [
				'aliases' => [
					[
						'to'   => 1,
						'path' => 'company/about',
					],
				],
			];
			$listed[]             = [ 'company/about/kid-' . $i, 5000 + $i ];
		}

		$start  = microtime( true );
		$result = Rebuild::listed_then( self::block( 'company/about', 1, 'company', 0 ), self::tree( $tops ), self::history( $places, $entries, $markers, $virtual ), static fn(): string => '' );
		$took   = microtime( true ) - $start;

		$this->assertSame( $listed, $result );
		$this->assertLessThan( 1.0, $took, 'One pass per page in the trash, each over every event, took quadratic time.' );
	}

	public function test_the_passes_rebuilt_alone_stop_at_a_cap_and_mark_the_list_capped(): void {
		$holders = 1000;
		$tops    = [];
		$places  = [ 1 => self::place( 0, 'company' ) ];
		$entries = [ 1 => [] ];
		$markers = [];
		$virtual = [];
		for ( $i = 1; $i <= $holders; $i++ ) {
			// Page 1000 + i left company/about for the top level as company, the path above it, then had subpage 5000 + i and went to the trash under Company.
			$tops[]               = self::node( 5000 + $i, 1, 'kid-' . $i ) + [ 'holder' => 1000 + $i ];
			$places[ 1000 + $i ]  = self::place( 1, 'about-' . $i );
			$entries[ 1000 + $i ] = [ self::entry( 10 * $i + 1, 'company/about' ), self::entry( 10 * $i + 2, 'company' ) ];
			$markers[ 5000 + $i ] = [ self::marker( 10 * $i + 3, 1000 + $i, 'kid-' . $i ) ];
			$virtual[ 1000 + $i ] = [
				'aliases' => [
					[
						'to'   => 1,
						'path' => 'company/about',
					],
				],
			];
		}
		$block   = self::block( 'company/about', 1, 'company', 0 );
		$tree    = self::tree( $tops );
		$history = self::history( $places, $entries, $markers, $virtual );

		$capped = false;
		$start  = microtime( true );
		$result = Rebuild::listed_then( $block, $tree, $history, static fn(): string => '', $capped );
		$took   = microtime( true ) - $start;

		$this->assertTrue( $capped, 'Each holder\'s entry for the old path lies below where it was then, so each such pass is rebuilt on its own, and past the cap the list is incomplete.' );
		$this->assertLessThan( 0.5, $took, 'One rewind per pass rebuilt alone, each over every event, took quadratic time.' );
		$this->assertSame( [ 'company/about/kid-1', 5001 ], $result[0] );
	}

	public function test_a_few_passes_rebuilt_alone_list_what_a_rewind_lists(): void {
		$block   = self::block( 'company/about', 1, 'company', 0 );
		$tops    = [];
		$places  = [ 1 => self::place( 0, 'company' ) ];
		$entries = [ 1 => [] ];
		$markers = [];
		$virtual = [];
		for ( $i = 1; $i <= 3; $i++ ) {
			$tops[]               = self::node( 5000 + $i, 1, 'kid-' . $i ) + [ 'holder' => 1000 + $i ];
			$places[ 1000 + $i ]  = self::place( 1, 'about-' . $i );
			$entries[ 1000 + $i ] = [ self::entry( 10 * $i + 1, 'company/about' ), self::entry( 10 * $i + 2, 'company' ) ];
			$markers[ 5000 + $i ] = [ self::marker( 10 * $i + 3, 1000 + $i, 'kid-' . $i ) ];
			$virtual[ 1000 + $i ] = [ 'aliases' => [] ];
		}
		$tree    = self::tree( $tops );
		$history = self::history( $places, $entries, $markers, $virtual );

		[ $nodes, $order ] = Rebuild::walked( $block, $tree );
		$above             = static fn(): string => '';
		$places            = Rebuild::places( $block, $nodes, $history, $above );
		$expected          = [];
		$seen              = [];
		foreach ( Rebuild::passes( $block, $nodes, $history ) as [ $anchor, $date ] ) {
			$then = Rebuild::rewind( $places, $history, $date, $anchor, 'company/about' );
			foreach ( $order as $id ) {
				$path = $then[ $id ] ?? '';
				if ( str_starts_with( $path, 'company/about/' ) && ! isset( $seen[ $path ] ) ) {
					$seen[ $path ] = true;
					$expected[]    = [ $path, (int) $id ];
				}
			}
		}

		$capped = false;
		$this->assertSame( $expected, Rebuild::listed_then( $block, $tree, $history, $above, $capped ) );
		$this->assertFalse( $capped );
	}

	/**
	 * A block on a post's old path.
	 *
	 * @param string $old  Old path.
	 * @param int    $post Post it is recorded on.
	 * @param string $base The post's path now.
	 * @param int    $from The post whose own subpages are walked, 0 for none.
	 * @return array<string,mixed>
	 */
	private static function block( string $old, int $post, string $base, int $from = -1 ): array {
		return [
			'old'         => $old,
			'base'        => $base,
			'from'        => -1 === $from ? $post : $from,
			'recorded_on' => $post,
			'index'       => 0,
		];
	}

	/**
	 * A walk of one block, its first-level pages first, then the pages below by parent.
	 *
	 * @param array<int,array<string,mixed>>            $tops First-level pages.
	 * @param array<int,array<int,array<string,mixed>>> $kids Pages below, by parent.
	 * @return array<string,mixed>
	 */
	private static function tree( array $tops, array $kids = [] ): array {
		return [
			'tops' => [ 0 => $tops ],
			'kids' => $kids,
		];
	}

	/**
	 * A published page walked.
	 *
	 * @param int    $id        Page ID.
	 * @param int    $parent_id Parent ID.
	 * @param string $slug      Slug now.
	 * @return array<string,mixed>
	 */
	private static function node( int $id, int $parent_id, string $slug ): array {
		return [
			'id'      => $id,
			'parent'  => $parent_id,
			'segment' => $slug,
			'slug'    => $slug,
			'status'  => 'publish',
		];
	}

	/**
	 * What history() reads.
	 *
	 * @param array<int,array<string,mixed>>            $places  Places now of the pages not walked, by ID.
	 * @param array<int,array<int,array<string,mixed>>> $entries Ledger entries by page, oldest first.
	 * @param array<int,array<int,array<string,mixed>>> $markers Trash markers by subpage.
	 * @param array<int,array<string,mixed>>            $virtual Pages in the trash, with the aliases they wrote.
	 * @return array<string,mixed>
	 */
	private static function history( array $places, array $entries, array $markers = [], array $virtual = [] ): array {
		return [
			'entries' => $entries,
			'markers' => $markers,
			'virtual' => $virtual,
			'places'  => $places,
		];
	}

	/**
	 * A page's place now.
	 *
	 * @param int    $parent_id Parent ID, 0 for top level.
	 * @param string $slug      Slug.
	 * @return array{parent:int,above:string,slug:string}
	 */
	private static function place( int $parent_id, string $slug ): array {
		return [
			'parent' => $parent_id,
			'above'  => '',
			'slug'   => $slug,
		];
	}

	/**
	 * A ledger entry.
	 *
	 * @param int    $id   Meta ID.
	 * @param string $path Old path.
	 * @return array{id:int,path:string}
	 */
	private static function entry( int $id, string $path ): array {
		return [
			'id'   => $id,
			'path' => $path,
		];
	}

	/**
	 * A trash marker: the page in the trash moved this subpage up.
	 *
	 * @param int    $id       Meta ID.
	 * @param int    $root     The page in the trash.
	 * @param string $original The subpage's slug under it.
	 * @return array{id:int,root:int,original:string}
	 */
	private static function marker( int $id, int $root, string $original ): array {
		return [
			'id'       => $id,
			'root'     => $root,
			'original' => $original,
		];
	}
}
