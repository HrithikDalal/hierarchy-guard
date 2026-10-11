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
		$this->assertNotContains( '/company/about/careers/ -> /company/jobs/', $this->lines( $collected ), 'That URL never existed.' );
		$this->assert_the_resolver_agrees( $collected );

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

		$this->assertSame( [], $this->lines( $collected ) );
		$this->assertContains(
			[
				'old'    => '/company/about/',
				'reason' => 'trash',
			],
			$this->pairs->page( $collected, 1, 100 )['unresolved']
		);
		$this->assert_the_resolver_agrees( $collected );
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

		$served = Batch_Resolver::served( [ 'news', 'company', 'company/services', 'company/about', 'company/about/history' ], 'page', $facts, true );
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
}
