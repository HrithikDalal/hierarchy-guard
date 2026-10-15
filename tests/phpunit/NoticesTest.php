<?php
/**
 * The outcome notice, background progress and the redirects nudge.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Admin\Notices;
use HierarchyGuard\Batch\Queue;
use HierarchyGuard\Redirects\Pairs;
use HierarchyGuard\Redirects\Providers;
use HierarchyGuard\Post_Types;
use HierarchyGuard\Settings;
use HierarchyGuard\Trash\Change_Log;
use WP_UnitTestCase;

/**
 * @covers \HierarchyGuard\Admin\Notices
 */
final class NoticesTest extends WP_UnitTestCase {

	use Trash_Fixtures;

	/**
	 * Page IDs.
	 *
	 * @var array<string,int>
	 */
	private array $ids;

	private int $admin;

	private Change_Log $log;

	private Notices $notice;

	public function set_up(): void {
		parent::set_up();
		delete_option( Settings::OPTION );
		Post_Types::reset();
		Change_Log::reset_request();
		$this->admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $this->admin );
		$this->log          = new Change_Log();
		$this->notice       = new Notices( $this->log, new Queue(), new Pairs(), new Providers() );
		$this->ids          = $this->make_tree();
		$GLOBALS['typenow'] = 'page';
	}

	public function tear_down(): void {
		unset( $GLOBALS['typenow'], $_REQUEST['ids'], $GLOBALS['post'] );
		$this->set_permalink_structure( '' );
		set_current_screen( 'front' );
		parent::tear_down();
	}

	/**
	 * Trashes about through core with pretty permalinks, so its subpages move up
	 * and their old URLs have pairs, and returns the trash notice core would show.
	 *
	 * @return string The notice text after core's sprintf().
	 */
	private function trash_about(): string {
		$this->set_permalink_structure( '/%postname%/' );
		wp_trash_post( $this->ids['about'] );
		$_REQUEST['ids'] = (string) $this->ids['about'];
		return sprintf( $this->notice->append( $this->core_messages(), [ 'trashed' => 1 ] )['page']['trashed'], '1' );
	}

	/**
	 * The URLs notice for the admin's last change.
	 *
	 * @return array{type:string,message:string}|null
	 */
	private function urls_notice(): ?array {
		return $this->notice->urls_notice( $this->log->latest( $this->admin ) );
	}

	/**
	 * What render() prints on the Pages list.
	 *
	 * @return string
	 */
	private function rendered(): string {
		set_current_screen( 'edit-page' );
		ob_start();
		$this->notice->render();
		return (string) ob_get_clean();
	}

	/**
	 * Renames about as the admin with pretty permalinks, as the classic editor
	 * saves it, and returns the change the save recorded.
	 *
	 * @return array<string,mixed>
	 */
	private function save_about(): array {
		$this->set_permalink_structure( '/%postname%/' );
		$this->as_classic_save(
			fn() => wp_update_post(
				[
					'ID'        => $this->ids['about'],
					'post_name' => 'about-us',
				]
			)
		);
		return $this->log->latest( $this->admin );
	}

	/**
	 * What render() prints on the edit screen of a post.
	 *
	 * @param int  $post_id Post being edited.
	 * @param bool $blocks  Whether the screen is the block editor's.
	 * @return string
	 */
	private function rendered_on_edit( int $post_id, bool $blocks = false ): string {
		set_current_screen( 'page' );
		get_current_screen()->is_block_editor( $blocks );
		$GLOBALS['post'] = get_post( $post_id );
		ob_start();
		$this->notice->render();
		return (string) ob_get_clean();
	}

	/**
	 * Records an old URL, so the ledger is not empty.
	 */
	private function record_an_old_url(): void {
		$this->set_permalink_structure( '/%postname%/' );
		wp_update_post(
			[
				'ID'        => $this->ids['history'],
				'post_name' => 'our-history',
			]
		);
	}

	/**
	 * Makes a redirect plugin look active.
	 */
	private function with_provider(): void {
		add_filter(
			'hierarchy_guard_redirect_providers',
			static fn(): array => [
				'redirection' => [
					'name'   => 'Redirection',
					'active' => true,
					'url'    => admin_url( 'tools.php?page=redirection.php' ),
					'csv'    => 'redirection',
				],
			]
		);
	}

	/**
	 * Core's page messages, as edit.php builds them.
	 *
	 * @return array<string,array<string,string>>
	 */
	private function core_messages(): array {
		return [
			'post' => [
				'trashed'   => '%s post moved to the Trash.',
				'untrashed' => '%s post restored from the Trash.',
			],
			'page' => [
				'trashed'   => '%s page moved to the Trash.',
				'untrashed' => '%s page restored from the Trash.',
			],
		];
	}

	/**
	 * A change with one or more roots.
	 *
	 * @param string                           $action  trash or restore.
	 * @param array<int,array<string,mixed>>   $entries Root entries.
	 * @return array<string,mixed>
	 */
	private function change( string $action, array $entries ): array {
		foreach ( $entries as $entry ) {
			$this->log->record( $this->admin, $action, 'page', $entry );
		}
		return $this->log->latest( $this->admin );
	}

	/**
	 * A root entry for about.
	 *
	 * @param array<string,mixed> $fields Fields to set.
	 * @return array<string,mixed>
	 */
	private function about( array $fields ): array {
		return array_merge(
			[
				'root'   => $this->ids['about'],
				'to'     => $this->ids['company'],
				'policy' => Settings::POLICY_REPARENT,
			],
			$fields
		);
	}

	public function test_the_filter_is_registered_by_the_plugin(): void {
		global $wp_filter;
		$found = false;
		foreach ( $wp_filter['bulk_post_updated_messages']->callbacks[10] ?? [] as $callback ) {
			if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof Notices ) {
				$found = true;
			}
		}
		$this->assertTrue( $found );
	}

	public function test_core_s_trash_notice_gains_one_sentence_once(): void {
		$this->change( 'trash', [ $this->about( [ 'counts' => [ 'moved' => 2 ] ] ) ] );
		$_REQUEST['ids'] = (string) $this->ids['about'];

		$messages = $this->notice->append( $this->core_messages(), [ 'trashed' => 1 ] );

		$this->assertSame( '“About” moved to the Trash. 2 subpages moved up under “Company”.', sprintf( $messages['page']['trashed'], '1' ) );
		$this->assertSame( $this->core_messages(), $this->notice->append( $this->core_messages(), [ 'trashed' => 1 ] ), 'Shown once.' );
	}

	public function test_a_percent_sign_in_a_title_survives_core_s_sprintf(): void {
		wp_update_post(
			[
				'ID'         => $this->ids['company'],
				'post_title' => '100% Company',
			]
		);
		$this->change( 'trash', [ $this->about( [ 'counts' => [ 'moved' => 1 ] ] ) ] );
		$_REQUEST['ids'] = (string) $this->ids['about'];

		$messages = $this->notice->append( $this->core_messages(), [ 'trashed' => 1 ] );

		$this->assertSame( '“About” moved to the Trash. 1 subpage moved up under “100% Company”.', sprintf( $messages['page']['trashed'], '1' ) );
	}

	public function test_another_page_s_notice_is_left_alone(): void {
		$this->change( 'trash', [ $this->about( [ 'counts' => [ 'moved' => 2 ] ] ) ] );

		$_REQUEST['ids'] = (string) $this->ids['history'];
		$this->assertSame( $this->core_messages(), $this->notice->append( $this->core_messages(), [ 'trashed' => 1 ] ), 'Different IDs.' );

		$_REQUEST['ids'] = (string) $this->ids['about'];
		$this->assertSame( $this->core_messages(), $this->notice->append( $this->core_messages(), [ 'updated' => 1 ] ), 'Not a trash notice.' );

		$GLOBALS['typenow'] = 'post';
		$this->assertSame( $this->core_messages(), $this->notice->append( $this->core_messages(), [ 'trashed' => 1 ] ), 'Another post type.' );
	}

	public function test_a_bulk_restore_without_ids_matches_a_change_made_moments_ago(): void {
		$this->change( 'restore', [ $this->about( [ 'counts' => [ 'moved' => 2 ] ] ) ] );

		$messages = $this->notice->append( $this->core_messages(), [ 'untrashed' => 2 ] );

		$this->assertStringEndsWith( '2 subpages moved back under “About”.', $messages['page']['untrashed'] );
	}

	public function test_a_save_change_leaves_core_s_trash_and_restore_notices_alone(): void {
		$this->set_permalink_structure( '/%postname%/' );
		wp_update_post(
			[
				'ID'        => $this->ids['about'],
				'post_name' => 'about-us',
			]
		);
		$change = $this->log->latest( $this->admin );
		$this->assertSame( 'save', $change['action'] );
		$_REQUEST['ids'] = (string) $this->ids['about'];

		foreach ( [ 'trashed', 'untrashed' ] as $slot ) {
			$this->assertSame( $this->core_messages(), $this->notice->append( $this->core_messages(), [ $slot => 1 ] ), $slot );
		}
		$this->assertFalse( $this->log->latest( $this->admin )['shown'], 'The save’s own notice is still to come.' );
		$this->assertStringNotContainsString( 'hierarchy-guard-urls', $this->rendered(), 'The list screen prints no URLs notice for a save.' );
	}

	public function test_a_save_s_urls_notice_says_what_handles_its_redirects(): void {
		$change = $this->save_about();

		$notice = $this->notice->urls_notice( $change );
		$this->assertSame( 'warning', $notice['type'] );
		$this->assertStringStartsWith( '4 old URLs now show “Page not found”. <a href="', $notice['message'] );
		$this->assertStringContainsString( '/hierarchy-guard/v1/changes/' . $change['id'] . '/csv', $notice['message'] );

		update_option( Settings::OPTION, [ 'serve_redirects' => true ] );
		$this->assertSame(
			[
				'type'    => 'success',
				'message' => '4 old URLs now redirect to their new pages.',
			],
			$this->notice->urls_notice( $change )
		);
		delete_option( Settings::OPTION );

		$this->with_provider();
		$this->assertStringStartsWith( '4 URLs changed. Add their redirects in Redirection. <a href="', $this->notice->urls_notice( $change )['message'] );
	}

	public function test_the_classic_editor_prints_the_save_s_urls_notice_once(): void {
		$change = $this->save_about();
		$before = did_action( 'hierarchy_guard_list_script' );

		$html = $this->rendered_on_edit( $this->ids['about'] );

		$this->assertStringContainsString( 'notice-warning', $html );
		$this->assertStringContainsString( 'hierarchy-guard-urls', $html );
		$this->assertStringContainsString( 'is-dismissible', $html );
		$this->assertStringContainsString( '4 old URLs now show “Page not found”.', $html );
		$this->assertStringContainsString( 'changes/' . $change['id'] . '/csv', $html, 'Without JavaScript the link downloads the CSV.' );
		$this->assertStringContainsString( 'download>Download redirects (CSV)</a>', $html );
		$this->assertSame( 0, did_action( 'hierarchy_guard_list_script' ) - $before, 'The edit screen needs no list script.' );
		$this->assertTrue( $this->log->latest( $this->admin )['shown'] );

		$this->assertSame( '', $this->rendered_on_edit( $this->ids['about'] ), 'Shown once.' );
	}

	public function test_the_classic_notice_is_only_for_the_page_the_save_changed(): void {
		$this->save_about();

		$this->assertSame( '', $this->rendered_on_edit( $this->ids['team'] ), 'Its root is about.' );
		$this->assertSame( '', $this->rendered_on_edit( $this->ids['about'], true ), 'The block editor raises its own notice.' );
		$this->assertFalse( $this->log->latest( $this->admin )['shown'], 'Still to be shown on about’s edit screen.' );

		$this->trash_about();
		$this->assertSame( '', $this->rendered_on_edit( $this->ids['company'] ), 'A trash is no save.' );
	}

	public function test_a_save_made_elsewhere_prints_nothing_on_the_classic_screen(): void {
		$this->set_permalink_structure( '/%postname%/' );
		$saves = [
			'Quick Edit'   => fn() => $this->as_quick_edit(
				fn() => wp_update_post(
					[
						'ID'        => $this->ids['about'],
						'post_name' => 'about-quick',
					]
				)
			),
			'block editor' => fn() => $this->assertSame( 200, $this->rest_save( $this->ids['about'], 'about-blocks' ) ),
			'Bulk Edit'    => fn() => $this->bulk_edit( [ $this->ids['about'] ], 0 ),
			'code'         => fn() => wp_update_post(
				[
					'ID'        => $this->ids['about'],
					'post_name' => 'about-code',
				]
			),
		];
		foreach ( $saves as $where => $save ) {
			Change_Log::reset_request();
			$save();
			$change = $this->log->latest( $this->admin );
			$this->assertSame( 'save', $change['action'], $where );
			$this->assertNotNull( $this->notice->urls_notice( $change ), $where . ': the save changed URLs.' );

			Change_Log::reset_request();
			$this->assertSame( '', $this->rendered_on_edit( $this->ids['about'] ), $where . ': a later visit to the classic edit screen.' );
		}
	}

	public function test_a_bulk_edit_s_urls_notice_prints_once_on_the_pages_list_core_returns_to(): void {
		$this->set_permalink_structure( '/%postname%/' );
		$this->bulk_edit( [ $this->ids['about'] ], 0 );
		$change = $this->log->latest( $this->admin );
		// Core redirects back to the Pages list.
		Change_Log::reset_request();
		$before = did_action( 'hierarchy_guard_list_script' );

		$html = $this->rendered();

		$this->assertStringContainsString( 'hierarchy-guard-urls', $html );
		$this->assertStringContainsString( '4 old URLs now show “Page not found”.', $html );
		$this->assertStringContainsString( 'data-change="' . $change['id'] . '"', $html );
		$this->assertSame( 1, did_action( 'hierarchy_guard_list_script' ) - $before, 'The list script turns its link into the modal’s button.' );
		$this->assertStringNotContainsString( 'hierarchy-guard-nudge', $html, 'Never beside the nudge.' );
		$this->assertTrue( $this->log->latest( $this->admin )['shown'] );

		$this->assertStringNotContainsString( 'hierarchy-guard-urls', $this->rendered(), 'Shown once.' );
		$this->assertSame( '', $this->rendered_on_edit( $this->ids['about'] ), 'Nor on the edit screen.' );
	}

	public function test_the_pages_list_prints_no_urls_notice_for_a_quick_edit_or_another_type(): void {
		$this->set_permalink_structure( '/%postname%/' );
		$this->as_quick_edit(
			fn() => wp_update_post(
				[
					'ID'        => $this->ids['about'],
					'post_name' => 'about-quick',
				]
			)
		);
		Change_Log::reset_request();
		$this->assertStringNotContainsString( 'hierarchy-guard-urls', $this->rendered(), 'Quick Edit showed it in place.' );

		$this->bulk_edit( [ $this->ids['about'] ], 0 );
		Change_Log::reset_request();
		register_post_type(
			'doc',
			[
				'public'       => true,
				'hierarchical' => true,
			]
		);
		add_filter( 'hierarchy_guard_post_types', static fn(): array => [ 'page', 'doc' ] );
		Post_Types::reset();
		try {
			set_current_screen( 'edit-doc' );
			ob_start();
			$this->notice->render();
			$this->assertStringNotContainsString( 'hierarchy-guard-urls', (string) ob_get_clean(), 'Only the list of its own type.' );
			$this->assertFalse( $this->log->latest( $this->admin )['shown'] );
		} finally {
			unregister_post_type( 'doc' );
			Post_Types::reset();
		}
	}

	public function test_trash_sentences_for_one_page(): void {
		$this->assertSame( [ '1 subpage moved up under “Company”.' ], Notices::sentences( $this->change( 'trash', [ $this->about( [ 'counts' => [ 'moved' => 1 ] ] ) ] ) ) );
		Change_Log::reset_request();
		$this->assertSame(
			[ '3 subpages are now top-level pages.' ],
			Notices::sentences(
				$this->change(
					'trash',
					[
						$this->about(
							[
								'to'     => 0,
								'counts' => [ 'moved' => 3 ],
							]
						),
					]
				)
			)
		);
		Change_Log::reset_request();
		$this->assertSame(
			[ '4 subpages moved to the Trash too.', '1 subpage stayed where it is.' ],
			Notices::sentences(
				$this->change(
					'trash',
					[
						$this->about(
							[
								'policy' => Settings::POLICY_TRASH_CHILDREN,
								'counts' => [
									'trashed' => 4,
									'kept'    => 1,
								],
							]
						),
					]
				)
			)
		);
		Change_Log::reset_request();
		$this->assertSame(
			[ 'Its 2 subpages stayed where they are.' ],
			Notices::sentences(
				$this->change(
					'trash',
					[
						$this->about(
							[
								'policy' => Settings::POLICY_KEEP,
								'counts' => [ 'kept' => 2 ],
							]
						),
					]
				)
			)
		);
		Change_Log::reset_request();
		$this->assertSame(
			[ '1 subpage moved up under “Company”.', '1 subpage you don’t have permission to change stayed where it is.', 'The rest are still moving in the background.' ],
			Notices::sentences(
				$this->change(
					'trash',
					[
						$this->about(
							[
								'counts' => [
									'moved'  => 1,
									'kept'   => 1,
									'denied' => 1,
								],
								'job'    => true,
							]
						),
					]
				)
			)
		);
		Change_Log::reset_request();
		$this->assertSame(
			[ '1,200 subpages moved up under “Company”.', 'There were more than 1,000 subpages, so they moved up instead of going to the Trash.' ],
			Notices::sentences(
				$this->change(
					'trash',
					[
						$this->about(
							[
								'fallback' => true,
								'counts'   => [ 'moved' => 1200 ],
							]
						),
					]
				)
			)
		);
	}

	public function test_trash_sentences_for_several_pages(): void {
		$change = $this->change(
			'trash',
			[
				$this->about( [ 'counts' => [ 'moved' => 2 ] ] ),
				[
					'root'   => $this->ids['team'],
					'to'     => $this->ids['company'],
					'policy' => Settings::POLICY_REPARENT,
					'counts' => [ 'moved' => 1 ],
				],
			]
		);

		$this->assertSame( [ '3 subpages moved up one level.' ], Notices::sentences( $change ) );
	}

	public function test_restore_sentences(): void {
		$this->assertSame(
			[ '2 subpages moved back under “About”.', '3 subpages were restored with it.', '1 subpage couldn’t be brought back and stayed where it is.', '1 subpage couldn’t get its old URL back.' ],
			Notices::sentences(
				$this->change(
					'restore',
					[
						$this->about(
							[
								'counts' => [
									'moved'    => 2,
									'restored' => 3,
									'left'     => 1,
									'renamed'  => 1,
								],
							]
						),
					]
				)
			)
		);

		Change_Log::reset_request();
		$change = $this->change(
			'restore',
			[
				$this->about( [ 'counts' => [ 'moved' => 1 ] ] ),
				[
					'root'   => $this->ids['team'],
					'counts' => [ 'restored' => 2 ],
				],
			]
		);
		$this->assertSame( [ '1 subpage moved back under its parent.', '2 subpages were restored too.' ], Notices::sentences( $change ) );
	}

	public function test_the_fallback_sentence_agrees_with_the_cap(): void {
		add_filter( 'hierarchy_guard_count_cap', static fn(): int => 1 );

		$change = $this->change(
			'trash',
			[
				$this->about(
					[
						'fallback' => true,
						'counts'   => [ 'moved' => 2 ],
					]
				),
			]
		);

		$this->assertContains( 'There was more than 1 subpage, so they moved up instead of going to the Trash.', Notices::sentences( $change ) );
	}

	public function test_a_background_trash_says_the_rest_are_going_to_the_trash(): void {
		$change = $this->change(
			'trash',
			[
				$this->about(
					[
						'policy' => Settings::POLICY_TRASH_CHILDREN,
						'counts' => [ 'trashed' => 25 ],
						'job'    => true,
					]
				),
			]
		);

		$this->assertSame( [ '25 subpages moved to the Trash too.', 'The rest are still going to the Trash in the background.' ], Notices::sentences( $change ) );
	}

	public function test_core_s_notice_keeps_what_happened_and_leaves_the_urls_to_their_own_notice(): void {
		$text = $this->trash_about();

		$this->assertSame( '“About” moved to the Trash. 2 subpages moved up under “Company”.', $text, 'Core adds Undo after it.' );
	}

	public function test_with_a_redirect_plugin_the_urls_notice_says_where_to_add_them(): void {
		$this->with_provider();
		$this->trash_about();

		$notice = $this->urls_notice();

		$this->assertSame( 'info', $notice['type'] );
		$this->assertStringStartsWith( '3 URLs changed. Add their redirects in Redirection. <a href="', $notice['message'] );
		$this->assertStringContainsString( 'class="hierarchy-guard-view-redirects" data-change="', $notice['message'] );
		$this->assertStringContainsString( 'data-label="View redirects (3)" download>Download redirects (CSV)</a>', $notice['message'], 'Without JavaScript it downloads the CSV.' );
		$this->assertStringContainsString( 'format=redirection', $notice['message'] );
	}

	public function test_with_built_in_redirects_on_the_urls_notice_is_a_success(): void {
		update_option( Settings::OPTION, [ 'serve_redirects' => true ] );
		$this->trash_about();

		$this->assertSame(
			[
				'type'    => 'success',
				'message' => '3 old URLs now redirect to their new pages.',
			],
			$this->urls_notice()
		);
	}

	public function test_with_nothing_handling_redirects_the_urls_notice_is_a_warning(): void {
		$this->trash_about();

		$notice = $this->urls_notice();

		$this->assertSame( 'warning', $notice['type'] );
		$this->assertStringStartsWith( '3 old URLs now show “Page not found”. <a href="', $notice['message'] );
		$this->assertStringContainsString( 'class="hierarchy-guard-view-redirects"', $notice['message'] );
		$this->assertStringEndsWith( 'download>Download redirects (CSV)</a><span aria-hidden="true"> ·</span> <a href="' . esc_url( admin_url( 'options-general.php?page=hierarchy-guard#hierarchy-guard-redirects' ) ) . '">Set up redirects</a>', $notice['message'], 'View comes first, as in the other two notices.' );
		$this->assertStringContainsString( 'format=generic', $notice['message'] );
	}

	public function test_an_editor_is_not_offered_the_settings(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->admin = get_current_user_id();
		$this->trash_about();

		$notice = $this->urls_notice();

		$this->assertStringNotContainsString( 'Set up redirects', $notice['message'] );
		$this->assertStringContainsString( 'View redirects (3)', $notice['message'] );
	}

	public function test_a_restore_or_a_trash_without_moved_subpages_has_no_urls_notice(): void {
		$this->assertNull( $this->notice->urls_notice( $this->change( 'restore', [ $this->about( [ 'counts' => [ 'moved' => 2 ] ] ) ] ) ) );
	}

	public function test_the_landing_load_prints_the_urls_notice_and_nothing_else(): void {
		$this->record_an_old_url();
		$this->trash_about();

		$html = $this->rendered();

		$this->assertStringContainsString( 'notice-warning', $html );
		$this->assertStringContainsString( 'hierarchy-guard-urls', $html );
		$this->assertStringNotContainsString( 'hierarchy-guard-nudge', $html, 'The URLs notice already offers to set up redirects.' );
	}

	public function test_a_root_title_with_a_percent_sign_survives_core_s_sprintf(): void {
		wp_update_post(
			[
				'ID'         => $this->ids['about'],
				'post_title' => '100% About',
			]
		);

		$this->assertStringStartsWith( '“100% About” moved to the Trash.', $this->trash_about() );
	}

	public function test_a_bulk_trash_keeps_core_s_count(): void {
		$this->change(
			'trash',
			[
				$this->about( [ 'counts' => [ 'moved' => 2 ] ] ),
				[
					'root'   => $this->ids['team'],
					'to'     => $this->ids['about'],
					'policy' => Settings::POLICY_REPARENT,
					'counts' => [ 'moved' => 1 ],
				],
			]
		);
		$_REQUEST['ids'] = $this->ids['about'] . ',' . $this->ids['team'];

		$messages = $this->notice->append( $this->core_messages(), [ 'trashed' => 2 ] );

		$this->assertStringStartsWith( '2 page moved to the Trash. 3 subpages moved up one level.', sprintf( $messages['page']['trashed'], '2' ) );
	}

	public function test_progress_is_shown_while_the_batch_runs_and_not_on_the_landing_load(): void {
		$queue = new Queue();
		$id    = $this->change( 'trash', [ $this->about( [ 'job' => true ] ) ] )['id'];
		$queue->save(
			[
				'root'   => $this->ids['about'],
				'change' => $id,
				'total'  => 40,
				'counts' => array_merge( Change_Log::empty_counts(), [ 'moved' => 25 ] ),
			]
		);

		$this->assertSame( [ 'Still moving the subpages of “About” in the background: 25 of 40 done.' ], $this->notice->progress_sentences( 'page' ) );
		$this->assertSame( [], $this->notice->progress_sentences( 'post' ), 'Another list screen.' );

		$_REQUEST['ids'] = (string) $this->ids['about'];
		$this->notice->append( $this->core_messages(), [ 'trashed' => 1 ] );
		$this->assertSame( [], $this->notice->progress_sentences( 'page' ), 'The outcome notice on this load already says so.' );
		$queue->delete( $this->ids['about'] );
	}

	public function test_progress_of_a_trash_of_the_subpages_and_of_a_restore(): void {
		$queue = new Queue();
		$id    = $this->change(
			'trash',
			[
				$this->about(
					[
						'job'    => true,
						'policy' => Settings::POLICY_TRASH_CHILDREN,
					]
				),
			]
		)['id'];
		$queue->save(
			[
				'root'   => $this->ids['about'],
				'change' => $id,
				'total'  => 0,
				'counts' => array_merge( Change_Log::empty_counts(), [ 'trashed' => 7 ] ),
			]
		);
		$this->assertSame( [ 'Still moving the subpages of “About” to the Trash in the background: 7 done so far.' ], $this->notice->progress_sentences( 'page' ) );

		Change_Log::reset_request();
		$id = $this->change( 'restore', [ $this->about( [ 'job' => true ] ) ] )['id'];
		$queue->save(
			[
				'root'   => $this->ids['about'],
				'change' => $id,
				'total'  => 9,
				'counts' => array_merge( Change_Log::empty_counts(), [ 'restored' => 3 ] ),
			]
		);
		$this->assertSame( [ 'Still restoring the subpages of “About” in the background: 3 of 9 done.' ], $this->notice->progress_sentences( 'page' ) );
		$queue->delete( $this->ids['about'] );
	}

	public function test_a_finished_or_replaced_job_shows_no_progress(): void {
		$this->change( 'trash', [ $this->about( [ 'job' => true ] ) ] );

		$this->assertSame( [], $this->notice->progress_sentences( 'page' ), 'No job in the queue.' );
	}

	public function test_the_nudge_needs_old_urls_and_an_admin_with_nothing_handling_redirects(): void {
		$this->assertFalse( $this->notice->wants_nudge(), 'Nothing recorded yet.' );

		$this->record_an_old_url();
		$this->assertTrue( $this->notice->wants_nudge() );

		update_user_meta( $this->admin, 'dismissed_wp_pointers', 'wp496_privacy,' . Notices::NUDGE_POINTER );
		$this->assertFalse( $this->notice->wants_nudge(), 'Dismissed, through core’s pointer store.' );
		delete_user_meta( $this->admin, 'dismissed_wp_pointers' );

		update_option( Settings::OPTION, [ 'serve_redirects' => true ] );
		$this->assertFalse( $this->notice->wants_nudge(), 'Built-in redirects are on.' );
		delete_option( Settings::OPTION );

		$this->with_provider();
		$this->assertFalse( $this->notice->wants_nudge(), 'A redirect plugin is active.' );
	}

	public function test_an_editor_gets_no_nudge(): void {
		$this->record_an_old_url();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$this->assertFalse( $this->notice->wants_nudge() );
	}

	public function test_the_nudge_is_printed_on_a_supported_list_screen_only(): void {
		$this->record_an_old_url();

		$html = $this->rendered();
		$this->assertStringContainsString( 'hierarchy-guard-nudge', $html );
		$this->assertStringContainsString( 'is-dismissible', $html );
		$this->assertStringContainsString( '<a href="' . esc_url( admin_url( 'options-general.php?page=hierarchy-guard#hierarchy-guard-redirects' ) ) . '">Set up redirects</a>', $html );

		set_current_screen( 'dashboard' );
		ob_start();
		$this->notice->render();
		$this->assertSame( '', (string) ob_get_clean() );
	}

	public function test_progress_lists_three_pages_then_the_rest(): void {
		$queue   = new Queue();
		$entries = [];
		foreach ( [ 'one', 'two', 'three', 'four', 'five' ] as $slug ) {
			$entries[] = [
				'root'   => $this->page( $slug ),
				'to'     => 0,
				'policy' => Settings::POLICY_REPARENT,
				'job'    => true,
			];
		}
		$id = $this->change( 'trash', $entries )['id'];
		foreach ( $entries as $entry ) {
			$queue->save(
				[
					'root'   => $entry['root'],
					'change' => $id,
					'total'  => 10,
					'counts' => array_merge( Change_Log::empty_counts(), [ 'moved' => 4 ] ),
				]
			);
		}

		$sentences = $this->notice->progress_sentences( 'page' );

		$this->assertCount( 4, $sentences );
		$this->assertSame( 'Still moving the subpages of “One” in the background: 4 of 10 done.', $sentences[0] );
		$this->assertSame( 'The subpages of 2 more pages are still being worked on in the background.', $sentences[3] );
		foreach ( $entries as $entry ) {
			$queue->delete( $entry['root'] );
		}
	}

	public function test_a_success_urls_notice_asks_for_the_list_script_when_printed(): void {
		update_option( Settings::OPTION, [ 'serve_redirects' => true ] );
		$this->trash_about();

		$before = did_action( 'hierarchy_guard_list_script' );
		$html   = $this->rendered();

		$this->assertStringContainsString( 'hierarchy-guard-urls', $html );
		$this->assertStringContainsString( 'notice-success', $html );
		$this->assertGreaterThanOrEqual( 1, did_action( 'hierarchy_guard_list_script' ) - $before );
	}

	public function test_building_a_urls_notice_does_not_load_the_list_script(): void {
		$this->trash_about();

		$before = did_action( 'hierarchy_guard_list_script' );
		$this->urls_notice();

		$this->assertSame( 0, did_action( 'hierarchy_guard_list_script' ) - $before );
	}

	public function test_the_nudge_asks_for_the_list_script(): void {
		$this->record_an_old_url();

		$before = did_action( 'hierarchy_guard_list_script' );
		$html   = $this->rendered();

		$this->assertStringContainsString( 'hierarchy-guard-nudge', $html );
		$this->assertGreaterThanOrEqual( 1, did_action( 'hierarchy_guard_list_script' ) - $before );
	}
}
