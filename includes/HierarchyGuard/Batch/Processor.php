<?php
/**
 * Runs queued jobs in time-boxed batches.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Batch;

use HierarchyGuard\Ledger;
use HierarchyGuard\Subtree;
use HierarchyGuard\Trash\Change_Log;
use HierarchyGuard\Trash\Operations;

/**
 * Each run holds the root's lock and steps its job until the job is done or
 * the run's time or memory runs low, then schedules the next run. An hourly
 * watchdog restarts a job whose run died, and gives up on one after a week.
 */
final class Processor {

	public const TIME_LIMIT    = 20;
	public const STALE_AFTER   = 10 * MINUTE_IN_SECONDS;
	public const GIVE_UP_AFTER = 7 * DAY_IN_SECONDS;

	/**
	 * Queue.
	 *
	 * @var Queue
	 */
	private Queue $queue;

	/**
	 * Operations.
	 *
	 * @var Operations
	 */
	private Operations $operations;

	/**
	 * Change log.
	 *
	 * @var Change_Log
	 */
	private Change_Log $log;

	/**
	 * Constructor.
	 *
	 * @param Queue      $queue      Queue.
	 * @param Operations $operations Operations.
	 * @param Change_Log $log        Change log.
	 */
	public function __construct( Queue $queue, Operations $operations, Change_Log $log ) {
		$this->queue      = $queue;
		$this->operations = $operations;
		$this->log        = $log;
	}

	/**
	 * Hooks into the schedulers.
	 */
	public function register(): void {
		add_action( Queue::HOOK, [ $this, 'handle' ] );
		add_action( Queue::WATCHDOG, [ $this, 'watchdog' ] );
		add_action( 'admin_init', [ self::class, 'schedule_watchdog' ] );
	}

	/**
	 * Scheduler entry point: one time-boxed run for a root.
	 *
	 * @param mixed $root_id Root page, as the scheduler passes it.
	 */
	public function handle( $root_id ): void {
		$this->run( (int) $root_id, $this->time_limit() );
	}

	/**
	 * Steps a root's job while holding its lock.
	 *
	 * @param int $root_id    Root page.
	 * @param int $time_limit Seconds before the run stops and schedules the rest, 0 for no limit.
	 * @param int $pause_ms   Pause between steps, in milliseconds.
	 * @param int $max_steps  Steps before the run stops and schedules the rest, 0 for no limit.
	 * @return array{state:string,job:array<string,mixed>|null} state is busy (another run holds the lock, or took it over), idle, done or queued; job is the last one stepped.
	 */
	public function run( int $root_id, int $time_limit = 0, int $pause_ms = 0, int $max_steps = 0 ): array {
		$token = $this->queue->lock( $root_id );
		if ( 0 === $token ) {
			// Another run holds the lock; it reschedules itself if work is left.
			return [
				'state' => 'busy',
				'job'   => null,
			];
		}

		$start = microtime( true );
		$job   = null;
		$last  = null;
		$steps = 0;
		$lost  = false;
		try {
			while ( true ) {
				$job = $this->adopt( $root_id, $job );
				if ( null === $job ) {
					break;
				}

				$job   = Ledger::deferred( fn() => $this->operations->step( $job, Operations::threshold(), Operations::core_threshold() ) );
				$last  = $job;
				$token = $this->queue->extend( $root_id, $token );
				if ( 0 === $token ) {
					// Another run took the lock over after it expired; that run owns the job now.
					$lost = true;
					break;
				}
				$done = ! empty( $job['finished'] );
				$this->log->update( (int) $job['user'], (string) $job['change'], $root_id, $job['counts'], $job['kept_ids'], $job['ids'], ! $done );

				if ( $done ) {
					$this->queue->delete( $root_id );

					/**
					 * Fires when a background batch job completes.
					 *
					 * @param array<string,mixed> $job The finished job.
					 */
					do_action( 'hierarchy_guard_job_finished', $job );
					$job = null;
					continue;
				}

				$this->queue->save( $job );
				++$steps;
				$this->flush_runtime();
				if ( ( $max_steps > 0 && $steps >= $max_steps ) || $this->out_of_time( $start, $time_limit ) ) {
					break;
				}
				if ( $pause_ms > 0 ) {
					usleep( $pause_ms * 1000 );
				}
			}
		} finally {
			$this->queue->unlock( $root_id, $token );
		}

		if ( $lost ) {
			return [
				'state' => 'busy',
				'job'   => $last,
			];
		}

		// Scheduled after the unlock, so the next run cannot find this one still holding it.
		if ( null !== $job || null !== $this->queue->offered( $root_id ) ) {
			$this->queue->schedule( $root_id );
		}

		if ( null !== $job ) {
			$state = 'queued';
		} else {
			$state = null === $last ? 'idle' : 'done';
		}

		return [
			'state' => $state,
			'job'   => $last,
		];
	}

	/**
	 * Restarts jobs whose run died and drops jobs that can no longer finish.
	 */
	public function watchdog(): void {
		$now = time();
		foreach ( $this->queue->roots() as $root_id ) {
			$job   = $this->queue->load( $root_id );
			$offer = $this->queue->offered( $root_id );
			$any   = $job ?? $offer;
			if ( null === $any ) {
				continue;
			}

			$gone = Operations::TYPE_CLEANUP !== ( $any['type'] ?? '' ) && '' === Subtree::status( $root_id );
			if ( $gone || (int) ( $any['created'] ?? 0 ) < $now - self::GIVE_UP_AFTER ) {
				$token = $this->queue->lock( $root_id );
				if ( 0 === $token ) {
					// A run holds the lock, so the job is alive; the next check sees it again.
					continue;
				}
				$this->queue->delete( $root_id );
				$this->queue->discard_offer( $root_id );
				$this->queue->unlock( $root_id, $token );
				continue;
			}

			$touched = max( (int) ( $job['touched'] ?? 0 ), (int) ( $offer['touched'] ?? 0 ) );
			if ( $touched < $now - self::STALE_AFTER && ! $this->queue->is_scheduled( $root_id ) ) {
				$this->queue->schedule( $root_id );
			}
		}
	}

	/**
	 * Schedules the hourly watchdog if it is missing.
	 */
	public static function schedule_watchdog(): void {
		if ( false === wp_next_scheduled( Queue::WATCHDOG ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', Queue::WATCHDOG );
		}
	}

	/**
	 * Activation: start the watchdog, which also restarts jobs left from before.
	 */
	public static function activate(): void {
		self::schedule_watchdog();
	}

	/**
	 * Deactivation: stop scheduled runs. Stored jobs stay, and the watchdog
	 * restarts them after reactivation.
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( Queue::WATCHDOG );
		wp_unschedule_hook( Queue::HOOK );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', [], Queue::GROUP );
		}
	}

	/**
	 * The job to step next: a waiting offer replaces the current job.
	 *
	 * @param int                      $root_id Root page.
	 * @param array<string,mixed>|null $current Job stepped so far in this run.
	 * @return array<string,mixed>|null
	 */
	private function adopt( int $root_id, ?array $current ): ?array {
		$offer = $this->queue->take_offer( $root_id );
		if ( null === $offer ) {
			return $current ?? $this->queue->load( $root_id );
		}

		$replaced = $current ?? $this->queue->load( $root_id );
		if ( null !== $replaced ) {
			$this->log->update( (int) $replaced['user'], (string) $replaced['change'], $root_id, $replaced['counts'], $replaced['kept_ids'], $replaced['ids'], false );
		}
		$this->queue->save( $offer );
		return $offer;
	}

	/**
	 * Seconds a scheduled run may take: 20, or half of max_execution_time when that is lower.
	 *
	 * @return int
	 */
	private function time_limit(): int {
		$max   = (int) ini_get( 'max_execution_time' );
		$limit = self::TIME_LIMIT;
		if ( $max > 0 ) {
			$limit = min( $limit, intdiv( $max, 2 ) );
		}
		return max( 1, $limit );
	}

	/**
	 * Whether the run should stop: its time is up, or memory use passed 80%.
	 *
	 * @param float $start      microtime() at the start of the run.
	 * @param int   $time_limit Seconds, 0 for no limit.
	 * @return bool
	 */
	private function out_of_time( float $start, int $time_limit ): bool {
		if ( $time_limit > 0 && microtime( true ) - $start >= $time_limit ) {
			return true;
		}
		$memory = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );
		return $memory > 0 && memory_get_usage() > 0.8 * $memory;
	}

	/**
	 * Frees the in-process object cache between steps, so a long run does not
	 * keep every post it touched in memory.
	 */
	private function flush_runtime(): void {
		global $wpdb;

		if ( wp_cache_supports( 'flush_runtime' ) ) {
			wp_cache_flush_runtime();
		}
		if ( defined( 'SAVEQUERIES' ) && SAVEQUERIES ) {
			$wpdb->queries = [];
		}
	}
}
