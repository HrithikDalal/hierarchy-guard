# Hierarchy Guard Admin UI Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. Every review seat (task review, fix re-review, final review) runs on Opus.

**Goal:** Show editors what sits above and below a page before they change it, ask before a trash from the Pages list moves subpages, and after any URL change list the old and new URLs with what to do about them, without any request doing work that grows with the size of a subtree.

**Architecture:** PHP builds every number and pair: `Redirects\Pairs` walks the subpages behind an old path level by level up to the count cap, `Overview` summarizes one page, and read-only REST routes under `hierarchy-guard/v1` serve both, with CSV downloads printed through `rest_pre_serve_request`. The list screen gets a Hierarchy column, a subtree filter and "View subpages" from `Admin\List_Table`, the outcome notice from `Admin\Notices` (which replaces `Admin\Trash_Notice`), and the trash dialog's choice from `Admin\Trash_Choice`. Three small bundles built with `@wordpress/scripts` add the block editor panel, the list-screen behaviour (trash dialog, Quick Edit warning, redirects modal) and the classic-editor confirmation; `Admin\Assets` enqueues whichever bundles were built.

**Tech Stack:** PHP 8.0+, WordPress 6.6+, the plugin's engine and trash flow (plans 1 and 2), PHPUnit 9.6 in wp-env, PHPCS (WordPress-Extra, WordPress-VIP-Go, PHPCompatibilityWP), `@wordpress/scripts` 36.1.0 (webpack, ESLint, Stylelint, Vitest 5), React through `@wordpress/element`, `@wordpress/components`.

**Spec:** `docs/superpowers/specs/hierarchy-guard-spec.html` is a snapshot of the live spec at https://claude.ai/artifact/6V5RMR2w49uPiEp96ycyzq. Read "Features and mockups" (Editor sidebar panel; Hierarchy column, subtree filter and Tree view; Trash dialog; Outcome notice and redirects needed), "What the editor sees, by situation", "REST endpoint", Architecture and Testing. This plan builds spec build-order steps 6 and 7. The Tree view (step 8) is plan 4. The DataViews field and trash action, and the `hierarchy_guard` field on the REST page object they need (steps 9 and 10), are plan 5. The spec's parent dropdown is replaced by a breadcrumb beside the heading: a dropdown of every parent page cannot be bounded on a site with a million pages.

**Code in this plan is verified.** Every code block was run in a prototype on wp-env (WordPress 7.1.3) before it was written down: after every task the PHP suite passes (355 tests at the end), `composer lint` exits 0, and from Task 10 on `npm run lint:js`, `npm run lint:style`, `npm run test:js` (19 tests at the end) and `npm run build` succeed. The prototype was also checked in the browser. Transcribe it exactly; deviations need a reason in the report.

## Global Constraints

- Requires WordPress 6.6 and PHP 8.0. Namespace `HierarchyGuard`; new PHP goes in `HierarchyGuard\Admin`, `HierarchyGuard\REST` (`includes/HierarchyGuard/REST/`), `HierarchyGuard\Export` (`includes/HierarchyGuard/Export/`), `HierarchyGuard\Redirects\Pairs` and `HierarchyGuard\Overview`. Every PHP file declares `strict_types=1`.
- Commits are authored by the repository's git config. No `Co-Authored-By` trailer and no Claude attribution in any commit message.
- `composer lint` must exit 0, warnings included. Run `composer lint:fix` (phpcbf) first; formatting-only changes it makes are not deviations. Resolve any remaining warning with a behavior-neutral change or a targeted `// phpcs:ignore Sniff.Name.Code -- reason`.
- JavaScript and SCSS: `npm run lint:js` and `npm run lint:style` must exit 0, warnings included. Run `npx wp-scripts lint-js src --fix` and `npx wp-scripts lint-style "src/**/*.scss" --fix` first; formatting-only changes are not deviations. `build/` is never committed (`.gitignore` already lists it).
- Tooling versions: `@wordpress/scripts` exactly 36.1.0, which needs Node `^22.22.2 || ^24.15.0` (CI uses 24) and runs unit tests with Vitest 5 and Vite 8. `@wordpress/*` imports are externals at run time, so code may use only APIs WordPress 6.6 ships: no `__experimental` components, `createRoot` from `@wordpress/element`, `PluginDocumentSettingPanel` and `store` from `@wordpress/editor`.
- Tests that check a hook is registered must find the plugin's own object in `$GLOBALS['wp_filter'][ $hook ]->callbacks[ $priority ]`; `has_action( $hook )` alone is always true because core registers callbacks on the same hooks.
- UI: core components and core admin classes only (`notice`, `description`, `widefat striped`, `subtitle`, `screen-reader-text`). No colours, radii, shadows or type sizes of the plugin's own; plugin CSS sets layout only (margins, gaps, widths, grid, display). Status is text, not a pill. The word is "subpages".
- Copy: sentence case, no product name inside UI text except the nudge, no exclamation marks. One translatable string per sentence, `_n()` on the counted noun, a translator comment for every placeholder, titles inside the string as “%s”, curly quotes and apostrophes. PHP numbers through `number_format_i18n()`, JS numbers through `formatCount()`; a count at the cap shows as "1,000+". "Trash" is capitalised in sentences shown on core's notices and in the list screen's dialogs, as core does there. Text domain `hierarchy-guard` in PHP and JS.
- Scale: no request does work that grows with the size of a subtree. Every walk stops at `Subtree::count_cap()` (filter `hierarchy_guard_count_cap`, default 1,000). The Hierarchy column costs one query per list screen, none in core's indented tree mode. The trash dialog's capability check covers one batch of direct subpages (`Operations::threshold()`, default 200).
- REST: namespace `hierarchy-guard/v1`, every route read-only. `posts/{id}/hierarchy`, `posts/{id}/pairs` and `posts/{id}/csv` need a supported type and `edit_post` on the ID; `trash-preview` needs a logged-in user and lists only IDs the user may `delete_post`; `changes/{id}/pairs` and `changes/{id}/csv` answer only for the current user's latest change. Error codes: `rest_post_invalid_id` (404), `rest_forbidden`, `hierarchy_guard_unknown_path` (404), `hierarchy_guard_change_gone` (404).
- Pairs: a pair is a published page's old URL and its current one, both relative to the home URL (`/about/team/`). An old path whose head has no target is "unresolved", with reason `trash`, `unpublished` or `none`. Pairs are never stored. CSV layouts: `redirection` and `srm` are `source,target,regex,code` with `0` and `301`; `generic` is `source,target,code`; CRLF line ends, a header row, RFC 4180 quoting, and a leading apostrophe on a cell starting with `=`, `+`, `-`, `@`, tab or CR. A CSV response carries the `X-Hierarchy-Guard-Csv` header and is printed as is; download links carry `_wpnonce` for `wp_rest`.
- Trash dialog: the chosen policy travels as `hierarchy_guard_policy` on core's own row-action link (`post.php?action=trash`) or bulk form (`edit.php`, `action` or `action2` `trash`). It applies only after core's nonce verifies again (`trash-post_{id}`, `bulk-posts`) and only to the IDs the request names, through the `hierarchy_guard_trash_policy` filter.
- Subtree filter: query var `hierarchy_guard_under` (an ID). The filtered list is the capped walk's IDs as `post__in`, sorted by menu order then title as an array, so core renders paginated rows instead of loading every page for its tree.
- Settings anchors: `#hierarchy-guard-redirects` on the Redirects checkbox and `#hierarchy-guard-trash-policy` on the trash-policy fieldset. "Turn on redirects" and "Settings" links appear only for users with `manage_options`.
- The redirects nudge: user meta `hierarchy_guard_nudge_dismissed` (boolean, `show_in_rest`, writable by users with `manage_options`), shown on supported list screens while built-in redirects are off and no redirect plugin is detected.

## Review Focus

1. Core's default Pages list renders in its indented tree mode, which loads the ID and parent of every page: the Hierarchy column must count from those rows without a query, and still mark pages with subpages so the trash dialog opens. Pinned in Task 4 (`test_core_s_tree_mode_is_counted_without_a_query`).
2. The trashed page is deleted for good within the thirty minutes: "View redirects" still lists every old subpage URL, now leading under the new parent, and the old page URL leads to that parent. Pinned in Task 2 (`test_once_the_trash_is_emptied_the_old_path_leads_to_the_new_parent`).
3. A forged or stray `hierarchy_guard_policy` (wrong nonce, another page, an unknown value, another screen) changes nothing. Pinned in Task 6 (`test_a_choice_without_core_s_nonce_is_ignored`, `test_an_unknown_policy_is_ignored`, `test_outside_the_two_screens_the_choice_is_ignored`).
4. One user asks for another user's change: refused, whatever its ID. Pinned in Task 7 (`test_another_user_cannot_read_the_change`).
5. A subpage whose slug was taken under the new parent: the pairs list it at its old slug, leading to the numbered one. Pinned in Task 2 (`test_a_subpage_renamed_on_the_move_is_listed_at_its_old_slug`).

## Known limitations

Accepted for this plan; reviewers should not re-raise them.

- In core's indented tree mode the Hierarchy column counts the children the current view shows, so a page whose only subpages the view hides (another status, or private pages the user can't read) has no count and its Trash link skips the dialog. The default policy, the outcome notice and Undo still apply.
- The bulk dialog's total counts a selected subpage's subtree twice when its parent is selected too. Core trashes the selected subpages itself, and the outcome notice reports what happened.
- Bulk Edit parent changes get neither the warning nor the redirects notice.
- Once a trashed page is deleted for good its moved markers are gone, so a subpage that was renamed on the move is listed at its current slug.
- CSV and modal paths are relative to the home URL, so on a subdirectory install they leave out the directory.
- The Tree view is plan 4. The DataViews field and trash action, the `hierarchy_guard` REST page field, readme.txt, uninstall cleanup and the release zip are plan 5.

---

## File Structure

```
hierarchy-guard.php                        MOD  plugin description
includes/HierarchyGuard/
├── Subtree.php                            MOD  child_counts() for a screen of rows, descendant_ids() capped walk
├── Paths.php                              MOD  url() core's URL for a path, relative() home-relative form
├── Overview.php                           NEW  one page's ancestors, paged children, counts, old URLs, trash outcome
├── Plugin.php                             MOD  wiring
├── Redirects/Pairs.php                    NEW  old and new URL pairs behind an old path, capped, never stored
├── Export/Csv.php                         NEW  CSV in redirect plugins' import layouts
├── REST/Hierarchy_Controller.php          NEW  posts/{id}/hierarchy, posts/{id}/pairs, posts/{id}/csv, trash-preview
├── REST/Changes_Controller.php            NEW  changes/{id}/pairs, changes/{id}/csv
├── REST/Csv_Download.php                  NEW  prints a flagged CSV body instead of JSON
├── Trash/Change_Log.php                   MOD  a root entry keeps its old path
├── Trash/Trash_Handler.php                MOD  passes the old path to the change
├── Admin/List_Table.php                   NEW  Hierarchy column, "View subpages", subtree filter and breadcrumb
├── Admin/Trash_Choice.php                 NEW  the dialog's policy from core's verified trash request
├── Admin/Notices.php                      NEW  outcome notice (was Trash_Notice), background progress, redirects nudge
├── Admin/Trash_Notice.php                 DEL  moved to Notices.php
├── Admin/Meta_Box.php                     NEW  read-only Hierarchy box in the classic editor
├── Admin/Assets.php                       NEW  enqueues built bundles with their settings
└── Admin/Settings_Section.php             MOD  anchors, the spec's help text, a nameless provider
src/
├── common/   config.js · copy.js (every sentence) · api.js · mount.js · redirects-modal.js · modal.scss · test/
├── editor/   index.js · panel.js · editor.scss        block editor Hierarchy panel
├── list-table/ index.js · trash-dialog.js · quick-edit.js · list-table.scss
└── classic/  index.js                                  classic-editor confirmation
package.json · package-lock.json · webpack.config.js · .distignore · .github/workflows/ci.yml · README.md · composer.json   MOD/NEW
tests/phpunit/
├── PairsTest · CsvTest · ListTableTest · HierarchyControllerTest · TrashChoiceTest · ChangesControllerTest · MetaBoxTest · AssetsTest   NEW
├── NoticesTest (was TrashNoticeTest)
└── SubtreeTest · PathsTest · SettingsSectionTest                                                                                        MOD
```

`Pairs` is the only class that decides which old URL leads where. `Overview` decides what one page's summary says. The REST controllers check permissions and shape responses; `List_Table`, `Notices`, `Meta_Box` and `Trash_Choice` are the PHP screens; the bundles add behaviour on top of markup that works without them. Every sentence the scripts show lives in `src/common/copy.js`.

---
### Task 1: Capped child counts, the subtree walk, and URL helpers

**Files:**
- Modify: `includes/HierarchyGuard/Subtree.php` (two methods at the end of the class)
- Modify: `includes/HierarchyGuard/Paths.php` (an import and two methods at the end of the class)
- Test: `tests/phpunit/SubtreeTest.php`, `tests/phpunit/PathsTest.php` (tests at the end of each class)

**Interfaces:**
- Produces: `Subtree::child_counts( int[] $ids, string $post_type, int $cap ): array<int,int>`: live direct-child counts for a screenful of posts in one statement (a capped subquery per ID joined with `UNION ALL`), every given ID present, each count stopping at `$cap`.
- Produces: `Subtree::descendant_ids( int $root_id, string $post_type, int $cap ): array{ids:int[],capped:bool}`: live descendants level by level, each level in menu order then title then ID, at most `$cap` IDs, `capped` when more exist.
- Produces: `Paths::url( string $post_type, string $path ): string`: the absolute URL core would build for a post of that type at that path (page permastruct, or the type's extra permastruct), empty without pretty permalinks or a permastruct.
- Produces: `Paths::relative( string $url ): string`: a URL on this site as a path relative to the home URL (`/about/team/`), query string kept, a subdirectory home stripped.

- [ ] **Step 1: Write the failing tests**

Append to the class in `tests/phpunit/SubtreeTest.php`:
```php
	public function test_child_counts_counts_live_children_per_post_in_one_query(): void {
		$this->page( 'draft-child', $this->ids['about'], 'draft' );
		$this->trash_raw( $this->page( 'gone', $this->ids['about'] ) );

		$queries = get_num_queries();
		$counts  = Subtree::child_counts( [ $this->ids['company'], $this->ids['about'], $this->ids['team'], $this->ids['leadership'] ], 'page', 10 );

		$this->assertSame( 1, get_num_queries() - $queries );
		$this->assertSame(
			[
				$this->ids['company']    => 1,
				$this->ids['about']      => 3,
				$this->ids['team']       => 1,
				$this->ids['leadership'] => 0,
			],
			$counts,
			'A draft counts, a trashed child does not.'
		);
	}

	public function test_child_counts_stop_at_the_cap(): void {
		$this->page( 'third', $this->ids['about'] );

		$this->assertSame( [ $this->ids['about'] => 2 ], Subtree::child_counts( [ $this->ids['about'] ], 'page', 2 ) );
		$this->assertSame( [], Subtree::child_counts( [], 'page', 2 ) );
	}

	public function test_descendant_ids_walks_level_by_level_up_to_the_cap(): void {
		$all = Subtree::descendant_ids( $this->ids['company'], 'page', 10 );
		$this->assertSame( [ $this->ids['about'], $this->ids['history'], $this->ids['team'], $this->ids['leadership'] ], $all['ids'], 'Level by level, each in menu order then title.' );
		$this->assertFalse( $all['capped'] );

		$two = Subtree::descendant_ids( $this->ids['company'], 'page', 2 );
		$this->assertSame( [ $this->ids['about'], $this->ids['history'] ], $two['ids'] );
		$this->assertTrue( $two['capped'] );

		$exact = Subtree::descendant_ids( $this->ids['company'], 'page', 4 );
		$this->assertFalse( $exact['capped'], 'Exactly the cap is not capped.' );
	}
```

Append to the class in `tests/phpunit/PathsTest.php`:
```php
	public function test_url_builds_the_permalink_core_would_build_for_a_path(): void {
		$parent = self::factory()->post->create(
			[
				'post_type' => 'page',
				'post_name' => 'about',
			]
		);
		$child  = self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_name'   => 'team',
				'post_parent' => $parent,
			]
		);

		$this->assertSame( get_permalink( $child ), Paths::url( 'page', 'about/team' ) );
		$this->assertSame( home_url( '/old/path/' ), Paths::url( 'page', '/Old/Path/' ) );
	}

	public function test_url_uses_the_rewrite_prefix_of_a_custom_type(): void {
		register_post_type(
			'hg_doc',
			[
				'public'       => true,
				'hierarchical' => true,
				'rewrite'      => [ 'slug' => 'docs' ],
			]
		);
		$this->set_permalink_structure( '/%postname%/' );

		$this->assertSame( home_url( '/docs/guide/intro/' ), Paths::url( 'hg_doc', 'guide/intro' ) );
		$this->assertSame( '', Paths::url( 'hg_none', 'guide' ), 'A type without a permastruct has no URL.' );
		unregister_post_type( 'hg_doc' );
	}

	public function test_url_is_empty_without_pretty_permalinks(): void {
		$this->set_permalink_structure( '' );

		$this->assertSame( '', Paths::url( 'page', 'about' ) );
	}

	public function test_relative_strips_the_home_url(): void {
		$this->assertSame( '/about/team/', Paths::relative( home_url( '/about/team/' ) ) );
		$this->assertSame( '/', Paths::relative( home_url() ) );
		$this->assertSame( '/about/?x=1', Paths::relative( home_url( '/about/?x=1' ) ) );
	}

	public function test_relative_strips_a_subdirectory_home(): void {
		$filter = static fn( $url, $path ): string => 'http://example.org/blog/' . ltrim( (string) $path, '/' );
		add_filter( 'home_url', $filter, 10, 2 );

		$this->assertSame( '/about/', Paths::relative( 'http://example.org/blog/about/' ) );
		$this->assertSame( '/', Paths::relative( 'http://example.org/blog/' ) );
		remove_filter( 'home_url', $filter, 10 );
	}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npm run test:php -- --filter 'SubtreeTest|PathsTest'`
Expected: FAIL with `Error: Call to undefined method HierarchyGuard\Subtree::child_counts()` and `Error: Call to undefined method HierarchyGuard\Paths::url()`.

- [ ] **Step 3: Add the Subtree methods**

Append to the class in `includes/HierarchyGuard/Subtree.php`:
```php
	/**
	 * Live direct-child counts for a screenful of posts, each stopping at the cap.
	 * One statement: a capped subquery per post, joined with UNION ALL.
	 *
	 * @param int[]  $ids       Post IDs, at most one list-table page of them.
	 * @param string $post_type Post type name.
	 * @param int    $cap       Stop counting each post's children at this number.
	 * @return array<int,int> Count by post ID; posts without live children are 0.
	 */
	public static function child_counts( array $ids, string $post_type, int $cap ): array {
		global $wpdb;

		$ids    = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		$counts = array_fill_keys( $ids, 0 );
		if ( [] === $ids ) {
			return $counts;
		}

		$cap   = max( 1, $cap );
		$parts = [];
		foreach ( $ids as $id ) {
			$parts[] = $wpdb->prepare(
				"( SELECT %d AS parent_id, COUNT(*) AS children FROM ( SELECT ID FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = %s AND post_status NOT IN ( 'trash', 'auto-draft', 'inherit' ) LIMIT %d ) AS c%d )",
				$id,
				$id,
				$post_type,
				$cap,
				$id
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Every part was prepared above; each is an index range on post_parent bounded by LIMIT.
		$rows = $wpdb->get_results( implode( ' UNION ALL ', $parts ), ARRAY_A );
		foreach ( (array) $rows as $row ) {
			$counts[ (int) $row['parent_id'] ] = (int) $row['children'];
		}

		return $counts;
	}

	/**
	 * Live descendants level by level, stopping at the cap.
	 *
	 * @param int    $root_id   Root post ID.
	 * @param string $post_type Post type name.
	 * @param int    $cap       Most IDs to return.
	 * @return array{ids:int[],capped:bool} IDs root-near first; capped when more exist.
	 */
	public static function descendant_ids( int $root_id, string $post_type, int $cap ): array {
		global $wpdb;

		$cap   = max( 1, $cap );
		$ids   = [];
		$found = 0;
		$level = [ $root_id ];
		$depth = 0;

		while ( [] !== $level && $found <= $cap && $depth < 50 ) {
			++$depth;
			$in = implode( ',', array_map( 'intval', $level ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in holds integers only; LIMIT bounds each level by the remaining cap.
			$level  = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_parent IN ( {$in} ) AND post_type = %s AND post_status NOT IN ( 'trash', 'auto-draft', 'inherit' ) ORDER BY menu_order ASC, post_title ASC, ID ASC LIMIT %d", $post_type, $cap + 1 - $found ) ) );
			$ids    = array_merge( $ids, $level );
			$found += count( $level );
		}

		return [
			'ids'    => array_slice( $ids, 0, $cap ),
			'capped' => $found > $cap,
		];
	}
```

- [ ] **Step 4: Add the Paths methods**

In `includes/HierarchyGuard/Paths.php`, replace:
```php
use WP_Post;
```
with:
```php
use WP_Post;
use WP_Rewrite;
```

Append to the class in `includes/HierarchyGuard/Paths.php`:
```php
	/**
	 * The URL core would build for a post of this type at this path, the way
	 * get_page_link() and get_post_permalink() do.
	 *
	 * @param string $post_type Post type name.
	 * @param string $path      Path without the type's rewrite prefix, such as `about/team`.
	 * @return string Absolute URL, or an empty string without pretty permalinks.
	 */
	public static function url( string $post_type, string $path ): string {
		global $wp_rewrite;

		if ( ! $wp_rewrite instanceof WP_Rewrite || '' === (string) get_option( 'permalink_structure' ) ) {
			return '';
		}
		$path = self::normalize( $path );

		if ( 'page' === $post_type ) {
			$struct = (string) $wp_rewrite->get_page_permastruct();
			return user_trailingslashit( home_url( str_replace( '%pagename%', $path, $struct ) ), 'page' );
		}

		$struct = $wp_rewrite->get_extra_permastruct( $post_type );
		if ( ! is_string( $struct ) || '' === $struct ) {
			return '';
		}
		return home_url( user_trailingslashit( str_replace( '%' . $post_type . '%', $path, $struct ) ) );
	}

	/**
	 * A URL on this site as a path relative to the home URL, such as `/about/team/`.
	 * This is the form redirect plugins expect for the old and the new URL.
	 *
	 * @param string $url Absolute URL on this site.
	 * @return string Path starting with a slash, with any query string kept.
	 */
	public static function relative( string $url ): string {
		$home  = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$path  = (string) wp_parse_url( $url, PHP_URL_PATH );
		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );

		if ( '' !== $home && '/' !== $home && str_starts_with( $path, $home ) ) {
			$path = '/' . substr( $path, strlen( $home ) );
		}
		if ( '' === $path ) {
			$path = '/';
		}

		return $path . ( '' !== $query ? '?' . $query : '' );
	}
```

- [ ] **Step 5: Run the tests, lint and the suite**

Run: `npm run test:php -- --filter 'SubtreeTest|PathsTest'`
Expected: PASS.
Run: `composer lint:fix; composer lint && npm run test:php`
Expected: lint exits 0; the suite passes.

- [ ] **Step 6: Commit**

```bash
git add includes/HierarchyGuard/Subtree.php includes/HierarchyGuard/Paths.php tests/phpunit/SubtreeTest.php tests/phpunit/PathsTest.php
git commit -m "feat: capped child counts, the subtree walk and URL helpers"
```

### Task 2: Old and new URL pairs

**Files:**
- Create: `includes/HierarchyGuard/Redirects/Pairs.php`
- Modify: `includes/HierarchyGuard/Trash/Change_Log.php` (a root entry keeps `path`)
- Modify: `includes/HierarchyGuard/Trash/Trash_Handler.php` (`summarize()` passes it)
- Test: `tests/phpunit/PairsTest.php`

**Interfaces:**
- Consumes: Task 1's `Paths::url()` and `Paths::relative()`; `Subtree::count_cap()`; plan 2's `Markers::rows()`, `Markers::moved_key()`, `Markers::decode_moved()`, `Subtree::status()`, `Change_Log`.
- Produces: `Redirects\Pairs` with `PER_PAGE = 20`.
  - A block is `array{post_type:string, old:string, head:int, reason:string, children:array<int,array{id:int,parent:int,segment:string,status:string}>}`: the old path leads to `head` (0 for none), and each listed child, at its old `segment`, leads to that child today.
  - `static post_block( WP_Post $post, string $old_path ): array`: the block for an old path recorded on a post; its children are the post's live children at their current slugs.
  - `static change_blocks( array $change ): array`: one block per root of a `trash` change whose policy is `reparent` and whose `path` is set. A root restored since has no block. While the root is in the trash its head is 0 with reason `trash` and its children are the moved markers at their original slugs; once it is deleted for good its head is its `to` and its children are the change's `ids` that are live children of `to`.
  - `collect( array $blocks ): array{pairs:array<int,array{old:string,id:int}>, unresolved:array<int,array{old:string,reason:string}>, capped:bool}`: depth first in menu order, published pages only, at most the count cap. A head that is not publicly viewable is unresolved with the block's reason, else `unpublished` for a post head or `none` without one.
  - `page( array $blocks, int $page, int $per_page ): array{pairs:array<int,array{old:string,new:string}>, unresolved:array<int,array{old:string,reason:string}>, total:int, capped:bool, page:int, pages:int}`: URLs relative to the home URL; a page past the end shows the last one.
  - `all( array $blocks ): array<int,array{old:string,new:string}>` and `count( array $blocks ): array{count:int,capped:bool}`.
- Produces: `Change_Log` root entries gain `path` (string, the root's old path); `Trash_Handler::summarize()` sets it from the job's `paths[0]`.

- [ ] **Step 1: Write the failing test**

Create `tests/phpunit/PairsTest.php`:
```php
<?php
/**
 * Old and new URL pairs.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Ledger;
use HierarchyGuard\Post_Types;
use HierarchyGuard\Redirects\Pairs;
use HierarchyGuard\Settings;
use HierarchyGuard\Trash\Change_Log;
use WP_UnitTestCase;

/**
 * @covers \HierarchyGuard\Redirects\Pairs
 */
final class PairsTest extends WP_UnitTestCase {

	use Trash_Fixtures;

	/**
	 * Page IDs.
	 *
	 * @var array<string,int>
	 */
	private array $ids;

	private int $admin;

	private Pairs $pairs;

	private Change_Log $log;

	public function set_up(): void {
		parent::set_up();
		delete_option( Settings::OPTION );
		delete_option( Ledger::ROOT_OPTION );
		Post_Types::reset();
		Change_Log::reset_request();
		$this->set_permalink_structure( '/%postname%/' );
		$this->admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $this->admin );
		$this->pairs = new Pairs();
		$this->log   = new Change_Log();
		$this->ids   = $this->make_tree();
	}

	public function tear_down(): void {
		$this->set_permalink_structure( '' );
		parent::tear_down();
	}

	/**
	 * Old and new URLs of a page of pairs.
	 *
	 * @param array<int,array<string,mixed>> $blocks Blocks.
	 * @return array<int,string> "old -> new" lines.
	 */
	private function lines( array $blocks ): array {
		return array_map( static fn( array $pair ): string => $pair['old'] . ' -> ' . $pair['new'], $this->pairs->page( $blocks, 1, 100 )['pairs'] );
	}

	public function test_an_old_path_on_a_page_leads_to_the_page_and_its_subpages(): void {
		$block = Pairs::post_block( get_post( $this->ids['about'] ), 'company/about-us' );

		$this->assertSame(
			[
				'/company/about-us/ -> /company/about/',
				'/company/about-us/history/ -> /company/about/history/',
				'/company/about-us/team/ -> /company/about/team/',
				'/company/about-us/team/leadership/ -> /company/about/team/leadership/',
			],
			$this->lines( [ $block ] ),
			'Depth first, in menu order then title.'
		);
	}

	public function test_a_draft_subpage_has_no_pair_but_its_published_subpages_do(): void {
		wp_update_post(
			[
				'ID'          => $this->ids['team'],
				'post_status' => 'draft',
			]
		);

		$this->assertSame(
			[
				'/company/about-us/ -> /company/about/',
				'/company/about-us/history/ -> /company/about/history/',
				'/company/about-us/team/leadership/ -> /company/about/team/leadership/',
			],
			$this->lines( [ Pairs::post_block( get_post( $this->ids['about'] ), 'company/about-us' ) ] )
		);
	}

	public function test_a_head_that_is_not_published_is_listed_as_unresolved(): void {
		wp_update_post(
			[
				'ID'          => $this->ids['about'],
				'post_status' => 'draft',
			]
		);

		$page = $this->pairs->page( [ Pairs::post_block( get_post( $this->ids['about'] ), 'company/about-us' ) ], 1, 100 );

		$this->assertSame(
			[
				[
					'old'    => '/company/about-us/',
					'reason' => 'unpublished',
				],
			],
			$page['unresolved']
		);
		$this->assertSame( 3, $page['total'] );
	}

	public function test_pairs_are_paged(): void {
		$block = Pairs::post_block( get_post( $this->ids['about'] ), 'company/about-us' );

		$page = $this->pairs->page( [ $block ], 2, 3 );

		$this->assertSame( 4, $page['total'] );
		$this->assertSame( 2, $page['pages'] );
		$this->assertSame( 2, $page['page'] );
		$this->assertSame( [ '/company/about-us/team/leadership/' ], array_column( $page['pairs'], 'old' ) );
		$this->assertSame( 2, $this->pairs->page( [ $block ], 9, 3 )['page'], 'A page past the end shows the last one.' );
	}

	public function test_the_walk_stops_at_the_count_cap(): void {
		add_filter( 'hierarchy_guard_count_cap', static fn(): int => 2 );

		$count = $this->pairs->count( [ Pairs::post_block( get_post( $this->ids['about'] ), 'company/about-us' ) ] );

		$this->assertSame(
			[
				'count'  => 2,
				'capped' => true,
			],
			$count
		);
	}

	public function test_the_walk_costs_one_query_per_level(): void {
		$block   = Pairs::post_block( get_post( $this->ids['about'] ), 'company/about-us' );
		$queries = get_num_queries();

		$this->pairs->collect( [ $block ] );

		$this->assertLessThanOrEqual( 3, get_num_queries() - $queries, 'Levels below about: team and history, then leadership, then an empty level.' );
	}

	public function test_a_trash_lists_the_moved_subpages_under_the_old_path(): void {
		wp_trash_post( $this->ids['about'] );
		$blocks = Pairs::change_blocks( $this->log->latest( $this->admin ) );

		$this->assertSame(
			[
				'/company/about/history/ -> /company/history/',
				'/company/about/team/ -> /company/team/',
				'/company/about/team/leadership/ -> /company/team/leadership/',
			],
			$this->lines( $blocks )
		);
		$this->assertSame(
			[
				[
					'old'    => '/company/about/',
					'reason' => 'trash',
				],
			],
			$this->pairs->page( $blocks, 1, 20 )['unresolved'],
			'The trashed page itself has no target.'
		);
	}

	public function test_a_subpage_renamed_on_the_move_is_listed_at_its_old_slug(): void {
		$this->page( 'team', $this->ids['company'] );

		wp_trash_post( $this->ids['about'] );

		$this->assertContains( '/company/about/team/ -> /company/team-2/', $this->lines( Pairs::change_blocks( $this->log->latest( $this->admin ) ) ) );
	}

	public function test_once_the_trash_is_emptied_the_old_path_leads_to_the_new_parent(): void {
		wp_trash_post( $this->ids['about'] );
		$change = $this->log->latest( $this->admin );
		wp_delete_post( $this->ids['about'], true );

		$this->assertSame(
			[
				'/company/about/ -> /company/',
				'/company/about/history/ -> /company/history/',
				'/company/about/team/ -> /company/team/',
				'/company/about/team/leadership/ -> /company/team/leadership/',
			],
			$this->lines( Pairs::change_blocks( $change ) )
		);
	}

	public function test_a_restored_page_needs_no_redirects(): void {
		wp_trash_post( $this->ids['about'] );
		$change = $this->log->latest( $this->admin );
		wp_untrash_post( $this->ids['about'] );

		$this->assertSame( [], Pairs::change_blocks( $change ) );
	}

	public function test_only_a_trash_that_moved_subpages_up_has_pairs(): void {
		add_filter( 'hierarchy_guard_trash_policy', static fn(): string => Settings::POLICY_KEEP );
		wp_trash_post( $this->ids['about'] );

		$this->assertSame( [], Pairs::change_blocks( $this->log->latest( $this->admin ) ) );

		$this->assertSame( [], Pairs::change_blocks( [ 'action' => 'restore' ] ) );
	}

	public function test_a_download_has_every_pair(): void {
		$block = Pairs::post_block( get_post( $this->ids['about'] ), 'company/about-us' );

		$this->assertCount( 4, $this->pairs->all( [ $block ] ) );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `npm run test:php -- --filter PairsTest`
Expected: FAIL with `Error: Class "HierarchyGuard\Redirects\Pairs" not found`.

- [ ] **Step 3: Keep the root's old path in the change**

In `includes/HierarchyGuard/Trash/Change_Log.php`, replace:
```php
	 * @param array<string,mixed> $entry     Keys: root, to, policy, fallback, counts, kept_ids, urls, job.
```
with:
```php
	 * @param array<string,mixed> $entry     Keys: root, to, policy, fallback, path, counts, kept_ids, urls, job.
```

and replace:
```php
			'fallback' => (bool) ( $entry['fallback'] ?? false ),
			'counts'   => $counts,
```
with:
```php
			'fallback' => (bool) ( $entry['fallback'] ?? false ),
			'path'     => (string) ( $entry['path'] ?? '' ),
			'counts'   => $counts,
```

In `includes/HierarchyGuard/Trash/Trash_Handler.php`, replace:
```php
				'fallback' => (bool) $job['fallback'],
				'counts'   => (array) $job['counts'],
```
with:
```php
				'fallback' => (bool) $job['fallback'],
				// The root's own old path: the redirects modal rebuilds the pairs from it.
				'path'     => (string) ( $job['paths'][0] ?? '' ),
				'counts'   => (array) $job['counts'],
```

- [ ] **Step 4: Create Pairs**

Create `includes/HierarchyGuard/Redirects/Pairs.php`:
```php
<?php
/**
 * Old and new URL pairs for the redirects a change needs.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Redirects;

use HierarchyGuard\Paths;
use HierarchyGuard\Settings;
use HierarchyGuard\Subtree;
use HierarchyGuard\Trash\Markers;
use WP_Post;

/**
 * Lists the old URLs a change left behind and where each leads now.
 *
 * A block starts at one old path. Its head is the post that path leads to,
 * or none. Below it come the listed children, each at its old path segment,
 * and the published pages under them, depth first in menu order. Nothing is
 * stored: pairs are computed when asked for, and no walk goes past the
 * count cap.
 */
final class Pairs {

	public const PER_PAGE = 20;

	/**
	 * The block for an old path recorded on a post: the old path leads to the
	 * post, and each old subpage path to the same subpage today.
	 *
	 * @param WP_Post $post     Post the old path is recorded on.
	 * @param string  $old_path The old path, any form.
	 * @return array<string,mixed> Block.
	 */
	public static function post_block( WP_Post $post, string $old_path ): array {
		global $wpdb;

		$type = (string) $post->post_type;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Index range on post_parent, bounded by the count cap.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_parent, post_name, post_status FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = %s AND post_status NOT IN ( 'trash', 'auto-draft', 'inherit' ) ORDER BY menu_order ASC, post_title ASC, ID ASC LIMIT %d",
				(int) $post->ID,
				$type,
				Subtree::count_cap() + 1
			),
			ARRAY_A
		);

		$children = [];
		foreach ( (array) $rows as $row ) {
			$children[] = self::node( $row, (string) $row['post_name'] );
		}

		return [
			'post_type' => $type,
			'old'       => Paths::normalize( $old_path ),
			'head'      => (int) $post->ID,
			'reason'    => '',
			'children'  => $children,
		];
	}

	/**
	 * The blocks for a trash change: one per root whose subpages moved up.
	 * A root restored since has no block, because its URLs work again.
	 *
	 * @param array<string,mixed> $change A change from Change_Log.
	 * @return array<int,array<string,mixed>> Blocks.
	 */
	public static function change_blocks( array $change ): array {
		if ( 'trash' !== ( $change['action'] ?? '' ) ) {
			return [];
		}

		$type   = (string) $change['post_type'];
		$blocks = [];
		foreach ( (array) $change['roots'] as $root ) {
			$path = (string) ( $root['path'] ?? '' );
			if ( Settings::POLICY_REPARENT !== ( $root['policy'] ?? '' ) || '' === $path ) {
				continue;
			}
			$root_id = (int) $root['root'];
			$to      = (int) $root['to'];
			$status  = Subtree::status( $root_id );
			if ( '' !== $status && 'trash' !== $status ) {
				continue;
			}

			$children = 'trash' === $status ? self::moved_children( $root_id ) : [];
			if ( [] === $children ) {
				$children = self::children_among( array_map( 'intval', (array) ( $change['ids'] ?? [] ) ), $to, $type );
			}

			$blocks[] = [
				'post_type' => $type,
				'old'       => Paths::normalize( $path ),
				// A deleted root's old path now leads to the page its subpages moved under.
				'head'      => '' === $status ? $to : 0,
				'reason'    => 'trash' === $status ? 'trash' : '',
				'children'  => $children,
			];
		}

		return $blocks;
	}

	/**
	 * Every pair of the blocks, up to the count cap.
	 *
	 * @param array<int,array<string,mixed>> $blocks Blocks.
	 * @return array{pairs:array<int,array{old:string,id:int}>,unresolved:array<int,array{old:string,reason:string}>,capped:bool}
	 */
	public function collect( array $blocks ): array {
		$cap        = Subtree::count_cap();
		$pairs      = [];
		$unresolved = [];
		$capped     = false;

		foreach ( $blocks as $block ) {
			$old  = (string) $block['old'];
			$head = (int) $block['head'];
			if ( $head > 0 && is_post_publicly_viewable( $head ) ) {
				$pairs[] = [
					'old' => $old,
					'id'  => $head,
				];
			} else {
				$reason = (string) $block['reason'];
				if ( '' === $reason ) {
					$reason = $head > 0 ? 'unpublished' : 'none';
				}
				$unresolved[] = [
					'old'    => $old,
					'reason' => $reason,
				];
			}

			$budget = $cap - count( $pairs );
			if ( $budget <= 0 ) {
				$capped = true;
				break;
			}

			$walk = $this->walk( (array) $block['children'], (string) $block['post_type'], $old, $budget );
			foreach ( $walk['pairs'] as $pair ) {
				$pairs[] = $pair;
			}
			if ( $walk['capped'] ) {
				$capped = true;
				break;
			}
		}

		return [
			'pairs'      => array_slice( $pairs, 0, $cap ),
			'unresolved' => $unresolved,
			'capped'     => $capped || count( $pairs ) > $cap,
		];
	}

	/**
	 * One page of pairs as URLs relative to the home URL.
	 *
	 * @param array<int,array<string,mixed>> $blocks   Blocks.
	 * @param int                            $page     Page number, from 1.
	 * @param int                            $per_page Pairs per page.
	 * @return array{pairs:array<int,array{old:string,new:string}>,unresolved:array<int,array{old:string,reason:string}>,total:int,capped:bool,page:int,pages:int}
	 */
	public function page( array $blocks, int $page, int $per_page ): array {
		$all      = $this->collect( $blocks );
		$per_page = max( 1, $per_page );
		$total    = count( $all['pairs'] );
		$pages    = max( 1, (int) ceil( $total / $per_page ) );
		$page     = min( max( 1, $page ), $pages );
		$slice    = array_slice( $all['pairs'], ( $page - 1 ) * $per_page, $per_page );
		$type     = (string) ( $blocks[0]['post_type'] ?? 'page' );

		return [
			'pairs'      => $this->urls( $slice, $type ),
			'unresolved' => array_map(
				static fn( array $row ): array => [
					'old'    => Paths::relative( Paths::url( $type, $row['old'] ) ),
					'reason' => $row['reason'],
				],
				$all['unresolved']
			),
			'total'      => $total,
			'capped'     => $all['capped'],
			'page'       => $page,
			'pages'      => $pages,
		];
	}

	/**
	 * Every pair as URLs relative to the home URL, for a download.
	 *
	 * @param array<int,array<string,mixed>> $blocks Blocks.
	 * @return array<int,array{old:string,new:string}>
	 */
	public function all( array $blocks ): array {
		$type  = (string) ( $blocks[0]['post_type'] ?? 'page' );
		$pairs = $this->collect( $blocks )['pairs'];
		$out   = [];
		foreach ( array_chunk( $pairs, 100 ) as $chunk ) {
			foreach ( $this->urls( $chunk, $type ) as $row ) {
				$out[] = $row;
			}
		}
		return $out;
	}

	/**
	 * The number of pairs, stopping at the cap.
	 *
	 * @param array<int,array<string,mixed>> $blocks Blocks.
	 * @return array{count:int,capped:bool}
	 */
	public function count( array $blocks ): array {
		$all = $this->collect( $blocks );
		return [
			'count'  => count( $all['pairs'] ),
			'capped' => $all['capped'],
		];
	}

	/**
	 * Depth-first pairs below a block's children, level queries bounded by the budget.
	 *
	 * @param array<int,array{id:int,parent:int,segment:string,status:string}> $children Listed children.
	 * @param string                                                            $type     Post type name.
	 * @param string                                                            $old      Old path of the block.
	 * @param int                                                               $budget   Most pairs to return.
	 * @return array{pairs:array<int,array{old:string,id:int}>,capped:bool}
	 */
	private function walk( array $children, string $type, string $old, int $budget ): array {
		global $wpdb;

		$visited = count( $children );
		$capped  = $visited > $budget;
		$tops    = array_slice( $children, 0, $budget );
		$kids    = [];
		$level   = array_map( static fn( array $node ): int => (int) $node['id'], $tops );
		$depth   = 0;

		while ( [] !== $level && ! $capped && $depth < 50 ) {
			++$depth;
			$in = implode( ',', $level );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in holds integers only; LIMIT bounds the level by what is left of the budget.
			$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_parent, post_name, post_status FROM {$wpdb->posts} WHERE post_parent IN ( {$in} ) AND post_type = %s AND post_status NOT IN ( 'trash', 'auto-draft', 'inherit' ) ORDER BY menu_order ASC, post_title ASC, ID ASC LIMIT %d", $type, $budget + 1 - $visited ), ARRAY_A );
			$level = [];
			foreach ( (array) $rows as $row ) {
				$node                      = self::node( $row, (string) $row['post_name'] );
				$kids[ $node['parent'] ][] = $node;
				$level[]                   = $node['id'];
			}
			$visited += count( $level );
			if ( $visited > $budget ) {
				$capped = true;
			}
		}

		$pairs = [];
		$found = 0;
		$stack = array_reverse(
			array_map(
				static fn( array $node ): array => [ $node, $old ],
				$tops
			)
		);
		while ( [] !== $stack && $found < $budget ) {
			[ $node, $base ] = array_pop( $stack );
			// Core skips an empty slug when it builds a path, so the old path does too.
			$path = '' !== $node['segment'] ? $base . '/' . $node['segment'] : $base;
			if ( 'publish' === $node['status'] ) {
				$pairs[] = [
					'old' => $path,
					'id'  => $node['id'],
				];
				++$found;
			}
			foreach ( array_reverse( $kids[ $node['id'] ] ?? [] ) as $kid ) {
				$stack[] = [ $kid, $path ];
			}
		}

		return [
			'pairs'  => $pairs,
			'capped' => $capped || [] !== $stack,
		];
	}

	/**
	 * Old and new URLs for pairs, priming the posts first.
	 *
	 * @param array<int,array{old:string,id:int}> $pairs Pairs.
	 * @param string                              $type  Post type name.
	 * @return array<int,array{old:string,new:string}>
	 */
	private function urls( array $pairs, string $type ): array {
		_prime_post_caches( array_map( static fn( array $pair ): int => $pair['id'], $pairs ), false, false );

		$out = [];
		foreach ( $pairs as $pair ) {
			$new = get_permalink( $pair['id'] );
			if ( ! is_string( $new ) || '' === $new ) {
				continue;
			}
			$out[] = [
				'old' => Paths::relative( Paths::url( $type, $pair['old'] ) ),
				'new' => Paths::relative( $new ),
			];
		}
		return $out;
	}

	/**
	 * Subpages the trash moved up, with the slug each had under the root.
	 *
	 * @param int $root_id Root in the trash.
	 * @return array<int,array{id:int,parent:int,segment:string,status:string}>
	 */
	private static function moved_children( int $root_id ): array {
		global $wpdb;

		$original = [];
		foreach ( Markers::rows( Markers::moved_key( $root_id ), Subtree::count_cap() + 1 ) as $row ) {
			$original[ (int) $row['post_id'] ] = Markers::decode_moved( (string) $row['value'] )['original'];
		}
		if ( [] === $original ) {
			return [];
		}

		$in = implode( ',', array_keys( $original ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in holds integers only, at most the count cap of them.
		$rows = $wpdb->get_results( "SELECT ID, post_parent, post_name, post_status FROM {$wpdb->posts} WHERE ID IN ( {$in} ) AND post_status NOT IN ( 'trash', 'auto-draft', 'inherit' ) ORDER BY menu_order ASC, post_title ASC, ID ASC", ARRAY_A );

		$children = [];
		foreach ( (array) $rows as $row ) {
			$slug       = $original[ (int) $row['ID'] ] ?? '';
			$children[] = self::node( $row, '' !== $slug ? $slug : (string) $row['post_name'] );
		}
		return $children;
	}

	/**
	 * The given posts that are live children of a parent, at their current slugs.
	 * Used once a deleted root's moved markers are gone.
	 *
	 * @param int[]  $ids       Affected post IDs from the change.
	 * @param int    $parent_id Parent they moved under, 0 for top level.
	 * @param string $type      Post type name.
	 * @return array<int,array{id:int,parent:int,segment:string,status:string}>
	 */
	private static function children_among( array $ids, int $parent_id, string $type ): array {
		global $wpdb;

		$ids = array_values( array_filter( $ids ) );
		if ( [] === $ids ) {
			return [];
		}
		$in = implode( ',', $ids );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in holds integers only, at most Change_Log::MAX_IDS of them.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_parent, post_name, post_status FROM {$wpdb->posts} WHERE ID IN ( {$in} ) AND post_parent = %d AND post_type = %s AND post_status NOT IN ( 'trash', 'auto-draft', 'inherit' ) ORDER BY menu_order ASC, post_title ASC, ID ASC", $parent_id, $type ), ARRAY_A );

		$children = [];
		foreach ( (array) $rows as $row ) {
			$children[] = self::node( $row, (string) $row['post_name'] );
		}
		return $children;
	}

	/**
	 * A tree node from a posts row.
	 *
	 * @param array<string,mixed> $row     Row with ID, post_parent, post_name, post_status.
	 * @param string              $segment The node's segment in the old path.
	 * @return array{id:int,parent:int,segment:string,status:string}
	 */
	private static function node( array $row, string $segment ): array {
		return [
			'id'      => (int) $row['ID'],
			'parent'  => (int) $row['post_parent'],
			'segment' => Paths::normalize( $segment ),
			'status'  => (string) $row['post_status'],
		];
	}
}
```

- [ ] **Step 5: Run the tests, lint and the suite**

Run: `npm run test:php -- --filter PairsTest`
Expected: PASS.
Run: `composer lint:fix; composer lint && npm run test:php`
Expected: lint exits 0; the suite passes.

- [ ] **Step 6: Commit**

```bash
git add includes/HierarchyGuard/Redirects/Pairs.php includes/HierarchyGuard/Trash/Change_Log.php includes/HierarchyGuard/Trash/Trash_Handler.php tests/phpunit/PairsTest.php
git commit -m "feat: list the old and new URLs behind an old path"
```

### Task 3: CSV in redirect plugins' import layouts

**Files:**
- Create: `includes/HierarchyGuard/Export/Csv.php`
- Test: `tests/phpunit/CsvTest.php`

**Interfaces:**
- Produces: `Export\Csv` with `LAYOUTS` (`redirection`, `srm`, `generic`), `static layout( string $layout ): string` (unknown → `generic`), `static build( string $layout, array $pairs ): string` (header row then one row per `{old,new}` pair, CRLF), `static row( string $layout, string $old_url, string $new_url ): string[]`, `static line( string[] $cells ): string`, `static filename( string $layout ): string` (`hierarchy-guard-redirects-{layout}-{Y-m-d}.csv`, GMT date).

Redirection's importer reads columns in the order source, target, regex, code and skips a header row whose first two cells are `source` and `target`; Safe Redirect Manager's WP-CLI import uses the same column names. Every other detected plugin gets the generic layout, which a person maps by hand.

- [ ] **Step 1: Write the failing test**

Create `tests/phpunit/CsvTest.php`:
```php
<?php
/**
 * CSV files in redirect plugins' import layouts.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Export\Csv;
use WP_UnitTestCase;

/**
 * @covers \HierarchyGuard\Export\Csv
 */
final class CsvTest extends WP_UnitTestCase {

	/**
	 * Two pairs.
	 *
	 * @return array<int,array{old:string,new:string}>
	 */
	private function pairs(): array {
		return [
			[
				'old' => '/about/team/',
				'new' => '/company/team/',
			],
			[
				'old' => '/about/team/leadership/',
				'new' => '/company/team/leadership/',
			],
		];
	}

	public function test_redirection_reads_source_target_regex_and_code(): void {
		$this->assertSame(
			"source,target,regex,code\r\n/about/team/,/company/team/,0,301\r\n/about/team/leadership/,/company/team/leadership/,0,301\r\n",
			Csv::build( 'redirection', $this->pairs() )
		);
	}

	public function test_safe_redirect_manager_uses_the_same_columns(): void {
		$this->assertSame( Csv::build( 'redirection', $this->pairs() ), Csv::build( 'srm', $this->pairs() ) );
	}

	public function test_the_generic_layout_has_source_target_and_code(): void {
		$this->assertSame( "source,target,code\r\n/about/team/,/company/team/,301\r\n", Csv::build( 'generic', [ $this->pairs()[0] ] ) );
	}

	public function test_an_unknown_layout_falls_back_to_generic(): void {
		$this->assertSame( 'generic', Csv::layout( 'nope' ) );
		$this->assertSame( Csv::build( 'generic', $this->pairs() ), Csv::build( 'nope', $this->pairs() ) );
	}

	public function test_cells_are_quoted_only_when_they_need_it(): void {
		$this->assertSame( '/a/,"/b,c/","say ""hi""",301', Csv::line( [ '/a/', '/b,c/', 'say "hi"', '301' ] ) );
	}

	public function test_a_cell_a_spreadsheet_would_read_as_a_formula_is_defused(): void {
		$this->assertSame( "'=1+1,'@x,'-2", Csv::line( [ '=1+1', '@x', '-2' ] ) );
	}

	public function test_the_file_name_names_the_layout_and_the_day(): void {
		$this->assertSame( 'hierarchy-guard-redirects-redirection-' . gmdate( 'Y-m-d' ) . '.csv', Csv::filename( 'redirection' ) );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `npm run test:php -- --filter CsvTest`
Expected: FAIL with `Error: Class "HierarchyGuard\Export\Csv" not found`.

- [ ] **Step 3: Create Csv**

Create `includes/HierarchyGuard/Export/Csv.php`:
```php
<?php
/**
 * CSV files of redirect pairs in a redirect plugin's import layout.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Export;

/**
 * Builds the CSV a detected redirect plugin imports without editing.
 * Redirection and Safe Redirect Manager read source, target, regex and code;
 * every other plugin gets a plain source, target and code layout.
 */
final class Csv {

	public const LAYOUTS = [
		'redirection' => [ 'source', 'target', 'regex', 'code' ],
		'srm'         => [ 'source', 'target', 'regex', 'code' ],
		'generic'     => [ 'source', 'target', 'code' ],
	];

	/**
	 * A known layout name, falling back to generic.
	 *
	 * @param string $layout Requested layout.
	 * @return string
	 */
	public static function layout( string $layout ): string {
		return isset( self::LAYOUTS[ $layout ] ) ? $layout : 'generic';
	}

	/**
	 * The whole file: a header row, then one row per pair.
	 *
	 * @param string                                  $layout Layout name.
	 * @param array<int,array{old:string,new:string}> $pairs  Old and new URLs relative to the home URL.
	 * @return string
	 */
	public static function build( string $layout, array $pairs ): string {
		$layout = self::layout( $layout );
		$lines  = [ self::line( self::LAYOUTS[ $layout ] ) ];
		foreach ( $pairs as $pair ) {
			$lines[] = self::line( self::row( $layout, (string) $pair['old'], (string) $pair['new'] ) );
		}
		return implode( "\r\n", $lines ) . "\r\n";
	}

	/**
	 * One pair's cells in a layout.
	 *
	 * @param string $layout  Layout name.
	 * @param string $old_url Old URL.
	 * @param string $new_url New URL.
	 * @return string[]
	 */
	public static function row( string $layout, string $old_url, string $new_url ): array {
		$cells = [
			'source' => $old_url,
			'target' => $new_url,
			'regex'  => '0',
			'code'   => '301',
		];
		return array_map( static fn( string $column ): string => $cells[ $column ], self::LAYOUTS[ self::layout( $layout ) ] );
	}

	/**
	 * One CSV line, quoting a cell only when it needs it (RFC 4180). A cell
	 * that a spreadsheet would read as a formula gets a leading apostrophe.
	 *
	 * @param string[] $cells Cells.
	 * @return string
	 */
	public static function line( array $cells ): string {
		return implode(
			',',
			array_map(
				static function ( string $cell ): string {
					if ( '' !== $cell && in_array( $cell[0], [ '=', '+', '-', '@', "\t", "\r" ], true ) ) {
						$cell = "'" . $cell;
					}
					if ( strpbrk( $cell, ",\"\r\n" ) !== false ) {
						return '"' . str_replace( '"', '""', $cell ) . '"';
					}
					return $cell;
				},
				$cells
			)
		);
	}

	/**
	 * The download's file name.
	 *
	 * @param string $layout Layout name.
	 * @return string
	 */
	public static function filename( string $layout ): string {
		return sprintf( 'hierarchy-guard-redirects-%s-%s.csv', self::layout( $layout ), gmdate( 'Y-m-d' ) );
	}
}
```

- [ ] **Step 4: Run the tests, lint and the suite**

Run: `npm run test:php -- --filter CsvTest`
Expected: PASS.
Run: `composer lint:fix; composer lint && npm run test:php`
Expected: lint exits 0; the suite passes.

- [ ] **Step 5: Commit**

```bash
git add includes/HierarchyGuard/Export/Csv.php tests/phpunit/CsvTest.php
git commit -m "feat: CSV of redirect pairs in import layouts"
```

### Task 4: Hierarchy column, "View subpages" and the subtree filter

**Files:**
- Create: `includes/HierarchyGuard/Admin/List_Table.php`
- Modify: `includes/HierarchyGuard/Plugin.php`
- Test: `tests/phpunit/ListTableTest.php`

**Interfaces:**
- Consumes: Task 1's `Subtree::child_counts()` and `Subtree::descendant_ids()`; core's `_draft_or_post_title()` (loaded on list screens and in the Quick Edit Ajax save).
- Produces: `Admin\List_Table` with `QUERY_VAR = 'hierarchy_guard_under'`, `COLUMN = 'hierarchy_guard'`, `static filter_url( string $post_type, int $post_id ): string`, `static under(): int`, and hooks: `hook_types()` on `admin_init` (column filters per supported type), `row_actions()` on `page_row_actions`, `filter_request()` on `request`, `filter_controls()` on `restrict_manage_posts`.
- Produces: each cell prints `<span class="hierarchy-guard-cell" data-id data-children data-status>`. `data-children` is the live child count up to the cap plus one; the list-table script (Task 12) reads it to decide whether a Trash link needs the dialog.
- Produces: the filter prints `<input type="hidden" name="hierarchy_guard_under">` and `<span class="hierarchy-guard-under">Subpages of {ancestors} › <strong>{page}</strong> · Clear</span>` above the table; Task 12 moves the span beside the heading.

Hooking the column on `admin_init` rather than `load-edit.php` matters: core re-renders a row after a Quick Edit save in `admin-ajax.php`, which never fires `load-edit.php`.

- [ ] **Step 1: Write the failing test**

Create `tests/phpunit/ListTableTest.php`:
```php
<?php
/**
 * Hierarchy column, subtree filter and "View subpages".
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Admin\List_Table;
use HierarchyGuard\Post_Types;
use HierarchyGuard\Settings;
use WP_Query;
use WP_UnitTestCase;

/**
 * @covers \HierarchyGuard\Admin\List_Table
 */
final class ListTableTest extends WP_UnitTestCase {

	use Trash_Fixtures;

	/**
	 * Page IDs.
	 *
	 * @var array<string,int>
	 */
	private array $ids;

	private List_Table $table;

	public function set_up(): void {
		parent::set_up();
		require_once ABSPATH . 'wp-admin/includes/template.php';
		delete_option( Settings::OPTION );
		Post_Types::reset();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->ids   = $this->make_tree();
		$this->table = new List_Table();
		set_current_screen( 'edit-page' );
		$GLOBALS['pagenow'] = 'edit.php';
	}

	public function tear_down(): void {
		unset( $_GET[ List_Table::QUERY_VAR ], $GLOBALS['pagenow'] );
		$GLOBALS['wp_query'] = new WP_Query();
		set_current_screen( 'front' );
		parent::tear_down();
	}

	/**
	 * The cell the column prints for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private function cell( int $post_id ): string {
		ob_start();
		$this->table->render_column( List_Table::COLUMN, $post_id );
		return (string) ob_get_clean();
	}

	/**
	 * Puts posts in the main query, the way the list screen holds them.
	 *
	 * @param int[]  $ids    Post IDs.
	 * @param string $fields Query fields, '' or 'id=>parent'.
	 */
	private function screen_rows( array $ids, string $fields = '' ): void {
		$query               = new WP_Query();
		$query->query        = [ 'fields' => $fields ];
		$query->posts        = array_map(
			static function ( int $id ) use ( $fields ) {
				$post = get_post( $id );
				return 'id=>parent' === $fields ? (object) [
					'ID'          => $id,
					'post_parent' => (int) $post->post_parent,
				] : $post;
			},
			$ids
		);
		$GLOBALS['wp_query'] = $query;
	}

	public function test_the_column_comes_after_the_title(): void {
		$this->assertSame(
			[ 'cb', 'title', List_Table::COLUMN, 'author', 'date' ],
			array_keys(
				$this->table->columns(
					[
						'cb'     => '',
						'title'  => 'Title',
						'author' => 'Author',
						'date'   => 'Date',
					]
				)
			)
		);
	}

	public function test_the_column_is_added_to_supported_types_on_admin_init(): void {
		global $wp_filter;
		$hooked = false;
		foreach ( $wp_filter['admin_init']->callbacks[10] ?? [] as $callback ) {
			$hooked = $hooked || ( is_array( $callback['function'] ) && $callback['function'][0] instanceof List_Table && 'hook_types' === $callback['function'][1] );
		}
		$this->assertTrue( $hooked, 'admin_init also runs for the Quick Edit save over Ajax.' );

		$this->table->hook_types();

		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's own column filters.
		$this->assertArrayHasKey( List_Table::COLUMN, apply_filters( 'manage_page_posts_columns', [ 'title' => 'Title' ] ) );
		$this->assertArrayNotHasKey( List_Table::COLUMN, apply_filters( 'manage_post_posts_columns', [ 'title' => 'Title' ] ) );
		// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
	}

	public function test_a_cell_shows_the_parent_and_the_subpage_count(): void {
		$cell = $this->cell( $this->ids['about'] );

		$this->assertStringContainsString( 'data-children="2"', $cell );
		$this->assertStringContainsString( '>Company</a> · <a href="' . esc_url( List_Table::filter_url( 'page', $this->ids['about'] ) ) . '">2 subpages</a>', $cell );
	}

	public function test_a_cell_without_subpages_shows_only_the_parent(): void {
		$cell = $this->cell( $this->ids['leadership'] );

		$this->assertStringContainsString( 'data-children="0"', $cell );
		$this->assertStringContainsString( '>Team</a></span>', $cell );
	}

	public function test_a_top_level_page_without_subpages_shows_a_dash(): void {
		$cell = $this->cell( $this->page( 'lonely' ) );

		$this->assertStringContainsString( '<span aria-hidden="true">—</span>', $cell );
		$this->assertStringContainsString( 'Top level, no subpages', $cell );
	}

	public function test_a_parent_in_the_trash_is_marked(): void {
		$this->trash_raw( $this->ids['team'] );

		$this->assertStringContainsString( 'Team</a> (in the Trash)', $this->cell( $this->ids['leadership'] ) );
	}

	public function test_counts_above_the_cap_show_a_plus(): void {
		add_filter( 'hierarchy_guard_count_cap', static fn(): int => 1 );

		$cell = $this->cell( $this->ids['about'] );

		$this->assertStringContainsString( '1+ subpage</a>', $cell );
		$this->assertStringContainsString( 'data-children="2"', $cell );
	}

	public function test_a_screen_of_rows_is_counted_in_one_query(): void {
		$this->screen_rows( [ $this->ids['company'], $this->ids['about'], $this->ids['team'], $this->ids['history'] ] );

		$queries = get_num_queries();
		foreach ( [ 'company', 'about', 'team', 'history' ] as $slug ) {
			$this->cell( $this->ids[ $slug ] );
		}

		$this->assertSame( 1, get_num_queries() - $queries );
	}

	public function test_core_s_tree_mode_is_counted_without_a_query(): void {
		$this->screen_rows( array_values( $this->ids ), 'id=>parent' );

		$queries = get_num_queries();
		$cells   = array_map( [ $this, 'cell' ], [ $this->ids['about'], $this->ids['team'] ] );

		$this->assertSame( 0, get_num_queries() - $queries );
		$this->assertStringContainsString( 'data-children="2"', $cells[0] );
		$this->assertStringContainsString( 'data-children="1"', $cells[1] );
	}

	public function test_rows_with_subpages_get_view_subpages(): void {
		$actions = $this->table->row_actions( [ 'edit' => 'Edit' ], get_post( $this->ids['about'] ) );

		$this->assertArrayHasKey( 'hierarchy_guard_subpages', $actions );
		$this->assertStringContainsString( 'hierarchy_guard_under=' . $this->ids['about'], $actions['hierarchy_guard_subpages'] );
		$this->assertSame( [ 'edit' => 'Edit' ], $this->table->row_actions( [ 'edit' => 'Edit' ], get_post( $this->ids['leadership'] ) ) );
	}

	public function test_the_filter_lists_the_subtree_in_paginated_rows(): void {
		$_GET[ List_Table::QUERY_VAR ] = (string) $this->ids['about'];

		$vars = $this->table->filter_request(
			[
				'post_type'              => 'page',
				'orderby'                => 'menu_order title',
				'order'                  => 'asc',
				'posts_per_page'         => -1,
				'posts_per_archive_page' => -1,
				'fields'                 => 'id=>parent',
			]
		);

		$this->assertSame( [ $this->ids['history'], $this->ids['team'], $this->ids['leadership'] ], $vars['post__in'] );
		$this->assertSame(
			[
				'menu_order' => 'ASC',
				'title'      => 'ASC',
			],
			$vars['orderby'],
			'Not core’s tree mode, which loads every page.'
		);
		$this->assertSame( 20, $vars['posts_per_page'] );
		$this->assertArrayNotHasKey( 'fields', $vars );
	}

	public function test_a_sorted_list_keeps_its_sort(): void {
		$_GET[ List_Table::QUERY_VAR ] = (string) $this->ids['about'];

		$vars = $this->table->filter_request(
			[
				'post_type'      => 'page',
				'orderby'        => 'date',
				'posts_per_page' => 20,
			]
		);

		$this->assertSame( 'date', $vars['orderby'] );
		$this->assertCount( 3, $vars['post__in'] );
	}

	public function test_a_page_without_subpages_filters_to_nothing(): void {
		$_GET[ List_Table::QUERY_VAR ] = (string) $this->ids['leadership'];

		$this->assertSame( [ 0 ], $this->table->filter_request( [ 'post_type' => 'page' ] )['post__in'] );
	}

	public function test_the_filter_ignores_other_screens_and_types(): void {
		$_GET[ List_Table::QUERY_VAR ] = (string) $this->ids['about'];
		$vars                          = [ 'post_type' => 'page' ];

		$GLOBALS['pagenow'] = 'upload.php';
		$this->assertSame( $vars, $this->table->filter_request( $vars ) );

		$GLOBALS['pagenow'] = 'edit.php';
		$this->assertSame( [ 'post_type' => 'post' ], $this->table->filter_request( [ 'post_type' => 'post' ] ) );

		$_GET[ List_Table::QUERY_VAR ] = (string) self::factory()->post->create();
		$this->assertSame( $vars, $this->table->filter_request( $vars ), 'The filtered ID is not a page.' );
	}

	public function test_the_filter_stops_at_the_cap_and_says_so(): void {
		add_filter( 'hierarchy_guard_count_cap', static fn(): int => 2 );
		$_GET[ List_Table::QUERY_VAR ] = (string) $this->ids['about'];

		$this->assertCount( 2, $this->table->filter_request( [ 'post_type' => 'page' ] )['post__in'] );

		ob_start();
		$this->table->filter_controls( 'page', 'top' );
		$this->assertStringContainsString( '(the first 2)', (string) ob_get_clean() );
	}

	public function test_the_filter_controls_keep_the_filter_and_show_the_ancestors(): void {
		$_GET[ List_Table::QUERY_VAR ] = (string) $this->ids['team'];

		ob_start();
		$this->table->filter_controls( 'page', 'top' );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<input type="hidden" name="hierarchy_guard_under" value="' . $this->ids['team'] . '">', $html );
		$this->assertStringContainsString( 'Subpages of <a href="' . esc_url( List_Table::filter_url( 'page', $this->ids['company'] ) ) . '">Company</a> › <a href="' . esc_url( List_Table::filter_url( 'page', $this->ids['about'] ) ) . '">About</a> › <strong>Team</strong>', $html );
		$this->assertStringContainsString( '>Clear</a>', $html );

		ob_start();
		$this->table->filter_controls( 'page', 'bottom' );
		$this->assertSame( '', (string) ob_get_clean(), 'Printed once, above the table.' );
	}

	public function test_core_s_list_query_returns_the_subtree_in_flat_rows(): void {
		require_once ABSPATH . 'wp-admin/includes/post.php';
		$_GET = [
			'post_type'           => 'page',
			List_Table::QUERY_VAR => (string) $this->ids['about'],
		];

		wp_edit_posts_query( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The list screen's own request, built above.

		$this->assertEqualSets( [ $this->ids['history'], $this->ids['team'], $this->ids['leadership'] ], wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' ) );
		$this->assertNotSame( 'menu_order title', $GLOBALS['wp_query']->query['orderby'] );
		$_GET = [];
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `npm run test:php -- --filter ListTableTest`
Expected: FAIL with `Error: Class "HierarchyGuard\Admin\List_Table" not found`.

- [ ] **Step 3: Create List_Table**

Create `includes/HierarchyGuard/Admin/List_Table.php`:
```php
<?php
/**
 * Hierarchy column, subtree filter and "View subpages" on the list screen.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Admin;

use HierarchyGuard\Post_Types;
use HierarchyGuard\Subtree;
use WP_Post;
use WP_Query;

/**
 * Adds a one-line Hierarchy column to every supported list screen, a
 * "View subpages" row action, and a filter that narrows the list to one
 * page's subtree. Child counts for a screen cost one query; the filter is one
 * capped walk. Runs on list screens, where core's list-table helpers such as
 * _draft_or_post_title() are loaded.
 */
final class List_Table {

	public const QUERY_VAR = 'hierarchy_guard_under';
	public const COLUMN    = 'hierarchy_guard';

	/**
	 * Live child counts by post ID for the rows on this screen.
	 *
	 * @var array<int,int>
	 */
	private array $counts = [];

	/**
	 * Whether the screen's rows were counted.
	 *
	 * @var bool
	 */
	private bool $counted = false;

	/**
	 * The subtree filter's walk, once run.
	 *
	 * @var array{ids:int[],capped:bool}|null
	 */
	private ?array $walk = null;

	/**
	 * Hooks into core.
	 */
	public function register(): void {
		add_action( 'admin_init', [ $this, 'hook_types' ] );
		add_filter( 'page_row_actions', [ $this, 'row_actions' ], 10, 2 );
		add_filter( 'request', [ $this, 'filter_request' ] );
		add_action( 'restrict_manage_posts', [ $this, 'filter_controls' ], 10, 2 );
	}

	/**
	 * Adds the column to every supported type. Hooked on admin_init rather
	 * than load-edit.php so the row Quick Edit re-renders over Ajax has it too.
	 */
	public function hook_types(): void {
		foreach ( Post_Types::supported() as $type ) {
			add_filter( "manage_{$type}_posts_columns", [ $this, 'columns' ] );
			add_action( "manage_{$type}_posts_custom_column", [ $this, 'render_column' ], 10, 2 );
		}
	}

	/**
	 * The list URL filtered to a page's subtree.
	 *
	 * @param string $post_type Post type name.
	 * @param int    $post_id   Page whose subpages to list.
	 * @return string
	 */
	public static function filter_url( string $post_type, int $post_id ): string {
		return add_query_arg(
			[
				'post_type'     => $post_type,
				self::QUERY_VAR => $post_id,
			],
			admin_url( 'edit.php' )
		);
	}

	/**
	 * The page the list is filtered to, or 0.
	 *
	 * @return int
	 */
	public static function under(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A read-only list filter, like core's own list filters.
		return isset( $_GET[ self::QUERY_VAR ] ) ? absint( wp_unslash( $_GET[ self::QUERY_VAR ] ) ) : 0;
	}

	/**
	 * Adds the Hierarchy column after the title.
	 *
	 * @param mixed $columns Columns.
	 * @return mixed
	 */
	public function columns( $columns ) {
		if ( ! is_array( $columns ) ) {
			return $columns;
		}
		$out = [];
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'title' === $key ) {
				$out[ self::COLUMN ] = __( 'Hierarchy', 'hierarchy-guard' );
			}
		}
		if ( ! isset( $out[ self::COLUMN ] ) ) {
			$out[ self::COLUMN ] = __( 'Hierarchy', 'hierarchy-guard' );
		}
		return $out;
	}

	/**
	 * Prints the parent, then the subpage count as a link to the subtree.
	 *
	 * @param mixed $column  Column name.
	 * @param mixed $post_id Post ID.
	 */
	public function render_column( $column, $post_id ): void {
		if ( self::COLUMN !== $column ) {
			return;
		}
		$post = get_post( (int) $post_id );
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		$type     = (string) $post->post_type;
		$count    = $this->child_count( (int) $post->ID, $type );
		$cap      = Subtree::count_cap();
		$parts    = [];
		$parent   = (int) $post->post_parent;
		$readable = $parent > 0 ? get_post( $parent ) : null;

		if ( $readable instanceof WP_Post ) {
			$title = _draft_or_post_title( $parent );
			$link  = get_edit_post_link( $parent );
			$name  = is_string( $link ) && '' !== $link ? sprintf( '<a href="%s">%s</a>', esc_url( $link ), $title ) : $title;
			if ( 'trash' === $readable->post_status ) {
				/* translators: %s: title of a parent page that is in the trash. */
				$name = sprintf( __( '%s (in the Trash)', 'hierarchy-guard' ), $name );
			}
			$parts[] = $name;
		}
		if ( $count > 0 ) {
			$shown = number_format_i18n( min( $count, $cap ) ) . ( $count > $cap ? '+' : '' );
			/* translators: %s: number of subpages, such as 3 or 1,000+. */
			$label   = sprintf( _n( '%s subpage', '%s subpages', min( $count, $cap ), 'hierarchy-guard' ), $shown );
			$parts[] = sprintf( '<a href="%s">%s</a>', esc_url( self::filter_url( $type, (int) $post->ID ) ), esc_html( $label ) );
		}

		printf(
			'<span class="hierarchy-guard-cell" data-id="%1$d" data-children="%2$d" data-status="%3$s">%4$s</span>',
			(int) $post->ID,
			(int) min( $count, $cap + 1 ),
			esc_attr( (string) $post->post_status ),
			[] !== $parts ? implode( ' · ', $parts ) : '<span aria-hidden="true">—</span><span class="screen-reader-text">' . esc_html__( 'Top level, no subpages', 'hierarchy-guard' ) . '</span>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Every part was escaped as it was built.
		);
	}

	/**
	 * Adds "View subpages" to rows with subpages.
	 *
	 * @param mixed $actions Row actions.
	 * @param mixed $post    Post.
	 * @return mixed
	 */
	public function row_actions( $actions, $post ) {
		if ( ! is_array( $actions ) || ! $post instanceof WP_Post || ! Post_Types::is_supported( (string) $post->post_type ) ) {
			return $actions;
		}
		if ( 0 === $this->child_count( (int) $post->ID, (string) $post->post_type ) ) {
			return $actions;
		}

		$actions['hierarchy_guard_subpages'] = sprintf(
			'<a href="%1$s" aria-label="%2$s">%3$s</a>',
			esc_url( self::filter_url( (string) $post->post_type, (int) $post->ID ) ),
			/* translators: %s: page title. */
			esc_attr( sprintf( __( 'View the subpages of “%s”', 'hierarchy-guard' ), _draft_or_post_title( $post ) ) ),
			esc_html__( 'View subpages', 'hierarchy-guard' )
		);
		return $actions;
	}

	/**
	 * Narrows the list query to the filtered page's subtree, and asks for a
	 * sort order so core renders normal paginated rows rather than loading
	 * every page for its indented tree.
	 *
	 * @param mixed $vars Query vars.
	 * @return mixed
	 */
	public function filter_request( $vars ) {
		if ( ! is_array( $vars ) || ! is_admin() || 'edit.php' !== ( $GLOBALS['pagenow'] ?? '' ) ) {
			return $vars;
		}
		$root = self::under();
		$type = (string) ( $vars['post_type'] ?? '' );
		if ( 0 === $root || ! Post_Types::is_supported( $type ) || get_post_type( $root ) !== $type ) {
			return $vars;
		}

		$walk             = $this->walk( $root, $type );
		$vars['post__in'] = [] !== $walk['ids'] ? $walk['ids'] : [ 0 ];

		if ( 'menu_order title' === ( $vars['orderby'] ?? '' ) ) {
			$vars['orderby'] = [
				'menu_order' => 'ASC',
				'title'      => 'ASC',
			];
			unset( $vars['fields'], $vars['posts_per_archive_page'] );
			$vars['posts_per_page'] = self::per_page( $type );
		}

		return $vars;
	}

	/**
	 * Keeps the filter across the list's own filters, and says what is filtered.
	 * The list-table script moves the sentence beside the heading, the way core
	 * shows search results.
	 *
	 * @param mixed $post_type Post type of the list.
	 * @param mixed $which     Which table navigation, top or bottom.
	 */
	public function filter_controls( $post_type, $which = 'top' ): void {
		$root = self::under();
		$type = (string) $post_type;
		if ( 'top' !== $which || 0 === $root || ! Post_Types::is_supported( $type ) || get_post_type( $root ) !== $type ) {
			return;
		}

		$crumbs = [];
		foreach ( array_reverse( get_post_ancestors( $root ) ) as $ancestor_id ) {
			$crumbs[] = sprintf( '<a href="%s">%s</a>', esc_url( self::filter_url( $type, (int) $ancestor_id ) ), _draft_or_post_title( (int) $ancestor_id ) );
		}
		$crumbs[] = '<strong>' . _draft_or_post_title( $root ) . '</strong>';

		$walk  = $this->walk( $root, $type );
		$clear = remove_query_arg( [ self::QUERY_VAR, 'paged' ] );

		printf( '<input type="hidden" name="%1$s" value="%2$d">', esc_attr( self::QUERY_VAR ), (int) $root );
		echo '<span class="hierarchy-guard-under">';
		/* translators: %s: the filtered page, after its ancestors, such as "Company › About". */
		printf( esc_html__( 'Subpages of %s', 'hierarchy-guard' ), implode( ' › ', $crumbs ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Every crumb was escaped as it was built.
		if ( $walk['capped'] ) {
			echo ' ' . esc_html(
				sprintf(
					/* translators: %s: the most subpages the filter lists, such as 1,000. */
					__( '(the first %s)', 'hierarchy-guard' ),
					number_format_i18n( Subtree::count_cap() )
				)
			);
		}
		printf( ' · <a href="%1$s">%2$s</a>', esc_url( $clear ), esc_html__( 'Clear', 'hierarchy-guard' ) );
		echo '</span>';
	}

	/**
	 * A row's live child count, counting the whole screen the first time.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $post_type Post type name.
	 * @return int Count, up to the cap plus one.
	 */
	private function child_count( int $post_id, string $post_type ): int {
		if ( ! array_key_exists( $post_id, $this->counts ) && ! $this->counted ) {
			$this->counted = true;
			$this->counts += $this->screen_counts( $post_type );
		}
		if ( ! array_key_exists( $post_id, $this->counts ) ) {
			// A row the screen query did not return, such as a Quick Edit save over Ajax.
			$this->counts += Subtree::child_counts( [ $post_id ], $post_type, Subtree::count_cap() + 1 );
		}
		return $this->counts[ $post_id ] ?? 0;
	}

	/**
	 * Live child counts for the screen's rows.
	 *
	 * In core's indented tree mode the query already holds the ID and parent of
	 * every page in the view, so children are counted from it without a query.
	 * Otherwise the rows on this page are counted in one statement.
	 *
	 * @param string $post_type Post type name.
	 * @return array<int,int>
	 */
	private function screen_counts( string $post_type ): array {
		global $wp_query;

		if ( ! $wp_query instanceof WP_Query || empty( $wp_query->posts ) ) {
			return [];
		}

		$ids = [];
		foreach ( $wp_query->posts as $row ) {
			if ( is_object( $row ) && isset( $row->ID ) ) {
				$ids[] = (int) $row->ID;
			}
		}

		$tree = 'id=>parent' === ( $wp_query->query['fields'] ?? '' ) && empty( $wp_query->query['s'] );
		if ( $tree ) {
			$counts = array_fill_keys( $ids, 0 );
			foreach ( $wp_query->posts as $row ) {
				$parent = is_object( $row ) && isset( $row->post_parent ) ? (int) $row->post_parent : 0;
				if ( $parent > 0 && isset( $counts[ $parent ] ) ) {
					++$counts[ $parent ];
				}
			}
			return $counts;
		}

		return Subtree::child_counts( array_slice( $ids, 0, self::per_page( $post_type ) ), $post_type, Subtree::count_cap() + 1 );
	}

	/**
	 * The filter's capped walk, run once per request.
	 *
	 * @param int    $root_id   Filtered page.
	 * @param string $post_type Post type name.
	 * @return array{ids:int[],capped:bool}
	 */
	private function walk( int $root_id, string $post_type ): array {
		if ( null === $this->walk ) {
			$this->walk = Subtree::descendant_ids( $root_id, $post_type, Subtree::count_cap() );
		}
		return $this->walk;
	}

	/**
	 * Rows per page on the list screen, as core works it out.
	 *
	 * @param string $post_type Post type name.
	 * @return int
	 */
	private static function per_page( string $post_type ): int {
		$per_page = (int) get_user_option( "edit_{$post_type}_per_page" );
		if ( $per_page < 1 ) {
			$per_page = 20;
		}
		/** This filter is documented in wp-admin/includes/post.php */
		$per_page = (int) apply_filters( "edit_{$post_type}_per_page", $per_page ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound,WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Core's own filter.
		/** This filter is documented in wp-admin/includes/post.php */
		return max( 1, (int) apply_filters( 'edit_posts_per_page', $per_page, $post_type ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's own filter.
	}
}
```

- [ ] **Step 4: Wire it**

In `includes/HierarchyGuard/Plugin.php`, replace:
```php
		( new Admin\Trash_Notice( $log ) )->register();
```
with:
```php
		( new Admin\Trash_Notice( $log ) )->register();
		( new Admin\List_Table() )->register();
```

- [ ] **Step 5: Run the tests, lint and the suite**

Run: `npm run test:php -- --filter ListTableTest`
Expected: PASS.
Run: `composer lint:fix; composer lint && npm run test:php`
Expected: lint exits 0; the suite passes.

- [ ] **Step 6: Commit**

```bash
git add includes/HierarchyGuard/Admin/List_Table.php includes/HierarchyGuard/Plugin.php tests/phpunit/ListTableTest.php
git commit -m "feat: Hierarchy column, View subpages and the subtree filter"
```

### Task 5: The hierarchy route, page pairs, and CSV downloads

**Files:**
- Create: `includes/HierarchyGuard/Overview.php`, `includes/HierarchyGuard/REST/Hierarchy_Controller.php`, `includes/HierarchyGuard/REST/Csv_Download.php`
- Modify: `includes/HierarchyGuard/Plugin.php`
- Test: `tests/phpunit/HierarchyControllerTest.php`

**Interfaces:**
- Consumes: Task 1, Task 2's `Pairs::post_block()`, `Pairs::page()`, `Pairs::all()`, Task 3's `Csv`, Task 4's `List_Table::filter_url()`; plan 1's `Ledger::paths_for()`, `Providers::detect()`; plan 2's `Trash_Handler::policy_for()`, `Subtree::count_live_children()`, `Subtree::count_live_descendants()`, `Subtree::nearest_live_ancestor()`.
- Produces: `Overview` with `CHILDREN_PER_PAGE = 10`, `MAX_OLD_PATHS = 20`, `describe( WP_Post $post, int $children_page = 1 ): array` (keys `id`, `status`, `ancestors` top level first, `children` (the page's readable ones, each with `id`, `title`, `edit_link`, `status`, `status_label`, `children_count`, `children_capped`, `view_link`), `children_total`, `children_capped`, `has_more`, `descendants{count,capped}`, `old_paths` newest first as `{path,url}`, `redirects{serving,provider{name,url,csv}|null}`, `trash_policy`, `trash_fallback`, `trash_to{id,title,edit_link}|null`, `list_url`), `static title( int $post_id ): string` (plain text, "(no title)" when empty), `static ref( int $post_id ): array{id:int,title:string,edit_link:string|null}`.
- Produces: `REST\Hierarchy_Controller` with `NAMESPACE = 'hierarchy-guard/v1'`, routes `GET posts/(?P<id>\d+)/hierarchy` (`children_page`), `GET posts/(?P<id>\d+)/pairs` (`from` required, `page`, `per_page` ≤ 1,000), `GET posts/(?P<id>\d+)/csv` (`from`, `format`), all behind `can_edit()`. `from` must be one of the post's recorded old paths.
- Produces: `REST\Csv_Download` with `FLAG = 'X-Hierarchy-Guard-Csv'`, `static response( string $layout, array $pairs ): WP_REST_Response` and `serve()` on `rest_pre_serve_request`, which prints a flagged 200 response's string body (nothing for HEAD) and returns true.
- Produces: `Plugin::register()` creates `$providers`, `$pairs` and `$overview` once; Tasks 6 to 10 pass them on.

- [ ] **Step 1: Write the failing test**

Create `tests/phpunit/HierarchyControllerTest.php`:
```php
<?php
/**
 * REST routes about one post's hierarchy.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Ledger;
use HierarchyGuard\Overview;
use HierarchyGuard\Post_Types;
use HierarchyGuard\REST\Csv_Download;
use HierarchyGuard\Settings;
use HierarchyGuard\Trash\Change_Log;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * @covers \HierarchyGuard\REST\Hierarchy_Controller
 * @covers \HierarchyGuard\REST\Csv_Download
 * @covers \HierarchyGuard\Overview
 */
final class HierarchyControllerTest extends WP_UnitTestCase {

	use Trash_Fixtures;

	/**
	 * Page IDs.
	 *
	 * @var array<string,int>
	 */
	private array $ids;

	private int $admin;

	public function set_up(): void {
		parent::set_up();
		delete_option( Settings::OPTION );
		delete_option( Ledger::ROOT_OPTION );
		Post_Types::reset();
		Change_Log::reset_request();
		$this->set_permalink_structure( '/%postname%/' );
		$this->admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $this->admin );
		$this->ids = $this->make_tree();
	}

	public function tear_down(): void {
		$this->set_permalink_structure( '' );
		parent::tear_down();
	}

	/**
	 * Runs a GET request.
	 *
	 * @param string              $route  Route under the plugin's namespace.
	 * @param array<string,mixed> $params Query parameters.
	 * @return WP_REST_Response
	 */
	private function get( string $route, array $params = [] ): WP_REST_Response {
		$request = new WP_REST_Request( 'GET', '/hierarchy-guard/v1' . $route );
		$request->set_query_params( $params );
		return rest_do_request( $request );
	}

	/**
	 * Renames a page, which records its old path.
	 *
	 * @param int    $post_id Page.
	 * @param string $slug    New slug.
	 */
	private function rename( int $post_id, string $slug ): void {
		wp_update_post(
			[
				'ID'        => $post_id,
				'post_name' => $slug,
			]
		);
	}

	public function test_the_hierarchy_route_needs_edit_rights_on_a_supported_post(): void {
		$route = '/posts/' . $this->ids['about'] . '/hierarchy';

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$this->assertSame( 403, $this->get( $route )->get_status() );

		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->get( $route )->get_status() );

		wp_set_current_user( $this->admin );
		$this->assertSame( 404, $this->get( '/posts/999999/hierarchy' )->get_status() );
		$this->assertSame( 404, $this->get( '/posts/' . self::factory()->post->create() . '/hierarchy' )->get_status(), 'Posts are not hierarchical.' );
		$this->assertSame( 200, $this->get( $route )->get_status() );
	}

	public function test_the_hierarchy_of_a_page(): void {
		$data = $this->get( '/posts/' . $this->ids['about'] . '/hierarchy' )->get_data();

		$this->assertSame( [ 'Company' ], array_column( $data['ancestors'], 'title' ) );
		$this->assertSame( [ 'History', 'Team' ], array_column( $data['children'], 'title' ) );
		$this->assertSame( [ 0, 1 ], array_column( $data['children'], 'children_count' ) );
		$this->assertSame( home_url( '/company/about/team/' ), $data['children'][1]['view_link'] );
		$this->assertSame( 2, $data['children_total'] );
		$this->assertFalse( $data['has_more'] );
		$this->assertSame(
			[
				'count'  => 3,
				'capped' => false,
			],
			$data['descendants']
		);
		$this->assertSame( [], $data['old_paths'] );
		$this->assertSame(
			[
				'serving'  => false,
				'provider' => null,
			],
			$data['redirects']
		);
		$this->assertSame( Settings::POLICY_REPARENT, $data['trash_policy'] );
		$this->assertSame( 'Company', $data['trash_to']['title'] );
		$this->assertStringContainsString( 'hierarchy_guard_under=' . $this->ids['about'], $data['list_url'] );
	}

	public function test_children_are_paged(): void {
		for ( $i = 1; $i <= 11; $i++ ) {
			$this->page( 'extra-' . $i, $this->ids['about'] );
		}

		$first  = $this->get( '/posts/' . $this->ids['about'] . '/hierarchy' )->get_data();
		$second = $this->get( '/posts/' . $this->ids['about'] . '/hierarchy', [ 'children_page' => 2 ] )->get_data();

		$this->assertCount( Overview::CHILDREN_PER_PAGE, $first['children'] );
		$this->assertTrue( $first['has_more'] );
		$this->assertCount( 3, $second['children'] );
		$this->assertFalse( $second['has_more'] );
		$this->assertSame( 13, $second['children_total'] );
	}

	public function test_a_child_the_user_may_not_read_is_left_out(): void {
		$hidden = $this->ids['history'];
		add_filter(
			'map_meta_cap',
			static function ( array $caps, string $cap, int $user_id, array $args ) use ( $hidden ): array {
				return 'read_post' === $cap && isset( $args[0] ) && $hidden === (int) $args[0] ? [ 'do_not_allow' ] : $caps;
			},
			10,
			4
		);

		$data = $this->get( '/posts/' . $this->ids['about'] . '/hierarchy' )->get_data();

		$this->assertSame( [ 'Team' ], array_column( $data['children'], 'title' ) );
	}

	public function test_old_paths_are_listed_newest_first(): void {
		$this->rename( $this->ids['about'], 'about-us' );
		$this->rename( $this->ids['about'], 'who-we-are' );

		$data = $this->get( '/posts/' . $this->ids['about'] . '/hierarchy' )->get_data();

		$this->assertSame( [ 'company/about-us', 'company/about' ], array_column( $data['old_paths'], 'path' ) );
		$this->assertSame( '/company/about-us/', $data['old_paths'][0]['url'] );
	}

	public function test_the_trash_policy_shows_the_fallback_for_a_large_subtree(): void {
		add_filter( 'hierarchy_guard_trash_policy', static fn(): string => Settings::POLICY_TRASH_CHILDREN );
		add_filter( 'hierarchy_guard_count_cap', static fn(): int => 2 );

		$data = $this->get( '/posts/' . $this->ids['about'] . '/hierarchy' )->get_data();

		$this->assertSame( Settings::POLICY_REPARENT, $data['trash_policy'] );
		$this->assertTrue( $data['trash_fallback'] );
		$this->assertTrue( $data['descendants']['capped'] );
	}

	public function test_pairs_for_an_old_path_recorded_on_the_page(): void {
		$this->rename( $this->ids['about'], 'about-us' );

		$data = $this->get(
			'/posts/' . $this->ids['about'] . '/pairs',
			[ 'from' => 'company/about' ]
		)->get_data();

		$this->assertSame( 4, $data['total'] );
		$this->assertSame(
			[
				'old' => '/company/about/',
				'new' => '/company/about-us/',
			],
			$data['pairs'][0]
		);
	}

	public function test_pairs_for_a_path_not_recorded_on_the_page_are_refused(): void {
		$response = $this->get(
			'/posts/' . $this->ids['about'] . '/pairs',
			[ 'from' => 'somewhere/else' ]
		);

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'hierarchy_guard_unknown_path', $response->get_data()['code'] );
	}

	public function test_the_csv_route_answers_with_a_flagged_csv_body(): void {
		$this->rename( $this->ids['about'], 'about-us' );
		$request = new WP_REST_Request( 'GET', '/hierarchy-guard/v1/posts/' . $this->ids['about'] . '/csv' );
		$request->set_query_params(
			[
				'from'   => 'company/about',
				'format' => 'redirection',
			]
		);

		$response = rest_do_request( $request );
		$headers  = $response->get_headers();

		$this->assertSame( 'text/csv; charset=utf-8', $headers['Content-Type'] );
		$this->assertStringContainsString( 'attachment; filename="hierarchy-guard-redirects-redirection-', $headers['Content-Disposition'] );
		$this->assertStringStartsWith( "source,target,regex,code\r\n/company/about/,/company/about-us/,0,301\r\n", $response->get_data() );

		ob_start();
		$served = ( new Csv_Download() )->serve( false, $response, $request );
		$body   = (string) ob_get_clean();

		$this->assertTrue( $served );
		$this->assertSame( $response->get_data(), $body );
	}

	public function test_a_json_response_is_left_to_the_server(): void {
		$request  = new WP_REST_Request( 'GET', '/hierarchy-guard/v1/posts/' . $this->ids['about'] . '/hierarchy' );
		$response = rest_do_request( $request );

		ob_start();
		$served = ( new Csv_Download() )->serve( false, $response, $request );
		ob_end_clean();

		$this->assertFalse( $served );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `npm run test:php -- --filter HierarchyControllerTest`
Expected: FAIL: the routes are not registered (`rest_no_route`), and the CSV tests stop at `Error: Class "HierarchyGuard\REST\Csv_Download" not found`.

- [ ] **Step 3: Create Overview**

Create `includes/HierarchyGuard/Overview.php`:
```php
<?php
/**
 * What sits above and below a page, for the editor panel and the meta box.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard;

use HierarchyGuard\Admin\List_Table;
use HierarchyGuard\Redirects\Providers;
use HierarchyGuard\Trash\Trash_Handler;
use WP_Post;

/**
 * Builds the hierarchy summary of one post. Children are paged, counts are
 * direct, and every total stops at the count cap.
 */
final class Overview {

	public const CHILDREN_PER_PAGE = 10;
	public const MAX_OLD_PATHS     = 20;

	/**
	 * Ledger.
	 *
	 * @var Ledger
	 */
	private Ledger $ledger;

	/**
	 * Providers.
	 *
	 * @var Providers
	 */
	private Providers $providers;

	/**
	 * Constructor.
	 *
	 * @param Ledger    $ledger    Ledger.
	 * @param Providers $providers Redirect plugin detection.
	 */
	public function __construct( Ledger $ledger, Providers $providers ) {
		$this->ledger    = $ledger;
		$this->providers = $providers;
	}

	/**
	 * The summary of one post.
	 *
	 * @param WP_Post $post          Post.
	 * @param int     $children_page Page of children, from 1.
	 * @return array<string,mixed>
	 */
	public function describe( WP_Post $post, int $children_page = 1 ): array {
		$id   = (int) $post->ID;
		$type = (string) $post->post_type;
		$cap  = Subtree::count_cap();

		$children_total = Subtree::count_live_children( $id, $type, $cap + 1 );
		$descendants    = $children_total > 0 ? Subtree::count_live_descendants( $id, $type, $cap ) : 0;
		$children       = $this->children( $id, $type, max( 1, $children_page ) );
		$policy         = Trash_Handler::policy_for( $post );
		$fallback       = Settings::POLICY_TRASH_CHILDREN === $policy && $descendants > $cap;
		$to             = Subtree::nearest_live_ancestor( $post );
		$provider       = $this->providers->detect();

		return [
			'id'              => $id,
			'status'          => (string) $post->post_status,
			'ancestors'       => $this->ancestors( $post ),
			'children'        => $children,
			'children_total'  => min( $children_total, $cap ),
			'children_capped' => $children_total > $cap,
			'has_more'        => $children_page * self::CHILDREN_PER_PAGE < min( $children_total, $cap ),
			'descendants'     => [
				'count'  => min( $descendants, $cap ),
				'capped' => $descendants > $cap,
			],
			'old_paths'       => $this->old_paths( $id, $type ),
			'redirects'       => [
				'serving'  => Settings::serve_redirects(),
				'provider' => null === $provider ? null : [
					'name' => (string) ( $provider['name'] ?? '' ),
					'url'  => (string) ( $provider['url'] ?? '' ),
					'csv'  => (string) ( $provider['csv'] ?? 'generic' ),
				],
			],
			'trash_policy'    => $fallback ? Settings::POLICY_REPARENT : $policy,
			'trash_fallback'  => $fallback,
			'trash_to'        => $to > 0 ? self::ref( $to ) : null,
			'list_url'        => List_Table::filter_url( $type, $id ),
		];
	}

	/**
	 * A post's title as plain text, never empty.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function title( int $post_id ): string {
		$title = wp_strip_all_tags( html_entity_decode( get_the_title( $post_id ), ENT_QUOTES, 'UTF-8' ) );
		return '' !== $title ? $title : __( '(no title)', 'hierarchy-guard' );
	}

	/**
	 * A short reference to a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array{id:int,title:string,edit_link:string|null}
	 */
	public static function ref( int $post_id ): array {
		$link = get_edit_post_link( $post_id, 'raw' );
		return [
			'id'        => $post_id,
			'title'     => self::title( $post_id ),
			'edit_link' => is_string( $link ) && '' !== $link ? $link : null,
		];
	}

	/**
	 * Ancestors, top level first.
	 *
	 * @param WP_Post $post Post.
	 * @return array<int,array<string,mixed>>
	 */
	private function ancestors( WP_Post $post ): array {
		$ids = array_reverse( array_map( 'intval', get_post_ancestors( $post ) ) );
		_prime_post_caches( $ids, false, false );

		$out = [];
		foreach ( $ids as $ancestor_id ) {
			$out[] = self::ref( $ancestor_id ) + [ 'status' => (string) get_post_status( $ancestor_id ) ];
		}
		return $out;
	}

	/**
	 * One page of live children the current user may read, each with its own child count.
	 *
	 * @param int    $parent_id Parent post ID.
	 * @param string $type      Post type name.
	 * @param int    $page      Page number, from 1.
	 * @return array<int,array<string,mixed>>
	 */
	private function children( int $parent_id, string $type, int $page ): array {
		global $wpdb;

		$cap    = Subtree::count_cap();
		$offset = ( $page - 1 ) * self::CHILDREN_PER_PAGE;
		if ( $offset >= $cap ) {
			return [];
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Index range on post_parent; the offset stops at the count cap.
		$ids = array_map(
			'intval',
			$wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = %s AND post_status NOT IN ( 'trash', 'auto-draft', 'inherit' ) ORDER BY menu_order ASC, post_title ASC, ID ASC LIMIT %d OFFSET %d",
					$parent_id,
					$type,
					self::CHILDREN_PER_PAGE,
					$offset
				)
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
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

	/**
	 * Old paths recorded on the post, newest first, as URLs relative to the home URL.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $type    Post type name.
	 * @return array<int,array{path:string,url:string}>
	 */
	private function old_paths( int $post_id, string $type ): array {
		$paths = array_slice( array_reverse( $this->ledger->paths_for( $post_id ) ), 0, self::MAX_OLD_PATHS );
		return array_map(
			static fn( string $path ): array => [
				'path' => $path,
				'url'  => Paths::relative( Paths::url( $type, $path ) ),
			],
			$paths
		);
	}
}
```

- [ ] **Step 4: Create the CSV download and the controller**

Create `includes/HierarchyGuard/REST/Csv_Download.php`:
```php
<?php
/**
 * Serves a CSV body from a REST route.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\REST;

use HierarchyGuard\Export\Csv;
use WP_HTTP_Response;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The REST server encodes every response as JSON. A CSV route returns its
 * body as a string flagged with a header, and this class prints it as is.
 * Download links are the one place the plugin sends a file.
 */
final class Csv_Download {

	public const FLAG = 'X-Hierarchy-Guard-Csv';

	/**
	 * Hooks into core.
	 */
	public function register(): void {
		add_filter( 'rest_pre_serve_request', [ $this, 'serve' ], 10, 3 );
	}

	/**
	 * A CSV response for pairs in a layout.
	 *
	 * @param string                                  $layout Layout name.
	 * @param array<int,array{old:string,new:string}> $pairs  Pairs.
	 * @return WP_REST_Response
	 */
	public static function response( string $layout, array $pairs ): WP_REST_Response {
		$response = new WP_REST_Response( Csv::build( $layout, $pairs ) );
		$response->header( 'Content-Type', 'text/csv; charset=utf-8' );
		$response->header( 'Content-Disposition', 'attachment; filename="' . Csv::filename( $layout ) . '"' );
		$response->header( 'Cache-Control', 'no-store' );
		$response->header( self::FLAG, '1' );
		return $response;
	}

	/**
	 * Prints a flagged CSV body instead of letting the server JSON-encode it.
	 *
	 * @param mixed $served  Whether the request was served already.
	 * @param mixed $result  The response.
	 * @param mixed $request The request.
	 * @return mixed
	 */
	public function serve( $served, $result, $request ) {
		if ( $served || ! $result instanceof WP_HTTP_Response || ! $request instanceof WP_REST_Request ) {
			return $served;
		}
		$headers = $result->get_headers();
		if ( empty( $headers[ self::FLAG ] ) || 200 !== $result->get_status() || ! is_string( $result->get_data() ) ) {
			return $served;
		}
		if ( 'HEAD' !== $request->get_method() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A text/csv body built by Csv::build(), sent with nosniff by the REST server.
			echo $result->get_data();
		}
		return true;
	}
}
```

Create `includes/HierarchyGuard/REST/Hierarchy_Controller.php`:
```php
<?php
/**
 * REST routes about one post's hierarchy.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\REST;

use HierarchyGuard\Export\Csv;
use HierarchyGuard\Ledger;
use HierarchyGuard\Overview;
use HierarchyGuard\Paths;
use HierarchyGuard\Post_Types;
use HierarchyGuard\Redirects\Pairs;
use HierarchyGuard\Subtree;
use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Backs the editor panel, the Quick Edit warning, the trash dialog and the
 * redirects modal. Every route is read-only and needs edit rights on the
 * post it reads.
 */
final class Hierarchy_Controller {

	public const NAMESPACE = 'hierarchy-guard/v1';

	/**
	 * Overview builder.
	 *
	 * @var Overview
	 */
	private Overview $overview;

	/**
	 * Ledger.
	 *
	 * @var Ledger
	 */
	private Ledger $ledger;

	/**
	 * Pairs.
	 *
	 * @var Pairs
	 */
	private Pairs $pairs;

	/**
	 * Constructor.
	 *
	 * @param Overview $overview Overview builder.
	 * @param Ledger   $ledger   Ledger.
	 * @param Pairs    $pairs    Pairs.
	 */
	public function __construct( Overview $overview, Ledger $ledger, Pairs $pairs ) {
		$this->overview = $overview;
		$this->ledger   = $ledger;
		$this->pairs    = $pairs;
	}

	/**
	 * Hooks into core.
	 */
	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'routes' ] );
	}

	/**
	 * Registers the routes.
	 */
	public function routes(): void {
		$id = [
			'type'     => 'integer',
			'minimum'  => 1,
			'required' => true,
		];

		register_rest_route(
			self::NAMESPACE,
			'/posts/(?P<id>\d+)/hierarchy',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'hierarchy' ],
				'permission_callback' => [ $this, 'can_edit' ],
				'args'                => [
					'id'            => $id,
					'children_page' => [
						'type'    => 'integer',
						'minimum' => 1,
						'default' => 1,
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/posts/(?P<id>\d+)/pairs',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'pairs' ],
				'permission_callback' => [ $this, 'can_edit' ],
				'args'                => [
					'id'       => $id,
					'from'     => [
						'type'     => 'string',
						'required' => true,
					],
					'page'     => [
						'type'    => 'integer',
						'minimum' => 1,
						'default' => 1,
					],
					'per_page' => [
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => Subtree::COUNT_CAP,
						'default' => Pairs::PER_PAGE,
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/posts/(?P<id>\d+)/csv',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'csv' ],
				'permission_callback' => [ $this, 'can_edit' ],
				'args'                => [
					'id'     => $id,
					'from'   => [
						'type'     => 'string',
						'required' => true,
					],
					'format' => [
						'type'    => 'string',
						'enum'    => array_keys( Csv::LAYOUTS ),
						'default' => 'generic',
					],
				],
			]
		);
	}

	/**
	 * Whether the current user may edit the requested post of a supported type.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function can_edit( WP_REST_Request $request ) {
		$post = get_post( (int) $request['id'] );
		if ( ! $post instanceof WP_Post || ! Post_Types::is_supported( (string) $post->post_type ) ) {
			return new WP_Error( 'rest_post_invalid_id', __( 'Invalid post ID.', 'hierarchy-guard' ), [ 'status' => 404 ] );
		}
		if ( ! current_user_can( 'edit_post', (int) $post->ID ) ) {
			return new WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to edit this post.', 'hierarchy-guard' ), [ 'status' => rest_authorization_required_code() ] );
		}
		return true;
	}

	/**
	 * GET /posts/{id}/hierarchy.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function hierarchy( WP_REST_Request $request ): WP_REST_Response {
		$post = get_post( (int) $request['id'] );
		return new WP_REST_Response( $this->overview->describe( $post, (int) $request['children_page'] ) );
	}

	/**
	 * GET /posts/{id}/pairs: the redirects an old path recorded on the post needs.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function pairs( WP_REST_Request $request ) {
		$blocks = $this->post_blocks( $request );
		if ( $blocks instanceof WP_Error ) {
			return $blocks;
		}
		return new WP_REST_Response( $this->pairs->page( $blocks, (int) $request['page'], (int) $request['per_page'] ) );
	}

	/**
	 * GET /posts/{id}/csv: the same pairs as a download.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function csv( WP_REST_Request $request ) {
		$blocks = $this->post_blocks( $request );
		if ( $blocks instanceof WP_Error ) {
			return $blocks;
		}
		return Csv_Download::response( (string) $request['format'], $this->pairs->all( $blocks ) );
	}

	/**
	 * The pairs block for the request's old path, if it is recorded on the post.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<int,array<string,mixed>>|WP_Error
	 */
	private function post_blocks( WP_REST_Request $request ) {
		$post = get_post( (int) $request['id'] );
		$from = Paths::normalize( (string) $request['from'] );
		if ( '' === $from || ! in_array( $from, $this->ledger->paths_for( (int) $post->ID ), true ) ) {
			return new WP_Error( 'hierarchy_guard_unknown_path', __( 'This old URL is not recorded for this page.', 'hierarchy-guard' ), [ 'status' => 404 ] );
		}
		return [ Pairs::post_block( $post, $from ) ];
	}
}
```

- [ ] **Step 5: Wire them**

In `includes/HierarchyGuard/Plugin.php`, replace:
```php
		$ledger = new Ledger();
		( new Change_Listener( $ledger ) )->register();
		( new Redirects\Server( new Redirects\Resolver( $ledger ) ) )->register();
		( new Admin\Settings_Section( new Redirects\Providers() ) )->register();
```
with:
```php
		$ledger    = new Ledger();
		$providers = new Redirects\Providers();
		( new Change_Listener( $ledger ) )->register();
		( new Redirects\Server( new Redirects\Resolver( $ledger ) ) )->register();
		( new Admin\Settings_Section( $providers ) )->register();
```

and replace:
```php
		( new Trash\Trash_Handler( $ledger, $operations, $queue, $log ) )->register();
		( new Admin\Trash_Notice( $log ) )->register();
		( new Admin\List_Table() )->register();
```
with:
```php
		( new Trash\Trash_Handler( $ledger, $operations, $queue, $log ) )->register();

		$pairs    = new Redirects\Pairs();
		$overview = new Overview( $ledger, $providers );
		( new Admin\Trash_Notice( $log ) )->register();
		( new Admin\List_Table() )->register();
		( new REST\Hierarchy_Controller( $overview, $ledger, $pairs ) )->register();
		( new REST\Csv_Download() )->register();
```

- [ ] **Step 6: Run the tests, lint and the suite**

Run: `npm run test:php -- --filter HierarchyControllerTest`
Expected: PASS.
Run: `composer lint:fix; composer lint && npm run test:php`
Expected: lint exits 0; the suite passes.

- [ ] **Step 7: Commit**

```bash
git add includes/HierarchyGuard/Overview.php includes/HierarchyGuard/REST includes/HierarchyGuard/Plugin.php tests/phpunit/HierarchyControllerTest.php
git commit -m "feat: hierarchy REST route, page pairs and CSV downloads"
```

### Task 6: The trash preview and the dialog's policy

**Files:**
- Modify: `includes/HierarchyGuard/REST/Hierarchy_Controller.php` (imports, a constant, the `trash-preview` route, two methods)
- Create: `includes/HierarchyGuard/Admin/Trash_Choice.php`
- Modify: `includes/HierarchyGuard/Plugin.php`
- Test: `tests/phpunit/HierarchyControllerTest.php` (tests at the end of the class), `tests/phpunit/TrashChoiceTest.php`

**Interfaces:**
- Consumes: Task 5's controller and `Overview::title()`, `Overview::ref()`; plan 2's `Operations::threshold()`, `Subtree::live_children()`, `Settings::trash_policy()`, the `hierarchy_guard_trash_policy` filter.
- Produces: `GET hierarchy-guard/v1/trash-preview?ids[]=…` (at most `MAX_PREVIEW = 50` IDs), answering `{ pages: [ { id, title, path, children, descendants{count,capped}, fallback, to{id,title,edit_link}|null, example{from,to}|null, denied } ], total{count,capped}, policy, cap }`. A page appears only if it has live subpages, isn't in the trash, and the user may `delete_post` it. Every page draws on one shared budget, so a large selection is still one capped walk. `example` uses the lowest-ID subpage. `denied` means the user can't `edit_post` one of the first `Operations::threshold()` direct subpages.
- Produces: `Admin\Trash_Choice` with `PARAM = 'hierarchy_guard_policy'`, `static chosen( int $post_id ): ?string`, and `filter()` on `hierarchy_guard_trash_policy`.

- [ ] **Step 1: Write the failing tests**

Append to the class in `tests/phpunit/HierarchyControllerTest.php`:
```php
	public function test_the_trash_preview_describes_each_page_with_subpages(): void {
		$data = $this->get(
			'/trash-preview',
			[ 'ids' => [ $this->ids['about'], $this->ids['history'], $this->ids['team'] ] ]
		)->get_data();

		$this->assertSame( [ $this->ids['about'], $this->ids['team'] ], array_column( $data['pages'], 'id' ), 'History has no subpages.' );
		$about = $data['pages'][0];
		$this->assertSame( 'About', $about['title'] );
		$this->assertSame( '/company/about/', $about['path'] );
		$this->assertSame( 2, $about['children'] );
		$this->assertSame( 3, $about['descendants']['count'] );
		$this->assertSame( 'Company', $about['to']['title'] );
		$this->assertSame(
			[
				'from' => '/company/about/team/',
				'to'   => '/company/team/',
			],
			$about['example']
		);
		$this->assertFalse( $about['denied'] );
		$this->assertFalse( $about['fallback'] );
		$this->assertSame(
			[
				'count'  => 4,
				'capped' => false,
			],
			$data['total']
		);
		$this->assertSame( Settings::POLICY_REPARENT, $data['policy'] );
	}

	public function test_the_trash_preview_says_when_some_subpages_cannot_be_moved(): void {
		$locked = $this->ids['team'];
		add_filter(
			'map_meta_cap',
			static function ( array $caps, string $cap, int $user_id, array $args ) use ( $locked ): array {
				return 'edit_post' === $cap && isset( $args[0] ) && $locked === (int) $args[0] ? [ 'do_not_allow' ] : $caps;
			},
			10,
			4
		);

		$data = $this->get( '/trash-preview', [ 'ids' => [ $this->ids['about'] ] ] )->get_data();

		$this->assertTrue( $data['pages'][0]['denied'] );
	}

	public function test_the_trash_preview_shares_one_capped_budget(): void {
		add_filter( 'hierarchy_guard_count_cap', static fn(): int => 2 );

		$data = $this->get( '/trash-preview', [ 'ids' => [ $this->ids['about'], $this->ids['team'] ] ] )->get_data();

		$this->assertTrue( $data['pages'][0]['fallback'], 'About has more subpages than the cap.' );
		$this->assertSame(
			[
				'count'  => 2,
				'capped' => true,
			],
			$data['total']
		);
	}

	public function test_the_trash_preview_skips_pages_the_user_cannot_trash(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$data = $this->get( '/trash-preview', [ 'ids' => [ $this->ids['about'] ] ] )->get_data();

		$this->assertSame( [], $data['pages'] );
	}
```

Create `tests/phpunit/TrashChoiceTest.php`:
```php
<?php
/**
 * The trash policy the Pages list dialog sends.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Admin\Trash_Choice;
use HierarchyGuard\Post_Types;
use HierarchyGuard\Settings;
use HierarchyGuard\Trash\Trash_Handler;
use WP_UnitTestCase;

/**
 * @covers \HierarchyGuard\Admin\Trash_Choice
 */
final class TrashChoiceTest extends WP_UnitTestCase {

	use Trash_Fixtures;

	/**
	 * Page IDs.
	 *
	 * @var array<string,int>
	 */
	private array $ids;

	public function set_up(): void {
		parent::set_up();
		delete_option( Settings::OPTION );
		Post_Types::reset();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->ids = $this->make_tree();
		set_current_screen( 'edit-page' );
	}

	public function tear_down(): void {
		$_REQUEST = [];
		unset( $GLOBALS['pagenow'] );
		set_current_screen( 'front' );
		parent::tear_down();
	}

	/**
	 * Sets up a request from the row action's trash link.
	 *
	 * @param int    $post_id Page the link trashes.
	 * @param string $policy  Chosen policy.
	 * @param string $nonce   Nonce action, by default core's.
	 */
	private function row_request( int $post_id, string $policy, string $nonce = '' ): void {
		$GLOBALS['pagenow'] = 'post.php';
		$_REQUEST           = [
			'action'            => 'trash',
			'post'              => (string) $post_id,
			'_wpnonce'          => wp_create_nonce( '' !== $nonce ? $nonce : 'trash-post_' . $post_id ),
			Trash_Choice::PARAM => $policy,
		];
	}

	public function test_the_row_link_s_choice_applies_to_its_page(): void {
		$this->row_request( $this->ids['about'], Settings::POLICY_KEEP );

		$this->assertSame( Settings::POLICY_KEEP, Trash_Choice::chosen( $this->ids['about'] ) );
		$this->assertSame( Settings::POLICY_KEEP, Trash_Handler::policy_for( get_post( $this->ids['about'] ) ), 'The plugin hooks the filter.' );
		$this->assertNull( Trash_Choice::chosen( $this->ids['team'] ), 'Another page keeps the default.' );
	}

	public function test_a_choice_without_core_s_nonce_is_ignored(): void {
		$this->row_request( $this->ids['about'], Settings::POLICY_KEEP, 'something-else' );

		$this->assertNull( Trash_Choice::chosen( $this->ids['about'] ) );
	}

	public function test_an_unknown_policy_is_ignored(): void {
		$this->row_request( $this->ids['about'], 'delete_everything' );

		$this->assertNull( Trash_Choice::chosen( $this->ids['about'] ) );
	}

	public function test_the_bulk_form_s_choice_applies_to_the_selected_pages(): void {
		$GLOBALS['pagenow'] = 'edit.php';
		$_REQUEST           = [
			'action'            => '-1',
			'action2'           => 'trash',
			'post'              => [ (string) $this->ids['about'], (string) $this->ids['history'] ],
			'_wpnonce'          => wp_create_nonce( 'bulk-posts' ),
			Trash_Choice::PARAM => Settings::POLICY_TRASH_CHILDREN,
		];

		$this->assertSame( Settings::POLICY_TRASH_CHILDREN, Trash_Choice::chosen( $this->ids['about'] ) );
		$this->assertNull( Trash_Choice::chosen( $this->ids['team'] ), 'Not selected.' );

		$_REQUEST['action2'] = 'edit';
		$this->assertNull( Trash_Choice::chosen( $this->ids['about'] ), 'Not a trash.' );
	}

	public function test_outside_the_two_screens_the_choice_is_ignored(): void {
		$this->row_request( $this->ids['about'], Settings::POLICY_KEEP );
		$GLOBALS['pagenow'] = 'admin-ajax.php';

		$this->assertNull( Trash_Choice::chosen( $this->ids['about'] ) );
	}

	public function test_a_trash_from_the_row_link_uses_the_chosen_policy(): void {
		$this->row_request( $this->ids['about'], Settings::POLICY_KEEP );

		wp_trash_post( $this->ids['about'] );

		$this->assertSame( $this->ids['about'], (int) get_post_field( 'post_parent', $this->ids['team'] ), 'Kept where it was.' );
	}
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npm run test:php -- --filter 'HierarchyControllerTest|TrashChoiceTest'`
Expected: FAIL; the preview tests get a 404 (`rest_no_route`) and TrashChoiceTest stops at `Error: Class "HierarchyGuard\Admin\Trash_Choice" not found`.

- [ ] **Step 3: Add the preview to the controller**

In `includes/HierarchyGuard/REST/Hierarchy_Controller.php`, replace:
```php
use HierarchyGuard\Redirects\Pairs;
use HierarchyGuard\Subtree;
```
with:
```php
use HierarchyGuard\Redirects\Pairs;
use HierarchyGuard\Settings;
use HierarchyGuard\Subtree;
use HierarchyGuard\Trash\Operations;
```

replace:
```php
	public const NAMESPACE = 'hierarchy-guard/v1';
```
with:
```php
	public const NAMESPACE   = 'hierarchy-guard/v1';
	public const MAX_PREVIEW = 50;
```

replace the end of the CSV route:
```php
					'format' => [
						'type'    => 'string',
						'enum'    => array_keys( Csv::LAYOUTS ),
						'default' => 'generic',
					],
				],
			]
		);
```
with:
```php
					'format' => [
						'type'    => 'string',
						'enum'    => array_keys( Csv::LAYOUTS ),
						'default' => 'generic',
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/trash-preview',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'trash_preview' ],
				'permission_callback' => 'is_user_logged_in',
				'args'                => [
					'ids' => [
						'type'     => 'array',
						'items'    => [
							'type'    => 'integer',
							'minimum' => 1,
						],
						'maxItems' => self::MAX_PREVIEW,
						'required' => true,
					],
				],
			]
		);
```

and replace the `csv()` method:
```php
	/**
	 * GET /posts/{id}/csv: the same pairs as a download.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function csv( WP_REST_Request $request ) {
		$blocks = $this->post_blocks( $request );
		if ( $blocks instanceof WP_Error ) {
			return $blocks;
		}
		return Csv_Download::response( (string) $request['format'], $this->pairs->all( $blocks ) );
	}
```
with the same method followed by the two new ones:
```php
	/**
	 * GET /posts/{id}/csv: the same pairs as a download.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function csv( WP_REST_Request $request ) {
		$blocks = $this->post_blocks( $request );
		if ( $blocks instanceof WP_Error ) {
			return $blocks;
		}
		return Csv_Download::response( (string) $request['format'], $this->pairs->all( $blocks ) );
	}

	/**
	 * GET /trash-preview: what trashing each of the given pages would do to its subpages.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function trash_preview( WP_REST_Request $request ): WP_REST_Response {
		$cap    = Subtree::count_cap();
		$ids    = array_slice( array_values( array_unique( array_map( 'absint', (array) $request['ids'] ) ) ), 0, self::MAX_PREVIEW );
		$pages  = [];
		$total  = 0;
		$capped = false;

		_prime_post_caches( $ids, false, false );
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! $post instanceof WP_Post || 'trash' === $post->post_status || ! Post_Types::is_supported( (string) $post->post_type ) || ! current_user_can( 'delete_post', $id ) ) {
				continue;
			}
			$type     = (string) $post->post_type;
			$children = Subtree::count_live_children( $id, $type, $cap + 1 );
			if ( 0 === $children ) {
				continue;
			}

			// Every page shares one budget, so a large selection stays one capped walk.
			$budget      = max( 1, $cap - $total );
			$descendants = Subtree::count_live_descendants( $id, $type, $budget );
			$total      += min( $descendants, $budget );
			$capped      = $capped || $descendants > $budget;

			$pages[] = $this->preview_page( $post, $children, $descendants, $budget );
		}

		return new WP_REST_Response(
			[
				'pages'  => $pages,
				'total'  => [
					'count'  => min( $total, $cap ),
					'capped' => $capped || $total > $cap,
				],
				'policy' => Settings::trash_policy(),
				'cap'    => $cap,
			]
		);
	}

	/**
	 * The preview of one page with subpages.
	 *
	 * @param WP_Post $post        Page.
	 * @param int     $children    Live children, up to the cap plus one.
	 * @param int     $descendants Live descendants, up to the budget plus one.
	 * @param int     $budget      The cap the descendants were counted against.
	 * @return array<string,mixed>
	 */
	private function preview_page( WP_Post $post, int $children, int $descendants, int $budget ): array {
		$id   = (int) $post->ID;
		$type = (string) $post->post_type;
		$cap  = Subtree::count_cap();
		$to   = Subtree::nearest_live_ancestor( $post );

		// The first subpage shows what an URL change looks like.
		$first   = Subtree::live_children( $id, $type, 1 );
		$example = null;
		if ( [] !== $first && '' !== (string) get_option( 'permalink_structure' ) ) {
			$slug    = (string) get_post_field( 'post_name', $first[0] );
			$base    = $to > 0 ? Paths::uri( get_post( $to ) ) . '/' : '';
			$example = [
				'from' => Paths::relative( Paths::url( $type, Paths::untrashed_uri( $post ) . '/' . $slug ) ),
				'to'   => Paths::relative( Paths::url( $type, $base . $slug ) ),
			];
		}

		// Moving a subpage needs the right to edit it; the trash flow leaves the rest in place.
		// One batch is checked, the most the trash moves in the request itself.
		$checked = Subtree::live_children( $id, $type, Operations::threshold() );
		_prime_post_caches( $checked, false, false );
		$denied = false;
		foreach ( $checked as $child_id ) {
			if ( ! current_user_can( 'edit_post', $child_id ) ) {
				$denied = true;
				break;
			}
		}

		return [
			'id'          => $id,
			'title'       => Overview::title( $id ),
			'path'        => Paths::relative( Paths::url( $type, Paths::untrashed_uri( $post ) ) ),
			'children'    => min( $children, $cap ),
			'descendants' => [
				'count'  => min( $descendants, $budget ),
				'capped' => $descendants > $budget,
			],
			'fallback'    => $descendants > $cap,
			'to'          => $to > 0 ? Overview::ref( $to ) : null,
			'example'     => $example,
			'denied'      => $denied,
		];
	}
```

- [ ] **Step 4: Create Trash_Choice**

Create `includes/HierarchyGuard/Admin/Trash_Choice.php`:
```php
<?php
/**
 * The trash policy the Pages list dialog sends with a trash request.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Admin;

use HierarchyGuard\Settings;
use WP_Post;

/**
 * The trash dialog adds the editor's choice to core's own trash link or bulk
 * form. Core checks its nonce before it trashes anything; this checks the
 * same nonce again and applies the choice only to the pages the request names.
 */
final class Trash_Choice {

	public const PARAM = 'hierarchy_guard_policy';

	/**
	 * Hooks into core.
	 */
	public function register(): void {
		add_filter( 'hierarchy_guard_trash_policy', [ $this, 'filter' ], 10, 2 );
	}

	/**
	 * Uses the dialog's choice for a page this request trashes.
	 *
	 * @param mixed $policy Policy so far.
	 * @param mixed $post   The page being trashed.
	 * @return mixed
	 */
	public function filter( $policy, $post ) {
		if ( ! $post instanceof WP_Post ) {
			return $policy;
		}
		$chosen = self::chosen( (int) $post->ID );
		return null !== $chosen ? $chosen : $policy;
	}

	/**
	 * The policy the current request chose for a page, or null.
	 *
	 * @param int $post_id Page being trashed.
	 * @return string|null
	 */
	public static function chosen( int $post_id ): ?string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Each branch verifies core's own nonce for the request below.
		if ( ! is_admin() || ! isset( $_REQUEST[ self::PARAM ], $_REQUEST['_wpnonce'] ) ) {
			return null;
		}
		$policy = sanitize_key( wp_unslash( $_REQUEST[ self::PARAM ] ) );
		$nonce  = sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) );
		if ( ! in_array( $policy, Settings::POLICIES, true ) ) {
			return null;
		}

		$page = (string) ( $GLOBALS['pagenow'] ?? '' );
		if ( 'post.php' === $page ) {
			$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
			$target = isset( $_REQUEST['post'] ) ? absint( wp_unslash( $_REQUEST['post'] ) ) : 0;
			$valid  = 'trash' === $action && $target === $post_id && false !== wp_verify_nonce( $nonce, 'trash-post_' . $post_id );
		} elseif ( 'edit.php' === $page ) {
			$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
			if ( '' === $action || '-1' === $action ) {
				$action = isset( $_REQUEST['action2'] ) ? sanitize_key( wp_unslash( $_REQUEST['action2'] ) ) : '';
			}
			$targets = isset( $_REQUEST['post'] ) ? array_map( 'absint', (array) wp_unslash( $_REQUEST['post'] ) ) : [];
			$valid   = 'trash' === $action && in_array( $post_id, $targets, true ) && false !== wp_verify_nonce( $nonce, 'bulk-posts' );
		} else {
			$valid = false;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return $valid ? $policy : null;
	}
}
```

- [ ] **Step 5: Wire it**

In `includes/HierarchyGuard/Plugin.php`, replace:
```php
		( new REST\Csv_Download() )->register();
```
with:
```php
		( new REST\Csv_Download() )->register();
		( new Admin\Trash_Choice() )->register();
```

- [ ] **Step 6: Run the tests, lint and the suite**

Run: `npm run test:php -- --filter 'HierarchyControllerTest|TrashChoiceTest'`
Expected: PASS.
Run: `composer lint:fix; composer lint && npm run test:php`
Expected: lint exits 0; the suite passes.

- [ ] **Step 7: Commit**

```bash
git add includes/HierarchyGuard/REST/Hierarchy_Controller.php includes/HierarchyGuard/Admin/Trash_Choice.php includes/HierarchyGuard/Plugin.php tests/phpunit/HierarchyControllerTest.php tests/phpunit/TrashChoiceTest.php
git commit -m "feat: trash preview and the dialog's chosen policy"
```

### Task 7: Pairs and CSV of the user's last trash

**Files:**
- Create: `includes/HierarchyGuard/REST/Changes_Controller.php`
- Modify: `includes/HierarchyGuard/Plugin.php`
- Test: `tests/phpunit/ChangesControllerTest.php`

**Interfaces:**
- Consumes: Task 2's `Pairs::change_blocks()`, `page()`, `all()`; Task 5's `Hierarchy_Controller::NAMESPACE`, `Csv_Download::response()`; plan 2's `Change_Log::latest()`.
- Produces: `GET hierarchy-guard/v1/changes/(?P<id>[0-9a-f-]{36})/pairs` (`page`, `per_page` ≤ 1,000) and `…/csv` (`format`), for a logged-in user, answering only for that user's latest change with that ID; otherwise `hierarchy_guard_change_gone` (404).

- [ ] **Step 1: Write the failing test**

Create `tests/phpunit/ChangesControllerTest.php`:
```php
<?php
/**
 * REST routes about the current user's last trash.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Ledger;
use HierarchyGuard\Post_Types;
use HierarchyGuard\Settings;
use HierarchyGuard\Trash\Change_Log;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * @covers \HierarchyGuard\REST\Changes_Controller
 */
final class ChangesControllerTest extends WP_UnitTestCase {

	use Trash_Fixtures;

	/**
	 * Page IDs.
	 *
	 * @var array<string,int>
	 */
	private array $ids;

	private int $admin;

	private string $change_id;

	public function set_up(): void {
		parent::set_up();
		delete_option( Settings::OPTION );
		delete_option( Ledger::ROOT_OPTION );
		Post_Types::reset();
		Change_Log::reset_request();
		$this->set_permalink_structure( '/%postname%/' );
		$this->admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $this->admin );
		$this->ids = $this->make_tree();
		wp_trash_post( $this->ids['about'] );
		$this->change_id = (string) ( new Change_Log() )->latest( $this->admin )['id'];
	}

	public function tear_down(): void {
		$this->set_permalink_structure( '' );
		parent::tear_down();
	}

	/**
	 * Runs a GET request.
	 *
	 * @param string              $route  Route under the plugin's namespace.
	 * @param array<string,mixed> $params Query parameters.
	 * @return WP_REST_Response
	 */
	private function get( string $route, array $params = [] ): WP_REST_Response {
		$request = new WP_REST_Request( 'GET', '/hierarchy-guard/v1' . $route );
		$request->set_query_params( $params );
		return rest_do_request( $request );
	}

	public function test_the_pairs_of_the_user_s_last_trash(): void {
		$data = $this->get( '/changes/' . $this->change_id . '/pairs' )->get_data();

		$this->assertSame( 3, $data['total'] );
		$this->assertSame( [ '/company/about/history/', '/company/about/team/', '/company/about/team/leadership/' ], array_column( $data['pairs'], 'old' ) );
		$this->assertSame( [ '/company/about/' ], array_column( $data['unresolved'], 'old' ) );
	}

	public function test_another_user_cannot_read_the_change(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$response = $this->get( '/changes/' . $this->change_id . '/pairs' );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'hierarchy_guard_change_gone', $response->get_data()['code'] );
	}

	public function test_an_older_change_is_gone(): void {
		$this->assertSame( 404, $this->get( '/changes/' . wp_generate_uuid4() . '/pairs' )->get_status() );
	}

	public function test_a_visitor_is_refused(): void {
		wp_set_current_user( 0 );

		$this->assertSame( 401, $this->get( '/changes/' . $this->change_id . '/pairs' )->get_status() );
	}

	public function test_the_csv_of_the_change(): void {
		$response = $this->get( '/changes/' . $this->change_id . '/csv', [ 'format' => 'generic' ] );

		$this->assertSame( "source,target,code\r\n/company/about/history/,/company/history/,301\r\n/company/about/team/,/company/team/,301\r\n/company/about/team/leadership/,/company/team/leadership/,301\r\n", $response->get_data() );
	}

	public function test_an_unknown_csv_layout_is_refused(): void {
		$this->assertSame( 400, $this->get( '/changes/' . $this->change_id . '/csv', [ 'format' => 'excel' ] )->get_status() );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `npm run test:php -- --filter ChangesControllerTest`
Expected: FAIL; the routes answer 404 `rest_no_route`.

- [ ] **Step 3: Create the controller**

Create `includes/HierarchyGuard/REST/Changes_Controller.php`:
```php
<?php
/**
 * REST routes about the current user's last trash.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\REST;

use HierarchyGuard\Export\Csv;
use HierarchyGuard\Redirects\Pairs;
use HierarchyGuard\Subtree;
use HierarchyGuard\Trash\Change_Log;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Backs the "View redirects" link of the outcome notice. A change is the
 * current user's own and lives for thirty minutes, so these routes read
 * nothing another user wrote.
 */
final class Changes_Controller {

	/**
	 * Change log.
	 *
	 * @var Change_Log
	 */
	private Change_Log $log;

	/**
	 * Pairs.
	 *
	 * @var Pairs
	 */
	private Pairs $pairs;

	/**
	 * Constructor.
	 *
	 * @param Change_Log $log   Change log.
	 * @param Pairs      $pairs Pairs.
	 */
	public function __construct( Change_Log $log, Pairs $pairs ) {
		$this->log   = $log;
		$this->pairs = $pairs;
	}

	/**
	 * Hooks into core.
	 */
	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'routes' ] );
	}

	/**
	 * Registers the routes.
	 */
	public function routes(): void {
		$id = [
			'type'     => 'string',
			'format'   => 'uuid',
			'required' => true,
		];

		register_rest_route(
			Hierarchy_Controller::NAMESPACE,
			'/changes/(?P<id>[0-9a-f-]{36})/pairs',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'pairs' ],
				'permission_callback' => 'is_user_logged_in',
				'args'                => [
					'id'       => $id,
					'page'     => [
						'type'    => 'integer',
						'minimum' => 1,
						'default' => 1,
					],
					'per_page' => [
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => Subtree::COUNT_CAP,
						'default' => Pairs::PER_PAGE,
					],
				],
			]
		);

		register_rest_route(
			Hierarchy_Controller::NAMESPACE,
			'/changes/(?P<id>[0-9a-f-]{36})/csv',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'csv' ],
				'permission_callback' => 'is_user_logged_in',
				'args'                => [
					'id'     => $id,
					'format' => [
						'type'    => 'string',
						'enum'    => array_keys( Csv::LAYOUTS ),
						'default' => 'generic',
					],
				],
			]
		);
	}

	/**
	 * GET /changes/{id}/pairs.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function pairs( WP_REST_Request $request ) {
		$change = $this->change( (string) $request['id'] );
		if ( $change instanceof WP_Error ) {
			return $change;
		}
		return new WP_REST_Response( $this->pairs->page( Pairs::change_blocks( $change ), (int) $request['page'], (int) $request['per_page'] ) );
	}

	/**
	 * GET /changes/{id}/csv.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function csv( WP_REST_Request $request ) {
		$change = $this->change( (string) $request['id'] );
		if ( $change instanceof WP_Error ) {
			return $change;
		}
		return Csv_Download::response( (string) $request['format'], $this->pairs->all( Pairs::change_blocks( $change ) ) );
	}

	/**
	 * The current user's change with this ID.
	 *
	 * @param string $change_id Change ID.
	 * @return array<string,mixed>|WP_Error
	 */
	private function change( string $change_id ) {
		$change = $this->log->latest( get_current_user_id() );
		if ( null === $change || $change_id !== $change['id'] ) {
			return new WP_Error( 'hierarchy_guard_change_gone', __( 'These redirects are no longer available. They are kept for 30 minutes after a page is trashed.', 'hierarchy-guard' ), [ 'status' => 404 ] );
		}
		return $change;
	}
}
```

- [ ] **Step 4: Wire it**

In `includes/HierarchyGuard/Plugin.php`, replace:
```php
		( new Admin\Trash_Choice() )->register();
```
with:
```php
		( new Admin\Trash_Choice() )->register();
		( new REST\Changes_Controller( $log, $pairs ) )->register();
```

- [ ] **Step 5: Run the tests, lint and the suite**

Run: `npm run test:php -- --filter ChangesControllerTest`
Expected: PASS.
Run: `composer lint:fix; composer lint && npm run test:php`
Expected: lint exits 0; the suite passes.

- [ ] **Step 6: Commit**

```bash
git add includes/HierarchyGuard/REST/Changes_Controller.php includes/HierarchyGuard/Plugin.php tests/phpunit/ChangesControllerTest.php
git commit -m "feat: pairs and CSV of the user's last trash"
```

### Task 8: The outcome notice, background progress, the nudge, and settings anchors

**Files:**
- Move: `includes/HierarchyGuard/Admin/Trash_Notice.php` → `includes/HierarchyGuard/Admin/Notices.php` (new contents)
- Move: `tests/phpunit/TrashNoticeTest.php` → `tests/phpunit/NoticesTest.php` (new contents)
- Modify: `includes/HierarchyGuard/Admin/Settings_Section.php`, `includes/HierarchyGuard/Plugin.php`
- Test: `tests/phpunit/NoticesTest.php`, `tests/phpunit/SettingsSectionTest.php`

**Interfaces:**
- Consumes: Task 2's `Pairs::change_blocks()` and `count()`, Task 3's `Csv::layout()`, Task 5's `Overview::title()` and `Hierarchy_Controller::NAMESPACE`; plan 2's `Change_Log`, `Queue::load()`, and the sentence builders of `Trash_Notice`.
- Produces: `Admin\Notices` (constructor `Change_Log, Queue, Pairs, Providers`) with `NUDGE_META = 'hierarchy_guard_nudge_dismissed'`, `append()` on `bulk_post_updated_messages` (same matching rules as `Trash_Notice`), `redirects_sentence( array $change ): string` (HTML), `static change_csv_url( string $change_id, string $layout ): string`, `render()` on `admin_notices`, `progress_sentences( string $post_type ): string[]`, `wants_nudge(): bool`, `register_meta()` on `init`, and the unchanged static `sentences( array $change ): string[]`.
- Produces: the outcome notice's "View redirects" link is `<a href="{CSV download URL}" class="hierarchy-guard-view-redirects" data-change="{change id}">`; without JavaScript it downloads the CSV, and Task 12's script opens the modal instead. The nudge is a dismissible `.hierarchy-guard-nudge` notice.
- Produces: `Settings_Section::redirects_url(): string` and `Settings_Section::policy_url(): string`.

What changes for the editor: when one page was trashed or restored, core's "1 page moved to the Trash." becomes "“About” moved to the Trash." After the subpage sentences comes one sentence about the URLs, by what handles them: "The 3 old URLs redirect to their new pages." with built-in redirects on; "3 URLs changed. View redirects (3)" with a redirect plugin; "3 old URLs now show “Page not found”. Turn on redirects · View redirects (3)" with neither. Core's Undo link stays last. The sentence for subpages a restore couldn't bring back now covers ones another plugin kept in the trash too.

- [ ] **Step 1: Move the files**

```bash
git mv includes/HierarchyGuard/Admin/Trash_Notice.php includes/HierarchyGuard/Admin/Notices.php
git mv tests/phpunit/TrashNoticeTest.php tests/phpunit/NoticesTest.php
```

- [ ] **Step 2: Write the failing tests**

Replace the contents of `tests/phpunit/NoticesTest.php` with:
```php
<?php
/**
 * The outcome notice, background progress and the redirects nudge.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Admin\Notices;
use HierarchyGuard\Batch\Queue;
use HierarchyGuard\Redirects\Pairs;
use HierarchyGuard\Redirects\Providers;
use HierarchyGuard\Post_Types;
use HierarchyGuard\Settings;
use HierarchyGuard\Trash\Change_Log;
use WP_UnitTestCase;

/**
 * @covers \HierarchyGuard\Admin\Notices
 */
final class NoticesTest extends WP_UnitTestCase {

	use Trash_Fixtures;

	/**
	 * Page IDs.
	 *
	 * @var array<string,int>
	 */
	private array $ids;

	private int $admin;

	private Change_Log $log;

	private Notices $notice;

	public function set_up(): void {
		parent::set_up();
		delete_option( Settings::OPTION );
		Post_Types::reset();
		Change_Log::reset_request();
		$this->admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $this->admin );
		$this->log          = new Change_Log();
		$this->notice       = new Notices( $this->log, new Queue(), new Pairs(), new Providers() );
		$this->ids          = $this->make_tree();
		$GLOBALS['typenow'] = 'page';
	}

	public function tear_down(): void {
		unset( $GLOBALS['typenow'], $_REQUEST['ids'] );
		$this->set_permalink_structure( '' );
		set_current_screen( 'front' );
		parent::tear_down();
	}

	/**
	 * Trashes about through core with pretty permalinks, so its subpages move up
	 * and their old URLs have pairs, and returns the trash notice core would show.
	 *
	 * @return string The notice text after core's sprintf().
	 */
	private function trash_about(): string {
		$this->set_permalink_structure( '/%postname%/' );
		wp_trash_post( $this->ids['about'] );
		$_REQUEST['ids'] = (string) $this->ids['about'];
		return sprintf( $this->notice->append( $this->core_messages(), [ 'trashed' => 1 ] )['page']['trashed'], '1' );
	}

	/**
	 * Makes a redirect plugin look active.
	 */
	private function with_provider(): void {
		add_filter(
			'hierarchy_guard_redirect_providers',
			static fn(): array => [
				'redirection' => [
					'name'   => 'Redirection',
					'active' => true,
					'url'    => admin_url( 'tools.php?page=redirection.php' ),
					'csv'    => 'redirection',
				],
			]
		);
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
			if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof Notices ) {
				$found = true;
			}
		}
		$this->assertTrue( $found );
	}

	public function test_core_s_trash_notice_gains_one_sentence_once(): void {
		$this->change( 'trash', [ $this->about( [ 'counts' => [ 'moved' => 2 ] ] ) ] );
		$_REQUEST['ids'] = (string) $this->ids['about'];

		$messages = $this->notice->append( $this->core_messages(), [ 'trashed' => 1 ] );

		$this->assertSame( '“About” moved to the Trash. 2 subpages moved up under “Company”.', sprintf( $messages['page']['trashed'], '1' ) );
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

		$this->assertSame( '“About” moved to the Trash. 1 subpage moved up under “100% Company”.', sprintf( $messages['page']['trashed'], '1' ) );
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
		$this->assertSame( [ '1 subpage moved up under “Company”.' ], Notices::sentences( $this->change( 'trash', [ $this->about( [ 'counts' => [ 'moved' => 1 ] ] ) ] ) ) );
		Change_Log::reset_request();
		$this->assertSame(
			[ '3 subpages are now top-level pages.' ],
			Notices::sentences(
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
			Notices::sentences(
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
			Notices::sentences(
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
			Notices::sentences(
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
			Notices::sentences(
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

		$this->assertSame( [ '3 subpages moved up one level.' ], Notices::sentences( $change ) );
	}

	public function test_restore_sentences(): void {
		$this->assertSame(
			[ '2 subpages moved back under “About”.', '3 subpages were restored with it.', '1 subpage couldn’t be brought back and stayed where it is.', '1 subpage couldn’t get its old URL back.' ],
			Notices::sentences(
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
		$this->assertSame( [ '1 subpage moved back under its parent.', '2 subpages were restored too.' ], Notices::sentences( $change ) );
	}

	public function test_the_fallback_sentence_agrees_with_the_cap(): void {
		add_filter( 'hierarchy_guard_count_cap', static fn(): int => 1 );

		$change = $this->change(
			'trash',
			[
				$this->about(
					[
						'fallback' => true,
						'counts'   => [ 'moved' => 2 ],
					]
				),
			]
		);

		$this->assertContains( 'There was more than 1 subpage, so they moved up instead of going to the Trash.', Notices::sentences( $change ) );
	}

	public function test_a_background_trash_says_the_rest_are_going_to_the_trash(): void {
		$change = $this->change(
			'trash',
			[
				$this->about(
					[
						'policy' => Settings::POLICY_TRASH_CHILDREN,
						'counts' => [ 'trashed' => 25 ],
						'job'    => true,
					]
				),
			]
		);

		$this->assertSame( [ '25 subpages moved to the Trash too.', 'The rest are still going to the Trash in the background.' ], Notices::sentences( $change ) );
	}

	public function test_with_a_redirect_plugin_the_notice_counts_the_changed_urls_and_links_the_redirects(): void {
		$this->with_provider();

		$text = $this->trash_about();

		$this->assertStringStartsWith( '“About” moved to the Trash. 2 subpages moved up under “Company”. 3 URLs changed. <a href="', $text );
		$this->assertStringContainsString( 'class="hierarchy-guard-view-redirects" data-change="', $text );
		$this->assertStringContainsString( '>View redirects (3)</a>', $text );
		$this->assertStringContainsString( 'format=redirection', $text, 'The link downloads the provider’s layout without JavaScript.' );
	}

	public function test_with_built_in_redirects_on_the_notice_says_the_old_urls_redirect(): void {
		update_option( Settings::OPTION, [ 'serve_redirects' => true ] );

		$text = $this->trash_about();

		$this->assertStringEndsWith( 'The 3 old URLs redirect to their new pages.', $text );
		$this->assertStringNotContainsString( 'View redirects', $text );
	}

	public function test_with_nothing_handling_redirects_the_notice_warns_and_offers_to_turn_them_on(): void {
		$text = $this->trash_about();

		$this->assertStringContainsString( '3 old URLs now show “Page not found”. <a href="' . esc_url( admin_url( 'options-writing.php#hierarchy-guard-redirects' ) ) . '">Turn on redirects</a> · <a href="', $text );
		$this->assertStringContainsString( 'format=generic', $text );
	}

	public function test_an_editor_is_not_offered_the_settings(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->admin = get_current_user_id();

		$text = $this->trash_about();

		$this->assertStringNotContainsString( 'Turn on redirects', $text );
		$this->assertStringContainsString( 'View redirects (3)', $text );
	}

	public function test_a_root_title_with_a_percent_sign_survives_core_s_sprintf(): void {
		wp_update_post(
			[
				'ID'         => $this->ids['about'],
				'post_title' => '100% About',
			]
		);

		$this->assertStringStartsWith( '“100% About” moved to the Trash.', $this->trash_about() );
	}

	public function test_a_bulk_trash_keeps_core_s_count(): void {
		$this->change(
			'trash',
			[
				$this->about( [ 'counts' => [ 'moved' => 2 ] ] ),
				[
					'root'   => $this->ids['team'],
					'to'     => $this->ids['about'],
					'policy' => Settings::POLICY_REPARENT,
					'counts' => [ 'moved' => 1 ],
				],
			]
		);
		$_REQUEST['ids'] = $this->ids['about'] . ',' . $this->ids['team'];

		$messages = $this->notice->append( $this->core_messages(), [ 'trashed' => 2 ] );

		$this->assertStringStartsWith( '2 page moved to the Trash. 3 subpages moved up one level.', sprintf( $messages['page']['trashed'], '2' ) );
	}

	public function test_progress_is_shown_while_the_batch_runs_and_not_on_the_landing_load(): void {
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

		$this->assertSame( [ 'Still moving the subpages of “About” in the background: 25 of 40 done.' ], $this->notice->progress_sentences( 'page' ) );
		$this->assertSame( [], $this->notice->progress_sentences( 'post' ), 'Another list screen.' );

		$_REQUEST['ids'] = (string) $this->ids['about'];
		$this->notice->append( $this->core_messages(), [ 'trashed' => 1 ] );
		$this->assertSame( [], $this->notice->progress_sentences( 'page' ), 'The outcome notice on this load already says so.' );
		$queue->delete( $this->ids['about'] );
	}

	public function test_progress_of_a_trash_of_the_subpages_and_of_a_restore(): void {
		$queue = new Queue();
		$id    = $this->change(
			'trash',
			[
				$this->about(
					[
						'job'    => true,
						'policy' => Settings::POLICY_TRASH_CHILDREN,
					]
				),
			]
		)['id'];
		$queue->save(
			[
				'root'   => $this->ids['about'],
				'change' => $id,
				'total'  => 0,
				'counts' => array_merge( Change_Log::empty_counts(), [ 'trashed' => 7 ] ),
			]
		);
		$this->assertSame( [ 'Still moving the subpages of “About” to the Trash in the background: 7 done so far.' ], $this->notice->progress_sentences( 'page' ) );

		Change_Log::reset_request();
		$id = $this->change( 'restore', [ $this->about( [ 'job' => true ] ) ] )['id'];
		$queue->save(
			[
				'root'   => $this->ids['about'],
				'change' => $id,
				'total'  => 9,
				'counts' => array_merge( Change_Log::empty_counts(), [ 'restored' => 3 ] ),
			]
		);
		$this->assertSame( [ 'Still restoring the subpages of “About” in the background: 3 of 9 done.' ], $this->notice->progress_sentences( 'page' ) );
		$queue->delete( $this->ids['about'] );
	}

	public function test_a_finished_or_replaced_job_shows_no_progress(): void {
		$this->change( 'trash', [ $this->about( [ 'job' => true ] ) ] );

		$this->assertSame( [], $this->notice->progress_sentences( 'page' ), 'No job in the queue.' );
	}

	public function test_the_nudge_needs_an_admin_with_nothing_handling_redirects(): void {
		$this->assertTrue( $this->notice->wants_nudge() );

		update_user_meta( $this->admin, Notices::NUDGE_META, true );
		$this->assertFalse( $this->notice->wants_nudge(), 'Dismissed.' );
		delete_user_meta( $this->admin, Notices::NUDGE_META );

		update_option( Settings::OPTION, [ 'serve_redirects' => true ] );
		$this->assertFalse( $this->notice->wants_nudge(), 'Built-in redirects are on.' );
		delete_option( Settings::OPTION );

		$this->with_provider();
		$this->assertFalse( $this->notice->wants_nudge(), 'A redirect plugin is active.' );
	}

	public function test_an_editor_gets_no_nudge(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$this->assertFalse( $this->notice->wants_nudge() );
	}

	public function test_the_nudge_is_printed_on_a_supported_list_screen_only(): void {
		set_current_screen( 'edit-page' );
		ob_start();
		$this->notice->render();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'hierarchy-guard-nudge', $html );
		$this->assertStringContainsString( 'is-dismissible', $html );

		set_current_screen( 'dashboard' );
		ob_start();
		$this->notice->render();
		$this->assertSame( '', (string) ob_get_clean() );
	}

	public function test_the_nudge_setting_is_writable_through_the_users_route(): void {
		$this->notice->register_meta();
		$meta = get_registered_meta_keys( 'user' );

		$this->assertArrayHasKey( Notices::NUDGE_META, $meta );
		$this->assertTrue( (bool) $meta[ Notices::NUDGE_META ]['show_in_rest'] );
		$this->assertNotFalse( has_action( 'init', [ $this->plugin_notices(), 'register_meta' ] ), 'The plugin registers it on init.' );
		unregister_meta_key( 'user', Notices::NUDGE_META );
	}

	/**
	 * The Notices instance the plugin registered.
	 */
	private function plugin_notices(): Notices {
		global $wp_filter;
		foreach ( $wp_filter['bulk_post_updated_messages']->callbacks[10] ?? [] as $callback ) {
			if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof Notices ) {
				return $callback['function'][0];
			}
		}
		$this->fail( 'The plugin registered no Notices instance.' );
	}
}
```

In `tests/phpunit/SettingsSectionTest.php`, replace:
```php
		$this->section = new Settings_Section( new Providers() );
	}
```
with:
```php
		$this->section = new Settings_Section( new Providers() );
	}

	public function tear_down(): void {
		// add() writes core's settings globals; later tests must not see this section.
		unset( $GLOBALS['wp_settings_sections']['writing'][ Settings_Section::SECTION ], $GLOBALS['wp_settings_fields']['writing'][ Settings_Section::SECTION ] );
		parent::tear_down();
	}
```

replace:
```php
		$this->assertSame( 3, substr_count( $html, 'type="radio"' ) );
```
with:
```php
		$this->assertSame( 3, substr_count( $html, 'type="radio"' ) );
		$this->assertStringContainsString( '<fieldset id="hierarchy-guard-trash-policy">', $html, 'The target of the editor panel’s Settings link.' );
```

replace:
```php
		$this->assertStringContainsString( 'Applies whenever a page with subpages is moved to the trash, including from the editor, the REST API, and WP-CLI.', $html );
		$this->assertStringNotContainsString( 'The trash is turned off', $html, 'This test site keeps its trash.' );
		$this->assertStringNotContainsString( 'Pages list', $html, 'No claims about UI that does not exist yet.' );
```
with:
```php
		$this->assertStringContainsString( 'Used when there’s no chance to ask, for example in the editor, the REST API, or WP-CLI. The Pages list asks each time.', $html );
		$this->assertStringNotContainsString( 'The trash is turned off', $html, 'This test site keeps its trash.' );
```

and append to the class:
```php
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
		$this->section->render_serve_redirects();
		$html = (string) ob_get_clean();
		remove_filter( 'hierarchy_guard_redirect_providers', $filter );

		$this->assertStringContainsString( 'A redirect plugin is active and can handle these.', $html );
	}

	public function test_the_redirects_checkbox_is_the_target_of_turn_on_redirects_links(): void {
		ob_start();
		$this->section->render_serve_redirects();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'id="hierarchy-guard-redirects"', $html );
		$this->assertStringEndsWith( 'options-writing.php#hierarchy-guard-redirects', Settings_Section::redirects_url() );
	}

	public function test_the_settings_globals_do_not_leak_into_the_next_test(): void {
		global $wp_settings_sections;

		$this->assertArrayNotHasKey( Settings_Section::SECTION, $wp_settings_sections['writing'] ?? [], 'tear_down() removed what an earlier add() registered.' );
	}
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `npm run test:php -- --filter 'NoticesTest|SettingsSectionTest'`
Expected: FAIL before any test runs: the bootstrap stops with `Error: Class "HierarchyGuard\Admin\Trash_Notice" not found`, because `Plugin.php` still names the class whose file just moved. Step 6 fixes that.

- [ ] **Step 4: Write Notices**

Replace the contents of `includes/HierarchyGuard/Admin/Notices.php` with:
```php
<?php
/**
 * The outcome notice after a trash or restore, background progress, and the redirects nudge.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Admin;

use HierarchyGuard\Batch\Queue;
use HierarchyGuard\Export\Csv;
use HierarchyGuard\Overview;
use HierarchyGuard\Post_Types;
use HierarchyGuard\REST\Hierarchy_Controller;
use HierarchyGuard\Redirects\Pairs;
use HierarchyGuard\Redirects\Providers;
use HierarchyGuard\Settings;
use HierarchyGuard\Subtree;
use HierarchyGuard\Trash\Change_Log;
use WP_Screen;

/**
 * Trashing from the editor or the Pages list lands on core's "moved to the
 * Trash" notice. This rewrites it once per change into the outcome: what
 * happened to the subpages, how many URLs changed, and what handles them,
 * with core's own Undo link kept at the end. While a background batch runs,
 * the list screen says how far it got.
 */
final class Notices {

	public const NUDGE_META = 'hierarchy_guard_nudge_dismissed';

	/**
	 * Change log.
	 *
	 * @var Change_Log
	 */
	private Change_Log $log;

	/**
	 * Queue.
	 *
	 * @var Queue
	 */
	private Queue $queue;

	/**
	 * Pairs.
	 *
	 * @var Pairs
	 */
	private Pairs $pairs;

	/**
	 * Providers.
	 *
	 * @var Providers
	 */
	private Providers $providers;

	/**
	 * Whether this request already showed the outcome notice.
	 *
	 * @var bool
	 */
	private bool $shown_now = false;

	/**
	 * Constructor.
	 *
	 * @param Change_Log $log       Change log.
	 * @param Queue      $queue     Queue.
	 * @param Pairs      $pairs     Pairs.
	 * @param Providers  $providers Redirect plugin detection.
	 */
	public function __construct( Change_Log $log, Queue $queue, Pairs $pairs, Providers $providers ) {
		$this->log       = $log;
		$this->queue     = $queue;
		$this->pairs     = $pairs;
		$this->providers = $providers;
	}

	/**
	 * Hooks into core.
	 */
	public function register(): void {
		add_filter( 'bulk_post_updated_messages', [ $this, 'append' ], 10, 2 );
		add_action( 'admin_notices', [ $this, 'render' ] );
		add_action( 'init', [ $this, 'register_meta' ] );
	}

	/**
	 * The user setting that hides the redirects nudge, writable through the REST users route.
	 */
	public function register_meta(): void {
		register_meta(
			'user',
			self::NUDGE_META,
			[
				'type'          => 'boolean',
				'single'        => true,
				'default'       => false,
				'show_in_rest'  => true,
				'auth_callback' => static fn(): bool => current_user_can( 'manage_options' ),
			]
		);
	}

	/**
	 * Rewrites core's trashed or untrashed message into the outcome notice.
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

		$extra = array_map( 'esc_html', self::sentences( $change ) );
		$urls  = 'trash' === $change['action'] ? $this->redirects_sentence( $change ) : '';
		if ( '' !== $urls ) {
			$extra[] = $urls;
		}
		if ( [] === $extra ) {
			return $messages;
		}

		$roots = array_values( (array) $change['roots'] );
		$first = (string) $messages[ $key ][ $slot ];
		if ( 1 === (int) $counts[ $slot ] && 1 === count( $roots ) ) {
			$title = esc_html( Overview::title( (int) $roots[0]['root'] ) );
			$first = 'trashed' === $slot
				/* translators: %s: page title. */
				? sprintf( __( '“%s” moved to the Trash.', 'hierarchy-guard' ), $title )
				/* translators: %s: page title. */
				: sprintf( __( '“%s” restored from the Trash.', 'hierarchy-guard' ), $title );
			// Core runs the message through sprintf() with the count.
			$first = str_replace( '%', '%%', $first );
		}

		$messages[ $key ][ $slot ] = $first . ' ' . str_replace( '%', '%%', implode( ' ', $extra ) );
		$this->log->mark_shown( $user, (string) $change['id'] );
		$this->shown_now = true;

		return $messages;
	}

	/**
	 * The sentence about the URLs a trash changed and what handles them, with its links.
	 *
	 * @param array<string,mixed> $change A trash change.
	 * @return string HTML, or an empty string when no URL changed.
	 */
	public function redirects_sentence( array $change ): string {
		$count = $this->pairs->count( Pairs::change_blocks( $change ) );
		$n     = $count['count'];
		if ( 0 === $n ) {
			return '';
		}
		$shown = number_format_i18n( $n ) . ( $count['capped'] ? '+' : '' );

		if ( Settings::serve_redirects() ) {
			/* translators: %s: number of old URLs, such as 12 or 1,000+. */
			return esc_html( sprintf( _n( 'The %s old URL redirects to its new page.', 'The %s old URLs redirect to their new pages.', $n, 'hierarchy-guard' ), $shown ) );
		}

		$provider = $this->providers->detect();
		$layout   = null !== $provider ? Csv::layout( (string) ( $provider['csv'] ?? 'generic' ) ) : 'generic';
		$view     = sprintf(
			'<a href="%1$s" class="hierarchy-guard-view-redirects" data-change="%2$s">%3$s</a>',
			esc_url( self::change_csv_url( (string) $change['id'], $layout ) ),
			esc_attr( (string) $change['id'] ),
			/* translators: %s: number of old URLs, such as 12 or 1,000+. */
			esc_html( sprintf( __( 'View redirects (%s)', 'hierarchy-guard' ), $shown ) )
		);

		if ( null !== $provider ) {
			/* translators: %s: number of URLs, such as 12 or 1,000+. */
			return esc_html( sprintf( _n( '%s URL changed.', '%s URLs changed.', $n, 'hierarchy-guard' ), $shown ) ) . ' ' . $view;
		}

		/* translators: %s: number of old URLs, such as 12 or 1,000+. */
		$out = esc_html( sprintf( _n( '%s old URL now shows “Page not found”.', '%s old URLs now show “Page not found”.', $n, 'hierarchy-guard' ), $shown ) );
		if ( current_user_can( 'manage_options' ) ) {
			$out .= sprintf( ' <a href="%1$s">%2$s</a> ·', esc_url( Settings_Section::redirects_url() ), esc_html__( 'Turn on redirects', 'hierarchy-guard' ) );
		}
		return $out . ' ' . $view;
	}

	/**
	 * The download URL for a change's pairs, signed for the REST cookie check.
	 *
	 * @param string $change_id Change ID.
	 * @param string $layout    CSV layout.
	 * @return string
	 */
	public static function change_csv_url( string $change_id, string $layout ): string {
		return add_query_arg(
			[
				'format'   => $layout,
				'_wpnonce' => wp_create_nonce( 'wp_rest' ),
			],
			rest_url( Hierarchy_Controller::NAMESPACE . '/changes/' . rawurlencode( $change_id ) . '/csv' )
		);
	}

	/**
	 * Prints the background progress and the redirects nudge on supported list screens.
	 */
	public function render(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen instanceof WP_Screen || 'edit' !== $screen->base || ! Post_Types::is_supported( (string) $screen->post_type ) ) {
			return;
		}

		foreach ( $this->progress_sentences( (string) $screen->post_type ) as $sentence ) {
			wp_admin_notice(
				esc_html( $sentence ),
				[
					'type'               => 'info',
					'additional_classes' => [ 'hierarchy-guard-progress' ],
				]
			);
		}

		if ( $this->wants_nudge() ) {
			wp_admin_notice(
				sprintf(
					'%1$s <a href="%2$s">%3$s</a>',
					esc_html__( 'Hierarchy Guard records page URL changes, but nothing on this site redirects old URLs yet.', 'hierarchy-guard' ),
					esc_url( Settings_Section::redirects_url() ),
					esc_html__( 'Turn on redirects', 'hierarchy-guard' )
				),
				[
					'type'               => 'info',
					'dismissible'        => true,
					'additional_classes' => [ 'hierarchy-guard-nudge' ],
				]
			);
		}
	}

	/**
	 * One sentence per root of the user's change whose batch is still running.
	 *
	 * @param string $post_type List screen's post type.
	 * @return string[]
	 */
	public function progress_sentences( string $post_type ): array {
		$change = $this->log->latest( get_current_user_id() );
		if ( $this->shown_now || null === $change || $post_type !== $change['post_type'] ) {
			return [];
		}

		$out = [];
		foreach ( (array) $change['roots'] as $root ) {
			if ( empty( $root['job'] ) ) {
				continue;
			}
			$job = $this->queue->load( (int) $root['root'] );
			if ( null === $job || $job['change'] !== $change['id'] ) {
				continue;
			}
			$counts = (array) $job['counts'];
			$done   = (int) $counts['moved'] + (int) $counts['trashed'] + (int) $counts['restored'] + (int) $counts['kept'] + (int) $counts['left'];
			$title  = Overview::title( (int) $root['root'] );
			$total  = (int) $job['total'];
			$known  = $total > 0 && $total < Subtree::count_cap();

			if ( 'restore' === $change['action'] ) {
				$out[] = $known
					/* translators: 1: page title, 2: subpages done, 3: subpages in all. */
					? sprintf( __( 'Still restoring the subpages of “%1$s” in the background: %2$s of %3$s done.', 'hierarchy-guard' ), $title, number_format_i18n( $done ), number_format_i18n( $total ) )
					/* translators: 1: page title, 2: subpages done. */
					: sprintf( __( 'Still restoring the subpages of “%1$s” in the background: %2$s done so far.', 'hierarchy-guard' ), $title, number_format_i18n( $done ) );
			} elseif ( Settings::POLICY_TRASH_CHILDREN === $root['policy'] ) {
				$out[] = $known
					/* translators: 1: page title, 2: subpages done, 3: subpages in all. */
					? sprintf( __( 'Still moving the subpages of “%1$s” to the Trash in the background: %2$s of %3$s done.', 'hierarchy-guard' ), $title, number_format_i18n( $done ), number_format_i18n( $total ) )
					/* translators: 1: page title, 2: subpages done. */
					: sprintf( __( 'Still moving the subpages of “%1$s” to the Trash in the background: %2$s done so far.', 'hierarchy-guard' ), $title, number_format_i18n( $done ) );
			} else {
				$out[] = $known
					/* translators: 1: page title, 2: subpages done, 3: subpages in all. */
					? sprintf( __( 'Still moving the subpages of “%1$s” in the background: %2$s of %3$s done.', 'hierarchy-guard' ), $title, number_format_i18n( $done ), number_format_i18n( $total ) )
					/* translators: 1: page title, 2: subpages done. */
					: sprintf( __( 'Still moving the subpages of “%1$s” in the background: %2$s done so far.', 'hierarchy-guard' ), $title, number_format_i18n( $done ) );
			}
		}
		return $out;
	}

	/**
	 * Whether to nudge this user to turn on redirects: nothing on the site
	 * serves them, the user can change that, and has not dismissed the nudge.
	 *
	 * @return bool
	 */
	public function wants_nudge(): bool {
		if ( Settings::serve_redirects() || ! current_user_can( 'manage_options' ) || null !== $this->providers->detect() ) {
			return false;
		}
		return ! get_user_meta( get_current_user_id(), self::NUDGE_META, true );
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
				$out[] = Settings::POLICY_TRASH_CHILDREN === $root['policy']
					? __( 'The rest are still going to the Trash in the background.', 'hierarchy-guard' )
					: __( 'The rest are still moving in the background.', 'hierarchy-guard' );
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
				$out[] = sprintf( _n( '%1$s subpage moved up under “%2$s”.', '%1$s subpages moved up under “%2$s”.', $counts['moved'], 'hierarchy-guard' ), number_format_i18n( $counts['moved'] ), Overview::title( (int) $single['to'] ) );
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
				$cap = Subtree::count_cap();
				/* translators: %s: the most subpages the plugin trashes together with a page, such as 1,000. */
				$out[] = sprintf( _n( 'There was more than %s subpage, so they moved up instead of going to the Trash.', 'There were more than %s subpages, so they moved up instead of going to the Trash.', $cap, 'hierarchy-guard' ), number_format_i18n( $cap ) );
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
				$out[] = sprintf( _n( '%1$s subpage moved back under “%2$s”.', '%1$s subpages moved back under “%2$s”.', $counts['moved'], 'hierarchy-guard' ), number_format_i18n( $counts['moved'] ), Overview::title( (int) $single['root'] ) );
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
			$out[] = sprintf( _n( '%s subpage couldn’t be brought back and stayed where it is.', '%s subpages couldn’t be brought back and stayed where they are.', $counts['left'], 'hierarchy-guard' ), number_format_i18n( $counts['left'] ) );
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
}
```

- [ ] **Step 5: Add the settings anchors and the spec's help text**

In `includes/HierarchyGuard/Admin/Settings_Section.php`, make these replacements.

Replace:
```php
	/**
	 * Registers the option with its sanitize callback and defaults.
	 */
```
with:
```php
	/**
	 * The Redirects field on Settings › Writing, for "Turn on redirects" links.
	 *
	 * @return string
	 */
	public static function redirects_url(): string {
		return admin_url( 'options-writing.php#hierarchy-guard-redirects' );
	}

	/**
	 * The trash-policy field on Settings › Writing, for "Settings" links.
	 *
	 * @return string
	 */
	public static function policy_url(): string {
		return admin_url( 'options-writing.php#hierarchy-guard-trash-policy' );
	}

	/**
	 * Registers the option with its sanitize callback and defaults.
	 */
```

Replace:
```php
		echo '<fieldset><legend class="screen-reader-text"><span>' . esc_html__( 'Trashing a page with subpages', 'hierarchy-guard' ) . '</span></legend>';
```
with:
```php
		echo '<fieldset id="hierarchy-guard-trash-policy"><legend class="screen-reader-text"><span>' . esc_html__( 'Trashing a page with subpages', 'hierarchy-guard' ) . '</span></legend>';
```

Replace:
```php
		$help = __( 'Applies whenever a page with subpages is moved to the trash, including from the editor, the REST API, and WP-CLI.', 'hierarchy-guard' );
```
with:
```php
		$help = __( 'Used when there’s no chance to ask, for example in the editor, the REST API, or WP-CLI. The Pages list asks each time.', 'hierarchy-guard' );
```

Replace:
```php
			'<label><input type="checkbox" name="%1$s[serve_redirects]" value="1" %2$s> %3$s</label>',
```
with:
```php
			'<label><input type="checkbox" id="hierarchy-guard-redirects" name="%1$s[serve_redirects]" value="1" %2$s> %3$s</label>',
```

Replace:
```php
		if ( null !== $provider ) {
			/* translators: %s: name of the detected redirect plugin. */
			$help = sprintf( __( '%s is active and can handle these. URL changes are recorded either way.', 'hierarchy-guard' ), $provider['name'] );
		} else {
```
with:
```php
		$name = null !== $provider ? trim( (string) ( $provider['name'] ?? '' ) ) : '';
		if ( null !== $provider && '' !== $name ) {
			/* translators: %s: name of the detected redirect plugin. */
			$help = sprintf( __( '%s is active and can handle these. URL changes are recorded either way.', 'hierarchy-guard' ), $name );
		} elseif ( null !== $provider ) {
			$help = __( 'A redirect plugin is active and can handle these. URL changes are recorded either way.', 'hierarchy-guard' );
		} else {
```

- [ ] **Step 6: Wire it**

In `includes/HierarchyGuard/Plugin.php`, replace:
```php
		( new Admin\Trash_Notice( $log ) )->register();
```
with:
```php
		( new Admin\Notices( $log, $queue, $pairs, $providers ) )->register();
```

- [ ] **Step 7: Run the tests, lint and the suite**

Run: `npm run test:php -- --filter 'NoticesTest|SettingsSectionTest'`
Expected: PASS.
Run: `composer lint:fix; composer lint && npm run test:php`
Expected: lint exits 0; the suite passes.

- [ ] **Step 8: Commit**

```bash
git add -A includes/HierarchyGuard/Admin includes/HierarchyGuard/Plugin.php tests/phpunit/NoticesTest.php tests/phpunit/TrashNoticeTest.php tests/phpunit/SettingsSectionTest.php
git commit -m "feat: outcome notice with redirects, progress and the nudge"
```

### Task 9: The Hierarchy box in the classic editor

**Files:**
- Create: `includes/HierarchyGuard/Admin/Meta_Box.php`
- Modify: `includes/HierarchyGuard/Plugin.php`
- Test: `tests/phpunit/MetaBoxTest.php`

**Interfaces:**
- Consumes: Task 5's `Overview::describe()`.
- Produces: `Admin\Meta_Box` with `add()` on `add_meta_boxes` (side, `__back_compat_meta_box` so the block editor shows the panel instead), `render( WP_Post $post )`, `static counts_line( array $data ): string` ("2 direct · 3 in total"), `static trash_sentence( array $data ): string`.
- Produces: the box wraps its content in `<div class="hierarchy-guard-box" data-descendants data-capped data-status>`; Task 10's classic-editor script reads it.

- [ ] **Step 1: Write the failing test**

Create `tests/phpunit/MetaBoxTest.php`:
```php
<?php
/**
 * The Hierarchy box in the classic editor.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Admin\Meta_Box;
use HierarchyGuard\Ledger;
use HierarchyGuard\Overview;
use HierarchyGuard\Post_Types;
use HierarchyGuard\Redirects\Providers;
use HierarchyGuard\Settings;
use WP_UnitTestCase;

/**
 * @covers \HierarchyGuard\Admin\Meta_Box
 */
final class MetaBoxTest extends WP_UnitTestCase {

	use Trash_Fixtures;

	/**
	 * Page IDs.
	 *
	 * @var array<string,int>
	 */
	private array $ids;

	private Meta_Box $box;

	public function set_up(): void {
		parent::set_up();
		require_once ABSPATH . 'wp-admin/includes/template.php';
		delete_option( Settings::OPTION );
		Post_Types::reset();
		$this->set_permalink_structure( '/%postname%/' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->ids = $this->make_tree();
		$this->box = new Meta_Box( new Overview( new Ledger(), new Providers() ) );
	}

	public function tear_down(): void {
		global $wp_meta_boxes;
		$wp_meta_boxes = [];
		$this->set_permalink_structure( '' );
		parent::tear_down();
	}

	/**
	 * The box's HTML for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private function html( int $post_id ): string {
		ob_start();
		$this->box->render( get_post( $post_id ) );
		return (string) ob_get_clean();
	}

	public function test_the_box_is_added_for_supported_types_in_the_classic_editor_only(): void {
		global $wp_meta_boxes;

		$this->box->add( 'page', get_post( $this->ids['about'] ) );
		$this->box->add( 'post', get_post( self::factory()->post->create() ) );

		$box = $wp_meta_boxes['page']['side']['default']['hierarchy-guard'] ?? null;
		$this->assertIsArray( $box );
		$this->assertTrue( $box['args']['__back_compat_meta_box'], 'The block editor shows the panel instead.' );
		$this->assertArrayNotHasKey( 'post', $wp_meta_boxes );
	}

	public function test_the_box_shows_parents_children_and_the_trash_policy(): void {
		$html = $this->html( $this->ids['about'] );

		$this->assertStringContainsString( 'data-descendants="3"', $html );
		$this->assertStringContainsString( 'data-status="publish"', $html );
		$this->assertStringContainsString( '>Company</a>', $html );
		$this->assertStringContainsString( '2 direct · 3 in total', $html );
		$this->assertStringContainsString( '>Team</a> · 1 subpage</li>', $html );
		$this->assertStringContainsString( 'Trashing this page moves its 2 subpages under “Company”.', $html );
	}

	public function test_a_top_level_page_without_subpages(): void {
		$html = $this->html( $this->page( 'lonely' ) );

		$this->assertStringContainsString( 'None. This is a top-level page.', $html );
		$this->assertStringContainsString( 'No subpages.', $html );
		$this->assertStringNotContainsString( 'Trashing this page', $html );
	}

	public function test_old_urls_are_listed_with_what_handles_them(): void {
		wp_update_post(
			[
				'ID'        => $this->ids['about'],
				'post_name' => 'about-us',
			]
		);

		$this->assertStringContainsString( '<code>/company/about/</code> — Not redirected', $this->html( $this->ids['about'] ) );

		update_option( Settings::OPTION, [ 'serve_redirects' => true ] );
		$this->assertStringContainsString( '<code>/company/about/</code> — Redirects here', $this->html( $this->ids['about'] ) );
	}

	public function test_the_trash_sentence_follows_the_policy(): void {
		$data = [
			'children_total' => 2,
			'descendants'    => [
				'count'  => 5,
				'capped' => false,
			],
			'trash_to'       => null,
		];

		$this->assertSame( 'Trashing this page moves its 5 subpages to the Trash too.', Meta_Box::trash_sentence( $data + [ 'trash_policy' => Settings::POLICY_TRASH_CHILDREN ] ) );
		$this->assertSame( 'Trashing this page leaves its 2 subpages where they are.', Meta_Box::trash_sentence( $data + [ 'trash_policy' => Settings::POLICY_KEEP ] ) );
		$this->assertSame( 'Trashing this page makes its 2 subpages top-level pages.', Meta_Box::trash_sentence( $data + [ 'trash_policy' => Settings::POLICY_REPARENT ] ) );
	}

	public function test_a_long_list_of_children_links_to_the_list(): void {
		for ( $i = 1; $i <= 10; $i++ ) {
			$this->page( 'extra-' . $i, $this->ids['about'] );
		}

		$this->assertStringContainsString( 'View all 13 in the list', $this->html( $this->ids['about'] ) );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `npm run test:php -- --filter MetaBoxTest`
Expected: FAIL with `Error: Class "HierarchyGuard\Admin\Meta_Box" not found`.

- [ ] **Step 3: Create the box**

Create `includes/HierarchyGuard/Admin/Meta_Box.php`:
```php
<?php
/**
 * The Hierarchy box in the classic editor.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Admin;

use HierarchyGuard\Overview;
use HierarchyGuard\Post_Types;
use HierarchyGuard\Settings;
use WP_Post;

/**
 * A read-only box with the same data as the block editor panel. The
 * classic-editor script asks for confirmation when the form is submitted with
 * a changed slug or parent on a published page with subpages.
 */
final class Meta_Box {

	/**
	 * Overview builder.
	 *
	 * @var Overview
	 */
	private Overview $overview;

	/**
	 * Constructor.
	 *
	 * @param Overview $overview Overview builder.
	 */
	public function __construct( Overview $overview ) {
		$this->overview = $overview;
	}

	/**
	 * Hooks into core.
	 */
	public function register(): void {
		add_action( 'add_meta_boxes', [ $this, 'add' ], 10, 2 );
	}

	/**
	 * Adds the box to supported types, in the classic editor only.
	 *
	 * @param mixed $post_type Post type of the screen.
	 * @param mixed $post      Post being edited.
	 */
	public function add( $post_type, $post = null ): void {
		if ( ! is_string( $post_type ) || ! Post_Types::is_supported( $post_type ) || ! $post instanceof WP_Post ) {
			return;
		}
		add_meta_box(
			'hierarchy-guard',
			__( 'Hierarchy', 'hierarchy-guard' ),
			[ $this, 'render' ],
			$post_type,
			'side',
			'default',
			[ '__back_compat_meta_box' => true ]
		);
	}

	/**
	 * Prints the box.
	 *
	 * @param WP_Post $post Post being edited.
	 */
	public function render( WP_Post $post ): void {
		$data        = $this->overview->describe( $post );
		$descendants = (int) $data['descendants']['count'];

		printf(
			'<div class="hierarchy-guard-box" data-descendants="%1$d" data-capped="%2$d" data-status="%3$s">',
			(int) $descendants,
			$data['descendants']['capped'] ? 1 : 0,
			esc_attr( (string) $data['status'] )
		);

		echo '<p><strong>' . esc_html__( 'Parent pages', 'hierarchy-guard' ) . '</strong><br>';
		if ( [] === $data['ancestors'] ) {
			esc_html_e( 'None. This is a top-level page.', 'hierarchy-guard' );
		} else {
			$crumbs = array_map( [ self::class, 'link' ], $data['ancestors'] );
			echo implode( ' › ', $crumbs ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Each crumb is escaped by link().
		}
		echo '</p>';

		echo '<p><strong>' . esc_html__( 'Child pages', 'hierarchy-guard' ) . '</strong><br>';
		if ( 0 === (int) $data['children_total'] ) {
			esc_html_e( 'No subpages.', 'hierarchy-guard' );
			echo '</p>';
		} else {
			echo esc_html( self::counts_line( $data ) ) . '</p><ul class="hierarchy-guard-box__children">';
			foreach ( $data['children'] as $child ) {
				$note = (int) $child['children_count'] > 0
					/* translators: %s: number of subpages. */
					? sprintf( _n( '%s subpage', '%s subpages', (int) $child['children_count'], 'hierarchy-guard' ), number_format_i18n( (int) $child['children_count'] ) . ( $child['children_capped'] ? '+' : '' ) )
					: '';
				$state = 'publish' !== $child['status'] ? (string) $child['status_label'] : '';
				printf(
					'<li>%1$s%2$s%3$s</li>',
					self::link( $child ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by link().
					'' !== $state ? ' — ' . esc_html( $state ) : '',
					'' !== $note ? ' · ' . esc_html( $note ) : ''
				);
			}
			echo '</ul>';
			if ( $data['has_more'] ) {
				printf(
					'<p><a href="%1$s">%2$s</a></p>',
					esc_url( (string) $data['list_url'] ),
					/* translators: %s: number of subpages in all. */
					esc_html( sprintf( __( 'View all %s in the list', 'hierarchy-guard' ), number_format_i18n( $descendants ) . ( $data['descendants']['capped'] ? '+' : '' ) ) )
				);
			}
		}

		if ( [] !== $data['old_paths'] ) {
			echo '<p><strong>' . esc_html__( 'Old URLs', 'hierarchy-guard' ) . '</strong></p><ul class="hierarchy-guard-box__old">';
			$state = $data['redirects']['serving'] ? __( 'Redirects here', 'hierarchy-guard' ) : __( 'Not redirected', 'hierarchy-guard' );
			foreach ( $data['old_paths'] as $old ) {
				printf( '<li><code>%1$s</code> — %2$s</li>', esc_html( (string) $old['url'] ), esc_html( $state ) );
			}
			echo '</ul>';
		}

		$trash = self::trash_sentence( $data );
		if ( '' !== $trash ) {
			echo '<p class="description">' . esc_html( $trash ) . '</p>';
		}
		echo '</div>';
	}

	/**
	 * "4 direct · 12 in total".
	 *
	 * @param array<string,mixed> $data Overview.
	 * @return string
	 */
	public static function counts_line( array $data ): string {
		$direct = number_format_i18n( (int) $data['children_total'] ) . ( $data['children_capped'] ? '+' : '' );
		$all    = number_format_i18n( (int) $data['descendants']['count'] ) . ( $data['descendants']['capped'] ? '+' : '' );
		/* translators: 1: number of direct subpages, 2: number of subpages at every level. */
		return sprintf( __( '%1$s direct · %2$s in total', 'hierarchy-guard' ), $direct, $all );
	}

	/**
	 * What trashing the page would do to its subpages under its policy.
	 *
	 * @param array<string,mixed> $data Overview.
	 * @return string
	 */
	public static function trash_sentence( array $data ): string {
		$children = (int) $data['children_total'];
		if ( 0 === $children ) {
			return '';
		}
		$all = (int) $data['descendants']['count'];

		switch ( $data['trash_policy'] ) {
			case Settings::POLICY_TRASH_CHILDREN:
				/* translators: %s: number of subpages at every level. */
				return sprintf( _n( 'Trashing this page moves its %s subpage to the Trash too.', 'Trashing this page moves its %s subpages to the Trash too.', $all, 'hierarchy-guard' ), number_format_i18n( $all ) );
			case Settings::POLICY_KEEP:
				/* translators: %s: number of direct subpages. */
				return sprintf( _n( 'Trashing this page leaves its %s subpage where it is.', 'Trashing this page leaves its %s subpages where they are.', $children, 'hierarchy-guard' ), number_format_i18n( $children ) );
		}

		if ( null !== $data['trash_to'] ) {
			/* translators: 1: number of direct subpages, 2: title of the page they would move under. */
			return sprintf( _n( 'Trashing this page moves its %1$s subpage under “%2$s”.', 'Trashing this page moves its %1$s subpages under “%2$s”.', $children, 'hierarchy-guard' ), number_format_i18n( $children ), $data['trash_to']['title'] );
		}
		/* translators: %s: number of direct subpages. */
		return sprintf( _n( 'Trashing this page makes its %s subpage a top-level page.', 'Trashing this page makes its %s subpages top-level pages.', $children, 'hierarchy-guard' ), number_format_i18n( $children ) );
	}

	/**
	 * A post's title, linked to its editor when the user may edit it.
	 *
	 * @param array<string,mixed> $ref Post reference from Overview.
	 * @return string HTML.
	 */
	private static function link( array $ref ): string {
		$title = esc_html( (string) $ref['title'] );
		return null !== $ref['edit_link'] ? sprintf( '<a href="%1$s">%2$s</a>', esc_url( (string) $ref['edit_link'] ), $title ) : $title;
	}
}
```

- [ ] **Step 4: Wire it**

In `includes/HierarchyGuard/Plugin.php`, replace:
```php
		( new REST\Changes_Controller( $log, $pairs ) )->register();
```
with:
```php
		( new REST\Changes_Controller( $log, $pairs ) )->register();
		( new Admin\Meta_Box( $overview ) )->register();
```

- [ ] **Step 5: Run the tests, lint and the suite**

Run: `npm run test:php -- --filter MetaBoxTest`
Expected: PASS.
Run: `composer lint:fix; composer lint && npm run test:php`
Expected: lint exits 0; the suite passes.

- [ ] **Step 6: Commit**

```bash
git add includes/HierarchyGuard/Admin/Meta_Box.php includes/HierarchyGuard/Plugin.php tests/phpunit/MetaBoxTest.php
git commit -m "feat: Hierarchy box in the classic editor"
```

### Task 10: Build tooling, shared copy, the asset loader and the classic-editor check

**Files:**
- Modify: `package.json`, `package-lock.json` (through npm), `.distignore`, `.github/workflows/ci.yml`, `includes/HierarchyGuard/Plugin.php`
- Create: `webpack.config.js`, `src/common/config.js`, `src/common/copy.js`, `src/common/api.js`, `src/common/test/copy.test.js`, `src/common/test/api.test.js`, `src/classic/index.js`, `includes/HierarchyGuard/Admin/Assets.php`
- Test: `tests/phpunit/AssetsTest.php`, the two Vitest files

**Interfaces:**
- Consumes: Task 4's `.hierarchy-guard-cell` data, Task 8's `Settings_Section::redirects_url()` and `policy_url()`, Task 9's `.hierarchy-guard-box` data.
- Produces: `Admin\Assets` (constructor `Providers $providers, string $dir = HIERARCHY_GUARD_DIR`) with `HANDLE_PREFIX = 'hierarchy-guard-'`, `enqueue( string $name, string[] $extra = [] ): bool` (false when `build/{name}.asset.php` is missing; else script `hierarchy-guard-{name}` with the asset's dependencies plus `$extra`, translations, `window.hierarchyGuard` printed before it, `build/{name}.css` when built, and `wp-components` styles when the script uses components), `enqueue_editor()` on `enqueue_block_editor_assets` (bundle `editor`, supported types), `enqueue_admin( $hook_suffix )` on `admin_enqueue_scripts` (bundle `list-table` with `jquery` and `inline-edit-post` on `edit.php`, bundle `classic` on `post.php`/`post-new.php` outside the block editor), `config(): array` with `namespace`, `serving`, `provider{name,url,csv}|null`, `settingsUrl`, `policyUrl` (both empty without `manage_options`), `policy`, `postTypes`, `cap`, `restNonce`, `restUrl`.
- Produces: `src/common/config.js` (the settings merged over defaults, read from `globalThis.hierarchyGuard`), `src/common/copy.js` (every sentence the scripts show; `POLICIES`, `formatCount`, `subpagesLabel`, `countsLine`, `trashedSegment`, `trashSentence`, `bulkSentence`, `optionLabel`, `optionDescription`, `deniedSentence`, `saveWarning`, `quickEditWarning`, `changedSentence`, `viewRedirectsLabel`, `trashNote`, `unresolvedSentence`, `pairsText`), `src/common/api.js` (`fetchHierarchy`, `sourceRoute`, `fetchPairs`, `csvUrl`, `trashPreview`, `dismissNudge`). A pairs source is `{ change }` or `{ post, from }`. Tasks 11 and 12 import these.
- Produces: npm scripts `build`, `start`, `lint:js`, `lint:style`, `test:js`; a `JavaScript` CI job.

Without a build every bundle is skipped, so the PHP suite and a checkout without npm keep working. Every screen already works without its script; the scripts add the dialog, the warnings and the modal.

- [ ] **Step 1: Install the tooling**

```bash
npm install --save-dev --save-exact @wordpress/scripts@36.1.0
npm install --save-dev vitest@^5.0.3 vite@^8.3.4 @wordpress/i18n@^6.30.0 @wordpress/api-fetch@^7.57.0 @wordpress/url@^4.57.0 @wordpress/dom-ready@^4.57.0
```

Expected: `package.json` gains those seven entries under `devDependencies`, and `package-lock.json` changes. The `@wordpress/*` packages are build and lint dependencies only; webpack maps them to WordPress's own scripts.

In `package.json`, replace:
```json
	"scripts": {
		"wp-env": "wp-env",
		"env:start": "wp-env start",
		"env:stop": "wp-env stop",
		"env:destroy": "wp-env destroy",
		"test:php": "wp-env run tests-cli --env-cwd=wp-content/plugins/hierarchy-guard vendor/bin/phpunit"
	},
```
with:
```json
	"scripts": {
		"build": "wp-scripts build",
		"start": "wp-scripts start",
		"lint:js": "wp-scripts lint-js src",
		"lint:style": "wp-scripts lint-style \"src/**/*.scss\" --allow-empty-input",
		"test:js": "wp-scripts test-unit-js",
		"wp-env": "wp-env",
		"env:start": "wp-env start",
		"env:stop": "wp-env stop",
		"env:destroy": "wp-env destroy",
		"test:php": "wp-env run tests-cli --env-cwd=wp-content/plugins/hierarchy-guard vendor/bin/phpunit"
	},
```

Create `webpack.config.js`:
```js
/**
 * One bundle per screen: the block editor panel, the list screen, the classic editor.
 */
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

module.exports = {
	...defaultConfig,
	entry: {
		classic: './src/classic/index.js',
	},
};
```

In `.distignore`, replace:
```text
src
```
with:
```text
src
webpack.config.js
```

In `.github/workflows/ci.yml`, replace:
```yaml
  test:
```
with:
```yaml
  js:
    name: JavaScript
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v7
      - uses: actions/setup-node@v7
        with:
          node-version: '24'
          cache: npm
      - run: npm ci
      - run: npm run lint:js
      - run: npm run lint:style
      - run: npm run test:js
      - run: npm run build

  test:
```

and replace:
```yaml
          node-version: '22'
```
with:
```yaml
          node-version: '24'
```

- [ ] **Step 2: Write the failing tests**

Create `src/common/test/copy.test.js`:
```js
/**
 * The sentences the scripts show.
 */
import { describe, expect, it } from 'vitest';
import {
	bulkSentence,
	changedSentence,
	countsLine,
	formatCount,
	optionDescription,
	optionLabel,
	pairsText,
	quickEditWarning,
	saveWarning,
	subpagesLabel,
	trashNote,
	trashSentence,
	trashedSegment,
	unresolvedSentence,
	viewRedirectsLabel,
} from '../copy';

const about = {
	title: 'About',
	path: '/company/about/',
	children: 4,
	descendants: { count: 12, capped: false },
	fallback: false,
	to: { id: 7, title: 'Company' },
	example: { from: '/company/about/team/', to: '/company/team/' },
	denied: false,
};

describe( 'counts', () => {
	it( 'formats a capped count with a plus', () => {
		expect( formatCount( 1000, true ) ).toBe( '1,000+' );
		expect( formatCount( 3 ) ).toBe( '3' );
	} );

	it( 'labels subpages in the singular and the plural', () => {
		expect( subpagesLabel( 1 ) ).toBe( '1 subpage' );
		expect( subpagesLabel( 1000, true ) ).toBe( '1,000+ subpages' );
	} );

	it( 'says how many subpages sit directly below and at every level', () => {
		expect(
			countsLine( {
				children_total: 4,
				children_capped: false,
				descendants: { count: 12, capped: false },
			} )
		).toBe( '4 direct · 12 in total' );
	} );
} );

describe( 'the trash dialog', () => {
	it( 'says where the subpages move under the default policy', () => {
		expect( trashSentence( about, 'reparent', 1000 ) ).toBe(
			'“About” has 4 subpages (12 pages in total). They’ll move up under “Company” and get new URLs.'
		);
	} );

	it( 'leaves out the total when every subpage sits directly below', () => {
		expect(
			trashSentence(
				{
					...about,
					children: 1,
					descendants: { count: 1, capped: false },
				},
				'reparent',
				1000
			)
		).toBe(
			'“About” has 1 subpage. It will move up under “Company” and get a new URL.'
		);
	} );

	it( 'says when the subpages become top-level pages', () => {
		expect(
			trashSentence( { ...about, to: null }, 'reparent', 1000 )
		).toBe(
			'“About” has 4 subpages (12 pages in total). They’ll become top-level pages and get new URLs.'
		);
	} );

	it( 'describes trashing the subpages too, and the fallback past the cap', () => {
		expect( trashSentence( about, 'trash_children', 1000 ) ).toBe(
			'“About” has 4 subpages (12 pages in total). They go to the Trash too, and come back with their statuses if you restore “About”.'
		);
		expect(
			trashSentence(
				{ ...about, fallback: true },
				'trash_children',
				1000
			)
		).toBe(
			'“About” has 4 subpages (12 pages in total). That is more than 1,000, so they’ll move up one level instead of going to the Trash.'
		);
	} );

	it( 'names the trashed slug the kept subpages will carry', () => {
		expect( trashedSegment( '/company/about/' ) ).toBe( 'about__trashed' );
		expect( trashSentence( about, 'keep', 1000 ) ).toBe(
			'“About” has 4 subpages (12 pages in total). They stay where they are, and their URLs will contain about__trashed until you move them.'
		);
	} );

	it( 'sums a bulk selection', () => {
		expect(
			bulkSentence(
				{
					pages: [ about, about, about ],
					total: { count: 19, capped: false },
				},
				'reparent'
			)
		).toBe(
			'3 of the selected pages have subpages (19 pages in total). The subpages move up one level and get new URLs.'
		);
	} );

	it( 'labels and describes each option', () => {
		expect( optionLabel( 'reparent', about ) ).toBe(
			'Move the subpages up under “Company”'
		);
		expect( optionLabel( 'reparent', null ) ).toBe(
			'Move the subpages up one level'
		);
		expect( optionLabel( 'trash_children', about ) ).toBe(
			'Trash the 12 subpages too'
		);
		expect( optionDescription( 'reparent', about, 1000 ) ).toBe(
			'Their URLs change, for example /company/about/team/ becomes /company/team/.'
		);
		expect(
			optionDescription(
				'trash_children',
				{ ...about, fallback: true },
				1000
			)
		).toBe( 'Not available: there are more than 1,000.' );
		expect( optionDescription( 'keep', about, 1000 ) ).toBe(
			'Their URLs will contain about__trashed until you move them.'
		);
	} );
} );

describe( 'warnings and outcomes', () => {
	it( 'warns before a save changes subpage URLs', () => {
		expect( saveWarning( 12, false, false ) ).toBe(
			'Saving changes this page’s URL and the URLs of 12 subpages. You’ll get the redirects to add after saving.'
		);
		expect( saveWarning( 1, false, true ) ).toBe(
			'Saving changes this page’s URL and the URL of 1 subpage. The old URLs will redirect to the new ones.'
		);
	} );

	it( 'warns in Quick Edit', () => {
		expect( quickEditWarning( 12, false ) ).toBe(
			'Changing this moves 12 subpages to new URLs.'
		);
	} );

	it( 'says what handles the changed URLs', () => {
		expect( changedSentence( 12, false, true, null ) ).toBe(
			'The 12 old URLs redirect to their new pages.'
		);
		expect(
			changedSentence( 12, false, false, { name: 'Redirection' } )
		).toBe( '12 URLs changed.' );
		expect( changedSentence( 1, false, false, null ) ).toBe(
			'1 old URL now shows “Page not found”.'
		);
		expect( viewRedirectsLabel( 1000, true ) ).toBe(
			'View redirects (1,000+)'
		);
	} );

	it( 'says what trashing the page would do, by policy', () => {
		const data = {
			children_total: 4,
			descendants: { count: 12, capped: false },
			trash_policy: 'reparent',
			trash_to: { title: 'Company' },
		};
		expect( trashNote( data ) ).toBe(
			'Trashing this page moves its 4 subpages under “Company”.'
		);
		expect( trashNote( { ...data, trash_to: null } ) ).toBe(
			'Trashing this page makes its 4 subpages top-level pages.'
		);
		expect( trashNote( { ...data, trash_policy: 'trash_children' } ) ).toBe(
			'Trashing this page moves its 12 subpages to the Trash too.'
		);
		expect( trashNote( { ...data, trash_policy: 'keep' } ) ).toBe(
			'Trashing this page leaves its 4 subpages where they are.'
		);
		expect( trashNote( { ...data, children_total: 0 } ) ).toBe( '' );
	} );

	it( 'explains an old URL without a new one', () => {
		expect(
			unresolvedSentence( { old: '/about/', reason: 'trash' } )
		).toBe( '/about/ is in the Trash, so it has no new URL.' );
		expect(
			unresolvedSentence( { old: '/about/', reason: 'unpublished' } )
		).toBe( '/about/ leads to a page that isn’t published.' );
	} );

	it( 'writes pairs as text', () => {
		expect(
			pairsText( [
				{ old: '/a/', new: '/b/' },
				{ old: '/a/c/', new: '/b/c/' },
			] )
		).toBe( '/a/ /b/\n/a/c/ /b/c/' );
	} );
} );
```

Create `src/common/test/api.test.js`:
```js
/**
 * Routes and download URLs.
 */
import { beforeEach, describe, expect, it } from 'vitest';
import { csvUrl, sourceRoute } from '../api';
import config from '../config';

describe( 'routes', () => {
	beforeEach( () => {
		config.restNonce = 'abc';
	} );

	it( 'names the change and post routes', () => {
		expect( sourceRoute( { change: 'x-1' }, 'pairs' ) ).toEqual( {
			route: 'hierarchy-guard/v1/changes/x-1/pairs',
			args: {},
		} );
		expect( sourceRoute( { post: 42, from: 'about-us' }, 'csv' ) ).toEqual(
			{
				route: 'hierarchy-guard/v1/posts/42/csv',
				args: { from: 'about-us' },
			}
		);
	} );

	it( 'signs a download URL with pretty permalinks', () => {
		config.restUrl = 'https://example.org/wp-json/';
		expect( csvUrl( { change: 'x-1' }, 'redirection' ) ).toBe(
			'https://example.org/wp-json/hierarchy-guard/v1/changes/x-1/csv?format=redirection&_wpnonce=abc'
		);
	} );

	it( 'signs a download URL with plain permalinks', () => {
		config.restUrl = 'https://example.org/index.php?rest_route=/';
		expect( csvUrl( { post: 42, from: 'about-us' }, 'generic' ) ).toBe(
			'https://example.org/index.php?rest_route=%2Fhierarchy-guard%2Fv1%2Fposts%2F42%2Fcsv&from=about-us&format=generic&_wpnonce=abc'
		);
	} );
} );
```

Create `tests/phpunit/AssetsTest.php`:
```php
<?php
/**
 * The compiled scripts and their settings.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Admin\Assets;
use HierarchyGuard\Post_Types;
use HierarchyGuard\Redirects\Providers;
use HierarchyGuard\Settings;
use WP_UnitTestCase;

/**
 * @covers \HierarchyGuard\Admin\Assets
 */
final class AssetsTest extends WP_UnitTestCase {

	private string $dir;

	public function set_up(): void {
		parent::set_up();
		delete_option( Settings::OPTION );
		Post_Types::reset();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->dir = get_temp_dir() . 'hierarchy-guard-assets-' . wp_generate_password( 6, false );
		wp_mkdir_p( $this->dir . '/build' );
	}

	public function tear_down(): void {
		foreach ( (array) glob( $this->dir . '/build/*' ) as $file ) {
			unlink( (string) $file );
		}
		rmdir( $this->dir . '/build' );
		rmdir( $this->dir );
		wp_dequeue_script( 'hierarchy-guard-list-table' );
		wp_deregister_script( 'hierarchy-guard-list-table' );
		wp_dequeue_style( 'wp-components' );
		set_current_screen( 'front' );
		parent::tear_down();
	}

	/**
	 * Writes a fake compiled bundle.
	 *
	 * @param string   $name Bundle name.
	 * @param string[] $deps Dependencies.
	 */
	private function build( string $name, array $deps ): void {
		$list = implode( ', ', array_map( static fn( string $dep ): string => "'" . $dep . "'", $deps ) );
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A throwaway file in the temp directory.
		file_put_contents( $this->dir . '/build/' . $name . '.asset.php', "<?php return array( 'dependencies' => array( {$list} ), 'version' => 'abc123' );" );
		file_put_contents( $this->dir . '/build/' . $name . '.js', '' );
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	public function test_a_bundle_that_was_never_built_is_skipped(): void {
		$this->assertFalse( ( new Assets( new Providers(), $this->dir ) )->enqueue( 'list-table' ) );
		$this->assertFalse( wp_script_is( 'hierarchy-guard-list-table', 'enqueued' ) );
	}

	public function test_a_built_bundle_is_enqueued_with_its_dependencies_and_settings(): void {
		$this->build( 'list-table', [ 'wp-api-fetch', 'wp-components', 'wp-element', 'wp-i18n' ] );

		$this->assertTrue( ( new Assets( new Providers(), $this->dir ) )->enqueue( 'list-table' ) );

		$script = wp_scripts()->registered['hierarchy-guard-list-table'];
		$this->assertSame( [ 'wp-api-fetch', 'wp-components', 'wp-element', 'wp-i18n' ], $script->deps );
		$this->assertSame( 'abc123', $script->ver );
		$this->assertStringContainsString( 'window.hierarchyGuard = {', implode( '', (array) $script->extra['before'] ) );
		$this->assertSame( 'hierarchy-guard', $script->textdomain );
		$this->assertTrue( wp_style_is( 'wp-components', 'enqueued' ), 'Components need their styles.' );
	}

	public function test_the_settings_every_bundle_reads(): void {
		$config = ( new Assets( new Providers(), $this->dir ) )->config();

		$this->assertSame( 'hierarchy-guard/v1', $config['namespace'] );
		$this->assertFalse( $config['serving'] );
		$this->assertNull( $config['provider'] );
		$this->assertStringEndsWith( 'options-writing.php#hierarchy-guard-redirects', $config['settingsUrl'] );
		$this->assertStringEndsWith( 'options-writing.php#hierarchy-guard-trash-policy', $config['policyUrl'] );
		$this->assertSame( Settings::POLICY_REPARENT, $config['policy'] );
		$this->assertContains( 'page', $config['postTypes'] );
		$this->assertSame( 1000, $config['cap'] );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$editor = ( new Assets( new Providers(), $this->dir ) )->config();
		$this->assertSame( '', $editor['settingsUrl'], 'Only users who can change the setting get the link.' );
		$this->assertSame( '', $editor['policyUrl'] );
	}

	public function test_the_list_screen_gets_the_list_table_bundle(): void {
		$this->build( 'list-table', [] );
		set_current_screen( 'edit-page' );

		( new Assets( new Providers(), $this->dir ) )->enqueue_admin( 'edit.php' );

		$this->assertTrue( wp_script_is( 'hierarchy-guard-list-table', 'enqueued' ) );
		$deps = wp_scripts()->registered['hierarchy-guard-list-table']->deps;
		$this->assertContains( 'jquery', $deps );
		$this->assertContains( 'inline-edit-post', $deps, 'It wraps Quick Edit, so Quick Edit loads first.' );
	}

	public function test_an_unsupported_screen_gets_nothing(): void {
		$this->build( 'list-table', [] );
		set_current_screen( 'edit-post' );

		( new Assets( new Providers(), $this->dir ) )->enqueue_admin( 'edit.php' );

		$this->assertFalse( wp_script_is( 'hierarchy-guard-list-table', 'enqueued' ) );
	}
}
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `npm run test:js`
Expected: FAIL, both files: `Error: Cannot find module '../copy'` and `'../api'`.
Run: `npm run test:php -- --filter AssetsTest`
Expected: FAIL with `Error: Class "HierarchyGuard\Admin\Assets" not found`.

- [ ] **Step 4: Create the shared modules**

Create `src/common/config.js`:
```js
/**
 * The plugin's settings, printed by Admin\Assets before each bundle.
 */
const defaults = {
	namespace: 'hierarchy-guard/v1',
	serving: false,
	provider: null,
	settingsUrl: '',
	policyUrl: '',
	policy: 'reparent',
	postTypes: [],
	cap: 1000,
	restNonce: '',
	restUrl: '/wp-json/',
};

export default { ...defaults, ...( globalThis.hierarchyGuard || {} ) };
```

Create `src/common/copy.js`:
```js
/**
 * Every sentence the scripts show, in one place so it can be tested.
 */
import { __, _n, sprintf } from '@wordpress/i18n';

export const POLICIES = [ 'reparent', 'trash_children', 'keep' ];

/**
 * A count for a sentence: 1,000 stays 1,000, a capped count becomes 1,000+.
 *
 * @param {number}  count  Count.
 * @param {boolean} capped Whether more exist.
 * @return {string} Formatted count.
 */
export function formatCount( count, capped = false ) {
	return Number( count ).toLocaleString() + ( capped ? '+' : '' );
}

/**
 * "3 subpages".
 *
 * @param {number}  count  Count.
 * @param {boolean} capped Whether more exist.
 * @return {string} Label.
 */
export function subpagesLabel( count, capped = false ) {
	return sprintf(
		/* translators: %s: number of subpages, such as 3 or 1,000+. */
		_n( '%s subpage', '%s subpages', count, 'hierarchy-guard' ),
		formatCount( count, capped )
	);
}

/**
 * "4 direct · 12 in total".
 *
 * @param {Object} data Hierarchy from the REST route.
 * @return {string} Line.
 */
export function countsLine( data ) {
	return sprintf(
		/* translators: 1: number of direct subpages, 2: number of subpages at every level. */
		__( '%1$s direct · %2$s in total', 'hierarchy-guard' ),
		formatCount( data.children_total, data.children_capped ),
		formatCount( data.descendants.count, data.descendants.capped )
	);
}

/**
 * The trashed-slug segment a kept subpage's URL will carry, such as about__trashed.
 *
 * @param {string} path Page path relative to the home URL, such as /company/about/.
 * @return {string} Segment.
 */
export function trashedSegment( path ) {
	const parts = String( path || '' )
		.split( '/' )
		.filter( Boolean );
	return ( parts.length ? parts[ parts.length - 1 ] : '' ) + '__trashed';
}

/**
 * What trashing one page does to its subpages under a policy.
 *
 * @param {Object} page   A page from the trash preview.
 * @param {string} policy Policy.
 * @param {number} cap    Count cap.
 * @return {string} Sentence.
 */
export function trashSentence( page, policy, cap ) {
	const children = page.children;
	const all = page.descendants.count;
	const head =
		all > children
			? sprintf(
					/* translators: 1: page title, 2: number of direct subpages, 3: number of pages below it at every level. */
					_n(
						'“%1$s” has %2$s subpage (%3$s pages in total).',
						'“%1$s” has %2$s subpages (%3$s pages in total).',
						children,
						'hierarchy-guard'
					),
					page.title,
					formatCount( children ),
					formatCount( all, page.descendants.capped )
				)
			: sprintf(
					/* translators: 1: page title, 2: number of subpages. */
					_n(
						'“%1$s” has %2$s subpage.',
						'“%1$s” has %2$s subpages.',
						children,
						'hierarchy-guard'
					),
					page.title,
					formatCount( children )
				);

	if ( 'trash_children' === policy && page.fallback ) {
		return (
			head +
			' ' +
			sprintf(
				/* translators: %s: the most subpages trashed together with a page, such as 1,000. */
				__(
					'That is more than %s, so they’ll move up one level instead of going to the Trash.',
					'hierarchy-guard'
				),
				formatCount( cap )
			)
		);
	}
	if ( 'trash_children' === policy ) {
		return (
			head +
			' ' +
			sprintf(
				/* translators: %s: page title. */
				_n(
					'It goes to the Trash too, and comes back with its status if you restore “%s”.',
					'They go to the Trash too, and come back with their statuses if you restore “%s”.',
					all,
					'hierarchy-guard'
				),
				page.title
			)
		);
	}
	if ( 'keep' === policy ) {
		return (
			head +
			' ' +
			sprintf(
				/* translators: %s: the trashed slug the URLs will contain, such as about__trashed. */
				_n(
					'It stays where it is, and its URL will contain %s until you move it.',
					'They stay where they are, and their URLs will contain %s until you move them.',
					children,
					'hierarchy-guard'
				),
				trashedSegment( page.path )
			)
		);
	}
	if ( page.to ) {
		return (
			head +
			' ' +
			sprintf(
				/* translators: %s: title of the page the subpages move under. */
				_n(
					'It will move up under “%s” and get a new URL.',
					'They’ll move up under “%s” and get new URLs.',
					children,
					'hierarchy-guard'
				),
				page.to.title
			)
		);
	}
	return (
		head +
		' ' +
		_n(
			'It will become a top-level page and get a new URL.',
			'They’ll become top-level pages and get new URLs.',
			children,
			'hierarchy-guard'
		)
	);
}

/**
 * What a bulk trash does to the subpages of the selected pages.
 *
 * @param {Object} preview Trash preview.
 * @param {string} policy  Policy.
 * @return {string} Sentence.
 */
export function bulkSentence( preview, policy ) {
	const parents = preview.pages.length;
	const head = sprintf(
		/* translators: 1: number of selected pages with subpages, 2: number of subpages at every level. */
		_n(
			'%1$s of the selected pages has subpages (%2$s pages in total).',
			'%1$s of the selected pages have subpages (%2$s pages in total).',
			parents,
			'hierarchy-guard'
		),
		formatCount( parents ),
		formatCount( preview.total.count, preview.total.capped )
	);
	const tails = {
		reparent: __(
			'The subpages move up one level and get new URLs.',
			'hierarchy-guard'
		),
		trash_children: __(
			'The subpages go to the Trash too, and come back with their statuses if you restore their parent.',
			'hierarchy-guard'
		),
		keep: __(
			'The subpages stay where they are, and their URLs will contain “__trashed” until you move them.',
			'hierarchy-guard'
		),
	};
	return head + ' ' + ( tails[ policy ] || tails.reparent );
}

/**
 * The label of a policy option in the trash dialog.
 *
 * @param {string}      policy Policy.
 * @param {Object|null} page   The only page with subpages, or null for several.
 * @return {string} Label.
 */
export function optionLabel( policy, page ) {
	if ( 'trash_children' === policy ) {
		return page
			? sprintf(
					/* translators: %s: number of subpages at every level. */
					_n(
						'Trash the %s subpage too',
						'Trash the %s subpages too',
						page.descendants.count,
						'hierarchy-guard'
					),
					formatCount(
						page.descendants.count,
						page.descendants.capped
					)
				)
			: __( 'Trash the subpages too', 'hierarchy-guard' );
	}
	if ( 'keep' === policy ) {
		return __( 'Leave the subpages where they are', 'hierarchy-guard' );
	}
	if ( page && page.to ) {
		return sprintf(
			/* translators: %s: title of the page the subpages move under. */
			__( 'Move the subpages up under “%s”', 'hierarchy-guard' ),
			page.to.title
		);
	}
	return page
		? __( 'Make the subpages top-level pages', 'hierarchy-guard' )
		: __( 'Move the subpages up one level', 'hierarchy-guard' );
}

/**
 * The description under a policy option.
 *
 * @param {string}      policy Policy.
 * @param {Object|null} page   The only page with subpages, or null for several.
 * @param {number}      cap    Count cap.
 * @return {string} Description.
 */
export function optionDescription( policy, page, cap ) {
	if ( 'trash_children' === policy ) {
		if ( page && page.fallback ) {
			return sprintf(
				/* translators: %s: the most subpages trashed together with a page, such as 1,000. */
				__(
					'Not available: there are more than %s.',
					'hierarchy-guard'
				),
				formatCount( cap )
			);
		}
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
		return page
			? sprintf(
					/* translators: %s: the trashed slug the URLs will contain, such as about__trashed. */
					__(
						'Their URLs will contain %s until you move them.',
						'hierarchy-guard'
					),
					trashedSegment( page.path )
				)
			: __(
					'Their URLs will contain “__trashed” until you move them.',
					'hierarchy-guard'
				);
	}
	if ( page && page.example ) {
		return sprintf(
			/* translators: 1: an old URL, 2: the URL it becomes. */
			__(
				'Their URLs change, for example %1$s becomes %2$s.',
				'hierarchy-guard'
			),
			page.example.from,
			page.example.to
		);
	}
	return __( 'Their URLs change.', 'hierarchy-guard' );
}

/**
 * The trash dialog's note when some subpages can't be moved.
 *
 * @return {string} Sentence.
 */
export function deniedSentence() {
	return __(
		'You can’t edit some of the subpages. They’ll stay where they are.',
		'hierarchy-guard'
	);
}

/**
 * The editor's warning before a save changes the URLs of a page with subpages.
 *
 * @param {number}  count   Subpages at every level.
 * @param {boolean} capped  Whether more exist.
 * @param {boolean} serving Whether built-in redirects are on.
 * @return {string} Sentence.
 */
export function saveWarning( count, capped, serving ) {
	const first = sprintf(
		/* translators: %s: number of subpages, such as 12 or 1,000+. */
		_n(
			'Saving changes this page’s URL and the URL of %s subpage.',
			'Saving changes this page’s URL and the URLs of %s subpages.',
			count,
			'hierarchy-guard'
		),
		formatCount( count, capped )
	);
	return (
		first +
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
}

/**
 * The Quick Edit warning when the slug or parent of a page with subpages changes.
 *
 * @param {number}  count  Subpages at every level.
 * @param {boolean} capped Whether more exist.
 * @return {string} Sentence.
 */
export function quickEditWarning( count, capped ) {
	return sprintf(
		/* translators: %s: number of subpages, such as 12 or 1,000+. */
		_n(
			'Changing this moves %s subpage to a new URL.',
			'Changing this moves %s subpages to new URLs.',
			count,
			'hierarchy-guard'
		),
		formatCount( count, capped )
	);
}

/**
 * The sentence after a save changed URLs, by what handles redirects.
 *
 * @param {number}      count    Old URLs that now lead somewhere new.
 * @param {boolean}     capped   Whether more exist.
 * @param {boolean}     serving  Whether built-in redirects are on.
 * @param {Object|null} provider Detected redirect plugin.
 * @return {string} Sentence.
 */
export function changedSentence( count, capped, serving, provider ) {
	const shown = formatCount( count, capped );
	if ( serving ) {
		return sprintf(
			/* translators: %s: number of old URLs, such as 12 or 1,000+. */
			_n(
				'The %s old URL redirects to its new page.',
				'The %s old URLs redirect to their new pages.',
				count,
				'hierarchy-guard'
			),
			shown
		);
	}
	if ( provider ) {
		return sprintf(
			/* translators: %s: number of URLs, such as 12 or 1,000+. */
			_n(
				'%s URL changed.',
				'%s URLs changed.',
				count,
				'hierarchy-guard'
			),
			shown
		);
	}
	return sprintf(
		/* translators: %s: number of old URLs, such as 12 or 1,000+. */
		_n(
			'%s old URL now shows “Page not found”.',
			'%s old URLs now show “Page not found”.',
			count,
			'hierarchy-guard'
		),
		shown
	);
}

/**
 * "View redirects (12)".
 *
 * @param {number}  count  Pairs.
 * @param {boolean} capped Whether more exist.
 * @return {string} Label.
 */
export function viewRedirectsLabel( count, capped ) {
	return sprintf(
		/* translators: %s: number of redirects, such as 12 or 1,000+. */
		__( 'View redirects (%s)', 'hierarchy-guard' ),
		formatCount( count, capped )
	);
}

/**
 * What trashing a page would do to its subpages, for the editor panel.
 *
 * @param {Object} data Hierarchy from the REST route.
 * @return {string} Sentence, or an empty string without subpages.
 */
export function trashNote( data ) {
	const children = data.children_total;
	const all = data.descendants.count;
	if ( ! children ) {
		return '';
	}
	if ( 'trash_children' === data.trash_policy ) {
		return sprintf(
			/* translators: %s: number of subpages at every level. */
			_n(
				'Trashing this page moves its %s subpage to the Trash too.',
				'Trashing this page moves its %s subpages to the Trash too.',
				all,
				'hierarchy-guard'
			),
			formatCount( all )
		);
	}
	if ( 'keep' === data.trash_policy ) {
		return sprintf(
			/* translators: %s: number of direct subpages. */
			_n(
				'Trashing this page leaves its %s subpage where it is.',
				'Trashing this page leaves its %s subpages where they are.',
				children,
				'hierarchy-guard'
			),
			formatCount( children )
		);
	}
	if ( data.trash_to ) {
		return sprintf(
			/* translators: 1: number of direct subpages, 2: title of the page they would move under. */
			_n(
				'Trashing this page moves its %1$s subpage under “%2$s”.',
				'Trashing this page moves its %1$s subpages under “%2$s”.',
				children,
				'hierarchy-guard'
			),
			formatCount( children ),
			data.trash_to.title
		);
	}
	return sprintf(
		/* translators: %s: number of direct subpages. */
		_n(
			'Trashing this page makes its %s subpage a top-level page.',
			'Trashing this page makes its %s subpages top-level pages.',
			children,
			'hierarchy-guard'
		),
		formatCount( children )
	);
}

/**
 * Why an old URL has no new one.
 *
 * @param {Object} row Unresolved row from the pairs route.
 * @return {string} Sentence.
 */
export function unresolvedSentence( row ) {
	if ( 'trash' === row.reason ) {
		return sprintf(
			/* translators: %s: an old URL. */
			__(
				'%s is in the Trash, so it has no new URL.',
				'hierarchy-guard'
			),
			row.old
		);
	}
	if ( 'unpublished' === row.reason ) {
		return sprintf(
			/* translators: %s: an old URL. */
			__( '%s leads to a page that isn’t published.', 'hierarchy-guard' ),
			row.old
		);
	}
	return sprintf(
		/* translators: %s: an old URL. */
		__( '%s has no new URL.', 'hierarchy-guard' ),
		row.old
	);
}

/**
 * Pairs as plain text, one "old new" line each.
 *
 * @param {Array<{old: string, new: string}>} pairs Pairs.
 * @return {string} Text.
 */
export function pairsText( pairs ) {
	return pairs.map( ( pair ) => pair.old + ' ' + pair.new ).join( '\n' );
}
```

Create `src/common/api.js`:
```js
/**
 * Requests to the plugin's REST routes.
 */
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import config from './config';

const base = () => '/' + config.namespace;

/**
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
}

/**
 * The route and arguments for a pairs source.
 *
 * @param {Object} source Either { change } or { post, from }.
 * @param {string} kind   pairs or csv.
 * @return {{route: string, args: Object}} Route and arguments.
 */
export function sourceRoute( source, kind ) {
	if ( source.change ) {
		return {
			route: `${ config.namespace }/changes/${ source.change }/${ kind }`,
			args: {},
		};
	}
	return {
		route: `${ config.namespace }/posts/${ source.post }/${ kind }`,
		args: { from: source.from },
	};
}

/**
 * One page of pairs.
 *
 * @param {Object} source  Either { change } or { post, from }.
 * @param {number} page    Page number.
 * @param {number} perPage Pairs per page.
 * @return {Promise<Object>} Page of pairs.
 */
export function fetchPairs( source, page = 1, perPage = 20 ) {
	const { route, args } = sourceRoute( source, 'pairs' );
	return apiFetch( {
		path: addQueryArgs( '/' + route, {
			...args,
			page,
			per_page: perPage,
		} ),
	} );
}

/**
 * The CSV download URL for a source, signed for the REST cookie check.
 *
 * @param {Object} source Either { change } or { post, from }.
 * @param {string} format CSV layout.
 * @return {string} URL.
 */
export function csvUrl( source, format ) {
	const { route, args } = sourceRoute( source, 'csv' );
	const root = config.restUrl.includes( '?' )
		? addQueryArgs( config.restUrl, { rest_route: '/' + route } )
		: config.restUrl.replace( /\/?$/, '/' ) + route;
	return addQueryArgs( root, {
		...args,
		format,
		_wpnonce: config.restNonce,
	} );
}

/**
 * What trashing the given pages would do to their subpages.
 *
 * @param {number[]} ids Page IDs.
 * @return {Promise<Object>} Preview.
 */
export function trashPreview( ids ) {
	return apiFetch( {
		path: addQueryArgs( `${ base() }/trash-preview`, { ids } ),
	} );
}

/**
 * Hides the redirects nudge for the current user.
 *
 * @return {Promise<Object>} The user.
 */
export function dismissNudge() {
	return apiFetch( {
		path: '/wp/v2/users/me',
		method: 'POST',
		data: { meta: { hierarchy_guard_nudge_dismissed: true } },
	} );
}
```

- [ ] **Step 5: Create the classic-editor script**

Create `src/classic/index.js`:
```js
/**
 * The classic editor: a plain confirmation when the form is submitted with a
 * changed slug or parent on a published page with subpages.
 */
import domReady from '@wordpress/dom-ready';
import { _n, sprintf } from '@wordpress/i18n';
import { formatCount } from '../common/copy';

domReady( () => {
	const box = document.querySelector( '.hierarchy-guard-box' );
	const form = document.getElementById( 'post' );
	if ( ! box || ! form ) {
		return;
	}
	const count = Number( box.dataset.descendants || 0 );
	if ( ! count || 'publish' !== box.dataset.status ) {
		return;
	}

	const slug = document.getElementById( 'post_name' );
	const parent = document.getElementById( 'parent_id' );
	const initial = {
		slug: slug ? slug.value : '',
		parent: parent ? parent.value : '',
	};

	form.addEventListener( 'submit', ( event ) => {
		const edited =
			( slug ? slug.value : '' ) !== initial.slug ||
			( parent ? parent.value : '' ) !== initial.parent;
		if ( ! edited ) {
			return;
		}
		const message = sprintf(
			/* translators: %s: number of subpages, such as 12 or 1,000+. */
			_n(
				'Saving changes this page’s URL and the URL of %s subpage. Continue?',
				'Saving changes this page’s URL and the URLs of %s subpages. Continue?',
				count,
				'hierarchy-guard'
			),
			formatCount( count, '1' === box.dataset.capped )
		);
		// eslint-disable-next-line no-alert
		if ( ! window.confirm( message ) ) {
			event.preventDefault();
		}
	} );
} );
```

- [ ] **Step 6: Create the asset loader and wire it**

Create `includes/HierarchyGuard/Admin/Assets.php`:
```php
<?php
/**
 * Scripts for the editor panel, the list screen and the classic editor.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Admin;

use HierarchyGuard\Post_Types;
use HierarchyGuard\REST\Hierarchy_Controller;
use HierarchyGuard\Redirects\Providers;
use HierarchyGuard\Settings;
use HierarchyGuard\Subtree;
use WP_Screen;

/**
 * Enqueues the compiled bundles from build/, each with the dependencies its
 * .asset.php file lists, its translations, and the plugin's settings. A
 * bundle that was never built is skipped, so a checkout without npm still runs.
 */
final class Assets {

	public const HANDLE_PREFIX = 'hierarchy-guard-';

	/**
	 * Providers.
	 *
	 * @var Providers
	 */
	private Providers $providers;

	/**
	 * Plugin directory holding build/.
	 *
	 * @var string
	 */
	private string $dir;

	/**
	 * Constructor.
	 *
	 * @param Providers $providers Redirect plugin detection.
	 * @param string    $dir       Plugin directory holding build/.
	 */
	public function __construct( Providers $providers, string $dir = HIERARCHY_GUARD_DIR ) {
		$this->providers = $providers;
		$this->dir       = rtrim( $dir, '/\\' );
	}

	/**
	 * Hooks into core.
	 */
	public function register(): void {
		add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue_editor' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin' ] );
	}

	/**
	 * The editor panel, for supported types.
	 */
	public function enqueue_editor(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen instanceof WP_Screen || ! Post_Types::is_supported( (string) $screen->post_type ) ) {
			return;
		}
		$this->enqueue( 'editor' );
	}

	/**
	 * The list-screen script on supported list screens, and the classic-editor
	 * script where the block editor is off.
	 *
	 * @param mixed $hook_suffix Admin page.
	 */
	public function enqueue_admin( $hook_suffix ): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen instanceof WP_Screen || ! Post_Types::is_supported( (string) $screen->post_type ) ) {
			return;
		}
		if ( 'edit.php' === $hook_suffix ) {
			// Quick Edit's script defines the object the list-table script wraps.
			$this->enqueue( 'list-table', [ 'jquery', 'inline-edit-post' ] );
		} elseif ( in_array( $hook_suffix, [ 'post.php', 'post-new.php' ], true ) && ! $screen->is_block_editor() ) {
			$this->enqueue( 'classic' );
		}
	}

	/**
	 * Enqueues one bundle with its styles, translations and settings.
	 *
	 * @param string   $name  Bundle name, as in build/{name}.js.
	 * @param string[] $extra Script handles the bundle uses without importing them.
	 * @return bool Whether the bundle was built and enqueued.
	 */
	public function enqueue( string $name, array $extra = [] ): bool {
		$asset_file = $this->dir . '/build/' . $name . '.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			return false;
		}
		$asset = require $asset_file;
		if ( ! is_array( $asset ) ) {
			return false;
		}

		$handle  = self::HANDLE_PREFIX . $name;
		$deps    = array_values( array_unique( array_merge( array_map( 'strval', (array) ( $asset['dependencies'] ?? [] ) ), $extra ) ) );
		$version = (string) ( $asset['version'] ?? HIERARCHY_GUARD_VERSION );
		$url     = plugins_url( 'build/' . $name . '.js', $this->dir . '/hierarchy-guard.php' );

		wp_enqueue_script( $handle, $url, $deps, $version, true );
		wp_set_script_translations( $handle, 'hierarchy-guard' );
		wp_add_inline_script( $handle, 'window.hierarchyGuard = ' . wp_json_encode( $this->config() ) . ';', 'before' );

		$styles = in_array( 'wp-components', $deps, true ) ? [ 'wp-components' ] : [];
		if ( is_readable( $this->dir . '/build/' . $name . '.css' ) ) {
			wp_enqueue_style( $handle, plugins_url( 'build/' . $name . '.css', $this->dir . '/hierarchy-guard.php' ), $styles, $version );
		} elseif ( [] !== $styles ) {
			wp_enqueue_style( 'wp-components' );
		}

		return true;
	}

	/**
	 * What every bundle needs to know about the site.
	 *
	 * @return array<string,mixed>
	 */
	public function config(): array {
		$provider = $this->providers->detect();
		return [
			'namespace'   => Hierarchy_Controller::NAMESPACE,
			'serving'     => Settings::serve_redirects(),
			'provider'    => null === $provider ? null : [
				'name' => (string) ( $provider['name'] ?? '' ),
				'url'  => (string) ( $provider['url'] ?? '' ),
				'csv'  => (string) ( $provider['csv'] ?? 'generic' ),
			],
			'settingsUrl' => current_user_can( 'manage_options' ) ? Settings_Section::redirects_url() : '',
			'policyUrl'   => current_user_can( 'manage_options' ) ? Settings_Section::policy_url() : '',
			'policy'      => Settings::trash_policy(),
			'postTypes'   => Post_Types::supported(),
			'cap'         => Subtree::count_cap(),
			'restNonce'   => wp_create_nonce( 'wp_rest' ),
			'restUrl'     => esc_url_raw( rest_url() ),
		];
	}
}
```

In `includes/HierarchyGuard/Plugin.php`, replace:
```php
		( new Admin\Meta_Box( $overview ) )->register();
```
with:
```php
		( new Admin\Meta_Box( $overview ) )->register();
		( new Admin\Assets( $providers ) )->register();
```

- [ ] **Step 7: Run every check**

Run: `npm run test:js`
Expected: PASS (2 files).
Run: `npx wp-scripts lint-js src --fix; npm run lint:js && npm run lint:style && npm run build`
Expected: lint exits 0; webpack compiles `build/classic.js` and `build/classic.asset.php`.
Run: `composer lint:fix; composer lint && npm run test:php`
Expected: lint exits 0; the suite passes.

- [ ] **Step 8: Commit**

```bash
git add package.json package-lock.json webpack.config.js .distignore .github/workflows/ci.yml src includes/HierarchyGuard/Admin/Assets.php includes/HierarchyGuard/Plugin.php tests/phpunit/AssetsTest.php
git commit -m "feat: build tooling, shared copy, asset loader and classic-editor check"
```

### Task 11: The redirects modal and the block editor panel

**Files:**
- Modify: `package.json`, `package-lock.json` (through npm), `webpack.config.js`
- Create: `src/common/mount.js`, `src/common/redirects-modal.js`, `src/common/modal.scss`, `src/editor/index.js`, `src/editor/panel.js`, `src/editor/editor.scss`

**Interfaces:**
- Consumes: Task 5's routes, Task 10's `config`, `copy`, `api`.
- Produces: `src/common/mount.js` `open( render ): close` (renders into its own root at the end of `<body>`), `src/common/redirects-modal.js` default export `RedirectsModal( { source, onClose } )`. Task 12 uses both.
- Produces: the `hierarchy-guard` plugin with a "Hierarchy" `PluginDocumentSettingPanel` for supported types: parent pages, a page of child pages with their counts and non-published statuses, "View all N in the list", the save warning, the notice after a save that changed URLs (with "View redirects" and, when nothing handles redirects, "Turn on redirects"), old URLs with "View redirects", and the trash sentence with a "Settings" link.

The panel compares the edited slug and parent with the saved ones and warns only for a published page with subpages. After a save that isn't an autosave it reloads the hierarchy; a newest old path that wasn't there before means the save changed URLs.

- [ ] **Step 1: Install the component packages**

```bash
npm install --save-dev @wordpress/a11y@^4.57.0 @wordpress/components@^42.0.0 @wordpress/data@^10.57.0 @wordpress/editor@^15.2.0 @wordpress/element@^9.0.0 @wordpress/plugins@^7.57.0
```

- [ ] **Step 2: Create the modal**

Create `src/common/mount.js`:
```js
/**
 * Renders a component into its own root at the end of the page.
 */
import { createRoot } from '@wordpress/element';

/**
 * Opens a component until it calls close().
 *
 * @param {(close: () => void) => Element} render Receives close() and returns an element.
 * @return {() => void} close().
 */
export function open( render ) {
	const node = document.createElement( 'div' );
	document.body.appendChild( node );
	const root = createRoot( node );
	const close = () => {
		root.unmount();
		node.remove();
	};
	root.render( render( close ) );
	return close;
}
```

Create `src/common/redirects-modal.js`:
```js
/**
 * The "Redirects needed" modal: old and new URLs, paged, with what to do next.
 */
import { speak } from '@wordpress/a11y';
import { Button, Modal, Notice, Spinner } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { csvUrl, fetchPairs } from './api';
import config from './config';
import { formatCount, pairsText, unresolvedSentence } from './copy';

const PER_PAGE = 20;

/**
 * The modal.
 *
 * @param {Object}     props         Props.
 * @param {Object}     props.source  Either { change } or { post, from }.
 * @param {() => void} props.onClose Closes the modal.
 * @return {Element} Modal.
 */
export default function RedirectsModal( { source, onClose } ) {
	const [ page, setPage ] = useState( 1 );
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ text, setText ] = useState( '' );

	useEffect( () => {
		let live = true;
		setData( null );
		fetchPairs( source, page, PER_PAGE )
			.then( ( result ) => live && setData( result ) )
			.catch(
				( failure ) =>
					live &&
					setError(
						failure.message ||
							__(
								'The redirects could not be loaded.',
								'hierarchy-guard'
							)
					)
			);
		return () => {
			live = false;
		};
	}, [ source, page ] );

	const provider = config.provider;
	const layout = provider ? provider.csv : 'generic';

	const copy = () =>
		fetchPairs( source, 1, config.cap ).then( ( all ) => {
			const lines = pairsText( all.pairs );
			const fallback = () => setText( lines );
			if ( window.navigator.clipboard && window.isSecureContext ) {
				window.navigator.clipboard
					.writeText( lines )
					.then( () =>
						speak( __( 'Copied.', 'hierarchy-guard' ), 'polite' )
					)
					.catch( fallback );
			} else {
				fallback();
			}
		} );

	let intro;
	if ( config.serving ) {
		intro = __(
			'Hierarchy Guard redirects these old URLs to their new pages.',
			'hierarchy-guard'
		);
	} else if ( provider ) {
		intro = sprintf(
			/* translators: %s: name of the redirect plugin. */
			__( 'Add these in %s.', 'hierarchy-guard' ),
			provider.name
		);
	} else {
		intro = __(
			'Nothing on this site redirects these old URLs yet.',
			'hierarchy-guard'
		);
	}

	return (
		<Modal
			title={
				config.serving
					? __( 'Redirects', 'hierarchy-guard' )
					: __( 'Redirects needed', 'hierarchy-guard' )
			}
			onRequestClose={ onClose }
			className="hierarchy-guard-modal"
			size="medium"
		>
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
			{ ! error && ! data && <Spinner /> }
			{ data && (
				<>
					<p>{ intro }</p>
					{ data.unresolved.map( ( row ) => (
						<p key={ row.old } className="description">
							{ unresolvedSentence( row ) }
						</p>
					) ) }
					{ data.total > 0 ? (
						<table className="widefat striped hierarchy-guard-pairs">
							<thead>
								<tr>
									<th scope="col">
										{ __( 'Old URL', 'hierarchy-guard' ) }
									</th>
									<th scope="col">
										{ __( 'New URL', 'hierarchy-guard' ) }
									</th>
								</tr>
							</thead>
							<tbody>
								{ data.pairs.map( ( pair ) => (
									<tr key={ pair.old }>
										<td>
											<code>{ pair.old }</code>
										</td>
										<td>
											<code>{ pair.new }</code>
										</td>
									</tr>
								) ) }
							</tbody>
						</table>
					) : (
						<p>
							{ __(
								'No old URL needs a redirect.',
								'hierarchy-guard'
							) }
						</p>
					) }
					{ data.pages > 1 && (
						<div className="hierarchy-guard-modal__pager">
							<span>
								{ sprintf(
									/* translators: 1: first pair shown, 2: last pair shown, 3: pairs in all. */
									__(
										'Showing %1$s–%2$s of %3$s',
										'hierarchy-guard'
									),
									formatCount(
										( data.page - 1 ) * PER_PAGE + 1
									),
									formatCount(
										( data.page - 1 ) * PER_PAGE +
											data.pairs.length
									),
									formatCount( data.total, data.capped )
								) }
							</span>
							<Button
								variant="secondary"
								size="compact"
								disabled={ data.page <= 1 }
								onClick={ () => setPage( data.page - 1 ) }
							>
								{ __( 'Previous', 'hierarchy-guard' ) }
							</Button>
							<Button
								variant="secondary"
								size="compact"
								disabled={ data.page >= data.pages }
								onClick={ () => setPage( data.page + 1 ) }
							>
								{ __( 'Next', 'hierarchy-guard' ) }
							</Button>
						</div>
					) }
					{ text && (
						<textarea
							className="large-text code"
							rows={ 6 }
							readOnly
							value={ text }
							aria-label={ __(
								'Redirects as text',
								'hierarchy-guard'
							) }
							onFocus={ ( event ) => event.target.select() }
						/>
					) }
					<div className="hierarchy-guard-modal__actions">
						{ ! config.serving &&
							! provider &&
							config.settingsUrl && (
								<Button
									variant="primary"
									href={ config.settingsUrl }
								>
									{ __(
										'Turn on redirects',
										'hierarchy-guard'
									) }
								</Button>
							) }
						{ ! config.serving && provider && provider.url && (
							<Button
								variant="secondary"
								href={ provider.url }
								target="_blank"
								rel="noopener noreferrer"
							>
								{ sprintf(
									/* translators: %s: name of the redirect plugin. */
									__( 'Open %s', 'hierarchy-guard' ),
									provider.name
								) }
							</Button>
						) }
						{ data.total > 0 && (
							<Button
								variant="secondary"
								href={ csvUrl( source, layout ) }
								download
							>
								{ provider
									? sprintf(
											/* translators: %s: name of the redirect plugin. */
											__(
												'Download CSV for %s',
												'hierarchy-guard'
											),
											provider.name
										)
									: __( 'Download CSV', 'hierarchy-guard' ) }
							</Button>
						) }
						{ data.total > 0 && (
							<Button variant="tertiary" onClick={ copy }>
								{ __( 'Copy as text', 'hierarchy-guard' ) }
							</Button>
						) }
					</div>
				</>
			) }
		</Modal>
	);
}
```

Create `src/common/modal.scss`:
```scss
// Layout only: colours, radii and type come from the admin theme.
.hierarchy-guard-modal {

	.hierarchy-guard-pairs {
		margin: 12px 0;

		code {
			word-break: break-all;
		}
	}

	&__pager,
	&__actions {
		display: flex;
		flex-wrap: wrap;
		gap: 8px;
		align-items: center;
		margin-top: 12px;
	}
}
```

- [ ] **Step 3: Create the panel**

Create `src/editor/index.js`:
```js
/**
 * The Hierarchy panel in the block editor's document settings.
 */
import { registerPlugin } from '@wordpress/plugins';
import HierarchyPanel from './panel';
import '../common/modal.scss';
import './editor.scss';

registerPlugin( 'hierarchy-guard', { render: HierarchyPanel } );
```

Create `src/editor/panel.js`:
```js
/**
 * What is above and below the page being edited, a warning before a save
 * changes subpage URLs, and the redirects after it did.
 */
import { Button, Notice, Spinner } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import {
	PluginDocumentSettingPanel,
	store as editorStore,
} from '@wordpress/editor';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { fetchHierarchy, fetchPairs } from '../common/api';
import config from '../common/config';
import {
	changedSentence,
	countsLine,
	formatCount,
	saveWarning,
	subpagesLabel,
	trashNote,
	viewRedirectsLabel,
} from '../common/copy';
import RedirectsModal from '../common/redirects-modal';

/**
 * A title linked to its editor when the user may edit it.
 *
 * @param {Object} props      Props.
 * @param {Object} props.item Post reference from the REST route.
 * @return {Element} Link or text.
 */
function Title( { item } ) {
	return item.edit_link ? (
		<a href={ item.edit_link }>{ item.title }</a>
	) : (
		<span>{ item.title }</span>
	);
}

/**
 * The panel.
 *
 * @return {Element|null} Panel, or null for unsupported types.
 */
export default function HierarchyPanel() {
	const { postId, postType, status, urlEdited, saving, autosaving, saved } =
		useSelect( ( select ) => {
			const editor = select( editorStore );
			return {
				postId: editor.getCurrentPostId(),
				postType: editor.getCurrentPostType(),
				status: editor.getCurrentPostAttribute( 'status' ),
				urlEdited:
					editor.getEditedPostAttribute( 'slug' ) !==
						editor.getCurrentPostAttribute( 'slug' ) ||
					( editor.getEditedPostAttribute( 'parent' ) || 0 ) !==
						( editor.getCurrentPostAttribute( 'parent' ) || 0 ),
				saving: editor.isSavingPost(),
				autosaving: editor.isAutosavingPost(),
				saved: editor.didPostSaveRequestSucceed(),
			};
		}, [] );

	const supported = config.postTypes.includes( postType );
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ changed, setChanged ] = useState( null );
	const [ modal, setModal ] = useState( null );
	const newest = useRef( undefined );
	const wasSaving = useRef( false );

	const load = useCallback(
		() =>
			fetchHierarchy( postId )
				.then( ( result ) => {
					setData( result );
					setError( '' );
					return result;
				} )
				.catch( ( failure ) => {
					setError(
						failure.message ||
							__(
								'The hierarchy could not be loaded.',
								'hierarchy-guard'
							)
					);
					return null;
				} ),
		[ postId ]
	);

	useEffect( () => {
		if ( ! supported || ! postId ) {
			return;
		}
		load().then( ( result ) => {
			newest.current = result?.old_paths?.[ 0 ]?.path ?? null;
		} );
	}, [ supported, postId, load ] );

	// After a save, reload; a new old path means the save changed URLs.
	useEffect( () => {
		if ( saving && ! autosaving ) {
			wasSaving.current = true;
			return;
		}
		if ( saving || ! wasSaving.current ) {
			return;
		}
		wasSaving.current = false;
		if ( ! saved ) {
			return;
		}
		load().then( ( result ) => {
			const latest = result?.old_paths?.[ 0 ]?.path ?? null;
			if ( latest && latest !== newest.current ) {
				fetchPairs( { post: postId, from: latest }, 1, 1 ).then(
					( pairs ) =>
						setChanged( {
							from: latest,
							count: pairs.total,
							capped: pairs.capped,
						} )
				);
			}
			newest.current = latest;
		} );
	}, [ saving, autosaving, saved, load, postId ] );

	if ( ! supported ) {
		return null;
	}

	const published = 'publish' === status;
	const showWarning =
		data && published && urlEdited && data.descendants.count > 0;
	const trash = data ? trashNote( data ) : '';

	return (
		<PluginDocumentSettingPanel
			name="hierarchy-guard"
			title={ __( 'Hierarchy', 'hierarchy-guard' ) }
			className="hierarchy-guard-panel"
		>
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
			{ ! data && ! error && <Spinner /> }
			{ showWarning && (
				<Notice status="warning" isDismissible={ false }>
					{ saveWarning(
						data.descendants.count,
						data.descendants.capped,
						data.redirects.serving
					) }
				</Notice>
			) }
			{ changed && changed.count > 0 && (
				<Notice
					status={
						data?.redirects.serving || data?.redirects.provider
							? 'success'
							: 'warning'
					}
					onRemove={ () => setChanged( null ) }
					actions={ [
						...( ! data?.redirects.serving
							? [
									{
										label: viewRedirectsLabel(
											changed.count,
											changed.capped
										),
										onClick: () =>
											setModal( {
												post: postId,
												from: changed.from,
											} ),
									},
								]
							: [] ),
						...( ! data?.redirects.serving &&
						! data?.redirects.provider &&
						config.settingsUrl
							? [
									{
										label: __(
											'Turn on redirects',
											'hierarchy-guard'
										),
										url: config.settingsUrl,
									},
								]
							: [] ),
					] }
				>
					{ changedSentence(
						changed.count,
						changed.capped,
						data?.redirects.serving,
						data?.redirects.provider
					) }
				</Notice>
			) }
			{ data && (
				<div className="hierarchy-guard-panel__body">
					<div className="hierarchy-guard-panel__section">
						<p className="hierarchy-guard-panel__label">
							{ __( 'Parent pages', 'hierarchy-guard' ) }
						</p>
						{ data.ancestors.length ? (
							<p>
								{ data.ancestors.map( ( item, index ) => (
									<span key={ item.id }>
										{ index > 0 && ' / ' }
										<Title item={ item } />
									</span>
								) ) }
							</p>
						) : (
							<p>
								{ __(
									'None. This is a top-level page.',
									'hierarchy-guard'
								) }
							</p>
						) }
					</div>

					<div className="hierarchy-guard-panel__section">
						<p className="hierarchy-guard-panel__label">
							{ __( 'Child pages', 'hierarchy-guard' ) }
							{ data.children_total > 0 && (
								<span className="hierarchy-guard-panel__meta description">
									{ countsLine( data ) }
								</span>
							) }
						</p>
						{ data.children_total === 0 ? (
							<p>{ __( 'No subpages.', 'hierarchy-guard' ) }</p>
						) : (
							<ul className="hierarchy-guard-panel__list">
								{ data.children.map( ( child ) => (
									<li key={ child.id }>
										<Title item={ child } />
										{ 'publish' !== child.status &&
											' · ' + child.status_label }
										<span className="hierarchy-guard-panel__meta description">
											{ child.children_count > 0
												? subpagesLabel(
														child.children_count,
														child.children_capped
													)
												: __(
														'No subpages',
														'hierarchy-guard'
													) }
										</span>
									</li>
								) ) }
							</ul>
						) }
						{ data.has_more && (
							<p>
								<a href={ data.list_url }>
									{ sprintf(
										/* translators: %s: number of subpages at every level. */
										__(
											'View all %s in the list',
											'hierarchy-guard'
										),
										formatCount(
											data.descendants.count,
											data.descendants.capped
										)
									) }
								</a>
							</p>
						) }
					</div>

					{ data.old_paths.length > 0 && (
						<div className="hierarchy-guard-panel__section">
							<p className="hierarchy-guard-panel__label">
								{ __( 'Old URLs', 'hierarchy-guard' ) }
							</p>
							<ul className="hierarchy-guard-panel__list">
								{ data.old_paths.map( ( old ) => (
									<li key={ old.path }>
										<code>{ old.url }</code>
										<span className="hierarchy-guard-panel__meta description">
											{ data.redirects.serving
												? __(
														'Redirects here',
														'hierarchy-guard'
													)
												: __(
														'Not redirected',
														'hierarchy-guard'
													) }
										</span>
										<Button
											variant="link"
											onClick={ () =>
												setModal( {
													post: postId,
													from: old.path,
												} )
											}
										>
											{ __(
												'View redirects',
												'hierarchy-guard'
											) }
										</Button>
									</li>
								) ) }
							</ul>
						</div>
					) }

					{ trash && (
						<p className="hierarchy-guard-panel__note">
							{ trash }{ ' ' }
							{ config.policyUrl && (
								<a href={ config.policyUrl }>
									{ __( 'Settings', 'hierarchy-guard' ) }
								</a>
							) }
						</p>
					) }
				</div>
			) }
			{ modal && (
				<RedirectsModal
					source={ modal }
					onClose={ () => setModal( null ) }
				/>
			) }
		</PluginDocumentSettingPanel>
	);
}
```

Create `src/editor/editor.scss`:
```scss
// Layout only: colours, radii and type come from the admin theme.
.hierarchy-guard-panel {

	.components-notice {
		margin: 0 0 12px;
	}

	&__section {
		margin-bottom: 16px;

		p {
			margin: 0 0 4px;
		}
	}

	&__label {
		font-weight: 500;
	}

	&__meta {
		display: block;
	}

	&__list {
		margin: 0;

		li {
			margin-bottom: 8px;
		}
	}

	&__note {
		margin: 0;
	}
}
```

In `webpack.config.js`, replace:
```js
	entry: {
		classic: './src/classic/index.js',
	},
```
with:
```js
	entry: {
		editor: './src/editor/index.js',
		classic: './src/classic/index.js',
	},
```

- [ ] **Step 4: Run every check**

Run: `npx wp-scripts lint-js src --fix; npx wp-scripts lint-style "src/**/*.scss" --fix; npm run lint:js && npm run lint:style && npm run test:js && npm run build`
Expected: lint exits 0; tests pass; webpack compiles `build/editor.js`, `build/editor.css` and `build/editor.asset.php`, whose dependencies include `wp-components`, `wp-editor` and `wp-plugins`.
Run: `npm run test:php`
Expected: the suite passes.

- [ ] **Step 5: Look at it**

Run `npm run build`, open a published page with subpages in the block editor on the dev site (`npm run env:start`; http://localhost:8888, admin / password) and open Settings › Page › Hierarchy. Expected: the parent pages, the child pages with "N subpages", and the trash sentence. Edit the slug: the warning "Saving changes this page’s URL and the URLs of N subpages. You’ll get the redirects to add after saving." appears. Save: a notice says how many old URLs changed, and "View redirects (N)" opens a modal listing them.

- [ ] **Step 6: Commit**

```bash
git add package.json package-lock.json webpack.config.js src
git commit -m "feat: redirects modal and the block editor Hierarchy panel"
```

### Task 12: The list-screen script

**Files:**
- Modify: `webpack.config.js`
- Create: `src/list-table/index.js`, `src/list-table/trash-dialog.js`, `src/list-table/quick-edit.js`, `src/list-table/list-table.scss`

**Interfaces:**
- Consumes: Task 4's `.hierarchy-guard-cell` and `.hierarchy-guard-under`, Task 6's `trash-preview` route and `hierarchy_guard_policy` parameter, Task 8's `a.hierarchy-guard-view-redirects` and `.hierarchy-guard-nudge`, Task 10's `config`, `copy`, `api`, Task 11's `open()` and `RedirectsModal`; core's `inlineEditPost` and the `wp-notice-added` event.
- Produces: on supported list screens, a Trash row action or a bulk "Move to Trash" whose pages have subpages opens the trash dialog. It previews every page with subpages, shows the default policy's sentence, and offers the other two policies behind "Other options"; trash-the-subpages is disabled past the cap. Confirming follows core's own link or submits core's form with `hierarchy_guard_policy`; if the preview finds no subpages any more it proceeds at once. Quick Edit warns under the slug field when the slug or parent of a published page with subpages changes, and a save that recorded an old path adds a notice with "View redirects". The outcome notice's "View redirects" opens the modal. Dismissing the nudge saves the user setting. The subtree breadcrumb moves beside the heading as a `.subtitle`, the way core shows search results.

- [ ] **Step 1: Create the dialog**

Create `src/list-table/trash-dialog.js`:
```js
/**
 * The trash dialog: one sentence under the default policy, and the other
 * two policies behind "Other options".
 */
import { Button, Modal, Notice, Spinner } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { trashPreview } from '../common/api';
import config from '../common/config';
import {
	POLICIES,
	bulkSentence,
	deniedSentence,
	optionDescription,
	optionLabel,
	trashSentence,
} from '../common/copy';

/**
 * The dialog.
 *
 * @param {Object}                        props           Props.
 * @param {number[]}                      props.ids       Selected pages that have subpages.
 * @param {number}                        props.selected  Pages selected in all.
 * @param {() => void}                    props.onCancel  Closes the dialog.
 * @param {(policy: string|null) => void} props.onConfirm Trashes with a policy, or null for the default.
 * @return {Element} Modal.
 */
export default function TrashDialog( { ids, selected, onCancel, onConfirm } ) {
	const [ preview, setPreview ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ policy, setPolicy ] = useState( config.policy );
	const [ expanded, setExpanded ] = useState( false );
	const [ busy, setBusy ] = useState( false );

	useEffect( () => {
		trashPreview( ids )
			.then( ( result ) => {
				if ( ! result.pages.length ) {
					// The subpages are gone since the list loaded: nothing to ask.
					onConfirm( null );
					return;
				}
				setPreview( result );
			} )
			.catch( ( failure ) =>
				setError(
					failure.message ||
						__(
							'The subpages could not be checked.',
							'hierarchy-guard'
						)
				)
			);
		// The dialog asks once, for the pages it opened with.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	const single =
		preview && 1 === selected && 1 === preview.pages.length
			? preview.pages[ 0 ]
			: null;
	const title = single
		? sprintf(
				/* translators: %s: page title. */
				__( 'Move “%s” to the Trash?', 'hierarchy-guard' ),
				single.title
			)
		: sprintf(
				/* translators: %s: number of selected pages. */
				_n(
					'Move %s page to the Trash?',
					'Move %s pages to the Trash?',
					selected,
					'hierarchy-guard'
				),
				Number( selected ).toLocaleString()
			);
	const fallback = preview
		? preview.pages.some( ( page ) => page.fallback )
		: false;

	const confirm = () => {
		setBusy( true );
		onConfirm( policy );
	};

	return (
		<Modal
			title={ title }
			onRequestClose={ onCancel }
			className="hierarchy-guard-dialog"
			size="medium"
		>
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
			{ ! error && ! preview && <Spinner /> }
			{ preview && (
				<>
					<p>
						{ single
							? trashSentence( single, policy, config.cap )
							: bulkSentence( preview, policy ) }
					</p>
					{ preview.pages.some( ( page ) => page.denied ) && (
						<p>{ deniedSentence() }</p>
					) }
					<Button
						variant="link"
						aria-expanded={ expanded }
						aria-controls="hierarchy-guard-options"
						onClick={ () => setExpanded( ! expanded ) }
					>
						{ __( 'Other options', 'hierarchy-guard' ) }
					</Button>
					{ expanded && (
						<fieldset
							id="hierarchy-guard-options"
							className="hierarchy-guard-dialog__options"
						>
							<legend className="screen-reader-text">
								{ __(
									'What happens to the subpages',
									'hierarchy-guard'
								) }
							</legend>
							{ POLICIES.map( ( value ) => {
								const id = `hierarchy-guard-policy-${ value }`;
								const disabled =
									'trash_children' === value && fallback;
								return (
									<div
										key={ value }
										className="hierarchy-guard-dialog__option"
									>
										<input
											type="radio"
											id={ id }
											name="hierarchy-guard-policy"
											value={ value }
											checked={ policy === value }
											disabled={ disabled }
											aria-describedby={ `${ id }-description` }
											onChange={ () =>
												setPolicy( value )
											}
										/>
										<label htmlFor={ id }>
											{ optionLabel( value, single ) }
										</label>
										<p
											id={ `${ id }-description` }
											className="description"
										>
											{ optionDescription(
												value,
												single,
												config.cap
											) }
										</p>
									</div>
								);
							} ) }
						</fieldset>
					) }
				</>
			) }
			<div className="hierarchy-guard-modal__actions hierarchy-guard-dialog__actions">
				<Button variant="tertiary" onClick={ onCancel }>
					{ __( 'Cancel', 'hierarchy-guard' ) }
				</Button>
				<Button
					variant="primary"
					isDestructive
					isBusy={ busy }
					disabled={ ( ! preview && ! error ) || busy }
					onClick={ confirm }
				>
					{ __( 'Move to Trash', 'hierarchy-guard' ) }
				</Button>
			</div>
		</Modal>
	);
}
```

- [ ] **Step 2: Create the Quick Edit warning**

Create `src/list-table/quick-edit.js`:
```js
/**
 * The Quick Edit warning when the slug or parent of a published page with
 * subpages changes, and the redirects after such a save.
 */
import { fetchHierarchy, fetchPairs } from '../common/api';
import config from '../common/config';
import {
	changedSentence,
	quickEditWarning,
	viewRedirectsLabel,
} from '../common/copy';
import { open } from '../common/mount';
import RedirectsModal from '../common/redirects-modal';

/**
 * Rows whose Quick Edit is open, with what they had when it opened.
 *
 * @type {Map<number, {slug: string, parent: string, newest: string|null}>}
 */
const watched = new Map();

/**
 * Prints a notice under the heading saying the save changed URLs.
 *
 * @param {number} postId Saved post.
 * @param {string} from   The old path the save recorded.
 */
function announce( postId, from ) {
	fetchPairs( { post: postId, from }, 1, 1 ).then( ( pairs ) => {
		if ( ! pairs.total ) {
			return;
		}
		const end = document.querySelector( '.wp-header-end' );
		if ( ! end ) {
			return;
		}
		const notice = document.createElement( 'div' );
		notice.className = 'notice notice-info is-dismissible';
		const text = document.createElement( 'p' );
		text.textContent =
			changedSentence(
				pairs.total,
				pairs.capped,
				config.serving,
				config.provider
			) + ' ';
		if ( ! config.serving ) {
			const link = document.createElement( 'a' );
			link.href = '#';
			link.textContent = viewRedirectsLabel( pairs.total, pairs.capped );
			link.addEventListener( 'click', ( event ) => {
				event.preventDefault();
				const source = { post: postId, from };
				open( ( close ) => (
					<RedirectsModal source={ source } onClose={ close } />
				) );
			} );
			text.appendChild( link );
		}
		notice.appendChild( text );
		end.parentNode.insertBefore( notice, end.nextSibling );
		window.jQuery( document ).trigger( 'wp-notice-added' );
	} );
}

/**
 * Adds the warning to a row's Quick Edit form.
 *
 * @param {number} postId Post being edited.
 */
function attach( postId ) {
	const cell = document.querySelector(
		`#post-${ postId } .hierarchy-guard-cell`
	);
	const form = document.getElementById( `edit-${ postId }` );
	if (
		! cell ||
		! form ||
		! Number( cell.dataset.children || 0 ) ||
		'publish' !== cell.dataset.status
	) {
		return;
	}
	const slug = form.querySelector( 'input[name="post_name"]' );
	const field = slug ? slug.closest( 'label' ) : null;
	if ( ! field ) {
		return;
	}
	const parent = form.querySelector( 'select[name="post_parent"]' );

	const initial = { slug: slug.value, parent: parent ? parent.value : '' };
	const box = document.createElement( 'div' );
	box.className = 'notice notice-warning inline hierarchy-guard-quick-edit';
	box.hidden = true;
	box.setAttribute( 'role', 'status' );
	box.appendChild( document.createElement( 'p' ) );
	// Right under the slug, where the change is made.
	field.after( box );

	let descendants = null;
	const update = () => {
		const edited =
			slug.value !== initial.slug ||
			( parent ? parent.value : '' ) !== initial.parent;
		box.hidden = ! edited || ! descendants || ! descendants.count;
		if ( ! box.hidden ) {
			box.firstChild.textContent = quickEditWarning(
				descendants.count,
				descendants.capped
			);
		}
	};
	slug.addEventListener( 'input', update );
	if ( parent ) {
		parent.addEventListener( 'change', update );
	}

	fetchHierarchy( postId ).then( ( data ) => {
		descendants = data.descendants;
		watched.set( postId, {
			...initial,
			newest: data.old_paths[ 0 ] ? data.old_paths[ 0 ].path : null,
		} );
		update();
	} );
}

/**
 * Hooks into core's Quick Edit.
 */
export default function bindQuickEdit() {
	const inline = window.inlineEditPost;
	const $ = window.jQuery;
	if ( ! inline || ! inline.edit || ! $ ) {
		return;
	}

	const edit = inline.edit;
	inline.edit = function ( id, ...rest ) {
		const result = edit.call( this, id, ...rest );
		const postId = Number(
			'object' === typeof id ? inline.getId( id ) : id
		);
		if ( postId ) {
			attach( postId );
		}
		return result;
	};

	$( document ).on( 'ajaxSuccess', ( event, xhr, settings ) => {
		if ( 'string' !== typeof settings.data ) {
			return;
		}
		const params = new URLSearchParams( settings.data );
		const postId = Number( params.get( 'post_ID' ) );
		const before = watched.get( postId );
		if ( 'inline-save' !== params.get( 'action' ) || ! before ) {
			return;
		}
		watched.delete( postId );
		if (
			params.get( 'post_name' ) === before.slug &&
			( params.get( 'post_parent' ) || '' ) === before.parent
		) {
			return;
		}
		fetchHierarchy( postId ).then( ( data ) => {
			const latest = data.old_paths[ 0 ]
				? data.old_paths[ 0 ].path
				: null;
			if ( latest && latest !== before.newest ) {
				announce( postId, latest );
			}
		} );
	} );
}
```

- [ ] **Step 3: Create the entry and its styles**

Create `src/list-table/index.js`:
```js
/**
 * The list screen: the trash dialog, the Quick Edit warning, the redirects
 * modal behind the outcome notice, the filter subtitle and the nudge.
 */
import domReady from '@wordpress/dom-ready';
import { dismissNudge } from '../common/api';
import { open } from '../common/mount';
import RedirectsModal from '../common/redirects-modal';
import bindQuickEdit from './quick-edit';
import TrashDialog from './trash-dialog';
import '../common/modal.scss';
import './list-table.scss';

const POLICY_PARAM = 'hierarchy_guard_policy';

/**
 * A row's live child count, from the Hierarchy column's cell.
 *
 * @param {number} id Post ID.
 * @return {number} Count.
 */
function childCount( id ) {
	const cell = document.querySelector(
		`#post-${ id } .hierarchy-guard-cell`
	);
	return cell ? Number( cell.dataset.children || 0 ) : 0;
}

/**
 * Moves the "Subpages of …" sentence beside the heading, the way core shows search results.
 */
function moveSubtitle() {
	const under = document.querySelector( '.hierarchy-guard-under' );
	const end = document.querySelector( '.wp-header-end' );
	if ( under && end ) {
		under.classList.add( 'subtitle' );
		end.parentNode.insertBefore( under, end );
	}
}

/**
 * Asks before the row action trashes a page with subpages.
 */
function bindRowTrash() {
	document.addEventListener( 'click', ( event ) => {
		const link = event.target.closest( '#the-list .row-actions .trash a' );
		if ( ! link || event.defaultPrevented ) {
			return;
		}
		const row = link.closest( 'tr' );
		const id = row ? Number( row.id.replace( 'post-', '' ) ) : 0;
		if ( ! id || ! childCount( id ) ) {
			return;
		}
		event.preventDefault();
		open( ( close ) => (
			<TrashDialog
				ids={ [ id ] }
				selected={ 1 }
				onCancel={ close }
				onConfirm={ ( policy ) => {
					const url = new URL( link.href );
					if ( policy ) {
						url.searchParams.set( POLICY_PARAM, policy );
					}
					window.location.href = url.toString();
				} }
			/>
		) );
	} );
}

/**
 * Asks before the bulk action trashes pages with subpages.
 */
function bindBulkTrash() {
	const form = document.getElementById( 'posts-filter' );
	if ( ! form ) {
		return;
	}
	form.addEventListener( 'submit', ( event ) => {
		const top = form.querySelector( 'select[name="action"]' );
		const bottom = form.querySelector( 'select[name="action2"]' );
		const action =
			top && '-1' !== top.value ? top.value : bottom && bottom.value;
		if ( 'trash' !== action ) {
			return;
		}
		const selected = Array.from(
			form.querySelectorAll( 'input[name="post[]"]:checked' )
		).map( ( box ) => Number( box.value ) );
		const parents = selected.filter( ( id ) => childCount( id ) > 0 );
		if ( ! parents.length ) {
			return;
		}
		event.preventDefault();
		open( ( close ) => (
			<TrashDialog
				ids={ parents }
				selected={ selected.length }
				onCancel={ close }
				onConfirm={ ( policy ) => {
					if ( policy ) {
						const input = document.createElement( 'input' );
						input.type = 'hidden';
						input.name = POLICY_PARAM;
						input.value = policy;
						form.appendChild( input );
					}
					form.submit();
				} }
			/>
		) );
	} );
}

/**
 * Opens the redirects modal from the outcome notice's link.
 */
function bindViewRedirects() {
	document.addEventListener( 'click', ( event ) => {
		const link = event.target.closest( 'a.hierarchy-guard-view-redirects' );
		if ( ! link ) {
			return;
		}
		event.preventDefault();
		const source = { change: link.dataset.change };
		open( ( close ) => (
			<RedirectsModal source={ source } onClose={ close } />
		) );
	} );
}

/**
 * Remembers that the nudge was dismissed.
 */
function bindNudge() {
	document.addEventListener( 'click', ( event ) => {
		if (
			event.target.closest( '.hierarchy-guard-nudge .notice-dismiss' )
		) {
			dismissNudge().catch( () => {} );
		}
	} );
}

domReady( () => {
	moveSubtitle();
	bindRowTrash();
	bindBulkTrash();
	bindViewRedirects();
	bindNudge();
	bindQuickEdit();
} );
```

Create `src/list-table/list-table.scss`:
```scss
// Layout only: colours, radii and type come from the admin theme.
.hierarchy-guard-dialog {

	&__options {
		margin: 12px 0 0;
	}

	&__option {
		display: grid;
		grid-template-columns: auto 1fr;
		column-gap: 8px;
		margin-bottom: 12px;

		input {
			margin-top: 2px;
		}

		.description {
			grid-column: 2;
			margin: 2px 0 0;
		}
	}

	&__actions {
		justify-content: flex-end;
	}
}

.hierarchy-guard-quick-edit {
	margin: 8px 0 0;
}

// Core gives Author and Date fixed widths and leaves the rest to Title.
.fixed .column-hierarchy_guard {
	width: 20%;
}
```

In `webpack.config.js`, replace:
```js
	entry: {
		editor: './src/editor/index.js',
		classic: './src/classic/index.js',
	},
```
with:
```js
	entry: {
		editor: './src/editor/index.js',
		'list-table': './src/list-table/index.js',
		classic: './src/classic/index.js',
	},
```

- [ ] **Step 4: Run every check**

Run: `npx wp-scripts lint-js src --fix; npx wp-scripts lint-style "src/**/*.scss" --fix; npm run lint:js && npm run lint:style && npm run test:js && npm run build`
Expected: lint exits 0; tests pass; webpack compiles `build/list-table.js`, `build/list-table.css` and `build/list-table.asset.php`.
Run: `npm run test:php`
Expected: the suite passes.

- [ ] **Step 5: Look at it**

On the dev site's Pages list, click Trash on a page with subpages. Expected: a dialog "Move “Title” to the Trash?" with one sentence; "Other options" shows the three policies. Cancel closes it; "Move to Trash" trashes and lands on the outcome notice, whose "View redirects (N)" opens the modal. Open Quick Edit on a published page with subpages and change its slug: the warning appears under the slug field.

- [ ] **Step 6: Commit**

```bash
git add webpack.config.js src
git commit -m "feat: trash dialog, Quick Edit warning and redirects modal on the list screen"
```

### Task 13: README, plugin description, and a live check

**Files:**
- Modify: `README.md`, `hierarchy-guard.php` (Description header), `composer.json` (description)

- [ ] **Step 1: Describe the admin UI**

In `README.md`, make these replacements.

Replace:
```markdown
Records every page URL change and redirects old subpage URLs to their new location. The admin views and warnings described in the design spec arrive in later releases.
```
with:
```markdown
Shows the parent and child structure of pages in wp-admin, warns before a slug, parent or trash change breaks subpage URLs, and records every old URL so it can reach the page it leads to now. Built-in redirects are off by default: with a redirect plugin active, Hierarchy Guard lists the redirects to add there; with none, turn on Redirects in Settings › Writing › Page hierarchy.

## In the admin

- **Pages list.** A Hierarchy column shows each page's parent and its number of subpages. The number, and the "View subpages" row action, narrow the list to that page's subtree; the heading then says which subtree is shown, with each ancestor one click away. Large subtrees list their first 1,000 pages.
- **Trashing from the list.** Trashing a page with subpages, from its row or in bulk, opens a dialog that says what happens to the subpages and offers the other two choices. Quick Edit warns when a changed slug or parent would move subpages to new URLs.
- **After a trash.** The notice says what happened to the subpages and how many URLs changed, with Undo. "View redirects" lists each old URL and its new one, links to the detected redirect plugin, downloads a CSV in its import layout, or copies the list as text. Without JavaScript the link downloads the CSV. The list stays available for 30 minutes.
- **Editor.** The block editor's Hierarchy panel shows the parent pages, the subpages, and the page's old URLs, warns before a save changes subpage URLs, and offers the redirects after it. The classic editor gets the same information in a box, and asks before saving such a change.
```

Replace:
```markdown
Turn on Redirects in Settings › Writing › Page hierarchy and visitors on an old URL reach the new one.
```
with:
```markdown
With Redirects on, visitors on an old URL reach the new one.
```

Replace:
```markdown
Requires Docker, Node 22+, Composer and PHP 8.0+ on the host.
```
with:
```markdown
Requires Docker, Node 22.22 or 24.15+, Composer and PHP 8.0+ on the host.
```

Replace:
````markdown
npm run test:php -- --filter PathsTest
composer lint              # PHPCS
```
````
with:
````markdown
npm run test:php -- --filter PathsTest
composer lint              # PHPCS
npm run build              # compile src/ into build/ (npm start watches)
npm run test:js            # Vitest
npm run lint:js            # ESLint; lint:style runs Stylelint
```

`build/` is not committed; release zips include it.
````

In `hierarchy-guard.php`, replace:
```php
 * Description:       Records page URL changes and redirects old subpage URLs to their new location.
```
with:
```php
 * Description:       Shows page hierarchy in wp-admin, warns before a slug, parent or trash change breaks subpage URLs, and keeps old URLs reaching their pages.
```

In `composer.json`, replace:
```json
"description": "Records page URL changes and redirects old subpage URLs to their new location.",
```
with:
```json
"description": "Shows page hierarchy in wp-admin, warns before a slug, parent or trash change breaks subpage URLs, and keeps old URLs reaching their pages.",
```

Run: `composer validate && composer lint && npm run test:php`
Expected: `./composer.json is valid` (the description is not part of the lock file's content hash); lint exits 0; the suite passes.

- [ ] **Step 2: Live check**

With `npm run build` done and the dev site running, build a tree: a published top-level page "Studio" with subpages "Team" and "History", "Team" with "Ada" (published) and "Bo" (draft), and "Ada" with "Lab". Turn built-in redirects off and use no redirect plugin. Report what you see for each:

1. Pages list: the Hierarchy column reads "2 subpages" for Studio and "Studio · 2 subpages" for Team; Team's row has "View subpages"; a dismissible notice offers to turn on redirects.
2. Click "2 subpages" on Team: the list shows Ada, Bo and Lab, and "Subpages of Studio › Team · Clear" sits beside the heading.
3. Trash Team from its row: the dialog says "“Team” has 2 subpages (3 pages in total). They’ll move up under “Studio” and get new URLs." Confirm: the notice reads "“Team” moved to the Trash. 2 subpages moved up under “Studio”. 2 old URLs now show “Page not found”. Turn on redirects · View redirects (2) Undo".
4. "View redirects (2)": the modal lists `/studio/team/ada/ → /studio/ada/` and `/studio/team/ada/lab/ → /studio/ada/lab/` (with your slugs), says Team's own URL is in the Trash, and "Download CSV" downloads them. Click Undo: "“Team” restored from the Trash. 2 subpages moved back under “Team”."
5. Bulk-select Team, Ada and History, choose Move to Trash: the dialog says "2 of the selected pages have subpages …". Pick "Leave the subpages where they are" under Other options and confirm: Bo stays under Team. Undo.
6. Open Studio in Quick Edit and change its slug: the warning "Changing this moves 5 subpages to new URLs." appears under the slug field. Save: a notice "5 old URLs now show “Page not found”. View redirects (5)" appears. (Studio, History, Team, Ada and Lab are published; Bo is a draft.) Change the slug back.
7. Open Team in the block editor: the Hierarchy panel lists Studio, then Ada ("1 subpage") and "Bo · Draft". Change the slug and save: the panel says how many old URLs changed, with "View redirects".
8. Settings › Writing: the trash-policy help reads "Used when there’s no chance to ask, for example in the editor, the REST API, or WP-CLI. The Pages list asks each time."

Delete the test pages afterwards.

- [ ] **Step 3: Commit**

```bash
git add README.md hierarchy-guard.php composer.json
git commit -m "docs: describe the admin UI"
```

## Self-Review

**Spec coverage.** Editor sidebar panel with parent pages, child pages ("N direct · M in total"), status only when not published, "View all", the warning when the slug or parent differs from the saved value on a page with subpages, previous URLs and their state, and the trash sentence with a Settings link: Tasks 5 and 11. Classic editor read-only box and a plain confirmation on submit: Tasks 9 and 10. Hierarchy column with the parent as a link and the subpage count as a link to the subtree, from one query per screen: Task 4. Subtree filter reachable from the count and a "View subpages" row action, its state beside the heading, a sort order so core paginates normally, capped at 1,000: Tasks 4 and 12. Trash dialog for row and bulk trash with one sentence under the default policy, "Other options", example URLs, the bulk sentence, the permission note, and the choice travelling with the request: Tasks 6 and 12. Quick Edit inline warning: Task 12. Outcome notice with Undo and "View redirects", in three variants by what handles redirects, scoped to the list screen and shown once: Task 8. Redirects modal, computed on demand and paged, with the provider's deep link, a CSV in its import layout and "Copy as text": Tasks 2, 3, 5, 7 and 11. A one-time dismissible nudge on the Pages screen when nothing handles redirects: Tasks 8 and 12. Batch progress notice: Task 8. REST: one read-only hierarchy route behind `edit_post`, children paged, counts direct, totals capped, and the change pairs and CSV routes: Tasks 5 and 7. Plan 2's hand-offs: the dialog's choice through `hierarchy_guard_trash_policy` (Task 6), the outcome notice replacing `Trash_Notice` and reading `Change_Log::latest()` and the queue (Task 8), the spec's trash-policy help text with the flipped assertion, the widened Description, the guarded `$provider['name']` and the leaking `SettingsSectionTest` globals (Tasks 8 and 13). Out of this plan by design: the Tree view (plan 4), the DataViews field and trash action, the `hierarchy_guard` REST page field, the editor snackbar, readme.txt, uninstall and the release zip (plan 5). The spec's parent dropdown is replaced by the breadcrumb (see Spec above).

**Placeholder scan.** No TBD or TODO. Every code step carries complete code taken from the verified prototype; the two install steps name exact versions.

**Type consistency.** A pairs block (`post_type`, `old`, `head`, `reason`, `children` of `id`, `parent`, `segment`, `status`) is built only by `Pairs::post_block()` and `Pairs::change_blocks()` and read only by `Pairs`. `Pairs::page()` returns `pairs`, `unresolved`, `total`, `capped`, `page` and `pages`, which `RedirectsModal` reads. `Overview::describe()` keys match the panel (`ancestors`, `children`, `children_total`, `children_capped`, `has_more`, `descendants`, `old_paths`, `redirects`, `trash_policy`, `trash_to`, `list_url`), `Meta_Box`, `trashNote()` and `countsLine()`. The trash preview's `pages[]` keys (`title`, `path`, `children`, `descendants`, `fallback`, `to`, `example`, `denied`) match `trashSentence()`, `bulkSentence()`, `optionLabel()` and `optionDescription()`. The `data-children` and `data-status` attributes printed by `List_Table` are what the list-table script reads; `data-descendants`, `data-capped` and `data-status` printed by `Meta_Box` are what the classic script reads. `Assets::config()` keys match `src/common/config.js` defaults.

**Review Focus.** All five named tests exist: two in Task 2 and one each in Tasks 4, 6 and 7.

**Undo.** The outcome notice keeps core's own Undo link. Core's Undo already restores each page with the status it had (`wp_untrash_post_set_previous_status`), and plan 2's restore flow then moves the subpages back, so the list screen needs no Undo route of its own. `Trash_Handler::undo()` stays for plan 5, where a DataViews trash stays on screen and its snackbar needs an Undo that isn't a page load.

## Hand-offs to plan 4 (Tree view)

- Add "Tree" to the views row through `views_edit-{type}` next to All and Published, as a view of the same list screen. Reuse `List_Table::filter_url()` for "View subpages" links and `Subtree::child_counts()` for each level's counts, one query per level.
- Children load on demand from `GET hierarchy-guard/v1/posts/{id}/hierarchy?children_page=N` (10 per page today). The tree probably needs a larger page and a lighter response; add a parameter rather than changing the panel's response.
- The list-table bundle (`src/list-table/`) is where the tree grid behaviour can live, or add a `tree` entry to `webpack.config.js`; `Assets::enqueue()` takes any built bundle.
- Without JavaScript the toggles are links that reload the view opened at that node, as the spec says.

## Hand-offs to plan 5 (DataViews and release)

- The `hierarchy_guard` field on the REST page object: for a list of pages, compute counts with `Subtree::child_counts()` once for the response, not once per object. It carries the change ID after a DataViews trash, for the snackbar and `RedirectsModal` (`{ change }` source).
- The DataViews trash action calls `Trash_Handler::undo()` for its Undo.
- Uninstall removes the `hierarchy_guard_nudge_dismissed` user meta along with plan 2's markers, jobs, locks and transients.
- readme.txt documents the list column, the dialog, the modal and the panel, and the release zip runs `npm ci && npm run build` (the `.distignore` already keeps `build/` and drops `src/` and `webpack.config.js`).
