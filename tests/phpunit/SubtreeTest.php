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

	public function test_has_trashed_child_matches_the_slug_before_the_trash(): void {
		$this->trash_raw( $this->ids['history'] );

		$this->assertTrue( Subtree::has_trashed_child( $this->ids['about'], 'history', 'page' ) );
		$this->assertFalse( Subtree::has_trashed_child( $this->ids['about'], 'team', 'page' ), 'team is live.' );
		$this->assertFalse( Subtree::has_trashed_child( $this->ids['company'], 'history', 'page' ), 'Only direct children.' );
	}

	public function test_nearest_live_ancestor_skips_trashed_ones(): void {
		$this->assertSame( $this->ids['team'], Subtree::nearest_live_ancestor( get_post( $this->ids['leadership'] ) ) );

		$this->trash_raw( $this->ids['team'] );
		$this->trash_raw( $this->ids['about'] );

		$this->assertSame( $this->ids['company'], Subtree::nearest_live_ancestor( get_post( $this->ids['leadership'] ) ) );
		$this->assertSame( 0, Subtree::nearest_live_ancestor( get_post( $this->ids['company'] ) ) );
	}
}
