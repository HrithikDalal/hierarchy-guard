<?php
/**
 * Serving redirects on 404s.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Ledger;
use HierarchyGuard\Post_Types;
use HierarchyGuard\Redirects\Resolver;
use HierarchyGuard\Redirects\Server;
use HierarchyGuard\Settings;
use WP_UnitTestCase;

/**
 * @covers \HierarchyGuard\Redirects\Server
 */
final class ServerTest extends WP_UnitTestCase {

	private Server $server;

	private int $company;

	private int $about;

	private int $team;

	public function set_up(): void {
		parent::set_up();
		delete_option( Ledger::ROOT_OPTION );
		// Writes pass through Settings::sanitize(), where a missing post_types key means none.
		update_option(
			Settings::OPTION,
			[
				'post_types'      => [ 'page' ],
				'serve_redirects' => true,
			]
		);
		Post_Types::reset();
		$this->set_permalink_structure( '/%postname%/' );

		$this->server  = new Server( new Resolver( new Ledger() ) );
		$this->company = self::factory()->post->create(
			[
				'post_type' => 'page',
				'post_name' => 'company',
			] 
		);
		$this->about   = self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_name'   => 'about',
				'post_parent' => $this->company,
			] 
		);
		$this->team    = self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_name'   => 'team',
				'post_parent' => $this->about,
			] 
		);
	}

	public function tear_down(): void {
		unset( $_SERVER['REQUEST_METHOD'], $_SERVER['QUERY_STRING'] );
		unregister_post_type( 'hg_docs' );
		$this->set_permalink_structure( '' );
		parent::tear_down();
	}

	public function test_server_is_registered_before_canonical_redirects(): void {
		$this->assertSame( 9, has_action( 'template_redirect', [ $this->server_from_plugin(), 'maybe_redirect' ] ) );
	}

	public function test_redirects_an_old_subpage_url_to_the_new_permalink(): void {
		wp_update_post(
			[
				'ID'        => $this->about,
				'post_name' => 'company-info',
			] 
		);
		$this->go_to( '/company/about/team/' );

		$this->assertTrue( is_404(), 'Precondition: the old URL no longer resolves.' );
		$target = $this->server->target_for_current_request();
		$this->assertNotNull( $target );
		$this->assertSame( get_permalink( $this->team ), $target['url'] );
		$this->assertSame( 301, $target['status'] );
	}

	public function test_does_nothing_when_serving_is_off(): void {
		update_option(
			Settings::OPTION,
			[
				'post_types'      => [ 'page' ],
				'serve_redirects' => false,
			]
		);
		wp_update_post(
			[
				'ID'        => $this->about,
				'post_name' => 'company-info',
			] 
		);
		$this->go_to( '/company/about/team/' );

		$this->assertNull( $this->server->target_for_current_request() );
	}

	public function test_ignores_requests_that_are_not_get_or_head(): void {
		wp_update_post(
			[
				'ID'        => $this->about,
				'post_name' => 'company-info',
			] 
		);
		$this->go_to( '/company/about/team/' );

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$this->assertNull( $this->server->target_for_current_request() );

		$_SERVER['REQUEST_METHOD'] = 'HEAD';
		$this->assertNotNull( $this->server->target_for_current_request() );
	}

	public function test_does_nothing_for_pages_that_resolve(): void {
		$this->go_to( '/company/about/team/' );

		$this->assertFalse( is_404() );
		$this->assertNull( $this->server->target_for_current_request() );
	}

	public function test_a_new_page_at_the_old_path_is_served_not_redirected(): void {
		wp_update_post(
			[
				'ID'        => $this->about,
				'post_name' => 'company-info',
			] 
		);
		self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_name'   => 'about',
				'post_parent' => $this->company,
			] 
		);
		$this->go_to( '/company/about/' );

		$this->assertFalse( is_404() );
		$this->assertNull( $this->server->target_for_current_request() );
	}

	public function test_preserves_pagination(): void {
		wp_update_post(
			[
				'ID'        => $this->about,
				'post_name' => 'company-info',
			] 
		);
		$this->go_to( '/company/about/team/2/' );

		$this->assertTrue( is_404() );
		$target = $this->server->target_for_current_request();
		$this->assertSame( user_trailingslashit( trailingslashit( get_permalink( $this->team ) ) . '2', 'single_paged' ), $target['url'] );
	}

	public function test_preserves_the_query_string(): void {
		wp_update_post(
			[
				'ID'        => $this->about,
				'post_name' => 'company-info',
			]
		);
		// go_to() leaves QUERY_STRING unset; the web server sets it on a real request.
		$this->go_to( '/company/about/team/?utm_source=x' );
		$_SERVER['QUERY_STRING'] = 'utm_source=x';

		$this->assertTrue( is_404() );
		$this->assertSame( get_permalink( $this->team ) . '?utm_source=x', $this->server->target_for_current_request()['url'] );
	}

	public function test_preserves_feed_and_embed_suffixes(): void {
		wp_update_post(
			[
				'ID'        => $this->about,
				'post_name' => 'company-info',
			] 
		);

		$this->go_to( '/company/about/team/feed/' );
		$this->assertTrue( is_404() );
		$this->assertSame( get_post_comments_feed_link( $this->team ), $this->server->target_for_current_request()['url'] );

		$this->go_to( '/company/about/team/embed/' );
		$this->assertTrue( is_404() );
		$this->assertSame( user_trailingslashit( trailingslashit( get_permalink( $this->team ) ) . 'embed', 'embed' ), $this->server->target_for_current_request()['url'] );
	}

	public function test_two_segment_old_url_redirects(): void {
		// Two segments match core's post-attachment rule, so the 404 carries
		// attachment=design rather than a pagename. The path must still resolve.
		$services = self::factory()->post->create(
			[
				'post_type' => 'page',
				'post_name' => 'services',
			] 
		);
		$design   = self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_name'   => 'design',
				'post_parent' => $services,
			] 
		);
		wp_update_post(
			[
				'ID'        => $services,
				'post_name' => 'solutions',
			] 
		);

		$this->go_to( '/services/design/' );
		$this->assertTrue( is_404() );
		$this->assertSame( get_permalink( $design ), $this->server->target_for_current_request()['url'] );
	}

	public function test_status_and_url_filters_apply_and_offsite_urls_are_refused(): void {
		wp_update_post(
			[
				'ID'        => $this->about,
				'post_name' => 'company-info',
			] 
		);
		$this->go_to( '/company/about/team/' );

		$status = static fn(): int => 302;
		add_filter( 'hierarchy_guard_redirect_status', $status );
		$this->assertSame( 302, $this->server->target_for_current_request()['status'] );
		remove_filter( 'hierarchy_guard_redirect_status', $status );

		$offsite = static fn(): string => 'https://evil.example/elsewhere';
		add_filter( 'hierarchy_guard_redirect_url', $offsite );
		$this->assertNull( $this->server->target_for_current_request() );
		remove_filter( 'hierarchy_guard_redirect_url', $offsite );
	}

	public function test_unicode_slugs_resolve(): void {
		$cyrillic = self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_title'  => 'О нас',
				'post_parent' => $this->company,
			] 
		);
		$child    = self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_name'   => 'team',
				'post_parent' => $cyrillic,
			] 
		);
		$old_slug = get_post( $cyrillic )->post_name;
		$this->assertStringContainsString( '%', $old_slug, 'Precondition: core stores non-ASCII slugs percent-encoded.' );

		wp_update_post(
			[
				'ID'        => $cyrillic,
				'post_name' => 'about-us',
			] 
		);

		$this->go_to( '/company/' . $old_slug . '/team/' );
		$this->assertTrue( is_404() );
		$this->assertSame( get_permalink( $child ), $this->server->target_for_current_request()['url'] );

		$this->go_to( '/company/' . urldecode( $old_slug ) . '/team/' );
		$this->assertTrue( is_404() );
		$this->assertSame( get_permalink( $child ), $this->server->target_for_current_request()['url'] );
	}

	public function test_index_php_permalink_structure(): void {
		$this->set_permalink_structure( '/index.php/%postname%/' );
		wp_update_post(
			[
				'ID'        => $this->about,
				'post_name' => 'company-info',
			] 
		);
		$this->go_to( '/index.php/company/about/team/' );

		$this->assertTrue( is_404() );
		$target = $this->server->target_for_current_request();
		$this->assertStringContainsString( '/index.php/', $target['url'] );
		$this->assertSame( get_permalink( $this->team ), $target['url'] );
	}

	public function test_hierarchical_custom_post_type_with_rewrite_prefix(): void {
		register_post_type(
			'hg_docs',
			[
				'public'       => true,
				'hierarchical' => true,
				'rewrite'      => [
					'slug'         => 'docs',
					'hierarchical' => true,
				],
			]
		);
		update_option(
			Settings::OPTION,
			[
				'post_types'      => [ 'page', 'hg_docs' ],
				'serve_redirects' => true,
			]
		);
		$this->set_permalink_structure( '/%postname%/' );

		$guide   = self::factory()->post->create(
			[
				'post_type' => 'hg_docs',
				'post_name' => 'guide',
			] 
		);
		$install = self::factory()->post->create(
			[
				'post_type'   => 'hg_docs',
				'post_name'   => 'install',
				'post_parent' => $guide,
			] 
		);
		$this->assertSame( home_url( '/docs/guide/install/' ), get_permalink( $install ), 'Precondition: the rewrite prefix is in the permalink.' );

		wp_update_post(
			[
				'ID'        => $guide,
				'post_name' => 'handbook',
			] 
		);
		$this->go_to( '/docs/guide/install/' );

		$this->assertTrue( is_404() );
		$this->assertSame( get_permalink( $install ), $this->server->target_for_current_request()['url'] );
	}

	public function test_overlong_paths_are_ignored(): void {
		wp_update_post(
			[
				'ID'        => $this->about,
				'post_name' => 'company-info',
			] 
		);
		$this->go_to( '/company/about/team' . str_repeat( '/x', 18 ) . '/' );

		$this->assertTrue( is_404() );
		$before = get_num_queries();
		$this->assertNull( $this->server->target_for_current_request() );
		$this->assertSame( 0, get_num_queries() - $before, 'The cap must stop the lookup before any query.' );

		$raise  = static fn(): int => 25;
		add_filter( 'hierarchy_guard_max_segments', $raise );
		$before = get_num_queries();
		$this->assertNull( $this->server->target_for_current_request() );
		$this->assertGreaterThan( 0, get_num_queries() - $before, 'With the cap raised the resolver runs and finds nothing.' );
		remove_filter( 'hierarchy_guard_max_segments', $raise );
	}

	public function test_never_redirects_to_the_requested_url(): void {
		$x     = self::factory()->post->create(
			[
				'post_type' => 'page',
				'post_name' => 'x',
			] 
		);
		$y     = self::factory()->post->create(
			[
				'post_type' => 'page',
				'post_name' => 'y',
			] 
		);
		$about = self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_name'   => 'about',
				'post_parent' => $x,
			] 
		);

		wp_update_post(
			[
				'ID'          => $about,
				'post_parent' => $y,
			] 
		);
		wp_update_post(
			[
				'ID'        => $x,
				'post_name' => 'x-old',
			] 
		);
		wp_update_post(
			[
				'ID'        => $y,
				'post_name' => 'x',
			] 
		);
		$this->assertSame( home_url( '/x/about/' ), get_permalink( $about ), 'Precondition: about now lives at x/about.' );

		$this->go_to( '/x/about/3/' );
		$this->assertTrue( is_404() );
		$this->assertNull( $this->server->target_for_current_request() );

		$_SERVER['QUERY_STRING'] = 'utm_source=x';
		$this->assertNull( $this->server->target_for_current_request(), 'The carried-over query string must not defeat the guard.' );
	}

	/**
	 * The Server instance the plugin registered, found through the hook table.
	 */
	private function server_from_plugin(): Server {
		global $wp_filter;
		foreach ( $wp_filter['template_redirect']->callbacks[9] ?? [] as $callback ) {
			if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof Server ) {
				return $callback['function'][0];
			}
		}
		$this->fail( 'No Server registered at template_redirect priority 9.' );
	}
}
