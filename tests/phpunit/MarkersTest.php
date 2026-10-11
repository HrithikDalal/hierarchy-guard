<?php
/**
 * Subpage markers.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Trash\Markers;
use WP_UnitTestCase;

/**
 * @covers \HierarchyGuard\Trash\Markers
 */
final class MarkersTest extends WP_UnitTestCase {

	public function test_both_filters_are_registered_by_the_plugin(): void {
		global $wp_filter;
		foreach ( [
			'wxr_export_skip_postmeta' => 10,
			'wp_untrash_post_status'   => 20,
		] as $hook => $priority ) {
			$found = false;
			foreach ( $wp_filter[ $hook ]->callbacks[ $priority ] ?? [] as $callback ) {
				if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof Markers ) {
					$found = true;
				}
			}
			$this->assertTrue( $found, $hook );
		}
	}

	public function test_a_subpage_trashed_with_its_parent_comes_back_with_its_previous_status(): void {
		$with_parent = self::factory()->post->create( [ 'post_type' => 'page' ] );
		$on_its_own  = self::factory()->post->create( [ 'post_type' => 'page' ] );
		wp_trash_post( $with_parent );
		wp_trash_post( $on_its_own );
		Markers::add( $with_parent, Markers::trashed_key( 9 ), '1' );

		wp_untrash_post( $with_parent );
		wp_untrash_post( $on_its_own );

		$this->assertSame( 'publish', get_post_status( $with_parent ) );
		$this->assertSame( 'draft', get_post_status( $on_its_own ), 'Core restores everything else as a draft.' );
	}

	public function test_markers_are_left_out_of_exports_and_ledger_entries_stay_in(): void {
		$markers = new Markers();
		foreach ( [ Markers::moved_key( 12 ), Markers::trashed_key( 12 ), Markers::ALIASES ] as $key ) {
			$this->assertTrue( $markers->skip_on_export( false, $key ), $key );
		}
		$this->assertFalse( $markers->skip_on_export( false, '_hierarchy_guard_path_abc' ), 'Old-path entries stay exportable.' );
		$this->assertTrue( $markers->skip_on_export( true, 'anything' ), 'An earlier skip is kept.' );
	}

	public function test_moved_values_round_trip(): void {
		$value = Markers::encode_moved( 7, 'team', 'team-2', 1700000000 );

		$this->assertSame( '7|team|team-2|1700000000', $value );
		$this->assertSame(
			[
				'to'       => 7,
				'original' => 'team',
				'assigned' => 'team-2',
				'time'     => 1700000000,
			],
			Markers::decode_moved( $value )
		);
		$this->assertSame( 0, Markers::decode_moved( '' )['to'], 'A damaged value decodes to safe defaults.' );
	}

	public function test_rows_are_read_oldest_first_after_a_cursor_and_removed(): void {
		$a = self::factory()->post->create();
		$b = self::factory()->post->create();
		Markers::add( $a, Markers::moved_key( 9 ), 'first' );
		Markers::add( $b, Markers::moved_key( 9 ), 'second' );
		Markers::add( $b, Markers::moved_key( 10 ), 'other root' );

		$rows = Markers::rows( Markers::moved_key( 9 ), 10 );
		$this->assertSame( [ $a, $b ], array_column( $rows, 'post_id' ) );
		$this->assertSame( [ 'first', 'second' ], array_column( $rows, 'value' ) );
		$this->assertSame( 'first', get_post_meta( $a, Markers::moved_key( 9 ), true ), 'The meta cache sees the row.' );

		$this->assertSame( [ 'second' ], array_column( Markers::rows( Markers::moved_key( 9 ), 10, $rows[0]['meta_id'] ), 'value' ) );
		$this->assertCount( 1, Markers::rows( Markers::moved_key( 9 ), 1 ) );

		Markers::remove( $rows );

		$this->assertSame( [], Markers::rows( Markers::moved_key( 9 ), 10 ) );
		$this->assertSame( '', get_post_meta( $a, Markers::moved_key( 9 ), true ), 'The meta cache is cleared.' );
		$this->assertCount( 1, Markers::rows( Markers::moved_key( 10 ), 10 ), 'Other roots are untouched.' );
	}

	public function test_count_covers_both_kinds_and_stops_at_the_cap(): void {
		$a = self::factory()->post->create();
		$b = self::factory()->post->create();
		Markers::add( $a, Markers::moved_key( 9 ), '1' );
		Markers::add( $b, Markers::moved_key( 9 ), '1' );
		Markers::add( $b, Markers::trashed_key( 9 ), '1' );

		$this->assertSame( 3, Markers::count( 9, 10 ) );
		$this->assertSame( 2, Markers::count( 9, 2 ) );
		$this->assertSame( 0, Markers::count( 10, 10 ) );
	}

	public function test_a_post_s_own_trashed_rows_are_found_and_cleared(): void {
		$a = self::factory()->post->create();
		Markers::add( $a, Markers::trashed_key( 9 ), '1' );
		Markers::add( $a, Markers::moved_key( 9 ), '1' );

		$this->assertTrue( Markers::has_trashed_row( $a ) );
		$this->assertSame( 1, Markers::clear_trashed_rows( $a ) );
		$this->assertFalse( Markers::has_trashed_row( $a ) );
		$this->assertCount( 1, Markers::rows( Markers::moved_key( 9 ), 10 ), 'Moved rows are not trashed rows.' );
		$this->assertSame( 0, Markers::clear_trashed_rows( $a ) );
	}

	public function test_remove_key_deletes_one_post_s_row(): void {
		$a = self::factory()->post->create();
		$b = self::factory()->post->create();
		Markers::add( $a, Markers::trashed_key( 9 ), '1' );
		Markers::add( $b, Markers::trashed_key( 9 ), '1' );

		Markers::remove_key( $a, Markers::trashed_key( 9 ) );

		$this->assertSame( [ $b ], array_column( Markers::rows( Markers::trashed_key( 9 ), 10 ), 'post_id' ) );
	}
}
