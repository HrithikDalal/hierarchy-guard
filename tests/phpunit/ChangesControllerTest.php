<?php
/**
 * REST routes about the current user's last change.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Ledger;
use HierarchyGuard\Post_Types;
use HierarchyGuard\REST\Csv_Download;
use HierarchyGuard\Settings;
use HierarchyGuard\Trash\Change_Log;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * @covers \HierarchyGuard\REST\Changes_Controller
 * @covers \HierarchyGuard\REST\Csv_Download
 */
final class ChangesControllerTest extends WP_UnitTestCase {

	use Trash_Fixtures;

	/**
	 * Page IDs.
	 *
	 * @var array<string,int>
	 */
	private array $ids;

	private int $admin;

	private string $change_id;

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
		wp_trash_post( $this->ids['about'] );
		$this->change_id = (string) ( new Change_Log() )->latest( $this->admin )['id'];
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

	public function test_the_pairs_of_the_user_s_last_trash(): void {
		$data = $this->get( '/changes/' . $this->change_id . '/pairs' )->get_data();

		$this->assertSame( 3, $data['total'] );
		$this->assertSame( [ '/company/about/history/', '/company/about/team/', '/company/about/team/leadership/' ], array_column( $data['pairs'], 'old' ) );
		$this->assertSame( [ '/company/about/' ], array_column( $data['unresolved'], 'old' ) );
	}

	public function test_another_user_cannot_read_the_change(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$response = $this->get( '/changes/' . $this->change_id . '/pairs' );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'hierarchy_guard_change_gone', $response->get_data()['code'] );
	}

	public function test_an_older_change_is_gone(): void {
		$this->assertSame( 404, $this->get( '/changes/' . wp_generate_uuid4() . '/pairs' )->get_status() );
	}

	public function test_a_visitor_is_refused(): void {
		wp_set_current_user( 0 );

		$this->assertSame( 401, $this->get( '/changes/' . $this->change_id . '/pairs' )->get_status() );
	}

	public function test_the_csv_of_the_change(): void {
		$response = $this->get( '/changes/' . $this->change_id . '/csv', [ 'format' => 'generic' ] );

		$this->assertSame( "source,target,code\r\n/company/about/history/,/company/history/,302\r\n/company/about/team/,/company/team/,302\r\n/company/about/team/leadership/,/company/team/leadership/,302\r\n", $response->get_data(), 'Restoring about would move its subpages back, so the server answers 302.' );
	}

	public function test_the_pairs_and_the_csv_of_a_save(): void {
		Change_Log::reset_request();
		add_filter( 'wp_untrash_post_status', 'wp_untrash_post_set_previous_status', 10, 3 );
		wp_untrash_post( $this->ids['about'] );
		Change_Log::reset_request();
		$this->assertSame( 200, $this->rest_save( $this->ids['company'], 'firm' ) );
		$change = ( new Change_Log() )->latest( $this->admin );
		$this->assertSame( 'save', $change['action'] );
		Change_Log::reset_request();

		$data = $this->get( '/changes/' . $change['id'] . '/pairs', [ 'per_page' => 2 ] )->get_data();
		$this->assertSame( 5, $data['total'] );
		$this->assertSame( 3, $data['pages'] );
		$this->assertSame(
			[
				[
					'old'       => '/company/',
					'new'       => '/firm/',
					'temporary' => false,
				],
				[
					'old'       => '/company/about/',
					'new'       => '/firm/about/',
					'temporary' => false,
				],
			],
			$data['pairs']
		);
		$this->assertSame(
			"source,target,regex,code\r\n/company/,/firm/,0,301\r\n/company/about/,/firm/about/,0,301\r\n/company/about/history/,/firm/about/history/,0,301\r\n/company/about/team/,/firm/about/team/,0,301\r\n/company/about/team/leadership/,/firm/about/team/leadership/,0,301\r\n",
			$this->get( '/changes/' . $change['id'] . '/csv', [ 'format' => 'redirection' ] )->get_data()
		);
		$this->assertSame( 404, $this->get( '/changes/' . $this->change_id . '/csv' )->get_status(), 'The save replaced the trash.' );
	}

	public function test_the_csv_route_answers_with_a_flagged_csv_body(): void {
		$request = new WP_REST_Request( 'GET', '/hierarchy-guard/v1/changes/' . $this->change_id . '/csv' );
		$request->set_query_params( [ 'format' => 'redirection' ] );

		$response = rest_do_request( $request );
		$headers  = $response->get_headers();

		$this->assertSame( 'text/csv; charset=utf-8', $headers['Content-Type'] );
		$this->assertStringContainsString( 'attachment; filename="hierarchy-guard-redirects-redirection-', $headers['Content-Disposition'] );
		$this->assertStringStartsWith( "source,target,regex,code\r\n/company/about/history/,/company/history/,0,302\r\n", $response->get_data() );

		ob_start();
		$served = ( new Csv_Download() )->serve( false, $response, $request );
		$body   = (string) ob_get_clean();

		$this->assertTrue( $served );
		$this->assertSame( $response->get_data(), $body );
	}

	public function test_a_fields_parameter_on_the_csv_route_still_gives_the_csv(): void {
		$request = new WP_REST_Request( 'GET', '/hierarchy-guard/v1/changes/' . $this->change_id . '/csv' );
		$request->set_query_params(
			[
				'format'  => 'redirection',
				'_fields' => 'source',
			]
		);

		// What the REST server runs on the response before serving it; core's
		// filter is attached on rest_api_init, which earlier tests may have run.
		add_filter( 'rest_post_dispatch', 'rest_filter_response_fields', 10, 3 );
		$response = apply_filters( 'rest_post_dispatch', rest_ensure_response( rest_do_request( $request ) ), rest_get_server(), $request ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's hook, run the way the REST server runs it.

		$this->assertSame( 200, $response->get_status() );
		$this->assertStringStartsWith( "source,target,regex,code\r\n/company/about/history/,/company/history/,0,302\r\n", $response->get_data() );
	}

	public function test_an_unknown_csv_layout_is_refused(): void {
		$this->assertSame( 400, $this->get( '/changes/' . $this->change_id . '/csv', [ 'format' => 'excel' ] )->get_status() );
	}

	public function test_the_gone_message_says_a_later_change_replaced_them_and_how_long_they_are_kept(): void {
		$response = $this->get( '/changes/' . wp_generate_uuid4() . '/pairs' );

		$this->assertSame( 'These redirects are no longer available. A later change replaced them. They are kept for 30 minutes.', $response->get_data()['message'] );
	}
}
