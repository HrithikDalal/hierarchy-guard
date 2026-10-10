<?php
/**
 * PHPUnit bootstrap. Expects the WordPress test library at WP_TESTS_DIR (wp-env sets it).
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

$hierarchy_guard_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $hierarchy_guard_tests_dir ) {
	$hierarchy_guard_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $hierarchy_guard_tests_dir . '/includes/functions.php' ) ) {
	echo "Could not find {$hierarchy_guard_tests_dir}/includes/functions.php. Run the tests with: npm run test:php\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit( 1 );
}

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';
require_once $hierarchy_guard_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require dirname( __DIR__, 2 ) . '/hierarchy-guard.php';
	}
);

require $hierarchy_guard_tests_dir . '/includes/bootstrap.php';
