<?php
/**
 * Smoke test: the plugin loads inside the test suite.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Batch\Queue;
use HierarchyGuard\Ledger;
use HierarchyGuard\Meta_Guard;
use HierarchyGuard\Plugin;
use HierarchyGuard\Redirects\Server;
use HierarchyGuard\Subtree;
use HierarchyGuard\Trash\Operations;
use WP_UnitTestCase;

/**
 * @covers \HierarchyGuard\Plugin
 */
final class PluginTest extends WP_UnitTestCase {

	public function test_plugin_is_loaded(): void {
		$this->assertTrue( defined( 'HIERARCHY_GUARD_VERSION' ) );
		$this->assertSame( '0.1.0', HIERARCHY_GUARD_VERSION );
		$this->assertInstanceOf( Plugin::class, Plugin::instance() );
		$this->assertSame( Plugin::instance(), Plugin::instance() );
	}

	public function test_a_site_is_set_up_once_per_version_and_a_fresh_one_schedules_nothing(): void {
		delete_option( Plugin::VERSION_OPTION );
		wp_clear_scheduled_hook( Ledger::REBUILD_HOOK );
		wp_clear_scheduled_hook( Queue::WATCHDOG );

		Plugin::maybe_upgrade();

		$this->assertSame( HIERARCHY_GUARD_VERSION, get_option( Plugin::VERSION_OPTION ) );
		$this->assertFalse( wp_next_scheduled( Ledger::REBUILD_HOOK ), 'No entry, so no rebuild.' );
		$this->assertFalse( wp_next_scheduled( Queue::WATCHDOG ), 'No job, so no watchdog.' );
		$queries = get_num_queries();
		Plugin::maybe_upgrade();
		$this->assertSame( $queries, get_num_queries(), 'Set up already: an autoloaded read.' );
	}

	public function test_activation_sets_the_site_up(): void {
		$root = self::factory()->post->create( [ 'post_type' => 'page' ] );
		$jobs = new Queue();
		$jobs->save( Operations::new_job( Operations::TYPE_REPARENT, $root, 'page', 0, [ 'to' => 0 ] ) );
		delete_option( Plugin::VERSION_OPTION );
		wp_clear_scheduled_hook( Queue::WATCHDOG );

		do_action( 'activate_' . plugin_basename( HIERARCHY_GUARD_FILE ), false ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's activation hook for this plugin.

		$this->assertNotFalse( wp_next_scheduled( Queue::WATCHDOG ), 'The job waiting from before is resumed.' );
		$this->assertSame( HIERARCHY_GUARD_VERSION, get_option( Plugin::VERSION_OPTION ) );
		$jobs->delete( $root );
		wp_clear_scheduled_hook( Queue::WATCHDOG );
	}

	public function test_an_update_schedules_the_rebuild_a_site_with_entries_needs(): void {
		$page = self::factory()->post->create( [ 'post_type' => 'page' ] );
		Meta_Guard::writing( static fn() => add_post_meta( $page, Ledger::key( 'page', 'old' ), 'old', true ) );
		wp_clear_scheduled_hook( Ledger::REBUILD_HOOK );
		update_option( Plugin::VERSION_OPTION, '0.0.1', true );

		Plugin::maybe_upgrade();

		$this->assertNotFalse( wp_next_scheduled( Ledger::REBUILD_HOOK ) );
		$this->assertSame( HIERARCHY_GUARD_VERSION, get_option( Plugin::VERSION_OPTION ) );
	}

	public function test_a_subsite_is_set_up_again_after_the_plugin_is_network_activated_again(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only: run with npm run test:php:multisite.' );
		}
		$root = self::factory()->post->create( [ 'post_type' => 'page' ] );
		$jobs = new Queue();
		$jobs->save( Operations::new_job( Operations::TYPE_REPARENT, $root, 'page', 0, [ 'to' => 0 ] ) );
		Plugin::setup();
		wp_clear_scheduled_hook( Queue::WATCHDOG );

		Plugin::maybe_upgrade();
		$this->assertFalse( wp_next_scheduled( Queue::WATCHDOG ), 'Same version, same network activation: nothing to do.' );

		$again = static fn(): array => [ plugin_basename( HIERARCHY_GUARD_FILE ) => 1700000000 ];
		add_filter( 'pre_site_option_active_sitewide_plugins', $again );
		Plugin::maybe_upgrade();
		remove_filter( 'pre_site_option_active_sitewide_plugins', $again );

		$this->assertNotFalse( wp_next_scheduled( Queue::WATCHDOG ), 'The job waiting here is resumed.' );
		$this->assertSame( 1700000000, (int) get_option( Plugin::NETWORK_OPTION ) );
		$jobs->delete( $root );
		wp_clear_scheduled_hook( Queue::WATCHDOG );
	}

	public function test_an_integration_can_reach_and_unhook_the_plugin_s_own_objects(): void {
		$server = Plugin::instance()->get( Server::class );

		$this->assertInstanceOf( Server::class, $server );
		$this->assertSame( $server, Plugin::instance()->get( '\\' . Server::class ), 'With or without the leading backslash.' );
		$this->assertSame( Server::PRIORITY, has_action( 'template_redirect', [ $server, 'maybe_redirect' ] ) );
		$this->assertTrue( remove_action( 'template_redirect', [ $server, 'maybe_redirect' ], Server::PRIORITY ) );
		add_action( 'template_redirect', [ $server, 'maybe_redirect' ], Server::PRIORITY );
		$this->assertInstanceOf( Ledger::class, Plugin::instance()->get( Ledger::class ) );
	}

	public function test_asking_for_a_class_the_plugin_has_no_object_of_says_so_in_development(): void {
		$this->setExpectedIncorrectUsage( 'HierarchyGuard\\Plugin::get' );

		$this->assertNull( Plugin::instance()->get( 'HierarchyGuard\\Redirects\\Sever' ), 'Null, as the signature says, with a notice naming the class.' );
	}

	public function test_every_public_hook_says_since_when(): void {
		$public    = [];
		$seen      = [];
		$elsewhere = 0;
		$root      = dirname( __DIR__, 2 ) . '/includes';
		$files     = [ dirname( __DIR__, 2 ) . '/hierarchy-guard.php' ];
		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) ) as $file ) {
			$files[] = $file->getPathname();
		}
		foreach ( $files as $path ) {
			$code  = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local source file.
			$class = basename( $path, '.php' );
			preg_match_all( '#\b(?:apply_filters|do_action)(?:_ref_array|_deprecated)?\(\s*([^,)]+?)\s*[,)]#', $code, $calls, PREG_OFFSET_CAPTURE );
			foreach ( $calls[1] as [ $hook, $offset ] ) {
				$declared = str_starts_with( $hook, 'self::' );
				$hook     = str_replace( 'self::', $class . '::', $hook );
				$seen[]   = $hook;
				// The docblock right above the call's own line.
				$before = substr( $code, 0, $offset );
				$end    = (int) strrpos( $before, '*/' );
				$doc    = substr( $before, (int) strrpos( substr( $before, 0, $end ), '/**' ), $end );
				$above  = 1 === preg_match( '#\A[ \t]*\n[^\n;{}]*\z#', substr( $before, $end + 2 ) );
				if ( ! $declared && in_array( $hook, [ 'List_Table::SCRIPT', 'Tree_View::SCRIPT' ], true ) ) {
					$this->assertTrue( $above && str_contains( $doc, 'This action is documented in includes/HierarchyGuard/Admin/' . strstr( $hook, '::', true ) . '.php' ), $class . ' points ' . $hook . ' at the docblock that declares it.' );
				}
				if ( 1 !== preg_match( "#^'(hierarchy_guard_[a-z_]+)'$#", $hook, $name ) ) {
					continue;
				}
				$this->assertTrue( $above, $name[1] . ' has a docblock right above it.' );
				if ( str_contains( $doc, 'is documented in' ) ) {
					++$elsewhere;
					$this->assertSame( 'hierarchy_guard_children_reparented', $name[1], 'Only the restore\'s second call refers to another docblock.' );
					continue;
				}
				$this->assertStringContainsString( '@since 1.0.0', $doc, $name[1] );
				$public[] = $name[1];
			}
		}

		sort( $public );
		$this->assertSame(
			[
				'hierarchy_guard_batch_threshold',
				'hierarchy_guard_children_reparented',
				'hierarchy_guard_core_call_threshold',
				'hierarchy_guard_count_cap',
				'hierarchy_guard_default_trash_policy',
				'hierarchy_guard_job_finished',
				'hierarchy_guard_max_segments',
				'hierarchy_guard_post_types',
				'hierarchy_guard_redirect_providers',
				'hierarchy_guard_redirect_status',
				'hierarchy_guard_redirect_url',
				'hierarchy_guard_should_record',
				'hierarchy_guard_trash_policy',
			],
			$public,
			'The 13 public hooks, each documented once.'
		);
		$this->assertSame( 1, $elsewhere );

		// Every other call is a core hook or one of the two internal signals, each counted.
		$others = array_count_values( array_diff( $seen, array_map( static fn( string $hook ): string => "'{$hook}'", $public ), [ "'hierarchy_guard_children_reparented'" ] ) );
		ksort( $others );
		$this->assertSame(
			[
				'"edit_{$post_type}_per_page"' => 1,
				"'edit_posts_per_page'"        => 1,
				"'the_title'"                  => 1,
				"'wp_check_post_lock_window'"  => 1,
				'List_Table::SCRIPT'           => 5,
				'Tree_View::SCRIPT'            => 1,
			],
			$others
		);
		foreach ( [ 'Admin/List_Table.php', 'Admin/Tree_View.php' ] as $declares ) {
			$code = (string) file_get_contents( $root . '/HierarchyGuard/' . $declares ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local source file.
			$this->assertSame( 1, preg_match( '#@internal\s*\*/\s*do_action\( self::SCRIPT \)#', $code ), $declares . ' marks its signal internal.' );
		}
	}

	public function test_every_level_by_level_walk_stops_at_the_one_named_depth_limit(): void {
		$named = 0;
		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( dirname( __DIR__, 2 ) . '/includes', \FilesystemIterator::SKIP_DOTS ) ) as $file ) {
			$code = (string) file_get_contents( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local source file.
			$this->assertSame( 0, preg_match( '/\$depth\s*<=?\s*\d/', $code ), $file->getFilename() . ' bounds a walk with a bare number.' );
			$named += preg_match_all( '/\$depth < (?:Subtree|self)::WALK_DEPTH\b/', $code );
		}
		$this->assertSame( 5, $named, 'The subtree counts and walks, the search\'s parent paths, the batch resolver\'s ancestors and the pairs\' levels.' );
		$this->assertSame( 50, Subtree::WALK_DEPTH );
	}

	public function test_the_main_file_and_every_file_in_includes_stop_when_requested_directly(): void {
		$root  = dirname( __DIR__, 2 );
		$files = [ $root . '/hierarchy-guard.php' ];
		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/includes', \FilesystemIterator::SKIP_DOTS ) ) as $file ) {
			if ( 'php' === $file->getExtension() ) {
				$files[] = $file->getPathname();
			}
		}
		$this->assertGreaterThan( 40, count( $files ) );

		foreach ( $files as $file ) {
			$code  = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local source file.
			$guard = preg_match( "/if \\( ! defined\\( 'ABSPATH' \\) \\) \\{\\s*exit;\\s*\\}/", $code, $match, PREG_OFFSET_CAPTURE ) ? $match[0][1] : false;
			$this->assertNotFalse( $guard, $file );
			$this->assertLessThan( 50, substr_count( substr( $code, 0, (int) $guard ), "\n" ), $file . ': within the first 50 lines, where Plugin Check looks.' );
			if ( preg_match( '/^(?:final class|class|interface|trait|require|spl_autoload_register|if \\( ! class_exists)/m', $code, $first, PREG_OFFSET_CAPTURE ) ) {
				$this->assertLessThan( $first[0][1], $guard, $file . ': before any code runs.' );
			}
		}
	}
}
