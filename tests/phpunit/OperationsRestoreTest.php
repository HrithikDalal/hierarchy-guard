<?php
/**
 * The restore and cleanup steps.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Ledger;
use HierarchyGuard\Post_Types;
use HierarchyGuard\Settings;
use HierarchyGuard\Trash\Guard;
use HierarchyGuard\Trash\Markers;
use HierarchyGuard\Trash\Mover;
use HierarchyGuard\Trash\Operations;
use WP_UnitTestCase;

/**
 * @covers \HierarchyGuard\Trash\Operations
 */
final class OperationsRestoreTest extends WP_UnitTestCase {

	use Trash_Fixtures;

	/**
	 * Page IDs.
	 *
	 * @var array<string,int>
	 */
	private array $ids;

	private Ledger $ledger;

	private Operations $operations;

	public function set_up(): void {
		parent::set_up();
		delete_option( Settings::OPTION );
		delete_option( Ledger::ROOT_OPTION );
		Post_Types::reset();
		$this->set_permalink_structure( '/%postname%/' );
		$this->ledger     = new Ledger();
		$this->operations = new Operations( $this->ledger, new Mover() );
		$this->ids        = $this->make_tree();
		$this->trash_raw( $this->ids['about'] );
	}

	public function tear_down(): void {
		$this->end_core_bulk();
		$this->set_permalink_structure( '' );
		parent::tear_down();
	}

	/**
	 * Runs the reparent job for about.
	 */
	private function move_out(): void {
		$this->operations->run(
			Operations::new_job(
				Operations::TYPE_REPARENT,
				$this->ids['about'],
				'page',
				0,
				[
					'to'    => $this->ids['company'],
					'paths' => [ 'company/about' ],
				]
			)
		);
	}

	/**
	 * Runs the trash-subtree job for about.
	 */
	private function trash_out(): void {
		$this->operations->run( Operations::new_job( Operations::TYPE_TRASH, $this->ids['about'], 'page', 0 ) );
	}

	/**
	 * Runs the restore job for about.
	 *
	 * @return array<string,mixed>
	 */
	private function restore(): array {
		return $this->operations->run( Operations::new_job( Operations::TYPE_RESTORE, $this->ids['about'], 'page', 0 ) );
	}

	public function test_moved_children_go_back_under_the_restored_parent(): void {
		$this->move_out();
		$this->untrash_raw( $this->ids['about'] );

		$job = $this->restore();

		$this->assertTrue( $job['finished'] );
		foreach ( [ 'team', 'history' ] as $slug ) {
			$this->assertSame( $this->ids['about'], (int) get_post( $this->ids[ $slug ] )->post_parent, $slug );
		}
		$this->assertSame( [], Markers::rows( Markers::moved_key( $this->ids['about'] ), 10 ), 'Each row is deleted once handled.' );
		$this->assertSame( 2, $job['counts']['moved'] );
		$this->assertNull( $this->ledger->find( 'page', 'company/team' ), 'An undo right away records no interim URL.' );
	}

	public function test_an_older_move_keeps_its_interim_url(): void {
		$this->move_out();
		$this->age_moved_markers( $this->ids['about'], 2 * HOUR_IN_SECONDS );
		$this->untrash_raw( $this->ids['about'] );

		$this->restore();

		$this->assertSame( $this->ids['team'], $this->ledger->find( 'page', 'company/team' ), 'Links made while the parent was in the trash keep working.' );
	}

	public function test_the_aliases_are_removed_once_the_parent_is_back(): void {
		$this->move_out();
		$this->assertSame( $this->ids['company'], $this->ledger->find( 'page', 'company/about' ), 'Precondition.' );
		$this->untrash_raw( $this->ids['about'] );

		$this->restore();

		$this->assertNull( $this->ledger->find( 'page', 'company/about' ) );
		$this->assertSame( '', get_post_meta( $this->ids['about'], Markers::ALIASES, true ) );
	}

	public function test_a_parent_that_came_back_at_another_path_takes_over_its_old_path(): void {
		global $wpdb;

		$this->move_out();
		$this->untrash_raw( $this->ids['about'] );
		// What core does when another page took "about" while this one was in the trash.
		$wpdb->update( $wpdb->posts, [ 'post_name' => 'about-2' ], [ 'ID' => $this->ids['about'] ] );
		clean_post_cache( $this->ids['about'] );

		$this->restore();

		$this->assertSame( $this->ids['about'], $this->ledger->find( 'page', 'company/about' ) );
	}

	public function test_a_renamed_child_gets_its_original_slug_back(): void {
		$this->page( 'team', $this->ids['company'] );
		$this->move_out();
		$this->assertSame( 'team-2', get_post( $this->ids['team'] )->post_name, 'Precondition.' );
		$this->untrash_raw( $this->ids['about'] );

		$job = $this->restore();

		$this->assertSame( 'team', get_post( $this->ids['team'] )->post_name );
		$this->assertSame( 0, $job['counts']['renamed'] );
		$this->assertNull( $this->ledger->find( 'page', 'company/about/team' ), 'The entry equal to the current path is pruned.' );
	}

	public function test_a_child_an_editor_renamed_since_keeps_its_new_slug(): void {
		$this->page( 'team', $this->ids['company'] );
		$this->move_out();
		wp_update_post(
			[
				'ID'        => $this->ids['team'],
				'post_name' => 'crew',
			]
		);
		$this->untrash_raw( $this->ids['about'] );

		$this->restore();

		$this->assertSame( 'crew', get_post( $this->ids['team'] )->post_name );
		$this->assertSame( $this->ids['about'], (int) get_post( $this->ids['team'] )->post_parent );
	}

	public function test_a_child_moved_elsewhere_is_left_alone(): void {
		$this->move_out();
		wp_update_post(
			[
				'ID'          => $this->ids['team'],
				'post_parent' => $this->ids['history'],
			]
		);
		$this->untrash_raw( $this->ids['about'] );

		$job = $this->restore();

		$this->assertSame( $this->ids['history'], (int) get_post( $this->ids['team'] )->post_parent );
		$this->assertSame( '', get_post_meta( $this->ids['team'], Markers::moved_key( $this->ids['about'] ), true ), 'Its row is deleted either way.' );
		$this->assertSame( 1, $job['counts']['left'] );
		$this->assertSame( 1, $job['counts']['moved'], 'Only history moves back.' );
	}

	public function test_a_moved_child_trashed_since_goes_back_under_its_parent_in_the_trash(): void {
		$this->page( 'team', $this->ids['company'] );
		$this->move_out();
		$this->trash_raw( $this->ids['team'] );
		$this->assertSame( 'team-2', get_post_meta( $this->ids['team'], '_wp_desired_post_slug', true ), 'Precondition.' );
		$this->untrash_raw( $this->ids['about'] );

		$this->restore();

		$this->assertSame( 'trash', get_post_status( $this->ids['team'] ) );
		$this->assertSame( $this->ids['about'], (int) get_post( $this->ids['team'] )->post_parent );
		$this->assertSame( 'team', get_post_meta( $this->ids['team'], '_wp_desired_post_slug', true ), 'Restoring it later gives back its original slug.' );
	}

	public function test_subpages_trashed_with_the_parent_return_with_their_previous_status(): void {
		$plans = $this->page( 'plans', $this->ids['team'], 'draft' );
		$this->trash_out();
		$this->untrash_raw( $this->ids['about'] );

		$job = $this->restore();

		$this->assertSame( 'publish', get_post_status( $this->ids['team'] ) );
		$this->assertSame( 'publish', get_post_status( $this->ids['leadership'] ) );
		$this->assertSame( 'draft', get_post_status( $plans ) );
		$this->assertSame( [], Markers::rows( Markers::trashed_key( $this->ids['about'] ), 10 ) );
		$this->assertSame( 4, $job['counts']['restored'] );
	}

	public function test_covers_each_subpage_while_restoring_it(): void {
		$this->trash_out();
		$this->untrash_raw( $this->ids['about'] );
		$covered = [];
		add_action(
			'untrash_post',
			static function ( $post_id ) use ( &$covered ): void {
				$covered[] = Guard::covers( (int) $post_id );
			}
		);

		$this->restore();

		$this->assertSame( [ true, true, true ], $covered );
	}

	public function test_subpages_core_is_about_to_restore_are_left_to_core(): void {
		$this->trash_out();
		$this->untrash_raw( $this->ids['about'] );
		$this->start_core_bulk( 'untrash', [ $this->ids['about'], $this->ids['team'] ] );

		$this->restore();

		$this->assertSame( 'trash', get_post_status( $this->ids['team'] ), 'Core restores it next in its bulk loop.' );
		$this->assertSame( [ $this->ids['team'] ], $this->marked_ids( Markers::trashed_key( $this->ids['about'] ) ), 'Its row stays so core gives it its previous status.' );
		$this->assertSame( 'publish', get_post_status( $this->ids['history'] ) );
	}

	public function test_stops_while_the_parent_is_in_the_trash(): void {
		$this->move_out();

		$job = $this->restore();

		$this->assertTrue( $job['finished'] );
		$this->assertSame( 0, $job['counts']['moved'] );
		$this->assertSame( $this->ids['company'], (int) get_post( $this->ids['team'] )->post_parent );
	}

	public function test_cleanup_deletes_the_rows_of_a_deleted_root(): void {
		$this->move_out();
		Markers::add( $this->ids['leadership'], Markers::trashed_key( $this->ids['about'] ), '1' );

		$job = Operations::new_job( Operations::TYPE_CLEANUP, $this->ids['about'], 'page', 0 );
		$job = $this->operations->step( $job, 1, 0 );
		$this->assertFalse( $job['finished'] );

		$job = $this->operations->run( $job );

		$this->assertTrue( $job['finished'] );
		$this->assertSame( 0, Markers::count( $this->ids['about'], 10 ) );
	}

	public function test_an_untrash_another_plugin_answers_true_for_is_not_counted(): void {
		$this->trash_out();
		$this->untrash_raw( $this->ids['about'] );
		$blocked = $this->ids['team'];
		add_filter( 'pre_untrash_post', static fn( $check, $post ) => $post->ID === $blocked ? true : $check, 10, 2 );

		$job = $this->restore();

		$this->assertSame( 'trash', get_post_status( $blocked ) );
		$this->assertSame( 'publish', get_post_status( $this->ids['history'] ) );
		$this->assertSame( 2, $job['counts']['restored'] );
		$this->assertSame( 1, $job['counts']['left'] );
	}
}
