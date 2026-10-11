<?php
/**
 * The Hierarchy box in the classic editor.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Admin\Meta_Box;
use HierarchyGuard\Overview;
use HierarchyGuard\Post_Types;
use HierarchyGuard\Redirects\Providers;
use HierarchyGuard\Settings;
use WP_UnitTestCase;

/**
 * @covers \HierarchyGuard\Admin\Meta_Box
 */
final class MetaBoxTest extends WP_UnitTestCase {

	use Trash_Fixtures;

	/**
	 * Page IDs.
	 *
	 * @var array<string,int>
	 */
	private array $ids;

	private Meta_Box $box;

	public function set_up(): void {
		parent::set_up();
		require_once ABSPATH . 'wp-admin/includes/template.php';
		delete_option( Settings::OPTION );
		Post_Types::reset();
		$this->set_permalink_structure( '/%postname%/' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->ids = $this->make_tree();
		$this->box = new Meta_Box( new Overview( new Providers() ) );
	}

	public function tear_down(): void {
		global $wp_meta_boxes;
		$wp_meta_boxes = [];
		$this->set_permalink_structure( '' );
		parent::tear_down();
	}

	/**
	 * The box's HTML for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private function html( int $post_id ): string {
		ob_start();
		$this->box->render( get_post( $post_id ) );
		return (string) ob_get_clean();
	}

	public function test_the_box_is_added_for_supported_types_in_the_classic_editor_only(): void {
		global $wp_meta_boxes;

		$this->box->add( 'page', get_post( $this->ids['about'] ) );
		$this->box->add( 'post', get_post( self::factory()->post->create() ) );

		$box = $wp_meta_boxes['page']['side']['default']['hierarchy-guard'] ?? null;
		$this->assertIsArray( $box );
		$this->assertTrue( $box['args']['__back_compat_meta_box'], 'The block editor shows the panel instead.' );
		$this->assertArrayNotHasKey( 'post', $wp_meta_boxes );
	}

	public function test_the_box_shows_parents_children_and_the_trash_policy(): void {
		$html = $this->html( $this->ids['about'] );

		$this->assertStringContainsString( 'data-serving="0"', $html, 'The classic confirmation reads whether this page is redirected to.' );

		$this->assertStringContainsString( 'data-descendants="3"', $html );
		$this->assertStringContainsString( 'data-status="publish"', $html );
		$this->assertStringContainsString( '>Company</a>', $html );
		$this->assertStringContainsString( '<strong>Subpages</strong>', $html );
		$this->assertStringContainsString( '2 direct · 3 in total', $html );
		$this->assertStringContainsString( '>Team</a><span aria-hidden="true"> · </span>1 subpage</li>', $html );
		$this->assertStringContainsString( 'Trashing this page moves its 2 subpages under “Company”.', $html );
	}

	public function test_separators_are_not_read_aloud(): void {
		$html = $this->html( $this->ids['leadership'] );

		$this->assertStringContainsString( '>Company</a><span aria-hidden="true"> › </span><a ', $html );
		$this->assertStringNotContainsString( '</a> › ', $html );

		wp_update_post(
			[
				'ID'          => $this->ids['history'],
				'post_status' => 'draft',
			]
		);
		$this->assertStringContainsString( '>History</a><span aria-hidden="true"> — </span>Draft</li>', $this->html( $this->ids['about'] ) );
	}

	public function test_a_parent_in_the_trash_is_not_linked(): void {
		add_filter( 'hierarchy_guard_trash_policy', static fn(): string => Settings::POLICY_KEEP );
		wp_trash_post( $this->ids['team'] );

		$html = $this->html( $this->ids['leadership'] );

		$this->assertStringContainsString( '<span aria-hidden="true"> › </span>Team</p>', $html, 'Core refuses to edit a page in the Trash.' );
		$this->assertStringContainsString( '>About</a>', $html );
	}

	public function test_the_box_says_whether_the_page_is_redirected_to(): void {
		update_option( Settings::OPTION, [ 'serve_redirects' => true ] );

		$this->assertStringContainsString( 'data-serving="1"', $this->html( $this->ids['about'] ) );
	}

	public function test_a_capped_count_links_to_the_list(): void {
		add_filter( 'hierarchy_guard_count_cap', static fn(): int => 2 );

		$html = $this->html( $this->ids['about'] );

		remove_all_filters( 'hierarchy_guard_count_cap' );
		$this->assertStringContainsString( 'View all 2+ subpages in the list', $html );
	}

	public function test_a_top_level_page_without_subpages(): void {
		$html = $this->html( $this->page( 'lonely' ) );

		$this->assertStringContainsString( 'None. This is a top-level page.', $html );
		$this->assertStringContainsString( 'No subpages.', $html );
		$this->assertStringNotContainsString( 'Trashing this page', $html );
	}

	public function test_the_trash_sentence_follows_the_policy(): void {
		$data = [
			'children_total' => 2,
			'descendants'    => [
				'count'  => 5,
				'capped' => false,
			],
			'trash_to'       => null,
		];

		$this->assertSame( 'Trashing this page moves its 5 subpages to the Trash too.', Meta_Box::trash_sentence( $data + [ 'trash_policy' => Settings::POLICY_TRASH_CHILDREN ] ) );
		$this->assertSame( 'Trashing this page leaves its 2 subpages where they are.', Meta_Box::trash_sentence( $data + [ 'trash_policy' => Settings::POLICY_KEEP ] ) );
		$this->assertSame( 'Trashing this page makes its 2 subpages top-level pages.', Meta_Box::trash_sentence( $data + [ 'trash_policy' => Settings::POLICY_REPARENT ] ) );
	}

	public function test_a_long_list_of_children_links_to_the_list(): void {
		for ( $i = 1; $i <= 10; $i++ ) {
			$this->page( 'extra-' . $i, $this->ids['about'] );
		}

		$this->assertStringContainsString( 'View all 13 subpages in the list', $this->html( $this->ids['about'] ) );
	}

	public function test_a_capped_count_in_the_trash_sentence_is_a_lower_bound(): void {
		$this->page( 'extra', $this->ids['about'] );
		add_filter( 'hierarchy_guard_count_cap', static fn(): int => 2 );
		add_filter( 'hierarchy_guard_trash_policy', static fn(): string => Settings::POLICY_KEEP );

		$html = $this->html( $this->ids['about'] );

		remove_all_filters( 'hierarchy_guard_count_cap' );
		remove_all_filters( 'hierarchy_guard_trash_policy' );
		$this->assertStringContainsString( 'Trashing this page leaves its 2+ subpages where they are.', $html );
	}

	public function test_the_box_shows_where_the_page_is_not_its_old_urls(): void {
		wp_update_post(
			[
				'ID'        => $this->ids['about'],
				'post_name' => 'about-us',
			]
		);

		$html = $this->html( $this->ids['about'] );

		$this->assertStringNotContainsString( 'Old URLs', $html );
		$this->assertStringNotContainsString( '/company/about/', $html );
		$this->assertStringNotContainsString( '/csv', $html );
	}

	public function test_the_list_link_shows_when_subpages_outnumber_the_children_listed(): void {
		$this->assertStringContainsString( 'View all 3 subpages in the list', $this->html( $this->ids['about'] ) );
	}
}
