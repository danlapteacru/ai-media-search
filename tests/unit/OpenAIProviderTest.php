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
