<?php
/**
 * Old and new URL pairs.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Admin\Notices;
use HierarchyGuard\Batch\Queue;
use HierarchyGuard\Export\Csv;
use HierarchyGuard\Ledger;
use HierarchyGuard\Paths;
use HierarchyGuard\Post_Types;
use HierarchyGuard\Redirects\Batch_Resolver;
use HierarchyGuard\Redirects\Pairs;
use HierarchyGuard\Redirects\Pairs_Blocks;
use HierarchyGuard\Redirects\Providers;
use HierarchyGuard\Redirects\Resolver;
use HierarchyGuard\Redirects\Server;
use HierarchyGuard\Settings;
use HierarchyGuard\Trash\Change_Log;
use WP_UnitTestCase;

/**
 * @covers \HierarchyGuard\Redirects\Pairs
 */
final class PairsTest extends WP_UnitTestCase {

	use Trash_Fixtures;

	/**
	 * Page IDs.
	 *
	 * @var array<string,int>
	 */
	private array $ids;

	private int $admin;

	private Pairs $pairs;

	private Change_Log $log;

	public function set_up(): void {
		parent::set_up();
		delete_option( Settings::OPTION );
		delete_option( Ledger::ROOT_OPTION );
		delete_option( Ledger::ROOT_TARGETS );
		Post_Types::reset();
		Change_Log::reset_request();
		$this->set_permalink_structure( '/%postname%/' );
		$this->admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $this->admin );
		$this->pairs = new Pairs();
		$this->log   = new Change_Log();
		$this->ids   = $this->make_tree();
	}

	public function tear_down(): void {
		$this->set_permalink_structure( '' );
		parent::tear_down();
	}

	/**
	 * Old and new URLs of collected pairs.
	 *
	 * @param array<string,mixed> $collected Collected pairs.
	 * @return array<int,string> "old -> new" lines.
	 */
	private function lines( array $collected ): array {
		return array_map( static fn( array $pair ): string => $pair['old'] . ' -> ' . $pair['new'], $this->pairs->page( $collected, 1, 100 )['pairs'] );
	}

	/**
	 * Asserts that every collected pair leads where the resolver sends its old path.
	 *
	 * @param array<string,mixed> $collected Collected pairs.
	 */
	private function assert_the_resolver_agrees( array $collected ): void {
		$resolver = new Resolver( new Ledger() );
		foreach ( $collected['pairs'] as $pair ) {
			$target = $resolver->resolve( (string) $collected['post_type'], $pair['old'] );
			$this->assertSame( $pair['id'], $target ? (int) $target->ID : 0, $pair['old'] );
		}
	}

	/**
	 * The pairs of the admin's last change.
	 *
	 * @return array<string,mixed>
	 */
	private function last_change(): array {
		return $this->pairs->collect_change( $this->log->latest( $this->admin ) );
	}

	/**
	 * Saves a page as the admin, in a request of its own, and returns the
	 * change the save recorded.
	 *
	 * @param int                 $post_id Page.
	 * @param array<string,mixed> $fields  Fields to save.
	 * @return array<string,mixed>
	 */
	private function save( int $post_id, array $fields ): array {
		Change_Log::reset_request();
		wp_update_post( array_merge( [ 'ID' => $post_id ], $fields ) );
		$change = $this->log->latest( $this->admin );
		$this->assertIsArray( $change, 'The save recorded a change.' );
		$this->assertSame( 'save', $change['action'] );
		return $change;
	}

	/**
	 * Renames a page as the admin, in a request of its own, and returns the change.
	 *
	 * @param int    $post_id Page.
	 * @param string $slug    New slug.
	 * @return array<string,mixed>
	 */
	private function rename( int $post_id, string $slug ): array {
		return $this->save( $post_id, [ 'post_name' => $slug ] );
	}

	public function test_a_trash_lists_the_moved_subpages_under_the_old_path(): void {
		wp_trash_post( $this->ids['about'] );
		$collected = $this->last_change();

		$this->assertSame(
			[
				'/company/about/history/ -> /company/history/',
				'/company/about/team/ -> /company/team/',
				'/company/about/team/leadership/ -> /company/team/leadership/',
			],
			$this->lines( $collected )
		);
		$this->assertSame(
			[
				[
					'old'    => '/company/about/',
					'reason' => 'trash',
				],
			],
			$this->pairs->page( $collected, 1, 20 )['unresolved'],
			'The trashed page itself has no target.'
		);
		$this->assertFalse( $collected['capped'], 'While the page is in the Trash, the list is complete.' );
		$this->assertFalse( $collected['gone'] );
	}

	public function test_a_subpage_renamed_on_the_move_is_listed_at_its_old_slug(): void {
		$this->page( 'team', $this->ids['company'] );

		wp_trash_post( $this->ids['about'] );

		$this->assertContains( '/company/about/team/ -> /company/team-2/', $this->lines( $this->last_change() ) );
	}

	public function test_once_every_root_is_deleted_for_good_nothing_is_listed(): void {
		wp_trash_post( $this->ids['about'] );
		$change = $this->log->latest( $this->admin );
		wp_delete_post( $this->ids['about'], true );

		$collected = $this->pairs->collect_change( $change );

		$this->assertTrue( $collected['gone'], 'Its moved markers went with it, so there is no list.' );
		$this->assertSame( [], $collected['pairs'] );
		$this->assertSame( [], $collected['unresolved'] );
		$this->assertFalse( $collected['capped'] );
		$this->assertNull( ( new Notices( $this->log, new Queue(), new Pairs(), new Providers() ) )->urls_notice( $change ), 'No notice offers it.' );
		$this->assertSame( $this->ids['team'], ( new Resolver( new Ledger() ) )->resolve( 'page', 'company/about/team' )->ID, 'The built-in redirects still serve the old URLs.' );
	}

	public function test_one_parent_deleted_for_good_among_others_in_the_trash_makes_the_list_gone(): void {
		$sibling = $this->page( 'careers', $this->ids['company'] );
		$this->page( 'jobs', $sibling );
		// One request, one change with two roots.
		wp_trash_post( $this->ids['about'] );
		wp_trash_post( $sibling );
		$change = $this->log->latest( $this->admin );

		$collected = $this->pairs->collect_change( $change );
		$this->assertFalse( $collected['capped'], 'While both are in the Trash, the list is complete.' );
		$this->assertFalse( $collected['gone'] );
		$this->assertSame(
			[
				'/company/about/history/ -> /company/history/',
				'/company/about/team/ -> /company/team/',
				'/company/about/team/leadership/ -> /company/team/leadership/',
				'/company/careers/jobs/ -> /company/jobs/',
			],
			$this->lines( $collected )
		);

		wp_delete_post( $this->ids['about'], true );
		$collected = $this->pairs->collect_change( $change );

		$this->assertTrue( $collected['gone'], 'About had redirects to list, and they are gone with it.' );
		$this->assertSame( [], $collected['pairs'], 'Careers, still in the Trash, lists nothing either.' );
		$this->assertSame( [], $collected['unresolved'] );
		$this->assertFalse( $collected['capped'] );
		$this->assertNull( ( new Notices( $this->log, new Queue(), new Pairs(), new Providers() ) )->urls_notice( $change ), 'No notice offers it.' );
		$this->assertSame( $this->ids['team'], ( new Resolver( new Ledger() ) )->resolve( 'page', 'company/about/team' )->ID, 'The built-in redirects still serve the old URLs.' );
	}

	public function test_a_parent_deleted_for_good_beside_one_restored_makes_the_list_gone(): void {
		$sibling = $this->page( 'careers', $this->ids['company'] );
		$this->page( 'jobs', $sibling );
		wp_trash_post( $this->ids['about'] );
		wp_trash_post( $sibling );
		$change = $this->log->latest( $this->admin );

		Change_Log::reset_request();
		wp_untrash_post( $sibling );
		Change_Log::reset_request();
		wp_delete_post( $this->ids['about'], true );
		$collected = $this->pairs->collect_change( $change );

		$this->assertTrue( $collected['gone'] );
		$this->assertSame( [], $collected['pairs'] );
		$this->assertFalse( $collected['capped'] );
	}

	public function test_a_parent_deleted_for_good_that_moved_nothing_leaves_the_others_listed(): void {
		// Parent first, in one bulk action: p's job skips its only child c, which the same action trashes next, so it moves nothing.
		$p   = $this->page( 'p', $this->ids['company'] );
		$c   = $this->page( 'c', $p );
		$ids = [ $this->ids['about'], $p, $c ];
		$this->start_core_bulk( 'trash', $ids );
		foreach ( $ids as $id ) {
			wp_trash_post( $id );
		}
		$this->end_core_bulk();
		$change = $this->log->latest( $this->admin );
		$this->assertSame( Settings::POLICY_REPARENT, $change['roots'][ $p ]['policy'], 'Precondition.' );
		$this->assertSame( 'company/p', $change['roots'][ $p ]['path'], 'Precondition.' );
		$this->assertSame( 0, $change['roots'][ $p ]['counts']['moved'], 'Precondition: p moved nothing.' );
		$before = $this->pairs->collect_change( $change );

		wp_delete_post( $p, true );
		$collected = $this->pairs->collect_change( $change );

		$this->assertFalse( $collected['gone'], 'P had no redirect to list.' );
		$this->assertFalse( $collected['capped'], 'The list is complete.' );
		$this->assertSame(
			[
				'/company/about/history/ -> /company/history/',
				'/company/about/team/ -> /company/team/',
				'/company/about/team/leadership/ -> /company/team/leadership/',
			],
			$this->lines( $collected )
		);
		$this->assertSame( $this->lines( $before ), $this->lines( $collected ), 'The same list as before the delete.' );
		$this->assert_the_resolver_agrees( $collected );
	}

	public function test_once_the_page_a_save_moved_is_deleted_for_good_the_list_is_gone(): void {
		$careers = $this->page( 'careers', $this->ids['company'] );
		$this->page( 'jobs', $careers );
		$change = $this->rename( $careers, 'join-us' );
		$this->assertSame( [ '/company/careers/ -> /company/join-us/', '/company/careers/jobs/ -> /company/join-us/jobs/' ], $this->lines( $this->pairs->collect_change( $change ) ), 'Precondition.' );

		// Another admin trashes it, which records no change for this one, then deletes it for good.
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		Change_Log::reset_request();
		wp_trash_post( $careers );
		Change_Log::reset_request();
		wp_delete_post( $careers, true );
		wp_set_current_user( $this->admin );
		$this->assertSame( $change['id'], $this->log->latest( $this->admin )['id'], 'Precondition: the save is still the admin\'s latest change.' );

		$collected = $this->pairs->collect_change( $change );

		$this->assertTrue( $collected['gone'] );
		$this->assertSame( [], $collected['pairs'] );
		$this->assertFalse( $collected['capped'] );
	}

	public function test_a_restored_page_needs_no_redirects(): void {
		wp_trash_post( $this->ids['about'] );
		$change = $this->log->latest( $this->admin );
		wp_untrash_post( $this->ids['about'] );

		$this->assertSame( [], Pairs_Blocks::change_blocks( $change ) );
	}

	public function test_only_a_trash_that_moved_subpages_up_has_pairs(): void {
		add_filter( 'hierarchy_guard_trash_policy', static fn(): string => Settings::POLICY_KEEP );
		wp_trash_post( $this->ids['about'] );

		$this->assertSame( [], Pairs_Blocks::change_blocks( $this->log->latest( $this->admin ) ) );

		$this->assertSame( [], Pairs_Blocks::change_blocks( [ 'action' => 'restore' ] ) );
	}

	public function test_the_blocks_of_a_bulk_trash_cost_the_same_few_queries(): void {
		$roots = [];
		foreach ( [ 'a', 'b', 'c', 'd' ] as $slug ) {
			$roots[] = $this->page( 'parent-' . $slug, $this->ids['company'] );
			$this->page( 'kid-' . $slug, end( $roots ) );
		}
		foreach ( $roots as $root ) {
			wp_trash_post( $root );
		}
		$change = $this->log->latest( $this->admin );

		$queries = get_num_queries();
		Pairs_Blocks::change_blocks( $change );

		$this->assertLessThanOrEqual( 4, get_num_queries() - $queries, 'Statuses, markers and moved pages in one query each, whatever the number of roots.' );
	}

	public function test_a_trash_lists_a_subpage_renamed_before_it_at_the_slug_it_had_then(): void {
		wp_update_post(
			[
				'ID'        => $this->ids['team'],
				'post_name' => 'crew',
			]
		);
		wp_trash_post( $this->ids['about'] );
		$change = $this->log->latest( $this->admin );

		$this->assertContains( '/company/about/crew/ -> /company/crew/', $this->lines( $this->pairs->collect_change( $change ) ), 'The URL that worked until the trash.' );
	}

	public function test_a_child_trashed_before_its_parent_in_one_bulk_action_keeps_its_pairs(): void {
		$orders = [
			'child first'  => true,
			'parent first' => false,
		];
		foreach ( $orders as $order => $child_first ) {
			Change_Log::reset_request();
			$b   = $this->page( 'b', $this->ids['company'] );
			$a   = $this->page( 'a', $b );
			$kid = $this->page( 'kid', $a );
			$ids = $child_first ? [ $a, $b ] : [ $b, $a ];

			$this->start_core_bulk( 'trash', $ids );
			foreach ( $ids as $id ) {
				wp_trash_post( $id );
			}
			$this->end_core_bulk();
			$change = $this->log->latest( $this->admin );

			$collected = $this->pairs->collect_change( $change );
			$this->assertSame( [ '/company/b/a/kid/ -> /company/kid/' ], $this->lines( $collected ), $order );
			$this->assert_the_resolver_agrees( $collected );
			$this->assertSame( (int) $this->ids['company'], (int) get_post( $kid )->post_parent, $order );
			$this->assertNotNull( ( new Notices( $this->log, new Queue(), new Pairs(), new Providers() ) )->urls_notice( $change ), $order );

			wp_delete_post( $a, true );
			$collected = $this->pairs->collect_change( $change );
			$this->assertTrue( $collected['gone'], $order . ': a moved its kid up, so once it is deleted for good the list is gone.' );
			$this->assertSame( [], $collected['pairs'], $order );

			foreach ( [ $kid, $b ] as $id ) {
				wp_delete_post( $id, true );
			}
		}
	}

	public function test_an_old_subpage_url_a_live_page_uses_under_a_hidden_page_is_not_listed(): void {
		wp_trash_post( $this->ids['about'] );
		$change = $this->log->latest( $this->admin );
		// A new draft about, with a published team under it: /company/about/team/ works again.
		$draft = $this->page( 'about', $this->ids['company'], 'draft' );
		$this->page( 'team', $draft );

		$lines = $this->lines( $this->pairs->collect_change( $change ) );

		$this->assertNotContains( '/company/about/team/ -> /company/team/', $lines, 'A redirect plugin would hide the live page.' );
		$this->assertContains( '/company/about/history/ -> /company/history/', $lines );
		$this->assertContains( '/company/about/team/leadership/ -> /company/team/leadership/', $lines, 'No live page uses that one.' );
	}

	public function test_collecting_a_bulk_trash_costs_the_same_queries_for_two_roots_or_six(): void {
		$two = $this->queries_to_collect_bulk( 'two', 2 );
		$six = $this->queries_to_collect_bulk( 'six', 6 );

		$this->assertSame( $two, $six, 'The pages at the old paths are looked up for every block at once.' );
	}

	/**
	 * Queries a cold collect of a bulk trash costs, for roots that each have a subpage.
	 *
	 * @param string $prefix Slug prefix.
	 * @param int    $roots  How many roots.
	 * @return int
	 */
	private function queries_to_collect_bulk( string $prefix, int $roots ): int {
		Change_Log::reset_request();
		$ids = [];
		for ( $i = 1; $i <= $roots; $i++ ) {
			$ids[] = $this->page( $prefix . '-' . $i, $this->ids['company'] );
			$this->page( $prefix . '-kid-' . $i, end( $ids ) );
		}
		foreach ( $ids as $id ) {
			wp_trash_post( $id );
		}
		$change = $this->log->latest( $this->admin );
		wp_cache_flush();

		$queries   = get_num_queries();
		$collected = $this->pairs->collect_change( $change );
		$this->assertCount( $roots, $collected['pairs'] );
		return get_num_queries() - $queries;
	}

	public function test_a_trash_lists_a_subpage_moved_in_and_renamed_at_the_url_it_had_until_the_trash(): void {
		// Careers lived under Company, moved under about, and was renamed jobs there.
		$careers = $this->page( 'careers', $this->ids['company'] );
		wp_update_post(
			[
				'ID'          => $careers,
				'post_parent' => $this->ids['about'],
			]
		);
		wp_update_post(
			[
				'ID'        => $careers,
				'post_name' => 'jobs',
			]
		);
		wp_trash_post( $this->ids['about'] );
		$change = $this->log->latest( $this->admin );

		$collected = $this->pairs->collect_change( $change );
		$this->assertContains( '/company/about/jobs/ -> /company/jobs/', $this->lines( $collected ), 'The URL that worked until the trash.' );
		$this->assertNotContains( '/company/about/careers/ -> /company/jobs/', $this->lines( $collected ), 'That URL never existed.' );
		$this->assert_the_resolver_agrees( $collected );
	}

	public function test_a_trash_lists_a_subpage_renamed_before_it_moved_in_at_the_url_it_had_until_the_trash(): void {
		$services = $this->page( 'services', $this->ids['company'] );
		wp_update_post(
			[
				'ID'        => $services,
				'post_name' => 'our-services',
			]
		);
		wp_update_post(
			[
				'ID'          => $services,
				'post_parent' => $this->ids['about'],
			]
		);
		wp_trash_post( $this->ids['about'] );

		$collected = $this->last_change();

		$this->assertContains( '/company/about/our-services/ -> /company/our-services/', $this->lines( $collected ) );
		$this->assertNotContains( '/company/about/services/ -> /company/our-services/', $this->lines( $collected ), 'That URL never existed.' );
		$this->assert_the_resolver_agrees( $collected );
	}

	public function test_a_trash_of_a_top_level_page_lists_a_subpage_at_the_url_it_had_until_the_trash(): void {
		$services = $this->page( 'services' );
		$web      = $this->page( 'web-design' );
		wp_update_post(
			[
				'ID'        => $web,
				'post_name' => 'web',
			]
		);
		wp_update_post(
			[
				'ID'          => $web,
				'post_parent' => $services,
			]
		);
		wp_trash_post( $services );

		$collected = $this->last_change();

		$this->assertSame( [ '/services/web/ -> /web/' ], $this->lines( $collected ), '/services/web-design/ never existed.' );
		$this->assert_the_resolver_agrees( $collected );
	}

	public function test_a_long_chain_of_deleted_targets_costs_no_more_queries_than_a_short_one(): void {
		$short = $this->queries_to_collect_chain( 'short', 4 );
		$long  = $this->queries_to_collect_chain( 'long', 12 );

		$this->assertSame( $short, $long, 'Each deleted root is followed once, without a read.' );
	}

	/**
	 * Queries a cold collect costs for a chain of nested roots, trashed child
	 * first in one bulk action and then all but the first deleted for good.
	 * The first one moved its subpage up under the next, and every root above
	 * it kept its subpages, so none of the deleted ones had a redirect to
	 * list; as each was deleted, core moved the subpage up again, through
	 * all of them.
	 *
	 * @param string $prefix Slug prefix.
	 * @param int    $length How many roots.
	 * @return int
	 */
	private function queries_to_collect_chain( string $prefix, int $length ): int {
		Change_Log::reset_request();
		$chain  = [];
		$parent = $this->ids['company'];
		for ( $i = 1; $i <= $length; $i++ ) {
			$parent  = $this->page( $prefix . '-' . $i, $parent );
			$chain[] = $parent;
		}
		$kid = $this->page( $prefix . '-kid', $parent );
		// A subpage of its own under each root above the first, so each is a root of the trash too.
		foreach ( array_slice( $chain, 0, -1 ) as $id ) {
			$this->page( $prefix . '-leaf-' . $id, $id );
		}
		$ids    = array_reverse( $chain );
		$keep   = array_slice( $ids, 1 );
		$policy = static fn( string $policy, \WP_Post $post ): string => in_array( $post->ID, $keep, true ) ? Settings::POLICY_KEEP : $policy;
		add_filter( 'hierarchy_guard_trash_policy', $policy, 10, 2 );
		$this->start_core_bulk( 'trash', $ids );
		foreach ( $ids as $id ) {
			wp_trash_post( $id );
		}
		$this->end_core_bulk();
		remove_filter( 'hierarchy_guard_trash_policy', $policy, 10 );
		$change = $this->log->latest( $this->admin );
		$this->assertCount( $length, $change['roots'], 'Precondition: every page of the chain is a root.' );
		foreach ( $keep as $id ) {
			wp_delete_post( $id, true );
		}
		wp_cache_flush();

		$queries   = get_num_queries();
		$collected = $this->pairs->collect_change( $change );
		$cost      = get_num_queries() - $queries;
		$old       = '/company/' . implode( '/', array_map( static fn( int $i ): string => $prefix . '-' . $i, range( 1, $length ) ) ) . '/' . $prefix . '-kid/';
		$this->assertSame( [ $old . ' -> /company/' . $prefix . '-kid/' ], $this->lines( $collected ) );
		$this->assertSame( [ $kid ], array_column( $collected['pairs'], 'id' ) );
		$this->assertFalse( $collected['gone'], 'No root deleted for good had a redirect to list.' );
		$this->assertFalse( $collected['capped'] );
		$this->assert_the_resolver_agrees( $collected );
		return $cost;
	}

	/**
	 * Where a logged-out request for an old path is redirected, by the Server
	 * on a real main query; an empty string when it is not.
	 *
	 * @param string $path Normalized path.
	 * @return string
	 */
	private function served_on_request( string $path ): string {
		$target = $this->target_on_request( $path );
		return null === $target ? '' : Paths::relative( $target['url'] );
	}

	/**
	 * The Server's redirect for a logged-out request for an old path, on a real main query.
	 *
	 * @param string $path Normalized path.
	 * @return array{url:string,status:int}|null
	 */
	private function target_on_request( string $path ): ?array {
		$user = get_current_user_id();
		update_option( Settings::OPTION, [ 'serve_redirects' => true ] );
		wp_set_current_user( 0 );
		$this->go_to( Paths::url( 'page', $path ) );
		$target = ( new Server( new Resolver( new Ledger() ) ) )->target_for_current_request();
		wp_set_current_user( $user );
		delete_option( Settings::OPTION );
		return $target;
	}

	/**
	 * Each listed pair with the status the CSV gives it, and the status a
	 * request for its old URL gets from the Server.
	 *
	 * @param array<string,mixed> $collected Collected pairs.
	 * @return array<string,array{0:int,1:int}> CSV status and served status, by "old -> new" line.
	 */
	private function statuses( array $collected ): array {
		$out = [];
		foreach ( $this->pairs->all( $collected ) as $row ) {
			$target                                    = $this->target_on_request( Paths::normalize( $row['old'] ) );
			$out[ $row['old'] . ' -> ' . $row['new'] ] = [ $row['temporary'] ? 302 : 301, null === $target ? 0 : $target['status'] ];
		}
		return $out;
	}

	/**
	 * Switches the permalink structure, with core's category and tag rules for
	 * it, as a site has them from init on: the test bootstrap registered the
	 * taxonomies under plain permalinks, which gives them no rewrite rules.
	 *
	 * @param string $structure Permalink structure.
	 */
	private function use_permalinks( string $structure ): void {
		global $wp_rewrite;
		$this->set_permalink_structure( $structure );
		create_initial_taxonomies();
		$wp_rewrite->flush_rules();
	}

	public function test_under_postname_permalinks_a_404_is_told_as_before(): void {
		$this->use_permalinks( '/%postname%/' );
		$news     = $this->page( 'news' );
		$services = $this->page( 'services', $this->ids['company'] );
		$renames  = [
			$news                 => 'updates',
			$services             => 'offers',
			$this->ids['company'] => 'company-new',
		];
		foreach ( $renames as $id => $slug ) {
			wp_update_post(
				[
					'ID'        => $id,
					'post_name' => $slug,
				]
			);
		}
		// The category archive lives under /category/; a blog post at one segment; an attachment at the last of two.
		self::factory()->category->create(
			[
				'slug' => 'news',
				'name' => 'News',
			]
		);
		self::factory()->post->create(
			[
				'post_name'   => 'company',
				'post_status' => 'publish',
			]
		);
		self::factory()->post->create(
			[
				'post_name'   => 'history',
				'post_status' => 'publish',
			]
		);
		self::factory()->attachment->create(
			[
				'post_name'      => 'services',
				'post_title'     => 'services',
				'post_parent'    => $news,
				'post_mime_type' => 'image/jpeg',
				'file'           => 'services.jpg',
			]
		);
		$facts = [];

		$served = Batch_Resolver::served( [ 'news', 'company', 'company/services', 'company/about', 'company/about/history' ], 'page', $facts );
		ksort( $served );

		$this->assertSame(
			[
				'company'               => Batch_Resolver::NOT_404,
				'company/about'         => $this->ids['about'],
				'company/about/history' => $this->ids['history'],
				'company/services'      => Batch_Resolver::NOT_404,
				'news'                  => $news,
			],
			$served
		);
		$this->assertSame( '', $this->served_on_request( 'company' ) );
		$this->assertSame( '/company-new/about/', $this->served_on_request( 'company/about' ) );
		$this->assertSame( '/company-new/about/history/', $this->served_on_request( 'company/about/history' ) );
		$this->assertSame( '', $this->served_on_request( 'company/services' ) );
		$this->assertSame( '/updates/', $this->served_on_request( 'news' ) );
	}

	public function test_a_save_change_has_one_block_per_page_it_saved(): void {
		$change = $this->rename( $this->ids['about'], 'about-us' );

		$this->assertSame(
			[
				[
					'old'      => 'company/about',
					'head'     => $this->ids['about'],
					'reason'   => '',
					'base'     => 'company/about-us',
					'from'     => $this->ids['about'],
					'children' => [],
					'target'   => $this->ids['about'],
				],
			],
			Pairs_Blocks::change_blocks( $change ),
			'The page at its old path, its subtree walked at its current slugs.'
		);

		wp_delete_post( $this->ids['about'], true );
		$gone = false;
		$this->assertSame( [], Pairs_Blocks::change_blocks( $change, $gone ), 'A page deleted since has no URL to list.' );
		$this->assertTrue( $gone, 'The list is gone with it.' );
	}

	public function test_a_rename_lists_the_page_and_its_subpages_at_the_urls_they_had(): void {
		$collected = $this->pairs->collect_change( $this->rename( $this->ids['about'], 'about-us' ) );

		$this->assertSame(
			[
				'/company/about/ -> /company/about-us/',
				'/company/about/history/ -> /company/about-us/history/',
				'/company/about/team/ -> /company/about-us/team/',
				'/company/about/team/leadership/ -> /company/about-us/team/leadership/',
			],
			$this->lines( $collected ),
			'Depth first, in menu order then title.'
		);
		$this->assertSame( [], $collected['unresolved'] );
		$this->assert_the_resolver_agrees( $collected );
	}

	public function test_a_move_lists_the_page_and_its_subpages_at_the_urls_they_had(): void {
		$collected = $this->pairs->collect_change( $this->save( $this->ids['team'], [ 'post_parent' => $this->ids['company'] ] ) );

		$this->assertSame(
			[
				'/company/about/team/ -> /company/team/',
				'/company/about/team/leadership/ -> /company/team/leadership/',
			],
			$this->lines( $collected )
		);
		$this->assert_the_resolver_agrees( $collected );
	}

	public function test_saves_in_one_request_list_every_page_they_moved(): void {
		Change_Log::reset_request();
		// One request, as a bulk edit makes it.
		foreach ( [ 'team', 'history' ] as $page ) {
			wp_update_post(
				[
					'ID'          => $this->ids[ $page ],
					'post_parent' => $this->ids['company'],
				]
			);
		}
		$collected = $this->last_change();

		$this->assertSame(
			[
				'/company/about/team/ -> /company/team/',
				'/company/about/team/leadership/ -> /company/team/leadership/',
				'/company/about/history/ -> /company/history/',
			],
			$this->lines( $collected )
		);
		$this->assert_the_resolver_agrees( $collected );
	}

	public function test_a_bulk_edit_of_a_parent_and_its_subpage_lists_their_urls_from_before_it(): void {
		Change_Log::reset_request();

		// The Pages list shows about above team, so core saves about first.
		$this->bulk_edit( [ $this->ids['about'], $this->ids['team'] ], 0 );
		$collected = $this->last_change();

		$this->assertSame(
			[
				'/company/about/ -> /about/',
				'/company/about/history/ -> /about/history/',
				'/company/about/team/ -> /team/',
				'/company/about/team/leadership/ -> /team/leadership/',
			],
			$this->lines( $collected ),
			'No URL that lasted only inside the request, and none of the URLs before it missing.'
		);
		$this->assert_the_resolver_agrees( $collected );
	}

	public function test_a_pair_is_temporary_where_the_server_answers_302(): void {
		$this->assertSame(
			[
				'/company/about/ -> /company/about-us/' => [ 301, 301 ],
				'/company/about/history/ -> /company/about-us/history/' => [ 301, 301 ],
				'/company/about/team/ -> /company/about-us/team/' => [ 301, 301 ],
				'/company/about/team/leadership/ -> /company/about-us/team/leadership/' => [ 301, 301 ],
			],
			$this->statuses( $this->pairs->collect_change( $this->rename( $this->ids['about'], 'about-us' ) ) ),
			'A rename nothing can undo.'
		);

		// The trash moved team up; restoring about would move it back.
		Change_Log::reset_request();
		wp_trash_post( $this->ids['about'] );
		$this->assertSame(
			[
				'/company/team/ -> /company/crew/' => [ 302, 302 ],
				'/company/team/leadership/ -> /company/crew/leadership/' => [ 302, 302 ],
			],
			$this->statuses( $this->pairs->collect_change( $this->rename( $this->ids['team'], 'crew' ) ) ),
			'A target the trash moved.'
		);
	}

	public function test_a_pair_through_an_old_path_a_page_in_the_trash_holds_is_temporary(): void {
		// An earlier page left /promo/ and went to the trash, keeping its claim on it.
		$earlier = $this->page( 'promo' );
		$this->rename( $earlier, 'promo-old' );
		wp_trash_post( $earlier );
		$promo = $this->page( 'promo' );
		$this->page( 'kid', $promo );

		$collected = $this->pairs->collect_change( $this->rename( $promo, 'sale' ) );

		$this->assertSame(
			[
				'/promo/ -> /sale/'         => [ 302, 302 ],
				'/promo/kid/ -> /sale/kid/' => [ 302, 302 ],
			],
			$this->statuses( $collected ),
			'The newer page held /promo/ since, but a restore of the earlier page would take it back.'
		);
		$this->assertSame( [], $this->pairs->page( $collected, 1, 20 )['unresolved'] );
	}

	public function test_a_save_lists_no_draft_subpage_but_its_published_subpages(): void {
		wp_update_post(
			[
				'ID'          => $this->ids['team'],
				'post_status' => 'draft',
			]
		);

		$this->assertSame(
			[
				'/company/about/ -> /company/about-us/',
				'/company/about/history/ -> /company/about-us/history/',
				'/company/about/team/leadership/ -> /company/about-us/team/leadership/',
			],
			$this->lines( $this->pairs->collect_change( $this->rename( $this->ids['about'], 'about-us' ) ) )
		);
	}

	public function test_a_saved_page_that_is_not_published_now_is_listed_as_unresolved(): void {
		$change = $this->save(
			$this->ids['about'],
			[
				'post_name'   => 'about-us',
				'post_status' => 'draft',
			]
		);

		$page = $this->pairs->page( $this->pairs->collect_change( $change ), 1, 100 );

		$this->assertSame(
			[
				[
					'old'    => '/company/about/',
					'reason' => 'unpublished',
				],
			],
			$page['unresolved']
		);
		$this->assertSame( 3, $page['total'], 'Its published subpages still redirect.' );
	}

	public function test_a_save_s_pairs_are_paged(): void {
		$collected = $this->pairs->collect_change( $this->rename( $this->ids['about'], 'about-us' ) );

		$page = $this->pairs->page( $collected, 2, 3 );

		$this->assertSame( 4, $page['total'] );
		$this->assertSame( 2, $page['pages'] );
		$this->assertSame( 2, $page['page'] );
		$this->assertSame( [ '/company/about/team/leadership/' ], array_column( $page['pairs'], 'old' ) );
		$this->assertSame( 2, $this->pairs->page( $collected, 9, 3 )['page'], 'A page past the end shows the last one.' );
		$this->assertCount( 4, $this->pairs->all( $collected ), 'A download has every pair.' );
	}

	public function test_a_save_s_walk_stops_at_the_count_cap(): void {
		$change = $this->rename( $this->ids['about'], 'about-us' );
		add_filter( 'hierarchy_guard_count_cap', static fn(): int => 2 );

		$this->assertSame(
			[
				'count'  => 2,
				'capped' => true,
			],
			$this->pairs->count( $this->pairs->collect_change( $change ) )
		);
	}

	public function test_a_save_s_walk_costs_two_queries_per_level_and_is_then_cached(): void {
		$change  = $this->rename( $this->ids['about'], 'about-us' );
		$queries = get_num_queries();

		$this->pairs->collect_change( $change );

		$this->assertLessThanOrEqual( 10, get_num_queries() - $queries, 'One path lookup for the old path, an index read and a sort per level (about; team and history; leadership), the ledger for the paths listed and their prefixes, the lookups of the resolver’s check of every pair at once, and which pages listed a trash moved.' );

		$queries = get_num_queries();
		$this->pairs->collect_change( $change );
		$this->assertSame( 0, get_num_queries() - $queries, 'The collected pairs are cached until a post changes.' );
	}

	public function test_without_pretty_permalinks_a_save_lists_nothing(): void {
		$change = $this->rename( $this->ids['about'], 'about-us' );
		$this->assertCount( 4, $this->pairs->collect_change( $change )['pairs'] );

		$this->set_permalink_structure( '' );
		$collected = $this->pairs->collect_change( $change );

		$this->assertSame( [], $collected['pairs'], 'No URL ever held the path, and a permalink change is not served from the cache.' );
		$this->assertSame( [], $collected['unresolved'] );
		$this->assertSame( [], $this->pairs->all( $collected ) );
	}

	public function test_an_old_url_a_live_page_uses_now_is_not_redirected(): void {
		$change = $this->rename( $this->ids['about'], 'about-us' );
		$about  = $this->page( 'about', $this->ids['company'] );
		$this->page( 'team', $about );

		$collected = $this->pairs->collect_change( $change );

		$this->assertSame(
			[],
			$this->lines( $collected ),
			'The new page took /company/about/: the URL is its now, so the old page\'s subpages are not reached through it.'
		);
		$this->assertSame(
			[
				[
					'old'    => '/company/about/',
					'reason' => 'in_use',
				],
			],
			$this->pairs->page( $collected, 1, 100 )['unresolved']
		);
		$this->assert_the_resolver_agrees( $collected );
	}

	public function test_a_save_back_to_the_current_path_lists_nothing(): void {
		$first = $this->rename( $this->ids['about'], 'about-us' );
		$this->rename( $this->ids['about'], 'about' );

		$this->assertSame( [], $this->pairs->collect_change( $first )['pairs'], 'The old path is the page’s URL again.' );
	}

	public function test_a_saved_page_in_the_trash_since_lists_nothing(): void {
		$change = $this->rename( $this->ids['about'], 'about-us' );
		$this->trash_raw( $this->ids['about'] );

		$this->assertSame( [], $this->pairs->collect_change( $change )['pairs'], 'Its subpages now sit under a __trashed URL.' );
	}

	public function test_a_save_lists_a_subpage_renamed_before_it_at_the_url_it_had_right_before_the_save(): void {
		$this->rename( $this->ids['team'], 'crew' );
		$collected = $this->pairs->collect_change( $this->rename( $this->ids['about'], 'about-us' ) );

		$this->assertContains( '/company/about/crew/ -> /company/about-us/crew/', $this->lines( $collected ), 'The URL crew had until about moved.' );
		$this->assertNotContains( '/company/about/team/ -> /company/about-us/crew/', $this->lines( $collected ), 'That URL belongs to crew’s own, earlier change.' );
		$this->assert_the_resolver_agrees( $collected );
	}

	public function test_a_subpage_moved_and_renamed_in_one_save_is_listed_at_the_url_it_had(): void {
		$this->rename( $this->ids['about'], 'about-us' );
		// One save, as Quick Edit makes it: a new parent and a new slug.
		$change = $this->save(
			$this->ids['team'],
			[
				'post_parent' => $this->ids['history'],
				'post_name'   => 'crew',
			]
		);

		$collected = $this->pairs->collect_change( $change );

		$this->assertSame(
			[
				'/company/about-us/team/ -> /company/about-us/history/crew/',
				'/company/about-us/team/leadership/ -> /company/about-us/history/crew/leadership/',
			],
			$this->lines( $collected )
		);
		$this->assertSame( '/company/about-us/history/crew/', $this->served_on_request( 'company/about-us/team' ) );
		$this->assert_the_resolver_agrees( $collected );
	}

	public function test_collecting_a_save_costs_the_same_few_queries_for_fifty_subpages(): void {
		$five  = $this->queries_to_collect_save( 'services', 5 );
		$fifty = $this->queries_to_collect_save( 'products', 50 );

		$this->assertSame( $five, $fifty, 'Lookups are batched for every block and page, never one per subpage.' );
		$this->assertLessThanOrEqual( 12, $fifty );
	}

	public function test_collecting_a_save_under_a_hidden_page_costs_the_same_few_queries_for_fifty_subpages(): void {
		$five  = $this->queries_to_collect_save( 'services', 5, true );
		$fifty = $this->queries_to_collect_save( 'products', 50, true );

		$this->assertSame( $five, $fifty, 'The pages at the old subpage paths are looked up at once, never one per subpage.' );
	}

	/**
	 * Queries a cold collect of a rename costs, for a page with subpages.
	 *
	 * @param string $slug     The page's old slug.
	 * @param int    $subpages How many subpages it has.
	 * @param bool   $hidden   Whether a private page takes the old slug, so the page there is hidden.
	 * @return int
	 */
	private function queries_to_collect_save( string $slug, int $subpages, bool $hidden = false ): int {
		$parent = $this->page( $slug, $this->ids['company'] );
		for ( $i = 1; $i <= $subpages; $i++ ) {
			$this->page( $slug . '-' . $i, $parent );
		}
		$change = $this->rename( $parent, $slug . '-now' );
		if ( $hidden ) {
			$this->page( $slug, $this->ids['company'], 'private' );
		}
		wp_cache_flush();

		$queries   = get_num_queries();
		$collected = $this->pairs->collect_change( $change );
		$this->assertCount( $subpages + 1, $collected['pairs'] );
		return get_num_queries() - $queries;
	}

	public function test_with_two_pages_at_one_path_the_pairs_follow_the_one_core_finds(): void {
		$services = $this->page( 'services', $this->ids['company'] );
		// Core leaves a draft's slug as it is, so the draft sits at the same path.
		$this->page( 'services', $this->ids['company'], 'draft' );

		$collected = $this->pairs->collect_change( $this->rename( $this->ids['company'], 'company-new' ) );

		$this->assertSame( 'company-new/services', Paths::uri( get_post( $services ) ) );
		$this->assertNotContains( '/company/services/ -> /company-new/services/', $this->lines( $collected ), 'get_page_by_path() finds the draft there, the later of the two, which nobody can view.' );
		$this->assertContains( '/company/about/ -> /company-new/about/', $this->lines( $collected ) );
		$this->assertSame( '', $this->served_on_request( 'company/services' ) );
		$this->assert_the_resolver_agrees( $collected );
	}

	public function test_an_old_url_a_blog_post_uses_now_is_not_listed(): void {
		$news   = $this->page( 'news' );
		$change = $this->rename( $news, 'updates' );
		self::factory()->post->create(
			[
				'post_type'   => 'post',
				'post_name'   => 'news',
				'post_status' => 'publish',
			]
		);

		$collected = $this->pairs->collect_change( $change );

		$this->assertSame( [], $this->lines( $collected ), 'WordPress shows the blog post at /news/.' );
		$this->assertSame(
			[
				[
					'old'    => '/news/',
					'reason' => 'in_use',
				],
			],
			$this->pairs->page( $collected, 1, 100 )['unresolved']
		);
		$this->assertSame( '', $this->served_on_request( 'news' ) );
	}

	public function test_an_old_url_an_attachment_uses_now_is_not_listed(): void {
		$services = $this->page( 'services', $this->ids['company'] );
		$gallery  = $this->page( 'gallery' );
		$change   = $this->rename( $services, 'offers' );
		// WordPress serves /anything/services/ as this attachment, wherever it is attached.
		self::factory()->attachment->create(
			[
				'post_name'      => 'services',
				'post_title'     => 'services',
				'post_parent'    => $gallery,
				'post_mime_type' => 'image/jpeg',
				'file'           => 'services.jpg',
			]
		);

		$this->assertSame( [], $this->lines( $this->pairs->collect_change( $change ) ) );
		$this->assertSame( '', $this->served_on_request( 'company/services' ) );
	}

	public function test_an_old_url_deeper_than_the_server_s_segment_cap_is_not_listed(): void {
		add_filter( 'hierarchy_guard_max_segments', static fn(): int => 3 );

		$lines = $this->lines( $this->pairs->collect_change( $this->rename( $this->ids['company'], 'company-new' ) ) );

		$this->assertContains( '/company/about/team/ -> /company-new/about/team/', $lines );
		$this->assertNotContains( '/company/about/team/leadership/ -> /company-new/about/team/leadership/', $lines, 'The server refuses a request of four segments.' );
		$this->assertSame( '', $this->served_on_request( 'company/about/team/leadership' ) );
	}

	public function test_an_old_url_whose_newer_holder_nobody_can_view_leads_nowhere(): void {
		$change = $this->rename( $this->ids['about'], 'about-us' );
		$other  = $this->page( 'about', $this->ids['company'] );
		$this->rename( $other, 'about-y' );
		wp_update_post(
			[
				'ID'          => $other,
				'post_status' => 'draft',
			]
		);

		$collected = $this->pairs->collect_change( $change );

		$this->assertSame( [], $this->lines( $collected ) );
		$this->assertSame(
			[
				[
					'old'    => '/company/about/',
					'reason' => 'none',
				],
			],
			$this->pairs->page( $collected, 1, 100 )['unresolved'],
			'The resolver follows the draft, which nobody can view, so the URL leads nowhere.'
		);
		$this->assertSame( '', $this->served_on_request( 'company/about' ) );
	}

	public function test_under_category_permalinks_an_old_url_a_category_uses_now_is_not_listed(): void {
		$this->use_permalinks( '/%category%/%postname%/' );
		$news   = $this->page( 'news' );
		$change = $this->rename( $news, 'updates' );
		self::factory()->category->create(
			[
				'slug' => 'news',
				'name' => 'News',
			]
		);

		$collected = $this->pairs->collect_change( $change );

		$this->assertSame( [], $this->lines( $collected ), 'WordPress shows the category archive at /news/, with no posts in it too.' );
		$this->assertSame(
			[
				[
					'old'    => '/news/',
					'reason' => 'in_use',
				],
			],
			$this->pairs->page( $collected, 1, 100 )['unresolved']
		);
		$this->assertSame( '', $this->served_on_request( 'news' ) );
	}

	public function test_under_category_permalinks_an_old_url_a_blog_post_uses_now_is_not_listed(): void {
		$this->use_permalinks( '/%category%/%postname%/' );
		$press = $this->page( 'press' );
		$this->page( 'release', $press );
		$this->page( 'archive', $press );
		$change   = $this->rename( $press, 'media' );
		$category = self::factory()->category->create(
			[
				'slug' => 'press',
				'name' => 'Press',
			]
		);
		$post     = self::factory()->post->create(
			[
				'post_name'     => 'release',
				'post_status'   => 'publish',
				'post_category' => [ $category ],
			]
		);

		$lines = $this->lines( $this->pairs->collect_change( $change ) );

		$this->assertSame( '/press/release/', Paths::relative( (string) get_permalink( $post ) ), 'The old URL of the page is the blog post\'s.' );
		$this->assertSame( [ '/press/archive/ -> /media/archive/' ], $lines, 'The category is shown at /press/ and the blog post at /press/release/.' );
		$this->assertSame( '', $this->served_on_request( 'press' ) );
		$this->assertSame( '', $this->served_on_request( 'press/release' ) );
		$this->assertSame( '/media/archive/', $this->served_on_request( 'press/archive' ) );
	}

	public function test_under_a_blog_front_an_old_url_a_blog_post_uses_now_is_not_listed(): void {
		$this->use_permalinks( '/blog/%postname%/' );
		$blog = $this->page( 'blog' );
		$this->page( 'foo', $blog );
		$this->page( 'bar', $blog );
		$change = $this->rename( $blog, 'journal' );
		$post   = self::factory()->post->create(
			[
				'post_name'   => 'foo',
				'post_status' => 'publish',
			]
		);

		$lines = $this->lines( $this->pairs->collect_change( $change ) );

		$this->assertSame( '/blog/foo/', Paths::relative( (string) get_permalink( $post ) ), 'The old URL of the page is the blog post\'s.' );
		$this->assertSame( [ '/blog/ -> /journal/', '/blog/bar/ -> /journal/bar/' ], $lines );
		$this->assertSame( '', $this->served_on_request( 'blog/foo' ) );
		$this->assertSame( '/journal/bar/', $this->served_on_request( 'blog/bar' ) );
	}

	public function test_under_a_blog_front_an_old_url_a_category_uses_now_is_not_listed(): void {
		$this->use_permalinks( '/blog/%postname%/' );
		$blog = $this->page( 'blog' );
		$this->page( 'news', $this->page( 'category', $blog ) );
		$change = $this->rename( $blog, 'journal' );
		$term   = self::factory()->category->create(
			[
				'slug' => 'news',
				'name' => 'News',
			]
		);
		$news   = $this->page( 'news' );

		$lines = $this->lines( $this->pairs->collect_change( $change ) );

		$this->assertSame( '/blog/category/news/', Paths::relative( (string) get_term_link( $term ) ), 'The old URL of the page is the category\'s.' );
		$this->assertSame( [ '/blog/ -> /journal/', '/blog/category/ -> /journal/category/' ], $lines );
		$this->assertSame( '', $this->served_on_request( 'blog/category/news' ) );
		$this->assertSame( [ '/news/ -> /updates/' ], $this->lines( $this->pairs->collect_change( $this->rename( $news, 'updates' ) ) ), 'Under this front the categories live under /blog/category/.' );
		$this->assertSame( '/updates/', $this->served_on_request( 'news' ) );
	}

	/**
	 * A hierarchical type at /docs/, its doc Guide renamed Handbook, and a page
	 * Docs with a subpage Guide, Docs renamed Documentation in the change returned.
	 *
	 * @param bool $flush Whether the site's rewrite rules have the type's yet.
	 * @return array<string,mixed> The change that renamed Docs.
	 */
	private function docs_beside_pages( bool $flush ): array {
		global $wp_rewrite;
		register_post_type(
			'hg_docs',
			[
				'public'       => true,
				'hierarchical' => true,
				'rewrite'      => [ 'slug' => 'docs' ],
			]
		);
		Post_Types::reset();
		if ( $flush ) {
			$wp_rewrite->flush_rules();
		}
		$doc = self::factory()->post->create(
			[
				'post_type' => 'hg_docs',
				'post_name' => 'guide',
			]
		);
		wp_update_post(
			[
				'ID'        => $doc,
				'post_name' => 'handbook',
			]
		);
		$docs = $this->page( 'docs' );
		$this->page( 'guide', $docs );
		return $this->rename( $docs, 'documentation' );
	}

	public function test_an_old_url_the_server_sends_to_a_type_with_that_prefix_first_is_not_listed(): void {
		$lines = $this->lines( $this->pairs->collect_change( $this->docs_beside_pages( false ) ) );

		$this->assertSame( [ '/docs/ -> /documentation/' ], $lines );
		$this->assertSame( '/docs/handbook/', $this->served_on_request( 'docs/guide' ), 'With no rule for the type yet the request is a 404, and the server tries the type whose prefix starts the path first.' );
	}

	public function test_an_old_url_a_type_s_rewrite_rule_takes_is_not_listed(): void {
		$lines = $this->lines( $this->pairs->collect_change( $this->docs_beside_pages( true ) ) );

		$this->assertSame( [ '/docs/ -> /documentation/' ], $lines, 'WordPress hands /docs/guide/ to the type\'s rule, which finds no doc there; the server then tries the type first, and its record answers.' );
		$this->assertSame( '/docs/handbook/', $this->served_on_request( 'docs/guide' ) );
	}

	public function test_past_the_fallbacks_a_path_two_pages_share_leads_to_no_pair(): void {
		$company = $this->ids['company'];
		for ( $i = 1; $i <= Batch_Resolver::FALLBACKS; $i++ ) {
			$this->page( 'a' . $i, $company );
			// A draft keeps its slug: two pages at one path, and core's own lookup says which it finds.
			$this->page( 'a' . $i, $company, 'draft' );
		}
		$draft = $this->page( 'kid', 0, 'draft' );
		$kid   = $this->page( 'kid', $company );
		$this->rename( $kid, 'kid-moved' );
		$this->page( 'kid', $company );
		// company/kid then has two pages too: the draft, then the published one.
		wp_update_post(
			[
				'ID'          => $draft,
				'post_parent' => $company,
			]
		);
		$change = $this->rename( $company, 'company-new' );

		$collected = $this->pairs->collect_change( $change );

		$this->assertNotContains( '/company/kid/', array_map( static fn( string $line ): string => strtok( $line, ' ' ), $this->lines( $collected ) ), 'Core was asked about twenty paths before this one, so which page it finds there is not known.' );
		$this->assertContains( '/company/about/ -> /company-new/about/', $this->lines( $collected ) );
		$this->assert_the_resolver_agrees( $collected );
	}

	public function test_a_save_s_walk_stops_where_the_server_s_segment_cap_does(): void {
		add_filter( 'hierarchy_guard_max_segments', static fn(): int => 4 );
		$shallow = $this->queries_to_collect_deep( 'shallow', 0 );
		$deep    = $this->queries_to_collect_deep( 'deep', 8 );

		$this->assertSame( $shallow, $deep, 'The pages below four segments can list nothing, so they are not read.' );
	}

	/**
	 * Queries a cold collect costs for a renamed page with About › Team ›
	 * Leadership below it and a chain of pages below that.
	 *
	 * @param string $slug  The page's old slug.
	 * @param int    $below How many pages the chain below Leadership has.
	 * @return int
	 */
	private function queries_to_collect_deep( string $slug, int $below ): int {
		$root   = $this->page( $slug );
		$parent = $this->page( 'leadership', $this->page( 'team', $this->page( 'about', $root ) ) );
		for ( $i = 1; $i <= $below; $i++ ) {
			$parent = $this->page( 'level-' . $i, $parent );
		}
		$change = $this->rename( $root, $slug . '-now' );
		wp_cache_flush();

		$queries   = get_num_queries();
		$collected = $this->pairs->collect_change( $change );
		$cost      = get_num_queries() - $queries;
		$this->assertSame(
			[
				'/' . $slug . '/ -> /' . $slug . '-now/',
				'/' . $slug . '/about/ -> /' . $slug . '-now/about/',
				'/' . $slug . '/about/team/ -> /' . $slug . '-now/about/team/',
				'/' . $slug . '/about/team/leadership/ -> /' . $slug . '-now/about/team/leadership/',
			],
			$this->lines( $collected )
		);
		return $cost;
	}

	public function test_a_record_s_url_target_is_listed_for_its_exact_old_url_as_the_server_sends_it(): void {
		$change = $this->rename( $this->ids['about'], 'about-us' );
		( new Ledger() )->set_target( 'page', 'company/about', $this->ids['about'], 'https://example.org/about/', 0 );

		$collected = $this->pairs->collect_change( $change );

		$this->assertSame( [ '/company/about/ -> https://example.org/about/' ], $this->lines( $collected ), 'Only the exact old URL redirects; the subpages’ old URLs no longer do.' );
		$this->assertSame( [ '/company/about/ -> https://example.org/about/' => [ 301, 301 ] ], $this->statuses( $collected ) );
		$this->assertSame( 'https://example.org/about/', $this->target_on_request( 'company/about' )['url'] );
		$this->assertNull( $this->target_on_request( 'company/about/team' ) );

		( new Ledger() )->set_target( 'page', 'company/about', $this->ids['about'], home_url( '/careers/?from=about#jobs' ), 0 );
		$this->assertSame( [ '/company/about/ -> ' . home_url( '/careers/?from=about#jobs' ) ], $this->lines( $this->pairs->collect_change( $change ) ), 'A URL on this site is listed as it was set, absolute, with its query and #fragment.' );
		$this->assertSame( home_url( '/careers/?from=about#jobs' ), $this->target_on_request( 'company/about' )['url'], 'As the server sends it.' );
	}

	public function test_a_url_target_with_a_chosen_302_is_listed_and_served_as_a_302(): void {
		$change = $this->rename( $this->ids['about'], 'about-us' );
		( new Ledger() )->set_target( 'page', 'company/about', $this->ids['about'], 'https://example.org/about/', 302 );

		$this->assertSame( [ '/company/about/ -> https://example.org/about/' => [ 302, 302 ] ], $this->statuses( $this->pairs->collect_change( $change ) ) );
	}

	public function test_a_root_entry_s_url_target_answers_its_own_old_url_only_in_the_list_as_on_the_server(): void {
		$services = $this->page( 'services' );
		$this->page( 'web', $services );
		wp_trash_post( $services );
		$change = $this->log->latest( $this->admin );
		$this->assertSame( [ '/services/web/ -> /web/' ], $this->lines( $this->pairs->collect_change( $change ) ), 'Precondition: the root entry leads the subpage to the top level.' );

		( new Ledger() )->set_target( 'page', 'services', 0, 'https://example.org/services/', 0 );

		$this->assertSame( [], $this->lines( $this->pairs->collect_change( $change ) ), 'A URL target answers its exact old URL only, so the subpage’s old URL no longer redirects.' );
		$this->assertNull( $this->target_on_request( 'services/web' ), 'The server agrees.' );
		$this->assertSame( 'https://example.org/services/', $this->target_on_request( 'services' )['url'] ?? null );
	}

	public function test_the_csv_lists_a_url_target_absolute_as_the_server_sends_it(): void {
		$change = $this->rename( $this->ids['about'], 'about-us' );
		( new Ledger() )->set_target( 'page', 'company/about', $this->ids['about'], home_url( '/careers/' ), 302 );

		$csv = Csv::build( 'redirection', $this->pairs->all( $this->pairs->collect_change( $change ) ) );

		$this->assertSame( "source,target,regex,code\r\n/company/about/," . home_url( '/careers/' ) . ",0,302\r\n", $csv );
		$this->assertSame( home_url( '/careers/' ), $this->target_on_request( 'company/about' )['url'] );
	}

	public function test_a_chosen_code_is_the_code_listed_and_the_code_served(): void {
		$change = $this->rename( $this->ids['about'], 'about-us' );
		( new Ledger() )->set_target( 'page', 'company/about', $this->ids['about'], '', 302 );

		$this->assertSame(
			[
				'/company/about/ -> /company/about-us/' => [ 302, 302 ],
				'/company/about/history/ -> /company/about-us/history/' => [ 302, 302 ],
				'/company/about/team/ -> /company/about-us/team/' => [ 302, 302 ],
				'/company/about/team/leadership/ -> /company/about-us/team/leadership/' => [ 302, 302 ],
			],
			$this->statuses( $this->pairs->collect_change( $change ) )
		);
	}

	public function test_an_edit_to_a_root_entry_shows_in_a_list_already_cached(): void {
		$services = $this->page( 'services' );
		$this->page( 'web', $services );
		wp_trash_post( $services );
		$change = $this->log->latest( $this->admin );
		$this->assertSame( [ '/services/web/ -> /web/' => [ 302, 302 ] ], $this->statuses( $this->pairs->collect_change( $change ) ), 'Precondition: a restore could undo it, and the list is cached now.' );

		( new Ledger() )->set_target( 'page', 'services', 0, '', 301 );

		$this->assertSame( [ '/services/web/ -> /web/' => [ 301, 301 ] ], $this->statuses( $this->pairs->collect_change( $change ) ), 'A root-list edit writes no post, so the cache key has the ledger’s.' );
	}
}
