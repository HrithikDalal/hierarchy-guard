<?php
/**
 * WP-CLI's output calls, as the command makes them: each line is recorded.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- WP-CLI's own name, stubbed for tests.

/**
 * Records the lines a command prints.
 */
class WP_CLI {

	/**
	 * Lines, in the order printed; a success line starts "Success: ".
	 *
	 * @var string[]
	 */
	public static array $lines = [];

	/**
	 * Records a line.
	 *
	 * @param string $message Line.
	 */
	public static function log( $message ): void {
		self::$lines[] = (string) $message;
	}

	/**
	 * Records a success line.
	 *
	 * @param string $message Line.
	 */
	public static function success( $message ): void {
		self::$lines[] = 'Success: ' . $message;
	}
}
