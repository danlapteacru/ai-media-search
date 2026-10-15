<?php
namespace AIMS\Tests;

use AIMS\Description_Result;
use AIMS\Providers\Registry;
use AIMS\Providers\WP_AI_Client_Provider;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Stand-in for the snake_case core builder (WP_AI_Client_Prompt_Builder).
 */
class Fake_Prompt_Builder {
	public $calls     = array();
	public $text      = '';
	public $supported = true;
	public $result;

	public function __construct( string $text ) {
		$this->text = $text;
	}

	public function __call( $name, $args ) {
		$this->calls[] = array( $name, $args );
		return $this;
	}

	public function is_supported_for_text_generation(): bool {
		return $this->supported;
	}

	public function generate_text() {
		return $this->result;
	}
}

class WpAiClientProviderTest extends TestCase {
	private $file;

	/** @var Fake_Prompt_Builder|null */
	public static $last_builder;

	/** @var mixed */
	public static $next_result;

	/** @var bool */
	public static $next_supported = true;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'get_option' )->justReturn( array() );
		self::$last_builder   = null;
		self::$next_result    = null;
		self::$next_supported = true;
		$this->file           = tempnam( sys_get_temp_dir(), 'aims' );
		file_put_contents( $this->file, 'fakejpegbytes' );
	}

	protected function tearDown(): void {
		unlink( $this->file );
		Monkey\tearDown();
		parent::tearDown();
	}

	private function define_core_client(): void {
		Functions\when( 'wp_ai_client_prompt' )->alias(
			function ( $text ) {
				$builder            = new Fake_Prompt_Builder( $text );
				$builder->result    = self::$next_result;
				$builder->supported = self::$next_supported;
				self::$last_builder = $builder;
				return $builder;
			}
		);
	}

	private function calls_named( string $name ): array {
		return array_values( array_filter( self::$last_builder->calls, function ( $c ) use ( $name ) {
			return $c[0] === $name;
		} ) );
	}

	public function test_describe_without_core_client_is_error() {
		if ( function_exists( 'wp_ai_client_prompt' ) ) {
			$this->markTestSkipped( 'wp_ai_client_prompt() was already defined by an earlier test.' );
		}
		$result = ( new WP_AI_Client_Provider( '', 'auto' ) )->describe( $this->file, 'image/jpeg', 'I' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'bad_provider', $result->get_error_code() );
	}

	public function test_registry_hides_provider_without_core_client() {
		if ( function_exists( 'wp_ai_client_prompt' ) ) {
			$this->markTestSkipped( 'wp_ai_client_prompt() was already defined by an earlier test.' );
		}
		$this->assertFalse( WP_AI_Client_Provider::is_available() );
		$this->assertArrayNotHasKey( 'wp_ai_client', Registry::classes() );
		$this->assertFalse( Registry::is_ready( 'wp_ai_client' ) );
	}

	public function test_registry_lists_provider_with_core_client() {
		$this->define_core_client();
		$this->assertTrue( WP_AI_Client_Provider::is_available() );
		$this->assertArrayHasKey( 'wp_ai_client', Registry::classes() );
		$this->assertInstanceOf( WP_AI_Client_Provider::class, Registry::make( 'wp_ai_client' ) );
		$this->assertTrue( Registry::is_keyless( 'wp_ai_client' ) );
		$this->assertFalse( Registry::is_keyless( 'claude' ) );
	}

	public function test_is_ready_follows_support_check() {
		$this->define_core_client();
		self::$next_supported = true;
		$this->assertTrue( Registry::is_ready( 'wp_ai_client' ) );
		$this->assertSame( 1, count( $this->calls_named( 'with_file' ) ), 'probe image is attached for the capability check' );

		self::$next_supported = false;
		$this->assertFalse( Registry::is_ready( 'wp_ai_client' ) );
	}

	public function test_describe_builds_prompt_and_parses_json() {
		$this->define_core_client();
		self::$next_result = '{"description":"A woman.","tags":["woman"],"alt":"A woman."}';

		$provider = new WP_AI_Client_Provider( '', 'auto' );
		$result   = $provider->describe( $this->file, 'image/jpeg', 'INSTRUCTIONS' );

		$this->assertInstanceOf( Description_Result::class, $result );
		$this->assertSame( 'Describe this image.', self::$last_builder->text );
		$this->assertSame( array( $this->file, 'image/jpeg' ), $this->calls_named( 'with_file' )[0][1] );
		$this->assertSame( array( 'INSTRUCTIONS' ), $this->calls_named( 'using_system_instruction' )[0][1] );
		$this->assertArrayHasKey( 'properties', $this->calls_named( 'as_json_response' )[0][1][0] );
		$this->assertSame( array(), $this->calls_named( 'using_model_preference' ), '"auto" sets no model preference' );
	}

	public function test_specific_model_becomes_preference() {
		$this->define_core_client();
		self::$next_result = '{"description":"x","tags":["x"],"alt":"x"}';

		( new WP_AI_Client_Provider( '', 'gpt-5-mini' ) )->describe( $this->file, 'image/png', 'I' );

		$this->assertSame( array( 'gpt-5-mini' ), $this->calls_named( 'using_model_preference' )[0][1] );
	}

	public function test_core_errors_map_to_queue_codes() {
		$this->define_core_client();
		$provider = new WP_AI_Client_Provider( '', 'auto' );

		self::$next_result = new \WP_Error( 'ai_client_error', 'slow down', array( 'status' => 429 ) );
		$this->assertSame( 'rate_limited', $provider->describe( $this->file, 'image/jpeg', 'I' )->get_error_code() );

		self::$next_result = new \WP_Error( 'ai_client_error', 'nope', 401 );
		$this->assertSame( 'auth_error', $provider->describe( $this->file, 'image/jpeg', 'I' )->get_error_code() );

		self::$next_result = new \WP_Error( 'ai_client_error', 'down', array( 'status' => 503 ) );
		$this->assertSame( 'server_error', $provider->describe( $this->file, 'image/jpeg', 'I' )->get_error_code() );

		self::$next_result = new \WP_Error( 'ai_client_error', 'odd' );
		$this->assertSame( 'bad_response', $provider->describe( $this->file, 'image/jpeg', 'I' )->get_error_code() );
	}


	public function test_test_connection() {
		$this->define_core_client();
		$provider = new WP_AI_Client_Provider( '', 'auto' );

		self::$next_result = 'OK';
		$this->assertTrue( $provider->test_connection() );

		self::$next_supported = false;
		$this->assertSame( 'auth_error', $provider->test_connection()->get_error_code() );
	}
}
