<?php
/**
 * Removes every trace of the plugin.
 *
 * @package AIMS
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'aims_settings' );
delete_transient( 'aims_stats' );

global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\\_aims\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
