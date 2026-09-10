<?php
/**
 * Knows every provider and builds them from settings.
 *
 * @package AIMS
 */

namespace AIMS\Providers;

use AIMS\Settings;

defined( 'ABSPATH' ) || exit;

final class Registry {
	/**
	 * @return array<string,class-string<Provider_Interface>>
	 */
	public static function classes(): array {
		return array(
			'claude' => Claude_Provider::class,
			'openai' => OpenAI_Provider::class,
			'gemini' => Gemini_Provider::class,
		);
	}

	public static function ids(): array {
		return array_keys( self::classes() );
	}

	/**
	 * @return array<string,string> id => label
	 */
	public static function labels(): array {
		$labels = array();
		foreach ( self::classes() as $id => $class ) {
			$labels[ $id ] = class_exists( $class ) ? $class::get_label() : $id;
		}
		return $labels;
	}

	public static function known_models( string $id ): array {
		$classes = self::classes();
		if ( ! isset( $classes[ $id ] ) || ! class_exists( $classes[ $id ] ) ) {
			return array();
		}
		return $classes[ $id ]::get_known_models();
	}

	/**
	 * @return Provider_Interface|\WP_Error
	 */
	public static function make( string $id ) {
		$classes = self::classes();
		if ( ! isset( $classes[ $id ] ) || ! class_exists( $classes[ $id ] ) ) {
			return new \WP_Error( 'bad_provider', __( 'Unknown AI provider.', 'ai-media-search' ) );
		}
		$class = $classes[ $id ];
		return new $class( Settings::get_api_key( $id ), Settings::get_model( $id ) );
	}

	/**
	 * @return Provider_Interface|\WP_Error
	 */
	public static function active() {
		return self::make( (string) Settings::get( 'provider' ) );
	}
}
