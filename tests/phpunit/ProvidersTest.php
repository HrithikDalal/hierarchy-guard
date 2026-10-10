<?php
/**
 * Redirect plugin detection.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Redirects\Providers;
use WP_UnitTestCase;

/**
 * @covers \HierarchyGuard\Redirects\Providers
 */
final class ProvidersTest extends WP_UnitTestCase {

	public function test_known_providers_are_listed_with_the_required_shape(): void {
		$all = ( new Providers() )->all();

		foreach ( [ 'redirection', 'safe-redirect-manager', 'yoast-premium', 'rank-math', 'aioseo', 'seopress-pro', 'simple-301' ] as $slug ) {
			$this->assertArrayHasKey( $slug, $all );
			$this->assertIsString( $all[ $slug ]['name'] );
			$this->assertIsBool( $all[ $slug ]['active'] );
			$this->assertStringStartsWith( admin_url(), $all[ $slug ]['url'] );
			$this->assertContains( $all[ $slug ]['csv'], [ 'redirection', 'srm', 'generic' ] );
		}
	}

	public function test_detect_returns_null_when_no_provider_is_active(): void {
		$this->assertNull( ( new Providers() )->detect() );
	}

	public function test_filter_can_add_an_active_provider(): void {
		$filter = static function ( array $providers ): array {
			$providers['custom'] = [
				'name'   => 'Custom Redirects',
				'active' => true,
				'url'    => admin_url( 'admin.php?page=custom-redirects' ),
				'csv'    => 'generic',
			];
			return $providers;
		};
		add_filter( 'hierarchy_guard_redirect_providers', $filter );

		$detected = ( new Providers() )->detect();
		$this->assertSame( 'custom', $detected['slug'] );
		$this->assertSame( 'Custom Redirects', $detected['name'] );

		remove_filter( 'hierarchy_guard_redirect_providers', $filter );
	}

	public function test_detect_ignores_entries_without_an_active_flag(): void {
		$filter = static function ( array $providers ): array {
			$providers['broken'] = [ 'name' => 'Broken' ];
			return $providers;
		};
		add_filter( 'hierarchy_guard_redirect_providers', $filter );
		$this->assertNull( ( new Providers() )->detect() );
		remove_filter( 'hierarchy_guard_redirect_providers', $filter );
	}
}
