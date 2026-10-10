<?php
/**
 * Supported post types.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Post_Types;
use HierarchyGuard\Settings;
use WP_UnitTestCase;

/**
 * @covers \HierarchyGuard\Post_Types
 */
final class PostTypesTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( Settings::OPTION );
		Post_Types::reset();
	}

	public function tear_down(): void {
		unregister_post_type( 'hg_docs' );
		unregister_post_type( 'hg_flat' );
		Post_Types::reset();
		parent::tear_down();
	}

	public function test_pages_are_supported_by_default(): void {
		$supported = Post_Types::supported();
		$this->assertContains( 'page', $supported );
		$this->assertNotContains( 'post', $supported );
		$this->assertNotContains( 'attachment', $supported );
		$this->assertTrue( Post_Types::is_supported( 'page' ) );
		$this->assertFalse( Post_Types::is_supported( 'post' ) );
	}

	public function test_public_hierarchical_custom_types_are_included(): void {
		register_post_type(
			'hg_docs',
			[
				'public'       => true,
				'hierarchical' => true,
			]
		);
		register_post_type( 'hg_flat', [ 'public' => true ] );
		Post_Types::reset();

		$this->assertContains( 'hg_docs', Post_Types::supported() );
		$this->assertNotContains( 'hg_flat', Post_Types::supported() );
	}

	public function test_registering_a_type_resets_the_memoised_list(): void {
		$this->assertNotContains( 'hg_docs', Post_Types::supported() );
		register_post_type(
			'hg_docs',
			[
				'public'       => true,
				'hierarchical' => true,
			]
		);
		$this->assertContains( 'hg_docs', Post_Types::supported(), 'The registered_post_type hook must reset the cache.' );
	}

	public function test_settings_restrict_supported_types(): void {
		register_post_type(
			'hg_docs',
			[
				'public'       => true,
				'hierarchical' => true,
			]
		);
		update_option( Settings::OPTION, [ 'post_types' => [ 'hg_docs' ] ] );

		$this->assertSame( [ 'hg_docs' ], Post_Types::supported(), 'Saving the option must reset the cache and restrict the list.' );
	}

	public function test_filter_has_the_final_say(): void {
		$filter = static fn(): array => [ 'page', 'hg_docs' ];
		add_filter( 'hierarchy_guard_post_types', $filter );
		Post_Types::reset();
		$this->assertSame( [ 'page', 'hg_docs' ], Post_Types::supported() );
		remove_filter( 'hierarchy_guard_post_types', $filter );
	}
}
