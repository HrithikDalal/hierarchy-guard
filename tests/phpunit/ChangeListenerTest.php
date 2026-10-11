<?php
/**
 * Recording URL changes from core hooks.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Change_Listener;
use HierarchyGuard\Ledger;
use HierarchyGuard\Post_Types;
use HierarchyGuard\Redirects\Resolver;
use HierarchyGuard\Settings;
use HierarchyGuard\Trash\Markers;
use WP_UnitTestCase;

/**
 * @covers \HierarchyGuard\Change_Listener
 */
final class ChangeListenerTest extends WP_UnitTestCase {

	private Ledger $ledger;

	private int $company;

	private int $about;

	private int $team;

	public function set_up(): void {
		parent::set_up();
		delete_option( Settings::OPTION );
		delete_option( Ledger::ROOT_OPTION );
		Post_Types::reset();
		Change_Listener::suspend( false );
		$this->set_permalink_structure( '/%postname%/' );
		// These tests exercise core's own trash behaviour, which the keep policy leaves untouched.
		add_filter( 'hierarchy_guard_trash_policy', static fn(): string => Settings::POLICY_KEEP );

		$this->ledger  = new Ledger();
		$this->company = self::factory()->post->create(
			[
				'post_type' => 'page',
				'post_name' => 'company',
			]
		);
		$this->about   = self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_name'   => 'about',
				'post_parent' => $this->company,
			]
		);
		$this->team    = self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_name'   => 'team',
				'post_parent' => $this->about,
			]
		);
	}

	public function tear_down(): void {
		Change_Listener::suspend( false );
		$this->set_permalink_structure( '' );
		parent::tear_down();
	}

	public function test_listener_is_registered_by_the_plugin(): void {
		$this->assertTrue( $this->plugin_listener_on( 'post_updated', 'on_post_updated' ) );
		$this->assertTrue( $this->plugin_listener_on( 'before_delete_post', 'on_before_delete' ) );
	}

	/**
	 * Whether a Change_Listener method is hooked at priority 10. has_action( $hook )
	 * alone proves nothing: core registers its own callbacks on both hooks.
	 */
	private function plugin_listener_on( string $hook, string $method ): bool {
		global $wp_filter;
		foreach ( $wp_filter[ $hook ]->callbacks[10] ?? [] as $callback ) {
			if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof Change_Listener && $method === $callback['function'][1] ) {
				return true;
			}
		}
		return false;
	}

	public function test_renaming_a_page_records_its_old_path_only_on_that_page(): void {
		wp_update_post(
			[
				'ID'        => $this->about,
				'post_name' => 'company-info',
			]
		);

		$this->assertSame( [ 'company/about' ], $this->ledger->paths_for( $this->about ) );
		$this->assertSame( [], $this->ledger->paths_for( $this->team ), 'Subpages are resolved by prefix, never recorded.' );
		$this->assertSame( [], $this->ledger->paths_for( $this->company ) );
	}

	public function test_changing_the_parent_records_the_old_chain(): void {
		wp_update_post(
			[
				'ID'          => $this->team,
				'post_parent' => $this->company,
			]
		);

		$this->assertSame( [ 'company/about/team' ], $this->ledger->paths_for( $this->team ) );
	}

	public function test_renaming_back_prunes_the_entry_for_the_current_path(): void {
		wp_update_post(
			[
				'ID'        => $this->about,
				'post_name' => 'company-info',
			]
		);
		wp_update_post(
			[
				'ID'        => $this->about,
				'post_name' => 'about',
			]
		);

		$this->assertSame( [ 'company/company-info' ], $this->ledger->paths_for( $this->about ), 'Only the intermediate path remains; the current path is pruned.' );
	}

	public function test_saving_without_slug_or_parent_change_runs_no_queries(): void {
		Post_Types::supported();
		$listener = new Change_Listener( $this->ledger );
		$post     = get_post( $this->about );

		$queries = get_num_queries();
		$listener->on_post_updated( $this->about, $post, $post );

		$this->assertSame( $queries, get_num_queries() );
		$this->assertSame( [], $this->ledger->paths_for( $this->about ) );
	}

	public function test_unsupported_types_and_unpublished_pages_are_ignored(): void {
		$post  = self::factory()->post->create(
			[
				'post_type' => 'post',
				'post_name' => 'hello',
			]
		);
		$draft = self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_status' => 'draft',
				'post_name'   => 'draft',
			]
		);

		wp_update_post(
			[
				'ID'        => $post,
				'post_name' => 'hello-world',
			]
		);
		wp_update_post(
			[
				'ID'        => $draft,
				'post_name' => 'draft-two',
			]
		);

		$this->assertSame( [], $this->ledger->paths_for( $post ) );
		$this->assertSame( [], $this->ledger->paths_for( $draft ) );
	}

	public function test_trash_and_untrash_transitions_record_nothing(): void {
		wp_trash_post( $this->about );
		$this->assertStringEndsWith( '__trashed', get_post( $this->about )->post_name, 'Precondition: core renames the slug on trash.' );
		$this->assertSame( [], $this->ledger->paths_for( $this->about ) );

		wp_untrash_post( $this->about );
		$this->assertSame( 'about', get_post( $this->about )->post_name, 'Precondition: core restores the slug on untrash.' );
		$this->assertSame( [], $this->ledger->paths_for( $this->about ) );
	}

	public function test_suspended_listener_records_nothing(): void {
		Change_Listener::suspend( true );
		wp_update_post(
			[
				'ID'        => $this->about,
				'post_name' => 'company-info',
			]
		);

		$this->assertTrue( Change_Listener::is_suspended() );
		$this->assertSame( [], $this->ledger->paths_for( $this->about ) );
	}

	public function test_deleting_a_parent_records_its_path_on_the_grandparent(): void {
		wp_delete_post( $this->about, true );

		$this->assertSame( $this->company, (int) get_post( $this->team )->post_parent, 'Precondition: core re-parents to the grandparent.' );
		$this->assertSame( [ 'company/about' ], $this->ledger->paths_for( $this->company ) );
	}

	public function test_deleting_a_top_level_parent_records_a_root_path(): void {
		wp_delete_post( $this->company, true );

		$this->assertSame( 0, (int) get_post( $this->about )->post_parent );
		$this->assertSame( 0, $this->ledger->find( 'page', 'company' ) );
	}

	public function test_deleting_a_childless_page_records_nothing(): void {
		wp_delete_post( $this->team, true );

		$this->assertSame( [], $this->ledger->paths_for( $this->about ) );
	}

	public function test_deleting_a_trashed_parent_carries_its_old_paths_up_but_not_trashed_ones(): void {
		$this->ledger->record( $this->about, 'company/about-old' );
		wp_trash_post( $this->about );
		wp_delete_post( $this->about, true );

		$paths = $this->ledger->paths_for( $this->company );
		$this->assertContains( 'company/about-old', $paths );
		foreach ( $paths as $path ) {
			$this->assertStringNotContainsString( '__trashed', $path );
		}
	}

	public function test_emptying_the_trash_records_the_pre_trash_path(): void {
		wp_trash_post( $this->about );
		$this->assertSame( 'about', get_post_meta( $this->about, '_wp_desired_post_slug', true ), 'Precondition: core keeps the slug it replaced with about__trashed.' );
		$this->assertSame( $this->about, (int) get_post( $this->team )->post_parent, 'Precondition: the subpage stays under the trashed parent.' );

		wp_delete_post( $this->about, true );

		$this->assertSame( $this->company, $this->ledger->find( 'page', 'company/about' ) );
		$this->assertSame( $this->team, ( new Resolver( $this->ledger ) )->resolve( 'page', 'company/about/team' )->ID );
	}

	public function test_emptying_the_trash_records_the_pre_trash_path_of_a_numbered_slug(): void {
		// Trash an earlier "about" first, so core has to suffix this one __trashed-2.
		wp_update_post(
			[
				'ID'        => $this->about,
				'post_name' => 'about-tmp',
			]
		);
		$earlier = self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_name'   => 'about',
				'post_parent' => $this->company,
			]
		);
		wp_trash_post( $earlier );
		wp_update_post(
			[
				'ID'        => $this->about,
				'post_name' => 'about',
			]
		);
		wp_trash_post( $this->about );
		$this->assertSame( 'about__trashed-2', get_post( $this->about )->post_name, 'Precondition: core numbered the trashed slug.' );

		wp_delete_post( $this->about, true );

		$this->assertSame( $this->company, $this->ledger->find( 'page', 'company/about' ) );
		$this->assertSame( $this->team, ( new Resolver( $this->ledger ) )->resolve( 'page', 'company/about/team' )->ID );
	}

	public function test_emptying_the_trash_without_the_desired_slug_strips_the_suffix(): void {
		wp_trash_post( $this->about );
		delete_post_meta( $this->about, '_wp_desired_post_slug' );

		wp_delete_post( $this->about, true );

		$this->assertSame( $this->company, $this->ledger->find( 'page', 'company/about' ) );
	}

	public function test_deleting_a_parent_whose_only_child_is_trashed_records_its_path(): void {
		wp_trash_post( $this->team );
		$this->assertSame( 'trash', get_post_status( $this->team ), 'Precondition: the only child is in the trash.' );

		wp_delete_post( $this->about, true );

		$this->assertSame( $this->company, (int) get_post( $this->team )->post_parent, 'Precondition: core re-parents trashed children too.' );
		$this->assertSame( [ 'company/about' ], $this->ledger->paths_for( $this->company ) );
	}

	public function test_emptying_a_trashed_tree_keeps_paths_through_trashed_ancestors(): void {
		$leadership = self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_name'   => 'leadership',
				'post_parent' => $this->team,
			]
		);

		wp_trash_post( $this->about );
		wp_trash_post( $this->team );
		$this->assertSame( $this->about, (int) get_post( $this->team )->post_parent, 'Precondition: both ancestors are in the trash.' );

		// The trash empties child-first: team, then about.
		wp_delete_post( $this->team, true );
		wp_delete_post( $this->about, true );

		$this->assertSame( $this->company, (int) get_post( $leadership )->post_parent, 'Precondition: core moved leadership up twice.' );
		$resolved = ( new Resolver( $this->ledger ) )->resolve( 'page', 'company/about/team/leadership' );
		$this->assertNotNull( $resolved );
		$this->assertSame( $leadership, $resolved->ID );
	}

	public function test_deleting_a_subpage_trashed_with_its_parent_records_nothing(): void {
		self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_name'   => 'leadership',
				'post_parent' => $this->team,
			]
		);
		Markers::add( $this->team, Markers::trashed_key( $this->about ), '1' );

		wp_delete_post( $this->team, true );

		$this->assertSame( [], $this->ledger->paths_for( $this->about ) );
	}

	/**
	 * Company › About, with About renamed to about-us and a newer page that
	 * held company/about since renamed too: that page is the newest holder.
	 *
	 * @return int The newer page.
	 */
	private function newer_holder(): int {
		wp_update_post(
			[
				'ID'        => $this->about,
				'post_name' => 'about-us',
			]
		);
		$other = self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_name'   => 'about',
				'post_parent' => $this->company,
			]
		);
		wp_update_post(
			[
				'ID'        => $other,
				'post_name' => 'x-now',
			]
		);
		return $other;
	}

	public function test_trashing_a_holder_with_no_subpages_drops_the_cached_lookup(): void {
		$other = $this->newer_holder();
		$this->assertSame( $other, $this->ledger->find( 'page', 'company/about' ), 'Cached: the newer page holds the path.' );

		wp_trash_post( $other );

		$this->assertSame( $this->about, $this->ledger->find( 'page', 'company/about' ), 'The live holder takes over at once.' );
		$this->assertSame( $this->team, ( new Resolver( $this->ledger ) )->resolve( 'page', 'company/about/team' )->ID );
	}

	public function test_restoring_a_holder_drops_the_cached_lookup(): void {
		$other = $this->newer_holder();
		wp_trash_post( $other );
		$this->assertSame( $this->about, $this->ledger->find( 'page', 'company/about' ) );

		wp_untrash_post( $other );

		$this->assertSame( $other, $this->ledger->find( 'page', 'company/about' ), 'The restored page holds the path again.' );
	}

	public function test_deleting_a_holder_drops_the_cached_lookup(): void {
		$other = $this->newer_holder();
		$this->assertSame( $other, $this->ledger->find( 'page', 'company/about' ) );

		wp_delete_post( $other, true );

		$this->assertSame( $this->about, $this->ledger->find( 'page', 'company/about' ) );
	}
}
