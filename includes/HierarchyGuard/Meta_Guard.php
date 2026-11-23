<?php
/**
 * Keeps other plugins from copying the plugin's post meta.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard;

use HierarchyGuard\Trash\Markers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Duplicators and some translation tools copy every meta row of a post. A
 * copied ledger entry makes the copy the newest holder of an old URL, so the
 * URL redirects to the copy; a copied marker makes a restore move the copy
 * too. So the plugin's keys are written and deleted only by the plugin
 * itself: markers through their own direct SQL, the rest inside writing(). A
 * WordPress import keeps its ledger rows, as an export carries them.
 */
final class Meta_Guard {

	public const PREFIX = '_hierarchy_guard_';

	/**
	 * Nesting depth of writing() calls.
	 *
	 * @var int
	 */
	private static int $writing = 0;

	/**
	 * Hooks into core and Yoast Duplicate Post.
	 */
	public function register(): void {
		add_filter( 'add_post_metadata', [ $this, 'refuse_copies' ], 10, 3 );
		add_filter( 'delete_post_metadata', [ $this, 'refuse_deletes' ], 10, 3 );
		add_filter( 'duplicate_post_excludelist_filter', [ $this, 'exclude_from_duplicates' ] );
	}

	/**
	 * Runs one of the plugin's own meta writes.
	 *
	 * @param callable $write The write.
	 * @return mixed What the write returns.
	 */
	public static function writing( callable $write ) {
		++self::$writing;
		try {
			return $write();
		} finally {
			--self::$writing;
		}
	}

	/**
	 * Refuses a new row of one of the plugin's keys that the plugin isn't writing.
	 *
	 * @param mixed $check     Null to let the write go ahead, or an earlier callback's answer.
	 * @param mixed $object_id Post ID.
	 * @param mixed $meta_key  Meta key.
	 * @return mixed False to refuse the row, else $check.
	 */
	public function refuse_copies( $check, $object_id, $meta_key ) {
		$key = (string) $meta_key;
		if ( ! str_starts_with( $key, self::PREFIX ) ) {
			return $check;
		}
		return self::allowed( $key, self::$writing > 0, defined( 'WP_IMPORTING' ) && WP_IMPORTING ) ? $check : false;
	}

	/**
	 * Refuses a delete of one of the plugin's keys that the plugin isn't
	 * making. Yoast Duplicate Post's republish deletes each of the copy's keys
	 * on the original before adding the copy's rows back; with those rows
	 * refused, the delete alone would take the original's marker. Deleting a
	 * post for good deletes its rows by meta ID, which this filter never sees.
	 *
	 * @param mixed $check     Null to let the delete go ahead, or an earlier callback's answer.
	 * @param mixed $object_id Post ID.
	 * @param mixed $meta_key  Meta key.
	 * @return mixed False to refuse the delete, else $check.
	 */
	public function refuse_deletes( $check, $object_id, $meta_key ) {
		return str_starts_with( (string) $meta_key, self::PREFIX ) && 0 === self::$writing ? false : $check;
	}

	/**
	 * Whether a row of one of the plugin's keys may be added through the meta
	 * API. A marker never may: Markers::add() inserts it directly.
	 *
	 * @param string $key       Meta key.
	 * @param bool   $writing   Whether the plugin is writing it.
	 * @param bool   $importing Whether a WordPress import is running.
	 * @return bool
	 */
	public static function allowed( string $key, bool $writing, bool $importing ): bool {
		if ( str_starts_with( $key, Markers::MOVED_PREFIX ) || str_starts_with( $key, Markers::TRASHED_PREFIX ) ) {
			return false;
		}
		return $writing || $importing;
	}

	/**
	 * Leaves the plugin's keys out of the copies Yoast Duplicate Post makes
	 * with New Draft and Clone. Its list takes wildcards. Rewrite & Republish
	 * copies skip this filter; refuse_copies() refuses their rows instead.
	 *
	 * @param mixed $excluded Meta keys the duplicate leaves out.
	 * @return string[]
	 */
	public function exclude_from_duplicates( $excluded ): array {
		$excluded   = is_array( $excluded ) ? $excluded : [];
		$excluded[] = self::PREFIX . '*';
		return $excluded;
	}
}
