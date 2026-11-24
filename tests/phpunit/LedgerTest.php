<?php
/**
 * The old-path ledger.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Ledger;
use HierarchyGuard\Meta_Guard;
use HierarchyGuard\Plugin;
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

	/**
	 * Each method that changes an old URL's record, and the URL it purges.
	 *
	 * @return array<string,string[]>
	 */
	public static function purge_sites(): array {
		return [
			'record'                   => [ '/old-about/' ],
			'record_root'              => [ '/section/' ],
			'set_target'               => [ '/old-about/' ],
			'forget'                   => [ '/old-about/' ],
			'forget_root'              => [ '/section/' ],
			'claim'                    => [ '/spot/' ],
			'prune'                    => [ '/about-us/' ],
			'record_root past the cap' => [ '/root-1/', '/newest/' ],
		];
	}

	/**
	 * Each method alone, after this request's purges are forgotten, so the
	 * URL it purges is its own. In a process of its own, so VIP's functions
	 * exist for this test only.
	 *
	 * @dataProvider purge_sites
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @param string ...$urls The URLs the method purges, in order.
	 */
	public function test_each_change_to_an_old_url_s_record_purges_it_from_a_vip_edge_cache( string ...$urls ): void {
		global $wpdb;
		require_once __DIR__ . '/stubs/vip.php';
		$site = $this->dataName();
		switch ( $site ) {
			case 'set_target':
			case 'forget':
				$this->ledger->record( $this->about, 'old-about' );
				break;
			case 'forget_root':
				$this->ledger->record_root( 'page', 'section' );
				break;
			case 'claim':
				$this->ledger->record( $this->about, 'spot' );
				$spot = self::factory()->post->create(
					[
						'post_type' => 'page',
						'post_name' => 'spot-new',
					]
				);
				// Takes the path without a save, so only claim() acts on it.
				$wpdb->update( $wpdb->posts, [ 'post_name' => 'spot' ], [ 'ID' => $spot ] );
				clean_post_cache( $spot );
				break;
			case 'record_root past the cap':
				update_option( Ledger::ROOT_OPTION, [ 'page' => array_map( static fn( int $i ): string => "root-{$i}", range( 1, Ledger::ROOT_CAP ) ) ], false );
				break;
			case 'prune':
				$this->ledger->record( $this->about, 'about-us' );
				// Back at the recorded path without a save, so only prune() acts on it.
				$wpdb->update( $wpdb->posts, [ 'post_name' => 'about-us' ], [ 'ID' => $this->about ] );
				clean_post_cache( $this->about );
				break;
		}
		$this->forget_purges();

		switch ( $site ) {
			case 'record':
				$this->ledger->record( $this->about, 'old-about' );
				break;
			case 'record_root':
				$this->ledger->record_root( 'page', 'section' );
				break;
			case 'set_target':
				$this->ledger->set_target( 'page', 'old-about', $this->about, 'https://example.org/', 302 );
				break;
			case 'forget':
				$this->ledger->forget( $this->about, 'old-about' );
				break;
			case 'forget_root':
				$this->ledger->forget_root( 'page', 'section' );
				break;
			case 'claim':
				$this->ledger->claim( get_post( $spot ) );
				break;
			case 'prune':
				$this->ledger->prune( $this->about );
				break;
			case 'record_root past the cap':
				$this->ledger->record_root( 'page', 'newest' );
				break;
		}

		$this->assertSame( array_map( 'home_url', $urls ), $GLOBALS['hierarchy_guard_purged'], 'VIP\'s current function, for each URL this method changed.' );
		$this->assertSame( [], $GLOBALS['hierarchy_guard_purged_deprecated'] );
	}

	/**
	 * In a process of its own, so VIP's functions exist for this test only.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_request_purges_an_old_url_once(): void {
		require_once __DIR__ . '/stubs/vip.php';
		$this->forget_purges();

		$this->ledger->record( $this->about, 'old-about' );
		$this->ledger->set_target( 'page', 'old-about', $this->about, 'https://example.org/', 302 );
		$this->ledger->forget( $this->about, 'old-about' );

		$this->assertSame( [ home_url( '/old-about/' ) ], $GLOBALS['hierarchy_guard_purged'] );
	}

	/**
	 * In a process of its own, with only the purge function VIP deprecated.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_vip_site_with_only_the_deprecated_purge_function_still_purges(): void {
		require_once __DIR__ . '/stubs/vip-deprecated.php';
		$this->forget_purges();

		$this->ledger->record( $this->about, 'old-about' );

		$this->assertFalse( function_exists( 'wpvip_purge_edge_cache_for_url' ), 'Precondition: an older VIP platform.' );
		$this->assertSame( [ home_url( '/old-about/' ) ], $GLOBALS['hierarchy_guard_purged_deprecated'] );
	}

	/**
	 * In a process of its own, so VIP's functions exist for this test only.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_page_moved_back_to_its_old_url_purges_that_url_from_a_vip_edge_cache(): void {
		require_once __DIR__ . '/stubs/vip.php';
		wp_update_post(
			[
				'ID'        => $this->about,
				'post_name' => 'about-us',
			]
		);
		$this->forget_purges();

		wp_update_post(
			[
				'ID'        => $this->about,
				'post_name' => 'about',
			]
		);

		$this->assertSame( [ home_url( '/about-us/' ), home_url( '/about/' ) ], $GLOBALS['hierarchy_guard_purged'], 'The edge forgets the redirect it keeps for /about/, which now leads to the page itself.' );
	}

	/**
	 * In a process of its own, so VIP's functions exist for this test only.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_request_purges_at_most_fifty_old_urls_so_vip_keeps_its_own(): void {
		require_once __DIR__ . '/stubs/vip.php';
		$this->forget_purges();

		for ( $i = 1; $i <= 60; $i++ ) {
			$this->ledger->record( $this->about, "old-{$i}" );
		}

		$this->assertCount( 50, $GLOBALS['hierarchy_guard_purged'] );
		$this->assertSame( home_url( '/old-50/' ), end( $GLOBALS['hierarchy_guard_purged'] ) );
	}

	/**
	 * Clears what the VIP stubs recorded and what this request purged.
	 */
	private function forget_purges(): void {
		$GLOBALS['hierarchy_guard_purged']            = [];
		$GLOBALS['hierarchy_guard_purged_deprecated'] = [];
		$purged                                       = new \ReflectionProperty( Ledger::class, 'purged' );
		$purged->setAccessible( true );
		$purged->setValue( null, [] );
	}

	public function test_a_url_target_on_this_site_is_kept_as_a_path_and_follows_the_site_to_a_new_domain(): void {
		$this->ledger->record( $this->about, 'old-about' );
		$this->ledger->set_target( 'page', 'old-about', $this->about, home_url( '/news/?ref=old#latest' ), 302 );
		$this->ledger->record_root( 'page', 'archive' );
		$this->ledger->set_target( 'page', 'archive', 0, home_url( '/blog/' ), 0 );
		$this->ledger->record( $this->about, 'elsewhere' );
		$this->ledger->set_target( 'page', 'elsewhere', $this->about, 'https://jobs.example.org/open/', 0 );

		$this->assertSame( '/news/?ref=old#latest', get_post_meta( $this->about, Ledger::target_key( 'page', 'old-about' ), true )['url'], 'Stored as a path.' );
		$this->assertSame( '/blog/', get_option( Ledger::ROOT_TARGETS )['page']['archive']['url'] );
		$this->assertSame( 'https://jobs.example.org/open/', get_post_meta( $this->about, Ledger::target_key( 'page', 'elsewhere' ), true )['url'], 'Another site’s URL stays as it is.' );

		$moved = static fn(): string => 'https://new.example.com';
		add_filter( 'pre_option_home', $moved );
		wp_cache_set_last_changed( Ledger::CACHE_GROUP );
		$target = $this->ledger->target( 'page', 'old-about', $this->about );
		$root   = $this->ledger->target( 'page', 'archive', 0 );
		remove_filter( 'pre_option_home', $moved );

		$this->assertSame(
			[
				'url'  => 'https://new.example.com/news/?ref=old#latest',
				'code' => 302,
			],
			$target,
			'Read against the home URL, as after an import on a new domain.'
		);
		$this->assertSame( 'https://new.example.com/blog/', $root['url'] );
	}

	public function test_a_target_kept_as_a_path_is_read_with_the_home_url_s_scheme_whatever_the_request_s(): void {
		$https = $_SERVER['HTTPS'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Saved as it was, to put back.
		$home  = static fn(): string => 'http://example.org';
		add_filter( 'pre_option_home', $home );
		try {
			$this->ledger->record( $this->about, 'old-about' );
			$this->ledger->set_target( 'page', 'old-about', $this->about, 'http://example.org/news/?ref=old#latest', 0 );
			$_SERVER['HTTPS'] = 'on';
			wp_cache_set_last_changed( Ledger::CACHE_GROUP );

			$this->assertSame( 'http://example.org/news/?ref=old#latest', $this->ledger->target( 'page', 'old-about', $this->about )['url'], 'An admin forced to SSL reads what the home URL says.' );
		} finally {
			remove_filter( 'pre_option_home', $home );
			if ( null === $https ) {
				unset( $_SERVER['HTTPS'] );
			} else {
				$_SERVER['HTTPS'] = $https;
			}
		}
	}

	/**
	 * URLs typed for a site at http://example.org/blog, and what is stored.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function same_site_forms(): array {
		return [
			'the home URL without its slash' => [ 'http://example.org/blog', '/', 'http://example.org/blog/' ],
			'the home URL with its slash'    => [ 'http://example.org/blog/', '/', 'http://example.org/blog/' ],
			'the home URL with a query'      => [ 'http://example.org/blog?ref=1', '/?ref=1', 'http://example.org/blog/?ref=1' ],
			'a page, query and fragment'     => [ 'http://example.org/blog/about/?a=1#team', '/about/?a=1#team', 'http://example.org/blog/about/?a=1#team' ],
			'the other scheme'               => [ 'https://example.org/blog/about/', '/about/', 'http://example.org/blog/about/' ],
			'an upper-case host'             => [ 'http://EXAMPLE.org/blog/about/', '/about/', 'http://example.org/blog/about/' ],
			'an upper-case home path'        => [ 'http://example.org/BLOG/about/', '/about/', 'http://example.org/blog/about/' ],
			'another port'                   => [ 'http://example.org:8080/blog/about/', 'http://example.org:8080/blog/about/', 'http://example.org:8080/blog/about/' ],
			'the www host'                   => [ 'http://www.example.org/blog/about/', 'http://www.example.org/blog/about/', 'http://www.example.org/blog/about/' ],
			'a path outside the home path'   => [ 'http://example.org/other/', 'http://example.org/other/', 'http://example.org/other/' ],
			'a path starting like the home'  => [ 'http://example.org/blogroll/', 'http://example.org/blogroll/', 'http://example.org/blogroll/' ],
			'a doubled slash after the home' => [ 'http://example.org/blog//about/?a=1', '/about/?a=1', 'http://example.org/blog/about/?a=1' ],
			'slashes that look like a host'  => [ 'http://example.org/blog///evil.example/x', '/evil.example/x', 'http://example.org/blog/evil.example/x' ],
		];
	}

	/**
	 * @dataProvider same_site_forms
	 *
	 * @param string $typed  URL entered as the target.
	 * @param string $stored What the record keeps.
	 * @param string $read   What it is read back as.
	 */
	public function test_a_target_on_a_site_in_a_subdirectory_is_kept_as_a_path_only_when_it_is_under_the_home_url( string $typed, string $stored, string $read ): void {
		$home = static fn(): string => 'http://example.org/blog';
		add_filter( 'pre_option_home', $home );
		$this->ledger->record( $this->about, 'old-about' );
		$this->ledger->set_target( 'page', 'old-about', $this->about, $typed, 0 );
		wp_cache_set_last_changed( Ledger::CACHE_GROUP );
		$target = $this->ledger->target( 'page', 'old-about', $this->about );
		remove_filter( 'pre_option_home', $home );

		$this->assertSame( $stored, get_post_meta( $this->about, Ledger::target_key( 'page', 'old-about' ), true )['url'] );
		$this->assertSame( $read, $target['url'] );
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
		return (int) Meta_Guard::writing( static fn() => add_post_meta( $post_id, Ledger::key( 'page', $path ), $path, true ) );
	}

	/**
	 * Runs the rebuild's WP-Cron event, as WP-Cron does.
	 */
	private static function run_rebuild(): void {
		do_action( Ledger::REBUILD_HOOK ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- The plugin's own hook, by its constant: hierarchy_guard_rebuild_recent.
	}

	/**
	 * The rebuild's events scheduled now, single or recurring.
	 *
	 * @return array<int,array<string,mixed>> Each event, with its time.
	 */
	private static function rebuild_events(): array {
		$out = [];
		foreach ( (array) _get_cron_array() as $time => $hooks ) {
			foreach ( (array) ( $hooks[ Ledger::REBUILD_HOOK ] ?? [] ) as $event ) {
				$out[] = [ 'time' => (int) $time ] + (array) $event;
			}
		}
		return $out;
	}

	public function test_a_site_without_entries_schedules_no_rebuild(): void {
		Ledger::schedule_rebuild();

		$this->assertSame( [], self::rebuild_events() );
		$this->assertFalse( get_option( Ledger::RECENT_STATE ) );
		$this->assertFalse( Ledger::building(), 'Nothing to build, so no notice.' );
	}

	public function test_the_first_entry_recorded_schedules_the_daily_rebuild(): void {
		$this->ledger->record( $this->about, 'first' );

		$events = self::rebuild_events();
		$this->assertCount( 1, $events );
		$this->assertGreaterThan( time() + DAY_IN_SECONDS - MINUTE_IN_SECONDS, $events[0]['time'] );
		$this->assertFalse( Ledger::building(), 'The entry is on the list already.' );
	}

	public function test_a_rebuild_that_finds_no_entry_schedules_nothing_after_it(): void {
		$this->ledger->record( $this->about, 'gone' );
		$this->ledger->forget( $this->about, 'gone' );
		wp_clear_scheduled_hook( Ledger::REBUILD_HOOK );

		self::run_rebuild();

		$this->assertSame( [], self::rebuild_events(), 'The next entry recorded schedules it again.' );
		$this->assertSame( 'done:page', get_option( Ledger::RECENT_STATE ) );
	}

	public function test_an_imported_entry_brings_the_rebuild_forward(): void {
		$this->ledger->record( $this->about, 'kept' );
		$this->assertGreaterThan( time() + HOUR_IN_SECONDS, wp_next_scheduled( Ledger::REBUILD_HOOK ), 'Precondition: a day ahead.' );

		$this->assertNull( ( new Meta_Guard() )->check( null, Ledger::key( 'page', 'imported' ), true ), 'An import may add the row.' );
		( new Meta_Guard() )->note_import( Ledger::key( 'page', 'imported' ), true );

		$this->assertLessThanOrEqual( time(), wp_next_scheduled( Ledger::REBUILD_HOOK ) );
		$this->assertCount( 1, self::rebuild_events() );
	}

	public function test_an_imported_entry_is_found_where_a_miss_was_cached(): void {
		$this->assertNull( $this->ledger->find( 'page', 'imported-about' ), 'Precondition: the miss is cached now.' );
		Meta_Guard::writing( fn() => add_post_meta( $this->about, Ledger::key( 'page', 'imported-about' ), 'imported-about' ) );
		$this->assertNull( $this->ledger->find( 'page', 'imported-about' ), 'Precondition: a row added without a bump is not seen.' );

		( new Meta_Guard() )->note_import( Ledger::key( 'page', 'imported-about' ), true );

		$this->assertSame( $this->about, $this->ledger->find( 'page', 'imported-about' ) );
	}

	public function test_a_row_added_outside_an_import_brings_nothing_forward(): void {
		$this->ledger->record( $this->about, 'kept' );
		$changed = wp_cache_get_last_changed( Ledger::CACHE_GROUP );

		( new Meta_Guard() )->note_import( Ledger::key( 'page', 'other' ), false );
		( new Meta_Guard() )->note_import( '_other_key', true );

		$this->assertSame( $changed, wp_cache_get_last_changed( Ledger::CACHE_GROUP ) );
		$this->assertGreaterThan( time() + HOUR_IN_SECONDS, wp_next_scheduled( Ledger::REBUILD_HOOK ) );
	}

	public function test_the_rebuild_reads_the_ledger_s_rows_through_the_meta_key_index(): void {
		global $wpdb;
		$this->ledger->record( $this->about, 'old-about' );
		$sql  = '';
		$keep = static function ( string $query ) use ( &$sql ): string {
			if ( str_contains( $query, 'ORDER BY pm.meta_id DESC LIMIT' ) ) {
				$sql = $query;
			}
			return $query;
		};
		add_filter( 'query', $keep );
		Ledger::rebuild_recent();
		remove_filter( 'query', $keep );

		$plan = $wpdb->get_results( 'EXPLAIN ' . $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The rebuild's own prepared query.
		$this->assertSame( 'meta_key', $plan[0]['key'], 'Not a walk of the primary key through every newer row of other meta.' );
		$this->assertSame( 'range', $plan[0]['type'] );
	}

	public function test_records_made_before_the_list_reach_it_on_the_first_rebuild(): void {
		$first  = $this->old_entry( $this->about, 'first' );
		$second = $this->old_entry( $this->about, 'second' );
		$this->assertSame( [], $this->ledger->recent( [ 'page' ] )['ids'], 'Precondition: not on the list.' );

		Ledger::schedule_rebuild();
		$this->assertTrue( Ledger::building(), 'Until the first build, the tab says the list is being built.' );
		$this->assertLessThanOrEqual( time(), wp_next_scheduled( Ledger::REBUILD_HOOK ), 'The first build runs on the next cron run.' );
		$this->assertFalse( wp_get_schedule( Ledger::REBUILD_HOOK ), 'A single event, which schedules the next one when it runs.' );

		$this->assertSame( 'hierarchy_guard_rebuild_recent', Ledger::REBUILD_HOOK );
		self::run_rebuild();

		$this->assertSame( [ $second, $first ], $this->ledger->recent( [ 'page' ] )['ids'] );
		$this->assertFalse( Ledger::building() );
		$this->assertSame( 'done:page', get_option( Ledger::RECENT_STATE ), 'Done, for the types it covered.' );
		$this->assertCount( 1, self::rebuild_events(), 'The run scheduled the next one.' );
	}

	public function test_the_rebuild_schedules_its_own_next_run_a_day_later_and_only_one(): void {
		$this->ledger->record( $this->about, 'kept' );
		wp_clear_scheduled_hook( Ledger::REBUILD_HOOK );

		self::run_rebuild();

		$events = self::rebuild_events();
		$this->assertCount( 1, $events );
		$this->assertFalse( $events[0]['schedule'] ?? false, 'A single event: once the plugin is inactive, nothing schedules it again.' );
		$this->assertGreaterThan( time() + DAY_IN_SECONDS - MINUTE_IN_SECONDS, $events[0]['time'] );

		self::run_rebuild();
		Ledger::schedule_rebuild();
		$this->ledger->record( $this->about, 'one' );
		$this->ledger->forget( $this->about, 'one' );
		$this->assertCount( 1, self::rebuild_events(), 'Never more than one.' );
	}

	/**
	 * Runs the rebuild while another request writes the list right after
	 * the rebuild's own write: $write runs once then, and returns the list
	 * that request writes.
	 *
	 * @param callable $write Returns the page list the other request writes: fn(): array{ids:int[],more:bool}.
	 */
	private function rebuild_with_a_write_after( callable $write ): void {
		global $wpdb;

		$after = static function () use ( $wpdb, $write ): void {
			static $done = false;
			if ( $done ) {
				return;
			}
			$done = true;
			$wpdb->update( $wpdb->options, [ 'option_value' => maybe_serialize( [ 'page' => $write() ] ) ], [ 'option_name' => Ledger::RECENT ] );
		};
		add_action( 'update_option_' . Ledger::RECENT, $after );
		self::run_rebuild();
		remove_action( 'update_option_' . Ledger::RECENT, $after );
		wp_cache_delete( Ledger::RECENT, 'options' );
	}

	public function test_the_merge_back_reads_more_from_the_rebuild_not_a_stale_write(): void {
		$a = $this->old_entry( $this->about, 'a' );
		$b = $this->old_entry( $this->about, 'b' );
		update_option( Ledger::RECENT, [], false );

		$this->rebuild_with_a_write_after(
			static fn(): array => [
				'ids'  => [ $b, $a ],
				'more' => true,
			]
		);

		$this->assertSame(
			[
				'ids'  => [ $b, $a ],
				'more' => false,
			],
			$this->ledger->recent( [ 'page' ] ),
			'Every entry fits: the other request’s more was read before the rebuild.'
		);
	}

	public function test_the_merge_back_keeps_an_entry_only_the_other_write_holds_while_its_row_exists(): void {
		$a = $this->old_entry( $this->about, 'a' );
		update_option( Ledger::RECENT, [], false );
		$late = 0;

		$this->rebuild_with_a_write_after(
			function () use ( $a, &$late ): array {
				// Recorded after the rebuild's query read the entries.
				$late = $this->old_entry( $this->about, 'late' );
				return [
					'ids'  => [ $late, $a ],
					'more' => false,
				];
			}
		);
		$this->assertSame( [ $late, $a ], $this->ledger->recent( [ 'page' ] )['ids'], 'Kept: its row exists.' );

		update_option( Ledger::RECENT, [], false );
		$gone = 0;
		$this->rebuild_with_a_write_after(
			function () use ( $a, &$gone ): array {
				global $wpdb;
				$gone = $this->old_entry( $this->about, 'gone' );
				$wpdb->delete( $wpdb->postmeta, [ 'meta_id' => $gone ] );
				return [
					'ids'  => [ $gone, $a ],
					'more' => false,
				];
			}
		);
		$this->assertNotContains( $gone, $this->ledger->recent( [ 'page' ] )['ids'], 'Dropped: its row was deleted.' );
		$this->assertContains( $late, $this->ledger->recent( [ 'page' ] )['ids'] );
	}

	public function test_a_write_made_while_the_rebuild_writes_does_not_undo_it(): void {
		global $wpdb;

		$a = $this->old_entry( $this->about, 'a' );
		$b = $this->old_entry( $this->about, 'b' );
		$this->ledger->record( $this->about, 'meanwhile' );
		$meanwhile = $this->meta_id( $this->about, 'meanwhile' );
		Ledger::schedule_rebuild();
		// Another request read the list before the rebuild wrote it, and writes after:
		// it records an entry, and its view knew nothing of a or b, whose row it deleted.
		$new   = (int) Meta_Guard::writing( fn() => add_post_meta( $this->about, Ledger::key( 'page', 'new' ), 'new', true ) );
		$after = static function () use ( $wpdb, $new, $meanwhile, $b ): void {
			static $done = false;
			if ( $done ) {
				return;
			}
			$done = true;
			$wpdb->delete( $wpdb->postmeta, [ 'meta_id' => $b ] );
			$wpdb->update(
				$wpdb->options,
				[
					'option_value' => maybe_serialize(
						[
							'page' => [
								'ids'  => [ $new, $meanwhile ],
								'more' => false,
							],
						]
					),
				],
				[ 'option_name' => Ledger::RECENT ]
			);
		};
		add_action( 'update_option_' . Ledger::RECENT, $after );

		self::run_rebuild();
		remove_action( 'update_option_' . Ledger::RECENT, $after );
		wp_cache_delete( Ledger::RECENT, 'options' );

		$this->assertSame( [ $new, $meanwhile, $a ], $this->ledger->recent( [ 'page' ] )['ids'], 'The rebuilt entries are merged back; the one whose row went is not.' );
		$this->assertFalse( Ledger::building(), 'Done once the merged list is written.' );
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

		$this->old_entry( $this->about, 'old' );
		Ledger::schedule_rebuild();
		Ledger::rebuild_recent();
		Plugin::setup();
		$next = wp_next_scheduled( Ledger::REBUILD_HOOK );

		$queries = get_num_queries();
		Plugin::maybe_upgrade();
		$this->assertSame( $queries, get_num_queries(), 'A read of an autoloaded option only.' );
		$this->assertSame( $next, wp_next_scheduled( Ledger::REBUILD_HOOK ), 'The daily run is left where it is.' );

		wp_clear_scheduled_hook( Ledger::REBUILD_HOOK );
		Ledger::schedule_rebuild();
		$this->assertCount( 1, self::rebuild_events(), 'A missing daily run is scheduled again.' );
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
		$this->assertFalse( $hooked( 'admin_init', 'schedule_rebuild' ), 'An admin load schedules nothing.' );
		$this->assertSame( 10, has_action( 'admin_init', [ Plugin::class, 'maybe_upgrade' ] ) );
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
		self::run_rebuild();
		$wpdb->suppress_errors( $errors );
		remove_filter( 'query', $break );

		$this->assertTrue( Ledger::building(), 'A failed query does not mark the list built.' );
		wp_clear_scheduled_hook( Ledger::REBUILD_HOOK );
		wp_schedule_single_event( time() + DAY_IN_SECONDS, Ledger::REBUILD_HOOK );
		Ledger::schedule_rebuild();
		$this->assertLessThanOrEqual( time() + MINUTE_IN_SECONDS, wp_next_scheduled( Ledger::REBUILD_HOOK ), 'The next admin load has it run again soon.' );

		self::run_rebuild();
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
		$entry = (int) Meta_Guard::writing( static fn() => add_post_meta( $doc, Ledger::key( 'hg_docs', 'manual' ), 'manual', true ) );
		$this->old_entry( $this->about, 'old' );
		$only = static fn(): array => [ 'page' ];
		add_filter( 'hierarchy_guard_post_types', $only );
		Post_Types::reset();
		Ledger::schedule_rebuild();
		self::run_rebuild();
		$this->assertFalse( Ledger::building() );
		$this->assertSame( [], $this->ledger->recent( [ 'hg_docs' ] )['ids'], 'Precondition: the type was not supported then.' );

		remove_filter( 'hierarchy_guard_post_types', $only );
		$both = static fn(): array => [ 'page', 'hg_docs' ];
		add_filter( 'hierarchy_guard_post_types', $both );
		Post_Types::reset();
		$next = wp_next_scheduled( Ledger::REBUILD_HOOK );
		Ledger::schedule_rebuild();

		$this->assertFalse( Ledger::building(), 'A first build is done: no notice comes back for a type supported later.' );
		$this->assertSame( $next, wp_next_scheduled( Ledger::REBUILD_HOOK ), 'The next daily run builds it.' );
		self::run_rebuild();
		remove_filter( 'hierarchy_guard_post_types', $both );
		Post_Types::reset();
		$this->assertSame( [ $entry ], $this->ledger->recent( [ 'hg_docs' ] )['ids'] );
	}

	public function test_types_wp_cron_sees_differently_from_wp_admin_never_keep_the_notice_on(): void {
		$this->old_entry( $this->about, 'old' );
		Ledger::schedule_rebuild();
		// WP-Cron supports only pages; wp-admin also a type registered there alone.
		$cron = static fn(): array => [ 'page' ];
		add_filter( 'hierarchy_guard_post_types', $cron );
		Post_Types::reset();
		// WP-Cron takes a single event off before it runs it.
		wp_clear_scheduled_hook( Ledger::REBUILD_HOOK );
		self::run_rebuild();
		remove_filter( 'hierarchy_guard_post_types', $cron );

		$admin = static fn(): array => [ 'page', 'hg_admin_only' ];
		add_filter( 'hierarchy_guard_post_types', $admin );
		Post_Types::reset();
		Ledger::schedule_rebuild();
		Ledger::schedule_rebuild();
		remove_filter( 'hierarchy_guard_post_types', $admin );
		Post_Types::reset();

		$this->assertFalse( Ledger::building(), 'The notice asks only whether a first build completed.' );
		$this->assertGreaterThan( time() + HOUR_IN_SECONDS, wp_next_scheduled( Ledger::REBUILD_HOOK ), 'And wp-admin does not keep bringing the run forward.' );
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
		wp_schedule_single_event( time() + DAY_IN_SECONDS, Ledger::REBUILD_HOOK );

		$this->ledger->forget( $this->about, 'three' );

		$this->assertLessThanOrEqual( time() + MINUTE_IN_SECONDS, wp_next_scheduled( Ledger::REBUILD_HOOK ), 'The list fell below the cap with older entries left out: the rebuild runs soon.' );
		$this->assertCount( 1, self::rebuild_events(), 'Brought forward, not added.' );
		$this->assertFalse( Ledger::building(), 'Quietly: the tab does not say the list is being built for a refill.' );
		self::run_rebuild();
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
		wp_schedule_single_event( time() + DAY_IN_SECONDS, Ledger::REBUILD_HOOK );

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

		self::run_rebuild();

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

		self::run_rebuild();

		$this->assertSame( [ $this->meta_id( $this->about, 'one' ) ], $this->ledger->recent( [ 'page' ] )['ids'] );
	}

	public function test_a_write_to_the_newest_list_keeps_what_another_request_wrote_meanwhile(): void {
		global $wpdb;

		$this->ledger->record( $this->about, 'one' );
		$one = $this->meta_id( $this->about, 'one' );
		get_option( Ledger::RECENT );
		// Another request records an entry: the row is in the database, this request's cache is stale.
		$other = (int) Meta_Guard::writing( fn() => add_post_meta( $this->about, Ledger::key( 'page', 'other' ), 'other', true ) );
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

	public function test_a_post_deleted_for_good_leaves_the_newest_list_before_core_deletes_its_meta(): void {
		global $wp_filter;
		$hooked = static function ( string $hook, int $priority, string $method ) use ( $wp_filter ): bool {
			foreach ( $wp_filter[ $hook ]->callbacks[ $priority ] ?? [] as $callback ) {
				if ( [ Ledger::class, $method ] === $callback['function'] ) {
					return true;
				}
			}
			return false;
		};

		$this->assertTrue( $hooked( 'before_delete_post', PHP_INT_MIN, 'begin_delete' ), 'First on before_delete_post, before core deletes the meta and before other handlers move paths off the post.' );
		$this->assertTrue( $hooked( 'delete_post', PHP_INT_MAX, 'end_delete' ), 'Last on delete_post, once the meta is gone.' );
	}

	public function test_deleting_a_holder_reads_and_writes_the_newest_list_once_however_many_entries_it_holds(): void {
		$this->assertSame( $this->queries_to_delete_a_holder( 5 ), $this->queries_to_delete_a_holder( 50 ), 'Core deletes the meta rows one by one; the list costs the same for each holder.' );
	}

	/**
	 * Queries deleting a page with this many listed entries costs, beyond
	 * deleting one with as many other meta rows.
	 *
	 * @param int $entries Entries on the page.
	 * @return int
	 */
	private function queries_to_delete_a_holder( int $entries ): int {
		global $wpdb;

		$keep   = self::factory()->post->create( [ 'post_type' => 'page' ] );
		$holder = self::factory()->post->create( [ 'post_type' => 'page' ] );
		$plain  = self::factory()->post->create( [ 'post_type' => 'page' ] );
		$this->ledger->record( $keep, 'kept-' . $entries );
		for ( $i = 1; $i <= $entries; $i++ ) {
			$this->ledger->record( $holder, 'gone-' . $entries . '-' . $i );
			add_post_meta( $plain, '_plain_' . $i, 'x' );
		}
		$kept = $this->meta_id( $keep, 'kept-' . $entries );

		$queries = get_num_queries();
		wp_delete_post( $holder, true );
		$listed  = get_num_queries() - $queries;
		$queries = get_num_queries();
		wp_delete_post( $plain, true );
		$other = get_num_queries() - $queries;

		$ids = $this->ledger->recent( [ 'page' ] )['ids'];
		$this->assertContains( $kept, $ids, 'Another page’s entry stays.' );
		foreach ( $ids as $id ) {
			$this->assertNotNull( $wpdb->get_var( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_id = %d", $id ) ), 'The deleted page’s entries left the list.' );
		}
		return $listed - $other;
	}

	public function test_deleting_a_holder_with_subpages_writes_the_newest_list_once(): void {
		foreach ( [ 5, 30 ] as $entries ) {
			$holder = self::factory()->post->create(
				[
					'post_type'   => 'page',
					'post_name'   => 'holder-' . $entries,
					'post_parent' => $this->about,
				]
			);
			self::factory()->post->create(
				[
					'post_type'   => 'page',
					'post_name'   => 'kid-' . $entries,
					'post_parent' => $holder,
				]
			);
			for ( $i = 1; $i <= $entries; $i++ ) {
				$this->ledger->record( $holder, 'gone-' . $entries . '-' . $i );
			}

			$writes = 0;
			$count  = static function ( string $query ) use ( &$writes ): string {
				if ( 1 === preg_match( '/^\s*(UPDATE|INSERT)\b/i', $query ) && str_contains( $query, Ledger::RECENT ) ) {
					++$writes;
				}
				return $query;
			};
			add_filter( 'query', $count );
			wp_delete_post( $holder, true );
			remove_filter( 'query', $count );

			$this->assertSame( 1, $writes, $entries . ' entries: the holder’s entries leave the list and the paths moved to its parent join it in one write.' );
			$this->assertNotEmpty( $this->ledger->paths_for( $this->about ), 'Precondition: its paths moved to the parent, for its subpage.' );
			foreach ( $this->ledger->recent( [ 'page' ] )['ids'] as $id ) {
				$this->assertNotNull( get_metadata_by_mid( 'post', $id ), 'Every listed entry exists.' );
			}
			$this->assertContains( $this->meta_id( $this->about, 'about/holder-' . $entries ), $this->ledger->recent( [ 'page' ] )['ids'], 'The holder’s own path, now on its parent, is listed.' );
		}
	}

	public function test_the_merge_back_writes_the_list_again_when_another_request_deleted_its_row(): void {
		global $wpdb;

		$a = $this->old_entry( $this->about, 'a' );
		$b = $this->old_entry( $this->about, 'b' );
		update_option( Ledger::RECENT, [], false );
		$after = static function () use ( $wpdb ): void {
			static $done = false;
			if ( $done ) {
				return;
			}
			$done = true;
			$wpdb->delete( $wpdb->options, [ 'option_name' => Ledger::RECENT ] );
		};
		add_action( 'update_option_' . Ledger::RECENT, $after );
		self::run_rebuild();
		remove_action( 'update_option_' . Ledger::RECENT, $after );
		wp_cache_delete( Ledger::RECENT, 'options' );

		$this->assertSame( [ $b, $a ], $this->ledger->recent( [ 'page' ] )['ids'], 'The merged list is written as a new row.' );
	}
}
