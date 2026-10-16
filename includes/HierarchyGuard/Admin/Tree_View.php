<?php
/**
 * The Tree view of a supported list screen.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Admin;

use HierarchyGuard\Post_Types;

/**
 * Adds "Tree" to the views row of every supported list screen. The Tree view
 * is core's own list table: the top level comes from core's query with
 * post_parent 0 in menu order then title, 20 to a page, and a search lists
 * the matches at any level, flat. The list-table script loads subpages 50 at
 * a time from the hierarchy route. Without JavaScript a toggle reloads the
 * view opened at one page: the path down to it and its page of 50 subpages
 * are put after their parents in core's results, so core prints them.
 * Nothing here reads every top-level post: Subtree::ordered_children() is
 * never asked for parent 0.
 */
final class Tree_View {

	public const QUERY_VAR         = 'hierarchy_guard_view';
	public const VIEW              = 'tree';
	public const OPEN_VAR          = 'hierarchy_guard_open';
	public const CHILDREN_PAGE_VAR = 'hierarchy_guard_children_page';
	public const PER_PAGE          = 50;

	/**
	 * Hooks into core.
	 */
	public function register(): void {
		add_filter( 'request', [ $this, 'filter_request' ] );
		add_action( 'load-edit.php', [ $this, 'load' ] );
	}

	/**
	 * The Tree view's URL for a post type.
	 *
	 * @param string $post_type Post type name.
	 * @return string
	 */
	public static function url( string $post_type ): string {
		return add_query_arg(
			[
				'post_type'     => $post_type,
				self::QUERY_VAR => self::VIEW,
			],
			admin_url( 'edit.php' )
		);
	}

	/**
	 * Which Tree view the request asks for on a post type's list: 'tree', a
	 * search in it ('search'), or none (''). The subtree filter and the Trash
	 * are views of their own.
	 *
	 * @param string $post_type Post type of the list.
	 * @return string
	 */
	public function mode( string $post_type ): string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only list views, like core's own.
		if ( ! is_admin() || 'edit.php' !== ( $GLOBALS['pagenow'] ?? '' ) || ! Post_Types::is_supported( $post_type ) ) {
			return '';
		}
		$view   = isset( $_GET[ self::QUERY_VAR ] ) ? sanitize_key( wp_unslash( $_GET[ self::QUERY_VAR ] ) ) : '';
		$status = isset( $_GET['post_status'] ) ? sanitize_key( wp_unslash( $_GET['post_status'] ) ) : '';
		$search = isset( $_GET['s'] ) && is_string( $_GET['s'] ) ? trim( sanitize_text_field( wp_unslash( $_GET['s'] ) ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( self::VIEW !== $view || List_Table::under() > 0 || ! in_array( $status, [ '', 'all' ], true ) ) {
			return '';
		}
		return '' !== $search ? 'search' : 'tree';
	}

	/**
	 * Hooks the Tree view's pieces into the list screen being loaded.
	 */
	public function load(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$type   = null !== $screen ? (string) $screen->post_type : '';
		if ( ! Post_Types::is_supported( $type ) ) {
			return;
		}
		add_filter( "views_edit-{$type}", [ $this, 'views' ] );
		$mode = $this->mode( $type );
		if ( '' === $mode ) {
			return;
		}
		add_filter( "manage_{$type}_posts_columns", [ $this, 'columns' ], 20 );
		add_filter( "bulk_actions-edit-{$type}", [ $this, 'bulk_actions' ] );
		add_filter( 'quick_edit_enabled_for_post_type', '__return_false' );
		if ( 'tree' === $mode ) {
			add_filter( 'admin_body_class', [ $this, 'body_class' ] );
		}
	}

	/**
	 * Adds "Tree" to the views row.
	 *
	 * @param mixed $views Views, by key.
	 * @return mixed
	 */
	public function views( $views ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$type   = null !== $screen ? (string) $screen->post_type : '';
		if ( ! is_array( $views ) || ! Post_Types::is_supported( $type ) ) {
			return $views;
		}
		$views['hierarchy_guard_tree'] = sprintf(
			'<a href="%1$s"%2$s>%3$s</a>',
			esc_url( self::url( $type ) ),
			'' !== $this->mode( $type ) ? ' class="current" aria-current="page"' : '',
			esc_html__( 'Tree', 'hierarchy-guard' )
		);
		return $views;
	}

	/**
	 * Limits core's list query to the top level, or for a search to the
	 * matches at any level, in menu order then title as an array, so core
	 * pages it 20 at a time instead of loading every page for its indented
	 * tree mode. A column's own sort is left to core.
	 *
	 * @param mixed $vars Query vars.
	 * @return mixed
	 */
	public function filter_request( $vars ) {
		if ( ! is_array( $vars ) || ! self::on_list() ) {
			return $vars;
		}
		$type = isset( $vars['post_type'] ) && is_string( $vars['post_type'] ) ? $vars['post_type'] : '';
		$mode = $this->mode( $type );
		if ( '' === $mode ) {
			return $vars;
		}
		if ( 'tree' === $mode ) {
			$vars['post_parent'] = 0;
		}
		if ( 'menu_order title' === ( $vars['orderby'] ?? '' ) ) {
			$vars['orderby'] = [
				'menu_order' => 'ASC',
				'title'      => 'ASC',
			];
			unset( $vars['fields'], $vars['posts_per_archive_page'] );
			$vars['posts_per_page'] = List_Table::per_page( $type );
		}
		return $vars;
	}

	/**
	 * The tree has no checkbox column: its loaded subpages are not core's rows.
	 *
	 * @param mixed $columns Columns.
	 * @return mixed
	 */
	public function columns( $columns ) {
		if ( is_array( $columns ) ) {
			unset( $columns['cb'] );
		}
		return $columns;
	}

	/**
	 * The tree has no bulk actions.
	 *
	 * @return array<string,string>
	 */
	public function bulk_actions(): array {
		return [];
	}

	/**
	 * Marks the screen as the tree, for the stylesheet's indents.
	 *
	 * @param mixed $classes Body classes.
	 * @return mixed
	 */
	public function body_class( $classes ) {
		return is_string( $classes ) ? $classes . ' hierarchy-guard-tree-view ' : $classes;
	}

	/**
	 * Whether the request is a list screen in wp-admin, the only place the
	 * view acts; front-end queries are left alone before anything is read.
	 *
	 * @return bool
	 */
	private static function on_list(): bool {
		return is_admin() && 'edit.php' === ( $GLOBALS['pagenow'] ?? '' );
	}
}
