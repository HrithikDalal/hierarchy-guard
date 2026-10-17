<?php
/**
 * The Tree view of a supported list screen.
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
	 * What the screen knows about each tree row, by post ID: its level, its
	 * parent, its place among its siblings, and for an opened row the page of
	 * subpages shown and whether more follow.
	 *
	 * @var array<int,array{level:int,parent:int,position:int|null,setsize:int|null,page:int,open:bool,shown:int,more:bool,capped:bool}>
	 */
	private array $rows = [];

	/**
	 * Hooks into core.
	 */
	public function register(): void {
		add_filter( 'request', [ $this, 'filter_request' ] );
		add_filter( 'the_posts', [ $this, 'open_levels' ], 10, 2 );
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
		if ( ! self::on_list() || ! Post_Types::is_supported( $post_type ) ) {
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
		$this->keep_page_on_undo();
		$type = self::screen_type();
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
		// Core still prints its hidden Quick Edit and Bulk Edit forms, each with a Parent dropdown of every page.
		add_filter( 'quick_edit_dropdown_pages_args', [ $this, 'no_parent_pages' ] );
		add_action( 'restrict_manage_posts', [ $this, 'controls' ], 10, 2 );
		add_action( 'manage_posts_extra_tablenav', [ $this, 'collapse_all' ] );
		if ( 'tree' === $mode ) {
			add_filter( 'admin_body_class', [ $this, 'body_class' ] );
			// A search's rows are core's, and the browser's referrer already returns to the results.
			add_filter( 'page_row_actions', [ $this, 'row_actions' ], 20, 2 );
		}
	}

	/**
	 * Core's hidden Quick Edit and Bulk Edit forms can't be reached from the
	 * tree, so their Parent dropdowns read no pages: one ID that matches
	 * nothing, and core prints no dropdown.
	 *
	 * @param mixed $args wp_dropdown_pages() arguments.
	 * @return mixed
	 */
	public function no_parent_pages( $args ) {
		if ( is_array( $args ) ) {
			$args['include'] = [ 0 ];
		}
		return $args;
	}

	/**
	 * Adds "Tree" to the views row.
	 *
	 * @param mixed $views Views, by key.
	 * @return mixed
	 */
	public function views( $views ) {
		$type = self::screen_type();
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
	 * Notes each top-level row's place and, with hierarchy_guard_open, puts
	 * the opened page's rows after their parent in core's results, so core
	 * prints them as its own rows with its level-N class. Each level above
	 * the opened page shows only the page that leads down to it; the opened
	 * page shows its page of 50 subpages, hierarchy_guard_children_page. A
	 * page past the view's last one gets the view's own count, so core sends
	 * the user to its last page.
	 *
	 * @param mixed $posts Posts.
	 * @param mixed $query Query.
	 * @return mixed
	 */
	public function open_levels( $posts, $query ) {
		if ( ! is_array( $posts ) || ! $query instanceof WP_Query || ! self::on_list() || ! $query->is_main_query() ) {
			return $posts;
		}
		$type = $query->get( 'post_type' );
		$mode = is_string( $type ) ? $this->mode( $type ) : '';
		if ( '' === $mode ) {
			return $posts;
		}
		if ( [] === $posts && (int) $query->get( 'paged' ) > 1 ) {
			// Past the last page core finds nothing and counts every page of the type instead; one row of the view's own query gives its count.
			$count                = new WP_Query(
				array_merge(
					$query->query_vars,
					[
						'paged'                  => 1,
						'posts_per_page'         => 1,
						'fields'                 => 'ids',
						'no_found_rows'          => false,
						'update_post_meta_cache' => false,
						'update_post_term_cache' => false,
					]
				)
			);
			$per_page             = (int) $query->get( 'posts_per_page' );
			$query->found_posts   = (int) $count->found_posts;
			$query->max_num_pages = $per_page > 0 ? (int) ceil( $query->found_posts / $per_page ) : 1;
			return $posts;
		}
		if ( 'tree' !== $mode ) {
			return $posts;
		}

		$start      = ( max( 1, (int) $query->get( 'paged' ) ) - 1 ) * List_Table::per_page( $type );
		$total      = (int) $query->found_posts;
		$chain      = $this->chain( self::opened(), $type );
		$this->rows = [];
		$out        = [];
		foreach ( array_values( $posts ) as $index => $post ) {
			$out[] = $post;
			if ( ! $post instanceof WP_Post ) {
				continue;
			}
			$this->rows[ (int) $post->ID ] = self::fact( 0, 0, $start + $index + 1, $total, 1 );
			if ( [] !== $chain && (int) $post->ID === $chain[0] ) {
				$this->open( $chain, 0, $type, $out );
			}
		}
		return $out;
	}

	/**
	 * What the screen knows about a tree row, or null when it is not one.
	 *
	 * @param int $post_id Post ID.
	 * @return array{level:int,parent:int,position:int|null,setsize:int|null,page:int,open:bool,shown:int,more:bool,capped:bool}|null
	 */
	public function row( int $post_id ): ?array {
		return $this->rows[ $post_id ] ?? null;
	}

	/**
	 * The Tree view at its current page and sort, opened at a page, and at a
	 * page of that page's subpages. Only the view's own arguments are kept.
	 *
	 * @param string $post_type     Post type name.
	 * @param int    $open          Page to open, or 0 for none.
	 * @param int    $children_page Page of its subpages, from 1.
	 * @return string
	 */
	public static function place_url( string $post_type, int $open = 0, int $children_page = 1 ): string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only list arguments, carried over as core carries them.
		$orderby = isset( $_GET['orderby'] ) && is_string( $_GET['orderby'] ) ? sanitize_text_field( wp_unslash( $_GET['orderby'] ) ) : '';
		$order   = isset( $_GET['order'] ) && is_string( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : '';
		$month   = isset( $_GET['m'] ) && is_string( $_GET['m'] ) ? absint( wp_unslash( $_GET['m'] ) ) : 0;
		$paged   = isset( $_GET['paged'] ) && is_string( $_GET['paged'] ) ? absint( wp_unslash( $_GET['paged'] ) ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$args = [
			// add_query_arg() doesn't encode: a sort with & or # would add arguments or a fragment.
			'orderby'               => '' !== $orderby ? rawurlencode( $orderby ) : null,
			'order'                 => in_array( $order, [ 'asc', 'desc' ], true ) ? $order : null,
			'm'                     => $month > 0 ? $month : null,
			'paged'                 => $paged > 1 ? $paged : null,
			self::OPEN_VAR          => $open > 0 ? $open : null,
			self::CHILDREN_PAGE_VAR => $open > 0 && $children_page > 1 ? $children_page : null,
		];
		return add_query_arg( array_filter( $args, static fn( $value ): bool => null !== $value ), self::url( $post_type ) );
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
	 * The trash link carries the tree's place as core's _wp_http_referer, so
	 * core's redirect after the trash lands on the same page of top-level
	 * pages, opened at the trashed page's parent.
	 *
	 * @param mixed $actions Row actions.
	 * @param mixed $post    Post.
	 * @return mixed
	 */
	public function row_actions( $actions, $post ) {
		if ( ! is_array( $actions ) || ! isset( $actions['trash'] ) || ! $post instanceof WP_Post ) {
			return $actions;
		}
		$row   = $this->row( (int) $post->ID );
		$place = self::place_url( (string) $post->post_type, null !== $row ? $row['parent'] : 0, null !== $row ? $row['page'] : 1 );

		$actions['trash'] = (string) preg_replace_callback(
			'/href="([^"]*)"/',
			static fn( array $href ): string => 'href="' . esc_url( add_query_arg( '_wp_http_referer', rawurlencode( $place ), html_entity_decode( $href[1], ENT_QUOTES, 'UTF-8' ) ) ) . '"',
			(string) $actions['trash'],
			1
		);
		return $actions;
	}

	/**
	 * Keeps the view across Search and the date filter, says where "Back to
	 * the tree" leads in a search, and leaves what the script needs.
	 *
	 * @param mixed $post_type Post type of the list.
	 * @param mixed $which     Which table navigation, top or bottom.
	 */
	public function controls( $post_type, $which = 'top' ): void {
		$type = (string) $post_type;
		$mode = $this->mode( $type );
		if ( 'top' !== $which || '' === $mode ) {
			return;
		}
		/** This action is documented in includes/HierarchyGuard/Admin/List_Table.php */
		do_action( 'hierarchy_guard_list_script' );

		printf( '<input type="hidden" name="%1$s" value="%2$s">', esc_attr( self::QUERY_VAR ), esc_attr( self::VIEW ) );
		if ( 'search' === $mode ) {
			printf( '<span class="hierarchy-guard-back"><a href="%1$s">%2$s</a></span>', esc_url( self::url( $type ) ), esc_html__( 'Back to the tree', 'hierarchy-guard' ) );
			return;
		}

		global $wp_query;
		$per_page = List_Table::per_page( $type );
		$paged    = $wp_query instanceof WP_Query ? max( 1, (int) $wp_query->get( 'paged' ) ) : 1;
		printf(
			'<span class="hierarchy-guard-tree" hidden data-type="%1$s" data-place="%2$s" data-total="%3$d" data-start="%4$d" data-per-page="%5$d"></span>',
			esc_attr( $type ),
			esc_url( self::place_url( $type ) ),
			$wp_query instanceof WP_Query ? (int) $wp_query->found_posts : 0,
			(int) ( ( $paged - 1 ) * $per_page ),
			(int) self::PER_PAGE
		);
	}

	/**
	 * "Collapse all" above the tree: a link that reloads the view with nothing
	 * opened, which the script turns into a button that needs no request.
	 *
	 * @param mixed $which Which table navigation, top or bottom.
	 */
	public function collapse_all( $which ): void {
		$type = self::screen_type();
		if ( 'top' !== $which || 'tree' !== $this->mode( $type ) ) {
			return;
		}
		printf(
			'<div class="alignleft actions"><a class="hierarchy-guard-collapse" href="%1$s">%2$s</a></div>',
			esc_url( self::place_url( $type ) ),
			esc_html__( 'Collapse all', 'hierarchy-guard' )
		);
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
	 * Undo on core's trash notice redirects to the page it was clicked on,
	 * but core sets the page number from the Undo request, which has none.
	 * For the tree, the page number comes back from that referring page.
	 */
	public function keep_page_on_undo(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Core checks the bulk-posts nonce before it redirects.
		if ( ! isset( $_GET['doaction'] ) || 'undo' !== $_GET['doaction'] ) {
			return;
		}
		$referer = wp_get_referer();
		$query   = is_string( $referer ) ? (string) wp_parse_url( $referer, PHP_URL_QUERY ) : '';
		parse_str( $query, $args );
		if ( self::VIEW !== ( $args[ self::QUERY_VAR ] ?? '' ) ) {
			return;
		}
		$paged = absint( $args['paged'] ?? 0 );
		add_filter(
			'wp_redirect',
			static function ( $location ) use ( $paged ) {
				if ( ! is_string( $location ) ) {
					return $location;
				}
				return $paged > 1 ? add_query_arg( 'paged', $paged, $location ) : remove_query_arg( 'paged', $location );
			}
		);
	}

	/**
	 * The page hierarchy_guard_open names, or 0. A negative number names
	 * nothing: its sign is not stripped.
	 *
	 * @return int
	 */
	public static function opened(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A read-only list argument.
		$open = isset( $_GET[ self::OPEN_VAR ] ) && is_string( $_GET[ self::OPEN_VAR ] ) ? intval( wp_unslash( $_GET[ self::OPEN_VAR ] ) ) : 0;
		return max( 0, $open );
	}

	/**
	 * The page of the opened page's subpages, from 1; a number below 1 is
	 * the first page.
	 *
	 * @return int
	 */
	public static function children_page(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A read-only list argument.
		return isset( $_GET[ self::CHILDREN_PAGE_VAR ] ) && is_string( $_GET[ self::CHILDREN_PAGE_VAR ] ) ? max( 1, intval( wp_unslash( $_GET[ self::CHILDREN_PAGE_VAR ] ) ) ) : 1;
	}

	/**
	 * The pages to open, top level first, ending at the requested page. A
	 * level opens only for a user who may edit it, as the route requires.
	 *
	 * @param int    $open      Requested page.
	 * @param string $post_type List's post type.
	 * @return int[]
	 */
	private function chain( int $open, string $post_type ): array {
		$post = $open > 0 ? get_post( $open ) : null;
		if ( ! $post instanceof WP_Post || $post_type !== $post->post_type || 'trash' === $post->post_status ) {
			return [];
		}
		$chain   = array_reverse( array_map( 'intval', get_post_ancestors( $post ) ) );
		$chain[] = $open;
		foreach ( $chain as $index => $id ) {
			if ( ! current_user_can( 'edit_post', $id ) ) {
				return array_slice( $chain, 0, $index );
			}
		}
		return $chain;
	}

	/**
	 * Puts one opened level into the output. A level above the opened page
	 * shows only the page that leads down, then opens the next level inside
	 * it; the opened page shows one page of 50 subpages. So the rows grow
	 * with the opened page's depth plus 50, never with a subtree.
	 *
	 * @param int[]             $chain Pages to open, top level first.
	 * @param int               $level Index of the page opened here.
	 * @param string            $type  Post type name.
	 * @param array<int,mixed> $out   Rows so far.
	 */
	private function open( array $chain, int $level, string $type, array &$out ): void {
		$node    = $chain[ $level ];
		$next    = $chain[ $level + 1 ] ?? 0;
		$cap     = Subtree::count_cap();
		$ordered = array_map( 'intval', array_column( Subtree::ordered_children( [ $node ], $type, $cap ), 'ID' ) );
		$found   = $next > 0 ? array_search( $next, $ordered, true ) : false;
		$total   = Subtree::count_live_children( $node, $type, $cap + 1 );
		$capped  = $total > $cap;
		$fact    = $this->rows[ $node ] ?? self::fact( $level, 0, null, null, 1 );

		if ( false !== $found ) {
			// A level above the opened page: only the path down.
			$page                = intdiv( (int) $found, self::PER_PAGE ) + 1;
			$this->rows[ $node ] = array_merge(
				$fact,
				[
					'open'  => true,
					'shown' => $page,
				]
			);
			$child               = get_post( $next );
			if ( $child instanceof WP_Post ) {
				$this->rows[ $next ] = self::fact( $level + 1, $node, $capped ? null : (int) $found + 1, $capped ? null : $total, $page );
				$out[]               = $child;
				$this->open( $chain, $level + 1, $type, $out );
			}
			return;
		}

		// The opened page, or a level whose next page lies past the cap: one page of 50.
		$page = min( $next > 0 ? 1 : self::children_page(), max( 1, (int) ceil( count( $ordered ) / self::PER_PAGE ) ) );
		$ids  = array_slice( $ordered, ( $page - 1 ) * self::PER_PAGE, self::PER_PAGE );
		// Every row put in core's results, the path down included, with its meta and terms in one go, as core's own query reads its rows.
		_prime_post_caches( array_merge( array_slice( $chain, 1, $level ), $ids ), true, true );
		$this->rows[ $node ] = array_merge(
			$fact,
			[
				'open'   => true,
				'shown'  => $page,
				'more'   => $page * self::PER_PAGE < min( $total, $cap ),
				'capped' => $capped && $page * self::PER_PAGE >= $cap,
			]
		);
		foreach ( $ids as $index => $id ) {
			$child = get_post( $id );
			if ( ! $child instanceof WP_Post || ! current_user_can( 'read_post', $id ) ) {
				continue;
			}
			$position          = ( $page - 1 ) * self::PER_PAGE + $index + 1;
			$this->rows[ $id ] = self::fact( $level + 1, $node, $capped ? null : $position, $capped ? null : $total, $page );
			$out[]             = $child;
		}
	}

	/**
	 * One row's facts.
	 *
	 * @param int      $level    Depth, 0 for the top level.
	 * @param int      $parent_id Parent post ID, 0 for the top level.
	 * @param int|null $position Place among its siblings, from 1, when known.
	 * @param int|null $setsize  Number of its siblings, when known.
	 * @param int      $page     The page of its parent's subpages it is on.
	 * @return array{level:int,parent:int,position:int|null,setsize:int|null,page:int,open:bool,shown:int,more:bool,capped:bool}
	 */
	private static function fact( int $level, int $parent_id, ?int $position, ?int $setsize, int $page ): array {
		return [
			'level'    => $level,
			'parent'   => $parent_id,
			'position' => $position,
			'setsize'  => $setsize,
			'page'     => $page,
			'open'     => false,
			'shown'    => 0,
			'more'     => false,
			'capped'   => false,
		];
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

	/**
	 * The post type of the current screen, or ''.
	 *
	 * @return string
	 */
	private static function screen_type(): string {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return null !== $screen ? (string) $screen->post_type : '';
	}
}
