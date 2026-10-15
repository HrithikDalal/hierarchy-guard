<?php
/**
 * The recorded redirects the Redirects tab lists and edits.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Ledger;
use HierarchyGuard\Post_Types;
use HierarchyGuard\Redirects\Records;
use HierarchyGuard\Redirects\Resolver;
use HierarchyGuard\Redirects\Server;
use HierarchyGuard\Settings;
use HierarchyGuard\Trash\Markers;
use WP_Error;
use WP_UnitTestCase;

/**
 * @covers \HierarchyGuard\Redirects\Records
 */
final class RecordsTest extends WP_UnitTestCase {

	use Trash_Fixtures;

	private Ledger $ledger;

	private Records $records;

	/**
	 * Page IDs.
	 *
	 * @var array<string,int>
	 */
	private array $ids;

	public function set_up(): void {
		parent::set_up();
		delete_option( Settings::OPTION );
		delete_option( Ledger::ROOT_OPTION );
		delete_option( Ledger::ROOT_TARGETS );
		delete_option( Ledger::RECENT );
		Post_Types::reset();
		$this->set_permalink_structure( '/%postname%/' );
		// Trashing a holder in these tests leaves its subpages alone.
		add_filter( 'hierarchy_guard_trash_policy', static fn(): string => Settings::POLICY_KEEP );
		$this->ledger  = new Ledger();
		$this->records = new Records( $this->ledger );
		$this->ids     = $this->make_tree();
	}

	public function tear_down(): void {
		unregister_post_type( 'hg_docs' );
		Post_Types::reset();
		$this->set_permalink_structure( '' );
		parent::tear_down();
	}

	/**
	 * The IDs and old URLs of a page of records.
	 *
	 * @param array<string,mixed> $page From Records::page().
	 * @return array<string,string> Old URL by record ID.
	 */
	private static function olds( array $page ): array {
		return array_column( $page['rows'], 'old', 'id' );
	}

	/**
	 * Registers a hierarchical type with a rewrite prefix.
	 */
	private function register_docs(): void {
		register_post_type(
			'hg_docs',
			[
				'public'       => true,
				'hierarchical' => true,
				'rewrite'      => [ 'slug' => 'docs' ],
			]
		);
		Post_Types::reset();
	}

	/**
	 * Each row's status on the first page, by old URL; the holder's ID is
	 * added for an old URL two rows share.
	 *
	 * @return array<string,string>
	 */
	private function statuses_by_old(): array {
		$rows = $this->records->page( '', '', 1 )['rows'];
		$all  = $this->records->statuses( $rows );
		$olds = array_count_values( array_column( $rows, 'old' ) );
		$out  = [];
		foreach ( $rows as $row ) {
			$key         = $olds[ $row['old'] ] > 1 ? $row['old'] . ' ' . $row['holder'] : $row['old'];
			$out[ $key ] = $all[ $row['id'] ] ?? '';
		}
		return $out;
	}

	/**
	 * What the server sends for a request of a URL relative to the home URL.
	 *
	 * @param string $old Old URL, such as /about/.
	 * @return array{url:string,status:int}|null
	 */
	private function served_at( string $old ): ?array {
		$this->go_to( home_url( $old ) );
		return ( new Server( new Resolver( $this->ledger ) ) )->target_for_current_request();
	}

	/**
	 * Every row that reads Active is served for its old URL, by the server
	 * itself; a top-level root-list entry, whose old URL leads nowhere of
	 * its own, for a URL under it.
	 *
	 * @param string $under A top-level page, for the URLs under a top-level root-list entry.
	 */
	private function assert_every_active_row_is_served( string $under ): void {
		$rows     = $this->records->page( '', '', 1 )['rows'];
		$statuses = $this->records->statuses( $rows );
		$active   = 0;
		foreach ( $rows as $row ) {
			if ( Records::STATUS_ACTIVE !== $statuses[ $row['id'] ] ) {
				continue;
			}
			++$active;
			$old = Records::is_top_level( $row ) ? $row['old'] . $under . '/' : $row['old'];
			$this->assertNotNull( $this->served_at( $old ), $old . ' reads Active, so the server serves it.' );
		}
		$this->assertGreaterThan( 0, $active, 'Precondition: some row reads Active.' );
	}

	public function test_each_entry_and_each_root_entry_is_one_row_and_nothing_else_is(): void {
		$this->ledger->record( $this->ids['about'], 'old-about' );
		$this->ledger->record( $this->ids['about'], 'company/info' );
		$this->ledger->record( $this->ids['team'], 'crew' );
		$this->ledger->record_root( 'page', 'archive' );
		$this->ledger->set_target( 'page', 'crew', $this->ids['team'], 'https://example.org/crew/', 302 );
		// What else the plugin stores on posts.
		add_post_meta( $this->ids['team'], Markers::moved_key( $this->ids['about'] ), 'x' );
		add_post_meta( $this->ids['team'], Markers::ALIASES, 'x' );

		$page = $this->records->page( '', '', 1 );

		$this->assertSame(
			[
				Records::id( 0, 'page', 'archive' ) => '/archive/',
				Records::id( $this->ids['about'], 'page', 'company/info' ) => '/company/info/',
				Records::id( $this->ids['team'], 'page', 'crew' ) => '/crew/',
				Records::id( $this->ids['about'], 'page', 'old-about' ) => '/old-about/',
			],
			self::olds( $page ),
			'Sorted by old URL, the root-list entry among them.'
		);
		$this->assertSame( 4, $page['total'] );
		$this->assertFalse( $page['capped'] );
		$this->assertSame(
			[
				'url'  => 'https://example.org/crew/',
				'code' => 302,
			],
			$page['rows'][2]['target']
		);
		$this->assertNull( $page['rows'][0]['target'], 'A root-list entry without an override.' );
		$this->assertSame( $this->ids['about'] . '.' . md5( 'page:old-about' ), Records::id( $this->ids['about'], 'page', 'old-about' ) );
	}

	public function test_an_entry_of_a_type_no_longer_supported_or_with_another_type_s_hash_is_not_a_row(): void {
		$this->ledger->record( $this->ids['about'], 'old-about' );
		// A key whose hash names another type than its post's.
		add_post_meta( $this->ids['team'], Ledger::key( 'hg_docs', 'crew' ), 'crew' );

		$this->assertSame( [ Records::id( $this->ids['about'], 'page', 'old-about' ) ], array_keys( self::olds( $this->records->page( '', '', 1 ) ) ) );

		update_option( Settings::OPTION, [ 'post_types' => [] ] );
		Post_Types::reset();
		$this->assertSame( 0, $this->records->page( '', '', 1 )['total'] );
	}

	public function test_twenty_rows_a_page_in_old_url_order(): void {
		for ( $i = 1; $i <= 22; $i++ ) {
			$this->ledger->record( $this->ids['about'], sprintf( 'old-%02d', $i ) );
		}
		$this->ledger->record_root( 'page', 'old-105' );

		$first  = $this->records->page( '', '', 1 );
		$second = $this->records->page( '', '', 2 );

		$this->assertSame( 23, $first['total'] );
		$this->assertCount( Records::PER_PAGE, $first['rows'] );
		$this->assertSame( '/old-01/', $first['rows'][0]['old'] );
		$this->assertSame( [ '/old-105/', '/old-11/' ], [ $first['rows'][10]['old'], $first['rows'][11]['old'] ], 'A root-list entry sorts with the others.' );
		$this->assertSame( [ '/old-20/', '/old-21/', '/old-22/' ], array_values( self::olds( $second ) ) );
	}

	public function test_search_matches_the_old_url(): void {
		$this->ledger->record( $this->ids['about'], 'company/about-us' );
		$this->ledger->record( $this->ids['team'], 'people/crew' );
		$this->ledger->record_root( 'page', 'about-archive' );

		$this->assertSame( [ '/about-archive/', '/company/about-us/' ], array_values( self::olds( $this->records->page( '', 'ABOUT', 1 ) ) ) );
		$this->assertSame( [ '/people/crew/' ], array_values( self::olds( $this->records->page( '', '/people/', 1 ) ) ) );
		$this->assertSame( 0, $this->records->page( '', 'nowhere', 1 )['total'] );
	}

	public function test_the_type_filter_lists_one_supported_type_with_its_rewrite_prefix(): void {
		$this->register_docs();
		$guide = self::factory()->post->create(
			[
				'post_type' => 'hg_docs',
				'post_name' => 'guide',
			]
		);
		$this->ledger->record( $guide, 'manual' );
		$this->ledger->record( $this->ids['about'], 'old-about' );

		$this->assertSame( [ '/docs/manual/', '/old-about/' ], array_values( self::olds( $this->records->page( '', '', 1 ) ) ) );
		$this->assertSame( [ '/docs/manual/' ], array_values( self::olds( $this->records->page( 'hg_docs', '', 1 ) ) ) );
		$this->assertSame( 0, $this->records->page( 'post', '', 1 )['total'], 'A type that is not supported lists nothing.' );
	}

	public function test_a_listing_reads_the_newest_entries_up_to_the_cap_and_finds_an_older_one_by_its_exact_old_url(): void {
		for ( $i = 1; $i <= 5; $i++ ) {
			$this->ledger->record( $this->ids['about'], 'old-' . $i );
		}
		$this->ledger->record_root( 'page', 'archive' );
		$cap = static fn(): int => 3;
		add_filter( 'hierarchy_guard_count_cap', $cap );

		$page = $this->records->page( '', '', 1 );
		$this->assertSame( [ '/archive/', '/old-3/', '/old-4/', '/old-5/' ], array_values( self::olds( $page ) ), 'The newest three, and the root list.' );
		$this->assertTrue( $page['capped'] );
		$this->assertSame( [ '/old-1/' ], array_values( self::olds( $this->records->page( '', home_url( '/old-1/' ), 1 ) ) ), 'Searching its full URL finds an older entry.' );
		remove_filter( 'hierarchy_guard_count_cap', $cap );
	}

	public function test_a_type_s_filter_lists_that_type_s_newest_entries_whatever_the_other_types_hold(): void {
		$this->register_docs();
		$guide = self::factory()->post->create(
			[
				'post_type' => 'hg_docs',
				'post_name' => 'guide',
			]
		);
		$cap   = static fn(): int => 2;
		add_filter( 'hierarchy_guard_count_cap', $cap );
		foreach ( [ 'p-1', 'p-2', 'p-3' ] as $path ) {
			$this->ledger->record( $this->ids['about'], $path );
		}
		$this->ledger->record( $guide, 'manual' );
		$this->ledger->record( $this->ids['about'], 'p-4' );

		$all  = $this->records->page( '', '', 1 );
		$docs = $this->records->page( 'hg_docs', '', 1 );
		remove_filter( 'hierarchy_guard_count_cap', $cap );

		$this->assertSame( [ '/docs/manual/', '/p-4/' ], array_values( self::olds( $all ) ), 'The newest two of every type.' );
		$this->assertTrue( $all['capped'] );
		$this->assertSame( [ '/docs/manual/' ], array_values( self::olds( $docs ) ) );
		$this->assertFalse( $docs['capped'], 'Nothing of that type is older.' );
	}

	public function test_a_page_past_the_last_one_shows_the_last_one(): void {
		for ( $i = 1; $i <= 21; $i++ ) {
			$this->ledger->record( $this->ids['about'], sprintf( 'old-%02d', $i ) );
		}

		$past = $this->records->page( '', '', 5 );
		$this->assertSame( 2, $past['page'] );
		$this->assertSame( [ '/old-21/' ], array_values( self::olds( $past ) ) );

		$this->ledger->forget( $this->ids['about'], 'old-21' );
		$after = $this->records->page( '', '', 2 );
		$this->assertSame( 1, $after['page'], 'After the last row of page 2 is deleted.' );
		$this->assertCount( 20, $after['rows'] );
	}

	public function test_a_listing_costs_the_same_queries_for_five_entries_or_fifty(): void {
		$this->assertSame( $this->queries_to_list( 5 ), $this->queries_to_list( 50 ) );
	}

	/**
	 * Queries to list a page of records and their statuses, with this many entries.
	 *
	 * @param int $entries Entries to record.
	 * @return int
	 */
	private function queries_to_list( int $entries ): int {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $wpdb->esc_like( Ledger::KEY_PREFIX ) . '%' ) );
		delete_option( Ledger::RECENT );
		for ( $i = 1; $i <= $entries; $i++ ) {
			$this->ledger->record( $this->ids['about'], 'many-' . $i );
		}
		wp_cache_flush();
		get_option( Ledger::ROOT_OPTION );
		get_option( Ledger::ROOT_TARGETS );
		get_option( Settings::OPTION );
		Post_Types::supported();

		$queries = get_num_queries();
		$this->records->statuses( $this->records->page( '', '', 1 )['rows'] );
		return get_num_queries() - $queries;
	}

	public function test_find_takes_only_the_id_of_a_record_that_exists(): void {
		$this->ledger->record( $this->ids['about'], 'old-about' );
		$this->ledger->record_root( 'page', 'archive' );
		$this->ledger->set_target( 'page', 'archive', 0, '', 302 );

		$found = $this->records->find( Records::id( $this->ids['about'], 'page', 'old-about' ) );
		$this->assertSame( [ $this->ids['about'], 'page', 'old-about', '/old-about/', null ], [ $found['holder'], $found['post_type'], $found['path'], $found['old'], $found['target'] ] );
		$this->assertSame( 302, $this->records->find( Records::id( 0, 'page', 'archive' ) )['target']['code'] );

		$this->assertNull( $this->records->find( Records::id( $this->ids['team'], 'page', 'old-about' ) ), 'Another page holds no such record.' );
		$this->assertNull( $this->records->find( Records::id( 0, 'page', 'old-about' ) ), 'The root list holds no such path.' );
		$this->assertNull( $this->records->find( $this->ids['about'] . '.' . md5( 'hg_docs:old-about' ) ), 'The hash must be of the holder’s own type.' );
		$this->assertNull( $this->records->find( '999999.' . md5( 'page:old-about' ) ) );
		$this->assertNull( $this->records->find( 'about.old-about' ) );
		$this->assertNull( $this->records->find( $this->ids['about'] . '.' . md5( 'page:old-about' ) . ' ' ) );
	}

	public function test_saving_a_page_of_the_same_type_moves_the_record_onto_it(): void {
		$this->ledger->record( $this->ids['about'], 'old-about' );
		$this->ledger->set_target( 'page', 'old-about', $this->ids['about'], '', 302 );
		$record = $this->records->find( Records::id( $this->ids['about'], 'page', 'old-about' ) );

		$this->assertTrue( $this->records->save( $record, get_permalink( $this->ids['history'] ), 301 ) );

		$this->assertSame( [], $this->ledger->paths_for( $this->ids['about'] ), 'Forgotten on the old holder.' );
		$this->assertFalse( metadata_exists( 'post', $this->ids['about'], Ledger::target_key( 'page', 'old-about' ) ), 'Its override went with it.' );
		$this->assertSame( [ 'old-about' ], $this->ledger->paths_for( $this->ids['history'] ) );
		$this->assertSame( 301, $this->ledger->target( 'page', 'old-about', $this->ids['history'] )['code'], 'The code moved with the record.' );

		$resolver = new Resolver( $this->ledger );
		$this->assertSame( $this->ids['history'], $resolver->resolve( 'page', 'old-about' )->ID );
		$sub = $this->page( 'timeline', $this->ids['history'] );
		$this->assertSame( $sub, $resolver->resolve( 'page', 'old-about/timeline' )->ID, 'A page target covers the URLs under the old one.' );
		wp_update_post(
			[
				'ID'          => $this->ids['history'],
				'post_parent' => 0,
			]
		);
		$this->assertSame( $this->ids['history'], $resolver->resolve( 'page', 'old-about' )->ID, 'And follows the page when it moves.' );
	}

	public function test_saving_a_page_moves_a_root_entry_out_of_the_root_list(): void {
		$this->ledger->record_root( 'page', 'archive' );
		$this->ledger->set_target( 'page', 'archive', 0, '', 302 );
		$record = $this->records->find( Records::id( 0, 'page', 'archive' ) );

		$this->assertTrue( $this->records->save( $record, get_permalink( $this->ids['company'] ), 302 ) );

		$this->assertSame( [ 'page' => [] ], $this->ledger->root_paths() );
		$this->assertFalse( get_option( Ledger::ROOT_TARGETS ) );
		$this->assertSame( [ 'archive' ], $this->ledger->paths_for( $this->ids['company'] ) );
		$this->assertSame( 302, $this->ledger->target( 'page', 'archive', $this->ids['company'] )['code'] );
		$this->assertSame( $this->ids['about'], ( new Resolver( $this->ledger ) )->resolve( 'page', 'archive/about' )->ID );
	}

	public function test_another_type_s_page_or_any_other_url_makes_a_url_target_on_the_same_holder(): void {
		$this->register_docs();
		$guide = self::factory()->post->create(
			[
				'post_type' => 'hg_docs',
				'post_name' => 'guide',
			]
		);
		$this->ledger->record( $this->ids['about'], 'old-about' );
		$record = $this->records->find( Records::id( $this->ids['about'], 'page', 'old-about' ) );

		$this->assertTrue( $this->records->save( $record, get_permalink( $guide ), 0 ) );
		$this->assertSame(
			[
				'url'  => get_permalink( $guide ),
				'code' => 0,
			],
			$this->ledger->target( 'page', 'old-about', $this->ids['about'] ),
			'A record belongs to its own post type.'
		);
		$this->assertSame( [ 'old-about' ], $this->ledger->paths_for( $this->ids['about'] ), 'The record stays where it was.' );

		$this->assertTrue( $this->records->save( $record, ' https://jobs.example.org/open?team=web ', 302 ) );
		$this->assertSame(
			[
				'url'  => 'https://jobs.example.org/open?team=web',
				'code' => 302,
			],
			$this->ledger->target( 'page', 'old-about', $this->ids['about'] )
		);
	}

	public function test_the_holder_s_own_url_makes_a_page_target_again(): void {
		$this->ledger->record( $this->ids['about'], 'old-about' );
		$this->ledger->set_target( 'page', 'old-about', $this->ids['about'], 'https://example.org/', 302 );
		$record = $this->records->find( Records::id( $this->ids['about'], 'page', 'old-about' ) );

		$this->assertTrue( $this->records->save( $record, get_permalink( $this->ids['about'] ), 302 ) );

		$this->assertSame(
			[
				'url'  => '',
				'code' => 302,
			],
			$this->ledger->target( 'page', 'old-about', $this->ids['about'] )
		);
	}

	public function test_a_top_level_entry_offers_an_empty_field_that_keeps_it_and_saves_the_code(): void {
		$this->ledger->record_root( 'page', 'archive' );
		$record = $this->records->find( Records::id( 0, 'page', 'archive' ) );
		$this->assertTrue( Records::is_top_level( $record ) );
		$this->assertSame( '', Records::target_url( $record ), 'A top-level entry offers nothing.' );

		$this->assertTrue( $this->records->save( $record, '  ', 302 ) );
		$this->assertSame( [ 'page' => [ 'archive' ] ], $this->ledger->root_paths(), 'Still in the root list: subpages stay top level.' );
		$this->assertSame(
			[
				'url'  => '',
				'code' => 302,
			],
			$this->ledger->target( 'page', 'archive', 0 )
		);

		$record = $this->records->find( Records::id( 0, 'page', 'archive' ) );
		$this->assertTrue( $this->records->save( $record, '', 0 ) );
		$this->assertNull( $this->ledger->target( 'page', 'archive', 0 ), 'Automatic again, the override goes.' );
	}

	public function test_the_url_the_form_offered_keeps_the_target_and_saves_the_code(): void {
		$this->ledger->record( $this->ids['about'], 'old-about' );
		$this->ledger->set_target( 'page', 'old-about', $this->ids['about'], 'https://example.org/about/', 0 );
		$record = $this->records->find( Records::id( $this->ids['about'], 'page', 'old-about' ) );

		$this->assertTrue( $this->records->save( $record, 'https://example.org/about', 301 ) );

		$this->assertSame(
			[
				'url'  => 'https://example.org/about/',
				'code' => 301,
			],
			$this->ledger->target( 'page', 'old-about', $this->ids['about'] )
		);
	}

	public function test_a_path_on_this_site_is_read_as_its_url(): void {
		$this->ledger->record( $this->ids['about'], 'old-about' );
		$record = function (): array {
			return $this->records->find( Records::id( (int) $this->ledger->find( 'page', 'old-about' ), 'page', 'old-about' ) );
		};

		$this->assertTrue( $this->records->save( $record(), '/company/about/history/', 0 ) );
		$this->assertSame( $this->ids['history'], $this->ledger->find( 'page', 'old-about' ), 'A page’s path makes a page target.' );

		$this->assertTrue( $this->records->save( $record(), '/careers/?team=web', 0 ) );
		$this->assertSame( home_url( '/careers/?team=web' ), $this->ledger->target( 'page', 'old-about', $this->ids['history'] )['url'], 'Any other path is a URL on this site.' );

		$this->assertSame( 'hierarchy_guard_not_http', $this->records->save( $record(), '//evil.example/x', 0 )->get_error_code(), 'Two slashes start another host, not a path.' );
	}

	public function test_a_url_that_is_not_http_or_is_empty_is_refused_and_nothing_is_written(): void {
		$this->ledger->record( $this->ids['about'], 'old-about' );
		$record = $this->records->find( Records::id( $this->ids['about'], 'page', 'old-about' ) );
		$before = wp_cache_get_last_changed( Ledger::CACHE_GROUP );

		foreach ( [ 'javascript:alert(1)', 'ftp://example.org/', 'mailto:a@example.org', 'company/about', '' ] as $url ) {
			$result = $this->records->save( $record, $url, 302 );
			$this->assertInstanceOf( WP_Error::class, $result, $url );
		}
		$this->assertSame( 'hierarchy_guard_no_url', $this->records->save( $record, '  ', 0 )->get_error_code() );
		$this->assertSame( 'Enter the URL or path to redirect to.', $this->records->save( $record, '', 0 )->get_error_message() );
		$this->assertSame( 'hierarchy_guard_not_http', $this->records->save( $record, 'ftp://example.org/', 0 )->get_error_code() );
		$this->assertSame( 'Enter a URL that starts with http:// or https://, or a path on this site that starts with /.', $this->records->save( $record, 'ftp://example.org/', 0 )->get_error_message() );
		$this->assertNull( $this->ledger->target( 'page', 'old-about', $this->ids['about'] ) );
		$this->assertSame( $before, wp_cache_get_last_changed( Ledger::CACHE_GROUP ) );
	}

	public function test_a_url_target_at_its_own_old_url_is_refused_and_nothing_is_written(): void {
		$this->ledger->record( $this->ids['about'], 'old-about' );
		$record = $this->records->find( Records::id( $this->ids['about'], 'page', 'old-about' ) );
		$before = wp_cache_get_last_changed( Ledger::CACHE_GROUP );

		foreach ( [ home_url( '/old-about/' ), home_url( '/old-about' ), '/old-about/', home_url( '/old-about/?from=edit#top' ) ] as $value ) {
			$result = $this->records->save( $record, $value, 302 );
			$this->assertInstanceOf( WP_Error::class, $result, $value );
			$this->assertSame( 'hierarchy_guard_own_url', $result->get_error_code(), $value );
			$this->assertSame( 'That URL is the old URL itself.', $result->get_error_message(), $value );
		}

		$this->assertNull( $this->ledger->target( 'page', 'old-about', $this->ids['about'] ), 'No override written.' );
		$this->assertSame( $before, wp_cache_get_last_changed( Ledger::CACHE_GROUP ) );
		$this->assertTrue( $this->records->save( $record, 'https://example.org/old-about/', 0 ), 'The same path on another host is no loop.' );
	}

	public function test_an_http_url_with_no_host_is_refused(): void {
		$this->ledger->record( $this->ids['about'], 'old-about' );
		$record = $this->records->find( Records::id( $this->ids['about'], 'page', 'old-about' ) );

		foreach ( [ 'http:/path', 'https:path', 'http:///path' ] as $value ) {
			$result = $this->records->save( $record, $value, 0 );
			$this->assertInstanceOf( WP_Error::class, $result, $value );
			$this->assertSame( 'hierarchy_guard_not_http', $result->get_error_code(), $value );
		}
		$this->assertNull( $this->ledger->target( 'page', 'old-about', $this->ids['about'] ) );
		$this->assertNull(
			Ledger::clean_target(
				[
					'url'  => 'http:/path',
					'code' => 0,
				]
			),
			'A stored URL with no host is no target.'
		);
	}

	public function test_a_page_at_the_old_url_is_refused(): void {
		$this->ledger->record( $this->ids['about'], 'old-about' );
		$draft  = $this->page( 'old-about', 0, 'draft' );
		$record = $this->records->find( Records::id( $this->ids['about'], 'page', 'old-about' ) );

		$result = $this->records->save( $record, get_permalink( $draft ), 0 );

		$this->assertSame( 'hierarchy_guard_same_path', $result->get_error_code() );
		$this->assertSame( 'That page is at /old-about/, so it needs no redirect.', $result->get_error_message() );
		$this->assertSame( [ 'old-about' ], $this->ledger->paths_for( $this->ids['about'] ) );
	}

	public function test_delete_removes_the_record_and_its_override_and_the_old_url_is_a_404(): void {
		$this->ledger->record( $this->ids['about'], 'old-about' );
		$this->ledger->set_target( 'page', 'old-about', $this->ids['about'], '', 302 );
		$this->ledger->record_root( 'page', 'archive' );
		$this->ledger->set_target( 'page', 'archive', 0, 'https://example.org/', 0 );
		$resolver = new Resolver( $this->ledger );
		$this->assertNotNull( $resolver->resolve( 'page', 'old-about' ), 'Precondition: it redirects.' );
		$before = wp_cache_get_last_changed( Ledger::CACHE_GROUP );

		$this->assertTrue( $this->records->delete( $this->records->find( Records::id( $this->ids['about'], 'page', 'old-about' ) ) ) );
		$this->assertTrue( $this->records->delete( $this->records->find( Records::id( 0, 'page', 'archive' ) ) ) );

		$this->assertNotSame( $before, wp_cache_get_last_changed( Ledger::CACHE_GROUP ) );
		$this->assertSame( [], $this->ledger->paths_for( $this->ids['about'] ) );
		$this->assertFalse( metadata_exists( 'post', $this->ids['about'], Ledger::target_key( 'page', 'old-about' ) ) );
		$this->assertFalse( get_option( Ledger::ROOT_TARGETS ) );
		$this->assertNull( $resolver->resolve( 'page', 'old-about' ) );
		$this->assertNull( $resolver->resolve( 'page', 'archive' ) );
	}

	public function test_each_row_shows_the_first_status_that_applies(): void {
		update_option( Settings::OPTION, [ 'serve_redirects' => true ] );
		$this->page( 'news' );
		$draft   = $this->page( 'whats-on', 0, 'draft' );
		$private = $this->page( 'staff', 0, 'private' );
		$trashed = $this->page( 'gone' );
		// Recorded straight into the ledger: a page that takes a path drops the records there.
		$this->ledger->record( $this->ids['about'], 'news' );
		$this->ledger->record( $draft, 'events' );
		$this->ledger->record( $private, 'people' );
		$this->ledger->record( $draft, 'events-feed' );
		$this->ledger->set_target( 'page', 'events-feed', $draft, 'https://example.org/feed/', 0 );
		$this->ledger->record( $trashed, 'old-gone' );
		$this->ledger->set_target( 'page', 'old-gone', $trashed, 'https://example.org/', 0 );
		wp_trash_post( $trashed );
		$this->ledger->record( $this->ids['team'], 'crew' );
		$this->ledger->record_root( 'page', 'archive' );

		$statuses = fn(): array => array_combine( array_column( $this->records->page( '', '', 1 )['rows'], 'old' ), $this->records->statuses( $this->records->page( '', '', 1 )['rows'] ) );

		$this->assertSame(
			[
				'/archive/'     => Records::STATUS_ACTIVE,
				'/crew/'        => Records::STATUS_ACTIVE,
				'/events-feed/' => Records::STATUS_ACTIVE,
				'/events/'      => Records::STATUS_UNPUBLISHED,
				'/news/'        => Records::STATUS_IN_USE,
				'/old-gone/'    => Records::STATUS_UNPUBLISHED,
				'/people/'      => Records::STATUS_UNPUBLISHED,
			],
			$statuses(),
			'A URL target on a draft is served; one whose page is in the Trash is not.'
		);

		update_option( Settings::OPTION, [ 'serve_redirects' => false ] );
		$this->assertSame(
			[
				'/archive/'     => Records::STATUS_OFF,
				'/crew/'        => Records::STATUS_OFF,
				'/events-feed/' => Records::STATUS_OFF,
				'/events/'      => Records::STATUS_UNPUBLISHED,
				'/news/'        => Records::STATUS_IN_USE,
				'/old-gone/'    => Records::STATUS_UNPUBLISHED,
				'/people/'      => Records::STATUS_UNPUBLISHED,
			],
			$statuses(),
			'Only a row that would be active says built-in redirects are off.'
		);
	}

	public function test_a_row_is_in_use_when_a_blog_post_is_shown_at_its_old_url(): void {
		update_option( Settings::OPTION, [ 'serve_redirects' => true ] );
		$this->ledger->record( $this->ids['about'], 'news' );
		$this->ledger->record( $this->ids['team'], 'crew' );
		self::factory()->post->create( [ 'post_name' => 'news' ] );

		$this->assertNull( $this->served_at( '/news/' ), 'Precondition: the blog post is shown, so the server never runs.' );
		$this->assertSame(
			[
				'/crew/' => Records::STATUS_ACTIVE,
				'/news/' => Records::STATUS_IN_USE,
			],
			$this->statuses_by_old()
		);
	}

	public function test_a_live_holder_s_row_is_in_use_while_a_page_in_the_trash_holds_the_newest_entry(): void {
		update_option( Settings::OPTION, [ 'serve_redirects' => true ] );
		$this->ledger->record( $this->ids['team'], 'old-crew' );
		$this->ledger->record( $this->ids['history'], 'old-crew' );
		wp_trash_post( $this->ids['history'] );
		wp_cache_flush();

		$this->assertNull( $this->served_at( '/old-crew/' ), 'Precondition: the server leaves it for the page in the Trash.' );
		$this->assertSame(
			[
				'/old-crew/ ' . $this->ids['team']    => Records::STATUS_IN_USE,
				'/old-crew/ ' . $this->ids['history'] => Records::STATUS_UNPUBLISHED,
			],
			$this->statuses_by_old()
		);
	}

	public function test_a_root_entry_a_post_entry_answers_instead_is_in_use(): void {
		update_option( Settings::OPTION, [ 'serve_redirects' => true ] );
		$this->ledger->record_root( 'page', 'archive' );
		$this->ledger->set_target( 'page', 'archive', 0, 'https://example.org/archive/', 0 );
		$this->ledger->record( $this->ids['team'], 'archive' );

		$this->assertSame( get_permalink( $this->ids['team'] ), $this->served_at( '/archive/' )['url'] ?? null, 'Precondition: the post entry answers.' );
		$this->assertSame(
			[
				'/archive/ 0'                     => Records::STATUS_IN_USE,
				'/archive/ ' . $this->ids['team'] => Records::STATUS_ACTIVE,
			],
			$this->statuses_by_old()
		);
	}

	public function test_an_old_url_deeper_than_the_segment_cap_never_reads_active(): void {
		update_option( Settings::OPTION, [ 'serve_redirects' => true ] );
		$this->ledger->record( $this->ids['team'], 'a/b/c' );
		$this->ledger->record( $this->ids['history'], 'a/b' );
		add_filter( 'hierarchy_guard_max_segments', static fn(): int => 2 );

		$this->assertNull( $this->served_at( '/a/b/c/' ), 'Precondition: the server refuses it.' );
		$this->assertSame(
			[
				'/a/b/'   => Records::STATUS_ACTIVE,
				'/a/b/c/' => Records::STATUS_OFF,
			],
			$this->statuses_by_old()
		);
	}

	public function test_a_url_target_at_its_own_old_url_never_reads_active(): void {
		update_option( Settings::OPTION, [ 'serve_redirects' => true ] );
		$this->ledger->record( $this->ids['team'], 'old-crew' );
		// Written straight into the ledger, as before the Edit view refused it.
		$this->ledger->set_target( 'page', 'old-crew', $this->ids['team'], home_url( '/old-crew/' ), 0 );

		$this->assertNull( $this->served_at( '/old-crew/' ), 'Precondition: the server refuses the loop.' );
		$this->assertNotSame( Records::STATUS_ACTIVE, $this->statuses_by_old()['/old-crew/'] );
	}

	public function test_a_row_another_type_answers_first_is_in_use(): void {
		update_option( Settings::OPTION, [ 'serve_redirects' => true ] );
		$this->register_docs();
		$this->set_permalink_structure( '/%postname%/' );
		$guide = self::factory()->post->create(
			[
				'post_type' => 'hg_docs',
				'post_name' => 'guide',
			]
		);
		$this->ledger->record( $guide, 'old-guide' );
		// A page entry at the docs prefix: the server tries hg_docs first.
		$this->ledger->record( $this->ids['team'], 'docs/old-guide' );

		$this->assertSame( get_permalink( $guide ), $this->served_at( '/docs/old-guide/' )['url'] ?? null, 'Precondition: the docs entry answers.' );
		$this->assertSame(
			[
				'/docs/old-guide/ ' . $guide             => Records::STATUS_ACTIVE,
				'/docs/old-guide/ ' . $this->ids['team'] => Records::STATUS_IN_USE,
			],
			$this->statuses_by_old()
		);
	}

	public function test_every_row_that_reads_active_is_served_by_the_server(): void {
		update_option( Settings::OPTION, [ 'serve_redirects' => true ] );
		$this->page( 'faq' );
		$draft   = $this->page( 'whats-on', 0, 'draft' );
		$trashed = $this->page( 'gone' );
		self::factory()->post->create( [ 'post_name' => 'news' ] );
		$this->ledger->record( $this->ids['about'], 'news' );
		$this->ledger->record( $draft, 'events' );
		$this->ledger->record( $draft, 'events-feed' );
		$this->ledger->set_target( 'page', 'events-feed', $draft, 'https://example.org/feed/', 0 );
		$this->ledger->record( $trashed, 'old-gone' );
		wp_trash_post( $trashed );
		$this->ledger->record( $this->ids['team'], 'crew' );
		$this->ledger->record( $this->ids['team'], 'crew/old-lead' );
		$this->ledger->record( $this->ids['leadership'], 'old-lead' );
		$this->ledger->set_target( 'page', 'old-lead', $this->ids['leadership'], 'https://example.org/lead/', 302 );
		$this->ledger->record( $this->ids['history'], 'old-crew' );
		$this->ledger->record( $this->ids['team'], 'old-crew' );
		$this->ledger->record( $this->ids['team'], 'self' );
		$this->ledger->set_target( 'page', 'self', $this->ids['team'], home_url( '/self/' ), 0 );
		$this->ledger->record_root( 'page', 'archive' );
		$this->ledger->record_root( 'page', 'crew' );
		$this->ledger->record( $this->ids['history'], 'a/b/c/d/e' );
		add_filter( 'hierarchy_guard_max_segments', static fn(): int => 4 );
		wp_cache_flush();

		$this->assert_every_active_row_is_served( 'faq' );
	}

	public function test_an_old_url_with_other_scripts_reads_and_searches_as_people_type_it(): void {
		$this->ledger->record( $this->ids['about'], 'café/équipe' );
		$this->assertSame( [ 'caf%c3%a9/%c3%a9quipe' ], $this->ledger->paths_for( $this->ids['about'] ), 'Precondition: stored percent-encoded.' );

		$this->assertSame( [ '/café/équipe/' ], array_values( self::olds( $this->records->page( '', '', 1 ) ) ) );
		$this->assertSame( [ '/café/équipe/' ], array_values( self::olds( $this->records->page( '', 'café', 1 ) ) ) );
		$this->assertSame( [ '/café/équipe/' ], array_values( self::olds( $this->records->page( '', home_url( '/café/équipe/' ), 1 ) ) ), 'A full URL, as pasted from the browser.' );

		for ( $i = 1; $i <= 3; $i++ ) {
			$this->ledger->record( $this->ids['team'], 'other-' . $i );
		}
		$cap = static fn(): int => 1;
		add_filter( 'hierarchy_guard_count_cap', $cap );
		$this->assertContains( '/café/équipe/', array_values( self::olds( $this->records->page( '', home_url( '/café/équipe/' ), 1 ) ) ), 'Past the cap too.' );
		remove_filter( 'hierarchy_guard_count_cap', $cap );
	}

	public function test_under_plain_permalinks_no_row_reads_active(): void {
		update_option( Settings::OPTION, [ 'serve_redirects' => true ] );
		$this->ledger->record( $this->ids['about'], 'old-about' );
		$this->set_permalink_structure( '' );

		$rows = $this->records->page( '', '', 1 )['rows'];

		$this->assertSame( [ '/old-about/' ], array_column( $rows, 'old' ) );
		$this->assertSame( [ Records::STATUS_OFF ], array_values( $this->records->statuses( $rows ) ), 'The server serves nothing without pretty permalinks.' );
	}

	public function test_a_page_url_pasted_without_its_slash_or_with_a_query_or_fragment_still_moves_the_record(): void {
		$record = function (): array {
			return $this->records->find( Records::id( $this->ledger->find( 'page', 'old-about' ), 'page', 'old-about' ) );
		};
		$this->ledger->record( $this->ids['about'], 'old-about' );

		$this->assertTrue( $this->records->save( $record(), untrailingslashit( get_permalink( $this->ids['history'] ) ), 0 ) );
		$this->assertSame( $this->ids['history'], $this->ledger->find( 'page', 'old-about' ) );

		$this->assertTrue( $this->records->save( $record(), get_permalink( $this->ids['team'] ) . '?ref=menu', 0 ) );
		$this->assertSame( $this->ids['team'], $this->ledger->find( 'page', 'old-about' ) );

		$this->assertTrue( $this->records->save( $record(), get_permalink( $this->ids['company'] ) . '#contact', 0 ) );
		$this->assertSame( $this->ids['company'], $this->ledger->find( 'page', 'old-about' ) );
		$this->assertNull( $this->ledger->target( 'page', 'old-about', $this->ids['company'] ), 'A page target, with no URL kept.' );
	}

	public function test_a_full_url_search_finds_any_record_at_that_exact_url_whatever_the_newest_list_holds(): void {
		// Written straight into post meta: not on the newest list, and the list does not say there are older ones.
		add_post_meta( $this->ids['about'], Ledger::key( 'page', 'not-listed' ), 'not-listed', true );
		$this->ledger->record_root( 'page', 'archive' );
		$this->assertSame( [ '/archive/' ], array_values( self::olds( $this->records->page( '', '', 1 ) ) ), 'Precondition: only the root list is listed.' );
		$this->assertFalse( $this->records->page( '', '', 1 )['capped'] );

		$this->assertSame( [ '/not-listed/' ], array_values( self::olds( $this->records->page( '', home_url( '/not-listed/' ), 1 ) ) ) );
		$this->assertSame( [ '/not-listed/' ], array_values( self::olds( $this->records->page( '', '/not-listed/', 1 ) ) ), 'A path works as well.' );
		$this->assertSame( [ '/archive/' ], array_values( self::olds( $this->records->page( '', home_url( '/archive/' ), 1 ) ) ), 'A root-list entry by its full URL.' );
	}

	public function test_an_id_on_the_newest_list_whose_row_is_gone_is_skipped(): void {
		global $wpdb;
		$this->ledger->record( $this->ids['about'], 'old-about' );
		$this->ledger->record( $this->ids['about'], 'old-gone' );
		$this->ledger->record( $this->ids['team'], 'crew' );
		// Deleted behind the plugin's back: the list still names it.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Test fixture.
		$wpdb->delete(
			$wpdb->postmeta,
			[
				'post_id'  => $this->ids['about'],
				'meta_key' => Ledger::key( 'page', 'old-gone' ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			]
		);
		wp_cache_flush();

		$page = $this->records->page( '', '', 1 );

		$this->assertSame( [ '/crew/', '/old-about/' ], array_values( self::olds( $page ) ) );
		$this->assertSame( 2, $page['total'] );
	}

	public function test_a_url_target_on_a_holder_under_a_trashed_parent_is_served_and_only_a_trashed_holder_is_not(): void {
		update_option( Settings::OPTION, [ 'serve_redirects' => true ] );
		$this->ledger->record( $this->ids['team'], 'crew' );
		$this->ledger->set_target( 'page', 'crew', $this->ids['team'], 'https://example.org/crew/', 0 );
		$this->ledger->record( $this->ids['history'], 'story' );
		$this->ledger->set_target( 'page', 'story', $this->ids['history'], 'https://example.org/story/', 0 );
		$this->ledger->record( $this->ids['leadership'], 'bosses' );
		$statuses = fn(): array => array_combine( array_column( $this->records->page( '', '', 1 )['rows'], 'old' ), $this->records->statuses( $this->records->page( '', '', 1 )['rows'] ) );

		// The parent goes to the Trash; the subpages stay (keep policy).
		wp_trash_post( $this->ids['about'] );
		wp_cache_flush();
		$this->assertSame(
			[
				'/bosses/' => Records::STATUS_UNPUBLISHED,
				'/crew/'   => Records::STATUS_ACTIVE,
				'/story/'  => Records::STATUS_ACTIVE,
			],
			$statuses(),
			'A URL target is served under a trashed parent; a page target there is not.'
		);
		$this->assertNotNull( ( new Resolver( $this->ledger ) )->resolve( 'page', 'crew' ), 'Rules agree: it is served.' );

		wp_trash_post( $this->ids['history'] );
		wp_cache_flush();
		$this->assertSame( Records::STATUS_UNPUBLISHED, $statuses()['/story/'], 'A holder in the Trash is not served.' );
	}

	public function test_a_top_level_entry_refuses_a_value_that_is_not_http_and_writes_nothing(): void {
		$this->ledger->record_root( 'page', 'archive' );
		$record = $this->records->find( Records::id( 0, 'page', 'archive' ) );
		$before = wp_cache_get_last_changed( Ledger::CACHE_GROUP );

		foreach ( [ 'javascript:alert(1)', 'data:text/html,x', 'vbscript:x', '<>' ] as $value ) {
			$result = $this->records->save( $record, $value, 302 );
			$this->assertInstanceOf( WP_Error::class, $result, $value );
			$this->assertSame( 'hierarchy_guard_not_http', $result->get_error_code(), $value );
		}

		$this->assertNull( $this->ledger->target( 'page', 'archive', 0 ), 'No override written.' );
		$this->assertFalse( get_option( Ledger::ROOT_TARGETS ) );
		$this->assertSame( $before, wp_cache_get_last_changed( Ledger::CACHE_GROUP ) );
	}
}
