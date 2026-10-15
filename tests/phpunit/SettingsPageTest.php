<?php
/**
 * Settings › Hierarchy Guard.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Admin\Settings_Page;
use HierarchyGuard\Ledger;
use HierarchyGuard\Post_Types;
use HierarchyGuard\Redirects\Providers;
use HierarchyGuard\Redirects\Records;
use HierarchyGuard\Settings;
use WP_UnitTestCase;

/**
 * @covers \HierarchyGuard\Admin\Settings_Page
 * @covers \HierarchyGuard\Settings::register_option
 */
final class SettingsPageTest extends WP_UnitTestCase {

	private Settings_Page $page;

	public function set_up(): void {
		parent::set_up();
		require_once ABSPATH . 'wp-admin/includes/template.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		delete_option( Settings::OPTION );
		Post_Types::reset();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->page = new Settings_Page( new Providers(), new Records( new Ledger() ) );
	}

	public function tear_down(): void {
		// add_fields() and add_page() write core's globals; later tests must not see them.
		unset( $GLOBALS['wp_settings_sections'][ Settings_Page::SLUG ], $GLOBALS['wp_settings_fields'][ Settings_Page::SLUG ], $GLOBALS['submenu']['options-general.php'] );
		parent::tear_down();
	}

	/**
	 * Whether a callback of the plugin's is hooked at priority 10. has_action( $hook )
	 * alone proves nothing: core hooks the same actions.
	 *
	 * @param string   $hook     Hook name.
	 * @param callable $matches  Tells the plugin's callback: fn( mixed $function ): bool.
	 */
	private function hooked( string $hook, callable $matches ): bool {
		global $wp_filter;
		foreach ( $wp_filter[ $hook ]->callbacks[10] ?? [] as $callback ) {
			if ( $matches( $callback['function'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a Settings_Page method is hooked at priority 10.
	 *
	 * @param string $hook   Hook name.
	 * @param string $method Method name.
	 */
	private function page_on( string $hook, string $method ): bool {
		return $this->hooked( $hook, static fn( $callback ): bool => is_array( $callback ) && $callback[0] instanceof Settings_Page && $method === $callback[1] );
	}

	public function test_the_plugin_hooks_the_page_the_fields_and_the_plugins_row_link(): void {
		$this->assertTrue( $this->page_on( 'admin_menu', 'add_page' ) );
		$this->assertTrue( $this->page_on( 'admin_init', 'add_fields' ) );
		$this->assertTrue( $this->page_on( 'plugin_action_links_' . plugin_basename( HIERARCHY_GUARD_FILE ), 'action_links' ) );
		$this->assertSame( 'hierarchy-guard/hierarchy-guard.php', plugin_basename( HIERARCHY_GUARD_FILE ) );
	}

	public function test_the_option_is_registered_on_init_in_the_page_s_group(): void {
		global $wp_registered_settings;

		$this->assertTrue( $this->hooked( 'init', static fn( $callback ): bool => [ Settings::class, 'register_option' ] === $callback ), 'Registering on init lets the sanitize callback cover WP-CLI and REST writes.' );

		unregister_setting( Settings::GROUP, Settings::OPTION );
		$this->assertArrayNotHasKey( Settings::OPTION, $wp_registered_settings, 'Precondition: the bootstrap init registration is undone.' );
		Settings::register_option();

		$this->assertSame( [ Settings::class, 'sanitize' ], $wp_registered_settings[ Settings::OPTION ]['sanitize_callback'] );
		$this->assertSame( 'hierarchy_guard', $wp_registered_settings[ Settings::OPTION ]['group'] );
	}

	public function test_the_page_sits_under_settings_for_users_who_can_manage_options(): void {
		global $submenu;

		$this->page->add_page();

		$entry = array_values( array_filter( $submenu['options-general.php'] ?? [], static fn( array $item ): bool => Settings_Page::SLUG === $item[2] ) );
		$this->assertCount( 1, $entry );
		$this->assertSame( [ 'Hierarchy Guard', 'manage_options' ], [ $entry[0][0], $entry[0][1] ] );
		$this->assertStringEndsWith( '/wp-admin/options-general.php?page=hierarchy-guard', Settings_Page::url() );
		$this->assertStringEndsWith( '/wp-admin/options-general.php?page=hierarchy-guard&tab=redirects', Settings_Page::url( 'redirects' ) );
	}

	public function test_the_fields_are_on_the_page_and_nothing_is_on_settings_writing(): void {
		global $wp_settings_sections, $wp_settings_fields;

		$this->page->add_fields();

		$this->assertSame( '', $wp_settings_sections[ Settings_Page::SLUG ][ Settings_Page::SECTION ]['title'], 'The page’s heading is enough.' );
		$this->assertSame( [ 'hierarchy_guard_post_types', 'hierarchy_guard_trash_policy', 'hierarchy_guard_serve_redirects' ], array_keys( $wp_settings_fields[ Settings_Page::SLUG ][ Settings_Page::SECTION ] ) );
		$this->assertArrayNotHasKey( 'hierarchy_guard', $wp_settings_sections['writing'] ?? [] );
		$this->assertArrayNotHasKey( 'hierarchy_guard', $wp_settings_fields['writing'] ?? [] );
	}

	public function test_the_settings_tab_is_a_settings_api_form_under_core_nav_tabs(): void {
		$this->page->add_fields();

		ob_start();
		$this->page->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<h1>Hierarchy Guard</h1>', $html );
		$this->assertStringContainsString( '<nav class="nav-tab-wrapper wp-clearfix" aria-label="Secondary menu">', $html );
		$this->assertMatchesRegularExpression( '#</nav><hr class="wp-header-end"><form#', $html, 'Core’s notices land below the tabs.' );
		$this->assertMatchesRegularExpression( '#<a href="[^"]*options-general\.php\?page=hierarchy-guard" class="nav-tab nav-tab-active" aria-current="page">Settings</a>#', $html );
		$this->assertStringContainsString( '<form action="options.php" method="post">', $html );
		$this->assertStringContainsString( "name='option_page' value='hierarchy_guard'", $html );
		$this->assertStringContainsString( 'name="hierarchy_guard_settings[trash_policy]"', $html );
		$this->assertStringContainsString( 'id="submit"', $html );
	}

	public function test_without_manage_options_the_page_prints_nothing(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		ob_start();
		$this->page->render();
		$this->assertSame( '', (string) ob_get_clean() );
	}

	public function test_the_plugins_row_gets_a_settings_link_first_for_administrators(): void {
		$links = $this->page->action_links( [ 'deactivate' => '<a href="#">Deactivate</a>' ] );

		$this->assertSame( [ 'settings', 'deactivate' ], array_keys( $links ) );
		$this->assertSame( '<a href="' . esc_url( admin_url( 'options-general.php?page=hierarchy-guard' ) ) . '">Settings</a>', $links['settings'] );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->assertSame( [ 'deactivate' ], array_keys( $this->page->action_links( [ 'deactivate' => '<a href="#">Deactivate</a>' ] ) ) );
	}

	public function test_post_types_field_lists_hierarchical_types_with_current_state(): void {
		ob_start();
		$this->page->render_post_types();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="hierarchy_guard_settings[post_types][]"', $html );
		$this->assertStringContainsString( 'value="page"', $html );
		$this->assertMatchesRegularExpression( '/value="page"[^>]*checked/', $html );
		$this->assertStringNotContainsString( 'value="post"', $html );
		$this->assertStringContainsString( 'Public hierarchical post types appear here automatically.', $html );
	}

	public function test_post_types_field_always_submits_the_key(): void {
		ob_start();
		$this->page->render_post_types();
		$html = (string) ob_get_clean();

		$hidden = '<input type="hidden" name="hierarchy_guard_settings[post_types][]" value="">';
		$this->assertStringContainsString( $hidden, $html );
		$this->assertLessThan( strpos( $html, 'type="checkbox"' ), strpos( $html, $hidden ), 'The hidden input comes before the checkboxes.' );
	}

	public function test_post_types_field_shows_the_stored_choice_not_the_filtered_list(): void {
		register_post_type(
			'hg_docs',
			[
				'public'       => true,
				'hierarchical' => true,
			]
		);
		$filter = static fn( array $types ): array => array_values( array_diff( $types, [ 'hg_docs' ] ) );
		add_filter( 'hierarchy_guard_post_types', $filter );
		Post_Types::reset();
		$this->assertNotContains( 'hg_docs', Post_Types::supported(), 'Precondition: the filter removes the type.' );

		ob_start();
		$this->page->render_post_types();
		$html = (string) ob_get_clean();
		remove_filter( 'hierarchy_guard_post_types', $filter );
		unregister_post_type( 'hg_docs' );

		$this->assertMatchesRegularExpression( '/value="hg_docs"[^>]*checked/', $html, 'With the all-types default stored, a filtered-out type still renders checked.' );
	}

	public function test_serve_redirects_field_names_the_detected_provider_or_says_none_found(): void {
		ob_start();
		$this->page->render_serve_redirects();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'name="hierarchy_guard_settings[serve_redirects]"', $html );
		$this->assertStringNotContainsString( 'checked', $html );
		$this->assertStringContainsString( 'No redirect plugin was found.', $html );

		$filter = static function ( array $providers ): array {
			$providers['custom'] = [
				'name'   => 'Custom Redirects',
				'active' => true,
				'url'    => admin_url( 'admin.php?page=x' ),
				'csv'    => 'generic',
			];
			return $providers;
		};
		add_filter( 'hierarchy_guard_redirect_providers', $filter );
		ob_start();
		$this->page->render_serve_redirects();
		$html = (string) ob_get_clean();
		remove_filter( 'hierarchy_guard_redirect_providers', $filter );

		$this->assertStringContainsString( 'Custom Redirects is active and can handle these.', $html );
	}

	public function test_trash_policy_field_renders_three_radios_with_the_stored_choice(): void {
		update_option( Settings::OPTION, [ 'trash_policy' => Settings::POLICY_KEEP ] );

		ob_start();
		$this->page->render_trash_policy();
		$html = (string) ob_get_clean();

		$this->assertSame( 3, substr_count( $html, 'type="radio"' ) );
		$this->assertStringContainsString( '<fieldset id="hierarchy-guard-trash-policy">', $html, 'The target of the editor panel’s Settings link.' );
		$this->assertMatchesRegularExpression( '/value="keep"\s+checked/', $html );
		$this->assertStringContainsString( 'Move subpages up one level', $html );
		$this->assertStringContainsString( 'Trash the subpages too', $html );
		$this->assertStringContainsString( 'Leave subpages where they are', $html );
		$this->assertStringContainsString( 'Used when there’s no chance to ask, for example in the editor, the REST API, or WP-CLI. The Pages list asks each time.', $html );
		$this->assertStringNotContainsString( 'The Trash is turned off', $html, 'This test site keeps its Trash.' );
	}

	public function test_a_provider_without_a_name_gets_a_generic_sentence(): void {
		$filter = static fn(): array => [
			'custom' => [
				'active' => true,
				'url'    => '',
				'csv'    => 'generic',
			],
		];
		add_filter( 'hierarchy_guard_redirect_providers', $filter );
		ob_start();
		$this->page->render_serve_redirects();
		$html = (string) ob_get_clean();
		remove_filter( 'hierarchy_guard_redirect_providers', $filter );

		$this->assertStringContainsString( 'A redirect plugin is active and can handle these.', $html );
	}

	public function test_settings_links_open_the_page_at_their_field(): void {
		ob_start();
		$this->page->render_serve_redirects();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'id="hierarchy-guard-redirects"', $html );
		$this->assertSame( admin_url( 'options-general.php?page=hierarchy-guard#hierarchy-guard-redirects' ), Settings_Page::redirects_url() );
		$this->assertSame( admin_url( 'options-general.php?page=hierarchy-guard#hierarchy-guard-trash-policy' ), Settings_Page::policy_url() );
	}

	public function test_the_settings_globals_do_not_leak_into_the_next_test(): void {
		global $wp_settings_sections;

		$this->assertArrayNotHasKey( Settings_Page::SLUG, $wp_settings_sections ?? [], 'tear_down() removed what an earlier add_fields() registered.' );
	}
}
