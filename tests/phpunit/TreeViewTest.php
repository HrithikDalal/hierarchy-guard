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
		foreach ( [ 'request', 'load-edit.php' ] as $hook ) {
			foreach ( $wp_filter[ $hook ]->callbacks[10] ?? [] as $callback ) {
				if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof Tree_View ) {
					$found[] = $hook;
				}
			}
		}

		$this->assertSame( [ 'request', 'load-edit.php' ], $found );
	}
}
