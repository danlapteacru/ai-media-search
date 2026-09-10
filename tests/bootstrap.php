<?php
require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/stubs/class-wp-error.php';

if ( ! defined( 'AIMS_PATH' ) ) {
	define( 'AIMS_PATH', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'AIMS_VERSION' ) ) {
	define( 'AIMS_VERSION', 'test' );
}
if ( ! defined( 'AIMS_URL' ) ) {
	define( 'AIMS_URL', 'http://example.test/wp-content/plugins/ai-media-search/' );
}
if ( ! defined( 'AIMS_FILE' ) ) {
	define( 'AIMS_FILE', AIMS_PATH . 'ai-media-search.php' );
}
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', AIMS_PATH );
}

require_once AIMS_PATH . 'includes/autoload.php';
