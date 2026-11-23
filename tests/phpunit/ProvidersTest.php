<?php
/**
 * Redirect plugin detection.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Export\Csv;
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
			$this->assertArrayHasKey( $all[ $slug ]['csv'], Csv::LAYOUTS );
		}
	}

	public function test_each_provider_gets_its_own_import_layout_or_the_generic_one(): void {
		$layouts = array_map( static fn( array $provider ): string => $provider['csv'], ( new Providers() )->all() );

		$this->assertSame(
			[
				'redirection'           => 'redirection',
				'safe-redirect-manager' => 'srm',
				'yoast-premium'         => 'yoast',
				'rank-math'             => 'rank-math',
				'aioseo'                => 'generic',
				'seopress-pro'          => 'generic',
				'simple-301'            => 'simple-301',
			],
			$layouts
		);
	}

	public function test_a_provider_whose_import_needs_more_says_so(): void {
		$all = ( new Providers() )->all();

		$this->assertStringContainsString( 'wp safe-redirect-manager import', $all['safe-redirect-manager']['note'] );
		$this->assertStringContainsString( 'PRO', $all['rank-math']['note'] );
		$this->assertStringContainsString( '301', $all['simple-301']['note'] );
		$this->assertArrayNotHasKey( 'note', $all['redirection'] );
	}

	public function test_the_scripts_get_a_provider_s_name_screen_layout_and_note(): void {
		$this->assertNull( Providers::described( null ) );
		$this->assertSame(
			[
				'name' => 'Custom',
				'url'  => admin_url( 'admin.php?page=custom' ),
				'csv'  => 'generic',
				'note' => '',
			],
			Providers::described(
				[
					'name' => 'Custom',
					'url'  => admin_url( 'admin.php?page=custom' ),
					'csv'  => 'unknown',
					'note' => [ 'not text' ],
				]
			)
		);
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
