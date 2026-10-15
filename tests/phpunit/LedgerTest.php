<?php
/**
 * The old-path ledger.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Ledger;
use HierarchyGuard\Post_Types;
use HierarchyGuard\Settings;
use WP_UnitTestCase;

/**
 * @covers \HierarchyGuard\Ledger
 */
final class LedgerTest extends WP_UnitTestCase {

	private Ledger $ledger;

	private int $about;

	public function set_up(): void {
		parent::set_up();
		delete_option( Settings::OPTION );
		delete_option( Ledger::ROOT_OPTION );
		Post_Types::reset();
		$this->set_permalink_structure( '/%postname%/' );
		$this->ledger = new Ledger();
		$this->about  = self::factory()->post->create(
			[
				'post_type' => 'page',
				'post_name' => 'about',
			]
		);
	}

	public function tear_down(): void {
		$this->set_permalink_structure( '' );
		parent::tear_down();
	}

	public function test_key_hashes_type_and_path_inside_the_index_prefix_length(): void {
		$key = Ledger::key( 'page', 'about/team' );
		$this->assertStringStartsWith( Ledger::KEY_PREFIX, $key );
		$this->assertSame( Ledger::KEY_PREFIX . md5( 'page:about/team' ), $key );
		$this->assertLessThan( 191, strlen( $key ) );
		$this->assertNotSame( $key, Ledger::key( 'hg_docs', 'about/team' ), 'Keys are scoped by post type.' );
	}

	public function test_record_stores_one_entry(): void {
		$this->assertTrue( $this->ledger->record( $this->about, '/About-Us/' ) );
		$this->assertSame( [ 'about-us' ], $this->ledger->paths_for( $this->about ) );
		$this->assertSame( 'about-us', get_post_meta( $this->about, Ledger::key( 'page', 'about-us' ), true ) );
	}

	public function test_record_is_idempotent(): void {
		$this->assertTrue( $this->ledger->record( $this->about, 'about-us' ) );
		$this->assertFalse( $this->ledger->record( $this->about, 'about-us' ) );
		$this->assertCount( 1, $this->ledger->paths_for( $this->about ) );
	}

	public function test_record_refuses_the_current_path_and_empty_paths(): void {
		$this->assertFalse( $this->ledger->record( $this->about, 'about' ) );
		$this->assertFalse( $this->ledger->record( $this->about, '/' ) );
		$this->assertSame( [], $this->ledger->paths_for( $this->about ) );
	}

	public function test_record_respects_the_should_record_filter(): void {
		add_filter( 'hierarchy_guard_should_record', '__return_false' );
		$this->assertFalse( $this->ledger->record( $this->about, 'about-us' ) );
		remove_filter( 'hierarchy_guard_should_record', '__return_false' );
	}

	public function test_record_ignores_unknown_posts(): void {
		$this->assertFalse( $this->ledger->record( 999999, 'about-us' ) );
	}

	public function test_prune_removes_the_entry_equal_to_the_current_path(): void {
		global $wpdb;

		$this->ledger->record( $this->about, 'old-about' );

		// Change the slug without firing hooks, so only prune() is under test.
		$wpdb->update( $wpdb->posts, [ 'post_name' => 'old-about' ], [ 'ID' => $this->about ] );
		clean_post_cache( $this->about );

		$this->assertSame( 1, $this->ledger->prune( $this->about ) );
		$this->assertSame( [], $this->ledger->paths_for( $this->about ) );
		$this->assertSame( 0, $this->ledger->prune( $this->about ) );
	}

	public function test_find_returns_the_post_for_a_recorded_path(): void {
		$this->ledger->record( $this->about, 'about-us' );

		$this->assertSame( $this->about, $this->ledger->find( 'page', 'about-us' ) );
		$this->assertSame( $this->about, $this->ledger->find( 'page', '/About-Us/' ), 'find() normalizes its input.' );
		$this->assertNull( $this->ledger->find( 'page', 'nope' ) );
		$this->assertNull( $this->ledger->find( 'post', 'about-us' ), 'Lookups are scoped by post type.' );
	}

	public function test_find_prefers_the_newest_entry_when_two_posts_share_an_old_path(): void {
		$other = self::factory()->post->create(
			[
				'post_type' => 'page',
				'post_name' => 'other',
			]
		);
		$this->ledger->record( $this->about, 'shared' );
		$this->ledger->record( $other, 'shared' );

		$this->assertSame( $other, $this->ledger->find( 'page', 'shared' ) );
	}

	public function test_find_caches_misses_until_the_ledger_changes(): void {
		$this->assertNull( $this->ledger->find( 'page', 'later' ) );

		$queries = get_num_queries();
		$this->assertNull( $this->ledger->find( 'page', 'later' ) );
		$this->assertSame( $queries, get_num_queries(), 'A repeated miss must be served from cache.' );

		$this->ledger->record( $this->about, 'later' );
		$this->assertSame( $this->about, $this->ledger->find( 'page', 'later' ), 'Recording must invalidate the cached miss.' );
	}

	public function test_root_paths_are_found_as_zero_and_capped(): void {
		$this->ledger->record_root( 'page', '/Company/' );

		$this->assertSame( 0, $this->ledger->find( 'page', 'company' ) );
		$this->assertNull( $this->ledger->find( 'hg_docs', 'company' ) );

		for ( $i = 0; $i < 205; $i++ ) {
			$this->ledger->record_root( 'page', 'old-' . $i );
		}
		$stored = get_option( Ledger::ROOT_OPTION );
		$this->assertCount( 200, $stored['page'] );
		$this->assertNull( $this->ledger->find( 'page', 'company' ), 'The oldest entries fall off the end.' );
		$this->assertSame( 0, $this->ledger->find( 'page', 'old-204' ) );
	}

	public function test_a_post_entry_wins_over_a_root_entry(): void {
		$this->ledger->record_root( 'page', 'about-us' );
		$this->ledger->record( $this->about, 'about-us' );

		$this->assertSame( $this->about, $this->ledger->find( 'page', 'about-us' ) );
	}

	public function test_record_alias_writes_on_the_new_parent_or_the_root_list(): void {
		$this->assertTrue( $this->ledger->record_alias( 'page', $this->about, 'about/team-old' ) );
		$this->assertSame( $this->about, $this->ledger->find( 'page', 'about/team-old' ) );

		$this->assertTrue( $this->ledger->record_alias( 'page', 0, 'gone' ) );
		$this->assertSame( 0, $this->ledger->find( 'page', 'gone' ) );
		$this->assertFalse( $this->ledger->record_alias( 'page', 0, 'gone' ), 'Already recorded.' );
	}

	public function test_forget_alias_removes_what_record_alias_wrote(): void {
		$this->ledger->record_alias( 'page', $this->about, 'about/old' );
		$this->ledger->record_alias( 'page', 0, 'gone' );
		$this->ledger->record_root( 'page', 'kept' );

		$this->assertTrue( $this->ledger->forget_alias( 'page', $this->about, '/About/Old/' ) );
		$this->assertTrue( $this->ledger->forget_alias( 'page', 0, 'gone' ) );

		$this->assertNull( $this->ledger->find( 'page', 'about/old' ) );
		$this->assertNull( $this->ledger->find( 'page', 'gone' ) );
		$this->assertSame( 0, $this->ledger->find( 'page', 'kept' ), 'Other root entries stay.' );
		$this->assertFalse( $this->ledger->forget_alias( 'page', 0, 'gone' ) );
		$this->assertFalse( $this->ledger->forget( 999999, 'about/old' ) );
	}

	public function test_deferred_invalidates_cached_lookups_once_at_the_end(): void {
		$this->assertNull( $this->ledger->find( 'page', 'one' ), 'Precondition: a cached miss.' );
		$before = wp_cache_get_last_changed( Ledger::CACHE_GROUP );
		$inside = null;

		Ledger::deferred(
			function () use ( &$inside ): void {
				$this->ledger->record( $this->about, 'one' );
				$this->ledger->record( $this->about, 'two' );
				$inside = wp_cache_get_last_changed( Ledger::CACHE_GROUP );
			}
		);

		$this->assertSame( $before, $inside, 'No invalidation while deferred.' );
		$this->assertNotSame( $before, wp_cache_get_last_changed( Ledger::CACHE_GROUP ) );
		$this->assertSame( $this->about, $this->ledger->find( 'page', 'one' ), 'The cached miss is gone.' );
	}

	public function test_a_live_holder_answers_and_a_trashed_one_does_not(): void {
		$other = self::factory()->post->create(
			[
				'post_type' => 'page',
				'post_name' => 'other',
			]
		);
		$this->ledger->record( $other, 'shared' );
		$this->ledger->record( $this->about, 'shared' );
		wp_trash_post( $this->about );

		$this->assertSame( $other, $this->ledger->find( 'page', 'shared' ), 'The newer entry is on a page in the trash.' );
		wp_trash_post( $other );
		$this->ledger->record_root( 'page', 'shared' );
		$this->assertSame( 0, $this->ledger->find( 'page', 'shared' ), 'With every holder in the trash, the root list answers.' );
		$this->assertTrue( $this->ledger->held_in_trash( 'page', '/Shared/' ) );
		$this->assertFalse( $this->ledger->held_in_trash( 'page', 'elsewhere' ) );
		$this->assertFalse( $this->ledger->held_in_trash( 'page', '' ) );
	}

	public function test_an_old_path_answers_again_once_its_page_leaves_the_trash(): void {
		$this->ledger->record( $this->about, 'about-old' );
		wp_trash_post( $this->about );
		$this->assertNull( $this->ledger->find( 'page', 'about-old' ), 'Nothing answers while the page is in the trash.' );

		wp_untrash_post( $this->about );

		$this->assertSame( $this->about, $this->ledger->find( 'page', 'about-old' ), 'No ledger write happened, so the cached answer must still be right.' );
	}

	public function test_held_in_trash_stops_at_the_first_page_in_the_trash(): void {
		global $wpdb;
		for ( $i = 1; $i <= 3; $i++ ) {
			$held = self::factory()->post->create(
				[
					'post_type' => 'page',
					'post_name' => 'held-' . $i,
				]
			);
			$this->ledger->record( $held, 'zz' );
			wp_trash_post( $held );
		}

		$this->assertTrue( Ledger::held_in_trash( 'page', 'zz' ) );
		$this->assertSame( 1, $wpdb->num_rows, 'One row answers the question.' );
		$this->assertFalse( Ledger::held_in_trash( 'page', 'yy' ) );
	}

	public function test_claim_drops_other_pages_entries_for_the_path_a_page_takes(): void {
		$moved   = self::factory()->post->create(
			[
				'post_type' => 'page',
				'post_name' => 'moved',
			]
		);
		$trashed = self::factory()->post->create(
			[
				'post_type' => 'page',
				'post_name' => 'trashed',
			]
		);
		$this->ledger->record( $moved, 'taken' );
		$this->ledger->record( $moved, 'kept' );
		$this->ledger->record( $trashed, 'taken' );
		$this->ledger->record_root( 'page', 'taken' );
		wp_trash_post( $trashed );

		$taker = self::factory()->post->create(
			[
				'post_type' => 'page',
				'post_name' => 'taken',
			]
		);

		$this->assertSame( [ 'kept' ], $this->ledger->paths_for( $moved ), 'A live page gives the path up.' );
		$this->assertSame( [ 'taken' ], $this->ledger->paths_for( $trashed ), 'A page in the trash keeps it, so a redirect stays temporary.' );
		$this->assertSame( [ 'page' => [] ], get_option( Ledger::ROOT_OPTION ) );
		$this->assertNull( $this->ledger->find( 'page', 'taken' ) );
		$this->assertNotSame( $taker, $moved );
	}

	public function test_a_page_moved_onto_a_recorded_path_claims_it(): void {
		$old = self::factory()->post->create(
			[
				'post_type' => 'page',
				'post_name' => 'old',
			]
		);
		$this->ledger->record( $old, 'about-us' );

		wp_update_post(
			[
				'ID'        => $this->about,
				'post_name' => 'about-us',
			]
		);

		$this->assertSame( [], $this->ledger->paths_for( $old ) );
		$this->assertSame( [ 'about' ], $this->ledger->paths_for( $this->about ) );
	}

	public function test_only_a_published_page_claims_a_path(): void {
		$old = self::factory()->post->create(
			[
				'post_type' => 'page',
				'post_name' => 'old',
			]
		);
		$this->ledger->record( $old, 'draft-path' );

		$draft = self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_name'   => 'draft-path',
				'post_status' => 'draft',
			]
		);
		$this->assertSame( [ 'draft-path' ], $this->ledger->paths_for( $old ), 'A draft shows nothing at the path.' );

		wp_publish_post( $draft );

		$this->assertSame( [], $this->ledger->paths_for( $old ), 'Publishing takes it.' );
	}

	public function test_claim_is_one_seek_on_the_key(): void {
		global $wpdb;

		$post = get_post( $this->about );
		get_option( Ledger::ROOT_OPTION );
		$queries = $wpdb->num_queries;
		$this->ledger->claim( $post );

		$this->assertSame( 1, $wpdb->num_queries - $queries, 'Nothing to drop costs one query.' );

		wp_cache_delete( Ledger::ROOT_OPTION, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		$queries = $wpdb->num_queries;
		$this->ledger->claim( $post );

		$this->assertSame( 2, $wpdb->num_queries - $queries, 'The root list not read yet costs its option read too.' );
	}
}
