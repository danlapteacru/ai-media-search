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
