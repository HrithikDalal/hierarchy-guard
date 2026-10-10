# Hierarchy Guard

Records every page URL change and redirects old subpage URLs to their new location. The admin views and warnings described in the design spec arrive in later releases.

## Trashing a page with subpages

By default, trashing a page moves its subpages up one level, so they don't end up under a `__trashed` URL, and records their old URLs. Turn on Redirects in Settings › Writing › Page hierarchy and visitors on an old URL reach the new one. While the page is in the trash those redirects are temporary, so browsers don't keep them after a restore. The same section can trash the subpages too, or leave them where they are. A subpage the user who trashed the page can't move or trash stays under it. Restoring the page, whether by Undo, Restore, or a status change over the REST API or WP-CLI, moves the subpages back, and those trashed with it come back with the status they had.

## Background jobs

One request moves at most 200 subpages and trashes or restores at most 25 through core (filters `hierarchy_guard_batch_threshold` and `hierarchy_guard_core_call_threshold`). Anything beyond that runs in background batches through WP-Cron, or Action Scheduler when it is installed, and until a job finishes some subpages are still under the trashed page. An hourly check restarts a job whose run stopped. On hosts where cron is disabled or only runs on traffic, finish queued jobs with:

```bash
wp hierarchy-guard process-jobs
```

## For developers

Subpages move with a direct database update, the way core moves them when it deletes their parent, so `save_post` does not run for them. Search indexes and SEO plugins that cache permalinks can re-sync the moved posts from the `hierarchy_guard_children_reparented` action. It fires when a page's subpages move up and again when they move back, with the moved IDs, the parent they left and their new parent.

## Development

Requires Docker, Node 22+, Composer and PHP 8.0+ on the host.

```bash
composer install
npm install
npm run env:start          # WordPress at http://localhost:8888 (admin / password)
npm run test:php           # PHPUnit inside the tests container
npm run test:php -- --filter PathsTest
composer lint              # PHPCS
```

The design spec lives in `docs/superpowers/specs/` and implementation plans in `docs/superpowers/plans/`.
