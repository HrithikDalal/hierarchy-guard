<?php
/**
 * The reparent step.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Ledger;
use HierarchyGuard\Post_Types;
use HierarchyGuard\Settings;
use HierarchyGuard\Trash\Markers;
use HierarchyGuard\Trash\Mover;
use HierarchyGuard\Trash\Operations;
use WP_UnitTestCase;

/**
 * @covers \HierarchyGuard\Trash\Operations
 */
final class OperationsReparentTest extends WP_UnitTestCase {

	use Trash_Fixtures;

	/**
	 * Page IDs.
	 *
	 * @var array<string,int>
	 */
	private array $ids;

	private Ledger $ledger;

	private Operations $operations;

	public function set_up(): void {
		parent::set_up();
		delete_option( Settings::OPTION );
		delete_option( Ledger::ROOT_OPTION );
		Post_Types::reset();
		$this->set_permalink_structure( '/%postname%/' );
		$this->ledger     = new Ledger();
		$this->operations = new Operations( $this->ledger, new Mover() );
		$this->ids        = $this->make_tree();
		$this->trash_raw( $this->ids['about'] );
	}

	public function tear_down(): void {
		$this->end_core_bulk();
		$this->set_permalink_structure( '' );
		parent::tear_down();
	}

	/**
	 * A reparent job moving about's children to company.
	 *
	 * @param int $user_id Triggering user.
	 * @return array<string,mixed>
	 */
	private function job( int $user_id = 0 ): array {
		return Operations::new_job(
			Operations::TYPE_REPARENT,
			$this->ids['about'],
			'page',
			$user_id,
			[
				'to'    => $this->ids['company'],
				'paths' => [ 'company/about' ],
			]
		);
	}

	public function test_moves_every_live_child_to_the_new_parent_and_marks_it(): void {
		$job = $this->operations->run( $this->job() );

		$this->assertTrue( $job['finished'] );
		foreach ( [ 'team', 'history' ] as $slug ) {
			$this->assertSame( $this->ids['company'], (int) get_post( $this->ids[ $slug ] )->post_parent, $slug );
			$moved = Markers::decode_moved( get_post_meta( $this->ids[ $slug ], Markers::moved_key( $this->ids['about'] ), true ) );
			$this->assertSame( $this->ids['company'], $moved['to'] );
			$this->assertSame( $slug, $moved['original'] );
			$this->assertSame( $slug, $moved['assigned'] );
		}
		$this->assertSame( $this->ids['team'], (int) get_post( $this->ids['leadership'] )->post_parent, 'Grandchildren travel with their parent.' );
		$this->assertSame( 2, $job['counts']['moved'] );
		$this->assertSame( [ $this->ids['team'], $this->ids['history'] ], $job['ids'] );
	}

	public function test_records_one_alias_on_the_new_parent_and_remembers_it_on_the_root(): void {
		$this->operations->run( $this->job() );

		$this->assertSame( [ 'company/about' ], $this->ledger->paths_for( $this->ids['company'] ) );
		$this->assertSame( [ 'company/about' ], $this->ledger->paths_for( $this->ids['about'] ), 'The page in the trash holds its own path.' );
		$this->assertSame(
			[
				[
					'to'   => $this->ids['company'],
					'path' => 'company/about',
				],
			],
			get_post_meta( $this->ids['about'], Markers::ALIASES, true )
		);
	}

	public function test_no_alias_is_recorded_when_nothing_moved(): void {
		add_filter( 'map_meta_cap', static fn(): array => [ 'do_not_allow' ] );

		$job = $this->operations->run( $this->job( self::factory()->user->create( [ 'role' => 'editor' ] ) ) );

		$this->assertSame( 0, $job['counts']['moved'] );
		$this->assertSame( [], $this->ledger->paths_for( $this->ids['company'] ) );
	}

	public function test_a_renamed_child_keeps_its_old_url_on_record(): void {
		$this->page( 'team', $this->ids['company'] );

		$job = $this->operations->run( $this->job() );

		$this->assertSame( 'team-2', get_post( $this->ids['team'] )->post_name );
		$this->assertSame( 'team-2', Markers::decode_moved( get_post_meta( $this->ids['team'], Markers::moved_key( $this->ids['about'] ), true ) )['assigned'] );
		$this->assertSame( 1, $job['counts']['renamed'] );
		$this->assertSame( $this->ids['team'], $this->ledger->find( 'page', 'company/about/team' ) );
	}

	public function test_children_the_user_cannot_edit_stay_put(): void {
		$editor = self::factory()->user->create( [ 'role' => 'editor' ] );
		$locked = $this->ids['history'];
		add_filter(
			'map_meta_cap',
			static function ( array $caps, string $cap, int $user_id, array $args ) use ( $locked ): array {
				return 'edit_post' === $cap && isset( $args[0] ) && (int) $args[0] === $locked ? [ 'do_not_allow' ] : $caps;
			},
			10,
			4
		);

		$job = $this->operations->run( $this->job( $editor ) );

		$this->assertSame( $this->ids['company'], (int) get_post( $this->ids['team'] )->post_parent );
		$this->assertSame( $this->ids['about'], (int) get_post( $locked )->post_parent );
		$this->assertSame( 1, $job['counts']['kept'] );
		$this->assertSame( 1, $job['counts']['denied'] );
		$this->assertSame( [ $locked ], $job['kept_ids'] );
		$this->assertSame( '', get_post_meta( $locked, Markers::moved_key( $this->ids['about'] ), true ) );
	}

	public function test_system_contexts_skip_capability_checks(): void {
		add_filter( 'map_meta_cap', static fn(): array => [ 'do_not_allow' ] );

		$this->assertSame( 2, $this->operations->run( $this->job( 0 ) )['counts']['moved'] );
	}

	public function test_a_budget_of_one_moves_one_child_per_step(): void {
		$job = $this->operations->step( $this->job(), 1, 0 );
		$this->assertFalse( $job['finished'] );
		$this->assertSame( 1, $job['counts']['moved'] );
		$this->assertSame( 1, $job['work']['moves'] );

		$job = $this->operations->step( $job, 1, 0 );
		$job = $this->operations->step( $job, 1, 0 );
		$this->assertTrue( $job['finished'] );
		$this->assertSame( 2, $job['counts']['moved'] );
	}

	public function test_a_budget_of_zero_does_nothing_yet(): void {
		$job = $this->operations->step( $this->job(), 0, 25 );

		$this->assertFalse( $job['finished'] );
		$this->assertSame( 0, $job['counts']['moved'] );
	}

	public function test_stops_once_the_parent_leaves_the_trash(): void {
		$this->untrash_raw( $this->ids['about'] );

		$job = $this->operations->run( $this->job() );

		$this->assertTrue( $job['finished'] );
		$this->assertSame( 0, $job['counts']['moved'] );
		$this->assertSame( $this->ids['about'], (int) get_post( $this->ids['team'] )->post_parent );
	}

	public function test_a_target_that_went_to_the_trash_is_passed_for_the_next_live_ancestor(): void {
		$this->trash_raw( $this->ids['company'] );

		$job = $this->operations->run( $this->job() );

		$this->assertSame( 0, $job['to'] );
		$this->assertSame( 0, (int) get_post( $this->ids['team'] )->post_parent );
		$this->assertSame( 0, $this->ledger->find( 'page', 'company/about' ), 'The alias follows the new target.' );
	}

	public function test_children_core_is_about_to_trash_are_left_to_core(): void {
		$this->start_core_bulk( 'trash', [ $this->ids['about'], $this->ids['team'] ] );

		$job = $this->operations->run( $this->job() );

		$this->assertSame( $this->ids['about'], (int) get_post( $this->ids['team'] )->post_parent, 'Core trashes it next in its bulk loop.' );
		$this->assertSame( $this->ids['company'], (int) get_post( $this->ids['history'] )->post_parent );
		$this->assertSame( 1, $job['counts']['moved'] );
		$this->assertSame( 0, $job['counts']['kept'] );
	}

	public function test_reports_the_moved_children(): void {
		$seen = [];
		add_action(
			'hierarchy_guard_children_reparented',
			static function ( array $ids, int $from, int $to ) use ( &$seen ): void {
				$seen[] = [ $ids, $from, $to ];
			},
			10,
			3
		);

		$this->operations->run( $this->job() );

		$this->assertSame( [ [ [ $this->ids['team'], $this->ids['history'] ], $this->ids['about'], $this->ids['company'] ] ], $seen );
	}

	public function test_the_budgets_are_filterable_with_a_floor_of_one(): void {
		$this->assertSame( 200, Operations::threshold() );
		$this->assertSame( 25, Operations::core_threshold() );
		add_filter( 'hierarchy_guard_batch_threshold', static fn(): int => 0 );
		add_filter( 'hierarchy_guard_core_call_threshold', static fn(): int => -5 );
		$this->assertSame( 1, Operations::threshold() );
		$this->assertSame( 1, Operations::core_threshold() );
	}
}
