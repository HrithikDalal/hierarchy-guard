<?php
/**
 * Per-user change summary.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Post_Types;
use HierarchyGuard\Redirects\Pairs;
use HierarchyGuard\Trash\Change_Log;
use WP_UnitTestCase;

/**
 * @covers \HierarchyGuard\Trash\Change_Log
 */
final class ChangeLogTest extends WP_UnitTestCase {

	use Trash_Fixtures;

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

	public function test_the_roots_of_one_request_are_written_once(): void {
		$writes = 0;
		add_filter(
			'pre_set_transient_' . Change_Log::key( $this->admin ),
			static function ( $value ) use ( &$writes ) {
				++$writes;
				return $value;
			}
		);
		$queries = get_num_queries();

		for ( $root = 1; $root <= 200; $root++ ) {
			$id = $this->log->record( $this->admin, 'save', 'page', $this->entry( $root, [ 'moved' => 1 ] ), [ $root ] );
		}

		$this->assertSame( 0, $writes, 'Nothing is written while the request records.' );
		$this->assertSame( $queries, get_num_queries(), 'Recording 200 roots costs no query.' );
		$change = $this->log->latest( $this->admin );
		$this->assertSame( $id, $change['id'], 'The request sees its own change.' );
		$this->assertCount( 200, $change['roots'] );
		$this->assertSame( 200, $change['counts']['moved'], 'Its counts are summed.' );

		Change_Log::reset_request();

		$this->assertSame( 1, $writes, 'The request’s change is written once, when it ends.' );
		$stored = get_transient( Change_Log::key( $this->admin ) );
		$this->assertSame( $id, $stored['id'] );
		$this->assertCount( 200, $stored['roots'] );
		$this->assertSame( range( 1, Change_Log::MAX_IDS ), $stored['ids'] );
	}

	public function test_a_change_waiting_to_be_written_is_written_at_shutdown(): void {
		$this->log->record( $this->admin, 'trash', 'page', $this->entry( 12, [] ) );

		$this->assertSame( 0, has_action( 'shutdown', [ Change_Log::class, 'flush' ] ), 'Before anything else at shutdown, so it is saved before the response ends.' );
		$this->assertFalse( get_transient( Change_Log::key( $this->admin ) ) );

		Change_Log::flush();
		$this->assertSame( [ 12 ], array_keys( get_transient( Change_Log::key( $this->admin ) )['roots'] ) );
	}

	public function test_a_change_recorded_at_shutdown_is_written_at_once(): void {
		$GLOBALS['wp_actions']['shutdown'] = 1;

		$this->log->record( $this->admin, 'restore', 'page', $this->entry( 12, [] ) );

		$this->assertSame( [ 12 ], array_keys( get_transient( Change_Log::key( $this->admin ) )['roots'] ), 'Shutdown has begun, so nothing would write it later.' );
	}

	public function test_marking_the_request_s_change_shown_is_written_with_it(): void {
		$writes = 0;
		add_filter(
			'pre_set_transient_' . Change_Log::key( $this->admin ),
			static function ( $value ) use ( &$writes ) {
				++$writes;
				return $value;
			}
		);
		$id = $this->log->record( $this->admin, 'save', 'page', $this->entry( 12, [] ) );
		$this->log->mark_shown( $this->admin, $id );
		$this->log->update( $this->admin, $id, 12, [ 'moved' => 2 ], [], [], [], false );

		Change_Log::reset_request();

		$this->assertSame( 1, $writes );
		$stored = get_transient( Change_Log::key( $this->admin ) );
		$this->assertTrue( $stored['shown'] );
		$this->assertSame( 2, $stored['counts']['moved'] );
	}

	public function test_a_change_from_an_earlier_request_is_updated_at_once(): void {
		$id = $this->log->record( $this->admin, 'trash', 'page', $this->entry( 12, [ 'moved' => 200 ], true ) );
		Change_Log::reset_request();

		$this->log->update( $this->admin, $id, 12, [ 'moved' => 400 ], [], [], [], true );
		$this->assertSame( 400, get_transient( Change_Log::key( $this->admin ) )['counts']['moved'], 'A background batch writes its progress as it goes.' );

		$this->log->mark_shown( $this->admin, $id );
		$this->assertTrue( get_transient( Change_Log::key( $this->admin ) )['shown'] );
	}

	public function test_the_request_s_change_can_be_written_before_the_request_ends(): void {
		$writes = 0;
		add_filter(
			'pre_set_transient_' . Change_Log::key( $this->admin ),
			static function ( $value ) use ( &$writes ) {
				++$writes;
				return $value;
			}
		);
		$id = $this->log->record( $this->admin, 'trash', 'page', $this->entry( 12, [ 'moved' => 2 ], true ), [ 3, 4 ] );

		$this->log->write_now( $this->admin );

		$this->assertSame( 1, $writes );
		$this->assertSame( $id, $this->in_another_process( fn() => $this->log->latest( $this->admin )['id'] ), 'Another process reads it now.' );
		Change_Log::reset_request();
		$this->assertSame( 1, $writes, 'Nothing is left to write when the request ends.' );
	}

	public function test_the_request_s_last_write_keeps_what_another_process_stored_since(): void {
		$id = $this->log->record( $this->admin, 'trash', 'page', $this->entry( 12, [ 'moved' => 2 ], true ), [ 3, 4 ] );
		$this->log->write_now( $this->admin );
		$this->log->record( $this->admin, 'trash', 'page', $this->entry( 20, [ 'kept' => 1 ] ), [ 9 ] );

		// A background run finishes root 12's job before the request ends.
		$this->in_another_process( fn() => $this->log->update( $this->admin, $id, 12, [ 'moved' => 5 ], [], [ 3, 4, 5, 6, 7 ], [], false ) );
		Change_Log::reset_request();

		$stored = get_transient( Change_Log::key( $this->admin ) );
		$this->assertSame( $id, $stored['id'] );
		$this->assertFalse( $stored['roots'][12]['job'], 'The run’s update survives the request’s last write.' );
		$this->assertSame( 5, $stored['roots'][12]['counts']['moved'] );
		$this->assertSame( [ 3, 4, 5, 6, 7 ], $stored['roots'][12]['ids'] );
		$this->assertSame( 1, $stored['roots'][20]['counts']['kept'], 'The root recorded after the first write is written.' );
		$this->assertSame( 5, $stored['counts']['moved'] );
		$this->assertSame( 1, $stored['counts']['kept'] );
		$this->assertEqualsCanonicalizing( [ 3, 4, 5, 6, 7, 9 ], $stored['ids'] );
	}

	public function test_each_site_keeps_its_own_change_in_the_request(): void {
		$main          = $this->log->record( $this->admin, 'save', 'page', $this->entry( 4, [] ) );
		$entry         = $this->entry( 4, [] );
		$entry['path'] = 'beta';
		$blog          = (int) $GLOBALS['blog_id'];
		// As switch_to_blog() sets it, which a single site does not have.
		$GLOBALS['blog_id'] = $blog + 1;
		try {
			$other = $this->log->record( $this->admin, 'save', 'page', $entry );
			$this->assertNotSame( $main, $other, 'A save on another site is a change of its own.' );
			$this->assertSame( 'beta', $this->log->latest( $this->admin )['roots'][4]['path'] );
		} finally {
			$this->log->forget( $this->admin );
			$GLOBALS['blog_id'] = $blog;
		}

		$this->assertSame( $main, $this->log->latest( $this->admin )['id'] );
		$this->assertSame( 'company/about', $this->log->latest( $this->admin )['roots'][4]['path'], 'This site’s change keeps its own root.' );
	}

	public function test_a_request_that_saves_on_two_sites_leaves_each_its_own_change(): void {
		$this->two_site_saves( false );
	}

	public function test_a_request_that_ends_on_another_site_writes_each_change_on_its_own_site(): void {
		$this->two_site_saves( true );
	}

	/**
	 * Saves a page on the main site and one on site B in one request, as a
	 * blog-switching plugin does, then ends the request.
	 *
	 * @param bool $end_switched End the request while site B is still current.
	 */
	private function two_site_saves( bool $end_switched ): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Run with WP_MULTISITE=1.' );
		}
		wp_set_current_user( $this->admin );
		Post_Types::reset();
		update_option( 'permalink_structure', '/%postname%/' );
		$site = self::factory()->blog->create();
		switch_to_blog( $site );
		update_option( 'permalink_structure', '/%postname%/' );
		$beta = $this->page( 'beta' );
		restore_current_blog();
		$alpha = $this->page( 'alpha' );
		Change_Log::reset_request();

		wp_update_post(
			[
				'ID'        => $alpha,
				'post_name' => 'alpha-2',
			]
		);
		switch_to_blog( $site );
		wp_update_post(
			[
				'ID'        => $beta,
				'post_name' => 'beta-2',
			]
		);
		if ( $end_switched ) {
			Change_Log::reset_request();
		}
		restore_current_blog();
		Change_Log::reset_request();

		$main = get_transient( Change_Log::key( $this->admin ) );
		switch_to_blog( $site );
		$other = get_transient( Change_Log::key( $this->admin ) );
		restore_current_blog();

		$this->assertIsArray( $main );
		$this->assertSame( [ $alpha => 'alpha' ], array_column( $main['roots'], 'path', 'root' ), 'The main site’s change has its own save only.' );
		$this->assertIsArray( $other, 'Site B keeps a change of its own.' );
		$this->assertSame( [ $beta => 'beta' ], array_column( $other['roots'], 'path', 'root' ) );
		$this->assertNotSame( $main['id'], $other['id'] );
		$pairs = new Pairs();
		$this->assertSame( [ '/alpha/' ], array_column( $pairs->page( $pairs->collect_change( $main ), 1, 100 )['pairs'], 'old' ) );
	}

	public function test_forgetting_drops_the_change_waiting_to_be_written(): void {
		$this->log->record( $this->admin, 'trash', 'page', $this->entry( 12, [] ) );

		$this->log->forget( $this->admin );
		Change_Log::reset_request();

		$this->assertNull( $this->log->latest( $this->admin ) );
		$this->assertFalse( get_transient( Change_Log::key( $this->admin ) ) );
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
