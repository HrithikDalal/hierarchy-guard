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
use HierarchyGuard\Ledger;
use HierarchyGuard\Post_Types;
use HierarchyGuard\Redirects\Pairs;
use HierarchyGuard\Redirects\Providers;
use HierarchyGuard\Redirects\Resolver;
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
	 * Old and new URLs of the pairs behind an old path on about.
	 *
	 * @param string $old_path Old path.
	 * @return array<int,string> "old -> new" lines.
	 */
	private function about_lines( string $old_path = 'company/about-us' ): array {
		return $this->lines( $this->pairs->collect_post( get_post( $this->ids['about'] ), $old_path ) );
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

	public function test_an_old_path_on_a_page_leads_to_the_page_and_its_subpages(): void {
		$this->assertSame(
			[
				'/company/about-us/ -> /company/about/',
				'/company/about-us/history/ -> /company/about/history/',
				'/company/about-us/team/ -> /company/about/team/',
				'/company/about-us/team/leadership/ -> /company/about/team/leadership/',
			],
			$this->about_lines(),
			'Depth first, in menu order then title.'
		);
	}

	public function test_a_draft_subpage_has_no_pair_but_its_published_subpages_do(): void {
		wp_update_post(
			[
				'ID'          => $this->ids['team'],
				'post_status' => 'draft',
			]
		);

		$this->assertSame(
			[
				'/company/about-us/ -> /company/about/',
				'/company/about-us/history/ -> /company/about/history/',
				'/company/about-us/team/leadership/ -> /company/about/team/leadership/',
			],
			$this->about_lines()
		);
	}

	public function test_a_head_that_is_not_published_is_listed_as_unresolved(): void {
		wp_update_post(
			[
				'ID'          => $this->ids['about'],
				'post_status' => 'draft',
			]
		);

		$page = $this->pairs->page( $this->pairs->collect_post( get_post( $this->ids['about'] ), 'company/about-us' ), 1, 100 );

		$this->assertSame(
			[
				[
					'old'    => '/company/about-us/',
					'reason' => 'unpublished',
				],
			],
			$page['unresolved']
		);
		$this->assertSame( 3, $page['total'] );
	}

	public function test_pairs_are_paged(): void {
		$collected = $this->pairs->collect_post( get_post( $this->ids['about'] ), 'company/about-us' );

		$page = $this->pairs->page( $collected, 2, 3 );

		$this->assertSame( 4, $page['total'] );
		$this->assertSame( 2, $page['pages'] );
		$this->assertSame( 2, $page['page'] );
		$this->assertSame( [ '/company/about-us/team/leadership/' ], array_column( $page['pairs'], 'old' ) );
		$this->assertSame( 2, $this->pairs->page( $collected, 9, 3 )['page'], 'A page past the end shows the last one.' );
	}

	public function test_the_walk_stops_at_the_count_cap(): void {
		add_filter( 'hierarchy_guard_count_cap', static fn(): int => 2 );

		$count = $this->pairs->count( $this->pairs->collect_post( get_post( $this->ids['about'] ), 'company/about-us' ) );

		$this->assertSame(
			[
				'count'  => 2,
				'capped' => true,
			],
			$count
		);
	}

	public function test_the_walk_costs_two_queries_per_level_and_is_then_cached(): void {
		$post    = get_post( $this->ids['about'] );
		$queries = get_num_queries();

		$this->pairs->collect_post( $post, 'company/about-us' );

		$this->assertLessThanOrEqual( 9, get_num_queries() - $queries, 'A trash-holder check and one path lookup, an index read and a sort per level (about; team and history; leadership), then the old paths of the pages walked and the holders of the paths listed.' );

		$queries = get_num_queries();
		$this->pairs->collect_post( $post, 'company/about-us' );
		$this->assertSame( 0, get_num_queries() - $queries, 'The collected pairs are cached until a post changes.' );
	}

	public function test_without_pretty_permalinks_nothing_is_listed(): void {
		$this->set_permalink_structure( '' );

		$collected = $this->pairs->collect_post( get_post( $this->ids['about'] ), 'company/about-us' );

		$this->assertSame( [], $collected['pairs'], 'No URL ever held the path.' );
		$this->assertSame( [], $collected['unresolved'] );
		$this->assertSame( [], $this->pairs->all( $collected ) );
	}

	public function test_an_old_path_a_live_page_uses_now_is_not_redirected(): void {
		wp_update_post(
			[
				'ID'        => $this->ids['about'],
				'post_name' => 'about-us',
			]
		);
		$this->page( 'about', $this->ids['company'] );

		$page = $this->pairs->page( $this->pairs->collect_post( get_post( $this->ids['about'] ), 'company/about' ), 1, 100 );

		$this->assertSame( 0, $page['total'], 'A redirect plugin would hide the new page and its subpages.' );
		$this->assertSame(
			[
				[
					'old'    => '/company/about/',
					'reason' => 'in_use',
				],
			],
			$page['unresolved']
		);
	}

	public function test_an_old_path_that_is_the_current_path_has_no_pairs(): void {
		$this->assertSame( [], $this->pairs->collect_post( get_post( $this->ids['about'] ), 'company/about' )['pairs'] );
	}

	public function test_a_page_in_the_trash_lists_no_pairs_under_it(): void {
		$this->trash_raw( $this->ids['about'] );

		$this->assertSame( [], $this->pairs->collect_post( get_post( $this->ids['about'] ), 'company/about-us' )['pairs'], 'Its subpages now sit under a __trashed URL.' );
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
	}

	public function test_the_parent_of_a_trashed_page_has_no_new_url_for_the_trashed_pages_own_path(): void {
		wp_trash_post( $this->ids['about'] );

		$collected = $this->pairs->collect_post( get_post( $this->ids['company'] ), 'company/about' );

		$this->assertNotContains( '/company/about/ -> /company/', $this->lines( $collected ) );
		$this->assertContains( '/company/about/history/ -> /company/history/', $this->lines( $collected ), 'Its subpages are still served.' );
		$this->assertSame(
			[
				[
					'old'    => '/company/about/',
					'reason' => 'trash',
				],
			],
			$this->pairs->page( $collected, 1, 20 )['unresolved']
		);
	}

	public function test_a_subpage_renamed_on_the_move_is_listed_at_its_old_slug(): void {
		$this->page( 'team', $this->ids['company'] );

		wp_trash_post( $this->ids['about'] );

		$this->assertContains( '/company/about/team/ -> /company/team-2/', $this->lines( $this->last_change() ) );
	}

	public function test_once_the_trash_is_emptied_the_old_path_leads_to_the_new_parent(): void {
		wp_trash_post( $this->ids['about'] );
		$change = $this->log->latest( $this->admin );
		wp_delete_post( $this->ids['about'], true );

		$this->assertSame(
			[
				'/company/about/ -> /company/',
				'/company/about/history/ -> /company/history/',
				'/company/about/team/ -> /company/team/',
				'/company/about/team/leadership/ -> /company/team/leadership/',
			],
			$this->lines( $this->pairs->collect_change( $change ) )
		);
	}

	public function test_after_a_delete_each_root_lists_only_its_own_subpages(): void {
		$sibling = $this->page( 'careers', $this->ids['company'] );
		$this->page( 'jobs', $sibling );
		// One request, one change with two roots.
		wp_trash_post( $this->ids['about'] );
		wp_trash_post( $sibling );
		$change = $this->log->latest( $this->admin );
		wp_delete_post( $this->ids['about'], true );

		$lines = $this->lines( $this->pairs->collect_change( $change ) );

		$this->assertContains( '/company/about/history/ -> /company/history/', $lines );
		$this->assertNotContains( '/company/about/jobs/ -> /company/jobs/', $lines, 'Jobs moved up from Careers, not from About.' );
		$this->assertContains( '/company/careers/jobs/ -> /company/jobs/', $lines );
	}

	public function test_after_a_delete_a_root_that_moved_more_than_it_kept_is_capped(): void {
		wp_trash_post( $this->ids['about'] );
		$change = $this->log->latest( $this->admin );
		$root   = $this->ids['about'];
		// As if the root had moved more subpages than its entry could keep.
		$change['roots'][ $root ]['counts']['moved'] = 500;
		wp_delete_post( $root, true );

		$this->assertTrue( $this->pairs->collect_change( $change )['capped'] );
	}

	public function test_a_restored_page_needs_no_redirects(): void {
		wp_trash_post( $this->ids['about'] );
		$change = $this->log->latest( $this->admin );
		wp_untrash_post( $this->ids['about'] );

		$this->assertSame( [], Pairs::change_blocks( $change ) );
	}

	public function test_only_a_trash_that_moved_subpages_up_has_pairs(): void {
		add_filter( 'hierarchy_guard_trash_policy', static fn(): string => Settings::POLICY_KEEP );
		wp_trash_post( $this->ids['about'] );

		$this->assertSame( [], Pairs::change_blocks( $this->log->latest( $this->admin ) ) );

		$this->assertSame( [], Pairs::change_blocks( [ 'action' => 'restore' ] ) );
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
		Pairs::change_blocks( $change );

		$this->assertLessThanOrEqual( 4, get_num_queries() - $queries, 'Statuses, markers and moved pages in one query each, whatever the number of roots.' );
	}

	public function test_a_download_has_every_pair(): void {
		$this->assertCount( 4, $this->pairs->all( $this->pairs->collect_post( get_post( $this->ids['about'] ), 'company/about-us' ) ) );
	}

	public function test_after_a_delete_a_subpage_renamed_on_the_move_keeps_its_old_slug(): void {
		$this->page( 'team', $this->ids['company'] );
		wp_trash_post( $this->ids['about'] );
		$change = $this->log->latest( $this->admin );
		wp_delete_post( $this->ids['about'], true );

		$lines = $this->lines( $this->pairs->collect_change( $change ) );

		$this->assertContains( '/company/about/team/ -> /company/team-2/', $lines );
		$this->assertContains( '/company/about/team/leadership/ -> /company/team-2/leadership/', $lines );
		foreach ( $lines as $line ) {
			$this->assertStringNotContainsString( 'about/team-2', explode( ' -> ', $line )[0] );
		}
	}

	public function test_an_alias_held_in_the_trash_lists_the_subpages_that_left_it_at_their_old_slugs(): void {
		// Company already has a team, so about's team becomes team-2 on the move.
		$this->page( 'team', $this->ids['company'] );
		wp_trash_post( $this->ids['about'] );

		$collected = $this->pairs->collect_post( get_post( $this->ids['company'] ), 'company/about' );

		$this->assertSame(
			[
				'/company/about/history/ -> /company/history/',
				'/company/about/team/ -> /company/team-2/',
				'/company/about/team/leadership/ -> /company/team-2/leadership/',
			],
			$this->lines( $collected ),
			'Company’s own team never lived under about, and about/team-2 never existed.'
		);
		$this->assert_the_resolver_agrees( $collected );
	}

	public function test_once_the_trash_is_emptied_an_alias_still_lists_each_subpage_at_its_old_slug(): void {
		$this->page( 'team', $this->ids['company'] );
		wp_trash_post( $this->ids['about'] );
		wp_delete_post( $this->ids['about'], true );

		$collected = $this->pairs->collect_post( get_post( $this->ids['company'] ), 'company/about' );

		$this->assertSame(
			[
				'/company/about/ -> /company/',
				'/company/about/history/ -> /company/history/',
				'/company/about/team/ -> /company/team-2/',
				'/company/about/team/leadership/ -> /company/team-2/leadership/',
			],
			$this->lines( $collected ),
			'The resolver serves team-2 for about/team; company’s own team is not listed under the old path.'
		);
		$this->assert_the_resolver_agrees( $collected );
	}

	public function test_a_subpage_renamed_after_its_parent_is_listed_at_the_slug_it_had_under_the_old_path(): void {
		wp_update_post(
			[
				'ID'        => $this->ids['about'],
				'post_name' => 'about-us',
			]
		);
		wp_update_post(
			[
				'ID'        => $this->ids['team'],
				'post_name' => 'crew',
			]
		);

		$collected = $this->pairs->collect_post( get_post( $this->ids['about'] ), 'company/about' );

		$this->assertSame(
			[
				'/company/about/ -> /company/about-us/',
				'/company/about/history/ -> /company/about-us/history/',
				'/company/about/team/ -> /company/about-us/crew/',
				'/company/about/team/leadership/ -> /company/about-us/crew/leadership/',
			],
			$this->lines( $collected ),
			'The real old URLs, not about/crew, which never existed.'
		);
		$this->assert_the_resolver_agrees( $collected );
	}

	public function test_collecting_an_old_path_costs_the_same_few_queries_for_fifty_subpages(): void {
		$five  = $this->queries_to_collect( 'services', 5 );
		$fifty = $this->queries_to_collect( 'products', 50 );

		$this->assertSame( $five, $fifty, 'Lookups are batched for every block and page, never one per subpage.' );
		$this->assertLessThanOrEqual( 10, $fifty );
	}

	/**
	 * Queries a cold collect of an old path costs, for a renamed page with subpages.
	 *
	 * @param string $slug     The page's old slug.
	 * @param int    $subpages How many subpages it has.
	 * @return int
	 */
	private function queries_to_collect( string $slug, int $subpages ): int {
		$parent = $this->page( $slug, $this->ids['company'] );
		for ( $i = 1; $i <= $subpages; $i++ ) {
			$this->page( $slug . '-' . $i, $parent );
		}
		wp_update_post(
			[
				'ID'        => $parent,
				'post_name' => $slug . '-now',
			]
		);
		$post = get_post( $parent );
		wp_cache_flush();

		$queries   = get_num_queries();
		$collected = $this->pairs->collect_post( $post, 'company/' . $slug );
		$this->assertCount( $subpages + 1, $collected['pairs'] );
		return get_num_queries() - $queries;
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
			$this->assertContains( '/company/b/a/kid/ -> /company/kid/', $this->lines( $collected ), $order . ', once the child is deleted for good' );
			$this->assert_the_resolver_agrees( $collected );

			foreach ( [ $kid, $b ] as $id ) {
				wp_delete_post( $id, true );
			}
		}
	}

	public function test_an_old_path_whose_target_was_deleted_leads_nowhere(): void {
		wp_trash_post( $this->ids['about'] );
		$change = $this->log->latest( $this->admin );
		wp_delete_post( $this->ids['about'], true );
		wp_delete_post( $this->ids['company'], true );

		$this->assertSame(
			[
				[
					'old'    => '/company/about/',
					'reason' => 'none',
				],
			],
			$this->pairs->page( $this->pairs->collect_change( $change ), 1, 100 )['unresolved'],
			'No page is there to be published.'
		);
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

	public function test_a_permalink_change_is_not_served_from_the_cache(): void {
		$this->assertSame( 4, $this->pairs->count( $this->pairs->collect_post( get_post( $this->ids['about'] ), 'company/about-us' ) )['count'] );

		$this->set_permalink_structure( '' );

		$this->assertSame( 0, $this->pairs->count( $this->pairs->collect_post( get_post( $this->ids['about'] ), 'company/about-us' ) )['count'], 'Without pretty permalinks no URL held the path.' );
	}

	public function test_after_a_delete_a_subpage_renamed_after_the_trash_keeps_its_original_slug(): void {
		wp_trash_post( $this->ids['about'] );
		$change = $this->log->latest( $this->admin );
		wp_update_post(
			[
				'ID'        => $this->ids['history'],
				'post_name' => 'story',
			]
		);
		wp_delete_post( $this->ids['about'], true );

		$lines = $this->lines( $this->pairs->collect_change( $change ) );

		$this->assertContains( '/company/about/history/ -> /company/story/', $lines );
	}
}
