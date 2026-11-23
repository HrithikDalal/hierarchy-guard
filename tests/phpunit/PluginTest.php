<?php
/**
 * Smoke test: the plugin loads inside the test suite.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Plugin;
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
