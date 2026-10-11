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
use HierarchyGuard\Paths;
use HierarchyGuard\Post_Types;
use HierarchyGuard\Redirects\Pairs;
use HierarchyGuard\Redirects\Pairs_Blocks;
use HierarchyGuard\Redirects\Providers;
use HierarchyGuard\Redirects\Resolver;
use HierarchyGuard\Redirects\Server;
use HierarchyGuard\Settings;
use HierarchyGuard\Trash\Change_Log;
use WP_Post;
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
		return $this->lines( $this->pairs->collect_post( $this->about_us(), $old_path ) );
	}

	/**
	 * About, with company/about-us recorded as a path it used to have, so the
	 * resolver serves that old path and the pages below it.
	 *
	 * @return WP_Post
	 */
	private function about_us(): WP_Post {
		( new Ledger() )->record( $this->ids['about'], 'company/about-us' );
		return get_post( $this->ids['about'] );
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

		$page = $this->pairs->page( $this->pairs->collect_post( $this->about_us(), 'company/about-us' ), 1, 100 );

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
		$collected = $this->pairs->collect_post( $this->about_us(), 'company/about-us' );

		$page = $this->pairs->page( $collected, 2, 3 );

		$this->assertSame( 4, $page['total'] );
		$this->assertSame( 2, $page['pages'] );
		$this->assertSame( 2, $page['page'] );
		$this->assertSame( [ '/company/about-us/team/leadership/' ], array_column( $page['pairs'], 'old' ) );
		$this->assertSame( 2, $this->pairs->page( $collected, 9, 3 )['page'], 'A page past the end shows the last one.' );
	}

	public function test_the_walk_stops_at_the_count_cap(): void {
		add_filter( 'hierarchy_guard_count_cap', static fn(): int => 2 );

		$count = $this->pairs->count( $this->pairs->collect_post( $this->about_us(), 'company/about-us' ) );

		$this->assertSame(
			[
				'count'  => 2,
				'capped' => true,
			],
			$count
		);
	}

	public function test_the_walk_costs_two_queries_per_level_and_is_then_cached(): void {
		$post    = $this->about_us();
		$queries = get_num_queries();

		$this->pairs->collect_post( $post, 'company/about-us' );

		$this->assertLessThanOrEqual( 11, get_num_queries() - $queries, 'A trash-holder check and one path lookup, an index read and a sort per level (about; team and history; leadership), then the old paths of the pages walked, the ledger for the paths listed and their prefixes, the root list, and one path lookup for the resolver’s check of every pair.' );

		$queries = get_num_queries();
		$this->pairs->collect_post( $post, 'company/about-us' );
		$this->assertSame( 0, get_num_queries() - $queries, 'The collected pairs are cached until a post changes.' );
	}

	public function test_without_pretty_permalinks_nothing_is_listed(): void {
		$this->set_permalink_structure( '' );

		$collected = $this->pairs->collect_post( $this->about_us(), 'company/about-us' );

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
		$about = $this->page( 'about', $this->ids['company'] );
		$this->page( 'team', $about );

		$collected = $this->pairs->collect_post( get_post( $this->ids['about'] ), 'company/about' );
		$page      = $this->pairs->page( $collected, 1, 100 );

		$this->assertSame(
			[
				'/company/about/history/ -> /company/about-us/history/',
				'/company/about/team/leadership/ -> /company/about-us/team/leadership/',
			],
			$this->lines( $collected ),
			'The new page and its team are reached before any redirect; the old URLs no page uses still lead on.'
		);
		$this->assertSame(
			[
				[
					'old'    => '/company/about/',
					'reason' => 'in_use',
				],
			],
			$page['unresolved']
		);
		$this->assert_the_resolver_agrees( $collected );
	}

	public function test_an_old_path_that_is_the_current_path_has_no_pairs(): void {
		$this->assertSame( [], $this->pairs->collect_post( get_post( $this->ids['about'] ), 'company/about' )['pairs'] );
	}

	public function test_a_page_in_the_trash_lists_no_pairs_under_it(): void {
		$this->trash_raw( $this->ids['about'] );

		$this->assertSame( [], $this->pairs->collect_post( $this->about_us(), 'company/about-us' )['pairs'], 'Its subpages now sit under a __trashed URL.' );
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

	public function test_a_download_has_every_pair(): void {
		$this->assertCount( 4, $this->pairs->all( $this->pairs->collect_post( $this->about_us(), 'company/about-us' ) ) );
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

	public function test_once_the_trash_is_emptied_an_alias_never_lists_the_new_parent_s_own_subpage(): void {
		$this->page( 'team', $this->ids['company'] );
		wp_trash_post( $this->ids['about'] );
		wp_delete_post( $this->ids['about'], true );

		$company = $this->pairs->collect_post( get_post( $this->ids['company'] ), 'company/about' );
		$team    = $this->pairs->collect_post( get_post( $this->ids['team'] ), 'company/about/team' );

		$this->assertNotContains( '/company/about/team/ -> /company/team/', $this->lines( $company ), 'Company’s own team never lived under about; team-2 holds that path.' );
		$this->assertContains( '/company/about/history/ -> /company/history/', $this->lines( $company ) );
		$this->assert_the_resolver_agrees( $company );
		$this->assertSame(
			[
				'/company/about/team/ -> /company/team-2/',
				'/company/about/team/leadership/ -> /company/team-2/leadership/',
			],
			$this->lines( $team ),
			'The subpage renamed on the move lists its old URL itself.'
		);
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

	public function test_a_subpage_renamed_twice_after_its_parent_is_listed_at_the_slug_it_had_under_the_old_path(): void {
		wp_update_post(
			[
				'ID'        => $this->ids['about'],
				'post_name' => 'about-us',
			]
		);
		foreach ( [ 'crew', 'squad' ] as $slug ) {
			wp_update_post(
				[
					'ID'        => $this->ids['team'],
					'post_name' => $slug,
				]
			);
		}

		$collected = $this->pairs->collect_post( get_post( $this->ids['about'] ), 'company/about' );

		$this->assertContains( '/company/about/team/ -> /company/about-us/squad/', $this->lines( $collected ), 'Team was team when about moved; about/crew never existed.' );
		$this->assert_the_resolver_agrees( $collected );
	}

	public function test_a_subpage_renamed_before_its_parent_is_listed_at_the_slug_it_had_last_under_the_old_path(): void {
		wp_update_post(
			[
				'ID'        => $this->ids['team'],
				'post_name' => 'crew',
			]
		);
		wp_update_post(
			[
				'ID'        => $this->ids['about'],
				'post_name' => 'about-us',
			]
		);

		$about = $this->pairs->collect_post( get_post( $this->ids['about'] ), 'company/about' );
		$crew  = $this->pairs->collect_post( get_post( $this->ids['team'] ), 'company/about/team' );

		$this->assertContains( '/company/about/crew/ -> /company/about-us/crew/', $this->lines( $about ), 'The URL crew had until about moved.' );
		$this->assertContains( '/company/about/team/ -> /company/about-us/crew/', $this->lines( $crew ), 'The older URL is on crew’s own list.' );
		$this->assert_the_resolver_agrees( $about );
		$this->assert_the_resolver_agrees( $crew );
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

		wp_delete_post( $this->ids['about'], true );
		$this->assertContains( '/company/about/crew/ -> /company/crew/', $this->lines( $this->pairs->collect_change( $change ) ) );
	}

	public function test_collecting_an_old_path_costs_the_same_few_queries_for_fifty_subpages(): void {
		$five  = $this->queries_to_collect( 'services', 5 );
		$fifty = $this->queries_to_collect( 'products', 50 );

		$this->assertSame( $five, $fifty, 'Lookups are batched for every block and page, never one per subpage.' );
		$this->assertLessThanOrEqual( 13, $fifty, 'Ten as before, plus the root list, one path lookup for the resolver’s check of every pair, and the pages it finds.' );
	}

	/**
	 * Queries a cold collect of an old path costs, for a renamed page with subpages.
	 *
	 * @param string $slug     The page's old slug.
	 * @param int    $subpages How many subpages it has.
	 * @param bool   $hidden   Whether a private page takes the old slug, so the page there is hidden.
	 * @return int
	 */
	private function queries_to_collect( string $slug, int $subpages, bool $hidden = false ): int {
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
		if ( $hidden ) {
			$this->page( $slug, $this->ids['company'], 'private' );
		}
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
		$this->assertSame( 4, $this->pairs->count( $this->pairs->collect_post( $this->about_us(), 'company/about-us' ) )['count'] );

		$this->set_permalink_structure( '' );

		$this->assertSame( 0, $this->pairs->count( $this->pairs->collect_post( $this->about_us(), 'company/about-us' ) )['count'], 'Without pretty permalinks no URL held the path.' );
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
		$this->assertNotContains( '/company/about/careers/ -> /company/jobs/', $this->lines( $collected ), 'The older URL is on the page’s own list.' );
		$this->assert_the_resolver_agrees( $collected );

		$company = $this->pairs->collect_post( get_post( $this->ids['company'] ), 'company/about' );
		$this->assertContains( '/company/about/jobs/ -> /company/jobs/', $this->lines( $company ), 'Company’s alias lists it too.' );
		$this->assertNotContains( '/company/about/careers/ -> /company/jobs/', $this->lines( $company ) );
		$this->assert_the_resolver_agrees( $company );

		wp_delete_post( $this->ids['about'], true );
		$collected = $this->pairs->collect_change( $change );
		$this->assertContains( '/company/about/jobs/ -> /company/jobs/', $this->lines( $collected ), 'Once the trash is emptied too.' );
		$this->assertNotContains( '/company/about/careers/ -> /company/jobs/', $this->lines( $collected ) );
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

	public function test_a_parent_renamed_and_renamed_back_lists_a_subpage_at_the_url_it_had_then(): void {
		foreach ( [ [ 'team', 'crew' ], [ 'about', 'about-us' ], [ 'about', 'about' ] ] as [ $page, $slug ] ) {
			wp_update_post(
				[
					'ID'        => $this->ids[ $page ],
					'post_name' => $slug,
				]
			);
		}

		$collected = $this->pairs->collect_post( get_post( $this->ids['about'] ), 'company/about-us' );

		$this->assertContains( '/company/about-us/crew/ -> /company/about/crew/', $this->lines( $collected ), 'Crew was crew while about was about-us.' );
		$this->assertContains( '/company/about-us/crew/leadership/ -> /company/about/crew/leadership/', $this->lines( $collected ) );
		$this->assertNotContains( '/company/about-us/team/ -> /company/about/crew/', $this->lines( $collected ), 'That URL never existed.' );
		$this->assert_the_resolver_agrees( $collected );
	}

	public function test_an_unrelated_page_in_the_trash_at_an_old_path_leaves_its_subpages_listed(): void {
		wp_update_post(
			[
				'ID'        => $this->ids['about'],
				'post_name' => 'about-us',
			]
		);
		// Another page took the old path, was renamed, and went to the trash with no subpages.
		$other = $this->page( 'about', $this->ids['company'] );
		wp_update_post(
			[
				'ID'        => $other,
				'post_name' => 'about-old',
			]
		);
		wp_trash_post( $other );

		$collected = $this->pairs->collect_post( get_post( $this->ids['about'] ), 'company/about' );

		$this->assertSame(
			[
				'/company/about/history/ -> /company/about-us/history/',
				'/company/about/team/ -> /company/about-us/team/',
				'/company/about/team/leadership/ -> /company/about-us/team/leadership/',
			],
			$this->lines( $collected ),
			'The page in the trash moved nothing off the old path.'
		);
		$this->assertSame(
			[
				[
					'old'    => '/company/about/',
					'reason' => 'trash',
				],
			],
			$this->pairs->page( $collected, 1, 100 )['unresolved'],
			'The old path itself is held in the trash.'
		);
		$this->assert_the_resolver_agrees( $collected );
	}

	public function test_every_page_in_the_trash_that_left_an_alias_lists_its_subpages(): void {
		wp_trash_post( $this->ids['about'] );
		Change_Log::reset_request();
		// A new about takes the path, gets a subpage, and goes to the trash too.
		$again = $this->page( 'about', $this->ids['company'] );
		$this->page( 'faq', $again );
		wp_trash_post( $again );

		$collected = $this->pairs->collect_post( get_post( $this->ids['company'] ), 'company/about' );

		$this->assertSame(
			[
				'/company/about/history/ -> /company/history/',
				'/company/about/team/ -> /company/team/',
				'/company/about/team/leadership/ -> /company/team/leadership/',
				'/company/about/faq/ -> /company/faq/',
			],
			$this->lines( $collected )
		);
		$this->assert_the_resolver_agrees( $collected );
	}

	public function test_an_old_path_held_by_a_page_under_the_trash_stays_listed(): void {
		$archive = $this->page( 'archive', $this->ids['company'] );
		// The old team moves under Archive and holds its old path there.
		wp_update_post(
			[
				'ID'          => $this->ids['team'],
				'post_parent' => $archive,
			]
		);
		$team = $this->page( 'team', $this->ids['about'] );
		$this->page( 'lead', $team );
		wp_update_post(
			[
				'ID'        => $this->ids['about'],
				'post_name' => 'about-us',
			]
		);
		// The keep policy leaves the old team under Archive in the trash.
		$this->trash_raw( $archive );

		$collected = $this->pairs->collect_post( get_post( $this->ids['about'] ), 'company/about' );

		$this->assertContains( '/company/about/team/ -> /company/about-us/team/', $this->lines( $collected ), 'Nothing redirects through a page under the trash.' );
		$this->assertContains( '/company/about/team/lead/ -> /company/about-us/team/lead/', $this->lines( $collected ) );
		$this->assert_the_resolver_agrees( $collected );
	}

	public function test_once_the_trash_is_emptied_a_child_trashed_before_its_parent_keeps_its_pairs(): void {
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
			foreach ( $ids as $id ) {
				wp_delete_post( $id, true );
			}

			$collected = $this->pairs->collect_change( $change );
			$this->assertContains( '/company/b/a/kid/ -> /company/kid/', $this->lines( $collected ), $order );
			$this->assertNotContains( '/company/b/kid/ -> /company/kid/', $this->lines( $collected ), $order . ': that URL lasted only inside the bulk request.' );
			$this->assert_the_resolver_agrees( $collected );

			wp_delete_post( $kid, true );
		}
	}

	public function test_once_the_trash_is_emptied_three_chained_roots_list_the_subpage_once(): void {
		$c   = $this->page( 'c', $this->ids['company'] );
		$b   = $this->page( 'b', $c );
		$a   = $this->page( 'a', $b );
		$kid = $this->page( 'kid', $a );
		$ids = [ $a, $b, $c ];
		$this->start_core_bulk( 'trash', $ids );
		foreach ( $ids as $id ) {
			wp_trash_post( $id );
		}
		$this->end_core_bulk();
		$change = $this->log->latest( $this->admin );
		foreach ( $ids as $id ) {
			wp_delete_post( $id, true );
		}

		$collected = $this->pairs->collect_change( $change );

		$this->assertContains( '/company/c/b/a/kid/ -> /company/kid/', $this->lines( $collected ) );
		$this->assertNotContains( '/company/c/kid/ -> /company/kid/', $this->lines( $collected ), 'That URL lasted only inside the bulk request.' );
		$this->assertSame( (int) $this->ids['company'], (int) get_post( $kid )->post_parent );
		$this->assert_the_resolver_agrees( $collected );
	}

	public function test_a_deleted_root_whose_old_path_nothing_leads_from_is_unresolved(): void {
		$c   = $this->page( 'c', $this->ids['company'] );
		$b   = $this->page( 'b', $c );
		$a   = $this->page( 'a', $b );
		$kid = $this->page( 'kid', $a );
		$ids = [ $c, $b, $a ];
		$this->start_core_bulk( 'trash', $ids );
		foreach ( $ids as $id ) {
			wp_trash_post( $id );
		}
		$this->end_core_bulk();
		$change = $this->log->latest( $this->admin );
		// b moved nothing, so only c, still in the trash, holds b's old path.
		wp_delete_post( $a, true );
		wp_delete_post( $b, true );

		$collected = $this->pairs->collect_change( $change );

		$this->assertNotContains( '/company/c/b/ -> /company/', $this->lines( $collected ), 'The resolver sends nothing there.' );
		$this->assertContains(
			[
				'old'    => '/company/c/b/',
				'reason' => 'none',
			],
			$this->pairs->page( $collected, 1, 100 )['unresolved']
		);
		$this->assertContains( '/company/c/b/a/kid/ -> /company/kid/', $this->lines( $collected ) );
		$this->assertSame( (int) $this->ids['company'], (int) get_post( $kid )->post_parent );
		$this->assert_the_resolver_agrees( $collected );
	}

	public function test_collecting_under_a_hidden_page_costs_the_same_few_queries_for_fifty_subpages(): void {
		$five  = $this->queries_to_collect( 'services', 5, true );
		$fifty = $this->queries_to_collect( 'products', 50, true );

		$this->assertSame( $five, $fifty, 'The pages at the old subpage paths are looked up at once, never one per subpage.' );
	}

	public function test_an_old_path_whose_newest_holder_sits_under_the_trash_lists_nothing_below_it(): void {
		wp_update_post(
			[
				'ID'        => $this->ids['about'],
				'post_name' => 'about-us',
			]
		);
		// A newer page took the old path and moved under Archive, so it holds the path last.
		$archive = $this->page( 'archive', $this->ids['company'] );
		$other   = $this->page( 'about', $this->ids['company'] );
		wp_update_post(
			[
				'ID'          => $other,
				'post_parent' => $archive,
			]
		);
		$this->trash_raw( $archive );

		$collected = $this->pairs->collect_post( get_post( $this->ids['about'] ), 'company/about' );

		$this->assertSame( [], $this->lines( $collected ), 'The resolver passes over that page and finds no shorter old path.' );
		$this->assertSame(
			[
				[
					'old'    => '/company/about/',
					'reason' => 'trash',
				],
			],
			$this->pairs->page( $collected, 1, 100 )['unresolved']
		);
		$this->assert_the_resolver_agrees( $collected );
	}

	public function test_once_the_trash_is_emptied_an_old_path_whose_newest_holder_sits_under_the_trash_lists_nothing(): void {
		wp_trash_post( $this->ids['about'] );
		$change = $this->log->latest( $this->admin );
		wp_delete_post( $this->ids['about'], true );
		$archive = $this->page( 'archive', $this->ids['company'] );
		$other   = $this->page( 'about', $this->ids['company'] );
		wp_update_post(
			[
				'ID'          => $other,
				'post_parent' => $archive,
			]
		);
		$this->trash_raw( $archive );

		$collected = $this->pairs->collect_change( $change );
		$company   = $this->pairs->collect_post( get_post( $this->ids['company'] ), 'company/about' );

		$this->assertSame( [], $this->lines( $collected ) );
		$this->assertContains(
			[
				'old'    => '/company/about/',
				'reason' => 'trash',
			],
			$this->pairs->page( $collected, 1, 100 )['unresolved']
		);
		$this->assertSame( [], $this->lines( $company ), 'Company’s alias leads nowhere either.' );
		$this->assert_the_resolver_agrees( $collected );
		$this->assert_the_resolver_agrees( $company );
	}

	public function test_a_second_page_in_the_trash_at_an_alias_lists_its_subpage_at_the_url_it_had_until_that_trash(): void {
		wp_trash_post( $this->ids['about'] );
		Change_Log::reset_request();
		// A new about takes the path; careers moves under it and is renamed jobs there.
		$again   = $this->page( 'about', $this->ids['company'] );
		$careers = $this->page( 'careers', $this->ids['company'] );
		wp_update_post(
			[
				'ID'          => $careers,
				'post_parent' => $again,
			]
		);
		wp_update_post(
			[
				'ID'        => $careers,
				'post_name' => 'jobs',
			]
		);
		wp_trash_post( $again );

		$company = $this->pairs->collect_post( get_post( $this->ids['company'] ), 'company/about' );

		$this->assertContains( '/company/about/jobs/ -> /company/jobs/', $this->lines( $company ), 'The URL jobs had until the second trash.' );
		$this->assertNotContains( '/company/about/careers/ -> /company/jobs/', $this->lines( $company ), 'Jobs’ own list has that older URL.' );
		$this->assert_the_resolver_agrees( $company );
	}

	public function test_an_alias_written_before_its_only_page_in_the_trash_lists_the_subpage_at_the_url_it_had_until_the_trash(): void {
		wp_update_post(
			[
				'ID'        => $this->ids['about'],
				'post_name' => 'about-us',
			]
		);
		// An earlier about with a subpage is deleted for good, which writes Company’s alias first.
		$earlier = $this->page( 'about', $this->ids['company'] );
		$this->page( 'old', $earlier );
		wp_delete_post( $earlier, true );
		Change_Log::reset_request();
		$again   = $this->page( 'about', $this->ids['company'] );
		$careers = $this->page( 'careers', $this->ids['company'] );
		wp_update_post(
			[
				'ID'          => $careers,
				'post_parent' => $again,
			]
		);
		wp_update_post(
			[
				'ID'        => $careers,
				'post_name' => 'jobs',
			]
		);
		wp_trash_post( $again );

		$company = $this->pairs->collect_post( get_post( $this->ids['company'] ), 'company/about' );

		$this->assertContains( '/company/about/jobs/ -> /company/jobs/', $this->lines( $company ), 'The URL jobs had until the trash.' );
		$this->assertContains( '/company/about/old/ -> /company/old/', $this->lines( $company ), 'The subpage the delete moved up still left the path.' );
		$this->assert_the_resolver_agrees( $company );
	}

	public function test_a_subpage_renamed_before_its_parent_was_renamed_is_listed_at_the_url_it_had_then(): void {
		foreach ( [ [ 'about', 'about-us' ], [ 'leadership', 'lead' ], [ 'team', 'crew' ] ] as [ $page, $slug ] ) {
			wp_update_post(
				[
					'ID'        => $this->ids[ $page ],
					'post_name' => $slug,
				]
			);
		}

		$collected = $this->pairs->collect_post( get_post( $this->ids['about'] ), 'company/about' );

		$this->assertContains( '/company/about/team/leadership/ -> /company/about-us/crew/lead/', $this->lines( $collected ), 'Leadership kept its slug until about had moved.' );
		$this->assertNotContains( '/company/about/team/lead/ -> /company/about-us/crew/lead/', $this->lines( $collected ), 'That URL never existed.' );
		$this->assert_the_resolver_agrees( $collected );
	}

	public function test_a_page_in_the_trash_that_moved_subpages_here_without_the_alias_leaves_the_page_s_own_subpages_listed(): void {
		wp_update_post(
			[
				'ID'        => $this->ids['about'],
				'post_name' => 'about-us',
			]
		);
		// A new about with an FAQ moves under About and goes to the trash: FAQ moves up to About.
		$other = $this->page( 'about', $this->ids['company'] );
		$this->page( 'faq', $other );
		wp_update_post(
			[
				'ID'          => $other,
				'post_parent' => $this->ids['about'],
			]
		);
		wp_trash_post( $other );

		$collected = $this->pairs->collect_post( get_post( $this->ids['about'] ), 'company/about' );

		$this->assertSame(
			[
				'/company/about/faq/ -> /company/about-us/faq/',
				'/company/about/history/ -> /company/about-us/history/',
				'/company/about/team/ -> /company/about-us/team/',
				'/company/about/team/leadership/ -> /company/about-us/team/leadership/',
			],
			$this->lines( $collected ),
			'The page in the trash wrote another alias, so About’s own subpages still left this path.'
		);
		$this->assert_the_resolver_agrees( $collected );
	}

	public function test_an_alias_whose_moved_subpages_are_gone_never_lists_the_new_parent_s_own_subpage(): void {
		$this->page( 'careers', $this->ids['company'] );
		wp_trash_post( $this->ids['about'] );
		$this->trash_raw( $this->ids['team'] );
		$this->trash_raw( $this->ids['history'] );

		$collected = $this->pairs->collect_post( get_post( $this->ids['company'] ), 'company/about' );

		$this->assertSame( [], $this->lines( $collected ), '/company/about/careers/ never existed.' );
	}

	public function test_unrelated_moves_in_the_trash_leave_the_marker_budget_to_this_page(): void {
		$archive = $this->page( 'archive', $this->ids['company'] );
		// An older svc moves under Archive, so it holds company/svc, and takes four subpages up to Archive.
		$older = $this->page( 'svc', $this->ids['company'] );
		for ( $i = 1; $i <= 4; $i++ ) {
			$this->page( 'x' . $i, $older );
		}
		wp_update_post(
			[
				'ID'          => $older,
				'post_parent' => $archive,
			]
		);
		wp_trash_post( $older );
		Change_Log::reset_request();
		$svc = $this->page( 'svc', $this->ids['company'] );
		$this->page( 's1', $svc );
		$this->page( 's2', $svc );
		wp_trash_post( $svc );
		add_filter( 'hierarchy_guard_count_cap', static fn(): int => 3 );

		$collected = $this->pairs->collect_post( get_post( $this->ids['company'] ), 'company/svc' );

		$this->assertSame(
			[
				'/company/svc/s1/ -> /company/s1/',
				'/company/svc/s2/ -> /company/s2/',
			],
			$this->lines( $collected )
		);
		$this->assertFalse( $collected['capped'] );
	}

	public function test_holders_of_listed_paths_cost_the_same_few_queries_for_fifty(): void {
		$five  = $this->queries_to_collect_held( 'held-five', 5 );
		$fifty = $this->queries_to_collect_held( 'held-fifty', 50 );

		$this->assertSame( $five, $fifty, 'The holders’ ancestors are read a level at a time, never one holder at a time.' );
	}

	/**
	 * Queries a cold collect costs when every old subpage path is held by
	 * another page that moved from there to a top-level parent of its own.
	 *
	 * @param string $slug The page's old slug.
	 * @param int    $held How many old subpage paths are held elsewhere.
	 * @return int
	 */
	private function queries_to_collect_held( string $slug, int $held ): int {
		$parent = $this->page( $slug, $this->ids['company'] );
		for ( $i = 1; $i <= $held; $i++ ) {
			$elsewhere = $this->page( $slug . '-top-' . $i );
			$moved     = $this->page( 'c' . $i, $parent );
			wp_update_post(
				[
					'ID'          => $moved,
					'post_parent' => $elsewhere,
				]
			);
			$this->page( 'c' . $i, $parent );
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
		$this->assertCount( 1, $collected['pairs'], 'Each old subpage path leads to the page that moved away from it.' );
		return get_num_queries() - $queries;
	}

	public function test_a_long_chain_of_deleted_roots_costs_no_more_queries_than_a_short_one(): void {
		$short = $this->queries_to_collect_chain( 'short', 4 );
		$long  = $this->queries_to_collect_chain( 'long', 12 );

		$this->assertSame( $short, $long, 'Each deleted root is followed once, without a read.' );
	}

	/**
	 * Queries a cold collect costs for a chain of nested roots, trashed child
	 * first in one bulk action and then all deleted for good.
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
		$ids = array_reverse( $chain );
		$this->start_core_bulk( 'trash', $ids );
		foreach ( $ids as $id ) {
			wp_trash_post( $id );
		}
		$this->end_core_bulk();
		$change = $this->log->latest( $this->admin );
		foreach ( $ids as $id ) {
			wp_delete_post( $id, true );
		}
		wp_cache_flush();

		$queries   = get_num_queries();
		$collected = $this->pairs->collect_change( $change );
		$cost      = get_num_queries() - $queries;
		$this->assertContains( $kid, array_column( $collected['pairs'], 'id' ) );
		$this->assert_the_resolver_agrees( $collected );
		return $cost;
	}

	public function test_an_old_path_lists_the_subpages_a_later_trash_moved_up_at_the_urls_they_had_then(): void {
		wp_update_post(
			[
				'ID'        => $this->ids['company'],
				'post_name' => 'studio',
			]
		);
		// The trash moves about's subpages up to Company after Company left company.
		wp_trash_post( $this->ids['about'] );

		$collected = $this->pairs->collect_post( get_post( $this->ids['company'] ), 'company' );

		$this->assertContains( '/company/about/team/ -> /studio/team/', $this->lines( $collected ), 'The URL team had when Company left the path.' );
		$this->assertContains( '/company/about/team/leadership/ -> /studio/team/leadership/', $this->lines( $collected ) );
		$this->assertNotContains( '/company/team/ -> /studio/team/', $this->lines( $collected ), 'That URL never existed.' );
		$this->assert_the_resolver_agrees( $collected );
	}

	public function test_a_trash_lists_a_deeper_subpage_renamed_since_at_the_url_it_had_until_the_trash(): void {
		wp_trash_post( $this->ids['about'] );
		$change = $this->log->latest( $this->admin );
		wp_update_post(
			[
				'ID'        => $this->ids['leadership'],
				'post_name' => 'lead',
			]
		);

		$collected = $this->pairs->collect_change( $change );

		$this->assertContains( '/company/about/team/leadership/ -> /company/team/lead/', $this->lines( $collected ), 'The URL leadership had until the trash.' );
		$this->assertNotContains( '/company/about/team/lead/ -> /company/team/lead/', $this->lines( $collected ), 'That URL never existed.' );
		$this->assert_the_resolver_agrees( $collected );
	}

	public function test_an_old_url_the_resolver_no_longer_serves_is_not_listed(): void {
		wp_trash_post( $this->ids['about'] );
		// History moves on, and Company is renamed: the alias leads to studio/history, where nothing is.
		$services = $this->page( 'services', $this->ids['company'] );
		wp_update_post(
			[
				'ID'          => $this->ids['history'],
				'post_parent' => $services,
			]
		);
		wp_update_post(
			[
				'ID'        => $this->ids['company'],
				'post_name' => 'studio',
			]
		);

		$collected = $this->pairs->collect_post( get_post( $this->ids['company'] ), 'company/about' );

		$this->assertNotContains( '/company/about/history/ -> /studio/services/history/', $this->lines( $collected ), 'The resolver sends that URL nowhere.' );
		$this->assertContains( '/company/about/team/ -> /studio/team/', $this->lines( $collected ) );
		$this->assert_the_resolver_agrees( $collected );
	}

	/**
	 * Where a logged-out request for an old path is redirected, by the Server
	 * on a real main query; an empty string when it is not.
	 *
	 * @param string $path Normalized path.
	 * @return string
	 */
	private function served_on_request( string $path ): string {
		$user = get_current_user_id();
		update_option( Settings::OPTION, [ 'serve_redirects' => true ] );
		wp_set_current_user( 0 );
		$this->go_to( Paths::url( 'page', $path ) );
		$target = ( new Server( new Resolver( new Ledger() ) ) )->target_for_current_request();
		wp_set_current_user( $user );
		return null === $target ? '' : Paths::relative( $target['url'] );
	}

	public function test_with_two_pages_at_one_path_the_pairs_follow_the_one_core_finds(): void {
		$services = $this->page( 'services', $this->ids['company'] );
		// Core leaves a draft's slug as it is, so the draft sits at the same path.
		$this->page( 'services', $this->ids['company'], 'draft' );
		wp_update_post(
			[
				'ID'        => $this->ids['company'],
				'post_name' => 'company-new',
			]
		);

		$collected = $this->pairs->collect_post( get_post( $this->ids['company'] ), 'company' );

		$this->assertSame( 'company-new/services', Paths::uri( get_post( $services ) ) );
		$this->assertNotContains( '/company/services/ -> /company-new/services/', $this->lines( $collected ), 'get_page_by_path() finds the draft there, the later of the two, which nobody can view.' );
		$this->assertContains( '/company/about/ -> /company-new/about/', $this->lines( $collected ) );
		$this->assertSame( '', $this->served_on_request( 'company/services' ) );
		$this->assert_the_resolver_agrees( $collected );
	}

	public function test_an_old_url_a_blog_post_uses_now_is_not_listed(): void {
		$news = $this->page( 'news' );
		wp_update_post(
			[
				'ID'        => $news,
				'post_name' => 'updates',
			]
		);
		self::factory()->post->create(
			[
				'post_type'   => 'post',
				'post_name'   => 'news',
				'post_status' => 'publish',
			]
		);

		$collected = $this->pairs->collect_post( get_post( $news ), 'news' );

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
		wp_update_post(
			[
				'ID'        => $services,
				'post_name' => 'offers',
			]
		);
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

		$collected = $this->pairs->collect_post( get_post( $services ), 'company/services' );

		$this->assertSame( [], $this->lines( $collected ) );
		$this->assertSame( '', $this->served_on_request( 'company/services' ) );
	}

	public function test_a_subpage_moved_and_renamed_in_one_save_is_listed_at_the_url_it_had(): void {
		wp_update_post(
			[
				'ID'        => $this->ids['about'],
				'post_name' => 'about-us',
			]
		);
		// One save, as Quick Edit makes it: a new parent and a new slug.
		wp_update_post(
			[
				'ID'          => $this->ids['team'],
				'post_parent' => $this->ids['history'],
				'post_name'   => 'crew',
			]
		);

		$collected = $this->pairs->collect_post( get_post( $this->ids['about'] ), 'company/about' );

		$this->assertContains( '/company/about/team/ -> /company/about-us/history/crew/', $this->lines( $collected ) );
		$this->assertContains( '/company/about/team/leadership/ -> /company/about-us/history/crew/leadership/', $this->lines( $collected ) );
		$this->assertSame( '/company/about-us/history/crew/', $this->served_on_request( 'company/about/team' ) );
		$this->assert_the_resolver_agrees( $collected );
	}

	public function test_the_pages_in_the_trash_read_for_an_old_path_stop_at_the_cap(): void {
		add_filter( 'hierarchy_guard_count_cap', static fn(): int => 3 );
		wp_update_post(
			[
				'ID'        => $this->ids['about'],
				'post_name' => 'about-us',
			]
		);
		// Five pages each take the old path, leave it and go to the trash: each holds it there.
		for ( $i = 1; $i <= 5; $i++ ) {
			$held = $this->page( 'about', $this->ids['company'] );
			wp_update_post(
				[
					'ID'        => $held,
					'post_name' => 'held-' . $i,
				]
			);
			wp_trash_post( $held );
		}

		$this->assertTrue( $this->pairs->collect_post( get_post( $this->ids['about'] ), 'company/about' )['capped'] );
	}

	public function test_an_old_url_deeper_than_the_server_s_segment_cap_is_not_listed(): void {
		add_filter( 'hierarchy_guard_max_segments', static fn(): int => 3 );
		wp_update_post(
			[
				'ID'        => $this->ids['company'],
				'post_name' => 'company-new',
			]
		);

		$lines = $this->lines( $this->pairs->collect_post( get_post( $this->ids['company'] ), 'company' ) );

		$this->assertContains( '/company/about/team/ -> /company-new/about/team/', $lines );
		$this->assertNotContains( '/company/about/team/leadership/ -> /company-new/about/team/leadership/', $lines, 'The server refuses a request of four segments.' );
		$this->assertSame( '', $this->served_on_request( 'company/about/team/leadership' ) );
	}

	public function test_the_history_read_for_a_rebuild_stops_at_the_cap(): void {
		add_filter( 'hierarchy_guard_count_cap', static fn(): int => 2 );
		wp_update_post(
			[
				'ID'        => $this->ids['about'],
				'post_name' => 'about-us',
			]
		);
		// Forty-one renames of history: more ledger entries than twenty per page the cap allows.
		for ( $i = 1; $i <= 41; $i++ ) {
			wp_update_post(
				[
					'ID'        => $this->ids['history'],
					'post_name' => 'history-' . $i,
				]
			);
		}
		wp_update_post(
			[
				'ID'          => $this->ids['team'],
				'post_parent' => $this->ids['company'],
			]
		);

		$this->assertTrue( $this->pairs->collect_post( get_post( $this->ids['about'] ), 'company/about' )['capped'] );
	}

	public function test_a_newer_holder_under_the_trash_leaves_the_old_urls_the_resolver_serves_here(): void {
		add_filter( 'hierarchy_guard_trash_policy', static fn(): string => Settings::POLICY_KEEP );
		$studio  = $this->page( 'studio' );
		$archive = $this->page( 'archive' );
		wp_update_post(
			[
				'ID'          => $this->ids['team'],
				'post_parent' => $studio,
			]
		);
		// A new page takes Team's old path and moves on: it holds that path last.
		$other = $this->page( 'team', $this->ids['about'] );
		wp_update_post(
			[
				'ID'          => $other,
				'post_parent' => $archive,
			]
		);
		wp_update_post(
			[
				'ID'        => $this->ids['about'],
				'post_name' => 'about-us',
			]
		);
		wp_update_post(
			[
				'ID'          => $this->ids['team'],
				'post_parent' => $this->ids['about'],
			]
		);
		// With the keep policy the other page stays where it is, under Archive in the trash.
		wp_trash_post( $archive );

		$collected = $this->pairs->collect_post( get_post( $this->ids['team'] ), 'company/about/team' );

		$this->assertSame( 'publish', get_post_status( $other ) );
		$this->assertSame(
			[
				'/company/about/team/ -> /company/about-us/team/',
				'/company/about/team/leadership/ -> /company/about-us/team/leadership/',
			],
			$this->lines( $collected ),
			'The resolver passes over that page and rewrites the URLs through About to Team.'
		);
		$this->assertSame( '/company/about-us/team/', $this->served_on_request( 'company/about/team' ) );
		$this->assert_the_resolver_agrees( $collected );
	}

	public function test_a_newer_holder_nobody_can_view_leaves_the_old_path_leading_nowhere(): void {
		wp_update_post(
			[
				'ID'        => $this->ids['about'],
				'post_name' => 'about-us',
			]
		);
		$other = $this->page( 'about', $this->ids['company'] );
		wp_update_post(
			[
				'ID'        => $other,
				'post_name' => 'about-y',
			]
		);
		wp_update_post(
			[
				'ID'          => $other,
				'post_status' => 'draft',
			]
		);

		$collected = $this->pairs->collect_post( get_post( $this->ids['about'] ), 'company/about' );

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
}
