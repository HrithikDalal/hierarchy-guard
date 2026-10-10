<?php
/**
 * Longest-prefix resolution of old paths.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Ledger;
use HierarchyGuard\Post_Types;
use HierarchyGuard\Redirects\Resolver;
use HierarchyGuard\Settings;
use WP_UnitTestCase;

/**
 * @covers \HierarchyGuard\Redirects\Resolver
 */
final class ResolverTest extends WP_UnitTestCase {

	private Ledger $ledger;

	private Resolver $resolver;

	private int $company;

	private int $about;

	private int $team;

	public function set_up(): void {
		parent::set_up();
		delete_option( Settings::OPTION );
		delete_option( Ledger::ROOT_OPTION );
		Post_Types::reset();
		$this->set_permalink_structure( '/%postname%/' );

		$this->ledger   = new Ledger();
		$this->resolver = new Resolver( $this->ledger );
		$this->company  = self::factory()->post->create(
			[
				'post_type' => 'page',
				'post_name' => 'company',
			]
		);
		$this->about    = self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_name'   => 'about',
				'post_parent' => $this->company,
			]
		);
		$this->team     = self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_name'   => 'team',
				'post_parent' => $this->about,
			]
		);
	}

	public function tear_down(): void {
		$this->set_permalink_structure( '' );
		parent::tear_down();
	}

	public function test_resolves_a_subpage_after_its_parent_was_renamed(): void {
		wp_update_post(
			[
				'ID'        => $this->about,
				'post_name' => 'company-info',
			]
		);

		$target = $this->resolver->resolve( 'page', 'company/about/team' );
		$this->assertNotNull( $target );
		$this->assertSame( $this->team, $target->ID );
	}

	public function test_resolves_the_renamed_page_itself(): void {
		wp_update_post(
			[
				'ID'        => $this->about,
				'post_name' => 'company-info',
			]
		);

		$this->assertSame( $this->about, $this->resolver->resolve( 'page', '/Company/About/' )->ID );
	}

	public function test_resolves_chained_changes_across_rounds(): void {
		wp_update_post(
			[
				'ID'        => $this->about,
				'post_name' => 'company-info',
			]
		);
		wp_update_post(
			[
				'ID'          => $this->team,
				'post_parent' => $this->company,
			]
		);

		// Round 1 rewrites company/about/team to company/company-info/team, which no longer exists.
		// Round 2 finds the entry team recorded when it moved and lands on company/team.
		$this->assertSame( $this->team, $this->resolver->resolve( 'page', 'company/about/team' )->ID );
	}

	public function test_unknown_paths_return_null(): void {
		$this->assertNull( $this->resolver->resolve( 'page', 'nothing/here' ) );
		$this->assertNull( $this->resolver->resolve( 'page', '' ) );
	}

	public function test_root_paths_resolve_to_top_level_pages(): void {
		wp_delete_post( $this->company, true );

		$this->assertSame( $this->team, $this->resolver->resolve( 'page', 'company/about/team' )->ID );
		$this->assertSame( $this->about, $this->resolver->resolve( 'page', 'company/about' )->ID );
	}

	public function test_target_must_be_publicly_viewable(): void {
		wp_update_post(
			[
				'ID'        => $this->about,
				'post_name' => 'company-info',
			]
		);
		wp_update_post(
			[
				'ID'          => $this->team,
				'post_status' => 'draft',
			]
		);

		$this->assertNull( $this->resolver->resolve( 'page', 'company/about/team' ) );
	}

	public function test_lookups_are_scoped_to_the_post_type(): void {
		wp_update_post(
			[
				'ID'        => $this->about,
				'post_name' => 'company-info',
			]
		);

		$this->assertNull( $this->resolver->resolve( 'post', 'company/about/team' ) );
	}

	public function test_cycles_terminate(): void {
		$a = self::factory()->post->create(
			[
				'post_type' => 'page',
				'post_name' => 'a',
			]
		);
		$b = self::factory()->post->create(
			[
				'post_type' => 'page',
				'post_name' => 'b',
			]
		);
		$this->ledger->record( $a, 'b' );
		$this->ledger->record( $b, 'a' );

		$this->assertNull( $this->resolver->resolve( 'page', 'a/missing' ) );
	}

	public function test_attachment_with_same_slug_does_not_hijack(): void {
		global $wpdb;

		$attachment = self::factory()->attachment->create_object(
			[
				'file'           => 'team.jpg',
				'post_parent'    => $this->about,
				'post_mime_type' => 'image/jpeg',
			]
		);
		// Core's wp_unique_post_slug() would suffix a same-slug sibling, so force the
		// collision the way an import or a direct database edit can produce it.
		$wpdb->update( $wpdb->posts, [ 'post_name' => 'team' ], [ 'ID' => $attachment ] );
		clean_post_cache( $attachment );
		$this->assertSame( 'team', get_post( $attachment )->post_name, 'Precondition: attachment and page share the slug.' );
		$this->assertSame( $this->about, (int) get_post( $attachment )->post_parent, 'Precondition: attachment and page share the parent.' );

		wp_update_post(
			[
				'ID'        => $this->about,
				'post_name' => 'company-info',
			]
		);

		$target = $this->resolver->resolve( 'page', 'company/about/team' );
		$this->assertNotNull( $target );
		$this->assertSame( 'page', $target->post_type );
		$this->assertSame( $this->team, $target->ID );
	}

	public function test_a_renamed_then_trashed_parent_does_not_lead_into_the_trash(): void {
		wp_update_post(
			[
				'ID'        => $this->about,
				'post_name' => 'about-us',
			]
		);
		wp_trash_post( $this->about );
		$this->assertSame( $this->about, (int) get_post( $this->team )->post_parent, 'Precondition: the subpage stays under the trashed parent.' );

		$this->assertNull( $this->resolver->resolve( 'page', 'company/about/team' ) );
	}

	public function test_a_trashed_ledger_hit_gives_way_to_a_shorter_prefix(): void {
		// about moves under elsewhere (recording company/about), a new about/team takes
		// its place, company becomes firm (recording company), then the moved about is trashed.
		$elsewhere = self::factory()->post->create(
			[
				'post_type' => 'page',
				'post_name' => 'elsewhere',
			]
		);
		wp_update_post(
			[
				'ID'          => $this->about,
				'post_parent' => $elsewhere,
			]
		);
		$new_about = self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_name'   => 'about',
				'post_parent' => $this->company,
			]
		);
		$new_team  = self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_name'   => 'team',
				'post_parent' => $new_about,
			]
		);
		wp_update_post(
			[
				'ID'        => $this->company,
				'post_name' => 'firm',
			]
		);
		wp_trash_post( $this->about );
		$this->assertTrue( $this->ledger->held_in_trash( 'page', 'company/about' ), 'Precondition: the longest prefix is held only by the trashed page.' );

		$this->assertSame( $new_team, $this->resolver->resolve( 'page', 'company/about/team' )->ID );
	}

	public function test_a_target_under_a_trashed_ancestor_is_refused(): void {
		wp_update_post(
			[
				'ID'        => $this->team,
				'post_name' => 'crew',
			]
		);
		wp_trash_post( $this->about );
		$this->assertSame( 'publish', get_post_status( $this->team ), 'Precondition: the ledger hit itself is live.' );
		$this->assertSame( 'company/about__trashed/crew', get_page_uri( $this->team ), 'Precondition: its URL runs through the trash.' );

		$this->assertNull( $this->resolver->resolve( 'page', 'company/about/team' ) );
	}

	public function test_a_target_under_a_numbered_trashed_ancestor_is_refused(): void {
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
		wp_update_post(
			[
				'ID'        => $this->team,
				'post_name' => 'crew',
			]
		);
		wp_trash_post( $this->about );
		$this->assertSame( 'about__trashed-2', get_post( $this->about )->post_name, 'Precondition: core numbered the trashed slug.' );
		$this->assertSame( 'company/about__trashed-2/crew', get_page_uri( $this->team ), 'Precondition: the live subpage sits under it.' );

		$this->assertNull( $this->resolver->resolve( 'page', 'company/about/team' ) );
	}

	public function test_a_hit_under_a_trashed_ancestor_gives_way_to_a_shorter_prefix(): void {
		wp_update_post(
			[
				'ID'        => $this->team,
				'post_name' => 'crew',
			]
		);
		wp_trash_post( $this->about );
		$this->assertSame( 'company/about__trashed/crew', get_page_uri( $this->team ), 'Precondition: the ledger hit lives under the trash.' );

		// Another route still leads somewhere live: company/about now maps to company.
		$replacement = self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_name'   => 'team',
				'post_parent' => $this->company,
			]
		);
		$this->ledger->record_alias( 'page', $this->company, 'company/about' );

		$target = $this->resolver->resolve( 'page', 'company/about/team' );
		$this->assertNotNull( $target );
		$this->assertSame( $replacement, $target->ID );
	}

	public function test_an_old_parent_path_whose_page_is_only_in_the_trash_does_not_redirect(): void {
		global $wpdb;

		// What the trash flow leaves behind: about in the trash, team moved up, the alias on company, and about's own path on about.
		wp_trash_post( $this->about );
		$wpdb->update( $wpdb->posts, [ 'post_parent' => $this->company ], [ 'ID' => $this->team ] );
		clean_post_cache( $this->team );
		$this->ledger->record_alias( 'page', $this->company, 'company/about' );
		$this->ledger->record( $this->about, 'company/about' );

		$this->assertSame( $this->team, $this->resolver->resolve( 'page', 'company/about/team' )->ID, 'Subpage URLs still resolve.' );
		$this->assertNull( $this->resolver->resolve( 'page', 'company/about' ), 'A browser would keep a 301 to the grandparent after a restore.' );

		wp_delete_post( $this->about, true );

		$this->assertSame( $this->company, $this->resolver->resolve( 'page', 'company/about' )->ID, 'Once the page is gone for good, its old URL leads to its parent.' );
	}

	public function test_an_older_path_of_a_page_in_the_trash_does_not_redirect_either(): void {
		global $wpdb;

		$this->ledger->record( $this->about, 'company/about-us' );
		wp_trash_post( $this->about );
		$wpdb->update( $wpdb->posts, [ 'post_parent' => $this->company ], [ 'ID' => $this->team ] );
		clean_post_cache( $this->team );
		foreach ( [ 'company/about', 'company/about-us' ] as $path ) {
			$this->ledger->record_alias( 'page', $this->company, $path );
			$this->ledger->record( $this->about, $path );
		}

		$this->assertSame( $this->team, $this->resolver->resolve( 'page', 'company/about-us/team' )->ID, 'The live alias wins over the trashed page holding the same path.' );
		$this->assertNull( $this->resolver->resolve( 'page', 'company/about-us' ) );
	}

	public function test_a_page_in_the_trash_under_a_trashed_parent_does_not_redirect(): void {
		global $wpdb;

		$leadership = self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_name'   => 'leadership',
				'post_parent' => $this->team,
			]
		);
		wp_trash_post( $this->about );
		wp_trash_post( $this->team );
		// What the trash flow leaves when team's subpages move past both trashed pages.
		$wpdb->update( $wpdb->posts, [ 'post_parent' => $this->company ], [ 'ID' => $leadership ] );
		clean_post_cache( $leadership );
		$this->ledger->record_alias( 'page', $this->company, 'company/about/team' );
		$this->ledger->record( $this->team, 'company/about/team' );

		$this->assertSame( $leadership, $this->resolver->resolve( 'page', 'company/about/team/leadership' )->ID );
		$this->assertNull( $this->resolver->resolve( 'page', 'company/about/team' ) );
	}

	public function test_a_top_level_page_in_the_trash_does_not_hide_its_root_list_alias(): void {
		global $wpdb;

		// What the trash flow leaves when a top-level page's subpages become top level.
		wp_trash_post( $this->company );
		$wpdb->update( $wpdb->posts, [ 'post_parent' => 0 ], [ 'ID' => $this->about ] );
		clean_post_cache( $this->about );
		$this->ledger->record_alias( 'page', 0, 'company' );
		$this->ledger->record( $this->company, 'company' );

		$this->assertSame( $this->about, $this->resolver->resolve( 'page', 'company/about' )->ID );
		$this->assertSame( $this->team, $this->resolver->resolve( 'page', 'company/about/team' )->ID );
		$this->assertNull( $this->resolver->resolve( 'page', 'company' ) );
	}
}
