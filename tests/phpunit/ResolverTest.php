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
use HierarchyGuard\Redirects\Batch_Resolver;
use HierarchyGuard\Redirects\Resolver;
use HierarchyGuard\Settings;
use HierarchyGuard\Trash\Markers;
use WP_UnitTestCase;

/**
 * @covers \HierarchyGuard\Redirects\Resolver
 * @covers \HierarchyGuard\Redirects\Rules
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
		delete_option( Ledger::ROOT_TARGETS );
		Post_Types::reset();
		$this->set_permalink_structure( '/%postname%/' );
		// These tests exercise core's own trash behaviour, which the keep policy leaves untouched.
		add_filter( 'hierarchy_guard_trash_policy', static fn(): string => Settings::POLICY_KEEP );

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
		// The new about took company/about from the moved one; an entry from before that rule is still there.
		$this->ledger->record( $this->about, 'company/about' );
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

		// Emptying the trash deletes company without a ledger write; the cached holder must not answer.
		wp_delete_post( $this->company, true );

		$this->assertSame( $this->about, $this->resolver->resolve( 'page', 'company/about' )->ID );
	}

	public function test_a_redirect_a_restore_can_undo_is_temporary(): void {
		global $wpdb;

		wp_trash_post( $this->about );
		$wpdb->update( $wpdb->posts, [ 'post_parent' => $this->company ], [ 'ID' => $this->team ] );
		clean_post_cache( $this->team );
		Markers::add( $this->team, Markers::moved_key( $this->about ), Markers::encode_moved( $this->company, 'team', 'team', time() ) );
		$this->ledger->record_alias( 'page', $this->company, 'company/about' );
		$this->ledger->record( $this->about, 'company/about' );

		$temporary = false;
		$this->assertSame( $this->team, $this->resolver->resolve( 'page', 'company/about/team', $temporary )->ID );
		$this->assertTrue( $temporary, 'The page the old URL belonged to is only in the trash.' );

		// Emptying the trash makes the move permanent: about's own entry goes with it, and so does the marker.
		Markers::remove_key( $this->team, Markers::moved_key( $this->about ) );
		wp_delete_post( $this->about, true );

		$this->assertSame( $this->team, $this->resolver->resolve( 'page', 'company/about/team', $temporary )->ID );
		$this->assertFalse( $temporary );
	}

	public function test_a_redirect_to_a_subpage_a_restore_will_move_back_is_temporary(): void {
		$this->ledger->record( $this->team, 'company/about/team-old' );
		Markers::add( $this->team, Markers::moved_key( $this->about ), Markers::encode_moved( $this->about, 'team', 'team', time() ) );

		$temporary = false;
		$this->assertSame( $this->team, $this->resolver->resolve( 'page', 'company/about/team-old', $temporary )->ID );
		$this->assertTrue( $temporary );
	}

	public function test_a_redirect_to_a_page_under_a_moved_subpage_is_temporary(): void {
		$alice = self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_name'   => 'alice',
				'post_parent' => $this->team,
			]
		);
		$this->ledger->record( $alice, 'company/about/team/alice-old' );
		Markers::add( $this->team, Markers::moved_key( $this->about ), Markers::encode_moved( $this->about, 'team', 'team', time() ) );

		$temporary = false;
		$this->assertSame( $alice, $this->resolver->resolve( 'page', 'company/about/team/alice-old', $temporary )->ID );
		$this->assertTrue( $temporary, 'alice moves back with team when the trashed page is restored.' );
	}

	/**
	 * Team moves out of about to company, recording company/about/team on
	 * it; a new team with a subpage crew takes company/about/team; then about
	 * is renamed to about-us.
	 *
	 * @param string $status The new team's status.
	 * @return array{0:int,1:int} The new team and crew.
	 */
	private function team_replaced( string $status ): array {
		wp_update_post(
			[
				'ID'          => $this->team,
				'post_parent' => $this->company,
			]
		);
		$new_team = self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_name'   => 'team',
				'post_parent' => $this->about,
				'post_status' => $status,
			]
		);
		$crew     = self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_name'   => 'crew',
				'post_parent' => $new_team,
			]
		);
		wp_update_post(
			[
				'ID'        => $this->about,
				'post_name' => 'about-us',
			]
		);
		return [ $new_team, $crew ];
	}

	public function test_a_page_that_took_a_recorded_path_keeps_it_after_its_parent_is_renamed(): void {
		[ $new_team, $crew ] = $this->team_replaced( 'publish' );

		$this->assertSame( $new_team, $this->resolver->resolve( 'page', 'company/about/team' )->ID, 'The URL last showed the new team.' );
		$this->assertSame( $crew, $this->resolver->resolve( 'page', 'company/about/team/crew' )->ID );
		$this->assertSame( [], $this->ledger->paths_for( $this->team ), 'The old team gave up the path the new team took.' );
	}

	public function test_a_draft_at_a_recorded_path_does_not_take_it(): void {
		[ $new_team ] = $this->team_replaced( 'draft' );

		$this->assertSame( $this->team, $this->resolver->resolve( 'page', 'company/about/team' )->ID, 'The URL last showed the old team.' );
		$this->assertNotSame( $new_team, $this->team );
	}

	public function test_a_newer_page_s_old_url_resolves_while_an_older_holder_is_in_the_trash(): void {
		add_filter( 'hierarchy_guard_trash_policy', static fn(): string => Settings::POLICY_REPARENT, 20 );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$history = self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_name'   => 'history',
				'post_parent' => $this->about,
			]
		);
		wp_trash_post( $this->about );
		$this->assertSame( $this->company, (int) get_post( $history )->post_parent, 'Precondition: the trash moved the subpages up.' );
		$this->assertNull( $this->resolver->resolve( 'page', 'company/about' ), 'Precondition: the trashed page\'s own URL is not redirected.' );

		$newer = self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_name'   => 'about',
				'post_parent' => $this->company,
			]
		);
		wp_update_post(
			[
				'ID'        => $newer,
				'post_name' => 'about-us',
			]
		);

		$temporary = false;
		$this->assertSame( $newer, $this->resolver->resolve( 'page', 'company/about', $temporary )->ID );
		$this->assertTrue( $temporary, 'A restore of the page in the trash would take the URL back.' );

		wp_delete_post( $this->about, true );

		$temporary = false;
		$this->assertSame( $newer, $this->resolver->resolve( 'page', 'company/about', $temporary )->ID );
		$this->assertFalse( $temporary );
	}

	public function test_an_older_entry_of_a_page_not_its_ancestor_does_not_beat_a_page_in_the_trash(): void {
		$elsewhere = self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_name'   => 'elsewhere',
				'post_parent' => $this->company,
			]
		);
		// An entry from before a page that takes a path drops the others.
		$this->ledger->record( $elsewhere, 'company/about' );
		wp_trash_post( $this->about );
		$this->ledger->record( $this->about, 'company/about' );

		$this->assertNull( $this->resolver->resolve( 'page', 'company/about' ), 'The page in the trash holds the newest entry.' );
	}

	public function test_a_url_target_answers_its_exact_old_path_with_its_url(): void {
		$this->ledger->record( $this->about, 'old/about' );
		$this->ledger->set_target( 'page', 'old/about', $this->about, 'https://example.org/about/', 0 );

		$code = null;
		$this->assertSame( 'https://example.org/about/', $this->resolver->resolve( 'page', '/Old/About/', $temporary, $code ) );
		$this->assertFalse( $temporary );
		$this->assertSame( 0, $code, 'No code was chosen: the automatic one applies.' );
	}

	public function test_past_a_url_target_a_longer_path_resolves_as_if_the_record_were_not_there(): void {
		$this->ledger->record( $this->company, 'old' );
		$this->ledger->record( $this->about, 'old/about' );
		$this->assertSame( $this->about, $this->resolver->resolve( 'page', 'old/about' )->ID, 'Precondition: the record answers.' );

		$this->ledger->set_target( 'page', 'old/about', $this->about, 'https://example.org/about/', 0 );

		$this->assertSame( $this->team, $this->resolver->resolve( 'page', 'old/about/team' )->ID, 'The shorter prefix answers: old/about/team → company/about/team.' );
		$this->ledger->forget( $this->company, 'old' );
		$this->assertNull( $this->resolver->resolve( 'page', 'old/about/team' ), 'With no shorter prefix, nothing answers.' );
	}

	public function test_a_chosen_code_replaces_the_automatic_one_and_the_first_along_a_chain_wins(): void {
		$this->ledger->record( $this->company, 'legacy' );
		$this->ledger->record( $this->about, 'company/old-about' );
		$this->ledger->set_target( 'page', 'company/old-about', $this->about, '', 301 );

		// Round 1: legacy → company, so company/old-about/team; round 2: company/old-about → company/about.
		$this->assertSame( $this->team, $this->resolver->resolve( 'page', 'legacy/old-about/team', $temporary, $code )->ID );
		$this->assertSame( 301, $code );

		$this->ledger->set_target( 'page', 'legacy', $this->company, '', 302 );
		$this->assertSame( $this->team, $this->resolver->resolve( 'page', 'legacy/old-about/team', $temporary, $code )->ID );
		$this->assertSame( 302, $code, 'The first record used that has a chosen code decides.' );
		$this->assertFalse( $temporary, 'A chosen code leaves the automatic flag as it was.' );
	}

	public function test_a_root_entry_s_override_is_read_from_its_option(): void {
		$this->ledger->record_root( 'page', 'archive' );
		$this->assertSame( $this->company, $this->resolver->resolve( 'page', 'archive/company' )->ID, 'Precondition: the root entry maps archive/company to company.' );

		$this->ledger->set_target( 'page', 'archive', 0, 'https://example.org/archive/', 302 );

		$this->assertSame( 'https://example.org/archive/', $this->resolver->resolve( 'page', 'archive', $temporary, $code ) );
		$this->assertSame( 302, $code );
		$this->assertNull( $this->resolver->resolve( 'page', 'archive/company' ), 'A URL target covers its exact path only.' );
	}

	public function test_a_url_target_on_a_page_in_the_trash_is_not_served(): void {
		$this->ledger->record( $this->about, 'old-about' );
		$this->ledger->set_target( 'page', 'old-about', $this->about, 'https://example.org/', 0 );
		wp_trash_post( $this->about );

		$this->assertNull( $this->resolver->resolve( 'page', 'old-about' ), 'A record lives with its page: in the trash, nothing answers.' );
	}

	public function test_the_batch_resolver_reads_overrides_as_the_live_one_does(): void {
		$this->ledger->record( $this->company, 'old' );
		$this->ledger->record( $this->about, 'old/about' );
		$this->ledger->set_target( 'page', 'old/about', $this->about, 'https://example.org/about/', 0 );
		$this->ledger->set_target( 'page', 'old', $this->company, '', 302 );
		$this->ledger->record_root( 'page', 'archive' );
		$this->ledger->set_target( 'page', 'archive', 0, 'https://example.org/archive/', 301 );

		$facts     = [];
		$temporary = [];
		$chosen    = [];
		$served    = Batch_Resolver::served( [ 'old/about', 'old/about/team', 'archive', 'archive/company' ], 'page', $facts, $temporary, $chosen );

		ksort( $served );
		ksort( $chosen );
		$this->assertSame(
			[
				'archive'         => Batch_Resolver::URL,
				'archive/company' => 0,
				'old/about'       => Batch_Resolver::URL,
				'old/about/team'  => $this->team,
			],
			$served
		);
		$this->assertSame(
			[
				'archive'        => [
					'url'  => 'https://example.org/archive/',
					'code' => 301,
				],
				'old/about'      => [
					'url'  => 'https://example.org/about/',
					'code' => 0,
				],
				'old/about/team' => [
					'url'  => '',
					'code' => 302,
				],
			],
			$chosen
		);
		foreach ( [ 'old/about', 'old/about/team', 'archive', 'archive/company' ] as $path ) {
			$live = $this->resolver->resolve( 'page', $path, $live_temporary, $live_code );
			$this->assertSame( is_string( $live ) ? $live : ( null === $live ? 0 : $live->ID ), is_string( $live ) ? $chosen[ $path ]['url'] : $served[ $path ], $path );
			$this->assertSame( $live_code, $chosen[ $path ]['code'] ?? 0, $path );
		}
	}
}
