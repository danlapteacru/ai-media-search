<?php
/**
 * Shared instruction text, JSON schema, and response parsing for all providers.
 *
 * @package AIMS
 */

namespace AIMS;

defined( 'ABSPATH' ) || exit;

final class Prompt {
	const MAX_TAGS = 30;

	public static function schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'description' => array(
					'type'        => 'string',
					'description' => 'Two to four sentences describing the image.',
				),
				'tags'        => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => 'Ten to twenty lowercase tags including plain synonyms.',
				),
				'alt'         => array(
					'type'        => 'string',
					'description' => 'One sentence under 125 characters suitable as HTML alt text.',
				),
			),
			'required'             => array( 'description', 'tags', 'alt' ),
			'additionalProperties' => false,
		);
	}

	public static function user_text(): string {
		return 'Describe this image.';
	}

	public static function instructions( string $language, string $custom_prompt = '' ): string {
		$lines = array(
			'You describe images for a website media library so that editors can find them later by typing plain words into a search box.',
			'Reply with JSON only, matching the schema you were given.',
			'',
			'description: Two to four sentences. Cover the main subjects, any people (how many, roughly what they are doing, notable clothing or expression; never guess names or identities), actions, setting, colours, any visible text, and the overall style (photo, illustration, screenshot, logo, diagram).',
			'tags: Ten to twenty lowercase tags, single words or short phrases. Include plain synonyms editors might type, for example "woman", "female", "lady"; "car", "vehicle", "automobile". Include the setting, colours, mood, and objects.',
			'alt: One sentence under 125 characters that works as HTML alt text.',
			'',
			sprintf( 'Write the description, tags, and alt in %s.', $language ),
		);

		$custom_prompt = trim( $custom_prompt );
		if ( '' !== $custom_prompt ) {
			$lines[] = '';
			$lines[] = 'Additional guidance from the site owner:';
			$lines[] = $custom_prompt;
		}

		return implode( "\n", $lines );
	}

	/**
	 * @return Description_Result|\WP_Error
	 */
	public static function parse( string $json ) {
		$json = trim( $json );
		$json = preg_replace( '/^```(?:json)?\s*/i', '', $json );
		$json = preg_replace( '/\s*```$/', '', $json );

		$data = json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'bad_response', __( 'The AI reply was not valid JSON.', 'ai-media-search' ) );
		}

		foreach ( array( 'description', 'tags', 'alt' ) as $field ) {
			if ( ! array_key_exists( $field, $data ) ) {
				return new \WP_Error(
					'bad_response',
					/* translators: %s: field name */
					sprintf( __( 'The AI reply is missing the "%s" field.', 'ai-media-search' ), $field )
				);
			}
		}

		if ( ! is_string( $data['description'] ) || '' === trim( $data['description'] ) ) {
			return new \WP_Error( 'bad_response', __( 'The AI reply has an empty description.', 'ai-media-search' ) );
		}
		if ( ! is_array( $data['tags'] ) || ! is_string( $data['alt'] ) ) {
			return new \WP_Error( 'bad_response', __( 'The AI reply has the wrong field types.', 'ai-media-search' ) );
		}

		$tags = array();
		foreach ( $data['tags'] as $tag ) {
			if ( ! is_string( $tag ) ) {
				continue;
			}
			$tag = strtolower( trim( $tag ) );
			if ( '' === $tag || in_array( $tag, $tags, true ) ) {
				continue;
			}
			$tags[] = $tag;
			if ( count( $tags ) >= self::MAX_TAGS ) {
				break;
			}
		}

		return new Description_Result( trim( $data['description'] ), $tags, trim( $data['alt'] ) );
	}
}
