<?php
/**
 * Plugin Name:       Hierarchy Guard
 * Plugin URI:        https://github.com/hrithikdalal/hierarchy-guard
 * Description:       Shows page hierarchy in wp-admin, warns before a slug, parent or trash change breaks subpage URLs, and records old URLs so they can be redirected to their pages.
 * Version:           0.1.0
 * Requires at least: 6.6
 * Requires PHP:      8.0
 * Author:            <a href="https://rtcamp.com">rtCamp</a>, <a href="https://github.com/HrithikDalal">Hrithik Dalal</a>
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       hierarchy-guard
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'HIERARCHY_GUARD_VERSION', '0.1.0' );
define( 'HIERARCHY_GUARD_FILE', __FILE__ );
define( 'HIERARCHY_GUARD_DIR', __DIR__ );

/*
 * PSR-4 autoloader for the plugin's own classes. The plugin has no runtime
 * Composer dependencies, so vendor/autoload.php is only needed for tests.
 */
spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'HierarchyGuard\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$relative = substr( $class_name, strlen( $prefix ) );
		$file     = __DIR__ . '/includes/HierarchyGuard/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

register_activation_hook( __FILE__, [ Batch\Processor::class, 'activate' ] );
register_activation_hook( __FILE__, [ Ledger::class, 'schedule_rebuild' ] );
register_deactivation_hook( __FILE__, [ Batch\Processor::class, 'deactivate' ] );
register_deactivation_hook( __FILE__, [ Ledger::class, 'unschedule_rebuild' ] );

Plugin::instance()->register();
