<?php
/**
 * Hierarchy column, subtree filter and "View subpages" on the list screen.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Admin;

use HierarchyGuard\Post_Types;
use HierarchyGuard\Subtree;
use WP_Post;
use WP_Query;

/**
 * Adds a one-line Hierarchy column to every supported list screen, a
 * "View subpages" row action, and a filter that narrows the list to one
 * page's subtree. Child counts for a screen cost one query; the filter is one
 * capped walk. Runs on list screens, where core's list-table helpers such as
 * _draft_or_post_title() are loaded.
 */
final class List_Table {

	public const QUERY_VAR = 'hierarchy_guard_under';
	public const COLUMN    = 'hierarchy_guard';
	public const SCRIPT    = 'hierarchy_guard_list_script';

	/**
	 * Live child counts by post ID for the rows on this screen.
	 *
	 * @var array<int,int>
	 */
	private array $counts = [];

	/**
	 * Whether the screen's rows were counted.
	 *
	 * @var bool
	 */
	private bool $counted = false;

	/**
	 * Whether the list-table script was asked for.
	 *
	 * @var bool
	 */
	private bool $signalled = false;

	/**
	 * The subtree filter's walk, once run.
	 *
	 * @var array{ids:int[],capped:bool}|null
	 */
	private ?array $walk = null;

	/**
	 * Hooks into core.
	 */
	public function register(): void {
		add_action( 'admin_init', [ $this, 'hook_types' ] );
		add_filter( 'page_row_actions', [ $this, 'row_actions' ], 10, 2 );
		add_filter( 'request', [ $this, 'filter_request' ] );
		add_action( 'restrict_manage_posts', [ $this, 'filter_controls' ], 10, 2 );
	}

	/**
	 * Adds the column to every supported type. Hooked on admin_init rather
	 * than load-edit.php so the row Quick Edit re-renders over Ajax has it too.
	 */
	public function hook_types(): void {
		foreach ( Post_Types::supported() as $type ) {
			add_filter( "manage_{$type}_posts_columns", [ $this, 'columns' ] );
			add_action( "manage_{$type}_posts_custom_column", [ $this, 'render_column' ], 10, 2 );
		}
	}

	/**
	 * The list URL filtered to a page's subtree.
	 *
	 * @param string $post_type Post type name.
	 * @param int    $post_id   Page whose subpages to list.
	 * @return string
	 */
	public static function filter_url( string $post_type, int $post_id ): string {
		return add_query_arg(
			[
				'post_type'     => $post_type,
				self::QUERY_VAR => $post_id,
			],
			admin_url( 'edit.php' )
		);
	}

	/**
	 * The page the list is filtered to, or 0.
	 *
	 * @return int
	 */
	public static function under(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A read-only list filter, like core's own list filters.
		return isset( $_GET[ self::QUERY_VAR ] ) ? absint( wp_unslash( $_GET[ self::QUERY_VAR ] ) ) : 0;
	}

	/**
	 * Adds the Hierarchy column after the title.
	 *
	 * @param mixed $columns Columns.
	 * @return mixed
	 */
	public function columns( $columns ) {
		if ( ! is_array( $columns ) ) {
			return $columns;
		}
		$out = [];
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'title' === $key ) {
				$out[ self::COLUMN ] = __( 'Hierarchy', 'hierarchy-guard' );
			}
		}
		if ( ! isset( $out[ self::COLUMN ] ) ) {
			$out[ self::COLUMN ] = __( 'Hierarchy', 'hierarchy-guard' );
		}
		return $out;
	}

	/**
	 * Prints the parent, then the subpage count as a link to the subtree.
	 *
	 * @param mixed $column  Column name.
	 * @param mixed $post_id Post ID.
	 */
	public function render_column( $column, $post_id ): void {
		if ( self::COLUMN !== $column ) {
			return;
		}
		$post = get_post( (int) $post_id );
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		$type   = (string) $post->post_type;
		$count  = $this->child_count( (int) $post->ID, $type );
		$cap    = Subtree::count_cap();
		$parts  = [];
		$parent = (int) $post->post_parent;
		$above  = $parent > 0 ? get_post( $parent ) : null;

		if ( $above instanceof WP_Post ) {
			$title = _draft_or_post_title( $parent );
			$link  = get_edit_post_link( $parent );
			$name  = is_string( $link ) && '' !== $link ? sprintf( '<a href="%s">%s</a>', esc_url( $link ), $title ) : $title;
			if ( 'trash' === $above->post_status ) {
				/* translators: %s: title of a parent page that is in the trash. */
				$name = sprintf( __( '%s (in the Trash)', 'hierarchy-guard' ), $name );
			}
			$parts[] = '<span class="screen-reader-text">' . esc_html__( 'Parent:', 'hierarchy-guard' ) . ' </span>' . $name;
		}
		if ( $count > 0 ) {
			$this->signal();
			$shown = Subtree::format_count( min( $count, $cap ), $count > $cap );
			/* translators: %s: number of subpages, such as 3 or 1,000+. */
			$label = sprintf( _n( '%s subpage', '%s subpages', min( $count, $cap ), 'hierarchy-guard' ), $shown );
			/* translators: 1: number of subpages, such as 3 or 1,000+, 2: page title. */
			$aria    = sprintf( _n( 'View the %1$s subpage of “%2$s”', 'View the %1$s subpages of “%2$s”', min( $count, $cap ), 'hierarchy-guard' ), $shown, _draft_or_post_title( $post ) );
			$parts[] = sprintf( '<a href="%1$s" aria-label="%2$s">%3$s</a>', esc_url( self::filter_url( $type, (int) $post->ID ) ), esc_attr( $aria ), esc_html( $label ) );
		}

		printf(
			'<span class="hierarchy-guard-cell" data-id="%1$d" data-children="%2$d" data-status="%3$s">%4$s</span>',
			(int) $post->ID,
			(int) min( $count, $cap + 1 ),
			esc_attr( (string) $post->post_status ),
			[] !== $parts ? implode( '<span aria-hidden="true"> · </span>', $parts ) : '<span aria-hidden="true">—</span><span class="screen-reader-text">' . esc_html__( 'Top level, no subpages', 'hierarchy-guard' ) . '</span>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Every part was escaped as it was built.
		);
	}

	/**
	 * Adds "View subpages" to rows with subpages.
	 *
	 * @param mixed $actions Row actions.
	 * @param mixed $post    Post.
	 * @return mixed
	 */
	public function row_actions( $actions, $post ) {
		if ( ! is_array( $actions ) || ! $post instanceof WP_Post || ! Post_Types::is_supported( (string) $post->post_type ) ) {
			return $actions;
		}
		if ( 0 === $this->child_count( (int) $post->ID, (string) $post->post_type ) ) {
			return $actions;
		}

		$actions['hierarchy_guard_subpages'] = sprintf(
			'<a href="%1$s" aria-label="%2$s">%3$s</a>',
			esc_url( self::filter_url( (string) $post->post_type, (int) $post->ID ) ),
			/* translators: %s: page title. */
			esc_attr( sprintf( __( 'View the subpages of “%s”', 'hierarchy-guard' ), _draft_or_post_title( $post ) ) ),
			esc_html__( 'View subpages', 'hierarchy-guard' )
		);
		return $actions;
	}

	/**
	 * Narrows the list query to the filtered page's subtree, and asks for a
	 * sort order so core renders normal paginated rows rather than loading
	 * every page for its indented tree.
	 *
	 * @param mixed $vars Query vars.
	 * @return mixed
	 */
	public function filter_request( $vars ) {
		if ( ! is_array( $vars ) || ! is_admin() || 'edit.php' !== ( $GLOBALS['pagenow'] ?? '' ) ) {
			return $vars;
		}
		$root = self::under();
		$type = (string) ( $vars['post_type'] ?? '' );
		if ( ! self::is_root( $root, $type ) ) {
			return $vars;
		}

		$walk             = $this->walk( $root, $type );
		$vars['post__in'] = [] !== $walk['ids'] ? $walk['ids'] : [ 0 ];

		if ( 'menu_order title' === ( $vars['orderby'] ?? '' ) ) {
			$vars['orderby'] = [
				'menu_order' => 'ASC',
				'title'      => 'ASC',
			];
			unset( $vars['fields'], $vars['posts_per_archive_page'] );
			$vars['posts_per_page'] = self::per_page( $type );
		}

		return $vars;
	}

	/**
	 * Keeps the filter across the list's own filters, and says what is filtered.
	 * The list-table script moves the sentence beside the heading, the way core
	 * shows search results.
	 *
	 * @param mixed $post_type Post type of the list.
	 * @param mixed $which     Which table navigation, top or bottom.
	 */
	public function filter_controls( $post_type, $which = 'top' ): void {
		$root = self::under();
		$type = (string) $post_type;
		if ( 'top' !== $which || ! self::is_root( $root, $type ) ) {
			return;
		}
		$this->signal();

		$crumbs = [];
		foreach ( array_reverse( get_post_ancestors( $root ) ) as $ancestor_id ) {
			$crumbs[] = current_user_can( 'read_post', (int) $ancestor_id )
				? sprintf( '<a href="%s">%s</a>', esc_url( self::filter_url( $type, (int) $ancestor_id ) ), _draft_or_post_title( (int) $ancestor_id ) )
				: esc_html__( '(private page)', 'hierarchy-guard' );
		}
		$crumbs[] = '<strong>' . _draft_or_post_title( $root ) . '</strong>';

		$walk  = $this->walk( $root, $type );
		$clear = remove_query_arg( [ self::QUERY_VAR, 'paged' ] );

		printf( '<input type="hidden" name="%1$s" value="%2$d">', esc_attr( self::QUERY_VAR ), (int) $root );
		echo '<span class="hierarchy-guard-under">';
		/* translators: %s: the filtered page, after its ancestors, such as "Company › About". */
		printf( esc_html__( 'Subpages of %s', 'hierarchy-guard' ), implode( '<span aria-hidden="true"> › </span>', $crumbs ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Every crumb was escaped as it was built.
		if ( $walk['capped'] ) {
			echo ' ' . esc_html(
				sprintf(
					/* translators: %s: the most subpages the filter lists, such as 1,000. */
					__( '(the first %s)', 'hierarchy-guard' ),
					number_format_i18n( Subtree::count_cap() )
				)
			);
		}
		printf( '<span aria-hidden="true"> · </span><a href="%1$s">%2$s</a>', esc_url( $clear ), esc_html__( 'Show all pages', 'hierarchy-guard' ) );
		echo '</span>';
	}

	/**
	 * A row's live child count, counting the whole screen the first time.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $post_type Post type name.
	 * @return int Count, up to the cap plus one.
	 */
	private function child_count( int $post_id, string $post_type ): int {
		if ( ! $this->counted ) {
			$this->counted = true;
			$this->counts  = $this->screen_counts( $post_type );
		}
		if ( ! array_key_exists( $post_id, $this->counts ) ) {
			// A row the screen query did not return, such as a Quick Edit save over Ajax.
			$this->counts += Subtree::child_counts( [ $post_id ], $post_type, Subtree::count_cap() + 1 );
		}
		return $this->counts[ $post_id ] ?? 0;
	}

	/**
	 * Live child counts for the screen's rows, and their parents primed.
	 *
	 * In core's indented tree mode the rows core shows on this page are worked
	 * out from the query's ID and parent rows, without a query, and counted in
	 * one statement like any other screen. A search keeps core's tree query but
	 * shows one flat page of it, which is the slice counted here.
	 *
	 * @param string $post_type Post type name.
	 * @return array<int,int>
	 */
	private function screen_counts( string $post_type ): array {
		global $wp_query;

		if ( ! $wp_query instanceof WP_Query || empty( $wp_query->posts ) ) {
			return [];
		}

		$tree_query = 'id=>parent' === ( $wp_query->query['fields'] ?? '' );
		$per_page   = self::per_page( $post_type );
		$pagenum    = max( 1, (int) $wp_query->get( 'paged' ) );
		$rows       = array_values( (array) $wp_query->posts );

		$parent_of = [];
		foreach ( $rows as $row ) {
			if ( is_object( $row ) && isset( $row->ID ) ) {
				$parent_of[ (int) $row->ID ] = isset( $row->post_parent ) ? (int) $row->post_parent : 0;
			}
		}

		if ( $tree_query && empty( $wp_query->query['s'] ) ) {
			$ids = self::tree_slice( $rows, $pagenum, $per_page );
		} else {
			$start = $tree_query ? ( $pagenum - 1 ) * $per_page : 0;
			$ids   = [];
			foreach ( array_slice( $rows, $start, $per_page ) as $row ) {
				if ( is_object( $row ) && isset( $row->ID ) ) {
					$ids[] = (int) $row->ID;
				}
			}
		}

		$parents = [];
		foreach ( $ids as $id ) {
			if ( ! empty( $parent_of[ $id ] ) ) {
				$parents[] = $parent_of[ $id ];
			}
		}
		_prime_post_caches( array_values( array_unique( $parents ) ), false, false );

		return Subtree::child_counts( $ids, $post_type, Subtree::count_cap() + 1 );
	}

	/**
	 * The IDs core shows on one page of its indented tree, worked out from the
	 * query's ID and parent rows the way WP_Posts_List_Table pages them: each
	 * top-level page followed by its subtree, the ancestors of a page that
	 * starts the screen mid-subtree, then pages whose parent the view leaves out.
	 *
	 * @param array<int,object> $rows     The tree query's rows, with ID and post_parent.
	 * @param int               $pagenum  Page number, from 1.
	 * @param int               $per_page Rows per page.
	 * @return int[]
	 */
	private static function tree_slice( array $rows, int $pagenum, int $per_page ): array {
		$top      = [];
		$children = [];
		foreach ( $rows as $row ) {
			if ( ! is_object( $row ) || ! isset( $row->ID ) ) {
				continue;
			}
			$id     = (int) $row->ID;
			$parent = isset( $row->post_parent ) ? (int) $row->post_parent : 0;
			if ( $parent > 0 && $parent !== $id ) {
				$children[ $parent ][] = $id;
			} else {
				$top[] = $id;
			}
		}

		$start = ( $pagenum - 1 ) * $per_page;
		$end   = $start + $per_page;
		$count = 0;
		$shown = [];
		$walk  = static function ( int $parent_id ) use ( &$walk, &$children, &$count, &$shown, $start, $end ): void {
			foreach ( $children[ $parent_id ] ?? [] as $id ) {
				if ( $count >= $end ) {
					break;
				}
				if ( $count === $start ) {
					// Core prints the ancestors of a page that starts the screen mid-subtree.
					foreach ( get_post_ancestors( $id ) as $ancestor ) {
						$shown[] = (int) $ancestor;
					}
				}
				if ( $count >= $start ) {
					$shown[] = $id;
				}
				++$count;
				$walk( $id );
			}
			unset( $children[ $parent_id ] );
		};

		foreach ( $top as $id ) {
			if ( $count >= $end ) {
				break;
			}
			if ( $count >= $start ) {
				$shown[] = $id;
			}
			++$count;
			$walk( $id );
		}
		foreach ( $children as $orphans ) {
			foreach ( $orphans as $id ) {
				if ( $count >= $end ) {
					break 2;
				}
				if ( $count >= $start ) {
					$shown[] = $id;
				}
				++$count;
			}
		}

		return array_values( array_unique( $shown ) );
	}

	/**
	 * Whether an ID is a page of this list's type the user may read, so the list can be filtered to it.
	 *
	 * @param int    $root_id   Requested page.
	 * @param string $post_type List's post type.
	 * @return bool
	 */
	private static function is_root( int $root_id, string $post_type ): bool {
		return $root_id > 0 && Post_Types::is_supported( $post_type ) && get_post_type( $root_id ) === $post_type && current_user_can( 'read_post', $root_id );
	}

	/**
	 * Asks once for the list-table script: this screen prints something it acts on.
	 */
	private function signal(): void {
		if ( ! $this->signalled ) {
			$this->signalled = true;
			/**
			 * Fires when the list screen needs the plugin's list-table script.
			 *
			 * @internal
			 */
			do_action( 'hierarchy_guard_list_script' );
		}
	}

	/**
	 * The filter's capped walk, run once per request.
	 *
	 * @param int    $root_id   Filtered page.
	 * @param string $post_type Post type name.
	 * @return array{ids:int[],capped:bool}
	 */
	private function walk( int $root_id, string $post_type ): array {
		if ( null === $this->walk ) {
			$this->walk = Subtree::descendant_ids( $root_id, $post_type, Subtree::count_cap() );
		}
		return $this->walk;
	}

	/**
	 * Rows per page on the list screen, as core works it out.
	 *
	 * @param string $post_type Post type name.
	 * @return int
	 */
	private static function per_page( string $post_type ): int {
		$per_page = (int) get_user_option( "edit_{$post_type}_per_page" );
		if ( $per_page < 1 ) {
			$per_page = 20;
		}
		/** This filter is documented in wp-admin/includes/post.php */
		$per_page = (int) apply_filters( "edit_{$post_type}_per_page", $per_page ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound,WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Core's own filter.
		/** This filter is documented in wp-admin/includes/post.php */
		return max( 1, (int) apply_filters( 'edit_posts_per_page', $per_page, $post_type ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's own filter.
	}
}
