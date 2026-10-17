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
		$this->assertArrayNotHasKey( 'old_paths', $data, 'The panel shows where the page is now, not its past URLs.' );
		$this->assertNull( $data['latest_change'] );
		$this->assertSame(
			[
				'serving'      => false,
				'pairs_served' => false,
				'provider'     => null,
			],
			$data['redirects']
		);
		$this->assertSame( Settings::POLICY_REPARENT, $data['trash_policy'] );
		$this->assertSame( 'Company', $data['trash_to']['title'] );
		$this->assertStringContainsString( 'hierarchy_guard_under=' . $this->ids['about'], $data['list_url'] );
	}

	public function test_the_latest_change_is_the_user_s_save_of_this_page(): void {
		$this->rename( $this->ids['about'], 'about-us' );
		$change = ( new Change_Log() )->latest( $this->admin );

		$this->assertSame( $change['id'], $this->get( '/posts/' . $this->ids['about'] . '/hierarchy' )->get_data()['latest_change'] );
		$this->assertNull( $this->get( '/posts/' . $this->ids['team'] . '/hierarchy' )->get_data()['latest_change'], 'The save moved team too, but its root is about.' );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertNull( $this->get( '/posts/' . $this->ids['about'] . '/hierarchy' )->get_data()['latest_change'], 'Another user’s change is not this user’s.' );
		wp_set_current_user( $this->admin );

		Change_Log::reset_request();
		wp_trash_post( $this->ids['team'] );
		$this->assertNull( $this->get( '/posts/' . $this->ids['about'] . '/hierarchy' )->get_data()['latest_change'], 'A trash replaced the save.' );
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

	public function test_the_tree_takes_children_50_at_a_time_and_paging_stops_at_the_cap(): void {
		for ( $i = 1; $i <= 58; $i++ ) {
			$this->page( sprintf( 'extra-%02d', $i ), $this->ids['about'] );
		}
		$route = '/posts/' . $this->ids['about'] . '/hierarchy';

		$first  = $this->get( $route, [ 'children_per_page' => 50 ] )->get_data();
		$second = $this->get(
			$route,
			[
				'children_page'     => 2,
				'children_per_page' => 50,
			]
		)->get_data();

		$this->assertCount( 50, $first['children'] );
		$this->assertTrue( $first['has_more'] );
		$this->assertCount( 10, $second['children'] );
		$this->assertFalse( $second['has_more'] );
		$this->assertCount( Overview::CHILDREN_PER_PAGE, $this->get( $route )->get_data()['children'], 'Without it, children still come 10 to a page.' );
		$this->assertSame( 400, $this->get( $route, [ 'children_per_page' => 51 ] )->get_status(), 'At most 50.' );

		add_filter( 'hierarchy_guard_count_cap', static fn(): int => 55 );
		$capped = $this->get(
			$route,
			[
				'children_page'     => 2,
				'children_per_page' => 50,
			]
		)->get_data();
		$this->assertCount( 5, $capped['children'], 'The 51st to the 55th, then the cap.' );
		$this->assertFalse( $capped['has_more'] );
		$this->assertTrue( $capped['children_capped'] );
		$this->assertSame(
			[],
			$this->get(
				$route,
				[
					'children_page'     => 3,
					'children_per_page' => 50,
				]
			)->get_data()['children']
		);
	}

	public function test_the_tree_s_context_sends_only_the_subpages_for_fewer_queries(): void {
		global $wpdb;
		$route  = '/posts/' . $this->ids['about'] . '/hierarchy';
		$params = [ 'children_per_page' => 50 ];
		// The first request after a flush also reads options that core loads once a request.
		wp_cache_flush();
		$this->get( $route, $params );

		wp_cache_flush();
		$queries = $wpdb->num_queries;
		$full    = $this->get( $route, $params )->get_data();
		$panel   = $wpdb->num_queries - $queries;

		wp_cache_flush();
		$queries = $wpdb->num_queries;
		$tree    = $this->get( $route, $params + [ 'context' => 'tree' ] )->get_data();
		$cost    = $wpdb->num_queries - $queries;

		$this->assertSame( [ 'id', 'children', 'children_total', 'children_capped', 'has_more' ], array_keys( $tree ), 'What the tree reads: no ancestors, descendants, redirects or trash policy.' );
		foreach ( array_keys( $tree ) as $key ) {
			$this->assertSame( $full[ $key ], $tree[ $key ], "The panel's {$key} too." );
		}
		$this->assertLessThan( $panel, $cost, 'Without the descendants walk, the nearest ancestor and the change log.' );
		$this->assertSame( 400, $this->get( $route, [ 'context' => 'panel' ] )->get_status(), 'Only the tree has a context of its own.' );
	}

	public function test_each_child_carries_what_the_list_s_columns_show(): void {
		$author = self::factory()->user->create(
			[
				'role'         => 'editor',
				'display_name' => 'Priya',
			]
		);
		wp_update_post(
			[
				'ID'          => $this->ids['history'],
				'post_author' => $author,
				'post_date'   => '2026-09-02 15:04:00',
			]
		);
		$draft = $this->page( 'press', $this->ids['about'], 'draft' );

		$children = array_column( $this->get( '/posts/' . $this->ids['about'] . '/hierarchy', [ 'children_per_page' => 50 ] )->get_data()['children'], null, 'id' );
		$history  = $children[ $this->ids['history'] ];

		$this->assertSame( 'Priya', $history['author'] );
		$this->assertSame(
			[
				'label'  => 'Published',
				'date'   => '2026/09/02 at 3:04 pm',
				'missed' => false,
			],
			$history['date'],
			'As core’s Date column words it.'
		);
		$this->assertSame( 'Last Modified', $children[ $draft ]['date']['label'] );
		parse_str( (string) wp_parse_url( $history['trash_link'], PHP_URL_QUERY ), $args );
		$this->assertSame( 'trash', $args['action'] );
		$this->assertSame( (string) $this->ids['history'], $args['post'] );
		$this->assertSame( 1, wp_verify_nonce( $args['_wpnonce'], 'trash-post_' . $this->ids['history'] ), 'A nonce the server made for this user.' );
		$this->assertStringNotContainsString( '&amp;', $history['trash_link'], 'Sent raw, like every other link.' );
	}

	public function test_a_scheduled_child_is_dated_as_scheduled(): void {
		$future = self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_status' => 'future',
				'post_parent' => $this->ids['about'],
				'post_date'   => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
			]
		);

		$children = array_column( $this->get( '/posts/' . $this->ids['about'] . '/hierarchy' )->get_data()['children'], null, 'id' );

		$this->assertSame( 'Scheduled', $children[ $future ]['date']['label'] );
	}

	public function test_a_child_past_its_schedule_is_marked_missed(): void {
		$late = self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_status' => 'future',
				'post_parent' => $this->ids['about'],
				'post_date'   => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
			]
		);
		// A schedule that cron never ran: still future, dated in the past.
		global $wpdb;
		$wpdb->update( $wpdb->posts, [ 'post_date' => '2020-01-01 00:00:00' ], [ 'ID' => $late ] );
		clean_post_cache( $late );

		$children = array_column( $this->get( '/posts/' . $this->ids['about'] . '/hierarchy' )->get_data()['children'], null, 'id' );

		$this->assertSame( 'Missed schedule', $children[ $late ]['date']['label'] );
		$this->assertTrue( $children[ $late ]['date']['missed'], 'Core prints it in its error style.' );
		$this->assertFalse( $children[ $this->ids['team'] ]['date']['missed'] );
	}

	public function test_a_private_child_has_the_view_link_core_prints(): void {
		wp_update_post(
			[
				'ID'          => $this->ids['history'],
				'post_status' => 'private',
			]
		);

		$children = array_column( $this->get( '/posts/' . $this->ids['about'] . '/hierarchy' )->get_data()['children'], null, 'id' );

		$this->assertSame( get_permalink( $this->ids['history'] ), $children[ $this->ids['history'] ]['view_link'] );
		$this->assertContains( 'Private', $children[ $this->ids['history'] ]['states'] );
	}

	public function test_each_child_carries_core_s_post_states(): void {
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $this->ids['history'] );
		wp_update_post(
			[
				'ID'            => $this->ids['team'],
				'post_password' => 'secret',
			]
		);

		$children = array_column( $this->get( '/posts/' . $this->ids['about'] . '/hierarchy' )->get_data()['children'], null, 'id' );

		$this->assertSame( [ 'Front Page' ], $children[ $this->ids['history'] ]['states'] );
		$this->assertSame( [ 'Password protected' ], $children[ $this->ids['team'] ]['states'] );
	}

	public function test_states_authors_and_lock_holders_are_sent_as_the_text_core_s_row_shows(): void {
		// WordPress stores a display name with its & encoded; core's row prints it, so it reads as the name.
		$tom = self::factory()->user->create(
			[
				'role'         => 'editor',
				'display_name' => 'Tom & Jerry’s',
			]
		);
		wp_update_post(
			[
				'ID'          => $this->ids['history'],
				'post_author' => $tom,
			]
		);
		update_post_meta( $this->ids['team'], '_edit_lock', time() . ':' . $tom );
		// A plugin's state, built with esc_html() as plugins build them, and one with markup.
		add_filter(
			'display_post_states',
			static function ( array $states ): array {
				$states['shop']  = esc_html( 'Shop’s & “Main” <page>' );
				$states['built'] = '<span class="dashicons dashicons-admin-site"></span>Built with X';
				return $states;
			}
		);

		$children = array_column( $this->get( '/posts/' . $this->ids['about'] . '/hierarchy' )->get_data()['children'], null, 'id' );

		$this->assertSame( 'Tom & Jerry’s', $children[ $this->ids['history'] ]['author'] );
		$this->assertSame( 'Tom & Jerry’s', $children[ $this->ids['team'] ]['locked_by'] );
		$this->assertSame( [ 'Shop’s & “Main” <page>', 'Built with X' ], $children[ $this->ids['history'] ]['states'], 'The script shows each as text, as the browser reads core’s.' );
	}

	public function test_each_child_s_title_is_read_once(): void {
		$reads = 0;
		$team  = $this->ids['team'];
		add_filter(
			'the_title',
			static function ( $title, $post_id = 0 ) use ( &$reads, $team ) {
				$reads += $team === (int) $post_id ? 1 : 0;
				return $title;
			},
			10,
			2
		);

		$this->get( '/posts/' . $this->ids['about'] . '/hierarchy' );

		$this->assertSame( 1, $reads );
	}

	public function test_a_post_state_callback_that_asks_for_the_screen_does_not_break_the_route(): void {
		// A plugin's display_post_states callback, hooked everywhere, that reads the admin screen as core's list does.
		add_filter(
			'display_post_states',
			static function ( array $states ): array {
				$screen = get_current_screen();
				return null === $screen ? $states + [ 'featured' => 'Featured' ] : $states;
			}
		);

		$response = $this->get( '/posts/' . $this->ids['about'] . '/hierarchy' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ 'Featured' ], $response->get_data()['children'][0]['states'] );
	}

	public function test_a_child_another_user_is_editing_says_who(): void {
		$other = self::factory()->user->create(
			[
				'role'         => 'editor',
				'display_name' => 'Priya',
			]
		);
		update_post_meta( $this->ids['team'], '_edit_lock', time() . ':' . $other );

		$children = array_column( $this->get( '/posts/' . $this->ids['about'] . '/hierarchy' )->get_data()['children'], null, 'id' );

		$this->assertSame( 'Priya', $children[ $this->ids['team'] ]['locked_by'] );
		$this->assertNull( $children[ $this->ids['history'] ]['locked_by'] );
	}

	public function test_a_lock_the_user_holds_or_that_expired_names_no_one(): void {
		$other = self::factory()->user->create( [ 'role' => 'editor' ] );
		update_post_meta( $this->ids['team'], '_edit_lock', time() . ':' . $this->admin );
		// An hour ago: well past core's lock window of 150 seconds.
		update_post_meta( $this->ids['history'], '_edit_lock', ( time() - HOUR_IN_SECONDS ) . ':' . $other );

		$children = array_column( $this->get( '/posts/' . $this->ids['about'] . '/hierarchy' )->get_data()['children'], null, 'id' );

		$this->assertNull( $children[ $this->ids['team'] ]['locked_by'], 'The user’s own lock is not another user editing.' );
		$this->assertNull( $children[ $this->ids['history'] ]['locked_by'], 'A lock past its window has lapsed.' );
	}

	public function test_a_child_whose_author_is_gone_has_no_author(): void {
		global $wpdb, $authordata;
		$wpdb->update( $wpdb->posts, [ 'post_author' => 999999 ], [ 'ID' => $this->ids['team'] ] );
		$wpdb->update( $wpdb->posts, [ 'post_author' => 0 ], [ 'ID' => $this->ids['history'] ] );
		clean_post_cache( $this->ids['team'] );
		clean_post_cache( $this->ids['history'] );
		// A loop earlier in the request, a theme's or a plugin's, leaves its author behind.
		$authordata = get_userdata( $this->admin ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Sets the state the route must not read.

		try {
			$children = array_column( $this->get( '/posts/' . $this->ids['about'] . '/hierarchy' )->get_data()['children'], null, 'id' );
		} finally {
			$authordata = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restores it.
		}

		$this->assertSame( '', $children[ $this->ids['team'] ]['author'], 'A deleted user.' );
		$this->assertSame( '', $children[ $this->ids['history'] ]['author'], 'No author, not the last loop’s.' );
	}

	public function test_a_page_of_50_children_reads_their_locks_in_one_query(): void {
		global $wpdb;
		for ( $i = 1; $i <= 50; $i++ ) {
			$child = $this->page( sprintf( 'bulk-%02d', $i ), $this->ids['about'] );
			// Each by an author of its own, so a user read per row would show.
			$wpdb->update( $wpdb->posts, [ 'post_author' => self::factory()->user->create( [ 'role' => 'author' ] ) ], [ 'ID' => $child ] );
			clean_post_cache( $child );
		}
		$route = '/posts/' . $this->ids['about'] . '/hierarchy';
		// The first request after a flush also reads options that core loads once a request.
		wp_cache_flush();
		$this->get( $route, [ 'children_per_page' => 1 ] );

		wp_cache_flush();
		$queries = $wpdb->num_queries;
		$this->assertCount( 1, $this->get( $route, [ 'children_per_page' => 1 ] )->get_data()['children'] );
		$one = $wpdb->num_queries - $queries;

		wp_cache_flush();
		$queries = $wpdb->num_queries;
		$this->assertCount( 50, $this->get( $route, [ 'children_per_page' => 50 ] )->get_data()['children'] );
		$fifty = $wpdb->num_queries - $queries;

		$this->assertSame( $one, $fifty, 'Fifty editable rows cost the queries of one: their meta, edit locks included, loads in one query.' );
	}

	public function test_fifty_children_locked_by_fifty_users_cost_the_queries_of_one_locked_child(): void {
		global $wpdb;
		$children = [ $this->ids['history'], $this->ids['team'] ];
		for ( $i = 1; $i <= 48; $i++ ) {
			$children[] = $this->page( sprintf( 'locked-%02d', $i ), $this->ids['about'] );
		}
		foreach ( $children as $child ) {
			// Each held by a user of its own, so a user read per row would show.
			update_post_meta( $child, '_edit_lock', time() . ':' . self::factory()->user->create( [ 'role' => 'editor' ] ) );
		}
		$route = '/posts/' . $this->ids['about'] . '/hierarchy';
		wp_cache_flush();
		$this->get( $route, [ 'children_per_page' => 1 ] );

		wp_cache_flush();
		$queries = $wpdb->num_queries;
		$this->assertNotNull( $this->get( $route, [ 'children_per_page' => 1 ] )->get_data()['children'][0]['locked_by'] );
		$one = $wpdb->num_queries - $queries;

		wp_cache_flush();
		$queries  = $wpdb->num_queries;
		$children = $this->get( $route, [ 'children_per_page' => 50 ] )->get_data()['children'];
		$fifty    = $wpdb->num_queries - $queries;

		$this->assertCount( 50, array_filter( array_column( $children, 'locked_by' ) ) );
		$this->assertSame( $one, $fifty, 'The lock holders are read in one go, however many.' );
	}

	public function test_children_whose_authors_are_gone_cost_the_queries_of_one(): void {
		global $wpdb;
		for ( $i = 1; $i <= 50; $i++ ) {
			$child = $this->page( sprintf( 'orphan-%02d', $i ), $this->ids['about'] );
			// Users deleted without reassigning their pages: no such user, one for each row.
			$wpdb->update( $wpdb->posts, [ 'post_author' => 900000 + $i ], [ 'ID' => $child ] );
			clean_post_cache( $child );
		}
		$route = '/posts/' . $this->ids['about'] . '/hierarchy';
		// The first request after a flush also reads options that core loads once a request.
		wp_cache_flush();
		$this->get( $route, [ 'children_per_page' => 1 ] );

		wp_cache_flush();
		$queries = $wpdb->num_queries;
		$this->assertCount( 1, $this->get( $route, [ 'children_per_page' => 1 ] )->get_data()['children'] );
		$one = $wpdb->num_queries - $queries;

		wp_cache_flush();
		$queries  = $wpdb->num_queries;
		$children = $this->get( $route, [ 'children_per_page' => 50 ] )->get_data()['children'];
		$fifty    = $wpdb->num_queries - $queries;

		$this->assertSame( $one, $fifty, 'The authors are read once a page, found or not.' );
		$this->assertSame( [ '' ], array_values( array_unique( array_column( array_slice( $children, 2 ), 'author' ) ) ), 'No author for a deleted user.' );
	}

	public function test_each_child_carries_its_place_among_its_siblings(): void {
		$route = '/posts/' . $this->ids['about'] . '/hierarchy';

		$second = $this->get(
			$route,
			[
				'children_page'     => 2,
				'children_per_page' => 1,
			]
		)->get_data()['children'][0];
		$this->assertSame( $this->ids['team'], $second['id'] );
		$this->assertSame( 2, $second['position'], 'History, then Team.' );
		$this->assertSame( 2, $second['setsize'] );

		add_filter( 'hierarchy_guard_count_cap', static fn(): int => 1 );
		$capped = $this->get( $route )->get_data()['children'][0];
		$this->assertNull( $capped['position'], 'Past the cap no place is known, as the server prints none.' );
		$this->assertNull( $capped['setsize'] );
	}

	public function test_a_child_s_title_reads_as_core_s_list_prints_it(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/template.php';
		$wpdb->update( $wpdb->posts, [ 'post_title' => '<b>Bold</b> &amp; Co' ], [ 'ID' => $this->ids['team'] ] );
		clean_post_cache( $this->ids['team'] );

		$children = array_column( $this->get( '/posts/' . $this->ids['about'] . '/hierarchy' )->get_data()['children'], null, 'id' );

		$this->assertSame( '<b>Bold</b> & Co', $children[ $this->ids['team'] ]['title'], 'Core’s list escapes the markup, so it reads as text, and decodes the entity.' );
		$this->assertSame( html_entity_decode( _draft_or_post_title( $this->ids['team'] ), ENT_QUOTES, 'UTF-8' ), $children[ $this->ids['team'] ]['title'] );
	}

	public function test_each_child_carries_core_s_row_classes(): void {
		$other = self::factory()->user->create( [ 'role' => 'editor' ] );
		wp_update_post(
			[
				'ID'          => $this->ids['history'],
				'post_author' => $other,
				'post_status' => 'private',
			]
		);
		wp_update_post(
			[
				'ID'          => $this->ids['team'],
				'post_author' => $this->admin,
			]
		);

		$children = array_column( $this->get( '/posts/' . $this->ids['about'] . '/hierarchy' )->get_data()['children'], null, 'id' );

		$this->assertSame( [ 'iedit', 'author-other', 'post-' . $this->ids['history'], 'type-page', 'status-private', 'hentry' ], $children[ $this->ids['history'] ]['classes'] );
		$this->assertSame( [ 'iedit', 'author-self', 'post-' . $this->ids['team'], 'type-page', 'status-publish', 'hentry' ], $children[ $this->ids['team'] ]['classes'], 'Not the bare type name core adds outside wp-admin.' );
	}

	public function test_the_route_builds_the_row_classes_without_the_post_class_filter(): void {
		$called = 0;
		add_filter(
			'post_class',
			static function ( $classes ) use ( &$called ) {
				// A theme's callback that assumes the front-end loop could warn or fail in a REST request.
				++$called;
				return $classes;
			}
		);
		update_post_meta( $this->ids['team'], '_thumbnail_id', self::factory()->attachment->create() );
		add_theme_support( 'post-thumbnails' );
		wp_update_post(
			[
				'ID'            => $this->ids['history'],
				'post_password' => 'secret',
			]
		);

		$children = array_column( $this->get( '/posts/' . $this->ids['about'] . '/hierarchy' )->get_data()['children'], null, 'id' );

		$this->assertSame( 0, $called, 'The route never runs post_class.' );
		$this->assertContains( 'has-post-thumbnail', $children[ $this->ids['team'] ]['classes'] );
		$this->assertContains( 'post-password-required', $children[ $this->ids['history'] ]['classes'], 'As get_post_class() marks it without the password cookie.' );
	}

	public function test_a_child_the_user_may_not_trash_has_no_trash_link(): void {
		$kept = $this->ids['team'];
		add_filter(
			'map_meta_cap',
			static function ( array $caps, string $cap, int $user_id, array $args ) use ( $kept ): array {
				return 'delete_post' === $cap && isset( $args[0] ) && $kept === (int) $args[0] ? [ 'do_not_allow' ] : $caps;
			},
			10,
			4
		);

		$children = array_column( $this->get( '/posts/' . $this->ids['about'] . '/hierarchy' )->get_data()['children'], null, 'id' );

		$this->assertNull( $children[ $kept ]['trash_link'] );
		$this->assertNotNull( $children[ $this->ids['history'] ]['trash_link'] );
	}

	public function test_the_tree_s_children_need_edit_rights_on_the_page_they_open(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$this->assertSame( 403, $this->get( '/posts/' . $this->ids['about'] . '/hierarchy', [ 'children_per_page' => 50 ] )->get_status() );
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

	public function test_a_page_has_no_routes_for_its_old_urls(): void {
		$this->rename( $this->ids['about'], 'about-us' );

		foreach ( [ 'pairs', 'csv' ] as $kind ) {
			$response = $this->get( '/posts/' . $this->ids['about'] . '/' . $kind, [ 'from' => 'company/about' ] );
			$this->assertSame( 404, $response->get_status(), $kind );
			$this->assertSame( 'rest_no_route', $response->get_data()['code'], $kind );
		}
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

	public function test_the_pairs_of_a_page_nobody_can_view_are_still_served(): void {
		update_option( Settings::OPTION, [ 'serve_redirects' => true ] );
		$this->rename( $this->ids['about'], 'about-us' );
		wp_update_post(
			[
				'ID'          => $this->ids['about'],
				'post_status' => 'private',
			]
		);

		$data = $this->get( '/posts/' . $this->ids['about'] . '/hierarchy' )->get_data();

		$this->assertFalse( $data['redirects']['serving'], 'Nothing redirects to the page itself.' );
		$this->assertTrue( $data['redirects']['pairs_served'], 'Its published subpages are redirected to.' );

		delete_option( Settings::OPTION );
		$this->assertFalse( $this->get( '/posts/' . $this->ids['about'] . '/hierarchy' )->get_data()['redirects']['pairs_served'] );
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
