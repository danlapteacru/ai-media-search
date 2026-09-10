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
