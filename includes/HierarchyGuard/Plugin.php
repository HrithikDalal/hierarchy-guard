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

		$ledger    = new Ledger();
		$providers = new Redirects\Providers();
		( new Change_Listener( $ledger ) )->register();
		( new Redirects\Server( new Redirects\Resolver( $ledger ) ) )->register();
		( new Admin\Settings_Section( $providers ) )->register();
		( new Trash\Markers() )->register();

		$log        = new Trash\Change_Log();
		$queue      = new Batch\Queue();
		$operations = new Trash\Operations( $ledger, new Trash\Mover() );
		$processor  = new Batch\Processor( $queue, $operations, $log );
		$processor->register();
		( new Trash\Trash_Handler( $ledger, $operations, $queue, $log ) )->register();

		$pairs    = new Redirects\Pairs();
		$overview = new Overview( $ledger, $providers );
		( new Admin\Notices( $log, $queue, $pairs, $providers ) )->register();
		( new Admin\List_Table() )->register();
		( new REST\Hierarchy_Controller( $overview, $ledger, $pairs ) )->register();
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
