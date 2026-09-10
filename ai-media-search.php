<?php
/**
 * Plugin Name:       AI Media Search
 * Plugin URI:        https://wordpress.org/plugins/ai-media-search/
 * Description:       Search your Media Library by what is in the image. A vision model describes each image and PDF so the media search box finds them by content.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Dan Lapteacru
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ai-media-search
 *
 * @package AIMS
 */

defined( 'ABSPATH' ) || exit;

define( 'AIMS_VERSION', '1.0.0' );
define( 'AIMS_FILE', __FILE__ );
define( 'AIMS_PATH', plugin_dir_path( __FILE__ ) );
define( 'AIMS_URL', plugin_dir_url( __FILE__ ) );

require_once AIMS_PATH . 'includes/autoload.php';

add_action(
	'plugins_loaded',
	function () {
		AIMS\Plugin::instance()->init();
	}
);

register_deactivation_hook( __FILE__, array( 'AIMS\Queue', 'clear_all' ) );
