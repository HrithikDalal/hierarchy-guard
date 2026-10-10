# Hierarchy Guard Trash Flow Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. Every review seat (task review, fix re-review, final review) runs on Opus.

**Goal:** When a page with subpages is trashed, apply the trash policy (move subpages up, trash them too, or leave them), keep every old URL resolvable, and take all of it back when the page leaves the trash by any route.

**Architecture:** `Trash\Trash_Handler` snapshots in `wp_trash_post`, mutates in `trashed_post` only after core succeeded, and reverses on `transition_post_status` out of trash. The work itself lives in `Trash\Operations` as bounded steps over a plain job array, so the same step runs inline for subtrees up to the batch threshold and from `Batch\Processor` (WP-Cron or Action Scheduler, plus a WP-CLI command) for larger ones. Re-parenting is a raw update through `Trash\Mover`, as core's `wp_delete_post()` does it. Subpages carry ID markers so a restore only undoes the plugin's own moves.

**Tech Stack:** PHP 8.0+, WordPress 6.6+, the plugin's existing engine (Ledger, Paths, Resolver, Change_Listener), PHPUnit 9.6 in wp-env, PHPCS (WordPress-Extra, WordPress-VIP-Go, PHPCompatibilityWP).

**Spec:** `docs/superpowers/specs/hierarchy-guard-spec.html` is a snapshot of the live spec at https://claude.ai/artifact/6V5RMR2w49uPiEp96ycyzq. Read "Trashing a parent" under How it works, the Data model table, and the Hooks and handlers table. The Pages-list dialog, outcome notice and Undo link are admin UI and belong to plan 3. This plan builds the engine they call (`hierarchy_guard_trash_policy` filter, `Change_Log`, `Trash_Handler::undo()`).

## Global Constraints

- Requires WordPress 6.6 and PHP 8.0. Namespace `HierarchyGuard`; new code goes in `HierarchyGuard\Trash` (`includes/HierarchyGuard/Trash/`) and `HierarchyGuard\Batch` (`includes/HierarchyGuard/Batch/`), plus `HierarchyGuard\Subtree`. Every file declares `strict_types=1`.
- Commits are authored by the repository's git config. No `Co-Authored-By` trailer and no Claude attribution in any commit message.
- `composer lint` must exit 0, warnings included. Run `composer lint:fix` (phpcbf) first; formatting-only changes it makes (array line breaks, `=`/`=>` alignment) are not deviations from this plan. Resolve any remaining warning with a behavior-neutral change or a targeted `// phpcs:ignore Sniff.Name.Code -- reason` on the line before.
- Tests that check a hook is registered must find the plugin's own object in `$GLOBALS['wp_filter'][ $hook ]->callbacks[ $priority ]`; `has_action( $hook )` alone is always true because core registers callbacks on the same hooks.
- Markers (post meta): `_hierarchy_guard_moved_from` (trashed parent ID), `_hierarchy_guard_moved_to` (new parent ID), `_hierarchy_guard_moved_slug` (original slug, only when the move renamed it), `_hierarchy_guard_trashed_with` (root ID). All four are skipped by `wxr_export_skip_postmeta`; old-path ledger entries stay exportable.
- Change summary: transient `hierarchy_guard_change_{user_id}`, 30 minutes, written only when a user is logged in, at most 200 affected IDs. Keys: `action` (`trash`|`restore`), `root`, `policy`, `counts`, `ids`, `job` (job ID while a batch runs, else `''`), `time`.
- Jobs: option `hierarchy_guard_job_{uuid}`, not autoloaded; index option `hierarchy_guard_jobs`, not autoloaded; cron hook `hierarchy_guard_process_batch` with the job ID as its only argument; Action Scheduler group `hierarchy-guard` when `as_enqueue_async_action()` exists; WP-CLI `wp hierarchy-guard process-jobs`.
- Batch threshold: filter `hierarchy_guard_batch_threshold`, default 200, floor 1. A subtree of size ≤ threshold runs inline; above it the first batch runs inline and the rest is queued. No single request does more than one threshold of moves or trashes.
- Policy: `Settings::trash_policy()` then filter `hierarchy_guard_trash_policy( $policy, WP_Post $post )`; an invalid filtered value falls back to the setting. Policies: `reparent` (default), `trash_children`, `keep`.
- Capabilities: `edit_post` to move a subpage, `delete_post` to trash one, checked against the user who triggered the trash (stored in the job). User ID 0 (cron, WP-CLI without `--user`) is trusted, as core trusts its own API. A subpage that fails the check stays where it is and is counted as `kept`.
- Nothing is written in `wp_trash_post`; all mutation happens in `trashed_post`. Re-parenting is `$wpdb->update` of `post_parent` and `post_name` plus `clean_post_cache()`, after `wp_unique_post_slug()` under the new parent. Fires `hierarchy_guard_children_reparented( int[] $ids, int $from, int $to )`.
- A trash job stops as soon as its root is no longer in the trash; a restore job stops as soon as its root is in the trash again. Restore moves a subpage back only if it still sits under `_hierarchy_guard_moved_to`, restores its original slug when free, records its interim path in the ledger, and restores co-trashed subpages with `wp_untrash_post_set_previous_status`.
- Copy rules for admin strings: sentence case, the word "subpages", no product name inside UI text, no exclamation marks, no claims about UI that doesn't exist yet.

## Review Focus

1. A subpage whose slug is already taken under the grandparent (the grandparent has its own `team`): it must become `team-2`, and its old URL must still resolve to it. Pinned in Task 8 (`test_colliding_slug_gets_a_number_and_its_old_url_still_resolves`).
2. An editor who can trash the parent but lacks `edit_post` on one subpage: that subpage stays put, the rest move, and the summary counts it as kept. Pinned in Task 8 (`test_subpages_the_user_cannot_edit_stay_put`).
3. Restoring through a plain status update (REST `PUT status=publish`, `wp post update --post_status=publish`), which never calls `wp_untrash_post()`: subpages must still come back. Pinned in Task 8 (`test_restoring_through_a_status_update_moves_subpages_back`).
4. A trash that fails after `wp_trash_post` fired (another plugin blocks the update): no subpage moves and no alias is recorded. Pinned in Task 8 (`test_a_failed_trash_changes_nothing`).
5. A subpage an editor moved somewhere else while the parent was in the trash: the restore must leave it alone and clear its markers. Pinned in Task 6 (`test_a_child_moved_elsewhere_is_left_alone`).

---

## File Structure

```
includes/HierarchyGuard/
├── Subtree.php                       NEW  bounded queries: live children, counts, marked posts
├── Paths.php                         MOD  untrashed_uri(): path with trashed segments restored
├── Ledger.php                        MOD  record_alias(): old parent path on the new parent or the root list
├── Change_Listener.php               MOD  delete flow uses untrashed_uri() + record_alias()
├── Plugin.php                        MOD  wiring
├── Redirects/Resolver.php            MOD  skip ledger hits whose base runs through the trash
├── Admin/Settings_Section.php        MOD  trash-policy field restored
├── Trash/Guard.php                   NEW  re-entrancy guard for the plugin's own core calls
├── Trash/Markers.php                 NEW  marker keys, set/clear, WXR export skip
├── Trash/Change_Log.php              NEW  per-user change summary transient
├── Trash/Mover.php                   NEW  raw re-parent with a unique slug
├── Trash/Operations.php              NEW  reparent, trash-subtree and restore steps over a job array
├── Trash/Trash_Handler.php           NEW  core hooks, policy, inline-or-queue dispatch, undo()
├── Batch/Queue.php                   NEW  job storage, index, scheduling
├── Batch/Processor.php               NEW  one batch per run, reschedule until done, drain()
└── Batch/CLI_Command.php             NEW  wp hierarchy-guard process-jobs
tests/phpunit/
├── Trash_Fixtures.php                NEW  trait: page tree, raw trash/untrash without hooks
├── GuardTest · MarkersTest · ChangeLogTest · SubtreeTest · MoverTest
├── OperationsReparentTest · OperationsTrashTest · OperationsRestoreTest
├── QueueTest · ProcessorTest · TrashHandlerTest
└── PathsTest · LedgerTest · ChangeListenerTest · ResolverTest · SettingsSectionTest   MOD
```

`Operations` is the only class that decides what happens to a subpage. `Trash_Handler` decides when and how big. `Mover`, `Subtree`, `Markers` and `Change_Log` are single-purpose helpers with no hooks except `Markers::register()`.

---

### Task 1: Guard, markers, change summary and test fixtures

**Files:**
- Create: `includes/HierarchyGuard/Trash/Guard.php`, `includes/HierarchyGuard/Trash/Markers.php`, `includes/HierarchyGuard/Trash/Change_Log.php`
- Create: `tests/phpunit/Trash_Fixtures.php`
- Modify: `composer.json` (autoload-dev), `includes/HierarchyGuard/Plugin.php`
- Test: `tests/phpunit/GuardTest.php`, `tests/phpunit/MarkersTest.php`, `tests/phpunit/ChangeLogTest.php`

**Interfaces:**
- Produces: `Trash\Guard::run( callable $callback ): mixed`, `Guard::active(): bool`.
- Produces: `Trash\Markers` constants `MOVED_FROM`, `MOVED_TO`, `MOVED_SLUG`, `TRASHED_WITH`, `KEYS`; `register(): void`; `skip_on_export( mixed $skip, mixed $meta_key ): bool`; static `mark_moved( int $post_id, int $from, int $to, string $original_slug = '' )`, `clear_moved( int $post_id )`, `mark_trashed_with( int $post_id, int $root_id )`, `clear_trashed_with( int $post_id )`.
- Produces: `Trash\Change_Log` constants `TTL`, `MAX_IDS`; static `key( int $user_id ): string`; `record( array $change ): void`; `latest( int $user_id ): ?array`; `forget( int $user_id ): void`.
- Produces: trait `HierarchyGuard\Tests\Trash_Fixtures` with `make_tree(): array<string,int>` (company / about / { team / leadership, history }), `trash_raw( int $post_id ): void`, `untrash_raw( int $post_id ): void`.

- [ ] **Step 1: Add the tests autoloader**

In `composer.json`, add after the `"autoload"` block:
```json
	"autoload-dev": {
		"psr-4": {
			"HierarchyGuard\\Tests\\": "tests/phpunit/"
		}
	},
```
Run: `composer dump-autoload`
Expected: `Generated autoload files`. (`autoload-dev` is not part of the lock file's content hash; `composer validate` stays clean.)

- [ ] **Step 2: Create the test fixtures trait**

`tests/phpunit/Trash_Fixtures.php`:
```php
<?php
/**
 * Shared fixtures for the trash-flow tests.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

/**
 * Builds a small page tree and moves posts in and out of the trash without
 * firing hooks, so a test can exercise one unit at a time.
 */
trait Trash_Fixtures {

	/**
	 * Company / about / { team / leadership, history }.
	 *
	 * @return array<string,int> Page IDs keyed by slug.
	 */
	protected function make_tree(): array {
		$ids               = [];
		$ids['company']    = self::factory()->post->create( [ 'post_type' => 'page', 'post_name' => 'company' ] );
		$ids['about']      = self::factory()->post->create( [ 'post_type' => 'page', 'post_name' => 'about', 'post_parent' => $ids['company'] ] );
		$ids['team']       = self::factory()->post->create( [ 'post_type' => 'page', 'post_name' => 'team', 'post_parent' => $ids['about'] ] );
		$ids['history']    = self::factory()->post->create( [ 'post_type' => 'page', 'post_name' => 'history', 'post_parent' => $ids['about'] ] );
		$ids['leadership'] = self::factory()->post->create( [ 'post_type' => 'page', 'post_name' => 'leadership', 'post_parent' => $ids['team'] ] );
		return $ids;
	}

	/**
	 * Leaves a post in the trash the way core does, without running any hooks.
	 *
	 * @param int $post_id Post ID.
	 */
	protected function trash_raw( int $post_id ): void {
		global $wpdb;
		$post = get_post( $post_id );
		add_post_meta( $post_id, '_wp_desired_post_slug', $post->post_name );
		add_post_meta( $post_id, '_wp_trash_meta_status', $post->post_status );
		$wpdb->update( $wpdb->posts, [ 'post_status' => 'trash', 'post_name' => $post->post_name . '__trashed' ], [ 'ID' => $post_id ] );
		clean_post_cache( $post_id );
	}

	/**
	 * Takes a post out of the trash the way core does, without running any hooks.
	 *
	 * @param int $post_id Post ID.
	 */
	protected function untrash_raw( int $post_id ): void {
		global $wpdb;
		$slug   = (string) get_post_meta( $post_id, '_wp_desired_post_slug', true );
		$status = (string) get_post_meta( $post_id, '_wp_trash_meta_status', true );
		$wpdb->update( $wpdb->posts, [ 'post_status' => '' !== $status ? $status : 'publish', 'post_name' => $slug ], [ 'ID' => $post_id ] );
		delete_post_meta( $post_id, '_wp_desired_post_slug' );
		delete_post_meta( $post_id, '_wp_trash_meta_status' );
		clean_post_cache( $post_id );
	}
}
```

- [ ] **Step 3: Write the failing tests**

`tests/phpunit/GuardTest.php`:
```php
<?php
/**
 * Re-entrancy guard.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Trash\Guard;
use RuntimeException;
use WP_UnitTestCase;

/**
 * @covers \HierarchyGuard\Trash\Guard
 */
final class GuardTest extends WP_UnitTestCase {

	public function test_run_returns_the_callback_value_and_holds_the_guard(): void {
		$this->assertFalse( Guard::active() );
		$seen   = null;
		$result = Guard::run(
			static function () use ( &$seen ): int {
				$seen = Guard::active();
				return 42;
			}
		);
		$this->assertSame( 42, $result );
		$this->assertTrue( $seen );
		$this->assertFalse( Guard::active() );
	}

	public function test_nested_runs_release_only_at_the_outermost_level(): void {
		$inner = null;
		Guard::run(
			static function () use ( &$inner ): void {
				Guard::run( static fn() => null );
				$inner = Guard::active();
			}
		);
		$this->assertTrue( $inner, 'The outer run still holds the guard after an inner one returns.' );
		$this->assertFalse( Guard::active() );
	}

	public function test_an_exception_releases_the_guard(): void {
		try {
			Guard::run(
				static function (): void {
					throw new RuntimeException( 'boom' );
				}
			);
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'boom', $e->getMessage() );
		}
		$this->assertFalse( Guard::active() );
	}
}
```

`tests/phpunit/MarkersTest.php`:
```php
<?php
/**
 * Subpage markers.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Trash\Markers;
use WP_UnitTestCase;

/**
 * @covers \HierarchyGuard\Trash\Markers
 */
final class MarkersTest extends WP_UnitTestCase {

	public function test_the_export_filter_is_registered_by_the_plugin(): void {
		global $wp_filter;
		$found = false;
		foreach ( $wp_filter['wxr_export_skip_postmeta']->callbacks[10] ?? [] as $callback ) {
			if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof Markers ) {
				$found = true;
			}
		}
		$this->assertTrue( $found );
	}

	public function test_id_markers_are_left_out_of_exports(): void {
		foreach ( Markers::KEYS as $key ) {
			$this->assertTrue( apply_filters( 'wxr_export_skip_postmeta', false, $key, null ), $key );
		}
		$this->assertFalse( apply_filters( 'wxr_export_skip_postmeta', false, '_hierarchy_guard_path_abc', null ), 'Old-path entries stay exportable.' );
		$this->assertTrue( apply_filters( 'wxr_export_skip_postmeta', true, 'anything', null ), 'An earlier skip is kept.' );
	}

	public function test_moved_markers_round_trip(): void {
		$id = self::factory()->post->create( [ 'post_type' => 'page' ] );

		Markers::mark_moved( $id, 5, 7, 'team' );
		$this->assertSame( '5', get_post_meta( $id, Markers::MOVED_FROM, true ) );
		$this->assertSame( '7', get_post_meta( $id, Markers::MOVED_TO, true ) );
		$this->assertSame( 'team', get_post_meta( $id, Markers::MOVED_SLUG, true ) );

		Markers::clear_moved( $id );
		foreach ( [ Markers::MOVED_FROM, Markers::MOVED_TO, Markers::MOVED_SLUG ] as $key ) {
			$this->assertFalse( metadata_exists( 'post', $id, $key ), $key );
		}
	}

	public function test_the_slug_marker_is_written_only_for_a_rename(): void {
		$id = self::factory()->post->create( [ 'post_type' => 'page' ] );
		Markers::mark_moved( $id, 5, 7 );
		$this->assertFalse( metadata_exists( 'post', $id, Markers::MOVED_SLUG ) );
	}

	public function test_trashed_with_marker_round_trips(): void {
		$id = self::factory()->post->create( [ 'post_type' => 'page' ] );
		Markers::mark_trashed_with( $id, 9 );
		$this->assertSame( '9', get_post_meta( $id, Markers::TRASHED_WITH, true ) );
		Markers::clear_trashed_with( $id );
		$this->assertFalse( metadata_exists( 'post', $id, Markers::TRASHED_WITH ) );
	}
}
```

`tests/phpunit/ChangeLogTest.php`:
```php
<?php
/**
 * Per-user change summary.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Trash\Change_Log;
use WP_UnitTestCase;

/**
 * @covers \HierarchyGuard\Trash\Change_Log
 */
final class ChangeLogTest extends WP_UnitTestCase {

	private Change_Log $log;

	private int $admin;

	public function set_up(): void {
		parent::set_up();
		$this->log   = new Change_Log();
		$this->admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
	}

	public function test_records_the_latest_change_for_the_current_user(): void {
		wp_set_current_user( $this->admin );
		$this->log->record( [ 'action' => 'trash', 'root' => 12, 'policy' => 'reparent', 'counts' => [ 'moved' => 2 ], 'ids' => [ 3, 4 ], 'job' => '' ] );

		$change = $this->log->latest( $this->admin );
		$this->assertSame( 'trash', $change['action'] );
		$this->assertSame( 12, $change['root'] );
		$this->assertSame( [ 3, 4 ], $change['ids'] );
		$this->assertIsInt( $change['time'] );
	}

	public function test_affected_ids_are_capped(): void {
		wp_set_current_user( $this->admin );
		$this->log->record( [ 'action' => 'trash', 'root' => 1, 'policy' => 'reparent', 'counts' => [], 'ids' => range( 1, 250 ), 'job' => '' ] );
		$this->assertCount( Change_Log::MAX_IDS, $this->log->latest( $this->admin )['ids'] );
	}

	public function test_nothing_is_stored_without_a_user(): void {
		wp_set_current_user( 0 );
		$this->log->record( [ 'action' => 'trash', 'root' => 1, 'policy' => 'reparent', 'counts' => [], 'ids' => [], 'job' => '' ] );
		$this->assertNull( $this->log->latest( 0 ) );
		$this->assertFalse( get_transient( Change_Log::key( 0 ) ) );
	}

	public function test_forget_clears_the_summary(): void {
		wp_set_current_user( $this->admin );
		$this->log->record( [ 'action' => 'trash', 'root' => 1, 'policy' => 'keep', 'counts' => [], 'ids' => [], 'job' => '' ] );
		$this->log->forget( $this->admin );
		$this->assertNull( $this->log->latest( $this->admin ) );
	}
}
```

- [ ] **Step 4: Run the tests to verify they fail**

Run: `npm run test:php -- --filter 'GuardTest|MarkersTest|ChangeLogTest'`
Expected: `Error: Class "HierarchyGuard\Trash\Guard" not found` (and the same for Markers and Change_Log).

- [ ] **Step 5: Create Guard, Markers and Change_Log**

`includes/HierarchyGuard/Trash/Guard.php`:
```php
<?php
/**
 * Re-entrancy guard for the plugin's own trash operations.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Trash;

/**
 * While the plugin trashes or restores posts itself, its own hooks must not
 * treat those calls as new editor actions.
 */
final class Guard {

	/**
	 * Nesting depth of guarded calls.
	 *
	 * @var int
	 */
	private static int $depth = 0;

	/**
	 * Runs a callback with the guard held.
	 *
	 * @param callable $callback Work to run.
	 * @return mixed The callback's return value.
	 */
	public static function run( callable $callback ) {
		++self::$depth;
		try {
			return $callback();
		} finally {
			--self::$depth;
		}
	}

	/**
	 * Whether a guarded call is in progress.
	 *
	 * @return bool
	 */
	public static function active(): bool {
		return self::$depth > 0;
	}
}
```

`includes/HierarchyGuard/Trash/Markers.php`:
```php
<?php
/**
 * Markers on subpages the plugin moved or trashed.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Trash;

/**
 * Post meta that lets a restore undo exactly what the trash flow did. The values
 * are post IDs, which mean nothing on another site, so they never leave in a WXR export.
 */
final class Markers {

	public const MOVED_FROM   = '_hierarchy_guard_moved_from';
	public const MOVED_TO     = '_hierarchy_guard_moved_to';
	public const MOVED_SLUG   = '_hierarchy_guard_moved_slug';
	public const TRASHED_WITH = '_hierarchy_guard_trashed_with';

	public const KEYS = [
		self::MOVED_FROM,
		self::MOVED_TO,
		self::MOVED_SLUG,
		self::TRASHED_WITH,
	];

	/**
	 * Hooks into core.
	 */
	public function register(): void {
		add_filter( 'wxr_export_skip_postmeta', [ $this, 'skip_on_export' ], 10, 2 );
	}

	/**
	 * Leaves the markers out of WXR exports.
	 *
	 * @param mixed $skip     Whether an earlier callback skips the row.
	 * @param mixed $meta_key Meta key of the row.
	 * @return bool
	 */
	public function skip_on_export( $skip, $meta_key ): bool {
		return (bool) $skip || in_array( (string) $meta_key, self::KEYS, true );
	}

	/**
	 * Marks a subpage as moved from the trashed parent to its new parent.
	 *
	 * @param int    $post_id       Subpage.
	 * @param int    $from          Trashed parent.
	 * @param int    $to            New parent, 0 for top level.
	 * @param string $original_slug Slug before the move, only when the move renamed it.
	 */
	public static function mark_moved( int $post_id, int $from, int $to, string $original_slug = '' ): void {
		update_post_meta( $post_id, self::MOVED_FROM, $from );
		update_post_meta( $post_id, self::MOVED_TO, $to );
		if ( '' !== $original_slug ) {
			update_post_meta( $post_id, self::MOVED_SLUG, $original_slug );
		}
	}

	/**
	 * Removes the moved markers.
	 *
	 * @param int $post_id Subpage.
	 */
	public static function clear_moved( int $post_id ): void {
		delete_post_meta( $post_id, self::MOVED_FROM );
		delete_post_meta( $post_id, self::MOVED_TO );
		delete_post_meta( $post_id, self::MOVED_SLUG );
	}

	/**
	 * Marks a subpage as trashed together with a root page.
	 *
	 * @param int $post_id Subpage.
	 * @param int $root_id The page whose trash took it along.
	 */
	public static function mark_trashed_with( int $post_id, int $root_id ): void {
		update_post_meta( $post_id, self::TRASHED_WITH, $root_id );
	}

	/**
	 * Removes the trashed-with marker.
	 *
	 * @param int $post_id Subpage.
	 */
	public static function clear_trashed_with( int $post_id ): void {
		delete_post_meta( $post_id, self::TRASHED_WITH );
	}
}
```

`includes/HierarchyGuard/Trash/Change_Log.php`:
```php
<?php
/**
 * Summary of the last trash or restore, per user.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Trash;

/**
 * Feeds the outcome notice and Undo. Old/new URL pairs are never stored here;
 * they are computed on demand from the IDs.
 */
final class Change_Log {

	public const TTL     = 30 * MINUTE_IN_SECONDS;
	public const MAX_IDS = 200;

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
	 * Stores a change for the current user. Does nothing without a user.
	 *
	 * @param array<string,mixed> $change Keys: action, root, policy, counts, ids, job.
	 */
	public function record( array $change ): void {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return;
		}
		$change['ids']  = array_slice( array_map( 'intval', (array) ( $change['ids'] ?? [] ) ), 0, self::MAX_IDS );
		$change['time'] = time();
		set_transient( self::key( $user_id ), $change, self::TTL );
	}

	/**
	 * The latest change for a user.
	 *
	 * @param int $user_id User ID.
	 * @return array<string,mixed>|null
	 */
	public function latest( int $user_id ): ?array {
		$change = get_transient( self::key( $user_id ) );
		return is_array( $change ) ? $change : null;
	}

	/**
	 * Clears a user's summary.
	 *
	 * @param int $user_id User ID.
	 */
	public function forget( int $user_id ): void {
		delete_transient( self::key( $user_id ) );
	}
}
```

- [ ] **Step 6: Register the markers in Plugin::register()**

In `includes/HierarchyGuard/Plugin.php`, add after the `Admin\Settings_Section` line:
```php
		( new Trash\Markers() )->register();
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `npm run test:php -- --filter 'GuardTest|MarkersTest|ChangeLogTest'`
Expected: `OK (12 tests, ...)`.

Run: `npm run test:php` and `composer lint`
Expected: all green; lint exit 0.

- [ ] **Step 8: Commit**

```bash
git add composer.json includes tests
git commit -m "feat: guard, subpage markers and change summary for the trash flow"
```

---

### Task 2: Paths through the trash, ledger aliases, and the two parked edge cases

**Files:**
- Modify: `includes/HierarchyGuard/Paths.php`, `includes/HierarchyGuard/Ledger.php`, `includes/HierarchyGuard/Change_Listener.php`, `includes/HierarchyGuard/Redirects/Resolver.php`
- Test: `tests/phpunit/PathsTest.php`, `tests/phpunit/LedgerTest.php`, `tests/phpunit/ChangeListenerTest.php`, `tests/phpunit/ResolverTest.php`

**Interfaces:**
- Consumes: `Paths::is_trashed_slug()`, `Paths::strip_trashed_suffix()`, `Paths::has_trashed_segment()` (engine).
- Produces: `Paths::untrashed_uri( \WP_Post $post ): string`; `Ledger::record_alias( string $post_type, int $new_parent_id, string $old_path ): void`. `Change_Listener::pre_trash_uri()` is removed.

- [ ] **Step 1: Write the failing tests**

Add to `tests/phpunit/PathsTest.php`:
```php
	public function test_untrashed_uri_restores_trashed_segments(): void {
		global $wpdb;

		$company = self::factory()->post->create( [ 'post_type' => 'page', 'post_name' => 'company' ] );
		$about   = self::factory()->post->create( [ 'post_type' => 'page', 'post_name' => 'about', 'post_parent' => $company ] );
		$team    = self::factory()->post->create( [ 'post_type' => 'page', 'post_name' => 'team', 'post_parent' => $about ] );

		// about trashed with core's desired-slug meta; team trashed second, numbered, without it.
		add_post_meta( $about, '_wp_desired_post_slug', 'about' );
		$wpdb->update( $wpdb->posts, [ 'post_name' => 'about__trashed' ], [ 'ID' => $about ] );
		$wpdb->update( $wpdb->posts, [ 'post_name' => 'team__trashed-2' ], [ 'ID' => $team ] );
		clean_post_cache( $about );
		clean_post_cache( $team );

		$this->assertSame( 'company/about__trashed/team__trashed-2', Paths::uri( get_post( $team ) ), 'Precondition: the live path runs through the trash.' );
		$this->assertSame( 'company/about/team', Paths::untrashed_uri( get_post( $team ) ) );
		$this->assertSame( 'company', Paths::untrashed_uri( get_post( $company ) ), 'Untrashed segments are unchanged.' );
	}
```

Add to `tests/phpunit/LedgerTest.php`:
```php
	public function test_record_alias_writes_on_the_new_parent_or_the_root_list(): void {
		$this->ledger->record_alias( 'page', $this->about, 'about/team-old' );
		$this->assertSame( $this->about, $this->ledger->find( 'page', 'about/team-old' ) );

		$this->ledger->record_alias( 'page', 0, 'gone' );
		$this->assertSame( 0, $this->ledger->find( 'page', 'gone' ) );
	}
```

Add to `tests/phpunit/ChangeListenerTest.php`:
```php
	public function test_emptying_a_trashed_tree_keeps_paths_through_trashed_ancestors(): void {
		$leadership = self::factory()->post->create( [ 'post_type' => 'page', 'post_name' => 'leadership', 'post_parent' => $this->team ] );

		wp_trash_post( $this->about );
		wp_trash_post( $this->team );
		$this->assertStringEndsWith( '__trashed', get_post( $this->team )->post_name, 'Precondition: both ancestors are in the trash.' );

		// The trash empties child-first: team, then about.
		wp_delete_post( $this->team, true );
		wp_delete_post( $this->about, true );

		$this->assertSame( $this->company, (int) get_post( $leadership )->post_parent, 'Precondition: core moved leadership up twice.' );
		$resolved = ( new Resolver( $this->ledger ) )->resolve( 'page', 'company/about/team/leadership' );
		$this->assertNotNull( $resolved );
		$this->assertSame( $leadership, $resolved->ID );
	}
```

Add to `tests/phpunit/ResolverTest.php`:
```php
	public function test_a_hit_under_a_trashed_ancestor_gives_way_to_a_shorter_prefix(): void {
		wp_update_post( [ 'ID' => $this->team, 'post_name' => 'crew' ] );
		wp_trash_post( $this->about );
		$this->assertSame( 'company/about__trashed/crew', get_page_uri( $this->team ), 'Precondition: the ledger hit lives under the trash.' );

		// Another route still leads somewhere live: company/about now maps to company.
		$replacement = self::factory()->post->create( [ 'post_type' => 'page', 'post_name' => 'team', 'post_parent' => $this->company ] );
		$this->ledger->record_alias( 'page', $this->company, 'company/about' );

		$target = $this->resolver->resolve( 'page', 'company/about/team' );
		$this->assertNotNull( $target );
		$this->assertSame( $replacement, $target->ID );
	}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npm run test:php -- --filter 'untrashed_uri|record_alias|through_trashed_ancestors|gives_way_to_a_shorter_prefix'`
Expected: errors for the missing `Paths::untrashed_uri()` and `Ledger::record_alias()`; the ChangeListener and Resolver tests fail on their final assertions.

- [ ] **Step 3: Add untrashed_uri() to Paths**

In `includes/HierarchyGuard/Paths.php`, replace the whole `uri()` method with the four methods below (the loop moves into `chain()` so both public methods share it):
```php
	/**
	 * The path of a post built from its slug and its ancestors' slugs.
	 *
	 * Uses the object's own post_parent, so a $post_before object passed by
	 * post_updated yields the chain as it was before the save. Ancestors with
	 * an empty slug are skipped, matching get_page_uri().
	 *
	 * @param WP_Post $post Post object.
	 * @return string Normalized path, for example `company/about`.
	 */
	public static function uri( WP_Post $post ): string {
		return self::chain( $post, static fn( WP_Post $item ): string => (string) $item->post_name );
	}

	/**
	 * The path a post had before it or any ancestor was trashed: each segment
	 * carrying core's trashed suffix gets back the slug core kept in
	 * _wp_desired_post_slug, or the slug with the suffix stripped.
	 *
	 * @param WP_Post $post Post object.
	 * @return string Normalized path.
	 */
	public static function untrashed_uri( WP_Post $post ): string {
		return self::chain( $post, static fn( WP_Post $item ): string => self::untrashed_slug( $item ) );
	}

	/**
	 * Joins the slugs of a post and its ancestors, root first.
	 *
	 * @param WP_Post  $post    Post object.
	 * @param callable $slug_of Returns the slug to use for one post.
	 * @return string Normalized path.
	 */
	private static function chain( WP_Post $post, callable $slug_of ): string {
		$segments  = [ (string) $slug_of( $post ) ];
		$parent_id = (int) $post->post_parent;
		$guard     = 0;

		while ( $parent_id > 0 && $guard < 50 ) {
			++$guard;
			$parent = get_post( $parent_id );
			if ( ! $parent instanceof WP_Post ) {
				break;
			}
			$slug = (string) $slug_of( $parent );
			if ( '' !== $slug ) {
				array_unshift( $segments, $slug );
			}
			$parent_id = (int) $parent->post_parent;
		}

		return self::normalize( implode( '/', $segments ) );
	}

	/**
	 * A post's slug as it was before core trashed it.
	 *
	 * @param WP_Post $post Post object.
	 * @return string
	 */
	private static function untrashed_slug( WP_Post $post ): string {
		$slug = (string) $post->post_name;
		if ( ! self::is_trashed_slug( $slug ) ) {
			return $slug;
		}
		$desired = (string) get_post_meta( $post->ID, '_wp_desired_post_slug', true );
		return '' !== $desired ? $desired : self::strip_trashed_suffix( $slug );
	}
```

- [ ] **Step 4: Add record_alias() to Ledger**

In `includes/HierarchyGuard/Ledger.php`, add after `record_root()`:
```php
	/**
	 * Records that children which lived under $old_path now live under a new
	 * parent, or became top level when it is 0.
	 *
	 * @param string $post_type     Post type name.
	 * @param int    $new_parent_id New parent ID, 0 for top level.
	 * @param string $old_path      The old parent path, any form.
	 */
	public function record_alias( string $post_type, int $new_parent_id, string $old_path ): void {
		if ( $new_parent_id > 0 ) {
			$this->record( $new_parent_id, $old_path );
			return;
		}
		$this->record_root( $post_type, $old_path );
	}
```

- [ ] **Step 5: Use both in Change_Listener::on_before_delete()**

In `includes/HierarchyGuard/Change_Listener.php`, replace the block from `$paths = array_merge(` to the end of the `foreach` in `on_before_delete()` with:
```php
		$paths = array_merge( [ Paths::uri( $post ), Paths::untrashed_uri( $post ) ], $this->ledger->paths_for( $post_id ) );
		$paths = array_unique(
			array_filter(
				$paths,
				static fn( string $path ): bool => '' !== $path && ! Paths::has_trashed_segment( $path )
			)
		);

		foreach ( $paths as $path ) {
			$this->ledger->record_alias( (string) $post->post_type, (int) $post->post_parent, $path );
		}
```
Then delete the `pre_trash_uri()` method and its docblock; `untrashed_uri()` replaces it.

- [ ] **Step 6: Skip ledger hits whose base runs through the trash**

In `includes/HierarchyGuard/Redirects/Resolver.php`, in `rewrite_once()`, change:
```php
				$base = Paths::uri( $post );
			}
```
to:
```php
				$base = Paths::uri( $post );
				if ( Paths::has_trashed_segment( $base ) ) {
					continue;
				}
			}
```
And extend the method's docblock sentence to: "Ledger hits on trashed posts, or on posts under a trashed ancestor, are skipped so a shorter prefix can match."

- [ ] **Step 7: Run the tests to verify they pass**

Run: `npm run test:php -- --filter 'PathsTest|LedgerTest|ChangeListenerTest|ResolverTest'`
Expected: all pass, including the existing pre-trash, numbered-slug and strip-suffix tests, which now run through `untrashed_uri()`.

Run: `npm run test:php` and `composer lint`
Expected: all green; lint exit 0.

- [ ] **Step 8: Commit**

```bash
git add includes tests
git commit -m "feat: paths through trashed ancestors and ledger aliases for moved children"
```

---

### Task 3: Bounded subtree queries and the mover

**Files:**
- Create: `includes/HierarchyGuard/Subtree.php`, `includes/HierarchyGuard/Trash/Mover.php`
- Test: `tests/phpunit/SubtreeTest.php`, `tests/phpunit/MoverTest.php`

**Interfaces:**
- Consumes: `Paths::uri()`, `Paths::untrashed_uri()`.
- Produces: `Subtree::live_children( int $parent_id, string $post_type, int $limit, int $after_id = 0 ): int[]`; `Subtree::count_live_children( int $parent_id, string $post_type ): int`; `Subtree::count_live_descendants( int $root_id, string $post_type, int $cap ): int` (a number above `$cap` means "more than the cap"); `Subtree::marked( string $meta_key, int $root_id, string $post_type, int $limit, int $after_id = 0 ): int[]`. "Live" means any status except `trash`, `auto-draft`, `inherit`.
- Produces: `Trash\Mover::move( int $post_id, int $new_parent_id, string $preferred_slug = '' ): array{moved:bool, old_path:string, new_path:string, old_slug:string, renamed:bool}`.

- [ ] **Step 1: Write the failing tests**

`tests/phpunit/SubtreeTest.php`:
```php
<?php
/**
 * Bounded subtree queries.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Subtree;
use WP_UnitTestCase;

/**
 * @covers \HierarchyGuard\Subtree
 */
final class SubtreeTest extends WP_UnitTestCase {

	use Trash_Fixtures;

	/**
	 * Page IDs.
	 *
	 * @var array<string,int>
	 */
	private array $ids;

	public function set_up(): void {
		parent::set_up();
		$this->ids = $this->make_tree();
	}

	public function test_live_children_are_ordered_limited_and_paged(): void {
		$extra = self::factory()->post->create( [ 'post_type' => 'page', 'post_name' => 'extra', 'post_parent' => $this->ids['about'] ] );

		$this->assertSame( [ $this->ids['team'], $this->ids['history'], $extra ], Subtree::live_children( $this->ids['about'], 'page', 10 ) );
		$this->assertSame( [ $this->ids['team'] ], Subtree::live_children( $this->ids['about'], 'page', 1 ) );
		$this->assertSame( [ $this->ids['history'], $extra ], Subtree::live_children( $this->ids['about'], 'page', 10, $this->ids['team'] ) );
		$this->assertSame( [], Subtree::live_children( $this->ids['about'], 'post', 10 ), 'Scoped to the post type.' );
	}

	public function test_trashed_and_internal_children_are_not_live(): void {
		$draft = self::factory()->post->create( [ 'post_type' => 'page', 'post_status' => 'draft', 'post_parent' => $this->ids['about'] ] );
		$this->trash_raw( $this->ids['history'] );

		$this->assertSame( [ $this->ids['team'], $draft ], Subtree::live_children( $this->ids['about'], 'page', 10 ), 'Drafts are live; trashed pages are not.' );
		$this->assertSame( 2, Subtree::count_live_children( $this->ids['about'], 'page' ) );
	}

	public function test_count_live_descendants_stops_past_the_cap(): void {
		$this->assertSame( 3, Subtree::count_live_descendants( $this->ids['about'], 'page', 10 ) );
		$this->assertGreaterThan( 2, Subtree::count_live_descendants( $this->ids['about'], 'page', 2 ) );
		$this->assertSame( 0, Subtree::count_live_descendants( $this->ids['leadership'], 'page', 10 ) );
	}

	public function test_marked_finds_posts_by_marker_value(): void {
		add_post_meta( $this->ids['team'], '_hg_test_marker', (string) $this->ids['about'] );
		add_post_meta( $this->ids['history'], '_hg_test_marker', (string) $this->ids['about'] );
		add_post_meta( $this->ids['leadership'], '_hg_test_marker', '999' );

		$this->assertSame( [ $this->ids['team'], $this->ids['history'] ], Subtree::marked( '_hg_test_marker', $this->ids['about'], 'page', 10 ) );
		$this->assertSame( [ $this->ids['history'] ], Subtree::marked( '_hg_test_marker', $this->ids['about'], 'page', 10, $this->ids['team'] ) );
	}
}
```

`tests/phpunit/MoverTest.php`:
```php
<?php
/**
 * Raw re-parenting.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Trash\Mover;
use WP_UnitTestCase;

/**
 * @covers \HierarchyGuard\Trash\Mover
 */
final class MoverTest extends WP_UnitTestCase {

	use Trash_Fixtures;

	/**
	 * Page IDs.
	 *
	 * @var array<string,int>
	 */
	private array $ids;

	private Mover $mover;

	public function set_up(): void {
		parent::set_up();
		$this->set_permalink_structure( '/%postname%/' );
		$this->ids   = $this->make_tree();
		$this->mover = new Mover();
	}

	public function tear_down(): void {
		$this->set_permalink_structure( '' );
		parent::tear_down();
	}

	public function test_moves_a_post_without_touching_its_modified_date(): void {
		$modified = get_post( $this->ids['team'] )->post_modified;

		$result = $this->mover->move( $this->ids['team'], $this->ids['company'] );

		$this->assertTrue( $result['moved'] );
		$this->assertSame( $this->ids['company'], (int) get_post( $this->ids['team'] )->post_parent );
		$this->assertSame( $modified, get_post( $this->ids['team'] )->post_modified );
		$this->assertSame( 'company/about/team', $result['old_path'] );
		$this->assertSame( 'company/team', $result['new_path'] );
		$this->assertFalse( $result['renamed'] );
	}

	public function test_a_taken_slug_gets_a_number(): void {
		self::factory()->post->create( [ 'post_type' => 'page', 'post_name' => 'team', 'post_parent' => $this->ids['company'] ] );

		$result = $this->mover->move( $this->ids['team'], $this->ids['company'] );

		$this->assertSame( 'team-2', get_post( $this->ids['team'] )->post_name );
		$this->assertTrue( $result['renamed'] );
		$this->assertSame( 'team', $result['old_slug'] );
		$this->assertSame( 'company/team-2', $result['new_path'] );
	}

	public function test_the_old_path_ignores_the_trash_suffix(): void {
		$this->trash_raw( $this->ids['about'] );
		$this->assertSame( 'company/about/team', $this->mover->move( $this->ids['team'], $this->ids['company'] )['old_path'] );
	}

	public function test_a_preferred_slug_is_used_when_free(): void {
		$taken = self::factory()->post->create( [ 'post_type' => 'page', 'post_name' => 'team', 'post_parent' => $this->ids['company'] ] );
		$this->mover->move( $this->ids['team'], $this->ids['company'] );
		$this->assertSame( 'team-2', get_post( $this->ids['team'] )->post_name, 'Precondition.' );

		wp_delete_post( $taken, true );
		$this->mover->move( $this->ids['team'], $this->ids['about'], 'team' );

		$this->assertSame( 'team', get_post( $this->ids['team'] )->post_name );
	}

	public function test_a_missing_post_is_reported_unmoved(): void {
		$this->assertFalse( $this->mover->move( 999999, 0 )['moved'] );
	}
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npm run test:php -- --filter 'SubtreeTest|MoverTest'`
Expected: `Error: Class "HierarchyGuard\Subtree" not found` and the same for `Trash\Mover`.

- [ ] **Step 3: Create Subtree**

`includes/HierarchyGuard/Subtree.php`:
```php
<?php
/**
 * Bounded queries over a post's subtree.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard;

/**
 * Every query here is bounded by a limit or a cap, never by the size of the subtree.
 * "Live" means any status except trash and core's internal auto-draft and inherit.
 */
final class Subtree {

	/**
	 * Live direct children, lowest ID first, after a cursor.
	 *
	 * @param int    $parent_id Parent post ID.
	 * @param string $post_type Post type name.
	 * @param int    $limit     Maximum IDs to return.
	 * @param int    $after_id  Only IDs above this one.
	 * @return int[]
	 */
	public static function live_children( int $parent_id, string $post_type, int $limit, int $after_id = 0 ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Cursor over the post_parent index; every batch writes, so a cache would serve stale pages.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = %s AND post_status NOT IN ( 'trash', 'auto-draft', 'inherit' ) AND ID > %d ORDER BY ID ASC LIMIT %d",
				$parent_id,
				$post_type,
				$after_id,
				max( 1, $limit )
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Number of live direct children.
	 *
	 * @param int    $parent_id Parent post ID.
	 * @param string $post_type Post type name.
	 * @return int
	 */
	public static function count_live_children( int $parent_id, string $post_type ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One indexed count per trash.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = %s AND post_status NOT IN ( 'trash', 'auto-draft', 'inherit' )",
				$parent_id,
				$post_type
			)
		);
	}

	/**
	 * Counts live descendants level by level, stopping once the count passes the cap.
	 *
	 * @param int    $root_id   Root post ID.
	 * @param string $post_type Post type name.
	 * @param int    $cap       Stop counting past this number.
	 * @return int The count, or a number above $cap when there are more.
	 */
	public static function count_live_descendants( int $root_id, string $post_type, int $cap ): int {
		global $wpdb;

		$count = 0;
		$level = [ $root_id ];
		$depth = 0;

		while ( [] !== $level && $count <= $cap && $depth < 50 ) {
			++$depth;
			$in = implode( ',', array_map( 'intval', $level ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in holds integers only; LIMIT bounds each level by the remaining cap.
			$level  = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_parent IN ( {$in} ) AND post_type = %s AND post_status NOT IN ( 'trash', 'auto-draft', 'inherit' ) LIMIT %d", $post_type, $cap + 1 - $count ) ) );
			$count += count( $level );
		}

		return $count;
	}

	/**
	 * Posts whose marker points at a root post, lowest ID first, after a cursor.
	 *
	 * @param string $meta_key  Marker meta key.
	 * @param int    $root_id   Root post ID the marker stores.
	 * @param string $post_type Post type name.
	 * @param int    $limit     Maximum IDs to return.
	 * @param int    $after_id  Only IDs above this one.
	 * @return int[]
	 */
	public static function marked( string $meta_key, int $root_id, string $post_type, int $limit, int $after_id = 0 ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Seek on the meta_key index; only moved or co-trashed subpages carry these keys.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID WHERE m.meta_key = %s AND m.meta_value = %s AND p.post_type = %s AND p.ID > %d ORDER BY p.ID ASC LIMIT %d",
				$meta_key,
				(string) $root_id,
				$post_type,
				$after_id,
				max( 1, $limit )
			)
		);

		return array_map( 'intval', $ids );
	}
}
```

- [ ] **Step 4: Create Mover**

`includes/HierarchyGuard/Trash/Mover.php`:
```php
<?php
/**
 * Moves a post under a new parent.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Trash;

use HierarchyGuard\Paths;
use WP_Post;

/**
 * Re-parents the way wp_delete_post() does: a direct update, so content is not
 * re-filtered and the modified date stays, then a cache clear. The slug is made
 * unique under the new parent first.
 */
final class Mover {

	/**
	 * Moves a post.
	 *
	 * @param int    $post_id        Post to move.
	 * @param int    $new_parent_id  New parent, 0 for top level.
	 * @param string $preferred_slug Slug to try first, such as the one it had before an earlier move.
	 * @return array{moved:bool,old_path:string,new_path:string,old_slug:string,renamed:bool}
	 */
	public function move( int $post_id, int $new_parent_id, string $preferred_slug = '' ): array {
		global $wpdb;

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return [
				'moved'    => false,
				'old_path' => '',
				'new_path' => '',
				'old_slug' => '',
				'renamed'  => false,
			];
		}

		$old_path = Paths::untrashed_uri( $post );
		$old_slug = (string) $post->post_name;
		$slug     = '' !== $preferred_slug ? $preferred_slug : $old_slug;
		if ( '' !== $slug ) {
			$slug = wp_unique_post_slug( $slug, $post_id, (string) $post->post_status, (string) $post->post_type, $new_parent_id );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Mirrors wp_delete_post()'s re-parent; the post cache is cleared next.
		$updated = $wpdb->update(
			$wpdb->posts,
			[
				'post_parent' => $new_parent_id,
				'post_name'   => $slug,
			],
			[ 'ID' => $post_id ]
		);
		clean_post_cache( $post_id );

		$moved = get_post( $post_id );

		return [
			'moved'    => false !== $updated,
			'old_path' => $old_path,
			'new_path' => $moved instanceof WP_Post ? Paths::uri( $moved ) : '',
			'old_slug' => $old_slug,
			'renamed'  => $slug !== $old_slug,
		];
	}
}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `npm run test:php -- --filter 'SubtreeTest|MoverTest'`
Expected: `OK (9 tests, ...)`.

Run: `npm run test:php` and `composer lint`
Expected: all green; lint exit 0.

- [ ] **Step 6: Commit**

```bash
git add includes tests
git commit -m "feat: bounded subtree queries and a raw re-parent with unique slugs"
```

---

### Task 4: Operations — the reparent step

**Files:**
- Create: `includes/HierarchyGuard/Trash/Operations.php`
- Test: `tests/phpunit/OperationsReparentTest.php`

**Interfaces:**
- Consumes: `Subtree::live_children()`, `Mover::move()`, `Markers::mark_moved()`, `Ledger::record()`.
- Produces: `Trash\Operations` constants `TYPE_REPARENT`, `TYPE_TRASH`, `TYPE_RESTORE`, `DEFAULT_THRESHOLD`, `MAX_REMEMBERED`; `__construct( Ledger $ledger, Mover $mover )`; static `threshold(): int`; static `new_job( string $type, int $root_id, int $to_id, string $post_type, int $user_id ): array`; `step( array $job, int $budget ): array`; `run( array $job ): array`. Job keys: `id`, `type`, `root`, `to`, `post_type`, `user`, `cursor`, `phase`, `frontier`, `head`, `counts{moved,trashed,kept,renamed,restored}`, `ids`, `finished`.

- [ ] **Step 1: Write the failing tests**

`tests/phpunit/OperationsReparentTest.php`:
```php
<?php
/**
 * The reparent step.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Ledger;
use HierarchyGuard\Post_Types;
use HierarchyGuard\Settings;
use HierarchyGuard\Trash\Markers;
use HierarchyGuard\Trash\Mover;
use HierarchyGuard\Trash\Operations;
use WP_UnitTestCase;

/**
 * @covers \HierarchyGuard\Trash\Operations
 */
final class OperationsReparentTest extends WP_UnitTestCase {

	use Trash_Fixtures;

	/**
	 * Page IDs.
	 *
	 * @var array<string,int>
	 */
	private array $ids;

	private Ledger $ledger;

	private Operations $operations;

	public function set_up(): void {
		parent::set_up();
		delete_option( Settings::OPTION );
		delete_option( Ledger::ROOT_OPTION );
		Post_Types::reset();
		$this->set_permalink_structure( '/%postname%/' );
		$this->ledger     = new Ledger();
		$this->operations = new Operations( $this->ledger, new Mover() );
		$this->ids        = $this->make_tree();
		$this->trash_raw( $this->ids['about'] );
	}

	public function tear_down(): void {
		$this->set_permalink_structure( '' );
		parent::tear_down();
	}

	/**
	 * A reparent job moving about's children to company.
	 *
	 * @param int $user_id Triggering user.
	 * @return array<string,mixed>
	 */
	private function job( int $user_id = 0 ): array {
		return Operations::new_job( Operations::TYPE_REPARENT, $this->ids['about'], $this->ids['company'], 'page', $user_id );
	}

	public function test_moves_every_live_child_to_the_new_parent_and_marks_it(): void {
		$job = $this->operations->run( $this->job() );

		$this->assertTrue( $job['finished'] );
		foreach ( [ 'team', 'history' ] as $slug ) {
			$this->assertSame( $this->ids['company'], (int) get_post( $this->ids[ $slug ] )->post_parent, $slug );
			$this->assertSame( (string) $this->ids['about'], get_post_meta( $this->ids[ $slug ], Markers::MOVED_FROM, true ) );
			$this->assertSame( (string) $this->ids['company'], get_post_meta( $this->ids[ $slug ], Markers::MOVED_TO, true ) );
		}
		$this->assertSame( $this->ids['team'], (int) get_post( $this->ids['leadership'] )->post_parent, 'Grandchildren travel with their parent.' );
		$this->assertSame( 2, $job['counts']['moved'] );
		$this->assertSame( [ $this->ids['team'], $this->ids['history'] ], $job['ids'] );
	}

	public function test_a_renamed_child_keeps_its_old_url_on_record(): void {
		self::factory()->post->create( [ 'post_type' => 'page', 'post_name' => 'team', 'post_parent' => $this->ids['company'] ] );

		$job = $this->operations->run( $this->job() );

		$this->assertSame( 'team-2', get_post( $this->ids['team'] )->post_name );
		$this->assertSame( 'team', get_post_meta( $this->ids['team'], Markers::MOVED_SLUG, true ) );
		$this->assertSame( 1, $job['counts']['renamed'] );
		$this->assertSame( $this->ids['team'], $this->ledger->find( 'page', 'company/about/team' ) );
	}

	public function test_children_the_user_cannot_edit_stay_put(): void {
		$editor = self::factory()->user->create( [ 'role' => 'editor' ] );
		$locked = $this->ids['history'];
		add_filter(
			'map_meta_cap',
			static function ( array $caps, string $cap, int $user_id, array $args ) use ( $locked ): array {
				return 'edit_post' === $cap && isset( $args[0] ) && (int) $args[0] === $locked ? [ 'do_not_allow' ] : $caps;
			},
			10,
			4
		);

		$job = $this->operations->run( $this->job( $editor ) );

		$this->assertSame( $this->ids['company'], (int) get_post( $this->ids['team'] )->post_parent );
		$this->assertSame( $this->ids['about'], (int) get_post( $locked )->post_parent );
		$this->assertSame( 1, $job['counts']['kept'] );
		$this->assertFalse( metadata_exists( 'post', $locked, Markers::MOVED_FROM ) );
	}

	public function test_system_contexts_skip_capability_checks(): void {
		add_filter( 'map_meta_cap', static fn(): array => [ 'do_not_allow' ] );

		$job = $this->operations->run( $this->job( 0 ) );

		$this->assertSame( 2, $job['counts']['moved'] );
	}

	public function test_a_budget_of_one_moves_one_child_per_step(): void {
		$job = $this->operations->step( $this->job(), 1 );
		$this->assertFalse( $job['finished'] );
		$this->assertSame( 1, $job['counts']['moved'] );

		$job = $this->operations->step( $job, 1 );
		$job = $this->operations->step( $job, 1 );
		$this->assertTrue( $job['finished'] );
		$this->assertSame( 2, $job['counts']['moved'] );
	}

	public function test_stops_once_the_parent_leaves_the_trash(): void {
		$this->untrash_raw( $this->ids['about'] );

		$job = $this->operations->run( $this->job() );

		$this->assertTrue( $job['finished'] );
		$this->assertSame( 0, $job['counts']['moved'] );
		$this->assertSame( $this->ids['about'], (int) get_post( $this->ids['team'] )->post_parent );
	}

	public function test_reports_the_moved_children(): void {
		$seen = [];
		add_action(
			'hierarchy_guard_children_reparented',
			static function ( array $ids, int $from, int $to ) use ( &$seen ): void {
				$seen[] = [ $ids, $from, $to ];
			},
			10,
			3
		);

		$this->operations->run( $this->job() );

		$this->assertSame( [ [ [ $this->ids['team'], $this->ids['history'] ], $this->ids['about'], $this->ids['company'] ] ], $seen );
	}

	public function test_the_threshold_is_filterable_with_a_floor_of_one(): void {
		$this->assertSame( 200, Operations::threshold() );
		add_filter( 'hierarchy_guard_batch_threshold', static fn(): int => 0 );
		$this->assertSame( 1, Operations::threshold() );
	}
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npm run test:php -- --filter OperationsReparentTest`
Expected: `Error: Class "HierarchyGuard\Trash\Operations" not found`.

- [ ] **Step 3: Create Operations with the reparent step**

`includes/HierarchyGuard/Trash/Operations.php`:
```php
<?php
/**
 * The trash flow's units of work.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Trash;

use HierarchyGuard\Ledger;
use HierarchyGuard\Subtree;

/**
 * Moves, trashes and restores subpages in bounded steps. A job is a plain array,
 * so the same step runs inline for small subtrees and from the batch queue for
 * large ones.
 */
final class Operations {

	public const TYPE_REPARENT = 'reparent';
	public const TYPE_TRASH    = 'trash_children';
	public const TYPE_RESTORE  = 'restore';

	public const DEFAULT_THRESHOLD = 200;
	public const MAX_REMEMBERED    = 200;

	/**
	 * Ledger.
	 *
	 * @var Ledger
	 */
	private Ledger $ledger;

	/**
	 * Mover.
	 *
	 * @var Mover
	 */
	private Mover $mover;

	/**
	 * Constructor.
	 *
	 * @param Ledger $ledger Ledger.
	 * @param Mover  $mover  Mover.
	 */
	public function __construct( Ledger $ledger, Mover $mover ) {
		$this->ledger = $ledger;
		$this->mover  = $mover;
	}

	/**
	 * Subtrees up to this size are handled in one request; larger ones in batches of this size.
	 *
	 * @return int
	 */
	public static function threshold(): int {
		/**
		 * Filters how many subpages are handled in one request.
		 *
		 * @param int $threshold Subpages per batch, 200 by default.
		 */
		return max( 1, (int) apply_filters( 'hierarchy_guard_batch_threshold', self::DEFAULT_THRESHOLD ) );
	}

	/**
	 * A fresh job.
	 *
	 * @param string $type      One of the TYPE_ constants.
	 * @param int    $root_id   The page being trashed or restored.
	 * @param int    $to_id     Reparent only: the new parent, 0 for top level.
	 * @param string $post_type Post type of the subtree.
	 * @param int    $user_id   User whose capabilities apply, 0 for system contexts.
	 * @return array<string,mixed>
	 */
	public static function new_job( string $type, int $root_id, int $to_id, string $post_type, int $user_id ): array {
		return [
			'id'        => '',
			'type'      => $type,
			'root'      => $root_id,
			'to'        => $to_id,
			'post_type' => $post_type,
			'user'      => $user_id,
			'cursor'    => 0,
			'phase'     => 'moved',
			'frontier'  => [ $root_id ],
			'head'      => 0,
			'counts'    => [
				'moved'    => 0,
				'trashed'  => 0,
				'kept'     => 0,
				'renamed'  => 0,
				'restored' => 0,
			],
			'ids'       => [],
			'finished'  => false,
		];
	}

	/**
	 * Runs one bounded step of a job.
	 *
	 * @param array<string,mixed> $job    Job.
	 * @param int                 $budget Most subpages this step may touch.
	 * @return array<string,mixed> The updated job.
	 */
	public function step( array $job, int $budget ): array {
		$budget = max( 1, $budget );

		switch ( $job['type'] ?? '' ) {
			case self::TYPE_REPARENT:
				return $this->step_reparent( $job, $budget );
		}

		$job['finished'] = true;
		return $job;
	}

	/**
	 * Runs a job to completion in this request.
	 *
	 * @param array<string,mixed> $job Job.
	 * @return array<string,mixed> The finished job.
	 */
	public function run( array $job ): array {
		for ( $i = 0; $i < 100000 && empty( $job['finished'] ); $i++ ) {
			$job = $this->step( $job, self::threshold() );
		}
		return $job;
	}

	/**
	 * Moves the trashed parent's live children to the new parent.
	 *
	 * @param array<string,mixed> $job    Job.
	 * @param int                 $budget Most children to move.
	 * @return array<string,mixed>
	 */
	private function step_reparent( array $job, int $budget ): array {
		if ( 'trash' !== get_post_status( (int) $job['root'] ) ) {
			// The parent left the trash; the restore takes back what was already moved.
			$job['finished'] = true;
			return $job;
		}

		$children = Subtree::live_children( (int) $job['root'], (string) $job['post_type'], $budget, (int) $job['cursor'] );
		$moved    = [];

		foreach ( $children as $child_id ) {
			$job['cursor'] = $child_id;
			if ( ! $this->can( (int) $job['user'], 'edit_post', $child_id ) ) {
				++$job['counts']['kept'];
				continue;
			}

			$result = $this->mover->move( $child_id, (int) $job['to'] );
			if ( ! $result['moved'] ) {
				++$job['counts']['kept'];
				continue;
			}

			Markers::mark_moved( $child_id, (int) $job['root'], (int) $job['to'], $result['renamed'] ? $result['old_slug'] : '' );
			if ( $result['renamed'] ) {
				++$job['counts']['renamed'];
				if ( $this->recording() ) {
					// The alias on the new parent can't reach a renamed slug, so record it here.
					$this->ledger->record( $child_id, $result['old_path'] );
				}
			}
			++$job['counts']['moved'];
			$moved[] = $child_id;
		}

		if ( [] !== $moved ) {
			/**
			 * Fires after subpages were moved to a new parent with a direct update.
			 *
			 * @param int[] $ids  Moved post IDs.
			 * @param int   $from The trashed parent.
			 * @param int   $to   The new parent, 0 for top level.
			 */
			do_action( 'hierarchy_guard_children_reparented', $moved, (int) $job['root'], (int) $job['to'] );
		}

		$job             = $this->remember( $job, $moved );
		$job['finished'] = count( $children ) < $budget;
		return $job;
	}

	/**
	 * Whether the job's user may act on a post. User 0 is a system context and trusted.
	 *
	 * @param int    $user_id    User ID.
	 * @param string $capability Meta capability.
	 * @param int    $post_id    Post ID.
	 * @return bool
	 */
	private function can( int $user_id, string $capability, int $post_id ): bool {
		return $user_id <= 0 || user_can( $user_id, $capability, $post_id );
	}

	/**
	 * Whether URL changes are worth recording (pretty permalinks are on).
	 *
	 * @return bool
	 */
	private function recording(): bool {
		return '' !== (string) get_option( 'permalink_structure' );
	}

	/**
	 * Adds affected IDs to the job, up to MAX_REMEMBERED.
	 *
	 * @param array<string,mixed> $job Job.
	 * @param int[]               $ids Affected IDs.
	 * @return array<string,mixed>
	 */
	private function remember( array $job, array $ids ): array {
		$job['ids'] = array_slice( array_merge( (array) $job['ids'], $ids ), 0, self::MAX_REMEMBERED );
		return $job;
	}
}
```
- [ ] **Step 4: Run the tests to verify they pass**

Run: `npm run test:php -- --filter OperationsReparentTest`
Expected: `OK (8 tests, ...)`.

Run: `npm run test:php` and `composer lint`
Expected: all green; lint exit 0.

- [ ] **Step 5: Commit**

```bash
git add includes tests
git commit -m "feat: reparent step moves a trashed page's subpages up with capability checks"
```

---

### Task 5: Operations — the trash-subtree step

**Files:**
- Modify: `includes/HierarchyGuard/Trash/Operations.php`
- Test: `tests/phpunit/OperationsTrashTest.php`

**Interfaces:**
- Consumes: `Guard::run()`, `Markers::mark_trashed_with()`, `Markers::clear_trashed_with()`, `Subtree::live_children()`.
- Produces: `Operations::step()` handles `TYPE_TRASH`: breadth-first over `frontier` (starting `[ root ]`, `head` index), trashing each live descendant inside `Guard::run()` with `TRASHED_WITH = root`. Each child trashed and each empty expansion spends one unit of budget.

- [ ] **Step 1: Write the failing tests**

`tests/phpunit/OperationsTrashTest.php`:
```php
<?php
/**
 * The trash-subtree step.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Ledger;
use HierarchyGuard\Trash\Guard;
use HierarchyGuard\Trash\Markers;
use HierarchyGuard\Trash\Mover;
use HierarchyGuard\Trash\Operations;
use WP_UnitTestCase;

/**
 * @covers \HierarchyGuard\Trash\Operations
 */
final class OperationsTrashTest extends WP_UnitTestCase {

	use Trash_Fixtures;

	/**
	 * Page IDs.
	 *
	 * @var array<string,int>
	 */
	private array $ids;

	private Operations $operations;

	public function set_up(): void {
		parent::set_up();
		$this->operations  = new Operations( new Ledger(), new Mover() );
		$this->ids         = $this->make_tree();
		$this->ids['plans'] = self::factory()->post->create( [ 'post_type' => 'page', 'post_status' => 'draft', 'post_name' => 'plans', 'post_parent' => $this->ids['team'] ] );
		$this->trash_raw( $this->ids['about'] );
	}

	/**
	 * A job trashing about's subtree.
	 *
	 * @param int $user_id Triggering user.
	 * @return array<string,mixed>
	 */
	private function job( int $user_id = 0 ): array {
		return Operations::new_job( Operations::TYPE_TRASH, $this->ids['about'], 0, 'page', $user_id );
	}

	public function test_trashes_every_live_descendant_and_marks_it(): void {
		$job = $this->operations->run( $this->job() );

		$this->assertTrue( $job['finished'] );
		foreach ( [ 'team', 'history', 'leadership', 'plans' ] as $slug ) {
			$this->assertSame( 'trash', get_post_status( $this->ids[ $slug ] ), $slug );
			$this->assertSame( (string) $this->ids['about'], get_post_meta( $this->ids[ $slug ], Markers::TRASHED_WITH, true ), $slug );
		}
		$this->assertSame( 4, $job['counts']['trashed'] );
		$this->assertSame( [], $job['frontier'] );
	}

	public function test_holds_the_guard_while_trashing(): void {
		$held = [];
		add_action(
			'wp_trash_post',
			static function () use ( &$held ): void {
				$held[] = Guard::active();
			}
		);

		$this->operations->run( $this->job() );

		$this->assertSame( [ true, true, true, true ], $held );
	}

	public function test_a_descendant_the_user_cannot_delete_keeps_its_own_subtree(): void {
		$editor = self::factory()->user->create( [ 'role' => 'editor' ] );
		$locked = $this->ids['team'];
		add_filter(
			'map_meta_cap',
			static function ( array $caps, string $cap, int $user_id, array $args ) use ( $locked ): array {
				return 'delete_post' === $cap && isset( $args[0] ) && (int) $args[0] === $locked ? [ 'do_not_allow' ] : $caps;
			},
			10,
			4
		);

		$job = $this->operations->run( $this->job( $editor ) );

		$this->assertSame( 'publish', get_post_status( $locked ) );
		$this->assertSame( 'publish', get_post_status( $this->ids['leadership'] ), 'Not expanded past a kept page.' );
		$this->assertSame( 'trash', get_post_status( $this->ids['history'] ) );
		$this->assertSame( 1, $job['counts']['kept'] );
	}

	public function test_a_budget_of_one_spreads_the_work_across_steps(): void {
		$job   = $this->job();
		$steps = 0;
		while ( ! $job['finished'] && $steps < 50 ) {
			$job = $this->operations->step( $job, 1 );
			++$steps;
		}

		$this->assertTrue( $job['finished'] );
		$this->assertGreaterThan( 4, $steps, 'Each trash and each empty expansion spends budget.' );
		$this->assertSame( 4, $job['counts']['trashed'] );
	}

	public function test_stops_once_the_parent_leaves_the_trash(): void {
		$this->untrash_raw( $this->ids['about'] );

		$job = $this->operations->run( $this->job() );

		$this->assertTrue( $job['finished'] );
		$this->assertSame( 'publish', get_post_status( $this->ids['team'] ) );
	}
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npm run test:php -- --filter OperationsTrashTest`
Expected: failures — `step()` finishes `TYPE_TRASH` jobs immediately, so nothing is trashed.

- [ ] **Step 3: Add the trash-subtree step**

In `includes/HierarchyGuard/Trash/Operations.php`, add `use WP_Post;` after `use HierarchyGuard\Subtree;`, add the case to `step()`:
```php
			case self::TYPE_TRASH:
				return $this->step_trash_children( $job, $budget );
```
and add the method after `step_reparent()`:
```php
	/**
	 * Trashes the trashed root's live descendants, breadth first.
	 *
	 * @param array<string,mixed> $job    Job.
	 * @param int                 $budget Most trashes plus empty expansions in this step.
	 * @return array<string,mixed>
	 */
	private function step_trash_children( array $job, int $budget ): array {
		if ( 'trash' !== get_post_status( (int) $job['root'] ) ) {
			$job['finished'] = true;
			return $job;
		}

		$trashed = [];
		while ( $budget > 0 && (int) $job['head'] < count( $job['frontier'] ) ) {
			$parent_id = (int) $job['frontier'][ (int) $job['head'] ];
			$kids      = Subtree::live_children( $parent_id, (string) $job['post_type'], $budget, (int) $job['cursor'] );

			if ( [] === $kids ) {
				++$job['head'];
				$job['cursor'] = 0;
				--$budget;
				continue;
			}

			foreach ( $kids as $kid ) {
				$job['cursor'] = $kid;
				--$budget;

				if ( ! $this->can( (int) $job['user'], 'delete_post', $kid ) ) {
					++$job['counts']['kept'];
					continue;
				}

				Markers::mark_trashed_with( $kid, (int) $job['root'] );
				$result = Guard::run( static fn() => wp_trash_post( $kid ) );
				if ( ! $result instanceof WP_Post ) {
					Markers::clear_trashed_with( $kid );
					++$job['counts']['kept'];
					continue;
				}

				$job['frontier'][] = $kid;
				++$job['counts']['trashed'];
				$trashed[] = $kid;
			}
		}

		// Drop the parents already expanded so a stored job stays small.
		$job['frontier'] = array_values( array_slice( $job['frontier'], (int) $job['head'] ) );
		$job['head']     = 0;
		$job['finished'] = [] === $job['frontier'];

		return $this->remember( $job, $trashed );
	}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `npm run test:php -- --filter 'OperationsTrashTest|OperationsReparentTest'`
Expected: `OK (13 tests, ...)`.

Run: `npm run test:php` and `composer lint`
Expected: all green; lint exit 0.

- [ ] **Step 5: Commit**

```bash
git add includes tests
git commit -m "feat: trash-subtree step trashes descendants breadth first under the guard"
```

---

### Task 6: Operations — the restore step

**Files:**
- Modify: `includes/HierarchyGuard/Trash/Operations.php`
- Test: `tests/phpunit/OperationsRestoreTest.php`

**Interfaces:**
- Consumes: `Subtree::marked()`, `Mover::move()` with a preferred slug, `Ledger::record()`, `Ledger::prune()`, `Markers::clear_moved()`, `Markers::clear_trashed_with()`, `Guard::run()`.
- Produces: `Operations::step()` handles `TYPE_RESTORE` in two phases: `moved` (move back subpages still under `MOVED_TO`, original slug when free, record the interim path, prune) then `trashed` (untrash `TRASHED_WITH` subpages with their previous status). Stops when the root is in the trash again.

- [ ] **Step 1: Write the failing tests**

`tests/phpunit/OperationsRestoreTest.php`:
```php
<?php
/**
 * The restore step.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Ledger;
use HierarchyGuard\Post_Types;
use HierarchyGuard\Settings;
use HierarchyGuard\Trash\Guard;
use HierarchyGuard\Trash\Markers;
use HierarchyGuard\Trash\Mover;
use HierarchyGuard\Trash\Operations;
use WP_UnitTestCase;

/**
 * @covers \HierarchyGuard\Trash\Operations
 */
final class OperationsRestoreTest extends WP_UnitTestCase {

	use Trash_Fixtures;

	/**
	 * Page IDs.
	 *
	 * @var array<string,int>
	 */
	private array $ids;

	private Ledger $ledger;

	private Operations $operations;

	public function set_up(): void {
		parent::set_up();
		delete_option( Settings::OPTION );
		delete_option( Ledger::ROOT_OPTION );
		Post_Types::reset();
		$this->set_permalink_structure( '/%postname%/' );
		$this->ledger     = new Ledger();
		$this->operations = new Operations( $this->ledger, new Mover() );
		$this->ids        = $this->make_tree();
		$this->trash_raw( $this->ids['about'] );
	}

	public function tear_down(): void {
		$this->set_permalink_structure( '' );
		parent::tear_down();
	}

	/**
	 * Runs the reparent job for about.
	 */
	private function move_out(): void {
		$this->operations->run( Operations::new_job( Operations::TYPE_REPARENT, $this->ids['about'], $this->ids['company'], 'page', 0 ) );
	}

	/**
	 * Runs the restore job for about.
	 *
	 * @return array<string,mixed>
	 */
	private function restore(): array {
		return $this->operations->run( Operations::new_job( Operations::TYPE_RESTORE, $this->ids['about'], 0, 'page', 0 ) );
	}

	public function test_moved_children_go_back_under_the_restored_parent(): void {
		$this->move_out();
		$this->untrash_raw( $this->ids['about'] );

		$job = $this->restore();

		$this->assertTrue( $job['finished'] );
		foreach ( [ 'team', 'history' ] as $slug ) {
			$this->assertSame( $this->ids['about'], (int) get_post( $this->ids[ $slug ] )->post_parent, $slug );
			$this->assertFalse( metadata_exists( 'post', $this->ids[ $slug ], Markers::MOVED_FROM ), $slug );
		}
		$this->assertSame( 2, $job['counts']['restored'] );
		$this->assertSame( $this->ids['team'], $this->ledger->find( 'page', 'company/team' ), 'Links made while the parent was in the trash keep working.' );
	}

	public function test_a_renamed_child_gets_its_original_slug_back(): void {
		self::factory()->post->create( [ 'post_type' => 'page', 'post_name' => 'team', 'post_parent' => $this->ids['company'] ] );
		$this->move_out();
		$this->assertSame( 'team-2', get_post( $this->ids['team'] )->post_name, 'Precondition.' );
		$this->untrash_raw( $this->ids['about'] );

		$this->restore();

		$this->assertSame( 'team', get_post( $this->ids['team'] )->post_name );
		$this->assertNull( $this->ledger->find( 'page', 'company/about/team' ), 'The entry equal to the current path is pruned.' );
		$this->assertSame( $this->ids['team'], $this->ledger->find( 'page', 'company/team-2' ) );
	}

	public function test_a_child_moved_elsewhere_is_left_alone(): void {
		$this->move_out();
		wp_update_post( [ 'ID' => $this->ids['team'], 'post_parent' => $this->ids['history'] ] );
		$this->untrash_raw( $this->ids['about'] );

		$job = $this->restore();

		$this->assertSame( $this->ids['history'], (int) get_post( $this->ids['team'] )->post_parent );
		$this->assertFalse( metadata_exists( 'post', $this->ids['team'], Markers::MOVED_FROM ), 'Markers are cleared either way.' );
		$this->assertSame( 1, $job['counts']['restored'], 'Only history moves back.' );
	}

	public function test_co_trashed_subpages_return_with_their_previous_status(): void {
		$plans = self::factory()->post->create( [ 'post_type' => 'page', 'post_status' => 'draft', 'post_name' => 'plans', 'post_parent' => $this->ids['team'] ] );
		$this->operations->run( Operations::new_job( Operations::TYPE_TRASH, $this->ids['about'], 0, 'page', 0 ) );
		$this->untrash_raw( $this->ids['about'] );

		$job = $this->restore();

		$this->assertSame( 'publish', get_post_status( $this->ids['team'] ) );
		$this->assertSame( 'publish', get_post_status( $this->ids['leadership'] ) );
		$this->assertSame( 'draft', get_post_status( $plans ) );
		$this->assertFalse( metadata_exists( 'post', $this->ids['team'], Markers::TRASHED_WITH ) );
		$this->assertSame( 4, $job['counts']['restored'] );
	}

	public function test_holds_the_guard_while_untrashing(): void {
		$this->operations->run( Operations::new_job( Operations::TYPE_TRASH, $this->ids['about'], 0, 'page', 0 ) );
		$this->untrash_raw( $this->ids['about'] );
		$held = [];
		add_action(
			'untrash_post',
			static function () use ( &$held ): void {
				$held[] = Guard::active();
			}
		);

		$this->restore();

		$this->assertNotEmpty( $held );
		$this->assertNotContains( false, $held );
	}

	public function test_stops_while_the_parent_is_in_the_trash(): void {
		$this->move_out();

		$job = $this->restore();

		$this->assertTrue( $job['finished'] );
		$this->assertSame( 0, $job['counts']['restored'] );
		$this->assertSame( $this->ids['company'], (int) get_post( $this->ids['team'] )->post_parent );
	}
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npm run test:php -- --filter OperationsRestoreTest`
Expected: failures — `TYPE_RESTORE` jobs finish immediately without restoring.

- [ ] **Step 3: Add the restore step**

In `includes/HierarchyGuard/Trash/Operations.php`, add the case to `step()`:
```php
			case self::TYPE_RESTORE:
				return $this->step_restore( $job, $budget );
```
and add these methods after `step_trash_children()`:
```php
	/**
	 * Takes back the plugin's moves and trashes for a restored root.
	 *
	 * @param array<string,mixed> $job    Job.
	 * @param int                 $budget Most subpages to restore in this step.
	 * @return array<string,mixed>
	 */
	private function step_restore( array $job, int $budget ): array {
		if ( 'trash' === get_post_status( (int) $job['root'] ) ) {
			$job['finished'] = true;
			return $job;
		}

		if ( 'moved' === $job['phase'] ) {
			$ids      = Subtree::marked( Markers::MOVED_FROM, (int) $job['root'], (string) $job['post_type'], $budget, (int) $job['cursor'] );
			$restored = [];
			foreach ( $ids as $id ) {
				$job['cursor'] = $id;
				if ( $this->still_where_moved( $id ) ) {
					$result = $this->mover->move( $id, (int) $job['root'], (string) get_post_meta( $id, Markers::MOVED_SLUG, true ) );
					if ( $result['moved'] ) {
						if ( $this->recording() && '' !== $result['old_path'] && $result['old_path'] !== $result['new_path'] ) {
							$this->ledger->record( $id, $result['old_path'] );
						}
						$this->ledger->prune( $id );
						++$job['counts']['restored'];
						$restored[] = $id;
					}
				}
				Markers::clear_moved( $id );
			}
			if ( count( $ids ) < $budget ) {
				$job['phase']  = 'trashed';
				$job['cursor'] = 0;
			}
			return $this->remember( $job, $restored );
		}

		$ids      = Subtree::marked( Markers::TRASHED_WITH, (int) $job['root'], (string) $job['post_type'], $budget, (int) $job['cursor'] );
		$restored = [];
		foreach ( $ids as $id ) {
			$job['cursor'] = $id;
			if ( 'trash' === get_post_status( $id ) && $this->untrash_with_previous_status( $id ) ) {
				++$job['counts']['restored'];
				$restored[] = $id;
			}
			Markers::clear_trashed_with( $id );
		}
		$job['finished'] = count( $ids ) < $budget;

		return $this->remember( $job, $restored );
	}

	/**
	 * Whether a moved subpage still sits where the plugin put it.
	 *
	 * @param int $post_id Subpage.
	 * @return bool
	 */
	private function still_where_moved( int $post_id ): bool {
		$post = get_post( $post_id );
		return $post instanceof WP_Post
			&& 'trash' !== $post->post_status
			&& (int) $post->post_parent === (int) get_post_meta( $post_id, Markers::MOVED_TO, true );
	}

	/**
	 * Untrashes a post with the status it had before, under the guard.
	 *
	 * @param int $post_id Post ID.
	 * @return bool Whether it left the trash.
	 */
	private function untrash_with_previous_status( int $post_id ): bool {
		return (bool) Guard::run(
			static function () use ( $post_id ): bool {
				add_filter( 'wp_untrash_post_status', 'wp_untrash_post_set_previous_status', 10, 3 );
				$result = wp_untrash_post( $post_id );
				remove_filter( 'wp_untrash_post_status', 'wp_untrash_post_set_previous_status', 10 );
				return $result instanceof WP_Post;
			}
		);
	}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `npm run test:php -- --filter 'Operations'`
Expected: `OK (19 tests, ...)`.

Run: `npm run test:php` and `composer lint`
Expected: all green; lint exit 0.

- [ ] **Step 5: Commit**

```bash
git add includes tests
git commit -m "feat: restore step brings subpages back with their slugs and statuses"
```

---

### Task 7: Batch queue, processor and WP-CLI command

**Files:**
- Create: `includes/HierarchyGuard/Batch/Queue.php`, `includes/HierarchyGuard/Batch/Processor.php`, `includes/HierarchyGuard/Batch/CLI_Command.php`
- Modify: `includes/HierarchyGuard/Plugin.php`
- Test: `tests/phpunit/QueueTest.php`, `tests/phpunit/ProcessorTest.php`

**Interfaces:**
- Consumes: `Operations::step()`, `Operations::threshold()`, `Operations::new_job()`.
- Produces: `Batch\Queue` constants `HOOK`, `INDEX`, `PREFIX`, `GROUP`; `save( array $job ): array` (assigns a UUID when `id` is empty); `load( string $id ): ?array`; `delete( string $id ): void`; `pending(): string[]`; `pending_for( int $root_id ): bool`; `schedule( string $id ): void`.
- Produces: `Batch\Processor::__construct( Queue $queue, Operations $operations )`; `register(): void`; `run( mixed $job_id, bool $reschedule = true ): bool` (true when finished or unknown); `drain( string $job_id ): void`. Fires `hierarchy_guard_job_finished( array $job )`.
- Produces: `Batch\CLI_Command::process_jobs()` as `wp hierarchy-guard process-jobs`.

- [ ] **Step 1: Write the failing tests**

`tests/phpunit/QueueTest.php`:
```php
<?php
/**
 * Job storage and scheduling.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Batch\Queue;
use HierarchyGuard\Trash\Operations;
use WP_UnitTestCase;

/**
 * @covers \HierarchyGuard\Batch\Queue
 */
final class QueueTest extends WP_UnitTestCase {

	private Queue $queue;

	public function set_up(): void {
		parent::set_up();
		delete_option( Queue::INDEX );
		$this->queue = new Queue();
	}

	public function tear_down(): void {
		wp_clear_scheduled_hook( Queue::HOOK, [ 'abc' ] );
		parent::tear_down();
	}

	public function test_save_assigns_an_id_and_stores_the_job_unautoloaded(): void {
		global $wpdb;

		$job = $this->queue->save( Operations::new_job( Operations::TYPE_REPARENT, 10, 2, 'page', 0 ) );

		$this->assertMatchesRegularExpression( '/^[0-9a-f-]{36}$/', $job['id'] );
		$this->assertSame( $job, $this->queue->load( $job['id'] ) );
		foreach ( [ Queue::PREFIX . $job['id'], Queue::INDEX ] as $name ) {
			$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $name ) );
			$this->assertContains( $autoload, [ 'no', 'off' ], $name );
		}
	}

	public function test_pending_lists_jobs_and_delete_removes_them(): void {
		$first  = $this->queue->save( Operations::new_job( Operations::TYPE_REPARENT, 10, 2, 'page', 0 ) );
		$second = $this->queue->save( Operations::new_job( Operations::TYPE_TRASH, 11, 0, 'page', 0 ) );
		$this->assertSame( [ $first['id'], $second['id'] ], $this->queue->pending() );

		$this->queue->delete( $first['id'] );

		$this->assertSame( [ $second['id'] ], $this->queue->pending() );
		$this->assertNull( $this->queue->load( $first['id'] ) );
	}

	public function test_saving_an_existing_job_does_not_duplicate_it(): void {
		$job = $this->queue->save( Operations::new_job( Operations::TYPE_REPARENT, 10, 2, 'page', 0 ) );
		$this->queue->save( $job );
		$this->assertCount( 1, $this->queue->pending() );
	}

	public function test_pending_for_matches_the_root(): void {
		$this->queue->save( Operations::new_job( Operations::TYPE_REPARENT, 10, 2, 'page', 0 ) );
		$this->assertTrue( $this->queue->pending_for( 10 ) );
		$this->assertFalse( $this->queue->pending_for( 11 ) );
	}

	public function test_schedule_uses_wp_cron_without_action_scheduler(): void {
		$this->assertFalse( function_exists( 'as_enqueue_async_action' ), 'Precondition: Action Scheduler is not loaded in the test site.' );
		$this->queue->schedule( 'abc' );
		$this->assertNotFalse( wp_next_scheduled( Queue::HOOK, [ 'abc' ] ) );
	}
}
```

`tests/phpunit/ProcessorTest.php`:
```php
<?php
/**
 * Background batches.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Batch\Processor;
use HierarchyGuard\Batch\Queue;
use HierarchyGuard\Ledger;
use HierarchyGuard\Subtree;
use HierarchyGuard\Trash\Mover;
use HierarchyGuard\Trash\Operations;
use WP_UnitTestCase;

/**
 * @covers \HierarchyGuard\Batch\Processor
 */
final class ProcessorTest extends WP_UnitTestCase {

	use Trash_Fixtures;

	/**
	 * Page IDs.
	 *
	 * @var array<string,int>
	 */
	private array $ids;

	private Queue $queue;

	private Processor $processor;

	/**
	 * The queued job's ID.
	 *
	 * @var string
	 */
	private string $job_id;

	public function set_up(): void {
		parent::set_up();
		delete_option( Queue::INDEX );
		add_filter( 'hierarchy_guard_batch_threshold', static fn(): int => 2 );
		$this->queue     = new Queue();
		$this->processor = new Processor( $this->queue, new Operations( new Ledger(), new Mover() ) );
		$this->ids       = $this->make_tree();
		foreach ( [ 'a', 'b', 'c' ] as $slug ) {
			self::factory()->post->create( [ 'post_type' => 'page', 'post_name' => $slug, 'post_parent' => $this->ids['about'] ] );
		}
		$this->trash_raw( $this->ids['about'] );
		$this->job_id = $this->queue->save( Operations::new_job( Operations::TYPE_REPARENT, $this->ids['about'], $this->ids['company'], 'page', 0 ) )['id'];
	}

	public function tear_down(): void {
		wp_clear_scheduled_hook( Queue::HOOK, [ $this->job_id ] );
		parent::tear_down();
	}

	public function test_the_processor_is_registered_by_the_plugin(): void {
		global $wp_filter;
		$found = false;
		foreach ( $wp_filter[ Queue::HOOK ]->callbacks[10] ?? [] as $callback ) {
			if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof Processor ) {
				$found = true;
			}
		}
		$this->assertTrue( $found );
	}

	public function test_runs_one_batch_and_reschedules(): void {
		$this->assertFalse( $this->processor->run( $this->job_id ) );

		$this->assertSame( 2, $this->queue->load( $this->job_id )['counts']['moved'] );
		$this->assertNotFalse( wp_next_scheduled( Queue::HOOK, [ $this->job_id ] ) );
	}

	public function test_finishes_and_removes_the_job(): void {
		$finished = [];
		add_action(
			'hierarchy_guard_job_finished',
			static function ( array $job ) use ( &$finished ): void {
				$finished[] = $job;
			}
		);

		$runs = 0;
		while ( ! $this->processor->run( $this->job_id, false ) && $runs < 10 ) {
			++$runs;
		}

		$this->assertSame( [], $this->queue->pending() );
		$this->assertCount( 1, $finished );
		$this->assertSame( 5, $finished[0]['counts']['moved'] );
		$this->assertSame( 0, Subtree::count_live_children( $this->ids['about'], 'page' ) );
	}

	public function test_drain_runs_to_completion_without_scheduling(): void {
		$this->processor->drain( $this->job_id );

		$this->assertSame( [], $this->queue->pending() );
		$this->assertFalse( wp_next_scheduled( Queue::HOOK, [ $this->job_id ] ) );
	}

	public function test_unknown_jobs_count_as_finished(): void {
		$this->assertTrue( $this->processor->run( 'missing' ) );
	}
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npm run test:php -- --filter 'QueueTest|ProcessorTest'`
Expected: `Error: Class "HierarchyGuard\Batch\Queue" not found`.

- [ ] **Step 3: Create Queue, Processor and CLI_Command**

`includes/HierarchyGuard/Batch/Queue.php`:
```php
<?php
/**
 * Background job storage and scheduling.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Batch;

/**
 * Jobs live in non-autoloaded options, indexed by one more, and run through
 * Action Scheduler when a site has it, else WP-Cron.
 */
final class Queue {

	public const HOOK   = 'hierarchy_guard_process_batch';
	public const INDEX  = 'hierarchy_guard_jobs';
	public const PREFIX = 'hierarchy_guard_job_';
	public const GROUP  = 'hierarchy-guard';

	/**
	 * Stores a job, giving it an ID when it has none.
	 *
	 * @param array<string,mixed> $job Job.
	 * @return array<string,mixed> The job with its ID.
	 */
	public function save( array $job ): array {
		if ( '' === (string) ( $job['id'] ?? '' ) ) {
			$job['id'] = wp_generate_uuid4();
		}
		update_option( self::PREFIX . $job['id'], $job, false );

		$index = $this->pending();
		if ( ! in_array( (string) $job['id'], $index, true ) ) {
			$index[] = (string) $job['id'];
			update_option( self::INDEX, $index, false );
		}

		return $job;
	}

	/**
	 * A stored job.
	 *
	 * @param string $id Job ID.
	 * @return array<string,mixed>|null
	 */
	public function load( string $id ): ?array {
		$job = get_option( self::PREFIX . $id, null );
		return is_array( $job ) ? $job : null;
	}

	/**
	 * Removes a job.
	 *
	 * @param string $id Job ID.
	 */
	public function delete( string $id ): void {
		delete_option( self::PREFIX . $id );
		update_option( self::INDEX, array_values( array_diff( $this->pending(), [ $id ] ) ), false );
	}

	/**
	 * IDs of stored jobs, oldest first.
	 *
	 * @return string[]
	 */
	public function pending(): array {
		$index = get_option( self::INDEX, [] );
		return is_array( $index ) ? array_values( array_map( 'strval', $index ) ) : [];
	}

	/**
	 * Whether a job for a root page is still running.
	 *
	 * @param int $root_id Root page ID.
	 * @return bool
	 */
	public function pending_for( int $root_id ): bool {
		foreach ( $this->pending() as $id ) {
			$job = $this->load( $id );
			if ( null !== $job && (int) ( $job['root'] ?? 0 ) === $root_id ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Schedules the next batch of a job.
	 *
	 * @param string $id Job ID.
	 */
	public function schedule( string $id ): void {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::HOOK, [ $id ], self::GROUP );
			return;
		}
		wp_schedule_single_event( time(), self::HOOK, [ $id ] );
	}
}
```

`includes/HierarchyGuard/Batch/Processor.php`:
```php
<?php
/**
 * Runs queued jobs one batch at a time.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Batch;

use HierarchyGuard\Trash\Operations;

/**
 * Each run does one threshold of work, then reschedules the job or, once it is
 * done, removes it.
 */
final class Processor {

	/**
	 * Queue.
	 *
	 * @var Queue
	 */
	private Queue $queue;

	/**
	 * Operations.
	 *
	 * @var Operations
	 */
	private Operations $operations;

	/**
	 * Constructor.
	 *
	 * @param Queue      $queue      Queue.
	 * @param Operations $operations Operations.
	 */
	public function __construct( Queue $queue, Operations $operations ) {
		$this->queue      = $queue;
		$this->operations = $operations;
	}

	/**
	 * Hooks into the scheduler.
	 */
	public function register(): void {
		add_action( Queue::HOOK, [ $this, 'run' ] );
	}

	/**
	 * Runs one batch of a queued job.
	 *
	 * @param mixed $job_id     Job ID, as the scheduler passes it.
	 * @param bool  $reschedule Whether to schedule the next batch.
	 * @return bool True when the job is finished or unknown.
	 */
	public function run( $job_id, bool $reschedule = true ): bool {
		$id  = (string) $job_id;
		$job = $this->queue->load( $id );
		if ( null === $job ) {
			return true;
		}

		$job = $this->operations->step( $job, Operations::threshold() );

		if ( ! empty( $job['finished'] ) ) {
			$this->queue->delete( $id );
			/**
			 * Fires when a background batch job completes.
			 *
			 * @param array<string,mixed> $job The finished job.
			 */
			do_action( 'hierarchy_guard_job_finished', $job );
			return true;
		}

		$this->queue->save( $job );
		if ( $reschedule ) {
			$this->queue->schedule( $id );
		}
		return false;
	}

	/**
	 * Runs a queued job to completion in this process, without scheduling.
	 *
	 * @param string $job_id Job ID.
	 */
	public function drain( string $job_id ): void {
		for ( $i = 0; $i < 100000; $i++ ) {
			if ( $this->run( $job_id, false ) ) {
				return;
			}
		}
	}
}
```

`includes/HierarchyGuard/Batch/CLI_Command.php`:
```php
<?php
/**
 * WP-CLI access to the batch queue.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Batch;

/**
 * Manages Hierarchy Guard background jobs.
 */
final class CLI_Command {

	/**
	 * Queue.
	 *
	 * @var Queue
	 */
	private Queue $queue;

	/**
	 * Processor.
	 *
	 * @var Processor
	 */
	private Processor $processor;

	/**
	 * Constructor.
	 *
	 * @param Queue     $queue     Queue.
	 * @param Processor $processor Processor.
	 */
	public function __construct( Queue $queue, Processor $processor ) {
		$this->queue     = $queue;
		$this->processor = $processor;
	}

	/**
	 * Runs every queued job to completion.
	 *
	 * Use this where WP-Cron is disabled or only runs on traffic.
	 *
	 * ## EXAMPLES
	 *
	 *     wp hierarchy-guard process-jobs
	 *
	 * @subcommand process-jobs
	 */
	public function process_jobs(): void {
		$ids = $this->queue->pending();
		if ( [] === $ids ) {
			\WP_CLI::success( 'No queued jobs.' );
			return;
		}
		foreach ( $ids as $id ) {
			$this->processor->drain( $id );
			\WP_CLI::log( sprintf( 'Finished job %s.', $id ) );
		}
		\WP_CLI::success( sprintf( 'Processed %d job(s).', count( $ids ) ) );
	}
}
```

- [ ] **Step 4: Wire the queue and processor in Plugin::register()**

In `includes/HierarchyGuard/Plugin.php`, add after the `Trash\Markers` line:
```php
		$queue      = new Batch\Queue();
		$operations = new Trash\Operations( $ledger, new Trash\Mover() );
		$processor  = new Batch\Processor( $queue, $operations );
		$processor->register();
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'hierarchy-guard', new Batch\CLI_Command( $queue, $processor ) );
		}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `npm run test:php -- --filter 'QueueTest|ProcessorTest'`
Expected: `OK (10 tests, ...)`.

Run: `npm run test:php` and `composer lint`
Expected: all green; lint exit 0.

- [ ] **Step 6: Commit**

```bash
git add includes tests
git commit -m "feat: background job queue, batch processor and wp hierarchy-guard process-jobs"
```

---

### Task 8: Trash handler

**Files:**
- Create: `includes/HierarchyGuard/Trash/Trash_Handler.php`
- Modify: `includes/HierarchyGuard/Plugin.php`, `tests/phpunit/ChangeListenerTest.php`, `tests/phpunit/ResolverTest.php`
- Test: `tests/phpunit/TrashHandlerTest.php`

**Interfaces:**
- Consumes: everything above.
- Produces: `Trash\Trash_Handler::__construct( Ledger $ledger, Operations $operations, Queue $queue, Change_Log $log )`; `register(): void` (hooks `wp_trash_post`, `trashed_post`, `transition_post_status`); `before_trash( mixed $post_id ): void`; `after_trash( mixed $post_id ): void`; `on_transition( mixed $new_status, mixed $old_status, mixed $post ): void`; `undo( int $root_id ): bool`. Filter `hierarchy_guard_trash_policy( string $policy, WP_Post $post )`.

- [ ] **Step 1: Pin the engine's trash tests to the keep policy**

`ChangeListenerTest` and `ResolverTest` exercise core's own trash behavior (subpages stay under the trashed parent), which is what the `keep` policy preserves. Once the handler is live, the default `reparent` policy would move those subpages. In both classes' `set_up()`, after the existing setup lines, add:
```php
		// These tests exercise core's trash behaviour, which the keep policy leaves untouched.
		add_filter( 'hierarchy_guard_trash_policy', static fn(): string => Settings::POLICY_KEEP );
```
(`WP_UnitTestCase` restores hooks after each test, so the filter never leaks.)

- [ ] **Step 2: Write the failing tests**

`tests/phpunit/TrashHandlerTest.php`:
```php
<?php
/**
 * The trash flow end to end through core's APIs.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Batch\Processor;
use HierarchyGuard\Batch\Queue;
use HierarchyGuard\Ledger;
use HierarchyGuard\Post_Types;
use HierarchyGuard\Redirects\Resolver;
use HierarchyGuard\Settings;
use HierarchyGuard\Subtree;
use HierarchyGuard\Trash\Change_Log;
use HierarchyGuard\Trash\Markers;
use HierarchyGuard\Trash\Mover;
use HierarchyGuard\Trash\Operations;
use HierarchyGuard\Trash\Trash_Handler;
use WP_UnitTestCase;

/**
 * @covers \HierarchyGuard\Trash\Trash_Handler
 */
final class TrashHandlerTest extends WP_UnitTestCase {

	use Trash_Fixtures;

	/**
	 * Page IDs.
	 *
	 * @var array<string,int>
	 */
	private array $ids;

	private int $admin;

	private Ledger $ledger;

	private Resolver $resolver;

	public function set_up(): void {
		parent::set_up();
		delete_option( Settings::OPTION );
		delete_option( Ledger::ROOT_OPTION );
		delete_option( Queue::INDEX );
		Post_Types::reset();
		$this->set_permalink_structure( '/%postname%/' );
		$this->admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $this->admin );
		$this->ledger   = new Ledger();
		$this->resolver = new Resolver( $this->ledger );
		$this->ids      = $this->make_tree();
	}

	public function tear_down(): void {
		foreach ( ( new Queue() )->pending() as $id ) {
			wp_clear_scheduled_hook( Queue::HOOK, [ $id ] );
		}
		unregister_post_type( 'hg_private' );
		$this->set_permalink_structure( '' );
		parent::tear_down();
	}

	/**
	 * The handler the plugin registered.
	 */
	private function handler(): Trash_Handler {
		global $wp_filter;
		foreach ( $wp_filter['trashed_post']->callbacks[10] ?? [] as $callback ) {
			if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof Trash_Handler ) {
				return $callback['function'][0];
			}
		}
		$this->fail( 'No Trash_Handler registered on trashed_post.' );
	}

	/**
	 * Uses a fixed policy for the rest of the test.
	 *
	 * @param string $policy Policy.
	 */
	private function policy( string $policy ): void {
		add_filter( 'hierarchy_guard_trash_policy', static fn(): string => $policy );
	}

	public function test_the_handler_is_registered_by_the_plugin(): void {
		global $wp_filter;
		$this->assertInstanceOf( Trash_Handler::class, $this->handler() );
		foreach ( [ 'wp_trash_post', 'transition_post_status' ] as $hook ) {
			$found = false;
			foreach ( $wp_filter[ $hook ]->callbacks[10] ?? [] as $callback ) {
				if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof Trash_Handler ) {
					$found = true;
				}
			}
			$this->assertTrue( $found, $hook );
		}
	}

	public function test_trashing_a_parent_moves_its_subpages_up_and_keeps_their_urls(): void {
		wp_trash_post( $this->ids['about'] );

		$this->assertSame( $this->ids['company'], (int) get_post( $this->ids['team'] )->post_parent );
		$this->assertSame( $this->ids['company'], (int) get_post( $this->ids['history'] )->post_parent );
		$this->assertSame( $this->ids['team'], (int) get_post( $this->ids['leadership'] )->post_parent );
		$this->assertSame( $this->ids['team'], $this->resolver->resolve( 'page', 'company/about/team' )->ID );
		$this->assertSame( $this->ids['leadership'], $this->resolver->resolve( 'page', 'company/about/team/leadership' )->ID );

		$change = ( new Change_Log() )->latest( $this->admin );
		$this->assertSame( 'trash', $change['action'] );
		$this->assertSame( Settings::POLICY_REPARENT, $change['policy'] );
		$this->assertSame( 2, $change['counts']['moved'] );
		$this->assertSame( '', $change['job'] );
	}

	public function test_colliding_slug_gets_a_number_and_its_old_url_still_resolves(): void {
		self::factory()->post->create( [ 'post_type' => 'page', 'post_name' => 'team', 'post_parent' => $this->ids['company'] ] );

		wp_trash_post( $this->ids['about'] );

		$this->assertSame( 'team-2', get_post( $this->ids['team'] )->post_name );
		$this->assertSame( $this->ids['team'], $this->resolver->resolve( 'page', 'company/about/team' )->ID );
		$this->assertSame( $this->ids['leadership'], $this->resolver->resolve( 'page', 'company/about/team/leadership' )->ID );
	}

	public function test_subpages_the_user_cannot_edit_stay_put(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$locked = $this->ids['history'];
		add_filter(
			'map_meta_cap',
			static function ( array $caps, string $cap, int $user_id, array $args ) use ( $locked ): array {
				return 'edit_post' === $cap && isset( $args[0] ) && (int) $args[0] === $locked ? [ 'do_not_allow' ] : $caps;
			},
			10,
			4
		);

		wp_trash_post( $this->ids['about'] );

		$this->assertSame( $this->ids['company'], (int) get_post( $this->ids['team'] )->post_parent );
		$this->assertSame( $this->ids['about'], (int) get_post( $locked )->post_parent );
		$this->assertSame( 1, ( new Change_Log() )->latest( get_current_user_id() )['counts']['kept'] );
	}

	public function test_a_failed_trash_changes_nothing(): void {
		add_filter( 'wp_insert_post_empty_content', '__return_true' );

		$this->assertFalse( wp_trash_post( $this->ids['about'] ) );

		$this->assertSame( 'publish', get_post_status( $this->ids['about'] ) );
		$this->assertSame( $this->ids['about'], (int) get_post( $this->ids['team'] )->post_parent );
		$this->assertNull( $this->ledger->find( 'page', 'company/about' ) );
	}

	public function test_the_keep_policy_leaves_subpages_in_place(): void {
		$this->policy( Settings::POLICY_KEEP );

		wp_trash_post( $this->ids['about'] );

		$this->assertSame( $this->ids['about'], (int) get_post( $this->ids['team'] )->post_parent );
		$this->assertSame( Settings::POLICY_KEEP, ( new Change_Log() )->latest( $this->admin )['policy'] );
	}

	public function test_trash_children_then_undo_restores_previous_statuses(): void {
		$this->policy( Settings::POLICY_TRASH_CHILDREN );
		$plans = self::factory()->post->create( [ 'post_type' => 'page', 'post_status' => 'draft', 'post_name' => 'plans', 'post_parent' => $this->ids['team'] ] );

		wp_trash_post( $this->ids['about'] );
		foreach ( [ 'team', 'history', 'leadership' ] as $slug ) {
			$this->assertSame( 'trash', get_post_status( $this->ids[ $slug ] ), $slug );
		}
		$this->assertSame( 'trash', get_post_status( $plans ) );

		$this->assertTrue( $this->handler()->undo( $this->ids['about'] ) );

		$this->assertSame( 'publish', get_post_status( $this->ids['about'] ) );
		foreach ( [ 'team', 'history', 'leadership' ] as $slug ) {
			$this->assertSame( 'publish', get_post_status( $this->ids[ $slug ] ), $slug );
		}
		$this->assertSame( 'draft', get_post_status( $plans ) );
	}

	public function test_restoring_through_a_status_update_moves_subpages_back(): void {
		wp_trash_post( $this->ids['about'] );

		wp_update_post( [ 'ID' => $this->ids['about'], 'post_status' => 'publish' ] );

		$this->assertSame( 'about', get_post( $this->ids['about'] )->post_name, 'Precondition: core restored the slug.' );
		$this->assertSame( $this->ids['about'], (int) get_post( $this->ids['team'] )->post_parent );
		$this->assertSame( $this->ids['team'], $this->resolver->resolve( 'page', 'company/team' )->ID, 'The interim URL still resolves.' );
		$this->assertFalse( metadata_exists( 'post', $this->ids['team'], Markers::MOVED_FROM ) );
	}

	public function test_undo_restores_the_parent_with_its_status_and_brings_subpages_back(): void {
		wp_trash_post( $this->ids['about'] );

		$this->assertTrue( $this->handler()->undo( $this->ids['about'] ) );

		$this->assertSame( 'publish', get_post_status( $this->ids['about'] ), 'Core alone would restore it as a draft.' );
		$this->assertSame( $this->ids['about'], (int) get_post( $this->ids['history'] )->post_parent );
		$this->assertSame( 'restore', ( new Change_Log() )->latest( $this->admin )['action'] );
	}

	public function test_undo_waits_for_a_running_job(): void {
		add_filter( 'hierarchy_guard_batch_threshold', static fn(): int => 1 );
		wp_trash_post( $this->ids['about'] );
		$this->assertNotSame( '', ( new Change_Log() )->latest( $this->admin )['job'], 'Precondition: a job is queued.' );

		$this->assertFalse( $this->handler()->undo( $this->ids['about'] ) );
		$this->assertSame( 'trash', get_post_status( $this->ids['about'] ) );
	}

	public function test_large_subtrees_move_in_background_batches(): void {
		add_filter( 'hierarchy_guard_batch_threshold', static fn(): int => 2 );
		foreach ( [ 'a', 'b', 'c' ] as $slug ) {
			self::factory()->post->create( [ 'post_type' => 'page', 'post_name' => $slug, 'post_parent' => $this->ids['about'] ] );
		}

		wp_trash_post( $this->ids['about'] );

		$this->assertSame( 3, Subtree::count_live_children( $this->ids['about'], 'page' ), 'Only the first batch ran inline.' );
		$queue = new Queue();
		$ids   = $queue->pending();
		$this->assertCount( 1, $ids );
		$this->assertNotFalse( wp_next_scheduled( Queue::HOOK, [ $ids[0] ] ) );

		( new Processor( $queue, new Operations( $this->ledger, new Mover() ) ) )->drain( $ids[0] );

		$this->assertSame( 0, Subtree::count_live_children( $this->ids['about'], 'page' ) );
		$this->assertSame( [], $queue->pending() );
	}

	public function test_restoring_mid_batch_stops_the_job_and_brings_back_what_moved(): void {
		add_filter( 'hierarchy_guard_batch_threshold', static fn(): int => 2 );
		foreach ( [ 'a', 'b', 'c' ] as $slug ) {
			self::factory()->post->create( [ 'post_type' => 'page', 'post_name' => $slug, 'post_parent' => $this->ids['about'] ] );
		}
		wp_trash_post( $this->ids['about'] );
		$queue  = new Queue();
		$job_id = $queue->pending()[0];

		add_filter( 'wp_untrash_post_status', 'wp_untrash_post_set_previous_status', 10, 3 );
		wp_untrash_post( $this->ids['about'] );
		remove_filter( 'wp_untrash_post_status', 'wp_untrash_post_set_previous_status', 10 );
		( new Processor( $queue, new Operations( $this->ledger, new Mover() ) ) )->drain( $job_id );

		$this->assertSame( 5, Subtree::count_live_children( $this->ids['about'], 'page' ) );
		$this->assertSame( [], $queue->pending() );
	}

	public function test_untrashing_a_page_without_marked_subpages_does_nothing(): void {
		wp_trash_post( $this->ids['history'] );
		wp_untrash_post( $this->ids['history'] );

		$this->assertSame( [], ( new Queue() )->pending() );
		$this->assertSame( $this->ids['about'], (int) get_post( $this->ids['team'] )->post_parent );
	}

	public function test_unsupported_types_are_ignored(): void {
		register_post_type( 'hg_private', [ 'public' => false, 'hierarchical' => true ] );
		$parent = self::factory()->post->create( [ 'post_type' => 'hg_private', 'post_name' => 'parent' ] );
		$child  = self::factory()->post->create( [ 'post_type' => 'hg_private', 'post_name' => 'child', 'post_parent' => $parent ] );

		wp_trash_post( $parent );

		$this->assertSame( $parent, (int) get_post( $child )->post_parent );
	}
}
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `npm run test:php -- --filter TrashHandlerTest`
Expected: `No Trash_Handler registered on trashed_post.` and failures on every behavior.

- [ ] **Step 4: Create Trash_Handler**

`includes/HierarchyGuard/Trash/Trash_Handler.php`:
```php
<?php
/**
 * Applies the trash policy to a page's subpages and reverses it on restore.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Trash;

use HierarchyGuard\Batch\Queue;
use HierarchyGuard\Ledger;
use HierarchyGuard\Paths;
use HierarchyGuard\Post_Types;
use HierarchyGuard\Settings;
use HierarchyGuard\Subtree;
use WP_Post;

/**
 * Snapshots in wp_trash_post, mutates in trashed_post once core has succeeded,
 * and takes back its own moves on any transition out of the trash.
 */
final class Trash_Handler {

	/**
	 * Snapshots taken in wp_trash_post, keyed by post ID, used in trashed_post.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private static array $snapshots = [];

	/**
	 * Ledger.
	 *
	 * @var Ledger
	 */
	private Ledger $ledger;

	/**
	 * Operations.
	 *
	 * @var Operations
	 */
	private Operations $operations;

	/**
	 * Queue.
	 *
	 * @var Queue
	 */
	private Queue $queue;

	/**
	 * Change log.
	 *
	 * @var Change_Log
	 */
	private Change_Log $log;

	/**
	 * Constructor.
	 *
	 * @param Ledger     $ledger     Ledger.
	 * @param Operations $operations Operations.
	 * @param Queue      $queue      Queue.
	 * @param Change_Log $log        Change log.
	 */
	public function __construct( Ledger $ledger, Operations $operations, Queue $queue, Change_Log $log ) {
		$this->ledger     = $ledger;
		$this->operations = $operations;
		$this->queue      = $queue;
		$this->log        = $log;
	}

	/**
	 * Hooks into core.
	 */
	public function register(): void {
		add_action( 'wp_trash_post', [ $this, 'before_trash' ] );
		add_action( 'trashed_post', [ $this, 'after_trash' ] );
		add_action( 'transition_post_status', [ $this, 'on_transition' ], 10, 3 );
	}

	/**
	 * Resolves the policy and snapshots paths. Writes nothing: the trash can still fail.
	 *
	 * @param mixed $post_id Post being trashed.
	 */
	public function before_trash( $post_id ): void {
		if ( Guard::active() ) {
			return;
		}
		$post = get_post( (int) $post_id );
		if ( ! $post instanceof WP_Post || ! Post_Types::is_supported( (string) $post->post_type ) ) {
			return;
		}
		if ( 0 === Subtree::count_live_children( (int) $post->ID, (string) $post->post_type ) ) {
			return;
		}

		$default = Settings::trash_policy();

		/**
		 * Filters the trash policy for one page with subpages.
		 *
		 * @param string  $policy One of Settings::POLICIES.
		 * @param WP_Post $post   The page being trashed.
		 */
		$policy = (string) apply_filters( 'hierarchy_guard_trash_policy', $default, $post );
		if ( ! in_array( $policy, Settings::POLICIES, true ) ) {
			$policy = $default;
		}

		self::$snapshots[ (int) $post->ID ] = [
			'policy'    => $policy,
			'paths'     => array_values( array_unique( array_merge( [ Paths::untrashed_uri( $post ) ], $this->ledger->paths_for( (int) $post->ID ) ) ) ),
			'parent'    => (int) $post->post_parent,
			'post_type' => (string) $post->post_type,
		];
	}

	/**
	 * Applies the snapshot's policy now that core has trashed the post.
	 *
	 * @param mixed $post_id Trashed post.
	 */
	public function after_trash( $post_id ): void {
		$post_id = (int) $post_id;
		if ( Guard::active() || ! isset( self::$snapshots[ $post_id ] ) ) {
			return;
		}
		$snapshot = self::$snapshots[ $post_id ];
		unset( self::$snapshots[ $post_id ] );

		$policy = (string) $snapshot['policy'];
		$type   = (string) $snapshot['post_type'];

		if ( Settings::POLICY_KEEP === $policy ) {
			$this->log->record(
				[
					'action' => 'trash',
					'root'   => $post_id,
					'policy' => $policy,
					'counts' => [],
					'ids'    => [],
					'job'    => '',
				]
			);
			return;
		}

		if ( Settings::POLICY_REPARENT === $policy ) {
			if ( '' !== (string) get_option( 'permalink_structure' ) ) {
				foreach ( $snapshot['paths'] as $path ) {
					if ( ! Paths::has_trashed_segment( (string) $path ) ) {
						$this->ledger->record_alias( $type, (int) $snapshot['parent'], (string) $path );
					}
				}
			}
			$job  = Operations::new_job( Operations::TYPE_REPARENT, $post_id, (int) $snapshot['parent'], $type, get_current_user_id() );
			$size = Subtree::count_live_children( $post_id, $type );
		} else {
			$job  = Operations::new_job( Operations::TYPE_TRASH, $post_id, 0, $type, get_current_user_id() );
			$size = Subtree::count_live_descendants( $post_id, $type, Operations::threshold() );
		}

		$this->dispatch( 'trash', $policy, $job, $size );
	}

	/**
	 * Takes back the plugin's own moves when a post leaves the trash by any route.
	 *
	 * @param mixed $new_status New status.
	 * @param mixed $old_status Old status.
	 * @param mixed $post       Post.
	 */
	public function on_transition( $new_status, $old_status, $post ): void {
		// Runs on every status change on the site: the cheapest checks come first.
		if ( 'trash' !== $old_status || 'trash' === $new_status || Guard::active() || ! $post instanceof WP_Post ) {
			return;
		}
		if ( ! Post_Types::is_supported( (string) $post->post_type ) ) {
			return;
		}

		$type = (string) $post->post_type;
		$cap  = Operations::threshold();
		$size = count( Subtree::marked( Markers::MOVED_FROM, (int) $post->ID, $type, $cap + 1 ) )
			+ count( Subtree::marked( Markers::TRASHED_WITH, (int) $post->ID, $type, $cap + 1 ) );
		if ( 0 === $size ) {
			return;
		}

		$this->dispatch( 'restore', '', Operations::new_job( Operations::TYPE_RESTORE, (int) $post->ID, 0, $type, get_current_user_id() ), $size );
	}

	/**
	 * Restores a trashed page with the status it had, which brings its subpages
	 * back through on_transition(). Refused while a job for it is still running.
	 *
	 * @param int $root_id Trashed page.
	 * @return bool Whether it left the trash.
	 */
	public function undo( int $root_id ): bool {
		if ( 'trash' !== get_post_status( $root_id ) || $this->queue->pending_for( $root_id ) ) {
			return false;
		}
		add_filter( 'wp_untrash_post_status', 'wp_untrash_post_set_previous_status', 10, 3 );
		$restored = wp_untrash_post( $root_id );
		remove_filter( 'wp_untrash_post_status', 'wp_untrash_post_set_previous_status', 10 );
		return $restored instanceof WP_Post;
	}

	/**
	 * Runs a job inline when small enough, else its first batch inline and the
	 * rest in the background, then records the change summary.
	 *
	 * @param string              $action trash or restore.
	 * @param string              $policy Policy applied, empty for a restore.
	 * @param array<string,mixed> $job    Job.
	 * @param int                 $size   Estimated subpages the job touches.
	 */
	private function dispatch( string $action, string $policy, array $job, int $size ): void {
		if ( $size <= Operations::threshold() ) {
			$job = $this->operations->run( $job );
		} else {
			$job = $this->operations->step( $job, Operations::threshold() );
			if ( empty( $job['finished'] ) ) {
				$job = $this->queue->save( $job );
				$this->queue->schedule( (string) $job['id'] );
			}
		}

		$this->log->record(
			[
				'action' => $action,
				'root'   => (int) $job['root'],
				'policy' => $policy,
				'counts' => $job['counts'],
				'ids'    => $job['ids'],
				'job'    => empty( $job['finished'] ) ? (string) $job['id'] : '',
			]
		);
	}
}
```

- [ ] **Step 5: Register the handler in Plugin::register()**

In `includes/HierarchyGuard/Plugin.php`, add after `$processor->register();`:
```php
		( new Trash\Trash_Handler( $ledger, $operations, $queue, new Trash\Change_Log() ) )->register();
```
The complete `register()` body now reads:
```php
		if ( $this->registered ) {
			return;
		}
		$this->registered = true;

		add_action( 'registered_post_type', [ Post_Types::class, 'reset' ] );
		add_action( 'unregistered_post_type', [ Post_Types::class, 'reset' ] );
		add_action( 'update_option_' . Settings::OPTION, [ Post_Types::class, 'reset' ] );
		add_action( 'add_option_' . Settings::OPTION, [ Post_Types::class, 'reset' ] );
		add_action( 'delete_option_' . Settings::OPTION, [ Post_Types::class, 'reset' ] );
		add_action( 'switch_blog', [ Post_Types::class, 'reset' ] );

		$ledger = new Ledger();
		( new Change_Listener( $ledger ) )->register();
		( new Redirects\Server( new Redirects\Resolver( $ledger ) ) )->register();
		( new Admin\Settings_Section( new Redirects\Providers() ) )->register();
		( new Trash\Markers() )->register();

		$queue      = new Batch\Queue();
		$operations = new Trash\Operations( $ledger, new Trash\Mover() );
		$processor  = new Batch\Processor( $queue, $operations );
		$processor->register();
		( new Trash\Trash_Handler( $ledger, $operations, $queue, new Trash\Change_Log() ) )->register();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'hierarchy-guard', new Batch\CLI_Command( $queue, $processor ) );
		}
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `npm run test:php -- --filter TrashHandlerTest`
Expected: `OK (14 tests, ...)`.

Run: `npm run test:php`
Expected: all green, including `ChangeListenerTest` and `ResolverTest` under the keep policy. If any other engine test now fails because a subpage moved, it is exercising core's trash behavior: pin it with the same keep filter and say so in the report; do not change its assertions.

Run: `composer lint`
Expected: exit 0.

- [ ] **Step 7: Commit**

```bash
git add includes tests
git commit -m "feat: trash handler applies the policy, batches large subtrees and reverses on restore"
```

---

### Task 9: Restore the trash-policy setting

**Files:**
- Modify: `includes/HierarchyGuard/Admin/Settings_Section.php`
- Test: `tests/phpunit/SettingsSectionTest.php`

**Interfaces:**
- Consumes: `Settings::get( 'trash_policy' )`, `Settings::POLICY_*`.
- Produces: `Settings_Section::render_trash_policy(): void`; the Writing page registers three fields again: post types, trash policy, redirects.

- [ ] **Step 1: Write the failing tests**

In `tests/phpunit/SettingsSectionTest.php`, in `test_section_and_fields_register_on_the_writing_page()`, replace the field-count assertions with:
```php
		$this->assertCount( 3, $wp_settings_fields['writing']['hierarchy_guard'] );
		$this->assertArrayHasKey( 'hierarchy_guard_trash_policy', $wp_settings_fields['writing']['hierarchy_guard'] );
```
and add:
```php
	public function test_trash_policy_field_renders_three_radios_with_the_default_selected(): void {
		ob_start();
		$this->section->render_trash_policy();
		$html = (string) ob_get_clean();

		$this->assertSame( 3, substr_count( $html, 'type="radio"' ) );
		$this->assertMatchesRegularExpression( '/value="reparent"[^>]*checked/', $html );
		$this->assertStringContainsString( 'Move subpages up one level', $html );
		$this->assertStringContainsString( 'Not applied when the trash is disabled.', $html );
		$this->assertStringNotContainsString( 'Pages list', $html, 'No claims about UI that does not exist yet.' );
	}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npm run test:php -- --filter SettingsSectionTest`
Expected: the count assertion fails (2 vs 3) and `render_trash_policy()` is undefined.

- [ ] **Step 3: Restore the field**

In `includes/HierarchyGuard/Admin/Settings_Section.php`:

Replace the class docblock with:
```php
/**
 * Registers the plugin's settings as a section on the Writing settings page.
 */
```

In `add()`, between the post-types field and the redirects field, add:
```php
		add_settings_field( 'hierarchy_guard_trash_policy', __( 'Trashing a page with subpages', 'hierarchy-guard' ), [ $this, 'render_trash_policy' ], self::PAGE, self::SECTION );
```

Add the method after `render_post_types()`:
```php
	/**
	 * Radio group for the trash policy.
	 */
	public function render_trash_policy(): void {
		$current = (string) Settings::get( 'trash_policy' );
		$options = [
			Settings::POLICY_REPARENT       => __( 'Move subpages up one level', 'hierarchy-guard' ),
			Settings::POLICY_TRASH_CHILDREN => __( 'Trash the subpages too', 'hierarchy-guard' ),
			Settings::POLICY_KEEP           => __( 'Leave subpages where they are', 'hierarchy-guard' ),
		];

		echo '<fieldset><legend class="screen-reader-text"><span>' . esc_html__( 'Trashing a page with subpages', 'hierarchy-guard' ) . '</span></legend>';
		foreach ( $options as $value => $label ) {
			printf(
				'<label><input type="radio" name="%1$s[trash_policy]" value="%2$s" %3$s> %4$s</label><br>',
				esc_attr( Settings::OPTION ),
				esc_attr( $value ),
				checked( $current, $value, false ),
				esc_html( $label )
			);
		}
		echo '<p class="description">' . esc_html__( 'Applies whenever a page with subpages goes to the trash, including from the editor, the REST API and WP-CLI. Not applied when the trash is disabled.', 'hierarchy-guard' ) . '</p>';
		echo '</fieldset>';
	}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `npm run test:php -- --filter SettingsSectionTest`
Expected: all pass.

Run: `npm run test:php` and `composer lint`
Expected: all green; lint exit 0.

- [ ] **Step 5: Commit**

```bash
git add includes tests
git commit -m "feat: restore the trash-policy setting now that the handler reads it"
```

---

### Task 10: Document background jobs and verify on the dev site

**Files:**
- Modify: `README.md`

**Interfaces:**
- Consumes: the running wp-env dev site (port 8888 by default; a local `.wp-env.override.json` may move it, check `npx wp-env run cli wp option get siteurl`).

- [ ] **Step 1: Document background jobs in the README**

Append to `README.md`:
```markdown
## Background jobs

Trashing a page with more subpages than the batch threshold (200 by default, filter `hierarchy_guard_batch_threshold`) moves the first batch immediately and the rest through WP-Cron, or Action Scheduler when it is installed. On hosts where cron is disabled or only runs on traffic, finish queued jobs with:

```bash
wp hierarchy-guard process-jobs
```
```

- [ ] **Step 2: Verify end to end on the dev site**

Run, replacing `8898` with the dev site's port:
```bash
run() { npx wp-env run cli "$@" 2>/dev/null | grep -v '^ℹ\|^✔' ; }
c() { printf "%-26s " "$1"; curl -s -o /dev/null -w "%{http_code} -> %{redirect_url}\n" "http://localhost:8898$1"; }
run wp rewrite structure '/%postname%/' --hard
run wp option delete hierarchy_guard_settings
run wp option update hierarchy_guard_settings '{"serve_redirects":true}' --format=json
O=$(run wp post create --post_type=page --post_status=publish --post_title=Org --post_name=org --porcelain | tr -d '[:space:]')
T=$(run wp post create --post_type=page --post_status=publish --post_title=Team --post_name=team --post_parent=$O --porcelain | tr -d '[:space:]')
K=$(run wp post create --post_type=page --post_status=publish --post_title=Kai --post_name=kai --post_parent=$T --porcelain | tr -d '[:space:]')
c /org/team/kai/
run wp post delete $T
echo "kai parent after trash: $(run wp post get $K --field=post_parent) (org=$O)"
c /org/team/kai/
run wp post update $T --post_status=publish
echo "kai parent after restore: $(run wp post get $K --field=post_parent) (team=$T)"
c /org/kai/
run wp hierarchy-guard process-jobs
```
Expected:
```
/org/team/kai/             200 ->
kai parent after trash: <O> (org=<O>)
/org/team/kai/             301 -> http://localhost:8898/org/kai/
kai parent after restore: <T> (team=<T>)
/org/kai/                  301 -> http://localhost:8898/org/team/kai/
Success: No queued jobs.
```
Record the actual output in the report. If any line differs, stop and report it with the output; do not change code in this task.

- [ ] **Step 3: Commit**

```bash
git add README.md
git commit -m "docs: document background jobs and wp hierarchy-guard process-jobs"
```

---

## Self-Review

**Spec coverage.** Policy per trash with three options and a filterable per-page choice: Tasks 4, 5 and 8. Capability checks per child against the triggering user, user 0 trusted: Tasks 4, 5 and 8. Snapshot before core acts, mutate after success: Task 8 (`before_trash` writes nothing; `test_a_failed_trash_changes_nothing`). Raw re-parent with cache clear, unique slug, renamed child's old URL recorded, original slug restored: Tasks 3, 4 and 6. One alias on the grandparent for all moved children: Task 8, using Task 2's `record_alias()`. Inline up to the threshold, background batches beyond, Action Scheduler when present, WP-CLI command: Tasks 7 and 8. Undo waits for a running job: Task 8. Reversal on any transition out of trash, previous statuses kept, interim URLs recorded: Tasks 6 and 8. Batch stops when its root changes state: Tasks 4, 5, 6 and 8. Markers kept out of WXR: Task 1. Change summary for the notice: Tasks 1 and 8. Carried over from plan 1: trash-policy field restored (Task 9), trashed-ancestor paths on empty trash and resolver fallback past a trashed ancestor (Task 2). Out of this plan, by design: the Pages-list dialog, outcome notice, Undo link and redirects modal (plan 3).

**Placeholder scan.** No TBD or TODO. Every code step carries complete code. Task 8 Step 6 gives an exact rule for any further engine test that trips on the new default policy.

**Type consistency.** `Operations::new_job()` keys match every reader: `step_*` methods, `Processor::run()`, `Queue::pending_for()` (`root`), `Trash_Handler::dispatch()` (`counts`, `ids`, `id`, `finished`). `Mover::move()` returns `moved`, `old_path`, `new_path`, `old_slug`, `renamed`, and Operations reads exactly those. `Subtree::marked( $meta_key, $root_id, $post_type, $limit, $after_id )` is called with that order in Operations and Trash_Handler. `Markers::mark_moved( $post_id, $from, $to, $original_slug )` matches Task 4's call. `Ledger::record_alias( $post_type, $new_parent_id, $old_path )` matches Change_Listener and Trash_Handler.

**Review Focus.** All five named tests exist: four in Task 8 (collision, capability, status-update restore, failed trash) and one in Task 6 (subpage moved elsewhere).
