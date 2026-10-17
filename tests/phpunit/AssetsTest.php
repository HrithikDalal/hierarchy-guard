<?php
/**
 * The compiled scripts and their settings.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Admin\Assets;
use HierarchyGuard\Admin\Tree_View;
use HierarchyGuard\Post_Types;
use HierarchyGuard\Redirects\Providers;
use HierarchyGuard\Settings;
use WP_UnitTestCase;

/**
 * @covers \HierarchyGuard\Admin\Assets
 */
final class AssetsTest extends WP_UnitTestCase {

	private string $dir;

	public function set_up(): void {
		parent::set_up();
		delete_option( Settings::OPTION );
		Post_Types::reset();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->dir = get_temp_dir() . 'hierarchy-guard-assets-' . wp_generate_password( 6, false );
		wp_mkdir_p( $this->dir . '/build' );
	}

	public function tear_down(): void {
		foreach ( (array) glob( $this->dir . '/build/*' ) as $file ) {
			unlink( (string) $file );
		}
		rmdir( $this->dir . '/build' );
		rmdir( $this->dir );
		foreach ( [ 'list-table', 'redirects' ] as $bundle ) {
			wp_dequeue_script( 'hierarchy-guard-' . $bundle );
			wp_deregister_script( 'hierarchy-guard-' . $bundle );
		}
		unset( $_GET['tab'] );
		foreach ( [ 'list-table', 'editor' ] as $bundle ) {
			wp_dequeue_style( 'hierarchy-guard-' . $bundle );
			wp_deregister_style( 'hierarchy-guard-' . $bundle );
		}
		wp_dequeue_style( 'wp-components' );
		set_current_screen( 'front' );
		parent::tear_down();
	}

	/**
	 * Writes a fake compiled bundle.
	 *
	 * @param string   $name Bundle name.
	 * @param string[] $deps Dependencies.
	 */
	private function build( string $name, array $deps ): void {
		$list = implode( ', ', array_map( static fn( string $dep ): string => "'" . $dep . "'", $deps ) );
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A throwaway file in the temp directory.
		file_put_contents( $this->dir . '/build/' . $name . '.asset.php', "<?php return array( 'dependencies' => array( {$list} ), 'version' => 'abc123' );" );
		file_put_contents( $this->dir . '/build/' . $name . '.js', '' );
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	public function test_a_bundle_that_was_never_built_is_skipped(): void {
		$this->assertFalse( ( new Assets( new Providers(), $this->dir ) )->enqueue( 'list-table' ) );
		$this->assertFalse( wp_script_is( 'hierarchy-guard-list-table', 'enqueued' ) );
	}

	public function test_a_built_bundle_is_enqueued_with_its_dependencies_and_settings(): void {
		$this->build( 'list-table', [ 'wp-api-fetch', 'wp-components', 'wp-element', 'wp-i18n' ] );

		$this->assertTrue( ( new Assets( new Providers(), $this->dir ) )->enqueue( 'list-table' ) );

		$script = wp_scripts()->registered['hierarchy-guard-list-table'];
		$this->assertSame( [ 'wp-api-fetch', 'wp-components', 'wp-element', 'wp-i18n' ], $script->deps );
		$this->assertSame( 'abc123', $script->ver );
		$this->assertStringContainsString( 'window.hierarchyGuard = {', implode( '', (array) $script->extra['before'] ) );
		$this->assertSame( 'hierarchy-guard', $script->textdomain );
		$this->assertTrue( wp_style_is( 'wp-components', 'enqueued' ), 'Components need their styles.' );
	}

	public function test_a_bundle_s_right_to_left_stylesheet_replaces_it_right_to_left(): void {
		$assets = new Assets( new Providers(), $this->dir );
		foreach ( [ 'list-table', 'editor' ] as $bundle ) {
			$this->build( $bundle, [] );
			// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Throwaway files in the temp directory.
			file_put_contents( $this->dir . '/build/' . $bundle . '.css', '' );
			file_put_contents( $this->dir . '/build/' . $bundle . '-rtl.css', '' );
			// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

			$assets->enqueue( $bundle );

			$this->assertSame( 'replace', wp_styles()->get_data( 'hierarchy-guard-' . $bundle, 'rtl' ), "{$bundle}-rtl.css loads in its place right to left." );
		}
	}

	public function test_a_stylesheet_without_a_right_to_left_build_is_left_alone(): void {
		$this->build( 'list-table', [] );
		file_put_contents( $this->dir . '/build/list-table.css', '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A throwaway file in the temp directory.

		( new Assets( new Providers(), $this->dir ) )->enqueue( 'list-table' );

		$this->assertFalse( wp_styles()->get_data( 'hierarchy-guard-list-table', 'rtl' ) );
	}

	public function test_the_settings_every_bundle_reads(): void {
		$config = ( new Assets( new Providers(), $this->dir ) )->config();

		$this->assertSame( 'hierarchy-guard/v1', $config['namespace'] );
		$this->assertFalse( $config['serving'] );
		$this->assertNull( $config['provider'] );
		$this->assertSame( admin_url( 'options-general.php?page=hierarchy-guard#hierarchy-guard-redirects' ), $config['settingsUrl'] );
		$this->assertSame( admin_url( 'options-general.php?page=hierarchy-guard#hierarchy-guard-trash-policy' ), $config['policyUrl'] );
		$this->assertSame( Settings::POLICY_REPARENT, $config['policy'] );
		$this->assertContains( 'page', $config['postTypes'] );
		$this->assertSame( 1000, $config['cap'] );
		$this->assertSame( 'en-US', $config['locale'] );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$editor = ( new Assets( new Providers(), $this->dir ) )->config();
		$this->assertSame( '', $editor['settingsUrl'], 'Only users who can change the setting get the link.' );
		$this->assertSame( '', $editor['policyUrl'] );
	}

	public function test_the_list_screen_gets_the_list_table_bundle_only_when_it_asks(): void {
		$this->build( 'list-table', [] );
		set_current_screen( 'edit-page' );
		$assets = new Assets( new Providers(), $this->dir );

		$assets->enqueue_list_table();
		$this->assertFalse( wp_script_is( 'hierarchy-guard-list-table', 'enqueued' ), 'Nothing on the screen needs it.' );

		$assets->want_list_table();
		$assets->enqueue_list_table();
		$this->assertTrue( wp_script_is( 'hierarchy-guard-list-table', 'enqueued' ) );
		$deps = wp_scripts()->registered['hierarchy-guard-list-table']->deps;
		$this->assertContains( 'jquery', $deps );
		$this->assertContains( 'inline-edit-post', $deps, 'It wraps Quick Edit, so Quick Edit loads first.' );
	}

	public function test_an_unsupported_screen_gets_nothing(): void {
		$this->build( 'list-table', [] );
		set_current_screen( 'edit-post' );
		$assets = new Assets( new Providers(), $this->dir );

		$assets->want_list_table();
		$assets->enqueue_list_table();

		$this->assertFalse( wp_script_is( 'hierarchy-guard-list-table', 'enqueued' ) );
	}

	public function test_the_plugin_listens_for_the_list_screen_s_request(): void {
		global $wp_filter;
		$found = false;
		foreach ( $wp_filter['hierarchy_guard_list_script']->callbacks[10] ?? [] as $callback ) {
			$found = $found || ( is_array( $callback['function'] ) && $callback['function'][0] instanceof Assets );
		}

		$this->assertTrue( $found );
	}

	public function test_the_tree_s_stylesheet_loads_in_the_head_and_its_script_in_the_footer(): void {
		$this->build( 'list-table', [] );
		file_put_contents( $this->dir . '/build/list-table.css', '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A throwaway file in the temp directory.
		set_current_screen( 'edit-page' );
		$assets = new Assets( new Providers(), $this->dir );

		$assets->enqueue_admin( 'edit.php' );
		$this->assertFalse( wp_style_is( 'hierarchy-guard-list-table', 'enqueued' ), 'The list keeps its stylesheet in the footer.' );

		$assets->want_list_style();
		$assets->enqueue_admin( 'edit.php' );
		$this->assertTrue( wp_style_is( 'hierarchy-guard-list-table', 'enqueued' ), 'The tree’s indents print with the head, before its rows.' );
		$this->assertSame( 'abc123', wp_styles()->registered['hierarchy-guard-list-table']->ver );
		$this->assertFalse( wp_script_is( 'hierarchy-guard-list-table', 'enqueued' ), 'The script still waits for the footer.' );
	}

	public function test_the_plugin_listens_for_the_tree_s_stylesheet(): void {
		global $wp_filter;
		$found = false;
		foreach ( $wp_filter[ Tree_View::STYLE ]->callbacks[10] ?? [] as $callback ) {
			$found = $found || ( is_array( $callback['function'] ) && $callback['function'][0] instanceof Assets );
		}

		$this->assertTrue( $found );
	}

	public function test_the_redirects_tab_gets_the_bundle_that_asks_before_a_delete(): void {
		$this->build( 'redirects', [ 'wp-dom-ready' ] );
		$assets = new Assets( new Providers(), $this->dir );

		$assets->enqueue_admin( 'settings_page_hierarchy-guard' );
		$this->assertFalse( wp_script_is( 'hierarchy-guard-redirects', 'enqueued' ), 'Not on the Settings tab.' );

		$_GET['tab'] = 'redirects';
		$assets->enqueue_admin( 'settings_page_hierarchy-guard' );
		$this->assertTrue( wp_script_is( 'hierarchy-guard-redirects', 'enqueued' ) );
		$script = wp_scripts()->registered['hierarchy-guard-redirects'];
		$this->assertStringNotContainsString( 'window.hierarchyGuard', implode( '', (array) ( $script->extra['before'] ?? [] ) ), 'It reads only the questions the page prints: no settings, and no REST nonce.' );
		$this->assertEmpty( $script->textdomain ?? '', 'It has no strings of its own.' );

		wp_dequeue_script( 'hierarchy-guard-redirects' );
		$assets->enqueue_admin( 'options-general.php' );
		$this->assertFalse( wp_script_is( 'hierarchy-guard-redirects', 'enqueued' ), 'Not on another settings page.' );
	}
}
