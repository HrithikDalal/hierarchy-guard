<?php
/**
 * Background runs and the watchdog.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Batch\Processor;
use HierarchyGuard\Batch\Queue;
use HierarchyGuard\Ledger;
use HierarchyGuard\Subtree;
use HierarchyGuard\Trash\Change_Log;
use HierarchyGuard\Trash\Mover;
use HierarchyGuard\Trash\Operations;
use WP_UnitTestCase;

/**
 * @covers \HierarchyGuard\Batch\Processor
 */
final class ProcessorTest extends WP_UnitTestCase {

	use Error_Reports;
	use Trash_Fixtures;

	/**
	 * Page IDs.
	 *
	 * @var array<string,int>
	 */
	private array $ids;

	private Queue $queue;

	private Change_Log $log;

	private Processor $processor;

	/**
	 * The queued job.
	 *
	 * @var array<string,mixed>
	 */
	private array $job;

	public function set_up(): void {
		parent::set_up();
		Change_Log::reset_request();
		add_filter( 'hierarchy_guard_batch_threshold', static fn(): int => 2 );
		$this->queue     = new Queue();
		$this->log       = new Change_Log();
		$this->processor = new Processor( $this->queue, new Operations( new Ledger(), new Mover() ), $this->log );
		$this->ids       = $this->make_tree();
		foreach ( [ 'a', 'b', 'c' ] as $slug ) {
			$this->page( $slug, $this->ids['about'] );
		}
		$this->trash_raw( $this->ids['about'] );
		$this->job = Operations::new_job( Operations::TYPE_REPARENT, $this->ids['about'], 'page', 0, [ 'to' => $this->ids['company'] ] );
		$this->queue->save( $this->job );
	}

	public function tear_down(): void {
		wp_unschedule_hook( Queue::HOOK );
		wp_unschedule_hook( Queue::WATCHDOG );
		parent::tear_down();
	}

	/**
	 * Rewrites the stored job's timestamps.
	 *
	 * @param int $created Created time.
	 * @param int $touched Touched time.
	 */
	private function age_job( int $created, int $touched ): void {
		global $wpdb;
		$job            = $this->queue->load( $this->ids['about'] );
		$job['created'] = $created;
		$job['touched'] = $touched;
		$wpdb->update( $wpdb->options, [ 'option_value' => maybe_serialize( $job ) ], [ 'option_name' => Queue::job_option( $this->ids['about'] ) ] );
	}

	public function test_the_processor_is_registered_by_the_plugin(): void {
		global $wp_filter;
		foreach ( [
			Queue::HOOK     => 'handle',
			Queue::WATCHDOG => 'watchdog',
		] as $hook => $method ) {
			$found = false;
			foreach ( $wp_filter[ $hook ]->callbacks[10] ?? [] as $callback ) {
				if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof Processor && $method === $callback['function'][1] ) {
					$found = true;
				}
			}
			$this->assertTrue( $found, $hook );
		}
		$this->assertFalse( has_action( 'admin_init', [ Processor::class, 'schedule_watchdog' ] ), 'Nothing is scheduled on a site with no job.' );
	}

	public function test_one_run_works_through_every_queued_root_oldest_first(): void {
		$other = $this->page( 'other' );
		foreach ( [ 'x', 'y' ] as $slug ) {
			$this->page( $slug, $other );
		}
		$this->trash_raw( $other );
		$this->queue->save( Operations::new_job( Operations::TYPE_REPARENT, $other, 'page', 0, [ 'to' => 0 ] ) );
		$order = [];
		add_action(
			'hierarchy_guard_children_reparented',
			static function ( array $ids, int $from ) use ( &$order ): void {
				$order[] = $from;
			},
			10,
			2
		);

		$this->processor->handle();

		$this->assertSame( [ $this->ids['about'], $this->ids['about'], $this->ids['about'], $other ], $order, 'About, queued first, moves its five subpages two at a time, then Other.' );
		$this->assertSame( [], $this->queue->roots() );
		$this->assertSame( 0, Subtree::count_live_children( $this->ids['about'], 'page', 10 ) );
		$this->assertSame( 0, Subtree::count_live_children( $other, 'page', 10 ) );
		$this->assertFalse( $this->queue->is_scheduled(), 'Nothing is left for another run.' );
		$this->assertFalse( wp_next_scheduled( Queue::WATCHDOG ), 'With no job left, nothing is scheduled.' );
	}

	public function test_one_run_reads_more_roots_once_it_has_passed_the_first_hundred(): void {
		$parents = [];
		for ( $i = 1; $i <= 120; $i++ ) {
			$parent = $this->page( "many-{$i}" );
			$this->page( "many-{$i}-child", $parent );
			$this->trash_raw( $parent );
			$this->queue->save( Operations::new_job( Operations::TYPE_REPARENT, $parent, 'page', 0, [ 'to' => 0 ] ) );
			$parents[] = $parent;
		}

		$this->processor->handle();

		$this->assertSame( [], $this->queue->roots(), 'All 121 roots, About and the 120, in one run.' );
		$this->assertSame( 0, Subtree::count_live_children( end( $parents ), 'page', 10 ) );
		$this->assertFalse( $this->queue->is_scheduled() );
	}

	public function test_a_root_whose_step_fails_is_passed_over_and_left_to_the_watchdog(): void {
		$other = $this->page( 'other' );
		$this->page( 'x', $other );
		$this->trash_raw( $other );
		$this->queue->save( Operations::new_job( Operations::TYPE_REPARENT, $other, 'page', 0, [ 'to' => 0 ] ) );
		$about     = $this->ids['about'];
		$scheduled = null;
		$queue     = $this->queue;
		add_action(
			'hierarchy_guard_children_reparented',
			static function ( array $ids, int $from ) use ( $about, $queue, &$scheduled ): void {
				if ( $about === $from ) {
					$scheduled = $queue->is_scheduled();
					throw new \RuntimeException( 'Another plugin failed.' );
				}
			},
			10,
			2
		);

		$reports = $this->reports( fn() => $this->processor->handle() );

		$this->assertTrue( $scheduled, 'A next run is scheduled while a step runs, so a run that dies leaves one.' );
		$this->assertSame( [ $about ], $this->queue->roots(), 'Only the failed root is left.' );
		$this->assertSame( 0, Subtree::count_live_children( $other, 'page', 10 ), 'The root behind it was stepped.' );
		$this->assertFalse( $this->queue->is_scheduled(), 'The failed root waits for the watchdog, not the next page load.' );
		$this->assertNotFalse( wp_next_scheduled( Queue::WATCHDOG ) );
		if ( null !== $reports ) {
			$this->assertSame( [ "HierarchyGuard\\Batch\\Processor::handle(): The background job for post {$about} failed: Another plugin failed." ], $reports );
		}
	}

	/**
	 * Queues a trashed top-level page with subpages behind About.
	 *
	 * @param string $slug     Slug.
	 * @param int    $children Subpages.
	 * @return int Page ID.
	 */
	private function queue_behind( string $slug, int $children ): int {
		$root = $this->page( $slug );
		for ( $i = 1; $i <= $children; $i++ ) {
			$this->page( "{$slug}-{$i}", $root );
		}
		$this->trash_raw( $root );
		$this->queue->save( Operations::new_job( Operations::TYPE_REPARENT, $root, 'page', 0, [ 'to' => 0 ] ) );
		return $root;
	}

	/**
	 * A processor whose clock each step moves 25 seconds on, past a run's 20,
	 * so a run's time is up after one step.
	 *
	 * @return Processor
	 */
	private function slow_steps(): Processor {
		$now   = 1000.0;
		$clock = static function () use ( &$now ): float {
			return $now;
		};
		add_action(
			'hierarchy_guard_children_reparented',
			static function () use ( &$now ): void {
				$now += 25;
			}
		);
		return new Processor( $this->queue, new Operations( new Ledger(), new Mover() ), $this->log, $clock );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_each_run_of_a_long_lived_action_scheduler_process_queues_its_successor(): void {
		require_once __DIR__ . '/stubs/action-scheduler.php';
		Action_Scheduler_Stub::$on = true;
		$this->queue_behind( 'other', 1 );
		$processor = $this->slow_steps();
		$this->assertSame( Processor::TIME_LIMIT, Processor::time_limit(), 'Precondition: a 20-second run.' );

		$processor->handle();
		$this->assertSame( [ Queue::HOOK ], Action_Scheduler_Stub::$queued, 'The first run stopped early and queued its successor.' );

		// Action Scheduler runs that successor in the same process: it is no longer pending.
		Action_Scheduler_Stub::$queued = [];
		$during                        = null;
		add_action(
			'hierarchy_guard_children_reparented',
			static function () use ( &$during ): void {
				$during = Action_Scheduler_Stub::$queued;
			}
		);
		$processor->handle();

		$this->assertSame( [ Queue::HOOK ], $during, 'While the second run steps, its successor is queued, so it leaves one should it die.' );
		$this->assertSame( [ Queue::HOOK ], Action_Scheduler_Stub::$queued, 'The second run queued its own successor too.' );
		$this->assertCount( 2, $this->queue->roots(), 'Both roots still wait.' );
	}

	public function test_a_root_a_run_died_stepping_is_passed_over_once_so_the_roots_behind_it_go_on(): void {
		$other  = $this->queue_behind( 'other', 1 );
		$marked = [];
		$queue  = $this->queue;
		add_action(
			'hierarchy_guard_children_reparented',
			static function () use ( $queue, &$marked ): void {
				$marked[] = $queue->stepping();
			}
		);
		// What a run that died of a fatal error while stepping About leaves.
		$this->queue->mark_stepping( $this->ids['about'] );

		$this->processor->handle();

		$this->assertSame( [ $other ], $marked, 'Only Other was stepped, marked while it was.' );
		$this->assertSame( 5, Subtree::count_live_children( $this->ids['about'], 'page', 10 ), 'About was passed over.' );
		$this->assertSame( 0, Subtree::count_live_children( $other, 'page', 10 ) );
		$this->assertSame( [ $this->ids['about'] ], $this->queue->roots() );
		$this->assertSame( 0, $this->queue->stepping(), 'Cleared at the end, so the run the watchdog starts steps About again.' );
		$this->assertFalse( $this->queue->is_scheduled(), 'About waits for the watchdog.' );
		$this->assertNotFalse( wp_next_scheduled( Queue::WATCHDOG ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_run_out_of_time_stops_and_keeps_its_successor_even_when_a_run_beside_it_cleared_it(): void {
		require_once __DIR__ . '/stubs/action-scheduler.php';
		Action_Scheduler_Stub::$on = true;
		$other                     = $this->queue_behind( 'other', 2 );
		$processor                 = $this->slow_steps();
		$cleared                   = false;
		add_action(
			'hierarchy_guard_children_reparented',
			static function () use ( &$cleared ): void {
				if ( ! $cleared ) {
					// Another run ends meanwhile and clears the site's run.
					( new Queue() )->unschedule();
					$cleared = true;
				}
			}
		);

		$processor->handle();

		$this->assertSame( 3, Subtree::count_live_children( $this->ids['about'], 'page', 10 ), 'About took one step of two subpages.' );
		$this->assertSame( 2, Subtree::count_live_children( $other, 'page', 10 ), 'Other waits for the next run.' );
		$this->assertSame( [ Queue::HOOK ], Action_Scheduler_Stub::$queued, 'The next run is queued.' );
		$this->assertSame( 0, $this->queue->stepping() );
	}

	public function test_a_root_another_process_holds_is_passed_over_and_left_to_that_process(): void {
		$other = $this->queue_behind( 'other', 1 );
		$token = $this->queue->lock( $other );

		$this->processor->handle();

		$this->assertSame( [ $other ], $this->queue->roots() );
		$this->assertSame( 1, Subtree::count_live_children( $other, 'page', 10 ) );
		$this->assertSame( 0, Subtree::count_live_children( $this->ids['about'], 'page', 10 ) );
		$this->assertFalse( $this->queue->is_scheduled(), 'The process holding Other schedules its own next run.' );
		$this->assertNotFalse( wp_next_scheduled( Queue::WATCHDOG ), 'A job waits.' );
		$this->queue->unlock( $other, $token );
	}

	public function test_a_row_that_does_not_decode_schedules_no_run_and_the_watchdog_drops_it(): void {
		global $wpdb;
		$this->queue->delete( $this->ids['about'] );
		$wpdb->insert(
			$wpdb->options,
			[
				'option_name'  => Queue::job_option( $this->ids['about'] ),
				'option_value' => 'a:1:{s:4:"root";',
				'autoload'     => 'off',
			]
		);

		$this->processor->handle();
		$this->assertFalse( $this->queue->is_scheduled(), 'Nothing a run can step is left.' );

		wp_unschedule_hook( Queue::WATCHDOG );
		$this->processor->watchdog();

		$this->assertSame( [], $this->queue->roots() );
		$this->assertFalse( wp_next_scheduled( Queue::WATCHDOG ), 'No job waits.' );
	}

	public function test_a_run_finishes_the_job_and_removes_it(): void {
		$finished = [];
		add_action(
			'hierarchy_guard_job_finished',
			static function ( array $job ) use ( &$finished ): void {
				$finished[] = $job;
			}
		);

		$result = $this->processor->run( $this->ids['about'] );

		$this->assertSame( 'done', $result['state'] );
		$this->assertSame( 5, $result['job']['counts']['moved'] );
		$this->assertNull( $this->queue->load( $this->ids['about'] ) );
		$this->assertCount( 1, $finished );
		$this->assertSame( 0, Subtree::count_live_children( $this->ids['about'], 'page', 10 ) );
		$this->assertFalse( $this->queue->is_scheduled() );
	}

	public function test_a_run_that_stops_early_saves_progress_and_schedules_the_rest(): void {
		$result = $this->processor->run( $this->ids['about'], 0, 0, 1 );

		$this->assertSame( 'queued', $result['state'] );
		$this->assertSame( 2, $this->queue->load( $this->ids['about'] )['counts']['moved'] );
		$this->assertTrue( $this->queue->is_scheduled() );
	}

	public function test_a_run_backs_off_while_another_holds_the_lock(): void {
		$token = $this->queue->lock( $this->ids['about'] );

		$result = $this->processor->run( $this->ids['about'] );

		$this->assertSame( 'busy', $result['state'] );
		$this->assertSame( 0, $this->queue->load( $this->ids['about'] )['counts']['moved'] );
		$this->queue->unlock( $this->ids['about'], $token );
	}

	public function test_a_run_keeps_the_summary_current(): void {
		$admin         = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$entry         = [
			'root'   => $this->ids['about'],
			'policy' => 'reparent',
			'job'    => true,
		];
		$job           = $this->queue->load( $this->ids['about'] );
		$job['user']   = $admin;
		$job['change'] = $this->log->record( $admin, 'trash', 'page', $entry );
		$this->queue->save( $job );

		$this->processor->run( $this->ids['about'] );

		$root = $this->log->latest( $admin )['roots'][ $this->ids['about'] ];
		$this->assertSame( 5, $root['counts']['moved'] );
		$this->assertFalse( $root['job'] );
	}

	public function test_an_offer_replaces_the_job_and_closes_its_summary(): void {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$job   = $this->queue->load( $this->ids['about'] );
		$entry = [
			'root' => $this->ids['about'],
			'job'  => true,
		];

		$job['user']   = $admin;
		$job['change'] = $this->log->record( $admin, 'trash', 'page', $entry );
		$this->queue->save( $job );
		$this->untrash_raw( $this->ids['about'] );
		$this->queue->offer( Operations::new_job( Operations::TYPE_RESTORE, $this->ids['about'], 'page', 0 ) );

		$result = $this->processor->run( $this->ids['about'] );

		$this->assertSame( Operations::TYPE_RESTORE, $result['job']['type'] );
		$this->assertNull( $this->queue->offered( $this->ids['about'] ) );
		$this->assertFalse( $this->log->latest( $admin )['roots'][ $this->ids['about'] ]['job'] );
	}

	public function test_nothing_to_do_is_idle(): void {
		$this->queue->delete( $this->ids['about'] );
		$this->assertSame( 'idle', $this->processor->run( $this->ids['about'] )['state'] );
	}

	public function test_the_watchdog_restarts_a_stalled_job(): void {
		$this->age_job( time(), time() - HOUR_IN_SECONDS );
		$this->assertFalse( $this->queue->is_scheduled(), 'Precondition: its run died.' );

		$this->processor->watchdog();

		$this->assertTrue( $this->queue->is_scheduled() );
	}

	public function test_the_watchdog_leaves_a_recent_job_alone(): void {
		$this->processor->watchdog();
		$this->assertFalse( $this->queue->is_scheduled() );
	}

	public function test_the_watchdog_gives_up_after_a_week(): void {
		$this->age_job( time() - 8 * DAY_IN_SECONDS, time() - HOUR_IN_SECONDS );

		$this->processor->watchdog();

		$this->assertNull( $this->queue->load( $this->ids['about'] ) );
	}

	public function test_the_watchdog_drops_a_job_whose_root_is_gone_but_keeps_a_cleanup(): void {
		global $wpdb;
		$wpdb->delete( $wpdb->posts, [ 'ID' => $this->ids['about'] ] );
		$this->queue->save( Operations::new_job( Operations::TYPE_CLEANUP, 999999, 'page', 0 ) );

		$this->processor->watchdog();

		$this->assertNull( $this->queue->load( $this->ids['about'] ) );
		$this->assertNotNull( $this->queue->load( 999999 ) );
	}

	public function test_the_watchdog_is_one_single_event_an_hour_ahead_and_cleared_on_deactivation(): void {
		Processor::schedule_watchdog();
		$first = wp_next_scheduled( Queue::WATCHDOG );
		Processor::schedule_watchdog();

		$this->assertNotFalse( $first );
		$this->assertSame( $first, wp_next_scheduled( Queue::WATCHDOG ) );
		$this->assertGreaterThanOrEqual( time() + HOUR_IN_SECONDS - 5, $first );
		$this->assertFalse( wp_get_schedule( Queue::WATCHDOG ), 'A single event: with the plugin gone it runs once more and is gone.' );

		$this->queue->schedule();
		Processor::deactivate();

		$this->assertFalse( wp_next_scheduled( Queue::WATCHDOG ) );
		$this->assertFalse( $this->queue->is_scheduled() );
		$this->assertNotNull( $this->queue->load( $this->ids['about'] ), 'Jobs survive deactivation.' );
	}

	public function test_the_watchdog_checks_again_only_while_a_job_waits(): void {
		$this->processor->watchdog();
		$this->assertNotFalse( wp_next_scheduled( Queue::WATCHDOG ), 'A job waits.' );

		wp_unschedule_hook( Queue::WATCHDOG );
		$this->queue->delete( $this->ids['about'] );
		$this->processor->watchdog();

		$this->assertFalse( wp_next_scheduled( Queue::WATCHDOG ) );
	}

	public function test_activation_schedules_the_watchdog_only_when_a_job_waits(): void {
		Processor::activate();
		$this->assertNotFalse( wp_next_scheduled( Queue::WATCHDOG ), 'A job from before waits.' );

		wp_unschedule_hook( Queue::WATCHDOG );
		$this->queue->delete( $this->ids['about'] );
		Processor::activate();

		$this->assertFalse( wp_next_scheduled( Queue::WATCHDOG ) );
	}

	public function test_a_run_whose_lock_was_taken_over_stops_without_writing(): void {
		$root = $this->ids['about'];
		add_action(
			'hierarchy_guard_children_reparented',
			static function () use ( $root ): void {
				global $wpdb;
				// Another process holds the lock now, as if this run had held it too long.
				$wpdb->update( $wpdb->options, [ 'option_value' => (string) ( time() + 999 ) ], [ 'option_name' => Queue::lock_option( $root ) ] );
			}
		);

		$result = $this->processor->run( $root );

		$this->assertSame( 'busy', $result['state'] );
		$this->assertSame( 0, $this->queue->load( $root )['counts']['moved'], 'The slot keeps what the new holder will read.' );
		$this->assertFalse( $this->queue->is_scheduled() );
	}

	public function test_the_watchdog_leaves_a_job_whose_run_holds_the_lock(): void {
		$this->age_job( time() - 8 * DAY_IN_SECONDS, time() - HOUR_IN_SECONDS );
		$token = $this->queue->lock( $this->ids['about'] );

		$this->processor->watchdog();

		$this->assertNotNull( $this->queue->load( $this->ids['about'] ), 'A run holds it, so the job is alive.' );
		$this->queue->unlock( $this->ids['about'], $token );
	}
}
