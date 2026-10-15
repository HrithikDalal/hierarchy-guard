<?php
/**
 * The Redirects tab's actions.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Admin;

use HierarchyGuard\Redirects\Records;

/**
 * Deletes recorded redirects, one from its row or in bulk. Every action
 * checks manage_options and its nonce, and ends on the Redirects tab with a
 * core notice that core's options header prints.
 */
final class Redirect_Edit {

	public const NONCE_DELETE = 'hierarchy_guard_delete_';
	public const NONCE_BULK   = 'bulk-redirects';

	/**
	 * Records.
	 *
	 * @var Records
	 */
	private Records $records;

	/**
	 * Constructor.
	 *
	 * @param Records $records Records.
	 */
	public function __construct( Records $records ) {
		$this->records = $records;
	}

	/**
	 * The Delete link for a record, behind its own nonce.
	 *
	 * @param string $id Record ID.
	 * @return string
	 */
	public static function delete_url( string $id ): string {
		return wp_nonce_url(
			add_query_arg(
				[
					'action'   => 'delete',
					'redirect' => $id,
				],
				Settings_Page::url( 'redirects' )
			),
			self::NONCE_DELETE . $id
		);
	}

	/**
	 * What a Delete asks before it runs, for one record.
	 *
	 * @param string $old The record's old URL.
	 * @return string
	 */
	public static function confirm_one( string $old ): string {
		/* translators: %s: an old URL, such as /about/team/. */
		$question = sprintf( __( 'Delete the redirect from %s?', 'hierarchy-guard' ), $old );
		return $question . ' ' . __( 'This can’t be undone.', 'hierarchy-guard' );
	}

	/**
	 * Runs the Redirects tab's action, before the page prints anything.
	 *
	 * @return string|null Where to send the browser, or null to print the page.
	 */
	public function handle(): ?string {
		$action = self::action();
		if ( '' === $action ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only drops the table form's own nonce and referer from the address.
			return empty( $_REQUEST['_wp_http_referer'] ) ? null : $this->tab_url( false );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to change redirects.', 'hierarchy-guard' ), 403 );
		}
		if ( 'delete' !== $action ) {
			return null;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Each branch checks its nonce before it changes anything.
		$ids   = isset( $_REQUEST['redirect'] ) ? map_deep( wp_unslash( $_REQUEST['redirect'] ), 'sanitize_text_field' ) : '';
		$nonce = isset( $_REQUEST['_wpnonce'] ) && is_string( $_REQUEST['_wpnonce'] ) ? sanitize_key( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		// The table's form, with or without rows ticked, carries the bulk nonce; a row's link its own.
		if ( is_array( $ids ) || false !== wp_verify_nonce( $nonce, self::NONCE_BULK ) ) {
			check_admin_referer( self::NONCE_BULK );
			$ids = is_array( $ids ) ? array_slice( array_values( array_filter( $ids, 'is_string' ) ), 0, Records::PER_PAGE ) : [];
			return [] === $ids ? $this->tab_url( false ) : $this->delete_many( $ids );
		}
		$id = is_string( $ids ) ? $ids : '';
		check_admin_referer( self::NONCE_DELETE . $id );
		return $this->delete_one( $id );
	}

	/**
	 * The action asked for: the top bulk selector's, or the bottom one's, or
	 * a link's. Pressing Filter runs none, as in core's list tables.
	 *
	 * @return string
	 */
	private static function action(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read only, to run nothing.
		if ( ! empty( $_REQUEST['filter_action'] ) ) {
			return '';
		}
		foreach ( [ 'action', 'action2' ] as $key ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The action's nonce is checked before it runs.
			$value = isset( $_REQUEST[ $key ] ) ? sanitize_key( wp_unslash( $_REQUEST[ $key ] ) ) : '';
			if ( '' !== $value && '-1' !== $value ) {
				return $value;
			}
		}
		return '';
	}

	/**
	 * Deletes one record.
	 *
	 * @param string $id Record ID.
	 * @return string The Redirects tab.
	 */
	private function delete_one( string $id ): string {
		$record = $this->records->find( $id );
		if ( null === $record ) {
			return $this->gone();
		}
		$this->records->delete( $record );
		$deleted = esc_html__( 'Redirect deleted.', 'hierarchy-guard' );
		/* translators: %s: the old URL, such as /about/team/. */
		$through = sprintf( esc_html__( '%s no longer redirects through it.', 'hierarchy-guard' ), '<code>' . esc_html( $record['old'] ) . '</code>' );
		return $this->notice( $deleted . ' ' . $through, 'success' );
	}

	/**
	 * Deletes the records a bulk action names, at most a page of them.
	 *
	 * @param string[] $ids Record IDs.
	 * @return string The Redirects tab.
	 */
	private function delete_many( array $ids ): string {
		$count = 0;
		foreach ( $ids as $id ) {
			$record = $this->records->find( $id );
			if ( null !== $record && $this->records->delete( $record ) ) {
				++$count;
			}
		}
		if ( 0 === $count ) {
			return $this->gone( count( $ids ) );
		}
		/* translators: %s: number of redirects. */
		$deleted = sprintf( _n( '%s redirect deleted.', '%s redirects deleted.', $count, 'hierarchy-guard' ), number_format_i18n( $count ) );
		$through = _n( 'Its old URL no longer redirects through it.', 'Their old URLs no longer redirect through them.', $count, 'hierarchy-guard' );
		return $this->notice( esc_html( $deleted . ' ' . $through ), 'success' );
	}

	/**
	 * The notice for records that are no longer there.
	 *
	 * @param int $count How many the request named.
	 * @return string The Redirects tab.
	 */
	private function gone( int $count = 1 ): string {
		return $this->notice( esc_html( _n( 'This redirect no longer exists.', 'These redirects no longer exist.', $count, 'hierarchy-guard' ) ), 'error' );
	}

	/**
	 * Keeps a notice for the next page load, as options.php does, and gives
	 * the Redirects tab's URL that shows it.
	 *
	 * @param string $message Message, escaped HTML.
	 * @param string $type    success or error.
	 * @return string
	 */
	private function notice( string $message, string $type ): string {
		add_settings_error( 'hierarchy_guard', 'hierarchy_guard_redirects', $message, $type );
		set_transient( 'settings_errors', get_settings_errors(), 30 );
		return $this->tab_url( true );
	}

	/**
	 * The Redirects tab, keeping the search, filter and page the request had.
	 *
	 * @param bool $notice Whether the next load shows the notice kept for it.
	 * @return string
	 */
	private function tab_url( bool $notice ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only carried into the next address.
		$paged = isset( $_REQUEST['paged'] ) ? absint( wp_unslash( $_REQUEST['paged'] ) ) : 0;
		$args  = array_filter(
			[
				's'                       => rawurlencode( Redirects_Table::search() ),
				Redirects_Table::TYPE_ARG => Redirects_Table::post_type(),
				'paged'                   => $paged > 1 ? $paged : '',
				'settings-updated'        => $notice ? 'true' : '',
			]
		);
		return add_query_arg( $args, Settings_Page::url( 'redirects' ) );
	}
}
