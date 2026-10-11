<?php
/**
 * Job slots, offers, locks and scheduling.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Batch\Queue;
use HierarchyGuard\Trash\Operations;
use WP_UnitTestCase;

/**
 * @covers \HierarchyGuard\Batch\Queue
 */
final class QueueTest extends WP_UnitTestCase {

	private Queue $queue;

	public function set_up(): void {
		parent::set_up();
		$this->queue = new Queue();
	}

	public function tear_down(): void {
		wp_unschedule_hook( Queue::HOOK );
		parent::tear_down();
	}

	public function test_a_saved_job_round_trips_without_autoloading(): void {
		global $wpdb;

		$job = Operations::new_job( Operations::TYPE_REPARENT, 10, 'page', 0, [ 'to' => 2 ] );
		$this->queue->save( $job );

		$loaded = $this->queue->load( 10 );
		$this->assertSame( $job['id'], $loaded['id'] );
		$this->assertSame( 2, $loaded['to'] );
		$this->assertGreaterThanOrEqual( $job['touched'], $loaded['touched'] );
		$this->assertSame( 'off', $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", Queue::job_option( 10 ) ) ) );
		$this->assertNull( $this->queue->load( 11 ) );
	}

	public function test_saving_again_replaces_the_root_s_job(): void {
		$first  = Operations::new_job( Operations::TYPE_REPARENT, 10, 'page', 0 );
		$second = Operations::new_job( Operations::TYPE_RESTORE, 10, 'page', 0 );
		$this->queue->save( $first );
		$this->queue->save( $second );

		$this->assertSame( $second['id'], $this->queue->load( 10 )['id'] );

		$this->queue->delete( 10 );
		$this->assertNull( $this->queue->load( 10 ) );
	}

	public function test_an_offer_is_taken_once(): void {
		$job = Operations::new_job( Operations::TYPE_RESTORE, 10, 'page', 0 );
		$this->queue->offer( $job );

		$this->assertSame( $job['id'], $this->queue->offered( 10 )['id'] );
		$this->assertSame( $job['id'], $this->queue->take_offer( 10 )['id'] );
		$this->assertNull( $this->queue->take_offer( 10 ) );
		$this->assertNull( $this->queue->load( 10 ), 'An offer is not a slot.' );
	}

	public function test_roots_lists_slots_and_offers_once(): void {
		$this->queue->save( Operations::new_job( Operations::TYPE_REPARENT, 10, 'page', 0 ) );
		$this->queue->offer( Operations::new_job( Operations::TYPE_RESTORE, 10, 'page', 0 ) );
		$this->queue->offer( Operations::new_job( Operations::TYPE_RESTORE, 11, 'page', 0 ) );

		$this->assertSame( [ 10, 11 ], $this->queue->roots() );
	}

	public function test_a_lock_is_held_until_its_owner_releases_it(): void {
		$token = $this->queue->lock( 10 );

		$this->assertGreaterThan( 0, $token );
		$this->assertSame( 0, $this->queue->lock( 10 ) );
		$this->assertGreaterThan( 0, $this->queue->lock( 11 ), 'Locks are per root.' );

		$this->queue->unlock( 10, $token + 1 );
		$this->assertSame( 0, $this->queue->lock( 10 ), 'Only the owner releases it.' );

		$this->queue->unlock( 10, $token );
		$this->assertGreaterThan( 0, $this->queue->lock( 10 ) );
	}

	public function test_an_expired_lock_is_taken_over(): void {
		global $wpdb;

		$this->queue->lock( 10 );
		$wpdb->update( $wpdb->options, [ 'option_value' => (string) ( time() - 1 ) ], [ 'option_name' => Queue::lock_option( 10 ) ] );

		$this->assertGreaterThan( 0, $this->queue->lock( 10 ) );
		$this->assertSame( 0, $this->queue->lock( 10 ) );
	}

	public function test_schedule_uses_wp_cron_without_action_scheduler(): void {
		$this->assertFalse( function_exists( 'as_enqueue_async_action' ), 'Precondition: Action Scheduler is not loaded in the test site.' );
		$this->assertFalse( $this->queue->is_scheduled( 10 ) );

		$this->assertTrue( $this->queue->schedule( 10 ) );
		$this->assertTrue( $this->queue->schedule( 10 ), 'An existing event counts.' );

		$this->assertTrue( $this->queue->is_scheduled( 10 ) );
		$this->assertCount( 1, array_filter( _get_cron_array(), static fn( array $hooks ): bool => isset( $hooks[ Queue::HOOK ] ) ) );
	}

	public function test_extend_renews_only_the_owner_s_lock(): void {
		global $wpdb;

		$token   = $this->queue->lock( 10 );
		$renewed = $this->queue->extend( 10, $token );
		$this->assertGreaterThan( $token, $renewed );

		// Another process took the lock over: its token differs from this run's.
		$other = $renewed + 1000;
		$wpdb->update( $wpdb->options, [ 'option_value' => (string) $other ], [ 'option_name' => Queue::lock_option( 10 ) ] );

		$this->assertSame( 0, $this->queue->extend( 10, $renewed ), 'A run whose lock was taken over learns it.' );
		$this->assertGreaterThan( $other, $this->queue->extend( 10, $other ) );
	}
}
