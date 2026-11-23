<?php
/**
 * Other plugins' copies of the plugin's post meta.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Ledger;
use HierarchyGuard\Meta_Guard;
use HierarchyGuard\Redirects\Resolver;
use HierarchyGuard\Trash\Markers;
use WP_UnitTestCase;

/**
 * @covers \HierarchyGuard\Meta_Guard
 */
final class MetaGuardTest extends WP_UnitTestCase {

	use Trash_Fixtures;

	/**
	 * Page IDs.
	 *
	 * @var array<string,int>
	 */
	private array $ids;

	public function set_up(): void {
		parent::set_up();
		delete_option( Ledger::ROOT_OPTION );
		$this->set_permalink_structure( '/%postname%/' );
		$this->ids = $this->make_tree();
	}

	public function tear_down(): void {
		$this->set_permalink_structure( '' );
		parent::tear_down();
	}

	public function test_a_copy_of_a_renamed_page_takes_none_of_its_old_urls(): void {
		wp_update_post(
			[
				'ID'        => $this->ids['team'],
				'post_name' => 'team-new',
			]
		);
		$copy = $this->duplicate( $this->ids['team'] );

		$this->assertSame( [], $this->plugin_keys( $copy ) );
		wp_update_post(
			[
				'ID'          => $copy,
				'post_name'   => 'team-copy',
				'post_status' => 'publish',
			]
		);
		$this->assertSame( $this->ids['team'], $this->resolve( 'company/about/team' ) );
	}

	public function test_a_copy_of_a_moved_subpage_stays_where_it_is_when_the_trash_is_restored(): void {
		wp_trash_post( $this->ids['about'] );
		$this->assertSame( $this->ids['company'], (int) get_post( $this->ids['team'] )->post_parent, 'Precondition: the default policy moved it up.' );
		$copy = $this->duplicate( $this->ids['team'] );

		$this->assertSame( [], $this->plugin_keys( $copy ) );
		wp_untrash_post( $this->ids['about'] );

		$this->assertSame( $this->ids['about'], (int) get_post( $this->ids['team'] )->post_parent );
		$this->assertSame( $this->ids['company'], (int) get_post( $copy )->post_parent );
	}

	public function test_republishing_a_rewrite_copy_keeps_the_original_s_marker_so_a_restore_moves_it_back(): void {
		$copy = $this->duplicate( $this->ids['team'] );
		wp_trash_post( $this->ids['about'] );
		$this->assertSame( [ Markers::moved_key( $this->ids['about'] ) ], $this->plugin_keys( $copy ), 'Precondition: the trash moved the copy too.' );

		$this->republish( $copy, $this->ids['team'] );
		wp_untrash_post( $this->ids['about'] );

		$this->assertSame( $this->ids['about'], (int) get_post( $this->ids['team'] )->post_parent );
		$this->assertSame( [], $this->plugin_keys( $this->ids['team'] ), 'The restore took its marker off.' );
	}

	public function test_another_plugin_cannot_delete_the_plugins_rows_but_deleting_the_post_does(): void {
		$ledger = new Ledger();
		$ledger->record( $this->ids['history'], 'old-history' );
		$key = Ledger::key( 'page', 'old-history' );

		$this->assertFalse( delete_post_meta( $this->ids['history'], $key ) );
		$this->assertFalse( delete_post_meta_by_key( $key ) );
		$this->assertTrue( metadata_exists( 'post', $this->ids['history'], $key ) );

		wp_delete_post( $this->ids['history'], true );

		global $wpdb;
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d", $this->ids['history'] ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads what the delete left.
	}

	public function test_the_plugins_own_writes_still_land(): void {
		$ledger = new Ledger();
		$ledger->record( $this->ids['history'], 'old-history' );
		$ledger->set_target( 'page', 'old-history', $this->ids['history'], 'https://example.org/', 302 );
		wp_trash_post( $this->ids['about'] );

		$this->assertTrue( metadata_exists( 'post', $this->ids['history'], Ledger::key( 'page', 'old-history' ) ) );
		$this->assertTrue( metadata_exists( 'post', $this->ids['history'], Ledger::target_key( 'page', 'old-history' ) ) );
		$this->assertTrue( metadata_exists( 'post', $this->ids['about'], Markers::ALIASES ) );
		$this->assertTrue( metadata_exists( 'post', $this->ids['team'], Markers::moved_key( $this->ids['about'] ) ) );
	}

	public function test_only_the_plugin_or_an_import_adds_ledger_rows_and_nothing_adds_a_marker(): void {
		$path = Ledger::key( 'page', 'x' );

		$this->assertTrue( Meta_Guard::allowed( $path, true, false ) );
		$this->assertTrue( Meta_Guard::allowed( $path, false, true ) );
		$this->assertFalse( Meta_Guard::allowed( $path, false, false ) );
		$this->assertTrue( Meta_Guard::allowed( Markers::ALIASES, false, true ) );
		foreach ( [ Markers::moved_key( 7 ), Markers::trashed_key( 7 ) ] as $marker ) {
			$this->assertFalse( Meta_Guard::allowed( $marker, true, true ), $marker );
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_wordpress_import_adds_ledger_rows_but_no_marker(): void {
		define( 'WP_IMPORTING', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Core's import flag, set as an importer sets it.

		$this->assertIsInt( add_post_meta( $this->ids['team'], Ledger::key( 'page', 'imported' ), 'imported' ) );
		$this->assertFalse( add_post_meta( $this->ids['team'], Markers::moved_key( $this->ids['about'] ), 'x' ) );
	}

	public function test_writing_nests_and_ends_even_when_the_write_throws(): void {
		Meta_Guard::writing(
			function (): void {
				Meta_Guard::writing( static fn() => null );
				$this->assertIsInt( add_post_meta( $this->ids['team'], Ledger::key( 'page', 'outer' ), 'outer' ), 'Still writing once the inner write ends.' );
			}
		);
		try {
			Meta_Guard::writing(
				static function (): void {
					throw new \RuntimeException( 'The write failed.' );
				}
			);
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'The write failed.', $e->getMessage() );
		}

		$this->assertFalse( add_post_meta( $this->ids['team'], Ledger::key( 'page', 'after' ), 'after' ), 'No longer writing after the failed write.' );
	}

	public function test_other_meta_is_left_alone(): void {
		$this->assertIsInt( add_post_meta( $this->ids['team'], '_other_plugin', 'x' ) );
		$this->assertIsInt( add_post_meta( $this->ids['team'], 'hierarchy_guard_lookalike', 'x' ) );
		$this->assertFalse( add_post_meta( $this->ids['team'], Ledger::key( 'page', 'forged' ), 'forged' ) );
	}

	public function test_yoast_duplicate_post_leaves_the_keys_out(): void {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Yoast Duplicate Post's own filter.
		$this->assertSame( [ '_edit_lock', '_hierarchy_guard_*' ], apply_filters( 'duplicate_post_excludelist_filter', [ '_edit_lock' ] ) );
	}

	/**
	 * Copies a page as a duplicator that copies every meta row does, as Yoast
	 * Duplicate Post's Rewrite & Republish does without its exclude list: a
	 * draft with no slug under the same parent, with every meta row added
	 * again.
	 *
	 * @param int $post_id Page to copy.
	 * @return int The copy.
	 */
	private function duplicate( int $post_id ): int {
		$post = get_post( $post_id );
		$copy = self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_status' => 'draft',
				'post_title'  => $post->post_title,
				'post_parent' => $post->post_parent,
			]
		);
		foreach ( (array) get_post_custom_keys( $post_id ) as $key ) {
			foreach ( (array) get_post_custom_values( $key, $post_id ) as $value ) {
				add_post_meta( $copy, $key, wp_slash( maybe_unserialize( $value ) ) );
			}
		}
		return $copy;
	}

	/**
	 * Copies a copy's meta onto the original as Yoast Duplicate Post's
	 * republish does: each of the copy's keys deleted on the original, then
	 * added again with the copy's values.
	 *
	 * @param int $copy     The copy.
	 * @param int $original The original.
	 */
	private function republish( int $copy, int $original ): void {
		foreach ( (array) get_post_custom_keys( $copy ) as $key ) {
			delete_post_meta( $original, $key );
			foreach ( (array) get_post_custom_values( $key, $copy ) as $value ) {
				add_post_meta( $original, $key, wp_slash( maybe_unserialize( $value ) ) );
			}
		}
	}

	/**
	 * A post's meta keys of the plugin.
	 *
	 * @param int $post_id Post ID.
	 * @return string[]
	 */
	private function plugin_keys( int $post_id ): array {
		return array_values( array_filter( array_keys( (array) get_post_meta( $post_id ) ), static fn( $key ): bool => str_starts_with( (string) $key, Meta_Guard::PREFIX ) ) );
	}

	/**
	 * The ID of the page an old path resolves to, or 0.
	 *
	 * @param string $path Old path.
	 * @return int
	 */
	private function resolve( string $path ): int {
		$post = ( new Resolver( new Ledger() ) )->resolve( 'page', $path );
		return $post instanceof \WP_Post ? (int) $post->ID : 0;
	}
}
