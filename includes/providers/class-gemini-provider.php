<?php
/**
 * Google Gemini generateContent provider.
 *
 * @package AIMS
 */

namespace AIMS\Providers;

use AIMS\Prompt;

defined( 'ABSPATH' ) || exit;

final class Gemini_Provider extends Abstract_Provider {
	const ENDPOINT_BASE = 'https://generativelanguage.googleapis.com/v1beta/models/';

	public static function get_id(): string {
		return 'gemini';
	}

	public static function get_label(): string {
		return __( 'Google Gemini', 'ai-media-search' );
	}

	public static function get_known_models(): array {
		return array(
			'gemini-3.8-flash'      => __( 'Latest Flash, about $0.002 per image', 'ai-media-search' ),
			'gemini-3.5-flash-lite' => __( 'Flash-Lite, about $0.001 per image', 'ai-media-search' ),
			'gemini-2.5-flash'      => __( 'Previous Flash, about $0.001 per image', 'ai-media-search' ),
			'gemini-2.5-flash-lite' => __( 'Cheapest, under $0.001 per image', 'ai-media-search' ),
			'gemini-2.5-pro'        => __( 'Pro, about $0.005 per image', 'ai-media-search' ),
		);
	}

	private function url(): string {
		return self::ENDPOINT_BASE . rawurlencode( $this->model ) . ':generateContent';
	}

	private function headers(): array {
		return array(
			'x-goog-api-key' => $this->api_key,
			'Content-Type'   => 'application/json',
		);
	}

	protected function build_request( string $b64, string $mime, string $instructions ): array {
		return array(
			'url'     => $this->url(),
			'headers' => $this->headers(),
			'body'    => array(
				'systemInstruction' => array(
					'parts' => array( array( 'text' => $instructions ) ),
				),
				'contents'          => array(
					array(
						'parts' => array(
							array(
								'inline_data' => array(
									'mime_type' => $mime,
									'data'      => $b64,
								),
							),
							array( 'text' => Prompt::user_text() ),
						),
					),
				),
				'generationConfig'  => array(
					'responseMimeType' => 'application/json',
					'responseSchema'   => Prompt::schema(),
				),
			),
		);
	}

	protected function build_ping_request(): array {
		return array(
			'url'     => $this->url(),
			'headers' => $this->headers(),
			'body'    => array(
				'contents'         => array(
					array( 'parts' => array( array( 'text' => 'Reply with the word OK.' ) ) ),
				),
				'generationConfig' => array( 'maxOutputTokens' => 16 ),
			),
		);
	}

	protected function extract_text( array $data ) {
		if ( empty( $data['candidates'] ) ) {
			if ( ! empty( $data['promptFeedback']['blockReason'] ) ) {
				return new \WP_Error( 'refused', __( 'The model declined to describe this image.', 'ai-media-search' ) );
			}
			return new \WP_Error( 'bad_response', __( 'The reply contained no candidates.', 'ai-media-search' ) );
		}

		$candidate = $data['candidates'][0];
		if ( 'SAFETY' === ( $candidate['finishReason'] ?? '' ) ) {
			return new \WP_Error( 'refused', __( 'The model declined to describe this image.', 'ai-media-search' ) );
		}

		foreach ( (array) ( $candidate['content']['parts'] ?? array() ) as $part ) {
			if ( isset( $part['text'] ) && is_string( $part['text'] ) ) {
				return $part['text'];
			}
		}
		return new \WP_Error( 'bad_response', __( 'The reply contained no text.', 'ai-media-search' ) );
	}
}
