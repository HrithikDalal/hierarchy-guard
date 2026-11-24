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

	public function test_home_absolute_takes_the_home_url_s_scheme_whatever_the_request_s(): void {
		$https = $_SERVER['HTTPS'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Saved as it was, to put back.
		$home  = 'http://example.org/blog';
		$read  = static function () use ( &$home ): string {
			return $home;
		};
		add_filter( 'pre_option_home', $read );
		try {
			$_SERVER['HTTPS'] = 'on';
			$this->assertSame( 'http://example.org/blog/careers/?ref=1#jobs', Paths::home_absolute( '/careers/?ref=1#jobs' ), 'An admin forced to SSL still gets the home URL’s http.' );

			unset( $_SERVER['HTTPS'] );
			$home = 'https://example.org';
			$this->assertSame( 'https://example.org/', Paths::home_absolute( '/' ) );
		} finally {
			remove_filter( 'pre_option_home', $read );
			if ( null === $https ) {
				unset( $_SERVER['HTTPS'] );
			} else {
				$_SERVER['HTTPS'] = $https;
			}
		}
	}

	public function test_uri_follows_a_chain_of_any_depth_as_get_page_uri_does(): void {
		$parent = 0;
		for ( $level = 1; $level <= 60; $level++ ) {
			$parent = self::factory()->post->create(
				[
					'post_type'   => 'page',
					'post_name'   => "level-{$level}",
					'post_parent' => $parent,
				]
			);
		}

		$this->assertSame( 60, substr_count( Paths::uri( get_post( $parent ) ), '/' ) + 1 );
		$this->assertSame( Paths::normalize( get_page_uri( $parent ) ), Paths::uri( get_post( $parent ) ) );
	}

	public function test_a_loop_in_the_parent_chain_ends_the_walk(): void {
		global $wpdb;
		$a = self::factory()->post->create(
			[
				'post_type' => 'page',
				'post_name' => 'loop-a',
			]
		);
		$b = self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_name'   => 'loop-b',
				'post_parent' => $a,
			]
		);
		$wpdb->update( $wpdb->posts, [ 'post_parent' => $b ], [ 'ID' => $a ] );
		clean_post_cache( $a );

		$this->assertSame( 'loop-a/loop-b', Paths::uri( get_post( $b ) ) );
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

	public function test_trashed_slug_helpers_match_numbered_suffixes(): void {
		foreach ( [ 'about__trashed', 'about__trashed-2', 'about__trashed-12' ] as $slug ) {
			$this->assertTrue( Paths::is_trashed_slug( $slug ), $slug );
		}
		foreach ( [ 'about', 'trashed', 'about-trashed', 'about__trashed-x', '__trashed-about' ] as $slug ) {
			$this->assertFalse( Paths::is_trashed_slug( $slug ), $slug );
		}
		$this->assertSame( 'about', Paths::strip_trashed_suffix( 'about__trashed-2' ) );
		$this->assertSame( 'about', Paths::strip_trashed_suffix( 'about__trashed' ) );
		$this->assertTrue( Paths::has_trashed_segment( 'company/about__trashed-2/team' ) );
		$this->assertFalse( Paths::has_trashed_segment( 'company/about/team' ) );
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

	public function test_untrashed_uri_restores_trashed_segments(): void {
		global $wpdb;

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

		// about trashed with core's desired-slug meta; team trashed second, numbered, without it.
		add_post_meta( $about, '_wp_desired_post_slug', 'about' );
		$wpdb->update( $wpdb->posts, [ 'post_name' => 'about__trashed' ], [ 'ID' => $about ] );
		$wpdb->update( $wpdb->posts, [ 'post_name' => 'team__trashed-2' ], [ 'ID' => $team ] );
		clean_post_cache( $about );
		clean_post_cache( $team );

		$this->assertSame( 'company/about__trashed/team__trashed-2', Paths::uri( get_post( $team ) ), 'Precondition: the live path runs through the trash.' );
		$this->assertSame( 'company/about/team', Paths::untrashed_uri( get_post( $team ) ) );
		$this->assertSame( 'company', Paths::untrashed_uri( get_post( $company ) ), 'Untrashed segments are unchanged.' );
	}

	public function test_url_builds_the_permalink_core_would_build_for_a_path(): void {
		$parent = self::factory()->post->create(
			[
				'post_type' => 'page',
				'post_name' => 'about',
			]
		);
		$child  = self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_name'   => 'team',
				'post_parent' => $parent,
			]
		);

		$this->assertSame( get_permalink( $child ), Paths::url( 'page', 'about/team' ) );
		$this->assertSame( home_url( '/old/path/' ), Paths::url( 'page', '/Old/Path/' ) );
	}

	public function test_url_uses_the_rewrite_prefix_of_a_custom_type(): void {
		register_post_type(
			'hg_doc',
			[
				'public'       => true,
				'hierarchical' => true,
				'rewrite'      => [ 'slug' => 'docs' ],
			]
		);
		$this->set_permalink_structure( '/%postname%/' );

		$this->assertSame( home_url( '/docs/guide/intro/' ), Paths::url( 'hg_doc', 'guide/intro' ) );
		$this->assertSame( '', Paths::url( 'hg_none', 'guide' ), 'A type without a permastruct has no URL.' );
		unregister_post_type( 'hg_doc' );
	}

	public function test_url_is_empty_without_pretty_permalinks(): void {
		$this->set_permalink_structure( '' );

		$this->assertSame( '', Paths::url( 'page', 'about' ) );
	}

	public function test_relative_strips_the_home_url(): void {
		$this->assertSame( '/about/team/', Paths::relative( home_url( '/about/team/' ) ) );
		$this->assertSame( '/', Paths::relative( home_url() ) );
		$this->assertSame( '/about/?x=1', Paths::relative( home_url( '/about/?x=1' ) ) );
	}

	public function test_relative_strips_a_subdirectory_home(): void {
		$filter = static fn( $url, $path ): string => 'http://example.org/blog/' . ltrim( (string) $path, '/' );
		add_filter( 'home_url', $filter, 10, 2 );

		$this->assertSame( '/about/', Paths::relative( 'http://example.org/blog/about/' ) );
		$this->assertSame( '/', Paths::relative( 'http://example.org/blog/' ) );
		remove_filter( 'home_url', $filter, 10 );
	}
}
