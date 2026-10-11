<?php
/**
 * Bounded subtree queries.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Subtree;
use WP_UnitTestCase;

/**
 * @covers \HierarchyGuard\Subtree
 */
final class SubtreeTest extends WP_UnitTestCase {

	use Trash_Fixtures;

	/**
	 * Page IDs.
	 *
	 * @var array<string,int>
	 */
	private array $ids;

	public function set_up(): void {
		parent::set_up();
		$this->ids = $this->make_tree();
	}

	public function test_live_children_are_ordered_limited_and_paged(): void {
		$extra = $this->page( 'extra', $this->ids['about'] );

		$this->assertSame( [ $this->ids['team'], $this->ids['history'], $extra ], Subtree::live_children( $this->ids['about'], 'page', 10 ) );
		$this->assertSame( [ $this->ids['team'] ], Subtree::live_children( $this->ids['about'], 'page', 1 ) );
		$this->assertSame( [ $this->ids['history'], $extra ], Subtree::live_children( $this->ids['about'], 'page', 10, $this->ids['team'] ) );
		$this->assertSame( [], Subtree::live_children( $this->ids['about'], 'post', 10 ), 'Scoped to the post type.' );
		$this->assertSame( [], Subtree::live_children( $this->ids['about'], 'page', 0 ) );
	}

	public function test_trashed_children_are_not_live_but_drafts_are(): void {
		$draft = $this->page( 'plans', $this->ids['about'], 'draft' );
		$this->trash_raw( $this->ids['history'] );

		$this->assertSame( [ $this->ids['team'], $draft ], Subtree::live_children( $this->ids['about'], 'page', 10 ) );
		$this->assertTrue( Subtree::has_live_children( $this->ids['about'], 'page' ) );
		$this->assertFalse( Subtree::has_live_children( $this->ids['leadership'], 'page' ) );
	}

	public function test_count_live_children_stops_at_the_cap(): void {
		$this->assertSame( 2, Subtree::count_live_children( $this->ids['about'], 'page', 10 ) );
		$this->assertSame( 1, Subtree::count_live_children( $this->ids['about'], 'page', 1 ) );
		$this->assertSame( 0, Subtree::count_live_children( $this->ids['leadership'], 'page', 10 ) );
	}

	public function test_count_live_descendants_reports_one_past_the_cap(): void {
		$this->assertSame( 3, Subtree::count_live_descendants( $this->ids['about'], 'page', 10 ) );
		$this->assertSame( 3, Subtree::count_live_descendants( $this->ids['about'], 'page', 2 ) );
		$this->assertSame( 2, Subtree::count_live_descendants( $this->ids['about'], 'page', 1 ) );
		$this->assertSame( 0, Subtree::count_live_descendants( $this->ids['leadership'], 'page', 10 ) );
	}

	public function test_the_count_cap_is_filterable_with_a_floor_of_one(): void {
		$this->assertSame( 1000, Subtree::count_cap() );
		add_filter( 'hierarchy_guard_count_cap', static fn(): int => 0 );
		$this->assertSame( 1, Subtree::count_cap() );
	}

	public function test_with_live_children_keeps_only_parents(): void {
		$ids = [ $this->ids['team'], $this->ids['history'], $this->ids['leadership'] ];
		$this->assertSame( [ $this->ids['team'] ], Subtree::with_live_children( $ids, 'page' ) );
		$this->assertSame( [], Subtree::with_live_children( [], 'page' ) );
	}

	public function test_status_reads_past_the_object_cache(): void {
		global $wpdb;
		$this->assertSame( 'publish', get_post_status( $this->ids['team'] ), 'Precondition: the post is cached.' );

		$wpdb->update( $wpdb->posts, [ 'post_status' => 'trash' ], [ 'ID' => $this->ids['team'] ] );

		$this->assertSame( 'publish', get_post_status( $this->ids['team'] ), 'Precondition: the cache is stale.' );
		$this->assertSame( 'trash', Subtree::status( $this->ids['team'] ) );
		$this->assertSame( '', Subtree::status( 999999 ) );
	}

	public function test_nearest_live_ancestor_skips_trashed_ones(): void {
		$this->assertSame( $this->ids['team'], Subtree::nearest_live_ancestor( get_post( $this->ids['leadership'] ) ) );

		$this->trash_raw( $this->ids['team'] );
		$this->trash_raw( $this->ids['about'] );

		$this->assertSame( $this->ids['company'], Subtree::nearest_live_ancestor( get_post( $this->ids['leadership'] ) ) );
		$this->assertSame( 0, Subtree::nearest_live_ancestor( get_post( $this->ids['company'] ) ) );
	}

	public function test_child_counts_counts_live_children_per_post_in_one_query(): void {
		$this->page( 'draft-child', $this->ids['about'], 'draft' );
		$this->trash_raw( $this->page( 'gone', $this->ids['about'] ) );

		$queries = get_num_queries();
		$counts  = Subtree::child_counts( [ $this->ids['company'], $this->ids['about'], $this->ids['team'], $this->ids['leadership'] ], 'page', 10 );

		$this->assertSame( 1, get_num_queries() - $queries );
		$this->assertSame(
			[
				$this->ids['company']    => 1,
				$this->ids['about']      => 3,
				$this->ids['team']       => 1,
				$this->ids['leadership'] => 0,
			],
			$counts,
			'A draft counts, a trashed child does not.'
		);
	}

	public function test_child_counts_stop_at_the_cap(): void {
		$this->page( 'third', $this->ids['about'] );

		$this->assertSame( [ $this->ids['about'] => 2 ], Subtree::child_counts( [ $this->ids['about'] ], 'page', 2 ) );
		$this->assertSame( [], Subtree::child_counts( [], 'page', 2 ) );
	}

	public function test_descendant_ids_walks_level_by_level_up_to_the_cap(): void {
		$all = Subtree::descendant_ids( $this->ids['company'], 'page', 10 );
		$this->assertSame( [ $this->ids['about'], $this->ids['history'], $this->ids['team'], $this->ids['leadership'] ], $all['ids'], 'Level by level, each in menu order then title.' );
		$this->assertFalse( $all['capped'] );

		$two = Subtree::descendant_ids( $this->ids['company'], 'page', 2 );
		$this->assertSame( [ $this->ids['about'], $this->ids['history'] ], $two['ids'] );
		$this->assertTrue( $two['capped'] );

		$exact = Subtree::descendant_ids( $this->ids['company'], 'page', 4 );
		$this->assertFalse( $exact['capped'], 'Exactly the cap is not capped.' );
	}

	public function test_ordered_children_reads_up_to_the_limit_in_menu_order(): void {
		$zed = $this->page( 'zed', $this->ids['about'] );
		wp_update_post(
			[
				'ID'         => $zed,
				'menu_order' => -1,
			]
		);

		$this->assertSame( [ $zed, $this->ids['history'], $this->ids['team'] ], array_map( 'intval', array_column( Subtree::ordered_children( [ $this->ids['about'] ], 'page', 10 ), 'ID' ) ) );
		$this->assertCount( 2, Subtree::ordered_children( [ $this->ids['about'] ], 'page', 2 ), 'The limit bounds the read.' );
		$this->assertSame( [], Subtree::ordered_children( [], 'page', 2 ) );
	}

	public function test_ordered_children_reads_in_the_index_s_order_then_sorts_what_it_read(): void {
		$queries = [];
		$capture = static function ( string $sql ) use ( &$queries ): string {
			$queries[] = $sql;
			return $sql;
		};
		add_filter( 'query', $capture );
		Subtree::ordered_children( [ $this->ids['about'] ], 'page', 10 );
		remove_filter( 'query', $capture );

		$this->assertCount( 2, $queries );
		$this->assertStringContainsString( 'ORDER BY post_parent ASC, ID ASC LIMIT 10', $queries[0], 'The post_parent index gives this order, so LIMIT stops the read; no sort of every child.' );
		$this->assertStringContainsString( 'WHERE ID IN (', $queries[1] );
		$this->assertStringContainsString( 'ORDER BY menu_order ASC, post_title ASC, ID ASC', $queries[1] );
	}

	public function test_format_count_marks_a_capped_count(): void {
		$this->assertSame( '1,000+', Subtree::format_count( 1000, true ) );
		$this->assertSame( '12', Subtree::format_count( 12 ) );
	}
}
