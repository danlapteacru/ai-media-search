# AI Media Search Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A wordpress.org plugin that describes every image and PDF preview with a vision model and makes the Media Library search box match that text, so "woman on a beach" finds the photo.

**Architecture:** On upload, a WP-Cron event runs an indexer that picks a reasonably sized copy of the file, sends it to the configured provider (Claude, OpenAI, or Gemini) with a shared JSON-schema prompt, and stores description, tags, and alt in post meta plus one combined `_aims_search_text` row. `posts_join` and `posts_search` filters extend every attachment search to that meta row. A top-level admin page holds a Dashboard tab (stat cards, paginated asset table, batch indexing with progress) and a Settings tab.

**Tech Stack:** PHP 7.4+, WordPress 6.0+, WordPress HTTP API, Settings API, REST API, WP-Cron. Dev only: Composer, PHPUnit 9, Brain Monkey, PHPCS with WordPress Coding Standards. Vanilla JS, no build step.

**Spec:** `docs/superpowers/specs/2026-09-10-ai-media-search-design.md`

## Global Constraints

- PHP 7.4+, WordPress 6.0+. No PHP 8-only syntax (no enums, no `match`, no readonly, no named args to WP functions).
- Slug and text domain `ai-media-search`, function/hook/option prefix `aims_`, namespace `AIMS`, meta key prefix `_aims_`, REST namespace `aims/v1`.
- Every string translatable with the `ai-media-search` text domain. Every output escaped. Every input sanitized. Capability checks and nonces on every form handler and REST route.
- HTTP calls only through `wp_remote_post` / `wp_remote_get`. No vendored SDKs. No Composer autoloader shipped; `composer.json` is dev-only.
- Eligible mime types: `image/jpeg`, `image/png`, `image/gif`, `image/webp`, `application/pdf`.
- Provider request shapes and model IDs come from the spec section "Claude_Provider, OpenAI_Provider, Gemini_Provider". Do not change them from memory.
- Git commits in this repo must use `git -c commit.gpgsign=false commit ...` because the machine's signing helper cannot run non-interactively. Commit messages carry no AI attribution lines.
- Run tests with `vendor/bin/phpunit` from the repo root. Run style checks with `vendor/bin/phpcs`.

---

## File Structure

The repo root is the plugin root, so a zip of the repo (minus dev files) is the plugin.

```
ai-media-search.php                    plugin header, constants, boot
uninstall.php                          delete option and _aims_* meta
readme.txt                             wordpress.org readme
composer.json                          dev dependencies only
phpunit.xml.dist                       PHPUnit config
phpcs.xml.dist                         WPCS ruleset
.gitignore / .distignore
includes/autoload.php                  tiny PSR-4-ish autoloader for AIMS\
includes/class-plugin.php              wires components (no logic)
includes/class-description-result.php  value object
includes/class-prompt.php              instruction text, JSON schema, parse()
includes/class-settings.php            option registration, defaults, sanitize, getters
includes/class-image-preparer.php      choose/resize the file to send
includes/class-indexer.php             prepare -> provider -> meta
includes/class-queue.php               cron scheduling and handler
includes/class-search.php              posts_join / posts_search filters
includes/class-stats.php               dashboard counts
includes/class-rest.php                REST routes
includes/class-attachment-fields.php   media modal fields, list column, bulk action
includes/class-admin-page.php          menu page, tabs, settings form, dashboard table
includes/providers/interface-provider.php
includes/providers/class-registry.php  provider ids, labels, factory
includes/providers/class-abstract-provider.php  shared HTTP and error mapping
includes/providers/class-claude-provider.php
includes/providers/class-openai-provider.php
includes/providers/class-gemini-provider.php
assets/admin.js                        tabs, regenerate button, batch loop
assets/admin.css                       dashboard styling
tests/bootstrap.php                    Brain Monkey + WP_Error stub + autoloader
tests/stubs/class-wp-error.php         minimal WP_Error for unit tests
tests/unit/*Test.php                   one test class per component
```

Autoloader mapping: `AIMS\Foo_Bar` -> `includes/class-foo-bar.php`; `AIMS\Providers\Foo_Provider` -> `includes/providers/class-foo-provider.php`; `AIMS\Providers\Provider_Interface` -> `includes/providers/interface-provider.php`.

---

### Task 1: Scaffold, autoloader, value object, test harness

**Files:**
- Create: `ai-media-search.php`, `uninstall.php`, `includes/autoload.php`, `includes/class-plugin.php`, `includes/class-description-result.php`, `composer.json`, `phpunit.xml.dist`, `.gitignore`, `.distignore`, `tests/bootstrap.php`, `tests/stubs/class-wp-error.php`
- Test: `tests/unit/DescriptionResultTest.php`

**Interfaces:**
- Produces: constants `AIMS_VERSION`, `AIMS_FILE`, `AIMS_PATH`, `AIMS_URL`; class `AIMS\Description_Result` with public typed properties `description` (string), `tags` (string[]), `alt` (string), constructor `(string $description, array $tags, string $alt)`, and `to_array(): array`; class `AIMS\Plugin` with `instance()` and `init()`; test bootstrap that loads Brain Monkey and a `WP_Error` stub.

- [ ] **Step 1: Create composer.json, phpunit config, ignores**

`composer.json`:

```json
{
  "name": "danlapteacru/ai-media-search",
  "description": "Search the WordPress Media Library by what is in the image.",
  "type": "wordpress-plugin",
  "license": "GPL-2.0-or-later",
  "require": {
    "php": ">=7.4"
  },
  "require-dev": {
    "phpunit/phpunit": "^9.6",
    "brain/monkey": "^2.6",
    "wp-coding-standards/wpcs": "^3.1",
    "dealerdirect/phpcodesniffer-composer-installer": "^1.0",
    "phpcompatibility/phpcompatibility-wp": "^2.1"
  },
  "autoload-dev": {
    "psr-4": { "AIMS\\Tests\\": "tests/unit/" }
  },
  "config": {
    "allow-plugins": { "dealerdirect/phpcodesniffer-composer-installer": true }
  },
  "scripts": {
    "test": "phpunit",
    "lint": "phpcs",
    "fix": "phpcbf"
  }
}
```

`phpunit.xml.dist`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit bootstrap="tests/bootstrap.php" colors="true" beStrictAboutTestsThatDoNotTestAnything="true">
  <testsuites>
    <testsuite name="unit">
      <directory suffix="Test.php">tests/unit</directory>
    </testsuite>
  </testsuites>
</phpunit>
```

`.gitignore`:

```
/vendor/
/node_modules/
.phpunit.result.cache
.phpcs.cache
*.zip
```

`.distignore`:

```
/.git
/.github
/vendor
/tests
/docs
/node_modules
.gitignore
.distignore
composer.json
composer.lock
phpunit.xml.dist
phpcs.xml.dist
.phpunit.result.cache
```

- [ ] **Step 2: Install dev dependencies**

Run: `composer install`
Expected: `vendor/bin/phpunit` and `vendor/bin/phpcs` exist. If Composer complains about the PHP version for PHPUnit 9 on PHP 8.1, it is fine; PHPUnit 9.6 supports 7.3 to 8.3.

- [ ] **Step 3: Write the test bootstrap and WP_Error stub**

`tests/stubs/class-wp-error.php`:

```php
<?php
/**
 * Minimal WP_Error stand-in for unit tests that run without WordPress.
 */
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $errors     = array();
		public $error_data = array();

		public function __construct( $code = '', $message = '', $data = '' ) {
			if ( '' !== $code ) {
				$this->errors[ $code ][] = $message;
				if ( '' !== $data ) {
					$this->error_data[ $code ] = $data;
				}
			}
		}

		public function get_error_code() {
			$codes = array_keys( $this->errors );
			return $codes ? $codes[0] : '';
		}

		public function get_error_message( $code = '' ) {
			if ( '' === $code ) {
				$code = $this->get_error_code();
			}
			return isset( $this->errors[ $code ][0] ) ? $this->errors[ $code ][0] : '';
		}

		public function get_error_data( $code = '' ) {
			if ( '' === $code ) {
				$code = $this->get_error_code();
			}
			return isset( $this->error_data[ $code ] ) ? $this->error_data[ $code ] : null;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}
```

`tests/bootstrap.php`:

```php
<?php
require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/stubs/class-wp-error.php';

if ( ! defined( 'AIMS_PATH' ) ) {
	define( 'AIMS_PATH', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'AIMS_VERSION' ) ) {
	define( 'AIMS_VERSION', 'test' );
}
if ( ! defined( 'AIMS_URL' ) ) {
	define( 'AIMS_URL', 'http://example.test/wp-content/plugins/ai-media-search/' );
}
if ( ! defined( 'AIMS_FILE' ) ) {
	define( 'AIMS_FILE', AIMS_PATH . 'ai-media-search.php' );
}
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', AIMS_PATH );
}

require_once AIMS_PATH . 'includes/autoload.php';
```

- [ ] **Step 4: Write the failing test for Description_Result**

`tests/unit/DescriptionResultTest.php`:

```php
<?php
namespace AIMS\Tests;

use AIMS\Description_Result;
use PHPUnit\Framework\TestCase;

class DescriptionResultTest extends TestCase {
	public function test_holds_values_and_exports_array() {
		$result = new Description_Result( 'A woman on a beach.', array( 'woman', 'beach' ), 'Woman standing on a beach.' );

		$this->assertSame( 'A woman on a beach.', $result->description );
		$this->assertSame( array( 'woman', 'beach' ), $result->tags );
		$this->assertSame( 'Woman standing on a beach.', $result->alt );
		$this->assertSame(
			array(
				'description' => 'A woman on a beach.',
				'tags'        => array( 'woman', 'beach' ),
				'alt'         => 'Woman standing on a beach.',
			),
			$result->to_array()
		);
	}
}
```

- [ ] **Step 5: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter DescriptionResultTest`
Expected: Error, class `AIMS\Description_Result` not found.

- [ ] **Step 6: Write the autoloader and value object**

`includes/autoload.php`:

```php
<?php
/**
 * Autoloader for the AIMS namespace.
 *
 * AIMS\Foo_Bar                     -> includes/class-foo-bar.php
 * AIMS\Providers\Foo_Provider      -> includes/providers/class-foo-provider.php
 * AIMS\Providers\Provider_Interface-> includes/providers/interface-provider.php
 *
 * @package AIMS
 */

defined( 'ABSPATH' ) || exit;

spl_autoload_register(
	function ( $class ) {
		if ( 0 !== strpos( $class, 'AIMS\\' ) ) {
			return;
		}

		$relative = substr( $class, 5 );
		$parts    = explode( '\\', $relative );
		$name     = array_pop( $parts );
		$slug     = strtolower( str_replace( '_', '-', $name ) );

		if ( '-interface' === substr( $slug, -10 ) ) {
			$file = 'interface-' . substr( $slug, 0, -10 ) . '.php';
		} else {
			$file = 'class-' . $slug . '.php';
		}

		$dir = AIMS_PATH . 'includes/';
		if ( $parts ) {
			$dir .= strtolower( implode( '/', $parts ) ) . '/';
		}

		$path = $dir . $file;
		if ( file_exists( $path ) ) {
			require_once $path;
		}
	}
);
```

`includes/class-description-result.php`:

```php
<?php
/**
 * Value object returned by a provider.
 *
 * @package AIMS
 */

namespace AIMS;

defined( 'ABSPATH' ) || exit;

final class Description_Result {
	/** @var string */
	public $description;

	/** @var string[] */
	public $tags;

	/** @var string */
	public $alt;

	public function __construct( string $description, array $tags, string $alt ) {
		$this->description = $description;
		$this->tags        = array_values( $tags );
		$this->alt         = $alt;
	}

	public function to_array(): array {
		return array(
			'description' => $this->description,
			'tags'        => $this->tags,
			'alt'         => $this->alt,
		);
	}
}
```

- [ ] **Step 7: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter DescriptionResultTest`
Expected: OK (1 test, 4 assertions).

- [ ] **Step 8: Write the main plugin file, Plugin class, uninstall**

`ai-media-search.php`:

```php
<?php
/**
 * Plugin Name:       AI Media Search
 * Plugin URI:        https://wordpress.org/plugins/ai-media-search/
 * Description:       Search your Media Library by what is in the image. A vision model describes each image and PDF so the media search box finds them by content.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Dan Lapteacru
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ai-media-search
 *
 * @package AIMS
 */

defined( 'ABSPATH' ) || exit;

define( 'AIMS_VERSION', '1.0.0' );
define( 'AIMS_FILE', __FILE__ );
define( 'AIMS_PATH', plugin_dir_path( __FILE__ ) );
define( 'AIMS_URL', plugin_dir_url( __FILE__ ) );

require_once AIMS_PATH . 'includes/autoload.php';

add_action(
	'plugins_loaded',
	function () {
		AIMS\Plugin::instance()->init();
	}
);

register_deactivation_hook( __FILE__, array( 'AIMS\Queue', 'clear_all' ) );
```

`includes/class-plugin.php` (components are added to `init()` in later tasks; keep the list in this order):

```php
<?php
/**
 * Wires every component. Holds no logic.
 *
 * @package AIMS
 */

namespace AIMS;

defined( 'ABSPATH' ) || exit;

final class Plugin {
	/** @var Plugin|null */
	private static $instance = null;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function init(): void {
		load_plugin_textdomain( 'ai-media-search', false, dirname( plugin_basename( AIMS_FILE ) ) . '/languages' );
		// Components register here in later tasks.
	}
}
```

`uninstall.php`:

```php
<?php
/**
 * Removes every trace of the plugin.
 *
 * @package AIMS
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'aims_settings' );
delete_transient( 'aims_stats' );

global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\\_aims\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
```

- [ ] **Step 9: Syntax-check every PHP file**

Run: `for f in ai-media-search.php uninstall.php includes/*.php; do php -l "$f"; done`
Expected: "No syntax errors detected" for each.

- [ ] **Step 10: Commit**

```bash
git add -A
git -c commit.gpgsign=false commit -m "Scaffold plugin, autoloader, value object, test harness"
```

---

### Task 2: Prompt builder and parser

**Files:**
- Create: `includes/class-prompt.php`
- Test: `tests/unit/PromptTest.php`

**Interfaces:**
- Consumes: `AIMS\Description_Result`.
- Produces: `AIMS\Prompt::schema(): array` (JSON schema with `additionalProperties: false` and full `required`), `Prompt::instructions( string $language, string $custom_prompt = '' ): string`, `Prompt::user_text(): string` (returns `Describe this image.`), `Prompt::parse( string $json )` returning `Description_Result` or `WP_Error( 'bad_response' )`.

- [ ] **Step 1: Write the failing tests**

`tests/unit/PromptTest.php`:

```php
<?php
namespace AIMS\Tests;

use AIMS\Description_Result;
use AIMS\Prompt;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class PromptTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg( 1 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_schema_requires_all_fields_and_forbids_extras() {
		$schema = Prompt::schema();
		$this->assertSame( 'object', $schema['type'] );
		$this->assertSame( array( 'description', 'tags', 'alt' ), $schema['required'] );
		$this->assertFalse( $schema['additionalProperties'] );
		$this->assertSame( 'array', $schema['properties']['tags']['type'] );
		$this->assertSame( 'string', $schema['properties']['tags']['items']['type'] );
	}

	public function test_instructions_include_language_and_custom_prompt() {
		$text = Prompt::instructions( 'German', 'Prefer product names from our catalogue.' );
		$this->assertStringContainsString( 'German', $text );
		$this->assertStringContainsString( 'Additional guidance from the site owner:', $text );
		$this->assertStringContainsString( 'Prefer product names from our catalogue.', $text );
	}

	public function test_instructions_omit_custom_heading_when_empty() {
		$text = Prompt::instructions( 'English', '   ' );
		$this->assertStringNotContainsString( 'Additional guidance', $text );
	}

	public function test_parse_good_json() {
		$json   = '{"description":"A woman walks on a beach at sunset.","tags":["Woman","beach"," Sunset ","woman"],"alt":"Woman walking on a beach at sunset."}';
		$result = Prompt::parse( $json );
		$this->assertInstanceOf( Description_Result::class, $result );
		$this->assertSame( 'A woman walks on a beach at sunset.', $result->description );
		$this->assertSame( array( 'woman', 'beach', 'sunset' ), $result->tags );
		$this->assertSame( 'Woman walking on a beach at sunset.', $result->alt );
	}

	public function test_parse_strips_code_fences() {
		$json   = "```json\n{\"description\":\"A red car.\",\"tags\":[\"car\",\"red\"],\"alt\":\"Red car.\"}\n```";
		$result = Prompt::parse( $json );
		$this->assertInstanceOf( Description_Result::class, $result );
		$this->assertSame( 'A red car.', $result->description );
	}

	public function test_parse_rejects_missing_field() {
		$result = Prompt::parse( '{"description":"x","tags":["a"]}' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'bad_response', $result->get_error_code() );
	}

	public function test_parse_rejects_malformed_json() {
		$result = Prompt::parse( 'not json' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'bad_response', $result->get_error_code() );
	}

	public function test_parse_rejects_empty_description() {
		$result = Prompt::parse( '{"description":"  ","tags":["a"],"alt":"b"}' );
		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_parse_drops_non_string_tags_and_caps_at_30() {
		$tags = array_map( 'strval', range( 1, 40 ) );
		$tags[] = 12;
		$json   = wp_json_encode_stub( array( 'description' => 'd', 'tags' => $tags, 'alt' => 'a' ) );
		$result = Prompt::parse( $json );
		$this->assertCount( 30, $result->tags );
	}
}

function wp_json_encode_stub( $data ) {
	return json_encode( $data );
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit --filter PromptTest`
Expected: Error, class `AIMS\Prompt` not found.

- [ ] **Step 3: Write the Prompt class**

`includes/class-prompt.php`:

```php
<?php
/**
 * Shared instruction text, JSON schema, and response parsing for all providers.
 *
 * @package AIMS
 */

namespace AIMS;

defined( 'ABSPATH' ) || exit;

final class Prompt {
	const MAX_TAGS = 30;

	public static function schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'description' => array(
					'type'        => 'string',
					'description' => 'Two to four sentences describing the image.',
				),
				'tags'        => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => 'Ten to twenty lowercase tags including plain synonyms.',
				),
				'alt'         => array(
					'type'        => 'string',
					'description' => 'One sentence under 125 characters suitable as HTML alt text.',
				),
			),
			'required'             => array( 'description', 'tags', 'alt' ),
			'additionalProperties' => false,
		);
	}

	public static function user_text(): string {
		return 'Describe this image.';
	}

	public static function instructions( string $language, string $custom_prompt = '' ): string {
		$lines = array(
			'You describe images for a website media library so that editors can find them later by typing plain words into a search box.',
			'Reply with JSON only, matching the schema you were given.',
			'',
			'description: Two to four sentences. Cover the main subjects, any people (how many, roughly what they are doing, notable clothing or expression; never guess names or identities), actions, setting, colours, any visible text, and the overall style (photo, illustration, screenshot, logo, diagram).',
			'tags: Ten to twenty lowercase tags, single words or short phrases. Include plain synonyms editors might type, for example "woman", "female", "lady"; "car", "vehicle", "automobile". Include the setting, colours, mood, and objects.',
			'alt: One sentence under 125 characters that works as HTML alt text.',
			'',
			sprintf( 'Write the description, tags, and alt in %s.', $language ),
		);

		$custom_prompt = trim( $custom_prompt );
		if ( '' !== $custom_prompt ) {
			$lines[] = '';
			$lines[] = 'Additional guidance from the site owner:';
			$lines[] = $custom_prompt;
		}

		return implode( "\n", $lines );
	}

	/**
	 * @return Description_Result|\WP_Error
	 */
	public static function parse( string $json ) {
		$json = trim( $json );
		$json = preg_replace( '/^```(?:json)?\s*/i', '', $json );
		$json = preg_replace( '/\s*```$/', '', $json );

		$data = json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'bad_response', __( 'The AI reply was not valid JSON.', 'ai-media-search' ) );
		}

		foreach ( array( 'description', 'tags', 'alt' ) as $field ) {
			if ( ! array_key_exists( $field, $data ) ) {
				return new \WP_Error(
					'bad_response',
					/* translators: %s: field name */
					sprintf( __( 'The AI reply is missing the "%s" field.', 'ai-media-search' ), $field )
				);
			}
		}

		if ( ! is_string( $data['description'] ) || '' === trim( $data['description'] ) ) {
			return new \WP_Error( 'bad_response', __( 'The AI reply has an empty description.', 'ai-media-search' ) );
		}
		if ( ! is_array( $data['tags'] ) || ! is_string( $data['alt'] ) ) {
			return new \WP_Error( 'bad_response', __( 'The AI reply has the wrong field types.', 'ai-media-search' ) );
		}

		$tags = array();
		foreach ( $data['tags'] as $tag ) {
			if ( ! is_string( $tag ) ) {
				continue;
			}
			$tag = strtolower( trim( $tag ) );
			if ( '' === $tag || in_array( $tag, $tags, true ) ) {
				continue;
			}
			$tags[] = $tag;
			if ( count( $tags ) >= self::MAX_TAGS ) {
				break;
			}
		}

		return new Description_Result( trim( $data['description'] ), $tags, trim( $data['alt'] ) );
	}
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit --filter PromptTest`
Expected: OK (9 tests).

- [ ] **Step 5: Commit**

```bash
git add includes/class-prompt.php tests/unit/PromptTest.php
git -c commit.gpgsign=false commit -m "Add shared prompt, JSON schema, and response parser"
```

---

### Task 3: Settings model

**Files:**
- Create: `includes/class-settings.php`
- Modify: `includes/class-plugin.php` (register Settings in `init()`)
- Test: `tests/unit/SettingsTest.php`

**Interfaces:**
- Produces: `AIMS\Settings` with `const OPTION = 'aims_settings'`, `const PROVIDERS = array( 'claude', 'openai', 'gemini' )`, `defaults(): array`, `all(): array`, `get( string $key )`, `sanitize( $input ): array`, `get_api_key( string $provider ): string`, `get_model( string $provider ): string`, `get_active_model(): string`, and instance method `register()` that calls `register_setting`. Known model lists are read from `AIMS\Providers\Registry::known_models( $provider )` which Task 4 creates; until then `sanitize()` accepts any non-empty model string.

- [ ] **Step 1: Write the failing tests**

`tests/unit/SettingsTest.php`:

```php
<?php
namespace AIMS\Tests;

use AIMS\Settings;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class SettingsTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Functions\when( 'sanitize_textarea_field' )->returnArg( 1 );
		Functions\when( 'wp_unslash' )->returnArg( 1 );
		Functions\when( '__' )->returnArg( 1 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_defaults() {
		$d = Settings::defaults();
		$this->assertSame( 'claude', $d['provider'] );
		$this->assertSame( 'English', $d['language'] );
		$this->assertTrue( $d['auto_index'] );
		$this->assertFalse( $d['fill_alt'] );
		$this->assertSame( 3, $d['batch_size'] );
		$this->assertSame( array( 'claude' => '', 'openai' => '', 'gemini' => '' ), $d['api_keys'] );
	}

	public function test_all_merges_saved_values_over_defaults() {
		Functions\when( 'get_option' )->justReturn( array( 'provider' => 'gemini', 'api_keys' => array( 'gemini' => 'g-key' ) ) );
		$all = Settings::all();
		$this->assertSame( 'gemini', $all['provider'] );
		$this->assertSame( 'g-key', $all['api_keys']['gemini'] );
		$this->assertSame( '', $all['api_keys']['claude'] );
		$this->assertSame( 'English', $all['language'] );
	}

	public function test_get_api_key_and_active_model() {
		Functions\when( 'get_option' )->justReturn(
			array(
				'provider'      => 'openai',
				'api_keys'      => array( 'openai' => 'sk-1' ),
				'models'        => array( 'openai' => 'custom' ),
				'custom_models' => array( 'openai' => 'gpt-future' ),
			)
		);
		$this->assertSame( 'sk-1', Settings::get_api_key( 'openai' ) );
		$this->assertSame( '', Settings::get_api_key( 'claude' ) );
		$this->assertSame( 'gpt-future', Settings::get_active_model() );
	}

	public function test_sanitize_normalises_input() {
		Functions\when( 'get_option' )->justReturn( array( 'api_keys' => array( 'claude' => 'keep-me' ) ) );
		$out = Settings::sanitize(
			array(
				'provider'      => 'openai',
				'api_keys'      => array( 'openai' => ' sk-2 ', 'claude' => '' ),
				'models'        => array( 'openai' => 'gpt-5.6-terra' ),
				'custom_models' => array( 'openai' => '' ),
				'language'      => ' French ',
				'custom_prompt' => "Use brand names.\n",
				'auto_index'    => '1',
				'batch_size'    => '25',
			)
		);
		$this->assertSame( 'openai', $out['provider'] );
		$this->assertSame( 'sk-2', $out['api_keys']['openai'] );
		$this->assertSame( 'keep-me', $out['api_keys']['claude'], 'empty key keeps the previously saved key' );
		$this->assertSame( 'French', $out['language'] );
		$this->assertSame( 'Use brand names.', $out['custom_prompt'] );
		$this->assertTrue( $out['auto_index'] );
		$this->assertFalse( $out['fill_alt'] );
		$this->assertSame( 10, $out['batch_size'] );
	}

	public function test_sanitize_rejects_unknown_provider() {
		Functions\when( 'get_option' )->justReturn( array() );
		$out = Settings::sanitize( array( 'provider' => 'bogus' ) );
		$this->assertSame( 'claude', $out['provider'] );
	}
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit --filter SettingsTest`
Expected: Error, class `AIMS\Settings` not found.

- [ ] **Step 3: Write the Settings class**

`includes/class-settings.php`:

```php
<?php
/**
 * Single-option settings model.
 *
 * @package AIMS
 */

namespace AIMS;

defined( 'ABSPATH' ) || exit;

final class Settings {
	const OPTION    = 'aims_settings';
	const PROVIDERS = array( 'claude', 'openai', 'gemini' );

	public static function defaults(): array {
		$per_provider = array_fill_keys( self::PROVIDERS, '' );
		return array(
			'provider'      => 'claude',
			'api_keys'      => $per_provider,
			'models'        => $per_provider,
			'custom_models' => $per_provider,
			'language'      => 'English',
			'custom_prompt' => '',
			'auto_index'    => true,
			'fill_alt'      => false,
			'batch_size'    => 3,
		);
	}

	public static function all(): array {
		$saved    = get_option( self::OPTION, array() );
		$defaults = self::defaults();
		if ( ! is_array( $saved ) ) {
			return $defaults;
		}
		$all = array_merge( $defaults, $saved );
		foreach ( array( 'api_keys', 'models', 'custom_models' ) as $key ) {
			$all[ $key ] = array_merge( $defaults[ $key ], is_array( $saved[ $key ] ?? null ) ? $saved[ $key ] : array() );
		}
		return $all;
	}

	/**
	 * @return mixed
	 */
	public static function get( string $key ) {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	public static function get_api_key( string $provider ): string {
		$keys = self::get( 'api_keys' );
		return (string) ( $keys[ $provider ] ?? '' );
	}

	public static function get_model( string $provider ): string {
		$all   = self::all();
		$model = (string) ( $all['models'][ $provider ] ?? '' );
		if ( 'custom' === $model ) {
			$model = (string) ( $all['custom_models'][ $provider ] ?? '' );
		}
		if ( '' === $model && class_exists( '\AIMS\Providers\Registry' ) ) {
			$known = Providers\Registry::known_models( $provider );
			$model = $known ? (string) array_key_first( $known ) : '';
		}
		return $model;
	}

	public static function get_active_model(): string {
		return self::get_model( (string) self::get( 'provider' ) );
	}

	/**
	 * @param mixed $input Raw form input.
	 */
	public static function sanitize( $input ): array {
		$input    = is_array( $input ) ? $input : array();
		$previous = self::all();
		$out      = self::defaults();

		$provider        = sanitize_text_field( (string) ( $input['provider'] ?? '' ) );
		$out['provider'] = in_array( $provider, self::PROVIDERS, true ) ? $provider : 'claude';

		foreach ( self::PROVIDERS as $id ) {
			$key = trim( sanitize_text_field( (string) ( $input['api_keys'][ $id ] ?? '' ) ) );
			// An empty submitted key keeps the stored one so the password field can stay blank.
			$out['api_keys'][ $id ] = '' === $key ? (string) ( $previous['api_keys'][ $id ] ?? '' ) : $key;

			$out['models'][ $id ]        = trim( sanitize_text_field( (string) ( $input['models'][ $id ] ?? '' ) ) );
			$out['custom_models'][ $id ] = trim( sanitize_text_field( (string) ( $input['custom_models'][ $id ] ?? '' ) ) );
		}

		$language        = trim( sanitize_text_field( (string) ( $input['language'] ?? '' ) ) );
		$out['language'] = '' === $language ? 'English' : $language;

		$out['custom_prompt'] = trim( sanitize_textarea_field( (string) ( $input['custom_prompt'] ?? '' ) ) );
		$out['auto_index']    = ! empty( $input['auto_index'] );
		$out['fill_alt']      = ! empty( $input['fill_alt'] );

		$batch             = (int) ( $input['batch_size'] ?? 3 );
		$out['batch_size'] = max( 1, min( 10, $batch ?: 3 ) );

		return $out;
	}

	public function register(): void {
		add_action( 'admin_init', array( $this, 'register_setting' ) );
	}

	public function register_setting(): void {
		register_setting(
			'aims_settings_group',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}
}
```

Note `array_key_first` is PHP 7.3+, fine for our 7.4 floor.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit --filter SettingsTest`
Expected: OK (5 tests).

- [ ] **Step 5: Register Settings in Plugin::init()**

In `includes/class-plugin.php`, replace the comment line inside `init()` with:

```php
		( new Settings() )->register();
```

- [ ] **Step 6: Commit**

```bash
git add includes/class-settings.php includes/class-plugin.php tests/unit/SettingsTest.php
git -c commit.gpgsign=false commit -m "Add settings model with sanitization"
```

---

### Task 4: Provider interface, registry, abstract provider, Claude provider

**Files:**
- Create: `includes/providers/interface-provider.php`, `includes/providers/class-registry.php`, `includes/providers/class-abstract-provider.php`, `includes/providers/class-claude-provider.php`
- Test: `tests/unit/ClaudeProviderTest.php`, `tests/unit/RegistryTest.php`

**Interfaces:**
- Consumes: `Prompt::schema()`, `Prompt::user_text()`, `Prompt::parse()`, `Settings::get_api_key()`, `Settings::get_model()`, `Settings::get( 'provider' )`.
- Produces:
  - `AIMS\Providers\Provider_Interface` with `describe( string $file_path, string $mime_type, string $instructions )` returning `Description_Result|WP_Error`, `test_connection()` returning `true|WP_Error`, static `get_id(): string`, `get_label(): string`, `get_known_models(): array` (model id => hint).
  - `AIMS\Providers\Abstract_Provider` with constructor `( string $api_key, string $model )`, abstract `build_request( string $b64, string $mime, string $instructions ): array` returning `array( 'url' => ..., 'headers' => array, 'body' => array )`, abstract `build_ping_request(): array` (same shape), abstract `extract_text( array $data )` returning `string|WP_Error`, and helper `handle_response( $response )` returning decoded array or `WP_Error` with codes `auth_error`, `rate_limited`, `server_error`, `bad_response`.
  - `AIMS\Providers\Registry` with `ids(): array`, `labels(): array`, `known_models( string $id ): array`, `make( string $id )` returning `Provider_Interface|WP_Error`, `active()` returning `Provider_Interface|WP_Error`.
  - `AIMS\Providers\Claude_Provider`.

- [ ] **Step 1: Write the failing Claude provider test**

`tests/unit/ClaudeProviderTest.php`:

```php
<?php
namespace AIMS\Tests;

use AIMS\Description_Result;
use AIMS\Providers\Claude_Provider;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class ClaudeProviderTest extends TestCase {
	private $file;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( function ( $r ) { return $r['response']['code']; } );
		Functions\when( 'wp_remote_retrieve_body' )->alias( function ( $r ) { return $r['body']; } );
		$this->file = tempnam( sys_get_temp_dir(), 'aims' );
		file_put_contents( $this->file, 'fakejpegbytes' );
	}

	protected function tearDown(): void {
		unlink( $this->file );
		Monkey\tearDown();
		parent::tearDown();
	}

	private function response( int $code, array $body ): array {
		return array( 'response' => array( 'code' => $code ), 'body' => json_encode( $body ) );
	}

	public function test_builds_request_per_spec_and_parses_success() {
		$captured = null;
		Functions\expect( 'wp_remote_post' )->once()->andReturnUsing(
			function ( $url, $args ) use ( &$captured ) {
				$captured = array( 'url' => $url, 'args' => $args );
				return $this->response( 200, array(
					'content'     => array( array( 'type' => 'text', 'text' => '{"description":"A woman.","tags":["woman"],"alt":"A woman."}' ) ),
					'stop_reason' => 'end_turn',
				) );
			}
		);

		$provider = new Claude_Provider( 'sk-ant', 'claude-opus-5' );
		$result   = $provider->describe( $this->file, 'image/jpeg', 'INSTRUCTIONS' );

		$this->assertInstanceOf( Description_Result::class, $result );
		$this->assertSame( 'https://api.anthropic.com/v1/messages', $captured['url'] );
		$this->assertSame( 'sk-ant', $captured['args']['headers']['x-api-key'] );
		$this->assertSame( '2023-06-01', $captured['args']['headers']['anthropic-version'] );
		$this->assertSame( 60, $captured['args']['timeout'] );

		$body = json_decode( $captured['args']['body'], true );
		$this->assertSame( 'claude-opus-5', $body['model'] );
		$this->assertSame( 'INSTRUCTIONS', $body['system'] );
		$this->assertSame( 'image', $body['messages'][0]['content'][0]['type'] );
		$this->assertSame( 'base64', $body['messages'][0]['content'][0]['source']['type'] );
		$this->assertSame( 'image/jpeg', $body['messages'][0]['content'][0]['source']['media_type'] );
		$this->assertSame( base64_encode( 'fakejpegbytes' ), $body['messages'][0]['content'][0]['source']['data'] );
		$this->assertSame( 'Describe this image.', $body['messages'][0]['content'][1]['text'] );
		$this->assertSame( 'json_schema', $body['output_config']['format']['type'] );
		$this->assertFalse( $body['output_config']['format']['schema']['additionalProperties'] );
	}

	public function test_maps_http_errors() {
		$cases = array(
			array( 401, 'auth_error' ),
			array( 403, 'auth_error' ),
			array( 429, 'rate_limited' ),
			array( 500, 'server_error' ),
			array( 529, 'server_error' ),
			array( 400, 'bad_response' ),
		);
		foreach ( $cases as list( $code, $expected ) ) {
			Functions\when( 'wp_remote_post' )->justReturn( $this->response( $code, array( 'error' => array( 'type' => 'x', 'message' => 'boom' ) ) ) );
			$result = ( new Claude_Provider( 'k', 'm' ) )->describe( $this->file, 'image/png', 'i' );
			$this->assertInstanceOf( \WP_Error::class, $result, "code $code" );
			$this->assertSame( $expected, $result->get_error_code(), "code $code" );
			$this->assertSame( 'boom', $result->get_error_message(), "code $code" );
		}
	}

	public function test_transport_error_is_server_error() {
		Functions\when( 'wp_remote_post' )->justReturn( new \WP_Error( 'http_request_failed', 'cURL timeout' ) );
		$result = ( new Claude_Provider( 'k', 'm' ) )->describe( $this->file, 'image/png', 'i' );
		$this->assertSame( 'server_error', $result->get_error_code() );
		$this->assertSame( 'cURL timeout', $result->get_error_message() );
	}

	public function test_refusal_stop_reason() {
		Functions\when( 'wp_remote_post' )->justReturn( $this->response( 200, array( 'content' => array(), 'stop_reason' => 'refusal' ) ) );
		$result = ( new Claude_Provider( 'k', 'm' ) )->describe( $this->file, 'image/png', 'i' );
		$this->assertSame( 'refused', $result->get_error_code() );
	}

	public function test_missing_api_key() {
		Functions\expect( 'wp_remote_post' )->never();
		$result = ( new Claude_Provider( '', 'm' ) )->describe( $this->file, 'image/png', 'i' );
		$this->assertSame( 'auth_error', $result->get_error_code() );
	}

	public function test_unreadable_file() {
		Functions\expect( 'wp_remote_post' )->never();
		$result = ( new Claude_Provider( 'k', 'm' ) )->describe( '/nope/missing.jpg', 'image/jpeg', 'i' );
		$this->assertSame( 'bad_file', $result->get_error_code() );
	}

	public function test_test_connection_ok() {
		Functions\when( 'wp_remote_post' )->justReturn( $this->response( 200, array( 'content' => array( array( 'type' => 'text', 'text' => 'OK' ) ), 'stop_reason' => 'end_turn' ) ) );
		$this->assertTrue( ( new Claude_Provider( 'k', 'm' ) )->test_connection() );
	}

	public function test_known_models_not_empty() {
		$models = Claude_Provider::get_known_models();
		$this->assertArrayHasKey( 'claude-opus-5', $models );
		$this->assertSame( 'claude', Claude_Provider::get_id() );
	}
}
```

- [ ] **Step 2: Write the failing Registry test**

`tests/unit/RegistryTest.php`:

```php
<?php
namespace AIMS\Tests;

use AIMS\Providers\Claude_Provider;
use AIMS\Providers\Registry;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class RegistryTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg( 1 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_ids_and_labels() {
		$this->assertSame( array( 'claude', 'openai', 'gemini' ), Registry::ids() );
		$this->assertArrayHasKey( 'gemini', Registry::labels() );
	}

	public function test_make_returns_provider_with_settings() {
		Functions\when( 'get_option' )->justReturn( array( 'api_keys' => array( 'claude' => 'k' ), 'models' => array( 'claude' => 'claude-sonnet-5' ) ) );
		$provider = Registry::make( 'claude' );
		$this->assertInstanceOf( Claude_Provider::class, $provider );
	}

	public function test_make_unknown_id_is_error() {
		$this->assertInstanceOf( \WP_Error::class, Registry::make( 'nope' ) );
	}

	public function test_known_models_falls_back_to_empty() {
		$this->assertSame( array(), Registry::known_models( 'nope' ) );
	}
}
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `vendor/bin/phpunit --filter 'ClaudeProviderTest|RegistryTest'`
Expected: Errors, classes not found.

- [ ] **Step 4: Write the interface**

`includes/providers/interface-provider.php`:

```php
<?php
/**
 * Contract every vision provider implements.
 *
 * @package AIMS
 */

namespace AIMS\Providers;

defined( 'ABSPATH' ) || exit;

interface Provider_Interface {
	/**
	 * Describe one image file.
	 *
	 * @param string $file_path    Absolute path of a JPEG/PNG/GIF/WebP file.
	 * @param string $mime_type    Mime type of that file.
	 * @param string $instructions System instruction text from Prompt::instructions().
	 * @return \AIMS\Description_Result|\WP_Error
	 */
	public function describe( string $file_path, string $mime_type, string $instructions );

	/**
	 * Cheap text-only request that proves the key and model work.
	 *
	 * @return true|\WP_Error
	 */
	public function test_connection();

	public static function get_id(): string;

	public static function get_label(): string;

	/**
	 * @return array<string,string> model id => short hint shown in the settings dropdown.
	 */
	public static function get_known_models(): array;
}
```

- [ ] **Step 5: Write the abstract provider**

`includes/providers/class-abstract-provider.php`:

```php
<?php
/**
 * Shared HTTP plumbing and error mapping for providers.
 *
 * @package AIMS
 */

namespace AIMS\Providers;

use AIMS\Prompt;

defined( 'ABSPATH' ) || exit;

abstract class Abstract_Provider implements Provider_Interface {
	const TIMEOUT = 60;

	/** @var string */
	protected $api_key;

	/** @var string */
	protected $model;

	public function __construct( string $api_key, string $model ) {
		$this->api_key = $api_key;
		$this->model   = $model;
	}

	/**
	 * @return array{url:string,headers:array,body:array}
	 */
	abstract protected function build_request( string $b64, string $mime, string $instructions ): array;

	/**
	 * @return array{url:string,headers:array,body:array}
	 */
	abstract protected function build_ping_request(): array;

	/**
	 * Pull the JSON text out of a decoded successful response.
	 *
	 * @return string|\WP_Error
	 */
	abstract protected function extract_text( array $data );

	public function describe( string $file_path, string $mime_type, string $instructions ) {
		if ( '' === $this->api_key ) {
			return new \WP_Error( 'auth_error', __( 'No API key is saved for this provider.', 'ai-media-search' ) );
		}

		$bytes = is_readable( $file_path ) ? file_get_contents( $file_path ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $bytes || '' === $bytes ) {
			return new \WP_Error( 'bad_file', __( 'The image file could not be read.', 'ai-media-search' ) );
		}

		$request = $this->build_request( base64_encode( $bytes ), $mime_type, $instructions ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$data    = $this->send( $request );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$text = $this->extract_text( $data );
		if ( is_wp_error( $text ) ) {
			return $text;
		}

		return Prompt::parse( $text );
	}

	public function test_connection() {
		if ( '' === $this->api_key ) {
			return new \WP_Error( 'auth_error', __( 'No API key is saved for this provider.', 'ai-media-search' ) );
		}
		$data = $this->send( $this->build_ping_request() );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$text = $this->extract_text( $data );
		return is_wp_error( $text ) ? $text : true;
	}

	/**
	 * @return array|\WP_Error Decoded JSON body.
	 */
	protected function send( array $request ) {
		$response = wp_remote_post(
			$request['url'],
			array(
				'headers' => $request['headers'],
				'body'    => wp_json_encode( $request['body'] ),
				'timeout' => self::TIMEOUT,
			)
		);
		return $this->handle_response( $response );
	}

	/**
	 * @param array|\WP_Error $response Raw wp_remote_post result.
	 * @return array|\WP_Error
	 */
	protected function handle_response( $response ) {
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'server_error', $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );

		if ( 401 === $code || 403 === $code ) {
			return new \WP_Error( 'auth_error', $this->error_message( $body, __( 'The API key was rejected.', 'ai-media-search' ) ) );
		}
		if ( 429 === $code ) {
			return new \WP_Error( 'rate_limited', $this->error_message( $body, __( 'The provider rate limit was reached.', 'ai-media-search' ) ) );
		}
		if ( $code >= 500 ) {
			return new \WP_Error( 'server_error', $this->error_message( $body, __( 'The provider returned a server error.', 'ai-media-search' ) ) );
		}
		if ( 200 !== $code ) {
			return new \WP_Error( 'bad_response', $this->error_message( $body, sprintf( /* translators: %d: HTTP status */ __( 'Unexpected HTTP status %d.', 'ai-media-search' ), $code ) ) );
		}

		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'bad_response', __( 'The provider reply was not valid JSON.', 'ai-media-search' ) );
		}
		return $data;
	}

	/**
	 * All three providers put a human message at error.message.
	 */
	protected function error_message( string $body, string $fallback ): string {
		$data = json_decode( $body, true );
		if ( is_array( $data ) && isset( $data['error']['message'] ) && is_string( $data['error']['message'] ) ) {
			return $data['error']['message'];
		}
		return $fallback;
	}
}
```

- [ ] **Step 6: Write the Claude provider**

`includes/providers/class-claude-provider.php`:

```php
<?php
/**
 * Anthropic Claude Messages API provider.
 *
 * @package AIMS
 */

namespace AIMS\Providers;

use AIMS\Prompt;

defined( 'ABSPATH' ) || exit;

final class Claude_Provider extends Abstract_Provider {
	const ENDPOINT = 'https://api.anthropic.com/v1/messages';

	public static function get_id(): string {
		return 'claude';
	}

	public static function get_label(): string {
		return __( 'Anthropic Claude', 'ai-media-search' );
	}

	public static function get_known_models(): array {
		return array(
			'claude-opus-5'   => __( 'Highest quality, about $0.015 per image', 'ai-media-search' ),
			'claude-sonnet-5' => __( 'Balanced, about $0.006 per image', 'ai-media-search' ),
			'claude-haiku-4-5' => __( 'Fastest and cheapest, about $0.003 per image', 'ai-media-search' ),
		);
	}

	private function headers(): array {
		return array(
			'x-api-key'         => $this->api_key,
			'anthropic-version' => '2023-06-01',
			'content-type'      => 'application/json',
		);
	}

	protected function build_request( string $b64, string $mime, string $instructions ): array {
		return array(
			'url'     => self::ENDPOINT,
			'headers' => $this->headers(),
			'body'    => array(
				'model'         => $this->model,
				'max_tokens'    => 1024,
				'system'        => $instructions,
				'messages'      => array(
					array(
						'role'    => 'user',
						'content' => array(
							array(
								'type'   => 'image',
								'source' => array(
									'type'       => 'base64',
									'media_type' => $mime,
									'data'       => $b64,
								),
							),
							array(
								'type' => 'text',
								'text' => Prompt::user_text(),
							),
						),
					),
				),
				'output_config' => array(
					'format' => array(
						'type'   => 'json_schema',
						'schema' => Prompt::schema(),
					),
				),
			),
		);
	}

	protected function build_ping_request(): array {
		return array(
			'url'     => self::ENDPOINT,
			'headers' => $this->headers(),
			'body'    => array(
				'model'      => $this->model,
				'max_tokens' => 16,
				'messages'   => array(
					array(
						'role'    => 'user',
						'content' => 'Reply with the word OK.',
					),
				),
			),
		);
	}

	protected function extract_text( array $data ) {
		if ( 'refusal' === ( $data['stop_reason'] ?? '' ) ) {
			return new \WP_Error( 'refused', __( 'The model declined to describe this image.', 'ai-media-search' ) );
		}
		foreach ( (array) ( $data['content'] ?? array() ) as $block ) {
			if ( isset( $block['type'], $block['text'] ) && 'text' === $block['type'] ) {
				return (string) $block['text'];
			}
		}
		return new \WP_Error( 'bad_response', __( 'The reply contained no text.', 'ai-media-search' ) );
	}
}
```

- [ ] **Step 7: Write the Registry**

`includes/providers/class-registry.php`:

```php
<?php
/**
 * Knows every provider and builds them from settings.
 *
 * @package AIMS
 */

namespace AIMS\Providers;

use AIMS\Settings;

defined( 'ABSPATH' ) || exit;

final class Registry {
	/**
	 * @return array<string,class-string<Provider_Interface>>
	 */
	public static function classes(): array {
		return array(
			'claude' => Claude_Provider::class,
			'openai' => OpenAI_Provider::class,
			'gemini' => Gemini_Provider::class,
		);
	}

	public static function ids(): array {
		return array_keys( self::classes() );
	}

	/**
	 * @return array<string,string> id => label
	 */
	public static function labels(): array {
		$labels = array();
		foreach ( self::classes() as $id => $class ) {
			$labels[ $id ] = class_exists( $class ) ? $class::get_label() : $id;
		}
		return $labels;
	}

	public static function known_models( string $id ): array {
		$classes = self::classes();
		if ( ! isset( $classes[ $id ] ) || ! class_exists( $classes[ $id ] ) ) {
			return array();
		}
		return $classes[ $id ]::get_known_models();
	}

	/**
	 * @return Provider_Interface|\WP_Error
	 */
	public static function make( string $id ) {
		$classes = self::classes();
		if ( ! isset( $classes[ $id ] ) || ! class_exists( $classes[ $id ] ) ) {
			return new \WP_Error( 'bad_provider', __( 'Unknown AI provider.', 'ai-media-search' ) );
		}
		$class = $classes[ $id ];
		return new $class( Settings::get_api_key( $id ), Settings::get_model( $id ) );
	}

	/**
	 * @return Provider_Interface|\WP_Error
	 */
	public static function active() {
		return self::make( (string) Settings::get( 'provider' ) );
	}
}
```

`OpenAI_Provider` and `Gemini_Provider` do not exist yet; `class_exists` guards keep the registry usable until Tasks 5 and 6 add them.

- [ ] **Step 8: Run the tests to verify they pass**

Run: `vendor/bin/phpunit --filter 'ClaudeProviderTest|RegistryTest'`
Expected: OK (12 tests).

- [ ] **Step 9: Run the whole suite and lint**

Run: `vendor/bin/phpunit && for f in includes/providers/*.php; do php -l "$f"; done`
Expected: all green, no syntax errors.

- [ ] **Step 10: Commit**

```bash
git add includes/providers tests/unit/ClaudeProviderTest.php tests/unit/RegistryTest.php
git -c commit.gpgsign=false commit -m "Add provider interface, registry, and Claude provider"
```

---

### Task 5: OpenAI provider

**Files:**
- Create: `includes/providers/class-openai-provider.php`
- Test: `tests/unit/OpenAIProviderTest.php`

**Interfaces:**
- Consumes: `Abstract_Provider`, `Prompt`.
- Produces: `AIMS\Providers\OpenAI_Provider` with id `openai`.

- [ ] **Step 1: Write the failing test**

`tests/unit/OpenAIProviderTest.php`:

```php
<?php
namespace AIMS\Tests;

use AIMS\Description_Result;
use AIMS\Providers\OpenAI_Provider;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class OpenAIProviderTest extends TestCase {
	private $file;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( function ( $r ) { return $r['response']['code']; } );
		Functions\when( 'wp_remote_retrieve_body' )->alias( function ( $r ) { return $r['body']; } );
		$this->file = tempnam( sys_get_temp_dir(), 'aims' );
		file_put_contents( $this->file, 'pngbytes' );
	}

	protected function tearDown(): void {
		unlink( $this->file );
		Monkey\tearDown();
		parent::tearDown();
	}

	private function response( int $code, array $body ): array {
		return array( 'response' => array( 'code' => $code ), 'body' => json_encode( $body ) );
	}

	private function success_body(): array {
		return array(
			'status' => 'completed',
			'output' => array(
				array( 'type' => 'reasoning', 'summary' => array() ),
				array(
					'type'    => 'message',
					'content' => array(
						array( 'type' => 'output_text', 'text' => '{"description":"A cat.","tags":["cat"],"alt":"A cat."}' ),
					),
				),
			),
		);
	}

	public function test_builds_request_per_spec_and_parses_success() {
		$captured = null;
		Functions\expect( 'wp_remote_post' )->once()->andReturnUsing(
			function ( $url, $args ) use ( &$captured ) {
				$captured = array( 'url' => $url, 'args' => $args );
				return $this->response( 200, $this->success_body() );
			}
		);

		$result = ( new OpenAI_Provider( 'sk-openai', 'gpt-5.6-terra' ) )->describe( $this->file, 'image/png', 'INSTR' );
		$this->assertInstanceOf( Description_Result::class, $result );

		$this->assertSame( 'https://api.openai.com/v1/responses', $captured['url'] );
		$this->assertSame( 'Bearer sk-openai', $captured['args']['headers']['Authorization'] );

		$body = json_decode( $captured['args']['body'], true );
		$this->assertSame( 'gpt-5.6-terra', $body['model'] );
		$this->assertSame( 'INSTR', $body['instructions'] );
		$this->assertSame( 'input_text', $body['input'][0]['content'][0]['type'] );
		$this->assertSame( 'input_image', $body['input'][0]['content'][1]['type'] );
		$this->assertSame( 'data:image/png;base64,' . base64_encode( 'pngbytes' ), $body['input'][0]['content'][1]['image_url'] );
		$this->assertSame( 'auto', $body['input'][0]['content'][1]['detail'] );
		$this->assertSame( 'json_schema', $body['text']['format']['type'] );
		$this->assertSame( 'media_description', $body['text']['format']['name'] );
		$this->assertTrue( $body['text']['format']['strict'] );
		$this->assertSame( array( 'description', 'tags', 'alt' ), $body['text']['format']['schema']['required'] );
	}

	public function test_refusal_content_item() {
		$body = array(
			'status' => 'completed',
			'output' => array( array( 'type' => 'message', 'content' => array( array( 'type' => 'refusal', 'refusal' => 'no' ) ) ) ),
		);
		Functions\when( 'wp_remote_post' )->justReturn( $this->response( 200, $body ) );
		$result = ( new OpenAI_Provider( 'k', 'm' ) )->describe( $this->file, 'image/png', 'i' );
		$this->assertSame( 'refused', $result->get_error_code() );
	}

	public function test_incomplete_status_is_bad_response() {
		$body = array( 'status' => 'incomplete', 'output' => array() );
		Functions\when( 'wp_remote_post' )->justReturn( $this->response( 200, $body ) );
		$result = ( new OpenAI_Provider( 'k', 'm' ) )->describe( $this->file, 'image/png', 'i' );
		$this->assertSame( 'bad_response', $result->get_error_code() );
	}

	public function test_http_401_maps_to_auth_error() {
		Functions\when( 'wp_remote_post' )->justReturn( $this->response( 401, array( 'error' => array( 'message' => 'bad key' ) ) ) );
		$result = ( new OpenAI_Provider( 'k', 'm' ) )->describe( $this->file, 'image/png', 'i' );
		$this->assertSame( 'auth_error', $result->get_error_code() );
		$this->assertSame( 'bad key', $result->get_error_message() );
	}

	public function test_ping_uses_text_only_input() {
		$captured = null;
		Functions\expect( 'wp_remote_post' )->once()->andReturnUsing(
			function ( $url, $args ) use ( &$captured ) {
				$captured = json_decode( $args['body'], true );
				return $this->response( 200, array( 'status' => 'completed', 'output' => array( array( 'type' => 'message', 'content' => array( array( 'type' => 'output_text', 'text' => 'OK' ) ) ) ) ) );
			}
		);
		$this->assertTrue( ( new OpenAI_Provider( 'k', 'm' ) )->test_connection() );
		$this->assertSame( 'Reply with the word OK.', $captured['input'] );
		$this->assertSame( 16, $captured['max_output_tokens'] );
	}

	public function test_known_models() {
		$this->assertArrayHasKey( 'gpt-5.6-terra', OpenAI_Provider::get_known_models() );
		$this->assertSame( 'openai', OpenAI_Provider::get_id() );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter OpenAIProviderTest`
Expected: Error, class not found.

- [ ] **Step 3: Write the OpenAI provider**

`includes/providers/class-openai-provider.php`:

```php
<?php
/**
 * OpenAI Responses API provider.
 *
 * @package AIMS
 */

namespace AIMS\Providers;

use AIMS\Prompt;

defined( 'ABSPATH' ) || exit;

final class OpenAI_Provider extends Abstract_Provider {
	const ENDPOINT = 'https://api.openai.com/v1/responses';

	public static function get_id(): string {
		return 'openai';
	}

	public static function get_label(): string {
		return __( 'OpenAI', 'ai-media-search' );
	}

	public static function get_known_models(): array {
		return array(
			'gpt-5.6-terra' => __( 'Balanced, about $0.007 per image', 'ai-media-search' ),
			'gpt-5.6-luna'  => __( 'Cheapest, about $0.001 per image', 'ai-media-search' ),
			'gpt-5.6-sol'   => __( 'High quality, about $0.012 per image', 'ai-media-search' ),
			'gpt-6-astra'   => __( 'Flagship, about $0.03 per image', 'ai-media-search' ),
		);
	}

	private function headers(): array {
		return array(
			'Authorization' => 'Bearer ' . $this->api_key,
			'Content-Type'  => 'application/json',
		);
	}

	protected function build_request( string $b64, string $mime, string $instructions ): array {
		return array(
			'url'     => self::ENDPOINT,
			'headers' => $this->headers(),
			'body'    => array(
				'model'             => $this->model,
				'instructions'      => $instructions,
				'max_output_tokens' => 1024,
				'input'             => array(
					array(
						'role'    => 'user',
						'content' => array(
							array(
								'type' => 'input_text',
								'text' => Prompt::user_text(),
							),
							array(
								'type'      => 'input_image',
								'image_url' => 'data:' . $mime . ';base64,' . $b64,
								'detail'    => 'auto',
							),
						),
					),
				),
				'text'              => array(
					'format' => array(
						'type'   => 'json_schema',
						'name'   => 'media_description',
						'schema' => Prompt::schema(),
						'strict' => true,
					),
				),
			),
		);
	}

	protected function build_ping_request(): array {
		return array(
			'url'     => self::ENDPOINT,
			'headers' => $this->headers(),
			'body'    => array(
				'model'             => $this->model,
				'input'             => 'Reply with the word OK.',
				'max_output_tokens' => 16,
			),
		);
	}

	protected function extract_text( array $data ) {
		if ( 'incomplete' === ( $data['status'] ?? '' ) ) {
			return new \WP_Error( 'bad_response', __( 'The reply was cut off before it finished.', 'ai-media-search' ) );
		}
		foreach ( (array) ( $data['output'] ?? array() ) as $item ) {
			if ( 'message' !== ( $item['type'] ?? '' ) ) {
				continue;
			}
			foreach ( (array) ( $item['content'] ?? array() ) as $part ) {
				$type = $part['type'] ?? '';
				if ( 'refusal' === $type ) {
					return new \WP_Error( 'refused', __( 'The model declined to describe this image.', 'ai-media-search' ) );
				}
				if ( 'output_text' === $type && isset( $part['text'] ) ) {
					return (string) $part['text'];
				}
			}
		}
		return new \WP_Error( 'bad_response', __( 'The reply contained no text.', 'ai-media-search' ) );
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter OpenAIProviderTest`
Expected: OK (6 tests).

- [ ] **Step 5: Commit**

```bash
git add includes/providers/class-openai-provider.php tests/unit/OpenAIProviderTest.php
git -c commit.gpgsign=false commit -m "Add OpenAI provider"
```

---

### Task 6: Gemini provider

**Files:**
- Create: `includes/providers/class-gemini-provider.php`
- Test: `tests/unit/GeminiProviderTest.php`

**Interfaces:**
- Consumes: `Abstract_Provider`, `Prompt`.
- Produces: `AIMS\Providers\Gemini_Provider` with id `gemini`.

- [ ] **Step 1: Write the failing test**

`tests/unit/GeminiProviderTest.php`:

```php
<?php
namespace AIMS\Tests;

use AIMS\Description_Result;
use AIMS\Providers\Gemini_Provider;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class GeminiProviderTest extends TestCase {
	private $file;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( function ( $r ) { return $r['response']['code']; } );
		Functions\when( 'wp_remote_retrieve_body' )->alias( function ( $r ) { return $r['body']; } );
		$this->file = tempnam( sys_get_temp_dir(), 'aims' );
		file_put_contents( $this->file, 'webpbytes' );
	}

	protected function tearDown(): void {
		unlink( $this->file );
		Monkey\tearDown();
		parent::tearDown();
	}

	private function response( int $code, array $body ): array {
		return array( 'response' => array( 'code' => $code ), 'body' => json_encode( $body ) );
	}

	public function test_builds_request_per_spec_and_parses_success() {
		$captured = null;
		Functions\expect( 'wp_remote_post' )->once()->andReturnUsing(
			function ( $url, $args ) use ( &$captured ) {
				$captured = array( 'url' => $url, 'args' => $args );
				return $this->response( 200, array(
					'candidates' => array(
						array(
							'finishReason' => 'STOP',
							'content'      => array( 'parts' => array( array( 'text' => '{"description":"A dog.","tags":["dog"],"alt":"A dog."}' ) ) ),
						),
					),
				) );
			}
		);

		$result = ( new Gemini_Provider( 'g-key', 'gemini-3.8-flash' ) )->describe( $this->file, 'image/webp', 'INSTR' );
		$this->assertInstanceOf( Description_Result::class, $result );

		$this->assertSame( 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.8-flash:generateContent', $captured['url'] );
		$this->assertSame( 'g-key', $captured['args']['headers']['x-goog-api-key'] );

		$body = json_decode( $captured['args']['body'], true );
		$this->assertSame( 'INSTR', $body['systemInstruction']['parts'][0]['text'] );
		$this->assertSame( 'image/webp', $body['contents'][0]['parts'][0]['inline_data']['mime_type'] );
		$this->assertSame( base64_encode( 'webpbytes' ), $body['contents'][0]['parts'][0]['inline_data']['data'] );
		$this->assertSame( 'Describe this image.', $body['contents'][0]['parts'][1]['text'] );
		$this->assertSame( 'application/json', $body['generationConfig']['responseMimeType'] );
		$this->assertSame( 'object', $body['generationConfig']['responseSchema']['type'] );
	}

	public function test_safety_finish_reason_is_refused() {
		$body = array( 'candidates' => array( array( 'finishReason' => 'SAFETY', 'content' => array( 'parts' => array() ) ) ) );
		Functions\when( 'wp_remote_post' )->justReturn( $this->response( 200, $body ) );
		$result = ( new Gemini_Provider( 'k', 'm' ) )->describe( $this->file, 'image/webp', 'i' );
		$this->assertSame( 'refused', $result->get_error_code() );
	}

	public function test_prompt_block_is_refused() {
		$body = array( 'promptFeedback' => array( 'blockReason' => 'SAFETY' ) );
		Functions\when( 'wp_remote_post' )->justReturn( $this->response( 200, $body ) );
		$result = ( new Gemini_Provider( 'k', 'm' ) )->describe( $this->file, 'image/webp', 'i' );
		$this->assertSame( 'refused', $result->get_error_code() );
	}

	public function test_http_429_maps_to_rate_limited() {
		Functions\when( 'wp_remote_post' )->justReturn( $this->response( 429, array( 'error' => array( 'message' => 'quota' ) ) ) );
		$result = ( new Gemini_Provider( 'k', 'm' ) )->describe( $this->file, 'image/webp', 'i' );
		$this->assertSame( 'rate_limited', $result->get_error_code() );
		$this->assertSame( 'quota', $result->get_error_message() );
	}

	public function test_model_id_is_url_encoded() {
		$captured = null;
		Functions\expect( 'wp_remote_post' )->once()->andReturnUsing(
			function ( $url ) use ( &$captured ) {
				$captured = $url;
				return $this->response( 200, array( 'candidates' => array( array( 'finishReason' => 'STOP', 'content' => array( 'parts' => array( array( 'text' => 'OK' ) ) ) ) ) ) );
			}
		);
		$this->assertTrue( ( new Gemini_Provider( 'k', 'weird model' ) )->test_connection() );
		$this->assertStringContainsString( 'models/weird%20model:generateContent', $captured );
	}

	public function test_known_models() {
		$this->assertArrayHasKey( 'gemini-3.8-flash', Gemini_Provider::get_known_models() );
		$this->assertSame( 'gemini', Gemini_Provider::get_id() );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter GeminiProviderTest`
Expected: Error, class not found.

- [ ] **Step 3: Write the Gemini provider**

`includes/providers/class-gemini-provider.php`:

```php
<?php
/**
 * Google Gemini generateContent provider.
 *
 * @package AIMS
 */

namespace AIMS\Providers;

use AIMS\Prompt;

defined( 'ABSPATH' ) || exit;

final class Gemini_Provider extends Abstract_Provider {
	const ENDPOINT_BASE = 'https://generativelanguage.googleapis.com/v1beta/models/';

	public static function get_id(): string {
		return 'gemini';
	}

	public static function get_label(): string {
		return __( 'Google Gemini', 'ai-media-search' );
	}

	public static function get_known_models(): array {
		return array(
			'gemini-3.8-flash'      => __( 'Latest Flash, about $0.002 per image', 'ai-media-search' ),
			'gemini-3.5-flash-lite' => __( 'Flash-Lite, about $0.001 per image', 'ai-media-search' ),
			'gemini-2.5-flash'      => __( 'Previous Flash, about $0.001 per image', 'ai-media-search' ),
			'gemini-2.5-flash-lite' => __( 'Cheapest, under $0.001 per image', 'ai-media-search' ),
			'gemini-2.5-pro'        => __( 'Pro, about $0.005 per image', 'ai-media-search' ),
		);
	}

	private function url(): string {
		return self::ENDPOINT_BASE . rawurlencode( $this->model ) . ':generateContent';
	}

	private function headers(): array {
		return array(
			'x-goog-api-key' => $this->api_key,
			'Content-Type'   => 'application/json',
		);
	}

	protected function build_request( string $b64, string $mime, string $instructions ): array {
		return array(
			'url'     => $this->url(),
			'headers' => $this->headers(),
			'body'    => array(
				'systemInstruction' => array(
					'parts' => array( array( 'text' => $instructions ) ),
				),
				'contents'          => array(
					array(
						'parts' => array(
							array(
								'inline_data' => array(
									'mime_type' => $mime,
									'data'      => $b64,
								),
							),
							array( 'text' => Prompt::user_text() ),
						),
					),
				),
				'generationConfig'  => array(
					'responseMimeType' => 'application/json',
					'responseSchema'   => Prompt::schema(),
				),
			),
		);
	}

	protected function build_ping_request(): array {
		return array(
			'url'     => $this->url(),
			'headers' => $this->headers(),
			'body'    => array(
				'contents'         => array(
					array( 'parts' => array( array( 'text' => 'Reply with the word OK.' ) ) ),
				),
				'generationConfig' => array( 'maxOutputTokens' => 16 ),
			),
		);
	}

	protected function extract_text( array $data ) {
		if ( empty( $data['candidates'] ) ) {
			if ( ! empty( $data['promptFeedback']['blockReason'] ) ) {
				return new \WP_Error( 'refused', __( 'The model declined to describe this image.', 'ai-media-search' ) );
			}
			return new \WP_Error( 'bad_response', __( 'The reply contained no candidates.', 'ai-media-search' ) );
		}

		$candidate = $data['candidates'][0];
		if ( 'SAFETY' === ( $candidate['finishReason'] ?? '' ) ) {
			return new \WP_Error( 'refused', __( 'The model declined to describe this image.', 'ai-media-search' ) );
		}

		foreach ( (array) ( $candidate['content']['parts'] ?? array() ) as $part ) {
			if ( isset( $part['text'] ) && is_string( $part['text'] ) ) {
				return $part['text'];
			}
		}
		return new \WP_Error( 'bad_response', __( 'The reply contained no text.', 'ai-media-search' ) );
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter GeminiProviderTest`
Expected: OK (6 tests).

- [ ] **Step 5: Run the whole suite**

Run: `vendor/bin/phpunit`
Expected: all green. `RegistryTest::test_ids_and_labels` still passes and `Registry::labels()` now returns three real labels.

- [ ] **Step 6: Commit**

```bash
git add includes/providers/class-gemini-provider.php tests/unit/GeminiProviderTest.php
git -c commit.gpgsign=false commit -m "Add Gemini provider"
```

---

### Task 7: Image preparer

**Files:**
- Create: `includes/class-image-preparer.php`
- Test: `tests/unit/ImagePreparerTest.php`

**Interfaces:**
- Produces: `AIMS\Image_Preparer` with `const IMAGE_MIMES`, `const PDF_MIME = 'application/pdf'`, `const MIN_EDGE = 1000`, `const MAX_EDGE = 1600`, static `is_eligible_mime( string $mime ): bool`, static `choose_size( array $metadata ): ?string` (pure; returns a size key, `'full'`, or `null` meaning "must resize"), instance `prepare( int $attachment_id )` returning `array( 'path' => string, 'mime' => string, 'temporary' => bool )` or `WP_Error` with code `unsupported`, `no_preview`, `no_file`, or `resize_failed`; instance `cleanup( array $prepared ): void`.

- [ ] **Step 1: Write the failing test**

`tests/unit/ImagePreparerTest.php`:

```php
<?php
namespace AIMS\Tests;

use AIMS\Image_Preparer;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class ImagePreparerTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'path_join' )->alias( function ( $a, $b ) { return rtrim( $a, '/' ) . '/' . $b; } );
		Functions\when( 'get_temp_dir' )->justReturn( sys_get_temp_dir() . '/' );
		Functions\when( 'wp_rand' )->justReturn( 1234 );
		Functions\when( 'wp_delete_file' )->alias( 'unlink' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_eligible_mimes() {
		$this->assertTrue( Image_Preparer::is_eligible_mime( 'image/jpeg' ) );
		$this->assertTrue( Image_Preparer::is_eligible_mime( 'image/webp' ) );
		$this->assertTrue( Image_Preparer::is_eligible_mime( 'application/pdf' ) );
		$this->assertFalse( Image_Preparer::is_eligible_mime( 'image/svg+xml' ) );
		$this->assertFalse( Image_Preparer::is_eligible_mime( 'video/mp4' ) );
	}

	public function test_choose_size_prefers_smallest_size_over_min_edge() {
		$meta = array(
			'width'  => 4000,
			'height' => 3000,
			'sizes'  => array(
				'thumbnail'    => array( 'width' => 150, 'height' => 150 ),
				'medium'       => array( 'width' => 300, 'height' => 225 ),
				'medium_large' => array( 'width' => 768, 'height' => 576 ),
				'large'        => array( 'width' => 1024, 'height' => 768 ),
				'1536x1536'    => array( 'width' => 1536, 'height' => 1152 ),
			),
		);
		$this->assertSame( 'large', Image_Preparer::choose_size( $meta ) );
	}

	public function test_choose_size_uses_full_when_original_is_small_enough() {
		$meta = array( 'width' => 1200, 'height' => 800, 'sizes' => array( 'thumbnail' => array( 'width' => 150, 'height' => 150 ) ) );
		$this->assertSame( 'full', Image_Preparer::choose_size( $meta ) );
	}

	public function test_choose_size_returns_null_when_resize_needed() {
		$meta = array( 'width' => 5000, 'height' => 5000, 'sizes' => array( 'thumbnail' => array( 'width' => 150, 'height' => 150 ) ) );
		$this->assertNull( Image_Preparer::choose_size( $meta ) );
	}

	public function test_choose_size_small_image_with_no_sizes_uses_full() {
		$this->assertSame( 'full', Image_Preparer::choose_size( array( 'width' => 300, 'height' => 200 ) ) );
	}

	public function test_prepare_rejects_unsupported_mime() {
		Functions\when( 'get_post_mime_type' )->justReturn( 'video/mp4' );
		$result = ( new Image_Preparer() )->prepare( 5 );
		$this->assertSame( 'unsupported', $result->get_error_code() );
	}

	public function test_prepare_image_returns_chosen_size_path() {
		Functions\when( 'get_post_mime_type' )->justReturn( 'image/jpeg' );
		Functions\when( 'get_attached_file' )->justReturn( '/uploads/2026/09/photo.jpg' );
		Functions\when( 'wp_get_attachment_metadata' )->justReturn(
			array(
				'width'  => 4000,
				'height' => 3000,
				'sizes'  => array( 'large' => array( 'file' => 'photo-1024x768.jpg', 'width' => 1024, 'height' => 768, 'mime-type' => 'image/jpeg' ) ),
			)
		);
		$result = ( new Image_Preparer() )->prepare( 5 );
		$this->assertSame( '/uploads/2026/09/photo-1024x768.jpg', $result['path'] );
		$this->assertSame( 'image/jpeg', $result['mime'] );
		$this->assertFalse( $result['temporary'] );
	}

	public function test_prepare_pdf_uses_generated_preview() {
		Functions\when( 'get_post_mime_type' )->justReturn( 'application/pdf' );
		Functions\when( 'get_attached_file' )->justReturn( '/uploads/2026/09/brochure.pdf' );
		Functions\when( 'wp_get_attachment_metadata' )->justReturn(
			array(
				'sizes' => array(
					'full'  => array( 'file' => 'brochure-pdf.jpg', 'width' => 1058, 'height' => 1497, 'mime-type' => 'image/jpeg' ),
					'large' => array( 'file' => 'brochure-pdf-724x1024.jpg', 'width' => 724, 'height' => 1024, 'mime-type' => 'image/jpeg' ),
				),
			)
		);
		$result = ( new Image_Preparer() )->prepare( 7 );
		$this->assertSame( '/uploads/2026/09/brochure-pdf-724x1024.jpg', $result['path'] );
		$this->assertSame( 'image/jpeg', $result['mime'] );
	}

	public function test_prepare_pdf_without_preview_is_no_preview() {
		Functions\when( 'get_post_mime_type' )->justReturn( 'application/pdf' );
		Functions\when( 'get_attached_file' )->justReturn( '/uploads/2026/09/brochure.pdf' );
		Functions\when( 'wp_get_attachment_metadata' )->justReturn( array() );
		$result = ( new Image_Preparer() )->prepare( 7 );
		$this->assertSame( 'no_preview', $result->get_error_code() );
	}

	public function test_prepare_resizes_when_no_size_fits() {
		Functions\when( 'get_post_mime_type' )->justReturn( 'image/png' );
		Functions\when( 'get_attached_file' )->justReturn( '/uploads/big.png' );
		Functions\when( 'wp_get_attachment_metadata' )->justReturn( array( 'width' => 6000, 'height' => 4000, 'sizes' => array() ) );

		$editor = new class() {
			public $resized;
			public function resize( $w, $h, $crop ) { $this->resized = array( $w, $h, $crop ); return true; }
			public function save( $dest ) { return array( 'path' => $dest, 'mime-type' => 'image/png' ); }
		};
		Functions\when( 'wp_get_image_editor' )->justReturn( $editor );

		$result = ( new Image_Preparer() )->prepare( 9 );
		$this->assertTrue( $result['temporary'] );
		$this->assertSame( 'image/png', $result['mime'] );
		$this->assertStringContainsString( 'aims-9', $result['path'] );
		$this->assertSame( array( 1600, 1600, false ), $editor->resized );
	}

	public function test_prepare_resize_failure() {
		Functions\when( 'get_post_mime_type' )->justReturn( 'image/png' );
		Functions\when( 'get_attached_file' )->justReturn( '/uploads/big.png' );
		Functions\when( 'wp_get_attachment_metadata' )->justReturn( array( 'width' => 6000, 'height' => 4000 ) );
		Functions\when( 'wp_get_image_editor' )->justReturn( new \WP_Error( 'image_no_editor', 'no editor' ) );
		$result = ( new Image_Preparer() )->prepare( 9 );
		$this->assertSame( 'resize_failed', $result->get_error_code() );
	}

	public function test_cleanup_removes_only_temporary_files() {
		$tmp = tempnam( sys_get_temp_dir(), 'aims' );
		( new Image_Preparer() )->cleanup( array( 'path' => $tmp, 'mime' => 'image/png', 'temporary' => true ) );
		$this->assertFileDoesNotExist( $tmp );

		$keep = tempnam( sys_get_temp_dir(), 'aims' );
		( new Image_Preparer() )->cleanup( array( 'path' => $keep, 'mime' => 'image/png', 'temporary' => false ) );
		$this->assertFileExists( $keep );
		unlink( $keep );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter ImagePreparerTest`
Expected: Error, class not found.

- [ ] **Step 3: Write the Image_Preparer class**

`includes/class-image-preparer.php`:

```php
<?php
/**
 * Picks (or produces) a reasonably sized image file to send to a provider.
 *
 * @package AIMS
 */

namespace AIMS;

defined( 'ABSPATH' ) || exit;

// Not final: the indexer tests replace it with a PHPUnit mock.
class Image_Preparer {
	const IMAGE_MIMES = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );
	const PDF_MIME    = 'application/pdf';
	const MIN_EDGE    = 1000;
	const MAX_EDGE    = 1600;

	public static function is_eligible_mime( string $mime ): bool {
		return self::PDF_MIME === $mime || in_array( $mime, self::IMAGE_MIMES, true );
	}

	/**
	 * Pure size selection.
	 *
	 * Returns the key of the smallest registered size whose long edge is at least
	 * MIN_EDGE, or 'full' when the original fits within MAX_EDGE, or null when a
	 * resize is needed.
	 */
	public static function choose_size( array $metadata ) {
		$best_key  = null;
		$best_edge = PHP_INT_MAX;

		foreach ( (array) ( $metadata['sizes'] ?? array() ) as $key => $size ) {
			$edge = max( (int) ( $size['width'] ?? 0 ), (int) ( $size['height'] ?? 0 ) );
			if ( $edge >= self::MIN_EDGE && $edge < $best_edge ) {
				$best_key  = (string) $key;
				$best_edge = $edge;
			}
		}
		if ( null !== $best_key ) {
			return $best_key;
		}

		$full_edge = max( (int) ( $metadata['width'] ?? 0 ), (int) ( $metadata['height'] ?? 0 ) );
		if ( $full_edge > 0 && $full_edge <= self::MAX_EDGE ) {
			return 'full';
		}
		return null;
	}

	/**
	 * @return array{path:string,mime:string,temporary:bool}|\WP_Error
	 */
	public function prepare( int $attachment_id ) {
		$mime = (string) get_post_mime_type( $attachment_id );
		if ( ! self::is_eligible_mime( $mime ) ) {
			return new \WP_Error( 'unsupported', __( 'Only images and PDFs can be described.', 'ai-media-search' ) );
		}

		$original = (string) get_attached_file( $attachment_id );
		if ( '' === $original ) {
			return new \WP_Error( 'no_file', __( 'The attachment has no file.', 'ai-media-search' ) );
		}
		$metadata = wp_get_attachment_metadata( $attachment_id );
		$metadata = is_array( $metadata ) ? $metadata : array();
		$dir      = dirname( $original );

		if ( self::PDF_MIME === $mime ) {
			// WordPress renders PDF previews into metadata['sizes'] when Imagick is available.
			if ( empty( $metadata['sizes'] ) ) {
				return new \WP_Error( 'no_preview', __( 'WordPress did not generate a preview image for this PDF.', 'ai-media-search' ) );
			}
			$key = self::choose_size( array( 'sizes' => $metadata['sizes'] ) );
			if ( null === $key || 'full' === $key ) {
				// Fall back to whichever preview size is largest.
				$key = self::largest_size_key( $metadata['sizes'] );
			}
			$size = $metadata['sizes'][ $key ];
			return array(
				'path'      => path_join( $dir, (string) $size['file'] ),
				'mime'      => (string) ( $size['mime-type'] ?? 'image/jpeg' ),
				'temporary' => false,
			);
		}

		$key = self::choose_size( $metadata );
		if ( 'full' === $key ) {
			return array( 'path' => $original, 'mime' => $mime, 'temporary' => false );
		}
		if ( null !== $key && isset( $metadata['sizes'][ $key ]['file'] ) ) {
			$size = $metadata['sizes'][ $key ];
			return array(
				'path'      => path_join( $dir, (string) $size['file'] ),
				'mime'      => (string) ( $size['mime-type'] ?? $mime ),
				'temporary' => false,
			);
		}

		return $this->resize( $attachment_id, $original, $mime );
	}

	/**
	 * @return array{path:string,mime:string,temporary:bool}|\WP_Error
	 */
	private function resize( int $attachment_id, string $original, string $mime ) {
		$editor = wp_get_image_editor( $original );
		if ( is_wp_error( $editor ) ) {
			return new \WP_Error( 'resize_failed', $editor->get_error_message() );
		}
		$resized = $editor->resize( self::MAX_EDGE, self::MAX_EDGE, false );
		if ( is_wp_error( $resized ) ) {
			return new \WP_Error( 'resize_failed', $resized->get_error_message() );
		}
		$ext  = pathinfo( $original, PATHINFO_EXTENSION );
		$dest = get_temp_dir() . 'aims-' . $attachment_id . '-' . wp_rand( 1000, 9999 ) . '.' . $ext;
		$saved = $editor->save( $dest );
		if ( is_wp_error( $saved ) || empty( $saved['path'] ) ) {
			return new \WP_Error( 'resize_failed', __( 'Could not save the resized copy.', 'ai-media-search' ) );
		}
		return array(
			'path'      => (string) $saved['path'],
			'mime'      => (string) ( $saved['mime-type'] ?? $mime ),
			'temporary' => true,
		);
	}

	public function cleanup( array $prepared ): void {
		if ( ! empty( $prepared['temporary'] ) && ! empty( $prepared['path'] ) && file_exists( $prepared['path'] ) ) {
			wp_delete_file( $prepared['path'] );
		}
	}

	private static function largest_size_key( array $sizes ): string {
		$best_key  = (string) array_key_first( $sizes );
		$best_edge = 0;
		foreach ( $sizes as $key => $size ) {
			$edge = max( (int) ( $size['width'] ?? 0 ), (int) ( $size['height'] ?? 0 ) );
			if ( $edge > $best_edge ) {
				$best_edge = $edge;
				$best_key  = (string) $key;
			}
		}
		return $best_key;
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter ImagePreparerTest`
Expected: OK (12 tests).

- [ ] **Step 5: Commit**

```bash
git add includes/class-image-preparer.php tests/unit/ImagePreparerTest.php
git -c commit.gpgsign=false commit -m "Add image preparer with size selection and resize fallback"
```

---

### Task 8: Indexer

**Files:**
- Create: `includes/class-indexer.php`
- Test: `tests/unit/IndexerTest.php`

**Interfaces:**
- Consumes: `Image_Preparer::prepare()/cleanup()`, `Providers\Registry::active()`, `Provider_Interface::describe()`, `Prompt::instructions()`, `Settings::get()`, `Description_Result`.
- Produces: `AIMS\Indexer` with status constants `STATUS_PENDING`, `STATUS_INDEXED`, `STATUS_FAILED`, `STATUS_SKIPPED`; meta key constants `META_DESCRIPTION = '_aims_description'`, `META_TAGS = '_aims_tags'`, `META_ALT = '_aims_alt'`, `META_SEARCH = '_aims_search_text'`, `META_STATUS = '_aims_status'`, `META_ERROR = '_aims_error'`, `META_INDEXED_AT = '_aims_indexed_at'`, `META_PROVIDER = '_aims_provider'`, `META_RETRY = '_aims_retry_count'`; constructor `( ?Image_Preparer $preparer = null, ?callable $provider_factory = null )`; `index_attachment( int $id )` returning `true|WP_Error`; static `build_search_text( string $description, array $tags, string $alt ): string`; static `rebuild_search_text( int $id ): void`; static `payload( int $id ): array` with keys `id, description, tags, alt, status, error, indexed_at, provider`.

- [ ] **Step 1: Write the failing test**

`tests/unit/IndexerTest.php`:

```php
<?php
namespace AIMS\Tests;

use AIMS\Description_Result;
use AIMS\Image_Preparer;
use AIMS\Indexer;
use AIMS\Providers\Provider_Interface;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class IndexerTest extends TestCase {
	/** @var array<int,array<string,mixed>> */
	private $meta = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'get_option' )->justReturn( array( 'provider' => 'claude', 'language' => 'English', 'custom_prompt' => '', 'fill_alt' => false ) );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'get_post_mime_type' )->justReturn( 'image/jpeg' );

		$meta = &$this->meta;
		Functions\when( 'update_post_meta' )->alias( function ( $id, $key, $value ) use ( &$meta ) { $meta[ $id ][ $key ] = $value; return true; } );
		Functions\when( 'delete_post_meta' )->alias( function ( $id, $key ) use ( &$meta ) { unset( $meta[ $id ][ $key ] ); return true; } );
		Functions\when( 'get_post_meta' )->alias( function ( $id, $key, $single ) use ( &$meta ) { return $meta[ $id ][ $key ] ?? ''; } );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function preparer( $return ): Image_Preparer {
		$p = $this->createMock( Image_Preparer::class );
		$p->method( 'prepare' )->willReturn( $return );
		return $p;
	}

	/**
	 * PHPUnit cannot configure static interface methods, so use a real class.
	 * The returned object exposes a public $calls counter.
	 */
	private function provider( $return ): Provider_Interface {
		return new class( $return ) implements Provider_Interface {
			public $calls = 0;
			private $return;
			public function __construct( $return ) { $this->return = $return; }
			public function describe( string $file_path, string $mime_type, string $instructions ) { ++$this->calls; return $this->return; }
			public function test_connection() { return true; }
			public static function get_id(): string { return 'claude'; }
			public static function get_label(): string { return 'Claude'; }
			public static function get_known_models(): array { return array( 'claude-opus-5' => 'x' ); }
		};
	}

	public function test_success_writes_all_meta() {
		$prepared = array( 'path' => '/tmp/x.jpg', 'mime' => 'image/jpeg', 'temporary' => false );
		$result   = new Description_Result( 'A Woman on a beach.', array( 'woman', 'beach' ), 'Woman on a beach.' );
		$provider = $this->provider( $result );
		Functions\when( 'get_option' )->justReturn( array( 'provider' => 'claude', 'models' => array( 'claude' => 'claude-opus-5' ), 'language' => 'English', 'custom_prompt' => '', 'fill_alt' => false ) );

		$indexer = new Indexer( $this->preparer( $prepared ), function () use ( $provider ) { return $provider; } );
		$before  = time();
		$this->assertTrue( $indexer->index_attachment( 42 ) );

		$m = $this->meta[42];
		$this->assertSame( 'A Woman on a beach.', $m['_aims_description'] );
		$this->assertSame( array( 'woman', 'beach' ), $m['_aims_tags'] );
		$this->assertSame( 'Woman on a beach.', $m['_aims_alt'] );
		$this->assertSame( 'a woman on a beach. woman beach woman on a beach.', $m['_aims_search_text'] );
		$this->assertSame( 'indexed', $m['_aims_status'] );
		$this->assertGreaterThanOrEqual( $before, $m['_aims_indexed_at'] );
		$this->assertSame( 'claude:claude-opus-5', $m['_aims_provider'] );
		$this->assertArrayNotHasKey( '_aims_error', $m );
		$this->assertArrayNotHasKey( '_wp_attachment_image_alt', $m, 'fill_alt is off' );
	}

	public function test_fill_alt_only_when_empty_and_enabled() {
		Functions\when( 'get_option' )->justReturn( array( 'provider' => 'claude', 'language' => 'English', 'custom_prompt' => '', 'fill_alt' => true ) );
		$prepared = array( 'path' => '/tmp/x.jpg', 'mime' => 'image/jpeg', 'temporary' => false );
		$provider = $this->provider( new Description_Result( 'd', array( 't' ), 'Generated alt' ) );
		$indexer  = new Indexer( $this->preparer( $prepared ), function () use ( $provider ) { return $provider; } );

		$indexer->index_attachment( 1 );
		$this->assertSame( 'Generated alt', $this->meta[1]['_wp_attachment_image_alt'] );

		$this->meta[2]['_wp_attachment_image_alt'] = 'Existing';
		$indexer->index_attachment( 2 );
		$this->assertSame( 'Existing', $this->meta[2]['_wp_attachment_image_alt'] );
	}

	public function test_unsupported_and_no_preview_mark_skipped_without_calling_provider() {
		foreach ( array( 'unsupported', 'no_preview' ) as $code ) {
			$provider = $this->provider( null );
			$indexer  = new Indexer( $this->preparer( new \WP_Error( $code, 'nope' ) ), function () use ( $provider ) { return $provider; } );
			$result   = $indexer->index_attachment( 3 );
			$this->assertTrue( $result );
			$this->assertSame( 'skipped', $this->meta[3]['_aims_status'] );
			$this->assertSame( 'nope', $this->meta[3]['_aims_error'] );
			$this->assertSame( 0, $provider->calls );
		}
	}

	public function test_provider_error_marks_failed_and_returns_error() {
		$prepared = array( 'path' => '/tmp/x.jpg', 'mime' => 'image/jpeg', 'temporary' => false );
		$provider = $this->provider( new \WP_Error( 'rate_limited', 'slow down' ) );
		$indexer  = new Indexer( $this->preparer( $prepared ), function () use ( $provider ) { return $provider; } );

		$result = $indexer->index_attachment( 4 );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rate_limited', $result->get_error_code() );
		$this->assertSame( 'failed', $this->meta[4]['_aims_status'] );
		$this->assertSame( 'slow down', $this->meta[4]['_aims_error'] );
	}

	public function test_lock_prevents_double_processing() {
		Functions\when( 'get_transient' )->justReturn( 1 );
		$provider = $this->provider( null );
		$indexer  = new Indexer( $this->preparer( array() ), function () use ( $provider ) { return $provider; } );
		$result   = $indexer->index_attachment( 5 );
		$this->assertSame( 'locked', $result->get_error_code() );
		$this->assertSame( 0, $provider->calls );
	}

	public function test_temporary_file_is_cleaned_up() {
		$preparer = $this->createMock( Image_Preparer::class );
		$preparer->method( 'prepare' )->willReturn( array( 'path' => '/tmp/t.jpg', 'mime' => 'image/jpeg', 'temporary' => true ) );
		$preparer->expects( $this->once() )->method( 'cleanup' );
		$provider = $this->provider( new Description_Result( 'd', array(), 'a' ) );
		( new Indexer( $preparer, function () use ( $provider ) { return $provider; } ) )->index_attachment( 6 );
	}

	public function test_build_search_text_is_lowercase_and_deduplicated_whitespace() {
		$this->assertSame( 'a red car. car red vehicle red car', Indexer::build_search_text( "A Red  Car.\n", array( 'car', 'Red', 'vehicle' ), ' Red Car ' ) );
	}

	public function test_payload_reads_meta() {
		$this->meta[8] = array( '_aims_description' => 'd', '_aims_tags' => array( 'x' ), '_aims_status' => 'indexed', '_aims_indexed_at' => 5 );
		$p = Indexer::payload( 8 );
		$this->assertSame( 8, $p['id'] );
		$this->assertSame( 'd', $p['description'] );
		$this->assertSame( array( 'x' ), $p['tags'] );
		$this->assertSame( 'indexed', $p['status'] );
		$this->assertSame( '', $p['error'] );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter IndexerTest`
Expected: Error, class not found.

- [ ] **Step 3: Write the Indexer class**

`includes/class-indexer.php`:

```php
<?php
/**
 * Runs prepare -> provider -> meta for one attachment.
 *
 * @package AIMS
 */

namespace AIMS;

use AIMS\Providers\Registry;

defined( 'ABSPATH' ) || exit;

// Not final: the queue tests replace it with a PHPUnit mock.
class Indexer {
	const STATUS_PENDING = 'pending';
	const STATUS_INDEXED = 'indexed';
	const STATUS_FAILED  = 'failed';
	const STATUS_SKIPPED = 'skipped';

	const META_DESCRIPTION = '_aims_description';
	const META_TAGS        = '_aims_tags';
	const META_ALT         = '_aims_alt';
	const META_SEARCH      = '_aims_search_text';
	const META_STATUS      = '_aims_status';
	const META_ERROR       = '_aims_error';
	const META_INDEXED_AT  = '_aims_indexed_at';
	const META_PROVIDER    = '_aims_provider';
	const META_RETRY       = '_aims_retry_count';

	const LOCK_TTL = 120;

	/** @var Image_Preparer */
	private $preparer;

	/** @var callable Returns Provider_Interface|WP_Error. */
	private $provider_factory;

	public function __construct( ?Image_Preparer $preparer = null, ?callable $provider_factory = null ) {
		$this->preparer         = $preparer ?: new Image_Preparer();
		$this->provider_factory = $provider_factory ?: array( Registry::class, 'active' );
	}

	/**
	 * @return true|\WP_Error
	 */
	public function index_attachment( int $id ) {
		$lock = 'aims_lock_' . $id;
		if ( get_transient( $lock ) ) {
			return new \WP_Error( 'locked', __( 'This file is already being processed.', 'ai-media-search' ) );
		}
		set_transient( $lock, 1, self::LOCK_TTL );

		try {
			return $this->run( $id );
		} finally {
			delete_transient( $lock );
			Stats_Cache::clear();
		}
	}

	/**
	 * @return true|\WP_Error
	 */
	private function run( int $id ) {
		update_post_meta( $id, self::META_STATUS, self::STATUS_PENDING );

		$prepared = $this->preparer->prepare( $id );
		if ( is_wp_error( $prepared ) ) {
			if ( in_array( $prepared->get_error_code(), array( 'unsupported', 'no_preview' ), true ) ) {
				$this->mark( $id, self::STATUS_SKIPPED, $prepared->get_error_message() );
				return true;
			}
			$this->mark( $id, self::STATUS_FAILED, $prepared->get_error_message() );
			return $prepared;
		}

		$provider = call_user_func( $this->provider_factory );
		if ( is_wp_error( $provider ) ) {
			$this->preparer->cleanup( $prepared );
			$this->mark( $id, self::STATUS_FAILED, $provider->get_error_message() );
			return $provider;
		}

		$instructions = Prompt::instructions( (string) Settings::get( 'language' ), (string) Settings::get( 'custom_prompt' ) );
		$result       = $provider->describe( $prepared['path'], $prepared['mime'], $instructions );
		$this->preparer->cleanup( $prepared );

		if ( is_wp_error( $result ) ) {
			$this->mark( $id, self::STATUS_FAILED, $result->get_error_message() );
			return $result;
		}

		update_post_meta( $id, self::META_DESCRIPTION, $result->description );
		update_post_meta( $id, self::META_TAGS, $result->tags );
		update_post_meta( $id, self::META_ALT, $result->alt );
		update_post_meta( $id, self::META_SEARCH, self::build_search_text( $result->description, $result->tags, $result->alt ) );
		update_post_meta( $id, self::META_INDEXED_AT, time() );
		update_post_meta( $id, self::META_PROVIDER, $provider::get_id() . ':' . Settings::get_model( $provider::get_id() ) );
		delete_post_meta( $id, self::META_ERROR );
		delete_post_meta( $id, self::META_RETRY );
		update_post_meta( $id, self::META_STATUS, self::STATUS_INDEXED );

		if ( Settings::get( 'fill_alt' ) && '' !== $result->alt ) {
			$existing = (string) get_post_meta( $id, '_wp_attachment_image_alt', true );
			if ( '' === trim( $existing ) ) {
				update_post_meta( $id, '_wp_attachment_image_alt', $result->alt );
			}
		}

		return true;
	}

	private function mark( int $id, string $status, string $error ): void {
		update_post_meta( $id, self::META_STATUS, $status );
		update_post_meta( $id, self::META_ERROR, $error );
	}

	public static function build_search_text( string $description, array $tags, string $alt ): string {
		$text = $description . ' ' . implode( ' ', $tags ) . ' ' . $alt;
		$text = strtolower( $text );
		$text = preg_replace( '/\s+/u', ' ', $text );
		return trim( (string) $text );
	}

	public static function rebuild_search_text( int $id ): void {
		$description = (string) get_post_meta( $id, self::META_DESCRIPTION, true );
		$tags        = get_post_meta( $id, self::META_TAGS, true );
		$alt         = (string) get_post_meta( $id, self::META_ALT, true );
		update_post_meta( $id, self::META_SEARCH, self::build_search_text( $description, is_array( $tags ) ? $tags : array(), $alt ) );
	}

	public static function payload( int $id ): array {
		$tags = get_post_meta( $id, self::META_TAGS, true );
		return array(
			'id'          => $id,
			'description' => (string) get_post_meta( $id, self::META_DESCRIPTION, true ),
			'tags'        => is_array( $tags ) ? array_values( $tags ) : array(),
			'alt'         => (string) get_post_meta( $id, self::META_ALT, true ),
			'status'      => (string) get_post_meta( $id, self::META_STATUS, true ),
			'error'       => (string) get_post_meta( $id, self::META_ERROR, true ),
			'indexed_at'  => (int) get_post_meta( $id, self::META_INDEXED_AT, true ),
			'provider'    => (string) get_post_meta( $id, self::META_PROVIDER, true ),
		);
	}
}
```

`Stats_Cache::clear()` is a one-line helper so the indexer does not depend on the full Stats class (Task 11). Create `includes/class-stats-cache.php` now:

```php
<?php
/**
 * Transient cache for dashboard counts.
 *
 * @package AIMS
 */

namespace AIMS;

defined( 'ABSPATH' ) || exit;

final class Stats_Cache {
	const KEY = 'aims_stats';
	const TTL = 60;

	public static function clear(): void {
		delete_transient( self::KEY );
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter IndexerTest`
Expected: OK (8 tests).

- [ ] **Step 5: Commit**

```bash
git add includes/class-indexer.php includes/class-stats-cache.php tests/unit/IndexerTest.php
git -c commit.gpgsign=false commit -m "Add indexer that stores AI descriptions in post meta"
```

---

### Task 9: Queue (cron on upload, retry once)

**Files:**
- Create: `includes/class-queue.php`
- Modify: `includes/class-plugin.php`
- Test: `tests/unit/QueueTest.php`

**Interfaces:**
- Consumes: `Indexer::index_attachment()`, `Indexer::META_RETRY`, `Image_Preparer::is_eligible_mime()`, `Settings::get( 'auto_index' )`.
- Produces: `AIMS\Queue` with `const HOOK = 'aims_index_attachment'`, `register()`, `on_add_attachment( int $id )`, static `schedule( int $id, int $delay = 10 ): bool`, `handle( int $id )`, static `clear_all()`.

- [ ] **Step 1: Write the failing test**

`tests/unit/QueueTest.php`:

```php
<?php
namespace AIMS\Tests;

use AIMS\Indexer;
use AIMS\Queue;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class QueueTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** PHP's time() cannot be stubbed, so match "at least now + delay". */
	private function at_least( int $delay ) {
		return \Mockery::on( function ( $timestamp ) use ( $delay ) { return $timestamp >= time() + $delay; } );
	}

	public function test_schedule_adds_single_event_once() {
		Functions\expect( 'wp_next_scheduled' )->once()->with( Queue::HOOK, array( 7 ) )->andReturn( false );
		Functions\expect( 'wp_schedule_single_event' )->once()->with( $this->at_least( 10 ), Queue::HOOK, array( 7 ) )->andReturn( true );
		$this->assertTrue( Queue::schedule( 7 ) );
	}

	public function test_schedule_skips_when_already_queued() {
		Functions\when( 'wp_next_scheduled' )->justReturn( 2000 );
		Functions\expect( 'wp_schedule_single_event' )->never();
		$this->assertFalse( Queue::schedule( 7 ) );
	}

	public function test_on_add_attachment_respects_setting_and_mime() {
		Functions\when( 'get_option' )->justReturn( array( 'auto_index' => false ) );
		Functions\expect( 'wp_schedule_single_event' )->never();
		( new Queue() )->on_add_attachment( 1 );

		Functions\when( 'get_option' )->justReturn( array( 'auto_index' => true ) );
		Functions\when( 'get_post_mime_type' )->justReturn( 'video/mp4' );
		( new Queue() )->on_add_attachment( 2 );
	}

	public function test_on_add_attachment_schedules_eligible_upload() {
		Functions\when( 'get_option' )->justReturn( array( 'auto_index' => true ) );
		Functions\when( 'get_post_mime_type' )->justReturn( 'image/png' );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\expect( 'wp_schedule_single_event' )->once()->with( $this->at_least( 10 ), Queue::HOOK, array( 3 ) );
		( new Queue() )->on_add_attachment( 3 );
	}

	public function test_handle_retries_once_on_retryable_error() {
		$indexer = $this->createMock( Indexer::class );
		$indexer->method( 'index_attachment' )->willReturn( new \WP_Error( 'rate_limited', 'x' ) );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\expect( 'update_post_meta' )->once()->with( 9, Indexer::META_RETRY, 1 );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\expect( 'wp_schedule_single_event' )->once()->with( $this->at_least( 300 ), Queue::HOOK, array( 9 ) );
		( new Queue( $indexer ) )->handle( 9 );
	}

	public function test_handle_does_not_retry_twice_or_on_non_retryable() {
		$indexer = $this->createMock( Indexer::class );
		$indexer->method( 'index_attachment' )->willReturn( new \WP_Error( 'rate_limited', 'x' ) );
		Functions\when( 'get_post_meta' )->justReturn( 1 );
		Functions\expect( 'wp_schedule_single_event' )->never();
		( new Queue( $indexer ) )->handle( 9 );

		$indexer2 = $this->createMock( Indexer::class );
		$indexer2->method( 'index_attachment' )->willReturn( new \WP_Error( 'auth_error', 'x' ) );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		( new Queue( $indexer2 ) )->handle( 10 );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter QueueTest`
Expected: Error, class not found.

- [ ] **Step 3: Write the Queue class**

`includes/class-queue.php`:

```php
<?php
/**
 * Schedules indexing on upload through WP-Cron.
 *
 * @package AIMS
 */

namespace AIMS;

defined( 'ABSPATH' ) || exit;

final class Queue {
	const HOOK        = 'aims_index_attachment';
	const DELAY       = 10;
	const RETRY_DELAY = 300;
	const RETRYABLE   = array( 'rate_limited', 'server_error' );

	/** @var Indexer */
	private $indexer;

	public function __construct( ?Indexer $indexer = null ) {
		$this->indexer = $indexer ?: new Indexer();
	}

	public function register(): void {
		add_action( 'add_attachment', array( $this, 'on_add_attachment' ) );
		add_action( self::HOOK, array( $this, 'handle' ) );
	}

	public function on_add_attachment( int $id ): void {
		if ( ! Settings::get( 'auto_index' ) ) {
			return;
		}
		if ( ! Image_Preparer::is_eligible_mime( (string) get_post_mime_type( $id ) ) ) {
			return;
		}
		self::schedule( $id );
	}

	public static function schedule( int $id, int $delay = self::DELAY ): bool {
		if ( wp_next_scheduled( self::HOOK, array( $id ) ) ) {
			return false;
		}
		return (bool) wp_schedule_single_event( time() + $delay, self::HOOK, array( $id ) );
	}

	public function handle( int $id ): void {
		$result = $this->indexer->index_attachment( $id );
		if ( ! is_wp_error( $result ) ) {
			return;
		}
		if ( ! in_array( $result->get_error_code(), self::RETRYABLE, true ) ) {
			return;
		}
		$retries = (int) get_post_meta( $id, Indexer::META_RETRY, true );
		if ( $retries >= 1 ) {
			return;
		}
		update_post_meta( $id, Indexer::META_RETRY, $retries + 1 );
		self::schedule( $id, self::RETRY_DELAY );
	}

	public static function clear_all(): void {
		wp_unschedule_hook( self::HOOK );
	}
}
```

- [ ] **Step 4: Register the queue in Plugin::init()**

In `includes/class-plugin.php`, after `( new Settings() )->register();` add:

```php
		( new Queue() )->register();
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter QueueTest`
Expected: OK (6 tests).

- [ ] **Step 6: Commit**

```bash
git add includes/class-queue.php includes/class-plugin.php tests/unit/QueueTest.php
git -c commit.gpgsign=false commit -m "Add cron queue for indexing new uploads"
```

---

### Task 10: Search filters

**Files:**
- Create: `includes/class-search.php`
- Modify: `includes/class-plugin.php`
- Test: `tests/unit/SearchTest.php`

**Interfaces:**
- Consumes: `Indexer::META_SEARCH`.
- Produces: `AIMS\Search` with `const ALIAS = 'aims_st'`, `register()`, `join( string $join, $query ): string`, `search( string $search, $query ): string`, static `applies( $query ): bool` (any object with a `get( string )` method), static `rewrite( string $search, string $posts_table ): string` (pure).

- [ ] **Step 1: Write the failing test**

`tests/unit/SearchTest.php`:

```php
<?php
namespace AIMS\Tests;

use AIMS\Search;
use Brain\Monkey;
use PHPUnit\Framework\TestCase;

class SearchTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$GLOBALS['wpdb'] = new class() {
			public $posts    = 'wp_posts';
			public $postmeta = 'wp_postmeta';
		};
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	private function query( $post_type, string $s ) {
		return new class( $post_type, $s ) {
			private $vars;
			public function __construct( $post_type, $s ) { $this->vars = array( 'post_type' => $post_type, 's' => $s ); }
			public function get( $key ) { return $this->vars[ $key ] ?? ''; }
		};
	}

	public function test_applies_only_to_attachment_searches() {
		$this->assertTrue( Search::applies( $this->query( 'attachment', 'woman' ) ) );
		$this->assertTrue( Search::applies( $this->query( array( 'attachment' ), 'woman' ) ) );
		$this->assertFalse( Search::applies( $this->query( 'attachment', '  ' ) ) );
		$this->assertFalse( Search::applies( $this->query( 'post', 'woman' ) ) );
		$this->assertFalse( Search::applies( $this->query( array( 'post', 'attachment' ), 'woman' ) ) );
	}

	public function test_rewrite_single_term() {
		$in  = " AND (((wp_posts.post_title LIKE '{a1b2}woman{a1b2}') OR (wp_posts.post_excerpt LIKE '{a1b2}woman{a1b2}') OR (wp_posts.post_content LIKE '{a1b2}woman{a1b2}')))";
		$out = Search::rewrite( $in, 'wp_posts' );
		$this->assertStringContainsString( "(wp_posts.post_title LIKE '{a1b2}woman{a1b2}' OR aims_st.meta_value LIKE '{a1b2}woman{a1b2}')", $out );
		$this->assertSame( 1, substr_count( $out, 'aims_st.meta_value' ) );
	}

	public function test_rewrite_multi_term_keeps_and_structure() {
		$in  = " AND (((wp_posts.post_title LIKE '{x}red{x}') OR (wp_posts.post_content LIKE '{x}red{x}')) AND ((wp_posts.post_title LIKE '{x}car{x}') OR (wp_posts.post_content LIKE '{x}car{x}')))";
		$out = Search::rewrite( $in, 'wp_posts' );
		$this->assertSame( 2, substr_count( $out, 'aims_st.meta_value' ) );
		$this->assertStringContainsString( "AND ((wp_posts.post_title LIKE '{x}car{x}' OR aims_st.meta_value LIKE '{x}car{x}')", $out );
	}

	public function test_rewrite_handles_escaped_quote_in_term() {
		$in  = " AND (((wp_posts.post_title LIKE '{x}o\\'neil{x}') OR (wp_posts.post_content LIKE '{x}o\\'neil{x}')))";
		$out = Search::rewrite( $in, 'wp_posts' );
		$this->assertStringContainsString( "OR aims_st.meta_value LIKE '{x}o\\'neil{x}')", $out );
	}

	public function test_join_added_once_and_only_for_attachment_search() {
		$search = new Search();
		$join   = $search->join( '', $this->query( 'attachment', 'x' ) );
		$this->assertStringContainsString( "LEFT JOIN wp_postmeta AS aims_st ON (wp_posts.ID = aims_st.post_id AND aims_st.meta_key = '_aims_search_text')", $join );
		$this->assertSame( $join, $search->join( $join, $this->query( 'attachment', 'x' ) ), 'no double join' );
		$this->assertSame( '', $search->join( '', $this->query( 'post', 'x' ) ) );
	}

	public function test_search_filter_untouched_for_other_queries() {
		$search = new Search();
		$sql    = " AND ((wp_posts.post_title LIKE '{x}a{x}'))";
		$this->assertSame( $sql, $search->search( $sql, $this->query( 'post', 'a' ) ) );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter SearchTest`
Expected: Error, class not found.

- [ ] **Step 3: Write the Search class**

`includes/class-search.php`:

```php
<?php
/**
 * Extends every attachment search to the AI search text meta.
 *
 * @package AIMS
 */

namespace AIMS;

defined( 'ABSPATH' ) || exit;

final class Search {
	const ALIAS = 'aims_st';

	public function register(): void {
		add_filter( 'posts_join', array( $this, 'join' ), 10, 2 );
		add_filter( 'posts_search', array( $this, 'search' ), 10, 2 );
	}

	/**
	 * @param object $query WP_Query (or anything with get()).
	 */
	public static function applies( $query ): bool {
		if ( ! is_object( $query ) || ! method_exists( $query, 'get' ) ) {
			return false;
		}
		$post_type = $query->get( 'post_type' );
		if ( is_array( $post_type ) ) {
			if ( 1 !== count( $post_type ) || 'attachment' !== reset( $post_type ) ) {
				return false;
			}
		} elseif ( 'attachment' !== $post_type ) {
			return false;
		}
		return '' !== trim( (string) $query->get( 's' ) );
	}

	/**
	 * @param string $join  Existing JOIN clause.
	 * @param object $query WP_Query.
	 */
	public function join( $join, $query ) {
		$join = (string) $join;
		if ( ! self::applies( $query ) || false !== strpos( $join, self::ALIAS ) ) {
			return $join;
		}
		global $wpdb;
		$join .= " LEFT JOIN {$wpdb->postmeta} AS " . self::ALIAS . " ON ({$wpdb->posts}.ID = " . self::ALIAS . '.post_id AND ' . self::ALIAS . ".meta_key = '" . Indexer::META_SEARCH . "')";
		return $join;
	}

	/**
	 * @param string $search Existing search SQL.
	 * @param object $query  WP_Query.
	 */
	public function search( $search, $query ) {
		$search = (string) $search;
		if ( ! self::applies( $query ) ) {
			return $search;
		}
		global $wpdb;
		return self::rewrite( $search, $wpdb->posts );
	}

	/**
	 * Turn every "(posts.post_title LIKE 'term')" clause WordPress generated into
	 * "(posts.post_title LIKE 'term' OR aims_st.meta_value LIKE 'term')".
	 */
	public static function rewrite( string $search, string $posts_table ): string {
		$table   = preg_quote( $posts_table, '/' );
		$literal = "'(?:[^'\\\\]|\\\\.)*'";
		$pattern = '/\((' . $table . '\.post_title LIKE (' . $literal . '))\)/';
		$result  = preg_replace( $pattern, '($1 OR ' . self::ALIAS . '.meta_value LIKE $2)', $search );
		return null === $result ? $search : $result;
	}
}
```

- [ ] **Step 4: Register in Plugin::init()**

Add after the Queue line in `includes/class-plugin.php`:

```php
		( new Search() )->register();
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter SearchTest`
Expected: OK (6 tests).

- [ ] **Step 6: Commit**

```bash
git add includes/class-search.php includes/class-plugin.php tests/unit/SearchTest.php
git -c commit.gpgsign=false commit -m "Extend media library search to AI descriptions"
```

---

### Task 11: Stats and REST routes

**Files:**
- Create: `includes/class-stats.php`, `includes/class-rest.php`
- Modify: `includes/class-plugin.php`
- Test: `tests/unit/StatsTest.php`, `tests/unit/RestTest.php`

**Interfaces:**
- Consumes: `Stats_Cache`, `Image_Preparer::IMAGE_MIMES`, `Image_Preparer::PDF_MIME`, `Indexer`, `Settings`, `Providers\Registry::active()`.
- Produces:
  - `AIMS\Stats` with static `counts(): array` (keys `total, indexed, not_indexed, failed, skipped`), static `mime_in_sql(): string` (a `('image/jpeg','image/png',...)` literal built with `$wpdb->prepare`), static `next_ids( int $limit, bool $retry_failed ): int[]`, static `remaining_count( bool $retry_failed ): int`, static `status_where( bool $retry_failed, string $alias ): string` (pure).
  - `AIMS\Rest` with `const NS = 'aims/v1'`, `register()`, route callbacks `index_one`, `bulk`, `stats`, `test`, permission callbacks `can_manage()` and `can_edit_attachment( $request )`, static `TIME_BUDGET = 20` seconds, and static `normalize_ids( $raw ): int[]` (pure).

- [ ] **Step 1: Write the failing tests**

`tests/unit/StatsTest.php`:

```php
<?php
namespace AIMS\Tests;

use AIMS\Stats;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class StatsTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		$GLOBALS['wpdb'] = new class() {
			public $posts    = 'wp_posts';
			public $postmeta = 'wp_postmeta';
			public $last_sql = '';
			public $rows     = array();
			public function prepare( $sql, ...$args ) {
				foreach ( $args as $a ) { $sql = preg_replace( '/%[sd]/', is_int( $a ) ? $a : "'" . $a . "'", $sql, 1 ); }
				return $sql;
			}
			public function get_results( $sql ) { $this->last_sql = $sql; return $this->rows; }
			public function get_col( $sql ) { $this->last_sql = $sql; return array( 3, 5 ); }
			public function get_var( $sql ) { $this->last_sql = $sql; return 7; }
		};
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_counts_groups_statuses() {
		$GLOBALS['wpdb']->rows = array(
			(object) array( 'status' => 'none', 'n' => '4' ),
			(object) array( 'status' => 'pending', 'n' => '1' ),
			(object) array( 'status' => 'indexed', 'n' => '10' ),
			(object) array( 'status' => 'failed', 'n' => '2' ),
			(object) array( 'status' => 'skipped', 'n' => '3' ),
		);
		$c = Stats::counts();
		$this->assertSame( 20, $c['total'] );
		$this->assertSame( 10, $c['indexed'] );
		$this->assertSame( 5, $c['not_indexed'] );
		$this->assertSame( 2, $c['failed'] );
		$this->assertSame( 3, $c['skipped'] );
		$this->assertStringContainsString( "post_mime_type IN ('image/jpeg'", $GLOBALS['wpdb']->last_sql );
	}

	public function test_status_where() {
		$this->assertSame( "(m.meta_value IS NULL OR m.meta_value = 'pending')", Stats::status_where( false, 'm' ) );
		$this->assertSame( "(m.meta_value IS NULL OR m.meta_value = 'pending' OR m.meta_value = 'failed')", Stats::status_where( true, 'm' ) );
	}

	public function test_next_ids_and_remaining() {
		$this->assertSame( array( 3, 5 ), Stats::next_ids( 2, false ) );
		$this->assertStringContainsString( 'LIMIT 2', $GLOBALS['wpdb']->last_sql );
		$this->assertSame( 7, Stats::remaining_count( true ) );
		$this->assertStringContainsString( "m.meta_value = 'failed'", $GLOBALS['wpdb']->last_sql );
	}
}
```

`tests/unit/RestTest.php`:

```php
<?php
namespace AIMS\Tests;

use AIMS\Rest;
use PHPUnit\Framework\TestCase;

class RestTest extends TestCase {
	public function test_normalize_ids() {
		$this->assertSame( array( 3, 7, 9 ), Rest::normalize_ids( array( '3', 7, '7', 'x', 0, -2, '9' ) ) );
		$this->assertSame( array(), Rest::normalize_ids( 'nope' ) );
		$this->assertSame( array( 1 ), Rest::normalize_ids( '1' ) );
	}
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit --filter 'StatsTest|RestTest'`
Expected: Errors, classes not found.

- [ ] **Step 3: Write the Stats class**

`includes/class-stats.php`:

```php
<?php
/**
 * Dashboard counts and "what to index next" queries.
 *
 * @package AIMS
 */

namespace AIMS;

defined( 'ABSPATH' ) || exit;

final class Stats {
	public static function mime_in_sql(): string {
		global $wpdb;
		$mimes = array_merge( Image_Preparer::IMAGE_MIMES, array( Image_Preparer::PDF_MIME ) );
		$parts = array();
		foreach ( $mimes as $mime ) {
			$parts[] = $wpdb->prepare( '%s', $mime );
		}
		return '(' . implode( ',', $parts ) . ')';
	}

	public static function status_where( bool $retry_failed, string $alias ): string {
		$where = "({$alias}.meta_value IS NULL OR {$alias}.meta_value = '" . Indexer::STATUS_PENDING . "'";
		if ( $retry_failed ) {
			$where .= " OR {$alias}.meta_value = '" . Indexer::STATUS_FAILED . "'";
		}
		return $where . ')';
	}

	private static function base_from(): string {
		global $wpdb;
		return "FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON (m.post_id = p.ID AND m.meta_key = '" . Indexer::META_STATUS . "') "
			. "WHERE p.post_type = 'attachment' AND p.post_status = 'inherit' AND p.post_mime_type IN " . self::mime_in_sql();
	}

	/**
	 * @return array{total:int,indexed:int,not_indexed:int,failed:int,skipped:int}
	 */
	public static function counts(): array {
		$cached = get_transient( Stats_Cache::KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;
		$rows = $wpdb->get_results( "SELECT COALESCE(m.meta_value, 'none') AS status, COUNT(*) AS n " . self::base_from() . ' GROUP BY status' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery

		$by = array();
		foreach ( (array) $rows as $row ) {
			$by[ (string) $row->status ] = (int) $row->n;
		}
		$counts = array(
			'total'       => array_sum( $by ),
			'indexed'     => $by[ Indexer::STATUS_INDEXED ] ?? 0,
			'not_indexed' => ( $by['none'] ?? 0 ) + ( $by[ Indexer::STATUS_PENDING ] ?? 0 ),
			'failed'      => $by[ Indexer::STATUS_FAILED ] ?? 0,
			'skipped'     => $by[ Indexer::STATUS_SKIPPED ] ?? 0,
		);
		set_transient( Stats_Cache::KEY, $counts, Stats_Cache::TTL );
		return $counts;
	}

	/**
	 * @return int[]
	 */
	public static function next_ids( int $limit, bool $retry_failed ): array {
		global $wpdb;
		$limit = max( 1, $limit );
		$ids   = $wpdb->get_col( 'SELECT p.ID ' . self::base_from() . ' AND ' . self::status_where( $retry_failed, 'm' ) . " ORDER BY p.ID ASC LIMIT {$limit}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		return array_map( 'intval', (array) $ids );
	}

	public static function remaining_count( bool $retry_failed ): int {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) ' . self::base_from() . ' AND ' . self::status_where( $retry_failed, 'm' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
	}
}
```

- [ ] **Step 4: Write the Rest class**

`includes/class-rest.php`:

```php
<?php
/**
 * REST routes used by the admin JS.
 *
 * @package AIMS
 */

namespace AIMS;

use AIMS\Providers\Registry;

defined( 'ABSPATH' ) || exit;

final class Rest {
	const NS          = 'aims/v1';
	const TIME_BUDGET = 20;

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function routes(): void {
		register_rest_route(
			self::NS,
			'/index/(?P<id>\d+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'index_one' ),
				'permission_callback' => array( $this, 'can_edit_attachment' ),
				'args'                => array( 'id' => array( 'type' => 'integer', 'required' => true ) ),
			)
		);
		register_rest_route(
			self::NS,
			'/bulk',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'bulk' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'ids'          => array( 'type' => 'array', 'required' => false ),
					'batch_size'   => array( 'type' => 'integer', 'required' => false ),
					'retry_failed' => array( 'type' => 'boolean', 'required' => false, 'default' => false ),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/stats',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'stats' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
		register_rest_route(
			self::NS,
			'/test',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'test' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
	}

	public function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 */
	public function can_edit_attachment( $request ): bool {
		$id = (int) $request['id'];
		return $id > 0 && current_user_can( 'upload_files' ) && current_user_can( 'edit_post', $id );
	}

	/**
	 * @param mixed $raw Anything the client sent as ids.
	 * @return int[] Unique positive integers in the order received.
	 */
	public static function normalize_ids( $raw ): array {
		if ( is_string( $raw ) || is_int( $raw ) ) {
			$raw = array( $raw );
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$ids = array();
		foreach ( $raw as $value ) {
			if ( is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ) {
				$id = (int) $value;
				if ( $id > 0 && ! in_array( $id, $ids, true ) ) {
					$ids[] = $id;
				}
			}
		}
		return $ids;
	}

	private static function result_for( int $id, $outcome ): array {
		$payload          = Indexer::payload( $id );
		$payload['ok']    = ! is_wp_error( $outcome );
		$payload['title'] = (string) get_the_title( $id );
		if ( is_wp_error( $outcome ) ) {
			$payload['error'] = $outcome->get_error_message();
			$payload['code']  = $outcome->get_error_code();
		}
		return $payload;
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 */
	public function index_one( $request ) {
		$id = (int) $request['id'];
		if ( 'attachment' !== get_post_type( $id ) ) {
			return new \WP_Error( 'not_found', __( 'Attachment not found.', 'ai-media-search' ), array( 'status' => 404 ) );
		}
		$outcome = ( new Indexer() )->index_attachment( $id );
		return rest_ensure_response( self::result_for( $id, $outcome ) );
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 */
	public function bulk( $request ) {
		$batch_size   = (int) ( $request['batch_size'] ?? 0 );
		$batch_size   = $batch_size > 0 ? min( 10, $batch_size ) : (int) Settings::get( 'batch_size' );
		$retry_failed = ! empty( $request['retry_failed'] );
		$explicit     = self::normalize_ids( $request['ids'] ?? null );

		if ( $explicit ) {
			$ids       = array_slice( $explicit, 0, $batch_size );
			$remaining = array_slice( $explicit, $batch_size );
		} else {
			$ids       = Stats::next_ids( $batch_size, $retry_failed );
			$remaining = array();
		}

		$indexer = new Indexer();
		$results = array();
		$started = microtime( true );
		foreach ( $ids as $position => $id ) {
			if ( $position > 0 && ( microtime( true ) - $started ) > self::TIME_BUDGET ) {
				// Out of time for this request; hand the rest back to the client.
				$remaining = array_merge( array_slice( $ids, $position ), $remaining );
				break;
			}
			$results[] = self::result_for( $id, $indexer->index_attachment( $id ) );
		}

		return rest_ensure_response(
			array(
				'results'         => $results,
				'remaining_ids'   => array_values( $remaining ),
				'remaining_count' => $explicit ? count( $remaining ) : Stats::remaining_count( $retry_failed ),
				'stats'           => Stats::counts(),
			)
		);
	}

	public function stats() {
		return rest_ensure_response( Stats::counts() );
	}

	public function test() {
		$provider = Registry::active();
		if ( is_wp_error( $provider ) ) {
			return rest_ensure_response( array( 'ok' => false, 'message' => $provider->get_error_message() ) );
		}
		$outcome = $provider->test_connection();
		if ( is_wp_error( $outcome ) ) {
			return rest_ensure_response( array( 'ok' => false, 'message' => $outcome->get_error_message() ) );
		}
		return rest_ensure_response(
			array(
				'ok'      => true,
				/* translators: 1: provider label, 2: model id */
				'message' => sprintf( __( 'Connected to %1$s using %2$s.', 'ai-media-search' ), $provider::get_label(), Settings::get_active_model() ),
			)
		);
	}
}
```

- [ ] **Step 5: Register in Plugin::init()**

Add after the Search line in `includes/class-plugin.php`:

```php
		( new Rest() )->register();
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `vendor/bin/phpunit --filter 'StatsTest|RestTest'`
Expected: OK (4 tests).

- [ ] **Step 7: Commit**

```bash
git add includes/class-stats.php includes/class-rest.php includes/class-plugin.php tests/unit/StatsTest.php tests/unit/RestTest.php
git -c commit.gpgsign=false commit -m "Add stats queries and REST routes for indexing"
```

---

### Task 12: Attachment fields, media column, bulk action

**Files:**
- Create: `includes/class-attachment-fields.php`
- Modify: `includes/class-plugin.php`
- Test: `tests/unit/AttachmentFieldsTest.php`

**Interfaces:**
- Consumes: `Indexer::payload()`, `Indexer::META_DESCRIPTION`, `Indexer::rebuild_search_text()`, `Image_Preparer::is_eligible_mime()`, `Queue::schedule()`.
- Produces: `AIMS\Attachment_Fields` with `register()`, `fields( array $fields, $post ): array`, `save( array $post, array $attachment ): array`, `column( array $columns ): array`, `column_content( string $column, int $id ): void`, `bulk_action( array $actions ): array`, `handle_bulk( string $redirect, string $action, array $ids ): string`, `bulk_notice(): void`, static `status_label( string $status ): string`, static `status_html( array $payload ): string`.

- [ ] **Step 1: Write the failing test**

`tests/unit/AttachmentFieldsTest.php`:

```php
<?php
namespace AIMS\Tests;

use AIMS\Attachment_Fields;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class AttachmentFieldsTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( 'esc_html' )->alias( function ( $s ) { return htmlspecialchars( $s, ENT_QUOTES ); } );
		Functions\when( 'esc_attr' )->alias( function ( $s ) { return htmlspecialchars( $s, ENT_QUOTES ); } );
		Functions\when( 'wp_date' )->justReturn( '2026-09-10 12:00' );
		Functions\when( 'get_option' )->justReturn( 'Y-m-d H:i' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_status_label() {
		$this->assertSame( 'Indexed', Attachment_Fields::status_label( 'indexed' ) );
		$this->assertSame( 'Not indexed', Attachment_Fields::status_label( '' ) );
		$this->assertSame( 'Failed', Attachment_Fields::status_label( 'failed' ) );
	}

	public function test_status_html_escapes_error() {
		$html = Attachment_Fields::status_html( array( 'status' => 'failed', 'error' => '<b>boom</b>', 'indexed_at' => 0 ) );
		$this->assertStringContainsString( 'aims-status-failed', $html );
		$this->assertStringContainsString( '&lt;b&gt;boom&lt;/b&gt;', $html );
		$this->assertStringNotContainsString( '<b>boom', $html );
	}

	public function test_fields_added_only_for_eligible_mimes() {
		Functions\when( 'get_post_meta' )->justReturn( '' );
		$post = (object) array( 'ID' => 4, 'post_mime_type' => 'video/mp4' );
		$this->assertSame( array( 'x' => 1 ), ( new Attachment_Fields() )->fields( array( 'x' => 1 ), $post ) );

		$post   = (object) array( 'ID' => 4, 'post_mime_type' => 'image/jpeg' );
		$fields = ( new Attachment_Fields() )->fields( array(), $post );
		$this->assertSame( 'textarea', $fields['aims_description']['input'] );
		$this->assertSame( 'html', $fields['aims_status']['input'] );
		$this->assertStringContainsString( 'data-id="4"', $fields['aims_status']['html'] );
		$this->assertStringContainsString( 'aims-regenerate', $fields['aims_status']['html'] );
	}

	public function test_save_updates_description_and_rebuilds_search_text() {
		Functions\when( 'wp_unslash' )->returnArg( 1 );
		Functions\when( 'sanitize_textarea_field' )->alias( 'trim' );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		$calls = array();
		Functions\when( 'update_post_meta' )->alias( function ( $id, $key, $value ) use ( &$calls ) { $calls[ $key ] = $value; return true; } );

		( new Attachment_Fields() )->save( array( 'ID' => 5 ), array( 'aims_description' => ' Fixed text ' ) );
		$this->assertSame( 'Fixed text', $calls['_aims_description'] );
		$this->assertArrayHasKey( '_aims_search_text', $calls );
	}

	public function test_handle_bulk_schedules_each_id() {
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\expect( 'wp_schedule_single_event' )->twice()->andReturn( true );
		Functions\when( 'add_query_arg' )->alias( function ( $k, $v, $url ) { return $url . '?' . $k . '=' . $v; } );
		$redirect = ( new Attachment_Fields() )->handle_bulk( 'upload.php', 'aims_index', array( 1, 2 ) );
		$this->assertSame( 'upload.php?aims_queued=2', $redirect );
		$this->assertSame( 'upload.php', ( new Attachment_Fields() )->handle_bulk( 'upload.php', 'other', array( 1 ) ) );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter AttachmentFieldsTest`
Expected: Error, class not found.

- [ ] **Step 3: Write the Attachment_Fields class**

`includes/class-attachment-fields.php`:

```php
<?php
/**
 * Media modal fields, list-view column, and bulk action.
 *
 * @package AIMS
 */

namespace AIMS;

defined( 'ABSPATH' ) || exit;

final class Attachment_Fields {
	public function register(): void {
		add_filter( 'attachment_fields_to_edit', array( $this, 'fields' ), 10, 2 );
		add_filter( 'attachment_fields_to_save', array( $this, 'save' ), 10, 2 );
		add_filter( 'manage_media_columns', array( $this, 'column' ) );
		add_action( 'manage_media_custom_column', array( $this, 'column_content' ), 10, 2 );
		add_filter( 'bulk_actions-upload', array( $this, 'bulk_action' ) );
		add_filter( 'handle_bulk_actions-upload', array( $this, 'handle_bulk' ), 10, 3 );
		add_action( 'admin_notices', array( $this, 'bulk_notice' ) );
	}

	public static function status_label( string $status ): string {
		switch ( $status ) {
			case Indexer::STATUS_INDEXED:
				return __( 'Indexed', 'ai-media-search' );
			case Indexer::STATUS_FAILED:
				return __( 'Failed', 'ai-media-search' );
			case Indexer::STATUS_SKIPPED:
				return __( 'Skipped', 'ai-media-search' );
			case Indexer::STATUS_PENDING:
				return __( 'Pending', 'ai-media-search' );
			default:
				return __( 'Not indexed', 'ai-media-search' );
		}
	}

	public static function status_html( array $payload ): string {
		$status = (string) ( $payload['status'] ?? '' );
		$class  = 'aims-status aims-status-' . ( '' === $status ? 'none' : $status );
		$html   = '<span class="' . esc_attr( $class ) . '">' . esc_html( self::status_label( $status ) ) . '</span>';

		if ( Indexer::STATUS_INDEXED === $status && ! empty( $payload['indexed_at'] ) ) {
			$html .= ' <span class="aims-muted">' . esc_html( wp_date( (string) get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $payload['indexed_at'] ) ) . '</span>';
		}
		if ( ! empty( $payload['error'] ) && Indexer::STATUS_INDEXED !== $status ) {
			$html .= ' <span class="aims-error">' . esc_html( (string) $payload['error'] ) . '</span>';
		}
		return $html;
	}

	/**
	 * @param array  $fields Existing fields.
	 * @param object $post   WP_Post.
	 */
	public function fields( $fields, $post ) {
		$fields = (array) $fields;
		if ( ! Image_Preparer::is_eligible_mime( (string) $post->post_mime_type ) ) {
			return $fields;
		}
		$payload = Indexer::payload( (int) $post->ID );

		$fields['aims_description'] = array(
			'label' => __( 'AI description', 'ai-media-search' ),
			'input' => 'textarea',
			'value' => $payload['description'],
			'helps' => __( 'Used by the media search. Edit it to correct the AI.', 'ai-media-search' ),
		);
		$fields['aims_tags']        = array(
			'label' => __( 'AI tags', 'ai-media-search' ),
			'input' => 'html',
			'html'  => '<p class="aims-tags" data-id="' . esc_attr( (string) $post->ID ) . '">' . esc_html( implode( ', ', $payload['tags'] ) ) . '</p>',
		);
		$fields['aims_status']      = array(
			'label' => __( 'AI index', 'ai-media-search' ),
			'input' => 'html',
			'html'  => '<span class="aims-status-wrap" data-id="' . esc_attr( (string) $post->ID ) . '">' . self::status_html( $payload ) . '</span> '
				. '<button type="button" class="button button-small aims-regenerate" data-id="' . esc_attr( (string) $post->ID ) . '">'
				. esc_html__( 'Regenerate', 'ai-media-search' ) . '</button>',
		);
		return $fields;
	}

	/**
	 * @param array $post       Post data being saved.
	 * @param array $attachment Submitted field values for this attachment.
	 */
	public function save( $post, $attachment ) {
		if ( isset( $attachment['aims_description'] ) && isset( $post['ID'] ) ) {
			$id = (int) $post['ID'];
			update_post_meta( $id, Indexer::META_DESCRIPTION, sanitize_textarea_field( wp_unslash( (string) $attachment['aims_description'] ) ) );
			Indexer::rebuild_search_text( $id );
		}
		return $post;
	}

	public function column( $columns ) {
		$columns['aims_status'] = __( 'AI index', 'ai-media-search' );
		return $columns;
	}

	public function column_content( $column, $id ): void {
		if ( 'aims_status' !== $column ) {
			return;
		}
		$payload = Indexer::payload( (int) $id );
		echo '<span class="aims-status-wrap" data-id="' . esc_attr( (string) $id ) . '">' . self::status_html( $payload ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- status_html escapes.
	}

	public function bulk_action( $actions ) {
		$actions['aims_index'] = __( 'Index with AI', 'ai-media-search' );
		return $actions;
	}

	public function handle_bulk( $redirect, $action, $ids ) {
		if ( 'aims_index' !== $action ) {
			return $redirect;
		}
		$count = 0;
		foreach ( (array) $ids as $id ) {
			if ( Queue::schedule( (int) $id, 5 ) ) {
				++$count;
			}
		}
		return add_query_arg( 'aims_queued', $count, $redirect );
	}

	public function bulk_notice(): void {
		if ( ! isset( $_GET['aims_queued'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
			return;
		}
		$count = (int) $_GET['aims_queued']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<div class="notice notice-success is-dismissible"><p>'
			/* translators: %d: number of files */
			. esc_html( sprintf( _n( '%d file queued for AI indexing.', '%d files queued for AI indexing.', $count, 'ai-media-search' ), $count ) )
			. '</p></div>';
	}
}
```

- [ ] **Step 4: Register in Plugin::init()**

Add after the Rest line in `includes/class-plugin.php`:

```php
		( new Attachment_Fields() )->register();
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter AttachmentFieldsTest`
Expected: OK (5 tests). If `_n` is reported undefined in `bulk_notice`, it is only called at runtime, not in tests; no stub needed.

- [ ] **Step 6: Commit**

```bash
git add includes/class-attachment-fields.php includes/class-plugin.php tests/unit/AttachmentFieldsTest.php
git -c commit.gpgsign=false commit -m "Add attachment fields, media column, and bulk action"
```

---

### Task 13: Admin page (Dashboard and Settings tabs) with JS and CSS

**Files:**
- Create: `includes/class-admin-page.php`, `assets/admin.js`, `assets/admin.css`
- Modify: `includes/class-plugin.php`
- Test: `tests/unit/AdminPageTest.php` (pure helpers only; the rendered page is verified manually in Task 14)

**Interfaces:**
- Consumes: `Settings`, `Providers\Registry`, `Stats`, `Indexer`, `Attachment_Fields::status_html()`, `Image_Preparer`, `Rest::NS`.
- Produces: `AIMS\Admin_Page` with `const SLUG = 'ai-media-search'`, `register()`, `menu()`, `enqueue( string $hook )`, `enqueue_for_media()`, `render()`, static `filter_meta_query( string $filter ): array` (pure), static `truncate( string $text, int $length = 160 ): string` (pure).

- [ ] **Step 1: Write the failing test**

`tests/unit/AdminPageTest.php`:

```php
<?php
namespace AIMS\Tests;

use AIMS\Admin_Page;
use PHPUnit\Framework\TestCase;

class AdminPageTest extends TestCase {
	public function test_filter_meta_query() {
		$this->assertSame( array(), Admin_Page::filter_meta_query( 'all' ) );
		$this->assertSame( array(), Admin_Page::filter_meta_query( 'bogus' ) );
		$this->assertSame(
			array( array( 'key' => '_aims_status', 'value' => 'indexed' ) ),
			Admin_Page::filter_meta_query( 'indexed' )
		);
		$not = Admin_Page::filter_meta_query( 'not_indexed' );
		$this->assertSame( 'OR', $not['relation'] );
		$this->assertSame( 'NOT EXISTS', $not[0]['compare'] );
		$this->assertSame( 'pending', $not[1]['value'] );
	}

	public function test_truncate() {
		$this->assertSame( 'short', Admin_Page::truncate( 'short' ) );
		$long = str_repeat( 'a', 200 );
		$this->assertSame( str_repeat( 'a', 160 ) . '…', Admin_Page::truncate( $long ) );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter AdminPageTest`
Expected: Error, class not found.

- [ ] **Step 3: Write the Admin_Page class**

`includes/class-admin-page.php`:

```php
<?php
/**
 * Top-level admin page with Dashboard and Settings tabs.
 *
 * @package AIMS
 */

namespace AIMS;

use AIMS\Providers\Registry;

defined( 'ABSPATH' ) || exit;

final class Admin_Page {
	const SLUG     = 'ai-media-search';
	const PER_PAGE = 50;
	const FILTERS  = array( 'all', 'indexed', 'not_indexed', 'failed', 'skipped' );

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_enqueue_media', array( $this, 'enqueue_for_media' ) );
	}

	public function menu(): void {
		add_menu_page(
			__( 'AI Media Search', 'ai-media-search' ),
			__( 'AI Media Search', 'ai-media-search' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-search',
			81
		);
	}

	public function enqueue( string $hook ): void {
		$is_our_page  = 'toplevel_page_' . self::SLUG === $hook;
		$is_media     = 'upload.php' === $hook;
		$is_edit_att  = 'post.php' === $hook && 'attachment' === get_post_type( (int) ( $_GET['post'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $is_our_page || $is_media || $is_edit_att ) {
			$this->enqueue_for_media();
		}
	}

	public function enqueue_for_media(): void {
		if ( wp_script_is( 'aims-admin', 'enqueued' ) ) {
			return;
		}
		wp_enqueue_style( 'aims-admin', AIMS_URL . 'assets/admin.css', array(), AIMS_VERSION );
		wp_enqueue_script( 'aims-admin', AIMS_URL . 'assets/admin.js', array(), AIMS_VERSION, true );
		wp_localize_script(
			'aims-admin',
			'aimsData',
			array(
				'restUrl'   => esc_url_raw( rest_url( Rest::NS . '/' ) ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'batchSize' => (int) Settings::get( 'batch_size' ),
				'hasKey'    => '' !== Settings::get_api_key( (string) Settings::get( 'provider' ) ),
				'i18n'      => array(
					'working'    => __( 'Working…', 'ai-media-search' ),
					'done'       => __( 'Done.', 'ai-media-search' ),
					'stopped'    => __( 'Stopped.', 'ai-media-search' ),
					'failed'     => __( 'Request failed.', 'ai-media-search' ),
					'regenerate' => __( 'Regenerate', 'ai-media-search' ),
					'index'      => __( 'Index', 'ai-media-search' ),
					'progress'   => /* translators: 1: done count, 2: total count */ __( '%1$s of %2$s', 'ai-media-search' ),
					'noSelection' => __( 'Select at least one file first.', 'ai-media-search' ),
				),
			)
		);
	}

	public static function filter_meta_query( string $filter ): array {
		switch ( $filter ) {
			case 'indexed':
			case 'failed':
			case 'skipped':
				return array( array( 'key' => Indexer::META_STATUS, 'value' => $filter ) );
			case 'not_indexed':
				return array(
					'relation' => 'OR',
					array( 'key' => Indexer::META_STATUS, 'compare' => 'NOT EXISTS' ),
					array( 'key' => Indexer::META_STATUS, 'value' => Indexer::STATUS_PENDING ),
				);
			default:
				return array();
		}
	}

	public static function truncate( string $text, int $length = 160 ): string {
		if ( mb_strlen( $text ) <= $length ) {
			return $text;
		}
		return rtrim( mb_substr( $text, 0, $length ) ) . '…';
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'ai-media-search' ) );
		}
		$settings = Settings::all();
		$has_key  = '' !== Settings::get_api_key( $settings['provider'] );
		?>
		<div class="wrap aims-wrap">
			<h1><?php esc_html_e( 'AI Media Search', 'ai-media-search' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Describe your images with AI so the Media Library search finds them by what is in the picture.', 'ai-media-search' ); ?></p>

			<nav class="nav-tab-wrapper aims-tabs">
				<a href="#dashboard" class="nav-tab nav-tab-active aims-tab" data-tab="dashboard"><?php esc_html_e( 'Dashboard', 'ai-media-search' ); ?></a>
				<a href="#settings" class="nav-tab aims-tab" data-tab="settings"><?php esc_html_e( 'Settings', 'ai-media-search' ); ?></a>
			</nav>

			<div id="aims-tab-dashboard" class="aims-tab-panel">
				<?php $this->render_dashboard( $has_key ); ?>
			</div>
			<div id="aims-tab-settings" class="aims-tab-panel" hidden>
				<?php $this->render_settings( $settings ); ?>
			</div>
		</div>
		<?php
	}

	private function render_dashboard( bool $has_key ): void {
		$filter = sanitize_key( (string) ( $_GET['filter'] ?? 'all' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$filter = in_array( $filter, self::FILTERS, true ) ? $filter : 'all';
		$paged  = max( 1, (int) ( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$counts = Stats::counts();

		$cards = array(
			'all'         => array( __( 'All files', 'ai-media-search' ), $counts['total'] ),
			'indexed'     => array( __( 'Indexed', 'ai-media-search' ), $counts['indexed'] ),
			'not_indexed' => array( __( 'Not indexed', 'ai-media-search' ), $counts['not_indexed'] ),
			'failed'      => array( __( 'Failed', 'ai-media-search' ), $counts['failed'] ),
			'skipped'     => array( __( 'Skipped', 'ai-media-search' ), $counts['skipped'] ),
		);

		if ( ! $has_key ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Add an API key on the Settings tab before indexing.', 'ai-media-search' ) . '</p></div>';
		}

		echo '<div class="aims-cards">';
		foreach ( $cards as $key => list( $label, $count ) ) {
			$url   = add_query_arg( array( 'page' => self::SLUG, 'filter' => $key ), admin_url( 'admin.php' ) ) . '#dashboard';
			$class = 'aims-card aims-card-' . $key . ( $key === $filter ? ' is-active' : '' );
			echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( $url ) . '"><span class="aims-card-count">' . esc_html( number_format_i18n( $count ) ) . '</span><span class="aims-card-label">' . esc_html( $label ) . '</span></a>';
		}
		echo '</div>';

		$query = new \WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => array_merge( Image_Preparer::IMAGE_MIMES, array( Image_Preparer::PDF_MIME ) ),
				'posts_per_page' => self::PER_PAGE,
				'paged'          => $paged,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'meta_query'     => self::filter_meta_query( $filter ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			)
		);
		?>
		<table class="widefat striped aims-table">
			<thead>
				<tr>
					<td class="check-column"><input type="checkbox" id="aims-select-all" /></td>
					<th><?php esc_html_e( 'File', 'ai-media-search' ); ?></th>
					<th><?php esc_html_e( 'AI description', 'ai-media-search' ); ?></th>
					<th><?php esc_html_e( 'Tags', 'ai-media-search' ); ?></th>
					<th><?php esc_html_e( 'Status', 'ai-media-search' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'ai-media-search' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( ! $query->have_posts() ) : ?>
				<tr><td colspan="6"><?php esc_html_e( 'No files match this filter.', 'ai-media-search' ); ?></td></tr>
			<?php endif; ?>
			<?php
			foreach ( $query->posts as $post ) :
				$id      = (int) $post->ID;
				$payload = Indexer::payload( $id );
				$button  = Indexer::STATUS_INDEXED === $payload['status'] ? __( 'Regenerate', 'ai-media-search' ) : __( 'Index', 'ai-media-search' );
				?>
				<tr class="aims-row" data-id="<?php echo esc_attr( (string) $id ); ?>">
					<th scope="row" class="check-column"><input type="checkbox" class="aims-select" value="<?php echo esc_attr( (string) $id ); ?>" /></th>
					<td class="aims-file">
						<?php echo wp_get_attachment_image( $id, array( 60, 60 ), true ); ?>
						<strong><?php echo esc_html( get_the_title( $id ) ); ?></strong><br />
						<span class="aims-muted"><?php echo esc_html( wp_basename( (string) get_attached_file( $id ) ) ); ?></span>
					</td>
					<td class="aims-description"><?php echo esc_html( self::truncate( $payload['description'] ) ); ?></td>
					<td class="aims-tags"><?php echo esc_html( implode( ', ', $payload['tags'] ) ); ?></td>
					<td class="aims-status-cell"><span class="aims-status-wrap" data-id="<?php echo esc_attr( (string) $id ); ?>"><?php echo Attachment_Fields::status_html( $payload ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span></td>
					<td class="aims-actions">
						<button type="button" class="button button-small aims-index-one" data-id="<?php echo esc_attr( (string) $id ); ?>" <?php disabled( ! $has_key ); ?>><?php echo esc_html( $button ); ?></button>
						<a class="aims-edit" href="<?php echo esc_url( (string) get_edit_post_link( $id ) ); ?>"><?php esc_html_e( 'Edit', 'ai-media-search' ); ?></a>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		$links = paginate_links(
			array(
				'base'      => add_query_arg( array( 'page' => self::SLUG, 'filter' => $filter, 'paged' => '%#%' ), admin_url( 'admin.php' ) ) . '#dashboard',
				'format'    => '',
				'current'   => $paged,
				'total'     => (int) $query->max_num_pages,
				'prev_text' => '&laquo;',
				'next_text' => '&raquo;',
			)
		);
		if ( $links ) {
			echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( $links ) . '</div></div>';
		}
		?>
		<div class="aims-controls">
			<button type="button" class="button button-primary" id="aims-index-selected" <?php disabled( ! $has_key ); ?>><?php esc_html_e( 'Index selected', 'ai-media-search' ); ?></button>
			<button type="button" class="button" id="aims-index-all" <?php disabled( ! $has_key ); ?>><?php esc_html_e( 'Index all not indexed', 'ai-media-search' ); ?></button>
			<label><input type="checkbox" id="aims-retry-failed" /> <?php esc_html_e( 'Also retry failed', 'ai-media-search' ); ?></label>
			<button type="button" class="button" id="aims-stop" disabled><?php esc_html_e( 'Stop', 'ai-media-search' ); ?></button>
			<span id="aims-progress-text" class="aims-muted"></span>
			<div class="aims-progress"><div class="aims-progress-bar" id="aims-progress-bar"></div></div>
			<ul id="aims-log" class="aims-log"></ul>
		</div>
		<?php
	}

	private function render_settings( array $settings ): void {
		?>
		<div class="notice notice-info inline"><p><?php esc_html_e( 'Images and PDF previews are sent to the selected third-party API for analysis. Check the provider\'s terms and privacy policy before enabling.', 'ai-media-search' ); ?></p></div>
		<form method="post" action="options.php" class="aims-settings-form">
			<?php settings_fields( 'aims_settings_group' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Provider', 'ai-media-search' ); ?></th>
					<td>
						<?php foreach ( Registry::labels() as $id => $label ) : ?>
							<label class="aims-provider-choice">
								<input type="radio" name="aims_settings[provider]" value="<?php echo esc_attr( $id ); ?>" <?php checked( $settings['provider'], $id ); ?> />
								<?php echo esc_html( $label ); ?>
							</label>
						<?php endforeach; ?>
					</td>
				</tr>
				<?php foreach ( Registry::labels() as $id => $label ) : ?>
					<?php
					$key_set = '' !== $settings['api_keys'][ $id ];
					$model   = $settings['models'][ $id ];
					$known   = Registry::known_models( $id );
					if ( '' === $model && $known ) {
						$model = (string) array_key_first( $known );
					}
					$is_custom = 'custom' === $model || ( '' !== $model && ! isset( $known[ $model ] ) );
					?>
					<tr class="aims-provider-row" data-provider="<?php echo esc_attr( $id ); ?>">
						<th scope="row"><?php echo esc_html( $label ); ?></th>
						<td>
							<p>
								<label for="aims-key-<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'API key', 'ai-media-search' ); ?></label><br />
								<input type="password" class="regular-text" id="aims-key-<?php echo esc_attr( $id ); ?>" name="aims_settings[api_keys][<?php echo esc_attr( $id ); ?>]" value="" autocomplete="off"
									placeholder="<?php echo esc_attr( $key_set ? __( 'Saved. Paste a new key to replace it.', 'ai-media-search' ) : __( 'Paste your API key', 'ai-media-search' ) ); ?>" />
							</p>
							<p>
								<label for="aims-model-<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Model', 'ai-media-search' ); ?></label><br />
								<select id="aims-model-<?php echo esc_attr( $id ); ?>" class="aims-model-select" name="aims_settings[models][<?php echo esc_attr( $id ); ?>]">
									<?php foreach ( $known as $model_id => $hint ) : ?>
										<option value="<?php echo esc_attr( $model_id ); ?>" <?php selected( ! $is_custom && $model === $model_id ); ?>><?php echo esc_html( $model_id . ' — ' . $hint ); ?></option>
									<?php endforeach; ?>
									<option value="custom" <?php selected( $is_custom ); ?>><?php esc_html_e( 'Custom model ID…', 'ai-media-search' ); ?></option>
								</select>
								<input type="text" class="regular-text aims-custom-model" name="aims_settings[custom_models][<?php echo esc_attr( $id ); ?>]"
									value="<?php echo esc_attr( $is_custom && 'custom' !== $model ? $model : $settings['custom_models'][ $id ] ); ?>"
									placeholder="<?php esc_attr_e( 'exact model id', 'ai-media-search' ); ?>" <?php echo $is_custom ? '' : 'hidden'; ?> />
							</p>
						</td>
					</tr>
				<?php endforeach; ?>
				<tr>
					<th scope="row"><label for="aims-language"><?php esc_html_e( 'Description language', 'ai-media-search' ); ?></label></th>
					<td><input type="text" id="aims-language" class="regular-text" name="aims_settings[language]" value="<?php echo esc_attr( $settings['language'] ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="aims-custom-prompt"><?php esc_html_e( 'Custom prompt', 'ai-media-search' ); ?></label></th>
					<td>
						<textarea id="aims-custom-prompt" class="large-text" rows="5" name="aims_settings[custom_prompt]"><?php echo esc_textarea( $settings['custom_prompt'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Optional extra guidance added to every request, for example product names or house style.', 'ai-media-search' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Automation', 'ai-media-search' ); ?></th>
					<td>
						<label><input type="checkbox" name="aims_settings[auto_index]" value="1" <?php checked( $settings['auto_index'] ); ?> /> <?php esc_html_e( 'Describe new uploads automatically', 'ai-media-search' ); ?></label><br />
						<label><input type="checkbox" name="aims_settings[fill_alt]" value="1" <?php checked( $settings['fill_alt'] ); ?> /> <?php esc_html_e( 'Fill empty alt text with the AI alt sentence', 'ai-media-search' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aims-batch-size"><?php esc_html_e( 'Batch size', 'ai-media-search' ); ?></label></th>
					<td>
						<input type="number" id="aims-batch-size" min="1" max="10" name="aims_settings[batch_size]" value="<?php echo esc_attr( (string) $settings['batch_size'] ); ?>" />
						<p class="description"><?php esc_html_e( 'Files described per request when indexing from the dashboard.', 'ai-media-search' ); ?></p>
					</td>
				</tr>
			</table>
			<p class="submit">
				<?php submit_button( __( 'Save settings', 'ai-media-search' ), 'primary', 'submit', false ); ?>
				<button type="button" class="button" id="aims-test"><?php esc_html_e( 'Test connection', 'ai-media-search' ); ?></button>
				<span id="aims-test-result" class="aims-muted"></span>
			</p>
		</form>
		<?php
	}
}
```

- [ ] **Step 4: Write the admin JS**

`assets/admin.js`:

```js
( function () {
	'use strict';

	var data = window.aimsData || {};
	var i18n = data.i18n || {};

	function api( path, body ) {
		return fetch( data.restUrl + path, {
			method: body === undefined ? 'GET' : 'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': data.nonce },
			credentials: 'same-origin',
			body: body === undefined ? undefined : JSON.stringify( body )
		} ).then( function ( res ) {
			return res.json().then( function ( json ) {
				if ( ! res.ok ) {
					throw new Error( ( json && json.message ) || i18n.failed );
				}
				return json;
			} );
		} );
	}

	function escapeHtml( text ) {
		var div = document.createElement( 'div' );
		div.textContent = text == null ? '' : String( text );
		return div.innerHTML;
	}

	function statusHtml( item ) {
		var status = item.status || 'none';
		var labels = { indexed: 'Indexed', failed: 'Failed', skipped: 'Skipped', pending: 'Pending', none: 'Not indexed' };
		var html = '<span class="aims-status aims-status-' + escapeHtml( status ) + '">' + escapeHtml( labels[ status ] || status ) + '</span>';
		if ( item.error && status !== 'indexed' ) {
			html += ' <span class="aims-error">' + escapeHtml( item.error ) + '</span>';
		}
		return html;
	}

	// Update every place on the page that shows this attachment.
	function applyResult( item ) {
		var id = String( item.id );
		document.querySelectorAll( '.aims-status-wrap[data-id="' + id + '"]' ).forEach( function ( el ) {
			el.innerHTML = statusHtml( item );
		} );
		document.querySelectorAll( '.aims-tags[data-id="' + id + '"]' ).forEach( function ( el ) {
			el.textContent = ( item.tags || [] ).join( ', ' );
		} );
		var row = document.querySelector( '.aims-row[data-id="' + id + '"]' );
		if ( row ) {
			row.querySelector( '.aims-description' ).textContent = item.description || '';
			row.querySelector( '.aims-tags' ).textContent = ( item.tags || [] ).join( ', ' );
			var btn = row.querySelector( '.aims-index-one' );
			if ( btn ) {
				btn.textContent = item.status === 'indexed' ? i18n.regenerate : i18n.index;
			}
		}
		// Media modal / attachment edit screen textarea.
		var textarea = document.querySelector( 'textarea[name="attachments[' + id + '][aims_description]"]' );
		if ( textarea ) {
			textarea.value = item.description || '';
		}
	}

	// ---- Tabs -------------------------------------------------------------
	function activateTab( name ) {
		document.querySelectorAll( '.aims-tab' ).forEach( function ( tab ) {
			tab.classList.toggle( 'nav-tab-active', tab.dataset.tab === name );
		} );
		document.querySelectorAll( '.aims-tab-panel' ).forEach( function ( panel ) {
			panel.hidden = panel.id !== 'aims-tab-' + name;
		} );
	}
	document.querySelectorAll( '.aims-tab' ).forEach( function ( tab ) {
		tab.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			activateTab( tab.dataset.tab );
			history.replaceState( null, '', '#' + tab.dataset.tab );
		} );
	} );
	if ( location.hash === '#settings' ) {
		activateTab( 'settings' );
	}

	// ---- Settings helpers -------------------------------------------------
	document.querySelectorAll( '.aims-model-select' ).forEach( function ( select ) {
		select.addEventListener( 'change', function () {
			var custom = select.parentNode.querySelector( '.aims-custom-model' );
			if ( custom ) {
				custom.hidden = select.value !== 'custom';
			}
		} );
	} );

	var testBtn = document.getElementById( 'aims-test' );
	if ( testBtn ) {
		testBtn.addEventListener( 'click', function () {
			var out = document.getElementById( 'aims-test-result' );
			out.textContent = i18n.working;
			testBtn.disabled = true;
			api( 'test', {} ).then( function ( json ) {
				out.textContent = json.message;
				out.className = json.ok ? 'aims-ok' : 'aims-error';
			} ).catch( function ( err ) {
				out.textContent = err.message;
				out.className = 'aims-error';
			} ).then( function () {
				testBtn.disabled = false;
			} );
		} );
	}

	// ---- Single index / regenerate (dashboard rows and media modal) -------
	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '.aims-index-one, .aims-regenerate' );
		if ( ! btn ) {
			return;
		}
		e.preventDefault();
		var id = btn.dataset.id;
		var label = btn.textContent;
		btn.disabled = true;
		btn.textContent = i18n.working;
		api( 'index/' + id, {} ).then( applyResult ).catch( function ( err ) {
			applyResult( { id: id, status: 'failed', error: err.message, tags: [] } );
		} ).then( function () {
			btn.disabled = false;
			if ( btn.textContent === i18n.working ) {
				btn.textContent = label;
			}
		} );
	} );

	// ---- Dashboard batch loop --------------------------------------------
	var selectAll = document.getElementById( 'aims-select-all' );
	if ( selectAll ) {
		selectAll.addEventListener( 'change', function () {
			document.querySelectorAll( '.aims-select' ).forEach( function ( box ) {
				box.checked = selectAll.checked;
			} );
		} );
	}

	var state = { running: false, stop: false };

	function setProgress( done, total ) {
		var bar = document.getElementById( 'aims-progress-bar' );
		var text = document.getElementById( 'aims-progress-text' );
		var pct = total > 0 ? Math.round( ( done / total ) * 100 ) : 0;
		bar.style.width = pct + '%';
		text.textContent = ( i18n.progress || '%1$s of %2$s' ).replace( '%1$s', done ).replace( '%2$s', total );
	}

	function log( item ) {
		var ul = document.getElementById( 'aims-log' );
		var li = document.createElement( 'li' );
		li.className = item.ok ? 'aims-log-ok' : 'aims-log-fail';
		li.textContent = '#' + item.id + ' ' + ( item.title || '' ) + ' — ' + ( item.ok ? ( item.status || '' ) : ( item.error || '' ) );
		ul.insertBefore( li, ul.firstChild );
	}

	function setRunning( running ) {
		state.running = running;
		[ 'aims-index-selected', 'aims-index-all' ].forEach( function ( id ) {
			var el = document.getElementById( id );
			if ( el ) {
				el.disabled = running;
			}
		} );
		document.getElementById( 'aims-stop' ).disabled = ! running;
	}

	function runBatch( ids, total, done ) {
		if ( state.stop ) {
			finish( i18n.stopped );
			return;
		}
		var body = {
			batch_size: data.batchSize,
			retry_failed: document.getElementById( 'aims-retry-failed' ).checked
		};
		if ( ids ) {
			body.ids = ids;
		}
		api( 'bulk', body ).then( function ( json ) {
			json.results.forEach( function ( item ) {
				applyResult( item );
				log( item );
			} );
			done += json.results.length;
			if ( ids ) {
				total = done + json.remaining_ids.length;
				setProgress( done, total );
				if ( json.remaining_ids.length === 0 || json.results.length === 0 ) {
					finish( i18n.done );
					return;
				}
				runBatch( json.remaining_ids, total, done );
			} else {
				total = done + json.remaining_count;
				setProgress( done, total );
				if ( json.remaining_count === 0 || json.results.length === 0 ) {
					finish( i18n.done );
					return;
				}
				runBatch( null, total, done );
			}
		} ).catch( function ( err ) {
			log( { id: '-', ok: false, error: err.message } );
			finish( i18n.failed );
		} );
	}

	function finish( message ) {
		document.getElementById( 'aims-progress-text' ).textContent += ' ' + message;
		setRunning( false );
		state.stop = false;
	}

	function start( ids ) {
		if ( state.running ) {
			return;
		}
		document.getElementById( 'aims-log' ).innerHTML = '';
		setProgress( 0, ids ? ids.length : 0 );
		state.stop = false;
		setRunning( true );
		runBatch( ids, ids ? ids.length : 0, 0 );
	}

	var indexSelected = document.getElementById( 'aims-index-selected' );
	if ( indexSelected ) {
		indexSelected.addEventListener( 'click', function () {
			var ids = Array.prototype.map.call( document.querySelectorAll( '.aims-select:checked' ), function ( box ) {
				return parseInt( box.value, 10 );
			} );
			if ( ids.length === 0 ) {
				window.alert( i18n.noSelection );
				return;
			}
			start( ids );
		} );
		document.getElementById( 'aims-index-all' ).addEventListener( 'click', function () {
			start( null );
		} );
		document.getElementById( 'aims-stop' ).addEventListener( 'click', function () {
			state.stop = true;
		} );
	}
}() );
```

- [ ] **Step 5: Write the admin CSS**

`assets/admin.css`:

```css
.aims-wrap .aims-tabs { margin-bottom: 16px; }
.aims-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; margin: 16px 0; }
.aims-card { display: block; padding: 14px 16px; background: #fff; border: 1px solid #c3c4c7; border-left-width: 4px; text-decoration: none; color: #1d2327; }
.aims-card:hover, .aims-card.is-active { border-color: #2271b1; box-shadow: 0 0 0 1px #2271b1; }
.aims-card-count { display: block; font-size: 24px; font-weight: 600; line-height: 1.2; }
.aims-card-label { display: block; color: #646970; }
.aims-card-indexed { border-left-color: #00a32a; }
.aims-card-not_indexed { border-left-color: #dba617; }
.aims-card-failed { border-left-color: #d63638; }
.aims-card-skipped { border-left-color: #8c8f94; }
.aims-table .aims-file img { float: left; margin-right: 8px; width: 60px; height: 60px; object-fit: cover; }
.aims-table .aims-description { max-width: 360px; }
.aims-table .aims-tags { max-width: 220px; color: #50575e; }
.aims-status { display: inline-block; padding: 1px 6px; border-radius: 3px; font-size: 12px; background: #f0f0f1; }
.aims-status-indexed { background: #edfaef; color: #00600f; }
.aims-status-failed { background: #fcf0f1; color: #8a1f22; }
.aims-status-pending { background: #fcf9e8; color: #8a6d00; }
.aims-muted { color: #646970; }
.aims-error { color: #d63638; }
.aims-ok { color: #00a32a; }
.aims-controls { margin-top: 16px; }
.aims-controls .button { margin-right: 6px; }
.aims-progress { height: 10px; margin: 12px 0; background: #dcdcde; border-radius: 5px; overflow: hidden; max-width: 600px; }
.aims-progress-bar { height: 100%; width: 0; background: #2271b1; transition: width .3s ease; }
.aims-log { max-height: 240px; overflow: auto; margin: 0; padding: 8px 12px; background: #fff; border: 1px solid #c3c4c7; max-width: 600px; font-family: monospace; font-size: 12px; }
.aims-log li { margin: 0 0 2px; }
.aims-log-fail { color: #d63638; }
.aims-provider-choice { margin-right: 16px; }
.aims-custom-model { margin-top: 6px; display: block; }
.aims-custom-model[hidden] { display: none; }
```

- [ ] **Step 6: Register in Plugin::init()**

Add after the Attachment_Fields line in `includes/class-plugin.php`:

```php
		if ( is_admin() ) {
			( new Admin_Page() )->register();
		}
```

- [ ] **Step 7: Run the tests and syntax checks**

Run: `vendor/bin/phpunit && php -l includes/class-admin-page.php && node --check assets/admin.js`
Expected: all green, no syntax errors.

- [ ] **Step 8: Commit**

```bash
git add includes/class-admin-page.php includes/class-plugin.php assets tests/unit/AdminPageTest.php
git -c commit.gpgsign=false commit -m "Add admin page with dashboard and settings tabs"
```

---

### Task 14: readme.txt, coding standards pass, manual verification

**Files:**
- Create: `readme.txt`, `phpcs.xml.dist`
- Modify: any file PHPCS flags

**Interfaces:** none new.

- [ ] **Step 1: Write readme.txt**

`readme.txt`:

```
=== AI Media Search ===
Contributors: danlapteacru
Tags: media library, search, ai, alt text, images
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Search your Media Library by what is in the picture. A vision model describes each image so the search box finds "woman on a beach" even when nobody typed it.

== Description ==

The WordPress media search only matches titles, captions, and descriptions. AI Media Search sends each image (and the first-page preview of each PDF) to a vision model of your choice, stores the description and tags it returns, and extends the Media Library search box to match that text. It works in list view, grid view, and the media modal inside the editor.

**Features**

* Automatic description of new uploads in the background.
* Dashboard with counts, filters, and a batch indexer with progress for your existing library.
* Editable AI description on every attachment, with a Regenerate button.
* Choice of provider and model: Anthropic Claude, OpenAI, or Google Gemini. Bring your own API key.
* Optional: fill empty alt text with the AI alt sentence.
* Optional custom prompt for house style or product names.

**Requirements**

You need an API key from the provider you choose. Each request costs a fraction of a cent to a few cents depending on the model; the settings screen shows an estimate next to each model.

== External services ==

This plugin sends image data to a third-party API that you select and configure. Nothing is sent until you save an API key.

**Anthropic Claude** (https://www.anthropic.com/) — When an image or PDF preview is indexed, the plugin sends the image bytes, the instruction text, and your API key to https://api.anthropic.com/v1/messages. Terms: https://www.anthropic.com/legal/commercial-terms. Privacy: https://www.anthropic.com/legal/privacy.

**OpenAI** (https://openai.com/) — Same data is sent to https://api.openai.com/v1/responses. Terms: https://openai.com/policies/terms-of-use. Privacy: https://openai.com/policies/privacy-policy.

**Google Gemini** (https://ai.google.dev/) — Same data is sent to https://generativelanguage.googleapis.com/. Terms: https://ai.google.dev/gemini-api/terms. Privacy: https://policies.google.com/privacy.

The "Test connection" button sends a short text prompt to the selected provider.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` or install it from the Plugins screen.
2. Activate it.
3. Open **AI Media Search** in the admin menu, go to the Settings tab, choose a provider, paste your API key, and save.
4. On the Dashboard tab, click **Index all not indexed** to describe your existing library.
5. Search the Media Library for something in a picture.

== Frequently Asked Questions ==

= Does this change my titles, captions, or descriptions? =

No. AI text is stored in its own fields. The only optional write to a standard field is alt text, and only when it is empty and you turned that setting on.

= Which files are described? =

JPEG, PNG, GIF, WebP images, and PDFs for which WordPress generated a preview image (requires the Imagick extension on your server). Other files are marked as skipped.

= Can I correct the AI? =

Yes. Every attachment has an editable "AI description" field. Your edit is what the search uses.

= What does it cost? =

Only what your provider charges. The model dropdown shows a rough per-image estimate. Nothing is sent unless an API key is saved, and existing files are only processed when you start the indexer.

== Screenshots ==

1. Dashboard with counts, filters, and the batch indexer.
2. Settings tab with provider, model, and prompt options.
3. AI description and Regenerate button in the media modal.
4. Media Library search finding an image by its content.

== Changelog ==

= 1.0.0 =
* First release.
```

- [ ] **Step 2: Write the PHPCS ruleset**

`phpcs.xml.dist`:

```xml
<?xml version="1.0"?>
<ruleset name="AI Media Search">
	<description>WordPress Coding Standards for AI Media Search.</description>
	<file>.</file>
	<exclude-pattern>/vendor/*</exclude-pattern>
	<exclude-pattern>/tests/*</exclude-pattern>
	<exclude-pattern>/node_modules/*</exclude-pattern>
	<arg name="extensions" value="php"/>
	<arg name="colors"/>
	<arg value="sp"/>
	<config name="minimum_supported_wp_version" value="6.0"/>
	<config name="testVersion" value="7.4-"/>
	<rule ref="WordPress">
		<exclude name="Generic.Arrays.DisallowShortArraySyntax"/>
	</rule>
	<rule ref="PHPCompatibilityWP"/>
	<rule ref="WordPress.WP.I18n">
		<properties>
			<property name="text_domain" type="array">
				<element value="ai-media-search"/>
			</property>
		</properties>
	</rule>
	<rule ref="WordPress.NamingConventions.PrefixAllGlobals">
		<properties>
			<property name="prefixes" type="array">
				<element value="aims"/>
				<element value="AIMS"/>
			</property>
		</properties>
	</rule>
</ruleset>
```

- [ ] **Step 3: Run PHPCS and fix what it reports**

Run: `vendor/bin/phpcs`
Expected: a list of warnings and errors on first run. Run `vendor/bin/phpcbf` to auto-fix formatting, then fix the remainder by hand. Typical items: missing `// phpcs:ignore` justifications on the direct database queries in `Stats` and `uninstall.php` (already annotated), unescaped output where `status_html()` is echoed (already annotated), and `$_GET` reads without nonce verification on display-only screens (already annotated). Do not silence anything that is a real escaping or sanitization gap; fix the code instead.

Re-run until: `vendor/bin/phpcs` prints no errors. Warnings about `file_get_contents` and `base64_encode` are already annotated in `Abstract_Provider`.

- [ ] **Step 4: Run the full test suite one more time**

Run: `vendor/bin/phpunit`
Expected: all green.

- [ ] **Step 5: Commit**

```bash
git add readme.txt phpcs.xml.dist -A
git -c commit.gpgsign=false commit -m "Add readme and pass WordPress coding standards"
```

- [ ] **Step 6: Manual verification in a WordPress install**

Docker Desktop is installed on this machine but was not running when the plan was written. If it can be started, use wp-env. Otherwise use any local WordPress (Local, Valet, or an existing dev site) and symlink or copy the repo into `wp-content/plugins/ai-media-search`.

With wp-env, create `.wp-env.json` in the repo root:

```json
{
  "core": null,
  "plugins": [ "." ],
  "config": { "WP_DEBUG": true, "WP_DEBUG_LOG": true, "DISABLE_WP_CRON": false }
}
```

Run: `npx @wordpress/env start` then open `http://localhost:8888/wp-admin` (admin / password).

Checklist. Tick each after seeing it work:

- [ ] Activate the plugin with no errors in `wp-content/debug.log`.
- [ ] **AI Media Search** appears in the admin menu. Dashboard shows a warning that no key is saved; buttons are disabled.
- [ ] Settings tab: pick a provider, paste a real key, save. Reload shows the "Saved" placeholder. Choose "Custom model ID…", the text field appears; choose a known model, it hides.
- [ ] Click **Test connection**. Success message names provider and model. Enter a wrong key and confirm the error message comes from the provider.
- [ ] Upload a JPEG through Media > Add New. Within about a minute (or after visiting any page to trigger WP-Cron), the attachment details show an AI description, tags, and status "Indexed".
- [ ] Media Library list view: the "AI index" column shows the status. Grid view search for a word that appears only in the AI description returns the image. The same search inside the editor's media modal returns it.
- [ ] Open the image in the media modal, edit the AI description to include a made-up word, save, search for that word: found.
- [ ] Click **Regenerate** in the modal: button shows "Working…", then fields update without a reload.
- [ ] Upload a PDF. If Imagick is present it gets indexed from its preview; otherwise it shows "Skipped" with the no-preview message.
- [ ] Upload a video: not listed on the dashboard and no cron event scheduled (check with WP Crontrol or `wp cron event list`).
- [ ] Dashboard: with several unindexed files, click **Index all not indexed**. Progress bar advances, log lists each file, rows update in place, Stop halts after the current batch, stat cards refresh after reload.
- [ ] Dashboard: select two rows, click **Index selected**. Only those two are processed.
- [ ] Media Library list view: select rows, bulk action **Index with AI**, confirm the notice with the count and that the rows get indexed shortly after.
- [ ] Turn on **Fill empty alt text**, regenerate an image with empty alt: alt field is filled. Regenerate one with existing alt: unchanged.
- [ ] Temporarily set an invalid key, upload an image: status "Failed" with the auth error, no retry event scheduled. Restore the key.
- [ ] Deactivate the plugin: `wp cron event list` shows no `aims_index_attachment` events.
- [ ] Run the Plugin Check plugin (`wp plugin install plugin-check --activate` then Tools > Plugin Check) and fix anything it reports as an error.

- [ ] **Step 7: Commit any fixes from manual verification**

```bash
git add -A
git -c commit.gpgsign=false commit -m "Fix issues found during manual verification"
```

---

## Out of scope reminders

Do not add embeddings, folders, frontend search, video support, key encryption, or multisite network settings. If a task seems to need one of these, stop and raise it instead of building it.
