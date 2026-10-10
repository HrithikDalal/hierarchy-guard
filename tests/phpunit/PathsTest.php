<?php
/**
 * Path normalization and slug-chain URIs.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Paths;
use HierarchyGuard\Post_Types;
use HierarchyGuard\Settings;
use WP_UnitTestCase;

/**
 * @covers \HierarchyGuard\Paths
 */
final class PathsTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( Settings::OPTION );
		Post_Types::reset();
		$this->set_permalink_structure( '/%postname%/' );
	}

	public function tear_down(): void {
		$this->set_permalink_structure( '' );
		parent::tear_down();
	}

	public function test_normalize_trims_lowercases_and_drops_empty_segments(): void {
		$this->assertSame( 'about/team', Paths::normalize( '/About/Team/' ) );
		$this->assertSame( 'about/team', Paths::normalize( 'about//team' ) );
		$this->assertSame( '', Paths::normalize( '///' ) );
	}

	public function test_normalize_treats_encoded_and_decoded_slugs_alike(): void {
		$decoded = 'о-нас';
		$encoded = rawurlencode( $decoded );

		$this->assertSame( Paths::normalize( $encoded ), Paths::normalize( $decoded ) );
		$this->assertSame( sanitize_title( $decoded ), Paths::normalize( $decoded ), 'Stored post_name values and request segments must normalize to the same string.' );
	}

	public function test_uri_follows_the_parent_chain(): void {
		$company = self::factory()->post->create(
			[
				'post_type' => 'page',
				'post_name' => 'company',
			] 
		);
		$about   = self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_name'   => 'about',
				'post_parent' => $company,
			] 
		);
		$team    = self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_name'   => 'team',
				'post_parent' => $about,
			] 
		);

		$this->assertSame( 'company/about/team', Paths::uri( get_post( $team ) ) );
		$this->assertSame( 'company', Paths::uri( get_post( $company ) ) );
	}

	public function test_uri_uses_the_object_parent_not_the_database(): void {
		$company = self::factory()->post->create(
			[
				'post_type' => 'page',
				'post_name' => 'company',
			] 
		);
		$about   = self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_name'   => 'about',
				'post_parent' => $company,
			] 
		);
		$team    = self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_name'   => 'team',
				'post_parent' => $about,
			] 
		);

		$before = clone get_post( $team );
		wp_update_post(
			[
				'ID'          => $team,
				'post_parent' => $company,
			] 
		);

		$this->assertSame( 'company/about/team', Paths::uri( $before ), 'A before-object still describes the old chain.' );
		$this->assertSame( 'company/team', Paths::uri( get_post( $team ) ) );
	}

	public function test_uri_skips_ancestors_with_empty_slugs_like_core(): void {
		$draft = self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_status' => 'draft',
				'post_name'   => '',
			] 
		);
		$child = self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_name'   => 'child',
				'post_parent' => $draft,
			] 
		);

		$this->assertSame( '', get_post( $draft )->post_name, 'Precondition: drafts may have an empty slug.' );
		$this->assertSame( get_page_uri( $child ), Paths::uri( get_post( $child ) ) );
	}

	public function test_is_recordable_requires_pretty_permalinks(): void {
		$page = get_post(
			self::factory()->post->create(
				[
					'post_type' => 'page',
					'post_name' => 'about',
				] 
			) 
		);
		$this->assertTrue( Paths::is_recordable( $page ) );

		$this->set_permalink_structure( '' );
		$this->assertFalse( Paths::is_recordable( $page ) );
	}

	public function test_is_recordable_requires_published_supported_type_with_slug(): void {
		$draft = get_post(
			self::factory()->post->create(
				[
					'post_type'   => 'page',
					'post_status' => 'draft',
					'post_name'   => 'draft',
				] 
			) 
		);
		$post  = get_post(
			self::factory()->post->create(
				[
					'post_type' => 'post',
					'post_name' => 'hello',
				] 
			) 
		);
		$page  = get_post(
			self::factory()->post->create(
				[
					'post_type' => 'page',
					'post_name' => 'about',
				] 
			) 
		);

		$this->assertFalse( Paths::is_recordable( $draft ) );
		$this->assertFalse( Paths::is_recordable( $post ) );
		$this->assertTrue( Paths::is_recordable( $page ) );

		$nameless            = clone $page;
		$nameless->post_name = '';
		$this->assertFalse( Paths::is_recordable( $nameless ) );

		$trashed            = clone $page;
		$trashed->post_name = 'about__trashed';
		$this->assertFalse( Paths::is_recordable( $trashed ) );
	}
}
