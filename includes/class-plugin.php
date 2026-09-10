<?php
/**
 * Wires every component. Holds no logic.
 *
 * @package AIMS
 */

namespace AIMS;

defined( 'ABSPATH' ) || exit;

final class Plugin {
	/** @var Plugin|null */
	private static $instance = null;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function init(): void {
		( new Settings() )->register();
		( new Queue() )->register();
		( new Search() )->register();
		( new Rest() )->register();
		( new Attachment_Fields() )->register();

		if ( is_admin() ) {
			( new Admin_Page() )->register();
		}
	}
}
