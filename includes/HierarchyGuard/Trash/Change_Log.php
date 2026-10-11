<?php
/**
 * Summary of the last trash or restore, per user.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Trash;

use HierarchyGuard\Subtree;

/**
 * Feeds the outcome notice and Undo. One change per request, so a bulk trash
 * of three parents is one change with three roots. Background batches keep
 * their root's entry current. Titles, URLs and old/new pairs are never stored;
 * they are computed when shown.
 */
final class Change_Log {

	public const TTL      = 30 * MINUTE_IN_SECONDS;
	public const MAX_IDS  = 200;
	public const MAX_KEPT = 20;

	public const COUNT_KEYS = [ 'moved', 'trashed', 'restored', 'kept', 'denied', 'left', 'renamed' ];

	/**
	 * The change each user started in this request, by user ID.
	 *
	 * @var array<int,string>
	 */
	private static array $current = [];

	/**
	 * Transient key for a user.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	public static function key( int $user_id ): string {
		return 'hierarchy_guard_change_' . $user_id;
	}

	/**
	 * Every count at zero.
	 *
	 * @return array<string,int>
	 */
	public static function empty_counts(): array {
		return array_fill_keys( self::COUNT_KEYS, 0 );
	}

	/**
	 * Starts a new request: the next record() begins a new change. The plugin
	 * never calls this; long-running processes and tests do.
	 */
	public static function reset_request(): void {
		self::$current = [];
	}

	/**
	 * Adds or replaces one root's entry in the user's change for this request.
	 *
	 * @param int                 $user_id   User whose change it is. Nothing is stored for 0.
	 * @param string              $action    What happened: trash or restore.
	 * @param string              $post_type Post type of the root.
	 * @param array<string,mixed> $entry     Keys: root, to, policy, fallback, path, counts, kept_ids, ids, slugs, urls, job.
	 * @param int[]               $ids       Affected post IDs.
	 * @return string The change ID, or an empty string when nothing was stored.
	 */
	public function record( int $user_id, string $action, string $post_type, array $entry, array $ids = [] ): string {
		if ( $user_id <= 0 ) {
			return '';
		}

		$change = null;
		if ( isset( self::$current[ $user_id ] ) ) {
			$existing = $this->latest( $user_id );
			if ( null !== $existing && self::$current[ $user_id ] === $existing['id'] && $action === $existing['action'] ) {
				$change = $existing;
			}
		}
		if ( null === $change ) {
			$change                    = [
				'id'        => wp_generate_uuid4(),
				'action'    => $action,
				'post_type' => $post_type,
				'roots'     => [],
				'counts'    => self::empty_counts(),
				'ids'       => [],
				'time'      => time(),
				'updated'   => time(),
				'shown'     => false,
			];
			self::$current[ $user_id ] = $change['id'];
		}

		$root                     = (int) ( $entry['root'] ?? 0 );
		$change['roots'][ $root ] = self::entry( $entry );

		$this->store( $user_id, $change, $ids );

		return $change['id'];
	}

	/**
	 * Updates one root's entry from a background batch, if the change still exists.
	 *
	 * @param int                $user_id   User whose change it is.
	 * @param string             $change_id Change ID the job was started with.
	 * @param int                $root_id   Root page.
	 * @param array<string,int>  $counts    The job's counts so far.
	 * @param int[]              $kept_ids  The job's kept IDs.
	 * @param int[]              $ids       The job's affected IDs.
	 * @param array<int,string>  $slugs     Each moved subpage's slug before the move.
	 * @param bool               $running   Whether the job still has work left.
	 */
	public function update( int $user_id, string $change_id, int $root_id, array $counts, array $kept_ids, array $ids, array $slugs, bool $running ): void {
		if ( $user_id <= 0 || '' === $change_id ) {
			return;
		}
		$change = $this->latest( $user_id );
		if ( null === $change || $change_id !== $change['id'] || ! isset( $change['roots'][ $root_id ] ) ) {
			return;
		}

		$change['roots'][ $root_id ] = self::entry(
			array_merge(
				$change['roots'][ $root_id ],
				[
					'counts'   => $counts,
					'kept_ids' => $kept_ids,
					'ids'      => $ids,
					'slugs'    => $slugs,
					'job'      => $running,
				]
			)
		);

		$this->store( $user_id, $change, $ids );
	}

	/**
	 * The latest change for a user.
	 *
	 * @param int $user_id User ID.
	 * @return array<string,mixed>|null
	 */
	public function latest( int $user_id ): ?array {
		$change = get_transient( self::key( $user_id ) );
		return is_array( $change ) && isset( $change['id'] ) ? $change : null;
	}

	/**
	 * Marks a change as shown, so a later page load does not repeat it.
	 *
	 * @param int    $user_id   User ID.
	 * @param string $change_id Change ID.
	 */
	public function mark_shown( int $user_id, string $change_id ): void {
		$change = $this->latest( $user_id );
		if ( null === $change || $change_id !== $change['id'] ) {
			return;
		}
		$change['shown'] = true;
		set_transient( self::key( $user_id ), $change, self::TTL );
	}

	/**
	 * Clears a user's summary.
	 *
	 * @param int $user_id User ID.
	 */
	public function forget( int $user_id ): void {
		delete_transient( self::key( $user_id ) );
	}

	/**
	 * Sums the roots, keeps the roots' own IDs within one budget, merges the IDs and saves.
	 *
	 * The roots' IDs and slugs together stay within the count cap, later roots
	 * trimmed first, so a bulk trash of many parents stays a small transient.
	 * A trimmed root keeps its counts, so its pairs show as incomplete.
	 *
	 * set_transient() returns false both when the save fails and when nothing
	 * changed, so its result says nothing reliable here. Nothing depends on it:
	 * a change that was not saved is simply not shown.
	 *
	 * @param int                 $user_id User ID.
	 * @param array<string,mixed> $change  Change.
	 * @param int[]               $ids     IDs to add.
	 */
	private function store( int $user_id, array $change, array $ids ): void {
		$counts = self::empty_counts();
		$budget = Subtree::count_cap();
		foreach ( $change['roots'] as $root_id => $root ) {
			foreach ( self::COUNT_KEYS as $key ) {
				$counts[ $key ] += (int) ( $root['counts'][ $key ] ?? 0 );
			}
			$own     = array_slice( (array) ( $root['ids'] ?? [] ), 0, max( 0, $budget ) );
			$budget -= count( $own );

			$change['roots'][ $root_id ]['ids']   = $own;
			$change['roots'][ $root_id ]['slugs'] = array_intersect_key( (array) ( $root['slugs'] ?? [] ), array_flip( $own ) );
		}
		$change['counts']  = $counts;
		$change['ids']     = array_slice( array_values( array_unique( array_merge( array_map( 'intval', (array) $change['ids'] ), array_map( 'intval', $ids ) ) ) ), 0, self::MAX_IDS );
		$change['updated'] = time();

		set_transient( self::key( $user_id ), $change, self::TTL );
	}

	/**
	 * One root's entry with every key present and typed.
	 *
	 * @param array<string,mixed> $entry Raw entry.
	 * @return array<string,mixed>
	 */
	private static function entry( array $entry ): array {
		$counts = self::empty_counts();
		foreach ( self::COUNT_KEYS as $key ) {
			$counts[ $key ] = (int) ( $entry['counts'][ $key ] ?? 0 );
		}
		$urls = (array) ( $entry['urls'] ?? [] );

		return [
			'root'     => (int) ( $entry['root'] ?? 0 ),
			'to'       => (int) ( $entry['to'] ?? 0 ),
			'policy'   => (string) ( $entry['policy'] ?? '' ),
			'fallback' => (bool) ( $entry['fallback'] ?? false ),
			'path'     => (string) ( $entry['path'] ?? '' ),
			'counts'   => $counts,
			'kept_ids' => array_slice( array_map( 'intval', (array) ( $entry['kept_ids'] ?? [] ) ), 0, self::MAX_KEPT ),
			// This root's own affected IDs, so its redirects can be listed after it is deleted for good.
			'ids'      => array_slice( array_map( 'intval', (array) ( $entry['ids'] ?? [] ) ), 0, self::MAX_IDS ),
			// Each moved subpage's slug before the move, so its old URL survives a delete for good.
			'slugs'    => self::slugs( (array) ( $entry['slugs'] ?? [] ) ),
			'urls'     => [
				'count'  => (int) ( $urls['count'] ?? 0 ),
				'capped' => (bool) ( $urls['capped'] ?? false ),
			],
			'job'      => (bool) ( $entry['job'] ?? false ),
		];
	}

	/**
	 * Cleans a map of slugs by post ID, keeping at most MAX_IDS.
	 *
	 * @param array<int|string,mixed> $slugs Slugs by post ID.
	 * @return array<int,string>
	 */
	private static function slugs( array $slugs ): array {
		$out = [];
		foreach ( array_slice( $slugs, 0, self::MAX_IDS, true ) as $id => $slug ) {
			$out[ (int) $id ] = (string) $slug;
		}
		return $out;
	}
}
