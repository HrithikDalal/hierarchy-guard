<?php
/**
 * Smoke test: the plugin loads inside the test suite.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Batch\Queue;
use HierarchyGuard\Ledger;
use HierarchyGuard\Meta_Guard;
use HierarchyGuard\Plugin;
use HierarchyGuard\Trash\Operations;
use WP_UnitTestCase;

/**
 * @covers \HierarchyGuard\Plugin
 */
final class PluginTest extends WP_UnitTestCase {

	public function test_plugin_is_loaded(): void {
		$this->assertTrue( defined( 'HIERARCHY_GUARD_VERSION' ) );
		$this->assertSame( '0.1.0', HIERARCHY_GUARD_VERSION );
		$this->assertInstanceOf( Plugin::class, Plugin::instance() );
		$this->assertSame( Plugin::instance(), Plugin::instance() );
	}

	public function test_a_site_is_set_up_once_per_version_and_a_fresh_one_schedules_nothing(): void {
		delete_option( Plugin::VERSION_OPTION );
		wp_clear_scheduled_hook( Ledger::REBUILD_HOOK );
		wp_clear_scheduled_hook( Queue::WATCHDOG );

		Plugin::maybe_upgrade();

		$this->assertSame( HIERARCHY_GUARD_VERSION, get_option( Plugin::VERSION_OPTION ) );
		$this->assertFalse( wp_next_scheduled( Ledger::REBUILD_HOOK ), 'No entry, so no rebuild.' );
		$this->assertFalse( wp_next_scheduled( Queue::WATCHDOG ), 'No job, so no watchdog.' );
		$queries = get_num_queries();
		Plugin::maybe_upgrade();
		$this->assertSame( $queries, get_num_queries(), 'Set up already: an autoloaded read.' );
	}

	public function test_activation_sets_the_site_up(): void {
		$root = self::factory()->post->create( [ 'post_type' => 'page' ] );
		$jobs = new Queue();
		$jobs->save( Operations::new_job( Operations::TYPE_REPARENT, $root, 'page', 0, [ 'to' => 0 ] ) );
		delete_option( Plugin::VERSION_OPTION );
		wp_clear_scheduled_hook( Queue::WATCHDOG );

		do_action( 'activate_' . plugin_basename( HIERARCHY_GUARD_FILE ), false ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's activation hook for this plugin.

		$this->assertNotFalse( wp_next_scheduled( Queue::WATCHDOG ), 'The job waiting from before is resumed.' );
		$this->assertSame( HIERARCHY_GUARD_VERSION, get_option( Plugin::VERSION_OPTION ) );
		$jobs->delete( $root );
		wp_clear_scheduled_hook( Queue::WATCHDOG );
	}

	public function test_an_update_schedules_the_rebuild_a_site_with_entries_needs(): void {
		$page = self::factory()->post->create( [ 'post_type' => 'page' ] );
		Meta_Guard::writing( static fn() => add_post_meta( $page, Ledger::key( 'page', 'old' ), 'old', true ) );
		wp_clear_scheduled_hook( Ledger::REBUILD_HOOK );
		update_option( Plugin::VERSION_OPTION, '0.0.1', true );

		Plugin::maybe_upgrade();

		$this->assertNotFalse( wp_next_scheduled( Ledger::REBUILD_HOOK ) );
		$this->assertSame( HIERARCHY_GUARD_VERSION, get_option( Plugin::VERSION_OPTION ) );
	}

	public function test_a_subsite_is_set_up_again_after_the_plugin_is_network_activated_again(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only: run with npm run test:php:multisite.' );
		}
		$root = self::factory()->post->create( [ 'post_type' => 'page' ] );
		$jobs = new Queue();
		$jobs->save( Operations::new_job( Operations::TYPE_REPARENT, $root, 'page', 0, [ 'to' => 0 ] ) );
		Plugin::setup();
		wp_clear_scheduled_hook( Queue::WATCHDOG );

		Plugin::maybe_upgrade();
		$this->assertFalse( wp_next_scheduled( Queue::WATCHDOG ), 'Same version, same network activation: nothing to do.' );

		$again = static fn(): array => [ plugin_basename( HIERARCHY_GUARD_FILE ) => 1700000000 ];
		add_filter( 'pre_site_option_active_sitewide_plugins', $again );
		Plugin::maybe_upgrade();
		remove_filter( 'pre_site_option_active_sitewide_plugins', $again );

		$this->assertNotFalse( wp_next_scheduled( Queue::WATCHDOG ), 'The job waiting here is resumed.' );
		$this->assertSame( 1700000000, (int) get_option( Plugin::NETWORK_OPTION ) );
		$jobs->delete( $root );
		wp_clear_scheduled_hook( Queue::WATCHDOG );
	}

	public function test_the_main_file_and_every_file_in_includes_stop_when_requested_directly(): void {
		$root  = dirname( __DIR__, 2 );
		$files = [ $root . '/hierarchy-guard.php' ];
		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/includes', \FilesystemIterator::SKIP_DOTS ) ) as $file ) {
			if ( 'php' === $file->getExtension() ) {
				$files[] = $file->getPathname();
			}
		}
		$this->assertGreaterThan( 40, count( $files ) );

		foreach ( $files as $file ) {
			$code  = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local source file.
			$guard = preg_match( "/if \\( ! defined\\( 'ABSPATH' \\) \\) \\{\\s*exit;\\s*\\}/", $code, $match, PREG_OFFSET_CAPTURE ) ? $match[0][1] : false;
			$this->assertNotFalse( $guard, $file );
			$this->assertLessThan( 50, substr_count( substr( $code, 0, (int) $guard ), "\n" ), $file . ': within the first 50 lines, where Plugin Check looks.' );
			if ( preg_match( '/^(?:final class|class|interface|trait|require|spl_autoload_register|if \\( ! class_exists)/m', $code, $first, PREG_OFFSET_CAPTURE ) ) {
				$this->assertLessThan( $first[0][1], $guard, $file . ': before any code runs.' );
			}
		}
	}
}
