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
