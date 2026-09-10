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
