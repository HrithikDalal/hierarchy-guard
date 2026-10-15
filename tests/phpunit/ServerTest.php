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
use HierarchyGuard\Trash\Markers;
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
		delete_option( Ledger::ROOT_TARGETS );
		update_option( Settings::OPTION, [ 'serve_redirects' => true ] );
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
		unset( $_SERVER['REQUEST_METHOD'], $_SERVER['QUERY_STRING'], $_SERVER['PATH_INFO'] );
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
		update_option( Settings::OPTION, [ 'serve_redirects' => false ] );
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
		$this->go_to( '/company/about/team/?utm_source=x' );

		$this->assertTrue( is_404() );
		$this->assertSame( get_permalink( $this->team ) . '?utm_source=x', $this->server->target_for_current_request()['url'] );
	}

	public function test_query_string_comes_from_the_request_uri(): void {
		wp_update_post(
			[
				'ID'        => $this->about,
				'post_name' => 'company-info',
			]
		);
		$this->go_to( '/company/about/team/?utm_source=x' );
		// A rewritten QUERY_STRING, as nginx's `/index.php?q=$uri&$args` produces.
		$_SERVER['QUERY_STRING'] = 'q=/company/about/team/&utm_source=x';

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

	public function test_preserves_comment_page_suffix(): void {
		wp_update_post(
			[
				'ID'        => $this->about,
				'post_name' => 'company-info',
			]
		);

		$this->go_to( '/company/about/team/comment-page-2/' );
		$this->assertTrue( is_404() );
		$this->assertSame( user_trailingslashit( trailingslashit( get_permalink( $this->team ) ) . 'comment-page-2', 'commentpaged' ), $this->server->target_for_current_request()['url'] );
	}

	public function test_preserves_trackback_suffix(): void {
		wp_update_post(
			[
				'ID'        => $this->about,
				'post_name' => 'company-info',
			]
		);

		$this->go_to( '/company/about/team/trackback/' );
		$this->assertTrue( is_404() );
		$this->assertSame( user_trailingslashit( trailingslashit( get_permalink( $this->team ) ) . 'trackback', 'single_trackback' ), $this->server->target_for_current_request()['url'] );
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
		$about = $this->page_whose_old_path_is_its_current_path();
		$this->assertSame( home_url( '/x/about/' ), get_permalink( $about ), 'Precondition: about now lives at x/about.' );

		$this->go_to( '/x/about/3/' );
		$this->assertTrue( is_404() );
		$this->assertNull( $this->server->target_for_current_request() );

		$this->go_to( '/x/about/3/?utm_source=x' );
		$this->assertTrue( is_404() );
		$this->assertNull( $this->server->target_for_current_request(), 'The carried-over query string must not defeat the guard.' );
	}

	public function test_never_redirects_to_the_requested_url_on_path_info_permalinks(): void {
		$this->set_permalink_structure( '/index.php/%postname%/' );
		$about = $this->page_whose_old_path_is_its_current_path();
		$this->assertSame( home_url( '/index.php/x/about/' ), get_permalink( $about ), 'Precondition: about now lives at index.php/x/about.' );

		$this->go_to( '/index.php/x/about/3/' );
		$this->assertTrue( is_404() );

		// A server that passes PATH_INFO: core then fills $wp->request without index.php.
		$_SERVER['REQUEST_URI'] = '/index.php/x/about/3/';
		$_SERVER['PATH_INFO']   = '/x/about/3/';
		$GLOBALS['wp']->parse_request();
		$this->assertSame( 'x/about/3', $GLOBALS['wp']->request, 'Precondition: the request path omits index.php.' );

		$this->assertNull( $this->server->target_for_current_request() );
	}

	/**
	 * Builds the case where a ledger entry equals a page's current path: about
	 * moves from x to y, x is renamed, then y takes x's old slug. A 404 under
	 * about (an excess page number) then resolves to about's own URL.
	 *
	 * @return int The about page ID.
	 */
	private function page_whose_old_path_is_its_current_path(): int {
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
		return $about;
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

	public function test_a_redirect_a_restore_can_undo_is_a_302(): void {
		global $wpdb;

		// What the trash flow leaves: about in the trash, team moved up with its marker, the alias, and about's own path.
		add_filter( 'hierarchy_guard_trash_policy', static fn(): string => Settings::POLICY_KEEP );
		wp_trash_post( $this->about );
		$wpdb->update( $wpdb->posts, [ 'post_parent' => $this->company ], [ 'ID' => $this->team ] );
		clean_post_cache( $this->team );
		Markers::add( $this->team, Markers::moved_key( $this->about ), Markers::encode_moved( $this->company, 'team', 'team', time() ) );
		$ledger = new Ledger();
		$ledger->record_alias( 'page', $this->company, 'company/about' );
		$ledger->record( $this->about, 'company/about' );

		$this->go_to( '/company/about/team/' );
		$target = $this->server->target_for_current_request();

		$this->assertSame( get_permalink( $this->team ), $target['url'] );
		$this->assertSame( 302, $target['status'], 'Browsers must not keep it: a restore moves team back.' );
	}

	public function test_a_url_target_is_sent_for_its_exact_old_url_only(): void {
		$ledger = new Ledger();
		$ledger->record( $this->company, 'old/sub' );
		$ledger->record( $this->about, 'old/sub/about' );
		$ledger->set_target( 'page', 'old/sub/about', $this->about, 'https://jobs.example.org/about/', 0 );

		$this->go_to( '/old/sub/about/?ref=mail' );
		$this->assertTrue( is_404() );
		$this->assertSame(
			[
				'url'    => 'https://jobs.example.org/about/?ref=mail',
				'status' => 301,
			],
			$this->server->target_for_current_request(),
			'Another host, through wp_redirect(): wp_validate_redirect() would refuse it.'
		);

		$this->go_to( '/old/sub/about/feed/' );
		$this->assertTrue( is_404() );
		$this->assertSame( 'https://jobs.example.org/about/', $this->server->target_for_current_request()['url'], 'Suffixes are re-applied to pages only.' );

		$this->go_to( '/old/sub/about/team/' );
		$this->assertSame( get_permalink( $this->team ), $this->server->target_for_current_request()['url'], 'A longer path goes past the URL target to the shorter prefix.' );
	}

	public function test_a_chosen_code_is_sent_as_chosen(): void {
		$ledger = new Ledger();
		$ledger->record( $this->about, 'old-about' );
		$ledger->set_target( 'page', 'old-about', $this->about, '', 302 );

		$this->go_to( '/old-about/team/' );
		$this->assertSame(
			[
				'url'    => get_permalink( $this->team ),
				'status' => 302,
			],
			$this->server->target_for_current_request()
		);

		$ledger->set_target( 'page', 'old-about', $this->about, '', 301 );
		$this->go_to( '/old-about/team/' );
		$this->assertSame( 301, $this->server->target_for_current_request()['status'] );
	}

	public function test_a_url_target_that_is_not_http_is_never_sent(): void {
		$ledger = new Ledger();
		$ledger->record( $this->about, 'old-about' );
		$ledger->set_target( 'page', 'old-about', $this->about, 'https://example.org/', 0 );
		$this->go_to( '/old-about/' );

		$scheme = static fn(): string => 'javascript:alert(1)';
		add_filter( 'hierarchy_guard_redirect_url', $scheme );
		$this->assertNull( $this->server->target_for_current_request() );
		remove_filter( 'hierarchy_guard_redirect_url', $scheme );

		$passed = [];
		$spy    = static function ( $value, $target ) use ( &$passed ) {
			$passed[ current_filter() ] = $target;
			return $value;
		};
		add_filter( 'hierarchy_guard_redirect_status', $spy, 10, 2 );
		add_filter( 'hierarchy_guard_redirect_url', $spy, 10, 2 );
		$this->assertSame( 'https://example.org/', $this->server->target_for_current_request()['url'] );
		remove_filter( 'hierarchy_guard_redirect_status', $spy );
		remove_filter( 'hierarchy_guard_redirect_url', $spy );
		$this->assertSame(
			[
				'hierarchy_guard_redirect_status' => null,
				'hierarchy_guard_redirect_url'    => null,
			],
			$passed,
			'Both filters get no post for a URL target.'
		);
	}

	public function test_a_url_target_filtered_to_a_url_with_no_scheme_is_never_sent(): void {
		$ledger = new Ledger();
		$ledger->record( $this->about, 'old-about' );
		$ledger->set_target( 'page', 'old-about', $this->about, 'https://example.org/', 0 );
		$this->go_to( '/old-about/' );

		foreach ( [ '//evil.example.org/x', '/relative/', 'evil.example.org/x', 'ftp://example.org/' ] as $url ) {
			$to = static fn(): string => $url;
			add_filter( 'hierarchy_guard_redirect_url', $to );
			$this->assertNull( $this->server->target_for_current_request(), $url . ' is not http(s).' );
			remove_filter( 'hierarchy_guard_redirect_url', $to );
		}
		$this->assertSame( 'https://example.org/', $this->server->target_for_current_request()['url'], 'Unfiltered, it is sent.' );
	}

	public function test_a_url_target_on_this_site_at_the_requested_path_is_not_sent(): void {
		$ledger = new Ledger();
		$ledger->record( $this->about, 'old-about' );
		$ledger->set_target( 'page', 'old-about', $this->about, home_url( '/old-about/' ), 0 );
		$this->go_to( '/old-about/' );

		$this->assertNull( $this->server->target_for_current_request(), 'That would loop.' );

		$ledger->set_target( 'page', 'old-about', $this->about, 'https://other.example.org/old-about/', 0 );
		$this->assertSame( 'https://other.example.org/old-about/', $this->server->target_for_current_request()['url'], 'The same path on another host does not.' );
	}

	public function test_a_live_page_at_the_old_url_wins_over_a_url_target(): void {
		$ledger = new Ledger();
		$ledger->record( $this->about, 'news' );
		$ledger->set_target( 'page', 'news', $this->about, 'https://example.org/', 302 );
		self::factory()->post->create(
			[
				'post_type' => 'page',
				'post_name' => 'news',
			]
		);

		$this->go_to( '/news/' );
		$this->assertFalse( is_404() );
		$this->assertNull( $this->server->target_for_current_request() );
	}

	public function test_the_query_string_goes_before_a_url_target_s_fragment(): void {
		$ledger = new Ledger();
		$ledger->record( $this->about, 'old-about' );
		$ledger->set_target( 'page', 'old-about', $this->about, 'https://example.org/landing/#section', 0 );
		$this->go_to( '/old-about/?utm_source=news' );
		$this->assertSame( 'https://example.org/landing/?utm_source=news#section', $this->server->target_for_current_request()['url'] );

		$ledger->set_target( 'page', 'old-about', $this->about, 'https://example.org/landing/?ref=hg#section', 0 );
		$this->assertSame( 'https://example.org/landing/?ref=hg&utm_source=news#section', $this->server->target_for_current_request()['url'] );
	}
}
