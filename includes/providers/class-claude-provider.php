<?php
/**
 * Anthropic Claude Messages API provider.
 *
 * @package AIMS
 */

namespace AIMS\Providers;

use AIMS\Prompt;

defined( 'ABSPATH' ) || exit;

final class Claude_Provider extends Abstract_Provider {
	const ENDPOINT = 'https://api.anthropic.com/v1/messages';

	public static function get_id(): string {
		return 'claude';
	}

	public static function get_label(): string {
		return __( 'Anthropic Claude', 'ai-media-search' );
	}

	public static function get_known_models(): array {
		return array(
			'claude-opus-5'   => __( 'Highest quality, about $0.015 per image', 'ai-media-search' ),
			'claude-sonnet-5' => __( 'Balanced, about $0.006 per image', 'ai-media-search' ),
			'claude-haiku-4-5' => __( 'Fastest and cheapest, about $0.003 per image', 'ai-media-search' ),
		);
	}

	private function headers(): array {
		return array(
			'x-api-key'         => $this->api_key,
			'anthropic-version' => '2023-06-01',
			'content-type'      => 'application/json',
		);
	}

	protected function build_request( string $b64, string $mime, string $instructions ): array {
		return array(
			'url'     => self::ENDPOINT,
			'headers' => $this->headers(),
			'body'    => array(
				'model'         => $this->model,
				'max_tokens'    => 1024,
				'system'        => $instructions,
				'messages'      => array(
					array(
						'role'    => 'user',
						'content' => array(
							array(
								'type'   => 'image',
								'source' => array(
									'type'       => 'base64',
									'media_type' => $mime,
									'data'       => $b64,
								),
							),
							array(
								'type' => 'text',
								'text' => Prompt::user_text(),
							),
						),
					),
				),
				'output_config' => array(
					'format' => array(
						'type'   => 'json_schema',
						'schema' => Prompt::schema(),
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
				'model'      => $this->model,
				'max_tokens' => 16,
				'messages'   => array(
					array(
						'role'    => 'user',
						'content' => 'Reply with the word OK.',
					),
				),
			),
		);
	}

	protected function extract_text( array $data ) {
		if ( 'refusal' === ( $data['stop_reason'] ?? '' ) ) {
			return new \WP_Error( 'refused', __( 'The model declined to describe this image.', 'ai-media-search' ) );
		}
		foreach ( (array) ( $data['content'] ?? array() ) as $block ) {
			if ( isset( $block['type'], $block['text'] ) && 'text' === $block['type'] ) {
				return (string) $block['text'];
			}
		}
		return new \WP_Error( 'bad_response', __( 'The reply contained no text.', 'ai-media-search' ) );
	}
}
