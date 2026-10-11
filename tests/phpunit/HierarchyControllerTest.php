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
		$this->assertSame( '(private page)', $data['trash_to']['title'] );
	}

	public function test_old_paths_without_a_url_are_left_out(): void {
		$this->rename( $this->ids['about'], 'about-us' );
		$this->set_permalink_structure( '' );

		$this->assertSame( [], $this->get( '/posts/' . $this->ids['about'] . '/hierarchy' )->get_data()['old_paths'] );
	}
}
