<?php
/**
 * Keeps Yoast SEO's indexables right after a direct re-parent.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Compat;

use HierarchyGuard\Batch\Processor;
use HierarchyGuard\Meta_Guard;
use Throwable;
use Yoast\WP\SEO\Integrations\Watchers\Indexable_Post_Watcher;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Yoast SEO rebuilds a post's indexable (its canonical, og:url and sitemap
 * entry) only on wp_insert_post, which a direct re-parent skips. Trashing a
 * parent makes Yoast rewrite every subpage's permalink to the parent's
 * __trashed path just before the move, so each moved page's indexable is
 * rebuilt here. Saving it makes Yoast rewrite every page under it too, a
 * cost that grows with that subtree, so a moved page with subpages is
 * rebuilt in a time-boxed run of its own rather than inside the step.
 */
final class Yoast {

	public const HOOK    = 'hierarchy_guard_yoast_rebuild';
	public const PENDING = '_hierarchy_guard_yoast_pending';

	/**
	 * Pending pages read at a time by a run.
	 */
	private const BATCH = 20;

	/**
	 * Hooks into the plugin's re-parent action, its own run and the export.
	 */
	public function register(): void {
		add_action( 'hierarchy_guard_children_reparented', [ $this, 'rebuild' ] );
		add_action( self::HOOK, [ $this, 'rebuild_pending' ] );
		add_filter( 'wxr_export_skip_postmeta', [ $this, 'skip_on_export' ], 10, 2 );
	}

	/**
	 * Rebuilds the indexables of pages one step moved, when Yoast SEO is
	 * active: a page without subpages at once, a page with subpages in the
	 * next run.
	 *
	 * @param mixed $ids Moved post IDs.
	 */
	public function rebuild( $ids ): void {
		$watcher = self::watcher();
		if ( null === $watcher ) {
			return;
		}
		$ids     = array_values( array_filter( array_map( 'intval', (array) $ids ) ) );
		$parents = self::with_children( $ids );
		foreach ( $ids as $id ) {
			if ( in_array( $id, $parents, true ) ) {
				Meta_Guard::writing( static fn() => update_post_meta( $id, self::PENDING, '1' ) );
				continue;
			}
			self::build( $watcher, $id );
		}
		if ( [] !== $parents ) {
			self::schedule();
		}
	}

	/**
	 * The run: rebuilds pending pages, oldest first, until none is left or
	 * the time is up. Each page is one call into Yoast, as when it is saved
	 * in the editor; the time is checked between pages.
	 */
	public function rebuild_pending(): void {
		$watcher = self::watcher();
		if ( null === $watcher ) {
			return;
		}
		$limit = Processor::time_limit();
		$start = microtime( true );
		$after = 0;
		// Scheduled first, so a run that dies part way still leaves the next one.
		self::schedule();
		while ( true ) {
			$flags = self::pending( self::BATCH, $after );
			if ( [] === $flags ) {
				break;
			}
			foreach ( $flags as $meta_id => $id ) {
				if ( microtime( true ) - $start >= $limit ) {
					return;
				}
				// Read past each flag, so one that can't be taken off is tried once a run.
				$after = $meta_id;
				// Taken off before the call, so a page that kills the run is not tried again forever.
				Meta_Guard::writing( static fn() => delete_post_meta( $id, self::PENDING ) );
				self::build( $watcher, $id );
			}
		}
		wp_clear_scheduled_hook( self::HOOK );
		// A page flagged after the last read found the run scheduled and left it to this one.
		if ( [] !== self::pending( 1, $after ) ) {
			self::schedule();
		}
	}

	/**
	 * Schedules the run when pages wait for it, as on activation.
	 */
	public static function resume(): void {
		if ( [] !== self::pending( 1 ) ) {
			self::schedule();
		}
	}

	/**
	 * Clears the run, as on deactivation.
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Leaves the pending flag out of WXR exports: it means something only on
	 * the site that set it.
	 *
	 * @param mixed $skip     Whether to skip the row.
	 * @param mixed $meta_key Meta key.
	 * @return bool
	 */
	public function skip_on_export( $skip, $meta_key ): bool {
		return (bool) $skip || self::PENDING === (string) $meta_key;
	}

	/**
	 * Yoast's post watcher, or null when Yoast SEO isn't active or its
	 * internals changed. An active Yoast SEO whose watcher can't be reached
	 * is reported under WP_DEBUG.
	 *
	 * @return object|null
	 */
	private static function watcher(): ?object {
		if ( ! function_exists( 'YoastSEO' ) ) {
			return null;
		}
		$reason = '';
		try {
			$watcher = class_exists( Indexable_Post_Watcher::class ) ? YoastSEO()->classes->get( Indexable_Post_Watcher::class ) : null;
		} catch ( Throwable $e ) {
			$watcher = null;
			$reason  = $e->getMessage();
		}
		if ( is_object( $watcher ) ) {
			return $watcher;
		}
		if ( '' === $reason ) {
			$message = __( 'Yoast SEO is active, but its post watcher could not be reached.', 'hierarchy-guard' );
		} else {
			/* translators: %s: the error's message. */
			$message = sprintf( __( 'Yoast SEO is active, but its post watcher could not be reached: %s', 'hierarchy-guard' ), $reason );
		}
		wp_trigger_error( __METHOD__, $message );
		return null;
	}

	/**
	 * Rebuilds one page's indexable, and reports under WP_DEBUG a page Yoast
	 * fails on; the others still get theirs. That page keeps its old
	 * indexable until it is saved again or Yoast's indexables are rebuilt:
	 * Yoast's own indexing only picks up posts without a current one.
	 *
	 * @param object $watcher Yoast's post watcher.
	 * @param int    $id      Post ID.
	 */
	private static function build( object $watcher, int $id ): void {
		try {
			$watcher->build_indexable( $id );
		} catch ( Throwable $e ) {
			wp_trigger_error(
				__METHOD__,
				sprintf(
					/* translators: 1: post ID, 2: the error's message. */
					__( 'Yoast SEO could not update its SEO data for post %1$d: %2$s', 'hierarchy-guard' ),
					$id,
					$e->getMessage()
				)
			);
		}
	}

	/**
	 * Which of these posts have children Yoast keeps indexables for: any
	 * post type and status but revisions and auto-drafts.
	 *
	 * @param int[] $ids Post IDs.
	 * @return int[]
	 */
	private static function with_children( array $ids ): array {
		global $wpdb;

		if ( [] === $ids ) {
			return [];
		}
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $placeholders holds only %d, one per ID, and the IDs come as one array; one primary-key read per ID, each stopping at its first child on the post_parent index.
		$found = $wpdb->get_col( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p WHERE p.ID IN ( {$placeholders} ) AND EXISTS ( SELECT 1 FROM {$wpdb->posts} c WHERE c.post_parent = p.ID AND c.post_type <> 'revision' AND c.post_status <> 'auto-draft' )", $ids ) );
		return array_map( 'intval', (array) $found );
	}

	/**
	 * Pending pages, oldest first, past a flag already read.
	 *
	 * @param int $limit Most to read.
	 * @param int $after Meta ID of the last flag read, 0 for none.
	 * @return array<int,int> Post IDs keyed by their flag's meta ID.
	 */
	private static function pending( int $limit, int $after = 0 ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- A range on the meta_key index, bounded by LIMIT.
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT meta_id, post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_id > %d ORDER BY meta_id ASC LIMIT %d", self::PENDING, $after, max( 1, $limit ) ) );
		$flags = [];
		foreach ( (array) $rows as $row ) {
			$flags[ (int) $row->meta_id ] = (int) $row->post_id;
		}
		return $flags;
	}

	/**
	 * Schedules the run, unless it is scheduled already.
	 */
	private static function schedule(): void {
		if ( false === wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time(), self::HOOK );
		}
	}
}
