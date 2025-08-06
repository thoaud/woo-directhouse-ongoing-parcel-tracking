<?php
/**
 * Main Shipment Tracking Class
 *
 * @package Ongoing\ShipmentTracking
 */

namespace Ongoing\ShipmentTracking;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main ShipmentTracking class
 */
class ShipmentTracking {

	/**
	 * API instance
	 *
	 * @var ShipmentTrackingAPI
	 */
	private $api;

	/**
	 * Cron instance
	 *
	 * @var ShipmentTrackingCron
	 */
	private $cron;

	/**
	 * Admin instance
	 *
	 * @var ShipmentTrackingAdmin
	 */
	private $admin;

	/**
	 * Frontend instance
	 *
	 * @var ShipmentTrackingFrontend
	 */
	private $frontend;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->init_hooks();
		$this->init_components();
	}

	/**
	 * Initialize hooks
	 */
	private function init_hooks() {
		// AJAX handlers
		add_action( 'wp_ajax_ongoing_shipment_tracking_update', [ $this, 'ajax_update_tracking' ] );
		add_action( 'wp_ajax_nopriv_ongoing_shipment_tracking_update', [ $this, 'ajax_update_tracking' ] );

		// Cron hook
		add_action( 'ongoing_shipment_tracking_cron', [ $this, 'update_all_tracking' ] );

		// Order status change hook
		add_action( 'woocommerce_order_status_changed', [ $this, 'on_order_status_change' ], 10, 4 );

		// Add tracking number field to checkout
		add_action( 'woocommerce_after_checkout_billing_form', [ $this, 'add_tracking_number_field' ] );
		add_action( 'woocommerce_checkout_update_order_meta', [ $this, 'save_tracking_number' ] );
		
		// WP CLI command
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'directhouse-tracking', [ $this, 'wp_cli_command' ] );
		}
	}

	/**
	 * Initialize components
	 */
	private function init_components() {
		$this->api = new ShipmentTrackingAPI();
		$this->cron = new ShipmentTrackingCron();
		
		if ( is_admin() ) {
			$this->admin = new ShipmentTrackingAdmin();
		}
		
		$this->frontend = new ShipmentTrackingFrontend();
	}

	/**
	 * AJAX handler for updating tracking
	 */
	public function ajax_update_tracking() {
		// Verify nonce
		if ( ! wp_verify_nonce( $_POST['nonce'] ?? '', 'ongoing_shipment_tracking_nonce' ) ) {
			wp_die( 'Security check failed' );
		}

		$order_id = intval( $_POST['order_id'] ?? 0 );
		
		if ( ! $order_id ) {
			wp_send_json_error( 'Invalid order ID' );
		}

		$order = wc_get_order( $order_id );
		
		if ( ! $order ) {
			wp_send_json_error( 'Order not found' );
		}

		// Check if user can view this order
		if ( ! current_user_can( 'manage_woocommerce' ) && $order->get_customer_id() !== get_current_user_id() ) {
			wp_send_json_error( 'Access denied' );
		}

		$result = $this->update_order_tracking( $order_id );
		
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Update tracking for all processing orders
	 */
	public function update_all_tracking() {
		$orders = wc_get_orders( [
			'status' => 'processing',
			'limit'  => -1,
		] );

		$updated = 0;
		$errors = [];

		foreach ( $orders as $order ) {
			$result = $this->update_order_tracking( $order->get_id() );
			
			if ( is_wp_error( $result ) ) {
				$errors[] = sprintf( 'Order %d: %s', $order->get_id(), $result->get_error_message() );
			} else {
				$updated++;
			}
		}

		// Log results
		if ( ! empty( $errors ) ) {
			error_log( 'Ongoing Shipment Tracking - Errors during bulk update: ' . implode( ', ', $errors ) );
		}

		return [
			'updated' => $updated,
			'errors'  => $errors,
		];
	}

	/**
	 * Update tracking for a specific order
	 *
	 * @param int $order_id Order ID
	 * @return array|WP_Error Tracking data or error
	 */
	public function update_order_tracking( $order_id ) {
		$order = wc_get_order( $order_id );
		
		if ( ! $order ) {
			return new \WP_Error( 'invalid_order', 'Order not found' );
		}

		$tracking_number = $order->get_meta( 'ongoing_tracking_number' );
		
		if ( empty( $tracking_number ) ) {
			return new \WP_Error( 'no_tracking_number', 'No tracking number found' );
		}

		$tracking_data = $this->api->get_tracking_data( $tracking_number );
		
		if ( is_wp_error( $tracking_data ) ) {
			return $tracking_data;
		}

		// Store the tracking data
		$order->update_meta_data( '_ongoing_tracking_data', $tracking_data );
		$order->update_meta_data( '_ongoing_tracking_updated', current_time( 'mysql' ) );
		
		// Sync current status to separate meta field
		if ( ! empty( $tracking_data['events'] ) ) {
			$api = new ShipmentTrackingAPI();
							$current_status = $api->get_latest_status( $tracking_data['events'] );
			$order->update_meta_data( '_ongoing_tracking_status', $current_status );
		}
		
		$order->save();

		return $tracking_data;
	}

	/**
	 * Handle order status change
	 *
	 * @param int    $order_id Order ID
	 * @param string $old_status Old status
	 * @param string $new_status New status
	 * @param object $order Order object
	 */
	public function on_order_status_change( $order_id, $old_status, $new_status, $order ) {
		// Update tracking when order moves to processing
		if ( $new_status === 'processing' ) {
			$this->update_order_tracking( $order_id );
		}
	}

	/**
	 * Add tracking number field to checkout
	 *
	 * @param object $checkout Checkout object
	 */
	public function add_tracking_number_field( $checkout ) {
		// This field will be populated by the warehouse system
		// We'll keep it hidden for now as it's managed by the backend
	}

	/**
	 * Save tracking number from checkout
	 *
	 * @param int $order_id Order ID
	 */
	public function save_tracking_number( $order_id ) {
		// This will be handled by the warehouse system
		// The tracking number will be set via admin or API
	}

	/**
	 * Get tracking data for an order
	 *
	 * @param int $order_id Order ID
	 * @return array|false Tracking data or false if not found
	 */
	public function get_tracking_data( $order_id ) {
		$order = wc_get_order( $order_id );
		
		if ( ! $order ) {
			return false;
		}

		return $order->get_meta( '_ongoing_tracking_data' );
	}

	/**
	 * Get current tracking status for an order
	 *
	 * @param int $order_id Order ID
	 * @return string|false Current status or false if not found
	 */
	public function get_tracking_status( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return false;
		}
		return $order->get_meta( '_ongoing_tracking_status' );
	}

	/**
	 * Get shipping method information from order
	 *
	 * @param int $order_id Order ID
	 * @return array Shipping method information
	 */
	public function get_shipping_method_info( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return [
				'method_id' => '',
				'method_title' => '',
				'shipping_method' => ''
			];
		}

		$shipping_methods = $order->get_shipping_methods();
		if ( empty( $shipping_methods ) ) {
			return [
				'method_id' => '',
				'method_title' => '',
				'shipping_method' => ''
			];
		}

		$shipping_method = reset( $shipping_methods );
		$method_id = $shipping_method->get_method_id();
		$method_title = $shipping_method->get_method_title();
		$shipping_method_name = $order->get_shipping_method();
		
		// Remove instance ID if present (e.g., "flat_rate:15" -> "flat_rate")
		$method_id = strpos( $method_id, ':' ) ? substr( $method_id, 0, strpos( $method_id, ':' ) ) : $method_id;

		// Check for Instabox using custom function if available
		if ( function_exists( 'get_instabox_shipping_method_from_order' ) ) {
			$instabox_method = get_instabox_shipping_method_from_order( $order );
			if ( ! empty( $instabox_method ) ) {
				$method_id = $instabox_method;
			}
		}

		// Check if any shipping method field contains "instabox" (case-insensitive)
		$instabox_indicators = [
			strtolower( $method_id ),
			strtolower( $method_title ),
			strtolower( $shipping_method_name )
		];

		foreach ( $instabox_indicators as $indicator ) {
			if ( strpos( $indicator, 'instabox' ) !== false ) {
				$method_id = 'instabox';
				break;
			}
		}

		return [
			'method_id' => $method_id,
			'method_title' => $method_title,
			'shipping_method' => $shipping_method_name
		];
	}

	/**
	 * Get shipping method ID from order (backward compatibility)
	 *
	 * @param int $order_id Order ID
	 * @return string Shipping method ID
	 */
	public function get_shipping_method_id( $order_id ) {
		$info = $this->get_shipping_method_info( $order_id );
		return $info['method_id'];
	}

	/**
	 * Get API instance
	 *
	 * @return ShipmentTrackingAPI
	 */
	public function get_api() {
		return $this->api;
	}

	/**
	 * WP CLI command handler
	 *
	 * @param array $args Command arguments
	 * @param array $assoc_args Command options
	 */
	public function wp_cli_command( $args, $assoc_args ) {
		$command = $args[0] ?? 'update';
		
		switch ( $command ) {
			case 'update':
				$this->wp_cli_update_tracking( $assoc_args );
				break;
			case 'status':
				$this->wp_cli_status( $assoc_args );
				break;
			default:
				\WP_CLI::error( 'Unknown command. Use "update" or "status".' );
		}
	}

	/**
	 * WP CLI update tracking command
	 *
	 * @param array $assoc_args Command options
	 */
	private function wp_cli_update_tracking( $assoc_args ) {
		// Check if cron is enabled
		$enable_cron = get_option( 'ongoing_shipment_tracking_enable_cron', 'yes' );
		if ( $enable_cron !== 'yes' && ! isset( $assoc_args['force'] ) ) {
			\WP_CLI::warning( 'Cron updates are disabled. Use --force to override.' );
			return;
		}

		// Get enabled order statuses
		$order_statuses = wc_get_order_statuses();
		$enabled_statuses = [];
		
		foreach ( $order_statuses as $status_key => $status_label ) {
			$enabled = get_option( 'ongoing_shipment_tracking_status_' . $status_key, 'no' );
			if ( $enabled === 'yes' ) {
				$enabled_statuses[] = str_replace( 'wc-', '', $status_key );
			}
		}
		
		// If no statuses are enabled, use defaults
		if ( empty( $enabled_statuses ) ) {
			$enabled_statuses = [ 'processing', 'completed' ];
		}

		// Check exclude delivered setting
		$exclude_delivered = get_option( 'ongoing_shipment_tracking_exclude_delivered', 'yes' ) === 'yes';
		
		// Override with CLI options if provided
		if ( isset( $assoc_args['statuses'] ) ) {
			$enabled_statuses = explode( ',', $assoc_args['statuses'] );
		}
		
		if ( isset( $assoc_args['include-delivered'] ) ) {
			$exclude_delivered = false;
		}

		// Build meta query - only get orders that have a tracking number
		$meta_query = [
			[
				'key' => 'ongoing_tracking_number',
				'compare' => 'EXISTS',
			],
			[
				'key' => 'ongoing_tracking_number',
				'value' => '',
				'compare' => '!=',
			],
		];

		if ( $exclude_delivered ) {
			$meta_query[] = [
				'relation' => 'OR',
				[
					'key' => '_ongoing_tracking_status',
					'compare' => 'NOT EXISTS',
				],
				[
					'key' => '_ongoing_tracking_status',
					'value' => 'DELIVERED',
					'compare' => '!=',
				],
			];
		}

		// Get all orders with the specified statuses
		$all_orders = wc_get_orders( [
			'status' => $enabled_statuses,
			'limit'  => -1,
			'return' => 'ids',
		] );
		
		// Filter orders that actually have tracking numbers
		$orders = [];
		foreach ( $all_orders as $order_id ) {
			$tracking_number = get_post_meta( $order_id, 'ongoing_tracking_number', true );
			if ( ! empty( $tracking_number ) ) {
				$orders[] = $order_id;
			}
		}
		
		\WP_CLI::log( sprintf( 'Total orders with status %s: %d', implode( ', ', $enabled_statuses ), count( $all_orders ) ) );
		\WP_CLI::log( sprintf( 'Orders with tracking numbers: %d', count( $orders ) ) );

		if ( empty( $orders ) ) {
			\WP_CLI::success( 'No orders found matching the criteria.' );
			return;
		}

		\WP_CLI::log( sprintf( 'Found %d orders to update. Starting update process...', count( $orders ) ) );

		$updated = 0;
		$errors = [];
		$progress = \WP_CLI\Utils\make_progress_bar( 'Updating tracking', count( $orders ) );

		foreach ( $orders as $order_id ) {
			$progress->tick();
			
			$result = $this->update_order_tracking( $order_id );
			
			if ( is_wp_error( $result ) ) {
				$tracking_number = get_post_meta( $order_id, 'ongoing_tracking_number', true );
				$errors[] = sprintf( 'Order %d (tracking: %s): %s', $order_id, $tracking_number, $result->get_error_message() );
			} else {
				$updated++;
			}
		}

		$progress->finish();

		// Display results
		\WP_CLI::success( sprintf( 'Updated %d orders successfully.', $updated ) );
		
		if ( ! empty( $errors ) ) {
			\WP_CLI::warning( sprintf( 'Encountered %d errors:', count( $errors ) ) );
			foreach ( $errors as $error ) {
				\WP_CLI::log( '  - ' . $error );
			}
		}
	}

	/**
	 * WP CLI status command
	 *
	 * @param array $assoc_args Command options
	 */
	private function wp_cli_status( $assoc_args ) {
		$enable_cron = get_option( 'ongoing_shipment_tracking_enable_cron', 'yes' );
		$cron_interval = get_option( 'ongoing_shipment_tracking_cron_interval', 'hourly' );
		$exclude_delivered = get_option( 'ongoing_shipment_tracking_exclude_delivered', 'yes' );
		
		$order_statuses = wc_get_order_statuses();
		$enabled_statuses = [];
		
		foreach ( $order_statuses as $status_key => $status_label ) {
			$enabled = get_option( 'ongoing_shipment_tracking_status_' . $status_key, 'no' );
			if ( $enabled === 'yes' ) {
				$enabled_statuses[] = $status_label;
			}
		}

		\WP_CLI::log( 'Ongoing Shipment Tracking Status:' );
		\WP_CLI::log( '  Cron Enabled: ' . ( $enable_cron === 'yes' ? 'Yes' : 'No' ) );
		\WP_CLI::log( '  Cron Interval: ' . $cron_interval );
		\WP_CLI::log( '  Exclude Delivered: ' . ( $exclude_delivered === 'yes' ? 'Yes' : 'No' ) );
		\WP_CLI::log( '  Enabled Statuses: ' . implode( ', ', $enabled_statuses ) );
		
		// Check next scheduled cron
		$next_scheduled = wp_next_scheduled( 'ongoing_shipment_tracking_cron' );
		if ( $next_scheduled ) {
			\WP_CLI::log( '  Next Cron Run: ' . date( 'Y-m-d H:i:s', $next_scheduled ) );
		} else {
			\WP_CLI::log( '  Next Cron Run: Not scheduled' );
		}
	}
}