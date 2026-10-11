<?php
/**
 * REST routes about one post's hierarchy.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Ledger;
use HierarchyGuard\Overview;
use HierarchyGuard\Paths;
use HierarchyGuard\Post_Types;
use HierarchyGuard\REST\Csv_Download;
use HierarchyGuard\Settings;
use HierarchyGuard\Trash\Change_Log;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * @covers \HierarchyGuard\REST\Hierarchy_Controller
 * @covers \HierarchyGuard\REST\Csv_Download
 * @covers \HierarchyGuard\Overview
 */
final class HierarchyControllerTest extends WP_UnitTestCase {

	use Trash_Fixtures;

	/**
	 * Page IDs.
	 *
	 * @var array<string,int>
	 */
	private array $ids;

	private int $admin;

	public function set_up(): void {
		parent::set_up();
		delete_option( Settings::OPTION );
		delete_option( Ledger::ROOT_OPTION );
		Post_Types::reset();
		Change_Log::reset_request();
		$this->set_permalink_structure( '/%postname%/' );
		$this->admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $this->admin );
		$this->ids = $this->make_tree();
	}

	public function tear_down(): void {
		$this->set_permalink_structure( '' );
		parent::tear_down();
	}

	/**
	 * Runs a GET request.
	 *
	 * @param string              $route  Route under the plugin's namespace.
	 * @param array<string,mixed> $params Query parameters.
	 * @return WP_REST_Response
	 */
	private function get( string $route, array $params = [] ): WP_REST_Response {
		$request = new WP_REST_Request( 'GET', '/hierarchy-guard/v1' . $route );
		$request->set_query_params( $params );
		return rest_do_request( $request );
	}

	/**
	 * Renames a page, which records its old path.
	 *
	 * @param int    $post_id Page.
	 * @param string $slug    New slug.
	 */
	private function rename( int $post_id, string $slug ): void {
		wp_update_post(
			[
				'ID'        => $post_id,
				'post_name' => $slug,
			]
		);
	}

	public function test_the_hierarchy_route_needs_edit_rights_on_a_supported_post(): void {
		$route = '/posts/' . $this->ids['about'] . '/hierarchy';

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$this->assertSame( 403, $this->get( $route )->get_status() );

		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->get( $route )->get_status() );

		wp_set_current_user( $this->admin );
		$this->assertSame( 404, $this->get( '/posts/999999/hierarchy' )->get_status() );
		$this->assertSame( 404, $this->get( '/posts/' . self::factory()->post->create() . '/hierarchy' )->get_status(), 'Posts are not hierarchical.' );
		$this->assertSame( 200, $this->get( $route )->get_status() );
	}

	public function test_the_hierarchy_of_a_page(): void {
		$data = $this->get( '/posts/' . $this->ids['about'] . '/hierarchy' )->get_data();

		$this->assertSame( [ 'Company' ], array_column( $data['ancestors'], 'title' ) );
		$this->assertSame( [ 'History', 'Team' ], array_column( $data['children'], 'title' ) );
		$this->assertSame( [ 0, 1 ], array_column( $data['children'], 'children_count' ) );
		$this->assertSame( home_url( '/company/about/team/' ), $data['children'][1]['view_link'] );
		$this->assertSame( 2, $data['children_total'] );
		$this->assertFalse( $data['has_more'] );
		$this->assertSame(
			[
				'count'  => 3,
				'capped' => false,
			],
			$data['descendants']
		);
		$this->assertSame( [], $data['old_paths'] );
		$this->assertSame(
			[
				'serving'  => false,
				'provider' => null,
			],
			$data['redirects']
		);
		$this->assertSame( Settings::POLICY_REPARENT, $data['trash_policy'] );
		$this->assertSame( 'Company', $data['trash_to']['title'] );
		$this->assertStringContainsString( 'hierarchy_guard_under=' . $this->ids['about'], $data['list_url'] );
	}

	public function test_children_are_paged(): void {
		for ( $i = 1; $i <= 11; $i++ ) {
			$this->page( 'extra-' . $i, $this->ids['about'] );
		}

		$first  = $this->get( '/posts/' . $this->ids['about'] . '/hierarchy' )->get_data();
		$second = $this->get( '/posts/' . $this->ids['about'] . '/hierarchy', [ 'children_page' => 2 ] )->get_data();

		$this->assertCount( Overview::CHILDREN_PER_PAGE, $first['children'] );
		$this->assertTrue( $first['has_more'] );
		$this->assertCount( 3, $second['children'] );
		$this->assertFalse( $second['has_more'] );
		$this->assertSame( 13, $second['children_total'] );
	}

	public function test_a_child_the_user_may_not_read_is_left_out(): void {
		$hidden = $this->ids['history'];
		add_filter(
			'map_meta_cap',
			static function ( array $caps, string $cap, int $user_id, array $args ) use ( $hidden ): array {
				return 'read_post' === $cap && isset( $args[0] ) && $hidden === (int) $args[0] ? [ 'do_not_allow' ] : $caps;
			},
			10,
			4
		);

		$data = $this->get( '/posts/' . $this->ids['about'] . '/hierarchy' )->get_data();

		$this->assertSame( [ 'Team' ], array_column( $data['children'], 'title' ) );
	}

	public function test_old_paths_are_listed_newest_first(): void {
		$this->rename( $this->ids['about'], 'about-us' );
		$this->rename( $this->ids['about'], 'who-we-are' );

		$data = $this->get( '/posts/' . $this->ids['about'] . '/hierarchy' )->get_data();

		$this->assertSame( [ 'company/about-us', 'company/about' ], array_column( $data['old_paths'], 'path' ) );
		$this->assertSame( '/company/about-us/', $data['old_paths'][0]['url'] );
	}

	public function test_an_old_path_has_a_state(): void {
		$this->rename( $this->ids['about'], 'about-us' );
		$route = '/posts/' . $this->ids['about'] . '/hierarchy';
		$this->assertSame( 'ok', $this->get( $route )->get_data()['old_paths'][0]['state'] );

		wp_update_post(
			[
				'ID'          => $this->ids['about'],
				'post_status' => 'private',
			]
		);
		$this->assertSame( 'none', $this->get( $route )->get_data()['old_paths'][0]['state'], 'A page nobody can view receives no redirect.' );
		wp_update_post(
			[
				'ID'          => $this->ids['about'],
				'post_status' => 'publish',
			]
		);

		$this->page( 'about', $this->ids['company'] );
		$this->assertSame( 'in_use', $this->get( $route )->get_data()['old_paths'][0]['state'] );
	}

	public function test_an_old_path_under_a_trashed_ancestor_has_no_redirect(): void {
		$this->rename( $this->ids['team'], 'crew' );
		$this->trash_raw( $this->ids['about'] );

		$data = $this->get( '/posts/' . $this->ids['team'] . '/hierarchy' )->get_data();

		$this->assertSame( 'none', $data['old_paths'][0]['state'] );
	}

	public function test_an_old_path_held_by_a_page_in_the_trash_is_in_the_trash(): void {
		wp_trash_post( $this->ids['about'] );

		$data = $this->get( '/posts/' . $this->ids['company'] . '/hierarchy' )->get_data();

		$this->assertSame( [ 'company/about' ], array_column( $data['old_paths'], 'path' ) );
		$this->assertSame( 'trash', $data['old_paths'][0]['state'] );
	}

	public function test_a_page_under_a_trashed_ancestor_is_not_served_redirects(): void {
		update_option( Settings::OPTION, [ 'serve_redirects' => true ] );
		$route = '/posts/' . $this->ids['team'] . '/hierarchy';
		$this->assertTrue( $this->get( $route )->get_data()['redirects']['serving'] );

		$this->trash_raw( $this->ids['about'] );

		$this->assertFalse( $this->get( $route )->get_data()['redirects']['serving'] );
	}

	public function test_a_page_nobody_can_view_is_not_served_redirects(): void {
		update_option( Settings::OPTION, [ 'serve_redirects' => true ] );
		$route = '/posts/' . $this->ids['about'] . '/hierarchy';
		$this->assertTrue( $this->get( $route )->get_data()['redirects']['serving'] );

		wp_update_post(
			[
				'ID'          => $this->ids['about'],
				'post_status' => 'private',
			]
		);

		$this->assertFalse( $this->get( $route )->get_data()['redirects']['serving'] );
	}

	public function test_the_trash_policy_shows_the_fallback_for_a_large_subtree(): void {
		add_filter( 'hierarchy_guard_trash_policy', static fn(): string => Settings::POLICY_TRASH_CHILDREN );
		add_filter( 'hierarchy_guard_count_cap', static fn(): int => 2 );

		$data = $this->get( '/posts/' . $this->ids['about'] . '/hierarchy' )->get_data();

		$this->assertSame( Settings::POLICY_REPARENT, $data['trash_policy'] );
		$this->assertTrue( $data['trash_fallback'] );
		$this->assertTrue( $data['descendants']['capped'] );
	}

	public function test_pairs_for_an_old_path_recorded_on_the_page(): void {
		$this->rename( $this->ids['about'], 'about-us' );

		$data = $this->get(
			'/posts/' . $this->ids['about'] . '/pairs',
			[ 'from' => 'company/about' ]
		)->get_data();

		$this->assertSame( 4, $data['total'] );
		$this->assertSame(
			[
				'old' => '/company/about/',
				'new' => '/company/about-us/',
			],
			$data['pairs'][0]
		);
	}

	public function test_pairs_for_a_path_not_recorded_on_the_page_are_refused(): void {
		$response = $this->get(
			'/posts/' . $this->ids['about'] . '/pairs',
			[ 'from' => 'somewhere/else' ]
		);

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'hierarchy_guard_unknown_path', $response->get_data()['code'] );
	}

	public function test_the_csv_route_answers_with_a_flagged_csv_body(): void {
		$this->rename( $this->ids['about'], 'about-us' );
		$request = new WP_REST_Request( 'GET', '/hierarchy-guard/v1/posts/' . $this->ids['about'] . '/csv' );
		$request->set_query_params(
			[
				'from'   => 'company/about',
				'format' => 'redirection',
			]
		);

		$response = rest_do_request( $request );
		$headers  = $response->get_headers();

		$this->assertSame( 'text/csv; charset=utf-8', $headers['Content-Type'] );
		$this->assertStringContainsString( 'attachment; filename="hierarchy-guard-redirects-redirection-', $headers['Content-Disposition'] );
		$this->assertStringStartsWith( "source,target,regex,code\r\n/company/about/,/company/about-us/,0,301\r\n", $response->get_data() );

		ob_start();
		$served = ( new Csv_Download() )->serve( false, $response, $request );
		$body   = (string) ob_get_clean();

		$this->assertTrue( $served );
		$this->assertSame( $response->get_data(), $body );
	}

	public function test_a_json_response_is_left_to_the_server(): void {
		$request  = new WP_REST_Request( 'GET', '/hierarchy-guard/v1/posts/' . $this->ids['about'] . '/hierarchy' );
		$response = rest_do_request( $request );

		ob_start();
		$served = ( new Csv_Download() )->serve( false, $response, $request );
		ob_end_clean();

		$this->assertFalse( $served );
	}

	public function test_an_ancestor_the_user_may_not_read_keeps_its_title(): void {
		$hidden = $this->ids['company'];
		add_filter(
			'map_meta_cap',
			static function ( array $caps, string $cap, int $user_id, array $args ) use ( $hidden ): array {
				return 'read_post' === $cap && isset( $args[0] ) && $hidden === (int) $args[0] ? [ 'do_not_allow' ] : $caps;
			},
			10,
			4
		);

		$data = $this->get( '/posts/' . $this->ids['about'] . '/hierarchy' )->get_data();

		$this->assertSame( [ '(private page)' ], array_column( $data['ancestors'], 'title' ) );
		$this->assertArrayNotHasKey( 'status', $data['ancestors'][0], 'Nothing reads it, and the user may not read the ancestor.' );
		$this->assertSame( '(private page)', $data['trash_to']['title'] );
	}

	public function test_an_ancestor_in_the_trash_has_no_edit_link(): void {
		$this->trash_raw( $this->ids['team'] );

		$data = $this->get( '/posts/' . $this->ids['leadership'] . '/hierarchy' )->get_data();

		$this->assertSame( [ 'Company', 'About', 'Team' ], array_column( $data['ancestors'], 'title' ) );
		$this->assertNull( $data['ancestors'][2]['edit_link'], 'Core refuses to edit a page in the Trash.' );
		$this->assertIsString( $data['ancestors'][1]['edit_link'] );
	}

	public function test_a_page_moved_back_to_an_old_path_does_not_list_it_as_an_old_url(): void {
		$services = $this->page( 'services', $this->ids['company'] );
		wp_update_post(
			[
				'ID'          => $services,
				'post_parent' => $this->ids['about'],
			]
		);
		// The trash moves it back to company/services.
		wp_trash_post( $this->ids['about'] );

		$data = $this->get( '/posts/' . $services . '/hierarchy' )->get_data();

		$this->assertSame( 'company/services', Paths::uri( get_post( $services ) ) );
		$this->assertSame( [], array_column( $data['old_paths'], 'path' ), 'That is its URL again.' );
	}

	public function test_a_fields_parameter_on_the_csv_route_still_gives_the_csv(): void {
		$this->rename( $this->ids['about'], 'about-us' );
		$request = new WP_REST_Request( 'GET', '/hierarchy-guard/v1/posts/' . $this->ids['about'] . '/csv' );
		$request->set_query_params(
			[
				'from'    => 'company/about',
				'format'  => 'redirection',
				'_fields' => 'source',
			]
		);

		// What the REST server runs on the response before serving it; core's
		// filter is attached on rest_api_init, which earlier tests may have run.
		add_filter( 'rest_post_dispatch', 'rest_filter_response_fields', 10, 3 );
		$response = apply_filters( 'rest_post_dispatch', rest_ensure_response( rest_do_request( $request ) ), rest_get_server(), $request ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's hook, run the way the REST server runs it.

		$this->assertSame( 200, $response->get_status() );
		$this->assertStringStartsWith( "source,target,regex,code\r\n/company/about/,/company/about-us/,0,301\r\n", $response->get_data() );
	}

	public function test_old_paths_without_a_url_are_left_out(): void {
		$this->rename( $this->ids['about'], 'about-us' );
		$this->set_permalink_structure( '' );

		$this->assertSame( [], $this->get( '/posts/' . $this->ids['about'] . '/hierarchy' )->get_data()['old_paths'] );
	}

	public function test_titles_carry_no_private_or_protected_prefix(): void {
		self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_parent' => $this->ids['about'],
				'post_status' => 'private',
				'post_title'  => 'Secret Page',
			]
		);
		self::factory()->post->create(
			[
				'post_type'     => 'page',
				'post_parent'   => $this->ids['about'],
				'post_status'   => 'publish',
				'post_title'    => 'Guarded Page',
				'post_password' => 'secret',
			]
		);

		$data   = $this->get( '/posts/' . $this->ids['about'] . '/hierarchy' )->get_data();
		$titles = array_column( $data['children'], 'title' );
		sort( $titles );

		$this->assertSame( [ 'Guarded Page', 'History', 'Secret Page', 'Team' ], $titles );
		$this->assertFalse( has_filter( 'private_title_format' ) );
		$this->assertFalse( has_filter( 'protected_title_format' ) );
	}

	public function test_the_trash_preview_describes_each_page_with_subpages(): void {
		$data = $this->get(
			'/trash-preview',
			[ 'ids' => [ $this->ids['about'], $this->ids['history'], $this->ids['team'] ] ]
		)->get_data();

		$this->assertSame( [ $this->ids['about'], $this->ids['team'] ], array_column( $data['pages'], 'id' ), 'History has no subpages.' );
		$about = $data['pages'][0];
		$this->assertSame( 'About', $about['title'] );
		$this->assertSame( '/company/about/', $about['path'] );
		$this->assertSame( 2, $about['children'] );
		$this->assertSame( 3, $about['descendants']['count'] );
		$this->assertSame( 'Company', $about['to']['title'] );
		$this->assertSame(
			[
				'from' => '/company/about/team/',
				'to'   => '/company/team/',
			],
			$about['example']
		);
		$this->assertFalse( $about['denied'] );
		$this->assertFalse( $about['fallback'] );
		$this->assertSame(
			[
				'count'  => 4,
				'capped' => false,
			],
			$data['total']
		);
		$this->assertSame( Settings::POLICY_REPARENT, $data['policy'] );
	}

	public function test_the_trash_preview_says_when_some_subpages_cannot_be_moved(): void {
		$locked = $this->ids['team'];
		add_filter(
			'map_meta_cap',
			static function ( array $caps, string $cap, int $user_id, array $args ) use ( $locked ): array {
				return 'edit_post' === $cap && isset( $args[0] ) && $locked === (int) $args[0] ? [ 'do_not_allow' ] : $caps;
			},
			10,
			4
		);

		$data = $this->get( '/trash-preview', [ 'ids' => [ $this->ids['about'] ] ] )->get_data();

		$this->assertTrue( $data['pages'][0]['denied'] );
	}

	public function test_the_trash_preview_shares_one_capped_budget(): void {
		add_filter( 'hierarchy_guard_count_cap', static fn(): int => 2 );

		$data = $this->get( '/trash-preview', [ 'ids' => [ $this->ids['about'], $this->ids['team'] ] ] )->get_data();

		$this->assertTrue( $data['pages'][0]['fallback'], 'About has more subpages than the cap.' );
		$this->assertSame(
			[
				'count'  => 2,
				'capped' => true,
			],
			$data['total']
		);
	}

	public function test_the_trash_preview_skips_pages_the_user_cannot_trash(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$data = $this->get( '/trash-preview', [ 'ids' => [ $this->ids['about'] ] ] )->get_data();

		$this->assertSame( [], $data['pages'] );
	}

	public function test_the_trash_preview_carries_each_page_s_own_policy(): void {
		$about = $this->ids['about'];
		add_filter( 'hierarchy_guard_trash_policy', static fn( string $policy, \WP_Post $post ): string => $about === (int) $post->ID ? Settings::POLICY_KEEP : $policy, 10, 2 );

		$data = $this->get( '/trash-preview', [ 'ids' => [ $about, $this->ids['team'] ] ] )->get_data();

		$this->assertSame( [ Settings::POLICY_KEEP, Settings::POLICY_REPARENT ], array_column( $data['pages'], 'policy' ) );
		$this->assertSame( Settings::POLICY_REPARENT, $data['policy'], 'The site default, for a bulk dialog.' );
	}

	public function test_the_trash_preview_checks_both_rights(): void {
		$locked = $this->ids['team'];
		add_filter(
			'map_meta_cap',
			static function ( array $caps, string $cap, int $user_id, array $args ) use ( $locked ): array {
				return 'delete_post' === $cap && isset( $args[0] ) && $locked === (int) $args[0] ? [ 'do_not_allow' ] : $caps;
			},
			10,
			4
		);

		$page = $this->get( '/trash-preview', [ 'ids' => [ $this->ids['about'] ] ] )->get_data()['pages'][0];

		$this->assertFalse( $page['denied'], 'Team can be moved.' );
		$this->assertTrue( $page['denied_trash'], 'Team can’t be trashed.' );
	}

	public function test_the_trash_preview_reports_pages_past_its_limit(): void {
		$ids = array_merge( [ $this->ids['about'] ], range( 900000, 900050 ) );

		$data = $this->get( '/trash-preview', [ 'ids' => $ids ] )->get_data();

		$this->assertSame( 2, $data['more'] );
		$this->assertTrue( $data['total']['capped'] );
	}

	public function test_the_trash_preview_takes_at_most_a_thousand_ids(): void {
		$this->assertSame( 200, $this->get( '/trash-preview', [ 'ids' => range( 1, 1000 ) ] )->get_status() );
		$this->assertSame( 400, $this->get( '/trash-preview', [ 'ids' => range( 1, 1001 ) ] )->get_status() );
	}

	public function test_a_page_counted_past_the_shared_budget_has_an_unknown_fallback(): void {
		add_filter( 'hierarchy_guard_count_cap', static fn(): int => 3 );

		$data = $this->get( '/trash-preview', [ 'ids' => [ $this->ids['team'], $this->ids['about'] ] ] )->get_data();

		$this->assertFalse( $data['pages'][0]['fallback'], 'Team was counted in full.' );
		$this->assertNull( $data['pages'][1]['fallback'], 'About was counted against what was left.' );
	}

	public function test_the_capability_checks_share_one_batch(): void {
		add_filter( 'hierarchy_guard_batch_threshold', static fn(): int => 2 );
		$locked = $this->ids['leadership'];
		add_filter(
			'map_meta_cap',
			static function ( array $caps, string $cap, int $user_id, array $args ) use ( $locked ): array {
				return 'edit_post' === $cap && isset( $args[0] ) && $locked === (int) $args[0] ? [ 'do_not_allow' ] : $caps;
			},
			10,
			4
		);

		$data = $this->get( '/trash-preview', [ 'ids' => [ $this->ids['about'], $this->ids['team'] ] ] )->get_data();

		$this->assertFalse( $data['pages'][1]['denied'], 'About used the whole batch, so Team’s subpages were not checked.' );
	}

	public function test_the_trash_preview_example_skips_a_draft_subpage_without_a_slug(): void {
		$parent = $this->page( 'parent' );
		self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_name'   => '',
				'post_title'  => 'Draft',
				'post_parent' => $parent,
				'post_status' => 'draft',
			]
		);
		$this->page( 'live', $parent );

		$example = $this->get( '/trash-preview', [ 'ids' => [ $parent ] ] )->get_data()['pages'][0]['example'];

		$this->assertSame( '/parent/live/', $example['from'] );
		$this->assertNotSame( '/parent/', $example['from'], 'The draft is not the example.' );
	}

	public function test_the_trash_preview_example_is_null_when_only_drafts_lack_a_slug(): void {
		$parent = $this->page( 'parent' );
		self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_name'   => '',
				'post_title'  => 'Draft',
				'post_parent' => $parent,
				'post_status' => 'draft',
			]
		);

		$page = $this->get( '/trash-preview', [ 'ids' => [ $parent ] ] )->get_data()['pages'][0];

		$this->assertNull( $page['example'] );
	}
}
