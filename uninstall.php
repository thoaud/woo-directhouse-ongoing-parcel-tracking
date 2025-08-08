<?php
/**
 * Uninstall file for DirectHouse Ongoing Parcel Tracking
 * 
 * This file is executed when the plugin is uninstalled from WordPress.
 * It cleans up all plugin data from the database.
 */

// If uninstall not called from WordPress, exit
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Include the main plugin file to access the cleanup function
$plugin_file = __DIR__ . '/woo-directhouse-ongoing-parcel-tracking.php';

if ( file_exists( $plugin_file ) ) {
	require_once $plugin_file;
	
	// Call the cleanup function
	if ( class_exists( 'Ongoing\ShipmentTracking\ShipmentTracking' ) ) {
		\Ongoing\ShipmentTracking\ShipmentTracking::uninstall_cleanup();
	}
}
