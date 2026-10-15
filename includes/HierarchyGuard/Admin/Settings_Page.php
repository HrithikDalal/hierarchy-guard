<?php
/**
 * Settings › Hierarchy Guard.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Admin;

use HierarchyGuard\Ledger;
use HierarchyGuard\Redirects\Providers;
use HierarchyGuard\Redirects\Records;
use HierarchyGuard\Settings;
use HierarchyGuard\Subtree;

/**
 * The plugin's one admin page, under Settings, for users with manage_options:
 * core nav tabs, the Settings tab's three fields, saved through the Settings
 * API, the Redirects tab, whose actions run on the page's load hook, and a
 * "Settings" link on the plugin's row of the Plugins screen.
 */
final class Settings_Page {

	public const SLUG    = 'hierarchy-guard';
	public const SECTION = 'hierarchy_guard';

	/**
	 * Providers.
	 *
	 * @var Providers
	 */
	private Providers $providers;

	/**
	 * Records.
	 *
	 * @var Records
	 */
	private Records $records;

	/**
	 * The Redirects tab's actions.
	 *
	 * @var Redirect_Edit
	 */
	private Redirect_Edit $edit;

	/**
	 * Constructor.
	 *
	 * @param Providers $providers Redirect plugin detection.
	 * @param Records   $records   Recorded redirects.
	 */
	public function __construct( Providers $providers, Records $records ) {
		$this->providers = $providers;
		$this->records   = $records;
		$this->edit      = new Redirect_Edit( $records );
	}

	/**
	 * Hooks into core.
	 */
	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_page' ] );
		add_action( 'admin_init', [ $this, 'add_fields' ] );
		add_filter( 'plugin_action_links_' . plugin_basename( HIERARCHY_GUARD_FILE ), [ $this, 'action_links' ] );
	}

	/**
	 * The page's URL, on a tab.
	 *
	 * @param string $tab Tab, '' for the Settings tab.
	 * @return string
	 */
	public static function url( string $tab = '' ): string {
		$args = [ 'page' => self::SLUG ];
		if ( '' !== $tab ) {
			$args['tab'] = $tab;
		}
		return add_query_arg( $args, admin_url( 'options-general.php' ) );
	}

	/**
	 * The Redirects field, for "Set up redirects" links.
	 *
	 * @return string
	 */
	public static function redirects_url(): string {
		return self::url() . '#hierarchy-guard-redirects';
	}

	/**
	 * The trash-policy field, for "Change this in Settings" links.
	 *
	 * @return string
	 */
	public static function policy_url(): string {
		return self::url() . '#hierarchy-guard-trash-policy';
	}

	/**
	 * The tab the request asks for.
	 *
	 * @return string `redirects` or `settings`.
	 */
	public static function current_tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Which tab to show; it changes nothing.
		return isset( $_GET['tab'] ) && 'redirects' === sanitize_key( wp_unslash( $_GET['tab'] ) ) ? 'redirects' : 'settings';
	}

	/**
	 * Adds the page under Settings, and its load hook.
	 */
	public function add_page(): void {
		$hook = add_options_page( __( 'Hierarchy Guard', 'hierarchy-guard' ), __( 'Hierarchy Guard', 'hierarchy-guard' ), 'manage_options', self::SLUG, [ $this, 'render' ] );
		if ( is_string( $hook ) ) {
			add_action( 'load-' . $hook, [ $this, 'load' ] );
		}
	}

	/**
	 * Before the page prints: runs the Redirects tab's action and sends the
	 * browser on; otherwise names the tab's list for screen readers and in the
	 * browser title. Assets enqueues the tab's script, which asks before a Delete.
	 */
	public function load(): void {
		if ( 'redirects' !== self::current_tab() ) {
			return;
		}
		$url = $this->edit->handle();
		if ( null !== $url ) {
			wp_safe_redirect( $url );
			exit;
		}
		$screen = get_current_screen();
		if ( null !== $screen ) {
			$screen->set_screen_reader_content(
				[
					'heading_pagination' => __( 'Redirects list navigation', 'hierarchy-guard' ),
					'heading_list'       => __( 'Redirects list', 'hierarchy-guard' ),
				]
			);
		}
		add_filter( 'admin_title', [ $this, 'admin_title' ] );
	}

	/**
	 * The browser title of the Redirects tab and the Edit view: the view's name first.
	 *
	 * @param string $admin_title The admin page's title, as core builds it.
	 * @return string
	 */
	public function admin_title( $admin_title ): string {
		$view = $this->edit->is_editing() ? __( 'Edit redirect', 'hierarchy-guard' ) : __( 'Redirects', 'hierarchy-guard' );
		/* translators: 1: the view of the page, such as Redirects; 2: the page's title, as the browser shows it. */
		return sprintf( __( '%1$s ‹ %2$s', 'hierarchy-guard' ), $view, (string) $admin_title );
	}

	/**
	 * Puts a "Settings" link first on the plugin's row of the Plugins screen.
	 *
	 * @param array<int|string,string> $links Action links.
	 * @return array<int|string,string>
	 */
	public function action_links( array $links ): array {
		if ( current_user_can( 'manage_options' ) ) {
			$links = array_merge( [ 'settings' => sprintf( '<a href="%1$s">%2$s</a>', esc_url( self::url() ), esc_html__( 'Settings', 'hierarchy-guard' ) ) ], $links );
		}
		return $links;
	}

	/**
	 * Adds the Settings tab's fields, in a section without a heading.
	 */
	public function add_fields(): void {
		add_settings_section( self::SECTION, '', '__return_null', self::SLUG );

		add_settings_field( 'hierarchy_guard_post_types', __( 'Post types', 'hierarchy-guard' ), [ $this, 'render_post_types' ], self::SLUG, self::SECTION );
		add_settings_field( 'hierarchy_guard_trash_policy', __( 'Trashing a page with subpages', 'hierarchy-guard' ), [ $this, 'render_trash_policy' ], self::SLUG, self::SECTION );
		add_settings_field( 'hierarchy_guard_serve_redirects', __( 'Redirects', 'hierarchy-guard' ), [ $this, 'render_serve_redirects' ], self::SLUG, self::SECTION );
	}

	/**
	 * Prints the page: the heading, the tabs, and the current tab.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$tab    = self::current_tab();
		$search = 'redirects' === $tab ? Redirects_Table::search() : '';
		echo '<div class="wrap">';
		if ( '' === $search ) {
			echo '<h1>' . esc_html__( 'Hierarchy Guard', 'hierarchy-guard' ) . '</h1>';
		} else {
			echo '<h1 class="wp-heading-inline">' . esc_html__( 'Hierarchy Guard', 'hierarchy-guard' ) . '</h1> ';
			/* translators: %s: the search text. */
			printf( '<span class="subtitle">%s</span>', sprintf( esc_html__( 'Search results for: %s', 'hierarchy-guard' ), '<strong><span dir="ltr">' . esc_html( $search ) . '</span></strong>' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Both parts are escaped.
		}
		$this->render_tabs( $tab );
		// Core moves notices to just after this, below the tabs.
		echo '<hr class="wp-header-end">';
		if ( 'redirects' === $tab ) {
			$this->render_redirects();
		} else {
			echo '<form action="options.php" method="post">';
			settings_fields( Settings::GROUP );
			do_settings_sections( self::SLUG );
			submit_button();
			echo '</form>';
		}
		echo '</div>';
	}

	/**
	 * The Redirects tab: the Edit view when it is asked for; otherwise its
	 * notices, the search box and the table, in a GET form back to this tab,
	 * as core's list screens have it.
	 */
	private function render_redirects(): void {
		if ( $this->edit->render() ) {
			return;
		}
		$table = new Redirects_Table( $this->records );
		$table->prepare_items();

		if ( Ledger::backfilling() ) {
			$building = __( 'The list of recorded redirects is still being built.', 'hierarchy-guard' );
			$older    = __( 'Older redirects appear here once it’s done.', 'hierarchy-guard' );
			$this->inline_notice( esc_html( $building . ' ' . $older ), 'info' );
		}
		if ( ! Settings::serve_redirects() ) {
			$off = esc_html__( 'Built-in redirects are off, so these redirects aren’t served.', 'hierarchy-guard' );
			/* translators: %s: URL of the Redirects field on the Settings tab. */
			$on = sprintf( __( 'Turn them on in the <a href="%s">Settings tab</a>.', 'hierarchy-guard' ), esc_url( self::redirects_url() ) );
			$this->inline_notice( $off . ' ' . $on, 'warning' );
		}
		$provider = $this->providers->detect();
		if ( null !== $provider ) {
			$name = trim( (string) ( $provider['name'] ?? '' ) );
			if ( '' !== $name ) {
				/* translators: %s: name of the detected redirect plugin. */
				$active = sprintf( __( '%s is active.', 'hierarchy-guard' ), $name );
				/* translators: %s: name of the detected redirect plugin. */
				$recorded = sprintf( __( 'These are the redirects Hierarchy Guard recorded; its notices after each change offer to add them in %s.', 'hierarchy-guard' ), $name );
			} else {
				$active   = __( 'A redirect plugin is active.', 'hierarchy-guard' );
				$recorded = __( 'These are the redirects Hierarchy Guard recorded; its notices after each change offer to add them there.', 'hierarchy-guard' );
			}
			$this->inline_notice( esc_html( $active . ' ' . $recorded ), 'info' );
		}
		if ( $table->is_capped() ) {
			$cap = Subtree::count_cap();
			/* translators: %s: the most recorded redirects listed, such as 1,000. */
			$only    = sprintf( _n( 'Only the newest %s recorded redirect is listed.', 'Only the newest %s recorded redirects are listed.', $cap, 'hierarchy-guard' ), number_format_i18n( $cap ) );
			$another = __( 'To find another, search for its full old URL.', 'hierarchy-guard' );
			$this->inline_notice( esc_html( $only . ' ' . $another ), 'info' );
		}

		$bulk = __( 'Delete the selected redirects?', 'hierarchy-guard' ) . ' ' . __( 'This can’t be undone.', 'hierarchy-guard' );
		printf( '<form method="get" id="hierarchy-guard-redirects" data-hierarchy-guard-confirm="%s">', esc_attr( $bulk ) );
		printf( '<input type="hidden" name="page" value="%s">', esc_attr( self::SLUG ) );
		echo '<input type="hidden" name="tab" value="redirects">';
		$table->search_box( __( 'Search Redirects', 'hierarchy-guard' ), 'hierarchy-guard-search' );
		$table->display();
		echo '</form>';
	}

	/**
	 * A core notice in the page's flow.
	 *
	 * @param string $message Message, escaped HTML: text, links and code.
	 * @param string $type    Notice type.
	 */
	private function inline_notice( string $message, string $type ): void {
		wp_admin_notice(
			wp_kses(
				$message,
				[
					'a'    => [ 'href' => [] ],
					'code' => [],
				]
			),
			[
				'type'               => $type,
				'additional_classes' => [ 'inline' ],
			]
		);
	}

	/**
	 * The tabs, by key.
	 *
	 * @return array<string,string>
	 */
	private function tabs(): array {
		return [
			'settings'  => __( 'Settings', 'hierarchy-guard' ),
			'redirects' => __( 'Redirects', 'hierarchy-guard' ),
		];
	}

	/**
	 * Prints core's nav tabs.
	 *
	 * @param string $current The tab shown.
	 */
	private function render_tabs( string $current ): void {
		echo '<nav class="nav-tab-wrapper wp-clearfix" aria-label="' . esc_attr__( 'Secondary menu', 'hierarchy-guard' ) . '">';
		foreach ( $this->tabs() as $tab => $label ) {
			$active = $tab === $current;
			printf(
				'<a href="%1$s" class="nav-tab%2$s"%3$s>%4$s</a>',
				esc_url( self::url( 'settings' === $tab ? '' : $tab ) ),
				$active ? ' nav-tab-active' : '',
				$active ? ' aria-current="page"' : '',
				esc_html( $label )
			);
		}
		echo '</nav>';
	}

	/**
	 * Checkbox per public hierarchical post type.
	 */
	public function render_post_types(): void {
		$types      = get_post_types(
			[
				'public'       => true,
				'hierarchical' => true,
			],
			'objects'
		);
		$configured = Settings::get( 'post_types' );
		// The stored choice, not the filtered list, so a save never persists filter output.
		$selected = null === $configured ? array_keys( $types ) : (array) $configured;

		echo '<fieldset><legend class="screen-reader-text"><span>' . esc_html__( 'Post types', 'hierarchy-guard' ) . '</span></legend>';
		// Always submits the key, so unchecking every box saves [] rather than the null default.
		printf( '<input type="hidden" name="%s[post_types][]" value="">', esc_attr( Settings::OPTION ) );
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
	 * Radio group for the trash policy.
	 */
	public function render_trash_policy(): void {
		$current = (string) Settings::get( 'trash_policy' );
		$options = [
			Settings::POLICY_REPARENT       => __( 'Move subpages up one level', 'hierarchy-guard' ),
			Settings::POLICY_TRASH_CHILDREN => __( 'Trash the subpages too', 'hierarchy-guard' ),
			Settings::POLICY_KEEP           => __( 'Leave subpages where they are', 'hierarchy-guard' ),
		];

		echo '<fieldset id="hierarchy-guard-trash-policy"><legend class="screen-reader-text"><span>' . esc_html__( 'Trashing a page with subpages', 'hierarchy-guard' ) . '</span></legend>';
		foreach ( $options as $value => $label ) {
			printf(
				'<label><input type="radio" name="%1$s[trash_policy]" value="%2$s" %3$s> %4$s</label><br>',
				esc_attr( Settings::OPTION ),
				esc_attr( $value ),
				checked( $current, $value, false ),
				esc_html( $label )
			);
		}

		$help = __( 'Used when there’s no chance to ask, for example in the editor, the REST API, or WP-CLI.', 'hierarchy-guard' ) . ' ' . __( 'The Pages list asks each time.', 'hierarchy-guard' );
		if ( ! EMPTY_TRASH_DAYS ) {
			$help .= ' ' . __( 'The Trash is turned off on this site, so deleted pages skip it and their subpages always move up one level.', 'hierarchy-guard' );
		}
		echo '<p class="description">' . esc_html( $help ) . '</p>';
		echo '</fieldset>';
	}

	/**
	 * Checkbox for the built-in redirect server, with provider-aware help text.
	 */
	public function render_serve_redirects(): void {
		$provider = $this->providers->detect();

		printf(
			'<label><input type="checkbox" id="hierarchy-guard-redirects" name="%1$s[serve_redirects]" value="1" %2$s> %3$s</label>',
			esc_attr( Settings::OPTION ),
			checked( Settings::serve_redirects(), true, false ),
			esc_html__( 'Redirect old URLs to their new pages', 'hierarchy-guard' )
		);

		$name = null !== $provider ? trim( (string) ( $provider['name'] ?? '' ) ) : '';
		if ( null !== $provider && '' !== $name ) {
			/* translators: %s: name of the detected redirect plugin. */
			$help = sprintf( __( '%s is active and can handle these.', 'hierarchy-guard' ), $name );
		} elseif ( null !== $provider ) {
			$help = __( 'A redirect plugin is active and can handle these.', 'hierarchy-guard' );
		} else {
			$help = __( 'No redirect plugin was found.', 'hierarchy-guard' ) . ' ' . __( 'Turn this on so old URLs reach their new pages.', 'hierarchy-guard' );
		}
		$help .= ' ' . __( 'URL changes are recorded either way.', 'hierarchy-guard' );
		echo '<p class="description">' . esc_html( $help ) . '</p>';
	}
}
