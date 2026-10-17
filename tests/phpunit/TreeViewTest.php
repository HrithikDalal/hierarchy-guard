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

	public function test_a_search_of_markup_alone_is_a_search_that_finds_nothing(): void {
		$bold      = self::factory()->post->create(
			[
				'post_type'    => 'page',
				'post_title'   => 'Styled',
				'post_content' => '<b>Bold</b> words',
			]
		);
		$_GET['s'] = '<b>';

		$this->assertSame( 'search', $this->tree->mode( 'page' ), 'Typed text, even if sanitizing empties it.' );
		$query = $this->list_query();

		$this->assertSame( [], $query->posts, 'Nothing is left to search for once sanitized, as on the Redirects tab.' );
		$this->assertNotContains( $bold, wp_list_pluck( $query->posts, 'ID' ) );
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

	/**
	 * Core's hidden Quick Edit and Bulk Edit forms, as edit.php prints them under the table.
	 *
	 * @return string
	 */
	private static function inline_edit_forms(): string {
		$table = _get_list_table( 'WP_Posts_List_Table', [ 'screen' => 'edit-page' ] );
		// Core prints the forms after its rows, with the last row's post still global.
		$GLOBALS['post'] = get_post( (int) get_page_by_path( 'company' )->ID ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Core's list leaves it set.
		ob_start();
		$table->inline_edit();
		$html = (string) ob_get_clean();
		unset( $GLOBALS['post'] );
		return $html;
	}

	public function test_core_s_hidden_edit_forms_list_no_pages_in_the_tree_or_its_search(): void {
		$_GET = [ 'post_type' => 'page' ];
		$this->tree->load();
		$list = self::inline_edit_forms();
		// Quick Edit's and Bulk Edit's; WordPress 7.1 gives the second its own ID, 6.6 doesn't.
		$this->assertSame( 2, substr_count( $list, "<select name='post_parent'" ), 'The list keeps core’s Parent dropdowns.' );
		$this->assertStringContainsString( '>Company</option>', $list );

		$_GET = [
			'post_type'          => 'page',
			Tree_View::QUERY_VAR => Tree_View::VIEW,
		];
		$this->tree->load();
		$tree = self::inline_edit_forms();
		$this->assertStringNotContainsString( "name='post_parent'", $tree, 'The forms can’t be reached from the tree, so they read no pages.' );
		$this->assertStringNotContainsString( '>Company</option>', $tree );

		remove_all_filters( 'quick_edit_dropdown_pages_args' );
		$_GET['s'] = 'team';
		$this->tree->load();
		$this->assertStringNotContainsString( "name='post_parent'", self::inline_edit_forms(), 'Nor from a search in the tree.' );
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

	public function test_a_later_page_of_subpages_keeps_its_places_among_all_of_them(): void {
		$this->subpages( $this->ids['team'], 120 );
		$_GET[ Tree_View::OPEN_VAR ]          = (string) $this->ids['team'];
		$_GET[ Tree_View::CHILDREN_PAGE_VAR ] = '2';

		$second = array_slice( $this->open_levels( [ $this->ids['company'] ], 1 ), 3 );
		$this->assertCount( 50, $second );
		$this->assertSame( 51, $this->tree->row( (int) $second[0]->ID )['position'], 'Page 2 starts at the 51st of Team’s 121.' );
		$this->assertSame( 100, $this->tree->row( (int) $second[49]->ID )['position'] );
		$this->assertSame( 121, $this->tree->row( (int) $second[49]->ID )['setsize'] );
		$this->assertSame( 2, $this->tree->row( (int) $second[0]->ID )['page'] );

		$_GET[ Tree_View::CHILDREN_PAGE_VAR ] = '3';
		$third                                = array_slice( $this->open_levels( [ $this->ids['company'] ], 1 ), 3 );
		$this->assertSame( 101, $this->tree->row( (int) $third[0]->ID )['position'] );
		$this->assertSame( 121, $this->tree->row( (int) end( $third )->ID )['position'] );
	}

	/**
	 * One page of a page's subpages, as the hierarchy route sends them to the script, by ID.
	 *
	 * @param int $parent_id Page.
	 * @return array<int,array<string,mixed>>
	 */
	private static function loaded( int $parent_id ): array {
		$request = new \WP_REST_Request( 'GET', '/hierarchy-guard/v1/posts/' . $parent_id . '/hierarchy' );
		$request->set_query_params( [ 'children_per_page' => Tree_View::PER_PAGE ] );
		return array_column( rest_do_request( $request )->get_data()['children'], null, 'id' );
	}

	public function test_both_paths_number_the_subpages_alike_around_one_the_user_may_not_read(): void {
		$hidden = $this->ids['history'];
		add_filter(
			'map_meta_cap',
			static function ( array $caps, string $cap, int $user_id, array $args ) use ( $hidden ): array {
				return 'read_post' === $cap && isset( $args[0] ) && $hidden === (int) $args[0] ? [ 'do_not_allow' ] : $caps;
			},
			10,
			4
		);
		$_GET[ Tree_View::OPEN_VAR ] = (string) $this->ids['about'];

		$this->assertSame( [ 'company', 'about', 'team' ], wp_list_pluck( $this->open_levels( [ $this->ids['company'] ], 1 ), 'post_name' ) );
		$loaded = self::loaded( $this->ids['about'] );

		$this->assertSame( [ $this->ids['team'] ], array_keys( $loaded ) );
		$this->assertSame( 2, $this->tree->row( $this->ids['team'] )['position'], 'History keeps its place, unseen.' );
		$this->assertSame( $this->tree->row( $this->ids['team'] )['position'], $loaded[ $this->ids['team'] ]['position'], 'The script shows the server’s place.' );
		$this->assertSame( $this->tree->row( $this->ids['team'] )['setsize'], $loaded[ $this->ids['team'] ]['setsize'] );
	}

	public function test_a_loaded_row_carries_core_s_row_classes_and_reads_its_title_as_core_s_row(): void {
		global $wpdb;
		$wpdb->update(
			$wpdb->posts,
			[
				'post_title'    => '<b>Bold</b> &amp; Co',
				'post_password' => 'secret',
			],
			[ 'ID' => $this->ids['team'] ]
		);
		clean_post_cache( $this->ids['team'] );
		// A public taxonomy on pages: core's row names each term.
		register_taxonomy_for_object_type( 'category', 'page' );
		wp_set_post_categories( $this->ids['team'], [ self::factory()->category->create( [ 'slug' => 'press' ] ) ] );
		// Core prints the opened levels as the Tree view's screen does: its query isn't core's indented
		// tree, so each row goes through display_rows() at level 0, with no level dashes on any version.
		$table = _get_list_table( 'WP_Posts_List_Table', [ 'screen' => 'edit-page' ] );
		ob_start();
		$table->display_rows( [ get_post( $this->ids['team'] ) ] );
		$html = (string) ob_get_clean();
		remove_filter( 'the_title', 'esc_html' );
		preg_match( '/<tr id="post-\d+" class="([^"]*)"/', $html, $row );
		preg_match( '/<a class="row-title"[^>]*>(.*?)<\/a>/', $html, $title );

		$loaded = self::loaded( $this->ids['about'] )[ $this->ids['team'] ];

		unregister_taxonomy_for_object_type( 'category', 'page' );

		$core = array_values( array_diff( explode( ' ', $row[1] ), [ 'level-2' ] ) );
		$this->assertContains( 'level-2', explode( ' ', $row[1] ) );
		$this->assertContains( 'category-press', $core );
		$this->assertContains( 'post-password-required', $core );
		$this->assertSame( $core, $loaded['classes'], 'Core’s classes, but the level, which the script adds.' );
		$this->assertStringContainsString( '<strong><a class="row-title"', $html, 'No level dashes before the title: the indent shows the level.' );
		$this->assertSame( html_entity_decode( $title[1], ENT_QUOTES, 'UTF-8' ), $loaded['title'], 'The text core’s row shows.' );
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

	public function test_opening_a_page_without_subpages_leaves_it_closed(): void {
		$_GET[ Tree_View::OPEN_VAR ] = (string) $this->ids['history'];

		$rows = $this->open_levels( [ $this->ids['company'] ], 1 );

		$this->assertSame( [ 'company', 'about', 'history' ], wp_list_pluck( $rows, 'post_name' ), 'The path down to it.' );
		$this->assertTrue( $this->tree->row( $this->ids['about'] )['open'] );
		$this->assertFalse( $this->tree->row( $this->ids['history'] )['open'], 'A stale link to a page whose subpages have gone: it has none to show.' );
		$this->assertSame( 0, $this->tree->row( $this->ids['history'] )['shown'] );
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

	public function test_a_crafted_sort_stays_one_argument_of_the_place(): void {
		$_GET['orderby'] = 'title&hierarchy_guard_open=5#x';

		$place = Tree_View::place_url( 'page', 7, 2 );

		$this->assertStringNotContainsString( '#', $place, 'No fragment.' );
		parse_str( (string) wp_parse_url( $place, PHP_URL_QUERY ), $args );
		$this->assertSame( 'title&hierarchy_guard_open=5#x', $args['orderby'], 'Carried over as core received it, as one argument.' );
		$this->assertSame( '7', $args[ Tree_View::OPEN_VAR ] );
		$this->assertSame( '2', $args[ Tree_View::CHILDREN_PAGE_VAR ] );

		ob_start();
		$this->tree->collapse_all( 'top' );
		$this->assertStringContainsString( 'orderby=title%26hierarchy_guard_open%3D5%23x', (string) ob_get_clean(), 'Collapse all, too.' );
	}

	public function test_the_trash_link_carries_the_tree_s_place_as_core_s_referer(): void {
		update_user_option( get_current_user_id(), 'edit_page_per_page', 1 );
		$_GET['paged']               = '3';
		$_GET['orderby']             = 'title';
		$_GET['order']               = 'desc';
		$_GET[ Tree_View::OPEN_VAR ] = (string) $this->ids['about'];
		$this->open_levels( [ $this->ids['company'] ], 3, 3 );
		$link = '<a href="' . esc_url( (string) get_delete_post_link( $this->ids['team'] ) ) . '" class="submitdelete" aria-label="Move “Team” to the Trash">Trash</a>';

		$actions = $this->tree->row_actions( [ 'trash' => $link ], get_post( $this->ids['team'] ) );

		$this->assertMatchesRegularExpression( '/^<a href="[^"]+" class="submitdelete" aria-label="Move “Team” to the Trash">Trash<\/a>$/', $actions['trash'], 'Core’s link, with one more argument.' );
		preg_match( '/href="([^"]+)"/', $actions['trash'], $href );
		parse_str( (string) wp_parse_url( html_entity_decode( $href[1] ), PHP_URL_QUERY ), $args );
		$this->assertSame( 'trash', $args['action'] );
		$this->assertSame( 1, wp_verify_nonce( $args['_wpnonce'], 'trash-post_' . $this->ids['team'] ) );
		$this->assertSame( Tree_View::place_url( 'page', $this->ids['about'] ), $args['_wp_http_referer'], 'The same page of top-level pages, opened at the trashed page’s parent.' );
		$this->assertStringContainsString( 'paged=3', $args['_wp_http_referer'] );
		$this->assertStringContainsString( 'orderby=title', $args['_wp_http_referer'] );
	}

	public function test_a_trash_from_a_search_in_the_tree_returns_to_the_search(): void {
		$_GET['s'] = 'team';
		$this->tree->load();
		$link = '<a href="' . esc_url( (string) get_delete_post_link( $this->ids['team'] ) ) . '" class="submitdelete">Trash</a>';

		$actions = apply_filters( 'page_row_actions', [ 'trash' => $link ], get_post( $this->ids['team'] ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's own row actions filter.

		$this->assertSame( $link, $actions['trash'], 'Core’s link: the browser’s referrer returns to the results.' );
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

	public function test_undo_lands_on_the_tree_s_page(): void {
		$_GET['doaction']        = 'undo';
		$_SERVER['HTTP_REFERER'] = Tree_View::place_url( 'page', $this->ids['about'] ) . '&paged=3&trashed=1&ids=9';
		$core                    = admin_url( 'edit.php?post_type=page&hierarchy_guard_view=tree&hierarchy_guard_open=' . $this->ids['about'] . '&paged=1&untrashed=1' );

		$this->tree->keep_page_on_undo();
		$location = apply_filters( 'wp_redirect', $core, 302 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's own redirect filter.

		$this->assertSame( str_replace( 'paged=1', 'paged=3', $core ), $location, 'Core sets the page from the Undo link, which has none.' );
	}

	public function test_undo_elsewhere_is_left_to_core(): void {
		$_GET['doaction']        = 'undo';
		$_SERVER['HTTP_REFERER'] = admin_url( 'edit.php?post_type=page&paged=3' );
		$core                    = admin_url( 'edit.php?post_type=page&paged=1' );

		$this->tree->keep_page_on_undo();

		$this->assertSame( $core, apply_filters( 'wp_redirect', $core, 302 ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's own redirect filter.
	}

	/**
	 * The _wp_http_referer a row's Trash link carries, or null.
	 *
	 * @param mixed $actions Row actions.
	 * @return string|null
	 */
	private static function trash_referer( $actions ): ?string {
		preg_match( '/href="([^"]+)"/', (string) ( $actions['trash'] ?? '' ), $href );
		parse_str( (string) wp_parse_url( html_entity_decode( $href[1] ?? '' ), PHP_URL_QUERY ), $args );
		return isset( $args['_wp_http_referer'] ) && is_string( $args['_wp_http_referer'] ) ? $args['_wp_http_referer'] : null;
	}

	/**
	 * Core's Trash link for a page, as its row prints it.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,string>
	 */
	private static function core_trash( int $post_id ): array {
		return [ 'trash' => '<a href="' . esc_url( (string) get_delete_post_link( $post_id ) ) . '" class="submitdelete">Trash</a>' ];
	}

	public function test_the_tree_s_load_hooks_the_trash_link_s_place_into_core_s_row_actions(): void {
		global $wp_filter;
		$_GET[ Tree_View::OPEN_VAR ] = (string) $this->ids['about'];

		$this->tree->load();

		$found = false;
		foreach ( $wp_filter['page_row_actions']->callbacks[20] ?? [] as $callback ) {
			if ( is_array( $callback['function'] ) && $this->tree === $callback['function'][0] && 'row_actions' === $callback['function'][1] && 2 === $callback['accepted_args'] ) {
				$found = true;
			}
		}
		$this->assertTrue( $found, 'The plugin’s own object, at 20 with 2 arguments.' );

		$this->open_levels( [ $this->ids['company'] ], 1 );
		$actions = apply_filters( 'page_row_actions', self::core_trash( $this->ids['team'] ), get_post( $this->ids['team'] ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's own row actions filter.

		$this->assertSame( Tree_View::place_url( 'page', $this->ids['about'] ), self::trash_referer( $actions ), 'Core’s row prints the link with the tree’s place.' );
	}

	public function test_the_tree_s_load_keeps_undo_s_page(): void {
		$_GET['doaction']        = 'undo';
		$_SERVER['HTTP_REFERER'] = Tree_View::place_url( 'page' ) . '&paged=2&trashed=1&ids=9';
		$core                    = admin_url( 'edit.php?post_type=page&hierarchy_guard_view=tree&paged=1&untrashed=1' );

		$this->tree->load();

		$this->assertSame( str_replace( 'paged=1', 'paged=2', $core ), apply_filters( 'wp_redirect', $core, 302 ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's own redirect filter.
	}

	public function test_undo_from_the_tree_s_first_page_drops_core_s_page_one(): void {
		$_GET['doaction']        = 'undo';
		$_SERVER['HTTP_REFERER'] = Tree_View::place_url( 'page', $this->ids['about'] ) . '&trashed=1&ids=9';
		$core                    = admin_url( 'edit.php?post_type=page&hierarchy_guard_view=tree&hierarchy_guard_open=' . $this->ids['about'] . '&paged=1&untrashed=1' );

		$this->tree->keep_page_on_undo();
		$location = apply_filters( 'wp_redirect', $core, 302 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's own redirect filter.

		$this->assertSame( admin_url( 'edit.php?post_type=page&hierarchy_guard_view=tree&hierarchy_guard_open=' . $this->ids['about'] . '&untrashed=1' ), $location, 'The first page has no paged, as place_url() builds it.' );
	}

	public function test_a_trash_from_a_later_page_of_subpages_returns_to_that_page(): void {
		$this->subpages( $this->ids['about'], 58 );
		// About's subpages sort as History, Sub-001 … Sub-058, Team: Team is the 60th, on page 2.
		$_GET[ Tree_View::OPEN_VAR ]          = (string) $this->ids['about'];
		$_GET[ Tree_View::CHILDREN_PAGE_VAR ] = '2';
		$this->open_levels( [ $this->ids['company'] ], 1 );

		$referer = self::trash_referer( $this->tree->row_actions( self::core_trash( $this->ids['team'] ), get_post( $this->ids['team'] ) ) );

		$this->assertSame( 2, $this->tree->row( $this->ids['team'] )['page'] );
		$this->assertSame( Tree_View::place_url( 'page', $this->ids['about'], 2 ), $referer );
		$this->assertStringContainsString( Tree_View::CHILDREN_PAGE_VAR . '=2', (string) $referer );
	}

	public function test_a_trash_that_empties_a_later_page_of_subpages_returns_to_the_last_page_left(): void {
		$this->subpages( $this->ids['about'], 49 );
		// About's 51 subpages sort as History, Sub-001 … Sub-049, Team: Team is alone on page 2.
		$_GET[ Tree_View::OPEN_VAR ]          = (string) $this->ids['about'];
		$_GET[ Tree_View::CHILDREN_PAGE_VAR ] = '2';
		$this->assertCount( 2 + 1, $this->open_levels( [ $this->ids['company'] ], 1 ) );
		$referer = (string) self::trash_referer( $this->tree->row_actions( self::core_trash( $this->ids['team'] ), get_post( $this->ids['team'] ) ) );
		$this->assertStringContainsString( Tree_View::CHILDREN_PAGE_VAR . '=2', $referer );

		$this->trash_raw( $this->ids['team'] );
		// Core's redirect after the trash goes to the referer; the next request reads its arguments.
		parse_str( (string) wp_parse_url( $referer, PHP_URL_QUERY ), $back );
		$_GET = $back;
		$rows = $this->open_levels( [ $this->ids['company'] ], 1 );

		$this->assertCount( 2 + 50, $rows, 'Page 2 is gone: the 50 left are page 1, not an empty table.' );
		$this->assertSame( [ 'company', 'about', 'history' ], array_slice( wp_list_pluck( $rows, 'post_name' ), 0, 3 ) );
		$this->assertNotContains( $this->ids['team'], wp_list_pluck( $rows, 'ID' ) );
		$this->assertSame( 1, $this->tree->row( $this->ids['about'] )['shown'] );
		$this->assertFalse( $this->tree->row( $this->ids['about'] )['more'] );
	}

	public function test_the_controls_keep_the_view_and_leave_what_the_script_needs(): void {
		$asked = 0;
		add_action(
			'hierarchy_guard_list_script',
			static function () use ( &$asked ): void {
				++$asked;
			}
		);
		$GLOBALS['wp_query'] = $this->list_query();

		ob_start();
		$this->tree->controls( 'page', 'top' );
		$this->tree->collapse_all( 'top' );
		$html = (string) ob_get_clean();

		$this->assertSame( 1, $asked, 'The Tree view always loads the list-table script.' );
		$this->assertStringContainsString( '<input type="hidden" name="hierarchy_guard_view" value="tree">', $html, 'Search and the date filter stay in the tree.' );
		$this->assertStringContainsString( '<span class="hierarchy-guard-tree" hidden data-type="page" data-place="' . esc_url( Tree_View::place_url( 'page' ) ) . '"></span>', $html, 'The type and the place: all the script reads.' );
		$this->assertStringContainsString( '<a class="hierarchy-guard-collapse" href="' . esc_url( Tree_View::place_url( 'page' ) ) . '">Collapse all</a>', $html );

		ob_start();
		$this->tree->controls( 'page', 'bottom' );
		$this->tree->collapse_all( 'bottom' );
		$this->assertSame( '', (string) ob_get_clean(), 'Printed once, above the table.' );
	}

	public function test_a_search_offers_back_to_the_tree_and_no_collapse_all(): void {
		$_GET['s'] = 'team';

		ob_start();
		$this->tree->controls( 'page', 'top' );
		$this->tree->collapse_all( 'top' );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<input type="hidden" name="hierarchy_guard_view" value="tree">', $html, 'A search refined from the results stays in the tree.' );
		$this->assertStringContainsString( '<span class="hierarchy-guard-back"><a href="' . esc_url( Tree_View::url( 'page' ) ) . '">Back to the tree</a></span>', $html );
		$this->assertStringNotContainsString( 'hierarchy-guard-collapse', $html );
		$this->assertStringNotContainsString( 'class="hierarchy-guard-tree"', $html );
	}
}
