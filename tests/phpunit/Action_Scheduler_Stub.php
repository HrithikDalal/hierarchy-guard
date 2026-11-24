<?php
/**
 * What the Action Scheduler stubs were asked, and whether they answer.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

/**
 * Off, the stubs answer as an Action Scheduler that hasn't initialized yet,
 * so the code under test falls back to WP-Cron.
 */
final class Action_Scheduler_Stub {

	/**
	 * Whether the stubs answer.
	 *
	 * @var bool
	 */
	public static bool $on = false;

	/**
	 * Times as_get_scheduled_actions() was asked.
	 *
	 * @var int
	 */
	public static int $asked = 0;

	/**
	 * Times as_has_scheduled_action() was asked.
	 *
	 * @var int
	 */
	public static int $checked = 0;

	/**
	 * Hooks of the pending actions.
	 *
	 * @var string[]
	 */
	public static array $queued = [];

	/**
	 * When each pending action is due, as a Unix time, in the order queued.
	 *
	 * @var int[]
	 */
	public static array $due = [];

	/**
	 * Turns the stubs off and forgets what they were asked.
	 */
	public static function reset(): void {
		self::$on      = false;
		self::$asked   = 0;
		self::$checked = 0;
		self::$queued  = [];
		self::$due     = [];
	}
}
