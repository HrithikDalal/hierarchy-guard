<?php
/**
 * The Edit view of a recorded redirect.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Admin\Redirect_Edit;
use HierarchyGuard\Admin\Settings_Page;
use HierarchyGuard\Ledger;
use HierarchyGuard\Post_Types;
use HierarchyGuard\Redirects\Providers;
use HierarchyGuard\Redirects\Records;
use HierarchyGuard\Settings;
use WPDieException;
use WP_UnitTestCase;

/**
 * @covers \HierarchyGuard\Admin\Redirect_Edit
 */
final class RedirectEditTest extends WP_UnitTestCase {

	use Trash_Fixtures;

	private Ledger $ledger;

	private Records $records;

	private Redirect_Edit $edit;

	/**
	 * Page IDs.
	 *
	 * @var array<string,int>
	 */
	private array $ids;

	private string $id;

	public function set_up(): void {
		parent::set_up();
		require_once ABSPATH . 'wp-admin/includes/template.php';
		delete_option( Settings::OPTION );
		delete_option( Ledger::ROOT_OPTION );
		delete_option( Ledger::ROOT_TARGETS );
		delete_option( Ledger::RECENT );
		delete_transient( 'settings_errors' );
		Post_Types::reset();
		$this->set_permalink_structure( '/%postname%/' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->ledger  = new Ledger();
		$this->records = new Records( $this->ledger );
		$this->edit    = new Redirect_Edit( $this->records );
		$this->ids     = $this->make_tree();
		$this->ledger->record( $this->ids['about'], 'old-about' );
		$this->id    = Records::id( $this->ids['about'], 'page', 'old-about' );
		$_GET['tab'] = 'redirects';
	}

	public function tear_down(): void {
		foreach ( [ 'tab', 'action', 'redirect', '_wpnonce', 'target', 'code' ] as $key ) {
			unset( $_GET[ $key ], $_POST[ $key ], $_REQUEST[ $key ] );
		}
		$GLOBALS['wp_settings_errors'] = [];
		$this->set_permalink_structure( '' );
		parent::tear_down();
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
	 * Posts the Edit form, as Save does.
	 *
	 * @param string $url   Redirect to.
	 * @param string $code  Code radio value.
	 * @param string $nonce Nonce, or '' for the form's own.
	 */
	private function submit( string $url, string $code, string $nonce = '' ): void {
		$fields = [
			'action'   => 'save',
			'redirect' => $this->id,
			'_wpnonce' => '' !== $nonce ? $nonce : wp_create_nonce( Redirect_Edit::NONCE_SAVE . $this->id ),
			'target'   => $url,
			'code'     => $code,
		];
		foreach ( $fields as $key => $value ) {
			$_POST[ $key ]    = wp_slash( $value );
			$_REQUEST[ $key ] = wp_slash( $value );
		}
	}

	/**
	 * The Edit view as it prints, or '' when it does not.
	 *
	 * @return string
	 */
	private function view(): string {
		ob_start();
		$this->edit->render();
		return (string) ob_get_clean();
	}

	public function test_the_edit_link_opens_the_view_behind_its_nonce(): void {
		$this->assertStringContainsString( 'options-general.php?page=hierarchy-guard&amp;tab=redirects&amp;action=edit&amp;redirect=' . $this->id . '&amp;_wpnonce=', Redirect_Edit::edit_url( $this->id ) );
		$this->follow( Redirect_Edit::edit_url( $this->id ) );

		$this->assertNull( $this->edit->handle(), 'The view prints on this load.' );
		$html = $this->view();

		$this->assertStringContainsString( '<h2>Edit redirect</h2>', $html );
		$this->assertStringContainsString( '<form method="post" action="' . esc_url( Settings_Page::url( 'redirects' ) ) . '">', $html );
		$this->assertStringContainsString( '<tr><th scope="row">Old URL</th><td><code>/old-about/</code> and the URLs under it</td></tr>', $html );
		$this->assertStringContainsString( '<td><input type="text" class="regular-text code" id="hierarchy-guard-target" name="target" value="' . esc_attr( get_permalink( $this->ids['about'] ) ) . '" dir="ltr" aria-describedby="hierarchy-guard-target-description">', $html, 'A text field, so a path is not stopped by the browser.' );
		$this->assertStringContainsString( '<p class="description" id="hierarchy-guard-target-description">A page on this site: the redirect follows it when it moves, and the URLs under <code>/old-about/</code> go to the matching URLs under it. Any other URL: only <code>/old-about/</code> redirects, and this redirect no longer covers the URLs under it. A path on this site, such as <code>/about/team/</code>, works as well as a full URL.</p>', $html );
		$this->assertStringContainsString( 'the URLs under <code>/old-about/</code> go to', $html, 'The old URL prints in <code> in the help, so it reads left to right in a right-to-left admin.' );
		$this->assertStringNotContainsString( 'Leave empty to keep this.', $html );
		$this->assertMatchesRegularExpression( '#<input type="radio" name="code" value="0" aria-describedby="hierarchy-guard-code-automatic-description"\s+checked=\'checked\'> Automatic</label><p class="description" id="hierarchy-guard-code-automatic-description">301, or 302 while restoring a page from the Trash could undo the move.</p>#', $html );
		$this->assertStringContainsString( '301 (permanent)', $html );
		$this->assertStringContainsString( '302 (temporary)', $html );
		$this->assertStringContainsString( 'name="_wpnonce" value="' . wp_create_nonce( Redirect_Edit::NONCE_SAVE . $this->id ) . '"', $html );
		$this->assertMatchesRegularExpression( '#<input type="submit" name="submit" id="submit" class="button button-primary" value="Save"\s*/> <a class="button" href="[^"]+">Cancel</a> <span id="delete-link"><a class="delete" href="[^"]*action=delete[^"]*" data-hierarchy-guard-confirm="Delete the redirect from /old-about/\? This can’t be undone.">Delete</a></span>#', $html, 'Core’s red delete link, which asks first.' );
	}

	public function test_the_view_of_a_url_target_offers_its_url_and_its_code(): void {
		$this->ledger->set_target( 'page', 'old-about', $this->ids['about'], 'https://jobs.example.org/', 302 );
		$this->follow( Redirect_Edit::edit_url( $this->id ) );

		$html = $this->view();

		$this->assertStringContainsString( '<td><code>/old-about/</code></td>', $html, 'A URL target covers the exact old URL only.' );
		$this->assertStringContainsString( 'value="https://jobs.example.org/"', $html );
		$this->assertMatchesRegularExpression( '#value="302"\s+checked=\'checked\'#', $html );
	}

	public function test_the_view_without_its_nonce_or_without_manage_options_is_refused(): void {
		$this->follow( Redirect_Edit::edit_url( $this->id ) );
		$_REQUEST['_wpnonce'] = wp_create_nonce( Redirect_Edit::NONCE_EDIT . Records::id( 0, 'page', 'old-about' ) );
		try {
			$this->edit->handle();
			$this->fail( 'Another record’s nonce must not open this one.' );
		} catch ( WPDieException $refused ) {
			unset( $refused );
		}

		// An editor with a nonce of their own for this record: only the capability check can refuse it.
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->follow( Redirect_Edit::edit_url( $this->id ) );
		$this->assertSame( 1, wp_verify_nonce( sanitize_key( wp_unslash( $_REQUEST['_wpnonce'] ?? '' ) ), Redirect_Edit::NONCE_EDIT . $this->id ), 'Precondition: the nonce is good.' );
		try {
			$this->edit->handle();
			$this->fail( 'An editor cannot open the Edit view.' );
		} catch ( WPDieException $refused ) {
			$this->assertSame( 'Sorry, you are not allowed to change redirects.', $refused->getMessage() );
		}
	}

	public function test_the_edit_link_of_a_record_deleted_since_says_it_no_longer_exists(): void {
		$this->follow( Redirect_Edit::edit_url( $this->id ) );
		$this->ledger->forget( $this->ids['about'], 'old-about' );

		$this->assertSame( admin_url( 'options-general.php?page=hierarchy-guard&tab=redirects&settings-updated=true' ), $this->edit->handle() );

		$kept = get_transient( 'settings_errors' );
		$this->assertSame( 'This redirect no longer exists.', end( $kept )['message'] );
		$this->assertSame( 'error', end( $kept )['type'] );
		$this->assertSame( '', $this->view(), 'No Edit view prints.' );
	}

	public function test_a_refused_save_through_the_page_prints_the_form_again_with_why(): void {
		set_current_screen( 'settings_page_hierarchy-guard' );
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$page = new Settings_Page( new Providers(), $this->records );
		$this->submit( 'ftp://example.org/', '301' );

		$page->load();
		ob_start();
		$page->render();
		$html = (string) ob_get_clean();
		set_current_screen( 'front' );

		$this->assertSame( [ 'Enter a URL that starts with http:// or https://, or a path on this site that starts with /.' ], array_column( get_settings_errors( 'hierarchy_guard' ), 'message' ) );
		$this->assertStringContainsString( '<h2>Edit redirect</h2>', $html );
		$this->assertStringContainsString( 'value="ftp://example.org/"', $html );
		$this->assertMatchesRegularExpression( '#value="301"\s+checked=\'checked\'#', $html );
		$this->assertStringNotContainsString( 'wp-list-table', $html );
		$this->assertNull( $this->ledger->target( 'page', 'old-about', $this->ids['about'] ) );
	}

	public function test_a_code_sent_as_an_array_is_read_as_automatic(): void {
		$this->submit( 'https://example.org/', '302' );
		$_POST['code']    = [ '302' ];
		$_REQUEST['code'] = [ '302' ];

		$this->edit->handle();

		$this->assertSame(
			[
				'url'  => 'https://example.org/',
				'code' => 0,
			],
			$this->ledger->target( 'page', 'old-about', $this->ids['about'] )
		);
	}

	public function test_saving_a_page_returns_to_the_tab_with_redirect_updated(): void {
		$this->submit( get_permalink( $this->ids['history'] ), '302' );

		$this->assertSame( admin_url( 'options-general.php?page=hierarchy-guard&tab=redirects&settings-updated=true' ), $this->edit->handle() );

		$this->assertSame( [], $this->ledger->paths_for( $this->ids['about'] ) );
		$this->assertSame( [ 'old-about' ], $this->ledger->paths_for( $this->ids['history'] ) );
		$this->assertSame( 302, $this->ledger->target( 'page', 'old-about', $this->ids['history'] )['code'] );
		$kept = get_transient( 'settings_errors' );
		$this->assertSame( 'Redirect updated.', end( $kept )['message'] );
		$this->assertSame( 'success', end( $kept )['type'] );
	}

	public function test_saving_any_other_url_makes_a_url_target(): void {
		$this->submit( 'https://jobs.example.org/open?team=web%20design', '0' );

		$this->assertNotNull( $this->edit->handle() );

		$this->assertSame(
			[
				'url'  => 'https://jobs.example.org/open?team=web%20design',
				'code' => 0,
			],
			$this->ledger->target( 'page', 'old-about', $this->ids['about'] ),
			'Percent-encoding survives the save.'
		);
	}

	public function test_a_url_that_is_not_http_prints_the_form_again_with_why(): void {
		$this->submit( 'javascript:alert(1)', '302' );

		$this->assertNull( $this->edit->handle() );

		$this->assertSame( [ 'Enter a URL that starts with http:// or https://, or a path on this site that starts with /.' ], array_column( get_settings_errors( 'hierarchy_guard' ), 'message' ) );
		$this->assertNull( $this->ledger->target( 'page', 'old-about', $this->ids['about'] ), 'Nothing is saved.' );
		$html = $this->view();
		$this->assertStringContainsString( '<h2>Edit redirect</h2>', $html );
		$this->assertStringContainsString( 'value="javascript:alert(1)"', $html, 'What was entered, to correct.' );
		$this->assertMatchesRegularExpression( '#value="302"\s+checked=\'checked\'#', $html );
	}

	public function test_a_save_without_its_nonce_or_without_manage_options_saves_nothing(): void {
		$this->submit( 'https://example.org/', '302', wp_create_nonce( Redirect_Edit::NONCE_SAVE . Records::id( 0, 'page', 'x' ) ) );
		try {
			$this->edit->handle();
			$this->fail( 'Another record’s nonce must not save this one.' );
		} catch ( WPDieException $refused ) {
			unset( $refused );
		}
		$this->assertNull( $this->ledger->target( 'page', 'old-about', $this->ids['about'] ) );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->submit( 'https://example.org/', '302' );
		try {
			$this->edit->handle();
			$this->fail( 'An editor cannot point a redirect off the site.' );
		} catch ( WPDieException $refused ) {
			unset( $refused );
		}
		$this->assertNull( $this->ledger->target( 'page', 'old-about', $this->ids['about'] ) );
	}

	public function test_the_table_offers_edit_and_the_tab_prints_the_view_instead_of_the_table(): void {
		set_current_screen( 'settings_page_hierarchy-guard' );
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$page = new Settings_Page( new Providers(), $this->records );

		ob_start();
		$page->render();
		$list = (string) ob_get_clean();
		$this->assertStringContainsString( '<a href="' . esc_url( Redirect_Edit::edit_url( $this->id ) ) . '" aria-label="Edit the redirect from /old-about/">Edit</a>', $list );

		$this->follow( Redirect_Edit::edit_url( $this->id ) );
		ob_start();
		$page->render();
		$edit = (string) ob_get_clean();
		set_current_screen( 'front' );

		$this->assertStringContainsString( '<h2>Edit redirect</h2>', $edit );
		$this->assertStringNotContainsString( 'wp-list-table', $edit );
		$this->assertStringContainsString( 'nav-tab nav-tab-active" aria-current="page">Redirects</a>', $edit );
	}

	public function test_a_record_deleted_between_edit_and_save_is_not_written_back(): void {
		$this->submit( 'https://example.org/', '302' );
		$this->ledger->forget( $this->ids['about'], 'old-about' );

		$this->assertSame( admin_url( 'options-general.php?page=hierarchy-guard&tab=redirects&settings-updated=true' ), $this->edit->handle() );

		$this->assertSame( [], $this->ledger->paths_for( $this->ids['about'] ) );
		$this->assertFalse( metadata_exists( 'post', $this->ids['about'], Ledger::target_key( 'page', 'old-about' ) ), 'No override without its record.' );
		$kept = get_transient( 'settings_errors' );
		$this->assertSame( 'This redirect no longer exists.', end( $kept )['message'] );
	}

	public function test_a_top_level_entry_s_view_says_where_it_leads_and_keeps_it_when_left_empty(): void {
		$this->ledger->record_root( 'page', 'archive' );
		$this->id = Records::id( 0, 'page', 'archive' );
		$this->follow( Redirect_Edit::edit_url( $this->id ) );

		$html = $this->view();

		$this->assertStringContainsString( '<td><p>Now redirects to the same page at the top level, for example <code>/about/team/</code> to <code>/team/</code>.</p><input type="text" class="regular-text code" id="hierarchy-guard-target" name="target" value=""', $html, 'The field starts empty.' );
		$this->assertStringContainsString( 'id="hierarchy-guard-target-description">Leave empty to keep this. Enter a URL to send these URLs somewhere else. A page on this site:', $html );

		$this->submit( '', '302' );
		$this->assertStringContainsString( 'settings-updated=true', $this->edit->handle() );
		$this->assertSame( [ 'page' => [ 'archive' ] ], $this->ledger->root_paths(), 'Still top level.' );
		$this->assertSame( 302, $this->ledger->target( 'page', 'archive', 0 )['code'] );
	}

	public function test_a_path_entered_is_saved_as_the_url_on_this_site(): void {
		$this->submit( '/company/about/history/', '0' );

		$this->assertStringContainsString( 'settings-updated=true', $this->edit->handle() );

		$this->assertSame( [ 'old-about' ], $this->ledger->paths_for( $this->ids['history'] ), 'The path of a page moves the record onto it.' );
	}

	public function test_the_edit_view_is_named_in_the_browser_title(): void {
		set_current_screen( 'settings_page_hierarchy-guard' );
		$page = new Settings_Page( new Providers(), $this->records );
		$this->follow( Redirect_Edit::edit_url( $this->id ) );

		$page->load();

		$this->assertSame( 'Edit redirect ‹ Hierarchy Guard ‹ Site — WordPress', $page->admin_title( 'Hierarchy Guard ‹ Site — WordPress' ) );
		remove_filter( 'admin_title', [ $page, 'admin_title' ] );
		set_current_screen( 'front' );
	}
}
