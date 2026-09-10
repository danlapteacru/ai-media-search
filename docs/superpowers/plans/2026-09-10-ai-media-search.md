     1	# AI Media Search Implementation Plan
     2	
     3	> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.
     4	
     5	**Goal:** A wordpress.org plugin that describes every image and PDF preview with a vision model and makes the Media Library search box match that text, so "woman on a beach" finds the photo.
     6	
     7	**Architecture:** On upload, a WP-Cron event runs an indexer that picks a reasonably sized copy of the file, sends it to the configured provider (Claude, OpenAI, or Gemini) with a shared JSON-schema prompt, and stores description, tags, and alt in post meta plus one combined `_aims_search_text` row. `posts_join` and `posts_search` filters extend every attachment search to that meta row. A top-level admin page holds a Dashboard tab (stat cards, paginated asset table, batch indexing with progress) and a Settings tab.
     8	
     9	**Tech Stack:** PHP 7.4+, WordPress 6.0+, WordPress HTTP API, Settings API, REST API, WP-Cron. Dev only: Composer, PHPUnit 9, Brain Monkey, PHPCS with WordPress Coding Standards. Vanilla JS, no build step.
    10	
    11	**Spec:** `docs/superpowers/specs/2026-09-10-ai-media-search-design.md`
    12	
    13	## Global Constraints
    14	
    15	- PHP 7.4+, WordPress 6.0+. No PHP 8-only syntax (no enums, no `match`, no readonly, no named args to WP functions).
    16	- Slug and text domain `ai-media-search`, function/hook/option prefix `aims_`, namespace `AIMS`, meta key prefix `_aims_`, REST namespace `aims/v1`.
    17	- Every string translatable with the `ai-media-search` text domain. Every output escaped. Every input sanitized. Capability checks and nonces on every form handler and REST route.
    18	- HTTP calls only through `wp_remote_post` / `wp_remote_get`. No vendored SDKs. No Composer autoloader shipped; `composer.json` is dev-only.
    19	- Eligible mime types: `image/jpeg`, `image/png`, `image/gif`, `image/webp`, `application/pdf`.
    20	- Provider request shapes and model IDs come from the spec section "Claude_Provider, OpenAI_Provider, Gemini_Provider". Do not change them from memory.
    21	- Git commits in this repo must use `git -c commit.gpgsign=false commit ...` because the machine's signing helper cannot run non-interactively. Commit messages carry no AI attribution lines.
    22	- Run tests with `vendor/bin/phpunit` from the repo root. Run style checks with `vendor/bin/phpcs`.
    23	
    24	---
    25	
    26	## File Structure
    27	
    28	The repo root is the plugin root, so a zip of the repo (minus dev files) is the plugin.
    29	
    30	```
    31	ai-media-search.php                    plugin header, constants, boot
    32	uninstall.php                          delete option and _aims_* meta
    33	readme.txt                             wordpress.org readme
    34	composer.json                          dev dependencies only
    35	phpunit.xml.dist                       PHPUnit config
    36	phpcs.xml.dist                         WPCS ruleset
    37	.gitignore / .distignore
    38	includes/autoload.php                  tiny PSR-4-ish autoloader for AIMS\
    39	includes/class-plugin.php              wires components (no logic)
    40	includes/class-description-result.php  value object
    41	includes/class-prompt.php              instruction text, JSON schema, parse()
    42	includes/class-settings.php            option registration, defaults, sanitize, getters
    43	includes/class-image-preparer.php      choose/resize the file to send
    44	includes/class-indexer.php             prepare -> provider -> meta
    45	includes/class-queue.php               cron scheduling and handler
    46	includes/class-search.php              posts_join / posts_search filters
    47	includes/class-stats.php               dashboard counts
    48	includes/class-rest.php                REST routes
    49	includes/class-attachment-fields.php   media modal fields, list column, bulk action
    50	includes/class-admin-page.php          menu page, tabs, settings form, dashboard table
    51	includes/providers/interface-provider.php
    52	includes/providers/class-registry.php  provider ids, labels, factory
    53	includes/providers/class-abstract-provider.php  shared HTTP and error mapping
    54	includes/providers/class-claude-provider.php
    55	includes/providers/class-openai-provider.php
    56	includes/providers/class-gemini-provider.php
    57	assets/admin.js                        tabs, regenerate button, batch loop
    58	assets/admin.css                       dashboard styling
    59	tests/bootstrap.php                    Brain Monkey + WP_Error stub + autoloader
    60	tests/stubs/class-wp-error.php         minimal WP_Error for unit tests
    61	tests/unit/*Test.php                   one test class per component
    62	```
    63	
    64	Autoloader mapping: `AIMS\Foo_Bar` -> `includes/class-foo-bar.php`; `AIMS\Providers\Foo_Provider` -> `includes/providers/class-foo-provider.php`; `AIMS\Providers\Provider_Interface` -> `includes/providers/interface-provider.php`.
    65	
    66	---
    67	
    68	### Task 1: Scaffold, autoloader, value object, test harness
    69	
    70	**Files:**
    71	- Create: `ai-media-search.php`, `uninstall.php`, `includes/autoload.php`, `includes/class-plugin.php`, `includes/class-description-result.php`, `composer.json`, `phpunit.xml.dist`, `.gitignore`, `.distignore`, `tests/bootstrap.php`, `tests/stubs/class-wp-error.php`
    72	- Test: `tests/unit/DescriptionResultTest.php`
    73	
    74	**Interfaces:**
    75	- Produces: constants `AIMS_VERSION`, `AIMS_FILE`, `AIMS_PATH`, `AIMS_URL`; class `AIMS\Description_Result` with public typed properties `description` (string), `tags` (string[]), `alt` (string), constructor `(string $description, array $tags, string $alt)`, and `to_array(): array`; class `AIMS\Plugin` with `instance()` and `init()`; test bootstrap that loads Brain Monkey and a `WP_Error` stub.
    76	
    77	- [ ] **Step 1: Create composer.json, phpunit config, ignores**
    78	
    79	`composer.json`:
    80	
    81	```json
    82	{
    83	  "name": "danlapteacru/ai-media-search",
    84	  "description": "Search the WordPress Media Library by what is in the image.",
    85	  "type": "wordpress-plugin",
    86	  "license": "GPL-2.0-or-later",
    87	  "require": {
    88	    "php": ">=7.4"
    89	  },
    90	  "require-dev": {
    91	    "phpunit/phpunit": "^9.6",
    92	    "brain/monkey": "^2.6",
    93	    "wp-coding-standards/wpcs": "^3.1",
    94	    "dealerdirect/phpcodesniffer-composer-installer": "^1.0",
    95	    "phpcompatibility/phpcompatibility-wp": "^2.1"
    96	  },
    97	  "autoload-dev": {
    98	    "psr-4": { "AIMS\\Tests\\": "tests/unit/" }
    99	  },
   100	  "config": {
   101	    "allow-plugins": { "dealerdirect/phpcodesniffer-composer-installer": true }
   102	  },
   103	  "scripts": {
   104	    "test": "phpunit",
   105	    "lint": "phpcs",
   106	    "fix": "phpcbf"
   107	  }
   108	}
   109	```
   110	
   111	`phpunit.xml.dist`:
   112	
   113	```xml
   114	<?xml version="1.0" encoding="UTF-8"?>
   115	<phpunit bootstrap="tests/bootstrap.php" colors="true" beStrictAboutTestsThatDoNotTestAnything="true">
   116	  <testsuites>
   117	    <testsuite name="unit">
   118	      <directory suffix="Test.php">tests/unit</directory>
   119	    </testsuite>
   120	  </testsuites>
   121	</phpunit>
   122	```
   123	
   124	`.gitignore`:
   125	
   126	```
   127	/vendor/
   128	/node_modules/
   129	.phpunit.result.cache
   130	.phpcs.cache
   131	*.zip
   132	```
   133	
   134	`.distignore`:
   135	
   136	```
   137	/.git
   138	/.github
   139	/vendor
   140	/tests
   141	/docs
   142	/node_modules
   143	.gitignore
   144	.distignore
   145	composer.json
   146	composer.lock
   147	phpunit.xml.dist
   148	phpcs.xml.dist
   149	.phpunit.result.cache
   150	```
   151	
   152	- [ ] **Step 2: Install dev dependencies**
   153	
   154	Run: `composer install`
   155	Expected: `vendor/bin/phpunit` and `vendor/bin/phpcs` exist. If Composer complains about the PHP version for PHPUnit 9 on PHP 8.1, it is fine; PHPUnit 9.6 supports 7.3 to 8.3.
   156	
   157	- [ ] **Step 3: Write the test bootstrap and WP_Error stub**
   158	
   159	`tests/stubs/class-wp-error.php`:
   160	
   161	```php
   162	<?php
   163	/**
   164	 * Minimal WP_Error stand-in for unit tests that run without WordPress.
   165	 */
   166	if ( ! class_exists( 'WP_Error' ) ) {
   167		class WP_Error {
   168			public $errors     = array();
   169			public $error_data = array();
   170	
   171			public function __construct( $code = '', $message = '', $data = '' ) {
   172				if ( '' !== $code ) {
   173					$this->errors[ $code ][] = $message;
   174					if ( '' !== $data ) {
   175						$this->error_data[ $code ] = $data;
   176					}
   177				}
   178			}
   179	
   180			public function get_error_code() {
   181				$codes = array_keys( $this->errors );
   182				return $codes ? $codes[0] : '';
   183			}
   184	
   185			public function get_error_message( $code = '' ) {
   186				if ( '' === $code ) {
   187					$code = $this->get_error_code();
   188				}
   189				return isset( $this->errors[ $code ][0] ) ? $this->errors[ $code ][0] : '';
   190			}
   191	
   192			public function get_error_data( $code = '' ) {
   193				if ( '' === $code ) {
   194					$code = $this->get_error_code();
   195				}
   196				return isset( $this->error_data[ $code ] ) ? $this->error_data[ $code ] : null;
   197			}
   198		}
   199	}
   200	
   201	if ( ! function_exists( 'is_wp_error' ) ) {
   202		function is_wp_error( $thing ) {
   203			return $thing instanceof WP_Error;
   204		}
   205	}
   206	```
   207	
   208	`tests/bootstrap.php`:
   209	
   210	```php
   211	<?php
   212	require_once dirname( __DIR__ ) . '/vendor/autoload.php';
   213	require_once __DIR__ . '/stubs/class-wp-error.php';
   214	
   215	if ( ! defined( 'AIMS_PATH' ) ) {
   216		define( 'AIMS_PATH', dirname( __DIR__ ) . '/' );
   217	}
   218	if ( ! defined( 'AIMS_VERSION' ) ) {
   219		define( 'AIMS_VERSION', 'test' );
   220	}
   221	if ( ! defined( 'AIMS_URL' ) ) {
   222		define( 'AIMS_URL', 'http://example.test/wp-content/plugins/ai-media-search/' );
   223	}
   224	if ( ! defined( 'AIMS_FILE' ) ) {
   225		define( 'AIMS_FILE', AIMS_PATH . 'ai-media-search.php' );
   226	}
   227	if ( ! defined( 'ABSPATH' ) ) {
   228		define( 'ABSPATH', AIMS_PATH );
   229	}
   230	
   231	require_once AIMS_PATH . 'includes/autoload.php';
   232	```
   233	
   234	- [ ] **Step 4: Write the failing test for Description_Result**
   235	
   236	`tests/unit/DescriptionResultTest.php`:
   237	
   238	```php
   239	<?php
   240	namespace AIMS\Tests;
   241	
   242	use AIMS\Description_Result;
   243	use PHPUnit\Framework\TestCase;
   244	
   245	class DescriptionResultTest extends TestCase {
   246		public function test_holds_values_and_exports_array() {
   247			$result = new Description_Result( 'A woman on a beach.', array( 'woman', 'beach' ), 'Woman standing on a beach.' );
   248	
   249			$this->assertSame( 'A woman on a beach.', $result->description );
   250			$this->assertSame( array( 'woman', 'beach' ), $result->tags );
   251			$this->assertSame( 'Woman standing on a beach.', $result->alt );
   252			$this->assertSame(
   253				array(
   254					'description' => 'A woman on a beach.',
   255					'tags'        => array( 'woman', 'beach' ),
   256					'alt'         => 'Woman standing on a beach.',
   257				),
   258				$result->to_array()
   259			);
   260		}
   261	}
   262	```
   263	
   264	- [ ] **Step 5: Run the test to verify it fails**
   265	
   266	Run: `vendor/bin/phpunit --filter DescriptionResultTest`
   267	Expected: Error, class `AIMS\Description_Result` not found.
   268	
   269	- [ ] **Step 6: Write the autoloader and value object**
   270	
   271	`includes/autoload.php`:
   272	
   273	```php
   274	<?php
   275	/**
   276	 * Autoloader for the AIMS namespace.
   277	 *
   278	 * AIMS\Foo_Bar                     -> includes/class-foo-bar.php
   279	 * AIMS\Providers\Foo_Provider      -> includes/providers/class-foo-provider.php
   280	 * AIMS\Providers\Provider_Interface-> includes/providers/interface-provider.php
   281	 *
   282	 * @package AIMS
   283	 */
   284	
   285	defined( 'ABSPATH' ) || exit;
   286	
   287	spl_autoload_register(
   288		function ( $class ) {
   289			if ( 0 !== strpos( $class, 'AIMS\\' ) ) {
   290				return;
   291			}
   292	
   293			$relative = substr( $class, 5 );
   294			$parts    = explode( '\\', $relative );
   295			$name     = array_pop( $parts );
   296			$slug     = strtolower( str_replace( '_', '-', $name ) );
   297	
   298			if ( '-interface' === substr( $slug, -10 ) ) {
   299				$file = 'interface-' . substr( $slug, 0, -10 ) . '.php';
   300			} else {
   301				$file = 'class-' . $slug . '.php';
   302			}
   303	
   304			$dir = AIMS_PATH . 'includes/';
   305			if ( $parts ) {
   306				$dir .= strtolower( implode( '/', $parts ) ) . '/';
   307			}
   308	
   309			$path = $dir . $file;
   310			if ( file_exists( $path ) ) {
   311				require_once $path;
   312			}
   313		}
   314	);
   315	```
   316	
   317	`includes/class-description-result.php`:
   318	
   319	```php
   320	<?php
   321	/**
   322	 * Value object returned by a provider.
   323	 *
   324	 * @package AIMS
   325	 */
   326	
   327	namespace AIMS;
   328	
   329	defined( 'ABSPATH' ) || exit;
   330	
   331	final class Description_Result {
   332		/** @var string */
   333		public $description;
   334	
   335		/** @var string[] */
   336		public $tags;
   337	
   338		/** @var string */
   339		public $alt;
   340	
   341		public function __construct( string $description, array $tags, string $alt ) {
   342			$this->description = $description;
   343			$this->tags        = array_values( $tags );
   344			$this->alt         = $alt;
   345		}
   346	
   347		public function to_array(): array {
   348			return array(
   349				'description' => $this->description,
   350				'tags'        => $this->tags,
   351				'alt'         => $this->alt,
   352			);
   353		}
   354	}
   355	```
   356	
   357	- [ ] **Step 7: Run the test to verify it passes**
   358	
   359	Run: `vendor/bin/phpunit --filter DescriptionResultTest`
   360	Expected: OK (1 test, 4 assertions).
   361	
   362	- [ ] **Step 8: Write the main plugin file, Plugin class, uninstall**
   363	
   364	`ai-media-search.php`:
   365	
   366	```php
   367	<?php
   368	/**
   369	 * Plugin Name:       AI Media Search
   370	 * Plugin URI:        https://wordpress.org/plugins/ai-media-search/
   371	 * Description:       Search your Media Library by what is in the image. A vision model describes each image and PDF so the media search box finds them by content.
   372	 * Version:           1.0.0
   373	 * Requires at least: 6.0
   374	 * Requires PHP:      7.4
   375	 * Author:            Dan Lapteacru
   376	 * License:           GPLv2 or later
   377	 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
   378	 * Text Domain:       ai-media-search
   379	 *
   380	 * @package AIMS
   381	 */
   382	
   383	defined( 'ABSPATH' ) || exit;
   384	
   385	define( 'AIMS_VERSION', '1.0.0' );
   386	define( 'AIMS_FILE', __FILE__ );
   387	define( 'AIMS_PATH', plugin_dir_path( __FILE__ ) );
   388	define( 'AIMS_URL', plugin_dir_url( __FILE__ ) );
   389	
   390	require_once AIMS_PATH . 'includes/autoload.php';
   391	
   392	add_action(
   393		'plugins_loaded',
   394		function () {
   395			AIMS\Plugin::instance()->init();
   396		}
   397	);
   398	
   399	register_deactivation_hook( __FILE__, array( 'AIMS\Queue', 'clear_all' ) );
   400	```
   401	
   402	`includes/class-plugin.php` (components are added to `init()` in later tasks; keep the list in this order):
   403	
   404	```php
   405	<?php
   406	/**
   407	 * Wires every component. Holds no logic.
   408	 *
   409	 * @package AIMS
   410	 */
   411	
   412	namespace AIMS;
   413	
   414	defined( 'ABSPATH' ) || exit;
   415	
   416	final class Plugin {
   417		/** @var Plugin|null */
   418		private static $instance = null;
   419	
   420		public static function instance(): Plugin {
   421			if ( null === self::$instance ) {
   422				self::$instance = new self();
   423			}
   424			return self::$instance;
   425		}
   426	
   427		public function init(): void {
   428			load_plugin_textdomain( 'ai-media-search', false, dirname( plugin_basename( AIMS_FILE ) ) . '/languages' );
   429			// Components register here in later tasks.
   430		}
   431	}
   432	```
   433	
   434	`uninstall.php`:
   435	
   436	```php
   437	<?php
   438	/**
   439	 * Removes every trace of the plugin.
   440	 *
   441	 * @package AIMS
   442	 */
   443	
   444	defined( 'WP_UNINSTALL_PLUGIN' ) || exit;
   445	
   446	delete_option( 'aims_settings' );
   447	delete_transient( 'aims_stats' );
   448	
   449	global $wpdb;
   450	$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\\_aims\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
   451	```
   452	
   453	- [ ] **Step 9: Syntax-check every PHP file**
   454	
   455	Run: `for f in ai-media-search.php uninstall.php includes/*.php; do php -l "$f"; done`
   456	Expected: "No syntax errors detected" for each.
   457	
   458	- [ ] **Step 10: Commit**
   459	
   460	```bash
   461	git add -A
   462	git -c commit.gpgsign=false commit -m "Scaffold plugin, autoloader, value object, test harness"
   463	```
   464	
   465	---
   466	
   467	### Task 2: Prompt builder and parser
   468	
   469	**Files:**
   470	- Create: `includes/class-prompt.php`
   471	- Test: `tests/unit/PromptTest.php`
   472	
   473	**Interfaces:**
   474	- Consumes: `AIMS\Description_Result`.
   475	- Produces: `AIMS\Prompt::schema(): array` (JSON schema with `additionalProperties: false` and full `required`), `Prompt::instructions( string $language, string $custom_prompt = '' ): string`, `Prompt::user_text(): string` (returns `Describe this image.`), `Prompt::parse( string $json )` returning `Description_Result` or `WP_Error( 'bad_response' )`.
   476	
   477	- [ ] **Step 1: Write the failing tests**
   478	
   479	`tests/unit/PromptTest.php`:
   480	
   481	```php
   482	<?php
   483	namespace AIMS\Tests;
   484	
   485	use AIMS\Description_Result;
   486	use AIMS\Prompt;
   487	use Brain\Monkey;
   488	use Brain\Monkey\Functions;
   489	use PHPUnit\Framework\TestCase;
   490	
   491	class PromptTest extends TestCase {
   492		protected function setUp(): void {
   493			parent::setUp();
   494			Monkey\setUp();
   495			Functions\when( '__' )->returnArg( 1 );
   496		}
   497	
   498		protected function tearDown(): void {
   499			Monkey\tearDown();
   500			parent::tearDown();
   501		}
   502	
   503		public function test_schema_requires_all_fields_and_forbids_extras() {
   504			$schema = Prompt::schema();
   505			$this->assertSame( 'object', $schema['type'] );
   506			$this->assertSame( array( 'description', 'tags', 'alt' ), $schema['required'] );
   507			$this->assertFalse( $schema['additionalProperties'] );
   508			$this->assertSame( 'array', $schema['properties']['tags']['type'] );
   509			$this->assertSame( 'string', $schema['properties']['tags']['items']['type'] );
   510		}
   511	
   512		public function test_instructions_include_language_and_custom_prompt() {
   513			$text = Prompt::instructions( 'German', 'Prefer product names from our catalogue.' );
   514			$this->assertStringContainsString( 'German', $text );
   515			$this->assertStringContainsString( 'Additional guidance from the site owner:', $text );
   516			$this->assertStringContainsString( 'Prefer product names from our catalogue.', $text );
   517		}
   518	
   519		public function test_instructions_omit_custom_heading_when_empty() {
   520			$text = Prompt::instructions( 'English', '   ' );
   521			$this->assertStringNotContainsString( 'Additional guidance', $text );
   522		}
   523	
   524		public function test_parse_good_json() {
   525			$json   = '{"description":"A woman walks on a beach at sunset.","tags":["Woman","beach"," Sunset ","woman"],"alt":"Woman walking on a beach at sunset."}';
   526			$result = Prompt::parse( $json );
   527			$this->assertInstanceOf( Description_Result::class, $result );
   528			$this->assertSame( 'A woman walks on a beach at sunset.', $result->description );
   529			$this->assertSame( array( 'woman', 'beach', 'sunset' ), $result->tags );
   530			$this->assertSame( 'Woman walking on a beach at sunset.', $result->alt );
   531		}
   532	
   533		public function test_parse_strips_code_fences() {
   534			$json   = "```json\n{\"description\":\"A red car.\",\"tags\":[\"car\",\"red\"],\"alt\":\"Red car.\"}\n```";
   535			$result = Prompt::parse( $json );
   536			$this->assertInstanceOf( Description_Result::class, $result );
   537			$this->assertSame( 'A red car.', $result->description );
   538		}
   539	
   540		public function test_parse_rejects_missing_field() {
   541			$result = Prompt::parse( '{"description":"x","tags":["a"]}' );
   542			$this->assertInstanceOf( \WP_Error::class, $result );
   543			$this->assertSame( 'bad_response', $result->get_error_code() );
   544		}
   545	
   546		public function test_parse_rejects_malformed_json() {
   547			$result = Prompt::parse( 'not json' );
   548			$this->assertInstanceOf( \WP_Error::class, $result );
   549			$this->assertSame( 'bad_response', $result->get_error_code() );
   550		}
   551	
   552		public function test_parse_rejects_empty_description() {
   553			$result = Prompt::parse( '{"description":"  ","tags":["a"],"alt":"b"}' );
   554			$this->assertInstanceOf( \WP_Error::class, $result );
   555		}
   556	
   557		public function test_parse_drops_non_string_tags_and_caps_at_30() {
   558			$tags = array_map( 'strval', range( 1, 40 ) );
   559			$tags[] = 12;
   560			$json   = wp_json_encode_stub( array( 'description' => 'd', 'tags' => $tags, 'alt' => 'a' ) );
   561			$result = Prompt::parse( $json );
   562			$this->assertCount( 30, $result->tags );
   563		}
   564	}
   565	
   566	function wp_json_encode_stub( $data ) {
   567		return json_encode( $data );
   568	}
   569	```
   570	
   571	- [ ] **Step 2: Run the tests to verify they fail**
   572	
   573	Run: `vendor/bin/phpunit --filter PromptTest`
   574	Expected: Error, class `AIMS\Prompt` not found.
   575	
   576	- [ ] **Step 3: Write the Prompt class**
   577	
   578	`includes/class-prompt.php`:
   579	
   580	```php
   581	<?php
   582	/**
   583	 * Shared instruction text, JSON schema, and response parsing for all providers.
   584	 *
   585	 * @package AIMS
   586	 */
   587	
   588	namespace AIMS;
   589	
   590	defined( 'ABSPATH' ) || exit;
   591	
   592	final class Prompt {
   593		const MAX_TAGS = 30;
   594	
   595		public static function schema(): array {
   596			return array(
   597				'type'                 => 'object',
   598				'properties'           => array(
   599					'description' => array(
   600						'type'        => 'string',
   601						'description' => 'Two to four sentences describing the image.',
   602					),
   603					'tags'        => array(
   604						'type'        => 'array',
   605						'items'       => array( 'type' => 'string' ),
   606						'description' => 'Ten to twenty lowercase tags including plain synonyms.',
   607					),
   608					'alt'         => array(
   609						'type'        => 'string',
   610						'description' => 'One sentence under 125 characters suitable as HTML alt text.',
   611					),
   612				),
   613				'required'             => array( 'description', 'tags', 'alt' ),
   614				'additionalProperties' => false,
   615			);
   616		}
   617	
   618		public static function user_text(): string {
   619			return 'Describe this image.';
   620		}
   621	
   622		public static function instructions( string $language, string $custom_prompt = '' ): string {
   623			$lines = array(
   624				'You describe images for a website media library so that editors can find them later by typing plain words into a search box.',
   625				'Reply with JSON only, matching the schema you were given.',
   626				'',
   627				'description: Two to four sentences. Cover the main subjects, any people (how many, roughly what they are doing, notable clothing or expression; never guess names or identities), actions, setting, colours, any visible text, and the overall style (photo, illustration, screenshot, logo, diagram).',
   628				'tags: Ten to twenty lowercase tags, single words or short phrases. Include plain synonyms editors might type, for example "woman", "female", "lady"; "car", "vehicle", "automobile". Include the setting, colours, mood, and objects.',
   629				'alt: One sentence under 125 characters that works as HTML alt text.',
   630				'',
   631				sprintf( 'Write the description, tags, and alt in %s.', $language ),
   632			);
   633	
   634			$custom_prompt = trim( $custom_prompt );
   635			if ( '' !== $custom_prompt ) {
   636				$lines[] = '';
   637				$lines[] = 'Additional guidance from the site owner:';
   638				$lines[] = $custom_prompt;
   639			}
   640	
   641			return implode( "\n", $lines );
   642		}
   643	
   644		/**
   645		 * @return Description_Result|\WP_Error
   646		 */
   647		public static function parse( string $json ) {
   648			$json = trim( $json );
   649			$json = preg_replace( '/^```(?:json)?\s*/i', '', $json );
   650			$json = preg_replace( '/\s*```$/', '', $json );
   651	
   652			$data = json_decode( $json, true );
   653			if ( ! is_array( $data ) ) {
   654				return new \WP_Error( 'bad_response', __( 'The AI reply was not valid JSON.', 'ai-media-search' ) );
   655			}
   656	
   657			foreach ( array( 'description', 'tags', 'alt' ) as $field ) {
   658				if ( ! array_key_exists( $field, $data ) ) {
   659					return new \WP_Error(
   660						'bad_response',
   661						/* translators: %s: field name */
   662						sprintf( __( 'The AI reply is missing the "%s" field.', 'ai-media-search' ), $field )
   663					);
   664				}
   665			}
   666	
   667			if ( ! is_string( $data['description'] ) || '' === trim( $data['description'] ) ) {
   668				return new \WP_Error( 'bad_response', __( 'The AI reply has an empty description.', 'ai-media-search' ) );
   669			}
   670			if ( ! is_array( $data['tags'] ) || ! is_string( $data['alt'] ) ) {
   671				return new \WP_Error( 'bad_response', __( 'The AI reply has the wrong field types.', 'ai-media-search' ) );
   672			}
   673	
   674			$tags = array();
   675			foreach ( $data['tags'] as $tag ) {
   676				if ( ! is_string( $tag ) ) {
   677					continue;
   678				}
   679				$tag = strtolower( trim( $tag ) );
   680				if ( '' === $tag || in_array( $tag, $tags, true ) ) {
   681					continue;
   682				}
   683				$tags[] = $tag;
   684				if ( count( $tags ) >= self::MAX_TAGS ) {
   685					break;
   686				}
   687			}
   688	
   689			return new Description_Result( trim( $data['description'] ), $tags, trim( $data['alt'] ) );
   690		}
   691	}
   692	```
   693	
   694	- [ ] **Step 4: Run the tests to verify they pass**
   695	
   696	Run: `vendor/bin/phpunit --filter PromptTest`
   697	Expected: OK (9 tests).
   698	
   699	- [ ] **Step 5: Commit**
   700	
   701	```bash
   702	git add includes/class-prompt.php tests/unit/PromptTest.php
   703	git -c commit.gpgsign=false commit -m "Add shared prompt, JSON schema, and response parser"
   704	```
   705	
   706	---
   707	
   708	### Task 3: Settings model
   709	
   710	**Files:**
   711	- Create: `includes/class-settings.php`
   712	- Modify: `includes/class-plugin.php` (register Settings in `init()`)
   713	- Test: `tests/unit/SettingsTest.php`
   714	
   715	**Interfaces:**
   716	- Produces: `AIMS\Settings` with `const OPTION = 'aims_settings'`, `const PROVIDERS = array( 'claude', 'openai', 'gemini' )`, `defaults(): array`, `all(): array`, `get( string $key )`, `sanitize( $input ): array`, `get_api_key( string $provider ): string`, `get_model( string $provider ): string`, `get_active_model(): string`, and instance method `register()` that calls `register_setting`. Known model lists are read from `AIMS\Providers\Registry::known_models( $provider )` which Task 4 creates; until then `sanitize()` accepts any non-empty model string.
   717	
   718	- [ ] **Step 1: Write the failing tests**
   719	
   720	`tests/unit/SettingsTest.php`:
   721	
   722	```php
   723	<?php
   724	namespace AIMS\Tests;
   725	
   726	use AIMS\Settings;
   727	use Brain\Monkey;
   728	use Brain\Monkey\Functions;
   729	use PHPUnit\Framework\TestCase;
   730	
   731	class SettingsTest extends TestCase {
   732		protected function setUp(): void {
   733			parent::setUp();
   734			Monkey\setUp();
   735			Functions\when( 'sanitize_text_field' )->returnArg( 1 );
   736			Functions\when( 'sanitize_textarea_field' )->returnArg( 1 );
   737			Functions\when( 'wp_unslash' )->returnArg( 1 );
   738			Functions\when( '__' )->returnArg( 1 );
   739		}
   740	
   741		protected function tearDown(): void {
   742			Monkey\tearDown();
   743			parent::tearDown();
   744		}
   745	
   746		public function test_defaults() {
   747			$d = Settings::defaults();
   748			$this->assertSame( 'claude', $d['provider'] );
   749			$this->assertSame( 'English', $d['language'] );
   750			$this->assertTrue( $d['auto_index'] );
   751			$this->assertFalse( $d['fill_alt'] );
   752			$this->assertSame( 3, $d['batch_size'] );
   753			$this->assertSame( array( 'claude' => '', 'openai' => '', 'gemini' => '' ), $d['api_keys'] );
   754		}
   755	
   756		public function test_all_merges_saved_values_over_defaults() {
   757			Functions\when( 'get_option' )->justReturn( array( 'provider' => 'gemini', 'api_keys' => array( 'gemini' => 'g-key' ) ) );
   758			$all = Settings::all();
   759			$this->assertSame( 'gemini', $all['provider'] );
   760			$this->assertSame( 'g-key', $all['api_keys']['gemini'] );
   761			$this->assertSame( '', $all['api_keys']['claude'] );
   762			$this->assertSame( 'English', $all['language'] );
   763		}
   764	
   765		public function test_get_api_key_and_active_model() {
   766			Functions\when( 'get_option' )->justReturn(
   767				array(
   768					'provider'      => 'openai',
   769					'api_keys'      => array( 'openai' => 'sk-1' ),
   770					'models'        => array( 'openai' => 'custom' ),
   771					'custom_models' => array( 'openai' => 'gpt-future' ),
   772				)
   773			);
   774			$this->assertSame( 'sk-1', Settings::get_api_key( 'openai' ) );
   775			$this->assertSame( '', Settings::get_api_key( 'claude' ) );
   776			$this->assertSame( 'gpt-future', Settings::get_active_model() );
   777		}
   778	
   779		public function test_sanitize_normalises_input() {
   780			Functions\when( 'get_option' )->justReturn( array( 'api_keys' => array( 'claude' => 'keep-me' ) ) );
   781			$out = Settings::sanitize(
   782				array(
   783					'provider'      => 'openai',
   784					'api_keys'      => array( 'openai' => ' sk-2 ', 'claude' => '' ),
   785					'models'        => array( 'openai' => 'gpt-5.6-terra' ),
   786					'custom_models' => array( 'openai' => '' ),
   787					'language'      => ' French ',
   788					'custom_prompt' => "Use brand names.\n",
   789					'auto_index'    => '1',
   790					'batch_size'    => '25',
   791				)
   792			);
   793			$this->assertSame( 'openai', $out['provider'] );
   794			$this->assertSame( 'sk-2', $out['api_keys']['openai'] );
   795			$this->assertSame( 'keep-me', $out['api_keys']['claude'], 'empty key keeps the previously saved key' );
   796			$this->assertSame( 'French', $out['language'] );
   797			$this->assertSame( 'Use brand names.', $out['custom_prompt'] );
   798			$this->assertTrue( $out['auto_index'] );
   799			$this->assertFalse( $out['fill_alt'] );
   800			$this->assertSame( 10, $out['batch_size'] );
   801		}
   802	
   803		public function test_sanitize_rejects_unknown_provider() {
   804			Functions\when( 'get_option' )->justReturn( array() );
   805			$out = Settings::sanitize( array( 'provider' => 'bogus' ) );
   806			$this->assertSame( 'claude', $out['provider'] );
   807		}
   808	}
   809	```
   810	
   811	- [ ] **Step 2: Run the tests to verify they fail**
   812	
   813	Run: `vendor/bin/phpunit --filter SettingsTest`
   814	Expected: Error, class `AIMS\Settings` not found.
   815	
   816	- [ ] **Step 3: Write the Settings class**
   817	
   818	`includes/class-settings.php`:
   819	
   820	```php
   821	<?php
   822	/**
   823	 * Single-option settings model.
   824	 *
   825	 * @package AIMS
   826	 */
   827	
   828	namespace AIMS;
   829	
   830	defined( 'ABSPATH' ) || exit;
   831	
   832	final class Settings {
   833		const OPTION    = 'aims_settings';
   834		const PROVIDERS = array( 'claude', 'openai', 'gemini' );
   835	
   836		public static function defaults(): array {
   837			$per_provider = array_fill_keys( self::PROVIDERS, '' );
   838			return array(
   839				'provider'      => 'claude',
   840				'api_keys'      => $per_provider,
   841				'models'        => $per_provider,
   842				'custom_models' => $per_provider,
   843				'language'      => 'English',
   844				'custom_prompt' => '',
   845				'auto_index'    => true,
   846				'fill_alt'      => false,
   847				'batch_size'    => 3,
   848			);
   849		}
   850	
   851		public static function all(): array {
   852			$saved    = get_option( self::OPTION, array() );
   853			$defaults = self::defaults();
   854			if ( ! is_array( $saved ) ) {
   855				return $defaults;
   856			}
   857			$all = array_merge( $defaults, $saved );
   858			foreach ( array( 'api_keys', 'models', 'custom_models' ) as $key ) {
   859				$all[ $key ] = array_merge( $defaults[ $key ], is_array( $saved[ $key ] ?? null ) ? $saved[ $key ] : array() );
   860			}
   861			return $all;
   862		}
   863	
   864		/**
   865		 * @return mixed
   866		 */
   867		public static function get( string $key ) {
   868			$all = self::all();
   869			return $all[ $key ] ?? null;
   870		}
   871	
   872		public static function get_api_key( string $provider ): string {
   873			$keys = self::get( 'api_keys' );
   874			return (string) ( $keys[ $provider ] ?? '' );
   875		}
   876	
   877		public static function get_model( string $provider ): string {
   878			$all   = self::all();
   879			$model = (string) ( $all['models'][ $provider ] ?? '' );
   880			if ( 'custom' === $model ) {
   881				$model = (string) ( $all['custom_models'][ $provider ] ?? '' );
   882			}
   883			if ( '' === $model && class_exists( '\AIMS\Providers\Registry' ) ) {
   884				$known = Providers\Registry::known_models( $provider );
   885				$model = $known ? (string) array_key_first( $known ) : '';
   886			}
   887			return $model;
   888		}
   889	
   890		public static function get_active_model(): string {
   891			return self::get_model( (string) self::get( 'provider' ) );
   892		}
   893	
   894		/**
   895		 * @param mixed $input Raw form input.
   896		 */
   897		public static function sanitize( $input ): array {
   898			$input    = is_array( $input ) ? $input : array();
   899			$previous = self::all();
   900			$out      = self::defaults();
   901	
   902			$provider        = sanitize_text_field( (string) ( $input['provider'] ?? '' ) );
   903			$out['provider'] = in_array( $provider, self::PROVIDERS, true ) ? $provider : 'claude';
   904	
   905			foreach ( self::PROVIDERS as $id ) {
   906				$key = trim( sanitize_text_field( (string) ( $input['api_keys'][ $id ] ?? '' ) ) );
   907				// An empty submitted key keeps the stored one so the password field can stay blank.
   908				$out['api_keys'][ $id ] = '' === $key ? (string) ( $previous['api_keys'][ $id ] ?? '' ) : $key;
   909	
   910				$out['models'][ $id ]        = trim( sanitize_text_field( (string) ( $input['models'][ $id ] ?? '' ) ) );
   911				$out['custom_models'][ $id ] = trim( sanitize_text_field( (string) ( $input['custom_models'][ $id ] ?? '' ) ) );
   912			}
   913	
   914			$language        = trim( sanitize_text_field( (string) ( $input['language'] ?? '' ) ) );
   915			$out['language'] = '' === $language ? 'English' : $language;
   916	
   917			$out['custom_prompt'] = trim( sanitize_textarea_field( (string) ( $input['custom_prompt'] ?? '' ) ) );
   918			$out['auto_index']    = ! empty( $input['auto_index'] );
   919			$out['fill_alt']      = ! empty( $input['fill_alt'] );
   920	
   921			$batch             = (int) ( $input['batch_size'] ?? 3 );
   922			$out['batch_size'] = max( 1, min( 10, $batch ?: 3 ) );
   923	
   924			return $out;
   925		}
   926	
   927		public function register(): void {
   928			add_action( 'admin_init', array( $this, 'register_setting' ) );
   929		}
   930	
   931		public function register_setting(): void {
   932			register_setting(
   933				'aims_settings_group',
   934				self::OPTION,
   935				array(
   936					'type'              => 'array',
   937					'sanitize_callback' => array( __CLASS__, 'sanitize' ),
   938					'default'           => self::defaults(),
   939				)
   940			);
   941		}
   942	}
   943	```
   944	
   945	Note `array_key_first` is PHP 7.3+, fine for our 7.4 floor.
   946	
   947	- [ ] **Step 4: Run the tests to verify they pass**
   948	
   949	Run: `vendor/bin/phpunit --filter SettingsTest`
   950	Expected: OK (5 tests).
   951	
   952	- [ ] **Step 5: Register Settings in Plugin::init()**
   953	
   954	In `includes/class-plugin.php`, replace the comment line inside `init()` with:
   955	
   956	```php
   957			( new Settings() )->register();
   958	```
   959	
   960	- [ ] **Step 6: Commit**
   961	
   962	```bash
   963	git add includes/class-settings.php includes/class-plugin.php tests/unit/SettingsTest.php
   964	git -c commit.gpgsign=false commit -m "Add settings model with sanitization"
   965	```
   966	
   967	---
   968	
   969	### Task 4: Provider interface, registry, abstract provider, Claude provider
   970	
   971	**Files:**
   972	- Create: `includes/providers/interface-provider.php`, `includes/providers/class-registry.php`, `includes/providers/class-abstract-provider.php`, `includes/providers/class-claude-provider.php`
   973	- Test: `tests/unit/ClaudeProviderTest.php`, `tests/unit/RegistryTest.php`
   974	
   975	**Interfaces:**
   976	- Consumes: `Prompt::schema()`, `Prompt::user_text()`, `Prompt::parse()`, `Settings::get_api_key()`, `Settings::get_model()`, `Settings::get( 'provider' )`.
   977	- Produces:
   978	  - `AIMS\Providers\Provider_Interface` with `describe( string $file_path, string $mime_type, string $instructions )` returning `Description_Result|WP_Error`, `test_connection()` returning `true|WP_Error`, static `get_id(): string`, `get_label(): string`, `get_known_models(): array` (model id => hint).
   979	  - `AIMS\Providers\Abstract_Provider` with constructor `( string $api_key, string $model )`, abstract `build_request( string $b64, string $mime, string $instructions ): array` returning `array( 'url' => ..., 'headers' => array, 'body' => array )`, abstract `build_ping_request(): array` (same shape), abstract `extract_text( array $data )` returning `string|WP_Error`, and helper `handle_response( $response )` returning decoded array or `WP_Error` with codes `auth_error`, `rate_limited`, `server_error`, `bad_response`.
   980	  - `AIMS\Providers\Registry` with `ids(): array`, `labels(): array`, `known_models( string $id ): array`, `make( string $id )` returning `Provider_Interface|WP_Error`, `active()` returning `Provider_Interface|WP_Error`.
   981	  - `AIMS\Providers\Claude_Provider`.
   982	
   983	- [ ] **Step 1: Write the failing Claude provider test**
   984	
   985	`tests/unit/ClaudeProviderTest.php`:
   986	
   987	```php
   988	<?php
   989	namespace AIMS\Tests;
   990	
   991	use AIMS\Description_Result;
   992	use AIMS\Providers\Claude_Provider;
   993	use Brain\Monkey;
   994	use Brain\Monkey\Functions;
   995	use PHPUnit\Framework\TestCase;
   996	
   997	class ClaudeProviderTest extends TestCase {
   998		private $file;
   999	
  1000		protected function setUp(): void {
  1001			parent::setUp();
  1002			Monkey\setUp();
  1003			Functions\when( '__' )->returnArg( 1 );
  1004			Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
  1005			Functions\when( 'wp_remote_retrieve_response_code' )->alias( function ( $r ) { return $r['response']['code']; } );
  1006			Functions\when( 'wp_remote_retrieve_body' )->alias( function ( $r ) { return $r['body']; } );
  1007			$this->file = tempnam( sys_get_temp_dir(), 'aims' );
  1008			file_put_contents( $this->file, 'fakejpegbytes' );
  1009		}
  1010	
  1011		protected function tearDown(): void {
  1012			unlink( $this->file );
  1013			Monkey\tearDown();
  1014			parent::tearDown();
  1015		}
  1016	
  1017		private function response( int $code, array $body ): array {
  1018			return array( 'response' => array( 'code' => $code ), 'body' => json_encode( $body ) );
  1019		}
  1020	
  1021		public function test_builds_request_per_spec_and_parses_success() {
  1022			$captured = null;
  1023			Functions\expect( 'wp_remote_post' )->once()->andReturnUsing(
  1024				function ( $url, $args ) use ( &$captured ) {
  1025					$captured = array( 'url' => $url, 'args' => $args );
  1026					return $this->response( 200, array(
  1027						'content'     => array( array( 'type' => 'text', 'text' => '{"description":"A woman.","tags":["woman"],"alt":"A woman."}' ) ),
  1028						'stop_reason' => 'end_turn',
  1029					) );
  1030				}
  1031			);
  1032	
  1033			$provider = new Claude_Provider( 'sk-ant', 'claude-opus-5' );
  1034			$result   = $provider->describe( $this->file, 'image/jpeg', 'INSTRUCTIONS' );
  1035	
  1036			$this->assertInstanceOf( Description_Result::class, $result );
  1037			$this->assertSame( 'https://api.anthropic.com/v1/messages', $captured['url'] );
  1038			$this->assertSame( 'sk-ant', $captured['args']['headers']['x-api-key'] );
  1039			$this->assertSame( '2023-06-01', $captured['args']['headers']['anthropic-version'] );
  1040			$this->assertSame( 60, $captured['args']['timeout'] );
  1041	
  1042			$body = json_decode( $captured['args']['body'], true );
  1043			$this->assertSame( 'claude-opus-5', $body['model'] );
  1044			$this->assertSame( 'INSTRUCTIONS', $body['system'] );
  1045			$this->assertSame( 'image', $body['messages'][0]['content'][0]['type'] );
  1046			$this->assertSame( 'base64', $body['messages'][0]['content'][0]['source']['type'] );
  1047			$this->assertSame( 'image/jpeg', $body['messages'][0]['content'][0]['source']['media_type'] );
  1048			$this->assertSame( base64_encode( 'fakejpegbytes' ), $body['messages'][0]['content'][0]['source']['data'] );
  1049			$this->assertSame( 'Describe this image.', $body['messages'][0]['content'][1]['text'] );
  1050			$this->assertSame( 'json_schema', $body['output_config']['format']['type'] );
  1051			$this->assertFalse( $body['output_config']['format']['schema']['additionalProperties'] );
  1052		}
  1053	
  1054		public function test_maps_http_errors() {
  1055			$cases = array(
  1056				array( 401, 'auth_error' ),
  1057				array( 403, 'auth_error' ),
  1058				array( 429, 'rate_limited' ),
  1059				array( 500, 'server_error' ),
  1060				array( 529, 'server_error' ),
  1061				array( 400, 'bad_response' ),
  1062			);
  1063			foreach ( $cases as list( $code, $expected ) ) {
  1064				Functions\when( 'wp_remote_post' )->justReturn( $this->response( $code, array( 'error' => array( 'type' => 'x', 'message' => 'boom' ) ) ) );
  1065				$result = ( new Claude_Provider( 'k', 'm' ) )->describe( $this->file, 'image/png', 'i' );
  1066				$this->assertInstanceOf( \WP_Error::class, $result, "code $code" );
  1067				$this->assertSame( $expected, $result->get_error_code(), "code $code" );
  1068				$this->assertSame( 'boom', $result->get_error_message(), "code $code" );
  1069			}
  1070		}
  1071	
  1072		public function test_transport_error_is_server_error() {
  1073			Functions\when( 'wp_remote_post' )->justReturn( new \WP_Error( 'http_request_failed', 'cURL timeout' ) );
  1074			$result = ( new Claude_Provider( 'k', 'm' ) )->describe( $this->file, 'image/png', 'i' );
  1075			$this->assertSame( 'server_error', $result->get_error_code() );
  1076			$this->assertSame( 'cURL timeout', $result->get_error_message() );
  1077		}
  1078	
  1079		public function test_refusal_stop_reason() {
  1080			Functions\when( 'wp_remote_post' )->justReturn( $this->response( 200, array( 'content' => array(), 'stop_reason' => 'refusal' ) ) );
  1081			$result = ( new Claude_Provider( 'k', 'm' ) )->describe( $this->file, 'image/png', 'i' );
  1082			$this->assertSame( 'refused', $result->get_error_code() );
  1083		}
  1084	
  1085		public function test_missing_api_key() {
  1086			Functions\expect( 'wp_remote_post' )->never();
  1087			$result = ( new Claude_Provider( '', 'm' ) )->describe( $this->file, 'image/png', 'i' );
  1088			$this->assertSame( 'auth_error', $result->get_error_code() );
  1089		}
  1090	
  1091		public function test_unreadable_file() {
  1092			Functions\expect( 'wp_remote_post' )->never();
  1093			$result = ( new Claude_Provider( 'k', 'm' ) )->describe( '/nope/missing.jpg', 'image/jpeg', 'i' );
  1094			$this->assertSame( 'bad_file', $result->get_error_code() );
  1095		}
  1096	
  1097		public function test_test_connection_ok() {
  1098			Functions\when( 'wp_remote_post' )->justReturn( $this->response( 200, array( 'content' => array( array( 'type' => 'text', 'text' => 'OK' ) ), 'stop_reason' => 'end_turn' ) ) );
  1099			$this->assertTrue( ( new Claude_Provider( 'k', 'm' ) )->test_connection() );
  1100		}
  1101	
  1102		public function test_known_models_not_empty() {
  1103			$models = Claude_Provider::get_known_models();
  1104			$this->assertArrayHasKey( 'claude-opus-5', $models );
  1105			$this->assertSame( 'claude', Claude_Provider::get_id() );
  1106		}
  1107	}
  1108	```
  1109	
  1110	- [ ] **Step 2: Write the failing Registry test**
  1111	
  1112	`tests/unit/RegistryTest.php`:
  1113	
  1114	```php
  1115	<?php
  1116	namespace AIMS\Tests;
  1117	
  1118	use AIMS\Providers\Claude_Provider;
  1119	use AIMS\Providers\Registry;
  1120	use Brain\Monkey;
  1121	use Brain\Monkey\Functions;
  1122	use PHPUnit\Framework\TestCase;
  1123	
  1124	class RegistryTest extends TestCase {
  1125		protected function setUp(): void {
  1126			parent::setUp();
  1127			Monkey\setUp();
  1128			Functions\when( '__' )->returnArg( 1 );
  1129		}
  1130	
  1131		protected function tearDown(): void {
  1132			Monkey\tearDown();
  1133			parent::tearDown();
  1134		}
  1135	
  1136		public function test_ids_and_labels() {
  1137			$this->assertSame( array( 'claude', 'openai', 'gemini' ), Registry::ids() );
  1138			$this->assertArrayHasKey( 'gemini', Registry::labels() );
  1139		}
  1140	
  1141		public function test_make_returns_provider_with_settings() {
  1142			Functions\when( 'get_option' )->justReturn( array( 'api_keys' => array( 'claude' => 'k' ), 'models' => array( 'claude' => 'claude-sonnet-5' ) ) );
  1143			$provider = Registry::make( 'claude' );
  1144			$this->assertInstanceOf( Claude_Provider::class, $provider );
  1145		}
  1146	
  1147		public function test_make_unknown_id_is_error() {
  1148			$this->assertInstanceOf( \WP_Error::class, Registry::make( 'nope' ) );
  1149		}
  1150	
  1151		public function test_known_models_falls_back_to_empty() {
  1152			$this->assertSame( array(), Registry::known_models( 'nope' ) );
  1153		}
  1154	}
  1155	```
  1156	
  1157	- [ ] **Step 3: Run the tests to verify they fail**
  1158	
  1159	Run: `vendor/bin/phpunit --filter 'ClaudeProviderTest|RegistryTest'`
  1160	Expected: Errors, classes not found.
  1161	
  1162	- [ ] **Step 4: Write the interface**
  1163	
  1164	`includes/providers/interface-provider.php`:
  1165	
  1166	```php
  1167	<?php
  1168	/**
  1169	 * Contract every vision provider implements.
  1170	 *
  1171	 * @package AIMS
  1172	 */
  1173	
  1174	namespace AIMS\Providers;
  1175	
  1176	defined( 'ABSPATH' ) || exit;
  1177	
  1178	interface Provider_Interface {
  1179		/**
  1180		 * Describe one image file.
  1181		 *
  1182		 * @param string $file_path    Absolute path of a JPEG/PNG/GIF/WebP file.
  1183		 * @param string $mime_type    Mime type of that file.
  1184		 * @param string $instructions System instruction text from Prompt::instructions().
  1185		 * @return \AIMS\Description_Result|\WP_Error
  1186		 */
  1187		public function describe( string $file_path, string $mime_type, string $instructions );
  1188	
  1189		/**
  1190		 * Cheap text-only request that proves the key and model work.
  1191		 *
  1192		 * @return true|\WP_Error
  1193		 */
  1194		public function test_connection();
  1195	
  1196		public static function get_id(): string;
  1197	
  1198		public static function get_label(): string;
  1199	
  1200		/**
  1201		 * @return array<string,string> model id => short hint shown in the settings dropdown.
  1202		 */
  1203		public static function get_known_models(): array;
  1204	}
  1205	```
  1206	
  1207	- [ ] **Step 5: Write the abstract provider**
  1208	
  1209	`includes/providers/class-abstract-provider.php`:
  1210	
  1211	```php
  1212	<?php
  1213	/**
  1214	 * Shared HTTP plumbing and error mapping for providers.
  1215	 *
  1216	 * @package AIMS
  1217	 */
  1218	
  1219	namespace AIMS\Providers;
  1220	
  1221	use AIMS\Prompt;
  1222	
  1223	defined( 'ABSPATH' ) || exit;
  1224	
  1225	abstract class Abstract_Provider implements Provider_Interface {
  1226		const TIMEOUT = 60;
  1227	
  1228		/** @var string */
  1229		protected $api_key;
  1230	
  1231		/** @var string */
  1232		protected $model;
  1233	
  1234		public function __construct( string $api_key, string $model ) {
  1235			$this->api_key = $api_key;
  1236			$this->model   = $model;
  1237		}
  1238	
  1239		/**
  1240		 * @return array{url:string,headers:array,body:array}
  1241		 */
  1242		abstract protected function build_request( string $b64, string $mime, string $instructions ): array;
  1243	
  1244		/**
  1245		 * @return array{url:string,headers:array,body:array}
  1246		 */
  1247		abstract protected function build_ping_request(): array;
  1248	
  1249		/**
  1250		 * Pull the JSON text out of a decoded successful response.
  1251		 *
  1252		 * @return string|\WP_Error
  1253		 */
  1254		abstract protected function extract_text( array $data );
  1255	
  1256		public function describe( string $file_path, string $mime_type, string $instructions ) {
  1257			if ( '' === $this->api_key ) {
  1258				return new \WP_Error( 'auth_error', __( 'No API key is saved for this provider.', 'ai-media-search' ) );
  1259			}
  1260	
  1261			$bytes = is_readable( $file_path ) ? file_get_contents( $file_path ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
  1262			if ( false === $bytes || '' === $bytes ) {
  1263				return new \WP_Error( 'bad_file', __( 'The image file could not be read.', 'ai-media-search' ) );
  1264			}
  1265	
  1266			$request = $this->build_request( base64_encode( $bytes ), $mime_type, $instructions ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
  1267			$data    = $this->send( $request );
  1268			if ( is_wp_error( $data ) ) {
  1269				return $data;
  1270			}
  1271	
  1272			$text = $this->extract_text( $data );
  1273			if ( is_wp_error( $text ) ) {
  1274				return $text;
  1275			}
  1276	
  1277			return Prompt::parse( $text );
  1278		}
  1279	
  1280		public function test_connection() {
  1281			if ( '' === $this->api_key ) {
  1282				return new \WP_Error( 'auth_error', __( 'No API key is saved for this provider.', 'ai-media-search' ) );
  1283			}
  1284			$data = $this->send( $this->build_ping_request() );
  1285			if ( is_wp_error( $data ) ) {
  1286				return $data;
  1287			}
  1288			$text = $this->extract_text( $data );
  1289			return is_wp_error( $text ) ? $text : true;
  1290		}
  1291	
  1292		/**
  1293		 * @return array|\WP_Error Decoded JSON body.
  1294		 */
  1295		protected function send( array $request ) {
  1296			$response = wp_remote_post(
  1297				$request['url'],
  1298				array(
  1299					'headers' => $request['headers'],
  1300					'body'    => wp_json_encode( $request['body'] ),
  1301					'timeout' => self::TIMEOUT,
  1302				)
  1303			);
  1304			return $this->handle_response( $response );
  1305		}
  1306	
  1307		/**
  1308		 * @param array|\WP_Error $response Raw wp_remote_post result.
  1309		 * @return array|\WP_Error
  1310		 */
  1311		protected function handle_response( $response ) {
  1312			if ( is_wp_error( $response ) ) {
  1313				return new \WP_Error( 'server_error', $response->get_error_message() );
  1314			}
  1315	
  1316			$code = (int) wp_remote_retrieve_response_code( $response );
  1317			$body = (string) wp_remote_retrieve_body( $response );
  1318	
  1319			if ( 401 === $code || 403 === $code ) {
  1320				return new \WP_Error( 'auth_error', $this->error_message( $body, __( 'The API key was rejected.', 'ai-media-search' ) ) );
  1321			}
  1322			if ( 429 === $code ) {
  1323				return new \WP_Error( 'rate_limited', $this->error_message( $body, __( 'The provider rate limit was reached.', 'ai-media-search' ) ) );
  1324			}
  1325			if ( $code >= 500 ) {
  1326				return new \WP_Error( 'server_error', $this->error_message( $body, __( 'The provider returned a server error.', 'ai-media-search' ) ) );
  1327			}
  1328			if ( 200 !== $code ) {
  1329				return new \WP_Error( 'bad_response', $this->error_message( $body, sprintf( /* translators: %d: HTTP status */ __( 'Unexpected HTTP status %d.', 'ai-media-search' ), $code ) ) );
  1330			}
  1331	
  1332			$data = json_decode( $body, true );
  1333			if ( ! is_array( $data ) ) {
  1334				return new \WP_Error( 'bad_response', __( 'The provider reply was not valid JSON.', 'ai-media-search' ) );
  1335			}
  1336			return $data;
  1337		}
  1338	
  1339		/**
  1340		 * All three providers put a human message at error.message.
  1341		 */
  1342		protected function error_message( string $body, string $fallback ): string {
  1343			$data = json_decode( $body, true );
  1344			if ( is_array( $data ) && isset( $data['error']['message'] ) && is_string( $data['error']['message'] ) ) {
  1345				return $data['error']['message'];
  1346			}
  1347			return $fallback;
  1348		}
  1349	}
  1350	```
  1351	
  1352	- [ ] **Step 6: Write the Claude provider**
  1353	
  1354	`includes/providers/class-claude-provider.php`:
  1355	
  1356	```php
  1357	<?php
  1358	/**
  1359	 * Anthropic Claude Messages API provider.
  1360	 *
  1361	 * @package AIMS
  1362	 */
  1363	
  1364	namespace AIMS\Providers;
  1365	
  1366	use AIMS\Prompt;
  1367	
  1368	defined( 'ABSPATH' ) || exit;
  1369	
  1370	final class Claude_Provider extends Abstract_Provider {
  1371		const ENDPOINT = 'https://api.anthropic.com/v1/messages';
  1372	
  1373		public static function get_id(): string {
  1374			return 'claude';
  1375		}
  1376	
  1377		public static function get_label(): string {
  1378			return __( 'Anthropic Claude', 'ai-media-search' );
  1379		}
  1380	
  1381		public static function get_known_models(): array {
  1382			return array(
  1383				'claude-opus-5'   => __( 'Highest quality, about $0.015 per image', 'ai-media-search' ),
  1384				'claude-sonnet-5' => __( 'Balanced, about $0.006 per image', 'ai-media-search' ),
  1385				'claude-haiku-4-5' => __( 'Fastest and cheapest, about $0.003 per image', 'ai-media-search' ),
  1386			);
  1387		}
  1388	
  1389		private function headers(): array {
  1390			return array(
  1391				'x-api-key'         => $this->api_key,
  1392				'anthropic-version' => '2023-06-01',
  1393				'content-type'      => 'application/json',
  1394			);
  1395		}
  1396	
  1397		protected function build_request( string $b64, string $mime, string $instructions ): array {
  1398			return array(
  1399				'url'     => self::ENDPOINT,
  1400				'headers' => $this->headers(),
  1401				'body'    => array(
  1402					'model'         => $this->model,
  1403					'max_tokens'    => 1024,
  1404					'system'        => $instructions,
  1405					'messages'      => array(
  1406						array(
  1407							'role'    => 'user',
  1408							'content' => array(
  1409								array(
  1410									'type'   => 'image',
  1411									'source' => array(
  1412										'type'       => 'base64',
  1413										'media_type' => $mime,
  1414										'data'       => $b64,
  1415									),
  1416								),
  1417								array(
  1418									'type' => 'text',
  1419									'text' => Prompt::user_text(),
  1420								),
  1421							),
  1422						),
  1423					),
  1424					'output_config' => array(
  1425						'format' => array(
  1426							'type'   => 'json_schema',
  1427							'schema' => Prompt::schema(),
  1428						),
  1429					),
  1430				),
  1431			);
  1432		}
  1433	
  1434		protected function build_ping_request(): array {
  1435			return array(
  1436				'url'     => self::ENDPOINT,
  1437				'headers' => $this->headers(),
  1438				'body'    => array(
  1439					'model'      => $this->model,
  1440					'max_tokens' => 16,
  1441					'messages'   => array(
  1442						array(
  1443							'role'    => 'user',
  1444							'content' => 'Reply with the word OK.',
  1445						),
  1446					),
  1447				),
  1448			);
  1449		}
  1450	
  1451		protected function extract_text( array $data ) {
  1452			if ( 'refusal' === ( $data['stop_reason'] ?? '' ) ) {
  1453				return new \WP_Error( 'refused', __( 'The model declined to describe this image.', 'ai-media-search' ) );
  1454			}
  1455			foreach ( (array) ( $data['content'] ?? array() ) as $block ) {
  1456				if ( isset( $block['type'], $block['text'] ) && 'text' === $block['type'] ) {
  1457					return (string) $block['text'];
  1458				}
  1459			}
  1460			return new \WP_Error( 'bad_response', __( 'The reply contained no text.', 'ai-media-search' ) );
  1461		}
  1462	}
  1463	```
  1464	
  1465	- [ ] **Step 7: Write the Registry**
  1466	
  1467	`includes/providers/class-registry.php`:
  1468	
  1469	```php
  1470	<?php
  1471	/**
  1472	 * Knows every provider and builds them from settings.
  1473	 *
  1474	 * @package AIMS
  1475	 */
  1476	
  1477	namespace AIMS\Providers;
  1478	
  1479	use AIMS\Settings;
  1480	
  1481	defined( 'ABSPATH' ) || exit;
  1482	
  1483	final class Registry {
  1484		/**
  1485		 * @return array<string,class-string<Provider_Interface>>
  1486		 */
  1487		public static function classes(): array {
  1488			return array(
  1489				'claude' => Claude_Provider::class,
  1490				'openai' => OpenAI_Provider::class,
  1491				'gemini' => Gemini_Provider::class,
  1492			);
  1493		}
  1494	
  1495		public static function ids(): array {
  1496			return array_keys( self::classes() );
  1497		}
  1498	
  1499		/**
  1500		 * @return array<string,string> id => label
  1501		 */
  1502		public static function labels(): array {
  1503			$labels = array();
  1504			foreach ( self::classes() as $id => $class ) {
  1505				$labels[ $id ] = class_exists( $class ) ? $class::get_label() : $id;
  1506			}
  1507			return $labels;
  1508		}
  1509	
  1510		public static function known_models( string $id ): array {
  1511			$classes = self::classes();
  1512			if ( ! isset( $classes[ $id ] ) || ! class_exists( $classes[ $id ] ) ) {
  1513				return array();
  1514			}
  1515			return $classes[ $id ]::get_known_models();
  1516		}
  1517	
  1518		/**
  1519		 * @return Provider_Interface|\WP_Error
  1520		 */
  1521		public static function make( string $id ) {
  1522			$classes = self::classes();
  1523			if ( ! isset( $classes[ $id ] ) || ! class_exists( $classes[ $id ] ) ) {
  1524				return new \WP_Error( 'bad_provider', __( 'Unknown AI provider.', 'ai-media-search' ) );
  1525			}
  1526			$class = $classes[ $id ];
  1527			return new $class( Settings::get_api_key( $id ), Settings::get_model( $id ) );
  1528		}
  1529	
  1530		/**
  1531		 * @return Provider_Interface|\WP_Error
  1532		 */
  1533		public static function active() {
  1534			return self::make( (string) Settings::get( 'provider' ) );
  1535		}
  1536	}
  1537	```
  1538	
  1539	`OpenAI_Provider` and `Gemini_Provider` do not exist yet; `class_exists` guards keep the registry usable until Tasks 5 and 6 add them.
  1540	
  1541	- [ ] **Step 8: Run the tests to verify they pass**
  1542	
  1543	Run: `vendor/bin/phpunit --filter 'ClaudeProviderTest|RegistryTest'`
  1544	Expected: OK (12 tests).
  1545	
  1546	- [ ] **Step 9: Run the whole suite and lint**
  1547	
  1548	Run: `vendor/bin/phpunit && for f in includes/providers/*.php; do php -l "$f"; done`
  1549	Expected: all green, no syntax errors.
  1550	
  1551	- [ ] **Step 10: Commit**
  1552	
  1553	```bash
  1554	git add includes/providers tests/unit/ClaudeProviderTest.php tests/unit/RegistryTest.php
  1555	git -c commit.gpgsign=false commit -m "Add provider interface, registry, and Claude provider"
  1556	```
  1557	
  1558	---
  1559	
  1560	### Task 5: OpenAI provider
  1561	
  1562	**Files:**
  1563	- Create: `includes/providers/class-openai-provider.php`
  1564	- Test: `tests/unit/OpenAIProviderTest.php`
  1565	
  1566	**Interfaces:**
  1567	- Consumes: `Abstract_Provider`, `Prompt`.
  1568	- Produces: `AIMS\Providers\OpenAI_Provider` with id `openai`.
  1569	
  1570	- [ ] **Step 1: Write the failing test**
  1571	
  1572	`tests/unit/OpenAIProviderTest.php`:
  1573	
  1574	```php
  1575	<?php
  1576	namespace AIMS\Tests;
  1577	
  1578	use AIMS\Description_Result;
  1579	use AIMS\Providers\OpenAI_Provider;
  1580	use Brain\Monkey;
  1581	use Brain\Monkey\Functions;
  1582	use PHPUnit\Framework\TestCase;
  1583	
  1584	class OpenAIProviderTest extends TestCase {
  1585		private $file;
  1586	
  1587		protected function setUp(): void {
  1588			parent::setUp();
  1589			Monkey\setUp();
  1590			Functions\when( '__' )->returnArg( 1 );
  1591			Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
  1592			Functions\when( 'wp_remote_retrieve_response_code' )->alias( function ( $r ) { return $r['response']['code']; } );
  1593			Functions\when( 'wp_remote_retrieve_body' )->alias( function ( $r ) { return $r['body']; } );
  1594			$this->file = tempnam( sys_get_temp_dir(), 'aims' );
  1595			file_put_contents( $this->file, 'pngbytes' );
  1596		}
  1597	
  1598		protected function tearDown(): void {
  1599			unlink( $this->file );
  1600			Monkey\tearDown();
  1601			parent::tearDown();
  1602		}
  1603	
  1604		private function response( int $code, array $body ): array {
  1605			return array( 'response' => array( 'code' => $code ), 'body' => json_encode( $body ) );
  1606		}
  1607	
  1608		private function success_body(): array {
  1609			return array(
  1610				'status' => 'completed',
  1611				'output' => array(
  1612					array( 'type' => 'reasoning', 'summary' => array() ),
  1613					array(
  1614						'type'    => 'message',
  1615						'content' => array(
  1616							array( 'type' => 'output_text', 'text' => '{"description":"A cat.","tags":["cat"],"alt":"A cat."}' ),
  1617						),
  1618					),
  1619				),
  1620			);
  1621		}
  1622	
  1623		public function test_builds_request_per_spec_and_parses_success() {
  1624			$captured = null;
  1625			Functions\expect( 'wp_remote_post' )->once()->andReturnUsing(
  1626				function ( $url, $args ) use ( &$captured ) {
  1627					$captured = array( 'url' => $url, 'args' => $args );
  1628					return $this->response( 200, $this->success_body() );
  1629				}
  1630			);
  1631	
  1632			$result = ( new OpenAI_Provider( 'sk-openai', 'gpt-5.6-terra' ) )->describe( $this->file, 'image/png', 'INSTR' );
  1633			$this->assertInstanceOf( Description_Result::class, $result );
  1634	
  1635			$this->assertSame( 'https://api.openai.com/v1/responses', $captured['url'] );
  1636			$this->assertSame( 'Bearer sk-openai', $captured['args']['headers']['Authorization'] );
  1637	
  1638			$body = json_decode( $captured['args']['body'], true );
  1639			$this->assertSame( 'gpt-5.6-terra', $body['model'] );
  1640			$this->assertSame( 'INSTR', $body['instructions'] );
  1641			$this->assertSame( 'input_text', $body['input'][0]['content'][0]['type'] );
  1642			$this->assertSame( 'input_image', $body['input'][0]['content'][1]['type'] );
  1643			$this->assertSame( 'data:image/png;base64,' . base64_encode( 'pngbytes' ), $body['input'][0]['content'][1]['image_url'] );
  1644			$this->assertSame( 'auto', $body['input'][0]['content'][1]['detail'] );
  1645			$this->assertSame( 'json_schema', $body['text']['format']['type'] );
  1646			$this->assertSame( 'media_description', $body['text']['format']['name'] );
  1647			$this->assertTrue( $body['text']['format']['strict'] );
  1648			$this->assertSame( array( 'description', 'tags', 'alt' ), $body['text']['format']['schema']['required'] );
  1649		}
  1650	
  1651		public function test_refusal_content_item() {
  1652			$body = array(
  1653				'status' => 'completed',
  1654				'output' => array( array( 'type' => 'message', 'content' => array( array( 'type' => 'refusal', 'refusal' => 'no' ) ) ) ),
  1655			);
  1656			Functions\when( 'wp_remote_post' )->justReturn( $this->response( 200, $body ) );
  1657			$result = ( new OpenAI_Provider( 'k', 'm' ) )->describe( $this->file, 'image/png', 'i' );
  1658			$this->assertSame( 'refused', $result->get_error_code() );
  1659		}
  1660	
  1661		public function test_incomplete_status_is_bad_response() {
  1662			$body = array( 'status' => 'incomplete', 'output' => array() );
  1663			Functions\when( 'wp_remote_post' )->justReturn( $this->response( 200, $body ) );
  1664			$result = ( new OpenAI_Provider( 'k', 'm' ) )->describe( $this->file, 'image/png', 'i' );
  1665			$this->assertSame( 'bad_response', $result->get_error_code() );
  1666		}
  1667	
  1668		public function test_http_401_maps_to_auth_error() {
  1669			Functions\when( 'wp_remote_post' )->justReturn( $this->response( 401, array( 'error' => array( 'message' => 'bad key' ) ) ) );
  1670			$result = ( new OpenAI_Provider( 'k', 'm' ) )->describe( $this->file, 'image/png', 'i' );
  1671			$this->assertSame( 'auth_error', $result->get_error_code() );
  1672			$this->assertSame( 'bad key', $result->get_error_message() );
  1673		}
  1674	
  1675		public function test_ping_uses_text_only_input() {
  1676			$captured = null;
  1677			Functions\expect( 'wp_remote_post' )->once()->andReturnUsing(
  1678				function ( $url, $args ) use ( &$captured ) {
  1679					$captured = json_decode( $args['body'], true );
  1680					return $this->response( 200, array( 'status' => 'completed', 'output' => array( array( 'type' => 'message', 'content' => array( array( 'type' => 'output_text', 'text' => 'OK' ) ) ) ) ) );
  1681				}
  1682			);
  1683			$this->assertTrue( ( new OpenAI_Provider( 'k', 'm' ) )->test_connection() );
  1684			$this->assertSame( 'Reply with the word OK.', $captured['input'] );
  1685			$this->assertSame( 16, $captured['max_output_tokens'] );
  1686		}
  1687	
  1688		public function test_known_models() {
  1689			$this->assertArrayHasKey( 'gpt-5.6-terra', OpenAI_Provider::get_known_models() );
  1690			$this->assertSame( 'openai', OpenAI_Provider::get_id() );
  1691		}
  1692	}
  1693	```
  1694	
  1695	- [ ] **Step 2: Run the test to verify it fails**
  1696	
  1697	Run: `vendor/bin/phpunit --filter OpenAIProviderTest`
  1698	Expected: Error, class not found.
  1699	
  1700	- [ ] **Step 3: Write the OpenAI provider**
  1701	
  1702	`includes/providers/class-openai-provider.php`:
  1703	
  1704	```php
  1705	<?php
  1706	/**
  1707	 * OpenAI Responses API provider.
  1708	 *
  1709	 * @package AIMS
  1710	 */
  1711	
  1712	namespace AIMS\Providers;
  1713	
  1714	use AIMS\Prompt;
  1715	
  1716	defined( 'ABSPATH' ) || exit;
  1717	
  1718	final class OpenAI_Provider extends Abstract_Provider {
  1719		const ENDPOINT = 'https://api.openai.com/v1/responses';
  1720	
  1721		public static function get_id(): string {
  1722			return 'openai';
  1723		}
  1724	
  1725		public static function get_label(): string {
  1726			return __( 'OpenAI', 'ai-media-search' );
  1727		}
  1728	
  1729		public static function get_known_models(): array {
  1730			return array(
  1731				'gpt-5.6-terra' => __( 'Balanced, about $0.007 per image', 'ai-media-search' ),
  1732				'gpt-5.6-luna'  => __( 'Cheapest, about $0.001 per image', 'ai-media-search' ),
  1733				'gpt-5.6-sol'   => __( 'High quality, about $0.012 per image', 'ai-media-search' ),
  1734				'gpt-6-astra'   => __( 'Flagship, about $0.03 per image', 'ai-media-search' ),
  1735			);
  1736		}
  1737	
  1738		private function headers(): array {
  1739			return array(
  1740				'Authorization' => 'Bearer ' . $this->api_key,
  1741				'Content-Type'  => 'application/json',
  1742			);
  1743		}
  1744	
  1745		protected function build_request( string $b64, string $mime, string $instructions ): array {
  1746			return array(
  1747				'url'     => self::ENDPOINT,
  1748				'headers' => $this->headers(),
  1749				'body'    => array(
  1750					'model'             => $this->model,
  1751					'instructions'      => $instructions,
  1752					'max_output_tokens' => 1024,
  1753					'input'             => array(
  1754						array(
  1755							'role'    => 'user',
  1756							'content' => array(
  1757								array(
  1758									'type' => 'input_text',
  1759									'text' => Prompt::user_text(),
  1760								),
  1761								array(
  1762									'type'      => 'input_image',
  1763									'image_url' => 'data:' . $mime . ';base64,' . $b64,
  1764									'detail'    => 'auto',
  1765								),
  1766							),
  1767						),
  1768					),
  1769					'text'              => array(
  1770						'format' => array(
  1771							'type'   => 'json_schema',
  1772							'name'   => 'media_description',
  1773							'schema' => Prompt::schema(),
  1774							'strict' => true,
  1775						),
  1776					),
  1777				),
  1778			);
  1779		}
  1780	
  1781		protected function build_ping_request(): array {
  1782			return array(
  1783				'url'     => self::ENDPOINT,
  1784				'headers' => $this->headers(),
  1785				'body'    => array(
  1786					'model'             => $this->model,
  1787					'input'             => 'Reply with the word OK.',
  1788					'max_output_tokens' => 16,
  1789				),
  1790			);
  1791		}
  1792	
  1793		protected function extract_text( array $data ) {
  1794			if ( 'incomplete' === ( $data['status'] ?? '' ) ) {
  1795				return new \WP_Error( 'bad_response', __( 'The reply was cut off before it finished.', 'ai-media-search' ) );
  1796			}
  1797			foreach ( (array) ( $data['output'] ?? array() ) as $item ) {
  1798				if ( 'message' !== ( $item['type'] ?? '' ) ) {
  1799					continue;
  1800				}
  1801				foreach ( (array) ( $item['content'] ?? array() ) as $part ) {
  1802					$type = $part['type'] ?? '';
  1803					if ( 'refusal' === $type ) {
  1804						return new \WP_Error( 'refused', __( 'The model declined to describe this image.', 'ai-media-search' ) );
  1805					}
  1806					if ( 'output_text' === $type && isset( $part['text'] ) ) {
  1807						return (string) $part['text'];
  1808					}
  1809				}
  1810			}
  1811			return new \WP_Error( 'bad_response', __( 'The reply contained no text.', 'ai-media-search' ) );
  1812		}
  1813	}
  1814	```
  1815	
  1816	- [ ] **Step 4: Run the test to verify it passes**
  1817	
  1818	Run: `vendor/bin/phpunit --filter OpenAIProviderTest`
  1819	Expected: OK (6 tests).
  1820	
  1821	- [ ] **Step 5: Commit**
  1822	
  1823	```bash
  1824	git add includes/providers/class-openai-provider.php tests/unit/OpenAIProviderTest.php
  1825	git -c commit.gpgsign=false commit -m "Add OpenAI provider"
  1826	```
  1827	
  1828	---
  1829	
  1830	### Task 6: Gemini provider
  1831	
  1832	**Files:**
  1833	- Create: `includes/providers/class-gemini-provider.php`
  1834	- Test: `tests/unit/GeminiProviderTest.php`
  1835	
  1836	**Interfaces:**
  1837	- Consumes: `Abstract_Provider`, `Prompt`.
  1838	- Produces: `AIMS\Providers\Gemini_Provider` with id `gemini`.
  1839	
  1840	- [ ] **Step 1: Write the failing test**
  1841	
  1842	`tests/unit/GeminiProviderTest.php`:
  1843	
  1844	```php
  1845	<?php
  1846	namespace AIMS\Tests;
  1847	
  1848	use AIMS\Description_Result;
  1849	use AIMS\Providers\Gemini_Provider;
  1850	use Brain\Monkey;
  1851	use Brain\Monkey\Functions;
  1852	use PHPUnit\Framework\TestCase;
  1853	
  1854	class GeminiProviderTest extends TestCase {
  1855		private $file;
  1856	
  1857		protected function setUp(): void {
  1858			parent::setUp();
  1859			Monkey\setUp();
  1860			Functions\when( '__' )->returnArg( 1 );
  1861			Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
  1862			Functions\when( 'wp_remote_retrieve_response_code' )->alias( function ( $r ) { return $r['response']['code']; } );
  1863			Functions\when( 'wp_remote_retrieve_body' )->alias( function ( $r ) { return $r['body']; } );
  1864			$this->file = tempnam( sys_get_temp_dir(), 'aims' );
  1865			file_put_contents( $this->file, 'webpbytes' );
  1866		}
  1867	
  1868		protected function tearDown(): void {
  1869			unlink( $this->file );
  1870			Monkey\tearDown();
  1871			parent::tearDown();
  1872		}
  1873	
  1874		private function response( int $code, array $body ): array {
  1875			return array( 'response' => array( 'code' => $code ), 'body' => json_encode( $body ) );
  1876		}
  1877	
  1878		public function test_builds_request_per_spec_and_parses_success() {
  1879			$captured = null;
  1880			Functions\expect( 'wp_remote_post' )->once()->andReturnUsing(
  1881				function ( $url, $args ) use ( &$captured ) {
  1882					$captured = array( 'url' => $url, 'args' => $args );
  1883					return $this->response( 200, array(
  1884						'candidates' => array(
  1885							array(
  1886								'finishReason' => 'STOP',
  1887								'content'      => array( 'parts' => array( array( 'text' => '{"description":"A dog.","tags":["dog"],"alt":"A dog."}' ) ) ),
  1888							),
  1889						),
  1890					) );
  1891				}
  1892			);
  1893	
  1894			$result = ( new Gemini_Provider( 'g-key', 'gemini-3.8-flash' ) )->describe( $this->file, 'image/webp', 'INSTR' );
  1895			$this->assertInstanceOf( Description_Result::class, $result );
  1896	
  1897			$this->assertSame( 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.8-flash:generateContent', $captured['url'] );
  1898			$this->assertSame( 'g-key', $captured['args']['headers']['x-goog-api-key'] );
  1899	
  1900			$body = json_decode( $captured['args']['body'], true );
  1901			$this->assertSame( 'INSTR', $body['systemInstruction']['parts'][0]['text'] );
  1902			$this->assertSame( 'image/webp', $body['contents'][0]['parts'][0]['inline_data']['mime_type'] );
  1903			$this->assertSame( base64_encode( 'webpbytes' ), $body['contents'][0]['parts'][0]['inline_data']['data'] );
  1904			$this->assertSame( 'Describe this image.', $body['contents'][0]['parts'][1]['text'] );
  1905			$this->assertSame( 'application/json', $body['generationConfig']['responseMimeType'] );
  1906			$this->assertSame( 'object', $body['generationConfig']['responseSchema']['type'] );
  1907		}
  1908	
  1909		public function test_safety_finish_reason_is_refused() {
  1910			$body = array( 'candidates' => array( array( 'finishReason' => 'SAFETY', 'content' => array( 'parts' => array() ) ) ) );
  1911			Functions\when( 'wp_remote_post' )->justReturn( $this->response( 200, $body ) );
  1912			$result = ( new Gemini_Provider( 'k', 'm' ) )->describe( $this->file, 'image/webp', 'i' );
  1913			$this->assertSame( 'refused', $result->get_error_code() );
  1914		}
  1915	
  1916		public function test_prompt_block_is_refused() {
  1917			$body = array( 'promptFeedback' => array( 'blockReason' => 'SAFETY' ) );
  1918			Functions\when( 'wp_remote_post' )->justReturn( $this->response( 200, $body ) );
  1919			$result = ( new Gemini_Provider( 'k', 'm' ) )->describe( $this->file, 'image/webp', 'i' );
  1920			$this->assertSame( 'refused', $result->get_error_code() );
  1921		}
  1922	
  1923		public function test_http_429_maps_to_rate_limited() {
  1924			Functions\when( 'wp_remote_post' )->justReturn( $this->response( 429, array( 'error' => array( 'message' => 'quota' ) ) ) );
  1925			$result = ( new Gemini_Provider( 'k', 'm' ) )->describe( $this->file, 'image/webp', 'i' );
  1926			$this->assertSame( 'rate_limited', $result->get_error_code() );
  1927			$this->assertSame( 'quota', $result->get_error_message() );
  1928		}
  1929	
  1930		public function test_model_id_is_url_encoded() {
  1931			$captured = null;
  1932			Functions\expect( 'wp_remote_post' )->once()->andReturnUsing(
  1933				function ( $url ) use ( &$captured ) {
  1934					$captured = $url;
  1935					return $this->response( 200, array( 'candidates' => array( array( 'finishReason' => 'STOP', 'content' => array( 'parts' => array( array( 'text' => 'OK' ) ) ) ) ) ) );
  1936				}
  1937			);
  1938			$this->assertTrue( ( new Gemini_Provider( 'k', 'weird model' ) )->test_connection() );
  1939			$this->assertStringContainsString( 'models/weird%20model:generateContent', $captured );
  1940		}
  1941	
  1942		public function test_known_models() {
  1943			$this->assertArrayHasKey( 'gemini-3.8-flash', Gemini_Provider::get_known_models() );
  1944			$this->assertSame( 'gemini', Gemini_Provider::get_id() );
  1945		}
  1946	}
  1947	```
  1948	
  1949	- [ ] **Step 2: Run the test to verify it fails**
  1950	
  1951	Run: `vendor/bin/phpunit --filter GeminiProviderTest`
  1952	Expected: Error, class not found.
  1953	
  1954	- [ ] **Step 3: Write the Gemini provider**
  1955	
  1956	`includes/providers/class-gemini-provider.php`:
  1957	
  1958	```php
  1959	<?php
  1960	/**
  1961	 * Google Gemini generateContent provider.
  1962	 *
  1963	 * @package AIMS
  1964	 */
  1965	
  1966	namespace AIMS\Providers;
  1967	
  1968	use AIMS\Prompt;
  1969	
  1970	defined( 'ABSPATH' ) || exit;
  1971	
  1972	final class Gemini_Provider extends Abstract_Provider {
  1973		const ENDPOINT_BASE = 'https://generativelanguage.googleapis.com/v1beta/models/';
  1974	
  1975		public static function get_id(): string {
  1976			return 'gemini';
  1977		}
  1978	
  1979		public static function get_label(): string {
  1980			return __( 'Google Gemini', 'ai-media-search' );
  1981		}
  1982	
  1983		public static function get_known_models(): array {
  1984			return array(
  1985				'gemini-3.8-flash'      => __( 'Latest Flash, about $0.002 per image', 'ai-media-search' ),
  1986				'gemini-3.5-flash-lite' => __( 'Flash-Lite, about $0.001 per image', 'ai-media-search' ),
  1987				'gemini-2.5-flash'      => __( 'Previous Flash, about $0.001 per image', 'ai-media-search' ),
  1988				'gemini-2.5-flash-lite' => __( 'Cheapest, under $0.001 per image', 'ai-media-search' ),
  1989				'gemini-2.5-pro'        => __( 'Pro, about $0.005 per image', 'ai-media-search' ),
  1990			);
  1991		}
  1992	
  1993		private function url(): string {
  1994			return self::ENDPOINT_BASE . rawurlencode( $this->model ) . ':generateContent';
  1995		}
  1996	
  1997		private function headers(): array {
  1998			return array(
  1999				'x-goog-api-key' => $this->api_key,
  2000				'Content-Type'   => 'application/json',
  2001			);
  2002		}
  2003	
  2004		protected function build_request( string $b64, string $mime, string $instructions ): array {
  2005			return array(
  2006				'url'     => $this->url(),
  2007				'headers' => $this->headers(),
  2008				'body'    => array(
  2009					'systemInstruction' => array(
  2010						'parts' => array( array( 'text' => $instructions ) ),
  2011					),
  2012					'contents'          => array(
  2013						array(
  2014							'parts' => array(
  2015								array(
  2016									'inline_data' => array(
  2017										'mime_type' => $mime,
  2018										'data'      => $b64,
  2019									),
  2020								),
  2021								array( 'text' => Prompt::user_text() ),
  2022							),
  2023						),
  2024					),
  2025					'generationConfig'  => array(
  2026						'responseMimeType' => 'application/json',
  2027						'responseSchema'   => Prompt::schema(),
  2028					),
  2029				),
  2030			);
  2031		}
  2032	
  2033		protected function build_ping_request(): array {
  2034			return array(
  2035				'url'     => $this->url(),
  2036				'headers' => $this->headers(),
  2037				'body'    => array(
  2038					'contents'         => array(
  2039						array( 'parts' => array( array( 'text' => 'Reply with the word OK.' ) ) ),
  2040					),
  2041					'generationConfig' => array( 'maxOutputTokens' => 16 ),
  2042				),
  2043			);
  2044		}
  2045	
  2046		protected function extract_text( array $data ) {
  2047			if ( empty( $data['candidates'] ) ) {
  2048				if ( ! empty( $data['promptFeedback']['blockReason'] ) ) {
  2049					return new \WP_Error( 'refused', __( 'The model declined to describe this image.', 'ai-media-search' ) );
  2050				}
  2051				return new \WP_Error( 'bad_response', __( 'The reply contained no candidates.', 'ai-media-search' ) );
  2052			}
  2053	
  2054			$candidate = $data['candidates'][0];
  2055			if ( 'SAFETY' === ( $candidate['finishReason'] ?? '' ) ) {
  2056				return new \WP_Error( 'refused', __( 'The model declined to describe this image.', 'ai-media-search' ) );
  2057			}
  2058	
  2059			foreach ( (array) ( $candidate['content']['parts'] ?? array() ) as $part ) {
  2060				if ( isset( $part['text'] ) && is_string( $part['text'] ) ) {
  2061					return $part['text'];
  2062				}
  2063			}
  2064			return new \WP_Error( 'bad_response', __( 'The reply contained no text.', 'ai-media-search' ) );
  2065		}
  2066	}
  2067	```
  2068	
  2069	- [ ] **Step 4: Run the test to verify it passes**
  2070	
  2071	Run: `vendor/bin/phpunit --filter GeminiProviderTest`
  2072	Expected: OK (6 tests).
  2073	
  2074	- [ ] **Step 5: Run the whole suite**
  2075	
  2076	Run: `vendor/bin/phpunit`
  2077	Expected: all green. `RegistryTest::test_ids_and_labels` still passes and `Registry::labels()` now returns three real labels.
  2078	
  2079	- [ ] **Step 6: Commit**
  2080	
  2081	```bash
  2082	git add includes/providers/class-gemini-provider.php tests/unit/GeminiProviderTest.php
  2083	git -c commit.gpgsign=false commit -m "Add Gemini provider"
  2084	```
  2085	
  2086	---
  2087	
  2088	### Task 7: Image preparer
  2089	
  2090	**Files:**
  2091	- Create: `includes/class-image-preparer.php`
  2092	- Test: `tests/unit/ImagePreparerTest.php`
  2093	
  2094	**Interfaces:**
  2095	- Produces: `AIMS\Image_Preparer` with `const IMAGE_MIMES`, `const PDF_MIME = 'application/pdf'`, `const MIN_EDGE = 1000`, `const MAX_EDGE = 1600`, static `is_eligible_mime( string $mime ): bool`, static `choose_size( array $metadata ): ?string` (pure; returns a size key, `'full'`, or `null` meaning "must resize"), instance `prepare( int $attachment_id )` returning `array( 'path' => string, 'mime' => string, 'temporary' => bool )` or `WP_Error` with code `unsupported`, `no_preview`, `no_file`, or `resize_failed`; instance `cleanup( array $prepared ): void`.
  2096	
  2097	- [ ] **Step 1: Write the failing test**
  2098	
  2099	`tests/unit/ImagePreparerTest.php`:
  2100	
  2101	```php
  2102	<?php
  2103	namespace AIMS\Tests;
  2104	
  2105	use AIMS\Image_Preparer;
  2106	use Brain\Monkey;
  2107	use Brain\Monkey\Functions;
  2108	use PHPUnit\Framework\TestCase;
  2109	
  2110	class ImagePreparerTest extends TestCase {
  2111		protected function setUp(): void {
  2112			parent::setUp();
  2113			Monkey\setUp();
  2114			Functions\when( '__' )->returnArg( 1 );
  2115			Functions\when( 'path_join' )->alias( function ( $a, $b ) { return rtrim( $a, '/' ) . '/' . $b; } );
  2116			Functions\when( 'get_temp_dir' )->justReturn( sys_get_temp_dir() . '/' );
  2117		}
  2118	
  2119		protected function tearDown(): void {
  2120			Monkey\tearDown();
  2121			parent::tearDown();
  2122		}
  2123	
  2124		public function test_eligible_mimes() {
  2125			$this->assertTrue( Image_Preparer::is_eligible_mime( 'image/jpeg' ) );
  2126			$this->assertTrue( Image_Preparer::is_eligible_mime( 'image/webp' ) );
  2127			$this->assertTrue( Image_Preparer::is_eligible_mime( 'application/pdf' ) );
  2128			$this->assertFalse( Image_Preparer::is_eligible_mime( 'image/svg+xml' ) );
  2129			$this->assertFalse( Image_Preparer::is_eligible_mime( 'video/mp4' ) );
  2130		}
  2131	
  2132		public function test_choose_size_prefers_smallest_size_over_min_edge() {
  2133			$meta = array(
  2134				'width'  => 4000,
  2135				'height' => 3000,
  2136				'sizes'  => array(
  2137					'thumbnail'    => array( 'width' => 150, 'height' => 150 ),
  2138					'medium'       => array( 'width' => 300, 'height' => 225 ),
  2139					'medium_large' => array( 'width' => 768, 'height' => 576 ),
  2140					'large'        => array( 'width' => 1024, 'height' => 768 ),
  2141					'1536x1536'    => array( 'width' => 1536, 'height' => 1152 ),
  2142				),
  2143			);
  2144			$this->assertSame( 'large', Image_Preparer::choose_size( $meta ) );
  2145		}
  2146	
  2147		public function test_choose_size_uses_full_when_original_is_small_enough() {
  2148			$meta = array( 'width' => 1200, 'height' => 800, 'sizes' => array( 'thumbnail' => array( 'width' => 150, 'height' => 150 ) ) );
  2149			$this->assertSame( 'full', Image_Preparer::choose_size( $meta ) );
  2150		}
  2151	
  2152		public function test_choose_size_returns_null_when_resize_needed() {
  2153			$meta = array( 'width' => 5000, 'height' => 5000, 'sizes' => array( 'thumbnail' => array( 'width' => 150, 'height' => 150 ) ) );
  2154			$this->assertNull( Image_Preparer::choose_size( $meta ) );
  2155		}
  2156	
  2157		public function test_choose_size_small_image_with_no_sizes_uses_full() {
  2158			$this->assertSame( 'full', Image_Preparer::choose_size( array( 'width' => 300, 'height' => 200 ) ) );
  2159		}
  2160	
  2161		public function test_prepare_rejects_unsupported_mime() {
  2162			Functions\when( 'get_post_mime_type' )->justReturn( 'video/mp4' );
  2163			$result = ( new Image_Preparer() )->prepare( 5 );
  2164			$this->assertSame( 'unsupported', $result->get_error_code() );
  2165		}
  2166	
  2167		public function test_prepare_image_returns_chosen_size_path() {
  2168			Functions\when( 'get_post_mime_type' )->justReturn( 'image/jpeg' );
  2169			Functions\when( 'get_attached_file' )->justReturn( '/uploads/2026/09/photo.jpg' );
  2170			Functions\when( 'wp_get_attachment_metadata' )->justReturn(
  2171				array(
  2172					'width'  => 4000,
  2173					'height' => 3000,
  2174					'sizes'  => array( 'large' => array( 'file' => 'photo-1024x768.jpg', 'width' => 1024, 'height' => 768, 'mime-type' => 'image/jpeg' ) ),
  2175				)
  2176			);
  2177			$result = ( new Image_Preparer() )->prepare( 5 );
  2178			$this->assertSame( '/uploads/2026/09/photo-1024x768.jpg', $result['path'] );
  2179			$this->assertSame( 'image/jpeg', $result['mime'] );
  2180			$this->assertFalse( $result['temporary'] );
  2181		}
  2182	
  2183		public function test_prepare_pdf_uses_generated_preview() {
  2184			Functions\when( 'get_post_mime_type' )->justReturn( 'application/pdf' );
  2185			Functions\when( 'get_attached_file' )->justReturn( '/uploads/2026/09/brochure.pdf' );
  2186			Functions\when( 'wp_get_attachment_metadata' )->justReturn(
  2187				array(
  2188					'sizes' => array(
  2189						'full'  => array( 'file' => 'brochure-pdf.jpg', 'width' => 1058, 'height' => 1497, 'mime-type' => 'image/jpeg' ),
  2190						'large' => array( 'file' => 'brochure-pdf-724x1024.jpg', 'width' => 724, 'height' => 1024, 'mime-type' => 'image/jpeg' ),
  2191					),
  2192				)
  2193			);
  2194			$result = ( new Image_Preparer() )->prepare( 7 );
  2195			$this->assertSame( '/uploads/2026/09/brochure-pdf-724x1024.jpg', $result['path'] );
  2196			$this->assertSame( 'image/jpeg', $result['mime'] );
  2197		}
  2198	
  2199		public function test_prepare_pdf_without_preview_is_no_preview() {
  2200			Functions\when( 'get_post_mime_type' )->justReturn( 'application/pdf' );
  2201			Functions\when( 'get_attached_file' )->justReturn( '/uploads/2026/09/brochure.pdf' );
  2202			Functions\when( 'wp_get_attachment_metadata' )->justReturn( array() );
  2203			$result = ( new Image_Preparer() )->prepare( 7 );
  2204			$this->assertSame( 'no_preview', $result->get_error_code() );
  2205		}
  2206	
  2207		public function test_prepare_resizes_when_no_size_fits() {
  2208			Functions\when( 'get_post_mime_type' )->justReturn( 'image/png' );
  2209			Functions\when( 'get_attached_file' )->justReturn( '/uploads/big.png' );
  2210			Functions\when( 'wp_get_attachment_metadata' )->justReturn( array( 'width' => 6000, 'height' => 4000, 'sizes' => array() ) );
  2211	
  2212			$editor = new class() {
  2213				public $resized;
  2214				public function resize( $w, $h, $crop ) { $this->resized = array( $w, $h, $crop ); return true; }
  2215				public function save( $dest ) { return array( 'path' => $dest, 'mime-type' => 'image/png' ); }
  2216			};
  2217			Functions\when( 'wp_get_image_editor' )->justReturn( $editor );
  2218	
  2219			$result = ( new Image_Preparer() )->prepare( 9 );
  2220			$this->assertTrue( $result['temporary'] );
  2221			$this->assertSame( 'image/png', $result['mime'] );
  2222			$this->assertStringContainsString( 'aims-9', $result['path'] );
  2223			$this->assertSame( array( 1600, 1600, false ), $editor->resized );
  2224		}
  2225	
  2226		public function test_prepare_resize_failure() {
  2227			Functions\when( 'get_post_mime_type' )->justReturn( 'image/png' );
  2228			Functions\when( 'get_attached_file' )->justReturn( '/uploads/big.png' );
  2229			Functions\when( 'wp_get_attachment_metadata' )->justReturn( array( 'width' => 6000, 'height' => 4000 ) );
  2230			Functions\when( 'wp_get_image_editor' )->justReturn( new \WP_Error( 'image_no_editor', 'no editor' ) );
  2231			$result = ( new Image_Preparer() )->prepare( 9 );
  2232			$this->assertSame( 'resize_failed', $result->get_error_code() );
  2233		}
  2234	
  2235		public function test_cleanup_removes_only_temporary_files() {
  2236			$tmp = tempnam( sys_get_temp_dir(), 'aims' );
  2237			( new Image_Preparer() )->cleanup( array( 'path' => $tmp, 'mime' => 'image/png', 'temporary' => true ) );
  2238			$this->assertFileDoesNotExist( $tmp );
  2239	
  2240			$keep = tempnam( sys_get_temp_dir(), 'aims' );
  2241			( new Image_Preparer() )->cleanup( array( 'path' => $keep, 'mime' => 'image/png', 'temporary' => false ) );
  2242			$this->assertFileExists( $keep );
  2243			unlink( $keep );
  2244		}
  2245	}
  2246	```
  2247	
  2248	- [ ] **Step 2: Run the test to verify it fails**
  2249	
  2250	Run: `vendor/bin/phpunit --filter ImagePreparerTest`
  2251	Expected: Error, class not found.
  2252	
  2253	- [ ] **Step 3: Write the Image_Preparer class**
  2254	
  2255	`includes/class-image-preparer.php`:
  2256	
  2257	```php
  2258	<?php
  2259	/**
  2260	 * Picks (or produces) a reasonably sized image file to send to a provider.
  2261	 *
  2262	 * @package AIMS
  2263	 */
  2264	
  2265	namespace AIMS;
  2266	
  2267	defined( 'ABSPATH' ) || exit;
  2268	
  2269	final class Image_Preparer {
  2270		const IMAGE_MIMES = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );
  2271		const PDF_MIME    = 'application/pdf';
  2272		const MIN_EDGE    = 1000;
  2273		const MAX_EDGE    = 1600;
  2274	
  2275		public static function is_eligible_mime( string $mime ): bool {
  2276			return self::PDF_MIME === $mime || in_array( $mime, self::IMAGE_MIMES, true );
  2277		}
  2278	
  2279		/**
  2280		 * Pure size selection.
  2281		 *
  2282		 * Returns the key of the smallest registered size whose long edge is at least
  2283		 * MIN_EDGE, or 'full' when the original fits within MAX_EDGE, or null when a
  2284		 * resize is needed.
  2285		 */
  2286		public static function choose_size( array $metadata ) {
  2287			$best_key  = null;
  2288			$best_edge = PHP_INT_MAX;
  2289	
  2290			foreach ( (array) ( $metadata['sizes'] ?? array() ) as $key => $size ) {
  2291				$edge = max( (int) ( $size['width'] ?? 0 ), (int) ( $size['height'] ?? 0 ) );
  2292				if ( $edge >= self::MIN_EDGE && $edge < $best_edge ) {
  2293					$best_key  = (string) $key;
  2294					$best_edge = $edge;
  2295				}
  2296			}
  2297			if ( null !== $best_key ) {
  2298				return $best_key;
  2299			}
  2300	
  2301			$full_edge = max( (int) ( $metadata['width'] ?? 0 ), (int) ( $metadata['height'] ?? 0 ) );
  2302			if ( $full_edge > 0 && $full_edge <= self::MAX_EDGE ) {
  2303				return 'full';
  2304			}
  2305			return null;
  2306		}
  2307	
  2308		/**
  2309		 * @return array{path:string,mime:string,temporary:bool}|\WP_Error
  2310		 */
  2311		public function prepare( int $attachment_id ) {
  2312			$mime = (string) get_post_mime_type( $attachment_id );
  2313			if ( ! self::is_eligible_mime( $mime ) ) {
  2314				return new \WP_Error( 'unsupported', __( 'Only images and PDFs can be described.', 'ai-media-search' ) );
  2315			}
  2316	
  2317			$original = (string) get_attached_file( $attachment_id );
  2318			if ( '' === $original ) {
  2319				return new \WP_Error( 'no_file', __( 'The attachment has no file.', 'ai-media-search' ) );
  2320			}
  2321			$metadata = wp_get_attachment_metadata( $attachment_id );
  2322			$metadata = is_array( $metadata ) ? $metadata : array();
  2323			$dir      = dirname( $original );
  2324	
  2325			if ( self::PDF_MIME === $mime ) {
  2326				// WordPress renders PDF previews into metadata['sizes'] when Imagick is available.
  2327				if ( empty( $metadata['sizes'] ) ) {
  2328					return new \WP_Error( 'no_preview', __( 'WordPress did not generate a preview image for this PDF.', 'ai-media-search' ) );
  2329				}
  2330				$key = self::choose_size( array( 'sizes' => $metadata['sizes'] ) );
  2331				if ( null === $key || 'full' === $key ) {
  2332					// Fall back to whichever preview size is largest.
  2333					$key = self::largest_size_key( $metadata['sizes'] );
  2334				}
  2335				$size = $metadata['sizes'][ $key ];
  2336				return array(
  2337					'path'      => path_join( $dir, (string) $size['file'] ),
  2338					'mime'      => (string) ( $size['mime-type'] ?? 'image/jpeg' ),
  2339					'temporary' => false,
  2340				);
  2341			}
  2342	
  2343			$key = self::choose_size( $metadata );
  2344			if ( 'full' === $key ) {
  2345				return array( 'path' => $original, 'mime' => $mime, 'temporary' => false );
  2346			}
  2347			if ( null !== $key && isset( $metadata['sizes'][ $key ]['file'] ) ) {
  2348				$size = $metadata['sizes'][ $key ];
  2349				return array(
  2350					'path'      => path_join( $dir, (string) $size['file'] ),
  2351					'mime'      => (string) ( $size['mime-type'] ?? $mime ),
  2352					'temporary' => false,
  2353				);
  2354			}
  2355	
  2356			return $this->resize( $attachment_id, $original, $mime );
  2357		}
  2358	
  2359		/**
  2360		 * @return array{path:string,mime:string,temporary:bool}|\WP_Error
  2361		 */
  2362		private function resize( int $attachment_id, string $original, string $mime ) {
  2363			$editor = wp_get_image_editor( $original );
  2364			if ( is_wp_error( $editor ) ) {
  2365				return new \WP_Error( 'resize_failed', $editor->get_error_message() );
  2366			}
  2367			$resized = $editor->resize( self::MAX_EDGE, self::MAX_EDGE, false );
  2368			if ( is_wp_error( $resized ) ) {
  2369				return new \WP_Error( 'resize_failed', $resized->get_error_message() );
  2370			}
  2371			$ext  = pathinfo( $original, PATHINFO_EXTENSION );
  2372			$dest = get_temp_dir() . 'aims-' . $attachment_id . '-' . wp_rand_stub() . '.' . $ext;
  2373			$saved = $editor->save( $dest );
  2374			if ( is_wp_error( $saved ) || empty( $saved['path'] ) ) {
  2375				return new \WP_Error( 'resize_failed', __( 'Could not save the resized copy.', 'ai-media-search' ) );
  2376			}
  2377			return array(
  2378				'path'      => (string) $saved['path'],
  2379				'mime'      => (string) ( $saved['mime-type'] ?? $mime ),
  2380				'temporary' => true,
  2381			);
  2382		}
  2383	
  2384		public function cleanup( array $prepared ): void {
  2385			if ( ! empty( $prepared['temporary'] ) && ! empty( $prepared['path'] ) && file_exists( $prepared['path'] ) ) {
  2386				wp_delete_file( $prepared['path'] );
  2387			}
  2388		}
  2389	
  2390		private static function largest_size_key( array $sizes ): string {
  2391			$best_key  = (string) array_key_first( $sizes );
  2392			$best_edge = 0;
  2393			foreach ( $sizes as $key => $size ) {
  2394				$edge = max( (int) ( $size['width'] ?? 0 ), (int) ( $size['height'] ?? 0 ) );
  2395				if ( $edge > $best_edge ) {
  2396					$best_edge = $edge;
  2397					$best_key  = (string) $key;
  2398				}
  2399			}
  2400			return $best_key;
  2401		}
  2402	}
  2403	```
  2404	
  2405	Two details to fix before running: replace `wp_rand_stub()` with `wp_rand( 1000, 9999 )` and stub it in the test (`Functions\when( 'wp_rand' )->justReturn( 1234 );`), and stub `wp_delete_file` in the cleanup test with `Functions\when( 'wp_delete_file' )->alias( 'unlink' );`. Add both stubs to the test's `setUp()`.
  2406	
  2407	- [ ] **Step 4: Run the test to verify it passes**
  2408	
  2409	Run: `vendor/bin/phpunit --filter ImagePreparerTest`
  2410	Expected: OK (12 tests).
  2411	
  2412	- [ ] **Step 5: Commit**
  2413	
  2414	```bash
  2415	git add includes/class-image-preparer.php tests/unit/ImagePreparerTest.php
  2416	git -c commit.gpgsign=false commit -m "Add image preparer with size selection and resize fallback"
  2417	```
  2418	
  2419	---
  2420	
  2421	### Task 8: Indexer
  2422	
  2423	**Files:**
  2424	- Create: `includes/class-indexer.php`
  2425	- Test: `tests/unit/IndexerTest.php`
  2426	
  2427	**Interfaces:**
  2428	- Consumes: `Image_Preparer::prepare()/cleanup()`, `Providers\Registry::active()`, `Provider_Interface::describe()`, `Prompt::instructions()`, `Settings::get()`, `Description_Result`.
  2429	- Produces: `AIMS\Indexer` with status constants `STATUS_PENDING`, `STATUS_INDEXED`, `STATUS_FAILED`, `STATUS_SKIPPED`; meta key constants `META_DESCRIPTION = '_aims_description'`, `META_TAGS = '_aims_tags'`, `META_ALT = '_aims_alt'`, `META_SEARCH = '_aims_search_text'`, `META_STATUS = '_aims_status'`, `META_ERROR = '_aims_error'`, `META_INDEXED_AT = '_aims_indexed_at'`, `META_PROVIDER = '_aims_provider'`, `META_RETRY = '_aims_retry_count'`; constructor `( ?Image_Preparer $preparer = null, ?callable $provider_factory = null )`; `index_attachment( int $id )` returning `true|WP_Error`; static `build_search_text( string $description, array $tags, string $alt ): string`; static `rebuild_search_text( int $id ): void`; static `payload( int $id ): array` with keys `id, description, tags, alt, status, error, indexed_at, provider`.
  2430	
  2431	- [ ] **Step 1: Write the failing test**
  2432	
  2433	`tests/unit/IndexerTest.php`:
  2434	
  2435	```php
  2436	<?php
  2437	namespace AIMS\Tests;
  2438	
  2439	use AIMS\Description_Result;
  2440	use AIMS\Image_Preparer;
  2441	use AIMS\Indexer;
  2442	use AIMS\Providers\Provider_Interface;
  2443	use Brain\Monkey;
  2444	use Brain\Monkey\Functions;
  2445	use PHPUnit\Framework\TestCase;
  2446	
  2447	class IndexerTest extends TestCase {
  2448		/** @var array<int,array<string,mixed>> */
  2449		private $meta = array();
  2450	
  2451		protected function setUp(): void {
  2452			parent::setUp();
  2453			Monkey\setUp();
  2454			Functions\when( '__' )->returnArg( 1 );
  2455			Functions\when( 'get_option' )->justReturn( array( 'provider' => 'claude', 'language' => 'English', 'custom_prompt' => '', 'fill_alt' => false ) );
  2456			Functions\when( 'get_transient' )->justReturn( false );
  2457			Functions\when( 'set_transient' )->justReturn( true );
  2458			Functions\when( 'delete_transient' )->justReturn( true );
  2459			Functions\when( 'time' )->justReturn( 1700000000 );
  2460			Functions\when( 'get_post_mime_type' )->justReturn( 'image/jpeg' );
  2461	
  2462			$meta = &$this->meta;
  2463			Functions\when( 'update_post_meta' )->alias( function ( $id, $key, $value ) use ( &$meta ) { $meta[ $id ][ $key ] = $value; return true; } );
  2464			Functions\when( 'delete_post_meta' )->alias( function ( $id, $key ) use ( &$meta ) { unset( $meta[ $id ][ $key ] ); return true; } );
  2465			Functions\when( 'get_post_meta' )->alias( function ( $id, $key, $single ) use ( &$meta ) { return $meta[ $id ][ $key ] ?? ''; } );
  2466		}
  2467	
  2468		protected function tearDown(): void {
  2469			Monkey\tearDown();
  2470			parent::tearDown();
  2471		}
  2472	
  2473		private function preparer( $return ): Image_Preparer {
  2474			$p = $this->createMock( Image_Preparer::class );
  2475			$p->method( 'prepare' )->willReturn( $return );
  2476			return $p;
  2477		}
  2478	
  2479		private function provider( $return ): Provider_Interface {
  2480			$p = $this->createMock( Provider_Interface::class );
  2481			$p->method( 'describe' )->willReturn( $return );
  2482			$p->method( 'get_id' )->willReturn( 'claude' );
  2483			return $p;
  2484		}
  2485	
  2486		public function test_success_writes_all_meta() {
  2487			$prepared = array( 'path' => '/tmp/x.jpg', 'mime' => 'image/jpeg', 'temporary' => false );
  2488			$result   = new Description_Result( 'A Woman on a beach.', array( 'woman', 'beach' ), 'Woman on a beach.' );
  2489			$provider = $this->provider( $result );
  2490			Functions\when( 'get_option' )->justReturn( array( 'provider' => 'claude', 'models' => array( 'claude' => 'claude-opus-5' ), 'language' => 'English', 'custom_prompt' => '', 'fill_alt' => false ) );
  2491	
  2492			$indexer = new Indexer( $this->preparer( $prepared ), function () use ( $provider ) { return $provider; } );
  2493			$this->assertTrue( $indexer->index_attachment( 42 ) );
  2494	
  2495			$m = $this->meta[42];
  2496			$this->assertSame( 'A Woman on a beach.', $m['_aims_description'] );
  2497			$this->assertSame( array( 'woman', 'beach' ), $m['_aims_tags'] );
  2498			$this->assertSame( 'Woman on a beach.', $m['_aims_alt'] );
  2499			$this->assertSame( 'a woman on a beach. woman beach woman on a beach.', $m['_aims_search_text'] );
  2500			$this->assertSame( 'indexed', $m['_aims_status'] );
  2501			$this->assertSame( 1700000000, $m['_aims_indexed_at'] );
  2502			$this->assertSame( 'claude:claude-opus-5', $m['_aims_provider'] );
  2503			$this->assertArrayNotHasKey( '_aims_error', $m );
  2504			$this->assertArrayNotHasKey( '_wp_attachment_image_alt', $m, 'fill_alt is off' );
  2505		}
  2506	
  2507		public function test_fill_alt_only_when_empty_and_enabled() {
  2508			Functions\when( 'get_option' )->justReturn( array( 'provider' => 'claude', 'language' => 'English', 'custom_prompt' => '', 'fill_alt' => true ) );
  2509			$prepared = array( 'path' => '/tmp/x.jpg', 'mime' => 'image/jpeg', 'temporary' => false );
  2510			$provider = $this->provider( new Description_Result( 'd', array( 't' ), 'Generated alt' ) );
  2511			$indexer  = new Indexer( $this->preparer( $prepared ), function () use ( $provider ) { return $provider; } );
  2512	
  2513			$indexer->index_attachment( 1 );
  2514			$this->assertSame( 'Generated alt', $this->meta[1]['_wp_attachment_image_alt'] );
  2515	
  2516			$this->meta[2]['_wp_attachment_image_alt'] = 'Existing';
  2517			$indexer->index_attachment( 2 );
  2518			$this->assertSame( 'Existing', $this->meta[2]['_wp_attachment_image_alt'] );
  2519		}
  2520	
  2521		public function test_unsupported_and_no_preview_mark_skipped_without_calling_provider() {
  2522			foreach ( array( 'unsupported', 'no_preview' ) as $code ) {
  2523				$provider = $this->createMock( Provider_Interface::class );
  2524				$provider->expects( $this->never() )->method( 'describe' );
  2525				$indexer = new Indexer( $this->preparer( new \WP_Error( $code, 'nope' ) ), function () use ( $provider ) { return $provider; } );
  2526				$result  = $indexer->index_attachment( 3 );
  2527				$this->assertTrue( $result );
  2528				$this->assertSame( 'skipped', $this->meta[3]['_aims_status'] );
  2529				$this->assertSame( 'nope', $this->meta[3]['_aims_error'] );
  2530			}
  2531		}
  2532	
  2533		public function test_provider_error_marks_failed_and_returns_error() {
  2534			$prepared = array( 'path' => '/tmp/x.jpg', 'mime' => 'image/jpeg', 'temporary' => false );
  2535			$provider = $this->provider( new \WP_Error( 'rate_limited', 'slow down' ) );
  2536			$indexer  = new Indexer( $this->preparer( $prepared ), function () use ( $provider ) { return $provider; } );
  2537	
  2538			$result = $indexer->index_attachment( 4 );
  2539			$this->assertInstanceOf( \WP_Error::class, $result );
  2540			$this->assertSame( 'rate_limited', $result->get_error_code() );
  2541			$this->assertSame( 'failed', $this->meta[4]['_aims_status'] );
  2542			$this->assertSame( 'slow down', $this->meta[4]['_aims_error'] );
  2543		}
  2544	
  2545		public function test_lock_prevents_double_processing() {
  2546			Functions\when( 'get_transient' )->justReturn( 1 );
  2547			$provider = $this->createMock( Provider_Interface::class );
  2548			$provider->expects( $this->never() )->method( 'describe' );
  2549			$indexer = new Indexer( $this->preparer( array() ), function () use ( $provider ) { return $provider; } );
  2550			$result  = $indexer->index_attachment( 5 );
  2551			$this->assertSame( 'locked', $result->get_error_code() );
  2552		}
  2553	
  2554		public function test_temporary_file_is_cleaned_up() {
  2555			$preparer = $this->createMock( Image_Preparer::class );
  2556			$preparer->method( 'prepare' )->willReturn( array( 'path' => '/tmp/t.jpg', 'mime' => 'image/jpeg', 'temporary' => true ) );
  2557			$preparer->expects( $this->once() )->method( 'cleanup' );
  2558			$provider = $this->provider( new Description_Result( 'd', array(), 'a' ) );
  2559			( new Indexer( $preparer, function () use ( $provider ) { return $provider; } ) )->index_attachment( 6 );
  2560		}
  2561	
  2562		public function test_build_search_text_is_lowercase_and_deduplicated_whitespace() {
  2563			$this->assertSame( 'a red car. car red vehicle red car', Indexer::build_search_text( "A Red  Car.\n", array( 'car', 'Red', 'vehicle' ), ' Red Car ' ) );
  2564		}
  2565	
  2566		public function test_payload_reads_meta() {
  2567			$this->meta[8] = array( '_aims_description' => 'd', '_aims_tags' => array( 'x' ), '_aims_status' => 'indexed', '_aims_indexed_at' => 5 );
  2568			$p = Indexer::payload( 8 );
  2569			$this->assertSame( 8, $p['id'] );
  2570			$this->assertSame( 'd', $p['description'] );
  2571			$this->assertSame( array( 'x' ), $p['tags'] );
  2572			$this->assertSame( 'indexed', $p['status'] );
  2573			$this->assertSame( '', $p['error'] );
  2574		}
  2575	}
  2576	```
  2577	
  2578	- [ ] **Step 2: Run the test to verify it fails**
  2579	
  2580	Run: `vendor/bin/phpunit --filter IndexerTest`
  2581	Expected: Error, class not found.
  2582	
  2583	- [ ] **Step 3: Write the Indexer class**
  2584	
  2585	`includes/class-indexer.php`:
  2586	
  2587	```php
  2588	<?php
  2589	/**
  2590	 * Runs prepare -> provider -> meta for one attachment.
  2591	 *
  2592	 * @package AIMS
  2593	 */
  2594	
  2595	namespace AIMS;
  2596	
  2597	use AIMS\Providers\Registry;
  2598	
  2599	defined( 'ABSPATH' ) || exit;
  2600	
  2601	final class Indexer {
  2602		const STATUS_PENDING = 'pending';
  2603		const STATUS_INDEXED = 'indexed';
  2604		const STATUS_FAILED  = 'failed';
  2605		const STATUS_SKIPPED = 'skipped';
  2606	
  2607		const META_DESCRIPTION = '_aims_description';
  2608		const META_TAGS        = '_aims_tags';
  2609		const META_ALT         = '_aims_alt';
  2610		const META_SEARCH      = '_aims_search_text';
  2611		const META_STATUS      = '_aims_status';
  2612		const META_ERROR       = '_aims_error';
  2613		const META_INDEXED_AT  = '_aims_indexed_at';
  2614		const META_PROVIDER    = '_aims_provider';
  2615		const META_RETRY       = '_aims_retry_count';
  2616	
  2617		const LOCK_TTL = 120;
  2618	
  2619		/** @var Image_Preparer */
  2620		private $preparer;
  2621	
  2622		/** @var callable Returns Provider_Interface|WP_Error. */
  2623		private $provider_factory;
  2624	
  2625		public function __construct( ?Image_Preparer $preparer = null, ?callable $provider_factory = null ) {
  2626			$this->preparer         = $preparer ?: new Image_Preparer();
  2627			$this->provider_factory = $provider_factory ?: array( Registry::class, 'active' );
  2628		}
  2629	
  2630		/**
  2631		 * @return true|\WP_Error
  2632		 */
  2633		public function index_attachment( int $id ) {
  2634			$lock = 'aims_lock_' . $id;
  2635			if ( get_transient( $lock ) ) {
  2636				return new \WP_Error( 'locked', __( 'This file is already being processed.', 'ai-media-search' ) );
  2637			}
  2638			set_transient( $lock, 1, self::LOCK_TTL );
  2639	
  2640			try {
  2641				return $this->run( $id );
  2642			} finally {
  2643				delete_transient( $lock );
  2644				Stats_Cache::clear();
  2645			}
  2646		}
  2647	
  2648		/**
  2649		 * @return true|\WP_Error
  2650		 */
  2651		private function run( int $id ) {
  2652			update_post_meta( $id, self::META_STATUS, self::STATUS_PENDING );
  2653	
  2654			$prepared = $this->preparer->prepare( $id );
  2655			if ( is_wp_error( $prepared ) ) {
  2656				if ( in_array( $prepared->get_error_code(), array( 'unsupported', 'no_preview' ), true ) ) {
  2657					$this->mark( $id, self::STATUS_SKIPPED, $prepared->get_error_message() );
  2658					return true;
  2659				}
  2660				$this->mark( $id, self::STATUS_FAILED, $prepared->get_error_message() );
  2661				return $prepared;
  2662			}
  2663	
  2664			$provider = call_user_func( $this->provider_factory );
  2665			if ( is_wp_error( $provider ) ) {
  2666				$this->preparer->cleanup( $prepared );
  2667				$this->mark( $id, self::STATUS_FAILED, $provider->get_error_message() );
  2668				return $provider;
  2669			}
  2670	
  2671			$instructions = Prompt::instructions( (string) Settings::get( 'language' ), (string) Settings::get( 'custom_prompt' ) );
  2672			$result       = $provider->describe( $prepared['path'], $prepared['mime'], $instructions );
  2673			$this->preparer->cleanup( $prepared );
  2674	
  2675			if ( is_wp_error( $result ) ) {
  2676				$this->mark( $id, self::STATUS_FAILED, $result->get_error_message() );
  2677				return $result;
  2678			}
  2679	
  2680			update_post_meta( $id, self::META_DESCRIPTION, $result->description );
  2681			update_post_meta( $id, self::META_TAGS, $result->tags );
  2682			update_post_meta( $id, self::META_ALT, $result->alt );
  2683			update_post_meta( $id, self::META_SEARCH, self::build_search_text( $result->description, $result->tags, $result->alt ) );
  2684			update_post_meta( $id, self::META_INDEXED_AT, time() );
  2685			update_post_meta( $id, self::META_PROVIDER, $provider::get_id() . ':' . Settings::get_model( $provider::get_id() ) );
  2686			delete_post_meta( $id, self::META_ERROR );
  2687			delete_post_meta( $id, self::META_RETRY );
  2688			update_post_meta( $id, self::META_STATUS, self::STATUS_INDEXED );
  2689	
  2690			if ( Settings::get( 'fill_alt' ) && '' !== $result->alt ) {
  2691				$existing = (string) get_post_meta( $id, '_wp_attachment_image_alt', true );
  2692				if ( '' === trim( $existing ) ) {
  2693					update_post_meta( $id, '_wp_attachment_image_alt', $result->alt );
  2694				}
  2695			}
  2696	
  2697			return true;
  2698		}
  2699	
  2700		private function mark( int $id, string $status, string $error ): void {
  2701			update_post_meta( $id, self::META_STATUS, $status );
  2702			update_post_meta( $id, self::META_ERROR, $error );
  2703		}
  2704	
  2705		public static function build_search_text( string $description, array $tags, string $alt ): string {
  2706			$text = $description . ' ' . implode( ' ', $tags ) . ' ' . $alt;
  2707			$text = strtolower( $text );
  2708			$text = preg_replace( '/\s+/u', ' ', $text );
  2709			return trim( (string) $text );
  2710		}
  2711	
  2712		public static function rebuild_search_text( int $id ): void {
  2713			$description = (string) get_post_meta( $id, self::META_DESCRIPTION, true );
  2714			$tags        = get_post_meta( $id, self::META_TAGS, true );
  2715			$alt         = (string) get_post_meta( $id, self::META_ALT, true );
  2716			update_post_meta( $id, self::META_SEARCH, self::build_search_text( $description, is_array( $tags ) ? $tags : array(), $alt ) );
  2717		}
  2718	
  2719		public static function payload( int $id ): array {
  2720			$tags = get_post_meta( $id, self::META_TAGS, true );
  2721			return array(
  2722				'id'          => $id,
  2723				'description' => (string) get_post_meta( $id, self::META_DESCRIPTION, true ),
  2724				'tags'        => is_array( $tags ) ? array_values( $tags ) : array(),
  2725				'alt'         => (string) get_post_meta( $id, self::META_ALT, true ),
  2726				'status'      => (string) get_post_meta( $id, self::META_STATUS, true ),
  2727				'error'       => (string) get_post_meta( $id, self::META_ERROR, true ),
  2728				'indexed_at'  => (int) get_post_meta( $id, self::META_INDEXED_AT, true ),
  2729				'provider'    => (string) get_post_meta( $id, self::META_PROVIDER, true ),
  2730			);
  2731		}
  2732	}
  2733	```
  2734	
  2735	`Stats_Cache::clear()` is a one-line helper so the indexer does not depend on the full Stats class (Task 11). Create `includes/class-stats-cache.php` now:
  2736	
  2737	```php
  2738	<?php
  2739	/**
  2740	 * Transient cache for dashboard counts.
  2741	 *
  2742	 * @package AIMS
  2743	 */
  2744	
  2745	namespace AIMS;
  2746	
  2747	defined( 'ABSPATH' ) || exit;
  2748	
  2749	final class Stats_Cache {
  2750		const KEY = 'aims_stats';
  2751		const TTL = 60;
  2752	
  2753		public static function clear(): void {
  2754			delete_transient( self::KEY );
  2755		}
  2756	}
  2757	```
  2758	
  2759	- [ ] **Step 4: Run the test to verify it passes**
  2760	
  2761	Run: `vendor/bin/phpunit --filter IndexerTest`
  2762	Expected: OK (8 tests). The provider mock's `get_id()` is static on the interface; PHPUnit mocks of static methods are not called through the instance, so in `run()` the line `$provider::get_id()` resolves against the mock class. If PHPUnit refuses to mock the static method, change the test helper to use an anonymous class implementing `Provider_Interface` instead of `createMock`:
  2763	
  2764	```php
  2765	private function provider( $return ): Provider_Interface {
  2766		return new class( $return ) implements Provider_Interface {
  2767			private $return;
  2768			public function __construct( $return ) { $this->return = $return; }
  2769			public function describe( string $a, string $b, string $c ) { return $this->return; }
  2770			public function test_connection() { return true; }
  2771			public static function get_id(): string { return 'claude'; }
  2772			public static function get_label(): string { return 'Claude'; }
  2773			public static function get_known_models(): array { return array(); }
  2774		};
  2775	}
  2776	```
  2777	
  2778	and for the "never called" cases assert on a flag set inside `describe()` instead of `expects( $this->never() )`.
  2779	
  2780	- [ ] **Step 5: Commit**
  2781	
  2782	```bash
  2783	git add includes/class-indexer.php includes/class-stats-cache.php tests/unit/IndexerTest.php
  2784	git -c commit.gpgsign=false commit -m "Add indexer that stores AI descriptions in post meta"
  2785	```
  2786	
  2787	---
  2788	
  2789	### Task 9: Queue (cron on upload, retry once)
  2790	
  2791	**Files:**
  2792	- Create: `includes/class-queue.php`
  2793	- Modify: `includes/class-plugin.php`
  2794	- Test: `tests/unit/QueueTest.php`
  2795	
  2796	**Interfaces:**
  2797	- Consumes: `Indexer::index_attachment()`, `Indexer::META_RETRY`, `Image_Preparer::is_eligible_mime()`, `Settings::get( 'auto_index' )`.
  2798	- Produces: `AIMS\Queue` with `const HOOK = 'aims_index_attachment'`, `register()`, `on_add_attachment( int $id )`, static `schedule( int $id, int $delay = 10 ): bool`, `handle( int $id )`, static `clear_all()`.
  2799	
  2800	- [ ] **Step 1: Write the failing test**
  2801	
  2802	`tests/unit/QueueTest.php`:
  2803	
  2804	```php
  2805	<?php
  2806	namespace AIMS\Tests;
  2807	
  2808	use AIMS\Indexer;
  2809	use AIMS\Queue;
  2810	use Brain\Monkey;
  2811	use Brain\Monkey\Functions;
  2812	use PHPUnit\Framework\TestCase;
  2813	
  2814	class QueueTest extends TestCase {
  2815		protected function setUp(): void {
  2816			parent::setUp();
  2817			Monkey\setUp();
  2818			Functions\when( 'time' )->justReturn( 1000 );
  2819		}
  2820	
  2821		protected function tearDown(): void {
  2822			Monkey\tearDown();
  2823			parent::tearDown();
  2824		}
  2825	
  2826		public function test_schedule_adds_single_event_once() {
  2827			Functions\expect( 'wp_next_scheduled' )->once()->with( Queue::HOOK, array( 7 ) )->andReturn( false );
  2828			Functions\expect( 'wp_schedule_single_event' )->once()->with( 1010, Queue::HOOK, array( 7 ) )->andReturn( true );
  2829			$this->assertTrue( Queue::schedule( 7 ) );
  2830		}
  2831	
  2832		public function test_schedule_skips_when_already_queued() {
  2833			Functions\when( 'wp_next_scheduled' )->justReturn( 2000 );
  2834			Functions\expect( 'wp_schedule_single_event' )->never();
  2835			$this->assertFalse( Queue::schedule( 7 ) );
  2836		}
  2837	
  2838		public function test_on_add_attachment_respects_setting_and_mime() {
  2839			Functions\when( 'get_option' )->justReturn( array( 'auto_index' => false ) );
  2840			Functions\expect( 'wp_schedule_single_event' )->never();
  2841			( new Queue() )->on_add_attachment( 1 );
  2842	
  2843			Functions\when( 'get_option' )->justReturn( array( 'auto_index' => true ) );
  2844			Functions\when( 'get_post_mime_type' )->justReturn( 'video/mp4' );
  2845			( new Queue() )->on_add_attachment( 2 );
  2846		}
  2847	
  2848		public function test_on_add_attachment_schedules_eligible_upload() {
  2849			Functions\when( 'get_option' )->justReturn( array( 'auto_index' => true ) );
  2850			Functions\when( 'get_post_mime_type' )->justReturn( 'image/png' );
  2851			Functions\when( 'wp_next_scheduled' )->justReturn( false );
  2852			Functions\expect( 'wp_schedule_single_event' )->once()->with( 1010, Queue::HOOK, array( 3 ) );
  2853			( new Queue() )->on_add_attachment( 3 );
  2854		}
  2855	
  2856		public function test_handle_retries_once_on_retryable_error() {
  2857			$indexer = $this->createMock( Indexer::class );
  2858			$indexer->method( 'index_attachment' )->willReturn( new \WP_Error( 'rate_limited', 'x' ) );
  2859			Functions\when( 'get_post_meta' )->justReturn( '' );
  2860			Functions\expect( 'update_post_meta' )->once()->with( 9, Indexer::META_RETRY, 1 );
  2861			Functions\when( 'wp_next_scheduled' )->justReturn( false );
  2862			Functions\expect( 'wp_schedule_single_event' )->once()->with( 1300, Queue::HOOK, array( 9 ) );
  2863			( new Queue( $indexer ) )->handle( 9 );
  2864		}
  2865	
  2866		public function test_handle_does_not_retry_twice_or_on_non_retryable() {
  2867			$indexer = $this->createMock( Indexer::class );
  2868			$indexer->method( 'index_attachment' )->willReturn( new \WP_Error( 'rate_limited', 'x' ) );
  2869			Functions\when( 'get_post_meta' )->justReturn( 1 );
  2870			Functions\expect( 'wp_schedule_single_event' )->never();
  2871			( new Queue( $indexer ) )->handle( 9 );
  2872	
  2873			$indexer2 = $this->createMock( Indexer::class );
  2874			$indexer2->method( 'index_attachment' )->willReturn( new \WP_Error( 'auth_error', 'x' ) );
  2875			Functions\when( 'get_post_meta' )->justReturn( '' );
  2876			( new Queue( $indexer2 ) )->handle( 10 );
  2877		}
  2878	}
  2879	```
  2880	
  2881	- [ ] **Step 2: Run the test to verify it fails**
  2882	
  2883	Run: `vendor/bin/phpunit --filter QueueTest`
  2884	Expected: Error, class not found.
  2885	
  2886	- [ ] **Step 3: Write the Queue class**
  2887	
  2888	`includes/class-queue.php`:
  2889	
  2890	```php
  2891	<?php
  2892	/**
  2893	 * Schedules indexing on upload through WP-Cron.
  2894	 *
  2895	 * @package AIMS
  2896	 */
  2897	
  2898	namespace AIMS;
  2899	
  2900	defined( 'ABSPATH' ) || exit;
  2901	
  2902	final class Queue {
  2903		const HOOK        = 'aims_index_attachment';
  2904		const DELAY       = 10;
  2905		const RETRY_DELAY = 300;
  2906		const RETRYABLE   = array( 'rate_limited', 'server_error' );
  2907	
  2908		/** @var Indexer */
  2909		private $indexer;
  2910	
  2911		public function __construct( ?Indexer $indexer = null ) {
  2912			$this->indexer = $indexer ?: new Indexer();
  2913		}
  2914	
  2915		public function register(): void {
  2916			add_action( 'add_attachment', array( $this, 'on_add_attachment' ) );
  2917			add_action( self::HOOK, array( $this, 'handle' ) );
  2918		}
  2919	
  2920		public function on_add_attachment( int $id ): void {
  2921			if ( ! Settings::get( 'auto_index' ) ) {
  2922				return;
  2923			}
  2924			if ( ! Image_Preparer::is_eligible_mime( (string) get_post_mime_type( $id ) ) ) {
  2925				return;
  2926			}
  2927			self::schedule( $id );
  2928		}
  2929	
  2930		public static function schedule( int $id, int $delay = self::DELAY ): bool {
  2931			if ( wp_next_scheduled( self::HOOK, array( $id ) ) ) {
  2932				return false;
  2933			}
  2934			return (bool) wp_schedule_single_event( time() + $delay, self::HOOK, array( $id ) );
  2935		}
  2936	
  2937		public function handle( int $id ): void {
  2938			$result = $this->indexer->index_attachment( $id );
  2939			if ( ! is_wp_error( $result ) ) {
  2940				return;
  2941			}
  2942			if ( ! in_array( $result->get_error_code(), self::RETRYABLE, true ) ) {
  2943				return;
  2944			}
  2945			$retries = (int) get_post_meta( $id, Indexer::META_RETRY, true );
  2946			if ( $retries >= 1 ) {
  2947				return;
  2948			}
  2949			update_post_meta( $id, Indexer::META_RETRY, $retries + 1 );
  2950			self::schedule( $id, self::RETRY_DELAY );
  2951		}
  2952	
  2953		public static function clear_all(): void {
  2954			wp_unschedule_hook( self::HOOK );
  2955		}
  2956	}
  2957	```
  2958	
  2959	- [ ] **Step 4: Register the queue in Plugin::init()**
  2960	
  2961	In `includes/class-plugin.php`, after `( new Settings() )->register();` add:
  2962	
  2963	```php
  2964			( new Queue() )->register();
  2965	```
  2966	
  2967	- [ ] **Step 5: Run the test to verify it passes**
  2968	
  2969	Run: `vendor/bin/phpunit --filter QueueTest`
  2970	Expected: OK (6 tests).
  2971	
  2972	- [ ] **Step 6: Commit**
  2973	
  2974	```bash
  2975	git add includes/class-queue.php includes/class-plugin.php tests/unit/QueueTest.php
  2976	git -c commit.gpgsign=false commit -m "Add cron queue for indexing new uploads"
  2977	```
  2978	
  2979	---
  2980	
  2981	### Task 10: Search filters
  2982	
  2983	**Files:**
  2984	- Create: `includes/class-search.php`
  2985	- Modify: `includes/class-plugin.php`
  2986	- Test: `tests/unit/SearchTest.php`
  2987	
  2988	**Interfaces:**
  2989	- Consumes: `Indexer::META_SEARCH`.
  2990	- Produces: `AIMS\Search` with `const ALIAS = 'aims_st'`, `register()`, `join( string $join, $query ): string`, `search( string $search, $query ): string`, static `applies( $query ): bool` (any object with a `get( string )` method), static `rewrite( string $search, string $posts_table ): string` (pure).
  2991	
  2992	- [ ] **Step 1: Write the failing test**
  2993	
  2994	`tests/unit/SearchTest.php`:
  2995	
  2996	```php
  2997	<?php
  2998	namespace AIMS\Tests;
  2999	
  3000	use AIMS\Search;
  3001	use Brain\Monkey;
  3002	use PHPUnit\Framework\TestCase;
  3003	
  3004	class SearchTest extends TestCase {
  3005		protected function setUp(): void {
  3006			parent::setUp();
  3007			Monkey\setUp();
  3008			$GLOBALS['wpdb'] = new class() {
  3009				public $posts    = 'wp_posts';
  3010				public $postmeta = 'wp_postmeta';
  3011			};
  3012		}
  3013	
  3014		protected function tearDown(): void {
  3015			unset( $GLOBALS['wpdb'] );
  3016			Monkey\tearDown();
  3017			parent::tearDown();
  3018		}
  3019	
  3020		private function query( $post_type, string $s ) {
  3021			return new class( $post_type, $s ) {
  3022				private $vars;
  3023				public function __construct( $post_type, $s ) { $this->vars = array( 'post_type' => $post_type, 's' => $s ); }
  3024				public function get( $key ) { return $this->vars[ $key ] ?? ''; }
  3025			};
  3026		}
  3027	
  3028		public function test_applies_only_to_attachment_searches() {
  3029			$this->assertTrue( Search::applies( $this->query( 'attachment', 'woman' ) ) );
  3030			$this->assertTrue( Search::applies( $this->query( array( 'attachment' ), 'woman' ) ) );
  3031			$this->assertFalse( Search::applies( $this->query( 'attachment', '  ' ) ) );
  3032			$this->assertFalse( Search::applies( $this->query( 'post', 'woman' ) ) );
  3033			$this->assertFalse( Search::applies( $this->query( array( 'post', 'attachment' ), 'woman' ) ) );
  3034		}
  3035	
  3036		public function test_rewrite_single_term() {
  3037			$in  = " AND (((wp_posts.post_title LIKE '{a1b2}woman{a1b2}') OR (wp_posts.post_excerpt LIKE '{a1b2}woman{a1b2}') OR (wp_posts.post_content LIKE '{a1b2}woman{a1b2}')))";
  3038			$out = Search::rewrite( $in, 'wp_posts' );
  3039			$this->assertStringContainsString( "(wp_posts.post_title LIKE '{a1b2}woman{a1b2}' OR aims_st.meta_value LIKE '{a1b2}woman{a1b2}')", $out );
  3040			$this->assertSame( 1, substr_count( $out, 'aims_st.meta_value' ) );
  3041		}
  3042	
  3043		public function test_rewrite_multi_term_keeps_and_structure() {
  3044			$in  = " AND (((wp_posts.post_title LIKE '{x}red{x}') OR (wp_posts.post_content LIKE '{x}red{x}')) AND ((wp_posts.post_title LIKE '{x}car{x}') OR (wp_posts.post_content LIKE '{x}car{x}')))";
  3045			$out = Search::rewrite( $in, 'wp_posts' );
  3046			$this->assertSame( 2, substr_count( $out, 'aims_st.meta_value' ) );
  3047			$this->assertStringContainsString( "AND ((wp_posts.post_title LIKE '{x}car{x}' OR aims_st.meta_value LIKE '{x}car{x}')", $out );
  3048		}
  3049	
  3050		public function test_rewrite_handles_escaped_quote_in_term() {
  3051			$in  = " AND (((wp_posts.post_title LIKE '{x}o\\'neil{x}') OR (wp_posts.post_content LIKE '{x}o\\'neil{x}')))";
  3052			$out = Search::rewrite( $in, 'wp_posts' );
  3053			$this->assertStringContainsString( "OR aims_st.meta_value LIKE '{x}o\\'neil{x}')", $out );
  3054		}
  3055	
  3056		public function test_join_added_once_and_only_for_attachment_search() {
  3057			$search = new Search();
  3058			$join   = $search->join( '', $this->query( 'attachment', 'x' ) );
  3059			$this->assertStringContainsString( "LEFT JOIN wp_postmeta AS aims_st ON (wp_posts.ID = aims_st.post_id AND aims_st.meta_key = '_aims_search_text')", $join );
  3060			$this->assertSame( $join, $search->join( $join, $this->query( 'attachment', 'x' ) ), 'no double join' );
  3061			$this->assertSame( '', $search->join( '', $this->query( 'post', 'x' ) ) );
  3062		}
  3063	
  3064		public function test_search_filter_untouched_for_other_queries() {
  3065			$search = new Search();
  3066			$sql    = " AND ((wp_posts.post_title LIKE '{x}a{x}'))";
  3067			$this->assertSame( $sql, $search->search( $sql, $this->query( 'post', 'a' ) ) );
  3068		}
  3069	}
  3070	```
  3071	
  3072	- [ ] **Step 2: Run the test to verify it fails**
  3073	
  3074	Run: `vendor/bin/phpunit --filter SearchTest`
  3075	Expected: Error, class not found.
  3076	
  3077	- [ ] **Step 3: Write the Search class**
  3078	
  3079	`includes/class-search.php`:
  3080	
  3081	```php
  3082	<?php
  3083	/**
  3084	 * Extends every attachment search to the AI search text meta.
  3085	 *
  3086	 * @package AIMS
  3087	 */
  3088	
  3089	namespace AIMS;
  3090	
  3091	defined( 'ABSPATH' ) || exit;
  3092	
  3093	final class Search {
  3094		const ALIAS = 'aims_st';
  3095	
  3096		public function register(): void {
  3097			add_filter( 'posts_join', array( $this, 'join' ), 10, 2 );
  3098			add_filter( 'posts_search', array( $this, 'search' ), 10, 2 );
  3099		}
  3100	
  3101		/**
  3102		 * @param object $query WP_Query (or anything with get()).
  3103		 */
  3104		public static function applies( $query ): bool {
  3105			if ( ! is_object( $query ) || ! method_exists( $query, 'get' ) ) {
  3106				return false;
  3107			}
  3108			$post_type = $query->get( 'post_type' );
  3109			if ( is_array( $post_type ) ) {
  3110				if ( 1 !== count( $post_type ) || 'attachment' !== reset( $post_type ) ) {
  3111					return false;
  3112				}
  3113			} elseif ( 'attachment' !== $post_type ) {
  3114				return false;
  3115			}
  3116			return '' !== trim( (string) $query->get( 's' ) );
  3117		}
  3118	
  3119		/**
  3120		 * @param string $join  Existing JOIN clause.
  3121		 * @param object $query WP_Query.
  3122		 */
  3123		public function join( $join, $query ) {
  3124			$join = (string) $join;
  3125			if ( ! self::applies( $query ) || false !== strpos( $join, self::ALIAS ) ) {
  3126				return $join;
  3127			}
  3128			global $wpdb;
  3129			$join .= " LEFT JOIN {$wpdb->postmeta} AS " . self::ALIAS . " ON ({$wpdb->posts}.ID = " . self::ALIAS . '.post_id AND ' . self::ALIAS . ".meta_key = '" . Indexer::META_SEARCH . "')";
  3130			return $join;
  3131		}
  3132	
  3133		/**
  3134		 * @param string $search Existing search SQL.
  3135		 * @param object $query  WP_Query.
  3136		 */
  3137		public function search( $search, $query ) {
  3138			$search = (string) $search;
  3139			if ( ! self::applies( $query ) ) {
  3140				return $search;
  3141			}
  3142			global $wpdb;
  3143			return self::rewrite( $search, $wpdb->posts );
  3144		}
  3145	
  3146		/**
  3147		 * Turn every "(posts.post_title LIKE 'term')" clause WordPress generated into
  3148		 * "(posts.post_title LIKE 'term' OR aims_st.meta_value LIKE 'term')".
  3149		 */
  3150		public static function rewrite( string $search, string $posts_table ): string {
  3151			$table   = preg_quote( $posts_table, '/' );
  3152			$literal = "'(?:[^'\\\\]|\\\\.)*'";
  3153			$pattern = '/\((' . $table . '\.post_title LIKE (' . $literal . '))\)/';
  3154			$result  = preg_replace( $pattern, '($1 OR ' . self::ALIAS . '.meta_value LIKE $2)', $search );
  3155			return null === $result ? $search : $result;
  3156		}
  3157	}
  3158	```
  3159	
  3160	- [ ] **Step 4: Register in Plugin::init()**
  3161	
  3162	Add after the Queue line in `includes/class-plugin.php`:
  3163	
  3164	```php
  3165			( new Search() )->register();
  3166	```
  3167	
  3168	- [ ] **Step 5: Run the test to verify it passes**
  3169	
  3170	Run: `vendor/bin/phpunit --filter SearchTest`
  3171	Expected: OK (6 tests).
  3172	
  3173	- [ ] **Step 6: Commit**
  3174	
  3175	```bash
  3176	git add includes/class-search.php includes/class-plugin.php tests/unit/SearchTest.php
  3177	git -c commit.gpgsign=false commit -m "Extend media library search to AI descriptions"
  3178	```
  3179	
  3180	---
  3181	
  3182	### Task 11: Stats and REST routes
  3183	
  3184	**Files:**
  3185	- Create: `includes/class-stats.php`, `includes/class-rest.php`
  3186	- Modify: `includes/class-plugin.php`
  3187	- Test: `tests/unit/StatsTest.php`, `tests/unit/RestTest.php`
  3188	
  3189	**Interfaces:**
  3190	- Consumes: `Stats_Cache`, `Image_Preparer::IMAGE_MIMES`, `Image_Preparer::PDF_MIME`, `Indexer`, `Settings`, `Providers\Registry::active()`.
  3191	- Produces:
  3192	  - `AIMS\Stats` with static `counts(): array` (keys `total, indexed, not_indexed, failed, skipped`), static `mime_in_sql(): string` (a `('image/jpeg','image/png',...)` literal built with `$wpdb->prepare`), static `next_ids( int $limit, bool $retry_failed ): int[]`, static `remaining_count( bool $retry_failed ): int`, static `status_where( bool $retry_failed, string $alias ): string` (pure).
  3193	  - `AIMS\Rest` with `const NS = 'aims/v1'`, `register()`, route callbacks `index_one`, `bulk`, `stats`, `test`, permission callbacks `can_manage()` and `can_edit_attachment( $request )`, static `TIME_BUDGET = 20` seconds, and static `normalize_ids( $raw ): int[]` (pure).
  3194	
  3195	- [ ] **Step 1: Write the failing tests**
  3196	
  3197	`tests/unit/StatsTest.php`:
  3198	
  3199	```php
  3200	<?php
  3201	namespace AIMS\Tests;
  3202	
  3203	use AIMS\Stats;
  3204	use Brain\Monkey;
  3205	use Brain\Monkey\Functions;
  3206	use PHPUnit\Framework\TestCase;
  3207	
  3208	class StatsTest extends TestCase {
  3209		protected function setUp(): void {
  3210			parent::setUp();
  3211			Monkey\setUp();
  3212			Functions\when( 'get_transient' )->justReturn( false );
  3213			Functions\when( 'set_transient' )->justReturn( true );
  3214			$GLOBALS['wpdb'] = new class() {
  3215				public $posts    = 'wp_posts';
  3216				public $postmeta = 'wp_postmeta';
  3217				public $last_sql = '';
  3218				public $rows     = array();
  3219				public function prepare( $sql, ...$args ) {
  3220					foreach ( $args as $a ) { $sql = preg_replace( '/%[sd]/', is_int( $a ) ? $a : "'" . $a . "'", $sql, 1 ); }
  3221					return $sql;
  3222				}
  3223				public function get_results( $sql ) { $this->last_sql = $sql; return $this->rows; }
  3224				public function get_col( $sql ) { $this->last_sql = $sql; return array( 3, 5 ); }
  3225				public function get_var( $sql ) { $this->last_sql = $sql; return 7; }
  3226			};
  3227		}
  3228	
  3229		protected function tearDown(): void {
  3230			unset( $GLOBALS['wpdb'] );
  3231			Monkey\tearDown();
  3232			parent::tearDown();
  3233		}
  3234	
  3235		public function test_counts_groups_statuses() {
  3236			$GLOBALS['wpdb']->rows = array(
  3237				(object) array( 'status' => 'none', 'n' => '4' ),
  3238				(object) array( 'status' => 'pending', 'n' => '1' ),
  3239				(object) array( 'status' => 'indexed', 'n' => '10' ),
  3240				(object) array( 'status' => 'failed', 'n' => '2' ),
  3241				(object) array( 'status' => 'skipped', 'n' => '3' ),
  3242			);
  3243			$c = Stats::counts();
  3244			$this->assertSame( 20, $c['total'] );
  3245			$this->assertSame( 10, $c['indexed'] );
  3246			$this->assertSame( 5, $c['not_indexed'] );
  3247			$this->assertSame( 2, $c['failed'] );
  3248			$this->assertSame( 3, $c['skipped'] );
  3249			$this->assertStringContainsString( "post_mime_type IN ('image/jpeg'", $GLOBALS['wpdb']->last_sql );
  3250		}
  3251	
  3252		public function test_status_where() {
  3253			$this->assertSame( "(m.meta_value IS NULL OR m.meta_value = 'pending')", Stats::status_where( false, 'm' ) );
  3254			$this->assertSame( "(m.meta_value IS NULL OR m.meta_value = 'pending' OR m.meta_value = 'failed')", Stats::status_where( true, 'm' ) );
  3255		}
  3256	
  3257		public function test_next_ids_and_remaining() {
  3258			$this->assertSame( array( 3, 5 ), Stats::next_ids( 2, false ) );
  3259			$this->assertStringContainsString( 'LIMIT 2', $GLOBALS['wpdb']->last_sql );
  3260			$this->assertSame( 7, Stats::remaining_count( true ) );
  3261			$this->assertStringContainsString( "m.meta_value = 'failed'", $GLOBALS['wpdb']->last_sql );
  3262		}
  3263	}
  3264	```
  3265	
  3266	`tests/unit/RestTest.php`:
  3267	
  3268	```php
  3269	<?php
  3270	namespace AIMS\Tests;
  3271	
  3272	use AIMS\Rest;
  3273	use PHPUnit\Framework\TestCase;
  3274	
  3275	class RestTest extends TestCase {
  3276		public function test_normalize_ids() {
  3277			$this->assertSame( array( 3, 7, 9 ), Rest::normalize_ids( array( '3', 7, '7', 'x', 0, -2, '9' ) ) );
  3278			$this->assertSame( array(), Rest::normalize_ids( 'nope' ) );
  3279			$this->assertSame( array( 1 ), Rest::normalize_ids( '1' ) );
  3280		}
  3281	}
  3282	```
  3283	
  3284	- [ ] **Step 2: Run the tests to verify they fail**
  3285	
  3286	Run: `vendor/bin/phpunit --filter 'StatsTest|RestTest'`
  3287	Expected: Errors, classes not found.
  3288	
  3289	- [ ] **Step 3: Write the Stats class**
  3290	
  3291	`includes/class-stats.php`:
  3292	
  3293	```php
  3294	<?php
  3295	/**
  3296	 * Dashboard counts and "what to index next" queries.
  3297	 *
  3298	 * @package AIMS
  3299	 */
  3300	
  3301	namespace AIMS;
  3302	
  3303	defined( 'ABSPATH' ) || exit;
  3304	
  3305	final class Stats {
  3306		public static function mime_in_sql(): string {
  3307			global $wpdb;
  3308			$mimes = array_merge( Image_Preparer::IMAGE_MIMES, array( Image_Preparer::PDF_MIME ) );
  3309			$parts = array();
  3310			foreach ( $mimes as $mime ) {
  3311				$parts[] = $wpdb->prepare( '%s', $mime );
  3312			}
  3313			return '(' . implode( ',', $parts ) . ')';
  3314		}
  3315	
  3316		public static function status_where( bool $retry_failed, string $alias ): string {
  3317			$where = "({$alias}.meta_value IS NULL OR {$alias}.meta_value = '" . Indexer::STATUS_PENDING . "'";
  3318			if ( $retry_failed ) {
  3319				$where .= " OR {$alias}.meta_value = '" . Indexer::STATUS_FAILED . "'";
  3320			}
  3321			return $where . ')';
  3322		}
  3323	
  3324		private static function base_from(): string {
  3325			global $wpdb;
  3326			return "FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON (m.post_id = p.ID AND m.meta_key = '" . Indexer::META_STATUS . "') "
  3327				. "WHERE p.post_type = 'attachment' AND p.post_status = 'inherit' AND p.post_mime_type IN " . self::mime_in_sql();
  3328		}
  3329	
  3330		/**
  3331		 * @return array{total:int,indexed:int,not_indexed:int,failed:int,skipped:int}
  3332		 */
  3333		public static function counts(): array {
  3334			$cached = get_transient( Stats_Cache::KEY );
  3335			if ( is_array( $cached ) ) {
  3336				return $cached;
  3337			}
  3338	
  3339			global $wpdb;
  3340			$rows = $wpdb->get_results( "SELECT COALESCE(m.meta_value, 'none') AS status, COUNT(*) AS n " . self::base_from() . ' GROUP BY status' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
  3341	
  3342			$by = array();
  3343			foreach ( (array) $rows as $row ) {
  3344				$by[ (string) $row->status ] = (int) $row->n;
  3345			}
  3346			$counts = array(
  3347				'total'       => array_sum( $by ),
  3348				'indexed'     => $by[ Indexer::STATUS_INDEXED ] ?? 0,
  3349				'not_indexed' => ( $by['none'] ?? 0 ) + ( $by[ Indexer::STATUS_PENDING ] ?? 0 ),
  3350				'failed'      => $by[ Indexer::STATUS_FAILED ] ?? 0,
  3351				'skipped'     => $by[ Indexer::STATUS_SKIPPED ] ?? 0,
  3352			);
  3353			set_transient( Stats_Cache::KEY, $counts, Stats_Cache::TTL );
  3354			return $counts;
  3355		}
  3356	
  3357		/**
  3358		 * @return int[]
  3359		 */
  3360		public static function next_ids( int $limit, bool $retry_failed ): array {
  3361			global $wpdb;
  3362			$limit = max( 1, $limit );
  3363			$ids   = $wpdb->get_col( 'SELECT p.ID ' . self::base_from() . ' AND ' . self::status_where( $retry_failed, 'm' ) . " ORDER BY p.ID ASC LIMIT {$limit}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
  3364			return array_map( 'intval', (array) $ids );
  3365		}
  3366	
  3367		public static function remaining_count( bool $retry_failed ): int {
  3368			global $wpdb;
  3369			return (int) $wpdb->get_var( 'SELECT COUNT(*) ' . self::base_from() . ' AND ' . self::status_where( $retry_failed, 'm' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
  3370		}
  3371	}
  3372	```
  3373	
  3374	- [ ] **Step 4: Write the Rest class**
  3375	
  3376	`includes/class-rest.php`:
  3377	
  3378	```php
  3379	<?php
  3380	/**
  3381	 * REST routes used by the admin JS.
  3382	 *
  3383	 * @package AIMS
  3384	 */
  3385	
  3386	namespace AIMS;
  3387	
  3388	use AIMS\Providers\Registry;
  3389	
  3390	defined( 'ABSPATH' ) || exit;
  3391	
  3392	final class Rest {
  3393		const NS          = 'aims/v1';
  3394		const TIME_BUDGET = 20;
  3395	
  3396		public function register(): void {
  3397			add_action( 'rest_api_init', array( $this, 'routes' ) );
  3398		}
  3399	
  3400		public function routes(): void {
  3401			register_rest_route(
  3402				self::NS,
  3403				'/index/(?P<id>\d+)',
  3404				array(
  3405					'methods'             => 'POST',
  3406					'callback'            => array( $this, 'index_one' ),
  3407					'permission_callback' => array( $this, 'can_edit_attachment' ),
  3408					'args'                => array( 'id' => array( 'type' => 'integer', 'required' => true ) ),
  3409				)
  3410			);
  3411			register_rest_route(
  3412				self::NS,
  3413				'/bulk',
  3414				array(
  3415					'methods'             => 'POST',
  3416					'callback'            => array( $this, 'bulk' ),
  3417					'permission_callback' => array( $this, 'can_manage' ),
  3418					'args'                => array(
  3419						'ids'          => array( 'type' => 'array', 'required' => false ),
  3420						'batch_size'   => array( 'type' => 'integer', 'required' => false ),
  3421						'retry_failed' => array( 'type' => 'boolean', 'required' => false, 'default' => false ),
  3422					),
  3423				)
  3424			);
  3425			register_rest_route(
  3426				self::NS,
  3427				'/stats',
  3428				array(
  3429					'methods'             => 'GET',
  3430					'callback'            => array( $this, 'stats' ),
  3431					'permission_callback' => array( $this, 'can_manage' ),
  3432				)
  3433			);
  3434			register_rest_route(
  3435				self::NS,
  3436				'/test',
  3437				array(
  3438					'methods'             => 'POST',
  3439					'callback'            => array( $this, 'test' ),
  3440					'permission_callback' => array( $this, 'can_manage' ),
  3441				)
  3442			);
  3443		}
  3444	
  3445		public function can_manage(): bool {
  3446			return current_user_can( 'manage_options' );
  3447		}
  3448	
  3449		/**
  3450		 * @param \WP_REST_Request $request Request.
  3451		 */
  3452		public function can_edit_attachment( $request ): bool {
  3453			$id = (int) $request['id'];
  3454			return $id > 0 && current_user_can( 'upload_files' ) && current_user_can( 'edit_post', $id );
  3455		}
  3456	
  3457		/**
  3458		 * @param mixed $raw Anything the client sent as ids.
  3459		 * @return int[] Unique positive integers in the order received.
  3460		 */
  3461		public static function normalize_ids( $raw ): array {
  3462			if ( is_string( $raw ) || is_int( $raw ) ) {
  3463				$raw = array( $raw );
  3464			}
  3465			if ( ! is_array( $raw ) ) {
  3466				return array();
  3467			}
  3468			$ids = array();
  3469			foreach ( $raw as $value ) {
  3470				if ( is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ) {
  3471					$id = (int) $value;
  3472					if ( $id > 0 && ! in_array( $id, $ids, true ) ) {
  3473						$ids[] = $id;
  3474					}
  3475				}
  3476			}
  3477			return $ids;
  3478		}
  3479	
  3480		private static function result_for( int $id, $outcome ): array {
  3481			$payload          = Indexer::payload( $id );
  3482			$payload['ok']    = ! is_wp_error( $outcome );
  3483			$payload['title'] = (string) get_the_title( $id );
  3484			if ( is_wp_error( $outcome ) ) {
  3485				$payload['error'] = $outcome->get_error_message();
  3486				$payload['code']  = $outcome->get_error_code();
  3487			}
  3488			return $payload;
  3489		}
  3490	
  3491		/**
  3492		 * @param \WP_REST_Request $request Request.
  3493		 */
  3494		public function index_one( $request ) {
  3495			$id = (int) $request['id'];
  3496			if ( 'attachment' !== get_post_type( $id ) ) {
  3497				return new \WP_Error( 'not_found', __( 'Attachment not found.', 'ai-media-search' ), array( 'status' => 404 ) );
  3498			}
  3499			$outcome = ( new Indexer() )->index_attachment( $id );
  3500			return rest_ensure_response( self::result_for( $id, $outcome ) );
  3501		}
  3502	
  3503		/**
  3504		 * @param \WP_REST_Request $request Request.
  3505		 */
  3506		public function bulk( $request ) {
  3507			$batch_size   = (int) ( $request['batch_size'] ?? 0 );
  3508			$batch_size   = $batch_size > 0 ? min( 10, $batch_size ) : (int) Settings::get( 'batch_size' );
  3509			$retry_failed = ! empty( $request['retry_failed'] );
  3510			$explicit     = self::normalize_ids( $request['ids'] ?? null );
  3511	
  3512			if ( $explicit ) {
  3513				$ids       = array_slice( $explicit, 0, $batch_size );
  3514				$remaining = array_slice( $explicit, $batch_size );
  3515			} else {
  3516				$ids       = Stats::next_ids( $batch_size, $retry_failed );
  3517				$remaining = array();
  3518			}
  3519	
  3520			$indexer = new Indexer();
  3521			$results = array();
  3522			$started = microtime( true );
  3523			foreach ( $ids as $position => $id ) {
  3524				if ( $position > 0 && ( microtime( true ) - $started ) > self::TIME_BUDGET ) {
  3525					// Out of time for this request; hand the rest back to the client.
  3526					$remaining = array_merge( array_slice( $ids, $position ), $remaining );
  3527					break;
  3528				}
  3529				$results[] = self::result_for( $id, $indexer->index_attachment( $id ) );
  3530			}
  3531	
  3532			return rest_ensure_response(
  3533				array(
  3534					'results'         => $results,
  3535					'remaining_ids'   => array_values( $remaining ),
  3536					'remaining_count' => $explicit ? count( $remaining ) : Stats::remaining_count( $retry_failed ),
  3537					'stats'           => Stats::counts(),
  3538				)
  3539			);
  3540		}
  3541	
  3542		public function stats() {
  3543			return rest_ensure_response( Stats::counts() );
  3544		}
  3545	
  3546		public function test() {
  3547			$provider = Registry::active();
  3548			if ( is_wp_error( $provider ) ) {
  3549				return rest_ensure_response( array( 'ok' => false, 'message' => $provider->get_error_message() ) );
  3550			}
  3551			$outcome = $provider->test_connection();
  3552			if ( is_wp_error( $outcome ) ) {
  3553				return rest_ensure_response( array( 'ok' => false, 'message' => $outcome->get_error_message() ) );
  3554			}
  3555			return rest_ensure_response(
  3556				array(
  3557					'ok'      => true,
  3558					/* translators: 1: provider label, 2: model id */
  3559					'message' => sprintf( __( 'Connected to %1$s using %2$s.', 'ai-media-search' ), $provider::get_label(), Settings::get_active_model() ),
  3560				)
  3561			);
  3562		}
  3563	}
  3564	```
  3565	
  3566	- [ ] **Step 5: Register in Plugin::init()**
  3567	
  3568	Add after the Search line in `includes/class-plugin.php`:
  3569	
  3570	```php
  3571			( new Rest() )->register();
  3572	```
  3573	
  3574	- [ ] **Step 6: Run the tests to verify they pass**
  3575	
  3576	Run: `vendor/bin/phpunit --filter 'StatsTest|RestTest'`
  3577	Expected: OK (4 tests).
  3578	
  3579	- [ ] **Step 7: Commit**
  3580	
  3581	```bash
  3582	git add includes/class-stats.php includes/class-rest.php includes/class-plugin.php tests/unit/StatsTest.php tests/unit/RestTest.php
  3583	git -c commit.gpgsign=false commit -m "Add stats queries and REST routes for indexing"
  3584	```
  3585	
  3586	---
  3587	
  3588	### Task 12: Attachment fields, media column, bulk action
  3589	
  3590	**Files:**
  3591	- Create: `includes/class-attachment-fields.php`
  3592	- Modify: `includes/class-plugin.php`
  3593	- Test: `tests/unit/AttachmentFieldsTest.php`
  3594	
  3595	**Interfaces:**
  3596	- Consumes: `Indexer::payload()`, `Indexer::META_DESCRIPTION`, `Indexer::rebuild_search_text()`, `Image_Preparer::is_eligible_mime()`, `Queue::schedule()`.
  3597	- Produces: `AIMS\Attachment_Fields` with `register()`, `fields( array $fields, $post ): array`, `save( array $post, array $attachment ): array`, `column( array $columns ): array`, `column_content( string $column, int $id ): void`, `bulk_action( array $actions ): array`, `handle_bulk( string $redirect, string $action, array $ids ): string`, `bulk_notice(): void`, static `status_label( string $status ): string`, static `status_html( array $payload ): string`.
  3598	
  3599	- [ ] **Step 1: Write the failing test**
  3600	
  3601	`tests/unit/AttachmentFieldsTest.php`:
  3602	
  3603	```php
  3604	<?php
  3605	namespace AIMS\Tests;
  3606	
  3607	use AIMS\Attachment_Fields;
  3608	use Brain\Monkey;
  3609	use Brain\Monkey\Functions;
  3610	use PHPUnit\Framework\TestCase;
  3611	
  3612	class AttachmentFieldsTest extends TestCase {
  3613		protected function setUp(): void {
  3614			parent::setUp();
  3615			Monkey\setUp();
  3616			Functions\when( '__' )->returnArg( 1 );
  3617			Functions\when( 'esc_html__' )->returnArg( 1 );
  3618			Functions\when( 'esc_html' )->alias( function ( $s ) { return htmlspecialchars( $s, ENT_QUOTES ); } );
  3619			Functions\when( 'esc_attr' )->alias( function ( $s ) { return htmlspecialchars( $s, ENT_QUOTES ); } );
  3620			Functions\when( 'wp_date' )->justReturn( '2026-09-10 12:00' );
  3621			Functions\when( 'get_option' )->justReturn( 'Y-m-d H:i' );
  3622		}
  3623	
  3624		protected function tearDown(): void {
  3625			Monkey\tearDown();
  3626			parent::tearDown();
  3627		}
  3628	
  3629		public function test_status_label() {
  3630			$this->assertSame( 'Indexed', Attachment_Fields::status_label( 'indexed' ) );
  3631			$this->assertSame( 'Not indexed', Attachment_Fields::status_label( '' ) );
  3632			$this->assertSame( 'Failed', Attachment_Fields::status_label( 'failed' ) );
  3633		}
  3634	
  3635		public function test_status_html_escapes_error() {
  3636			$html = Attachment_Fields::status_html( array( 'status' => 'failed', 'error' => '<b>boom</b>', 'indexed_at' => 0 ) );
  3637			$this->assertStringContainsString( 'aims-status-failed', $html );
  3638			$this->assertStringContainsString( '&lt;b&gt;boom&lt;/b&gt;', $html );
  3639			$this->assertStringNotContainsString( '<b>boom', $html );
  3640		}
  3641	
  3642		public function test_fields_added_only_for_eligible_mimes() {
  3643			Functions\when( 'get_post_meta' )->justReturn( '' );
  3644			$post = (object) array( 'ID' => 4, 'post_mime_type' => 'video/mp4' );
  3645			$this->assertSame( array( 'x' => 1 ), ( new Attachment_Fields() )->fields( array( 'x' => 1 ), $post ) );
  3646	
  3647			$post   = (object) array( 'ID' => 4, 'post_mime_type' => 'image/jpeg' );
  3648			$fields = ( new Attachment_Fields() )->fields( array(), $post );
  3649			$this->assertSame( 'textarea', $fields['aims_description']['input'] );
  3650			$this->assertSame( 'html', $fields['aims_status']['input'] );
  3651			$this->assertStringContainsString( 'data-id="4"', $fields['aims_status']['html'] );
  3652			$this->assertStringContainsString( 'aims-regenerate', $fields['aims_status']['html'] );
  3653		}
  3654	
  3655		public function test_save_updates_description_and_rebuilds_search_text() {
  3656			Functions\when( 'wp_unslash' )->returnArg( 1 );
  3657			Functions\when( 'sanitize_textarea_field' )->alias( 'trim' );
  3658			Functions\when( 'get_post_meta' )->justReturn( '' );
  3659			$calls = array();
  3660			Functions\when( 'update_post_meta' )->alias( function ( $id, $key, $value ) use ( &$calls ) { $calls[ $key ] = $value; return true; } );
  3661	
  3662			( new Attachment_Fields() )->save( array( 'ID' => 5 ), array( 'aims_description' => ' Fixed text ' ) );
  3663			$this->assertSame( 'Fixed text', $calls['_aims_description'] );
  3664			$this->assertArrayHasKey( '_aims_search_text', $calls );
  3665		}
  3666	
  3667		public function test_handle_bulk_schedules_each_id() {
  3668			Functions\when( 'wp_next_scheduled' )->justReturn( false );
  3669			Functions\when( 'time' )->justReturn( 1 );
  3670			Functions\expect( 'wp_schedule_single_event' )->twice();
  3671			Functions\when( 'add_query_arg' )->alias( function ( $k, $v, $url ) { return $url . '?' . $k . '=' . $v; } );
  3672			$redirect = ( new Attachment_Fields() )->handle_bulk( 'upload.php', 'aims_index', array( 1, 2 ) );
  3673			$this->assertSame( 'upload.php?aims_queued=2', $redirect );
  3674			$this->assertSame( 'upload.php', ( new Attachment_Fields() )->handle_bulk( 'upload.php', 'other', array( 1 ) ) );
  3675		}
  3676	}
  3677	```
  3678	
  3679	- [ ] **Step 2: Run the test to verify it fails**
  3680	
  3681	Run: `vendor/bin/phpunit --filter AttachmentFieldsTest`
  3682	Expected: Error, class not found.
  3683	
  3684	- [ ] **Step 3: Write the Attachment_Fields class**
  3685	
  3686	`includes/class-attachment-fields.php`:
  3687	
  3688	```php
  3689	<?php
  3690	/**
  3691	 * Media modal fields, list-view column, and bulk action.
  3692	 *
  3693	 * @package AIMS
  3694	 */
  3695	
  3696	namespace AIMS;
  3697	
  3698	defined( 'ABSPATH' ) || exit;
  3699	
  3700	final class Attachment_Fields {
  3701		public function register(): void {
  3702			add_filter( 'attachment_fields_to_edit', array( $this, 'fields' ), 10, 2 );
  3703			add_filter( 'attachment_fields_to_save', array( $this, 'save' ), 10, 2 );
  3704			add_filter( 'manage_media_columns', array( $this, 'column' ) );
  3705			add_action( 'manage_media_custom_column', array( $this, 'column_content' ), 10, 2 );
  3706			add_filter( 'bulk_actions-upload', array( $this, 'bulk_action' ) );
  3707			add_filter( 'handle_bulk_actions-upload', array( $this, 'handle_bulk' ), 10, 3 );
  3708			add_action( 'admin_notices', array( $this, 'bulk_notice' ) );
  3709		}
  3710	
  3711		public static function status_label( string $status ): string {
  3712			switch ( $status ) {
  3713				case Indexer::STATUS_INDEXED:
  3714					return __( 'Indexed', 'ai-media-search' );
  3715				case Indexer::STATUS_FAILED:
  3716					return __( 'Failed', 'ai-media-search' );
  3717				case Indexer::STATUS_SKIPPED:
  3718					return __( 'Skipped', 'ai-media-search' );
  3719				case Indexer::STATUS_PENDING:
  3720					return __( 'Pending', 'ai-media-search' );
  3721				default:
  3722					return __( 'Not indexed', 'ai-media-search' );
  3723			}
  3724		}
  3725	
  3726		public static function status_html( array $payload ): string {
  3727			$status = (string) ( $payload['status'] ?? '' );
  3728			$class  = 'aims-status aims-status-' . ( '' === $status ? 'none' : $status );
  3729			$html   = '<span class="' . esc_attr( $class ) . '">' . esc_html( self::status_label( $status ) ) . '</span>';
  3730	
  3731			if ( Indexer::STATUS_INDEXED === $status && ! empty( $payload['indexed_at'] ) ) {
  3732				$html .= ' <span class="aims-muted">' . esc_html( wp_date( (string) get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $payload['indexed_at'] ) ) . '</span>';
  3733			}
  3734			if ( ! empty( $payload['error'] ) && Indexer::STATUS_INDEXED !== $status ) {
  3735				$html .= ' <span class="aims-error">' . esc_html( (string) $payload['error'] ) . '</span>';
  3736			}
  3737			return $html;
  3738		}
  3739	
  3740		/**
  3741		 * @param array  $fields Existing fields.
  3742		 * @param object $post   WP_Post.
  3743		 */
  3744		public function fields( $fields, $post ) {
  3745			$fields = (array) $fields;
  3746			if ( ! Image_Preparer::is_eligible_mime( (string) $post->post_mime_type ) ) {
  3747				return $fields;
  3748			}
  3749			$payload = Indexer::payload( (int) $post->ID );
  3750	
  3751			$fields['aims_description'] = array(
  3752				'label' => __( 'AI description', 'ai-media-search' ),
  3753				'input' => 'textarea',
  3754				'value' => $payload['description'],
  3755				'helps' => __( 'Used by the media search. Edit it to correct the AI.', 'ai-media-search' ),
  3756			);
  3757			$fields['aims_tags']        = array(
  3758				'label' => __( 'AI tags', 'ai-media-search' ),
  3759				'input' => 'html',
  3760				'html'  => '<p class="aims-tags" data-id="' . esc_attr( (string) $post->ID ) . '">' . esc_html( implode( ', ', $payload['tags'] ) ) . '</p>',
  3761			);
  3762			$fields['aims_status']      = array(
  3763				'label' => __( 'AI index', 'ai-media-search' ),
  3764				'input' => 'html',
  3765				'html'  => '<span class="aims-status-wrap" data-id="' . esc_attr( (string) $post->ID ) . '">' . self::status_html( $payload ) . '</span> '
  3766					. '<button type="button" class="button button-small aims-regenerate" data-id="' . esc_attr( (string) $post->ID ) . '">'
  3767					. esc_html__( 'Regenerate', 'ai-media-search' ) . '</button>',
  3768			);
  3769			return $fields;
  3770		}
  3771	
  3772		/**
  3773		 * @param array $post       Post data being saved.
  3774		 * @param array $attachment Submitted field values for this attachment.
  3775		 */
  3776		public function save( $post, $attachment ) {
  3777			if ( isset( $attachment['aims_description'] ) && isset( $post['ID'] ) ) {
  3778				$id = (int) $post['ID'];
  3779				update_post_meta( $id, Indexer::META_DESCRIPTION, sanitize_textarea_field( wp_unslash( (string) $attachment['aims_description'] ) ) );
  3780				Indexer::rebuild_search_text( $id );
  3781			}
  3782			return $post;
  3783		}
  3784	
  3785		public function column( $columns ) {
  3786			$columns['aims_status'] = __( 'AI index', 'ai-media-search' );
  3787			return $columns;
  3788		}
  3789	
  3790		public function column_content( $column, $id ): void {
  3791			if ( 'aims_status' !== $column ) {
  3792				return;
  3793			}
  3794			$payload = Indexer::payload( (int) $id );
  3795			echo '<span class="aims-status-wrap" data-id="' . esc_attr( (string) $id ) . '">' . self::status_html( $payload ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- status_html escapes.
  3796		}
  3797	
  3798		public function bulk_action( $actions ) {
  3799			$actions['aims_index'] = __( 'Index with AI', 'ai-media-search' );
  3800			return $actions;
  3801		}
  3802	
  3803		public function handle_bulk( $redirect, $action, $ids ) {
  3804			if ( 'aims_index' !== $action ) {
  3805				return $redirect;
  3806			}
  3807			$count = 0;
  3808			foreach ( (array) $ids as $id ) {
  3809				if ( Queue::schedule( (int) $id, 5 ) ) {
  3810					++$count;
  3811				}
  3812			}
  3813			return add_query_arg( 'aims_queued', $count, $redirect );
  3814		}
  3815	
  3816		public function bulk_notice(): void {
  3817			if ( ! isset( $_GET['aims_queued'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
  3818				return;
  3819			}
  3820			$count = (int) $_GET['aims_queued']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
  3821			echo '<div class="notice notice-success is-dismissible"><p>'
  3822				/* translators: %d: number of files */
  3823				. esc_html( sprintf( _n( '%d file queued for AI indexing.', '%d files queued for AI indexing.', $count, 'ai-media-search' ), $count ) )
  3824				. '</p></div>';
  3825		}
  3826	}
  3827	```
  3828	
  3829	- [ ] **Step 4: Register in Plugin::init()**
  3830	
  3831	Add after the Rest line in `includes/class-plugin.php`:
  3832	
  3833	```php
  3834			( new Attachment_Fields() )->register();
  3835	```
  3836	
  3837	- [ ] **Step 5: Run the test to verify it passes**
  3838	
  3839	Run: `vendor/bin/phpunit --filter AttachmentFieldsTest`
  3840	Expected: OK (5 tests). If `_n` is reported undefined in `bulk_notice`, it is only called at runtime, not in tests; no stub needed.
  3841	
  3842	- [ ] **Step 6: Commit**
  3843	
  3844	```bash
  3845	git add includes/class-attachment-fields.php includes/class-plugin.php tests/unit/AttachmentFieldsTest.php
  3846	git -c commit.gpgsign=false commit -m "Add attachment fields, media column, and bulk action"
  3847	```
  3848	
  3849	---
  3850	
  3851	### Task 13: Admin page (Dashboard and Settings tabs) with JS and CSS
  3852	
  3853	**Files:**
  3854	- Create: `includes/class-admin-page.php`, `assets/admin.js`, `assets/admin.css`
  3855	- Modify: `includes/class-plugin.php`
  3856	- Test: `tests/unit/AdminPageTest.php` (pure helpers only; the rendered page is verified manually in Task 14)
  3857	
  3858	**Interfaces:**
  3859	- Consumes: `Settings`, `Providers\Registry`, `Stats`, `Indexer`, `Attachment_Fields::status_html()`, `Image_Preparer`, `Rest::NS`.
  3860	- Produces: `AIMS\Admin_Page` with `const SLUG = 'ai-media-search'`, `register()`, `menu()`, `enqueue( string $hook )`, `enqueue_for_media()`, `render()`, static `filter_meta_query( string $filter ): array` (pure), static `truncate( string $text, int $length = 160 ): string` (pure).
  3861	
  3862	- [ ] **Step 1: Write the failing test**
  3863	
  3864	`tests/unit/AdminPageTest.php`:
  3865	
  3866	```php
  3867	<?php
  3868	namespace AIMS\Tests;
  3869	
  3870	use AIMS\Admin_Page;
  3871	use PHPUnit\Framework\TestCase;
  3872	
  3873	class AdminPageTest extends TestCase {
  3874		public function test_filter_meta_query() {
  3875			$this->assertSame( array(), Admin_Page::filter_meta_query( 'all' ) );
  3876			$this->assertSame( array(), Admin_Page::filter_meta_query( 'bogus' ) );
  3877			$this->assertSame(
  3878				array( array( 'key' => '_aims_status', 'value' => 'indexed' ) ),
  3879				Admin_Page::filter_meta_query( 'indexed' )
  3880			);
  3881			$not = Admin_Page::filter_meta_query( 'not_indexed' );
  3882			$this->assertSame( 'OR', $not['relation'] );
  3883			$this->assertSame( 'NOT EXISTS', $not[0]['compare'] );
  3884			$this->assertSame( 'pending', $not[1]['value'] );
  3885		}
  3886	
  3887		public function test_truncate() {
  3888			$this->assertSame( 'short', Admin_Page::truncate( 'short' ) );
  3889			$long = str_repeat( 'a', 200 );
  3890			$this->assertSame( str_repeat( 'a', 160 ) . '…', Admin_Page::truncate( $long ) );
  3891		}
  3892	}
  3893	```
  3894	
  3895	- [ ] **Step 2: Run the test to verify it fails**
  3896	
  3897	Run: `vendor/bin/phpunit --filter AdminPageTest`
  3898	Expected: Error, class not found.
  3899	
  3900	- [ ] **Step 3: Write the Admin_Page class**
  3901	
  3902	`includes/class-admin-page.php`:
  3903	
  3904	```php
  3905	<?php
  3906	/**
  3907	 * Top-level admin page with Dashboard and Settings tabs.
  3908	 *
  3909	 * @package AIMS
  3910	 */
  3911	
  3912	namespace AIMS;
  3913	
  3914	use AIMS\Providers\Registry;
  3915	
  3916	defined( 'ABSPATH' ) || exit;
  3917	
  3918	final class Admin_Page {
  3919		const SLUG     = 'ai-media-search';
  3920		const PER_PAGE = 50;
  3921		const FILTERS  = array( 'all', 'indexed', 'not_indexed', 'failed', 'skipped' );
  3922	
  3923		public function register(): void {
  3924			add_action( 'admin_menu', array( $this, 'menu' ) );
  3925			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
  3926			add_action( 'wp_enqueue_media', array( $this, 'enqueue_for_media' ) );
  3927		}
  3928	
  3929		public function menu(): void {
  3930			add_menu_page(
  3931				__( 'AI Media Search', 'ai-media-search' ),
  3932				__( 'AI Media Search', 'ai-media-search' ),
  3933				'manage_options',
  3934				self::SLUG,
  3935				array( $this, 'render' ),
  3936				'dashicons-search',
  3937				81
  3938			);
  3939		}
  3940	
  3941		public function enqueue( string $hook ): void {
  3942			$is_our_page  = 'toplevel_page_' . self::SLUG === $hook;
  3943			$is_media     = 'upload.php' === $hook;
  3944			$is_edit_att  = 'post.php' === $hook && 'attachment' === get_post_type( (int) ( $_GET['post'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
  3945			if ( $is_our_page || $is_media || $is_edit_att ) {
  3946				$this->enqueue_for_media();
  3947			}
  3948		}
  3949	
  3950		public function enqueue_for_media(): void {
  3951			if ( wp_script_is( 'aims-admin', 'enqueued' ) ) {
  3952				return;
  3953			}
  3954			wp_enqueue_style( 'aims-admin', AIMS_URL . 'assets/admin.css', array(), AIMS_VERSION );
  3955			wp_enqueue_script( 'aims-admin', AIMS_URL . 'assets/admin.js', array(), AIMS_VERSION, true );
  3956			wp_localize_script(
  3957				'aims-admin',
  3958				'aimsData',
  3959				array(
  3960					'restUrl'   => esc_url_raw( rest_url( Rest::NS . '/' ) ),
  3961					'nonce'     => wp_create_nonce( 'wp_rest' ),
  3962					'batchSize' => (int) Settings::get( 'batch_size' ),
  3963					'hasKey'    => '' !== Settings::get_api_key( (string) Settings::get( 'provider' ) ),
  3964					'i18n'      => array(
  3965						'working'    => __( 'Working…', 'ai-media-search' ),
  3966						'done'       => __( 'Done.', 'ai-media-search' ),
  3967						'stopped'    => __( 'Stopped.', 'ai-media-search' ),
  3968						'failed'     => __( 'Request failed.', 'ai-media-search' ),
  3969						'regenerate' => __( 'Regenerate', 'ai-media-search' ),
  3970						'index'      => __( 'Index', 'ai-media-search' ),
  3971						'progress'   => /* translators: 1: done count, 2: total count */ __( '%1$s of %2$s', 'ai-media-search' ),
  3972						'noSelection' => __( 'Select at least one file first.', 'ai-media-search' ),
  3973					),
  3974				)
  3975			);
  3976		}
  3977	
  3978		public static function filter_meta_query( string $filter ): array {
  3979			switch ( $filter ) {
  3980				case 'indexed':
  3981				case 'failed':
  3982				case 'skipped':
  3983					return array( array( 'key' => Indexer::META_STATUS, 'value' => $filter ) );
  3984				case 'not_indexed':
  3985					return array(
  3986						'relation' => 'OR',
  3987						array( 'key' => Indexer::META_STATUS, 'compare' => 'NOT EXISTS' ),
  3988						array( 'key' => Indexer::META_STATUS, 'value' => Indexer::STATUS_PENDING ),
  3989					);
  3990				default:
  3991					return array();
  3992			}
  3993		}
  3994	
  3995		public static function truncate( string $text, int $length = 160 ): string {
  3996			if ( mb_strlen( $text ) <= $length ) {
  3997				return $text;
  3998			}
  3999			return rtrim( mb_substr( $text, 0, $length ) ) . '…';
  4000		}
  4001	
  4002		public function render(): void {
  4003			if ( ! current_user_can( 'manage_options' ) ) {
  4004				wp_die( esc_html__( 'You do not have permission to view this page.', 'ai-media-search' ) );
  4005			}
  4006			$settings = Settings::all();
  4007			$has_key  = '' !== Settings::get_api_key( $settings['provider'] );
  4008			?>
  4009			<div class="wrap aims-wrap">
  4010				<h1><?php esc_html_e( 'AI Media Search', 'ai-media-search' ); ?></h1>
  4011				<p class="description"><?php esc_html_e( 'Describe your images with AI so the Media Library search finds them by what is in the picture.', 'ai-media-search' ); ?></p>
  4012	
  4013				<nav class="nav-tab-wrapper aims-tabs">
  4014					<a href="#dashboard" class="nav-tab nav-tab-active aims-tab" data-tab="dashboard"><?php esc_html_e( 'Dashboard', 'ai-media-search' ); ?></a>
  4015					<a href="#settings" class="nav-tab aims-tab" data-tab="settings"><?php esc_html_e( 'Settings', 'ai-media-search' ); ?></a>
  4016				</nav>
  4017	
  4018				<div id="aims-tab-dashboard" class="aims-tab-panel">
  4019					<?php $this->render_dashboard( $has_key ); ?>
  4020				</div>
  4021				<div id="aims-tab-settings" class="aims-tab-panel" hidden>
  4022					<?php $this->render_settings( $settings ); ?>
  4023				</div>
  4024			</div>
  4025			<?php
  4026		}
  4027	
  4028		private function render_dashboard( bool $has_key ): void {
  4029			$filter = sanitize_key( (string) ( $_GET['filter'] ?? 'all' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
  4030			$filter = in_array( $filter, self::FILTERS, true ) ? $filter : 'all';
  4031			$paged  = max( 1, (int) ( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
  4032			$counts = Stats::counts();
  4033	
  4034			$cards = array(
  4035				'all'         => array( __( 'All files', 'ai-media-search' ), $counts['total'] ),
  4036				'indexed'     => array( __( 'Indexed', 'ai-media-search' ), $counts['indexed'] ),
  4037				'not_indexed' => array( __( 'Not indexed', 'ai-media-search' ), $counts['not_indexed'] ),
  4038				'failed'      => array( __( 'Failed', 'ai-media-search' ), $counts['failed'] ),
  4039				'skipped'     => array( __( 'Skipped', 'ai-media-search' ), $counts['skipped'] ),
  4040			);
  4041	
  4042			if ( ! $has_key ) {
  4043				echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Add an API key on the Settings tab before indexing.', 'ai-media-search' ) . '</p></div>';
  4044			}
  4045	
  4046			echo '<div class="aims-cards">';
  4047			foreach ( $cards as $key => list( $label, $count ) ) {
  4048				$url   = add_query_arg( array( 'page' => self::SLUG, 'filter' => $key ), admin_url( 'admin.php' ) ) . '#dashboard';
  4049				$class = 'aims-card aims-card-' . $key . ( $key === $filter ? ' is-active' : '' );
  4050				echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( $url ) . '"><span class="aims-card-count">' . esc_html( number_format_i18n( $count ) ) . '</span><span class="aims-card-label">' . esc_html( $label ) . '</span></a>';
  4051			}
  4052			echo '</div>';
  4053	
  4054			$query = new \WP_Query(
  4055				array(
  4056					'post_type'      => 'attachment',
  4057					'post_status'    => 'inherit',
  4058					'post_mime_type' => array_merge( Image_Preparer::IMAGE_MIMES, array( Image_Preparer::PDF_MIME ) ),
  4059					'posts_per_page' => self::PER_PAGE,
  4060					'paged'          => $paged,
  4061					'orderby'        => 'date',
  4062					'order'          => 'DESC',
  4063					'meta_query'     => self::filter_meta_query( $filter ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
  4064				)
  4065			);
  4066			?>
  4067			<table class="widefat striped aims-table">
  4068				<thead>
  4069					<tr>
  4070						<td class="check-column"><input type="checkbox" id="aims-select-all" /></td>
  4071						<th><?php esc_html_e( 'File', 'ai-media-search' ); ?></th>
  4072						<th><?php esc_html_e( 'AI description', 'ai-media-search' ); ?></th>
  4073						<th><?php esc_html_e( 'Tags', 'ai-media-search' ); ?></th>
  4074						<th><?php esc_html_e( 'Status', 'ai-media-search' ); ?></th>
  4075						<th><?php esc_html_e( 'Actions', 'ai-media-search' ); ?></th>
  4076					</tr>
  4077				</thead>
  4078				<tbody>
  4079				<?php if ( ! $query->have_posts() ) : ?>
  4080					<tr><td colspan="6"><?php esc_html_e( 'No files match this filter.', 'ai-media-search' ); ?></td></tr>
  4081				<?php endif; ?>
  4082				<?php
  4083				foreach ( $query->posts as $post ) :
  4084					$id      = (int) $post->ID;
  4085					$payload = Indexer::payload( $id );
  4086					$button  = Indexer::STATUS_INDEXED === $payload['status'] ? __( 'Regenerate', 'ai-media-search' ) : __( 'Index', 'ai-media-search' );
  4087					?>
  4088					<tr class="aims-row" data-id="<?php echo esc_attr( (string) $id ); ?>">
  4089						<th scope="row" class="check-column"><input type="checkbox" class="aims-select" value="<?php echo esc_attr( (string) $id ); ?>" /></th>
  4090						<td class="aims-file">
  4091							<?php echo wp_get_attachment_image( $id, array( 60, 60 ), true ); ?>
  4092							<strong><?php echo esc_html( get_the_title( $id ) ); ?></strong><br />
  4093							<span class="aims-muted"><?php echo esc_html( wp_basename( (string) get_attached_file( $id ) ) ); ?></span>
  4094						</td>
  4095						<td class="aims-description"><?php echo esc_html( self::truncate( $payload['description'] ) ); ?></td>
  4096						<td class="aims-tags"><?php echo esc_html( implode( ', ', $payload['tags'] ) ); ?></td>
  4097						<td class="aims-status-cell"><span class="aims-status-wrap" data-id="<?php echo esc_attr( (string) $id ); ?>"><?php echo Attachment_Fields::status_html( $payload ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span></td>
  4098						<td class="aims-actions">
  4099							<button type="button" class="button button-small aims-index-one" data-id="<?php echo esc_attr( (string) $id ); ?>" <?php disabled( ! $has_key ); ?>><?php echo esc_html( $button ); ?></button>
  4100							<a class="aims-edit" href="<?php echo esc_url( (string) get_edit_post_link( $id ) ); ?>"><?php esc_html_e( 'Edit', 'ai-media-search' ); ?></a>
  4101						</td>
  4102					</tr>
  4103				<?php endforeach; ?>
  4104				</tbody>
  4105			</table>
  4106			<?php
  4107			$links = paginate_links(
  4108				array(
  4109					'base'      => add_query_arg( array( 'page' => self::SLUG, 'filter' => $filter, 'paged' => '%#%' ), admin_url( 'admin.php' ) ) . '#dashboard',
  4110					'format'    => '',
  4111					'current'   => $paged,
  4112					'total'     => (int) $query->max_num_pages,
  4113					'prev_text' => '&laquo;',
  4114					'next_text' => '&raquo;',
  4115				)
  4116			);
  4117			if ( $links ) {
  4118				echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( $links ) . '</div></div>';
  4119			}
  4120			?>
  4121			<div class="aims-controls">
  4122				<button type="button" class="button button-primary" id="aims-index-selected" <?php disabled( ! $has_key ); ?>><?php esc_html_e( 'Index selected', 'ai-media-search' ); ?></button>
  4123				<button type="button" class="button" id="aims-index-all" <?php disabled( ! $has_key ); ?>><?php esc_html_e( 'Index all not indexed', 'ai-media-search' ); ?></button>
  4124				<label><input type="checkbox" id="aims-retry-failed" /> <?php esc_html_e( 'Also retry failed', 'ai-media-search' ); ?></label>
  4125				<button type="button" class="button" id="aims-stop" disabled><?php esc_html_e( 'Stop', 'ai-media-search' ); ?></button>
  4126				<span id="aims-progress-text" class="aims-muted"></span>
  4127				<div class="aims-progress"><div class="aims-progress-bar" id="aims-progress-bar"></div></div>
  4128				<ul id="aims-log" class="aims-log"></ul>
  4129			</div>
  4130			<?php
  4131		}
  4132	
  4133		private function render_settings( array $settings ): void {
  4134			?>
  4135			<div class="notice notice-info inline"><p><?php esc_html_e( 'Images and PDF previews are sent to the selected third-party API for analysis. Check the provider\'s terms and privacy policy before enabling.', 'ai-media-search' ); ?></p></div>
  4136			<form method="post" action="options.php" class="aims-settings-form">
  4137				<?php settings_fields( 'aims_settings_group' ); ?>
  4138				<table class="form-table" role="presentation">
  4139					<tr>
  4140						<th scope="row"><?php esc_html_e( 'Provider', 'ai-media-search' ); ?></th>
  4141						<td>
  4142							<?php foreach ( Registry::labels() as $id => $label ) : ?>
  4143								<label class="aims-provider-choice">
  4144									<input type="radio" name="aims_settings[provider]" value="<?php echo esc_attr( $id ); ?>" <?php checked( $settings['provider'], $id ); ?> />
  4145									<?php echo esc_html( $label ); ?>
  4146								</label>
  4147							<?php endforeach; ?>
  4148						</td>
  4149					</tr>
  4150					<?php foreach ( Registry::labels() as $id => $label ) : ?>
  4151						<?php
  4152						$key_set = '' !== $settings['api_keys'][ $id ];
  4153						$model   = $settings['models'][ $id ];
  4154						$known   = Registry::known_models( $id );
  4155						if ( '' === $model && $known ) {
  4156							$model = (string) array_key_first( $known );
  4157						}
  4158						$is_custom = 'custom' === $model || ( '' !== $model && ! isset( $known[ $model ] ) );
  4159						?>
  4160						<tr class="aims-provider-row" data-provider="<?php echo esc_attr( $id ); ?>">
  4161							<th scope="row"><?php echo esc_html( $label ); ?></th>
  4162							<td>
  4163								<p>
  4164									<label for="aims-key-<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'API key', 'ai-media-search' ); ?></label><br />
  4165									<input type="password" class="regular-text" id="aims-key-<?php echo esc_attr( $id ); ?>" name="aims_settings[api_keys][<?php echo esc_attr( $id ); ?>]" value="" autocomplete="off"
  4166										placeholder="<?php echo esc_attr( $key_set ? __( 'Saved. Paste a new key to replace it.', 'ai-media-search' ) : __( 'Paste your API key', 'ai-media-search' ) ); ?>" />
  4167								</p>
  4168								<p>
  4169									<label for="aims-model-<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Model', 'ai-media-search' ); ?></label><br />
  4170									<select id="aims-model-<?php echo esc_attr( $id ); ?>" class="aims-model-select" name="aims_settings[models][<?php echo esc_attr( $id ); ?>]">
  4171										<?php foreach ( $known as $model_id => $hint ) : ?>
  4172											<option value="<?php echo esc_attr( $model_id ); ?>" <?php selected( ! $is_custom && $model === $model_id ); ?>><?php echo esc_html( $model_id . ' — ' . $hint ); ?></option>
  4173										<?php endforeach; ?>
  4174										<option value="custom" <?php selected( $is_custom ); ?>><?php esc_html_e( 'Custom model ID…', 'ai-media-search' ); ?></option>
  4175									</select>
  4176									<input type="text" class="regular-text aims-custom-model" name="aims_settings[custom_models][<?php echo esc_attr( $id ); ?>]"
  4177										value="<?php echo esc_attr( $is_custom && 'custom' !== $model ? $model : $settings['custom_models'][ $id ] ); ?>"
  4178										placeholder="<?php esc_attr_e( 'exact model id', 'ai-media-search' ); ?>" <?php echo $is_custom ? '' : 'hidden'; ?> />
  4179								</p>
  4180							</td>
  4181						</tr>
  4182					<?php endforeach; ?>
  4183					<tr>
  4184						<th scope="row"><label for="aims-language"><?php esc_html_e( 'Description language', 'ai-media-search' ); ?></label></th>
  4185						<td><input type="text" id="aims-language" class="regular-text" name="aims_settings[language]" value="<?php echo esc_attr( $settings['language'] ); ?>" /></td>
  4186					</tr>
  4187					<tr>
  4188						<th scope="row"><label for="aims-custom-prompt"><?php esc_html_e( 'Custom prompt', 'ai-media-search' ); ?></label></th>
  4189						<td>
  4190							<textarea id="aims-custom-prompt" class="large-text" rows="5" name="aims_settings[custom_prompt]"><?php echo esc_textarea( $settings['custom_prompt'] ); ?></textarea>
  4191							<p class="description"><?php esc_html_e( 'Optional extra guidance added to every request, for example product names or house style.', 'ai-media-search' ); ?></p>
  4192						</td>
  4193					</tr>
  4194					<tr>
  4195						<th scope="row"><?php esc_html_e( 'Automation', 'ai-media-search' ); ?></th>
  4196						<td>
  4197							<label><input type="checkbox" name="aims_settings[auto_index]" value="1" <?php checked( $settings['auto_index'] ); ?> /> <?php esc_html_e( 'Describe new uploads automatically', 'ai-media-search' ); ?></label><br />
  4198							<label><input type="checkbox" name="aims_settings[fill_alt]" value="1" <?php checked( $settings['fill_alt'] ); ?> /> <?php esc_html_e( 'Fill empty alt text with the AI alt sentence', 'ai-media-search' ); ?></label>
  4199						</td>
  4200					</tr>
  4201					<tr>
  4202						<th scope="row"><label for="aims-batch-size"><?php esc_html_e( 'Batch size', 'ai-media-search' ); ?></label></th>
  4203						<td>
  4204							<input type="number" id="aims-batch-size" min="1" max="10" name="aims_settings[batch_size]" value="<?php echo esc_attr( (string) $settings['batch_size'] ); ?>" />
  4205							<p class="description"><?php esc_html_e( 'Files described per request when indexing from the dashboard.', 'ai-media-search' ); ?></p>
  4206						</td>
  4207					</tr>
  4208				</table>
  4209				<p class="submit">
  4210					<?php submit_button( __( 'Save settings', 'ai-media-search' ), 'primary', 'submit', false ); ?>
  4211					<button type="button" class="button" id="aims-test"><?php esc_html_e( 'Test connection', 'ai-media-search' ); ?></button>
  4212					<span id="aims-test-result" class="aims-muted"></span>
  4213				</p>
  4214			</form>
  4215			<?php
  4216		}
  4217	}
  4218	```
  4219	
  4220	- [ ] **Step 4: Write the admin JS**
  4221	
  4222	`assets/admin.js`:
  4223	
  4224	```js
  4225	( function () {
  4226		'use strict';
  4227	
  4228		var data = window.aimsData || {};
  4229		var i18n = data.i18n || {};
  4230	
  4231		function api( path, body ) {
  4232			return fetch( data.restUrl + path, {
  4233				method: body === undefined ? 'GET' : 'POST',
  4234				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': data.nonce },
  4235				credentials: 'same-origin',
  4236				body: body === undefined ? undefined : JSON.stringify( body )
  4237			} ).then( function ( res ) {
  4238				return res.json().then( function ( json ) {
  4239					if ( ! res.ok ) {
  4240						throw new Error( ( json && json.message ) || i18n.failed );
  4241					}
  4242					return json;
  4243				} );
  4244			} );
  4245		}
  4246	
  4247		function escapeHtml( text ) {
  4248			var div = document.createElement( 'div' );
  4249			div.textContent = text == null ? '' : String( text );
  4250			return div.innerHTML;
  4251		}
  4252	
  4253		function statusHtml( item ) {
  4254			var status = item.status || 'none';
  4255			var labels = { indexed: 'Indexed', failed: 'Failed', skipped: 'Skipped', pending: 'Pending', none: 'Not indexed' };
  4256			var html = '<span class="aims-status aims-status-' + escapeHtml( status ) + '">' + escapeHtml( labels[ status ] || status ) + '</span>';
  4257			if ( item.error && status !== 'indexed' ) {
  4258				html += ' <span class="aims-error">' + escapeHtml( item.error ) + '</span>';
  4259			}
  4260			return html;
  4261		}
  4262	
  4263		// Update every place on the page that shows this attachment.
  4264		function applyResult( item ) {
  4265			var id = String( item.id );
  4266			document.querySelectorAll( '.aims-status-wrap[data-id="' + id + '"]' ).forEach( function ( el ) {
  4267				el.innerHTML = statusHtml( item );
  4268			} );
  4269			document.querySelectorAll( '.aims-tags[data-id="' + id + '"]' ).forEach( function ( el ) {
  4270				el.textContent = ( item.tags || [] ).join( ', ' );
  4271			} );
  4272			var row = document.querySelector( '.aims-row[data-id="' + id + '"]' );
  4273			if ( row ) {
  4274				row.querySelector( '.aims-description' ).textContent = item.description || '';
  4275				row.querySelector( '.aims-tags' ).textContent = ( item.tags || [] ).join( ', ' );
  4276				var btn = row.querySelector( '.aims-index-one' );
  4277				if ( btn ) {
  4278					btn.textContent = item.status === 'indexed' ? i18n.regenerate : i18n.index;
  4279				}
  4280			}
  4281			// Media modal / attachment edit screen textarea.
  4282			var textarea = document.querySelector( 'textarea[name="attachments[' + id + '][aims_description]"]' );
  4283			if ( textarea ) {
  4284				textarea.value = item.description || '';
  4285			}
  4286		}
  4287	
  4288		// ---- Tabs -------------------------------------------------------------
  4289		function activateTab( name ) {
  4290			document.querySelectorAll( '.aims-tab' ).forEach( function ( tab ) {
  4291				tab.classList.toggle( 'nav-tab-active', tab.dataset.tab === name );
  4292			} );
  4293			document.querySelectorAll( '.aims-tab-panel' ).forEach( function ( panel ) {
  4294				panel.hidden = panel.id !== 'aims-tab-' + name;
  4295			} );
  4296		}
  4297		document.querySelectorAll( '.aims-tab' ).forEach( function ( tab ) {
  4298			tab.addEventListener( 'click', function ( e ) {
  4299				e.preventDefault();
  4300				activateTab( tab.dataset.tab );
  4301				history.replaceState( null, '', '#' + tab.dataset.tab );
  4302			} );
  4303		} );
  4304		if ( location.hash === '#settings' ) {
  4305			activateTab( 'settings' );
  4306		}
  4307	
  4308		// ---- Settings helpers -------------------------------------------------
  4309		document.querySelectorAll( '.aims-model-select' ).forEach( function ( select ) {
  4310			select.addEventListener( 'change', function () {
  4311				var custom = select.parentNode.querySelector( '.aims-custom-model' );
  4312				if ( custom ) {
  4313					custom.hidden = select.value !== 'custom';
  4314				}
  4315			} );
  4316		} );
  4317	
  4318		var testBtn = document.getElementById( 'aims-test' );
  4319		if ( testBtn ) {
  4320			testBtn.addEventListener( 'click', function () {
  4321				var out = document.getElementById( 'aims-test-result' );
  4322				out.textContent = i18n.working;
  4323				testBtn.disabled = true;
  4324				api( 'test', {} ).then( function ( json ) {
  4325					out.textContent = json.message;
  4326					out.className = json.ok ? 'aims-ok' : 'aims-error';
  4327				} ).catch( function ( err ) {
  4328					out.textContent = err.message;
  4329					out.className = 'aims-error';
  4330				} ).then( function () {
  4331					testBtn.disabled = false;
  4332				} );
  4333			} );
  4334		}
  4335	
  4336		// ---- Single index / regenerate (dashboard rows and media modal) -------
  4337		document.addEventListener( 'click', function ( e ) {
  4338			var btn = e.target.closest( '.aims-index-one, .aims-regenerate' );
  4339			if ( ! btn ) {
  4340				return;
  4341			}
  4342			e.preventDefault();
  4343			var id = btn.dataset.id;
  4344			var label = btn.textContent;
  4345			btn.disabled = true;
  4346			btn.textContent = i18n.working;
  4347			api( 'index/' + id, {} ).then( applyResult ).catch( function ( err ) {
  4348				applyResult( { id: id, status: 'failed', error: err.message, tags: [] } );
  4349			} ).then( function () {
  4350				btn.disabled = false;
  4351				if ( btn.textContent === i18n.working ) {
  4352					btn.textContent = label;
  4353				}
  4354			} );
  4355		} );
  4356	
  4357		// ---- Dashboard batch loop --------------------------------------------
  4358		var selectAll = document.getElementById( 'aims-select-all' );
  4359		if ( selectAll ) {
  4360			selectAll.addEventListener( 'change', function () {
  4361				document.querySelectorAll( '.aims-select' ).forEach( function ( box ) {
  4362					box.checked = selectAll.checked;
  4363				} );
  4364			} );
  4365		}
  4366	
  4367		var state = { running: false, stop: false };
  4368	
  4369		function setProgress( done, total ) {
  4370			var bar = document.getElementById( 'aims-progress-bar' );
  4371			var text = document.getElementById( 'aims-progress-text' );
  4372			var pct = total > 0 ? Math.round( ( done / total ) * 100 ) : 0;
  4373			bar.style.width = pct + '%';
  4374			text.textContent = ( i18n.progress || '%1$s of %2$s' ).replace( '%1$s', done ).replace( '%2$s', total );
  4375		}
  4376	
  4377		function log( item ) {
  4378			var ul = document.getElementById( 'aims-log' );
  4379			var li = document.createElement( 'li' );
  4380			li.className = item.ok ? 'aims-log-ok' : 'aims-log-fail';
  4381			li.textContent = '#' + item.id + ' ' + ( item.title || '' ) + ' — ' + ( item.ok ? ( item.status || '' ) : ( item.error || '' ) );
  4382			ul.insertBefore( li, ul.firstChild );
  4383		}
  4384	
  4385		function setRunning( running ) {
  4386			state.running = running;
  4387			[ 'aims-index-selected', 'aims-index-all' ].forEach( function ( id ) {
  4388				var el = document.getElementById( id );
  4389				if ( el ) {
  4390					el.disabled = running;
  4391				}
  4392			} );
  4393			document.getElementById( 'aims-stop' ).disabled = ! running;
  4394		}
  4395	
  4396		function runBatch( ids, total, done ) {
  4397			if ( state.stop ) {
  4398				finish( i18n.stopped );
  4399				return;
  4400			}
  4401			var body = {
  4402				batch_size: data.batchSize,
  4403				retry_failed: document.getElementById( 'aims-retry-failed' ).checked
  4404			};
  4405			if ( ids ) {
  4406				body.ids = ids;
  4407			}
  4408			api( 'bulk', body ).then( function ( json ) {
  4409				json.results.forEach( function ( item ) {
  4410					applyResult( item );
  4411					log( item );
  4412				} );
  4413				done += json.results.length;
  4414				if ( ids ) {
  4415					total = done + json.remaining_ids.length;
  4416					setProgress( done, total );
  4417					if ( json.remaining_ids.length === 0 || json.results.length === 0 ) {
  4418						finish( i18n.done );
  4419						return;
  4420					}
  4421					runBatch( json.remaining_ids, total, done );
  4422				} else {
  4423					total = done + json.remaining_count;
  4424					setProgress( done, total );
  4425					if ( json.remaining_count === 0 || json.results.length === 0 ) {
  4426						finish( i18n.done );
  4427						return;
  4428					}
  4429					runBatch( null, total, done );
  4430				}
  4431			} ).catch( function ( err ) {
  4432				log( { id: '-', ok: false, error: err.message } );
  4433				finish( i18n.failed );
  4434			} );
  4435		}
  4436	
  4437		function finish( message ) {
  4438			document.getElementById( 'aims-progress-text' ).textContent += ' ' + message;
  4439			setRunning( false );
  4440			state.stop = false;
  4441		}
  4442	
  4443		function start( ids ) {
  4444			if ( state.running ) {
  4445				return;
  4446			}
  4447			document.getElementById( 'aims-log' ).innerHTML = '';
  4448			setProgress( 0, ids ? ids.length : 0 );
  4449			state.stop = false;
  4450			setRunning( true );
  4451			runBatch( ids, ids ? ids.length : 0, 0 );
  4452		}
  4453	
  4454		var indexSelected = document.getElementById( 'aims-index-selected' );
  4455		if ( indexSelected ) {
  4456			indexSelected.addEventListener( 'click', function () {
  4457				var ids = Array.prototype.map.call( document.querySelectorAll( '.aims-select:checked' ), function ( box ) {
  4458					return parseInt( box.value, 10 );
  4459				} );
  4460				if ( ids.length === 0 ) {
  4461					window.alert( i18n.noSelection );
  4462					return;
  4463				}
  4464				start( ids );
  4465			} );
  4466			document.getElementById( 'aims-index-all' ).addEventListener( 'click', function () {
  4467				start( null );
  4468			} );
  4469			document.getElementById( 'aims-stop' ).addEventListener( 'click', function () {
  4470				state.stop = true;
  4471			} );
  4472		}
  4473	}() );
  4474	```
  4475	
  4476	- [ ] **Step 5: Write the admin CSS**
  4477	
  4478	`assets/admin.css`:
  4479	
  4480	```css
  4481	.aims-wrap .aims-tabs { margin-bottom: 16px; }
  4482	.aims-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; margin: 16px 0; }
  4483	.aims-card { display: block; padding: 14px 16px; background: #fff; border: 1px solid #c3c4c7; border-left-width: 4px; text-decoration: none; color: #1d2327; }
  4484	.aims-card:hover, .aims-card.is-active { border-color: #2271b1; box-shadow: 0 0 0 1px #2271b1; }
  4485	.aims-card-count { display: block; font-size: 24px; font-weight: 600; line-height: 1.2; }
  4486	.aims-card-label { display: block; color: #646970; }
  4487	.aims-card-indexed { border-left-color: #00a32a; }
  4488	.aims-card-not_indexed { border-left-color: #dba617; }
  4489	.aims-card-failed { border-left-color: #d63638; }
  4490	.aims-card-skipped { border-left-color: #8c8f94; }
  4491	.aims-table .aims-file img { float: left; margin-right: 8px; width: 60px; height: 60px; object-fit: cover; }
  4492	.aims-table .aims-description { max-width: 360px; }
  4493	.aims-table .aims-tags { max-width: 220px; color: #50575e; }
  4494	.aims-status { display: inline-block; padding: 1px 6px; border-radius: 3px; font-size: 12px; background: #f0f0f1; }
  4495	.aims-status-indexed { background: #edfaef; color: #00600f; }
  4496	.aims-status-failed { background: #fcf0f1; color: #8a1f22; }
  4497	.aims-status-pending { background: #fcf9e8; color: #8a6d00; }
  4498	.aims-muted { color: #646970; }
  4499	.aims-error { color: #d63638; }
  4500	.aims-ok { color: #00a32a; }
  4501	.aims-controls { margin-top: 16px; }
  4502	.aims-controls .button { margin-right: 6px; }
  4503	.aims-progress { height: 10px; margin: 12px 0; background: #dcdcde; border-radius: 5px; overflow: hidden; max-width: 600px; }
  4504	.aims-progress-bar { height: 100%; width: 0; background: #2271b1; transition: width .3s ease; }
  4505	.aims-log { max-height: 240px; overflow: auto; margin: 0; padding: 8px 12px; background: #fff; border: 1px solid #c3c4c7; max-width: 600px; font-family: monospace; font-size: 12px; }
  4506	.aims-log li { margin: 0 0 2px; }
  4507	.aims-log-fail { color: #d63638; }
  4508	.aims-provider-choice { margin-right: 16px; }
  4509	.aims-custom-model { margin-top: 6px; display: block; }
  4510	.aims-custom-model[hidden] { display: none; }
  4511	```
  4512	
  4513	- [ ] **Step 6: Register in Plugin::init()**
  4514	
  4515	Add after the Attachment_Fields line in `includes/class-plugin.php`:
  4516	
  4517	```php
  4518			if ( is_admin() ) {
  4519				( new Admin_Page() )->register();
  4520			}
  4521	```
  4522	
  4523	- [ ] **Step 7: Run the tests and syntax checks**
  4524	
  4525	Run: `vendor/bin/phpunit && php -l includes/class-admin-page.php && node --check assets/admin.js`
  4526	Expected: all green, no syntax errors.
  4527	
  4528	- [ ] **Step 8: Commit**
  4529	
  4530	```bash
  4531	git add includes/class-admin-page.php includes/class-plugin.php assets tests/unit/AdminPageTest.php
  4532	git -c commit.gpgsign=false commit -m "Add admin page with dashboard and settings tabs"
  4533	```
  4534	
  4535	---
  4536	
  4537	### Task 14: readme.txt, coding standards pass, manual verification
  4538	
  4539	**Files:**
  4540	- Create: `readme.txt`, `phpcs.xml.dist`
  4541	- Modify: any file PHPCS flags
  4542	
  4543	**Interfaces:** none new.
  4544	
  4545	- [ ] **Step 1: Write readme.txt**
  4546	
  4547	`readme.txt`:
  4548	
  4549	```
  4550	=== AI Media Search ===
  4551	Contributors: danlapteacru
  4552	Tags: media library, search, ai, alt text, images
  4553	Requires at least: 6.0
  4554	Tested up to: 6.9
  4555	Requires PHP: 7.4
  4556	Stable tag: 1.0.0
  4557	License: GPLv2 or later
  4558	License URI: https://www.gnu.org/licenses/gpl-2.0.html
  4559	
  4560	Search your Media Library by what is in the picture. A vision model describes each image so the search box finds "woman on a beach" even when nobody typed it.
  4561	
  4562	== Description ==
  4563	
  4564	The WordPress media search only matches titles, captions, and descriptions. AI Media Search sends each image (and the first-page preview of each PDF) to a vision model of your choice, stores the description and tags it returns, and extends the Media Library search box to match that text. It works in list view, grid view, and the media modal inside the editor.
  4565	
  4566	**Features**
  4567	
  4568	* Automatic description of new uploads in the background.
  4569	* Dashboard with counts, filters, and a batch indexer with progress for your existing library.
  4570	* Editable AI description on every attachment, with a Regenerate button.
  4571	* Choice of provider and model: Anthropic Claude, OpenAI, or Google Gemini. Bring your own API key.
  4572	* Optional: fill empty alt text with the AI alt sentence.
  4573	* Optional custom prompt for house style or product names.
  4574	
  4575	**Requirements**
  4576	
  4577	You need an API key from the provider you choose. Each request costs a fraction of a cent to a few cents depending on the model; the settings screen shows an estimate next to each model.
  4578	
  4579	== External services ==
  4580	
  4581	This plugin sends image data to a third-party API that you select and configure. Nothing is sent until you save an API key.
  4582	
  4583	**Anthropic Claude** (https://www.anthropic.com/) — When an image or PDF preview is indexed, the plugin sends the image bytes, the instruction text, and your API key to https://api.anthropic.com/v1/messages. Terms: https://www.anthropic.com/legal/commercial-terms. Privacy: https://www.anthropic.com/legal/privacy.
  4584	
  4585	**OpenAI** (https://openai.com/) — Same data is sent to https://api.openai.com/v1/responses. Terms: https://openai.com/policies/terms-of-use. Privacy: https://openai.com/policies/privacy-policy.
  4586	
  4587	**Google Gemini** (https://ai.google.dev/) — Same data is sent to https://generativelanguage.googleapis.com/. Terms: https://ai.google.dev/gemini-api/terms. Privacy: https://policies.google.com/privacy.
  4588	
  4589	The "Test connection" button sends a short text prompt to the selected provider.
  4590	
  4591	== Installation ==
  4592	
  4593	1. Upload the plugin folder to `/wp-content/plugins/` or install it from the Plugins screen.
  4594	2. Activate it.
  4595	3. Open **AI Media Search** in the admin menu, go to the Settings tab, choose a provider, paste your API key, and save.
  4596	4. On the Dashboard tab, click **Index all not indexed** to describe your existing library.
  4597	5. Search the Media Library for something in a picture.
  4598	
  4599	== Frequently Asked Questions ==
  4600	
  4601	= Does this change my titles, captions, or descriptions? =
  4602	
  4603	No. AI text is stored in its own fields. The only optional write to a standard field is alt text, and only when it is empty and you turned that setting on.
  4604	
  4605	= Which files are described? =
  4606	
  4607	JPEG, PNG, GIF, WebP images, and PDFs for which WordPress generated a preview image (requires the Imagick extension on your server). Other files are marked as skipped.
  4608	
  4609	= Can I correct the AI? =
  4610	
  4611	Yes. Every attachment has an editable "AI description" field. Your edit is what the search uses.
  4612	
  4613	= What does it cost? =
  4614	
  4615	Only what your provider charges. The model dropdown shows a rough per-image estimate. Nothing is sent unless an API key is saved, and existing files are only processed when you start the indexer.
  4616	
  4617	== Screenshots ==
  4618	
  4619	1. Dashboard with counts, filters, and the batch indexer.
  4620	2. Settings tab with provider, model, and prompt options.
  4621	3. AI description and Regenerate button in the media modal.
  4622	4. Media Library search finding an image by its content.
  4623	
  4624	== Changelog ==
  4625	
  4626	= 1.0.0 =
  4627	* First release.
  4628	```
  4629	
  4630	- [ ] **Step 2: Write the PHPCS ruleset**
  4631	
  4632	`phpcs.xml.dist`:
  4633	
  4634	```xml
  4635	<?xml version="1.0"?>
  4636	<ruleset name="AI Media Search">
  4637		<description>WordPress Coding Standards for AI Media Search.</description>
  4638		<file>.</file>
  4639		<exclude-pattern>/vendor/*</exclude-pattern>
  4640		<exclude-pattern>/tests/*</exclude-pattern>
  4641		<exclude-pattern>/node_modules/*</exclude-pattern>
  4642		<arg name="extensions" value="php"/>
  4643		<arg name="colors"/>
  4644		<arg value="sp"/>
  4645		<config name="minimum_supported_wp_version" value="6.0"/>
  4646		<config name="testVersion" value="7.4-"/>
  4647		<rule ref="WordPress">
  4648			<exclude name="Generic.Arrays.DisallowShortArraySyntax"/>
  4649		</rule>
  4650		<rule ref="PHPCompatibilityWP"/>
  4651		<rule ref="WordPress.WP.I18n">
  4652			<properties>
  4653				<property name="text_domain" type="array">
  4654					<element value="ai-media-search"/>
  4655				</property>
  4656			</properties>
  4657		</rule>
  4658		<rule ref="WordPress.NamingConventions.PrefixAllGlobals">
  4659			<properties>
  4660				<property name="prefixes" type="array">
  4661					<element value="aims"/>
  4662					<element value="AIMS"/>
  4663				</property>
  4664			</properties>
  4665		</rule>
  4666	</ruleset>
  4667	```
  4668	
  4669	- [ ] **Step 3: Run PHPCS and fix what it reports**
  4670	
  4671	Run: `vendor/bin/phpcs`
  4672	Expected: a list of warnings and errors on first run. Run `vendor/bin/phpcbf` to auto-fix formatting, then fix the remainder by hand. Typical items: missing `// phpcs:ignore` justifications on the direct database queries in `Stats` and `uninstall.php` (already annotated), unescaped output where `status_html()` is echoed (already annotated), and `$_GET` reads without nonce verification on display-only screens (already annotated). Do not silence anything that is a real escaping or sanitization gap; fix the code instead.
  4673	
  4674	Re-run until: `vendor/bin/phpcs` prints no errors. Warnings about `file_get_contents` and `base64_encode` are already annotated in `Abstract_Provider`.
  4675	
  4676	- [ ] **Step 4: Run the full test suite one more time**
  4677	
  4678	Run: `vendor/bin/phpunit`
  4679	Expected: all green.
  4680	
  4681	- [ ] **Step 5: Commit**
  4682	
  4683	```bash
  4684	git add readme.txt phpcs.xml.dist -A
  4685	git -c commit.gpgsign=false commit -m "Add readme and pass WordPress coding standards"
  4686	```
  4687	
  4688	- [ ] **Step 6: Manual verification in a WordPress install**
  4689	
  4690	Docker Desktop is installed on this machine but was not running when the plan was written. If it can be started, use wp-env. Otherwise use any local WordPress (Local, Valet, or an existing dev site) and symlink or copy the repo into `wp-content/plugins/ai-media-search`.
  4691	
  4692	With wp-env, create `.wp-env.json` in the repo root:
  4693	
  4694	```json
  4695	{
  4696	  "core": null,
  4697	  "plugins": [ "." ],
  4698	  "config": { "WP_DEBUG": true, "WP_DEBUG_LOG": true, "DISABLE_WP_CRON": false }
  4699	}
  4700	```
  4701	
  4702	Run: `npx @wordpress/env start` then open `http://localhost:8888/wp-admin` (admin / password).
  4703	
  4704	Checklist. Tick each after seeing it work:
  4705	
  4706	- [ ] Activate the plugin with no errors in `wp-content/debug.log`.
  4707	- [ ] **AI Media Search** appears in the admin menu. Dashboard shows a warning that no key is saved; buttons are disabled.
  4708	- [ ] Settings tab: pick a provider, paste a real key, save. Reload shows the "Saved" placeholder. Choose "Custom model ID…", the text field appears; choose a known model, it hides.
  4709	- [ ] Click **Test connection**. Success message names provider and model. Enter a wrong key and confirm the error message comes from the provider.
  4710	- [ ] Upload a JPEG through Media > Add New. Within about a minute (or after visiting any page to trigger WP-Cron), the attachment details show an AI description, tags, and status "Indexed".
  4711	- [ ] Media Library list view: the "AI index" column shows the status. Grid view search for a word that appears only in the AI description returns the image. The same search inside the editor's media modal returns it.
  4712	- [ ] Open the image in the media modal, edit the AI description to include a made-up word, save, search for that word: found.
  4713	- [ ] Click **Regenerate** in the modal: button shows "Working…", then fields update without a reload.
  4714	- [ ] Upload a PDF. If Imagick is present it gets indexed from its preview; otherwise it shows "Skipped" with the no-preview message.
  4715	- [ ] Upload a video: not listed on the dashboard and no cron event scheduled (check with WP Crontrol or `wp cron event list`).
  4716	- [ ] Dashboard: with several unindexed files, click **Index all not indexed**. Progress bar advances, log lists each file, rows update in place, Stop halts after the current batch, stat cards refresh after reload.
  4717	- [ ] Dashboard: select two rows, click **Index selected**. Only those two are processed.
  4718	- [ ] Media Library list view: select rows, bulk action **Index with AI**, confirm the notice with the count and that the rows get indexed shortly after.
  4719	- [ ] Turn on **Fill empty alt text**, regenerate an image with empty alt: alt field is filled. Regenerate one with existing alt: unchanged.
  4720	- [ ] Temporarily set an invalid key, upload an image: status "Failed" with the auth error, no retry event scheduled. Restore the key.
  4721	- [ ] Deactivate the plugin: `wp cron event list` shows no `aims_index_attachment` events.
  4722	- [ ] Run the Plugin Check plugin (`wp plugin install plugin-check --activate` then Tools > Plugin Check) and fix anything it reports as an error.
  4723	
  4724	- [ ] **Step 7: Commit any fixes from manual verification**
  4725	
  4726	```bash
  4727	git add -A
  4728	git -c commit.gpgsign=false commit -m "Fix issues found during manual verification"
  4729	```
  4730	
  4731	---
  4732	
  4733	## Out of scope reminders
  4734	
  4735	Do not add embeddings, folders, frontend search, video support, key encryption, or multisite network settings. If a task seems to need one of these, stop and raise it instead of building it.
