<?php
/**
 * Summary of the last trash, restore or URL-changing save, per user.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Trash;

use HierarchyGuard\Settings;
use HierarchyGuard\Subtree;

/**
 * Feeds the outcome notice and Undo. One change per request, so a bulk trash
 * of three parents is one change with three roots, and a later change
 * replaces it. A save that changed a page's URL is a change too, with the
 * page as its root. Background batches keep their root's entry current.
 * Titles, URLs and old/new pairs are never stored; they are computed when
 * shown.
 *
 * The change a request records is kept in memory and written once, at
 * shutdown, so a request of many roots writes it once, not once per root.
 * Until then latest() answers from memory. A job a background run updates
 * is queued only once the change is written, as a run in another process
 * reads only what is stored; a later write of the same change then replaces
 * only the roots the request recorded since, so it never takes back what
 * such a run stored in between. On a network each site keeps its own: a
 * request that saves on two sites, as a plugin that switches sites does,
 * records a change on each, and each is written on its own site.
 */
final class Change_Log {

	public const TTL      = 30 * MINUTE_IN_SECONDS;
	public const MAX_IDS  = 200;
	public const MAX_KEPT = 20;

	public const COUNT_KEYS = [ 'moved', 'trashed', 'restored', 'kept', 'denied', 'left', 'renamed' ];

	/**
	 * The change each user started in this request, by site ID and user ID.
	 *
	 * @var array<int,array<int,string>>
	 */
	private static array $current = [];

	/**
	 * Changes of this request not written yet, by site ID and user ID.
	 *
	 * @var array<int,array<int,array<string,mixed>>>
	 */
	private static array $pending = [];

	/**
	 * The roots of each pending change recorded or updated since it was last
	 * written, by site ID and user ID.
	 *
	 * @var array<int,array<int,array<int,true>>>
	 */
	private static array $dirty = [];

	/**
	 * The change of each user this request has written already, by site ID and user ID.
	 *
	 * @var array<int,array<int,string>>
	 */
	private static array $written = [];

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
	 * Starts a new request: what this one recorded is written, and the next
	 * record() begins a new change. The plugin never calls this; long-running
	 * processes and tests do.
	 */
	public static function reset_request(): void {
		self::flush();
		self::$current = [];
		self::$written = [];
	}

	/**
	 * Writes the changes this request recorded, each on its own site. Runs
	 * first at shutdown.
	 */
	public static function flush(): void {
		foreach ( self::$pending as $site_id => $changes ) {
			foreach ( array_keys( $changes ) as $user_id ) {
				self::write( (int) $site_id, (int) $user_id );
			}
		}
	}

	/**
	 * Writes the user's change now, if this request has one waiting: before a
	 * job is queued that a run in another process updates.
	 *
	 * @param int $user_id User ID.
	 */
	public function write_now( int $user_id ): void {
		self::write( get_current_blog_id(), $user_id );
	}

	/**
	 * Adds or replaces one root's entry in the user's change for this request.
	 *
	 * @param int                 $user_id   User whose change it is. Nothing is stored for 0.
	 * @param string              $action    What happened: trash, restore or save.
	 * @param string              $post_type Post type of the root.
	 * @param array<string,mixed> $entry     Keys: root, to, policy, fallback, path, counts, kept_ids, ids, slugs, urls, job.
	 * @param int[]               $ids       Affected post IDs.
	 * @param string              $origin    Where a save was made: classic, list, editor, or '' elsewhere. Kept from the change's first root.
	 * @param bool                $shown     Whether the screen it was made on already shows it. Kept from the change's first root.
	 * @return string The change ID, or an empty string when nothing was stored.
	 */
	public function record( int $user_id, string $action, string $post_type, array $entry, array $ids = [], string $origin = '', bool $shown = false ): string {
		if ( $user_id <= 0 ) {
			return '';
		}

		$site   = get_current_blog_id();
		$change = null;
		if ( isset( self::$current[ $site ][ $user_id ] ) ) {
			$existing = self::$pending[ $site ][ $user_id ] ?? $this->latest( $user_id );
			if ( null !== $existing && self::$current[ $site ][ $user_id ] === $existing['id'] && $action === $existing['action'] ) {
				$change = $existing;
			}
		}
		if ( null === $change ) {
			$change                             = [
				'id'        => wp_generate_uuid4(),
				'action'    => $action,
				'post_type' => $post_type,
				'roots'     => [],
				'counts'    => self::empty_counts(),
				'ids'       => [],
				'time'      => time(),
				'updated'   => time(),
				'origin'    => $origin,
				'shown'     => $shown,
			];
			self::$current[ $site ][ $user_id ] = $change['id'];
		}

		$root                     = (int) ( $entry['root'] ?? 0 );
		$change['roots'][ $root ] = self::entry( $entry );

		$this->store( $user_id, $change, $ids, $root );

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

		$this->store( $user_id, $change, $ids, $root_id );
	}

	/**
	 * The latest change for a user.
	 *
	 * @param int $user_id User ID.
	 * @return array<string,mixed>|null
	 */
	public function latest( int $user_id ): ?array {
		$site = get_current_blog_id();
		if ( isset( self::$pending[ $site ][ $user_id ] ) ) {
			return self::summed( self::$pending[ $site ][ $user_id ] );
		}
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
		self::save( $user_id, $change );
	}

	/**
	 * Clears a user's summary.
	 *
	 * @param int $user_id User ID.
	 */
	public function forget( int $user_id ): void {
		$site = get_current_blog_id();
		unset( self::$pending[ $site ][ $user_id ], self::$dirty[ $site ][ $user_id ], self::$written[ $site ][ $user_id ] );
		delete_transient( self::key( $user_id ) );
	}

	/**
	 * Merges the IDs and saves.
	 *
	 * @param int                 $user_id User ID.
	 * @param array<string,mixed> $change  Change.
	 * @param int[]               $ids     IDs to add.
	 * @param int                 $root_id The root recorded or updated.
	 */
	private function store( int $user_id, array $change, array $ids, int $root_id ): void {
		$change['ids']     = self::merged_ids( (array) $change['ids'], $ids );
		$change['updated'] = time();
		self::save( $user_id, $change, $root_id );
	}

	/**
	 * Saves a change: the one this request recorded waits in memory until the
	 * request ends, or until a job is queued (write_now()), and one from an
	 * earlier request, which a background batch or a notice updates, is
	 * written now. Once shutdown has begun nothing would write it later, so
	 * it is written now too.
	 *
	 * set_transient() returns false both when the save fails and when nothing
	 * changed, so its result says nothing reliable here. Nothing depends on it:
	 * a change that was not saved is simply not shown.
	 *
	 * @param int                 $user_id User ID.
	 * @param array<string,mixed> $change  Change.
	 * @param int|null            $root_id The root recorded or updated, if any.
	 */
	private static function save( int $user_id, array $change, ?int $root_id = null ): void {
		$site = get_current_blog_id();
		if ( ( self::$current[ $site ][ $user_id ] ?? '' ) !== $change['id'] ) {
			unset( self::$pending[ $site ][ $user_id ], self::$dirty[ $site ][ $user_id ] );
			set_transient( self::key( $user_id ), self::summed( $change ), self::TTL );
			return;
		}
		self::$pending[ $site ][ $user_id ] = $change;
		if ( null !== $root_id ) {
			self::$dirty[ $site ][ $user_id ][ $root_id ] = true;
		}
		if ( did_action( 'shutdown' ) ) {
			self::write( $site, $user_id );
		} elseif ( false === has_action( 'shutdown', [ self::class, 'flush' ] ) ) {
			add_action( 'shutdown', [ self::class, 'flush' ], 0 );
		}
	}

	/**
	 * Writes the change this request has waiting for a user on a site, with
	 * that site current. When the request wrote it before, the stored copy is
	 * read back first and only the roots recorded or updated since replace
	 * its own: a background run may have updated another root in between.
	 *
	 * @param int $site_id Site ID.
	 * @param int $user_id User ID.
	 */
	private static function write( int $site_id, int $user_id ): void {
		if ( ! isset( self::$pending[ $site_id ][ $user_id ] ) ) {
			return;
		}
		$change = self::$pending[ $site_id ][ $user_id ];
		$roots  = array_keys( self::$dirty[ $site_id ][ $user_id ] ?? [] );
		unset( self::$pending[ $site_id ][ $user_id ], self::$dirty[ $site_id ][ $user_id ] );

		$switched = is_multisite() && get_current_blog_id() !== $site_id;
		if ( $switched ) {
			switch_to_blog( $site_id );
		}
		try {
			if ( ( self::$written[ $site_id ][ $user_id ] ?? '' ) === $change['id'] ) {
				$change = self::merged( $change, $roots, get_transient( self::key( $user_id ) ) );
			}
			set_transient( self::key( $user_id ), self::summed( $change ), self::TTL );
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
		self::$written[ $site_id ][ $user_id ] = (string) $change['id'];
	}

	/**
	 * The stored copy of a change with this request's newer roots laid over
	 * it. When the stored change is gone or another one replaced it, this
	 * request's copy is written whole, as before.
	 *
	 * @param array<string,mixed> $change The request's copy.
	 * @param int[]               $roots  Roots it recorded or updated since it last wrote the change.
	 * @param mixed               $stored What the transient holds now.
	 * @return array<string,mixed>
	 */
	private static function merged( array $change, array $roots, $stored ): array {
		if ( ! is_array( $stored ) || ( $stored['id'] ?? '' ) !== $change['id'] ) {
			return $change;
		}
		foreach ( $roots as $root_id ) {
			if ( isset( $change['roots'][ $root_id ] ) ) {
				$stored['roots'][ $root_id ] = $change['roots'][ $root_id ];
			}
		}
		$stored['ids']     = self::merged_ids( (array) ( $stored['ids'] ?? [] ), (array) $change['ids'] );
		$stored['updated'] = max( (int) ( $stored['updated'] ?? 0 ), (int) $change['updated'] );
		$stored['shown']   = ! empty( $stored['shown'] ) || ! empty( $change['shown'] );

		return $stored;
	}

	/**
	 * Two lists of IDs as one, each once, at most MAX_IDS.
	 *
	 * @param array<int|string,mixed> $ids  IDs.
	 * @param array<int|string,mixed> $more IDs to add.
	 * @return int[]
	 */
	private static function merged_ids( array $ids, array $more ): array {
		return array_slice( array_values( array_unique( array_merge( array_map( 'intval', $ids ), array_map( 'intval', $more ) ) ) ), 0, self::MAX_IDS );
	}

	/**
	 * The change with its roots summed and the reparent roots' own IDs within one budget.
	 *
	 * The IDs and slugs of the roots whose subpages moved up together stay
	 * within the count cap, later roots trimmed first, so a bulk trash of many
	 * parents stays a small transient. Only the pairs read them, and only for
	 * those roots; every other root keeps its own IDs, at most MAX_IDS each, as
	 * before. A trimmed root keeps its counts, so its pairs show as incomplete.
	 *
	 * @param array<string,mixed> $change Change.
	 * @return array<string,mixed>
	 */
	private static function summed( array $change ): array {
		$counts = self::empty_counts();
		$budget = Subtree::count_cap();
		foreach ( $change['roots'] as $root_id => $root ) {
			foreach ( self::COUNT_KEYS as $key ) {
				$counts[ $key ] += (int) ( $root['counts'][ $key ] ?? 0 );
			}
			if ( Settings::POLICY_REPARENT !== ( $root['policy'] ?? '' ) ) {
				continue;
			}
			$own     = array_slice( (array) ( $root['ids'] ?? [] ), 0, max( 0, $budget ) );
			$budget -= count( $own );

			$change['roots'][ $root_id ]['ids']   = $own;
			$change['roots'][ $root_id ]['slugs'] = array_intersect_key( (array) ( $root['slugs'] ?? [] ), array_flip( $own ) );
		}
		$change['counts'] = $counts;

		return $change;
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
