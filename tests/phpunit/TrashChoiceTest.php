<?php
/**
 * The trash policy the Pages list dialog sends.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Admin\Trash_Choice;
use HierarchyGuard\Post_Types;
use HierarchyGuard\Settings;
use HierarchyGuard\Trash\Trash_Handler;
use WP_UnitTestCase;

/**
 * @covers \HierarchyGuard\Admin\Trash_Choice
 */
final class TrashChoiceTest extends WP_UnitTestCase {

	use Trash_Fixtures;

	/**
	 * Page IDs.
	 *
	 * @var array<string,int>
	 */
	private array $ids;

	public function set_up(): void {
		parent::set_up();
		delete_option( Settings::OPTION );
		Post_Types::reset();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->ids = $this->make_tree();
		set_current_screen( 'edit-page' );
	}

	public function tear_down(): void {
		$_REQUEST = [];
		unset( $GLOBALS['pagenow'] );
		set_current_screen( 'front' );
		parent::tear_down();
	}

	/**
	 * Sets up a request from the row action's trash link.
	 *
	 * @param int    $post_id Page the link trashes.
	 * @param string $policy  Chosen policy.
	 * @param string $nonce   Nonce action, by default core's.
	 */
	private function row_request( int $post_id, string $policy, string $nonce = '' ): void {
		$GLOBALS['pagenow'] = 'post.php';
		$_REQUEST           = [
			'action'            => 'trash',
			'post'              => (string) $post_id,
			'_wpnonce'          => wp_create_nonce( '' !== $nonce ? $nonce : 'trash-post_' . $post_id ),
			Trash_Choice::PARAM => $policy,
		];
	}

	public function test_the_row_link_s_choice_applies_to_its_page(): void {
		$this->row_request( $this->ids['about'], Settings::POLICY_KEEP );

		$this->assertSame( Settings::POLICY_KEEP, Trash_Choice::chosen( $this->ids['about'] ) );
		$this->assertSame( Settings::POLICY_KEEP, Trash_Handler::policy_for( get_post( $this->ids['about'] ) ), 'The plugin hooks the filter.' );
		$this->assertNull( Trash_Choice::chosen( $this->ids['team'] ), 'Another page keeps the default.' );
	}

	public function test_a_choice_without_core_s_nonce_is_ignored(): void {
		$this->row_request( $this->ids['about'], Settings::POLICY_KEEP, 'something-else' );

		$this->assertNull( Trash_Choice::chosen( $this->ids['about'] ) );
	}

	public function test_an_unknown_policy_is_ignored(): void {
		$this->row_request( $this->ids['about'], 'delete_everything' );

		$this->assertNull( Trash_Choice::chosen( $this->ids['about'] ) );
	}

	public function test_the_bulk_form_s_choice_applies_to_the_selected_pages(): void {
		$GLOBALS['pagenow'] = 'edit.php';
		$_REQUEST           = [
			'action'            => 'trash',
			'post'              => [ (string) $this->ids['about'], (string) $this->ids['history'] ],
			'_wpnonce'          => wp_create_nonce( 'bulk-posts' ),
			Trash_Choice::PARAM => Settings::POLICY_TRASH_CHILDREN,
		];

		$this->assertSame( Settings::POLICY_TRASH_CHILDREN, Trash_Choice::chosen( $this->ids['about'] ) );
		$this->assertNull( Trash_Choice::chosen( $this->ids['team'] ), 'Not selected.' );

		$_REQUEST['action'] = 'edit';
		$this->assertNull( Trash_Choice::chosen( $this->ids['about'] ), 'Not a trash.' );

		$_REQUEST['action']  = '-1';
		$_REQUEST['action2'] = 'trash';
		$this->assertNull( Trash_Choice::chosen( $this->ids['about'] ), 'Core reads only `action`, so this request trashes nothing.' );
	}

	public function test_outside_the_two_screens_the_choice_is_ignored(): void {
		$this->row_request( $this->ids['about'], Settings::POLICY_KEEP );
		$GLOBALS['pagenow'] = 'admin-ajax.php';

		$this->assertNull( Trash_Choice::chosen( $this->ids['about'] ) );
	}

	public function test_a_trash_from_the_row_link_uses_the_chosen_policy(): void {
		$this->row_request( $this->ids['about'], Settings::POLICY_KEEP );

		wp_trash_post( $this->ids['about'] );

		$this->assertSame( $this->ids['about'], (int) get_post_field( 'post_parent', $this->ids['team'] ), 'Kept where it was.' );
	}

	public function test_an_explicit_choice_wins_over_other_filters(): void {
		add_filter( 'hierarchy_guard_trash_policy', static fn(): string => Settings::POLICY_REPARENT );
		$this->row_request( $this->ids['about'], Settings::POLICY_KEEP );

		$this->assertSame( Settings::POLICY_KEEP, Trash_Handler::policy_for( get_post( $this->ids['about'] ) ) );
	}
}
