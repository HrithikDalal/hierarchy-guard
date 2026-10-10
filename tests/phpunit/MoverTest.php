<?php
/**
 * Raw re-parenting.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Trash\Mover;
use WP_UnitTestCase;

/**
 * @covers \HierarchyGuard\Trash\Mover
 */
final class MoverTest extends WP_UnitTestCase {

	use Trash_Fixtures;

	/**
	 * Page IDs.
	 *
	 * @var array<string,int>
	 */
	private array $ids;

	private Mover $mover;

	public function set_up(): void {
		parent::set_up();
		$this->set_permalink_structure( '/%postname%/' );
		$this->ids   = $this->make_tree();
		$this->mover = new Mover();
	}

	public function tear_down(): void {
		$this->set_permalink_structure( '' );
		parent::tear_down();
	}

	public function test_moves_a_post_without_touching_its_modified_date(): void {
		global $wpdb;
		$wpdb->update(
			$wpdb->posts,
			[
				'post_modified'     => '2001-01-01 00:00:00',
				'post_modified_gmt' => '2001-01-01 00:00:00',
			],
			[ 'ID' => $this->ids['team'] ]
		);
		clean_post_cache( $this->ids['team'] );

		$result = $this->mover->move( $this->ids['team'], $this->ids['company'] );

		$this->assertTrue( $result['moved'] );
		$this->assertSame( $this->ids['company'], (int) get_post( $this->ids['team'] )->post_parent );
		$this->assertSame( '2001-01-01 00:00:00', get_post( $this->ids['team'] )->post_modified );
		$this->assertSame( 'company/about/team', $result['old_path'] );
		$this->assertSame( 'team', $result['slug'] );
		$this->assertFalse( $result['renamed'] );
		$this->assertTrue( $result['recordable'] );
	}

	public function test_a_taken_slug_gets_a_number(): void {
		$this->page( 'team', $this->ids['company'] );

		$result = $this->mover->move( $this->ids['team'], $this->ids['company'] );

		$this->assertSame( 'team-2', get_post( $this->ids['team'] )->post_name );
		$this->assertTrue( $result['renamed'] );
		$this->assertSame( 'team', $result['old_slug'] );
		$this->assertSame( 'team-2', $result['slug'] );
	}

	public function test_a_draft_gets_a_unique_slug_too(): void {
		$this->page( 'team', $this->ids['company'] );
		$draft = $this->page( 'team', $this->ids['history'], 'draft' );

		$result = $this->mover->move( $draft, $this->ids['company'] );

		$this->assertSame( 'team-2', $result['slug'], 'A duplicate draft slug would hide the published page.' );
		$this->assertFalse( $result['recordable'], 'A draft has no public URL to record.' );
	}

	public function test_the_old_path_ignores_the_trash_suffix(): void {
		$this->trash_raw( $this->ids['about'] );
		$this->assertSame( 'company/about/team', $this->mover->move( $this->ids['team'], $this->ids['company'] )['old_path'] );
	}

	public function test_a_preferred_slug_is_used_when_free(): void {
		$taken = $this->page( 'team', $this->ids['company'] );
		$this->mover->move( $this->ids['team'], $this->ids['company'] );
		$this->assertSame( 'team-2', get_post( $this->ids['team'] )->post_name, 'Precondition.' );

		wp_delete_post( $taken, true );
		$this->mover->move( $this->ids['team'], $this->ids['about'], 'team' );

		$this->assertSame( 'team', get_post( $this->ids['team'] )->post_name );
	}

	public function test_reparent_changes_only_the_parent(): void {
		$this->trash_raw( $this->ids['team'] );

		$this->assertTrue( $this->mover->reparent( $this->ids['team'], $this->ids['company'] ) );

		$this->assertSame( $this->ids['company'], (int) get_post( $this->ids['team'] )->post_parent );
		$this->assertSame( 'team__trashed', get_post( $this->ids['team'] )->post_name );
	}

	public function test_a_missing_post_is_reported_unmoved(): void {
		$this->assertFalse( $this->mover->move( 999999, 0 )['moved'] );
	}

	public function test_a_reserved_slug_is_not_taken_under_the_new_parent(): void {
		$result = $this->mover->move( $this->ids['team'], $this->ids['company'], '', 'team' );

		$this->assertSame( 'team-2', $result['slug'] );
		$this->assertTrue( $result['renamed'] );
		$this->assertSame( 'history', $this->mover->move( $this->ids['history'], $this->ids['company'], '', 'team' )['slug'], 'Other slugs are unaffected.' );
	}
}
