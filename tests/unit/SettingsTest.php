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
