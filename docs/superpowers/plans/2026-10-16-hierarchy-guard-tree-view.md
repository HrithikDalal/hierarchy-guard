# Hierarchy Guard Tree View Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. Every review seat (task review, fix re-review, final review) runs on Opus.

**Goal:** Give every supported hierarchical post type's list a "Tree" view: core's own list table with the top level paged 20 at a time by core's query, subpages opened on demand 50 at a time from the hierarchy route, tree grid keys, a flat search that shows each match's parent path, links that work without JavaScript, and Trash through the list's own dialog with the return to the same place.

**Architecture:** `Admin\Tree_View` owns the view. It adds "Tree" to the views row (`edit.php?post_type=page&hierarchy_guard_view=tree`), and on `request` limits core's list query to `post_parent` 0 (or, in a search, to the matches at any level) with the list's order as an array, so core pages 20 rows and counts them. On `the_posts` it notes each row's place and, for `hierarchy_guard_open=<id>`, puts the path down to that page and then its page of 50 subpages after their parents in core's results, so core prints those rows with its own `level-N` class; that is the view without JavaScript and the reload after a trash. Both filters act only in wp-admin on `edit.php`. On a page past the view's last one it sets the view's own count, so core sends the user to its last page. In the tree it removes the checkbox column, bulk actions and Quick Edit, gives the trash link the tree's place as `_wp_http_referer`, and keeps Undo on the same page. `Admin\List_Table` keeps printing the Hierarchy column and asks `Tree_View` what the cell shows there: the count and a toggle link in the tree, the parent path in a search. The hierarchy route gains `children_per_page` (at most 50) and, per child, what core's row shows: `states`, `locked_by`, `author`, `date` (with `missed`), a `view_link` for private pages too, and `trash_link`. The script in `src/tree/`, part of the list-table bundle, puts tree grid roles and keys on core's table, turns the toggle links into core `button-link` buttons, renders loaded subpages with the list's columns, and reuses the list's trash dialog.

**Tech Stack:** PHP 8.0+, WordPress 6.6+, the plugin's engine, trash flow, admin UI and settings page (plans 1 to 4), PHPUnit 9.6 in wp-env, PHPCS (WordPress-Extra, WordPress-VIP-Go, PHPCompatibilityWP), `@wordpress/scripts` 36.1.0 (webpack, ESLint, Stylelint, Vitest 5), and `happy-dom` 20.14.5 as a dev dependency for the tree script's DOM tests.

**Spec:** `docs/superpowers/specs/hierarchy-guard-spec.html`, as corrected at `5e6013a` and updated with this plan for the audit rulings (the path-only levels without JavaScript, the pages the tree cannot reach, the loaded row's states, lock, description and label, the status rows, the keyboard on Show more and after a collapse). Read the Decisions row "Tree view", "Hierarchy column, subtree filter and Tree view" with both Tree view mockups, "REST endpoint", Architecture (`Admin\Tree_View`, `src/tree/`), the hooks table rows for `request`, the list-table hooks and `the_posts`, Testing ("Tree view, bounded", "Tree view, behaviour", "Tree grid", "REST routes"), Build order step 9, and Out of scope. This plan is build-order step 9. DataViews, the release pass and uninstall are plan 6.

**Code in this plan is verified.** Every code block was run in a prototype on wp-env (WordPress 7.1.3, PHP 8.2) on the local branch `proto-tree-view`, with the three plan 5 audits (core, scale, UI) folded in, then replayed from `tree-view` on a scratch branch task by task, reproducing the prototype exactly; every Replace below was checked to rebuild the task's file, and the finished document was applied to `5e6013a` and compared with the replay task by task. Each task's tests were seen to fail before its code and pass after it. After every task `composer lint`, `npm run lint:js` and `npm run lint:style` exit 0 and the suites pass: PHPUnit 851, 864, 878, 890 and 894 tests after Tasks 1 to 5 (5 skipped, as before this plan; 894 also as multisite), Vitest 87 after Tasks 1 to 5, 99 after Task 6 and 127 after Task 7; `npm run build` compiles. The live check in Task 8 was run on the dev site, left to right and right to left.

## Global Constraints

- Requires WordPress 6.6 and PHP 8.0. Namespace `HierarchyGuard`; the new class is `HierarchyGuard\Admin\Tree_View`. Every PHP file declares `strict_types=1`. Only APIs WordPress 6.6 has: `quick_edit_enabled_for_post_type` (6.4), `update_post_author_caches()` (6.1), `views_edit-{type}`, `bulk_actions-edit-{type}`, `manage_{type}_posts_columns`, `page_row_actions`, `restrict_manage_posts`, `manage_posts_extra_tablenav`, `admin_body_class`, `request`, `the_posts`, `load-edit.php`, `wp_redirect`.
- Commits are authored by the repository's git config. No `Co-Authored-By` trailer, and no mention of Claude or AI, in any commit message.
- `composer lint` must exit 0, warnings included. Run `composer lint:fix` (phpcbf) first; formatting-only changes it makes are not deviations. `npm run lint:js`, `npm run lint:style`, `npm run test:js` and `npm run build` must pass; `npx wp-scripts lint-js src --fix` fixes Prettier formatting only.
- UI: core components and core admin classes only: `button-link`, `dashicons dashicons-arrow-right-alt2` / `dashicons-arrow-down-alt2`, `screen-reader-text`, `row-title`, `row-actions`, `post-state`, `submitdelete`, `toggle-row`, `alignleft actions`, `subtitle`, `colspanchange`, `hidden`. CSS is layout only: the title's indent per level and the toggle's place, in logical properties so it reads right to left too. No colours, radii, shadows or type sizes of the plugin's own. Status is text (" — Draft"), not a pill.
- Copy: "subpages" is the word. One translatable string per sentence; `_n()` on a counted noun; a translator comment directly above the one call whose string has a placeholder (never above a string without one, except a date format, which gets core's own comment: "Post date format. See https://www.php.net/manual/datetime.format.php" above "Y/m/d" and "Post time format. …" above "g:i a"); curly quotes and apostrophes; "Trash" capitalised on PHP screens; text domain `hierarchy-guard`. The view's strings are: "Tree", "Collapse all", "Back to the tree", "Show more", "Show the subpages of “%s”", "Hide the subpages of “%s”", "No subpages", "Parent page:" / "Parent pages:", "Only the first %s subpage is shown." / "Only the first %s subpages are shown.", "%1$s of %2$s subpage shown" / "%1$s of %2$s subpages shown", "Loading subpages…", "The subpages couldn’t be loaded.", "Try again", and for a loaded row "Edit", "Edit “%s”", "Trash", "Move “%s” to the Trash", "View", "View “%s”", "View subpages", "View subpages of “%s”", "View the %1$s subpage of “%2$s”", "Show more details", "(no author)", "Child of %s", "%s is currently editing", and the date words "Published", "Scheduled", "Missed schedule", "Last Modified", "Unpublished", "%1$s at %2$s", "Y/m/d", "g:i a".
- Scale: nothing in a web request grows with the size of a subtree beyond core's 20-row page of top-level pages and one 50-row page of subpages: without JavaScript the levels above the opened page show only the path down to it, so the rows are the opened page's depth plus 50. A page past the view's last one costs one more query, of one row. A page of subpages is read the way the route already reads it: `Subtree::ordered_children( [ $parent ], $type, Subtree::count_cap() )`, at most the cap's IDs of one parent, sorted. `Subtree::ordered_children()` is never asked for parent 0: the top level comes only from core's query with `post_parent` 0. The Hierarchy column stays one grouped count query per screen, opened levels included; a search's parent paths load one query per level, whatever the rows.
- Every query goes through `$wpdb->prepare()` or a core API. This plan adds no SQL of its own.
- Request arguments: `hierarchy_guard_view=tree` turns the view on, on `edit.php` of a supported type, without `hierarchy_guard_under` and with `post_status` empty or `all` (core's search form sends `all`); a non-empty `s` makes it the search. `hierarchy_guard_open=<id>` and `hierarchy_guard_children_page=N` open a page without JavaScript. Arguments that are not text, a `post_type` array included, are ignored, never warned about; the view's `request` and `the_posts` filters return before reading anything unless the request is wp-admin's `edit.php`.
- Order: core's default `orderby` of `menu_order title` becomes `[ 'menu_order' => 'ASC', 'title' => 'ASC' ]` with `fields` and `posts_per_archive_page` unset and `posts_per_page` set to the screen's Number of items, so core pages the rows instead of loading every top-level page for its indented tree; a column's own sort is left to core.
- The hierarchy route: `children_per_page` is an integer from 1 to 50, default 10 (unchanged for the editor panel, the meta box and Quick Edit); paging stops at the count cap; the route still needs `edit_post` on the page it reads. Each child adds `states` (core's `get_post_states()`, as text), `locked_by` (the display name of another user holding the edit lock, from `wp_check_post_lock()`, or null), `author` (display name, or ''), `date` (`{ label, date, missed }` in core's Date column words) and `trash_link` (the raw URL of core's trash action, signed for the current user, or null when they may not trash it); `view_link` is the permalink for a page anyone can view and for a private page of a viewable type, as core's row prints View.
- Trash from the tree: the trash link carries `_wp_http_referer` = `Tree_View::place_url( $type, $parent, $page )`: the view at its page, sort and date filter, opened at the trashed page's parent, at the page of subpages it was on. Undo's redirect gets the referring Tree view's `paged` back. A search in the tree leaves core's trash link alone, so the browser's referrer returns to the results.
- The script lives in `src/tree/` and is part of the `list-table` bundle (no new webpack entry). It reuses the list's trash dialog through the existing `#the-list .row-actions .trash a` handler. The tree has no checkboxes, bulk actions or Quick Edit.
- The redirect features (ledger, resolver, server, pairs, CSV, Settings › Hierarchy Guard) are untouched.
- Tests: no test depends on the install folder name; URLs come from `admin_url()`, `Tree_View::url()` and `Tree_View::place_url()`. Tests that check a hook is registered find the plugin's own object in `$GLOBALS['wp_filter'][ $hook ]->callbacks[ $priority ]`. DOM tests start with `// @vitest-environment happy-dom`.

## Review Focus

1. A crafted URL or query: an array or a non-number in `hierarchy_guard_view`, `s`, `post_status`, `hierarchy_guard_open` or `hierarchy_guard_children_page`, and an array `post_type`, in wp-admin or on the front end (`/?post_type[]=post`, or a theme setting one on the main query). Expected: no PHP warning anywhere; the view is off or nothing opens, a page number below 1 is the first page, and the front end is never read. Pinned in Task 2 (`test_crafted_view_arguments_turn_the_view_off_without_a_warning`, `test_the_request_filter_leaves_the_front_end_and_an_array_post_type_alone`) and Task 3 (`test_a_crafted_open_or_children_page_opens_nothing_and_warns_nothing`, `test_the_posts_filter_leaves_the_front_end_and_an_array_post_type_alone`).
2. A page title with markup or entities (`<img src=x onerror=alert(1)> Tom & “Jerry”`) in a subpage the script loads. Expected: it reads as text in the title, the actions and their labels, and nothing runs. Pinned in Task 6 (`shows a title with markup or entities as text`).
3. A toggle clicked again, or three times, while its subpages load. Expected: the row ends in the state of the last click, its subpages appear once, and no second request starts while one is pending. Pinned in Task 7 (`shows a row once whatever the clicks while it loads`).
4. The Hierarchy column hidden in Screen Options. Expected: the script still finds every row's place and toggle in the hidden cell and puts the toggle before the title. Pinned in Task 7 (`still finds the toggles when the Hierarchy column is hidden`).
5. A loaded subpage the user may not edit that has subpages. Expected: no toggle and no `aria-expanded`, since the route would refuse it, but its count still links to what sits under it. Pinned in Task 7 (`gives a loaded page the user may not edit no toggle`).

## Spec rulings and known limitations

Settled for this plan; reviewers should not re-raise them.

- Undo. Core's Undo request carries no `paged`, and `edit.php` adds `paged` from that request, so it lands on page 1: checked on the dev site, where Undo from page 2 of the normal list returned `…&paged=1&untrashed=1`. `Tree_View::keep_page_on_undo()` (Task 5), on `load-edit.php`, puts the referring Tree view's `paged` back on that one redirect, as the spec now says.
- The levels above an opened page, without JavaScript (owner ruling): each shows only the page that leads down, so the rows are the depth plus one page of 50, and the trash return inherits it. Close and reopen such a level to list all of it; its toggle closes it to its parent.
- Pages the tree cannot reach (owner ruling): the tree starts from `post_parent` 0, so a page whose parent is in the Trash, missing, or a private page the user may not read is not in it. No bounded query finds them; the list and a search in the Tree view still show them. The spec (Tree view prose and Out of scope), the README (Task 8) and this list say so; there is no UI change.
- A page past the view's last one (owner ruling): core counts nothing there and falls back to every page of the type, so `open_levels()` sets the view's own count from one one-row query and core redirects to the last page.
- A trash from a search in the tree (owner ruling) keeps core's link, so the browser's referrer returns to the search; only the tree's own rows carry the tree's place.
- Where the toggle sits without JavaScript. Core has no hook before a row's title, so the no-JavaScript toggle link, "Show more" and the cap's sentence are printed in the Hierarchy cell; the script moves the toggle before the title and turns "Show more" and the cap's sentence into rows. With the Hierarchy column hidden in Screen Options, the view without JavaScript has no toggles; the script is unaffected.
- "Show more" without JavaScript pages: `hierarchy_guard_children_page=2` shows the 51st to the 100th in place of the first 50 (one 50-row page per opened page); the opened page's toggle closes it, and opening it again shows the first page.
- A subpage the script loads has the list's columns and cells (hidden ones hidden, another plugin's column empty), core's post states, its lock notice (`.locked-info`, filled by core's heartbeat, and `wp-locked` with "… is currently editing" when the route says another user holds the lock), core's hidden "Child of …" description, and, on WordPress 7.1 (where core's own title cell has it), the title cell's `aria-label`. Its Author is the name as text, and its Comments cell is empty. A draft, pending or scheduled page gets no Preview: the spec lists Edit, View, Trash and "View subpages"; a private page gets View, as core's row does.
- The lock notice carries no avatar: core prints one, the route sends only the name.
- Post states come from `get_post_states()` in a REST request, so a state a plugin registers only inside `is_admin()` shows on core's rows but not on a row the script loads; a `display_post_states` callback that asks for the screen gets null there, not a fatal error.
- The route's date words are the plugin's own strings, worded as core's Date column words them; borrowing core's text domain would fail WordPress.org's plugin check. Core's `post_date_column_status` and `post_date_column_time` filters are not applied to them. A missed schedule is in core's `error-message` style.
- The indent is set for levels 1 to 20; a deeper row indents as level 20. On screens up to 782 pixels wide, core's own breakpoint, the step is 12 pixels and the indent stops at half the cell.
- A page past its parent's first 1,000 subpages cannot be opened through `hierarchy_guard_open`: the chain stops above it, as paging stops at the cap.
- "Collapse all" and closing a row remove the loaded rows; opening the row again loads them again.
- Core's item count and paging are the top level's; opened levels are shown in addition and are not counted.
- The "Tree" link has no count beside it, as in the mockups.
- Home and End move to the first and last row, beyond the spec's four keys; pinned in Task 7.

---

## File Structure

```
includes/HierarchyGuard/
├── Admin/Tree_View.php          NEW  the view: query var, views row, request shaping, opened levels without JavaScript, row facts, controls, trash return, Undo's page
├── Admin/List_Table.php         MOD  constructor takes the Tree_View; the cell in the tree (toggle, count, Show more) and in a search (parent path); every row of a flat screen counted in one query; per_page() public
├── Overview.php                 MOD  children_per_page; each child's author, date and trash_link
├── REST/Hierarchy_Controller.php MOD the children_per_page argument
└── Plugin.php                   MOD  wires Tree_View and hands it to List_Table
src/
├── tree/rows.js                 NEW  a loaded subpage's row with the list's columns; status rows; the tree's place for a trash
├── tree/tree.js                 NEW  tree grid roles and keys on core's rows, toggles, 50 at a time, Show more, Collapse all
├── tree/index.js                NEW  bindTree(): the view's script inside the list-table bundle; Back to the tree
├── tree/test/fixture.js · rows.test.js · tree.test.js   NEW  core's markup as the view prints it, and the tests on it
├── common/api.js                MOD  hierarchyPath(), fetchHierarchy( id, page, perPage )
├── common/copy.js               MOD  the tree's sentences
├── common/test/api.test.js · copy.test.js   MOD
├── list-table/index.js          MOD  calls bindTree()
└── list-table/list-table.scss   MOD  the indent and the toggle's place
package.json · package-lock.json MOD  happy-dom 20.14.5 (dev)
README.md                        MOD
tests/phpunit/
├── TreeViewTest.php             NEW
└── ListTableTest.php · HierarchyControllerTest.php   MOD
```

`Tree_View` is the only class that decides whether a request is the tree, what core's query asks for, which rows an opened page adds and where each row sits; `List_Table` prints the Hierarchy cell and asks `Tree_View` for the mode and the row's facts. `Overview` is the only builder of the route's children. In the script, `rows.js` builds rows and knows nothing of state; `tree.js` owns the tree's state on the DOM (levels, open rows, loads in progress); `index.js` connects it to the page.

---
### Task 1: The hierarchy route pages children 50 at a time, with the list's columns

**Files:**
- Modify: `includes/HierarchyGuard/Overview.php` (`MAX_CHILDREN_PER_PAGE`, `describe()` takes `$per_page`, `children()` adds what core's row shows, new `date()`, `locked_by()` and `trash_link()`)
- Modify: `includes/HierarchyGuard/REST/Hierarchy_Controller.php` (the `children_per_page` argument)
- Modify: `src/common/api.js` (`hierarchyPath()`, `fetchHierarchy()` takes `perPage`)
- Test: `tests/phpunit/HierarchyControllerTest.php`, `src/common/test/api.test.js`

**Interfaces:**
- Produces: `Overview::MAX_CHILDREN_PER_PAGE = 50`; `Overview::describe( WP_Post $post, int $children_page = 1, int $per_page = self::CHILDREN_PER_PAGE ): array`, `has_more` now `$children_page * $per_page < min( $children_total, $cap )`.
- Produces: each item of `children` keeps `id`, `title`, `edit_link`, `status`, `status_label`, `children_count`, `children_capped`, `view_link` (now also for a private page of a viewable type), and adds `states: string[]`, `locked_by: ?string`, `author: string`, `date: array{label:string,date:string,missed:bool}`, `trash_link: ?string`.
- Produces: `Overview::date( WP_Post $post ): array{label:string,date:string,missed:bool}`, `Overview::locked_by( WP_Post $post ): ?string` and `Overview::trash_link( WP_Post $post ): ?string` (public static). The route loads core's `wp-admin/includes/template.php` and `post.php` for `get_post_states()` and `wp_check_post_lock()`.
- Produces: `GET /hierarchy-guard/v1/posts/{id}/hierarchy?children_page=N&children_per_page=50`; `children_per_page` 1 to 50, default 10.
- Produces (JS): `hierarchyPath( id: number, page = 1, perPage = 0 ): string` and `fetchHierarchy( id, page = 1, perPage = 0 ): Promise<Object>` in `src/common/api.js`; `perPage` 0 leaves the route's own 10.

- [ ] **Step 1: Write the failing tests**

In `tests/phpunit/HierarchyControllerTest.php`, replace:

```php
		$this->assertSame( 13, $second['children_total'] );
```

with:

```php
		$this->assertSame( 13, $second['children_total'] );
	}

	public function test_the_tree_takes_children_50_at_a_time_and_paging_stops_at_the_cap(): void {
		for ( $i = 1; $i <= 58; $i++ ) {
			$this->page( sprintf( 'extra-%02d', $i ), $this->ids['about'] );
		}
		$route = '/posts/' . $this->ids['about'] . '/hierarchy';

		$first  = $this->get( $route, [ 'children_per_page' => 50 ] )->get_data();
		$second = $this->get(
			$route,
			[
				'children_page'     => 2,
				'children_per_page' => 50,
			]
		)->get_data();

		$this->assertCount( 50, $first['children'] );
		$this->assertTrue( $first['has_more'] );
		$this->assertCount( 10, $second['children'] );
		$this->assertFalse( $second['has_more'] );
		$this->assertCount( Overview::CHILDREN_PER_PAGE, $this->get( $route )->get_data()['children'], 'Without it, children still come 10 to a page.' );
		$this->assertSame( 400, $this->get( $route, [ 'children_per_page' => 51 ] )->get_status(), 'At most 50.' );

		add_filter( 'hierarchy_guard_count_cap', static fn(): int => 55 );
		$capped = $this->get(
			$route,
			[
				'children_page'     => 2,
				'children_per_page' => 50,
			]
		)->get_data();
		$this->assertCount( 5, $capped['children'], 'The 51st to the 55th, then the cap.' );
		$this->assertFalse( $capped['has_more'] );
		$this->assertTrue( $capped['children_capped'] );
		$this->assertSame(
			[],
			$this->get(
				$route,
				[
					'children_page'     => 3,
					'children_per_page' => 50,
				]
			)->get_data()['children']
		);
	}

	public function test_each_child_carries_what_the_list_s_columns_show(): void {
		$author = self::factory()->user->create(
			[
				'role'         => 'editor',
				'display_name' => 'Priya',
			]
		);
		wp_update_post(
			[
				'ID'          => $this->ids['history'],
				'post_author' => $author,
				'post_date'   => '2026-09-02 15:04:00',
			]
		);
		$draft = $this->page( 'press', $this->ids['about'], 'draft' );

		$children = array_column( $this->get( '/posts/' . $this->ids['about'] . '/hierarchy', [ 'children_per_page' => 50 ] )->get_data()['children'], null, 'id' );
		$history  = $children[ $this->ids['history'] ];

		$this->assertSame( 'Priya', $history['author'] );
		$this->assertSame(
			[
				'label'  => 'Published',
				'date'   => '2026/09/02 at 3:04 pm',
				'missed' => false,
			],
			$history['date'],
			'As core’s Date column words it.'
		);
		$this->assertSame( 'Last Modified', $children[ $draft ]['date']['label'] );
		parse_str( (string) wp_parse_url( $history['trash_link'], PHP_URL_QUERY ), $args );
		$this->assertSame( 'trash', $args['action'] );
		$this->assertSame( (string) $this->ids['history'], $args['post'] );
		$this->assertSame( 1, wp_verify_nonce( $args['_wpnonce'], 'trash-post_' . $this->ids['history'] ), 'A nonce the server made for this user.' );
		$this->assertStringNotContainsString( '&amp;', $history['trash_link'], 'Sent raw, like every other link.' );
	}

	public function test_a_scheduled_child_is_dated_as_scheduled(): void {
		$future = self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_status' => 'future',
				'post_parent' => $this->ids['about'],
				'post_date'   => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
			]
		);

		$children = array_column( $this->get( '/posts/' . $this->ids['about'] . '/hierarchy' )->get_data()['children'], null, 'id' );

		$this->assertSame( 'Scheduled', $children[ $future ]['date']['label'] );
	}

	public function test_a_child_past_its_schedule_is_marked_missed(): void {
		$late = self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_status' => 'future',
				'post_parent' => $this->ids['about'],
				'post_date'   => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
			]
		);
		// A schedule that cron never ran: still future, dated in the past.
		global $wpdb;
		$wpdb->update( $wpdb->posts, [ 'post_date' => '2020-01-01 00:00:00' ], [ 'ID' => $late ] );
		clean_post_cache( $late );

		$children = array_column( $this->get( '/posts/' . $this->ids['about'] . '/hierarchy' )->get_data()['children'], null, 'id' );

		$this->assertSame( 'Missed schedule', $children[ $late ]['date']['label'] );
		$this->assertTrue( $children[ $late ]['date']['missed'], 'Core prints it in its error style.' );
		$this->assertFalse( $children[ $this->ids['team'] ]['date']['missed'] );
	}

	public function test_a_private_child_has_the_view_link_core_prints(): void {
		wp_update_post(
			[
				'ID'          => $this->ids['history'],
				'post_status' => 'private',
			]
		);

		$children = array_column( $this->get( '/posts/' . $this->ids['about'] . '/hierarchy' )->get_data()['children'], null, 'id' );

		$this->assertSame( get_permalink( $this->ids['history'] ), $children[ $this->ids['history'] ]['view_link'] );
		$this->assertContains( 'Private', $children[ $this->ids['history'] ]['states'] );
	}

	public function test_each_child_carries_core_s_post_states(): void {
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $this->ids['history'] );
		wp_update_post(
			[
				'ID'            => $this->ids['team'],
				'post_password' => 'secret',
			]
		);

		$children = array_column( $this->get( '/posts/' . $this->ids['about'] . '/hierarchy' )->get_data()['children'], null, 'id' );

		$this->assertSame( [ 'Front Page' ], $children[ $this->ids['history'] ]['states'] );
		$this->assertSame( [ 'Password protected' ], $children[ $this->ids['team'] ]['states'] );
		delete_option( 'show_on_front' );
		delete_option( 'page_on_front' );
	}

	public function test_a_child_another_user_is_editing_says_who(): void {
		$other = self::factory()->user->create(
			[
				'role'         => 'editor',
				'display_name' => 'Priya',
			]
		);
		update_post_meta( $this->ids['team'], '_edit_lock', time() . ':' . $other );

		$children = array_column( $this->get( '/posts/' . $this->ids['about'] . '/hierarchy' )->get_data()['children'], null, 'id' );

		$this->assertSame( 'Priya', $children[ $this->ids['team'] ]['locked_by'] );
		$this->assertNull( $children[ $this->ids['history'] ]['locked_by'] );
	}

	public function test_a_child_the_user_may_not_trash_has_no_trash_link(): void {
		$kept = $this->ids['team'];
		add_filter(
			'map_meta_cap',
			static function ( array $caps, string $cap, int $user_id, array $args ) use ( $kept ): array {
				return 'delete_post' === $cap && isset( $args[0] ) && $kept === (int) $args[0] ? [ 'do_not_allow' ] : $caps;
			},
			10,
			4
		);

		$children = array_column( $this->get( '/posts/' . $this->ids['about'] . '/hierarchy' )->get_data()['children'], null, 'id' );

		$this->assertNull( $children[ $kept ]['trash_link'] );
		$this->assertNotNull( $children[ $this->ids['history'] ]['trash_link'] );
	}

	public function test_the_tree_s_children_need_edit_rights_on_the_page_they_open(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$this->assertSame( 403, $this->get( '/posts/' . $this->ids['about'] . '/hierarchy', [ 'children_per_page' => 50 ] )->get_status() );
```

In `src/common/test/api.test.js`, replace:

```js
import { csvUrl, sourceRoute } from '../api';
import config from '../config';

describe( 'routes', () => {
	beforeEach( () => {
		config.restNonce = 'abc';
```

with:

```js
import { csvUrl, hierarchyPath, sourceRoute } from '../api';
import config from '../config';

describe( 'routes', () => {
	beforeEach( () => {
		config.restNonce = 'abc';
	} );

	it( 'asks for a page of children, 50 at a time for the tree', () => {
		expect( hierarchyPath( 42 ) ).toBe(
			'/hierarchy-guard/v1/posts/42/hierarchy?children_page=1'
		);
		expect( hierarchyPath( 42, 2, 50 ) ).toBe(
			'/hierarchy-guard/v1/posts/42/hierarchy?children_page=2&children_per_page=50'
		);
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npm run test:php -- --filter HierarchyControllerTest` and `npm run test:js`
Expected: FAIL. PHPUnit reports `Failed asserting that actual size 10 matches expected size 50.` for `test_the_tree_takes_children_50_at_a_time_and_paging_stops_at_the_cap`, `Undefined array key "author"`, `"date"`, `"states"`, `"locked_by"` and `"trash_link"` for the new field tests, and a null `view_link` for the private child; Vitest reports `TypeError: hierarchyPath is not a function`.

- [ ] **Step 3: Page the children by `children_per_page` and give each the list's columns**

In `includes/HierarchyGuard/Overview.php`, replace (1 of 4):

```php
	public const CHILDREN_PER_PAGE = 10;
```

with:

```php
	public const CHILDREN_PER_PAGE     = 10;
	public const MAX_CHILDREN_PER_PAGE = 50;
```

In `includes/HierarchyGuard/Overview.php`, replace (2 of 4):

```php
	 * @return array<string,mixed>
	 */
	public function describe( WP_Post $post, int $children_page = 1 ): array {
		$id   = (int) $post->ID;
		$type = (string) $post->post_type;
		$cap  = Subtree::count_cap();

		$children_total = Subtree::count_live_children( $id, $type, $cap + 1 );
		$descendants    = $children_total > 0 ? Subtree::count_live_descendants( $id, $type, $cap ) : 0;
		$children       = $this->children( $id, $type, max( 1, $children_page ) );
```

with:

```php
	 * @param int     $per_page      Children per page, at most MAX_CHILDREN_PER_PAGE.
	 * @return array<string,mixed>
	 */
	public function describe( WP_Post $post, int $children_page = 1, int $per_page = self::CHILDREN_PER_PAGE ): array {
		$id            = (int) $post->ID;
		$type          = (string) $post->post_type;
		$cap           = Subtree::count_cap();
		$children_page = max( 1, $children_page );
		$per_page      = max( 1, min( self::MAX_CHILDREN_PER_PAGE, $per_page ) );

		$children_total = Subtree::count_live_children( $id, $type, $cap + 1 );
		$descendants    = $children_total > 0 ? Subtree::count_live_descendants( $id, $type, $cap ) : 0;
		$children       = $this->children( $id, $type, $children_page, $per_page );
```

In `includes/HierarchyGuard/Overview.php`, replace (3 of 4):

```php
			'has_more'        => $children_page * self::CHILDREN_PER_PAGE < min( $children_total, $cap ),
```

with:

```php
			'has_more'        => $children_page * $per_page < min( $children_total, $cap ),
```

In `includes/HierarchyGuard/Overview.php`, replace (4 of 4):

```php
	 * One page of live children the current user may read, each with its own child count.
	 *
	 * @param int    $parent_id Parent post ID.
	 * @param string $type      Post type name.
	 * @param int    $page      Page number, from 1.
	 * @return array<int,array<string,mixed>>
	 */
	private function children( int $parent_id, string $type, int $page ): array {
		$cap    = Subtree::count_cap();
		$offset = ( $page - 1 ) * self::CHILDREN_PER_PAGE;
		if ( $offset >= $cap ) {
			return [];
		}

		// The first $cap children in menu order, read without sorting every child of a large parent.
		$rows = array_slice( Subtree::ordered_children( [ $parent_id ], $type, $cap ), $offset, self::CHILDREN_PER_PAGE );
		$ids  = array_map( static fn( array $row ): int => (int) $row['ID'], $rows );
		_prime_post_caches( $ids, false, false );
		$counts = Subtree::child_counts( $ids, $type, $cap + 1 );

		$out = [];
		foreach ( $ids as $child_id ) {
			if ( ! current_user_can( 'read_post', $child_id ) ) {
				continue;
			}
			$status = (string) get_post_status( $child_id );
			$object = get_post_status_object( $status );
			$out[]  = self::ref( $child_id ) + [
				'status'          => $status,
				'status_label'    => null !== $object ? (string) $object->label : $status,
				'children_count'  => min( $counts[ $child_id ] ?? 0, $cap ),
				'children_capped' => ( $counts[ $child_id ] ?? 0 ) > $cap,
				'view_link'       => is_post_publicly_viewable( $child_id ) ? (string) get_permalink( $child_id ) : null,
			];
		}
		return $out;
	}
```

with:

```php
	 * One page of live children the current user may read, each with its own
	 * child count and what the list's rows show: the post states and edit lock
	 * by the title, the author, the date as core's Date column words it, the
	 * View link core prints, and the trash link when the user may trash it.
	 *
	 * @param int    $parent_id Parent post ID.
	 * @param string $type      Post type name.
	 * @param int    $page      Page number, from 1.
	 * @param int    $per_page  Children per page.
	 * @return array<int,array<string,mixed>>
	 */
	private function children( int $parent_id, string $type, int $page, int $per_page ): array {
		$cap    = Subtree::count_cap();
		$offset = ( $page - 1 ) * $per_page;
		if ( $offset >= $cap ) {
			return [];
		}

		// The first $cap children in menu order, read without sorting every child of a large parent.
		$rows = array_slice( Subtree::ordered_children( [ $parent_id ], $type, $cap ), $offset, min( $per_page, $cap - $offset ) );
		$ids  = array_map( static fn( array $row ): int => (int) $row['ID'], $rows );
		_prime_post_caches( $ids, false, false );
		$counts = Subtree::child_counts( $ids, $type, $cap + 1 );
		$posts  = array_values( array_filter( array_map( 'get_post', $ids ) ) );
		update_post_author_caches( $posts );
		// Core's helpers for the states and the edit lock live with its list screens.
		require_once ABSPATH . 'wp-admin/includes/template.php';
		require_once ABSPATH . 'wp-admin/includes/post.php';

		$out = [];
		foreach ( $posts as $child ) {
			$child_id = (int) $child->ID;
			if ( ! current_user_can( 'read_post', $child_id ) ) {
				continue;
			}
			$status = (string) $child->post_status;
			$object = get_post_status_object( $status );
			$out[]  = self::ref( $child_id ) + [
				'status'          => $status,
				'status_label'    => null !== $object ? (string) $object->label : $status,
				'children_count'  => min( $counts[ $child_id ] ?? 0, $cap ),
				'children_capped' => ( $counts[ $child_id ] ?? 0 ) > $cap,
				'author'          => (string) get_the_author_meta( 'display_name', (int) $child->post_author ),
				'states'          => array_values( array_map( 'wp_strip_all_tags', (array) get_post_states( $child ) ) ),
				'locked_by'       => self::locked_by( $child ),
				'date'            => self::date( $child ),
				// As core's row: View for a page anyone can view, and for a private page of a viewable type, which this user may read.
				'view_link'       => is_post_publicly_viewable( $child ) || ( 'private' === $status && is_post_type_viewable( $type ) ) ? (string) get_permalink( $child ) : null,
				'trash_link'      => self::trash_link( $child ),
			];
		}
		return $out;
	}

	/**
	 * A post's date as core's Date column words it: the status it is dated by,
	 * then the date and time.
	 *
	 * @param WP_Post $post Post.
	 * @return array{label:string,date:string,missed:bool}
	 */
	public static function date( WP_Post $post ): array {
		if ( '0000-00-00 00:00:00' === $post->post_date ) {
			$date = __( 'Unpublished', 'hierarchy-guard' );
			$late = false;
		} else {
			$date = sprintf(
				/* translators: 1: post date, 2: post time. */
				__( '%1$s at %2$s', 'hierarchy-guard' ),
				/* translators: Post date format. See https://www.php.net/manual/datetime.format.php */
				get_the_time( __( 'Y/m/d', 'hierarchy-guard' ), $post ),
				/* translators: Post time format. See https://www.php.net/manual/datetime.format.php */
				get_the_time( __( 'g:i a', 'hierarchy-guard' ), $post )
			);
			$late = time() > (int) get_post_timestamp( $post );
		}

		$missed = 'future' === $post->post_status && $late;
		if ( 'publish' === $post->post_status ) {
			$label = __( 'Published', 'hierarchy-guard' );
		} elseif ( 'future' === $post->post_status ) {
			$label = $missed ? __( 'Missed schedule', 'hierarchy-guard' ) : __( 'Scheduled', 'hierarchy-guard' );
		} else {
			$label = __( 'Last Modified', 'hierarchy-guard' );
		}

		return [
			'label'  => $label,
			'date'   => $date,
			'missed' => $missed,
		];
	}

	/**
	 * Who holds the edit lock on a post the current user may edit, as core's
	 * row says "… is currently editing", or null.
	 *
	 * @param WP_Post $post Post.
	 * @return string|null Display name.
	 */
	public static function locked_by( WP_Post $post ): ?string {
		if ( 'trash' === $post->post_status || ! current_user_can( 'edit_post', (int) $post->ID ) ) {
			return null;
		}
		$holder = wp_check_post_lock( $post );
		$user   = false !== $holder ? get_userdata( (int) $holder ) : false;
		return false !== $user ? (string) $user->display_name : null;
	}

	/**
	 * The link that moves a post to the Trash, signed for the current user, or
	 * null when they may not trash it or the site deletes posts outright.
	 *
	 * @param WP_Post $post Post.
	 * @return string|null
	 */
	public static function trash_link( WP_Post $post ): ?string {
		if ( ! EMPTY_TRASH_DAYS || 'trash' === $post->post_status ) {
			return null;
		}
		$link = get_delete_post_link( $post );
		// Core escapes the link for HTML; the route sends it raw, as it sends every other link.
		return is_string( $link ) && '' !== $link ? html_entity_decode( $link, ENT_QUOTES, 'UTF-8' ) : null;
	}
```

In `includes/HierarchyGuard/REST/Hierarchy_Controller.php`, replace (1 of 3):

```php
 * Backs the editor panel, the Quick Edit warning and the trash dialog. Every
 * route is read-only and needs edit rights on the post it reads.
```

with:

```php
 * Backs the editor panel, the Quick Edit warning, the trash dialog and the
 * Tree view's subpages, 50 at a time. Every route is read-only and needs
 * edit rights on the post it reads.
```

In `includes/HierarchyGuard/REST/Hierarchy_Controller.php`, replace (2 of 3):

```php
					'id'            => $id,
					'children_page' => [
						'type'    => 'integer',
						'minimum' => 1,
						'default' => 1,
```

with:

```php
					'id'                => $id,
					'children_page'     => [
						'type'    => 'integer',
						'minimum' => 1,
						'default' => 1,
					],
					'children_per_page' => [
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => Overview::MAX_CHILDREN_PER_PAGE,
						'default' => Overview::CHILDREN_PER_PAGE,
```

In `includes/HierarchyGuard/REST/Hierarchy_Controller.php`, replace (3 of 3):

```php
		return new WP_REST_Response( $this->overview->describe( $post, (int) $request['children_page'] ) );
```

with:

```php
		return new WP_REST_Response( $this->overview->describe( $post, (int) $request['children_page'], (int) $request['children_per_page'] ) );
```

In `src/common/api.js`, replace:

```js
 * The hierarchy of one post.
 *
 * @param {number} id   Post ID.
 * @param {number} page Page of children.
 * @return {Promise<Object>} Hierarchy.
 */
export function fetchHierarchy( id, page = 1 ) {
	return apiFetch( {
		path: addQueryArgs( `${ base() }/posts/${ id }/hierarchy`, {
			children_page: page,
		} ),
	} );
```

with:

```js
 * The route of one post's hierarchy.
 *
 * @param {number} id      Post ID.
 * @param {number} page    Page of children.
 * @param {number} perPage Children per page; the route's own 10 when omitted.
 * @return {string} Path.
 */
export function hierarchyPath( id, page = 1, perPage = 0 ) {
	return addQueryArgs( `${ base() }/posts/${ id }/hierarchy`, {
		children_page: page,
		...( perPage ? { children_per_page: perPage } : {} ),
	} );
}

/**
 * The hierarchy of one post.
 *
 * @param {number} id      Post ID.
 * @param {number} page    Page of children.
 * @param {number} perPage Children per page; the route's own 10 when omitted.
 * @return {Promise<Object>} Hierarchy.
 */
export function fetchHierarchy( id, page = 1, perPage = 0 ) {
	return apiFetch( { path: hierarchyPath( id, page, perPage ) } );
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `npm run test:php -- --filter HierarchyControllerTest`, then `npm run test:js`
Expected: PASS (35 PHPUnit tests; 87 Vitest tests).

Run: `npm run test:php`, `composer lint`, `npm run lint:js`
Expected: 851 tests pass (5 skipped); both lints exit 0.

- [ ] **Step 5: Commit**

```bash
git add includes/HierarchyGuard/Overview.php includes/HierarchyGuard/REST/Hierarchy_Controller.php src/common/api.js src/common/test/api.test.js tests/phpunit/HierarchyControllerTest.php
git commit -m "feat: the hierarchy route pages children 50 at a time, with the list's columns"
```

### Task 2: Tree in the views row, the top level through core's query 20 to a page

**Files:**
- Create: `includes/HierarchyGuard/Admin/Tree_View.php`
- Modify: `includes/HierarchyGuard/Admin/List_Table.php` (`per_page()` becomes public)
- Modify: `includes/HierarchyGuard/Plugin.php` (registers `Tree_View`)
- Test: `tests/phpunit/TreeViewTest.php`

**Interfaces:**
- Produces: `Tree_View::QUERY_VAR = 'hierarchy_guard_view'`, `Tree_View::VIEW = 'tree'`, `Tree_View::OPEN_VAR = 'hierarchy_guard_open'`, `Tree_View::CHILDREN_PAGE_VAR = 'hierarchy_guard_children_page'`, `Tree_View::PER_PAGE = 50`.
- Produces: `Tree_View::url( string $post_type ): string`, the view's URL; `Tree_View::mode( string $post_type ): string`, `'tree'`, `'search'` or `''`.
- Produces: `Tree_View::register()` hooks `request` → `filter_request( $vars )` and `load-edit.php` → `load()`; `filter_request()` returns at once unless the request is wp-admin's `edit.php` (`Tree_View::on_list()`, private) and reads `post_type` only when it is a string; `load()` hooks `views_edit-{type}` → `views()` on every supported list, and in the tree `manage_{type}_posts_columns` (priority 20) → `columns()`, `bulk_actions-edit-{type}` → `bulk_actions()`, `quick_edit_enabled_for_post_type` → `__return_false`, and in the tree but not a search `admin_body_class` → `body_class()` (adds `hierarchy-guard-tree-view`).
- Produces: `List_Table::per_page( string $post_type ): int` is public static.
- Consumes: `List_Table::under()`, `Post_Types::is_supported()`.

- [ ] **Step 1: Write the failing tests**

Create `tests/phpunit/TreeViewTest.php`:
```php
<?php
/**
 * The Tree view of a supported list screen.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Admin\List_Table;
use HierarchyGuard\Admin\Tree_View;
use HierarchyGuard\Post_Types;
use HierarchyGuard\Settings;
use WP_Query;
use WP_UnitTestCase;

/**
 * @covers \HierarchyGuard\Admin\Tree_View
 */
final class TreeViewTest extends WP_UnitTestCase {

	use Trash_Fixtures;

	/**
	 * Page IDs.
	 *
	 * @var array<string,int>
	 */
	private array $ids;

	private Tree_View $tree;

	public function set_up(): void {
		parent::set_up();
		require_once ABSPATH . 'wp-admin/includes/template.php';
		require_once ABSPATH . 'wp-admin/includes/post.php';
		delete_option( Settings::OPTION );
		Post_Types::reset();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->ids  = $this->make_tree();
		$this->tree = new Tree_View();
		set_current_screen( 'edit-page' );
		$GLOBALS['pagenow'] = 'edit.php';
		$_GET               = [
			'post_type'          => 'page',
			Tree_View::QUERY_VAR => Tree_View::VIEW,
		];
	}

	public function tear_down(): void {
		$_GET = [];
		unset( $GLOBALS['pagenow'], $_SERVER['HTTP_REFERER'] );
		$GLOBALS['wp_query']     = new WP_Query();
		$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
		set_current_screen( 'front' );
		parent::tear_down();
	}

	/**
	 * The query vars core's list screen asks for on a hierarchical type.
	 *
	 * @return array<string,mixed>
	 */
	private static function core_vars(): array {
		return [
			'post_type'              => 'page',
			'orderby'                => 'menu_order title',
			'order'                  => 'asc',
			'posts_per_page'         => -1,
			'posts_per_archive_page' => -1,
			'fields'                 => 'id=>parent',
		];
	}

	/**
	 * Runs core's own list query for the request in $_GET.
	 *
	 * @return WP_Query
	 */
	private function list_query(): WP_Query {
		// As on edit.php, where core matches no rewrite rule, whatever permalinks an earlier test left.
		$server                 = $_SERVER;
		$_SERVER['REQUEST_URI'] = '/wp-admin/edit.php';
		$_SERVER['PHP_SELF']    = '/wp-admin/edit.php';
		wp_edit_posts_query( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The list screen's own request, built in the test.
		$_SERVER = $server;
		return $GLOBALS['wp_query'];
	}

	public function test_the_view_is_the_tree_only_when_asked_on_a_supported_list(): void {
		$this->assertSame( 'tree', $this->tree->mode( 'page' ) );

		$_GET['s'] = 'team';
		$this->assertSame( 'search', $this->tree->mode( 'page' ) );
		$_GET['s'] = '   ';
		$this->assertSame( 'tree', $this->tree->mode( 'page' ), 'A search of spaces searches nothing.' );
		unset( $_GET['s'] );

		$_GET['post_status'] = 'all';
		$this->assertSame( 'tree', $this->tree->mode( 'page' ), 'Core’s search form sends post_status=all.' );
		$_GET['post_status'] = 'trash';
		$this->assertSame( '', $this->tree->mode( 'page' ), 'The Trash is a view of its own.' );
		unset( $_GET['post_status'] );

		$_GET[ List_Table::QUERY_VAR ] = (string) $this->ids['about'];
		$this->assertSame( '', $this->tree->mode( 'page' ), 'The subtree filter is a view of its own.' );
		unset( $_GET[ List_Table::QUERY_VAR ] );

		$this->assertSame( '', $this->tree->mode( 'post' ), 'Posts are not hierarchical.' );
		$GLOBALS['pagenow'] = 'upload.php';
		$this->assertSame( '', $this->tree->mode( 'page' ) );
		$GLOBALS['pagenow']           = 'edit.php';
		$_GET[ Tree_View::QUERY_VAR ] = 'list';
		$this->assertSame( '', $this->tree->mode( 'page' ) );
	}

	public function test_crafted_view_arguments_turn_the_view_off_without_a_warning(): void {
		$_GET[ Tree_View::QUERY_VAR ] = [ Tree_View::VIEW ];
		$this->assertSame( '', $this->tree->mode( 'page' ) );

		$_GET[ Tree_View::QUERY_VAR ] = Tree_View::VIEW;
		$_GET['s']                    = [ 'team' ];
		$_GET['post_status']          = [ 'trash' ];
		$this->assertSame( 'tree', $this->tree->mode( 'page' ), 'A search or status that is not text is no search and no status.' );
	}

	public function test_the_request_filter_leaves_the_front_end_and_an_array_post_type_alone(): void {
		$vars = [ 'post_type' => [ 'post', 'page' ] ];
		$this->assertSame( $vars, $this->tree->filter_request( $vars ), 'An array post type in wp-admin is not one list.' );

		set_current_screen( 'front' );
		$GLOBALS['pagenow'] = 'index.php';
		$this->assertSame( $vars, $this->tree->filter_request( $vars ), 'A visitor’s ?post_type[]=post is never read.' );
		$this->assertSame( self::core_vars(), $this->tree->filter_request( self::core_vars() ), 'The view acts only on edit.php.' );
	}

	public function test_the_top_level_is_core_s_query_with_post_parent_0_twenty_to_a_page(): void {
		$vars = $this->tree->filter_request( self::core_vars() );

		$this->assertSame( 0, $vars['post_parent'] );
		$this->assertSame(
			[
				'menu_order' => 'ASC',
				'title'      => 'ASC',
			],
			$vars['orderby'],
			'An array, so core pages its rows instead of loading every top-level page for its indented tree.'
		);
		$this->assertSame( 20, $vars['posts_per_page'] );
		$this->assertArrayNotHasKey( 'fields', $vars );
		$this->assertArrayNotHasKey( 'posts_per_archive_page', $vars );
	}

	public function test_a_column_s_sort_is_left_to_core(): void {
		$vars = $this->tree->filter_request(
			[
				'post_type'      => 'page',
				'orderby'        => 'date',
				'order'          => 'desc',
				'posts_per_page' => 20,
			]
		);

		$this->assertSame( 'date', $vars['orderby'] );
		$this->assertSame( 0, $vars['post_parent'] );
	}

	public function test_a_search_lists_the_matches_at_any_level_in_the_list_s_order(): void {
		$_GET['s'] = 'team';

		$vars = $this->tree->filter_request( self::core_vars() + [ 's' => 'team' ] );

		$this->assertArrayNotHasKey( 'post_parent', $vars );
		$this->assertSame(
			[
				'menu_order' => 'ASC',
				'title'      => 'ASC',
			],
			$vars['orderby']
		);
		$this->assertSame( 20, $vars['posts_per_page'] );
	}

	public function test_other_lists_are_left_alone(): void {
		$_GET = [ 'post_type' => 'page' ];

		$this->assertSame( self::core_vars(), $this->tree->filter_request( self::core_vars() ) );
	}

	public function test_core_s_list_query_pages_the_top_level_and_never_reads_every_child_of_0(): void {
		update_user_option( get_current_user_id(), 'edit_page_per_page', 2 );
		$this->page( 'zeta' );
		$this->page( 'alpha' );
		$sql = [];
		$log = static function ( string $statement ) use ( &$sql ): string {
			$sql[] = $statement;
			return $statement;
		};
		add_filter( 'query', $log );
		$query = $this->list_query();
		remove_filter( 'query', $log );

		$this->assertSame( [ 'alpha', 'company' ], wp_list_pluck( $query->posts, 'post_name' ) );
		$this->assertSame( 3, (int) $query->found_posts, 'Core counts the top level for its paging.' );
		$this->assertNotSame( 'menu_order title', $query->query['orderby'], 'Not core’s tree mode.' );
		foreach ( $sql as $statement ) {
			$this->assertDoesNotMatchRegularExpression( '/post_parent IN \( 0 \)/', $statement, 'Subtree::ordered_children() is never asked for the top level.' );
		}
	}

	public function test_a_search_in_the_tree_is_one_flat_page_of_matches(): void {
		$_GET['s'] = 'a';

		$query = $this->list_query();

		$this->assertSame( [ 'about', 'company', 'leadership', 'team' ], wp_list_pluck( $query->posts, 'post_name' ), 'Matches at any level, in menu order then title.' );
	}

	public function test_tree_joins_the_views_row(): void {
		$views = $this->tree->views( [ 'all' => '<a href="edit.php?post_type=page">All</a>' ] );

		$this->assertSame( [ 'all', 'hierarchy_guard_tree' ], array_keys( $views ) );
		$this->assertSame( '<a href="' . esc_url( Tree_View::url( 'page' ) ) . '" class="current" aria-current="page">Tree</a>', $views['hierarchy_guard_tree'] );

		$_GET = [ 'post_type' => 'page' ];
		$this->assertSame( '<a href="' . esc_url( Tree_View::url( 'page' ) ) . '">Tree</a>', $this->tree->views( [] )['hierarchy_guard_tree'] );
		$this->assertStringContainsString( 'post_type=page&#038;hierarchy_guard_view=tree', esc_url( Tree_View::url( 'page' ) ) );
	}

	public function test_loading_the_tree_hooks_its_pieces_into_core_s_list(): void {
		$this->tree->load();

		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound,WordPress.NamingConventions.ValidHookName.UseUnderscores -- Core's own list filters.
		$this->assertSame(
			[ 'title' => 'Title' ],
			apply_filters(
				'manage_page_posts_columns',
				[
					'cb'    => '<input type="checkbox" />',
					'title' => 'Title',
				]
			),
			'No checkboxes: the subpages the tree loads are not core’s rows.'
		);
		$this->assertSame( [], apply_filters( 'bulk_actions-edit-page', [ 'trash' => 'Move to Trash' ] ) );
		$this->assertFalse( apply_filters( 'quick_edit_enabled_for_post_type', true, 'page' ), 'No Quick Edit in the tree.' );
		$this->assertStringContainsString( 'hierarchy-guard-tree-view', apply_filters( 'admin_body_class', '' ) );
		$this->assertArrayHasKey( 'hierarchy_guard_tree', apply_filters( 'views_edit-page', [] ) );
		// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound,WordPress.NamingConventions.ValidHookName.UseUnderscores
	}

	public function test_the_list_keeps_its_checkboxes_and_quick_edit_outside_the_tree(): void {
		$_GET = [ 'post_type' => 'page' ];

		$this->tree->load();

		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound,WordPress.NamingConventions.ValidHookName.UseUnderscores -- Core's own list filters.
		$this->assertTrue( apply_filters( 'quick_edit_enabled_for_post_type', true, 'page' ) );
		$this->assertArrayHasKey( 'cb', apply_filters( 'manage_page_posts_columns', [ 'cb' => '' ] ) );
		$this->assertArrayHasKey( 'hierarchy_guard_tree', apply_filters( 'views_edit-page', [] ), 'Tree is in the views row of every list.' );
		// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound,WordPress.NamingConventions.ValidHookName.UseUnderscores
	}

	public function test_the_plugin_hooks_the_view_into_core(): void {
		global $wp_filter;
		$found = [];
		foreach ( [ 'request', 'load-edit.php' ] as $hook ) {
			foreach ( $wp_filter[ $hook ]->callbacks[10] ?? [] as $callback ) {
				if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof Tree_View ) {
					$found[] = $hook;
				}
			}
		}

		$this->assertSame( [ 'request', 'load-edit.php' ], $found );
	}
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npm run test:php -- --filter TreeViewTest`
Expected: FAIL. Every test errors with `Error: Class "HierarchyGuard\Admin\Tree_View" not found`.

- [ ] **Step 3: Add the view and shape core's query**

Create `includes/HierarchyGuard/Admin/Tree_View.php`:
```php
<?php
/**
 * The Tree view of a supported list screen.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Admin;

use HierarchyGuard\Post_Types;

/**
 * Adds "Tree" to the views row of every supported list screen. The Tree view
 * is core's own list table: the top level comes from core's query with
 * post_parent 0 in menu order then title, 20 to a page, and a search lists
 * the matches at any level, flat. The list-table script loads subpages 50 at
 * a time from the hierarchy route. Without JavaScript a toggle reloads the
 * view opened at one page: the path down to it and its page of 50 subpages
 * are put after their parents in core's results, so core prints them.
 * Nothing here reads every top-level post: Subtree::ordered_children() is
 * never asked for parent 0.
 */
final class Tree_View {

	public const QUERY_VAR         = 'hierarchy_guard_view';
	public const VIEW              = 'tree';
	public const OPEN_VAR          = 'hierarchy_guard_open';
	public const CHILDREN_PAGE_VAR = 'hierarchy_guard_children_page';
	public const PER_PAGE          = 50;

	/**
	 * Hooks into core.
	 */
	public function register(): void {
		add_filter( 'request', [ $this, 'filter_request' ] );
		add_action( 'load-edit.php', [ $this, 'load' ] );
	}

	/**
	 * The Tree view's URL for a post type.
	 *
	 * @param string $post_type Post type name.
	 * @return string
	 */
	public static function url( string $post_type ): string {
		return add_query_arg(
			[
				'post_type'     => $post_type,
				self::QUERY_VAR => self::VIEW,
			],
			admin_url( 'edit.php' )
		);
	}

	/**
	 * Which Tree view the request asks for on a post type's list: 'tree', a
	 * search in it ('search'), or none (''). The subtree filter and the Trash
	 * are views of their own.
	 *
	 * @param string $post_type Post type of the list.
	 * @return string
	 */
	public function mode( string $post_type ): string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only list views, like core's own.
		if ( ! is_admin() || 'edit.php' !== ( $GLOBALS['pagenow'] ?? '' ) || ! Post_Types::is_supported( $post_type ) ) {
			return '';
		}
		$view   = isset( $_GET[ self::QUERY_VAR ] ) ? sanitize_key( wp_unslash( $_GET[ self::QUERY_VAR ] ) ) : '';
		$status = isset( $_GET['post_status'] ) ? sanitize_key( wp_unslash( $_GET['post_status'] ) ) : '';
		$search = isset( $_GET['s'] ) && is_string( $_GET['s'] ) ? trim( sanitize_text_field( wp_unslash( $_GET['s'] ) ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( self::VIEW !== $view || List_Table::under() > 0 || ! in_array( $status, [ '', 'all' ], true ) ) {
			return '';
		}
		return '' !== $search ? 'search' : 'tree';
	}

	/**
	 * Hooks the Tree view's pieces into the list screen being loaded.
	 */
	public function load(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$type   = null !== $screen ? (string) $screen->post_type : '';
		if ( ! Post_Types::is_supported( $type ) ) {
			return;
		}
		add_filter( "views_edit-{$type}", [ $this, 'views' ] );
		$mode = $this->mode( $type );
		if ( '' === $mode ) {
			return;
		}
		add_filter( "manage_{$type}_posts_columns", [ $this, 'columns' ], 20 );
		add_filter( "bulk_actions-edit-{$type}", [ $this, 'bulk_actions' ] );
		add_filter( 'quick_edit_enabled_for_post_type', '__return_false' );
		if ( 'tree' === $mode ) {
			add_filter( 'admin_body_class', [ $this, 'body_class' ] );
		}
	}

	/**
	 * Adds "Tree" to the views row.
	 *
	 * @param mixed $views Views, by key.
	 * @return mixed
	 */
	public function views( $views ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$type   = null !== $screen ? (string) $screen->post_type : '';
		if ( ! is_array( $views ) || ! Post_Types::is_supported( $type ) ) {
			return $views;
		}
		$views['hierarchy_guard_tree'] = sprintf(
			'<a href="%1$s"%2$s>%3$s</a>',
			esc_url( self::url( $type ) ),
			'' !== $this->mode( $type ) ? ' class="current" aria-current="page"' : '',
			esc_html__( 'Tree', 'hierarchy-guard' )
		);
		return $views;
	}

	/**
	 * Limits core's list query to the top level, or for a search to the
	 * matches at any level, in menu order then title as an array, so core
	 * pages it 20 at a time instead of loading every page for its indented
	 * tree mode. A column's own sort is left to core.
	 *
	 * @param mixed $vars Query vars.
	 * @return mixed
	 */
	public function filter_request( $vars ) {
		if ( ! is_array( $vars ) || ! self::on_list() ) {
			return $vars;
		}
		$type = isset( $vars['post_type'] ) && is_string( $vars['post_type'] ) ? $vars['post_type'] : '';
		$mode = $this->mode( $type );
		if ( '' === $mode ) {
			return $vars;
		}
		if ( 'tree' === $mode ) {
			$vars['post_parent'] = 0;
		}
		if ( 'menu_order title' === ( $vars['orderby'] ?? '' ) ) {
			$vars['orderby'] = [
				'menu_order' => 'ASC',
				'title'      => 'ASC',
			];
			unset( $vars['fields'], $vars['posts_per_archive_page'] );
			$vars['posts_per_page'] = List_Table::per_page( $type );
		}
		return $vars;
	}

	/**
	 * The tree has no checkbox column: its loaded subpages are not core's rows.
	 *
	 * @param mixed $columns Columns.
	 * @return mixed
	 */
	public function columns( $columns ) {
		if ( is_array( $columns ) ) {
			unset( $columns['cb'] );
		}
		return $columns;
	}

	/**
	 * The tree has no bulk actions.
	 *
	 * @return array<string,string>
	 */
	public function bulk_actions(): array {
		return [];
	}

	/**
	 * Marks the screen as the tree, for the stylesheet's indents.
	 *
	 * @param mixed $classes Body classes.
	 * @return mixed
	 */
	public function body_class( $classes ) {
		return is_string( $classes ) ? $classes . ' hierarchy-guard-tree-view ' : $classes;
	}

	/**
	 * Whether the request is a list screen in wp-admin, the only place the
	 * view acts; front-end queries are left alone before anything is read.
	 *
	 * @return bool
	 */
	private static function on_list(): bool {
		return is_admin() && 'edit.php' === ( $GLOBALS['pagenow'] ?? '' );
	}
}
```

In `includes/HierarchyGuard/Admin/List_Table.php`, replace:

```php
	private static function per_page( string $post_type ): int {
```

with:

```php
	public static function per_page( string $post_type ): int {
```

In `includes/HierarchyGuard/Plugin.php`, replace:

```php
		( new Admin\Notices( $log, $queue, $pairs, $providers ) )->register();
```

with:

```php
		( new Admin\Notices( $log, $queue, $pairs, $providers ) )->register();
		( new Admin\Tree_View() )->register();
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `npm run test:php -- --filter TreeViewTest`
Expected: PASS (13 tests).

Run: `npm run test:php`, `npx wp-env run tests-cli --env-cwd=wp-content/plugins/hierarchy-guard bash -c 'WP_MULTISITE=1 vendor/bin/phpunit'`, `composer lint`
Expected: 864 tests pass (5 skipped), and as multisite; lint exits 0. `list_query()` sets `REQUEST_URI` and `PHP_SELF` to `/wp-admin/edit.php` as the list screen has them: without that, core's list query in the full suite matches a rewrite rule an earlier test left and finds nothing.

- [ ] **Step 5: Commit**

```bash
git add includes/HierarchyGuard/Admin/Tree_View.php includes/HierarchyGuard/Admin/List_Table.php includes/HierarchyGuard/Plugin.php tests/phpunit/TreeViewTest.php
git commit -m "feat: Tree in the views row, the top level through core's query 20 to a page"
```

### Task 3: Without JavaScript an opened page's levels follow it in core's results

**Files:**
- Modify: `includes/HierarchyGuard/Admin/Tree_View.php` (the row facts, `the_posts` → `open_levels()`, `row()`, `place_url()`, `opened()`, `children_page()`, `chain()`, `open()`, `fact()`)
- Test: `tests/phpunit/TreeViewTest.php`

**Interfaces:**
- Consumes: `Subtree::ordered_children()`, `Subtree::count_live_children()`, `Subtree::count_cap()`, `List_Table::per_page()`.
- Produces: `Tree_View::open_levels( mixed $posts, mixed $query ): mixed`, hooked on `the_posts` (10, 2 arguments), acting only for wp-admin's `edit.php` main query with a string `post_type`. On an empty page past page 1 of the tree or a search it sets `found_posts` from one one-row copy of the query. For the tree (not a search) it records every top-level row's facts and, for `hierarchy_guard_open`, inserts the path down to the opened page (one row a level, the page that leads down) and then the opened page's page of up to 50 `WP_Post` rows.
- Produces: `Tree_View::row( int $post_id ): ?array{level:int,parent:int,position:int|null,setsize:int|null,page:int,open:bool,shown:int,more:bool,capped:bool}`: `level` from 0, `page` is the page of its parent's subpages the row is on, `shown` the page of its own subpages shown when `open` (on a level above the opened page, the page that holds the next one down), `more` when a next page exists within the cap, `capped` when the shown page reached the cap and more exist; `position` and `setsize` are null past the cap.
- Produces: `Tree_View::place_url( string $post_type, int $open = 0, int $children_page = 1 ): string`: the view's URL keeping only `orderby`, `order` (`asc` or `desc`), `m` and `paged` from the request, plus `hierarchy_guard_open` and, above 1, `hierarchy_guard_children_page`.
- Produces: `Tree_View::opened(): int` and `Tree_View::children_page(): int` (from 1), ignoring values that are not text.

- [ ] **Step 1: Write the failing tests**

In `tests/phpunit/TreeViewTest.php`, replace (1 of 3):

```php
		return $GLOBALS['wp_query'];
```

with:

```php
		return $GLOBALS['wp_query'];
	}

	/**
	 * Hands this test's view a page of top-level rows, as core's main query holds them.
	 *
	 * @param int[] $ids   Top-level post IDs on the page.
	 * @param int   $total Core's item count.
	 * @param int   $paged Page number.
	 * @return array<int,mixed> The rows core prints.
	 */
	private function open_levels( array $ids, int $total, int $paged = 1 ): array {
		$query = new WP_Query();
		$query->set( 'post_type', 'page' );
		$query->set( 'paged', $paged );
		$query->found_posts      = $total;
		$GLOBALS['wp_the_query'] = $query;
		return (array) $this->tree->open_levels( array_map( 'get_post', $ids ), $query );
	}

	/**
	 * Gives a page many subpages, named so they sort in creation order.
	 *
	 * @param int    $parent_id Parent page.
	 * @param int    $count     How many.
	 * @param string $prefix    Slug and title prefix.
	 * @return int[] Their IDs, in menu order then title.
	 */
	private function subpages( int $parent_id, int $count, string $prefix = 'sub' ): array {
		$ids = [];
		for ( $i = 1; $i <= $count; $i++ ) {
			$ids[] = $this->page( sprintf( '%s-%03d', $prefix, $i ), $parent_id );
		}
		return $ids;
```

In `tests/phpunit/TreeViewTest.php`, replace (2 of 3):

```php
		foreach ( [ 'request', 'load-edit.php' ] as $hook ) {
```

with:

```php
		foreach ( [ 'request', 'the_posts', 'load-edit.php' ] as $hook ) {
```

In `tests/phpunit/TreeViewTest.php`, replace (3 of 3):

```php
		$this->assertSame( [ 'request', 'load-edit.php' ], $found );
```

with:

```php
		$this->assertSame( [ 'request', 'the_posts', 'load-edit.php' ], $found );
	}

	public function test_without_an_opened_page_the_rows_are_core_s_with_their_places(): void {
		$rows = $this->open_levels( [ $this->ids['company'] ], 7, 2 );

		$this->assertSame( [ $this->ids['company'] ], wp_list_pluck( $rows, 'ID' ) );
		$this->assertSame(
			[
				'level'    => 0,
				'parent'   => 0,
				'position' => 21,
				'setsize'  => 7,
				'page'     => 1,
				'open'     => false,
				'shown'    => 0,
				'more'     => false,
				'capped'   => false,
			],
			$this->tree->row( $this->ids['company'] ),
			'Page 2 of 20 a page starts at 21; the size is core’s item count.'
		);
	}

	public function test_an_opened_page_s_levels_follow_their_parents_in_core_s_results(): void {
		$_GET[ Tree_View::OPEN_VAR ] = (string) $this->ids['team'];
		$lonely                      = $this->page( 'lonely' );

		$rows = $this->open_levels( [ $this->ids['company'], $lonely ], 2 );

		$this->assertSame(
			[ 'company', 'about', 'team', 'leadership', 'lonely' ],
			wp_list_pluck( $rows, 'post_name' ),
			'The levels above the opened page show only the path down to it: History, beside Team, is left out.'
		);
		$this->assertTrue( $this->tree->row( $this->ids['about'] )['open'] );
		$this->assertSame( 3, $this->tree->row( $this->ids['leadership'] )['level'], 'Leadership sits three levels down.' );
		$this->assertSame( $this->ids['team'], $this->tree->row( $this->ids['leadership'] )['parent'] );
		$this->assertSame( 2, $this->tree->row( $this->ids['team'] )['position'], 'History, then Team.' );
		$this->assertSame( 2, $this->tree->row( $this->ids['team'] )['setsize'] );
		$this->assertSame( 2, $this->tree->row( $lonely )['position'], 'The top level keeps its places around the opened levels.' );
	}

	public function test_core_prints_the_opened_levels_with_its_own_level_class(): void {
		$_GET[ Tree_View::OPEN_VAR ] = (string) $this->ids['about'];

		$query = $this->list_query();

		$this->assertSame( [ 'company', 'about', 'history', 'team' ], wp_list_pluck( $query->posts, 'post_name' ) );
		$this->assertSame( 4, $query->post_count );
		$this->assertSame( 1, (int) $query->found_posts, 'Core still counts and pages only the top level.' );
		$this->assertContains( 'level-2', get_post_class( 'level-' . count( get_post_ancestors( $this->ids['team'] ) ), $this->ids['team'] ) );
	}

	public function test_each_level_above_shows_only_the_page_that_leads_down_however_far_in_it_is(): void {
		$many = $this->subpages( $this->ids['about'], 58 );
		// About's subpages sort as history, sub-001 … sub-058, team: team is the 60th, on page 2.
		$_GET[ Tree_View::OPEN_VAR ] = (string) $this->ids['team'];

		$rows = $this->open_levels( [ $this->ids['company'] ], 1 );

		$this->assertSame( [ 'company', 'about', 'team', 'leadership' ], wp_list_pluck( $rows, 'post_name' ), 'The depth plus one page of the opened page’s subpages, never 50 rows a level.' );
		$this->assertSame( 2, $this->tree->row( $this->ids['team'] )['page'], 'A trash from Team comes back to About’s page 2.' );
		$this->assertSame( 60, $this->tree->row( $this->ids['team'] )['position'] );
		$this->assertSame( 60, $this->tree->row( $this->ids['team'] )['setsize'] );
		$this->assertTrue( $this->tree->row( $this->ids['about'] )['open'] );
		$this->assertFalse( $this->tree->row( $this->ids['about'] )['more'], 'A level on the path has no Show more.' );
		$this->assertNotContains( $many[0], wp_list_pluck( $rows, 'ID' ) );
	}

	public function test_a_deep_opened_page_costs_its_depth_plus_one_page(): void {
		$parent = $this->ids['leadership'];
		$chain  = [];
		for ( $depth = 1; $depth <= 8; $depth++ ) {
			$this->subpages( $parent, 3, "side{$depth}" );
			$parent  = $this->page( "deep-{$depth}", $parent );
			$chain[] = $parent;
		}
		$this->subpages( $parent, 60, 'leaf' );
		$_GET[ Tree_View::OPEN_VAR ] = (string) $parent;

		$rows = $this->open_levels( [ $this->ids['company'] ], 1 );

		// Company, About, Team, Leadership, the eight deep pages, then 50 of the 60 leaves.
		$this->assertCount( 4 + 8 + 50, $rows );
		$this->assertSame( array_slice( $chain, 0, 7 ), array_slice( wp_list_pluck( $rows, 'ID' ), 4, 7 ) );
		$this->assertSame( 12, $this->tree->row( (int) $rows[ count( $rows ) - 1 ]->ID )['level'] );
	}

	public function test_the_opened_page_shows_the_children_page_it_asks_for_and_says_when_more_follow(): void {
		$this->subpages( $this->ids['team'], 120 );
		$_GET[ Tree_View::OPEN_VAR ] = (string) $this->ids['team'];

		$first = $this->open_levels( [ $this->ids['company'] ], 1 );
		$this->assertCount( 3 + 50, $first, 'Company, About, Team, and 50 of Team’s 121.' );
		$this->assertTrue( $this->tree->row( $this->ids['team'] )['more'] );
		$this->assertSame( 1, $this->tree->row( $this->ids['team'] )['shown'] );

		$_GET[ Tree_View::CHILDREN_PAGE_VAR ] = '3';
		$third                                = $this->open_levels( [ $this->ids['company'] ], 1 );
		$this->assertCount( 3 + 21, $third, 'Leadership and 120 more: page 3 holds the last 21.' );
		$this->assertFalse( $this->tree->row( $this->ids['team'] )['more'] );

		$_GET[ Tree_View::CHILDREN_PAGE_VAR ] = '99';
		$this->assertCount( 3 + 21, $this->open_levels( [ $this->ids['company'] ], 1 ), 'A page past the end shows the last one.' );
	}

	public function test_past_the_cap_the_opened_page_says_only_the_first_are_shown(): void {
		add_filter( 'hierarchy_guard_count_cap', static fn(): int => 60 );
		$this->subpages( $this->ids['team'], 70 );
		$_GET[ Tree_View::OPEN_VAR ]          = (string) $this->ids['team'];
		$_GET[ Tree_View::CHILDREN_PAGE_VAR ] = '2';

		$rows = $this->open_levels( [ $this->ids['company'] ], 1 );

		$this->assertCount( 3 + 10, $rows, 'Paging stops at the cap: page 2 holds the 51st to the 60th.' );
		$row = $this->tree->row( $this->ids['team'] );
		$this->assertFalse( $row['more'] );
		$this->assertTrue( $row['capped'] );
		$this->assertNull( $this->tree->row( (int) end( $rows )->ID )['setsize'], 'No set size past the cap.' );
	}

	public function test_a_level_the_user_may_not_edit_stays_closed(): void {
		$closed = $this->ids['about'];
		add_filter(
			'map_meta_cap',
			static function ( array $caps, string $cap, int $user_id, array $args ) use ( $closed ): array {
				return 'edit_post' === $cap && isset( $args[0] ) && $closed === (int) $args[0] ? [ 'do_not_allow' ] : $caps;
			},
			10,
			4
		);
		$_GET[ Tree_View::OPEN_VAR ] = (string) $this->ids['team'];

		$rows = $this->open_levels( [ $this->ids['company'] ], 1 );

		$this->assertSame( [ 'company', 'about' ], wp_list_pluck( $rows, 'post_name' ), 'Company opens; About, which the route would refuse, does not.' );
	}

	public function test_a_page_of_another_type_or_in_the_trash_opens_nothing(): void {
		$_GET[ Tree_View::OPEN_VAR ] = (string) self::factory()->post->create();
		$this->assertCount( 1, $this->open_levels( [ $this->ids['company'] ], 1 ) );

		$this->trash_raw( $this->ids['history'] );
		$_GET[ Tree_View::OPEN_VAR ] = (string) $this->ids['history'];
		$this->assertCount( 1, $this->open_levels( [ $this->ids['company'] ], 1 ) );
	}

	public function test_a_crafted_open_or_children_page_opens_nothing_and_warns_nothing(): void {
		foreach ( [ 'abc', '-5', '0', '', [ (string) $this->ids['about'] ] ] as $value ) {
			$_GET[ Tree_View::OPEN_VAR ]          = $value;
			$_GET[ Tree_View::CHILDREN_PAGE_VAR ] = [ '2' ];

			$this->assertSame( [ 'company' ], wp_list_pluck( $this->open_levels( [ $this->ids['company'] ], 1 ), 'post_name' ) );
		}

		$_GET[ Tree_View::OPEN_VAR ]          = (string) $this->ids['company'];
		$_GET[ Tree_View::CHILDREN_PAGE_VAR ] = '-3';
		$this->assertSame( [ 'company', 'about' ], wp_list_pluck( $this->open_levels( [ $this->ids['company'] ], 1 ), 'post_name' ), 'A page number below 1 is the first page.' );
	}

	public function test_the_posts_filter_leaves_the_front_end_and_an_array_post_type_alone(): void {
		$posts = [ get_post( $this->ids['company'] ) ];
		$query = new WP_Query();
		$query->set( 'post_type', [ 'post', 'page' ] );
		$GLOBALS['wp_the_query'] = $query;
		$this->assertSame( $posts, $this->tree->open_levels( $posts, $query ) );

		set_current_screen( 'front' );
		$GLOBALS['pagenow'] = 'index.php';
		$this->assertSame( $posts, $this->tree->open_levels( $posts, $query ), 'A theme’s array post type on the front end is never read.' );
		$query->set( 'post_type', 'page' );
		$this->assertSame( $posts, $this->tree->open_levels( $posts, $query ) );
		$this->assertNull( $this->tree->row( $this->ids['company'] ) );
	}

	public function test_a_page_past_the_last_gets_the_view_s_own_count_so_core_sends_it_to_the_last_page(): void {
		update_user_option( get_current_user_id(), 'edit_page_per_page', 1 );
		$this->page( 'alpha' );
		$last = $this->page( 'zeta' );
		wp_trash_post( $last );
		$_GET['paged'] = '3';

		$query = $this->list_query();

		$this->assertSame( [], $query->posts, 'Zeta was the only row on page 3.' );
		$this->assertSame( 2, (int) $query->found_posts, 'Alpha and Company: core’s list then redirects to page 2 instead of counting every page.' );
	}

	public function test_opening_a_level_costs_the_same_queries_whatever_its_size(): void {
		$small = $this->page( 'small' );
		$big   = $this->page( 'big' );
		$this->subpages( $small, 3, 's' );
		$this->subpages( $big, 150, 'b' );
		$cost = function ( int $root ): int {
			$_GET[ Tree_View::OPEN_VAR ] = (string) $root;
			wp_cache_flush();
			$before = get_num_queries();
			$this->open_levels( [ $root ], 1 );
			return get_num_queries() - $before;
		};

		$cost( $small ); // The first run also reads the plugin's settings.

		$this->assertSame( $cost( $small ), $cost( $big ), 'One page of 50 per opened level, read the same way.' );
	}

	public function test_the_place_keeps_only_the_view_s_own_arguments(): void {
		$_GET += [
			'paged'                      => '2',
			'm'                          => '202610',
			'orderby'                    => 'title',
			'order'                      => 'sideways',
			'trashed'                    => '1',
			'ids'                        => '7',
			Tree_View::CHILDREN_PAGE_VAR => '4',
		];

		$this->assertSame( admin_url( 'edit.php?post_type=page&hierarchy_guard_view=tree&orderby=title&m=202610&paged=2' ), Tree_View::place_url( 'page' ) );
		$this->assertSame( admin_url( 'edit.php?post_type=page&hierarchy_guard_view=tree&orderby=title&m=202610&paged=2&hierarchy_guard_open=5&hierarchy_guard_children_page=2' ), Tree_View::place_url( 'page', 5, 2 ) );
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npm run test:php -- --filter TreeViewTest`
Expected: FAIL. The new tests error with `Error: Call to undefined method HierarchyGuard\Admin\Tree_View::open_levels()` (or `::place_url()`), and `test_the_plugin_hooks_the_view_into_core` fails without `the_posts`.

- [ ] **Step 3: Open the requested page's levels in core's results**

In `includes/HierarchyGuard/Admin/Tree_View.php`, replace (1 of 4):

```php
use HierarchyGuard\Post_Types;
```

with:

```php
use HierarchyGuard\Post_Types;
use HierarchyGuard\Subtree;
use WP_Post;
use WP_Query;
```

In `includes/HierarchyGuard/Admin/Tree_View.php`, replace (2 of 4):

```php
	 * Hooks into core.
	 */
	public function register(): void {
		add_filter( 'request', [ $this, 'filter_request' ] );
```

with:

```php
	 * What the screen knows about each tree row, by post ID: its level, its
	 * parent, its place among its siblings, and for an opened row the page of
	 * subpages shown and whether more follow.
	 *
	 * @var array<int,array{level:int,parent:int,position:int|null,setsize:int|null,page:int,open:bool,shown:int,more:bool,capped:bool}>
	 */
	private array $rows = [];

	/**
	 * Hooks into core.
	 */
	public function register(): void {
		add_filter( 'request', [ $this, 'filter_request' ] );
		add_filter( 'the_posts', [ $this, 'open_levels' ], 10, 2 );
```

In `includes/HierarchyGuard/Admin/Tree_View.php`, replace (3 of 4):

```php
		return $vars;
	}

	/**
```

with:

```php
		return $vars;
	}

	/**
	 * Notes each top-level row's place and, with hierarchy_guard_open, puts
	 * the opened page's rows after their parent in core's results, so core
	 * prints them as its own rows with its level-N class. Each level above
	 * the opened page shows only the page that leads down to it; the opened
	 * page shows its page of 50 subpages, hierarchy_guard_children_page. A
	 * page past the view's last one gets the view's own count, so core sends
	 * the user to its last page.
	 *
	 * @param mixed $posts Posts.
	 * @param mixed $query Query.
	 * @return mixed
	 */
	public function open_levels( $posts, $query ) {
		if ( ! is_array( $posts ) || ! $query instanceof WP_Query || ! self::on_list() || ! $query->is_main_query() ) {
			return $posts;
		}
		$type = $query->get( 'post_type' );
		$mode = is_string( $type ) ? $this->mode( $type ) : '';
		if ( '' === $mode ) {
			return $posts;
		}
		if ( [] === $posts && (int) $query->get( 'paged' ) > 1 ) {
			// Past the last page core finds nothing and counts every page of the type instead; one row of the view's own query gives its count.
			$count              = new WP_Query(
				array_merge(
					$query->query_vars,
					[
						'paged'                  => 1,
						'posts_per_page'         => 1,
						'fields'                 => 'ids',
						'no_found_rows'          => false,
						'update_post_meta_cache' => false,
						'update_post_term_cache' => false,
					]
				)
			);
			$query->found_posts = (int) $count->found_posts;
			return $posts;
		}
		if ( 'tree' !== $mode ) {
			return $posts;
		}

		$start      = ( max( 1, (int) $query->get( 'paged' ) ) - 1 ) * List_Table::per_page( $type );
		$total      = (int) $query->found_posts;
		$chain      = $this->chain( self::opened(), $type );
		$this->rows = [];
		$out        = [];
		foreach ( array_values( $posts ) as $index => $post ) {
			$out[] = $post;
			if ( ! $post instanceof WP_Post ) {
				continue;
			}
			$this->rows[ (int) $post->ID ] = self::fact( 0, 0, $start + $index + 1, $total, 1 );
			if ( [] !== $chain && (int) $post->ID === $chain[0] ) {
				$this->open( $chain, 0, $type, $out );
			}
		}
		return $out;
	}

	/**
	 * What the screen knows about a tree row, or null when it is not one.
	 *
	 * @param int $post_id Post ID.
	 * @return array{level:int,parent:int,position:int|null,setsize:int|null,page:int,open:bool,shown:int,more:bool,capped:bool}|null
	 */
	public function row( int $post_id ): ?array {
		return $this->rows[ $post_id ] ?? null;
	}

	/**
	 * The Tree view at its current page and sort, opened at a page, and at a
	 * page of that page's subpages. Only the view's own arguments are kept.
	 *
	 * @param string $post_type     Post type name.
	 * @param int    $open          Page to open, or 0 for none.
	 * @param int    $children_page Page of its subpages, from 1.
	 * @return string
	 */
	public static function place_url( string $post_type, int $open = 0, int $children_page = 1 ): string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only list arguments, carried over as core carries them.
		$orderby = isset( $_GET['orderby'] ) && is_string( $_GET['orderby'] ) ? sanitize_text_field( wp_unslash( $_GET['orderby'] ) ) : '';
		$order   = isset( $_GET['order'] ) && is_string( $_GET['order'] ) ? strtolower( sanitize_key( wp_unslash( $_GET['order'] ) ) ) : '';
		$month   = isset( $_GET['m'] ) ? absint( wp_unslash( $_GET['m'] ) ) : 0;
		$paged   = isset( $_GET['paged'] ) ? absint( wp_unslash( $_GET['paged'] ) ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$args = [
			'orderby'               => '' !== $orderby ? $orderby : null,
			'order'                 => in_array( $order, [ 'asc', 'desc' ], true ) ? $order : null,
			'm'                     => $month > 0 ? $month : null,
			'paged'                 => $paged > 1 ? $paged : null,
			self::OPEN_VAR          => $open > 0 ? $open : null,
			self::CHILDREN_PAGE_VAR => $open > 0 && $children_page > 1 ? $children_page : null,
		];
		return add_query_arg( array_filter( $args, static fn( $value ): bool => null !== $value ), self::url( $post_type ) );
	}

	/**
```

In `includes/HierarchyGuard/Admin/Tree_View.php`, replace (4 of 4):

```php
		return is_string( $classes ) ? $classes . ' hierarchy-guard-tree-view ' : $classes;
	}

	/**
```

with:

```php
		return is_string( $classes ) ? $classes . ' hierarchy-guard-tree-view ' : $classes;
	}

	/**
	 * The page hierarchy_guard_open names, or 0.
	 *
	 * @return int
	 */
	public static function opened(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A read-only list argument.
		return isset( $_GET[ self::OPEN_VAR ] ) && is_string( $_GET[ self::OPEN_VAR ] ) ? absint( wp_unslash( $_GET[ self::OPEN_VAR ] ) ) : 0;
	}

	/**
	 * The page of the opened page's subpages, from 1.
	 *
	 * @return int
	 */
	public static function children_page(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A read-only list argument.
		return isset( $_GET[ self::CHILDREN_PAGE_VAR ] ) && is_string( $_GET[ self::CHILDREN_PAGE_VAR ] ) ? max( 1, absint( wp_unslash( $_GET[ self::CHILDREN_PAGE_VAR ] ) ) ) : 1;
	}

	/**
	 * The pages to open, top level first, ending at the requested page. A
	 * level opens only for a user who may edit it, as the route requires.
	 *
	 * @param int    $open      Requested page.
	 * @param string $post_type List's post type.
	 * @return int[]
	 */
	private function chain( int $open, string $post_type ): array {
		$post = $open > 0 ? get_post( $open ) : null;
		if ( ! $post instanceof WP_Post || $post_type !== $post->post_type || 'trash' === $post->post_status ) {
			return [];
		}
		$chain   = array_reverse( array_map( 'intval', get_post_ancestors( $post ) ) );
		$chain[] = $open;
		foreach ( $chain as $index => $id ) {
			if ( ! current_user_can( 'edit_post', $id ) ) {
				return array_slice( $chain, 0, $index );
			}
		}
		return $chain;
	}

	/**
	 * Puts one opened level into the output. A level above the opened page
	 * shows only the page that leads down, then opens the next level inside
	 * it; the opened page shows one page of 50 subpages. So the rows grow
	 * with the opened page's depth plus 50, never with a subtree.
	 *
	 * @param int[]             $chain Pages to open, top level first.
	 * @param int               $level Index of the page opened here.
	 * @param string            $type  Post type name.
	 * @param array<int,mixed> $out   Rows so far.
	 */
	private function open( array $chain, int $level, string $type, array &$out ): void {
		$node    = $chain[ $level ];
		$next    = $chain[ $level + 1 ] ?? 0;
		$cap     = Subtree::count_cap();
		$ordered = array_map( 'intval', array_column( Subtree::ordered_children( [ $node ], $type, $cap ), 'ID' ) );
		$found   = $next > 0 ? array_search( $next, $ordered, true ) : false;
		$total   = Subtree::count_live_children( $node, $type, $cap + 1 );
		$capped  = $total > $cap;
		$fact    = $this->rows[ $node ] ?? self::fact( $level, 0, null, null, 1 );

		if ( false !== $found ) {
			// A level above the opened page: only the path down.
			$page                = intdiv( (int) $found, self::PER_PAGE ) + 1;
			$this->rows[ $node ] = array_merge(
				$fact,
				[
					'open'  => true,
					'shown' => $page,
				]
			);
			$child               = get_post( $next );
			if ( $child instanceof WP_Post ) {
				$this->rows[ $next ] = self::fact( $level + 1, $node, $capped ? null : (int) $found + 1, $capped ? null : $total, $page );
				$out[]               = $child;
				$this->open( $chain, $level + 1, $type, $out );
			}
			return;
		}

		// The opened page, or a level whose next page lies past the cap: one page of 50.
		$page = min( $next > 0 ? 1 : self::children_page(), max( 1, (int) ceil( count( $ordered ) / self::PER_PAGE ) ) );
		$ids  = array_slice( $ordered, ( $page - 1 ) * self::PER_PAGE, self::PER_PAGE );
		_prime_post_caches( $ids, false, false );
		$this->rows[ $node ] = array_merge(
			$fact,
			[
				'open'   => true,
				'shown'  => $page,
				'more'   => $page * self::PER_PAGE < min( $total, $cap ),
				'capped' => $capped && $page * self::PER_PAGE >= $cap,
			]
		);
		foreach ( $ids as $index => $id ) {
			$child = get_post( $id );
			if ( ! $child instanceof WP_Post || ! current_user_can( 'read_post', $id ) ) {
				continue;
			}
			$position          = ( $page - 1 ) * self::PER_PAGE + $index + 1;
			$this->rows[ $id ] = self::fact( $level + 1, $node, $capped ? null : $position, $capped ? null : $total, $page );
			$out[]             = $child;
		}
	}

	/**
	 * One row's facts.
	 *
	 * @param int      $level    Depth, 0 for the top level.
	 * @param int      $parent_id Parent post ID, 0 for the top level.
	 * @param int|null $position Place among its siblings, from 1, when known.
	 * @param int|null $setsize  Number of its siblings, when known.
	 * @param int      $page     The page of its parent's subpages it is on.
	 * @return array{level:int,parent:int,position:int|null,setsize:int|null,page:int,open:bool,shown:int,more:bool,capped:bool}
	 */
	private static function fact( int $level, int $parent_id, ?int $position, ?int $setsize, int $page ): array {
		return [
			'level'    => $level,
			'parent'   => $parent_id,
			'position' => $position,
			'setsize'  => $setsize,
			'page'     => $page,
			'open'     => false,
			'shown'    => 0,
			'more'     => false,
			'capped'   => false,
		];
	}

	/**
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `npm run test:php -- --filter TreeViewTest`
Expected: PASS (27 tests).

Run: `npm run test:php`, `npx wp-env run tests-cli --env-cwd=wp-content/plugins/hierarchy-guard bash -c 'WP_MULTISITE=1 vendor/bin/phpunit'`, `composer lint`
Expected: 878 tests pass (5 skipped), and as multisite; lint exits 0.

- [ ] **Step 5: Commit**

```bash
git add includes/HierarchyGuard/Admin/Tree_View.php tests/phpunit/TreeViewTest.php
git commit -m "feat: without JavaScript an opened page's levels follow it in core's results"
```

### Task 4: The tree's Hierarchy column, toggles without JavaScript, and a search's parent paths

**Files:**
- Modify: `includes/HierarchyGuard/Admin/List_Table.php` (constructor, the cell in the tree and in a search, `render_tree_cell()`, `trail()`, the screen's counts)
- Modify: `includes/HierarchyGuard/Admin/Tree_View.php` (`load()` hooks the controls; `controls()`, `collapse_all()`)
- Modify: `includes/HierarchyGuard/Plugin.php` (hands the `Tree_View` to `List_Table`)
- Test: `tests/phpunit/ListTableTest.php`, `tests/phpunit/TreeViewTest.php`

**Interfaces:**
- Consumes: `Tree_View::mode()`, `Tree_View::row()`, `Tree_View::place_url()`, `Tree_View::open_levels()`.
- Produces: `List_Table::__construct( ?Tree_View $tree = null )`.
- Produces: the tree's cell: `<span class="hierarchy-guard-cell" data-id data-children data-status data-level data-parent data-page [data-position data-setsize] [data-open]>` holding, separated by `<span aria-hidden="true"> · </span>`, `a.hierarchy-guard-toggle` (only for a page with subpages the user may edit; `href` opens it, or for an opened page goes back to its parent; a dashicon and a screen-reader label), the count link, then `a.hierarchy-guard-more[data-page]` or `span.hierarchy-guard-capped` under an opened page. `data-children` is the count up to the cap plus one; `data-open` is the page of subpages shown.
- Produces: in a search, the cell's parent path, each ancestor linked to `List_Table::filter_url()`, after `Parent page:` or `Parent pages:` for screen readers. The closed toggle's dashicon is `arrow-left-alt2` when `is_rtl()`, so it points toward the title.
- Produces: `Tree_View::controls( $post_type, $which )` on `restrict_manage_posts`: asks for the list-table script, prints `<input type="hidden" name="hierarchy_guard_view" value="tree">`, then in a search `span.hierarchy-guard-back` with the "Back to the tree" link, otherwise `<span class="hierarchy-guard-tree" hidden data-type data-place data-total data-start data-per-page>`; `Tree_View::collapse_all( $which )` on `manage_posts_extra_tablenav` prints `a.hierarchy-guard-collapse` in the tree.

- [ ] **Step 1: Write the failing tests**

In `tests/phpunit/ListTableTest.php`, replace (1 of 3):

```php
use HierarchyGuard\Admin\List_Table;
```

with:

```php
use HierarchyGuard\Admin\List_Table;
use HierarchyGuard\Admin\Tree_View;
```

In `tests/phpunit/ListTableTest.php`, replace (2 of 3):

```php
		unset( $_GET[ List_Table::QUERY_VAR ], $GLOBALS['pagenow'] );
		$GLOBALS['wp_query'] = new WP_Query();
```

with:

```php
		unset( $_GET[ List_Table::QUERY_VAR ], $_GET[ Tree_View::QUERY_VAR ], $_GET[ Tree_View::OPEN_VAR ], $_GET['s'], $GLOBALS['pagenow'] );
		$GLOBALS['wp_query']     = new WP_Query();
		$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
```

In `tests/phpunit/ListTableTest.php`, replace (3 of 3):

```php
		$this->assertSame( 1, $asked );
	}
```

with:

```php
		$this->assertSame( 1, $asked );
	}

	/**
	 * A list table on the Tree view, its rows placed as core's main query
	 * holds them: the top-level pages given, and the opened levels.
	 *
	 * @param int[] $top  Top-level post IDs on the screen.
	 * @param int   $open Page opened without JavaScript, or 0.
	 * @return List_Table
	 */
	private function tree_table( array $top, int $open = 0 ): List_Table {
		$_GET[ Tree_View::QUERY_VAR ] = Tree_View::VIEW;
		if ( $open > 0 ) {
			$_GET[ Tree_View::OPEN_VAR ] = (string) $open;
		}
		$tree  = new Tree_View();
		$query = new WP_Query();
		$query->set( 'post_type', 'page' );
		$query->found_posts      = count( $top );
		$GLOBALS['wp_the_query'] = $query;
		$query->posts            = (array) $tree->open_levels( array_map( 'get_post', $top ), $query );
		$GLOBALS['wp_query']     = $query;
		$this->table             = new List_Table( $tree );
		return $this->table;
	}

	public function test_a_tree_cell_holds_the_toggle_and_the_count_but_not_the_parent(): void {
		$this->tree_table( [ $this->ids['company'] ], $this->ids['about'] );

		$cell = $this->cell( $this->ids['team'] );

		$this->assertStringNotContainsString( 'Parent:', $cell, 'The indent shows the parent.' );
		$this->assertStringContainsString( '<a class="hierarchy-guard-toggle" href="' . esc_url( Tree_View::place_url( 'page', $this->ids['team'] ) ) . '"><span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span><span class="screen-reader-text">Show the subpages of “Team”</span></a>', $cell, 'Without JavaScript the toggle reloads the view opened at the page.' );
		$this->assertStringContainsString( 'aria-label="View the 1 subpage of “Team”">1 subpage</a>', $cell );
		$this->assertStringContainsString( 'data-id="' . $this->ids['team'] . '" data-children="1" data-status="publish" data-level="2" data-parent="' . $this->ids['about'] . '" data-page="1" data-position="2" data-setsize="2"', $cell );
	}

	public function test_an_opened_tree_row_closes_to_its_parent_and_says_what_it_shows(): void {
		$this->tree_table( [ $this->ids['company'] ], $this->ids['about'] );

		$cell = $this->cell( $this->ids['about'] );

		$this->assertStringContainsString( 'href="' . esc_url( Tree_View::place_url( 'page', $this->ids['company'] ) ) . '"><span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span><span class="screen-reader-text">Hide the subpages of “About”</span>', $cell );
		$this->assertStringContainsString( 'data-open="1"', $cell );
		$this->assertStringContainsString( '<span aria-hidden="true">—</span><span class="screen-reader-text">No subpages</span>', $this->cell( $this->ids['history'] ) );
	}

	public function test_an_opened_tree_row_with_more_links_to_its_next_50(): void {
		for ( $i = 1; $i <= 50; $i++ ) {
			$this->page( sprintf( 'more-%02d', $i ), $this->ids['about'] );
		}
		$this->tree_table( [ $this->ids['company'] ], $this->ids['about'] );

		$this->assertStringContainsString( '<a class="hierarchy-guard-more" href="' . esc_url( Tree_View::place_url( 'page', $this->ids['about'], 2 ) ) . '" data-page="2">Show more</a>', $this->cell( $this->ids['about'] ) );
	}

	public function test_past_the_cap_an_opened_tree_row_says_only_the_first_are_shown(): void {
		add_filter( 'hierarchy_guard_count_cap', static fn(): int => 1 );
		$this->tree_table( [ $this->ids['company'] ], $this->ids['about'] );

		$this->assertStringContainsString( '<span class="hierarchy-guard-capped">Only the first 1 subpage is shown.</span>', $this->cell( $this->ids['about'] ) );
	}

	public function test_a_tree_row_the_user_may_not_edit_has_no_toggle_but_keeps_its_count(): void {
		$locked = $this->ids['about'];
		add_filter(
			'map_meta_cap',
			static function ( array $caps, string $cap, int $user_id, array $args ) use ( $locked ): array {
				return 'edit_post' === $cap && isset( $args[0] ) && $locked === (int) $args[0] ? [ 'do_not_allow' ] : $caps;
			},
			10,
			4
		);
		$this->tree_table( [ $this->ids['company'] ], $this->ids['company'] );

		$cell = $this->cell( $this->ids['about'] );

		$this->assertStringNotContainsString( 'hierarchy-guard-toggle', $cell, 'The route needs edit rights on the page it opens.' );
		$this->assertStringContainsString( '2 subpages</a>', $cell, 'Its count still links to what sits under it.' );
	}

	public function test_a_tree_screen_counts_its_opened_levels_in_the_same_one_query(): void {
		for ( $i = 1; $i <= 30; $i++ ) {
			$this->page( sprintf( 'wide-%02d', $i ), $this->ids['about'] );
		}
		$this->tree_table( [ $this->ids['company'] ], $this->ids['about'] );
		$rows = wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' );

		$queries = get_num_queries();
		foreach ( $rows as $id ) {
			$this->cell( (int) $id );
		}

		$this->assertCount( 34, $rows );
		$this->assertSame( 1, get_num_queries() - $queries, 'The 34 rows, opened levels included, are counted in one statement.' );
	}

	public function test_a_tree_search_shows_each_parent_path_with_ancestor_links(): void {
		$_GET[ Tree_View::QUERY_VAR ] = Tree_View::VIEW;
		$_GET['s']                    = 'lead';
		$this->table                  = new List_Table( new Tree_View() );
		$this->screen_rows( [ $this->ids['leadership'], $this->ids['company'] ] );

		$cell = $this->cell( $this->ids['leadership'] );

		$this->assertStringContainsString( '<span class="screen-reader-text">Parent pages: </span><a href="' . esc_url( List_Table::filter_url( 'page', $this->ids['company'] ) ) . '">Company</a><span aria-hidden="true"> › </span><a href="' . esc_url( List_Table::filter_url( 'page', $this->ids['about'] ) ) . '">About</a><span aria-hidden="true"> › </span><a href="' . esc_url( List_Table::filter_url( 'page', $this->ids['team'] ) ) . '">Team</a>', $cell, 'Every ancestor links to its own subtree.' );
		$this->assertStringContainsString( 'Top level, no subpages', $this->cell( $this->page( 'lonely-lead' ) ) );
		$this->assertStringContainsString( '1 subpage</a>', $this->cell( $this->ids['company'] ) );
	}

	public function test_a_tree_search_names_one_parent_page_in_the_singular(): void {
		$_GET[ Tree_View::QUERY_VAR ] = Tree_View::VIEW;
		$_GET['s']                    = 'about';
		$this->table                  = new List_Table( new Tree_View() );
		$this->screen_rows( [ $this->ids['about'] ] );

		$this->assertStringContainsString( '<span class="screen-reader-text">Parent page: </span><a href="', $this->cell( $this->ids['about'] ) );
	}

	public function test_a_closed_toggle_points_toward_the_title_right_to_left(): void {
		$GLOBALS['wp_locale']->text_direction = 'rtl';
		$this->tree_table( [ $this->ids['company'] ] );

		$cell                                 = $this->cell( $this->ids['company'] );
		$GLOBALS['wp_locale']->text_direction = 'ltr';

		$this->assertStringContainsString( 'dashicons-arrow-left-alt2', $cell );
	}

	public function test_a_tree_search_loads_the_screen_s_ancestors_one_level_at_a_time(): void {
		$_GET[ Tree_View::QUERY_VAR ] = Tree_View::VIEW;
		$_GET['s']                    = 'e';
		$this->table                  = new List_Table( new Tree_View() );
		$deep                         = $this->page( 'deep', $this->ids['leadership'] );
		$this->screen_rows( [ $deep, $this->ids['history'] ] );
		foreach ( [ 'company', 'about', 'team', 'leadership' ] as $slug ) {
			clean_post_cache( $this->ids[ $slug ] );
		}

		$queries = get_num_queries();
		$this->cell( $deep );
		$this->cell( $this->ids['history'] );

		// The counts; the rows' parents, Leadership and About; then Team; then Company: one query per level, whatever the rows.
		$this->assertSame( 4, get_num_queries() - $queries );
		$this->assertStringContainsString( '>Leadership</a>', $this->cell( $deep ) );
	}
```

In `tests/phpunit/TreeViewTest.php`, replace:

```php
		$this->assertSame( admin_url( 'edit.php?post_type=page&hierarchy_guard_view=tree&orderby=title&m=202610&paged=2&hierarchy_guard_open=5&hierarchy_guard_children_page=2' ), Tree_View::place_url( 'page', 5, 2 ) );
	}
```

with:

```php
		$this->assertSame( admin_url( 'edit.php?post_type=page&hierarchy_guard_view=tree&orderby=title&m=202610&paged=2&hierarchy_guard_open=5&hierarchy_guard_children_page=2' ), Tree_View::place_url( 'page', 5, 2 ) );
	}

	public function test_the_controls_keep_the_view_and_leave_what_the_script_needs(): void {
		$asked = 0;
		add_action(
			'hierarchy_guard_list_script',
			static function () use ( &$asked ): void {
				++$asked;
			}
		);
		$GLOBALS['wp_query'] = $this->list_query();

		ob_start();
		$this->tree->controls( 'page', 'top' );
		$this->tree->collapse_all( 'top' );
		$html = (string) ob_get_clean();

		$this->assertSame( 1, $asked, 'The Tree view always loads the list-table script.' );
		$this->assertStringContainsString( '<input type="hidden" name="hierarchy_guard_view" value="tree">', $html, 'Search and the date filter stay in the tree.' );
		$this->assertStringContainsString( '<span class="hierarchy-guard-tree" hidden data-type="page" data-place="' . esc_url( Tree_View::place_url( 'page' ) ) . '" data-total="1" data-start="0" data-per-page="50"></span>', $html );
		$this->assertStringContainsString( '<a class="hierarchy-guard-collapse" href="' . esc_url( Tree_View::place_url( 'page' ) ) . '">Collapse all</a>', $html );

		ob_start();
		$this->tree->controls( 'page', 'bottom' );
		$this->tree->collapse_all( 'bottom' );
		$this->assertSame( '', (string) ob_get_clean(), 'Printed once, above the table.' );
	}

	public function test_a_search_offers_back_to_the_tree_and_no_collapse_all(): void {
		$_GET['s'] = 'team';

		ob_start();
		$this->tree->controls( 'page', 'top' );
		$this->tree->collapse_all( 'top' );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<span class="hierarchy-guard-back"><a href="' . esc_url( Tree_View::url( 'page' ) ) . '">Back to the tree</a></span>', $html );
		$this->assertStringNotContainsString( 'hierarchy-guard-collapse', $html );
		$this->assertStringNotContainsString( 'class="hierarchy-guard-tree"', $html );
	}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npm run test:php -- --filter 'TreeViewTest|ListTableTest'`
Expected: FAIL. The list's new tests fail on the cell (for example `Failed asserting that '<span class="hierarchy-guard-cell" … Parent: …' does not contain "Parent:".`), and the controls tests error with `Call to undefined method HierarchyGuard\Admin\Tree_View::controls()`.

- [ ] **Step 3: Print the tree's cell, the search's paths and the controls**

In `includes/HierarchyGuard/Admin/List_Table.php`, replace (1 of 7):

```php
	private ?array $walk = null;
```

with:

```php
	private ?array $walk = null;

	/**
	 * The Tree view, which changes what the column shows.
	 *
	 * @var Tree_View|null
	 */
	private ?Tree_View $tree;

	/**
	 * Constructor.
	 *
	 * @param Tree_View|null $tree The Tree view, if the plugin has one.
	 */
	public function __construct( ?Tree_View $tree = null ) {
		$this->tree = $tree;
	}
```

In `includes/HierarchyGuard/Admin/List_Table.php`, replace (2 of 7):

```php
	 * Prints the parent, then the subpage count as a link to the subtree.
```

with:

```php
	 * Prints the parent, then the subpage count as a link to the subtree. In
	 * the Tree view the indent shows the parent, so the cell holds the count
	 * and the toggle; in a search there it holds the whole parent path, each
	 * ancestor a link to its own subtree.
```

In `includes/HierarchyGuard/Admin/List_Table.php`, replace (3 of 7):

```php
		$above  = $parent > 0 ? get_post( $parent ) : null;

		if ( $above instanceof WP_Post ) {
```

with:

```php
		$above  = $parent > 0 ? get_post( $parent ) : null;
		$mode   = null !== $this->tree ? $this->tree->mode( $type ) : '';

		if ( 'search' === $mode && $above instanceof WP_Post ) {
			$parts[] = '<span class="screen-reader-text">' . esc_html( _n( 'Parent page:', 'Parent pages:', count( get_post_ancestors( $post ) ), 'hierarchy-guard' ) ) . ' </span>' . $this->trail( $post );
		} elseif ( '' === $mode && $above instanceof WP_Post ) {
```

In `includes/HierarchyGuard/Admin/List_Table.php`, replace (4 of 7):

```php
			$parts[] = sprintf( '<a href="%1$s" aria-label="%2$s">%3$s</a>', esc_url( self::filter_url( $type, (int) $post->ID ) ), esc_attr( $aria ), esc_html( $label ) );
		}

		printf(
```

with:

```php
			$parts[] = sprintf( '<a href="%1$s" aria-label="%2$s">%3$s</a>', esc_url( self::filter_url( $type, (int) $post->ID ) ), esc_attr( $aria ), esc_html( $label ) );
		}

		if ( 'tree' === $mode ) {
			$this->render_tree_cell( $post, $count, $parts );
			return;
		}

		printf(
```

In `includes/HierarchyGuard/Admin/List_Table.php`, replace (5 of 7):

```php
			[] !== $parts ? implode( '<span aria-hidden="true"> · </span>', $parts ) : '<span aria-hidden="true">—</span><span class="screen-reader-text">' . esc_html__( 'Top level, no subpages', 'hierarchy-guard' ) . '</span>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Every part was escaped as it was built.
		);
```

with:

```php
			[] !== $parts ? implode( '<span aria-hidden="true"> · </span>', $parts ) : '<span aria-hidden="true">—</span><span class="screen-reader-text">' . esc_html__( 'Top level, no subpages', 'hierarchy-guard' ) . '</span>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Every part was escaped as it was built.
		);
	}

	/**
	 * The Tree view's cell: the toggle for a page the user may edit, the
	 * count, and under an opened page with more subpages a link to the next
	 * 50, with the row's place in the tree for the script.
	 *
	 * @param WP_Post  $post  Row's post.
	 * @param int      $count Live children, up to the cap plus one.
	 * @param string[] $parts The count's link, if any.
	 */
	private function render_tree_cell( WP_Post $post, int $count, array $parts ): void {
		$id    = (int) $post->ID;
		$type  = (string) $post->post_type;
		$cap   = Subtree::count_cap();
		$row   = null !== $this->tree ? $this->tree->row( $id ) : null;
		$open  = null !== $row && $row['open'];
		$title = _draft_or_post_title( $post );

		if ( $count > 0 && current_user_can( 'edit_post', $id ) ) {
			$this->signal();
			$href = $open ? Tree_View::place_url( $type, null !== $row ? $row['parent'] : 0, null !== $row ? $row['page'] : 1 ) : Tree_View::place_url( $type, $id );
			array_unshift(
				$parts,
				sprintf(
					'<a class="hierarchy-guard-toggle" href="%1$s"><span class="dashicons dashicons-arrow-%2$s-alt2" aria-hidden="true"></span><span class="screen-reader-text">%3$s</span></a>',
					esc_url( $href ),
					// A closed toggle points along the line toward the title.
					$open ? 'down' : ( is_rtl() ? 'left' : 'right' ),
					$open
						/* translators: %s: page title. */
						? sprintf( esc_html__( 'Hide the subpages of “%s”', 'hierarchy-guard' ), $title )
						/* translators: %s: page title. */
						: sprintf( esc_html__( 'Show the subpages of “%s”', 'hierarchy-guard' ), $title )
				)
			);
		}
		if ( null !== $row && $row['more'] ) {
			$parts[] = sprintf(
				'<a class="hierarchy-guard-more" href="%1$s" data-page="%2$d">%3$s</a>',
				esc_url( Tree_View::place_url( $type, $id, $row['shown'] + 1 ) ),
				$row['shown'] + 1,
				esc_html__( 'Show more', 'hierarchy-guard' )
			);
		} elseif ( null !== $row && $row['capped'] ) {
			$parts[] = '<span class="hierarchy-guard-capped">' . esc_html(
				sprintf(
					/* translators: %s: the most subpages the tree shows, such as 1,000. */
					_n( 'Only the first %s subpage is shown.', 'Only the first %s subpages are shown.', $cap, 'hierarchy-guard' ),
					Subtree::format_count( $cap )
				)
			) . '</span>';
		}

		$data = [
			'id'       => $id,
			'children' => min( $count, $cap + 1 ),
			'status'   => (string) $post->post_status,
			'level'    => null !== $row ? $row['level'] : 0,
			'parent'   => null !== $row ? $row['parent'] : (int) $post->post_parent,
			'page'     => null !== $row ? $row['page'] : 1,
		];
		if ( null !== $row && null !== $row['position'] ) {
			$data['position'] = $row['position'];
		}
		if ( null !== $row && null !== $row['setsize'] ) {
			$data['setsize'] = $row['setsize'];
		}
		if ( $open ) {
			$data['open'] = $row['shown'];
		}
		$attributes = '';
		foreach ( $data as $key => $value ) {
			$attributes .= sprintf( ' data-%1$s="%2$s"', esc_attr( $key ), esc_attr( (string) $value ) );
		}

		printf(
			'<span class="hierarchy-guard-cell"%1$s>%2$s</span>',
			$attributes, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Every attribute was escaped as it was built.
			[] !== $parts ? implode( '<span aria-hidden="true"> · </span>', $parts ) : '<span aria-hidden="true">—</span><span class="screen-reader-text">' . esc_html__( 'No subpages', 'hierarchy-guard' ) . '</span>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Every part was escaped as it was built.
		);
	}

	/**
	 * A search row's parent path, top level first, each ancestor a link to its
	 * own subtree. The screen's ancestors were loaded together with its counts.
	 *
	 * @param WP_Post $post Row's post.
	 * @return string
	 */
	private function trail( WP_Post $post ): string {
		$type   = (string) $post->post_type;
		$crumbs = [];
		foreach ( array_reverse( get_post_ancestors( $post ) ) as $ancestor_id ) {
			$ancestor_id = (int) $ancestor_id;
			if ( ! current_user_can( 'read_post', $ancestor_id ) ) {
				$crumbs[] = esc_html__( '(private page)', 'hierarchy-guard' );
				continue;
			}
			$title = _draft_or_post_title( $ancestor_id );
			if ( 'trash' === get_post_status( $ancestor_id ) ) {
				/* translators: %s: title of a parent page that is in the trash. */
				$crumbs[] = sprintf( esc_html__( '%s (in the Trash)', 'hierarchy-guard' ), $title );
				continue;
			}
			$crumbs[] = sprintf( '<a href="%1$s">%2$s</a>', esc_url( self::filter_url( $type, $ancestor_id ) ), $title );
		}
		return implode( '<span aria-hidden="true"> › </span>', $crumbs );
```

In `includes/HierarchyGuard/Admin/List_Table.php`, replace (6 of 7):

```php
			$start = $tree_query ? ( $pagenum - 1 ) * $per_page : 0;
			$ids   = [];
			foreach ( array_slice( $rows, $start, $per_page ) as $row ) {
```

with:

```php
			// A flat screen is one page already; the Tree view's opened levels add theirs to it.
			$ids = [];
			foreach ( $tree_query ? array_slice( $rows, ( $pagenum - 1 ) * $per_page, $per_page ) : $rows as $row ) {
```

In `includes/HierarchyGuard/Admin/List_Table.php`, replace (7 of 7):

```php
		_prime_post_caches( array_values( array_unique( $parents ) ), false, false );
```

with:

```php
		$parents = array_values( array_unique( $parents ) );
		_prime_post_caches( $parents, false, false );
		if ( null !== $this->tree && 'search' === $this->tree->mode( $post_type ) ) {
			// A search shows each row's whole parent path: one query per level, whatever the rows.
			for ( $depth = 0; [] !== $parents && $depth < 50; ++$depth ) {
				$above = [];
				foreach ( $parents as $parent_id ) {
					$above[] = (int) get_post_field( 'post_parent', $parent_id );
				}
				$parents = array_values( array_unique( array_filter( $above ) ) );
				_prime_post_caches( $parents, false, false );
			}
		}
```

In `includes/HierarchyGuard/Admin/Tree_View.php`, replace (1 of 2):

```php
		add_filter( 'quick_edit_enabled_for_post_type', '__return_false' );
```

with:

```php
		add_filter( 'quick_edit_enabled_for_post_type', '__return_false' );
		add_action( 'restrict_manage_posts', [ $this, 'controls' ], 10, 2 );
		add_action( 'manage_posts_extra_tablenav', [ $this, 'collapse_all' ] );
```

In `includes/HierarchyGuard/Admin/Tree_View.php`, replace (2 of 2):

```php
		return [];
	}

	/**
```

with:

```php
		return [];
	}

	/**
	 * Keeps the view across Search and the date filter, says where "Back to
	 * the tree" leads in a search, and leaves what the script needs.
	 *
	 * @param mixed $post_type Post type of the list.
	 * @param mixed $which     Which table navigation, top or bottom.
	 */
	public function controls( $post_type, $which = 'top' ): void {
		$type = (string) $post_type;
		$mode = $this->mode( $type );
		if ( 'top' !== $which || '' === $mode ) {
			return;
		}
		/** This action is documented in includes/HierarchyGuard/Admin/List_Table.php */
		do_action( 'hierarchy_guard_list_script' );

		printf( '<input type="hidden" name="%1$s" value="%2$s">', esc_attr( self::QUERY_VAR ), esc_attr( self::VIEW ) );
		if ( 'search' === $mode ) {
			printf( '<span class="hierarchy-guard-back"><a href="%1$s">%2$s</a></span>', esc_url( self::url( $type ) ), esc_html__( 'Back to the tree', 'hierarchy-guard' ) );
			return;
		}

		global $wp_query;
		$per_page = List_Table::per_page( $type );
		$paged    = $wp_query instanceof WP_Query ? max( 1, (int) $wp_query->get( 'paged' ) ) : 1;
		printf(
			'<span class="hierarchy-guard-tree" hidden data-type="%1$s" data-place="%2$s" data-total="%3$d" data-start="%4$d" data-per-page="%5$d"></span>',
			esc_attr( $type ),
			esc_url( self::place_url( $type ) ),
			$wp_query instanceof WP_Query ? (int) $wp_query->found_posts : 0,
			(int) ( ( $paged - 1 ) * $per_page ),
			(int) self::PER_PAGE
		);
	}

	/**
	 * "Collapse all" above the tree: a link that reloads the view with nothing
	 * opened, which the script turns into a button that needs no request.
	 *
	 * @param mixed $which Which table navigation, top or bottom.
	 */
	public function collapse_all( $which ): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$type   = null !== $screen ? (string) $screen->post_type : '';
		if ( 'top' !== $which || 'tree' !== $this->mode( $type ) ) {
			return;
		}
		printf(
			'<div class="alignleft actions"><a class="hierarchy-guard-collapse" href="%1$s">%2$s</a></div>',
			esc_url( self::place_url( $type ) ),
			esc_html__( 'Collapse all', 'hierarchy-guard' )
		);
	}

	/**
```

In `includes/HierarchyGuard/Plugin.php`, replace:

```php
		( new Admin\Tree_View() )->register();
		( new Admin\List_Table() )->register();
```

with:

```php
		$tree = new Admin\Tree_View();
		$tree->register();
		( new Admin\List_Table( $tree ) )->register();
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `npm run test:php -- --filter 'TreeViewTest|ListTableTest'`
Expected: PASS (29 and 34 tests).

Run: `npm run test:php`, `composer lint`
Expected: 890 tests pass (5 skipped); lint exits 0.

- [ ] **Step 5: Commit**

```bash
git add includes/HierarchyGuard/Admin/List_Table.php includes/HierarchyGuard/Admin/Tree_View.php includes/HierarchyGuard/Plugin.php tests/phpunit/ListTableTest.php tests/phpunit/TreeViewTest.php
git commit -m "feat: the tree's Hierarchy column, toggles without JavaScript, and a search's parent paths"
```

### Task 5: A trash from the tree, and its Undo, come back to the same place

**Files:**
- Modify: `includes/HierarchyGuard/Admin/Tree_View.php` (`load()` keeps Undo's page and hooks `page_row_actions`; `row_actions()`, `keep_page_on_undo()`)
- Test: `tests/phpunit/TreeViewTest.php`

**Interfaces:**
- Consumes: `Tree_View::row()`, `Tree_View::place_url()`; core's `wp_get_referer()`, which reads `_wp_http_referer` before the browser's referrer.
- Produces: `Tree_View::row_actions( $actions, $post )` on `page_row_actions` (20, 2 arguments) in the tree, not a search: core's own Trash link with `_wp_http_referer` = `place_url( type, row's parent, row's page )` added, nothing else changed.
- Produces: `Tree_View::keep_page_on_undo()`, called first in `load()`: on an Undo request (`doaction=undo`) referred by the Tree view, a `wp_redirect` filter sets that redirect's `paged` to the referring page's, or removes it for page 1.

- [ ] **Step 1: Write the failing tests**

In `tests/phpunit/TreeViewTest.php`, replace (1 of 2):

```php
		$this->assertSame( $cost( $small ), $cost( $big ), 'One page of 50 per opened level, read the same way.' );
	}

	public function test_the_place_keeps_only_the_view_s_own_arguments(): void {
```

with:

```php
		$this->assertSame( $cost( $small ), $cost( $big ), 'One page of 50 per opened level, read the same way.' );
	}

	public function test_the_trash_link_carries_the_tree_s_place_as_core_s_referer(): void {
		update_user_option( get_current_user_id(), 'edit_page_per_page', 1 );
		$_GET['paged']               = '3';
		$_GET['orderby']             = 'title';
		$_GET['order']               = 'desc';
		$_GET[ Tree_View::OPEN_VAR ] = (string) $this->ids['about'];
		$this->open_levels( [ $this->ids['company'] ], 3, 3 );
		$link = '<a href="' . esc_url( (string) get_delete_post_link( $this->ids['team'] ) ) . '" class="submitdelete" aria-label="Move “Team” to the Trash">Trash</a>';

		$actions = $this->tree->row_actions( [ 'trash' => $link ], get_post( $this->ids['team'] ) );

		$this->assertMatchesRegularExpression( '/^<a href="[^"]+" class="submitdelete" aria-label="Move “Team” to the Trash">Trash<\/a>$/', $actions['trash'], 'Core’s link, with one more argument.' );
		preg_match( '/href="([^"]+)"/', $actions['trash'], $href );
		parse_str( (string) wp_parse_url( html_entity_decode( $href[1] ), PHP_URL_QUERY ), $args );
		$this->assertSame( 'trash', $args['action'] );
		$this->assertSame( 1, wp_verify_nonce( $args['_wpnonce'], 'trash-post_' . $this->ids['team'] ) );
		$this->assertSame( Tree_View::place_url( 'page', $this->ids['about'] ), $args['_wp_http_referer'], 'The same page of top-level pages, opened at the trashed page’s parent.' );
		$this->assertStringContainsString( 'paged=3', $args['_wp_http_referer'] );
		$this->assertStringContainsString( 'orderby=title', $args['_wp_http_referer'] );
	}

	public function test_a_trash_from_a_search_in_the_tree_returns_to_the_search(): void {
		$_GET['s'] = 'team';
		$this->tree->load();
		$link = '<a href="' . esc_url( (string) get_delete_post_link( $this->ids['team'] ) ) . '" class="submitdelete">Trash</a>';

		$actions = apply_filters( 'page_row_actions', [ 'trash' => $link ], get_post( $this->ids['team'] ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's own row actions filter.

		$this->assertSame( $link, $actions['trash'], 'Core’s link: the browser’s referrer returns to the results.' );
	}

	public function test_the_place_keeps_only_the_view_s_own_arguments(): void {
```

In `tests/phpunit/TreeViewTest.php`, replace (2 of 2):

```php
		$this->assertSame( admin_url( 'edit.php?post_type=page&hierarchy_guard_view=tree&orderby=title&m=202610&paged=2&hierarchy_guard_open=5&hierarchy_guard_children_page=2' ), Tree_View::place_url( 'page', 5, 2 ) );
```

with:

```php
		$this->assertSame( admin_url( 'edit.php?post_type=page&hierarchy_guard_view=tree&orderby=title&m=202610&paged=2&hierarchy_guard_open=5&hierarchy_guard_children_page=2' ), Tree_View::place_url( 'page', 5, 2 ) );
	}

	public function test_undo_lands_on_the_tree_s_page(): void {
		$_GET['doaction']        = 'undo';
		$_SERVER['HTTP_REFERER'] = Tree_View::place_url( 'page', $this->ids['about'] ) . '&paged=3&trashed=1&ids=9';
		$core                    = admin_url( 'edit.php?post_type=page&hierarchy_guard_view=tree&hierarchy_guard_open=' . $this->ids['about'] . '&paged=1&untrashed=1' );

		$this->tree->keep_page_on_undo();
		$location = apply_filters( 'wp_redirect', $core, 302 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's own redirect filter.

		$this->assertSame( str_replace( 'paged=1', 'paged=3', $core ), $location, 'Core sets the page from the Undo link, which has none.' );
	}

	public function test_undo_elsewhere_is_left_to_core(): void {
		$_GET['doaction']        = 'undo';
		$_SERVER['HTTP_REFERER'] = admin_url( 'edit.php?post_type=page&paged=3' );
		$core                    = admin_url( 'edit.php?post_type=page&paged=1' );

		$this->tree->keep_page_on_undo();

		$this->assertSame( $core, apply_filters( 'wp_redirect', $core, 302 ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's own redirect filter.
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npm run test:php -- --filter TreeViewTest`
Expected: FAIL. `Error: Call to undefined method HierarchyGuard\Admin\Tree_View::row_actions()` and `::keep_page_on_undo()`.

- [ ] **Step 3: Carry the tree's place on the trash link and keep Undo's page**

In `includes/HierarchyGuard/Admin/Tree_View.php`, replace (1 of 4):

```php
	public function load(): void {
```

with:

```php
	public function load(): void {
		$this->keep_page_on_undo();
```

In `includes/HierarchyGuard/Admin/Tree_View.php`, replace (2 of 4):

```php
			add_filter( 'admin_body_class', [ $this, 'body_class' ] );
```

with:

```php
			add_filter( 'admin_body_class', [ $this, 'body_class' ] );
			// A search's rows are core's, and the browser's referrer already returns to the results.
			add_filter( 'page_row_actions', [ $this, 'row_actions' ], 20, 2 );
```

In `includes/HierarchyGuard/Admin/Tree_View.php`, replace (3 of 4):

```php
		return [];
	}

	/**
```

with:

```php
		return [];
	}

	/**
	 * The trash link carries the tree's place as core's _wp_http_referer, so
	 * core's redirect after the trash lands on the same page of top-level
	 * pages, opened at the trashed page's parent.
	 *
	 * @param mixed $actions Row actions.
	 * @param mixed $post    Post.
	 * @return mixed
	 */
	public function row_actions( $actions, $post ) {
		if ( ! is_array( $actions ) || ! isset( $actions['trash'] ) || ! $post instanceof WP_Post ) {
			return $actions;
		}
		$row   = $this->row( (int) $post->ID );
		$place = self::place_url( (string) $post->post_type, null !== $row ? $row['parent'] : 0, null !== $row ? $row['page'] : 1 );

		$actions['trash'] = (string) preg_replace_callback(
			'/href="([^"]*)"/',
			static fn( array $href ): string => 'href="' . esc_url( add_query_arg( '_wp_http_referer', rawurlencode( $place ), html_entity_decode( $href[1], ENT_QUOTES, 'UTF-8' ) ) ) . '"',
			(string) $actions['trash'],
			1
		);
		return $actions;
	}

	/**
```

In `includes/HierarchyGuard/Admin/Tree_View.php`, replace (4 of 4):

```php
		return is_string( $classes ) ? $classes . ' hierarchy-guard-tree-view ' : $classes;
```

with:

```php
		return is_string( $classes ) ? $classes . ' hierarchy-guard-tree-view ' : $classes;
	}

	/**
	 * Undo on core's trash notice redirects to the page it was clicked on,
	 * but core sets the page number from the Undo request, which has none.
	 * For the tree, the page number comes back from that referring page.
	 */
	public function keep_page_on_undo(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Core checks the bulk-posts nonce before it redirects.
		if ( ! isset( $_GET['doaction'] ) || 'undo' !== $_GET['doaction'] ) {
			return;
		}
		$referer = wp_get_referer();
		$query   = is_string( $referer ) ? (string) wp_parse_url( $referer, PHP_URL_QUERY ) : '';
		parse_str( $query, $args );
		if ( self::VIEW !== ( $args[ self::QUERY_VAR ] ?? '' ) ) {
			return;
		}
		$paged = absint( $args['paged'] ?? 0 );
		add_filter(
			'wp_redirect',
			static function ( $location ) use ( $paged ) {
				if ( ! is_string( $location ) ) {
					return $location;
				}
				return $paged > 1 ? add_query_arg( 'paged', $paged, $location ) : remove_query_arg( 'paged', $location );
			}
		);
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `npm run test:php -- --filter TreeViewTest`
Expected: PASS (33 tests).

Run: `npm run test:php`, `npx wp-env run tests-cli --env-cwd=wp-content/plugins/hierarchy-guard bash -c 'WP_MULTISITE=1 vendor/bin/phpunit'`, `composer lint`
Expected: 894 tests pass (5 skipped), and as multisite; lint exits 0.

- [ ] **Step 5: Commit**

```bash
git add includes/HierarchyGuard/Admin/Tree_View.php tests/phpunit/TreeViewTest.php
git commit -m "feat: a trash from the tree, and its Undo, come back to the same place"
```

### Task 6: A subpage the tree loads gets a row with the list's columns and actions

**Files:**
- Modify: `package.json`, `package-lock.json` (happy-dom, dev only)
- Modify: `src/common/copy.js` (`treeRowLabels()`, `viewSubpagesLabel()`, `childOfLabel()`, `lockedLabel()`)
- Create: `src/tree/rows.js`
- Test: `src/tree/test/fixture.js`, `src/tree/test/rows.test.js`, `src/common/test/copy.test.js`

**Interfaces:**
- Consumes: the route's child fields from Task 1; `subpagesLabel()` from `src/common/copy.js`.
- Produces (copy): `treeRowLabels( title: string ): Object<string,string>` with `edit`, `editLabel`, `trash`, `trashLabel`, `view`, `viewLabel`, `subpages`, `subpagesLabel`, `details`, `noSubpages`, `noAuthor`; `viewSubpagesLabel( count: number, capped: boolean, title: string ): string`; `childOfLabel( title ): string` ("Child of %s"); `lockedLabel( name ): string` ("%s is currently editing").
- Produces (rows): `PER_PAGE = 50`; `placeUrl( place: string, parent: number, page = 1 ): string`; `subtreeUrl( type: string, id: number ): string`; `childRow( template: HTMLTableRowElement, child: Object, context: {level, parent, parentTitle, page, position, setsize, place, type} ): HTMLTableRowElement`, a row `#post-{id}.level-{level}` (plus `wp-locked` when another user holds the lock) whose cells copy the template's tag, classes, `scope` and `data-colname`, and the title cell's `aria-label` (set to the title) when the template's has one; the title cell holds core's `.locked-info`, the title with `aria-describedby="post-hierarchy-{id}"` and its hidden "Child of …" description, and core's post states; a missed schedule's label is `strong.error-message`; the Hierarchy cell has its data attributes as the server prints them; `statusRow( template, parent: number, level: number ): {row, cell}`, a one-cell row spanning the visible columns.
- Produces (fixture): `coreRow( {...} ): string`, `mountScreen( rows: string )`, `child( id, title, overrides )`, `page( children, total, hasMore, capped )`.

- [ ] **Step 1: Add the DOM test environment**

Run: `npm install --save-dev --save-exact happy-dom@20.14.5`
Expected: `package.json` gains the line below and `package-lock.json` is updated.

In `package.json`, replace:

```json
		"@wordpress/url": "^4.57.0",
```

with:

```json
		"@wordpress/url": "^4.57.0",
		"happy-dom": "20.14.5",
```

- [ ] **Step 2: Write the failing tests**

Create `src/tree/test/fixture.js`:
```js
/**
 * Core's list table as the Tree view prints it, for the tree script's tests.
 */

/**
 * One row as core prints it, with the Hierarchy cell the plugin prints.
 *
 * @param {Object}  row          Row.
 * @param {number}  row.id       Post ID.
 * @param {string}  row.title    Title.
 * @param {number}  row.level    Level, from 0.
 * @param {number}  row.parent   Parent ID.
 * @param {number}  row.children Live subpages.
 * @param {number}  row.position Place among its siblings.
 * @param {number}  row.setsize  Number of its siblings.
 * @param {boolean} row.toggle   Whether the user may open it.
 * @param {number}  row.open     The children page the server opened, or 0.
 * @param {string}  row.extra    More cell markup.
 * @return {string} Markup.
 */
export function coreRow( {
	id,
	title,
	level = 0,
	parent = 0,
	children = 0,
	position = 1,
	setsize = 3,
	toggle = children > 0,
	open = 0,
	extra = '',
} ) {
	const sep = '<span aria-hidden="true"> · </span>';
	const toggleLink = toggle
		? `<a class="hierarchy-guard-toggle" href="edit.php?post_type=page&hierarchy_guard_view=tree&hierarchy_guard_open=${ id }"><span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span><span class="screen-reader-text">Show the subpages of “${ title }”</span></a>${ sep }`
		: '';
	const count = children
		? `<a href="edit.php?post_type=page&hierarchy_guard_under=${ id }">${ children } subpages</a>`
		: '<span aria-hidden="true">—</span>';
	return `<tr id="post-${ id }" class="iedit level-${ level }">
		<th scope="row" class="title column-title has-row-actions column-primary page-title" data-colname="Title" aria-label="${ title }"><div class="locked-info"><span class="locked-avatar"></span> <span class="locked-text"></span></div><strong><a class="row-title" href="post.php?post=${ id }&action=edit">${ title }</a></strong><div class="row-actions"><span class="trash"><a href="post.php?post=${ id }&action=trash" class="submitdelete">Trash</a></span></div></th>
		<td class="hierarchy_guard column-hierarchy_guard" data-colname="Hierarchy"><span class="hierarchy-guard-cell" data-id="${ id }" data-children="${ children }" data-status="publish" data-level="${ level }" data-parent="${ parent }" data-page="1" data-position="${ position }" data-setsize="${ setsize }"${ open ? ` data-open="${ open }"` : '' }>${ toggleLink }${ count }${ extra }</span></td>
		<td class="author column-author" data-colname="Author">Sam</td>
		<td class="comments column-comments hidden" data-colname="Comments"></td>
		<td class="date column-date" data-colname="Date">Published<br>2026/09/02 at 3:04 pm</td>
	</tr>`;
}

/**
 * The screen: the tree's data, Collapse all and the table.
 *
 * @param {string} rows Row markup.
 * @return {void}
 */
export function mountScreen( rows ) {
	document.body.innerHTML = `<div class="wrap">
		<span class="hierarchy-guard-tree" hidden data-type="page" data-place="https://example.org/wp-admin/edit.php?post_type=page&hierarchy_guard_view=tree&paged=2" data-total="3" data-start="20" data-per-page="50"></span>
		<div class="alignleft actions"><a class="hierarchy-guard-collapse" href="edit.php?post_type=page&hierarchy_guard_view=tree">Collapse all</a></div>
		<table class="wp-list-table widefat fixed striped pages"><thead><tr><th id="title">Title</th><th id="hierarchy_guard">Hierarchy</th><th id="author">Author</th><th id="comments" class="hidden">Comments</th><th id="date">Date</th></tr></thead>
		<tbody id="the-list">${ rows }</tbody></table></div>`;
}

/**
 * One subpage as the hierarchy route sends it.
 *
 * @param {number} id        Post ID.
 * @param {string} title     Title.
 * @param {Object} overrides Fields to change.
 * @return {Object} Child.
 */
export function child( id, title, overrides = {} ) {
	return {
		id,
		title,
		status: 'publish',
		status_label: 'Published',
		children_count: 0,
		children_capped: false,
		author: 'Priya',
		date: { label: 'Published', date: '2026/07/19 at 9:00 am' },
		edit_link: `https://example.org/wp-admin/post.php?post=${ id }&action=edit`,
		view_link: `https://example.org/page-${ id }/`,
		trash_link: `https://example.org/wp-admin/post.php?post=${ id }&action=trash&_wpnonce=abc`,
		...overrides,
	};
}

/**
 * A page of subpages as the hierarchy route sends it.
 *
 * @param {Object[]} children Children.
 * @param {number}   total    Children in all.
 * @param {boolean}  hasMore  Whether a next page exists.
 * @param {boolean}  capped   Whether more than the cap exist.
 * @return {Object} Hierarchy.
 */
export function page( children, total, hasMore = false, capped = false ) {
	return {
		children,
		children_total: total,
		children_capped: capped,
		has_more: hasMore,
	};
}
```

Create `src/tree/test/rows.test.js`:
```js
// @vitest-environment happy-dom
/**
 * The rows the Tree view adds: a loaded subpage reads as one core prints.
 */
import { beforeEach, describe, expect, it } from 'vitest';
import { childRow, placeUrl, subtreeUrl } from '../rows';
import { child, coreRow, mountScreen } from './fixture';

const PLACE =
	'https://example.org/wp-admin/edit.php?post_type=page&hierarchy_guard_view=tree&paged=2';
const context = ( overrides = {} ) => ( {
	level: 1,
	parent: 4,
	page: 1,
	position: 2,
	setsize: 3,
	place: PLACE,
	type: 'page',
	...overrides,
} );

describe( 'a loaded subpage’s row', () => {
	let template;

	beforeEach( () => {
		mountScreen( coreRow( { id: 4, title: 'Company', children: 3 } ) );
		template = document.getElementById( 'post-4' );
	} );

	it( 'has the list’s columns, in core’s cells, hidden ones hidden', () => {
		const row = childRow( template, child( 5, 'About' ), context() );

		expect( row.id ).toBe( 'post-5' );
		expect(
			Array.from( row.children ).map( ( cell ) => [
				cell.tagName,
				cell.className,
				cell.dataset.colname,
			] )
		).toEqual( [
			[
				'TH',
				'title column-title has-row-actions column-primary page-title',
				'Title',
			],
			[ 'TD', 'hierarchy_guard column-hierarchy_guard', 'Hierarchy' ],
			[ 'TD', 'author column-author', 'Author' ],
			[ 'TD', 'comments column-comments hidden', 'Comments' ],
			[ 'TD', 'date column-date', 'Date' ],
		] );
		expect( row.children[ 0 ].getAttribute( 'scope' ) ).toBe( 'row' );
		expect( row.children[ 2 ].textContent ).toBe( 'Priya' );
		expect( row.children[ 4 ].innerHTML ).toBe(
			'Published<br>2026/07/19 at 9:00 am'
		);
	} );

	it( 'carries Edit, Trash, View and View subpages, and the title links to Edit', () => {
		const row = childRow(
			template,
			child( 5, 'About', { children_count: 4 } ),
			context()
		);

		const title = row.querySelector( 'strong .row-title' );
		expect( title.getAttribute( 'href' ) ).toBe(
			'https://example.org/wp-admin/post.php?post=5&action=edit'
		);
		expect(
			Array.from( row.querySelectorAll( '.row-actions > span' ) ).map(
				( item ) => [
					item.className,
					item.textContent,
					item.firstChild.getAttribute( 'aria-label' ),
				]
			)
		).toEqual( [
			[ 'edit', 'Edit | ', 'Edit “About”' ],
			[ 'trash', 'Trash | ', 'Move “About” to the Trash' ],
			[ 'view', 'View | ', 'View “About”' ],
			[
				'hierarchy_guard_subpages',
				'View subpages',
				'View subpages of “About”',
			],
		] );
		expect(
			row
				.querySelector( '.hierarchy_guard_subpages a' )
				.getAttribute( 'href' )
		).toBe( subtreeUrl( 'page', 5 ) );
		expect( row.querySelector( '.trash a' ).className ).toBe(
			'submitdelete'
		);
		expect( row.querySelector( 'button.toggle-row' ).textContent ).toBe(
			'Show more details'
		);
	} );

	it( 'sends the trash back to the tree, opened at the parent, at the subpage’s page', () => {
		const row = childRow(
			template,
			child( 60, 'Late' ),
			context( { page: 2 } )
		);

		const href = new window.URL(
			row.querySelector( '.trash a' ).getAttribute( 'href' )
		);
		expect( href.searchParams.get( '_wpnonce' ) ).toBe( 'abc' );
		expect( href.searchParams.get( '_wp_http_referer' ) ).toBe(
			PLACE + '&hierarchy_guard_open=4&hierarchy_guard_children_page=2'
		);
	} );

	it( 'puts the subpage count in the Hierarchy column, with the row’s place', () => {
		const row = childRow(
			template,
			child( 5, 'About', {
				children_count: 1000,
				children_capped: true,
			} ),
			context( { position: null, setsize: null } )
		);

		const cell = row.querySelector( '.hierarchy-guard-cell' );
		expect( cell.textContent ).toBe( '1,000+ subpages' );
		expect( cell.querySelector( 'a' ).getAttribute( 'aria-label' ) ).toBe(
			'View the 1,000+ subpages of “About”'
		);
		expect( { ...cell.dataset } ).toEqual( {
			id: '5',
			children: '1001',
			status: 'publish',
			level: '1',
			parent: '4',
			page: '1',
		} );
	} );

	it( 'shows a draft’s status as text and a page the user may not edit as plain text', () => {
		const row = childRow(
			template,
			child( 7, 'Press', {
				status: 'draft',
				status_label: 'Draft',
				edit_link: null,
				view_link: null,
				trash_link: null,
				author: '',
			} ),
			context()
		);

		expect( row.querySelector( 'strong' ).textContent ).toBe(
			'Press — Draft'
		);
		expect( row.querySelector( '.row-title' ) ).toBe( null );
		expect( row.querySelector( '.row-actions' ) ).toBe( null );
		expect( row.children[ 1 ].textContent ).toBe( '—No subpages' );
		expect( row.children[ 2 ].textContent ).toBe( '—(no author)' );
	} );

	it( 'shows a title with markup or entities as text', () => {
		const title = '<img src=x onerror=alert(1)> Tom & “Jerry”';
		const row = childRow(
			template,
			child( 8, title, { children_count: 2 } ),
			context()
		);

		expect( row.querySelector( '.row-title' ).textContent ).toBe( title );
		expect( row.querySelector( 'img' ) ).toBe( null );
		expect(
			row.querySelector( '.edit a' ).getAttribute( 'aria-label' )
		).toBe( `Edit “${ title }”` );
	} );

	it( 'names the row by its title as WordPress 7.1 does, and says whose child it is', () => {
		const row = childRow(
			template,
			child( 5, 'About' ),
			context( { parentTitle: 'Company' } )
		);

		expect( row.children[ 0 ].getAttribute( 'aria-label' ) ).toBe(
			'About'
		);
		const title = row.querySelector( '.row-title' );
		expect( title.getAttribute( 'aria-describedby' ) ).toBe(
			'post-hierarchy-5'
		);
		const description = row.querySelector( '#post-hierarchy-5' );
		expect( description.className ).toBe( 'hidden' );
		expect( description.textContent ).toBe( 'Child of Company' );

		template.children[ 0 ].removeAttribute( 'aria-label' );
		expect(
			childRow(
				template,
				child( 6, 'History' ),
				context()
			).children[ 0 ].hasAttribute( 'aria-label' )
		).toBe( false );
	} );

	it( 'carries core’s lock notice, and who holds the lock', () => {
		const free = childRow( template, child( 5, 'About' ), context() );
		expect( free.querySelector( '.locked-info' ).innerHTML ).toBe(
			'<span class="locked-avatar"></span> <span class="locked-text"></span>'
		);
		expect( free.classList.contains( 'wp-locked' ) ).toBe( false );

		const held = childRow(
			template,
			child( 6, 'History', { locked_by: 'Priya' } ),
			context()
		);
		expect( held.classList.contains( 'wp-locked' ) ).toBe( true );
		expect( held.querySelector( '.locked-text' ).textContent ).toBe(
			'Priya is currently editing'
		);
	} );

	it( 'lists core’s post states after the title', () => {
		const row = childRow(
			template,
			child( 3, 'Privacy', {
				status: 'draft',
				states: [ 'Draft', 'Privacy Policy Page' ],
			} ),
			context()
		);

		expect(
			Array.from( row.querySelectorAll( 'strong .post-state' ) ).map(
				( state ) => state.textContent
			)
		).toEqual( [ 'Draft, ', 'Privacy Policy Page' ] );
		expect( row.querySelector( 'strong' ).textContent ).toBe(
			'Privacy — Draft, Privacy Policy Page'
		);
	} );

	it( 'shows a missed schedule in core’s error style', () => {
		const row = childRow(
			template,
			child( 9, 'Launch', {
				status: 'future',
				date: {
					label: 'Missed schedule',
					date: '2026/01/01 at 9:00 am',
					missed: true,
				},
			} ),
			context()
		);

		expect( row.children[ 4 ].innerHTML ).toBe(
			'<strong class="error-message">Missed schedule</strong><br>2026/01/01 at 9:00 am'
		);
	} );

	it( 'keeps the tree’s place when nothing is opened', () => {
		expect( placeUrl( PLACE, 0 ) ).toBe( PLACE );
		expect( placeUrl( PLACE, 9 ) ).toBe(
			PLACE + '&hierarchy_guard_open=9'
		);
	} );
} );
```

In `src/common/test/copy.test.js`, replace (1 of 2):

```js
	unresolvedSentence,
	viewAllLabel,
	viewRedirectsLabel,
```

with:

```js
	treeRowLabels,
	unresolvedSentence,
	viewAllLabel,
	viewRedirectsLabel,
	viewSubpagesLabel,
```

In `src/common/test/copy.test.js`, replace (2 of 2):

```js
			provider: null,
		} );
	} );
} );
```

with:

```js
			provider: null,
		} );
	} );
} );

describe( 'the Tree view', () => {
	it( 'words a loaded row’s actions as core does', () => {
		const labels = treeRowLabels( 'About' );
		expect( [
			labels.edit,
			labels.editLabel,
			labels.trash,
			labels.trashLabel,
			labels.view,
			labels.viewLabel,
			labels.subpages,
			labels.subpagesLabel,
		] ).toEqual( [
			'Edit',
			'Edit “About”',
			'Trash',
			'Move “About” to the Trash',
			'View',
			'View “About”',
			'View subpages',
			'View subpages of “About”',
		] );
		expect( viewSubpagesLabel( 1, false, 'About' ) ).toBe(
			'View the 1 subpage of “About”'
		);
	} );
} );
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `npm run test:js`
Expected: FAIL. `Error: Failed to resolve import "../rows" from "src/tree/test/rows.test.js".` and `TypeError: treeRowLabels is not a function`.

- [ ] **Step 4: Write the row builder and its labels**

In `src/common/copy.js`, replace:

```js
	return pairs.map( ( pair ) => pair.old + ' ' + pair.new ).join( '\n' );
}
```

with:

```js
	return pairs.map( ( pair ) => pair.old + ' ' + pair.new ).join( '\n' );
}

/**
 * The row actions and labels of a subpage the Tree view loaded, as core
 * words them for the rows it prints.
 *
 * @param {string} title Page title.
 * @return {Object<string,string>} Labels.
 */
export function treeRowLabels( title ) {
	return {
		edit: __( 'Edit', 'hierarchy-guard' ),
		editLabel: sprintf(
			/* translators: %s: page title. */
			__( 'Edit “%s”', 'hierarchy-guard' ),
			title
		),
		trash: __( 'Trash', 'hierarchy-guard' ),
		trashLabel: sprintf(
			/* translators: %s: page title. */
			__( 'Move “%s” to the Trash', 'hierarchy-guard' ),
			title
		),
		view: __( 'View', 'hierarchy-guard' ),
		viewLabel: sprintf(
			/* translators: %s: page title. */
			__( 'View “%s”', 'hierarchy-guard' ),
			title
		),
		subpages: __( 'View subpages', 'hierarchy-guard' ),
		subpagesLabel: sprintf(
			/* translators: %s: page title. */
			__( 'View subpages of “%s”', 'hierarchy-guard' ),
			title
		),
		details: __( 'Show more details', 'hierarchy-guard' ),
		noSubpages: __( 'No subpages', 'hierarchy-guard' ),
		noAuthor: __( '(no author)', 'hierarchy-guard' ),
	};
}

/**
 * "View the 3 subpages of “About”", the label of a row's count link.
 *
 * @param {number}  count  Subpages.
 * @param {boolean} capped Whether more exist.
 * @param {string}  title  Page title.
 * @return {string} Label.
 */
export function viewSubpagesLabel( count, capped, title ) {
	return sprintf(
		/* translators: 1: number of subpages, such as 3 or 1,000+, 2: page title. */
		_n(
			'View the %1$s subpage of “%2$s”',
			'View the %1$s subpages of “%2$s”',
			count,
			'hierarchy-guard'
		),
		formatCount( count, capped ),
		title
	);
}

/**
 * A loaded row's description, as core gives its own rows: "Child of About".
 *
 * @param {string} title The parent page's title.
 * @return {string} Description.
 */
export function childOfLabel( title ) {
	return sprintf(
		/* translators: %s: parent page title. */
		__( 'Child of %s', 'hierarchy-guard' ),
		title
	);
}

/**
 * Core's lock notice on a row: "Priya is currently editing".
 *
 * @param {string} name The display name of the user holding the lock.
 * @return {string} Notice.
 */
export function lockedLabel( name ) {
	return sprintf(
		/* translators: %s: user's display name. */
		__( '%s is currently editing', 'hierarchy-guard' ),
		name
	);
}
```

Create `src/tree/rows.js`:
```js
/**
 * The rows the Tree view adds to core's table: a subpage the hierarchy route
 * loaded, with the list's columns, and the status rows under an opened page.
 */
import { addQueryArgs } from '@wordpress/url';
import {
	childOfLabel,
	lockedLabel,
	subpagesLabel,
	treeRowLabels,
	viewSubpagesLabel,
} from '../common/copy';

export const PER_PAGE = 50;

/**
 * An element with a class and text.
 *
 * @param {Document} doc       The document.
 * @param {string}   tag       Tag name.
 * @param {string}   className Class, or ''.
 * @param {string}   text      Text, or ''.
 * @return {HTMLElement} Element.
 */
function el( doc, tag, className = '', text = '' ) {
	const node = doc.createElement( tag );
	if ( className ) {
		node.className = className;
	}
	if ( text ) {
		node.textContent = text;
	}
	return node;
}

/**
 * A link.
 *
 * @param {Document} doc   The document.
 * @param {string}   href  URL.
 * @param {string}   text  Text.
 * @param {string}   label Accessible name, or ''.
 * @return {HTMLAnchorElement} Link.
 */
function link( doc, href, text, label = '' ) {
	const node = el( doc, 'a', '', text );
	node.href = href;
	if ( label ) {
		node.setAttribute( 'aria-label', label );
	}
	return node;
}

/**
 * The tree's place to come back to after a trash: the view at its page and
 * sort, opened at a page, at the page of its subpages a row was on.
 *
 * @param {string} place  The view's URL at its page and sort.
 * @param {number} parent Page to open, or 0.
 * @param {number} page   Page of its subpages, from 1.
 * @return {string} URL.
 */
export function placeUrl( place, parent, page = 1 ) {
	if ( ! parent ) {
		return place;
	}
	return addQueryArgs( place, {
		hierarchy_guard_open: parent,
		...( page > 1 ? { hierarchy_guard_children_page: page } : {} ),
	} );
}

/**
 * The list filtered to a page's subtree, as "View subpages" links it.
 *
 * @param {string} type Post type.
 * @param {number} id   Page ID.
 * @return {string} URL.
 */
export function subtreeUrl( type, id ) {
	return addQueryArgs( 'edit.php', {
		post_type: type,
		hierarchy_guard_under: id,
	} );
}

/**
 * Core's row actions, "Edit | Trash | View | View subpages".
 *
 * @param {Document}                doc     The document.
 * @param {Array<[string,Element]>} actions Action class and link.
 * @return {HTMLElement} The row actions.
 */
function rowActions( doc, actions ) {
	const box = el( doc, 'div', 'row-actions' );
	actions.forEach( ( [ name, node ], index ) => {
		const item = el( doc, 'span', name );
		item.appendChild( node );
		if ( index < actions.length - 1 ) {
			item.appendChild( doc.createTextNode( ' | ' ) );
		}
		box.appendChild( item );
	} );
	return box;
}

/**
 * The title cell's content as core prints it: the edit lock, the title with
 * its "Child of" description and its post states, and the row actions.
 *
 * @param {Document} doc     The document.
 * @param {Object}   child   The subpage from the route.
 * @param {Object}   context Where it goes.
 * @return {DocumentFragment} Content.
 */
function titleContent( doc, child, context ) {
	const labels = treeRowLabels( child.title );
	const out = doc.createDocumentFragment();
	const described = `post-hierarchy-${ child.id }`;
	if ( child.edit_link ) {
		// Core's lock notice, which its heartbeat fills when another user starts editing.
		const lock = el( doc, 'div', 'locked-info' );
		lock.appendChild( el( doc, 'span', 'locked-avatar' ) );
		lock.appendChild( doc.createTextNode( ' ' ) );
		lock.appendChild(
			el(
				doc,
				'span',
				'locked-text',
				child.locked_by ? lockedLabel( child.locked_by ) : ''
			)
		);
		out.appendChild( lock );
	}
	const strong = el( doc, 'strong' );
	if ( child.edit_link ) {
		const title = link( doc, child.edit_link, child.title );
		title.className = 'row-title';
		strong.appendChild( title );
		if ( context.parentTitle ) {
			title.setAttribute( 'aria-describedby', described );
			const description = el(
				doc,
				'span',
				'hidden',
				childOfLabel( context.parentTitle )
			);
			description.id = described;
			strong.appendChild( description );
		}
	} else {
		const title = el( doc, 'span', '', child.title );
		if ( context.parentTitle ) {
			const description = el(
				doc,
				'span',
				'screen-reader-text',
				` (${ childOfLabel( context.parentTitle ) })`
			);
			description.id = described;
			title.appendChild( description );
		}
		strong.appendChild( title );
	}
	const states =
		child.states ||
		( 'publish' !== child.status ? [ child.status_label ] : [] );
	if ( states.length ) {
		strong.appendChild( doc.createTextNode( ' — ' ) );
		states.forEach( ( state, index ) =>
			strong.appendChild(
				el(
					doc,
					'span',
					'post-state',
					state + ( index < states.length - 1 ? ', ' : '' )
				)
			)
		);
	}
	out.appendChild( strong );

	const actions = [];
	if ( child.edit_link ) {
		actions.push( [
			'edit',
			link( doc, child.edit_link, labels.edit, labels.editLabel ),
		] );
	}
	if ( child.trash_link ) {
		const trash = link(
			doc,
			addQueryArgs( child.trash_link, {
				_wp_http_referer: placeUrl(
					context.place,
					context.parent,
					context.page
				),
			} ),
			labels.trash,
			labels.trashLabel
		);
		trash.className = 'submitdelete';
		actions.push( [ 'trash', trash ] );
	}
	if ( child.view_link ) {
		const view = link(
			doc,
			child.view_link,
			labels.view,
			labels.viewLabel
		);
		view.rel = 'bookmark';
		actions.push( [ 'view', view ] );
	}
	if ( child.children_count > 0 ) {
		actions.push( [
			'hierarchy_guard_subpages',
			link(
				doc,
				subtreeUrl( context.type, child.id ),
				labels.subpages,
				labels.subpagesLabel
			),
		] );
	}
	if ( actions.length ) {
		out.appendChild( rowActions( doc, actions ) );
	}

	const details = el( doc, 'button', 'toggle-row' );
	details.type = 'button';
	details.appendChild(
		el( doc, 'span', 'screen-reader-text', labels.details )
	);
	out.appendChild( details );
	return out;
}

/**
 * The Hierarchy cell: the subpage count as a link to the subtree, with the
 * row's place in the tree for the script, as the server prints it.
 *
 * @param {Document} doc     The document.
 * @param {Object}   child   The subpage from the route.
 * @param {Object}   context Where it goes.
 * @return {HTMLElement} Cell content.
 */
function hierarchyContent( doc, child, context ) {
	const labels = treeRowLabels( child.title );
	const cell = el( doc, 'span', 'hierarchy-guard-cell' );
	const count = child.children_count;
	const data = {
		id: child.id,
		children: child.children_capped ? count + 1 : count,
		status: child.status,
		level: context.level,
		parent: context.parent,
		page: context.page,
	};
	if ( null !== context.position ) {
		data.position = context.position;
	}
	if ( null !== context.setsize ) {
		data.setsize = context.setsize;
	}
	Object.entries( data ).forEach( ( [ key, value ] ) => {
		cell.dataset[ key ] = String( value );
	} );
	if ( count > 0 ) {
		cell.appendChild(
			link(
				doc,
				subtreeUrl( context.type, child.id ),
				subpagesLabel( count, child.children_capped ),
				viewSubpagesLabel( count, child.children_capped, child.title )
			)
		);
	} else {
		const dash = el( doc, 'span', '', '—' );
		dash.setAttribute( 'aria-hidden', 'true' );
		cell.appendChild( dash );
		cell.appendChild(
			el( doc, 'span', 'screen-reader-text', labels.noSubpages )
		);
	}
	return cell;
}

/**
 * Fills one cell of a loaded row.
 *
 * @param {Document}    doc     The document.
 * @param {HTMLElement} cell    The new cell.
 * @param {string}      column  Column name.
 * @param {Object}      child   The subpage from the route.
 * @param {Object}      context Where it goes.
 */
function fill( doc, cell, column, child, context ) {
	const labels = treeRowLabels( child.title );
	if ( 'title' === column ) {
		cell.appendChild( titleContent( doc, child, context ) );
	} else if ( 'hierarchy_guard' === column ) {
		cell.appendChild( hierarchyContent( doc, child, context ) );
	} else if ( 'author' === column ) {
		if ( child.author ) {
			cell.textContent = child.author;
		} else {
			const dash = el( doc, 'span', '', '—' );
			dash.setAttribute( 'aria-hidden', 'true' );
			cell.appendChild( dash );
			cell.appendChild(
				el( doc, 'span', 'screen-reader-text', labels.noAuthor )
			);
		}
	} else if ( 'date' === column && child.date ) {
		cell.appendChild(
			child.date.missed
				? el( doc, 'strong', 'error-message', child.date.label )
				: doc.createTextNode( child.date.label )
		);
		cell.appendChild( el( doc, 'br' ) );
		cell.appendChild( doc.createTextNode( child.date.date ) );
	}
}

/**
 * A row for a subpage the route loaded. Its cells copy the columns of a row
 * core printed, so hidden columns stay hidden and another plugin's column
 * keeps its place, empty.
 *
 * @param {HTMLTableRowElement} template A row core printed.
 * @param {Object}              child    The subpage from the route.
 * @param {Object}              context  Where it goes: level (from 0), parent, parentTitle, page, position and setsize (or null), place, type.
 * @return {HTMLTableRowElement} Row.
 */
export function childRow( template, child, context ) {
	const doc = template.ownerDocument;
	const row = el(
		doc,
		'tr',
		`level-${ context.level } status-${ child.status } hierarchy-guard-loaded` +
			( child.locked_by ? ' wp-locked' : '' )
	);
	row.id = `post-${ child.id }`;
	Array.from( template.children ).forEach( ( source ) => {
		const cell = doc.createElement( source.tagName.toLowerCase() );
		cell.className = source.className;
		if ( source.hasAttribute( 'scope' ) ) {
			cell.setAttribute( 'scope', source.getAttribute( 'scope' ) );
		}
		if ( source.dataset.colname ) {
			cell.dataset.colname = source.dataset.colname;
		}
		const column = Array.from( source.classList )
			.find( ( name ) => name.startsWith( 'column-' ) )
			?.slice( 'column-'.length );
		if ( 'title' === column && source.hasAttribute( 'aria-label' ) ) {
			// WordPress 7.1 names the row by its title alone.
			cell.setAttribute( 'aria-label', child.title );
		}
		fill( doc, cell, column || '', child, context );
		row.appendChild( cell );
	} );
	return row;
}

/**
 * A row under an opened page that is not a page: "Show more", the cap, a
 * load in progress or a failure. It spans the table and sits at the level
 * of the subpages it is about.
 *
 * @param {HTMLTableRowElement} template A row core printed.
 * @param {number}              parent   The opened page.
 * @param {number}              level    Level of its subpages, from 0.
 * @return {{row: HTMLTableRowElement, cell: HTMLTableCellElement}} The row and its one cell.
 */
export function statusRow( template, parent, level ) {
	const doc = template.ownerDocument;
	const row = el( doc, 'tr', `level-${ level } hierarchy-guard-status` );
	row.dataset.parent = String( parent );
	const cell = el( doc, 'td', 'colspanchange' );
	cell.colSpan = Array.from( template.children ).filter(
		( source ) => ! source.classList.contains( 'hidden' )
	).length;
	row.appendChild( cell );
	return { row, cell };
}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `npm run test:js`, then `npm run lint:js`
Expected: 99 tests pass; lint exits 0 (`npx wp-scripts lint-js src --fix` first if Prettier reports formatting).

- [ ] **Step 6: Commit**

```bash
git add package.json package-lock.json src/common/copy.js src/common/test/copy.test.js src/tree/rows.js src/tree/test/fixture.js src/tree/test/rows.test.js
git commit -m "feat: a subpage the tree loads gets a row with the list's columns and actions"
```

### Task 7: The tree grid: toggles, arrow keys, Show more and Collapse all

**Files:**
- Modify: `src/common/copy.js` (`treeToggleLabel()`, `treeShownLine()`, `treeCappedSentence()`)
- Create: `src/tree/tree.js`, `src/tree/index.js`
- Modify: `src/list-table/index.js` (calls `bindTree()`), `src/list-table/list-table.scss` (the indent of titles and status rows, the toggle, the phone step)
- Test: `src/tree/test/tree.test.js`, `src/common/test/copy.test.js`

**Interfaces:**
- Consumes: `childRow()`, `statusRow()`, `PER_PAGE` (Task 6); `fetchHierarchy( id, page, perPage )` (Task 1); the markup of Task 4; the list's trash dialog, which `src/list-table/index.js` already opens for any `#the-list .row-actions .trash a` whose row's `.hierarchy-guard-cell` has `data-children` above 0.
- Produces (copy): `treeToggleLabel( open: boolean, title: string ): string`, `treeShownLine( shown: number, total: number, capped = false ): string`, `treeCappedSentence( cap: number ): string`.
- Produces (tree): `createTree( { body, data, cap, fetchChildren } ): {expand, collapse, toggle, collapseAll, load}|null`; `levelOf( row ): number` (from 1), `descendantsOf( row ): HTMLTableRowElement[]`, `parentOf( row ): HTMLTableRowElement|null`. `toggle()` and `expand()` return a promise that settles when the load does. Show more keeps its row while it loads (`aria-busy`, the button `aria-disabled`) and gives the keyboard to the first new row; a failed load turns its status row into `.hierarchy-guard-failed` with "The subpages couldn’t be loaded." and a "Try again" button, and gives the keyboard back to the opened row's toggle; closing rows hands the tab stop to the closed row, and Collapse all leaves one; "N of M subpages shown" is spoken only when some are held back; the closed arrow follows `isRTL()`; Up, Down, Left, Home and End work from a status row's button too.
- Produces (index): `bindTree( doc = window.document, loadKin = ( id, page ) => fetchHierarchy( id, page, 50 ) ): Object|null`, which also moves "Back to the tree" into core's search subtitle and turns `a.hierarchy-guard-collapse` into a `button-link`.

- [ ] **Step 1: Write the failing tests**

Create `src/tree/test/tree.test.js`:
```js
// @vitest-environment happy-dom
/**
 * The tree grid on core's list table.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { speak } from '@wordpress/a11y';
import { setLocaleData } from '@wordpress/i18n';
import bindTree from '..';
import { child, coreRow, mountScreen, page } from './fixture';

const rows = () => Array.from( document.querySelectorAll( '#the-list > tr' ) );
const row = ( id ) => document.getElementById( `post-${ id }` );
const press = ( target, key ) =>
	target.dispatchEvent(
		new window.KeyboardEvent( 'keydown', { key, bubbles: true } )
	);
const settle = () => new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
const tabStops = () =>
	rows()
		.filter( ( item ) => 0 === item.tabIndex )
		.map( ( item ) => item.id );

vi.mock( '@wordpress/a11y', () => ( { speak: vi.fn() } ) );

/**
 * Company (3 subpages), Contact, Locations (128 subpages) on page 2 of the top level.
 *
 * @return {string} Markup.
 */
const topLevel = () =>
	coreRow( { id: 4, title: 'Company', children: 3, position: 21 } ) +
	coreRow( { id: 8, title: 'Contact', position: 22 } ) +
	coreRow( { id: 9, title: 'Locations', children: 128, position: 23 } );

describe( 'the tree grid', () => {
	let fetchChildren;

	beforeEach( () => {
		fetchChildren = vi.fn( ( id, number ) =>
			Promise.resolve(
				4 === id
					? page(
							[
								child( 5, 'About', { children_count: 4 } ),
								child( 6, 'History' ),
								child( 7, 'Press', {
									status: 'draft',
									status_label: 'Draft',
									view_link: null,
								} ),
							],
							3
						)
					: page(
							Array.from( { length: 50 }, ( unused, index ) =>
								child(
									1000 + ( number - 1 ) * 50 + index,
									`City ${ ( number - 1 ) * 50 + index + 1 }`
								)
							),
							128,
							number < 3
						)
			)
		);
		mountScreen( topLevel() );
	} );

	it( 'puts tree grid roles on core’s table and rows', () => {
		bindTree( document, fetchChildren );

		expect( document.querySelector( 'table' ).getAttribute( 'role' ) ).toBe(
			'treegrid'
		);
		expect(
			rows().map( ( item ) => [
				item.getAttribute( 'aria-level' ),
				item.getAttribute( 'aria-expanded' ),
				item.getAttribute( 'aria-posinset' ),
				item.getAttribute( 'aria-setsize' ),
			] )
		).toEqual( [
			[ '1', 'false', '21', '3' ],
			[ '1', null, '22', '3' ],
			[ '1', 'false', '23', '3' ],
		] );
		expect( rows().map( ( item ) => item.tabIndex ) ).toEqual( [
			0, -1, -1,
		] );
	} );

	it( 'turns the toggle links into core button-link buttons before the title', () => {
		bindTree( document, fetchChildren );

		const button = row( 4 ).querySelector(
			'.column-title strong'
		).firstElementChild;
		expect( button.tagName ).toBe( 'BUTTON' );
		expect( button.className ).toBe( 'button-link hierarchy-guard-toggle' );
		expect( button.getAttribute( 'aria-label' ) ).toBe(
			'Show the subpages of “Company”'
		);
		expect(
			row( 4 ).querySelector( '.hierarchy-guard-cell' ).textContent
		).toBe( '3 subpages' );
		expect(
			document.querySelector( 'button.hierarchy-guard-collapse' )
				.className
		).toBe( 'button-link hierarchy-guard-collapse' );
	} );

	it( 'opens a row with its subpages one level down, 50 at a time', async () => {
		const tree = bindTree( document, fetchChildren );

		await tree.toggle( row( 4 ) );

		expect( fetchChildren ).toHaveBeenCalledWith( 4, 1 );
		expect( rows().map( ( item ) => item.id ) ).toEqual( [
			'post-4',
			'post-5',
			'post-6',
			'post-7',
			'post-8',
			'post-9',
		] );
		expect( row( 4 ).getAttribute( 'aria-expanded' ) ).toBe( 'true' );
		expect(
			row( 4 )
				.querySelector( '.hierarchy-guard-toggle' )
				.getAttribute( 'aria-label' )
		).toBe( 'Hide the subpages of “Company”' );
		expect(
			[ 5, 6, 7 ].map( ( id ) => [
				row( id ).getAttribute( 'aria-level' ),
				row( id ).getAttribute( 'aria-posinset' ),
				row( id ).getAttribute( 'aria-setsize' ),
				row( id ).className,
			] )
		).toEqual( [
			[ '2', '1', '3', 'level-1 status-publish hierarchy-guard-loaded' ],
			[ '2', '2', '3', 'level-1 status-publish hierarchy-guard-loaded' ],
			[ '2', '3', '3', 'level-1 status-draft hierarchy-guard-loaded' ],
		] );
		expect( row( 5 ).getAttribute( 'aria-expanded' ) ).toBe( 'false' );
		expect( row( 6 ).hasAttribute( 'aria-expanded' ) ).toBe( false );
	} );

	it( 'closes a row without a request', async () => {
		const tree = bindTree( document, fetchChildren );
		await tree.toggle( row( 4 ) );

		tree.toggle( row( 4 ) );

		expect( rows().map( ( item ) => item.id ) ).toEqual( [
			'post-4',
			'post-8',
			'post-9',
		] );
		expect( row( 4 ).getAttribute( 'aria-expanded' ) ).toBe( 'false' );
		expect( fetchChildren ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'shows more, 50 at a time, and says how many are shown', async () => {
		const tree = bindTree( document, fetchChildren );
		await tree.toggle( row( 9 ) );

		const more = document.querySelector( '.hierarchy-guard-more' );
		expect( more.textContent ).toBe(
			'Show more · 50 of 128 subpages shown'
		);
		expect( more.getAttribute( 'aria-level' ) ).toBe( '2' );
		expect( more.firstElementChild.colSpan ).toBe( 4 );

		more.querySelector( 'button' ).click();
		await settle();

		expect( fetchChildren ).toHaveBeenLastCalledWith( 9, 2 );
		expect( row( 1050 ).getAttribute( 'aria-posinset' ) ).toBe( '51' );
		expect( document.activeElement ).toBe( row( 1050 ) );
		expect(
			document.querySelector( '.hierarchy-guard-more' ).textContent
		).toBe( 'Show more · 100 of 128 subpages shown' );
		expect( rows().pop().id ).toBe( '' );
	} );

	it( 'says past the cap that only the first are shown', async () => {
		fetchChildren = vi.fn( () =>
			Promise.resolve(
				page( [ child( 30, 'Last' ) ], 1000, false, true )
			)
		);
		const tree = bindTree( document, fetchChildren );

		await tree.toggle( row( 9 ) );

		expect( row( 30 ).hasAttribute( 'aria-setsize' ) ).toBe( false );
		expect( row( 30 ).hasAttribute( 'aria-posinset' ) ).toBe( false );
		expect(
			document.querySelector( '.hierarchy-guard-capped' ).textContent
		).toBe( 'Only the first 1,000 subpages are shown.' );
	} );

	it( 'collapses every open row without a request', async () => {
		const tree = bindTree( document, fetchChildren );
		await tree.toggle( row( 4 ) );
		await tree.toggle( row( 5 ) );
		await tree.toggle( row( 9 ) );
		fetchChildren.mockClear();

		document.querySelector( 'button.hierarchy-guard-collapse' ).click();

		expect( rows().map( ( item ) => item.id ) ).toEqual( [
			'post-4',
			'post-8',
			'post-9',
		] );
		expect(
			rows().map( ( item ) => item.getAttribute( 'aria-expanded' ) )
		).toEqual( [ 'false', null, 'false' ] );
		expect( fetchChildren ).not.toHaveBeenCalled();
	} );

	it( 'moves between rows with up and down', () => {
		bindTree( document, fetchChildren );
		row( 4 ).focus();

		press( row( 4 ), 'ArrowDown' );
		expect( document.activeElement ).toBe( row( 8 ) );
		expect( row( 8 ).tabIndex ).toBe( 0 );
		expect( row( 4 ).tabIndex ).toBe( -1 );

		press( row( 8 ), 'ArrowUp' );
		expect( document.activeElement ).toBe( row( 4 ) );
		press( row( 4 ), 'ArrowUp' );
		expect( document.activeElement ).toBe( row( 4 ) );
	} );

	it( 'opens a row with right, then moves into its first subpage', async () => {
		bindTree( document, fetchChildren );
		row( 4 ).focus();

		press( row( 4 ), 'ArrowRight' );
		await settle();
		expect( row( 4 ).getAttribute( 'aria-expanded' ) ).toBe( 'true' );
		expect( document.activeElement ).toBe( row( 4 ) );

		press( row( 4 ), 'ArrowRight' );
		expect( document.activeElement ).toBe( row( 5 ) );

		press( row( 6 ), 'ArrowRight' );
		expect( fetchChildren ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'moves to the parent with left, then closes it', async () => {
		bindTree( document, fetchChildren );
		row( 4 ).focus();
		press( row( 4 ), 'ArrowRight' );
		await settle();
		row( 6 ).focus();

		press( row( 6 ), 'ArrowLeft' );
		expect( document.activeElement ).toBe( row( 4 ) );

		press( row( 4 ), 'ArrowLeft' );
		expect( row( 4 ).getAttribute( 'aria-expanded' ) ).toBe( 'false' );
		expect( row( 6 ) ).toBe( null );
	} );

	it( 'leaves the keys of a link inside a row to the browser', () => {
		bindTree( document, fetchChildren );
		const link = row( 4 ).querySelector( '.row-title' );
		link.focus();

		press( link, 'ArrowDown' );

		expect( document.activeElement ).toBe( link );
	} );

	it( 'says when the subpages could not be loaded, and tries again without closing', async () => {
		const load = vi
			.fn()
			.mockRejectedValueOnce( new Error() )
			.mockResolvedValue( page( [ child( 5, 'About' ) ], 1 ) );
		const tree = bindTree( document, load );
		const toggleButton = row( 4 ).querySelector(
			'.hierarchy-guard-toggle'
		);
		toggleButton.focus();

		await tree.toggle( row( 4 ) );

		expect( rows()[ 1 ].textContent ).toBe(
			'The subpages couldn’t be loaded. Try again'
		);
		expect( rows()[ 1 ].getAttribute( 'aria-level' ) ).toBe( '2' );
		expect( document.activeElement ).toBe( toggleButton );
		expect( row( 4 ).getAttribute( 'aria-expanded' ) ).toBe( 'true' );

		rows()[ 1 ].querySelector( 'button' ).click();
		await settle();

		expect( rows().map( ( item ) => item.id ) ).toEqual( [
			'post-4',
			'post-5',
			'post-8',
			'post-9',
		] );
		expect( document.activeElement ).toBe( row( 5 ) );
	} );

	it( 'keeps the keyboard on Show more while it loads, and keeps the rows when it fails', async () => {
		const tree = bindTree( document, fetchChildren );
		await tree.toggle( row( 9 ) );
		let fail;
		fetchChildren.mockImplementationOnce(
			() =>
				new Promise( ( resolve, reject ) => {
					fail = reject;
				} )
		);
		const more = document.querySelector( '.hierarchy-guard-more button' );
		more.focus();

		more.click();

		expect( document.activeElement ).toBe( more );
		expect( more.getAttribute( 'aria-disabled' ) ).toBe( 'true' );
		expect(
			document
				.querySelector( '.hierarchy-guard-more' )
				.getAttribute( 'aria-busy' )
		).toBe( 'true' );

		fail( new Error() );
		await settle();

		expect(
			rows().filter( ( item ) => item.id.startsWith( 'post-10' ) )
		).toHaveLength( 50 );
		expect( document.activeElement ).toBe(
			row( 9 ).querySelector( '.hierarchy-guard-toggle' )
		);
		const failure = document.querySelector( '.hierarchy-guard-failed' );
		failure.querySelector( 'button' ).click();
		await settle();
		expect( fetchChildren ).toHaveBeenLastCalledWith( 9, 2 );
		expect( row( 1050 ) ).not.toBe( null );
		expect( document.querySelector( '.hierarchy-guard-failed' ) ).toBe(
			null
		);
	} );

	it( 'moves the tab stop to the closed row when its subpages go', async () => {
		const tree = bindTree( document, fetchChildren );
		await tree.toggle( row( 4 ) );
		await tree.toggle( row( 5 ) );
		row( 6 ).focus();
		row( 6 ).blur();
		expect( tabStops() ).toEqual( [ 'post-6' ] );

		tree.toggle( row( 4 ) );

		expect( tabStops() ).toEqual( [ 'post-4' ] );
	} );

	it( 'leaves a tab stop after Collapse all', async () => {
		const tree = bindTree( document, fetchChildren );
		await tree.toggle( row( 9 ) );
		row( 1003 ).focus();
		row( 1003 ).blur();

		tree.collapseAll();

		expect( tabStops() ).toEqual( [ 'post-9' ] );
	} );

	it( 'moves to the first and last rows with Home and End', () => {
		bindTree( document, fetchChildren );
		row( 8 ).focus();

		press( row( 8 ), 'End' );
		expect( document.activeElement ).toBe( row( 9 ) );

		press( row( 9 ), 'Home' );
		expect( document.activeElement ).toBe( row( 4 ) );
	} );

	it( 'moves between rows from the Show more button', async () => {
		const tree = bindTree( document, fetchChildren );
		await tree.toggle( row( 9 ) );
		const more = document.querySelector( '.hierarchy-guard-more button' );
		more.focus();

		press( more, 'ArrowUp' );
		expect( document.activeElement ).toBe( row( 1049 ) );

		document.querySelector( '.hierarchy-guard-more button' ).focus();
		press(
			document.querySelector( '.hierarchy-guard-more button' ),
			'ArrowLeft'
		);
		expect( document.activeElement ).toBe( row( 9 ) );
	} );

	it( 'says how many are shown only when some are held back', async () => {
		const tree = bindTree( document, fetchChildren );
		speak.mockClear();

		await tree.toggle( row( 4 ) );
		expect( speak ).not.toHaveBeenCalled();

		await tree.toggle( row( 9 ) );
		expect( speak ).toHaveBeenCalledWith(
			'50 of 128 subpages shown',
			'polite'
		);
	} );

	it( 'points a closed toggle toward the title right to left', () => {
		setLocaleData( { 'text direction\u0004ltr': [ 'rtl' ] } );
		try {
			bindTree( document, fetchChildren );

			expect(
				row( 4 ).querySelector( '.hierarchy-guard-toggle .dashicons' )
					.className
			).toBe( 'dashicons dashicons-arrow-left-alt2' );
		} finally {
			setLocaleData( { 'text direction\u0004ltr': [ 'ltr' ] } );
		}
	} );

	it( 'shows a row once whatever the clicks while it loads', async () => {
		let answer;
		const load = vi.fn(
			() =>
				new Promise( ( resolve ) => {
					answer = resolve;
				} )
		);
		const tree = bindTree( document, load );

		const opening = tree.toggle( row( 4 ) );
		tree.toggle( row( 4 ) );
		answer( page( [ child( 5, 'About' ) ], 1 ) );
		await opening;
		expect( rows().map( ( item ) => item.id ) ).toEqual( [
			'post-4',
			'post-8',
			'post-9',
		] );

		const again = tree.toggle( row( 4 ) );
		tree.toggle( row( 4 ) );
		const third = tree.toggle( row( 4 ) );
		answer( page( [ child( 5, 'About' ) ], 1 ) );
		await Promise.all( [ again, third ] );
		expect( rows().map( ( item ) => item.id ) ).toEqual( [
			'post-4',
			'post-5',
			'post-8',
			'post-9',
		] );
		expect( load ).toHaveBeenCalledTimes( 2 );
	} );

	it( 'gives a loaded page the user may not edit no toggle', async () => {
		const tree = bindTree( document, () =>
			Promise.resolve(
				page(
					[
						child( 5, 'About', {
							children_count: 4,
							edit_link: null,
						} ),
					],
					1
				)
			)
		);

		await tree.toggle( row( 4 ) );

		expect( row( 5 ).hasAttribute( 'aria-expanded' ) ).toBe( false );
		expect( row( 5 ).querySelector( '.hierarchy-guard-toggle' ) ).toBe(
			null
		);
		expect(
			row( 5 ).querySelector( '.hierarchy-guard-cell' ).textContent
		).toBe( '4 subpages' );
	} );

	it( 'still finds the toggles when the Hierarchy column is hidden', () => {
		document
			.querySelectorAll( '.column-hierarchy_guard' )
			.forEach( ( cell ) => cell.classList.add( 'hidden' ) );

		bindTree( document, fetchChildren );

		expect(
			row( 4 ).querySelector( '.column-title .hierarchy-guard-toggle' )
		).not.toBe( null );
		expect( row( 4 ).getAttribute( 'aria-expanded' ) ).toBe( 'false' );
	} );

	it( 'keeps the levels the server opened, with their Show more', () => {
		mountScreen(
			coreRow( {
				id: 9,
				title: 'Locations',
				children: 128,
				open: 1,
				extra: '<span aria-hidden="true"> · </span><a class="hierarchy-guard-more" href="edit.php?hierarchy_guard_children_page=2" data-page="2">Show more</a>',
			} ) +
				coreRow( {
					id: 11,
					title: 'Amsterdam',
					level: 1,
					parent: 9,
					setsize: 128,
				} ) +
				coreRow( { id: 8, title: 'Contact', position: 2 } )
		);
		const load = vi.fn( () => Promise.resolve( page( [], 128 ) ) );

		bindTree( document, load );

		expect( row( 9 ).getAttribute( 'aria-expanded' ) ).toBe( 'true' );
		expect( row( 11 ).getAttribute( 'aria-level' ) ).toBe( '2' );
		expect(
			row( 9 ).querySelector( '.hierarchy-guard-cell' ).textContent
		).toBe( '128 subpages' );
		const more = rows()[ 2 ];
		expect( more.textContent ).toBe(
			'Show more · 1 of 128 subpages shown'
		);
		more.querySelector( 'button' ).click();
		expect( load ).toHaveBeenCalledWith( 9, 2 );
	} );

	it( 'puts “Back to the tree” beside a search’s heading', () => {
		document.body.innerHTML =
			'<div class="wrap"><h1>Pages</h1><span class="subtitle">Search results for: <strong>team</strong></span><span class="hierarchy-guard-back"><a href="tree">Back to the tree</a></span></div>';

		expect( bindTree( document, fetchChildren ) ).toBe( null );

		expect( document.querySelector( '.subtitle' ).textContent ).toBe(
			'Search results for: team · Back to the tree'
		);
		expect( document.querySelector( '.hierarchy-guard-back' ) ).toBe(
			null
		);
	} );

	it( 'does nothing outside the Tree view', () => {
		document.body.innerHTML =
			'<table><tbody id="the-list"><tr id="post-4"><td>Company</td></tr></tbody></table>';

		expect( bindTree( document, fetchChildren ) ).toBe( null );
		expect( document.querySelector( 'table' ).hasAttribute( 'role' ) ).toBe(
			false
		);
	} );
} );
```

In `src/common/test/copy.test.js`, replace (1 of 2):

```js
	treeRowLabels,
```

with:

```js
	treeCappedSentence,
	treeRowLabels,
	treeShownLine,
	treeToggleLabel,
```

In `src/common/test/copy.test.js`, replace (2 of 2):

```js
describe( 'the Tree view', () => {
```

with:

```js
describe( 'the Tree view', () => {
	it( 'labels a toggle by what it does next', () => {
		expect( treeToggleLabel( false, 'About' ) ).toBe(
			'Show the subpages of “About”'
		);
		expect( treeToggleLabel( true, 'About' ) ).toBe(
			'Hide the subpages of “About”'
		);
	} );

	it( 'says how many subpages Show more has shown', () => {
		expect( treeShownLine( 50, 128 ) ).toBe( '50 of 128 subpages shown' );
		expect( treeShownLine( 1, 1 ) ).toBe( '1 of 1 subpage shown' );
		expect( treeShownLine( 950, 1000, true ) ).toBe(
			'950 of 1,000+ subpages shown'
		);
	} );

	it( 'says past the cap that only the first are shown', () => {
		expect( treeCappedSentence( 1000 ) ).toBe(
			'Only the first 1,000 subpages are shown.'
		);
	} );

```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npm run test:js`
Expected: FAIL. `Error: Failed to resolve import ".." from "src/tree/test/tree.test.js".` and `TypeError: treeToggleLabel is not a function` (and the other two sentences).

- [ ] **Step 3: Write the tree's sentences**

In `src/common/copy.js`, replace:

```js
	return pairs.map( ( pair ) => pair.old + ' ' + pair.new ).join( '\n' );
}

/**
```

with:

```js
	return pairs.map( ( pair ) => pair.old + ' ' + pair.new ).join( '\n' );
}

/**
 * The Tree view's toggle: "Show the subpages of “About”", or "Hide …" once open.
 *
 * @param {boolean} open  Whether the row is open.
 * @param {string}  title Page title.
 * @return {string} Label.
 */
export function treeToggleLabel( open, title ) {
	return open
		? sprintf(
				/* translators: %s: page title. */
				__( 'Hide the subpages of “%s”', 'hierarchy-guard' ),
				title
			)
		: sprintf(
				/* translators: %s: page title. */
				__( 'Show the subpages of “%s”', 'hierarchy-guard' ),
				title
			);
}

/**
 * The Tree view's "Show more" row: "50 of 128 subpages shown".
 *
 * @param {number}  shown  Subpages on the screen.
 * @param {number}  total  Subpages in all, at most the cap.
 * @param {boolean} capped Whether more than the cap exist.
 * @return {string} Line.
 */
export function treeShownLine( shown, total, capped = false ) {
	return sprintf(
		/* translators: 1: subpages on the screen, such as 50, 2: subpages in all, such as 128 or 1,000+. */
		_n(
			'%1$s of %2$s subpage shown',
			'%1$s of %2$s subpages shown',
			total,
			'hierarchy-guard'
		),
		formatCount( shown ),
		formatCount( total, capped )
	);
}

/**
 * The Tree view's last row past the cap: "Only the first 1,000 subpages are shown."
 *
 * @param {number} cap The most subpages the tree shows.
 * @return {string} Sentence.
 */
export function treeCappedSentence( cap ) {
	return sprintf(
		/* translators: %s: the most subpages the tree shows, such as 1,000. */
		_n(
			'Only the first %s subpage is shown.',
			'Only the first %s subpages are shown.',
			cap,
			'hierarchy-guard'
		),
		formatCount( cap )
	);
}

/**
```

- [ ] **Step 4: Write the tree grid**

Create `src/tree/tree.js`:
```js
/**
 * The Tree view on core's list table: tree grid roles and arrow keys on
 * core's own rows, the toggles as core button-link buttons, subpages 50 at
 * a time with "Show more", and "Collapse all", which needs no request.
 */
import { speak } from '@wordpress/a11y';
import { __, isRTL } from '@wordpress/i18n';
import {
	treeCappedSentence,
	treeShownLine,
	treeToggleLabel,
} from '../common/copy';
import { PER_PAGE, childRow, statusRow } from './rows';

const OPEN = 'dashicons-arrow-down-alt2';
const RIGHT = 'dashicons-arrow-right-alt2';
const LEFT = 'dashicons-arrow-left-alt2';

/**
 * The closed toggle's arrow, pointing along the line toward the title.
 *
 * @return {string} Dashicon class.
 */
const closed = () => ( isRTL() ? LEFT : RIGHT );

/**
 * A row's level, from 1.
 *
 * @param {HTMLTableRowElement} row Row.
 * @return {number} Level.
 */
export function levelOf( row ) {
	return Number( row.getAttribute( 'aria-level' ) || 1 );
}

/**
 * The page a row shows, or 0 for a status row.
 *
 * @param {HTMLTableRowElement} row Row.
 * @return {number} Post ID.
 */
function idOf( row ) {
	return row.id.startsWith( 'post-' ) ? Number( row.id.slice( 5 ) ) : 0;
}

/**
 * The rows under a row, down to the next row at its level or above.
 *
 * @param {HTMLTableRowElement} row Row.
 * @return {HTMLTableRowElement[]} Rows.
 */
export function descendantsOf( row ) {
	const level = levelOf( row );
	const out = [];
	for (
		let next = row.nextElementSibling;
		next && levelOf( next ) > level;
		next = next.nextElementSibling
	) {
		out.push( next );
	}
	return out;
}

/**
 * The row of a row's parent page, or null at the top level.
 *
 * @param {HTMLTableRowElement} row Row.
 * @return {HTMLTableRowElement|null} Row.
 */
export function parentOf( row ) {
	const level = levelOf( row );
	for (
		let prev = row.previousElementSibling;
		prev;
		prev = prev.previousElementSibling
	) {
		if ( levelOf( prev ) < level ) {
			return prev;
		}
	}
	return null;
}

/**
 * A row's title, as its title link reads.
 *
 * @param {HTMLTableRowElement} row Row.
 * @return {string} Title.
 */
function titleOf( row ) {
	const title = row.querySelector( '.row-title' );
	return title ? title.textContent.trim() : '';
}

/**
 * Sets up the Tree view on a list table.
 *
 * @param {Object}                                        args               Arguments.
 * @param {HTMLTableSectionElement}                       args.body          Core's #the-list.
 * @param {DOMStringMap}                                  args.data          The view's data: type and place.
 * @param {number}                                        args.cap           The count cap.
 * @param {(id: number, page: number) => Promise<Object>} args.fetchChildren Loads one page of a page's subpages.
 * @return {Object} The tree's actions, for the toggles, keys and tests.
 */
export function createTree( { body, data, cap, fetchChildren } ) {
	const doc = body.ownerDocument;
	const loading = new Set();
	let template = null;

	// Children rather than rows and cells, which not every DOM keeps live.
	const rows = () =>
		Array.from( body.children ).filter( ( node ) => 'TR' === node.tagName );
	const isTreeRow = ( row ) => idOf( row ) > 0;

	/**
	 * Makes a row the one Tab reaches.
	 *
	 * @param {HTMLTableRowElement} row Row.
	 */
	const setTabStop = ( row ) =>
		rows().forEach( ( other ) =>
			other.setAttribute( 'tabindex', other === row ? '0' : '-1' )
		);

	/**
	 * Moves the keyboard to a row, which becomes the one Tab reaches.
	 *
	 * @param {HTMLTableRowElement|null} row Row.
	 */
	const focus = ( row ) => {
		if ( ! row ) {
			return;
		}
		setTabStop( row );
		row.focus();
	};

	const setOpen = ( row, open ) => {
		row.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		const button = row.querySelector( 'button.hierarchy-guard-toggle' );
		if ( button ) {
			button.setAttribute(
				'aria-label',
				treeToggleLabel( open, button.dataset.title )
			);
			const icon = button.querySelector( '.dashicons' );
			icon.classList.remove( OPEN, RIGHT, LEFT );
			icon.classList.add( open ? OPEN : closed() );
		}
	};

	/**
	 * Puts a toggle button before a row's title.
	 *
	 * @param {HTMLTableRowElement} row   Row.
	 * @param {string}              title Page title.
	 * @param {boolean}             open  Whether the row is open.
	 */
	const addToggle = ( row, title, open ) => {
		const cell = row.querySelector( '.column-title' );
		if ( ! cell ) {
			return;
		}
		const button = doc.createElement( 'button' );
		button.type = 'button';
		button.className = 'button-link hierarchy-guard-toggle';
		button.dataset.title = title;
		const icon = doc.createElement( 'span' );
		icon.className = 'dashicons';
		icon.setAttribute( 'aria-hidden', 'true' );
		button.appendChild( icon );
		button.addEventListener( 'click', () => toggle( row ) );
		// Inside core's title line, a block, so the toggle sits on it.
		const heading = cell.querySelector( 'strong' ) || cell;
		heading.insertBefore( button, heading.firstChild );
		setOpen( row, open );
	};

	/**
	 * The direct subpages of a row on the screen.
	 *
	 * @param {HTMLTableRowElement} row Row.
	 * @return {number} Count.
	 */
	const shownUnder = ( row ) =>
		descendantsOf( row ).filter(
			( other ) =>
				isTreeRow( other ) && levelOf( other ) === levelOf( row ) + 1
		).length;

	/**
	 * The row after which a row's next subpages go.
	 *
	 * @param {HTMLTableRowElement} row Row.
	 * @return {HTMLTableRowElement} Row.
	 */
	const lastOf = ( row ) => {
		const under = descendantsOf( row );
		return under.length ? under[ under.length - 1 ] : row;
	};

	/**
	 * Adds a row under an opened row that is not a page.
	 *
	 * @param {HTMLTableRowElement} row The opened row.
	 * @return {{row: HTMLTableRowElement, cell: HTMLTableCellElement}} The status row.
	 */
	const addStatus = ( row ) => {
		const status = statusRow( template, idOf( row ), levelOf( row ) );
		status.row.setAttribute( 'aria-level', String( levelOf( row ) + 1 ) );
		status.row.setAttribute( 'tabindex', '-1' );
		lastOf( row ).after( status.row );
		return status;
	};

	/**
	 * "Show more" under an opened row, or the cap's sentence past it. While
	 * the next page loads the row stays, busy, so the keyboard stays on it.
	 *
	 * @param {HTMLTableRowElement} row     The opened row.
	 * @param {number}              next    The next page of its subpages.
	 * @param {number}              total   Its subpages, at most the cap.
	 * @param {boolean}             capped  Whether more than the cap exist.
	 * @param {boolean}             hasMore Whether a next page exists.
	 */
	const addMore = ( row, next, total, capped, hasMore ) => {
		if ( hasMore ) {
			const status = addStatus( row );
			status.row.classList.add( 'hierarchy-guard-more' );
			const button = doc.createElement( 'button' );
			button.type = 'button';
			button.className = 'button-link';
			button.textContent = __( 'Show more', 'hierarchy-guard' );
			button.addEventListener( 'click', () => {
				if ( loading.has( idOf( row ) ) ) {
					return;
				}
				button.setAttribute( 'aria-disabled', 'true' );
				load( row, next, true, status );
			} );
			status.cell.appendChild( button );
			const line = doc.createElement( 'span' );
			line.textContent =
				' · ' + treeShownLine( shownUnder( row ), total, capped );
			status.cell.appendChild( line );
		} else if ( capped ) {
			const { row: end, cell } = addStatus( row );
			end.classList.add( 'hierarchy-guard-capped' );
			cell.textContent = treeCappedSentence( cap );
		}
	};

	/**
	 * Says a load failed, in its status row, with a button to try again
	 * without closing the row, and gives the keyboard back to the toggle.
	 *
	 * @param {HTMLTableRowElement}                                    row    The opened row.
	 * @param {number}                                                 page   The page that failed.
	 * @param {{row: HTMLTableRowElement, cell: HTMLTableCellElement}} status Its status row.
	 */
	const failed = ( row, page, status ) => {
		const here =
			doc.activeElement === doc.body ||
			status.row.contains( doc.activeElement ) ||
			row.contains( doc.activeElement );
		status.row.removeAttribute( 'aria-busy' );
		status.row.classList.remove( 'hierarchy-guard-more' );
		status.row.classList.add( 'hierarchy-guard-failed' );
		status.cell.textContent = __(
			'The subpages couldn’t be loaded.',
			'hierarchy-guard'
		);
		status.cell.appendChild( doc.createTextNode( ' ' ) );
		const retry = doc.createElement( 'button' );
		retry.type = 'button';
		retry.className = 'button-link';
		retry.textContent = __( 'Try again', 'hierarchy-guard' );
		retry.addEventListener( 'click', () => {
			if ( ! loading.has( idOf( row ) ) ) {
				retry.setAttribute( 'aria-disabled', 'true' );
				load( row, page, true, status );
			}
		} );
		status.cell.appendChild( retry );
		speak(
			__( 'The subpages couldn’t be loaded.', 'hierarchy-guard' ),
			'assertive'
		);
		if ( here ) {
			setTabStop( row );
			(
				row.querySelector( 'button.hierarchy-guard-toggle' ) || row
			).focus();
		}
	};

	/**
	 * Gives a row its place in the tree grid.
	 *
	 * @param {HTMLTableRowElement} row Row.
	 */
	const place = ( row ) => {
		const cell = row.querySelector( '.hierarchy-guard-cell' );
		if ( ! cell ) {
			return;
		}
		const facts = cell.dataset;
		row.setAttribute(
			'aria-level',
			String( Number( facts.level || 0 ) + 1 )
		);
		if ( facts.setsize && facts.position ) {
			row.setAttribute( 'aria-setsize', facts.setsize );
			row.setAttribute( 'aria-posinset', facts.position );
		}
		row.setAttribute( 'tabindex', '-1' );
	};

	/**
	 * Loads one page of a row's subpages under it.
	 *
	 * @param {HTMLTableRowElement}                                         row       The opened row.
	 * @param {number}                                                      page      Page of its subpages, from 1.
	 * @param {boolean}                                                     moveFocus Whether the first new row takes the keyboard.
	 * @param {{row: HTMLTableRowElement, cell: HTMLTableCellElement}|null} reuse     The Show more or failure row to keep while it loads.
	 * @return {Promise<void>} Done.
	 */
	const load = async ( row, page, moveFocus = false, reuse = null ) => {
		const id = idOf( row );
		if ( loading.has( id ) ) {
			return;
		}
		loading.add( id );
		const status = reuse || addStatus( row );
		status.row.setAttribute( 'aria-busy', 'true' );
		if ( ! reuse ) {
			status.cell.textContent = __(
				'Loading subpages…',
				'hierarchy-guard'
			);
		}
		let result;
		try {
			result = await fetchChildren( id, page );
		} catch {
			if ( status.row.isConnected ) {
				failed( row, page, status );
			}
			return;
		} finally {
			loading.delete( id );
		}
		status.row.remove();
		if ( 'true' !== row.getAttribute( 'aria-expanded' ) ) {
			// Closed while it loaded.
			return;
		}

		let anchor = lastOf( row );
		let first = null;
		( result.children || [] ).forEach( ( child, index ) => {
			const node = childRow( template, child, {
				level: levelOf( row ),
				parent: id,
				parentTitle: titleOf( row ),
				page,
				position: result.children_capped
					? null
					: ( page - 1 ) * PER_PAGE + index + 1,
				setsize: result.children_capped ? null : result.children_total,
				place: data.place,
				type: data.type,
			} );
			place( node );
			if ( child.children_count > 0 && child.edit_link ) {
				addToggle( node, child.title, false );
			}
			anchor.after( node );
			anchor = node;
			first = first || node;
		} );
		addMore(
			row,
			page + 1,
			result.children_total,
			result.children_capped,
			result.has_more
		);
		if ( result.has_more || result.children_capped ) {
			// Said only when some are held back; a row opened in full needs no count.
			speak(
				treeShownLine(
					shownUnder( row ),
					result.children_total,
					result.children_capped
				),
				'polite'
			);
		}
		if ( moveFocus ) {
			focus( first || row );
		}
	};

	const expand = ( row ) => {
		if ( 'false' !== row.getAttribute( 'aria-expanded' ) ) {
			return Promise.resolve();
		}
		setOpen( row, true );
		return load( row, 1 );
	};

	const collapse = ( row ) => {
		if ( 'true' !== row.getAttribute( 'aria-expanded' ) ) {
			return;
		}
		const under = descendantsOf( row );
		const lost = under.some( ( other ) =>
			other.contains( doc.activeElement )
		);
		const held = under.some(
			( other ) => '0' === other.getAttribute( 'tabindex' )
		);
		under.forEach( ( other ) => other.remove() );
		setOpen( row, false );
		if ( lost ) {
			focus( row );
		} else if ( held ) {
			// The row that Tab reached is gone: the closed row takes it.
			setTabStop( row );
		}
	};

	const toggle = ( row ) =>
		'true' === row.getAttribute( 'aria-expanded' )
			? collapse( row )
			: expand( row );

	const collapseAll = () => {
		rows()
			.filter( ( row ) => 'true' === row.getAttribute( 'aria-expanded' ) )
			.forEach( ( row ) => {
				if ( row.isConnected ) {
					collapse( row );
				}
			} );
		if (
			! rows().some( ( row ) => '0' === row.getAttribute( 'tabindex' ) )
		) {
			setTabStop( rows()[ 0 ] );
		}
	};

	// The rows core printed, with the levels the server opened.
	rows().forEach( ( row ) => {
		place( row );
		template = template || ( isTreeRow( row ) ? row : null );
	} );
	if ( ! template ) {
		return null;
	}
	body.closest( 'table' ).setAttribute( 'role', 'treegrid' );

	rows().forEach( ( row ) => {
		const cell = row.querySelector( '.hierarchy-guard-cell' );
		const toggleLink =
			cell && cell.querySelector( 'a.hierarchy-guard-toggle' );
		if ( toggleLink ) {
			const separator = toggleLink.nextElementSibling;
			if ( separator && separator.matches( '[aria-hidden="true"]' ) ) {
				separator.remove();
			}
			toggleLink.remove();
			addToggle( row, titleOf( row ), !! cell.dataset.open );
		}
	} );
	// "Show more" and the cap's sentence, printed in an opened row's cell, become rows under it.
	rows()
		.filter( ( row ) => 'true' === row.getAttribute( 'aria-expanded' ) )
		.forEach( ( row ) => {
			const cell = row.querySelector( '.hierarchy-guard-cell' );
			const more = cell.querySelector(
				'a.hierarchy-guard-more, .hierarchy-guard-capped'
			);
			if ( ! more ) {
				return;
			}
			const separator = more.previousElementSibling;
			if ( separator && separator.matches( '[aria-hidden="true"]' ) ) {
				separator.remove();
			}
			more.remove();
			const total = Number( cell.dataset.children || 0 );
			addMore(
				row,
				Number( more.dataset.page || 0 ),
				Math.min( total, cap ),
				total > cap,
				more.matches( 'a' )
			);
		} );

	const first = rows()[ 0 ];
	if ( first ) {
		first.setAttribute( 'tabindex', '0' );
	}

	body.addEventListener( 'focusin', ( event ) => {
		const row = event.target.closest( 'tr' );
		if (
			row &&
			row.parentNode === body &&
			'0' !== row.getAttribute( 'tabindex' )
		) {
			setTabStop( row );
		}
	} );

	body.addEventListener( 'keydown', ( event ) => {
		const row = event.target.closest( 'tr' );
		if (
			! row ||
			row.parentNode !== body ||
			( event.target !== row &&
				! event.target.classList.contains( 'hierarchy-guard-toggle' ) &&
				! event.target.closest( '.hierarchy-guard-status' ) )
		) {
			return;
		}
		const expanded = row.getAttribute( 'aria-expanded' );
		switch ( event.key ) {
			case 'ArrowDown':
				focus( row.nextElementSibling );
				break;
			case 'ArrowUp':
				focus( row.previousElementSibling );
				break;
			case 'Home':
				focus( rows()[ 0 ] );
				break;
			case 'End':
				focus( rows()[ rows().length - 1 ] );
				break;
			case 'ArrowRight':
				if ( 'false' === expanded ) {
					expand( row );
				} else if (
					'true' === expanded &&
					row.nextElementSibling &&
					levelOf( row.nextElementSibling ) > levelOf( row )
				) {
					focus( row.nextElementSibling );
				}
				break;
			case 'ArrowLeft':
				if ( 'true' === expanded ) {
					collapse( row );
				} else {
					focus( parentOf( row ) );
				}
				break;
			default:
				return;
		}
		event.preventDefault();
	} );

	return { expand, collapse, toggle, collapseAll, load };
}
```

Create `src/tree/index.js`:
```js
/**
 * The Tree view's script, part of the list-table bundle: the tree grid on
 * core's table, "Collapse all", and "Back to the tree" beside a search's
 * heading.
 */
import { fetchHierarchy } from '../common/api';
import config from '../common/config';
import { PER_PAGE } from './rows';
import { createTree } from './tree';

/**
 * One page of a page's subpages, 50 at a time.
 *
 * @param {number} id   Page ID.
 * @param {number} page Page of its subpages, from 1.
 * @return {Promise<Object>} The page's hierarchy.
 */
const fetchChildren = ( id, page ) => fetchHierarchy( id, page, PER_PAGE );

/**
 * Sets up the Tree view, if the screen is one.
 *
 * @param {Document}                                      doc     The document.
 * @param {(id: number, page: number) => Promise<Object>} loadKin Loads one page of a page's subpages.
 * @return {Object|null} The tree's actions, or null.
 */
export default function bindTree(
	doc = window.document,
	loadKin = fetchChildren
) {
	// A search in the Tree view: "Back to the tree" goes beside core's "Search results for".
	const back = doc.querySelector( '.hierarchy-guard-back' );
	const subtitle = doc.querySelector( '.wrap .subtitle' );
	if ( back && subtitle ) {
		const separator = doc.createElement( 'span' );
		separator.setAttribute( 'aria-hidden', 'true' );
		separator.textContent = ' · ';
		subtitle.append( separator, ...back.childNodes );
		back.remove();
	}

	const data = doc.querySelector( '.hierarchy-guard-tree' );
	const body = doc.getElementById( 'the-list' );
	if ( ! data || ! body ) {
		return null;
	}
	const tree = createTree( {
		body,
		data: data.dataset,
		cap: config.cap,
		fetchChildren: loadKin,
	} );
	if ( ! tree ) {
		return null;
	}

	const collapse = doc.querySelector( 'a.hierarchy-guard-collapse' );
	if ( collapse ) {
		const button = doc.createElement( 'button' );
		button.type = 'button';
		button.className = 'button-link hierarchy-guard-collapse';
		button.textContent = collapse.textContent;
		button.addEventListener( 'click', () => tree.collapseAll() );
		collapse.replaceWith( button );
	}
	return tree;
}
```

- [ ] **Step 5: Load it with the list-table bundle and lay out the indent**

In `src/list-table/index.js`, replace (1 of 2):

```js
 * modal behind the URLs notice, the filter subtitle and the nudge.
 */
import domReady from '@wordpress/dom-ready';
import { dismissNudge } from '../common/api';
import { open } from '../common/mount';
import RedirectsModal from '../common/redirects-modal';
```

with:

```js
 * modal behind the URLs notice, the filter subtitle, the nudge and the Tree
 * view.
 */
import domReady from '@wordpress/dom-ready';
import { dismissNudge } from '../common/api';
import { open } from '../common/mount';
import RedirectsModal from '../common/redirects-modal';
import bindTree from '../tree';
```

In `src/list-table/index.js`, replace (2 of 2):

```js
	bindQuickEdit();
```

with:

```js
	bindQuickEdit();
	bindTree();
```

In `src/list-table/list-table.scss`, replace:

```scss
	width: 20%;
}
```

with:

```scss
	width: 20%;
}

// The Tree view: each level indents its title by one step, and a row's
// toggle sits in the step before its title, so titles line up by level.
// A status row (Show more, loading, a failure, the cap) lines up with the
// titles of the subpages it is about.
.hierarchy-guard-tree-view #the-list {

	.column-title {
		padding-inline-start: 34px;
	}

	@for $level from 1 through 20 {
		.level-#{$level} .column-title,
		.level-#{$level}.hierarchy-guard-status > .colspanchange {
			padding-inline-start: 34px + 24px * $level;
		}
	}

	.column-title .hierarchy-guard-toggle {
		display: inline-block;
		width: 24px;
		margin-inline-start: -24px;
		vertical-align: top;
		text-decoration: none;
	}
}

// On a phone, at core's own breakpoint, the step halves and the indent
// stops at half the row, so a deep title keeps its room.
@media screen and (max-width: 782px) {

	.hierarchy-guard-tree-view #the-list {

		@for $level from 1 through 20 {
			.level-#{$level} .column-title,
			.level-#{$level}.hierarchy-guard-status > .colspanchange {
				padding-inline-start: min(#{34px + 12px * $level}, 50%);
			}
		}
	}
}
```

- [ ] **Step 6: Run the tests and the build**

Run: `npm run test:js`, `npm run lint:js`, `npm run lint:style`, `npm run build`
Expected: 127 tests pass; both lints exit 0; webpack compiles `build/list-table.js`, `build/list-table.css` and `build/list-table-rtl.css`.

- [ ] **Step 7: Commit**

```bash
git add src/common/copy.js src/common/test/copy.test.js src/tree/tree.js src/tree/index.js src/tree/test/tree.test.js src/list-table/index.js src/list-table/list-table.scss
git commit -m "feat: the tree grid: toggles, arrow keys, Show more and Collapse all"
```

### Task 8: README and a live check

**Files:**
- Modify: `README.md`

- [ ] **Step 1: Describe the view**

In `README.md`, replace:

```markdown
- **Pages list.** A Hierarchy column shows each page's parent and its number of subpages. The number, and the "View subpages" row action, narrow the list to that page's subtree; the heading then says which subtree is shown, with each ancestor one click away. Large subtrees list their first 1,000 pages.
```

with:

```markdown
- **Pages list.** A Hierarchy column shows each page's parent and its number of subpages. The number, and the "View subpages" row action, narrow the list to that page's subtree; the heading then says which subtree is shown, with each ancestor one click away. Large subtrees list their first 1,000 pages.
- **Tree view.** "Tree" in the list's views row shows the top-level pages, 20 to a page as the list pages them. A page with subpages has a toggle that opens them below it, indented, 50 at a time with "Show more", up to the first 1,000; each level opens the same way, and "Collapse all" closes them. Each row has the list's columns and its Edit, Trash, View and "View subpages" actions; trashing from the tree asks as the list does and comes back to the same place. Arrow keys move between rows and open and close them. A search in the Tree view lists the matching pages at any level with each one's parent pages, and "Back to the tree" returns to it. Without JavaScript a toggle reloads the view opened at that page, with only the path down to it shown at the levels above. The tree has no Quick Edit, checkboxes or bulk actions; use the list for those. A page whose parent is in the Trash, missing, or a private page the user can't read is not in the tree, because the tree starts from the top-level pages; the list and a search in the Tree view still show it.
```

- [ ] **Step 2: Run every check**

Run: `npm run test:php`, `npx wp-env run tests-cli --env-cwd=wp-content/plugins/hierarchy-guard bash -c 'WP_MULTISITE=1 vendor/bin/phpunit'`, `composer lint`, `npm run lint:js`, `npm run lint:style`, `npm run test:js`, `npm run build`
Expected: 894 PHPUnit tests pass (5 skipped), the same suite passes as multisite, both lints and Stylelint exit 0, 127 Vitest tests pass, and the build compiles.

- [ ] **Step 3: Live check**

With the build done and the dev site at http://localhost:8898 (admin / password), snapshot the `_hierarchy_guard_%` meta rows and the `hierarchy_guard_*` options (`wp option get <name> --format=json`), and note whether the admin user has an `edit_page_per_page` meta row. Create the test pages with WP-CLI (`npx wp-env run cli wp eval '…'`): "HGP5 Root" (`hgp5-root`, top level) with 60 subpages "HGP5 Child 01" to "HGP5 Child 60" (`hgp5-c01` … `hgp5-c60`; Child 07 a draft, Child 08 private, Child 09 password protected), "HGP5 Grand 1" and "HGP5 Grand 2" under Child 55, "HGP5 Great" under Grand 1, and a top-level "HGP5 Solo".

Without JavaScript (curl with a cookie jar: GET `/wp-login.php`, then POST `log=admin&pwd=password&testcookie=1`):

1. `edit.php?post_type=page&hierarchy_guard_view=tree`: the views row ends `… | Tree` with Tree `current`; the table has no checkbox column and no Quick Edit; only top-level pages are rows, each `level-0`; the Hierarchy cell holds the toggle link (`hierarchy_guard_open=<id>`) and the count, no parent; Collapse all is above the table; the body has `hierarchy-guard-tree-view`.
2. `…&hierarchy_guard_open=<Great>`: the rows are the top level with only the path down: HGP5 Root, Child 55 (`level-1`, position 55 of 60, on page 2), Grand 1 (`level-2`), Great (`level-3`); no other subpage of Root or of Child 55 is a row. Child 55's Trash link's `_wp_http_referer` opens Root at `hierarchy_guard_children_page=2`.
3. `…&hierarchy_guard_open=<Root>`: Root's first 50 subpages follow it, and its cell has "Show more" linking to `…&hierarchy_guard_open=<Root>&hierarchy_guard_children_page=2`, which shows Child 51 to 60.
4. Search `s=HGP5 Gr` in the view: a flat list of Grand 1, Grand 2 and Great, each with its parent path ("HGP5 Root › HGP5 Child 55", every ancestor linked to `hierarchy_guard_under=<id>`) after "Parent pages:", "Back to the tree" in the table navigation, no Collapse all, and core's Trash links without `_wp_http_referer`.
5. Trash a subpage through its link from an opened Root: core redirects to `…&hierarchy_guard_view=tree&hierarchy_guard_open=<Root>&trashed=1&ids=<id>`.
6. Create a top-level "ZZ HGP5 Zed", set Number of items to 6 (`wp user meta update 1 edit_page_per_page 6`) so Zed is alone on page 2, and trash it from there: the trash redirects to `…&paged=2&trashed=1&ids=<Zed>`, and that page redirects to `…&paged=1&trashed=1&ids=<Zed>`. With the same setting, trash a top-level test page from page 2 while page 2 still has rows: the Undo link, requested with that page as its referrer (`curl -e`), redirects to `…&paged=2&untrashed=1`. Delete the `edit_page_per_page` meta row again unless it was there before.
7. `/?post_type[]=post&post_type[]=page` on the front end answers 200 with no "Array to string conversion" in the debug log.

With JavaScript, in a browser:

8. The table is `role="treegrid"`, each top-level row `aria-level="1"` with `aria-posinset` and `aria-setsize` from core's item count, rows with subpages `aria-expanded="false"`; the toggle is a `button-link` with an arrow before the title, on the title's line.
9. Click HGP5 Root's toggle: 50 subpages load under it, indented one step, each with Title, Hierarchy, Author and Date, the actions Edit | Trash | View (| View subpages), the title cell's `aria-label` (WordPress 7.1), core's `.locked-info`, the "Child of HGP5 Root" description, and the states " — Draft", " — Private" (with View) and " — Password protected". The last row, indented like the titles, says "Show more · 50 of 60 subpages shown". Focus Show more and press it: the keyboard stays on it while it loads, then moves to the first new row.
10. With an `apiFetch` middleware that fails once (`wp.apiFetch.use()` in the console), close and reopen Root: the status row says "The subpages couldn’t be loaded. Try again", the keyboard is back on Root's toggle, and Try again loads the 50 rows without closing Root.
11. Focus a loaded row and press Collapse all: no network request, and Root is the row Tab reaches.
12. Keys: Down and Up move between rows, from the Show more button too; Home and End go to the first and last rows; Right opens a closed row, then moves into its first subpage; Left moves to the parent, then closes it.
13. Trash a loaded subpage that has subpages: the list's dialog opens with its sentence and Other options; Move to Trash reloads the view opened at its parent with core's notice, and the script keeps the server's open rows open. Undo restores it and lands on the same view.
14. Search "HGP5 Gr" in the view: "Search results for: HGP5 Gr · Back to the tree" beside the heading.
15. Right to left: with a temporary must-use plugin that sets `$wp_locale->text_direction` to `rtl` and translates `ltr` in the "text direction" context to `rtl` (behind a cookie), load `…&hierarchy_guard_open=<Great>`: the document is `dir="rtl"`, the indent is on the right, and closed toggles point left, toward their titles, before and after the script opens and closes a row. Delete the must-use plugin and the cookie.
16. At 500 pixels wide the indent steps by 12 pixels a level.
17. The console shows no errors from the plugin's script.

Afterwards delete every `hgp5-` page for good, deepest first (`wp eval` with `wp_delete_post( $id, true )` sorted by ancestor count, so no deletion moves a subpage), delete the `hierarchy_guard_change_1` transient, and delete the `edit_page_per_page` meta row if it wasn't there before. The meta rows and options must match the snapshot.

- [ ] **Step 4: Commit**

```bash
git add README.md
git commit -m "docs: describe the Tree view"
```

## Self-Review

**Spec coverage.** "Tree" in the views row at `edit.php?post_type=page&hierarchy_guard_view=tree` on every supported type, core's own list table on the same screen: Task 2 (`views()`, `load()` on `load-edit.php` for the screen's type). The top level from core's query with `post_parent` 0, 20 to a page by Number of items, in menu order then title as an array, so paging, sorting by a column and the item count are core's: Task 2 (`filter_request()`, `test_core_s_list_query_pages_the_top_level_and_never_reads_every_child_of_0`). A disclosure toggle on a row with subpages, only where the user may edit, opening its direct subpages below it indented one level, 50 at a time from the route's children paging in menu order then title: Tasks 1, 4 and 7. "Show more" loading the next 50 up to the cap, then "only the first 1,000 are shown": Tasks 1, 3, 4 and 7. Each level opening the same way: Task 7. Collapse all with no request, no Expand all: Tasks 4 and 7. Every row with Title, Hierarchy, Author and Date; the Hierarchy cell in the tree holding only the count as a link to the subtree: Tasks 4 and 6. Rows carrying Edit, View, Trash and "View subpages", the title linking to Edit: core's rows plus Task 6. Trash opening the list's own dialog and the view reloading in place through `_wp_http_referer`, Undo too: Tasks 5 and 7 and the live check. No Quick Edit, no checkboxes, no bulk actions: Task 2. Nothing growing with a subtree, `Subtree::ordered_children()` never for parent 0, counts in one grouped query, "1,000+" past the cap: Tasks 2, 3 and 4 (`test_opening_a_level_costs_the_same_queries_whatever_its_size`, `test_a_tree_screen_counts_its_opened_levels_in_the_same_one_query`). The flat search with parent paths, each ancestor linking to its subtree, "Back to the tree", one query per level for the ancestors: Tasks 2, 4 and 7. The tree grid: `role="treegrid"`, `aria-level`, `aria-expanded`, `aria-setsize` and `aria-posinset` where known and none past the cap, Up, Down, Right and Left, the toggles as `button-link` buttons: Tasks 3, 4 and 7. Without JavaScript, `hierarchy_guard_open=<id>` putting the path down to the opened page and its page of 50 after their parents so core prints them with its `level-N` class, each level above showing only the page that leads down, "Show more" linking to `hierarchy_guard_children_page=2`, the reload after a trash using the same link: Tasks 3, 4 and 5. The route's `children_per_page` (at most 50), each child's states, lock, author, date, View and trash links, `edit_post` on the parent: Task 1. Every supported hierarchical type: `Post_Types::is_supported()` throughout; the tests use pages. The audit rulings: the path-only levels without JavaScript (Task 3, `test_a_deep_opened_page_costs_its_depth_plus_one_page`), the pages the tree cannot reach (rulings, spec, README), the date formats' translator comments (Task 1), the front-end guard (Tasks 2 and 3), the page past the last (Task 3), a trash from a search (Task 5), a private page's View (Task 1), the loaded row's label, lock, description, states and missed schedule (Tasks 1 and 6), the status rows' indent, the keyboard on Show more and after a failure, the tab stop after a collapse, Up and Down from a status row, Home and End, the right-to-left arrow, the singular "Parent page:", the spoken count only when some are held back, and the phone step (Tasks 4 and 7). Out of scope respected: no Expand all, no Quick Edit, no drag and drop, no DataViews.

**Placeholder scan.** No TBD or TODO. Every code step carries complete code taken from the verified replay; every command names its expected result.

**Type consistency.** `Tree_View::row()`'s array (`level`, `parent`, `position`, `setsize`, `page`, `open`, `shown`, `more`, `capped`) is built only by `Tree_View::fact()` and `open()`, and read by `List_Table::render_tree_cell()` and `Tree_View::row_actions()`. The cell's `data-*` names (`id`, `children`, `status`, `level`, `parent`, `page`, `position`, `setsize`, `open`) are printed by `render_tree_cell()` and by `rows.js`'s `hierarchyContent()`, and read by `tree.js`'s `place()` and its set-up. `place_url()`'s arguments (`hierarchy_guard_open`, `hierarchy_guard_children_page`) are what `opened()` and `children_page()` read and what `rows.js`'s `placeUrl()` builds. The route's child fields (`states`, `locked_by`, `author`, `date.label`, `date.date`, `date.missed`, `trash_link`, `edit_link`, `view_link`, `children_count`, `children_capped`, `status`, `status_label`) are those `childRow()` reads. `fetchChildren( id, page )` resolves to the route's response (`children`, `children_total`, `children_capped`, `has_more`).

**Review Focus.** All named tests exist: in Tasks 2, 3, 6 and 7.

## Hand-offs to plan 6 (DataViews and release)

- Plan 6's readme.txt describes the Tree view from the README line Task 8 adds, and its translation pass covers the view's strings listed in Global Constraints.
- Plan 6's release zip builds the list-table bundle with the tree in it (`npm ci && npm run build`); `happy-dom` is a dev dependency and does not ship.
- Revisit a DataViews tree when DataViews gains expandable rows or core ships a shared `wp-dataviews` script (spec, Decisions "Tree view").
