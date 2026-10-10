<?php
/**
 * One sentence about subpages, added to core's trash and restore notices.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Admin;

use HierarchyGuard\Post_Types;
use HierarchyGuard\Settings;
use HierarchyGuard\Subtree;
use HierarchyGuard\Trash\Change_Log;

/**
 * Trashing from the editor or the Pages list lands on core's "moved to the
 * Trash" notice, which says nothing about the subpages that moved with it.
 * This appends what happened to them, once per change.
 */
final class Trash_Notice {

	/**
	 * Change log.
	 *
	 * @var Change_Log
	 */
	private Change_Log $log;

	/**
	 * Constructor.
	 *
	 * @param Change_Log $log Change log.
	 */
	public function __construct( Change_Log $log ) {
		$this->log = $log;
	}

	/**
	 * Hooks into core.
	 */
	public function register(): void {
		add_filter( 'bulk_post_updated_messages', [ $this, 'append' ], 10, 2 );
	}

	/**
	 * Appends the subpage sentences to core's trashed or untrashed message.
	 *
	 * @param mixed $messages Messages by post type, then by action.
	 * @param mixed $counts   Counts by action.
	 * @return mixed
	 */
	public function append( $messages, $counts ) {
		if ( ! is_array( $messages ) || ! is_array( $counts ) || ( empty( $counts['trashed'] ) && empty( $counts['untrashed'] ) ) ) {
			return $messages;
		}
		$type = (string) ( $GLOBALS['typenow'] ?? '' );
		if ( '' === $type || ! Post_Types::is_supported( $type ) ) {
			return $messages;
		}

		$user   = get_current_user_id();
		$change = $this->log->latest( $user );
		if ( null === $change || ! empty( $change['shown'] ) || $type !== $change['post_type'] ) {
			return $messages;
		}
		$slot = 'trash' === $change['action'] ? 'trashed' : 'untrashed';
		if ( empty( $counts[ $slot ] ) || ! $this->matches( $change ) ) {
			return $messages;
		}
		$key = isset( $messages[ $type ][ $slot ] ) ? $type : 'post';
		if ( ! isset( $messages[ $key ][ $slot ] ) ) {
			return $messages;
		}

		$sentences = self::sentences( $change );
		if ( [] === $sentences ) {
			return $messages;
		}

		// Core runs the message through sprintf() with the count, so a literal percent sign is doubled.
		$messages[ $key ][ $slot ] .= ' ' . str_replace( '%', '%%', esc_html( implode( ' ', $sentences ) ) );
		$this->log->mark_shown( $user, (string) $change['id'] );

		return $messages;
	}

	/**
	 * What happened to the subpages, one translatable sentence each.
	 *
	 * @param array<string,mixed> $change A change from Change_Log.
	 * @return string[]
	 */
	public static function sentences( array $change ): array {
		$roots  = array_values( (array) $change['roots'] );
		$single = 1 === count( $roots ) ? $roots[0] : null;
		$counts = array_map( 'intval', (array) $change['counts'] );
		$out    = [];

		if ( 'restore' === $change['action'] ) {
			$out = array_merge( $out, self::restore_sentences( $counts, $single ) );
		} else {
			$out = array_merge( $out, self::trash_sentences( $counts, $single, $roots ) );
		}

		foreach ( $roots as $root ) {
			if ( ! empty( $root['job'] ) ) {
				$out[] = Settings::POLICY_TRASH_CHILDREN === $root['policy']
					? __( 'The rest are still going to the Trash in the background.', 'hierarchy-guard' )
					: __( 'The rest are still moving in the background.', 'hierarchy-guard' );
				break;
			}
		}

		return $out;
	}

	/**
	 * Sentences for a trash.
	 *
	 * @param array<string,int>                    $counts Summed counts.
	 * @param array<string,mixed>|null             $single The only root, or null for several.
	 * @param array<int,array<string,mixed>>       $roots  Every root.
	 * @return string[]
	 */
	private static function trash_sentences( array $counts, ?array $single, array $roots ): array {
		$out = [];

		if ( $counts['moved'] > 0 ) {
			if ( null !== $single && (int) $single['to'] > 0 ) {
				/* translators: 1: number of subpages, 2: title of the page they now sit under. */
				$out[] = sprintf( _n( '%1$s subpage moved up under “%2$s”.', '%1$s subpages moved up under “%2$s”.', $counts['moved'], 'hierarchy-guard' ), number_format_i18n( $counts['moved'] ), self::title( (int) $single['to'] ) );
			} elseif ( null !== $single ) {
				/* translators: %s: number of subpages. */
				$out[] = sprintf( _n( '%s subpage is now a top-level page.', '%s subpages are now top-level pages.', $counts['moved'], 'hierarchy-guard' ), number_format_i18n( $counts['moved'] ) );
			} else {
				/* translators: %s: number of subpages. */
				$out[] = sprintf( _n( '%s subpage moved up one level.', '%s subpages moved up one level.', $counts['moved'], 'hierarchy-guard' ), number_format_i18n( $counts['moved'] ) );
			}
		}

		if ( $counts['trashed'] > 0 ) {
			/* translators: %s: number of subpages. */
			$out[] = sprintf( _n( '%s subpage moved to the Trash too.', '%s subpages moved to the Trash too.', $counts['trashed'], 'hierarchy-guard' ), number_format_i18n( $counts['trashed'] ) );
		}

		$stayed = $counts['kept'] - $counts['denied'];
		if ( $stayed > 0 ) {
			if ( null !== $single && Settings::POLICY_KEEP === $single['policy'] ) {
				/* translators: %s: number of subpages. */
				$out[] = sprintf( _n( 'Its %s subpage stayed where it is.', 'Its %s subpages stayed where they are.', $stayed, 'hierarchy-guard' ), number_format_i18n( $stayed ) );
			} else {
				/* translators: %s: number of subpages. */
				$out[] = sprintf( _n( '%s subpage stayed where it is.', '%s subpages stayed where they are.', $stayed, 'hierarchy-guard' ), number_format_i18n( $stayed ) );
			}
		}

		if ( $counts['denied'] > 0 ) {
			/* translators: %s: number of subpages. */
			$out[] = sprintf( _n( '%s subpage you don’t have permission to change stayed where it is.', '%s subpages you don’t have permission to change stayed where they are.', $counts['denied'], 'hierarchy-guard' ), number_format_i18n( $counts['denied'] ) );
		}

		foreach ( $roots as $root ) {
			if ( ! empty( $root['fallback'] ) ) {
				$cap = Subtree::count_cap();
				/* translators: %s: the most subpages the plugin trashes together with a page, such as 1,000. */
				$out[] = sprintf( _n( 'There was more than %s subpage, so they moved up instead of going to the Trash.', 'There were more than %s subpages, so they moved up instead of going to the Trash.', $cap, 'hierarchy-guard' ), number_format_i18n( $cap ) );
				break;
			}
		}

		return $out;
	}

	/**
	 * Sentences for a restore.
	 *
	 * @param array<string,int>        $counts Summed counts.
	 * @param array<string,mixed>|null $single The only root, or null for several.
	 * @return string[]
	 */
	private static function restore_sentences( array $counts, ?array $single ): array {
		$out = [];

		if ( $counts['moved'] > 0 ) {
			if ( null !== $single ) {
				/* translators: 1: number of subpages, 2: title of the restored page. */
				$out[] = sprintf( _n( '%1$s subpage moved back under “%2$s”.', '%1$s subpages moved back under “%2$s”.', $counts['moved'], 'hierarchy-guard' ), number_format_i18n( $counts['moved'] ), self::title( (int) $single['root'] ) );
			} else {
				/* translators: %s: number of subpages. */
				$out[] = sprintf( _n( '%s subpage moved back under its parent.', '%s subpages moved back under their parents.', $counts['moved'], 'hierarchy-guard' ), number_format_i18n( $counts['moved'] ) );
			}
		}

		if ( $counts['restored'] > 0 ) {
			$out[] = null !== $single
				/* translators: %s: number of subpages. */
				? sprintf( _n( '%s subpage was restored with it.', '%s subpages were restored with it.', $counts['restored'], 'hierarchy-guard' ), number_format_i18n( $counts['restored'] ) )
				/* translators: %s: number of subpages. */
				: sprintf( _n( '%s subpage was restored too.', '%s subpages were restored too.', $counts['restored'], 'hierarchy-guard' ), number_format_i18n( $counts['restored'] ) );
		}

		if ( $counts['left'] > 0 ) {
			/* translators: %s: number of subpages. */
			$out[] = sprintf( _n( '%s subpage was moved since and stayed where it is.', '%s subpages were moved since and stayed where they are.', $counts['left'], 'hierarchy-guard' ), number_format_i18n( $counts['left'] ) );
		}

		if ( $counts['renamed'] > 0 ) {
			/* translators: %s: number of subpages. */
			$out[] = sprintf( _n( '%s subpage couldn’t get its old URL back.', '%s subpages couldn’t get their old URLs back.', $counts['renamed'], 'hierarchy-guard' ), number_format_i18n( $counts['renamed'] ) );
		}

		return $out;
	}

	/**
	 * Whether the notice on screen belongs to this change.
	 *
	 * @param array<string,mixed> $change Change.
	 * @return bool
	 */
	private function matches( array $change ): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read only, to pick the notice text.
		$raw = isset( $_REQUEST['ids'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['ids'] ) ) : '';
		if ( '' === $raw ) {
			// Core's bulk restore redirects without the IDs; only a change made moments ago belongs to it.
			return time() - (int) $change['time'] <= MINUTE_IN_SECONDS;
		}
		$ids = array_map( 'absint', explode( ',', $raw ) );
		return [] !== array_intersect( $ids, array_map( 'intval', array_keys( (array) $change['roots'] ) ) );
	}

	/**
	 * A page title for a sentence.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private static function title( int $post_id ): string {
		$title = get_the_title( $post_id );
		return '' !== $title ? $title : __( '(no title)', 'hierarchy-guard' );
	}
}
