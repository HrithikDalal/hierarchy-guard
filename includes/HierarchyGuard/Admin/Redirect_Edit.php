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
 * The Edit view of a recorded redirect, and the Redirects tab's actions:
 * save an edit, delete one record from its row or the Edit view, or delete
 * in bulk. The view and every action check manage_options and a nonce. An
 * action ends on the Redirects tab with a core notice that core's options
 * header prints; a save the records refuse prints the form again, with
 * what was entered and why it was not saved.
 */
final class Redirect_Edit {

	public const NONCE_EDIT   = 'hierarchy_guard_edit_';
	public const NONCE_SAVE   = 'hierarchy_guard_save_';
	public const NONCE_DELETE = 'hierarchy_guard_delete_';
	public const NONCE_BULK   = 'bulk-redirects';

	/**
	 * Records.
	 *
	 * @var Records
	 */
	private Records $records;

	/**
	 * A save the records refused: the record and what was entered.
	 *
	 * @var array{record:array<string,mixed>,url:string,code:int}|null
	 */
	private ?array $refused = null;

	/**
	 * Constructor.
	 *
	 * @param Records $records Records.
	 */
	public function __construct( Records $records ) {
		$this->records = $records;
	}

	/**
	 * The Edit view's link for a record, behind its own nonce.
	 *
	 * @param string $id Record ID.
	 * @return string
	 */
	public static function edit_url( string $id ): string {
		return wp_nonce_url(
			add_query_arg(
				[
					'action'   => 'edit',
					'redirect' => $id,
				],
				Settings_Page::url( 'redirects' )
			),
			self::NONCE_EDIT . $id
		);
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
		if ( 'edit' === $action ) {
			$id = self::requested_id();
			check_admin_referer( self::NONCE_EDIT . $id );
			return null === $this->records->find( $id ) ? $this->gone() : null;
		}
		if ( 'save' === $action ) {
			$id = self::requested_id();
			check_admin_referer( self::NONCE_SAVE . $id );
			return $this->save( $id );
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
	 * Prints the Edit view when the request asks for it, or when a save was
	 * refused: the old URL, read only; one field for a URL or a path; the
	 * code; Save, Cancel and Delete. A top-level root-list entry says where
	 * it leads and starts with the field empty. It works without JavaScript;
	 * with it, Delete asks first.
	 *
	 * @return bool Whether it printed.
	 */
	public function render(): bool {
		if ( null !== $this->refused ) {
			$record = $this->refused['record'];
			$url    = $this->refused['url'];
			$code   = $this->refused['code'];
		} else {
			$record = 'edit' === self::action() ? $this->records->find( self::requested_id() ) : null;
			if ( null === $record ) {
				return false;
			}
			$url  = Records::target_url( $record );
			$code = null !== $record['target'] ? (int) $record['target']['code'] : 0;
		}
		$old     = (string) $record['old'];
		$in_code = '<code>' . esc_html( $old ) . '</code>';
		$help    = [];
		if ( Records::is_top_level( $record ) ) {
			$help[] = esc_html__( 'Leave empty to keep this.', 'hierarchy-guard' );
			$help[] = esc_html__( 'Enter a URL to send these URLs somewhere else.', 'hierarchy-guard' );
		}
		/* translators: %s: the old URL, such as /about/team/. */
		$help[] = sprintf( esc_html__( 'A page on this site: the redirect follows it when it moves, and the URLs under %s go to the matching URLs under it.', 'hierarchy-guard' ), $in_code );
		/* translators: %s: the old URL, such as /about/team/. */
		$help[] = sprintf( esc_html__( 'Any other URL: only %s redirects, and this redirect no longer covers the URLs under it.', 'hierarchy-guard' ), $in_code );
		/* translators: %s: an example path, /about/team/. */
		$help[] = sprintf( esc_html__( 'A path on this site, such as %s, works as well as a full URL.', 'hierarchy-guard' ), '<code>/about/team/</code>' );

		echo '<h2>' . esc_html__( 'Edit redirect', 'hierarchy-guard' ) . '</h2>';
		printf( '<form method="post" action="%s">', esc_url( Settings_Page::url( 'redirects' ) ) );
		echo '<input type="hidden" name="action" value="save">';
		printf( '<input type="hidden" name="redirect" value="%s">', esc_attr( (string) $record['id'] ) );
		wp_nonce_field( self::NONCE_SAVE . $record['id'] );
		echo '<table class="form-table" role="presentation"><tbody>';
		printf( '<tr><th scope="row">%1$s</th><td>%2$s</td></tr>', esc_html__( 'Old URL', 'hierarchy-guard' ), Redirects_Table::old_label( $record ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- old_label() escapes its parts.
		/* translators: 1: an example old URL, /about/team/; 2: where it leads, /team/. */
		$now = Records::is_top_level( $record ) ? '<p>' . sprintf( esc_html__( 'Now redirects to the same page at the top level, for example %1$s to %2$s.', 'hierarchy-guard' ), '<code>/about/team/</code>', '<code>/team/</code>' ) . '</p>' : '';
		printf(
			'<tr><th scope="row"><label for="hierarchy-guard-target">%1$s</label></th><td>%2$s<input type="text" class="regular-text code" id="hierarchy-guard-target" name="target" value="%3$s" dir="ltr" aria-describedby="hierarchy-guard-target-description"><p class="description" id="hierarchy-guard-target-description">%4$s</p></td></tr>',
			esc_html__( 'Redirect to', 'hierarchy-guard' ),
			$now, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
			esc_attr( $url ),
			implode( ' ', $help ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Each sentence is escaped above; the old URL is escaped inside its <code>.
		);
		echo '<tr><th scope="row">' . esc_html__( 'Code', 'hierarchy-guard' ) . '</th><td><fieldset><legend class="screen-reader-text"><span>' . esc_html__( 'Code', 'hierarchy-guard' ) . '</span></legend>';
		printf( '<label><input type="radio" name="code" value="0" aria-describedby="hierarchy-guard-code-automatic-description" %1$s> %2$s</label><p class="description" id="hierarchy-guard-code-automatic-description">%3$s</p>', checked( 0, $code, false ), esc_html__( 'Automatic', 'hierarchy-guard' ), esc_html__( '301, or 302 while restoring a page from the Trash could undo the move.', 'hierarchy-guard' ) );
		printf( '<label><input type="radio" name="code" value="301" %1$s> %2$s</label><br>', checked( 301, $code, false ), esc_html__( '301 (permanent)', 'hierarchy-guard' ) );
		printf( '<label><input type="radio" name="code" value="302" %1$s> %2$s</label>', checked( 302, $code, false ), esc_html__( '302 (temporary)', 'hierarchy-guard' ) );
		echo '</fieldset></td></tr>';
		echo '</tbody></table>';
		echo '<p class="submit">';
		submit_button( __( 'Save', 'hierarchy-guard' ), 'primary', 'submit', false );
		printf( ' <a class="button" href="%1$s">%2$s</a>', esc_url( Settings_Page::url( 'redirects' ) ), esc_html__( 'Cancel', 'hierarchy-guard' ) );
		printf( ' <span id="delete-link"><a class="delete" href="%1$s" data-hierarchy-guard-confirm="%2$s">%3$s</a></span>', esc_url( self::delete_url( (string) $record['id'] ) ), esc_attr( self::confirm_one( $old ) ), esc_html__( 'Delete', 'hierarchy-guard' ) );
		echo '</p>';
		echo '</form>';
		return true;
	}

	/**
	 * Whether this load shows the Edit view: asked for, or a save refused.
	 *
	 * @return bool
	 */
	public function is_editing(): bool {
		return null !== $this->refused || 'edit' === self::action();
	}

	/**
	 * The record ID the request names, as one string.
	 *
	 * @return string
	 */
	private static function requested_id(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The caller checks the nonce for this ID before anything changes.
		return isset( $_REQUEST['redirect'] ) && is_string( $_REQUEST['redirect'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['redirect'] ) ) : '';
	}

	/**
	 * Saves an edit; a refused one is kept for the form to print again.
	 *
	 * @param string $id Record ID.
	 * @return string|null The Redirects tab, or null to print the form again.
	 */
	private function save( string $id ): ?string {
		$record = $this->records->find( $id );
		if ( null === $record ) {
			return $this->gone();
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- handle() checked the save's nonce.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- A URL or a path; Records::save() passes it through esc_url_raw(), and sanitize_text_field() would strip its percent-encoding.
		$url  = isset( $_POST['target'] ) && is_string( $_POST['target'] ) ? trim( wp_unslash( $_POST['target'] ) ) : '';
		$code = isset( $_POST['code'] ) && is_string( $_POST['code'] ) ? absint( wp_unslash( $_POST['code'] ) ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$result = $this->records->save( $record, $url, $code );
		if ( is_wp_error( $result ) ) {
			add_settings_error( 'hierarchy_guard', $result->get_error_code(), esc_html( $result->get_error_message() ), 'error' );
			$this->refused = [
				'record' => $record,
				'url'    => $url,
				'code'   => in_array( $code, [ 301, 302 ], true ) ? $code : 0,
			];
			return null;
		}
		return $this->notice( esc_html__( 'Redirect updated.', 'hierarchy-guard' ), 'success' );
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
		// Deleted since it was found, by another tab or another administrator.
		if ( ! $this->records->delete( $record ) ) {
			return $this->gone();
		}
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
