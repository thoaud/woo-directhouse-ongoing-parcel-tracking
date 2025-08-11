<?php
/**
 * Shipment Tracking Cron Class
 *
 * @package Ongoing\ShipmentTracking
 */

namespace Ongoing\ShipmentTracking;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ShipmentTrackingCron class
 */
class ShipmentTrackingCron {

	/**
	 * Constructor
	 */
	public function __construct() {
		// Hook into the cron events
		add_action( 'ongoing_shipment_tracking_cron', [ $this, 'update_tracking_for_processing_orders' ] );
		add_action( 'ongoing_shipment_tracking_unfetched_cron', [ $this, 'update_unfetched_tracking' ] );
		
		// Add custom cron interval
		add_filter( 'cron_schedules', [ $this, 'add_cron_interval' ] );
	}

	/**
	 * Add custom cron intervals
	 *
	 * @param array $schedules Existing schedules
	 * @return array Modified schedules
	 */
	public function add_cron_interval( $schedules ) {
		$schedules['every_15_minutes'] = [
			'interval' => 900, // 15 minutes
			'display'  => __( 'Every 15 Minutes', 'directhouse-ongoing-parcel-tracking' ),
		];
		
		$schedules['every_30_minutes'] = [
			'interval' => 1800, // 30 minutes
			'display'  => __( 'Every 30 Minutes', 'directhouse-ongoing-parcel-tracking' ),
		];
		
		$schedules['every_45_minutes'] = [
			'interval' => 2700, // 45 minutes
			'display'  => __( 'Every 45 Minutes', 'directhouse-ongoing-parcel-tracking' ),
		];
		
		$schedules['every_2_hours'] = [
			'interval' => 7200, // 2 hours
			'display'  => __( 'Every 2 Hours', 'directhouse-ongoing-parcel-tracking' ),
		];
		
		$schedules['every_3_hours'] = [
			'interval' => 10800, // 3 hours
			'display'  => __( 'Every 3 Hours', 'directhouse-ongoing-parcel-tracking' ),
		];
		
		$schedules['every_4_hours'] = [
			'interval' => 14400, // 4 hours
			'display'  => __( 'Every 4 Hours', 'directhouse-ongoing-parcel-tracking' ),
		];
		
		$schedules['every_6_hours'] = [
			'interval' => 21600, // 6 hours
			'display'  => __( 'Every 6 Hours', 'directhouse-ongoing-parcel-tracking' ),
		];
		
		$schedules['every_8_hours'] = [
			'interval' => 28800, // 8 hours
			'display'  => __( 'Every 8 Hours', 'directhouse-ongoing-parcel-tracking' ),
		];
		
		$schedules['every_12_hours'] = [
			'interval' => 43200, // 12 hours
			'display'  => __( 'Every 12 Hours', 'directhouse-ongoing-parcel-tracking' ),
		];
		
		$schedules['ongoing_tracking_hourly'] = [
			'interval' => 3600, // 1 hour
			'display'  => __( 'Every Hour', 'directhouse-ongoing-parcel-tracking' ),
		];
		
		return $schedules;
	}

	/**
	 * Update tracking for relevant orders
	 */
	public function update_tracking_for_processing_orders() {
		// Add debug logging
		error_log( 'Ongoing Shipment Tracking - Cron started: update_tracking_for_processing_orders' );
		
		// Use the unified method from the main class
		$tracking = new ShipmentTracking();
		error_log( 'Ongoing Shipment Tracking - Created tracking object' );
		
		$result = $tracking->unified_update_tracking( [
			'quiet' => true, // Cron should be quiet
			'force' => false, // Don't force if cron is disabled
		] );
		
		error_log( 'Ongoing Shipment Tracking - unified_update_tracking completed. Result: ' . print_r( $result, true ) );
		
		// Log results for cron
		if ( $result['updated'] > 0 || ! empty( $result['errors'] ) ) {
			error_log( sprintf( 
				'Ongoing Shipment Tracking - Cron update completed: %d updated, %d errors', 
				$result['updated'], 
				count( $result['errors'] )
			) );
		}
		
		// Store last run time
		$last_run_time = current_time( 'mysql' );
		error_log( 'Ongoing Shipment Tracking - About to update last run time to: ' . $last_run_time );
		
		$update_result = update_option( 'ongoing_shipment_tracking_last_cron_run', $last_run_time );
		error_log( 'Ongoing Shipment Tracking - update_option result: ' . ( $update_result ? 'true' : 'false' ) );
		
		// Verify the update
		$stored_time = get_option( 'ongoing_shipment_tracking_last_cron_run', 'never' );
		error_log( 'Ongoing Shipment Tracking - Stored time after update: ' . $stored_time );
		
		error_log( 'Ongoing Shipment Tracking - Cron completed: update_tracking_for_processing_orders' );
	}

	/**
	 * Update unfetched tracking for orders that have tracking numbers but haven't been fetched yet
	 */
	public function update_unfetched_tracking() {
		// Add debug logging
		error_log( 'Ongoing Shipment Tracking - Unfetched cron started: update_unfetched_tracking' );
		
		// Check if cron updates are enabled
		$enable_cron = get_option( 'ongoing_shipment_tracking_enable_cron', 'yes' );
		if ( $enable_cron !== 'yes' ) {
			error_log( 'Ongoing Shipment Tracking - Unfetched cron disabled, returning early' );
			return;
		}

		// Get configured order statuses from individual settings
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

		error_log( 'Ongoing Shipment Tracking - Unfetched cron enabled statuses: ' . implode( ', ', $enabled_statuses ) );

		// Build meta query for unfetched orders (have tracking number but no tracking data)
		$meta_query = [
			'relation' => 'AND',
			[
				'key' => 'ongoing_tracking_number',
				'compare' => 'EXISTS',
			],
			[
				'key' => 'ongoing_tracking_number',
				'value' => '',
				'compare' => '!=',
			],
			[
				'key' => 'ongoing_tracking_number',
				'value' => null,
				'compare' => '!=',
			],
			[
				'key' => '_ongoing_tracking_data',
				'compare' => 'NOT EXISTS',
			],
		];

		// Check if we should exclude delivered orders
		$exclude_delivered = get_option( 'ongoing_shipment_tracking_exclude_delivered', 'yes' ) === 'yes';
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

		// Get max updates limit
		$max_updates = intval( get_option( 'ongoing_shipment_tracking_max_updates_per_run', '50' ) );

        // Prefer repository-based unfetched discovery
        $repo = new ShipmentTrackingRepository();
        $orders = $repo->get_unfetched_orders( $enabled_statuses, $max_updates, $exclude_delivered );

		error_log( 'Ongoing Shipment Tracking - Unfetched cron found ' . count( $orders ) . ' unfetched orders' );

		if ( empty( $orders ) ) {
			error_log( 'Ongoing Shipment Tracking - Unfetched cron no orders to process, returning' );
			return;
		}

		$updated = 0;
		$errors = [];
		$api = new ShipmentTrackingAPI();

		foreach ( $orders as $order_id ) {
			$order = wc_get_order( $order_id );
			
			if ( ! $order ) {
				continue;
			}

			$tracking_number = $order->get_meta( 'ongoing_tracking_number' );
			
			// Double-check we have a tracking number
			if ( empty( $tracking_number ) ) {
				continue;
			}

			// Get tracking data from API
			$tracking_data = $api->get_tracking_data( $tracking_number );
			
			if ( is_wp_error( $tracking_data ) ) {
				$errors[] = sprintf( 'Order %d: %s', $order_id, $tracking_data->get_error_message() );
				continue;
			}

            // Persist to repository and minimally sync meta
            $latest_status = ! empty( $tracking_data['events'] ) ? $api->get_latest_status( $tracking_data['events'] ) : '';
            $repo->upsert_order_tracking( (int) $order_id, (string) $tracking_number, $tracking_data, (string) $latest_status );

            $order->update_meta_data( '_ongoing_tracking_updated', current_time( 'mysql' ) );
            if ( $latest_status ) {
                $order->update_meta_data( '_ongoing_tracking_status', $latest_status );
            }
            $order->save();

			$updated++;

			// Check if order is delivered and update status if needed
			if ( $api->is_delivered( $tracking_data['events'] ) ) {
				$order->update_status( 'completed', __( 'Order delivered according to tracking information.', 'directhouse-ongoing-parcel-tracking' ) );
			}

			// Add a small delay to avoid overwhelming the API
			usleep( 100000 ); // 0.1 second delay
		}

		// Log results
		if ( $updated > 0 ) {
			error_log( sprintf( 'Ongoing Shipment Tracking - Updated unfetched tracking for %d orders', $updated ) );
		}

		if ( ! empty( $errors ) ) {
			error_log( 'Ongoing Shipment Tracking - Errors during unfetched cron update: ' . implode( ', ', $errors ) );
		}

		// Store last unfetched run time
		update_option( 'ongoing_shipment_tracking_last_unfetched_cron_run', current_time( 'mysql' ) );
		error_log( 'Ongoing Shipment Tracking - Unfetched cron completed: update_unfetched_tracking' );
	}

	/**
	 * Manually trigger tracking update for relevant orders
	 *
	 * @return array Results
	 */
	public function manual_update_all() {
		$this->update_tracking_for_processing_orders();
		
		return [
			'success' => true,
			'message' => __( 'Tracking update completed', 'directhouse-ongoing-parcel-tracking' ),
		];
	}

	/**
	 * Get last cron run time
	 *
	 * @return string Last run time or empty string
	 */
	public function get_last_run_time() {
		return get_option( 'ongoing_shipment_tracking_last_cron_run', '' );
	}

	/**
	 * Get next scheduled run time
	 *
	 * @return int|false Next run time or false
	 */
	public function get_next_run_time() {
		return wp_next_scheduled( 'ongoing_shipment_tracking_cron' );
	}

	/**
	 * Check if cron is properly scheduled
	 *
	 * @return bool
	 */
	public function is_scheduled() {
		return wp_next_scheduled( 'ongoing_shipment_tracking_cron' ) !== false;
	}

	/**
	 * Calculate the highest frequency interval from order status settings
	 *
	 * @return string The highest frequency interval
	 */
	public function get_highest_frequency_interval() {
		$order_statuses = wc_get_order_statuses();
		$intervals = [];
		
		foreach ( $order_statuses as $status_key => $status_label ) {
			$enabled = get_option( 'ongoing_shipment_tracking_status_' . $status_key, 'no' );
			if ( $enabled === 'yes' ) {
				$interval = get_option( 'ongoing_shipment_tracking_interval_' . $status_key, 'default' );
				if ( $interval !== 'default' ) {
					$intervals[] = $interval;
				}
			}
		}
		
		// If no custom intervals are set, return the default global interval
		if ( empty( $intervals ) ) {
			$global_interval = get_option( 'ongoing_shipment_tracking_cron_interval', 'hourly' );
			// If global interval is also 'on_demand', fall back to 'hourly'
			return $global_interval === 'on_demand' ? 'hourly' : $global_interval;
		}
		
		// Define interval priorities (lower number = higher frequency)
		$interval_priorities = [
			'every_15_minutes' => 1,
			'every_30_minutes' => 2,
			'every_45_minutes' => 3,
			'hourly' => 4,
			'every_2_hours' => 5,
			'every_3_hours' => 6,
			'every_4_hours' => 7,
			'every_6_hours' => 8,
			'every_8_hours' => 9,
			'every_12_hours' => 10,
			'twicedaily' => 11,
			'daily' => 12,
			'weekly' => 13,
		];
		
		// Find the interval with the highest priority (lowest number)
		$highest_priority = 999;
		$highest_frequency_interval = 'hourly'; // fallback
		
		foreach ( $intervals as $interval ) {
			if ( isset( $interval_priorities[ $interval ] ) && $interval_priorities[ $interval ] < $highest_priority ) {
				$highest_priority = $interval_priorities[ $interval ];
				$highest_frequency_interval = $interval;
			}
		}
		
		return $highest_frequency_interval;
	}

	/**
	 * Reschedule the cron jobs
	 */
	public function reschedule() {
		// Clear existing schedules
		wp_clear_scheduled_hook( 'ongoing_shipment_tracking_cron' );
		wp_clear_scheduled_hook( 'ongoing_shipment_tracking_unfetched_cron' );
		
		// Check if cron updates are enabled
		$enable_cron = get_option( 'ongoing_shipment_tracking_enable_cron', 'yes' );
		if ( $enable_cron !== 'yes' ) {
			return;
		}
		
		// Get configured interval
		$interval = get_option( 'ongoing_shipment_tracking_cron_interval', 'on_demand' );
		
		// Handle "on demand" setting
		if ( $interval === 'on_demand' ) {
			$interval = $this->get_highest_frequency_interval();
		}
		
		// Schedule main tracking job
		if ( ! wp_next_scheduled( 'ongoing_shipment_tracking_cron' ) ) {
			wp_schedule_event( time(), $interval, 'ongoing_shipment_tracking_cron' );
		}
		
		// Schedule unfetched tracking job (runs more frequently)
		$unfetched_interval = get_option( 'ongoing_shipment_tracking_unfetched_cron_interval', 'every_15_minutes' );
		if ( ! wp_next_scheduled( 'ongoing_shipment_tracking_unfetched_cron' ) ) {
			wp_schedule_event( time(), $unfetched_interval, 'ongoing_shipment_tracking_unfetched_cron' );
		}
	}

	/**
	 * Schedule the unfetched tracking cron job
	 */
	public function schedule_unfetched_cron() {
		// Clear existing unfetched cron job
		wp_clear_scheduled_hook( 'ongoing_shipment_tracking_unfetched_cron' );
		
		// Check if cron updates are enabled
		$enable_cron = get_option( 'ongoing_shipment_tracking_enable_cron', 'yes' );
		if ( $enable_cron !== 'yes' ) {
			return false;
		}
		
		// Get configured interval for unfetched tracking
		$interval = get_option( 'ongoing_shipment_tracking_unfetched_cron_interval', 'every_15_minutes' );
		
		// Schedule unfetched tracking job
		$scheduled = wp_schedule_event( time(), $interval, 'ongoing_shipment_tracking_unfetched_cron' );
		
		return $scheduled !== false;
	}
} 