<?php
/**
 * The Hierarchy box in the classic editor.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Admin\Meta_Box;
use HierarchyGuard\Ledger;
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
		$this->box = new Meta_Box( new Overview( new Ledger(), new Providers() ) );
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

		$this->assertStringContainsString( 'data-descendants="3"', $html );
		$this->assertStringContainsString( 'data-status="publish"', $html );
		$this->assertStringContainsString( '>Company</a>', $html );
		$this->assertStringContainsString( '<strong>Subpages</strong>', $html );
		$this->assertStringContainsString( '2 direct · 3 in total', $html );
		$this->assertStringContainsString( '>Team</a> · 1 subpage</li>', $html );
		$this->assertStringContainsString( 'Trashing this page moves its 2 subpages under “Company”.', $html );
	}

	public function test_a_top_level_page_without_subpages(): void {
		$html = $this->html( $this->page( 'lonely' ) );

		$this->assertStringContainsString( 'None. This is a top-level page.', $html );
		$this->assertStringContainsString( 'No subpages.', $html );
		$this->assertStringNotContainsString( 'Trashing this page', $html );
	}

	public function test_old_urls_are_listed_with_what_handles_them(): void {
		wp_update_post(
			[
				'ID'        => $this->ids['about'],
				'post_name' => 'about-us',
			]
		);

		$html = $this->html( $this->ids['about'] );
		$this->assertStringContainsString( '<code>/company/about/</code> · Not redirected · <a href="', $html );
		$this->assertStringContainsString( '/hierarchy-guard/v1/posts/' . $this->ids['about'] . '/csv', $html );
		$this->assertStringContainsString( 'aria-label="Download the redirects for /company/about/ (CSV)" download>Download redirects (CSV)</a>', $html );

		update_option( Settings::OPTION, [ 'serve_redirects' => true ] );
		$this->assertStringContainsString( '<code>/company/about/</code> · Redirects here', $this->html( $this->ids['about'] ) );
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

		$this->assertStringContainsString( 'View all 13 in the list', $this->html( $this->ids['about'] ) );
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

	public function test_an_old_url_of_a_page_nobody_can_view_is_not_redirected(): void {
		update_option( Settings::OPTION, [ 'serve_redirects' => true ] );
		wp_update_post(
			[
				'ID'          => $this->ids['about'],
				'post_name'   => 'about-us',
				'post_status' => 'private',
			]
		);

		$html = $this->html( $this->ids['about'] );
		$this->assertStringContainsString( '<code>/company/about/</code> · Not redirected · <a href="', $html );
		$this->assertStringNotContainsString( 'Redirects here', $html );
	}

	public function test_an_old_url_another_published_page_now_uses_is_marked_so(): void {
		wp_update_post(
			[
				'ID'        => $this->ids['about'],
				'post_name' => 'about-us',
			]
		);
		$this->page( 'about', $this->ids['company'] );

		$this->assertStringContainsString( '<code>/company/about/</code> · Used by another page · <a href="', $this->html( $this->ids['about'] ) );
	}

	public function test_an_old_url_names_the_redirect_plugin_that_can_add_it(): void {
		add_filter(
			'hierarchy_guard_redirect_providers',
			static function ( array $providers ): array {
				$providers['custom'] = [
					'name'   => 'Custom Redirects',
					'active' => true,
					'url'    => admin_url( 'admin.php?page=custom-redirects' ),
					'csv'    => 'generic',
				];
				return $providers;
			}
		);
		wp_update_post(
			[
				'ID'        => $this->ids['about'],
				'post_name' => 'about-us',
			]
		);

		$html = $this->html( $this->ids['about'] );

		remove_all_filters( 'hierarchy_guard_redirect_providers' );
		$this->assertStringContainsString( '<code>/company/about/</code> · Add in Custom Redirects · <a href="', $html );
	}
}
