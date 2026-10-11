<?php
/**
 * Scripts for the editor panel, the list screen and the classic editor.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Admin;

use HierarchyGuard\Post_Types;
use HierarchyGuard\REST\Hierarchy_Controller;
use HierarchyGuard\Redirects\Providers;
use HierarchyGuard\Settings;
use HierarchyGuard\Subtree;
use WP_Screen;

/**
 * Enqueues the compiled bundles from build/, each with the dependencies its
 * .asset.php file lists, its translations, and the plugin's settings. A
 * bundle that was never built is skipped, so a checkout without npm still runs.
 * The list-screen bundle loads only when the screen prints something it acts
 * on, so a Pages list without parents never loads React or the components.
 */
final class Assets {

	public const HANDLE_PREFIX = 'hierarchy-guard-';

	/**
	 * Providers.
	 *
	 * @var Providers
	 */
	private Providers $providers;

	/**
	 * Plugin directory holding build/.
	 *
	 * @var string
	 */
	private string $dir;

	/**
	 * Whether the list screen asked for its script.
	 *
	 * @var bool
	 */
	private bool $wanted = false;

	/**
	 * Constructor.
	 *
	 * @param Providers $providers Redirect plugin detection.
	 * @param string    $dir       Plugin directory holding build/.
	 */
	public function __construct( Providers $providers, string $dir = HIERARCHY_GUARD_DIR ) {
		$this->providers = $providers;
		$this->dir       = rtrim( $dir, '/\\' );
	}

	/**
	 * Hooks into core.
	 */
	public function register(): void {
		add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue_editor' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin' ] );
		add_action( List_Table::SCRIPT, [ $this, 'want_list_table' ] );
		add_action( 'admin_footer', [ $this, 'enqueue_list_table' ] );
	}

	/**
	 * Notes that the list screen printed something its script acts on.
	 */
	public function want_list_table(): void {
		$this->wanted = true;
	}

	/**
	 * Enqueues the list-screen script in the footer, if the screen asked for it.
	 * Footer scripts and late styles enqueued here still print.
	 */
	public function enqueue_list_table(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $this->wanted || ! $screen instanceof WP_Screen || 'edit' !== $screen->base || ! Post_Types::is_supported( (string) $screen->post_type ) ) {
			return;
		}
		// Quick Edit's script defines the object the list-table script wraps.
		$this->enqueue( 'list-table', [ 'jquery', 'inline-edit-post' ] );
	}

	/**
	 * The editor panel, for supported types.
	 */
	public function enqueue_editor(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen instanceof WP_Screen || ! Post_Types::is_supported( (string) $screen->post_type ) ) {
			return;
		}
		$this->enqueue( 'editor' );
	}

	/**
	 * The classic-editor script where the block editor is off.
	 *
	 * @param mixed $hook_suffix Admin page.
	 */
	public function enqueue_admin( $hook_suffix ): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen instanceof WP_Screen || ! Post_Types::is_supported( (string) $screen->post_type ) ) {
			return;
		}
		if ( in_array( $hook_suffix, [ 'post.php', 'post-new.php' ], true ) && ! $screen->is_block_editor() ) {
			// It binds a jQuery submit handler, which core's own handler runs after.
			$this->enqueue( 'classic', [ 'jquery' ] );
		}
	}

	/**
	 * Enqueues one bundle with its styles, translations and settings.
	 *
	 * @param string   $name  Bundle name, as in build/{name}.js.
	 * @param string[] $extra Script handles the bundle uses without importing them.
	 * @return bool Whether the bundle was built and enqueued.
	 */
	public function enqueue( string $name, array $extra = [] ): bool {
		$asset_file = $this->dir . '/build/' . $name . '.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			return false;
		}
		$asset = require $asset_file;
		if ( ! is_array( $asset ) ) {
			return false;
		}

		$handle  = self::HANDLE_PREFIX . $name;
		$deps    = array_values( array_unique( array_merge( array_map( 'strval', (array) ( $asset['dependencies'] ?? [] ) ), $extra ) ) );
		$version = (string) ( $asset['version'] ?? HIERARCHY_GUARD_VERSION );
		$url     = plugins_url( 'build/' . $name . '.js', $this->dir . '/hierarchy-guard.php' );

		wp_enqueue_script( $handle, $url, $deps, $version, true );
		wp_set_script_translations( $handle, 'hierarchy-guard' );
		wp_add_inline_script( $handle, 'window.hierarchyGuard = ' . wp_json_encode( $this->config() ) . ';', 'before' );

		$styles = in_array( 'wp-components', $deps, true ) ? [ 'wp-components' ] : [];
		if ( is_readable( $this->dir . '/build/' . $name . '.css' ) ) {
			wp_enqueue_style( $handle, plugins_url( 'build/' . $name . '.css', $this->dir . '/hierarchy-guard.php' ), $styles, $version );
		} elseif ( [] !== $styles ) {
			wp_enqueue_style( 'wp-components' );
		}

		return true;
	}

	/**
	 * What every bundle needs to know about the site.
	 *
	 * @return array<string,mixed>
	 */
	public function config(): array {
		$provider = $this->providers->detect();
		return [
			'namespace'   => Hierarchy_Controller::NAMESPACE,
			'serving'     => Settings::serve_redirects(),
			'provider'    => null === $provider ? null : [
				'name' => (string) ( $provider['name'] ?? '' ),
				'url'  => (string) ( $provider['url'] ?? '' ),
				'csv'  => (string) ( $provider['csv'] ?? 'generic' ),
			],
			'settingsUrl' => current_user_can( 'manage_options' ) ? Settings_Section::redirects_url() : '',
			'policyUrl'   => current_user_can( 'manage_options' ) ? Settings_Section::policy_url() : '',
			'policy'      => Settings::trash_policy(),
			'postTypes'   => Post_Types::supported(),
			'cap'         => Subtree::count_cap(),
			'locale'      => str_replace( '_', '-', get_user_locale() ),
			'restNonce'   => wp_create_nonce( 'wp_rest' ),
			'restUrl'     => esc_url_raw( rest_url() ),
		];
	}
}
