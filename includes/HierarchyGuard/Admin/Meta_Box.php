<?php
/**
 * The Hierarchy box in the classic editor.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Admin;

use HierarchyGuard\Export\Csv;
use HierarchyGuard\Overview;
use HierarchyGuard\Post_Types;
use HierarchyGuard\REST\Hierarchy_Controller;
use HierarchyGuard\Settings;
use HierarchyGuard\Subtree;
use WP_Post;

/**
 * A read-only box with the same data as the block editor panel. The
 * classic-editor script asks for confirmation when the form is submitted with
 * a changed slug or parent on a published page with subpages.
 */
final class Meta_Box {

	/**
	 * Overview builder.
	 *
	 * @var Overview
	 */
	private Overview $overview;

	/**
	 * Constructor.
	 *
	 * @param Overview $overview Overview builder.
	 */
	public function __construct( Overview $overview ) {
		$this->overview = $overview;
	}

	/**
	 * Hooks into core.
	 */
	public function register(): void {
		add_action( 'add_meta_boxes', [ $this, 'add' ], 10, 2 );
	}

	/**
	 * Adds the box to supported types, in the classic editor only.
	 *
	 * @param mixed $post_type Post type of the screen.
	 * @param mixed $post      Post being edited.
	 */
	public function add( $post_type, $post = null ): void {
		if ( ! is_string( $post_type ) || ! Post_Types::is_supported( $post_type ) || ! $post instanceof WP_Post ) {
			return;
		}
		add_meta_box(
			'hierarchy-guard',
			__( 'Hierarchy', 'hierarchy-guard' ),
			[ $this, 'render' ],
			$post_type,
			'side',
			'default',
			[ '__back_compat_meta_box' => true ]
		);
	}

	/**
	 * Prints the box.
	 *
	 * @param WP_Post $post Post being edited.
	 */
	public function render( WP_Post $post ): void {
		$data        = $this->overview->describe( $post );
		$descendants = (int) $data['descendants']['count'];

		printf(
			'<div class="hierarchy-guard-box" data-descendants="%1$d" data-capped="%2$d" data-status="%3$s" data-serving="%4$d">',
			(int) $descendants,
			$data['descendants']['capped'] ? 1 : 0,
			esc_attr( (string) $data['status'] ),
			$data['redirects']['serving'] ? 1 : 0
		);

		echo '<p><strong>' . esc_html__( 'Parent pages', 'hierarchy-guard' ) . '</strong><br>';
		if ( [] === $data['ancestors'] ) {
			esc_html_e( 'None.', 'hierarchy-guard' );
			echo ' ';
			esc_html_e( 'This is a top-level page.', 'hierarchy-guard' );
		} else {
			$crumbs = array_map( [ self::class, 'link' ], $data['ancestors'] );
			echo implode( '<span aria-hidden="true"> › </span>', $crumbs ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Each crumb is escaped by link().
		}
		echo '</p>';

		echo '<p><strong>' . esc_html__( 'Subpages', 'hierarchy-guard' ) . '</strong><br>';
		if ( 0 === (int) $data['children_total'] ) {
			esc_html_e( 'No subpages.', 'hierarchy-guard' );
			echo '</p>';
		} else {
			echo esc_html( self::counts_line( $data ) ) . '</p><ul class="hierarchy-guard-box__children">';
			foreach ( $data['children'] as $child ) {
				$note = (int) $child['children_count'] > 0
					/* translators: %s: number of subpages. */
					? sprintf( _n( '%s subpage', '%s subpages', (int) $child['children_count'], 'hierarchy-guard' ), Subtree::format_count( (int) $child['children_count'], (bool) $child['children_capped'] ) )
					: '';
				$state = 'publish' !== $child['status'] ? (string) $child['status_label'] : '';
				printf(
					'<li>%1$s%2$s%3$s</li>',
					self::link( $child ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by link().
					'' !== $state ? '<span aria-hidden="true"> — </span>' . esc_html( $state ) : '',
					'' !== $note ? '<span aria-hidden="true"> · </span>' . esc_html( $note ) : ''
				);
			}
			echo '</ul>';
			if ( $data['has_more'] || $descendants > count( $data['children'] ) || ! empty( $data['descendants']['capped'] ) ) {
				printf(
					'<p><a href="%1$s">%2$s</a></p>',
					esc_url( (string) $data['list_url'] ),
					/* translators: %s: number of subpages in all, such as 12 or 1,000+. */
					esc_html( sprintf( _n( 'View all %s subpage in the list', 'View all %s subpages in the list', $descendants, 'hierarchy-guard' ), Subtree::format_count( $descendants, (bool) $data['descendants']['capped'] ) ) )
				);
			}
		}

		if ( [] !== $data['old_paths'] ) {
			echo '<p><strong>' . esc_html__( 'Old URLs', 'hierarchy-guard' ) . '</strong></p><ul class="hierarchy-guard-box__old">';
			$provider = $data['redirects']['provider'];
			$layout   = null !== $provider ? Csv::layout( (string) $provider['csv'] ) : 'generic';
			foreach ( $data['old_paths'] as $old ) {
				if ( 'in_use' === $old['state'] ) {
					$state = __( 'Used by another page', 'hierarchy-guard' );
				} elseif ( 'trash' === $old['state'] ) {
					$state = __( 'In the Trash', 'hierarchy-guard' );
				} elseif ( 'none' === $old['state'] ) {
					$state = __( 'Not redirected', 'hierarchy-guard' );
				} elseif ( $data['redirects']['serving'] ) {
					$state = __( 'Redirects here', 'hierarchy-guard' );
				} elseif ( null !== $provider && '' !== $provider['name'] ) {
					/* translators: %s: name of the redirect plugin. */
					$state = sprintf( __( 'Add in %s', 'hierarchy-guard' ), $provider['name'] );
				} else {
					$state = __( 'Not redirected', 'hierarchy-guard' );
				}
				// Shown decoded, as core shows permalinks; the download keeps the stored path.
				$shown = rawurldecode( (string) $old['url'] );
				printf(
					'<li><code>%1$s</code><span aria-hidden="true"> · </span>%2$s<span aria-hidden="true"> · </span><a href="%3$s" download><span aria-hidden="true">%4$s</span><span class="screen-reader-text">%5$s</span></a></li>',
					esc_html( $shown ),
					esc_html( $state ),
					esc_url( self::csv_url( (int) $post->ID, (string) $old['path'], $layout ) ),
					esc_html__( 'Download redirects (CSV)', 'hierarchy-guard' ),
					/* translators: %s: an old URL, such as /about-us/. Starts with the visible link text "Download redirects (CSV)". */
					esc_html( sprintf( __( 'Download redirects (CSV) for %s', 'hierarchy-guard' ), $shown ) )
				);
			}
			echo '</ul>';
		}

		$trash = self::trash_sentence( $data );
		if ( '' !== $trash ) {
			echo '<p class="description">' . esc_html( $trash ) . '</p>';
		}
		echo '</div>';
	}

	/**
	 * The download URL for the pairs behind an old path recorded on a post.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $old_path Old path recorded on it.
	 * @param string $layout   CSV layout.
	 * @return string
	 */
	public static function csv_url( int $post_id, string $old_path, string $layout ): string {
		return add_query_arg(
			[
				'from'     => rawurlencode( $old_path ),
				'format'   => $layout,
				'_wpnonce' => wp_create_nonce( 'wp_rest' ),
			],
			rest_url( Hierarchy_Controller::NAMESPACE . '/posts/' . $post_id . '/csv' )
		);
	}

	/**
	 * "4 direct · 12 in total".
	 *
	 * @param array<string,mixed> $data Overview.
	 * @return string
	 */
	public static function counts_line( array $data ): string {
		$direct = Subtree::format_count( (int) $data['children_total'], (bool) $data['children_capped'] );
		$all    = Subtree::format_count( (int) $data['descendants']['count'], (bool) $data['descendants']['capped'] );
		/* translators: 1: number of direct subpages, 2: number of subpages at every level. */
		return sprintf( __( '%1$s direct · %2$s in total', 'hierarchy-guard' ), $direct, $all );
	}

	/**
	 * What trashing the page would do to its subpages under its policy.
	 *
	 * @param array<string,mixed> $data Overview.
	 * @return string
	 */
	public static function trash_sentence( array $data ): string {
		$children        = (int) $data['children_total'];
		$children_capped = (bool) ( $data['children_capped'] ?? false );
		if ( 0 === $children ) {
			return '';
		}
		$all        = (int) $data['descendants']['count'];
		$all_capped = (bool) ( $data['descendants']['capped'] ?? false );

		switch ( $data['trash_policy'] ) {
			case Settings::POLICY_TRASH_CHILDREN:
				/* translators: %s: number of subpages at every level. */
				return sprintf( _n( 'Trashing this page moves its %s subpage to the Trash too.', 'Trashing this page moves its %s subpages to the Trash too.', $all, 'hierarchy-guard' ), Subtree::format_count( $all, $all_capped ) );
			case Settings::POLICY_KEEP:
				/* translators: %s: number of direct subpages. */
				return sprintf( _n( 'Trashing this page leaves its %s subpage where it is.', 'Trashing this page leaves its %s subpages where they are.', $children, 'hierarchy-guard' ), Subtree::format_count( $children, $children_capped ) );
		}

		if ( null !== $data['trash_to'] ) {
			/* translators: 1: number of direct subpages, 2: title of the page they would move under. */
			return sprintf( _n( 'Trashing this page moves its %1$s subpage under “%2$s”.', 'Trashing this page moves its %1$s subpages under “%2$s”.', $children, 'hierarchy-guard' ), Subtree::format_count( $children, $children_capped ), $data['trash_to']['title'] );
		}
		/* translators: %s: number of direct subpages. */
		return sprintf( _n( 'Trashing this page makes its %s subpage a top-level page.', 'Trashing this page makes its %s subpages top-level pages.', $children, 'hierarchy-guard' ), Subtree::format_count( $children, $children_capped ) );
	}

	/**
	 * A post's title, linked to its editor when the user may edit it and core
	 * would open it: never for a page in the Trash.
	 *
	 * @param array<string,mixed> $ref Post reference from Overview.
	 * @return string HTML.
	 */
	private static function link( array $ref ): string {
		$title = esc_html( (string) $ref['title'] );
		if ( null === $ref['edit_link'] || 'trash' === get_post_status( (int) $ref['id'] ) ) {
			return $title;
		}
		return sprintf( '<a href="%1$s">%2$s</a>', esc_url( (string) $ref['edit_link'] ), $title );
	}
}
