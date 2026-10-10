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
		$this->assertSame( 10, has_action( 'admin_init', [ Processor::class, 'schedule_watchdog' ] ) );
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
		$this->assertFalse( $this->queue->is_scheduled( $this->ids['about'] ) );
	}

	public function test_a_run_that_stops_early_saves_progress_and_schedules_the_rest(): void {
		$result = $this->processor->run( $this->ids['about'], 0, 0, 1 );

		$this->assertSame( 'queued', $result['state'] );
		$this->assertSame( 2, $this->queue->load( $this->ids['about'] )['counts']['moved'] );
		$this->assertTrue( $this->queue->is_scheduled( $this->ids['about'] ) );
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
		$this->assertFalse( $this->queue->is_scheduled( $this->ids['about'] ), 'Precondition: its run died.' );

		$this->processor->watchdog();

		$this->assertTrue( $this->queue->is_scheduled( $this->ids['about'] ) );
	}

	public function test_the_watchdog_leaves_a_recent_job_alone(): void {
		$this->processor->watchdog();
		$this->assertFalse( $this->queue->is_scheduled( $this->ids['about'] ) );
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

	public function test_the_watchdog_is_scheduled_hourly_once_and_cleared_on_deactivation(): void {
		Processor::schedule_watchdog();
		$first = wp_next_scheduled( Queue::WATCHDOG );
		Processor::schedule_watchdog();

		$this->assertNotFalse( $first );
		$this->assertSame( $first, wp_next_scheduled( Queue::WATCHDOG ) );
		$this->assertSame( 'hourly', wp_get_schedule( Queue::WATCHDOG ) );

		$this->queue->schedule( $this->ids['about'] );
		Processor::deactivate();

		$this->assertFalse( wp_next_scheduled( Queue::WATCHDOG ) );
		$this->assertFalse( $this->queue->is_scheduled( $this->ids['about'] ) );
		$this->assertNotNull( $this->queue->load( $this->ids['about'] ), 'Jobs survive deactivation.' );
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
		$this->assertFalse( $this->queue->is_scheduled( $root ) );
	}

	public function test_the_watchdog_leaves_a_job_whose_run_holds_the_lock(): void {
		$this->age_job( time() - 8 * DAY_IN_SECONDS, time() - HOUR_IN_SECONDS );
		$token = $this->queue->lock( $this->ids['about'] );

		$this->processor->watchdog();

		$this->assertNotNull( $this->queue->load( $this->ids['about'] ), 'A run holds it, so the job is alive.' );
		$this->queue->unlock( $this->ids['about'], $token );
	}
}
