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
     * Repository instance
     *
     * @var ShipmentTrackingRepository
     */
    private $repository;

    /**
     * Order IDs scheduled for retry in current parallel run
     *
     * @var array<int,bool>
     */
    private $pendingRetryOrderIds = [];

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
			// Determine available commands based on environment
            $available_commands = [ 'update', 'update-unfetched', 'schedule-unfetched', 'cleanup', 'backfill', 'status', 'help' ];
			
			// Only include assign-test-numbers in development environments
			$dev_environments = [ 'local', 'dev', 'development', 'test', 'staging', 'debug' ];
			$current_env = defined( 'WP_ENV' ) ? WP_ENV : '';
			
			if ( in_array( strtolower( $current_env ), $dev_environments ) ) {
				$available_commands[] = 'assign-test-numbers';
			}
			

			\WP_CLI::add_command( 'directhouse-tracking', [ $this, 'wp_cli_command' ], [
				'shortdesc' => 'Manage DirectHouse Ongoing Parcel Tracking',
				'synopsis' => [
					[
						'type' => 'positional',
						'name' => 'command',
						'description' => 'Command to run',
						'optional' => false,
						'options' => $available_commands
					],
					[
						'type' => 'assoc',
						'name' => 'statuses',
						'description' => 'Comma-separated list of order statuses to process',
						'optional' => true,
					],
					[
						'type' => 'assoc',
						'name' => 'limit',
						'description' => 'Maximum number of orders to process',
						'optional' => true,
					],
					[
						'type' => 'flag',
						'name' => 'include-delivered',
						'description' => 'Include orders that are already delivered',
						'optional' => true,
					],
					[
						'type' => 'flag',
						'name' => 'force',
						'description' => 'Force execution even if cron is disabled',
						'optional' => true,
					],
					[
						'type' => 'flag',
						'name' => 'quiet',
						'description' => 'Suppress verbose output',
						'optional' => true,
					],
					[
						'type' => 'flag',
						'name' => 'parallel',
						'description' => 'Use parallel processing for faster updates',
						'optional' => true,
					],
					[
						'type' => 'flag',
						'name' => 'fast-query',
						'description' => 'Use optimized SQL queries for large datasets',
						'optional' => true,
					],
					[
						'type' => 'assoc',
						'name' => 'file',
						'description' => 'File path containing tracking numbers (for assign-test-numbers command)',
						'optional' => true,
					],
					[
						'type' => 'flag',
						'name' => 'all',
						'description' => 'Clean up ALL tracking data from all orders (for cleanup command)',
						'optional' => true,
					],
					[
						'type' => 'flag',
						'name' => 'dry-run',
						'description' => 'Show what would be backfilled without actually doing it',
						'optional' => true,
					],
				],
				'when' => 'after_wp_load',
			] );
		}
	}

	/**
	 * Initialize components
	 */
	private function init_components() {
		$this->api = new ShipmentTrackingAPI();
		$this->cron = new ShipmentTrackingCron();
        $this->repository = new ShipmentTrackingRepository();
		
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
	 * Update tracking for all enabled status orders
	 */
	public function update_all_tracking() {
		// Get enabled statuses and settings using shared method
		$status_data = $this->get_enabled_order_statuses( [], true ); // quiet mode for cron
		$enabled_statuses = $status_data['statuses'];
		$status_settings = $status_data['settings'];
		
		// Get orders to update using shared method
		$orders = $this->get_orders_to_update( $enabled_statuses, $status_settings, [], true ); // quiet mode for cron
		
		if ( empty( $orders ) ) {
			return [
				'updated' => 0,
				'errors'  => [],
				'processed' => 0,
			];
		}
		
		// Get batch size from settings or use default
		$batch_size = intval( get_option( 'ongoing_shipment_tracking_batch_size', '50' ) );
		$batch_size = max( 10, min( 200, $batch_size ) ); // Ensure reasonable bounds
		
		$updated = 0;
		$errors = [];
		$processed = 0;
		$page = 1;
		$start_time = time();
		
		do {
			// Check memory and time limits before processing batch
			if ( $this->memory_exceeded() ) {
				error_log( 'Ongoing Shipment Tracking - Memory limit exceeded, stopping batch processing' );
				break;
			}
			
			if ( $this->time_exceeded( $start_time ) ) {
				error_log( 'Ongoing Shipment Tracking - Time limit exceeded, stopping batch processing' );
				break;
			}

			if(is_multisite()){
				switch_to_blog(get_current_site()->blog_id);
			}
			// Get orders in batches to prevent memory issues
			$batch_orders = wc_get_orders( [
				'status' => $enabled_statuses,
				'limit'  => $batch_size,
				'type' => 'shop_order',
				'paged'  => $page,
				'orderby' => 'ID',
				'order' => 'ASC',
			] );

			if(is_multisite()){
				restore_current_blog();
			}
			
			if ( empty( $batch_orders ) ) {
				break;
			}
			
			$batch_updated = 0;
			$batch_errors = [];
			
			foreach ( $batch_orders as $order ) {
				// Check limits before each order
				if ( $this->memory_exceeded() || $this->time_exceeded( $start_time ) ) {
					error_log( 'Ongoing Shipment Tracking - Limits exceeded during order processing, stopping' );
					break 2; // Break out of both loops
				}
				
				$result = $this->update_order_tracking( $order->get_id() );
				
				if ( is_wp_error( $result ) ) {
					$batch_errors[] = sprintf( 'Order %d: %s', $order->get_id(), $result->get_error_message() );
				} else {
					$batch_updated++;
				}
				
				$processed++;
			}
			
			$updated += $batch_updated;
			$errors = array_merge( $errors, $batch_errors );
			
			// Log batch progress with memory usage
			if ( $batch_updated > 0 || ! empty( $batch_errors ) ) {
				error_log( sprintf( 
					'Ongoing Shipment Tracking - Batch %d: %d updated, %d errors, %d total processed, Memory: %s', 
					$page, 
					$batch_updated, 
					count( $batch_errors ), 
					$processed,
					$this->format_memory_usage()
				) );
			}
			
			$page++;
			
			// Safety check to prevent infinite loops
			if ( $page > 100 ) {
				error_log( 'Ongoing Shipment Tracking - Safety limit reached, stopping batch processing' );
				break;
			}
			
			// Free memory after checking count
			$orders_count = count( $batch_orders );
			unset( $batch_orders );
			
			// Small delay to prevent overwhelming the system
			usleep( 100000 ); // 0.1 seconds
			
		} while ( $orders_count === $batch_size );

		// Log final results with duration and memory
		if ( $updated > 0 || ! empty( $errors ) ) {
			error_log( sprintf( 
				'Ongoing Shipment Tracking - Bulk update completed: %d updated, %d errors, %d total processed, Duration: %ds, Memory: %s', 
				$updated, 
				count( $errors ), 
				$processed,
				time() - $start_time,
				$this->format_memory_usage()
			) );
		}

		return [
			'updated' => $updated,
			'errors'  => $errors,
			'processed' => $processed,
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

        // Check rate limiting before making API call
		if ( ! $this->can_make_api_request( $tracking_number ) ) {
			// Wait for rate limit to reset (quiet mode for sequential processing)
			$this->wait_for_rate_limit_reset( true );
		}

        // Mark as fetched-in-progress to avoid duplicate fetch attempts elsewhere
        $existing_payload = $order->get_meta( '_ongoing_tracking_data' );
        if ( $existing_payload === '' || $existing_payload === null ) {
            $order->update_meta_data( '_ongoing_tracking_data', '{}' );
            $order->update_meta_data( '_ongoing_tracking_updated', gmdate( 'Y-m-d H:i:s' ) );
            $order->save();
        }

        $tracking_data = $this->api->get_tracking_data( $tracking_number );
		
		if ( is_wp_error( $tracking_data ) ) {
			return $tracking_data;
		}

        // Persist to dedicated table first, then sync a minimal status/meta snapshot
        $latest_status = '';
        if ( ! empty( $tracking_data['events'] ) ) {
            $latest_status = $this->api->get_latest_status( $tracking_data['events'] );
        }

        // Store in repository
        $this->repository->upsert_order_tracking( (int) $order_id, (string) $tracking_number, $tracking_data, (string) $latest_status );

        // Keep meta in sync for quick UI reads that still depend on order meta
        return $this->atomic_update_order_tracking( $order, $tracking_data );
	}

	/**
	 * Atomically update order tracking data with transaction safety
	 *
	 * @param \WC_Order $order Order object
	 * @param array $tracking_data Tracking data from API
	 * @return array|WP_Error Tracking data or error
	 */
    private function atomic_update_order_tracking( $order, $tracking_data ) {
		global $wpdb;
		
		// Start transaction
		$wpdb->query( 'START TRANSACTION' );
		
		try {
			// Get current time in UTC to match API timezone
			$current_time_utc = gmdate( 'Y-m-d H:i:s' );
			
            // Prepare meta snapshot (minimal, no large blobs)
			$meta_updates = [
				'_ongoing_tracking_updated' => $current_time_utc,
			];
			
            // Add status if events are available
            $current_status = '';
            if ( ! empty( $tracking_data['events'] ) ) {
                $current_status = $this->api->get_latest_status( $tracking_data['events'] );
                $meta_updates['_ongoing_tracking_status'] = $current_status;
            }
			
			// Update all meta data atomically
			foreach ( $meta_updates as $meta_key => $meta_value ) {
				$order->update_meta_data( $meta_key, $meta_value );
			}
			
			// Save the order
			$save_result = $order->save();
			
			// Check if save was successful (returns order ID on success, false on failure)
			if ( ! $save_result ) {
				throw new \Exception( 'Failed to save order: save() returned false' );
			}
			
            // Also persist the full payload to repository table for fast reads
            $tracking_number = $order->get_meta( 'ongoing_tracking_number' );
            if ( ! empty( $tracking_number ) ) {
                ( new ShipmentTrackingRepository() )->upsert_order_tracking(
                    (int) $order->get_id(),
                    (string) $tracking_number,
                    $tracking_data,
                    (string) $current_status
                );
            }

            // Commit transaction
            $wpdb->query( 'COMMIT' );
			
			// Log successful update
			error_log( sprintf( 
				'Ongoing Shipment Tracking - Successfully updated tracking for order %d with %d meta fields at %s UTC', 
				$order->get_id(), 
				count( $meta_updates ),
				$current_time_utc
			) );
			
			return $tracking_data;
			
		} catch ( \Exception $e ) {
			// Rollback transaction on error
			$wpdb->query( 'ROLLBACK' );
			
			error_log( sprintf( 
				'Ongoing Shipment Tracking - Failed to update tracking for order %d: %s', 
				$order->get_id(), 
				$e->getMessage() 
			) );
			
			return new \WP_Error( 'update_failed', 'Failed to update order tracking data: ' . $e->getMessage() );
		}
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
        // Prefer the repository table
        $data = $this->repository->get_tracking_by_order_id( (int) $order_id );
        if ( $data !== false ) {
            return $data;
        }
        // Fallback to meta for backward compatibility
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
        // Fast path via repository
        $status = $this->repository->get_latest_status_by_order_id( (int) $order_id );
        if ( $status ) {
            return $status;
        }
        // Fallback to meta
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
	 * Process multiple orders in parallel using cURL multi
	 *
	 * @param array $order_ids Array of order IDs to process
	 * @param bool $quiet Whether to suppress output
	 * @return array Array of results keyed by order ID
	 */
    private function process_orders_parallel( $order_ids, $quiet = false ) {
		$results = [];
		$requests = [];
		$total_retries = 0;
		$max_retries = 3;
		
		// Prepare requests for all orders
		foreach ( $order_ids as $order_id ) {
			$tracking_number = get_post_meta( $order_id, 'ongoing_tracking_number', true );
			
			if ( empty( $tracking_number ) ) {
				$results[ $order_id ] = new \WP_Error( 'no_tracking_number', 'No tracking number found' );
				continue;
			}
			
			// Check rate limiting before preparing request
			if ( ! $this->can_make_api_request( $tracking_number ) ) {
				// Wait for rate limit to reset
				$this->wait_for_rate_limit_reset( $quiet );
			}
			
			// Build the API URL
			$api_base_url = apply_filters( 'ongoing_shipment_tracking_api_base_url', 'https://warehouse.directhouse.no/api/' );
			$url = $api_base_url . 'fullOrderTracking/' . urlencode( $tracking_number );
			
			// Set timeout based on environment - use same logic as API class
			if ( defined( 'WP_ENV' ) && WP_ENV === 'local' ) {
				$timeout = 10;
			} else {
				$timeout = 5;
			}
			
			// Prepare the request
			$requests[ $order_id ] = [
				'url' => $url,
				'timeout' => $timeout,
				'order_id' => $order_id,
				'tracking_number' => $tracking_number,
				'retry_count' => 0,
			];
			
			if ( ! $quiet ) {
				\WP_CLI::log( sprintf( 'Preparing request for order %d (tracking: %s)', $order_id, $tracking_number ) );
			}
		}
		
		if ( empty( $requests ) ) {
			return [ 'results' => $results, 'retry_count' => 0 ];
		}
		
		// Use cURL multi for parallel requests
		if ( ! $quiet ) {
			\WP_CLI::log( sprintf( 'Making %d parallel API requests...', count( $requests ) ) );
		}
		
		$multi_handle = curl_multi_init();
		$curl_handles = [];
		
		// Add all requests to the multi handle
		foreach ( $requests as $order_id => $request ) {
			$ch = curl_init();
			curl_setopt_array( $ch, [
				CURLOPT_URL => $request['url'],
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT => $request['timeout'],
				CURLOPT_CONNECTTIMEOUT => $request['timeout'],
				CURLOPT_USERAGENT => 'WP-Ongoing-Shipment-Tracking/1.0.0 (' . get_bloginfo( 'url' ) . ')',
				CURLOPT_HTTPHEADER => [
					'Accept: application/json',
				],
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_SSL_VERIFYPEER => true,
			] );
			
			curl_multi_add_handle( $multi_handle, $ch );
			$curl_handles[ $order_id ] = $ch;
		}
		
		// Execute all requests
		$active = null;
		do {
			$mrc = curl_multi_exec( $multi_handle, $active );
		} while ( $mrc == CURLM_CALL_MULTI_PERFORM );
		
		while ( $active && $mrc == CURLM_OK ) {
			if ( curl_multi_select( $multi_handle ) != -1 ) {
				do {
					$mrc = curl_multi_exec( $multi_handle, $active );
				} while ( $mrc == CURLM_CALL_MULTI_PERFORM );
			}
		}
		
		// Process results and classify failures
        $failed_requests = [];
        $retryable_errors = [];
        $permanent_errors = [];
		
		foreach ( $curl_handles as $order_id => $ch ) {
			$http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
			$response_body = curl_multi_getcontent( $ch );
			$curl_error = curl_error( $ch );
			
			curl_multi_remove_handle( $multi_handle, $ch );
			curl_close( $ch );
			
			if ( $curl_error ) {
				$error_type = $this->classify_curl_error( $curl_error );
				if ( $error_type === 'retryable' ) {
					$retryable_errors[ $order_id ] = [
						'error' => 'cURL error: ' . $curl_error,
						'type' => 'curl_error',
						'request' => $requests[ $order_id ],
					];
				} else {
					$permanent_errors[ $order_id ] = [
						'error' => 'cURL error: ' . $curl_error,
						'type' => 'curl_error',
					];
				}
				continue;
			}
			
			if ( $http_code !== 200 ) {
				$error_type = $this->classify_http_error( $http_code );
				if ( $error_type === 'retryable' ) {
					$retryable_errors[ $order_id ] = [
						'error' => sprintf( 'API returned status code %d', $http_code ),
						'type' => 'api_error',
						'request' => $requests[ $order_id ],
					];
				} else {
					$permanent_errors[ $order_id ] = [
						'error' => sprintf( 'API returned status code %d', $http_code ),
						'type' => 'api_error',
					];
				}
				continue;
			}
			
			$data = json_decode( $response_body, true );
			
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				$retryable_errors[ $order_id ] = [
					'error' => 'Failed to parse API response',
					'type' => 'json_decode_error',
					'request' => $requests[ $order_id ],
				];
				continue;
			}
			
			// Check for API error response - but don't count responses with "error" strings as failures
			// Only count actual technical failures for batch size adjustment
			if ( isset( $data['error'] ) ) {
				// This is a valid API response with an error message, not a technical failure
				// We'll still process it as a valid response
				$tracking_data = [
					'events' => [],
					'raw_data' => $data,
					'last_updated' => current_time( 'mysql' ),
					'api_error' => $data['error'],
				];
				
				// Update the order with tracking data using atomic update
				$order = wc_get_order( $order_id );
				if ( $order ) {
					$update_result = $this->atomic_update_order_tracking( $order, $tracking_data );
					if ( is_wp_error( $update_result ) ) {
						$retryable_errors[ $order_id ] = [
							'error' => $update_result->get_error_message(),
							'type' => 'update_failed',
							'request' => $requests[ $order_id ],
						];
						continue;
					}
				}
				
				$results[ $order_id ] = $tracking_data;
				continue;
			}
			
			// Validate response structure
			if ( ! isset( $data['events'] ) || ! is_array( $data['events'] ) ) {
				$permanent_errors[ $order_id ] = [
					'error' => 'Invalid response structure from API',
					'type' => 'invalid_response',
				];
				continue;
			}
			
			// Process and format the events
			$api = $this->get_api();
			$formatted_events = $api->format_events( $data['events'] );
			
			$tracking_data = [
				'events' => $formatted_events,
				'raw_data' => $data,
				'last_updated' => current_time( 'mysql' ),
			];
			
			// Update the order with tracking data using atomic update
			$order = wc_get_order( $order_id );
			if ( $order ) {
				$update_result = $this->atomic_update_order_tracking( $order, $tracking_data );
				if ( is_wp_error( $update_result ) ) {
					$retryable_errors[ $order_id ] = [
						'error' => $update_result->get_error_message(),
						'type' => 'update_failed',
						'request' => $requests[ $order_id ],
					];
					continue;
				}
			}
			
			$results[ $order_id ] = $tracking_data;
		}
		
		curl_multi_close( $multi_handle );
		
        // Handle permanent errors immediately
		foreach ( $permanent_errors as $order_id => $error_info ) {
			$results[ $order_id ] = new \WP_Error( $error_info['type'], $error_info['error'] );
			if ( ! $quiet ) {
				\WP_CLI::warning( sprintf( 'Permanent error for order %d: %s', $order_id, $error_info['error'] ) );
			}
		}
        
        // Defer retryable errors to the caller so they can be appended to the end of the queue
        $retryable_order_ids = array_keys( $retryable_errors );
        
        return [
            'results' => $results,
            'retry_count' => 0, // retries happen later by caller
            'retryable' => $retryable_order_ids,
        ];
	}

	/**
	 * Classify cURL errors as retryable or permanent
	 *
	 * @param string $curl_error cURL error message
	 * @return string 'retryable' or 'permanent'
	 */
	private function classify_curl_error( $curl_error ) {
		$retryable_errors = [
			'CURLE_COULDNT_CONNECT',
			'CURLE_COULDNT_RESOLVE_HOST',
			'CURLE_OPERATION_TIMEDOUT',
			'CURLE_GOT_NOTHING',
			'CURLE_SEND_ERROR',
			'CURLE_RECV_ERROR',
			'CURLE_SSL_CONNECT_ERROR',
			'CURLE_PARTIAL_FILE',
		];
		
		foreach ( $retryable_errors as $retryable_error ) {
			if ( strpos( $curl_error, $retryable_error ) !== false ) {
				return 'retryable';
			}
		}
		
		return 'permanent';
	}

	/**
	 * Classify HTTP status codes as retryable or permanent
	 *
	 * @param int $http_code HTTP status code
	 * @return string 'retryable' or 'permanent'
	 */
	private function classify_http_error( $http_code ) {
		// Retryable status codes: 5xx server errors, 429 rate limit, 0 (timeout)
		$retryable_codes = [ 0, 429, 500, 502, 503, 504, 507, 508, 509 ];
		
		if ( in_array( $http_code, $retryable_codes ) ) {
			return 'retryable';
		}
		
		return 'permanent';
	}

	/**
	 * Check if a WP_Error should be retried
	 *
	 * @param \WP_Error $error Error object
	 * @return bool True if error should be retried
	 */
	private function is_retryable_error( $error ) {
		$retryable_error_codes = [
			'api_request_failed',
			'api_error',
			'json_decode_error',
		];
		
		return in_array( $error->get_error_code(), $retryable_error_codes );
	}

	/**
	 * Check if we can make an API request (rate limiting)
	 *
	 * @param string $tracking_number Tracking number for logging
	 * @return bool True if request is allowed
	 */
	private function can_make_api_request( $tracking_number = '' ) {
		$rate_limit_window = intval( get_option( 'ongoing_shipment_tracking_rate_limit_window', '20' ) ); // seconds
		$rate_limit_max_requests = intval( get_option( 'ongoing_shipment_tracking_rate_limit_max_requests', $rate_limit_window * 10 ) ); // requests per window
		
		$current_time = time();
		$window_start = $current_time - $rate_limit_window;
		
		// Get recent API calls from transient
		$recent_calls = get_transient( 'ongoing_shipment_tracking_api_calls' );
		if ( ! is_array( $recent_calls ) ) {
			$recent_calls = [];
		}
		
		// Remove old calls outside the window
		$recent_calls = array_filter( $recent_calls, function( $timestamp ) use ( $window_start ) {
			return $timestamp >= $window_start;
		} );
		
		// Check if we're under the limit
		$current_requests = count( $recent_calls );
		
		if ( $current_requests >= $rate_limit_max_requests ) {
			// Log rate limit hit
			error_log( sprintf( 
				'Ongoing Shipment Tracking - Rate limit hit: %d requests in %d seconds (limit: %d)', 
				$current_requests, 
				$rate_limit_window, 
				$rate_limit_max_requests 
			) );
			return false;
		}
		
		// Add current request to the list
		$recent_calls[] = $current_time;
		
		// Store updated list
		set_transient( 'ongoing_shipment_tracking_api_calls', $recent_calls, $rate_limit_window + 60 );
		
		return true;
	}

	/**
	 * Wait for rate limit to reset
	 *
	 * @param bool $quiet Whether to suppress output
	 * @return void
	 */
	private function wait_for_rate_limit_reset( $quiet = false ) {
		$rate_limit_window = intval( get_option( 'ongoing_shipment_tracking_rate_limit_window', '60' ) );
		
		if ( ! $quiet ) {
			\WP_CLI::log( sprintf( 'Rate limit reached. Waiting %d seconds for reset...', $rate_limit_window ) );
		}
		
		sleep( $rate_limit_window );
		
		// Clear the transient to reset the counter
		delete_transient( 'ongoing_shipment_tracking_api_calls' );
		
		if ( ! $quiet ) {
			\WP_CLI::log( 'Rate limit reset. Continuing...' );
		}
	}

	/**
	 * Get enabled order statuses from settings
	 *
	 * @param array $cli_overrides Optional CLI overrides
	 * @param bool $quiet Whether to suppress output
	 * @return array Array with 'statuses' and 'settings' keys
	 */
	private function get_enabled_order_statuses( $cli_overrides = [], $quiet = false ) {
		$order_statuses = wc_get_order_statuses();
		$enabled_statuses = [];
		$status_settings = [];
		
		if ( ! $quiet ) {
			\WP_CLI::log( 'Checking plugin settings...' );
		}
		
		foreach ( $order_statuses as $status_key => $status_label ) {
			$enabled = get_option( 'ongoing_shipment_tracking_status_' . $status_key, 'no' );
			if ( $enabled === 'yes' ) {
				$status_clean = str_replace( 'wc-', '', $status_key );
				$enabled_statuses[] = $status_clean;
				
				// Get age limit for this status
				$age_limit = intval( get_option( 'ongoing_shipment_tracking_age_' . $status_key, '30' ) );
				$status_settings[ $status_clean ] = [
					'age_limit' => $age_limit,
				];
				
				if ( ! $quiet ) {
					\WP_CLI::log( sprintf( '  - Status "%s": enabled (age limit: %d days)', $status_label, $age_limit ) );
				}
			}
		}

		// Override with CLI options if provided
		if ( isset( $cli_overrides['statuses'] ) ) {
			$enabled_statuses = explode( ',', $cli_overrides['statuses'] );
			if ( ! $quiet ) {
				\WP_CLI::log( sprintf( 'CLI override: Using statuses: %s', implode( ', ', $enabled_statuses ) ) );
			}
		}

		if ( empty( $enabled_statuses ) ) {
			$enabled_statuses = [ 'processing', 'completed' ];
			if ( ! $quiet ) {
				\WP_CLI::log( 'No enabled statuses found, using defaults: processing, completed' );
			}
		}

		return [
			'statuses' => $enabled_statuses,
			'settings' => $status_settings,
		];
	}

	/**
	 * Get orders to update based on settings and filters
	 *
	 * @param array $enabled_statuses Array of enabled statuses
	 * @param array $status_settings Status settings with age limits
	 * @param array $cli_overrides Optional CLI overrides
	 * @param bool $quiet Whether to suppress output
	 * @return array Array of order IDs to process
	 */
	private function get_orders_to_update( $enabled_statuses, $status_settings, $cli_overrides = [], $quiet = false ) {
		// Get max updates limit
		$max_updates = isset( $cli_overrides['limit'] ) ? intval( $cli_overrides['limit'] ) : intval( get_option( 'ongoing_shipment_tracking_max_updates_per_run', '50' ) );
		if ( ! $quiet ) {
			\WP_CLI::log( sprintf( 'Max updates per run: %d', $max_updates ) );
		}

		// Check exclude delivered setting
		$exclude_delivered = get_option( 'ongoing_shipment_tracking_exclude_delivered', 'yes' ) === 'yes';
		
		// Override with CLI options if provided
		if ( isset( $cli_overrides['include-delivered'] ) ) {
			$exclude_delivered = false;
			if ( ! $quiet ) {
				\WP_CLI::log( 'CLI override: Including delivered orders' );
			}
		}
		
		if ( ! $quiet ) {
			\WP_CLI::log( sprintf( 'Exclude delivered orders: %s', $exclude_delivered ? 'yes' : 'no' ) );
		}

		if ( ! $quiet ) {
			\WP_CLI::log( 'Querying orders with optimized query...' );
		}
		
		// Build optimized meta query for tracking numbers
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
		];

		// Add exclude delivered filter if enabled
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

		// Build date query for age limits
		$date_query = [];
		$has_age_limits = false;
		foreach ( $status_settings as $status => $settings ) {
			if ( $settings['age_limit'] > 0 ) {
				$has_age_limits = true;
				$cutoff_date = date( 'Y-m-d H:i:s', time() - ( $settings['age_limit'] * DAY_IN_SECONDS ) );
				$date_query[] = [
					'after' => $cutoff_date,
					'inclusive' => true,
				];
			}
		}

		// Use optimized query with proper limits and performance settings
		$query_args = [
			'status' => $enabled_statuses,
			'limit'  => $max_updates, // Limit at query level for performance
			'type' => 'shop_order',
			'return' => 'ids',
			'orderby' => 'ID',
			'order' => 'ASC',
			'meta_query' => $meta_query,
			'no_found_rows' => true, // Don't count total rows for performance
			'update_post_meta_cache' => false, // Don't cache meta for performance
			'update_post_term_cache' => false, // Don't cache terms for performance
		];

		// Add date query if we have age limits
		if ( $has_age_limits && ! empty( $date_query ) ) {
			$query_args['date_query'] = $date_query;
		}

		$orders = wc_get_orders( $query_args );

		if ( ! $quiet ) {
			\WP_CLI::log( sprintf( 'Found %d orders to update. Starting update process...', count( $orders ) ) );
		}

		return $orders;
	}
	


	/**
	 * WP CLI command handler
	 *
	 * @param array $args Command arguments
	 * @param array $assoc_args Command options
	 */
	public function wp_cli_command( $args, $assoc_args ) {
		// Show help if no command provided or help requested
		if ( empty( $args ) || isset( $assoc_args['help'] ) ) {
			$this->wp_cli_help();
			return;
		}
		
		$command = $args[0];
		
		// Show help if help command is requested
		if ( $command === 'help' ) {
			$this->wp_cli_help();
			return;
		}
		
		// Check if assign-test-numbers is allowed in current environment
		$dev_environments = [ 'local', 'dev', 'development', 'test', 'staging', 'debug' ];
		$current_env = defined( 'WP_ENV' ) ? WP_ENV : '';
		$is_dev_environment = in_array( strtolower( $current_env ), $dev_environments );
		
		switch ( $command ) {
			case 'update':
				\WP_CLI::log( 'Updating tracking for orders...' );
				$this->wp_cli_update_tracking( $assoc_args );
				break;
			case 'update-unfetched':
				\WP_CLI::log( 'Updating unfetched tracking for orders...' );
				$this->wp_cli_update_unfetched_tracking( $assoc_args );
				break;
			case 'schedule-unfetched':
				\WP_CLI::log( 'Scheduling unfetched tracking cron job...' );
				$this->wp_cli_schedule_unfetched_cron( $assoc_args );
				break;
			case 'cleanup':
				\WP_CLI::log( 'Cleaning up tracking data...' );
				$this->wp_cli_cleanup_tracking_data( $assoc_args );
				break;
			case 'backfill':
				\WP_CLI::log( 'Backfilling tracking data to repository table...' );
				$this->wp_cli_backfill_tracking_data( $assoc_args );
				break;
			case 'status':
				\WP_CLI::log( 'Checking plugin status...' );
				$this->wp_cli_status( $assoc_args );
				break;
			case 'assign-test-numbers':
				if ( ! $is_dev_environment ) {
					\WP_CLI::error( sprintf( 'Command "assign-test-numbers" is only available in development environments. Current environment: %s', $current_env ?: 'production' ) );
					return;
				}
				\WP_CLI::log( 'Assigning test tracking numbers...' );
				$this->wp_cli_assign_test_numbers( $assoc_args );
				break;
			default:
				\WP_CLI::error( sprintf( 'Unknown command: %s. Use "update", "update-unfetched", "schedule-unfetched", "cleanup", "status", "backfill", or "assign-test-numbers" (in dev environments).', $command ) );
		}
	}

	/**
	 * Display help information for WP CLI command
	 */
	private function wp_cli_help() {
		// Check if assign-test-numbers is available in current environment
		$dev_environments = [ 'local', 'dev', 'development', 'test', 'staging', 'debug' ];
		$current_env = defined( 'WP_ENV' ) ? WP_ENV : '';
		$is_dev_environment = in_array( strtolower( $current_env ), $dev_environments );
		
		\WP_CLI::log( 'DirectHouse Ongoing Parcel Tracking WP CLI Commands' );
		\WP_CLI::log( '' );
		\WP_CLI::log( 'USAGE:' );
		\WP_CLI::log( '  wp directhouse-tracking <command> [--<option>=<value>]' );
		\WP_CLI::log( '' );
		\WP_CLI::log( 'COMMANDS:' );
		\WP_CLI::log( '  update              Update tracking for orders' );
		\WP_CLI::log( '  update-unfetched    Update only orders that haven\'t been fetched yet' );
		\WP_CLI::log( '  schedule-unfetched  Schedule the unfetched tracking cron job' );
		\WP_CLI::log( '  cleanup             Clean up tracking data from orders' );
		\WP_CLI::log( '  status              Show plugin status and configuration' );
		\WP_CLI::log( '  backfill            Backfill existing tracking data to repository table' );
		
		if ( $is_dev_environment ) {
			\WP_CLI::log( '  assign-test-numbers Assign test tracking numbers to orders' );
		}
		
		\WP_CLI::log( '' );
		\WP_CLI::log( 'OPTIONS:' );
		\WP_CLI::log( '  --statuses=<list>   Comma-separated list of order statuses to process' );
		\WP_CLI::log( '  --limit=<number>    Maximum number of orders to process' );
		\WP_CLI::log( '  --include-delivered Include orders that are already delivered' );
		\WP_CLI::log( '  --force             Force execution even if cron is disabled' );
		\WP_CLI::log( '  --quiet             Suppress verbose output' );
        \WP_CLI::log( '  --parallel          Use parallel processing for faster updates' );
        \WP_CLI::log( '  --dry-run           Do not write changes (for backfill/cleanup); just show what would happen' );
        \WP_CLI::log( '  --fetch-missing     When backfilling, fetch from API if no tracking payload is stored in meta' );
        \WP_CLI::log( '  --force             Overwrite existing tracking rows in table during backfill' );
        \WP_CLI::log( '  --fast-query        Use optimized SQL queries for large datasets' );
		
		if ( $is_dev_environment ) {
			\WP_CLI::log( '  --file=<path>       File path containing tracking numbers (for assign-test-numbers)' );
		}
		
		\WP_CLI::log( '' );
		\WP_CLI::log( 'EXAMPLES:' );
		\WP_CLI::log( '  wp directhouse-tracking update' );
		\WP_CLI::log( '  wp directhouse-tracking update --statuses=processing,completed --limit=10' );
		\WP_CLI::log( '  wp directhouse-tracking update-unfetched --parallel --limit=100' );
		\WP_CLI::log( '  wp directhouse-tracking status' );
		\WP_CLI::log( '  wp directhouse-tracking backfill --limit=50 --dry-run' );
		
		if ( $is_dev_environment ) {
			\WP_CLI::log( '  wp directhouse-tracking assign-test-numbers --file=/path/to/numbers.txt --limit=20' );
		}
		
		\WP_CLI::log( '' );
		
		if ( ! $is_dev_environment ) {
			\WP_CLI::log( 'NOTE: Development commands (assign-test-numbers) are only available in development environments.' );
			\WP_CLI::log( 'Current environment: ' . ( $current_env ?: 'production' ) );
			\WP_CLI::log( '' );
		}
		
		\WP_CLI::log( 'For more information, visit: https://www.comfyballs.no' );
	}

	/**
	 * WP CLI update unfetched tracking command
	 *
	 * @param array $assoc_args Command options
	 */
	private function wp_cli_update_unfetched_tracking( $assoc_args ) {
		// Check if quiet mode is enabled
		$quiet = isset( $assoc_args['quiet'] );
		
		// Get enabled statuses and settings using shared method
		$status_data = $this->get_enabled_order_statuses( $assoc_args, $quiet );
		$enabled_statuses = $status_data['statuses'];
		$status_settings = $status_data['settings'];
		
		// Check if fast query is requested
		$use_fast_query = isset( $assoc_args['fast-query'] );
		
		// Get unfetched orders to update using appropriate method
		if ( $use_fast_query ) {
			$orders = $this->get_unfetched_orders_to_update( $enabled_statuses, $status_settings, $assoc_args, $quiet );
		} else {
			$orders = $this->get_unfetched_orders_to_update( $enabled_statuses, $status_settings, $assoc_args, $quiet );
		}
		
		if ( empty( $orders ) ) {
			if ( ! $quiet ) {
				\WP_CLI::success( 'No unfetched orders found matching the criteria.' );
			}
			return;
		}

		// Get max updates limit for parallel processing
		$max_updates = isset( $assoc_args['limit'] ) ? intval( $assoc_args['limit'] ) : intval( get_option( 'ongoing_shipment_tracking_max_updates_per_run', '50' ) );

		$updated = 0;
		$errors = [];
		
		// Check if parallel processing is requested
		$use_parallel = isset( $assoc_args['parallel'] );
		
		if ( $use_parallel ) {
			\WP_CLI::log( 'Using parallel processing for faster updates...' );
		}
		
		if ( $quiet ) {
			$progress = \WP_CLI\Utils\make_progress_bar( 'Updating unfetched tracking', count( $orders ) );
		}

		if ( $use_parallel ) {
			// Calculate initial batch size based on max updates setting
			$batch_size = max( 5, min( 20, intval( $max_updates / 3 ) ) );
			$min_batch_size = 3;
			$max_batch_size = 25;
			$reverted_to_sequential = false;
			$start_time = time();
			
			if ( ! $quiet ) {
				\WP_CLI::log( sprintf( 'Using parallel processing with initial batch size of %d orders...', $batch_size ) );
			}
			
			// Process orders in batches with dynamic sizing
			$all_results = [];
			$remaining_orders = $orders;
			$batch_index = 0;
			
			while ( ! empty( $remaining_orders ) ) {
				// Check memory and time limits before processing batch
				if ( $this->memory_exceeded() ) {
					if ( ! $quiet ) {
						\WP_CLI::warning( 'Memory limit exceeded, stopping parallel processing' );
					}
					break;
				}
				
				if ( $this->time_exceeded( $start_time ) ) {
					if ( ! $quiet ) {
						\WP_CLI::warning( 'Time limit exceeded, stopping parallel processing' );
					}
					break;
				}
				
				$batch_index++;
				
				// Take the current batch
				$batch_orders = array_slice( $remaining_orders, 0, $batch_size );
				$remaining_orders = array_slice( $remaining_orders, $batch_size );
				
				if ( ! $quiet ) {
					\WP_CLI::log( sprintf( 'Processing batch %d (%d orders, batch size: %d)...', $batch_index, count( $batch_orders ), $batch_size ) );
				}
				
                // Process this batch in parallel
                $batch_result = $this->process_orders_parallel( $batch_orders, $quiet );
                // If there are retryables, move them to the end of the remaining queue
                if ( ! empty( $batch_result['retryable'] ) ) {
                    if ( ! $quiet ) {
                        \WP_CLI::log( sprintf( '  Appending %d retryable requests to the end of the queue', count( $batch_result['retryable'] ) ) );
                    }
                    $remaining_orders = array_merge( $remaining_orders, $batch_result['retryable'] );
                }
                // If there are retryables, move them to the end of the remaining queue
                if ( ! empty( $batch_result['retryable'] ) ) {
                    if ( ! $quiet ) {
                        \WP_CLI::log( sprintf( '  Appending %d retryable requests to the end of the queue', count( $batch_result['retryable'] ) ) );
                    }
                    // Preserve order: add to tail of remaining orders
                    $remaining_orders = array_merge( $remaining_orders, $batch_result['retryable'] );
                }
				
				// Count failures in this batch (including retries)
				$batch_failures = 0;
                // Retries are deferred, do not count them for batch-size adjustment now
                $batch_retries = 0;
				
				// Count permanent failures (after retries)
				foreach ( $batch_result['results'] as $order_id => $result ) {
					if ( is_wp_error( $result ) ) {
						$batch_failures++;
					}
				}
				
				// Count retries as failures for batch size adjustment
				$total_failures = $batch_failures + $batch_retries;
				
				if ( ! $quiet && $total_failures > 0 ) {
					\WP_CLI::log( sprintf( '  Batch %d: %d permanent failures, %d retries (total: %d failures)', $batch_index, $batch_failures, $batch_retries, $total_failures ) );
				}
				
				// Check if we should revert to sequential processing
				if ( $batch_size === $min_batch_size && $total_failures > 0 && ! $reverted_to_sequential ) {
					$reverted_to_sequential = true;
					if ( ! $quiet ) {
						\WP_CLI::log( '  Minimum batch size reached with failures. Reverting to sequential processing for remaining orders.' );
					}
					
					// Process remaining orders sequentially
					foreach ( $remaining_orders as $order_id ) {
						if ( ! $quiet ) {
							$tracking_number = get_post_meta( $order_id, 'ongoing_tracking_number', true );
							\WP_CLI::log( sprintf( 'Sequential: Updating order %d (tracking: %s)...', $order_id, $tracking_number ) );
						}
						
						$result = $this->update_order_tracking( $order_id );
						$all_results[ $order_id ] = $result;
						
						if ( is_wp_error( $result ) ) {
							$tracking_number = get_post_meta( $order_id, 'ongoing_tracking_number', true );
							$error_msg = sprintf( 'Order %d (tracking: %s): %s', $order_id, $tracking_number, $result->get_error_message() );
							$errors[] = $error_msg;
							if ( ! $quiet ) {
								\WP_CLI::warning( '  - Error: ' . $result->get_error_message() );
							}
						} else {
							$updated++;
							if ( ! $quiet ) {
								\WP_CLI::log( '  - Success' );
							}
						}
					}
					
					$remaining_orders = []; // Clear remaining orders since we processed them
					break;
				}
				
                // Adjust batch size only for permanent failures (not deferred retries)
                if ( $batch_failures > 0 && $batch_size > $min_batch_size ) {
					// Failures detected, reduce batch size
					$old_batch_size = $batch_size;
					$batch_size = max( $min_batch_size, intval( $batch_size * 0.8 ) );
					
					if ( ! $quiet && $batch_size !== $old_batch_size ) {
						\WP_CLI::log( sprintf( '  Failures detected. Reducing batch size from %d to %d for next batch.', $old_batch_size, $batch_size ) );
					}
                } else if ( $total_failures === 0 ) {
                    // No failures: keep batch size unchanged (do not increase)
                    if ( ! $quiet ) {
                        \WP_CLI::log( sprintf( '  No failures detected. Keeping batch size at %d for next batch.', $batch_size ) );
                    }
                }
				
				// Merge results
                $all_results = array_merge( $all_results, $batch_result['results'] );
			}
			
			$results = $all_results;
			
			// Process results
			foreach ( $results as $order_id => $result ) {
				if ( $quiet ) {
					$progress->tick();
				}
				
				if ( is_wp_error( $result ) ) {
					$tracking_number = get_post_meta( $order_id, 'ongoing_tracking_number', true );
					$error_msg = sprintf( 'Order %d (tracking: %s): %s', $order_id, $tracking_number, $result->get_error_message() );
					$errors[] = $error_msg;
					if ( ! $quiet ) {
						\WP_CLI::warning( '  - Error: ' . $result->get_error_message() );
					}
				} else {
					$updated++;
					if ( ! $quiet ) {
						\WP_CLI::log( sprintf( '  - Order %d: Success', $order_id ) );
					}
				}
			}
		} else {
			// Sequential processing
			foreach ( $orders as $order_id ) {
				if ( $quiet ) {
					$progress->tick();
				} else {
					$tracking_number = get_post_meta( $order_id, 'ongoing_tracking_number', true );
					\WP_CLI::log( sprintf( 'Updating order %d (tracking: %s)...', $order_id, $tracking_number ) );
				}
				
				$result = $this->update_order_tracking( $order_id );
				
				if ( is_wp_error( $result ) ) {
					$tracking_number = get_post_meta( $order_id, 'ongoing_tracking_number', true );
					$error_msg = sprintf( 'Order %d (tracking: %s): %s', $order_id, $tracking_number, $result->get_error_message() );
					$errors[] = $error_msg;
					if ( ! $quiet ) {
						\WP_CLI::warning( '  - Error: ' . $result->get_error_message() );
					}
				} else {
					$updated++;
					if ( ! $quiet ) {
						\WP_CLI::log( '  - Success' );
					}
				}
			}
		}

		if ( $quiet ) {
			$progress->finish();
		}

		// Display results
		\WP_CLI::success( sprintf( 'Updated %d unfetched orders successfully.', $updated ) );
		
		if ( ! empty( $errors ) ) {
			\WP_CLI::warning( sprintf( 'Encountered %d errors:', count( $errors ) ) );
			foreach ( $errors as $error ) {
				\WP_CLI::log( '  - ' . $error );
			}
		}
	}

	/**
	 * WP CLI schedule unfetched cron command
	 *
	 * @param array $assoc_args Command options
	 */
	private function wp_cli_schedule_unfetched_cron( $assoc_args ) {
		$scheduled = $this->cron->schedule_unfetched_cron();
		
		if ( $scheduled ) {
			\WP_CLI::success( 'Unfetched tracking cron job scheduled successfully!' );
			
			// Show next scheduled run
			$next_run = wp_next_scheduled( 'ongoing_shipment_tracking_unfetched_cron' );
			if ( $next_run ) {
				\WP_CLI::log( sprintf( 'Next run scheduled for: %s', date( 'Y-m-d H:i:s', $next_run ) ) );
			}
			
			\WP_CLI::log( 'The cron job will run every 15 minutes to process orders with tracking numbers that haven\'t been fetched yet.' );
		} else {
			\WP_CLI::error( 'Failed to schedule unfetched tracking cron job. Make sure cron updates are enabled in plugin settings.' );
		}
	}

	/**
	 * WP CLI cleanup tracking data command
	 *
	 * @param array $assoc_args Command options
	 */
	private function wp_cli_cleanup_tracking_data( $assoc_args ) {
		$limit = isset( $assoc_args['limit'] ) ? intval( $assoc_args['limit'] ) : 50;
		$all = isset( $assoc_args['all'] );
		$quiet = isset( $assoc_args['quiet'] );
		
		if ( $all ) {
			if ( ! $quiet ) {
				\WP_CLI::log( 'Cleaning up ALL tracking data from all orders...' );
			}

			$cleaned = $this->cleanup_all_tracking_data( $quiet );
		} else {
			if ( ! $quiet ) {
				\WP_CLI::log( sprintf( 'Cleaning up tracking data from up to %d orders...', $limit ) );
			}
			$cleaned = $this->cleanup_tracking_data_batch( $limit, $quiet );
		}
		
		if ( $cleaned > 0 ) {
			\WP_CLI::success( sprintf( 'Cleaned up tracking data from %d orders.', $cleaned ) );
		} else {
			\WP_CLI::success( 'No tracking data found to clean up.' );
		}
	}

	/**
	 * WP CLI update tracking command
	 *
	 * @param array $assoc_args Command options
	 */
	private function wp_cli_update_tracking( $assoc_args ) {
		// Check if quiet mode is enabled
		$quiet = isset( $assoc_args['quiet'] );
		
		// Get enabled statuses and settings using shared method
		$status_data = $this->get_enabled_order_statuses( $assoc_args, $quiet );
		$enabled_statuses = $status_data['statuses'];
		$status_settings = $status_data['settings'];
		
		// Check if fast query is requested
		$use_fast_query = isset( $assoc_args['fast-query'] );
		
		// Get orders to update using appropriate method
		if ( $use_fast_query ) {
			$orders = $this->get_orders_to_update_fast( $enabled_statuses, $status_settings, $assoc_args, $quiet );
		} else {
			$orders = $this->get_orders_to_update( $enabled_statuses, $status_settings, $assoc_args, $quiet );
		}
		
		if ( empty( $orders ) ) {
			return;
		}

		// Get max updates limit for parallel processing
		$max_updates = isset( $assoc_args['limit'] ) ? intval( $assoc_args['limit'] ) : intval( get_option( 'ongoing_shipment_tracking_max_updates_per_run', '50' ) );

		$updated = 0;
		$errors = [];
		
		// Check if parallel processing is requested
		$use_parallel = isset( $assoc_args['parallel'] );
		
		if ( $use_parallel ) {
			\WP_CLI::log( 'Using parallel processing for faster updates...' );
		}
		
		if ( $quiet ) {
			$progress = \WP_CLI\Utils\make_progress_bar( 'Updating tracking', count( $orders ) );
		}

		if ( $use_parallel ) {
			// Calculate initial batch size based on max updates setting
			$batch_size = max( 5, min( 20, intval( $max_updates / 3 ) ) );
			$min_batch_size = 3;
			$max_batch_size = 25;
			$reverted_to_sequential = false;
			$start_time = time();
			
			if ( ! $quiet ) {
				\WP_CLI::log( sprintf( 'Using parallel processing with initial batch size of %d orders...', $batch_size ) );
			}
			
			// Process orders in batches with dynamic sizing
			$all_results = [];
			$remaining_orders = $orders;
			$batch_index = 0;
			
			while ( ! empty( $remaining_orders ) ) {
				// Check memory and time limits before processing batch
				if ( $this->memory_exceeded() ) {
					if ( ! $quiet ) {
						\WP_CLI::warning( 'Memory limit exceeded, stopping parallel processing' );
					}
					break;
				}
				
				if ( $this->time_exceeded( $start_time ) ) {
					if ( ! $quiet ) {
						\WP_CLI::warning( 'Time limit exceeded, stopping parallel processing' );
					}
					break;
				}
				$batch_index++;
				
				// Take the current batch
				$batch_orders = array_slice( $remaining_orders, 0, $batch_size );
				$remaining_orders = array_slice( $remaining_orders, $batch_size );
				
				if ( ! $quiet ) {
					\WP_CLI::log( sprintf( 'Processing batch %d (%d orders, batch size: %d)...', $batch_index, count( $batch_orders ), $batch_size ) );
				}
				
				// Process this batch in parallel
				$batch_result = $this->process_orders_parallel( $batch_orders, $quiet );
				
				// Count failures in this batch (including retries)
				$batch_failures = 0;
                // Retries are deferred, do not count them now
                $batch_retries = 0;
				
				// Count permanent failures (after retries)
				foreach ( $batch_result['results'] as $order_id => $result ) {
					if ( is_wp_error( $result ) ) {
						$batch_failures++;
					}
				}
				
				// Count retries as failures for batch size adjustment
				$total_failures = $batch_failures + $batch_retries;
				
				if ( ! $quiet && $total_failures > 0 ) {
					\WP_CLI::log( sprintf( '  Batch %d: %d permanent failures, %d retries (total: %d failures)', $batch_index, $batch_failures, $batch_retries, $total_failures ) );
				}
				
				// Check if we should revert to sequential processing
				if ( $batch_size === $min_batch_size && $total_failures > 0 && ! $reverted_to_sequential ) {
					$reverted_to_sequential = true;
					if ( ! $quiet ) {
						\WP_CLI::log( '  Minimum batch size reached with failures. Reverting to sequential processing for remaining orders.' );
					}
					
					// Process remaining orders sequentially
					foreach ( $remaining_orders as $order_id ) {
						if ( ! $quiet ) {
							$tracking_number = get_post_meta( $order_id, 'ongoing_tracking_number', true );
							\WP_CLI::log( sprintf( 'Sequential: Updating order %d (tracking: %s)...', $order_id, $tracking_number ) );
						}
						
						$result = $this->update_order_tracking( $order_id );
						$all_results[ $order_id ] = $result;
						
						if ( is_wp_error( $result ) ) {
							$tracking_number = get_post_meta( $order_id, 'ongoing_tracking_number', true );
							$error_msg = sprintf( 'Order %d (tracking: %s): %s', $order_id, $tracking_number, $result->get_error_message() );
							$errors[] = $error_msg;
							if ( ! $quiet ) {
								\WP_CLI::warning( '  - Error: ' . $result->get_error_message() );
							}
						} else {
							$updated++;
							if ( ! $quiet ) {
								\WP_CLI::log( '  - Success' );
							}
						}
					}
					
					$remaining_orders = []; // Clear remaining orders since we processed them
					break;
				}
				
                // Adjust batch size only for permanent failures
                if ( $batch_failures > 0 && $batch_size > $min_batch_size ) {
					// Failures detected, reduce batch size
					$old_batch_size = $batch_size;
					$batch_size = max( $min_batch_size, intval( $batch_size * 0.8 ) );
					
					if ( ! $quiet && $batch_size !== $old_batch_size ) {
						\WP_CLI::log( sprintf( '  Failures detected. Reducing batch size from %d to %d for next batch.', $old_batch_size, $batch_size ) );
					}
                } elseif ( $total_failures === 0 && ! empty( $remaining_orders ) ) {
                    // No failures: keep batch size unchanged (do not increase)
                    if ( ! $quiet ) {
                        \WP_CLI::log( sprintf( '  No failures detected. Keeping batch size at %d for next batch.', $batch_size ) );
                    }
                }
				
				// Merge results
				$all_results = array_merge( $all_results, $batch_result['results'] );
			}
			
			$results = $all_results;
			
			// Process results
			foreach ( $results as $order_id => $result ) {
				if ( $quiet ) {
					$progress->tick();
				}
				
				if ( is_wp_error( $result ) ) {
					$tracking_number = get_post_meta( $order_id, 'ongoing_tracking_number', true );
					$error_msg = sprintf( 'Order %d (tracking: %s): %s', $order_id, $tracking_number, $result->get_error_message() );
					$errors[] = $error_msg;
					if ( ! $quiet ) {
						\WP_CLI::warning( '  - Error: ' . $result->get_error_message() );
					}
				} else {
					$updated++;
					if ( ! $quiet ) {
						\WP_CLI::log( sprintf( '  - Order %d: Success', $order_id ) );
					}
				}
			}
		} else {
			// Process orders sequentially (original method)
			foreach ( $orders as $order_id ) {
				if ( $quiet ) {
					$progress->tick();
				} else {
					$tracking_number = get_post_meta( $order_id, 'ongoing_tracking_number', true );
					\WP_CLI::log( sprintf( 'Updating order %d (tracking: %s)...', $order_id, $tracking_number ) );
				}
				
				$result = $this->update_order_tracking( $order_id );
				
				if ( is_wp_error( $result ) ) {
					$tracking_number = get_post_meta( $order_id, 'ongoing_tracking_number', true );
					$error_msg = sprintf( 'Order %d (tracking: %s): %s', $order_id, $tracking_number, $result->get_error_message() );
					$errors[] = $error_msg;
					if ( ! $quiet ) {
						\WP_CLI::warning( '  - Error: ' . $result->get_error_message() );
					}
				} else {
					$updated++;
					if ( ! $quiet ) {
						\WP_CLI::log( '  - Success' );
					}
				}
			}
		}
		
		if ( $quiet ) {
			$progress->finish();
		}

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
		$enable_cron = get_option( 'ongoing_shipment_tracking_enable_cron', 'no' );
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

	/**
	 * WP CLI assign test tracking numbers command
	 *
	 * @param array $assoc_args Command options
	 */
	private function wp_cli_assign_test_numbers( $assoc_args ) {
		// Check if file path is provided
		$file_path = $assoc_args['file'] ?? null;
		if ( ! $file_path ) {
			\WP_CLI::error( 'Please provide a file path with --file=path/to/file.txt' );
			return;
		}

		// Check if file exists
		if ( ! file_exists( $file_path ) ) {
			\WP_CLI::error( sprintf( 'File not found: %s', $file_path ) );
			return;
		}

		// Read tracking numbers from file
		$tracking_numbers = file( $file_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		if ( empty( $tracking_numbers ) ) {
			\WP_CLI::error( 'No tracking numbers found in file.' );
			return;
		}

		// Get order status filter
		$status_filter = $assoc_args['status'] ?? 'processing,completed';
		$statuses = explode( ',', $status_filter );

		// Get limit
		$limit = isset( $assoc_args['limit'] ) ? intval( $assoc_args['limit'] ) : count( $tracking_numbers );
		$limit = min( $limit, count( $tracking_numbers ) );

		// Get orders without tracking numbers
		$orders = wc_get_orders( [
			'status' => $statuses,
			'limit'  => $limit,
			'type' => 'shop_order',
			'return' => 'ids',
			'meta_query' => [
				'relation' => 'OR',
				[
					'key' => 'ongoing_tracking_number',
					'compare' => 'NOT EXISTS',
				],
				[
					'key' => 'ongoing_tracking_number',
					'value' => '',
					'compare' => '=',
				],
			],
		] );

		if ( empty( $orders ) ) {
			\WP_CLI::error( 'No orders found without tracking numbers.' );
			return;
		}

		\WP_CLI::log( sprintf( 'Found %d orders without tracking numbers.', count( $orders ) ) );
		\WP_CLI::log( sprintf( 'Will assign %d tracking numbers.', $limit ) );

		// Confirm action
		if ( ! isset( $assoc_args['force'] ) ) {
			\WP_CLI::confirm( 'Are you sure you want to assign these tracking numbers to orders?' );
		}

		$assigned = 0;
		$errors = [];
		$progress = \WP_CLI\Utils\make_progress_bar( 'Assigning tracking numbers', $limit );

		for ( $i = 0; $i < $limit; $i++ ) {
			$progress->tick();
			
			$order_id = $orders[ $i ];
			$tracking_number = trim( $tracking_numbers[ $i ] );
			
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				$errors[] = sprintf( 'Order %d not found', $order_id );
				continue;
			}

			// Assign tracking number
			$order->update_meta_data( 'ongoing_tracking_number', $tracking_number );
			$order->save();
			
			$assigned++;
		}

		$progress->finish();

		// Display results
		\WP_CLI::success( sprintf( 'Assigned %d tracking numbers to orders.', $assigned ) );
		
		if ( ! empty( $errors ) ) {
			\WP_CLI::warning( sprintf( 'Encountered %d errors:', count( $errors ) ) );
			foreach ( $errors as $error ) {
				\WP_CLI::log( '  - ' . $error );
			}
		}

		\WP_CLI::log( 'You can now run "wp directhouse-tracking update" to fetch tracking data for these orders.' );
	}

	/**
	 * Check if memory usage has exceeded limits
	 *
	 * @return bool True if memory limit exceeded
	 */
	private function memory_exceeded() {
		$memory_limit = $this->get_memory_limit() * 0.9; // 90% of max memory
		$current_memory = memory_get_usage( true );
		
		return $current_memory >= $memory_limit;
	}

	/**
	 * Check if time limit has been exceeded
	 *
	 * @param int $start_time Start time timestamp
	 * @return bool True if time limit exceeded
	 */
	private function time_exceeded( $start_time ) {
		$time_limit = apply_filters( 'ongoing_shipment_tracking_time_limit', 600 ); // 25 seconds default
		$finish = $start_time + $time_limit;
		
		return time() >= $finish;
	}

	/**
	 * Get memory limit in bytes
	 *
	 * @return int Memory limit in bytes
	 */
	private function get_memory_limit() {
		if ( function_exists( 'ini_get' ) ) {
			$memory_limit = ini_get( 'memory_limit' );
		} else {
			$memory_limit = '128M'; // Sensible default
		}

		if ( ! $memory_limit || -1 === intval( $memory_limit ) ) {
			$memory_limit = '256M'; // Reasonable default for unlimited
		}

		return wp_convert_hr_to_bytes( $memory_limit );
	}

	/**
	 * Format memory usage for logging
	 *
	 * @return string Formatted memory usage
	 */
	private function format_memory_usage() {
		$current = memory_get_usage( true );
		$peak = memory_get_peak_usage( true );
		$limit = $this->get_memory_limit();
		
		return sprintf( 
			'Current: %s, Peak: %s, Limit: %s', 
			size_format( $current ), 
			size_format( $peak ), 
			size_format( $limit ) 
		);
	}

	/**
	 * Get orders using ultra-fast direct SQL query for large datasets
	 *
	 * @param array $enabled_statuses Array of enabled statuses
	 * @param array $status_settings Status settings with age limits
	 * @param array $cli_overrides Optional CLI overrides
	 * @param bool $quiet Whether to suppress output
	 * @return array Array of order IDs to process
	 */
	private function get_orders_to_update_fast( $enabled_statuses, $status_settings, $cli_overrides = [], $quiet = false ) {
		global $wpdb;
		
		// Get max updates limit
		$max_updates = isset( $cli_overrides['limit'] ) ? intval( $cli_overrides['limit'] ) : intval( get_option( 'ongoing_shipment_tracking_max_updates_per_run', '50' ) );
		
		// Check exclude delivered setting
		$exclude_delivered = get_option( 'ongoing_shipment_tracking_exclude_delivered', 'yes' ) === 'yes';
		if ( isset( $cli_overrides['include-delivered'] ) ) {
			$exclude_delivered = false;
		}

		if ( ! $quiet ) {
			\WP_CLI::log( 'Using ultra-fast SQL query for large dataset...' );
		}

		// Build status conditions
		$status_conditions = [];
		foreach ( $enabled_statuses as $status ) {
			$status_conditions[] = $wpdb->prepare( 'p.post_status = %s', 'wc-' . $status );
		}
		$status_where = implode( ' OR ', $status_conditions );

		// Build age limit conditions
		$age_conditions = [];
		foreach ( $status_settings as $status => $settings ) {
			if ( $settings['age_limit'] > 0 ) {
				$cutoff_date = date( 'Y-m-d H:i:s', time() - ( $settings['age_limit'] * DAY_IN_SECONDS ) );
				$age_conditions[] = $wpdb->prepare( 
					'(p.post_status = %s AND p.post_date >= %s)', 
					'wc-' . $status, 
					$cutoff_date 
				);
			}
		}

		// Build the main query
		$sql = "
			SELECT DISTINCT p.ID 
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} pm_tracking ON p.ID = pm_tracking.post_id 
				AND pm_tracking.meta_key = 'ongoing_tracking_number' 
				AND pm_tracking.meta_value != ''
		";

		// Add exclude delivered condition
		if ( $exclude_delivered ) {
			$sql .= "
				LEFT JOIN {$wpdb->postmeta} pm_status ON p.ID = pm_status.post_id 
					AND pm_status.meta_key = '_ongoing_tracking_status'
			";
		}

		$sql .= " WHERE p.post_type = 'shop_order' AND ({$status_where})";

		// Add age limit conditions if any
		if ( ! empty( $age_conditions ) ) {
			$sql .= " AND (" . implode( ' OR ', $age_conditions ) . ")";
		}

		// Add exclude delivered condition
		if ( $exclude_delivered ) {
			$sql .= " AND (pm_status.meta_value IS NULL OR pm_status.meta_value != 'DELIVERED')";
		}

		$sql .= " ORDER BY p.ID ASC LIMIT %d";

		$sql = $wpdb->prepare( $sql, $max_updates );

		// Execute query
		$orders = $wpdb->get_col( $sql );

		if ( ! $quiet ) {
			\WP_CLI::log( sprintf( 'Found %d orders to update using fast SQL query.', count( $orders ) ) );
		}

		return $orders;
	}

	/**
	 * Get orders that have tracking numbers but haven't been fetched yet
	 *
	 * @param array $enabled_statuses Array of enabled statuses
	 * @param array $status_settings Status settings with age limits
	 * @param array $cli_overrides Optional CLI overrides
	 * @param bool $quiet Whether to suppress output
	 * @return array Array of order IDs to process
	 */
	private function get_unfetched_orders_to_update( $enabled_statuses, $status_settings, $cli_overrides = [], $quiet = false ) {
		global $wpdb;
		
		// Get max updates limit
		$max_updates = isset( $cli_overrides['limit'] ) ? intval( $cli_overrides['limit'] ) : intval( get_option( 'ongoing_shipment_tracking_max_updates_per_run', '50' ) );
		
		// Check exclude delivered setting
		$exclude_delivered = get_option( 'ongoing_shipment_tracking_exclude_delivered', 'yes' ) === 'yes';
		if ( isset( $cli_overrides['include-delivered'] ) ) {
			$exclude_delivered = false;
		}

		if ( ! $quiet ) {
			\WP_CLI::log( 'Finding orders with tracking numbers that haven\'t been fetched yet...' );
		}

		// Build status conditions
		$status_conditions = [];
		foreach ( $enabled_statuses as $status ) {
			$status_conditions[] = $wpdb->prepare( 'p.post_status = %s', 'wc-' . $status );
		}
		$status_where = implode( ' OR ', $status_conditions );

		// Build age limit conditions
		$age_conditions = [];
		foreach ( $status_settings as $status => $settings ) {
			if ( $settings['age_limit'] > 0 ) {
				$cutoff_date = date( 'Y-m-d H:i:s', time() - ( $settings['age_limit'] * DAY_IN_SECONDS ) );
				$age_conditions[] = $wpdb->prepare( 
					'(p.post_status = %s AND p.post_date >= %s)', 
					'wc-' . $status, 
					$cutoff_date 
				);
			}
		}

		// Build the main query - only orders with tracking numbers but no tracking data
		$sql = "
			SELECT DISTINCT p.ID 
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} pm_tracking ON p.ID = pm_tracking.post_id 
				AND pm_tracking.meta_key = 'ongoing_tracking_number' 
				AND pm_tracking.meta_value != ''
			LEFT JOIN {$wpdb->postmeta} pm_data ON p.ID = pm_data.post_id 
				AND pm_data.meta_key = '_ongoing_tracking_data'
		";

		// Add exclude delivered condition
		if ( $exclude_delivered ) {
			$sql .= "
				LEFT JOIN {$wpdb->postmeta} pm_status ON p.ID = pm_status.post_id 
					AND pm_status.meta_key = '_ongoing_tracking_status'
			";
		}

		$sql .= " WHERE p.post_type = 'shop_order' AND ({$status_where})";

		// Add age limit conditions if any
		if ( ! empty( $age_conditions ) ) {
			$sql .= " AND (" . implode( ' OR ', $age_conditions ) . ")";
		}

        // Add condition to only get orders that haven't been fetched yet
        // Treat empty string as unfetched; '{}' (in-progress marker) should be considered fetched-in-progress and excluded
        $sql .= " AND (pm_data.meta_value IS NULL OR pm_data.meta_value = '')";

		// Add exclude delivered condition
		if ( $exclude_delivered ) {
			$sql .= " AND (pm_status.meta_value IS NULL OR pm_status.meta_value != 'DELIVERED')";
		}

		$sql .= " ORDER BY p.ID ASC LIMIT %d";

		$sql = $wpdb->prepare( $sql, $max_updates );

		// Execute query
		$orders = $wpdb->get_col( $sql );

		if ( ! $quiet ) {
			\WP_CLI::log( sprintf( 'Found %d unfetched orders to update.', count( $orders ) ) );
		}

		return $orders;
	}

	/**
	 * Clean up tracking data from a batch of orders
	 *
	 * @param int $limit Maximum number of orders to process
	 * @param bool $quiet Whether to suppress output
	 * @return int Number of orders cleaned
	 */
	private function cleanup_tracking_data_batch( $limit = 50, $quiet = false ) {
		global $wpdb;
		
		// Get orders that have tracking data
		$sql = $wpdb->prepare( "
			SELECT DISTINCT p.ID 
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} pm_data ON p.ID = pm_data.post_id 
				AND pm_data.meta_key = '_ongoing_tracking_data'
			WHERE p.post_type = 'shop_order'
			ORDER BY p.ID ASC 
			LIMIT %d
		", $limit );
		
		$order_ids = $wpdb->get_col( $sql );
		
		if ( empty( $order_ids ) ) {
			return 0;
		}
		
		$cleaned = 0;
		
		foreach ( $order_ids as $order_id ) {
			$result = $this->cleanup_order_tracking_data( $order_id );
			if ( $result ) {
				$cleaned++;
				if ( ! $quiet ) {
					\WP_CLI::log( sprintf( 'Cleaned tracking data from order %d', $order_id ) );
				}
			}
		}
		
		return $cleaned;
	}

	/**
	 * Clean up ALL tracking data from all orders (for uninstall)
	 *
	 * @param bool $quiet Whether to suppress output
	 * @return int Number of orders cleaned
	 */
	private function cleanup_all_tracking_data( $quiet = false ) {
		global $wpdb;
		
		if ( ! $quiet ) {
			\WP_CLI::log( 'Removing all tracking data from database...' );
		}
		
		// Delete all tracking-related meta data in batches
		$meta_keys = [
			'_ongoing_tracking_data',
			'_ongoing_tracking_status', 
			'_ongoing_tracking_updated',
			'ongoing_tracking_number'
		];
		
		$total_deleted = 0;
		
		foreach ( $meta_keys as $meta_key ) {
			$deleted = $wpdb->delete(
				$wpdb->postmeta,
				[ 'meta_key' => $meta_key ],
				[ '%s' ]
			);
			
			if ( $deleted !== false ) {
				$total_deleted += $deleted;
				if ( ! $quiet ) {
					\WP_CLI::log( sprintf( 'Deleted %d records with meta key: %s', $deleted, $meta_key ) );
				}
			}
		}
		
		// Clean up plugin options
		$options_to_delete = [
			'ongoing_shipment_tracking_enable_cron',
			'ongoing_shipment_tracking_cron_interval',
			'ongoing_shipment_tracking_unfetched_cron_interval',
			'ongoing_shipment_tracking_max_updates_per_run',
			'ongoing_shipment_tracking_batch_size',
			'ongoing_shipment_tracking_exclude_delivered',
			'ongoing_shipment_tracking_last_cron_run',
			'ongoing_shipment_tracking_last_unfetched_cron_run',
		];
		
		// Add status-specific options
		$order_statuses = wc_get_order_statuses();
		foreach ( $order_statuses as $status_key => $status_label ) {
			$options_to_delete[] = 'ongoing_shipment_tracking_status_' . $status_key;
		}
		
		foreach ( $options_to_delete as $option ) {
			delete_option( $option );
		}
		
		if ( ! $quiet ) {
			\WP_CLI::log( sprintf( 'Total tracking data records deleted: %d', $total_deleted ) );
		}
		
		return $total_deleted;
	}

	/**
	 * Clean up tracking data from a single order
	 *
	 * @param int $order_id Order ID
	 * @return bool Success status
	 */
	private function cleanup_order_tracking_data( $order_id ) {
		$order = wc_get_order( $order_id );
		
		if ( ! $order ) {
			return false;
		}
		
		// Remove tracking-related meta data
		$order->delete_meta_data( '_ongoing_tracking_data' );
		$order->delete_meta_data( '_ongoing_tracking_status' );
		$order->delete_meta_data( '_ongoing_tracking_updated' );
		
		// Note: We keep the tracking number as it might be user-provided
		// $order->delete_meta_data( 'ongoing_tracking_number' );
		
		$order->save();
		
		return true;
	}

	/**
	 * Plugin uninstall cleanup - called when plugin is uninstalled
	 */
	public static function uninstall_cleanup() {
		global $wpdb;
		
		// Clear scheduled cron jobs
		wp_clear_scheduled_hook( 'ongoing_shipment_tracking_cron' );
		wp_clear_scheduled_hook( 'ongoing_shipment_tracking_unfetched_cron' );
		
		// Delete all tracking-related meta data
		$meta_keys = [
			'_ongoing_tracking_data',
			'_ongoing_tracking_status', 
			'_ongoing_tracking_updated',
			'ongoing_tracking_number'
		];
		
		foreach ( $meta_keys as $meta_key ) {
			$wpdb->delete(
				$wpdb->postmeta,
				[ 'meta_key' => $meta_key ],
				[ '%s' ]
			);
		}
		
		// Clean up plugin options
		$options_to_delete = [
			'ongoing_shipment_tracking_enable_cron',
			'ongoing_shipment_tracking_cron_interval',
			'ongoing_shipment_tracking_unfetched_cron_interval',
			'ongoing_shipment_tracking_max_updates_per_run',
			'ongoing_shipment_tracking_batch_size',
			'ongoing_shipment_tracking_exclude_delivered',
			'ongoing_shipment_tracking_last_cron_run',
			'ongoing_shipment_tracking_last_unfetched_cron_run',
		];
		
		// Add status-specific options
		$order_statuses = wc_get_order_statuses();
		foreach ( $order_statuses as $status_key => $status_label ) {
			$options_to_delete[] = 'ongoing_shipment_tracking_status_' . $status_key;
		}
		
		foreach ( $options_to_delete as $option ) {
			delete_option( $option );
		}
	}

	/**
	 * WP CLI backfill tracking data command
	 *
	 * @param array $assoc_args Command options
	 */
	private function wp_cli_backfill_tracking_data( $assoc_args ) {
		$limit = isset( $assoc_args['limit'] ) ? intval( $assoc_args['limit'] ) : 50;
		$dry_run = isset( $assoc_args['dry-run'] );
		$quiet = isset( $assoc_args['quiet'] );

		if ( ! $quiet ) {
			\WP_CLI::log( sprintf( 'Backfilling tracking data to repository table (limit: %d, dry-run: %s)...', $limit, $dry_run ? 'yes' : 'no' ) );
		}

		// Ensure repository table exists
		ShipmentTrackingRepository::ensure_installed();

		// Get orders that have tracking data in meta but not in repository
		global $wpdb;
		$table = ShipmentTrackingRepository::table_name();

		$sql = $wpdb->prepare( "
			SELECT DISTINCT p.ID, p.post_status
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} pm_tracking ON p.ID = pm_tracking.post_id
				AND pm_tracking.meta_key = 'ongoing_tracking_number'
				AND pm_tracking.meta_value != ''
			INNER JOIN {$wpdb->postmeta} pm_data ON p.ID = pm_data.post_id
				AND pm_data.meta_key = '_ongoing_tracking_data'
				AND pm_data.meta_value != ''
			LEFT JOIN {$table} t ON t.order_id = p.ID
			WHERE p.post_type = 'shop_order'
				AND t.order_id IS NULL
			ORDER BY p.ID ASC
			LIMIT %d
		", $limit );

		$orders = $wpdb->get_results( $sql );

		if ( empty( $orders ) ) {
			if ( ! $quiet ) {
				\WP_CLI::success( 'No orders found with tracking data in meta that needs backfilling.' );
			}
			return;
		}

		if ( ! $quiet ) {
			\WP_CLI::log( sprintf( 'Found %d orders to backfill.', count( $orders ) ) );
		}

		$backfilled = 0;
		$errors = [];
		$progress = $quiet ? null : \WP_CLI\Utils\make_progress_bar( 'Backfilling tracking data', count( $orders ) );

		foreach ( $orders as $order_info ) {
			$order_id = (int) $order_info->ID;
			$order = wc_get_order( $order_id );

			if ( ! $order ) {
				$errors[] = sprintf( 'Order %d not found', $order_id );
				continue;
			}

			$tracking_number = $order->get_meta( 'ongoing_tracking_number' );
			$tracking_data = $order->get_meta( '_ongoing_tracking_data' );
			$latest_status = $order->get_meta( '_ongoing_tracking_status' );

			if ( empty( $tracking_data ) ) {
				$errors[] = sprintf( 'Order %d has no tracking data in meta', $order_id );
				continue;
			}

			if ( $dry_run ) {
				if ( ! $quiet ) {
					\WP_CLI::log( sprintf( '  Would backfill order %d (tracking: %s, status: %s)', $order_id, $tracking_number, $latest_status ?: 'unknown' ) );
				}
				$backfilled++;
			} else {
				// Backfill to repository
				$success = $this->repository->upsert_order_tracking( $order_id, $tracking_number, $tracking_data, $latest_status );

				if ( $success ) {
					$backfilled++;
					if ( ! $quiet ) {
						\WP_CLI::log( sprintf( '  Backfilled order %d (tracking: %s, status: %s)', $order_id, $tracking_number, $latest_status ?: 'unknown' ) );
					}
				} else {
					$errors[] = sprintf( 'Failed to backfill order %d', $order_id );
				}
			}

			if ( $progress ) {
				$progress->tick();
			}
		}

		if ( $progress ) {
			$progress->finish();
		}

		// Display results
		if ( $dry_run ) {
			\WP_CLI::success( sprintf( 'Would backfill %d orders (dry run).', $backfilled ) );
		} else {
			\WP_CLI::success( sprintf( 'Successfully backfilled %d orders.', $backfilled ) );
		}

		if ( ! empty( $errors ) ) {
			\WP_CLI::warning( sprintf( 'Encountered %d errors:', count( $errors ) ) );
			foreach ( $errors as $error ) {
				\WP_CLI::log( '  - ' . $error );
			}
		}
	}
}