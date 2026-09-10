<?php
/**
 * Minimal WP_Error stand-in for unit tests that run without WordPress.
 */
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $errors     = array();
		public $error_data = array();

		public function __construct( $code = '', $message = '', $data = '' ) {
			if ( '' !== $code ) {
				$this->errors[ $code ][] = $message;
				if ( '' !== $data ) {
					$this->error_data[ $code ] = $data;
				}
			}
		}

		public function get_error_code() {
			$codes = array_keys( $this->errors );
			return $codes ? $codes[0] : '';
		}

		public function get_error_message( $code = '' ) {
			if ( '' === $code ) {
				$code = $this->get_error_code();
			}
			return isset( $this->errors[ $code ][0] ) ? $this->errors[ $code ][0] : '';
		}

		public function get_error_data( $code = '' ) {
			if ( '' === $code ) {
				$code = $this->get_error_code();
			}
			return isset( $this->error_data[ $code ] ) ? $this->error_data[ $code ] : null;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}
