<?php
/**
 * Yoast SEO's indexables after the trash flow moves subpages.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Compat\Yoast;
use WP_UnitTestCase;
use Yoast\WP\SEO\Integrations\Watchers\Indexable_Post_Watcher;

/**
 * @covers \HierarchyGuard\Compat\Yoast
 */
final class YoastCompatTest extends WP_UnitTestCase {

	use Trash_Fixtures;

	/**
	 * Page IDs.
	 *
	 * @var array<string,int>
	 */
	private array $ids;

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		require_once __DIR__ . '/stubs/yoast-watcher.php';
		require_once __DIR__ . '/stubs/yoast-main.php';
	}

	public function set_up(): void {
		parent::set_up();
		$this->set_permalink_structure( '/%postname%/' );
		$this->ids = $this->make_tree();

		YoastSEO()->classes->unreachable = false;

		$watcher             = $this->watcher();
		$watcher->built      = [];
		$watcher->broken     = false;
		$watcher->broken_for = [];
	}

	public function tear_down(): void {
		wp_clear_scheduled_hook( Yoast::HOOK );
		$this->set_permalink_structure( '' );
		parent::tear_down();
	}

	public function test_a_moved_page_without_subpages_is_rebuilt_at_once_and_one_with_subpages_in_a_run_of_its_own(): void {
		wp_trash_post( $this->ids['about'] );

		$this->assertSame( $this->ids['company'], (int) get_post( $this->ids['team'] )->post_parent, 'Precondition: the default policy moved them up.' );
		$this->assertSame( [ $this->ids['history'] ], $this->watcher()->built, 'Team has a subpage, so the step leaves it.' );
		$this->assertSame( '1', get_post_meta( $this->ids['team'], Yoast::PENDING, true ) );
		$this->assertNotFalse( wp_next_scheduled( Yoast::HOOK ) );

		( new Yoast() )->rebuild_pending();

		$this->assertSame( [ $this->ids['history'], $this->ids['team'] ], $this->watcher()->built );
		$this->assertSame( '', get_post_meta( $this->ids['team'], Yoast::PENDING, true ) );
		$this->assertFalse( wp_next_scheduled( Yoast::HOOK ), 'Nothing is left for another run.' );
	}

	public function test_the_subpages_a_restore_moves_back_get_their_indexables_rebuilt_again(): void {
		wp_trash_post( $this->ids['about'] );
		( new Yoast() )->rebuild_pending();
		$this->watcher()->built = [];

		wp_untrash_post( $this->ids['about'] );
		( new Yoast() )->rebuild_pending();

		$this->assertSame( $this->ids['about'], (int) get_post( $this->ids['team'] )->post_parent, 'Precondition: the restore moved them back.' );
		$this->assertSame( [ $this->ids['history'], $this->ids['team'] ], $this->watcher()->built );
	}

	public function test_a_page_yoast_fails_on_leaves_the_others_of_the_step_rebuilt(): void {
		$careers                     = $this->page( 'careers', $this->ids['about'] );
		$this->watcher()->broken_for = [ $this->ids['history'] ];

		$reports = $this->reports( fn() => wp_trash_post( $this->ids['about'] ) );

		$this->assertSame( [ $careers ], $this->watcher()->built );
		if ( null !== $reports ) {
			$this->assertSame( [ "HierarchyGuard\\Compat\\Yoast::build(): Yoast SEO could not update its SEO data for post {$this->ids['history']}: A content filter failed." ], $reports );
		}
	}

	public function test_a_yoast_whose_internals_changed_leaves_the_trash_to_finish(): void {
		$this->watcher()->broken = true;

		$reports = $this->reports(
			function (): void {
				wp_trash_post( $this->ids['about'] );
				( new Yoast() )->rebuild_pending();
			}
		);

		$this->assertSame( $this->ids['company'], (int) get_post( $this->ids['team'] )->post_parent );
		$this->assertSame( $this->ids['company'], (int) get_post( $this->ids['history'] )->post_parent );
		$this->assertSame( '', get_post_meta( $this->ids['team'], Yoast::PENDING, true ), 'Tried once, then left to Yoast.' );
		if ( null !== $reports ) {
			$this->assertSame(
				[
					"HierarchyGuard\\Compat\\Yoast::build(): Yoast SEO could not update its SEO data for post {$this->ids['history']}: Changed internals.",
					"HierarchyGuard\\Compat\\Yoast::build(): Yoast SEO could not update its SEO data for post {$this->ids['team']}: Changed internals.",
				],
				$reports
			);
		}
	}

	public function test_a_watcher_that_cannot_be_reached_is_reported_and_no_page_waits(): void {
		$reports = $this->reports(
			function (): void {
				YoastSEO()->classes->unreachable = true;
				wp_trash_post( $this->ids['about'] );
				( new Yoast() )->rebuild_pending();
				YoastSEO()->classes->unreachable = false;
			}
		);

		$this->assertSame( $this->ids['company'], (int) get_post( $this->ids['team'] )->post_parent, 'Precondition: the trash moved them up.' );
		$this->assertSame( [], $this->watcher()->built );
		$this->assertSame( '', get_post_meta( $this->ids['team'], Yoast::PENDING, true ) );
		$this->assertFalse( wp_next_scheduled( Yoast::HOOK ) );
		if ( null !== $reports ) {
			$this->assertSame(
				[
					'HierarchyGuard\\Compat\\Yoast::watcher(): Yoast SEO is active, but its post watcher could not be reached: The container is not ready.',
					'HierarchyGuard\\Compat\\Yoast::watcher(): Yoast SEO is active, but its post watcher could not be reached: The container is not ready.',
				],
				$reports,
				'Once for the step, once for the run.'
			);
		}
	}

	public function test_a_revision_is_not_a_subpage_that_makes_a_page_wait(): void {
		wp_save_post_revision( $this->ids['history'] );
		self::factory()->post->create(
			[
				'post_type'   => 'revision',
				'post_status' => 'inherit',
				'post_parent' => $this->ids['history'],
				'post_name'   => "{$this->ids['history']}-revision-v1",
			]
		);

		wp_trash_post( $this->ids['about'] );

		$this->assertContains( $this->ids['history'], $this->watcher()->built );
	}

	public function test_activation_resumes_pages_still_waiting(): void {
		wp_trash_post( $this->ids['about'] );
		wp_clear_scheduled_hook( Yoast::HOOK );

		Yoast::resume();

		$this->assertNotFalse( wp_next_scheduled( Yoast::HOOK ) );
	}

	public function test_the_pending_flag_stays_out_of_exports(): void {
		$yoast = new Yoast();

		$this->assertTrue( $yoast->skip_on_export( false, Yoast::PENDING ) );
		$this->assertFalse( $yoast->skip_on_export( false, '_hierarchy_guard_yoast' ) );
		$this->assertTrue( $yoast->skip_on_export( true, 'other' ) );
	}

	/**
	 * What a callback reports through wp_trigger_error(), as
	 * "function(): message", recorded rather than raised. WordPress 7.0 and
	 * later announce each report even with WP_DEBUG off, as in the tests;
	 * older versions announce none, so this returns null there.
	 *
	 * @param callable $run The callback.
	 * @return string[]|null
	 */
	private function reports( callable $run ): ?array {
		$reports = [];
		$record  = static function ( string $function_name, string $message ) use ( &$reports ): void {
			$reports[] = "{$function_name}(): {$message}";
		};
		add_action( 'wp_trigger_error_always_run', $record, 10, 2 );
		add_filter( 'wp_trigger_error_trigger_error', '__return_false' );
		try {
			$run();
		} finally {
			remove_action( 'wp_trigger_error_always_run', $record, 10 );
			remove_filter( 'wp_trigger_error_trigger_error', '__return_false' );
		}
		return version_compare( get_bloginfo( 'version' ), '7.0', '>=' ) ? $reports : null;
	}

	/**
	 * The stub watcher YoastSEO() hands out.
	 *
	 * @return Indexable_Post_Watcher
	 */
	private function watcher(): Indexable_Post_Watcher {
		return YoastSEO()->classes->get( Indexable_Post_Watcher::class );
	}
}
