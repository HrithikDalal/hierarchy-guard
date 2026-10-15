<?php
/**
 * The trash flow end to end through core's APIs.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Admin\Notices;
use HierarchyGuard\Batch\Processor;
use HierarchyGuard\Batch\Queue;
use HierarchyGuard\Ledger;
use HierarchyGuard\Post_Types;
use HierarchyGuard\Redirects\Pairs;
use HierarchyGuard\Redirects\Resolver;
use HierarchyGuard\Settings;
use HierarchyGuard\Subtree;
use HierarchyGuard\Trash\Change_Log;
use HierarchyGuard\Trash\Markers;
use HierarchyGuard\Trash\Mover;
use HierarchyGuard\Trash\Operations;
use HierarchyGuard\Trash\Trash_Handler;
use WP_Error;
use WP_UnitTestCase;

/**
 * @covers \HierarchyGuard\Trash\Trash_Handler
 */
final class TrashHandlerTest extends WP_UnitTestCase {

	use Trash_Fixtures;

	/**
	 * Page IDs.
	 *
	 * @var array<string,int>
	 */
	private array $ids;

	private int $admin;

	private Ledger $ledger;

	private Resolver $resolver;

	private Queue $queue;

	private Change_Log $log;

	public function set_up(): void {
		parent::set_up();
		delete_option( Settings::OPTION );
		delete_option( Ledger::ROOT_OPTION );
		Post_Types::reset();
		Change_Log::reset_request();
		$this->handler()->reset_request_budget();
		$this->set_permalink_structure( '/%postname%/' );
		$this->admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $this->admin );
		$this->ledger   = new Ledger();
		$this->resolver = new Resolver( $this->ledger );
		$this->queue    = new Queue();
		$this->log      = new Change_Log();
		$this->ids      = $this->make_tree();
	}

	public function tear_down(): void {
		$this->end_core_bulk();
		wp_unschedule_hook( Queue::HOOK );
		unregister_post_type( 'hg_private' );
		$this->set_permalink_structure( '' );
		parent::tear_down();
	}

	/**
	 * The handler the plugin registered.
	 */
	private function handler(): Trash_Handler {
		global $wp_filter;
		foreach ( $wp_filter['trashed_post']->callbacks[10] ?? [] as $callback ) {
			if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof Trash_Handler ) {
				return $callback['function'][0];
			}
		}
		$this->fail( 'No Trash_Handler registered on trashed_post.' );
	}

	/**
	 * A processor like the plugin's.
	 */
	private function processor(): Processor {
		return new Processor( $this->queue, new Operations( $this->ledger, new Mover() ), $this->log );
	}

	/**
	 * Uses a fixed policy for the rest of the test.
	 *
	 * @param string $policy Policy.
	 */
	private function policy( string $policy ): void {
		add_filter( 'hierarchy_guard_trash_policy', static fn(): string => $policy );
	}

	/**
	 * Adds subpages under about.
	 *
	 * @param string[] $slugs Slugs.
	 */
	private function more_subpages( array $slugs ): void {
		foreach ( $slugs as $slug ) {
			$this->ids[ $slug ] = $this->page( $slug, $this->ids['about'] );
		}
	}

	/**
	 * The current user's change summary.
	 *
	 * @return array<string,mixed>
	 */
	private function change(): array {
		return (array) $this->log->latest( $this->admin );
	}

	/**
	 * Adds a top-level page that keeps its subpages where they are when it is trashed.
	 *
	 * @param string   $slug     Slug.
	 * @param string[] $subpages Its subpages' slugs.
	 * @return int Page ID.
	 */
	private function keep_policy_page( string $slug, array $subpages ): int {
		$id = $this->page( $slug );
		foreach ( $subpages as $subpage ) {
			$this->page( $subpage, $id );
		}
		add_filter(
			'hierarchy_guard_trash_policy',
			static fn( string $policy, \WP_Post $post ): string => $id === $post->ID ? Settings::POLICY_KEEP : $policy,
			10,
			2
		);
		return $id;
	}

	/**
	 * How many old URLs the user's stored change lists, as a later request reads it.
	 *
	 * @return int
	 */
	private function stored_pair_count(): int {
		return $this->in_another_process(
			function (): int {
				$pairs = new Pairs();
				return count( $pairs->page( $pairs->collect_change( (array) ( new Change_Log() )->latest( $this->admin ) ), 1, 1000 )['pairs'] );
			}
		);
	}

	/**
	 * Runs core's bulk loop the way edit.php does, failing where core would wp_die().
	 *
	 * @param string $action trash, untrash or undo.
	 * @param int[]  $ids    Selected IDs.
	 */
	private function core_bulk( string $action, array $ids ): void {
		$undo = 'undo' === $action;
		$this->start_core_bulk( $undo ? 'untrash' : $action, $ids, $undo );
		if ( $undo ) {
			add_filter( 'wp_untrash_post_status', 'wp_untrash_post_set_previous_status', 10, 3 );
		}
		foreach ( $ids as $id ) {
			$result = 'trash' === $action ? wp_trash_post( $id ) : wp_untrash_post( $id );
			$this->assertNotFalse( $result, "Core's bulk loop would stop with wp_die() at page {$id}." );
		}
		if ( $undo ) {
			remove_filter( 'wp_untrash_post_status', 'wp_untrash_post_set_previous_status', 10 );
		}
		$this->end_core_bulk();
	}

	public function test_the_handler_is_registered_by_the_plugin(): void {
		global $wp_filter;
		$hooks = [
			'wp_trash_post'          => 10,
			'trashed_post'           => 10,
			'transition_post_status' => 10,
			'wp_after_insert_post'   => 20,
			'shutdown'               => 10,
			'deleted_post'           => 10,
			'wp_untrash_post_status' => 20,
		];
		foreach ( $hooks as $hook => $priority ) {
			$found = false;
			foreach ( $wp_filter[ $hook ]->callbacks[ $priority ] ?? [] as $callback ) {
				if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof Trash_Handler ) {
					$found = true;
				}
			}
			$this->assertTrue( $found, $hook );
		}
	}

	public function test_trashing_a_parent_moves_its_subpages_up_and_keeps_their_urls(): void {
		wp_trash_post( $this->ids['about'] );

		$this->assertSame( $this->ids['company'], (int) get_post( $this->ids['team'] )->post_parent );
		$this->assertSame( $this->ids['company'], (int) get_post( $this->ids['history'] )->post_parent );
		$this->assertSame( $this->ids['team'], (int) get_post( $this->ids['leadership'] )->post_parent );
		$this->assertSame( $this->ids['team'], $this->resolver->resolve( 'page', 'company/about/team' )->ID );
		$this->assertSame( $this->ids['leadership'], $this->resolver->resolve( 'page', 'company/about/team/leadership' )->ID );
		$this->assertNull( $this->resolver->resolve( 'page', 'company/about' ), 'The trashed page itself is not redirected.' );

		$change = $this->change();
		$root   = $change['roots'][ $this->ids['about'] ];
		$this->assertSame( 'trash', $change['action'] );
		$this->assertSame( 'page', $change['post_type'] );
		$this->assertSame( Settings::POLICY_REPARENT, $root['policy'] );
		$this->assertSame( $this->ids['company'], $root['to'] );
		$this->assertSame( 2, $root['counts']['moved'] );
		$this->assertSame( 3, $root['urls']['count'] );
		$this->assertFalse( $root['job'] );
	}

	public function test_colliding_slug_gets_a_number_and_its_old_url_still_resolves(): void {
		$this->page( 'team', $this->ids['company'] );

		wp_trash_post( $this->ids['about'] );

		$this->assertSame( 'team-2', get_post( $this->ids['team'] )->post_name );
		$this->assertSame( $this->ids['team'], $this->resolver->resolve( 'page', 'company/about/team' )->ID );
		$this->assertSame( $this->ids['leadership'], $this->resolver->resolve( 'page', 'company/about/team/leadership' )->ID );
	}

	public function test_subpages_the_user_cannot_edit_stay_put(): void {
		$editor = self::factory()->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $editor );
		$locked = $this->ids['history'];
		add_filter(
			'map_meta_cap',
			static function ( array $caps, string $cap, int $user_id, array $args ) use ( $locked ): array {
				return 'edit_post' === $cap && isset( $args[0] ) && (int) $args[0] === $locked ? [ 'do_not_allow' ] : $caps;
			},
			10,
			4
		);

		wp_trash_post( $this->ids['about'] );

		$this->assertSame( $this->ids['company'], (int) get_post( $this->ids['team'] )->post_parent );
		$this->assertSame( $this->ids['about'], (int) get_post( $locked )->post_parent );
		$root = $this->log->latest( $editor )['roots'][ $this->ids['about'] ];
		$this->assertSame( 1, $root['counts']['denied'] );
		$this->assertSame( [ $locked ], $root['kept_ids'] );
	}

	public function test_a_failed_trash_changes_nothing(): void {
		add_filter( 'wp_insert_post_empty_content', '__return_true' );

		$this->assertFalse( wp_trash_post( $this->ids['about'] ) );

		$this->assertSame( 'publish', get_post_status( $this->ids['about'] ) );
		$this->assertSame( $this->ids['about'], (int) get_post( $this->ids['team'] )->post_parent );
		$this->assertNull( $this->ledger->find( 'page', 'company/about' ) );
		$this->assertSame( [], $this->change() );
	}

	public function test_the_keep_policy_leaves_subpages_in_place(): void {
		$this->policy( Settings::POLICY_KEEP );

		wp_trash_post( $this->ids['about'] );

		$this->assertSame( $this->ids['about'], (int) get_post( $this->ids['team'] )->post_parent );
		$root = $this->change()['roots'][ $this->ids['about'] ];
		$this->assertSame( Settings::POLICY_KEEP, $root['policy'] );
		$this->assertSame( 2, $root['counts']['kept'] );
		$this->assertSame( 3, $root['urls']['count'] );
	}

	public function test_a_page_without_subpages_is_left_to_core(): void {
		wp_trash_post( $this->ids['history'] );

		$this->assertSame( [], $this->change() );
		$this->assertSame( [], $this->queue->roots() );
	}

	public function test_trash_children_then_undo_restores_previous_statuses(): void {
		$this->policy( Settings::POLICY_TRASH_CHILDREN );
		$plans = $this->page( 'plans', $this->ids['team'], 'draft' );

		wp_trash_post( $this->ids['about'] );
		foreach ( [ 'team', 'history', 'leadership' ] as $slug ) {
			$this->assertSame( 'trash', get_post_status( $this->ids[ $slug ] ), $slug );
		}
		$this->assertSame( 'trash', get_post_status( $plans ) );

		$this->assertTrue( $this->handler()->undo( $this->ids['about'] ) );

		$this->assertSame( 'publish', get_post_status( $this->ids['about'] ) );
		foreach ( [ 'team', 'history', 'leadership' ] as $slug ) {
			$this->assertSame( 'publish', get_post_status( $this->ids[ $slug ] ), $slug );
		}
		$this->assertSame( 'draft', get_post_status( $plans ) );
	}

	public function test_core_s_restore_makes_the_parent_a_draft_and_subpages_keep_their_statuses(): void {
		$this->policy( Settings::POLICY_TRASH_CHILDREN );
		$plans = $this->page( 'plans', $this->ids['team'], 'draft' );
		wp_trash_post( $this->ids['about'] );

		wp_untrash_post( $this->ids['about'] );

		$this->assertSame( 'draft', get_post_status( $this->ids['about'] ), 'As core does.' );
		$this->assertSame( 'publish', get_post_status( $this->ids['team'] ) );
		$this->assertSame( 'publish', get_post_status( $this->ids['leadership'] ) );
		$this->assertSame( 'draft', get_post_status( $plans ) );
	}

	public function test_restoring_through_a_status_update_moves_subpages_back_after_the_save(): void {
		wp_trash_post( $this->ids['about'] );
		$during = null;
		$about  = $this->ids['about'];
		$team   = $this->ids['team'];
		add_action(
			'save_post_page',
			static function ( int $post_id ) use ( &$during, $about, $team ): void {
				if ( $post_id === $about ) {
					$during = (int) get_post( $team )->post_parent;
				}
			}
		);

		wp_update_post(
			[
				'ID'          => $this->ids['about'],
				'post_status' => 'publish',
			]
		);

		$this->assertSame( $this->ids['company'], $during, "The page's own save completes first." );
		$this->assertSame( 'about', get_post( $this->ids['about'] )->post_name, 'Precondition: core restored the slug.' );
		$this->assertSame( $this->ids['about'], (int) get_post( $this->ids['team'] )->post_parent );
		$this->assertNull( $this->ledger->find( 'page', 'company/about' ), 'The alias is gone once the page is back.' );
		$this->assertSame( 'restore', $this->change()['action'] );
		$this->assertSame( 2, $this->change()['counts']['moved'] );
	}

	public function test_undo_restores_the_parent_with_its_status_and_brings_subpages_back(): void {
		wp_trash_post( $this->ids['about'] );

		$this->assertTrue( $this->handler()->undo( $this->ids['about'] ) );

		$this->assertSame( 'publish', get_post_status( $this->ids['about'] ), 'Core alone would restore it as a draft.' );
		$this->assertSame( $this->ids['about'], (int) get_post( $this->ids['history'] )->post_parent );
	}

	public function test_undo_explains_why_it_cannot_run(): void {
		$result = $this->handler()->undo( $this->ids['about'] );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'hierarchy_guard_not_in_trash', $result->get_error_code() );
		$this->assertSame( '“About” is no longer in the trash.', $result->get_error_message() );

		wp_trash_post( $this->ids['about'] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$result = $this->handler()->undo( $this->ids['about'] );
		$this->assertSame( 'hierarchy_guard_forbidden', $result->get_error_code() );
		$this->assertSame( 'trash', get_post_status( $this->ids['about'] ) );

		wp_set_current_user( $this->admin );
		add_filter( 'pre_untrash_post', '__return_true' );
		$result = $this->handler()->undo( $this->ids['about'] );
		$this->assertSame( 'hierarchy_guard_restore_failed', $result->get_error_code(), 'A filter that answers true without restoring is no success.' );
		$this->assertSame( '“About” could not be restored from the trash.', $result->get_error_message() );
	}

	public function test_large_subtrees_move_in_background_batches(): void {
		add_filter( 'hierarchy_guard_batch_threshold', static fn(): int => 2 );
		$this->more_subpages( [ 'a', 'b', 'c' ] );

		wp_trash_post( $this->ids['about'] );

		$this->assertSame( 3, Subtree::count_live_children( $this->ids['about'], 'page', 10 ), 'Only the first batch ran inline.' );
		$this->assertTrue( $this->queue->is_scheduled( $this->ids['about'] ) );
		$this->assertTrue( $this->change()['roots'][ $this->ids['about'] ]['job'] );

		$this->processor()->run( $this->ids['about'] );

		$this->assertSame( 0, Subtree::count_live_children( $this->ids['about'], 'page', 10 ) );
		$this->assertNull( $this->queue->load( $this->ids['about'] ) );
		$root = $this->change()['roots'][ $this->ids['about'] ];
		$this->assertSame( 5, $root['counts']['moved'] );
		$this->assertFalse( $root['job'] );
	}

	/**
	 * Adds numbered subpages under about until it has this many descendants.
	 *
	 * @param int $descendants Descendants about ends up with.
	 */
	private function fill_about( int $descendants ): void {
		for ( $i = 3; $i < $descendants; $i++ ) {
			$this->page( "kid-{$i}", $this->ids['about'] );
		}
	}

	/**
	 * Asserts the trash or restore of about finished inline: no queued job and no work said to be left.
	 *
	 * @param string $message What was done.
	 */
	private function assert_finished_inline( string $message ): void {
		$this->assertNull( $this->queue->load( $this->ids['about'] ), $message );
		$this->assertFalse( $this->queue->is_scheduled( $this->ids['about'] ), $message );
		$this->assertFalse( $this->change()['roots'][ $this->ids['about'] ]['job'], $message );
	}

	public function test_a_subtree_of_exactly_the_core_budget_is_trashed_and_restored_inline(): void {
		$this->policy( Settings::POLICY_TRASH_CHILDREN );
		$this->fill_about( Operations::DEFAULT_CORE_THRESHOLD );

		wp_trash_post( $this->ids['about'] );

		$this->assertSame( 0, Subtree::count_live_descendants( $this->ids['about'], 'page', 100 ) );
		$this->assert_finished_inline( 'Trash.' );

		$this->handler()->reset_request_budget();
		Change_Log::reset_request();
		wp_untrash_post( $this->ids['about'] );

		$this->assertSame( Operations::DEFAULT_CORE_THRESHOLD, Subtree::count_live_descendants( $this->ids['about'], 'page', 100 ) );
		$this->assert_finished_inline( 'Restore.' );
	}

	public function test_a_subtree_one_past_the_core_budget_queues_the_rest(): void {
		$this->policy( Settings::POLICY_TRASH_CHILDREN );
		$this->fill_about( Operations::DEFAULT_CORE_THRESHOLD + 1 );

		wp_trash_post( $this->ids['about'] );

		$this->assertSame( 'publish', get_post_status( $this->ids['leadership'] ), 'The last subpage is left for the queue.' );
		$this->assertSame( Operations::DEFAULT_CORE_THRESHOLD, $this->queue->load( $this->ids['about'] )['counts']['trashed'] );
		$this->assertTrue( $this->queue->is_scheduled( $this->ids['about'] ) );
		$this->assertTrue( $this->change()['roots'][ $this->ids['about'] ]['job'] );
	}

	public function test_exactly_the_move_budget_of_subpages_moves_up_inline(): void {
		$this->fill_about( Operations::DEFAULT_THRESHOLD + 1 );

		wp_trash_post( $this->ids['about'] );

		$this->assertSame( 0, Subtree::count_live_children( $this->ids['about'], 'page', 10 ) );
		$this->assert_finished_inline( 'Trash.' );
	}

	public function test_one_past_the_move_budget_of_subpages_queues_the_rest(): void {
		$this->fill_about( Operations::DEFAULT_THRESHOLD + 2 );

		wp_trash_post( $this->ids['about'] );

		$this->assertSame( 1, Subtree::count_live_children( $this->ids['about'], 'page', 10 ) );
		$this->assertTrue( $this->queue->is_scheduled( $this->ids['about'] ) );
		$this->assertTrue( $this->change()['roots'][ $this->ids['about'] ]['job'] );
	}

	public function test_a_bulk_trash_shares_one_budget_and_one_summary(): void {
		add_filter( 'hierarchy_guard_batch_threshold', static fn(): int => 3 );
		$services = $this->page( 'services' );
		$this->page( 'design', $services );
		$this->page( 'build', $services );

		$this->core_bulk( 'trash', [ $this->ids['about'], $services ] );

		$change = $this->change();
		$this->assertSame( [ $this->ids['about'], $services ], array_keys( $change['roots'] ) );
		$this->assertFalse( $change['roots'][ $this->ids['about'] ]['job'] );
		$this->assertTrue( $change['roots'][ $services ]['job'], 'The second parent got what was left of the budget.' );
		$this->assertSame( 3, $change['counts']['moved'] );
	}

	public function test_restoring_mid_batch_stops_the_job_and_brings_back_what_moved(): void {
		add_filter( 'hierarchy_guard_batch_threshold', static fn(): int => 2 );
		$this->more_subpages( [ 'a', 'b', 'c' ] );
		wp_trash_post( $this->ids['about'] );

		// A real Undo is a new request with a fresh budget.
		$this->handler()->reset_request_budget();
		$this->assertTrue( $this->handler()->undo( $this->ids['about'] ) );
		$this->assertSame( 5, Subtree::count_live_children( $this->ids['about'], 'page', 10 ), 'The inline restore already brought back the two that moved.' );
		$this->processor()->run( $this->ids['about'] );

		$this->assertSame( 5, Subtree::count_live_children( $this->ids['about'], 'page', 10 ) );
		$this->assertNull( $this->queue->load( $this->ids['about'] ) );
	}

	public function test_a_restore_while_a_batch_runs_is_handed_to_that_batch(): void {
		add_filter( 'hierarchy_guard_batch_threshold', static fn(): int => 1 );
		wp_trash_post( $this->ids['about'] );
		$token = $this->queue->lock( $this->ids['about'] );

		$this->handler()->undo( $this->ids['about'] );

		$this->assertSame( Operations::TYPE_RESTORE, $this->queue->offered( $this->ids['about'] )['type'] );
		$this->assertTrue( $this->change()['roots'][ $this->ids['about'] ]['job'] );

		$this->queue->unlock( $this->ids['about'], $token );
		$this->processor()->run( $this->ids['about'] );

		$this->assertSame( $this->ids['about'], (int) get_post( $this->ids['team'] )->post_parent );
		$this->assertSame( $this->ids['about'], (int) get_post( $this->ids['history'] )->post_parent );
	}

	public function test_a_background_run_before_the_trash_request_ends_keeps_what_it_did(): void {
		add_filter( 'hierarchy_guard_batch_threshold', static fn(): int => 2 );
		$this->more_subpages( [ 'a', 'b', 'c' ] );
		// The user's change from an earlier request.
		wp_update_post(
			[
				'ID'        => $this->page( 'older' ),
				'post_name' => 'older-2',
			]
		);
		Change_Log::reset_request();

		wp_trash_post( $this->ids['about'] );
		// Cron runs the job in another process while the trash request is still finishing.
		$seen = $this->in_another_process(
			function (): string {
				$change = (array) $this->log->latest( $this->admin );
				$this->processor()->run( $this->ids['about'] );
				return (string) ( $change['action'] ?? '' );
			}
		);
		Change_Log::reset_request();

		$this->assertSame( 'save', $seen, 'The trash’s change is written once, when its request ends; the run does not need it.' );
		$this->assertNull( $this->queue->load( $this->ids['about'] ), 'Precondition: the run finished the job.' );
		$change = $this->stored_change( $this->admin );
		$root   = $change['roots'][ $this->ids['about'] ];
		$this->assertFalse( $root['job'], 'Nothing is still moving.' );
		$this->assertSame( 5, $root['counts']['moved'] );
		$this->assertSame( 5, $change['counts']['moved'] );
	}

	public function test_the_end_of_the_trash_request_keeps_what_a_background_run_did_since(): void {
		add_filter( 'hierarchy_guard_batch_threshold', static fn(): int => 2 );
		$this->more_subpages( [ 'a', 'b', 'c' ] );
		$services = $this->keep_policy_page( 'services', [ 'design' ] );

		// A bulk trash: about's job is queued, then services is recorded...
		$this->core_bulk( 'trash', [ $this->ids['about'], $services ] );
		// ...and about's run finishes in another process before the request ends.
		$this->in_another_process( fn() => $this->processor()->run( $this->ids['about'] ) );
		Change_Log::reset_request();

		$change = $this->stored_change( $this->admin );
		$root   = $change['roots'][ $this->ids['about'] ];
		$this->assertFalse( $root['job'], 'The request’s own write does not take back what the run stored.' );
		$this->assertSame( 5, $root['counts']['moved'] );
		$this->assertSame( 1, $change['roots'][ $services ]['counts']['kept'], 'What the request recorded after the job was queued is written too.' );
		$this->assertSame( 5, $change['counts']['moved'] );
		$this->assertSame( 1, $change['counts']['kept'] );
	}

	public function test_a_restore_handed_to_a_running_batch_keeps_what_that_batch_did(): void {
		add_filter( 'hierarchy_guard_batch_threshold', static fn(): int => 1 );
		wp_trash_post( $this->ids['about'] );
		// The Undo is a new request, while a run holds the lock.
		Change_Log::reset_request();
		$this->handler()->reset_request_budget();
		$token = $this->queue->lock( $this->ids['about'] );

		$this->handler()->undo( $this->ids['about'] );
		$this->assertSame( Operations::TYPE_RESTORE, $this->queue->offered( $this->ids['about'] )['type'], 'Precondition: the restore is offered to the run.' );
		// The run adopts the offer and finishes it before the Undo request ends.
		$this->in_another_process(
			function () use ( $token ): void {
				$this->queue->unlock( $this->ids['about'], $token );
				$this->processor()->run( $this->ids['about'] );
			}
		);
		Change_Log::reset_request();

		$change = $this->stored_change( $this->admin );
		$this->assertSame( 'restore', $change['action'] );
		$root = $change['roots'][ $this->ids['about'] ];
		$this->assertFalse( $root['job'], 'The run found the restore’s change and closed it.' );
		$this->assertSame( 1, $root['counts']['moved'], 'The one subpage the trash moved came back.' );
	}

	public function test_a_run_that_finishes_before_a_bulk_trash_ends_keeps_its_root_after_a_later_one(): void {
		// About's job is queued, services is recorded after it, and about's run finishes in another process before the request ends.
		add_filter( 'hierarchy_guard_batch_threshold', static fn(): int => 2 );
		$this->more_subpages( [ 'a', 'b', 'c' ] );
		$services = $this->keep_policy_page( 'services', [ 'design' ] );

		$this->core_bulk( 'trash', [ $this->ids['about'], $services ] );
		$this->in_another_process( fn() => $this->processor()->run( $this->ids['about'] ) );
		Change_Log::reset_request();

		$change = $this->stored_change( $this->admin );
		$about  = $change['roots'][ $this->ids['about'] ];
		$this->assertFalse( $about['job'] );
		$this->assertSame( 5, $about['counts']['moved'] );
		$this->assertSame( 1, $change['roots'][ $services ]['counts']['kept'] );
		$this->assertSame( [ '5 subpages moved up one level.', '1 subpage stayed where it is.' ], Notices::sentences( $change ), 'Nothing says pages are still moving.' );
		$this->assertNull( $this->queue->load( $this->ids['about'] ), 'No job is left that could correct it later.' );
		$this->assertSame( 6, $this->stored_pair_count() );
	}

	public function test_a_run_that_finishes_between_two_job_roots_of_a_bulk_trash_keeps_its_root(): void {
		// About's run finishes in another process while the request trashes careers, which queues a job too.
		add_filter( 'hierarchy_guard_batch_threshold', static fn(): int => 2 );
		$this->more_subpages( [ 'a', 'b', 'c' ] );
		$careers = $this->page( 'careers' );
		foreach ( [ 'x', 'y', 'z', 'w' ] as $slug ) {
			$this->page( $slug, $careers );
		}
		$about = $this->ids['about'];
		$ran   = false;
		add_action(
			'wp_trash_post',
			function ( $post_id ) use ( $careers, $about, &$ran ): void {
				if ( $careers === (int) $post_id && ! $ran ) {
					$ran = true;
					$this->in_another_process( fn() => $this->processor()->run( $about ) );
				}
			},
			1
		);

		$this->core_bulk( 'trash', [ $about, $careers ] );
		$this->assertTrue( $ran, 'Precondition: about’s run finished before careers was trashed.' );
		$this->assertNotNull( $this->queue->load( $careers ), 'Precondition: careers queued a job too.' );
		$this->in_another_process( fn() => $this->processor()->run( $careers ) );
		Change_Log::reset_request();

		$change = $this->stored_change( $this->admin );
		$this->assertFalse( $change['roots'][ $about ]['job'] );
		$this->assertSame( 5, $change['roots'][ $about ]['counts']['moved'] );
		$this->assertFalse( $change['roots'][ $careers ]['job'] );
		$this->assertSame( 4, $change['roots'][ $careers ]['counts']['moved'] );
		$this->assertSame( [ '9 subpages moved up one level.' ], Notices::sentences( $change ) );
		$this->assertSame( 10, $this->stored_pair_count() );
	}

	public function test_a_cron_process_that_runs_two_jobs_keeps_a_root_the_request_wrote_in_between(): void {
		// One cron process, keeping its cache across its runs, finishes about's job, the request ends, then the same process runs careers' job.
		add_filter( 'hierarchy_guard_batch_threshold', static fn(): int => 2 );
		$this->more_subpages( [ 'a', 'b', 'c' ] );
		$careers = $this->page( 'careers' );
		foreach ( [ 'x', 'y', 'z', 'w' ] as $slug ) {
			$this->page( $slug, $careers );
		}
		$services = $this->keep_policy_page( 'services', [ 'design' ] );
		$cron     = $this->another_process();

		$this->core_bulk( 'trash', [ $this->ids['about'], $careers, $services ] );
		$cron( fn() => $this->processor()->run( $this->ids['about'] ) );
		Change_Log::reset_request();
		$cron( fn() => $this->processor()->run( $careers ) );

		$change = $this->stored_change( $this->admin );
		$this->assertFalse( $change['roots'][ $this->ids['about'] ]['job'] );
		$this->assertSame( 5, $change['roots'][ $this->ids['about'] ]['counts']['moved'] );
		$this->assertFalse( $change['roots'][ $careers ]['job'] );
		$this->assertSame( 4, $change['roots'][ $careers ]['counts']['moved'] );
		$this->assertArrayHasKey( $services, $change['roots'], 'The cron process’s second run does not drop the root the request wrote.' );
		$this->assertSame( 1, $change['roots'][ $services ]['counts']['kept'] );
		$this->assertSame( [ '9 subpages moved up one level.', '1 subpage stayed where it is.' ], Notices::sentences( $change ) );
	}

	public function test_a_bulk_trash_of_999_parents_past_the_budget_writes_its_change_once(): void {
		$parents = [];
		for ( $i = 0; $i < 999; $i++ ) {
			$parent    = $this->page( 'p' . $i );
			$parents[] = $parent;
			$this->page( 'c' . $i, $parent );
		}
		$writes = 0;
		add_filter(
			'pre_set_transient_' . Change_Log::key( $this->admin ),
			static function ( $value ) use ( &$writes ) {
				++$writes;
				return $value;
			}
		);

		$this->core_bulk( 'trash', $parents );

		$this->assertSame( 0, $writes, 'Nothing is written while the request trashes.' );
		$this->assertGreaterThanOrEqual( 999 - Operations::DEFAULT_THRESHOLD, count( $this->queue->roots( 1000 ) ), 'Precondition: past the budget, every later parent queues a job.' );
		Change_Log::reset_request();
		$this->assertSame( 1, $writes, 'The change is written once, when the request ends.' );
		$change = $this->stored_change( $this->admin );
		$this->assertCount( 999, $change['roots'] );
		$this->assertSame( Operations::DEFAULT_THRESHOLD, $change['counts']['moved'] );
	}

	public function test_a_job_on_another_site_keeps_its_record_on_that_site(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Run with WP_MULTISITE=1.' );
		}
		grant_super_admin( $this->admin );
		add_filter( 'hierarchy_guard_batch_threshold', static fn(): int => 2 );
		$site = self::factory()->blog->create();
		switch_to_blog( $site );
		update_option( 'permalink_structure', '/%postname%/' );
		$parent = $this->page( 'parent-b' );
		foreach ( [ 'a', 'b', 'c', 'd' ] as $slug ) {
			$this->page( $slug, $parent );
		}
		restore_current_blog();
		Change_Log::reset_request();
		$this->handler()->reset_request_budget();

		// One request: a trash on site B that queues a job, then a save on the main site.
		switch_to_blog( $site );
		wp_trash_post( $parent );
		$queued = null !== ( new Queue() )->load( $parent );
		restore_current_blog();
		wp_update_post(
			[
				'ID'        => $this->ids['about'],
				'post_name' => 'about-2',
			]
		);
		Change_Log::reset_request();
		$this->assertTrue( $queued, 'Precondition: the trash queued a job on site B.' );

		// Site B's cron finishes the job.
		$run    = static fn() => ( new Processor( new Queue(), new Operations( new Ledger(), new Mover() ), new Change_Log() ) )->run( $parent );
		$record = fn() => get_transient( Change_Log::job_key( $this->admin, $parent ) );
		switch_to_blog( $site );
		$this->in_another_process( $run );
		$on_site = $this->in_another_process( $record );
		$other   = $this->stored_change( $this->admin );
		restore_current_blog();
		$on_main = $this->in_another_process( $record );
		$main    = $this->stored_change( $this->admin );

		$this->assertIsArray( $on_site, 'The job’s record is on its own site.' );
		$this->assertFalse( $on_main, 'The main site has none.' );
		$this->assertSame( $other['id'], $on_site['change'] );
		$this->assertSame( 'trash', $other['action'] );
		$this->assertFalse( $other['roots'][ $parent ]['job'], 'Site B’s change has its job’s record laid over it.' );
		$this->assertSame( 4, $other['roots'][ $parent ]['counts']['moved'] );
		$this->assertSame( 'save', $main['action'] );
		$this->assertSame( [ $this->ids['about'] ], array_keys( $main['roots'] ), 'The main site’s change has its own save only.' );
	}

	public function test_bulk_trash_and_undo_of_a_parent_and_its_subpage_round_trip(): void {
		$contact = $this->page( 'contact' );

		$this->core_bulk( 'trash', [ $this->ids['about'], $this->ids['team'], $contact ] );

		$this->assertSame( $this->ids['company'], (int) get_post( $this->ids['history'] )->post_parent );
		$this->assertSame( $this->ids['company'], (int) get_post( $this->ids['leadership'] )->post_parent, 'Its own trash moved it past both trashed pages.' );

		$this->core_bulk( 'undo', [ $this->ids['about'], $this->ids['team'], $contact ] );

		$this->assertSame( $this->ids['about'], (int) get_post( $this->ids['history'] )->post_parent );
		$this->assertSame( $this->ids['team'], (int) get_post( $this->ids['leadership'] )->post_parent );
		foreach ( [ $this->ids['about'], $this->ids['team'], $contact ] as $id ) {
			$this->assertSame( 'publish', get_post_status( $id ), "Core's Undo keeps every page's status." );
		}
	}

	public function test_bulk_trash_and_undo_with_trash_children_round_trip(): void {
		$this->policy( Settings::POLICY_TRASH_CHILDREN );
		$contact = $this->page( 'contact' );

		$this->core_bulk( 'trash', [ $this->ids['about'], $this->ids['team'], $contact ] );

		foreach ( [ 'team', 'history', 'leadership' ] as $slug ) {
			$this->assertSame( 'trash', get_post_status( $this->ids[ $slug ] ), $slug );
		}

		$this->core_bulk( 'undo', [ $this->ids['about'], $this->ids['team'], $contact ] );

		foreach ( [ 'about', 'team', 'history', 'leadership' ] as $slug ) {
			$this->assertSame( 'publish', get_post_status( $this->ids[ $slug ] ), $slug );
		}
		$this->assertSame( 'publish', get_post_status( $contact ) );
	}

	public function test_restoring_everything_from_the_trash_view_never_stops_core(): void {
		$this->policy( Settings::POLICY_TRASH_CHILDREN );
		wp_trash_post( $this->ids['about'] );

		$this->core_bulk( 'untrash', [ $this->ids['about'], $this->ids['team'], $this->ids['history'], $this->ids['leadership'] ] );

		$this->assertSame( 'draft', get_post_status( $this->ids['about'] ), 'Selected on its own, as core does.' );
		foreach ( [ 'team', 'history', 'leadership' ] as $slug ) {
			$this->assertSame( 'publish', get_post_status( $this->ids[ $slug ] ), $slug );
		}
	}

	public function test_a_subpage_restored_on_its_own_stays_out_of_its_parent_s_restore(): void {
		$this->policy( Settings::POLICY_TRASH_CHILDREN );
		wp_trash_post( $this->ids['about'] );

		wp_untrash_post( $this->ids['history'] );
		$this->assertSame( 'publish', get_post_status( $this->ids['history'] ), 'It still gets its previous status.' );
		wp_trash_post( $this->ids['history'] );

		$this->handler()->undo( $this->ids['about'] );

		$this->assertSame( 'trash', get_post_status( $this->ids['history'] ), 'Trashed on purpose since.' );
		$this->assertSame( 'publish', get_post_status( $this->ids['team'] ) );
	}

	public function test_a_subpage_deleted_out_of_the_trash_before_its_parent_s_restore_leaves_its_subpage_its_old_url(): void {
		$this->policy( Settings::POLICY_TRASH_CHILDREN );
		wp_trash_post( $this->ids['about'] );

		wp_delete_post( $this->ids['team'], true );
		wp_untrash_post( $this->ids['about'] );

		$this->assertSame( $this->ids['about'], (int) get_post( $this->ids['leadership'] )->post_parent, 'Precondition: core moved leadership up under about.' );
		$this->assertSame( 'publish', get_post_status( $this->ids['leadership'] ) );
		$this->assertSame( $this->ids['leadership'], $this->resolver->resolve( 'page', 'company/about/team/leadership' )->ID );
		$this->assertSame( [ 'company/about/team/leadership' ], $this->ledger->paths_for( $this->ids['leadership'] ), 'Recorded on the subpage itself.' );
		$this->assertSame( [], $this->ledger->paths_for( $this->ids['about'] ), 'Not on the parent.' );
		$this->assertFalse( get_option( Ledger::ROOT_OPTION ), 'Not in the root list.' );
	}

	/**
	 * Trashes about under the trash-the-subpages policy while the user may not delete leadership.
	 */
	private function trash_about_keeping_leadership(): void {
		$this->policy( Settings::POLICY_TRASH_CHILDREN );
		$locked = $this->ids['leadership'];
		$deny   = static function ( array $caps, string $cap, int $user_id, array $args ) use ( $locked ): array {
			return 'delete_post' === $cap && isset( $args[0] ) && (int) $args[0] === $locked ? [ 'do_not_allow' ] : $caps;
		};
		add_filter( 'map_meta_cap', $deny, 10, 4 );
		wp_trash_post( $this->ids['about'] );
		remove_filter( 'map_meta_cap', $deny, 10 );

		$this->assertSame( 'trash', get_post_status( $this->ids['team'] ), 'Precondition: team went with about.' );
		$this->assertSame( 'publish', get_post_status( $this->ids['leadership'] ), 'Precondition: leadership stayed live.' );
	}

	public function test_empty_trash_keeps_the_url_of_a_subpage_the_user_could_not_trash(): void {
		$this->trash_about_keeping_leadership();

		// Child first: team, then about.
		wp_delete_post( $this->ids['team'], true );
		wp_delete_post( $this->ids['about'], true );

		$this->assertSame( $this->ids['company'], (int) get_post( $this->ids['leadership'] )->post_parent );
		$this->assertSame( $this->ids['leadership'], $this->resolver->resolve( 'page', 'company/about/team/leadership' )->ID );
		$this->assertSame( [ 'company/about/team/leadership' ], $this->ledger->paths_for( $this->ids['leadership'] ) );
	}

	public function test_empty_trash_parent_first_keeps_the_url_of_a_subpage_the_user_could_not_trash(): void {
		$this->trash_about_keeping_leadership();

		wp_delete_post( $this->ids['about'], true );
		wp_delete_post( $this->ids['team'], true );

		$this->assertSame( $this->ids['company'], (int) get_post( $this->ids['leadership'] )->post_parent );
		$this->assertSame( $this->ids['leadership'], $this->resolver->resolve( 'page', 'company/about/team/leadership' )->ID );
		$this->assertSame( [ 'company/about' ], $this->ledger->paths_for( $this->ids['company'] ), 'Only the URL of about itself is on its parent.' );
		$this->assertFalse( get_option( Ledger::ROOT_OPTION ), 'Nothing in the root list.' );
	}

	public function test_an_older_url_of_a_trashed_page_is_not_redirected_to_its_parent(): void {
		$this->policy( Settings::POLICY_REPARENT );
		wp_update_post(
			[
				'ID'        => $this->ids['about'],
				'post_name' => 'about-us',
			]
		);
		wp_update_post(
			[
				'ID'        => $this->ids['about'],
				'post_name' => 'about',
			]
		);
		$this->assertSame( [ 'company/about-us' ], $this->ledger->paths_for( $this->ids['about'] ), 'Precondition: about holds its older URL.' );

		wp_trash_post( $this->ids['about'] );

		$this->assertNull( $this->resolver->resolve( 'page', 'company/about-us' ), 'A restore would give the page its URLs back.' );
		$this->assertNull( $this->resolver->resolve( 'page', 'company/about' ) );
		$this->assertSame( $this->ids['team'], $this->resolver->resolve( 'page', 'company/about-us/team' )->ID );
	}

	public function test_a_trashed_page_is_not_redirected_to_its_parent_after_the_parent_is_trashed_and_restored(): void {
		$this->policy( Settings::POLICY_REPARENT );
		wp_trash_post( $this->ids['about'] );
		$this->handler()->reset_request_budget();
		Change_Log::reset_request();
		wp_trash_post( $this->ids['company'] );
		$this->handler()->reset_request_budget();
		Change_Log::reset_request();

		wp_untrash_post( $this->ids['company'] );
		wp_publish_post( $this->ids['company'] );

		$this->assertSame( 'trash', get_post_status( $this->ids['about'] ), 'Precondition: about is still in the trash.' );
		$this->assertNull( $this->resolver->resolve( 'page', 'company/about' ) );
		$this->assertSame( $this->ids['history'], $this->resolver->resolve( 'page', 'company/about/history' )->ID );
	}

	public function test_a_trashed_page_is_not_redirected_to_its_parent_after_a_page_above_is_deleted(): void {
		$this->policy( Settings::POLICY_REPARENT );
		$org = $this->page( 'org' );
		wp_update_post(
			[
				'ID'          => $this->ids['company'],
				'post_parent' => $org,
			]
		);
		wp_trash_post( $this->ids['about'] );

		wp_delete_post( $this->ids['company'], true );

		$this->assertSame( $org, (int) get_post( $this->ids['about'] )->post_parent, 'Precondition: core moved about up under org.' );
		$this->assertNull( $this->resolver->resolve( 'page', 'org/company/about' ) );
		$this->assertSame( $this->ids['history'], $this->resolver->resolve( 'page', 'org/company/about/history' )->ID );
	}

	public function test_too_many_subpages_to_trash_move_up_instead(): void {
		$this->policy( Settings::POLICY_TRASH_CHILDREN );
		add_filter( 'hierarchy_guard_count_cap', static fn(): int => 2 );

		wp_trash_post( $this->ids['about'] );

		$this->assertSame( 'publish', get_post_status( $this->ids['team'] ) );
		$this->assertSame( $this->ids['company'], (int) get_post( $this->ids['team'] )->post_parent );
		$root = $this->change()['roots'][ $this->ids['about'] ];
		$this->assertTrue( $root['fallback'] );
		$this->assertSame( Settings::POLICY_REPARENT, $root['policy'] );
		$this->assertTrue( $root['urls']['capped'] );
	}

	public function test_subpages_of_a_page_under_a_trashed_parent_move_to_the_nearest_live_ancestor(): void {
		$company = $this->ids['company'];
		add_filter( 'hierarchy_guard_trash_policy', static fn( string $policy, \WP_Post $post ): string => $post->ID === $company ? Settings::POLICY_KEEP : $policy, 10, 2 );
		wp_trash_post( $this->ids['company'] );

		wp_trash_post( $this->ids['about'] );

		$this->assertSame( 0, (int) get_post( $this->ids['team'] )->post_parent );
		$this->assertSame( $this->ids['team'], $this->resolver->resolve( 'page', 'company/about/team' )->ID );
	}

	public function test_deleting_a_trashed_parent_for_good_cleans_up_its_markers(): void {
		wp_trash_post( $this->ids['about'] );
		$this->assertSame( 2, Markers::count( $this->ids['about'], 10 ), 'Precondition.' );

		wp_delete_post( $this->ids['about'], true );

		$this->assertSame( 0, Markers::count( $this->ids['about'], 10 ) );
		$this->assertNull( $this->queue->load( $this->ids['about'] ) );
		$this->assertSame( $this->ids['team'], $this->resolver->resolve( 'page', 'company/about/team' )->ID, 'Old URLs still resolve.' );
		$this->assertSame( $this->ids['company'], $this->resolver->resolve( 'page', 'company/about' )->ID, 'Now that it is gone, its URL leads to its parent.' );
	}

	public function test_a_restore_whose_save_never_finished_is_queued_at_shutdown(): void {
		wp_trash_post( $this->ids['about'] );
		$this->untrash_raw( $this->ids['about'] );
		$this->handler()->on_transition( 'publish', 'trash', get_post( $this->ids['about'] ) );

		$this->handler()->flush_pending();

		$this->assertSame( Operations::TYPE_RESTORE, $this->queue->load( $this->ids['about'] )['type'] );
		$this->assertSame( $this->ids['company'], (int) get_post( $this->ids['team'] )->post_parent, 'Nothing runs inline at shutdown.' );

		$this->processor()->run( $this->ids['about'] );

		$this->assertSame( $this->ids['about'], (int) get_post( $this->ids['team'] )->post_parent );
	}

	public function test_unsupported_types_are_ignored(): void {
		register_post_type(
			'hg_private',
			[
				'public'       => false,
				'hierarchical' => true,
			]
		);
		$parent = self::factory()->post->create( [ 'post_type' => 'hg_private' ] );
		$child  = self::factory()->post->create(
			[
				'post_type'   => 'hg_private',
				'post_parent' => $parent,
			]
		);

		wp_trash_post( $parent );

		$this->assertSame( $parent, (int) get_post( $child )->post_parent );
	}

	public function test_cli_lines_say_what_happened(): void {
		$job                     = Operations::new_job(
			Operations::TYPE_REPARENT,
			12,
			'page',
			0,
			[
				'to'    => 7,
				'total' => 900,
			]
		);
		$job['counts']['moved']  = 200;
		$job['counts']['denied'] = 2;
		$job['counts']['kept']   = 2;

		$this->assertSame(
			[
				'Moved 200 subpages of page 12 up under page 7.',
				'Skipped 2 subpages the user cannot change.',
				'698 more are queued. Run `wp hierarchy-guard process-jobs` to finish now.',
			],
			Trash_Handler::cli_lines( 'trash', $job, true )
		);

		$job['to']               = 0;
		$job['counts']['denied'] = 0;
		$this->assertSame( [ 'Moved 200 subpages of page 12 to the top level.' ], Trash_Handler::cli_lines( 'trash', $job, false ) );

		$restore                       = Operations::new_job( Operations::TYPE_RESTORE, 12, 'page', 0 );
		$restore['counts']['moved']    = 1;
		$restore['counts']['restored'] = 3;
		$this->assertSame( [ 'Moved 1 subpage back under page 12.', 'Restored 3 subpages of page 12 from the trash.' ], Trash_Handler::cli_lines( 'restore', $restore, false ) );
	}

	public function test_a_subpage_with_its_parent_s_slug_leaves_the_parent_its_url(): void {
		$twin = $this->page( 'about', $this->ids['about'] );

		wp_trash_post( $this->ids['about'] );

		$this->assertSame( 'about-2', get_post( $twin )->post_name, 'The slug stays free for the parent.' );
		$this->assertSame( $twin, $this->resolver->resolve( 'page', 'company/about/about' )->ID );

		$this->assertTrue( $this->handler()->undo( $this->ids['about'] ) );

		$this->assertSame( 'about', get_post( $this->ids['about'] )->post_name );
		$this->assertSame( $this->ids['about'], (int) get_post( $twin )->post_parent );
		$this->assertSame( 'about', get_post( $twin )->post_name );
	}

	public function test_deleting_a_page_whose_batch_is_running_leaves_the_job_to_that_batch(): void {
		$this->policy( Settings::POLICY_TRASH_CHILDREN );
		add_filter( 'hierarchy_guard_core_call_threshold', static fn(): int => 1 );
		wp_trash_post( $this->ids['about'] );
		$this->assertNotNull( $this->queue->load( $this->ids['about'] ), 'Precondition: a trash job is queued.' );
		$token = $this->queue->lock( $this->ids['about'] );

		wp_delete_post( $this->ids['about'], true );

		$this->assertNotNull( $this->queue->load( $this->ids['about'] ), 'The running batch owns the slot.' );
		$this->queue->unlock( $this->ids['about'], $token );

		$this->processor()->run( $this->ids['about'] );

		$this->assertNull( $this->queue->load( $this->ids['about'] ), 'The batch found the page gone and ended the job.' );
	}

	public function test_deleting_a_page_without_a_job_writes_no_lock(): void {
		$locks = [];
		add_filter(
			'query',
			static function ( $sql ) use ( &$locks ) {
				if ( str_contains( (string) $sql, 'hierarchy_guard_lock_' ) ) {
					$locks[] = $sql;
				}
				return $sql;
			}
		);

		wp_delete_post( $this->ids['history'], true );

		$this->assertSame( [], $locks );
	}
}
