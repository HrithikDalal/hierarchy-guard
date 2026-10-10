<?php
/**
 * Settings option access and sanitization.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Settings;
use WP_UnitTestCase;

/**
 * @covers \HierarchyGuard\Settings
 */
final class SettingsTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( Settings::OPTION );
	}

	public function test_defaults_when_nothing_is_stored(): void {
		$this->assertNull( Settings::get( 'post_types' ) );
		$this->assertSame( Settings::POLICY_REPARENT, Settings::trash_policy() );
		$this->assertFalse( Settings::serve_redirects() );
	}

	public function test_stored_values_override_defaults(): void {
		update_option(
			Settings::OPTION,
			[
				'serve_redirects' => true,
				'trash_policy'    => Settings::POLICY_TRASH_CHILDREN,
			]
		);
		$this->assertTrue( Settings::serve_redirects() );
		$this->assertSame( Settings::POLICY_TRASH_CHILDREN, Settings::trash_policy() );
		$this->assertNull( Settings::get( 'post_types' ), 'Keys that were never saved keep their defaults.' );
	}

	public function test_sanitize_normalizes_input(): void {
		$clean = Settings::sanitize(
			[
				'post_types'      => [ 'page', 'bogus', '<script>' ],
				'trash_policy'    => 'nonsense',
				'serve_redirects' => '1',
			]
		);
		$this->assertSame( [ 'page' ], $clean['post_types'] );
		$this->assertSame( Settings::POLICY_REPARENT, $clean['trash_policy'] );
		$this->assertTrue( $clean['serve_redirects'] );
	}

	public function test_sanitize_without_checkboxes_means_none_selected(): void {
		$clean = Settings::sanitize( [] );
		$this->assertSame( [], $clean['post_types'] );
		$this->assertFalse( $clean['serve_redirects'] );
	}

	public function test_sanitize_rejects_non_array_input(): void {
		$clean = Settings::sanitize( 'garbage' );
		$this->assertSame( Settings::POLICY_REPARENT, $clean['trash_policy'] );
	}

	public function test_default_trash_policy_filter(): void {
		$filter = static fn(): string => Settings::POLICY_KEEP;
		add_filter( 'hierarchy_guard_default_trash_policy', $filter );
		$this->assertSame( Settings::POLICY_KEEP, Settings::trash_policy() );
		remove_filter( 'hierarchy_guard_default_trash_policy', $filter );
	}

	public function test_invalid_filtered_policy_falls_back_to_default(): void {
		$filter = static fn(): string => 'explode';
		add_filter( 'hierarchy_guard_default_trash_policy', $filter );
		$this->assertSame( Settings::POLICY_REPARENT, Settings::trash_policy() );
		remove_filter( 'hierarchy_guard_default_trash_policy', $filter );
	}
}
