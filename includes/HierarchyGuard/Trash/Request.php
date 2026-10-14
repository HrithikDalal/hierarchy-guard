<?php
/**
 * What the current admin request is about to do.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Trash;

/**
 * Core's Pages list trashes and restores bulk selections one ID at a time and
 * stops with wp_die() when an ID is already in, or already out of, the trash.
 * The plugin leaves those IDs to core instead of handling them as subpages.
 * Its Bulk Edit saves a selection one page at a time too.
 */
final class Request {

	/**
	 * Post IDs that core's bulk trash or untrash in edit.php will process in this request.
	 *
	 * @return int[]
	 */
	public static function core_ids(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read only; core verified the bulk-posts nonce before it calls wp_trash_post() or wp_untrash_post().
		if ( 'edit.php' !== ( $GLOBALS['pagenow'] ?? '' ) || ! empty( $_REQUEST['filter_action'] ) ) {
			return [];
		}
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
		if ( ! in_array( $action, [ 'trash', 'untrash' ], true ) ) {
			return [];
		}

		if ( isset( $_REQUEST['ids'] ) ) {
			$raw = explode( ',', sanitize_text_field( wp_unslash( $_REQUEST['ids'] ) ) );
		} elseif ( isset( $_REQUEST['post'] ) ) {
			$raw = array_map( 'sanitize_text_field', (array) wp_unslash( $_REQUEST['post'] ) );
		} else {
			$raw = [];
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return array_values( array_unique( array_filter( array_map( 'absint', $raw ) ) ) );
	}

	/**
	 * The Bulk Edit selection last read, with its IDs and their key.
	 *
	 * @var array{raw:array<int|string,mixed>,ids:int[],key:string}|null
	 */
	private static ?array $bulk = null;

	/**
	 * Post IDs core's Bulk Edit in edit.php saves in this request, in the
	 * list's order, which bulk_edit_posts() keeps.
	 *
	 * @return int[]
	 */
	public static function bulk_edit_ids(): array {
		return self::bulk_edit()['ids'];
	}

	/**
	 * The same IDs as one string, to tell one selection from another; empty
	 * outside a Bulk Edit.
	 *
	 * @return string
	 */
	public static function bulk_edit_key(): string {
		return self::bulk_edit()['key'];
	}

	/**
	 * The Bulk Edit selection, parsed once per request: every save in a Bulk
	 * Edit asks for it, and it can hold 999 IDs. It is parsed again only
	 * when the request's selection is a different one, as in tests.
	 *
	 * @return array{ids:int[],key:string,raw?:array<int|string,mixed>} The IDs and their key; inside a Bulk Edit also raw, the selection as the request sent it, kept only to tell whether it changed.
	 */
	private static function bulk_edit(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read only; core verified the bulk-posts nonce before bulk_edit_posts() saves anything.
		if ( 'edit.php' !== ( $GLOBALS['pagenow'] ?? '' ) || ! isset( $_REQUEST['bulk_edit'], $_REQUEST['post'] ) ) {
			return [
				'ids' => [],
				'key' => '',
			];
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Only compared with the selection read last, to tell whether it changed; it is sanitized below.
		$raw = (array) $_REQUEST['post'];
		if ( null === self::$bulk || $raw !== self::$bulk['raw'] ) {
			$ids        = array_values( array_unique( array_filter( array_map( 'absint', array_map( 'sanitize_text_field', (array) wp_unslash( $raw ) ) ) ) ) );
			self::$bulk = [
				'raw' => $raw,
				'ids' => $ids,
				'key' => implode( ',', $ids ),
			];
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return self::$bulk;
	}
}
