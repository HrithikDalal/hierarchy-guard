# Hierarchy Guard 1.0 Hardening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. Every subagent runs on Opus, never Sonnet: implementers, task reviewers, fix re-reviewers and the final review.

**Goal:** Fix every code finding of the 1.0 pre-release audit that plan 6a owns, so that 1.0 works beside Yoast SEO, duplicators and the redirect plugins it detects, keeps its cron and 404 cost flat on large sites and networks, says each thing in one plain sentence, and passes Plugin Check's code checks.

**Architecture:** Mostly changes inside existing classes. Two classes are new: `Compat\Yoast` rebuilds Yoast SEO's indexables on the plugin's own `hierarchy_guard_children_reparented` action, and `Meta_Guard` lets only the plugin write its `_hierarchy_guard_` post meta. The batch queue drains through one cron event per site and the watchdog exists only while jobs wait; `Plugin` sets a site up once per stored version and hands out its objects through `get()`; the 404 lookup reads every prefix in one query; the Tree view's script becomes a webpack entry of its own; the copy pass and per-type labels touch strings across the PHP screens and `src/common/copy.js`.

**Tech Stack:** PHP 8.0+, WordPress 6.6+ (verified on 6.6.x and 7.1.x), PHPUnit 9.6 in wp-env (single site and multisite), PHPCS (WordPress-Extra, WordPress-VIP-Go, WordPressVIPMinimum, PHPCompatibilityWP 8.0+), `@wordpress/scripts` (webpack, ESLint, Stylelint, Vitest), `happy-dom` for the DOM tests.

**Spec:** `docs/superpowers/specs/hierarchy-guard-spec.html`, as updated by Task 1 (commit `3bd6369`). Read: "Block editor panel and classic meta box", "Hierarchy column, subtree filter and Tree view" with the Tree view and trash dialog mockups, "Outcome notice and redirects needed", "Settings › Hierarchy Guard" (Settings tab, Redirects tab, Edit view), "Recording old URLs", "Trashing a parent", "Serving old URLs", "Data model", "Architecture", the hooks and filters tables, "REST endpoint", Testing, Build order and Out of scope. This plan is the first half of build-order step 11; plan 6b (readme.txt and README, screenshots, uninstall, version 1.0.0, the release zip, the comment rewrite and the hook documentation) is the second.

**Audit:** `~/.claude/outputs/hierarchy-guard/audit-1.0/audit-report.md`, with the evidence for every finding ID in `lens-01-02.md` to `lens-09-10.md` and `verify-critical-high.md` in the same folder. Each task names the IDs it closes; the coverage table at the end lists every ID. Three audits of this plan (`~/.claude/outputs/hierarchy-guard/plan6a-audits/`: C-1 to C-8, S-1 to S-7, U-1 to U-18, and Y-1 from the coordinator) are folded in; "Plan audit findings" at the end says where each went.

**Code in this plan is verified.** Every code block comes from a prototype on the local branch `proto-hardening` (head `0d83265`), replayed task by task on top of Task 1's spec commit. A script generated each Create and Replace step from those commits and checked that the steps rebuild each file. A second script then applied this document's steps, in order, to `3bd6369` and compared every task's files with the replay: all 22 code tasks match. Each task's new tests were seen to fail on its parent (the failures are quoted in each Step 2), each key test was seen to kill a named mutant of the code it pins (also quoted in Step 2), and after each task PHPUnit (single site and multisite), Vitest, `composer lint`, `npm run lint:js`, `npm run lint:style` and `npm run build` pass, with the counts quoted in each task. Task 24 records the full runs on WordPress 7.1 and 6.6.

## Global Constraints

- Requires WordPress 6.6 and PHP 8.0; every task must pass on WordPress 6.6 and 7.1. Namespace `HierarchyGuard`; every PHP file declares `strict_types=1`; every class file stops with `if ( ! defined( 'ABSPATH' ) ) { exit; }` after its `use` lines (Task 6 adds it to the existing files; new files have it from the start).
- Version stays `0.1.0` and the Author header is untouched; plan 6b sets both.
- Commits are authored by the repository's git config. No `Co-Authored-By` trailer and no mention of Claude or AI in any commit message.
- `composer lint` must exit 0, warnings included (`composer lint:fix` first; formatting-only changes are not deviations). `npm run lint:js`, `npm run lint:style`, `npm run test:js` and `npm run build` must pass. Plugin Check (2.2.0) reports no code ERROR or WARNING it can fix; its readme ERRORs stay until plan 6b adds readme.txt.
- Comments in new or touched code are concise: what, why or how where it matters, and important decisions. They never mention reviews, rounds, tasks, rulings, audits, finding IDs, plans or development history, in code or tests.
- Copy: one sentence per translatable string, plain words, core's vocabulary. "Subpages" is the word for a page's children and "built-in redirects" the one name for the plugin's own redirects. `_n()` on every counted noun; `_x()` for a short label whose meaning depends on context ("Trash" the verb, "Tree", "Automatic"); a translator comment directly above each call whose string has a placeholder or a pronoun whose subject isn't in the string; curly quotes and apostrophes; text domain `hierarchy-guard`. A translator comment must survive minification: a sentence chosen by an `if` chain is passed as an argument (`parts.push( /* translators: … */ __( … ) )`), and make-pot gives the same comment for every msgid from `build/` as from `src/`.
- Every query goes through `$wpdb->prepare()` or a core API; a list of values is built as placeholders (`%d` for IDs, `%s` for keys and types), never interpolated.
- Scale: nothing in a web request grows with the size of a subtree or of the ledger; counts and capped reads stop at `Subtree::count_cap()` (1,000). Background work runs from one scheduled event per site.
- Owner rulings that stand: the redirect features stay a minimal add-on (no adding redirects by hand, plain status checks with stated limits, the runtime loop guard); the newest-1,000 list is rebuilt daily; the Tree view stays on core's list table with lazy rows and plain offset paging; no Expand all. Where the audit offers a simple and a thorough fix, the simple one is taken and the task says so. Nothing beyond the listed findings is added.
- Tests: `npm run test:php` and `npm run test:php:multisite` in wp-env; Vitest DOM tests start with `// @vitest-environment happy-dom`. A request handler a task touches gets a data provider with input the UI never sends (wrong case, empty, an array, slashed, unknown). Expected values are literals. A test that needs core to send HTTP headers runs `@runInSeparateProcess` with `@preserveGlobalState disabled`; the bootstrap sets `WP_TESTS_SKIP_INSTALL` for such child processes, so they reuse the installed tables. A JavaScript module a test loads has no JSX. Tests that need a third-party plugin's classes use the stubs in `tests/phpunit/stubs/`, loaded by that test only. Tests that seed the plugin's meta directly do it inside `Meta_Guard::writing()`.
- Live checks use the dev site (wp-env, http://localhost:8898, admin/password) and a WordPress 6.6 site. Test content there has a `p6a-` prefix and is deleted by its own IDs afterwards; IDs at or below 84 are never touched. Third-party plugins installed for a check (Yoast SEO, Yoast Duplicate Post, Redirection) are removed afterwards.

## Review Focus

1. A duplicator or translation plugin that copies every meta row of a page, and a WordPress import of an export that carries the plugin's rows. Expected: a copy never takes over an old URL or follows a restore, the plugin's own writes still land, and an import keeps its ledger rows. Pinned in Task 4 (`test_a_copy_of_a_renamed_page_takes_none_of_its_old_urls`, `test_a_copy_of_a_moved_subpage_stays_where_it_is_when_the_trash_is_restored`, `test_only_the_plugin_or_an_import_adds_ledger_rows_and_nothing_adds_a_marker`).
2. Yoast SEO with deep subtrees, absent, or present with internals that changed or throw. Expected: a step's Yoast work stays bounded whatever lies under the moved pages, the trash or restore finishes and moves every subpage, and one page Yoast fails on leaves the others rebuilt. Pinned in Task 3 (`test_a_moved_page_without_subpages_is_rebuilt_at_once_and_one_with_subpages_in_a_run_of_its_own`, `test_a_page_yoast_fails_on_leaves_the_others_of_the_step_rebuilt`, `test_a_yoast_whose_internals_changed_leaves_the_trash_to_finish`).
3. A bulk trash of hundreds of parents in one request, past the inline budget, with one parent whose step throws. Expected: every parent is queued, the site has one scheduled run, a run works through more than a hundred roots oldest first, and the failing root is passed over and left to the watchdog. Pinned in Task 7 (`test_a_bulk_trash_past_the_budget_queues_every_parent_with_one_event`, `test_one_run_works_through_every_queued_root_oldest_first`, `test_one_run_reads_more_roots_once_it_has_passed_the_first_hundred`, `test_a_root_whose_step_fails_is_passed_over_and_left_to_the_watchdog`).
4. Bots requesting deep unknown paths while built-in redirects are on. Expected: a 20-segment miss costs one ledger query, then none while the ledger is unchanged. Pinned in Task 9 (`test_an_unknown_path_twenty_segments_deep_costs_one_ledger_query_then_none`).
5. A site copied to a new domain (staging to production, or an import) whose recorded redirects point at its own pages by URL, including a site in a subdirectory. Expected: they follow the site to the new domain, the home URL typed without its slash included. Pinned in Task 18 (`test_a_url_target_on_this_site_is_kept_as_a_path_and_follows_the_site_to_a_new_domain`, `test_a_target_on_a_site_in_a_subdirectory_is_kept_as_a_path_only_when_it_is_under_the_home_url`).

## Where this plan differs from the audit

Settled for this plan; reviewers should not re-raise them. Each is the simpler of the fixes the audit offers, or a fix the evidence showed wasn't needed.

- **9.1, one job per bulk trash.** One scheduled event per site works through every queued root instead. It needs no new job shape, keeps per-root locks (so a trash and a restore of one root never overlap), and also covers trashes from separate requests (Task 7).
- **3.4 and 5.4, clean-up on every site of a network.** No network-wide sweep. Events exist only while needed: the watchdog while jobs wait, the rebuild while entries exist. Deactivation clears both on the current site, and on the network's other sites a leftover single event fires once with nothing hooked and is gone (Tasks 7 and 8).
- **3.11 and 5.6, a non-autoloaded version option.** `hierarchy_guard_version` is autoloaded: `admin_init` reads it on every admin load, and a separate query each time would cost more than a few autoloaded bytes (Task 8).
- **9.2, ordering the capped read by menu order.** That sorts every child of a huge parent. The label now says what the cap shows: "Only the 1,000 oldest subpages are shown." (Task 10).
- **10.5, sanitize every superglobal at read.** All reads sanitize at read except three that keep the raw string on purpose, each with its reason on the ignore: two search terms, the Edit view's URL target, and the Bulk Edit selection compared raw (Task 6).
- **11.1, a short `max-age` on 302s.** Every redirect goes out with `nocache_headers()` instead, since a restore can change a 301's target too; VIP's edge purge is called as the audit says (Task 17).
- **6.2, keep the previous status on Restore.** Only the notice changes ("“%s” was restored as a draft. Publish it so the URLs of its subpages work again."); the user stays in charge of publishing (Task 13).
- **7.2, the assertive region.** The plugin waits for core's row animation and speaks one polite message that starts with core's "Changes saved." (Task 13).
- **1.7, an RTL-aware separator.** No change: U+203A is a bidi-mirrored character, so browsers already flip ` › ` in right-to-left text. The arrow keys do swap (Task 16).
- **1.2, labels per post type.** The audit put it in a later release; the owner included it. Where core's post type labels have the word (`all_items`, `parent_item_colon`), the type's own label is used; sentences keep "page" and "subpage", because building them from a type's singular name breaks grammar in most languages, and the Post types field says so (Task 15).
- **9.6, accept the backwards scan.** One `FORCE INDEX ( meta_key )` clause, with a test that runs `EXPLAIN` (Task 19).
- **6.5, point users at the Tree view.** The filter lists the subtree in tree order instead, since `Subtree::descendant_ids()` already reads it level by level (Task 10).
- **9.7, the Hierarchy column's second pass over core's loaded pages.** Accepted, as the audit allows: core runs out of memory first, and the Tree view and the subtree filter load only what they show. The spec's Out of scope says so; no task.
- **3.8, root-list records that outlive their pages.** A root-list entry stands for every page that once sat under the old path, and nothing records which those are once the trash's markers are cleaned up, so "the last page under it was deleted" can't be known with a bounded query. The owner chose a note: under each root-list record the Redirects tab says "This redirect stays until you delete it, even after the pages under it are gone." (Task 14).
- **7.8 (3), `aria-haspopup` on the block editor notice's "View redirects" action.** Left as is, as the audit allows: core's notice actions take a label, a URL or an `onClick`, and no attributes.
- **3.1, ElasticPress.** Not adapted: its sync of a direct update is not a public API. The owner chose a readme note (plan 6b).
- **C-2, `main`'s recurring hourly watchdog.** Closed by fact, not code (coordinator's ruling): no production site runs 0.1.0 (AT-KBB production doesn't have the plugin; only its disposable preprod does) and WordPress.org users start at 1.0.0, so no upgrade step clears it. The dev site's recurring event was deleted by hand. Listed under "For the PR body".
- **Y-1, Yoast's cost on deep subtrees.** Rebuilding a moved page makes Yoast rewrite every page under it. Counting descendants into the inline threshold cannot bound one page with a huge subtree, so a step rebuilds only moved pages without subpages, and pages with subpages wait for a time-boxed run of their own, one call into Yoast each (Task 3). The owner accepted Yoast's cost; this keeps it out of the step.
- **C-4, Redirection on a site in a subdirectory.** Fixed for the `redirection` layout only: Safe Redirect Manager, Rank Math, Simple 301 and Yoast Premium strip the home path themselves (Task 5).
- **C-6, network reactivation.** No loop over the network's sites: each subsite runs setup on its own next admin load, keyed on core's network activation time (Task 8).
- **S-3, a lost watchdog.** Re-ensured only where a waiting job is read anyway, the progress notice, so an admin load still runs nothing (Task 7).
- **S-6, VIP's edge.** Kept `nocache_headers()` and the exact-URL purge, de-duplicated and capped at 50 a request; the spec and comments claim only what VIP does, and the readme states the limit in plan 6b. No prefix purging.
- **S-7, Action Scheduler asked once.** The request remembers only Action Scheduler's answer; WP-Cron's check reads the cron array already in memory.
- **U-3, the policy descriptions.** Layout only, by inline margins: the Settings tab loads no stylesheet of the plugin's (Task 13).
- **U-9, the meta box headings.** Core's Page Attributes label classes with `role="heading" aria-level="3"`, rather than a styled `<h3>` or an `<h4>` that would skip a level: the classic screen loads no stylesheet of the plugin's (Task 16).
- **U-7, `Post_Types::label()`'s fallback.** Core fills `parent_item_colon` and `all_items` for every hierarchical type, so the fallback serves only a type that is gone; a type with only its name shows core's defaults, as the owner's "labels only, as built" keeps (Task 15).

## File Structure

```
includes/HierarchyGuard/
├── Compat/Yoast.php                 NEW  rebuilds Yoast SEO's indexables for the pages a trash or restore moved
├── Meta_Guard.php                   NEW  only the plugin writes _hierarchy_guard_ meta; Duplicate Post's exclude list; imports
├── Plugin.php                       MOD  keeps each object for get(); setup() once per stored version; wires Yoast and Meta_Guard
├── Ledger.php                       MOD  writes inside Meta_Guard::writing(); rebuild only with entries, through the meta_key index; prefixes in one query; same-site targets as paths; VIP purge
├── Batch/Queue.php · Processor.php  MOD  one drain event per site; watchdog only while jobs wait
├── Export/Csv.php                   MOD  layouts as header => field maps; Yoast, Rank Math, Simple 301
├── Redirects/Providers.php          MOD  layouts and import notes per provider; described()
├── Redirects/Records.php            MOD  a status per cause; "The URLs under …"
├── Redirects/Live_View.php          MOD  memoized prefixes per request
├── Redirects/Server.php             MOD  request URI sanitized at read; no-cache headers
├── Subtree.php                      MOD  tree order; children_in_any_status(); prepared IN lists
├── Change_Listener.php              MOD  children without a sort or filters; suspend flag removed
├── Trash/Markers.php                MOD  counts(); a moved row counts only while its root exists; prepared remove()
├── Trash/Trash_Handler.php          MOD  undo path removed; one schedule call
├── Admin/*.php                      MOD  Bulk Edit, restore, Trash view and settings copy; Site Editor and tree bundles; stylesheet in the head; headings and semantics
├── Post_Types.php                   MOD  label()
├── REST/Hierarchy_Controller.php    MOD  can_trash_some()
└── every other class file           MOD  the ABSPATH guard
src/
├── list-table/bulk-edit.js          NEW  the Bulk Edit warning
├── list-table/quick-edit-words.js   NEW  Quick Edit's warning text and its announcement after core's
├── tree/start.js                    NEW  the tree bundle's entry
├── common/copy.js                   MOD  the copy pass; csvLabel(), importNotes(), bulkEditWarning()
├── common/redirects-modal.js        MOD  per-provider label and notes; spinner text
├── list-table/quick-edit.js         MOD  one announcement after core's
├── tree/tree.js · tree/index.js     MOD  RTL keys; bound from start.js
└── editor/panel.js · list-table/index.js · list-table/trash-dialog.js   MOD
tests/phpunit/
├── YoastCompatTest.php · Yoast_Container.php · stubs/yoast-*.php · stubs/vip.php   NEW
├── Action_Scheduler_Stub.php · stubs/action-scheduler.php   NEW
├── src/list-table/test/quick-edit.test.js · bulk-edit.test.js   NEW
├── MetaGuardTest.php                NEW
└── 28 test classes                  MOD
package.json · .github/workflows/ci.yml · webpack.config.js · hierarchy-guard.php · README.md   MOD
```

`Meta_Guard` is the only place that decides who may write the plugin's meta; `Compat\Yoast` is the only place that knows Yoast's classes. `Processor` alone decides what a scheduled run does and when the watchdog exists; `Plugin::setup()` alone decides what a site needs scheduled. `Csv::LAYOUTS` and `Providers::all()` are data: adding a provider never adds code.

---

### Task 1: The spec describes the hardened 1.0

**Findings:** every finding in this plan, as behaviour

The spec edit is already made, as its own commit on `hardening`: `3bd6369` ("docs: spec the 1.0 hardening: …"). It changes, in place and with no revision history: the status line and date; the editor warning's place and the Site Editor; the All Pages link, the tree order of the subtree filter and the "1,000 oldest" cap; right-to-left keys and the separator; the tree's own bundle; "Change what happens to the subpages" and the restore wording; the Bulk Edit warning and the Quick Edit announcement; "may now show “Page not found”" and the restore-as-draft sentence; the Trash view's hint; the CSV layouts, labels and import notes per provider; the Built-in redirects field, each policy's description and the help; the split statuses and "The URLs under …"; the Redirects tab and Edit view copy; same-site targets as paths; who may write the plugin's meta; one scheduled run and the watchdog while jobs wait; Yoast's indexables; the one-query 404 lookup, no-cache headers and the VIP purge; markers that count only while their root exists; the data model (`hierarchy_guard_version`, rebuild scheduling); the architecture, hooks and filters tables (`Meta_Guard`, `Compat\Yoast`, `Plugin::get()`, `@since 1.0.0`, `@internal`); the trash preview's capability; the CI matrix; the build order (plans 6a and 6b); and an Out of scope line for the Hierarchy column's memory. After the plan's audits it also says: Yoast's rebuild of moved pages with subpages runs in a time-boxed run of its own (`hierarchy_guard_yoast_rebuild`, `_hierarchy_guard_yoast_pending`); a run reads more roots after the first hundred, schedules the next run first and passes over a failing root; a progress notice re-schedules a lost run; Action Scheduler is asked once a request; a subsite is set up again after a network reactivation (`hierarchy_guard_network_setup`); an import clears cached lookups; what VIP's edge keeps and purges; Redirection's CSV on a site in a subdirectory; same-site targets equal to the home URL; the restore-as-draft, Bulk Edit, Automatic and Post types wording; plural trails and core's default labels; the root-list note; the refused field's description; and Out of scope lines for ElasticPress and VIP's prefix purging.

- [ ] **Step 1: Confirm the spec commit is on the branch**

Run: `git log --oneline -1 3bd6369 -- docs/superpowers/specs/hierarchy-guard-spec.html`

Expected: `3bd6369 docs: spec the 1.0 hardening: compatibility with Yoast and duplicators, real CSV layouts, one scheduled run, the copy pass, per-type labels and the Site Editor`

- [ ] **Step 2: Confirm the scratchpad copy is byte-identical**

Run: `cmp docs/superpowers/specs/hierarchy-guard-spec.html /private/tmp/claude-501/-Users-hrithikdalal-Work-SiteData-LocalSites/a796125f-3108-47cf-901f-40c650e2153a/scratchpad/hierarchy-guard.html`

Expected: no output, exit 0. If the scratchpad copy is missing, copy the spec there with `cp`; never edit the copy.

---

### Task 2: The PHPUnit suite runs as multisite, from npm and in CI

**Findings:** 3.3

- `wp-env run` does not pass the host's environment into the container, so `WP_MULTISITE=1 npm run test:php` ran single site and the multisite tests always skipped. `npm run test:php:multisite` sets the variable inside the container with `env WP_MULTISITE=1`.
- Before this task the suite reports `Tests: 950, Assertions: 4198, Skipped: 5.` however it is started; after it, `npm run test:php:multisite` reports `OK (950 tests, 4235 assertions)`, the multisite-only tests included.
- CI gains a `multisite` matrix key and two multisite cells (PHP 8.2 on the latest WordPress and on the 6.6 branch); the step runs `test:php:multisite` for them. The README's development line names the command.

**Files:**
- Modify: `.github/workflows/ci.yml`
- Modify: `README.md`
- Modify: `package.json`

**Interfaces:**
- Produces: `npm run test:php:multisite`, used by every later task's verification step and by Task 24.

- [ ] **Step 1: Make the change**

In `.github/workflows/ci.yml`, replace:

```yaml
    name: PHPUnit (PHP ${{ matrix.php }}, WP ${{ matrix.wp }})
```

with:

```yaml
    name: PHPUnit (PHP ${{ matrix.php }}, WP ${{ matrix.wp }}${{ matrix.multisite && ', multisite' || '' }})
```

In `.github/workflows/ci.yml`, replace:

```yaml
        include:
          - php: '8.2'
            wp: 'WordPress/WordPress#6.6-branch'
```

with:

```yaml
        multisite: [ false ]
        include:
          - php: '8.2'
            wp: 'WordPress/WordPress#6.6-branch'
            multisite: false
          - php: '8.2'
            wp: 'latest'
            multisite: true
          - php: '8.2'
            wp: 'WordPress/WordPress#6.6-branch'
            multisite: true
```

In `.github/workflows/ci.yml`, replace:

```yaml
      - run: npm run test:php
```

with:

```yaml
      - run: npm run ${{ matrix.multisite && 'test:php:multisite' || 'test:php' }}
```

In `README.md`, replace:

```markdown
npm run test:php           # PHPUnit inside the tests container
```

with:

```markdown
npm run test:php           # PHPUnit inside the tests container
npm run test:php:multisite # the same suite as a multisite network
```

In `package.json`, replace:

```json
		"test:php": "wp-env run tests-cli --env-cwd=wp-content/plugins/hierarchy-guard vendor/bin/phpunit"
```

with:

```json
		"test:php": "wp-env run tests-cli --env-cwd=wp-content/plugins/hierarchy-guard vendor/bin/phpunit",
		"test:php:multisite": "wp-env run tests-cli --env-cwd=wp-content/plugins/hierarchy-guard env WP_MULTISITE=1 vendor/bin/phpunit"
```

- [ ] **Step 2: Run the tests to verify they pass**

Run: `npm run test:php`, `npm run test:php:multisite` and `npm run test:js`

Expected: `Tests: 950, Assertions: 4198, Skipped: 5.` (single site), `OK (950 tests, 4235 assertions)` (multisite), and Vitest `Tests 174 passed (174)`.

Mutant, to run once the tests pass, which shows `npm run test:php:multisite` pins its behaviour: in `package.json`, replace

```json
env WP_MULTISITE=1 vendor/bin/phpunit
```

with:

```json
vendor/bin/phpunit
```

Expected: the test fails, as it did on the prototype:

```
OK, but incomplete, skipped, or risky tests!
Tests: 1, Assertions: 0, Skipped: 1.
```

Then undo the mutant (`git checkout -- package.json`). Never commit it.

- [ ] **Step 3: Lint and build**

Run: `composer lint && npm run lint:js && npm run lint:style && npm run build`

Expected: every command exits 0.

- [ ] **Step 4: Commit**

```bash
git add .github/workflows/ci.yml README.md package.json
git commit -m "test: run the PHPUnit suite as multisite from npm and in CI"
```

---

### Task 3: Yoast SEO rebuilds the indexables of the pages a trash or restore moves, within each step's budget

**Findings:** 3.1, and the plan audits' C-5 and Y-1

- Moves go through a direct update, which skips `wp_insert_post`, the only post hook Yoast listens to, so a moved page kept its `__trashed` canonical, `og:url` and sitemap entry. `Compat\Yoast` listens to the plugin's own `hierarchy_guard_children_reparented` and asks Yoast's `Indexable_Post_Watcher` to `build_indexable()`. As the verifier asked: guarded by `function_exists( 'YoastSEO' )` and `class_exists( Indexable_Post_Watcher::class )`, and no hand-fired `save_post`.
- Rebuilding a page makes Yoast rewrite every page under it too, so the cost follows the moved pages' subtrees. To keep a step, and an inline trash, within its budget however deep the subtree, the step rebuilds only the moved pages without subpages (one `EXISTS` query decides which, revisions and auto-drafts not counting); a moved page with subpages gets the `_hierarchy_guard_yoast_pending` flag and is rebuilt by `hierarchy_guard_yoast_rebuild`, a run of its own that takes the flagged pages oldest first, one call into Yoast each, checks the time between pages, schedules its next run first and clears it when none is left. Chosen over counting descendants into the inline threshold, which cannot bound one page with a huge subtree. Pages that wait are resumed by `Plugin::setup()` (Task 8).
- Each page is rebuilt in its own `try`/`catch ( Throwable )`, so a page whose content filters fail leaves the rest of the step rebuilt; a Yoast whose internals changed is left to its own reindex. The flag is taken off before the call, so a page that kills a run is not tried forever, and it stays out of WXR exports.
- `Processor::time_limit()` becomes public and static, so the Yoast run and the batch share one rule: 20 seconds, or half of `max_execution_time` when that is lower.
- Probe, 50 moved pages with 20 subpages each (1,051 pages): on WordPress 6.6.10 with Yoast SEO 23.0 the old inline rebuild spent 0.24 s of the trash step in Yoast and 0.31 s of the restore's; now 0.01 s each, and one background run rebuilt the 50 pages in 0.3 s (trash) and 0.1 s (restore), after which all 1,050 indexables matched their permalinks. On WordPress 7.1.5 with Yoast SEO 28.9: 0.21 s and 0.22 s before; now 0.01 s, with runs of 0.2 s and 0.1 s.
- ElasticPress, which the audit names as affected the same way, gets no adapter: the owner chose a readme note (plan 6b).
- The tests run against two small stubs of Yoast's classes (`tests/phpunit/stubs/`), loaded only by this test, so the suite needs no Yoast install.

**Files:**
- Modify: `includes/HierarchyGuard/Batch/Processor.php`
- Create: `includes/HierarchyGuard/Compat/Yoast.php`
- Modify: `includes/HierarchyGuard/Plugin.php`
- Test (create): `tests/phpunit/Yoast_Container.php`
- Test (create): `tests/phpunit/stubs/yoast-main.php`
- Test (create): `tests/phpunit/stubs/yoast-watcher.php`
- Test (create): `tests/phpunit/YoastCompatTest.php`

**Interfaces:**
- Consumes: the action `hierarchy_guard_children_reparented( int[] $ids, int $from, int $to )`, fired by `Trash\Operations` after each trash or restore step.
- Produces: `HierarchyGuard\Compat\Yoast` with `HOOK = 'hierarchy_guard_yoast_rebuild'`, `PENDING = '_hierarchy_guard_yoast_pending'`, `register(): void`, `rebuild( $ids ): void`, `rebuild_pending(): void`, static `resume(): void` (used by Task 8's `Plugin::setup()`), `skip_on_export( $skip, $meta_key ): bool`; static `Processor::time_limit(): int`.

- [ ] **Step 1: Write the failing tests**

Create `tests/phpunit/Yoast_Container.php`:

```php
<?php
/**
 * The container YoastSEO() hands out in tests.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use RuntimeException;
use Yoast\WP\SEO\Integrations\Watchers\Indexable_Post_Watcher;

/**
 * Hands out one stub watcher, the only service the compat class asks for.
 */
final class Yoast_Container {

	/**
	 * The watcher.
	 *
	 * @var Indexable_Post_Watcher
	 */
	private Indexable_Post_Watcher $watcher;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->watcher = new Indexable_Post_Watcher();
	}

	/**
	 * The service of a class.
	 *
	 * @param string $name Class name.
	 * @return Indexable_Post_Watcher
	 * @throws RuntimeException For any other class.
	 */
	public function get( string $name ): Indexable_Post_Watcher {
		if ( Indexable_Post_Watcher::class !== $name ) {
			throw new RuntimeException( 'No such service.' );
		}
		return $this->watcher;
	}
}
```

Create `tests/phpunit/stubs/yoast-main.php`:

```php
<?php
/**
 * Yoast SEO's main function, as the compat class calls it.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

if ( ! function_exists( 'YoastSEO' ) ) {
	/**
	 * An object whose `classes` container hands out the stub watcher.
	 *
	 * @return object
	 */
	function YoastSEO(): object { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound,WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Yoast's own name, stubbed for tests.
		static $main = null;
		if ( null === $main ) {
			$main = (object) [ 'classes' => new HierarchyGuard\Tests\Yoast_Container() ];
		}
		return $main;
	}
}
```

Create `tests/phpunit/stubs/yoast-watcher.php`:

```php
<?php
/**
 * Yoast SEO's post watcher, as the compat class calls it: it records the
 * posts whose indexable it was asked to build.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace Yoast\WP\SEO\Integrations\Watchers; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Yoast's own namespace, stubbed for tests.

/**
 * Records the posts whose indexable it was asked to build.
 */
class Indexable_Post_Watcher {

	/**
	 * Post IDs, in the order asked.
	 *
	 * @var int[]
	 */
	public array $built = [];

	/**
	 * Whether build_indexable() throws, as a changed Yoast might.
	 *
	 * @var bool
	 */
	public bool $broken = false;

	/**
	 * Posts whose build throws an Error, as a page's content filters might.
	 *
	 * @var int[]
	 */
	public array $broken_for = [];

	/**
	 * Records one build.
	 *
	 * @param int $post_id Post ID.
	 * @throws \RuntimeException When broken.
	 * @throws \Error For a post in $broken_for.
	 */
	public function build_indexable( $post_id ): void {
		if ( $this->broken ) {
			throw new \RuntimeException( 'Changed internals.' );
		}
		if ( in_array( (int) $post_id, $this->broken_for, true ) ) {
			throw new \Error( 'A content filter failed.' );
		}
		$this->built[] = (int) $post_id;
	}
}
```

Create `tests/phpunit/YoastCompatTest.php`:

```php
<?php
/**
 * Yoast SEO's indexables after the trash flow moves subpages.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Compat\Yoast;
use WP_UnitTestCase;
use Yoast\WP\SEO\Integrations\Watchers\Indexable_Post_Watcher;

/**
 * @covers \HierarchyGuard\Compat\Yoast
 */
final class YoastCompatTest extends WP_UnitTestCase {

	use Trash_Fixtures;

	/**
	 * Page IDs.
	 *
	 * @var array<string,int>
	 */
	private array $ids;

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		require_once __DIR__ . '/stubs/yoast-watcher.php';
		require_once __DIR__ . '/stubs/yoast-main.php';
	}

	public function set_up(): void {
		parent::set_up();
		$this->set_permalink_structure( '/%postname%/' );
		$this->ids           = $this->make_tree();
		$watcher             = $this->watcher();
		$watcher->built      = [];
		$watcher->broken     = false;
		$watcher->broken_for = [];
	}

	public function tear_down(): void {
		wp_clear_scheduled_hook( Yoast::HOOK );
		$this->set_permalink_structure( '' );
		parent::tear_down();
	}

	public function test_a_moved_page_without_subpages_is_rebuilt_at_once_and_one_with_subpages_in_a_run_of_its_own(): void {
		wp_trash_post( $this->ids['about'] );

		$this->assertSame( $this->ids['company'], (int) get_post( $this->ids['team'] )->post_parent, 'Precondition: the default policy moved them up.' );
		$this->assertSame( [ $this->ids['history'] ], $this->watcher()->built, 'Team has a subpage, so the step leaves it.' );
		$this->assertSame( '1', get_post_meta( $this->ids['team'], Yoast::PENDING, true ) );
		$this->assertNotFalse( wp_next_scheduled( Yoast::HOOK ) );

		( new Yoast() )->rebuild_pending();

		$this->assertSame( [ $this->ids['history'], $this->ids['team'] ], $this->watcher()->built );
		$this->assertSame( '', get_post_meta( $this->ids['team'], Yoast::PENDING, true ) );
		$this->assertFalse( wp_next_scheduled( Yoast::HOOK ), 'Nothing is left for another run.' );
	}

	public function test_the_subpages_a_restore_moves_back_get_their_indexables_rebuilt_again(): void {
		wp_trash_post( $this->ids['about'] );
		( new Yoast() )->rebuild_pending();
		$this->watcher()->built = [];

		wp_untrash_post( $this->ids['about'] );
		( new Yoast() )->rebuild_pending();

		$this->assertSame( $this->ids['about'], (int) get_post( $this->ids['team'] )->post_parent, 'Precondition: the restore moved them back.' );
		$this->assertSame( [ $this->ids['history'], $this->ids['team'] ], $this->watcher()->built );
	}

	public function test_a_page_yoast_fails_on_leaves_the_others_of_the_step_rebuilt(): void {
		$careers                     = $this->page( 'careers', $this->ids['about'] );
		$this->watcher()->broken_for = [ $this->ids['history'] ];

		wp_trash_post( $this->ids['about'] );

		$this->assertSame( [ $careers ], $this->watcher()->built );
	}

	public function test_a_yoast_whose_internals_changed_leaves_the_trash_to_finish(): void {
		$this->watcher()->broken = true;

		wp_trash_post( $this->ids['about'] );
		( new Yoast() )->rebuild_pending();

		$this->assertSame( $this->ids['company'], (int) get_post( $this->ids['team'] )->post_parent );
		$this->assertSame( $this->ids['company'], (int) get_post( $this->ids['history'] )->post_parent );
		$this->assertSame( '', get_post_meta( $this->ids['team'], Yoast::PENDING, true ), 'Tried once, then left to Yoast.' );
	}

	public function test_a_revision_is_not_a_subpage_that_makes_a_page_wait(): void {
		wp_save_post_revision( $this->ids['history'] );
		self::factory()->post->create(
			[
				'post_type'   => 'revision',
				'post_status' => 'inherit',
				'post_parent' => $this->ids['history'],
				'post_name'   => "{$this->ids['history']}-revision-v1",
			]
		);

		wp_trash_post( $this->ids['about'] );

		$this->assertContains( $this->ids['history'], $this->watcher()->built );
	}

	public function test_activation_resumes_pages_still_waiting(): void {
		wp_trash_post( $this->ids['about'] );
		wp_clear_scheduled_hook( Yoast::HOOK );

		Yoast::resume();

		$this->assertNotFalse( wp_next_scheduled( Yoast::HOOK ) );
	}

	public function test_the_pending_flag_stays_out_of_exports(): void {
		$yoast = new Yoast();

		$this->assertTrue( $yoast->skip_on_export( false, Yoast::PENDING ) );
		$this->assertFalse( $yoast->skip_on_export( false, '_hierarchy_guard_yoast' ) );
		$this->assertTrue( $yoast->skip_on_export( true, 'other' ) );
	}

	/**
	 * The stub watcher YoastSEO() hands out.
	 *
	 * @return Indexable_Post_Watcher
	 */
	private function watcher(): Indexable_Post_Watcher {
		return YoastSEO()->classes->get( Indexable_Post_Watcher::class );
	}
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npm run test:php -- --filter 'YoastCompatTest'`

Expected: FAIL as below; every other test in these classes passes.

- 4 tests in `YoastCompatTest`: Error: Class "HierarchyGuard\Compat\Yoast" not found
- `YoastCompatTest::test_a_moved_page_without_subpages_is_rebuilt_at_once_and_one_with_subpages_in_a_run_of_its_own`: Team has a subpage, so the step leaves it.
- `YoastCompatTest::test_a_page_yoast_fails_on_leaves_the_others_of_the_step_rebuilt`: Failed asserting that two arrays are identical.
- `YoastCompatTest::test_a_revision_is_not_a_subpage_that_makes_a_page_wait`: Failed asserting that an array contains 32.

Totals: `Tests: 7, Assertions: 4, Errors: 4, Failures: 3.`

Mutant, to run once the tests pass, which shows `test_a_moved_page_without_subpages_is_rebuilt_at_once_and_one_with_subpages_in_a_run_of_its_own` pins its behaviour: in `includes/HierarchyGuard/Compat/Yoast.php`, replace

```php
$parents = self::with_children( $ids );
```

with:

```php
$parents = [];
```

Expected: the test fails, as it did on the prototype:

```
YoastCompatTest::test_a_moved_page_without_subpages_is_rebuilt_at_once_and_one_with_subpages_in_a_run_of_its_own
Team has a subpage, so the step leaves it.
Tests: 1, Assertions: 2, Failures: 1.
```

Then undo the mutant (`git checkout -- includes/HierarchyGuard/Compat/Yoast.php`). Never commit it.

Mutant, to run once the tests pass, which shows `test_a_page_yoast_fails_on_leaves_the_others_of_the_step_rebuilt` pins its behaviour: in `includes/HierarchyGuard/Compat/Yoast.php`, replace

```php
		try {
			$watcher->build_indexable( $id );
		} catch ( Throwable $e ) {
			return;
		}
```

with:

```php
		$watcher->build_indexable( $id );
```

Expected: the test fails, as it did on the prototype:

```
YoastCompatTest::test_a_page_yoast_fails_on_leaves_the_others_of_the_step_rebuilt
Error: A content filter failed.
Tests: 1, Assertions: 0, Errors: 1.
```

Then undo the mutant (`git checkout -- includes/HierarchyGuard/Compat/Yoast.php`). Never commit it.

- [ ] **Step 3: Implement**

In `includes/HierarchyGuard/Batch/Processor.php`, replace:

```php
		$this->run( (int) $root_id, $this->time_limit() );
```

with:

```php
		$this->run( (int) $root_id, self::time_limit() );
```

In `includes/HierarchyGuard/Batch/Processor.php`, replace:

```php
	private function time_limit(): int {
```

with:

```php
	public static function time_limit(): int {
```

Create `includes/HierarchyGuard/Compat/Yoast.php`:

```php
<?php
/**
 * Keeps Yoast SEO's indexables right after a direct re-parent.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Compat;

use HierarchyGuard\Batch\Processor;
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
				update_post_meta( $id, self::PENDING, '1' );
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
		// Scheduled first, so a run that dies part way still leaves the next one.
		self::schedule();
		while ( true ) {
			$ids = self::pending( self::BATCH );
			if ( [] === $ids ) {
				break;
			}
			foreach ( $ids as $id ) {
				if ( microtime( true ) - $start >= $limit ) {
					return;
				}
				// Taken off before the call, so a page that kills the run is not tried again forever.
				delete_post_meta( $id, self::PENDING );
				self::build( $watcher, $id );
			}
		}
		wp_clear_scheduled_hook( self::HOOK );
		// A page added after the last read found the run scheduled and left it to this one.
		if ( [] !== self::pending( 1 ) ) {
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
	 * internals changed.
	 *
	 * @return object|null
	 */
	private static function watcher(): ?object {
		if ( ! function_exists( 'YoastSEO' ) || ! class_exists( Indexable_Post_Watcher::class ) ) {
			return null;
		}
		try {
			$watcher = YoastSEO()->classes->get( Indexable_Post_Watcher::class );
		} catch ( Throwable $e ) {
			return null;
		}
		return is_object( $watcher ) ? $watcher : null;
	}

	/**
	 * Rebuilds one page's indexable. A page Yoast fails on is left to Yoast's
	 * own reindex, and the others still get theirs.
	 *
	 * @param object $watcher Yoast's post watcher.
	 * @param int    $id      Post ID.
	 */
	private static function build( object $watcher, int $id ): void {
		try {
			$watcher->build_indexable( $id );
		} catch ( Throwable $e ) {
			return;
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
	 * Pending pages, oldest first.
	 *
	 * @param int $limit Most to read.
	 * @return int[]
	 */
	private static function pending( int $limit ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- A range on the meta_key index, bounded by LIMIT.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s ORDER BY meta_id ASC LIMIT %d", self::PENDING, max( 1, $limit ) ) );
		return array_map( 'intval', (array) $ids );
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
```

In `includes/HierarchyGuard/Plugin.php`, replace:

```php
		( new Trash\Trash_Handler( $ledger, $operations, $queue, $log ) )->register();
```

with:

```php
		( new Trash\Trash_Handler( $ledger, $operations, $queue, $log ) )->register();
		( new Compat\Yoast() )->register();
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `npm run test:php -- --filter 'YoastCompatTest'`

Expected: `OK`.

Run: `npm run test:php`, `npm run test:php:multisite` and `npm run test:js`

Expected: `Tests: 957, Assertions: 4216, Skipped: 5.` (single site), `OK (957 tests, 4253 assertions)` (multisite), and Vitest `Tests 174 passed (174)`.

- [ ] **Step 5: Lint and build**

Run: `composer lint && npm run lint:js && npm run lint:style && npm run build`

Expected: every command exits 0.

- [ ] **Step 6: Commit**

```bash
git add includes/HierarchyGuard/Batch/Processor.php includes/HierarchyGuard/Compat/Yoast.php includes/HierarchyGuard/Plugin.php tests/phpunit/YoastCompatTest.php tests/phpunit/Yoast_Container.php tests/phpunit/stubs/yoast-main.php tests/phpunit/stubs/yoast-watcher.php
git commit -m "fix: rebuild Yoast SEO's indexables for the subpages a trash or restore moves"
```

---

### Task 4: Only the plugin writes its own post meta; duplicators leave it out

**Findings:** 5.1

- A duplicator copied every `_hierarchy_guard_*` row, so a published copy became the newest holder of an old URL, and a restore also moved the copy. `Meta_Guard` refuses, on `add_post_metadata`, a new row of a moved or trashed marker always, and of any other `_hierarchy_guard_` key unless the plugin is writing it or a WordPress import is running (`WP_IMPORTING`), where a ledger row is kept as the export carried it and the rebuild of the newest-records list is brought forward (Task 8 adds that call).
- The plugin's own writers (`Ledger::record()`, `Ledger::set_target()`, `Operations::record_aliases()`, and Task 3's Yoast flag) wrap their writes in `Meta_Guard::writing()`, a depth counter, so nested writes stay allowed. Markers write with direct SQL and never meet the filter.
- Yoast Duplicate Post's own exclude list gets `_hierarchy_guard_*`, as the verifier proposed, so its New Draft and Clone never even try. Its Rewrite & Republish copies skip that filter, and `refuse_copies()` refuses their rows.
- Tests that seed ledger rows directly now do it inside `Meta_Guard::writing()`.

**Files:**
- Modify: `includes/HierarchyGuard/Compat/Yoast.php`
- Modify: `includes/HierarchyGuard/Ledger.php`
- Create: `includes/HierarchyGuard/Meta_Guard.php`
- Modify: `includes/HierarchyGuard/Plugin.php`
- Modify: `includes/HierarchyGuard/Trash/Operations.php`
- Test (modify): `tests/phpunit/LedgerTest.php`
- Test (create): `tests/phpunit/MetaGuardTest.php`
- Test (modify): `tests/phpunit/RecordsTest.php`

**Interfaces:**
- Produces: `HierarchyGuard\Meta_Guard` with `PREFIX = '_hierarchy_guard_'`, `register(): void`, `static writing( callable $write )` (returns the callable's value), `refuse_copies( $check, $object_id, $meta_key )` on `add_post_metadata`, `static allowed( string $key, bool $writing, bool $importing ): bool`, `exclude_from_duplicates( $excluded ): array` on `duplicate_post_excludelist_filter`. Task 8 splits `refuse_copies()` into a `check( $check, string $key, bool $importing )`.

- [ ] **Step 1: Write the failing tests**

In `tests/phpunit/LedgerTest.php`, replace:

```php
use HierarchyGuard\Ledger;
```

with:

```php
use HierarchyGuard\Ledger;
use HierarchyGuard\Meta_Guard;
```

In `tests/phpunit/LedgerTest.php`, replace:

```php
		return (int) add_post_meta( $post_id, Ledger::key( 'page', $path ), $path, true );
```

with:

```php
		return (int) Meta_Guard::writing( static fn() => add_post_meta( $post_id, Ledger::key( 'page', $path ), $path, true ) );
```

In `tests/phpunit/LedgerTest.php`, replace:

```php
		$new   = (int) add_post_meta( $this->about, Ledger::key( 'page', 'new' ), 'new', true );
```

with:

```php
		$new   = (int) Meta_Guard::writing( fn() => add_post_meta( $this->about, Ledger::key( 'page', 'new' ), 'new', true ) );
```

In `tests/phpunit/LedgerTest.php`, replace:

```php
		$entry = (int) add_post_meta( $doc, Ledger::key( 'hg_docs', 'manual' ), 'manual', true );
```

with:

```php
		$entry = (int) Meta_Guard::writing( static fn() => add_post_meta( $doc, Ledger::key( 'hg_docs', 'manual' ), 'manual', true ) );
```

In `tests/phpunit/LedgerTest.php`, replace:

```php
		$other = (int) add_post_meta( $this->about, Ledger::key( 'page', 'other' ), 'other', true );
```

with:

```php
		$other = (int) Meta_Guard::writing( fn() => add_post_meta( $this->about, Ledger::key( 'page', 'other' ), 'other', true ) );
```

Create `tests/phpunit/MetaGuardTest.php`:

```php
<?php
/**
 * Other plugins' copies of the plugin's post meta.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Ledger;
use HierarchyGuard\Meta_Guard;
use HierarchyGuard\Redirects\Resolver;
use HierarchyGuard\Trash\Markers;
use WP_UnitTestCase;

/**
 * @covers \HierarchyGuard\Meta_Guard
 */
final class MetaGuardTest extends WP_UnitTestCase {

	use Trash_Fixtures;

	/**
	 * Page IDs.
	 *
	 * @var array<string,int>
	 */
	private array $ids;

	public function set_up(): void {
		parent::set_up();
		delete_option( Ledger::ROOT_OPTION );
		$this->set_permalink_structure( '/%postname%/' );
		$this->ids = $this->make_tree();
	}

	public function tear_down(): void {
		$this->set_permalink_structure( '' );
		parent::tear_down();
	}

	public function test_a_copy_of_a_renamed_page_takes_none_of_its_old_urls(): void {
		wp_update_post(
			[
				'ID'        => $this->ids['team'],
				'post_name' => 'team-new',
			]
		);
		$copy = $this->duplicate( $this->ids['team'] );

		$this->assertSame( [], $this->plugin_keys( $copy ) );
		wp_update_post(
			[
				'ID'          => $copy,
				'post_name'   => 'team-copy',
				'post_status' => 'publish',
			]
		);
		$this->assertSame( $this->ids['team'], $this->resolve( 'company/about/team' ) );
	}

	public function test_a_copy_of_a_moved_subpage_stays_where_it_is_when_the_trash_is_restored(): void {
		wp_trash_post( $this->ids['about'] );
		$this->assertSame( $this->ids['company'], (int) get_post( $this->ids['team'] )->post_parent, 'Precondition: the default policy moved it up.' );
		$copy = $this->duplicate( $this->ids['team'] );

		$this->assertSame( [], $this->plugin_keys( $copy ) );
		wp_untrash_post( $this->ids['about'] );

		$this->assertSame( $this->ids['about'], (int) get_post( $this->ids['team'] )->post_parent );
		$this->assertSame( $this->ids['company'], (int) get_post( $copy )->post_parent );
	}

	public function test_the_plugins_own_writes_still_land(): void {
		$ledger = new Ledger();
		$ledger->record( $this->ids['history'], 'old-history' );
		$ledger->set_target( 'page', 'old-history', $this->ids['history'], 'https://example.org/', 302 );
		wp_trash_post( $this->ids['about'] );

		$this->assertTrue( metadata_exists( 'post', $this->ids['history'], Ledger::key( 'page', 'old-history' ) ) );
		$this->assertTrue( metadata_exists( 'post', $this->ids['history'], Ledger::target_key( 'page', 'old-history' ) ) );
		$this->assertTrue( metadata_exists( 'post', $this->ids['about'], Markers::ALIASES ) );
		$this->assertTrue( metadata_exists( 'post', $this->ids['team'], Markers::moved_key( $this->ids['about'] ) ) );
	}

	public function test_only_the_plugin_or_an_import_adds_ledger_rows_and_nothing_adds_a_marker(): void {
		$path = Ledger::key( 'page', 'x' );

		$this->assertTrue( Meta_Guard::allowed( $path, true, false ) );
		$this->assertTrue( Meta_Guard::allowed( $path, false, true ) );
		$this->assertFalse( Meta_Guard::allowed( $path, false, false ) );
		$this->assertTrue( Meta_Guard::allowed( Markers::ALIASES, false, true ) );
		foreach ( [ Markers::moved_key( 7 ), Markers::trashed_key( 7 ) ] as $marker ) {
			$this->assertFalse( Meta_Guard::allowed( $marker, true, true ), $marker );
		}
	}

	public function test_other_meta_is_left_alone(): void {
		$this->assertIsInt( add_post_meta( $this->ids['team'], '_other_plugin', 'x' ) );
		$this->assertIsInt( add_post_meta( $this->ids['team'], 'hierarchy_guard_lookalike', 'x' ) );
		$this->assertFalse( add_post_meta( $this->ids['team'], Ledger::key( 'page', 'forged' ), 'forged' ) );
	}

	public function test_yoast_duplicate_post_leaves_the_keys_out(): void {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Yoast Duplicate Post's own filter.
		$this->assertSame( [ '_edit_lock', '_hierarchy_guard_*' ], apply_filters( 'duplicate_post_excludelist_filter', [ '_edit_lock' ] ) );
	}

	/**
	 * Copies a page as Yoast Duplicate Post does by default: a draft with no
	 * slug under the same parent, with every meta row added again.
	 *
	 * @param int $post_id Page to copy.
	 * @return int The copy.
	 */
	private function duplicate( int $post_id ): int {
		$post = get_post( $post_id );
		$copy = self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_status' => 'draft',
				'post_title'  => $post->post_title,
				'post_parent' => $post->post_parent,
			]
		);
		foreach ( (array) get_post_custom_keys( $post_id ) as $key ) {
			foreach ( (array) get_post_custom_values( $key, $post_id ) as $value ) {
				add_post_meta( $copy, $key, wp_slash( maybe_unserialize( $value ) ) );
			}
		}
		return $copy;
	}

	/**
	 * A post's meta keys of the plugin.
	 *
	 * @param int $post_id Post ID.
	 * @return string[]
	 */
	private function plugin_keys( int $post_id ): array {
		return array_values( array_filter( array_keys( (array) get_post_meta( $post_id ) ), static fn( $key ): bool => str_starts_with( (string) $key, Meta_Guard::PREFIX ) ) );
	}

	/**
	 * The ID of the page an old path resolves to, or 0.
	 *
	 * @param string $path Old path.
	 * @return int
	 */
	private function resolve( string $path ): int {
		$post = ( new Resolver( new Ledger() ) )->resolve( 'page', $path );
		return $post instanceof \WP_Post ? (int) $post->ID : 0;
	}
}
```

In `tests/phpunit/RecordsTest.php`, replace:

```php
use HierarchyGuard\Ledger;
```

with:

```php
use HierarchyGuard\Ledger;
use HierarchyGuard\Meta_Guard;
```

In `tests/phpunit/RecordsTest.php`, replace:

```php
		add_post_meta( $this->ids['team'], Markers::moved_key( $this->ids['about'] ), 'x' );
		add_post_meta( $this->ids['team'], Markers::ALIASES, 'x' );
```

with:

```php
		Markers::add( $this->ids['team'], Markers::moved_key( $this->ids['about'] ), 'x' );
		Meta_Guard::writing( fn() => add_post_meta( $this->ids['team'], Markers::ALIASES, 'x' ) );
```

In `tests/phpunit/RecordsTest.php`, replace:

```php
		add_post_meta( $this->ids['team'], Ledger::key( 'hg_docs', 'crew' ), 'crew' );
```

with:

```php
		Meta_Guard::writing( fn() => add_post_meta( $this->ids['team'], Ledger::key( 'hg_docs', 'crew' ), 'crew' ) );
```

In `tests/phpunit/RecordsTest.php`, replace:

```php
		add_post_meta( $this->ids['about'], Ledger::key( 'page', 'not-listed' ), 'not-listed', true );
```

with:

```php
		Meta_Guard::writing( fn() => add_post_meta( $this->ids['about'], Ledger::key( 'page', 'not-listed' ), 'not-listed', true ) );
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npm run test:php -- --filter 'LedgerTest|MetaGuardTest|RecordsTest'`

Expected: FAIL as below; every other test in these classes passes.

- 15 tests in `LedgerTest`, `MetaGuardTest`, `RecordsTest`: Error: Class "HierarchyGuard\Meta_Guard" not found
- `MetaGuardTest::test_other_meta_is_left_alone`: Failed asserting that 297 is false.
- `MetaGuardTest::test_yoast_duplicate_post_leaves_the_keys_out`: Failed asserting that two arrays are identical.

Totals: `Tests: 118, Assertions: 488, Errors: 15, Failures: 2, Skipped: 2.`

Mutant, to run once the tests pass, which shows `test_a_copy_of_a_renamed_page_takes_none_of_its_old_urls` pins its behaviour: in `includes/HierarchyGuard/Meta_Guard.php`, replace

```php
if ( ! self::allowed( $key, self::$writing > 0, $importing ) ) {
```

with:

```php
if ( false ) {
```

Expected: the test fails, as it did on the prototype:

```
MetaGuardTest::test_a_copy_of_a_renamed_page_takes_none_of_its_old_urls
Failed asserting that two arrays are identical.
Tests: 1, Assertions: 1, Failures: 1.
```

Then undo the mutant (`git checkout -- includes/HierarchyGuard/Meta_Guard.php`). Never commit it.

- [ ] **Step 3: Implement**

In `includes/HierarchyGuard/Compat/Yoast.php`, replace:

```php
use HierarchyGuard\Batch\Processor;
```

with:

```php
use HierarchyGuard\Batch\Processor;
use HierarchyGuard\Meta_Guard;
```

In `includes/HierarchyGuard/Compat/Yoast.php`, replace:

```php
				update_post_meta( $id, self::PENDING, '1' );
```

with:

```php
				Meta_Guard::writing( static fn() => update_post_meta( $id, self::PENDING, '1' ) );
```

In `includes/HierarchyGuard/Ledger.php`, replace:

```php
		$meta_id = add_post_meta( $post_id, $key, $old_path, true );
```

with:

```php
		$meta_id = Meta_Guard::writing( static fn() => add_post_meta( $post_id, $key, $old_path, true ) );
```

In `includes/HierarchyGuard/Ledger.php`, replace:

```php
				update_post_meta( $holder, $key, $target );
```

with:

```php
				Meta_Guard::writing( static fn() => update_post_meta( $holder, $key, $target ) );
```

Create `includes/HierarchyGuard/Meta_Guard.php`:

```php
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
 * too. So the plugin's keys are written only by the plugin itself: markers
 * through their own direct insert, ledger rows inside writing(). A WordPress
 * import keeps its ledger rows, as an export carries them.
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
```

In `includes/HierarchyGuard/Plugin.php`, replace:

```php
		( new Trash\Markers() )->register();
```

with:

```php
		( new Trash\Markers() )->register();
		( new Meta_Guard() )->register();
```

In `includes/HierarchyGuard/Trash/Operations.php`, replace:

```php
use HierarchyGuard\Ledger;
```

with:

```php
use HierarchyGuard\Ledger;
use HierarchyGuard\Meta_Guard;
```

In `includes/HierarchyGuard/Trash/Operations.php`, replace:

```php
			update_post_meta( $root, Markers::ALIASES, array_merge( is_array( $stored ) ? $stored : [], $written ) );
```

with:

```php
			Meta_Guard::writing( static fn() => update_post_meta( $root, Markers::ALIASES, array_merge( is_array( $stored ) ? $stored : [], $written ) ) );
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `npm run test:php -- --filter 'LedgerTest|MetaGuardTest|RecordsTest'`

Expected: `OK`.

Run: `npm run test:php`, `npm run test:php:multisite` and `npm run test:js`

Expected: `Tests: 963, Assertions: 4236, Skipped: 5.` (single site), `OK (963 tests, 4273 assertions)` (multisite), and Vitest `Tests 174 passed (174)`.

- [ ] **Step 5: Lint and build**

Run: `composer lint && npm run lint:js && npm run lint:style && npm run build`

Expected: every command exits 0.

- [ ] **Step 6: Commit**

```bash
git add includes/HierarchyGuard/Compat/Yoast.php includes/HierarchyGuard/Ledger.php includes/HierarchyGuard/Meta_Guard.php includes/HierarchyGuard/Plugin.php includes/HierarchyGuard/Trash/Operations.php tests/phpunit/LedgerTest.php tests/phpunit/MetaGuardTest.php tests/phpunit/RecordsTest.php
git commit -m "fix: keep duplicators and other plugins from copying the plugin's post meta"
```

---

### Task 5: Each detected redirect plugin gets its real CSV import layout, or a plain file not named after it

**Findings:** 6.1, 1.5, 6.7, and the plan audits' C-4 and U-16 (the CSV route)

- The verifier's fix, as written: Yoast SEO Premium `Origin,Target,Type,Format` with `plain` and Unix line endings; Rank Math `source,destination,type`; Simple 301 Redirects `request,destination`; Redirection and Safe Redirect Manager keep `source,target,regex,code`. SEOPress PRO, AIOSEO and any provider a filter adds without a layout get the plain `source,target,code` file, and the button reads "Download CSV" without a name.
- `Csv::LAYOUTS` now maps each header to the field it holds, so a layout is data, not code. Providers carry an optional `note`: Safe Redirect Manager imports only from WP-CLI, Rank Math only in PRO, Simple 301 imports every row as a 301. Under the button the modal adds, for any provider, "If <plugin> already added any of these redirects itself, skip those rows." (6.7, the simple fix).
- Redirection matches the whole request path, so on a site in a subdirectory its file carries the home path (`/blog/about/`), and a relative target too; Safe Redirect Manager, Rank Math, Simple 301 and Yoast Premium strip the home path themselves and keep home-relative paths.
- `Providers::described()` is the one shape the scripts and the hierarchy route receive (name, url, csv, note), so `Assets` and `Overview` no longer build it each.
- README: the CSV sentence names the layouts. This is the one README change 6.1 needs; the README rewrite is 6b.
- The CSV route is pinned with layouts no button sends (wrong case, a trailing space, empty, an array, two layouts, a path) and with each new layout's first lines over REST, Yoast's on Unix line endings.

**Files:**
- Modify: `includes/HierarchyGuard/Admin/Assets.php`
- Modify: `includes/HierarchyGuard/Export/Csv.php`
- Modify: `includes/HierarchyGuard/Overview.php`
- Modify: `includes/HierarchyGuard/Redirects/Providers.php`
- Modify: `src/common/copy.js`
- Modify: `src/common/redirects-modal.js`
- Modify: `README.md`
- Test (modify): `src/common/test/copy.test.js`
- Test (modify): `tests/phpunit/ChangesControllerTest.php`
- Test (modify): `tests/phpunit/CsvTest.php`
- Test (modify): `tests/phpunit/ProvidersTest.php`

**Interfaces:**
- Produces: `Csv::LAYOUTS` as `array<string, array<string header, string field>>` with fields `old`, `new`, `regex`, `code`, `format`; layouts `redirection`, `srm`, `yoast`, `rank-math`, `simple-301`, `generic`.
- Produces: `Providers::described( ?array $provider ): ?array{name:string,url:string,csv:string,note:string}`; the `hierarchy_guard_redirect_providers` entries may carry `note`.
- Produces (JS, `src/common/copy.js`): `csvLabel( provider ): string` and `importNotes( provider ): string[]`, used by `src/common/redirects-modal.js`.

- [ ] **Step 1: Write the failing tests**

In `src/common/test/copy.test.js`, replace:

```js
	deniedSentence,
	dialogTitle,
	formatCount,
```

with:

```js
	csvLabel,
	deniedSentence,
	dialogTitle,
	formatCount,
	importNotes,
```

In `src/common/test/copy.test.js`, replace:

```js

describe( 'what redirects the pairs', () => {
```

with:

```js

describe( 'the CSV download', () => {
	it( 'names the redirect plugin only for a file in its own layout', () => {
		expect( csvLabel( { name: 'Redirection', csv: 'redirection' } ) ).toBe(
			'Download CSV for Redirection'
		);
		expect( csvLabel( { name: 'SEOPress PRO', csv: 'generic' } ) ).toBe(
			'Download CSV'
		);
		expect( csvLabel( { name: '', csv: 'yoast' } ) ).toBe( 'Download CSV' );
		expect( csvLabel( null ) ).toBe( 'Download CSV' );
	} );

	it( 'says what to know before importing, the plugin’s own note first', () => {
		expect(
			importNotes( {
				name: 'Rank Math',
				note: 'Rank Math imports a CSV only in its PRO version.',
			} )
		).toEqual( [
			'Rank Math imports a CSV only in its PRO version.',
			'If Rank Math already added any of these redirects itself, skip those rows.',
		] );
		expect( importNotes( { name: '', note: '' } ) ).toEqual( [
			'If your redirect plugin already added any of these redirects itself, skip those rows.',
		] );
		expect( importNotes( null ) ).toEqual( [] );
	} );
} );

describe( 'what redirects the pairs', () => {
```

In `tests/phpunit/ChangesControllerTest.php`, replace:

```php
	public function test_an_unknown_csv_layout_is_refused(): void {
		$this->assertSame( 400, $this->get( '/changes/' . $this->change_id . '/csv', [ 'format' => 'excel' ] )->get_status() );
```

with:

```php
	/**
	 * Layouts the download offers no button for.
	 *
	 * @return array<string,array{0:mixed}>
	 */
	public static function crafted_formats(): array {
		return [
			'unknown'          => [ 'excel' ],
			'wrong case'       => [ 'Yoast' ],
			'trailing space'   => [ 'yoast ' ],
			'empty'            => [ '' ],
			'array'            => [ [ 'yoast' ] ],
			'two layouts'      => [ 'yoast,redirection' ],
			'path to a layout' => [ '../yoast' ],
		];
	}

	/**
	 * @dataProvider crafted_formats
	 *
	 * @param mixed $format The format parameter.
	 */
	public function test_a_layout_the_buttons_never_send_is_refused( $format ): void {
		$this->assertSame( 400, $this->get( '/changes/' . $this->change_id . '/csv', [ 'format' => $format ] )->get_status() );
	}

	/**
	 * Each documented layout's first two lines.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function layouts(): array {
		return [
			'Yoast SEO Premium'    => [ 'yoast', "Origin,Target,Type,Format\n/company/about/history/,/company/history/,302,plain\n" ],
			'Rank Math'            => [ 'rank-math', "source,destination,type\r\n/company/about/history/,/company/history/,302\r\n" ],
			'Simple 301 Redirects' => [ 'simple-301', "request,destination\r\n/company/about/history/,/company/history/\r\n" ],
		];
	}

	/**
	 * @dataProvider layouts
	 *
	 * @param string $format Layout.
	 * @param string $start  The file's first two lines.
	 */
	public function test_the_route_gives_each_documented_layout( string $format, string $start ): void {
		$response = $this->get( '/changes/' . $this->change_id . '/csv', [ 'format' => $format ] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertStringStartsWith( $start, $response->get_data() );
```

In `tests/phpunit/CsvTest.php`, replace:

```php

	public function test_the_generic_layout_has_source_target_and_code(): void {
```

with:

```php

	public function test_on_a_site_in_a_subdirectory_only_redirection_s_paths_carry_the_home_path(): void {
		$home  = static fn(): string => 'http://example.org/blog';
		add_filter( 'pre_option_home', $home );
		$pairs = [
			[
				'old' => '/about/team/',
				'new' => '/company/team/',
			],
			[
				'old' => '/old/',
				'new' => 'https://other.example/new/',
			],
		];

		$redirection = Csv::build( 'redirection', $pairs );
		$srm         = Csv::build( 'srm', $pairs );
		$yoast       = Csv::build( 'yoast', $pairs );
		remove_filter( 'pre_option_home', $home );

		$this->assertSame( "source,target,regex,code\r\n/blog/about/team/,/blog/company/team/,0,301\r\n/blog/old/,https://other.example/new/,0,301\r\n", $redirection );
		$this->assertSame( "source,target,regex,code\r\n/about/team/,/company/team/,0,301\r\n/old/,https://other.example/new/,0,301\r\n", $srm, 'Safe Redirect Manager strips the home path itself.' );
		$this->assertStringContainsString( "\n/about/team/,/company/team/,301,plain\n", $yoast );
	}

	public function test_the_generic_layout_has_source_target_and_code(): void {
```

In `tests/phpunit/CsvTest.php`, replace:

```php

	public function test_an_unknown_layout_falls_back_to_generic(): void {
```

with:

```php

	public function test_yoast_reads_origin_target_type_and_a_plain_format_on_unix_lines(): void {
		$pairs                 = $this->pairs();
		$pairs[1]['temporary'] = true;

		$this->assertSame(
			"Origin,Target,Type,Format\n/about/team/,/company/team/,301,plain\n/about/team/leadership/,/company/team/leadership/,302,plain\n",
			Csv::build( 'yoast', $pairs )
		);
	}

	public function test_rank_math_reads_source_destination_and_type(): void {
		$this->assertSame( "source,destination,type\r\n/about/team/,/company/team/,301\r\n", Csv::build( 'rank-math', [ $this->pairs()[0] ] ) );
	}

	public function test_simple_301_reads_request_and_destination_only(): void {
		$pairs                 = $this->pairs();
		$pairs[1]['temporary'] = true;

		$this->assertSame(
			"request,destination\r\n/about/team/,/company/team/\r\n/about/team/leadership/,/company/team/leadership/\r\n",
			Csv::build( 'simple-301', $pairs )
		);
	}

	public function test_an_unknown_layout_falls_back_to_generic(): void {
```

In `tests/phpunit/ProvidersTest.php`, replace:

```php

use HierarchyGuard\Redirects\Providers;
```

with:

```php

use HierarchyGuard\Export\Csv;
use HierarchyGuard\Redirects\Providers;
```

In `tests/phpunit/ProvidersTest.php`, replace:

```php
			$this->assertContains( $all[ $slug ]['csv'], [ 'redirection', 'srm', 'generic' ] );
		}
	}

```

with:

```php
			$this->assertArrayHasKey( $all[ $slug ]['csv'], Csv::LAYOUTS );
		}
	}

	public function test_each_provider_gets_its_own_import_layout_or_the_generic_one(): void {
		$layouts = array_map( static fn( array $provider ): string => $provider['csv'], ( new Providers() )->all() );

		$this->assertSame(
			[
				'redirection'           => 'redirection',
				'safe-redirect-manager' => 'srm',
				'yoast-premium'         => 'yoast',
				'rank-math'             => 'rank-math',
				'aioseo'                => 'generic',
				'seopress-pro'          => 'generic',
				'simple-301'            => 'simple-301',
			],
			$layouts
		);
	}

	public function test_a_provider_whose_import_needs_more_says_so(): void {
		$all = ( new Providers() )->all();

		$this->assertStringContainsString( 'wp safe-redirect-manager import', $all['safe-redirect-manager']['note'] );
		$this->assertStringContainsString( 'PRO', $all['rank-math']['note'] );
		$this->assertStringContainsString( '301', $all['simple-301']['note'] );
		$this->assertArrayNotHasKey( 'note', $all['redirection'] );
	}

	public function test_the_scripts_get_a_provider_s_name_screen_layout_and_note(): void {
		$this->assertNull( Providers::described( null ) );
		$this->assertSame(
			[
				'name' => 'Custom',
				'url'  => admin_url( 'admin.php?page=custom' ),
				'csv'  => 'generic',
				'note' => '',
			],
			Providers::described(
				[
					'name' => 'Custom',
					'url'  => admin_url( 'admin.php?page=custom' ),
					'csv'  => 'unknown',
					'note' => [ 'not text' ],
				]
			)
		);
	}

```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npm run test:php -- --filter 'ChangesControllerTest|CsvTest|ProvidersTest'`

Expected: FAIL as below; every other test in these classes passes.

- `ProvidersTest::test_a_provider_whose_import_needs_more_says_so`: Undefined array key "note"
- `ProvidersTest::test_the_scripts_get_a_provider_s_name_screen_layout_and_note`: Error: Call to undefined method HierarchyGuard\Redirects\Providers::described()
- 3 tests in `ChangesControllerTest`: Failed asserting that 400 is identical to 200.
- 4 tests in `CsvTest`: Failed asserting that two strings are identical.
- `ProvidersTest::test_each_provider_gets_its_own_import_layout_or_the_generic_one`: Failed asserting that two arrays are identical.

Totals: `Tests: 42, Assertions: 111, Errors: 2, Failures: 8.`

Run: `npx wp-scripts test-unit-js src/common/test/copy.test.js`

Expected: FAIL:

- `× names the redirect plugin only for a file in its own layout 2ms`
- `× says what to know before importing, the plugin’s own note first 0ms`
- `FAIL  src/common/test/copy.test.js > the CSV download > names the redirect plugin only for a file in its own layout`
- `FAIL  src/common/test/copy.test.js > the CSV download > says what to know before importing, the plugin’s own note first`

Totals: `Tests  2 failed | 27 passed (29)`

Mutant, to run once the tests pass, which shows `test_yoast_reads_origin_target_type_and_a_plain_format_on_unix_lines` pins its behaviour: in `includes/HierarchyGuard/Export/Csv.php`, replace

```php
private const UNIX_LINES = [ 'yoast' ];
```

with:

```php
private const UNIX_LINES = [];
```

Expected: the test fails, as it did on the prototype:

```
CsvTest::test_yoast_reads_origin_target_type_and_a_plain_format_on_unix_lines
Failed asserting that two strings are identical.
Tests: 1, Assertions: 1, Failures: 1.
```

Then undo the mutant (`git checkout -- includes/HierarchyGuard/Export/Csv.php`). Never commit it.

Mutant, to run once the tests pass, which shows `test_on_a_site_in_a_subdirectory_only_redirection_s_paths_carry_the_home_path` pins its behaviour: in `includes/HierarchyGuard/Export/Csv.php`, replace

```php
private const FROM_ROOT = [ 'redirection' ];
```

with:

```php
private const FROM_ROOT = [];
```

Expected: the test fails, as it did on the prototype:

```
CsvTest::test_on_a_site_in_a_subdirectory_only_redirection_s_paths_carry_the_home_path
Failed asserting that two strings are identical.
Tests: 1, Assertions: 1, Failures: 1.
```

Then undo the mutant (`git checkout -- includes/HierarchyGuard/Export/Csv.php`). Never commit it.

- [ ] **Step 3: Implement**

In `includes/HierarchyGuard/Admin/Assets.php`, replace:

```php

use HierarchyGuard\Export\Csv;
```

with:

```php

```

In `includes/HierarchyGuard/Admin/Assets.php`, replace:

```php
			'provider'    => null === $provider ? null : [
				'name' => (string) ( $provider['name'] ?? '' ),
				'url'  => esc_url_raw( (string) ( $provider['url'] ?? '' ) ),
				'csv'  => Csv::layout( (string) ( $provider['csv'] ?? 'generic' ) ),
			],
```

with:

```php
			'provider'    => Providers::described( $provider ),
```

In `includes/HierarchyGuard/Export/Csv.php`, replace:

```php
 * Builds the CSV a detected redirect plugin imports without editing.
 * Redirection and Safe Redirect Manager read source, target, regex and code;
 * every other plugin gets a plain source, target and code layout. The code
 * is the one the built-in server answers with: 302 while a restore from the
 * trash could still undo the move a redirect follows, 301 otherwise.
```

with:

```php
 * Builds the CSV a detected redirect plugin imports. Each layout maps a header
 * to what its cells hold: the old URL, the new one, the regex flag, the code
 * or Yoast's format. A plugin whose import format isn't documented gets the
 * plain generic layout. The code is the one the built-in server answers
 * with: 302 while a restore from the trash could still undo the move a
 * redirect follows, 301 otherwise.
```

In `includes/HierarchyGuard/Export/Csv.php`, replace:

```php
		'redirection' => [ 'source', 'target', 'regex', 'code' ],
		'srm'         => [ 'source', 'target', 'regex', 'code' ],
		'generic'     => [ 'source', 'target', 'code' ],
	];

```

with:

```php
		'redirection' => [
			'source' => 'old',
			'target' => 'new',
			'regex'  => 'regex',
			'code'   => 'code',
		],
		'srm'         => [
			'source' => 'old',
			'target' => 'new',
			'regex'  => 'regex',
			'code'   => 'code',
		],
		'yoast'       => [
			'Origin' => 'old',
			'Target' => 'new',
			'Type'   => 'code',
			'Format' => 'format',
		],
		'rank-math'   => [
			'source'      => 'old',
			'destination' => 'new',
			'type'        => 'code',
		],
		'simple-301'  => [
			'request'     => 'old',
			'destination' => 'new',
		],
		'generic'     => [
			'source' => 'old',
			'target' => 'new',
			'code'   => 'code',
		],
	];

	/**
	 * Layouts whose importer refuses Windows line endings.
	 */
	private const UNIX_LINES = [ 'yoast' ];

	/**
	 * Layouts whose plugin matches the whole request path, so their paths
	 * carry the site's home path: /blog/about/ on a site at /blog/.
	 */
	private const FROM_ROOT = [ 'redirection' ];

```

In `includes/HierarchyGuard/Export/Csv.php`, replace:

```php
		$lines  = [ self::line( self::LAYOUTS[ $layout ] ) ];
		foreach ( $pairs as $pair ) {
			$lines[] = self::line( self::row( $layout, (string) $pair['old'], (string) $pair['new'], ! empty( $pair['temporary'] ) ) );
		}
		return implode( "\r\n", $lines ) . "\r\n";
```

with:

```php
		$base   = in_array( $layout, self::FROM_ROOT, true ) ? untrailingslashit( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) ) : '';
		$lines  = [ self::line( array_keys( self::LAYOUTS[ $layout ] ) ) ];
		foreach ( $pairs as $pair ) {
			$old = (string) $pair['old'];
			$new = (string) $pair['new'];
			if ( '' !== $base ) {
				$old = $base . $old;
				$new = str_starts_with( $new, '/' ) && ! str_starts_with( $new, '//' ) ? $base . $new : $new;
			}
			$lines[] = self::line( self::row( $layout, $old, $new, ! empty( $pair['temporary'] ) ) );
		}
		$end = in_array( $layout, self::UNIX_LINES, true ) ? "\n" : "\r\n";
		return implode( $end, $lines ) . $end;
```

In `includes/HierarchyGuard/Export/Csv.php`, replace:

```php
			'source' => $old_url,
			'target' => $new_url,
			'regex'  => '0',
			'code'   => $temporary ? '302' : '301',
		];
		return array_map( static fn( string $column ): string => $cells[ $column ], self::LAYOUTS[ self::layout( $layout ) ] );
```

with:

```php
			'old'    => $old_url,
			'new'    => $new_url,
			'regex'  => '0',
			'code'   => $temporary ? '302' : '301',
			'format' => 'plain',
		];
		return array_values( array_map( static fn( string $field ): string => $cells[ $field ], self::LAYOUTS[ self::layout( $layout ) ] ) );
```

In `includes/HierarchyGuard/Overview.php`, replace:

```php
use HierarchyGuard\Admin\List_Table;
use HierarchyGuard\Export\Csv;
```

with:

```php
use HierarchyGuard\Admin\List_Table;
```

In `includes/HierarchyGuard/Overview.php`, replace:

```php
				'provider'     => null === $provider ? null : [
					'name' => (string) ( $provider['name'] ?? '' ),
					'url'  => esc_url_raw( (string) ( $provider['url'] ?? '' ) ),
					'csv'  => Csv::layout( (string) ( $provider['csv'] ?? 'generic' ) ),
				],
```

with:

```php
				'provider'     => Providers::described( $provider ),
```

In `includes/HierarchyGuard/Redirects/Providers.php`, replace:

```php
/**
 * Known redirect plugins: a presence check, the URL of their redirects screen,
 * and which CSV layout they import.
```

with:

```php
use HierarchyGuard\Export\Csv;

/**
 * Known redirect plugins: a presence check, the URL of their redirects screen,
 * which CSV layout they import, and what to know before importing it.
```

In `includes/HierarchyGuard/Redirects/Providers.php`, replace:

```php
	 * Every known provider, active or not.
	 *
	 * @return array<string,array{name:string,active:bool,url:string,csv:string}>
```

with:

```php
	 * Every known provider, active or not. A provider with the generic layout
	 * has no documented import format, so its download isn't named after it.
	 *
	 * @return array<string,array{name:string,active:bool,url:string,csv:string,note?:string}>
```

In `includes/HierarchyGuard/Redirects/Providers.php`, replace:

```php
				'csv'    => 'srm',
```

with:

```php
				'csv'    => 'srm',
				/* translators: %s: a WP-CLI command, which stays as it is. */
				'note'   => sprintf( __( 'Safe Redirect Manager imports a CSV only from WP-CLI: %s', 'hierarchy-guard' ), 'wp safe-redirect-manager import <file>' ),
```

In `includes/HierarchyGuard/Redirects/Providers.php`, replace:

```php
				'url'    => admin_url( 'admin.php?page=wpseo_redirects' ),
				'csv'    => 'generic',
```

with:

```php
				'url'    => admin_url( 'admin.php?page=wpseo_redirects' ),
				'csv'    => 'yoast',
```

In `includes/HierarchyGuard/Redirects/Providers.php`, replace:

```php
				'url'    => admin_url( 'admin.php?page=rank-math-redirections' ),
				'csv'    => 'generic',
```

with:

```php
				'url'    => admin_url( 'admin.php?page=rank-math-redirections' ),
				'csv'    => 'rank-math',
				'note'   => __( 'Rank Math imports a CSV only in its PRO version.', 'hierarchy-guard' ),
```

In `includes/HierarchyGuard/Redirects/Providers.php`, replace:

```php
				'url'    => admin_url( 'options-general.php?page=301options' ),
				'csv'    => 'generic',
```

with:

```php
				'url'    => admin_url( 'options-general.php?page=301options' ),
				'csv'    => 'simple-301',
				'note'   => __( 'Simple 301 Redirects imports every row as a 301.', 'hierarchy-guard' ),
```

In `includes/HierarchyGuard/Redirects/Providers.php`, replace:

```php
		 * @param array<string,array{name:string,active:bool,url:string,csv:string}> $providers Keyed by slug.
```

with:

```php
		 * @param array<string,array{name:string,active:bool,url:string,csv:string,note?:string}> $providers Keyed by slug: the plugin's name, whether it is active, its redirects screen, one of the Csv::LAYOUTS names, and an optional sentence shown before importing.
```

In `includes/HierarchyGuard/Redirects/Providers.php`, replace:

```php
	}

	/**
```

with:

```php
	}

	/**
	 * What the scripts and the hierarchy route say about a detected provider.
	 *
	 * @param array<string,mixed>|null $provider From detect().
	 * @return array{name:string,url:string,csv:string,note:string}|null
	 */
	public static function described( ?array $provider ): ?array {
		if ( null === $provider ) {
			return null;
		}
		return [
			'name' => (string) ( $provider['name'] ?? '' ),
			'url'  => esc_url_raw( (string) ( $provider['url'] ?? '' ) ),
			'csv'  => Csv::layout( (string) ( $provider['csv'] ?? 'generic' ) ),
			'note' => is_string( $provider['note'] ?? null ) ? $provider['note'] : '',
		];
	}

	/**
```

In `src/common/copy.js`, replace:

```js
}

/**
 * What handles the redirects of a page's pairs: the server redirects them
```

with:

```js
}

/**
 * The CSV button's label: it names the redirect plugin only when the file is
 * in that plugin's own import layout.
 *
 * @param {(Object|null)} provider The detected redirect plugin: `name` and `csv`, its layout.
 * @return {string} Label.
 */
export function csvLabel( provider ) {
	if ( provider && provider.name && 'generic' !== provider.csv ) {
		return sprintf(
			/* translators: %s: name of the redirect plugin. */
			__( 'Download CSV for %s', 'hierarchy-guard' ),
			provider.name
		);
	}
	return __( 'Download CSV', 'hierarchy-guard' );
}

/**
 * What to know before importing the CSV into the detected redirect plugin:
 * its own note, then that a plugin which adds a redirect for a changed page
 * on its own already has that row.
 *
 * @param {(Object|null)} provider The detected redirect plugin: `name` and `note`.
 * @return {string[]} Sentences.
 */
export function importNotes( provider ) {
	if ( ! provider ) {
		return [];
	}
	const own = provider.name
		? sprintf(
				/* translators: %s: name of the redirect plugin. */
				__(
					'If %s already added any of these redirects itself, skip those rows.',
					'hierarchy-guard'
				),
				provider.name
			)
		: __(
				'If your redirect plugin already added any of these redirects itself, skip those rows.',
				'hierarchy-guard'
			);
	return provider.note ? [ provider.note, own ] : [ own ];
}

/**
 * What handles the redirects of a page's pairs: the server redirects them
```

In `src/common/redirects-modal.js`, replace:

```js
	formatCount,
```

with:

```js
	csvLabel,
	formatCount,
	importNotes,
```

In `src/common/redirects-modal.js`, replace:

```js
								{ name
									? sprintf(
											/* translators: %s: name of the redirect plugin. */
											__(
												'Download CSV for %s',
												'hierarchy-guard'
											),
											name
										)
									: __( 'Download CSV', 'hierarchy-guard' ) }
```

with:

```js
								{ csvLabel( provider ) }
```

In `src/common/redirects-modal.js`, replace:

```js
					</div>
				</>
```

with:

```js
					</div>
					{ data.total > 0 &&
						! serving &&
						importNotes( provider ).map( ( note ) => (
							<p key={ note } className="description">
								{ note }
							</p>
						) ) }
				</>
```

In `README.md`, replace:

```markdown
  On the Pages list and in the block editor, "View redirects" opens a list of each old URL of that change and its new one, which links to the detected redirect plugin, downloads a CSV in its import layout, or copies the list as text. Without JavaScript the link downloads the CSV. A CSV row's code is the one the plugin's own redirect rule chooses (before filters): 302 while restoring a page from the trash could still undo the move its redirect follows, and 301 otherwise. The list stays available for 30 minutes, until the user's next change replaces it, or until the page a save moved, or a trashed parent whose subpages moved up, is deleted for good, and a trash's list covers only its pages still in the Trash. If another user then trashes a subpage that trash moved, that subpage's own subpages are left out of the first trash's list, though with Redirects on they keep redirecting. Old URLs from earlier changes, or from pages deleted for good, are not listed again, and with Redirects on they keep redirecting.
```

with:

```markdown
  On the Pages list and in the block editor, "View redirects" opens a list of each old URL of that change and its new one, which links to the detected redirect plugin, downloads a CSV, or copies the list as text. The CSV is in the plugin's own import layout for Redirection, Safe Redirect Manager (which imports it from WP-CLI), Yoast SEO Premium, Rank Math (whose PRO version imports it) and Simple 301 Redirects (which imports every row as a 301); other redirect plugins get a plain source, target and code file. Without JavaScript the link downloads the CSV. A CSV row's code is the one the plugin's own redirect rule chooses (before filters): 302 while restoring a page from the trash could still undo the move its redirect follows, and 301 otherwise. The list stays available for 30 minutes, until the user's next change replaces it, or until the page a save moved, or a trashed parent whose subpages moved up, is deleted for good, and a trash's list covers only its pages still in the Trash. If another user then trashes a subpage that trash moved, that subpage's own subpages are left out of the first trash's list, though with Redirects on they keep redirecting. Old URLs from earlier changes, or from pages deleted for good, are not listed again, and with Redirects on they keep redirecting.
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `npm run test:php -- --filter 'ChangesControllerTest|CsvTest|ProvidersTest'`

Expected: `OK`.

Run: `npx wp-scripts test-unit-js src/common/test/copy.test.js`

Expected: every test passes.

Run: `npm run test:php`, `npm run test:php:multisite` and `npm run test:js`

Expected: `Tests: 979, Assertions: 4261, Skipped: 5.` (single site), `OK (979 tests, 4298 assertions)` (multisite), and Vitest `Tests 176 passed (176)`.

- [ ] **Step 5: Lint and build**

Run: `composer lint && npm run lint:js && npm run lint:style && npm run build`

Expected: every command exits 0.

- [ ] **Step 6: Commit**

```bash
git add README.md includes/HierarchyGuard/Admin/Assets.php includes/HierarchyGuard/Export/Csv.php includes/HierarchyGuard/Overview.php includes/HierarchyGuard/Redirects/Providers.php src/common/copy.js src/common/redirects-modal.js src/common/test/copy.test.js tests/phpunit/ChangesControllerTest.php tests/phpunit/CsvTest.php tests/phpunit/ProvidersTest.php
git commit -m "fix: give each detected redirect plugin its real CSV import layout, or a plain file it isn't named on"
```

---

### Task 6: Class files stop when requested directly; every list of values is prepared

**Findings:** 10.1, 8.5, 10.4, 10.5, and the plan audits' U-16 (the request URI)

- Every class file gets `if ( ! defined( 'ABSPATH' ) ) { exit; }` after its `use` lines (the files Tasks 3 and 4 create already have it). The main file already had one. A PluginTest scans `includes/` so a new file without the guard fails.
- Integer IN-lists are built as `%d` placeholders and passed through `$wpdb->prepare()` (`Subtree`, `Pairs_Blocks::rows()`, `Records::with_targets()`), `Batch_Resolver` builds a `%s` list for its types, and `Markers::remove()` is now prepared. Each such call carries one `phpcs:ignore` for `ReplacementsWrongNumber`, the sniff's known false positive on spread placeholders.
- The request URI is sanitized at read in `Server::request_uri()` with `esc_url_raw( wp_unslash() )`. The other superglobal reads already sanitize at read (`sanitize_key()`, `absint()`, `sanitize_text_field()`) or compare strictly. Three keep the raw string on purpose, each with its reason on its ignore: the two search terms (sanitizing changes what is searched; escaped where printed), the Edit view's target (sanitize_text_field() strips percent-encoding; `Records::save()` applies `esc_url_raw()`), and the Bulk Edit selection, compared raw only to tell whether it changed and sanitized below. Those ignores stay (simple fix preferred).
- Two meta writes in `Markers` get the `slow_db_query_meta_key` ignore Plugin Check asks for.
- A data provider sends request URIs no link carries (markup, an encoded line break, quotes, a space and braces, raw and percent-encoded UTF-8) and pins what the redirect carries over.

**Files:**
- Modify: `includes/HierarchyGuard/Admin/Assets.php`
- Modify: `includes/HierarchyGuard/Admin/List_Table.php`
- Modify: `includes/HierarchyGuard/Admin/Meta_Box.php`
- Modify: `includes/HierarchyGuard/Admin/Notices.php`
- Modify: `includes/HierarchyGuard/Admin/Redirect_Edit.php`
- Modify: `includes/HierarchyGuard/Admin/Redirects_Table.php`
- Modify: `includes/HierarchyGuard/Admin/Settings_Page.php`
- Modify: `includes/HierarchyGuard/Admin/Trash_Choice.php`
- Modify: `includes/HierarchyGuard/Admin/Tree_View.php`
- Modify: `includes/HierarchyGuard/Batch/CLI_Command.php`
- Modify: `includes/HierarchyGuard/Batch/Processor.php`
- Modify: `includes/HierarchyGuard/Batch/Queue.php`
- Modify: `includes/HierarchyGuard/Change_Listener.php`
- Modify: `includes/HierarchyGuard/Export/Csv.php`
- Modify: `includes/HierarchyGuard/Ledger.php`
- Modify: `includes/HierarchyGuard/Overview.php`
- Modify: `includes/HierarchyGuard/Paths.php`
- Modify: `includes/HierarchyGuard/Plugin.php`
- Modify: `includes/HierarchyGuard/Post_Types.php`
- Modify: `includes/HierarchyGuard/REST/Changes_Controller.php`
- Modify: `includes/HierarchyGuard/REST/Csv_Download.php`
- Modify: `includes/HierarchyGuard/REST/Hierarchy_Controller.php`
- Modify: `includes/HierarchyGuard/Redirects/Batch_Resolver.php`
- Modify: `includes/HierarchyGuard/Redirects/Ledger_View.php`
- Modify: `includes/HierarchyGuard/Redirects/Live_View.php`
- Modify: `includes/HierarchyGuard/Redirects/Pairs.php`
- Modify: `includes/HierarchyGuard/Redirects/Pairs_Blocks.php`
- Modify: `includes/HierarchyGuard/Redirects/Prefetched_View.php`
- Modify: `includes/HierarchyGuard/Redirects/Providers.php`
- Modify: `includes/HierarchyGuard/Redirects/Records.php`
- Modify: `includes/HierarchyGuard/Redirects/Resolver.php`
- Modify: `includes/HierarchyGuard/Redirects/Rules.php`
- Modify: `includes/HierarchyGuard/Redirects/Server.php`
- Modify: `includes/HierarchyGuard/Settings.php`
- Modify: `includes/HierarchyGuard/Subtree.php`
- Modify: `includes/HierarchyGuard/Trash/Change_Log.php`
- Modify: `includes/HierarchyGuard/Trash/Guard.php`
- Modify: `includes/HierarchyGuard/Trash/Markers.php`
- Modify: `includes/HierarchyGuard/Trash/Mover.php`
- Modify: `includes/HierarchyGuard/Trash/Operations.php`
- Modify: `includes/HierarchyGuard/Trash/Request.php`
- Modify: `includes/HierarchyGuard/Trash/Trash_Handler.php`
- Test (modify): `tests/phpunit/PluginTest.php`
- Test (modify): `tests/phpunit/ServerTest.php`

**Interfaces:**
- No new interfaces. `Subtree::count_live_descendants()`, `with_live_children()` and `ordered_children()` keep their signatures.

- [ ] **Step 1: Write the failing tests**

In `tests/phpunit/PluginTest.php`, replace:

```php
	}
```

with:

```php
	}

	public function test_every_shipped_php_file_stops_when_requested_directly(): void {
		$root  = dirname( __DIR__, 2 );
		$files = [ $root . '/hierarchy-guard.php' ];
		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/includes', \FilesystemIterator::SKIP_DOTS ) ) as $file ) {
			if ( 'php' === $file->getExtension() ) {
				$files[] = $file->getPathname();
			}
		}
		$this->assertGreaterThan( 40, count( $files ) );

		foreach ( $files as $file ) {
			$code  = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local source file.
			$guard = preg_match( "/if \\( ! defined\\( 'ABSPATH' \\) \\) \\{\\s*exit;\\s*\\}/", $code, $match, PREG_OFFSET_CAPTURE ) ? $match[0][1] : false;
			$this->assertNotFalse( $guard, $file );
			$this->assertLessThan( 50, substr_count( substr( $code, 0, (int) $guard ), "\n" ), $file . ': within the first 50 lines, where Plugin Check looks.' );
			if ( preg_match( '/^(?:final class|class|interface|trait|require|spl_autoload_register|if \\( ! class_exists)/m', $code, $first, PREG_OFFSET_CAPTURE ) ) {
				$this->assertLessThan( $first[0][1], $guard, $file . ': before any code runs.' );
			}
		}
	}
```

In `tests/phpunit/ServerTest.php`, replace:

```php

	public function test_preserves_feed_and_embed_suffixes(): void {
```

with:

```php

	/**
	 * Query strings a link never carries: what a filter on the redirect URL
	 * sees of each, read through esc_url_raw(), and what the redirect sends,
	 * after wp_validate_redirect().
	 *
	 * @return array<string,array{0:string,1:string,2:string}>
	 */
	public static function crafted_queries(): array {
		return [
			'markup'                => [ 'q=<script>alert(1)</script>', 'q=scriptalert(1)/script', 'q=scriptalert(1)/script' ],
			'encoded line break'    => [ 'a=1%0d%0aSet-Cookie:x=1', 'a=1Set-Cookie:x=1', 'a=1Set-Cookie:x=1' ],
			'quotes'                => [ 'a="b"&c=\'d\'', 'a=b&c=\'d\'', 'a=b&c=d' ],
			'space and braces'      => [ 'a=b c&d={e}', 'a=b%20c&d=e', 'a=b%20c&d=e' ],
			'percent-encoded UTF-8' => [ 'q=caf%C3%A9', 'q=caf%C3%A9', 'q=caf%C3%A9' ],
			'raw UTF-8'             => [ 'q=café', 'q=café', 'q=caf%C3%A9' ],
		];
	}

	/**
	 * @dataProvider crafted_queries
	 *
	 * @param string $query The request's query string.
	 * @param string $seen  What a filter on the redirect URL sees of it.
	 * @param string $sent  What the redirect carries over.
	 */
	public function test_a_crafted_query_string_is_carried_over_only_as_url_characters( string $query, string $seen, string $sent ): void {
		wp_update_post(
			[
				'ID'        => $this->about,
				'post_name' => 'company-info',
			]
		);
		$this->go_to( '/company/about/team/' );
		$_SERVER['REQUEST_URI'] = '/company/about/team/?' . $query;
		$filtered               = '';
		$see                    = static function ( string $url ) use ( &$filtered ): string {
			$filtered = $url;
			return $url;
		};
		add_filter( 'hierarchy_guard_redirect_url', $see );

		$target = $this->server->target_for_current_request();
		remove_filter( 'hierarchy_guard_redirect_url', $see );

		$this->assertSame( get_permalink( $this->team ) . '?' . $seen, $filtered, 'Read sanitized: a filter on the URL never sees the raw request.' );
		$this->assertSame( get_permalink( $this->team ) . '?' . $sent, $target['url'] );
	}

	public function test_preserves_feed_and_embed_suffixes(): void {
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npm run test:php -- --filter 'PluginTest|ServerTest'`

Expected: FAIL as below; every other test in these classes passes.

- `PluginTest::test_every_shipped_php_file_stops_when_requested_directly`: /var/www/html/wp-content/plugins/hierarchy-guard/includes/HierarchyGuard/Paths.php
- 4 tests in `ServerTest`: Read sanitized: a filter on the URL never sees the raw request.

Totals: `Tests: 42, Assertions: 135, Failures: 5.`

Mutant, to run once the tests pass, which shows `test_every_shipped_php_file_stops_when_requested_directly` pins its behaviour: in `includes/HierarchyGuard/Ledger.php`, replace

```php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


```

with nothing.

Expected: the test fails, as it did on the prototype:

```
PluginTest::test_every_shipped_php_file_stops_when_requested_directly
/var/www/html/wp-content/plugins/hierarchy-guard/includes/HierarchyGuard/Ledger.php
Tests: 1, Assertions: 134, Failures: 1.
```

Then undo the mutant (`git checkout -- includes/HierarchyGuard/Ledger.php`). Never commit it.

Mutant, to run once the tests pass, which shows `test_a_crafted_query_string_is_carried_over_only_as_url_characters` pins its behaviour: in `includes/HierarchyGuard/Redirects/Server.php`, replace

```php
esc_url_raw( (string) wp_unslash( $_SERVER['REQUEST_URI'] ) )
```

with:

```php
(string) wp_unslash( $_SERVER['REQUEST_URI'] )
```

Expected: the test fails, as it did on the prototype:

```
ServerTest::test_a_crafted_query_string_is_carried_over_only_as_url_characters with data set "markup" ('q=<script>alert(1)</script>', 'q=scriptalert(1)/script', 'q=scriptalert(1)/script')
Read sanitized: a filter on the URL never sees the raw request.
ServerTest::test_a_crafted_query_string_is_carried_over_only_as_url_characters with data set "encoded line break" ('a=1%0d%0aSet-Cookie:x=1', 'a=1Set-Cookie:x=1', 'a=1Set-Cookie:x=1')
Read sanitized: a filter on the URL never sees the raw request.
ServerTest::test_a_crafted_query_string_is_carried_over_only_as_url_characters with data set "quotes" ('a="b"&c='d'', 'a=b&c='d'', 'a=b&c=d')
Read sanitized: a filter on the URL never sees the raw request.
ServerTest::test_a_crafted_query_string_is_carried_over_only_as_url_characters with data set "space and braces" ('a=b c&d={e}', 'a=b%20c&d=e', 'a=b%20c&d=e')
Read sanitized: a filter on the URL never sees the raw request.
Tests: 6, Assertions: 8, Failures: 4.
```

Then undo the mutant (`git checkout -- includes/HierarchyGuard/Redirects/Server.php`). Never commit it.

- [ ] **Step 3: Implement**

In `includes/HierarchyGuard/Admin/Assets.php`, replace:

```php
use WP_Screen;

/**
```

with:

```php
use WP_Screen;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
```

In `includes/HierarchyGuard/Admin/List_Table.php`, replace:

```php
use WP_Query;

/**
```

with:

```php
use WP_Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
```

In `includes/HierarchyGuard/Admin/Meta_Box.php`, replace:

```php
use WP_Post;

/**
```

with:

```php
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
```

In `includes/HierarchyGuard/Admin/Notices.php`, replace:

```php
use WP_Screen;

/**
```

with:

```php
use WP_Screen;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
```

In `includes/HierarchyGuard/Admin/Redirect_Edit.php`, replace:

```php
use HierarchyGuard\Redirects\Records;

/**
```

with:

```php
use HierarchyGuard\Redirects\Records;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
```

In `includes/HierarchyGuard/Admin/Redirects_Table.php`, replace:

```php

if ( ! class_exists( WP_List_Table::class ) ) {
```

with:

```php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( WP_List_Table::class ) ) {
```

In `includes/HierarchyGuard/Admin/Settings_Page.php`, replace:

```php
use HierarchyGuard\Subtree;

/**
```

with:

```php
use HierarchyGuard\Subtree;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
```

In `includes/HierarchyGuard/Admin/Trash_Choice.php`, replace:

```php
use WP_Post;

/**
```

with:

```php
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
```

In `includes/HierarchyGuard/Admin/Tree_View.php`, replace:

```php
use WP_Query;

/**
```

with:

```php
use WP_Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
```

In `includes/HierarchyGuard/Batch/CLI_Command.php`, replace:

```php
use HierarchyGuard\Trash\Operations;

/**
```

with:

```php
use HierarchyGuard\Trash\Operations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
```

In `includes/HierarchyGuard/Batch/Processor.php`, replace:

```php
use HierarchyGuard\Trash\Operations;

/**
```

with:

```php
use HierarchyGuard\Trash\Operations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
```

In `includes/HierarchyGuard/Batch/Queue.php`, replace:

```php
namespace HierarchyGuard\Batch;

/**
```

with:

```php
namespace HierarchyGuard\Batch;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
```

In `includes/HierarchyGuard/Change_Listener.php`, replace:

```php
use WP_Post;

/**
```

with:

```php
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
```

In `includes/HierarchyGuard/Export/Csv.php`, replace:

```php
namespace HierarchyGuard\Export;

/**
```

with:

```php
namespace HierarchyGuard\Export;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
```

In `includes/HierarchyGuard/Ledger.php`, replace:

```php
use WP_Post;

/**
```

with:

```php
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
```

In `includes/HierarchyGuard/Overview.php`, replace:

```php
use WP_Post;

/**
```

with:

```php
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
```

In `includes/HierarchyGuard/Paths.php`, replace:

```php
use WP_Rewrite;

/**
```

with:

```php
use WP_Rewrite;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
```

In `includes/HierarchyGuard/Plugin.php`, replace:

```php
namespace HierarchyGuard;

/**
```

with:

```php
namespace HierarchyGuard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
```

In `includes/HierarchyGuard/Post_Types.php`, replace:

```php
namespace HierarchyGuard;

/**
```

with:

```php
namespace HierarchyGuard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
```

In `includes/HierarchyGuard/REST/Changes_Controller.php`, replace:

```php
use WP_REST_Server;

/**
```

with:

```php
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
```

In `includes/HierarchyGuard/REST/Csv_Download.php`, replace:

```php
use WP_REST_Response;

/**
```

with:

```php
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
```

In `includes/HierarchyGuard/REST/Hierarchy_Controller.php`, replace:

```php
use WP_REST_Server;

/**
```

with:

```php
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
```

In `includes/HierarchyGuard/Redirects/Batch_Resolver.php`, replace:

```php
use WP_Post;

/**
```

with:

```php
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
```

In `includes/HierarchyGuard/Redirects/Batch_Resolver.php`, replace:

```php
		$types        = [] !== $paths ? ( isset( $asked['post'] ) ? "%s, 'attachment', 'post'" : "%s, 'attachment'" ) : '%s';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $placeholders holds only %s, one per segment of the paths and slug a rule asks for; $types is a fixed placeholder list; core's get_page_by_path() query for every path at once, on the post_name index.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_name, post_parent, post_type FROM {$wpdb->posts} WHERE post_name IN ( {$placeholders} ) AND post_type IN ( {$types} )", ...array_merge( $names, [ $post_type ] ) ), OBJECT_K );
```

with:

```php
		$types        = [] !== $paths ? ( isset( $asked['post'] ) ? [ $post_type, 'attachment', 'post' ] : [ $post_type, 'attachment' ] ) : [ $post_type ];
		$type_list    = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $placeholders and $type_list hold only %s, one per segment of the paths and slug a rule asks for and one per post type; core's get_page_by_path() query for every path at once, on the post_name index.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_name, post_parent, post_type FROM {$wpdb->posts} WHERE post_name IN ( {$placeholders} ) AND post_type IN ( {$type_list} )", ...array_merge( $names, $types ) ), OBJECT_K );
```

In `includes/HierarchyGuard/Redirects/Ledger_View.php`, replace:

```php
use WP_Post;

/**
```

with:

```php
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
```

In `includes/HierarchyGuard/Redirects/Live_View.php`, replace:

```php
use WP_Post;

/**
```

with:

```php
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
```

In `includes/HierarchyGuard/Redirects/Pairs.php`, replace:

```php
use WP_Post;

/**
```

with:

```php
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
```

In `includes/HierarchyGuard/Redirects/Pairs_Blocks.php`, replace:

```php
use WP_Post;

/**
```

with:

```php
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
```

In `includes/HierarchyGuard/Redirects/Pairs_Blocks.php`, replace:

```php
		$in = implode( ',', array_map( 'intval', $ids ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in holds integers only, bounded by the cap; a primary-key range.
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_parent, post_name, post_status FROM {$wpdb->posts} WHERE ID IN ( {$in} ) AND post_type = %s AND post_status NOT IN ( 'trash', 'auto-draft', 'inherit' ) ORDER BY menu_order ASC, post_title ASC, ID ASC", $type ), ARRAY_A );
```

with:

```php
		$ids = array_map( 'intval', $ids );
		if ( [] === $ids ) {
			return [];
		}
		$in = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $in holds only %d, one per post, bounded by the cap; a primary-key range.
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_parent, post_name, post_status FROM {$wpdb->posts} WHERE ID IN ( {$in} ) AND post_type = %s AND post_status NOT IN ( 'trash', 'auto-draft', 'inherit' ) ORDER BY menu_order ASC, post_title ASC, ID ASC", ...array_merge( $ids, [ $type ] ) ), ARRAY_A );
```

In `includes/HierarchyGuard/Redirects/Prefetched_View.php`, replace:

```php
use WP_Post;

/**
```

with:

```php
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
```

In `includes/HierarchyGuard/Redirects/Providers.php`, replace:

```php
use HierarchyGuard\Export\Csv;

/**
```

with:

```php
use HierarchyGuard\Export\Csv;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
```

In `includes/HierarchyGuard/Redirects/Records.php`, replace:

```php
use WP_Post;

/**
```

with:

```php
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
```

In `includes/HierarchyGuard/Redirects/Records.php`, replace:

```php
			$ids  = implode( ',', array_map( 'intval', $holders ) );
			$in   = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $ids holds integers only and $in only %s, one per row of the page.
			$overrides = (array) $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ( {$ids} ) AND meta_key IN ( {$in} )", $keys ), ARRAY_A );
```

with:

```php
			$ids  = implode( ',', array_fill( 0, count( $holders ), '%d' ) );
			$in   = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $ids holds only %d and $in only %s, one per row of the page.
			$overrides = (array) $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ( {$ids} ) AND meta_key IN ( {$in} )", ...array_merge( array_map( 'intval', $holders ), $keys ) ), ARRAY_A );
```

In `includes/HierarchyGuard/Redirects/Resolver.php`, replace:

```php
use WP_Post;

/**
```

with:

```php
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
```

In `includes/HierarchyGuard/Redirects/Rules.php`, replace:

```php
use WP_Rewrite;

/**
```

with:

```php
use WP_Rewrite;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
```

In `includes/HierarchyGuard/Redirects/Server.php`, replace:

```php
use WP_Rewrite;

/**
```

with:

```php
use WP_Rewrite;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
```

In `includes/HierarchyGuard/Redirects/Server.php`, replace:

```php
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Kept verbatim (sanitize_text_field() strips percent-encoding). Only its path is compared, and its query reaches the redirect through wp_validate_redirect(), which runs wp_sanitize_redirect(), for a page target, or through external(), esc_url_raw() and http(s) only, for a URL target.
		return isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
```

with:

```php
		// esc_url_raw() keeps the percent-encoding, which sanitize_text_field() would strip.
		return isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( (string) wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
```

In `includes/HierarchyGuard/Settings.php`, replace:

```php
namespace HierarchyGuard;

/**
```

with:

```php
namespace HierarchyGuard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
```

In `includes/HierarchyGuard/Subtree.php`, replace:

```php
use WP_Post;

/**
```

with:

```php
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
```

In `includes/HierarchyGuard/Subtree.php`, replace:

```php
			$in = implode( ',', array_map( 'intval', $level ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in holds integers only; LIMIT bounds each level by the remaining cap.
			$level  = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_parent IN ( {$in} ) AND post_type = %s AND post_status NOT IN ( 'trash', 'auto-draft', 'inherit' ) LIMIT %d", $post_type, $cap + 1 - $count ) ) );
```

with:

```php
			$in = implode( ',', array_fill( 0, count( $level ), '%d' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in holds only %d, one per post of the level, and the values come as one array; LIMIT bounds each level by the remaining cap.
			$level  = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_parent IN ( {$in} ) AND post_type = %s AND post_status NOT IN ( 'trash', 'auto-draft', 'inherit' ) LIMIT %d", array_merge( array_map( 'intval', $level ), [ $post_type, $cap + 1 - $count ] ) ) ) );
```

In `includes/HierarchyGuard/Subtree.php`, replace:

```php
		$in = implode( ',', $ids );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in holds integers only, at most one batch of them.
		$parents = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_parent FROM {$wpdb->posts} WHERE post_parent IN ( {$in} ) AND post_type = %s AND post_status NOT IN ( 'trash', 'auto-draft', 'inherit' )", $post_type ) );
```

with:

```php
		$in = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $in holds only %d, one per post, at most one batch of them.
		$parents = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_parent FROM {$wpdb->posts} WHERE post_parent IN ( {$in} ) AND post_type = %s AND post_status NOT IN ( 'trash', 'auto-draft', 'inherit' )", ...array_merge( $ids, [ $post_type ] ) ) );
```

In `includes/HierarchyGuard/Subtree.php`, replace:

```php
		$in = implode( ',', $parent_ids );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in holds integers only; the index's own order lets LIMIT stop the read.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_parent IN ( {$in} ) AND post_type = %s AND post_status NOT IN ( 'trash', 'auto-draft', 'inherit' ) ORDER BY post_parent ASC, ID ASC LIMIT %d", $post_type, $limit ) );
```

with:

```php
		$in = implode( ',', array_fill( 0, count( $parent_ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in holds only %d, one per parent, and the values come as one array; the index's own order lets LIMIT stop the read.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_parent IN ( {$in} ) AND post_type = %s AND post_status NOT IN ( 'trash', 'auto-draft', 'inherit' ) ORDER BY post_parent ASC, ID ASC LIMIT %d", array_merge( $parent_ids, [ $post_type, $limit ] ) ) );
```

In `includes/HierarchyGuard/Trash/Change_Log.php`, replace:

```php
namespace HierarchyGuard\Trash;

/**
```

with:

```php
namespace HierarchyGuard\Trash;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
```

In `includes/HierarchyGuard/Trash/Guard.php`, replace:

```php
namespace HierarchyGuard\Trash;

/**
```

with:

```php
namespace HierarchyGuard\Trash;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
```

In `includes/HierarchyGuard/Trash/Markers.php`, replace:

```php
namespace HierarchyGuard\Trash;

/**
```

with:

```php
namespace HierarchyGuard\Trash;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
```

In `includes/HierarchyGuard/Trash/Markers.php`, replace:

```php
				'post_id'    => $post_id,
```

with:

```php
				'post_id'    => $post_id,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- An insert, not a query on the key.
```

In `includes/HierarchyGuard/Trash/Markers.php`, replace:

```php
		$meta_ids = implode( ',', array_map( static fn( array $row ): int => (int) $row['meta_id'], $rows ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $meta_ids holds integers only; the meta caches are cleared next.
		$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_id IN ( {$meta_ids} )" );
```

with:

```php
		$meta_ids     = array_map( static fn( array $row ): int => (int) $row['meta_id'], $rows );
		$placeholders = implode( ',', array_fill( 0, count( $meta_ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $placeholders holds only %d, one per row handled; the meta caches are cleared next.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_id IN ( {$placeholders} )", $meta_ids ) );
```

In `includes/HierarchyGuard/Trash/Markers.php`, replace:

```php
				'post_id'  => $post_id,
```

with:

```php
				'post_id'  => $post_id,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One post's rows, found by post_id first.
```

In `includes/HierarchyGuard/Trash/Mover.php`, replace:

```php
use WP_Post;

/**
```

with:

```php
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
```

In `includes/HierarchyGuard/Trash/Operations.php`, replace:

```php
use WP_Post;

/**
```

with:

```php
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
```

In `includes/HierarchyGuard/Trash/Request.php`, replace:

```php
namespace HierarchyGuard\Trash;

/**
```

with:

```php
namespace HierarchyGuard\Trash;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
```

In `includes/HierarchyGuard/Trash/Trash_Handler.php`, replace:

```php
use WP_Post;

/**
```

with:

```php
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `npm run test:php -- --filter 'PluginTest|ServerTest'`

Expected: `OK`.

Run: `npm run test:php`, `npm run test:php:multisite` and `npm run test:js`

Expected: `Tests: 986, Assertions: 4409, Skipped: 5.` (single site), `OK (986 tests, 4446 assertions)` (multisite), and Vitest `Tests 176 passed (176)`.

- [ ] **Step 5: Lint and build**

Run: `composer lint && npm run lint:js && npm run lint:style && npm run build`

Expected: every command exits 0.

- [ ] **Step 6: Commit**

```bash
git add includes/HierarchyGuard/Admin/Assets.php includes/HierarchyGuard/Admin/List_Table.php includes/HierarchyGuard/Admin/Meta_Box.php includes/HierarchyGuard/Admin/Notices.php includes/HierarchyGuard/Admin/Redirect_Edit.php includes/HierarchyGuard/Admin/Redirects_Table.php includes/HierarchyGuard/Admin/Settings_Page.php includes/HierarchyGuard/Admin/Trash_Choice.php includes/HierarchyGuard/Admin/Tree_View.php includes/HierarchyGuard/Batch/CLI_Command.php includes/HierarchyGuard/Batch/Processor.php includes/HierarchyGuard/Batch/Queue.php includes/HierarchyGuard/Change_Listener.php includes/HierarchyGuard/Export/Csv.php includes/HierarchyGuard/Ledger.php includes/HierarchyGuard/Overview.php includes/HierarchyGuard/Paths.php includes/HierarchyGuard/Plugin.php includes/HierarchyGuard/Post_Types.php includes/HierarchyGuard/REST/Changes_Controller.php includes/HierarchyGuard/REST/Csv_Download.php includes/HierarchyGuard/REST/Hierarchy_Controller.php includes/HierarchyGuard/Redirects/Batch_Resolver.php includes/HierarchyGuard/Redirects/Ledger_View.php includes/HierarchyGuard/Redirects/Live_View.php includes/HierarchyGuard/Redirects/Pairs.php includes/HierarchyGuard/Redirects/Pairs_Blocks.php includes/HierarchyGuard/Redirects/Prefetched_View.php includes/HierarchyGuard/Redirects/Providers.php includes/HierarchyGuard/Redirects/Records.php includes/HierarchyGuard/Redirects/Resolver.php includes/HierarchyGuard/Redirects/Rules.php includes/HierarchyGuard/Redirects/Server.php includes/HierarchyGuard/Settings.php includes/HierarchyGuard/Subtree.php includes/HierarchyGuard/Trash/Change_Log.php includes/HierarchyGuard/Trash/Guard.php includes/HierarchyGuard/Trash/Markers.php includes/HierarchyGuard/Trash/Mover.php includes/HierarchyGuard/Trash/Operations.php includes/HierarchyGuard/Trash/Request.php includes/HierarchyGuard/Trash/Trash_Handler.php tests/phpunit/PluginTest.php tests/phpunit/ServerTest.php
git commit -m "fix: stop direct requests to class files, prepare every IN list, and sanitize the request URI at read"
```

---

### Task 7: One scheduled run per site works through every queued job; the watchdog exists only while jobs wait

**Findings:** 9.1, 3.4, 5.4, 9.5 (watchdog), and the plan audits' C-1/S-1, S-2, S-3, S-7 and U-14 (order)

- A bulk trash of a thousand parents scheduled a cron event per root, rewriting the autoloaded `cron` option each time. Now there is one `hierarchy_guard_process_batch` event with no arguments; `Processor::handle()` steps the queued roots oldest first, each under its own lock, within the 20-second budget, and reads more roots once it has passed the 100 it read, so a run is not over while roots wait and time is left. Per-root jobs and locks stay, so a trash and a restore of one root never overlap. (The audit proposed one job per bulk request; one event per site is simpler and also covers trashes from different requests.)
- The next run is scheduled before the first step, so a run that dies still leaves one, and cleared at the end (`Queue::unschedule()`) unless a root it could step is left. A root whose step throws is caught, passed over and left to the watchdog, so the roots behind it go on; a root another process holds is passed over too, since that process schedules its own next run. Time and memory are checked before each root.
- The watchdog becomes a single event an hour ahead, scheduled with every run and on activation when jobs wait, and rescheduled by itself only while roots exist. Nothing schedules it from `admin_init`, so an idle site has no plugin cron event. If the cron option loses both events while a job waits, the user's progress notice, which reads that job anyway, schedules the run again (`Notices::keep_going()`).
- With Action Scheduler, a request asks it once whether the run is queued (`Queue::$queued_in_as`), so a bulk trash does not query it once per parent; WP-Cron's check reads the cron array already in memory.

**Files:**
- Modify: `includes/HierarchyGuard/Admin/Notices.php`
- Modify: `includes/HierarchyGuard/Batch/Processor.php`
- Modify: `includes/HierarchyGuard/Batch/Queue.php`
- Modify: `includes/HierarchyGuard/Trash/Trash_Handler.php`
- Modify: `README.md`
- Test (create): `tests/phpunit/Action_Scheduler_Stub.php`
- Test (create): `tests/phpunit/stubs/action-scheduler.php`
- Test (modify): `tests/phpunit/NoticesTest.php`
- Test (modify): `tests/phpunit/ProcessorTest.php`
- Test (modify): `tests/phpunit/QueueTest.php`
- Test (modify): `tests/phpunit/TrashHandlerTest.php`

**Interfaces:**
- Produces: `Queue::schedule(): bool`, `Queue::is_scheduled(): bool` (no root argument) and `Queue::unschedule(): void`; `Processor::handle(): void` (no argument); static `Processor::schedule_watchdog(): void`; static `Processor::activate(): void` schedules the watchdog only when roots wait, and `Processor::deactivate()` clears both events.
- Consumes: `Trash_Handler` calls `Queue::schedule()` after queueing a root; static `Processor::time_limit()` from Task 3.
- Test helpers: `tests/phpunit/Action_Scheduler_Stub.php` and `stubs/action-scheduler.php`, Action Scheduler's four functions answering only while the stub is on.

- [ ] **Step 1: Write the failing tests**

Create `tests/phpunit/Action_Scheduler_Stub.php`:

```php
<?php
/**
 * What the Action Scheduler stubs were asked, and whether they answer.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

/**
 * Off, the stubs answer as an Action Scheduler that hasn't initialized yet,
 * so the code under test falls back to WP-Cron.
 */
final class Action_Scheduler_Stub {

	/**
	 * Whether the stubs answer.
	 *
	 * @var bool
	 */
	public static bool $on = false;

	/**
	 * Times as_get_scheduled_actions() was asked.
	 *
	 * @var int
	 */
	public static int $asked = 0;

	/**
	 * Hooks of the pending actions.
	 *
	 * @var string[]
	 */
	public static array $queued = [];

	/**
	 * Turns the stubs off and forgets what they were asked.
	 */
	public static function reset(): void {
		self::$on     = false;
		self::$asked  = 0;
		self::$queued = [];
	}
}
```

Create `tests/phpunit/stubs/action-scheduler.php`:

```php
<?php
/**
 * Action Scheduler's functions, as the queue calls them.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

use HierarchyGuard\Tests\Action_Scheduler_Stub;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Action Scheduler's own names, stubbed for tests.

if ( ! function_exists( 'as_enqueue_async_action' ) ) {
	/**
	 * Queues an action, or returns 0 as before initialization.
	 *
	 * @param string $hook Hook.
	 * @return int Action ID.
	 */
	function as_enqueue_async_action( $hook ): int {
		if ( ! Action_Scheduler_Stub::$on ) {
			return 0;
		}
		Action_Scheduler_Stub::$queued[] = (string) $hook;
		return count( Action_Scheduler_Stub::$queued );
	}

	/**
	 * Pending action IDs.
	 *
	 * @return int[]
	 */
	function as_get_scheduled_actions(): array {
		if ( ! Action_Scheduler_Stub::$on ) {
			return [];
		}
		++Action_Scheduler_Stub::$asked;
		return array_keys( Action_Scheduler_Stub::$queued );
	}

	/**
	 * Whether an action is pending.
	 *
	 * @return bool
	 */
	function as_has_scheduled_action(): bool {
		return Action_Scheduler_Stub::$on && [] !== Action_Scheduler_Stub::$queued;
	}

	/**
	 * Drops pending actions.
	 */
	function as_unschedule_all_actions(): void {
		if ( Action_Scheduler_Stub::$on ) {
			Action_Scheduler_Stub::$queued = [];
		}
	}
}
```

In `tests/phpunit/NoticesTest.php`, replace:

```php

	public function test_progress_is_shown_while_the_batch_runs_and_not_on_the_landing_load(): void {
```

with:

```php

	public function test_a_waiting_job_shown_with_neither_its_run_nor_the_watchdog_scheduled_schedules_them(): void {
		$queue = new Queue();
		$id    = $this->change( 'trash', [ $this->about( [ 'job' => true ] ) ] )['id'];
		$queue->save(
			[
				'root'   => $this->ids['about'],
				'change' => $id,
				'total'  => 40,
				'counts' => array_merge( Change_Log::empty_counts(), [ 'moved' => 25 ] ),
			]
		);
		wp_unschedule_hook( Queue::HOOK );
		wp_unschedule_hook( Queue::WATCHDOG );

		$this->notice->progress_sentences( 'page' );

		$this->assertTrue( $queue->is_scheduled() );
		$this->assertNotFalse( wp_next_scheduled( Queue::WATCHDOG ) );

		wp_unschedule_hook( Queue::HOOK );
		$this->notice->progress_sentences( 'page' );
		$this->assertFalse( $queue->is_scheduled(), 'With the watchdog still scheduled, the job is not lost: nothing more is scheduled.' );

		$queue->delete( $this->ids['about'] );
		wp_unschedule_hook( Queue::WATCHDOG );
	}

	public function test_progress_is_shown_while_the_batch_runs_and_not_on_the_landing_load(): void {
```

In `tests/phpunit/ProcessorTest.php`, replace:

```php
		$this->assertSame( 10, has_action( 'admin_init', [ Processor::class, 'schedule_watchdog' ] ) );
```

with:

```php
		$this->assertFalse( has_action( 'admin_init', [ Processor::class, 'schedule_watchdog' ] ), 'Nothing is scheduled on a site with no job.' );
	}

	public function test_one_run_works_through_every_queued_root_oldest_first(): void {
		$other = $this->page( 'other' );
		foreach ( [ 'x', 'y' ] as $slug ) {
			$this->page( $slug, $other );
		}
		$this->trash_raw( $other );
		$this->queue->save( Operations::new_job( Operations::TYPE_REPARENT, $other, 'page', 0, [ 'to' => 0 ] ) );
		$order = [];
		add_action(
			'hierarchy_guard_children_reparented',
			static function ( array $ids, int $from ) use ( &$order ): void {
				$order[] = $from;
			},
			10,
			2
		);

		$this->processor->handle();

		$this->assertSame( [ $this->ids['about'], $this->ids['about'], $this->ids['about'], $other ], $order, 'About, queued first, moves its five subpages two at a time, then Other.' );
		$this->assertSame( [], $this->queue->roots() );
		$this->assertSame( 0, Subtree::count_live_children( $this->ids['about'], 'page', 10 ) );
		$this->assertSame( 0, Subtree::count_live_children( $other, 'page', 10 ) );
		$this->assertFalse( $this->queue->is_scheduled(), 'Nothing is left for another run.' );
	}

	public function test_one_run_reads_more_roots_once_it_has_passed_the_first_hundred(): void {
		$parents = [];
		for ( $i = 1; $i <= 120; $i++ ) {
			$parent = $this->page( "many-{$i}" );
			$this->page( "many-{$i}-child", $parent );
			$this->trash_raw( $parent );
			$this->queue->save( Operations::new_job( Operations::TYPE_REPARENT, $parent, 'page', 0, [ 'to' => 0 ] ) );
			$parents[] = $parent;
		}

		$this->processor->handle();

		$this->assertSame( [], $this->queue->roots(), 'All 121 roots, About and the 120, in one run.' );
		$this->assertSame( 0, Subtree::count_live_children( end( $parents ), 'page', 10 ) );
		$this->assertFalse( $this->queue->is_scheduled() );
	}

	public function test_a_root_whose_step_fails_is_passed_over_and_left_to_the_watchdog(): void {
		$other = $this->page( 'other' );
		$this->page( 'x', $other );
		$this->trash_raw( $other );
		$this->queue->save( Operations::new_job( Operations::TYPE_REPARENT, $other, 'page', 0, [ 'to' => 0 ] ) );
		$about     = $this->ids['about'];
		$scheduled = null;
		$queue     = $this->queue;
		add_action(
			'hierarchy_guard_children_reparented',
			static function ( array $ids, int $from ) use ( $about, $queue, &$scheduled ): void {
				if ( $about === $from ) {
					$scheduled = $queue->is_scheduled();
					throw new \RuntimeException( 'Another plugin failed.' );
				}
			},
			10,
			2
		);

		$this->processor->handle();

		$this->assertTrue( $scheduled, 'A next run is scheduled while a step runs, so a run that dies leaves one.' );
		$this->assertSame( [ $about ], $this->queue->roots(), 'Only the failed root is left.' );
		$this->assertSame( 0, Subtree::count_live_children( $other, 'page', 10 ), 'The root behind it was stepped.' );
		$this->assertFalse( $this->queue->is_scheduled(), 'The failed root waits for the watchdog, not the next page load.' );
		$this->assertNotFalse( wp_next_scheduled( Queue::WATCHDOG ) );
```

In `tests/phpunit/ProcessorTest.php`, replace:

```php
		$this->assertSame( 0, Subtree::count_live_children( $this->ids['about'], 'page', 10 ) );
		$this->assertFalse( $this->queue->is_scheduled( $this->ids['about'] ) );
```

with:

```php
		$this->assertSame( 0, Subtree::count_live_children( $this->ids['about'], 'page', 10 ) );
		$this->assertFalse( $this->queue->is_scheduled() );
```

In `tests/phpunit/ProcessorTest.php`, replace:

```php
		$this->assertSame( 2, $this->queue->load( $this->ids['about'] )['counts']['moved'] );
		$this->assertTrue( $this->queue->is_scheduled( $this->ids['about'] ) );
```

with:

```php
		$this->assertSame( 2, $this->queue->load( $this->ids['about'] )['counts']['moved'] );
		$this->assertTrue( $this->queue->is_scheduled() );
```

In `tests/phpunit/ProcessorTest.php`, replace:

```php
		$this->assertFalse( $this->queue->is_scheduled( $this->ids['about'] ), 'Precondition: its run died.' );

		$this->processor->watchdog();

		$this->assertTrue( $this->queue->is_scheduled( $this->ids['about'] ) );
```

with:

```php
		$this->assertFalse( $this->queue->is_scheduled(), 'Precondition: its run died.' );

		$this->processor->watchdog();

		$this->assertTrue( $this->queue->is_scheduled() );
```

In `tests/phpunit/ProcessorTest.php`, replace:

```php
		$this->processor->watchdog();
		$this->assertFalse( $this->queue->is_scheduled( $this->ids['about'] ) );
```

with:

```php
		$this->processor->watchdog();
		$this->assertFalse( $this->queue->is_scheduled() );
```

In `tests/phpunit/ProcessorTest.php`, replace:

```php
	public function test_the_watchdog_is_scheduled_hourly_once_and_cleared_on_deactivation(): void {
```

with:

```php
	public function test_the_watchdog_is_one_single_event_an_hour_ahead_and_cleared_on_deactivation(): void {
```

In `tests/phpunit/ProcessorTest.php`, replace:

```php
		$this->assertSame( 'hourly', wp_get_schedule( Queue::WATCHDOG ) );

		$this->queue->schedule( $this->ids['about'] );
		Processor::deactivate();

		$this->assertFalse( wp_next_scheduled( Queue::WATCHDOG ) );
		$this->assertFalse( $this->queue->is_scheduled( $this->ids['about'] ) );
		$this->assertNotNull( $this->queue->load( $this->ids['about'] ), 'Jobs survive deactivation.' );
	}

```

with:

```php
		$this->assertGreaterThanOrEqual( time() + HOUR_IN_SECONDS - 5, $first );
		$this->assertFalse( wp_get_schedule( Queue::WATCHDOG ), 'A single event: with the plugin gone it runs once more and is gone.' );

		$this->queue->schedule();
		Processor::deactivate();

		$this->assertFalse( wp_next_scheduled( Queue::WATCHDOG ) );
		$this->assertFalse( $this->queue->is_scheduled() );
		$this->assertNotNull( $this->queue->load( $this->ids['about'] ), 'Jobs survive deactivation.' );
	}

	public function test_the_watchdog_checks_again_only_while_a_job_waits(): void {
		$this->processor->watchdog();
		$this->assertNotFalse( wp_next_scheduled( Queue::WATCHDOG ), 'A job waits.' );

		wp_unschedule_hook( Queue::WATCHDOG );
		$this->queue->delete( $this->ids['about'] );
		$this->processor->watchdog();

		$this->assertFalse( wp_next_scheduled( Queue::WATCHDOG ) );
	}

	public function test_activation_schedules_the_watchdog_only_when_a_job_waits(): void {
		Processor::activate();
		$this->assertNotFalse( wp_next_scheduled( Queue::WATCHDOG ), 'A job from before waits.' );

		wp_unschedule_hook( Queue::WATCHDOG );
		$this->queue->delete( $this->ids['about'] );
		Processor::activate();

		$this->assertFalse( wp_next_scheduled( Queue::WATCHDOG ) );
	}

```

In `tests/phpunit/ProcessorTest.php`, replace:

```php
		$this->assertFalse( $this->queue->is_scheduled( $root ) );
```

with:

```php
		$this->assertFalse( $this->queue->is_scheduled() );
```

In `tests/phpunit/QueueTest.php`, replace:

```php
		wp_unschedule_hook( Queue::HOOK );
		parent::tear_down();
	}

```

with:

```php
		Action_Scheduler_Stub::reset();
		wp_unschedule_hook( Queue::HOOK );
		wp_unschedule_hook( Queue::WATCHDOG );
		parent::tear_down();
	}

	public function test_a_bulk_trash_asks_action_scheduler_once_until_the_run_is_cleared(): void {
		require_once __DIR__ . '/stubs/action-scheduler.php';
		Action_Scheduler_Stub::$on = true;
		$queue                     = new Queue();

		$this->assertTrue( $queue->schedule() );
		$this->assertTrue( $queue->schedule() );
		$this->assertTrue( $queue->schedule() );

		$this->assertSame( 1, Action_Scheduler_Stub::$asked );
		$this->assertSame( [ Queue::HOOK ], Action_Scheduler_Stub::$queued );

		$queue->unschedule();
		$this->assertSame( [], Action_Scheduler_Stub::$queued );
		$queue->schedule();
		$this->assertSame( 2, Action_Scheduler_Stub::$asked );
		$this->assertSame( [ Queue::HOOK ], Action_Scheduler_Stub::$queued );
	}

```

In `tests/phpunit/QueueTest.php`, replace:

```php
		$this->assertFalse( function_exists( 'as_enqueue_async_action' ), 'Precondition: Action Scheduler is not loaded in the test site.' );
		$this->assertFalse( $this->queue->is_scheduled( 10 ) );

		$this->assertTrue( $this->queue->schedule( 10 ) );
		$this->assertTrue( $this->queue->schedule( 10 ), 'An existing event counts.' );

		$this->assertTrue( $this->queue->is_scheduled( 10 ) );
		$this->assertCount( 1, array_filter( _get_cron_array(), static fn( array $hooks ): bool => isset( $hooks[ Queue::HOOK ] ) ) );
	}

```

with:

```php
		$this->assertFalse( function_exists( 'as_enqueue_async_action' ) && Action_Scheduler_Stub::$on, 'Precondition: no Action Scheduler answers in the test site.' );
		$this->assertFalse( $this->queue->is_scheduled() );

		$this->assertTrue( $this->queue->schedule() );
		$this->assertTrue( $this->queue->schedule(), 'An existing event counts.' );

		$this->assertTrue( $this->queue->is_scheduled() );
		$this->assertCount( 1, array_filter( _get_cron_array(), static fn( array $hooks ): bool => isset( $hooks[ Queue::HOOK ] ) ) );
	}

	public function test_a_scheduled_run_brings_the_watchdog_with_it(): void {
		$this->assertFalse( wp_next_scheduled( Queue::WATCHDOG ) );

		$this->queue->schedule();

		$this->assertNotFalse( wp_next_scheduled( Queue::WATCHDOG ) );
	}

```

In `tests/phpunit/TrashHandlerTest.php`, replace:

```php
		wp_unschedule_hook( Queue::HOOK );
```

with:

```php
		wp_unschedule_hook( Queue::HOOK );
		wp_unschedule_hook( Queue::WATCHDOG );
```

In `tests/phpunit/TrashHandlerTest.php`, replace:

```php
		$this->assertSame( 3, Subtree::count_live_children( $this->ids['about'], 'page', 10 ), 'Only the first batch ran inline.' );
		$this->assertTrue( $this->queue->is_scheduled( $this->ids['about'] ) );
```

with:

```php
		$this->assertSame( 3, Subtree::count_live_children( $this->ids['about'], 'page', 10 ), 'Only the first batch ran inline.' );
		$this->assertTrue( $this->queue->is_scheduled() );
```

In `tests/phpunit/TrashHandlerTest.php`, replace:

```php
		$this->assertFalse( $this->queue->is_scheduled( $this->ids['about'] ), $message );
```

with:

```php
		$this->assertFalse( $this->queue->is_scheduled(), $message );
```

In `tests/phpunit/TrashHandlerTest.php`, replace:

```php
		$this->assertSame( Operations::DEFAULT_CORE_THRESHOLD, $this->queue->load( $this->ids['about'] )['counts']['trashed'] );
		$this->assertTrue( $this->queue->is_scheduled( $this->ids['about'] ) );
```

with:

```php
		$this->assertSame( Operations::DEFAULT_CORE_THRESHOLD, $this->queue->load( $this->ids['about'] )['counts']['trashed'] );
		$this->assertTrue( $this->queue->is_scheduled() );
```

In `tests/phpunit/TrashHandlerTest.php`, replace:

```php
		$this->assertTrue( $this->queue->is_scheduled( $this->ids['about'] ) );
		$this->assertTrue( $this->change()['roots'][ $this->ids['about'] ]['job'] );
	}

```

with:

```php
		$this->assertTrue( $this->queue->is_scheduled() );
		$this->assertTrue( $this->change()['roots'][ $this->ids['about'] ]['job'] );
	}

	public function test_a_bulk_trash_past_the_budget_queues_every_parent_with_one_event(): void {
		add_filter( 'hierarchy_guard_batch_threshold', static fn(): int => 1 );
		$parents = [];
		for ( $i = 1; $i <= 5; $i++ ) {
			$parents[] = $this->page( "parent-{$i}" );
			$this->page( "child-{$i}-a", end( $parents ) );
			$this->page( "child-{$i}-b", end( $parents ) );
		}
		$writes = 0;
		$count  = static function () use ( &$writes ): void {
			++$writes;
		};
		add_action( 'update_option_cron', $count );

		foreach ( $parents as $parent ) {
			wp_trash_post( $parent );
		}
		remove_action( 'update_option_cron', $count );

		$this->assertSame( $parents, $this->queue->roots() );
		$this->assertCount( 1, array_filter( _get_cron_array(), static fn( array $hooks ): bool => isset( $hooks[ Queue::HOOK ] ) ) );
		$this->assertLessThanOrEqual( 2, $writes, 'The run and its watchdog, once each.' );

		$this->processor()->handle();

		$this->assertSame( [], $this->queue->roots() );
		foreach ( $parents as $parent ) {
			$this->assertSame( 0, Subtree::count_live_children( $parent, 'page', 10 ) );
		}
	}

```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npm run test:php -- --filter 'NoticesTest|ProcessorTest|QueueTest|TrashHandlerTest'`

Expected: FAIL as below; every other test in these classes passes.

- `NoticesTest::test_a_waiting_job_shown_with_neither_its_run_nor_the_watchdog_scheduled_schedules_them`: ArgumentCountError: Too few arguments to function HierarchyGuard\Batch\Queue::is_scheduled(), 0 passed in /var/www/html/wp-content/plugins/hierarchy-guard/tests/phpunit/N …
- 3 tests in `ProcessorTest`: ArgumentCountError: Too few arguments to function HierarchyGuard\Batch\Processor::handle(), 0 passed in /var/www/html/wp-content/plugins/hierarchy-guard/tests/phpunit/Pro …
- 5 tests in `ProcessorTest`: ArgumentCountError: Too few arguments to function HierarchyGuard\Batch\Queue::is_scheduled(), 0 passed in /var/www/html/wp-content/plugins/hierarchy-guard/tests/phpunit/P …
- `QueueTest::test_a_bulk_trash_asks_action_scheduler_once_until_the_run_is_cleared`: ArgumentCountError: Too few arguments to function HierarchyGuard\Batch\Queue::schedule(), 0 passed in /var/www/html/wp-content/plugins/hierarchy-guard/tests/phpunit/Queue …
- `QueueTest::test_a_scheduled_run_brings_the_watchdog_with_it`: ArgumentCountError: Too few arguments to function HierarchyGuard\Batch\Queue::schedule(), 0 passed in /var/www/html/wp-content/plugins/hierarchy-guard/tests/phpunit/Queue …
- `QueueTest::test_schedule_uses_wp_cron_without_action_scheduler`: ArgumentCountError: Too few arguments to function HierarchyGuard\Batch\Queue::is_scheduled(), 0 passed in /var/www/html/wp-content/plugins/hierarchy-guard/tests/phpunit/Q …
- 5 tests in `TrashHandlerTest`: ArgumentCountError: Too few arguments to function HierarchyGuard\Batch\Queue::is_scheduled(), 0 passed in /var/www/html/wp-content/plugins/hierarchy-guard/tests/phpunit/T …
- `ProcessorTest::test_the_processor_is_registered_by_the_plugin`: Nothing is scheduled on a site with no job.
- `ProcessorTest::test_the_watchdog_is_one_single_event_an_hour_ahead_and_cleared_on_deactivation`: A single event: with the plugin gone it runs once more and is gone.
- `ProcessorTest::test_the_watchdog_checks_again_only_while_a_job_waits`: A job waits.
- `ProcessorTest::test_activation_schedules_the_watchdog_only_when_a_job_waits`: Failed asserting that 1795410099 is false.
- `TrashHandlerTest::test_a_bulk_trash_past_the_budget_queues_every_parent_with_one_event`: The run and its watchdog, once each.

Totals: `Tests: 120, Assertions: 1418, Errors: 17, Failures: 5, Skipped: 1.`

Mutant, to run once the tests pass, which shows `test_one_run_works_through_every_queued_root_oldest_first` pins its behaviour: in `includes/HierarchyGuard/Batch/Processor.php`, replace

```php
$roots = array_diff( $this->queue->roots( self::ROOTS_PER_READ + count( $passed ) ), $passed );
```

with:

```php
$roots = array_reverse( array_diff( $this->queue->roots( self::ROOTS_PER_READ + count( $passed ) ), $passed ) );
```

Expected: the test fails, as it did on the prototype:

```
ProcessorTest::test_one_run_works_through_every_queued_root_oldest_first
About, queued first, moves its five subpages two at a time, then Other.
Tests: 1, Assertions: 1, Failures: 1.
```

Then undo the mutant (`git checkout -- includes/HierarchyGuard/Batch/Processor.php`). Never commit it.

Mutant, to run once the tests pass, which shows `test_one_run_reads_more_roots_once_it_has_passed_the_first_hundred` pins its behaviour: in `includes/HierarchyGuard/Batch/Processor.php`, replace

```php
} while ( [] !== $roots );
```

with:

```php
} while ( false );
```

Expected: the test fails, as it did on the prototype:

```
ProcessorTest::test_one_run_reads_more_roots_once_it_has_passed_the_first_hundred
All 121 roots, About and the 120, in one run.
Tests: 1, Assertions: 1, Failures: 1.
```

Then undo the mutant (`git checkout -- includes/HierarchyGuard/Batch/Processor.php`). Never commit it.

Mutant, to run once the tests pass, which shows `test_a_root_whose_step_fails_is_passed_over_and_left_to_the_watchdog` pins its behaviour: in `includes/HierarchyGuard/Batch/Processor.php`, replace

```php
				try {
					if ( 'busy' === $this->run( $root_id, $left )['state'] ) {
						$skip[] = $root_id;
					}
				} catch ( Throwable $e ) {
					$skip[] = $root_id;
				}
```

with:

```php
				if ( 'busy' === $this->run( $root_id, $left )['state'] ) {
					$skip[] = $root_id;
				}
```

Expected: the test fails, as it did on the prototype:

```
ProcessorTest::test_a_root_whose_step_fails_is_passed_over_and_left_to_the_watchdog
RuntimeException: Another plugin failed.
Tests: 1, Assertions: 0, Errors: 1.
```

Then undo the mutant (`git checkout -- includes/HierarchyGuard/Batch/Processor.php`). Never commit it.

- [ ] **Step 3: Implement**

In `includes/HierarchyGuard/Admin/Notices.php`, replace:

```php
			}
			$counts = (array) $job['counts'];
```

with:

```php
			}
			$this->keep_going();
			$counts = (array) $job['counts'];
```

In `includes/HierarchyGuard/Admin/Notices.php`, replace:

```php
	}

	/**
	 * Whether to nudge this user to turn on redirects: old URLs were recorded,
```

with:

```php
	}

	/**
	 * A job is waiting: schedules the run when neither it nor the watchdog
	 * is scheduled, as when the cron option lost both.
	 */
	private function keep_going(): void {
		if ( ! $this->queue->is_scheduled() && false === wp_next_scheduled( Queue::WATCHDOG ) ) {
			$this->queue->schedule();
		}
	}

	/**
	 * Whether to nudge this user to turn on redirects: old URLs were recorded,
```

In `includes/HierarchyGuard/Batch/Processor.php`, replace:

```php
use HierarchyGuard\Trash\Operations;
```

with:

```php
use HierarchyGuard\Trash\Operations;
use Throwable;
```

In `includes/HierarchyGuard/Batch/Processor.php`, replace:

```php
 * Each run holds the root's lock and steps its job until the job is done or
 * the run's time or memory runs low, then schedules the next run. An hourly
 * watchdog restarts a job whose run died, and gives up on one after a week.
 * A run writes its job's record in the user's change log only while it
 * holds the lock: after each step, and when an offer replaces the job.
```

with:

```php
 * A scheduled run works through the queued roots, oldest first. For each it
 * holds the root's lock and steps its job until the job is done or the run's
 * time or memory runs low, then schedules the next run. A watchdog, scheduled
 * only while jobs wait, restarts a job whose run died each hour, and gives up
 * on one after a week. A run writes its job's record in the user's change log
 * only while it holds the lock: after each step, and when an offer replaces
 * the job.
```

In `includes/HierarchyGuard/Batch/Processor.php`, replace:

```php
	public const GIVE_UP_AFTER = 7 * DAY_IN_SECONDS;

	/**
```

with:

```php
	public const GIVE_UP_AFTER = 7 * DAY_IN_SECONDS;

	/**
	 * Queued roots a run reads at a time.
	 */
	private const ROOTS_PER_READ = 100;

	/**
```

In `includes/HierarchyGuard/Batch/Processor.php`, replace:

```php
		add_action( 'admin_init', [ self::class, 'schedule_watchdog' ] );
	}

	/**
	 * Scheduler entry point: one time-boxed run for a root.
	 *
	 * @param mixed $root_id Root page, as the scheduler passes it.
	 */
	public function handle( $root_id ): void {
		$this->run( (int) $root_id, self::time_limit() );
```

with:

```php
	}

	/**
	 * Scheduler entry point: one time-boxed run through the queued roots,
	 * oldest first, reading more roots once it has passed those it read. The
	 * next run is scheduled before the first step, so a run that dies still
	 * leaves one, and cleared at the end unless a root this run could step is
	 * left. A root another process holds is passed over: that process
	 * schedules its own next run. So is a root whose step failed: the
	 * watchdog restarts it, and the roots behind it go on.
	 */
	public function handle(): void {
		$limit  = self::time_limit();
		$start  = microtime( true );
		$passed = [];
		$skip   = [];
		$this->queue->schedule();
		do {
			$roots = array_diff( $this->queue->roots( self::ROOTS_PER_READ + count( $passed ) ), $passed );
			foreach ( $roots as $root_id ) {
				$left = $limit - (int) floor( microtime( true ) - $start );
				if ( $left < 1 || $this->out_of_time( $start, 0 ) ) {
					return;
				}
				$passed[] = $root_id;
				try {
					if ( 'busy' === $this->run( $root_id, $left )['state'] ) {
						$skip[] = $root_id;
					}
				} catch ( Throwable $e ) {
					$skip[] = $root_id;
				}
			}
		} while ( [] !== $roots );

		$this->queue->unschedule();
		if ( [] !== array_diff( $this->queue->roots( self::ROOTS_PER_READ + count( $skip ) ), $skip ) ) {
			$this->queue->schedule();
		}
```

In `includes/HierarchyGuard/Batch/Processor.php`, replace:

```php
		if ( null !== $job || null !== $this->queue->offered( $root_id ) ) {
			$this->queue->schedule( $root_id );
```

with:

```php
		if ( null !== $job || null !== $this->queue->offered( $root_id ) ) {
			$this->queue->schedule();
```

In `includes/HierarchyGuard/Batch/Processor.php`, replace:

```php
	 * Restarts jobs whose run died and drops jobs that can no longer finish.
```

with:

```php
	 * Restarts jobs whose run died and drops jobs that can no longer finish,
	 * then checks again in an hour while any job waits.
```

In `includes/HierarchyGuard/Batch/Processor.php`, replace:

```php
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
```

with:

```php
			if ( $touched < $now - self::STALE_AFTER && ! $this->queue->is_scheduled() ) {
				$this->queue->schedule();
			}
		}
		if ( [] !== $this->queue->roots( 1 ) ) {
			self::schedule_watchdog();
		}
	}

	/**
	 * Schedules the watchdog an hour ahead, unless it is scheduled already.
	 * It is a single event, so on a site where the plugin is no longer active,
	 * as after a network deactivation, it runs once more with nothing hooked
	 * and is gone.
	 */
	public static function schedule_watchdog(): void {
		if ( false === wp_next_scheduled( Queue::WATCHDOG ) ) {
			wp_schedule_single_event( time() + HOUR_IN_SECONDS, Queue::WATCHDOG );
```

In `includes/HierarchyGuard/Batch/Processor.php`, replace:

```php
	 * Activation: start the watchdog, which also restarts jobs left from before.
	 */
	public static function activate(): void {
		self::schedule_watchdog();
	}

	/**
	 * Deactivation: stop scheduled runs. Stored jobs stay, and the watchdog
	 * restarts them after reactivation.
```

with:

```php
	 * Activation: the watchdog restarts jobs left from before, when there are any.
	 */
	public static function activate(): void {
		if ( [] !== ( new Queue() )->roots( 1 ) ) {
			self::schedule_watchdog();
		}
	}

	/**
	 * Deactivation: stop scheduled runs. Stored jobs stay; activation
	 * schedules the watchdog again while any wait.
```

In `includes/HierarchyGuard/Batch/Queue.php`, replace:

```php
 * entry per page.
```

with:

```php
 * entry per page. However many roots wait, a site has one scheduled run,
 * which works through them, so a bulk trash writes the cron option once.
```

In `includes/HierarchyGuard/Batch/Queue.php`, replace:

```php
	public const LOCK_TTL     = 300;

	/**
```

with:

```php
	public const LOCK_TTL     = 300;

	/**
	 * Whether this request has the run queued in Action Scheduler, so a bulk
	 * trash asks Action Scheduler once rather than once per parent.
	 *
	 * @var bool
	 */
	private bool $queued_in_as = false;

	/**
```

In `includes/HierarchyGuard/Batch/Queue.php`, replace:

```php
	 * Schedules a run for a root through Action Scheduler when a site has it,
	 * else WP-Cron. A run already in progress does not count: it may be the one
	 * about to finish.
	 *
	 * @param int $root_id Root page.
	 * @return bool Whether a run is scheduled.
	 */
	public function schedule( int $root_id ): bool {
		$args = [ $root_id ];

		if ( function_exists( 'as_enqueue_async_action' ) ) {
```

with:

```php
	 * Schedules the site's run through Action Scheduler when a site has it,
	 * else WP-Cron, unless one is scheduled already, and the watchdog with
	 * it. A run already in progress does not count: it may be the one about
	 * to finish.
	 *
	 * @return bool Whether a run is scheduled.
	 */
	public function schedule(): bool {
		Processor::schedule_watchdog();

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			if ( $this->queued_in_as ) {
				return true;
			}
```

In `includes/HierarchyGuard/Batch/Queue.php`, replace:

```php
						'args'     => $args,
```

with:

```php
						'args'     => [],
```

In `includes/HierarchyGuard/Batch/Queue.php`, replace:

```php
			if ( [] !== $pending ) {
				return true;
			}
			if ( as_enqueue_async_action( self::HOOK, $args, self::GROUP ) ) {
```

with:

```php
			if ( [] !== $pending || as_enqueue_async_action( self::HOOK, [], self::GROUP ) ) {
				$this->queued_in_as = true;
```

In `includes/HierarchyGuard/Batch/Queue.php`, replace:

```php
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
```

with:

```php
		if ( false !== wp_next_scheduled( self::HOOK ) ) {
			return true;
		}
		return true === wp_schedule_single_event( time(), self::HOOK );
	}

	/**
	 * Whether the site's run is scheduled.
	 *
	 * @return bool
	 */
	public function is_scheduled(): bool {
		if ( false !== wp_next_scheduled( self::HOOK ) ) {
			return true;
		}
		return function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( self::HOOK, [], self::GROUP );
	}

	/**
	 * Clears the site's scheduled run.
	 */
	public function unschedule(): void {
		$this->queued_in_as = false;
		wp_clear_scheduled_hook( self::HOOK );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, [], self::GROUP );
		}
```

In `includes/HierarchyGuard/Trash/Trash_Handler.php`, replace:

```php
			$this->queue->offer( $job );
			$this->queue->schedule( $root );
```

with:

```php
			$this->queue->offer( $job );
			$this->queue->schedule();
```

In `includes/HierarchyGuard/Trash/Trash_Handler.php`, replace:

```php
			$this->queue->schedule( $root );
```

with:

```php
			$this->queue->schedule();
```

In `README.md`, replace:

```markdown
One request moves at most 200 subpages and trashes or restores at most 25 through core (filters `hierarchy_guard_batch_threshold` and `hierarchy_guard_core_call_threshold`). Anything beyond that runs in background batches through WP-Cron, or Action Scheduler when it is installed, and until a job finishes some subpages are still under the trashed page. An hourly check restarts a job whose run stopped. On hosts where cron is disabled or only runs on traffic, finish queued jobs with:
```

with:

```markdown
One request moves at most 200 subpages and trashes or restores at most 25 through core (filters `hierarchy_guard_batch_threshold` and `hierarchy_guard_core_call_threshold`). Anything beyond that runs in background batches through WP-Cron, or Action Scheduler when it is installed: one scheduled run per site works through every waiting job, and until a job finishes some subpages are still under the trashed page. While jobs wait, an hourly check restarts a job whose run stopped; with no job, nothing is scheduled. On hosts where cron is disabled or only runs on traffic, finish queued jobs with:
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `npm run test:php -- --filter 'NoticesTest|ProcessorTest|QueueTest|TrashHandlerTest'`

Expected: `OK`.

Run: `npm run test:php`, `npm run test:php:multisite` and `npm run test:js`

Expected: `Tests: 995, Assertions: 4451, Skipped: 5.` (single site), `OK (995 tests, 4488 assertions)` (multisite), and Vitest `Tests 176 passed (176)`.

- [ ] **Step 5: Lint and build**

Run: `composer lint && npm run lint:js && npm run lint:style && npm run build`

Expected: every command exits 0.

- [ ] **Step 6: Commit**

```bash
git add README.md includes/HierarchyGuard/Admin/Notices.php includes/HierarchyGuard/Batch/Processor.php includes/HierarchyGuard/Batch/Queue.php includes/HierarchyGuard/Trash/Trash_Handler.php tests/phpunit/Action_Scheduler_Stub.php tests/phpunit/NoticesTest.php tests/phpunit/ProcessorTest.php tests/phpunit/QueueTest.php tests/phpunit/TrashHandlerTest.php tests/phpunit/stubs/action-scheduler.php
git commit -m "fix: one scheduled run per site works through every queued job, and the watchdog exists only while jobs wait"
```

---

### Task 8: The daily rebuild exists only once the ledger has entries; the plugin stores its version

**Findings:** 3.4, 9.5 (rebuild), 3.11, 5.6, and the plan audits' C-6 and S-4

- `Ledger::schedule_rebuild()` schedules the newest-records rebuild only when an entry exists (one `LIMIT 1` query); recording the first entry schedules it a day ahead; a run that finds no entry of a supported type schedules no next one. The old clean-up of development-build events is removed: no site ever ran those builds.
- `Plugin::setup()` (activation, and `admin_init` only when `hierarchy_guard_version` differs from `HIERARCHY_GUARD_VERSION`) schedules the watchdog when jobs wait, the rebuild when entries exist and Yoast's run when pages wait for it (Task 3), then stores the version. An admin load otherwise reads one autoloaded option. The audit suggested a non-autoloaded option; autoloaded is chosen because it is read on every admin load.
- On a network, core runs the activation hook on one site only, so a subsite would not resume its jobs or its rebuild after a network deactivation and reactivation. `setup()` also stores when the plugin was network-activated (`hierarchy_guard_network_setup`), and `maybe_upgrade()` runs `setup()` again when core's `active_sitewide_plugins` time differs: each subsite on its own next admin load, so no request loops over the network. Core has loaded that network option already.
- A WordPress import of a ledger row, seen on `added_post_meta`, now bumps the ledger's last-changed key, so a miss cached before the import is read again, and brings the rebuild forward (`Meta_Guard::note_import()`).

**Files:**
- Modify: `includes/HierarchyGuard/Batch/Processor.php`
- Modify: `includes/HierarchyGuard/Ledger.php`
- Modify: `includes/HierarchyGuard/Meta_Guard.php`
- Modify: `includes/HierarchyGuard/Plugin.php`
- Modify: `README.md`
- Modify: `hierarchy-guard.php`
- Test (modify): `tests/phpunit/LedgerTest.php`
- Test (modify): `tests/phpunit/PluginTest.php`
- Test (modify): `tests/phpunit/TrashHandlerTest.php`

**Interfaces:**
- Consumes: `Processor::activate()` (Task 7), `Compat\Yoast::resume()` (Task 3).
- Produces: `Plugin::VERSION_OPTION = 'hierarchy_guard_version'`, `Plugin::NETWORK_OPTION = 'hierarchy_guard_network_setup'`, static `Plugin::setup(): void`, static `Plugin::maybe_upgrade(): void` (on `admin_init`); `Ledger::schedule_rebuild(): void`, `Ledger::rebuild_soon(): void` (now public); `Meta_Guard::check( $check, string $key, bool $importing )`, `Meta_Guard::imported( $meta_id, $object_id, $meta_key )` on `added_post_meta`, `Meta_Guard::note_import( string $key, bool $importing ): void`.

- [ ] **Step 1: Write the failing tests**

In `tests/phpunit/LedgerTest.php`, replace:

```php
use HierarchyGuard\Meta_Guard;
```

with:

```php
use HierarchyGuard\Meta_Guard;
use HierarchyGuard\Plugin;
```

In `tests/phpunit/LedgerTest.php`, replace:

```php

	public function test_records_made_before_the_list_reach_it_on_the_first_rebuild(): void {
```

with:

```php

	public function test_a_site_without_entries_schedules_no_rebuild(): void {
		Ledger::schedule_rebuild();

		$this->assertSame( [], self::rebuild_events() );
		$this->assertFalse( get_option( Ledger::RECENT_STATE ) );
		$this->assertFalse( Ledger::building(), 'Nothing to build, so no notice.' );
	}

	public function test_the_first_entry_recorded_schedules_the_daily_rebuild(): void {
		$this->ledger->record( $this->about, 'first' );

		$events = self::rebuild_events();
		$this->assertCount( 1, $events );
		$this->assertGreaterThan( time() + DAY_IN_SECONDS - MINUTE_IN_SECONDS, $events[0]['time'] );
		$this->assertFalse( Ledger::building(), 'The entry is on the list already.' );
	}

	public function test_a_rebuild_that_finds_no_entry_schedules_nothing_after_it(): void {
		$this->ledger->record( $this->about, 'gone' );
		$this->ledger->forget( $this->about, 'gone' );
		wp_clear_scheduled_hook( Ledger::REBUILD_HOOK );

		self::run_rebuild();

		$this->assertSame( [], self::rebuild_events(), 'The next entry recorded schedules it again.' );
		$this->assertSame( 'done:page', get_option( Ledger::RECENT_STATE ) );
	}

	public function test_an_imported_entry_brings_the_rebuild_forward(): void {
		$this->ledger->record( $this->about, 'kept' );
		$this->assertGreaterThan( time() + HOUR_IN_SECONDS, wp_next_scheduled( Ledger::REBUILD_HOOK ), 'Precondition: a day ahead.' );

		$this->assertNull( ( new Meta_Guard() )->check( null, Ledger::key( 'page', 'imported' ), true ), 'An import may add the row.' );
		( new Meta_Guard() )->note_import( Ledger::key( 'page', 'imported' ), true );

		$this->assertLessThanOrEqual( time(), wp_next_scheduled( Ledger::REBUILD_HOOK ) );
		$this->assertCount( 1, self::rebuild_events() );
	}

	public function test_an_imported_entry_is_found_where_a_miss_was_cached(): void {
		$this->assertNull( $this->ledger->find( 'page', 'imported-about' ), 'Precondition: the miss is cached now.' );
		Meta_Guard::writing( fn() => add_post_meta( $this->about, Ledger::key( 'page', 'imported-about' ), 'imported-about' ) );
		$this->assertNull( $this->ledger->find( 'page', 'imported-about' ), 'Precondition: a row added without a bump is not seen.' );

		( new Meta_Guard() )->note_import( Ledger::key( 'page', 'imported-about' ), true );

		$this->assertSame( $this->about, $this->ledger->find( 'page', 'imported-about' ) );
	}

	public function test_a_row_added_outside_an_import_brings_nothing_forward(): void {
		$this->ledger->record( $this->about, 'kept' );
		$changed = wp_cache_get_last_changed( Ledger::CACHE_GROUP );

		( new Meta_Guard() )->note_import( Ledger::key( 'page', 'other' ), false );
		( new Meta_Guard() )->note_import( '_other_key', true );

		$this->assertSame( $changed, wp_cache_get_last_changed( Ledger::CACHE_GROUP ) );
		$this->assertGreaterThan( time() + HOUR_IN_SECONDS, wp_next_scheduled( Ledger::REBUILD_HOOK ) );
	}

	public function test_records_made_before_the_list_reach_it_on_the_first_rebuild(): void {
```

In `tests/phpunit/LedgerTest.php`, replace:

```php
	public function test_the_rebuild_schedules_its_own_next_run_a_day_later_and_only_one(): void {
```

with:

```php
	public function test_the_rebuild_schedules_its_own_next_run_a_day_later_and_only_one(): void {
		$this->ledger->record( $this->about, 'kept' );
```

In `tests/phpunit/LedgerTest.php`, replace:

```php

	public function test_an_old_recurring_rebuild_event_is_replaced_by_a_single_one(): void {
		Ledger::schedule_rebuild();
		Ledger::rebuild_recent();
		wp_clear_scheduled_hook( Ledger::REBUILD_HOOK );
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', Ledger::REBUILD_HOOK );

		Ledger::schedule_rebuild();

		$events = self::rebuild_events();
		$this->assertCount( 1, $events );
		$this->assertFalse( $events[0]['schedule'] ?? false );
	}

```

with:

```php

```

In `tests/phpunit/LedgerTest.php`, replace:

```php
		Ledger::schedule_rebuild();
		Ledger::rebuild_recent();
		$next = wp_next_scheduled( Ledger::REBUILD_HOOK );

		$queries = get_num_queries();
		Ledger::schedule_rebuild();
		$this->assertSame( $queries, get_num_queries(), 'Reads of autoloaded options only.' );
```

with:

```php
		$this->old_entry( $this->about, 'old' );
		Ledger::schedule_rebuild();
		Ledger::rebuild_recent();
		Plugin::setup();
		$next = wp_next_scheduled( Ledger::REBUILD_HOOK );

		$queries = get_num_queries();
		Plugin::maybe_upgrade();
		$this->assertSame( $queries, get_num_queries(), 'A read of an autoloaded option only.' );
```

In `tests/phpunit/LedgerTest.php`, replace:

```php
		$this->assertTrue( $hooked( 'admin_init', 'schedule_rebuild' ) );
```

with:

```php
		$this->assertFalse( $hooked( 'admin_init', 'schedule_rebuild' ), 'An admin load schedules nothing.' );
		$this->assertSame( 10, has_action( 'admin_init', [ Plugin::class, 'maybe_upgrade' ] ) );
```

In `tests/phpunit/LedgerTest.php`, replace:

```php
		$only  = static fn(): array => [ 'page' ];
```

with:

```php
		$this->old_entry( $this->about, 'old' );
		$only = static fn(): array => [ 'page' ];
```

In `tests/phpunit/LedgerTest.php`, replace:

```php
	public function test_types_wp_cron_sees_differently_from_wp_admin_never_keep_the_notice_on(): void {
```

with:

```php
	public function test_types_wp_cron_sees_differently_from_wp_admin_never_keep_the_notice_on(): void {
		$this->old_entry( $this->about, 'old' );
```

In `tests/phpunit/LedgerTest.php`, replace:

```php

	public function test_scheduling_the_daily_rebuild_clears_what_earlier_builds_left_once(): void {
		Ledger::schedule_rebuild();
		Ledger::rebuild_recent();
		wp_clear_scheduled_hook( Ledger::REBUILD_HOOK );
		// Left by a build of the plugin from before the daily rebuild.
		wp_schedule_single_event( time() + HOUR_IN_SECONDS, 'hierarchy_guard_backfill_recent' );
		add_option( 'hierarchy_guard_recent_lock', (string) ( time() + 10 ), '', false );
		add_option( 'hierarchy_guard_recent_queue', '["add","page",[1]]', '', false );

		Ledger::schedule_rebuild();

		$this->assertFalse( wp_next_scheduled( 'hierarchy_guard_backfill_recent' ) );
		$this->assertFalse( get_option( 'hierarchy_guard_recent_lock' ) );
		$this->assertFalse( get_option( 'hierarchy_guard_recent_queue' ) );
		$this->assertCount( 1, self::rebuild_events() );

		$queries = get_num_queries();
		Ledger::schedule_rebuild();
		$this->assertSame( $queries, get_num_queries(), 'Once: with the daily run scheduled, nothing is cleared again.' );
	}

```

with:

```php

```

In `tests/phpunit/PluginTest.php`, replace:

```php
use HierarchyGuard\Plugin;
```

with:

```php
use HierarchyGuard\Batch\Queue;
use HierarchyGuard\Ledger;
use HierarchyGuard\Meta_Guard;
use HierarchyGuard\Plugin;
use HierarchyGuard\Trash\Operations;
```

In `tests/phpunit/PluginTest.php`, replace:

```php

	public function test_every_shipped_php_file_stops_when_requested_directly(): void {
```

with:

```php

	public function test_a_site_is_set_up_once_per_version_and_a_fresh_one_schedules_nothing(): void {
		delete_option( Plugin::VERSION_OPTION );
		wp_clear_scheduled_hook( Ledger::REBUILD_HOOK );
		wp_clear_scheduled_hook( Queue::WATCHDOG );

		Plugin::maybe_upgrade();

		$this->assertSame( HIERARCHY_GUARD_VERSION, get_option( Plugin::VERSION_OPTION ) );
		$this->assertFalse( wp_next_scheduled( Ledger::REBUILD_HOOK ), 'No entry, so no rebuild.' );
		$this->assertFalse( wp_next_scheduled( Queue::WATCHDOG ), 'No job, so no watchdog.' );
		$queries = get_num_queries();
		Plugin::maybe_upgrade();
		$this->assertSame( $queries, get_num_queries(), 'Set up already: an autoloaded read.' );
	}

	public function test_an_update_schedules_the_rebuild_a_site_with_entries_needs(): void {
		$page = self::factory()->post->create( [ 'post_type' => 'page' ] );
		Meta_Guard::writing( static fn() => add_post_meta( $page, Ledger::key( 'page', 'old' ), 'old', true ) );
		wp_clear_scheduled_hook( Ledger::REBUILD_HOOK );
		update_option( Plugin::VERSION_OPTION, '0.0.1', true );

		Plugin::maybe_upgrade();

		$this->assertNotFalse( wp_next_scheduled( Ledger::REBUILD_HOOK ) );
		$this->assertSame( HIERARCHY_GUARD_VERSION, get_option( Plugin::VERSION_OPTION ) );
	}

	public function test_a_subsite_is_set_up_again_after_the_plugin_is_network_activated_again(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only: run with WP_MULTISITE=1.' );
		}
		$root = self::factory()->post->create( [ 'post_type' => 'page' ] );
		$jobs = new Queue();
		$jobs->save( Operations::new_job( Operations::TYPE_REPARENT, $root, 'page', 0, [ 'to' => 0 ] ) );
		Plugin::setup();
		wp_clear_scheduled_hook( Queue::WATCHDOG );

		Plugin::maybe_upgrade();
		$this->assertFalse( wp_next_scheduled( Queue::WATCHDOG ), 'Same version, same network activation: nothing to do.' );

		$again = static fn(): array => [ plugin_basename( HIERARCHY_GUARD_FILE ) => 1700000000 ];
		add_filter( 'pre_site_option_active_sitewide_plugins', $again );
		Plugin::maybe_upgrade();
		remove_filter( 'pre_site_option_active_sitewide_plugins', $again );

		$this->assertNotFalse( wp_next_scheduled( Queue::WATCHDOG ), 'The job waiting here is resumed.' );
		$this->assertSame( 1700000000, (int) get_option( Plugin::NETWORK_OPTION ) );
		$jobs->delete( $root );
		wp_clear_scheduled_hook( Queue::WATCHDOG );
	}

	public function test_every_shipped_php_file_stops_when_requested_directly(): void {
```

In `tests/phpunit/TrashHandlerTest.php`, replace:

```php
		$this->assertLessThanOrEqual( 2, $writes, 'The run and its watchdog, once each.' );
```

with:

```php
		$this->assertLessThanOrEqual( 3, $writes, 'The run, its watchdog and the daily rebuild of the records, once each.' );
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npm run test:php -- --filter 'LedgerTest|PluginTest|TrashHandlerTest'`

Expected: FAIL as below; every other test in these classes passes.

- `LedgerTest::test_an_imported_entry_is_found_where_a_miss_was_cached`: Error: Call to undefined method HierarchyGuard\Meta_Guard::note_import()
- `LedgerTest::test_a_row_added_outside_an_import_brings_nothing_forward`: Error: Call to undefined method HierarchyGuard\Meta_Guard::note_import()
- `LedgerTest::test_once_built_a_normal_load_runs_nothing`: Error: Call to undefined method HierarchyGuard\Plugin::setup()
- `PluginTest::test_a_site_is_set_up_once_per_version_and_a_fresh_one_schedules_nothing`: Error: Undefined constant HierarchyGuard\Plugin::VERSION_OPTION
- `PluginTest::test_an_update_schedules_the_rebuild_a_site_with_entries_needs`: Error: Undefined constant HierarchyGuard\Plugin::VERSION_OPTION
- `LedgerTest::test_a_site_without_entries_schedules_no_rebuild`: Failed asserting that two arrays are identical.
- `LedgerTest::test_the_first_entry_recorded_schedules_the_daily_rebuild`: Failed asserting that actual size 0 matches expected size 1.
- `LedgerTest::test_a_rebuild_that_finds_no_entry_schedules_nothing_after_it`: The next entry recorded schedules it again.
- `LedgerTest::test_an_imported_entry_brings_the_rebuild_forward`: Precondition: a day ahead.

Totals: `Tests: 121, Assertions: 1639, Errors: 5, Failures: 4, Skipped: 2.`

Mutant, to run once the tests pass, which shows `test_an_imported_entry_is_found_where_a_miss_was_cached` pins its behaviour: in `includes/HierarchyGuard/Meta_Guard.php`, replace

```php
			wp_cache_set_last_changed( Ledger::CACHE_GROUP );

```

with nothing.

Expected: the test fails, as it did on the prototype:

```
LedgerTest::test_an_imported_entry_is_found_where_a_miss_was_cached
Failed asserting that null is identical to 4.
Tests: 1, Assertions: 3, Failures: 1.
```

Then undo the mutant (`git checkout -- includes/HierarchyGuard/Meta_Guard.php`). Never commit it.

Mutant, to run once the tests pass, which shows `test_a_subsite_is_set_up_again_after_the_plugin_is_network_activated_again` pins its behaviour: in `includes/HierarchyGuard/Plugin.php`, replace

```php
 || ( is_multisite() && self::network_activated() !== (int) get_option( self::NETWORK_OPTION, 0 ) )
```

with nothing.

Expected: the test fails, as it did on the prototype:

```
PluginTest::test_a_subsite_is_set_up_again_after_the_plugin_is_network_activated_again
The job waiting here is resumed.
Tests: 1, Assertions: 2, Failures: 1.
```

Then undo the mutant (`git checkout -- includes/HierarchyGuard/Plugin.php`). Never commit it.

- [ ] **Step 3: Implement**

In `includes/HierarchyGuard/Batch/Processor.php`, replace:

```php
	 * Deactivation: stop scheduled runs. Stored jobs stay; activation
	 * schedules the watchdog again while any wait.
```

with:

```php
	 * Deactivation: stop scheduled runs. Stored jobs stay: activation, or on
	 * a network a subsite's first admin load after the plugin is
	 * network-activated again, schedules the watchdog while any wait.
```

In `includes/HierarchyGuard/Ledger.php`, replace:

```php
	 * Makes sure the daily rebuild of the newest-records list is scheduled,
	 * on activation and on admin_init: one single event, which schedules
	 * the next one a day later when it runs, so on a site where the plugin
	 * is no longer active nothing schedules it again. Until a first build
	 * has completed, the list reads as being built and the rebuild runs on
	 * the next cron run. A type supported later is built by the next daily
	 * run, with no notice. It never runs in a web request, and once built a
	 * page load only reads autoloaded options here.
```

with:

```php
	 * Makes sure the daily rebuild of the newest-records list is scheduled
	 * once the ledger holds an entry, on activation and after an update: one
	 * single event, which schedules the next one a day later when it runs, so
	 * on a site where the plugin is no longer active nothing schedules it
	 * again. A site with no entry schedules nothing; its first entry
	 * schedules the run (remember()). Until a first build has completed, the
	 * list reads as being built and the rebuild runs on the next cron run. A
	 * type supported later is built by the next daily run, with no notice.
	 * It never runs in a web request.
```

In `includes/HierarchyGuard/Ledger.php`, replace:

```php
			// Missing, or a recurring event from an earlier build: one single run, a day ahead.
			if ( false === wp_next_scheduled( self::REBUILD_HOOK ) || false !== wp_get_schedule( self::REBUILD_HOOK ) ) {
				self::clear_leftovers();
```

with:

```php
			if ( false === wp_next_scheduled( self::REBUILD_HOOK ) && self::has_any_entry() ) {
```

In `includes/HierarchyGuard/Ledger.php`, replace:

```php
		}
		if ( 'building' !== $state ) {
```

with:

```php
		}
		if ( ! self::has_any_entry() ) {
			return;
		}
		if ( 'building' !== $state ) {
```

In `includes/HierarchyGuard/Ledger.php`, replace:

```php
	 * rather than another added, unless it is due within a minute already.
	 */
	private static function rebuild_soon(): void {
		$next  = wp_next_scheduled( self::REBUILD_HOOK );
		$older = false !== $next && false !== wp_get_schedule( self::REBUILD_HOOK );
		if ( false !== $next && ! $older && $next <= time() + MINUTE_IN_SECONDS ) {
			return;
		}
		if ( false === $next || $older ) {
			self::clear_leftovers();
		}
```

with:

```php
	 * rather than another added, unless it is due within a minute already:
	 * when deletes leave a full list short, and for entries an import adds,
	 * which reach the list only through the rebuild.
	 */
	public static function rebuild_soon(): void {
		$next = wp_next_scheduled( self::REBUILD_HOOK );
		if ( false !== $next && $next <= time() + MINUTE_IN_SECONDS ) {
			return;
		}
```

In `includes/HierarchyGuard/Ledger.php`, replace:

```php
	 * Schedules the next daily run, from the rebuild itself, unless one is
	 * scheduled already.
```

with:

```php
	 * Schedules the next daily run, from the rebuild itself and when an entry
	 * is recorded, unless one is scheduled already.
```

In `includes/HierarchyGuard/Ledger.php`, replace:

```php
	 * Upgrade cleanup, when the rebuild is first scheduled on a site: the
	 * one-time backfill's event, the list's lock and queue rows, and a
	 * recurring rebuild event, which development builds before the single
	 * daily event left behind.
	 */
	private static function clear_leftovers(): void {
		wp_clear_scheduled_hook( 'hierarchy_guard_backfill_recent' );
		wp_clear_scheduled_hook( self::REBUILD_HOOK );
		delete_option( 'hierarchy_guard_recent_lock' );
		delete_option( 'hierarchy_guard_recent_queue' );
```

with:

```php
	 * Whether any post holds an entry: one range read on the meta_key index
	 * that stops at the first, on activation and after an update only.
	 *
	 * @return bool
	 */
	private static function has_any_entry(): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Range on the meta_key index, LIMIT 1; on activation and after an update only.
		return null !== $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->postmeta} WHERE meta_key LIKE %s LIMIT 1", $wpdb->esc_like( self::KEY_PREFIX ) . '%' ) );
```

In `includes/HierarchyGuard/Ledger.php`, replace:

```php
	 * not marked built.
```

with:

```php
	 * not marked built. When no supported type has an entry left, nothing is
	 * scheduled after it: the next entry recorded schedules the run again.
```

In `includes/HierarchyGuard/Ledger.php`, replace:

```php
			update_option( self::RECENT_STATE, self::done_state( $types ), true );
		}
	}
```

with:

```php
			update_option( self::RECENT_STATE, self::done_state( $types ), true );
		}
		if ( ! $failed && [] === array_filter( $kept, static fn( array $by_type ): bool => [] !== $by_type['ids'] ) ) {
			wp_clear_scheduled_hook( self::REBUILD_HOOK );
		}
	}
```

In `includes/HierarchyGuard/Ledger.php`, replace:

```php
	private static function remember( string $post_type, array $meta_ids ): void {
```

with:

```php
	private static function remember( string $post_type, array $meta_ids ): void {
		self::schedule_next();
```

In `includes/HierarchyGuard/Meta_Guard.php`, replace:

```php
		add_filter( 'add_post_metadata', [ $this, 'refuse_copies' ], 10, 3 );
```

with:

```php
		add_filter( 'add_post_metadata', [ $this, 'refuse_copies' ], 10, 3 );
		add_action( 'added_post_meta', [ $this, 'imported' ], 10, 3 );
```

In `includes/HierarchyGuard/Meta_Guard.php`, replace:

```php
		$key = (string) $meta_key;
		if ( ! str_starts_with( $key, self::PREFIX ) ) {
			return $check;
		}
		return self::allowed( $key, self::$writing > 0, defined( 'WP_IMPORTING' ) && WP_IMPORTING ) ? $check : false;
```

with:

```php
		return $this->check( $check, (string) $meta_key, defined( 'WP_IMPORTING' ) && WP_IMPORTING );
	}

	/**
	 * What refuse_copies() answers, with whether an import is running.
	 *
	 * @param mixed  $check     Null to let the write go ahead, or an earlier callback's answer.
	 * @param string $key       Meta key.
	 * @param bool   $importing Whether a WordPress import is running.
	 * @return mixed False to refuse the row, else $check.
	 */
	public function check( $check, string $key, bool $importing ) {
		if ( ! str_starts_with( $key, self::PREFIX ) ) {
			return $check;
		}
		if ( ! self::allowed( $key, self::$writing > 0, $importing ) ) {
			return false;
		}
		return $check;
	}

	/**
	 * After a meta row is added: see note_import().
	 *
	 * @param mixed $meta_id   Meta ID.
	 * @param mixed $object_id Post ID.
	 * @param mixed $meta_key  Meta key.
	 */
	public function imported( $meta_id, $object_id, $meta_key ): void {
		$this->note_import( (string) $meta_key, defined( 'WP_IMPORTING' ) && WP_IMPORTING );
	}

	/**
	 * An imported ledger entry: lookups cached before it read the ledger
	 * again, and it reaches the newest-records list through the rebuild,
	 * brought forward.
	 *
	 * @param string $key       Meta key of the row just added.
	 * @param bool   $importing Whether a WordPress import is running.
	 */
	public function note_import( string $key, bool $importing ): void {
		if ( $importing && 0 === self::$writing && str_starts_with( $key, Ledger::KEY_PREFIX ) ) {
			wp_cache_set_last_changed( Ledger::CACHE_GROUP );
			Ledger::rebuild_soon();
		}
```

In `includes/HierarchyGuard/Plugin.php`, replace:

```php
final class Plugin {

	/**
```

with:

```php
final class Plugin {

	public const VERSION_OPTION = 'hierarchy_guard_version';
	public const NETWORK_OPTION = 'hierarchy_guard_network_setup';

	/**
```

In `includes/HierarchyGuard/Plugin.php`, replace:

```php
	}

	/**
```

with:

```php
	}

	/**
	 * What a site needs once, on activation, after an update and after a
	 * network activation: the watchdog when jobs wait, the daily rebuild when
	 * the ledger holds entries, and Yoast's run when pages wait for it.
	 * Records the version and the network activation it was done for.
	 */
	public static function setup(): void {
		Batch\Processor::activate();
		Ledger::schedule_rebuild();
		Compat\Yoast::resume();
		update_option( self::VERSION_OPTION, HIERARCHY_GUARD_VERSION, true );
		if ( is_multisite() ) {
			update_option( self::NETWORK_OPTION, self::network_activated(), true );
		}
	}

	/**
	 * Runs setup() when the stored version differs from the code's: after
	 * an update, on a subsite of a network activation, or where the plugin
	 * is loaded without its activation hook. On a network it runs, too, once
	 * the plugin was network-activated again since this subsite was set up,
	 * because core runs the activation hook on one site only. Otherwise one
	 * read of an autoloaded option, and on a network one of a network option
	 * core has loaded already.
	 */
	public static function maybe_upgrade(): void {
		if ( HIERARCHY_GUARD_VERSION !== get_option( self::VERSION_OPTION ) || ( is_multisite() && self::network_activated() !== (int) get_option( self::NETWORK_OPTION, 0 ) ) ) {
			self::setup();
		}
	}

	/**
	 * When the plugin was last network-activated, from core's own list, or
	 * 0 when it isn't network-active.
	 *
	 * @return int
	 */
	private static function network_activated(): int {
		$sitewide = get_site_option( 'active_sitewide_plugins', [] );
		return is_array( $sitewide ) ? (int) ( $sitewide[ plugin_basename( HIERARCHY_GUARD_FILE ) ] ?? 0 ) : 0;
	}

	/**
```

In `includes/HierarchyGuard/Plugin.php`, replace:

```php
		// The newest-records list is rebuilt daily from WP-Cron; admin_init makes sure the run is scheduled.
		add_action( 'admin_init', [ Ledger::class, 'schedule_rebuild' ] );
		add_action( Ledger::REBUILD_HOOK, [ Ledger::class, 'rebuild_recent' ] );
```

with:

```php
		// The newest-records list is rebuilt daily from WP-Cron, once the ledger holds an entry.
		add_action( Ledger::REBUILD_HOOK, [ Ledger::class, 'rebuild_recent' ] );
		add_action( 'admin_init', [ self::class, 'maybe_upgrade' ] );
```

In `README.md`, replace:

```markdown
The Redirects tab lists the newest 1,000 records from a list the plugin keeps. A daily WP-Cron event rebuilds that list from the database: it adds records made before the list existed, or before their post type was supported, and an entry that two writes at the same moment lost, so a lost update heals within a day; a search by full URL finds any record meanwhile. The same event runs early when deletes leave a full list short. Until the list is first built, the tab says it is still being built. Without cron, run it with:
```

with:

```markdown
The Redirects tab lists the newest 1,000 records from a list the plugin keeps. Once the site has a record, a daily WP-Cron event rebuilds that list from the database: it adds records made before the list existed, or before their post type was supported, and an entry that two writes at the same moment lost, so a lost update heals within a day; a search by full URL finds any record meanwhile. The same event runs early when deletes leave a full list short. Until the list is first built, the tab says it is still being built. Without cron, run it with:
```

In `hierarchy-guard.php`, replace:

```php
register_activation_hook( __FILE__, [ Batch\Processor::class, 'activate' ] );
register_activation_hook( __FILE__, [ Ledger::class, 'schedule_rebuild' ] );
```

with:

```php
register_activation_hook( __FILE__, [ Plugin::class, 'setup' ] );
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `npm run test:php -- --filter 'LedgerTest|PluginTest|TrashHandlerTest'`

Expected: `OK`.

Run: `npm run test:php`, `npm run test:php:multisite` and `npm run test:js`

Expected: `Tests: 1002, Assertions: 4469, Skipped: 6.` (single site), `OK (1002 tests, 4509 assertions)` (multisite), and Vitest `Tests 176 passed (176)`.

- [ ] **Step 5: Lint and build**

Run: `composer lint && npm run lint:js && npm run lint:style && npm run build`

Expected: every command exits 0.

- [ ] **Step 6: Commit**

```bash
git add README.md hierarchy-guard.php includes/HierarchyGuard/Batch/Processor.php includes/HierarchyGuard/Ledger.php includes/HierarchyGuard/Meta_Guard.php includes/HierarchyGuard/Plugin.php tests/phpunit/LedgerTest.php tests/phpunit/PluginTest.php tests/phpunit/TrashHandlerTest.php
git commit -m "fix: schedule the daily rebuild only once the ledger has entries, and store the plugin's version"
```

---

### Task 9: A 404 looks up every prefix of its path in one query, cached for the whole path

**Findings:** 5.2, 8.1, 9.3

- With built-in redirects on, a 20-segment miss ran 22 seeks and cached 22 keys. `Ledger::find_prefixes( $type, $path )` reads the hashed keys of every prefix in one `meta_key IN (…)` query and caches the answer under the full path; the holder's status is still checked on each call, so a trashed holder is never served from cache.
- `Live_View` keeps the answer for the rest of the request, since the rules ask for each prefix in turn, and drops it when the ledger's last-changed key moves.

**Files:**
- Modify: `includes/HierarchyGuard/Ledger.php`
- Modify: `includes/HierarchyGuard/Redirects/Live_View.php`
- Test (modify): `tests/phpunit/ResolverTest.php`

**Interfaces:**
- Produces: `Ledger::find_prefixes( string $post_type, string $path ): array<string,int|null>`, by prefix with the path itself first: the holder's ID, 0 for the root list, null when unknown. `Ledger::find()` keeps its signature and reads its own entry from it.

- [ ] **Step 1: Write the failing tests**

In `tests/phpunit/ResolverTest.php`, replace:

```php

	public function test_resolves_a_subpage_after_its_parent_was_renamed(): void {
```

with:

```php

	public function test_an_unknown_path_twenty_segments_deep_costs_one_ledger_query_then_none(): void {
		$path = implode( '/', array_map( static fn( int $i ): string => "seg-{$i}", range( 1, 20 ) ) );
		get_option( Ledger::ROOT_OPTION );

		$queries = get_num_queries();
		$this->assertNull( $this->resolver->resolve( 'page', $path ) );
		$this->assertSame( 1, get_num_queries() - $queries, 'Every prefix in one query.' );

		$queries = get_num_queries();
		$this->assertNull( ( new Resolver( $this->ledger ) )->resolve( 'page', $path ) );
		$this->assertSame( 0, get_num_queries() - $queries, 'The miss is cached for the whole path.' );
	}

	public function test_each_prefix_of_a_path_answers_as_its_own_lookup_does(): void {
		$this->ledger->record( $this->about, 'old-about' );
		$this->ledger->record_root( 'page', 'archive' );

		$this->assertSame(
			[
				'old-about/x/y' => null,
				'old-about/x'   => null,
				'old-about'     => $this->about,
			],
			$this->ledger->find_prefixes( 'page', '/Old-About/x/y/' )
		);
		$this->assertSame( [ 'archive' => 0 ], $this->ledger->find_prefixes( 'page', 'archive' ) );
		$this->assertSame( [], $this->ledger->find_prefixes( 'page', '/' ) );
	}

	public function test_resolves_a_subpage_after_its_parent_was_renamed(): void {
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npm run test:php -- --filter 'ResolverTest'`

Expected: FAIL as below; every other test in these classes passes.

- `ResolverTest::test_each_prefix_of_a_path_answers_as_its_own_lookup_does`: Error: Call to undefined method HierarchyGuard\Ledger::find_prefixes()
- `ResolverTest::test_an_unknown_path_twenty_segments_deep_costs_one_ledger_query_then_none`: Every prefix in one query.

Totals: `Tests: 38, Assertions: 118, Errors: 1, Failures: 1.`

Mutant, to run once the tests pass, which shows `test_an_unknown_path_twenty_segments_deep_costs_one_ledger_query_then_none` pins its behaviour: in `includes/HierarchyGuard/Ledger.php`, replace

```php
		$found     = wp_cache_get( $cache_key, self::CACHE_GROUP );
```

with:

```php
		$found     = false;
```

Expected: the test fails, as it did on the prototype:

```
ResolverTest::test_an_unknown_path_twenty_segments_deep_costs_one_ledger_query_then_none
The miss is cached for the whole path.
Tests: 1, Assertions: 4, Failures: 1.
```

Then undo the mutant (`git checkout -- includes/HierarchyGuard/Ledger.php`). Never commit it.

- [ ] **Step 3: Implement**

In `includes/HierarchyGuard/Ledger.php`, replace:

```php
	private const CACHE_MISS = -1;
	private const CACHE_TTL  = 12 * HOUR_IN_SECONDS;
```

with:

```php
	private const CACHE_TTL = 12 * HOUR_IN_SECONDS;
```

In `includes/HierarchyGuard/Ledger.php`, replace:

```php
		$path = Paths::normalize( $path );
		if ( '' === $path ) {
			return null;
		}

		$cache_key = 'find:' . md5( $post_type . ':' . $path ) . ':' . wp_cache_get_last_changed( self::CACHE_GROUP );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			$found = self::CACHE_MISS === (int) $cached ? null : (int) $cached;
		} else {
			$found = $this->seek( $post_type, $path );
			if ( null === $found ) {
				$roots = $this->roots();
				if ( in_array( $path, $roots[ $post_type ] ?? [], true ) ) {
					$found = 0;
				}
			}

			// phpcs:ignore WordPressVIPMinimum.Performance.LowExpiryCacheTime.CacheTimeUndetermined -- CACHE_TTL is 12 hours, well above 300 seconds.
			wp_cache_set( $cache_key, null === $found ? self::CACHE_MISS : $found, self::CACHE_GROUP, self::CACHE_TTL );
```

with:

```php
		$path = Paths::normalize( $path );
		return '' === $path ? null : $this->find_prefixes( $post_type, $path )[ $path ];
	}

	/**
	 * What a path and each shorter prefix of it used to lead to, as find()
	 * answers each: one query for all of them, and one cache entry for the
	 * path, so a 404 several segments deep costs what a one-segment one does.
	 *
	 * @param string $post_type Post type name.
	 * @param string $path      Path, any form; normalized here.
	 * @return array<string,int|null> By prefix, the path itself first: post ID, 0 for the root list, null when unknown.
	 */
	public function find_prefixes( string $post_type, string $path ): array {
		$path = Paths::normalize( $path );
		if ( '' === $path ) {
			return [];
		}
		$segments = explode( '/', $path );
		$prefixes = [];
		for ( $length = count( $segments ); $length >= 1; $length-- ) {
			$prefixes[] = implode( '/', array_slice( $segments, 0, $length ) );
		}

		$cache_key = 'find:' . md5( $post_type . ':' . $path ) . ':' . wp_cache_get_last_changed( self::CACHE_GROUP );
		$found     = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( ! is_array( $found ) ) {
			$found = $this->seek( $post_type, $prefixes );
			$roots = $this->roots()[ $post_type ] ?? [];
			foreach ( $found as $prefix => $id ) {
				if ( null === $id && in_array( (string) $prefix, $roots, true ) ) {
					$found[ $prefix ] = 0;
				}
			}
			// phpcs:ignore WordPressVIPMinimum.Performance.LowExpiryCacheTime.CacheTimeUndetermined -- CACHE_TTL is 12 hours, well above 300 seconds.
			wp_cache_set( $cache_key, $found, self::CACHE_GROUP, self::CACHE_TTL );
```

In `includes/HierarchyGuard/Ledger.php`, replace:

```php
		if ( null !== $found && $found > 0 && in_array( get_post_status( $found ), [ false, 'trash' ], true ) ) {
			$roots = $this->roots();
			return in_array( $path, $roots[ $post_type ] ?? [], true ) ? 0 : null;
		}

		return $found;
```

with:

```php
		$out = [];
		foreach ( $prefixes as $prefix ) {
			$id = $found[ $prefix ] ?? null;
			if ( null !== $id && $id > 0 && in_array( get_post_status( $id ), [ false, 'trash' ], true ) ) {
				$id = in_array( $prefix, $this->roots()[ $post_type ] ?? [], true ) ? 0 : null;
			}
			$out[ $prefix ] = $id;
		}
		return $out;
```

In `includes/HierarchyGuard/Ledger.php`, replace:

```php
	 * Single index seek on the hashed key: a live holder first, newest entry first.
	 * A post in the trash comes back only when no live post holds the key; find()
	 * then answers as if it held nothing.
	 *
	 * @param string $post_type Post type name.
	 * @param string $path      Normalized path.
	 */
	private function seek( string $post_type, string $path ): ?int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Point lookup on the meta_key index; cached by the caller.
		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND p.post_type = %s ORDER BY ( p.post_status = 'trash' ) ASC, pm.meta_id DESC LIMIT 1",
				self::key( $post_type, $path ),
				$post_type
			)
		);

		return $id ? (int) $id : null;
```

with:

```php
	 * Index seeks on the hashed keys of several paths, in one query: for
	 * each, a live holder first, newest entry first. A post in the trash
	 * comes back only when no live post holds the key; find() then answers
	 * as if it held nothing. The rows are the holders of these paths.
	 *
	 * @param string   $post_type Post type name.
	 * @param string[] $paths     Normalized paths.
	 * @return array<string,int|null> By path.
	 */
	private function seek( string $post_type, array $paths ): array {
		global $wpdb;

		$by_key = [];
		foreach ( $paths as $path ) {
			$by_key[ self::key( $post_type, $path ) ] = $path;
		}
		$placeholders = implode( ',', array_fill( 0, count( $by_key ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $placeholders holds only %s, one per path, and the values come as one array; seeks on the meta_key index, cached by the caller.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT pm.meta_key, pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key IN ( {$placeholders} ) AND p.post_type = %s ORDER BY ( p.post_status = 'trash' ) ASC, pm.meta_id DESC", array_merge( array_keys( $by_key ), [ $post_type ] ) ), ARRAY_A );

		$found = array_fill_keys( $paths, null );
		foreach ( (array) $rows as $row ) {
			$path = $by_key[ (string) $row['meta_key'] ] ?? null;
			if ( null !== $path && null === $found[ $path ] ) {
				$found[ $path ] = (int) $row['post_id'];
			}
		}
		return $found;
```

In `includes/HierarchyGuard/Redirects/Live_View.php`, replace:

```php
	private Ledger $ledger;

	/**
```

with:

```php
	private Ledger $ledger;

	/**
	 * What find_prefixes() answered in this request, by post type then prefix,
	 * while the ledger is unchanged: the rules ask for each prefix of a path
	 * in turn, longest first, and the first ask reads them all.
	 *
	 * @var array<string,array<string,int|null>>
	 */
	private array $found = [];

	/**
	 * The ledger's last-changed key $found was read under.
	 *
	 * @var string
	 */
	private string $found_under = '';

	/**
```

In `includes/HierarchyGuard/Redirects/Live_View.php`, replace:

```php
		return $this->ledger->find( $post_type, $prefix );
```

with:

```php
		$changed = (string) wp_cache_get_last_changed( Ledger::CACHE_GROUP );
		if ( $changed !== $this->found_under ) {
			$this->found       = [];
			$this->found_under = $changed;
		}
		if ( ! array_key_exists( $prefix, $this->found[ $post_type ] ?? [] ) ) {
			$this->found[ $post_type ] = $this->ledger->find_prefixes( $post_type, $prefix ) + ( $this->found[ $post_type ] ?? [] );
		}
		return $this->found[ $post_type ][ $prefix ] ?? null;
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `npm run test:php -- --filter 'ResolverTest'`

Expected: `OK`.

Run: `npm run test:php`, `npm run test:php:multisite` and `npm run test:js`

Expected: `Tests: 1004, Assertions: 4476, Skipped: 6.` (single site), `OK (1004 tests, 4516 assertions)` (multisite), and Vitest `Tests 176 passed (176)`.

- [ ] **Step 5: Lint and build**

Run: `composer lint && npm run lint:js && npm run lint:style && npm run build`

Expected: every command exits 0.

- [ ] **Step 6: Commit**

```bash
git add includes/HierarchyGuard/Ledger.php includes/HierarchyGuard/Redirects/Live_View.php tests/phpunit/ResolverTest.php
git commit -m "perf: look up every prefix of a 404 path in one query and cache the answer for the whole path"
```

---

### Task 10: A subtree lists in tree order; a large parent's capped subpages are its oldest

**Findings:** 6.5, 9.2

- The subtree filter listed the whole subtree flat by `menu_order`, interleaving branches. `Subtree::descendant_ids()` now returns depth-first tree order (siblings by menu order, then title), and the list keeps it with `orderby => post__in` unless a column is sorted.
- Past the cap, the panel, meta box and Tree view listed the 1,000 lowest IDs under the label "first 1,000". Ordering the capped read by `menu_order` would sort every child of a huge parent, so the simple fix is taken: the label now says what it is, "Only the 1,000 oldest subpages are shown."

**Files:**
- Modify: `includes/HierarchyGuard/Admin/List_Table.php`
- Modify: `includes/HierarchyGuard/Overview.php`
- Modify: `includes/HierarchyGuard/Subtree.php`
- Modify: `src/common/copy.js`
- Modify: `README.md`
- Test (modify): `src/common/test/copy.test.js`
- Test (modify): `src/tree/test/tree.test.js`
- Test (modify): `tests/phpunit/ListTableTest.php`
- Test (modify): `tests/phpunit/SubtreeTest.php`

**Interfaces:**
- Produces: `Subtree::descendant_ids()` in tree order (same signature); `treeCappedSentence( cap )` in `src/common/copy.js` reads "oldest".

- [ ] **Step 1: Write the failing tests**

In `src/common/test/copy.test.js`, replace:

```js
			'Only the first 1,000 subpages are shown.'
```

with:

```js
			'Only the 1,000 oldest subpages are shown.'
```

In `src/tree/test/tree.test.js`, replace:

```js
		).toBe( 'Only the first 1,000 subpages are shown.' );
```

with:

```js
		).toBe( 'Only the 1,000 oldest subpages are shown.' );
```

In `tests/phpunit/ListTableTest.php`, replace:

```php
		$this->assertSame(
			[
				'menu_order' => 'ASC',
				'title'      => 'ASC',
			],
			$vars['orderby'],
			'Not core’s tree mode, which loads every page.'
		);
```

with:

```php
		$this->assertSame( 'post__in', $vars['orderby'], 'The walk’s tree order, and not core’s tree mode, which loads every page.' );
```

In `tests/phpunit/ListTableTest.php`, replace:

```php

	public function test_a_sorted_list_keeps_its_sort(): void {
```

with:

```php

	public function test_the_filter_lists_each_page_followed_by_its_own_subpages(): void {
		$early                         = $this->page( 'aa-early', $this->ids['history'] );
		$_GET[ List_Table::QUERY_VAR ] = (string) $this->ids['about'];

		$vars = $this->table->filter_request(
			[
				'post_type' => 'page',
				'orderby'   => 'menu_order title',
			]
		);

		$this->assertSame( [ $this->ids['history'], $early, $this->ids['team'], $this->ids['leadership'] ], $vars['post__in'], 'Not level by level, where branches interleave.' );
	}

	public function test_a_sorted_list_keeps_its_sort(): void {
```

In `tests/phpunit/ListTableTest.php`, replace:

```php
		$this->assertStringContainsString( '<span class="hierarchy-guard-capped">Only the first 1 subpage is shown.</span>', $this->cell( $this->ids['about'] ) );
```

with:

```php
		$this->assertStringContainsString( '<span class="hierarchy-guard-capped">Only the 1 oldest subpage is shown.</span>', $this->cell( $this->ids['about'] ) );
```

In `tests/phpunit/SubtreeTest.php`, replace:

```php
	public function test_descendant_ids_walks_level_by_level_up_to_the_cap(): void {
		$all = Subtree::descendant_ids( $this->ids['company'], 'page', 10 );
		$this->assertSame( [ $this->ids['about'], $this->ids['history'], $this->ids['team'], $this->ids['leadership'] ], $all['ids'], 'Level by level, each in menu order then title.' );
		$this->assertFalse( $all['capped'] );

```

with:

```php
	public function test_descendant_ids_lists_a_subtree_in_tree_order_up_to_the_cap(): void {
		$team_b = $this->page( 'team-b', $this->ids['history'] );

		$all = Subtree::descendant_ids( $this->ids['company'], 'page', 10 );
		$this->assertSame( [ $this->ids['about'], $this->ids['history'], $team_b, $this->ids['team'], $this->ids['leadership'] ], $all['ids'], 'Each page followed by its subpages, siblings in menu order then title.' );
		$this->assertFalse( $all['capped'] );

		$this->assertSame( [ $this->ids['about'], $this->ids['history'], $this->ids['team'] ], Subtree::descendant_ids( $this->ids['company'], 'page', 3 )['ids'], 'Past the cap, the levels nearest the root stay.' );
```

In `tests/phpunit/SubtreeTest.php`, replace:

```php
		$exact = Subtree::descendant_ids( $this->ids['company'], 'page', 4 );
```

with:

```php
		$exact = Subtree::descendant_ids( $this->ids['company'], 'page', 5 );
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npm run test:php -- --filter 'ListTableTest|SubtreeTest'`

Expected: FAIL as below; every other test in these classes passes.

- `ListTableTest::test_the_filter_lists_the_subtree_in_paginated_rows`: The walk’s tree order, and not core’s tree mode, which loads every page.
- `ListTableTest::test_the_filter_lists_each_page_followed_by_its_own_subpages`: Not level by level, where branches interleave.
- `ListTableTest::test_past_the_cap_an_opened_tree_row_says_only_the_first_are_shown`: Failed asserting that '<span class="hierarchy-guard-cell" data-id="204" data-children="2" data-status="publish" data-level="1" data-parent="203" data-page="1" data-positi …
- `SubtreeTest::test_descendant_ids_lists_a_subtree_in_tree_order_up_to_the_cap`: Each page followed by its subpages, siblings in menu order then title.

Totals: `Tests: 54, Assertions: 134, Failures: 4.`

Run: `npx wp-scripts test-unit-js src/common/test/copy.test.js src/tree/test/tree.test.js`

Expected: FAIL:

- `× says past the cap that only the first are shown 3ms`
- `× says past the cap that only the first are shown 8ms`
- `FAIL  src/common/test/copy.test.js > the Tree view > says past the cap that only the first are shown`
- `FAIL  src/tree/test/tree.test.js > the tree grid > says past the cap that only the first are shown`

Totals: `Tests  2 failed | 80 passed (82)`

Mutant, to run once the tests pass, which shows `test_descendant_ids_lists_a_subtree_in_tree_order_up_to_the_cap` pins its behaviour: in `includes/HierarchyGuard/Subtree.php`, replace

```php
$stack = array_merge( $stack, array_reverse( $kids[ $id ] ?? [] ) );
```

with:

```php
$stack = array_merge( array_reverse( $kids[ $id ] ?? [] ), $stack );
```

Expected: the test fails, as it did on the prototype:

```
SubtreeTest::test_descendant_ids_lists_a_subtree_in_tree_order_up_to_the_cap
Each page followed by its subpages, siblings in menu order then title.
Tests: 1, Assertions: 1, Failures: 1.
```

Then undo the mutant (`git checkout -- includes/HierarchyGuard/Subtree.php`). Never commit it.

- [ ] **Step 3: Implement**

In `includes/HierarchyGuard/Admin/List_Table.php`, replace:

```php
					_n( 'Only the first %s subpage is shown.', 'Only the first %s subpages are shown.', $cap, 'hierarchy-guard' ),
```

with:

```php
					_n( 'Only the %s oldest subpage is shown.', 'Only the %s oldest subpages are shown.', $cap, 'hierarchy-guard' ),
```

In `includes/HierarchyGuard/Admin/List_Table.php`, replace:

```php
	 * Narrows the list query to the filtered page's subtree, and asks for a
	 * sort order so core renders normal paginated rows rather than loading
	 * every page for its indented tree.
```

with:

```php
	 * Narrows the list query to the filtered page's subtree. Unless a column
	 * is sorted, the rows come in the walk's tree order, each page followed by
	 * its own subpages; that sort also makes core render normal paginated
	 * rows rather than loading every page for its indented tree.
```

In `includes/HierarchyGuard/Admin/List_Table.php`, replace:

```php
			$vars['orderby'] = [
				'menu_order' => 'ASC',
				'title'      => 'ASC',
			];
			unset( $vars['fields'], $vars['posts_per_archive_page'] );
```

with:

```php
			$vars['orderby'] = 'post__in';
			unset( $vars['order'], $vars['fields'], $vars['posts_per_archive_page'] );
```

In `includes/HierarchyGuard/Overview.php`, replace:

```php
		// The first $cap children in menu order, read without sorting every child of a large parent.
```

with:

```php
		// The $cap oldest children, in menu order: read without sorting every child of a large parent.
```

In `includes/HierarchyGuard/Subtree.php`, replace:

```php
	 * order and stops at the limit; the second sorts only that bounded set. No
	 * query reads every child of a large parent.
```

with:

```php
	 * order and stops at the limit, so past it the set is the oldest
	 * children, by ID; the second sorts only that bounded set. No query reads
	 * every child of a large parent.
```

In `includes/HierarchyGuard/Subtree.php`, replace:

```php
	 * Live descendants level by level, stopping at the cap.
```

with:

```php
	 * Live descendants in the order a tree lists them, stopping at the cap:
	 * each page followed by its own subpages, siblings in menu order then
	 * title. The levels are read one query each, nearest the root first, so
	 * past the cap the levels nearest the root are the ones kept.
```

In `includes/HierarchyGuard/Subtree.php`, replace:

```php
	 * @return array{ids:int[],capped:bool} IDs root-near first, each level in menu order; capped when more exist.
	 */
	public static function descendant_ids( int $root_id, string $post_type, int $cap ): array {
		$cap   = max( 1, $cap );
		$ids   = [];
```

with:

```php
	 * @return array{ids:int[],capped:bool} IDs in tree order; capped when more exist.
	 */
	public static function descendant_ids( int $root_id, string $post_type, int $cap ): array {
		$cap   = max( 1, $cap );
		$rows  = [];
```

In `includes/HierarchyGuard/Subtree.php`, replace:

```php
			$level  = array_map( 'intval', array_column( self::ordered_children( $level, $post_type, $cap + 1 - $found ), 'ID' ) );
			$ids    = array_merge( $ids, $level );
			$found += count( $level );
		}

		return [
			'ids'    => array_slice( $ids, 0, $cap ),
```

with:

```php
			$read   = self::ordered_children( $level, $post_type, $cap + 1 - $found );
			$rows   = array_merge( $rows, $read );
			$found += count( $read );
			$level  = array_map( 'intval', array_column( $read, 'ID' ) );
		}

		$kids = [];
		foreach ( array_slice( $rows, 0, $cap ) as $row ) {
			$kids[ (int) $row['post_parent'] ][] = (int) $row['ID'];
		}
		$ids   = [];
		$stack = array_reverse( $kids[ $root_id ] ?? [] );
		while ( [] !== $stack ) {
			$id    = array_pop( $stack );
			$ids[] = $id;
			$stack = array_merge( $stack, array_reverse( $kids[ $id ] ?? [] ) );
		}

		return [
			'ids'    => $ids,
```

In `src/common/copy.js`, replace:

```js
 * The Tree view's last row past the cap: "Only the first 1,000 subpages are shown."
```

with:

```js
 * The Tree view's last row past the cap: "Only the 1,000 oldest subpages are shown.",
 * in menu order, as the route reads a large parent's subpages without sorting every one.
```

In `src/common/copy.js`, replace:

```js
			'Only the first %s subpage is shown.',
			'Only the first %s subpages are shown.',
```

with:

```js
			'Only the %s oldest subpage is shown.',
			'Only the %s oldest subpages are shown.',
```

In `README.md`, replace:

```markdown
- **Pages list.** A Hierarchy column shows each page's parent and its number of subpages. The number, and the "View subpages" row action, narrow the list to that page's subtree; the heading then says which subtree is shown, with each ancestor one click away. Large subtrees list their first 1,000 pages.
- **Tree view.** "Tree" in the list's views row shows the top-level pages as the list pages them, by the screen's Number of items. A page with subpages that the user may edit has a toggle that opens them below it, indented, 50 at a time with "Show more", up to the first 1,000; each level opens the same way, and "Collapse all" closes them. A page whose subpages the user can't view stays open with its count and says so. If subpages are added, deleted or reordered while a page is open, a later "Show more" can skip or overlap them (a page is never listed twice); collapse and reopen the page to see them as they are. A column sort and the date filter apply to the top level only; opened subpages keep menu order then title. Each row has the list's columns and its Edit, Trash, View and "View subpages" actions; trashing from the tree asks as the list does and comes back to the same place. Arrow keys move between rows and open and close them. A search in the Tree view lists the matching pages at any level with each one's parent pages, and "Back to the tree" returns to it. Without JavaScript a toggle reloads the view opened at that page, with only the path down to it shown at the levels above. The tree has no Quick Edit, checkboxes or bulk actions; use the list for those. A page whose parent is in the Trash, missing, or a private page the user can't read is not in the tree, because the tree starts from the top-level pages; the list and a search in the Tree view still show it.
```

with:

```markdown
- **Pages list.** A Hierarchy column shows each page's parent and its number of subpages. The number, and the "View subpages" row action, narrow the list to that page's subtree; the heading then says which subtree is shown, with each ancestor one click away. The subtree is listed as the Tree view would show it, each page followed by its own subpages; a large subtree lists 1,000 of its pages, the levels nearest the top first.
- **Tree view.** "Tree" in the list's views row shows the top-level pages as the list pages them, by the screen's Number of items. A page with subpages that the user may edit has a toggle that opens them below it, indented, 50 at a time with "Show more", up to 1,000 (past that, the 1,000 oldest); each level opens the same way, and "Collapse all" closes them. A page whose subpages the user can't view stays open with its count and says so. If subpages are added, deleted or reordered while a page is open, a later "Show more" can skip or overlap them (a page is never listed twice); collapse and reopen the page to see them as they are. A column sort and the date filter apply to the top level only; opened subpages keep menu order then title. Each row has the list's columns and its Edit, Trash, View and "View subpages" actions; trashing from the tree asks as the list does and comes back to the same place. Arrow keys move between rows and open and close them. A search in the Tree view lists the matching pages at any level with each one's parent pages, and "Back to the tree" returns to it. Without JavaScript a toggle reloads the view opened at that page, with only the path down to it shown at the levels above. The tree has no Quick Edit, checkboxes or bulk actions; use the list for those. A page whose parent is in the Trash, missing, or a private page the user can't read is not in the tree, because the tree starts from the top-level pages; the list and a search in the Tree view still show it.
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `npm run test:php -- --filter 'ListTableTest|SubtreeTest'`

Expected: `OK`.

Run: `npx wp-scripts test-unit-js src/common/test/copy.test.js src/tree/test/tree.test.js`

Expected: every test passes.

Run: `npm run test:php`, `npm run test:php:multisite` and `npm run test:js`

Expected: `Tests: 1005, Assertions: 4478, Skipped: 6.` (single site), `OK (1005 tests, 4518 assertions)` (multisite), and Vitest `Tests 176 passed (176)`.

- [ ] **Step 5: Lint and build**

Run: `composer lint && npm run lint:js && npm run lint:style && npm run build`

Expected: every command exits 0.

- [ ] **Step 6: Commit**

```bash
git add README.md includes/HierarchyGuard/Admin/List_Table.php includes/HierarchyGuard/Overview.php includes/HierarchyGuard/Subtree.php src/common/copy.js src/common/test/copy.test.js src/tree/test/tree.test.js tests/phpunit/ListTableTest.php tests/phpunit/SubtreeTest.php
git commit -m "fix: list a subtree in tree order, and say a large parent's capped subpages are its oldest"
```

---

### Task 11: Deleting a page reads its children without a sort or query filters, and its entries once

**Findings:** 9.4, 11.2, 11.3, 5.5

- `Subtree::children_in_any_status( $parent, $type, $limit )` reads child IDs with one direct, prepared query: no `ORDER BY`, no other plugin's query filters, any status. `Change_Listener::has_children()` asks for one; `record_on_children()` asks for up to the cap and primes posts and meta in chunks of 100 instead of loading 1,000 full posts at once.
- `Ledger::has_entries()` reuses the rows read for the delete, so the duplicate `LIKE` read is gone.

**Files:**
- Modify: `includes/HierarchyGuard/Change_Listener.php`
- Modify: `includes/HierarchyGuard/Ledger.php`
- Modify: `includes/HierarchyGuard/Subtree.php`
- Test (modify): `tests/phpunit/ChangeListenerTest.php`

**Interfaces:**
- Produces: `Subtree::children_in_any_status( int $parent_id, string $post_type, int $limit ): int[]`.

- [ ] **Step 1: Write the failing tests**

In `tests/phpunit/ChangeListenerTest.php`, replace:

```php

	public function test_deleting_a_subpage_trashed_with_its_parent_records_nothing(): void {
```

with:

```php

	public function test_deleting_a_page_reads_its_entries_once_and_its_children_without_a_sort(): void {
		global $wpdb;
		$queries = [];
		$keep    = static function ( string $sql ) use ( &$queries ): string {
			$queries[] = $sql;
			return $sql;
		};
		add_filter( 'query', $keep );

		wp_delete_post( $this->team, true );

		remove_filter( 'query', $keep );
		$entries  = array_filter( $queries, fn( string $sql ): bool => str_contains( $sql, "post_id = {$this->team} AND meta_key LIKE" ) );
		$children = array_filter( $queries, fn( string $sql ): bool => str_contains( $sql, "post_parent = {$this->team}" ) );
		$this->assertCount( 1, $entries, 'begin_delete() reads the entries; has_entries() reuses that read.' );
		$this->assertNotEmpty( $children );
		foreach ( $children as $sql ) {
			$this->assertStringNotContainsString( 'post_date', $sql, 'No query sorts every child to find one.' );
			$this->assertStringContainsString( "FROM {$wpdb->posts}", $sql );
		}
	}

	public function test_a_subpage_trashed_with_its_parent_and_deleted_for_good_records_each_published_subpage_past_a_hundred(): void {
		$kids = [];
		for ( $i = 1; $i <= 120; $i++ ) {
			$kids[] = self::factory()->post->create(
				[
					'post_type'   => 'page',
					'post_name'   => "kid-{$i}",
					'post_parent' => $this->team,
				]
			);
		}
		wp_trash_post( $this->team );
		Markers::add( $this->team, Markers::trashed_key( $this->about ), '1' );

		wp_delete_post( $this->team, true );

		$this->assertSame( [ 'company/about/team/kid-120' ], $this->ledger->paths_for( end( $kids ) ) );
		$this->assertSame( [ 'company/about/team/kid-1' ], $this->ledger->paths_for( $kids[0] ) );
	}

	public function test_deleting_a_subpage_trashed_with_its_parent_records_nothing(): void {
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npm run test:php -- --filter 'ChangeListenerTest'`

Expected: FAIL as below; every other test in these classes passes.

- `ChangeListenerTest::test_deleting_a_page_reads_its_entries_once_and_its_children_without_a_sort`: begin_delete() reads the entries; has_entries() reuses that read.

Totals: `Tests: 40, Assertions: 109, Failures: 1.`

Mutant, to run once the tests pass, which shows `test_deleting_a_page_reads_its_entries_once_and_its_children_without_a_sort` pins its behaviour: in `includes/HierarchyGuard/Subtree.php`, replace

```php
post_type = %s ORDER BY ID ASC LIMIT %d", $parent_id
```

with:

```php
post_type = %s ORDER BY post_date DESC LIMIT %d", $parent_id
```

Expected: the test fails, as it did on the prototype:

```
ChangeListenerTest::test_deleting_a_page_reads_its_entries_once_and_its_children_without_a_sort
No query sorts every child to find one.
Tests: 1, Assertions: 3, Failures: 1.
```

Then undo the mutant (`git checkout -- includes/HierarchyGuard/Subtree.php`). Never commit it.

- [ ] **Step 3: Implement**

In `includes/HierarchyGuard/Change_Listener.php`, replace:

```php
		$children = get_posts(
			[
				'post_parent'            => $post_id,
				'post_type'              => $post_type,
				'post_status'            => array_values( array_diff( array_keys( get_post_stati() ), [ 'auto-draft', 'inherit' ] ) ),
				'posts_per_page'         => Subtree::count_cap(),
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
			]
		);

		Ledger::deferred(
			function () use ( $children ): void {
				foreach ( $children as $child ) {
					$was = 'trash' === $child->post_status ? (string) get_post_meta( $child->ID, '_wp_trash_meta_status', true ) : (string) $child->post_status;
					if ( 'publish' === $was ) {
						$this->ledger->record( (int) $child->ID, Paths::untrashed_uri( $child ) );
```

with:

```php
		$ids = Subtree::children_in_any_status( $post_id, $post_type, Subtree::count_cap() );

		Ledger::deferred(
			function () use ( $ids ): void {
				// A hundred posts and their meta at a time, never the whole cap at once.
				foreach ( array_chunk( $ids, 100 ) as $chunk ) {
					_prime_post_caches( $chunk, false, true );
					foreach ( $chunk as $id ) {
						$child = get_post( $id );
						if ( ! $child instanceof WP_Post ) {
							continue;
						}
						$was = 'trash' === $child->post_status ? (string) get_post_meta( $id, '_wp_trash_meta_status', true ) : (string) $child->post_status;
						if ( 'publish' === $was ) {
							$this->ledger->record( $id, Paths::untrashed_uri( $child ) );
						}
```

In `includes/HierarchyGuard/Change_Listener.php`, replace:

```php
	 * core re-parents trashed and auto-draft children too. One indexed query.
```

with:

```php
	 * core re-parents trashed and auto-draft children too.
```

In `includes/HierarchyGuard/Change_Listener.php`, replace:

```php
		$children = get_posts(
			[
				'post_parent'            => $post_id,
				'post_type'              => $post_type,
				'post_status'            => array_keys( get_post_stati() ),
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			]
		);

		return ! empty( $children );
```

with:

```php
		return [] !== Subtree::children_in_any_status( $post_id, $post_type, 1 );
```

In `includes/HierarchyGuard/Ledger.php`, replace:

```php
	 * Whether a post holds any ledger entry.
```

with:

```php
	 * Whether a post holds any ledger entry. For a post being deleted for
	 * good, begin_delete() has read its entries already.
```

In `includes/HierarchyGuard/Ledger.php`, replace:

```php

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Range on the post_id index that stops at the first entry; once per post trashed, restored or deleted.
```

with:

```php

		if ( isset( self::$deleting[ $post_id ] ) ) {
			return [] !== self::$deleting[ $post_id ];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Range on the post_id index that stops at the first entry; once per post trashed, restored or deleted.
```

In `includes/HierarchyGuard/Subtree.php`, replace:

```php
	}

	/**
	 * Number of live direct children, stopping at a cap.
```

with:

```php
	}

	/**
	 * Direct children in any status, lowest ID first, as core's own
	 * re-parent on a delete finds them: one read of the post_parent index
	 * that stops at the limit, never sorting every child, and no query
	 * filter of another plugin narrows it.
	 *
	 * @param int    $parent_id Parent post ID.
	 * @param string $post_type Post type name.
	 * @param int    $limit     Maximum IDs to return.
	 * @return int[]
	 */
	public static function children_in_any_status( int $parent_id, string $post_type, int $limit ): array {
		global $wpdb;

		if ( $limit < 1 ) {
			return [];
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Index range on post_parent, bounded by LIMIT; once per post deleted for good.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = %s ORDER BY ID ASC LIMIT %d", $parent_id, $post_type, $limit ) );

		return array_map( 'intval', $ids );
	}

	/**
	 * Number of live direct children, stopping at a cap.
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `npm run test:php -- --filter 'ChangeListenerTest'`

Expected: `OK`.

Run: `npm run test:php`, `npm run test:php:multisite` and `npm run test:js`

Expected: `Tests: 1007, Assertions: 4488, Skipped: 6.` (single site), `OK (1007 tests, 4528 assertions)` (multisite), and Vitest `Tests 176 passed (176)`.

- [ ] **Step 5: Lint and build**

Run: `composer lint && npm run lint:js && npm run lint:style && npm run build`

Expected: every command exits 0.

- [ ] **Step 6: Commit**

```bash
git add includes/HierarchyGuard/Change_Listener.php includes/HierarchyGuard/Ledger.php includes/HierarchyGuard/Subtree.php tests/phpunit/ChangeListenerTest.php
git commit -m "perf: read a deleted page's children without a sort or query filters, and its entries once"
```

---

### Task 12: Remove the unused undo path, the suspend flag, `Change_Log::forget()` and `Resolver::MAX_ROUNDS`

**Findings:** 2.9, 3.9, 4.2

- Undo goes through core's own `wp_untrash_post_set_previous_status`, so `Trash_Handler::undo()`, its `undo_status` filter, `$undo_ids` and `title()` never ran, and shipped four strings. `Change_Listener::suspend()`/`is_suspended()`, `Change_Log::forget()` and `Resolver::MAX_ROUNDS` had no caller. All removed with their tests; the Undo tests now drive core's callback.

**Files:**
- Modify: `includes/HierarchyGuard/Change_Listener.php`
- Modify: `includes/HierarchyGuard/Redirects/Resolver.php`
- Modify: `includes/HierarchyGuard/Trash/Change_Log.php`
- Modify: `includes/HierarchyGuard/Trash/Trash_Handler.php`
- Test (modify): `tests/phpunit/ChangeListenerTest.php`
- Test (modify): `tests/phpunit/ChangeLogTest.php`
- Test (modify): `tests/phpunit/TrashHandlerTest.php`

**Interfaces:**
- Removes: `Trash_Handler::undo()`, `Trash_Handler::undo_status()`, `Change_Listener::suspend()`, `Change_Listener::is_suspended()`, `Change_Log::forget()`, `Resolver::MAX_ROUNDS`. Nothing in a later task uses them.

- [ ] **Step 1: Write the failing tests**

In `tests/phpunit/ChangeListenerTest.php`, replace:

```php
		Post_Types::reset();
		Change_Listener::suspend( false );
```

with:

```php
		Post_Types::reset();
```

In `tests/phpunit/ChangeListenerTest.php`, replace:

```php
	public function tear_down(): void {
		Change_Listener::suspend( false );
```

with:

```php
	public function tear_down(): void {
```

In `tests/phpunit/ChangeListenerTest.php`, replace:

```php
		Change_Listener::suspend( true );
		wp_update_post(
			[
				'ID'        => $this->team,
				'post_name' => 'crew',
			]
		);
		Change_Listener::suspend( false );

		$this->assertNull( $this->log->latest( $user ), 'A draft has no URL, a title is no URL, and a suspended listener records nothing.' );
```

with:

```php

		$this->assertNull( $this->log->latest( $user ), 'A draft has no URL, and a title is no URL.' );
```

In `tests/phpunit/ChangeListenerTest.php`, replace:

```php
		$this->assertSame( [], $this->ledger->paths_for( $this->about ) );
	}

	public function test_suspended_listener_records_nothing(): void {
		Change_Listener::suspend( true );
		wp_update_post(
			[
				'ID'        => $this->about,
				'post_name' => 'company-info',
			]
		);

		$this->assertTrue( Change_Listener::is_suspended() );
		$this->assertSame( [], $this->ledger->paths_for( $this->about ) );
```

with:

```php
		$this->assertSame( [], $this->ledger->paths_for( $this->about ) );
```

In `tests/phpunit/ChangeLogTest.php`, replace:

```php
		} finally {
			$this->log->forget( $this->admin );
```

with:

```php
		} finally {
```

In `tests/phpunit/ChangeLogTest.php`, replace:

```php

	public function test_forgetting_drops_the_change_waiting_to_be_written(): void {
		$this->log->record( $this->admin, 'trash', 'page', $this->entry( 12, [] ) );

		$this->log->forget( $this->admin );
		Change_Log::reset_request();

		$this->assertNull( $this->log->latest( $this->admin ) );
		$this->assertFalse( get_transient( Change_Log::key( $this->admin ) ) );
	}

```

with:

```php

```

In `tests/phpunit/ChangeLogTest.php`, replace:

```php
	public function test_mark_shown_and_forget(): void {
```

with:

```php
	public function test_mark_shown(): void {
```

In `tests/phpunit/ChangeLogTest.php`, replace:

```php
		$this->assertTrue( $this->log->latest( $this->admin )['shown'] );

		$this->log->forget( $this->admin );
		$this->assertNull( $this->log->latest( $this->admin ) );
```

with:

```php
		$this->assertTrue( $this->log->latest( $this->admin )['shown'] );
```

In `tests/phpunit/TrashHandlerTest.php`, replace:

```php
use HierarchyGuard\Trash\Trash_Handler;
use WP_Error;
```

with:

```php
use HierarchyGuard\Trash\Trash_Handler;
```

In `tests/phpunit/TrashHandlerTest.php`, replace:

```php
	}

	/**
	 * Uses a fixed policy for the rest of the test.
```

with:

```php
	}

	/**
	 * Restores a page as core's Undo link does: edit.php gives it the status
	 * it had before the trash.
	 *
	 * @param int $post_id Trashed page.
	 */
	private function core_undo( int $post_id ): void {
		add_filter( 'wp_untrash_post_status', 'wp_untrash_post_set_previous_status', 10, 3 );
		try {
			wp_untrash_post( $post_id );
		} finally {
			remove_filter( 'wp_untrash_post_status', 'wp_untrash_post_set_previous_status', 10 );
		}
	}

	/**
	 * Uses a fixed policy for the rest of the test.
```

In `tests/phpunit/TrashHandlerTest.php`, replace:

```php
			'deleted_post'           => 10,
			'wp_untrash_post_status' => 20,
```

with:

```php
			'deleted_post'           => 10,
```

In `tests/phpunit/TrashHandlerTest.php`, replace:

```php
			$this->assertTrue( $found, $hook );
		}
	}
```

with:

```php
			$this->assertTrue( $found, $hook );
		}
		foreach ( $wp_filter['wp_untrash_post_status']->callbacks ?? [] as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$this->assertFalse( is_array( $callback['function'] ) && $callback['function'][0] instanceof Trash_Handler, 'Undo gets its status from core.' );
			}
		}
	}
```

In `tests/phpunit/TrashHandlerTest.php`, replace:

```php
	public function test_trash_children_then_undo_restores_previous_statuses(): void {
```

with:

```php
	public function test_trash_children_then_core_s_undo_restores_previous_statuses(): void {
```

In `tests/phpunit/TrashHandlerTest.php`, replace:

```php
		$this->assertSame( 'trash', get_post_status( $plans ) );

		$this->assertTrue( $this->handler()->undo( $this->ids['about'] ) );

```

with:

```php
		$this->assertSame( 'trash', get_post_status( $plans ) );

		$this->core_undo( $this->ids['about'] );

```

In `tests/phpunit/TrashHandlerTest.php`, replace:

```php
	public function test_undo_restores_the_parent_with_its_status_and_brings_subpages_back(): void {
		wp_trash_post( $this->ids['about'] );

		$this->assertTrue( $this->handler()->undo( $this->ids['about'] ) );
```

with:

```php
	public function test_core_s_undo_restores_the_parent_with_its_status_and_brings_subpages_back(): void {
		wp_trash_post( $this->ids['about'] );

		$this->core_undo( $this->ids['about'] );
```

In `tests/phpunit/TrashHandlerTest.php`, replace:

```php

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

	public function test_undo_names_a_page_titled_0_as_core_s_list_does(): void {
		global $wpdb;
		$wpdb->update( $wpdb->posts, [ 'post_title' => '0' ], [ 'ID' => $this->ids['about'] ] );
		clean_post_cache( $this->ids['about'] );

		$result = $this->handler()->undo( $this->ids['about'] );

		$this->assertSame( '“(no title)” is no longer in the trash.', $result->get_error_message(), 'As _draft_or_post_title() names it.' );
	}

```

with:

```php

```

In `tests/phpunit/TrashHandlerTest.php`, replace:

```php
		$this->handler()->reset_request_budget();
		$this->assertTrue( $this->handler()->undo( $this->ids['about'] ) );
```

with:

```php
		$this->handler()->reset_request_budget();
		$this->core_undo( $this->ids['about'] );
```

In `tests/phpunit/TrashHandlerTest.php`, replace:

```php
		$token = $this->queue->lock( $this->ids['about'] );

		$this->handler()->undo( $this->ids['about'] );

		$this->assertSame( Operations::TYPE_RESTORE, $this->queue->offered( $this->ids['about'] )['type'] );
```

with:

```php
		$token = $this->queue->lock( $this->ids['about'] );

		$this->core_undo( $this->ids['about'] );

		$this->assertSame( Operations::TYPE_RESTORE, $this->queue->offered( $this->ids['about'] )['type'] );
```

In `tests/phpunit/TrashHandlerTest.php`, replace:

```php

		$this->handler()->undo( $this->ids['about'] );
		$this->assertSame( Operations::TYPE_RESTORE, $this->queue->offered( $this->ids['about'] )['type'], 'Precondition: the restore is offered to the run.' );
```

with:

```php

		$this->core_undo( $this->ids['about'] );
		$this->assertSame( Operations::TYPE_RESTORE, $this->queue->offered( $this->ids['about'] )['type'], 'Precondition: the restore is offered to the run.' );
```

In `tests/phpunit/TrashHandlerTest.php`, replace:

```php
		$this->handler()->undo( $this->ids['about'] );
```

with:

```php
		$this->core_undo( $this->ids['about'] );
```

In `tests/phpunit/TrashHandlerTest.php`, replace:

```php
		$this->assertTrue( $this->handler()->undo( $this->ids['about'] ) );
```

with:

```php
		$this->core_undo( $this->ids['about'] );
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npm run test:php -- --filter 'ChangeListenerTest|ChangeLogTest|TrashHandlerTest'`

Expected: FAIL as below; every other test in these classes passes.

- `TrashHandlerTest::test_the_handler_is_registered_by_the_plugin`: Undo gets its status from core.

Totals: `Tests: 112, Assertions: 1471, Failures: 1, Skipped: 3.`

Mutant, to run once the tests pass, which shows `test_the_handler_is_registered_by_the_plugin` pins its behaviour: in `includes/HierarchyGuard/Trash/Trash_Handler.php`, replace

```php
		add_action( 'shutdown', [ $this, 'flush_pending' ] );
```

with:

```php
		add_action( 'shutdown', [ $this, 'flush_pending' ] );
		add_filter( 'wp_untrash_post_status', [ $this, 'flush_pending' ], 20 );
```

Expected: the test fails, as it did on the prototype:

```
TrashHandlerTest::test_the_handler_is_registered_by_the_plugin
Undo gets its status from core.
Tests: 1, Assertions: 8, Failures: 1.
```

Then undo the mutant (`git checkout -- includes/HierarchyGuard/Trash/Trash_Handler.php`). Never commit it.

- [ ] **Step 3: Implement**

In `includes/HierarchyGuard/Change_Listener.php`, replace:

```php

	/**
	 * When true, nothing is recorded: for code that changes many posts and records their paths itself.
	 *
	 * @var bool
	 */
	private static bool $suspended = false;

```

with:

```php

```

In `includes/HierarchyGuard/Change_Listener.php`, replace:

```php

	/**
	 * Suspends or resumes recording.
	 *
	 * @param bool $on True to suspend.
	 */
	public static function suspend( bool $on ): void {
		self::$suspended = $on;
	}

	/**
	 * Whether recording is suspended.
	 *
	 * @return bool
	 */
	public static function is_suspended(): bool {
		return self::$suspended;
	}

```

with:

```php

```

In `includes/HierarchyGuard/Change_Listener.php`, replace:

```php
	public function on_post_updated( int $post_id, WP_Post $after, WP_Post $before ): void {
		if ( self::$suspended ) {
			return;
		}
```

with:

```php
	public function on_post_updated( int $post_id, WP_Post $after, WP_Post $before ): void {
```

In `includes/HierarchyGuard/Change_Listener.php`, replace:

```php
		}
		if ( self::$suspended ) {
			return;
		}
```

with:

```php
		}
```

In `includes/HierarchyGuard/Change_Listener.php`, replace:

```php
		if ( 'publish' === $new_status && ! self::$suspended ) {
```

with:

```php
		if ( 'publish' === $new_status ) {
```

In `includes/HierarchyGuard/Redirects/Resolver.php`, replace:

```php

	public const MAX_ROUNDS = Rules::MAX_ROUNDS;

```

with:

```php

```

In `includes/HierarchyGuard/Trash/Change_Log.php`, replace:

```php

	/**
	 * Clears a user's summary.
	 *
	 * @param int $user_id User ID.
	 */
	public function forget( int $user_id ): void {
		unset( self::$pending[ get_current_blog_id() ][ $user_id ] );
		delete_transient( self::key( $user_id ) );
	}

```

with:

```php

```

In `includes/HierarchyGuard/Trash/Trash_Handler.php`, replace:

```php
use HierarchyGuard\Subtree;
use WP_Error;
```

with:

```php
use HierarchyGuard\Subtree;
```

In `includes/HierarchyGuard/Trash/Trash_Handler.php`, replace:

```php

	/**
	 * Pages undo() is restoring right now.
	 *
	 * @var array<int,true>
	 */
	private array $undo_ids = [];

```

with:

```php

```

In `includes/HierarchyGuard/Trash/Trash_Handler.php`, replace:

```php
		add_action( 'deleted_post', [ $this, 'on_deleted' ], 10, 2 );
		add_filter( 'wp_untrash_post_status', [ $this, 'undo_status' ], 20, 3 );
```

with:

```php
		add_action( 'deleted_post', [ $this, 'on_deleted' ], 10, 2 );
```

In `includes/HierarchyGuard/Trash/Trash_Handler.php`, replace:

```php

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

```

with:

```php

```

In `includes/HierarchyGuard/Trash/Trash_Handler.php`, replace:

```php
	}

	/**
	 * A post's title for a message.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	private static function title( WP_Post $post ): string {
		$title = get_the_title( $post );
		// As _draft_or_post_title(): a title of "0" is no title either.
		return ! empty( $title ) ? $title : __( '(no title)', 'hierarchy-guard' );
	}
```

with:

```php
	}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `npm run test:php -- --filter 'ChangeListenerTest|ChangeLogTest|TrashHandlerTest'`

Expected: `OK`.

Run: `npm run test:php`, `npm run test:php:multisite` and `npm run test:js`

Expected: `Tests: 1003, Assertions: 4471, Skipped: 6.` (single site), `OK (1003 tests, 4511 assertions)` (multisite), and Vitest `Tests 176 passed (176)`.

- [ ] **Step 5: Lint and build**

Run: `composer lint && npm run lint:js && npm run lint:style && npm run build`

Expected: every command exits 0.

- [ ] **Step 6: Commit**

```bash
git add includes/HierarchyGuard/Change_Listener.php includes/HierarchyGuard/Redirects/Resolver.php includes/HierarchyGuard/Trash/Change_Log.php includes/HierarchyGuard/Trash/Trash_Handler.php tests/phpunit/ChangeListenerTest.php tests/phpunit/ChangeLogTest.php tests/phpunit/TrashHandlerTest.php
git commit -m "refactor: remove the unused undo path, the listener's suspend flag, Change_Log::forget() and Resolver::MAX_ROUNDS"
```

---

### Task 13: Bulk Edit warns, a restored parent says it is a draft, the Trash view says what a restore brings back, and each trash policy is described

**Findings:** 6.2, 6.3, 7.2, 7.3, 7.4, 7.5, and the plan audits' U-1, U-2, U-3, U-12, U-13 (7.2), U-14 (one query) and U-5 (in part)

- Bulk Edit (6.3): choosing a parent for selected pages, some published with subpages, shows "Saving will change the URLs of the selected pages and of the subpages under %s of them." and the same next step as Quick Edit, above Update, counted from the rows' Hierarchy cells, so it costs no request. It counts again when core's × button takes a page out (core removes the line before the click reaches the document, so the button's own class is what still matches) and when a row is ticked.
- Quick Edit (7.2): core fades the saved row in for 400 ms and then speaks "Changes saved.", which replaced the plugin's sentence. The plugin now waits for the row's animation (`jQuery( row ).promise()`) and speaks one message, core's words first. The announcement and the warning text move to `src/list-table/quick-edit-words.js`, which has no JSX, so Vitest can load it.
- Restore (6.2, the simple fix): when subpages come back under a page core restored as a draft, the notice adds "It’s a draft now, so visitors can’t see it until you publish it." The subpages' URLs work under a draft parent; only its own doesn't. No status is changed for the user.
- Trash view (7.3): a trashed page whose trash moved or trashed subpages says "Restoring it brings back %s subpages.", from one capped `UNION ALL` count for the screen (`Markers::counts()`), pinned by counting that query for three trashed rows.
- Settings (7.4, 7.5): each default-policy radio has the trash dialog's description, tied with `aria-describedby`, indented under its label with space before the next option (layout only: a margin and an indent); the help asks to turn built-in redirects on only while they are off.

**Files:**
- Modify: `includes/HierarchyGuard/Admin/List_Table.php`
- Modify: `includes/HierarchyGuard/Admin/Notices.php`
- Modify: `includes/HierarchyGuard/Admin/Settings_Page.php`
- Modify: `includes/HierarchyGuard/Trash/Markers.php`
- Modify: `src/common/copy.js`
- Create: `src/list-table/bulk-edit.js`
- Modify: `src/list-table/index.js`
- Create: `src/list-table/quick-edit-words.js`
- Modify: `src/list-table/quick-edit.js`
- Test (modify): `src/common/test/copy.test.js`
- Test (create): `src/list-table/test/bulk-edit.test.js`
- Test (create): `src/list-table/test/quick-edit.test.js`
- Test (modify): `tests/phpunit/ListTableTest.php`
- Test (modify): `tests/phpunit/NoticesTest.php`
- Test (modify): `tests/phpunit/SettingsPageTest.php`

**Interfaces:**
- Produces: `Markers::counts( int[] $root_ids, int $cap ): array<int,int>`.
- Produces (JS): in `src/common/copy.js`, `afterSave( serving ): string` and `bulkEditWarning( pages, serving ): string`; `src/list-table/bulk-edit.js` exports `updateBulkWarning( doc )` and default `bindBulkEdit( doc )`, called from `src/list-table/index.js`; `src/list-table/quick-edit-words.js` exports `warningText( descendants )` and `speakAfterSave( postId, sentence )`.

- [ ] **Step 1: Write the failing tests**

In `src/common/test/copy.test.js`, replace:

```js
import {
	bulkSentence,
```

with:

```js
import {
	bulkEditWarning,
	bulkSentence,
```

In `src/common/test/copy.test.js`, replace:

```js

	it( 'composes the classic confirmation from the save warning', () => {
```

with:

```js

	it( 'warns in Bulk Edit with the same next step', () => {
		expect( bulkEditWarning( 2, false ) ).toBe(
			'Saving will change the URLs of the selected pages and of the subpages under 2 of them. You’ll get the redirects to add after saving.'
		);
		expect( bulkEditWarning( 1, true ) ).toBe(
			'Saving will change the URLs of the selected pages and of the subpages under 1 of them. The old URLs will redirect to the new ones.'
		);
	} );

	it( 'composes the classic confirmation from the save warning', () => {
```

Create `src/list-table/test/bulk-edit.test.js`:

```js
// @vitest-environment happy-dom
/**
 * The Bulk Edit warning before a new parent changes subpage URLs.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock( '@wordpress/a11y', () => ( { speak: vi.fn() } ) );

const { speak } = await import( '@wordpress/a11y' );
const { default: bindBulkEdit, updateBulkWarning } =
	await import( '../bulk-edit' );

/**
 * Core's list rows, each with its Hierarchy cell, and its Bulk Edit form.
 *
 * @param {Array<{id: number, children: number, status: string, checked: boolean}>} rows Rows.
 */
function render( rows ) {
	window.document.body.innerHTML = `
		<table><tbody id="the-list">
			<tr id="bulk-edit"><td>
				<ul id="bulk-titles">${ rows
					.filter( ( row ) => row.checked )
					.map(
						( row ) =>
							`<li class="ntdelitem"><button type="button" id="_${ row.id }" class="button-link ntdelbutton">×</button></li>`
					)
					.join( '' ) }</ul>
				<select name="post_parent"><option value="-1">— No Change —</option><option value="0">Main Page (no parent)</option><option value="7">Company</option></select>
				<div class="submit inline-edit-save"><input type="submit" id="bulk_edit" value="Update"></div>
			</td></tr>
			${ rows
				.map(
					( row ) =>
						`<tr id="post-${ row.id }"><th><input type="checkbox" name="post[]" value="${ row.id }"${ row.checked ? ' checked' : '' }></th><td><span class="hierarchy-guard-cell" data-id="${ row.id }" data-children="${ row.children }" data-status="${ row.status }"></span></td></tr>`
				)
				.join( '' ) }
		</tbody></table>`;
}

/**
 * Chooses a parent in the Bulk Edit form.
 *
 * @param {string} value Option value.
 */
function choose( value ) {
	window.document.querySelector( 'select[name="post_parent"]' ).value = value;
	updateBulkWarning( window.document );
}

const box = () => window.document.getElementById( 'hierarchy-guard-bulk-edit' );

describe( 'the Bulk Edit warning', () => {
	beforeEach( () => {
		speak.mockClear();
		render( [
			{ id: 1, children: 3, status: 'publish', checked: true },
			{ id: 2, children: 0, status: 'publish', checked: true },
			{ id: 3, children: 5, status: 'draft', checked: true },
			{ id: 4, children: 2, status: 'publish', checked: false },
			{ id: 5, children: 1, status: 'publish', checked: true },
		] );
	} );

	it( 'counts the selected published pages with subpages once a parent is chosen', () => {
		choose( '7' );

		expect( box().hidden ).toBe( false );
		expect( box().textContent ).toBe(
			'Saving will change the URLs of the selected pages and of the subpages under 2 of them. You’ll get the redirects to add after saving.'
		);
		expect(
			window.document
				.querySelector( 'select[name="post_parent"]' )
				.getAttribute( 'aria-describedby' )
		).toBe( 'hierarchy-guard-bulk-edit' );
		expect( speak ).toHaveBeenCalledTimes( 1 );
		expect(
			box().parentNode.classList.contains( 'inline-edit-save' )
		).toBe( true );
	} );

	it( 'says nothing while the parent is left unchanged, or no selected page has subpages', () => {
		choose( '-1' );
		expect( box() ).toBeNull();

		choose( '7' );
		choose( '-1' );
		expect( box().hidden ).toBe( true );

		window.document
			.querySelectorAll( 'input[name="post[]"]' )
			.forEach( ( input ) => {
				input.checked = '2' === input.value;
			} );
		choose( '0' );
		expect( box().hidden ).toBe( true );
	} );

	it( 'counts again when core removes a page from the selection with its × button', async () => {
		bindBulkEdit( window.document );
		choose( '7' );
		const button = window.document.getElementById( '_5' );
		// What core's own handler on the button does before the click reaches the document.
		button.addEventListener( 'click', () => {
			window.document.querySelector( 'input[value="5"]' ).checked = false;
			button.parentNode.remove();
		} );

		button.dispatchEvent(
			new window.MouseEvent( 'click', { bubbles: true } )
		);
		await new Promise( ( resolve ) => window.setTimeout( resolve, 0 ) );

		expect( box().textContent ).toContain( 'under 1 of them' );
	} );

	it( 'counts again when a row is ticked while Bulk Edit is open', () => {
		bindBulkEdit( window.document );
		choose( '7' );
		const row = window.document.querySelector( 'input[value="4"]' );
		row.checked = true;

		row.dispatchEvent( new window.Event( 'change', { bubbles: true } ) );

		expect( box().textContent ).toContain( 'under 3 of them' );
	} );

	it( 'speaks again only when the sentence changes', () => {
		choose( '7' );
		choose( '0' );
		expect( speak ).toHaveBeenCalledTimes( 1 );

		window.document.querySelector( 'input[value="5"]' ).checked = false;
		choose( '0' );
		expect( speak ).toHaveBeenCalledTimes( 2 );
		expect( box().textContent ).toContain( 'under 1 of them' );
	} );
} );
```

Create `src/list-table/test/quick-edit.test.js`:

```js
// @vitest-environment happy-dom
/**
 * Quick Edit's announcements.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock( '@wordpress/a11y', () => ( { speak: vi.fn() } ) );

const { speak } = await import( '@wordpress/a11y' );
const { speakAfterSave } = await import( '../quick-edit-words' );

describe( 'the announcement after a Quick Edit save', () => {
	let finish;

	beforeEach( () => {
		speak.mockClear();
		finish = null;
		// A saved row whose fade-in core is still running.
		window.jQuery = ( selector ) =>
			'#post-7' === selector
				? {
						length: 1,
						promise: () => ( {
							done: ( callback ) => {
								finish = callback;
							},
						} ),
					}
				: { length: 0 };
	} );

	afterEach( () => {
		delete window.jQuery;
	} );

	it( 'waits for core’s row to fade in, then says core’s words and the sentence once', () => {
		speakAfterSave( 7, '4 old URLs now redirect to their new pages.' );
		expect( speak ).not.toHaveBeenCalled();

		finish();

		expect( speak ).toHaveBeenCalledTimes( 1 );
		expect( speak ).toHaveBeenCalledWith(
			'Changes saved. 4 old URLs now redirect to their new pages.',
			'polite'
		);
	} );

	it( 'says it at once when the row is gone', () => {
		speakAfterSave( 8, 'Sentence.' );

		expect( speak ).toHaveBeenCalledWith(
			'Changes saved. Sentence.',
			'polite'
		);
	} );
} );
```

In `tests/phpunit/ListTableTest.php`, replace:

```php

	public function test_the_column_comes_after_the_title(): void {
```

with:

```php

	public function test_the_trash_view_says_how_many_subpages_a_restore_brings_back(): void {
		wp_trash_post( $this->ids['about'] );
		$this->screen_rows( [ $this->ids['about'] ], 'id=>parent' );
		$GLOBALS['wp_query']->set( 'post_status', 'trash' );

		$cell = $this->cell( $this->ids['about'] );

		$this->assertStringContainsString( 'Restoring it brings back 2 subpages.', $cell );
		$this->assertStringNotContainsString( 'Top level, no subpages', $cell );
	}

	public function test_the_trash_view_reads_the_screen_s_markers_in_one_query(): void {
		wp_trash_post( $this->ids['about'] );
		$other = $this->page( 'other' );
		$this->page( 'other-child', $other );
		wp_trash_post( $other );
		$third = $this->page( 'third' );
		$this->page( 'third-child', $third );
		wp_trash_post( $third );
		$this->screen_rows( [ $this->ids['about'], $other, $third ], 'id=>parent' );
		$GLOBALS['wp_query']->set( 'post_status', 'trash' );
		$reads = 0;
		$count = static function ( string $query ) use ( &$reads ): string {
			if ( str_contains( $query, '_hierarchy_guard_moved_' ) && str_starts_with( ltrim( $query ), '(' ) ) {
				++$reads;
			}
			return $query;
		};
		add_filter( 'query', $count );

		$this->cell( $this->ids['about'] );
		$this->assertStringContainsString( 'Restoring it brings back 1 subpage.', $this->cell( $other ) );
		$this->assertStringContainsString( 'Restoring it brings back 1 subpage.', $this->cell( $third ) );
		remove_filter( 'query', $count );

		$this->assertSame( 1, $reads, 'One query for the three trashed rows of the screen.' );
	}

	public function test_the_column_comes_after_the_title(): void {
```

In `tests/phpunit/NoticesTest.php`, replace:

```php

	public function test_the_fallback_sentence_agrees_with_the_cap(): void {
```

with:

```php

	public function test_a_restore_that_leaves_the_parent_a_draft_says_visitors_can_t_see_it_until_it_is_published(): void {
		$restored = $this->change( 'restore', [ $this->about( [ 'counts' => [ 'moved' => 2 ] ] ) ] );
		$this->assertSame( [ '2 subpages moved back under “About”.' ], Notices::sentences( $restored ), 'Undo brings it back published.' );

		wp_update_post(
			[
				'ID'          => $this->ids['about'],
				'post_status' => 'draft',
			]
		);

		$this->assertSame(
			[ '2 subpages moved back under “About”.', 'It’s a draft now, so visitors can’t see it until you publish it.' ],
			Notices::sentences( $restored ),
			'Core’s Restore brings it back as a draft.'
		);
	}

	public function test_the_fallback_sentence_agrees_with_the_cap(): void {
```

In `tests/phpunit/SettingsPageTest.php`, replace:

```php

	public function test_a_provider_without_a_name_gets_a_generic_sentence(): void {
```

with:

```php

	public function test_each_trash_policy_says_what_it_does_to_the_subpages(): void {
		ob_start();
		$this->page->render_trash_policy();
		$html = (string) ob_get_clean();

		foreach (
			[
				Settings::POLICY_REPARENT       => 'Their URLs change.',
				Settings::POLICY_TRASH_CHILDREN => 'Restoring their parent restores them with their original status.',
				Settings::POLICY_KEEP           => 'Their URLs will contain “__trashed” until you move them.',
			] as $policy => $description
		) {
			$this->assertStringContainsString( 'value="' . $policy . '"', $html );
			$this->assertMatchesRegularExpression( '/value="' . $policy . '"[^>]*aria-describedby="hierarchy-guard-policy-' . $policy . '"/', $html );
			// Each description sits with its own option, under the label's text.
			$this->assertMatchesRegularExpression( '#<div style="margin-bottom:12px"><label><input [^>]*value="' . $policy . '"[^>]*>[^<]*</label><p class="description" id="hierarchy-guard-policy-' . $policy . '" style="margin-top:0;margin-inline-start:24px">' . preg_quote( esc_html( $description ), '#' ) . '</p></div>#', $html );
		}
		$this->assertMatchesRegularExpression( '#</div><p class="description">[^<]+</p></fieldset>$#', $html, 'The help for the whole field comes after the last option\'s space.' );
	}

	public function test_the_redirects_help_asks_to_turn_them_on_only_while_they_are_off(): void {
		ob_start();
		$this->page->render_serve_redirects();
		$off = (string) ob_get_clean();
		update_option( Settings::OPTION, [ 'serve_redirects' => true ] );
		ob_start();
		$this->page->render_serve_redirects();
		$on = (string) ob_get_clean();

		$this->assertStringContainsString( 'Turn this on so old URLs reach their new pages.', $off );
		$this->assertStringNotContainsString( 'Turn this on', $on );
		$this->assertStringContainsString( 'No redirect plugin was found. URL changes are recorded either way.', $on );
	}

	public function test_a_provider_without_a_name_gets_a_generic_sentence(): void {
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npm run test:php -- --filter 'ListTableTest|NoticesTest|SettingsPageTest'`

Expected: FAIL as below; every other test in these classes passes.

- `ListTableTest::test_the_trash_view_says_how_many_subpages_a_restore_brings_back`: Failed asserting that '<span class="hierarchy-guard-cell" data-id="5" data-children="0" data-status="trash"><span class="screen-reader-text">Parent: </span><a href="http: …
- `ListTableTest::test_the_trash_view_reads_the_screen_s_markers_in_one_query`: Failed asserting that '<span class="hierarchy-guard-cell" data-id="16" data-children="0" data-status="trash"><span aria-hidden="true">—</span><span class="screen-reader-t …
- `NoticesTest::test_a_restore_that_leaves_the_parent_a_draft_says_visitors_can_t_see_it_until_it_is_published`: Core’s Restore brings it back as a draft.
- `SettingsPageTest::test_each_trash_policy_says_what_it_does_to_the_subpages`: Failed asserting that '<fieldset id="hierarchy-guard-trash-policy"><legend class="screen-reader-text"><span>Trashing a page with subpages</span></legend><label><input typ …
- `SettingsPageTest::test_the_redirects_help_asks_to_turn_them_on_only_while_they_are_off`: Failed asserting that '<label><input type="checkbox" id="hierarchy-guard-redirects" name="hierarchy_guard_settings[serve_redirects]" value="1"  checked='checked'> Redirec …

Totals: `Tests: 97, Assertions: 275, Failures: 5.`

Run: `npx wp-scripts test-unit-js src/common/test/copy.test.js src/list-table/test/bulk-edit.test.js src/list-table/test/quick-edit.test.js`

Expected: FAIL:

- `× warns in Bulk Edit with the same next step 2ms`
- `FAIL  src/list-table/test/bulk-edit.test.js [ src/list-table/test/bulk-edit.test.js ]`
- `Error: Failed to resolve import "../bulk-edit" from "src/list-table/test/bulk-edit.test.js". Does the file exist?`
- `FAIL  src/list-table/test/quick-edit.test.js [ src/list-table/test/quick-edit.test.js ]`
- `Error: Failed to resolve import "../quick-edit-words" from "src/list-table/test/quick-edit.test.js". Does the file exist?`
- `FAIL  src/common/test/copy.test.js > warnings and outcomes > warns in Bulk Edit with the same next step`

Totals: `Tests  1 failed | 29 passed (30)`

Mutant, to run once the tests pass, which shows `test_a_restore_that_leaves_the_parent_a_draft_says_visitors_can_t_see_it_until_it_is_published` pins its behaviour: in `includes/HierarchyGuard/Admin/Notices.php`, replace

```php
&& 'draft' === get_post_status( (int) $single['root'] )
```

with:

```php
&& 'publish' === get_post_status( (int) $single['root'] )
```

Expected: the test fails, as it did on the prototype:

```
NoticesTest::test_a_restore_that_leaves_the_parent_a_draft_says_visitors_can_t_see_it_until_it_is_published
Undo brings it back published.
Tests: 1, Assertions: 1, Failures: 1.
```

Then undo the mutant (`git checkout -- includes/HierarchyGuard/Admin/Notices.php`). Never commit it.

Mutant, to run once the tests pass, which shows `counts again when core removes a page from the selection with its × button` pins its behaviour: in `src/list-table/bulk-edit.js`, replace

```js
event.target.closest( '.ntdelbutton' )
```

with:

```js
event.target.closest( '#bulk-titles button' )
```

Expected: the Vitest case fails, as it did on the prototype:

```
× counts again when core removes a page from the selection with its × button 17ms
Tests  1 failed | 4 skipped (5)
FAIL  src/list-table/test/bulk-edit.test.js > the Bulk Edit warning > counts again when core removes a page from the selection with its × button
AssertionError: expected 'Saving will change the URLs of the se…' to contain 'under 1 of them'
Expected: "under 1 of them"
Received: "Saving will change the URLs of the selected pages and of the subpages under 2 of them. After you save, you’ll see the redirects to add."
```

Then undo the mutant (`git checkout -- src/list-table/bulk-edit.js`). Never commit it.

- [ ] **Step 3: Implement**

In `includes/HierarchyGuard/Admin/List_Table.php`, replace:

```php
use HierarchyGuard\Subtree;
```

with:

```php
use HierarchyGuard\Subtree;
use HierarchyGuard\Trash\Markers;
```

In `includes/HierarchyGuard/Admin/List_Table.php`, replace:

```php
	private bool $counted = false;

	/**
```

with:

```php
	private bool $counted = false;

	/**
	 * In the Trash view, how many subpages a restore of each row brings back,
	 * from the trash's markers, read with the screen's counts.
	 *
	 * @var array<int,int>
	 */
	private array $restorable = [];

	/**
```

In `includes/HierarchyGuard/Admin/List_Table.php`, replace:

```php
			$parts[] = sprintf( '<a href="%1$s" aria-label="%2$s">%3$s</a>', esc_url( self::filter_url( $type, (int) $post->ID ) ), esc_attr( $aria ), esc_html( $label ) );
		}

```

with:

```php
			$parts[] = sprintf( '<a href="%1$s" aria-label="%2$s">%3$s</a>', esc_url( self::filter_url( $type, (int) $post->ID ) ), esc_attr( $aria ), esc_html( $label ) );
		}
		$back = 'trash' === $post->post_status ? ( $this->restorable[ (int) $post->ID ] ?? 0 ) : 0;
		if ( $back > 0 ) {
			/* translators: %s: number of subpages, such as 3 or 1,000+; "it" is the trashed page of this row. */
			$parts[] = esc_html( sprintf( _n( 'Restoring it brings back %s subpage.', 'Restoring it brings back %s subpages.', min( $back, $cap ), 'hierarchy-guard' ), Subtree::format_count( min( $back, $cap ), $back > $cap ) ) );
		}

```

In `includes/HierarchyGuard/Admin/List_Table.php`, replace:

```php

		return Subtree::child_counts( $ids, $post_type, Subtree::count_cap() + 1 );
```

with:

```php

		if ( 'trash' === (string) $wp_query->get( 'post_status' ) ) {
			$this->restorable = Markers::counts( $ids, Subtree::count_cap() + 1 );
		}

		return Subtree::child_counts( $ids, $post_type, Subtree::count_cap() + 1 );
```

In `includes/HierarchyGuard/Admin/Notices.php`, replace:

```php
			$out[] = sprintf( _n( '%s subpage couldn’t get its old URL back.', '%s subpages couldn’t get their old URLs back.', $counts['renamed'], 'hierarchy-guard' ), number_format_i18n( $counts['renamed'] ) );
		}

		return $out;
	}
```

with:

```php
			$out[] = sprintf( _n( '%s subpage couldn’t get its old URL back.', '%s subpages couldn’t get their old URLs back.', $counts['renamed'], 'hierarchy-guard' ), number_format_i18n( $counts['renamed'] ) );
		}

		// Core's Restore, unlike Undo, brings a page back as a draft: its subpages' URLs work, its own doesn't until it is published.
		if ( null !== $single && $counts['moved'] + $counts['restored'] > 0 && 'draft' === get_post_status( (int) $single['root'] ) ) {
			/* translators: "It" is the page just restored from the Trash. */
			$out[] = __( 'It’s a draft now, so visitors can’t see it until you publish it.', 'hierarchy-guard' );
		}

		return $out;
	}
```

In `includes/HierarchyGuard/Admin/Settings_Page.php`, replace:

```php
		echo '<fieldset id="hierarchy-guard-trash-policy"><legend class="screen-reader-text"><span>' . esc_html__( 'Trashing a page with subpages', 'hierarchy-guard' ) . '</span></legend>';
		foreach ( $options as $value => $label ) {
			printf(
				'<label><input type="radio" name="%1$s[trash_policy]" value="%2$s" %3$s> %4$s</label><br>',
				esc_attr( Settings::OPTION ),
				esc_attr( $value ),
				checked( $current, $value, false ),
				esc_html( $label )
```

with:

```php
		// The trash dialog's own descriptions.
		$descriptions = [
			/* translators: "Their" are the subpages of the page being trashed. */
			Settings::POLICY_REPARENT       => __( 'Their URLs change.', 'hierarchy-guard' ),
			/* translators: "Their parent" is the page being trashed; "them" are its subpages. */
			Settings::POLICY_TRASH_CHILDREN => __( 'Restoring their parent restores them with their original status.', 'hierarchy-guard' ),
			/* translators: "Their" are the subpages of the page being trashed. */
			Settings::POLICY_KEEP           => __( 'Their URLs will contain “__trashed” until you move them.', 'hierarchy-guard' ),
		];

		echo '<fieldset id="hierarchy-guard-trash-policy"><legend class="screen-reader-text"><span>' . esc_html__( 'Trashing a page with subpages', 'hierarchy-guard' ) . '</span></legend>';
		// Each description sits under its own label's text, with space after it, so it reads as that option's.
		foreach ( $options as $value => $label ) {
			printf(
				'<div style="margin-bottom:12px"><label><input type="radio" name="%1$s[trash_policy]" value="%2$s" %3$s aria-describedby="hierarchy-guard-policy-%2$s"> %4$s</label><p class="description" id="hierarchy-guard-policy-%2$s" style="margin-top:0;margin-inline-start:24px">%5$s</p></div>',
				esc_attr( Settings::OPTION ),
				esc_attr( $value ),
				checked( $current, $value, false ),
				esc_html( $label ),
				esc_html( $descriptions[ $value ] )
```

In `includes/HierarchyGuard/Admin/Settings_Page.php`, replace:

```php
			$help = __( 'A redirect plugin is active and can handle these.', 'hierarchy-guard' );
```

with:

```php
			$help = __( 'A redirect plugin is active and can handle these.', 'hierarchy-guard' );
		} elseif ( Settings::serve_redirects() ) {
			$help = __( 'No redirect plugin was found.', 'hierarchy-guard' );
```

In `includes/HierarchyGuard/Trash/Markers.php`, replace:

```php
	}

	/**
	 * Whether a post was trashed together with some root page. Reads the meta
```

with:

```php
	}

	/**
	 * How many marker rows point at each of several roots, each count
	 * stopping at a cap: one statement of capped seeks on the meta_key index,
	 * for a screen of pages in the Trash.
	 *
	 * @param int[] $root_ids Root pages.
	 * @param int   $cap      Stop counting each at this number.
	 * @return array<int,int> Count by root; a root with no rows is 0.
	 */
	public static function counts( array $root_ids, int $cap ): array {
		global $wpdb;

		$root_ids = array_values( array_unique( array_filter( array_map( 'intval', $root_ids ) ) ) );
		$counts   = array_fill_keys( $root_ids, 0 );
		$parts    = [];
		foreach ( $root_ids as $root_id ) {
			$parts[] = $wpdb->prepare(
				"( SELECT %d AS root_id, COUNT(*) AS markers FROM ( SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key IN ( %s, %s ) LIMIT %d ) AS m%d )",
				$root_id,
				self::moved_key( $root_id ),
				self::trashed_key( $root_id ),
				max( 1, $cap ),
				$root_id
			);
		}
		if ( [] === $parts ) {
			return $counts;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Every part was prepared above; each is a seek on the meta_key index bounded by LIMIT.
		$rows = $wpdb->get_results( implode( ' UNION ALL ', $parts ), ARRAY_A );
		foreach ( (array) $rows as $row ) {
			$counts[ (int) $row['root_id'] ] = (int) $row['markers'];
		}
		return $counts;
	}

	/**
	 * Whether a post was trashed together with some root page. Reads the meta
```

In `src/common/copy.js`, replace:

```js
	return (
		quickEditWarning( count, capped ) +
		' ' +
		( serving
			? __(
					'The old URLs will redirect to the new ones.',
					'hierarchy-guard'
				)
			: __(
					'You’ll get the redirects to add after saving.',
					'hierarchy-guard'
				) )
	);
```

with:

```js
	return quickEditWarning( count, capped ) + ' ' + afterSave( serving );
}

/**
 * What happens to the old URLs once a save that changes them is made.
 *
 * @param {boolean} serving Whether built-in redirects are on.
 * @return {string} Sentence.
 */
export function afterSave( serving ) {
	return serving
		? __( 'The old URLs will redirect to the new ones.', 'hierarchy-guard' )
		: __(
				'You’ll get the redirects to add after saving.',
				'hierarchy-guard'
			);
```

In `src/common/copy.js`, replace:

```js
}

/**
 * The notice status for URLs a change moved, by what handles redirects.
```

with:

```js
}

/**
 * The Bulk Edit warning, when a new parent is chosen for selected pages of
 * which some have subpages.
 *
 * @param {number}  pages   Selected published pages that have subpages.
 * @param {boolean} serving Whether built-in redirects are on.
 * @return {string} Two sentences.
 */
export function bulkEditWarning( pages, serving ) {
	const change = sprintf(
		/* translators: %s: number of selected pages that have subpages. */
		_n(
			'Saving will change the URLs of the selected pages and of the subpages under %s of them.',
			'Saving will change the URLs of the selected pages and of the subpages under %s of them.',
			pages,
			'hierarchy-guard'
		),
		formatCount( pages )
	);
	return change + ' ' + afterSave( serving );
}

/**
 * The notice status for URLs a change moved, by what handles redirects.
```

Create `src/list-table/bulk-edit.js`:

```js
/**
 * The Bulk Edit warning: a new parent for selected pages that have subpages
 * changes the URLs of those subpages too.
 */
import { speak } from '@wordpress/a11y';
import config from '../common/config';
import { bulkEditWarning } from '../common/copy';

const BOX = 'hierarchy-guard-bulk-edit';

/**
 * How many selected rows are published pages with subpages, from their
 * Hierarchy cells.
 *
 * @param {Document} doc The list screen.
 * @return {number} Count.
 */
function parentsSelected( doc ) {
	let count = 0;
	doc.querySelectorAll( '#the-list input[name="post[]"]:checked' ).forEach(
		( box ) => {
			const cell = doc.querySelector(
				`#post-${ box.value } .hierarchy-guard-cell`
			);
			if (
				cell &&
				Number( cell.dataset.children || 0 ) > 0 &&
				'publish' === cell.dataset.status
			) {
				count++;
			}
		}
	);
	return count;
}

/**
 * Shows, updates or hides the warning above the Bulk Edit form's Update
 * button, as the Parent field and the selection stand.
 *
 * @param {Document} doc The list screen.
 */
export function updateBulkWarning( doc = window.document ) {
	const form = doc.getElementById( 'bulk-edit' );
	const parent = form && form.querySelector( 'select[name="post_parent"]' );
	const bar = form && form.querySelector( '.inline-edit-save' );
	if ( ! parent || ! bar ) {
		return;
	}
	const pages = '-1' !== parent.value ? parentsSelected( doc ) : 0;
	let box = doc.getElementById( BOX );
	if ( ! pages ) {
		if ( box ) {
			box.hidden = true;
			parent.removeAttribute( 'aria-describedby' );
		}
		return;
	}
	if ( ! box ) {
		box = doc.createElement( 'div' );
		box.id = BOX;
		box.className = 'notice notice-warning notice-alt inline';
		box.hidden = true;
		box.appendChild( doc.createElement( 'p' ) );
		bar.insertBefore( box, bar.firstChild );
	}
	const text = bulkEditWarning( pages, config.serving );
	const changed = box.hidden || box.firstChild.textContent !== text;
	box.firstChild.textContent = text;
	box.hidden = false;
	parent.setAttribute( 'aria-describedby', BOX );
	if ( changed ) {
		speak( text, 'polite' );
	}
}

/**
 * Watches the Bulk Edit form's Parent field, and the selection it edits.
 *
 * @param {Document} doc The list screen.
 */
export default function bindBulkEdit( doc = window.document ) {
	doc.addEventListener( 'change', ( event ) => {
		if (
			event.target.matches(
				'#bulk-edit select[name="post_parent"], #the-list input[name="post[]"], .check-column input[id^="cb-select-all-"]'
			)
		) {
			updateBulkWarning( doc );
		}
	} );
	doc.addEventListener( 'click', ( event ) => {
		// Core's own handler on the button takes the page out of the
		// selection and removes its line first, so the button is detached
		// by now and only its own class still matches.
		if ( event.target.closest( '.ntdelbutton' ) ) {
			window.setTimeout( () => updateBulkWarning( doc ), 0 );
		}
	} );
}
```

In `src/list-table/index.js`, replace:

```js
 * The list screen: the trash dialog, the Quick Edit warning, the redirects
```

with:

```js
 * The list screen: the trash dialog, the Quick Edit and Bulk Edit warnings, the redirects
```

In `src/list-table/index.js`, replace:

```js
import bindTree from '../tree';
```

with:

```js
import bindTree from '../tree';
import bindBulkEdit from './bulk-edit';
```

In `src/list-table/index.js`, replace:

```js
	bindQuickEdit();
```

with:

```js
	bindQuickEdit();
	bindBulkEdit();
```

Create `src/list-table/quick-edit-words.js`:

```js
/**
 * What Quick Edit shows and says: the warning in its form, and the
 * announcement after a save.
 */
import { speak } from '@wordpress/a11y';
import { __ } from '@wordpress/i18n';
import { quickEditWarning } from '../common/copy';

/**
 * The warning in the Quick Edit form of a page whose subpages' URLs a save
 * would change.
 *
 * @param {{count: number, capped: boolean}} descendants The page's subpages at every level.
 * @return {string} Sentence.
 */
export function warningText( descendants ) {
	return quickEditWarning( descendants.count, descendants.capped );
}

/**
 * Speaks a sentence after core's own "Changes saved.", which core speaks
 * once the saved row has faded in. In the same polite region the later
 * message replaces the earlier, so both are said in one.
 *
 * @param {number} postId   The saved row's post.
 * @param {string} sentence What to say.
 */
export function speakAfterSave( postId, sentence ) {
	const say = () =>
		speak(
			`${ __( 'Changes saved.', 'hierarchy-guard' ) } ${ sentence }`,
			'polite'
		);
	const row = window.jQuery( `#post-${ postId }` );
	if ( row.length ) {
		row.promise().done( say );
	} else {
		say();
	}
}
```

In `src/list-table/quick-edit.js`, replace:

```js
import { quickEditWarning } from '../common/copy';
import { open } from '../common/mount';
import RedirectsModal from '../common/redirects-modal';
```

with:

```js
import { open } from '../common/mount';
import RedirectsModal from '../common/redirects-modal';
import { speakAfterSave, warningText } from './quick-edit-words';
```

In `src/list-table/quick-edit.js`, replace:

```js
 */
function printNotice( notice, source, redirects ) {
```

with:

```js
 * @param {number}                                                           postId    The row Quick Edit saved.
 */
function printNotice( notice, source, redirects, postId ) {
```

In `src/list-table/quick-edit.js`, replace:

```js
	speak( notice.sentence, 'polite' );
```

with:

```js
	speakAfterSave( postId, notice.sentence );
```

In `src/list-table/quick-edit.js`, replace:

```js
 * @param {Object|null} source    The change, or null when it is not known.
 * @param {Object}      redirects The page's redirects, or the site's when the page's failed to load.
 */
function printFailure( source, redirects = siteRedirects() ) {
```

with:

```js
 * @param {number}      postId    The row Quick Edit saved.
 * @param {Object|null} source    The change, or null when it is not known.
 * @param {Object}      redirects The page's redirects, or the site's when the page's failed to load.
 */
function printFailure( postId, source, redirects = siteRedirects() ) {
```

In `src/list-table/quick-edit.js`, replace:

```js
		source,
		redirects
```

with:

```js
		source,
		redirects,
		postId
```

In `src/list-table/quick-edit.js`, replace:

```js
 * @param {string} change    The change.
 * @param {Object} redirects The page's redirects, from its hierarchy.
 */
function announce( change, redirects ) {
```

with:

```js
 * @param {number} postId    The row Quick Edit saved.
 * @param {string} change    The change.
 * @param {Object} redirects The page's redirects, from its hierarchy.
 */
function announce( postId, change, redirects ) {
```

In `src/list-table/quick-edit.js`, replace:

```js
				printNotice( notice, source, redirects );
			}
		} )
		.catch( () => printFailure( source, redirects ) );
```

with:

```js
				printNotice( notice, source, redirects, postId );
			}
		} )
		.catch( () => printFailure( postId, source, redirects ) );
```

In `src/list-table/quick-edit.js`, replace:

```js
			box.firstChild.textContent = quickEditWarning(
				descendants.count,
				descendants.capped
			);
```

with:

```js
			box.firstChild.textContent = warningText( descendants );
```

In `src/list-table/quick-edit.js`, replace:

```js
					announce( change, data.redirects || siteRedirects() );
				}
			} )
			.catch( () => printFailure( null ) );
```

with:

```js
					announce(
						postId,
						change,
						data.redirects || siteRedirects()
					);
				}
			} )
			.catch( () => printFailure( postId, null ) );
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `npm run test:php -- --filter 'ListTableTest|NoticesTest|SettingsPageTest'`

Expected: `OK`.

Run: `npx wp-scripts test-unit-js src/common/test/copy.test.js src/list-table/test/bulk-edit.test.js src/list-table/test/quick-edit.test.js`

Expected: every test passes.

Run: `npm run test:php`, `npm run test:php:multisite` and `npm run test:js`

Expected: `Tests: 1008, Assertions: 4491, Skipped: 6.` (single site), `OK (1008 tests, 4531 assertions)` (multisite), and Vitest `Tests 184 passed (184)`.

- [ ] **Step 5: Lint and build**

Run: `composer lint && npm run lint:js && npm run lint:style && npm run build`

Expected: every command exits 0.

- [ ] **Step 6: Commit**

```bash
git add includes/HierarchyGuard/Admin/List_Table.php includes/HierarchyGuard/Admin/Notices.php includes/HierarchyGuard/Admin/Settings_Page.php includes/HierarchyGuard/Trash/Markers.php src/common/copy.js src/common/test/copy.test.js src/list-table/bulk-edit.js src/list-table/index.js src/list-table/quick-edit-words.js src/list-table/quick-edit.js src/list-table/test/bulk-edit.test.js src/list-table/test/quick-edit.test.js tests/phpunit/ListTableTest.php tests/phpunit/NoticesTest.php tests/phpunit/SettingsPageTest.php
git commit -m "feat: warn in Bulk Edit, say a restored parent is a draft, show what a restore brings back, and describe each trash policy"
```

---

### Task 14: The copy pass: one plain sentence per string, a status per cause, one name for built-in redirects

**Findings:** 1.1, 1.3, 1.4, 1.6, 1.8, 1.9, 1.10, 2.1–2.8, 2.10, 2.11 (in part), 2.14, 6.4, 7.7, 10.6, the owner's note for 3.8, and the plan audits' U-4, U-5, U-6, U-11, U-13 (7.7), U-16 and U-18

- Lens 2's rewrites are the starting point, each kept to one sentence in core's vocabulary. "Built-in redirects" is the one name (1.4). Plain permalinks get their own status (1.3) and the server's three refusals get one each: too many levels, points back to its own old URL, points to another redirected URL (1.6). A top-level root-list record reads "The URLs under /board/" (1.1). "Change what happens to the subpages" replaces "Other options" (1.8). "may now show “Page not found”" (6.4).
- The Edit view's help is split into one paragraph per sentence, and Automatic reads "302 (temporary) while restoring a page from the Trash could still undo the move, 301 (permanent) otherwise." (2.2). Pronoun-only strings name their subject or get a translator comment (2.3). Short labels get `_x()` context (2.5: "Trash" the verb, "Tree", "Automatic"). The "%s subpage" msgid has one translator comment everywhere, so make-pot no longer warns (2.4, and its lens twin 10.6). The trash dialog, the Redirects tab and the notices take lens 2's rewrites (2.6, 2.7, 2.8, 2.14), and stale doc lines go (2.10). Quick Edit's warning ends with the next step (7.7).
- The Redirects list's query argument is `hierarchy_guard_type` and its plural `hierarchy_guard_redirects`, so its bulk nonce is `bulk-hierarchy_guard_redirects` (1.9).
- 2.11 (README paragraphs of 150 to 250 words): only README sentences that state changed behaviour are corrected here; the paragraphs are rewritten with the README in plan 6b. 2.12 (the comment rewrite) is 6b; new and touched comments here already follow the comment rule.
- Root-list records (owner's answer for 3.8): under each one the Redirects tab says "This redirect stays until you delete it, even after the pages under it are gone." Nothing records which pages such a record stands for, so only a Delete removes it.
- Translator comments survive minification: a sentence chosen by an `if` chain is passed as an argument (`parts.push( /* translators: … */ _n( … ) )`), because terser folds such chains into conditionals and drops the comments of all but the first branch. make-pot now gives the same comment for every msgid from `build/` as from `src/`, and "Their URLs will contain “__trashed”…" has one comment in PHP and in JS.
- Pronouns without their subject in the string get a comment (6 strings); "Restoring “%s” restores it/them…" takes `_n()` with the count; Automatic reads "Uses 301 (permanent), or 302 (temporary) while a restore from the Trash can still undo the move."
- Crafted input: the type filter (an array, unknown, not hierarchical, empty, markup, upper case, slashed) and a bulk Delete signed for another nonce action.
- Every new and changed string is listed verbatim under "New and changed strings" at the end of this plan.

**Files:**
- Modify: `includes/HierarchyGuard/Admin/List_Table.php`
- Modify: `includes/HierarchyGuard/Admin/Meta_Box.php`
- Modify: `includes/HierarchyGuard/Admin/Notices.php`
- Modify: `includes/HierarchyGuard/Admin/Redirect_Edit.php`
- Modify: `includes/HierarchyGuard/Admin/Redirects_Table.php`
- Modify: `includes/HierarchyGuard/Admin/Settings_Page.php`
- Modify: `includes/HierarchyGuard/Admin/Tree_View.php`
- Modify: `includes/HierarchyGuard/REST/Changes_Controller.php`
- Modify: `includes/HierarchyGuard/Redirects/Records.php`
- Modify: `src/common/copy.js`
- Modify: `src/common/redirects-modal.js`
- Modify: `src/editor/panel.js`
- Modify: `src/list-table/quick-edit-words.js`
- Modify: `src/list-table/trash-dialog.js`
- Modify: `README.md`
- Test (modify): `src/common/test/change-notice.test.js`
- Test (modify): `src/common/test/copy.test.js`
- Test (modify): `src/list-table/test/bulk-edit.test.js`
- Test (modify): `src/list-table/test/quick-edit.test.js`
- Test (modify): `tests/phpunit/ChangesControllerTest.php`
- Test (modify): `tests/phpunit/ListTableTest.php`
- Test (modify): `tests/phpunit/NoticesTest.php`
- Test (modify): `tests/phpunit/RecordsTest.php`
- Test (modify): `tests/phpunit/RedirectEditTest.php`
- Test (modify): `tests/phpunit/RedirectsTabTest.php`
- Test (modify): `tests/phpunit/SettingsPageTest.php`

**Interfaces:**
- Produces: `Records::STATUS_TOO_DEEP`, `STATUS_LOOP`, `STATUS_CHAINED`, `STATUS_PLAIN` (replacing the single "can't send" status); `Redirects_Table::TYPE_ARG = 'hierarchy_guard_type'`, `Redirects_Table::NONCE_BULK = 'bulk-hierarchy_guard_redirects'`; `List_Table::request_list_script()` (was `signal()`).
- Produces (JS): `quick-edit-words.js`'s `warningText()` now returns `saveWarning( count, capped, config.serving )` (7.7).

- [ ] **Step 1: Write the failing tests**

In `src/common/test/change-notice.test.js`, replace:

```js
			sentence: '1 old URL now shows “Page not found”.',
```

with:

```js
			sentence: '1 old URL may now show “Page not found”.',
```

In `src/common/test/copy.test.js`, replace:

```js
			'“Team” has 1 subpage. It will move up under “Studio” and get a new URL.'
```

with:

```js
			'“Team” has 1 subpage. It’ll move up under “Studio” and get a new URL.'
```

In `src/common/test/copy.test.js`, replace:

```js
			'“Team” has 2 subpages directly under it, 3 in all. They go to the Trash too, and come back with their statuses if you restore “Team”.'
```

with:

```js
			'“Team” has 2 subpages directly under it, 3 in all. They go to the Trash too. Restoring “Team” restores them with their original status.'
```

In `src/common/test/copy.test.js`, replace:

```js
			'“Team” has 2 subpages directly under it, 3 in all. That is more than 1,000, so they’ll move up one level instead of going to the Trash.'
```

with:

```js
			'“Team” has 2 subpages directly under it, 3 in all. That’s more than 1,000, so they’ll move up one level instead of going to the Trash.'
```

In `src/common/test/copy.test.js`, replace:

```js
			'2 of the selected pages have subpages, 1,000+ in all. The subpages go to the Trash too, and come back with their statuses if you restore their parent. A page with more than 1,000 subpages has them moved up instead.'
```

with:

```js
			'2 of the selected pages have subpages, 1,000+ in all. The subpages go to the Trash too. Restoring their parent restores them with their original status. A page with more than 1,000 subpages has them moved up instead.'
```

In `src/common/test/copy.test.js`, replace:

```js

	it( 'names the right that is missing, and what an error leads to', () => {
```

with:

```js

	it( 'says “it” or “them” for the subpages a restore brings back, as many as there are', () => {
		expect(
			optionDescription(
				'trash_children',
				{
					...team,
					title: 'Solo',
					descendants: { count: 1, capped: false },
				},
				1000
			)
		).toBe( 'Restoring “Solo” restores it with its original status.' );
		expect(
			optionDescription(
				'trash_children',
				{ ...team, descendants: { count: 3, capped: false } },
				1000
			)
		).toBe( 'Restoring “Team” restores them with their original status.' );
		expect( optionDescription( 'trash_children', null, 1000 ) ).toBe(
			'Restoring their parent restores them with their original status.'
		);
	} );

	it( 'names the right that is missing, and what an error leads to', () => {
```

In `src/common/test/copy.test.js`, replace:

```js
			'You can’t delete some of the subpages. They’ll stay where they are.'
```

with:

```js
			'You can’t move some of the subpages to the Trash. They’ll stay where they are.'
```

In `src/common/test/copy.test.js`, replace:

```js
			'Saving changes this page’s URL and the URLs of 12 subpages. You’ll get the redirects to add after saving.'
		);
		expect( saveWarning( 1, false, true ) ).toBe(
			'Saving changes this page’s URL and the URL of 1 subpage. The old URLs will redirect to the new ones.'
		);
		expect( quickEditWarning( 5, false ) ).toBe(
			'Saving changes this page’s URL and the URLs of 5 subpages.'
```

with:

```js
			'Saving will change the URLs of this page and of its 12 subpages. After you save, you’ll see the redirects to add.'
		);
		expect( saveWarning( 1, false, true ) ).toBe(
			'Saving will change the URL of this page and of its 1 subpage. The old URLs will redirect to the new ones.'
		);
		expect( quickEditWarning( 5, false ) ).toBe(
			'Saving will change the URLs of this page and of its 5 subpages.'
```

In `src/common/test/copy.test.js`, replace:

```js
			'Saving will change the URLs of the selected pages and of the subpages under 2 of them. You’ll get the redirects to add after saving.'
```

with:

```js
			'Saving will change the URLs of the selected pages and of the subpages under 2 of them. After you save, you’ll see the redirects to add.'
```

In `src/common/test/copy.test.js`, replace:

```js
			'Saving changes this page’s URL and the URLs of 12 subpages. You’ll get the redirects to add after saving. Continue?'
		);
		expect( classicConfirm( 1, false, true ) ).toBe(
			'Saving changes this page’s URL and the URL of 1 subpage. The old URLs will redirect to the new ones. Continue?'
```

with:

```js
			'Saving will change the URLs of this page and of its 12 subpages. After you save, you’ll see the redirects to add. Continue?'
		);
		expect( classicConfirm( 1, false, true ) ).toBe(
			'Saving will change the URL of this page and of its 1 subpage. The old URLs will redirect to the new ones. Continue?'
```

In `src/common/test/copy.test.js`, replace:

```js
			'1 old URL now shows “Page not found”.'
```

with:

```js
			'1 old URL may now show “Page not found”.'
```

In `src/list-table/test/bulk-edit.test.js`, replace:

```js
			'Saving will change the URLs of the selected pages and of the subpages under 2 of them. You’ll get the redirects to add after saving.'
```

with:

```js
			'Saving will change the URLs of the selected pages and of the subpages under 2 of them. After you save, you’ll see the redirects to add.'
```

In `src/list-table/test/quick-edit.test.js`, replace:

```js
const { speakAfterSave } = await import( '../quick-edit-words' );
```

with:

```js
const { default: config } = await import( '../../common/config' );
const { speakAfterSave, warningText } = await import( '../quick-edit-words' );

describe( 'the Quick Edit warning', () => {
	afterEach( () => {
		config.serving = false;
	} );

	it( 'says what happens to the old URLs, as the editors do', () => {
		config.serving = false;
		expect( warningText( { count: 3, capped: false } ) ).toBe(
			'Saving will change the URLs of this page and of its 3 subpages. After you save, you’ll see the redirects to add.'
		);

		config.serving = true;
		expect( warningText( { count: 1, capped: false } ) ).toBe(
			'Saving will change the URL of this page and of its 1 subpage. The old URLs will redirect to the new ones.'
		);
	} );
} );
```

In `tests/phpunit/ChangesControllerTest.php`, replace:

```php
		$this->assertSame( 'These redirects are no longer available. A later change replaced them. They are kept for 30 minutes.', $response->get_data()['message'] );
```

with:

```php
		$this->assertSame( 'These redirects are no longer available. A later change replaced them. Each change’s redirects are listed for 30 minutes.', $response->get_data()['message'] );
```

In `tests/phpunit/ChangesControllerTest.php`, replace:

```php
		$this->assertSame( 'These redirects are no longer available. They are kept for 30 minutes.', $response->get_data()['message'], 'Nothing replaced them.' );
```

with:

```php
		$this->assertSame( 'These redirects are no longer available. Each change’s redirects are listed for 30 minutes.', $response->get_data()['message'], 'Nothing replaced them.' );
```

In `tests/phpunit/ListTableTest.php`, replace:

```php
		$this->assertStringContainsString( '<span class="screen-reader-text">Parent: </span><a href="', $cell );
```

with:

```php
		$this->assertStringContainsString( '<span class="screen-reader-text">Parent page: </span><a href="', $cell );
```

In `tests/phpunit/ListTableTest.php`, replace:

```php
		$this->assertStringContainsString( 'Parent: </span>Team (in the Trash)', $cell, 'Core refuses to edit a page in the Trash, so it is not linked.' );
```

with:

```php
		$this->assertStringContainsString( 'Parent page: </span>Team (in the Trash)', $cell, 'Core refuses to edit a page in the Trash, so it is not linked.' );
```

In `tests/phpunit/NoticesTest.php`, replace:

```php
		$this->assertStringStartsWith( '4 old URLs now show “Page not found”. <a href="', $notice['message'] );
```

with:

```php
		$this->assertStringStartsWith( '4 old URLs may now show “Page not found”. <a href="', $notice['message'] );
```

In `tests/phpunit/NoticesTest.php`, replace:

```php
		$this->assertStringContainsString( 'is-dismissible', $html );
		$this->assertStringContainsString( '4 old URLs now show “Page not found”.', $html );
```

with:

```php
		$this->assertStringContainsString( 'is-dismissible', $html );
		$this->assertStringContainsString( '4 old URLs may now show “Page not found”.', $html );
```

In `tests/phpunit/NoticesTest.php`, replace:

```php
		$this->assertStringContainsString( '4 old URLs now show “Page not found”.', $html );
```

with:

```php
		$this->assertStringContainsString( '4 old URLs may now show “Page not found”.', $html );
```

In `tests/phpunit/NoticesTest.php`, replace:

```php
			[ '2 subpages moved back under “About”.', '3 subpages were restored with it.', '1 subpage couldn’t be brought back and stayed where it is.', '1 subpage couldn’t get its old URL back.' ],
```

with:

```php
			[ '2 subpages moved back under “About”.', '3 subpages were restored with it.', '1 subpage couldn’t be restored and stayed where it is.', '1 subpage couldn’t get its old URL back.' ],
```

In `tests/phpunit/NoticesTest.php`, replace:

```php
		$this->assertStringStartsWith( '3 old URLs now show “Page not found”. <a href="', $notice['message'] );
```

with:

```php
		$this->assertStringStartsWith( '3 old URLs may now show “Page not found”. <a href="', $notice['message'] );
```

In `tests/phpunit/NoticesTest.php`, replace:

```php
		$this->assertSame( 'The subpages of 2 more pages are still being worked on in the background.', $sentences[3] );
```

with:

```php
		$this->assertSame( 'The subpages of 2 more pages are still being processed in the background.', $sentences[3] );
```

In `tests/phpunit/RecordsTest.php`, replace:

```php
				'/old-a/' => Records::STATUS_REFUSED,
				'/p/'     => Records::STATUS_REFUSED,
```

with:

```php
				'/old-a/' => Records::STATUS_CHAINED,
				'/p/'     => Records::STATUS_CHAINED,
```

In `tests/phpunit/RecordsTest.php`, replace:

```php
				'/old-a/' => Records::STATUS_REFUSED,
				'/old-b/' => Records::STATUS_REFUSED,
				'/old-c/' => Records::STATUS_REFUSED,
```

with:

```php
				'/old-a/' => Records::STATUS_CHAINED,
				'/old-b/' => Records::STATUS_CHAINED,
				'/old-c/' => Records::STATUS_CHAINED,
```

In `tests/phpunit/RecordsTest.php`, replace:

```php
		foreach ( $rows as $row ) {
			$this->assertSame( Records::STATUS_REFUSED, $statuses[ $row['id'] ], $row['old'] . ' can’t be sent.' );
```

with:

```php
		$expected = [
			'old-a' => Records::STATUS_LOOP,
			'old-b' => Records::STATUS_CHAINED,
			'old-c' => Records::STATUS_CHAINED,
		];
		foreach ( $rows as $row ) {
			$this->assertSame( $expected[ $row['path'] ], $statuses[ $row['id'] ], $row['old'] . ' can’t be sent.' );
```

In `tests/phpunit/RecordsTest.php`, replace:

```php
				'/a/b/c/' => Records::STATUS_REFUSED,
```

with:

```php
				'/a/b/c/' => Records::STATUS_TOO_DEEP,
```

In `tests/phpunit/RecordsTest.php`, replace:

```php
		$this->assertSame( Records::STATUS_REFUSED, $this->statuses_by_old()['/a/b/c/'], 'Before built-in redirects are off: it can’t be sent even with them on.' );
```

with:

```php
		$this->assertSame( Records::STATUS_TOO_DEEP, $this->statuses_by_old()['/a/b/c/'], 'Before built-in redirects are off: it can’t be sent even with them on.' );
```

In `tests/phpunit/RecordsTest.php`, replace:

```php
		$this->assertSame( Records::STATUS_REFUSED, $this->statuses_by_old()['/old-crew/'] );
```

with:

```php
		$this->assertSame( Records::STATUS_LOOP, $this->statuses_by_old()['/old-crew/'] );
```

In `tests/phpunit/RecordsTest.php`, replace:

```php
			$this->assertSame( Records::STATUS_REFUSED, $this->statuses_by_old()['/café/'], $url );
```

with:

```php
			$this->assertSame( Records::STATUS_LOOP, $this->statuses_by_old()['/café/'], $url );
```

In `tests/phpunit/RecordsTest.php`, replace:

```php
				'/a/b/c/'    => Records::STATUS_REFUSED,
				'/old-crew/' => Records::STATUS_REFUSED,
				'/story/'    => Records::STATUS_OFF,
```

with:

```php
				'/a/b/c/'    => Records::STATUS_TOO_DEEP,
				'/old-crew/' => Records::STATUS_LOOP,
				'/story/'    => Records::STATUS_PLAIN,
```

In `tests/phpunit/RecordsTest.php`, replace:

```php
		$this->assertSame( 'Not active: the server can’t send this redirect', \HierarchyGuard\Admin\Redirects_Table::status_label( Records::STATUS_REFUSED ) );
```

with:

```php
		$words = [
			Records::STATUS_ACTIVE      => 'Active',
			Records::STATUS_IN_USE      => 'Not active: a page uses this URL',
			Records::STATUS_UNPUBLISHED => 'Not active: the page it points to isn’t published',
			Records::STATUS_TOO_DEEP    => 'Not active: the URL has too many levels',
			Records::STATUS_LOOP        => 'Not active: it points back to its own old URL',
			Records::STATUS_CHAINED     => 'Not active: it points to another redirected URL',
			Records::STATUS_OFF         => 'Not active: built-in redirects are off',
			Records::STATUS_PLAIN       => 'Not active: permalinks are set to Plain',
		];
		foreach ( $words as $status => $label ) {
			$this->assertSame( $label, \HierarchyGuard\Admin\Redirects_Table::status_label( $status ) );
		}
```

In `tests/phpunit/RecordsTest.php`, replace:

```php
				'/old/parent/' => Records::STATUS_REFUSED,
```

with:

```php
				'/old/parent/' => Records::STATUS_TOO_DEEP,
```

In `tests/phpunit/RecordsTest.php`, replace:

```php
		$this->assertSame( [ Records::STATUS_OFF ], array_values( $this->records->statuses( $rows ) ), 'The server serves nothing without pretty permalinks.' );
```

with:

```php
		$this->assertSame( [ Records::STATUS_PLAIN ], array_values( $this->records->statuses( $rows ) ), 'The server serves nothing without pretty permalinks.' );
```

In `tests/phpunit/RedirectEditTest.php`, replace:

```php
		foreach ( [ 'tab', 'action', 'redirect', '_wpnonce', 'target', 'code', 's', 'redirect_type', 'paged' ] as $key ) {
```

with:

```php
		foreach ( [ 'tab', 'action', 'redirect', '_wpnonce', 'target', 'code', 's', 'hierarchy_guard_type', 'paged' ] as $key ) {
```

In `tests/phpunit/RedirectEditTest.php`, replace:

```php
		$this->assertStringContainsString( '<p class="description" id="hierarchy-guard-target-description">A page on this site: the redirect follows it when it moves, and the URLs under <code>/old-about/</code> go to the matching URLs under it. Any other URL: only <code>/old-about/</code> redirects, and this redirect no longer covers the URLs under it. A path on this site, such as <code>/about/team/</code>, works as well as a full URL.</p>', $html );
		$this->assertStringContainsString( 'the URLs under <code>/old-about/</code> go to', $html, 'The old URL prints in <code> in the help, so it reads left to right in a right-to-left admin.' );
		$this->assertStringNotContainsString( 'Leave empty to keep this.', $html );
		$this->assertMatchesRegularExpression( '#<input type="radio" name="code" value="0" aria-describedby="hierarchy-guard-code-automatic-description"\s+checked=\'checked\'> Automatic</label><p class="description" id="hierarchy-guard-code-automatic-description">301, or 302 while restoring a page from the Trash could undo the move.</p>#', $html );
```

with:

```php
		$this->assertStringContainsString( '<div id="hierarchy-guard-target-description"><p class="description">If you enter a page on this site, the redirect follows that page when it moves, and URLs under <code>/old-about/</code> go to the matching URLs under that page.</p><p class="description">If you enter any other URL, only <code>/old-about/</code> itself redirects there, and URLs under it are no longer redirected.</p><p class="description">You can enter a path on this site, such as <code>/about/team/</code>, instead of a full URL.</p></div>', $html, 'One sentence a line, for each kind of target.' );
		$this->assertStringContainsString( 'URLs under <code>/old-about/</code> go to', $html, 'The old URL prints in <code> in the help, so it reads left to right in a right-to-left admin.' );
		$this->assertStringNotContainsString( 'Leave this empty', $html );
		$this->assertMatchesRegularExpression( '#<input type="radio" name="code" value="0" aria-describedby="hierarchy-guard-code-automatic-description"\s+checked=\'checked\'> Automatic</label><p class="description" id="hierarchy-guard-code-automatic-description">Uses 301 \(permanent\), or 302 \(temporary\) while a restore from the Trash can still undo the move.</p>#', $html );
```

In `tests/phpunit/RedirectEditTest.php`, replace:

```php
		$this->assertStringContainsString( '<td><p id="hierarchy-guard-target-now">Now redirects to the same page at the top level, for example <code>/about/team/</code> to <code>/team/</code>.</p><input type="text" class="regular-text code" id="hierarchy-guard-target" name="target" value="" dir="ltr" aria-describedby="hierarchy-guard-target-now hierarchy-guard-target-description">', $html, 'The field starts empty, and is described by where it leads now too.' );
		$this->assertStringContainsString( 'id="hierarchy-guard-target-description">Leave empty to keep this. Enter a URL to send these URLs somewhere else. A page on this site:', $html );
```

with:

```php
		$this->assertStringContainsString( '<td><p id="hierarchy-guard-target-now">URLs under <code>/archive/</code> now go to the same page at the top level, for example <code>/archive/example/</code> to <code>/example/</code>.</p><input type="text" class="regular-text code" id="hierarchy-guard-target" name="target" value="" dir="ltr" aria-describedby="hierarchy-guard-target-now hierarchy-guard-target-description">', $html, 'The field starts empty, and is described by where it leads now too, with an example from its own old URL.' );
		$this->assertStringContainsString( 'id="hierarchy-guard-target-description"><p class="description">Leave this empty to keep sending them to the same page at the top level.</p><p class="description">Or enter a URL to send them somewhere else.</p><p class="description">If you enter a page on this site,', $html );
		$this->assertStringContainsString( '<tr><th scope="row">Old URL</th><td>The URLs under <code>/archive/</code></td></tr>', $html, 'Its old URL itself is a 404: only the URLs under it redirect.' );
```

In `tests/phpunit/RedirectEditTest.php`, replace:

```php
			's'             => 'old about',
			'redirect_type' => 'page',
			'paged'         => '2',
```

with:

```php
			's'                    => 'old about',
			'hierarchy_guard_type' => 'page',
			'paged'                => '2',
```

In `tests/phpunit/RedirectEditTest.php`, replace:

```php
		$back = admin_url( 'options-general.php?page=hierarchy-guard&tab=redirects&s=old%20about&redirect_type=page&paged=2' );
		$this->assertStringContainsString( '&amp;s=old+about&amp;redirect_type=page&amp;paged=2', Redirect_Edit::edit_url( $this->id ), 'The row’s Edit link.' );
```

with:

```php
		$back = admin_url( 'options-general.php?page=hierarchy-guard&tab=redirects&s=old%20about&hierarchy_guard_type=page&paged=2' );
		$this->assertStringContainsString( '&amp;s=old+about&amp;hierarchy_guard_type=page&amp;paged=2', Redirect_Edit::edit_url( $this->id ), 'The row’s Edit link.' );
```

In `tests/phpunit/RedirectEditTest.php`, replace:

```php
		$this->assertStringContainsString( '<input type="hidden" name="s" value="old about"><input type="hidden" name="redirect_type" value="page"><input type="hidden" name="paged" value="2">', $html, 'Save posts them back.' );
```

with:

```php
		$this->assertStringContainsString( '<input type="hidden" name="s" value="old about"><input type="hidden" name="hierarchy_guard_type" value="page"><input type="hidden" name="paged" value="2">', $html, 'Save posts them back.' );
```

In `tests/phpunit/RedirectsTabTest.php`, replace:

```php
		foreach ( [ 'tab', 'action', 'action2', 'redirect', '_wpnonce', '_wp_http_referer', 's', 'redirect_type', 'paged', 'filter_action' ] as $key ) {
```

with:

```php
		foreach ( [ 'tab', 'action', 'action2', 'redirect', '_wpnonce', '_wp_http_referer', 's', 'hierarchy_guard_type', 'paged', 'filter_action' ] as $key ) {
```

In `tests/phpunit/RedirectsTabTest.php`, replace:

```php
		$this->assertStringContainsString( 'class="wp-list-table widefat fixed striped table-view-list redirects"', $html );
```

with:

```php
		$this->assertStringContainsString( 'class="wp-list-table widefat fixed striped table-view-list hierarchy_guard_redirects"', $html );
```

In `tests/phpunit/RedirectsTabTest.php`, replace:

```php
		$this->assertStringContainsString( '<code>/archive/</code></a> and the URLs under it', $html );
```

with:

```php
		$this->assertStringContainsString( '>The URLs under <a class="row-title"', $html, 'A top-level entry never redirects its old URL itself.' );
		$this->assertStringContainsString( '<code>/archive/</code></a>', $html );
		$this->assertStringNotContainsString( '<code>/archive/</code></a> and the URLs under it', $html );
		$this->assertStringContainsString( '<code>/archive/</code></a><p class="description">This redirect stays until you delete it, even after the pages under it are gone.</p>', $html );
		$this->assertSame( 1, substr_count( $html, 'This redirect stays until you delete it' ), 'Only the root-list record says so: the others go when their page does.' );
```

In `tests/phpunit/RedirectsTabTest.php`, replace:

```php
		$this->assertStringNotContainsString( 'name="redirect_type"', $this->rendered() );
```

with:

```php
		$this->assertStringNotContainsString( 'name="hierarchy_guard_type"', $this->rendered() );
```

In `tests/phpunit/RedirectsTabTest.php`, replace:

```php
		$this->assertStringContainsString( '<select name="redirect_type" id="hierarchy-guard-post-type">', $html );
		$this->assertStringContainsString( '<option value="">All post types</option>', $html );
		$this->assertStringContainsString( '<option value="hg_docs">Docs</option>', $html );

		$_GET['redirect_type']     = 'hg_docs';
		$_REQUEST['redirect_type'] = 'hg_docs';
		$html                      = $this->rendered();
```

with:

```php
		$this->assertStringContainsString( '<select name="hierarchy_guard_type" id="hierarchy-guard-post-type">', $html );
		$this->assertStringContainsString( '<option value="">All post types</option>', $html );
		$this->assertStringContainsString( '<option value="hg_docs">Docs</option>', $html );

		$_GET['hierarchy_guard_type']     = 'hg_docs';
		$_REQUEST['hierarchy_guard_type'] = 'hg_docs';
		$html                             = $this->rendered();
```

In `tests/phpunit/RedirectsTabTest.php`, replace:

```php
		$id                        = $this->recorded( $this->ids['about'], 'old-about' );
		$_GET['redirect_type']     = 'page';
		$_REQUEST['redirect_type'] = 'page';
		$html                      = $this->rendered();
```

with:

```php
		$id                               = $this->recorded( $this->ids['about'], 'old-about' );
		$_GET['hierarchy_guard_type']     = 'page';
		$_REQUEST['hierarchy_guard_type'] = 'page';
		$html                             = $this->rendered();
```

In `tests/phpunit/RedirectsTabTest.php`, replace:

```php
		$this->assertStringContainsString( 'redirect_type=page', $after );
```

with:

```php
		$this->assertStringContainsString( 'hierarchy_guard_type=page', $after );
```

In `tests/phpunit/RedirectsTabTest.php`, replace:

```php
		$this->assertStringContainsString( 'Built-in redirects are off, so these redirects aren’t served. Turn them on in the <a href="' . esc_url( Settings_Page::redirects_url() ) . '">Settings tab</a>.', $html );
```

with:

```php
		$this->assertStringContainsString( 'Built-in redirects are off, so these redirects aren’t served. Turn on built-in redirects in the <a href="' . esc_url( Settings_Page::redirects_url() ) . '">Settings tab</a>.', $html );
```

In `tests/phpunit/RedirectsTabTest.php`, replace:

```php
		$this->assertStringContainsString( 'Redirection is active. These are the redirects Hierarchy Guard recorded; its notices after each change offer to add them in Redirection.', $html );
```

with:

```php
		$this->assertStringContainsString( 'Redirection is active. After each URL change, Hierarchy Guard’s notice offers to add these redirects to Redirection.', $html );
```

In `tests/phpunit/RedirectsTabTest.php`, replace:

```php
		$this->assertStringContainsString( '>Not active: built-in redirects are off<', $html );
```

with:

```php
		$this->assertStringContainsString( '>Not active: permalinks are set to Plain<', $html );
		$this->assertStringNotContainsString( 'Not active: built-in redirects are off', $html, 'The switch is on: the permalinks are why.' );
```

In `tests/phpunit/RedirectsTabTest.php`, replace:

```php
				'message' => 'Redirect deleted. <code>/old-about/</code> no longer redirects through it.',
```

with:

```php
				'message' => 'Redirect deleted. Hierarchy Guard no longer redirects <code>/old-about/</code>.',
```

In `tests/phpunit/RedirectsTabTest.php`, replace:

```php
		$this->assertSame( '2 redirects deleted. Their old URLs no longer redirect through them.', $this->kept_notice()['message'] );
```

with:

```php
		$this->assertSame( '2 redirects deleted. Hierarchy Guard no longer redirects their old URLs.', $this->kept_notice()['message'] );
	}

	/**
	 * Nonces a bulk Delete never carries: none at all, another action's, and
	 * the one of WordPress's own list tables.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function foreign_nonce_actions(): array {
		return [
			'core list tables' => [ 'bulk-redirects' ],
			'another plugin'   => [ 'bulk-posts' ],
			'the edit view'    => [ 'hierarchy_guard_redirect_save' ],
			'wrong case'       => [ 'BULK-HIERARCHY_GUARD_REDIRECTS' ],
		];
	}

	/**
	 * @dataProvider foreign_nonce_actions
	 *
	 * @param string $action Nonce action the request was signed for.
	 */
	public function test_a_bulk_delete_signed_for_another_action_deletes_nothing( string $action ): void {
		$_REQUEST['action']   = 'delete';
		$_REQUEST['redirect'] = [ $this->recorded( $this->ids['about'], 'old-about' ) ];
		$_REQUEST['_wpnonce'] = wp_create_nonce( $action );

		try {
			( new Redirect_Edit( $this->records ) )->handle();
			$this->fail( 'A foreign nonce must stop the request.' );
		} catch ( WPDieException $stopped ) {
			$this->assertSame( [ 'old-about' ], $this->ledger->paths_for( $this->ids['about'] ) );
		}
	}

	/**
	 * Post type filter values the select never sends, and what the tab filters on.
	 *
	 * @return array<string,array{0:mixed,1:string}>
	 */
	public static function crafted_types(): array {
		return [
			'array'            => [ [ 'page' ], '' ],
			'unknown'          => [ 'nope', '' ],
			'not hierarchical' => [ 'post', '' ],
			'empty'            => [ '', '' ],
			'markup'           => [ 'page<script>', '' ],
			'upper case'       => [ 'PAGE', 'page' ],
			'slashed'          => [ 'pa\\ge', 'page' ],
		];
	}

	/**
	 * @dataProvider crafted_types
	 *
	 * @param mixed  $value    The hierarchy_guard_type argument.
	 * @param string $expected The type filtered on, '' for all.
	 */
	public function test_a_crafted_post_type_filter_falls_back_to_every_type( $value, string $expected ): void {
		$_GET['hierarchy_guard_type']     = $value;
		$_REQUEST['hierarchy_guard_type'] = $value;

		$this->assertSame( $expected, Redirects_Table::post_type() );
		$this->assertStringContainsString( 'id="hierarchy-guard-redirects"', $this->rendered(), 'The tab renders without a warning.' );
```

In `tests/phpunit/RedirectsTabTest.php`, replace:

```php
		$this->assertSame( '20 redirects deleted. Their old URLs no longer redirect through them.', $this->kept_notice()['message'] );
```

with:

```php
		$this->assertSame( '20 redirects deleted. Hierarchy Guard no longer redirects their old URLs.', $this->kept_notice()['message'] );
```

In `tests/phpunit/RedirectsTabTest.php`, replace:

```php
			's'             => 'old',
			'redirect_type' => 'page',
			'paged'         => '2',
```

with:

```php
			's'                    => 'old',
			'hierarchy_guard_type' => 'page',
			'paged'                => '2',
```

In `tests/phpunit/RedirectsTabTest.php`, replace:

```php
		foreach ( [ 's=old', 'redirect_type=page', 'paged=2' ] as $arg ) {
```

with:

```php
		foreach ( [ 's=old', 'hierarchy_guard_type=page', 'paged=2' ] as $arg ) {
```

In `tests/phpunit/RedirectsTabTest.php`, replace:

```php
		$this->assertSame( admin_url( 'options-general.php?page=hierarchy-guard&tab=redirects&s=old&redirect_type=page&paged=2&settings-updated=true' ), ( new Redirect_Edit( $this->records ) )->handle(), 'A row’s Delete returns to the same list.' );
```

with:

```php
		$this->assertSame( admin_url( 'options-general.php?page=hierarchy-guard&tab=redirects&s=old&hierarchy_guard_type=page&paged=2&settings-updated=true' ), ( new Redirect_Edit( $this->records ) )->handle(), 'A row’s Delete returns to the same list.' );
```

In `tests/phpunit/RedirectsTabTest.php`, replace:

```php
		$this->assertMatchesRegularExpression( '#</table>.*<p class="description">Status checks posts and pages at each old URL. Other WordPress URLs, such as archives or other plugins’ rules, aren’t checked. When two recorded redirects share an old URL, at most one is served, and both can read Active.</p>#s', $this->rendered() );
```

with:

```php
		$this->assertMatchesRegularExpression( '#</table>.*<p class="description">The Status column only checks whether a post or page now uses each old URL. It doesn’t check archives or other plugins’ redirect rules. If two recorded redirects have the same old URL, only one is served, though both may show Active.</p>#s', $this->rendered() );
```

In `tests/phpunit/RedirectsTabTest.php`, replace:

```php
		$this->assertStringContainsString( '<div class="notice notice-info inline"><p>The list of recorded redirects is still being built. Older redirects appear here once it’s done.</p></div>', $this->rendered() );
```

with:

```php
		$this->assertStringContainsString( '<div class="notice notice-info inline"><p>The list of recorded redirects is still being built. Older redirects will appear when it’s finished.</p></div>', $this->rendered() );
```

In `tests/phpunit/SettingsPageTest.php`, replace:

```php
		$this->assertStringContainsString( 'Custom Redirects is active and can handle these.', $html );
```

with:

```php
		$this->assertStringContainsString( 'Custom Redirects is active and can serve these redirects instead.', $html );
```

In `tests/phpunit/SettingsPageTest.php`, replace:

```php
		$this->assertStringContainsString( 'Trash the subpages too', $html );
		$this->assertStringContainsString( 'Leave subpages where they are', $html );
		$this->assertStringContainsString( 'Used when there’s no chance to ask, for example in the editor, the REST API, or WP-CLI. The Pages list asks each time.', $html );
```

with:

```php
		$this->assertStringContainsString( 'Trash subpages too', $html );
		$this->assertStringContainsString( 'Leave subpages where they are', $html );
		$this->assertStringContainsString( 'Applies when a page is trashed from the editor, the REST API or WP-CLI. Trashing from the Pages list asks each time.', $html );
```

In `tests/phpunit/SettingsPageTest.php`, replace:

```php
		$this->assertStringContainsString( 'A redirect plugin is active and can handle these.', $html );
```

with:

```php
		$this->assertStringContainsString( 'A redirect plugin is active and can serve these redirects instead.', $html );
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npm run test:php -- --filter 'ChangesControllerTest|ListTableTest|NoticesTest|RecordsTest|RedirectEditTest|RedirectsTabTest|SettingsPageTest'`

Expected: FAIL as below; every other test in these classes passes.

- `RecordsTest::test_a_loop_formed_after_both_saves_is_stopped_by_the_server`: Error: Undefined constant HierarchyGuard\Redirects\Records::STATUS_CHAINED
- `RecordsTest::test_a_row_whose_target_is_another_redirect_can_t_be_sent`: Error: Undefined constant HierarchyGuard\Redirects\Records::STATUS_CHAINED
- `RecordsTest::test_on_a_site_in_a_subdirectory_its_home_path_is_read_in_any_case`: Error: Undefined constant HierarchyGuard\Redirects\Records::STATUS_LOOP
- `RecordsTest::test_a_url_target_at_its_own_old_url_never_reads_active`: Error: Undefined constant HierarchyGuard\Redirects\Records::STATUS_LOOP
- 4 tests in `RecordsTest`: Error: Undefined constant HierarchyGuard\Redirects\Records::STATUS_TOO_DEEP
- `RecordsTest::test_under_plain_permalinks_no_row_reads_active`: Error: Undefined constant HierarchyGuard\Redirects\Records::STATUS_PLAIN
- 6 tests in `ChangesControllerTest`, `NoticesTest`, `RedirectsTabTest`: Failed asserting that two strings are identical.
- `ChangesControllerTest::test_the_gone_message_of_an_expired_change_says_only_how_long_they_are_kept`: Nothing replaced them.
- `ListTableTest::test_a_cell_shows_the_parent_and_the_subpage_count`: Failed asserting that '<span class="hierarchy-guard-cell" data-id="181" data-children="2" data-status="publish"><span class="screen-reader-text">Parent: </span><a href="h …
- `ListTableTest::test_a_parent_in_the_trash_is_marked`: Core refuses to edit a page in the Trash, so it is not linked.
- `NoticesTest::test_a_save_s_urls_notice_says_what_handles_its_redirects`: Failed asserting that '4 old URLs now show “Page not found”. <a href="http://localhost:8899/wp-json/hierarchy-guard/v1/changes/34fd3a3f-640f-4f65-ab5b-bc202e28f1c6/csv?fo …
- `NoticesTest::test_the_classic_editor_prints_the_save_s_urls_notice_once`: Failed asserting that '<div class="notice notice-warning is-dismissible hierarchy-guard-urls"><p>4 old URLs now show “Page not found”. <a href="http://localhost:8899/wp-j …
- `NoticesTest::test_a_bulk_edit_s_urls_notice_prints_once_on_the_pages_list_core_returns_to`: Failed asserting that '<div class="notice notice-warning is-dismissible hierarchy-guard-urls"><p>4 old URLs now show “Page not found”. <a href="http://localhost:8899/wp-j …
- `NoticesTest::test_restore_sentences`: Failed asserting that two arrays are identical.
- `RedirectsTabTest::test_delete_from_a_row_deletes_the_record_and_says_its_old_url_no_longer_redirects_through_it`: Failed asserting that two arrays are identical.
- `NoticesTest::test_with_nothing_handling_redirects_the_urls_notice_is_a_warning`: Failed asserting that '3 old URLs now show “Page not found”. <a href="http://localhost:8899/wp-json/hierarchy-guard/v1/changes/cdf3c63f-5f51-4837-9080-65bccd8e891e/csv?fo …
- `RedirectEditTest::test_the_edit_link_opens_the_view_behind_its_nonce`: One sentence a line, for each kind of target.
- `RedirectEditTest::test_a_top_level_entry_s_view_says_where_it_leads_and_keeps_it_when_left_empty`: The field starts empty, and is described by where it leads now too, with an example from its own old URL.
- … and 14 more.

Totals: `Tests: 230, Assertions: 730, Errors: 9, Failures: 31, Skipped: 2.`

Run: `npx wp-scripts test-unit-js src/common/test/change-notice.test.js src/common/test/copy.test.js src/list-table/test/bulk-edit.test.js src/list-table/test/quick-edit.test.js`

Expected: FAIL:

- `× warns when nothing handles redirects, and offers to set them up to those who can 3ms`
- `× leaves out the total when every subpage sits directly below 3ms`
- `× describes trashing the subpages too, and the fallback past the cap 1ms`
- `× sums a bulk selection, counting pages it did not preview 1ms`
- `× says “it” or “them” for the subpages a restore brings back, as many as there are 0ms`
- `× names the right that is missing, and what an error leads to 0ms`
- `× warns before a save changes subpage URLs 0ms`
- `× warns in Bulk Edit with the same next step 0ms`
- `× composes the classic confirmation from the save warning 0ms`
- `× says what handles the changed URLs, with a status to match 0ms`
- … and 14 more.

Totals: `Tests  12 failed | 44 passed (56)`

Mutant, to run once the tests pass, which shows `test_each_column_reads_as_the_spec_has_it` pins its behaviour: in `includes/HierarchyGuard/Admin/Redirects_Table.php`, replace

```php
		if ( 0 === (int) $item['holder'] ) {
			// A root-list record
```

with:

```php
		if ( false ) {
			// A root-list record
```

Expected: the test fails, as it did on the prototype:

```
RedirectsTabTest::test_each_column_reads_as_the_spec_has_it
Failed asserting that '<div class="wrap"><h1>Hierarchy Guard</h1><nav class="nav-tab-wrapper wp-clearfix" aria-label="Secondary menu"><a href="http://localhost:8899/wp-admin/options-general.php?page=hierarchy-guard" clas
Tests: 1, Assertions: 7, Failures: 1.
```

Then undo the mutant (`git checkout -- includes/HierarchyGuard/Admin/Redirects_Table.php`). Never commit it.

Mutant, to run once the tests pass, which shows `says “it” or “them” for the subpages a restore brings back, as many as there are` pins its behaviour: in `src/common/copy.js`, replace

```js
			page.descendants.count,
			'hierarchy-guard'
		);
		return sprintf( restores, page.title );
```

with:

```js
			2,
			'hierarchy-guard'
		);
		return sprintf( restores, page.title );
```

Expected: the Vitest case fails, as it did on the prototype:

```
× says “it” or “them” for the subpages a restore brings back, as many as there are 4ms
Tests  1 failed | 30 skipped (31)
FAIL  src/common/test/copy.test.js > the trash dialog > says “it” or “them” for the subpages a restore brings back, as many as there are
AssertionError: expected 'Restoring “Solo” restores them with t…' to be 'Restoring “Solo” restores it with its…' // Object.is equality
Expected: "Restoring “Solo” restores it with its original status."
Received: "Restoring “Solo” restores them with their original status."
```

Then undo the mutant (`git checkout -- src/common/copy.js`). Never commit it.

- [ ] **Step 3: Implement**

In `includes/HierarchyGuard/Admin/List_Table.php`, replace:

```php
			$parts[] = '<span class="screen-reader-text">' . esc_html__( 'Parent:', 'hierarchy-guard' ) . ' </span>' . $name;
		}
		if ( $count > 0 ) {
			$this->signal();
```

with:

```php
			$parts[] = '<span class="screen-reader-text">' . esc_html__( 'Parent page:', 'hierarchy-guard' ) . ' </span>' . $name;
		}
		if ( $count > 0 ) {
			$this->request_list_script();
```

In `includes/HierarchyGuard/Admin/List_Table.php`, replace:

```php
			$this->signal();
```

with:

```php
			$this->request_list_script();
```

In `includes/HierarchyGuard/Admin/List_Table.php`, replace:

```php
		$this->signal();
```

with:

```php
		$this->request_list_script();
```

In `includes/HierarchyGuard/Admin/List_Table.php`, replace:

```php
	private function signal(): void {
```

with:

```php
	private function request_list_script(): void {
```

In `includes/HierarchyGuard/Admin/List_Table.php`, replace:

```php
			do_action( 'hierarchy_guard_list_script' );
```

with:

```php
			do_action( self::SCRIPT ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- The plugin's own hook, by its constant: hierarchy_guard_list_script.
```

In `includes/HierarchyGuard/Admin/Meta_Box.php`, replace:

```php
					/* translators: %s: number of subpages. */
```

with:

```php
					/* translators: %s: number of subpages, such as 3 or 1,000+. */
```

In `includes/HierarchyGuard/Admin/Notices.php`, replace:

```php
		$message = esc_html( sprintf( _n( '%s old URL now shows “Page not found”.', '%s old URLs now show “Page not found”.', $n, 'hierarchy-guard' ), $shown ) ) . ' ' . $view;
```

with:

```php
		$message = esc_html( sprintf( _n( '%s old URL may now show “Page not found”.', '%s old URLs may now show “Page not found”.', $n, 'hierarchy-guard' ), $shown ) ) . ' ' . $view;
```

In `includes/HierarchyGuard/Admin/Notices.php`, replace:

```php
				do_action( 'hierarchy_guard_list_script' );
```

with:

```php
				do_action( List_Table::SCRIPT ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- The plugin's own hook, by its constant: hierarchy_guard_list_script.
```

In `includes/HierarchyGuard/Admin/Notices.php`, replace:

```php
			do_action( 'hierarchy_guard_list_script' );
```

with:

```php
			do_action( List_Table::SCRIPT ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- The plugin's own hook, by its constant: hierarchy_guard_list_script.
```

In `includes/HierarchyGuard/Admin/Notices.php`, replace:

```php
		do_action( 'hierarchy_guard_list_script' );
```

with:

```php
		do_action( List_Table::SCRIPT ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- The plugin's own hook, by its constant: hierarchy_guard_list_script.
```

In `includes/HierarchyGuard/Admin/Notices.php`, replace:

```php
			$out[] = sprintf( _n( 'The subpages of %s more page are still being worked on in the background.', 'The subpages of %s more pages are still being worked on in the background.', $rest, 'hierarchy-guard' ), number_format_i18n( $rest ) );
```

with:

```php
			$out[] = sprintf( _n( 'The subpages of %s more page are still being processed in the background.', 'The subpages of %s more pages are still being processed in the background.', $rest, 'hierarchy-guard' ), number_format_i18n( $rest ) );
```

In `includes/HierarchyGuard/Admin/Notices.php`, replace:

```php
					? __( 'The rest are still going to the Trash in the background.', 'hierarchy-guard' )
```

with:

```php
					/* translators: "The rest" are the subpages not yet moved to the Trash. */
					? __( 'The rest are still going to the Trash in the background.', 'hierarchy-guard' )
					/* translators: "The rest" are the subpages not yet moved. */
```

In `includes/HierarchyGuard/Admin/Notices.php`, replace:

```php
			$out[] = sprintf( _n( '%s subpage couldn’t be brought back and stayed where it is.', '%s subpages couldn’t be brought back and stayed where they are.', $counts['left'], 'hierarchy-guard' ), number_format_i18n( $counts['left'] ) );
```

with:

```php
			$out[] = sprintf( _n( '%s subpage couldn’t be restored and stayed where it is.', '%s subpages couldn’t be restored and stayed where they are.', $counts['left'], 'hierarchy-guard' ), number_format_i18n( $counts['left'] ) );
```

In `includes/HierarchyGuard/Admin/Redirect_Edit.php`, replace:

```php
	public const NONCE_BULK   = 'bulk-redirects';
```

with:

```php
	public const NONCE_BULK   = 'bulk-hierarchy_guard_redirects';
```

In `includes/HierarchyGuard/Admin/Redirect_Edit.php`, replace:

```php
			$help[] = esc_html__( 'Leave empty to keep this.', 'hierarchy-guard' );
			$help[] = esc_html__( 'Enter a URL to send these URLs somewhere else.', 'hierarchy-guard' );
		}
		/* translators: %s: the old URL, such as /about/team/. */
		$help[] = sprintf( esc_html__( 'A page on this site: the redirect follows it when it moves, and the URLs under %s go to the matching URLs under it.', 'hierarchy-guard' ), $in_code );
		/* translators: %s: the old URL, such as /about/team/. */
		$help[] = sprintf( esc_html__( 'Any other URL: only %s redirects, and this redirect no longer covers the URLs under it.', 'hierarchy-guard' ), $in_code );
		/* translators: %s: an example path, /about/team/. */
		$help[] = sprintf( esc_html__( 'A path on this site, such as %s, works as well as a full URL.', 'hierarchy-guard' ), '<code>/about/team/</code>' );
```

with:

```php
			/* translators: "them" are the URLs under the old URL, which go to the same pages at the top level. */
			$help[] = esc_html__( 'Leave this empty to keep sending them to the same page at the top level.', 'hierarchy-guard' );
			/* translators: "them" are the URLs under the old URL. */
			$help[] = esc_html__( 'Or enter a URL to send them somewhere else.', 'hierarchy-guard' );
		}
		/* translators: %s: the old URL, such as /about/team/. */
		$help[] = sprintf( esc_html__( 'If you enter a page on this site, the redirect follows that page when it moves, and URLs under %s go to the matching URLs under that page.', 'hierarchy-guard' ), $in_code );
		/* translators: %s: the old URL, such as /about/team/. */
		$help[] = sprintf( esc_html__( 'If you enter any other URL, only %s itself redirects there, and URLs under it are no longer redirected.', 'hierarchy-guard' ), $in_code );
		/* translators: %s: an example path, /about/team/. */
		$help[] = sprintf( esc_html__( 'You can enter a path on this site, such as %s, instead of a full URL.', 'hierarchy-guard' ), '<code>/about/team/</code>' );
```

In `includes/HierarchyGuard/Admin/Redirect_Edit.php`, replace:

```php
		/* translators: 1: an example old URL, /about/team/; 2: where it leads, /team/. */
		$now = Records::is_top_level( $record ) ? '<p id="hierarchy-guard-target-now">' . sprintf( esc_html__( 'Now redirects to the same page at the top level, for example %1$s to %2$s.', 'hierarchy-guard' ), '<code>/about/team/</code>', '<code>/team/</code>' ) . '</p>' : '';
		printf(
			'<tr><th scope="row"><label for="hierarchy-guard-target">%1$s</label></th><td>%2$s<input type="text" class="regular-text code" id="hierarchy-guard-target" name="target" value="%3$s" dir="ltr" aria-describedby="%5$shierarchy-guard-target-description"><p class="description" id="hierarchy-guard-target-description">%4$s</p></td></tr>',
			esc_html__( 'Redirect to', 'hierarchy-guard' ),
			$now, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
			esc_attr( $url ),
			implode( ' ', $help ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Each sentence is escaped above; the old URL is escaped inside its <code>.
			'' !== $now ? 'hierarchy-guard-target-now ' : ''
		);
		echo '<tr><th scope="row">' . esc_html__( 'Code', 'hierarchy-guard' ) . '</th><td><fieldset><legend class="screen-reader-text"><span>' . esc_html__( 'Code', 'hierarchy-guard' ) . '</span></legend>';
		printf( '<label><input type="radio" name="code" value="0" aria-describedby="hierarchy-guard-code-automatic-description" %1$s> %2$s</label><p class="description" id="hierarchy-guard-code-automatic-description">%3$s</p>', checked( 0, $code, false ), esc_html__( 'Automatic', 'hierarchy-guard' ), esc_html__( '301, or 302 while restoring a page from the Trash could undo the move.', 'hierarchy-guard' ) );
```

with:

```php
		$example = trailingslashit( $old ) . 'example/';
		/* translators: 1: the old URL, such as /about/; 2: a URL under it, such as /about/example/; 3: where that one leads, /example/. */
		$now = Records::is_top_level( $record ) ? '<p id="hierarchy-guard-target-now">' . sprintf( esc_html__( 'URLs under %1$s now go to the same page at the top level, for example %2$s to %3$s.', 'hierarchy-guard' ), $in_code, '<code>' . esc_html( $example ) . '</code>', '<code>/example/</code>' ) . '</p>' : '';
		printf(
			'<tr><th scope="row"><label for="hierarchy-guard-target">%1$s</label></th><td>%2$s<input type="text" class="regular-text code" id="hierarchy-guard-target" name="target" value="%3$s" dir="ltr" aria-describedby="%5$shierarchy-guard-target-description"><div id="hierarchy-guard-target-description"><p class="description">%4$s</p></div></td></tr>',
			esc_html__( 'Redirect to', 'hierarchy-guard' ),
			$now, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
			esc_attr( $url ),
			implode( '</p><p class="description">', $help ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Each sentence is escaped above; the old URL is escaped inside its <code>.
			'' !== $now ? 'hierarchy-guard-target-now ' : ''
		);
		echo '<tr><th scope="row">' . esc_html_x( 'Code', 'redirect code', 'hierarchy-guard' ) . '</th><td><fieldset><legend class="screen-reader-text"><span>' . esc_html_x( 'Code', 'redirect code', 'hierarchy-guard' ) . '</span></legend>';
		printf( '<label><input type="radio" name="code" value="0" aria-describedby="hierarchy-guard-code-automatic-description" %1$s> %2$s</label><p class="description" id="hierarchy-guard-code-automatic-description">%3$s</p>', checked( 0, $code, false ), esc_html_x( 'Automatic', 'redirect code', 'hierarchy-guard' ), esc_html__( 'Uses 301 (permanent), or 302 (temporary) while a restore from the Trash can still undo the move.', 'hierarchy-guard' ) );
```

In `includes/HierarchyGuard/Admin/Redirect_Edit.php`, replace:

```php
		$through = sprintf( esc_html__( '%s no longer redirects through it.', 'hierarchy-guard' ), '<code>' . esc_html( $record['old'] ) . '</code>' );
```

with:

```php
		$through = sprintf( esc_html__( 'Hierarchy Guard no longer redirects %s.', 'hierarchy-guard' ), '<code>' . esc_html( $record['old'] ) . '</code>' );
```

In `includes/HierarchyGuard/Admin/Redirect_Edit.php`, replace:

```php
		$through = _n( 'Its old URL no longer redirects through it.', 'Their old URLs no longer redirect through them.', $count, 'hierarchy-guard' );
```

with:

```php
		/* translators: "its" is the deleted redirect's, "their" the deleted redirects'. */
		$through = _n( 'Hierarchy Guard no longer redirects its old URL.', 'Hierarchy Guard no longer redirects their old URLs.', $count, 'hierarchy-guard' );
```

In `includes/HierarchyGuard/Admin/Redirects_Table.php`, replace:

```php
	public const TYPE_ARG = 'redirect_type';
```

with:

```php
	public const TYPE_ARG = 'hierarchy_guard_type';
```

In `includes/HierarchyGuard/Admin/Redirects_Table.php`, replace:

```php
				'singular' => 'redirect',
				'plural'   => 'redirects',
```

with:

```php
				'singular' => 'hierarchy_guard_redirect',
				'plural'   => 'hierarchy_guard_redirects',
```

In `includes/HierarchyGuard/Admin/Redirects_Table.php`, replace:

```php
			'http_code' => __( 'Code', 'hierarchy-guard' ),
```

with:

```php
			'http_code' => _x( 'Code', 'redirect code', 'hierarchy-guard' ),
```

In `includes/HierarchyGuard/Admin/Redirects_Table.php`, replace:

```php
		return self::old_label( $item, Redirect_Edit::edit_url( (string) $item['id'], $this->list ) );
```

with:

```php
		$label = self::old_label( $item, Redirect_Edit::edit_url( (string) $item['id'], $this->list ) );
		if ( 0 === (int) $item['holder'] ) {
			// A root-list record stands for every page that once sat under it, and nothing records which, so only a Delete removes it.
			$label .= '<p class="description">' . esc_html__( 'This redirect stays until you delete it, even after the pages under it are gone.', 'hierarchy-guard' ) . '</p>';
		}
		return $label;
```

In `includes/HierarchyGuard/Admin/Redirects_Table.php`, replace:

```php
		return $code > 0 ? (string) $code : esc_html__( 'Automatic', 'hierarchy-guard' );
```

with:

```php
		return $code > 0 ? (string) $code : esc_html_x( 'Automatic', 'redirect code', 'hierarchy-guard' );
```

In `includes/HierarchyGuard/Admin/Redirects_Table.php`, replace:

```php
	 * target; linked, as a row title, when a link is given.
```

with:

```php
	 * target, or as "The URLs under it" for a top-level root-list entry,
	 * which never redirects the old URL itself; linked, as a row title, when
	 * a link is given.
```

In `includes/HierarchyGuard/Admin/Redirects_Table.php`, replace:

```php
		}
		/* translators: %s: an old URL, such as /about/team/. */
```

with:

```php
		}
		if ( Records::is_top_level( $item ) ) {
			/* translators: %s: an old URL, such as /about/; its subpages are top-level pages now. */
			return sprintf( esc_html__( 'The URLs under %s', 'hierarchy-guard' ), $old );
		}
		/* translators: %s: an old URL, such as /about/team/. */
```

In `includes/HierarchyGuard/Admin/Redirects_Table.php`, replace:

```php
			case Records::STATUS_REFUSED:
				return __( 'Not active: the server can’t send this redirect', 'hierarchy-guard' );
			case Records::STATUS_OFF:
				return __( 'Not active: built-in redirects are off', 'hierarchy-guard' );
			case Records::STATUS_ACTIVE:
				return __( 'Active', 'hierarchy-guard' );
```

with:

```php
			case Records::STATUS_TOO_DEEP:
				return __( 'Not active: the URL has too many levels', 'hierarchy-guard' );
			case Records::STATUS_LOOP:
				/* translators: "it" is the redirect. */
				return __( 'Not active: it points back to its own old URL', 'hierarchy-guard' );
			case Records::STATUS_CHAINED:
				/* translators: "it" is the redirect. */
				return __( 'Not active: it points to another redirected URL', 'hierarchy-guard' );
			case Records::STATUS_OFF:
				return __( 'Not active: built-in redirects are off', 'hierarchy-guard' );
			case Records::STATUS_PLAIN:
				return __( 'Not active: permalinks are set to Plain', 'hierarchy-guard' );
			case Records::STATUS_ACTIVE:
				return _x( 'Active', 'redirect status', 'hierarchy-guard' );
```

In `includes/HierarchyGuard/Admin/Settings_Page.php`, replace:

```php
	 * The Redirects field, for "Set up redirects" links.
```

with:

```php
	 * The Built-in redirects field, for "Set up redirects" links.
```

In `includes/HierarchyGuard/Admin/Settings_Page.php`, replace:

```php
		$view = $this->edit->is_editing() ? __( 'Edit redirect', 'hierarchy-guard' ) : __( 'Redirects', 'hierarchy-guard' );
```

with:

```php
		$view = $this->edit->is_editing() ? __( 'Edit redirect', 'hierarchy-guard' ) : _x( 'Redirects', 'settings tab', 'hierarchy-guard' );
```

In `includes/HierarchyGuard/Admin/Settings_Page.php`, replace:

```php
		add_settings_field( 'hierarchy_guard_serve_redirects', __( 'Redirects', 'hierarchy-guard' ), [ $this, 'render_serve_redirects' ], self::SLUG, self::SECTION );
```

with:

```php
		add_settings_field( 'hierarchy_guard_serve_redirects', __( 'Built-in redirects', 'hierarchy-guard' ), [ $this, 'render_serve_redirects' ], self::SLUG, self::SECTION );
```

In `includes/HierarchyGuard/Admin/Settings_Page.php`, replace:

```php
			$older    = __( 'Older redirects appear here once it’s done.', 'hierarchy-guard' );
```

with:

```php
			/* translators: "it" is the list of recorded redirects. */
			$older = __( 'Older redirects will appear when it’s finished.', 'hierarchy-guard' );
```

In `includes/HierarchyGuard/Admin/Settings_Page.php`, replace:

```php
			/* translators: %s: URL of the Redirects field on the Settings tab. */
			$on = sprintf( __( 'Turn them on in the <a href="%s">Settings tab</a>.', 'hierarchy-guard' ), esc_url( self::redirects_url() ) );
```

with:

```php
			/* translators: %s: URL of the Built-in redirects field on the Settings tab. */
			$on = sprintf( __( 'Turn on built-in redirects in the <a href="%s">Settings tab</a>.', 'hierarchy-guard' ), esc_url( self::redirects_url() ) );
```

In `includes/HierarchyGuard/Admin/Settings_Page.php`, replace:

```php
				$recorded = sprintf( __( 'These are the redirects Hierarchy Guard recorded; its notices after each change offer to add them in %s.', 'hierarchy-guard' ), $name );
			} else {
				$active   = __( 'A redirect plugin is active.', 'hierarchy-guard' );
				$recorded = __( 'These are the redirects Hierarchy Guard recorded; its notices after each change offer to add them there.', 'hierarchy-guard' );
```

with:

```php
				$recorded = sprintf( __( 'After each URL change, Hierarchy Guard’s notice offers to add these redirects to %s.', 'hierarchy-guard' ), $name );
			} else {
				$active   = __( 'A redirect plugin is active.', 'hierarchy-guard' );
				$recorded = __( 'After each URL change, Hierarchy Guard’s notice offers to add these redirects to that plugin.', 'hierarchy-guard' );
```

In `includes/HierarchyGuard/Admin/Settings_Page.php`, replace:

```php
		$checks = __( 'Status checks posts and pages at each old URL.', 'hierarchy-guard' );
		$others = __( 'Other WordPress URLs, such as archives or other plugins’ rules, aren’t checked.', 'hierarchy-guard' );
		$shared = __( 'When two recorded redirects share an old URL, at most one is served, and both can read Active.', 'hierarchy-guard' );
```

with:

```php
		$checks = __( 'The Status column only checks whether a post or page now uses each old URL.', 'hierarchy-guard' );
		/* translators: "It" is the Status column. */
		$others = __( 'It doesn’t check archives or other plugins’ redirect rules.', 'hierarchy-guard' );
		$shared = __( 'If two recorded redirects have the same old URL, only one is served, though both may show Active.', 'hierarchy-guard' );
```

In `includes/HierarchyGuard/Admin/Settings_Page.php`, replace:

```php
			'redirects' => __( 'Redirects', 'hierarchy-guard' ),
```

with:

```php
			'redirects' => _x( 'Redirects', 'settings tab', 'hierarchy-guard' ),
```

In `includes/HierarchyGuard/Admin/Settings_Page.php`, replace:

```php
			Settings::POLICY_TRASH_CHILDREN => __( 'Trash the subpages too', 'hierarchy-guard' ),
```

with:

```php
			Settings::POLICY_TRASH_CHILDREN => __( 'Trash subpages too', 'hierarchy-guard' ),
```

In `includes/HierarchyGuard/Admin/Settings_Page.php`, replace:

```php
			Settings::POLICY_TRASH_CHILDREN => __( 'Restoring their parent restores them with their original status.', 'hierarchy-guard' ),
			/* translators: "Their" are the subpages of the page being trashed. */
```

with:

```php
			Settings::POLICY_TRASH_CHILDREN => __( 'Restoring their parent restores them with their original status.', 'hierarchy-guard' ),
			/* translators: "Their" and "them" are the subpages of the page being trashed. */
```

In `includes/HierarchyGuard/Admin/Settings_Page.php`, replace:

```php
		$help = __( 'Used when there’s no chance to ask, for example in the editor, the REST API, or WP-CLI.', 'hierarchy-guard' ) . ' ' . __( 'The Pages list asks each time.', 'hierarchy-guard' );
```

with:

```php
		$help = __( 'Applies when a page is trashed from the editor, the REST API or WP-CLI.', 'hierarchy-guard' ) . ' ' . __( 'Trashing from the Pages list asks each time.', 'hierarchy-guard' );
```

In `includes/HierarchyGuard/Admin/Settings_Page.php`, replace:

```php
			$help = sprintf( __( '%s is active and can handle these.', 'hierarchy-guard' ), $name );
		} elseif ( null !== $provider ) {
			$help = __( 'A redirect plugin is active and can handle these.', 'hierarchy-guard' );
```

with:

```php
			$help = sprintf( __( '%s is active and can serve these redirects instead.', 'hierarchy-guard' ), $name );
		} elseif ( null !== $provider ) {
			$help = __( 'A redirect plugin is active and can serve these redirects instead.', 'hierarchy-guard' );
```

In `includes/HierarchyGuard/Admin/Tree_View.php`, replace:

```php
			esc_html__( 'Tree', 'hierarchy-guard' )
```

with:

```php
			esc_html_x( 'Tree', 'list view', 'hierarchy-guard' )
```

In `includes/HierarchyGuard/REST/Changes_Controller.php`, replace:

```php
				_n( 'They are kept for %s minute.', 'They are kept for %s minutes.', $minutes, 'hierarchy-guard' ),
```

with:

```php
				_n( 'Each change’s redirects are listed for %s minute.', 'Each change’s redirects are listed for %s minutes.', $minutes, 'hierarchy-guard' ),
```

In `includes/HierarchyGuard/Redirects/Records.php`, replace:

```php
	public const STATUS_REFUSED     = 'refused';
	public const STATUS_OFF         = 'off';
```

with:

```php
	public const STATUS_TOO_DEEP    = 'too_deep';
	public const STATUS_LOOP        = 'loop';
	public const STATUS_CHAINED     = 'chained';
	public const STATUS_OFF         = 'off';
	public const STATUS_PLAIN       = 'plain';
```

In `includes/HierarchyGuard/Redirects/Records.php`, replace:

```php
	 * - refused: the server can't send it, whether built-in redirects are on
	 *   or off: a path deeper than its segment cap, which it refuses before
	 *   it looks, counting for a top-level root-list entry the segment of the
	 *   URLs under it; a URL target at the old URL itself; or a URL target
	 *   that is another old URL this plugin redirects, which the server
	 *   never sends (Server::redirected());
	 * - off: built-in redirects are off, or the site has plain permalinks;
```

with:

```php
	 * - too deep, loop, chained: the server can't send it, whether built-in
	 *   redirects are on or off: a path deeper than its segment cap, which it
	 *   refuses before it looks, counting for a top-level root-list entry the
	 *   segment of the URLs under it; a URL target at the old URL itself; or
	 *   a URL target that is another old URL this plugin redirects, which the
	 *   server never sends (Server::redirected());
	 * - off: built-in redirects are off;
	 * - plain: the site has plain permalinks, under which nothing is served;
```

In `includes/HierarchyGuard/Redirects/Records.php`, replace:

```php
	public function statuses( array $rows ): array {
		$serving  = Settings::serve_redirects() && '' !== (string) get_option( 'permalink_structure' );
```

with:

```php
	public function statuses( array $rows ): array {
```

In `includes/HierarchyGuard/Redirects/Records.php`, replace:

```php
			} elseif (
				// A top-level root-list entry serves only the URLs one segment under it.
				self::too_deep( $type, $path, self::is_top_level( $row ) ? 1 : 0 )
				|| self::loops( $type, $path, $url )
				|| ( '' !== $url && Server::is_here( $url ) && Server::redirected( $resolver, $url ) )
			) {
				$status = self::STATUS_REFUSED;
			} elseif ( ! $serving ) {
				$status = self::STATUS_OFF;
```

with:

```php
			} elseif ( self::too_deep( $type, $path, self::is_top_level( $row ) ? 1 : 0 ) ) {
				// A top-level root-list entry serves only the URLs one segment under it.
				$status = self::STATUS_TOO_DEEP;
			} elseif ( self::loops( $type, $path, $url ) ) {
				$status = self::STATUS_LOOP;
			} elseif ( '' !== $url && Server::is_here( $url ) && Server::redirected( $resolver, $url ) ) {
				$status = self::STATUS_CHAINED;
			} elseif ( ! Settings::serve_redirects() ) {
				$status = self::STATUS_OFF;
			} elseif ( '' === (string) get_option( 'permalink_structure' ) ) {
				$status = self::STATUS_PLAIN;
```

In `src/common/copy.js`, replace:

```js
 * Every sentence the scripts show, in one place so it can be tested.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
```

with:

```js
 * The scripts' sentences that depend on counts, policies or redirect state,
 * kept here so they can be tested.
 */
import { __, _n, _x, sprintf } from '@wordpress/i18n';
```

In `src/common/copy.js`, replace:

```js
		trash_children: __(
			'The subpages go to the Trash too, and come back with their statuses if you restore their parent.',
			'hierarchy-guard'
		),
```

with:

```js
		trash_children:
			__( 'The subpages go to the Trash too.', 'hierarchy-guard' ) +
			' ' +
			__(
				'Restoring their parent restores them with their original status.',
				'hierarchy-guard'
			),
```

In `src/common/copy.js`, replace:

```js
	let tail;
	if ( 'trash_children' === policy && true === page.fallback ) {
		tail = sprintf(
			/* translators: %s: the most subpages trashed together with a page, such as 1,000. */
			__(
				'That is more than %s, so they’ll move up one level instead of going to the Trash.',
				'hierarchy-guard'
			),
			formatCount( cap )
		);
	} else if ( 'trash_children' === policy ) {
		tail = sprintf(
			/* translators: %s: page title. */
			_n(
				'It goes to the Trash too, and comes back with its status if you restore “%s”.',
				'They go to the Trash too, and come back with their statuses if you restore “%s”.',
				all,
				'hierarchy-guard'
			),
			page.title
		);
	} else if ( 'keep' === policy ) {
		tail = _n(
			'It stays where it is, and its URL changes until you move it.',
			'They stay where they are, and their URLs change until you move them.',
			children,
			'hierarchy-guard'
		);
	} else if ( page.to ) {
		tail = sprintf(
			/* translators: %s: title of the page the subpages move under. */
			_n(
				'It will move up under “%s” and get a new URL.',
				'They’ll move up under “%s” and get new URLs.',
				children,
				'hierarchy-guard'
			),
			page.to.title
		);
	} else {
		tail = _n(
			'It will become a top-level page and get a new URL.',
			'They’ll become top-level pages and get new URLs.',
			children,
			'hierarchy-guard'
		);
	}
	return head + ' ' + tail;
```

with:

```js
	// Each sentence is passed as an argument, so the minifier keeps its translator comment beside it.
	const parts = [ head ];
	if ( 'trash_children' === policy && true === page.fallback ) {
		parts.push(
			sprintf(
				/* translators: %s: the most subpages trashed together with a page, such as 1,000; "they" are the page's subpages. */
				__(
					'That’s more than %s, so they’ll move up one level instead of going to the Trash.',
					'hierarchy-guard'
				),
				formatCount( cap )
			)
		);
	} else if ( 'trash_children' === policy ) {
		parts.push(
			/* translators: "It" or "They" are the subpages of the page being trashed. */
			_n(
				'It goes to the Trash too.',
				'They go to the Trash too.',
				all,
				'hierarchy-guard'
			),
			sprintf(
				/* translators: %s: page title; "it" or "them" are its subpages. */
				_n(
					'Restoring “%s” restores it with its original status.',
					'Restoring “%s” restores them with their original status.',
					all,
					'hierarchy-guard'
				),
				page.title
			)
		);
	} else if ( 'keep' === policy ) {
		parts.push(
			/* translators: "It" or "They" are the subpages of the page being trashed. */
			_n(
				'It stays where it is, and its URL changes until you move it.',
				'They stay where they are, and their URLs change until you move them.',
				children,
				'hierarchy-guard'
			)
		);
	} else if ( page.to ) {
		parts.push(
			sprintf(
				/* translators: %s: title of the page the subpages move under; "It" or "They" are the subpages. */
				_n(
					'It’ll move up under “%s” and get a new URL.',
					'They’ll move up under “%s” and get new URLs.',
					children,
					'hierarchy-guard'
				),
				page.to.title
			)
		);
	} else {
		parts.push(
			/* translators: "It" or "They" are the subpages of the page being trashed. */
			_n(
				'It’ll become a top-level page and get a new URL.',
				'They’ll become top-level pages and get new URLs.',
				children,
				'hierarchy-guard'
			)
		);
	}
	return parts.join( ' ' );
```

In `src/common/copy.js`, replace:

```js
			: __( 'Trash the subpages too', 'hierarchy-guard' );
```

with:

```js
			: __( 'Trash subpages too', 'hierarchy-guard' );
```

In `src/common/copy.js`, replace:

```js
		return page
			? sprintf(
					/* translators: %s: page title. */
					__(
						'They come back with their statuses if you restore “%s”.',
						'hierarchy-guard'
					),
					page.title
				)
			: __(
					'They come back with their statuses if you restore their parent.',
					'hierarchy-guard'
				);
	}
	if ( 'keep' === policy ) {
		return page && keptUrl( page )
			? __(
					'Their URLs change until you move them, for example <from /> becomes <to />.',
					'hierarchy-guard'
				)
			: __(
					'Their URLs will contain “__trashed” until you move them.',
					'hierarchy-guard'
				);
	}
	if ( page && page.example ) {
		return __(
			'Their URLs change, for example <from /> becomes <to />.',
			'hierarchy-guard'
		);
	}
	return __( 'Their URLs change.', 'hierarchy-guard' );
```

with:

```js
		if ( ! page ) {
			/* translators: "Their parent" is the page being trashed; "them" are its subpages. */
			const any = __(
				'Restoring their parent restores them with their original status.',
				'hierarchy-guard'
			);
			return any;
		}
		/* translators: %s: page title; "it" or "them" are its subpages. */
		const restores = _n(
			'Restoring “%s” restores it with its original status.',
			'Restoring “%s” restores them with their original status.',
			page.descendants.count,
			'hierarchy-guard'
		);
		return sprintf( restores, page.title );
	}
	// Passed as an argument, as in trashSentence(), for the translator comments.
	const sentence = [];
	if ( 'keep' === policy && page && keptUrl( page ) ) {
		sentence.push(
			/* translators: "Their" and "them" are the subpages; <from /> and <to /> are replaced by URLs. */
			__(
				'Their URLs change until you move them, for example <from /> becomes <to />.',
				'hierarchy-guard'
			)
		);
	} else if ( 'keep' === policy ) {
		sentence.push(
			/* translators: "Their" and "them" are the subpages of the page being trashed. */
			__(
				'Their URLs will contain “__trashed” until you move them.',
				'hierarchy-guard'
			)
		);
	} else if ( page && page.example ) {
		sentence.push(
			/* translators: "Their" are the subpages; <from /> and <to /> are replaced by URLs. */
			__(
				'Their URLs change, for example <from /> becomes <to />.',
				'hierarchy-guard'
			)
		);
	} else {
		sentence.push(
			/* translators: "Their" are the subpages of the page being trashed. */
			__( 'Their URLs change.', 'hierarchy-guard' )
		);
	}
	return sentence[ 0 ];
```

In `src/common/copy.js`, replace:

```js
			? __( 'You can’t delete some of the subpages.', 'hierarchy-guard' )
			: __( 'You can’t edit some of the subpages.', 'hierarchy-guard' );
	return (
		first + ' ' + __( 'They’ll stay where they are.', 'hierarchy-guard' )
```

with:

```js
			? __(
					'You can’t move some of the subpages to the Trash.',
					'hierarchy-guard'
				)
			: __( 'You can’t edit some of the subpages.', 'hierarchy-guard' );
	return (
		first +
		' ' +
		/* translators: "They" are the subpages the user can't change. */
		__( 'They’ll stay where they are.', 'hierarchy-guard' )
```

In `src/common/copy.js`, replace:

```js
	const outcomes = {
```

with:

```js
	const outcomes = {
		/* translators: "they" are the subpages of the page being trashed. */
```

In `src/common/copy.js`, replace:

```js
		),
		trash_children: __(
```

with:

```js
		),
		/* translators: "they" are the subpages of the page being trashed. */
		trash_children: __(
```

In `src/common/copy.js`, replace:

```js
			'hierarchy-guard'
		),
		keep: __(
```

with:

```js
			'hierarchy-guard'
		),
		/* translators: "they" are the subpages of the page being trashed. */
		keep: __(
```

In `src/common/copy.js`, replace:

```js
				'You’ll get the redirects to add after saving.',
```

with:

```js
				'After you save, you’ll see the redirects to add.',
```

In `src/common/copy.js`, replace:

```js
			'Saving changes this page’s URL and the URL of %s subpage.',
			'Saving changes this page’s URL and the URLs of %s subpages.',
```

with:

```js
			'Saving will change the URL of this page and of its %s subpage.',
			'Saving will change the URLs of this page and of its %s subpages.',
```

In `src/common/copy.js`, replace:

```js
			'%s old URL now shows “Page not found”.',
			'%s old URLs now show “Page not found”.',
```

with:

```js
			'%s old URL may now show “Page not found”.',
			'%s old URLs may now show “Page not found”.',
```

In `src/common/copy.js`, replace:

```js
		trash: __( 'Trash', 'hierarchy-guard' ),
```

with:

```js
		trash: _x( 'Trash', 'verb', 'hierarchy-guard' ),
```

In `src/common/copy.js`, replace:

```js
		view: __( 'View', 'hierarchy-guard' ),
```

with:

```js
		view: _x( 'View', 'verb', 'hierarchy-guard' ),
```

In `src/common/redirects-modal.js`, replace:

```js
import { __, sprintf } from '@wordpress/i18n';
```

with:

```js
import { __, _x, sprintf } from '@wordpress/i18n';
```

In `src/common/redirects-modal.js`, replace:

```js
			'Nothing on this site redirects these old URLs yet.',
```

with:

```js
			'Nothing on this site redirects these old URLs to their new pages yet.',
```

In `src/common/redirects-modal.js`, replace:

```js
					? __( 'Redirects', 'hierarchy-guard' )
```

with:

```js
					? _x( 'Redirects', 'modal title', 'hierarchy-guard' )
```

In `src/editor/panel.js`, replace:

```js
 * The Hierarchy plugin for the block editor: a warning under the Summary
 * before a save changes subpage URLs, a notice with that change's redirects
```

with:

```js
 * The Hierarchy plugin for the block editor: a warning in the document
 * sidebar's status area before a save changes subpage URLs, a notice with that change's redirects
```

In `src/list-table/quick-edit-words.js`, replace:

```js
import { quickEditWarning } from '../common/copy';

/**
 * The warning in the Quick Edit form of a page whose subpages' URLs a save
 * would change.
 *
 * @param {{count: number, capped: boolean}} descendants The page's subpages at every level.
 * @return {string} Sentence.
 */
export function warningText( descendants ) {
	return quickEditWarning( descendants.count, descendants.capped );
```

with:

```js
import config from '../common/config';
import { saveWarning } from '../common/copy';

/**
 * The warning in the Quick Edit form of a page whose subpages' URLs a save
 * would change, with what happens to the old URLs, as in the editors.
 *
 * @param {{count: number, capped: boolean}} descendants The page's subpages at every level.
 * @return {string} Two sentences.
 */
export function warningText( descendants ) {
	return saveWarning( descendants.count, descendants.capped, config.serving );
```

In `src/list-table/trash-dialog.js`, replace:

```js
						{ __( 'Other options', 'hierarchy-guard' ) }
```

with:

```js
						{ __(
							'Change what happens to the subpages',
							'hierarchy-guard'
						) }
```

In `README.md`, replace:

```markdown
Shows the parent and child structure of pages in wp-admin, warns before a slug, parent or trash change breaks subpage URLs, and records every old URL so it can reach the page it leads to now. A page published, moved or renamed onto a path another page used to have takes that URL over, and the old URLs of the earlier page's subpages under it stop redirecting. Built-in redirects are off by default: with a redirect plugin active, Hierarchy Guard lists the redirects to add there; with none, turn on Redirects in Settings › Hierarchy Guard.
```

with:

```markdown
Shows the parent and child structure of pages in wp-admin, warns before a slug, parent or trash change breaks subpage URLs, and records every old URL so it can reach the page it leads to now. A page published, moved or renamed onto a path another page used to have takes that URL over, and the old URLs of the earlier page's subpages under it stop redirecting. Built-in redirects are off by default: with a redirect plugin active, Hierarchy Guard lists the redirects to add there; with none, turn on Built-in redirects in Settings › Hierarchy Guard.
```

In `README.md`, replace:

```markdown
  On the Pages list and in the block editor, "View redirects" opens a list of each old URL of that change and its new one, which links to the detected redirect plugin, downloads a CSV, or copies the list as text. The CSV is in the plugin's own import layout for Redirection, Safe Redirect Manager (which imports it from WP-CLI), Yoast SEO Premium, Rank Math (whose PRO version imports it) and Simple 301 Redirects (which imports every row as a 301); other redirect plugins get a plain source, target and code file. Without JavaScript the link downloads the CSV. A CSV row's code is the one the plugin's own redirect rule chooses (before filters): 302 while restoring a page from the trash could still undo the move its redirect follows, and 301 otherwise. The list stays available for 30 minutes, until the user's next change replaces it, or until the page a save moved, or a trashed parent whose subpages moved up, is deleted for good, and a trash's list covers only its pages still in the Trash. If another user then trashes a subpage that trash moved, that subpage's own subpages are left out of the first trash's list, though with Redirects on they keep redirecting. Old URLs from earlier changes, or from pages deleted for good, are not listed again, and with Redirects on they keep redirecting.
- **Settings › Hierarchy Guard.** The Settings tab holds the supported post types, the trash policy and the switch for built-in redirects. The Redirects tab lists every redirect Hierarchy Guard recorded, 20 to a page in old URL order, with where each one leads, its code and whether it is served, as far as its status checks tell; search finds them by old URL. Status checks posts and pages at each old URL. Other WordPress URLs, such as archives or other plugins’ rules, aren’t checked. When two recorded redirects share an old URL, at most one is served, and both can read Active. Edit points one at another published page of its post type, which it then follows along with the URLs under it, or at any other http(s) URL, which redirects only its exact old URL and is refused when it is another old URL Hierarchy Guard recorded (the server never sends one there, so redirects can't send each other round), and sets the code to 301, 302 or automatic; a path on this site, such as `/about/team/`, works as well as a full URL. Delete removes it, from its row, the Edit view or in bulk, after asking; without JavaScript it deletes without asking, as core does for terms. Redirects can't be added by hand: that is a redirect plugin's job. With more than 1,000 recorded redirects the tab lists the newest 1,000, plus every old parent URL whose subpages became top level, and a search for a full old URL finds any other. Under plain permalinks nothing is served, and the tab says so.
- **Editor.** The block editor warns under the page's Summary before a save changes subpage URLs. Its Hierarchy panel shows the parent pages and the subpages, where the page is now. The classic editor shows the same in a box, and asks before saving such a change.

## Trashing a page with subpages

By default, trashing a page moves its subpages up one level, so they don't end up under a `__trashed` URL, and records their old URLs. With Redirects on, visitors on an old URL reach the new one. While the page is in the trash those redirects are temporary, so browsers don't keep them after a restore. The Settings tab of Settings › Hierarchy Guard can trash the subpages too, or leave them where they are. A subpage the user who trashed the page can't move or trash stays under it. Restoring the page, whether by Undo, Restore, or a status change over the REST API or WP-CLI, moves the subpages back, and those trashed with it come back with the status they had.
```

with:

```markdown
  On the Pages list and in the block editor, "View redirects" opens a list of each old URL of that change and its new one, which links to the detected redirect plugin, downloads a CSV, or copies the list as text. The CSV is in the plugin's own import layout for Redirection, Safe Redirect Manager (which imports it from WP-CLI), Yoast SEO Premium, Rank Math (whose PRO version imports it) and Simple 301 Redirects (which imports every row as a 301); other redirect plugins get a plain source, target and code file. Without JavaScript the link downloads the CSV. A CSV row's code is the one the plugin's own redirect rule chooses (before filters): 302 while restoring a page from the trash could still undo the move its redirect follows, and 301 otherwise. The list stays available for 30 minutes, until the user's next change replaces it, or until the page a save moved, or a trashed parent whose subpages moved up, is deleted for good, and a trash's list covers only its pages still in the Trash. If another user then trashes a subpage that trash moved, that subpage's own subpages are left out of the first trash's list, though with built-in redirects on they keep redirecting. Old URLs from earlier changes, or from pages deleted for good, are not listed again, and with built-in redirects on they keep redirecting.
- **Settings › Hierarchy Guard.** The Settings tab holds the supported post types, the trash policy and the switch for built-in redirects. The Redirects tab lists every redirect Hierarchy Guard recorded, 20 to a page in old URL order, with where each one leads, its code and whether it is served, as far as its status checks tell; search finds them by old URL. Status checks posts and pages at each old URL. Other WordPress URLs, such as archives or other plugins’ rules, aren’t checked. When two recorded redirects share an old URL, at most one is served, and both can read Active. Edit points one at another published page of its post type, which it then follows along with the URLs under it, or at any other http(s) URL, which redirects only its exact old URL and is refused when it is another old URL Hierarchy Guard recorded (the server never sends one there, so redirects can't send each other round), and sets the code to 301, 302 or automatic; a path on this site, such as `/about/team/`, works as well as a full URL. Delete removes it, from its row, the Edit view or in bulk, after asking; without JavaScript it deletes without asking, as core does for terms. Redirects can't be added by hand: that is a redirect plugin's job. With more than 1,000 recorded redirects the tab lists the newest 1,000, plus every old parent URL whose subpages became top level, and a search for a full old URL finds any other. Under plain permalinks nothing is served, and the tab says so.
- **Editor.** The block editor shows a warning in the page sidebar, below the Parent setting, before a save changes subpage URLs. Its Hierarchy panel shows the parent pages and the subpages, where the page is now. The classic editor shows the same in a box, and asks before saving such a change.

## Trashing a page with subpages

By default, trashing a page moves its subpages up one level, so they don't end up under a `__trashed` URL, and records their old URLs. With built-in redirects on, visitors on an old URL reach the new one. While the page is in the trash those redirects are temporary, so browsers don't keep them after a restore. The Settings tab of Settings › Hierarchy Guard can trash the subpages too, or leave them where they are. A subpage the user who trashed the page can't move or trash stays under it. Restoring the page, whether by Undo, Restore, or a status change over the REST API or WP-CLI, moves the subpages back, and those trashed with it come back with the status they had.
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `npm run test:php -- --filter 'ChangesControllerTest|ListTableTest|NoticesTest|RecordsTest|RedirectEditTest|RedirectsTabTest|SettingsPageTest'`

Expected: `OK`.

Run: `npx wp-scripts test-unit-js src/common/test/change-notice.test.js src/common/test/copy.test.js src/list-table/test/bulk-edit.test.js src/list-table/test/quick-edit.test.js`

Expected: every test passes.

Run: `npm run test:php`, `npm run test:php:multisite` and `npm run test:js`

Expected: `Tests: 1019, Assertions: 4522, Skipped: 6.` (single site), `OK (1019 tests, 4562 assertions)` (multisite), and Vitest `Tests 186 passed (186)`.

- [ ] **Step 5: Lint and build**

Run: `composer lint && npm run lint:js && npm run lint:style && npm run build`

Expected: every command exits 0.

- [ ] **Step 6: Commit**

```bash
git add README.md includes/HierarchyGuard/Admin/List_Table.php includes/HierarchyGuard/Admin/Meta_Box.php includes/HierarchyGuard/Admin/Notices.php includes/HierarchyGuard/Admin/Redirect_Edit.php includes/HierarchyGuard/Admin/Redirects_Table.php includes/HierarchyGuard/Admin/Settings_Page.php includes/HierarchyGuard/Admin/Tree_View.php includes/HierarchyGuard/REST/Changes_Controller.php includes/HierarchyGuard/Redirects/Records.php src/common/copy.js src/common/redirects-modal.js src/common/test/change-notice.test.js src/common/test/copy.test.js src/editor/panel.js src/list-table/quick-edit-words.js src/list-table/test/bulk-edit.test.js src/list-table/test/quick-edit.test.js src/list-table/trash-dialog.js tests/phpunit/ChangesControllerTest.php tests/phpunit/ListTableTest.php tests/phpunit/NoticesTest.php tests/phpunit/RecordsTest.php tests/phpunit/RedirectEditTest.php tests/phpunit/RedirectsTabTest.php tests/phpunit/SettingsPageTest.php
git commit -m "fix: the copy pass: plain one-sentence strings, a status per cause, one name for built-in redirects, and context for short labels"
```

---

### Task 15: A type's lists and parents use its own labels; the Post types field says messages speak of pages

**Findings:** 1.2, and the plan audits' U-7, U-8 and U-5 ("(private)")

- The audit put per-type labels in a later release; the owner included 1.2 in 1.0 and kept this approach ("labels only, as built"). The places that have a matching label in `get_post_type_object()->labels` use it: the breadcrumb's link back ("All Pages" becomes the type's `all_items`) and the screen-reader "Parent Page:" before a row's parent (`parent_item_colon`). A search's trail of several parents keeps "Parent pages:", since the type's label names one.
- Core fills both labels for every hierarchical type, so a type that registers only its `name` and `singular_name` shows core's defaults: "Parent Page:" and its menu name ("Docs") as the link back; a test pins that case. `Post_Types::label()`'s fallback is for a type that is no longer registered.
- Sentences such as "Saving will change the URLs of this page and of its 12 subpages." keep "page" and "subpage": core has no per-type label to build them from. While a type other than Pages is listed, the Post types field says "Hierarchy Guard’s messages say “page” and “subpage” for every post type."
- "(private)" takes a context, "title of a page the user can’t read", for gender agreement.

**Files:**
- Modify: `includes/HierarchyGuard/Admin/List_Table.php`
- Modify: `includes/HierarchyGuard/Admin/Settings_Page.php`
- Modify: `includes/HierarchyGuard/Overview.php`
- Modify: `includes/HierarchyGuard/Post_Types.php`
- Test (modify): `tests/phpunit/HierarchyControllerTest.php`
- Test (modify): `tests/phpunit/ListTableTest.php`
- Test (modify): `tests/phpunit/SettingsPageTest.php`

**Interfaces:**
- Produces: `Post_Types::label( string $type, string $label, string $fallback ): string`, the type's label by name, or the fallback when the type or label is missing.

- [ ] **Step 1: Write the failing tests**

In `tests/phpunit/HierarchyControllerTest.php`, replace:

```php
		$this->assertSame( [ '(private page)' ], array_column( $data['ancestors'], 'title' ) );
		$this->assertArrayNotHasKey( 'status', $data['ancestors'][0], 'Nothing reads it, and the user may not read the ancestor.' );
		$this->assertSame( '(private page)', $data['trash_to']['title'] );
```

with:

```php
		$this->assertSame( [ '(private)' ], array_column( $data['ancestors'], 'title' ) );
		$this->assertArrayNotHasKey( 'status', $data['ancestors'][0], 'Nothing reads it, and the user may not read the ancestor.' );
		$this->assertSame( '(private)', $data['trash_to']['title'] );
```

In `tests/phpunit/ListTableTest.php`, replace:

```php

	public function test_the_column_comes_after_the_title(): void {
```

with:

```php

	public function test_another_type_s_list_reads_its_own_labels(): void {
		register_post_type(
			'hg_book',
			[
				'public'       => true,
				'hierarchical' => true,
				'labels'       => [
					'all_items'         => 'All Books',
					'parent_item_colon' => 'Parent Book:',
				],
			]
		);
		Post_Types::reset();
		$shelf   = self::factory()->post->create(
			[
				'post_type'  => 'hg_book',
				'post_title' => 'Shelf',
			]
		);
		$chapter = self::factory()->post->create(
			[
				'post_type'   => 'hg_book',
				'post_title'  => 'Chapter',
				'post_parent' => $shelf,
			]
		);
		$this->screen_rows( [ $chapter ] );
		$_GET[ List_Table::QUERY_VAR ] = (string) $shelf;

		$cell = $this->cell( $chapter );
		ob_start();
		$this->table->filter_controls( 'hg_book' );
		$controls = (string) ob_get_clean();
		unregister_post_type( 'hg_book' );
		Post_Types::reset();

		$this->assertStringContainsString( '<span class="screen-reader-text">Parent Book: </span>', $cell );
		$this->assertStringContainsString( '>All Books</a>', $controls );
	}

	public function test_a_type_that_names_itself_only_gets_core_s_default_labels(): void {
		register_post_type(
			'hg_doc',
			[
				'public'       => true,
				'hierarchical' => true,
				'labels'       => [
					'name'          => 'Docs',
					'singular_name' => 'Doc',
				],
			]
		);
		Post_Types::reset();
		$guide = self::factory()->post->create(
			[
				'post_type'  => 'hg_doc',
				'post_title' => 'Guide',
			]
		);
		$step  = self::factory()->post->create(
			[
				'post_type'   => 'hg_doc',
				'post_title'  => 'Step',
				'post_parent' => $guide,
			]
		);
		$this->screen_rows( [ $step ] );
		$_GET[ List_Table::QUERY_VAR ] = (string) $guide;

		$cell = $this->cell( $step );
		ob_start();
		$this->table->filter_controls( 'hg_doc' );
		$controls = (string) ob_get_clean();
		unregister_post_type( 'hg_doc' );
		Post_Types::reset();

		$this->assertStringContainsString( '<span class="screen-reader-text">Parent Page: </span>', $cell, 'Core fills parent_item_colon for every hierarchical type.' );
		$this->assertStringContainsString( '>Docs</a>', $controls, 'Core fills all_items from the menu name.' );
	}

	public function test_the_column_comes_after_the_title(): void {
```

In `tests/phpunit/ListTableTest.php`, replace:

```php
		$this->assertStringContainsString( '<span class="screen-reader-text">Parent page: </span><a href="', $cell );
```

with:

```php
		$this->assertStringContainsString( '<span class="screen-reader-text">Parent Page: </span><a href="', $cell );
```

In `tests/phpunit/ListTableTest.php`, replace:

```php
		$this->assertStringContainsString( 'Parent page: </span>Team (in the Trash)', $cell, 'Core refuses to edit a page in the Trash, so it is not linked.' );
```

with:

```php
		$this->assertStringContainsString( 'Parent Page: </span>Team (in the Trash)', $cell, 'Core refuses to edit a page in the Trash, so it is not linked.' );
```

In `tests/phpunit/ListTableTest.php`, replace:

```php
		$this->assertStringContainsString( '>Show all pages</a>', $html );
```

with:

```php
		$this->assertStringContainsString( '>All Pages</a>', $html );
```

In `tests/phpunit/ListTableTest.php`, replace:

```php
		$this->assertStringContainsString( '<span class="screen-reader-text">Parent page: </span><a href="', $this->cell( $this->ids['about'] ) );
```

with:

```php
		$this->assertStringContainsString( '<span class="screen-reader-text">Parent Page: </span><a href="', $this->cell( $this->ids['about'] ) );
```

In `tests/phpunit/ListTableTest.php`, replace:

```php
		$this->assertStringContainsString( '>Company</a><span aria-hidden="true"> › </span>(private page)<span aria-hidden="true"> · </span>', $cell );
```

with:

```php
		$this->assertStringContainsString( '>Company</a><span aria-hidden="true"> › </span>(private)<span aria-hidden="true"> · </span>', $cell );
```

In `tests/phpunit/SettingsPageTest.php`, replace:

```php
		$this->assertStringContainsString( 'Public hierarchical post types appear here automatically.', $html );
```

with:

```php
		$this->assertStringContainsString( 'Public hierarchical post types appear here automatically.', $html );
		$this->assertStringNotContainsString( 'messages say', $html, 'Pages is the only type listed: nothing to explain.' );
	}

	public function test_with_another_type_listed_the_field_says_messages_speak_of_pages(): void {
		register_post_type(
			'hg_docs',
			[
				'public'       => true,
				'hierarchical' => true,
			]
		);
		ob_start();
		$this->page->render_post_types();
		$html = (string) ob_get_clean();
		unregister_post_type( 'hg_docs' );

		$this->assertStringContainsString( '<p class="description">Public hierarchical post types appear here automatically. Hierarchy Guard’s messages say “page” and “subpage” for every post type.</p>', $html );
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npm run test:php -- --filter 'HierarchyControllerTest|ListTableTest|SettingsPageTest'`

Expected: FAIL as below; every other test in these classes passes.

- `HierarchyControllerTest::test_an_ancestor_the_user_may_not_read_keeps_its_title`: Failed asserting that two arrays are identical.
- `ListTableTest::test_another_type_s_list_reads_its_own_labels`: Failed asserting that '<span class="hierarchy-guard-cell" data-id="609" data-children="0" data-status="publish"><span class="screen-reader-text">Parent page: </span><a hr …
- `ListTableTest::test_a_type_that_names_itself_only_gets_core_s_default_labels`: Core fills parent_item_colon for every hierarchical type.
- `ListTableTest::test_a_cell_shows_the_parent_and_the_subpage_count`: Failed asserting that '<span class="hierarchy-guard-cell" data-id="628" data-children="2" data-status="publish"><span class="screen-reader-text">Parent page: </span><a hr …
- `ListTableTest::test_a_parent_in_the_trash_is_marked`: Core refuses to edit a page in the Trash, so it is not linked.
- `ListTableTest::test_the_filter_controls_keep_the_filter_and_show_the_ancestors`: Failed asserting that '<input type="hidden" name="hierarchy_guard_under" value="723"><span class="hierarchy-guard-under">Subpages of <a href="http://localhost:8899/wp-adm …
- `ListTableTest::test_a_tree_search_names_one_parent_page_in_the_singular`: Failed asserting that '<span class="hierarchy-guard-cell" data-id="924" data-children="2" data-status="publish"><span class="screen-reader-text">Parent page: </span><a hr …
- `ListTableTest::test_a_tree_search_hides_a_parent_the_user_may_not_read`: Failed asserting that '<span class="hierarchy-guard-cell" data-id="945" data-children="1" data-status="publish"><span class="screen-reader-text">Parent pages: </span><a h …
- `SettingsPageTest::test_with_another_type_listed_the_field_says_messages_speak_of_pages`: Failed asserting that '<fieldset><legend class="screen-reader-text"><span>Post types</span></legend><input type="hidden" name="hierarchy_guard_settings[post_types][]" val …

Totals: `Tests: 118, Assertions: 372, Failures: 9.`

Mutant, to run once the tests pass, which shows `test_a_tree_search_shows_each_parent_path_with_ancestor_links` pins its behaviour: in `includes/HierarchyGuard/Admin/List_Table.php`, replace

```php
$label   = count( $ancestors ) > 1 ?
```

with:

```php
$label   = false ?
```

Expected: the test fails, as it did on the prototype:

```
ListTableTest::test_a_tree_search_shows_each_parent_path_with_ancestor_links
Every ancestor links to its own subtree.
Tests: 1, Assertions: 1, Failures: 1.
```

Then undo the mutant (`git checkout -- includes/HierarchyGuard/Admin/List_Table.php`). Never commit it.

- [ ] **Step 3: Implement**

In `includes/HierarchyGuard/Admin/List_Table.php`, replace:

```php
			$parts[]   = '<span class="screen-reader-text">' . esc_html( _n( 'Parent page:', 'Parent pages:', count( $ancestors ), 'hierarchy-guard' ) ) . ' </span>' . $this->trail( $type, $ancestors );
```

with:

```php
			// The type's own label names one parent; a trail of several takes the plural.
			$label   = count( $ancestors ) > 1 ? _n( 'Parent page:', 'Parent pages:', count( $ancestors ), 'hierarchy-guard' ) : Post_Types::label( $type, 'parent_item_colon', __( 'Parent page:', 'hierarchy-guard' ) );
			$parts[] = '<span class="screen-reader-text">' . esc_html( $label ) . ' </span>' . $this->trail( $type, $ancestors );
```

In `includes/HierarchyGuard/Admin/List_Table.php`, replace:

```php
			$parts[] = '<span class="screen-reader-text">' . esc_html__( 'Parent page:', 'hierarchy-guard' ) . ' </span>' . $name;
```

with:

```php
			$parts[] = '<span class="screen-reader-text">' . esc_html( Post_Types::label( $type, 'parent_item_colon', __( 'Parent page:', 'hierarchy-guard' ) ) ) . ' </span>' . $name;
```

In `includes/HierarchyGuard/Admin/List_Table.php`, replace:

```php
				$crumbs[] = esc_html__( '(private page)', 'hierarchy-guard' );
```

with:

```php
				$crumbs[] = esc_html_x( '(private)', 'title of a page the user can’t read', 'hierarchy-guard' );
```

In `includes/HierarchyGuard/Admin/List_Table.php`, replace:

```php
				: esc_html__( '(private page)', 'hierarchy-guard' );
```

with:

```php
				: esc_html_x( '(private)', 'title of a page the user can’t read', 'hierarchy-guard' );
```

In `includes/HierarchyGuard/Admin/List_Table.php`, replace:

```php
		printf( '<span aria-hidden="true"> · </span><a href="%1$s">%2$s</a>', esc_url( $clear ), esc_html__( 'Show all pages', 'hierarchy-guard' ) );
```

with:

```php
		printf( '<span aria-hidden="true"> · </span><a href="%1$s">%2$s</a>', esc_url( $clear ), esc_html( Post_Types::label( $type, 'all_items', __( 'Show all pages', 'hierarchy-guard' ) ) ) );
```

In `includes/HierarchyGuard/Admin/Settings_Page.php`, replace:

```php
		echo '<p class="description">' . esc_html__( 'Public hierarchical post types appear here automatically.', 'hierarchy-guard' ) . '</p>';
```

with:

```php
		$help = __( 'Public hierarchical post types appear here automatically.', 'hierarchy-guard' );
		if ( [] !== array_diff( array_keys( $types ), [ 'page' ] ) ) {
			// Sentences can't take a type's own words; the type's labels appear where core has them.
			$help .= ' ' . __( 'Hierarchy Guard’s messages say “page” and “subpage” for every post type.', 'hierarchy-guard' );
		}
		echo '<p class="description">' . esc_html( $help ) . '</p>';
```

In `includes/HierarchyGuard/Overview.php`, replace:

```php
			'title'     => current_user_can( 'read_post', $post_id ) ? self::title( $post_id ) : __( '(private page)', 'hierarchy-guard' ),
```

with:

```php
			'title'     => current_user_can( 'read_post', $post_id ) ? self::title( $post_id ) : _x( '(private)', 'title of a page the user can’t read', 'hierarchy-guard' ),
```

In `includes/HierarchyGuard/Post_Types.php`, replace:

```php
	}

	/**
	 * Clears the memoised list. Hooked to post type registration and settings saves.
```

with:

```php
	}

	/**
	 * One of a post type's own labels, as it registered them, such as
	 * all_items or parent_item_colon, or the fallback when it has none.
	 *
	 * @param string $type     Post type name.
	 * @param string $label    Label key.
	 * @param string $fallback Label for a type without one.
	 * @return string
	 */
	public static function label( string $type, string $label, string $fallback ): string {
		$object = get_post_type_object( $type );
		$value  = null !== $object && isset( $object->labels->{$label} ) ? (string) $object->labels->{$label} : '';
		return '' !== $value ? $value : $fallback;
	}

	/**
	 * Clears the memoised list. Hooked to post type registration and settings saves.
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `npm run test:php -- --filter 'HierarchyControllerTest|ListTableTest|SettingsPageTest'`

Expected: `OK`.

Run: `npm run test:php`, `npm run test:php:multisite` and `npm run test:js`

Expected: `Tests: 1022, Assertions: 4528, Skipped: 6.` (single site), `OK (1022 tests, 4568 assertions)` (multisite), and Vitest `Tests 186 passed (186)`.

- [ ] **Step 5: Lint and build**

Run: `composer lint && npm run lint:js && npm run lint:style && npm run build`

Expected: every command exits 0.

- [ ] **Step 6: Commit**

```bash
git add includes/HierarchyGuard/Admin/List_Table.php includes/HierarchyGuard/Admin/Settings_Page.php includes/HierarchyGuard/Overview.php includes/HierarchyGuard/Post_Types.php tests/phpunit/HierarchyControllerTest.php tests/phpunit/ListTableTest.php tests/phpunit/SettingsPageTest.php
git commit -m "fix: name a type's lists and parents with its own labels, and say the messages speak of pages"
```

---

### Task 16: RTL arrow keys, row-title contrast, real headings, spinner text and a refused field's semantics

**Findings:** 1.7 (no change), 3.7, 7.1, 7.6, 7.8, and the plan audits' U-9 and U-10

- Right to left, the tree's Left and Right keys swap, as the toggle's arrow already points (3.7).
- 1.7: the ` › ` separator stays. Browsers mirror U+203A in right-to-left text (it is a bidi-mirrored character), so it already points from a page to its subpage; a per-direction separator would add code for no change on screen. The spec now says so.
- The Redirects tab's old-URL link sits in a `span.code` without `<code>`'s grey fill, which took the link to 4.49:1 on striped rows; now 5.23:1 (7.1).
- The meta box's `<strong>` labels become headings at the level under the box's title, in the look of core's Page Attributes labels (`<p class="post-attributes-label-wrapper" role="heading" aria-level="3"><span class="post-attributes-label">`): a bare `<h3>` would print larger than the box's own title, and the plugin has no stylesheet on that screen. The panel's and modal's spinners get a screen-reader "Loading…"; a refused Edit-view save marks the field `aria-invalid="true"`, describes it by the reason in a screen-reader span of its own (core's notice would add its Dismiss button) and focuses it (7.6, 7.8). 7.8 (3), `aria-haspopup` on the block editor notice's action, is left as is: core's notice actions take no attributes.

**Files:**
- Modify: `includes/HierarchyGuard/Admin/Meta_Box.php`
- Modify: `includes/HierarchyGuard/Admin/Redirect_Edit.php`
- Modify: `includes/HierarchyGuard/Admin/Redirects_Table.php`
- Modify: `src/common/redirects-modal.js`
- Modify: `src/editor/panel.js`
- Modify: `src/tree/tree.js`
- Test (modify): `src/tree/test/tree.test.js`
- Test (modify): `tests/phpunit/MetaBoxTest.php`
- Test (modify): `tests/phpunit/RedirectEditTest.php`
- Test (modify): `tests/phpunit/RedirectsTabTest.php`

**Interfaces:**
- No new interfaces.

- [ ] **Step 1: Write the failing tests**

In `src/tree/test/tree.test.js`, replace:

```js

	it( 'shows a row once whatever the clicks while it loads', async () => {
```

with:

```js

	it( 'swaps left and right right to left, as the toggle’s arrow does', async () => {
		setLocaleData( { 'text direction\u0004ltr': [ 'rtl' ] } );
		try {
			bindTree( document, fetchChildren );
			row( 4 ).focus();

			press( row( 4 ), 'ArrowLeft' );
			await settle();
			expect( row( 4 ).getAttribute( 'aria-expanded' ) ).toBe( 'true' );
			press( row( 4 ), 'ArrowLeft' );
			expect( document.activeElement ).toBe( row( 5 ) );

			press( row( 5 ), 'ArrowRight' );
			expect( document.activeElement ).toBe( row( 4 ) );
			press( row( 4 ), 'ArrowRight' );
			expect( row( 4 ).getAttribute( 'aria-expanded' ) ).toBe( 'false' );
		} finally {
			setLocaleData( { 'text direction\u0004ltr': [ 'ltr' ] } );
		}
	} );

	it( 'shows a row once whatever the clicks while it loads', async () => {
```

In `tests/phpunit/MetaBoxTest.php`, replace:

```php
		$this->assertStringContainsString( '<strong>Subpages</strong>', $html );
```

with:

```php
		$this->assertStringContainsString( '<p class="post-attributes-label-wrapper" role="heading" aria-level="3"><span class="post-attributes-label">Parent pages</span></p>', $html, 'Headings under the box’s own title, styled as core’s Page Attributes labels.' );
		$this->assertStringContainsString( '<p class="post-attributes-label-wrapper" role="heading" aria-level="3"><span class="post-attributes-label">Subpages</span></p>', $html );
		$this->assertStringNotContainsString( '<h3>', $html, 'A bare h3 would print larger than the box’s title.' );
		$this->assertStringNotContainsString( '<strong>', $html );
```

In `tests/phpunit/RedirectEditTest.php`, replace:

```php
		$this->assertStringContainsString( '<td><input type="text" class="regular-text code" id="hierarchy-guard-target" name="target" value="' . esc_attr( get_permalink( $this->ids['about'] ) ) . '" dir="ltr" aria-describedby="hierarchy-guard-target-description">', $html, 'A text field, so a path is not stopped by the browser.' );
```

with:

```php
		$this->assertStringContainsString( '<td><input type="text" class="regular-text code" id="hierarchy-guard-target" name="target" value="' . esc_attr( get_permalink( $this->ids['about'] ) ) . '" dir="ltr" aria-describedby="hierarchy-guard-target-description">', $html, 'A text field, so a path is not stopped by the browser; not marked invalid before a save.' );
```

In `tests/phpunit/RedirectEditTest.php`, replace:

```php
		$this->assertStringContainsString( 'value="ftp://example.org/"', $html );
```

with:

```php
		$this->assertStringContainsString( '<span class="screen-reader-text" id="hierarchy-guard-target-error">Enter a URL that starts with http:// or https://, or a path on this site that starts with /.</span><input', $html, 'Why, without the notice’s Dismiss button.' );
		$this->assertStringContainsString( 'value="ftp://example.org/" dir="ltr" aria-describedby="hierarchy-guard-target-error hierarchy-guard-target-description" aria-invalid="true" autofocus>', $html, 'Marked invalid, described by why, and focused.' );
```

In `tests/phpunit/RedirectsTabTest.php`, replace:

```php
		$this->assertStringContainsString( '<code>/old-about/</code></a> and the URLs under it', $html, 'In code, so it reads left to right in a right-to-left language too.' );
		$this->assertStringContainsString( '<code>/careers/</code><', $html, 'A URL target covers its exact old URL only.' );
		$this->assertStringContainsString( '>The URLs under <a class="row-title"', $html, 'A top-level entry never redirects its old URL itself.' );
		$this->assertStringContainsString( '<code>/archive/</code></a>', $html );
		$this->assertStringNotContainsString( '<code>/archive/</code></a> and the URLs under it', $html );
		$this->assertStringContainsString( '<code>/archive/</code></a><p class="description">This redirect stays until you delete it, even after the pages under it are gone.</p>', $html );
```

with:

```php
		$this->assertStringContainsString( '<span class="code">/old-about/</span></a> and the URLs under it', $html, 'Core’s monospace, left to right in a right-to-left language too, without code’s grey fill behind the link.' );
		$this->assertStringNotContainsString( '<code>/old-about/</code></a>', $html );
		$this->assertStringContainsString( '<span class="code">/careers/</span><', $html, 'A URL target covers its exact old URL only.' );
		$this->assertStringContainsString( '>The URLs under <a class="row-title"', $html, 'A top-level entry never redirects its old URL itself.' );
		$this->assertStringContainsString( '<span class="code">/archive/</span></a>', $html );
		$this->assertStringNotContainsString( '<span class="code">/archive/</span></a> and the URLs under it', $html );
		$this->assertStringContainsString( '<span class="code">/archive/</span></a><p class="description">This redirect stays until you delete it, even after the pages under it are gone.</p>', $html );
```

In `tests/phpunit/RedirectsTabTest.php`, replace:

```php
		$this->assertStringContainsString( '<code>/café/équipe/</code>', $html );
```

with:

```php
		$this->assertStringContainsString( '<span class="code">/café/équipe/</span>', $html );
```

In `tests/phpunit/RedirectsTabTest.php`, replace:

```php
		$this->assertStringContainsString( '<a class="row-title" href="' . esc_url( $edit ) . '"><code>/old-21/</code></a> and the URLs under it', $html, 'The old URL links to Edit, as core’s row titles do, named by its own text.' );
```

with:

```php
		$this->assertStringContainsString( '<a class="row-title" href="' . esc_url( $edit ) . '"><span class="code">/old-21/</span></a> and the URLs under it', $html, 'The old URL links to Edit, as core’s row titles do, named by its own text.' );
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npm run test:php -- --filter 'MetaBoxTest|RedirectEditTest|RedirectsTabTest'`

Expected: FAIL as below; every other test in these classes passes.

- `MetaBoxTest::test_the_box_shows_parents_children_and_the_trash_policy`: Headings under the box’s own title, styled as core’s Page Attributes labels.
- `RedirectEditTest::test_a_refused_save_through_the_page_prints_the_form_again_with_why`: Why, without the notice’s Dismiss button.
- `RedirectsTabTest::test_each_column_reads_as_the_spec_has_it`: Core’s monospace, left to right in a right-to-left language too, without code’s grey fill behind the link.
- `RedirectsTabTest::test_a_percent_encoded_search_finds_the_old_url_it_names`: Failed asserting that '<div class="wrap"><h1 class="wp-heading-inline">Hierarchy Guard</h1> <span class="subtitle">Search results for: <strong><span dir="ltr">http://loca …
- `RedirectsTabTest::test_the_row_links_and_the_old_url_keep_the_list_s_search_filter_and_page`: The old URL links to Edit, as core’s row titles do, named by its own text.

Totals: `Tests: 68, Assertions: 222, Failures: 5.`

Run: `npx wp-scripts test-unit-js src/tree/test/tree.test.js`

Expected: FAIL:

- `× swaps left and right right to left, as the toggle’s arrow does 10ms`
- `FAIL  src/tree/test/tree.test.js > the tree grid > swaps left and right right to left, as the toggle’s arrow does`

Totals: `Tests  1 failed | 53 passed (54)`

Mutant, to run once the tests pass, which shows `swaps left and right right to left, as the toggle’s arrow does` pins its behaviour: in `src/tree/tree.js`, replace

```js
isRTL() && mirrored[ event.key ]
```

with:

```js
false && mirrored[ event.key ]
```

Expected: the Vitest case fails, as it did on the prototype:

```
× swaps left and right right to left, as the toggle’s arrow does 12ms
Tests  1 failed | 53 skipped (54)
FAIL  src/tree/test/tree.test.js > the tree grid > swaps left and right right to left, as the toggle’s arrow does
AssertionError: expected 'false' to be 'true' // Object.is equality
Expected: "true"
Received: "false"
```

Then undo the mutant (`git checkout -- src/tree/tree.js`). Never commit it.

- [ ] **Step 3: Implement**

In `includes/HierarchyGuard/Admin/Meta_Box.php`, replace:

```php
		echo '<p><strong>' . esc_html__( 'Parent pages', 'hierarchy-guard' ) . '</strong><br>';
```

with:

```php
		// Headings in the look of core's Page Attributes labels, at the level under the box's own title.
		echo '<p class="post-attributes-label-wrapper" role="heading" aria-level="3"><span class="post-attributes-label">' . esc_html__( 'Parent pages', 'hierarchy-guard' ) . '</span></p><p>';
```

In `includes/HierarchyGuard/Admin/Meta_Box.php`, replace:

```php
		echo '<p><strong>' . esc_html__( 'Subpages', 'hierarchy-guard' ) . '</strong><br>';
```

with:

```php
		echo '<p class="post-attributes-label-wrapper" role="heading" aria-level="3"><span class="post-attributes-label">' . esc_html__( 'Subpages', 'hierarchy-guard' ) . '</span></p><p>';
```

In `includes/HierarchyGuard/Admin/Redirect_Edit.php`, replace:

```php
		$help    = [];
```

with:

```php
		$help    = [];
		// A refused URL: the field is marked invalid, described by why, and focused. Core's notice says it
		// too, but describing the field by the notice would read its Dismiss button as well.
		$invalid = null !== $this->refused ? ' aria-invalid="true" autofocus' : '';
		$why     = null !== $this->refused ? '<span class="screen-reader-text" id="hierarchy-guard-target-error">' . esc_html( $this->refused['message'] ) . '</span>' : '';
```

In `includes/HierarchyGuard/Admin/Redirect_Edit.php`, replace:

```php
			'<tr><th scope="row"><label for="hierarchy-guard-target">%1$s</label></th><td>%2$s<input type="text" class="regular-text code" id="hierarchy-guard-target" name="target" value="%3$s" dir="ltr" aria-describedby="%5$shierarchy-guard-target-description"><div id="hierarchy-guard-target-description"><p class="description">%4$s</p></div></td></tr>',
			esc_html__( 'Redirect to', 'hierarchy-guard' ),
			$now, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
			esc_attr( $url ),
			implode( '</p><p class="description">', $help ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Each sentence is escaped above; the old URL is escaped inside its <code>.
			'' !== $now ? 'hierarchy-guard-target-now ' : ''
```

with:

```php
			'<tr><th scope="row"><label for="hierarchy-guard-target">%1$s</label></th><td>%2$s<input type="text" class="regular-text code" id="hierarchy-guard-target" name="target" value="%3$s" dir="ltr" aria-describedby="%5$shierarchy-guard-target-description"%6$s><div id="hierarchy-guard-target-description"><p class="description">%4$s</p></div></td></tr>',
			esc_html__( 'Redirect to', 'hierarchy-guard' ),
			$now . $why, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
			esc_attr( $url ),
			implode( '</p><p class="description">', $help ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Each sentence is escaped above; the old URL is escaped inside its <code>.
			( '' !== $why ? 'hierarchy-guard-target-error ' : '' ) . ( '' !== $now ? 'hierarchy-guard-target-now ' : '' ),
			$invalid // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
```

In `includes/HierarchyGuard/Admin/Redirect_Edit.php`, replace:

```php
				'record' => $record,
				'url'    => $url,
				'code'   => in_array( $code, [ 301, 302 ], true ) ? $code : 0,
```

with:

```php
				'record'  => $record,
				'url'     => $url,
				'code'    => in_array( $code, [ 301, 302 ], true ) ? $code : 0,
				'error'   => (string) $result->get_error_code(),
				'message' => (string) $result->get_error_message(),
```

In `includes/HierarchyGuard/Admin/Redirects_Table.php`, replace:

```php
	 * right-to-left language too, with "and the URLs under it" for a page
```

with:

```php
	 * right-to-left language too; as a row title, core's monospace .code
	 * without code's grey fill, which takes the link below AA contrast on a
	 * striped row. With "and the URLs under it" for a page
```

In `includes/HierarchyGuard/Admin/Redirects_Table.php`, replace:

```php
			$old = sprintf( '<a class="row-title" href="%1$s">%2$s</a>', esc_url( $edit ), $old );
```

with:

```php
			$old = sprintf( '<a class="row-title" href="%1$s"><span class="code">%2$s</span></a>', esc_url( $edit ), esc_html( (string) $item['old'] ) );
```

In `src/common/redirects-modal.js`, replace:

```js
			{ ! data && loading && <Spinner /> }
```

with:

```js
			{ ! data && loading && (
				<>
					<Spinner />
					<span className="screen-reader-text">
						{ __( 'Loading…', 'hierarchy-guard' ) }
					</span>
				</>
			) }
```

In `src/editor/panel.js`, replace:

```js
				{ ! data && ! error && <Spinner /> }
```

with:

```js
				{ ! data && ! error && (
					<>
						<Spinner />
						<span className="screen-reader-text">
							{ __( 'Loading…', 'hierarchy-guard' ) }
						</span>
					</>
				) }
```

In `src/tree/tree.js`, replace:

```js
		switch ( event.key ) {
```

with:

```js
		// Right to left, the arrows swap, as the toggle's arrow does.
		const mirrored = { ArrowLeft: 'ArrowRight', ArrowRight: 'ArrowLeft' };
		const key =
			isRTL() && mirrored[ event.key ]
				? mirrored[ event.key ]
				: event.key;
		switch ( key ) {
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `npm run test:php -- --filter 'MetaBoxTest|RedirectEditTest|RedirectsTabTest'`

Expected: `OK`.

Run: `npx wp-scripts test-unit-js src/tree/test/tree.test.js`

Expected: every test passes.

Run: `npm run test:php`, `npm run test:php:multisite` and `npm run test:js`

Expected: `Tests: 1022, Assertions: 4533, Skipped: 6.` (single site), `OK (1022 tests, 4573 assertions)` (multisite), and Vitest `Tests 187 passed (187)`.

- [ ] **Step 5: Lint and build**

Run: `composer lint && npm run lint:js && npm run lint:style && npm run build`

Expected: every command exits 0.

- [ ] **Step 6: Commit**

```bash
git add includes/HierarchyGuard/Admin/Meta_Box.php includes/HierarchyGuard/Admin/Redirect_Edit.php includes/HierarchyGuard/Admin/Redirects_Table.php src/common/redirects-modal.js src/editor/panel.js src/tree/test/tree.test.js src/tree/tree.js tests/phpunit/MetaBoxTest.php tests/phpunit/RedirectEditTest.php tests/phpunit/RedirectsTabTest.php
git commit -m "fix: mirror the tree's arrow keys right to left, keep row-title links at AA contrast, and give headings, spinners and a refused field their semantics"
```

---

### Task 17: Small fixes: an array trash policy, the trash preview's capability, paths of any depth, no-cache redirects, VIP purge, the list stylesheet in the head

**Findings:** 3.10, 8.4, 9.8, 11.1, 8.2, and the plan audits' S-5, S-6 and U-14 (no-cache)

- `Settings` reads `trash_policy` only when it is a string (3.10).
- `/trash-preview` answers only a user who may trash some supported type, checked in `permission_callback` before any ID is primed (8.4).
- `Paths::chain()` follows any depth, as `get_page_uri()` does, and stops on a loop with a seen-set instead of a 50-level cap (9.8).
- Every redirect goes out with `nocache_headers()`, which browsers and caches that honour them respect, pinned by a test that runs in a process of its own, where core sends headers (the bootstrap sets `WP_TESTS_SKIP_INSTALL` for such child processes). On VIP, each change to an old URL's record purges it from the edge with `wpvip_purge_edge_cache_for_url()`, or the deprecated `wpcom_vip_purge_edge_cache_for_url()` when only that exists, once a request and at most 50 a request, below VIP's limit of 100 that would drop VIP's own purges. VIP's edge keeps a 301 for 30 minutes and a 302 for a minute whatever the headers say, and a purge clears that exact URL: URLs under it keep a cached redirect until it expires. The comments and the spec say only that; the readme says it in plan 6b. No prefix purging is built.
- The list's stylesheet is enqueued on `admin_enqueue_scripts` for every list screen of a supported type, so it prints in the head and the column width is there before paint (8.2). `Tree_View::STYLE` and `want_list_style()` go.

**Files:**
- Modify: `includes/HierarchyGuard/Admin/Assets.php`
- Modify: `includes/HierarchyGuard/Admin/Tree_View.php`
- Modify: `includes/HierarchyGuard/Ledger.php`
- Modify: `includes/HierarchyGuard/Paths.php`
- Modify: `includes/HierarchyGuard/REST/Hierarchy_Controller.php`
- Modify: `includes/HierarchyGuard/Redirects/Server.php`
- Modify: `includes/HierarchyGuard/Settings.php`
- Test (modify): `tests/phpunit/bootstrap.php`
- Test (create): `tests/phpunit/stubs/vip.php`
- Test (modify): `tests/phpunit/AssetsTest.php`
- Test (modify): `tests/phpunit/HierarchyControllerTest.php`
- Test (modify): `tests/phpunit/LedgerTest.php`
- Test (modify): `tests/phpunit/PathsTest.php`
- Test (modify): `tests/phpunit/ServerTest.php`
- Test (modify): `tests/phpunit/SettingsTest.php`
- Test (modify): `tests/phpunit/TreeViewTest.php`

**Interfaces:**
- Produces: `Hierarchy_Controller::can_trash_some(): bool` (the route's `permission_callback`).

- [ ] **Step 1: Write the failing tests**

In `tests/phpunit/bootstrap.php`, replace:

```php
require $hierarchy_guard_tests_dir . '/includes/bootstrap.php';
```

with:

```php
require $hierarchy_guard_tests_dir . '/includes/bootstrap.php';

// A test run in a separate process loads this file again: its tables are installed already.
putenv( 'WP_TESTS_SKIP_INSTALL=1' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- The test bootstrap's own environment, for its child processes.
```

Create `tests/phpunit/stubs/vip.php`:

```php
<?php
/**
 * WordPress VIP's edge cache purge, under its current name and the one it
 * deprecated, as the ledger calls them: each records the URLs it was asked
 * to purge.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- VIP's own names, stubbed for tests.

if ( ! function_exists( 'wpvip_purge_edge_cache_for_url' ) ) {
	/**
	 * Records one URL to purge.
	 *
	 * @param string $url URL.
	 */
	function wpvip_purge_edge_cache_for_url( $url ): void {
		$GLOBALS['hierarchy_guard_purged'][] = (string) $url;
	}

	/**
	 * Records one URL asked for under the deprecated name.
	 *
	 * @param string $url URL.
	 */
	function wpcom_vip_purge_edge_cache_for_url( $url ): void {
		$GLOBALS['hierarchy_guard_purged_deprecated'][] = (string) $url;
	}
}
```

In `tests/phpunit/AssetsTest.php`, replace:

```php
	public function test_the_tree_s_stylesheet_loads_in_the_head_and_its_script_in_the_footer(): void {
```

with:

```php
	public function test_the_list_s_stylesheet_loads_in_the_head_of_every_list_screen_and_its_script_in_the_footer(): void {
```

In `tests/phpunit/AssetsTest.php`, replace:

```php
		$this->assertFalse( wp_style_is( 'hierarchy-guard-list-table', 'enqueued' ), 'The list keeps its stylesheet in the footer.' );

		$assets->want_list_style();
		$assets->enqueue_admin( 'edit.php' );
		$this->assertTrue( wp_style_is( 'hierarchy-guard-list-table', 'enqueued' ), 'The tree’s indents print with the head, before its rows.' );
```

with:

```php

		$this->assertTrue( wp_style_is( 'hierarchy-guard-list-table', 'enqueued' ), 'The column’s width and the tree’s indents are there as the rows paint.' );
```

In `tests/phpunit/AssetsTest.php`, replace:

```php

	public function test_the_plugin_listens_for_the_tree_s_stylesheet(): void {
		global $wp_filter;
		$found = false;
		foreach ( $wp_filter[ Tree_View::STYLE ]->callbacks[10] ?? [] as $callback ) {
			$found = $found || ( is_array( $callback['function'] ) && $callback['function'][0] instanceof Assets );
		}

		$this->assertTrue( $found );
	}

```

with:

```php

```

In `tests/phpunit/HierarchyControllerTest.php`, replace:

```php
	public function test_the_trash_preview_skips_pages_the_user_cannot_trash(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$data = $this->get( '/trash-preview', [ 'ids' => [ $this->ids['about'] ] ] )->get_data();

		$this->assertSame( [], $data['pages'] );
```

with:

```php
	public function test_the_trash_preview_answers_only_users_who_can_trash_some_supported_type(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$this->assertSame( 403, $this->get( '/trash-preview', [ 'ids' => [ $this->ids['about'] ] ] )->get_status(), 'Not the policy or the cap either.' );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'author' ] ) );
		$this->assertSame( 403, $this->get( '/trash-preview', [ 'ids' => [ $this->ids['about'] ] ] )->get_status(), 'An author can’t trash pages.' );
	}

	public function test_the_trash_preview_skips_pages_the_user_cannot_trash(): void {
		$editor = self::factory()->user->create( [ 'role' => 'editor' ] );
		$mine   = self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_author' => $editor,
			]
		);
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'contributor' ] ) );
		add_filter(
			'user_has_cap',
			static function ( array $caps ) {
				$caps['delete_pages'] = true;
				return $caps;
			}
		);

		$data = $this->get( '/trash-preview', [ 'ids' => [ $this->ids['about'], $mine ] ] )->get_data();

		$this->assertSame( [], $data['pages'], 'Allowed to ask, but not to trash these pages.' );
```

In `tests/phpunit/LedgerTest.php`, replace:

```php

	public function test_record_is_idempotent(): void {
```

with:

```php

	public function test_every_change_to_an_old_url_s_redirect_purges_it_from_a_vip_edge_cache_once(): void {
		require_once __DIR__ . '/stubs/vip.php';
		$this->forget_purges();
		$other = self::factory()->post->create(
			[
				'post_type' => 'page',
				'post_name' => 'other',
			]
		);

		$this->ledger->record( $this->about, 'old-about' );
		$this->ledger->set_target( 'page', 'old-about', $this->about, 'https://example.org/', 302 );
		$this->ledger->forget( $this->about, 'old-about' );
		$this->ledger->record_root( 'page', 'section' );
		$this->ledger->forget_root( 'page', 'section' );
		$this->ledger->record( $other, 'about' );
		$this->ledger->claim( get_post( $this->about ) );

		$this->assertSame( [ home_url( '/old-about/' ), home_url( '/section/' ), home_url( '/about/' ) ], $GLOBALS['hierarchy_guard_purged'], 'VIP\'s current function, each URL once a request.' );
		$this->assertSame( [], $GLOBALS['hierarchy_guard_purged_deprecated'] );
		$this->forget_purges();
	}

	public function test_a_request_purges_at_most_fifty_old_urls_so_vip_keeps_its_own(): void {
		require_once __DIR__ . '/stubs/vip.php';
		$this->forget_purges();

		for ( $i = 1; $i <= 60; $i++ ) {
			$this->ledger->record( $this->about, "old-{$i}" );
		}

		$this->assertCount( 50, $GLOBALS['hierarchy_guard_purged'] );
		$this->assertSame( home_url( '/old-50/' ), end( $GLOBALS['hierarchy_guard_purged'] ) );
		$this->forget_purges();
	}

	/**
	 * Clears what the VIP stubs recorded and what this request purged.
	 */
	private function forget_purges(): void {
		$GLOBALS['hierarchy_guard_purged']            = [];
		$GLOBALS['hierarchy_guard_purged_deprecated'] = [];
		$purged                                       = new \ReflectionProperty( Ledger::class, 'purged' );
		$purged->setAccessible( true );
		$purged->setValue( null, [] );
	}

	public function test_record_is_idempotent(): void {
```

In `tests/phpunit/PathsTest.php`, replace:

```php

	public function test_uri_follows_the_parent_chain(): void {
```

with:

```php

	public function test_uri_follows_a_chain_of_any_depth_as_get_page_uri_does(): void {
		$parent = 0;
		for ( $level = 1; $level <= 60; $level++ ) {
			$parent = self::factory()->post->create(
				[
					'post_type'   => 'page',
					'post_name'   => "level-{$level}",
					'post_parent' => $parent,
				]
			);
		}

		$this->assertSame( 60, substr_count( Paths::uri( get_post( $parent ) ), '/' ) + 1 );
		$this->assertSame( Paths::normalize( get_page_uri( $parent ) ), Paths::uri( get_post( $parent ) ) );
	}

	public function test_a_loop_in_the_parent_chain_ends_the_walk(): void {
		global $wpdb;
		$a = self::factory()->post->create(
			[
				'post_type' => 'page',
				'post_name' => 'loop-a',
			]
		);
		$b = self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_name'   => 'loop-b',
				'post_parent' => $a,
			]
		);
		$wpdb->update( $wpdb->posts, [ 'post_parent' => $b ], [ 'ID' => $a ] );
		clean_post_cache( $a );

		$this->assertSame( 'loop-a/loop-b', Paths::uri( get_post( $b ) ) );
	}

	public function test_uri_follows_the_parent_chain(): void {
```

In `tests/phpunit/ServerTest.php`, replace:

```php

	public function test_redirects_an_old_subpage_url_to_the_new_permalink(): void {
```

with:

```php

	public function test_a_served_redirect_goes_out_through_wp_redirect_with_its_status(): void {
		wp_update_post(
			[
				'ID'        => $this->about,
				'post_name' => 'company-info',
			]
		);
		$this->go_to( '/company/about/team/' );
		$sent = [];
		$stop = static function ( $location, $status ) use ( &$sent ) {
			$sent = [ (string) $location, (int) $status ];
			return false;
		};
		add_filter( 'wp_redirect', $stop, 10, 2 );

		$this->server->maybe_redirect();

		remove_filter( 'wp_redirect', $stop, 10 );
		$this->assertSame( [ get_permalink( $this->team ), 301 ], $sent );
	}

	/**
	 * In a process of its own, where nothing is printed before the test, so
	 * core sends headers.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_served_redirect_goes_out_with_core_s_no_cache_headers(): void {
		wp_update_post(
			[
				'ID'        => $this->about,
				'post_name' => 'company-info',
			]
		);
		$this->go_to( '/company/about/team/' );
		$order = [];
		$cache = static function ( array $headers ) use ( &$order ): array {
			$order[] = 'no-cache';
			return $headers;
		};
		$stop  = static function () use ( &$order ) {
			$order[] = 'redirect';
			return false;
		};
		add_filter( 'nocache_headers', $cache );
		add_filter( 'wp_redirect', $stop );

		$this->server->maybe_redirect();

		$this->assertSame( [ 'no-cache', 'redirect' ], $order );
	}

	public function test_redirects_an_old_subpage_url_to_the_new_permalink(): void {
```

In `tests/phpunit/SettingsTest.php`, replace:

```php

	public function test_sanitize_reads_the_string_false_as_off(): void {
```

with:

```php

	public function test_a_trash_policy_that_is_not_text_falls_back_without_a_warning(): void {
		$this->assertSame( Settings::POLICY_REPARENT, Settings::sanitize( [ 'trash_policy' => [ 'keep' ] ] )['trash_policy'] );
	}

	public function test_sanitize_reads_the_string_false_as_off(): void {
```

In `tests/phpunit/TreeViewTest.php`, replace:

```php

	public function test_the_tree_and_its_search_ask_for_their_stylesheet_as_the_screen_loads(): void {
		$_GET = [ 'post_type' => 'page' ];
		$this->tree->load();
		$this->assertSame( 0, did_action( Tree_View::STYLE ), 'The list prints its own when it needs it.' );

		$_GET[ Tree_View::QUERY_VAR ] = Tree_View::VIEW;
		$this->tree->load();
		$this->assertSame( 1, did_action( Tree_View::STYLE ), 'Before admin_enqueue_scripts, so the indents print in the head.' );

		$_GET['s'] = 'team';
		$this->tree->load();
		$this->assertSame( 2, did_action( Tree_View::STYLE ) );
	}

```

with:

```php

```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npm run test:php -- --filter 'AssetsTest|HierarchyControllerTest|LedgerTest|PathsTest|ServerTest|SettingsTest|TreeViewTest'`

Expected: FAIL as below; every other test in these classes passes.

- `LedgerTest::test_every_change_to_an_old_url_s_redirect_purges_it_from_a_vip_edge_cache_once`: ReflectionException: Property HierarchyGuard\Ledger::$purged does not exist
- `LedgerTest::test_a_request_purges_at_most_fifty_old_urls_so_vip_keeps_its_own`: ReflectionException: Property HierarchyGuard\Ledger::$purged does not exist
- `SettingsTest::test_a_trash_policy_that_is_not_text_falls_back_without_a_warning`: Array to string conversion
- `AssetsTest::test_the_list_s_stylesheet_loads_in_the_head_of_every_list_screen_and_its_script_in_the_footer`: The column’s width and the tree’s indents are there as the rows paint.
- `HierarchyControllerTest::test_the_trash_preview_answers_only_users_who_can_trash_some_supported_type`: Not the policy or the cap either.
- `PathsTest::test_uri_follows_a_chain_of_any_depth_as_get_page_uri_does`: Failed asserting that 51 is identical to 60.
- `PathsTest::test_a_loop_in_the_parent_chain_ends_the_walk`: Failed asserting that two strings are identical.
- `ServerTest::test_a_served_redirect_goes_out_with_core_s_no_cache_headers`: Failed asserting that two arrays are identical.

Totals: `Tests: 262, Assertions: 913, Errors: 3, Failures: 5.`

Mutant, to run once the tests pass, which shows `test_a_served_redirect_goes_out_with_core_s_no_cache_headers` pins its behaviour: in `includes/HierarchyGuard/Redirects/Server.php`, replace

```php
		nocache_headers();

```

with nothing.

Expected: the test fails, as it did on the prototype:

```
ServerTest::test_a_served_redirect_goes_out_with_core_s_no_cache_headers
Failed asserting that two arrays are identical.
Tests: 1, Assertions: 1, Failures: 1.
```

Then undo the mutant (`git checkout -- includes/HierarchyGuard/Redirects/Server.php`). Never commit it.

Mutant, to run once the tests pass, which shows `test_a_request_purges_at_most_fifty_old_urls_so_vip_keeps_its_own` pins its behaviour: in `includes/HierarchyGuard/Ledger.php`, replace

```php
private const PURGE_LIMIT = 50;
```

with:

```php
private const PURGE_LIMIT = 100;
```

Expected: the test fails, as it did on the prototype:

```
LedgerTest::test_a_request_purges_at_most_fifty_old_urls_so_vip_keeps_its_own
Failed asserting that actual size 60 matches expected size 50.
Tests: 1, Assertions: 1, Failures: 1.
```

Then undo the mutant (`git checkout -- includes/HierarchyGuard/Ledger.php`). Never commit it.

- [ ] **Step 3: Implement**

In `includes/HierarchyGuard/Admin/Assets.php`, replace:

```php

	/**
	 * Whether the Tree view asked for its stylesheet in the head.
	 *
	 * @var bool
	 */
	private bool $styled = false;

```

with:

```php

```

In `includes/HierarchyGuard/Admin/Assets.php`, replace:

```php
		add_action( List_Table::SCRIPT, [ $this, 'want_list_table' ] );
		add_action( Tree_View::STYLE, [ $this, 'want_list_style' ] );
```

with:

```php
		add_action( List_Table::SCRIPT, [ $this, 'want_list_table' ] );
```

In `includes/HierarchyGuard/Admin/Assets.php`, replace:

```php

	/**
	 * Notes that the Tree view loads, so the list screen's stylesheet goes in
	 * the head: the indents are in it.
	 */
	public function want_list_style(): void {
		$this->styled = true;
	}

```

with:

```php

```

In `includes/HierarchyGuard/Admin/Assets.php`, replace:

```php
		if ( $this->styled && 'edit.php' === $hook_suffix ) {
			// The Tree view's indents print with the head; its script follows in the footer.
```

with:

```php
		if ( 'edit.php' === $hook_suffix ) {
			// In the head, so the Hierarchy column's width and the Tree view's indents are there as the rows paint; the script follows in the footer when the screen asks for it.
```

In `includes/HierarchyGuard/Admin/Tree_View.php`, replace:

```php
	public const PER_PAGE          = 50;
	public const STYLE             = 'hierarchy_guard_tree_style';
```

with:

```php
	public const PER_PAGE          = 50;
```

In `includes/HierarchyGuard/Admin/Tree_View.php`, replace:

```php
		}
		/**
		 * Fires when the Tree view, or a search in it, loads: its stylesheet
		 * prints in the head, so the rows are indented as they arrive.
		 *
		 * @internal
		 */
		do_action( 'hierarchy_guard_tree_style' );
```

with:

```php
		}
```

In `includes/HierarchyGuard/Ledger.php`, replace:

```php
	private const CACHE_TTL = 12 * HOUR_IN_SECONDS;

	/**
```

with:

```php
	private const CACHE_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * Most old URLs one request asks VIP's edge to purge. VIP purges at most
	 * 100 a request and drops the rest, its own purges included.
	 */
	private const PURGE_LIMIT = 50;

	/**
	 * Old URLs this request asked VIP's edge to purge.
	 *
	 * @var array<string,true>
	 */
	private static array $purged = [];

	/**
```

In `includes/HierarchyGuard/Ledger.php`, replace:

```php
		}
		$this->bump();
```

with:

```php
		}
		self::purge( (string) $post->post_type, $old_path );
		$this->bump();
```

In `includes/HierarchyGuard/Ledger.php`, replace:

```php
		update_option( self::ROOT_OPTION, $roots, false );
		$this->bump();
```

with:

```php
		update_option( self::ROOT_OPTION, $roots, false );
		self::purge( $post_type, $old_path );
		$this->bump();
```

In `includes/HierarchyGuard/Ledger.php`, replace:

```php
		if ( $dropped ) {
```

with:

```php
		if ( $dropped ) {
			self::purge( $type, $path );
```

In `includes/HierarchyGuard/Ledger.php`, replace:

```php
		$this->drop_target( $post_id, (string) $post->post_type, $old_path );
		delete_post_meta( $post_id, $key );
		$this->bump();
```

with:

```php
		$this->drop_target( $post_id, (string) $post->post_type, $old_path );
		delete_post_meta( $post_id, $key );
		self::purge( (string) $post->post_type, $old_path );
		$this->bump();
```

In `includes/HierarchyGuard/Ledger.php`, replace:

```php
		$this->drop_root_targets( $post_type, [ $old_path ] );
```

with:

```php
		$this->drop_root_targets( $post_type, [ $old_path ] );
		self::purge( $post_type, $old_path );
```

In `includes/HierarchyGuard/Ledger.php`, replace:

```php
		}

		$this->bump();
```

with:

```php
		}

		self::purge( $post_type, $path );
		$this->bump();
```

In `includes/HierarchyGuard/Ledger.php`, replace:

```php
	}

	/**
	 * Invalidates every cached lookup, or notes that it is due inside deferred().
```

with:

```php
	}

	/**
	 * Asks WordPress VIP's edge to forget an old URL whose redirect changed,
	 * once a request, up to PURGE_LIMIT URLs. VIP's edge keeps a 301 for 30
	 * minutes and a 302 for a minute whatever the response's headers say, and
	 * a purge clears that exact URL only: URLs under it keep a cached
	 * redirect until it expires.
	 *
	 * @param string $post_type Post type name.
	 * @param string $path      Normalized path.
	 */
	private static function purge( string $post_type, string $path ): void {
		// VIP's current name, else the one it deprecated.
		$purge = function_exists( 'wpvip_purge_edge_cache_for_url' ) ? 'wpvip_purge_edge_cache_for_url' : 'wpcom_vip_purge_edge_cache_for_url';
		if ( '' === $path || ! function_exists( $purge ) ) {
			return;
		}
		$prefix = Redirects\Server::prefix( $post_type );
		$url    = home_url( user_trailingslashit( '' !== $prefix ? $prefix . '/' . $path : $path ) );
		if ( isset( self::$purged[ $url ] ) || count( self::$purged ) >= self::PURGE_LIMIT ) {
			return;
		}
		self::$purged[ $url ] = true;
		$purge( $url );
	}

	/**
	 * Invalidates every cached lookup, or notes that it is due inside deferred().
```

In `includes/HierarchyGuard/Paths.php`, replace:

```php
		$guard     = 0;

		while ( $parent_id > 0 && $guard < 50 ) {
			++$guard;
			$parent = $as_before[ $parent_id ] ?? get_post( $parent_id );
```

with:

```php
		// However deep, as get_page_uri() goes; a parent seen before is a loop in the data, and ends the walk.
		$seen = [ (int) $post->ID => true ];

		while ( $parent_id > 0 && ! isset( $seen[ $parent_id ] ) ) {
			$seen[ $parent_id ] = true;
			$parent             = $as_before[ $parent_id ] ?? get_post( $parent_id );
```

In `includes/HierarchyGuard/REST/Hierarchy_Controller.php`, replace:

```php
				'permission_callback' => 'is_user_logged_in',
```

with:

```php
				'permission_callback' => [ $this, 'can_trash_some' ],
```

In `includes/HierarchyGuard/REST/Hierarchy_Controller.php`, replace:

```php
	}

	/**
	 * Whether the current user may edit the requested post of a supported type.
```

with:

```php
	}

	/**
	 * Whether the current user may trash posts of some supported type: the
	 * preview answers only those who can, and checks each page it reads.
	 *
	 * @return bool
	 */
	public function can_trash_some(): bool {
		foreach ( Post_Types::supported() as $type ) {
			$object = get_post_type_object( $type );
			if ( null !== $object && current_user_can( $object->cap->delete_posts ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether the current user may edit the requested post of a supported type.
```

In `includes/HierarchyGuard/Redirects/Server.php`, replace:

```php
			return;
		}

```

with:

```php
			return;
		}
		// Browsers and caches that honour these headers keep no redirect, which a restore or an
		// administrator's edit can make wrong. VIP's edge keeps redirects for times of its own; the
		// ledger purges an old URL there when its record changes.
		nocache_headers();

```

In `includes/HierarchyGuard/Settings.php`, replace:

```php
		$policy = isset( $input['trash_policy'] ) ? sanitize_key( (string) $input['trash_policy'] ) : self::POLICY_REPARENT;
```

with:

```php
		$policy = isset( $input['trash_policy'] ) && is_string( $input['trash_policy'] ) ? sanitize_key( $input['trash_policy'] ) : self::POLICY_REPARENT;
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `npm run test:php -- --filter 'AssetsTest|HierarchyControllerTest|LedgerTest|PathsTest|ServerTest|SettingsTest|TreeViewTest'`

Expected: `OK`.

Run: `npm run test:php`, `npm run test:php:multisite` and `npm run test:js`

Expected: `Tests: 1028, Assertions: 4540, Skipped: 6.` (single site), `OK (1028 tests, 4580 assertions)` (multisite), and Vitest `Tests 187 passed (187)`.

- [ ] **Step 5: Lint and build**

Run: `composer lint && npm run lint:js && npm run lint:style && npm run build`

Expected: every command exits 0.

- [ ] **Step 6: Commit**

```bash
git add includes/HierarchyGuard/Admin/Assets.php includes/HierarchyGuard/Admin/Tree_View.php includes/HierarchyGuard/Ledger.php includes/HierarchyGuard/Paths.php includes/HierarchyGuard/REST/Hierarchy_Controller.php includes/HierarchyGuard/Redirects/Server.php includes/HierarchyGuard/Settings.php tests/phpunit/AssetsTest.php tests/phpunit/HierarchyControllerTest.php tests/phpunit/LedgerTest.php tests/phpunit/PathsTest.php tests/phpunit/ServerTest.php tests/phpunit/SettingsTest.php tests/phpunit/TreeViewTest.php tests/phpunit/bootstrap.php tests/phpunit/stubs/vip.php
git commit -m "fix: refuse an array trash policy quietly, answer the trash preview only to users who can trash, follow paths of any depth, purge a VIP edge cache when a redirect changes, and print the list's stylesheet in the head"
```

---

### Task 18: A same-site URL target is stored as a path, so it follows the site to a new domain

**Findings:** 5.7, and the plan audits' C-3 and U-16 (same-site forms)

- After a WXR import on a new domain, an absolute same-site target pointed at the old domain. `Ledger::stored_url()` keeps a same-site URL as its path from the home URL, with its query and fragment, and `clean_target()` reads a `/path` against `home_url()`. Other sites' URLs stay absolute.
- On a site in a subdirectory, the home URL typed without its slash (`http://example.org/blog`) was stored as `/blog` and read back as `/blog/blog`. `Paths::relative()` now treats a path equal to the home path, without its slash and in any case, as `/`.
- A data provider pins eleven forms for a site at `http://example.org/blog`: the home URL with and without its slash and with a query, a page with a query and fragment, the other scheme, an upper-case host or home path, another port, the www host, a path outside the home path and one that only starts like it.

**Files:**
- Modify: `includes/HierarchyGuard/Ledger.php`
- Modify: `includes/HierarchyGuard/Paths.php`
- Test (modify): `tests/phpunit/LedgerTest.php`

**Interfaces:**
- Produces: private `Ledger::stored_url( string $url ): string`; `Ledger::set_target()` behaviour as described.

- [ ] **Step 1: Write the failing tests**

In `tests/phpunit/LedgerTest.php`, replace:

```php

	public function test_record_is_idempotent(): void {
```

with:

```php

	public function test_a_url_target_on_this_site_is_kept_as_a_path_and_follows_the_site_to_a_new_domain(): void {
		$this->ledger->record( $this->about, 'old-about' );
		$this->ledger->set_target( 'page', 'old-about', $this->about, home_url( '/news/?ref=old#latest' ), 302 );
		$this->ledger->record_root( 'page', 'archive' );
		$this->ledger->set_target( 'page', 'archive', 0, home_url( '/blog/' ), 0 );
		$this->ledger->record( $this->about, 'elsewhere' );
		$this->ledger->set_target( 'page', 'elsewhere', $this->about, 'https://jobs.example.org/open/', 0 );

		$this->assertSame( '/news/?ref=old#latest', get_post_meta( $this->about, Ledger::target_key( 'page', 'old-about' ), true )['url'], 'Stored as a path.' );
		$this->assertSame( '/blog/', get_option( Ledger::ROOT_TARGETS )['page']['archive']['url'] );
		$this->assertSame( 'https://jobs.example.org/open/', get_post_meta( $this->about, Ledger::target_key( 'page', 'elsewhere' ), true )['url'], 'Another site’s URL stays as it is.' );

		$moved = static fn(): string => 'https://new.example.com';
		add_filter( 'pre_option_home', $moved );
		wp_cache_set_last_changed( Ledger::CACHE_GROUP );
		$target = $this->ledger->target( 'page', 'old-about', $this->about );
		$root   = $this->ledger->target( 'page', 'archive', 0 );
		remove_filter( 'pre_option_home', $moved );

		$this->assertSame(
			[
				'url'  => 'https://new.example.com/news/?ref=old#latest',
				'code' => 302,
			],
			$target,
			'Read against the home URL, as after an import on a new domain.'
		);
		$this->assertSame( 'https://new.example.com/blog/', $root['url'] );
	}

	/**
	 * URLs typed for a site at http://example.org/blog, and what is stored.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function same_site_forms(): array {
		return [
			'the home URL without its slash' => [ 'http://example.org/blog', '/', 'http://example.org/blog/' ],
			'the home URL with its slash'    => [ 'http://example.org/blog/', '/', 'http://example.org/blog/' ],
			'the home URL with a query'      => [ 'http://example.org/blog?ref=1', '/?ref=1', 'http://example.org/blog/?ref=1' ],
			'a page, query and fragment'     => [ 'http://example.org/blog/about/?a=1#team', '/about/?a=1#team', 'http://example.org/blog/about/?a=1#team' ],
			'the other scheme'               => [ 'https://example.org/blog/about/', '/about/', 'http://example.org/blog/about/' ],
			'an upper-case host'             => [ 'http://EXAMPLE.org/blog/about/', '/about/', 'http://example.org/blog/about/' ],
			'an upper-case home path'        => [ 'http://example.org/BLOG/about/', '/about/', 'http://example.org/blog/about/' ],
			'another port'                   => [ 'http://example.org:8080/blog/about/', 'http://example.org:8080/blog/about/', 'http://example.org:8080/blog/about/' ],
			'the www host'                   => [ 'http://www.example.org/blog/about/', 'http://www.example.org/blog/about/', 'http://www.example.org/blog/about/' ],
			'a path outside the home path'   => [ 'http://example.org/other/', 'http://example.org/other/', 'http://example.org/other/' ],
			'a path starting like the home'  => [ 'http://example.org/blogroll/', 'http://example.org/blogroll/', 'http://example.org/blogroll/' ],
		];
	}

	/**
	 * @dataProvider same_site_forms
	 *
	 * @param string $typed  URL entered as the target.
	 * @param string $stored What the record keeps.
	 * @param string $read   What it is read back as.
	 */
	public function test_a_target_on_a_site_in_a_subdirectory_is_kept_as_a_path_only_when_it_is_under_the_home_url( string $typed, string $stored, string $read ): void {
		$home = static fn(): string => 'http://example.org/blog';
		add_filter( 'pre_option_home', $home );
		$this->ledger->record( $this->about, 'old-about' );
		$this->ledger->set_target( 'page', 'old-about', $this->about, $typed, 0 );
		wp_cache_set_last_changed( Ledger::CACHE_GROUP );
		$target = $this->ledger->target( 'page', 'old-about', $this->about );
		remove_filter( 'pre_option_home', $home );

		$this->assertSame( $stored, get_post_meta( $this->about, Ledger::target_key( 'page', 'old-about' ), true )['url'] );
		$this->assertSame( $read, $target['url'] );
	}

	public function test_record_is_idempotent(): void {
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npm run test:php -- --filter 'LedgerTest'`

Expected: FAIL as below; every other test in these classes passes.

- `LedgerTest::test_a_url_target_on_this_site_is_kept_as_a_path_and_follows_the_site_to_a_new_domain`: Stored as a path.
- 7 tests in `LedgerTest`: Failed asserting that two strings are identical.

Totals: `Tests: 76, Assertions: 271, Failures: 8.`

Mutant, to run once the tests pass, which shows `test_a_target_on_a_site_in_a_subdirectory_is_kept_as_a_path_only_when_it_is_under_the_home_url` pins its behaviour: in `includes/HierarchyGuard/Paths.php`, replace

```php
0 === strcasecmp( untrailingslashit( $path ), untrailingslashit( $home ) )
```

with:

```php
false
```

Expected: the test fails, as it did on the prototype:

```
LedgerTest::test_a_target_on_a_site_in_a_subdirectory_is_kept_as_a_path_only_when_it_is_under_the_home_url with data set "the home URL without its slash" ('http://example.org/blog', '/', 'http://example.org/blog/')
Failed asserting that two strings are identical.
LedgerTest::test_a_target_on_a_site_in_a_subdirectory_is_kept_as_a_path_only_when_it_is_under_the_home_url with data set "the home URL with a query" ('http://example.org/blog?ref=1', '/?ref=1', 'http://example.org/blog/?ref=1')
Failed asserting that two strings are identical.
Tests: 11, Assertions: 20, Failures: 2.
```

Then undo the mutant (`git checkout -- includes/HierarchyGuard/Paths.php`). Never commit it.

- [ ] **Step 3: Implement**

In `includes/HierarchyGuard/Ledger.php`, replace:

```php

		if ( $holder > 0 ) {
```

with:

```php

		if ( null !== $target ) {
			$target['url'] = self::stored_url( $target['url'] );
		}

		if ( $holder > 0 ) {
```

In `includes/HierarchyGuard/Ledger.php`, replace:

```php
	/**
	 * An override as stored, checked: an http(s) URL with a host or none,
	 * and 301, 302 or 0. Null when nothing is left.
```

with:

```php
	/**
	 * A URL target as it is stored: a URL on this site as its path from the
	 * home URL, with its query and fragment, so it follows the site to a new
	 * domain, as after a WordPress import there; any other URL as it is.
	 *
	 * @param string $url An http(s) URL, or ''.
	 * @return string
	 */
	private static function stored_url( string $url ): string {
		if ( '' === $url || ! Redirects\Server::is_here( $url ) ) {
			return $url;
		}
		$fragment = (string) wp_parse_url( $url, PHP_URL_FRAGMENT );
		return Paths::relative( $url ) . ( '' !== $fragment ? '#' . $fragment : '' );
	}

	/**
	 * An override as stored, checked: an http(s) URL with a host or none,
	 * and 301, 302 or 0. Null when nothing is left. A target stored as a path
	 * on this site is read against the home URL.
```

In `includes/HierarchyGuard/Ledger.php`, replace:

```php
		$url    = isset( $value['url'] ) && is_string( $value['url'] ) ? trim( $value['url'] ) : '';
```

with:

```php
		$url = isset( $value['url'] ) && is_string( $value['url'] ) ? trim( $value['url'] ) : '';
		if ( str_starts_with( $url, '/' ) && ! str_starts_with( $url, '//' ) ) {
			$url = home_url( $url );
		}
```

In `includes/HierarchyGuard/Paths.php`, replace:

```php
		// The home path in any case, as WP::parse_request() strips it.
		if ( '' !== $home && '/' !== $home && 0 === stripos( $path, $home ) ) {
```

with:

```php
		// The home path in any case, as WP::parse_request() strips it; typed without
		// its trailing slash it is the home URL itself.
		if ( '' !== $home && '/' !== $home && 0 === strcasecmp( untrailingslashit( $path ), untrailingslashit( $home ) ) ) {
			$path = '/';
		} elseif ( '' !== $home && '/' !== $home && 0 === stripos( $path, $home ) ) {
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `npm run test:php -- --filter 'LedgerTest'`

Expected: `OK`.

Run: `npm run test:php`, `npm run test:php:multisite` and `npm run test:js`

Expected: `Tests: 1040, Assertions: 4567, Skipped: 6.` (single site), `OK (1040 tests, 4607 assertions)` (multisite), and Vitest `Tests 187 passed (187)`.

- [ ] **Step 5: Lint and build**

Run: `composer lint && npm run lint:js && npm run lint:style && npm run build`

Expected: every command exits 0.

- [ ] **Step 6: Commit**

```bash
git add includes/HierarchyGuard/Ledger.php includes/HierarchyGuard/Paths.php tests/phpunit/LedgerTest.php
git commit -m "fix: keep a same-site URL target as a path, so it follows the site to a new domain"
```

---

### Task 19: The daily rebuild reads its newest entries through the `meta_key` index

**Findings:** 9.6

- With few ledger rows in a large postmeta table, the planner walked the primary key backwards. The rebuild query now says `FORCE INDEX ( meta_key )`, so its cost follows the ledger. A test runs `EXPLAIN` on it and checks the key used. (The audit's "accept" alternative was weighed; the hint is one clause and the test keeps it honest.)

**Files:**
- Modify: `includes/HierarchyGuard/Ledger.php`
- Test (modify): `tests/phpunit/LedgerTest.php`

**Interfaces:**
- No new interfaces.

- [ ] **Step 1: Write the failing tests**

In `tests/phpunit/LedgerTest.php`, replace:

```php

	public function test_records_made_before_the_list_reach_it_on_the_first_rebuild(): void {
```

with:

```php

	public function test_the_rebuild_reads_the_ledger_s_rows_through_the_meta_key_index(): void {
		global $wpdb;
		$this->ledger->record( $this->about, 'old-about' );
		$sql  = '';
		$keep = static function ( string $query ) use ( &$sql ): string {
			if ( str_contains( $query, 'ORDER BY pm.meta_id DESC LIMIT' ) ) {
				$sql = $query;
			}
			return $query;
		};
		add_filter( 'query', $keep );
		Ledger::rebuild_recent();
		remove_filter( 'query', $keep );

		$plan = $wpdb->get_results( 'EXPLAIN ' . $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The rebuild's own prepared query.
		$this->assertSame( 'meta_key', $plan[0]['key'], 'Not a walk of the primary key through every newer row of other meta.' );
		$this->assertSame( 'range', $plan[0]['type'] );
	}

	public function test_records_made_before_the_list_reach_it_on_the_first_rebuild(): void {
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npm run test:php -- --filter 'LedgerTest'`

Expected: FAIL as below; every other test in these classes passes.

- `LedgerTest::test_the_rebuild_reads_the_ledger_s_rows_through_the_meta_key_index`: Not a walk of the primary key through every newer row of other meta.

Totals: `Tests: 77, Assertions: 283, Failures: 1.`

Mutant, to run once the tests pass, which shows `test_the_rebuild_reads_the_ledger_s_rows_through_the_meta_key_index` pins its behaviour: in `includes/HierarchyGuard/Ledger.php`, replace

```php
 FORCE INDEX ( meta_key )
```

with nothing.

Expected: the test fails, as it did on the prototype:

```
LedgerTest::test_the_rebuild_reads_the_ledger_s_rows_through_the_meta_key_index
Not a walk of the primary key through every newer row of other meta.
Tests: 1, Assertions: 1, Failures: 1.
```

Then undo the mutant (`git checkout -- includes/HierarchyGuard/Ledger.php`). Never commit it.

- [ ] **Step 3: Implement**

In `includes/HierarchyGuard/Ledger.php`, replace:

```php
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- From WP-Cron only: the newest entries of one type, LIMIT the cap plus one.
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT pm.meta_id FROM {$wpdb->postmeta} pm STRAIGHT_JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key LIKE %s AND p.post_type = %s ORDER BY pm.meta_id DESC LIMIT %d", $wpdb->esc_like( self::KEY_PREFIX ) . '%', $type, $cap + 1 ) );
```

with:

```php
			// The meta_key range reads the ledger's rows only, then sorts them; left alone, the planner walks
			// postmeta's primary key backwards from the newest row, through every newer row of other meta.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- From WP-Cron only: the newest entries of one type, LIMIT the cap plus one.
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT pm.meta_id FROM {$wpdb->postmeta} pm FORCE INDEX ( meta_key ) STRAIGHT_JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key LIKE %s AND p.post_type = %s ORDER BY pm.meta_id DESC LIMIT %d", $wpdb->esc_like( self::KEY_PREFIX ) . '%', $type, $cap + 1 ) );
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `npm run test:php -- --filter 'LedgerTest'`

Expected: `OK`.

Run: `npm run test:php`, `npm run test:php:multisite` and `npm run test:js`

Expected: `Tests: 1041, Assertions: 4569, Skipped: 6.` (single site), `OK (1041 tests, 4609 assertions)` (multisite), and Vitest `Tests 187 passed (187)`.

- [ ] **Step 5: Lint and build**

Run: `composer lint && npm run lint:js && npm run lint:style && npm run build`

Expected: every command exits 0.

- [ ] **Step 6: Commit**

```bash
git add includes/HierarchyGuard/Ledger.php tests/phpunit/LedgerTest.php
git commit -m "perf: read the daily rebuild's newest entries through the meta_key index"
```

---

### Task 20: The Tree view's script loads only in the Tree view, as a bundle of its own

**Findings:** 8.3

- About a third of `list-table.js` was tree code loaded on every list. `src/tree/start.js` is a webpack entry of its own (`tree`); `Tree_View::controls()` fires the internal `hierarchy_guard_tree_script` action and `Assets::want_tree()` enqueues it after `list-table`, whose trash dialog it reuses. Its translations are set with `wp_set_script_translations()`.

**Files:**
- Modify: `includes/HierarchyGuard/Admin/Assets.php`
- Modify: `includes/HierarchyGuard/Admin/Tree_View.php`
- Modify: `src/list-table/index.js`
- Modify: `src/tree/index.js`
- Create: `src/tree/start.js`
- Modify: `webpack.config.js`
- Test (modify): `tests/phpunit/AssetsTest.php`

**Interfaces:**
- Produces: `Tree_View::SCRIPT = 'hierarchy_guard_tree_script'` (an `@internal` action), `Assets::want_tree(): void`; webpack entry `tree: './src/tree/start.js'`; `src/list-table/index.js` no longer calls `bindTree()`.

- [ ] **Step 1: Write the failing tests**

In `tests/phpunit/AssetsTest.php`, replace:

```php
		foreach ( [ 'list-table', 'redirects' ] as $bundle ) {
```

with:

```php
		foreach ( [ 'list-table', 'tree', 'redirects' ] as $bundle ) {
```

In `tests/phpunit/AssetsTest.php`, replace:

```php

	public function test_an_unsupported_screen_gets_nothing(): void {
```

with:

```php

	public function test_only_the_tree_view_gets_the_tree_s_bundle_after_the_list_s(): void {
		$this->build( 'list-table', [] );
		$this->build( 'tree', [ 'wp-a11y' ] );
		set_current_screen( 'edit-page' );
		$assets = new Assets( new Providers(), $this->dir );
		$assets->want_list_table();

		$assets->enqueue_list_table();
		$this->assertFalse( wp_script_is( 'hierarchy-guard-tree', 'enqueued' ), 'The flat list never runs the tree.' );

		$assets->want_tree();
		$assets->enqueue_list_table();
		$this->assertTrue( wp_script_is( 'hierarchy-guard-tree', 'enqueued' ) );
		$this->assertContains( 'hierarchy-guard-list-table', wp_scripts()->registered['hierarchy-guard-tree']->deps, 'After the list’s, whose settings it reads and whose trash dialog it uses.' );
		$this->assertSame( 'hierarchy-guard', wp_scripts()->registered['hierarchy-guard-tree']->textdomain );
		$this->assertFalse( wp_scripts()->get_data( 'hierarchy-guard-tree', 'before' ), 'The settings print once, with the list’s bundle.' );
	}

	public function test_the_tree_view_asks_for_the_tree_s_bundle(): void {
		global $wp_filter;
		$found = false;
		foreach ( $wp_filter[ Tree_View::SCRIPT ]->callbacks[10] ?? [] as $callback ) {
			$found = $found || ( is_array( $callback['function'] ) && $callback['function'][0] instanceof Assets && 'want_tree' === $callback['function'][1] );
		}
		$this->assertTrue( $found );

		$pagenow = $GLOBALS['pagenow'] ?? '';
		set_current_screen( 'edit-page' );
		$GLOBALS['pagenow'] = 'edit.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- As the list screen sets it.
		$_GET               = [
			'post_type'          => 'page',
			Tree_View::QUERY_VAR => Tree_View::VIEW,
		];
		ob_start();
		( new Tree_View() )->controls( 'page' );
		ob_end_clean();
		$_GET               = [];
		$GLOBALS['pagenow'] = $pagenow; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Put back.
		$this->assertSame( 1, did_action( Tree_View::SCRIPT ) );
	}

	public function test_an_unsupported_screen_gets_nothing(): void {
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npm run test:php -- --filter 'AssetsTest'`

Expected: FAIL as below; every other test in these classes passes.

- `AssetsTest::test_only_the_tree_view_gets_the_tree_s_bundle_after_the_list_s`: Error: Call to undefined method HierarchyGuard\Admin\Assets::want_tree()
- `AssetsTest::test_the_tree_view_asks_for_the_tree_s_bundle`: Error: Undefined constant HierarchyGuard\Admin\Tree_View::SCRIPT

Totals: `Tests: 12, Assertions: 37, Errors: 2.`

Mutant, to run once the tests pass, which shows `test_only_the_tree_view_gets_the_tree_s_bundle_after_the_list_s` pins its behaviour: in `includes/HierarchyGuard/Admin/Assets.php`, replace

```php
if ( $this->tree && $this->enqueue(
```

with:

```php
if ( $this->enqueue(
```

Expected: the test fails, as it did on the prototype:

```
AssetsTest::test_only_the_tree_view_gets_the_tree_s_bundle_after_the_list_s
The flat list never runs the tree.
Tests: 1, Assertions: 1, Failures: 1.
```

Then undo the mutant (`git checkout -- includes/HierarchyGuard/Admin/Assets.php`). Never commit it.

- [ ] **Step 3: Implement**

In `includes/HierarchyGuard/Admin/Assets.php`, replace:

```php
	private bool $wanted = false;

	/**
```

with:

```php
	private bool $wanted = false;

	/**
	 * Whether the Tree view asked for the tree's script.
	 *
	 * @var bool
	 */
	private bool $tree = false;

	/**
```

In `includes/HierarchyGuard/Admin/Assets.php`, replace:

```php
		add_action( List_Table::SCRIPT, [ $this, 'want_list_table' ] );
```

with:

```php
		add_action( List_Table::SCRIPT, [ $this, 'want_list_table' ] );
		add_action( Tree_View::SCRIPT, [ $this, 'want_tree' ] );
```

In `includes/HierarchyGuard/Admin/Assets.php`, replace:

```php
	 * Enqueues the list-screen script in the footer, if the screen asked for it.
```

with:

```php
	 * Notes that the Tree view loads, which needs the tree's script too.
	 */
	public function want_tree(): void {
		$this->tree = true;
	}

	/**
	 * Enqueues the list-screen script in the footer, if the screen asked for
	 * it, and in the Tree view the tree's after it, sharing its settings.
```

In `includes/HierarchyGuard/Admin/Assets.php`, replace:

```php
		$this->enqueue( 'list-table', [ 'jquery', 'inline-edit-post' ] );
```

with:

```php
		$this->enqueue( 'list-table', [ 'jquery', 'inline-edit-post' ] );
		if ( $this->tree && $this->enqueue( 'tree', [ self::HANDLE_PREFIX . 'list-table' ], false ) ) {
			wp_set_script_translations( self::HANDLE_PREFIX . 'tree', 'hierarchy-guard' );
		}
```

In `includes/HierarchyGuard/Admin/Tree_View.php`, replace:

```php
	public const PER_PAGE          = 50;
```

with:

```php
	public const PER_PAGE          = 50;
	public const SCRIPT            = 'hierarchy_guard_tree_script';
```

In `includes/HierarchyGuard/Admin/Tree_View.php`, replace:

```php
		do_action( 'hierarchy_guard_list_script' );
```

with:

```php
		do_action( List_Table::SCRIPT ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- The plugin's own hook, by its constant: hierarchy_guard_list_script.
		/**
		 * Fires when the Tree view, or a search in it, needs the tree's script.
		 *
		 * @internal
		 */
		do_action( self::SCRIPT ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- The plugin's own hook, by its constant: hierarchy_guard_tree_script.
```

In `src/list-table/index.js`, replace:

```js
 * modal behind the URLs notice, the filter subtitle, the nudge and the Tree
 * view.
```

with:

```js
 * modal behind the URLs notice, the filter subtitle and the nudge. The Tree
 * view has a bundle of its own.
```

In `src/list-table/index.js`, replace:

```js
import RedirectsModal from '../common/redirects-modal';
import bindTree from '../tree';
```

with:

```js
import RedirectsModal from '../common/redirects-modal';
```

In `src/list-table/index.js`, replace:

```js
	bindBulkEdit();
	bindTree();
```

with:

```js
	bindBulkEdit();
```

In `src/tree/index.js`, replace:

```js
 * The Tree view's script, part of the list-table bundle: the tree grid on
 * core's table, "Collapse all", and "Back to the tree" beside a search's
 * heading.
```

with:

```js
 * The Tree view's script: the tree grid on core's table, "Collapse all", and
 * "Back to the tree" beside a search's heading. A trash from a loaded row goes
 * through the list screen's own trash dialog.
```

Create `src/tree/start.js`:

```js
/**
 * The Tree view's bundle, loaded after the list screen's, only in that view.
 */
import domReady from '@wordpress/dom-ready';
import bindTree from '.';

domReady( () => {
	bindTree();
} );
```

In `webpack.config.js`, replace:

```js
 * One bundle per screen: the block editor panel, the list screen, the
```

with:

```js
 * One bundle per screen: the block editor panel, the list screen, the Tree
 * view (loaded with the list screen's bundle, only in that view), the
```

In `webpack.config.js`, replace:

```js
		'list-table': './src/list-table/index.js',
```

with:

```js
		'list-table': './src/list-table/index.js',
		tree: './src/tree/start.js',
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `npm run test:php -- --filter 'AssetsTest'`

Expected: `OK`.

Run: `npm run test:php`, `npm run test:php:multisite` and `npm run test:js`

Expected: `Tests: 1043, Assertions: 4576, Skipped: 6.` (single site), `OK (1043 tests, 4616 assertions)` (multisite), and Vitest `Tests 187 passed (187)`.

- [ ] **Step 5: Lint and build**

Run: `composer lint && npm run lint:js && npm run lint:style && npm run build`

Expected: every command exits 0.

- [ ] **Step 6: Commit**

```bash
git add includes/HierarchyGuard/Admin/Assets.php includes/HierarchyGuard/Admin/Tree_View.php src/list-table/index.js src/tree/index.js src/tree/start.js tests/phpunit/AssetsTest.php webpack.config.js
git commit -m "perf: load the Tree view's script only in the Tree view, as a bundle of its own"
```

---

### Task 21: The Hierarchy panel and its warnings load in the Site Editor's page editing

**Findings:** 3.2

- A block theme's Site Editor edits pages too, but the editor bundle never loaded there. `Assets::enqueue_editor()` now also loads it on the `site-editor` screen; the panel acts only while the edited entity is a supported post type, which it already checks.

**Files:**
- Modify: `includes/HierarchyGuard/Admin/Assets.php`
- Test (modify): `tests/phpunit/AssetsTest.php`

**Interfaces:**
- No new interfaces.

- [ ] **Step 1: Write the failing tests**

In `tests/phpunit/AssetsTest.php`, replace:

```php
		foreach ( [ 'list-table', 'tree', 'redirects' ] as $bundle ) {
```

with:

```php
		foreach ( [ 'list-table', 'tree', 'redirects', 'editor' ] as $bundle ) {
```

In `tests/phpunit/AssetsTest.php`, replace:

```php

	public function test_a_bundle_s_right_to_left_stylesheet_replaces_it_right_to_left(): void {
```

with:

```php

	public function test_the_editor_bundle_loads_in_the_block_editor_of_a_supported_type_and_in_the_site_editor(): void {
		$this->build( 'editor', [] );
		$assets = new Assets( new Providers(), $this->dir );

		set_current_screen( 'post' );
		$assets->enqueue_editor();
		$this->assertFalse( wp_script_is( 'hierarchy-guard-editor', 'enqueued' ), 'Posts are not hierarchical.' );

		set_current_screen( 'site-editor' );
		$assets->enqueue_editor();
		$this->assertTrue( wp_script_is( 'hierarchy-guard-editor', 'enqueued' ), 'The Site Editor’s Pages screen edits pages: the panel checks the type it edits.' );
		wp_dequeue_script( 'hierarchy-guard-editor' );

		set_current_screen( 'page' );
		$assets->enqueue_editor();
		$this->assertTrue( wp_script_is( 'hierarchy-guard-editor', 'enqueued' ) );
	}

	public function test_a_bundle_s_right_to_left_stylesheet_replaces_it_right_to_left(): void {
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npm run test:php -- --filter 'AssetsTest'`

Expected: FAIL as below; every other test in these classes passes.

- `AssetsTest::test_the_editor_bundle_loads_in_the_block_editor_of_a_supported_type_and_in_the_site_editor`: The Site Editor’s Pages screen edits pages: the panel checks the type it edits.

Totals: `Tests: 13, Assertions: 45, Failures: 1.`

Mutant, to run once the tests pass, which shows `test_the_editor_bundle_loads_in_the_block_editor_of_a_supported_type_and_in_the_site_editor` pins its behaviour: in `includes/HierarchyGuard/Admin/Assets.php`, replace

```php
if ( 'site-editor' !== $screen->base && ! Post_Types::is_supported(
```

with:

```php
if ( ! Post_Types::is_supported(
```

Expected: the test fails, as it did on the prototype:

```
AssetsTest::test_the_editor_bundle_loads_in_the_block_editor_of_a_supported_type_and_in_the_site_editor
The Site Editor’s Pages screen edits pages: the panel checks the type it edits.
Tests: 1, Assertions: 2, Failures: 1.
```

Then undo the mutant (`git checkout -- includes/HierarchyGuard/Admin/Assets.php`). Never commit it.

- [ ] **Step 3: Implement**

In `includes/HierarchyGuard/Admin/Assets.php`, replace:

```php
	 * The editor panel, for supported types.
	 */
	public function enqueue_editor(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen instanceof WP_Screen || ! Post_Types::is_supported( (string) $screen->post_type ) ) {
```

with:

```php
	 * The editor panel, for supported types, and in the Site Editor, whose
	 * Pages screen edits pages too: there the screen names no post type, and
	 * the panel acts only while the page it edits is of a supported type.
	 */
	public function enqueue_editor(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen instanceof WP_Screen ) {
			return;
		}
		if ( 'site-editor' !== $screen->base && ! Post_Types::is_supported( (string) $screen->post_type ) ) {
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `npm run test:php -- --filter 'AssetsTest'`

Expected: `OK`.

Run: `npm run test:php`, `npm run test:php:multisite` and `npm run test:js`

Expected: `Tests: 1044, Assertions: 4579, Skipped: 6.` (single site), `OK (1044 tests, 4619 assertions)` (multisite), and Vitest `Tests 187 passed (187)`.

- [ ] **Step 5: Lint and build**

Run: `composer lint && npm run lint:js && npm run lint:style && npm run build`

Expected: every command exits 0.

- [ ] **Step 6: Commit**

```bash
git add includes/HierarchyGuard/Admin/Assets.php tests/phpunit/AssetsTest.php
git commit -m "feat: load the Hierarchy panel and its warnings in the Site Editor's page editing"
```

---

### Task 22: The plugin's objects are reachable through `Plugin::get()`; every public hook says `@since 1.0.0`

**Findings:** 4.4, 2.13, and the plan audits' C-7/U-17

- Services were locals of `boot()`, so a third party could not unhook one. `Plugin` keeps each object it hooks (`keep()`) and `Plugin::instance()->get( Server::class )` returns it.
- Public hooks (13): `hierarchy_guard_post_types`, `_should_record`, `_redirect_providers`, `_redirect_status`, `_redirect_url`, `_max_segments`, `_default_trash_policy`, `_trash_policy`, `_count_cap`, `_batch_threshold`, `_core_call_threshold`, `_children_reparented`, `_job_finished`; each docblock gets `@since 1.0.0`. `hierarchy_guard_list_script` and `hierarchy_guard_tree_script` are signals between the plugin's own classes and are marked `@internal`. Documenting them in the readme is 6b (4.3).
- The guard denies by default: it finds every `apply_filters()` and `do_action()` call in `includes/`, requires a docblock right above each `hierarchy_guard_` literal with `@since 1.0.0`, counts the one "is documented in" reference (the restore's second `children_reparented`), asserts the exact 13 public names, and counts every other call: four core hooks once each, `List_Table::SCRIPT` five times and `Tree_View::SCRIPT` once, each declared `@internal`.

**Files:**
- Modify: `includes/HierarchyGuard/Batch/Processor.php`
- Modify: `includes/HierarchyGuard/Ledger.php`
- Modify: `includes/HierarchyGuard/Plugin.php`
- Modify: `includes/HierarchyGuard/Post_Types.php`
- Modify: `includes/HierarchyGuard/Redirects/Providers.php`
- Modify: `includes/HierarchyGuard/Redirects/Server.php`
- Modify: `includes/HierarchyGuard/Settings.php`
- Modify: `includes/HierarchyGuard/Subtree.php`
- Modify: `includes/HierarchyGuard/Trash/Operations.php`
- Modify: `includes/HierarchyGuard/Trash/Trash_Handler.php`
- Test (modify): `tests/phpunit/PluginTest.php`

**Interfaces:**
- Produces: `Plugin::get( string $class_name ): ?object`.

- [ ] **Step 1: Write the failing tests**

In `tests/phpunit/PluginTest.php`, replace:

```php
use HierarchyGuard\Plugin;
```

with:

```php
use HierarchyGuard\Plugin;
use HierarchyGuard\Redirects\Server;
```

In `tests/phpunit/PluginTest.php`, replace:

```php

	public function test_every_shipped_php_file_stops_when_requested_directly(): void {
```

with:

```php

	public function test_an_integration_can_reach_and_unhook_the_plugin_s_own_objects(): void {
		$server = Plugin::instance()->get( Server::class );

		$this->assertInstanceOf( Server::class, $server );
		$this->assertSame( $server, Plugin::instance()->get( '\\' . Server::class ), 'With or without the leading backslash.' );
		$this->assertSame( Server::PRIORITY, has_action( 'template_redirect', [ $server, 'maybe_redirect' ] ) );
		$this->assertTrue( remove_action( 'template_redirect', [ $server, 'maybe_redirect' ], Server::PRIORITY ) );
		add_action( 'template_redirect', [ $server, 'maybe_redirect' ], Server::PRIORITY );
		$this->assertInstanceOf( Ledger::class, Plugin::instance()->get( Ledger::class ) );
		$this->assertNull( Plugin::instance()->get( 'Nope' ) );
	}

	public function test_every_public_hook_says_since_when(): void {
		$public    = [];
		$seen      = [];
		$elsewhere = 0;
		$root      = dirname( __DIR__, 2 ) . '/includes';
		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) ) as $file ) {
			$code  = (string) file_get_contents( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local source file.
			$class = basename( $file->getPathname(), '.php' );
			preg_match_all( '#\b(?:apply_filters|do_action)(?:_ref_array|_deprecated)?\(\s*([^,)]+?)\s*[,)]#', $code, $calls, PREG_OFFSET_CAPTURE );
			foreach ( $calls[1] as [ $hook, $offset ] ) {
				$hook   = str_replace( 'self::', $class . '::', $hook );
				$seen[] = $hook;
				if ( 1 !== preg_match( "#^'(hierarchy_guard_[a-z_]+)'$#", $hook, $name ) ) {
					continue;
				}
				// The docblock right above the call's own line.
				$before = substr( $code, 0, $offset );
				$end    = (int) strrpos( $before, '*/' );
				$doc    = substr( $before, (int) strrpos( substr( $before, 0, $end ), '/**' ), $end );
				$this->assertSame( 1, preg_match( '#\A[ \t]*\n[^\n;{}]*\z#', substr( $before, $end + 2 ) ), $name[1] . ' has a docblock right above it.' );
				if ( str_contains( $doc, 'is documented in' ) ) {
					++$elsewhere;
					$this->assertSame( 'hierarchy_guard_children_reparented', $name[1], 'Only the restore\'s second call refers to another docblock.' );
					continue;
				}
				$this->assertStringContainsString( '@since 1.0.0', $doc, $name[1] );
				$public[] = $name[1];
			}
		}

		sort( $public );
		$this->assertSame(
			[
				'hierarchy_guard_batch_threshold',
				'hierarchy_guard_children_reparented',
				'hierarchy_guard_core_call_threshold',
				'hierarchy_guard_count_cap',
				'hierarchy_guard_default_trash_policy',
				'hierarchy_guard_job_finished',
				'hierarchy_guard_max_segments',
				'hierarchy_guard_post_types',
				'hierarchy_guard_redirect_providers',
				'hierarchy_guard_redirect_status',
				'hierarchy_guard_redirect_url',
				'hierarchy_guard_should_record',
				'hierarchy_guard_trash_policy',
			],
			$public,
			'The 13 public hooks, each documented once.'
		);
		$this->assertSame( 1, $elsewhere );

		// Every other call is a core hook or one of the two internal signals, each counted.
		$others = array_count_values( array_diff( $seen, array_map( static fn( string $hook ): string => "'{$hook}'", $public ), [ "'hierarchy_guard_children_reparented'" ] ) );
		ksort( $others );
		$this->assertSame(
			[
				'"edit_{$post_type}_per_page"' => 1,
				"'edit_posts_per_page'"        => 1,
				"'the_title'"                  => 1,
				"'wp_check_post_lock_window'"  => 1,
				'List_Table::SCRIPT'           => 5,
				'Tree_View::SCRIPT'            => 1,
			],
			$others
		);
		foreach ( [ 'Admin/List_Table.php', 'Admin/Tree_View.php' ] as $declares ) {
			$code = (string) file_get_contents( $root . '/HierarchyGuard/' . $declares ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local source file.
			$this->assertSame( 1, preg_match( '#@internal\s*\*/\s*do_action\( self::SCRIPT \)#', $code ), $declares . ' marks its signal internal.' );
		}
	}

	public function test_every_shipped_php_file_stops_when_requested_directly(): void {
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npm run test:php -- --filter 'PluginTest'`

Expected: FAIL as below; every other test in these classes passes.

- `PluginTest::test_an_integration_can_reach_and_unhook_the_plugin_s_own_objects`: Error: Call to undefined method HierarchyGuard\Plugin::get()
- `PluginTest::test_every_public_hook_says_since_when`: hierarchy_guard_count_cap

Totals: `Tests: 7, Assertions: 148, Errors: 1, Failures: 1, Skipped: 1.`

Mutant, to run once the tests pass, which shows `test_every_public_hook_says_since_when` pins its behaviour: in `includes/HierarchyGuard/Subtree.php`, replace

```php
		 * @since 1.0.0
		 *
		 * @param int $cap Largest count reported exactly
```

with:

```php
		 * @param int $cap Largest count reported exactly
```

Expected: the test fails, as it did on the prototype:

```
PluginTest::test_every_public_hook_says_since_when
hierarchy_guard_count_cap
Tests: 1, Assertions: 2, Failures: 1.
```

Then undo the mutant (`git checkout -- includes/HierarchyGuard/Subtree.php`). Never commit it.

- [ ] **Step 3: Implement**

In `includes/HierarchyGuard/Batch/Processor.php`, replace:

```php
					 *
					 * @param array<string,mixed> $job The finished job.
```

with:

```php
					 *
					 * @since 1.0.0
					 *
					 * @param array<string,mixed> $job The finished job.
```

In `includes/HierarchyGuard/Ledger.php`, replace:

```php
		 *
		 * @param bool    $record   Whether to record.
```

with:

```php
		 *
		 * @since 1.0.0
		 *
		 * @param bool    $record   Whether to record.
```

In `includes/HierarchyGuard/Plugin.php`, replace:

```php
	private bool $registered = false;

	/**
```

with:

```php
	private bool $registered = false;

	/**
	 * The objects register() created, by class name.
	 *
	 * @var array<string,object>
	 */
	private array $services = [];

	/**
```

In `includes/HierarchyGuard/Plugin.php`, replace:

```php
	}

	/**
	 * What a site needs once, on activation, after an update and after a
```

with:

```php
	}

	/**
	 * The plugin's object of a class, as it was hooked in, or null. With it
	 * an integration can unhook one part, for example the redirect server:
	 * remove_action( 'template_redirect', [ Plugin::instance()->get( Redirects\Server::class ), 'maybe_redirect' ], 9 ).
	 *
	 * @since 1.0.0
	 *
	 * @param string $class_name Class name, such as Redirects\Server::class.
	 * @return object|null
	 */
	public function get( string $class_name ): ?object {
		return $this->services[ ltrim( $class_name, '\\' ) ] ?? null;
	}

	/**
	 * Keeps an object register() creates, for get().
	 *
	 * @template T of object
	 * @param T $service The object.
	 * @return T
	 */
	private function keep( object $service ): object {
		$this->services[ get_class( $service ) ] = $service;
		return $service;
	}

	/**
	 * What a site needs once, on activation, after an update and after a
```

In `includes/HierarchyGuard/Plugin.php`, replace:

```php
		$ledger    = new Ledger();
		$log       = new Trash\Change_Log();
		$providers = new Redirects\Providers();
		( new Change_Listener( $ledger, $log ) )->register();
		( new Redirects\Server( new Redirects\Resolver( $ledger ) ) )->register();
		add_action( 'init', [ Settings::class, 'register_option' ] );
		( new Admin\Settings_Page( $providers, new Redirects\Records( $ledger ) ) )->register();
		( new Trash\Markers() )->register();
		( new Meta_Guard() )->register();

		$queue      = new Batch\Queue();
		$operations = new Trash\Operations( $ledger, new Trash\Mover() );
		$processor  = new Batch\Processor( $queue, $operations, $log );
		$processor->register();
		( new Trash\Trash_Handler( $ledger, $operations, $queue, $log ) )->register();
		( new Compat\Yoast() )->register();

		$pairs    = new Redirects\Pairs();
		$overview = new Overview( $providers, $log );
		( new Admin\Notices( $log, $queue, $pairs, $providers ) )->register();
		$tree = new Admin\Tree_View();
		$tree->register();
		( new Admin\List_Table( $tree ) )->register();
		( new REST\Hierarchy_Controller( $overview ) )->register();
		( new REST\Csv_Download() )->register();
		( new Admin\Trash_Choice() )->register();
		( new REST\Changes_Controller( $log, $pairs ) )->register();
		( new Admin\Meta_Box( $overview ) )->register();
		( new Admin\Assets( $providers ) )->register();
```

with:

```php
		$ledger    = $this->keep( new Ledger() );
		$log       = $this->keep( new Trash\Change_Log() );
		$providers = $this->keep( new Redirects\Providers() );
		$this->keep( new Change_Listener( $ledger, $log ) )->register();
		$this->keep( new Redirects\Server( new Redirects\Resolver( $ledger ) ) )->register();
		add_action( 'init', [ Settings::class, 'register_option' ] );
		$this->keep( new Admin\Settings_Page( $providers, new Redirects\Records( $ledger ) ) )->register();
		$this->keep( new Trash\Markers() )->register();
		$this->keep( new Meta_Guard() )->register();

		$queue      = $this->keep( new Batch\Queue() );
		$operations = $this->keep( new Trash\Operations( $ledger, new Trash\Mover() ) );
		$processor  = $this->keep( new Batch\Processor( $queue, $operations, $log ) );
		$processor->register();
		$this->keep( new Trash\Trash_Handler( $ledger, $operations, $queue, $log ) )->register();
		$this->keep( new Compat\Yoast() )->register();

		$pairs    = $this->keep( new Redirects\Pairs() );
		$overview = $this->keep( new Overview( $providers, $log ) );
		$this->keep( new Admin\Notices( $log, $queue, $pairs, $providers ) )->register();
		$tree = $this->keep( new Admin\Tree_View() );
		$tree->register();
		$this->keep( new Admin\List_Table( $tree ) )->register();
		$this->keep( new REST\Hierarchy_Controller( $overview ) )->register();
		$this->keep( new REST\Csv_Download() )->register();
		$this->keep( new Admin\Trash_Choice() )->register();
		$this->keep( new REST\Changes_Controller( $log, $pairs ) )->register();
		$this->keep( new Admin\Meta_Box( $overview ) )->register();
		$this->keep( new Admin\Assets( $providers ) )->register();
```

In `includes/HierarchyGuard/Post_Types.php`, replace:

```php
		 *
		 * @param string[] $types Post type names.
```

with:

```php
		 *
		 * @since 1.0.0
		 *
		 * @param string[] $types Post type names.
```

In `includes/HierarchyGuard/Redirects/Providers.php`, replace:

```php
		 *
		 * @param array<string,array{name:string,active:bool,url:string,csv:string,note?:string}> $providers Keyed by slug: the plugin's name, whether it is active, its redirects screen, one of the Csv::LAYOUTS names, and an optional sentence shown before importing.
```

with:

```php
		 *
		 * @since 1.0.0
		 *
		 * @param array<string,array{name:string,active:bool,url:string,csv:string,note?:string}> $providers Keyed by slug: the plugin's name, whether it is active, its redirects screen, one of the Csv::LAYOUTS names, and an optional sentence shown before importing.
```

In `includes/HierarchyGuard/Redirects/Server.php`, replace:

```php
		 *
		 * @param int          $status Status code: the 301 or 302 chosen on the Redirects tab, or else 301, or 302 while a restore from the trash could still undo the move the redirect follows.
```

with:

```php
		 *
		 * @since 1.0.0
		 *
		 * @param int          $status Status code: the 301 or 302 chosen on the Redirects tab, or else 301, or 302 while a restore from the trash could still undo the move the redirect follows.
```

In `includes/HierarchyGuard/Redirects/Server.php`, replace:

```php
		 *
		 * @param string       $url    Target URL, with the request's query string appended.
```

with:

```php
		 *
		 * @since 1.0.0
		 *
		 * @param string       $url    Target URL, with the request's query string appended.
```

In `includes/HierarchyGuard/Redirects/Server.php`, replace:

```php
		 *
		 * @param int $max Segment cap.
```

with:

```php
		 *
		 * @since 1.0.0
		 *
		 * @param int $max Segment cap.
```

In `includes/HierarchyGuard/Settings.php`, replace:

```php
		 *
		 * @param string $policy One of Settings::POLICIES.
```

with:

```php
		 *
		 * @since 1.0.0
		 *
		 * @param string $policy One of Settings::POLICIES.
```

In `includes/HierarchyGuard/Subtree.php`, replace:

```php
		 *
		 * @param int $cap Largest count reported exactly, 1,000 by default.
```

with:

```php
		 *
		 * @since 1.0.0
		 *
		 * @param int $cap Largest count reported exactly, 1,000 by default.
```

In `includes/HierarchyGuard/Trash/Operations.php`, replace:

```php
		 *
		 * @param int $threshold Moves per step, 200 by default.
```

with:

```php
		 *
		 * @since 1.0.0
		 *
		 * @param int $threshold Moves per step, 200 by default.
```

In `includes/HierarchyGuard/Trash/Operations.php`, replace:

```php
		 *
		 * @param int $threshold Core calls per step, 25 by default.
```

with:

```php
		 *
		 * @since 1.0.0
		 *
		 * @param int $threshold Core calls per step, 25 by default.
```

In `includes/HierarchyGuard/Trash/Operations.php`, replace:

```php
			 *
			 * @param int[] $ids  Moved post IDs.
```

with:

```php
			 *
			 * @since 1.0.0
			 *
			 * @param int[] $ids  Moved post IDs.
```

In `includes/HierarchyGuard/Trash/Trash_Handler.php`, replace:

```php
		 *
		 * @param string  $policy One of Settings::POLICIES.
```

with:

```php
		 *
		 * @since 1.0.0
		 *
		 * @param string  $policy One of Settings::POLICIES.
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `npm run test:php -- --filter 'PluginTest'`

Expected: `OK`.

Run: `npm run test:php`, `npm run test:php:multisite` and `npm run test:js`

Expected: `Tests: 1046, Assertions: 4618, Skipped: 6.` (single site), `OK (1046 tests, 4658 assertions)` (multisite), and Vitest `Tests 187 passed (187)`.

- [ ] **Step 5: Lint and build**

Run: `composer lint && npm run lint:js && npm run lint:style && npm run build`

Expected: every command exits 0.

- [ ] **Step 6: Commit**

```bash
git add includes/HierarchyGuard/Batch/Processor.php includes/HierarchyGuard/Ledger.php includes/HierarchyGuard/Plugin.php includes/HierarchyGuard/Post_Types.php includes/HierarchyGuard/Redirects/Providers.php includes/HierarchyGuard/Redirects/Server.php includes/HierarchyGuard/Settings.php includes/HierarchyGuard/Subtree.php includes/HierarchyGuard/Trash/Operations.php includes/HierarchyGuard/Trash/Trash_Handler.php tests/phpunit/PluginTest.php
git commit -m "feat: reach the plugin's objects through Plugin::get(), and mark every public hook @since 1.0.0"
```

---

### Task 23: A moved marker keeps a redirect temporary only while the page that moved it exists

**Findings:** 5.3

- A root deleted for good while the plugin was inactive left moved markers behind, which kept redirects at 302 forever. `Markers::has_moved_row()` and `moved_among()` count a moved row only while its root post exists. Uninstall removing the markers is 6b (3.5).

**Files:**
- Modify: `includes/HierarchyGuard/Trash/Markers.php`
- Test (modify): `tests/phpunit/MarkersTest.php`
- Test (modify): `tests/phpunit/ResolverTest.php`

**Interfaces:**
- No new interfaces.

- [ ] **Step 1: Write the failing tests**

In `tests/phpunit/MarkersTest.php`, replace:

```php

	public function test_count_covers_both_kinds_and_stops_at_the_cap(): void {
```

with:

```php

	public function test_a_moved_row_counts_only_while_its_root_exists(): void {
		$root  = self::factory()->post->create( [ 'post_type' => 'page' ] );
		$moved = self::factory()->post->create( [ 'post_type' => 'page' ] );
		$left  = self::factory()->post->create( [ 'post_type' => 'page' ] );
		wp_trash_post( $root );
		Markers::add( $moved, Markers::moved_key( $root ), '0|a|a|1' );
		// A root deleted for good while the plugin was inactive leaves its row behind.
		Markers::add( $left, Markers::moved_key( 999999 ), '0|b|b|1' );

		$this->assertTrue( Markers::has_moved_row( $moved ), 'A restore of its root can still move it back.' );
		$this->assertFalse( Markers::has_moved_row( $left ), 'Nothing can move it back any more.' );
		$this->assertSame( [ $moved => true ], Markers::moved_among( [ $moved, $left ] ) );
	}

	public function test_count_covers_both_kinds_and_stops_at_the_cap(): void {
```

In `tests/phpunit/ResolverTest.php`, replace:

```php

	public function test_an_unknown_path_twenty_segments_deep_costs_one_ledger_query_then_none(): void {
```

with:

```php

	public function test_a_page_left_with_the_moved_row_of_a_root_deleted_for_good_is_redirected_to_for_good(): void {
		$this->ledger->record( $this->team, 'old-team' );
		Markers::add( $this->team, Markers::moved_key( 999999 ), '0|team|team|1' );

		$temporary = null;
		$this->assertSame( $this->team, $this->resolver->resolve( 'page', 'old-team', $temporary )->ID );
		$this->assertFalse( $temporary, 'A 301: no restore can move it back.' );

		Markers::add( $this->team, Markers::moved_key( $this->company ), '0|team|team|1' );
		$this->resolver->resolve( 'page', 'old-team', $temporary );
		$this->assertTrue( $temporary, 'A 302 while a page that moved it is still there.' );
	}

	public function test_an_unknown_path_twenty_segments_deep_costs_one_ledger_query_then_none(): void {
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npm run test:php -- --filter 'MarkersTest|ResolverTest'`

Expected: FAIL as below; every other test in these classes passes.

- `MarkersTest::test_a_moved_row_counts_only_while_its_root_exists`: Nothing can move it back any more.
- `ResolverTest::test_a_page_left_with_the_moved_row_of_a_root_deleted_for_good_is_redirected_to_for_good`: A 301: no restore can move it back.

Totals: `Tests: 48, Assertions: 156, Failures: 2.`

Mutant, to run once the tests pass, which shows `test_a_moved_row_counts_only_while_its_root_exists` pins its behaviour: in `includes/HierarchyGuard/Trash/Markers.php`, replace

```php
if ( false !== get_post_status( (int) substr( $key, strlen( self::MOVED_PREFIX ) ) ) ) {
```

with:

```php
if ( true ) {
```

Expected: the test fails, as it did on the prototype:

```
MarkersTest::test_a_moved_row_counts_only_while_its_root_exists
Nothing can move it back any more.
Tests: 1, Assertions: 2, Failures: 1.
```

Then undo the mutant (`git checkout -- includes/HierarchyGuard/Trash/Markers.php`). Never commit it.

- [ ] **Step 3: Implement**

In `includes/HierarchyGuard/Trash/Markers.php`, replace:

```php
	 * Whether a post sits where the trash flow moved it, so a restore may still move it back.
```

with:

```php
	 * Whether a post sits where the trash flow moved it, so a restore may
	 * still move it back: it has a moved row whose root still exists. A root
	 * deleted for good while the plugin was inactive leaves its rows behind,
	 * and nothing can move the post back any more.
```

In `includes/HierarchyGuard/Trash/Markers.php`, replace:

```php
		return [] !== self::keys_with_prefix( $post_id, self::MOVED_PREFIX );
```

with:

```php
		foreach ( self::keys_with_prefix( $post_id, self::MOVED_PREFIX ) as $key ) {
			if ( false !== get_post_status( (int) substr( $key, strlen( self::MOVED_PREFIX ) ) ) ) {
				return true;
			}
		}
		return false;
```

In `includes/HierarchyGuard/Trash/Markers.php`, replace:

```php
	 * for many posts in one query.
```

with:

```php
	 * for many posts, in one query for the rows and one for their roots.
```

In `includes/HierarchyGuard/Trash/Markers.php`, replace:

```php
		$found = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE post_id IN ( {$placeholders} ) AND meta_key LIKE %s", ...array_merge( $ids, [ $wpdb->esc_like( self::MOVED_PREFIX ) . '%' ] ) ) );

		return array_fill_keys( array_map( 'intval', (array) $found ), true );
```

with:

```php
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_key FROM {$wpdb->postmeta} WHERE post_id IN ( {$placeholders} ) AND meta_key LIKE %s", ...array_merge( $ids, [ $wpdb->esc_like( self::MOVED_PREFIX ) . '%' ] ) ), ARRAY_A );

		$roots = [];
		foreach ( (array) $rows as $row ) {
			$roots[ (int) substr( (string) $row['meta_key'], strlen( self::MOVED_PREFIX ) ) ] = true;
		}
		_prime_post_caches( array_keys( $roots ), false, false );
		$found = [];
		foreach ( (array) $rows as $row ) {
			if ( false !== get_post_status( (int) substr( (string) $row['meta_key'], strlen( self::MOVED_PREFIX ) ) ) ) {
				$found[ (int) $row['post_id'] ] = true;
			}
		}
		return $found;
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `npm run test:php -- --filter 'MarkersTest|ResolverTest'`

Expected: `OK`.

Run: `npm run test:php`, `npm run test:php:multisite` and `npm run test:js`

Expected: `Tests: 1048, Assertions: 4624, Skipped: 6.` (single site), `OK (1048 tests, 4664 assertions)` (multisite), and Vitest `Tests 187 passed (187)`.

- [ ] **Step 5: Lint and build**

Run: `composer lint && npm run lint:js && npm run lint:style && npm run build`

Expected: every command exits 0.

- [ ] **Step 6: Commit**

```bash
git add includes/HierarchyGuard/Trash/Markers.php tests/phpunit/MarkersTest.php tests/phpunit/ResolverTest.php
git commit -m "fix: a moved marker keeps a redirect temporary only while the page that moved it exists"
```


---

### Task 24: Full verification on WordPress 7.1 and 6.6

**Findings:** none new; this proves the whole branch.

- [ ] **Step 1: Run every suite on the dev environment (WordPress 7.1)**

Run: `npm run test:php`, `npm run test:php:multisite`, `npm run test:js`

Expected: `Tests: 1048, Assertions: 4624, Skipped: 6.` (single site), `OK (1048 tests, 4664 assertions)` (multisite) and Vitest `Tests 187 passed (187)`, on WordPress 7.1.5 with PHP 8.2. The 6 skipped are the multisite-only tests.

- [ ] **Step 2: Run PHPUnit on WordPress 6.6, single site and multisite**

Start the 6.6 environment from its own folder (`npx @wordpress/env start` there; it maps this repository), run the suite in its tests container with and without `WP_MULTISITE=1`, then stop it. Never delete plugin files there; `wp plugin uninstall` only with `--skip-delete`.

Expected: `Tests: 1048, Assertions: 4618, Skipped: 6.` (single site) and `OK (1048 tests, 4658 assertions)` (multisite), on WordPress 6.6.10 with PHP 8.2. Wait for the database before the first run (for example until `wp eval "echo 1;"` succeeds in the tests container): a run started while `tests-mysql` is still starting stops at "Error establishing a database connection".

- [ ] **Step 3: Lints, build, Plugin Check and the POT**

Run: `composer lint && npm run lint:js && npm run lint:style && npm run build`. Then copy the release package (`rsync -a --exclude-from=.distignore ./ <scratch>/p6a-hg-dist/`) into the dev site's plugins folder with `docker cp`, install Plugin Check 2.2.0, and run `wp plugin check p6a-hg-dist --slug=hierarchy-guard` (without `--slug` every string reports a text domain mismatch, because the folder name differs) and `wp i18n make-pot <that folder> <scratch>/hg.pot --slug=hierarchy-guard --domain=hierarchy-guard`. Then run make-pot on the repository twice, once with `--exclude=src,tests,vendor,node_modules,docs` (the built JS, as the package ships it) and once with `--exclude=build,tests,vendor,node_modules,docs` (the sources), and compare the translator comments of every msgid between the two: they must be the same. Delete the copy, the POTs and Plugin Check afterwards; the POT is not committed in 6a.

Expected: all four commands exit 0. Plugin Check on the release package: no code ERROR or WARNING; only the readme's `no_stable_tag`, `no_license` and `missing_readme_header_tested` ERRORs and `readme_parser_warnings_no_short_description_present` WARNING, which plan 6b clears with readme.txt. make-pot on the package: no warnings, 284 strings. make-pot on the built JS and on the sources: 284 msgids each, every translator comment the same.

- [ ] **Step 4: Live checks**

On WordPress 7.1 (dev site) and 6.6, with `p6a-` test pages deleted afterwards:

- WordPress 7.1, list screens: the list's stylesheet prints in the head of every list screen of a supported type; `tree.js` loads only in the Tree view, after `list-table.js`; no console errors from the plugin.
- Bulk Edit: choosing a parent for selected pages, some with subpages, shows "Saving will change the URLs of the selected pages and of the subpages under N of them." with the next step above Update, counts again when core's × button takes a page out, and "— No Change —" hides it.
- Quick Edit: a slug change on a page with subpages speaks one message, "Changes saved." followed by the plugin's sentence, after the row fades back in.
- Trash view: a parent trashed with "Trash subpages too" reads "Restoring it brings back N subpages."; Restore from there adds "It’s a draft now, so visitors can’t see it until you publish it."
- Settings: each trash policy shows its description indented under its label, the next option well below it (measured: 7 px under the label, 17 px to the next); the built-in redirects help asks to turn them on only while the box is clear. Redirects tab: the old-URL link on a striped row measures 5.23:1.
- Yoast Duplicate Post (current release from WordPress.org): a renamed page's copy has no `_hierarchy_guard_` row, a copy that adds every meta row by hand gets none either, and the old URL still resolves to the original.
- WordPress 6.6: the Site Editor's page editing shows the Hierarchy panel, the warning on a slug change and the notice after saving; the Bulk Edit warning works; with Yoast SEO 23.0 active, a trash and a restore of a parent update the moved pages' indexables.
- Yoast SEO with a deep subtree, on 6.6 with Yoast SEO 23.0 and on 7.1 with the current release (installed for the check and removed): one root, 50 subpages, 20 under each. Trash and restore: the plugin's Yoast work inside each step is about 0.01 s, one background run rebuilds the 50 moved pages in at most 0.3 s, and afterwards every indexable's permalink matches `get_permalink()`. Use `add_filter( 'Yoast\\WP\\SEO\\should_index_indexables', '__return_true' )` on a local site, where Yoast saves no indexables otherwise.

- [ ] **Step 5: Leave the environments as found**

Delete the `p6a-` pages by their IDs, remove any third-party plugin installed for a check, rebuild `build/` on the branch, leave the dev environment running and stop the 6.6 one.

---

## Coverage

Every finding ID in the audit report, and where it is handled.

| ID | Where | Note |
|---|---|---|
| 1.1 | Task 14 | top-level root-list record reads "The URLs under /board/" |
| 1.2 | Task 15 | type labels where core has them; sentences keep "page" (owner: labels only, as built) |
| 1.3 | Task 14 | Plain permalinks get their own status |
| 1.4 | Task 14 | one name: built-in redirects |
| 1.5 | Task 5 | merged into 6.1: no "Download CSV for <plugin>" on a generic file |
| 1.6 | Task 14 | a status per cause the server refuses |
| 1.7 | Task 16 | judged: no change, U+203A mirrors in RTL; spec says so |
| 1.8 | Task 14 | "Change what happens to the subpages" |
| 1.9 | Task 14 | `hierarchy_guard_type`, `hierarchy_guard_redirects`, `bulk-hierarchy_guard_redirects` |
| 1.10 | Tasks 14, 15 | "Parent page:" everywhere (the type's own label), policy labels aligned |
| 2.1 | Task 14 | "Saving will change the URLs of this page and of its %s subpages." |
| 2.2 | Task 14 | Edit view help one sentence per paragraph; Automatic description |
| 2.3 | Task 14 | pronoun-only strings name their subject or carry a translator comment |
| 2.4 | Task 14 | one translator comment for "%s subpage" |
| 2.5 | Task 14 | `_x()` context for short labels |
| 2.6 | Task 14 | Redirects tab and Settings help rewrites |
| 2.7 | Task 14 | trash dialog and editor rewrites |
| 2.8 | Task 14 | "Hierarchy Guard no longer redirects %s."; expired change message |
| 2.9 | Task 12 | dead undo path removed |
| 2.10 | Task 14 | stale "Summary" and copy.js header |
| 2.11 | Task 14 (behaviour sentences) and 6b | README paragraphs rewritten with the README in 6b |
| 2.12 | 6b | the comment rewrite; 6a code already follows the comment rule |
| 2.13 | Task 22 | `@since 1.0.0` on every public hook |
| 2.14 | Task 14 | notice wording ("restore", no "worked on in the background") |
| 3.1 | Task 3 | Yoast indexables rebuilt, bounded per step; ElasticPress a readme note in 6b (owner) |
| 3.2 | Task 21 | editor bundle in the Site Editor |
| 3.3 | Task 2 | `npm run test:php:multisite` and CI cells |
| 3.4 | Tasks 7, 8 | watchdog only while jobs wait; rebuild only with entries |
| 3.5 | 6b | uninstall from the storage inventory |
| 3.6 | Won't fix | readme note in 6b |
| 3.7 | Task 16 | RTL arrow keys swap |
| 3.8 | Task 14 (owner: a note) | the Redirects tab says a root-list record stays until deleted; membership is not recorded once markers are cleaned up |
| 3.9 | Task 12 | dead code removed |
| 3.10 | Task 17 | array trash policy ignored quietly |
| 3.11 | Task 8 | `hierarchy_guard_version` and `Plugin::setup()` |
| 4.1 | Won't fix |  |
| 4.2 | Task 12 | suspend flag removed |
| 4.3 | 6b | documentation; 6a decides 13 public and 2 internal hooks (Task 22) |
| 4.4 | Task 22 | `Plugin::get()` |
| 5.1 | Task 4 | `Meta_Guard` |
| 5.2 | Task 9 | one-query prefix lookup |
| 5.3 | Task 23 | markers count only while their root exists; uninstall sweep in 6b |
| 5.4 | Task 7 | events expire on their own; no network sweep (judged) |
| 5.5 | Task 11 | entries read once on delete |
| 5.6 | Task 8 | stored version (autoloaded, ruling R1) |
| 5.7 | Task 18 | same-site targets stored as paths |
| 6.1 | Task 5 | real layouts for Yoast, Rank Math, Simple 301; plain file otherwise |
| 6.2 | Task 13 | restore-as-draft sentence |
| 6.3 | Task 13 | Bulk Edit warning |
| 6.4 | Task 14 | "may now show “Page not found”" |
| 6.5 | Task 10 | subtree filter in tree order |
| 6.6 | Won't fix |  |
| 6.7 | Task 5 | "skip those rows" note |
| 7.1 | Task 16 | row-title contrast 5.23:1 |
| 7.2 | Task 13 | one announcement after core's |
| 7.3 | Task 13 | Trash view: "Restoring it brings back %s subpages." |
| 7.4 | Task 13 | policy descriptions |
| 7.5 | Task 13 | help depends on the switch |
| 7.6 | Task 16 | meta box headings |
| 7.7 | Task 14 | Quick Edit warning ends with the next step |
| 7.8 | Task 16 | refused field and spinner text; (3) left as is |
| 8.1 | Task 9 | merged into 5.2 |
| 8.2 | Task 17 | list stylesheet in the head |
| 8.3 | Task 20 | tree bundle of its own |
| 8.4 | Task 17 | trash preview needs a trash capability |
| 8.5 | Task 6 | merged into 10.1 |
| 9.1 | Task 7 | one drain event per site |
| 9.2 | Task 10 | "oldest" label (judged) |
| 9.3 | Task 9 | merged into 5.2 |
| 9.4 | Task 11 | children without a sort |
| 9.5 | Tasks 7, 8 | merged into 3.4 |
| 9.6 | Task 19 | `FORCE INDEX ( meta_key )` |
| 9.7 | Accepted, no task | spec Out of scope line; Tree view and filter for large sites |
| 9.8 | Task 17 | paths of any depth, loop guard |
| 10.1 | Task 6 | ABSPATH guards |
| 10.2 | 6b | readme Source code section |
| 10.3 | 6b | release zip and CI job |
| 10.4 | Task 6 | prepared IN lists |
| 10.5 | Task 6 | request URI sanitized; three raw reads kept with reasons (judged) |
| 10.6 | Task 14 | lens-only twin of 2.4 |
| 11.1 | Task 17 | no-cache headers and VIP purge |
| 11.2 | Task 11 | no `get_posts()` without filters |
| 11.3 | Task 11 | chunked priming |
| DataViews | DataViews: out | later release |

## Plan audit findings

| Finding | Where | What changed |
|---|---|---|
| C-1 / S-1 | Task 7 | A run reads more roots once it has passed the 100 it read; pinned with 121 queued roots. |
| C-2 | Not changed (ruling R3) | No production site runs 0.1.0 and WordPress.org users start at 1.0.0; the dev site's recurring watchdog was deleted by hand. In "For the PR body". |
| C-3 | Task 18 | `Paths::relative()` reads the home path without its slash as `/`; eleven same-site forms pinned. |
| C-4 | Task 5 | Redirection's CSV carries the home path on a site in a subdirectory. |
| C-5 | Task 3 | One `try`/`catch ( Throwable )` per page; the stub fails for one page and the others are rebuilt. |
| C-6 | Tasks 7, 8 | A subsite runs setup again on its next admin load after a network reactivation; the deactivation docblock says so. |
| C-7 / U-17 | Task 22 | The hook guard finds every call, requires the docblock, counts the one reference and every other call, and asserts the 13 names. |
| C-8 | Task 4 | The docblock says Rewrite & Republish skips the exclude list and is refused by `refuse_copies()`. |
| S-2 | Task 7 | The next run is scheduled before the first step; a root that throws is caught, passed over and left to the watchdog. |
| S-3 | Task 7 | The progress notice schedules the run when a job waits and neither the run nor the watchdog is scheduled. |
| S-4 | Task 8 | An imported ledger row bumps the ledger's last-changed key on `added_post_meta`. |
| S-5 | Task 17 | `wpvip_purge_edge_cache_for_url()` first, the deprecated name only when it is the one there. |
| S-6 | Task 17, Task 1, 6b | Purges de-duplicated and capped at 50 a request; spec and comments say what VIP's edge keeps; the readme note is a 6b item. |
| S-7 | Task 7 | Action Scheduler is asked once a request. |
| U-1 | Task 13 | "It’s a draft now, so visitors can’t see it until you publish it." replaces the false sentence and the repeated title. |
| U-2 | Task 13 | Counts again on core's × button and on a ticked row. |
| U-3 | Task 13 | Each description indented under its label with space before the next option. |
| U-4 | Task 14 | Sentences from `if` chains passed as arguments; make-pot gives the same comments from `build/` as from `src/`; one comment for the shared string. |
| U-5 | Tasks 13, 14, 15 | Comments for the six pronoun strings, `_x()` for "(private)", the Settings tab comment names the Built-in redirects field. |
| U-6 | Task 14 | `_n()` with the count for "Restoring “%s” restores it/them…". |
| U-7 | Task 15 | Plural label for a trail of several; a test for a type with only its name; the plan says what it shows. |
| U-8 | Task 15 | "Hierarchy Guard’s messages say “page” and “subpage” for every post type.", only while another type is listed. |
| U-9 | Task 16 | Headings in core's Page Attributes look with `role="heading" aria-level="3"`. |
| U-10 | Task 16 | The field is described by its own screen-reader reason, not core's notice. |
| U-11 | Task 14 | "Uses 301 (permanent), or 302 (temporary) while a restore from the Trash can still undo the move." |
| U-12 | Task 13 | One sentence plus the editors' next step. |
| U-13 | Tasks 13, 14 | `quick-edit-words.js` with Vitest cases for the announcement and the warning's next step. |
| U-14 | Tasks 7, 13, 17 | The run order asserted; one marker query for three rows; the no-cache headers in a process of their own. |
| U-15 | Every task | Each Step 2 names a mutant of its key test and quotes it killed. |
| U-16 | Tasks 5, 6, 14, 18 | Data providers: CSV formats, request URIs, the type filter, foreign nonces, same-site forms. |
| U-18 | This plan | "New and changed strings" lists them verbatim. |
| Y-1 | Task 3 | Pages with subpages are rebuilt in a time-boxed run of their own; probed on 6.6 with Yoast 23.0 and 7.1 with Yoast 28.9. |

## For the PR body

- No upgrade step clears `main`'s recurring hourly `hierarchy_guard_watchdog`: no production site runs 0.1.0 (AT-KBB production doesn't have the plugin; its preprod data is disposable) and WordPress.org users start at 1.0.0. On a development site that ran `main`, delete it once: `wp cron event delete hierarchy_guard_watchdog`.
- The 13 public hooks carry `@since 1.0.0`; `hierarchy_guard_list_script` and `hierarchy_guard_tree_script` are internal.

## New and changed strings

Every string this plan adds or rewords, from make-pot on `main` and on the prototype's head (`includes/`, `src/` and the main file): 65 new msgids, 12 with a changed translator comment, 52 removed. The plan's tests pin each one in English; "Removed" lists the wordings they replace.

| # | Where | String (singular / plural) | Function | Translator comment |
|---|---|---|---|---|
| 1 | Admin/List_Table.php, Overview.php | (private) | _x, context "title of a page the user can’t read" | — |
| 2 | Admin/List_Table.php, src/common/copy.js | Only the %s oldest subpage is shown. / Only the %s oldest subpages are shown. | _n | %s: the most subpages the tree shows, such as 1,000. |
| 3 | Admin/List_Table.php | Restoring it brings back %s subpage. / Restoring it brings back %s subpages. | _n | %s: number of subpages, such as 3 or 1,000+; "it" is the trashed page of this row. |
| 4 | Admin/Notices.php, src/common/copy.js | %s old URL may now show “Page not found”. / %s old URLs may now show “Page not found”. | _n | %s: number of old URLs, such as 12 or 1,000+. |
| 5 | Admin/Notices.php | %s subpage couldn’t be restored and stayed where it is. / %s subpages couldn’t be restored and stayed where they are. | _n | %s: number of subpages. |
| 6 | Admin/Notices.php | It’s a draft now, so visitors can’t see it until you publish it. | __ | "It" is the page just restored from the Trash. |
| 7 | Admin/Notices.php | The subpages of %s more page are still being processed in the background. / The subpages of %s more pages are still being processed in the background. | _n | %s: number of pages. |
| 8 | Admin/Redirect_Edit.php, Admin/Redirects_Table.php | Automatic | _x, context "redirect code" | — |
| 9 | Admin/Redirect_Edit.php, Admin/Redirects_Table.php | Code | _x, context "redirect code" | — |
| 10 | Admin/Redirect_Edit.php | Hierarchy Guard no longer redirects %s. | __ | %s: the old URL, such as /about/team/. |
| 11 | Admin/Redirect_Edit.php | Hierarchy Guard no longer redirects its old URL. / Hierarchy Guard no longer redirects their old URLs. | _n | "its" is the deleted redirect's, "their" the deleted redirects'. |
| 12 | Admin/Redirect_Edit.php | If you enter a page on this site, the redirect follows that page when it moves, and URLs under %s go to the matching URLs under that page. | __ | %s: the old URL, such as /about/team/. |
| 13 | Admin/Redirect_Edit.php | If you enter any other URL, only %s itself redirects there, and URLs under it are no longer redirected. | __ | %s: the old URL, such as /about/team/. |
| 14 | Admin/Redirect_Edit.php | Leave this empty to keep sending them to the same page at the top level. | __ | "them" are the URLs under the old URL, which go to the same pages at the top level. |
| 15 | Admin/Redirect_Edit.php | Or enter a URL to send them somewhere else. | __ | "them" are the URLs under the old URL. |
| 16 | Admin/Redirect_Edit.php | URLs under %1$s now go to the same page at the top level, for example %2$s to %3$s. | __ | 1: the old URL, such as /about/; 2: a URL under it, such as /about/example/; 3: where that one leads, /example/. |
| 17 | Admin/Redirect_Edit.php | Uses 301 (permanent), or 302 (temporary) while a restore from the Trash can still undo the move. | __ | — |
| 18 | Admin/Redirect_Edit.php | You can enter a path on this site, such as %s, instead of a full URL. | __ | %s: an example path, /about/team/. |
| 19 | Admin/Redirects_Table.php | Active | _x, context "redirect status" | — |
| 20 | Admin/Redirects_Table.php | Not active: it points back to its own old URL | __ | "it" is the redirect. |
| 21 | Admin/Redirects_Table.php | Not active: it points to another redirected URL | __ | "it" is the redirect. |
| 22 | Admin/Redirects_Table.php | Not active: permalinks are set to Plain | __ | — |
| 23 | Admin/Redirects_Table.php | Not active: the URL has too many levels | __ | — |
| 24 | Admin/Redirects_Table.php | The URLs under %s | __ | %s: an old URL, such as /about/; its subpages are top-level pages now. |
| 25 | Admin/Redirects_Table.php | This redirect stays until you delete it, even after the pages under it are gone. | __ | — |
| 26 | Admin/Settings_Page.php | %s is active and can serve these redirects instead. | __ | %s: name of the detected redirect plugin. |
| 27 | Admin/Settings_Page.php | A redirect plugin is active and can serve these redirects instead. | __ | — |
| 28 | Admin/Settings_Page.php | After each URL change, Hierarchy Guard’s notice offers to add these redirects to %s. | __ | %s: name of the detected redirect plugin. |
| 29 | Admin/Settings_Page.php | After each URL change, Hierarchy Guard’s notice offers to add these redirects to that plugin. | __ | — |
| 30 | Admin/Settings_Page.php | Applies when a page is trashed from the editor, the REST API or WP-CLI. | __ | — |
| 31 | Admin/Settings_Page.php | Built-in redirects | __ | — |
| 32 | Admin/Settings_Page.php | Hierarchy Guard’s messages say “page” and “subpage” for every post type. | __ | — |
| 33 | Admin/Settings_Page.php | If two recorded redirects have the same old URL, only one is served, though both may show Active. | __ | — |
| 34 | Admin/Settings_Page.php | It doesn’t check archives or other plugins’ redirect rules. | __ | "It" is the Status column. |
| 35 | Admin/Settings_Page.php | Older redirects will appear when it’s finished. | __ | "it" is the list of recorded redirects. |
| 36 | Admin/Settings_Page.php | Redirects | _x, context "settings tab" | — |
| 37 | Admin/Settings_Page.php, src/common/copy.js | Restoring their parent restores them with their original status. | __ | "Their parent" is the page being trashed; "them" are its subpages. |
| 38 | Admin/Settings_Page.php | The Status column only checks whether a post or page now uses each old URL. | __ | — |
| 39 | Admin/Settings_Page.php, src/common/copy.js | Trash subpages too | __ | — |
| 40 | Admin/Settings_Page.php | Trashing from the Pages list asks each time. | __ | — |
| 41 | Admin/Settings_Page.php | Turn on built-in redirects in the <a href="%s">Settings tab</a>. | __ | %s: URL of the Built-in redirects field on the Settings tab. |
| 42 | Admin/Tree_View.php | Tree | _x, context "list view" | — |
| 43 | REST/Changes_Controller.php | Each change’s redirects are listed for %s minute. / Each change’s redirects are listed for %s minutes. | _n | %s: number of minutes. |
| 44 | Redirects/Providers.php | Rank Math imports a CSV only in its PRO version. | __ | — |
| 45 | Redirects/Providers.php | Safe Redirect Manager imports a CSV only from WP-CLI: %s | __ | %s: a WP-CLI command, which stays as it is. |
| 46 | Redirects/Providers.php | Simple 301 Redirects imports every row as a 301. | __ | — |
| 47 | src/common/copy.js | After you save, you’ll see the redirects to add. | __ | — |
| 48 | src/common/copy.js | If %s already added any of these redirects itself, skip those rows. | __ | %s: name of the redirect plugin. |
| 49 | src/common/copy.js | If your redirect plugin already added any of these redirects itself, skip those rows. | __ | — |
| 50 | src/common/copy.js | It goes to the Trash too. / They go to the Trash too. | _n | "It" or "They" are the subpages of the page being trashed. |
| 51 | src/common/copy.js | It’ll become a top-level page and get a new URL. / They’ll become top-level pages and get new URLs. | _n | "It" or "They" are the subpages of the page being trashed. |
| 52 | src/common/copy.js | It’ll move up under “%s” and get a new URL. / They’ll move up under “%s” and get new URLs. | _n | %s: title of the page the subpages move under; "It" or "They" are the subpages. |
| 53 | src/common/copy.js | Restoring “%s” restores it with its original status. / Restoring “%s” restores them with their original status. | _n | %s: page title; "it" or "them" are its subpages. |
| 54 | src/common/copy.js | Saving will change the URL of this page and of its %s subpage. / Saving will change the URLs of this page and of its %s subpages. | _n | %s: number of subpages, such as 12 or 1,000+. |
| 55 | src/common/copy.js | Saving will change the URLs of the selected pages and of the subpages under %s of them. / Saving will change the URLs of the selected pages and of the subpages under %s of them. | _n | %s: number of selected pages that have subpages. |
| 56 | src/common/copy.js | That’s more than %s, so they’ll move up one level instead of going to the Trash. | __ | %s: the most subpages trashed together with a page, such as 1,000; "they" are the page's subpages. |
| 57 | src/common/copy.js | The subpages go to the Trash too. | __ | — |
| 58 | src/common/copy.js | Trash | _x, context "verb" | — |
| 59 | src/common/copy.js | View | _x, context "verb" | — |
| 60 | src/common/copy.js | You can’t move some of the subpages to the Trash. | __ | — |
| 61 | src/common/redirects-modal.js, src/editor/panel.js | Loading… | __ | — |
| 62 | src/common/redirects-modal.js | Nothing on this site redirects these old URLs to their new pages yet. | __ | — |
| 63 | src/common/redirects-modal.js | Redirects | _x, context "modal title" | — |
| 64 | src/list-table/quick-edit-words.js | Changes saved. | __ | — |
| 65 | src/list-table/trash-dialog.js | Change what happens to the subpages | __ | — |

Strings whose translator comment changed:

| # | String | Translator comment now |
|---|---|---|
| 1 | %s subpage | %s: number of subpages, such as 3 or 1,000+. |
| 2 | If you continue, they’ll go to the Trash too. | "they" are the subpages of the page being trashed. |
| 3 | If you continue, they’ll move up one level. | "they" are the subpages of the page being trashed. |
| 4 | If you continue, they’ll stay where they are. | "they" are the subpages of the page being trashed. |
| 5 | It stays where it is, and its URL changes until you move it. | "It" or "They" are the subpages of the page being trashed. |
| 6 | The rest are still going to the Trash in the background. | "The rest" are the subpages not yet moved to the Trash. |
| 7 | The rest are still moving in the background. | "The rest" are the subpages not yet moved. |
| 8 | Their URLs change until you move them, for example <from /> becomes <to />. | "Their" and "them" are the subpages; <from /> and <to /> are replaced by URLs. |
| 9 | Their URLs change, for example <from /> becomes <to />. | "Their" are the subpages; <from /> and <to /> are replaced by URLs. |
| 10 | Their URLs change. | "Their" are the subpages of the page being trashed. |
| 11 | Their URLs will contain “__trashed” until you move them. | "Their" and "them" are the subpages of the page being trashed. |
| 12 | They’ll stay where they are. | "They" are the subpages the user can't change. |

Removed:

- %s is active and can handle these.
- %s no longer redirects through it.
- %s old URL now shows “Page not found”.
- %s subpage couldn’t be brought back and stayed where it is.
- (private page)
- 301, or 302 while restoring a page from the Trash could undo the move.
- A page on this site: the redirect follows it when it moves, and the URLs under %s go to the matching URLs under it.
- A path on this site, such as %s, works as well as a full URL.
- A redirect plugin is active and can handle these.
- Active
- Any other URL: only %s redirects, and this redirect no longer covers the URLs under it.
- Automatic
- Code
- Enter a URL to send these URLs somewhere else.
- It goes to the Trash too, and comes back with its status if you restore “%s”.
- It will become a top-level page and get a new URL.
- It will move up under “%s” and get a new URL.
- Its old URL no longer redirects through it.
- Leave empty to keep this.
- Not active: the server can’t send this redirect
- Nothing on this site redirects these old URLs yet.
- Now redirects to the same page at the top level, for example %1$s to %2$s.
- Older redirects appear here once it’s done.
- Only the first %s subpage is shown.
- Other WordPress URLs, such as archives or other plugins’ rules, aren’t checked.
- Other options
- Parent:
- Redirects
- Saving changes this page’s URL and the URL of %s subpage.
- Sorry, you are not allowed to restore this page from the trash.
- Status checks posts and pages at each old URL.
- That is more than %s, so they’ll move up one level instead of going to the Trash.
- The Pages list asks each time.
- The subpages go to the Trash too, and come back with their statuses if you restore their parent.
- The subpages of %s more page are still being worked on in the background.
- These are the redirects Hierarchy Guard recorded; its notices after each change offer to add them in %s.
- These are the redirects Hierarchy Guard recorded; its notices after each change offer to add them there.
- They are kept for %s minute.
- They come back with their statuses if you restore their parent.
- They come back with their statuses if you restore “%s”.
- This page is no longer in the trash.
- Trash
- Trash the subpages too
- Tree
- Turn them on in the <a href="%s">Settings tab</a>.
- Used when there’s no chance to ask, for example in the editor, the REST API, or WP-CLI.
- View
- When two recorded redirects share an old URL, at most one is served, and both can read Active.
- You can’t delete some of the subpages.
- You’ll get the redirects to add after saving.
- “%s” could not be restored from the trash.
- “%s” is no longer in the trash.

## Self-Review

1. **Spec coverage.** Every behaviour Task 1 adds to the spec has a task: compatibility (3, 4), CSV layouts (5), hygiene (6), cron (7, 8), 404 cost (9), tree order and cap (10), delete cost (11), dead code (12), editor UX (13), copy (14), labels (15), accessibility (16), small fixes (17), targets as paths (18), rebuild index (19), tree bundle (20), Site Editor (21), `Plugin::get()` and `@since` (22), markers (23). The spec's Out of scope line for 9.7 needs no task.
2. **Step scan.** Every code step is a Create or a Replace taken from a verified commit; every run step names its command and the output that means it passed; every Step 2 quotes the failures seen on the parent.
3. **Type consistency.** Names later tasks rely on are fixed where they are introduced and checked against the replay: `Meta_Guard::writing()` (Task 4) wraps `Ledger::record()`, `Ledger::set_target()` and `Operations::record_aliases()`, and Task 8 splits its filter into `check()`; `Processor::activate()` (Task 7) is what Task 8's `Plugin::setup()` calls; `Queue::schedule()` takes no root from Task 7 on; `Records::STATUS_*` (Task 14) are read by `Redirects_Table`; `Post_Types::label()` (Task 15) by `List_Table`; `Tree_View::SCRIPT` (Task 20) by `Assets`; `Plugin::get()` (Task 22) is for integrations only. Because the checker rebuilt every task's files from this document and each task's suites passed on those files, no later task names something an earlier one does not define.
4. **Review Focus.** The five lines above are each pinned by a named test in the owning task.
5. **Proportion.** This plan is long because its brief asks for complete, verified code in every step, as plans 4 and 5 had, and the coordinator kept that for plan 6b too; the installed writing-plans skill now prefers signatures over bodies, and the brief governs. About half of the code lines are tests.

## Hand-offs to plan 6b

- readme.txt and the README rewrite with screenshots (2.11's long paragraphs go with it), the "Source code" section (10.2), and every public hook documented (4.3): the 13 public hooks are listed in Task 22, and the two `@internal` ones stay out of the docs.
- uninstall.php from the storage inventory in `lens-04-05.md`, per site on multisite (3.5), including `hierarchy_guard_version` and `hierarchy_guard_network_setup` (Task 8), the `_hierarchy_guard_yoast_pending` flags and the `hierarchy_guard_yoast_rebuild` event (Task 3), and the markers (5.3).
- readme notes: ElasticPress keeps a moved page's old URL in its index until its own next sync (owner's answer for 3.1's sibling); on WordPress VIP the edge keeps a 301 for 30 minutes and a 302 for a minute whatever the headers say, and the purge clears the exact old URL only, so URLs under it keep a cached redirect until it expires (S-6).
- `hierarchy_guard_job_finished` passes the internal job array: document the keys that are stable, or mark the rest internal.
- Version 1.0.0, the Author header, the release zip and its CI job (10.3), and the readme note for 3.6.
- The comment rewrite (2.12).

---

## Errata

Corrections found while building this plan. Where they differ from the tasks above, the branch and the spec are the record.

- **Task 2, Step 2:** the mutant run uses `--filter test_a_job_on_another_site_keeps_its_record_on_that_site`.
- **Task 4, Step 2:** the named mutant line exists only after Task 8. On Task 4's own code, the mutant is `return self::allowed( … ) ? $check : false;` → `return $check;`.
- **Task 8:** the multisite-only skip message follows Task 2's rule (the multisite run fails on any skip). It doesn't print the old "Multisite only: run with WP_MULTISITE=1." text.
- **Task 13, Step 2:** the mutant's "Received" line quotes the string as Task 14 rewords it.
- **Task 14:** the summary assertion counts 7, not 6. The summary bullet uses the new built-in redirects wording, not "Automatic".
- **Task 5:** Safe Redirect Manager's CSV writes absolute targets with the scheme of the `home` option. The spec states this.
- **Strings table, rows 26–27:** "%s is active and can redirect old URLs to their new pages instead." and "A redirect plugin is active and can redirect old URLs to their new pages instead." They replace "…can serve these redirects instead."
- **Strings reworded after review:**
  - "This redirect stays even after the pages under it are gone, so delete it once you no longer need it."
  - "If you enter any other URL, only %s itself redirects there, and this redirect no longer covers the URLs under it."
  - "Applies whenever there’s no chance to ask, for example in the editor, the REST API or WP-CLI." and "With JavaScript on, the Pages list asks each time."
  - "At each old URL, the Status column checks only for a post or page."
  - "Subpages of %1$s (%2$s of them, the levels nearest the top first)"
  - "Saving will change the URLs of the pages it moves, and of the subpages under %s of them." Its translator comment is "%s: number of the pages it moves that have subpages."
  - Developer notice: "Hierarchy Guard keeps no object of the class %s."
- **Behaviour settled in review:**
  - The built-in server sends a same-site URL target as `https` in an SSL request and with the home scheme otherwise. Lists and CSV files always use the home scheme.
  - Under PHP's time limit, the background and Yoast runs of one request share half of it. A background run that finds the time spent queues the next run 5 seconds ahead, or clears itself when no job is left.
  - The dialog's trash policies come from `Settings::POLICIES`.
  - One walk-depth constant, `Subtree::WALK_DEPTH`.
- **Task 24 expected counts at the branch head:**
  - PHPUnit: 1138 tests on WordPress 7.1.5 and 6.6.10, with 6 multisite-only skips in single site and none in multisite.
  - Vitest: 198.
  - make-pot: 287 strings.
  - The Yoast background run over 50 moved pages takes about 0.5–1.1 s. The work inside each step is about 0.01 s.
