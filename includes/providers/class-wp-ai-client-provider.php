<?php
/**
 * WordPress core AI Client provider (WordPress 7.0 and newer).
 *
 * Routes requests through wp_ai_client_prompt(), so the site uses whichever
 * AI connector the administrator set up under Settings > Connectors. The
 * plugin never stores a key or calls a vendor endpoint for this provider.
 *
 * @package AIMS
 */

namespace AIMS\Providers;

use AIMS\Prompt;

defined( 'ABSPATH' ) || exit;

final class WP_AI_Client_Provider implements Provider_Interface {
	const ID = 'wp_ai_client';

	/**
	 * Model id picked in settings. "auto" or "" lets the client choose.
	 *
	 * @var string
	 */
	private $model;

	/**
	 * 1x1 transparent PNG used for the deterministic capability check.
	 */
	const PROBE_IMAGE = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

	/**
	 * Same constructor shape as the API-key providers so Registry can build any of them.
	 *
	 * @param string $api_key Ignored. Credentials live in the core connector.
	 * @param string $model   Preferred model id, or "auto".
	 */
	public function __construct( string $api_key, string $model ) {
		unset( $api_key );
		$this->model = $model;
	}

	public static function is_available(): bool {
		return function_exists( 'wp_ai_client_prompt' );
	}

	public static function get_id(): string {
		return self::ID;
	}

	public static function get_label(): string {
		return __( 'WordPress AI Client (site connector)', 'sightdex-media-search' );
	}

	public static function get_known_models(): array {
		return array(
			'auto' => __( 'Let WordPress pick a vision model from the configured connector', 'sightdex-media-search' ),
		);
	}

	/**
	 * True when core is new enough and a connector that accepts images is configured.
	 * No API call is made.
	 */
	public static function is_configured( string $model = '' ): bool {
		if ( ! self::is_available() ) {
			return false;
		}
		try {
			$provider = new self( '', $model );
			return (bool) $provider->builder( Prompt::user_text() )
				->with_file( self::PROBE_IMAGE )
				->is_supported_for_text_generation();
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	public function describe( string $file_path, string $mime_type, string $instructions ) {
		if ( ! self::is_available() ) {
			return $this->unavailable_error();
		}
		if ( ! is_readable( $file_path ) ) {
			return new \WP_Error( 'bad_file', __( 'The image file could not be read.', 'sightdex-media-search' ) );
		}

		try {
			$text = $this->builder( Prompt::user_text() )
				->with_file( $file_path, $mime_type )
				->using_system_instruction( $instructions )
				->using_max_tokens( 1024 )
				->as_json_response( Prompt::schema() )
				->generate_text();
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'server_error', $e->getMessage() );
		}

		if ( is_wp_error( $text ) ) {
			return $this->map_error( $text );
		}
		if ( ! is_string( $text ) || '' === trim( $text ) ) {
			return new \WP_Error( 'bad_response', __( 'The reply contained no text.', 'sightdex-media-search' ) );
		}

		return Prompt::parse( $text );
	}

	public function test_connection() {
		if ( ! self::is_available() ) {
			return $this->unavailable_error();
		}

		try {
			$builder = $this->builder( 'Reply with the word OK.' )->using_max_tokens( 16 );
			if ( ! $builder->is_supported_for_text_generation() ) {
				return new \WP_Error( 'auth_error', __( 'No AI connector is set up. An administrator can add one under Settings > Connectors.', 'sightdex-media-search' ) );
			}
			$text = $builder->generate_text();
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'server_error', $e->getMessage() );
		}

		if ( is_wp_error( $text ) ) {
			return $this->map_error( $text );
		}
		return true;
	}

	/**
	 * @return object Core prompt builder (WP_AI_Client_Prompt_Builder).
	 */
	private function builder( string $text ) {
		$builder = wp_ai_client_prompt( $text );
		if ( '' !== $this->model && 'auto' !== $this->model ) {
			$builder = $builder->using_model_preference( $this->model );
		}
		return $builder;
	}

	private function unavailable_error(): \WP_Error {
		return new \WP_Error( 'bad_provider', __( 'The WordPress AI Client needs WordPress 7.0 or newer.', 'sightdex-media-search' ) );
	}

	/**
	 * Translate the core client's WP_Error into the codes the queue understands,
	 * so rate limits and provider outages are retried and auth problems are not.
	 */
	private function map_error( \WP_Error $error ): \WP_Error {
		$data   = $error->get_error_data();
		$status = 0;
		if ( is_int( $data ) ) {
			$status = $data;
		} elseif ( is_array( $data ) && isset( $data['status'] ) ) {
			$status = (int) $data['status'];
		}

		$message = $error->get_error_message();
		if ( 401 === $status || 403 === $status ) {
			return new \WP_Error( 'auth_error', $message );
		}
		if ( 429 === $status ) {
			return new \WP_Error( 'rate_limited', $message );
		}
		if ( $status >= 500 ) {
			return new \WP_Error( 'server_error', $message );
		}
		return new \WP_Error( 'bad_response', $message );
	}
}
