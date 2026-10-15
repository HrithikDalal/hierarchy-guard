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
		delete_option( Ledger::ROOT_TARGETS );
		delete_option( Ledger::RECENT );
		delete_option( Ledger::RECENT_STATE );
		wp_clear_scheduled_hook( Ledger::REBUILD_HOOK );
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

	public function test_an_override_sits_beside_its_record_with_the_same_hash(): void {
		$this->ledger->record( $this->about, 'about-us' );
		$this->ledger->set_target( 'page', '/About-Us/', $this->about, 'https://example.org/jobs/', 302 );

		$this->assertSame( Ledger::TARGET_PREFIX . md5( 'page:about-us' ), Ledger::target_key( 'page', 'about-us' ) );
		$this->assertSame(
			[
				'url'  => 'https://example.org/jobs/',
				'code' => 302,
			],
			get_post_meta( $this->about, Ledger::target_key( 'page', 'about-us' ), true )
		);
		$this->assertSame(
			[
				'url'  => 'https://example.org/jobs/',
				'code' => 302,
			],
			$this->ledger->target( 'page', 'about-us', $this->about )
		);
		$this->assertSame( [ 'about-us' ], $this->ledger->paths_for( $this->about ), 'The override is not a record of its own.' );
		$this->assertNull( $this->ledger->target( 'page', 'elsewhere', $this->about ) );
	}

	public function test_an_override_with_nothing_left_is_removed(): void {
		$this->ledger->record( $this->about, 'about-us' );
		$this->ledger->set_target( 'page', 'about-us', $this->about, '', 301 );
		$this->assertSame(
			[
				'url'  => '',
				'code' => 301,
			],
			$this->ledger->target( 'page', 'about-us', $this->about ),
			'A chosen code alone is an override.'
		);

		$this->ledger->set_target( 'page', 'about-us', $this->about, '', 0 );

		$this->assertFalse( metadata_exists( 'post', $this->about, Ledger::target_key( 'page', 'about-us' ) ) );
		$this->assertNull( $this->ledger->target( 'page', 'about-us', $this->about ) );
	}

	public function test_clean_target_keeps_only_http_urls_and_the_two_codes(): void {
		$this->assertNull( Ledger::clean_target( 'https://example.org/' ) );
		$this->assertNull(
			Ledger::clean_target(
				[
					'url'  => 'javascript:alert(1)',
					'code' => 307,
				]
			)
		);
		$this->assertSame(
			[
				'url'  => '',
				'code' => 302,
			],
			Ledger::clean_target(
				[
					'url'  => 'ftp://example.org/',
					'code' => '302',
				]
			)
		);
		$this->assertSame(
			[
				'url'  => 'HTTP://example.org/',
				'code' => 0,
			],
			Ledger::clean_target( [ 'url' => ' HTTP://example.org/ ' ] )
		);
	}

	public function test_a_root_entry_s_override_lives_in_its_own_option(): void {
		global $wpdb;

		$this->ledger->record_root( 'page', 'archive' );
		$this->ledger->set_target( 'page', '/Archive/', 0, 'https://example.org/archive/', 0 );

		$this->assertSame(
			[
				'page' => [
					'archive' => [
						'url'  => 'https://example.org/archive/',
						'code' => 0,
					],
				],
			],
			get_option( Ledger::ROOT_TARGETS )
		);
		$this->assertSame( 'https://example.org/archive/', $this->ledger->target( 'page', 'archive', 0 )['url'] );
		$this->assertNull( $this->ledger->target( 'hg_docs', 'archive', 0 ), 'Overrides are scoped by post type.' );
		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", Ledger::ROOT_TARGETS ) );
		$this->assertContains( $autoload, [ 'no', 'off' ], 'The option is not autoloaded.' );

		$this->ledger->set_target( 'page', 'archive', 0, '', 0 );
		$this->assertFalse( get_option( Ledger::ROOT_TARGETS ), 'The last override gone, the option goes too.' );
	}

	public function test_forgetting_or_pruning_a_record_deletes_its_override(): void {
		global $wpdb;

		$this->ledger->record( $this->about, 'gone' );
		$this->ledger->set_target( 'page', 'gone', $this->about, '', 302 );
		$this->ledger->record( $this->about, 'old-about' );
		$this->ledger->set_target( 'page', 'old-about', $this->about, 'https://example.org/', 0 );

		$this->assertTrue( $this->ledger->forget( $this->about, 'gone' ) );
		$this->assertFalse( metadata_exists( 'post', $this->about, Ledger::target_key( 'page', 'gone' ) ) );

		$wpdb->update( $wpdb->posts, [ 'post_name' => 'old-about' ], [ 'ID' => $this->about ] );
		clean_post_cache( $this->about );
		$this->assertSame( 1, $this->ledger->prune( $this->about ) );
		$this->assertFalse( metadata_exists( 'post', $this->about, Ledger::target_key( 'page', 'old-about' ) ) );
	}

	public function test_a_root_entry_forgotten_or_pushed_out_by_the_cap_loses_its_override(): void {
		$this->ledger->record_root( 'page', 'first' );
		$this->ledger->record_root( 'page', 'second' );
		$this->ledger->set_target( 'page', 'first', 0, '', 301 );
		$this->ledger->set_target( 'page', 'second', 0, '', 302 );

		$this->assertTrue( $this->ledger->forget_root( 'page', 'second' ) );
		$this->assertNull( $this->ledger->target( 'page', 'second', 0 ) );
		$this->assertSame( 301, $this->ledger->target( 'page', 'first', 0 )['code'] );

		for ( $i = 0; $i < Ledger::ROOT_CAP; $i++ ) {
			$this->ledger->record_root( 'page', 'later-' . $i );
		}
		$this->assertNull( $this->ledger->find( 'page', 'first' ), 'Precondition: the oldest entry fell off the end.' );
		$this->assertFalse( get_option( Ledger::ROOT_TARGETS ), 'Its override went with it.' );
	}

	public function test_a_page_that_takes_a_path_drops_the_overrides_with_the_entries(): void {
		$moved = self::factory()->post->create(
			[
				'post_type' => 'page',
				'post_name' => 'moved',
			]
		);
		$this->ledger->record( $moved, 'taken' );
		$this->ledger->set_target( 'page', 'taken', $moved, 'https://example.org/', 0 );
		$this->ledger->record_root( 'page', 'taken' );
		$this->ledger->set_target( 'page', 'taken', 0, '', 302 );

		self::factory()->post->create(
			[
				'post_type' => 'page',
				'post_name' => 'taken',
			]
		);

		$this->assertFalse( metadata_exists( 'post', $moved, Ledger::target_key( 'page', 'taken' ) ) );
		$this->assertFalse( get_option( Ledger::ROOT_TARGETS ) );
	}

	public function test_an_override_is_read_once_and_re_read_after_any_ledger_write(): void {
		$this->ledger->record( $this->about, 'about-us' );
		$this->assertNull( $this->ledger->target( 'page', 'about-us', $this->about ) );

		$queries = get_num_queries();
		$this->assertNull( $this->ledger->target( 'page', 'about-us', $this->about ) );
		$this->assertSame( $queries, get_num_queries(), 'A repeated read is served from the cache.' );

		$before = wp_cache_get_last_changed( Ledger::CACHE_GROUP );
		$this->ledger->set_target( 'page', 'about-us', $this->about, '', 302 );
		$this->assertNotSame( $before, wp_cache_get_last_changed( Ledger::CACHE_GROUP ), 'An edit bumps the ledger cache key.' );
		$this->assertSame( 302, $this->ledger->target( 'page', 'about-us', $this->about )['code'] );
	}

	/**
	 * The meta ID of a post's entry for a path.
	 *
	 * @param int    $post_id Post.
	 * @param string $path    Normalized path.
	 * @param string $type    Post type.
	 * @return int
	 */
	private function meta_id( int $post_id, string $path, string $type = 'page' ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", $post_id, Ledger::key( $type, $path ) ) );
	}

	public function test_each_entry_recorded_goes_first_on_its_type_s_newest_list(): void {
		global $wpdb;

		$doc = self::factory()->post->create(
			[
				'post_type' => 'hg_docs',
				'post_name' => 'guide',
			]
		);
		$this->ledger->record( $this->about, 'one' );
		$this->ledger->record( $doc, 'manual' );
		$this->ledger->record( $this->about, 'two' );

		$one    = $this->meta_id( $this->about, 'one' );
		$two    = $this->meta_id( $this->about, 'two' );
		$manual = $this->meta_id( $doc, 'manual', 'hg_docs' );
		$this->assertSame(
			[
				'ids'  => [ $two, $one ],
				'more' => false,
			],
			$this->ledger->recent( [ 'page' ] )
		);
		$this->assertSame( [ $two, $manual, $one ], $this->ledger->recent( [ 'page', 'hg_docs' ] )['ids'], 'Types merge newest first.' );
		$this->assertSame( [], $this->ledger->recent( [ 'post' ] )['ids'] );
		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", Ledger::RECENT ) );
		$this->assertContains( $autoload, [ 'no', 'off' ], 'The list is not autoloaded.' );
	}

	public function test_the_newest_list_keeps_the_cap_and_says_older_entries_exist(): void {
		$cap = static fn(): int => 2;
		add_filter( 'hierarchy_guard_count_cap', $cap );
		foreach ( [ 'one', 'two', 'three' ] as $path ) {
			$this->ledger->record( $this->about, $path );
		}
		remove_filter( 'hierarchy_guard_count_cap', $cap );

		$this->assertSame(
			[
				'ids'  => [ $this->meta_id( $this->about, 'three' ), $this->meta_id( $this->about, 'two' ) ],
				'more' => true,
			],
			$this->ledger->recent( [ 'page' ] )
		);
	}

	public function test_an_entry_leaves_the_newest_list_however_its_row_goes(): void {
		global $wpdb;

		$other = self::factory()->post->create(
			[
				'post_type' => 'page',
				'post_name' => 'other',
			]
		);
		foreach ( [ 'forgotten', 'pruned', 'taken', 'kept' ] as $path ) {
			$this->ledger->record( $this->about, $path );
		}
		$this->ledger->record( $other, 'deleted-with-its-page' );
		$kept = $this->meta_id( $this->about, 'kept' );

		$this->ledger->forget( $this->about, 'forgotten' );
		$wpdb->update( $wpdb->posts, [ 'post_name' => 'pruned' ], [ 'ID' => $this->about ] );
		clean_post_cache( $this->about );
		$this->ledger->prune( $this->about );
		self::factory()->post->create(
			[
				'post_type' => 'page',
				'post_name' => 'taken',
			]
		);
		wp_delete_post( $other, true );

		$this->assertSame( [ $kept ], $this->ledger->recent( [ 'page' ] )['ids'] );
	}

	public function test_entries_recorded_in_a_batch_join_the_newest_list_at_its_end(): void {
		$inside = null;
		Ledger::deferred(
			function () use ( &$inside ): void {
				$this->ledger->record( $this->about, 'one' );
				$this->ledger->record( $this->about, 'two' );
				$inside = get_option( Ledger::RECENT );
			}
		);

		$this->assertFalse( $inside, 'Nothing is written while deferred.' );
		$this->assertSame( [ $this->meta_id( $this->about, 'two' ), $this->meta_id( $this->about, 'one' ) ], $this->ledger->recent( [ 'page' ] )['ids'] );
	}

	/**
	 * An entry written straight into post meta, as records made before the
	 * newest-records list existed are: not on the list.
	 *
	 * @param int    $post_id Post.
	 * @param string $path    Normalized path.
	 * @return int Meta ID.
	 */
	private function old_entry( int $post_id, string $path ): int {
		return (int) add_post_meta( $post_id, Ledger::key( 'page', $path ), $path, true );
	}

	public function test_records_made_before_the_list_reach_it_on_the_first_rebuild(): void {
		$first  = $this->old_entry( $this->about, 'first' );
		$second = $this->old_entry( $this->about, 'second' );
		$this->assertSame( [], $this->ledger->recent( [ 'page' ] )['ids'], 'Precondition: not on the list.' );

		Ledger::schedule_rebuild();
		$this->assertTrue( Ledger::building(), 'Until the first build, the tab says the list is being built.' );
		$this->assertLessThanOrEqual( time(), wp_next_scheduled( Ledger::REBUILD_HOOK ), 'The first build runs on the next cron run.' );
		$this->assertSame( 'daily', wp_get_schedule( Ledger::REBUILD_HOOK ), 'Then once a day.' );

		$this->assertSame( 'hierarchy_guard_rebuild_recent', Ledger::REBUILD_HOOK );
		do_action( 'hierarchy_guard_rebuild_recent' );

		$this->assertSame( [ $second, $first ], $this->ledger->recent( [ 'page' ] )['ids'] );
		$this->assertFalse( Ledger::building() );
		$this->assertSame( 'done:page', get_option( Ledger::RECENT_STATE ), 'Done, for the types it covered.' );
	}

	public function test_the_rebuild_is_idempotent_keeps_what_was_recorded_and_stops_at_the_cap(): void {
		$old = [];
		foreach ( [ 'a', 'b', 'c' ] as $path ) {
			$old[] = $this->old_entry( $this->about, $path );
		}
		$this->ledger->record( $this->about, 'meanwhile' );
		$meanwhile = $this->meta_id( $this->about, 'meanwhile' );

		Ledger::rebuild_recent();
		$once = get_option( Ledger::RECENT );
		Ledger::rebuild_recent();

		$this->assertSame( $once, get_option( Ledger::RECENT ), 'Running it again changes nothing.' );
		$this->assertSame( [ $meanwhile, $old[2], $old[1], $old[0] ], $this->ledger->recent( [ 'page' ] )['ids'] );

		delete_option( Ledger::RECENT );
		$cap = static fn(): int => 2;
		add_filter( 'hierarchy_guard_count_cap', $cap );
		Ledger::rebuild_recent();
		remove_filter( 'hierarchy_guard_count_cap', $cap );
		$this->assertSame(
			[
				'ids'  => [ $meanwhile, $old[2] ],
				'more' => true,
			],
			$this->ledger->recent( [ 'page' ] ),
			'One bounded query per type: the newest, and whether there are older ones.'
		);
	}

	public function test_once_built_a_normal_load_runs_nothing(): void {
		global $wp_filter;

		Ledger::schedule_rebuild();
		Ledger::rebuild_recent();
		$next = wp_next_scheduled( Ledger::REBUILD_HOOK );

		$queries = get_num_queries();
		Ledger::schedule_rebuild();
		$this->assertSame( $queries, get_num_queries(), 'Reads of autoloaded options only.' );
		$this->assertSame( $next, wp_next_scheduled( Ledger::REBUILD_HOOK ), 'The daily run is left where it is.' );

		wp_clear_scheduled_hook( Ledger::REBUILD_HOOK );
		Ledger::schedule_rebuild();
		$this->assertSame( 'daily', wp_get_schedule( Ledger::REBUILD_HOOK ), 'A missing daily run is scheduled again.' );
		$this->assertGreaterThan( time() + HOUR_IN_SECONDS, wp_next_scheduled( Ledger::REBUILD_HOOK ), 'A day ahead: the list is built.' );
		$this->assertFalse( Ledger::building() );

		// At any priority.
		$hooked = static function ( string $hook, string $method ) use ( $wp_filter ): bool {
			foreach ( isset( $wp_filter[ $hook ] ) ? $wp_filter[ $hook ]->callbacks : [] as $callbacks ) {
				foreach ( $callbacks as $callback ) {
					if ( [ Ledger::class, $method ] === $callback['function'] ) {
						return true;
					}
				}
			}
			return false;
		};
		$this->assertTrue( $hooked( 'admin_init', 'schedule_rebuild' ) );
		$this->assertTrue( $hooked( Ledger::REBUILD_HOOK, 'rebuild_recent' ) );
		foreach ( [ 'init', 'wp', 'template_redirect', 'admin_init' ] as $hook ) {
			$this->assertFalse( $hooked( $hook, 'rebuild_recent' ), "The rebuild never runs on $hook." );
		}
	}

	public function test_a_first_build_whose_query_fails_is_not_done_and_runs_again(): void {
		global $wpdb;

		$first = $this->old_entry( $this->about, 'first' );
		Ledger::schedule_rebuild();
		$break  = static fn( string $query ): string => str_contains( $query, 'ORDER BY pm.meta_id DESC LIMIT' ) ? 'SELECT nope FROM nowhere' : $query;
		add_filter( 'query', $break );
		$errors = $wpdb->suppress_errors( true );
		do_action( 'hierarchy_guard_rebuild_recent' );
		$wpdb->suppress_errors( $errors );
		remove_filter( 'query', $break );

		$this->assertTrue( Ledger::building(), 'A failed query does not mark the list built.' );
		wp_clear_scheduled_hook( Ledger::REBUILD_HOOK );
		wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', Ledger::REBUILD_HOOK );
		Ledger::schedule_rebuild();
		$this->assertLessThanOrEqual( time() + MINUTE_IN_SECONDS, wp_next_scheduled( Ledger::REBUILD_HOOK ), 'The next admin load has it run again soon.' );

		do_action( 'hierarchy_guard_rebuild_recent' );
		$this->assertFalse( Ledger::building() );
		$this->assertSame( [ $first ], $this->ledger->recent( [ 'page' ] )['ids'] );
	}

	public function test_a_type_supported_after_the_first_build_is_built_then(): void {
		$doc   = self::factory()->post->create(
			[
				'post_type' => 'hg_docs',
				'post_name' => 'guide',
			]
		);
		$entry = (int) add_post_meta( $doc, Ledger::key( 'hg_docs', 'manual' ), 'manual', true );
		$only  = static fn(): array => [ 'page' ];
		add_filter( 'hierarchy_guard_post_types', $only );
		Post_Types::reset();
		Ledger::schedule_rebuild();
		do_action( 'hierarchy_guard_rebuild_recent' );
		$this->assertFalse( Ledger::building() );
		$this->assertSame( [], $this->ledger->recent( [ 'hg_docs' ] )['ids'], 'Precondition: the type was not supported then.' );

		remove_filter( 'hierarchy_guard_post_types', $only );
		$both = static fn(): array => [ 'page', 'hg_docs' ];
		add_filter( 'hierarchy_guard_post_types', $both );
		Post_Types::reset();
		Ledger::schedule_rebuild();

		$this->assertTrue( Ledger::building(), 'The list is built again for the type now supported.' );
		do_action( 'hierarchy_guard_rebuild_recent' );
		remove_filter( 'hierarchy_guard_post_types', $both );
		Post_Types::reset();
		$this->assertSame( [ $entry ], $this->ledger->recent( [ 'hg_docs' ] )['ids'] );
	}

	public function test_a_full_list_that_loses_entries_is_filled_again_from_older_ones(): void {
		$cap = static fn(): int => 2;
		add_filter( 'hierarchy_guard_count_cap', $cap );
		foreach ( [ 'one', 'two', 'three' ] as $path ) {
			$this->ledger->record( $this->about, $path );
		}
		Ledger::rebuild_recent();
		$this->assertTrue( $this->ledger->recent( [ 'page' ] )['more'], 'Precondition: older entries exist than the list holds.' );

		wp_clear_scheduled_hook( Ledger::REBUILD_HOOK );
		wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', Ledger::REBUILD_HOOK );

		$this->ledger->forget( $this->about, 'three' );

		$this->assertLessThanOrEqual( time() + MINUTE_IN_SECONDS, wp_next_scheduled( Ledger::REBUILD_HOOK ), 'The list fell below the cap with older entries left out: the rebuild runs soon.' );
		$this->assertSame( 'daily', wp_get_schedule( Ledger::REBUILD_HOOK ), 'And stays daily.' );
		$this->assertFalse( Ledger::building(), 'Quietly: the tab does not say the list is being built for a refill.' );
		do_action( 'hierarchy_guard_rebuild_recent' );
		remove_filter( 'hierarchy_guard_count_cap', $cap );
		$this->assertSame(
			[
				'ids'  => [ $this->meta_id( $this->about, 'two' ), $this->meta_id( $this->about, 'one' ) ],
				'more' => false,
			],
			$this->ledger->recent( [ 'page' ] ),
			'Every entry fits now, so the list no longer says older ones exist.'
		);
	}

	public function test_a_short_list_of_a_type_no_longer_supported_is_not_filled_again(): void {
		$this->ledger->record( $this->about, 'one' );
		$one = $this->meta_id( $this->about, 'one' );
		update_option(
			Ledger::RECENT,
			[
				'retired' => [
					'ids'  => [ $one ],
					'more' => true,
				],
			],
			false
		);
		wp_clear_scheduled_hook( Ledger::REBUILD_HOOK );
		wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', Ledger::REBUILD_HOOK );

		$this->ledger->forget( $this->about, 'one' );

		$this->assertSame( [], $this->ledger->recent( [ 'retired' ] )['ids'], 'Precondition: the entry left the list.' );
		$this->assertGreaterThan( time() + HOUR_IN_SECONDS, wp_next_scheduled( Ledger::REBUILD_HOOK ), 'The rebuild reads supported types only, so it is not brought forward for this one.' );
	}

	public function test_the_daily_rebuild_restores_an_entry_a_lost_update_dropped(): void {
		$this->ledger->record( $this->about, 'one' );
		$this->ledger->record( $this->about, 'two' );
		Ledger::schedule_rebuild();
		Ledger::rebuild_recent();
		$one = $this->meta_id( $this->about, 'one' );
		// Two writes at the same moment: one of them is lost.
		update_option(
			Ledger::RECENT,
			[
				'page' => [
					'ids'  => [ $one ],
					'more' => false,
				],
			],
			false
		);

		do_action( 'hierarchy_guard_rebuild_recent' );

		$this->assertSame( [ $this->meta_id( $this->about, 'two' ), $one ], $this->ledger->recent( [ 'page' ] )['ids'] );
		$this->assertFalse( Ledger::building(), 'A daily rebuild is quiet.' );
	}

	public function test_the_daily_rebuild_drops_an_entry_whose_row_is_gone(): void {
		global $wpdb;

		$this->ledger->record( $this->about, 'one' );
		$this->ledger->record( $this->about, 'two' );
		$two = $this->meta_id( $this->about, 'two' );
		// Deleted without its hook, as a direct query does.
		$wpdb->delete( $wpdb->postmeta, [ 'meta_id' => $two ] );
		$this->assertContains( $two, $this->ledger->recent( [ 'page' ] )['ids'], 'Precondition: still listed.' );

		do_action( 'hierarchy_guard_rebuild_recent' );

		$this->assertSame( [ $this->meta_id( $this->about, 'one' ) ], $this->ledger->recent( [ 'page' ] )['ids'] );
	}

	public function test_a_write_to_the_newest_list_keeps_what_another_request_wrote_meanwhile(): void {
		global $wpdb;

		$this->ledger->record( $this->about, 'one' );
		$one = $this->meta_id( $this->about, 'one' );
		get_option( Ledger::RECENT );
		// Another request records an entry: the row is in the database, this request's cache is stale.
		$other = (int) add_post_meta( $this->about, Ledger::key( 'page', 'other' ), 'other', true );
		$wpdb->update(
			$wpdb->options,
			[
				'option_value' => maybe_serialize(
					[
						'page' => [
							'ids'  => [ $other, $one ],
							'more' => false,
						],
					]
				),
			],
			[ 'option_name' => Ledger::RECENT ]
		);

		$this->ledger->record( $this->about, 'two' );

		$this->assertSame( [ $this->meta_id( $this->about, 'two' ), $other, $one ], $this->ledger->recent( [ 'page' ] )['ids'] );
	}

	public function test_a_write_keeps_the_newest_entries_whatever_order_the_list_was_in(): void {
		$cap = static fn(): int => 2;
		add_filter( 'hierarchy_guard_count_cap', $cap );
		foreach ( [ 'one', 'two', 'three' ] as $path ) {
			$this->ledger->record( $this->about, $path );
		}
		$one   = $this->meta_id( $this->about, 'one' );
		$three = $this->meta_id( $this->about, 'three' );
		// Out of order, as a merge of two lists could leave it.
		update_option(
			Ledger::RECENT,
			[
				'page' => [
					'ids'  => [ $one, $three ],
					'more' => true,
				],
			],
			false
		);

		$this->ledger->record( $this->about, 'four' );
		remove_filter( 'hierarchy_guard_count_cap', $cap );

		$this->assertSame( [ $this->meta_id( $this->about, 'four' ), $three ], $this->ledger->recent( [ 'page' ] )['ids'], 'Newest first before the cap is applied, so the oldest goes.' );
	}

	public function test_deactivation_clears_the_rebuild_and_an_unfinished_first_build(): void {
		Ledger::schedule_rebuild();

		Ledger::unschedule_rebuild();

		$this->assertFalse( wp_next_scheduled( Ledger::REBUILD_HOOK ) );
		$this->assertFalse( get_option( Ledger::RECENT_STATE ), 'The next activation schedules it again.' );
	}

	public function test_an_entry_recorded_and_deleted_in_the_same_batch_never_reaches_the_newest_list(): void {
		$kept = null;
		Ledger::deferred(
			function () use ( &$kept ): void {
				$this->ledger->record( $this->about, 'kept' );
				$this->ledger->record( $this->about, 'forgotten' );
				$this->ledger->record( $this->about, 'pruned' );
				$kept = $this->meta_id( $this->about, 'kept' );

				$this->ledger->forget( $this->about, 'forgotten' );
				global $wpdb;
				$wpdb->update( $wpdb->posts, [ 'post_name' => 'pruned' ], [ 'ID' => $this->about ] );
				clean_post_cache( $this->about );
				$this->ledger->prune( $this->about );
			}
		);

		$this->assertSame( [ $kept ], $this->ledger->recent( [ 'page' ] )['ids'], 'Only the entry that still exists is listed.' );
	}
}
