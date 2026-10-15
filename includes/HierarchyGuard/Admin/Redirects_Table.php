<?php
/**
 * The Redirects tab's table.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Admin;

use HierarchyGuard\Paths;
use HierarchyGuard\Post_Types;
use HierarchyGuard\Redirects\Records;
use HierarchyGuard\Subtree;
use WP_List_Table;
use WP_Post;

if ( ! class_exists( WP_List_Table::class ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Core's list table of the redirects the plugin recorded: Old URL,
 * Redirects to, Code and Status, 20 rows a page sorted by old URL, a search
 * on the old URL, a post type filter when more than one type is supported,
 * and Edit and Delete. There is nothing to add. The filter's request
 * argument is TYPE_ARG, never `post_type`: wp-admin/admin.php reads that one
 * before the page loads and, for a plugin page under Settings, refuses to
 * load it.
 */
final class Redirects_Table extends WP_List_Table {

	public const TYPE_ARG = 'redirect_type';

	/**
	 * Records.
	 *
	 * @var Records
	 */
	private Records $records;

	/**
	 * Each row's status, by record ID.
	 *
	 * @var array<string,string>
	 */
	private array $statuses = [];

	/**
	 * Whether the listing stopped at the cap.
	 *
	 * @var bool
	 */
	private bool $capped = false;

	/**
	 * The page shown, which is the last one when the request asked for one past it.
	 *
	 * @var int|null
	 */
	private ?int $shown = null;

	/**
	 * Constructor.
	 *
	 * @param Records $records Records.
	 */
	public function __construct( Records $records ) {
		parent::__construct(
			[
				'singular' => 'redirect',
				'plural'   => 'redirects',
				'ajax'     => false,
				'screen'   => 'settings_page_' . Settings_Page::SLUG,
			]
		);
		$this->records = $records;
	}

	/**
	 * The search text, from the request.
	 *
	 * @return string
	 */
	public static function search(): string {
		// Decoded first: sanitize_text_field() strips percent-encoded octets, as a pasted URL has them.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- A search reads, it changes nothing; sanitize_text_field() runs on the decoded text.
		return isset( $_REQUEST['s'] ) && is_string( $_REQUEST['s'] ) ? trim( sanitize_text_field( rawurldecode( wp_unslash( $_REQUEST['s'] ) ) ) ) : '';
	}

	/**
	 * The post type filtered on, from the request: a supported type or ''.
	 *
	 * @return string
	 */
	public static function post_type(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A filter reads, it changes nothing.
		$type = isset( $_REQUEST[ self::TYPE_ARG ] ) && is_string( $_REQUEST[ self::TYPE_ARG ] ) ? sanitize_key( wp_unslash( $_REQUEST[ self::TYPE_ARG ] ) ) : '';
		return Post_Types::is_supported( $type ) ? $type : '';
	}

	/**
	 * Columns. A cell carries its column's key as a class, and core's admin
	 * CSS sets `.code` in a monospace font, so the Code column's key is http_code.
	 *
	 * @return array<string,string>
	 */
	public function get_columns(): array {
		return [
			'cb'        => '<input type="checkbox" />',
			'old'       => __( 'Old URL', 'hierarchy-guard' ),
			'target'    => __( 'Redirects to', 'hierarchy-guard' ),
			'http_code' => __( 'Code', 'hierarchy-guard' ),
			'status'    => __( 'Status', 'hierarchy-guard' ),
		];
	}

	/**
	 * Bulk actions: Delete.
	 *
	 * @return array<string,string>
	 */
	protected function get_bulk_actions(): array {
		return [ 'delete' => __( 'Delete', 'hierarchy-guard' ) ];
	}

	/**
	 * The column with the row actions.
	 *
	 * @return string
	 */
	protected function get_primary_column_name(): string {
		return 'old';
	}

	/**
	 * Reads one page of records and their statuses.
	 */
	public function prepare_items(): void {
		$this->_column_headers = [ $this->get_columns(), [], [], 'old' ];

		$page           = $this->records->page( self::post_type(), self::search(), $this->get_pagenum() );
		$this->shown    = $page['page'];
		$this->items    = $page['rows'];
		$this->capped   = $page['capped'];
		$this->statuses = $this->records->statuses( $this->items );
		$this->set_pagination_args(
			[
				'total_items' => $page['total'],
				'per_page'    => Records::PER_PAGE,
			]
		);
	}

	/**
	 * Whether the listing stopped at the cap.
	 *
	 * @return bool
	 */
	public function is_capped(): bool {
		return $this->capped;
	}

	/**
	 * The page shown: the records' own, once read, so the pagination links
	 * agree with the rows when the request asked for a page past the last.
	 *
	 * @return int
	 */
	public function get_pagenum(): int {
		return $this->shown ?? (int) parent::get_pagenum();
	}

	/**
	 * Core's pagination, with the count shown as "1,000+" once older
	 * records exist than the listing read, as the plugin's other counts show it.
	 *
	 * @param string $which Top or bottom.
	 */
	protected function pagination( $which ): void {
		ob_start();
		parent::pagination( $which );
		$html = (string) ob_get_clean();
		if ( $this->capped ) {
			$cap = Subtree::count_cap();
			/* translators: %s: number of redirects, such as 1,000+. */
			$count = sprintf( _n( '%s item', '%s items', $cap, 'hierarchy-guard' ), Subtree::format_count( $cap, true ) );
			$html  = (string) preg_replace( '#<span class="displaying-num">[^<]*</span>#', '<span class="displaying-num">' . esc_html( $count ) . '</span>', $html );
		}
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core's own pagination markup, with an escaped count.
	}

	/**
	 * The message for an empty table.
	 */
	public function no_items(): void {
		if ( '' !== self::post_type() ) {
			esc_html_e( 'No redirects found.', 'hierarchy-guard' );
			return;
		}
		if ( '' !== self::search() ) {
			esc_html_e( 'No redirects match your search.', 'hierarchy-guard' );
			return;
		}
		esc_html_e( 'No redirects have been recorded yet.', 'hierarchy-guard' );
	}

	/**
	 * The post type filter, when more than one type is supported.
	 *
	 * @param string $which Top or bottom.
	 */
	protected function extra_tablenav( $which ): void {
		$types = Post_Types::supported();
		if ( 'top' !== $which || count( $types ) < 2 ) {
			return;
		}
		$current = self::post_type();
		echo '<div class="alignleft actions">';
		echo '<label class="screen-reader-text" for="hierarchy-guard-post-type">' . esc_html__( 'Filter by post type', 'hierarchy-guard' ) . '</label>';
		printf( '<select name="%s" id="hierarchy-guard-post-type">', esc_attr( self::TYPE_ARG ) );
		echo '<option value="">' . esc_html__( 'All post types', 'hierarchy-guard' ) . '</option>';
		foreach ( $types as $type ) {
			$object = get_post_type_object( $type );
			printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $type ), selected( $current, $type, false ), esc_html( null !== $object ? $object->labels->name : $type ) );
		}
		echo '</select>';
		submit_button( __( 'Filter', 'hierarchy-guard' ), '', 'filter_action', false, [ 'id' => 'hierarchy-guard-filter' ] );
		echo '</div>';
	}

	/**
	 * The row's checkbox.
	 *
	 * @param array<string,mixed> $item Record.
	 * @return string
	 */
	protected function column_cb( $item ): string {
		/* translators: %s: an old URL, such as /about/team/. */
		$label = sprintf( __( 'Select the redirect from %s', 'hierarchy-guard' ), (string) $item['old'] );
		return sprintf(
			'<label class="screen-reader-text" for="cb-select-%1$s">%2$s</label><input id="cb-select-%1$s" type="checkbox" name="redirect[]" value="%1$s" data-hierarchy-guard-confirm-one="%3$s" />',
			esc_attr( (string) $item['id'] ),
			esc_html( $label ),
			esc_attr( Redirect_Edit::confirm_one( (string) $item['old'] ) )
		);
	}

	/**
	 * The old URL, and for a page target the URLs under it.
	 *
	 * @param array<string,mixed> $item Record.
	 * @return string
	 */
	protected function column_old( $item ): string {
		return self::old_label( $item );
	}

	/**
	 * Where it redirects: the page, with its states and current URL; the URL
	 * target; or "Top level" for a root-list entry.
	 *
	 * @param array<string,mixed> $item Record.
	 * @return string
	 */
	protected function column_target( $item ): string {
		$url = null !== $item['target'] ? (string) $item['target']['url'] : '';
		if ( '' !== $url ) {
			return '<span dir="ltr">' . esc_html( $url ) . '</span>';
		}
		if ( 0 === (int) $item['holder'] ) {
			return esc_html__( 'Top level', 'hierarchy-guard' );
		}
		$post = get_post( (int) $item['holder'] );
		if ( ! $post instanceof WP_Post ) {
			return '';
		}
		$title = get_the_title( $post );
		$title = '' !== $title ? $title : __( '(no title)', 'hierarchy-guard' );
		$link  = get_edit_post_link( $post );
		$name  = null !== $link ? sprintf( '<a href="%1$s">%2$s</a>', esc_url( $link ), esc_html( $title ) ) : esc_html( $title );
		$now   = get_permalink( $post );
		return $name . _post_states( $post, false ) . ( is_string( $now ) ? '<br><span dir="ltr">' . esc_html( Paths::relative( $now ) ) . '</span>' : '' );
	}

	/**
	 * Automatic, or the code chosen on Edit.
	 *
	 * @param array<string,mixed> $item Record.
	 * @return string
	 */
	protected function column_http_code( $item ): string {
		$code = null !== $item['target'] ? (int) $item['target']['code'] : 0;
		return $code > 0 ? (string) $code : esc_html__( 'Automatic', 'hierarchy-guard' );
	}

	/**
	 * Active, or why the redirect isn't served.
	 *
	 * @param array<string,mixed> $item Record.
	 * @return string
	 */
	protected function column_status( $item ): string {
		return esc_html( self::status_label( $this->statuses[ (string) $item['id'] ] ?? Records::STATUS_ACTIVE ) );
	}

	/**
	 * Edit and Delete, under the old URL.
	 *
	 * @param array<string,mixed> $item        Record.
	 * @param string              $column_name Column.
	 * @param string              $primary     Primary column.
	 * @return string
	 */
	protected function handle_row_actions( $item, $column_name, $primary ): string {
		if ( $primary !== $column_name ) {
			return '';
		}
		$id  = (string) $item['id'];
		$old = (string) $item['old'];
		/* translators: %s: an old URL, such as /about/team/. */
		$delete = sprintf( __( 'Delete the redirect from %s', 'hierarchy-guard' ), $old );
		return $this->row_actions(
			[
				'edit'   => sprintf(
					'<a href="%1$s" aria-label="%2$s">%3$s</a>',
					esc_url( Redirect_Edit::edit_url( $id ) ),
					/* translators: %s: an old URL, such as /about/team/. */
					esc_attr( sprintf( __( 'Edit the redirect from %s', 'hierarchy-guard' ), $old ) ),
					esc_html__( 'Edit', 'hierarchy-guard' )
				),
				'delete' => sprintf(
					'<a href="%1$s" class="submitdelete" aria-label="%2$s" data-hierarchy-guard-confirm="%3$s">%4$s</a>',
					esc_url( Redirect_Edit::delete_url( $id ) ),
					esc_attr( $delete ),
					esc_attr( Redirect_Edit::confirm_one( $old ) ),
					esc_html__( 'Delete', 'hierarchy-guard' )
				),
			]
		);
	}

	/**
	 * A record's old URL as HTML, in code so it reads left to right in a
	 * right-to-left language too, with "and the URLs under it" for a page target.
	 *
	 * @param array<string,mixed> $item Record.
	 * @return string
	 */
	public static function old_label( array $item ): string {
		$old = '<code>' . esc_html( (string) $item['old'] ) . '</code>';
		if ( null !== $item['target'] && '' !== (string) $item['target']['url'] ) {
			return $old;
		}
		/* translators: %s: an old URL, such as /about/team/. */
		return sprintf( esc_html__( '%s and the URLs under it', 'hierarchy-guard' ), $old );
	}

	/**
	 * The words for a status.
	 *
	 * @param string $status One of the Records::STATUS_ constants.
	 * @return string
	 */
	public static function status_label( string $status ): string {
		switch ( $status ) {
			case Records::STATUS_IN_USE:
				return __( 'Not active: a page uses this URL', 'hierarchy-guard' );
			case Records::STATUS_UNPUBLISHED:
				return __( 'Not active: the page it points to isn’t published', 'hierarchy-guard' );
			case Records::STATUS_OFF:
				return __( 'Not active: built-in redirects are off', 'hierarchy-guard' );
		}
		return __( 'Active', 'hierarchy-guard' );
	}
}
