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
