<?php
/**
 * REST routes about the current user's last trash.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Ledger;
use HierarchyGuard\Post_Types;
use HierarchyGuard\Settings;
use HierarchyGuard\Trash\Change_Log;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * @covers \HierarchyGuard\REST\Changes_Controller
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

		$this->assertSame( "source,target,code\r\n/company/about/history/,/company/history/,301\r\n/company/about/team/,/company/team/,301\r\n/company/about/team/leadership/,/company/team/leadership/,301\r\n", $response->get_data() );
	}

	public function test_an_unknown_csv_layout_is_refused(): void {
		$this->assertSame( 400, $this->get( '/changes/' . $this->change_id . '/csv', [ 'format' => 'excel' ] )->get_status() );
	}
}
