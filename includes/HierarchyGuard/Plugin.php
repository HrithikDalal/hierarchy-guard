<?php
/**
 * Plugin bootstrap.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires every hook to its handler.
 */
final class Plugin {

	public const VERSION_OPTION = 'hierarchy_guard_version';
	public const NETWORK_OPTION = 'hierarchy_guard_network_setup';

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Whether register() has run.
	 *
	 * @var bool
	 */
	private bool $registered = false;

	/**
	 * Returns the shared instance.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * What a site needs once, on activation, after an update and after a
	 * network activation: the watchdog when jobs wait, the daily rebuild when
	 * the ledger holds entries, and Yoast's run when pages wait for it.
	 * Records the version and the network activation it was done for. Core
	 * stores a network activation's time only after the activation hook
	 * runs, so on the site that hook ran on, 0 is recorded, and its next
	 * admin load runs setup() once more.
	 */
	public static function setup(): void {
		Batch\Processor::activate();
		Ledger::schedule_rebuild();
		Compat\Yoast::resume();
		update_option( self::VERSION_OPTION, HIERARCHY_GUARD_VERSION, true );
		if ( is_multisite() ) {
			update_option( self::NETWORK_OPTION, self::network_activated(), true );
		}
	}

	/**
	 * Runs setup() when the stored version differs from the code's: after
	 * an update, on a subsite of a network activation, or where the plugin
	 * is loaded without its activation hook. On a network it runs, too, once
	 * the plugin was network-activated again since this subsite was set up,
	 * because core runs the activation hook on one site only. Otherwise one
	 * read of an autoloaded option, and on a network one of a network option
	 * core has loaded already.
	 */
	public static function maybe_upgrade(): void {
		if ( HIERARCHY_GUARD_VERSION !== get_option( self::VERSION_OPTION ) || ( is_multisite() && self::network_activated() !== (int) get_option( self::NETWORK_OPTION, 0 ) ) ) {
			self::setup();
		}
	}

	/**
	 * When the plugin was last network-activated, from core's own list, or
	 * 0 when it isn't network-active.
	 *
	 * @return int
	 */
	private static function network_activated(): int {
		$sitewide = get_site_option( 'active_sitewide_plugins', [] );
		return is_array( $sitewide ) ? (int) ( $sitewide[ plugin_basename( HIERARCHY_GUARD_FILE ) ] ?? 0 ) : 0;
	}

	/**
	 * Registers hooks. Safe to call more than once.
	 */
	public function register(): void {
		if ( $this->registered ) {
			return;
		}
		$this->registered = true;

		add_action( 'registered_post_type', [ Post_Types::class, 'reset' ] );
		add_action( 'unregistered_post_type', [ Post_Types::class, 'reset' ] );
		add_action( 'update_option_' . Settings::OPTION, [ Post_Types::class, 'reset' ] );
		add_action( 'add_option_' . Settings::OPTION, [ Post_Types::class, 'reset' ] );
		add_action( 'delete_option_' . Settings::OPTION, [ Post_Types::class, 'reset' ] );
		add_action( 'switch_blog', [ Post_Types::class, 'reset' ] );

		// The newest-records list drops an entry whenever its row goes, however it goes: a post deleted for good writes it once, its entries and the paths moved off it together.
		add_action( 'deleted_post_meta', [ Ledger::class, 'on_deleted_meta' ], 10, 3 );
		add_action( 'before_delete_post', [ Ledger::class, 'begin_delete' ], PHP_INT_MIN, 2 );
		add_action( 'delete_post', [ Ledger::class, 'end_delete' ], PHP_INT_MAX );
		// The newest-records list is rebuilt daily from WP-Cron, once the ledger holds an entry.
		add_action( Ledger::REBUILD_HOOK, [ Ledger::class, 'rebuild_recent' ] );
		add_action( 'admin_init', [ self::class, 'maybe_upgrade' ] );

		$ledger    = new Ledger();
		$log       = new Trash\Change_Log();
		$providers = new Redirects\Providers();
		( new Change_Listener( $ledger, $log ) )->register();
		( new Redirects\Server( new Redirects\Resolver( $ledger ) ) )->register();
		add_action( 'init', [ Settings::class, 'register_option' ] );
		( new Admin\Settings_Page( $providers, new Redirects\Records( $ledger ) ) )->register();
		( new Trash\Markers() )->register();
		( new Meta_Guard() )->register();

		$queue      = new Batch\Queue();
		$operations = new Trash\Operations( $ledger, new Trash\Mover() );
		$processor  = new Batch\Processor( $queue, $operations, $log );
		$processor->register();
		( new Trash\Trash_Handler( $ledger, $operations, $queue, $log ) )->register();
		( new Compat\Yoast() )->register();

		$pairs    = new Redirects\Pairs();
		$overview = new Overview( $providers, $log );
		( new Admin\Notices( $log, $queue, $pairs, $providers ) )->register();
		$tree = new Admin\Tree_View();
		$tree->register();
		( new Admin\List_Table( $tree ) )->register();
		( new REST\Hierarchy_Controller( $overview ) )->register();
		( new REST\Csv_Download() )->register();
		( new Admin\Trash_Choice() )->register();
		( new REST\Changes_Controller( $log, $pairs ) )->register();
		( new Admin\Meta_Box( $overview ) )->register();
		( new Admin\Assets( $providers ) )->register();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'hierarchy-guard', new Batch\CLI_Command( $queue, $processor ) );
		}
	}
}
