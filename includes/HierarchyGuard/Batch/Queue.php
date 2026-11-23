<?php
/**
 * Background job storage, locks and scheduling.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Batch;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One job slot per root page, so a trash job and a restore job for the same
 * page can never both be pending. Only the holder of the root's lock writes
 * the slot; a request that finds the lock taken leaves its job as an offer,
 * which the holder adopts after its current step.
 *
 * Slots, offers and locks are option rows read and written with direct
 * queries. They are not autoloaded, and get_option() would cache values that
 * another process changes underneath, or fill the notoptions cache with one
 * entry per page. However many roots wait, a site has one scheduled run,
 * which works through them, so a bulk trash writes the cron option once.
 */
final class Queue {

	public const HOOK         = 'hierarchy_guard_process_batch';
	public const WATCHDOG     = 'hierarchy_guard_watchdog';
	public const GROUP        = 'hierarchy-guard';
	public const JOB_PREFIX   = 'hierarchy_guard_job_';
	public const OFFER_SUFFIX = '_next';
	public const LOCK_PREFIX  = 'hierarchy_guard_lock_';
	public const LOCK_TTL     = 300;
	public const STEPPING     = 'hierarchy_guard_stepping';

	/**
	 * Whether this request has the run queued in Action Scheduler, so a bulk
	 * trash asks Action Scheduler once rather than once per parent.
	 *
	 * @var bool
	 */
	private bool $queued_in_as = false;

	/**
	 * Option name of a root's job slot.
	 *
	 * @param int $root_id Root page.
	 * @return string
	 */
	public static function job_option( int $root_id ): string {
		return self::JOB_PREFIX . $root_id;
	}

	/**
	 * Option name of a root's offer.
	 *
	 * @param int $root_id Root page.
	 * @return string
	 */
	public static function offer_option( int $root_id ): string {
		return self::JOB_PREFIX . $root_id . self::OFFER_SUFFIX;
	}

	/**
	 * Option name of a root's lock.
	 *
	 * @param int $root_id Root page.
	 * @return string
	 */
	public static function lock_option( int $root_id ): string {
		return self::LOCK_PREFIX . $root_id;
	}

	/**
	 * Stores a job in its root's slot, replacing whatever was there.
	 *
	 * @param array<string,mixed> $job Job.
	 */
	public function save( array $job ): void {
		$job['touched'] = time();
		$this->write( self::job_option( (int) $job['root'] ), $job );
	}

	/**
	 * The job in a root's slot.
	 *
	 * @param int $root_id Root page.
	 * @return array<string,mixed>|null
	 */
	public function load( int $root_id ): ?array {
		return $this->decode( $this->read( self::job_option( $root_id ) ) );
	}

	/**
	 * Empties a root's slot.
	 *
	 * @param int $root_id Root page.
	 */
	public function delete( int $root_id ): void {
		$this->erase( self::job_option( $root_id ) );
	}

	/**
	 * Leaves a job for whoever holds the root's lock.
	 *
	 * @param array<string,mixed> $job Job.
	 */
	public function offer( array $job ): void {
		$job['touched'] = time();
		$this->write( self::offer_option( (int) $job['root'] ), $job );
	}

	/**
	 * The offer waiting for a root, left in place.
	 *
	 * @param int $root_id Root page.
	 * @return array<string,mixed>|null
	 */
	public function offered( int $root_id ): ?array {
		return $this->decode( $this->read( self::offer_option( $root_id ) ) );
	}

	/**
	 * Takes the offer waiting for a root. If a newer offer replaces it in
	 * between, nothing is taken and the next look finds the newer one.
	 *
	 * @param int $root_id Root page.
	 * @return array<string,mixed>|null
	 */
	public function take_offer( int $root_id ): ?array {
		global $wpdb;

		$name = self::offer_option( $root_id );
		$raw  = $this->read( $name );
		if ( null === $raw ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Delete only the value read, so a newer offer survives.
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, $raw ) );

		return 1 === $deleted ? $this->decode( $raw ) : null;
	}

	/**
	 * Drops the offer waiting for a root.
	 *
	 * @param int $root_id Root page.
	 */
	public function discard_offer( int $root_id ): void {
		$this->erase( self::offer_option( $root_id ) );
	}

	/**
	 * Roots with a stored job or offer, oldest first. A root has at most two
	 * rows, so twice the limit in rows holds the limit in roots.
	 *
	 * @param int $limit Most roots to return.
	 * @return int[]
	 */
	public function roots( int $limit = 100 ): array {
		global $wpdb;

		$limit = max( 1, $limit );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prefix range on the option_name index, bounded by LIMIT.
		$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id ASC LIMIT %d", $wpdb->esc_like( self::JOB_PREFIX ) . '%', 2 * $limit ) );

		$roots = [];
		foreach ( $names as $name ) {
			if ( 1 === preg_match( '/^' . preg_quote( self::JOB_PREFIX, '/' ) . '(\d+)(?:' . preg_quote( self::OFFER_SUFFIX, '/' ) . ')?$/', (string) $name, $match ) ) {
				$roots[ (int) $match[1] ] = true;
			}
		}
		return array_slice( array_keys( $roots ), 0, $limit );
	}

	/**
	 * Drops a root's job or offer row that does not decode, so it keeps no
	 * run or check scheduled. A row written meanwhile has another value, and
	 * stays.
	 *
	 * @param int $root_id Root page.
	 */
	public function drop_unreadable( int $root_id ): void {
		global $wpdb;

		foreach ( [ self::job_option( $root_id ), self::offer_option( $root_id ) ] as $name ) {
			$raw = $this->read( $name );
			if ( null !== $raw && null === $this->decode( $raw ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Delete only the value read.
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, $raw ) );
			}
		}
	}

	/**
	 * Notes the root a run is about to step, so that if the run dies of an
	 * error nothing can catch the next run knows which root it died on.
	 *
	 * @param int $root_id Root page, or 0 to clear the note.
	 */
	public function mark_stepping( int $root_id ): void {
		if ( $root_id > 0 ) {
			$this->write( self::STEPPING, [ 'root' => $root_id ] );
		} else {
			$this->erase( self::STEPPING );
		}
	}

	/**
	 * The root a run was stepping when it died, or 0.
	 *
	 * @return int
	 */
	public function stepping(): int {
		$note = $this->decode( $this->read( self::STEPPING ) );
		return (int) ( $note['root'] ?? 0 );
	}

	/**
	 * Takes a root's lock. The insert fails while another process holds it;
	 * a lock left by a process that died is taken over once it expires.
	 *
	 * @param int $root_id Root page.
	 * @return int A token for unlock(), or 0 when the lock is taken.
	 */
	public function lock( int $root_id ): int {
		global $wpdb;

		$name  = self::lock_option( $root_id );
		$now   = time();
		$token = $now + self::LOCK_TTL;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- add_option() is an upsert, so it cannot serve as a mutex.
		$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'off' )", $name, (string) $token ) );
		if ( 1 === $inserted ) {
			return $token;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic takeover of an expired lock.
		$taken = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND CAST( option_value AS UNSIGNED ) < %d", (string) $token, $name, $now ) );

		return 1 === $taken ? $token : 0;
	}

	/**
	 * Releases a root's lock, unless another process has taken it over since.
	 *
	 * @param int $root_id Root page.
	 * @param int $token   Token lock() returned.
	 */
	public function unlock( int $root_id, int $token ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Releases only this process's own lock.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::lock_option( $root_id ), (string) $token ) );
	}

	/**
	 * Renews a root's lock for another LOCK_TTL, if this process still holds it.
	 *
	 * @param int $root_id Root page.
	 * @param int $token   Token from lock() or the last extend().
	 * @return int The new token, or 0 when another process has taken the lock over.
	 */
	public function extend( int $root_id, int $token ): int {
		global $wpdb;

		// Always a new value, so the update changes a row only while the lock is still ours.
		$renewed = max( time() + self::LOCK_TTL, $token + 1 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Renews only this process's own lock.
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", (string) $renewed, self::lock_option( $root_id ), (string) $token ) );

		return 1 === $updated ? $renewed : 0;
	}

	/**
	 * Schedules the site's run through Action Scheduler when a site has it,
	 * else WP-Cron, unless one is scheduled already, and the watchdog with
	 * it. A run already in progress does not count: it may be the one about
	 * to finish.
	 *
	 * @return bool Whether a run is scheduled.
	 */
	public function schedule(): bool {
		Processor::schedule_watchdog();

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			if ( $this->queued_in_as ) {
				return true;
			}
			$pending = function_exists( 'as_get_scheduled_actions' )
				? as_get_scheduled_actions(
					[
						'hook'     => self::HOOK,
						'args'     => [],
						'group'    => self::GROUP,
						'status'   => 'pending',
						'per_page' => 1,
					],
					'ids'
				)
				: [];
			if ( [] !== $pending || as_enqueue_async_action( self::HOOK, [], self::GROUP ) ) {
				$this->queued_in_as = true;
				return true;
			}
			// Action Scheduler returns 0 before it has initialized; WP-Cron takes over.
		}

		if ( false !== wp_next_scheduled( self::HOOK ) ) {
			return true;
		}
		return true === wp_schedule_single_event( time(), self::HOOK );
	}

	/**
	 * Whether the site's run is scheduled.
	 *
	 * @return bool
	 */
	public function is_scheduled(): bool {
		if ( false !== wp_next_scheduled( self::HOOK ) ) {
			return true;
		}
		return function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( self::HOOK, [], self::GROUP );
	}

	/**
	 * Forgets that this request queued the run in Action Scheduler. A run
	 * that starts is that action, no longer pending, so the next schedule()
	 * asks Action Scheduler again, even in a process that runs action after
	 * action.
	 */
	public function forget_queued(): void {
		$this->queued_in_as = false;
	}

	/**
	 * Clears the site's scheduled run.
	 */
	public function unschedule(): void {
		$this->queued_in_as = false;
		wp_clear_scheduled_hook( self::HOOK );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, [], self::GROUP );
		}
	}

	/**
	 * Inserts or replaces an option row.
	 *
	 * @param string              $name  Option name.
	 * @param array<string,mixed> $value Value.
	 */
	private function write( string $name, array $value ): void {
		global $wpdb;

		$data = maybe_serialize( $value );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- See the class docblock.
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'off' ) ON DUPLICATE KEY UPDATE option_value = %s", $name, $data, $data ) );
	}

	/**
	 * Reads an option row.
	 *
	 * @param string $name Option name.
	 * @return string|null The raw value, or null when there is no row.
	 */
	private function read( string $name ): ?string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- See the class docblock.
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );

		return null === $raw ? null : (string) $raw;
	}

	/**
	 * Deletes an option row.
	 *
	 * @param string $name Option name.
	 */
	private function erase( string $name ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- See the class docblock.
		$wpdb->delete( $wpdb->options, [ 'option_name' => $name ], [ '%s' ] );
	}

	/**
	 * Unserializes a stored job.
	 *
	 * @param string|null $raw Raw value.
	 * @return array<string,mixed>|null
	 */
	private function decode( ?string $raw ): ?array {
		if ( null === $raw ) {
			return null;
		}
		$job = maybe_unserialize( $raw );
		return is_array( $job ) ? $job : null;
	}
}
