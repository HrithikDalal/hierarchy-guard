<?php
/**
 * The Redirects tab of Settings › Hierarchy Guard.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Admin\Redirect_Edit;
use HierarchyGuard\Admin\Redirects_Table;
use HierarchyGuard\Admin\Settings_Page;
use HierarchyGuard\Ledger;
use HierarchyGuard\Post_Types;
use HierarchyGuard\Redirects\Providers;
use HierarchyGuard\Redirects\Records;
use HierarchyGuard\Settings;
use WPDieException;
use WP_UnitTestCase;

/**
 * @covers \HierarchyGuard\Admin\Redirects_Table
 * @covers \HierarchyGuard\Admin\Redirect_Edit
 * @covers \HierarchyGuard\Admin\Settings_Page
 */
final class RedirectsTabTest extends WP_UnitTestCase {

	use Trash_Fixtures;

	private Ledger $ledger;

	private Records $records;

	private Settings_Page $page;

	/**
	 * Page IDs.
	 *
	 * @var array<string,int>
	 */
	private array $ids;

	public function set_up(): void {
		parent::set_up();
		require_once ABSPATH . 'wp-admin/includes/template.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		delete_option( Settings::OPTION );
		delete_option( Ledger::ROOT_OPTION );
		delete_option( Ledger::ROOT_TARGETS );
		delete_option( Ledger::RECENT );
		delete_option( Ledger::RECENT_STATE );
		delete_transient( 'settings_errors' );
		Post_Types::reset();
		$this->set_permalink_structure( '/%postname%/' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->ledger  = new Ledger();
		$this->records = new Records( $this->ledger );
		$this->page    = new Settings_Page( new Providers(), $this->records );
		$this->ids     = $this->make_tree();
		set_current_screen( 'settings_page_hierarchy-guard' );
		$_GET['tab'] = 'redirects';
	}

	public function tear_down(): void {
		foreach ( [ 'tab', 'action', 'action2', 'redirect', '_wpnonce', '_wp_http_referer', 's', 'redirect_type', 'paged', 'filter_action' ] as $key ) {
			unset( $_GET[ $key ], $_REQUEST[ $key ], $_POST[ $key ] );
		}
		$GLOBALS['wp_settings_errors'] = [];
		unregister_post_type( 'hg_docs' );
		unset( $GLOBALS['submenu']['options-general.php'] );
		set_current_screen( 'front' );
		$this->set_permalink_structure( '' );
		parent::tear_down();
	}

	/**
	 * The page as it prints.
	 *
	 * @return string
	 */
	private function rendered(): string {
		ob_start();
		$this->page->render();
		return (string) ob_get_clean();
	}

	/**
	 * Puts a link's query into the request, as a click on it would.
	 *
	 * @param string $url Link.
	 */
	private function follow( string $url ): void {
		wp_parse_str( (string) wp_parse_url( html_entity_decode( $url ), PHP_URL_QUERY ), $query );
		foreach ( $query as $key => $value ) {
			$_GET[ $key ]     = $value;
			$_REQUEST[ $key ] = $value;
		}
	}

	/**
	 * The notice an action kept for the next page load.
	 *
	 * @return array{message:string,type:string}
	 */
	private function kept_notice(): array {
		$kept = get_transient( 'settings_errors' );
		$this->assertIsArray( $kept );
		$last = end( $kept );
		return [
			'message' => (string) $last['message'],
			'type'    => (string) $last['type'],
		];
	}

	/**
	 * The ID of the record on a page for a path.
	 *
	 * @param int    $holder Holder.
	 * @param string $path   Old path.
	 * @return string
	 */
	private function recorded( int $holder, string $path ): string {
		$this->ledger->record( $holder, $path );
		return Records::id( $holder, 'page', $path );
	}

	public function test_the_tab_is_core_s_list_table_with_four_columns_and_nothing_to_add(): void {
		$this->recorded( $this->ids['about'], 'old-about' );

		$html = $this->rendered();

		$this->assertMatchesRegularExpression( '#<a href="[^"]*page=hierarchy-guard&\#038;tab=redirects" class="nav-tab nav-tab-active" aria-current="page">Redirects</a>#', $html );
		$this->assertStringContainsString( 'class="wp-list-table widefat fixed striped table-view-list redirects"', $html );
		foreach ( [ 'Old URL', 'Redirects to', 'Code', 'Status' ] as $column ) {
			$this->assertMatchesRegularExpression( '#<th scope="col" id=\'[a-z_]+\' class=\'manage-column column-[a-z_]+[^\']*\'\s*>' . $column . '</th>#', $html, $column );
		}
		$this->assertStringContainsString( '<td class=\'http_code column-http_code\'', $html, 'Not a cell of class "code": core’s CSS sets that in a monospace font.' );
		$this->assertStringContainsString( '<option value="delete">Delete</option>', $html );
		$this->assertMatchesRegularExpression( '#id="search-submit" class="button[^"]*" value="Search Redirects"#', $html );
		$this->assertStringContainsString( '<input type="hidden" name="page" value="hierarchy-guard"><input type="hidden" name="tab" value="redirects">', $html );
		$this->assertStringContainsString( 'name="_wpnonce" value="' . wp_create_nonce( Redirect_Edit::NONCE_BULK ) . '"', $html );
		$this->assertStringNotContainsString( 'page-title-action', $html, 'There is no Add button.' );
		$this->assertStringNotContainsString( 'options.php', $html, 'The Settings tab’s form is not on this tab.' );
	}

	public function test_each_column_reads_as_the_spec_has_it(): void {
		update_option( Settings::OPTION, [ 'serve_redirects' => true ] );
		$draft = $this->page( 'whats-on', 0, 'draft' );
		$this->recorded( $this->ids['about'], 'old-about' );
		$this->recorded( $draft, 'events' );
		$this->recorded( $this->ids['team'], 'careers' );
		$this->ledger->set_target( 'page', 'careers', $this->ids['team'], 'https://jobs.example.org/', 302 );
		$this->ledger->record_root( 'page', 'archive' );

		$html = $this->rendered();

		$this->assertStringContainsString( '<code>/old-about/</code> and the URLs under it', $html, 'In code, so it reads left to right in a right-to-left language too.' );
		$this->assertStringContainsString( '<code>/careers/</code><', $html, 'A URL target covers its exact old URL only.' );
		$this->assertStringContainsString( '<code>/archive/</code> and the URLs under it', $html );
		$this->assertStringContainsString( '<a href="' . esc_url( get_edit_post_link( $this->ids['about'] ) ) . '">About</a><br><span dir="ltr">/company/about/</span>', $html );
		$this->assertStringContainsString( '">Whats-on</a> &mdash; <span class=\'post-state\'>Draft</span><br><span dir="ltr">/?page_id=' . $draft . '</span>', $html );
		$this->assertStringContainsString( '<span dir="ltr">https://jobs.example.org/</span>', $html );
		$this->assertStringContainsString( '>Top level<', $html );
		$this->assertSame( 3, substr_count( $html, '>Automatic<' ) );
		$this->assertStringContainsString( '>302<', $html );
		$this->assertSame( 3, substr_count( $html, '>Active<' ) );
		$this->assertStringContainsString( '>Not active: the page it points to isn’t published<', $html );
		$this->assertStringContainsString( '<label class="screen-reader-text" for="cb-select-' . Records::id( $this->ids['about'], 'page', 'old-about' ) . '">Select the redirect from /old-about/</label>', $html );
		$this->assertStringContainsString( 'aria-label="Delete the redirect from /old-about/" data-hierarchy-guard-confirm="Delete the redirect from /old-about/? This can’t be undone."', $html );
		$this->assertStringContainsString( 'data-hierarchy-guard-confirm-one="Delete the redirect from /old-about/? This can’t be undone."', $html, 'A bulk Delete of one row asks about that row.' );
		$this->assertStringContainsString( '<form method="get" id="hierarchy-guard-redirects" data-hierarchy-guard-confirm="Delete the selected redirects? This can’t be undone.">', $html );
	}

	public function test_a_row_with_no_status_computed_never_reads_active(): void {
		$this->recorded( $this->ids['about'], 'old-about' );
		$table  = new Redirects_Table( $this->records );
		$column = new \ReflectionMethod( $table, 'column_status' );
		$column->setAccessible( true );
		$record = $this->records->find( Records::id( $this->ids['about'], 'page', 'old-about' ) );

		$this->assertSame( '', $column->invoke( $table, $record ), 'Statuses were never read for this row: the cell stays empty.' );
		$this->assertSame( '', Redirects_Table::status_label( 'unknown' ) );
		$this->assertSame( 'Active', Redirects_Table::status_label( Records::STATUS_ACTIVE ) );
	}

	public function test_the_post_type_filter_appears_only_when_two_types_are_supported(): void {
		$this->recorded( $this->ids['about'], 'old-about' );
		$this->assertStringNotContainsString( 'name="redirect_type"', $this->rendered() );

		register_post_type(
			'hg_docs',
			[
				'public'       => true,
				'hierarchical' => true,
				'label'        => 'Docs',
			]
		);
		Post_Types::reset();
		$html = $this->rendered();
		$this->assertStringContainsString( '<select name="redirect_type" id="hierarchy-guard-post-type">', $html );
		$this->assertStringContainsString( '<option value="">All post types</option>', $html );
		$this->assertStringContainsString( '<option value="hg_docs">Docs</option>', $html );

		$_GET['redirect_type']     = 'hg_docs';
		$_REQUEST['redirect_type'] = 'hg_docs';
		$html                      = $this->rendered();
		$this->assertStringContainsString( '<option value="hg_docs" selected=\'selected\'>Docs</option>', $html );
		$this->assertStringContainsString( 'No redirects found.', $html, 'Under a filter, not “No redirects have been recorded yet.”' );
	}

	public function test_no_argument_of_the_tab_is_one_wp_admin_reads_before_the_page_loads(): void {
		register_post_type(
			'hg_docs',
			[
				'public'       => true,
				'hierarchical' => true,
			]
		);
		Post_Types::reset();
		$id                        = $this->recorded( $this->ids['about'], 'old-about' );
		$_GET['redirect_type']     = 'page';
		$_REQUEST['redirect_type'] = 'page';
		$html                      = $this->rendered();
		$this->follow( Redirect_Edit::delete_url( $id ) );
		$after = ( new Redirect_Edit( $this->records ) )->handle();

		// wp-admin/admin.php sets $typenow from post_type and then cannot find a plugin page under Settings; it reads taxonomy too.
		preg_match_all( '#name="([^"]+)"#', $html, $names );
		$this->assertEmpty( array_intersect( [ 'post_type', 'taxonomy' ], $names[1] ) );
		$this->assertStringNotContainsString( 'post_type=', $html );
		$this->assertStringContainsString( 'redirect_type=page', $after );
		$this->assertStringNotContainsString( 'post_type=', $after );
	}

	public function test_rows_come_twenty_a_page(): void {
		for ( $i = 1; $i <= 21; $i++ ) {
			$this->recorded( $this->ids['about'], sprintf( 'old-%02d', $i ) );
		}

		$html = $this->rendered();
		$this->assertStringContainsString( '21 items', $html );
		$this->assertSame( 20, substr_count( $html, 'name="redirect[]"' ) );

		$_GET['paged']     = '2';
		$_REQUEST['paged'] = '2';
		$html              = $this->rendered();
		$this->assertSame( 1, substr_count( $html, 'name="redirect[]"' ) );
		$this->assertStringContainsString( '/old-21/', $html );
	}

	public function test_the_notices_above_the_table(): void {
		$this->recorded( $this->ids['about'], 'old-about' );

		$html = $this->rendered();
		$this->assertStringContainsString( 'Built-in redirects are off, so these redirects aren’t served. Turn them on in the <a href="' . esc_url( Settings_Page::redirects_url() ) . '">Settings tab</a>.', $html );
		$this->assertStringContainsString( 'notice notice-warning inline', $html );
		$this->assertStringNotContainsString( 'is active.', $html );

		update_option( Settings::OPTION, [ 'serve_redirects' => true ] );
		$provider = static function ( array $providers ): array {
			$providers['redirection']['active'] = true;
			return $providers;
		};
		add_filter( 'hierarchy_guard_redirect_providers', $provider );
		$html = $this->rendered();
		remove_filter( 'hierarchy_guard_redirect_providers', $provider );

		$this->assertStringNotContainsString( 'Built-in redirects are off', $html );
		$this->assertStringContainsString( 'Redirection is active. These are the redirects Hierarchy Guard recorded; its notices after each change offer to add them in Redirection.', $html );

		$cap = static fn(): int => 1;
		add_filter( 'hierarchy_guard_count_cap', $cap );
		$this->recorded( $this->ids['team'], 'old-team' );
		$html = $this->rendered();
		remove_filter( 'hierarchy_guard_count_cap', $cap );
		$this->assertStringContainsString( 'Only the newest 1 recorded redirect is listed. To find another, search for its full old URL.', $html );
		$this->assertStringContainsString( '<span class="displaying-num">1+ item</span>', $html, 'Past the cap, the count is a lower bound.' );
	}

	public function test_delete_from_a_row_deletes_the_record_and_says_its_old_url_no_longer_redirects_through_it(): void {
		$id = $this->recorded( $this->ids['about'], 'old-about' );
		$this->ledger->set_target( 'page', 'old-about', $this->ids['about'], '', 302 );
		$this->follow( Redirect_Edit::delete_url( $id ) );

		$url = ( new Redirect_Edit( $this->records ) )->handle();

		$this->assertSame( admin_url( 'options-general.php?page=hierarchy-guard&tab=redirects&settings-updated=true' ), $url );
		$this->assertSame( [], $this->ledger->paths_for( $this->ids['about'] ) );
		$this->assertFalse( metadata_exists( 'post', $this->ids['about'], Ledger::target_key( 'page', 'old-about' ) ) );
		$this->assertSame(
			[
				'message' => 'Redirect deleted. <code>/old-about/</code> no longer redirects through it.',
				'type'    => 'success',
			],
			$this->kept_notice()
		);
	}

	public function test_a_delete_without_its_own_nonce_deletes_nothing(): void {
		$id    = $this->recorded( $this->ids['about'], 'old-about' );
		$other = $this->recorded( $this->ids['team'], 'old-team' );
		$this->follow( Redirect_Edit::delete_url( $other ) );
		$_GET['redirect']     = $id;
		$_REQUEST['redirect'] = $id;

		try {
			( new Redirect_Edit( $this->records ) )->handle();
			$this->fail( 'Another record’s nonce must not delete this one.' );
		} catch ( WPDieException $refused ) {
			unset( $refused );
		}
		$this->assertSame( [ 'old-about' ], $this->ledger->paths_for( $this->ids['about'] ) );
	}

	public function test_bulk_delete_works_from_either_selector(): void {
		$ids = [ $this->recorded( $this->ids['about'], 'old-about' ), $this->recorded( $this->ids['team'], 'old-team' ) ];
		$this->recorded( $this->ids['history'], 'old-history' );
		$_REQUEST['action']   = '-1';
		$_REQUEST['action2']  = 'delete';
		$_REQUEST['redirect'] = $ids;
		$_REQUEST['_wpnonce'] = wp_create_nonce( Redirect_Edit::NONCE_BULK );

		$url = ( new Redirect_Edit( $this->records ) )->handle();

		$this->assertStringContainsString( 'settings-updated=true', $url );
		$this->assertSame( [], $this->ledger->paths_for( $this->ids['about'] ) );
		$this->assertSame( [], $this->ledger->paths_for( $this->ids['team'] ) );
		$this->assertSame( [ 'old-history' ], $this->ledger->paths_for( $this->ids['history'] ) );
		$this->assertSame( '2 redirects deleted. Their old URLs no longer redirect through them.', $this->kept_notice()['message'] );
	}

	public function test_a_bulk_delete_without_the_table_s_nonce_deletes_nothing(): void {
		$_REQUEST['action']   = 'delete';
		$_REQUEST['redirect'] = [ $this->recorded( $this->ids['about'], 'old-about' ) ];
		$_REQUEST['_wpnonce'] = 'nope';

		$this->expectException( WPDieException::class );
		try {
			( new Redirect_Edit( $this->records ) )->handle();
		} finally {
			$this->assertSame( [ 'old-about' ], $this->ledger->paths_for( $this->ids['about'] ) );
		}
	}

	public function test_without_manage_options_nothing_is_deleted(): void {
		$id = $this->recorded( $this->ids['about'], 'old-about' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->follow( Redirect_Edit::delete_url( $id ) );

		$this->expectException( WPDieException::class );
		try {
			( new Redirect_Edit( $this->records ) )->handle();
		} finally {
			$this->assertSame( [ 'old-about' ], $this->ledger->paths_for( $this->ids['about'] ) );
		}
	}

	public function test_a_link_to_a_record_deleted_since_says_so(): void {
		$id = $this->recorded( $this->ids['about'], 'old-about' );
		$this->ledger->forget( $this->ids['about'], 'old-about' );
		$this->follow( Redirect_Edit::delete_url( $id ) );

		( new Redirect_Edit( $this->records ) )->handle();

		$this->assertSame(
			[
				'message' => 'This redirect no longer exists.',
				'type'    => 'error',
			],
			$this->kept_notice()
		);
	}

	public function test_a_record_deleted_by_someone_else_after_it_was_found_says_it_no_longer_exists(): void {
		$id  = $this->recorded( $this->ids['about'], 'old-about' );
		$key = Ledger::key( 'page', 'old-about' );
		// Another tab deletes it between find() and delete(): the ledger finds no entry to forget.
		$gone = static fn( $value, int $object_id, string $meta_key ) => $key === $meta_key ? false : $value;
		add_filter( 'get_post_metadata', $gone, 10, 3 );
		$this->follow( Redirect_Edit::delete_url( $id ) );

		( new Redirect_Edit( $this->records ) )->handle();
		remove_filter( 'get_post_metadata', $gone, 10 );

		$this->assertSame(
			[
				'message' => 'This redirect no longer exists.',
				'type'    => 'error',
			],
			$this->kept_notice()
		);
	}

	public function test_a_search_drops_the_table_form_s_nonce_from_the_address_and_keeps_the_rest(): void {
		$_REQUEST['_wp_http_referer'] = '/wp-admin/options-general.php';
		$_REQUEST['_wpnonce']         = 'x';
		$_REQUEST['action']           = '-1';
		$_REQUEST['s']                = 'about us';

		$this->assertSame( admin_url( 'options-general.php?page=hierarchy-guard&tab=redirects&s=about%20us' ), ( new Redirect_Edit( $this->records ) )->handle() );

		unset( $_REQUEST['_wp_http_referer'] );
		$this->assertNull( ( new Redirect_Edit( $this->records ) )->handle(), 'A plain load prints the page.' );
	}

	public function test_the_actions_run_on_the_page_s_load_hook(): void {
		global $wp_filter;

		$this->page->add_page();

		// The suffix core gives the page: settings_page_hierarchy-guard once core's menu is built.
		$hook   = 'load-' . get_plugin_page_hookname( Settings_Page::SLUG, 'options-general.php' );
		$hooked = false;
		foreach ( $wp_filter[ $hook ]->callbacks[10] ?? [] as $callback ) {
			$hooked = $hooked || ( is_array( $callback['function'] ) && $this->page === $callback['function'][0] && 'load' === $callback['function'][1] );
		}
		$this->assertTrue( $hooked );
	}

	public function test_a_crafted_bulk_request_deletes_at_most_one_page_and_ignores_nested_values(): void {
		$ids = [];
		for ( $i = 1; $i <= 25; $i++ ) {
			$ids[] = $this->recorded( $this->ids['about'], 'bulk-' . $i );
		}
		$ids[]                = [ 'nested' => $ids[0] ];
		$_REQUEST['action']   = 'delete';
		$_REQUEST['redirect'] = $ids;
		$_REQUEST['_wpnonce'] = wp_create_nonce( Redirect_Edit::NONCE_BULK );

		( new Redirect_Edit( $this->records ) )->handle();

		$this->assertCount( 5, $this->ledger->paths_for( $this->ids['about'] ), 'Twenty deleted, one page of the table.' );
		$this->assertSame( '20 redirects deleted. Their old URLs no longer redirect through them.', $this->kept_notice()['message'] );
	}

	public function test_filter_never_runs_a_bulk_delete_and_an_empty_selection_returns_to_the_list(): void {
		$id                        = $this->recorded( $this->ids['about'], 'old-about' );
		$_REQUEST['action']        = 'delete';
		$_REQUEST['redirect']      = [ $id ];
		$_REQUEST['_wpnonce']      = wp_create_nonce( Redirect_Edit::NONCE_BULK );
		$_REQUEST['filter_action'] = 'Filter';

		$this->assertNull( ( new Redirect_Edit( $this->records ) )->handle(), 'Filter prints the list, as core’s tables do.' );
		$this->assertSame( [ 'old-about' ], $this->ledger->paths_for( $this->ids['about'] ) );

		unset( $_REQUEST['filter_action'], $_REQUEST['redirect'] );
		$_REQUEST['s'] = 'about';
		$this->assertSame( admin_url( 'options-general.php?page=hierarchy-guard&tab=redirects&s=about' ), ( new Redirect_Edit( $this->records ) )->handle(), 'Delete with nothing ticked, without JavaScript: back to the list.' );
		$this->assertSame( [ 'old-about' ], $this->ledger->paths_for( $this->ids['about'] ) );
		$this->assertFalse( get_transient( 'settings_errors' ) );
	}

	public function test_a_bulk_delete_of_records_all_gone_since_says_so_in_the_plural(): void {
		$ids = [ $this->recorded( $this->ids['about'], 'old-about' ), $this->recorded( $this->ids['team'], 'old-team' ) ];
		$this->ledger->forget( $this->ids['about'], 'old-about' );
		$this->ledger->forget( $this->ids['team'], 'old-team' );
		$_REQUEST['action']   = 'delete';
		$_REQUEST['redirect'] = $ids;
		$_REQUEST['_wpnonce'] = wp_create_nonce( Redirect_Edit::NONCE_BULK );

		( new Redirect_Edit( $this->records ) )->handle();

		$this->assertSame( 'These redirects no longer exist.', $this->kept_notice()['message'] );
	}

	public function test_a_percent_encoded_search_finds_the_old_url_it_names(): void {
		$this->recorded( $this->ids['about'], 'café/équipe' );
		$this->recorded( $this->ids['team'], 'other' );
		$_REQUEST['s'] = home_url( '/caf%C3%A9/%C3%A9quipe/' );

		$html = $this->rendered();

		$this->assertStringContainsString( '<code>/café/équipe/</code>', $html );
		$this->assertStringNotContainsString( '/other/', $html );
		$this->assertStringContainsString( '<span class="subtitle">Search results for: <strong><span dir="ltr">' . esc_html( home_url( '/café/équipe/' ) ) . '</span></strong></span>', $html, 'A URL searched reads left to right in a right-to-left admin too.' );
	}

	public function test_a_page_past_the_last_one_shows_the_last_one_with_its_rows(): void {
		for ( $i = 1; $i <= 21; $i++ ) {
			$this->recorded( $this->ids['about'], sprintf( 'old-%02d', $i ) );
		}
		$this->ledger->forget( $this->ids['about'], 'old-21' );
		$_GET['paged']     = '2';
		$_REQUEST['paged'] = '2';

		$html = $this->rendered();

		$this->assertSame( 20, substr_count( $html, 'name="redirect[]"' ) );
		$this->assertStringNotContainsString( 'No redirects have been recorded yet.', $html );
		$this->assertMatchesRegularExpression( "#id='current-page-selector' type='text'\\s+name='paged' value='1'#", $html, 'The pager agrees with the rows.' );
	}

	public function test_the_list_is_named_for_screen_readers_and_in_the_title(): void {
		$this->recorded( $this->ids['about'], 'old-about' );

		$this->page->load();
		$html = $this->rendered();

		$this->assertMatchesRegularExpression( '#<h2 class=[\'"]screen-reader-text[\'"]>Redirects list</h2>#', $html );
		$this->assertSame( 10, has_filter( 'admin_title', [ $this->page, 'admin_title' ] ) );
		$this->assertSame( 'Redirects ‹ Hierarchy Guard ‹ Site — WordPress', $this->page->admin_title( 'Hierarchy Guard ‹ Site — WordPress' ) );
		$this->assertStringContainsString( '<hr class="wp-header-end">', $html );
		remove_filter( 'admin_title', [ $this->page, 'admin_title' ] );
	}

	public function test_while_the_newest_list_is_built_the_tab_says_so(): void {
		$this->recorded( $this->ids['about'], 'old-about' );
		$this->assertStringNotContainsString( 'still being built', $this->rendered() );

		update_option( Ledger::RECENT_STATE, 'building' );
		$this->assertStringContainsString( '<div class="notice notice-info inline"><p>The list of recorded redirects is still being built. Older redirects appear here once it’s done.</p></div>', $this->rendered() );

		update_option( Ledger::RECENT_STATE, 'done' );
		$this->assertStringNotContainsString( 'still being built', $this->rendered() );
	}
}
