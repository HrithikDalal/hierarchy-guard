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
