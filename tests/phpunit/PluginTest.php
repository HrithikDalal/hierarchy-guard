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
}
