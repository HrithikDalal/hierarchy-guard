<?php
/**
 * Settings › Writing › Page hierarchy.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Admin;

use HierarchyGuard\Post_Types;
use HierarchyGuard\Redirects\Providers;
use HierarchyGuard\Settings;

/**
 * Registers the three settings as a section on the Writing settings page.
 */
final class Settings_Section {

	public const SECTION = 'hierarchy_guard';
	public const PAGE    = 'writing';

	/**
	 * Providers.
	 *
	 * @var Providers
	 */
	private Providers $providers;

	/**
	 * Constructor.
	 *
	 * @param Providers $providers Redirect plugin detection.
	 */
	public function __construct( Providers $providers ) {
		$this->providers = $providers;
	}

	/**
	 * Hooks into core. The option is registered on init so its sanitize callback
	 * also covers WP-CLI and REST writes; the section and fields are admin only.
	 */
	public function register(): void {
		add_action( 'init', [ $this, 'register_option' ] );
		add_action( 'admin_init', [ $this, 'add' ] );
	}

	/**
	 * Registers the option with its sanitize callback and defaults.
	 */
	public function register_option(): void {
		register_setting(
			self::PAGE,
			Settings::OPTION,
			[
				'type'              => 'array',
				'sanitize_callback' => [ Settings::class, 'sanitize' ],
				'default'           => Settings::defaults(),
			]
		);
	}

	/**
	 * Adds the section and fields to the Writing settings page.
	 */
	public function add(): void {
		add_settings_section( self::SECTION, __( 'Page hierarchy', 'hierarchy-guard' ), '__return_null', self::PAGE );

		add_settings_field( 'hierarchy_guard_post_types', __( 'Post types', 'hierarchy-guard' ), [ $this, 'render_post_types' ], self::PAGE, self::SECTION );
		add_settings_field( 'hierarchy_guard_trash_policy', __( 'Trashing a page with subpages', 'hierarchy-guard' ), [ $this, 'render_trash_policy' ], self::PAGE, self::SECTION );
		add_settings_field( 'hierarchy_guard_serve_redirects', __( 'Redirects', 'hierarchy-guard' ), [ $this, 'render_serve_redirects' ], self::PAGE, self::SECTION );
	}

	/**
	 * Checkbox per public hierarchical post type.
	 */
	public function render_post_types(): void {
		$types    = get_post_types(
			[
				'public'       => true,
				'hierarchical' => true,
			],
			'objects'
		);
		$selected = Post_Types::supported();

		echo '<fieldset><legend class="screen-reader-text"><span>' . esc_html__( 'Post types', 'hierarchy-guard' ) . '</span></legend>';
		foreach ( $types as $type ) {
			printf(
				'<label><input type="checkbox" name="%1$s[post_types][]" value="%2$s" %3$s> %4$s</label><br>',
				esc_attr( Settings::OPTION ),
				esc_attr( $type->name ),
				checked( in_array( $type->name, $selected, true ), true, false ),
				esc_html( $type->labels->name )
			);
		}
		echo '<p class="description">' . esc_html__( 'Public hierarchical post types appear here automatically.', 'hierarchy-guard' ) . '</p>';
		echo '</fieldset>';
	}

	/**
	 * Radio group for the default trash policy.
	 */
	public function render_trash_policy(): void {
		$current = (string) Settings::get( 'trash_policy' );
		$options = [
			Settings::POLICY_REPARENT       => __( 'Move subpages up one level', 'hierarchy-guard' ),
			Settings::POLICY_TRASH_CHILDREN => __( 'Trash the subpages too', 'hierarchy-guard' ),
			Settings::POLICY_KEEP           => __( 'Leave subpages where they are', 'hierarchy-guard' ),
		];

		echo '<fieldset><legend class="screen-reader-text"><span>' . esc_html__( 'Trashing a page with subpages', 'hierarchy-guard' ) . '</span></legend>';
		foreach ( $options as $value => $label ) {
			printf(
				'<label><input type="radio" name="%1$s[trash_policy]" value="%2$s" %3$s> %4$s</label><br>',
				esc_attr( Settings::OPTION ),
				esc_attr( $value ),
				checked( $current, $value, false ),
				esc_html( $label )
			);
		}
		echo '<p class="description">' . esc_html__( 'Used when there is no chance to ask, for example in the editor, the REST API, or WP-CLI. The Pages list asks each time. Not applied when the trash is disabled.', 'hierarchy-guard' ) . '</p>';
		echo '</fieldset>';
	}

	/**
	 * Checkbox for the built-in redirect server, with provider-aware help text.
	 */
	public function render_serve_redirects(): void {
		$provider = $this->providers->detect();

		printf(
			'<label><input type="checkbox" name="%1$s[serve_redirects]" value="1" %2$s> %3$s</label>',
			esc_attr( Settings::OPTION ),
			checked( Settings::serve_redirects(), true, false ),
			esc_html__( 'Redirect old URLs to their new pages', 'hierarchy-guard' )
		);

		if ( null !== $provider ) {
			/* translators: %s: name of the detected redirect plugin. */
			$help = sprintf( __( '%s is active and can handle these. URL changes are recorded either way.', 'hierarchy-guard' ), $provider['name'] );
		} else {
			$help = __( 'No redirect plugin was found. Turn this on so old URLs reach their new pages. URL changes are recorded either way.', 'hierarchy-guard' );
		}
		echo '<p class="description">' . esc_html( $help ) . '</p>';
	}
}
