# Hierarchy Guard

Shows the parent and child structure of pages in wp-admin, warns before a slug, parent or trash change breaks subpage URLs, and records every old URL so it can reach the page it leads to now. Built-in redirects are off by default: with a redirect plugin active, Hierarchy Guard lists the redirects to add there; with none, turn on Redirects in Settings › Writing › Page hierarchy.

## In the admin

- **Pages list.** A Hierarchy column shows each page's parent and its number of subpages. The number, and the "View subpages" row action, narrow the list to that page's subtree; the heading then says which subtree is shown, with each ancestor one click away. Large subtrees list their first 1,000 pages.
- **Trashing from the list.** Trashing a page with subpages, from its row or in bulk, opens a dialog that says what happens to the subpages and offers the other two choices. Quick Edit warns when a changed slug or parent would move subpages to new URLs.
- **After a change.** A notice says how many old URLs a change left and what handles them: a warning when nothing redirects them. It appears once, where the change was made:
  - on the Pages list after a trash, below core's notice, which says what happened to the subpages and keeps Undo;
  - on the Pages list after a Bulk Edit;
  - in Quick Edit, for a page with subpages;
  - in the block editor;
  - on the classic editor's screen right after a save made there, with a CSV download.

  On the Pages list and in the block editor, "View redirects" opens a list of each old URL of that change and its new one, which links to the detected redirect plugin, downloads a CSV in its import layout, or copies the list as text. Without JavaScript the link downloads the CSV. A CSV row says 302 while restoring a page from the trash could still undo the move its redirect follows, as the built-in redirects answer it then, and 301 otherwise. The list stays available for 30 minutes, until the user's next change replaces it. Old URLs from earlier changes are not listed again, and with Redirects on they keep redirecting.
- **Editor.** The block editor warns under the page's Summary before a save changes subpage URLs. Its Hierarchy panel shows the parent pages and the subpages, where the page is now. The classic editor shows the same in a box, and asks before saving such a change.

## Trashing a page with subpages

By default, trashing a page moves its subpages up one level, so they don't end up under a `__trashed` URL, and records their old URLs. With Redirects on, visitors on an old URL reach the new one. While the page is in the trash those redirects are temporary, so browsers don't keep them after a restore. The same section can trash the subpages too, or leave them where they are. A subpage the user who trashed the page can't move or trash stays under it. Restoring the page, whether by Undo, Restore, or a status change over the REST API or WP-CLI, moves the subpages back, and those trashed with it come back with the status they had.

## Background jobs

One request moves at most 200 subpages and trashes or restores at most 25 through core (filters `hierarchy_guard_batch_threshold` and `hierarchy_guard_core_call_threshold`). Anything beyond that runs in background batches through WP-Cron, or Action Scheduler when it is installed, and until a job finishes some subpages are still under the trashed page. An hourly check restarts a job whose run stopped. On hosts where cron is disabled or only runs on traffic, finish queued jobs with:

```bash
wp hierarchy-guard process-jobs
```

## For developers

Subpages move with a direct database update, the way core moves them when it deletes their parent, so `save_post` does not run for them. Search indexes and SEO plugins that cache permalinks can re-sync the moved posts from the `hierarchy_guard_children_reparented` action. It fires when a page's subpages move up and again when they move back, with the moved IDs, the parent they left and their new parent.

## Development

Requires Docker, Node 22.22 or 24.15+, Composer and PHP 8.0+ on the host.

```bash
composer install
npm install
npm run env:start          # WordPress at http://localhost:8888 (admin / password)
npm run test:php           # PHPUnit inside the tests container
npm run test:php -- --filter PathsTest
composer lint              # PHPCS
npm run build              # compile src/ into build/ (npm start watches)
npm run test:js            # Vitest
npm run lint:js            # ESLint; lint:style runs Stylelint
```

`build/` is not committed; release zips include it.

The design spec lives in `docs/superpowers/specs/` and implementation plans in `docs/superpowers/plans/`.
