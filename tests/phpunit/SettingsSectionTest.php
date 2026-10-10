<?php
/**
 * The Page hierarchy section on Settings › Writing.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Admin\Settings_Section;
use HierarchyGuard\Post_Types;
use HierarchyGuard\Redirects\Providers;
use HierarchyGuard\Settings;
use WP_UnitTestCase;

/**
 * @covers \HierarchyGuard\Admin\Settings_Section
 */
final class SettingsSectionTest extends WP_UnitTestCase {

	private Settings_Section $section;

	public function set_up(): void {
		parent::set_up();
		require_once ABSPATH . 'wp-admin/includes/template.php';
		delete_option( Settings::OPTION );
		Post_Types::reset();
		$this->section = new Settings_Section( new Providers() );
	}

	public function test_section_and_fields_register_on_the_writing_page(): void {
		global $wp_settings_sections, $wp_settings_fields;

		$this->section->add();

		$this->assertArrayHasKey( 'hierarchy_guard', $wp_settings_sections['writing'] );
		$this->assertCount( 3, $wp_settings_fields['writing']['hierarchy_guard'] );
	}

	public function test_option_registers_with_the_sanitize_callback(): void {
		global $wp_registered_settings;

		unregister_setting( Settings_Section::PAGE, Settings::OPTION );
		$this->assertArrayNotHasKey( Settings::OPTION, $wp_registered_settings, 'Precondition: the bootstrap init registration is undone.' );

		$this->section->register_option();

		$this->assertArrayHasKey( Settings::OPTION, $wp_registered_settings );
		$this->assertSame( [ Settings::class, 'sanitize' ], $wp_registered_settings[ Settings::OPTION ]['sanitize_callback'] );
		$this->assertSame( Settings_Section::PAGE, $wp_registered_settings[ Settings::OPTION ]['group'] );
	}

	public function test_option_is_registered_on_init_by_the_plugin(): void {
		$this->assertTrue( $this->plugin_section_on( 'init', 'register_option' ), 'Registering on init lets the sanitize callback cover WP-CLI and REST writes.' );
		$this->assertFalse( $this->plugin_section_on( 'admin_init', 'register_option' ) );
	}

	public function test_section_is_added_on_admin_init_by_the_plugin(): void {
		$this->assertTrue( $this->plugin_section_on( 'admin_init', 'add' ) );
	}

	/**
	 * Whether a Settings_Section method is hooked at priority 10. has_action( $hook )
	 * alone proves nothing: core hooks init and admin_init too.
	 */
	private function plugin_section_on( string $hook, string $method ): bool {
		global $wp_filter;
		foreach ( $wp_filter[ $hook ]->callbacks[10] ?? [] as $callback ) {
			if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof Settings_Section && $method === $callback['function'][1] ) {
				return true;
			}
		}
		return false;
	}

	public function test_post_types_field_lists_hierarchical_types_with_current_state(): void {
		ob_start();
		$this->section->render_post_types();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="hierarchy_guard_settings[post_types][]"', $html );
		$this->assertStringContainsString( 'value="page"', $html );
		$this->assertMatchesRegularExpression( '/value="page"[^>]*checked/', $html );
		$this->assertStringNotContainsString( 'value="post"', $html );
		$this->assertStringContainsString( 'Public hierarchical post types appear here automatically.', $html );
	}

	public function test_post_types_field_always_submits_the_key(): void {
		ob_start();
		$this->section->render_post_types();
		$html = (string) ob_get_clean();

		$hidden = '<input type="hidden" name="hierarchy_guard_settings[post_types][]" value="">';
		$this->assertStringContainsString( $hidden, $html );
		$this->assertLessThan( strpos( $html, 'type="checkbox"' ), strpos( $html, $hidden ), 'The hidden input comes before the checkboxes.' );
	}

	public function test_trash_policy_field_renders_three_radios_with_the_default_selected(): void {
		ob_start();
		$this->section->render_trash_policy();
		$html = (string) ob_get_clean();

		$this->assertSame( 3, substr_count( $html, 'type="radio"' ) );
		$this->assertMatchesRegularExpression( '/value="reparent"[^>]*checked/', $html );
		$this->assertStringContainsString( 'Move subpages up one level', $html );
		$this->assertStringContainsString( 'Not applied when the trash is disabled.', $html );
	}

	public function test_serve_redirects_field_names_the_detected_provider_or_says_none_found(): void {
		ob_start();
		$this->section->render_serve_redirects();
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
		$this->section->render_serve_redirects();
		$html = (string) ob_get_clean();
		remove_filter( 'hierarchy_guard_redirect_providers', $filter );

		$this->assertStringContainsString( 'Custom Redirects is active and can handle these.', $html );
	}
}
