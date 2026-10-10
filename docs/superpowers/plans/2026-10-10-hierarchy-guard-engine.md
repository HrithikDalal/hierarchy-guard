# Hierarchy Guard Engine Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the engine of the Hierarchy Guard WordPress plugin: record every URL change on hierarchical post types in a post-meta ledger, resolve old URLs at 404 time by longest-prefix match, optionally serve 301s, detect installed redirect plugins, and expose the three settings on Settings › Writing.

**Architecture:** Namespaced PHP (`HierarchyGuard\`) under `includes/HierarchyGuard/`, one class per responsibility, wired by `Plugin::register()`. State lives in post meta (one row per URL change, path hashed into the meta key for index seeks), one small root-paths option, and one settings option. `Change_Listener` turns `post_updated` and `before_delete_post` into ledger writes; `Redirects\Resolver` turns a 404 path into a target post; `Redirects\Server` turns that into a redirect at `template_redirect` priority 9. Tests run against the WordPress test suite inside wp-env.

**Tech Stack:** PHP 8.0+, WordPress 6.6+, Composer (autoload + dev tools only, no runtime dependencies), `@wordpress/env` for the test environment, PHPUnit 9.6 with Yoast polyfills, PHPCS with WordPress-Extra, WordPress-VIP-Go and PHPCompatibilityWP, GitHub Actions.

**Spec:** `docs/superpowers/specs/hierarchy-guard-spec.html` in this repo is a snapshot of the live spec at https://claude.ai/artifact/6V5RMR2w49uPiEp96ycyzq. The artifact is the source of truth; the snapshot exists so executors can read it offline. Later plans cover the trash flow, the admin UI, and the DataViews track plus release.

## Global Constraints

- Requires WordPress 6.6 and PHP 8.0. Plugin header: `Requires at least: 6.6`, `Requires PHP: 8.0`.
- Slug and text domain: `hierarchy-guard`. Namespace: `HierarchyGuard`. Option, meta, filter and cache prefixes: `hierarchy_guard_` / `_hierarchy_guard_`.
- No custom database tables. No runtime Composer dependencies. Nothing in a web request may grow with subtree size.
- Ledger meta key: `_hierarchy_guard_path_` followed by `md5( $post_type . ':' . $path )`. Value: the normalized page path without the post type's rewrite prefix, for example `about/team`.
- Root-paths option: `hierarchy_guard_root_paths`, not autoloaded, per post type, capped at 200 entries.
- Settings option: `hierarchy_guard_settings` with keys `post_types` (array, `null` when never saved means all public hierarchical types), `trash_policy` (`reparent` default, `trash_children`, `keep`), `serve_redirects` (bool, default `false`).
- Cache group: `hierarchy_guard`, with its own `wp_cache_get_last_changed( 'hierarchy_guard' )` key bumped only by the plugin's own writes. Misses are cached for 12 hours.
- Redirect handler: `template_redirect` priority 9, GET and HEAD only, status 301 by default, URL passed through `wp_validate_redirect()`. Resolver: at most 5 rounds, seen-set, final target must satisfy `is_post_publicly_viewable()`.
- Recording happens only when the old URL was publicly reachable: pretty permalinks on, supported type, status `publish`, non-empty slug, no `__trashed` segment, neither status in `trash`.
- Copy rules for any admin string: sentence case, the word "subpages", no product name inside UI text, no exclamation marks.
- Coding standard: WordPress-Extra (short array syntax allowed, PSR-4 file names allowed), WordPress-VIP-Go, PHPCompatibilityWP with `testVersion 8.0-`. Every file declares `strict_types=1`.
- `composer lint` must exit 0, warnings included. Run `composer lint:fix` (phpcbf) first; formatting-only changes phpcbf makes (array line breaks, `=`/`=>` alignment) are expected and are not deviations from this plan. Resolve any remaining warning with a behavior-neutral change, or with a targeted `// phpcs:ignore Sniff.Name.Code -- reason` on the line before. If PHPCS misbehaves under the host's PHP 8.5, run it in the container instead: `npx wp-env run cli --env-cwd=wp-content/plugins/hierarchy-guard vendor/bin/phpcs`.
- Tests that check a hook is registered must find the plugin's own object in `$GLOBALS['wp_filter'][ $hook ]->callbacks[ $priority ]`; `has_action( $hook )` alone is always true because core registers callbacks on the same hooks.

## Review Focus

1. Percent-encoded (non-ASCII) slugs in the request versus stored slugs: a Cyrillic page slug must resolve whether the request arrives raw or decoded. Pinned in Task 3 (`test_normalize_treats_encoded_and_decoded_slugs_alike`) and Task 7 (`test_unicode_slugs_resolve`).
2. The `/index.php/%postname%/` permalink structure: the query var excludes `index.php` while the generated permalink includes it; the redirect must land on the real URL. Pinned in Task 7 (`test_index_php_permalink_structure`).
3. An attachment that shares a slug with a page in the same position of the tree: `get_page_by_path()` includes attachments unless given an array of types, and must never produce a redirect to an attachment. Pinned in Task 6 (`test_attachment_with_same_slug_does_not_hijack`).
4. A new page created later at an old path: the request no longer 404s, so no redirect fires and the stale ledger entry is harmless. Pinned in Task 7 (`test_a_new_page_at_the_old_path_is_served_not_redirected`).
5. Core's own trash and untrash transitions, which rename the slug to `about__trashed` and back: the listener must record nothing for them, or the ledger fills with junk paths. Pinned in Task 5 (`test_trash_and_untrash_transitions_record_nothing`).

---

## File Structure

```
hierarchy-guard/
├── hierarchy-guard.php                      plugin header, constants, PSR-4 autoloader, Plugin::instance()->register()
├── composer.json                            dev tools + PSR-4 map; platform php 8.0.30
├── package.json                             wp-env scripts
├── .wp-env.json                             PHP 8.2, latest core, this plugin
├── phpunit.xml.dist                         bootstrap + tests/phpunit/*Test.php
├── phpcs.xml.dist                           WordPress-Extra, VIP-Go, PHPCompatibilityWP
├── .gitignore · .distignore · README.md
├── .github/workflows/ci.yml                 phpcs + phpunit matrix
├── includes/HierarchyGuard/
│   ├── Plugin.php                           singleton; wires hooks
│   ├── Settings.php                         option access, defaults, sanitize, policy constants
│   ├── Post_Types.php                       supported hierarchical types, memoised, filterable
│   ├── Paths.php                            normalize(), uri(), is_recordable()
│   ├── Ledger.php                           key(), record(), record_root(), paths_for(), prune(), find()
│   ├── Change_Listener.php                  post_updated, before_delete_post, suspend flag
│   ├── Redirects/Resolver.php               resolve( $post_type, $path ): ?WP_Post
│   ├── Redirects/Server.php                 target_for_current_request(), maybe_redirect()
│   ├── Redirects/Providers.php              all(), detect()
│   └── Admin/Settings_Section.php           Settings › Writing section and fields
└── tests/phpunit/
    ├── bootstrap.php
    ├── PluginTest.php · SettingsTest.php · PostTypesTest.php · PathsTest.php
    ├── LedgerTest.php · ChangeListenerTest.php · ResolverTest.php · ServerTest.php
    └── ProvidersTest.php · SettingsSectionTest.php
```

Each class has one job. `Paths` and `Ledger` are pure data helpers with no hooks. `Change_Listener`, `Server` and `Settings_Section` are the only classes that call `add_action`/`add_filter`, and only inside their `register()` methods, so tests can construct them without side effects.

---

### Task 1: Repository scaffold and test harness

**Files:**
- Create: `hierarchy-guard.php`, `composer.json`, `package.json`, `.wp-env.json`, `phpunit.xml.dist`, `phpcs.xml.dist`, `.gitignore`, `.distignore`, `README.md`
- Create: `includes/HierarchyGuard/Plugin.php`
- Create: `tests/phpunit/bootstrap.php`
- Test: `tests/phpunit/PluginTest.php`

**Interfaces:**
- Produces: constants `HIERARCHY_GUARD_VERSION`, `HIERARCHY_GUARD_FILE`, `HIERARCHY_GUARD_DIR`; class `HierarchyGuard\Plugin` with `public static function instance(): Plugin` and `public function register(): void`. Later tasks add lines to `register()`.
- Produces: the commands `npm run env:start`, `npm run test:php` (accepts `-- --filter Name`), `composer lint`.

- [ ] **Step 1: Confirm the docs commit exists**

The controller already committed `docs/` (spec snapshot and this plan) on branch `engine`. Run `git log --oneline -1 -- docs` and confirm a `docs: add design spec snapshot…` commit is listed. Do not re-commit it.

- [ ] **Step 2: Create composer.json**

```json
{
	"name": "hrithikdalal/hierarchy-guard",
	"description": "Shows page hierarchy in wp-admin, warns before changes break subpage URLs, and tracks the redirects needed.",
	"type": "wordpress-plugin",
	"license": "GPL-2.0-or-later",
	"require": {
		"php": ">=8.0"
	},
	"require-dev": {
		"phpunit/phpunit": "^9.6",
		"yoast/phpunit-polyfills": "^2.0",
		"wp-coding-standards/wpcs": "^3.1",
		"automattic/vipwpcs": "^3.0",
		"phpcompatibility/phpcompatibility-wp": "^2.1",
		"dealerdirect/phpcodesniffer-composer-installer": "^1.0"
	},
	"autoload": {
		"psr-4": {
			"HierarchyGuard\\": "includes/HierarchyGuard/"
		}
	},
	"config": {
		"allow-plugins": {
			"dealerdirect/phpcodesniffer-composer-installer": true
		},
		"platform": {
			"php": "8.0.30"
		},
		"sort-packages": true
	},
	"scripts": {
		"lint": "phpcs",
		"lint:fix": "phpcbf"
	}
}
```

- [ ] **Step 3: Create package.json**

```json
{
	"name": "hierarchy-guard",
	"version": "0.1.0",
	"private": true,
	"scripts": {
		"wp-env": "wp-env",
		"env:start": "wp-env start",
		"env:stop": "wp-env stop",
		"env:destroy": "wp-env destroy",
		"test:php": "wp-env run tests-cli --env-cwd=wp-content/plugins/hierarchy-guard vendor/bin/phpunit"
	},
	"devDependencies": {
		"@wordpress/env": "^10.0.0"
	}
}
```

- [ ] **Step 4: Create .wp-env.json**

```json
{
	"core": null,
	"phpVersion": "8.2",
	"plugins": [ "." ],
	"config": {
		"WP_DEBUG": true,
		"SCRIPT_DEBUG": true
	}
}
```

- [ ] **Step 5: Create phpunit.xml.dist**

```xml
<?xml version="1.0"?>
<phpunit
	bootstrap="tests/phpunit/bootstrap.php"
	backupGlobals="false"
	colors="true"
	convertErrorsToExceptions="true"
	convertNoticesToExceptions="true"
	convertWarningsToExceptions="true"
	beStrictAboutTestsThatDoNotTestAnything="true"
>
	<testsuites>
		<testsuite name="hierarchy-guard">
			<directory suffix="Test.php">./tests/phpunit</directory>
		</testsuite>
	</testsuites>
</phpunit>
```

- [ ] **Step 6: Create phpcs.xml.dist**

```xml
<?xml version="1.0"?>
<ruleset name="Hierarchy Guard">
	<description>WordPress-Extra, VIP Go and PHP 8.0+ compatibility.</description>

	<file>hierarchy-guard.php</file>
	<file>includes</file>
	<file>tests</file>

	<exclude-pattern>/vendor/*</exclude-pattern>
	<exclude-pattern>/node_modules/*</exclude-pattern>
	<exclude-pattern>/build/*</exclude-pattern>

	<arg name="extensions" value="php"/>
	<arg value="sp"/>
	<arg name="parallel" value="8"/>

	<config name="minimum_wp_version" value="6.6"/>
	<config name="testVersion" value="8.0-"/>

	<rule ref="WordPress-Extra">
		<!-- PSR-4 class files. -->
		<exclude name="WordPress.Files.FileName"/>
		<!-- Short array syntax is used throughout. -->
		<exclude name="Universal.Arrays.DisallowShortArraySyntax"/>
	</rule>
	<rule ref="WordPress-VIP-Go"/>
	<rule ref="PHPCompatibilityWP"/>

	<rule ref="WordPress.WP.I18n">
		<properties>
			<property name="text_domain" type="array">
				<element value="hierarchy-guard"/>
			</property>
		</properties>
	</rule>

	<rule ref="WordPress.NamingConventions.PrefixAllGlobals">
		<properties>
			<property name="prefixes" type="array">
				<element value="hierarchy_guard"/>
				<element value="HierarchyGuard"/>
				<element value="HIERARCHY_GUARD"/>
			</property>
		</properties>
	</rule>

	<!-- Tests may touch globals and the database directly. -->
	<rule ref="WordPress.DB.DirectDatabaseQuery">
		<exclude-pattern>/tests/*</exclude-pattern>
	</rule>
	<rule ref="WordPress.WP.GlobalVariablesOverride">
		<exclude-pattern>/tests/*</exclude-pattern>
	</rule>
	<rule ref="WordPressVIPMinimum">
		<exclude-pattern>/tests/*</exclude-pattern>
	</rule>
</ruleset>
```

- [ ] **Step 7: Create .gitignore, .distignore and README.md**

`.gitignore`:
```
/vendor/
/node_modules/
/build/
.wp-env.override.json
.phpunit.result.cache
*.log
.DS_Store
```

`.distignore`:
```
.git
.github
.gitignore
.distignore
.wp-env.json
.wp-env.override.json
.phpunit.result.cache
composer.json
composer.lock
package.json
package-lock.json
phpcs.xml.dist
phpunit.xml.dist
node_modules
tests
docs
src
```

`README.md`:
```markdown
# Hierarchy Guard

Shows the parent and child structure of pages inside wp-admin, warns before a slug change, re-parenting, or trash breaks a subtree, and makes sure every old URL has somewhere to go.

## Development

Requires Docker, Node 20+, Composer and PHP 8.0+ on the host.

```bash
composer install
npm install
npm run env:start          # WordPress at http://localhost:8888 (admin / password)
npm run test:php           # PHPUnit inside the tests container
npm run test:php -- --filter PathsTest
composer lint              # PHPCS
```

The design spec lives in `docs/superpowers/specs/` and implementation plans in `docs/superpowers/plans/`.
```

- [ ] **Step 8: Create the main plugin file**

`hierarchy-guard.php`:
```php
<?php
/**
 * Plugin Name:       Hierarchy Guard
 * Plugin URI:        https://github.com/hrithikdalal/hierarchy-guard
 * Description:       Shows page hierarchy in wp-admin, warns before slug, parent, or trash changes break subpage URLs, and tracks the redirects needed.
 * Version:           0.1.0
 * Requires at least: 6.6
 * Requires PHP:      8.0
 * Author:            Hrithik Dalal
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       hierarchy-guard
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'HIERARCHY_GUARD_VERSION', '0.1.0' );
define( 'HIERARCHY_GUARD_FILE', __FILE__ );
define( 'HIERARCHY_GUARD_DIR', __DIR__ );

/*
 * PSR-4 autoloader for the plugin's own classes. The plugin has no runtime
 * Composer dependencies, so vendor/autoload.php is only needed for tests.
 */
spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'HierarchyGuard\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$relative = substr( $class_name, strlen( $prefix ) );
		$file     = __DIR__ . '/includes/HierarchyGuard/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

Plugin::instance()->register();
```

- [ ] **Step 9: Create the Plugin class**

`includes/HierarchyGuard/Plugin.php`:
```php
<?php
/**
 * Plugin bootstrap.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard;

/**
 * Wires every hook to its handler. Each later task adds one line to register().
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Whether register() has run.
	 *
	 * @var bool
	 */
	private bool $registered = false;

	/**
	 * Returns the shared instance.
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Registers hooks. Safe to call more than once.
	 */
	public function register(): void {
		if ( $this->registered ) {
			return;
		}
		$this->registered = true;
	}
}
```

- [ ] **Step 10: Create the test bootstrap**

`tests/phpunit/bootstrap.php`:
```php
<?php
/**
 * PHPUnit bootstrap. Expects the WordPress test library at WP_TESTS_DIR (wp-env sets it).
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

$hierarchy_guard_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $hierarchy_guard_tests_dir ) {
	$hierarchy_guard_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $hierarchy_guard_tests_dir . '/includes/functions.php' ) ) {
	echo "Could not find {$hierarchy_guard_tests_dir}/includes/functions.php. Run the tests with: npm run test:php\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit( 1 );
}

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';
require_once $hierarchy_guard_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require dirname( __DIR__, 2 ) . '/hierarchy-guard.php';
	}
);

require $hierarchy_guard_tests_dir . '/includes/bootstrap.php';
```

- [ ] **Step 11: Write the smoke test**

`tests/phpunit/PluginTest.php`:
```php
<?php
/**
 * Smoke test: the plugin loads inside the test suite.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Plugin;
use WP_UnitTestCase;

/**
 * @covers \HierarchyGuard\Plugin
 */
final class PluginTest extends WP_UnitTestCase {

	public function test_plugin_is_loaded(): void {
		$this->assertTrue( defined( 'HIERARCHY_GUARD_VERSION' ) );
		$this->assertSame( '0.1.0', HIERARCHY_GUARD_VERSION );
		$this->assertInstanceOf( Plugin::class, Plugin::instance() );
		$this->assertSame( Plugin::instance(), Plugin::instance() );
	}
}
```

- [ ] **Step 12: Install dependencies and start the environment**

Run:
```bash
cd ~/Work/hierarchy-guard
composer install
npm install
npm run env:start
```
Expected: Composer installs PHPUnit, polyfills and the PHPCS standards (the installer plugin registers WordPress, WordPress-VIP-Go and PHPCompatibilityWP). wp-env ends with "WordPress development site started at http://localhost:8888" and a tests site at http://localhost:8889.

- [ ] **Step 13: Run the smoke test and PHPCS**

Run: `npm run test:php`
Expected: `OK (1 test, 4 assertions)`.

Run: `composer lint`
Expected: no errors. (If PHPCS reports `WordPress.Files.FileName` anywhere, the exclude in phpcs.xml.dist is misspelled; fix the ruleset, not the file names.)

- [ ] **Step 14: Commit**

```bash
git add -A
git commit -m "chore: scaffold plugin, wp-env test harness and coding standards"
```

---

### Task 2: Settings and supported post types

**Files:**
- Create: `includes/HierarchyGuard/Settings.php`
- Create: `includes/HierarchyGuard/Post_Types.php`
- Modify: `includes/HierarchyGuard/Plugin.php` (register reset hooks)
- Test: `tests/phpunit/SettingsTest.php`, `tests/phpunit/PostTypesTest.php`

**Interfaces:**
- Produces: `Settings::OPTION = 'hierarchy_guard_settings'`; `Settings::POLICY_REPARENT|POLICY_TRASH_CHILDREN|POLICY_KEEP`; `Settings::POLICIES` (array of the three); `Settings::defaults(): array`; `Settings::all(): array`; `Settings::get( string $key ): mixed`; `Settings::serve_redirects(): bool`; `Settings::trash_policy(): string` (applies `hierarchy_guard_default_trash_policy`); `Settings::sanitize( mixed $input ): array`.
- Produces: `Post_Types::supported(): string[]`; `Post_Types::is_supported( string $type ): bool`; `Post_Types::reset(): void`.

- [ ] **Step 1: Write the failing Settings tests**

`tests/phpunit/SettingsTest.php`:
```php
<?php
/**
 * Settings option access and sanitization.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Settings;
use WP_UnitTestCase;

/**
 * @covers \HierarchyGuard\Settings
 */
final class SettingsTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( Settings::OPTION );
	}

	public function test_defaults_when_nothing_is_stored(): void {
		$this->assertNull( Settings::get( 'post_types' ) );
		$this->assertSame( Settings::POLICY_REPARENT, Settings::trash_policy() );
		$this->assertFalse( Settings::serve_redirects() );
	}

	public function test_stored_values_override_defaults(): void {
		update_option(
			Settings::OPTION,
			[
				'serve_redirects' => true,
				'trash_policy'    => Settings::POLICY_TRASH_CHILDREN,
			]
		);
		$this->assertTrue( Settings::serve_redirects() );
		$this->assertSame( Settings::POLICY_TRASH_CHILDREN, Settings::trash_policy() );
		$this->assertNull( Settings::get( 'post_types' ), 'Keys that were never saved keep their defaults.' );
	}

	public function test_sanitize_normalizes_input(): void {
		$clean = Settings::sanitize(
			[
				'post_types'      => [ 'page', 'bogus', '<script>' ],
				'trash_policy'    => 'nonsense',
				'serve_redirects' => '1',
			]
		);
		$this->assertSame( [ 'page' ], $clean['post_types'] );
		$this->assertSame( Settings::POLICY_REPARENT, $clean['trash_policy'] );
		$this->assertTrue( $clean['serve_redirects'] );
	}

	public function test_sanitize_without_checkboxes_means_none_selected(): void {
		$clean = Settings::sanitize( [] );
		$this->assertSame( [], $clean['post_types'] );
		$this->assertFalse( $clean['serve_redirects'] );
	}

	public function test_sanitize_rejects_non_array_input(): void {
		$clean = Settings::sanitize( 'garbage' );
		$this->assertSame( Settings::POLICY_REPARENT, $clean['trash_policy'] );
	}

	public function test_default_trash_policy_filter(): void {
		$filter = static fn(): string => Settings::POLICY_KEEP;
		add_filter( 'hierarchy_guard_default_trash_policy', $filter );
		$this->assertSame( Settings::POLICY_KEEP, Settings::trash_policy() );
		remove_filter( 'hierarchy_guard_default_trash_policy', $filter );
	}

	public function test_invalid_filtered_policy_falls_back_to_default(): void {
		$filter = static fn(): string => 'explode';
		add_filter( 'hierarchy_guard_default_trash_policy', $filter );
		$this->assertSame( Settings::POLICY_REPARENT, Settings::trash_policy() );
		remove_filter( 'hierarchy_guard_default_trash_policy', $filter );
	}
}
```

- [ ] **Step 2: Write the failing Post_Types tests**

`tests/phpunit/PostTypesTest.php`:
```php
<?php
/**
 * Supported post types.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Post_Types;
use HierarchyGuard\Settings;
use WP_UnitTestCase;

/**
 * @covers \HierarchyGuard\Post_Types
 */
final class PostTypesTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( Settings::OPTION );
		Post_Types::reset();
	}

	public function tear_down(): void {
		unregister_post_type( 'hg_docs' );
		unregister_post_type( 'hg_flat' );
		Post_Types::reset();
		parent::tear_down();
	}

	public function test_pages_are_supported_by_default(): void {
		$supported = Post_Types::supported();
		$this->assertContains( 'page', $supported );
		$this->assertNotContains( 'post', $supported );
		$this->assertNotContains( 'attachment', $supported );
		$this->assertTrue( Post_Types::is_supported( 'page' ) );
		$this->assertFalse( Post_Types::is_supported( 'post' ) );
	}

	public function test_public_hierarchical_custom_types_are_included(): void {
		register_post_type(
			'hg_docs',
			[
				'public'       => true,
				'hierarchical' => true,
			]
		);
		register_post_type( 'hg_flat', [ 'public' => true ] );
		Post_Types::reset();

		$this->assertContains( 'hg_docs', Post_Types::supported() );
		$this->assertNotContains( 'hg_flat', Post_Types::supported() );
	}

	public function test_registering_a_type_resets_the_memoised_list(): void {
		$this->assertNotContains( 'hg_docs', Post_Types::supported() );
		register_post_type(
			'hg_docs',
			[
				'public'       => true,
				'hierarchical' => true,
			]
		);
		$this->assertContains( 'hg_docs', Post_Types::supported(), 'The registered_post_type hook must reset the cache.' );
	}

	public function test_settings_restrict_supported_types(): void {
		register_post_type(
			'hg_docs',
			[
				'public'       => true,
				'hierarchical' => true,
			]
		);
		update_option( Settings::OPTION, [ 'post_types' => [ 'hg_docs' ] ] );

		$this->assertSame( [ 'hg_docs' ], Post_Types::supported(), 'Saving the option must reset the cache and restrict the list.' );
	}

	public function test_filter_has_the_final_say(): void {
		$filter = static fn(): array => [ 'page', 'hg_docs' ];
		add_filter( 'hierarchy_guard_post_types', $filter );
		Post_Types::reset();
		$this->assertSame( [ 'page', 'hg_docs' ], Post_Types::supported() );
		remove_filter( 'hierarchy_guard_post_types', $filter );
	}
}
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `npm run test:php -- --filter 'SettingsTest|PostTypesTest'`
Expected: errors of the form `Error: Class "HierarchyGuard\Settings" not found`.

- [ ] **Step 4: Create Settings**

`includes/HierarchyGuard/Settings.php`:
```php
<?php
/**
 * Plugin settings.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard;

/**
 * Reads, defaults and sanitizes the single settings option.
 */
final class Settings {

	public const OPTION = 'hierarchy_guard_settings';

	public const POLICY_REPARENT       = 'reparent';
	public const POLICY_TRASH_CHILDREN = 'trash_children';
	public const POLICY_KEEP           = 'keep';

	public const POLICIES = [
		self::POLICY_REPARENT,
		self::POLICY_TRASH_CHILDREN,
		self::POLICY_KEEP,
	];

	/**
	 * Default values. post_types null means "every public hierarchical type".
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return [
			'post_types'      => null,
			'trash_policy'    => self::POLICY_REPARENT,
			'serve_redirects' => false,
		];
	}

	/**
	 * Stored values merged over the defaults.
	 *
	 * @return array<string,mixed>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, [] );
		if ( ! is_array( $stored ) ) {
			$stored = [];
		}
		return array_merge( self::defaults(), $stored );
	}

	/**
	 * One setting.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public static function get( string $key ) {
		return self::all()[ $key ] ?? null;
	}

	/**
	 * Whether the built-in redirect server is on.
	 */
	public static function serve_redirects(): bool {
		return (bool) self::get( 'serve_redirects' );
	}

	/**
	 * The policy used when no dialog could ask the editor.
	 */
	public static function trash_policy(): string {
		$policy = (string) self::get( 'trash_policy' );

		/**
		 * Filters the default trash policy.
		 *
		 * @param string $policy One of Settings::POLICIES.
		 */
		$policy = (string) apply_filters( 'hierarchy_guard_default_trash_policy', $policy );

		return in_array( $policy, self::POLICIES, true ) ? $policy : self::POLICY_REPARENT;
	}

	/**
	 * Sanitize callback for register_setting().
	 *
	 * @param mixed $input Raw option value.
	 * @return array<string,mixed>
	 */
	public static function sanitize( $input ): array {
		$input  = is_array( $input ) ? $input : [];
		$public = array_values( get_post_types( [ 'public' => true, 'hierarchical' => true ], 'names' ) );

		$types = isset( $input['post_types'] ) ? array_map( 'sanitize_key', (array) $input['post_types'] ) : [];
		$types = array_values( array_intersect( $types, $public ) );

		$policy = isset( $input['trash_policy'] ) ? sanitize_key( (string) $input['trash_policy'] ) : self::POLICY_REPARENT;
		if ( ! in_array( $policy, self::POLICIES, true ) ) {
			$policy = self::POLICY_REPARENT;
		}

		return [
			'post_types'      => $types,
			'trash_policy'    => $policy,
			'serve_redirects' => ! empty( $input['serve_redirects'] ),
		];
	}
}
```

- [ ] **Step 5: Create Post_Types**

`includes/HierarchyGuard/Post_Types.php`:
```php
<?php
/**
 * Supported post types.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard;

/**
 * The hierarchical post types the plugin acts on. Memoised per request.
 */
final class Post_Types {

	/**
	 * Memoised list.
	 *
	 * @var string[]|null
	 */
	private static ?array $cache = null;

	/**
	 * Supported post type names.
	 *
	 * @return string[]
	 */
	public static function supported(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$public     = array_values( get_post_types( [ 'public' => true, 'hierarchical' => true ], 'names' ) );
		$configured = Settings::get( 'post_types' );
		$types      = null === $configured ? $public : array_values( array_intersect( (array) $configured, $public ) );

		/**
		 * Filters the post types Hierarchy Guard acts on.
		 *
		 * @param string[] $types Post type names.
		 */
		$types = (array) apply_filters( 'hierarchy_guard_post_types', $types );

		self::$cache = array_values( array_unique( array_filter( array_map( 'strval', $types ) ) ) );

		return self::$cache;
	}

	/**
	 * Whether a post type is supported.
	 *
	 * @param string $type Post type name.
	 */
	public static function is_supported( string $type ): bool {
		return in_array( $type, self::supported(), true );
	}

	/**
	 * Clears the memoised list. Hooked to post type registration and settings saves.
	 */
	public static function reset(): void {
		self::$cache = null;
	}
}
```

- [ ] **Step 6: Wire the reset hooks in Plugin::register()**

In `includes/HierarchyGuard/Plugin.php`, replace the body of `register()` after `$this->registered = true;` with:
```php
		add_action( 'registered_post_type', [ Post_Types::class, 'reset' ] );
		add_action( 'unregistered_post_type', [ Post_Types::class, 'reset' ] );
		add_action( 'update_option_' . Settings::OPTION, [ Post_Types::class, 'reset' ] );
		add_action( 'add_option_' . Settings::OPTION, [ Post_Types::class, 'reset' ] );
		add_action( 'delete_option_' . Settings::OPTION, [ Post_Types::class, 'reset' ] );
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `npm run test:php -- --filter 'SettingsTest|PostTypesTest'`
Expected: `OK (12 tests, ...)`.

Run: `composer lint`
Expected: no errors.

- [ ] **Step 8: Commit**

```bash
git add includes tests
git commit -m "feat: settings option and supported post types"
```

---

### Task 3: Paths

**Files:**
- Create: `includes/HierarchyGuard/Paths.php`
- Test: `tests/phpunit/PathsTest.php`

**Interfaces:**
- Consumes: `Post_Types::is_supported()`.
- Produces: `Paths::normalize( string $path ): string` (same normalization as `get_page_by_path()`, segments joined by `/`, no surrounding slashes); `Paths::uri( \WP_Post $post ): string` (slug chain from the object's `post_parent` upward, so a before-object yields the old chain); `Paths::is_recordable( \WP_Post $post ): bool`.

- [ ] **Step 1: Write the failing tests**

`tests/phpunit/PathsTest.php`:
```php
<?php
/**
 * Path normalization and slug-chain URIs.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Paths;
use HierarchyGuard\Post_Types;
use HierarchyGuard\Settings;
use WP_UnitTestCase;

/**
 * @covers \HierarchyGuard\Paths
 */
final class PathsTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( Settings::OPTION );
		Post_Types::reset();
		$this->set_permalink_structure( '/%postname%/' );
	}

	public function tear_down(): void {
		$this->set_permalink_structure( '' );
		parent::tear_down();
	}

	public function test_normalize_trims_lowercases_and_drops_empty_segments(): void {
		$this->assertSame( 'about/team', Paths::normalize( '/About/Team/' ) );
		$this->assertSame( 'about/team', Paths::normalize( 'about//team' ) );
		$this->assertSame( '', Paths::normalize( '///' ) );
	}

	public function test_normalize_treats_encoded_and_decoded_slugs_alike(): void {
		$decoded = 'о-нас';
		$encoded = rawurlencode( $decoded );

		$this->assertSame( Paths::normalize( $encoded ), Paths::normalize( $decoded ) );
		$this->assertSame( sanitize_title( $decoded ), Paths::normalize( $decoded ), 'Stored post_name values and request segments must normalize to the same string.' );
	}

	public function test_uri_follows_the_parent_chain(): void {
		$company = self::factory()->post->create( [ 'post_type' => 'page', 'post_name' => 'company' ] );
		$about   = self::factory()->post->create( [ 'post_type' => 'page', 'post_name' => 'about', 'post_parent' => $company ] );
		$team    = self::factory()->post->create( [ 'post_type' => 'page', 'post_name' => 'team', 'post_parent' => $about ] );

		$this->assertSame( 'company/about/team', Paths::uri( get_post( $team ) ) );
		$this->assertSame( 'company', Paths::uri( get_post( $company ) ) );
	}

	public function test_uri_uses_the_object_parent_not_the_database(): void {
		$company = self::factory()->post->create( [ 'post_type' => 'page', 'post_name' => 'company' ] );
		$about   = self::factory()->post->create( [ 'post_type' => 'page', 'post_name' => 'about', 'post_parent' => $company ] );
		$team    = self::factory()->post->create( [ 'post_type' => 'page', 'post_name' => 'team', 'post_parent' => $about ] );

		$before = clone get_post( $team );
		wp_update_post( [ 'ID' => $team, 'post_parent' => $company ] );

		$this->assertSame( 'company/about/team', Paths::uri( $before ), 'A before-object still describes the old chain.' );
		$this->assertSame( 'company/team', Paths::uri( get_post( $team ) ) );
	}

	public function test_uri_skips_ancestors_with_empty_slugs_like_core(): void {
		$draft = self::factory()->post->create( [ 'post_type' => 'page', 'post_status' => 'draft', 'post_name' => '' ] );
		$child = self::factory()->post->create( [ 'post_type' => 'page', 'post_name' => 'child', 'post_parent' => $draft ] );

		$this->assertSame( '', get_post( $draft )->post_name, 'Precondition: drafts may have an empty slug.' );
		$this->assertSame( get_page_uri( $child ), Paths::uri( get_post( $child ) ) );
	}

	public function test_is_recordable_requires_pretty_permalinks(): void {
		$page = get_post( self::factory()->post->create( [ 'post_type' => 'page', 'post_name' => 'about' ] ) );
		$this->assertTrue( Paths::is_recordable( $page ) );

		$this->set_permalink_structure( '' );
		$this->assertFalse( Paths::is_recordable( $page ) );
	}

	public function test_is_recordable_requires_published_supported_type_with_slug(): void {
		$draft = get_post( self::factory()->post->create( [ 'post_type' => 'page', 'post_status' => 'draft', 'post_name' => 'draft' ] ) );
		$post  = get_post( self::factory()->post->create( [ 'post_type' => 'post', 'post_name' => 'hello' ] ) );
		$page  = get_post( self::factory()->post->create( [ 'post_type' => 'page', 'post_name' => 'about' ] ) );

		$this->assertFalse( Paths::is_recordable( $draft ) );
		$this->assertFalse( Paths::is_recordable( $post ) );
		$this->assertTrue( Paths::is_recordable( $page ) );

		$nameless            = clone $page;
		$nameless->post_name = '';
		$this->assertFalse( Paths::is_recordable( $nameless ) );

		$trashed            = clone $page;
		$trashed->post_name = 'about__trashed';
		$this->assertFalse( Paths::is_recordable( $trashed ) );
	}
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npm run test:php -- --filter PathsTest`
Expected: `Error: Class "HierarchyGuard\Paths" not found`.

- [ ] **Step 3: Create Paths**

`includes/HierarchyGuard/Paths.php`:
```php
<?php
/**
 * Path helpers.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard;

use WP_Post;

/**
 * Builds and normalizes page paths from slug chains.
 *
 * A "path" here is the page URI without the post type's rewrite prefix,
 * for example `about/team`, normalized the way get_page_by_path() does.
 */
final class Paths {

	/**
	 * Normalizes a path the way get_page_by_path() does before matching.
	 *
	 * @param string $path Raw or decoded path, with or without slashes.
	 */
	public static function normalize( string $path ): string {
		$path  = rawurlencode( urldecode( $path ) );
		$path  = str_replace( [ '%2F', '%20' ], [ '/', ' ' ], $path );
		$parts = explode( '/', trim( $path, '/' ) );
		$parts = array_map( 'sanitize_title_for_query', $parts );
		$parts = array_filter( $parts, static fn( string $part ): bool => '' !== $part );

		return implode( '/', $parts );
	}

	/**
	 * The path of a post built from its slug and its ancestors' slugs.
	 *
	 * Uses the object's own post_parent, so a $post_before object passed by
	 * post_updated yields the chain as it was before the save. Ancestors with
	 * an empty slug are skipped, matching get_page_uri().
	 *
	 * @param WP_Post $post Post object.
	 */
	public static function uri( WP_Post $post ): string {
		$segments  = [ (string) $post->post_name ];
		$parent_id = (int) $post->post_parent;
		$guard     = 0;

		while ( $parent_id > 0 && $guard < 50 ) {
			++$guard;
			$parent = get_post( $parent_id );
			if ( ! $parent instanceof WP_Post ) {
				break;
			}
			if ( '' !== (string) $parent->post_name ) {
				array_unshift( $segments, (string) $parent->post_name );
			}
			$parent_id = (int) $parent->post_parent;
		}

		return self::normalize( implode( '/', $segments ) );
	}

	/**
	 * Whether this post's URL is publicly reachable and worth recording.
	 *
	 * @param WP_Post $post Post object, usually the before-state.
	 */
	public static function is_recordable( WP_Post $post ): bool {
		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			return false;
		}
		if ( ! Post_Types::is_supported( (string) $post->post_type ) ) {
			return false;
		}
		if ( 'publish' !== $post->post_status ) {
			return false;
		}
		$name = (string) $post->post_name;
		if ( '' === $name || str_ends_with( $name, '__trashed' ) ) {
			return false;
		}
		return true;
	}
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `npm run test:php -- --filter PathsTest`
Expected: `OK (7 tests, ...)`.

Run: `composer lint`
Expected: no errors.

- [ ] **Step 5: Commit**

```bash
git add includes tests
git commit -m "feat: path normalization and slug-chain URIs"
```

---

### Task 4: Ledger

**Files:**
- Create: `includes/HierarchyGuard/Ledger.php`
- Test: `tests/phpunit/LedgerTest.php`

**Interfaces:**
- Consumes: `Paths::normalize()`, `Paths::uri()`.
- Produces: `Ledger::KEY_PREFIX = '_hierarchy_guard_path_'`; `Ledger::ROOT_OPTION = 'hierarchy_guard_root_paths'`; `Ledger::CACHE_GROUP = 'hierarchy_guard'`; `Ledger::key( string $post_type, string $path ): string`; `record( int $post_id, string $old_path ): bool`; `record_root( string $post_type, string $old_path ): void`; `paths_for( int $post_id ): string[]`; `prune( int $post_id ): int`; `find( string $post_type, string $path ): ?int` returning a post ID, `0` for a root-list hit, or `null` for no match.

- [ ] **Step 1: Write the failing tests**

`tests/phpunit/LedgerTest.php`:
```php
<?php
/**
 * The old-path ledger.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Ledger;
use HierarchyGuard\Post_Types;
use HierarchyGuard\Settings;
use WP_UnitTestCase;

/**
 * @covers \HierarchyGuard\Ledger
 */
final class LedgerTest extends WP_UnitTestCase {

	private Ledger $ledger;

	private int $about;

	public function set_up(): void {
		parent::set_up();
		delete_option( Settings::OPTION );
		delete_option( Ledger::ROOT_OPTION );
		Post_Types::reset();
		$this->set_permalink_structure( '/%postname%/' );
		$this->ledger = new Ledger();
		$this->about  = self::factory()->post->create( [ 'post_type' => 'page', 'post_name' => 'about' ] );
	}

	public function tear_down(): void {
		$this->set_permalink_structure( '' );
		parent::tear_down();
	}

	public function test_key_hashes_type_and_path_inside_the_index_prefix_length(): void {
		$key = Ledger::key( 'page', 'about/team' );
		$this->assertStringStartsWith( Ledger::KEY_PREFIX, $key );
		$this->assertSame( Ledger::KEY_PREFIX . md5( 'page:about/team' ), $key );
		$this->assertLessThan( 191, strlen( $key ) );
		$this->assertNotSame( $key, Ledger::key( 'hg_docs', 'about/team' ), 'Keys are scoped by post type.' );
	}

	public function test_record_stores_one_entry(): void {
		$this->assertTrue( $this->ledger->record( $this->about, '/About-Us/' ) );
		$this->assertSame( [ 'about-us' ], $this->ledger->paths_for( $this->about ) );
		$this->assertSame( 'about-us', get_post_meta( $this->about, Ledger::key( 'page', 'about-us' ), true ) );
	}

	public function test_record_is_idempotent(): void {
		$this->assertTrue( $this->ledger->record( $this->about, 'about-us' ) );
		$this->assertFalse( $this->ledger->record( $this->about, 'about-us' ) );
		$this->assertCount( 1, $this->ledger->paths_for( $this->about ) );
	}

	public function test_record_refuses_the_current_path_and_empty_paths(): void {
		$this->assertFalse( $this->ledger->record( $this->about, 'about' ) );
		$this->assertFalse( $this->ledger->record( $this->about, '/' ) );
		$this->assertSame( [], $this->ledger->paths_for( $this->about ) );
	}

	public function test_record_respects_the_should_record_filter(): void {
		add_filter( 'hierarchy_guard_should_record', '__return_false' );
		$this->assertFalse( $this->ledger->record( $this->about, 'about-us' ) );
		remove_filter( 'hierarchy_guard_should_record', '__return_false' );
	}

	public function test_record_ignores_unknown_posts(): void {
		$this->assertFalse( $this->ledger->record( 999999, 'about-us' ) );
	}

	public function test_prune_removes_the_entry_equal_to_the_current_path(): void {
		global $wpdb;

		$this->ledger->record( $this->about, 'old-about' );

		// Change the slug without firing hooks, so only prune() is under test.
		$wpdb->update( $wpdb->posts, [ 'post_name' => 'old-about' ], [ 'ID' => $this->about ] );
		clean_post_cache( $this->about );

		$this->assertSame( 1, $this->ledger->prune( $this->about ) );
		$this->assertSame( [], $this->ledger->paths_for( $this->about ) );
		$this->assertSame( 0, $this->ledger->prune( $this->about ) );
	}

	public function test_find_returns_the_post_for_a_recorded_path(): void {
		$this->ledger->record( $this->about, 'about-us' );

		$this->assertSame( $this->about, $this->ledger->find( 'page', 'about-us' ) );
		$this->assertSame( $this->about, $this->ledger->find( 'page', '/About-Us/' ), 'find() normalizes its input.' );
		$this->assertNull( $this->ledger->find( 'page', 'nope' ) );
		$this->assertNull( $this->ledger->find( 'post', 'about-us' ), 'Lookups are scoped by post type.' );
	}

	public function test_find_prefers_the_newest_entry_when_two_posts_share_an_old_path(): void {
		$other = self::factory()->post->create( [ 'post_type' => 'page', 'post_name' => 'other' ] );
		$this->ledger->record( $this->about, 'shared' );
		$this->ledger->record( $other, 'shared' );

		$this->assertSame( $other, $this->ledger->find( 'page', 'shared' ) );
	}

	public function test_find_caches_misses_until_the_ledger_changes(): void {
		$this->assertNull( $this->ledger->find( 'page', 'later' ) );

		$queries = get_num_queries();
		$this->assertNull( $this->ledger->find( 'page', 'later' ) );
		$this->assertSame( $queries, get_num_queries(), 'A repeated miss must be served from cache.' );

		$this->ledger->record( $this->about, 'later' );
		$this->assertSame( $this->about, $this->ledger->find( 'page', 'later' ), 'Recording must invalidate the cached miss.' );
	}

	public function test_root_paths_are_found_as_zero_and_capped(): void {
		$this->ledger->record_root( 'page', '/Company/' );

		$this->assertSame( 0, $this->ledger->find( 'page', 'company' ) );
		$this->assertNull( $this->ledger->find( 'hg_docs', 'company' ) );

		for ( $i = 0; $i < 205; $i++ ) {
			$this->ledger->record_root( 'page', 'old-' . $i );
		}
		$stored = get_option( Ledger::ROOT_OPTION );
		$this->assertCount( 200, $stored['page'] );
		$this->assertNull( $this->ledger->find( 'page', 'company' ), 'The oldest entries fall off the end.' );
		$this->assertSame( 0, $this->ledger->find( 'page', 'old-204' ) );
	}

	public function test_a_post_entry_wins_over_a_root_entry(): void {
		$this->ledger->record_root( 'page', 'about-us' );
		$this->ledger->record( $this->about, 'about-us' );

		$this->assertSame( $this->about, $this->ledger->find( 'page', 'about-us' ) );
	}
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npm run test:php -- --filter LedgerTest`
Expected: `Error: Class "HierarchyGuard\Ledger" not found`.

- [ ] **Step 3: Create Ledger**

`includes/HierarchyGuard/Ledger.php`:
```php
<?php
/**
 * The old-path ledger.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard;

use WP_Post;

/**
 * Stores the paths a post (or its moved children) used to live at.
 *
 * One repeatable-by-key post meta row per old path, with the post type and
 * path hashed into the key so a lookup is a single index seek. Paths whose
 * children became top level are kept in a small option instead.
 */
final class Ledger {

	public const KEY_PREFIX  = '_hierarchy_guard_path_';
	public const ROOT_OPTION = 'hierarchy_guard_root_paths';
	public const CACHE_GROUP = 'hierarchy_guard';
	public const ROOT_CAP    = 200;

	private const CACHE_MISS = -1;
	private const CACHE_TTL  = 12 * HOUR_IN_SECONDS;

	/**
	 * Meta key for a (post type, path) pair.
	 *
	 * @param string $post_type Post type name.
	 * @param string $path      Normalized path.
	 */
	public static function key( string $post_type, string $path ): string {
		return self::KEY_PREFIX . md5( $post_type . ':' . $path );
	}

	/**
	 * Records that $old_path used to lead to $post_id (or to children now under it).
	 *
	 * @param int    $post_id  Post that the path now maps to.
	 * @param string $old_path Old path, any form; normalized here.
	 * @return bool True when a new entry was written.
	 */
	public function record( int $post_id, string $old_path ): bool {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return false;
		}

		$old_path = Paths::normalize( $old_path );
		if ( '' === $old_path || Paths::uri( $post ) === $old_path ) {
			return false;
		}

		/**
		 * Filters whether an old path is recorded for a post.
		 *
		 * @param bool    $record   Whether to record.
		 * @param WP_Post $post     The post the path maps to.
		 * @param string  $old_path The normalized old path.
		 */
		if ( ! apply_filters( 'hierarchy_guard_should_record', true, $post, $old_path ) ) {
			return false;
		}

		$key = self::key( (string) $post->post_type, $old_path );
		if ( metadata_exists( 'post', $post_id, $key ) ) {
			return false;
		}

		add_post_meta( $post_id, $key, $old_path, true );
		$this->bump();

		return true;
	}

	/**
	 * Records an old parent path whose children became top level.
	 *
	 * @param string $post_type Post type name.
	 * @param string $old_path  Old path, any form; normalized here.
	 */
	public function record_root( string $post_type, string $old_path ): void {
		$old_path = Paths::normalize( $old_path );
		if ( '' === $old_path ) {
			return;
		}

		$roots = $this->roots();
		$list  = $roots[ $post_type ] ?? [];
		if ( in_array( $old_path, $list, true ) ) {
			return;
		}

		$list[] = $old_path;
		if ( count( $list ) > self::ROOT_CAP ) {
			$list = array_slice( $list, -self::ROOT_CAP );
		}
		$roots[ $post_type ] = array_values( $list );

		update_option( self::ROOT_OPTION, $roots, false );
		$this->bump();
	}

	/**
	 * Old paths recorded on a post, for display.
	 *
	 * @param int $post_id Post ID.
	 * @return string[]
	 */
	public function paths_for( int $post_id ): array {
		$paths = [];
		foreach ( (array) get_post_meta( $post_id ) as $key => $values ) {
			if ( str_starts_with( (string) $key, self::KEY_PREFIX ) && isset( $values[0] ) ) {
				$paths[] = (string) $values[0];
			}
		}
		return $paths;
	}

	/**
	 * Removes the entry on a post that equals its current path.
	 *
	 * @param int $post_id Post ID.
	 * @return int Number of entries removed (0 or 1).
	 */
	public function prune( int $post_id ): int {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return 0;
		}

		$key = self::key( (string) $post->post_type, Paths::uri( $post ) );
		if ( ! metadata_exists( 'post', $post_id, $key ) ) {
			return 0;
		}

		delete_post_meta( $post_id, $key );
		$this->bump();

		return 1;
	}

	/**
	 * Finds what a path used to lead to.
	 *
	 * @param string $post_type Post type name.
	 * @param string $path      Path, any form; normalized here.
	 * @return int|null Post ID, 0 when the path is in the root list, null when unknown.
	 */
	public function find( string $post_type, string $path ): ?int {
		$path = Paths::normalize( $path );
		if ( '' === $path ) {
			return null;
		}

		$cache_key = 'find:' . md5( $post_type . ':' . $path ) . ':' . wp_cache_get_last_changed( self::CACHE_GROUP );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return self::CACHE_MISS === (int) $cached ? null : (int) $cached;
		}

		$found = $this->seek( $post_type, $path );
		if ( null === $found ) {
			$roots = $this->roots();
			if ( in_array( $path, $roots[ $post_type ] ?? [], true ) ) {
				$found = 0;
			}
		}

		wp_cache_set( $cache_key, null === $found ? self::CACHE_MISS : $found, self::CACHE_GROUP, self::CACHE_TTL );

		return $found;
	}

	/**
	 * Single index seek on the hashed key, newest entry first.
	 *
	 * @param string $post_type Post type name.
	 * @param string $path      Normalized path.
	 */
	private function seek( string $post_type, string $path ): ?int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Point lookup on the meta_key index; cached by the caller.
		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND p.post_type = %s ORDER BY pm.meta_id DESC LIMIT 1",
				self::key( $post_type, $path ),
				$post_type
			)
		);

		return $id ? (int) $id : null;
	}

	/**
	 * The root-paths option as an array keyed by post type.
	 *
	 * @return array<string,string[]>
	 */
	private function roots(): array {
		$roots = get_option( self::ROOT_OPTION, [] );
		return is_array( $roots ) ? $roots : [];
	}

	/**
	 * Invalidates every cached lookup.
	 */
	private function bump(): void {
		wp_cache_set_last_changed( self::CACHE_GROUP );
	}
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `npm run test:php -- --filter LedgerTest`
Expected: `OK (12 tests, ...)`.

Run: `composer lint`
Expected: no errors.

- [ ] **Step 5: Commit**

```bash
git add includes tests
git commit -m "feat: old-path ledger with hashed keys, root list and cached lookups"
```

---

### Task 5: Change listener

**Files:**
- Create: `includes/HierarchyGuard/Change_Listener.php`
- Modify: `includes/HierarchyGuard/Plugin.php` (instantiate and register)
- Test: `tests/phpunit/ChangeListenerTest.php`

**Interfaces:**
- Consumes: `Ledger::record()`, `Ledger::record_root()`, `Ledger::paths_for()`, `Ledger::prune()`, `Paths::uri()`, `Paths::is_recordable()`, `Post_Types::is_supported()`.
- Produces: `Change_Listener::__construct( Ledger $ledger )`; `register(): void`; `on_post_updated( int $post_id, \WP_Post $after, \WP_Post $before ): void`; `on_before_delete( int $post_id, \WP_Post $post ): void`; `Change_Listener::suspend( bool $on ): void`; `Change_Listener::is_suspended(): bool`. The trash handler in the next plan calls `suspend()` around its own writes.

- [ ] **Step 1: Write the failing tests**

`tests/phpunit/ChangeListenerTest.php`:
```php
<?php
/**
 * Recording URL changes from core hooks.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Change_Listener;
use HierarchyGuard\Ledger;
use HierarchyGuard\Post_Types;
use HierarchyGuard\Settings;
use WP_UnitTestCase;

/**
 * @covers \HierarchyGuard\Change_Listener
 */
final class ChangeListenerTest extends WP_UnitTestCase {

	private Ledger $ledger;

	private int $company;

	private int $about;

	private int $team;

	public function set_up(): void {
		parent::set_up();
		delete_option( Settings::OPTION );
		delete_option( Ledger::ROOT_OPTION );
		Post_Types::reset();
		Change_Listener::suspend( false );
		$this->set_permalink_structure( '/%postname%/' );

		$this->ledger  = new Ledger();
		$this->company = self::factory()->post->create( [ 'post_type' => 'page', 'post_name' => 'company' ] );
		$this->about   = self::factory()->post->create( [ 'post_type' => 'page', 'post_name' => 'about', 'post_parent' => $this->company ] );
		$this->team    = self::factory()->post->create( [ 'post_type' => 'page', 'post_name' => 'team', 'post_parent' => $this->about ] );
	}

	public function tear_down(): void {
		Change_Listener::suspend( false );
		$this->set_permalink_structure( '' );
		parent::tear_down();
	}

	public function test_listener_is_registered_by_the_plugin(): void {
		$this->assertTrue( $this->plugin_listener_on( 'post_updated', 'on_post_updated' ) );
		$this->assertTrue( $this->plugin_listener_on( 'before_delete_post', 'on_before_delete' ) );
	}

	/**
	 * Whether a Change_Listener method is hooked at priority 10. has_action( $hook )
	 * alone proves nothing: core registers its own callbacks on both hooks.
	 */
	private function plugin_listener_on( string $hook, string $method ): bool {
		global $wp_filter;
		foreach ( $wp_filter[ $hook ]->callbacks[10] ?? [] as $callback ) {
			if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof Change_Listener && $method === $callback['function'][1] ) {
				return true;
			}
		}
		return false;
	}

	public function test_renaming_a_page_records_its_old_path_only_on_that_page(): void {
		wp_update_post( [ 'ID' => $this->about, 'post_name' => 'company-info' ] );

		$this->assertSame( [ 'company/about' ], $this->ledger->paths_for( $this->about ) );
		$this->assertSame( [], $this->ledger->paths_for( $this->team ), 'Subpages are resolved by prefix, never recorded.' );
		$this->assertSame( [], $this->ledger->paths_for( $this->company ) );
	}

	public function test_changing_the_parent_records_the_old_chain(): void {
		wp_update_post( [ 'ID' => $this->team, 'post_parent' => $this->company ] );

		$this->assertSame( [ 'company/about/team' ], $this->ledger->paths_for( $this->team ) );
	}

	public function test_renaming_back_prunes_the_entry_for_the_current_path(): void {
		wp_update_post( [ 'ID' => $this->about, 'post_name' => 'company-info' ] );
		wp_update_post( [ 'ID' => $this->about, 'post_name' => 'about' ] );

		$this->assertSame( [ 'company/company-info' ], $this->ledger->paths_for( $this->about ), 'Only the intermediate path remains; the current path is pruned.' );
	}

	public function test_saving_without_slug_or_parent_change_runs_no_queries(): void {
		Post_Types::supported();
		$listener = new Change_Listener( $this->ledger );
		$post     = get_post( $this->about );

		$queries = get_num_queries();
		$listener->on_post_updated( $this->about, $post, $post );

		$this->assertSame( $queries, get_num_queries() );
		$this->assertSame( [], $this->ledger->paths_for( $this->about ) );
	}

	public function test_unsupported_types_and_unpublished_pages_are_ignored(): void {
		$post  = self::factory()->post->create( [ 'post_type' => 'post', 'post_name' => 'hello' ] );
		$draft = self::factory()->post->create( [ 'post_type' => 'page', 'post_status' => 'draft', 'post_name' => 'draft' ] );

		wp_update_post( [ 'ID' => $post, 'post_name' => 'hello-world' ] );
		wp_update_post( [ 'ID' => $draft, 'post_name' => 'draft-two' ] );

		$this->assertSame( [], $this->ledger->paths_for( $post ) );
		$this->assertSame( [], $this->ledger->paths_for( $draft ) );
	}

	public function test_trash_and_untrash_transitions_record_nothing(): void {
		wp_trash_post( $this->about );
		$this->assertStringEndsWith( '__trashed', get_post( $this->about )->post_name, 'Precondition: core renames the slug on trash.' );
		$this->assertSame( [], $this->ledger->paths_for( $this->about ) );

		wp_untrash_post( $this->about );
		$this->assertSame( 'about', get_post( $this->about )->post_name, 'Precondition: core restores the slug on untrash.' );
		$this->assertSame( [], $this->ledger->paths_for( $this->about ) );
	}

	public function test_suspended_listener_records_nothing(): void {
		Change_Listener::suspend( true );
		wp_update_post( [ 'ID' => $this->about, 'post_name' => 'company-info' ] );

		$this->assertTrue( Change_Listener::is_suspended() );
		$this->assertSame( [], $this->ledger->paths_for( $this->about ) );
	}

	public function test_deleting_a_parent_records_its_path_on_the_grandparent(): void {
		wp_delete_post( $this->about, true );

		$this->assertSame( $this->company, (int) get_post( $this->team )->post_parent, 'Precondition: core re-parents to the grandparent.' );
		$this->assertSame( [ 'company/about' ], $this->ledger->paths_for( $this->company ) );
	}

	public function test_deleting_a_top_level_parent_records_a_root_path(): void {
		wp_delete_post( $this->company, true );

		$this->assertSame( 0, (int) get_post( $this->about )->post_parent );
		$this->assertSame( 0, $this->ledger->find( 'page', 'company' ) );
	}

	public function test_deleting_a_childless_page_records_nothing(): void {
		wp_delete_post( $this->team, true );

		$this->assertSame( [], $this->ledger->paths_for( $this->about ) );
	}

	public function test_deleting_a_trashed_parent_carries_its_old_paths_up_but_not_trashed_ones(): void {
		$this->ledger->record( $this->about, 'company/about-old' );
		wp_trash_post( $this->about );
		wp_delete_post( $this->about, true );

		$paths = $this->ledger->paths_for( $this->company );
		$this->assertContains( 'company/about-old', $paths );
		foreach ( $paths as $path ) {
			$this->assertStringNotContainsString( '__trashed', $path );
		}
	}
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npm run test:php -- --filter ChangeListenerTest`
Expected: `Error: Class "HierarchyGuard\Change_Listener" not found`.

- [ ] **Step 3: Create Change_Listener**

`includes/HierarchyGuard/Change_Listener.php`:
```php
<?php
/**
 * Turns core post hooks into ledger writes.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard;

use WP_Post;

/**
 * Records URL changes. Every handler returns as early and as cheaply as it can.
 */
final class Change_Listener {

	/**
	 * When true, nothing is recorded. The trash handler sets this around its own writes.
	 *
	 * @var bool
	 */
	private static bool $suspended = false;

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
	 * Hooks into core.
	 */
	public function register(): void {
		add_action( 'post_updated', [ $this, 'on_post_updated' ], 10, 3 );
		add_action( 'before_delete_post', [ $this, 'on_before_delete' ], 10, 2 );
	}

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
	 */
	public static function is_suspended(): bool {
		return self::$suspended;
	}

	/**
	 * Records the old path of a post whose slug or parent changed.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $after   Post after the update.
	 * @param WP_Post $before  Post before the update.
	 */
	public function on_post_updated( int $post_id, WP_Post $after, WP_Post $before ): void {
		if ( self::$suspended ) {
			return;
		}
		if ( ! Post_Types::is_supported( (string) $after->post_type ) ) {
			return;
		}
		if ( $after->post_name === $before->post_name && (int) $after->post_parent === (int) $before->post_parent ) {
			return;
		}
		if ( 'trash' === $before->post_status || 'trash' === $after->post_status ) {
			return;
		}
		if ( ! Paths::is_recordable( $before ) ) {
			return;
		}

		$this->ledger->record( $post_id, Paths::uri( $before ) );
		$this->ledger->prune( $post_id );
	}

	/**
	 * Before core re-parents a deleted post's children, records the paths they
	 * used to live under on their new parent, or in the root list.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post being deleted.
	 */
	public function on_before_delete( int $post_id, WP_Post $post ): void {
		if ( self::$suspended ) {
			return;
		}
		if ( ! Post_Types::is_supported( (string) $post->post_type ) ) {
			return;
		}
		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			return;
		}
		if ( ! $this->has_children( $post_id, (string) $post->post_type ) ) {
			return;
		}

		$paths = array_merge( [ Paths::uri( $post ) ], $this->ledger->paths_for( $post_id ) );
		$paths = array_unique(
			array_filter(
				$paths,
				static fn( string $path ): bool => '' !== $path && ! str_contains( $path, '__trashed' )
			)
		);

		$parent_id = (int) $post->post_parent;
		foreach ( $paths as $path ) {
			if ( $parent_id > 0 ) {
				$this->ledger->record( $parent_id, $path );
			} else {
				$this->ledger->record_root( (string) $post->post_type, $path );
			}
		}
	}

	/**
	 * Whether a post has at least one child of the same type. One indexed query.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $post_type Post type name.
	 */
	private function has_children( int $post_id, string $post_type ): bool {
		$children = get_posts(
			[
				'post_parent'            => $post_id,
				'post_type'              => $post_type,
				'post_status'            => 'any',
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			]
		);

		return ! empty( $children );
	}
}
```

- [ ] **Step 4: Register the listener in Plugin::register()**

Add after the reset hooks in `includes/HierarchyGuard/Plugin.php`:
```php
		$ledger = new Ledger();
		( new Change_Listener( $ledger ) )->register();
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `npm run test:php -- --filter ChangeListenerTest`
Expected: `OK (12 tests, ...)`.

Run the whole suite to make sure earlier tests still pass now that the listener fires on every save:
Run: `npm run test:php`
Expected: all green. (The Ledger prune test changes the slug with a raw update precisely so the listener does not interfere.)

Run: `composer lint`
Expected: no errors.

- [ ] **Step 6: Commit**

```bash
git add includes tests
git commit -m "feat: record URL changes from post_updated and before_delete_post"
```

---

### Task 6: Resolver

**Files:**
- Create: `includes/HierarchyGuard/Redirects/Resolver.php`
- Test: `tests/phpunit/ResolverTest.php`

**Interfaces:**
- Consumes: `Ledger::find()`, `Paths::normalize()`, `Paths::uri()`.
- Produces: `Resolver::MAX_ROUNDS = 5`; `Resolver::__construct( Ledger $ledger )`; `resolve( string $post_type, string $path ): ?\WP_Post` returning a publicly viewable post of that type, or null.

- [ ] **Step 1: Write the failing tests**

`tests/phpunit/ResolverTest.php`:
```php
<?php
/**
 * Longest-prefix resolution of old paths.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Ledger;
use HierarchyGuard\Post_Types;
use HierarchyGuard\Redirects\Resolver;
use HierarchyGuard\Settings;
use WP_UnitTestCase;

/**
 * @covers \HierarchyGuard\Redirects\Resolver
 */
final class ResolverTest extends WP_UnitTestCase {

	private Ledger $ledger;

	private Resolver $resolver;

	private int $company;

	private int $about;

	private int $team;

	public function set_up(): void {
		parent::set_up();
		delete_option( Settings::OPTION );
		delete_option( Ledger::ROOT_OPTION );
		Post_Types::reset();
		$this->set_permalink_structure( '/%postname%/' );

		$this->ledger   = new Ledger();
		$this->resolver = new Resolver( $this->ledger );
		$this->company  = self::factory()->post->create( [ 'post_type' => 'page', 'post_name' => 'company' ] );
		$this->about    = self::factory()->post->create( [ 'post_type' => 'page', 'post_name' => 'about', 'post_parent' => $this->company ] );
		$this->team     = self::factory()->post->create( [ 'post_type' => 'page', 'post_name' => 'team', 'post_parent' => $this->about ] );
	}

	public function tear_down(): void {
		$this->set_permalink_structure( '' );
		parent::tear_down();
	}

	public function test_resolves_a_subpage_after_its_parent_was_renamed(): void {
		wp_update_post( [ 'ID' => $this->about, 'post_name' => 'company-info' ] );

		$target = $this->resolver->resolve( 'page', 'company/about/team' );
		$this->assertNotNull( $target );
		$this->assertSame( $this->team, $target->ID );
	}

	public function test_resolves_the_renamed_page_itself(): void {
		wp_update_post( [ 'ID' => $this->about, 'post_name' => 'company-info' ] );

		$this->assertSame( $this->about, $this->resolver->resolve( 'page', '/Company/About/' )->ID );
	}

	public function test_resolves_chained_changes_across_rounds(): void {
		wp_update_post( [ 'ID' => $this->about, 'post_name' => 'company-info' ] );
		wp_update_post( [ 'ID' => $this->team, 'post_parent' => $this->company ] );

		// Round 1 rewrites company/about/team to company/company-info/team, which no longer exists.
		// Round 2 finds the entry team recorded when it moved and lands on company/team.
		$this->assertSame( $this->team, $this->resolver->resolve( 'page', 'company/about/team' )->ID );
	}

	public function test_unknown_paths_return_null(): void {
		$this->assertNull( $this->resolver->resolve( 'page', 'nothing/here' ) );
		$this->assertNull( $this->resolver->resolve( 'page', '' ) );
	}

	public function test_root_paths_resolve_to_top_level_pages(): void {
		wp_delete_post( $this->company, true );

		$this->assertSame( $this->team, $this->resolver->resolve( 'page', 'company/about/team' )->ID );
		$this->assertSame( $this->about, $this->resolver->resolve( 'page', 'company/about' )->ID );
	}

	public function test_target_must_be_publicly_viewable(): void {
		wp_update_post( [ 'ID' => $this->about, 'post_name' => 'company-info' ] );
		wp_update_post( [ 'ID' => $this->team, 'post_status' => 'draft' ] );

		$this->assertNull( $this->resolver->resolve( 'page', 'company/about/team' ) );
	}

	public function test_lookups_are_scoped_to_the_post_type(): void {
		wp_update_post( [ 'ID' => $this->about, 'post_name' => 'company-info' ] );

		$this->assertNull( $this->resolver->resolve( 'post', 'company/about/team' ) );
	}

	public function test_cycles_terminate(): void {
		$a = self::factory()->post->create( [ 'post_type' => 'page', 'post_name' => 'a' ] );
		$b = self::factory()->post->create( [ 'post_type' => 'page', 'post_name' => 'b' ] );
		$this->ledger->record( $a, 'b' );
		$this->ledger->record( $b, 'a' );

		$this->assertNull( $this->resolver->resolve( 'page', 'a/missing' ) );
	}

	public function test_attachment_with_same_slug_does_not_hijack(): void {
		global $wpdb;

		$attachment = self::factory()->attachment->create_object(
			[
				'file'           => 'team.jpg',
				'post_parent'    => $this->about,
				'post_mime_type' => 'image/jpeg',
			]
		);
		// Core's wp_unique_post_slug() would suffix a same-slug sibling, so force the
		// collision the way an import or a direct database edit can produce it.
		$wpdb->update( $wpdb->posts, [ 'post_name' => 'team' ], [ 'ID' => $attachment ] );
		clean_post_cache( $attachment );
		$this->assertSame( 'team', get_post( $attachment )->post_name, 'Precondition: attachment and page share the slug.' );
		$this->assertSame( $this->about, (int) get_post( $attachment )->post_parent, 'Precondition: attachment and page share the parent.' );

		wp_update_post( [ 'ID' => $this->about, 'post_name' => 'company-info' ] );

		$target = $this->resolver->resolve( 'page', 'company/about/team' );
		$this->assertNotNull( $target );
		$this->assertSame( 'page', $target->post_type );
		$this->assertSame( $this->team, $target->ID );
	}
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npm run test:php -- --filter ResolverTest`
Expected: `Error: Class "HierarchyGuard\Redirects\Resolver" not found`.

- [ ] **Step 3: Create Resolver**

`includes/HierarchyGuard/Redirects/Resolver.php`:
```php
<?php
/**
 * Resolves an old path to the post it now leads to.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Redirects;

use HierarchyGuard\Ledger;
use HierarchyGuard\Paths;
use WP_Post;

/**
 * Longest-prefix resolution with a bounded number of rewrite rounds.
 */
final class Resolver {

	public const MAX_ROUNDS = 5;

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
	 * The publicly viewable post an old path now leads to, or null.
	 *
	 * @param string $post_type Post type the request was for.
	 * @param string $path      Requested path, any form.
	 */
	public function resolve( string $post_type, string $path ): ?WP_Post {
		$path = Paths::normalize( $path );
		$seen = [];

		for ( $round = 0; $round < self::MAX_ROUNDS; $round++ ) {
			if ( '' === $path || isset( $seen[ $path ] ) ) {
				return null;
			}
			$seen[ $path ] = true;

			$candidate = $this->rewrite_once( $post_type, $path );
			if ( null === $candidate ) {
				return null;
			}

			$target = get_page_by_path( $candidate, OBJECT, [ $post_type ] );
			if ( $target instanceof WP_Post && $target->post_type === $post_type && is_post_publicly_viewable( $target ) ) {
				return $target;
			}

			$path = $candidate;
		}

		return null;
	}

	/**
	 * Replaces the longest prefix the ledger knows with the location it now maps to.
	 *
	 * @param string $post_type Post type name.
	 * @param string $path      Normalized path.
	 * @return string|null The rewritten path, or null when no prefix matched.
	 */
	private function rewrite_once( string $post_type, string $path ): ?string {
		$segments = explode( '/', $path );

		for ( $length = count( $segments ); $length >= 1; $length-- ) {
			$prefix = implode( '/', array_slice( $segments, 0, $length ) );
			$hit    = $this->ledger->find( $post_type, $prefix );
			if ( null === $hit ) {
				continue;
			}

			$base = '';
			if ( $hit > 0 ) {
				$post = get_post( $hit );
				if ( ! $post instanceof WP_Post ) {
					continue;
				}
				$base = Paths::uri( $post );
			}

			$remainder = implode( '/', array_slice( $segments, $length ) );

			return trim( $base . '/' . $remainder, '/' );
		}

		return null;
	}
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `npm run test:php -- --filter ResolverTest`
Expected: `OK (9 tests, ...)`.

Run: `composer lint`
Expected: no errors.

- [ ] **Step 5: Commit**

```bash
git add includes tests
git commit -m "feat: longest-prefix resolver for old hierarchical paths"
```

---

### Task 7: Redirect server

**Files:**
- Create: `includes/HierarchyGuard/Redirects/Server.php`
- Modify: `includes/HierarchyGuard/Plugin.php` (instantiate and register)
- Test: `tests/phpunit/ServerTest.php`

**Interfaces:**
- Consumes: `Resolver::resolve()`, `Settings::serve_redirects()`, `Post_Types::supported()`, `Post_Types::is_supported()`, `Paths::normalize()`.
- Produces: `Server::__construct( Resolver $resolver )`; `register(): void` (hooks `template_redirect` at priority 9); `target_for_current_request(): ?array{url: string, status: int}`; `maybe_redirect(): void`.

**Why the path comes from `$wp->request`, not query vars.** With the default `/%postname%/` structure WordPress uses verbose page rules: `WP::parse_request()` skips every page rule whose path `get_page_by_path()` cannot find. An old page path therefore matches no page rule, and the 404 either carries no `pagename` at all (three or more segments) or matches a post-attachment rule and carries `attachment=<last segment>` (two segments). `$wp->request` always holds the requested path with the home path removed. The server strips the `index.php` root and the pagination, feed and embed suffixes from it, tries hierarchical custom types whose rewrite prefix the path starts with, then pages, and re-applies the suffixes to the target.

- [ ] **Step 1: Write the failing tests**

`tests/phpunit/ServerTest.php`:
```php
<?php
/**
 * Serving redirects on 404s.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Ledger;
use HierarchyGuard\Post_Types;
use HierarchyGuard\Redirects\Resolver;
use HierarchyGuard\Redirects\Server;
use HierarchyGuard\Settings;
use WP_UnitTestCase;

/**
 * @covers \HierarchyGuard\Redirects\Server
 */
final class ServerTest extends WP_UnitTestCase {

	private Server $server;

	private int $company;

	private int $about;

	private int $team;

	public function set_up(): void {
		parent::set_up();
		delete_option( Ledger::ROOT_OPTION );
		update_option( Settings::OPTION, [ 'serve_redirects' => true ] );
		Post_Types::reset();
		$this->set_permalink_structure( '/%postname%/' );

		$this->server  = new Server( new Resolver( new Ledger() ) );
		$this->company = self::factory()->post->create( [ 'post_type' => 'page', 'post_name' => 'company' ] );
		$this->about   = self::factory()->post->create( [ 'post_type' => 'page', 'post_name' => 'about', 'post_parent' => $this->company ] );
		$this->team    = self::factory()->post->create( [ 'post_type' => 'page', 'post_name' => 'team', 'post_parent' => $this->about ] );
	}

	public function tear_down(): void {
		unset( $_SERVER['REQUEST_METHOD'] );
		unregister_post_type( 'hg_docs' );
		$this->set_permalink_structure( '' );
		parent::tear_down();
	}

	public function test_server_is_registered_before_canonical_redirects(): void {
		$this->assertSame( 9, has_action( 'template_redirect', [ $this->server_from_plugin(), 'maybe_redirect' ] ) );
	}

	public function test_redirects_an_old_subpage_url_to_the_new_permalink(): void {
		wp_update_post( [ 'ID' => $this->about, 'post_name' => 'company-info' ] );
		$this->go_to( '/company/about/team/' );

		$this->assertTrue( is_404(), 'Precondition: the old URL no longer resolves.' );
		$target = $this->server->target_for_current_request();
		$this->assertNotNull( $target );
		$this->assertSame( get_permalink( $this->team ), $target['url'] );
		$this->assertSame( 301, $target['status'] );
	}

	public function test_does_nothing_when_serving_is_off(): void {
		update_option( Settings::OPTION, [ 'serve_redirects' => false ] );
		wp_update_post( [ 'ID' => $this->about, 'post_name' => 'company-info' ] );
		$this->go_to( '/company/about/team/' );

		$this->assertNull( $this->server->target_for_current_request() );
	}

	public function test_ignores_requests_that_are_not_get_or_head(): void {
		wp_update_post( [ 'ID' => $this->about, 'post_name' => 'company-info' ] );
		$this->go_to( '/company/about/team/' );

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$this->assertNull( $this->server->target_for_current_request() );

		$_SERVER['REQUEST_METHOD'] = 'HEAD';
		$this->assertNotNull( $this->server->target_for_current_request() );
	}

	public function test_does_nothing_for_pages_that_resolve(): void {
		$this->go_to( '/company/about/team/' );

		$this->assertFalse( is_404() );
		$this->assertNull( $this->server->target_for_current_request() );
	}

	public function test_a_new_page_at_the_old_path_is_served_not_redirected(): void {
		wp_update_post( [ 'ID' => $this->about, 'post_name' => 'company-info' ] );
		self::factory()->post->create( [ 'post_type' => 'page', 'post_name' => 'about', 'post_parent' => $this->company ] );
		$this->go_to( '/company/about/' );

		$this->assertFalse( is_404() );
		$this->assertNull( $this->server->target_for_current_request() );
	}

	public function test_preserves_pagination(): void {
		wp_update_post( [ 'ID' => $this->about, 'post_name' => 'company-info' ] );
		$this->go_to( '/company/about/team/2/' );

		$this->assertTrue( is_404() );
		$target = $this->server->target_for_current_request();
		$this->assertSame( user_trailingslashit( trailingslashit( get_permalink( $this->team ) ) . '2', 'single_paged' ), $target['url'] );
	}

	public function test_preserves_feed_and_embed_suffixes(): void {
		wp_update_post( [ 'ID' => $this->about, 'post_name' => 'company-info' ] );

		$this->go_to( '/company/about/team/feed/' );
		$this->assertTrue( is_404() );
		$this->assertSame( get_post_comments_feed_link( $this->team ), $this->server->target_for_current_request()['url'] );

		$this->go_to( '/company/about/team/embed/' );
		$this->assertTrue( is_404() );
		$this->assertSame( user_trailingslashit( trailingslashit( get_permalink( $this->team ) ) . 'embed', 'embed' ), $this->server->target_for_current_request()['url'] );
	}

	public function test_two_segment_old_url_redirects(): void {
		// Two segments match core's post-attachment rule, so the 404 carries
		// attachment=design rather than a pagename. The path must still resolve.
		$services = self::factory()->post->create( [ 'post_type' => 'page', 'post_name' => 'services' ] );
		$design   = self::factory()->post->create( [ 'post_type' => 'page', 'post_name' => 'design', 'post_parent' => $services ] );
		wp_update_post( [ 'ID' => $services, 'post_name' => 'solutions' ] );

		$this->go_to( '/services/design/' );
		$this->assertTrue( is_404() );
		$this->assertSame( get_permalink( $design ), $this->server->target_for_current_request()['url'] );
	}

	public function test_status_and_url_filters_apply_and_offsite_urls_are_refused(): void {
		wp_update_post( [ 'ID' => $this->about, 'post_name' => 'company-info' ] );
		$this->go_to( '/company/about/team/' );

		$status = static fn(): int => 302;
		add_filter( 'hierarchy_guard_redirect_status', $status );
		$this->assertSame( 302, $this->server->target_for_current_request()['status'] );
		remove_filter( 'hierarchy_guard_redirect_status', $status );

		$offsite = static fn(): string => 'https://evil.example/elsewhere';
		add_filter( 'hierarchy_guard_redirect_url', $offsite );
		$this->assertNull( $this->server->target_for_current_request() );
		remove_filter( 'hierarchy_guard_redirect_url', $offsite );
	}

	public function test_unicode_slugs_resolve(): void {
		$cyrillic = self::factory()->post->create( [ 'post_type' => 'page', 'post_title' => 'О нас', 'post_parent' => $this->company ] );
		$child    = self::factory()->post->create( [ 'post_type' => 'page', 'post_name' => 'team', 'post_parent' => $cyrillic ] );
		$old_slug = get_post( $cyrillic )->post_name;
		$this->assertStringContainsString( '%', $old_slug, 'Precondition: core stores non-ASCII slugs percent-encoded.' );

		wp_update_post( [ 'ID' => $cyrillic, 'post_name' => 'about-us' ] );

		$this->go_to( '/company/' . $old_slug . '/team/' );
		$this->assertTrue( is_404() );
		$this->assertSame( get_permalink( $child ), $this->server->target_for_current_request()['url'] );

		$this->go_to( '/company/' . urldecode( $old_slug ) . '/team/' );
		$this->assertTrue( is_404() );
		$this->assertSame( get_permalink( $child ), $this->server->target_for_current_request()['url'] );
	}

	public function test_index_php_permalink_structure(): void {
		$this->set_permalink_structure( '/index.php/%postname%/' );
		wp_update_post( [ 'ID' => $this->about, 'post_name' => 'company-info' ] );
		$this->go_to( '/index.php/company/about/team/' );

		$this->assertTrue( is_404() );
		$target = $this->server->target_for_current_request();
		$this->assertStringContainsString( '/index.php/', $target['url'] );
		$this->assertSame( get_permalink( $this->team ), $target['url'] );
	}

	public function test_hierarchical_custom_post_type_with_rewrite_prefix(): void {
		register_post_type(
			'hg_docs',
			[
				'public'       => true,
				'hierarchical' => true,
				'rewrite'      => [ 'slug' => 'docs', 'hierarchical' => true ],
			]
		);
		$this->set_permalink_structure( '/%postname%/' );

		$guide   = self::factory()->post->create( [ 'post_type' => 'hg_docs', 'post_name' => 'guide' ] );
		$install = self::factory()->post->create( [ 'post_type' => 'hg_docs', 'post_name' => 'install', 'post_parent' => $guide ] );
		$this->assertSame( home_url( '/docs/guide/install/' ), get_permalink( $install ), 'Precondition: the rewrite prefix is in the permalink.' );

		wp_update_post( [ 'ID' => $guide, 'post_name' => 'handbook' ] );
		$this->go_to( '/docs/guide/install/' );

		$this->assertTrue( is_404() );
		$this->assertSame( get_permalink( $install ), $this->server->target_for_current_request()['url'] );
	}

	/**
	 * The Server instance the plugin registered, found through the hook table.
	 */
	private function server_from_plugin(): Server {
		global $wp_filter;
		foreach ( $wp_filter['template_redirect']->callbacks[9] ?? [] as $callback ) {
			if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof Server ) {
				return $callback['function'][0];
			}
		}
		$this->fail( 'No Server registered at template_redirect priority 9.' );
	}
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npm run test:php -- --filter ServerTest`
Expected: `Error: Class "HierarchyGuard\Redirects\Server" not found`.

- [ ] **Step 3: Create Server**

`includes/HierarchyGuard/Redirects/Server.php`:
```php
<?php
/**
 * Serves redirects for old hierarchical URLs.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Redirects;

use HierarchyGuard\Paths;
use HierarchyGuard\Post_Types;
use HierarchyGuard\Settings;
use WP;
use WP_Post;
use WP_Rewrite;

/**
 * Runs on 404s at template_redirect priority 9, before redirect_canonical()
 * and its fuzzy guess, and before wp_old_slug_redirect().
 */
final class Server {

	public const PRIORITY = 9;

	/**
	 * Resolver.
	 *
	 * @var Resolver
	 */
	private Resolver $resolver;

	/**
	 * Constructor.
	 *
	 * @param Resolver $resolver Resolver.
	 */
	public function __construct( Resolver $resolver ) {
		$this->resolver = $resolver;
	}

	/**
	 * Hooks into core.
	 */
	public function register(): void {
		add_action( 'template_redirect', [ $this, 'maybe_redirect' ], self::PRIORITY );
	}

	/**
	 * Redirects and exits when the current 404 maps to a page.
	 */
	public function maybe_redirect(): void {
		$target = $this->target_for_current_request();
		if ( null === $target ) {
			return;
		}

		// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- URL passed through wp_validate_redirect() in target_for_current_request().
		wp_redirect( $target['url'], $target['status'], 'Hierarchy Guard' );
		exit;
	}

	/**
	 * The redirect for the current request, or null when there is none.
	 *
	 * @return array{url:string,status:int}|null
	 */
	public function target_for_current_request(): ?array {
		global $wp;

		if ( ! is_404() || ! Settings::serve_redirects() ) {
			return null;
		}
		if ( ! in_array( $this->request_method(), [ 'GET', 'HEAD' ], true ) ) {
			return null;
		}
		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			return null;
		}
		if ( ! $wp instanceof WP ) {
			return null;
		}

		$request = $this->parse_request( (string) $wp->request );
		if ( '' === $request['path'] ) {
			return null;
		}

		$target = null;
		foreach ( $this->candidates( $request['path'] ) as [ $post_type, $path ] ) {
			$target = $this->resolver->resolve( $post_type, $path );
			if ( null !== $target ) {
				break;
			}
		}
		if ( null === $target ) {
			return null;
		}

		$url = get_permalink( $target );
		if ( ! is_string( $url ) || '' === $url ) {
			return null;
		}
		$url = $this->reapply_suffixes( $url, $target, $request );

		/**
		 * Filters the HTTP status used for a hierarchy redirect.
		 *
		 * @param int     $status Status code, 301 by default.
		 * @param WP_Post $target The post being redirected to.
		 * @param string  $path   The requested path, normalized, suffixes removed.
		 */
		$status = (int) apply_filters( 'hierarchy_guard_redirect_status', 301, $target, $request['path'] );
		if ( ! in_array( $status, [ 301, 302, 307, 308 ], true ) ) {
			$status = 301;
		}

		/**
		 * Filters the URL a hierarchy redirect sends visitors to.
		 *
		 * @param string  $url    Target URL.
		 * @param WP_Post $target The post being redirected to.
		 * @param string  $path   The requested path, normalized, suffixes removed.
		 */
		$url = (string) apply_filters( 'hierarchy_guard_redirect_url', $url, $target, $request['path'] );
		$url = wp_validate_redirect( $url, '' );
		if ( '' === $url ) {
			return null;
		}

		return [
			'url'    => $url,
			'status' => $status,
		];
	}

	/**
	 * Splits $wp->request into the page path and the suffixes core appends to it.
	 *
	 * @param string $request Home path removed, slashes trimmed, still percent-encoded.
	 * @return array{path:string,page:int,paged:int,feed:string,embed:bool}
	 */
	private function parse_request( string $request ): array {
		global $wp_rewrite;

		$index = $wp_rewrite instanceof WP_Rewrite ? $wp_rewrite->index : 'index.php';
		$feeds = $wp_rewrite instanceof WP_Rewrite ? (array) $wp_rewrite->feeds : [ 'feed', 'rdf', 'rss', 'rss2', 'atom' ];
		$base  = $wp_rewrite instanceof WP_Rewrite ? $wp_rewrite->pagination_base : 'page';
		$parts = [
			'path'  => '',
			'page'  => 0,
			'paged' => 0,
			'feed'  => '',
			'embed' => false,
		];

		$request = trim( $request, '/' );
		if ( str_starts_with( $request, $index . '/' ) ) {
			$request = substr( $request, strlen( $index ) + 1 );
		}

		$segments = array_values( array_filter( explode( '/', $request ), static fn( string $segment ): bool => '' !== $segment ) );
		$last     = (string) end( $segments );

		if ( 'embed' === $last ) {
			array_pop( $segments );
			$parts['embed'] = true;
		} elseif ( in_array( $last, $feeds, true ) ) {
			array_pop( $segments );
			$parts['feed'] = 'feed' === $last ? get_default_feed() : $last;
			if ( 'feed' === end( $segments ) ) {
				array_pop( $segments );
			}
		} elseif ( '' !== $last && ctype_digit( $last ) ) {
			array_pop( $segments );
			if ( end( $segments ) === $base ) {
				array_pop( $segments );
				$parts['paged'] = (int) $last;
			} else {
				$parts['page'] = (int) $last;
			}
		}

		$parts['path'] = Paths::normalize( implode( '/', $segments ) );

		return $parts;
	}

	/**
	 * The (post type, path) pairs a request path could stand for, most specific first:
	 * hierarchical custom types whose rewrite prefix the path starts with, then pages.
	 *
	 * @param string $path Normalized request path, suffixes removed.
	 * @return array<int,array{0:string,1:string}>
	 */
	private function candidates( string $path ): array {
		global $wp_rewrite;

		$typed = [];
		if ( $wp_rewrite instanceof WP_Rewrite ) {
			foreach ( Post_Types::supported() as $type ) {
				if ( 'page' === $type ) {
					continue;
				}
				$struct = $wp_rewrite->get_extra_permastruct( $type );
				if ( ! is_string( $struct ) || '' === $struct ) {
					continue;
				}
				$prefix = trim( str_replace( '%' . $type . '%', '', $struct ), '/' );
				if ( str_starts_with( $prefix, $wp_rewrite->index . '/' ) ) {
					$prefix = substr( $prefix, strlen( $wp_rewrite->index ) + 1 );
				}
				$prefix = Paths::normalize( $prefix );
				if ( '' !== $prefix && str_starts_with( $path, $prefix . '/' ) ) {
					$typed[] = [ strlen( $prefix ), $type, substr( $path, strlen( $prefix ) + 1 ) ];
				}
			}
		}

		usort( $typed, static fn( array $a, array $b ): int => $b[0] <=> $a[0] );

		$candidates = array_map( static fn( array $entry ): array => [ $entry[1], $entry[2] ], $typed );
		if ( Post_Types::is_supported( 'page' ) ) {
			$candidates[] = [ 'page', $path ];
		}

		return $candidates;
	}

	/**
	 * Re-applies the suffixes parse_request() set aside, in the shapes core builds them.
	 *
	 * @param string                                                       $url     Target permalink.
	 * @param WP_Post                                                      $target  Target post.
	 * @param array{path:string,page:int,paged:int,feed:string,embed:bool} $request Parsed request.
	 */
	private function reapply_suffixes( string $url, WP_Post $target, array $request ): string {
		if ( $request['embed'] ) {
			return user_trailingslashit( trailingslashit( $url ) . 'embed', 'embed' );
		}
		if ( '' !== $request['feed'] ) {
			$feed = get_post_comments_feed_link( $target->ID, $request['feed'] );
			return '' !== $feed ? $feed : $url;
		}
		if ( $request['paged'] > 1 ) {
			return user_trailingslashit( trailingslashit( $url ) . 'page/' . $request['paged'], 'paged' );
		}
		if ( $request['page'] > 1 ) {
			return user_trailingslashit( trailingslashit( $url ) . $request['page'], 'single_paged' );
		}
		return $url;
	}

	/**
	 * The request method, GET when unknown (WP-CLI, tests).
	 */
	private function request_method(): string {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( (string) wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'get';
		return strtoupper( $method );
	}
}
```

- [ ] **Step 4: Register the server in Plugin::register()**

Add after the Change_Listener line in `includes/HierarchyGuard/Plugin.php`:
```php
		( new Redirects\Server( new Redirects\Resolver( $ledger ) ) )->register();
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `npm run test:php -- --filter ServerTest`
Expected: `OK (13 tests, ...)`.

If `test_preserves_pagination` fails on the exact trailing slash, compare against what `wp_old_slug_redirect()` produces for a paged post on this WordPress version and adjust the `user_trailingslashit()` type argument in both the test and `reapply_suffixes()` so they agree; the contract is "same shape as core". If any test's `is_404()` precondition fails, stop and report it with the request path and `$GLOBALS['wp']->matched_rule`; do not change the precondition.

Run: `npm run test:php`
Expected: all green.

Run: `composer lint`
Expected: no errors.

- [ ] **Step 6: Commit**

```bash
git add includes tests
git commit -m "feat: serve 301s for old hierarchical URLs at template_redirect priority 9"
```

---

### Task 8: Redirect providers and the Settings › Writing section

**Files:**
- Create: `includes/HierarchyGuard/Redirects/Providers.php`
- Create: `includes/HierarchyGuard/Admin/Settings_Section.php`
- Modify: `includes/HierarchyGuard/Plugin.php` (instantiate and register)
- Test: `tests/phpunit/ProvidersTest.php`, `tests/phpunit/SettingsSectionTest.php`

**Interfaces:**
- Produces: `Providers::all(): array<string, array{name:string, active:bool, url:string, csv:string}>` (filterable via `hierarchy_guard_redirect_providers`); `Providers::detect(): ?array` (the first active provider plus its `slug`, or null).
- Produces: `Settings_Section::__construct( Providers $providers )`; `register(): void` (hooks `admin_init`); `add(): void`; `render_post_types(): void`; `render_trash_policy(): void`; `render_serve_redirects(): void`.

- [ ] **Step 1: Write the failing Providers tests**

`tests/phpunit/ProvidersTest.php`:
```php
<?php
/**
 * Redirect plugin detection.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Redirects\Providers;
use WP_UnitTestCase;

/**
 * @covers \HierarchyGuard\Redirects\Providers
 */
final class ProvidersTest extends WP_UnitTestCase {

	public function test_known_providers_are_listed_with_the_required_shape(): void {
		$all = ( new Providers() )->all();

		foreach ( [ 'redirection', 'safe-redirect-manager', 'yoast-premium', 'rank-math', 'aioseo', 'seopress-pro', 'simple-301' ] as $slug ) {
			$this->assertArrayHasKey( $slug, $all );
			$this->assertIsString( $all[ $slug ]['name'] );
			$this->assertIsBool( $all[ $slug ]['active'] );
			$this->assertStringStartsWith( admin_url(), $all[ $slug ]['url'] );
			$this->assertContains( $all[ $slug ]['csv'], [ 'redirection', 'srm', 'generic' ] );
		}
	}

	public function test_detect_returns_null_when_no_provider_is_active(): void {
		$this->assertNull( ( new Providers() )->detect() );
	}

	public function test_filter_can_add_an_active_provider(): void {
		$filter = static function ( array $providers ): array {
			$providers['custom'] = [
				'name'   => 'Custom Redirects',
				'active' => true,
				'url'    => admin_url( 'admin.php?page=custom-redirects' ),
				'csv'    => 'generic',
			];
			return $providers;
		};
		add_filter( 'hierarchy_guard_redirect_providers', $filter );

		$detected = ( new Providers() )->detect();
		$this->assertSame( 'custom', $detected['slug'] );
		$this->assertSame( 'Custom Redirects', $detected['name'] );

		remove_filter( 'hierarchy_guard_redirect_providers', $filter );
	}

	public function test_detect_ignores_entries_without_an_active_flag(): void {
		$filter = static function ( array $providers ): array {
			$providers['broken'] = [ 'name' => 'Broken' ];
			return $providers;
		};
		add_filter( 'hierarchy_guard_redirect_providers', $filter );
		$this->assertNull( ( new Providers() )->detect() );
		remove_filter( 'hierarchy_guard_redirect_providers', $filter );
	}
}
```

- [ ] **Step 2: Write the failing Settings_Section tests**

`tests/phpunit/SettingsSectionTest.php`:
```php
<?php
/**
 * The Page hierarchy section on Settings › Writing.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Admin\Settings_Section;
use HierarchyGuard\Post_Types;
use HierarchyGuard\Redirects\Providers;
use HierarchyGuard\Settings;
use WP_UnitTestCase;

/**
 * @covers \HierarchyGuard\Admin\Settings_Section
 */
final class SettingsSectionTest extends WP_UnitTestCase {

	private Settings_Section $section;

	public function set_up(): void {
		parent::set_up();
		require_once ABSPATH . 'wp-admin/includes/template.php';
		delete_option( Settings::OPTION );
		Post_Types::reset();
		$this->section = new Settings_Section( new Providers() );
	}

	public function test_section_and_fields_register_on_the_writing_page(): void {
		global $wp_settings_sections, $wp_settings_fields, $wp_registered_settings;

		$this->section->add();

		$this->assertArrayHasKey( 'hierarchy_guard', $wp_settings_sections['writing'] );
		$this->assertCount( 3, $wp_settings_fields['writing']['hierarchy_guard'] );
		$this->assertArrayHasKey( Settings::OPTION, $wp_registered_settings );
		$this->assertSame( [ Settings::class, 'sanitize' ], $wp_registered_settings[ Settings::OPTION ]['sanitize_callback'] );
	}

	public function test_registration_is_hooked_to_admin_init_by_the_plugin(): void {
		global $wp_filter;

		$found = false;
		foreach ( $wp_filter['admin_init']->callbacks[10] ?? [] as $callback ) {
			if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof Settings_Section && 'add' === $callback['function'][1] ) {
				$found = true;
			}
		}
		$this->assertTrue( $found, 'has_action( "admin_init" ) alone proves nothing: core hooks admin_init too.' );
	}

	public function test_post_types_field_lists_hierarchical_types_with_current_state(): void {
		ob_start();
		$this->section->render_post_types();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="hierarchy_guard_settings[post_types][]"', $html );
		$this->assertStringContainsString( 'value="page"', $html );
		$this->assertMatchesRegularExpression( '/value="page"[^>]*checked/', $html );
		$this->assertStringNotContainsString( 'value="post"', $html );
		$this->assertStringContainsString( 'Public hierarchical post types appear here automatically.', $html );
	}

	public function test_trash_policy_field_renders_three_radios_with_the_default_selected(): void {
		ob_start();
		$this->section->render_trash_policy();
		$html = (string) ob_get_clean();

		$this->assertSame( 3, substr_count( $html, 'type="radio"' ) );
		$this->assertMatchesRegularExpression( '/value="reparent"[^>]*checked/', $html );
		$this->assertStringContainsString( 'Move subpages up one level', $html );
		$this->assertStringContainsString( 'Not applied when the trash is disabled.', $html );
	}

	public function test_serve_redirects_field_names_the_detected_provider_or_says_none_found(): void {
		ob_start();
		$this->section->render_serve_redirects();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'name="hierarchy_guard_settings[serve_redirects]"', $html );
		$this->assertStringNotContainsString( 'checked', $html );
		$this->assertStringContainsString( 'No redirect plugin was found.', $html );

		$filter = static function ( array $providers ): array {
			$providers['custom'] = [ 'name' => 'Custom Redirects', 'active' => true, 'url' => admin_url( 'admin.php?page=x' ), 'csv' => 'generic' ];
			return $providers;
		};
		add_filter( 'hierarchy_guard_redirect_providers', $filter );
		ob_start();
		$this->section->render_serve_redirects();
		$html = (string) ob_get_clean();
		remove_filter( 'hierarchy_guard_redirect_providers', $filter );

		$this->assertStringContainsString( 'Custom Redirects is active and can handle these.', $html );
	}
}
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `npm run test:php -- --filter 'ProvidersTest|SettingsSectionTest'`
Expected: `Error: Class "HierarchyGuard\Redirects\Providers" not found`.

- [ ] **Step 4: Create Providers**

`includes/HierarchyGuard/Redirects/Providers.php`:
```php
<?php
/**
 * Detects installed redirect plugins.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Redirects;

/**
 * Known redirect plugins: a presence check, the URL of their redirects screen,
 * and which CSV layout they import.
 */
final class Providers {

	/**
	 * Every known provider, active or not.
	 *
	 * @return array<string,array{name:string,active:bool,url:string,csv:string}>
	 */
	public function all(): array {
		$providers = [
			'redirection'           => [
				'name'   => 'Redirection',
				'active' => defined( 'REDIRECTION_VERSION' ),
				'url'    => admin_url( 'tools.php?page=redirection.php' ),
				'csv'    => 'redirection',
			],
			'safe-redirect-manager' => [
				'name'   => 'Safe Redirect Manager',
				'active' => function_exists( 'srm_create_redirect' ),
				'url'    => admin_url( 'edit.php?post_type=redirect_rule' ),
				'csv'    => 'srm',
			],
			'yoast-premium'         => [
				'name'   => 'Yoast SEO Premium',
				'active' => defined( 'WPSEO_PREMIUM_VERSION' ),
				'url'    => admin_url( 'admin.php?page=wpseo_redirects' ),
				'csv'    => 'generic',
			],
			'rank-math'             => [
				'name'   => 'Rank Math',
				'active' => class_exists( 'RankMath\\Helper' ) && is_callable( [ 'RankMath\\Helper', 'is_module_active' ] ) && (bool) call_user_func( [ 'RankMath\\Helper', 'is_module_active' ], 'redirections' ),
				'url'    => admin_url( 'admin.php?page=rank-math-redirections' ),
				'csv'    => 'generic',
			],
			'aioseo'                => [
				'name'   => 'All in One SEO',
				'active' => class_exists( 'AIOSEO\\Plugin\\Addon\\Redirects\\Redirects' ),
				'url'    => admin_url( 'admin.php?page=aioseo-redirects' ),
				'csv'    => 'generic',
			],
			'seopress-pro'          => [
				'name'   => 'SEOPress PRO',
				'active' => defined( 'SEOPRESS_PRO_VERSION' ),
				'url'    => admin_url( 'edit.php?post_type=seopress_404' ),
				'csv'    => 'generic',
			],
			'simple-301'            => [
				'name'   => 'Simple 301 Redirects',
				'active' => class_exists( 'Simple301Redirects' ),
				'url'    => admin_url( 'options-general.php?page=301options' ),
				'csv'    => 'generic',
			],
		];

		/**
		 * Filters the redirect plugins Hierarchy Guard can detect.
		 *
		 * @param array<string,array{name:string,active:bool,url:string,csv:string}> $providers Keyed by slug.
		 */
		return (array) apply_filters( 'hierarchy_guard_redirect_providers', $providers );
	}

	/**
	 * The first active provider, with its slug, or null.
	 *
	 * @return array{slug:string,name:string,active:bool,url:string,csv:string}|null
	 */
	public function detect(): ?array {
		foreach ( $this->all() as $slug => $provider ) {
			if ( is_array( $provider ) && ! empty( $provider['active'] ) ) {
				return [ 'slug' => (string) $slug ] + $provider;
			}
		}
		return null;
	}
}
```

- [ ] **Step 5: Create Settings_Section**

`includes/HierarchyGuard/Admin/Settings_Section.php`:
```php
<?php
/**
 * Settings › Writing › Page hierarchy.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Admin;

use HierarchyGuard\Post_Types;
use HierarchyGuard\Redirects\Providers;
use HierarchyGuard\Settings;

/**
 * Registers the three settings as a section on the Writing settings page.
 */
final class Settings_Section {

	public const SECTION = 'hierarchy_guard';
	public const PAGE    = 'writing';

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
		add_action( 'admin_init', [ $this, 'add' ] );
	}

	/**
	 * Registers the setting, section and fields.
	 */
	public function add(): void {
		register_setting(
			self::PAGE,
			Settings::OPTION,
			[
				'type'              => 'array',
				'sanitize_callback' => [ Settings::class, 'sanitize' ],
				'default'           => Settings::defaults(),
			]
		);

		add_settings_section( self::SECTION, __( 'Page hierarchy', 'hierarchy-guard' ), '__return_null', self::PAGE );

		add_settings_field( 'hierarchy_guard_post_types', __( 'Post types', 'hierarchy-guard' ), [ $this, 'render_post_types' ], self::PAGE, self::SECTION );
		add_settings_field( 'hierarchy_guard_trash_policy', __( 'Trashing a page with subpages', 'hierarchy-guard' ), [ $this, 'render_trash_policy' ], self::PAGE, self::SECTION );
		add_settings_field( 'hierarchy_guard_serve_redirects', __( 'Redirects', 'hierarchy-guard' ), [ $this, 'render_serve_redirects' ], self::PAGE, self::SECTION );
	}

	/**
	 * Checkbox per public hierarchical post type.
	 */
	public function render_post_types(): void {
		$types    = get_post_types( [ 'public' => true, 'hierarchical' => true ], 'objects' );
		$selected = Post_Types::supported();

		echo '<fieldset><legend class="screen-reader-text"><span>' . esc_html__( 'Post types', 'hierarchy-guard' ) . '</span></legend>';
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
	 * Radio group for the default trash policy.
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
		echo '<p class="description">' . esc_html__( 'Used when there is no chance to ask, for example in the editor, the REST API, or WP-CLI. The Pages list asks each time. Not applied when the trash is disabled.', 'hierarchy-guard' ) . '</p>';
		echo '</fieldset>';
	}

	/**
	 * Checkbox for the built-in redirect server, with provider-aware help text.
	 */
	public function render_serve_redirects(): void {
		$provider = $this->providers->detect();

		printf(
			'<label><input type="checkbox" name="%1$s[serve_redirects]" value="1" %2$s> %3$s</label>',
			esc_attr( Settings::OPTION ),
			checked( Settings::serve_redirects(), true, false ),
			esc_html__( 'Redirect old URLs to their new pages', 'hierarchy-guard' )
		);

		if ( null !== $provider ) {
			/* translators: %s: name of the detected redirect plugin. */
			$help = sprintf( __( '%s is active and can handle these. URL changes are recorded either way.', 'hierarchy-guard' ), $provider['name'] );
		} else {
			$help = __( 'No redirect plugin was found. Turn this on so old URLs reach their new pages. URL changes are recorded either way.', 'hierarchy-guard' );
		}
		echo '<p class="description">' . esc_html( $help ) . '</p>';
	}
}
```

- [ ] **Step 6: Register the section in Plugin::register()**

Add after the Server line in `includes/HierarchyGuard/Plugin.php`:
```php
		( new Admin\Settings_Section( new Redirects\Providers() ) )->register();
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

		$ledger = new Ledger();
		( new Change_Listener( $ledger ) )->register();
		( new Redirects\Server( new Redirects\Resolver( $ledger ) ) )->register();
		( new Admin\Settings_Section( new Redirects\Providers() ) )->register();
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `npm run test:php -- --filter 'ProvidersTest|SettingsSectionTest'`
Expected: `OK (9 tests, ...)`.

Run: `npm run test:php`
Expected: all green.

Run: `composer lint`
Expected: no errors.

- [ ] **Step 8: Check the section by eye**

Open http://localhost:8888/wp-admin/options-writing.php (admin / password). Expected: a "Page hierarchy" heading with the three fields in core's form-table styling, Pages checked, "Move subpages up one level" selected, the redirect checkbox unchecked with "No redirect plugin was found." beneath it. Save with the checkbox ticked and confirm it stays ticked after reload.

- [ ] **Step 9: Commit**

```bash
git add includes tests
git commit -m "feat: redirect plugin detection and Page hierarchy section on Settings > Writing"
```

---

### Task 9: Continuous integration

**Files:**
- Create: `.github/workflows/ci.yml`

**Interfaces:**
- Consumes: `composer lint`, `npm run env:start`, `npm run test:php`.

- [ ] **Step 1: Create the workflow**

`.github/workflows/ci.yml`:
```yaml
name: CI

on:
  push:
    branches: [ main ]
  pull_request:

jobs:
  lint:
    name: PHPCS
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
          coverage: none
          tools: composer:v2
      - run: composer install --no-interaction --prefer-dist
      - run: composer lint

  test:
    name: PHPUnit (PHP ${{ matrix.php }}, WP ${{ matrix.wp }})
    runs-on: ubuntu-latest
    strategy:
      fail-fast: false
      matrix:
        php: [ '8.0', '8.3' ]
        wp: [ 'latest' ]
        include:
          - php: '8.2'
            wp: 'WordPress/WordPress#6.6-branch'
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-node@v4
        with:
          node-version: '20'
          cache: npm
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
          coverage: none
          tools: composer:v2
      - run: composer install --no-interaction --prefer-dist
      - run: npm ci
      - name: Configure wp-env for this matrix cell
        run: |
          if [ "${{ matrix.wp }}" != "latest" ]; then
            echo '{ "core": "${{ matrix.wp }}" }' > .wp-env.override.json
          fi
      - run: npm run env:start
        env:
          WP_ENV_PHP_VERSION: ${{ matrix.php }}
      - run: npm run test:php
```

- [ ] **Step 2: Validate the YAML locally**

Run: `node -e "const y=require('fs').readFileSync('.github/workflows/ci.yml','utf8'); console.log(y.split('\n').length, 'lines; jobs:', (y.match(/^\s{2}\w+:$/gm)||[]).join(' '))"`
Expected: prints the line count and `jobs:  lint:  test:` style output with no error. (A YAML syntax error surfaces on the first push; GitHub shows it on the Actions tab.)

- [ ] **Step 3: Commit and push**

```bash
git add .github
git commit -m "ci: run PHPCS and PHPUnit across PHP and WordPress versions"
```

If a GitHub remote exists, push and confirm both jobs are green on the Actions tab. If not, creating the remote is the user's call; leave the branch local.

---

## Self-Review

**Spec coverage for this plan's scope.** Recording URL changes (Paths, Ledger, Change_Listener): Tasks 3 to 5. Root list for children that become top level: Task 4 and Task 5. Serving a redirect at priority 9, GET/HEAD only, path from `$wp->request` with suffixes set aside and re-applied, viewable target, validated URL, filters: Tasks 6 and 7. Provider detection with CSV layout slugs: Task 8. Settings option with three keys, sanitization, defaults, Settings › Writing section with the spec's copy: Tasks 2 and 8. Supported post types with filter and settings override: Task 2. Cache group with its own last-changed key and 12-hour misses: Task 4. PHPCS with WordPress, VIP and PHPCompatibility, PHPUnit in wp-env, CI matrix including WP 6.6: Tasks 1 and 9. Out of this plan, by design: trash flow and markers, batch jobs, REST routes, editor panel, list table, tree view, DataViews, notices, CSV export, uninstall, readme.

**Placeholder scan.** No TBD/TODO. Every code step has full code. The only conditional instruction is the trailing-slash reconciliation note in Task 7 Step 5, which names the exact function and argument to adjust.

**Type consistency.** `Ledger::find()` returns `?int` with `0` for root and `null` for miss; `Resolver::rewrite_once()` treats `null` as skip and `0` as empty base; tests assert `0` and `null` accordingly. `Settings::sanitize( $input ): array` matches the `register_setting` callback in Task 8. `Change_Listener::__construct( Ledger )`, `Resolver::__construct( Ledger )`, `Server::__construct( Resolver )`, `Settings_Section::__construct( Providers )` match their uses in `Plugin::register()`. `Paths::normalize()` is the single normalization used by `Ledger::record()`, `Ledger::find()`, `Resolver::resolve()` and `Server::parse_request()`.

**Review Focus.** Each of the five lines names its test and task; all five tests are present in Tasks 3, 5, 6 and 7.
