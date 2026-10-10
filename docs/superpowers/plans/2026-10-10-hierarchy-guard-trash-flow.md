# Hierarchy Guard Trash Flow Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. Every review seat (task review, fix re-review, final review) runs on Opus.

**Goal:** When a page with subpages is trashed, apply the trash policy (move subpages up, trash them too, or leave them), keep every old URL resolvable, and take it all back when the page leaves the trash by any route, without any request doing work that grows with the size of the subtree.

**Architecture:** `Trash\Trash_Handler` snapshots in `wp_trash_post`, acts in `trashed_post` only after core succeeded, and restores at `wp_after_insert_post` once the page's own save is done. The work is `Trash\Operations` steps over a plain job array with two budgets, raw moves and core trash or untrash calls; one request spends at most one of each inline across every page it touches, and `Batch\Processor` finishes the rest in time-boxed runs under a per-root lock, watched by an hourly watchdog. Per-root marker rows on the subpages let a restore undo exactly what the trash did, and each row is deleted once handled.

**Tech Stack:** PHP 8.0+, WordPress 6.6+, the plugin's engine (Paths, Ledger, Change_Listener, Resolver, Server), PHPUnit 9.6 in wp-env, PHPCS (WordPress-Extra, WordPress-VIP-Go, PHPCompatibilityWP).

**Spec:** `docs/superpowers/specs/hierarchy-guard-spec.html` is a snapshot of the live spec at https://claude.ai/artifact/6V5RMR2w49uPiEp96ycyzq. Read "Trashing a parent" under How it works, the Data model table, Hooks and handlers, Filters for developers and Testing. The Pages-list trash dialog, the outcome notice with its Undo link and the redirects modal are admin UI and belong to plan 3. This plan builds what they call: the `hierarchy_guard_trash_policy` filter, `Trash\Change_Log`, `Trash_Handler::undo()`, and an interim sentence on core's own trash notice.

**Code in this plan is verified.** Every code block was run in a prototype on wp-env (WordPress 7.1.3) before it was written down: the full suite passes after every task (242 tests at the end) and `composer lint` exits 0. Transcribe it exactly; deviations need a reason in the report.

## Global Constraints

- Requires WordPress 6.6 and PHP 8.0. Namespace `HierarchyGuard`; new code goes in `HierarchyGuard\Trash` (`includes/HierarchyGuard/Trash/`), `HierarchyGuard\Batch` (`includes/HierarchyGuard/Batch/`), `HierarchyGuard\Subtree` and `HierarchyGuard\Admin\Trash_Notice`. Every file declares `strict_types=1`.
- Commits are authored by the repository's git config. No `Co-Authored-By` trailer and no Claude attribution in any commit message.
- `composer lint` must exit 0, warnings included. Run `composer lint:fix` (phpcbf) first; formatting-only changes it makes are not deviations. Resolve any remaining warning with a behavior-neutral change or a targeted `// phpcs:ignore Sniff.Name.Code -- reason` on the line before.
- Tests that check a hook is registered must find the plugin's own object in `$GLOBALS['wp_filter'][ $hook ]->callbacks[ $priority ]`; `has_action( $hook )` alone is always true because core registers callbacks on the same hooks.
- Markers: `_hierarchy_guard_moved_{root_id}` on each moved subpage, value `{to}|{original slug}|{assigned slug}|{unix time}`; `_hierarchy_guard_trashed_{root_id}` on each subpage trashed with its root, value `1`; `_hierarchy_guard_aliases` on the root, a list of `[ 'to' => int, 'path' => string ]`. The per-subpage moved and trashed rows are written and deleted with direct queries plus `wp_cache_delete( $post_id, 'post_meta' )`, read in `meta_id` order with a LIMIT, and deleted once handled; the single aliases row per root uses the meta API. All three keys are skipped by `wxr_export_skip_postmeta`; old-path ledger entries stay exportable.
- A subpage trashed with its root returns with its previous status however it leaves the trash, through a permanent `wp_untrash_post_status` filter at priority 20. The plugin never adds or removes core's `wp_untrash_post_set_previous_status` callback.
- Budgets: filter `hierarchy_guard_batch_threshold`, default 200 raw moves (or rows looked at, or rows deleted) per step; filter `hierarchy_guard_core_call_threshold`, default 25 `wp_trash_post()`/`wp_untrash_post()` calls per step; both floor 1. One request spends at most one of each inline, across every page it trashes or restores; the rest is queued.
- Count cap: filter `hierarchy_guard_count_cap`, default 1,000, floor 1. The trash-the-subpages policy falls back to moving them up when a subtree has more live pages than the cap.
- Jobs: one per root in option `hierarchy_guard_job_{root_id}`, an offer for a busy root in `hierarchy_guard_job_{root_id}_next`, and lock `hierarchy_guard_lock_{root_id}` (`INSERT IGNORE`, value is its expiry, 300 seconds, taken over once expired). All three are read and written with direct queries, autoload `off`. Cron hook `hierarchy_guard_process_batch` with the root ID as its only argument; Action Scheduler group `hierarchy-guard` when `as_enqueue_async_action()` exists, WP-Cron otherwise or when Action Scheduler returns 0.
- Runs: a scheduled run stops after 20 seconds or half of `max_execution_time`, whichever is lower, or at 80% of `memory_limit`, then schedules itself again. Watchdog hook `hierarchy_guard_watchdog`, hourly, scheduled on `admin_init` and on activation, cleared on deactivation: it reschedules a job untouched for 10 minutes with nothing scheduled, and deletes a job older than 7 days or whose root is gone (cleanup jobs excepted). WP-CLI: `wp hierarchy-guard process-jobs [--sleep=<milliseconds>]`, default 250.
- Change summary: transient `hierarchy_guard_change_{user_id}`, 30 minutes, written only for a logged-in user. One change per request, user and action. Keys: `id`, `action` (`trash`|`restore`), `post_type`, `roots[ root_id ] = { root, to, policy, fallback, counts, kept_ids (≤ 20), urls { count, capped }, job }`, summed `counts`, `ids` (≤ 200), `time`, `updated`, `shown`. Count keys: `moved`, `trashed`, `restored`, `kept`, `denied`, `left`, `renamed`. Titles, URLs and old/new pairs are never stored.
- Policy: `Settings::trash_policy()`, then filter `hierarchy_guard_trash_policy( string $policy, WP_Post $post )`; an invalid filtered value falls back to the setting. Policies: `reparent` (default), `trash_children`, `keep`.
- Capabilities: `edit_post` to move a subpage, `delete_post` to trash one, checked against the user who triggered the trash (stored in the job). User 0 (cron, WP-CLI without `--user`) is trusted, as core trusts its own API. A subpage that fails the check stays where it is and counts as both `kept` and `denied`.
- Order of events: nothing is written in `wp_trash_post`; mutation happens in `trashed_post`; the restore runs at `wp_after_insert_post` priority 20, after the page's own save, or is queued at `shutdown` when that hook never fired. Re-parenting is a direct `post_parent`/`post_name` update plus `clean_post_cache()`, after `wp_unique_post_slug()` under the new parent with drafts and pending posts checked as `publish`. It fires `hierarchy_guard_children_reparented( int[] $ids, int $from, int $to )`.
- Core's bulk loops: in a Pages-list bulk trash or untrash (`edit.php`, `action` `trash` or `untrash`, IDs from `ids` or `post[]`), the selected IDs are left to core. The plugin does not move, trash, expand or untrash them.
- Targets and stops: subpages move to the nearest ancestor that is not in the trash, re-checked every step. A trash step stops when its root, read past the object cache, is no longer in the trash; a restore step stops when it is in the trash again or gone. The front page, posts page and privacy policy page are never trashed by the subtree policy.
- Aliases: the old parent paths are recorded on the new parent, or in the root list, only after the first subpage moved, and each is also recorded on the root itself. The aliases are remembered on the root and removed when the restore finishes; the root's own entry is pruned when it is back at that path and stays, leading to it, when it came back elsewhere. Ledger lookups ignore entries held by posts in the trash, and an exact old parent path held by a page in the trash resolves to nothing.
- Interim URLs: a restore records a moved-back subpage's interim path only when the move is at least an hour old. Every ledger record the trash flow makes for a subpage requires `Paths::is_recordable()` on it before the move.
- Copy: sentence case, the word "subpages", no product name inside UI text, no exclamation marks, no claims about UI that doesn't exist yet. One translatable string per sentence, `_n()` on the counted noun, a translator comment for every placeholder, titles inside the string as “%s”, `number_format_i18n()` for numbers, "(no title)" as the fallback. "trash" is lowercase in the plugin's own sentences and "Trash" in sentences appended to core's notice. WP-CLI lines are English only.

## Review Focus

1. Bulk-trashing a parent together with one of its subpages from the Pages list, under either policy, then clicking Undo: core never stops with "Error in moving the item to Trash." or "Error in restoring the item from Trash.", and Undo puts the tree back with every page's status. Pinned in Task 9 (`test_bulk_trash_and_undo_of_a_parent_and_its_subpage_round_trip`, `test_bulk_trash_and_undo_with_trash_children_round_trip`, `test_restoring_everything_from_the_trash_view_never_stops_core`).
2. A subpage whose slug is already taken under the grandparent: it becomes `team-2`, its old URL still resolves, and a restore gives back `team`. Pinned in Task 9 (`test_colliding_slug_gets_a_number_and_its_old_url_still_resolves`) and Task 7 (`test_a_renamed_child_gets_its_original_slug_back`).
3. Restoring through a plain status update (REST `PUT status=publish`, `wp post update --post_status=publish`), which never calls `wp_untrash_post()`: subpages come back, and only after the page's own save. Pinned in Task 9 (`test_restoring_through_a_status_update_moves_subpages_back_after_the_save`).
4. An editor who can trash the parent but lacks `edit_post` on one subpage: that subpage stays, the rest move, and the summary counts it as denied. Pinned in Task 9 (`test_subpages_the_user_cannot_edit_stay_put`).
5. A restore while a background batch for the same page runs in another process: the restore is handed to that process and wins. Pinned in Task 9 (`test_a_restore_while_a_batch_runs_is_handed_to_that_batch`) and Task 8 (`test_an_offer_replaces_the_job_and_closes_its_summary`).

## Known limitations

Accepted for this plan; reviewers should not re-raise them.

- `wp post delete <parent> <subpage>` under the trash-the-subpages policy reports the subpage as already trashed. The plugin cannot see WP-CLI's argument list. Site Editor DataViews bulk trash gets `rest_already_trashed` the same way; plan 4's replacement trash action covers it.
- A request that dies mid-step right after trashing a subpage can leave that subpage's own subpages in place. The restore still brings back every page it marked.
- Core's 404 guess in `redirect_canonical()` can still send a merely-trashed page's URL to a similarly named page (the header says `X-Redirect-By: WordPress`). The plugin itself never redirects it.
- A selected page another user has locked is skipped by core's bulk trash and stays under its trashed parent.
- A subpage that stays under the trashed page because the user can't move it keeps its `__trashed` URL. Its old URL follows the alias to the grandparent, so if the grandparent has a subpage with the same slug, the old URL leads there.
- Restoring two nested trashed pages in the reverse order of their trashes can leave a subpage one level higher than it started.
- Uninstall cleanup of markers, jobs, locks and transients is plan 4.

---

## File Structure

```
hierarchy-guard.php                        MOD  activation and deactivation hooks
includes/HierarchyGuard/
├── Subtree.php                            NEW  bounded queries: live children, capped counts, uncached status, nearest live ancestor
├── Paths.php                              MOD  untrashed_uri(): the path with trashed segments restored
├── Ledger.php                             MOD  record_alias(), forget(), forget_root(), forget_alias(), deferred()
├── Change_Listener.php                    MOD  delete flow uses untrashed_uri() and record_alias(); skips co-trashed subpages
├── Plugin.php                             MOD  wiring
├── Redirects/Resolver.php                 MOD  skip hits under a trashed ancestor; no redirect for a merely-trashed page
├── Admin/Settings_Section.php             MOD  trash-policy field restored
├── Admin/Trash_Notice.php                 NEW  one sentence on core's trash and restore notices
├── Trash/Guard.php                        NEW  re-entrancy guard keyed by post ID
├── Trash/Markers.php                      NEW  per-root marker rows, export skip, previous-status filter
├── Trash/Mover.php                        NEW  raw re-parent with a unique slug
├── Trash/Change_Log.php                   NEW  per-user change summary
├── Trash/Request.php                      NEW  the IDs core's bulk trash or untrash is about to process
├── Trash/Operations.php                   NEW  reparent, trash-subtree, restore and cleanup steps over a job array
├── Trash/Trash_Handler.php                NEW  core hooks, policy, inline-or-queue dispatch, undo()
├── Batch/Queue.php                        NEW  per-root job slot, offer, lock, scheduling
├── Batch/Processor.php                    NEW  time-boxed runs, watchdog, activation and deactivation
└── Batch/CLI_Command.php                  NEW  wp hierarchy-guard process-jobs
tests/phpunit/
├── Trash_Fixtures.php                     NEW  trait: page tree, raw trash/untrash, marker helpers, core bulk request
├── GuardTest · MarkersTest · SubtreeTest · MoverTest · ChangeLogTest · RequestTest
├── OperationsReparentTest · OperationsTrashTest · OperationsRestoreTest
├── QueueTest · ProcessorTest · CLICommandTest · TrashHandlerTest · TrashNoticeTest
└── PathsTest · LedgerTest · ChangeListenerTest · ResolverTest · SettingsSectionTest   MOD
```

`Operations` is the only class that decides what happens to a subpage. `Trash_Handler` decides when, how much inline, and who gets told. `Batch\Processor` decides when background work runs. `Mover`, `Subtree`, `Markers`, `Change_Log` and `Request` are single-purpose helpers.

---
### Task 1: Guard, markers and test fixtures

**Files:**
- Create: `includes/HierarchyGuard/Trash/Guard.php`, `includes/HierarchyGuard/Trash/Markers.php`
- Create: `tests/phpunit/Trash_Fixtures.php`
- Modify: `composer.json` (autoload-dev), `includes/HierarchyGuard/Plugin.php`
- Test: `tests/phpunit/GuardTest.php`, `tests/phpunit/MarkersTest.php`

**Interfaces:**
- Produces: `Trash\Guard::run( int[] $ids, callable $callback ): mixed`; `Guard::covers( int $post_id ): bool`. Handlers skip only the IDs the plugin is acting on.
- Produces: `Trash\Markers` constants `MOVED_PREFIX`, `TRASHED_PREFIX`, `ALIASES`; static `moved_key( int $root_id ): string`, `trashed_key( int $root_id ): string`, `encode_moved( int $to, string $original, string $assigned, int $time ): string`, `decode_moved( string $value ): array{to:int,original:string,assigned:string,time:int}`, `add( int $post_id, string $key, string $value ): void`, `rows( string $key, int $limit, int $after_meta_id = 0 ): array<int,array{meta_id:int,post_id:int,value:string}>`, `remove( array $rows ): void`, `remove_key( int $post_id, string $key ): void`, `count( int $root_id, int $cap ): int` (both kinds), `has_trashed_row( int $post_id ): bool`, `clear_trashed_rows( int $post_id ): int`; instance `register(): void`, `skip_on_export( mixed $skip, mixed $meta_key ): bool`, `previous_status( mixed $new_status, mixed $post_id, mixed $previous_status ): string`.
- Produces: trait `HierarchyGuard\Tests\Trash_Fixtures` with `make_tree(): array<string,int>` (company / about / { team / leadership, history }, titles are the capitalized slugs), `page( string $slug, int $parent_id = 0, string $status = 'publish' ): int`, `trash_raw( int $post_id )`, `untrash_raw( int $post_id )`, `age_moved_markers( int $root_id, int $seconds )`, `marked_ids( string $key ): int[]`, `start_core_bulk( string $action, int[] $ids, bool $as_list = false )`, `end_core_bulk()`. Later tasks use all of them.

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

use HierarchyGuard\Trash\Markers;

/**
 * Builds a small page tree and moves posts in and out of the trash without
 * running hooks, so a test can exercise one unit at a time.
 */
trait Trash_Fixtures {

	/**
	 * Company / about / { team / leadership, history }.
	 *
	 * @return array<string,int> Page IDs keyed by slug.
	 */
	protected function make_tree(): array {
		$ids               = [];
		$ids['company']    = $this->page( 'company' );
		$ids['about']      = $this->page( 'about', $ids['company'] );
		$ids['team']       = $this->page( 'team', $ids['about'] );
		$ids['history']    = $this->page( 'history', $ids['about'] );
		$ids['leadership'] = $this->page( 'leadership', $ids['team'] );
		return $ids;
	}

	/**
	 * Creates a page.
	 *
	 * @param string $slug      Slug.
	 * @param int    $parent_id Parent page ID.
	 * @param string $status    Post status.
	 * @return int Page ID.
	 */
	protected function page( string $slug, int $parent_id = 0, string $status = 'publish' ): int {
		return self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_name'   => $slug,
				'post_title'  => ucfirst( $slug ),
				'post_parent' => $parent_id,
				'post_status' => $status,
			]
		);
	}

	/**
	 * Puts a post in the trash with core's trash meta and suffixed slug, without running hooks.
	 *
	 * @param int $post_id Post ID.
	 */
	protected function trash_raw( int $post_id ): void {
		global $wpdb;
		$post = get_post( $post_id );
		add_post_meta( $post_id, '_wp_desired_post_slug', $post->post_name );
		add_post_meta( $post_id, '_wp_trash_meta_status', $post->post_status );
		$wpdb->update(
			$wpdb->posts,
			[
				'post_status' => 'trash',
				'post_name'   => $post->post_name . '__trashed',
			],
			[ 'ID' => $post_id ]
		);
		clean_post_cache( $post_id );
	}

	/**
	 * Takes a post out of the trash with its previous status and slug, without
	 * running hooks. Core's own untrash would restore it as a draft.
	 *
	 * @param int $post_id Post ID.
	 */
	protected function untrash_raw( int $post_id ): void {
		global $wpdb;
		$slug   = (string) get_post_meta( $post_id, '_wp_desired_post_slug', true );
		$status = (string) get_post_meta( $post_id, '_wp_trash_meta_status', true );
		$wpdb->update(
			$wpdb->posts,
			[
				'post_status' => '' !== $status ? $status : 'publish',
				'post_name'   => $slug,
			],
			[ 'ID' => $post_id ]
		);
		delete_post_meta( $post_id, '_wp_desired_post_slug' );
		delete_post_meta( $post_id, '_wp_trash_meta_status' );
		clean_post_cache( $post_id );
	}

	/**
	 * Makes every moved marker of a root look older, so a restore treats the
	 * move as one visitors may have bookmarked.
	 *
	 * @param int $root_id Root page.
	 * @param int $seconds How much older.
	 */
	protected function age_moved_markers( int $root_id, int $seconds ): void {
		foreach ( Markers::rows( Markers::moved_key( $root_id ), 1000 ) as $row ) {
			$moved = Markers::decode_moved( $row['value'] );
			$value = Markers::encode_moved( $moved['to'], $moved['original'], $moved['assigned'], $moved['time'] - $seconds );
			Markers::remove( [ $row ] );
			Markers::add( $row['post_id'], Markers::moved_key( $root_id ), $value );
		}
	}

	/**
	 * Post IDs carrying a marker key.
	 *
	 * @param string $key Marker key.
	 * @return int[]
	 */
	protected function marked_ids( string $key ): array {
		return array_map( static fn( array $row ): int => $row['post_id'], Markers::rows( $key, 1000 ) );
	}

	/**
	 * Makes the request look like core's bulk action on the Pages list.
	 *
	 * @param string $action  trash or untrash.
	 * @param int[]  $ids     Selected IDs.
	 * @param bool   $as_list Pass them the way Undo does, as a comma-separated ids parameter.
	 */
	protected function start_core_bulk( string $action, array $ids, bool $as_list = false ): void {
		$GLOBALS['pagenow'] = 'edit.php';
		$_REQUEST['action'] = $action;
		if ( $as_list ) {
			$_REQUEST['ids'] = implode( ',', $ids );
		} else {
			$_REQUEST['post'] = array_map( 'strval', $ids );
		}
	}

	/**
	 * Undoes start_core_bulk().
	 */
	protected function end_core_bulk(): void {
		$GLOBALS['pagenow'] = 'index.php';
		unset( $_REQUEST['action'], $_REQUEST['ids'], $_REQUEST['post'], $_REQUEST['filter_action'] );
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

	public function test_run_covers_only_the_given_ids_and_returns_the_callback_value(): void {
		$seen   = [];
		$result = Guard::run(
			[ 5, 6 ],
			static function () use ( &$seen ): int {
				$seen = [ Guard::covers( 5 ), Guard::covers( 6 ), Guard::covers( 7 ) ];
				return 42;
			}
		);

		$this->assertSame( 42, $result );
		$this->assertSame( [ true, true, false ], $seen );
		$this->assertFalse( Guard::covers( 5 ) );
	}

	public function test_nested_runs_release_an_id_only_at_the_outermost_level(): void {
		$inner = null;
		Guard::run(
			[ 5 ],
			static function () use ( &$inner ): void {
				Guard::run( [ 5 ], static fn() => null );
				$inner = Guard::covers( 5 );
			}
		);

		$this->assertTrue( $inner, 'The outer run still covers the ID after an inner one returns.' );
		$this->assertFalse( Guard::covers( 5 ) );
	}

	public function test_an_exception_releases_the_ids(): void {
		try {
			Guard::run(
				[ 5 ],
				static function (): void {
					throw new RuntimeException( 'boom' );
				}
			);
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'boom', $e->getMessage() );
		}

		$this->assertFalse( Guard::covers( 5 ) );
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

	public function test_both_filters_are_registered_by_the_plugin(): void {
		global $wp_filter;
		foreach ( [
			'wxr_export_skip_postmeta' => 10,
			'wp_untrash_post_status'   => 20,
		] as $hook => $priority ) {
			$found = false;
			foreach ( $wp_filter[ $hook ]->callbacks[ $priority ] ?? [] as $callback ) {
				if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof Markers ) {
					$found = true;
				}
			}
			$this->assertTrue( $found, $hook );
		}
	}

	public function test_a_subpage_trashed_with_its_parent_comes_back_with_its_previous_status(): void {
		$with_parent = self::factory()->post->create( [ 'post_type' => 'page' ] );
		$on_its_own  = self::factory()->post->create( [ 'post_type' => 'page' ] );
		wp_trash_post( $with_parent );
		wp_trash_post( $on_its_own );
		Markers::add( $with_parent, Markers::trashed_key( 9 ), '1' );

		wp_untrash_post( $with_parent );
		wp_untrash_post( $on_its_own );

		$this->assertSame( 'publish', get_post_status( $with_parent ) );
		$this->assertSame( 'draft', get_post_status( $on_its_own ), 'Core restores everything else as a draft.' );
	}

	public function test_markers_are_left_out_of_exports_and_ledger_entries_stay_in(): void {
		$markers = new Markers();
		foreach ( [ Markers::moved_key( 12 ), Markers::trashed_key( 12 ), Markers::ALIASES ] as $key ) {
			$this->assertTrue( $markers->skip_on_export( false, $key ), $key );
		}
		$this->assertFalse( $markers->skip_on_export( false, '_hierarchy_guard_path_abc' ), 'Old-path entries stay exportable.' );
		$this->assertTrue( $markers->skip_on_export( true, 'anything' ), 'An earlier skip is kept.' );
	}

	public function test_moved_values_round_trip(): void {
		$value = Markers::encode_moved( 7, 'team', 'team-2', 1700000000 );

		$this->assertSame( '7|team|team-2|1700000000', $value );
		$this->assertSame(
			[
				'to'       => 7,
				'original' => 'team',
				'assigned' => 'team-2',
				'time'     => 1700000000,
			],
			Markers::decode_moved( $value )
		);
		$this->assertSame( 0, Markers::decode_moved( '' )['to'], 'A damaged value decodes to safe defaults.' );
	}

	public function test_rows_are_read_oldest_first_after_a_cursor_and_removed(): void {
		$a = self::factory()->post->create();
		$b = self::factory()->post->create();
		Markers::add( $a, Markers::moved_key( 9 ), 'first' );
		Markers::add( $b, Markers::moved_key( 9 ), 'second' );
		Markers::add( $b, Markers::moved_key( 10 ), 'other root' );

		$rows = Markers::rows( Markers::moved_key( 9 ), 10 );
		$this->assertSame( [ $a, $b ], array_column( $rows, 'post_id' ) );
		$this->assertSame( [ 'first', 'second' ], array_column( $rows, 'value' ) );
		$this->assertSame( 'first', get_post_meta( $a, Markers::moved_key( 9 ), true ), 'The meta cache sees the row.' );

		$this->assertSame( [ 'second' ], array_column( Markers::rows( Markers::moved_key( 9 ), 10, $rows[0]['meta_id'] ), 'value' ) );
		$this->assertCount( 1, Markers::rows( Markers::moved_key( 9 ), 1 ) );

		Markers::remove( $rows );

		$this->assertSame( [], Markers::rows( Markers::moved_key( 9 ), 10 ) );
		$this->assertSame( '', get_post_meta( $a, Markers::moved_key( 9 ), true ), 'The meta cache is cleared.' );
		$this->assertCount( 1, Markers::rows( Markers::moved_key( 10 ), 10 ), 'Other roots are untouched.' );
	}

	public function test_count_covers_both_kinds_and_stops_at_the_cap(): void {
		$a = self::factory()->post->create();
		$b = self::factory()->post->create();
		Markers::add( $a, Markers::moved_key( 9 ), '1' );
		Markers::add( $b, Markers::moved_key( 9 ), '1' );
		Markers::add( $b, Markers::trashed_key( 9 ), '1' );

		$this->assertSame( 3, Markers::count( 9, 10 ) );
		$this->assertSame( 2, Markers::count( 9, 2 ) );
		$this->assertSame( 0, Markers::count( 10, 10 ) );
	}

	public function test_a_post_s_own_trashed_rows_are_found_and_cleared(): void {
		$a = self::factory()->post->create();
		Markers::add( $a, Markers::trashed_key( 9 ), '1' );
		Markers::add( $a, Markers::moved_key( 9 ), '1' );

		$this->assertTrue( Markers::has_trashed_row( $a ) );
		$this->assertSame( 1, Markers::clear_trashed_rows( $a ) );
		$this->assertFalse( Markers::has_trashed_row( $a ) );
		$this->assertCount( 1, Markers::rows( Markers::moved_key( 9 ), 10 ), 'Moved rows are not trashed rows.' );
		$this->assertSame( 0, Markers::clear_trashed_rows( $a ) );
	}

	public function test_remove_key_deletes_one_post_s_row(): void {
		$a = self::factory()->post->create();
		$b = self::factory()->post->create();
		Markers::add( $a, Markers::trashed_key( 9 ), '1' );
		Markers::add( $b, Markers::trashed_key( 9 ), '1' );

		Markers::remove_key( $a, Markers::trashed_key( 9 ) );

		$this->assertSame( [ $b ], array_column( Markers::rows( Markers::trashed_key( 9 ), 10 ), 'post_id' ) );
	}
}
```

- [ ] **Step 4: Run the tests to verify they fail**

Run: `npm run test:php -- --filter 'GuardTest|MarkersTest'`
Expected: `Error: Class "HierarchyGuard\Trash\Guard" not found` and the same for `Markers`.

- [ ] **Step 5: Create Guard and Markers**

`includes/HierarchyGuard/Trash/Guard.php`:
```php
<?php
/**
 * Re-entrancy guard for the plugin's own trash and restore calls.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Trash;

/**
 * While the plugin trashes or restores a subpage itself, its own hooks must
 * not treat that call as a new editor action. The guard covers only the post
 * IDs the plugin is acting on, so a trash another plugin starts from inside
 * the same request still gets its policy.
 */
final class Guard {

	/**
	 * Covered post IDs and how many nested runs cover each.
	 *
	 * @var array<int,int>
	 */
	private static array $covered = [];

	/**
	 * Runs a callback with the given post IDs covered.
	 *
	 * @param int[]    $ids      Post IDs the callback acts on.
	 * @param callable $callback Work to run.
	 * @return mixed The callback's return value.
	 */
	public static function run( array $ids, callable $callback ) {
		$ids = array_map( 'intval', $ids );
		foreach ( $ids as $id ) {
			self::$covered[ $id ] = ( self::$covered[ $id ] ?? 0 ) + 1;
		}
		try {
			return $callback();
		} finally {
			foreach ( $ids as $id ) {
				if ( --self::$covered[ $id ] <= 0 ) {
					unset( self::$covered[ $id ] );
				}
			}
		}
	}

	/**
	 * Whether the plugin is acting on this post right now.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function covers( int $post_id ): bool {
		return isset( self::$covered[ $post_id ] );
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
 * Post meta that lets a restore undo exactly what the trash flow did.
 *
 * Each marker key carries the ID of the page whose trash caused it, so a
 * restore reads only its own rows with a seek on the meta_key index, and each
 * row is deleted once it has been handled. Rows are written and deleted with
 * direct queries: they are internal, and going through add_post_meta() would
 * bump the site-wide posts cache key once more per subpage. The values are
 * post IDs, which mean nothing on another site, so they never leave in a WXR
 * export.
 */
final class Markers {

	public const MOVED_PREFIX   = '_hierarchy_guard_moved_';
	public const TRASHED_PREFIX = '_hierarchy_guard_trashed_';
	public const ALIASES        = '_hierarchy_guard_aliases';

	/**
	 * Key of the marker set on a subpage moved when $root_id was trashed.
	 *
	 * @param int $root_id The trashed page.
	 * @return string
	 */
	public static function moved_key( int $root_id ): string {
		return self::MOVED_PREFIX . $root_id;
	}

	/**
	 * Key of the marker set on a subpage trashed together with $root_id.
	 *
	 * @param int $root_id The trashed page.
	 * @return string
	 */
	public static function trashed_key( int $root_id ): string {
		return self::TRASHED_PREFIX . $root_id;
	}

	/**
	 * Hooks into core.
	 */
	public function register(): void {
		add_filter( 'wxr_export_skip_postmeta', [ $this, 'skip_on_export' ], 10, 2 );
		add_filter( 'wp_untrash_post_status', [ $this, 'previous_status' ], 20, 3 );
	}

	/**
	 * Gives a subpage trashed together with its parent back the status it had,
	 * however it leaves the trash. Core alone would make it a draft. Core's own
	 * wp_untrash_post_set_previous_status() callback is never added or removed.
	 *
	 * @param mixed $new_status      Status core is about to assign.
	 * @param mixed $post_id         Post being untrashed.
	 * @param mixed $previous_status Status it had before the trash.
	 * @return string
	 */
	public function previous_status( $new_status, $post_id, $previous_status ): string {
		if ( '' !== (string) $previous_status && self::has_trashed_row( (int) $post_id ) ) {
			return (string) $previous_status;
		}
		return (string) $new_status;
	}

	/**
	 * Leaves the markers out of WXR exports. Old-path ledger entries stay in.
	 *
	 * @param mixed $skip     Whether an earlier callback skips the row.
	 * @param mixed $meta_key Meta key of the row.
	 * @return bool
	 */
	public function skip_on_export( $skip, $meta_key ): bool {
		$key = (string) $meta_key;
		return (bool) $skip
			|| str_starts_with( $key, self::MOVED_PREFIX )
			|| str_starts_with( $key, self::TRASHED_PREFIX )
			|| self::ALIASES === $key;
	}

	/**
	 * The value of a moved marker.
	 *
	 * @param int    $to       New parent, 0 for top level.
	 * @param string $original Slug before the move.
	 * @param string $assigned Slug the move gave it.
	 * @param int    $time     Unix time of the move.
	 * @return string
	 */
	public static function encode_moved( int $to, string $original, string $assigned, int $time ): string {
		return implode( '|', [ $to, $original, $assigned, $time ] );
	}

	/**
	 * Reads a moved marker's value. Slugs never contain a pipe.
	 *
	 * @param string $value Stored value.
	 * @return array{to:int,original:string,assigned:string,time:int}
	 */
	public static function decode_moved( string $value ): array {
		$parts = array_pad( explode( '|', $value, 4 ), 4, '' );
		return [
			'to'       => (int) $parts[0],
			'original' => (string) $parts[1],
			'assigned' => (string) $parts[2],
			'time'     => (int) $parts[3],
		];
	}

	/**
	 * Adds a marker row.
	 *
	 * @param int    $post_id Subpage.
	 * @param string $key     Marker key.
	 * @param string $value   Marker value.
	 */
	public static function add( int $post_id, string $key, string $value ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Internal marker row; the post's meta cache is cleared next.
		$wpdb->insert(
			$wpdb->postmeta,
			[
				'post_id'    => $post_id,
				'meta_key'   => $key,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- An insert, not a query on the value.
				'meta_value' => $value,
			],
			[ '%d', '%s', '%s' ]
		);
		wp_cache_delete( $post_id, 'post_meta' );
	}

	/**
	 * Marker rows with a key, oldest first, after a meta_id cursor.
	 *
	 * @param string $key           Marker key.
	 * @param int    $limit         Maximum rows.
	 * @param int    $after_meta_id Only rows above this meta_id.
	 * @return array<int,array{meta_id:int,post_id:int,value:string}>
	 */
	public static function rows( string $key, int $limit, int $after_meta_id = 0 ): array {
		global $wpdb;

		if ( $limit < 1 ) {
			return [];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Seek on the meta_key index, bounded by LIMIT; every step deletes what it read.
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_id, post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_id > %d ORDER BY meta_id ASC LIMIT %d",
				$key,
				$after_meta_id,
				$limit
			),
			ARRAY_A
		);

		$rows = [];
		foreach ( (array) $results as $row ) {
			$rows[] = [
				'meta_id' => (int) $row['meta_id'],
				'post_id' => (int) $row['post_id'],
				'value'   => (string) $row['meta_value'],
			];
		}
		return $rows;
	}

	/**
	 * Deletes handled rows.
	 *
	 * @param array<int,array{meta_id:int,post_id:int,value:string}> $rows Rows from rows().
	 */
	public static function remove( array $rows ): void {
		global $wpdb;

		if ( [] === $rows ) {
			return;
		}

		$meta_ids = implode( ',', array_map( static fn( array $row ): int => (int) $row['meta_id'], $rows ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $meta_ids holds integers only; the meta caches are cleared next.
		$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_id IN ( {$meta_ids} )" );

		$post_ids = array_values( array_unique( array_map( static fn( array $row ): int => (int) $row['post_id'], $rows ) ) );
		wp_cache_delete_multiple( $post_ids, 'post_meta' );
	}

	/**
	 * Deletes one post's rows with a key.
	 *
	 * @param int    $post_id Subpage.
	 * @param string $key     Marker key.
	 */
	public static function remove_key( int $post_id, string $key ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Internal marker row; the post's meta cache is cleared next.
		$wpdb->delete(
			$wpdb->postmeta,
			[
				'post_id'  => $post_id,
				'meta_key' => $key,
			],
			[ '%d', '%s' ]
		);
		wp_cache_delete( $post_id, 'post_meta' );
	}

	/**
	 * How many marker rows point at a root, stopping at a cap.
	 *
	 * @param int $root_id Root page.
	 * @param int $cap     Stop counting at this number.
	 * @return int
	 */
	public static function count( int $root_id, int $cap ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Two seeks on the meta_key index, bounded by LIMIT.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM ( SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key IN ( %s, %s ) LIMIT %d ) AS markers",
				self::moved_key( $root_id ),
				self::trashed_key( $root_id ),
				max( 1, $cap )
			)
		);
	}

	/**
	 * Whether a post was trashed together with some root page. Reads the meta
	 * cache, which core has already loaded whenever a post is being untrashed.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function has_trashed_row( int $post_id ): bool {
		return [] !== self::trashed_keys( $post_id );
	}

	/**
	 * Deletes a post's own trashed-with rows, for when it leaves the trash on its own.
	 *
	 * @param int $post_id Post ID.
	 * @return int Rows deleted.
	 */
	public static function clear_trashed_rows( int $post_id ): int {
		$keys = self::trashed_keys( $post_id );
		foreach ( $keys as $key ) {
			self::remove_key( $post_id, $key );
		}
		return count( $keys );
	}

	/**
	 * A post's trashed-with marker keys.
	 *
	 * @param int $post_id Post ID.
	 * @return string[]
	 */
	private static function trashed_keys( int $post_id ): array {
		$keys = [];
		foreach ( array_keys( (array) get_post_meta( $post_id ) ) as $key ) {
			if ( str_starts_with( (string) $key, self::TRASHED_PREFIX ) ) {
				$keys[] = (string) $key;
			}
		}
		return $keys;
	}
}
```

- [ ] **Step 6: Register the markers in Plugin::register()**

In `includes/HierarchyGuard/Plugin.php`, add after the `Admin\Settings_Section` line:
```php
		( new Trash\Markers() )->register();
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `npm run test:php -- --filter 'GuardTest|MarkersTest'`
Expected: `OK (11 tests, 36 assertions)`.

Run: `npm run test:php` and `composer lint`
Expected: all green; lint exit 0.

- [ ] **Step 8: Commit**

```bash
git add composer.json includes tests
git commit -m "feat: ID-keyed guard, per-root subpage markers and trash-flow test fixtures"
```

---

### Task 2: Bounded subtree queries, the path through the trash, and the mover

**Files:**
- Create: `includes/HierarchyGuard/Subtree.php`, `includes/HierarchyGuard/Trash/Mover.php`
- Modify: `includes/HierarchyGuard/Paths.php`
- Test: `tests/phpunit/SubtreeTest.php`, `tests/phpunit/MoverTest.php`, `tests/phpunit/PathsTest.php`

**Interfaces:**
- Consumes: `Paths::is_trashed_slug()`, `Paths::strip_trashed_suffix()`, `Paths::is_recordable()` (engine); `Trash_Fixtures` (Task 1).
- Produces: `Subtree::COUNT_CAP`; static `count_cap(): int`, `status( int $post_id ): string` (uncached; `''` when missing), `has_live_children( int $parent_id, string $post_type ): bool`, `live_children( int $parent_id, string $post_type, int $limit, int $after_id = 0 ): int[]`, `count_live_children( int $parent_id, string $post_type, int $cap ): int`, `count_live_descendants( int $root_id, string $post_type, int $cap ): int` (returns `$cap + 1` when there are more), `with_live_children( int[] $ids, string $post_type ): int[]`, `nearest_live_ancestor( WP_Post $post ): int`. "Live" means any status except `trash`, `auto-draft` and `inherit`.
- Produces: `Paths::untrashed_uri( WP_Post $post ): string`.
- Produces: `Trash\Mover::move( int $post_id, int $new_parent_id, string $preferred_slug = '' ): array{moved:bool,old_path:string,old_slug:string,slug:string,renamed:bool,recordable:bool}` (`old_path` is the untrashed path before the move, `recordable` is `Paths::is_recordable()` before the move); `Mover::reparent( int $post_id, int $new_parent_id ): bool` (parent only, for a post in the trash).

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
		$extra = $this->page( 'extra', $this->ids['about'] );

		$this->assertSame( [ $this->ids['team'], $this->ids['history'], $extra ], Subtree::live_children( $this->ids['about'], 'page', 10 ) );
		$this->assertSame( [ $this->ids['team'] ], Subtree::live_children( $this->ids['about'], 'page', 1 ) );
		$this->assertSame( [ $this->ids['history'], $extra ], Subtree::live_children( $this->ids['about'], 'page', 10, $this->ids['team'] ) );
		$this->assertSame( [], Subtree::live_children( $this->ids['about'], 'post', 10 ), 'Scoped to the post type.' );
		$this->assertSame( [], Subtree::live_children( $this->ids['about'], 'page', 0 ) );
	}

	public function test_trashed_children_are_not_live_but_drafts_are(): void {
		$draft = $this->page( 'plans', $this->ids['about'], 'draft' );
		$this->trash_raw( $this->ids['history'] );

		$this->assertSame( [ $this->ids['team'], $draft ], Subtree::live_children( $this->ids['about'], 'page', 10 ) );
		$this->assertTrue( Subtree::has_live_children( $this->ids['about'], 'page' ) );
		$this->assertFalse( Subtree::has_live_children( $this->ids['leadership'], 'page' ) );
	}

	public function test_count_live_children_stops_at_the_cap(): void {
		$this->assertSame( 2, Subtree::count_live_children( $this->ids['about'], 'page', 10 ) );
		$this->assertSame( 1, Subtree::count_live_children( $this->ids['about'], 'page', 1 ) );
		$this->assertSame( 0, Subtree::count_live_children( $this->ids['leadership'], 'page', 10 ) );
	}

	public function test_count_live_descendants_reports_one_past_the_cap(): void {
		$this->assertSame( 3, Subtree::count_live_descendants( $this->ids['about'], 'page', 10 ) );
		$this->assertSame( 3, Subtree::count_live_descendants( $this->ids['about'], 'page', 2 ) );
		$this->assertSame( 2, Subtree::count_live_descendants( $this->ids['about'], 'page', 1 ) );
		$this->assertSame( 0, Subtree::count_live_descendants( $this->ids['leadership'], 'page', 10 ) );
	}

	public function test_the_count_cap_is_filterable_with_a_floor_of_one(): void {
		$this->assertSame( 1000, Subtree::count_cap() );
		add_filter( 'hierarchy_guard_count_cap', static fn(): int => 0 );
		$this->assertSame( 1, Subtree::count_cap() );
	}

	public function test_with_live_children_keeps_only_parents(): void {
		$ids = [ $this->ids['team'], $this->ids['history'], $this->ids['leadership'] ];
		$this->assertSame( [ $this->ids['team'] ], Subtree::with_live_children( $ids, 'page' ) );
		$this->assertSame( [], Subtree::with_live_children( [], 'page' ) );
	}

	public function test_status_reads_past_the_object_cache(): void {
		global $wpdb;
		$this->assertSame( 'publish', get_post_status( $this->ids['team'] ), 'Precondition: the post is cached.' );

		$wpdb->update( $wpdb->posts, [ 'post_status' => 'trash' ], [ 'ID' => $this->ids['team'] ] );

		$this->assertSame( 'publish', get_post_status( $this->ids['team'] ), 'Precondition: the cache is stale.' );
		$this->assertSame( 'trash', Subtree::status( $this->ids['team'] ) );
		$this->assertSame( '', Subtree::status( 999999 ) );
	}

	public function test_nearest_live_ancestor_skips_trashed_ones(): void {
		$this->assertSame( $this->ids['team'], Subtree::nearest_live_ancestor( get_post( $this->ids['leadership'] ) ) );

		$this->trash_raw( $this->ids['team'] );
		$this->trash_raw( $this->ids['about'] );

		$this->assertSame( $this->ids['company'], Subtree::nearest_live_ancestor( get_post( $this->ids['leadership'] ) ) );
		$this->assertSame( 0, Subtree::nearest_live_ancestor( get_post( $this->ids['company'] ) ) );
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
		global $wpdb;
		$wpdb->update(
			$wpdb->posts,
			[
				'post_modified'     => '2001-01-01 00:00:00',
				'post_modified_gmt' => '2001-01-01 00:00:00',
			],
			[ 'ID' => $this->ids['team'] ]
		);
		clean_post_cache( $this->ids['team'] );

		$result = $this->mover->move( $this->ids['team'], $this->ids['company'] );

		$this->assertTrue( $result['moved'] );
		$this->assertSame( $this->ids['company'], (int) get_post( $this->ids['team'] )->post_parent );
		$this->assertSame( '2001-01-01 00:00:00', get_post( $this->ids['team'] )->post_modified );
		$this->assertSame( 'company/about/team', $result['old_path'] );
		$this->assertSame( 'team', $result['slug'] );
		$this->assertFalse( $result['renamed'] );
		$this->assertTrue( $result['recordable'] );
	}

	public function test_a_taken_slug_gets_a_number(): void {
		$this->page( 'team', $this->ids['company'] );

		$result = $this->mover->move( $this->ids['team'], $this->ids['company'] );

		$this->assertSame( 'team-2', get_post( $this->ids['team'] )->post_name );
		$this->assertTrue( $result['renamed'] );
		$this->assertSame( 'team', $result['old_slug'] );
		$this->assertSame( 'team-2', $result['slug'] );
	}

	public function test_a_draft_gets_a_unique_slug_too(): void {
		$this->page( 'team', $this->ids['company'] );
		$draft = $this->page( 'team', $this->ids['history'], 'draft' );

		$result = $this->mover->move( $draft, $this->ids['company'] );

		$this->assertSame( 'team-2', $result['slug'], 'A duplicate draft slug would hide the published page.' );
		$this->assertFalse( $result['recordable'], 'A draft has no public URL to record.' );
	}

	public function test_the_old_path_ignores_the_trash_suffix(): void {
		$this->trash_raw( $this->ids['about'] );
		$this->assertSame( 'company/about/team', $this->mover->move( $this->ids['team'], $this->ids['company'] )['old_path'] );
	}

	public function test_a_preferred_slug_is_used_when_free(): void {
		$taken = $this->page( 'team', $this->ids['company'] );
		$this->mover->move( $this->ids['team'], $this->ids['company'] );
		$this->assertSame( 'team-2', get_post( $this->ids['team'] )->post_name, 'Precondition.' );

		wp_delete_post( $taken, true );
		$this->mover->move( $this->ids['team'], $this->ids['about'], 'team' );

		$this->assertSame( 'team', get_post( $this->ids['team'] )->post_name );
	}

	public function test_reparent_changes_only_the_parent(): void {
		$this->trash_raw( $this->ids['team'] );

		$this->assertTrue( $this->mover->reparent( $this->ids['team'], $this->ids['company'] ) );

		$this->assertSame( $this->ids['company'], (int) get_post( $this->ids['team'] )->post_parent );
		$this->assertSame( 'team__trashed', get_post( $this->ids['team'] )->post_name );
	}

	public function test_a_missing_post_is_reported_unmoved(): void {
		$this->assertFalse( $this->mover->move( 999999, 0 )['moved'] );
	}
}
```

Add to the end of the class in `tests/phpunit/PathsTest.php`:
```php
	public function test_untrashed_uri_restores_trashed_segments(): void {
		global $wpdb;

		$company = self::factory()->post->create(
			[
				'post_type' => 'page',
				'post_name' => 'company',
			]
		);
		$about   = self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_name'   => 'about',
				'post_parent' => $company,
			]
		);
		$team    = self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_name'   => 'team',
				'post_parent' => $about,
			]
		);

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

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npm run test:php -- --filter 'SubtreeTest|MoverTest|PathsTest'`
Expected: `Error: Class "HierarchyGuard\Subtree" not found`, the same for `Trash\Mover`, and `Call to undefined method HierarchyGuard\Paths::untrashed_uri()`.

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

use WP_Post;

/**
 * Every query here is bounded by a limit or a cap, never by the size of the
 * subtree. "Live" means any status except trash and core's internal
 * auto-draft and inherit. Statuses are read straight from the database
 * where a long-running batch must not trust a cached post.
 */
final class Subtree {

	public const COUNT_CAP = 1000;

	/**
	 * The most a subtree count reports exactly.
	 *
	 * @return int
	 */
	public static function count_cap(): int {
		/**
		 * Filters the cap on subtree counts.
		 *
		 * @param int $cap Largest count reported exactly, 1,000 by default.
		 */
		return max( 1, (int) apply_filters( 'hierarchy_guard_count_cap', self::COUNT_CAP ) );
	}

	/**
	 * A post's status read from the database, bypassing the object cache.
	 *
	 * @param int $post_id Post ID.
	 * @return string The status, or an empty string when the post does not exist.
	 */
	public static function status( int $post_id ): string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- A batch must see a restore that happened in another request.
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT post_status FROM {$wpdb->posts} WHERE ID = %d", $post_id ) );
	}

	/**
	 * Whether a post has at least one live child of its type.
	 *
	 * @param int    $parent_id Parent post ID.
	 * @param string $post_type Post type name.
	 * @return bool
	 */
	public static function has_live_children( int $parent_id, string $post_type ): bool {
		return [] !== self::live_children( $parent_id, $post_type, 1 );
	}

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

		if ( $limit < 1 ) {
			return [];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Cursor over the post_parent index; every batch writes, so a cache would serve stale pages.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = %s AND post_status NOT IN ( 'trash', 'auto-draft', 'inherit' ) AND ID > %d ORDER BY ID ASC LIMIT %d",
				$parent_id,
				$post_type,
				$after_id,
				$limit
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Number of live direct children, stopping at a cap.
	 *
	 * @param int    $parent_id Parent post ID.
	 * @param string $post_type Post type name.
	 * @param int    $cap       Stop counting at this number.
	 * @return int
	 */
	public static function count_live_children( int $parent_id, string $post_type, int $cap ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Index range on post_parent, bounded by LIMIT.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM ( SELECT ID FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = %s AND post_status NOT IN ( 'trash', 'auto-draft', 'inherit' ) LIMIT %d ) AS children",
				$parent_id,
				$post_type,
				max( 1, $cap )
			)
		);
	}

	/**
	 * Counts live descendants level by level, stopping once the count passes the cap.
	 *
	 * @param int    $root_id   Root post ID.
	 * @param string $post_type Post type name.
	 * @param int    $cap       Stop counting past this number.
	 * @return int The count, or $cap + 1 when there are more.
	 */
	public static function count_live_descendants( int $root_id, string $post_type, int $cap ): int {
		global $wpdb;

		$cap   = max( 1, $cap );
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

		return min( $count, $cap + 1 );
	}

	/**
	 * Which of the given posts have at least one live child.
	 *
	 * @param int[]  $ids       Post IDs, at most a batch.
	 * @param string $post_type Post type name.
	 * @return int[]
	 */
	public static function with_live_children( array $ids, string $post_type ): array {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( [] === $ids ) {
			return [];
		}

		$in = implode( ',', $ids );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in holds integers only, at most one batch of them.
		$parents = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_parent FROM {$wpdb->posts} WHERE post_parent IN ( {$in} ) AND post_type = %s AND post_status NOT IN ( 'trash', 'auto-draft', 'inherit' )", $post_type ) );

		$parents = array_map( 'intval', $parents );
		return array_values( array_filter( $ids, static fn( int $id ): bool => in_array( $id, $parents, true ) ) );
	}

	/**
	 * The closest ancestor that is not in the trash, or 0 for top level.
	 *
	 * @param WP_Post $post Post object.
	 * @return int
	 */
	public static function nearest_live_ancestor( WP_Post $post ): int {
		foreach ( get_post_ancestors( $post ) as $ancestor_id ) {
			$status = self::status( (int) $ancestor_id );
			if ( '' !== $status && 'trash' !== $status ) {
				return (int) $ancestor_id;
			}
		}
		return 0;
	}
}
```

- [ ] **Step 4: Add untrashed_uri() to Paths**

In `includes/HierarchyGuard/Paths.php`, replace the whole `uri()` method (docblock included) with these four methods. The loop moves into `chain()` so both public methods share it:
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

- [ ] **Step 5: Create Mover**

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
 * re-filtered and the modified date stays, then a cache clear. The slug is
 * made unique under the new parent first.
 */
final class Mover {

	/**
	 * Moves a post and makes its slug unique under the new parent.
	 *
	 * @param int    $post_id        Post to move.
	 * @param int    $new_parent_id  New parent, 0 for top level.
	 * @param string $preferred_slug Slug to try instead of the current one, such as the one it had before an earlier move.
	 * @return array{moved:bool,old_path:string,old_slug:string,slug:string,renamed:bool,recordable:bool}
	 */
	public function move( int $post_id, int $new_parent_id, string $preferred_slug = '' ): array {
		global $wpdb;

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return [
				'moved'      => false,
				'old_path'   => '',
				'old_slug'   => '',
				'slug'       => '',
				'renamed'    => false,
				'recordable' => false,
			];
		}

		$old_path   = Paths::untrashed_uri( $post );
		$recordable = Paths::is_recordable( $post );
		$old_slug   = (string) $post->post_name;
		$slug       = '' !== $preferred_slug ? $preferred_slug : $old_slug;
		if ( '' !== $slug ) {
			// Core skips the uniqueness check for drafts and pending posts. A duplicate
			// path would let an older draft hide a published page from visitors.
			$status = in_array( $post->post_status, [ 'draft', 'pending' ], true ) ? 'publish' : (string) $post->post_status;
			$slug   = wp_unique_post_slug( $slug, $post_id, $status, (string) $post->post_type, $new_parent_id );
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

		return [
			'moved'      => false !== $updated,
			'old_path'   => $old_path,
			'old_slug'   => $old_slug,
			'slug'       => $slug,
			'renamed'    => $slug !== $old_slug,
			'recordable' => $recordable,
		];
	}

	/**
	 * Changes only the parent, for a post that sits in the trash.
	 *
	 * @param int $post_id       Post to move.
	 * @param int $new_parent_id New parent.
	 * @return bool Whether the update ran.
	 */
	public function reparent( int $post_id, int $new_parent_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Mirrors wp_delete_post()'s re-parent; the post cache is cleared next.
		$updated = $wpdb->update( $wpdb->posts, [ 'post_parent' => $new_parent_id ], [ 'ID' => $post_id ] );
		clean_post_cache( $post_id );

		return false !== $updated;
	}
}
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `npm run test:php -- --filter 'SubtreeTest|MoverTest|PathsTest'`
Expected: `OK (24 tests, 79 assertions)`.

Run: `npm run test:php` and `composer lint`
Expected: all green; lint exit 0.

- [ ] **Step 7: Commit**

```bash
git add includes tests
git commit -m "feat: bounded subtree queries, untrashed paths and a raw re-parent with unique slugs"
```

---

### Task 3: Ledger aliases, the delete listener and the resolver rules

**Files:**
- Modify: `includes/HierarchyGuard/Ledger.php`, `includes/HierarchyGuard/Change_Listener.php`, `includes/HierarchyGuard/Redirects/Resolver.php`
- Test: `tests/phpunit/LedgerTest.php`, `tests/phpunit/ChangeListenerTest.php`, `tests/phpunit/ResolverTest.php`

**Interfaces:**
- Consumes: `Paths::untrashed_uri()` (Task 2); `Markers::has_trashed_row()`, `Markers::add()`, `Markers::trashed_key()` (Task 1).
- Produces: `Ledger::record_root()` now returns `bool` (true when written); `Ledger::record_alias( string $post_type, int $new_parent_id, string $old_path ): bool`; `Ledger::forget( int $post_id, string $old_path ): bool`; `Ledger::forget_root( string $post_type, string $old_path ): bool`; `Ledger::forget_alias( string $post_type, int $new_parent_id, string $old_path ): bool`; static `Ledger::deferred( callable $callback ): mixed` (cache invalidation held back, then done once); `Ledger::held_in_trash( string $post_type, string $path ): bool`. `Ledger::find()` now ignores entries held by posts in the trash, falling back to the root list. `Change_Listener::pre_trash_uri()` is removed.

The trash flow (Task 5) records each alias path on the trashed page itself, so `held_in_trash()` tells the resolver that the page at an exact old parent path is only in the trash.

- [ ] **Step 1: Write the failing tests**

Add to the end of the class in `tests/phpunit/LedgerTest.php`:
```php
	public function test_record_alias_writes_on_the_new_parent_or_the_root_list(): void {
		$this->assertTrue( $this->ledger->record_alias( 'page', $this->about, 'about/team-old' ) );
		$this->assertSame( $this->about, $this->ledger->find( 'page', 'about/team-old' ) );

		$this->assertTrue( $this->ledger->record_alias( 'page', 0, 'gone' ) );
		$this->assertSame( 0, $this->ledger->find( 'page', 'gone' ) );
		$this->assertFalse( $this->ledger->record_alias( 'page', 0, 'gone' ), 'Already recorded.' );
	}

	public function test_forget_alias_removes_what_record_alias_wrote(): void {
		$this->ledger->record_alias( 'page', $this->about, 'about/old' );
		$this->ledger->record_alias( 'page', 0, 'gone' );
		$this->ledger->record_root( 'page', 'kept' );

		$this->assertTrue( $this->ledger->forget_alias( 'page', $this->about, '/About/Old/' ) );
		$this->assertTrue( $this->ledger->forget_alias( 'page', 0, 'gone' ) );

		$this->assertNull( $this->ledger->find( 'page', 'about/old' ) );
		$this->assertNull( $this->ledger->find( 'page', 'gone' ) );
		$this->assertSame( 0, $this->ledger->find( 'page', 'kept' ), 'Other root entries stay.' );
		$this->assertFalse( $this->ledger->forget_alias( 'page', 0, 'gone' ) );
		$this->assertFalse( $this->ledger->forget( 999999, 'about/old' ) );
	}

	public function test_deferred_invalidates_cached_lookups_once_at_the_end(): void {
		$this->assertNull( $this->ledger->find( 'page', 'one' ), 'Precondition: a cached miss.' );
		$before = wp_cache_get_last_changed( Ledger::CACHE_GROUP );
		$inside = null;

		Ledger::deferred(
			function () use ( &$inside ): void {
				$this->ledger->record( $this->about, 'one' );
				$this->ledger->record( $this->about, 'two' );
				$inside = wp_cache_get_last_changed( Ledger::CACHE_GROUP );
			}
		);

		$this->assertSame( $before, $inside, 'No invalidation while deferred.' );
		$this->assertNotSame( $before, wp_cache_get_last_changed( Ledger::CACHE_GROUP ) );
		$this->assertSame( $this->about, $this->ledger->find( 'page', 'one' ), 'The cached miss is gone.' );
	}

	public function test_a_live_holder_wins_and_a_trashed_holder_is_found(): void {
		$other = self::factory()->post->create(
			[
				'post_type' => 'page',
				'post_name' => 'other',
			]
		);
		$this->ledger->record( $other, 'shared' );
		$this->ledger->record( $this->about, 'shared' );
		wp_trash_post( $this->about );

		$this->assertSame( $other, $this->ledger->find( 'page', 'shared' ), 'The newer entry is on a page in the trash.' );
		wp_trash_post( $other );
		$this->ledger->record_root( 'page', 'shared' );
		$this->assertSame( 0, $this->ledger->find( 'page', 'shared' ), 'With every holder in the trash, the root list answers.' );
		$this->assertTrue( $this->ledger->held_in_trash( 'page', '/Shared/' ) );
		$this->assertFalse( $this->ledger->held_in_trash( 'page', 'elsewhere' ) );
		$this->assertFalse( $this->ledger->held_in_trash( 'page', '' ) );
	}
```

In `tests/phpunit/ChangeListenerTest.php`, add `use HierarchyGuard\Trash\Markers;` after `use HierarchyGuard\Settings;`, and add to the end of the class:
```php
	public function test_emptying_a_trashed_tree_keeps_paths_through_trashed_ancestors(): void {
		$leadership = self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_name'   => 'leadership',
				'post_parent' => $this->team,
			]
		);

		wp_trash_post( $this->about );
		wp_trash_post( $this->team );
		$this->assertSame( $this->about, (int) get_post( $this->team )->post_parent, 'Precondition: both ancestors are in the trash.' );

		// The trash empties child-first: team, then about.
		wp_delete_post( $this->team, true );
		wp_delete_post( $this->about, true );

		$this->assertSame( $this->company, (int) get_post( $leadership )->post_parent, 'Precondition: core moved leadership up twice.' );
		$resolved = ( new Resolver( $this->ledger ) )->resolve( 'page', 'company/about/team/leadership' );
		$this->assertNotNull( $resolved );
		$this->assertSame( $leadership, $resolved->ID );
	}

	public function test_deleting_a_subpage_trashed_with_its_parent_records_nothing(): void {
		self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_name'   => 'leadership',
				'post_parent' => $this->team,
			]
		);
		Markers::add( $this->team, Markers::trashed_key( $this->about ), '1' );

		wp_delete_post( $this->team, true );

		$this->assertSame( [], $this->ledger->paths_for( $this->about ) );
	}
```

In `tests/phpunit/ResolverTest.php`, `test_a_trashed_ledger_hit_gives_way_to_a_shorter_prefix()`, replace the precondition line `$this->assertSame( $this->about, $this->ledger->find( 'page', 'company/about' ), 'Precondition: the longest prefix maps to the trashed page.' );` with:
```php
		$this->assertTrue( $this->ledger->held_in_trash( 'page', 'company/about' ), 'Precondition: the longest prefix is held only by the trashed page.' );
```
and add to the end of the class:
```php
	public function test_a_hit_under_a_trashed_ancestor_gives_way_to_a_shorter_prefix(): void {
		wp_update_post(
			[
				'ID'        => $this->team,
				'post_name' => 'crew',
			]
		);
		wp_trash_post( $this->about );
		$this->assertSame( 'company/about__trashed/crew', get_page_uri( $this->team ), 'Precondition: the ledger hit lives under the trash.' );

		// Another route still leads somewhere live: company/about now maps to company.
		$replacement = self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_name'   => 'team',
				'post_parent' => $this->company,
			]
		);
		$this->ledger->record_alias( 'page', $this->company, 'company/about' );

		$target = $this->resolver->resolve( 'page', 'company/about/team' );
		$this->assertNotNull( $target );
		$this->assertSame( $replacement, $target->ID );
	}

	public function test_an_old_parent_path_whose_page_is_only_in_the_trash_does_not_redirect(): void {
		global $wpdb;

		// What the trash flow leaves behind: about in the trash, team moved up, the alias on company, and about's own path on about.
		wp_trash_post( $this->about );
		$wpdb->update( $wpdb->posts, [ 'post_parent' => $this->company ], [ 'ID' => $this->team ] );
		clean_post_cache( $this->team );
		$this->ledger->record_alias( 'page', $this->company, 'company/about' );
		$this->ledger->record( $this->about, 'company/about' );

		$this->assertSame( $this->team, $this->resolver->resolve( 'page', 'company/about/team' )->ID, 'Subpage URLs still resolve.' );
		$this->assertNull( $this->resolver->resolve( 'page', 'company/about' ), 'A browser would keep a 301 to the grandparent after a restore.' );

		wp_delete_post( $this->about, true );

		$this->assertSame( $this->company, $this->resolver->resolve( 'page', 'company/about' )->ID, 'Once the page is gone for good, its old URL leads to its parent.' );
	}

	public function test_an_older_path_of_a_page_in_the_trash_does_not_redirect_either(): void {
		global $wpdb;

		$this->ledger->record( $this->about, 'company/about-us' );
		wp_trash_post( $this->about );
		$wpdb->update( $wpdb->posts, [ 'post_parent' => $this->company ], [ 'ID' => $this->team ] );
		clean_post_cache( $this->team );
		foreach ( [ 'company/about', 'company/about-us' ] as $path ) {
			$this->ledger->record_alias( 'page', $this->company, $path );
			$this->ledger->record( $this->about, $path );
		}

		$this->assertSame( $this->team, $this->resolver->resolve( 'page', 'company/about-us/team' )->ID, 'The live alias wins over the trashed page holding the same path.' );
		$this->assertNull( $this->resolver->resolve( 'page', 'company/about-us' ) );
	}

	public function test_a_page_in_the_trash_under_a_trashed_parent_does_not_redirect(): void {
		global $wpdb;

		$leadership = self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_name'   => 'leadership',
				'post_parent' => $this->team,
			]
		);
		wp_trash_post( $this->about );
		wp_trash_post( $this->team );
		// What the trash flow leaves when team's subpages move past both trashed pages.
		$wpdb->update( $wpdb->posts, [ 'post_parent' => $this->company ], [ 'ID' => $leadership ] );
		clean_post_cache( $leadership );
		$this->ledger->record_alias( 'page', $this->company, 'company/about/team' );
		$this->ledger->record( $this->team, 'company/about/team' );

		$this->assertSame( $leadership, $this->resolver->resolve( 'page', 'company/about/team/leadership' )->ID );
		$this->assertNull( $this->resolver->resolve( 'page', 'company/about/team' ) );
	}

	public function test_a_top_level_page_in_the_trash_does_not_hide_its_root_list_alias(): void {
		global $wpdb;

		// What the trash flow leaves when a top-level page's subpages become top level.
		wp_trash_post( $this->company );
		$wpdb->update( $wpdb->posts, [ 'post_parent' => 0 ], [ 'ID' => $this->about ] );
		clean_post_cache( $this->about );
		$this->ledger->record_alias( 'page', 0, 'company' );
		$this->ledger->record( $this->company, 'company' );

		$this->assertSame( $this->about, $this->resolver->resolve( 'page', 'company/about' )->ID );
		$this->assertSame( $this->team, $this->resolver->resolve( 'page', 'company/about/team' )->ID );
		$this->assertNull( $this->resolver->resolve( 'page', 'company' ) );
	}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npm run test:php -- --filter 'LedgerTest|ChangeListenerTest|ResolverTest'`
Expected: `Call to undefined method HierarchyGuard\Ledger::record_alias()` (and `forget_alias()`, `deferred()`, `held_in_trash()`), and failures in `test_emptying_a_trashed_tree_keeps_paths_through_trashed_ancestors` (resolves to null) and `test_deleting_a_subpage_trashed_with_its_parent_records_nothing` (a path is recorded on about).

- [ ] **Step 3: Extend the Ledger**

In `includes/HierarchyGuard/Ledger.php`:

After `private const CACHE_TTL  = 12 * HOUR_IN_SECONDS;`, add:
```php

	/**
	 * Nesting depth of deferred() calls.
	 *
	 * @var int
	 */
	private static int $defer_depth = 0;

	/**
	 * Whether a write happened inside deferred().
	 *
	 * @var bool
	 */
	private static bool $bump_pending = false;
```

Replace the whole `record_root()` method (docblock included) with these six methods:
```php
	/**
	 * Records an old parent path whose children became top level.
	 *
	 * @param string $post_type Post type name.
	 * @param string $old_path  Old path, any form; normalized here.
	 * @return bool True when a new entry was written.
	 */
	public function record_root( string $post_type, string $old_path ): bool {
		$old_path = Paths::normalize( $old_path );
		if ( '' === $old_path ) {
			return false;
		}

		$roots = $this->roots();
		$list  = $roots[ $post_type ] ?? [];
		if ( in_array( $old_path, $list, true ) ) {
			return false;
		}

		$list[] = $old_path;
		if ( count( $list ) > self::ROOT_CAP ) {
			$list = array_slice( $list, -self::ROOT_CAP );
		}
		$roots[ $post_type ] = array_values( $list );

		update_option( self::ROOT_OPTION, $roots, false );
		$this->bump();

		return true;
	}

	/**
	 * Records that children which lived under $old_path now live under a new
	 * parent, or became top level when it is 0.
	 *
	 * @param string $post_type     Post type name.
	 * @param int    $new_parent_id New parent ID, 0 for top level.
	 * @param string $old_path      The old parent path, any form.
	 * @return bool True when a new entry was written.
	 */
	public function record_alias( string $post_type, int $new_parent_id, string $old_path ): bool {
		if ( $new_parent_id > 0 ) {
			return $this->record( $new_parent_id, $old_path );
		}
		return $this->record_root( $post_type, $old_path );
	}

	/**
	 * Removes an entry from a post.
	 *
	 * @param int    $post_id  Post the entry is on.
	 * @param string $old_path Old path, any form.
	 * @return bool True when an entry was removed.
	 */
	public function forget( int $post_id, string $old_path ): bool {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return false;
		}

		$key = self::key( (string) $post->post_type, Paths::normalize( $old_path ) );
		if ( ! metadata_exists( 'post', $post_id, $key ) ) {
			return false;
		}

		delete_post_meta( $post_id, $key );
		$this->bump();

		return true;
	}

	/**
	 * Removes a path from the root list.
	 *
	 * @param string $post_type Post type name.
	 * @param string $old_path  Old path, any form.
	 * @return bool True when an entry was removed.
	 */
	public function forget_root( string $post_type, string $old_path ): bool {
		$old_path = Paths::normalize( $old_path );
		$roots    = $this->roots();
		$list     = $roots[ $post_type ] ?? [];
		if ( ! in_array( $old_path, $list, true ) ) {
			return false;
		}

		$roots[ $post_type ] = array_values( array_diff( $list, [ $old_path ] ) );
		update_option( self::ROOT_OPTION, $roots, false );
		$this->bump();

		return true;
	}

	/**
	 * Removes what record_alias() wrote.
	 *
	 * @param string $post_type     Post type name.
	 * @param int    $new_parent_id The parent the alias was recorded on, 0 for the root list.
	 * @param string $old_path      The old parent path.
	 * @return bool True when an entry was removed.
	 */
	public function forget_alias( string $post_type, int $new_parent_id, string $old_path ): bool {
		if ( $new_parent_id > 0 ) {
			return $this->forget( $new_parent_id, $old_path );
		}
		return $this->forget_root( $post_type, $old_path );
	}

	/**
	 * Runs a callback with cache invalidation held back, then invalidates once.
	 * Batches write many entries; each would otherwise drop every cached lookup.
	 *
	 * @param callable $callback Work that writes to the ledger.
	 * @return mixed The callback's return value.
	 */
	public static function deferred( callable $callback ) {
		++self::$defer_depth;
		try {
			return $callback();
		} finally {
			--self::$defer_depth;
			if ( 0 === self::$defer_depth && self::$bump_pending ) {
				self::$bump_pending = false;
				wp_cache_set_last_changed( self::CACHE_GROUP );
			}
		}
	}
```

Replace the `bump()` method (docblock included) with:
```php
	/**
	 * Invalidates every cached lookup, or notes that it is due inside deferred().
	 */
	private function bump(): void {
		if ( self::$defer_depth > 0 ) {
			self::$bump_pending = true;
			return;
		}
		wp_cache_set_last_changed( self::CACHE_GROUP );
	}
```

Add this method after `find()`:
```php
	/**
	 * Whether a post in the trash holds this path. The trash flow records a
	 * trashed page's own paths on it, so a hit here means the page at an old
	 * parent path is only in the trash.
	 *
	 * @param string $post_type Post type name.
	 * @param string $path      Path, any form; normalized here.
	 * @return bool
	 */
	public function held_in_trash( string $post_type, string $path ): bool {
		global $wpdb;

		$path = Paths::normalize( $path );
		if ( '' === $path ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Point lookup on the meta_key index; only for a 404 on an exact old parent path.
		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND p.post_type = %s AND p.post_status = 'trash' LIMIT 1",
				self::key( $post_type, $path ),
				$post_type
			)
		);

		return null !== $id;
	}
```

In `seek()`, change the docblock's first line to `Single index seek on the hashed key, newest entry first. Posts in the trash do not count: their entries mean the page is only in the trash, which held_in_trash() answers.` and the query's condition from `WHERE pm.meta_key = %s AND p.post_type = %s ORDER BY pm.meta_id DESC LIMIT 1` to:
```php
WHERE pm.meta_key = %s AND p.post_type = %s AND p.post_status <> 'trash' ORDER BY pm.meta_id DESC LIMIT 1
```

- [ ] **Step 4: Use untrashed_uri() and record_alias() in Change_Listener**

In `includes/HierarchyGuard/Change_Listener.php`:

Add `use HierarchyGuard\Trash\Markers;` before `use WP_Post;`.

Change the docblock line of `$suspended` from `When true, nothing is recorded. The trash handler sets this around its own writes.` to:
```php
	 * When true, nothing is recorded: for code that changes many posts and records their paths itself.
```

In `on_before_delete()`, replace everything from the line `$paths = array_merge( [ Paths::uri( $post ), $this->pre_trash_uri( $post ) ], $this->ledger->paths_for( $post_id ) );` through the method's closing brace with:
```php
		// Subpages trashed together with a parent are deleted with it, so their own
		// paths would be carried onto pages about to be deleted too.
		if ( Markers::has_trashed_row( $post_id ) ) {
			return;
		}

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
	}
```

Then delete the `pre_trash_uri()` method with its docblock, leaving one blank line between `on_before_delete()` and `has_children()`; `Paths::untrashed_uri()` replaces it.

- [ ] **Step 5: Add the two resolver rules**

In `includes/HierarchyGuard/Redirects/Resolver.php`:

Replace the docblock sentence `Ledger hits on trashed posts are skipped so a shorter prefix can match.` in `rewrite_once()` with:
```php
	 * Ledger hits on trashed posts, or on posts under a trashed ancestor, are
	 * skipped so a shorter prefix can match. An exact old parent path whose page
	 * is only in the trash resolves to nothing: a 301 to the grandparent would
	 * stay in browsers after the page is restored.
```

In `rewrite_once()`, change:
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
				if ( count( $segments ) === $length && $this->ledger->held_in_trash( $post_type, $prefix ) ) {
					return null;
				}
			}
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `npm run test:php -- --filter 'LedgerTest|ChangeListenerTest|ResolverTest'`
Expected: `OK (52 tests, 134 assertions)`, including the engine's existing pre-trash, numbered-slug and strip-suffix tests, which now run through `untrashed_uri()`.

Run: `npm run test:php` and `composer lint`
Expected: all green; lint exit 0.

- [ ] **Step 7: Commit**

```bash
git add includes tests
git commit -m "feat: ledger aliases and deferral, paths through trashed ancestors, no redirect for a merely trashed page"
```

---

### Task 4: Change summary and core's bulk selection

**Files:**
- Create: `includes/HierarchyGuard/Trash/Change_Log.php`, `includes/HierarchyGuard/Trash/Request.php`
- Test: `tests/phpunit/ChangeLogTest.php`, `tests/phpunit/RequestTest.php`

**Interfaces:**
- Consumes: `Trash_Fixtures::start_core_bulk()`, `end_core_bulk()` (Task 1).
- Produces: `Trash\Change_Log` constants `TTL`, `MAX_IDS`, `MAX_KEPT`, `COUNT_KEYS`; static `key( int $user_id ): string`, `empty_counts(): array<string,int>`, `reset_request(): void`; instance `record( int $user_id, string $action, string $post_type, array $entry, int[] $ids = [] ): string` (change ID, `''` for user 0), `update( int $user_id, string $change_id, int $root_id, array $counts, int[] $kept_ids, int[] $ids, bool $running ): void`, `latest( int $user_id ): ?array`, `mark_shown( int $user_id, string $change_id ): void`, `forget( int $user_id ): void`. Entry keys: `root`, `to`, `policy`, `fallback`, `counts`, `kept_ids`, `urls`, `job`.
- Produces: `Trash\Request::core_ids(): int[]`.

- [ ] **Step 1: Write the failing tests**

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
		Change_Log::reset_request();
		$this->log   = new Change_Log();
		$this->admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
	}

	/**
	 * A root entry.
	 *
	 * @param int               $root   Root ID.
	 * @param array<string,int> $counts Counts.
	 * @param bool              $job    Whether a job is running.
	 * @return array<string,mixed>
	 */
	private function entry( int $root, array $counts, bool $job = false ): array {
		return [
			'root'     => $root,
			'to'       => 7,
			'policy'   => 'reparent',
			'fallback' => false,
			'counts'   => $counts,
			'kept_ids' => [],
			'urls'     => [
				'count'  => 3,
				'capped' => false,
			],
			'job'      => $job,
		];
	}

	public function test_records_one_change_with_its_root(): void {
		$id = $this->log->record( $this->admin, 'trash', 'page', $this->entry( 12, [ 'moved' => 2 ] ), [ 3, 4 ] );

		$change = $this->log->latest( $this->admin );
		$this->assertSame( $id, $change['id'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f-]{36}$/', $id );
		$this->assertSame( 'trash', $change['action'] );
		$this->assertSame( 'page', $change['post_type'] );
		$this->assertSame( [ 12 ], array_keys( $change['roots'] ) );
		$this->assertSame( 7, $change['roots'][12]['to'] );
		$this->assertSame( 2, $change['counts']['moved'] );
		$this->assertSame( 0, $change['counts']['trashed'], 'Every count key is present.' );
		$this->assertSame( [ 3, 4 ], $change['ids'] );
		$this->assertFalse( $change['shown'] );
		$this->assertIsInt( $change['time'] );
	}

	public function test_roots_recorded_in_one_request_share_a_change(): void {
		$first  = $this->log->record( $this->admin, 'trash', 'page', $this->entry( 12, [ 'moved' => 2 ] ), [ 3, 4 ] );
		$second = $this->log->record( $this->admin, 'trash', 'page', $this->entry( 20, [ 'moved' => 3 ] ), [ 4, 5 ] );

		$change = $this->log->latest( $this->admin );
		$this->assertSame( $first, $second );
		$this->assertSame( [ 12, 20 ], array_keys( $change['roots'] ) );
		$this->assertSame( 5, $change['counts']['moved'] );
		$this->assertSame( [ 3, 4, 5 ], $change['ids'] );
	}

	public function test_a_new_request_or_action_starts_a_new_change(): void {
		$first = $this->log->record( $this->admin, 'trash', 'page', $this->entry( 12, [] ) );
		Change_Log::reset_request();
		$second = $this->log->record( $this->admin, 'trash', 'page', $this->entry( 20, [] ) );
		$third  = $this->log->record( $this->admin, 'restore', 'page', $this->entry( 20, [] ) );

		$this->assertNotSame( $first, $second );
		$this->assertNotSame( $second, $third );
		$this->assertSame( [ 20 ], array_keys( $this->log->latest( $this->admin )['roots'] ) );
	}

	public function test_update_refreshes_a_root_while_the_change_lasts(): void {
		$id = $this->log->record( $this->admin, 'trash', 'page', $this->entry( 12, [ 'moved' => 200 ], true ), [ 1 ] );

		$this->log->update( $this->admin, $id, 12, [ 'moved' => 1200 ], [ 9 ], [ 2 ], false );

		$change = $this->log->latest( $this->admin );
		$this->assertSame( 1200, $change['roots'][12]['counts']['moved'] );
		$this->assertSame( 1200, $change['counts']['moved'] );
		$this->assertFalse( $change['roots'][12]['job'] );
		$this->assertSame( [ 9 ], $change['roots'][12]['kept_ids'] );
		$this->assertSame( 7, $change['roots'][12]['to'], 'Fields the batch does not own are kept.' );
		$this->assertSame( [ 1, 2 ], $change['ids'] );

		$this->log->update( $this->admin, 'another-change', 12, [ 'moved' => 5 ], [], [], false );
		$this->assertSame( 1200, $this->log->latest( $this->admin )['counts']['moved'], 'A replaced change is left alone.' );
	}

	public function test_ids_and_kept_ids_are_capped(): void {
		$entry             = $this->entry( 12, [] );
		$entry['kept_ids'] = range( 1, 50 );
		$this->log->record( $this->admin, 'trash', 'page', $entry, range( 1, 250 ) );

		$change = $this->log->latest( $this->admin );
		$this->assertCount( Change_Log::MAX_IDS, $change['ids'] );
		$this->assertCount( Change_Log::MAX_KEPT, $change['roots'][12]['kept_ids'] );
	}

	public function test_nothing_is_stored_without_a_user(): void {
		$this->assertSame( '', $this->log->record( 0, 'trash', 'page', $this->entry( 12, [] ) ) );
		$this->assertFalse( get_transient( Change_Log::key( 0 ) ) );
	}

	public function test_mark_shown_and_forget(): void {
		$id = $this->log->record( $this->admin, 'trash', 'page', $this->entry( 12, [] ) );

		$this->log->mark_shown( $this->admin, $id );
		$this->assertTrue( $this->log->latest( $this->admin )['shown'] );

		$this->log->forget( $this->admin );
		$this->assertNull( $this->log->latest( $this->admin ) );
	}
}
```

`tests/phpunit/RequestTest.php`:
```php
<?php
/**
 * Reading core's bulk selection.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Trash\Request;
use WP_UnitTestCase;

/**
 * @covers \HierarchyGuard\Trash\Request
 */
final class RequestTest extends WP_UnitTestCase {

	use Trash_Fixtures;

	public function tear_down(): void {
		$this->end_core_bulk();
		parent::tear_down();
	}

	public function test_reads_the_bulk_selection_of_a_trash(): void {
		$this->start_core_bulk( 'trash', [ 12, 13, 12 ] );
		$this->assertSame( [ 12, 13 ], Request::core_ids() );
	}

	public function test_reads_the_ids_list_of_an_undo(): void {
		$this->start_core_bulk( 'untrash', [ 12, 13 ], true );
		$this->assertSame( [ 12, 13 ], Request::core_ids() );
	}

	public function test_other_screens_and_actions_are_ignored(): void {
		$this->start_core_bulk( 'edit', [ 12 ] );
		$this->assertSame( [], Request::core_ids(), 'Not a trash or untrash.' );

		$this->start_core_bulk( 'trash', [ 12 ] );
		$_REQUEST['filter_action'] = 'Filter';
		$this->assertSame( [], Request::core_ids(), 'The Filter button runs no bulk action.' );

		$this->end_core_bulk();
		$_REQUEST['action'] = 'trash';
		$_REQUEST['post']   = [ '12' ];
		$this->assertSame( [], Request::core_ids(), 'Only the list screen loops over a selection.' );
	}
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npm run test:php -- --filter 'ChangeLogTest|RequestTest'`
Expected: `Error: Class "HierarchyGuard\Trash\Change_Log" not found` and the same for `Request`.

- [ ] **Step 3: Create Change_Log and Request**

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
	 * @param array<string,mixed> $entry     Keys: root, to, policy, fallback, counts, kept_ids, urls, job.
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
	 * @param bool               $running   Whether the job still has work left.
	 */
	public function update( int $user_id, string $change_id, int $root_id, array $counts, array $kept_ids, array $ids, bool $running ): void {
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
	 * Sums the roots, merges the IDs and saves.
	 *
	 * @param int                 $user_id User ID.
	 * @param array<string,mixed> $change  Change.
	 * @param int[]               $ids     IDs to add.
	 */
	private function store( int $user_id, array $change, array $ids ): void {
		$counts = self::empty_counts();
		foreach ( $change['roots'] as $root ) {
			foreach ( self::COUNT_KEYS as $key ) {
				$counts[ $key ] += (int) ( $root['counts'][ $key ] ?? 0 );
			}
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
			'counts'   => $counts,
			'kept_ids' => array_slice( array_map( 'intval', (array) ( $entry['kept_ids'] ?? [] ) ), 0, self::MAX_KEPT ),
			'urls'     => [
				'count'  => (int) ( $urls['count'] ?? 0 ),
				'capped' => (bool) ( $urls['capped'] ?? false ),
			],
			'job'      => (bool) ( $entry['job'] ?? false ),
		];
	}
}
```

`includes/HierarchyGuard/Trash/Request.php`:
```php
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
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `npm run test:php -- --filter 'ChangeLogTest|RequestTest'`
Expected: `OK (10 tests, 36 assertions)`.

Run: `npm run test:php` and `composer lint`
Expected: all green; lint exit 0.

- [ ] **Step 5: Commit**

```bash
git add includes tests
git commit -m "feat: per-request change summary and core's bulk selection"
```

---

### Task 5: Operations, the reparent step

**Files:**
- Create: `includes/HierarchyGuard/Trash/Operations.php`
- Test: `tests/phpunit/OperationsReparentTest.php`

**Interfaces:**
- Consumes: `Subtree::status()`, `live_children()`, `nearest_live_ancestor()`, `Mover::move()` (Task 2); `Markers::add()`, `moved_key()`, `encode_moved()`, `ALIASES` (Task 1); `Ledger::record()`, `record_alias()` (Task 3); `Request::core_ids()`, `Change_Log::empty_counts()`, `MAX_KEPT` (Task 4).
- Produces: `Trash\Operations` constants `TYPE_REPARENT`, `TYPE_TRASH`, `TYPE_RESTORE`, `TYPE_CLEANUP`, `DEFAULT_THRESHOLD`, `DEFAULT_CORE_THRESHOLD`, `MAX_REMEMBERED`, `INTERIM_AGE`; `__construct( Ledger $ledger, Mover $mover )`; static `threshold(): int`, `core_threshold(): int`, `new_job( string $type, int $root_id, string $post_type, int $user_id, array $args = [] ): array` (args: `to`, `policy`, `fallback`, `paths`, `urls`, `total`); `step( array $job, int $moves, int $calls ): array` (sets `work` = `[ 'moves' => n, 'calls' => n ]` for that step); `run( array $job ): array`. Job keys: `id`, `type`, `root`, `post_type`, `user`, `change`, `to`, `policy`, `fallback`, `paths`, `aliased`, `urls`, `total`, `cursor`, `phase`, `stack`, `counts`, `kept_ids`, `ids`, `work`, `created`, `touched`, `finished`.

The class docblock already describes the trash, restore and cleanup steps that Tasks 6 and 7 add; this task's `step()` handles only `TYPE_REPARENT` and finishes any other type at once.

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
		$this->end_core_bulk();
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
		return Operations::new_job(
			Operations::TYPE_REPARENT,
			$this->ids['about'],
			'page',
			$user_id,
			[
				'to'    => $this->ids['company'],
				'paths' => [ 'company/about' ],
			]
		);
	}

	public function test_moves_every_live_child_to_the_new_parent_and_marks_it(): void {
		$job = $this->operations->run( $this->job() );

		$this->assertTrue( $job['finished'] );
		foreach ( [ 'team', 'history' ] as $slug ) {
			$this->assertSame( $this->ids['company'], (int) get_post( $this->ids[ $slug ] )->post_parent, $slug );
			$moved = Markers::decode_moved( get_post_meta( $this->ids[ $slug ], Markers::moved_key( $this->ids['about'] ), true ) );
			$this->assertSame( $this->ids['company'], $moved['to'] );
			$this->assertSame( $slug, $moved['original'] );
			$this->assertSame( $slug, $moved['assigned'] );
		}
		$this->assertSame( $this->ids['team'], (int) get_post( $this->ids['leadership'] )->post_parent, 'Grandchildren travel with their parent.' );
		$this->assertSame( 2, $job['counts']['moved'] );
		$this->assertSame( [ $this->ids['team'], $this->ids['history'] ], $job['ids'] );
	}

	public function test_records_one_alias_on_the_new_parent_and_remembers_it_on_the_root(): void {
		$this->operations->run( $this->job() );

		$this->assertSame( [ 'company/about' ], $this->ledger->paths_for( $this->ids['company'] ) );
		$this->assertSame( [ 'company/about' ], $this->ledger->paths_for( $this->ids['about'] ), 'The page in the trash holds its own path.' );
		$this->assertSame(
			[
				[
					'to'   => $this->ids['company'],
					'path' => 'company/about',
				],
			],
			get_post_meta( $this->ids['about'], Markers::ALIASES, true )
		);
	}

	public function test_no_alias_is_recorded_when_nothing_moved(): void {
		add_filter( 'map_meta_cap', static fn(): array => [ 'do_not_allow' ] );

		$job = $this->operations->run( $this->job( self::factory()->user->create( [ 'role' => 'editor' ] ) ) );

		$this->assertSame( 0, $job['counts']['moved'] );
		$this->assertSame( [], $this->ledger->paths_for( $this->ids['company'] ) );
	}

	public function test_a_renamed_child_keeps_its_old_url_on_record(): void {
		$this->page( 'team', $this->ids['company'] );

		$job = $this->operations->run( $this->job() );

		$this->assertSame( 'team-2', get_post( $this->ids['team'] )->post_name );
		$this->assertSame( 'team-2', Markers::decode_moved( get_post_meta( $this->ids['team'], Markers::moved_key( $this->ids['about'] ), true ) )['assigned'] );
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
		$this->assertSame( 1, $job['counts']['denied'] );
		$this->assertSame( [ $locked ], $job['kept_ids'] );
		$this->assertSame( '', get_post_meta( $locked, Markers::moved_key( $this->ids['about'] ), true ) );
	}

	public function test_system_contexts_skip_capability_checks(): void {
		add_filter( 'map_meta_cap', static fn(): array => [ 'do_not_allow' ] );

		$this->assertSame( 2, $this->operations->run( $this->job( 0 ) )['counts']['moved'] );
	}

	public function test_a_budget_of_one_moves_one_child_per_step(): void {
		$job = $this->operations->step( $this->job(), 1, 0 );
		$this->assertFalse( $job['finished'] );
		$this->assertSame( 1, $job['counts']['moved'] );
		$this->assertSame( 1, $job['work']['moves'] );

		$job = $this->operations->step( $job, 1, 0 );
		$job = $this->operations->step( $job, 1, 0 );
		$this->assertTrue( $job['finished'] );
		$this->assertSame( 2, $job['counts']['moved'] );
	}

	public function test_a_budget_of_zero_does_nothing_yet(): void {
		$job = $this->operations->step( $this->job(), 0, 25 );

		$this->assertFalse( $job['finished'] );
		$this->assertSame( 0, $job['counts']['moved'] );
	}

	public function test_stops_once_the_parent_leaves_the_trash(): void {
		$this->untrash_raw( $this->ids['about'] );

		$job = $this->operations->run( $this->job() );

		$this->assertTrue( $job['finished'] );
		$this->assertSame( 0, $job['counts']['moved'] );
		$this->assertSame( $this->ids['about'], (int) get_post( $this->ids['team'] )->post_parent );
	}

	public function test_a_target_that_went_to_the_trash_is_passed_for_the_next_live_ancestor(): void {
		$this->trash_raw( $this->ids['company'] );

		$job = $this->operations->run( $this->job() );

		$this->assertSame( 0, $job['to'] );
		$this->assertSame( 0, (int) get_post( $this->ids['team'] )->post_parent );
		$this->assertSame( 0, $this->ledger->find( 'page', 'company/about' ), 'The alias follows the new target.' );
	}

	public function test_children_core_is_about_to_trash_are_left_to_core(): void {
		$this->start_core_bulk( 'trash', [ $this->ids['about'], $this->ids['team'] ] );

		$job = $this->operations->run( $this->job() );

		$this->assertSame( $this->ids['about'], (int) get_post( $this->ids['team'] )->post_parent, 'Core trashes it next in its bulk loop.' );
		$this->assertSame( $this->ids['company'], (int) get_post( $this->ids['history'] )->post_parent );
		$this->assertSame( 1, $job['counts']['moved'] );
		$this->assertSame( 0, $job['counts']['kept'] );
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

	public function test_the_budgets_are_filterable_with_a_floor_of_one(): void {
		$this->assertSame( 200, Operations::threshold() );
		$this->assertSame( 25, Operations::core_threshold() );
		add_filter( 'hierarchy_guard_batch_threshold', static fn(): int => 0 );
		add_filter( 'hierarchy_guard_core_call_threshold', static fn(): int => -5 );
		$this->assertSame( 1, Operations::threshold() );
		$this->assertSame( 1, Operations::core_threshold() );
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
use WP_Post;

/**
 * Moves, trashes and restores subpages in bounded steps. A job is a plain
 * array, so the same step runs inline for small subtrees and from the batch
 * queue for large ones. Each step takes two budgets: raw moves, which are
 * cheap, and core trash or untrash calls, which run every plugin's save hooks.
 */
final class Operations {

	public const TYPE_REPARENT = 'reparent';
	public const TYPE_TRASH    = 'trash_children';
	public const TYPE_RESTORE  = 'restore';
	public const TYPE_CLEANUP  = 'cleanup';

	public const DEFAULT_THRESHOLD      = 200;
	public const DEFAULT_CORE_THRESHOLD = 25;
	public const MAX_REMEMBERED         = 200;

	/**
	 * A move younger than this is taken back without recording its interim URL:
	 * nobody has had time to link to it.
	 */
	public const INTERIM_AGE = HOUR_IN_SECONDS;

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
	 * Raw moves per step.
	 *
	 * @return int
	 */
	public static function threshold(): int {
		/**
		 * Filters how many subpages one step moves with a direct update.
		 *
		 * @param int $threshold Moves per step, 200 by default.
		 */
		return max( 1, (int) apply_filters( 'hierarchy_guard_batch_threshold', self::DEFAULT_THRESHOLD ) );
	}

	/**
	 * Core trash and untrash calls per step.
	 *
	 * @return int
	 */
	public static function core_threshold(): int {
		/**
		 * Filters how many subpages one step trashes or restores through core,
		 * which runs every plugin's save hooks for each of them.
		 *
		 * @param int $threshold Core calls per step, 25 by default.
		 */
		return max( 1, (int) apply_filters( 'hierarchy_guard_core_call_threshold', self::DEFAULT_CORE_THRESHOLD ) );
	}

	/**
	 * A fresh job.
	 *
	 * @param string              $type      One of the TYPE_ constants.
	 * @param int                 $root_id   The page being trashed, restored or deleted.
	 * @param string              $post_type Post type of the subtree.
	 * @param int                 $user_id   User whose capabilities apply, 0 for system contexts.
	 * @param array<string,mixed> $args      Optional: to, policy, fallback, paths, urls, total.
	 * @return array<string,mixed>
	 */
	public static function new_job( string $type, int $root_id, string $post_type, int $user_id, array $args = [] ): array {
		$urls = (array) ( $args['urls'] ?? [] );

		return [
			'id'        => wp_generate_uuid4(),
			'type'      => $type,
			'root'      => $root_id,
			'post_type' => $post_type,
			'user'      => $user_id,
			'change'    => '',
			'to'        => (int) ( $args['to'] ?? 0 ),
			'policy'    => (string) ( $args['policy'] ?? '' ),
			'fallback'  => (bool) ( $args['fallback'] ?? false ),
			'paths'     => array_values( array_map( 'strval', (array) ( $args['paths'] ?? [] ) ) ),
			'aliased'   => false,
			'urls'      => [
				'count'  => (int) ( $urls['count'] ?? 0 ),
				'capped' => (bool) ( $urls['capped'] ?? false ),
			],
			'total'     => (int) ( $args['total'] ?? 0 ),
			'cursor'    => 0,
			'phase'     => 'moved',
			'stack'     => [ [ $root_id, 0 ] ],
			'counts'    => Change_Log::empty_counts(),
			'kept_ids'  => [],
			'ids'       => [],
			'work'      => [
				'moves' => 0,
				'calls' => 0,
			],
			'created'   => time(),
			'touched'   => time(),
			'finished'  => false,
		];
	}

	/**
	 * Runs one bounded step of a job.
	 *
	 * @param array<string,mixed> $job   Job.
	 * @param int                 $moves Most subpages this step may move or look at.
	 * @param int                 $calls Most core trash or untrash calls this step may make.
	 * @return array<string,mixed> The updated job; `work` says what this step spent.
	 */
	public function step( array $job, int $moves, int $calls ): array {
		$job['work'] = [
			'moves' => 0,
			'calls' => 0,
		];
		$moves       = max( 0, $moves );
		$calls       = max( 0, $calls );

		switch ( $job['type'] ?? '' ) {
			case self::TYPE_REPARENT:
				return $this->step_reparent( $job, $moves );
		}

		$job['finished'] = true;
		return $job;
	}

	/**
	 * Runs a job to completion in this process with the default budgets.
	 *
	 * @param array<string,mixed> $job Job.
	 * @return array<string,mixed> The finished job.
	 */
	public function run( array $job ): array {
		for ( $i = 0; $i < 10000 && empty( $job['finished'] ); $i++ ) {
			$job = $this->step( $job, self::threshold(), self::core_threshold() );
		}
		return $job;
	}

	/**
	 * Moves the trashed root's live children to the nearest live ancestor.
	 *
	 * @param array<string,mixed> $job   Job.
	 * @param int                 $moves Most children to look at.
	 * @return array<string,mixed>
	 */
	private function step_reparent( array $job, int $moves ): array {
		$root = (int) $job['root'];
		if ( 'trash' !== Subtree::status( $root ) ) {
			// The page left the trash; its restore takes back what was already moved.
			$job['finished'] = true;
			return $job;
		}
		if ( $moves < 1 ) {
			return $job;
		}

		$to = (int) $job['to'];
		if ( $to > 0 && in_array( Subtree::status( $to ), [ '', 'trash' ], true ) ) {
			// The target went to the trash or away since the last step: move up past it.
			$post           = get_post( $root );
			$to             = $post instanceof WP_Post ? Subtree::nearest_live_ancestor( $post ) : 0;
			$job['to']      = $to;
			$job['aliased'] = false;
		}

		$type     = (string) $job['post_type'];
		$children = Subtree::live_children( $root, $type, $moves, (int) $job['cursor'] );
		_prime_post_caches( $children, false, false );
		$core  = Request::core_ids();
		$moved = [];
		$now   = time();

		foreach ( $children as $child_id ) {
			$job['cursor'] = $child_id;
			if ( in_array( $child_id, $core, true ) ) {
				// Core trashes this one next in its bulk loop, with its own policy.
				continue;
			}
			if ( ! $this->can( (int) $job['user'], 'edit_post', $child_id ) ) {
				$job = $this->keep( $job, $child_id, true );
				continue;
			}

			$result = $this->mover->move( $child_id, $to );
			if ( ! $result['moved'] ) {
				$job = $this->keep( $job, $child_id, false );
				continue;
			}

			Markers::add( $child_id, Markers::moved_key( $root ), Markers::encode_moved( $to, $result['old_slug'], $result['slug'], $now ) );
			if ( $result['renamed'] ) {
				++$job['counts']['renamed'];
				if ( $result['recordable'] && $this->recording() ) {
					// The alias on the new parent can't reach a renamed slug, so record it here.
					$this->ledger->record( $child_id, $result['old_path'] );
				}
			}
			++$job['counts']['moved'];
			$moved[] = $child_id;
		}

		$job['work']['moves'] = count( $children );
		if ( [] !== $moved ) {
			if ( empty( $job['aliased'] ) ) {
				$job = $this->record_aliases( $job );
			}

			/**
			 * Fires after subpages were moved to a new parent with a direct update.
			 * No save_post runs for them, so search indexes and SEO plugins that
			 * cache permalinks can re-sync these IDs here.
			 *
			 * @param int[] $ids  Moved post IDs.
			 * @param int   $from The trashed parent.
			 * @param int   $to   The new parent, 0 for top level.
			 */
			do_action( 'hierarchy_guard_children_reparented', $moved, $root, $to );
		}

		$job['finished'] = count( $children ) < $moves;
		return $this->remember( $job, $moved );
	}

	/**
	 * Records the old parent paths on the new parent, once per job and only
	 * after a subpage actually moved, and on the root itself. Remembers the
	 * aliases on the root so the restore can remove them.
	 *
	 * @param array<string,mixed> $job Job.
	 * @return array<string,mixed>
	 */
	private function record_aliases( array $job ): array {
		$job['aliased'] = true;
		if ( ! $this->recording() ) {
			return $job;
		}

		$root    = (int) $job['root'];
		$to      = (int) $job['to'];
		$written = [];
		foreach ( (array) $job['paths'] as $path ) {
			if ( $this->ledger->record_alias( (string) $job['post_type'], $to, (string) $path ) ) {
				$written[] = [
					'to'   => $to,
					'path' => (string) $path,
				];
			}
			// Held by the page in the trash, the path tells the resolver not to redirect it.
			$this->ledger->record( $root, (string) $path );
		}

		if ( [] !== $written ) {
			$stored = get_post_meta( $root, Markers::ALIASES, true );
			update_post_meta( $root, Markers::ALIASES, array_merge( is_array( $stored ) ? $stored : [], $written ) );
		}
		return $job;
	}

	/**
	 * Counts a subpage left where it is.
	 *
	 * @param array<string,mixed> $job     Job.
	 * @param int                 $post_id Subpage.
	 * @param bool                $denied  Whether the user lacked the capability.
	 * @return array<string,mixed>
	 */
	private function keep( array $job, int $post_id, bool $denied ): array {
		++$job['counts']['kept'];
		if ( $denied ) {
			++$job['counts']['denied'];
		}
		if ( count( $job['kept_ids'] ) < Change_Log::MAX_KEPT ) {
			$job['kept_ids'][] = $post_id;
		}
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
		if ( $user_id <= 0 ) {
			return true;
		}
		if ( get_current_user_id() === $user_id ) {
			return current_user_can( $capability, $post_id );
		}
		return user_can( $user_id, $capability, $post_id );
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
Expected: `OK (13 tests, 50 assertions)`.

Run: `npm run test:php` and `composer lint`
Expected: all green; lint exit 0.

- [ ] **Step 5: Commit**

```bash
git add includes tests
git commit -m "feat: reparent step moves a trashed page's subpages to the nearest live ancestor"
```

---

### Task 6: Operations, the trash-subtree step

**Files:**
- Modify: `includes/HierarchyGuard/Trash/Operations.php`
- Test: `tests/phpunit/OperationsTrashTest.php`

**Interfaces:**
- Consumes: `Guard::run()`, `Markers::add()`, `remove_key()`, `trashed_key()` (Task 1); `Subtree::live_children()`, `with_live_children()`, `status()` (Task 2).
- Produces: `Operations::step()` handles `TYPE_TRASH`: depth first over `stack` (pairs of `[ parent_id, cursor ]`, starting `[ [ root, 0 ] ]`), only subpages with live children are pushed, each `wp_trash_post()` runs inside `Guard::run( [ $id ] )` with the `_hierarchy_guard_trashed_{root}` row written first. `$calls` bounds the core calls and `$moves` bounds the subpages looked at. Success is decided by the subpage's status read past the cache, because a `pre_trash_post` filter can answer `true` without trashing.

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
		$this->operations   = new Operations( new Ledger(), new Mover() );
		$this->ids          = $this->make_tree();
		$this->ids['plans'] = $this->page( 'plans', $this->ids['team'], 'draft' );
		$this->trash_raw( $this->ids['about'] );
	}

	public function tear_down(): void {
		$this->end_core_bulk();
		parent::tear_down();
	}

	/**
	 * A job trashing about's subtree.
	 *
	 * @param int $user_id Triggering user.
	 * @return array<string,mixed>
	 */
	private function job( int $user_id = 0 ): array {
		return Operations::new_job( Operations::TYPE_TRASH, $this->ids['about'], 'page', $user_id );
	}

	public function test_trashes_every_live_descendant_and_marks_it(): void {
		$job = $this->operations->run( $this->job() );

		$this->assertTrue( $job['finished'] );
		foreach ( [ 'team', 'history', 'leadership', 'plans' ] as $slug ) {
			$this->assertSame( 'trash', get_post_status( $this->ids[ $slug ] ), $slug );
		}
		$this->assertEqualSets( [ $this->ids['team'], $this->ids['history'], $this->ids['leadership'], $this->ids['plans'] ], $this->marked_ids( Markers::trashed_key( $this->ids['about'] ) ) );
		$this->assertSame( 4, $job['counts']['trashed'] );
		$this->assertSame( [], $job['stack'] );
	}

	public function test_covers_each_subpage_while_trashing_it(): void {
		$covered = [];
		add_action(
			'wp_trash_post',
			static function ( $post_id ) use ( &$covered ): void {
				$covered[] = Guard::covers( (int) $post_id );
			}
		);

		$this->operations->run( $this->job() );

		$this->assertSame( [ true, true, true, true ], $covered );
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
		$this->assertSame( 1, $job['counts']['denied'] );
		$this->assertSame( [ $locked ], $job['kept_ids'] );
	}

	public function test_the_front_page_stays_out_of_the_trash(): void {
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $this->ids['history'] );

		$job = $this->operations->run( $this->job() );

		$this->assertSame( 'publish', get_post_status( $this->ids['history'] ) );
		$this->assertSame( 'page', get_option( 'show_on_front' ), 'The homepage setting is untouched.' );
		$this->assertSame( 1, $job['counts']['kept'] );
		$this->assertSame( 0, $job['counts']['denied'] );
	}

	public function test_a_small_budget_spreads_the_work_across_steps(): void {
		$job   = $this->job();
		$steps = 0;
		while ( ! $job['finished'] && $steps < 50 ) {
			$job = $this->operations->step( $job, 200, 1 );
			$this->assertLessThanOrEqual( 1, $job['work']['calls'] );
			++$steps;
		}

		$this->assertTrue( $job['finished'] );
		$this->assertSame( 4, $job['counts']['trashed'] );
		$this->assertGreaterThanOrEqual( 4, $steps );
	}

	public function test_stops_once_the_parent_leaves_the_trash(): void {
		$this->untrash_raw( $this->ids['about'] );

		$job = $this->operations->run( $this->job() );

		$this->assertTrue( $job['finished'] );
		$this->assertSame( 'publish', get_post_status( $this->ids['team'] ) );
	}

	public function test_subpages_core_is_about_to_trash_are_left_to_core(): void {
		$this->start_core_bulk( 'trash', [ $this->ids['about'], $this->ids['team'] ] );

		$job = $this->operations->run( $this->job() );

		$this->assertSame( 'publish', get_post_status( $this->ids['team'] ), 'Core trashes it next in its bulk loop.' );
		$this->assertSame( 'publish', get_post_status( $this->ids['leadership'] ), 'Its own trash handles its subpages.' );
		$this->assertSame( 'trash', get_post_status( $this->ids['history'] ) );
		$this->assertSame( 1, $job['counts']['trashed'] );
	}

	public function test_a_trash_another_plugin_blocks_leaves_no_marker(): void {
		$blocked = $this->ids['history'];
		add_filter( 'pre_trash_post', static fn( $check, $post ) => $post->ID === $blocked ? false : $check, 10, 2 );

		$job = $this->operations->run( $this->job() );

		$this->assertSame( 'publish', get_post_status( $blocked ) );
		$this->assertSame( '', get_post_meta( $blocked, Markers::trashed_key( $this->ids['about'] ), true ) );
		$this->assertSame( 1, $job['counts']['kept'] );
		$this->assertSame( 3, $job['counts']['trashed'] );
	}

	public function test_a_trash_another_plugin_answers_true_for_changes_nothing_under_it(): void {
		$blocked = $this->ids['team'];
		add_filter( 'pre_trash_post', static fn( $check, $post ) => $post->ID === $blocked ? true : $check, 10, 2 );

		$job = $this->operations->run( $this->job() );

		$this->assertSame( 'publish', get_post_status( $blocked ) );
		$this->assertSame( 'publish', get_post_status( $this->ids['leadership'] ), 'Not expanded past a page that stayed live.' );
		$this->assertSame( 'draft', get_post_status( $this->ids['plans'] ) );
		$this->assertSame( '', get_post_meta( $blocked, Markers::trashed_key( $this->ids['about'] ), true ) );
		$this->assertSame( 1, $job['counts']['kept'] );
		$this->assertSame( 1, $job['counts']['trashed'] );
	}
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npm run test:php -- --filter OperationsTrashTest`
Expected: failures; `step()` finishes `TYPE_TRASH` jobs at once, so nothing is trashed.

- [ ] **Step 3: Add the trash-subtree step**

In `includes/HierarchyGuard/Trash/Operations.php`, add the case to `step()` after the `TYPE_REPARENT` case:
```php
			case self::TYPE_TRASH:
				return $this->step_trash_children( $job, $moves, $calls );
```
and add this method after `step_reparent()`:
```php
	/**
	 * Trashes the trashed root's live descendants, depth first.
	 *
	 * @param array<string,mixed> $job   Job.
	 * @param int                 $moves Most subpages to look at.
	 * @param int                 $calls Most core trash calls.
	 * @return array<string,mixed>
	 */
	private function step_trash_children( array $job, int $moves, int $calls ): array {
		$root = (int) $job['root'];
		// With the trash turned off, wp_trash_post() would delete the subpages for good.
		if ( 'trash' !== Subtree::status( $root ) || ! EMPTY_TRASH_DAYS ) {
			$job['finished'] = true;
			return $job;
		}

		$type    = (string) $job['post_type'];
		$core    = Request::core_ids();
		$special = array_values( array_filter( array_map( 'intval', [ get_option( 'page_on_front' ), get_option( 'page_for_posts' ), get_option( 'wp_page_for_privacy_policy' ) ] ) ) );
		$stack   = (array) $job['stack'];
		$seen    = 0;
		$used    = 0;
		$trashed = [];

		while ( [] !== $stack && $used < $calls && $seen < $moves ) {
			$top    = count( $stack ) - 1;
			$parent = (int) $stack[ $top ][0];
			$kids   = Subtree::live_children( $parent, $type, min( $calls - $used, $moves - $seen ), (int) $stack[ $top ][1] );
			if ( [] === $kids ) {
				array_pop( $stack );
				continue;
			}
			_prime_post_caches( $kids, false, false );

			$now_trashed = [];
			foreach ( $kids as $kid ) {
				$stack[ $top ][1] = $kid;
				++$seen;
				if ( in_array( $kid, $core, true ) ) {
					continue;
				}
				if ( in_array( $kid, $special, true ) ) {
					// Trashing the front page, posts page or privacy page would change site settings.
					$job = $this->keep( $job, $kid, false );
					continue;
				}
				if ( ! $this->can( (int) $job['user'], 'delete_post', $kid ) ) {
					$job = $this->keep( $job, $kid, true );
					continue;
				}

				++$used;
				// Marked first, so the row exists even if this request dies right after the trash.
				Markers::add( $kid, Markers::trashed_key( $root ), '1' );
				Guard::run( [ $kid ], static fn() => wp_trash_post( $kid ) );
				// A pre_trash_post filter can answer true without trashing, so the status decides.
				if ( 'trash' === Subtree::status( $kid ) ) {
					++$job['counts']['trashed'];
					$now_trashed[] = $kid;
					continue;
				}

				Markers::remove_key( $kid, Markers::trashed_key( $root ) );
				$job = $this->keep( $job, $kid, false );
			}

			foreach ( array_reverse( Subtree::with_live_children( $now_trashed, $type ) ) as $parent_id ) {
				$stack[] = [ $parent_id, 0 ];
			}
			$trashed = array_merge( $trashed, $now_trashed );
		}

		$job['stack']    = $stack;
		$job['work']     = [
			'moves' => $seen,
			'calls' => $used,
		];
		$job['finished'] = [] === $stack;

		return $this->remember( $job, $trashed );
	}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `npm run test:php -- --filter 'OperationsReparentTest|OperationsTrashTest'`
Expected: `OK (22 tests, 100 assertions)`.

Run: `npm run test:php` and `composer lint`
Expected: all green; lint exit 0.

- [ ] **Step 5: Commit**

```bash
git add includes tests
git commit -m "feat: trash-subtree step trashes descendants depth first under the guard"
```

---

### Task 7: Operations, the restore and cleanup steps

**Files:**
- Modify: `includes/HierarchyGuard/Trash/Operations.php`
- Test: `tests/phpunit/OperationsRestoreTest.php`

**Interfaces:**
- Consumes: `Markers::rows()`, `remove()`, `decode_moved()`, `previous_status()` filter (Task 1); `Mover::move()` with a preferred slug, `Mover::reparent()`, `Subtree::status()` (Task 2); `Ledger::record()`, `prune()`, `forget_alias()` (Task 3).
- Produces: `Operations::step()` handles `TYPE_RESTORE` in two phases. Phase `moved` reads `_hierarchy_guard_moved_{root}` rows: a subpage still under the recorded parent moves back (its original slug only if the editor hasn't renamed it since; a subpage that is itself in the trash gets only its parent and desired slug back), its interim path is recorded when the move is at least `INTERIM_AGE` old, and every row is deleted. Phase `trashed` untrashes `_hierarchy_guard_trashed_{root}` subpages under the guard (rows of IDs core is about to restore are kept for core); success is decided by the status read past the cache, because a `pre_untrash_post` filter can answer `true` without restoring. When both are done, the root's aliases are removed. It also handles `TYPE_CLEANUP`, which deletes the rows of a deleted root. A subpage another plugin keeps in the trash counts as `left` and keeps its row. The restore fires `hierarchy_guard_children_reparented` for the live subpages it moves back, grouped by the parent they leave. Counts: `moved`, `restored`, `left`, `renamed`.

- [ ] **Step 1: Write the failing tests**

`tests/phpunit/OperationsRestoreTest.php`:
```php
<?php
/**
 * The restore and cleanup steps.
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
		$this->end_core_bulk();
		$this->set_permalink_structure( '' );
		parent::tear_down();
	}

	/**
	 * Runs the reparent job for about.
	 */
	private function move_out(): void {
		$this->operations->run(
			Operations::new_job(
				Operations::TYPE_REPARENT,
				$this->ids['about'],
				'page',
				0,
				[
					'to'    => $this->ids['company'],
					'paths' => [ 'company/about' ],
				]
			)
		);
	}

	/**
	 * Runs the trash-subtree job for about.
	 */
	private function trash_out(): void {
		$this->operations->run( Operations::new_job( Operations::TYPE_TRASH, $this->ids['about'], 'page', 0 ) );
	}

	/**
	 * Runs the restore job for about.
	 *
	 * @return array<string,mixed>
	 */
	private function restore(): array {
		return $this->operations->run( Operations::new_job( Operations::TYPE_RESTORE, $this->ids['about'], 'page', 0 ) );
	}

	public function test_moved_children_go_back_under_the_restored_parent(): void {
		$this->move_out();
		$this->untrash_raw( $this->ids['about'] );

		$job = $this->restore();

		$this->assertTrue( $job['finished'] );
		foreach ( [ 'team', 'history' ] as $slug ) {
			$this->assertSame( $this->ids['about'], (int) get_post( $this->ids[ $slug ] )->post_parent, $slug );
		}
		$this->assertSame( [], Markers::rows( Markers::moved_key( $this->ids['about'] ), 10 ), 'Each row is deleted once handled.' );
		$this->assertSame( 2, $job['counts']['moved'] );
		$this->assertNull( $this->ledger->find( 'page', 'company/team' ), 'An undo right away records no interim URL.' );
	}

	public function test_an_older_move_keeps_its_interim_url(): void {
		$this->move_out();
		$this->age_moved_markers( $this->ids['about'], 2 * HOUR_IN_SECONDS );
		$this->untrash_raw( $this->ids['about'] );

		$this->restore();

		$this->assertSame( $this->ids['team'], $this->ledger->find( 'page', 'company/team' ), 'Links made while the parent was in the trash keep working.' );
	}

	public function test_the_aliases_are_removed_once_the_parent_is_back(): void {
		$this->move_out();
		$this->assertSame( $this->ids['company'], $this->ledger->find( 'page', 'company/about' ), 'Precondition.' );
		$this->untrash_raw( $this->ids['about'] );

		$this->restore();

		$this->assertNull( $this->ledger->find( 'page', 'company/about' ) );
		$this->assertSame( '', get_post_meta( $this->ids['about'], Markers::ALIASES, true ) );
	}

	public function test_a_parent_that_came_back_at_another_path_takes_over_its_old_path(): void {
		global $wpdb;

		$this->move_out();
		$this->untrash_raw( $this->ids['about'] );
		// What core does when another page took "about" while this one was in the trash.
		$wpdb->update( $wpdb->posts, [ 'post_name' => 'about-2' ], [ 'ID' => $this->ids['about'] ] );
		clean_post_cache( $this->ids['about'] );

		$this->restore();

		$this->assertSame( $this->ids['about'], $this->ledger->find( 'page', 'company/about' ) );
	}

	public function test_a_renamed_child_gets_its_original_slug_back(): void {
		$this->page( 'team', $this->ids['company'] );
		$this->move_out();
		$this->assertSame( 'team-2', get_post( $this->ids['team'] )->post_name, 'Precondition.' );
		$this->untrash_raw( $this->ids['about'] );

		$job = $this->restore();

		$this->assertSame( 'team', get_post( $this->ids['team'] )->post_name );
		$this->assertSame( 0, $job['counts']['renamed'] );
		$this->assertNull( $this->ledger->find( 'page', 'company/about/team' ), 'The entry equal to the current path is pruned.' );
	}

	public function test_a_child_an_editor_renamed_since_keeps_its_new_slug(): void {
		$this->page( 'team', $this->ids['company'] );
		$this->move_out();
		wp_update_post(
			[
				'ID'        => $this->ids['team'],
				'post_name' => 'crew',
			]
		);
		$this->untrash_raw( $this->ids['about'] );

		$this->restore();

		$this->assertSame( 'crew', get_post( $this->ids['team'] )->post_name );
		$this->assertSame( $this->ids['about'], (int) get_post( $this->ids['team'] )->post_parent );
	}

	public function test_a_child_moved_elsewhere_is_left_alone(): void {
		$this->move_out();
		wp_update_post(
			[
				'ID'          => $this->ids['team'],
				'post_parent' => $this->ids['history'],
			]
		);
		$this->untrash_raw( $this->ids['about'] );

		$job = $this->restore();

		$this->assertSame( $this->ids['history'], (int) get_post( $this->ids['team'] )->post_parent );
		$this->assertSame( '', get_post_meta( $this->ids['team'], Markers::moved_key( $this->ids['about'] ), true ), 'Its row is deleted either way.' );
		$this->assertSame( 1, $job['counts']['left'] );
		$this->assertSame( 1, $job['counts']['moved'], 'Only history moves back.' );
	}

	public function test_a_moved_child_trashed_since_goes_back_under_its_parent_in_the_trash(): void {
		$this->page( 'team', $this->ids['company'] );
		$this->move_out();
		$this->trash_raw( $this->ids['team'] );
		$this->assertSame( 'team-2', get_post_meta( $this->ids['team'], '_wp_desired_post_slug', true ), 'Precondition.' );
		$this->untrash_raw( $this->ids['about'] );

		$this->restore();

		$this->assertSame( 'trash', get_post_status( $this->ids['team'] ) );
		$this->assertSame( $this->ids['about'], (int) get_post( $this->ids['team'] )->post_parent );
		$this->assertSame( 'team', get_post_meta( $this->ids['team'], '_wp_desired_post_slug', true ), 'Restoring it later gives back its original slug.' );
	}

	public function test_subpages_trashed_with_the_parent_return_with_their_previous_status(): void {
		$plans = $this->page( 'plans', $this->ids['team'], 'draft' );
		$this->trash_out();
		$this->untrash_raw( $this->ids['about'] );

		$job = $this->restore();

		$this->assertSame( 'publish', get_post_status( $this->ids['team'] ) );
		$this->assertSame( 'publish', get_post_status( $this->ids['leadership'] ) );
		$this->assertSame( 'draft', get_post_status( $plans ) );
		$this->assertSame( [], Markers::rows( Markers::trashed_key( $this->ids['about'] ), 10 ) );
		$this->assertSame( 4, $job['counts']['restored'] );
	}

	public function test_covers_each_subpage_while_restoring_it(): void {
		$this->trash_out();
		$this->untrash_raw( $this->ids['about'] );
		$covered = [];
		add_action(
			'untrash_post',
			static function ( $post_id ) use ( &$covered ): void {
				$covered[] = Guard::covers( (int) $post_id );
			}
		);

		$this->restore();

		$this->assertSame( [ true, true, true ], $covered );
	}

	public function test_subpages_core_is_about_to_restore_are_left_to_core(): void {
		$this->trash_out();
		$this->untrash_raw( $this->ids['about'] );
		$this->start_core_bulk( 'untrash', [ $this->ids['about'], $this->ids['team'] ] );

		$this->restore();

		$this->assertSame( 'trash', get_post_status( $this->ids['team'] ), 'Core restores it next in its bulk loop.' );
		$this->assertSame( [ $this->ids['team'] ], $this->marked_ids( Markers::trashed_key( $this->ids['about'] ) ), 'Its row stays so core gives it its previous status.' );
		$this->assertSame( 'publish', get_post_status( $this->ids['history'] ) );
	}

	public function test_stops_while_the_parent_is_in_the_trash(): void {
		$this->move_out();

		$job = $this->restore();

		$this->assertTrue( $job['finished'] );
		$this->assertSame( 0, $job['counts']['moved'] );
		$this->assertSame( $this->ids['company'], (int) get_post( $this->ids['team'] )->post_parent );
	}

	public function test_cleanup_deletes_the_rows_of_a_deleted_root(): void {
		$this->move_out();
		Markers::add( $this->ids['leadership'], Markers::trashed_key( $this->ids['about'] ), '1' );

		$job = Operations::new_job( Operations::TYPE_CLEANUP, $this->ids['about'], 'page', 0 );
		$job = $this->operations->step( $job, 1, 0 );
		$this->assertFalse( $job['finished'] );

		$job = $this->operations->run( $job );

		$this->assertTrue( $job['finished'] );
		$this->assertSame( 0, Markers::count( $this->ids['about'], 10 ) );
	}

	public function test_an_untrash_another_plugin_answers_true_for_is_not_counted(): void {
		$this->trash_out();
		$this->untrash_raw( $this->ids['about'] );
		$blocked = $this->ids['team'];
		add_filter( 'pre_untrash_post', static fn( $check, $post ) => $post->ID === $blocked ? true : $check, 10, 2 );

		$job = $this->restore();

		$this->assertSame( 'trash', get_post_status( $blocked ) );
		$this->assertSame( 'publish', get_post_status( $this->ids['history'] ) );
		$this->assertSame( 2, $job['counts']['restored'] );
		$this->assertSame( 1, $job['counts']['left'] );
		$this->assertSame( [ $blocked ], $this->marked_ids( Markers::trashed_key( $this->ids['about'] ) ), 'Its row stays for when it does leave the trash.' );
	}

	public function test_reports_the_subpages_it_moves_back(): void {
		$this->move_out();
		$this->untrash_raw( $this->ids['about'] );
		$seen = [];
		add_action(
			'hierarchy_guard_children_reparented',
			static function ( array $ids, int $from, int $to ) use ( &$seen ): void {
				$seen[] = [ $ids, $from, $to ];
			},
			10,
			3
		);

		$this->restore();

		$this->assertSame( [ [ [ $this->ids['team'], $this->ids['history'] ], $this->ids['company'], $this->ids['about'] ] ], $seen );
	}
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npm run test:php -- --filter OperationsRestoreTest`
Expected: failures; `TYPE_RESTORE` and `TYPE_CLEANUP` jobs finish at once without changing anything.

- [ ] **Step 3: Add the restore and cleanup steps**

In `includes/HierarchyGuard/Trash/Operations.php`:

Add `use HierarchyGuard\Paths;` after `use HierarchyGuard\Ledger;`.

Add the cases to `step()` after the `TYPE_TRASH` case:
```php
			case self::TYPE_RESTORE:
				return $this->step_restore( $job, $moves, $calls );
			case self::TYPE_CLEANUP:
				return $this->step_cleanup( $job, $moves );
```
and add these methods after `step_trash_children()`:
```php
	/**
	 * Takes back the moves and trashes for a restored root: moved subpages
	 * first, then subpages trashed with it.
	 *
	 * @param array<string,mixed> $job   Job.
	 * @param int                 $moves Most moved subpages to handle.
	 * @param int                 $calls Most core untrash calls.
	 * @return array<string,mixed>
	 */
	private function step_restore( array $job, int $moves, int $calls ): array {
		if ( in_array( Subtree::status( (int) $job['root'] ), [ '', 'trash' ], true ) ) {
			$job['finished'] = true;
			return $job;
		}

		if ( 'moved' === $job['phase'] ) {
			$job = $this->restore_moved( $job, $moves );
			if ( 'moved' === $job['phase'] ) {
				return $job;
			}
		}

		$job = $this->restore_trashed( $job, $calls );
		if ( ! empty( $job['finished'] ) ) {
			$this->finish_restore( $job );
		}
		return $job;
	}

	/**
	 * Moves subpages back under the restored root.
	 *
	 * @param array<string,mixed> $job   Job.
	 * @param int                 $moves Most rows to handle.
	 * @return array<string,mixed>
	 */
	private function restore_moved( array $job, int $moves ): array {
		if ( $moves < 1 ) {
			return $job;
		}

		$root     = (int) $job['root'];
		$rows     = Markers::rows( Markers::moved_key( $root ), $moves, (int) $job['cursor'] );
		$restored = [];
		$by_from  = [];
		$now      = time();
		_prime_post_caches( array_column( $rows, 'post_id' ), false, false );

		foreach ( $rows as $row ) {
			$job['cursor'] = $row['meta_id'];
			$id            = $row['post_id'];
			$moved         = Markers::decode_moved( $row['value'] );
			$post          = get_post( $id );
			if ( ! $post instanceof WP_Post ) {
				continue;
			}
			if ( (int) $post->post_parent !== $moved['to'] ) {
				// An editor moved it somewhere else since: leave it there.
				++$job['counts']['left'];
				continue;
			}

			if ( 'trash' === $post->post_status ) {
				// Moved up, then trashed on its own: put it back under the root, still in the trash.
				$this->mover->reparent( $id, $root );
				if ( $moved['original'] !== $moved['assigned'] && get_post_meta( $id, '_wp_desired_post_slug', true ) === $moved['assigned'] ) {
					update_post_meta( $id, '_wp_desired_post_slug', $moved['original'] );
				}
				++$job['counts']['moved'];
				$restored[] = $id;
				continue;
			}

			// The original slug comes back only if the editor hasn't renamed the subpage since.
			$preferred = ( '' !== $moved['original'] && (string) $post->post_name === $moved['assigned'] ) ? $moved['original'] : '';
			$interim   = Paths::uri( $post );
			$result    = $this->mover->move( $id, $root, $preferred );
			if ( ! $result['moved'] ) {
				++$job['counts']['left'];
				continue;
			}

			if ( $result['recordable'] && $this->recording() && $now - $moved['time'] >= self::INTERIM_AGE ) {
				// Links made while the parent was in the trash keep working.
				$this->ledger->record( $id, $interim );
			}
			$this->ledger->prune( $id );
			if ( ( '' !== $preferred && $result['slug'] !== $preferred ) || ( '' === $preferred && $result['renamed'] ) ) {
				++$job['counts']['renamed'];
			}
			++$job['counts']['moved'];
			$restored[]                = $id;
			$by_from[ $moved['to'] ][] = $id;
		}

		Markers::remove( $rows );
		foreach ( $by_from as $from => $ids ) {
			/** This action is documented in includes/HierarchyGuard/Trash/Operations.php */
			do_action( 'hierarchy_guard_children_reparented', $ids, (int) $from, $root );
		}
		$job['work']['moves'] = count( $rows );
		if ( count( $rows ) < $moves ) {
			$job['phase']  = 'trashed';
			$job['cursor'] = 0;
		}

		return $this->remember( $job, $restored );
	}

	/**
	 * Restores subpages trashed together with the root. Their marker row makes
	 * core give them their previous status.
	 *
	 * @param array<string,mixed> $job   Job.
	 * @param int                 $calls Most rows to handle.
	 * @return array<string,mixed>
	 */
	private function restore_trashed( array $job, int $calls ): array {
		if ( $calls < 1 ) {
			return $job;
		}

		$root     = (int) $job['root'];
		$core     = Request::core_ids();
		$rows     = Markers::rows( Markers::trashed_key( $root ), $calls, (int) $job['cursor'] );
		$done     = [];
		$restored = [];
		$used     = 0;
		_prime_post_caches( array_column( $rows, 'post_id' ), false, false );

		foreach ( $rows as $row ) {
			$job['cursor'] = $row['meta_id'];
			$id            = $row['post_id'];
			if ( in_array( $id, $core, true ) ) {
				// Core restores it next in its bulk loop; the row gives it its previous status.
				continue;
			}
			if ( 'trash' !== get_post_status( $id ) ) {
				$done[] = $row;
				continue;
			}

			++$used;
			Guard::run( [ $id ], static fn() => wp_untrash_post( $id ) );
			// A pre_untrash_post filter can answer true without restoring, so the status decides.
			$status = Subtree::status( $id );
			if ( 'trash' === $status ) {
				// Its row stays, so it still gets its previous status whenever it does leave the trash.
				++$job['counts']['left'];
				continue;
			}
			$done[] = $row;
			if ( '' !== $status ) {
				++$job['counts']['restored'];
				$restored[] = $id;
			}
		}

		Markers::remove( $done );
		$job['work']['calls'] += $used;
		$job['finished']       = count( $rows ) < $calls;

		return $this->remember( $job, $restored );
	}

	/**
	 * Removes the aliases the trash recorded, now that the subpages are back.
	 * The page's own entry for the path it is back at goes too; an entry for a
	 * path it no longer has stays and leads to it.
	 *
	 * @param array<string,mixed> $job Finished restore job.
	 */
	private function finish_restore( array $job ): void {
		$root    = (int) $job['root'];
		$aliases = get_post_meta( $root, Markers::ALIASES, true );
		foreach ( is_array( $aliases ) ? $aliases : [] as $alias ) {
			$this->ledger->forget_alias( (string) $job['post_type'], (int) ( $alias['to'] ?? 0 ), (string) ( $alias['path'] ?? '' ) );
		}
		delete_post_meta( $root, Markers::ALIASES );
		$this->ledger->prune( $root );
	}

	/**
	 * Deletes marker rows that point at a permanently deleted root.
	 *
	 * @param array<string,mixed> $job   Job.
	 * @param int                 $moves Most rows to delete.
	 * @return array<string,mixed>
	 */
	private function step_cleanup( array $job, int $moves ): array {
		if ( $moves < 1 ) {
			return $job;
		}

		$root = (int) $job['root'];
		$rows = Markers::rows( Markers::moved_key( $root ), $moves );
		if ( count( $rows ) < $moves ) {
			$rows = array_merge( $rows, Markers::rows( Markers::trashed_key( $root ), $moves - count( $rows ) ) );
		}
		Markers::remove( $rows );

		$job['work']['moves'] = count( $rows );
		$job['finished']      = count( $rows ) < $moves;
		return $job;
	}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `npm run test:php -- --filter Operations`
Expected: `OK (37 tests, 146 assertions)`.

Run: `npm run test:php` and `composer lint`
Expected: all green; lint exit 0.

- [ ] **Step 5: Commit**

```bash
git add includes tests
git commit -m "feat: restore step brings subpages back with their slugs and statuses; cleanup for deleted roots"
```

---

### Task 8: Batch queue, processor, watchdog and WP-CLI command

**Files:**
- Create: `includes/HierarchyGuard/Batch/Queue.php`, `includes/HierarchyGuard/Batch/Processor.php`, `includes/HierarchyGuard/Batch/CLI_Command.php`
- Modify: `includes/HierarchyGuard/Plugin.php`, `hierarchy-guard.php`
- Test: `tests/phpunit/QueueTest.php`, `tests/phpunit/ProcessorTest.php`, `tests/phpunit/CLICommandTest.php`

**Interfaces:**
- Consumes: `Operations::step()`, `threshold()`, `core_threshold()`, `new_job()`, `TYPE_*` (Tasks 5 to 7); `Change_Log::update()`, `record()` (Task 4); `Ledger::deferred()` (Task 3); `Subtree::status()` (Task 2).
- Produces: `Batch\Queue` constants `HOOK`, `WATCHDOG`, `GROUP`, `JOB_PREFIX`, `OFFER_SUFFIX`, `LOCK_PREFIX`, `LOCK_TTL`; static `job_option( int )`, `offer_option( int )`, `lock_option( int )`; instance `save( array $job )` (stamps `touched`), `load( int $root_id ): ?array`, `delete( int $root_id )`, `offer( array $job )`, `offered( int $root_id ): ?array`, `take_offer( int $root_id ): ?array`, `discard_offer( int $root_id )`, `roots( int $limit = 100 ): int[]`, `lock( int $root_id ): int` (token, 0 when taken), `unlock( int $root_id, int $token )`, `schedule( int $root_id, bool $continuation = false ): bool`, `is_scheduled( int $root_id ): bool`.
- Produces: `Batch\Processor` constants `TIME_LIMIT`, `STALE_AFTER`, `GIVE_UP_AFTER`; `__construct( Queue $queue, Operations $operations, Change_Log $log )`; `register()`; `handle( mixed $root_id )`; `run( int $root_id, int $time_limit = 0, int $pause_ms = 0, int $max_steps = 0 ): array{state:string,job:?array}` (state `busy`, `idle`, `done` or `queued`); `watchdog()`; static `schedule_watchdog()`, `activate()`, `deactivate()`. Fires `hierarchy_guard_job_finished( array $job )`.
- Produces: `Batch\CLI_Command::process_jobs( array $args, array $assoc_args )` as `wp hierarchy-guard process-jobs`; static `describe( array $job ): string`, `subpages( int $count ): string`.

- [ ] **Step 1: Write the failing tests**

`tests/phpunit/QueueTest.php`:
```php
<?php
/**
 * Job slots, offers, locks and scheduling.
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
		$this->queue = new Queue();
	}

	public function tear_down(): void {
		wp_unschedule_hook( Queue::HOOK );
		parent::tear_down();
	}

	public function test_a_saved_job_round_trips_without_autoloading(): void {
		global $wpdb;

		$job = Operations::new_job( Operations::TYPE_REPARENT, 10, 'page', 0, [ 'to' => 2 ] );
		$this->queue->save( $job );

		$loaded = $this->queue->load( 10 );
		$this->assertSame( $job['id'], $loaded['id'] );
		$this->assertSame( 2, $loaded['to'] );
		$this->assertGreaterThanOrEqual( $job['touched'], $loaded['touched'] );
		$this->assertSame( 'off', $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", Queue::job_option( 10 ) ) ) );
		$this->assertNull( $this->queue->load( 11 ) );
	}

	public function test_saving_again_replaces_the_root_s_job(): void {
		$first  = Operations::new_job( Operations::TYPE_REPARENT, 10, 'page', 0 );
		$second = Operations::new_job( Operations::TYPE_RESTORE, 10, 'page', 0 );
		$this->queue->save( $first );
		$this->queue->save( $second );

		$this->assertSame( $second['id'], $this->queue->load( 10 )['id'] );

		$this->queue->delete( 10 );
		$this->assertNull( $this->queue->load( 10 ) );
	}

	public function test_an_offer_is_taken_once(): void {
		$job = Operations::new_job( Operations::TYPE_RESTORE, 10, 'page', 0 );
		$this->queue->offer( $job );

		$this->assertSame( $job['id'], $this->queue->offered( 10 )['id'] );
		$this->assertSame( $job['id'], $this->queue->take_offer( 10 )['id'] );
		$this->assertNull( $this->queue->take_offer( 10 ) );
		$this->assertNull( $this->queue->load( 10 ), 'An offer is not a slot.' );
	}

	public function test_roots_lists_slots_and_offers_once(): void {
		$this->queue->save( Operations::new_job( Operations::TYPE_REPARENT, 10, 'page', 0 ) );
		$this->queue->offer( Operations::new_job( Operations::TYPE_RESTORE, 10, 'page', 0 ) );
		$this->queue->offer( Operations::new_job( Operations::TYPE_RESTORE, 11, 'page', 0 ) );

		$this->assertSame( [ 10, 11 ], $this->queue->roots() );
	}

	public function test_a_lock_is_held_until_its_owner_releases_it(): void {
		$token = $this->queue->lock( 10 );

		$this->assertGreaterThan( 0, $token );
		$this->assertSame( 0, $this->queue->lock( 10 ) );
		$this->assertGreaterThan( 0, $this->queue->lock( 11 ), 'Locks are per root.' );

		$this->queue->unlock( 10, $token + 1 );
		$this->assertSame( 0, $this->queue->lock( 10 ), 'Only the owner releases it.' );

		$this->queue->unlock( 10, $token );
		$this->assertGreaterThan( 0, $this->queue->lock( 10 ) );
	}

	public function test_an_expired_lock_is_taken_over(): void {
		global $wpdb;

		$this->queue->lock( 10 );
		$wpdb->update( $wpdb->options, [ 'option_value' => (string) ( time() - 1 ) ], [ 'option_name' => Queue::lock_option( 10 ) ] );

		$this->assertGreaterThan( 0, $this->queue->lock( 10 ) );
		$this->assertSame( 0, $this->queue->lock( 10 ) );
	}

	public function test_schedule_uses_wp_cron_without_action_scheduler(): void {
		$this->assertFalse( function_exists( 'as_enqueue_async_action' ), 'Precondition: Action Scheduler is not loaded in the test site.' );
		$this->assertFalse( $this->queue->is_scheduled( 10 ) );

		$this->assertTrue( $this->queue->schedule( 10 ) );
		$this->assertTrue( $this->queue->schedule( 10 ), 'An existing event counts.' );

		$this->assertTrue( $this->queue->is_scheduled( 10 ) );
		$this->assertCount( 1, array_filter( _get_cron_array(), static fn( array $hooks ): bool => isset( $hooks[ Queue::HOOK ] ) ) );
	}
}
```

`tests/phpunit/ProcessorTest.php`:
```php
<?php
/**
 * Background runs and the watchdog.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Batch\Processor;
use HierarchyGuard\Batch\Queue;
use HierarchyGuard\Ledger;
use HierarchyGuard\Subtree;
use HierarchyGuard\Trash\Change_Log;
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

	private Change_Log $log;

	private Processor $processor;

	/**
	 * The queued job.
	 *
	 * @var array<string,mixed>
	 */
	private array $job;

	public function set_up(): void {
		parent::set_up();
		Change_Log::reset_request();
		add_filter( 'hierarchy_guard_batch_threshold', static fn(): int => 2 );
		$this->queue     = new Queue();
		$this->log       = new Change_Log();
		$this->processor = new Processor( $this->queue, new Operations( new Ledger(), new Mover() ), $this->log );
		$this->ids       = $this->make_tree();
		foreach ( [ 'a', 'b', 'c' ] as $slug ) {
			$this->page( $slug, $this->ids['about'] );
		}
		$this->trash_raw( $this->ids['about'] );
		$this->job = Operations::new_job( Operations::TYPE_REPARENT, $this->ids['about'], 'page', 0, [ 'to' => $this->ids['company'] ] );
		$this->queue->save( $this->job );
	}

	public function tear_down(): void {
		wp_unschedule_hook( Queue::HOOK );
		wp_unschedule_hook( Queue::WATCHDOG );
		parent::tear_down();
	}

	/**
	 * Rewrites the stored job's timestamps.
	 *
	 * @param int $created Created time.
	 * @param int $touched Touched time.
	 */
	private function age_job( int $created, int $touched ): void {
		global $wpdb;
		$job            = $this->queue->load( $this->ids['about'] );
		$job['created'] = $created;
		$job['touched'] = $touched;
		$wpdb->update( $wpdb->options, [ 'option_value' => maybe_serialize( $job ) ], [ 'option_name' => Queue::job_option( $this->ids['about'] ) ] );
	}

	public function test_the_processor_is_registered_by_the_plugin(): void {
		global $wp_filter;
		foreach ( [
			Queue::HOOK     => 'handle',
			Queue::WATCHDOG => 'watchdog',
		] as $hook => $method ) {
			$found = false;
			foreach ( $wp_filter[ $hook ]->callbacks[10] ?? [] as $callback ) {
				if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof Processor && $method === $callback['function'][1] ) {
					$found = true;
				}
			}
			$this->assertTrue( $found, $hook );
		}
		$this->assertSame( 10, has_action( 'admin_init', [ Processor::class, 'schedule_watchdog' ] ) );
	}

	public function test_a_run_finishes_the_job_and_removes_it(): void {
		$finished = [];
		add_action(
			'hierarchy_guard_job_finished',
			static function ( array $job ) use ( &$finished ): void {
				$finished[] = $job;
			}
		);

		$result = $this->processor->run( $this->ids['about'] );

		$this->assertSame( 'done', $result['state'] );
		$this->assertSame( 5, $result['job']['counts']['moved'] );
		$this->assertNull( $this->queue->load( $this->ids['about'] ) );
		$this->assertCount( 1, $finished );
		$this->assertSame( 0, Subtree::count_live_children( $this->ids['about'], 'page', 10 ) );
		$this->assertFalse( $this->queue->is_scheduled( $this->ids['about'] ) );
	}

	public function test_a_run_that_stops_early_saves_progress_and_schedules_the_rest(): void {
		$result = $this->processor->run( $this->ids['about'], 0, 0, 1 );

		$this->assertSame( 'queued', $result['state'] );
		$this->assertSame( 2, $this->queue->load( $this->ids['about'] )['counts']['moved'] );
		$this->assertTrue( $this->queue->is_scheduled( $this->ids['about'] ) );
	}

	public function test_a_run_backs_off_while_another_holds_the_lock(): void {
		$token = $this->queue->lock( $this->ids['about'] );

		$result = $this->processor->run( $this->ids['about'] );

		$this->assertSame( 'busy', $result['state'] );
		$this->assertSame( 0, $this->queue->load( $this->ids['about'] )['counts']['moved'] );
		$this->queue->unlock( $this->ids['about'], $token );
	}

	public function test_a_run_keeps_the_summary_current(): void {
		$admin         = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$entry         = [
			'root'   => $this->ids['about'],
			'policy' => 'reparent',
			'job'    => true,
		];
		$job           = $this->queue->load( $this->ids['about'] );
		$job['user']   = $admin;
		$job['change'] = $this->log->record( $admin, 'trash', 'page', $entry );
		$this->queue->save( $job );

		$this->processor->run( $this->ids['about'] );

		$root = $this->log->latest( $admin )['roots'][ $this->ids['about'] ];
		$this->assertSame( 5, $root['counts']['moved'] );
		$this->assertFalse( $root['job'] );
	}

	public function test_an_offer_replaces_the_job_and_closes_its_summary(): void {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$job   = $this->queue->load( $this->ids['about'] );
		$entry = [
			'root' => $this->ids['about'],
			'job'  => true,
		];

		$job['user']   = $admin;
		$job['change'] = $this->log->record( $admin, 'trash', 'page', $entry );
		$this->queue->save( $job );
		$this->untrash_raw( $this->ids['about'] );
		$this->queue->offer( Operations::new_job( Operations::TYPE_RESTORE, $this->ids['about'], 'page', 0 ) );

		$result = $this->processor->run( $this->ids['about'] );

		$this->assertSame( Operations::TYPE_RESTORE, $result['job']['type'] );
		$this->assertNull( $this->queue->offered( $this->ids['about'] ) );
		$this->assertFalse( $this->log->latest( $admin )['roots'][ $this->ids['about'] ]['job'] );
	}

	public function test_nothing_to_do_is_idle(): void {
		$this->queue->delete( $this->ids['about'] );
		$this->assertSame( 'idle', $this->processor->run( $this->ids['about'] )['state'] );
	}

	public function test_the_watchdog_restarts_a_stalled_job(): void {
		$this->age_job( time(), time() - HOUR_IN_SECONDS );
		$this->assertFalse( $this->queue->is_scheduled( $this->ids['about'] ), 'Precondition: its run died.' );

		$this->processor->watchdog();

		$this->assertTrue( $this->queue->is_scheduled( $this->ids['about'] ) );
	}

	public function test_the_watchdog_leaves_a_recent_job_alone(): void {
		$this->processor->watchdog();
		$this->assertFalse( $this->queue->is_scheduled( $this->ids['about'] ) );
	}

	public function test_the_watchdog_gives_up_after_a_week(): void {
		$this->age_job( time() - 8 * DAY_IN_SECONDS, time() - HOUR_IN_SECONDS );

		$this->processor->watchdog();

		$this->assertNull( $this->queue->load( $this->ids['about'] ) );
	}

	public function test_the_watchdog_drops_a_job_whose_root_is_gone_but_keeps_a_cleanup(): void {
		global $wpdb;
		$wpdb->delete( $wpdb->posts, [ 'ID' => $this->ids['about'] ] );
		$this->queue->save( Operations::new_job( Operations::TYPE_CLEANUP, 999999, 'page', 0 ) );

		$this->processor->watchdog();

		$this->assertNull( $this->queue->load( $this->ids['about'] ) );
		$this->assertNotNull( $this->queue->load( 999999 ) );
	}

	public function test_the_watchdog_is_scheduled_hourly_once_and_cleared_on_deactivation(): void {
		Processor::schedule_watchdog();
		$first = wp_next_scheduled( Queue::WATCHDOG );
		Processor::schedule_watchdog();

		$this->assertNotFalse( $first );
		$this->assertSame( $first, wp_next_scheduled( Queue::WATCHDOG ) );
		$this->assertSame( 'hourly', wp_get_schedule( Queue::WATCHDOG ) );

		$this->queue->schedule( $this->ids['about'] );
		Processor::deactivate();

		$this->assertFalse( wp_next_scheduled( Queue::WATCHDOG ) );
		$this->assertFalse( $this->queue->is_scheduled( $this->ids['about'] ) );
		$this->assertNotNull( $this->queue->load( $this->ids['about'] ), 'Jobs survive deactivation.' );
	}
}
```

`tests/phpunit/CLICommandTest.php`:
```php
<?php
/**
 * WP-CLI output lines.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Batch\CLI_Command;
use HierarchyGuard\Trash\Operations;
use WP_UnitTestCase;

/**
 * @covers \HierarchyGuard\Batch\CLI_Command
 */
final class CLICommandTest extends WP_UnitTestCase {

	/**
	 * A job with counts.
	 *
	 * @param string            $type   Job type.
	 * @param int               $to     New parent.
	 * @param array<string,int> $counts Counts.
	 * @return array<string,mixed>
	 */
	private function job( string $type, int $to, array $counts ): array {
		$job           = Operations::new_job( $type, 12, 'page', 0, [ 'to' => $to ] );
		$job['counts'] = array_merge( $job['counts'], $counts );
		return $job;
	}

	public function test_describes_each_kind_of_job(): void {
		$this->assertSame( 'Page 12: moved 1,200 subpages up under page 7.', CLI_Command::describe( $this->job( Operations::TYPE_REPARENT, 7, [ 'moved' => 1200 ] ) ) );
		$this->assertSame( 'Page 12: moved 1 subpage to the top level.', CLI_Command::describe( $this->job( Operations::TYPE_REPARENT, 0, [ 'moved' => 1 ] ) ) );
		$this->assertSame( 'Page 12: trashed 40 subpages.', CLI_Command::describe( $this->job( Operations::TYPE_TRASH, 0, [ 'trashed' => 40 ] ) ) );
		$this->assertSame( 'Page 12: moved 4 subpages back.', CLI_Command::describe( $this->job( Operations::TYPE_RESTORE, 0, [ 'moved' => 4 ] ) ) );
		$this->assertSame( 'Page 12: restored 3 subpages from the trash.', CLI_Command::describe( $this->job( Operations::TYPE_RESTORE, 0, [ 'restored' => 3 ] ) ) );
		$this->assertSame(
			'Page 12: moved 4 subpages back and restored 3 from the trash.',
			CLI_Command::describe(
				$this->job(
					Operations::TYPE_RESTORE,
					0,
					[
						'moved'    => 4,
						'restored' => 3,
					]
				)
			)
		);
		$this->assertSame( 'Page 12: cleaned up after the deleted page.', CLI_Command::describe( $this->job( Operations::TYPE_CLEANUP, 0, [] ) ) );
	}
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npm run test:php -- --filter 'QueueTest|ProcessorTest|CLICommandTest'`
Expected: `Error: Class "HierarchyGuard\Batch\Queue" not found`, and the same for `Processor` and `CLI_Command`.

- [ ] **Step 3: Create Queue, Processor and CLI_Command**

`includes/HierarchyGuard/Batch/Queue.php`:
```php
<?php
/**
 * Background job storage, locks and scheduling.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Batch;

/**
 * One job slot per root page, so a trash job and a restore job for the same
 * page can never both be pending. Only the holder of the root's lock writes
 * the slot; a request that finds the lock taken leaves its job as an offer,
 * which the holder adopts after its current step.
 *
 * Slots, offers and locks are option rows read and written with direct
 * queries. They are not autoloaded, and get_option() would cache values that
 * another process changes underneath, or fill the notoptions cache with one
 * entry per page.
 */
final class Queue {

	public const HOOK         = 'hierarchy_guard_process_batch';
	public const WATCHDOG     = 'hierarchy_guard_watchdog';
	public const GROUP        = 'hierarchy-guard';
	public const JOB_PREFIX   = 'hierarchy_guard_job_';
	public const OFFER_SUFFIX = '_next';
	public const LOCK_PREFIX  = 'hierarchy_guard_lock_';
	public const LOCK_TTL     = 300;

	/**
	 * Option name of a root's job slot.
	 *
	 * @param int $root_id Root page.
	 * @return string
	 */
	public static function job_option( int $root_id ): string {
		return self::JOB_PREFIX . $root_id;
	}

	/**
	 * Option name of a root's offer.
	 *
	 * @param int $root_id Root page.
	 * @return string
	 */
	public static function offer_option( int $root_id ): string {
		return self::JOB_PREFIX . $root_id . self::OFFER_SUFFIX;
	}

	/**
	 * Option name of a root's lock.
	 *
	 * @param int $root_id Root page.
	 * @return string
	 */
	public static function lock_option( int $root_id ): string {
		return self::LOCK_PREFIX . $root_id;
	}

	/**
	 * Stores a job in its root's slot, replacing whatever was there.
	 *
	 * @param array<string,mixed> $job Job.
	 */
	public function save( array $job ): void {
		$job['touched'] = time();
		$this->write( self::job_option( (int) $job['root'] ), $job );
	}

	/**
	 * The job in a root's slot.
	 *
	 * @param int $root_id Root page.
	 * @return array<string,mixed>|null
	 */
	public function load( int $root_id ): ?array {
		return $this->decode( $this->read( self::job_option( $root_id ) ) );
	}

	/**
	 * Empties a root's slot.
	 *
	 * @param int $root_id Root page.
	 */
	public function delete( int $root_id ): void {
		$this->erase( self::job_option( $root_id ) );
	}

	/**
	 * Leaves a job for whoever holds the root's lock.
	 *
	 * @param array<string,mixed> $job Job.
	 */
	public function offer( array $job ): void {
		$job['touched'] = time();
		$this->write( self::offer_option( (int) $job['root'] ), $job );
	}

	/**
	 * The offer waiting for a root, left in place.
	 *
	 * @param int $root_id Root page.
	 * @return array<string,mixed>|null
	 */
	public function offered( int $root_id ): ?array {
		return $this->decode( $this->read( self::offer_option( $root_id ) ) );
	}

	/**
	 * Takes the offer waiting for a root. If a newer offer replaces it in
	 * between, nothing is taken and the next look finds the newer one.
	 *
	 * @param int $root_id Root page.
	 * @return array<string,mixed>|null
	 */
	public function take_offer( int $root_id ): ?array {
		global $wpdb;

		$name = self::offer_option( $root_id );
		$raw  = $this->read( $name );
		if ( null === $raw ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Delete only the value read, so a newer offer survives.
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, $raw ) );

		return 1 === $deleted ? $this->decode( $raw ) : null;
	}

	/**
	 * Drops the offer waiting for a root.
	 *
	 * @param int $root_id Root page.
	 */
	public function discard_offer( int $root_id ): void {
		$this->erase( self::offer_option( $root_id ) );
	}

	/**
	 * Roots with a stored job or offer, oldest first.
	 *
	 * @param int $limit Most option rows to read.
	 * @return int[]
	 */
	public function roots( int $limit = 100 ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prefix range on the option_name index, bounded by LIMIT.
		$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id ASC LIMIT %d", $wpdb->esc_like( self::JOB_PREFIX ) . '%', max( 1, $limit ) ) );

		$roots = [];
		foreach ( $names as $name ) {
			if ( 1 === preg_match( '/^' . preg_quote( self::JOB_PREFIX, '/' ) . '(\d+)(?:' . preg_quote( self::OFFER_SUFFIX, '/' ) . ')?$/', (string) $name, $match ) ) {
				$roots[ (int) $match[1] ] = true;
			}
		}
		return array_keys( $roots );
	}

	/**
	 * Takes a root's lock. The insert fails while another process holds it;
	 * a lock left by a process that died is taken over once it expires.
	 *
	 * @param int $root_id Root page.
	 * @return int A token for unlock(), or 0 when the lock is taken.
	 */
	public function lock( int $root_id ): int {
		global $wpdb;

		$name  = self::lock_option( $root_id );
		$now   = time();
		$token = $now + self::LOCK_TTL;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- add_option() is an upsert, so it cannot serve as a mutex.
		$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'off' )", $name, (string) $token ) );
		if ( 1 === $inserted ) {
			return $token;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic takeover of an expired lock.
		$taken = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND CAST( option_value AS UNSIGNED ) < %d", (string) $token, $name, $now ) );

		return 1 === $taken ? $token : 0;
	}

	/**
	 * Releases a root's lock, unless another process has taken it over since.
	 *
	 * @param int $root_id Root page.
	 * @param int $token   Token lock() returned.
	 */
	public function unlock( int $root_id, int $token ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Releases only this process's own lock.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::lock_option( $root_id ), (string) $token ) );
	}

	/**
	 * Schedules a run for a root through Action Scheduler when a site has it, else WP-Cron.
	 *
	 * @param int  $root_id      Root page.
	 * @param bool $continuation True when called from the run itself, which Action Scheduler still lists as in progress.
	 * @return bool Whether a run is scheduled.
	 */
	public function schedule( int $root_id, bool $continuation = false ): bool {
		$args = [ $root_id ];

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			if ( ! $continuation && function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( self::HOOK, $args, self::GROUP ) ) {
				return true;
			}
			if ( as_enqueue_async_action( self::HOOK, $args, self::GROUP ) ) {
				return true;
			}
			// Action Scheduler returns 0 before it has initialized; WP-Cron takes over.
		}

		if ( false !== wp_next_scheduled( self::HOOK, $args ) ) {
			return true;
		}
		return true === wp_schedule_single_event( time(), self::HOOK, $args );
	}

	/**
	 * Whether a run is scheduled for a root.
	 *
	 * @param int $root_id Root page.
	 * @return bool
	 */
	public function is_scheduled( int $root_id ): bool {
		$args = [ $root_id ];
		if ( false !== wp_next_scheduled( self::HOOK, $args ) ) {
			return true;
		}
		return function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( self::HOOK, $args, self::GROUP );
	}

	/**
	 * Inserts or replaces an option row.
	 *
	 * @param string              $name  Option name.
	 * @param array<string,mixed> $value Value.
	 */
	private function write( string $name, array $value ): void {
		global $wpdb;

		$data = maybe_serialize( $value );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- See the class docblock.
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'off' ) ON DUPLICATE KEY UPDATE option_value = %s", $name, $data, $data ) );
	}

	/**
	 * Reads an option row.
	 *
	 * @param string $name Option name.
	 * @return string|null The raw value, or null when there is no row.
	 */
	private function read( string $name ): ?string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- See the class docblock.
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );

		return null === $raw ? null : (string) $raw;
	}

	/**
	 * Deletes an option row.
	 *
	 * @param string $name Option name.
	 */
	private function erase( string $name ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- See the class docblock.
		$wpdb->delete( $wpdb->options, [ 'option_name' => $name ], [ '%s' ] );
	}

	/**
	 * Unserializes a stored job.
	 *
	 * @param string|null $raw Raw value.
	 * @return array<string,mixed>|null
	 */
	private function decode( ?string $raw ): ?array {
		if ( null === $raw ) {
			return null;
		}
		$job = maybe_unserialize( $raw );
		return is_array( $job ) ? $job : null;
	}
}
```

`includes/HierarchyGuard/Batch/Processor.php`:
```php
<?php
/**
 * Runs queued jobs in time-boxed batches.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Batch;

use HierarchyGuard\Ledger;
use HierarchyGuard\Subtree;
use HierarchyGuard\Trash\Change_Log;
use HierarchyGuard\Trash\Operations;

/**
 * Each run holds the root's lock and steps its job until the job is done or
 * the run's time or memory runs low, then schedules the next run. An hourly
 * watchdog restarts a job whose run died, and gives up on one after a week.
 */
final class Processor {

	public const TIME_LIMIT    = 20;
	public const STALE_AFTER   = 10 * MINUTE_IN_SECONDS;
	public const GIVE_UP_AFTER = 7 * DAY_IN_SECONDS;

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
	 * Change log.
	 *
	 * @var Change_Log
	 */
	private Change_Log $log;

	/**
	 * Constructor.
	 *
	 * @param Queue      $queue      Queue.
	 * @param Operations $operations Operations.
	 * @param Change_Log $log        Change log.
	 */
	public function __construct( Queue $queue, Operations $operations, Change_Log $log ) {
		$this->queue      = $queue;
		$this->operations = $operations;
		$this->log        = $log;
	}

	/**
	 * Hooks into the schedulers.
	 */
	public function register(): void {
		add_action( Queue::HOOK, [ $this, 'handle' ] );
		add_action( Queue::WATCHDOG, [ $this, 'watchdog' ] );
		add_action( 'admin_init', [ self::class, 'schedule_watchdog' ] );
	}

	/**
	 * Scheduler entry point: one time-boxed run for a root.
	 *
	 * @param mixed $root_id Root page, as the scheduler passes it.
	 */
	public function handle( $root_id ): void {
		$this->run( (int) $root_id, $this->time_limit() );
	}

	/**
	 * Steps a root's job while holding its lock.
	 *
	 * @param int $root_id    Root page.
	 * @param int $time_limit Seconds before the run stops and schedules the rest, 0 for no limit.
	 * @param int $pause_ms   Pause between steps, in milliseconds.
	 * @param int $max_steps  Steps before the run stops and schedules the rest, 0 for no limit.
	 * @return array{state:string,job:array<string,mixed>|null} state is busy, idle, done or queued; job is the last one stepped.
	 */
	public function run( int $root_id, int $time_limit = 0, int $pause_ms = 0, int $max_steps = 0 ): array {
		$token = $this->queue->lock( $root_id );
		if ( 0 === $token ) {
			// Another run holds the lock; it reschedules itself if work is left.
			return [
				'state' => 'busy',
				'job'   => null,
			];
		}

		$start = microtime( true );
		$job   = null;
		$last  = null;
		$steps = 0;
		try {
			while ( true ) {
				$job = $this->adopt( $root_id, $job );
				if ( null === $job ) {
					break;
				}

				$job  = Ledger::deferred( fn() => $this->operations->step( $job, Operations::threshold(), Operations::core_threshold() ) );
				$last = $job;
				$done = ! empty( $job['finished'] );
				$this->log->update( (int) $job['user'], (string) $job['change'], $root_id, $job['counts'], $job['kept_ids'], $job['ids'], ! $done );

				if ( $done ) {
					$this->queue->delete( $root_id );

					/**
					 * Fires when a background batch job completes.
					 *
					 * @param array<string,mixed> $job The finished job.
					 */
					do_action( 'hierarchy_guard_job_finished', $job );
					$job = null;
					continue;
				}

				$this->queue->save( $job );
				++$steps;
				if ( ( $max_steps > 0 && $steps >= $max_steps ) || $this->out_of_time( $start, $time_limit ) ) {
					break;
				}
				$this->flush_runtime();
				if ( $pause_ms > 0 ) {
					usleep( $pause_ms * 1000 );
				}
			}
		} finally {
			$this->queue->unlock( $root_id, $token );
		}

		// Scheduled after the unlock, so the next run cannot find this one still holding it.
		if ( null !== $job || null !== $this->queue->offered( $root_id ) ) {
			$this->queue->schedule( $root_id, true );
		}

		if ( null !== $job ) {
			$state = 'queued';
		} else {
			$state = null === $last ? 'idle' : 'done';
		}

		return [
			'state' => $state,
			'job'   => $last,
		];
	}

	/**
	 * Restarts jobs whose run died and drops jobs that can no longer finish.
	 */
	public function watchdog(): void {
		$now = time();
		foreach ( $this->queue->roots() as $root_id ) {
			$job   = $this->queue->load( $root_id );
			$offer = $this->queue->offered( $root_id );
			$any   = $job ?? $offer;
			if ( null === $any ) {
				continue;
			}

			$gone = Operations::TYPE_CLEANUP !== ( $any['type'] ?? '' ) && '' === Subtree::status( $root_id );
			if ( $gone || (int) ( $any['created'] ?? 0 ) < $now - self::GIVE_UP_AFTER ) {
				$this->queue->delete( $root_id );
				$this->queue->discard_offer( $root_id );
				continue;
			}

			$touched = max( (int) ( $job['touched'] ?? 0 ), (int) ( $offer['touched'] ?? 0 ) );
			if ( $touched < $now - self::STALE_AFTER && ! $this->queue->is_scheduled( $root_id ) ) {
				$this->queue->schedule( $root_id );
			}
		}
	}

	/**
	 * Schedules the hourly watchdog if it is missing.
	 */
	public static function schedule_watchdog(): void {
		if ( false === wp_next_scheduled( Queue::WATCHDOG ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', Queue::WATCHDOG );
		}
	}

	/**
	 * Activation: start the watchdog, which also restarts jobs left from before.
	 */
	public static function activate(): void {
		self::schedule_watchdog();
	}

	/**
	 * Deactivation: stop scheduled runs. Stored jobs stay, and the watchdog
	 * restarts them after reactivation.
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( Queue::WATCHDOG );
		wp_unschedule_hook( Queue::HOOK );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', [], Queue::GROUP );
		}
	}

	/**
	 * The job to step next: a waiting offer replaces the current job.
	 *
	 * @param int                      $root_id Root page.
	 * @param array<string,mixed>|null $current Job stepped so far in this run.
	 * @return array<string,mixed>|null
	 */
	private function adopt( int $root_id, ?array $current ): ?array {
		$offer = $this->queue->take_offer( $root_id );
		if ( null === $offer ) {
			return $current ?? $this->queue->load( $root_id );
		}

		$replaced = $current ?? $this->queue->load( $root_id );
		if ( null !== $replaced ) {
			$this->log->update( (int) $replaced['user'], (string) $replaced['change'], $root_id, $replaced['counts'], $replaced['kept_ids'], $replaced['ids'], false );
		}
		$this->queue->save( $offer );
		return $offer;
	}

	/**
	 * Seconds a scheduled run may take: 20, or half of max_execution_time when that is lower.
	 *
	 * @return int
	 */
	private function time_limit(): int {
		$max   = (int) ini_get( 'max_execution_time' );
		$limit = self::TIME_LIMIT;
		if ( $max > 0 ) {
			$limit = min( $limit, intdiv( $max, 2 ) );
		}
		return max( 1, $limit );
	}

	/**
	 * Whether the run should stop: its time is up, or memory use passed 80%.
	 *
	 * @param float $start      microtime() at the start of the run.
	 * @param int   $time_limit Seconds, 0 for no limit.
	 * @return bool
	 */
	private function out_of_time( float $start, int $time_limit ): bool {
		if ( $time_limit > 0 && microtime( true ) - $start >= $time_limit ) {
			return true;
		}
		$memory = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );
		return $memory > 0 && memory_get_usage() > 0.8 * $memory;
	}

	/**
	 * Frees the in-process object cache between steps, so a long run does not
	 * keep every post it touched in memory.
	 */
	private function flush_runtime(): void {
		global $wpdb;

		if ( wp_cache_supports( 'flush_runtime' ) ) {
			wp_cache_flush_runtime();
		}
		if ( defined( 'SAVEQUERIES' ) && SAVEQUERIES ) {
			$wpdb->queries = [];
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

use HierarchyGuard\Trash\Operations;

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
	 * ## OPTIONS
	 *
	 * [--sleep=<milliseconds>]
	 * : Pause between batches, to spare database replicas.
	 * ---
	 * default: 250
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp hierarchy-guard process-jobs
	 *     wp hierarchy-guard process-jobs --sleep=0
	 *
	 * @subcommand process-jobs
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string,string> $assoc_args Options.
	 */
	public function process_jobs( array $args, array $assoc_args ): void {
		$pause     = max( 0, (int) ( $assoc_args['sleep'] ?? 250 ) );
		$processed = 0;
		$skipped   = [];

		for ( $pass = 0; $pass < 100; $pass++ ) {
			$roots = array_diff( $this->queue->roots(), $skipped );
			if ( [] === $roots ) {
				break;
			}
			foreach ( $roots as $root_id ) {
				$result = $this->drain( (int) $root_id, $pause );
				if ( 'busy' === $result['state'] ) {
					\WP_CLI::log( sprintf( 'Page %d: skipped, another process is working on it.', $root_id ) );
					$skipped[] = $root_id;
					continue;
				}
				if ( null !== $result['job'] ) {
					\WP_CLI::log( self::describe( $result['job'] ) );
				}
				++$processed;
			}
		}

		if ( 0 === $processed && [] === $skipped ) {
			\WP_CLI::success( 'No queued jobs.' );
			return;
		}
		\WP_CLI::success( 1 === $processed ? 'Processed 1 job.' : sprintf( 'Processed %d jobs.', $processed ) );
	}

	/**
	 * One line about a finished job.
	 *
	 * @param array<string,mixed> $job Job.
	 * @return string
	 */
	public static function describe( array $job ): string {
		$root   = (int) $job['root'];
		$counts = (array) $job['counts'];

		switch ( $job['type'] ) {
			case Operations::TYPE_REPARENT:
				if ( (int) $job['to'] > 0 ) {
					return sprintf( 'Page %d: moved %s up under page %d.', $root, self::subpages( (int) $counts['moved'] ), (int) $job['to'] );
				}
				return sprintf( 'Page %d: moved %s to the top level.', $root, self::subpages( (int) $counts['moved'] ) );
			case Operations::TYPE_TRASH:
				return sprintf( 'Page %d: trashed %s.', $root, self::subpages( (int) $counts['trashed'] ) );
			case Operations::TYPE_RESTORE:
				if ( (int) $counts['restored'] > 0 && 0 === (int) $counts['moved'] ) {
					return sprintf( 'Page %d: restored %s from the trash.', $root, self::subpages( (int) $counts['restored'] ) );
				}
				if ( (int) $counts['restored'] > 0 ) {
					return sprintf( 'Page %d: moved %s back and restored %s from the trash.', $root, self::subpages( (int) $counts['moved'] ), number_format( (int) $counts['restored'] ) );
				}
				return sprintf( 'Page %d: moved %s back.', $root, self::subpages( (int) $counts['moved'] ) );
		}
		return sprintf( 'Page %d: cleaned up after the deleted page.', $root );
	}

	/**
	 * "1 subpage", "1,200 subpages".
	 *
	 * @param int $count Count.
	 * @return string
	 */
	public static function subpages( int $count ): string {
		return number_format( $count ) . ( 1 === $count ? ' subpage' : ' subpages' );
	}

	/**
	 * Runs a root's job until it is done or another process holds it.
	 *
	 * @param int $root_id Root page.
	 * @param int $pause   Pause between steps, in milliseconds.
	 * @return array{state:string,job:array<string,mixed>|null}
	 */
	private function drain( int $root_id, int $pause ): array {
		$result = $this->processor->run( $root_id, 0, $pause );
		for ( $i = 0; $i < 1000 && 'queued' === $result['state']; $i++ ) {
			$result = $this->processor->run( $root_id, 0, $pause );
		}
		return $result;
	}
}
```

- [ ] **Step 4: Wire the queue and processor**

In `includes/HierarchyGuard/Plugin.php`, add after the `( new Trash\Markers() )->register();` line:
```php

		$log        = new Trash\Change_Log();
		$queue      = new Batch\Queue();
		$operations = new Trash\Operations( $ledger, new Trash\Mover() );
		$processor  = new Batch\Processor( $queue, $operations, $log );
		$processor->register();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'hierarchy-guard', new Batch\CLI_Command( $queue, $processor ) );
		}
```

In `hierarchy-guard.php`, add before `Plugin::instance()->register();`:
```php
register_activation_hook( __FILE__, [ Batch\Processor::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ Batch\Processor::class, 'deactivate' ] );

```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `npm run test:php -- --filter 'QueueTest|ProcessorTest|CLICommandTest'`
Expected: `OK (20 tests, 65 assertions)`.

Run: `npm run test:php` and `composer lint`
Expected: all green; lint exit 0.

- [ ] **Step 6: Commit**

```bash
git add hierarchy-guard.php includes tests
git commit -m "feat: per-root job queue with locks, time-boxed processor, watchdog and wp hierarchy-guard process-jobs"
```

---

### Task 9: Trash handler

**Files:**
- Create: `includes/HierarchyGuard/Trash/Trash_Handler.php`
- Modify: `includes/HierarchyGuard/Plugin.php`, `tests/phpunit/ChangeListenerTest.php`, `tests/phpunit/ResolverTest.php`
- Test: `tests/phpunit/TrashHandlerTest.php`

**Interfaces:**
- Consumes: everything above.
- Produces: `Trash\Trash_Handler::__construct( Ledger $ledger, Operations $operations, Queue $queue, Change_Log $log )`; `register()` (hooks `wp_trash_post`, `trashed_post`, `transition_post_status` at 10 with 3 args, `wp_after_insert_post` at 20, `shutdown`, `deleted_post` with 2 args, and filter `wp_untrash_post_status` at 20 with 3 args); `reset_request_budget()`; static `policy_for( WP_Post $post ): string`; `before_trash( mixed $post_id )`; `after_trash( mixed $post_id )`; `on_transition( mixed $new_status, mixed $old_status, mixed $post )`; `after_save( mixed $post_id )`; `flush_pending()`; `on_deleted( mixed $post_id, mixed $post )`; `undo( int $root_id ): true|WP_Error` (codes `hierarchy_guard_not_in_trash`, `hierarchy_guard_forbidden`, `hierarchy_guard_restore_failed`); `undo_status( mixed $new_status, mixed $post_id, mixed $previous_status ): string`; static `cli_lines( string $action, array $job, bool $queued ): string[]`. Filter `hierarchy_guard_trash_policy( string $policy, WP_Post $post )`.

Undo's guarantee, for plan 3's copy: `undo()` restores the page with the status it had; core's Restore link restores it as a draft, as core does. Either way its subpages come back with their own statuses.

- [ ] **Step 1: Pin the engine's trash tests to the keep policy**

`ChangeListenerTest` and `ResolverTest` exercise core's own trash behavior (subpages stay under the trashed parent), which is what the keep policy preserves. Once the handler is live, the default policy would move those subpages. In `tests/phpunit/ChangeListenerTest.php`, `set_up()`, add after `$this->set_permalink_structure( '/%postname%/' );`; and in `tests/phpunit/ResolverTest.php`, `set_up()`, add after `$this->set_permalink_structure( '/%postname%/' );`:
```php
		// These tests exercise core's own trash behaviour, which the keep policy leaves untouched.
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
use WP_Error;
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

	private Queue $queue;

	private Change_Log $log;

	public function set_up(): void {
		parent::set_up();
		delete_option( Settings::OPTION );
		delete_option( Ledger::ROOT_OPTION );
		Post_Types::reset();
		Change_Log::reset_request();
		$this->handler()->reset_request_budget();
		$this->set_permalink_structure( '/%postname%/' );
		$this->admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $this->admin );
		$this->ledger   = new Ledger();
		$this->resolver = new Resolver( $this->ledger );
		$this->queue    = new Queue();
		$this->log      = new Change_Log();
		$this->ids      = $this->make_tree();
	}

	public function tear_down(): void {
		$this->end_core_bulk();
		wp_unschedule_hook( Queue::HOOK );
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
	 * A processor like the plugin's.
	 */
	private function processor(): Processor {
		return new Processor( $this->queue, new Operations( $this->ledger, new Mover() ), $this->log );
	}

	/**
	 * Uses a fixed policy for the rest of the test.
	 *
	 * @param string $policy Policy.
	 */
	private function policy( string $policy ): void {
		add_filter( 'hierarchy_guard_trash_policy', static fn(): string => $policy );
	}

	/**
	 * Adds subpages under about.
	 *
	 * @param string[] $slugs Slugs.
	 */
	private function more_subpages( array $slugs ): void {
		foreach ( $slugs as $slug ) {
			$this->ids[ $slug ] = $this->page( $slug, $this->ids['about'] );
		}
	}

	/**
	 * The current user's change summary.
	 *
	 * @return array<string,mixed>
	 */
	private function change(): array {
		return (array) $this->log->latest( $this->admin );
	}

	/**
	 * Runs core's bulk loop the way edit.php does, failing where core would wp_die().
	 *
	 * @param string $action trash, untrash or undo.
	 * @param int[]  $ids    Selected IDs.
	 */
	private function core_bulk( string $action, array $ids ): void {
		$undo = 'undo' === $action;
		$this->start_core_bulk( $undo ? 'untrash' : $action, $ids, $undo );
		if ( $undo ) {
			add_filter( 'wp_untrash_post_status', 'wp_untrash_post_set_previous_status', 10, 3 );
		}
		foreach ( $ids as $id ) {
			$result = 'trash' === $action ? wp_trash_post( $id ) : wp_untrash_post( $id );
			$this->assertNotFalse( $result, "Core's bulk loop would stop with wp_die() at page {$id}." );
		}
		if ( $undo ) {
			remove_filter( 'wp_untrash_post_status', 'wp_untrash_post_set_previous_status', 10 );
		}
		$this->end_core_bulk();
	}

	public function test_the_handler_is_registered_by_the_plugin(): void {
		global $wp_filter;
		$hooks = [
			'wp_trash_post'          => 10,
			'trashed_post'           => 10,
			'transition_post_status' => 10,
			'wp_after_insert_post'   => 20,
			'shutdown'               => 10,
			'deleted_post'           => 10,
			'wp_untrash_post_status' => 20,
		];
		foreach ( $hooks as $hook => $priority ) {
			$found = false;
			foreach ( $wp_filter[ $hook ]->callbacks[ $priority ] ?? [] as $callback ) {
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
		$this->assertNull( $this->resolver->resolve( 'page', 'company/about' ), 'The trashed page itself is not redirected.' );

		$change = $this->change();
		$root   = $change['roots'][ $this->ids['about'] ];
		$this->assertSame( 'trash', $change['action'] );
		$this->assertSame( 'page', $change['post_type'] );
		$this->assertSame( Settings::POLICY_REPARENT, $root['policy'] );
		$this->assertSame( $this->ids['company'], $root['to'] );
		$this->assertSame( 2, $root['counts']['moved'] );
		$this->assertSame( 3, $root['urls']['count'] );
		$this->assertFalse( $root['job'] );
	}

	public function test_colliding_slug_gets_a_number_and_its_old_url_still_resolves(): void {
		$this->page( 'team', $this->ids['company'] );

		wp_trash_post( $this->ids['about'] );

		$this->assertSame( 'team-2', get_post( $this->ids['team'] )->post_name );
		$this->assertSame( $this->ids['team'], $this->resolver->resolve( 'page', 'company/about/team' )->ID );
		$this->assertSame( $this->ids['leadership'], $this->resolver->resolve( 'page', 'company/about/team/leadership' )->ID );
	}

	public function test_subpages_the_user_cannot_edit_stay_put(): void {
		$editor = self::factory()->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $editor );
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
		$root = $this->log->latest( $editor )['roots'][ $this->ids['about'] ];
		$this->assertSame( 1, $root['counts']['denied'] );
		$this->assertSame( [ $locked ], $root['kept_ids'] );
	}

	public function test_a_failed_trash_changes_nothing(): void {
		add_filter( 'wp_insert_post_empty_content', '__return_true' );

		$this->assertFalse( wp_trash_post( $this->ids['about'] ) );

		$this->assertSame( 'publish', get_post_status( $this->ids['about'] ) );
		$this->assertSame( $this->ids['about'], (int) get_post( $this->ids['team'] )->post_parent );
		$this->assertNull( $this->ledger->find( 'page', 'company/about' ) );
		$this->assertSame( [], $this->change() );
	}

	public function test_the_keep_policy_leaves_subpages_in_place(): void {
		$this->policy( Settings::POLICY_KEEP );

		wp_trash_post( $this->ids['about'] );

		$this->assertSame( $this->ids['about'], (int) get_post( $this->ids['team'] )->post_parent );
		$root = $this->change()['roots'][ $this->ids['about'] ];
		$this->assertSame( Settings::POLICY_KEEP, $root['policy'] );
		$this->assertSame( 2, $root['counts']['kept'] );
		$this->assertSame( 3, $root['urls']['count'] );
	}

	public function test_a_page_without_subpages_is_left_to_core(): void {
		wp_trash_post( $this->ids['history'] );

		$this->assertSame( [], $this->change() );
		$this->assertSame( [], $this->queue->roots() );
	}

	public function test_trash_children_then_undo_restores_previous_statuses(): void {
		$this->policy( Settings::POLICY_TRASH_CHILDREN );
		$plans = $this->page( 'plans', $this->ids['team'], 'draft' );

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

	public function test_core_s_restore_makes_the_parent_a_draft_and_subpages_keep_their_statuses(): void {
		$this->policy( Settings::POLICY_TRASH_CHILDREN );
		$plans = $this->page( 'plans', $this->ids['team'], 'draft' );
		wp_trash_post( $this->ids['about'] );

		wp_untrash_post( $this->ids['about'] );

		$this->assertSame( 'draft', get_post_status( $this->ids['about'] ), 'As core does.' );
		$this->assertSame( 'publish', get_post_status( $this->ids['team'] ) );
		$this->assertSame( 'publish', get_post_status( $this->ids['leadership'] ) );
		$this->assertSame( 'draft', get_post_status( $plans ) );
	}

	public function test_restoring_through_a_status_update_moves_subpages_back_after_the_save(): void {
		wp_trash_post( $this->ids['about'] );
		$during = null;
		$about  = $this->ids['about'];
		$team   = $this->ids['team'];
		add_action(
			'save_post_page',
			static function ( int $post_id ) use ( &$during, $about, $team ): void {
				if ( $post_id === $about ) {
					$during = (int) get_post( $team )->post_parent;
				}
			}
		);

		wp_update_post(
			[
				'ID'          => $this->ids['about'],
				'post_status' => 'publish',
			]
		);

		$this->assertSame( $this->ids['company'], $during, "The page's own save completes first." );
		$this->assertSame( 'about', get_post( $this->ids['about'] )->post_name, 'Precondition: core restored the slug.' );
		$this->assertSame( $this->ids['about'], (int) get_post( $this->ids['team'] )->post_parent );
		$this->assertNull( $this->ledger->find( 'page', 'company/about' ), 'The alias is gone once the page is back.' );
		$this->assertSame( 'restore', $this->change()['action'] );
		$this->assertSame( 2, $this->change()['counts']['moved'] );
	}

	public function test_undo_restores_the_parent_with_its_status_and_brings_subpages_back(): void {
		wp_trash_post( $this->ids['about'] );

		$this->assertTrue( $this->handler()->undo( $this->ids['about'] ) );

		$this->assertSame( 'publish', get_post_status( $this->ids['about'] ), 'Core alone would restore it as a draft.' );
		$this->assertSame( $this->ids['about'], (int) get_post( $this->ids['history'] )->post_parent );
	}

	public function test_undo_explains_why_it_cannot_run(): void {
		$result = $this->handler()->undo( $this->ids['about'] );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'hierarchy_guard_not_in_trash', $result->get_error_code() );
		$this->assertSame( '“About” is no longer in the trash.', $result->get_error_message() );

		wp_trash_post( $this->ids['about'] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$result = $this->handler()->undo( $this->ids['about'] );
		$this->assertSame( 'hierarchy_guard_forbidden', $result->get_error_code() );
		$this->assertSame( 'trash', get_post_status( $this->ids['about'] ) );

		wp_set_current_user( $this->admin );
		add_filter( 'pre_untrash_post', '__return_true' );
		$result = $this->handler()->undo( $this->ids['about'] );
		$this->assertSame( 'hierarchy_guard_restore_failed', $result->get_error_code(), 'A filter that answers true without restoring is no success.' );
		$this->assertSame( '“About” could not be restored from the trash.', $result->get_error_message() );
	}

	public function test_large_subtrees_move_in_background_batches(): void {
		add_filter( 'hierarchy_guard_batch_threshold', static fn(): int => 2 );
		$this->more_subpages( [ 'a', 'b', 'c' ] );

		wp_trash_post( $this->ids['about'] );

		$this->assertSame( 3, Subtree::count_live_children( $this->ids['about'], 'page', 10 ), 'Only the first batch ran inline.' );
		$this->assertTrue( $this->queue->is_scheduled( $this->ids['about'] ) );
		$this->assertTrue( $this->change()['roots'][ $this->ids['about'] ]['job'] );

		$this->processor()->run( $this->ids['about'] );

		$this->assertSame( 0, Subtree::count_live_children( $this->ids['about'], 'page', 10 ) );
		$this->assertNull( $this->queue->load( $this->ids['about'] ) );
		$root = $this->change()['roots'][ $this->ids['about'] ];
		$this->assertSame( 5, $root['counts']['moved'] );
		$this->assertFalse( $root['job'] );
	}

	public function test_a_bulk_trash_shares_one_budget_and_one_summary(): void {
		add_filter( 'hierarchy_guard_batch_threshold', static fn(): int => 3 );
		$services = $this->page( 'services' );
		$this->page( 'design', $services );
		$this->page( 'build', $services );

		$this->core_bulk( 'trash', [ $this->ids['about'], $services ] );

		$change = $this->change();
		$this->assertSame( [ $this->ids['about'], $services ], array_keys( $change['roots'] ) );
		$this->assertFalse( $change['roots'][ $this->ids['about'] ]['job'] );
		$this->assertTrue( $change['roots'][ $services ]['job'], 'The second parent got what was left of the budget.' );
		$this->assertSame( 3, $change['counts']['moved'] );
	}

	public function test_restoring_mid_batch_stops_the_job_and_brings_back_what_moved(): void {
		add_filter( 'hierarchy_guard_batch_threshold', static fn(): int => 2 );
		$this->more_subpages( [ 'a', 'b', 'c' ] );
		wp_trash_post( $this->ids['about'] );

		$this->assertTrue( $this->handler()->undo( $this->ids['about'] ) );
		$this->processor()->run( $this->ids['about'] );

		$this->assertSame( 5, Subtree::count_live_children( $this->ids['about'], 'page', 10 ) );
		$this->assertNull( $this->queue->load( $this->ids['about'] ) );
	}

	public function test_a_restore_while_a_batch_runs_is_handed_to_that_batch(): void {
		add_filter( 'hierarchy_guard_batch_threshold', static fn(): int => 1 );
		wp_trash_post( $this->ids['about'] );
		$token = $this->queue->lock( $this->ids['about'] );

		$this->handler()->undo( $this->ids['about'] );

		$this->assertSame( Operations::TYPE_RESTORE, $this->queue->offered( $this->ids['about'] )['type'] );
		$this->assertTrue( $this->change()['roots'][ $this->ids['about'] ]['job'] );

		$this->queue->unlock( $this->ids['about'], $token );
		$this->processor()->run( $this->ids['about'] );

		$this->assertSame( $this->ids['about'], (int) get_post( $this->ids['team'] )->post_parent );
		$this->assertSame( $this->ids['about'], (int) get_post( $this->ids['history'] )->post_parent );
	}

	public function test_bulk_trash_and_undo_of_a_parent_and_its_subpage_round_trip(): void {
		$contact = $this->page( 'contact' );

		$this->core_bulk( 'trash', [ $this->ids['about'], $this->ids['team'], $contact ] );

		$this->assertSame( $this->ids['company'], (int) get_post( $this->ids['history'] )->post_parent );
		$this->assertSame( $this->ids['company'], (int) get_post( $this->ids['leadership'] )->post_parent, 'Its own trash moved it past both trashed pages.' );

		$this->core_bulk( 'undo', [ $this->ids['about'], $this->ids['team'], $contact ] );

		$this->assertSame( $this->ids['about'], (int) get_post( $this->ids['history'] )->post_parent );
		$this->assertSame( $this->ids['team'], (int) get_post( $this->ids['leadership'] )->post_parent );
		foreach ( [ $this->ids['about'], $this->ids['team'], $contact ] as $id ) {
			$this->assertSame( 'publish', get_post_status( $id ), "Core's Undo keeps every page's status." );
		}
	}

	public function test_bulk_trash_and_undo_with_trash_children_round_trip(): void {
		$this->policy( Settings::POLICY_TRASH_CHILDREN );
		$contact = $this->page( 'contact' );

		$this->core_bulk( 'trash', [ $this->ids['about'], $this->ids['team'], $contact ] );

		foreach ( [ 'team', 'history', 'leadership' ] as $slug ) {
			$this->assertSame( 'trash', get_post_status( $this->ids[ $slug ] ), $slug );
		}

		$this->core_bulk( 'undo', [ $this->ids['about'], $this->ids['team'], $contact ] );

		foreach ( [ 'about', 'team', 'history', 'leadership' ] as $slug ) {
			$this->assertSame( 'publish', get_post_status( $this->ids[ $slug ] ), $slug );
		}
		$this->assertSame( 'publish', get_post_status( $contact ) );
	}

	public function test_restoring_everything_from_the_trash_view_never_stops_core(): void {
		$this->policy( Settings::POLICY_TRASH_CHILDREN );
		wp_trash_post( $this->ids['about'] );

		$this->core_bulk( 'untrash', [ $this->ids['about'], $this->ids['team'], $this->ids['history'], $this->ids['leadership'] ] );

		$this->assertSame( 'draft', get_post_status( $this->ids['about'] ), 'Selected on its own, as core does.' );
		foreach ( [ 'team', 'history', 'leadership' ] as $slug ) {
			$this->assertSame( 'publish', get_post_status( $this->ids[ $slug ] ), $slug );
		}
	}

	public function test_a_subpage_restored_on_its_own_stays_out_of_its_parent_s_restore(): void {
		$this->policy( Settings::POLICY_TRASH_CHILDREN );
		wp_trash_post( $this->ids['about'] );

		wp_untrash_post( $this->ids['history'] );
		$this->assertSame( 'publish', get_post_status( $this->ids['history'] ), 'It still gets its previous status.' );
		wp_trash_post( $this->ids['history'] );

		$this->handler()->undo( $this->ids['about'] );

		$this->assertSame( 'trash', get_post_status( $this->ids['history'] ), 'Trashed on purpose since.' );
		$this->assertSame( 'publish', get_post_status( $this->ids['team'] ) );
	}

	public function test_too_many_subpages_to_trash_move_up_instead(): void {
		$this->policy( Settings::POLICY_TRASH_CHILDREN );
		add_filter( 'hierarchy_guard_count_cap', static fn(): int => 2 );

		wp_trash_post( $this->ids['about'] );

		$this->assertSame( 'publish', get_post_status( $this->ids['team'] ) );
		$this->assertSame( $this->ids['company'], (int) get_post( $this->ids['team'] )->post_parent );
		$root = $this->change()['roots'][ $this->ids['about'] ];
		$this->assertTrue( $root['fallback'] );
		$this->assertSame( Settings::POLICY_REPARENT, $root['policy'] );
		$this->assertTrue( $root['urls']['capped'] );
	}

	public function test_subpages_of_a_page_under_a_trashed_parent_move_to_the_nearest_live_ancestor(): void {
		$company = $this->ids['company'];
		add_filter( 'hierarchy_guard_trash_policy', static fn( string $policy, \WP_Post $post ): string => $post->ID === $company ? Settings::POLICY_KEEP : $policy, 10, 2 );
		wp_trash_post( $this->ids['company'] );

		wp_trash_post( $this->ids['about'] );

		$this->assertSame( 0, (int) get_post( $this->ids['team'] )->post_parent );
		$this->assertSame( $this->ids['team'], $this->resolver->resolve( 'page', 'company/about/team' )->ID );
	}

	public function test_deleting_a_trashed_parent_for_good_cleans_up_its_markers(): void {
		wp_trash_post( $this->ids['about'] );
		$this->assertSame( 2, Markers::count( $this->ids['about'], 10 ), 'Precondition.' );

		wp_delete_post( $this->ids['about'], true );

		$this->assertSame( 0, Markers::count( $this->ids['about'], 10 ) );
		$this->assertNull( $this->queue->load( $this->ids['about'] ) );
		$this->assertSame( $this->ids['team'], $this->resolver->resolve( 'page', 'company/about/team' )->ID, 'Old URLs still resolve.' );
		$this->assertSame( $this->ids['company'], $this->resolver->resolve( 'page', 'company/about' )->ID, 'Now that it is gone, its URL leads to its parent.' );
	}

	public function test_a_restore_whose_save_never_finished_is_queued_at_shutdown(): void {
		wp_trash_post( $this->ids['about'] );
		$this->untrash_raw( $this->ids['about'] );
		$this->handler()->on_transition( 'publish', 'trash', get_post( $this->ids['about'] ) );

		$this->handler()->flush_pending();

		$this->assertSame( Operations::TYPE_RESTORE, $this->queue->load( $this->ids['about'] )['type'] );
		$this->assertSame( $this->ids['company'], (int) get_post( $this->ids['team'] )->post_parent, 'Nothing runs inline at shutdown.' );

		$this->processor()->run( $this->ids['about'] );

		$this->assertSame( $this->ids['about'], (int) get_post( $this->ids['team'] )->post_parent );
	}

	public function test_unsupported_types_are_ignored(): void {
		register_post_type(
			'hg_private',
			[
				'public'       => false,
				'hierarchical' => true,
			]
		);
		$parent = self::factory()->post->create( [ 'post_type' => 'hg_private' ] );
		$child  = self::factory()->post->create(
			[
				'post_type'   => 'hg_private',
				'post_parent' => $parent,
			]
		);

		wp_trash_post( $parent );

		$this->assertSame( $parent, (int) get_post( $child )->post_parent );
	}

	public function test_cli_lines_say_what_happened(): void {
		$job                     = Operations::new_job(
			Operations::TYPE_REPARENT,
			12,
			'page',
			0,
			[
				'to'    => 7,
				'total' => 900,
			]
		);
		$job['counts']['moved']  = 200;
		$job['counts']['denied'] = 2;
		$job['counts']['kept']   = 2;

		$this->assertSame(
			[
				'Moved 200 subpages of page 12 up under page 7.',
				'Skipped 2 subpages the user cannot change.',
				'698 more are queued. Run `wp hierarchy-guard process-jobs` to finish now.',
			],
			Trash_Handler::cli_lines( 'trash', $job, true )
		);

		$job['to']               = 0;
		$job['counts']['denied'] = 0;
		$this->assertSame( [ 'Moved 200 subpages of page 12 to the top level.' ], Trash_Handler::cli_lines( 'trash', $job, false ) );

		$restore                       = Operations::new_job( Operations::TYPE_RESTORE, 12, 'page', 0 );
		$restore['counts']['moved']    = 1;
		$restore['counts']['restored'] = 3;
		$this->assertSame( [ 'Moved 1 subpage back under page 12.', 'Restored 3 subpages of page 12 from the trash.' ], Trash_Handler::cli_lines( 'restore', $restore, false ) );
	}
}
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `npm run test:php -- --filter TrashHandlerTest`
Expected: `No Trash_Handler registered on trashed_post.` from `set_up()` for every test.

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

use HierarchyGuard\Batch\CLI_Command;
use HierarchyGuard\Batch\Queue;
use HierarchyGuard\Ledger;
use HierarchyGuard\Paths;
use HierarchyGuard\Post_Types;
use HierarchyGuard\Settings;
use HierarchyGuard\Subtree;
use WP_Error;
use WP_Post;

/**
 * Snapshots in wp_trash_post, acts in trashed_post once core has succeeded,
 * and takes back its own moves after any save that brings a page out of the
 * trash. Work beyond the request's budget goes to the batch queue.
 */
final class Trash_Handler {

	/**
	 * Snapshots taken in wp_trash_post, keyed by post ID.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $snapshots = [];

	/**
	 * Pages that left the trash in this request, keyed by ID, valued by post type.
	 *
	 * @var array<int,string>
	 */
	private array $pending = [];

	/**
	 * Pages undo() is restoring right now.
	 *
	 * @var array<int,true>
	 */
	private array $undo_ids = [];

	/**
	 * Inline work done in this request, across every page it trashed or restored.
	 *
	 * @var array{moves:int,calls:int}
	 */
	private array $spent = [
		'moves' => 0,
		'calls' => 0,
	];

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
		add_action( 'wp_after_insert_post', [ $this, 'after_save' ], 20 );
		add_action( 'shutdown', [ $this, 'flush_pending' ] );
		add_action( 'deleted_post', [ $this, 'on_deleted' ], 10, 2 );
		add_filter( 'wp_untrash_post_status', [ $this, 'undo_status' ], 20, 3 );
	}

	/**
	 * Starts a fresh inline budget. The plugin never calls this; long-running
	 * processes and tests do.
	 */
	public function reset_request_budget(): void {
		$this->spent = [
			'moves' => 0,
			'calls' => 0,
		];
	}

	/**
	 * The policy for one page: the setting, then the per-page filter.
	 *
	 * @param WP_Post $post Page being trashed.
	 * @return string One of Settings::POLICIES.
	 */
	public static function policy_for( WP_Post $post ): string {
		$default = Settings::trash_policy();

		/**
		 * Filters the trash policy for one page with subpages.
		 *
		 * @param string  $policy One of Settings::POLICIES.
		 * @param WP_Post $post   The page being trashed.
		 */
		$policy = (string) apply_filters( 'hierarchy_guard_trash_policy', $default, $post );

		return in_array( $policy, Settings::POLICIES, true ) ? $policy : $default;
	}

	/**
	 * Resolves the policy and snapshots paths. Writes nothing: the trash can still fail.
	 *
	 * @param mixed $post_id Post being trashed.
	 */
	public function before_trash( $post_id ): void {
		$id = (int) $post_id;
		unset( $this->snapshots[ $id ] );
		if ( Guard::covers( $id ) ) {
			return;
		}
		$post = get_post( $id );
		if ( ! $post instanceof WP_Post || ! Post_Types::is_supported( (string) $post->post_type ) ) {
			return;
		}
		$type = (string) $post->post_type;
		if ( ! Subtree::has_live_children( $id, $type ) ) {
			return;
		}

		$policy      = self::policy_for( $post );
		$cap         = Subtree::count_cap();
		$descendants = Subtree::count_live_descendants( $id, $type, $cap );
		// One click must never fill the trash beyond what core can empty in one request.
		$fallback = Settings::POLICY_TRASH_CHILDREN === $policy && $descendants > $cap;
		if ( $fallback ) {
			$policy = Settings::POLICY_REPARENT;
		}

		$paths = array_values(
			array_unique(
				array_filter(
					array_merge( [ Paths::untrashed_uri( $post ) ], $this->ledger->paths_for( $id ) ),
					static fn( string $path ): bool => '' !== $path && ! Paths::has_trashed_segment( $path )
				)
			)
		);

		$this->snapshots[ $id ] = [
			'policy'    => $policy,
			'fallback'  => $fallback,
			'to'        => Subtree::nearest_live_ancestor( $post ),
			'paths'     => $paths,
			'post_type' => $type,
			'urls'      => [
				'count'  => min( $descendants, $cap ),
				'capped' => $descendants > $cap,
			],
		];
	}

	/**
	 * Applies the snapshot's policy now that core has trashed the post.
	 *
	 * @param mixed $post_id Trashed post.
	 */
	public function after_trash( $post_id ): void {
		$id = (int) $post_id;
		if ( Guard::covers( $id ) || ! isset( $this->snapshots[ $id ] ) ) {
			return;
		}
		$snapshot = $this->snapshots[ $id ];
		unset( $this->snapshots[ $id ] );

		$type = (string) $snapshot['post_type'];
		$user = get_current_user_id();
		$cap  = Subtree::count_cap();

		if ( Settings::POLICY_KEEP === $snapshot['policy'] ) {
			$kept = Subtree::count_live_children( $id, $type, $cap );
			$this->log->record(
				$user,
				'trash',
				$type,
				[
					'root'   => $id,
					'to'     => (int) $snapshot['to'],
					'policy' => Settings::POLICY_KEEP,
					'counts' => [ 'kept' => $kept ],
					'urls'   => $snapshot['urls'],
				]
			);
			$this->cli( [ sprintf( 'Left %s of page %d where %s.', CLI_Command::subpages( $kept ), $id, 1 === $kept ? 'it is' : 'they are' ) ] );
			return;
		}

		$reparent = Settings::POLICY_REPARENT === $snapshot['policy'];
		$job      = Operations::new_job(
			$reparent ? Operations::TYPE_REPARENT : Operations::TYPE_TRASH,
			$id,
			$type,
			$user,
			[
				'to'       => (int) $snapshot['to'],
				'policy'   => (string) $snapshot['policy'],
				'fallback' => (bool) $snapshot['fallback'],
				'paths'    => $snapshot['paths'],
				'urls'     => $snapshot['urls'],
				'total'    => $reparent ? Subtree::count_live_children( $id, $type, $cap ) : (int) $snapshot['urls']['count'],
			]
		);

		$this->dispatch( 'trash', $job );
	}

	/**
	 * Notes a page leaving the trash by any route. The restore itself waits
	 * until the page's own save has finished.
	 *
	 * @param mixed $new_status New status.
	 * @param mixed $old_status Old status.
	 * @param mixed $post       Post.
	 */
	public function on_transition( $new_status, $old_status, $post ): void {
		// Runs on every status change on the site: the cheapest checks come first.
		if ( 'trash' !== $old_status || 'trash' === $new_status || ! $post instanceof WP_Post ) {
			return;
		}
		$id = (int) $post->ID;
		if ( Guard::covers( $id ) || ! Post_Types::is_supported( (string) $post->post_type ) ) {
			return;
		}

		// A subpage restored on its own no longer comes back with its parent.
		Markers::clear_trashed_rows( $id );
		$this->pending[ $id ] = (string) $post->post_type;
	}

	/**
	 * Restores a page's subpages once its own save is complete.
	 *
	 * @param mixed $post_id Saved post.
	 */
	public function after_save( $post_id ): void {
		$id = (int) $post_id;
		if ( ! isset( $this->pending[ $id ] ) ) {
			return;
		}
		$type = $this->pending[ $id ];
		unset( $this->pending[ $id ] );

		$this->start_restore( $id, $type, true );
	}

	/**
	 * Queues the restores of pages whose save never reached wp_after_insert_post.
	 */
	public function flush_pending(): void {
		foreach ( $this->pending as $id => $type ) {
			unset( $this->pending[ $id ] );
			$this->start_restore( (int) $id, $type, false );
		}
	}

	/**
	 * Cleans up after a root page that was deleted for good.
	 *
	 * @param mixed $post_id Deleted post.
	 * @param mixed $post    Its object, as core passes it.
	 */
	public function on_deleted( $post_id, $post ): void {
		$id = (int) $post_id;
		if ( ! $post instanceof WP_Post || ! Post_Types::is_supported( (string) $post->post_type ) ) {
			return;
		}
		if ( Markers::count( $id, 1 ) > 0 ) {
			$this->dispatch( 'cleanup', Operations::new_job( Operations::TYPE_CLEANUP, $id, (string) $post->post_type, 0 ) );
			return;
		}
		$this->queue->delete( $id );
		$this->queue->discard_offer( $id );
	}

	/**
	 * Restores a trashed page with the status it had, which brings its subpages
	 * back too. Core's Restore link makes the page a draft instead; either way
	 * its subpages return with their own statuses.
	 *
	 * @param int $root_id Trashed page.
	 * @return true|WP_Error
	 */
	public function undo( int $root_id ) {
		$post = get_post( $root_id );
		if ( ! $post instanceof WP_Post || 'trash' !== $post->post_status ) {
			return new WP_Error(
				'hierarchy_guard_not_in_trash',
				$post instanceof WP_Post
					/* translators: %s: page title. */
					? sprintf( __( '“%s” is no longer in the trash.', 'hierarchy-guard' ), self::title( $post ) )
					: __( 'This page is no longer in the trash.', 'hierarchy-guard' )
			);
		}
		if ( is_user_logged_in() && ! current_user_can( 'delete_post', $root_id ) ) {
			return new WP_Error( 'hierarchy_guard_forbidden', __( 'Sorry, you are not allowed to restore this page from the trash.', 'hierarchy-guard' ) );
		}

		$this->undo_ids[ $root_id ] = true;
		try {
			wp_untrash_post( $root_id );
		} finally {
			unset( $this->undo_ids[ $root_id ] );
		}

		// A pre_untrash_post filter can answer true without restoring, so the status decides.
		if ( 'trash' === Subtree::status( $root_id ) ) {
			/* translators: %s: page title. */
			return new WP_Error( 'hierarchy_guard_restore_failed', sprintf( __( '“%s” could not be restored from the trash.', 'hierarchy-guard' ), self::title( $post ) ) );
		}
		return true;
	}

	/**
	 * Gives the page undo() restores the status it had before the trash.
	 *
	 * @param mixed $new_status      Status core is about to assign.
	 * @param mixed $post_id         Post being untrashed.
	 * @param mixed $previous_status Status it had before the trash.
	 * @return string
	 */
	public function undo_status( $new_status, $post_id, $previous_status ): string {
		if ( isset( $this->undo_ids[ (int) $post_id ] ) && '' !== (string) $previous_status ) {
			return (string) $previous_status;
		}
		return (string) $new_status;
	}

	/**
	 * WP-CLI lines about a trash or restore.
	 *
	 * @param string              $action trash or restore.
	 * @param array<string,mixed> $job    Job after its inline step.
	 * @param bool                $queued Whether work is left for the queue.
	 * @return string[]
	 */
	public static function cli_lines( string $action, array $job, bool $queued ): array {
		$root   = (int) $job['root'];
		$counts = (array) $job['counts'];
		$lines  = [];

		if ( 'trash' === $action ) {
			if ( (int) $counts['moved'] > 0 ) {
				$lines[] = (int) $job['to'] > 0
					? sprintf( 'Moved %s of page %d up under page %d.', CLI_Command::subpages( (int) $counts['moved'] ), $root, (int) $job['to'] )
					: sprintf( 'Moved %s of page %d to the top level.', CLI_Command::subpages( (int) $counts['moved'] ), $root );
			}
			if ( (int) $counts['trashed'] > 0 ) {
				$lines[] = sprintf( 'Trashed %s of page %d with it.', CLI_Command::subpages( (int) $counts['trashed'] ), $root );
			}
		} elseif ( 'restore' === $action ) {
			if ( (int) $counts['moved'] > 0 ) {
				$lines[] = sprintf( 'Moved %s back under page %d.', CLI_Command::subpages( (int) $counts['moved'] ), $root );
			}
			if ( (int) $counts['restored'] > 0 ) {
				$lines[] = sprintf( 'Restored %s of page %d from the trash.', CLI_Command::subpages( (int) $counts['restored'] ), $root );
			}
		}
		if ( (int) $counts['denied'] > 0 ) {
			$lines[] = sprintf( 'Skipped %s the user cannot change.', CLI_Command::subpages( (int) $counts['denied'] ) );
		}

		if ( $queued ) {
			$done    = (int) $counts['moved'] + (int) $counts['trashed'] + (int) $counts['restored'] + (int) $counts['kept'] + (int) $counts['left'];
			$more    = (int) $job['total'] - $done;
			$lines[] = $more > 0 && (int) $job['total'] < Subtree::count_cap()
				? sprintf( '%s more are queued. Run `wp hierarchy-guard process-jobs` to finish now.', number_format( $more ) )
				: 'The rest are queued. Run `wp hierarchy-guard process-jobs` to finish now.';
		}

		return $lines;
	}

	/**
	 * Starts the restore of a page that left the trash, if the trash flow changed anything under it.
	 *
	 * @param int    $root_id Restored page.
	 * @param string $type    Its post type.
	 * @param bool   $inline  Whether to do the first batch in this request.
	 */
	private function start_restore( int $root_id, string $type, bool $inline ): void {
		$size = Markers::count( $root_id, Subtree::count_cap() );
		if ( 0 === $size ) {
			return;
		}
		$this->dispatch( 'restore', Operations::new_job( Operations::TYPE_RESTORE, $root_id, $type, get_current_user_id(), [ 'total' => $size ] ), $inline );
	}

	/**
	 * Runs the first batch of a job inline, within what is left of this
	 * request's budget, and queues the rest. A job for a page whose batch is
	 * running in another process is handed to that process instead.
	 *
	 * @param string              $action trash, restore or cleanup.
	 * @param array<string,mixed> $job    Job.
	 * @param bool                $inline Whether to do any work in this request.
	 */
	private function dispatch( string $action, array $job, bool $inline = true ): void {
		$root  = (int) $job['root'];
		$token = $this->queue->lock( $root );
		if ( 0 === $token ) {
			$job['change'] = $this->summarize( $action, $job, true );
			$this->queue->offer( $job );
			$this->queue->schedule( $root );
			$this->cli( 'cleanup' === $action ? [] : self::cli_lines( $action, $job, true ) );
			return;
		}

		$running = true;
		try {
			$this->supersede( $root );
			// Saved before any work, so a request that dies mid-step leaves the job for the watchdog.
			$this->queue->save( $job );

			$moves = $inline ? max( 0, Operations::threshold() - $this->spent['moves'] ) : 0;
			$calls = $inline ? max( 0, Operations::core_threshold() - $this->spent['calls'] ) : 0;
			if ( $moves > 0 || $calls > 0 ) {
				$job                   = Ledger::deferred( fn() => $this->operations->step( $job, $moves, $calls ) );
				$this->spent['moves'] += (int) $job['work']['moves'];
				$this->spent['calls'] += (int) $job['work']['calls'];
			}

			$running       = empty( $job['finished'] );
			$job['change'] = $this->summarize( $action, $job, $running );
			if ( $running ) {
				$this->queue->save( $job );
			} else {
				$this->queue->delete( $root );
			}
		} finally {
			$this->queue->unlock( $root, $token );
		}

		if ( $running ) {
			$this->queue->schedule( $root );
		}
		$this->cli( 'cleanup' === $action ? [] : self::cli_lines( $action, $job, $running ) );
	}

	/**
	 * Closes the summaries of the job and offer this one replaces.
	 *
	 * @param int $root_id Root page.
	 */
	private function supersede( int $root_id ): void {
		foreach ( [ $this->queue->load( $root_id ), $this->queue->take_offer( $root_id ) ] as $old ) {
			if ( null !== $old ) {
				$this->log->update( (int) $old['user'], (string) $old['change'], $root_id, (array) $old['counts'], (array) $old['kept_ids'], (array) $old['ids'], false );
			}
		}
	}

	/**
	 * Records the job's root in the user's change summary.
	 *
	 * @param string              $action  trash, restore or cleanup.
	 * @param array<string,mixed> $job     Job.
	 * @param bool                $running Whether work is left for the queue.
	 * @return string Change ID, or an empty string when nothing was recorded.
	 */
	private function summarize( string $action, array $job, bool $running ): string {
		if ( 'cleanup' === $action ) {
			return '';
		}
		return $this->log->record(
			(int) $job['user'],
			$action,
			(string) $job['post_type'],
			[
				'root'     => (int) $job['root'],
				'to'       => (int) $job['to'],
				'policy'   => (string) $job['policy'],
				'fallback' => (bool) $job['fallback'],
				'counts'   => (array) $job['counts'],
				'kept_ids' => (array) $job['kept_ids'],
				'urls'     => (array) $job['urls'],
				'job'      => $running,
			],
			(array) $job['ids']
		);
	}

	/**
	 * Prints lines when running under WP-CLI.
	 *
	 * @param string[] $lines Lines.
	 */
	private function cli( array $lines ): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}
		foreach ( $lines as $line ) {
			\WP_CLI::log( $line );
		}
	}

	/**
	 * A post's title for a message.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	private static function title( WP_Post $post ): string {
		$title = get_the_title( $post );
		return '' !== $title ? $title : __( '(no title)', 'hierarchy-guard' );
	}
}
```

- [ ] **Step 5: Register the handler**

In `includes/HierarchyGuard/Plugin.php`, add after `$processor->register();`:
```php
		( new Trash\Trash_Handler( $ledger, $operations, $queue, $log ) )->register();
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `npm run test:php -- --filter TrashHandlerTest`
Expected: `OK (26 tests, 145 assertions)`.

Run: `npm run test:php`
Expected: all green, including `ChangeListenerTest` and `ResolverTest` under the keep policy. If any other engine test now fails because a subpage moved, it is exercising core's trash behavior: pin it with the same keep filter and say so in the report; do not change its assertions.

Run: `composer lint`
Expected: exit 0.

- [ ] **Step 7: Commit**

```bash
git add includes tests
git commit -m "feat: trash handler applies the policy, shares one budget per request and reverses on any restore"
```

---

### Task 10: The sentence on core's trash notice

**Files:**
- Create: `includes/HierarchyGuard/Admin/Trash_Notice.php`
- Modify: `includes/HierarchyGuard/Plugin.php`
- Test: `tests/phpunit/TrashNoticeTest.php`

**Interfaces:**
- Consumes: `Change_Log::latest()`, `mark_shown()`, `record()`, `reset_request()` (Task 4); `Subtree::count_cap()` (Task 2).
- Produces: `Admin\Trash_Notice::__construct( Change_Log $log )`; `register()` (filter `bulk_post_updated_messages` at 10 with 2 args); `append( mixed $messages, mixed $counts ): mixed`; static `sentences( array $change ): string[]`.

Trashing from the block editor lands on `edit.php?post_type=page&trashed=1&ids=ID`, so this one filter covers the editor and the Pages list. The sentence is shown once per change, only on the change's post type screen, only when the request's `ids` include one of its roots (or, for core's bulk restore, which drops `ids`, when the change is under a minute old). Plan 3 replaces it with the spec's notice.

- [ ] **Step 1: Write the failing tests**

`tests/phpunit/TrashNoticeTest.php`:
```php
<?php
/**
 * The sentence added to core's trash notice.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Admin\Trash_Notice;
use HierarchyGuard\Post_Types;
use HierarchyGuard\Settings;
use HierarchyGuard\Trash\Change_Log;
use WP_UnitTestCase;

/**
 * @covers \HierarchyGuard\Admin\Trash_Notice
 */
final class TrashNoticeTest extends WP_UnitTestCase {

	use Trash_Fixtures;

	/**
	 * Page IDs.
	 *
	 * @var array<string,int>
	 */
	private array $ids;

	private int $admin;

	private Change_Log $log;

	private Trash_Notice $notice;

	public function set_up(): void {
		parent::set_up();
		delete_option( Settings::OPTION );
		Post_Types::reset();
		Change_Log::reset_request();
		$this->admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $this->admin );
		$this->log          = new Change_Log();
		$this->notice       = new Trash_Notice( $this->log );
		$this->ids          = $this->make_tree();
		$GLOBALS['typenow'] = 'page';
	}

	public function tear_down(): void {
		unset( $GLOBALS['typenow'], $_REQUEST['ids'] );
		parent::tear_down();
	}

	/**
	 * Core's page messages, as edit.php builds them.
	 *
	 * @return array<string,array<string,string>>
	 */
	private function core_messages(): array {
		return [
			'post' => [
				'trashed'   => '%s post moved to the Trash.',
				'untrashed' => '%s post restored from the Trash.',
			],
			'page' => [
				'trashed'   => '%s page moved to the Trash.',
				'untrashed' => '%s page restored from the Trash.',
			],
		];
	}

	/**
	 * A change with one or more roots.
	 *
	 * @param string                           $action  trash or restore.
	 * @param array<int,array<string,mixed>>   $entries Root entries.
	 * @return array<string,mixed>
	 */
	private function change( string $action, array $entries ): array {
		foreach ( $entries as $entry ) {
			$this->log->record( $this->admin, $action, 'page', $entry );
		}
		return $this->log->latest( $this->admin );
	}

	/**
	 * A root entry for about.
	 *
	 * @param array<string,mixed> $fields Fields to set.
	 * @return array<string,mixed>
	 */
	private function about( array $fields ): array {
		return array_merge(
			[
				'root'   => $this->ids['about'],
				'to'     => $this->ids['company'],
				'policy' => Settings::POLICY_REPARENT,
			],
			$fields
		);
	}

	public function test_the_filter_is_registered_by_the_plugin(): void {
		global $wp_filter;
		$found = false;
		foreach ( $wp_filter['bulk_post_updated_messages']->callbacks[10] ?? [] as $callback ) {
			if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof Trash_Notice ) {
				$found = true;
			}
		}
		$this->assertTrue( $found );
	}

	public function test_core_s_trash_notice_gains_one_sentence_once(): void {
		$this->change( 'trash', [ $this->about( [ 'counts' => [ 'moved' => 2 ] ] ) ] );
		$_REQUEST['ids'] = (string) $this->ids['about'];

		$messages = $this->notice->append( $this->core_messages(), [ 'trashed' => 1 ] );

		$this->assertSame( '1 page moved to the Trash. 2 subpages moved up under “Company”.', sprintf( $messages['page']['trashed'], '1' ) );
		$this->assertSame( $this->core_messages(), $this->notice->append( $this->core_messages(), [ 'trashed' => 1 ] ), 'Shown once.' );
	}

	public function test_a_percent_sign_in_a_title_survives_core_s_sprintf(): void {
		wp_update_post(
			[
				'ID'         => $this->ids['company'],
				'post_title' => '100% Company',
			]
		);
		$this->change( 'trash', [ $this->about( [ 'counts' => [ 'moved' => 1 ] ] ) ] );
		$_REQUEST['ids'] = (string) $this->ids['about'];

		$messages = $this->notice->append( $this->core_messages(), [ 'trashed' => 1 ] );

		$this->assertSame( '1 page moved to the Trash. 1 subpage moved up under “100% Company”.', sprintf( $messages['page']['trashed'], '1' ) );
	}

	public function test_another_page_s_notice_is_left_alone(): void {
		$this->change( 'trash', [ $this->about( [ 'counts' => [ 'moved' => 2 ] ] ) ] );

		$_REQUEST['ids'] = (string) $this->ids['history'];
		$this->assertSame( $this->core_messages(), $this->notice->append( $this->core_messages(), [ 'trashed' => 1 ] ), 'Different IDs.' );

		$_REQUEST['ids'] = (string) $this->ids['about'];
		$this->assertSame( $this->core_messages(), $this->notice->append( $this->core_messages(), [ 'updated' => 1 ] ), 'Not a trash notice.' );

		$GLOBALS['typenow'] = 'post';
		$this->assertSame( $this->core_messages(), $this->notice->append( $this->core_messages(), [ 'trashed' => 1 ] ), 'Another post type.' );
	}

	public function test_a_bulk_restore_without_ids_matches_a_change_made_moments_ago(): void {
		$this->change( 'restore', [ $this->about( [ 'counts' => [ 'moved' => 2 ] ] ) ] );

		$messages = $this->notice->append( $this->core_messages(), [ 'untrashed' => 2 ] );

		$this->assertStringEndsWith( '2 subpages moved back under “About”.', $messages['page']['untrashed'] );
	}

	public function test_trash_sentences_for_one_page(): void {
		$this->assertSame( [ '1 subpage moved up under “Company”.' ], Trash_Notice::sentences( $this->change( 'trash', [ $this->about( [ 'counts' => [ 'moved' => 1 ] ] ) ] ) ) );
		Change_Log::reset_request();
		$this->assertSame(
			[ '3 subpages are now top-level pages.' ],
			Trash_Notice::sentences(
				$this->change(
					'trash',
					[
						$this->about(
							[
								'to'     => 0,
								'counts' => [ 'moved' => 3 ],
							]
						),
					]
				)
			)
		);
		Change_Log::reset_request();
		$this->assertSame(
			[ '4 subpages moved to the Trash too.', '1 subpage stayed where it is.' ],
			Trash_Notice::sentences(
				$this->change(
					'trash',
					[
						$this->about(
							[
								'policy' => Settings::POLICY_TRASH_CHILDREN,
								'counts' => [
									'trashed' => 4,
									'kept'    => 1,
								],
							]
						),
					]
				)
			)
		);
		Change_Log::reset_request();
		$this->assertSame(
			[ 'Its 2 subpages stayed where they are.' ],
			Trash_Notice::sentences(
				$this->change(
					'trash',
					[
						$this->about(
							[
								'policy' => Settings::POLICY_KEEP,
								'counts' => [ 'kept' => 2 ],
							]
						),
					]
				)
			)
		);
		Change_Log::reset_request();
		$this->assertSame(
			[ '1 subpage moved up under “Company”.', '1 subpage you don’t have permission to change stayed where it is.', 'The rest are still moving in the background.' ],
			Trash_Notice::sentences(
				$this->change(
					'trash',
					[
						$this->about(
							[
								'counts' => [
									'moved'  => 1,
									'kept'   => 1,
									'denied' => 1,
								],
								'job'    => true,
							]
						),
					]
				)
			)
		);
		Change_Log::reset_request();
		$this->assertSame(
			[ '1,200 subpages moved up under “Company”.', 'There were more than 1,000 subpages, so they moved up instead of going to the Trash.' ],
			Trash_Notice::sentences(
				$this->change(
					'trash',
					[
						$this->about(
							[
								'fallback' => true,
								'counts'   => [ 'moved' => 1200 ],
							]
						),
					]
				)
			)
		);
	}

	public function test_trash_sentences_for_several_pages(): void {
		$change = $this->change(
			'trash',
			[
				$this->about( [ 'counts' => [ 'moved' => 2 ] ] ),
				[
					'root'   => $this->ids['team'],
					'to'     => $this->ids['company'],
					'policy' => Settings::POLICY_REPARENT,
					'counts' => [ 'moved' => 1 ],
				],
			]
		);

		$this->assertSame( [ '3 subpages moved up one level.' ], Trash_Notice::sentences( $change ) );
	}

	public function test_restore_sentences(): void {
		$this->assertSame(
			[ '2 subpages moved back under “About”.', '3 subpages were restored with it.', '1 subpage was moved since and stayed where it is.', '1 subpage couldn’t get its old URL back.' ],
			Trash_Notice::sentences(
				$this->change(
					'restore',
					[
						$this->about(
							[
								'counts' => [
									'moved'    => 2,
									'restored' => 3,
									'left'     => 1,
									'renamed'  => 1,
								],
							]
						),
					]
				)
			)
		);

		Change_Log::reset_request();
		$change = $this->change(
			'restore',
			[
				$this->about( [ 'counts' => [ 'moved' => 1 ] ] ),
				[
					'root'   => $this->ids['team'],
					'counts' => [ 'restored' => 2 ],
				],
			]
		);
		$this->assertSame( [ '1 subpage moved back under its parent.', '2 subpages were restored too.' ], Trash_Notice::sentences( $change ) );
	}
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npm run test:php -- --filter TrashNoticeTest`
Expected: `Error: Class "HierarchyGuard\Admin\Trash_Notice" not found`.

- [ ] **Step 3: Create Trash_Notice**

`includes/HierarchyGuard/Admin/Trash_Notice.php`:
```php
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
				$out[] = __( 'The rest are still moving in the background.', 'hierarchy-guard' );
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
				/* translators: %s: the most subpages the plugin trashes together with a page, such as 1,000. */
				$out[] = sprintf( __( 'There were more than %s subpages, so they moved up instead of going to the Trash.', 'hierarchy-guard' ), number_format_i18n( Subtree::count_cap() ) );
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
```

- [ ] **Step 4: Register the notice**

In `includes/HierarchyGuard/Plugin.php`, add after the `Trash\Trash_Handler` line:
```php
		( new Admin\Trash_Notice( $log ) )->register();
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

		$log        = new Trash\Change_Log();
		$queue      = new Batch\Queue();
		$operations = new Trash\Operations( $ledger, new Trash\Mover() );
		$processor  = new Batch\Processor( $queue, $operations, $log );
		$processor->register();
		( new Trash\Trash_Handler( $ledger, $operations, $queue, $log ) )->register();
		( new Admin\Trash_Notice( $log ) )->register();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'hierarchy-guard', new Batch\CLI_Command( $queue, $processor ) );
		}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `npm run test:php -- --filter TrashNoticeTest`
Expected: `OK (8 tests, 17 assertions)`.

Run: `npm run test:php` and `composer lint`
Expected: all green; lint exit 0.

- [ ] **Step 6: Commit**

```bash
git add includes tests
git commit -m "feat: say what happened to the subpages in core's trash and restore notices"
```

---

### Task 11: Trash-policy setting, README, and a live check

**Files:**
- Modify: `includes/HierarchyGuard/Admin/Settings_Section.php`, `README.md`
- Test: `tests/phpunit/SettingsSectionTest.php`

**Interfaces:**
- Consumes: `Settings::get( 'trash_policy' )`, `Settings::POLICY_*` (engine).
- Produces: `Settings_Section::render_trash_policy(): void`; the Writing page registers three fields again, in this order: post types, trash policy, redirects.

- [ ] **Step 1: Write the failing tests**

In `tests/phpunit/SettingsSectionTest.php`, `test_section_and_fields_register_on_the_writing_page()`, replace the two field assertions (`assertCount( 2, …)` and `assertArrayNotHasKey( 'hierarchy_guard_trash_policy', …)`) with:
```php
		$this->assertSame( [ 'hierarchy_guard_post_types', 'hierarchy_guard_trash_policy', 'hierarchy_guard_serve_redirects' ], array_keys( $wp_settings_fields['writing']['hierarchy_guard'] ) );
```
and add to the end of the class:
```php
	public function test_trash_policy_field_renders_three_radios_with_the_stored_choice(): void {
		update_option( Settings::OPTION, [ 'trash_policy' => Settings::POLICY_KEEP ] );

		ob_start();
		$this->section->render_trash_policy();
		$html = (string) ob_get_clean();

		$this->assertSame( 3, substr_count( $html, 'type="radio"' ) );
		$this->assertMatchesRegularExpression( '/value="keep"\s+checked/', $html );
		$this->assertStringContainsString( 'Move subpages up one level', $html );
		$this->assertStringContainsString( 'Trash the subpages too', $html );
		$this->assertStringContainsString( 'Leave subpages where they are', $html );
		$this->assertStringContainsString( 'Applies whenever a page with subpages is moved to the trash, including from the editor, the REST API, and WP-CLI.', $html );
		$this->assertStringNotContainsString( 'The trash is turned off', $html, 'This test site keeps its trash.' );
		$this->assertStringNotContainsString( 'Pages list', $html, 'No claims about UI that does not exist yet.' );
	}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npm run test:php -- --filter SettingsSectionTest`
Expected: the field-order assertion fails and `render_trash_policy()` is undefined.

- [ ] **Step 3: Restore the field**

In `includes/HierarchyGuard/Admin/Settings_Section.php`:

Delete the class docblock's second paragraph, `The trash-policy field is added together with the trash handler that reads it.`, and the blank line before it.

In `add()`, between the post-types field and the redirects field, add:
```php
		add_settings_field( 'hierarchy_guard_trash_policy', __( 'Trashing a page with subpages', 'hierarchy-guard' ), [ $this, 'render_trash_policy' ], self::PAGE, self::SECTION );
```

Add this method after `render_post_types()`, in place of the extra blank line there:
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

		$help = __( 'Applies whenever a page with subpages is moved to the trash, including from the editor, the REST API, and WP-CLI.', 'hierarchy-guard' );
		if ( ! EMPTY_TRASH_DAYS ) {
			$help .= ' ' . __( 'The trash is turned off on this site, so deleted pages skip it and their subpages always move up one level.', 'hierarchy-guard' );
		}
		echo '<p class="description">' . esc_html( $help ) . '</p>';
		echo '</fieldset>';
	}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `npm run test:php -- --filter SettingsSectionTest`
Expected: `OK (9 tests, 30 assertions)`.

- [ ] **Step 5: Document the trash flow in the README**

In `README.md`, insert before `## Development`:
````markdown
## Trashing a page with subpages

By default, trashing a page moves its subpages up one level, so none of them is left with a `__trashed` URL, and their old URLs keep working. Settings › Writing › Page hierarchy can trash them too or leave them where they are. Restoring the page, whether by Undo, Restore, or a status change over the REST API or WP-CLI, moves them back. Subpages trashed with it come back with the status they had.

## Background jobs

One request moves at most 200 subpages and trashes or restores at most 25 through core (filters `hierarchy_guard_batch_threshold` and `hierarchy_guard_core_call_threshold`). Anything beyond that runs in background batches through WP-Cron, or Action Scheduler when it is installed, and until a job finishes some subpages are still under the trashed page. An hourly check restarts a job whose run stopped. On hosts where cron is disabled or only runs on traffic, finish queued jobs with:

```bash
wp hierarchy-guard process-jobs
```

## For developers

Subpages move with a direct database update, the way core moves them when it deletes their parent, so `save_post` does not run for them. Search indexes and SEO plugins that cache permalinks can re-sync the moved posts from the `hierarchy_guard_children_reparented` action. It fires when a page's subpages move up and again when they move back, with the moved IDs, the parent they left and their new parent.
````

- [ ] **Step 6: Run the full suite and lint**

Run: `npm run test:php` and `composer lint`
Expected: `OK (242 tests, 825 assertions)`; lint exit 0.

- [ ] **Step 7: Verify on the dev site**

The dev site is the wp-env instance (port 8888 by default; a git-ignored `.wp-env.override.json` may move it, and `npx wp-env run cli wp option get siteurl` prints it). Run, replacing `8898` with its port:
```bash
run() { npx wp-env run cli "$@" 2>/dev/null | grep -v '^ℹ\|^✔' ; }
c() { local h; h=$(curl -s -o /dev/null -D - "http://localhost:8898$1" | tr -d '\r'); printf "%-22s %s %s [%s]\n" "$1" "$(echo "$h" | head -1 | cut -d' ' -f2)" "$(echo "$h" | grep -i '^location:' | cut -d' ' -f2)" "$(echo "$h" | grep -i '^x-redirect-by:' | cut -d' ' -f2-)"; }
for n in hg-org hg-team hg-kai hg-board hg-ann hg-ben hg-cat; do for id in $(run wp post list --post_type=page --post_status=publish,draft,trash --name=$n --field=ID); do run wp post delete $id --force >/dev/null; done; done
run wp rewrite structure '/%postname%/' --hard >/dev/null
run wp option update hierarchy_guard_settings '{"serve_redirects":true}' --format=json >/dev/null
O=$(run wp post create --post_type=page --post_status=publish --post_title=Org --post_name=hg-org --porcelain | tr -d '[:space:]')
T=$(run wp post create --post_type=page --post_status=publish --post_title=Team --post_name=hg-team --post_parent=$O --porcelain | tr -d '[:space:]')
K=$(run wp post create --post_type=page --post_status=publish --post_title=Kai --post_name=hg-kai --post_parent=$T --porcelain | tr -d '[:space:]')
c /hg-org/hg-team/hg-kai/
run wp post delete $T
echo "kai parent after trash: $(run wp post get $K --field=post_parent) (org=$O)"
c /hg-org/hg-team/hg-kai/
run wp post update $T --post_status=publish
echo "kai parent after restore: $(run wp post get $K --field=post_parent) (team=$T)"
c /hg-org/hg-team/hg-kai/
B=$(run wp post create --post_type=page --post_status=publish --post_title=Board --post_name=hg-board --porcelain | tr -d '[:space:]')
C=""; for s in hg-ann hg-ben hg-cat; do C="$C $(run wp post create --post_type=page --post_status=publish --post_title=$s --post_name=$s --post_parent=$B --porcelain | tr -d '[:space:]')"; done
run wp eval "add_filter( 'hierarchy_guard_batch_threshold', static fn() => 1 ); wp_trash_post( $B );"
run wp hierarchy-guard process-jobs --sleep=0
run wp hierarchy-guard process-jobs
run wp post delete $K $T $O $B $C --force >/dev/null
```
Expected, with the real IDs in place of `<…>`:
```
/hg-org/hg-team/hg-kai/ 200  []
Moved 1 subpage of page <T> up under page <O>.
Success: Trashed post <T>.
kai parent after trash: <O> (org=<O>)
/hg-org/hg-team/hg-kai/ 301 http://localhost:8898/hg-org/hg-kai/ [Hierarchy Guard]
Moved 1 subpage back under page <T>.
Success: Updated post <T>.
kai parent after restore: <T> (team=<T>)
/hg-org/hg-team/hg-kai/ 200  []
Moved 1 subpage of page <B> to the top level.
2 more are queued. Run `wp hierarchy-guard process-jobs` to finish now.
Page <B>: moved 3 subpages to the top level.
Success: Processed 1 job.
Success: No queued jobs.
```
The script deletes the pages it created, and its first loop removes any an interrupted earlier run left. Record the actual output in the report. A redirect marked `[WordPress]` comes from core's own 404 guess, not from the plugin. If any other line differs, stop and report it with the output; do not change code in this task.

- [ ] **Step 8: Commit**

```bash
git add includes tests README.md
git commit -m "feat: restore the trash-policy setting and document the trash flow"
```

---

## Self-Review

**Spec coverage.** Policy per trash with three options and a filterable per-page choice: Tasks 5, 6 and 9. Capabilities per subpage against the triggering user, user 0 trusted: Tasks 5, 6 and 9. Snapshot before core acts, mutate only after success: Task 9 (`before_trash()` writes nothing; `test_a_failed_trash_changes_nothing`). Raw re-parent with cache clear, unique slug including drafts, renamed subpage's old URL recorded on it, original slug back on restore unless the editor renamed it since: Tasks 2, 5 and 7. One alias per old parent path on the new parent or the root list, only after a subpage moved, removed on restore: Tasks 3, 5 and 7. No redirect for a merely trashed page: Task 3. Inline up to the budget, separate budgets for raw moves and core calls, one budget per request across a bulk action, background batches beyond it with Action Scheduler or WP-Cron, time-boxed runs, WP-CLI command: Tasks 8 and 9. One job and one lock per root, offers for a busy root, hourly watchdog with a seven-day limit: Task 8. Pages core's bulk loop is about to trash or restore are left to core: Tasks 4 to 7 and 9. Restore after the page's own save, on any route out of the trash, with previous statuses for co-trashed subpages through a permanent filter: Tasks 1, 7 and 9. Interim URLs recorded for moves older than an hour: Task 7. Trash-the-subpages falls back to moving them up above 1,000 pages: Task 9. Front page, posts page and privacy page kept out of the trash: Task 6. Markers kept out of WXR and cleaned up after a deleted root: Tasks 1, 7 and 9. Change summary with ID, post type, per-root entries, summed counts and background updates: Tasks 4, 8 and 9. Core's trash notice gains one sentence until plan 3's notice: Task 10. Carried over from plan 1: trash-policy field restored (Task 11), trashed-ancestor paths when the trash is emptied and the resolver fallback past a trashed ancestor (Task 3). Out of this plan by design: the Pages-list dialog, the outcome notice with Undo and View redirects, the redirects modal (plan 3), and uninstall (plan 4).

**Placeholder scan.** No TBD or TODO. Every code step carries complete code taken from the verified prototype. Task 9 Step 6 gives an exact rule for any further engine test that trips on the new default policy.

**Type consistency.** `Operations::new_job()` keys match every reader: the `step_*` methods, `Processor::run()` and `adopt()`, `Trash_Handler::dispatch()`, `supersede()` and `summarize()`, `CLI_Command::describe()` and `Trash_Handler::cli_lines()`. `Mover::move()` returns `moved`, `old_path`, `old_slug`, `slug`, `renamed` and `recordable`, which Operations reads. `Markers::rows()` returns `meta_id`, `post_id` and `value`, which `restore_moved()`, `restore_trashed()`, `step_cleanup()`, `Markers::remove()` and the fixtures read. `Change_Log::update( $user_id, $change_id, $root_id, $counts, $kept_ids, $ids, $running )` is called in that order by the Processor and the handler. `Ledger::record_alias( $post_type, $new_parent_id, $old_path )` and `forget_alias()` with the same order match Operations and Change_Listener. `Processor::run()` returns `state` and `job`, which `CLI_Command::drain()` reads.

**Review Focus.** All five named tests exist: four in Task 9 and one each in Tasks 7 and 8.

## Hand-offs to plan 3

- The Pages-list trash dialog passes the editor's choice through `hierarchy_guard_trash_policy( $policy, $post )`.
- The outcome notice replaces `Admin\Trash_Notice`. It reads `Change_Log::latest()` (`id`, `action`, `post_type`, `roots`, `counts`, `urls`, `job`, `shown`), shows only when the request's `trashed`/`untrashed` and `ids` match the change's roots, and calls `mark_shown()`. Its progress line reads the queue (`Queue::roots()`, `Queue::load()`), not only the 30-minute transient.
- Undo calls `Trash_Handler::undo()`, which returns `true` or a `WP_Error` with one of three codes and a translated message. State the guarantee from Task 9 in the copy.
- A DataViews trash stays on screen, so the change `id` must travel in the REST response (for example in the `hierarchy_guard` field) for the snackbar and the redirects modal.
- Swap the trash-policy help text for the spec's ("Used when there's no chance to ask, for example in the editor, the REST API, or WP-CLI. The Pages list asks each time.") and flip the `assertStringNotContainsString( 'Pages list' )` assertion in `SettingsSectionTest`.
- Widen the plugin Description once the admin UI lands.
- From plan 1: guard `$provider['name']` in `Settings_Section::render_serve_redirects()`, and stop the `SettingsSectionTest` globals from leaking.
