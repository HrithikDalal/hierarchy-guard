<?php
/**
 * Records what code reports through wp_trigger_error().
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

/**
 * For test cases that check a failure is reported under WP_DEBUG.
 */
trait Error_Reports {

	/**
	 * What a callback reports through wp_trigger_error(), as
	 * "function(): message", recorded rather than raised. WordPress 7.0 and
	 * later announce each report even with WP_DEBUG off, as in the tests;
	 * older versions announce none, so this returns null there.
	 *
	 * @param callable $run The callback.
	 * @return string[]|null
	 */
	private function reports( callable $run ): ?array {
		$reports = [];
		$record  = static function ( string $function_name, string $message ) use ( &$reports ): void {
			$reports[] = "{$function_name}(): {$message}";
		};
		add_action( 'wp_trigger_error_always_run', $record, 10, 2 );
		add_filter( 'wp_trigger_error_trigger_error', '__return_false' );
		try {
			$run();
		} finally {
			remove_action( 'wp_trigger_error_always_run', $record, 10 );
			remove_filter( 'wp_trigger_error_trigger_error', '__return_false' );
		}
		return version_compare( get_bloginfo( 'version' ), '7.0', '>=' ) ? $reports : null;
	}
}
