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
use HierarchyGuard\Trash\Change_Log;
use HierarchyGuard\Trash\Markers;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * @covers \HierarchyGuard\Change_Listener
 */
final class ChangeListenerTest extends WP_UnitTestCase {

	use Trash_Fixtures;

	private Ledger $ledger;

	private Change_Log $log;

	private int $company;

	private int $about;

	private int $team;

	public function set_up(): void {
		parent::set_up();
		delete_option( Settings::OPTION );
		delete_option( Ledger::ROOT_OPTION );
		Post_Types::reset();
		Change_Listener::suspend( false );
		Change_Log::reset_request();
		$this->set_permalink_structure( '/%postname%/' );
		// These tests exercise core's own trash behaviour, which the keep policy leaves untouched.
		add_filter( 'hierarchy_guard_trash_policy', static fn(): string => Settings::POLICY_KEEP );

		$this->ledger  = new Ledger();
		$this->log     = new Change_Log();
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
		$listener = new Change_Listener( $this->ledger, $this->log );
		$post     = get_post( $this->about );

		$queries = get_num_queries();
		$listener->on_post_updated( $this->about, $post, $post );

		$this->assertSame( $queries, get_num_queries() );
		$this->assertSame( [], $this->ledger->paths_for( $this->about ) );
	}

	public function test_a_url_changing_save_records_a_change_for_the_user(): void {
		$user = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user );

		wp_update_post(
			[
				'ID'        => $this->about,
				'post_name' => 'company-info',
			]
		);

		$change = $this->log->latest( $user );
		$this->assertNotNull( $change );
		$this->assertSame( 'save', $change['action'] );
		$this->assertSame( 'page', $change['post_type'] );
		$this->assertSame( [ $this->about ], array_keys( $change['roots'] ) );
		$root = $change['roots'][ $this->about ];
		$this->assertSame( $this->about, $root['root'] );
		$this->assertSame( $this->company, $root['to'], 'Its parent after the save.' );
		$this->assertSame( 'company/about', $root['path'], 'Its path right before the save.' );
		$this->assertSame( '', $root['policy'] );
		$this->assertSame( Change_Log::empty_counts(), $root['counts'] );
		$this->assertSame( Change_Log::empty_counts(), $change['counts'] );
	}

	public function test_a_move_records_the_new_parent_and_the_old_path(): void {
		$user = self::factory()->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $user );

		wp_update_post(
			[
				'ID'          => $this->team,
				'post_parent' => $this->company,
			]
		);

		$root = $this->log->latest( $user )['roots'][ $this->team ];
		$this->assertSame( $this->company, $root['to'] );
		$this->assertSame( 'company/about/team', $root['path'] );
	}

	public function test_a_save_without_a_user_records_the_old_path_but_no_change(): void {
		wp_set_current_user( 0 );

		wp_update_post(
			[
				'ID'        => $this->about,
				'post_name' => 'company-info',
			]
		);

		$this->assertSame( [ 'company/about' ], $this->ledger->paths_for( $this->about ) );
		$this->assertNull( $this->log->latest( 0 ) );
	}

	public function test_a_save_that_records_no_old_path_records_no_change(): void {
		$user = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user );
		$draft = self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_status' => 'draft',
				'post_name'   => 'draft',
			]
		);

		wp_update_post(
			[
				'ID'        => $draft,
				'post_name' => 'draft-two',
			]
		);
		wp_update_post(
			[
				'ID'         => $this->about,
				'post_title' => 'About us',
			]
		);
		Change_Listener::suspend( true );
		wp_update_post(
			[
				'ID'        => $this->team,
				'post_name' => 'crew',
			]
		);
		Change_Listener::suspend( false );

		$this->assertNull( $this->log->latest( $user ), 'A draft has no URL, a title is no URL, and a suspended listener records nothing.' );
	}

	public function test_a_save_the_ledger_does_not_record_is_no_change(): void {
		$user = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user );
		add_filter( 'hierarchy_guard_should_record', '__return_false' );

		wp_update_post(
			[
				'ID'        => $this->about,
				'post_name' => 'company-info',
			]
		);

		$this->assertSame( [], $this->ledger->paths_for( $this->about ) );
		$this->assertNull( $this->log->latest( $user ), 'Nothing redirects that old URL, so there are no redirects to offer.' );
	}

	public function test_an_old_path_the_page_already_holds_is_still_a_change(): void {
		global $wpdb;
		$user = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user );
		wp_update_post(
			[
				'ID'        => $this->about,
				'post_name' => 'company-info',
			]
		);
		// Back at its old path without a save, as a raw move makes it: the entry stays.
		$wpdb->update( $wpdb->posts, [ 'post_name' => 'about' ], [ 'ID' => $this->about ] );
		clean_post_cache( $this->about );
		Change_Log::reset_request();

		wp_update_post(
			[
				'ID'        => $this->about,
				'post_name' => 'who-we-are',
			]
		);

		$change = $this->log->latest( $user );
		$this->assertSame( 'save', $change['action'] );
		$this->assertSame( 'company/about', $change['roots'][ $this->about ]['path'] );
	}

	public function test_saves_in_one_request_are_one_change_and_a_later_change_replaces_it(): void {
		$user = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user );

		// One request, as a bulk edit makes it.
		foreach ( [
			$this->about => 'company-info',
			$this->team  => 'crew',
		] as $id => $slug ) {
			wp_update_post(
				[
					'ID'        => $id,
					'post_name' => $slug,
				]
			);
		}
		$first = $this->log->latest( $user );
		$this->assertSame( [ $this->about, $this->team ], array_keys( $first['roots'] ) );
		$this->assertSame( 'company/company-info/team', $first['roots'][ $this->team ]['path'] );

		Change_Log::reset_request();
		wp_update_post(
			[
				'ID'          => $this->team,
				'post_parent' => $this->company,
			]
		);
		$second = $this->log->latest( $user );
		$this->assertNotSame( $first['id'], $second['id'] );
		$this->assertSame( [ $this->team ], array_keys( $second['roots'] ) );

		Change_Log::reset_request();
		remove_all_filters( 'hierarchy_guard_trash_policy' );
		wp_trash_post( $this->company );
		$this->assertSame( 'trash', $this->log->latest( $user )['action'], 'A trash replaces the save.' );
	}

	public function test_a_bulk_edit_records_each_page_at_its_url_from_before_the_request(): void {
		$user = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user );
		$leadership = $this->page( 'leadership', $this->team );

		// The list shows a parent above its subpages, so core saves it first.
		$done = $this->bulk_edit( [ $this->about, $this->team ], 0 );

		$this->assertSame( [ $this->about, $this->team ], $done['updated'] );
		$change = $this->log->latest( $user );
		$this->assertSame( [ $this->about, $this->team ], array_keys( $change['roots'] ) );
		$this->assertSame( 'company/about', $change['roots'][ $this->about ]['path'] );
		$this->assertSame( 'company/about/team', $change['roots'][ $this->team ]['path'], 'The URL it had before the request, not one it had only inside it.' );
		$this->assertSame( [ 'company/about/team' ], $this->ledger->paths_for( $this->team ) );
		$resolver = new Resolver( $this->ledger );
		$this->assertSame( $this->team, $resolver->resolve( 'page', 'company/about/team' )->ID );
		$this->assertSame( $leadership, $resolver->resolve( 'page', 'company/about/team/leadership' )->ID );
	}

	public function test_a_bulk_edit_in_either_order_records_the_same_urls(): void {
		$user = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user );

		$this->bulk_edit( [ $this->team, $this->about ], 0 );

		$change = $this->log->latest( $user );
		$this->assertSame( 'company/about/team', $change['roots'][ $this->team ]['path'] );
		$this->assertSame( 'company/about', $change['roots'][ $this->about ]['path'] );
	}

	public function test_a_bulk_edit_writes_its_change_once(): void {
		$user = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user );
		$target = $this->page( 'target' );
		$ids    = [];
		for ( $i = 0; $i < 20; $i++ ) {
			$ids[] = $this->page( 'bulk-' . $i, $this->company );
		}
		$writes = 0;
		add_filter(
			'pre_set_transient_' . Change_Log::key( $user ),
			static function ( $value ) use ( &$writes ) {
				++$writes;
				return $value;
			}
		);

		$this->bulk_edit( $ids, $target );
		Change_Log::reset_request();

		$this->assertCount( 20, $this->log->latest( $user )['roots'] );
		$this->assertSame( 1, $writes, 'One write for the whole Bulk Edit, not one per page.' );
	}

	public function test_a_bulk_edit_of_a_999_page_selection_reads_the_selection_once(): void {
		$user = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user );
		$target = $this->page( 'target' );
		$ids    = [];
		for ( $i = 0; $i < 20; $i++ ) {
			$ids[] = $this->page( 'bulk-' . $i, $this->company );
		}
		// The rest of a 999-row selection, ending with one ID to count the reads by.
		$selection = array_merge( $ids, range( 900000001, 900000978 ), [ 999999999 ] );
		$this->assertCount( 999, $selection );
		$parses = 0;
		add_filter(
			'sanitize_text_field',
			static function ( $filtered, $str ) use ( &$parses ) {
				if ( '999999999' === $str ) {
					++$parses;
				}
				return $filtered;
			},
			10,
			2
		);

		$this->bulk_edit( $selection, $target );

		$this->assertSame( 1, $parses, 'Each of the 20 saves asks for the selection; it is parsed once.' );
		$change = $this->log->latest( $user );
		$this->assertSame( $ids, array_keys( $change['roots'] ) );
		$this->assertSame( 'company/bulk-0', $change['roots'][ $ids[0] ]['path'] );
		$this->assertSame( 'list', $change['origin'] );
	}

	public function test_a_save_records_where_it_was_made_and_whether_it_was_shown_there(): void {
		$user = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user );
		$made   = function ( callable $save ) use ( $user ): array {
			Change_Log::reset_request();
			$save();
			$change = $this->log->latest( $user );
			return [ $change['origin'], $change['shown'] ];
		};
		$rename = static fn( int $id, string $slug ): callable => static fn() => wp_update_post(
			[
				'ID'        => $id,
				'post_name' => $slug,
			]
		);

		$this->assertSame( [ 'classic', false ], $made( fn() => $this->as_classic_save( $rename( $this->about, 'a1' ) ) ), 'Its notice prints on the edit screen it lands on.' );
		$this->assertSame( [ 'list', true ], $made( fn() => $this->as_quick_edit( $rename( $this->about, 'a2' ) ) ), 'Quick Edit shows it in place.' );
		$this->assertSame( [ 'list', false ], $made( fn() => $this->bulk_edit( [ $this->team ], $this->company ) ), 'The Pages list prints it when core redirects back.' );
		$this->assertSame( [ 'editor', true ], $made( fn() => $this->assertSame( 200, $this->rest_save( $this->about, 'a3' ) ) ), 'The block editor shows it in place.' );
		$this->assertSame( [ '', false ], $made( $rename( $this->about, 'a4' ) ), 'Code saves it nowhere in particular.' );
	}

	public function test_a_rest_save_that_changes_the_slug_records_a_change(): void {
		$user = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user );
		$request = new WP_REST_Request( 'POST', '/wp/v2/pages/' . $this->about );
		$request->set_body_params( [ 'slug' => 'company-info' ] );

		$this->assertSame( 200, rest_do_request( $request )->get_status() );

		$change = $this->log->latest( $user );
		$this->assertSame( 'save', $change['action'] );
		$this->assertSame( 'company/about', $change['roots'][ $this->about ]['path'] );
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

	public function test_a_holder_a_status_update_puts_in_the_trash_drops_the_cached_lookup(): void {
		$other = $this->newer_holder();
		$this->assertSame( $other, $this->ledger->find( 'page', 'company/about' ), 'Cached: the newer page holds the path.' );

		// As `wp post update --post_status=trash` does it: no wp_trash_post(), so no trashed_post.
		wp_update_post(
			[
				'ID'          => $other,
				'post_status' => 'trash',
			]
		);

		$this->assertSame( $this->about, $this->ledger->find( 'page', 'company/about' ), 'The live holder takes over at once.' );
		$this->assertSame( $this->team, ( new Resolver( $this->ledger ) )->resolve( 'page', 'company/about/team' )->ID );
	}

	public function test_a_holder_a_status_update_takes_out_of_the_trash_drops_the_cached_lookup(): void {
		$other = $this->newer_holder();
		wp_trash_post( $other );
		$this->assertSame( $this->about, $this->ledger->find( 'page', 'company/about' ), 'Cached: the page in the trash holds nothing.' );

		wp_update_post(
			[
				'ID'          => $other,
				'post_status' => 'publish',
			]
		);

		$this->assertSame( $other, $this->ledger->find( 'page', 'company/about' ), 'The page out of the trash holds the path again.' );
	}

	public function test_a_holder_the_rest_api_takes_out_of_the_trash_drops_the_cached_lookup(): void {
		$other = $this->newer_holder();
		wp_trash_post( $other );
		$this->assertSame( $this->about, $this->ledger->find( 'page', 'company/about' ) );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		// The block editor's "Restore" on a page in the trash: a status change, not wp_untrash_post().
		$request = new WP_REST_Request( 'POST', '/wp/v2/pages/' . $other );
		$request->set_param( 'status', 'publish' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $other, $this->ledger->find( 'page', 'company/about' ) );
	}

	public function test_trashing_a_holder_looks_its_entries_up_once(): void {
		global $wpdb;
		$other   = $this->newer_holder();
		$lookups = 0;
		$count   = static function ( string $sql ) use ( &$lookups, $wpdb ): string {
			if ( str_starts_with( $sql, "SELECT 1 FROM {$wpdb->postmeta} WHERE post_id" ) ) {
				++$lookups;
			}
			return $sql;
		};
		add_filter( 'query', $count );

		wp_trash_post( $other );
		wp_untrash_post( $other );

		remove_filter( 'query', $count );
		$this->assertSame( 2, $lookups, 'The status change and core\'s trash hook that follows it check the page once between them.' );
	}
}
