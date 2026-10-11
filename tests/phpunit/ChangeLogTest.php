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
			'path'     => 'company/about',
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

		$this->log->update( $this->admin, $id, 12, [ 'moved' => 1200 ], [ 9 ], [ 2 ], [ 2 => 'old' ], false );

		$change = $this->log->latest( $this->admin );
		$this->assertSame( 1200, $change['roots'][12]['counts']['moved'] );
		$this->assertSame( 1200, $change['counts']['moved'] );
		$this->assertFalse( $change['roots'][12]['job'] );
		$this->assertSame( [ 9 ], $change['roots'][12]['kept_ids'] );
		$this->assertSame( 7, $change['roots'][12]['to'], 'Fields the batch does not own are kept.' );
		$this->assertSame( [ 1, 2 ], $change['ids'] );
		$this->assertSame( [ 2 ], $change['roots'][12]['ids'], 'The root’s own IDs are the batch’s, so its pairs survive a delete.' );
		$this->assertSame( 'company/about', $change['roots'][12]['path'], 'The old path the pairs are rebuilt from is kept.' );
		$this->assertSame( [ 2 => 'old' ], $change['roots'][12]['slugs'] );

		$this->log->update( $this->admin, 'another-change', 12, [ 'moved' => 5 ], [], [], [], false );
		$this->assertSame( 1200, $this->log->latest( $this->admin )['counts']['moved'], 'A replaced change is left alone.' );
	}

	public function test_ids_and_kept_ids_are_capped(): void {
		$entry             = $this->entry( 12, [] );
		$entry['kept_ids'] = range( 1, 50 );
		$entry['ids']      = range( 1, 250 );
		$entry['slugs']    = array_fill_keys( range( 1, 250 ), 'slug' );
		$this->log->record( $this->admin, 'trash', 'page', $entry, range( 1, 250 ) );

		$change = $this->log->latest( $this->admin );
		$this->assertCount( Change_Log::MAX_IDS, $change['ids'] );
		$this->assertCount( Change_Log::MAX_KEPT, $change['roots'][12]['kept_ids'] );
		$this->assertCount( Change_Log::MAX_IDS, $change['roots'][12]['ids'] );
		$this->assertCount( Change_Log::MAX_IDS, $change['roots'][12]['slugs'] );
	}

	public function test_the_ids_of_every_root_together_stay_within_the_count_cap(): void {
		add_filter( 'hierarchy_guard_count_cap', static fn(): int => 5 );
		$first           = $this->entry( 12, [ 'moved' => 4 ] );
		$first['ids']    = [ 1, 2, 3, 4 ];
		$first['slugs']  = [
			1 => 'a',
			2 => 'b',
			3 => 'c',
			4 => 'd',
		];
		$second          = $this->entry( 20, [ 'moved' => 3 ] );
		$second['ids']   = [ 5, 6, 7 ];
		$second['slugs'] = [
			5 => 'e',
			6 => 'f',
			7 => 'g',
		];

		$this->log->record( $this->admin, 'trash', 'page', $first );
		$this->log->record( $this->admin, 'trash', 'page', $second );

		$change = $this->log->latest( $this->admin );
		$this->assertSame( [ 1, 2, 3, 4 ], $change['roots'][12]['ids'], 'The first root keeps its IDs.' );
		$this->assertSame( [ 5 ], $change['roots'][20]['ids'], 'A later root is trimmed first.' );
		$this->assertSame( [ 5 => 'e' ], $change['roots'][20]['slugs'], 'Slugs follow the IDs kept.' );
		$this->assertSame( 3, $change['roots'][20]['counts']['moved'], 'The count still says how many moved, so its pairs are marked incomplete.' );
	}

	public function test_only_roots_whose_subpages_moved_up_share_the_id_budget(): void {
		add_filter( 'hierarchy_guard_count_cap', static fn(): int => 5 );
		$trashed           = $this->entry( 12, [ 'trashed' => 5 ] );
		$trashed['policy'] = 'trash_children';
		$trashed['ids']    = [ 1, 2, 3, 4, 5 ];
		$moved             = $this->entry( 20, [ 'moved' => 3 ] );
		$moved['ids']      = [ 6, 7, 8 ];
		$moved['slugs']    = [
			6 => 'f',
			7 => 'g',
			8 => 'h',
		];

		$this->log->record( $this->admin, 'trash', 'page', $trashed );
		$this->log->record( $this->admin, 'trash', 'page', $moved );

		$change = $this->log->latest( $this->admin );
		$this->assertSame( [ 1, 2, 3, 4, 5 ], $change['roots'][12]['ids'], 'A root that trashed its subpages keeps its IDs as before.' );
		$this->assertSame( [ 6, 7, 8 ], $change['roots'][20]['ids'], 'Only the pairs read IDs, and only a reparent root’s.' );
		$this->assertSame(
			[
				6 => 'f',
				7 => 'g',
				8 => 'h',
			],
			$change['roots'][20]['slugs']
		);
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
