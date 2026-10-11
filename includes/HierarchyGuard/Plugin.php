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

		$ledger = new Ledger();
		( new Change_Listener( $ledger ) )->register();
		( new Redirects\Server( new Redirects\Resolver( $ledger ) ) )->register();
		( new Admin\Settings_Section( new Redirects\Providers() ) )->register();
		( new Trash\Markers() )->register();

		$log        = new Trash\Change_Log();
		$queue      = new Batch\Queue();
		$operations = new Trash\Operations( $ledger, new Trash\Mover() );
		$processor  = new Batch\Processor( $queue, $operations, $log );
		$processor->register();
		( new Trash\Trash_Handler( $ledger, $operations, $queue, $log ) )->register();
		( new Admin\Trash_Notice( $log ) )->register();
		( new Admin\List_Table() )->register();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'hierarchy-guard', new Batch\CLI_Command( $queue, $processor ) );
		}
	}
}
