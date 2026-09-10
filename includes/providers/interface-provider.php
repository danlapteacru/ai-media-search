<?php
/**
 * Contract every vision provider implements.
 *
 * @package AIMS
 */

namespace AIMS\Providers;

defined( 'ABSPATH' ) || exit;

interface Provider_Interface {
	/**
	 * Describe one image file.
	 *
	 * @param string $file_path    Absolute path of a JPEG/PNG/GIF/WebP file.
	 * @param string $mime_type    Mime type of that file.
	 * @param string $instructions System instruction text from Prompt::instructions().
	 * @return \AIMS\Description_Result|\WP_Error
	 */
	public function describe( string $file_path, string $mime_type, string $instructions );

	/**
	 * Cheap text-only request that proves the key and model work.
	 *
	 * @return true|\WP_Error
	 */
	public function test_connection();

	public static function get_id(): string;

	public static function get_label(): string;

	/**
	 * @return array<string,string> model id => short hint shown in the settings dropdown.
	 */
	public static function get_known_models(): array;
}
