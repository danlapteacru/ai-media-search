<?php
/**
 * Single-option settings model.
 *
 * @package AIMS
 */

namespace AIMS;

defined( 'ABSPATH' ) || exit;

final class Settings {
	const OPTION    = 'aims_settings';
	const PROVIDERS = array( 'claude', 'openai', 'gemini' );

	public static function defaults(): array {
		$per_provider = array_fill_keys( self::PROVIDERS, '' );
		return array(
			'provider'      => 'claude',
			'api_keys'      => $per_provider,
			'models'        => $per_provider,
			'custom_models' => $per_provider,
			'language'      => 'English',
			'custom_prompt' => '',
			'auto_index'    => true,
			'fill_alt'      => false,
			'batch_size'    => 3,
		);
	}

	public static function all(): array {
		$saved    = get_option( self::OPTION, array() );
		$defaults = self::defaults();
		if ( ! is_array( $saved ) ) {
			return $defaults;
		}
		$all = array_merge( $defaults, $saved );
		foreach ( array( 'api_keys', 'models', 'custom_models' ) as $key ) {
			$all[ $key ] = array_merge( $defaults[ $key ], is_array( $saved[ $key ] ?? null ) ? $saved[ $key ] : array() );
		}
		return $all;
	}

	/**
	 * @return mixed
	 */
	public static function get( string $key ) {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	public static function get_api_key( string $provider ): string {
		$keys = self::get( 'api_keys' );
		return (string) ( $keys[ $provider ] ?? '' );
	}

	public static function get_model( string $provider ): string {
		$all   = self::all();
		$model = (string) ( $all['models'][ $provider ] ?? '' );
		if ( 'custom' === $model ) {
			$model = (string) ( $all['custom_models'][ $provider ] ?? '' );
		}
		if ( '' === $model && class_exists( '\AIMS\Providers\Registry' ) ) {
			$known = Providers\Registry::known_models( $provider );
			$model = $known ? (string) array_key_first( $known ) : '';
		}
		return $model;
	}

	public static function get_active_model(): string {
		return self::get_model( (string) self::get( 'provider' ) );
	}

	/**
	 * @param mixed $input Raw form input.
	 */
	public static function sanitize( $input ): array {
		$input    = is_array( $input ) ? $input : array();
		$previous = self::all();
		$out      = self::defaults();

		$provider        = sanitize_text_field( (string) ( $input['provider'] ?? '' ) );
		$out['provider'] = in_array( $provider, self::PROVIDERS, true ) ? $provider : 'claude';

		foreach ( self::PROVIDERS as $id ) {
			$key = trim( sanitize_text_field( (string) ( $input['api_keys'][ $id ] ?? '' ) ) );
			// An empty submitted key keeps the stored one so the password field can stay blank.
			$out['api_keys'][ $id ] = '' === $key ? (string) ( $previous['api_keys'][ $id ] ?? '' ) : $key;

			$out['models'][ $id ]        = trim( sanitize_text_field( (string) ( $input['models'][ $id ] ?? '' ) ) );
			$out['custom_models'][ $id ] = trim( sanitize_text_field( (string) ( $input['custom_models'][ $id ] ?? '' ) ) );
		}

		$language        = trim( sanitize_text_field( (string) ( $input['language'] ?? '' ) ) );
		$out['language'] = '' === $language ? 'English' : $language;

		$out['custom_prompt'] = trim( sanitize_textarea_field( (string) ( $input['custom_prompt'] ?? '' ) ) );
		$out['auto_index']    = ! empty( $input['auto_index'] );
		$out['fill_alt']      = ! empty( $input['fill_alt'] );

		$batch             = (int) ( $input['batch_size'] ?? 3 );
		$out['batch_size'] = max( 1, min( 10, 0 === $batch ? 3 : $batch ) );

		return $out;
	}

	public function register(): void {
		add_action( 'admin_init', array( $this, 'register_setting' ) );
	}

	public function register_setting(): void {
		register_setting(
			'aims_settings_group',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}
}
