<?php
/**
 * Hierarchy column, subtree filter and "View subpages".
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Admin\List_Table;
use HierarchyGuard\Post_Types;
use HierarchyGuard\Settings;
use WP_Query;
use WP_UnitTestCase;

/**
 * @covers \HierarchyGuard\Admin\List_Table
 */
final class ListTableTest extends WP_UnitTestCase {

	use Trash_Fixtures;

	/**
	 * Page IDs.
	 *
	 * @var array<string,int>
	 */
	private array $ids;

	private List_Table $table;

	public function set_up(): void {
		parent::set_up();
		require_once ABSPATH . 'wp-admin/includes/template.php';
		delete_option( Settings::OPTION );
		Post_Types::reset();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->ids   = $this->make_tree();
		$this->table = new List_Table();
		set_current_screen( 'edit-page' );
		$GLOBALS['pagenow'] = 'edit.php';
	}

	public function tear_down(): void {
		unset( $_GET[ List_Table::QUERY_VAR ], $GLOBALS['pagenow'] );
		$GLOBALS['wp_query'] = new WP_Query();
		set_current_screen( 'front' );
		parent::tear_down();
	}

	/**
	 * The cell the column prints for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private function cell( int $post_id ): string {
		ob_start();
		$this->table->render_column( List_Table::COLUMN, $post_id );
		return (string) ob_get_clean();
	}

	/**
	 * Puts posts in the main query, the way the list screen holds them.
	 *
	 * @param int[]  $ids    Post IDs.
	 * @param string $fields Query fields, '' or 'id=>parent'.
	 */
	private function screen_rows( array $ids, string $fields = '' ): void {
		$query               = new WP_Query();
		$query->query        = [ 'fields' => $fields ];
		$query->posts        = array_map(
			static function ( int $id ) use ( $fields ) {
				$post = get_post( $id );
				return 'id=>parent' === $fields ? (object) [
					'ID'          => $id,
					'post_parent' => (int) $post->post_parent,
				] : $post;
			},
			$ids
		);
		$GLOBALS['wp_query'] = $query;
	}

	public function test_the_column_comes_after_the_title(): void {
		$this->assertSame(
			[ 'cb', 'title', List_Table::COLUMN, 'author', 'date' ],
			array_keys(
				$this->table->columns(
					[
						'cb'     => '',
						'title'  => 'Title',
						'author' => 'Author',
						'date'   => 'Date',
					]
				)
			)
		);
	}

	public function test_the_column_is_added_to_supported_types_on_admin_init(): void {
		global $wp_filter;
		$hooked = false;
		foreach ( $wp_filter['admin_init']->callbacks[10] ?? [] as $callback ) {
			$hooked = $hooked || ( is_array( $callback['function'] ) && $callback['function'][0] instanceof List_Table && 'hook_types' === $callback['function'][1] );
		}
		$this->assertTrue( $hooked, 'admin_init also runs for the Quick Edit save over Ajax.' );

		$this->table->hook_types();

		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's own column filters.
		$this->assertArrayHasKey( List_Table::COLUMN, apply_filters( 'manage_page_posts_columns', [ 'title' => 'Title' ] ) );
		$this->assertArrayNotHasKey( List_Table::COLUMN, apply_filters( 'manage_post_posts_columns', [ 'title' => 'Title' ] ) );
		// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
	}

	public function test_a_cell_shows_the_parent_and_the_subpage_count(): void {
		$cell = $this->cell( $this->ids['about'] );

		$this->assertStringContainsString( 'data-children="2"', $cell );
		$this->assertStringContainsString( '<span class="screen-reader-text">Parent: </span><a href="', $cell );
		$this->assertStringContainsString( '>Company</a><span aria-hidden="true"> · </span><a href="' . esc_url( List_Table::filter_url( 'page', $this->ids['about'] ) ) . '" aria-label="View the 2 subpages of “About”">2 subpages</a>', $cell );
	}

	public function test_a_cell_without_subpages_shows_only_the_parent(): void {
		$cell = $this->cell( $this->ids['leadership'] );

		$this->assertStringContainsString( 'data-children="0"', $cell );
		$this->assertStringContainsString( '>Team</a></span>', $cell );
	}

	public function test_a_top_level_page_without_subpages_shows_a_dash(): void {
		$cell = $this->cell( $this->page( 'lonely' ) );

		$this->assertStringContainsString( '<span aria-hidden="true">—</span>', $cell );
		$this->assertStringContainsString( 'Top level, no subpages', $cell );
	}

	public function test_a_parent_in_the_trash_is_marked(): void {
		$this->trash_raw( $this->ids['team'] );

		$cell = $this->cell( $this->ids['leadership'] );

		$this->assertStringContainsString( 'Parent: </span>Team (in the Trash)', $cell, 'Core refuses to edit a page in the Trash, so it is not linked.' );
		$this->assertStringNotContainsString( 'post=' . $this->ids['team'] . '&amp;action=edit', $cell );
	}

	public function test_counts_above_the_cap_show_a_plus(): void {
		add_filter( 'hierarchy_guard_count_cap', static fn(): int => 1 );

		$cell = $this->cell( $this->ids['about'] );

		$this->assertStringContainsString( '1+ subpage</a>', $cell );
		$this->assertStringContainsString( 'data-children="2"', $cell );
	}

	public function test_a_screen_of_rows_is_counted_in_one_query(): void {
		$this->screen_rows( [ $this->ids['company'], $this->ids['about'], $this->ids['team'], $this->ids['history'] ] );

		$queries = get_num_queries();
		foreach ( [ 'company', 'about', 'team', 'history' ] as $slug ) {
			$this->cell( $this->ids[ $slug ] );
		}

		$this->assertSame( 1, get_num_queries() - $queries );
	}

	public function test_core_s_tree_mode_counts_the_shown_rows_in_one_query(): void {
		$this->screen_rows( array_values( $this->ids ), 'id=>parent' );
		// Forces the parent priming to query: Company and Team are not in the cache.
		clean_post_cache( $this->ids['company'] );
		clean_post_cache( $this->ids['team'] );

		$queries = get_num_queries();
		$cells   = array_map( [ $this, 'cell' ], [ $this->ids['about'], $this->ids['team'] ] );

		// Two statements: the parents of the shown rows (one SELECT), then the live child counts of the shown rows (one UNION ALL).
		$this->assertSame( 2, get_num_queries() - $queries );
		$this->assertStringContainsString( 'data-children="2"', $cells[0] );
		$this->assertStringContainsString( 'data-children="1"', $cells[1] );
	}

	public function test_a_narrowed_tree_view_counts_the_children_outside_it(): void {
		// A published page whose only subpage is a draft: the published view holds no draft row.
		self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_status' => 'draft',
				'post_parent' => $this->ids['history'],
			]
		);
		$this->screen_rows( [ $this->ids['company'], $this->ids['about'], $this->ids['history'], $this->ids['team'], $this->ids['leadership'] ], 'id=>parent' );
		$asked = 0;
		add_action(
			'hierarchy_guard_list_script',
			static function () use ( &$asked ): void {
				++$asked;
			}
		);

		$cell = $this->cell( $this->ids['history'] );

		$this->assertStringContainsString( 'data-children="1"', $cell );
		$this->assertStringContainsString( '1 subpage</a>', $cell );
		$this->assertSame( 1, $asked, 'The script is asked, so the Trash link opens the dialog.' );
	}

	public function test_page_two_of_a_tree_counts_only_the_shown_rows_and_their_ancestors(): void {
		update_user_option( get_current_user_id(), 'edit_page_per_page', 2 );
		// Page 2 of the tree shows About (printed as the ancestor of History), then History and Team.
		$this->screen_rows( [ $this->ids['company'], $this->ids['about'], $this->ids['history'], $this->ids['team'], $this->ids['leadership'] ], 'id=>parent' );
		$query = $GLOBALS['wp_query'];
		$query->set( 'paged', 2 );

		$sql = [];
		$log = static function ( string $statement ) use ( &$sql ): string {
			$sql[] = $statement;
			return $statement;
		};
		add_filter( 'query', $log );
		$cells = [ $this->cell( $this->ids['about'] ), $this->cell( $this->ids['team'] ), $this->cell( $this->ids['history'] ) ];
		remove_filter( 'query', $log );

		$this->assertStringContainsString( 'data-children="2"', $cells[0] );
		$this->assertStringContainsString( 'data-children="1"', $cells[1] );
		$this->assertStringContainsString( 'data-children="0"', $cells[2] );

		$counting = implode( "\n", array_filter( $sql, static fn( string $s ): bool => false !== strpos( $s, 'post_status NOT IN' ) ) );
		$this->assertCount( 1, array_filter( $sql, static fn( string $s ): bool => false !== strpos( $s, 'post_status NOT IN' ) ), 'One statement counts the screen.' );
		$this->assertMatchesRegularExpression( '/post_parent = ' . $this->ids['team'] . '\b/', $counting );
		$this->assertDoesNotMatchRegularExpression( '/post_parent = ' . $this->ids['leadership'] . '\b/', $counting, 'Leadership is past the page.' );
	}

	public function test_a_trashed_page_whose_subpages_were_trashed_counts_zero(): void {
		foreach ( [ 'about', 'history', 'team', 'leadership' ] as $slug ) {
			$this->trash_raw( $this->ids[ $slug ] );
		}
		$this->screen_rows( [ $this->ids['about'], $this->ids['history'], $this->ids['team'], $this->ids['leadership'] ], 'id=>parent' );

		$cell = $this->cell( $this->ids['about'] );

		$this->assertStringContainsString( 'data-children="0"', $cell );
		$this->assertStringNotContainsString( 'View the 2 subpages', $cell );
	}

	public function test_rows_with_subpages_get_view_subpages(): void {
		$actions = $this->table->row_actions( [ 'edit' => 'Edit' ], get_post( $this->ids['about'] ) );

		$this->assertArrayHasKey( 'hierarchy_guard_subpages', $actions );
		$this->assertStringContainsString( 'hierarchy_guard_under=' . $this->ids['about'], $actions['hierarchy_guard_subpages'] );
		$this->assertSame( [ 'edit' => 'Edit' ], $this->table->row_actions( [ 'edit' => 'Edit' ], get_post( $this->ids['leadership'] ) ) );
	}

	public function test_the_filter_lists_the_subtree_in_paginated_rows(): void {
		$_GET[ List_Table::QUERY_VAR ] = (string) $this->ids['about'];

		$vars = $this->table->filter_request(
			[
				'post_type'              => 'page',
				'orderby'                => 'menu_order title',
				'order'                  => 'asc',
				'posts_per_page'         => -1,
				'posts_per_archive_page' => -1,
				'fields'                 => 'id=>parent',
			]
		);

		$this->assertSame( [ $this->ids['history'], $this->ids['team'], $this->ids['leadership'] ], $vars['post__in'] );
		$this->assertSame(
			[
				'menu_order' => 'ASC',
				'title'      => 'ASC',
			],
			$vars['orderby'],
			'Not core’s tree mode, which loads every page.'
		);
		$this->assertSame( 20, $vars['posts_per_page'] );
		$this->assertArrayNotHasKey( 'fields', $vars );
	}

	public function test_a_sorted_list_keeps_its_sort(): void {
		$_GET[ List_Table::QUERY_VAR ] = (string) $this->ids['about'];

		$vars = $this->table->filter_request(
			[
				'post_type'      => 'page',
				'orderby'        => 'date',
				'posts_per_page' => 20,
			]
		);

		$this->assertSame( 'date', $vars['orderby'] );
		$this->assertCount( 3, $vars['post__in'] );
	}

	public function test_a_page_without_subpages_filters_to_nothing(): void {
		$_GET[ List_Table::QUERY_VAR ] = (string) $this->ids['leadership'];

		$this->assertSame( [ 0 ], $this->table->filter_request( [ 'post_type' => 'page' ] )['post__in'] );
	}

	public function test_the_filter_ignores_other_screens_and_types(): void {
		$_GET[ List_Table::QUERY_VAR ] = (string) $this->ids['about'];
		$vars                          = [ 'post_type' => 'page' ];

		$GLOBALS['pagenow'] = 'upload.php';
		$this->assertSame( $vars, $this->table->filter_request( $vars ) );

		$GLOBALS['pagenow'] = 'edit.php';
		$this->assertSame( [ 'post_type' => 'post' ], $this->table->filter_request( [ 'post_type' => 'post' ] ) );

		$_GET[ List_Table::QUERY_VAR ] = (string) self::factory()->post->create();
		$this->assertSame( $vars, $this->table->filter_request( $vars ), 'The filtered ID is not a page.' );
	}

	public function test_an_array_post_type_is_left_alone_without_a_warning(): void {
		$_GET[ List_Table::QUERY_VAR ] = (string) $this->ids['about'];
		$vars                          = [ 'post_type' => [ 'post', 'page' ] ];

		$this->assertSame( $vars, $this->table->filter_request( $vars ) );
	}

	public function test_the_filter_stops_at_the_cap_and_says_so(): void {
		add_filter( 'hierarchy_guard_count_cap', static fn(): int => 2 );
		$_GET[ List_Table::QUERY_VAR ] = (string) $this->ids['about'];

		$this->assertCount( 2, $this->table->filter_request( [ 'post_type' => 'page' ] )['post__in'] );

		ob_start();
		$this->table->filter_controls( 'page', 'top' );
		$this->assertStringContainsString( '(the first 2)', (string) ob_get_clean() );
	}

	public function test_the_filter_controls_keep_the_filter_and_show_the_ancestors(): void {
		$_GET[ List_Table::QUERY_VAR ] = (string) $this->ids['team'];

		ob_start();
		$this->table->filter_controls( 'page', 'top' );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<input type="hidden" name="hierarchy_guard_under" value="' . $this->ids['team'] . '">', $html );
		$this->assertStringContainsString( 'Subpages of <a href="' . esc_url( List_Table::filter_url( 'page', $this->ids['company'] ) ) . '">Company</a><span aria-hidden="true"> › </span><a href="' . esc_url( List_Table::filter_url( 'page', $this->ids['about'] ) ) . '">About</a><span aria-hidden="true"> › </span><strong>Team</strong>', $html );
		$this->assertStringContainsString( '>Show all pages</a>', $html );

		ob_start();
		$this->table->filter_controls( 'page', 'bottom' );
		$this->assertSame( '', (string) ob_get_clean(), 'Printed once, above the table.' );
	}

	public function test_core_s_list_query_returns_the_subtree_in_flat_rows(): void {
		require_once ABSPATH . 'wp-admin/includes/post.php';
		$_GET = [
			'post_type'           => 'page',
			List_Table::QUERY_VAR => (string) $this->ids['about'],
		];

		wp_edit_posts_query( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The list screen's own request, built above.

		$this->assertEqualSets( [ $this->ids['history'], $this->ids['team'], $this->ids['leadership'] ], wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' ) );
		$this->assertNotSame( 'menu_order title', $GLOBALS['wp_query']->query['orderby'] );
		$_GET = [];
	}

	public function test_a_flat_screen_loads_the_rows_parents_in_one_query(): void {
		$this->screen_rows( [ $this->ids['history'], $this->ids['leadership'] ] );
		// Their parents, About and Team, are not rows on this screen.
		clean_post_cache( $this->ids['about'] );
		clean_post_cache( $this->ids['team'] );

		$queries = get_num_queries();
		$this->cell( $this->ids['history'] );
		$this->cell( $this->ids['leadership'] );

		$this->assertSame( 2, get_num_queries() - $queries, 'The counts, then every parent the column prints, not one query per parent.' );
	}

	public function test_page_two_of_a_search_counts_the_rows_it_shows(): void {
		update_user_option( get_current_user_id(), 'edit_page_per_page', 2 );
		$query        = new WP_Query();
		$query->query = [
			'fields' => 'id=>parent',
			's'      => 'a',
		];
		$query->set( 'paged', 2 );
		$query->posts        = array_map(
			fn( string $slug ) => (object) [
				'ID'          => $this->ids[ $slug ],
				'post_parent' => (int) get_post_field( 'post_parent', $this->ids[ $slug ] ),
			],
			[ 'company', 'history', 'about', 'team' ]
		);
		$GLOBALS['wp_query'] = $query;

		$queries = get_num_queries();
		$cells   = [ $this->cell( $this->ids['about'] ), $this->cell( $this->ids['team'] ) ];

		$this->assertLessThanOrEqual( 2, get_num_queries() - $queries );
		$this->assertStringContainsString( 'data-children="2"', $cells[0] );
		$this->assertStringContainsString( 'data-children="1"', $cells[1] );
	}

	public function test_a_page_the_user_cannot_read_is_not_a_filter(): void {
		$private = $this->ids['team'];
		add_filter(
			'map_meta_cap',
			static function ( array $caps, string $cap, int $user_id, array $args ) use ( $private ): array {
				return 'read_post' === $cap && isset( $args[0] ) && $private === (int) $args[0] ? [ 'do_not_allow' ] : $caps;
			},
			10,
			4
		);
		$_GET[ List_Table::QUERY_VAR ] = (string) $private;

		$this->assertSame( [ 'post_type' => 'page' ], $this->table->filter_request( [ 'post_type' => 'page' ] ) );
		ob_start();
		$this->table->filter_controls( 'page', 'top' );
		$this->assertSame( '', (string) ob_get_clean() );
	}

	public function test_the_list_script_is_asked_for_once_when_a_row_has_subpages(): void {
		$asked = 0;
		add_action(
			'hierarchy_guard_list_script',
			static function () use ( &$asked ): void {
				++$asked;
			}
		);

		$this->cell( $this->ids['leadership'] );
		$this->assertSame( 0, $asked, 'No subpages, nothing for the script to do.' );

		$this->cell( $this->ids['about'] );
		$this->cell( $this->ids['team'] );
		$this->assertSame( 1, $asked );
	}
}
