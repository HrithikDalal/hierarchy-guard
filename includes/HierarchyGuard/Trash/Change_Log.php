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
 * page as its root. Titles, URLs and old/new pairs are never stored; they
 * are computed when shown.
 *
 * Each record has one writer at a time. The change a request records is
 * kept in memory and written once, at shutdown, only by that request, so a
 * request of many roots writes it once, not once per root; until then
 * latest() answers from memory. A root whose subpages a background job
 * works on gets a record of its own: the job's counts, kept IDs, IDs, slugs
 * and whether it still runs. Only a process that holds the root's lock
 * writes it: the job's runs, and the run or request that replaces the job,
 * to close it. latest() lays each record over its root, so a run in another
 * process never needs the change written and never writes it, and a
 * request's write never takes back what a run stored. On a network each
 * site keeps its own: a request that saves on two sites, as a plugin that
 * switches sites does, records a change on each, and each change and job
 * record is written on its own site.
 */
final class Change_Log {

	public const TTL      = 30 * MINUTE_IN_SECONDS;
	public const MAX_IDS  = 200;
	public const MAX_KEPT = 20;

	public const COUNT_KEYS = [ 'moved', 'trashed', 'restored', 'kept', 'denied', 'left', 'renamed' ];

	/**
	 * The fields of a root's entry a job's record holds.
	 */
	private const JOB_FIELDS = [
		'counts'   => true,
		'kept_ids' => true,
		'ids'      => true,
		'slugs'    => true,
		'job'      => true,
	];

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
	 * Transient key for a user's change.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	public static function key( int $user_id ): string {
		return 'hierarchy_guard_change_' . $user_id;
	}

	/**
	 * Transient key for the record a background job keeps of its root.
	 *
	 * @param int $user_id User whose change the job is part of.
	 * @param int $root_id Root page.
	 * @return string
	 */
	public static function job_key( int $user_id, int $root_id ): string {
		return self::key( $user_id ) . '_' . $root_id;
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

		$this->store( $user_id, $change, $ids );

		return $change['id'];
	}

	/**
	 * Writes a background job's record of its root: what the job has done so
	 * far, for the change it was started with. It never reads or writes the
	 * change itself; latest() lays the record over the change's entry for
	 * that root.
	 *
	 * Only the job's own run writes it, while it holds the root's lock, or the
	 * run or request that replaces the job, under the same lock. So no other
	 * process writes the record in between, and it is written whole. The lock
	 * and the job are the site's own, so the record is written on the job's
	 * site. It outlives the change by five minutes, as a change can be
	 * written up to the end of its request, after its jobs' records.
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
		$entry = self::entry(
			[
				'counts'   => $counts,
				'kept_ids' => $kept_ids,
				'ids'      => $ids,
				'slugs'    => $slugs,
				'job'      => $running,
			]
		);

		set_transient(
			self::job_key( $user_id, $root_id ),
			[
				'change'   => $change_id,
				'counts'   => $entry['counts'],
				'kept_ids' => $entry['kept_ids'],
				'ids'      => $entry['ids'],
				'slugs'    => $entry['slugs'],
				'job'      => $entry['job'],
				'updated'  => time(),
			],
			self::TTL + 5 * MINUTE_IN_SECONDS
		);
	}

	/**
	 * The latest change for a user: the one this request has waiting, or the
	 * stored one, with each of its jobs' records laid over its root.
	 *
	 * @param int $user_id User ID.
	 * @return array<string,mixed>|null
	 */
	public function latest( int $user_id ): ?array {
		$site   = get_current_blog_id();
		$change = self::$pending[ $site ][ $user_id ] ?? get_transient( self::key( $user_id ) );
		if ( ! is_array( $change ) || ! isset( $change['id'] ) ) {
			return null;
		}
		return self::summed( self::with_jobs( $user_id, $change ) );
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
		unset( self::$pending[ get_current_blog_id() ][ $user_id ] );
		delete_transient( self::key( $user_id ) );
	}

	/**
	 * Merges the IDs and saves.
	 *
	 * @param int                 $user_id User ID.
	 * @param array<string,mixed> $change  Change.
	 * @param int[]               $ids     IDs to add.
	 */
	private function store( int $user_id, array $change, array $ids ): void {
		$change['ids']     = self::merged_ids( (array) $change['ids'], $ids );
		$change['updated'] = time();
		self::save( $user_id, $change );
	}

	/**
	 * Saves a change: the one this request recorded waits in memory until the
	 * request ends, and one from an earlier request, which a notice marks
	 * shown, is written now. Once shutdown has begun nothing would write it
	 * later, so it is written now too.
	 *
	 * set_transient() returns false both when the save fails and when nothing
	 * changed, so its result says nothing reliable here. Nothing depends on it:
	 * a change that was not saved is simply not shown.
	 *
	 * @param int                 $user_id User ID.
	 * @param array<string,mixed> $change  Change.
	 */
	private static function save( int $user_id, array $change ): void {
		$site = get_current_blog_id();
		if ( ( self::$current[ $site ][ $user_id ] ?? '' ) !== $change['id'] ) {
			unset( self::$pending[ $site ][ $user_id ] );
			set_transient( self::key( $user_id ), self::summed( $change ), self::TTL );
			return;
		}
		self::$pending[ $site ][ $user_id ] = $change;
		if ( did_action( 'shutdown' ) ) {
			self::write( $site, $user_id );
		} elseif ( false === has_action( 'shutdown', [ self::class, 'flush' ] ) ) {
			add_action( 'shutdown', [ self::class, 'flush' ], 0 );
		}
	}

	/**
	 * Writes the change this request has waiting for a user on a site, with
	 * that site current.
	 *
	 * @param int $site_id Site ID.
	 * @param int $user_id User ID.
	 */
	private static function write( int $site_id, int $user_id ): void {
		if ( ! isset( self::$pending[ $site_id ][ $user_id ] ) ) {
			return;
		}
		$change = self::$pending[ $site_id ][ $user_id ];
		unset( self::$pending[ $site_id ][ $user_id ] );

		$switched = is_multisite() && get_current_blog_id() !== $site_id;
		if ( $switched ) {
			switch_to_blog( $site_id );
		}
		try {
			set_transient( self::key( $user_id ), self::summed( $change ), self::TTL );
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
	}

	/**
	 * The change with each of its jobs' records laid over its root. Only a
	 * root whose entry says a job was queued has a record to read, and a
	 * record counts only for the change it was written for. A record always
	 * wins over the change's own copy of its root, so a change written with
	 * what an earlier read laid over it is harmless.
	 *
	 * @param int                 $user_id User ID.
	 * @param array<string,mixed> $change  Change.
	 * @return array<string,mixed>
	 */
	private static function with_jobs( int $user_id, array $change ): array {
		$roots = [];
		foreach ( (array) $change['roots'] as $root_id => $root ) {
			if ( ! empty( $root['job'] ) ) {
				$roots[] = (int) $root_id;
			}
		}
		if ( [] === $roots ) {
			return $change;
		}

		$ids = [];
		foreach ( self::records( $user_id, $roots ) as $root_id => $record ) {
			if ( ! is_array( $record ) || ( $record['change'] ?? '' ) !== $change['id'] ) {
				continue;
			}
			$change['roots'][ $root_id ] = self::entry( array_merge( $change['roots'][ $root_id ], array_intersect_key( $record, self::JOB_FIELDS ) ) );
			$change['updated']           = max( (int) ( $change['updated'] ?? 0 ), (int) ( $record['updated'] ?? 0 ) );
			$ids[]                       = $change['roots'][ $root_id ]['ids'];
		}
		if ( [] !== $ids ) {
			$change['ids'] = self::merged_ids( (array) ( $change['ids'] ?? [] ), array_merge( ...$ids ) );
		}

		return $change;
	}

	/**
	 * The job records of a user's roots, in one read: from an external object
	 * cache when the site has one, else in one query for all their option rows.
	 *
	 * @param int   $user_id User ID.
	 * @param int[] $roots   Root pages.
	 * @return array<int,mixed> What each root's transient holds, false when it has none.
	 */
	private static function records( int $user_id, array $roots ): array {
		$keys = [];
		foreach ( $roots as $root_id ) {
			$keys[ $root_id ] = self::job_key( $user_id, $root_id );
		}

		if ( wp_using_ext_object_cache() ) {
			$found = wp_cache_get_multiple( array_values( $keys ), 'transient' );
			return array_map( static fn( string $key ) => $found[ $key ] ?? false, $keys );
		}

		$names = [];
		foreach ( $keys as $key ) {
			$names[] = '_transient_' . $key;
			$names[] = '_transient_timeout_' . $key;
		}
		// get_transient() then finds each row, or its absence, in the cache.
		wp_prime_option_caches( $names );
		return array_map( 'get_transient', $keys );
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
