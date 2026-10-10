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
		global $wp_settings_sections, $wp_settings_fields, $wp_registered_settings;

		$this->section->add();

		$this->assertArrayHasKey( 'hierarchy_guard', $wp_settings_sections['writing'] );
		$this->assertCount( 3, $wp_settings_fields['writing']['hierarchy_guard'] );
		$this->assertArrayHasKey( Settings::OPTION, $wp_registered_settings );
		$this->assertSame( [ Settings::class, 'sanitize' ], $wp_registered_settings[ Settings::OPTION ]['sanitize_callback'] );
	}

	public function test_registration_is_hooked_to_admin_init_by_the_plugin(): void {
		global $wp_filter;

		$found = false;
		foreach ( $wp_filter['admin_init']->callbacks[10] ?? [] as $callback ) {
			if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof Settings_Section && 'add' === $callback['function'][1] ) {
				$found = true;
			}
		}
		$this->assertTrue( $found, 'has_action( "admin_init" ) alone proves nothing: core hooks admin_init too.' );
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
