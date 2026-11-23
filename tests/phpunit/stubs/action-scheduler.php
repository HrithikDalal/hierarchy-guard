<?php
/**
 * Action Scheduler's functions, as the queue calls them.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

use HierarchyGuard\Tests\Action_Scheduler_Stub;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Action Scheduler's own names, stubbed for tests.

if ( ! function_exists( 'as_enqueue_async_action' ) ) {
	/**
	 * Queues an action, or returns 0 as before initialization.
	 *
	 * @param string $hook Hook.
	 * @return int Action ID.
	 */
	function as_enqueue_async_action( $hook ): int {
		if ( ! Action_Scheduler_Stub::$on ) {
			return 0;
		}
		Action_Scheduler_Stub::$queued[] = (string) $hook;
		return count( Action_Scheduler_Stub::$queued );
	}

	/**
	 * Pending action IDs.
	 *
	 * @return int[]
	 */
	function as_get_scheduled_actions(): array {
		if ( ! Action_Scheduler_Stub::$on ) {
			return [];
		}
		++Action_Scheduler_Stub::$asked;
		return array_keys( Action_Scheduler_Stub::$queued );
	}

	/**
	 * Whether an action is pending.
	 *
	 * @return bool
	 */
	function as_has_scheduled_action(): bool {
		if ( ! Action_Scheduler_Stub::$on ) {
			return false;
		}
		++Action_Scheduler_Stub::$checked;
		return [] !== Action_Scheduler_Stub::$queued;
	}

	/**
	 * Drops pending actions.
	 */
	function as_unschedule_all_actions(): void {
		if ( Action_Scheduler_Stub::$on ) {
			Action_Scheduler_Stub::$queued = [];
		}
	}
}
