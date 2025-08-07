<?php
/**
 * Plugin Name: DirectHouse Ongoing Parcel Tracking
 * Plugin URI: https://www.comfyballs.no
 * Description: Track shipments using the DirectHouse warehouse API.
 * Version: 1.0.1
 * Text Domain: directhouse-ongoing-parcel-tracking
 * Domain Path: /languages/
 * Author: Thomas Audunhus
 * Requires at least: 5.0
 * Tested up to: 6.4
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 */

namespace Ongoing\ShipmentTracking;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Check if WooCommerce is active
if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
	add_action(
		'admin_notices',
		function() {
			?>
			<div class="notice notice-error">
				<p><?php esc_html_e( 'DirectHouse Ongoing Parcel Tracking requires WooCommerce to be installed and activated.', 'directhouse-ongoing-parcel-tracking' ); ?></p>
			</div>
			<?php
		}
	);
	return;
}

define( __NAMESPACE__ . '\PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( __NAMESPACE__ . '\PLUGIN_URL', plugins_url( '', __FILE__ ) . '/' );
define( __NAMESPACE__ . '\PLUGIN_VERSION', '1.0.0' );

// Load text domain
add_action(
	'init',
	function() {
		load_plugin_textdomain(
			'directhouse-ongoing-parcel-tracking',
			false,
			'woo-directhouse-ongoing-parcel-tracking/languages/'
		);
	}
);

// Include required files
require_once PLUGIN_PATH . 'includes/class-shipment-tracking.php';
require_once PLUGIN_PATH . 'includes/class-shipment-tracking-api.php';
require_once PLUGIN_PATH . 'includes/class-shipment-tracking-cron.php';
require_once PLUGIN_PATH . 'includes/class-shipment-tracking-admin.php';
require_once PLUGIN_PATH . 'includes/class-shipment-tracking-frontend.php';

// Initialize the plugin
add_action( 'plugins_loaded', __NAMESPACE__ . '\\init' );

function init() {
	new ShipmentTracking();
}

// Activation hook
register_activation_hook(
	__FILE__,
	function() {
		// Schedule cron job
		if ( ! wp_next_scheduled( 'ongoing_shipment_tracking_cron' ) ) {
			wp_schedule_event( time(), 'hourly', 'ongoing_shipment_tracking_cron' );
		}
		
		// Flush rewrite rules
		flush_rewrite_rules();
	}
);

// Deactivation hook
register_deactivation_hook(
	__FILE__,
	function() {
		// Clear scheduled cron job
		wp_clear_scheduled_hook( 'ongoing_shipment_tracking_cron' );
		
		// Flush rewrite rules
		flush_rewrite_rules();
	}
); 