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
		$classes = array(
			'claude' => Claude_Provider::class,
			'openai' => OpenAI_Provider::class,
			'gemini' => Gemini_Provider::class,
		);
		if ( WP_AI_Client_Provider::is_available() ) {
			$classes[ WP_AI_Client_Provider::ID ] = WP_AI_Client_Provider::class;
		}
		return $classes;
	}

	/**
	 * Providers that need no API key from this plugin.
	 */
	public static function is_keyless( string $id ): bool {
		return WP_AI_Client_Provider::ID === $id;
	}

	/**
	 * Whether the given (or active) provider can take requests right now.
	 */
	public static function is_ready( ?string $id = null ): bool {
		$id = $id ?? (string) Settings::get( 'provider' );
		if ( self::is_keyless( $id ) ) {
			return WP_AI_Client_Provider::is_configured( Settings::get_model( $id ) );
		}
		return '' !== Settings::get_api_key( $id );
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
			return new \WP_Error( 'bad_provider', __( 'Unknown AI provider.', 'sightdex-media-search' ) );
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
