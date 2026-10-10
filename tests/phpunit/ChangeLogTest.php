<?php
/**
 * Per-user change summary.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Trash\Change_Log;
use WP_UnitTestCase;

/**
 * @covers \HierarchyGuard\Trash\Change_Log
 */
final class ChangeLogTest extends WP_UnitTestCase {

	private Change_Log $log;

	private int $admin;

	public function set_up(): void {
		parent::set_up();
		Change_Log::reset_request();
		$this->log   = new Change_Log();
		$this->admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
	}

	/**
	 * A root entry.
	 *
	 * @param int               $root   Root ID.
	 * @param array<string,int> $counts Counts.
	 * @param bool              $job    Whether a job is running.
	 * @return array<string,mixed>
	 */
	private function entry( int $root, array $counts, bool $job = false ): array {
		return [
			'root'     => $root,
			'to'       => 7,
			'policy'   => 'reparent',
			'fallback' => false,
			'counts'   => $counts,
			'kept_ids' => [],
			'urls'     => [
				'count'  => 3,
				'capped' => false,
			],
			'job'      => $job,
		];
	}

	public function test_records_one_change_with_its_root(): void {
		$id = $this->log->record( $this->admin, 'trash', 'page', $this->entry( 12, [ 'moved' => 2 ] ), [ 3, 4 ] );

		$change = $this->log->latest( $this->admin );
		$this->assertSame( $id, $change['id'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f-]{36}$/', $id );
		$this->assertSame( 'trash', $change['action'] );
		$this->assertSame( 'page', $change['post_type'] );
		$this->assertSame( [ 12 ], array_keys( $change['roots'] ) );
		$this->assertSame( 7, $change['roots'][12]['to'] );
		$this->assertSame( 2, $change['counts']['moved'] );
		$this->assertSame( 0, $change['counts']['trashed'], 'Every count key is present.' );
		$this->assertSame( [ 3, 4 ], $change['ids'] );
		$this->assertFalse( $change['shown'] );
		$this->assertIsInt( $change['time'] );
	}

	public function test_roots_recorded_in_one_request_share_a_change(): void {
		$first  = $this->log->record( $this->admin, 'trash', 'page', $this->entry( 12, [ 'moved' => 2 ] ), [ 3, 4 ] );
		$second = $this->log->record( $this->admin, 'trash', 'page', $this->entry( 20, [ 'moved' => 3 ] ), [ 4, 5 ] );

		$change = $this->log->latest( $this->admin );
		$this->assertSame( $first, $second );
		$this->assertSame( [ 12, 20 ], array_keys( $change['roots'] ) );
		$this->assertSame( 5, $change['counts']['moved'] );
		$this->assertSame( [ 3, 4, 5 ], $change['ids'] );
	}

	public function test_a_new_request_or_action_starts_a_new_change(): void {
		$first = $this->log->record( $this->admin, 'trash', 'page', $this->entry( 12, [] ) );
		Change_Log::reset_request();
		$second = $this->log->record( $this->admin, 'trash', 'page', $this->entry( 20, [] ) );
		$third  = $this->log->record( $this->admin, 'restore', 'page', $this->entry( 20, [] ) );

		$this->assertNotSame( $first, $second );
		$this->assertNotSame( $second, $third );
		$this->assertSame( [ 20 ], array_keys( $this->log->latest( $this->admin )['roots'] ) );
	}

	public function test_update_refreshes_a_root_while_the_change_lasts(): void {
		$id = $this->log->record( $this->admin, 'trash', 'page', $this->entry( 12, [ 'moved' => 200 ], true ), [ 1 ] );

		$this->log->update( $this->admin, $id, 12, [ 'moved' => 1200 ], [ 9 ], [ 2 ], false );

		$change = $this->log->latest( $this->admin );
		$this->assertSame( 1200, $change['roots'][12]['counts']['moved'] );
		$this->assertSame( 1200, $change['counts']['moved'] );
		$this->assertFalse( $change['roots'][12]['job'] );
		$this->assertSame( [ 9 ], $change['roots'][12]['kept_ids'] );
		$this->assertSame( 7, $change['roots'][12]['to'], 'Fields the batch does not own are kept.' );
		$this->assertSame( [ 1, 2 ], $change['ids'] );

		$this->log->update( $this->admin, 'another-change', 12, [ 'moved' => 5 ], [], [], false );
		$this->assertSame( 1200, $this->log->latest( $this->admin )['counts']['moved'], 'A replaced change is left alone.' );
	}

	public function test_ids_and_kept_ids_are_capped(): void {
		$entry             = $this->entry( 12, [] );
		$entry['kept_ids'] = range( 1, 50 );
		$this->log->record( $this->admin, 'trash', 'page', $entry, range( 1, 250 ) );

		$change = $this->log->latest( $this->admin );
		$this->assertCount( Change_Log::MAX_IDS, $change['ids'] );
		$this->assertCount( Change_Log::MAX_KEPT, $change['roots'][12]['kept_ids'] );
	}

	public function test_nothing_is_stored_without_a_user(): void {
		$this->assertSame( '', $this->log->record( 0, 'trash', 'page', $this->entry( 12, [] ) ) );
		$this->assertFalse( get_transient( Change_Log::key( 0 ) ) );
	}

	public function test_mark_shown_and_forget(): void {
		$id = $this->log->record( $this->admin, 'trash', 'page', $this->entry( 12, [] ) );

		$this->log->mark_shown( $this->admin, $id );
		$this->assertTrue( $this->log->latest( $this->admin )['shown'] );

		$this->log->forget( $this->admin );
		$this->assertNull( $this->log->latest( $this->admin ) );
	}
}
