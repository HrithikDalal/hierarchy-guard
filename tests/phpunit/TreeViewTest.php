<?php
/**
 * The Tree view of a supported list screen.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Admin\List_Table;
use HierarchyGuard\Admin\Tree_View;
use HierarchyGuard\Post_Types;
use HierarchyGuard\Settings;
use WP_Query;
use WP_UnitTestCase;

/**
 * @covers \HierarchyGuard\Admin\Tree_View
 */
final class TreeViewTest extends WP_UnitTestCase {

	use Trash_Fixtures;

	/**
	 * Page IDs.
	 *
	 * @var array<string,int>
	 */
	private array $ids;

	private Tree_View $tree;

	public function set_up(): void {
		parent::set_up();
		require_once ABSPATH . 'wp-admin/includes/template.php';
		require_once ABSPATH . 'wp-admin/includes/post.php';
		delete_option( Settings::OPTION );
		Post_Types::reset();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->ids  = $this->make_tree();
		$this->tree = new Tree_View();
		set_current_screen( 'edit-page' );
		$GLOBALS['pagenow'] = 'edit.php';
		$_GET               = [
			'post_type'          => 'page',
			Tree_View::QUERY_VAR => Tree_View::VIEW,
		];
	}

	public function tear_down(): void {
		$_GET = [];
		unset( $GLOBALS['pagenow'], $_SERVER['HTTP_REFERER'] );
		$GLOBALS['wp_query']     = new WP_Query();
		$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
		set_current_screen( 'front' );
		parent::tear_down();
	}

	/**
	 * The query vars core's list screen asks for on a hierarchical type.
	 *
	 * @return array<string,mixed>
	 */
	private static function core_vars(): array {
		return [
			'post_type'              => 'page',
			'orderby'                => 'menu_order title',
			'order'                  => 'asc',
			'posts_per_page'         => -1,
			'posts_per_archive_page' => -1,
			'fields'                 => 'id=>parent',
		];
	}

	/**
	 * Runs core's own list query for the request in $_GET.
	 *
	 * @return WP_Query
	 */
	private function list_query(): WP_Query {
		// As on edit.php, where core matches no rewrite rule, whatever permalinks an earlier test left.
		$server                 = $_SERVER;
		$_SERVER['REQUEST_URI'] = '/wp-admin/edit.php';
		$_SERVER['PHP_SELF']    = '/wp-admin/edit.php';
		wp_edit_posts_query( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The list screen's own request, built in the test.
		$_SERVER = $server;
		return $GLOBALS['wp_query'];
	}

	/**
	 * Hands this test's view a page of top-level rows, as core's main query holds them.
	 *
	 * @param int[] $ids   Top-level post IDs on the page.
	 * @param int   $total Core's item count.
	 * @param int   $paged Page number.
	 * @return array<int,mixed> The rows core prints.
	 */
	private function open_levels( array $ids, int $total, int $paged = 1 ): array {
		$query = new WP_Query();
		$query->set( 'post_type', 'page' );
		$query->set( 'paged', $paged );
		$query->found_posts      = $total;
		$GLOBALS['wp_the_query'] = $query;
		return (array) $this->tree->open_levels( array_map( 'get_post', $ids ), $query );
	}

	/**
	 * Gives a page many subpages, named so they sort in creation order.
	 *
	 * @param int    $parent_id Parent page.
	 * @param int    $count     How many.
	 * @param string $prefix    Slug and title prefix.
	 * @return int[] Their IDs, in menu order then title.
	 */
	private function subpages( int $parent_id, int $count, string $prefix = 'sub' ): array {
		$ids = [];
		for ( $i = 1; $i <= $count; $i++ ) {
			$ids[] = $this->page( sprintf( '%s-%03d', $prefix, $i ), $parent_id );
		}
		return $ids;
	}

	public function test_the_view_is_the_tree_only_when_asked_on_a_supported_list(): void {
		$this->assertSame( 'tree', $this->tree->mode( 'page' ) );

		$_GET['s'] = 'team';
		$this->assertSame( 'search', $this->tree->mode( 'page' ) );
		$_GET['s'] = '   ';
		$this->assertSame( 'tree', $this->tree->mode( 'page' ), 'A search of spaces searches nothing.' );
		unset( $_GET['s'] );

		$_GET['post_status'] = 'all';
		$this->assertSame( 'tree', $this->tree->mode( 'page' ), 'Core’s search form sends post_status=all.' );
		$_GET['post_status'] = 'trash';
		$this->assertSame( '', $this->tree->mode( 'page' ), 'The Trash is a view of its own.' );
		unset( $_GET['post_status'] );

		$_GET[ List_Table::QUERY_VAR ] = (string) $this->ids['about'];
		$this->assertSame( '', $this->tree->mode( 'page' ), 'The subtree filter is a view of its own.' );
		unset( $_GET[ List_Table::QUERY_VAR ] );

		$this->assertSame( '', $this->tree->mode( 'post' ), 'Posts are not hierarchical.' );
		$GLOBALS['pagenow'] = 'upload.php';
		$this->assertSame( '', $this->tree->mode( 'page' ) );
		$GLOBALS['pagenow']           = 'edit.php';
		$_GET[ Tree_View::QUERY_VAR ] = 'list';
		$this->assertSame( '', $this->tree->mode( 'page' ) );
	}

	public function test_crafted_view_arguments_turn_the_view_off_without_a_warning(): void {
		$_GET[ Tree_View::QUERY_VAR ] = [ Tree_View::VIEW ];
		$this->assertSame( '', $this->tree->mode( 'page' ) );

		$_GET[ Tree_View::QUERY_VAR ] = Tree_View::VIEW;
		$_GET['s']                    = [ 'team' ];
		$_GET['post_status']          = [ 'trash' ];
		$this->assertSame( 'tree', $this->tree->mode( 'page' ), 'A search or status that is not text is no search and no status.' );
	}

	public function test_a_crafted_subtree_filter_is_no_filter_and_warns_nothing(): void {
		$_GET[ List_Table::QUERY_VAR ] = [ (string) $this->ids['about'] ];

		$this->assertSame( 0, List_Table::under() );
		$this->assertSame( 'tree', $this->tree->mode( 'page' ), 'A subtree filter that is not text is no filter.' );
	}

	public function test_the_request_filter_leaves_the_front_end_and_an_array_post_type_alone(): void {
		$vars = [ 'post_type' => [ 'post', 'page' ] ];
		$this->assertSame( $vars, $this->tree->filter_request( $vars ), 'An array post type in wp-admin is not one list.' );

		set_current_screen( 'front' );
		$GLOBALS['pagenow'] = 'index.php';
		$this->assertSame( $vars, $this->tree->filter_request( $vars ), 'A visitor’s ?post_type[]=post is never read.' );
		$this->assertSame( self::core_vars(), $this->tree->filter_request( self::core_vars() ), 'The view acts only on edit.php.' );
	}

	public function test_the_top_level_is_core_s_query_with_post_parent_0_twenty_to_a_page(): void {
		$vars = $this->tree->filter_request( self::core_vars() );

		$this->assertSame( 0, $vars['post_parent'] );
		$this->assertSame(
			[
				'menu_order' => 'ASC',
				'title'      => 'ASC',
			],
			$vars['orderby'],
			'An array, so core pages its rows instead of loading every top-level page for its indented tree.'
		);
		$this->assertSame( 20, $vars['posts_per_page'] );
		$this->assertArrayNotHasKey( 'fields', $vars );
		$this->assertArrayNotHasKey( 'posts_per_archive_page', $vars );
	}

	public function test_a_column_s_sort_is_left_to_core(): void {
		$vars = $this->tree->filter_request(
			[
				'post_type'      => 'page',
				'orderby'        => 'date',
				'order'          => 'desc',
				'posts_per_page' => 20,
			]
		);

		$this->assertSame( 'date', $vars['orderby'] );
		$this->assertSame( 0, $vars['post_parent'] );
	}

	public function test_a_search_lists_the_matches_at_any_level_in_the_list_s_order(): void {
		$_GET['s'] = 'team';

		$vars = $this->tree->filter_request( self::core_vars() + [ 's' => 'team' ] );

		$this->assertArrayNotHasKey( 'post_parent', $vars );
		$this->assertSame(
			[
				'menu_order' => 'ASC',
				'title'      => 'ASC',
			],
			$vars['orderby']
		);
		$this->assertSame( 20, $vars['posts_per_page'] );
	}

	public function test_other_lists_are_left_alone(): void {
		$_GET = [ 'post_type' => 'page' ];

		$this->assertSame( self::core_vars(), $this->tree->filter_request( self::core_vars() ) );
	}

	public function test_core_s_list_query_pages_the_top_level_and_never_reads_every_child_of_0(): void {
		update_user_option( get_current_user_id(), 'edit_page_per_page', 2 );
		$this->page( 'zeta' );
		$this->page( 'alpha' );
		$sql = [];
		$log = static function ( string $statement ) use ( &$sql ): string {
			$sql[] = $statement;
			return $statement;
		};
		add_filter( 'query', $log );
		$query = $this->list_query();
		remove_filter( 'query', $log );

		$this->assertSame( [ 'alpha', 'company' ], wp_list_pluck( $query->posts, 'post_name' ) );
		$this->assertSame( 3, (int) $query->found_posts, 'Core counts the top level for its paging.' );
		$this->assertNotSame( 'menu_order title', $query->query['orderby'], 'Not core’s tree mode.' );
		foreach ( $sql as $statement ) {
			$this->assertDoesNotMatchRegularExpression( '/post_parent IN \( 0 \)/', $statement, 'Subtree::ordered_children() is never asked for the top level.' );
		}
	}

	public function test_a_search_in_the_tree_is_one_flat_page_of_matches(): void {
		$_GET['s'] = 'a';

		$query = $this->list_query();

		$this->assertSame( [ 'about', 'company', 'leadership', 'team' ], wp_list_pluck( $query->posts, 'post_name' ), 'Matches at any level, in menu order then title.' );
	}

	public function test_tree_joins_the_views_row(): void {
		$views = $this->tree->views( [ 'all' => '<a href="edit.php?post_type=page">All</a>' ] );

		$this->assertSame( [ 'all', 'hierarchy_guard_tree' ], array_keys( $views ) );
		$this->assertSame( '<a href="' . esc_url( Tree_View::url( 'page' ) ) . '" class="current" aria-current="page">Tree</a>', $views['hierarchy_guard_tree'] );

		$_GET = [ 'post_type' => 'page' ];
		$this->assertSame( '<a href="' . esc_url( Tree_View::url( 'page' ) ) . '">Tree</a>', $this->tree->views( [] )['hierarchy_guard_tree'] );
		$this->assertStringContainsString( 'post_type=page&#038;hierarchy_guard_view=tree', esc_url( Tree_View::url( 'page' ) ) );
	}

	public function test_loading_the_tree_hooks_its_pieces_into_core_s_list(): void {
		$this->tree->load();

		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound,WordPress.NamingConventions.ValidHookName.UseUnderscores -- Core's own list filters.
		$this->assertSame(
			[ 'title' => 'Title' ],
			apply_filters(
				'manage_page_posts_columns',
				[
					'cb'    => '<input type="checkbox" />',
					'title' => 'Title',
				]
			),
			'No checkboxes: the subpages the tree loads are not core’s rows.'
		);
		$this->assertSame( [], apply_filters( 'bulk_actions-edit-page', [ 'trash' => 'Move to Trash' ] ) );
		$this->assertFalse( apply_filters( 'quick_edit_enabled_for_post_type', true, 'page' ), 'No Quick Edit in the tree.' );
		$this->assertStringContainsString( 'hierarchy-guard-tree-view', apply_filters( 'admin_body_class', '' ) );
		$this->assertArrayHasKey( 'hierarchy_guard_tree', apply_filters( 'views_edit-page', [] ) );
		// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound,WordPress.NamingConventions.ValidHookName.UseUnderscores
	}

	public function test_a_search_in_the_tree_keeps_the_tree_s_list_but_not_its_indents(): void {
		$_GET['s'] = 'team';

		$this->tree->load();

		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound,WordPress.NamingConventions.ValidHookName.UseUnderscores -- Core's own list filters.
		$this->assertArrayNotHasKey( 'cb', apply_filters( 'manage_page_posts_columns', [ 'cb' => '' ] ) );
		$this->assertSame( [], apply_filters( 'bulk_actions-edit-page', [ 'trash' => 'Move to Trash' ] ) );
		$this->assertFalse( apply_filters( 'quick_edit_enabled_for_post_type', true, 'page' ) );
		$this->assertStringNotContainsString( 'hierarchy-guard-tree-view', apply_filters( 'admin_body_class', '' ), 'A search is flat: no indents.' );
		// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound,WordPress.NamingConventions.ValidHookName.UseUnderscores
	}

	public function test_the_list_keeps_its_checkboxes_and_quick_edit_outside_the_tree(): void {
		$_GET = [ 'post_type' => 'page' ];

		$this->tree->load();

		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound,WordPress.NamingConventions.ValidHookName.UseUnderscores -- Core's own list filters.
		$this->assertTrue( apply_filters( 'quick_edit_enabled_for_post_type', true, 'page' ) );
		$this->assertArrayHasKey( 'cb', apply_filters( 'manage_page_posts_columns', [ 'cb' => '' ] ) );
		$this->assertArrayHasKey( 'hierarchy_guard_tree', apply_filters( 'views_edit-page', [] ), 'Tree is in the views row of every list.' );
		// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound,WordPress.NamingConventions.ValidHookName.UseUnderscores
	}

	public function test_the_plugin_hooks_the_view_into_core(): void {
		global $wp_filter;
		$found = [];
		foreach ( [ 'request', 'the_posts', 'load-edit.php' ] as $hook ) {
			foreach ( $wp_filter[ $hook ]->callbacks[10] ?? [] as $callback ) {
				if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof Tree_View ) {
					$found[] = $hook;
				}
			}
		}

		$this->assertSame( [ 'request', 'the_posts', 'load-edit.php' ], $found );
	}

	public function test_without_an_opened_page_the_rows_are_core_s_with_their_places(): void {
		$rows = $this->open_levels( [ $this->ids['company'] ], 7, 2 );

		$this->assertSame( [ $this->ids['company'] ], wp_list_pluck( $rows, 'ID' ) );
		$this->assertSame(
			[
				'level'    => 0,
				'parent'   => 0,
				'position' => 21,
				'setsize'  => 7,
				'page'     => 1,
				'open'     => false,
				'shown'    => 0,
				'more'     => false,
				'capped'   => false,
			],
			$this->tree->row( $this->ids['company'] ),
			'Page 2 of 20 a page starts at 21; the size is core’s item count.'
		);
	}

	public function test_an_opened_page_s_levels_follow_their_parents_in_core_s_results(): void {
		$_GET[ Tree_View::OPEN_VAR ] = (string) $this->ids['team'];
		$lonely                      = $this->page( 'lonely' );

		$rows = $this->open_levels( [ $this->ids['company'], $lonely ], 2 );

		$this->assertSame(
			[ 'company', 'about', 'team', 'leadership', 'lonely' ],
			wp_list_pluck( $rows, 'post_name' ),
			'The levels above the opened page show only the path down to it: History, beside Team, is left out.'
		);
		$this->assertTrue( $this->tree->row( $this->ids['about'] )['open'] );
		$this->assertSame( 3, $this->tree->row( $this->ids['leadership'] )['level'], 'Leadership sits three levels down.' );
		$this->assertSame( $this->ids['team'], $this->tree->row( $this->ids['leadership'] )['parent'] );
		$this->assertSame( 2, $this->tree->row( $this->ids['team'] )['position'], 'History, then Team.' );
		$this->assertSame( 2, $this->tree->row( $this->ids['team'] )['setsize'] );
		$this->assertSame( 2, $this->tree->row( $lonely )['position'], 'The top level keeps its places around the opened levels.' );
	}

	public function test_core_prints_the_opened_levels_with_its_own_level_class(): void {
		$_GET[ Tree_View::OPEN_VAR ] = (string) $this->ids['about'];

		$query = $this->list_query();

		$this->assertSame( [ 'company', 'about', 'history', 'team' ], wp_list_pluck( $query->posts, 'post_name' ) );
		$this->assertSame( 4, $query->post_count );
		$this->assertSame( 1, (int) $query->found_posts, 'Core still counts and pages only the top level.' );

		$table = _get_list_table( 'WP_Posts_List_Table', [ 'screen' => 'edit-page' ] );
		ob_start();
		$table->single_row( $query->posts[3] );
		$html = (string) ob_get_clean();
		$this->assertMatchesRegularExpression( '/<tr id="post-' . $this->ids['team'] . '" class="[^"]*\\blevel-2\\b/', $html, 'Core’s own row prints Team two levels down.' );
	}

	public function test_each_level_above_shows_only_the_page_that_leads_down_however_far_in_it_is(): void {
		$many = $this->subpages( $this->ids['about'], 58 );
		// About's subpages sort as history, sub-001 … sub-058, team: team is the 60th, on page 2.
		$_GET[ Tree_View::OPEN_VAR ] = (string) $this->ids['team'];

		$rows = $this->open_levels( [ $this->ids['company'] ], 1 );

		$this->assertSame( [ 'company', 'about', 'team', 'leadership' ], wp_list_pluck( $rows, 'post_name' ), 'The depth plus one page of the opened page’s subpages, never 50 rows a level.' );
		$this->assertSame( 2, $this->tree->row( $this->ids['team'] )['page'], 'A trash from Team comes back to About’s page 2.' );
		$this->assertSame( 60, $this->tree->row( $this->ids['team'] )['position'] );
		$this->assertSame( 60, $this->tree->row( $this->ids['team'] )['setsize'] );
		$this->assertTrue( $this->tree->row( $this->ids['about'] )['open'] );
		$this->assertFalse( $this->tree->row( $this->ids['about'] )['more'], 'A level on the path has no Show more.' );
		$this->assertNotContains( $many[0], wp_list_pluck( $rows, 'ID' ) );
	}

	public function test_a_deep_opened_page_costs_its_depth_plus_one_page(): void {
		$parent = $this->ids['leadership'];
		$chain  = [];
		for ( $depth = 1; $depth <= 8; $depth++ ) {
			$this->subpages( $parent, 3, "side{$depth}" );
			$parent  = $this->page( "deep-{$depth}", $parent );
			$chain[] = $parent;
		}
		$this->subpages( $parent, 60, 'leaf' );
		$_GET[ Tree_View::OPEN_VAR ] = (string) $parent;

		$rows = $this->open_levels( [ $this->ids['company'] ], 1 );

		// Company, About, Team, Leadership, the eight deep pages, then 50 of the 60 leaves.
		$this->assertCount( 4 + 8 + 50, $rows );
		$this->assertSame( array_slice( $chain, 0, 7 ), array_slice( wp_list_pluck( $rows, 'ID' ), 4, 7 ) );
		$this->assertSame( 12, $this->tree->row( (int) $rows[ count( $rows ) - 1 ]->ID )['level'] );
	}

	public function test_the_opened_page_shows_the_children_page_it_asks_for_and_says_when_more_follow(): void {
		$this->subpages( $this->ids['team'], 120 );
		$_GET[ Tree_View::OPEN_VAR ] = (string) $this->ids['team'];

		$first = $this->open_levels( [ $this->ids['company'] ], 1 );
		$this->assertCount( 3 + 50, $first, 'Company, About, Team, and 50 of Team’s 121.' );
		$this->assertTrue( $this->tree->row( $this->ids['team'] )['more'] );
		$this->assertSame( 1, $this->tree->row( $this->ids['team'] )['shown'] );

		$_GET[ Tree_View::CHILDREN_PAGE_VAR ] = '3';
		$third                                = $this->open_levels( [ $this->ids['company'] ], 1 );
		$this->assertCount( 3 + 21, $third, 'Leadership and 120 more: page 3 holds the last 21.' );
		$this->assertFalse( $this->tree->row( $this->ids['team'] )['more'] );

		$_GET[ Tree_View::CHILDREN_PAGE_VAR ] = '99';
		$this->assertCount( 3 + 21, $this->open_levels( [ $this->ids['company'] ], 1 ), 'A page past the end shows the last one.' );
	}

	public function test_past_the_cap_the_opened_page_says_only_the_first_are_shown(): void {
		add_filter( 'hierarchy_guard_count_cap', static fn(): int => 60 );
		$this->subpages( $this->ids['team'], 70 );
		$_GET[ Tree_View::OPEN_VAR ]          = (string) $this->ids['team'];
		$_GET[ Tree_View::CHILDREN_PAGE_VAR ] = '2';

		$rows = $this->open_levels( [ $this->ids['company'] ], 1 );

		$this->assertCount( 3 + 10, $rows, 'Paging stops at the cap: page 2 holds the 51st to the 60th.' );
		$row = $this->tree->row( $this->ids['team'] );
		$this->assertFalse( $row['more'] );
		$this->assertTrue( $row['capped'] );
		$this->assertNull( $this->tree->row( (int) end( $rows )->ID )['setsize'], 'No set size past the cap.' );
	}

	public function test_a_level_the_user_may_not_edit_stays_closed(): void {
		$closed = $this->ids['about'];
		add_filter(
			'map_meta_cap',
			static function ( array $caps, string $cap, int $user_id, array $args ) use ( $closed ): array {
				return 'edit_post' === $cap && isset( $args[0] ) && $closed === (int) $args[0] ? [ 'do_not_allow' ] : $caps;
			},
			10,
			4
		);
		$_GET[ Tree_View::OPEN_VAR ] = (string) $this->ids['team'];

		$rows = $this->open_levels( [ $this->ids['company'] ], 1 );

		$this->assertSame( [ 'company', 'about' ], wp_list_pluck( $rows, 'post_name' ), 'Company opens; About, which the route would refuse, does not.' );
	}

	public function test_a_page_of_another_type_or_in_the_trash_opens_nothing(): void {
		$_GET[ Tree_View::OPEN_VAR ] = (string) self::factory()->post->create();
		$this->assertCount( 1, $this->open_levels( [ $this->ids['company'] ], 1 ) );

		$this->trash_raw( $this->ids['history'] );
		$_GET[ Tree_View::OPEN_VAR ] = (string) $this->ids['history'];
		$this->assertCount( 1, $this->open_levels( [ $this->ids['company'] ], 1 ) );
	}

	public function test_a_crafted_open_or_children_page_opens_nothing_and_warns_nothing(): void {
		$this->subpages( $this->ids['about'], 58 );
		// About's subpages sort as history, sub-001 … sub-058, team: 60, so it has a page 2.
		foreach ( [ 'abc', '-5', '-' . $this->ids['about'], '0', '', [ (string) $this->ids['about'] ] ] as $value ) {
			$_GET[ Tree_View::OPEN_VAR ]          = $value;
			$_GET[ Tree_View::CHILDREN_PAGE_VAR ] = [ '2' ];

			$this->assertSame( [ 'company' ], wp_list_pluck( $this->open_levels( [ $this->ids['company'] ], 1 ), 'post_name' ) );
		}

		$_GET[ Tree_View::OPEN_VAR ]          = (string) $this->ids['about'];
		$_GET[ Tree_View::CHILDREN_PAGE_VAR ] = '-2';
		$rows                                 = $this->open_levels( [ $this->ids['company'] ], 1 );
		$this->assertCount( 2 + 50, $rows, 'A page number below 1 is the first page, not page 2.' );
		$this->assertSame( 'history', $rows[2]->post_name );
		$this->assertSame( 1, $this->tree->row( $this->ids['about'] )['shown'] );
	}

	public function test_the_posts_filter_leaves_the_front_end_and_an_array_post_type_alone(): void {
		$posts = [ get_post( $this->ids['company'] ) ];
		$query = new WP_Query();
		$query->set( 'post_type', [ 'post', 'page' ] );
		$GLOBALS['wp_the_query'] = $query;
		$this->assertSame( $posts, $this->tree->open_levels( $posts, $query ) );

		set_current_screen( 'front' );
		$GLOBALS['pagenow'] = 'index.php';
		$this->assertSame( $posts, $this->tree->open_levels( $posts, $query ), 'A theme’s array post type on the front end is never read.' );
		$query->set( 'post_type', 'page' );
		$this->assertSame( $posts, $this->tree->open_levels( $posts, $query ) );
		$this->assertNull( $this->tree->row( $this->ids['company'] ) );
	}

	public function test_a_page_past_the_last_gets_the_view_s_own_count_so_core_sends_it_to_the_last_page(): void {
		update_user_option( get_current_user_id(), 'edit_page_per_page', 1 );
		$this->page( 'alpha' );
		$last = $this->page( 'zeta' );
		wp_trash_post( $last );
		$_GET['paged'] = '3';

		$query = $this->list_query();

		$this->assertSame( [], $query->posts, 'Zeta was the only row on page 3.' );
		$this->assertSame( 2, (int) $query->found_posts, 'Alpha and Company: core’s list then redirects to page 2 instead of counting every page.' );
		$this->assertSame( 2, (int) $query->max_num_pages, 'The page count agrees with the item count.' );
	}

	public function test_opening_a_level_costs_the_same_queries_whatever_its_size(): void {
		$small = $this->page( 'small' );
		$big   = $this->page( 'big' );
		$this->subpages( $small, 3, 's' );
		$this->subpages( $big, 150, 'b' );
		$cost = function ( int $root ): int {
			$_GET[ Tree_View::OPEN_VAR ] = (string) $root;
			wp_cache_flush();
			$before = get_num_queries();
			$this->open_levels( [ $root ], 1 );
			return get_num_queries() - $before;
		};

		$cost( $small ); // The first run also reads the plugin's settings.

		$this->assertSame( $cost( $small ), $cost( $big ), 'One page of 50 per opened level, read the same way.' );
	}

	public function test_the_opened_rows_meta_is_read_with_their_page(): void {
		$_GET[ Tree_View::OPEN_VAR ] = (string) $this->ids['team'];
		wp_cache_flush();

		$this->open_levels( [ $this->ids['company'] ], 1 );

		foreach ( [ 'about', 'team', 'leadership' ] as $name ) {
			$this->assertNotFalse( wp_cache_get( $this->ids[ $name ], 'post_meta' ), "{$name}’s meta is primed, so core’s row reads none of its own." );
		}
	}

	public function test_places_and_set_sizes_count_the_statuses_the_rows_show(): void {
		$parent = $this->ids['history'];
		foreach ( [ 'publish', 'draft', 'pending', 'private', 'auto-draft', 'inherit' ] as $status ) {
			$this->page( "s-{$status}", $parent, $status );
		}
		$this->trash_raw( $this->page( 's-trash', $parent ) );
		$_GET[ Tree_View::OPEN_VAR ] = (string) $parent;

		$rows = array_slice( $this->open_levels( [ $this->ids['company'] ], 1 ), 3 );

		$this->assertSame( [ 's-draft', 's-pending', 's-private', 's-publish' ], wp_list_pluck( $rows, 'post_name' ), 'No trash, auto-draft or inherit row.' );
		foreach ( $rows as $index => $row ) {
			$this->assertSame( $index + 1, $this->tree->row( (int) $row->ID )['position'] );
			$this->assertSame( 4, $this->tree->row( (int) $row->ID )['setsize'], 'The count leaves out the same statuses as the rows.' );
		}
	}

	public function test_a_crafted_date_or_page_is_left_out_of_the_place(): void {
		$_GET += [
			'm'     => [ '202610' ],
			'paged' => [ '3' ],
			'order' => [ 'desc' ],
		];

		$this->assertSame( admin_url( 'edit.php?post_type=page&hierarchy_guard_view=tree' ), Tree_View::place_url( 'page' ) );
	}

	public function test_the_place_keeps_only_the_view_s_own_arguments(): void {
		$_GET += [
			'paged'                      => '2',
			'm'                          => '202610',
			'orderby'                    => 'title',
			'order'                      => 'sideways',
			'trashed'                    => '1',
			'ids'                        => '7',
			Tree_View::CHILDREN_PAGE_VAR => '4',
		];

		$this->assertSame( admin_url( 'edit.php?post_type=page&hierarchy_guard_view=tree&orderby=title&m=202610&paged=2' ), Tree_View::place_url( 'page' ) );
		$this->assertSame( admin_url( 'edit.php?post_type=page&hierarchy_guard_view=tree&orderby=title&m=202610&paged=2&hierarchy_guard_open=5&hierarchy_guard_children_page=2' ), Tree_View::place_url( 'page', 5, 2 ) );
	}
}
