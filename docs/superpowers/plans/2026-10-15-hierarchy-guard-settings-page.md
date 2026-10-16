# Hierarchy Guard Settings Page Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. Every review seat (task review, fix re-review, final review) runs on Opus.

**Goal:** Give the plugin one admin page, Settings › Hierarchy Guard, whose Settings tab holds the three settings and whose Redirects tab lists, edits and deletes the redirects the plugin recorded, with the resolver, the server and every change's redirect list honouring those edits.

**Architecture:** The ledger also keeps a capped, non-autoloaded list of the newest records' meta IDs per post type, which the Redirects tab reads instead of every record. An edit lives next to the record it changes: `_hierarchy_guard_target_{md5}` = `{ url, code }` beside a post's `_hierarchy_guard_path_{md5}` entry, or `hierarchy_guard_root_targets[ type ][ path ]` for a root-list entry, read and written only by `Ledger`. `Ledger_View::target()` gives the shared `Rules` the override for the record each round uses, so the live `Resolver`, the `Server` and the `Batch_Resolver` behind `Pairs` answer alike: a URL target answers its exact old path only, and a chosen code replaces the automatic one. `Redirects\Records` turns the ledger into a bounded list of records with IDs, statuses and the save and delete operations; `Admin\Settings_Page` (the former Writing section) adds the page and its tabs, `Admin\Redirects_Table` is a core `WP_List_Table`, and `Admin\Redirect_Edit` prints the Edit view and runs every action on the page's load hook behind `manage_options` and a nonce. Delete asks first through a small `redirects` bundle built with `@wordpress/scripts`; without JavaScript it deletes directly, as core does. The newest list is rebuilt from the database by a daily WP-Cron event, `hierarchy_guard_rebuild_recent`, a single event that schedules its next run: records made before the list existed reach it, and a lost update heals within a day.

**Tech Stack:** PHP 8.0+, WordPress 6.6+, the plugin's engine, trash flow and admin UI (plans 1 to 3), PHPUnit 9.6 in wp-env, PHPCS (WordPress-Extra, WordPress-VIP-Go, PHPCompatibilityWP), `@wordpress/scripts` 36.1.0 (webpack, ESLint, Vitest 5) for one new bundle without dependencies; this plan adds no CSS.

**Spec:** `docs/superpowers/specs/hierarchy-guard-spec.html`, the approved spec for this plan, as corrected at `a76048c` and updated with this plan for the owner's audit rulings (the newest-records option and its rebuild (first written as a one-time backfill), the Delete confirmation, the top-level Edit copy and the path input). Read Decisions, "Settings › Hierarchy Guard", "Redirects tab", "Editing a redirect", "Editing a recorded redirect", "Serving a redirect", Data model, Architecture, Testing, Build order step 8, and Out of scope. This plan is build-order step 8. The Tree view is plan 5; the DataViews track, readme.txt, uninstall and the release zip are plan 6.

**Code in this plan is verified.** Every code block was run in a prototype on wp-env (WordPress 7.1.3, PHP 8.2) before it was written down, and the plan was then replayed from `settings-page` on a scratch branch, task by task, reproducing the prototype exactly. After every task the PHP suite passes (769 tests at the end, also as multisite) and `composer lint` exits 0; the pairs fuzz test passes 400 seeds; `npm run lint:js`, `npm run lint:style`, `npm run test:js` (81 tests at the end) and `npm run build` pass throughout. Both tabs, the Edit view (a page, a URL with a fragment, a path, a top-level entry), Save, Delete and bulk Delete with their confirmation, accepted and cancelled, and the backfill of the dev site's older records were checked in the browser and over HTTP. Three audits (core, scale, UI) were folded in. Transcribe it exactly; deviations need a reason in the report.

## Global Constraints

- Requires WordPress 6.6 and PHP 8.0. Namespace `HierarchyGuard`; new PHP goes in `HierarchyGuard\Admin` and `HierarchyGuard\Redirects`. Every PHP file declares `strict_types=1`.
- Commits are authored by the repository's git config. No `Co-Authored-By` trailer, and no mention of Claude or AI, in any commit message.
- `composer lint` must exit 0, warnings included. Run `composer lint:fix` (phpcbf) first; formatting-only changes it makes are not deviations. Resolve any remaining warning with a behaviour-neutral change or a targeted `// phpcs:ignore Sniff.Name.Code -- reason`.
- Hierarchy Guard is not a redirect plugin. The Redirects tab manages only the redirects the plugin recorded: edit a record's target and code, or delete it. No Add button, no adding by hand, no import or export, no regex, no hit counts.
- UI: core admin classes only (`wrap`, `nav-tab-wrapper`, `nav-tab`, `nav-tab-active`, `wp-list-table`, `form-table`, `notice … inline`, `description`, `regular-text code`, `button`, `button-primary`, `submitdelete`, `screen-reader-text`). No colours, radii, shadows or type sizes of the plugin's own; this plan adds no CSS. Status is text, not a pill.
- Copy: sentence case, no exclamation marks. One translatable string per sentence, `_n()` on a counted noun, a translator comment directly above the one call whose string has the placeholder (never above a string without one), curly quotes and apostrophes. "Trash" is capitalised on PHP screens ("301, or 302 while restoring a page from the Trash could undo the move."). Text domain `hierarchy-guard`. The spec's sentences are used as written, including "Hierarchy Guard" in the Redirects tab's provider notice.
- Scale: nothing in a web request grows with the total number of recorded redirects beyond one page of 20 rows plus bounded counts. A listing reads the newest `Subtree::count_cap()` (default 1,000) post entries from `hierarchy_guard_recent_records` (one option read, then one `meta_id IN (…)` query on primary keys with `STRAIGHT_JOIN` to posts, no derived or temporary table), filtered by type through each type's own list, plus the root list (at most 200 paths per type), and sorts, searches and counts only those; past the cap the count shows as "1,000+". Overrides, statuses and holders are read for the page's 20 rows only; a search adds one hashed seek per type, which finds any record at that exact old URL. A bulk delete takes at most 20 IDs. The list is rebuilt from the database by the daily WP-Cron event `hierarchy_guard_rebuild_recent`, a single event that schedules its next run a day later (one query per type ordered by `meta_id DESC`, `LIMIT` 1,001): never in a web request; a page load only reads the autoloaded `hierarchy_guard_recent_state` and the cron array.
- Request arguments: no argument of the Redirects tab is named `post_type` or `taxonomy`; wp-admin/admin.php reads them before the page loads and, for a plugin page under Settings, refuses to load it. The type filter is `redirect_type`.
- Delete asks first, from a row, the Edit view or in bulk (once for the selection), with `window.confirm()` from the `redirects` bundle (`src/redirects/`, under ESLint and Vitest), enqueued on the Redirects tab only; the questions come from data attributes the page prints: "Delete the redirect from %s?" and "This can’t be undone.", or "Delete the selected redirects?" and "This can’t be undone.". Without JavaScript it deletes directly, as core does for terms and media.
- Right-to-left: an old URL prints in `<code>` and every other URL in `<span dir="ltr">` (an attribute, not CSS), so it reads left to right in a right-to-left admin.
- Every query goes through `$wpdb->prepare()`; `IN` lists are built only from `%s` or `%d` placeholders or integer-cast IDs.
- The ledger, `Resolver` and `Server` behave exactly as before, except for honouring overrides; with no override stored every answer, pair and CSV row is unchanged.
- Overrides: `_hierarchy_guard_target_{md5}` = `{ url, code }`, the same hash as the record's `_hierarchy_guard_path_{md5}`, as sibling post meta on the holder; `hierarchy_guard_root_targets`, not autoloaded, keyed by post type then path. `url` is an http(s) URL or ''; `code` is 301, 302 or 0 (automatic). An override with neither is not stored. Deleting a record deletes its override, wherever the ledger deletes a record (forget, prune, claim, the root list's cap). Every edit bumps the ledger's cache key (`hierarchy_guard` group).
- The Redirect to field is `type="text"` and takes a URL or a path on this site: a value starting with one `/` is read as `home_url( $value )`; `//host` and a value with no typed http(s) scheme are refused. A top-level root-list entry's field starts empty, and saving it empty keeps the entry and saves only the code.
- Targets: a URL that `url_to_postid()` resolves to a page of the record's own post type is a page target, and the record moves onto that page (out of the root list, or off its old holder), taking its code with it. The holder's own URL makes a page target again. Any other http(s) URL, a page of another post type included, is a URL target on the same holder. Anything that isn't http(s) is refused. A URL target answers its exact old path only; for a longer path, matching continues as if the record weren't there. A URL target is sent with `wp_redirect()` after `esc_url_raw()`, http(s) only; a page target still passes `wp_validate_redirect()`.
- Security: the page is added with `manage_options`; `Redirect_Edit::handle()` checks `manage_options` and the action's nonce before it changes anything: `hierarchy_guard_edit_{id}` to open the Edit view, `hierarchy_guard_save_{id}` to save, `hierarchy_guard_delete_{id}` to delete one, `bulk-redirects` (the table's own) to bulk delete.
- Record IDs: `{holder}.{md5(type:path)}`, holder 0 for the root list. An ID is accepted only when it matches `^\d{1,20}\.[0-9a-f]{32}$` and names an existing record of a supported type whose hash is of its holder's own type.
- Statuses, the first that applies: "Not active: a page uses this URL"; "Not active: the page it points to isn’t published"; "Not active: built-in redirects are off"; "Active".
- URLs: the page is `options-general.php?page=hierarchy-guard`, the Redirects tab adds `&tab=redirects`, the Edit view adds `&action=edit&redirect=<id>&_wpnonce=…`. The Settings tab's anchors stay `#hierarchy-guard-redirects` and `#hierarchy-guard-trash-policy`.
- Tests that check a hook is registered find the plugin's own object in `$GLOBALS['wp_filter'][ $hook ]->callbacks[ $priority ]`; `has_action( $hook )` alone is always true because core uses the same hooks.

## Review Focus

1. An old URL in another script (`/café/équipe/`): the ledger stores it percent-encoded, and a person reads and searches it as they type it, a full URL pasted from the browser (percent-encoded) included, past the cap too. Pinned in Task 5 (`test_an_old_url_with_other_scripts_reads_and_searches_as_people_type_it`) and Task 7 (`test_a_percent_encoded_search_finds_the_old_url_it_names`).
2. A site switched to plain permalinks, where the server serves nothing: no row may read "Active". Pinned in Task 5 (`test_under_plain_permalinks_no_row_reads_active`).
3. A page URL pasted the way people paste it, without its trailing slash, with a query string or with a `#fragment`: still a page target, and the record moves. Pinned in Task 5 (`test_a_page_url_pasted_without_its_slash_or_with_a_query_or_fragment_still_moves_the_record`).
4. A record deleted, by another administrator or another tab, between opening Edit and pressing Save: Save says it no longer exists and writes nothing, no orphan override included. Pinned in Task 8 (`test_a_record_deleted_between_edit_and_save_is_not_written_back`).
5. A crafted bulk request naming more than a page of IDs, or an ID nested in an array: at most 20 deleted, no PHP warning; and Filter, or Delete with nothing ticked, deletes nothing. Pinned in Task 7 (`test_a_crafted_bulk_request_deletes_at_most_one_page_and_ignores_nested_values`, `test_filter_never_runs_a_bulk_delete_and_an_empty_selection_returns_to_the_list`).

## Spec rulings and known limitations

Settled for this plan, by the spec or the owner; reviewers should not re-raise them.

- The newest 1,000 records (owner ruling): the ledger keeps `hierarchy_guard_recent_records`, the newest record meta IDs per post type, at most 1,000, updated in `record()` and whenever a ledger row is deleted. The Redirects tab sorts that window by old URL, filters by type through the type's own list, and shows "1,000+" past the cap; a search for a full old URL or path finds any record by its key, whatever the list holds. Writes are a plain read-modify-write with no lock; the daily rebuild (`hierarchy_guard_rebuild_recent`, scheduled on activation or by the first admin load that finds it missing) reads each supported type's newest entries again, so records made before the list existed reach it, and a lost update heals within a day; a search by full URL finds any record meanwhile. Until a first build has completed, the tab says the list is still being built; a type supported later is built by the next daily run, with no notice. On hosts without cron, `wp cron event run hierarchy_guard_rebuild_recent` runs it.
- Delete asks first (owner ruling), as core does for terms and media; without JavaScript it deletes directly. The notice says the old URL "no longer redirects through it": a shorter recorded URL, or core's own 404 guess, may still send it somewhere.
- A top-level root-list entry (owner ruling): its Edit view says where it leads, its field starts empty, and empty keeps it. A root-list entry given a URL target cannot be turned back into "Top level" except by deleting it.
- A record lives with its holder. A URL target on a page that is in the Trash is not served (the ledger never answers from a holder in the Trash), and its row reads "the page it points to isn’t published". When a page is deleted for good its records go with it; the old paths `before_delete_post` moves to the grandparent or the root list arrive there without their overrides.
- A chosen code along a chain of records: the first record the resolver's rounds use that has a chosen code decides.
- A URL target is sent as it was set: pagination, feed, comment-page, trackback and embed suffixes are not re-applied to it. The request's query string is carried over, before any `#fragment`.
- Under plain permalinks every row that would be active reads "Not active: built-in redirects are off", the nearest of the spec's three reasons.
- Two live posts can hold the same old path (rare: an alias and a page's own entry). Both are rows; only the newer is served, and deleting it lets the older one answer.
- The Settings tab's Redirects help still says "Turn this on so old URLs reach their new pages." while the switch is on, as it did on Settings › Writing; plan 6's copy pass owns it.

---

## File Structure

```
includes/HierarchyGuard/
├── Ledger.php                         MOD  overrides: target_key(), target(), set_target(), clean_target(), root_paths(); records drop them; the newest-records list: recent(), on_deleted_meta(), its daily self-scheduling rebuild
├── Settings.php                       MOD  GROUP, register_option()
├── Plugin.php                         MOD  wiring, deleted_post_meta → Ledger::on_deleted_meta, the rebuild's hooks
├── Redirects/Ledger_View.php          MOD  target()
├── Redirects/Live_View.php            MOD  target() from the ledger
├── Redirects/Prefetched_View.php      MOD  target() from facts read ahead
├── Redirects/Rules.php                MOD  URL targets on their exact path, the first chosen code
├── Redirects/Resolver.php             MOD  WP_Post|string|null, $code
├── Redirects/Server.php               MOD  URL targets through wp_redirect(), chosen codes
├── Redirects/Batch_Resolver.php       MOD  URL, $chosen, overrides in ledger_facts()
├── Redirects/Pairs.php                MOD  URL targets and chosen codes in a change's list; ledger key in the cache key
├── Redirects/Records.php              NEW  records: bounded listing, IDs, statuses, save, delete
└── Admin/
    ├── Settings_Section.php → Settings_Page.php   MOV  menu, nav tabs, Settings tab, Plugins link, load hook, titles
    ├── Redirects_Table.php            NEW  WP_List_Table of records
    ├── Redirect_Edit.php              NEW  Edit view; save, delete and bulk delete behind manage_options and nonces
    ├── Notices.php · Assets.php       MOD  links to the new page; Assets enqueues the redirects bundle on the tab
hierarchy-guard.php                    MOD  the rebuild scheduled on activation, cleared on deactivation
src/redirects/  confirm.js · index.js · test/confirm.test.js   NEW  Delete asks first
webpack.config.js                      MOD  the redirects entry
README.md                              MOD
src/common/test/change-notice.test.js  MOD  the settings URL it sets
tests/phpunit/
├── LedgerTest · ResolverTest · ServerTest · PairsTest · AssetsTest · NoticesTest   MOD
├── RecordsTest · RedirectsTabTest · RedirectEditTest · SettingsPageTest           NEW
└── SettingsSectionTest                                                          DEL
```

`Ledger` is the only class that reads or writes overrides and the newest-records list; `Rules` is the only place that decides what an override does to an answer, so the live and the batch resolvers cannot drift. `Records` is the only class that decides which records a listing shows, what a row's ID is, and what a save does; the admin classes check permissions and nonces, read the request, and print.

---

### Task 1: Overrides beside their records, and the newest-records list, in the ledger

**Files:**
- Modify: `includes/HierarchyGuard/Ledger.php` (three constants, `target_key()`, `target()`, `set_target()`, `clean_target()`, `root_paths()`, `recent()`, `on_deleted_meta()`, private helpers; `record()` lists each new entry; `record_root()`, `claim()`, `forget()`, `forget_root()` and `prune()` drop the override with the record; `deferred()` writes the list once)
- Modify: `includes/HierarchyGuard/Plugin.php` (hooks `Ledger::on_deleted_meta` on `deleted_post_meta`, `Ledger::schedule_rebuild` on `admin_init`, `Ledger::rebuild_recent` on its cron event `hierarchy_guard_rebuild_recent`; as first written, `schedule_backfill` and `backfill`)
- Modify: `hierarchy-guard.php` (activation schedules the daily rebuild, deactivation clears it)
- Test: `tests/phpunit/LedgerTest.php`

**Interfaces:**
- Produces: `Ledger::TARGET_PREFIX = '_hierarchy_guard_target_'` and `Ledger::ROOT_TARGETS = 'hierarchy_guard_root_targets'`.
- Produces: `Ledger::target_key( string $post_type, string $path ): string`: `TARGET_PREFIX . md5( "$post_type:$path" )`, the same hash as `Ledger::key()`.
- Produces: `Ledger::target( string $post_type, string $path, int $holder ): ?array{url:string,code:int}`: a post record's override from its sibling meta row (one point query, cached in the ledger group until the ledger's last-changed key moves), or a root-list entry's (`$holder` 0) from `ROOT_TARGETS[ $post_type ][ $path ]`. Null when there is none.
- Produces: `Ledger::set_target( string $post_type, string $path, int $holder, string $url, int $code ): void`: stores `{ url, code }`, or removes it when both are empty; always bumps the ledger cache key. The option is written with autoload off and deleted once empty.
- Produces: `Ledger::clean_target( mixed $value ): ?array{url:string,code:int}`: keeps an http(s) `url` (any case of scheme, trimmed) or '', and a `code` of 301 or 302 or 0; null when nothing is left.
- Produces: `Ledger::root_paths(): array<string,string[]>`: the root list option as read today.
- Produces: `Ledger::RECENT = 'hierarchy_guard_recent_records'`, a non-autoloaded option `[ type => [ 'ids' => int[] newest first, 'more' => bool ] ]`. `record()` puts each new entry's meta ID at the front of its type's list, keeping at most `Subtree::count_cap()` and setting `more` when one falls off; inside `Ledger::deferred()` the IDs wait and are written once at its end.
- Produces: `Ledger::recent( string[] $types ): array{ids:int[],more:bool}`: the listed types' IDs merged newest first, at most `Subtree::count_cap()`; `more` when older entries exist. One option read.
- Produces: `Ledger::on_deleted_meta( $meta_ids, $object_id, $meta_key ): void`, hooked on `deleted_post_meta` (3 arguments) by `Plugin`: a deleted `_hierarchy_guard_path_` row leaves the list, however it was deleted (`forget()`, `prune()`, `claim()`, core deleting the post).
- Produces (as revised by the final fix rounds): the daily rebuild, which replaced the one-time backfill. `Ledger::RECENT_STATE = 'hierarchy_guard_recent_state'` (autoloaded: `building`, or `done:` and the types built) and `Ledger::REBUILD_HOOK = 'hierarchy_guard_rebuild_recent'`. `Ledger::schedule_rebuild(): void` (activation and `admin_init`) makes sure one single event is scheduled, a day ahead once a first build has completed, else now, with the state `building`. `Ledger::rebuild_recent(): void` (the event only, never a web request) schedules its own next run a day later, reads each supported type's newest `count_cap() + 1` entry IDs in one query ordered by `meta_id DESC`, keeps entries recorded meanwhile while their rows exist, merges back a write another request made while it wrote, and sets `done:`; running it again changes nothing. `Ledger::building(): bool` and `Ledger::unschedule_rebuild(): void` (deactivation).
- Behaviour kept: `record()`, `find()`, `claim()` and the trash lookups answer as before; the only new writes are the newest-records list and the override deletions that go with a record.

- [ ] **Step 1: Write the failing tests**

In `tests/phpunit/LedgerTest.php`, replace:
```php
		delete_option( Ledger::ROOT_OPTION );
		Post_Types::reset();
```
with:
```php
		delete_option( Ledger::ROOT_OPTION );
		delete_option( Ledger::ROOT_TARGETS );
		delete_option( Ledger::RECENT );
		delete_option( Ledger::RECENT_STATE );
		wp_clear_scheduled_hook( Ledger::BACKFILL_HOOK );
		Post_Types::reset();
```

Append to the class in `tests/phpunit/LedgerTest.php`:
```php
	public function test_an_override_sits_beside_its_record_with_the_same_hash(): void {
		$this->ledger->record( $this->about, 'about-us' );
		$this->ledger->set_target( 'page', '/About-Us/', $this->about, 'https://example.org/jobs/', 302 );

		$this->assertSame( Ledger::TARGET_PREFIX . md5( 'page:about-us' ), Ledger::target_key( 'page', 'about-us' ) );
		$this->assertSame(
			[
				'url'  => 'https://example.org/jobs/',
				'code' => 302,
			],
			get_post_meta( $this->about, Ledger::target_key( 'page', 'about-us' ), true )
		);
		$this->assertSame(
			[
				'url'  => 'https://example.org/jobs/',
				'code' => 302,
			],
			$this->ledger->target( 'page', 'about-us', $this->about )
		);
		$this->assertSame( [ 'about-us' ], $this->ledger->paths_for( $this->about ), 'The override is not a record of its own.' );
		$this->assertNull( $this->ledger->target( 'page', 'elsewhere', $this->about ) );
	}

	public function test_an_override_with_nothing_left_is_removed(): void {
		$this->ledger->record( $this->about, 'about-us' );
		$this->ledger->set_target( 'page', 'about-us', $this->about, '', 301 );
		$this->assertSame(
			[
				'url'  => '',
				'code' => 301,
			],
			$this->ledger->target( 'page', 'about-us', $this->about ),
			'A chosen code alone is an override.'
		);

		$this->ledger->set_target( 'page', 'about-us', $this->about, '', 0 );

		$this->assertFalse( metadata_exists( 'post', $this->about, Ledger::target_key( 'page', 'about-us' ) ) );
		$this->assertNull( $this->ledger->target( 'page', 'about-us', $this->about ) );
	}

	public function test_clean_target_keeps_only_http_urls_and_the_two_codes(): void {
		$this->assertNull( Ledger::clean_target( 'https://example.org/' ) );
		$this->assertNull(
			Ledger::clean_target(
				[
					'url'  => 'javascript:alert(1)',
					'code' => 307,
				]
			)
		);
		$this->assertSame(
			[
				'url'  => '',
				'code' => 302,
			],
			Ledger::clean_target(
				[
					'url'  => 'ftp://example.org/',
					'code' => '302',
				]
			)
		);
		$this->assertSame(
			[
				'url'  => 'HTTP://example.org/',
				'code' => 0,
			],
			Ledger::clean_target( [ 'url' => ' HTTP://example.org/ ' ] )
		);
	}

	public function test_a_root_entry_s_override_lives_in_its_own_option(): void {
		global $wpdb;

		$this->ledger->record_root( 'page', 'archive' );
		$this->ledger->set_target( 'page', '/Archive/', 0, 'https://example.org/archive/', 0 );

		$this->assertSame(
			[
				'page' => [
					'archive' => [
						'url'  => 'https://example.org/archive/',
						'code' => 0,
					],
				],
			],
			get_option( Ledger::ROOT_TARGETS )
		);
		$this->assertSame( 'https://example.org/archive/', $this->ledger->target( 'page', 'archive', 0 )['url'] );
		$this->assertNull( $this->ledger->target( 'hg_docs', 'archive', 0 ), 'Overrides are scoped by post type.' );
		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", Ledger::ROOT_TARGETS ) );
		$this->assertContains( $autoload, [ 'no', 'off' ], 'The option is not autoloaded.' );

		$this->ledger->set_target( 'page', 'archive', 0, '', 0 );
		$this->assertFalse( get_option( Ledger::ROOT_TARGETS ), 'The last override gone, the option goes too.' );
	}

	public function test_forgetting_or_pruning_a_record_deletes_its_override(): void {
		global $wpdb;

		$this->ledger->record( $this->about, 'gone' );
		$this->ledger->set_target( 'page', 'gone', $this->about, '', 302 );
		$this->ledger->record( $this->about, 'old-about' );
		$this->ledger->set_target( 'page', 'old-about', $this->about, 'https://example.org/', 0 );

		$this->assertTrue( $this->ledger->forget( $this->about, 'gone' ) );
		$this->assertFalse( metadata_exists( 'post', $this->about, Ledger::target_key( 'page', 'gone' ) ) );

		$wpdb->update( $wpdb->posts, [ 'post_name' => 'old-about' ], [ 'ID' => $this->about ] );
		clean_post_cache( $this->about );
		$this->assertSame( 1, $this->ledger->prune( $this->about ) );
		$this->assertFalse( metadata_exists( 'post', $this->about, Ledger::target_key( 'page', 'old-about' ) ) );
	}

	public function test_a_root_entry_forgotten_or_pushed_out_by_the_cap_loses_its_override(): void {
		$this->ledger->record_root( 'page', 'first' );
		$this->ledger->record_root( 'page', 'second' );
		$this->ledger->set_target( 'page', 'first', 0, '', 301 );
		$this->ledger->set_target( 'page', 'second', 0, '', 302 );

		$this->assertTrue( $this->ledger->forget_root( 'page', 'second' ) );
		$this->assertNull( $this->ledger->target( 'page', 'second', 0 ) );
		$this->assertSame( 301, $this->ledger->target( 'page', 'first', 0 )['code'] );

		for ( $i = 0; $i < Ledger::ROOT_CAP; $i++ ) {
			$this->ledger->record_root( 'page', 'later-' . $i );
		}
		$this->assertNull( $this->ledger->find( 'page', 'first' ), 'Precondition: the oldest entry fell off the end.' );
		$this->assertFalse( get_option( Ledger::ROOT_TARGETS ), 'Its override went with it.' );
	}

	public function test_a_page_that_takes_a_path_drops_the_overrides_with_the_entries(): void {
		$moved = self::factory()->post->create(
			[
				'post_type' => 'page',
				'post_name' => 'moved',
			]
		);
		$this->ledger->record( $moved, 'taken' );
		$this->ledger->set_target( 'page', 'taken', $moved, 'https://example.org/', 0 );
		$this->ledger->record_root( 'page', 'taken' );
		$this->ledger->set_target( 'page', 'taken', 0, '', 302 );

		self::factory()->post->create(
			[
				'post_type' => 'page',
				'post_name' => 'taken',
			]
		);

		$this->assertFalse( metadata_exists( 'post', $moved, Ledger::target_key( 'page', 'taken' ) ) );
		$this->assertFalse( get_option( Ledger::ROOT_TARGETS ) );
	}

	public function test_an_override_is_read_once_and_re_read_after_any_ledger_write(): void {
		$this->ledger->record( $this->about, 'about-us' );
		$this->assertNull( $this->ledger->target( 'page', 'about-us', $this->about ) );

		$queries = get_num_queries();
		$this->assertNull( $this->ledger->target( 'page', 'about-us', $this->about ) );
		$this->assertSame( $queries, get_num_queries(), 'A repeated read is served from the cache.' );

		$before = wp_cache_get_last_changed( Ledger::CACHE_GROUP );
		$this->ledger->set_target( 'page', 'about-us', $this->about, '', 302 );
		$this->assertNotSame( $before, wp_cache_get_last_changed( Ledger::CACHE_GROUP ), 'An edit bumps the ledger cache key.' );
		$this->assertSame( 302, $this->ledger->target( 'page', 'about-us', $this->about )['code'] );
	}

	/**
	 * The meta ID of a post's entry for a path.
	 *
	 * @param int    $post_id Post.
	 * @param string $path    Normalized path.
	 * @param string $type    Post type.
	 * @return int
	 */
	private function meta_id( int $post_id, string $path, string $type = 'page' ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", $post_id, Ledger::key( $type, $path ) ) );
	}

	public function test_each_entry_recorded_goes_first_on_its_type_s_newest_list(): void {
		global $wpdb;

		$doc = self::factory()->post->create(
			[
				'post_type' => 'hg_docs',
				'post_name' => 'guide',
			]
		);
		$this->ledger->record( $this->about, 'one' );
		$this->ledger->record( $doc, 'manual' );
		$this->ledger->record( $this->about, 'two' );

		$one    = $this->meta_id( $this->about, 'one' );
		$two    = $this->meta_id( $this->about, 'two' );
		$manual = $this->meta_id( $doc, 'manual', 'hg_docs' );
		$this->assertSame(
			[
				'ids'  => [ $two, $one ],
				'more' => false,
			],
			$this->ledger->recent( [ 'page' ] )
		);
		$this->assertSame( [ $two, $manual, $one ], $this->ledger->recent( [ 'page', 'hg_docs' ] )['ids'], 'Types merge newest first.' );
		$this->assertSame( [], $this->ledger->recent( [ 'post' ] )['ids'] );
		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", Ledger::RECENT ) );
		$this->assertContains( $autoload, [ 'no', 'off' ], 'The list is not autoloaded.' );
	}

	public function test_the_newest_list_keeps_the_cap_and_says_older_entries_exist(): void {
		$cap = static fn(): int => 2;
		add_filter( 'hierarchy_guard_count_cap', $cap );
		foreach ( [ 'one', 'two', 'three' ] as $path ) {
			$this->ledger->record( $this->about, $path );
		}
		remove_filter( 'hierarchy_guard_count_cap', $cap );

		$this->assertSame(
			[
				'ids'  => [ $this->meta_id( $this->about, 'three' ), $this->meta_id( $this->about, 'two' ) ],
				'more' => true,
			],
			$this->ledger->recent( [ 'page' ] )
		);
	}

	public function test_an_entry_leaves_the_newest_list_however_its_row_goes(): void {
		global $wpdb;

		$other = self::factory()->post->create(
			[
				'post_type' => 'page',
				'post_name' => 'other',
			]
		);
		foreach ( [ 'forgotten', 'pruned', 'taken', 'kept' ] as $path ) {
			$this->ledger->record( $this->about, $path );
		}
		$this->ledger->record( $other, 'deleted-with-its-page' );
		$kept = $this->meta_id( $this->about, 'kept' );

		$this->ledger->forget( $this->about, 'forgotten' );
		$wpdb->update( $wpdb->posts, [ 'post_name' => 'pruned' ], [ 'ID' => $this->about ] );
		clean_post_cache( $this->about );
		$this->ledger->prune( $this->about );
		self::factory()->post->create(
			[
				'post_type' => 'page',
				'post_name' => 'taken',
			]
		);
		wp_delete_post( $other, true );

		$this->assertSame( [ $kept ], $this->ledger->recent( [ 'page' ] )['ids'] );
	}

	public function test_entries_recorded_in_a_batch_join_the_newest_list_at_its_end(): void {
		$inside = null;
		Ledger::deferred(
			function () use ( &$inside ): void {
				$this->ledger->record( $this->about, 'one' );
				$this->ledger->record( $this->about, 'two' );
				$inside = get_option( Ledger::RECENT );
			}
		);

		$this->assertFalse( $inside, 'Nothing is written while deferred.' );
		$this->assertSame( [ $this->meta_id( $this->about, 'two' ), $this->meta_id( $this->about, 'one' ) ], $this->ledger->recent( [ 'page' ] )['ids'] );
	}

	/**
	 * An entry written straight into post meta, as records made before the
	 * newest-records list existed are: not on the list.
	 *
	 * @param int    $post_id Post.
	 * @param string $path    Normalized path.
	 * @return int Meta ID.
	 */
	private function old_entry( int $post_id, string $path ): int {
		return (int) add_post_meta( $post_id, Ledger::key( 'page', $path ), $path, true );
	}

	public function test_records_made_before_the_list_reach_it_once_the_backfill_runs(): void {
		$first  = $this->old_entry( $this->about, 'first' );
		$second = $this->old_entry( $this->about, 'second' );
		$this->assertSame( [], $this->ledger->recent( [ 'page' ] )['ids'], 'Precondition: not on the list.' );

		Ledger::schedule_backfill();
		$this->assertTrue( Ledger::backfilling() );
		$this->assertNotFalse( wp_next_scheduled( Ledger::BACKFILL_HOOK ), 'It runs from WP-Cron.' );

		$this->assertSame( 'hierarchy_guard_backfill_recent', Ledger::BACKFILL_HOOK );
		do_action( 'hierarchy_guard_backfill_recent' );

		$this->assertSame( [ $second, $first ], $this->ledger->recent( [ 'page' ] )['ids'] );
		$this->assertFalse( Ledger::backfilling() );
		$this->assertSame( 'done', get_option( Ledger::RECENT_STATE ) );
	}

	public function test_the_backfill_is_idempotent_keeps_what_was_recorded_meanwhile_and_stops_at_the_cap(): void {
		$old = [];
		foreach ( [ 'a', 'b', 'c' ] as $path ) {
			$old[] = $this->old_entry( $this->about, $path );
		}
		$this->ledger->record( $this->about, 'meanwhile' );
		$meanwhile = $this->meta_id( $this->about, 'meanwhile' );

		Ledger::backfill();
		$once = get_option( Ledger::RECENT );
		Ledger::backfill();

		$this->assertSame( $once, get_option( Ledger::RECENT ), 'Running it again changes nothing.' );
		$this->assertSame( [ $meanwhile, $old[2], $old[1], $old[0] ], $this->ledger->recent( [ 'page' ] )['ids'] );

		delete_option( Ledger::RECENT );
		$cap = static fn(): int => 2;
		add_filter( 'hierarchy_guard_count_cap', $cap );
		Ledger::backfill();
		remove_filter( 'hierarchy_guard_count_cap', $cap );
		$this->assertSame(
			[
				'ids'  => [ $meanwhile, $old[2] ],
				'more' => true,
			],
			$this->ledger->recent( [ 'page' ] ),
			'One bounded query per type: the newest, and whether there are older ones.'
		);
	}

	public function test_once_built_a_normal_load_runs_nothing(): void {
		global $wp_filter;

		Ledger::schedule_backfill();
		Ledger::backfill();
		wp_clear_scheduled_hook( Ledger::BACKFILL_HOOK );

		$queries = get_num_queries();
		Ledger::schedule_backfill();
		$this->assertSame( $queries, get_num_queries(), 'One read of an autoloaded option.' );
		$this->assertFalse( wp_next_scheduled( Ledger::BACKFILL_HOOK ), 'Nothing is scheduled again.' );

		$hooked = static function ( string $hook, string $method ) use ( $wp_filter ): bool {
			foreach ( $wp_filter[ $hook ]->callbacks[10] ?? [] as $callback ) {
				if ( [ Ledger::class, $method ] === $callback['function'] ) {
					return true;
				}
			}
			return false;
		};
		$this->assertTrue( $hooked( 'admin_init', 'schedule_backfill' ) );
		$this->assertTrue( $hooked( Ledger::BACKFILL_HOOK, 'backfill' ) );
		foreach ( [ 'init', 'wp', 'template_redirect', 'admin_init' ] as $hook ) {
			$this->assertFalse( $hooked( $hook, 'backfill' ), "The backfill never runs on $hook." );
		}
	}

	public function test_deactivation_clears_an_unfinished_backfill(): void {
		Ledger::schedule_backfill();

		Ledger::unschedule_backfill();

		$this->assertFalse( wp_next_scheduled( Ledger::BACKFILL_HOOK ) );
		$this->assertFalse( get_option( Ledger::RECENT_STATE ), 'The next activation schedules it again.' );
	}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npm run test:php -- --filter LedgerTest`
Expected: FAIL. Every test errors in `set_up()` with `Error: Undefined constant HierarchyGuard\Ledger::ROOT_TARGETS`.

- [ ] **Step 3: Store, read and drop overrides, and keep the newest-records list**

In `includes/HierarchyGuard/Ledger.php`, make these replacements.

Replace:
```php

	public const KEY_PREFIX  = '_hierarchy_guard_path_';
	public const ROOT_OPTION = 'hierarchy_guard_root_paths';
	public const CACHE_GROUP = 'hierarchy_guard';
	public const ROOT_CAP    = 200;

```
with:
```php

	public const KEY_PREFIX    = '_hierarchy_guard_path_';
	public const TARGET_PREFIX = '_hierarchy_guard_target_';
	public const ROOT_OPTION   = 'hierarchy_guard_root_paths';
	public const ROOT_TARGETS  = 'hierarchy_guard_root_targets';
	public const RECENT        = 'hierarchy_guard_recent_records';
	public const RECENT_STATE  = 'hierarchy_guard_recent_state';
	public const BACKFILL_HOOK = 'hierarchy_guard_backfill_recent';
	public const CACHE_GROUP   = 'hierarchy_guard';
	public const ROOT_CAP      = 200;

```

Replace:
```php
	/**
	 * Meta key for a (post type, path) pair.
```
with:
```php
	/**
	 * Entries recorded inside deferred(), meta IDs by post type, oldest first.
	 *
	 * @var array<string,int[]>
	 */
	private static array $recent_pending = [];

	/**
	 * Meta key for a (post type, path) pair.
```

Replace:
```php
		return self::KEY_PREFIX . md5( $post_type . ':' . $path );
	}
```
with:
```php
		return self::KEY_PREFIX . md5( $post_type . ':' . $path );
	}

	/**
	 * Meta key of the override beside a record: the same hash as its entry.
	 *
	 * @param string $post_type Post type name.
	 * @param string $path      Normalized path.
	 * @return string
	 */
	public static function target_key( string $post_type, string $path ): string {
		return self::TARGET_PREFIX . md5( $post_type . ':' . $path );
	}
```

Replace:
```php

		add_post_meta( $post_id, $key, $old_path, true );
		$this->bump();
```
with:
```php

		$meta_id = add_post_meta( $post_id, $key, $old_path, true );
		if ( is_int( $meta_id ) ) {
			self::remember( (string) $post->post_type, [ $meta_id ] );
		}
		$this->bump();
```

Replace:
```php
		if ( count( $list ) > self::ROOT_CAP ) {
			$list = array_slice( $list, -self::ROOT_CAP );
```
with:
```php
		if ( count( $list ) > self::ROOT_CAP ) {
			$this->drop_root_targets( $post_type, array_slice( $list, 0, count( $list ) - self::ROOT_CAP ) );
			$list = array_slice( $list, -self::ROOT_CAP );
```

Replace:
```php
		foreach ( array_unique( array_map( 'intval', $ids ) ) as $id ) {
			$dropped = delete_post_meta( $id, $key ) || $dropped;
```
with:
```php
		foreach ( array_unique( array_map( 'intval', $ids ) ) as $id ) {
			$this->drop_target( $id, $type, $path );
			$dropped = delete_post_meta( $id, $key ) || $dropped;
```

Replace:
```php

		$key = self::key( (string) $post->post_type, Paths::normalize( $old_path ) );
		if ( ! metadata_exists( 'post', $post_id, $key ) ) {
```
with:
```php

		$old_path = Paths::normalize( $old_path );
		$key      = self::key( (string) $post->post_type, $old_path );
		if ( ! metadata_exists( 'post', $post_id, $key ) ) {
```

Replace:
```php
			return false;
		}

		delete_post_meta( $post_id, $key );
```
with:
```php
			return false;
		}

		$this->drop_target( $post_id, (string) $post->post_type, $old_path );
		delete_post_meta( $post_id, $key );
```

Replace:
```php
		$roots[ $post_type ] = array_values( array_diff( $list, [ $old_path ] ) );
		update_option( self::ROOT_OPTION, $roots, false );
		$this->bump();
```
with:
```php
		$roots[ $post_type ] = array_values( array_diff( $list, [ $old_path ] ) );
		update_option( self::ROOT_OPTION, $roots, false );
		$this->drop_root_targets( $post_type, [ $old_path ] );
		$this->bump();
```

Replace:
```php
			--self::$defer_depth;
			if ( 0 === self::$defer_depth && self::$bump_pending ) {
```
with:
```php
			--self::$defer_depth;
			if ( 0 === self::$defer_depth && [] !== self::$recent_pending ) {
				$pending              = self::$recent_pending;
				self::$recent_pending = [];
				foreach ( $pending as $type => $ids ) {
					self::remember( (string) $type, $ids );
				}
			}
			if ( 0 === self::$defer_depth && self::$bump_pending ) {
```

Replace:
```php

		$key = self::key( (string) $post->post_type, Paths::uri( $post ) );
		if ( ! metadata_exists( 'post', $post_id, $key ) ) {
```
with:
```php

		$path = Paths::uri( $post );
		$key  = self::key( (string) $post->post_type, $path );
		if ( ! metadata_exists( 'post', $post_id, $key ) ) {
```

Replace:
```php
		}

		delete_post_meta( $post_id, $key );
```
with:
```php
		}

		$this->drop_target( $post_id, (string) $post->post_type, $path );
		delete_post_meta( $post_id, $key );
```

Replace:
```php
	/**
	 * Single index seek on the hashed key: a live holder first, newest entry first.
```
with:
```php
	/**
	 * The override an administrator set on a record from the Redirects tab:
	 * a URL target, a chosen code, or both. A post's record keeps it in the
	 * sibling meta row with the entry's hash, read with one seek and cached
	 * until the ledger changes; a root-list entry keeps it in the
	 * ROOT_TARGETS option, by post type then path.
	 *
	 * @param string $post_type Post type name.
	 * @param string $path      Path, any form; normalized here.
	 * @param int    $holder    The post that holds the record, 0 for the root list.
	 * @return array{url:string,code:int}|null Null when the record has no override.
	 */
	public function target( string $post_type, string $path, int $holder ): ?array {
		global $wpdb;

		$path = Paths::normalize( $path );
		if ( $holder <= 0 ) {
			return self::clean_target( $this->root_targets()[ $post_type ][ $path ] ?? null );
		}

		$cache_key = 'target:' . $holder . ':' . md5( $post_type . ':' . $path ) . ':' . wp_cache_get_last_changed( self::CACHE_GROUP );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return self::clean_target( $cached['target'] ?? null );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Point lookup on the post_id index, one row; cached below.
		$raw    = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1", $holder, self::target_key( $post_type, $path ) ) );
		$target = null === $raw ? null : maybe_unserialize( (string) $raw );
		// phpcs:ignore WordPressVIPMinimum.Performance.LowExpiryCacheTime.CacheTimeUndetermined -- CACHE_TTL is 12 hours, well above 300 seconds.
		wp_cache_set( $cache_key, [ 'target' => $target ], self::CACHE_GROUP, self::CACHE_TTL );

		return self::clean_target( $target );
	}

	/**
	 * Stores a record's override, or removes it when it has neither a URL
	 * nor a chosen code. Every call drops the cached lookups.
	 *
	 * @param string $post_type Post type name.
	 * @param string $path      Path, any form; normalized here.
	 * @param int    $holder    The post that holds the record, 0 for the root list.
	 * @param string $url       An http(s) URL target, or '' for none.
	 * @param int    $code      301 or 302, or 0 for the automatic code.
	 */
	public function set_target( string $post_type, string $path, int $holder, string $url, int $code ): void {
		$path   = Paths::normalize( $path );
		$target = self::clean_target(
			[
				'url'  => $url,
				'code' => $code,
			]
		);

		if ( $holder > 0 ) {
			$key = self::target_key( $post_type, $path );
			if ( null === $target ) {
				delete_post_meta( $holder, $key );
			} else {
				update_post_meta( $holder, $key, $target );
			}
		} else {
			$all = $this->root_targets();
			if ( null === $target ) {
				unset( $all[ $post_type ][ $path ] );
			} else {
				$all[ $post_type ][ $path ] = $target;
			}
			$this->save_root_targets( $all );
		}

		$this->bump();
	}

	/**
	 * An override as stored, checked: an http(s) URL or none, and 301, 302
	 * or 0. Null when nothing is left.
	 *
	 * @param mixed $value Stored value.
	 * @return array{url:string,code:int}|null
	 */
	public static function clean_target( $value ): ?array {
		if ( ! is_array( $value ) ) {
			return null;
		}
		$url    = isset( $value['url'] ) && is_string( $value['url'] ) ? trim( $value['url'] ) : '';
		$code   = isset( $value['code'] ) && is_numeric( $value['code'] ) ? (int) $value['code'] : 0;
		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		if ( '' !== $url && ! in_array( $scheme, [ 'http', 'https' ], true ) ) {
			$url = '';
		}
		if ( ! in_array( $code, [ 301, 302 ], true ) ) {
			$code = 0;
		}
		if ( '' === $url && 0 === $code ) {
			return null;
		}
		return [
			'url'  => $url,
			'code' => $code,
		];
	}

	/**
	 * The root list: old parent paths whose children became top level, by post type.
	 *
	 * @return array<string,string[]>
	 */
	public function root_paths(): array {
		return $this->roots();
	}

	/**
	 * The newest post entries of the given types, newest first: the meta IDs
	 * the RECENT option keeps per type as entries are recorded, at most
	 * Subtree::count_cap() in all. `more` says older entries exist that the
	 * list no longer holds. One option read; an entry deleted since may
	 * still be named, and a reader skips it.
	 *
	 * @param string[] $types Post type names.
	 * @return array{ids:int[],more:bool}
	 */
	public function recent( array $types ): array {
		$all  = get_option( self::RECENT, [] );
		$all  = is_array( $all ) ? $all : [];
		$ids  = [];
		$more = false;
		foreach ( $types as $type ) {
			$list = is_array( $all[ $type ] ?? null ) ? $all[ $type ] : [];
			$ids  = array_merge( $ids, array_map( 'intval', (array) ( $list['ids'] ?? [] ) ) );
			$more = $more || ! empty( $list['more'] );
		}
		rsort( $ids );
		$cap = Subtree::count_cap();
		return [
			'ids'  => array_slice( $ids, 0, $cap ),
			'more' => $more || count( $ids ) > $cap,
		];
	}

	/**
	 * Schedules the one-time backfill of the newest-records list, on
	 * activation and on admin_init, while the list was never built: records
	 * made before the list existed reach it from WP-Cron, never in a web
	 * request. Once built, this is one read of an autoloaded option.
	 */
	public static function schedule_backfill(): void {
		$state = get_option( self::RECENT_STATE );
		if ( 'done' === $state ) {
			return;
		}
		if ( 'building' !== $state ) {
			update_option( self::RECENT_STATE, 'building', true );
		}
		if ( false === wp_next_scheduled( self::BACKFILL_HOOK ) ) {
			wp_schedule_single_event( time(), self::BACKFILL_HOOK );
		}
	}

	/**
	 * Clears the backfill on deactivation; one not finished is scheduled
	 * again on the next activation.
	 */
	public static function unschedule_backfill(): void {
		wp_clear_scheduled_hook( self::BACKFILL_HOOK );
		if ( 'building' === get_option( self::RECENT_STATE ) ) {
			delete_option( self::RECENT_STATE );
		}
	}

	/**
	 * Whether the newest-records list is still being built.
	 *
	 * @return bool
	 */
	public static function backfilling(): bool {
		return 'building' === get_option( self::RECENT_STATE );
	}

	/**
	 * The one-time backfill, on its WP-Cron event: each supported type's list
	 * gets the newest Subtree::count_cap() entries of that type, in one query
	 * per type ordered by meta ID, merged with what the list already holds,
	 * so running it again changes nothing. Then it marks itself done.
	 */
	public static function backfill(): void {
		global $wpdb;

		$cap   = Subtree::count_cap();
		$found = [];
		foreach ( Post_Types::supported() as $type ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Once per site, from WP-Cron: the newest entries of one type, LIMIT the cap plus one.
			$found[ $type ] = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT pm.meta_id FROM {$wpdb->postmeta} pm STRAIGHT_JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key LIKE %s AND p.post_type = %s ORDER BY pm.meta_id DESC LIMIT %d", $wpdb->esc_like( self::KEY_PREFIX ) . '%', $type, $cap + 1 ) ) );
		}

		// Entries recorded while the queries ran are kept.
		$all = get_option( self::RECENT, [] );
		$all = is_array( $all ) ? $all : [];
		foreach ( $found as $type => $ids ) {
			$list = is_array( $all[ $type ] ?? null ) ? $all[ $type ] : [];
			$ids  = array_values( array_unique( array_merge( $ids, array_map( 'intval', (array) ( $list['ids'] ?? [] ) ) ) ) );
			rsort( $ids );

			$all[ $type ] = [
				'ids'  => array_slice( $ids, 0, $cap ),
				'more' => ! empty( $list['more'] ) || count( $ids ) > $cap,
			];
		}
		update_option( self::RECENT, $all, false );
		update_option( self::RECENT_STATE, 'done', true );
	}

	/**
	 * Takes entries out of the newest-records list when their rows are
	 * deleted, by forget(), prune(), claim() or core deleting their post:
	 * hooked on deleted_post_meta. Other keys cost a prefix check.
	 *
	 * @param int[]  $meta_ids  Meta IDs deleted.
	 * @param int    $object_id Post ID.
	 * @param string $meta_key  Meta key.
	 */
	public static function on_deleted_meta( $meta_ids, $object_id, $meta_key ): void {
		unset( $object_id );
		if ( ! str_starts_with( (string) $meta_key, self::KEY_PREFIX ) ) {
			return;
		}
		$gone = array_flip( array_map( 'intval', (array) $meta_ids ) );
		$all  = get_option( self::RECENT, [] );
		if ( ! is_array( $all ) ) {
			return;
		}
		$changed = false;
		foreach ( $all as $type => $list ) {
			$ids  = array_map( 'intval', (array) ( $list['ids'] ?? [] ) );
			$left = array_values( array_filter( $ids, static fn( int $id ): bool => ! isset( $gone[ $id ] ) ) );
			if ( count( $left ) !== count( $ids ) ) {
				$all[ $type ]['ids'] = $left;
				$changed             = true;
			}
		}
		if ( $changed ) {
			update_option( self::RECENT, $all, false );
		}
	}

	/**
	 * Single index seek on the hashed key: a live holder first, newest entry first.
```

Replace:
```php
	/**
	 * Invalidates every cached lookup, or notes that it is due inside deferred().
```
with:
```php
	/**
	 * Puts entries just recorded at the front of their type's newest-records
	 * list, keeping at most Subtree::count_cap(); inside deferred() they wait
	 * and are written once at its end. The option is not autoloaded.
	 *
	 * @param string $post_type Post type name.
	 * @param int[]  $meta_ids  Meta IDs, oldest first.
	 */
	private static function remember( string $post_type, array $meta_ids ): void {
		if ( self::$defer_depth > 0 ) {
			self::$recent_pending[ $post_type ] = array_merge( self::$recent_pending[ $post_type ] ?? [], $meta_ids );
			return;
		}
		$all  = get_option( self::RECENT, [] );
		$all  = is_array( $all ) ? $all : [];
		$list = is_array( $all[ $post_type ] ?? null ) ? $all[ $post_type ] : [];
		$ids  = array_merge( array_reverse( array_map( 'intval', $meta_ids ) ), array_map( 'intval', (array) ( $list['ids'] ?? [] ) ) );
		$cap  = Subtree::count_cap();

		$all[ $post_type ] = [
			'ids'  => array_slice( $ids, 0, $cap ),
			'more' => ! empty( $list['more'] ) || count( $ids ) > $cap,
		];
		update_option( self::RECENT, $all, false );
	}

	/**
	 * The root list's overrides, by post type then path.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function root_targets(): array {
		$all = get_option( self::ROOT_TARGETS, [] );
		return is_array( $all ) ? $all : [];
	}

	/**
	 * Saves the root list's overrides, not autoloaded; removes the option once it is empty.
	 *
	 * @param array<string,array<string,mixed>> $all Overrides by post type then path.
	 */
	private function save_root_targets( array $all ): void {
		$all = array_filter( $all, static fn( $by_path ): bool => is_array( $by_path ) && [] !== $by_path );
		if ( [] === $all ) {
			delete_option( self::ROOT_TARGETS );
			return;
		}
		update_option( self::ROOT_TARGETS, $all, false );
	}

	/**
	 * Removes the overrides of root-list entries that are going.
	 *
	 * @param string   $post_type Post type name.
	 * @param string[] $paths     Normalized paths.
	 */
	private function drop_root_targets( string $post_type, array $paths ): void {
		$all = $this->root_targets();
		if ( ! isset( $all[ $post_type ] ) || ! is_array( $all[ $post_type ] ) ) {
			return;
		}
		$left = array_diff_key( $all[ $post_type ], array_flip( $paths ) );
		if ( count( $left ) === count( $all[ $post_type ] ) ) {
			return;
		}
		$all[ $post_type ] = $left;
		$this->save_root_targets( $all );
	}

	/**
	 * Removes the override beside a post's record that is going. The post's
	 * meta is in the cache by then, so a record without one costs no query.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $post_type Post type name.
	 * @param string $path      Normalized path.
	 */
	private function drop_target( int $post_id, string $post_type, string $path ): void {
		$key = self::target_key( $post_type, $path );
		if ( metadata_exists( 'post', $post_id, $key ) ) {
			delete_post_meta( $post_id, $key );
		}
	}

	/**
	 * Invalidates every cached lookup, or notes that it is due inside deferred().
```

In `includes/HierarchyGuard/Plugin.php`, replace:
```php

		$ledger    = new Ledger();
```
with:
```php

		// The newest-records list drops an entry whenever its row goes, however it goes.
		add_action( 'deleted_post_meta', [ Ledger::class, 'on_deleted_meta' ], 10, 3 );
		// Records made before the list existed reach it once, from WP-Cron.
		add_action( 'admin_init', [ Ledger::class, 'schedule_backfill' ] );
		add_action( Ledger::BACKFILL_HOOK, [ Ledger::class, 'backfill' ] );

		$ledger    = new Ledger();
```

In `hierarchy-guard.php`, replace:
```php
register_activation_hook( __FILE__, [ Batch\Processor::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ Batch\Processor::class, 'deactivate' ] );

```
with:
```php
register_activation_hook( __FILE__, [ Batch\Processor::class, 'activate' ] );
register_activation_hook( __FILE__, [ Ledger::class, 'schedule_backfill' ] );
register_deactivation_hook( __FILE__, [ Batch\Processor::class, 'deactivate' ] );
register_deactivation_hook( __FILE__, [ Ledger::class, 'unschedule_backfill' ] );

```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `npm run test:php -- --filter LedgerTest`
Expected: PASS (38 tests).

Run: `npm run test:php` then `composer lint`
Expected: the whole suite passes, with the new tests counted; lint exits 0. Run `composer lint:fix` first if it reports alignment; formatting-only changes are not deviations.

- [ ] **Step 5: Commit**

```bash
git add hierarchy-guard.php includes/HierarchyGuard/Ledger.php includes/HierarchyGuard/Plugin.php tests/phpunit/LedgerTest.php
git commit -m "feat: keep a recorded redirect's override beside its record"
```

### Task 2: The rules honour overrides, live and in a batch

**Files:**
- Modify: `includes/HierarchyGuard/Redirects/Ledger_View.php` (one method on the interface)
- Modify: `includes/HierarchyGuard/Redirects/Live_View.php`, `includes/HierarchyGuard/Redirects/Prefetched_View.php` (implement it)
- Modify: `includes/HierarchyGuard/Redirects/Rules.php` (`resolve()`, `rewrite_once()`)
- Modify: `includes/HierarchyGuard/Redirects/Resolver.php` (`resolve()`)
- Modify: `includes/HierarchyGuard/Redirects/Batch_Resolver.php` (`URL`, `served()`, `run()`, `ledger_facts()`)
- Test: `tests/phpunit/ResolverTest.php`

**Interfaces:**
- Consumes: `Ledger::target()`, `Ledger::target_key()`, `Ledger::clean_target()`, `Ledger::ROOT_TARGETS` (Task 1).
- Produces: `Ledger_View::target( string $post_type, string $prefix, int $hit ): ?array{url:string,code:int}`: the override on the record `find()` answered with (`$hit` is the holder, or `Rules::ROOT`).
- Produces: `Rules::resolve( Ledger_View $view, string $post_type, string $path, bool &$temporary, ?array &$trace = null, ?int &$code = null ): WP_Post|string|null`: a string is the http(s) URL of a URL target; `$code` is the 301 or 302 chosen on the first record the rounds used that has one, else 0.
- Produces: `Rules::rewrite_once( …, ?array &$override = null ): ?string`: a record with a URL target is used only when its old path is the whole path (then the candidate is `''` and `$override` carries the URL); for a longer path it is skipped, so a shorter prefix can match. A page target's checks (holder in the trash, holder under a trashed parent, the exact path a trashed page holds) are unchanged.
- Produces: `Resolver::resolve( string $post_type, string $path, ?bool &$temporary = null, ?int &$code = null ): WP_Post|string|null`. A URL answer skips the moved-marker check, which is about a target page.
- Produces: `Batch_Resolver::URL = -3` and `Batch_Resolver::served( array $paths, string $post_type, array &$facts, ?array &$temporary = null, ?array &$chosen = null ): array`: a path a URL target answers maps to `URL`; `$chosen[ $path ] = { url, code }` for every path this type's records answer with an override; a URL answer through a prefix a trashed page holds is temporary.
- Produces: `Batch_Resolver::ledger_facts()` facts gain `target`: the override beside the live holder's record, read in the same single query (the target keys join the `IN` list).

- [ ] **Step 1: Write the failing tests**

In `tests/phpunit/ResolverTest.php`, make these replacements.

Replace:
```php
use HierarchyGuard\Post_Types;
use HierarchyGuard\Redirects\Resolver;
```
with:
```php
use HierarchyGuard\Post_Types;
use HierarchyGuard\Redirects\Batch_Resolver;
use HierarchyGuard\Redirects\Resolver;
```

Replace:
```php
 * @covers \HierarchyGuard\Redirects\Resolver
 */
```
with:
```php
 * @covers \HierarchyGuard\Redirects\Resolver
 * @covers \HierarchyGuard\Redirects\Rules
 */
```

Replace:
```php
		delete_option( Ledger::ROOT_OPTION );
		Post_Types::reset();
```
with:
```php
		delete_option( Ledger::ROOT_OPTION );
		delete_option( Ledger::ROOT_TARGETS );
		Post_Types::reset();
```

Append to the class in `tests/phpunit/ResolverTest.php`:
```php
	public function test_a_url_target_answers_its_exact_old_path_with_its_url(): void {
		$this->ledger->record( $this->about, 'old/about' );
		$this->ledger->set_target( 'page', 'old/about', $this->about, 'https://example.org/about/', 0 );

		$code = null;
		$this->assertSame( 'https://example.org/about/', $this->resolver->resolve( 'page', '/Old/About/', $temporary, $code ) );
		$this->assertFalse( $temporary );
		$this->assertSame( 0, $code, 'No code was chosen: the automatic one applies.' );
	}

	public function test_past_a_url_target_a_longer_path_resolves_as_if_the_record_were_not_there(): void {
		$this->ledger->record( $this->company, 'old' );
		$this->ledger->record( $this->about, 'old/about' );
		$this->assertSame( $this->about, $this->resolver->resolve( 'page', 'old/about' )->ID, 'Precondition: the record answers.' );

		$this->ledger->set_target( 'page', 'old/about', $this->about, 'https://example.org/about/', 0 );

		$this->assertSame( $this->team, $this->resolver->resolve( 'page', 'old/about/team' )->ID, 'The shorter prefix answers: old/about/team → company/about/team.' );
		$this->ledger->forget( $this->company, 'old' );
		$this->assertNull( $this->resolver->resolve( 'page', 'old/about/team' ), 'With no shorter prefix, nothing answers.' );
	}

	public function test_a_chosen_code_replaces_the_automatic_one_and_the_first_along_a_chain_wins(): void {
		$this->ledger->record( $this->company, 'legacy' );
		$this->ledger->record( $this->about, 'company/old-about' );
		$this->ledger->set_target( 'page', 'company/old-about', $this->about, '', 301 );

		// Round 1: legacy → company, so company/old-about/team; round 2: company/old-about → company/about.
		$this->assertSame( $this->team, $this->resolver->resolve( 'page', 'legacy/old-about/team', $temporary, $code )->ID );
		$this->assertSame( 301, $code );

		$this->ledger->set_target( 'page', 'legacy', $this->company, '', 302 );
		$this->assertSame( $this->team, $this->resolver->resolve( 'page', 'legacy/old-about/team', $temporary, $code )->ID );
		$this->assertSame( 302, $code, 'The first record used that has a chosen code decides.' );
		$this->assertFalse( $temporary, 'A chosen code leaves the automatic flag as it was.' );
	}

	public function test_a_root_entry_s_override_is_read_from_its_option(): void {
		$this->ledger->record_root( 'page', 'archive' );
		$this->assertSame( $this->company, $this->resolver->resolve( 'page', 'archive/company' )->ID, 'Precondition: the root entry maps archive/company to company.' );

		$this->ledger->set_target( 'page', 'archive', 0, 'https://example.org/archive/', 302 );

		$this->assertSame( 'https://example.org/archive/', $this->resolver->resolve( 'page', 'archive', $temporary, $code ) );
		$this->assertSame( 302, $code );
		$this->assertNull( $this->resolver->resolve( 'page', 'archive/company' ), 'A URL target covers its exact path only.' );
	}

	public function test_a_url_target_on_a_page_in_the_trash_is_not_served(): void {
		$this->ledger->record( $this->about, 'old-about' );
		$this->ledger->set_target( 'page', 'old-about', $this->about, 'https://example.org/', 0 );
		wp_trash_post( $this->about );

		$this->assertNull( $this->resolver->resolve( 'page', 'old-about' ), 'A record lives with its page: in the trash, nothing answers.' );
	}

	public function test_the_batch_resolver_reads_overrides_as_the_live_one_does(): void {
		$this->ledger->record( $this->company, 'old' );
		$this->ledger->record( $this->about, 'old/about' );
		$this->ledger->set_target( 'page', 'old/about', $this->about, 'https://example.org/about/', 0 );
		$this->ledger->set_target( 'page', 'old', $this->company, '', 302 );
		$this->ledger->record_root( 'page', 'archive' );
		$this->ledger->set_target( 'page', 'archive', 0, 'https://example.org/archive/', 301 );

		$facts     = [];
		$temporary = [];
		$chosen    = [];
		$served    = Batch_Resolver::served( [ 'old/about', 'old/about/team', 'archive', 'archive/company' ], 'page', $facts, $temporary, $chosen );

		ksort( $served );
		ksort( $chosen );
		$this->assertSame(
			[
				'archive'         => Batch_Resolver::URL,
				'archive/company' => 0,
				'old/about'       => Batch_Resolver::URL,
				'old/about/team'  => $this->team,
			],
			$served
		);
		$this->assertSame(
			[
				'archive'        => [
					'url'  => 'https://example.org/archive/',
					'code' => 301,
				],
				'old/about'      => [
					'url'  => 'https://example.org/about/',
					'code' => 0,
				],
				'old/about/team' => [
					'url'  => '',
					'code' => 302,
				],
			],
			$chosen
		);
		foreach ( [ 'old/about', 'old/about/team', 'archive', 'archive/company' ] as $path ) {
			$live = $this->resolver->resolve( 'page', $path, $live_temporary, $live_code );
			$this->assertSame( is_string( $live ) ? $live : ( null === $live ? 0 : $live->ID ), is_string( $live ) ? $chosen[ $path ]['url'] : $served[ $path ], $path );
			$this->assertSame( $live_code, $chosen[ $path ]['code'] ?? 0, $path );
		}
	}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npm run test:php -- --filter ResolverTest`
Expected: FAIL. The new tests fail or error, for example `Failed asserting that WP_Post Object (…) is identical to 'https://example.org/about/'` and `Error: Undefined constant HierarchyGuard\Redirects\Batch_Resolver::URL`.

- [ ] **Step 3: Give the views the override**

In `includes/HierarchyGuard/Redirects/Ledger_View.php`, replace:
```php
	public function find( string $post_type, string $prefix ): ?int;

```
with:
```php
	public function find( string $post_type, string $prefix ): ?int;

	/**
	 * The override on the record find() answered with, as Ledger::target() reads it.
	 *
	 * @param string $post_type Post type name.
	 * @param string $prefix    Normalized path.
	 * @param int    $hit       What find() answered: the holder, or Rules::ROOT.
	 * @return array{url:string,code:int}|null
	 */
	public function target( string $post_type, string $prefix, int $hit ): ?array;

```

In `includes/HierarchyGuard/Redirects/Live_View.php`, replace:
```php
		return $this->ledger->find( $post_type, $prefix );
	}
```
with:
```php
		return $this->ledger->find( $post_type, $prefix );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $post_type Post type name.
	 * @param string $prefix    Normalized path.
	 * @param int    $hit       What find() answered.
	 */
	public function target( string $post_type, string $prefix, int $hit ): ?array {
		return $this->ledger->target( $post_type, $prefix, $hit );
	}
```

In `includes/HierarchyGuard/Redirects/Prefetched_View.php`, make these replacements.

Replace:
```php
	private ?array $roots = null;

```
with:
```php
	private ?array $roots = null;

	/**
	 * The root list's overrides for the view's type, by path, read once when first needed.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $root_targets = null;

```

Replace:
```php
		return isset( $this->roots[ $prefix ] ) ? Rules::ROOT : null;
	}
```
with:
```php
		return isset( $this->roots[ $prefix ] ) ? Rules::ROOT : null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $post_type Post type name.
	 * @param string $prefix    Normalized path.
	 * @param int    $hit       What find() answered.
	 */
	public function target( string $post_type, string $prefix, int $hit ): ?array {
		$this->own( $post_type );
		if ( $hit > 0 ) {
			$fact = $this->fact( $prefix );
			return $fact['live'] === $hit ? Ledger::clean_target( $fact['target'] ?? null ) : null;
		}
		if ( null === $this->root_targets ) {
			$all                = get_option( Ledger::ROOT_TARGETS, [] );
			$this->root_targets = is_array( $all ) && is_array( $all[ $this->post_type ] ?? null ) ? $all[ $this->post_type ] : [];
		}
		return Ledger::clean_target( $this->root_targets[ $prefix ] ?? null );
	}
```

- [ ] **Step 4: Honour URL targets and chosen codes in the rules and the live resolver**

In `includes/HierarchyGuard/Redirects/Rules.php`, make these replacements.

Replace:
```php
	/**
	 * The publicly viewable post an old path now leads to, or null.
	 *
```
with:
```php
	/**
	 * The publicly viewable post an old path now leads to, the URL a record's
	 * URL target sends it to, or null. A record with a URL target answers
	 * its exact old path and ends the rounds. The code chosen on the first
	 * record the rounds use that has one replaces the automatic code.
	 *
```

Replace:
```php
	 * @param array<int,array{0:string,1:int,2:string}>|null $trace  Each round's prefix, holder and rewritten path is added.
	 * @return WP_Post|null
	 */
	public static function resolve( Ledger_View $view, string $post_type, string $path, bool &$temporary, ?array &$trace = null ): ?WP_Post {
		$seen = [];

```
with:
```php
	 * @param array<int,array{0:string,1:int,2:string}>|null $trace  Each round's prefix, holder and rewritten path is added.
	 * @param int|null                                    $code      Set to the chosen 301 or 302, or 0 when the automatic code applies.
	 * @return WP_Post|string|null A post, an http(s) URL from a URL target, or null.
	 */
	public static function resolve( Ledger_View $view, string $post_type, string $path, bool &$temporary, ?array &$trace = null, ?int &$code = null ): WP_Post|string|null {
		$seen = [];
		$code = 0;

```

Replace:
```php

			$candidate = self::rewrite_once( $view, $post_type, $path, $temporary, $trace );
			if ( null === $candidate ) {
				return null;
			}
```
with:
```php

			$override  = null;
			$candidate = self::rewrite_once( $view, $post_type, $path, $temporary, $trace, $override );
			if ( null === $candidate ) {
				return null;
			}
			if ( 0 === $code && null !== $override ) {
				$code = $override['code'];
			}
			if ( null !== $override && '' !== $override['url'] ) {
				return $override['url'];
			}
```

Replace:
```php
	 * an ancestor of that page and recorded the path after it did held it
	 * since, and is redirected to.
	 *
```
with:
```php
	 * an ancestor of that page and recorded the path after it did held it
	 * since, and is redirected to. A record with a URL target answers only
	 * its exact old path: for a longer path it is passed over, so a shorter
	 * prefix can match.
	 *
```

Replace:
```php
	 * @param array<int,array{0:string,1:int,2:string}>|null $trace  The prefix, its holder and the rewritten path are added.
	 * @return string|null The rewritten path, or null when no prefix matched.
	 */
	public static function rewrite_once( Ledger_View $view, string $post_type, string $path, bool &$temporary, ?array &$trace = null ): ?string {
		$segments = explode( '/', $path );

```
with:
```php
	 * @param array<int,array{0:string,1:int,2:string}>|null $trace  The prefix, its holder and the rewritten path are added.
	 * @param array{url:string,code:int}|null             $override  Set to the override on the record used, null for none.
	 * @return string|null The rewritten path, '' for a URL target, or null when no prefix matched.
	 */
	public static function rewrite_once( Ledger_View $view, string $post_type, string $path, bool &$temporary, ?array &$trace = null, ?array &$override = null ): ?string {
		$segments = explode( '/', $path );
		$override = null;

```

Replace:
```php
			if ( null === $hit ) {
				continue;
```
with:
```php
			if ( null === $hit ) {
				continue;
			}

			$exact  = count( $segments ) === $length;
			$target = $view->target( $post_type, $prefix, $hit );
			$url    = null !== $target ? $target['url'] : '';
			if ( '' !== $url && ! $exact ) {
				continue;
```

Replace:
```php
				}
				$base = $view->uri( $post );
				if ( Paths::has_trashed_segment( $base ) ) {
					continue;
				}
				if ( count( $segments ) === $length && self::held_for_trash( $view, $post_type, $prefix, $hit ) ) {
					return null;
```
with:
```php
				}
				if ( '' === $url ) {
					$base = $view->uri( $post );
					if ( Paths::has_trashed_segment( $base ) ) {
						continue;
					}
				}
				if ( $exact && self::held_for_trash( $view, $post_type, $prefix, $hit ) ) {
					return null;
```

Replace:
```php
			$trace[]   = [ $prefix, $hit, $candidate ];
			return $candidate;
```
with:
```php
			$trace[]   = [ $prefix, $hit, $candidate ];
			$override  = $target;
			return $candidate;
```

In `includes/HierarchyGuard/Redirects/Resolver.php`, make these replacements.

Replace:
```php
	/**
	 * The publicly viewable post an old path now leads to, or null.
	 *
```
with:
```php
	/**
	 * The publicly viewable post an old path now leads to, the URL a URL
	 * target sends it to, or null.
	 *
```

Replace:
```php
	 * @param bool|null $temporary Set to true when a restore from the trash could still undo the move the answer follows.
	 * @return WP_Post|null
	 */
	public function resolve( string $post_type, string $path, ?bool &$temporary = null ): ?WP_Post {
		$temporary = false;
		$target    = Rules::resolve( $this->view, $post_type, Paths::normalize( $path ), $temporary );
		if ( null === $target ) {
			return null;
		}
```
with:
```php
	 * @param bool|null $temporary Set to true when a restore from the trash could still undo the move the answer follows.
	 * @param int|null  $code      Set to the 301 or 302 chosen on the Redirects tab, or 0 for the automatic code.
	 * @return WP_Post|string|null A post, an http(s) URL, or null.
	 */
	public function resolve( string $post_type, string $path, ?bool &$temporary = null, ?int &$code = null ): WP_Post|string|null {
		$temporary = false;
		$code      = 0;
		$trace     = null;
		$target    = Rules::resolve( $this->view, $post_type, Paths::normalize( $path ), $temporary, $trace, $code );
		if ( null === $target || is_string( $target ) ) {
			return $target;
		}
```

- [ ] **Step 5: Read overrides ahead and report them from the batch resolver**

In `includes/HierarchyGuard/Redirects/Batch_Resolver.php`, make these replacements.

Replace:
```php
	/**
	 * Most paths several posts share that core's own get_page_by_path() is asked about, per batch.
```
with:
```php
	/**
	 * What served() answers for a path a record's URL target answers: the
	 * URL is in the $chosen it sets.
	 */
	public const URL = -3;

	/**
	 * Most paths several posts share that core's own get_page_by_path() is asked about, per batch.
```

Replace:
```php
	 * server would answer it with a 302: a restore from the trash could still
	 * undo the move the redirect follows.
	 *
```
with:
```php
	 * server would answer it with a 302: a restore from the trash could still
	 * undo the move the redirect follows. A record's override is honoured as
	 * the server honours it: a URL target answers its exact old path with
	 * URL, and a chosen code is set in $chosen.
	 *
```

Replace:
```php
	 * @param string                                  $post_type Post type name.
	 * @param array<string,array{live:int,held:bool,newest:bool,trashed:int[]}> $facts     Ledger facts read so far, by path; more are added, for every path.
	 * @param array<string,true>|null                 $temporary Set to the paths redirected to a post of this type that are temporary.
	 * @return array<string,int> Post ID by path, a post of another type when the server tries that type first; 0 for none, NOT_404 when something is known to be shown there.
	 */
	public static function served( array $paths, string $post_type, array &$facts, ?array &$temporary = null ): array {
		$prefix = Server::prefix( $post_type );
```
with:
```php
	 * @param string                                  $post_type Post type name.
	 * @param array<string,array{live:int,held:bool,newest:bool,trashed:int[],target?:array{url:string,code:int}|null}> $facts     Ledger facts read so far, by path; more are added, for every path.
	 * @param array<string,true>|null                 $temporary Set to the paths redirected by this type's records that are temporary.
	 * @param array<string,array{url:string,code:int}>|null $chosen Set to the URL target and the chosen code of each path this type's records answer with an override.
	 * @return array<string,int> Post ID by path, a post of another type when the server tries that type first; 0 for none, NOT_404 when something is known to be shown there, URL for a URL target.
	 */
	public static function served( array $paths, string $post_type, array &$facts, ?array &$temporary = null, ?array &$chosen = null ): array {
		$prefix = Server::prefix( $post_type );
```

Replace:
```php
		}
		$held  = [];
		$found = self::run( array_map( 'strval', array_keys( $within ) ), $post_type, $facts, true, $held );
		$ours  = $found;

```
with:
```php
		}
		$held    = [];
		$answers = [];
		$found   = self::run( array_map( 'strval', array_keys( $within ) ), $post_type, $facts, true, $held, $answers );
		$ours    = $found;

```

Replace:
```php
		foreach ( $found as $start => $target ) {
			if ( $target < 0 ) {
				continue;
```
with:
```php
		foreach ( $found as $start => $target ) {
			if ( $target < 0 && self::URL !== $target ) {
				continue;
```

Replace:
```php
		$chains = [];
		foreach ( $found as $start => $target ) {
			$out[ (string) $start ] = self::UNKNOWN === $target ? 0 : $target;
			if ( $target > 0 && $target === $ours[ $start ] ) {
				$chains[ (string) $start ] = array_merge( [ $target ], array_map( 'intval', get_post_ancestors( $target ) ) );
			}
		}
		$temporary = self::temporary( $chains, $held );
		return $out;
```
with:
```php
		$chains = [];
		$chosen = [];
		$urls   = [];
		foreach ( $found as $start => $target ) {
			$start         = (string) $start;
			$out[ $start ] = self::UNKNOWN === $target ? 0 : $target;
			if ( $target !== $ours[ $start ] ) {
				continue;
			}
			if ( isset( $answers[ $start ] ) ) {
				$chosen[ $start ] = $answers[ $start ];
			}
			if ( $target > 0 ) {
				$chains[ $start ] = array_merge( [ $target ], array_map( 'intval', get_post_ancestors( $target ) ) );
			} elseif ( self::URL === $target && isset( $held[ $start ] ) ) {
				$urls[ $start ] = true;
			}
		}
		$temporary = self::temporary( $chains, $held ) + $urls;
		return $out;
```

Replace:
```php
	 * @param bool                                    $requests  Whether each path is a request that must be a 404 first.
	 * @param array<string,true>|null                 $held      Set to the paths led to a post through a prefix a page in the trash holds.
	 * @return array<string,int> Post ID by path; 0 for none, NOT_404 when something is shown there, UNKNOWN when it cannot be told.
	 */
	private static function run( array $paths, string $post_type, array &$facts, bool $requests, ?array &$held = null ): array {
		$held    = [];
		$view    = new Prefetched_View( $post_type, $facts );
```
with:
```php
	 * @param bool                                    $requests  Whether each path is a request that must be a 404 first.
	 * @param array<string,true>|null                 $held      Set to the paths led to a post or URL through a prefix a page in the trash holds.
	 * @param array<string,array{url:string,code:int}>|null $answers Set to each answered path's URL target and chosen code, where it has either.
	 * @return array<string,int> Post ID by path; 0 for none, NOT_404 when something is shown there, URL for a URL target, UNKNOWN when it cannot be told.
	 */
	private static function run( array $paths, string $post_type, array &$facts, bool $requests, ?array &$held = null, ?array &$answers = null ): array {
		$held    = [];
		$answers = [];
		$view    = new Prefetched_View( $post_type, $facts );
```

Replace:
```php
				$temporary = false;
				$view->begin();
```
with:
```php
				$temporary = false;
				$code      = 0;
				$view->begin();
```

Replace:
```php
					// Only a 404 reaches the resolver.
					$target = ! $requests || $view->is_404( $start, $post_type ) ? Rules::resolve( $view, $post_type, $start, $temporary, $trace ) : null;
					if ( null !== $target && ! $view->guessed() ) {
						$out[ $start ] = (int) $target->ID;
						if ( $temporary ) {
```
with:
```php
					// Only a 404 reaches the resolver.
					$target = ! $requests || $view->is_404( $start, $post_type ) ? Rules::resolve( $view, $post_type, $start, $temporary, $trace, $code ) : null;
					if ( null !== $target && ! $view->guessed() ) {
						$url           = is_string( $target ) ? $target : '';
						$out[ $start ] = '' !== $url ? self::URL : (int) $target->ID;
						if ( '' !== $url || $code > 0 ) {
							$answers[ $start ] = [
								'url'  => $url,
								'code' => $code,
							];
						}
						if ( $temporary ) {
```

Replace:
```php
	 * What the ledger says about each path, the way Ledger::find(),
	 * Ledger::held_in_trash() and Ledger::newest_in_trash() read it: the
	 * newest post of the type not in the trash that holds it, whether a post
	 * in the trash holds it, whether the newest entry is one, and which posts
	 * in the trash hold it. One query.
	 *
```
with:
```php
	 * What the ledger says about each path, the way Ledger::find(),
	 * Ledger::held_in_trash(), Ledger::newest_in_trash() and
	 * Ledger::target() read it: the newest post of the type not in the trash
	 * that holds it, whether a post in the trash holds it, whether the newest
	 * entry is one, which posts in the trash hold it, and the override beside
	 * the live holder's record. One query.
	 *
```

Replace:
```php
	 * @param string   $post_type Post type name.
	 * @return array<string,array{live:int,held:bool,newest:bool,trashed:int[]}> Facts by path, for every path asked about.
	 */
```
with:
```php
	 * @param string   $post_type Post type name.
	 * @return array<string,array{live:int,held:bool,newest:bool,trashed:int[],target:array{url:string,code:int}|null}> Facts by path, for every path asked about.
	 */
```

Replace:
```php

		$out  = [];
		$keys = [];
		foreach ( $paths as $path ) {
			$out[ (string) $path ]                             = [
				'live'    => 0,
```
with:
```php

		$out     = [];
		$keys    = [];
		$targets = [];
		foreach ( $paths as $path ) {
			$out[ (string) $path ]                                       = [
				'live'    => 0,
```

Replace:
```php
				'trashed' => [],
			];
			$keys[ Ledger::key( $post_type, (string) $path ) ] = (string) $path;
		}
```
with:
```php
				'trashed' => [],
				'target'  => null,
			];
			$keys[ Ledger::key( $post_type, (string) $path ) ]           = (string) $path;
			$targets[ Ledger::target_key( $post_type, (string) $path ) ] = (string) $path;
		}
```

Replace:
```php
		}
		$placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $placeholders holds only %s, one per path listed or prefix of one, bounded by the count cap; a range on the meta_key index.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT pm.meta_key, pm.post_id, p.post_status FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key IN ( {$placeholders} ) AND p.post_type = %s ORDER BY pm.meta_id DESC", ...array_merge( array_keys( $keys ), [ $post_type ] ) ), ARRAY_A );

		$seen = [];
		foreach ( (array) $rows as $row ) {
			$path = $keys[ (string) $row['meta_key'] ] ?? '';
```
with:
```php
		}
		$placeholders = implode( ',', array_fill( 0, 2 * count( $keys ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $placeholders holds only %s, two per path listed or prefix of one (its entry and its override), bounded by the count cap; a range on the meta_key index.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT pm.meta_key, pm.post_id, pm.meta_value, p.post_status FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key IN ( {$placeholders} ) AND p.post_type = %s ORDER BY pm.meta_id DESC", ...array_merge( array_keys( $keys ), array_keys( $targets ), [ $post_type ] ) ), ARRAY_A );

		$seen      = [];
		$overrides = [];
		foreach ( (array) $rows as $row ) {
			if ( isset( $targets[ (string) $row['meta_key'] ] ) ) {
				$overrides[ $targets[ (string) $row['meta_key'] ] ][ (int) $row['post_id'] ] = maybe_unserialize( (string) $row['meta_value'] );
				continue;
			}
			$path = $keys[ (string) $row['meta_key'] ] ?? '';
```

Replace:
```php
				$out[ $path ]['live'] = (int) $row['post_id'];
			}
```
with:
```php
				$out[ $path ]['live'] = (int) $row['post_id'];
			}
		}
		foreach ( $overrides as $path => $by_holder ) {
			$live = $out[ $path ]['live'];
			if ( $live > 0 && isset( $by_holder[ $live ] ) ) {
				$out[ $path ]['target'] = Ledger::clean_target( $by_holder[ $live ] );
			}
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `npm run test:php -- --filter 'ResolverTest|PairsTest|PairsFuzzTest|BatchResolverTest'`
Expected: PASS. The pairs and fuzz tests still pass: with no overrides stored, every answer is what it was.

Run: `npm run test:php` then `composer lint`
Expected: the whole suite passes, with the new tests counted; lint exits 0. Run `composer lint:fix` first if it reports alignment; formatting-only changes are not deviations.

- [ ] **Step 7: Commit**

```bash
git add includes/HierarchyGuard/Redirects tests/phpunit/ResolverTest.php
git commit -m "feat: resolve old paths through a record's URL target and chosen code"
```

### Task 3: The server sends URL targets and chosen codes

**Files:**
- Modify: `includes/HierarchyGuard/Redirects/Server.php` (`maybe_redirect()` comment, `target_for_current_request()`, new private `external()`, `append_query_string()` puts the query before a `#fragment`)
- Test: `tests/phpunit/ServerTest.php`

**Interfaces:**
- Consumes: `Resolver::resolve( $type, $path, $temporary, $code ): WP_Post|string|null` (Task 2).
- Produces: `Server::target_for_current_request(): ?array{url:string,status:int}` as before, now also for URL targets: the URL as set (no suffixes re-applied), with the request's query string, through `esc_url_raw( $url, [ 'http', 'https' ] )` and sent only while http(s), never through `wp_validate_redirect()`. The status is the chosen code when there is one, else 301 or 302 as before; `hierarchy_guard_redirect_status` and `hierarchy_guard_redirect_url` get `null` for the post of a URL target. The loop guard compares paths only for a URL on this site's host. The request's query string goes before a URL target's `#fragment` (`…/landing/?utm_source=news#section`), so the browser still sends it.
- Behaviour kept: GET and HEAD only, 404 only, serving switch, plain permalinks inert, segment cap, page targets through `wp_validate_redirect()`.

- [ ] **Step 1: Write the failing tests**

In `tests/phpunit/ServerTest.php`, replace:
```php
		delete_option( Ledger::ROOT_OPTION );
		update_option( Settings::OPTION, [ 'serve_redirects' => true ] );
```
with:
```php
		delete_option( Ledger::ROOT_OPTION );
		delete_option( Ledger::ROOT_TARGETS );
		update_option( Settings::OPTION, [ 'serve_redirects' => true ] );
```

Append to the class in `tests/phpunit/ServerTest.php`:
```php
	public function test_a_url_target_is_sent_for_its_exact_old_url_only(): void {
		$ledger = new Ledger();
		$ledger->record( $this->company, 'old/sub' );
		$ledger->record( $this->about, 'old/sub/about' );
		$ledger->set_target( 'page', 'old/sub/about', $this->about, 'https://jobs.example.org/about/', 0 );

		$this->go_to( '/old/sub/about/?ref=mail' );
		$this->assertTrue( is_404() );
		$this->assertSame(
			[
				'url'    => 'https://jobs.example.org/about/?ref=mail',
				'status' => 301,
			],
			$this->server->target_for_current_request(),
			'Another host, through wp_redirect(): wp_validate_redirect() would refuse it.'
		);

		$this->go_to( '/old/sub/about/feed/' );
		$this->assertTrue( is_404() );
		$this->assertSame( 'https://jobs.example.org/about/', $this->server->target_for_current_request()['url'], 'Suffixes are re-applied to pages only.' );

		$this->go_to( '/old/sub/about/team/' );
		$this->assertSame( get_permalink( $this->team ), $this->server->target_for_current_request()['url'], 'A longer path goes past the URL target to the shorter prefix.' );
	}

	public function test_a_chosen_code_is_sent_as_chosen(): void {
		$ledger = new Ledger();
		$ledger->record( $this->about, 'old-about' );
		$ledger->set_target( 'page', 'old-about', $this->about, '', 302 );

		$this->go_to( '/old-about/team/' );
		$this->assertSame(
			[
				'url'    => get_permalink( $this->team ),
				'status' => 302,
			],
			$this->server->target_for_current_request()
		);

		$ledger->set_target( 'page', 'old-about', $this->about, '', 301 );
		$this->go_to( '/old-about/team/' );
		$this->assertSame( 301, $this->server->target_for_current_request()['status'] );
	}

	public function test_a_url_target_that_is_not_http_is_never_sent(): void {
		$ledger = new Ledger();
		$ledger->record( $this->about, 'old-about' );
		$ledger->set_target( 'page', 'old-about', $this->about, 'https://example.org/', 0 );
		$this->go_to( '/old-about/' );

		$scheme = static fn(): string => 'javascript:alert(1)';
		add_filter( 'hierarchy_guard_redirect_url', $scheme );
		$this->assertNull( $this->server->target_for_current_request() );
		remove_filter( 'hierarchy_guard_redirect_url', $scheme );

		$passed = null;
		$spy    = static function ( int $status, $target ) use ( &$passed ): int {
			$passed = $target;
			return $status;
		};
		add_filter( 'hierarchy_guard_redirect_status', $spy, 10, 2 );
		$this->assertSame( 'https://example.org/', $this->server->target_for_current_request()['url'] );
		remove_filter( 'hierarchy_guard_redirect_status', $spy );
		$this->assertNull( $passed, 'The filters get no post for a URL target.' );
	}

	public function test_a_url_target_on_this_site_at_the_requested_path_is_not_sent(): void {
		$ledger = new Ledger();
		$ledger->record( $this->about, 'old-about' );
		$ledger->set_target( 'page', 'old-about', $this->about, home_url( '/old-about/' ), 0 );
		$this->go_to( '/old-about/' );

		$this->assertNull( $this->server->target_for_current_request(), 'That would loop.' );

		$ledger->set_target( 'page', 'old-about', $this->about, 'https://other.example.org/old-about/', 0 );
		$this->assertSame( 'https://other.example.org/old-about/', $this->server->target_for_current_request()['url'], 'The same path on another host does not.' );
	}

	public function test_a_live_page_at_the_old_url_wins_over_a_url_target(): void {
		$ledger = new Ledger();
		$ledger->record( $this->about, 'news' );
		$ledger->set_target( 'page', 'news', $this->about, 'https://example.org/', 302 );
		self::factory()->post->create(
			[
				'post_type' => 'page',
				'post_name' => 'news',
			]
		);

		$this->go_to( '/news/' );
		$this->assertFalse( is_404() );
		$this->assertNull( $this->server->target_for_current_request() );
	}

	public function test_the_query_string_goes_before_a_url_target_s_fragment(): void {
		$ledger = new Ledger();
		$ledger->record( $this->about, 'old-about' );
		$ledger->set_target( 'page', 'old-about', $this->about, 'https://example.org/landing/#section', 0 );
		$this->go_to( '/old-about/?utm_source=news' );
		$this->assertSame( 'https://example.org/landing/?utm_source=news#section', $this->server->target_for_current_request()['url'] );

		$ledger->set_target( 'page', 'old-about', $this->about, 'https://example.org/landing/?ref=hg#section', 0 );
		$this->assertSame( 'https://example.org/landing/?ref=hg&utm_source=news#section', $this->server->target_for_current_request()['url'] );
	}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npm run test:php -- --filter ServerTest`
Expected: FAIL. The new tests fail, with `Failed asserting that null is identical to Array` and `Trying to access array offset on value of type null`; the live-page test passes already.

- [ ] **Step 3: Send URL targets and chosen codes**

In `includes/HierarchyGuard/Redirects/Server.php`, make these replacements.

Replace:
```php

		// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- URL passed through wp_validate_redirect() in target_for_current_request().
		if ( wp_redirect( $target['url'], $target['status'], 'Hierarchy Guard' ) ) {
```
with:
```php

		// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- A page target passed wp_validate_redirect() in target_for_current_request(); a URL target, which only an administrator can set, passed esc_url_raw() as http(s).
		if ( wp_redirect( $target['url'], $target['status'], 'Hierarchy Guard' ) ) {
```

Replace:
```php
		$temporary = false;
		foreach ( self::candidates( $request['path'] ) as [ $post_type, $path ] ) {
			$target = $this->resolver->resolve( $post_type, $path, $temporary );
			if ( null !== $target ) {
```
with:
```php
		$temporary = false;
		$code      = 0;
		foreach ( self::candidates( $request['path'] ) as [ $post_type, $path ] ) {
			$target = $this->resolver->resolve( $post_type, $path, $temporary, $code );
			if ( null !== $target ) {
```

Replace:
```php

		$url = get_permalink( $target );
		if ( ! is_string( $url ) || '' === $url ) {
			return null;
		}
		$url = $this->reapply_suffixes( $url, $target, $request );
		$url = $this->append_query_string( $url );
```
with:
```php

		// A URL target is sent as it was set: the suffixes are re-applied to a page only.
		$post = $target instanceof WP_Post ? $target : null;
		if ( null !== $post ) {
			$url = get_permalink( $post );
			if ( ! is_string( $url ) || '' === $url ) {
				return null;
			}
			$url = $this->reapply_suffixes( $url, $post, $request );
		} else {
			$url = (string) $target;
		}
		$url = $this->append_query_string( $url );
```

Replace:
```php
		 *
		 * @param int     $status Status code: 301, or 302 while a restore from the trash could still undo the move the redirect follows.
		 * @param WP_Post $target The post being redirected to.
		 * @param string  $path   The requested path, normalized, suffixes removed.
		 */
		$status = (int) apply_filters( 'hierarchy_guard_redirect_status', $temporary ? 302 : 301, $target, $request['path'] );
		if ( ! in_array( $status, [ 301, 302, 307, 308 ], true ) ) {
```
with:
```php
		 *
		 * @param int          $status Status code: the 301 or 302 chosen on the Redirects tab, or else 301, or 302 while a restore from the trash could still undo the move the redirect follows.
		 * @param WP_Post|null $target The post being redirected to, null for a URL target.
		 * @param string       $path   The requested path, normalized, suffixes removed.
		 */
		$status = (int) apply_filters( 'hierarchy_guard_redirect_status', $code > 0 ? $code : ( $temporary ? 302 : 301 ), $post, $request['path'] );
		if ( ! in_array( $status, [ 301, 302, 307, 308 ], true ) ) {
```

Replace:
```php
		/**
		 * Filters the URL a hierarchy redirect sends visitors to.
		 *
		 * @param string  $url    Target URL, with the request's query string appended.
		 * @param WP_Post $target The post being redirected to.
		 * @param string  $path   The requested path, normalized, suffixes removed.
		 */
		$url = (string) apply_filters( 'hierarchy_guard_redirect_url', $url, $target, $request['path'] );
		$url = wp_validate_redirect( $url, '' );
		if ( '' === $url ) {
```
with:
```php
		/**
		 * Filters the URL a hierarchy redirect sends visitors to. A page
		 * target's URL still passes wp_validate_redirect(); a URL target's is
		 * sent only while it is http(s).
		 *
		 * @param string       $url    Target URL, with the request's query string appended.
		 * @param WP_Post|null $target The post being redirected to, null for a URL target.
		 * @param string       $path   The requested path, normalized, suffixes removed.
		 */
		$url = (string) apply_filters( 'hierarchy_guard_redirect_url', $url, $post, $request['path'] );
		$url = null !== $post ? wp_validate_redirect( $url, '' ) : self::external( $url );
		if ( '' === $url ) {
```

Replace:
```php
		// while the target keeps it. The query string is carried over, so it is
		// left out of the comparison.
		$requested = untrailingslashit( (string) wp_parse_url( $this->request_uri(), PHP_URL_PATH ) );
		if ( untrailingslashit( (string) wp_parse_url( $url, PHP_URL_PATH ) ) === $requested ) {
			return null;
```
with:
```php
		// while the target keeps it. The query string is carried over, so it is
		// left out of the comparison. A URL target on another host never loops.
		$requested = untrailingslashit( (string) wp_parse_url( $this->request_uri(), PHP_URL_PATH ) );
		$here      = null !== $post || strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) === strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( $here && untrailingslashit( (string) wp_parse_url( $url, PHP_URL_PATH ) ) === $requested ) {
			return null;
```

Replace:
```php
		];
	}
```
with:
```php
		];
	}

	/**
	 * A URL target as it is sent: through esc_url_raw(), and only while it is http(s).
	 *
	 * @param string $url URL.
	 * @return string The URL, or '' when it is not http(s).
	 */
	private static function external( string $url ): string {
		$url = esc_url_raw( $url, [ 'http', 'https' ] );
		return in_array( strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ), [ 'http', 'https' ], true ) ? $url : '';
	}
```

Replace:
```php
	/**
	 * Carries the current request's query string over to the target URL. The
	 * query is read from the request URI, as redirect_canonical() does, because
	 * some servers rewrite QUERY_STRING (nginx's `/index.php?q=$uri&$args`).
	 *
```
with:
```php
	/**
	 * Carries the current request's query string over to the target URL,
	 * before any #fragment a URL target has. The query is read from the
	 * request URI, as redirect_canonical() does, because some servers
	 * rewrite QUERY_STRING (nginx's `/index.php?q=$uri&$args`).
	 *
```

Replace:
```php
		}
		return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . $query;
	}
```
with:
```php
		}
		// A URL target may carry a #fragment: the query goes before it, or the browser never sends it.
		$parts    = explode( '#', $url, 2 );
		$parts[0] = $parts[0] . ( str_contains( $parts[0], '?' ) ? '&' : '?' ) . $query;
		return implode( '#', $parts );
	}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `npm run test:php -- --filter ServerTest`
Expected: PASS (27 tests).

Run: `npm run test:php` then `composer lint`
Expected: the whole suite passes, with the new tests counted; lint exits 0. Run `composer lint:fix` first if it reports alignment; formatting-only changes are not deviations.

- [ ] **Step 5: Commit**

```bash
git add includes/HierarchyGuard/Redirects/Server.php tests/phpunit/ServerTest.php
git commit -m "feat: send a URL target through wp_redirect() and honour a chosen code"
```

### Task 4: A change's redirect list lists what the server sends

**Files:**
- Modify: `includes/HierarchyGuard/Redirects/Pairs.php` (class docblock, `collect_change()` and `collect()` return docs, `served_only()`, `cached()`, `urls()`)
- Test: `tests/phpunit/PairsTest.php`

**Interfaces:**
- Consumes: `Batch_Resolver::served( …, $temporary, $chosen )` and `Batch_Resolver::URL` (Task 2).
- Produces: collected pairs `array{old:string,id:int,temporary:bool,url:string}`: `url` is the URL target the pair's record sends it to, '' for a page; `temporary` is the chosen code's (302) when one was chosen, else the automatic flag as before. A pair the server answers through a URL target is kept; its subpages' old URLs, which the URL target no longer covers, drop out unless a shorter prefix still leads them to their pages.
- Produces: `Pairs::page()` and `Pairs::all()` rows keep their shape `{old,new,temporary}`: `new` is the URL target, relative to the home URL when it is on this host and absolute otherwise. The CSV writes 302 or 301 from `temporary` as before.
- Produces: the pairs cache key also carries `wp_cache_get_last_changed( Ledger::CACHE_GROUP )`, so a root-list edit, which writes no post, is seen at once.

- [ ] **Step 1: Write the failing tests**

In `tests/phpunit/PairsTest.php`, replace:
```php
		delete_option( Ledger::ROOT_OPTION );
		Post_Types::reset();
```
with:
```php
		delete_option( Ledger::ROOT_OPTION );
		delete_option( Ledger::ROOT_TARGETS );
		Post_Types::reset();
```

Append to the class in `tests/phpunit/PairsTest.php`:
```php
	public function test_a_record_s_url_target_is_listed_for_its_exact_old_url_as_the_server_sends_it(): void {
		$change = $this->rename( $this->ids['about'], 'about-us' );
		( new Ledger() )->set_target( 'page', 'company/about', $this->ids['about'], 'https://example.org/about/', 0 );

		$collected = $this->pairs->collect_change( $change );

		$this->assertSame( [ '/company/about/ -> https://example.org/about/' ], $this->lines( $collected ), 'Only the exact old URL redirects; the subpages’ old URLs no longer do.' );
		$this->assertSame( [ '/company/about/ -> https://example.org/about/' => [ 301, 301 ] ], $this->statuses( $collected ) );
		$this->assertSame( 'https://example.org/about/', $this->target_on_request( 'company/about' )['url'] );
		$this->assertNull( $this->target_on_request( 'company/about/team' ) );

		( new Ledger() )->set_target( 'page', 'company/about', $this->ids['about'], home_url( '/careers/' ), 0 );
		$this->assertSame( [ '/company/about/ -> /careers/' ], $this->lines( $this->pairs->collect_change( $change ) ), 'A URL on this site is relative, like a page’s.' );
	}

	public function test_a_chosen_code_is_the_code_listed_and_the_code_served(): void {
		$change = $this->rename( $this->ids['about'], 'about-us' );
		( new Ledger() )->set_target( 'page', 'company/about', $this->ids['about'], '', 302 );

		$this->assertSame(
			[
				'/company/about/ -> /company/about-us/' => [ 302, 302 ],
				'/company/about/history/ -> /company/about-us/history/' => [ 302, 302 ],
				'/company/about/team/ -> /company/about-us/team/' => [ 302, 302 ],
				'/company/about/team/leadership/ -> /company/about-us/team/leadership/' => [ 302, 302 ],
			],
			$this->statuses( $this->pairs->collect_change( $change ) )
		);
	}

	public function test_an_edit_to_a_root_entry_shows_in_a_list_already_cached(): void {
		$services = $this->page( 'services' );
		$this->page( 'web', $services );
		wp_trash_post( $services );
		$change = $this->log->latest( $this->admin );
		$this->assertSame( [ '/services/web/ -> /web/' => [ 302, 302 ] ], $this->statuses( $this->pairs->collect_change( $change ) ), 'Precondition: a restore could undo it, and the list is cached now.' );

		( new Ledger() )->set_target( 'page', 'services', 0, '', 301 );

		$this->assertSame( [ '/services/web/ -> /web/' => [ 301, 301 ] ], $this->statuses( $this->pairs->collect_change( $change ) ), 'A root-list edit writes no post, so the cache key has the ledger’s.' );
	}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npm run test:php -- --filter PairsTest`
Expected: FAIL. The three new tests fail with `Failed asserting that two arrays are identical.`

- [ ] **Step 3: List URL targets and chosen codes**

In `includes/HierarchyGuard/Redirects/Pairs.php`, make these replacements.

Replace:
```php
 * rewrite rules as Rules::is_404() reads them, and the resolver's rules must
 * lead to the pair's page. A pair the model cannot vouch for is left out.
 * Nothing is stored: the collected pairs are cached for a few minutes, keyed
 * on the posts' last change.
 */
```
with:
```php
 * rewrite rules as Rules::is_404() reads them, and the resolver's rules must
 * lead to the pair's page, or to the URL target an administrator set on its
 * record, with the code chosen there. A pair the model cannot vouch for is
 * left out. Nothing is stored: the collected pairs are cached for a few
 * minutes, keyed on the posts' and the ledger's last change.
 */
```

Replace:
```php
	 * @param array<string,mixed> $change A change from Change_Log.
	 * @return array{post_type:string,pairs:array<int,array{old:string,id:int,temporary:bool}>,unresolved:array<int,array{old:string,reason:string}>,capped:bool,gone:bool}
	 */
```
with:
```php
	 * @param array<string,mixed> $change A change from Change_Log.
	 * @return array{post_type:string,pairs:array<int,array{old:string,id:int,temporary:bool,url:string}>,unresolved:array<int,array{old:string,reason:string}>,capped:bool,gone:bool}
	 */
```

Replace:
```php
	 * @param string                         $post_type Post type name.
	 * @return array{post_type:string,pairs:array<int,array{old:string,id:int,temporary:bool}>,unresolved:array<int,array{old:string,reason:string}>,capped:bool}
	 */
```
with:
```php
	 * @param string                         $post_type Post type name.
	 * @return array{post_type:string,pairs:array<int,array{old:string,id:int,temporary:bool,url:string}>,unresolved:array<int,array{old:string,reason:string}>,capped:bool}
	 */
```

Replace:
```php
	 * page that held it sits under the trash. A pair the server answers with a
	 * 302, as a restore from the trash could still undo it, is temporary.
	 *
```
with:
```php
	 * page that held it sits under the trash. A pair the server answers with a
	 * 302, as a restore from the trash could still undo it, is temporary. A
	 * pair whose record has a URL target is listed with that URL, and a code
	 * chosen on its record decides whether it is temporary.
	 *
```

Replace:
```php
	 * @param array<int,array{old:string,reason:string}> $unresolved Unresolved rows; one is added per old path with no new URL.
	 * @return array<int,array{old:string,id:int,temporary:bool}>
	 */
```
with:
```php
	 * @param array<int,array{old:string,reason:string}> $unresolved Unresolved rows; one is added per old path with no new URL.
	 * @return array<int,array{old:string,id:int,temporary:bool,url:string}>
	 */
```

Replace:
```php
		$temporary = [];
		$served    = Batch_Resolver::served( array_values( array_unique( array_merge( array_column( $left, 'old' ), array_values( $heads ) ) ) ), $post_type, $facts, $temporary );
		$reason    = static fn( int $target, bool $trashed ): string => 0 !== $target ? 'in_use' : ( $trashed ? 'trash' : 'none' );
```
with:
```php
		$temporary = [];
		$chosen    = [];
		$served    = Batch_Resolver::served( array_values( array_unique( array_merge( array_column( $left, 'old' ), array_values( $heads ) ) ) ), $post_type, $facts, $temporary, $chosen );
		$reason    = static fn( int $target, bool $trashed ): string => 0 !== $target ? 'in_use' : ( $trashed ? 'trash' : 'none' );
```

Replace:
```php
			$target = $served[ $pair['old'] ] ?? 0;
			if ( $target === $pair['id'] ) {
				continue;
```
with:
```php
			$target = $served[ $pair['old'] ] ?? 0;
			if ( $target === $pair['id'] || Batch_Resolver::URL === $target ) {
				continue;
```

Replace:
```php
			array_map(
				static fn( array $pair ): array => $pair + [ 'temporary' => isset( $temporary[ $pair['old'] ] ) ],
				array_diff_key( $pairs, $dropped )
```
with:
```php
			array_map(
				static function ( array $pair ) use ( $temporary, $chosen ): array {
					$code = $chosen[ $pair['old'] ]['code'] ?? 0;
					return $pair + [
						'temporary' => $code > 0 ? 302 === $code : isset( $temporary[ $pair['old'] ] ),
						'url'       => $chosen[ $pair['old'] ]['url'] ?? '',
					];
				},
				array_diff_key( $pairs, $dropped )
```

Replace:
```php
	private function cached( string $key, callable $build ): array {
		// The pairs depend on the permalink structure, which a change of it does not bump.
		$key = 'pairs:' . $key . ':' . md5( (string) get_option( 'permalink_structure' ) ) . ':' . wp_cache_get_last_changed( 'posts' );
		$hit = wp_cache_get( $key, Ledger::CACHE_GROUP );
```
with:
```php
	private function cached( string $key, callable $build ): array {
		// The pairs depend on the permalink structure, which a change of it does not bump, and on the ledger's overrides.
		$key = 'pairs:' . $key . ':' . md5( (string) get_option( 'permalink_structure' ) ) . ':' . wp_cache_get_last_changed( 'posts' ) . ':' . wp_cache_get_last_changed( Ledger::CACHE_GROUP );
		$hit = wp_cache_get( $key, Ledger::CACHE_GROUP );
```

Replace:
```php
	 * Old and new URLs for pairs, priming the posts first, each saying
	 * whether the server answers it with a 302.
	 *
	 * @param array<int,array{old:string,id:int,temporary?:bool}> $pairs Pairs.
	 * @param string                                              $type  Post type name.
	 * @return array<int,array{old:string,new:string,temporary:bool}>
```
with:
```php
	 * Old and new URLs for pairs, priming the posts first, each saying
	 * whether the server answers it with a 302. A URL target on this site is
	 * relative to the home URL like a page's; one on another host stays absolute.
	 *
	 * @param array<int,array{old:string,id:int,temporary?:bool,url?:string}> $pairs Pairs.
	 * @param string                                                         $type  Post type name.
	 * @return array<int,array{old:string,new:string,temporary:bool}>
```

Replace:
```php

		$out = [];
		foreach ( $pairs as $pair ) {
			$old = Paths::url( $type, $pair['old'] );
			$new = get_permalink( $pair['id'] );
			if ( '' === $old || ! is_string( $new ) || '' === $new ) {
```
with:
```php

		$home = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$out  = [];
		foreach ( $pairs as $pair ) {
			$old = Paths::url( $type, $pair['old'] );
			$url = (string) ( $pair['url'] ?? '' );
			$new = '' !== $url ? $url : get_permalink( $pair['id'] );
			if ( '' === $old || ! is_string( $new ) || '' === $new ) {
```

Replace:
```php
				'old'       => Paths::relative( $old ),
				'new'       => Paths::relative( $new ),
				'temporary' => ! empty( $pair['temporary'] ),
```
with:
```php
				'old'       => Paths::relative( $old ),
				'new'       => '' === $url || strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) === $home ? Paths::relative( $new ) : $url,
				'temporary' => ! empty( $pair['temporary'] ),
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `npm run test:php -- --filter 'PairsTest|CsvTest|ChangesControllerTest|NoticesTest'`
Expected: PASS.

Run: `npm run test:php` then `composer lint`
Expected: the whole suite passes, with the new tests counted; lint exits 0. Run `composer lint:fix` first if it reports alignment; formatting-only changes are not deviations.

- [ ] **Step 5: Commit**

```bash
git add includes/HierarchyGuard/Redirects/Pairs.php tests/phpunit/PairsTest.php
git commit -m "feat: list a change's redirects with the overrides the server honours"
```

### Task 5: The recorded redirects as records: list, find, retarget, delete

**Files:**
- Create: `includes/HierarchyGuard/Redirects/Records.php`
- Test: `tests/phpunit/RecordsTest.php`

**Interfaces:**
- Consumes: `Ledger::record()`, `forget()`, `forget_root()`, `target()`, `set_target()`, `clean_target()`, `root_paths()`, `key()`, `target_key()`, `KEY_PREFIX`; `Batch_Resolver::owners()`, `prime_with_ancestors()`; `Server::prefix()`; `Subtree::count_cap()`; `Paths::url()`, `relative()`, `normalize()`, `uri()`, `has_trashed_segment()`.
- Produces: a record, `array{id:string,holder:int,post_type:string,path:string,old:string,target:array{url:string,code:int}|null}`. `holder` is the post holding the `_hierarchy_guard_path_{md5}` row, 0 for a root-list entry; `path` is the stored, normalized path; `old` is the old URL relative to the home URL, percent-decoded for reading (`/café/`); `target` is the override or null.
- Produces: `Records::id( int $holder, string $post_type, string $path ): string` = `"{holder}.{md5(type:path)}"`; `Records::find( string $id ): ?array` accepts only `^\d{1,20}\.[0-9a-f]{32}$` naming an existing record of a supported type whose hash is of its holder's own type.
- Produces: `Records::page( string $post_type, string $search, int $page ): array{rows:array,total:int,page:int,capped:bool}`: `PER_PAGE` (20) rows sorted by old URL (then type, then holder) across the newest `Subtree::count_cap()` post entries of the listed types (`Ledger::recent()`) plus the root list; search is a case-insensitive substring of the old URL, and a full URL is matched as its path; `page` is the page shown, the last one when the request asks for one past it; `capped` when older post entries exist. A search also finds, by its hashed key, any entry at the exact old URL or path it names, whatever the newest list holds.
- Produces: `Records::statuses( array $rows ): array<string,string>` by ID, one of `STATUS_IN_USE`, `STATUS_UNPUBLISHED`, `STATUS_OFF`, `STATUS_ACTIVE`, the first that applies.
- Produces: `Records::target_url( array $record ): string`, the URL the Edit view offers ('' for a top-level root-list entry); `Records::is_top_level( array $record ): bool` (a root-list entry with no URL target); `Records::save( array $record, string $url, int $code ): true|WP_Error` with codes `hierarchy_guard_no_url`, `hierarchy_guard_not_http`, `hierarchy_guard_same_path`, `hierarchy_guard_not_saved`; `Records::delete( array $record ): bool`.
- Save reads a value starting with one `/` as a path on this site (`home_url( $path )`); `//host` and a value with no typed `http(s)` scheme are refused. An empty field saves only the code for a top-level entry and is refused otherwise.
- Bounds: one option read for the newest list, then one query of `meta_id IN (…)` (at most `count_cap()` primary-key lookups) with `STRAIGHT_JOIN` to posts on their primary key, no derived or temporary table; one query for a page's overrides; one per type for the pages at the old URLs; one hashed seek per search. Nothing reads every record.

- [ ] **Step 1: Write the failing tests**

Create `tests/phpunit/RecordsTest.php`:
```php
<?php
/**
 * The recorded redirects the Redirects tab lists and edits.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Ledger;
use HierarchyGuard\Post_Types;
use HierarchyGuard\Redirects\Records;
use HierarchyGuard\Redirects\Resolver;
use HierarchyGuard\Settings;
use HierarchyGuard\Trash\Markers;
use WP_Error;
use WP_UnitTestCase;

/**
 * @covers \HierarchyGuard\Redirects\Records
 */
final class RecordsTest extends WP_UnitTestCase {

	use Trash_Fixtures;

	private Ledger $ledger;

	private Records $records;

	/**
	 * Page IDs.
	 *
	 * @var array<string,int>
	 */
	private array $ids;

	public function set_up(): void {
		parent::set_up();
		delete_option( Settings::OPTION );
		delete_option( Ledger::ROOT_OPTION );
		delete_option( Ledger::ROOT_TARGETS );
		delete_option( Ledger::RECENT );
		Post_Types::reset();
		$this->set_permalink_structure( '/%postname%/' );
		// Trashing a holder in these tests leaves its subpages alone.
		add_filter( 'hierarchy_guard_trash_policy', static fn(): string => Settings::POLICY_KEEP );
		$this->ledger  = new Ledger();
		$this->records = new Records( $this->ledger );
		$this->ids     = $this->make_tree();
	}

	public function tear_down(): void {
		unregister_post_type( 'hg_docs' );
		Post_Types::reset();
		$this->set_permalink_structure( '' );
		parent::tear_down();
	}

	/**
	 * The IDs and old URLs of a page of records.
	 *
	 * @param array<string,mixed> $page From Records::page().
	 * @return array<string,string> Old URL by record ID.
	 */
	private static function olds( array $page ): array {
		return array_column( $page['rows'], 'old', 'id' );
	}

	/**
	 * Registers a hierarchical type with a rewrite prefix.
	 */
	private function register_docs(): void {
		register_post_type(
			'hg_docs',
			[
				'public'       => true,
				'hierarchical' => true,
				'rewrite'      => [ 'slug' => 'docs' ],
			]
		);
		Post_Types::reset();
	}

	public function test_each_entry_and_each_root_entry_is_one_row_and_nothing_else_is(): void {
		$this->ledger->record( $this->ids['about'], 'old-about' );
		$this->ledger->record( $this->ids['about'], 'company/info' );
		$this->ledger->record( $this->ids['team'], 'crew' );
		$this->ledger->record_root( 'page', 'archive' );
		$this->ledger->set_target( 'page', 'crew', $this->ids['team'], 'https://example.org/crew/', 302 );
		// What else the plugin stores on posts.
		add_post_meta( $this->ids['team'], Markers::moved_key( $this->ids['about'] ), 'x' );
		add_post_meta( $this->ids['team'], Markers::ALIASES, 'x' );

		$page = $this->records->page( '', '', 1 );

		$this->assertSame(
			[
				Records::id( 0, 'page', 'archive' ) => '/archive/',
				Records::id( $this->ids['about'], 'page', 'company/info' ) => '/company/info/',
				Records::id( $this->ids['team'], 'page', 'crew' ) => '/crew/',
				Records::id( $this->ids['about'], 'page', 'old-about' ) => '/old-about/',
			],
			self::olds( $page ),
			'Sorted by old URL, the root-list entry among them.'
		);
		$this->assertSame( 4, $page['total'] );
		$this->assertFalse( $page['capped'] );
		$this->assertSame(
			[
				'url'  => 'https://example.org/crew/',
				'code' => 302,
			],
			$page['rows'][2]['target']
		);
		$this->assertNull( $page['rows'][0]['target'], 'A root-list entry without an override.' );
		$this->assertSame( $this->ids['about'] . '.' . md5( 'page:old-about' ), Records::id( $this->ids['about'], 'page', 'old-about' ) );
	}

	public function test_an_entry_of_a_type_no_longer_supported_or_with_another_type_s_hash_is_not_a_row(): void {
		$this->ledger->record( $this->ids['about'], 'old-about' );
		// A key whose hash names another type than its post's.
		add_post_meta( $this->ids['team'], Ledger::key( 'hg_docs', 'crew' ), 'crew' );

		$this->assertSame( [ Records::id( $this->ids['about'], 'page', 'old-about' ) ], array_keys( self::olds( $this->records->page( '', '', 1 ) ) ) );

		update_option( Settings::OPTION, [ 'post_types' => [] ] );
		Post_Types::reset();
		$this->assertSame( 0, $this->records->page( '', '', 1 )['total'] );
	}

	public function test_twenty_rows_a_page_in_old_url_order(): void {
		for ( $i = 1; $i <= 22; $i++ ) {
			$this->ledger->record( $this->ids['about'], sprintf( 'old-%02d', $i ) );
		}
		$this->ledger->record_root( 'page', 'old-105' );

		$first  = $this->records->page( '', '', 1 );
		$second = $this->records->page( '', '', 2 );

		$this->assertSame( 23, $first['total'] );
		$this->assertCount( Records::PER_PAGE, $first['rows'] );
		$this->assertSame( '/old-01/', $first['rows'][0]['old'] );
		$this->assertSame( [ '/old-105/', '/old-11/' ], [ $first['rows'][10]['old'], $first['rows'][11]['old'] ], 'A root-list entry sorts with the others.' );
		$this->assertSame( [ '/old-20/', '/old-21/', '/old-22/' ], array_values( self::olds( $second ) ) );
	}

	public function test_search_matches_the_old_url(): void {
		$this->ledger->record( $this->ids['about'], 'company/about-us' );
		$this->ledger->record( $this->ids['team'], 'people/crew' );
		$this->ledger->record_root( 'page', 'about-archive' );

		$this->assertSame( [ '/about-archive/', '/company/about-us/' ], array_values( self::olds( $this->records->page( '', 'ABOUT', 1 ) ) ) );
		$this->assertSame( [ '/people/crew/' ], array_values( self::olds( $this->records->page( '', '/people/', 1 ) ) ) );
		$this->assertSame( 0, $this->records->page( '', 'nowhere', 1 )['total'] );
	}

	public function test_the_type_filter_lists_one_supported_type_with_its_rewrite_prefix(): void {
		$this->register_docs();
		$guide = self::factory()->post->create(
			[
				'post_type' => 'hg_docs',
				'post_name' => 'guide',
			]
		);
		$this->ledger->record( $guide, 'manual' );
		$this->ledger->record( $this->ids['about'], 'old-about' );

		$this->assertSame( [ '/docs/manual/', '/old-about/' ], array_values( self::olds( $this->records->page( '', '', 1 ) ) ) );
		$this->assertSame( [ '/docs/manual/' ], array_values( self::olds( $this->records->page( 'hg_docs', '', 1 ) ) ) );
		$this->assertSame( 0, $this->records->page( 'post', '', 1 )['total'], 'A type that is not supported lists nothing.' );
	}

	public function test_a_listing_reads_the_newest_entries_up_to_the_cap_and_finds_an_older_one_by_its_exact_old_url(): void {
		for ( $i = 1; $i <= 5; $i++ ) {
			$this->ledger->record( $this->ids['about'], 'old-' . $i );
		}
		$this->ledger->record_root( 'page', 'archive' );
		$cap = static fn(): int => 3;
		add_filter( 'hierarchy_guard_count_cap', $cap );

		$page = $this->records->page( '', '', 1 );
		$this->assertSame( [ '/archive/', '/old-3/', '/old-4/', '/old-5/' ], array_values( self::olds( $page ) ), 'The newest three, and the root list.' );
		$this->assertTrue( $page['capped'] );
		$this->assertSame( [ '/old-1/' ], array_values( self::olds( $this->records->page( '', home_url( '/old-1/' ), 1 ) ) ), 'Searching its full URL finds an older entry.' );
		remove_filter( 'hierarchy_guard_count_cap', $cap );
	}

	public function test_a_type_s_filter_lists_that_type_s_newest_entries_whatever_the_other_types_hold(): void {
		$this->register_docs();
		$guide = self::factory()->post->create(
			[
				'post_type' => 'hg_docs',
				'post_name' => 'guide',
			]
		);
		$cap   = static fn(): int => 2;
		add_filter( 'hierarchy_guard_count_cap', $cap );
		foreach ( [ 'p-1', 'p-2', 'p-3' ] as $path ) {
			$this->ledger->record( $this->ids['about'], $path );
		}
		$this->ledger->record( $guide, 'manual' );
		$this->ledger->record( $this->ids['about'], 'p-4' );

		$all  = $this->records->page( '', '', 1 );
		$docs = $this->records->page( 'hg_docs', '', 1 );
		remove_filter( 'hierarchy_guard_count_cap', $cap );

		$this->assertSame( [ '/docs/manual/', '/p-4/' ], array_values( self::olds( $all ) ), 'The newest two of every type.' );
		$this->assertTrue( $all['capped'] );
		$this->assertSame( [ '/docs/manual/' ], array_values( self::olds( $docs ) ) );
		$this->assertFalse( $docs['capped'], 'Nothing of that type is older.' );
	}

	public function test_a_page_past_the_last_one_shows_the_last_one(): void {
		for ( $i = 1; $i <= 21; $i++ ) {
			$this->ledger->record( $this->ids['about'], sprintf( 'old-%02d', $i ) );
		}

		$past = $this->records->page( '', '', 5 );
		$this->assertSame( 2, $past['page'] );
		$this->assertSame( [ '/old-21/' ], array_values( self::olds( $past ) ) );

		$this->ledger->forget( $this->ids['about'], 'old-21' );
		$after = $this->records->page( '', '', 2 );
		$this->assertSame( 1, $after['page'], 'After the last row of page 2 is deleted.' );
		$this->assertCount( 20, $after['rows'] );
	}

	public function test_a_listing_costs_the_same_queries_for_five_entries_or_fifty(): void {
		$this->assertSame( $this->queries_to_list( 5 ), $this->queries_to_list( 50 ) );
	}

	/**
	 * Queries to list a page of records and their statuses, with this many entries.
	 *
	 * @param int $entries Entries to record.
	 * @return int
	 */
	private function queries_to_list( int $entries ): int {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $wpdb->esc_like( Ledger::KEY_PREFIX ) . '%' ) );
		delete_option( Ledger::RECENT );
		for ( $i = 1; $i <= $entries; $i++ ) {
			$this->ledger->record( $this->ids['about'], 'many-' . $i );
		}
		wp_cache_flush();
		get_option( Ledger::ROOT_OPTION );
		get_option( Ledger::ROOT_TARGETS );
		get_option( Settings::OPTION );
		Post_Types::supported();

		$queries = get_num_queries();
		$this->records->statuses( $this->records->page( '', '', 1 )['rows'] );
		return get_num_queries() - $queries;
	}

	public function test_find_takes_only_the_id_of_a_record_that_exists(): void {
		$this->ledger->record( $this->ids['about'], 'old-about' );
		$this->ledger->record_root( 'page', 'archive' );
		$this->ledger->set_target( 'page', 'archive', 0, '', 302 );

		$found = $this->records->find( Records::id( $this->ids['about'], 'page', 'old-about' ) );
		$this->assertSame( [ $this->ids['about'], 'page', 'old-about', '/old-about/', null ], [ $found['holder'], $found['post_type'], $found['path'], $found['old'], $found['target'] ] );
		$this->assertSame( 302, $this->records->find( Records::id( 0, 'page', 'archive' ) )['target']['code'] );

		$this->assertNull( $this->records->find( Records::id( $this->ids['team'], 'page', 'old-about' ) ), 'Another page holds no such record.' );
		$this->assertNull( $this->records->find( Records::id( 0, 'page', 'old-about' ) ), 'The root list holds no such path.' );
		$this->assertNull( $this->records->find( $this->ids['about'] . '.' . md5( 'hg_docs:old-about' ) ), 'The hash must be of the holder’s own type.' );
		$this->assertNull( $this->records->find( '999999.' . md5( 'page:old-about' ) ) );
		$this->assertNull( $this->records->find( 'about.old-about' ) );
		$this->assertNull( $this->records->find( $this->ids['about'] . '.' . md5( 'page:old-about' ) . ' ' ) );
	}

	public function test_saving_a_page_of_the_same_type_moves_the_record_onto_it(): void {
		$this->ledger->record( $this->ids['about'], 'old-about' );
		$this->ledger->set_target( 'page', 'old-about', $this->ids['about'], '', 302 );
		$record = $this->records->find( Records::id( $this->ids['about'], 'page', 'old-about' ) );

		$this->assertTrue( $this->records->save( $record, get_permalink( $this->ids['history'] ), 301 ) );

		$this->assertSame( [], $this->ledger->paths_for( $this->ids['about'] ), 'Forgotten on the old holder.' );
		$this->assertFalse( metadata_exists( 'post', $this->ids['about'], Ledger::target_key( 'page', 'old-about' ) ), 'Its override went with it.' );
		$this->assertSame( [ 'old-about' ], $this->ledger->paths_for( $this->ids['history'] ) );
		$this->assertSame( 301, $this->ledger->target( 'page', 'old-about', $this->ids['history'] )['code'], 'The code moved with the record.' );

		$resolver = new Resolver( $this->ledger );
		$this->assertSame( $this->ids['history'], $resolver->resolve( 'page', 'old-about' )->ID );
		$sub = $this->page( 'timeline', $this->ids['history'] );
		$this->assertSame( $sub, $resolver->resolve( 'page', 'old-about/timeline' )->ID, 'A page target covers the URLs under the old one.' );
		wp_update_post(
			[
				'ID'          => $this->ids['history'],
				'post_parent' => 0,
			]
		);
		$this->assertSame( $this->ids['history'], $resolver->resolve( 'page', 'old-about' )->ID, 'And follows the page when it moves.' );
	}

	public function test_saving_a_page_moves_a_root_entry_out_of_the_root_list(): void {
		$this->ledger->record_root( 'page', 'archive' );
		$this->ledger->set_target( 'page', 'archive', 0, '', 302 );
		$record = $this->records->find( Records::id( 0, 'page', 'archive' ) );

		$this->assertTrue( $this->records->save( $record, get_permalink( $this->ids['company'] ), 302 ) );

		$this->assertSame( [ 'page' => [] ], $this->ledger->root_paths() );
		$this->assertFalse( get_option( Ledger::ROOT_TARGETS ) );
		$this->assertSame( [ 'archive' ], $this->ledger->paths_for( $this->ids['company'] ) );
		$this->assertSame( 302, $this->ledger->target( 'page', 'archive', $this->ids['company'] )['code'] );
		$this->assertSame( $this->ids['about'], ( new Resolver( $this->ledger ) )->resolve( 'page', 'archive/about' )->ID );
	}

	public function test_another_type_s_page_or_any_other_url_makes_a_url_target_on_the_same_holder(): void {
		$this->register_docs();
		$guide = self::factory()->post->create(
			[
				'post_type' => 'hg_docs',
				'post_name' => 'guide',
			]
		);
		$this->ledger->record( $this->ids['about'], 'old-about' );
		$record = $this->records->find( Records::id( $this->ids['about'], 'page', 'old-about' ) );

		$this->assertTrue( $this->records->save( $record, get_permalink( $guide ), 0 ) );
		$this->assertSame(
			[
				'url'  => get_permalink( $guide ),
				'code' => 0,
			],
			$this->ledger->target( 'page', 'old-about', $this->ids['about'] ),
			'A record belongs to its own post type.'
		);
		$this->assertSame( [ 'old-about' ], $this->ledger->paths_for( $this->ids['about'] ), 'The record stays where it was.' );

		$this->assertTrue( $this->records->save( $record, ' https://jobs.example.org/open?team=web ', 302 ) );
		$this->assertSame(
			[
				'url'  => 'https://jobs.example.org/open?team=web',
				'code' => 302,
			],
			$this->ledger->target( 'page', 'old-about', $this->ids['about'] )
		);
	}

	public function test_the_holder_s_own_url_makes_a_page_target_again(): void {
		$this->ledger->record( $this->ids['about'], 'old-about' );
		$this->ledger->set_target( 'page', 'old-about', $this->ids['about'], 'https://example.org/', 302 );
		$record = $this->records->find( Records::id( $this->ids['about'], 'page', 'old-about' ) );

		$this->assertTrue( $this->records->save( $record, get_permalink( $this->ids['about'] ), 302 ) );

		$this->assertSame(
			[
				'url'  => '',
				'code' => 302,
			],
			$this->ledger->target( 'page', 'old-about', $this->ids['about'] )
		);
	}

	public function test_a_top_level_entry_offers_an_empty_field_that_keeps_it_and_saves_the_code(): void {
		$this->ledger->record_root( 'page', 'archive' );
		$record = $this->records->find( Records::id( 0, 'page', 'archive' ) );
		$this->assertTrue( Records::is_top_level( $record ) );
		$this->assertSame( '', Records::target_url( $record ), 'A top-level entry offers nothing.' );

		$this->assertTrue( $this->records->save( $record, '  ', 302 ) );
		$this->assertSame( [ 'page' => [ 'archive' ] ], $this->ledger->root_paths(), 'Still in the root list: subpages stay top level.' );
		$this->assertSame(
			[
				'url'  => '',
				'code' => 302,
			],
			$this->ledger->target( 'page', 'archive', 0 )
		);

		$record = $this->records->find( Records::id( 0, 'page', 'archive' ) );
		$this->assertTrue( $this->records->save( $record, '', 0 ) );
		$this->assertNull( $this->ledger->target( 'page', 'archive', 0 ), 'Automatic again, the override goes.' );
	}

	public function test_the_url_the_form_offered_keeps_the_target_and_saves_the_code(): void {
		$this->ledger->record( $this->ids['about'], 'old-about' );
		$this->ledger->set_target( 'page', 'old-about', $this->ids['about'], 'https://example.org/about/', 0 );
		$record = $this->records->find( Records::id( $this->ids['about'], 'page', 'old-about' ) );

		$this->assertTrue( $this->records->save( $record, 'https://example.org/about', 301 ) );

		$this->assertSame(
			[
				'url'  => 'https://example.org/about/',
				'code' => 301,
			],
			$this->ledger->target( 'page', 'old-about', $this->ids['about'] )
		);
	}

	public function test_a_path_on_this_site_is_read_as_its_url(): void {
		$this->ledger->record( $this->ids['about'], 'old-about' );
		$record = function (): array {
			return $this->records->find( Records::id( (int) $this->ledger->find( 'page', 'old-about' ), 'page', 'old-about' ) );
		};

		$this->assertTrue( $this->records->save( $record(), '/company/about/history/', 0 ) );
		$this->assertSame( $this->ids['history'], $this->ledger->find( 'page', 'old-about' ), 'A page’s path makes a page target.' );

		$this->assertTrue( $this->records->save( $record(), '/careers/?team=web', 0 ) );
		$this->assertSame( home_url( '/careers/?team=web' ), $this->ledger->target( 'page', 'old-about', $this->ids['history'] )['url'], 'Any other path is a URL on this site.' );

		$this->assertSame( 'hierarchy_guard_not_http', $this->records->save( $record(), '//evil.example/x', 0 )->get_error_code(), 'Two slashes start another host, not a path.' );
	}

	public function test_a_url_that_is_not_http_or_is_empty_is_refused_and_nothing_is_written(): void {
		$this->ledger->record( $this->ids['about'], 'old-about' );
		$record = $this->records->find( Records::id( $this->ids['about'], 'page', 'old-about' ) );
		$before = wp_cache_get_last_changed( Ledger::CACHE_GROUP );

		foreach ( [ 'javascript:alert(1)', 'ftp://example.org/', 'mailto:a@example.org', 'company/about', '' ] as $url ) {
			$result = $this->records->save( $record, $url, 302 );
			$this->assertInstanceOf( WP_Error::class, $result, $url );
		}
		$this->assertSame( 'hierarchy_guard_no_url', $this->records->save( $record, '  ', 0 )->get_error_code() );
		$this->assertSame( 'Enter the URL or path to redirect to.', $this->records->save( $record, '', 0 )->get_error_message() );
		$this->assertSame( 'hierarchy_guard_not_http', $this->records->save( $record, 'ftp://example.org/', 0 )->get_error_code() );
		$this->assertSame( 'Enter a URL that starts with http:// or https://, or a path on this site that starts with /.', $this->records->save( $record, 'ftp://example.org/', 0 )->get_error_message() );
		$this->assertNull( $this->ledger->target( 'page', 'old-about', $this->ids['about'] ) );
		$this->assertSame( $before, wp_cache_get_last_changed( Ledger::CACHE_GROUP ) );
	}

	public function test_a_page_at_the_old_url_is_refused(): void {
		$this->ledger->record( $this->ids['about'], 'old-about' );
		$draft  = $this->page( 'old-about', 0, 'draft' );
		$record = $this->records->find( Records::id( $this->ids['about'], 'page', 'old-about' ) );

		$result = $this->records->save( $record, get_permalink( $draft ), 0 );

		$this->assertSame( 'hierarchy_guard_same_path', $result->get_error_code() );
		$this->assertSame( 'That page is at /old-about/, so it needs no redirect.', $result->get_error_message() );
		$this->assertSame( [ 'old-about' ], $this->ledger->paths_for( $this->ids['about'] ) );
	}

	public function test_delete_removes_the_record_and_its_override_and_the_old_url_is_a_404(): void {
		$this->ledger->record( $this->ids['about'], 'old-about' );
		$this->ledger->set_target( 'page', 'old-about', $this->ids['about'], '', 302 );
		$this->ledger->record_root( 'page', 'archive' );
		$this->ledger->set_target( 'page', 'archive', 0, 'https://example.org/', 0 );
		$resolver = new Resolver( $this->ledger );
		$this->assertNotNull( $resolver->resolve( 'page', 'old-about' ), 'Precondition: it redirects.' );
		$before = wp_cache_get_last_changed( Ledger::CACHE_GROUP );

		$this->assertTrue( $this->records->delete( $this->records->find( Records::id( $this->ids['about'], 'page', 'old-about' ) ) ) );
		$this->assertTrue( $this->records->delete( $this->records->find( Records::id( 0, 'page', 'archive' ) ) ) );

		$this->assertNotSame( $before, wp_cache_get_last_changed( Ledger::CACHE_GROUP ) );
		$this->assertSame( [], $this->ledger->paths_for( $this->ids['about'] ) );
		$this->assertFalse( metadata_exists( 'post', $this->ids['about'], Ledger::target_key( 'page', 'old-about' ) ) );
		$this->assertFalse( get_option( Ledger::ROOT_TARGETS ) );
		$this->assertNull( $resolver->resolve( 'page', 'old-about' ) );
		$this->assertNull( $resolver->resolve( 'page', 'archive' ) );
	}

	public function test_each_row_shows_the_first_status_that_applies(): void {
		update_option( Settings::OPTION, [ 'serve_redirects' => true ] );
		$this->page( 'news' );
		$draft   = $this->page( 'whats-on', 0, 'draft' );
		$private = $this->page( 'staff', 0, 'private' );
		$trashed = $this->page( 'gone' );
		// Recorded straight into the ledger: a page that takes a path drops the records there.
		$this->ledger->record( $this->ids['about'], 'news' );
		$this->ledger->record( $draft, 'events' );
		$this->ledger->record( $private, 'people' );
		$this->ledger->record( $draft, 'events-feed' );
		$this->ledger->set_target( 'page', 'events-feed', $draft, 'https://example.org/feed/', 0 );
		$this->ledger->record( $trashed, 'old-gone' );
		$this->ledger->set_target( 'page', 'old-gone', $trashed, 'https://example.org/', 0 );
		wp_trash_post( $trashed );
		$this->ledger->record( $this->ids['team'], 'crew' );
		$this->ledger->record_root( 'page', 'archive' );

		$statuses = fn(): array => array_combine( array_column( $this->records->page( '', '', 1 )['rows'], 'old' ), $this->records->statuses( $this->records->page( '', '', 1 )['rows'] ) );

		$this->assertSame(
			[
				'/archive/'     => Records::STATUS_ACTIVE,
				'/crew/'        => Records::STATUS_ACTIVE,
				'/events-feed/' => Records::STATUS_ACTIVE,
				'/events/'      => Records::STATUS_UNPUBLISHED,
				'/news/'        => Records::STATUS_IN_USE,
				'/old-gone/'    => Records::STATUS_UNPUBLISHED,
				'/people/'      => Records::STATUS_UNPUBLISHED,
			],
			$statuses(),
			'A URL target on a draft is served; one whose page is in the Trash is not.'
		);

		update_option( Settings::OPTION, [ 'serve_redirects' => false ] );
		$this->assertSame(
			[
				'/archive/'     => Records::STATUS_OFF,
				'/crew/'        => Records::STATUS_OFF,
				'/events-feed/' => Records::STATUS_OFF,
				'/events/'      => Records::STATUS_UNPUBLISHED,
				'/news/'        => Records::STATUS_IN_USE,
				'/old-gone/'    => Records::STATUS_UNPUBLISHED,
				'/people/'      => Records::STATUS_UNPUBLISHED,
			],
			$statuses(),
			'Only a row that would be active says built-in redirects are off.'
		);
	}

	public function test_an_old_url_with_other_scripts_reads_and_searches_as_people_type_it(): void {
		$this->ledger->record( $this->ids['about'], 'café/équipe' );
		$this->assertSame( [ 'caf%c3%a9/%c3%a9quipe' ], $this->ledger->paths_for( $this->ids['about'] ), 'Precondition: stored percent-encoded.' );

		$this->assertSame( [ '/café/équipe/' ], array_values( self::olds( $this->records->page( '', '', 1 ) ) ) );
		$this->assertSame( [ '/café/équipe/' ], array_values( self::olds( $this->records->page( '', 'café', 1 ) ) ) );
		$this->assertSame( [ '/café/équipe/' ], array_values( self::olds( $this->records->page( '', home_url( '/café/équipe/' ), 1 ) ) ), 'A full URL, as pasted from the browser.' );

		for ( $i = 1; $i <= 3; $i++ ) {
			$this->ledger->record( $this->ids['team'], 'other-' . $i );
		}
		$cap = static fn(): int => 1;
		add_filter( 'hierarchy_guard_count_cap', $cap );
		$this->assertContains( '/café/équipe/', array_values( self::olds( $this->records->page( '', home_url( '/café/équipe/' ), 1 ) ) ), 'Past the cap too.' );
		remove_filter( 'hierarchy_guard_count_cap', $cap );
	}

	public function test_under_plain_permalinks_no_row_reads_active(): void {
		update_option( Settings::OPTION, [ 'serve_redirects' => true ] );
		$this->ledger->record( $this->ids['about'], 'old-about' );
		$this->set_permalink_structure( '' );

		$rows = $this->records->page( '', '', 1 )['rows'];

		$this->assertSame( [ '/old-about/' ], array_column( $rows, 'old' ) );
		$this->assertSame( [ Records::STATUS_OFF ], array_values( $this->records->statuses( $rows ) ), 'The server serves nothing without pretty permalinks.' );
	}

	public function test_a_page_url_pasted_without_its_slash_or_with_a_query_or_fragment_still_moves_the_record(): void {
		$record = function (): array {
			return $this->records->find( Records::id( $this->ledger->find( 'page', 'old-about' ), 'page', 'old-about' ) );
		};
		$this->ledger->record( $this->ids['about'], 'old-about' );

		$this->assertTrue( $this->records->save( $record(), untrailingslashit( get_permalink( $this->ids['history'] ) ), 0 ) );
		$this->assertSame( $this->ids['history'], $this->ledger->find( 'page', 'old-about' ) );

		$this->assertTrue( $this->records->save( $record(), get_permalink( $this->ids['team'] ) . '?ref=menu', 0 ) );
		$this->assertSame( $this->ids['team'], $this->ledger->find( 'page', 'old-about' ) );

		$this->assertTrue( $this->records->save( $record(), get_permalink( $this->ids['company'] ) . '#contact', 0 ) );
		$this->assertSame( $this->ids['company'], $this->ledger->find( 'page', 'old-about' ) );
		$this->assertNull( $this->ledger->target( 'page', 'old-about', $this->ids['company'] ), 'A page target, with no URL kept.' );
	}

	public function test_a_full_url_search_finds_any_record_at_that_exact_url_whatever_the_newest_list_holds(): void {
		// Written straight into post meta: not on the newest list, and the list does not say there are older ones.
		add_post_meta( $this->ids['about'], Ledger::key( 'page', 'not-listed' ), 'not-listed', true );
		$this->ledger->record_root( 'page', 'archive' );
		$this->assertSame( [ '/archive/' ], array_values( self::olds( $this->records->page( '', '', 1 ) ) ), 'Precondition: only the root list is listed.' );
		$this->assertFalse( $this->records->page( '', '', 1 )['capped'] );

		$this->assertSame( [ '/not-listed/' ], array_values( self::olds( $this->records->page( '', home_url( '/not-listed/' ), 1 ) ) ) );
		$this->assertSame( [ '/not-listed/' ], array_values( self::olds( $this->records->page( '', '/not-listed/', 1 ) ) ), 'A path works as well.' );
		$this->assertSame( [ '/archive/' ], array_values( self::olds( $this->records->page( '', home_url( '/archive/' ), 1 ) ) ), 'A root-list entry by its full URL.' );
	}
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npm run test:php -- --filter RecordsTest`
Expected: FAIL. Every test errors with `Error: Class "HierarchyGuard\Redirects\Records" not found`.

- [ ] **Step 3: Write the records model**

Create `includes/HierarchyGuard/Redirects/Records.php`:
```php
<?php
/**
 * The recorded redirects, as the Redirects tab lists and edits them.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Redirects;

use HierarchyGuard\Ledger;
use HierarchyGuard\Paths;
use HierarchyGuard\Post_Types;
use HierarchyGuard\Settings;
use HierarchyGuard\Subtree;
use WP_Error;
use WP_Post;

/**
 * One record per ledger entry: a post's _hierarchy_guard_path_{md5} row, or
 * one root-list entry. A listing reads the newest Subtree::count_cap() post
 * entries, which the ledger keeps a list of per type, and the root list,
 * which holds at most Ledger::ROOT_CAP paths per type; it sorts and searches
 * those, and reads targets and statuses for one page of rows only. A
 * record's ID is its holder's post ID, 0 for the root list, then a dot and
 * its hash.
 */
final class Records {

	public const PER_PAGE = 20;

	public const STATUS_ACTIVE      = 'active';
	public const STATUS_IN_USE      = 'in_use';
	public const STATUS_UNPUBLISHED = 'unpublished';
	public const STATUS_OFF         = 'off';

	/**
	 * Ledger.
	 *
	 * @var Ledger
	 */
	private Ledger $ledger;

	/**
	 * Constructor.
	 *
	 * @param Ledger $ledger Ledger.
	 */
	public function __construct( Ledger $ledger ) {
		$this->ledger = $ledger;
	}

	/**
	 * A record's ID: its holder, 0 for the root list, a dot, and the hash its key carries.
	 *
	 * @param int    $holder    Post that holds the record, 0 for the root list.
	 * @param string $post_type Post type name.
	 * @param string $path      Normalized path.
	 * @return string
	 */
	public static function id( int $holder, string $post_type, string $path ): string {
		return $holder . '.' . md5( $post_type . ':' . $path );
	}

	/**
	 * A record's old URL relative to the home URL, as people read it: such
	 * as `/about/team/`, or `/café/` for a path stored percent-encoded.
	 *
	 * @param string $post_type Post type name.
	 * @param string $path      Normalized path.
	 * @return string
	 */
	public static function old_url( string $post_type, string $path ): string {
		$url = Paths::url( $post_type, $path );
		return rawurldecode( '' !== $url ? Paths::relative( $url ) : '/' . $path . '/' );
	}

	/**
	 * One page of records sorted by old URL, how many match, the page shown,
	 * which is the last one when the page asked for is past it, and whether
	 * older post entries exist than the newest ones listed. A search also
	 * finds, by its hashed key, any entry at the exact old URL it names.
	 *
	 * @param string $post_type A supported type to list alone, or '' for every supported type.
	 * @param string $search    Text the old URL contains, or ''.
	 * @param int    $page      Page number, from 1.
	 * @return array{rows:array<int,array{id:string,holder:int,post_type:string,path:string,old:string,target:array{url:string,code:int}|null}>,total:int,page:int,capped:bool}
	 */
	public function page( string $post_type, string $search, int $page ): array {
		$types = Post_Types::supported();
		if ( '' !== $post_type ) {
			$types = in_array( $post_type, $types, true ) ? [ $post_type ] : [];
		}
		if ( [] === $types ) {
			return [
				'rows'   => [],
				'total'  => 0,
				'page'   => 1,
				'capped' => false,
			];
		}

		[ $rows, $capped ] = $this->window( $types );
		$rows             += $this->roots( $types );
		$search            = trim( $search );
		if ( '' !== $search ) {
			// A full URL is matched as the path the Old URL column shows.
			$needle = '' !== (string) wp_parse_url( $search, PHP_URL_HOST ) ? rawurldecode( Paths::relative( $search ) ) : $search;
			$rows   = array_filter( $rows, static fn( array $row ): bool => false !== stripos( $row['old'], $needle ) );
			// Any entry is found by its exact old URL, whether the newest list holds it or not.
			$rows += $this->exact( $types, $search );
		}

		uasort( $rows, [ self::class, 'compare' ] );
		$page  = min( max( 1, $page ), max( 1, (int) ceil( count( $rows ) / self::PER_PAGE ) ) );
		$slice = array_slice( array_values( $rows ), ( $page - 1 ) * self::PER_PAGE, self::PER_PAGE );

		return [
			'rows'   => $this->with_targets( $slice ),
			'total'  => count( $rows ),
			'page'   => $page,
			'capped' => $capped,
		];
	}

	/**
	 * The record an ID names, with its override, while it exists and its type is supported.
	 *
	 * @param string $id Record ID.
	 * @return array{id:string,holder:int,post_type:string,path:string,old:string,target:array{url:string,code:int}|null}|null
	 */
	public function find( string $id ): ?array {
		global $wpdb;

		if ( 1 !== preg_match( '/^(\d{1,20})\.([0-9a-f]{32})$/', $id, $parts ) ) {
			return null;
		}
		$holder = (int) $parts[1];
		$hash   = $parts[2];

		if ( $holder > 0 ) {
			$post = get_post( $holder );
			if ( ! $post instanceof WP_Post || ! Post_Types::is_supported( (string) $post->post_type ) ) {
				return null;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Point lookup on the post_id index, one row, on an admin action.
			$path = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1", $holder, Ledger::KEY_PREFIX . $hash ) );
			$path = Paths::normalize( (string) $path );
			if ( '' === $path || md5( $post->post_type . ':' . $path ) !== $hash ) {
				return null;
			}
			return $this->with_targets( [ self::row( $holder, (string) $post->post_type, $path ) ] )[0];
		}

		foreach ( $this->roots( Post_Types::supported() ) as $row ) {
			if ( $row['id'] === $id ) {
				return $this->with_targets( [ $row ] )[0];
			}
		}
		return null;
	}

	/**
	 * Each row's status, the first that applies: a live page uses the old
	 * URL; the page the record points to is not published (a page target
	 * whose page nobody can view or that sits under the trash, or any record
	 * whose page is in the trash, which the resolver passes over); built-in
	 * redirects are off, or the site has plain permalinks, under which the
	 * server serves nothing; or active. One query per post type for the pages
	 * at the old URLs, and the holders primed.
	 *
	 * @param array<int,array{id:string,holder:int,post_type:string,path:string,old:string,target:array{url:string,code:int}|null}> $rows Rows, one page at most.
	 * @return array<string,string> One of the STATUS_ constants by row ID.
	 */
	public function statuses( array $rows ): array {
		$paths = [];
		foreach ( $rows as $row ) {
			$paths[ $row['post_type'] ][] = $row['path'];
		}
		$owners = [];
		foreach ( $paths as $type => $list ) {
			$owners[ $type ] = Batch_Resolver::owners( array_values( array_unique( $list ) ), (string) $type );
		}
		Batch_Resolver::prime_with_ancestors( array_values( array_filter( array_column( $rows, 'holder' ) ) ) );

		$serving = Settings::serve_redirects() && '' !== (string) get_option( 'permalink_structure' );
		$out     = [];
		foreach ( $rows as $row ) {
			$owner  = $owners[ $row['post_type'] ][ $row['path'] ] ?? 0;
			$holder = $row['holder'] > 0 ? get_post( $row['holder'] ) : null;
			$url    = null !== $row['target'] ? $row['target']['url'] : '';
			if ( $owner > 0 && is_post_publicly_viewable( $owner ) ) {
				$status = self::STATUS_IN_USE;
			} elseif ( $holder instanceof WP_Post && ! self::reachable( $holder, $url ) ) {
				$status = self::STATUS_UNPUBLISHED;
			} elseif ( ! $serving ) {
				$status = self::STATUS_OFF;
			} else {
				$status = self::STATUS_ACTIVE;
			}
			$out[ $row['id'] ] = $status;
		}
		return $out;
	}

	/**
	 * The URL the Edit view offers for a record: its URL target, or its
	 * page's URL. A root-list entry without a URL target, whose subpages are
	 * top level, offers nothing: the field starts empty.
	 *
	 * @param array{holder:int,target:array{url:string,code:int}|null} $record Record.
	 * @return string
	 */
	public static function target_url( array $record ): string {
		$url = null !== $record['target'] ? $record['target']['url'] : '';
		if ( '' !== $url ) {
			return $url;
		}
		if ( $record['holder'] > 0 ) {
			$link = get_permalink( $record['holder'] );
			return is_string( $link ) ? $link : '';
		}
		return '';
	}

	/**
	 * Whether a record is a root-list entry whose subpages are top level: no
	 * holder, and no URL target.
	 *
	 * @param array{holder:int,target:array{url:string,code:int}|null} $record Record.
	 * @return bool
	 */
	public static function is_top_level( array $record ): bool {
		return 0 === $record['holder'] && ( null === $record['target'] || '' === $record['target']['url'] );
	}

	/**
	 * Saves an edit. A path starting with one slash is a URL on this site,
	 * resolved against the home URL. The URL the Edit view offered, unchanged,
	 * keeps the target and saves the code; so does an empty field for a
	 * top-level root-list entry, whose field starts empty. A URL that
	 * url_to_postid() resolves to a page of the record's own type makes a page
	 * target: the record moves onto that page, out of the root list or off
	 * its old holder, and its code goes with it. The holder's own URL makes it
	 * a page target again. Any other http(s) URL makes a URL target, stored
	 * beside the record. Anything else is refused and nothing is written.
	 *
	 * @param array{id:string,holder:int,post_type:string,path:string,old:string,target:array{url:string,code:int}|null} $record Record from find().
	 * @param string $url  The URL or path entered.
	 * @param int    $code 301, 302, or 0 for the automatic code.
	 * @return true|WP_Error
	 */
	public function save( array $record, string $url, int $code ) {
		$url  = trim( $url );
		$code = in_array( $code, [ 301, 302 ], true ) ? $code : 0;
		$type = $record['post_type'];
		$path = $record['path'];
		if ( '' === $url && self::is_top_level( $record ) ) {
			$this->ledger->set_target( $type, $path, 0, '', $code );
			return true;
		}
		if ( '' === $url ) {
			return new WP_Error( 'hierarchy_guard_no_url', __( 'Enter the URL or path to redirect to.', 'hierarchy-guard' ) );
		}
		if ( str_starts_with( $url, '/' ) && ! str_starts_with( $url, '//' ) ) {
			$url = home_url( $url );
		}

		$kept = null !== $record['target'] ? $record['target']['url'] : '';
		if ( untrailingslashit( esc_url_raw( $url ) ) === untrailingslashit( esc_url_raw( self::target_url( $record ) ) ) ) {
			$this->ledger->set_target( $type, $path, $record['holder'], $kept, $code );
			return true;
		}

		// esc_url_raw() would read `company/about` as http://company/about: the scheme must be typed.
		$typed = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		$clean = esc_url_raw( $url, [ 'http', 'https' ] );
		if ( '' === $clean || ! in_array( $typed, [ 'http', 'https' ], true ) || ! in_array( strtolower( (string) wp_parse_url( $clean, PHP_URL_SCHEME ) ), [ 'http', 'https' ], true ) ) {
			return new WP_Error( 'hierarchy_guard_not_http', __( 'Enter a URL that starts with http:// or https://, or a path on this site that starts with /.', 'hierarchy-guard' ) );
		}

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.url_to_postid_url_to_postid -- Once per save on an admin screen, never on the front end.
		$id   = url_to_postid( $clean );
		$page = $id > 0 ? get_post( $id ) : null;
		if ( ! $page instanceof WP_Post || $page->post_type !== $type ) {
			// Another type's page, or any other URL: the record stays where it is.
			$this->ledger->set_target( $type, $path, $record['holder'], $clean, $code );
			return true;
		}
		if ( (int) $page->ID === $record['holder'] ) {
			$this->ledger->set_target( $type, $path, $record['holder'], '', $code );
			return true;
		}
		if ( Paths::uri( $page ) === $path ) {
			/* translators: %s: the old URL, such as /about/team/. */
			return new WP_Error( 'hierarchy_guard_same_path', sprintf( __( 'That page is at %s, so it needs no redirect.', 'hierarchy-guard' ), $record['old'] ) );
		}

		$this->ledger->record( (int) $page->ID, $path );
		if ( ! metadata_exists( 'post', (int) $page->ID, Ledger::key( $type, $path ) ) ) {
			return new WP_Error( 'hierarchy_guard_not_saved', __( 'The redirect could not be saved.', 'hierarchy-guard' ) );
		}
		$this->delete( $record );
		$this->ledger->set_target( $type, $path, (int) $page->ID, '', $code );
		return true;
	}

	/**
	 * Deletes a record and its override.
	 *
	 * @param array{holder:int,post_type:string,path:string} $record Record from find().
	 * @return bool Whether it was there to delete.
	 */
	public function delete( array $record ): bool {
		if ( $record['holder'] > 0 ) {
			return $this->ledger->forget( $record['holder'], $record['path'] );
		}
		return $this->ledger->forget_root( $record['post_type'], $record['path'] );
	}

	/**
	 * The listing's order: old URL, then post type, then holder.
	 *
	 * @param array{old:string,post_type:string,holder:int} $a A row.
	 * @param array{old:string,post_type:string,holder:int} $b Another row.
	 * @return int
	 */
	private static function compare( array $a, array $b ): int {
		$order = strcmp( $a['old'], $b['old'] );
		if ( 0 === $order ) {
			$order = strcmp( $a['post_type'], $b['post_type'] );
		}
		return 0 !== $order ? $order : $a['holder'] <=> $b['holder'];
	}

	/**
	 * The post entries a listing reads: the newest of the listed types, at
	 * most the count cap, from the ledger's list, in one primary-key lookup
	 * joined to their posts. An entry deleted since the list named it is
	 * simply not found. Rows whose type is not listed, or whose key does not
	 * carry their own type's hash, are left out.
	 *
	 * @param string[] $types Post types to list.
	 * @return array{0:array<string,array<string,mixed>>,1:bool} Rows by ID, and whether older entries exist than were read.
	 */
	private function window( array $types ): array {
		global $wpdb;

		$recent = $this->ledger->recent( $types );
		if ( [] === $recent['ids'] ) {
			return [ [], $recent['more'] ];
		}
		$in = implode( ',', array_fill( 0, count( $recent['ids'] ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $in holds only %d, one per meta ID the list names, at most the count cap; primary-key lookups, then each row's post by its primary key.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT pm.post_id, pm.meta_key, pm.meta_value, p.post_type FROM {$wpdb->postmeta} pm STRAIGHT_JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_id IN ( {$in} )", $recent['ids'] ), ARRAY_A );

		return [ self::rows( $rows, $types ), $recent['more'] ];
	}

	/**
	 * The entries at the exact old URL a search names, for every listed type,
	 * a page of them at most: one query.
	 *
	 * @param string[] $types  Post types to list.
	 * @param string   $search An old URL, absolute or relative to the home URL.
	 * @return array<string,array<string,mixed>> Rows by ID.
	 */
	private function exact( array $types, string $search ): array {
		global $wpdb;

		$path = Paths::normalize( (string) wp_parse_url( Paths::relative( $search ), PHP_URL_PATH ) );
		$keys = [];
		foreach ( $types as $type ) {
			$prefix = Server::prefix( $type );
			if ( '' === $prefix ) {
				$keys[] = Ledger::key( $type, $path );
			} elseif ( str_starts_with( $path, $prefix . '/' ) ) {
				$keys[] = Ledger::key( $type, substr( $path, strlen( $prefix ) + 1 ) );
			}
		}
		if ( '' === $path || [] === $keys ) {
			return [];
		}
		$placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $placeholders holds only %s, one per supported type; seeks on the meta_key index, bounded by LIMIT.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT pm.post_id, pm.meta_key, pm.meta_value, p.post_type FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key IN ( {$placeholders} ) LIMIT %d", ...array_merge( $keys, [ self::PER_PAGE ] ) ), ARRAY_A );

		return self::rows( $rows, $types );
	}

	/**
	 * Rows from post entries read: a listed type, and a key with its own type's hash.
	 *
	 * @param array<int,array<string,string>> $entries Entries with post_id, meta_key, meta_value and post_type.
	 * @param string[]                        $types   Post types to list.
	 * @return array<string,array<string,mixed>> Rows by ID.
	 */
	private static function rows( array $entries, array $types ): array {
		$out = [];
		foreach ( $entries as $entry ) {
			$type = (string) $entry['post_type'];
			$path = Paths::normalize( (string) $entry['meta_value'] );
			if ( '' === $path || ! in_array( $type, $types, true ) || Ledger::key( $type, $path ) !== (string) $entry['meta_key'] ) {
				continue;
			}
			$row               = self::row( (int) $entry['post_id'], $type, $path );
			$out[ $row['id'] ] = $row;
		}
		return $out;
	}

	/**
	 * The root list's entries for the listed types.
	 *
	 * @param string[] $types Post types to list.
	 * @return array<string,array<string,mixed>> Rows by ID.
	 */
	private function roots( array $types ): array {
		$out   = [];
		$roots = $this->ledger->root_paths();
		foreach ( $types as $type ) {
			foreach ( (array) ( $roots[ $type ] ?? [] ) as $path ) {
				$row               = self::row( 0, $type, (string) $path );
				$out[ $row['id'] ] = $row;
			}
		}
		return $out;
	}

	/**
	 * A row without its override.
	 *
	 * @param int    $holder    Post that holds the record, 0 for the root list.
	 * @param string $post_type Post type name.
	 * @param string $path      Normalized path.
	 * @return array{id:string,holder:int,post_type:string,path:string,old:string,target:null}
	 */
	private static function row( int $holder, string $post_type, string $path ): array {
		return [
			'id'        => self::id( $holder, $post_type, $path ),
			'holder'    => $holder,
			'post_type' => $post_type,
			'path'      => $path,
			'old'       => self::old_url( $post_type, $path ),
			'target'    => null,
		];
	}

	/**
	 * The rows with their overrides: one query for the post entries' sibling
	 * rows, the root list's from its option, and the holders primed.
	 *
	 * @param array<int,array<string,mixed>> $rows Rows, one page at most.
	 * @return array<int,array{id:string,holder:int,post_type:string,path:string,old:string,target:array{url:string,code:int}|null}>
	 */
	private function with_targets( array $rows ): array {
		global $wpdb;

		$holders = array_values( array_unique( array_filter( array_map( static fn( array $row ): int => (int) $row['holder'], $rows ) ) ) );
		$found   = [];
		if ( [] !== $holders ) {
			_prime_post_caches( $holders, false, false );
			$keys = array_values( array_unique( array_map( static fn( array $row ): string => Ledger::target_key( (string) $row['post_type'], (string) $row['path'] ), $rows ) ) );
			$ids  = implode( ',', array_map( 'intval', $holders ) );
			$in   = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $ids holds integers only and $in only %s, one per row of the page.
			$overrides = (array) $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ( {$ids} ) AND meta_key IN ( {$in} )", $keys ), ARRAY_A );
			foreach ( $overrides as $override ) {
				$found[ (int) $override['post_id'] ][ (string) $override['meta_key'] ] = maybe_unserialize( (string) $override['meta_value'] );
			}
		}

		$out = [];
		foreach ( $rows as $row ) {
			$row['target'] = $row['holder'] > 0
				? Ledger::clean_target( $found[ $row['holder'] ][ Ledger::target_key( $row['post_type'], $row['path'] ) ] ?? null )
				: $this->ledger->target( $row['post_type'], $row['path'], 0 );
			$out[]         = $row;
		}
		return $out;
	}

	/**
	 * Whether the resolver can serve a record through its holder: never from
	 * the trash; for a page target, only to a page anyone can view that is
	 * not under the trash.
	 *
	 * @param WP_Post $holder The record's holder.
	 * @param string  $url    The record's URL target, or ''.
	 * @return bool
	 */
	private static function reachable( WP_Post $holder, string $url ): bool {
		if ( 'trash' === $holder->post_status ) {
			return false;
		}
		if ( '' !== $url ) {
			return true;
		}
		return is_post_publicly_viewable( $holder ) && ! Paths::has_trashed_segment( Paths::uri( $holder ) );
	}
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `npm run test:php -- --filter RecordsTest`
Expected: PASS (25 tests).

Run: `npm run test:php` then `composer lint`
Expected: the whole suite passes, with the new tests counted; lint exits 0. Run `composer lint:fix` first if it reports alignment; formatting-only changes are not deviations.

- [ ] **Step 5: Commit**

```bash
git add includes/HierarchyGuard/Redirects/Records.php tests/phpunit/RecordsTest.php
git commit -m "feat: list, find, retarget and delete recorded redirects"
```

### Task 6: Settings › Hierarchy Guard with the Settings tab

**Files:**
- Rename: `includes/HierarchyGuard/Admin/Settings_Section.php` → `includes/HierarchyGuard/Admin/Settings_Page.php` (then replace its contents)
- Modify: `includes/HierarchyGuard/Settings.php` (`GROUP`, `register_option()`), `includes/HierarchyGuard/Plugin.php` (wiring)
- Modify: `includes/HierarchyGuard/Admin/Notices.php`, `includes/HierarchyGuard/Admin/Assets.php` (links), `README.md`
- Delete: `tests/phpunit/SettingsSectionTest.php`
- Test: `tests/phpunit/SettingsPageTest.php` (new), `tests/phpunit/AssetsTest.php`, `tests/phpunit/NoticesTest.php`, `src/common/test/change-notice.test.js`

**Interfaces:**
- Produces: `Settings::GROUP = 'hierarchy_guard'` and `Settings::register_option(): void`, hooked on `init` by `Plugin`, registering `hierarchy_guard_settings` in that group with `Settings::sanitize()` and the defaults.
- Produces: `Admin\Settings_Page::__construct( Providers $providers )`, `SLUG = 'hierarchy-guard'`, `SECTION = 'hierarchy_guard'`; `register()` hooks `admin_menu` → `add_page()`, `admin_init` → `add_fields()` and `plugin_action_links_hierarchy-guard/hierarchy-guard.php` → `action_links()`.
- Produces: `Settings_Page::url( string $tab = '' ): string` (`options-general.php?page=hierarchy-guard[&tab=…]`), `redirects_url()` (`…#hierarchy-guard-redirects`), `policy_url()` (`…#hierarchy-guard-trash-policy`). Every link that pointed at Settings › Writing uses them: the notices' and the nudge's "Set up redirects", the block editor config's `settingsUrl` and `policyUrl` (the panel's "Change this in Settings").
- Produces: `render()` prints `<h1>`, core nav tabs (`nav-tab-wrapper`, `nav-tab`, `nav-tab-active`, `aria-current="page"`), `<hr class="wp-header-end">` (core's `common.js` puts admin notices there, below the tabs) and the Settings tab: an `options.php` form with `settings_fields( Settings::GROUP )` and the three fields in a section without a heading. The field renderers move from `Settings_Section` unchanged except "The Trash is turned off…", capitalised as on core's PHP screens. Task 7 adds the Redirects tab.
- Removes: `Admin\Settings_Section` and everything it registered on Settings › Writing.

- [ ] **Step 1: Write the failing tests**

```bash
git rm tests/phpunit/SettingsSectionTest.php
```

Create `tests/phpunit/SettingsPageTest.php`:
```php
<?php
/**
 * Settings › Hierarchy Guard.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Admin\Settings_Page;
use HierarchyGuard\Post_Types;
use HierarchyGuard\Redirects\Providers;
use HierarchyGuard\Settings;
use WP_UnitTestCase;

/**
 * @covers \HierarchyGuard\Admin\Settings_Page
 * @covers \HierarchyGuard\Settings::register_option
 */
final class SettingsPageTest extends WP_UnitTestCase {

	private Settings_Page $page;

	public function set_up(): void {
		parent::set_up();
		require_once ABSPATH . 'wp-admin/includes/template.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		delete_option( Settings::OPTION );
		Post_Types::reset();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->page = new Settings_Page( new Providers() );
	}

	public function tear_down(): void {
		// add_fields() and add_page() write core's globals; later tests must not see them.
		unset( $GLOBALS['wp_settings_sections'][ Settings_Page::SLUG ], $GLOBALS['wp_settings_fields'][ Settings_Page::SLUG ], $GLOBALS['submenu']['options-general.php'] );
		parent::tear_down();
	}

	/**
	 * Whether a callback of the plugin's is hooked at priority 10. has_action( $hook )
	 * alone proves nothing: core hooks the same actions.
	 *
	 * @param string   $hook     Hook name.
	 * @param callable $matches  Tells the plugin's callback: fn( mixed $function ): bool.
	 */
	private function hooked( string $hook, callable $matches ): bool {
		global $wp_filter;
		foreach ( $wp_filter[ $hook ]->callbacks[10] ?? [] as $callback ) {
			if ( $matches( $callback['function'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a Settings_Page method is hooked at priority 10.
	 *
	 * @param string $hook   Hook name.
	 * @param string $method Method name.
	 */
	private function page_on( string $hook, string $method ): bool {
		return $this->hooked( $hook, static fn( $callback ): bool => is_array( $callback ) && $callback[0] instanceof Settings_Page && $method === $callback[1] );
	}

	public function test_the_plugin_hooks_the_page_the_fields_and_the_plugins_row_link(): void {
		$this->assertTrue( $this->page_on( 'admin_menu', 'add_page' ) );
		$this->assertTrue( $this->page_on( 'admin_init', 'add_fields' ) );
		$this->assertTrue( $this->page_on( 'plugin_action_links_' . plugin_basename( HIERARCHY_GUARD_FILE ), 'action_links' ) );
		$this->assertSame( 'hierarchy-guard/hierarchy-guard.php', plugin_basename( HIERARCHY_GUARD_FILE ) );
	}

	public function test_the_option_is_registered_on_init_in_the_page_s_group(): void {
		global $wp_registered_settings;

		$this->assertTrue( $this->hooked( 'init', static fn( $callback ): bool => [ Settings::class, 'register_option' ] === $callback ), 'Registering on init lets the sanitize callback cover WP-CLI and REST writes.' );

		unregister_setting( Settings::GROUP, Settings::OPTION );
		$this->assertArrayNotHasKey( Settings::OPTION, $wp_registered_settings, 'Precondition: the bootstrap init registration is undone.' );
		Settings::register_option();

		$this->assertSame( [ Settings::class, 'sanitize' ], $wp_registered_settings[ Settings::OPTION ]['sanitize_callback'] );
		$this->assertSame( 'hierarchy_guard', $wp_registered_settings[ Settings::OPTION ]['group'] );
	}

	public function test_the_page_sits_under_settings_for_users_who_can_manage_options(): void {
		global $submenu;

		$this->page->add_page();

		$entry = array_values( array_filter( $submenu['options-general.php'] ?? [], static fn( array $item ): bool => Settings_Page::SLUG === $item[2] ) );
		$this->assertCount( 1, $entry );
		$this->assertSame( [ 'Hierarchy Guard', 'manage_options' ], [ $entry[0][0], $entry[0][1] ] );
		$this->assertStringEndsWith( '/wp-admin/options-general.php?page=hierarchy-guard', Settings_Page::url() );
		$this->assertStringEndsWith( '/wp-admin/options-general.php?page=hierarchy-guard&tab=redirects', Settings_Page::url( 'redirects' ) );
	}

	public function test_the_fields_are_on_the_page_and_nothing_is_on_settings_writing(): void {
		global $wp_settings_sections, $wp_settings_fields;

		$this->page->add_fields();

		$this->assertSame( '', $wp_settings_sections[ Settings_Page::SLUG ][ Settings_Page::SECTION ]['title'], 'The page’s heading is enough.' );
		$this->assertSame( [ 'hierarchy_guard_post_types', 'hierarchy_guard_trash_policy', 'hierarchy_guard_serve_redirects' ], array_keys( $wp_settings_fields[ Settings_Page::SLUG ][ Settings_Page::SECTION ] ) );
		$this->assertArrayNotHasKey( 'hierarchy_guard', $wp_settings_sections['writing'] ?? [] );
		$this->assertArrayNotHasKey( 'hierarchy_guard', $wp_settings_fields['writing'] ?? [] );
	}

	public function test_the_settings_tab_is_a_settings_api_form_under_core_nav_tabs(): void {
		$this->page->add_fields();

		ob_start();
		$this->page->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<h1>Hierarchy Guard</h1>', $html );
		$this->assertStringContainsString( '<nav class="nav-tab-wrapper wp-clearfix" aria-label="Secondary menu">', $html );
		$this->assertMatchesRegularExpression( '#</nav><hr class="wp-header-end"><form#', $html, 'Core’s notices land below the tabs.' );
		$this->assertMatchesRegularExpression( '#<a href="[^"]*options-general\.php\?page=hierarchy-guard" class="nav-tab nav-tab-active" aria-current="page">Settings</a>#', $html );
		$this->assertStringContainsString( '<form action="options.php" method="post">', $html );
		$this->assertStringContainsString( "name='option_page' value='hierarchy_guard'", $html );
		$this->assertStringContainsString( 'name="hierarchy_guard_settings[trash_policy]"', $html );
		$this->assertStringContainsString( 'id="submit"', $html );
	}

	public function test_without_manage_options_the_page_prints_nothing(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		ob_start();
		$this->page->render();
		$this->assertSame( '', (string) ob_get_clean() );
	}

	public function test_the_plugins_row_gets_a_settings_link_first_for_administrators(): void {
		$links = $this->page->action_links( [ 'deactivate' => '<a href="#">Deactivate</a>' ] );

		$this->assertSame( [ 'settings', 'deactivate' ], array_keys( $links ) );
		$this->assertSame( '<a href="' . esc_url( admin_url( 'options-general.php?page=hierarchy-guard' ) ) . '">Settings</a>', $links['settings'] );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->assertSame( [ 'deactivate' ], array_keys( $this->page->action_links( [ 'deactivate' => '<a href="#">Deactivate</a>' ] ) ) );
	}

	public function test_post_types_field_lists_hierarchical_types_with_current_state(): void {
		ob_start();
		$this->page->render_post_types();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="hierarchy_guard_settings[post_types][]"', $html );
		$this->assertStringContainsString( 'value="page"', $html );
		$this->assertMatchesRegularExpression( '/value="page"[^>]*checked/', $html );
		$this->assertStringNotContainsString( 'value="post"', $html );
		$this->assertStringContainsString( 'Public hierarchical post types appear here automatically.', $html );
	}

	public function test_post_types_field_always_submits_the_key(): void {
		ob_start();
		$this->page->render_post_types();
		$html = (string) ob_get_clean();

		$hidden = '<input type="hidden" name="hierarchy_guard_settings[post_types][]" value="">';
		$this->assertStringContainsString( $hidden, $html );
		$this->assertLessThan( strpos( $html, 'type="checkbox"' ), strpos( $html, $hidden ), 'The hidden input comes before the checkboxes.' );
	}

	public function test_post_types_field_shows_the_stored_choice_not_the_filtered_list(): void {
		register_post_type(
			'hg_docs',
			[
				'public'       => true,
				'hierarchical' => true,
			]
		);
		$filter = static fn( array $types ): array => array_values( array_diff( $types, [ 'hg_docs' ] ) );
		add_filter( 'hierarchy_guard_post_types', $filter );
		Post_Types::reset();
		$this->assertNotContains( 'hg_docs', Post_Types::supported(), 'Precondition: the filter removes the type.' );

		ob_start();
		$this->page->render_post_types();
		$html = (string) ob_get_clean();
		remove_filter( 'hierarchy_guard_post_types', $filter );
		unregister_post_type( 'hg_docs' );

		$this->assertMatchesRegularExpression( '/value="hg_docs"[^>]*checked/', $html, 'With the all-types default stored, a filtered-out type still renders checked.' );
	}

	public function test_serve_redirects_field_names_the_detected_provider_or_says_none_found(): void {
		ob_start();
		$this->page->render_serve_redirects();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'name="hierarchy_guard_settings[serve_redirects]"', $html );
		$this->assertStringNotContainsString( 'checked', $html );
		$this->assertStringContainsString( 'No redirect plugin was found.', $html );

		$filter = static function ( array $providers ): array {
			$providers['custom'] = [
				'name'   => 'Custom Redirects',
				'active' => true,
				'url'    => admin_url( 'admin.php?page=x' ),
				'csv'    => 'generic',
			];
			return $providers;
		};
		add_filter( 'hierarchy_guard_redirect_providers', $filter );
		ob_start();
		$this->page->render_serve_redirects();
		$html = (string) ob_get_clean();
		remove_filter( 'hierarchy_guard_redirect_providers', $filter );

		$this->assertStringContainsString( 'Custom Redirects is active and can handle these.', $html );
	}

	public function test_trash_policy_field_renders_three_radios_with_the_stored_choice(): void {
		update_option( Settings::OPTION, [ 'trash_policy' => Settings::POLICY_KEEP ] );

		ob_start();
		$this->page->render_trash_policy();
		$html = (string) ob_get_clean();

		$this->assertSame( 3, substr_count( $html, 'type="radio"' ) );
		$this->assertStringContainsString( '<fieldset id="hierarchy-guard-trash-policy">', $html, 'The target of the editor panel’s Settings link.' );
		$this->assertMatchesRegularExpression( '/value="keep"\s+checked/', $html );
		$this->assertStringContainsString( 'Move subpages up one level', $html );
		$this->assertStringContainsString( 'Trash the subpages too', $html );
		$this->assertStringContainsString( 'Leave subpages where they are', $html );
		$this->assertStringContainsString( 'Used when there’s no chance to ask, for example in the editor, the REST API, or WP-CLI. The Pages list asks each time.', $html );
		$this->assertStringNotContainsString( 'The Trash is turned off', $html, 'This test site keeps its Trash.' );
	}

	public function test_a_provider_without_a_name_gets_a_generic_sentence(): void {
		$filter = static fn(): array => [
			'custom' => [
				'active' => true,
				'url'    => '',
				'csv'    => 'generic',
			],
		];
		add_filter( 'hierarchy_guard_redirect_providers', $filter );
		ob_start();
		$this->page->render_serve_redirects();
		$html = (string) ob_get_clean();
		remove_filter( 'hierarchy_guard_redirect_providers', $filter );

		$this->assertStringContainsString( 'A redirect plugin is active and can handle these.', $html );
	}

	public function test_settings_links_open_the_page_at_their_field(): void {
		ob_start();
		$this->page->render_serve_redirects();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'id="hierarchy-guard-redirects"', $html );
		$this->assertSame( admin_url( 'options-general.php?page=hierarchy-guard#hierarchy-guard-redirects' ), Settings_Page::redirects_url() );
		$this->assertSame( admin_url( 'options-general.php?page=hierarchy-guard#hierarchy-guard-trash-policy' ), Settings_Page::policy_url() );
	}

	public function test_the_settings_globals_do_not_leak_into_the_next_test(): void {
		global $wp_settings_sections;

		$this->assertArrayNotHasKey( Settings_Page::SLUG, $wp_settings_sections ?? [], 'tear_down() removed what an earlier add_fields() registered.' );
	}
}
```

In `tests/phpunit/AssetsTest.php`, replace:
```php
		$this->assertNull( $config['provider'] );
		$this->assertStringEndsWith( 'options-writing.php#hierarchy-guard-redirects', $config['settingsUrl'] );
		$this->assertStringEndsWith( 'options-writing.php#hierarchy-guard-trash-policy', $config['policyUrl'] );
		$this->assertSame( Settings::POLICY_REPARENT, $config['policy'] );
```
with:
```php
		$this->assertNull( $config['provider'] );
		$this->assertSame( admin_url( 'options-general.php?page=hierarchy-guard#hierarchy-guard-redirects' ), $config['settingsUrl'] );
		$this->assertSame( admin_url( 'options-general.php?page=hierarchy-guard#hierarchy-guard-trash-policy' ), $config['policyUrl'] );
		$this->assertSame( Settings::POLICY_REPARENT, $config['policy'] );
```

In `tests/phpunit/NoticesTest.php`, make these replacements.

Replace:
```php
		$this->assertStringContainsString( 'class="hierarchy-guard-view-redirects"', $notice['message'] );
		$this->assertStringEndsWith( 'download>Download redirects (CSV)</a><span aria-hidden="true"> ·</span> <a href="' . esc_url( admin_url( 'options-writing.php#hierarchy-guard-redirects' ) ) . '">Set up redirects</a>', $notice['message'], 'View comes first, as in the other two notices.' );
		$this->assertStringContainsString( 'format=generic', $notice['message'] );
```
with:
```php
		$this->assertStringContainsString( 'class="hierarchy-guard-view-redirects"', $notice['message'] );
		$this->assertStringEndsWith( 'download>Download redirects (CSV)</a><span aria-hidden="true"> ·</span> <a href="' . esc_url( admin_url( 'options-general.php?page=hierarchy-guard#hierarchy-guard-redirects' ) ) . '">Set up redirects</a>', $notice['message'], 'View comes first, as in the other two notices.' );
		$this->assertStringContainsString( 'format=generic', $notice['message'] );
```

Replace:
```php
		$this->assertStringContainsString( 'is-dismissible', $html );
		$this->assertStringContainsString( 'Set up redirects', $html );

```
with:
```php
		$this->assertStringContainsString( 'is-dismissible', $html );
		$this->assertStringContainsString( '<a href="' . esc_url( admin_url( 'options-general.php?page=hierarchy-guard#hierarchy-guard-redirects' ) ) . '">Set up redirects</a>', $html );

```

In `src/common/test/change-notice.test.js`, make these replacements.

Replace:
```js
		config.serving = true;
		config.settingsUrl = '/wp-admin/options-writing.php';
		expect(
```
with:
```js
		config.serving = true;
		config.settingsUrl =
			'/wp-admin/options-general.php?page=hierarchy-guard#hierarchy-guard-redirects';
		expect(
```

Replace:
```js
	it( 'names the redirect plugin and offers the redirects', () => {
		config.settingsUrl = '/wp-admin/options-writing.php';
		expect(
```
with:
```js
	it( 'names the redirect plugin and offers the redirects', () => {
		config.settingsUrl =
			'/wp-admin/options-general.php?page=hierarchy-guard#hierarchy-guard-redirects';
		expect(
```

Replace:
```js
		} );
		config.settingsUrl = '/wp-admin/options-writing.php';
		expect( changeNotice( pairs( 1 ), redirects ).setup ).toBe( true );
```
with:
```js
		} );
		config.settingsUrl =
			'/wp-admin/options-general.php?page=hierarchy-guard#hierarchy-guard-redirects';
		expect( changeNotice( pairs( 1 ), redirects ).setup ).toBe( true );
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npm run test:php -- --filter 'SettingsPageTest|AssetsTest|NoticesTest'`
Expected: FAIL. `SettingsPageTest` errors with `Error: Class "HierarchyGuard\Admin\Settings_Page" not found`; the Assets and Notices link tests fail on `options-writing.php`. `npm run test:js` passes either way: the JS tests only set the config's URL.

- [ ] **Step 3: Register the option from Settings**

In `includes/HierarchyGuard/Settings.php`, make these replacements.

Replace:
```php
	public const OPTION = 'hierarchy_guard_settings';

```
with:
```php
	public const OPTION = 'hierarchy_guard_settings';

	/**
	 * The Settings API group the Settings tab saves.
	 */
	public const GROUP = 'hierarchy_guard';

```

Replace:
```php
	/**
	 * Sanitize callback for register_setting().
```
with:
```php
	/**
	 * Registers the option with its sanitize callback and defaults. Hooked on
	 * init, so the callback also covers WP-CLI and REST writes.
	 */
	public static function register_option(): void {
		register_setting(
			self::GROUP,
			self::OPTION,
			[
				'type'              => 'array',
				'sanitize_callback' => [ self::class, 'sanitize' ],
				'default'           => self::defaults(),
			]
		);
	}

	/**
	 * Sanitize callback for register_setting().
```

- [ ] **Step 4: Turn the Writing section into the page**

```bash
git mv includes/HierarchyGuard/Admin/Settings_Section.php includes/HierarchyGuard/Admin/Settings_Page.php
```

Create `includes/HierarchyGuard/Admin/Settings_Page.php`:
```php
<?php
/**
 * Settings › Hierarchy Guard.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Admin;

use HierarchyGuard\Redirects\Providers;
use HierarchyGuard\Settings;

/**
 * The plugin's one admin page, under Settings, for users with manage_options:
 * core nav tabs, the Settings tab's three fields, saved through the Settings
 * API, and a "Settings" link on the plugin's row of the Plugins screen.
 */
final class Settings_Page {

	public const SLUG    = 'hierarchy-guard';
	public const SECTION = 'hierarchy_guard';

	/**
	 * Providers.
	 *
	 * @var Providers
	 */
	private Providers $providers;

	/**
	 * Constructor.
	 *
	 * @param Providers $providers Redirect plugin detection.
	 */
	public function __construct( Providers $providers ) {
		$this->providers = $providers;
	}

	/**
	 * Hooks into core.
	 */
	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_page' ] );
		add_action( 'admin_init', [ $this, 'add_fields' ] );
		add_filter( 'plugin_action_links_' . plugin_basename( HIERARCHY_GUARD_FILE ), [ $this, 'action_links' ] );
	}

	/**
	 * The page's URL, on a tab.
	 *
	 * @param string $tab Tab, '' for the Settings tab.
	 * @return string
	 */
	public static function url( string $tab = '' ): string {
		$args = [ 'page' => self::SLUG ];
		if ( '' !== $tab ) {
			$args['tab'] = $tab;
		}
		return add_query_arg( $args, admin_url( 'options-general.php' ) );
	}

	/**
	 * The Redirects field, for "Set up redirects" links.
	 *
	 * @return string
	 */
	public static function redirects_url(): string {
		return self::url() . '#hierarchy-guard-redirects';
	}

	/**
	 * The trash-policy field, for "Change this in Settings" links.
	 *
	 * @return string
	 */
	public static function policy_url(): string {
		return self::url() . '#hierarchy-guard-trash-policy';
	}

	/**
	 * Adds the page under Settings.
	 */
	public function add_page(): void {
		add_options_page( __( 'Hierarchy Guard', 'hierarchy-guard' ), __( 'Hierarchy Guard', 'hierarchy-guard' ), 'manage_options', self::SLUG, [ $this, 'render' ] );
	}

	/**
	 * Puts a "Settings" link first on the plugin's row of the Plugins screen.
	 *
	 * @param array<int|string,string> $links Action links.
	 * @return array<int|string,string>
	 */
	public function action_links( array $links ): array {
		if ( current_user_can( 'manage_options' ) ) {
			$links = array_merge( [ 'settings' => sprintf( '<a href="%1$s">%2$s</a>', esc_url( self::url() ), esc_html__( 'Settings', 'hierarchy-guard' ) ) ], $links );
		}
		return $links;
	}

	/**
	 * Adds the Settings tab's fields, in a section without a heading.
	 */
	public function add_fields(): void {
		add_settings_section( self::SECTION, '', '__return_null', self::SLUG );

		add_settings_field( 'hierarchy_guard_post_types', __( 'Post types', 'hierarchy-guard' ), [ $this, 'render_post_types' ], self::SLUG, self::SECTION );
		add_settings_field( 'hierarchy_guard_trash_policy', __( 'Trashing a page with subpages', 'hierarchy-guard' ), [ $this, 'render_trash_policy' ], self::SLUG, self::SECTION );
		add_settings_field( 'hierarchy_guard_serve_redirects', __( 'Redirects', 'hierarchy-guard' ), [ $this, 'render_serve_redirects' ], self::SLUG, self::SECTION );
	}

	/**
	 * Prints the page: the heading, the tabs, and the current tab.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Hierarchy Guard', 'hierarchy-guard' ) . '</h1>';
		$this->render_tabs( 'settings' );
		// Core moves notices to just after this, below the tabs.
		echo '<hr class="wp-header-end">';
		echo '<form action="options.php" method="post">';
		settings_fields( Settings::GROUP );
		do_settings_sections( self::SLUG );
		submit_button();
		echo '</form>';
		echo '</div>';
	}

	/**
	 * The tabs, by key.
	 *
	 * @return array<string,string>
	 */
	private function tabs(): array {
		return [
			'settings' => __( 'Settings', 'hierarchy-guard' ),
		];
	}

	/**
	 * Prints core's nav tabs.
	 *
	 * @param string $current The tab shown.
	 */
	private function render_tabs( string $current ): void {
		echo '<nav class="nav-tab-wrapper wp-clearfix" aria-label="' . esc_attr__( 'Secondary menu', 'hierarchy-guard' ) . '">';
		foreach ( $this->tabs() as $tab => $label ) {
			$active = $tab === $current;
			printf(
				'<a href="%1$s" class="nav-tab%2$s"%3$s>%4$s</a>',
				esc_url( self::url( 'settings' === $tab ? '' : $tab ) ),
				$active ? ' nav-tab-active' : '',
				$active ? ' aria-current="page"' : '',
				esc_html( $label )
			);
		}
		echo '</nav>';
	}

	/**
	 * Checkbox per public hierarchical post type.
	 */
	public function render_post_types(): void {
		$types      = get_post_types(
			[
				'public'       => true,
				'hierarchical' => true,
			],
			'objects'
		);
		$configured = Settings::get( 'post_types' );
		// The stored choice, not the filtered list, so a save never persists filter output.
		$selected = null === $configured ? array_keys( $types ) : (array) $configured;

		echo '<fieldset><legend class="screen-reader-text"><span>' . esc_html__( 'Post types', 'hierarchy-guard' ) . '</span></legend>';
		// Always submits the key, so unchecking every box saves [] rather than the null default.
		printf( '<input type="hidden" name="%s[post_types][]" value="">', esc_attr( Settings::OPTION ) );
		foreach ( $types as $type ) {
			printf(
				'<label><input type="checkbox" name="%1$s[post_types][]" value="%2$s" %3$s> %4$s</label><br>',
				esc_attr( Settings::OPTION ),
				esc_attr( $type->name ),
				checked( in_array( $type->name, $selected, true ), true, false ),
				esc_html( $type->labels->name )
			);
		}
		echo '<p class="description">' . esc_html__( 'Public hierarchical post types appear here automatically.', 'hierarchy-guard' ) . '</p>';
		echo '</fieldset>';
	}

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

		echo '<fieldset id="hierarchy-guard-trash-policy"><legend class="screen-reader-text"><span>' . esc_html__( 'Trashing a page with subpages', 'hierarchy-guard' ) . '</span></legend>';
		foreach ( $options as $value => $label ) {
			printf(
				'<label><input type="radio" name="%1$s[trash_policy]" value="%2$s" %3$s> %4$s</label><br>',
				esc_attr( Settings::OPTION ),
				esc_attr( $value ),
				checked( $current, $value, false ),
				esc_html( $label )
			);
		}

		$help = __( 'Used when there’s no chance to ask, for example in the editor, the REST API, or WP-CLI.', 'hierarchy-guard' ) . ' ' . __( 'The Pages list asks each time.', 'hierarchy-guard' );
		if ( ! EMPTY_TRASH_DAYS ) {
			$help .= ' ' . __( 'The Trash is turned off on this site, so deleted pages skip it and their subpages always move up one level.', 'hierarchy-guard' );
		}
		echo '<p class="description">' . esc_html( $help ) . '</p>';
		echo '</fieldset>';
	}

	/**
	 * Checkbox for the built-in redirect server, with provider-aware help text.
	 */
	public function render_serve_redirects(): void {
		$provider = $this->providers->detect();

		printf(
			'<label><input type="checkbox" id="hierarchy-guard-redirects" name="%1$s[serve_redirects]" value="1" %2$s> %3$s</label>',
			esc_attr( Settings::OPTION ),
			checked( Settings::serve_redirects(), true, false ),
			esc_html__( 'Redirect old URLs to their new pages', 'hierarchy-guard' )
		);

		$name = null !== $provider ? trim( (string) ( $provider['name'] ?? '' ) ) : '';
		if ( null !== $provider && '' !== $name ) {
			/* translators: %s: name of the detected redirect plugin. */
			$help = sprintf( __( '%s is active and can handle these.', 'hierarchy-guard' ), $name );
		} elseif ( null !== $provider ) {
			$help = __( 'A redirect plugin is active and can handle these.', 'hierarchy-guard' );
		} else {
			$help = __( 'No redirect plugin was found.', 'hierarchy-guard' ) . ' ' . __( 'Turn this on so old URLs reach their new pages.', 'hierarchy-guard' );
		}
		$help .= ' ' . __( 'URL changes are recorded either way.', 'hierarchy-guard' );
		echo '<p class="description">' . esc_html( $help ) . '</p>';
	}
}
```

- [ ] **Step 5: Wire it and point every settings link at it**

In `includes/HierarchyGuard/Plugin.php`, replace:
```php
		( new Redirects\Server( new Redirects\Resolver( $ledger ) ) )->register();
		( new Admin\Settings_Section( $providers ) )->register();
		( new Trash\Markers() )->register();
```
with:
```php
		( new Redirects\Server( new Redirects\Resolver( $ledger ) ) )->register();
		add_action( 'init', [ Settings::class, 'register_option' ] );
		( new Admin\Settings_Page( $providers ) )->register();
		( new Trash\Markers() )->register();
```

In `includes/HierarchyGuard/Admin/Notices.php`, make these replacements.

Replace:
```php
		if ( current_user_can( 'manage_options' ) ) {
			$message .= sprintf( '<span aria-hidden="true"> ·</span> <a href="%1$s">%2$s</a>', esc_url( Settings_Section::redirects_url() ), esc_html__( 'Set up redirects', 'hierarchy-guard' ) );
		}
```
with:
```php
		if ( current_user_can( 'manage_options' ) ) {
			$message .= sprintf( '<span aria-hidden="true"> ·</span> <a href="%1$s">%2$s</a>', esc_url( Settings_Page::redirects_url() ), esc_html__( 'Set up redirects', 'hierarchy-guard' ) );
		}
```

Replace:
```php
					esc_html__( 'Hierarchy Guard has recorded old page URLs, but nothing on this site redirects them yet.', 'hierarchy-guard' ),
					esc_url( Settings_Section::redirects_url() ),
					esc_html__( 'Set up redirects', 'hierarchy-guard' )
```
with:
```php
					esc_html__( 'Hierarchy Guard has recorded old page URLs, but nothing on this site redirects them yet.', 'hierarchy-guard' ),
					esc_url( Settings_Page::redirects_url() ),
					esc_html__( 'Set up redirects', 'hierarchy-guard' )
```

In `includes/HierarchyGuard/Admin/Assets.php`, replace:
```php
			],
			'settingsUrl' => current_user_can( 'manage_options' ) ? Settings_Section::redirects_url() : '',
			'policyUrl'   => current_user_can( 'manage_options' ) ? Settings_Section::policy_url() : '',
			'policy'      => Settings::trash_policy(),
```
with:
```php
			],
			'settingsUrl' => current_user_can( 'manage_options' ) ? Settings_Page::redirects_url() : '',
			'policyUrl'   => current_user_can( 'manage_options' ) ? Settings_Page::policy_url() : '',
			'policy'      => Settings::trash_policy(),
```

In `README.md`, make these replacements.

Replace:
```markdown

Shows the parent and child structure of pages in wp-admin, warns before a slug, parent or trash change breaks subpage URLs, and records every old URL so it can reach the page it leads to now. A page published, moved or renamed onto a path another page used to have takes that URL over, and the old URLs of the earlier page's subpages under it stop redirecting. Built-in redirects are off by default: with a redirect plugin active, Hierarchy Guard lists the redirects to add there; with none, turn on Redirects in Settings › Writing › Page hierarchy.

```
with:
```markdown

Shows the parent and child structure of pages in wp-admin, warns before a slug, parent or trash change breaks subpage URLs, and records every old URL so it can reach the page it leads to now. A page published, moved or renamed onto a path another page used to have takes that URL over, and the old URLs of the earlier page's subpages under it stop redirecting. Built-in redirects are off by default: with a redirect plugin active, Hierarchy Guard lists the redirects to add there; with none, turn on Redirects in Settings › Hierarchy Guard.

```

Replace:
```markdown

By default, trashing a page moves its subpages up one level, so they don't end up under a `__trashed` URL, and records their old URLs. With Redirects on, visitors on an old URL reach the new one. While the page is in the trash those redirects are temporary, so browsers don't keep them after a restore. The same section can trash the subpages too, or leave them where they are. A subpage the user who trashed the page can't move or trash stays under it. Restoring the page, whether by Undo, Restore, or a status change over the REST API or WP-CLI, moves the subpages back, and those trashed with it come back with the status they had.

```
with:
```markdown

By default, trashing a page moves its subpages up one level, so they don't end up under a `__trashed` URL, and records their old URLs. With Redirects on, visitors on an old URL reach the new one. While the page is in the trash those redirects are temporary, so browsers don't keep them after a restore. The Settings tab of Settings › Hierarchy Guard can trash the subpages too, or leave them where they are. A subpage the user who trashed the page can't move or trash stays under it. Restoring the page, whether by Undo, Restore, or a status change over the REST API or WP-CLI, moves the subpages back, and those trashed with it come back with the status they had.

```

Run: `grep -rn "options-writing\|Settings_Section\|Settings › Writing" includes src tests README.md`
Expected: no output.

- [ ] **Step 6: Run the tests to verify they pass**

Run: `npm run test:php -- --filter 'SettingsPageTest|AssetsTest|NoticesTest|SettingsTest'` and `npm run test:js`
Expected: PASS.

Run: `npm run test:php` then `composer lint`, `npm run lint:js`
Expected: the whole suite passes, with the new tests counted; lint exits 0. Run `composer lint:fix` first if it reports alignment; formatting-only changes are not deviations.

- [ ] **Step 7: Commit**

```bash
git add -A includes tests src README.md
git commit -m "feat: move the settings to Settings › Hierarchy Guard"
```

### Task 7: The Redirects tab: table, notices, Delete and bulk Delete

**Files:**
- Create: `includes/HierarchyGuard/Admin/Redirects_Table.php`, `includes/HierarchyGuard/Admin/Redirect_Edit.php`, `src/redirects/confirm.js`, `src/redirects/index.js`
- Modify: `includes/HierarchyGuard/Admin/Settings_Page.php` (records, load hook, the Redirects tab), `includes/HierarchyGuard/Plugin.php`, `includes/HierarchyGuard/Admin/Assets.php` (the `redirects` bundle on the tab), `webpack.config.js` (its entry)
- Test: `tests/phpunit/RedirectsTabTest.php` (new), `tests/phpunit/SettingsPageTest.php`, `tests/phpunit/AssetsTest.php`, `src/redirects/test/confirm.test.js` (new)

**Interfaces:**
- Consumes: `Records` (Task 5), `Settings_Page::url()` (Task 6), `Providers::detect()`, `Settings::serve_redirects()`, `Subtree::count_cap()`.
- Produces: `Admin\Redirects_Table extends WP_List_Table` (`singular` redirect, `plural` redirects, screen `settings_page_hierarchy-guard`): columns `cb`, `old` (primary), `target`, `http_code` (not `code`: a cell carries its key as a class, and core's admin CSS sets `.code` in a monospace font), `status`; bulk action `delete`; row action Delete; a post type filter when two or more types are supported, whose argument is `TYPE_ARG = 'redirect_type'` (wp-admin/admin.php reads `post_type` before the page loads and then refuses to load a plugin page under Settings); static `search()` (percent-decoded before `sanitize_text_field()`, which strips octets), `post_type()`, `old_label( array $item ): string` (escaped HTML, the old URL in `<code>`), `status_label( string $status ): string`; `is_capped(): bool`; `get_pagenum()` returns the page `Records::page()` showed; `pagination()` shows the count as "1,000+ items" past the cap. URLs in the Redirects to column sit in `<span dir="ltr">`. Empty states: "No redirects found." under a type filter, "No redirects match your search.", "No redirects have been recorded yet."
- Produces: `Admin\Redirect_Edit::__construct( Records $records )`, `NONCE_DELETE = 'hierarchy_guard_delete_'` (plus the record ID), `NONCE_BULK = 'bulk-redirects'` (the table's own `bulk-{plural}` nonce), `delete_url( string $id ): string`, `confirm_one( string $old ): string` ("Delete the redirect from %s?" and "This can’t be undone.", two strings), `handle(): ?string` (where to redirect, or null to print the page). It runs nothing when `filter_action` is set, as core's `current_action()` does, then reads `action`, then `action2`, so the bottom bulk selector works without JavaScript; it checks `manage_options` (else `wp_die()` 403) and the nonce (`check_admin_referer()`) before it changes anything. A request carrying the bulk nonce is a bulk request: with no rows ticked it returns to the list, and it deletes at most `Records::PER_PAGE` string IDs. Notices, in the spec's words ("Redirect deleted. `<code>/about/team/</code>` no longer redirects through it."), go through `add_settings_error()` and the `settings_errors` transient with `settings-updated=true`, as `options.php` does, so core's options header prints them.
- Produces: `Settings_Page::__construct( Providers $providers, Records $records )`, `current_tab(): string` (`redirects` or `settings`), `load()` on the page's `load-{hook}`: runs `Redirect_Edit::handle()` on the Redirects tab, then `wp_safe_redirect()` and `exit`; otherwise sets the screen's list headings for screen readers and filters `admin_title` ("Redirects ‹ …"). The Redirects tab prints "Search results for:" beside the heading while searching, a notice while `Ledger::building()`, first written as `Ledger::backfilling()` ("The list of recorded redirects is still being built. Older redirects appear here once it’s done."), and the redirects-off notice with a link to the Settings tab's Redirects field.
- Produces: the `redirects` bundle (`src/redirects/`, built to `build/redirects.js`), which `Assets::enqueue_admin()` enqueues on `settings_page_hierarchy-guard` when the tab is Redirects. `confirm.js` exports `linkQuestion( target )` (the `data-hierarchy-guard-confirm` of the Delete link clicked, or null), `bulkQuestion( form, submitter )` (for `#doaction` or `#doaction2` with Delete chosen in that selector and rows ticked: the form's `data-hierarchy-guard-confirm`, or the one ticked box's `data-hierarchy-guard-confirm-one`; null for Filter, the search or anything else) and `guard( event, question, ask )` (asks, and calls `preventDefault()` on Cancel). `index.js` binds them to `click` and `submit` with `window.confirm()`. The questions come from the page, so the bundle needs no translations. Without JavaScript the Delete runs directly, as in core.

- [ ] **Step 1: Write the failing tests**

Create `tests/phpunit/RedirectsTabTest.php`:
```php
<?php
/**
 * The Redirects tab of Settings › Hierarchy Guard.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Admin\Redirect_Edit;
use HierarchyGuard\Admin\Settings_Page;
use HierarchyGuard\Ledger;
use HierarchyGuard\Post_Types;
use HierarchyGuard\Redirects\Providers;
use HierarchyGuard\Redirects\Records;
use HierarchyGuard\Settings;
use WPDieException;
use WP_UnitTestCase;

/**
 * @covers \HierarchyGuard\Admin\Redirects_Table
 * @covers \HierarchyGuard\Admin\Redirect_Edit
 * @covers \HierarchyGuard\Admin\Settings_Page
 */
final class RedirectsTabTest extends WP_UnitTestCase {

	use Trash_Fixtures;

	private Ledger $ledger;

	private Records $records;

	private Settings_Page $page;

	/**
	 * Page IDs.
	 *
	 * @var array<string,int>
	 */
	private array $ids;

	public function set_up(): void {
		parent::set_up();
		require_once ABSPATH . 'wp-admin/includes/template.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		delete_option( Settings::OPTION );
		delete_option( Ledger::ROOT_OPTION );
		delete_option( Ledger::ROOT_TARGETS );
		delete_option( Ledger::RECENT );
		delete_option( Ledger::RECENT_STATE );
		delete_transient( 'settings_errors' );
		Post_Types::reset();
		$this->set_permalink_structure( '/%postname%/' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->ledger  = new Ledger();
		$this->records = new Records( $this->ledger );
		$this->page    = new Settings_Page( new Providers(), $this->records );
		$this->ids     = $this->make_tree();
		set_current_screen( 'settings_page_hierarchy-guard' );
		$_GET['tab'] = 'redirects';
	}

	public function tear_down(): void {
		foreach ( [ 'tab', 'action', 'action2', 'redirect', '_wpnonce', '_wp_http_referer', 's', 'redirect_type', 'paged', 'filter_action' ] as $key ) {
			unset( $_GET[ $key ], $_REQUEST[ $key ], $_POST[ $key ] );
		}
		$GLOBALS['wp_settings_errors'] = [];
		unregister_post_type( 'hg_docs' );
		unset( $GLOBALS['submenu']['options-general.php'] );
		set_current_screen( 'front' );
		$this->set_permalink_structure( '' );
		parent::tear_down();
	}

	/**
	 * The page as it prints.
	 *
	 * @return string
	 */
	private function rendered(): string {
		ob_start();
		$this->page->render();
		return (string) ob_get_clean();
	}

	/**
	 * Puts a link's query into the request, as a click on it would.
	 *
	 * @param string $url Link.
	 */
	private function follow( string $url ): void {
		wp_parse_str( (string) wp_parse_url( html_entity_decode( $url ), PHP_URL_QUERY ), $query );
		foreach ( $query as $key => $value ) {
			$_GET[ $key ]     = $value;
			$_REQUEST[ $key ] = $value;
		}
	}

	/**
	 * The notice an action kept for the next page load.
	 *
	 * @return array{message:string,type:string}
	 */
	private function kept_notice(): array {
		$kept = get_transient( 'settings_errors' );
		$this->assertIsArray( $kept );
		$last = end( $kept );
		return [
			'message' => (string) $last['message'],
			'type'    => (string) $last['type'],
		];
	}

	/**
	 * The ID of the record on a page for a path.
	 *
	 * @param int    $holder Holder.
	 * @param string $path   Old path.
	 * @return string
	 */
	private function recorded( int $holder, string $path ): string {
		$this->ledger->record( $holder, $path );
		return Records::id( $holder, 'page', $path );
	}

	public function test_the_tab_is_core_s_list_table_with_four_columns_and_nothing_to_add(): void {
		$this->recorded( $this->ids['about'], 'old-about' );

		$html = $this->rendered();

		$this->assertMatchesRegularExpression( '#<a href="[^"]*page=hierarchy-guard&\#038;tab=redirects" class="nav-tab nav-tab-active" aria-current="page">Redirects</a>#', $html );
		$this->assertStringContainsString( 'class="wp-list-table widefat fixed striped table-view-list redirects"', $html );
		foreach ( [ 'Old URL', 'Redirects to', 'Code', 'Status' ] as $column ) {
			$this->assertMatchesRegularExpression( '#<th scope="col" id=\'[a-z_]+\' class=\'manage-column column-[a-z_]+[^\']*\'\s*>' . $column . '</th>#', $html, $column );
		}
		$this->assertStringContainsString( '<td class=\'http_code column-http_code\'', $html, 'Not a cell of class "code": core’s CSS sets that in a monospace font.' );
		$this->assertStringContainsString( '<option value="delete">Delete</option>', $html );
		$this->assertMatchesRegularExpression( '#id="search-submit" class="button[^"]*" value="Search Redirects"#', $html );
		$this->assertStringContainsString( '<input type="hidden" name="page" value="hierarchy-guard"><input type="hidden" name="tab" value="redirects">', $html );
		$this->assertStringContainsString( 'name="_wpnonce" value="' . wp_create_nonce( Redirect_Edit::NONCE_BULK ) . '"', $html );
		$this->assertStringNotContainsString( 'page-title-action', $html, 'There is no Add button.' );
		$this->assertStringNotContainsString( 'options.php', $html, 'The Settings tab’s form is not on this tab.' );
	}

	public function test_each_column_reads_as_the_spec_has_it(): void {
		update_option( Settings::OPTION, [ 'serve_redirects' => true ] );
		$draft = $this->page( 'whats-on', 0, 'draft' );
		$this->recorded( $this->ids['about'], 'old-about' );
		$this->recorded( $draft, 'events' );
		$this->recorded( $this->ids['team'], 'careers' );
		$this->ledger->set_target( 'page', 'careers', $this->ids['team'], 'https://jobs.example.org/', 302 );
		$this->ledger->record_root( 'page', 'archive' );

		$html = $this->rendered();

		$this->assertStringContainsString( '<code>/old-about/</code> and the URLs under it', $html, 'In code, so it reads left to right in a right-to-left language too.' );
		$this->assertStringContainsString( '<code>/careers/</code><', $html, 'A URL target covers its exact old URL only.' );
		$this->assertStringContainsString( '<code>/archive/</code> and the URLs under it', $html );
		$this->assertStringContainsString( '<a href="' . esc_url( get_edit_post_link( $this->ids['about'] ) ) . '">About</a><br><span dir="ltr">/company/about/</span>', $html );
		$this->assertStringContainsString( '">Whats-on</a> &mdash; <span class=\'post-state\'>Draft</span><br><span dir="ltr">/?page_id=' . $draft . '</span>', $html );
		$this->assertStringContainsString( '<span dir="ltr">https://jobs.example.org/</span>', $html );
		$this->assertStringContainsString( '>Top level<', $html );
		$this->assertSame( 3, substr_count( $html, '>Automatic<' ) );
		$this->assertStringContainsString( '>302<', $html );
		$this->assertSame( 3, substr_count( $html, '>Active<' ) );
		$this->assertStringContainsString( '>Not active: the page it points to isn’t published<', $html );
		$this->assertStringContainsString( '<label class="screen-reader-text" for="cb-select-' . Records::id( $this->ids['about'], 'page', 'old-about' ) . '">Select the redirect from /old-about/</label>', $html );
		$this->assertStringContainsString( 'aria-label="Delete the redirect from /old-about/" data-hierarchy-guard-confirm="Delete the redirect from /old-about/? This can’t be undone."', $html );
		$this->assertStringContainsString( 'data-hierarchy-guard-confirm-one="Delete the redirect from /old-about/? This can’t be undone."', $html, 'A bulk Delete of one row asks about that row.' );
		$this->assertStringContainsString( '<form method="get" id="hierarchy-guard-redirects" data-hierarchy-guard-confirm="Delete the selected redirects? This can’t be undone.">', $html );
	}

	public function test_the_post_type_filter_appears_only_when_two_types_are_supported(): void {
		$this->recorded( $this->ids['about'], 'old-about' );
		$this->assertStringNotContainsString( 'name="redirect_type"', $this->rendered() );

		register_post_type(
			'hg_docs',
			[
				'public'       => true,
				'hierarchical' => true,
				'label'        => 'Docs',
			]
		);
		Post_Types::reset();
		$html = $this->rendered();
		$this->assertStringContainsString( '<select name="redirect_type" id="hierarchy-guard-post-type">', $html );
		$this->assertStringContainsString( '<option value="">All post types</option>', $html );
		$this->assertStringContainsString( '<option value="hg_docs">Docs</option>', $html );

		$_GET['redirect_type']     = 'hg_docs';
		$_REQUEST['redirect_type'] = 'hg_docs';
		$html                      = $this->rendered();
		$this->assertStringContainsString( '<option value="hg_docs" selected=\'selected\'>Docs</option>', $html );
		$this->assertStringContainsString( 'No redirects found.', $html, 'Under a filter, not “No redirects have been recorded yet.”' );
	}

	public function test_no_argument_of_the_tab_is_one_wp_admin_reads_before_the_page_loads(): void {
		register_post_type(
			'hg_docs',
			[
				'public'       => true,
				'hierarchical' => true,
			]
		);
		Post_Types::reset();
		$id                        = $this->recorded( $this->ids['about'], 'old-about' );
		$_GET['redirect_type']     = 'page';
		$_REQUEST['redirect_type'] = 'page';
		$html                      = $this->rendered();
		$this->follow( Redirect_Edit::delete_url( $id ) );
		$after = ( new Redirect_Edit( $this->records ) )->handle();

		// wp-admin/admin.php sets $typenow from post_type and then cannot find a plugin page under Settings; it reads taxonomy too.
		preg_match_all( '#name="([^"]+)"#', $html, $names );
		$this->assertEmpty( array_intersect( [ 'post_type', 'taxonomy' ], $names[1] ) );
		$this->assertStringNotContainsString( 'post_type=', $html );
		$this->assertStringContainsString( 'redirect_type=page', $after );
		$this->assertStringNotContainsString( 'post_type=', $after );
	}

	public function test_rows_come_twenty_a_page(): void {
		for ( $i = 1; $i <= 21; $i++ ) {
			$this->recorded( $this->ids['about'], sprintf( 'old-%02d', $i ) );
		}

		$html = $this->rendered();
		$this->assertStringContainsString( '21 items', $html );
		$this->assertSame( 20, substr_count( $html, 'name="redirect[]"' ) );

		$_GET['paged']     = '2';
		$_REQUEST['paged'] = '2';
		$html              = $this->rendered();
		$this->assertSame( 1, substr_count( $html, 'name="redirect[]"' ) );
		$this->assertStringContainsString( '/old-21/', $html );
	}

	public function test_the_notices_above_the_table(): void {
		$this->recorded( $this->ids['about'], 'old-about' );

		$html = $this->rendered();
		$this->assertStringContainsString( 'Built-in redirects are off, so these redirects aren’t served. Turn them on in the <a href="' . esc_url( Settings_Page::redirects_url() ) . '">Settings tab</a>.', $html );
		$this->assertStringContainsString( 'notice notice-warning inline', $html );
		$this->assertStringNotContainsString( 'is active.', $html );

		update_option( Settings::OPTION, [ 'serve_redirects' => true ] );
		$provider = static function ( array $providers ): array {
			$providers['redirection']['active'] = true;
			return $providers;
		};
		add_filter( 'hierarchy_guard_redirect_providers', $provider );
		$html = $this->rendered();
		remove_filter( 'hierarchy_guard_redirect_providers', $provider );

		$this->assertStringNotContainsString( 'Built-in redirects are off', $html );
		$this->assertStringContainsString( 'Redirection is active. These are the redirects Hierarchy Guard recorded; its notices after each change offer to add them in Redirection.', $html );

		$cap = static fn(): int => 1;
		add_filter( 'hierarchy_guard_count_cap', $cap );
		$this->recorded( $this->ids['team'], 'old-team' );
		$html = $this->rendered();
		remove_filter( 'hierarchy_guard_count_cap', $cap );
		$this->assertStringContainsString( 'Only the newest 1 recorded redirect is listed. To find another, search for its full old URL.', $html );
		$this->assertStringContainsString( '<span class="displaying-num">1+ item</span>', $html, 'Past the cap, the count is a lower bound.' );
	}

	public function test_delete_from_a_row_deletes_the_record_and_says_its_old_url_no_longer_redirects_through_it(): void {
		$id = $this->recorded( $this->ids['about'], 'old-about' );
		$this->ledger->set_target( 'page', 'old-about', $this->ids['about'], '', 302 );
		$this->follow( Redirect_Edit::delete_url( $id ) );

		$url = ( new Redirect_Edit( $this->records ) )->handle();

		$this->assertSame( admin_url( 'options-general.php?page=hierarchy-guard&tab=redirects&settings-updated=true' ), $url );
		$this->assertSame( [], $this->ledger->paths_for( $this->ids['about'] ) );
		$this->assertFalse( metadata_exists( 'post', $this->ids['about'], Ledger::target_key( 'page', 'old-about' ) ) );
		$this->assertSame(
			[
				'message' => 'Redirect deleted. <code>/old-about/</code> no longer redirects through it.',
				'type'    => 'success',
			],
			$this->kept_notice()
		);
	}

	public function test_a_delete_without_its_own_nonce_deletes_nothing(): void {
		$id    = $this->recorded( $this->ids['about'], 'old-about' );
		$other = $this->recorded( $this->ids['team'], 'old-team' );
		$this->follow( Redirect_Edit::delete_url( $other ) );
		$_GET['redirect']     = $id;
		$_REQUEST['redirect'] = $id;

		try {
			( new Redirect_Edit( $this->records ) )->handle();
			$this->fail( 'Another record’s nonce must not delete this one.' );
		} catch ( WPDieException $refused ) {
			unset( $refused );
		}
		$this->assertSame( [ 'old-about' ], $this->ledger->paths_for( $this->ids['about'] ) );
	}

	public function test_bulk_delete_works_from_either_selector(): void {
		$ids = [ $this->recorded( $this->ids['about'], 'old-about' ), $this->recorded( $this->ids['team'], 'old-team' ) ];
		$this->recorded( $this->ids['history'], 'old-history' );
		$_REQUEST['action']   = '-1';
		$_REQUEST['action2']  = 'delete';
		$_REQUEST['redirect'] = $ids;
		$_REQUEST['_wpnonce'] = wp_create_nonce( Redirect_Edit::NONCE_BULK );

		$url = ( new Redirect_Edit( $this->records ) )->handle();

		$this->assertStringContainsString( 'settings-updated=true', $url );
		$this->assertSame( [], $this->ledger->paths_for( $this->ids['about'] ) );
		$this->assertSame( [], $this->ledger->paths_for( $this->ids['team'] ) );
		$this->assertSame( [ 'old-history' ], $this->ledger->paths_for( $this->ids['history'] ) );
		$this->assertSame( '2 redirects deleted. Their old URLs no longer redirect through them.', $this->kept_notice()['message'] );
	}

	public function test_a_bulk_delete_without_the_table_s_nonce_deletes_nothing(): void {
		$_REQUEST['action']   = 'delete';
		$_REQUEST['redirect'] = [ $this->recorded( $this->ids['about'], 'old-about' ) ];
		$_REQUEST['_wpnonce'] = 'nope';

		$this->expectException( WPDieException::class );
		try {
			( new Redirect_Edit( $this->records ) )->handle();
		} finally {
			$this->assertSame( [ 'old-about' ], $this->ledger->paths_for( $this->ids['about'] ) );
		}
	}

	public function test_without_manage_options_nothing_is_deleted(): void {
		$id = $this->recorded( $this->ids['about'], 'old-about' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->follow( Redirect_Edit::delete_url( $id ) );

		$this->expectException( WPDieException::class );
		try {
			( new Redirect_Edit( $this->records ) )->handle();
		} finally {
			$this->assertSame( [ 'old-about' ], $this->ledger->paths_for( $this->ids['about'] ) );
		}
	}

	public function test_a_link_to_a_record_deleted_since_says_so(): void {
		$id = $this->recorded( $this->ids['about'], 'old-about' );
		$this->ledger->forget( $this->ids['about'], 'old-about' );
		$this->follow( Redirect_Edit::delete_url( $id ) );

		( new Redirect_Edit( $this->records ) )->handle();

		$this->assertSame(
			[
				'message' => 'This redirect no longer exists.',
				'type'    => 'error',
			],
			$this->kept_notice()
		);
	}

	public function test_a_search_drops_the_table_form_s_nonce_from_the_address_and_keeps_the_rest(): void {
		$_REQUEST['_wp_http_referer'] = '/wp-admin/options-general.php';
		$_REQUEST['_wpnonce']         = 'x';
		$_REQUEST['action']           = '-1';
		$_REQUEST['s']                = 'about us';

		$this->assertSame( admin_url( 'options-general.php?page=hierarchy-guard&tab=redirects&s=about%20us' ), ( new Redirect_Edit( $this->records ) )->handle() );

		unset( $_REQUEST['_wp_http_referer'] );
		$this->assertNull( ( new Redirect_Edit( $this->records ) )->handle(), 'A plain load prints the page.' );
	}

	public function test_the_actions_run_on_the_page_s_load_hook(): void {
		global $wp_filter;

		$this->page->add_page();

		// The suffix core gives the page: settings_page_hierarchy-guard once core's menu is built.
		$hook   = 'load-' . get_plugin_page_hookname( Settings_Page::SLUG, 'options-general.php' );
		$hooked = false;
		foreach ( $wp_filter[ $hook ]->callbacks[10] ?? [] as $callback ) {
			$hooked = $hooked || ( is_array( $callback['function'] ) && $this->page === $callback['function'][0] && 'load' === $callback['function'][1] );
		}
		$this->assertTrue( $hooked );
	}

	public function test_a_crafted_bulk_request_deletes_at_most_one_page_and_ignores_nested_values(): void {
		$ids = [];
		for ( $i = 1; $i <= 25; $i++ ) {
			$ids[] = $this->recorded( $this->ids['about'], 'bulk-' . $i );
		}
		$ids[]                = [ 'nested' => $ids[0] ];
		$_REQUEST['action']   = 'delete';
		$_REQUEST['redirect'] = $ids;
		$_REQUEST['_wpnonce'] = wp_create_nonce( Redirect_Edit::NONCE_BULK );

		( new Redirect_Edit( $this->records ) )->handle();

		$this->assertCount( 5, $this->ledger->paths_for( $this->ids['about'] ), 'Twenty deleted, one page of the table.' );
		$this->assertSame( '20 redirects deleted. Their old URLs no longer redirect through them.', $this->kept_notice()['message'] );
	}

	public function test_filter_never_runs_a_bulk_delete_and_an_empty_selection_returns_to_the_list(): void {
		$id                        = $this->recorded( $this->ids['about'], 'old-about' );
		$_REQUEST['action']        = 'delete';
		$_REQUEST['redirect']      = [ $id ];
		$_REQUEST['_wpnonce']      = wp_create_nonce( Redirect_Edit::NONCE_BULK );
		$_REQUEST['filter_action'] = 'Filter';

		$this->assertNull( ( new Redirect_Edit( $this->records ) )->handle(), 'Filter prints the list, as core’s tables do.' );
		$this->assertSame( [ 'old-about' ], $this->ledger->paths_for( $this->ids['about'] ) );

		unset( $_REQUEST['filter_action'], $_REQUEST['redirect'] );
		$_REQUEST['s'] = 'about';
		$this->assertSame( admin_url( 'options-general.php?page=hierarchy-guard&tab=redirects&s=about' ), ( new Redirect_Edit( $this->records ) )->handle(), 'Delete with nothing ticked, without JavaScript: back to the list.' );
		$this->assertSame( [ 'old-about' ], $this->ledger->paths_for( $this->ids['about'] ) );
		$this->assertFalse( get_transient( 'settings_errors' ) );
	}

	public function test_a_bulk_delete_of_records_all_gone_since_says_so_in_the_plural(): void {
		$ids = [ $this->recorded( $this->ids['about'], 'old-about' ), $this->recorded( $this->ids['team'], 'old-team' ) ];
		$this->ledger->forget( $this->ids['about'], 'old-about' );
		$this->ledger->forget( $this->ids['team'], 'old-team' );
		$_REQUEST['action']   = 'delete';
		$_REQUEST['redirect'] = $ids;
		$_REQUEST['_wpnonce'] = wp_create_nonce( Redirect_Edit::NONCE_BULK );

		( new Redirect_Edit( $this->records ) )->handle();

		$this->assertSame( 'These redirects no longer exist.', $this->kept_notice()['message'] );
	}

	public function test_a_percent_encoded_search_finds_the_old_url_it_names(): void {
		$this->recorded( $this->ids['about'], 'café/équipe' );
		$this->recorded( $this->ids['team'], 'other' );
		$_REQUEST['s'] = home_url( '/caf%C3%A9/%C3%A9quipe/' );

		$html = $this->rendered();

		$this->assertStringContainsString( '<code>/café/équipe/</code>', $html );
		$this->assertStringNotContainsString( '/other/', $html );
		$this->assertStringContainsString( '<span class="subtitle">Search results for: <strong>' . esc_html( home_url( '/café/équipe/' ) ) . '</strong></span>', $html );
	}

	public function test_a_page_past_the_last_one_shows_the_last_one_with_its_rows(): void {
		for ( $i = 1; $i <= 21; $i++ ) {
			$this->recorded( $this->ids['about'], sprintf( 'old-%02d', $i ) );
		}
		$this->ledger->forget( $this->ids['about'], 'old-21' );
		$_GET['paged']     = '2';
		$_REQUEST['paged'] = '2';

		$html = $this->rendered();

		$this->assertSame( 20, substr_count( $html, 'name="redirect[]"' ) );
		$this->assertStringNotContainsString( 'No redirects have been recorded yet.', $html );
		$this->assertMatchesRegularExpression( "#id='current-page-selector' type='text'\\s+name='paged' value='1'#", $html, 'The pager agrees with the rows.' );
	}

	public function test_the_list_is_named_for_screen_readers_and_in_the_title(): void {
		$this->recorded( $this->ids['about'], 'old-about' );

		$this->page->load();
		$html = $this->rendered();

		$this->assertMatchesRegularExpression( '#<h2 class=[\'"]screen-reader-text[\'"]>Redirects list</h2>#', $html );
		$this->assertSame( 10, has_filter( 'admin_title', [ $this->page, 'admin_title' ] ) );
		$this->assertSame( 'Redirects ‹ Hierarchy Guard ‹ Site — WordPress', $this->page->admin_title( 'Hierarchy Guard ‹ Site — WordPress' ) );
		$this->assertStringContainsString( '<hr class="wp-header-end">', $html );
		remove_filter( 'admin_title', [ $this->page, 'admin_title' ] );
	}

	public function test_while_the_newest_list_is_built_the_tab_says_so(): void {
		$this->recorded( $this->ids['about'], 'old-about' );
		$this->assertStringNotContainsString( 'still being built', $this->rendered() );

		update_option( Ledger::RECENT_STATE, 'building' );
		$this->assertStringContainsString( '<div class="notice notice-info inline"><p>The list of recorded redirects is still being built. Older redirects appear here once it’s done.</p></div>', $this->rendered() );

		update_option( Ledger::RECENT_STATE, 'done' );
		$this->assertStringNotContainsString( 'still being built', $this->rendered() );
	}
}
```

In `tests/phpunit/SettingsPageTest.php`, make these replacements.

Replace:
```php
use HierarchyGuard\Admin\Settings_Page;
use HierarchyGuard\Post_Types;
use HierarchyGuard\Redirects\Providers;
use HierarchyGuard\Settings;
```
with:
```php
use HierarchyGuard\Admin\Settings_Page;
use HierarchyGuard\Ledger;
use HierarchyGuard\Post_Types;
use HierarchyGuard\Redirects\Providers;
use HierarchyGuard\Redirects\Records;
use HierarchyGuard\Settings;
```

Replace:
```php
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->page = new Settings_Page( new Providers() );
	}
```
with:
```php
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->page = new Settings_Page( new Providers(), new Records( new Ledger() ) );
	}
```

In `tests/phpunit/AssetsTest.php`, replace:
```php
		rmdir( $this->dir );
		wp_dequeue_script( 'hierarchy-guard-list-table' );
		wp_deregister_script( 'hierarchy-guard-list-table' );
		wp_dequeue_style( 'wp-components' );
```
with:
```php
		rmdir( $this->dir );
		foreach ( [ 'list-table', 'redirects' ] as $bundle ) {
			wp_dequeue_script( 'hierarchy-guard-' . $bundle );
			wp_deregister_script( 'hierarchy-guard-' . $bundle );
		}
		unset( $_GET['tab'] );
		wp_dequeue_style( 'wp-components' );
```

Append to the class in `tests/phpunit/AssetsTest.php`:
```php
	public function test_the_redirects_tab_gets_the_bundle_that_asks_before_a_delete(): void {
		$this->build( 'redirects', [ 'wp-dom-ready' ] );
		$assets = new Assets( new Providers(), $this->dir );

		$assets->enqueue_admin( 'settings_page_hierarchy-guard' );
		$this->assertFalse( wp_script_is( 'hierarchy-guard-redirects', 'enqueued' ), 'Not on the Settings tab.' );

		$_GET['tab'] = 'redirects';
		$assets->enqueue_admin( 'settings_page_hierarchy-guard' );
		$this->assertTrue( wp_script_is( 'hierarchy-guard-redirects', 'enqueued' ) );

		wp_dequeue_script( 'hierarchy-guard-redirects' );
		$assets->enqueue_admin( 'options-general.php' );
		$this->assertFalse( wp_script_is( 'hierarchy-guard-redirects', 'enqueued' ), 'Not on another settings page.' );
	}
```

Create `src/redirects/test/confirm.test.js`:
```js
/**
 * What a Delete on the Redirects tab asks, and what Cancel does.
 */
import { describe, expect, it, vi } from 'vitest';
import { bulkQuestion, guard, linkQuestion } from '../confirm';

const ONE = 'Delete the redirect from /about/team/? This can’t be undone.';
const MANY = 'Delete the selected redirects? This can’t be undone.';

/**
 * A stand-in element with attributes.
 *
 * @param {Object} attrs Attributes.
 * @return {Object} Element.
 */
const element = ( attrs ) => ( {
	hasAttribute: ( key ) => key in attrs,
	getAttribute: ( key ) => ( key in attrs ? attrs[ key ] : null ),
} );

/**
 * A stand-in of the table's form.
 *
 * @param {Object} options          The form's state.
 * @param {string} options.action   The top selector's value.
 * @param {string} options.action2  The bottom selector's value.
 * @param {number} options.ticked   How many rows are ticked.
 * @param {string} options.question The form's own question.
 * @return {Object} Form.
 */
const form = ( {
	action = '-1',
	action2 = '-1',
	ticked = 0,
	question = MANY,
} ) => {
	const boxes = Array.from( { length: ticked }, ( _, i ) =>
		element( {
			'data-hierarchy-guard-confirm-one': `Delete the redirect from /row-${ i }/? This can’t be undone.`,
		} )
	);
	return {
		...element( { 'data-hierarchy-guard-confirm': question } ),
		querySelector: ( selector ) =>
			( {
				'select[name="action"]': { value: action },
				'select[name="action2"]': { value: action2 },
			} )[ selector ] || null,
		querySelectorAll: () => boxes,
	};
};

describe( 'a Delete link', () => {
	it( 'asks the question it carries', () => {
		const link = element( { 'data-hierarchy-guard-confirm': ONE } );
		const target = { closest: () => link };
		expect( linkQuestion( target ) ).toBe( ONE );
	} );

	it( 'asks nothing for any other click', () => {
		expect( linkQuestion( { closest: () => null } ) ).toBe( null );
		expect( linkQuestion( null ) ).toBe( null );
		expect( linkQuestion( {} ) ).toBe( null );
	} );
} );

describe( 'a bulk Delete', () => {
	it( 'asks once for the selection, from either selector', () => {
		expect(
			bulkQuestion( form( { action: 'delete', ticked: 3 } ), {
				id: 'doaction',
			} )
		).toBe( MANY );
		expect(
			bulkQuestion( form( { action2: 'delete', ticked: 2 } ), {
				id: 'doaction2',
			} )
		).toBe( MANY );
	} );

	it( 'asks about the row when one is ticked', () => {
		expect(
			bulkQuestion( form( { action: 'delete', ticked: 1 } ), {
				id: 'doaction',
			} )
		).toBe( 'Delete the redirect from /row-0/? This can’t be undone.' );
	} );

	it( 'asks nothing for Filter, the search, another action or no rows', () => {
		const ticked = form( { action: 'delete', ticked: 2 } );
		expect( bulkQuestion( ticked, { id: 'post-query-submit' } ) ).toBe(
			null
		);
		expect( bulkQuestion( ticked, { id: 'search-submit' } ) ).toBe( null );
		expect( bulkQuestion( ticked, null ) ).toBe( null );
		expect(
			bulkQuestion( form( { action: 'delete' } ), { id: 'doaction' } )
		).toBe( null );
		expect(
			bulkQuestion( form( { action2: 'delete', ticked: 2 } ), {
				id: 'doaction',
			} )
		).toBe( null );
		expect( bulkQuestion( element( {} ), { id: 'doaction' } ) ).toBe(
			null
		);
	} );
} );

describe( 'the answer', () => {
	it( 'cancels the Delete on Cancel', () => {
		const event = { preventDefault: vi.fn() };
		const ask = vi.fn( () => false );
		expect( guard( event, ONE, ask ) ).toBe( false );
		expect( ask ).toHaveBeenCalledWith( ONE );
		expect( event.preventDefault ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'lets the Delete go on on OK', () => {
		const event = { preventDefault: vi.fn() };
		expect( guard( event, ONE, () => true ) ).toBe( true );
		expect( event.preventDefault ).not.toHaveBeenCalled();
	} );

	it( 'asks nothing and stops nothing without a question', () => {
		const event = { preventDefault: vi.fn() };
		const ask = vi.fn();
		expect( guard( event, null, ask ) ).toBe( true );
		expect( ask ).not.toHaveBeenCalled();
		expect( event.preventDefault ).not.toHaveBeenCalled();
	} );
} );
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npm run test:php -- --filter 'RedirectsTabTest|SettingsPageTest|AssetsTest'`
Expected: FAIL. The Redirects tab tests error with `Error: Class "HierarchyGuard\Admin\Redirect_Edit" not found` or fail because the page prints the Settings tab, and the Assets test fails because nothing is enqueued.

Run: `npm run test:js`
Expected: FAIL. `src/redirects/test/confirm.test.js` cannot import `../confirm`.

- [ ] **Step 3: Write the table**

Create `includes/HierarchyGuard/Admin/Redirects_Table.php`:
```php
<?php
/**
 * The Redirects tab's table.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Admin;

use HierarchyGuard\Paths;
use HierarchyGuard\Post_Types;
use HierarchyGuard\Redirects\Records;
use HierarchyGuard\Subtree;
use WP_List_Table;
use WP_Post;

if ( ! class_exists( WP_List_Table::class ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Core's list table of the redirects the plugin recorded: Old URL,
 * Redirects to, Code and Status, 20 rows a page sorted by old URL, a search
 * on the old URL, a post type filter when more than one type is supported,
 * and Delete. There is nothing to add. The filter's request argument is
 * TYPE_ARG, never `post_type`: wp-admin/admin.php reads that one before the
 * page loads and, for a plugin page under Settings, refuses to load it.
 */
final class Redirects_Table extends WP_List_Table {

	public const TYPE_ARG = 'redirect_type';

	/**
	 * Records.
	 *
	 * @var Records
	 */
	private Records $records;

	/**
	 * Each row's status, by record ID.
	 *
	 * @var array<string,string>
	 */
	private array $statuses = [];

	/**
	 * Whether the listing stopped at the cap.
	 *
	 * @var bool
	 */
	private bool $capped = false;

	/**
	 * The page shown, which is the last one when the request asked for one past it.
	 *
	 * @var int|null
	 */
	private ?int $shown = null;

	/**
	 * Constructor.
	 *
	 * @param Records $records Records.
	 */
	public function __construct( Records $records ) {
		parent::__construct(
			[
				'singular' => 'redirect',
				'plural'   => 'redirects',
				'ajax'     => false,
				'screen'   => 'settings_page_' . Settings_Page::SLUG,
			]
		);
		$this->records = $records;
	}

	/**
	 * The search text, from the request.
	 *
	 * @return string
	 */
	public static function search(): string {
		// Decoded first: sanitize_text_field() strips percent-encoded octets, as a pasted URL has them.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- A search reads, it changes nothing; sanitize_text_field() runs on the decoded text.
		return isset( $_REQUEST['s'] ) && is_string( $_REQUEST['s'] ) ? trim( sanitize_text_field( rawurldecode( wp_unslash( $_REQUEST['s'] ) ) ) ) : '';
	}

	/**
	 * The post type filtered on, from the request: a supported type or ''.
	 *
	 * @return string
	 */
	public static function post_type(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A filter reads, it changes nothing.
		$type = isset( $_REQUEST[ self::TYPE_ARG ] ) && is_string( $_REQUEST[ self::TYPE_ARG ] ) ? sanitize_key( wp_unslash( $_REQUEST[ self::TYPE_ARG ] ) ) : '';
		return Post_Types::is_supported( $type ) ? $type : '';
	}

	/**
	 * Columns. A cell carries its column's key as a class, and core's admin
	 * CSS sets `.code` in a monospace font, so the Code column's key is http_code.
	 *
	 * @return array<string,string>
	 */
	public function get_columns(): array {
		return [
			'cb'        => '<input type="checkbox" />',
			'old'       => __( 'Old URL', 'hierarchy-guard' ),
			'target'    => __( 'Redirects to', 'hierarchy-guard' ),
			'http_code' => __( 'Code', 'hierarchy-guard' ),
			'status'    => __( 'Status', 'hierarchy-guard' ),
		];
	}

	/**
	 * Bulk actions: Delete.
	 *
	 * @return array<string,string>
	 */
	protected function get_bulk_actions(): array {
		return [ 'delete' => __( 'Delete', 'hierarchy-guard' ) ];
	}

	/**
	 * The column with the row actions.
	 *
	 * @return string
	 */
	protected function get_primary_column_name(): string {
		return 'old';
	}

	/**
	 * Reads one page of records and their statuses.
	 */
	public function prepare_items(): void {
		$this->_column_headers = [ $this->get_columns(), [], [], 'old' ];

		$page           = $this->records->page( self::post_type(), self::search(), $this->get_pagenum() );
		$this->shown    = $page['page'];
		$this->items    = $page['rows'];
		$this->capped   = $page['capped'];
		$this->statuses = $this->records->statuses( $this->items );
		$this->set_pagination_args(
			[
				'total_items' => $page['total'],
				'per_page'    => Records::PER_PAGE,
			]
		);
	}

	/**
	 * Whether the listing stopped at the cap.
	 *
	 * @return bool
	 */
	public function is_capped(): bool {
		return $this->capped;
	}

	/**
	 * The page shown: the records' own, once read, so the pagination links
	 * agree with the rows when the request asked for a page past the last.
	 *
	 * @return int
	 */
	public function get_pagenum(): int {
		return $this->shown ?? (int) parent::get_pagenum();
	}

	/**
	 * Core's pagination, with the count shown as "1,000+" once older
	 * records exist than the listing read, as the plugin's other counts show it.
	 *
	 * @param string $which Top or bottom.
	 */
	protected function pagination( $which ): void {
		ob_start();
		parent::pagination( $which );
		$html = (string) ob_get_clean();
		if ( $this->capped ) {
			$cap = Subtree::count_cap();
			/* translators: %s: number of redirects, such as 1,000+. */
			$count = sprintf( _n( '%s item', '%s items', $cap, 'hierarchy-guard' ), Subtree::format_count( $cap, true ) );
			$html  = (string) preg_replace( '#<span class="displaying-num">[^<]*</span>#', '<span class="displaying-num">' . esc_html( $count ) . '</span>', $html );
		}
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core's own pagination markup, with an escaped count.
	}

	/**
	 * The message for an empty table.
	 */
	public function no_items(): void {
		if ( '' !== self::post_type() ) {
			esc_html_e( 'No redirects found.', 'hierarchy-guard' );
			return;
		}
		if ( '' !== self::search() ) {
			esc_html_e( 'No redirects match your search.', 'hierarchy-guard' );
			return;
		}
		esc_html_e( 'No redirects have been recorded yet.', 'hierarchy-guard' );
	}

	/**
	 * The post type filter, when more than one type is supported.
	 *
	 * @param string $which Top or bottom.
	 */
	protected function extra_tablenav( $which ): void {
		$types = Post_Types::supported();
		if ( 'top' !== $which || count( $types ) < 2 ) {
			return;
		}
		$current = self::post_type();
		echo '<div class="alignleft actions">';
		echo '<label class="screen-reader-text" for="hierarchy-guard-post-type">' . esc_html__( 'Filter by post type', 'hierarchy-guard' ) . '</label>';
		printf( '<select name="%s" id="hierarchy-guard-post-type">', esc_attr( self::TYPE_ARG ) );
		echo '<option value="">' . esc_html__( 'All post types', 'hierarchy-guard' ) . '</option>';
		foreach ( $types as $type ) {
			$object = get_post_type_object( $type );
			printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $type ), selected( $current, $type, false ), esc_html( null !== $object ? $object->labels->name : $type ) );
		}
		echo '</select>';
		submit_button( __( 'Filter', 'hierarchy-guard' ), '', 'filter_action', false, [ 'id' => 'hierarchy-guard-filter' ] );
		echo '</div>';
	}

	/**
	 * The row's checkbox.
	 *
	 * @param array<string,mixed> $item Record.
	 * @return string
	 */
	protected function column_cb( $item ): string {
		/* translators: %s: an old URL, such as /about/team/. */
		$label = sprintf( __( 'Select the redirect from %s', 'hierarchy-guard' ), (string) $item['old'] );
		return sprintf(
			'<label class="screen-reader-text" for="cb-select-%1$s">%2$s</label><input id="cb-select-%1$s" type="checkbox" name="redirect[]" value="%1$s" data-hierarchy-guard-confirm-one="%3$s" />',
			esc_attr( (string) $item['id'] ),
			esc_html( $label ),
			esc_attr( Redirect_Edit::confirm_one( (string) $item['old'] ) )
		);
	}

	/**
	 * The old URL, and for a page target the URLs under it.
	 *
	 * @param array<string,mixed> $item Record.
	 * @return string
	 */
	protected function column_old( $item ): string {
		return self::old_label( $item );
	}

	/**
	 * Where it redirects: the page, with its states and current URL; the URL
	 * target; or "Top level" for a root-list entry.
	 *
	 * @param array<string,mixed> $item Record.
	 * @return string
	 */
	protected function column_target( $item ): string {
		$url = null !== $item['target'] ? (string) $item['target']['url'] : '';
		if ( '' !== $url ) {
			return '<span dir="ltr">' . esc_html( $url ) . '</span>';
		}
		if ( 0 === (int) $item['holder'] ) {
			return esc_html__( 'Top level', 'hierarchy-guard' );
		}
		$post = get_post( (int) $item['holder'] );
		if ( ! $post instanceof WP_Post ) {
			return '';
		}
		$title = get_the_title( $post );
		$title = '' !== $title ? $title : __( '(no title)', 'hierarchy-guard' );
		$link  = get_edit_post_link( $post );
		$name  = null !== $link ? sprintf( '<a href="%1$s">%2$s</a>', esc_url( $link ), esc_html( $title ) ) : esc_html( $title );
		$now   = get_permalink( $post );
		return $name . _post_states( $post, false ) . ( is_string( $now ) ? '<br><span dir="ltr">' . esc_html( Paths::relative( $now ) ) . '</span>' : '' );
	}

	/**
	 * Automatic, or the code chosen on Edit.
	 *
	 * @param array<string,mixed> $item Record.
	 * @return string
	 */
	protected function column_http_code( $item ): string {
		$code = null !== $item['target'] ? (int) $item['target']['code'] : 0;
		return $code > 0 ? (string) $code : esc_html__( 'Automatic', 'hierarchy-guard' );
	}

	/**
	 * Active, or why the redirect isn't served.
	 *
	 * @param array<string,mixed> $item Record.
	 * @return string
	 */
	protected function column_status( $item ): string {
		return esc_html( self::status_label( $this->statuses[ (string) $item['id'] ] ?? Records::STATUS_ACTIVE ) );
	}

	/**
	 * Delete, under the old URL.
	 *
	 * @param array<string,mixed> $item        Record.
	 * @param string              $column_name Column.
	 * @param string              $primary     Primary column.
	 * @return string
	 */
	protected function handle_row_actions( $item, $column_name, $primary ): string {
		if ( $primary !== $column_name ) {
			return '';
		}
		$id  = (string) $item['id'];
		$old = (string) $item['old'];
		/* translators: %s: an old URL, such as /about/team/. */
		$delete = sprintf( __( 'Delete the redirect from %s', 'hierarchy-guard' ), $old );
		return $this->row_actions(
			[
				'delete' => sprintf(
					'<a href="%1$s" class="submitdelete" aria-label="%2$s" data-hierarchy-guard-confirm="%3$s">%4$s</a>',
					esc_url( Redirect_Edit::delete_url( $id ) ),
					esc_attr( $delete ),
					esc_attr( Redirect_Edit::confirm_one( $old ) ),
					esc_html__( 'Delete', 'hierarchy-guard' )
				),
			]
		);
	}

	/**
	 * A record's old URL as HTML, in code so it reads left to right in a
	 * right-to-left language too, with "and the URLs under it" for a page target.
	 *
	 * @param array<string,mixed> $item Record.
	 * @return string
	 */
	public static function old_label( array $item ): string {
		$old = '<code>' . esc_html( (string) $item['old'] ) . '</code>';
		if ( null !== $item['target'] && '' !== (string) $item['target']['url'] ) {
			return $old;
		}
		/* translators: %s: an old URL, such as /about/team/. */
		return sprintf( esc_html__( '%s and the URLs under it', 'hierarchy-guard' ), $old );
	}

	/**
	 * The words for a status.
	 *
	 * @param string $status One of the Records::STATUS_ constants.
	 * @return string
	 */
	public static function status_label( string $status ): string {
		switch ( $status ) {
			case Records::STATUS_IN_USE:
				return __( 'Not active: a page uses this URL', 'hierarchy-guard' );
			case Records::STATUS_UNPUBLISHED:
				return __( 'Not active: the page it points to isn’t published', 'hierarchy-guard' );
			case Records::STATUS_OFF:
				return __( 'Not active: built-in redirects are off', 'hierarchy-guard' );
		}
		return __( 'Active', 'hierarchy-guard' );
	}
}
```

- [ ] **Step 4: Write the delete actions**

Create `includes/HierarchyGuard/Admin/Redirect_Edit.php`:
```php
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
 * Deletes recorded redirects, one from its row or in bulk. Every action
 * checks manage_options and its nonce, and ends on the Redirects tab with a
 * core notice that core's options header prints.
 */
final class Redirect_Edit {

	public const NONCE_DELETE = 'hierarchy_guard_delete_';
	public const NONCE_BULK   = 'bulk-redirects';

	/**
	 * Records.
	 *
	 * @var Records
	 */
	private Records $records;

	/**
	 * Constructor.
	 *
	 * @param Records $records Records.
	 */
	public function __construct( Records $records ) {
		$this->records = $records;
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
		$this->records->delete( $record );
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
```

- [ ] **Step 5: Add the Redirects tab to the page**

In `includes/HierarchyGuard/Admin/Settings_Page.php`, make these replacements.

Replace:
```php

use HierarchyGuard\Redirects\Providers;
use HierarchyGuard\Settings;

```
with:
```php

use HierarchyGuard\Ledger;
use HierarchyGuard\Redirects\Providers;
use HierarchyGuard\Redirects\Records;
use HierarchyGuard\Settings;
use HierarchyGuard\Subtree;

```

Replace:
```php
 * core nav tabs, the Settings tab's three fields, saved through the Settings
 * API, and a "Settings" link on the plugin's row of the Plugins screen.
 */
```
with:
```php
 * core nav tabs, the Settings tab's three fields, saved through the Settings
 * API, the Redirects tab, whose actions run on the page's load hook, and a
 * "Settings" link on the plugin's row of the Plugins screen.
 */
```

Replace:
```php
	/**
	 * Constructor.
```
with:
```php
	/**
	 * Records.
	 *
	 * @var Records
	 */
	private Records $records;

	/**
	 * The Redirects tab's actions.
	 *
	 * @var Redirect_Edit
	 */
	private Redirect_Edit $edit;

	/**
	 * Constructor.
```

Replace:
```php
	 * @param Providers $providers Redirect plugin detection.
	 */
	public function __construct( Providers $providers ) {
		$this->providers = $providers;
	}
```
with:
```php
	 * @param Providers $providers Redirect plugin detection.
	 * @param Records   $records   Recorded redirects.
	 */
	public function __construct( Providers $providers, Records $records ) {
		$this->providers = $providers;
		$this->records   = $records;
		$this->edit      = new Redirect_Edit( $records );
	}
```

Replace:
```php
	/**
	 * Adds the page under Settings.
	 */
	public function add_page(): void {
		add_options_page( __( 'Hierarchy Guard', 'hierarchy-guard' ), __( 'Hierarchy Guard', 'hierarchy-guard' ), 'manage_options', self::SLUG, [ $this, 'render' ] );
	}
```
with:
```php
	/**
	 * The tab the request asks for.
	 *
	 * @return string `redirects` or `settings`.
	 */
	public static function current_tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Which tab to show; it changes nothing.
		return isset( $_GET['tab'] ) && 'redirects' === sanitize_key( wp_unslash( $_GET['tab'] ) ) ? 'redirects' : 'settings';
	}

	/**
	 * Adds the page under Settings, and its load hook.
	 */
	public function add_page(): void {
		$hook = add_options_page( __( 'Hierarchy Guard', 'hierarchy-guard' ), __( 'Hierarchy Guard', 'hierarchy-guard' ), 'manage_options', self::SLUG, [ $this, 'render' ] );
		if ( is_string( $hook ) ) {
			add_action( 'load-' . $hook, [ $this, 'load' ] );
		}
	}

	/**
	 * Before the page prints: runs the Redirects tab's action and sends the
	 * browser on; otherwise names the tab's list for screen readers and in the
	 * browser title. Assets enqueues the tab's script, which asks before a Delete.
	 */
	public function load(): void {
		if ( 'redirects' !== self::current_tab() ) {
			return;
		}
		$url = $this->edit->handle();
		if ( null !== $url ) {
			wp_safe_redirect( $url );
			exit;
		}
		$screen = get_current_screen();
		if ( null !== $screen ) {
			$screen->set_screen_reader_content(
				[
					'heading_pagination' => __( 'Redirects list navigation', 'hierarchy-guard' ),
					'heading_list'       => __( 'Redirects list', 'hierarchy-guard' ),
				]
			);
		}
		add_filter( 'admin_title', [ $this, 'admin_title' ] );
	}

	/**
	 * The browser title of the Redirects tab: the view's name first.
	 *
	 * @param string $admin_title The admin page's title, as core builds it.
	 * @return string
	 */
	public function admin_title( $admin_title ): string {
		/* translators: 1: the view of the page, such as Redirects; 2: the page's title, as the browser shows it. */
		return sprintf( __( '%1$s ‹ %2$s', 'hierarchy-guard' ), __( 'Redirects', 'hierarchy-guard' ), (string) $admin_title );
	}
```

Replace:
```php
		}
		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Hierarchy Guard', 'hierarchy-guard' ) . '</h1>';
		$this->render_tabs( 'settings' );
		// Core moves notices to just after this, below the tabs.
		echo '<hr class="wp-header-end">';
		echo '<form action="options.php" method="post">';
		settings_fields( Settings::GROUP );
		do_settings_sections( self::SLUG );
		submit_button();
		echo '</form>';
		echo '</div>';
	}
```
with:
```php
		}
		$tab    = self::current_tab();
		$search = 'redirects' === $tab ? Redirects_Table::search() : '';
		echo '<div class="wrap">';
		if ( '' === $search ) {
			echo '<h1>' . esc_html__( 'Hierarchy Guard', 'hierarchy-guard' ) . '</h1>';
		} else {
			echo '<h1 class="wp-heading-inline">' . esc_html__( 'Hierarchy Guard', 'hierarchy-guard' ) . '</h1> ';
			/* translators: %s: the search text. */
			printf( '<span class="subtitle">%s</span>', sprintf( esc_html__( 'Search results for: %s', 'hierarchy-guard' ), '<strong>' . esc_html( $search ) . '</strong>' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Both parts are escaped.
		}
		$this->render_tabs( $tab );
		// Core moves notices to just after this, below the tabs.
		echo '<hr class="wp-header-end">';
		if ( 'redirects' === $tab ) {
			$this->render_redirects();
		} else {
			echo '<form action="options.php" method="post">';
			settings_fields( Settings::GROUP );
			do_settings_sections( self::SLUG );
			submit_button();
			echo '</form>';
		}
		echo '</div>';
	}

	/**
	 * The Redirects tab: its notices, the search box and the table, in a GET
	 * form back to this tab, as core's list screens have it.
	 */
	private function render_redirects(): void {
		$table = new Redirects_Table( $this->records );
		$table->prepare_items();

		if ( Ledger::backfilling() ) {
			$building = __( 'The list of recorded redirects is still being built.', 'hierarchy-guard' );
			$older    = __( 'Older redirects appear here once it’s done.', 'hierarchy-guard' );
			$this->inline_notice( esc_html( $building . ' ' . $older ), 'info' );
		}
		if ( ! Settings::serve_redirects() ) {
			$off = esc_html__( 'Built-in redirects are off, so these redirects aren’t served.', 'hierarchy-guard' );
			/* translators: %s: URL of the Redirects field on the Settings tab. */
			$on = sprintf( __( 'Turn them on in the <a href="%s">Settings tab</a>.', 'hierarchy-guard' ), esc_url( self::redirects_url() ) );
			$this->inline_notice( $off . ' ' . $on, 'warning' );
		}
		$provider = $this->providers->detect();
		if ( null !== $provider ) {
			$name = trim( (string) ( $provider['name'] ?? '' ) );
			if ( '' !== $name ) {
				/* translators: %s: name of the detected redirect plugin. */
				$active = sprintf( __( '%s is active.', 'hierarchy-guard' ), $name );
				/* translators: %s: name of the detected redirect plugin. */
				$recorded = sprintf( __( 'These are the redirects Hierarchy Guard recorded; its notices after each change offer to add them in %s.', 'hierarchy-guard' ), $name );
			} else {
				$active   = __( 'A redirect plugin is active.', 'hierarchy-guard' );
				$recorded = __( 'These are the redirects Hierarchy Guard recorded; its notices after each change offer to add them there.', 'hierarchy-guard' );
			}
			$this->inline_notice( esc_html( $active . ' ' . $recorded ), 'info' );
		}
		if ( $table->is_capped() ) {
			$cap = Subtree::count_cap();
			/* translators: %s: the most recorded redirects listed, such as 1,000. */
			$only    = sprintf( _n( 'Only the newest %s recorded redirect is listed.', 'Only the newest %s recorded redirects are listed.', $cap, 'hierarchy-guard' ), number_format_i18n( $cap ) );
			$another = __( 'To find another, search for its full old URL.', 'hierarchy-guard' );
			$this->inline_notice( esc_html( $only . ' ' . $another ), 'info' );
		}

		$bulk = __( 'Delete the selected redirects?', 'hierarchy-guard' ) . ' ' . __( 'This can’t be undone.', 'hierarchy-guard' );
		printf( '<form method="get" id="hierarchy-guard-redirects" data-hierarchy-guard-confirm="%s">', esc_attr( $bulk ) );
		printf( '<input type="hidden" name="page" value="%s">', esc_attr( self::SLUG ) );
		echo '<input type="hidden" name="tab" value="redirects">';
		$table->search_box( __( 'Search Redirects', 'hierarchy-guard' ), 'hierarchy-guard-search' );
		$table->display();
		echo '</form>';
	}

	/**
	 * A core notice in the page's flow.
	 *
	 * @param string $message Message, escaped HTML: text, links and code.
	 * @param string $type    Notice type.
	 */
	private function inline_notice( string $message, string $type ): void {
		wp_admin_notice(
			wp_kses(
				$message,
				[
					'a'    => [ 'href' => [] ],
					'code' => [],
				]
			),
			[
				'type'               => $type,
				'additional_classes' => [ 'inline' ],
			]
		);
	}
```

Replace:
```php
		return [
			'settings' => __( 'Settings', 'hierarchy-guard' ),
		];
```
with:
```php
		return [
			'settings'  => __( 'Settings', 'hierarchy-guard' ),
			'redirects' => __( 'Redirects', 'hierarchy-guard' ),
		];
```

In `includes/HierarchyGuard/Plugin.php`, replace:
```php
		add_action( 'init', [ Settings::class, 'register_option' ] );
		( new Admin\Settings_Page( $providers ) )->register();
		( new Trash\Markers() )->register();
```
with:
```php
		add_action( 'init', [ Settings::class, 'register_option' ] );
		( new Admin\Settings_Page( $providers, new Redirects\Records( $ledger ) ) )->register();
		( new Trash\Markers() )->register();
```

- [ ] **Step 6: Write the bundle that asks before a Delete, and enqueue it on the tab**

Create `src/redirects/confirm.js`:
```js
/**
 * What a Delete on the Redirects tab asks first. The questions come from the
 * page: a link's data-hierarchy-guard-confirm, and for a bulk Delete the
 * form's, or the one ticked row's data-hierarchy-guard-confirm-one.
 */

/**
 * The question a click asks, or null: a Delete link carries it.
 *
 * @param {Object|null} target What was clicked.
 * @return {string|null} Question.
 */
export function linkQuestion( target ) {
	const link =
		target && target.closest
			? target.closest( 'a[data-hierarchy-guard-confirm]' )
			: null;
	return link ? link.getAttribute( 'data-hierarchy-guard-confirm' ) : null;
}

/**
 * The question a bulk Delete asks, once for the selection, or null for any
 * other submit: another action, Filter, the search, or nothing ticked.
 *
 * @param {Object|null} form      The form submitted.
 * @param {Object|null} submitter The button that submitted it.
 * @return {string|null} Question.
 */
export function bulkQuestion( form, submitter ) {
	if (
		! form ||
		! form.hasAttribute ||
		! form.hasAttribute( 'data-hierarchy-guard-confirm' ) ||
		! submitter ||
		( 'doaction' !== submitter.id && 'doaction2' !== submitter.id )
	) {
		return null;
	}
	const select = form.querySelector(
		'doaction2' === submitter.id
			? 'select[name="action2"]'
			: 'select[name="action"]'
	);
	const checked = form.querySelectorAll( 'input[name="redirect[]"]:checked' );
	if ( ! select || 'delete' !== select.value || 0 === checked.length ) {
		return null;
	}
	return 1 === checked.length
		? checked[ 0 ].getAttribute( 'data-hierarchy-guard-confirm-one' )
		: form.getAttribute( 'data-hierarchy-guard-confirm' );
}

/**
 * Asks a question, and cancels the event when the answer is no.
 *
 * @param {Object}                        event    The click or submit.
 * @param {string|null}                   question What to ask; nothing is asked without one.
 * @param {(question: string) => boolean} ask      Asks and answers true or false.
 * @return {boolean} Whether the event goes on.
 */
export function guard( event, question, ask ) {
	if ( ! question || ask( question ) ) {
		return true;
	}
	event.preventDefault();
	return false;
}
```

Create `src/redirects/index.js`:
```js
/**
 * Settings › Hierarchy Guard › Redirects: Delete asks first, from a row, the
 * Edit view or in bulk, as core asks before deleting a term. Without
 * JavaScript the Delete runs, as in core.
 */
import { bulkQuestion, guard, linkQuestion } from './confirm';

// eslint-disable-next-line no-alert
const ask = ( question ) => window.confirm( question );

window.document.addEventListener( 'click', ( event ) => {
	guard( event, linkQuestion( event.target ), ask );
} );

window.document.addEventListener( 'submit', ( event ) => {
	guard( event, bulkQuestion( event.target, event.submitter ), ask );
} );
```

In `webpack.config.js`, make these replacements.

Replace:
```js
/**
 * One bundle per screen: the block editor panel, the list screen, the classic editor.
 */
```
with:
```js
/**
 * One bundle per screen: the block editor panel, the list screen, the
 * classic editor, and the Redirects tab of Settings › Hierarchy Guard.
 */
```

Replace:
```js
		classic: './src/classic/index.js',
	},
```
with:
```js
		classic: './src/classic/index.js',
		redirects: './src/redirects/index.js',
	},
```

In `includes/HierarchyGuard/Admin/Assets.php`, make these replacements.

Replace:
```php
	/**
	 * The classic-editor script where the block editor is off.
	 *
```
with:
```php
	/**
	 * The classic-editor script where the block editor is off, and the
	 * Redirects tab's script, which asks before a Delete.
	 *
```

Replace:
```php
	public function enqueue_admin( $hook_suffix ): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
```
with:
```php
	public function enqueue_admin( $hook_suffix ): void {
		if ( 'settings_page_' . Settings_Page::SLUG === $hook_suffix && 'redirects' === Settings_Page::current_tab() ) {
			$this->enqueue( 'redirects' );
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
```

Run: `npm run build`
Expected: webpack compiles `build/redirects.js` and `build/redirects.asset.php`.

- [ ] **Step 7: Run the tests to verify they pass**

Run: `npm run test:php -- --filter 'RedirectsTabTest|SettingsPageTest|AssetsTest'` and `npm run test:js`
Expected: PASS (Vitest: 81 tests).

Run: `npm run test:php` then `composer lint`, `npm run lint:js`
Expected: the whole suite passes, with the new tests counted; lint exits 0. Run `composer lint:fix` first if it reports alignment; formatting-only changes are not deviations.

- [ ] **Step 8: Commit**

```bash
git add includes/HierarchyGuard/Admin includes/HierarchyGuard/Plugin.php src/redirects webpack.config.js tests/phpunit/RedirectsTabTest.php tests/phpunit/SettingsPageTest.php tests/phpunit/AssetsTest.php
git commit -m "feat: list the recorded redirects on a Redirects tab, with Delete"
```

### Task 8: The Edit view and Save

**Files:**
- Modify: `includes/HierarchyGuard/Admin/Redirect_Edit.php` (edit and save nonces, `edit_url()`, the `edit` and `save` actions, `render()`, `is_editing()`, `requested_id()`, `save()`)
- Modify: `includes/HierarchyGuard/Admin/Redirects_Table.php` (the Edit row action)
- Modify: `includes/HierarchyGuard/Admin/Settings_Page.php` (the Redirects tab prints the Edit view when asked, and titles it)
- Test: `tests/phpunit/RedirectEditTest.php`

**Interfaces:**
- Consumes: `Records::find()`, `target_url()`, `save()` (Task 5); `Redirects_Table::old_label()`, `Redirect_Edit::delete_url()` (Task 7).
- Produces: `Redirect_Edit::NONCE_EDIT = 'hierarchy_guard_edit_'`, `NONCE_SAVE = 'hierarchy_guard_save_'` (each plus the record ID); `edit_url( string $id ): string` (`…&tab=redirects&action=edit&redirect=<id>&_wpnonce=…`).
- Produces: `handle()` also takes `edit` (checks the nonce; a record gone since returns to the tab with "This redirect no longer exists.") and `save` (POST `redirect`, `target`, `code` of 0, 301 or 302, checked against `NONCE_SAVE . $id`). A saved edit returns to the tab with "Redirect updated."; a refused one returns null, keeps the record and what was entered, and adds the reason as a settings error.
- Produces: `render(): bool`: prints the Edit view (`<h2>`, a POST form to the Redirects tab, a `form-table` with the read-only Old URL, one `type="text"` field so that a path is not stopped by the browser, with the spec's two sentences and "A path on this site, such as /about/team/, works as well as a full URL.", the Code radios with Automatic's description tied by `aria-describedby`, Save, Cancel and core's red `<span id="delete-link"><a class="delete">` Delete, which asks first) and returns true, or prints nothing and returns false. For a top-level root-list entry it says "Now redirects to the same page at the top level, for example /about/team/ to /team/.", the field starts empty and its help begins "Leave empty to keep this. Enter a URL to send these URLs somewhere else." It works without JavaScript.
- Produces: `Redirect_Edit::is_editing(): bool`, which `Settings_Page::admin_title()` reads to title the view "Edit redirect ‹ …".

- [ ] **Step 1: Write the failing tests**

Create `tests/phpunit/RedirectEditTest.php`:
```php
<?php
/**
 * The Edit view of a recorded redirect.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Admin\Redirect_Edit;
use HierarchyGuard\Admin\Settings_Page;
use HierarchyGuard\Ledger;
use HierarchyGuard\Post_Types;
use HierarchyGuard\Redirects\Providers;
use HierarchyGuard\Redirects\Records;
use HierarchyGuard\Settings;
use WPDieException;
use WP_UnitTestCase;

/**
 * @covers \HierarchyGuard\Admin\Redirect_Edit
 */
final class RedirectEditTest extends WP_UnitTestCase {

	use Trash_Fixtures;

	private Ledger $ledger;

	private Records $records;

	private Redirect_Edit $edit;

	/**
	 * Page IDs.
	 *
	 * @var array<string,int>
	 */
	private array $ids;

	private string $id;

	public function set_up(): void {
		parent::set_up();
		require_once ABSPATH . 'wp-admin/includes/template.php';
		delete_option( Settings::OPTION );
		delete_option( Ledger::ROOT_OPTION );
		delete_option( Ledger::ROOT_TARGETS );
		delete_option( Ledger::RECENT );
		delete_transient( 'settings_errors' );
		Post_Types::reset();
		$this->set_permalink_structure( '/%postname%/' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->ledger  = new Ledger();
		$this->records = new Records( $this->ledger );
		$this->edit    = new Redirect_Edit( $this->records );
		$this->ids     = $this->make_tree();
		$this->ledger->record( $this->ids['about'], 'old-about' );
		$this->id    = Records::id( $this->ids['about'], 'page', 'old-about' );
		$_GET['tab'] = 'redirects';
	}

	public function tear_down(): void {
		foreach ( [ 'tab', 'action', 'redirect', '_wpnonce', 'target', 'code' ] as $key ) {
			unset( $_GET[ $key ], $_POST[ $key ], $_REQUEST[ $key ] );
		}
		$GLOBALS['wp_settings_errors'] = [];
		$this->set_permalink_structure( '' );
		parent::tear_down();
	}

	/**
	 * Puts a link's query into the request, as a click on it would.
	 *
	 * @param string $url Link.
	 */
	private function follow( string $url ): void {
		wp_parse_str( (string) wp_parse_url( html_entity_decode( $url ), PHP_URL_QUERY ), $query );
		foreach ( $query as $key => $value ) {
			$_GET[ $key ]     = $value;
			$_REQUEST[ $key ] = $value;
		}
	}

	/**
	 * Posts the Edit form, as Save does.
	 *
	 * @param string $url   Redirect to.
	 * @param string $code  Code radio value.
	 * @param string $nonce Nonce, or '' for the form's own.
	 */
	private function submit( string $url, string $code, string $nonce = '' ): void {
		$fields = [
			'action'   => 'save',
			'redirect' => $this->id,
			'_wpnonce' => '' !== $nonce ? $nonce : wp_create_nonce( Redirect_Edit::NONCE_SAVE . $this->id ),
			'target'   => $url,
			'code'     => $code,
		];
		foreach ( $fields as $key => $value ) {
			$_POST[ $key ]    = wp_slash( $value );
			$_REQUEST[ $key ] = wp_slash( $value );
		}
	}

	/**
	 * The Edit view as it prints, or '' when it does not.
	 *
	 * @return string
	 */
	private function view(): string {
		ob_start();
		$this->edit->render();
		return (string) ob_get_clean();
	}

	public function test_the_edit_link_opens_the_view_behind_its_nonce(): void {
		$this->assertStringContainsString( 'options-general.php?page=hierarchy-guard&amp;tab=redirects&amp;action=edit&amp;redirect=' . $this->id . '&amp;_wpnonce=', Redirect_Edit::edit_url( $this->id ) );
		$this->follow( Redirect_Edit::edit_url( $this->id ) );

		$this->assertNull( $this->edit->handle(), 'The view prints on this load.' );
		$html = $this->view();

		$this->assertStringContainsString( '<h2>Edit redirect</h2>', $html );
		$this->assertStringContainsString( '<form method="post" action="' . esc_url( Settings_Page::url( 'redirects' ) ) . '">', $html );
		$this->assertStringContainsString( '<tr><th scope="row">Old URL</th><td><code>/old-about/</code> and the URLs under it</td></tr>', $html );
		$this->assertStringContainsString( '<td><input type="text" class="regular-text code" id="hierarchy-guard-target" name="target" value="' . esc_attr( get_permalink( $this->ids['about'] ) ) . '" dir="ltr" aria-describedby="hierarchy-guard-target-description">', $html, 'A text field, so a path is not stopped by the browser.' );
		$this->assertStringContainsString( '<p class="description" id="hierarchy-guard-target-description">A page on this site: the redirect follows it when it moves, and the URLs under /old-about/ go to the matching URLs under it. Any other URL: only /old-about/ redirects, and this redirect no longer covers the URLs under it. A path on this site, such as /about/team/, works as well as a full URL.</p>', $html );
		$this->assertStringNotContainsString( 'Leave empty to keep this.', $html );
		$this->assertMatchesRegularExpression( '#<input type="radio" name="code" value="0" aria-describedby="hierarchy-guard-code-automatic-description"\s+checked=\'checked\'> Automatic</label><p class="description" id="hierarchy-guard-code-automatic-description">301, or 302 while restoring a page from the Trash could undo the move.</p>#', $html );
		$this->assertStringContainsString( '301 (permanent)', $html );
		$this->assertStringContainsString( '302 (temporary)', $html );
		$this->assertStringContainsString( 'name="_wpnonce" value="' . wp_create_nonce( Redirect_Edit::NONCE_SAVE . $this->id ) . '"', $html );
		$this->assertMatchesRegularExpression( '#<input type="submit" name="submit" id="submit" class="button button-primary" value="Save"\s*/> <a class="button" href="[^"]+">Cancel</a> <span id="delete-link"><a class="delete" href="[^"]*action=delete[^"]*" data-hierarchy-guard-confirm="Delete the redirect from /old-about/\? This can’t be undone.">Delete</a></span>#', $html, 'Core’s red delete link, which asks first.' );
	}

	public function test_the_view_of_a_url_target_offers_its_url_and_its_code(): void {
		$this->ledger->set_target( 'page', 'old-about', $this->ids['about'], 'https://jobs.example.org/', 302 );
		$this->follow( Redirect_Edit::edit_url( $this->id ) );

		$html = $this->view();

		$this->assertStringContainsString( '<td><code>/old-about/</code></td>', $html, 'A URL target covers the exact old URL only.' );
		$this->assertStringContainsString( 'value="https://jobs.example.org/"', $html );
		$this->assertMatchesRegularExpression( '#value="302"\s+checked=\'checked\'#', $html );
	}

	public function test_the_view_without_its_nonce_or_without_manage_options_is_refused(): void {
		$this->follow( Redirect_Edit::edit_url( $this->id ) );
		$_REQUEST['_wpnonce'] = wp_create_nonce( Redirect_Edit::NONCE_EDIT . Records::id( 0, 'page', 'old-about' ) );
		try {
			$this->edit->handle();
			$this->fail( 'Another record’s nonce must not open this one.' );
		} catch ( WPDieException $refused ) {
			unset( $refused );
		}

		$this->follow( Redirect_Edit::edit_url( $this->id ) );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->expectException( WPDieException::class );
		$this->edit->handle();
	}

	public function test_saving_a_page_returns_to_the_tab_with_redirect_updated(): void {
		$this->submit( get_permalink( $this->ids['history'] ), '302' );

		$this->assertSame( admin_url( 'options-general.php?page=hierarchy-guard&tab=redirects&settings-updated=true' ), $this->edit->handle() );

		$this->assertSame( [], $this->ledger->paths_for( $this->ids['about'] ) );
		$this->assertSame( [ 'old-about' ], $this->ledger->paths_for( $this->ids['history'] ) );
		$this->assertSame( 302, $this->ledger->target( 'page', 'old-about', $this->ids['history'] )['code'] );
		$kept = get_transient( 'settings_errors' );
		$this->assertSame( 'Redirect updated.', end( $kept )['message'] );
		$this->assertSame( 'success', end( $kept )['type'] );
	}

	public function test_saving_any_other_url_makes_a_url_target(): void {
		$this->submit( 'https://jobs.example.org/open?team=web%20design', '0' );

		$this->assertNotNull( $this->edit->handle() );

		$this->assertSame(
			[
				'url'  => 'https://jobs.example.org/open?team=web%20design',
				'code' => 0,
			],
			$this->ledger->target( 'page', 'old-about', $this->ids['about'] ),
			'Percent-encoding survives the save.'
		);
	}

	public function test_a_url_that_is_not_http_prints_the_form_again_with_why(): void {
		$this->submit( 'javascript:alert(1)', '302' );

		$this->assertNull( $this->edit->handle() );

		$this->assertSame( [ 'Enter a URL that starts with http:// or https://, or a path on this site that starts with /.' ], array_column( get_settings_errors( 'hierarchy_guard' ), 'message' ) );
		$this->assertNull( $this->ledger->target( 'page', 'old-about', $this->ids['about'] ), 'Nothing is saved.' );
		$html = $this->view();
		$this->assertStringContainsString( '<h2>Edit redirect</h2>', $html );
		$this->assertStringContainsString( 'value="javascript:alert(1)"', $html, 'What was entered, to correct.' );
		$this->assertMatchesRegularExpression( '#value="302"\s+checked=\'checked\'#', $html );
	}

	public function test_a_save_without_its_nonce_or_without_manage_options_saves_nothing(): void {
		$this->submit( 'https://example.org/', '302', wp_create_nonce( Redirect_Edit::NONCE_SAVE . Records::id( 0, 'page', 'x' ) ) );
		try {
			$this->edit->handle();
			$this->fail( 'Another record’s nonce must not save this one.' );
		} catch ( WPDieException $refused ) {
			unset( $refused );
		}
		$this->assertNull( $this->ledger->target( 'page', 'old-about', $this->ids['about'] ) );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->submit( 'https://example.org/', '302' );
		try {
			$this->edit->handle();
			$this->fail( 'An editor cannot point a redirect off the site.' );
		} catch ( WPDieException $refused ) {
			unset( $refused );
		}
		$this->assertNull( $this->ledger->target( 'page', 'old-about', $this->ids['about'] ) );
	}

	public function test_the_table_offers_edit_and_the_tab_prints_the_view_instead_of_the_table(): void {
		set_current_screen( 'settings_page_hierarchy-guard' );
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$page = new Settings_Page( new Providers(), $this->records );

		ob_start();
		$page->render();
		$list = (string) ob_get_clean();
		$this->assertStringContainsString( '<a href="' . esc_url( Redirect_Edit::edit_url( $this->id ) ) . '" aria-label="Edit the redirect from /old-about/">Edit</a>', $list );

		$this->follow( Redirect_Edit::edit_url( $this->id ) );
		ob_start();
		$page->render();
		$edit = (string) ob_get_clean();
		set_current_screen( 'front' );

		$this->assertStringContainsString( '<h2>Edit redirect</h2>', $edit );
		$this->assertStringNotContainsString( 'wp-list-table', $edit );
		$this->assertStringContainsString( 'nav-tab nav-tab-active" aria-current="page">Redirects</a>', $edit );
	}

	public function test_a_record_deleted_between_edit_and_save_is_not_written_back(): void {
		$this->submit( 'https://example.org/', '302' );
		$this->ledger->forget( $this->ids['about'], 'old-about' );

		$this->assertSame( admin_url( 'options-general.php?page=hierarchy-guard&tab=redirects&settings-updated=true' ), $this->edit->handle() );

		$this->assertSame( [], $this->ledger->paths_for( $this->ids['about'] ) );
		$this->assertFalse( metadata_exists( 'post', $this->ids['about'], Ledger::target_key( 'page', 'old-about' ) ), 'No override without its record.' );
		$kept = get_transient( 'settings_errors' );
		$this->assertSame( 'This redirect no longer exists.', end( $kept )['message'] );
	}

	public function test_a_top_level_entry_s_view_says_where_it_leads_and_keeps_it_when_left_empty(): void {
		$this->ledger->record_root( 'page', 'archive' );
		$this->id = Records::id( 0, 'page', 'archive' );
		$this->follow( Redirect_Edit::edit_url( $this->id ) );

		$html = $this->view();

		$this->assertStringContainsString( '<td><p>Now redirects to the same page at the top level, for example /about/team/ to /team/.</p><input type="text" class="regular-text code" id="hierarchy-guard-target" name="target" value=""', $html, 'The field starts empty.' );
		$this->assertStringContainsString( 'id="hierarchy-guard-target-description">Leave empty to keep this. Enter a URL to send these URLs somewhere else. A page on this site:', $html );

		$this->submit( '', '302' );
		$this->assertStringContainsString( 'settings-updated=true', $this->edit->handle() );
		$this->assertSame( [ 'page' => [ 'archive' ] ], $this->ledger->root_paths(), 'Still top level.' );
		$this->assertSame( 302, $this->ledger->target( 'page', 'archive', 0 )['code'] );
	}

	public function test_a_path_entered_is_saved_as_the_url_on_this_site(): void {
		$this->submit( '/company/about/history/', '0' );

		$this->assertStringContainsString( 'settings-updated=true', $this->edit->handle() );

		$this->assertSame( [ 'old-about' ], $this->ledger->paths_for( $this->ids['history'] ), 'The path of a page moves the record onto it.' );
	}

	public function test_the_edit_view_is_named_in_the_browser_title(): void {
		set_current_screen( 'settings_page_hierarchy-guard' );
		$page = new Settings_Page( new Providers(), $this->records );
		$this->follow( Redirect_Edit::edit_url( $this->id ) );

		$page->load();

		$this->assertSame( 'Edit redirect ‹ Hierarchy Guard ‹ Site — WordPress', $page->admin_title( 'Hierarchy Guard ‹ Site — WordPress' ) );
		remove_filter( 'admin_title', [ $page, 'admin_title' ] );
		set_current_screen( 'front' );
	}
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npm run test:php -- --filter RedirectEditTest`
Expected: FAIL. The tests error with `Error: Call to undefined method HierarchyGuard\Admin\Redirect_Edit::edit_url()`, `Error: Undefined constant HierarchyGuard\Admin\Redirect_Edit::NONCE_SAVE` or `Error: Call to undefined method HierarchyGuard\Admin\Redirect_Edit::render()`.

- [ ] **Step 3: Add the Edit view and Save**

In `includes/HierarchyGuard/Admin/Redirect_Edit.php`, make these replacements.

Replace:
```php
/**
 * Deletes recorded redirects, one from its row or in bulk. Every action
 * checks manage_options and its nonce, and ends on the Redirects tab with a
 * core notice that core's options header prints.
 */
```
with:
```php
/**
 * The Edit view of a recorded redirect, and the Redirects tab's actions:
 * save an edit, delete one record from its row or the Edit view, or delete
 * in bulk. The view and every action check manage_options and a nonce. An
 * action ends on the Redirects tab with a core notice that core's options
 * header prints; a save the records refuse prints the form again, with
 * what was entered and why it was not saved.
 */
```

Replace:
```php

	public const NONCE_DELETE = 'hierarchy_guard_delete_';
```
with:
```php

	public const NONCE_EDIT   = 'hierarchy_guard_edit_';
	public const NONCE_SAVE   = 'hierarchy_guard_save_';
	public const NONCE_DELETE = 'hierarchy_guard_delete_';
```

Replace:
```php
	/**
	 * Constructor.
```
with:
```php
	/**
	 * A save the records refused: the record and what was entered.
	 *
	 * @var array{record:array<string,mixed>,url:string,code:int}|null
	 */
	private ?array $refused = null;

	/**
	 * Constructor.
```

Replace:
```php
		$this->records = $records;
	}
```
with:
```php
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
```

Replace:
```php
		}
		if ( 'delete' !== $action ) {
```
with:
```php
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
```

Replace:
```php
		return $this->delete_one( $id );
	}
```
with:
```php
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
		$old  = (string) $record['old'];
		$help = [];
		if ( Records::is_top_level( $record ) ) {
			$help[] = __( 'Leave empty to keep this.', 'hierarchy-guard' );
			$help[] = __( 'Enter a URL to send these URLs somewhere else.', 'hierarchy-guard' );
		}
		/* translators: %s: the old URL, such as /about/team/. */
		$help[] = sprintf( __( 'A page on this site: the redirect follows it when it moves, and the URLs under %s go to the matching URLs under it.', 'hierarchy-guard' ), $old );
		/* translators: %s: the old URL, such as /about/team/. */
		$help[] = sprintf( __( 'Any other URL: only %s redirects, and this redirect no longer covers the URLs under it.', 'hierarchy-guard' ), $old );
		$help[] = __( 'A path on this site, such as /about/team/, works as well as a full URL.', 'hierarchy-guard' );

		echo '<h2>' . esc_html__( 'Edit redirect', 'hierarchy-guard' ) . '</h2>';
		printf( '<form method="post" action="%s">', esc_url( Settings_Page::url( 'redirects' ) ) );
		echo '<input type="hidden" name="action" value="save">';
		printf( '<input type="hidden" name="redirect" value="%s">', esc_attr( (string) $record['id'] ) );
		wp_nonce_field( self::NONCE_SAVE . $record['id'] );
		echo '<table class="form-table" role="presentation"><tbody>';
		printf( '<tr><th scope="row">%1$s</th><td>%2$s</td></tr>', esc_html__( 'Old URL', 'hierarchy-guard' ), Redirects_Table::old_label( $record ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- old_label() escapes its parts.
		$now = Records::is_top_level( $record ) ? '<p>' . esc_html__( 'Now redirects to the same page at the top level, for example /about/team/ to /team/.', 'hierarchy-guard' ) . '</p>' : '';
		printf(
			'<tr><th scope="row"><label for="hierarchy-guard-target">%1$s</label></th><td>%2$s<input type="text" class="regular-text code" id="hierarchy-guard-target" name="target" value="%3$s" dir="ltr" aria-describedby="hierarchy-guard-target-description"><p class="description" id="hierarchy-guard-target-description">%4$s</p></td></tr>',
			esc_html__( 'Redirect to', 'hierarchy-guard' ),
			$now, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
			esc_attr( $url ),
			esc_html( implode( ' ', $help ) )
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
		$code = isset( $_POST['code'] ) ? absint( wp_unslash( $_POST['code'] ) ) : 0;
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
```

- [ ] **Step 4: Offer Edit in the table and print the view on the tab**

In `includes/HierarchyGuard/Admin/Redirects_Table.php`, make these replacements.

Replace:
```php
 * on the old URL, a post type filter when more than one type is supported,
 * and Delete. There is nothing to add. The filter's request argument is
 * TYPE_ARG, never `post_type`: wp-admin/admin.php reads that one before the
 * page loads and, for a plugin page under Settings, refuses to load it.
 */
```
with:
```php
 * on the old URL, a post type filter when more than one type is supported,
 * and Edit and Delete. There is nothing to add. The filter's request
 * argument is TYPE_ARG, never `post_type`: wp-admin/admin.php reads that one
 * before the page loads and, for a plugin page under Settings, refuses to
 * load it.
 */
```

Replace:
```php
	/**
	 * Delete, under the old URL.
	 *
```
with:
```php
	/**
	 * Edit and Delete, under the old URL.
	 *
```

Replace:
```php
			[
				'delete' => sprintf(
```
with:
```php
			[
				'edit'   => sprintf(
					'<a href="%1$s" aria-label="%2$s">%3$s</a>',
					esc_url( Redirect_Edit::edit_url( $id ) ),
					/* translators: %s: an old URL, such as /about/team/. */
					esc_attr( sprintf( __( 'Edit the redirect from %s', 'hierarchy-guard' ), $old ) ),
					esc_html__( 'Edit', 'hierarchy-guard' )
				),
				'delete' => sprintf(
```

In `includes/HierarchyGuard/Admin/Settings_Page.php`, make these replacements.

Replace:
```php
	/**
	 * The browser title of the Redirects tab: the view's name first.
	 *
```
with:
```php
	/**
	 * The browser title of the Redirects tab and the Edit view: the view's name first.
	 *
```

Replace:
```php
	public function admin_title( $admin_title ): string {
		/* translators: 1: the view of the page, such as Redirects; 2: the page's title, as the browser shows it. */
		return sprintf( __( '%1$s ‹ %2$s', 'hierarchy-guard' ), __( 'Redirects', 'hierarchy-guard' ), (string) $admin_title );
	}
```
with:
```php
	public function admin_title( $admin_title ): string {
		$view = $this->edit->is_editing() ? __( 'Edit redirect', 'hierarchy-guard' ) : __( 'Redirects', 'hierarchy-guard' );
		/* translators: 1: the view of the page, such as Redirects; 2: the page's title, as the browser shows it. */
		return sprintf( __( '%1$s ‹ %2$s', 'hierarchy-guard' ), $view, (string) $admin_title );
	}
```

Replace:
```php
	/**
	 * The Redirects tab: its notices, the search box and the table, in a GET
	 * form back to this tab, as core's list screens have it.
	 */
	private function render_redirects(): void {
		$table = new Redirects_Table( $this->records );
```
with:
```php
	/**
	 * The Redirects tab: the Edit view when it is asked for; otherwise its
	 * notices, the search box and the table, in a GET form back to this tab,
	 * as core's list screens have it.
	 */
	private function render_redirects(): void {
		if ( $this->edit->render() ) {
			return;
		}
		$table = new Redirects_Table( $this->records );
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `npm run test:php -- --filter 'RedirectEditTest|RedirectsTabTest'`
Expected: PASS.

Run: `npm run test:php` then `composer lint`
Expected: the whole suite passes, with the new tests counted; lint exits 0. Run `composer lint:fix` first if it reports alignment; formatting-only changes are not deviations.

- [ ] **Step 6: Commit**

```bash
git add includes/HierarchyGuard/Admin tests/phpunit/RedirectEditTest.php
git commit -m "feat: edit a recorded redirect's target and code"
```

### Task 9: README and a live check

**Files:**
- Modify: `README.md`

- [ ] **Step 1: Describe the page**

In `README.md`, make these replacements.

Replace:
```markdown
  On the Pages list and in the block editor, "View redirects" opens a list of each old URL of that change and its new one, which links to the detected redirect plugin, downloads a CSV in its import layout, or copies the list as text. Without JavaScript the link downloads the CSV. A CSV row's code is the one the plugin's own redirect rule chooses (before filters): 302 while restoring a page from the trash could still undo the move its redirect follows, and 301 otherwise. The list stays available for 30 minutes, until the user's next change replaces it, or until the page a save moved, or a trashed parent whose subpages moved up, is deleted for good, and a trash's list covers only its pages still in the Trash. If another user then trashes a subpage that trash moved, that subpage's own subpages are left out of the first trash's list, though with Redirects on they keep redirecting. Old URLs from earlier changes, or from pages deleted for good, are not listed again, and with Redirects on they keep redirecting.
- **Editor.** The block editor warns under the page's Summary before a save changes subpage URLs. Its Hierarchy panel shows the parent pages and the subpages, where the page is now. The classic editor shows the same in a box, and asks before saving such a change.
```
with:
```markdown
  On the Pages list and in the block editor, "View redirects" opens a list of each old URL of that change and its new one, which links to the detected redirect plugin, downloads a CSV in its import layout, or copies the list as text. Without JavaScript the link downloads the CSV. A CSV row's code is the one the plugin's own redirect rule chooses (before filters): 302 while restoring a page from the trash could still undo the move its redirect follows, and 301 otherwise. The list stays available for 30 minutes, until the user's next change replaces it, or until the page a save moved, or a trashed parent whose subpages moved up, is deleted for good, and a trash's list covers only its pages still in the Trash. If another user then trashes a subpage that trash moved, that subpage's own subpages are left out of the first trash's list, though with Redirects on they keep redirecting. Old URLs from earlier changes, or from pages deleted for good, are not listed again, and with Redirects on they keep redirecting.
- **Settings › Hierarchy Guard.** The Settings tab holds the supported post types, the trash policy and the switch for built-in redirects. The Redirects tab lists every redirect Hierarchy Guard recorded, 20 to a page in old URL order, with where each one leads, its code and whether it is served; search finds them by old URL. Edit points one at another page of its post type, which it then follows along with the URLs under it, or at any other http(s) URL, which redirects only its exact old URL, and sets the code to 301, 302 or automatic; a path on this site, such as `/about/team/`, works as well as a full URL. Delete removes it, from its row or in bulk, after asking. Redirects can't be added by hand: that is a redirect plugin's job. With more than 1,000 recorded redirects the tab lists the newest 1,000, and a search for a full old URL finds any other.
- **Editor.** The block editor warns under the page's Summary before a save changes subpage URLs. Its Hierarchy panel shows the parent pages and the subpages, where the page is now. The classic editor shows the same in a box, and asks before saving such a change.
```

Replace:
````markdown
wp hierarchy-guard process-jobs
```
````
with:
````markdown
wp hierarchy-guard process-jobs
```

The Redirects tab lists the newest 1,000 records from a list the plugin keeps. A daily WP-Cron event rebuilds that list from the database: it adds records made before the list existed, or before their post type was supported, and an entry that two writes at the same moment lost, so a lost update heals within a day; a search by full URL finds any record meanwhile. The same event runs early when deletes leave a full list short. Until the list is first built, the tab says it is still being built. Without cron, run it with:

```bash
wp cron event run hierarchy_guard_rebuild_recent
```
````

- [ ] **Step 2: Run every check**

Run: `npm run test:php`, `npx wp-env run tests-cli --env-cwd=wp-content/plugins/hierarchy-guard bash -c 'WP_MULTISITE=1 vendor/bin/phpunit'`, `composer lint`, `npm run lint:js`, `npm run lint:style`, `npm run test:js`, `npm run build`
Expected: 769 PHPUnit tests pass (3 skipped), the same suite passes as multisite, both lints and Stylelint exit 0, 81 Vitest tests pass, and the build compiles. Run the pairs fuzz test with `HG_FUZZ_SEEDS=300` or more too: every seed passes.

- [ ] **Step 3: Live check**

With the build done and the dev site at http://localhost:8898 (admin / password), take a snapshot of the `_hierarchy_guard_%` meta rows, the `hierarchy_guard_*` options and the transients. First the first build, if the dev site has records from before this plan: the first admin load (an admin screen's heartbeat is enough) sets `hierarchy_guard_recent_state` to `building` and schedules the single event `hierarchy_guard_rebuild_recent` to run now; the Redirects tab then says "The list of recorded redirects is still being built. Older redirects appear here once it’s done." and lists only the root list, though a search for one old record's full URL already finds it. Run `wp cron event run hierarchy_guard_rebuild_recent`: the state is `done:` and the types built, the old records are listed, the notice is gone, the run has scheduled its own next run a day later (one event), and loading the front page schedules nothing again. Then create test pages over WP-CLI as the admin, so the saves are recorded: a published top-level page with slug `hgp4-studio`, a published subpage `hgp4-team` under it and `hgp4-ada` under that, and a published top-level page `hgp4-events`. Rename `hgp4-studio` to `hgp4-studio-new` and `hgp4-events` to `hgp4-whats-on`, then make `hgp4-whats-on` a draft. Report what you see:

1. Settings › Hierarchy Guard opens on the Settings tab with core nav tabs Settings | Redirects and the three fields; Save Changes says "Settings saved." below the tabs and stays on the page. Settings › Writing has no Hierarchy Guard section. The Plugins screen row reads "Settings | Deactivate", and Settings opens the page.
2. The Redirects tab at `&tab=redirects&s=hgp4&redirect_type=page` loads (with `post_type=page` instead, core would refuse it). The browser title starts "Redirects ‹ Hierarchy Guard", "Search results for: hgp4" sits beside the heading, and the table lists "/hgp4-events/ and the URLs under it → HGP4-Events — Draft, /?page_id=…, Automatic, Not active: the page it points to isn’t published" and "/hgp4-studio/ and the URLs under it → HGP4-Studio, /hgp4-studio-new/, Automatic, Active". Old URLs are in code, and the Code column is in the admin's normal font.
3. The page loads `build/redirects.js`. Delete on /hgp4-events/ asks "Delete the redirect from /hgp4-events/? This can’t be undone."; Cancel keeps the row.
4. Edit on /hgp4-studio/: the title starts "Edit redirect ‹", the field is a text field, and Delete is core's red link. Enter `https://jobs.example.org/studio/#open`, choose 302 and Save: "Redirect updated.". `curl -sI 'http://localhost:8898/hgp4-studio/?utm=a'` answers 302 to `https://jobs.example.org/studio/?utm=a#open` with `X-Redirect-By: Hierarchy Guard`; `/hgp4-studio/hgp4-team/` is not redirected by Hierarchy Guard (core's own guess may still redirect it, with `X-Redirect-By: WordPress`).
5. Edit on /hgp4-events/: enter the path `/hgp4-studio-new/hgp4-team/` and Save: the record moves onto that page, and `/hgp4-events/hgp4-ada/` redirects 301 to `/hgp4-studio-new/hgp4-team/hgp4-ada/`.
6. Edit on one of the dev site's "Top level" rows: it says "Now redirects to the same page at the top level, for example /about/team/ to /team/.", the field is empty and its help begins "Leave empty to keep this.". Save with the field empty and Automatic chosen: "Redirect updated.", and the row still reads "Top level".
7. Delete on /hgp4-events/ again, and accept: "Redirect deleted. /hgp4-events/ no longer redirects through it." Rename `hgp4-team` and `hgp4-studio-new` once more over WP-CLI so two rows match `hgp4`, tick both, choose Delete in a Bulk actions selector and Apply: it asks once, "Delete the selected redirects? This can’t be undone."; Cancel keeps them, and accepting says "2 redirects deleted. Their old URLs no longer redirect through them."

Afterwards delete the test pages, subpages first (`wp post delete <ids> --force`), so deleting a parent records nothing new, and the `hierarchy_guard_change_*` and `settings_errors` transients the check left. The meta rows and transients must match the snapshot. `hierarchy_guard_recent_records` and `hierarchy_guard_recent_state` stay: they are the plugin's own state from now on (deleting them while an admin screen is open only starts a first build again).

- [ ] **Step 4: Commit**

```bash
git add README.md
git commit -m "docs: describe Settings › Hierarchy Guard and its Redirects tab"
```

## Self-Review

**Spec coverage.** Settings › Hierarchy Guard at `options-general.php?page=hierarchy-guard` for `manage_options`, core nav tabs Settings | Redirects, the three fields saving `hierarchy_guard_settings` through the Settings API, nothing on Settings › Writing, every settings link (the "Set up redirects" notice action and nudge, the panel's "Change this in Settings", the readme) and the Plugins row's "Settings" link: Task 6. Redirects tab as a core `WP_List_Table` of every recorded redirect and only those, one row per `_hierarchy_guard_path_{md5}` entry and per root-list entry, Old URL with "and the URLs under it" for a page target, Redirects to as the page's title linked to its edit screen with its current URL, the URL, or "Top level", Code as Automatic or the chosen code, Status with the first reason that applies, search on the old URL, a post type filter only with two or more types, 20 rows a page sorted by old URL, Edit and Delete row actions, bulk Delete, no Add button, the built-in-redirects-off notice and the provider notice: Tasks 5 and 7. Editing a redirect at `&action=edit&redirect=<id>` behind a nonce, the read-only Old URL, one "Redirect to" field resolved through `url_to_postid()`, a page of another type counting as any other URL, no page picker, no JavaScript needed, Code as Automatic, 301 or 302, Save, Cancel and Delete, "Redirect updated." and the deleted notice from the view, a row or in bulk: Tasks 5 and 8. Editing a recorded redirect's storage (the record moves on retarget, `_hierarchy_guard_target_{md5}` and `hierarchy_guard_root_targets`, deleted with the record, the ledger cache key bumped, `manage_options` and a nonce on every action, http(s) only): Tasks 1, 5, 7 and 8. Serving a redirect with overrides (a URL target on its exact old path only, matching going on past it, a root-list entry's override from its option, `wp_redirect()` after `esc_url_raw()`, the chosen code replacing the automatic one, the batch resolver and the modal and CSV honouring the same): Tasks 2, 3 and 4. Data model rows for the two override stores: Task 1. Architecture's `Ledger` overrides and records list (the records list lives in `Redirects\Records`), `Settings` registration, `Settings_Page`, `Redirects_Table`, `Redirect_Edit`, and the `admin_menu`, `plugin_action_links_{basename}` and `load-settings_page_hierarchy-guard` hooks: Tasks 1 and 5 to 8. Every Testing bullet for plan 4 (sources, search and paging, edits to a page and to a URL, each code, delete, capability and nonce checks, a non-http URL, status labels, resolver and batch parity, settings links): Tasks 1 to 8. The owner's audit rulings: the newest-records option and its daily rebuild, first written as a one-time backfill (Task 1, read in Task 5, its notice in Task 7), the exact-key search whatever the list holds (Task 5), Delete asking first through the `redirects` bundle (Tasks 7 and 8), the top-level Edit copy and the path input (Tasks 5 and 8). Out of scope kept out: adding by hand, import and export, regex, hit counts.

**Placeholder scan.** No TBD or TODO. Every code step carries complete code taken from the verified prototype; every command names its expected result.

**Type consistency.** A record (`id`, `holder`, `post_type`, `path`, `old`, `target`) is built only by `Records` and read by `Redirects_Table` and `Redirect_Edit`. An override (`url`, `code`) is built only by `Ledger::clean_target()` and read by `Rules`, `Batch_Resolver`, `Records` and the table. `Rules::resolve()` and `Resolver::resolve()` return `WP_Post|string|null` with `$code`, which `Server` and `Batch_Resolver::run()` read. `Batch_Resolver::served()`'s `$chosen` is what `Pairs::served_only()` reads. The `Records::STATUS_*` constants are what `Redirects_Table::status_label()` maps. The nonce actions (`Redirect_Edit::NONCE_EDIT`, `NONCE_SAVE`, `NONCE_DELETE`, `NONCE_BULK`) are the ones the links and forms print.

**Review Focus.** All named tests exist: in Tasks 5, 7 and 8.

## Hand-offs to plan 5 (Tree view) and plan 6 (DataViews and release)

- Plan 6's uninstall removes `hierarchy_guard_root_targets` and `hierarchy_guard_recent_records` with the other options and the `_hierarchy_guard_target_` meta rows with the `_hierarchy_guard_path_` rows, in batches of a thousand, per site on multisite.
- Plan 6's readme.txt describes Settings › Hierarchy Guard from the README section Task 9 adds, and its copy pass revisits the Redirects help text that still says "Turn this on" while the switch is on.
- Plan 6's release zip builds the new `redirects` bundle with the others (`npm ci && npm run build`); uninstall also clears the `hierarchy_guard_rebuild_recent` event and removes `hierarchy_guard_recent_records` and `hierarchy_guard_recent_state`.
