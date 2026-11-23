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
	 * The objects register() created, by class name.
	 *
	 * @var array<string,object>
	 */
	private array $services = [];

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
	 * The plugin's object of a class, as it was hooked in, or null. With it
	 * an integration can unhook one part, for example the redirect server:
	 * remove_action( 'template_redirect', [ Plugin::instance()->get( Redirects\Server::class ), 'maybe_redirect' ], 9 ).
	 *
	 * @since 1.0.0
	 *
	 * @param string $class_name Class name, such as Redirects\Server::class.
	 * @return object|null
	 */
	public function get( string $class_name ): ?object {
		return $this->services[ ltrim( $class_name, '\\' ) ] ?? null;
	}

	/**
	 * Keeps an object register() creates, for get().
	 *
	 * @template T of object
	 * @param T $service The object.
	 * @return T
	 */
	private function keep( object $service ): object {
		$this->services[ get_class( $service ) ] = $service;
		return $service;
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

		$ledger    = $this->keep( new Ledger() );
		$log       = $this->keep( new Trash\Change_Log() );
		$providers = $this->keep( new Redirects\Providers() );
		$this->keep( new Change_Listener( $ledger, $log ) )->register();
		$this->keep( new Redirects\Server( new Redirects\Resolver( $ledger ) ) )->register();
		add_action( 'init', [ Settings::class, 'register_option' ] );
		$this->keep( new Admin\Settings_Page( $providers, new Redirects\Records( $ledger ) ) )->register();
		$this->keep( new Trash\Markers() )->register();
		$this->keep( new Meta_Guard() )->register();

		$queue      = $this->keep( new Batch\Queue() );
		$operations = $this->keep( new Trash\Operations( $ledger, new Trash\Mover() ) );
		$processor  = $this->keep( new Batch\Processor( $queue, $operations, $log ) );
		$processor->register();
		$this->keep( new Trash\Trash_Handler( $ledger, $operations, $queue, $log ) )->register();
		$this->keep( new Compat\Yoast() )->register();

		$pairs    = $this->keep( new Redirects\Pairs() );
		$overview = $this->keep( new Overview( $providers, $log ) );
		$this->keep( new Admin\Notices( $log, $queue, $pairs, $providers ) )->register();
		$tree = $this->keep( new Admin\Tree_View() );
		$tree->register();
		$this->keep( new Admin\List_Table( $tree ) )->register();
		$this->keep( new REST\Hierarchy_Controller( $overview ) )->register();
		$this->keep( new REST\Csv_Download() )->register();
		$this->keep( new Admin\Trash_Choice() )->register();
		$this->keep( new REST\Changes_Controller( $log, $pairs ) )->register();
		$this->keep( new Admin\Meta_Box( $overview ) )->register();
		$this->keep( new Admin\Assets( $providers ) )->register();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'hierarchy-guard', new Batch\CLI_Command( $queue, $processor ) );
		}
	}
}
