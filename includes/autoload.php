<?php
/**
 * Autoloader for the AIMS namespace.
 *
 * AIMS\Foo_Bar                     -> includes/class-foo-bar.php
 * AIMS\Providers\Foo_Provider      -> includes/providers/class-foo-provider.php
 * AIMS\Providers\Provider_Interface-> includes/providers/interface-provider.php
 *
 * @package AIMS
 */

defined( 'ABSPATH' ) || exit;

spl_autoload_register(
	function ( $class_name ) {
		if ( 0 !== strpos( $class_name, 'AIMS\\' ) ) {
			return;
		}

		$relative = substr( $class_name, 5 );
		$parts    = explode( '\\', $relative );
		$name     = array_pop( $parts );
		$slug     = strtolower( str_replace( '_', '-', $name ) );

		if ( '-interface' === substr( $slug, -10 ) ) {
			$file = 'interface-' . substr( $slug, 0, -10 ) . '.php';
		} else {
			$file = 'class-' . $slug . '.php';
		}

		$dir = AIMS_PATH . 'includes/';
		if ( $parts ) {
			$dir .= strtolower( implode( '/', $parts ) ) . '/';
		}

		$path = $dir . $file;
		if ( file_exists( $path ) ) {
			require_once $path;
		}
	}
);
