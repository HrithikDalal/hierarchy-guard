<?php
/**
 * The sentence added to core's trash notice.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Admin\Trash_Notice;
use HierarchyGuard\Post_Types;
use HierarchyGuard\Settings;
use HierarchyGuard\Trash\Change_Log;
use WP_UnitTestCase;

/**
 * @covers \HierarchyGuard\Admin\Trash_Notice
 */
final class TrashNoticeTest extends WP_UnitTestCase {

	use Trash_Fixtures;

	/**
	 * Page IDs.
	 *
	 * @var array<string,int>
	 */
	private array $ids;

	private int $admin;

	private Change_Log $log;

	private Trash_Notice $notice;

	public function set_up(): void {
		parent::set_up();
		delete_option( Settings::OPTION );
		Post_Types::reset();
		Change_Log::reset_request();
		$this->admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $this->admin );
		$this->log          = new Change_Log();
		$this->notice       = new Trash_Notice( $this->log );
		$this->ids          = $this->make_tree();
		$GLOBALS['typenow'] = 'page';
	}

	public function tear_down(): void {
		unset( $GLOBALS['typenow'], $_REQUEST['ids'] );
		parent::tear_down();
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
			if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof Trash_Notice ) {
				$found = true;
			}
		}
		$this->assertTrue( $found );
	}

	public function test_core_s_trash_notice_gains_one_sentence_once(): void {
		$this->change( 'trash', [ $this->about( [ 'counts' => [ 'moved' => 2 ] ] ) ] );
		$_REQUEST['ids'] = (string) $this->ids['about'];

		$messages = $this->notice->append( $this->core_messages(), [ 'trashed' => 1 ] );

		$this->assertSame( '1 page moved to the Trash. 2 subpages moved up under “Company”.', sprintf( $messages['page']['trashed'], '1' ) );
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

		$this->assertSame( '1 page moved to the Trash. 1 subpage moved up under “100% Company”.', sprintf( $messages['page']['trashed'], '1' ) );
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

	public function test_trash_sentences_for_one_page(): void {
		$this->assertSame( [ '1 subpage moved up under “Company”.' ], Trash_Notice::sentences( $this->change( 'trash', [ $this->about( [ 'counts' => [ 'moved' => 1 ] ] ) ] ) ) );
		Change_Log::reset_request();
		$this->assertSame(
			[ '3 subpages are now top-level pages.' ],
			Trash_Notice::sentences(
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
			Trash_Notice::sentences(
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
			Trash_Notice::sentences(
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
			Trash_Notice::sentences(
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
			Trash_Notice::sentences(
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

		$this->assertSame( [ '3 subpages moved up one level.' ], Trash_Notice::sentences( $change ) );
	}

	public function test_restore_sentences(): void {
		$this->assertSame(
			[ '2 subpages moved back under “About”.', '3 subpages were restored with it.', '1 subpage was moved since and stayed where it is.', '1 subpage couldn’t get its old URL back.' ],
			Trash_Notice::sentences(
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
		$this->assertSame( [ '1 subpage moved back under its parent.', '2 subpages were restored too.' ], Trash_Notice::sentences( $change ) );
	}
}
