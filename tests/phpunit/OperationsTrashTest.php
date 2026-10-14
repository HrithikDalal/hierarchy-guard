<?php
/**
 * The trash-subtree step.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Ledger;
use HierarchyGuard\Trash\Guard;
use HierarchyGuard\Trash\Markers;
use HierarchyGuard\Trash\Mover;
use HierarchyGuard\Trash\Operations;
use WP_UnitTestCase;

/**
 * @covers \HierarchyGuard\Trash\Operations
 */
final class OperationsTrashTest extends WP_UnitTestCase {

	use Trash_Fixtures;

	/**
	 * Page IDs.
	 *
	 * @var array<string,int>
	 */
	private array $ids;

	private Operations $operations;

	public function set_up(): void {
		parent::set_up();
		$this->operations   = new Operations( new Ledger(), new Mover() );
		$this->ids          = $this->make_tree();
		$this->ids['plans'] = $this->page( 'plans', $this->ids['team'], 'draft' );
		$this->trash_raw( $this->ids['about'] );
	}

	public function tear_down(): void {
		$this->end_core_bulk();
		parent::tear_down();
	}

	/**
	 * A job trashing about's subtree.
	 *
	 * @param int $user_id Triggering user.
	 * @return array<string,mixed>
	 */
	private function job( int $user_id = 0 ): array {
		return Operations::new_job( Operations::TYPE_TRASH, $this->ids['about'], 'page', $user_id );
	}

	public function test_trashes_every_live_descendant_and_marks_it(): void {
		$job = $this->operations->run( $this->job() );

		$this->assertTrue( $job['finished'] );
		foreach ( [ 'team', 'history', 'leadership', 'plans' ] as $slug ) {
			$this->assertSame( 'trash', get_post_status( $this->ids[ $slug ] ), $slug );
		}
		$this->assertEqualSets( [ $this->ids['team'], $this->ids['history'], $this->ids['leadership'], $this->ids['plans'] ], $this->marked_ids( Markers::trashed_key( $this->ids['about'] ) ) );
		$this->assertSame( 4, $job['counts']['trashed'] );
		$this->assertSame( [], $job['stack'] );
	}

	public function test_covers_each_subpage_while_trashing_it(): void {
		$covered = [];
		add_action(
			'wp_trash_post',
			static function ( $post_id ) use ( &$covered ): void {
				$covered[] = Guard::covers( (int) $post_id );
			}
		);

		$this->operations->run( $this->job() );

		$this->assertSame( [ true, true, true, true ], $covered );
	}

	public function test_a_descendant_the_user_cannot_delete_keeps_its_own_subtree(): void {
		$editor = self::factory()->user->create( [ 'role' => 'editor' ] );
		$locked = $this->ids['team'];
		add_filter(
			'map_meta_cap',
			static function ( array $caps, string $cap, int $user_id, array $args ) use ( $locked ): array {
				return 'delete_post' === $cap && isset( $args[0] ) && (int) $args[0] === $locked ? [ 'do_not_allow' ] : $caps;
			},
			10,
			4
		);

		$job = $this->operations->run( $this->job( $editor ) );

		$this->assertSame( 'publish', get_post_status( $locked ) );
		$this->assertSame( 'publish', get_post_status( $this->ids['leadership'] ), 'Not expanded past a kept page.' );
		$this->assertSame( 'trash', get_post_status( $this->ids['history'] ) );
		$this->assertSame( 1, $job['counts']['denied'] );
		$this->assertSame( [ $locked ], $job['kept_ids'] );
	}

	public function test_the_front_page_stays_out_of_the_trash(): void {
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $this->ids['history'] );

		$job = $this->operations->run( $this->job() );

		$this->assertSame( 'publish', get_post_status( $this->ids['history'] ) );
		$this->assertSame( 'page', get_option( 'show_on_front' ), 'The homepage setting is untouched.' );
		$this->assertSame( 1, $job['counts']['kept'] );
		$this->assertSame( 0, $job['counts']['denied'] );
	}

	public function test_a_small_budget_spreads_the_work_across_steps(): void {
		$job   = $this->job();
		$steps = 0;
		while ( ! $job['finished'] && $steps < 50 ) {
			$job = $this->operations->step( $job, 200, 1 );
			$this->assertLessThanOrEqual( 1, $job['work']['calls'] );
			++$steps;
		}

		$this->assertTrue( $job['finished'] );
		$this->assertSame( 4, $job['counts']['trashed'] );
		$this->assertGreaterThanOrEqual( 4, $steps );
	}

	public function test_a_budget_that_ends_on_the_last_subpage_finishes_the_job(): void {
		$job = $this->operations->step( $this->job(), 200, 4 );

		$this->assertTrue( $job['finished'] );
		$this->assertSame( 4, $job['counts']['trashed'] );
		$this->assertSame( [], $job['stack'] );
	}

	public function test_a_budget_one_short_of_the_subtree_leaves_the_job_unfinished(): void {
		$job = $this->operations->step( $this->job(), 200, 3 );

		$this->assertFalse( $job['finished'] );
		$this->assertSame( 3, $job['counts']['trashed'] );
	}

	public function test_stops_once_the_parent_leaves_the_trash(): void {
		$this->untrash_raw( $this->ids['about'] );

		$job = $this->operations->run( $this->job() );

		$this->assertTrue( $job['finished'] );
		$this->assertSame( 'publish', get_post_status( $this->ids['team'] ) );
	}

	public function test_subpages_core_is_about_to_trash_are_left_to_core(): void {
		$this->start_core_bulk( 'trash', [ $this->ids['about'], $this->ids['team'] ] );

		$job = $this->operations->run( $this->job() );

		$this->assertSame( 'publish', get_post_status( $this->ids['team'] ), 'Core trashes it next in its bulk loop.' );
		$this->assertSame( 'publish', get_post_status( $this->ids['leadership'] ), 'Its own trash handles its subpages.' );
		$this->assertSame( 'trash', get_post_status( $this->ids['history'] ) );
		$this->assertSame( 1, $job['counts']['trashed'] );
	}

	public function test_a_trash_another_plugin_blocks_leaves_no_marker(): void {
		$blocked = $this->ids['history'];
		add_filter( 'pre_trash_post', static fn( $check, $post ) => $post->ID === $blocked ? false : $check, 10, 2 );

		$job = $this->operations->run( $this->job() );

		$this->assertSame( 'publish', get_post_status( $blocked ) );
		$this->assertSame( '', get_post_meta( $blocked, Markers::trashed_key( $this->ids['about'] ), true ) );
		$this->assertSame( 1, $job['counts']['kept'] );
		$this->assertSame( 3, $job['counts']['trashed'] );
	}

	public function test_a_trash_another_plugin_answers_true_for_changes_nothing_under_it(): void {
		$blocked = $this->ids['team'];
		add_filter( 'pre_trash_post', static fn( $check, $post ) => $post->ID === $blocked ? true : $check, 10, 2 );

		$job = $this->operations->run( $this->job() );

		$this->assertSame( 'publish', get_post_status( $blocked ) );
		$this->assertSame( 'publish', get_post_status( $this->ids['leadership'] ), 'Not expanded past a page that stayed live.' );
		$this->assertSame( 'draft', get_post_status( $this->ids['plans'] ) );
		$this->assertSame( '', get_post_meta( $blocked, Markers::trashed_key( $this->ids['about'] ), true ) );
		$this->assertSame( 1, $job['counts']['kept'] );
		$this->assertSame( 1, $job['counts']['trashed'] );
	}
}
