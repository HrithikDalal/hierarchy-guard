<?php
/**
 * Plugin bootstrap.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard;

/**
 * Wires every hook to its handler. Each later task adds one line to register().
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

		$ledger = new Ledger();
		( new Change_Listener( $ledger ) )->register();
		( new Redirects\Server( new Redirects\Resolver( $ledger ) ) )->register();
	}
}
