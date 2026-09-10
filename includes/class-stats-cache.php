<?php
/**
 * Transient cache for dashboard counts.
 *
 * @package AIMS
 */

namespace AIMS;

defined( 'ABSPATH' ) || exit;

final class Stats_Cache {
	const KEY = 'aims_stats';
	const TTL = 60;

	public static function clear(): void {
		delete_transient( self::KEY );
	}
}
