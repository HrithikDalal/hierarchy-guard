<?php
/**
 * Plugin bootstrap.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard;

/**
 * Wires every hook to its handler.
 */
final class Plugin {

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
		// The newest-records list is rebuilt daily from WP-Cron; admin_init makes sure the run is scheduled.
		add_action( 'admin_init', [ Ledger::class, 'schedule_rebuild' ] );
		add_action( Ledger::REBUILD_HOOK, [ Ledger::class, 'rebuild_recent' ] );

		$ledger    = new Ledger();
		$log       = new Trash\Change_Log();
		$providers = new Redirects\Providers();
		( new Change_Listener( $ledger, $log ) )->register();
		( new Redirects\Server( new Redirects\Resolver( $ledger ) ) )->register();
		add_action( 'init', [ Settings::class, 'register_option' ] );
		( new Admin\Settings_Page( $providers, new Redirects\Records( $ledger ) ) )->register();
		( new Trash\Markers() )->register();

		$queue      = new Batch\Queue();
		$operations = new Trash\Operations( $ledger, new Trash\Mover() );
		$processor  = new Batch\Processor( $queue, $operations, $log );
		$processor->register();
		( new Trash\Trash_Handler( $ledger, $operations, $queue, $log ) )->register();

		$pairs    = new Redirects\Pairs();
		$overview = new Overview( $providers, $log );
		( new Admin\Notices( $log, $queue, $pairs, $providers ) )->register();
		( new Admin\List_Table() )->register();
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
