<?php
/**
 * Reading core's bulk selection.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Trash\Request;
use WP_UnitTestCase;

/**
 * @covers \HierarchyGuard\Trash\Request
 */
final class RequestTest extends WP_UnitTestCase {

	use Trash_Fixtures;

	public function tear_down(): void {
		$this->end_core_bulk();
		parent::tear_down();
	}

	public function test_reads_the_bulk_selection_of_a_trash(): void {
		$this->start_core_bulk( 'trash', [ 12, 13, 12 ] );
		$this->assertSame( [ 12, 13 ], Request::core_ids() );
	}

	public function test_reads_the_ids_list_of_an_undo(): void {
		$this->start_core_bulk( 'untrash', [ 12, 13 ], true );
		$this->assertSame( [ 12, 13 ], Request::core_ids() );
	}

	public function test_reads_the_selection_of_a_bulk_edit_in_its_order(): void {
		$this->start_core_bulk( 'edit', [ 13, 12, 13 ] );
		$this->assertSame( [], Request::bulk_edit_ids(), 'Not the Bulk Edit form.' );

		$_REQUEST['bulk_edit'] = 'Update';
		$this->assertSame( [ 13, 12 ], Request::bulk_edit_ids() );
		$this->assertSame( [], Request::core_ids(), 'No trash or restore.' );

		$GLOBALS['pagenow'] = 'post.php';
		$this->assertSame( [], Request::bulk_edit_ids(), 'Only the list screen saves a selection.' );
	}

	public function test_a_bulk_edit_selection_is_read_once_until_it_changes(): void {
		$this->start_core_bulk( 'edit', range( 1, 999 ) );
		$_REQUEST['bulk_edit'] = 'Update';
		$parses                = 0;
		add_filter(
			'sanitize_text_field',
			static function ( $filtered, $str ) use ( &$parses ) {
				if ( '999' === $str ) {
					++$parses;
				}
				return $filtered;
			},
			10,
			2
		);

		for ( $i = 0; $i < 1998; $i++ ) {
			$ids = Request::bulk_edit_ids();
		}

		$this->assertSame( 1, $parses, 'A Bulk Edit of 999 pages asks twice per saved page; the selection is parsed once.' );
		$this->assertSame( range( 1, 999 ), $ids );
		$this->assertSame( implode( ',', range( 1, 999 ) ), Request::bulk_edit_key() );
		$this->assertSame( 1, $parses );

		$_REQUEST['post'] = [ '5', '3', '5' ];
		$this->assertSame( [ 5, 3 ], Request::bulk_edit_ids(), 'A different selection is read again.' );
		$this->assertSame( '5,3', Request::bulk_edit_key() );
		unset( $_REQUEST['bulk_edit'] );
		$this->assertSame( [], Request::bulk_edit_ids(), 'Not the Bulk Edit form.' );
		$this->assertSame( '', Request::bulk_edit_key() );
	}

	public function test_other_screens_and_actions_are_ignored(): void {
		$this->start_core_bulk( 'edit', [ 12 ] );
		$this->assertSame( [], Request::core_ids(), 'Not a trash or untrash.' );

		$this->start_core_bulk( 'trash', [ 12 ] );
		$_REQUEST['filter_action'] = 'Filter';
		$this->assertSame( [], Request::core_ids(), 'The Filter button runs no bulk action.' );

		$this->end_core_bulk();
		$_REQUEST['action'] = 'trash';
		$_REQUEST['post']   = [ '12' ];
		$this->assertSame( [], Request::core_ids(), 'Only the list screen loops over a selection.' );
	}
}
