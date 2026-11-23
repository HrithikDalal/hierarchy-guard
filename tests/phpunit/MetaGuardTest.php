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
	 * Copies a page as Yoast Duplicate Post does by default: a draft with no
	 * slug under the same parent, with every meta row added again.
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
