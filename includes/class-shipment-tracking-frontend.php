<?php
/**
 * Shipment Tracking Frontend Class
 *
 * @package Ongoing\ShipmentTracking
 */

namespace Ongoing\ShipmentTracking;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ShipmentTrackingFrontend class
 */
class ShipmentTrackingFrontend {

	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'woocommerce_view_order', [ $this, 'display_tracking_info' ], 5 );
		add_action( 'woocommerce_view_order', [ $this, 'display_order_details_heading' ], 8 );
		
		add_action( 'woocommerce_after_order_details', [ $this, 'display_customer_details_heading' ], 15 );
		add_action( 'woocommerce_view_order', 'woocommerce_customer_details_table', 16 );
		
		add_action( 'woocommerce_view_order', [ $this, 'display_tracking_timeline' ], 25 );
		//add_action( 'woocommerce_order_details_after_order_table', [ $this, 'display_tracking_info' ], 20 );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend_scripts' ] );
		add_action( 'wp_ajax_ongoing_frontend_update_tracking', [ $this, 'ajax_frontend_update_tracking' ] );
		add_action( 'wp_ajax_nopriv_ongoing_frontend_update_tracking', [ $this, 'ajax_frontend_update_tracking' ] );
		
		// Add tracking status to orders table
		add_filter( 'woocommerce_my_account_my_orders_columns', [ $this, 'add_tracking_column_to_orders_table' ] );
		add_action( 'woocommerce_my_account_my_orders_column_tracking', [ $this, 'add_tracking_column_content_to_orders_table' ] );
	}

	/**
	 * Display tracking information on order view page
	 *
	 * @param int $order_id Order ID
	 */
	public function display_tracking_info( $order_id ) {
		// Prevent duplicate output
		static $info_displayed = [];
		if ( in_array( $order_id, $info_displayed ) ) {
			return;
		}
		$info_displayed[] = $order_id;
		
		$order = wc_get_order( $order_id );
		
		if ( ! $order ) {
			return;
		}

        $tracking_number = $order->get_meta( 'ongoing_tracking_number' );
        $tracking = new ShipmentTracking();
        $tracking_data = $tracking->get_tracking_data( $order_id );
        $last_updated_meta = $order->get_meta( '_ongoing_tracking_updated' );
        $last_updated = is_array( $tracking_data ) && isset( $tracking_data['last_updated'] ) ? $tracking_data['last_updated'] : $last_updated_meta;

		// Only show if order is processing or completed
        /*
		if ( ! in_array( $order->get_status(), [ 'processing', 'completed' ] ) ) {
			return;
		}
            */

		echo '<section class="directhouse-ongoing-parcel-tracking">';
		echo '<h2>' . esc_html__( 'Delivery Tracking', 'directhouse-ongoing-parcel-tracking' ) . '</h2>';

		if ( empty( $tracking_number ) ) {
			echo '<p>' . esc_html__( 'Tracking information is not yet available for this order.', 'directhouse-ongoing-parcel-tracking' ) . '</p>';
			echo '</section>';
			return;
		}

		// Get shipping method ID for tracking link
		$tracking = new ShipmentTracking();
		$shipping_method_info = $tracking->get_shipping_method_info( $order_id );
		$api = new ShipmentTrackingAPI();
		$tracking_link = $api->get_tracking_link( 
			$tracking_number, 
			$shipping_method_info['method_id'],
			$shipping_method_info['method_title'],
			$shipping_method_info['shipping_method']
		);

		// Display tracking information
        if ( $tracking_data && ! empty( $tracking_data['events'] ) ) {
            $this->display_tracking_combined_section( $tracking_data['events'], $order_id, $tracking_link, $last_updated );
		} else {
			echo '<p>' . esc_html__( 'No tracking information available yet. Please check back later.', 'directhouse-ongoing-parcel-tracking' ) . '</p>';
		}

		echo '</section>';
	}

	/**
	 * Display tracking combined section (status and link)
	 *
	 * @param array $events Formatted tracking events
	 * @param int   $order_id Order ID
	 * @param string|null $tracking_link Tracking link URL
	 * @param string|null $last_updated Last updated timestamp
	 */
	private function display_tracking_combined_section( $events, $order_id, $tracking_link = null, $last_updated = null ) {
		$api = new ShipmentTrackingAPI();
		$latest_status = $api->get_latest_status( $events );
		
		// Combined status summary and tracking link section
		echo '<div class="tracking-combined-section">';
		
		// Left column: Status summary
		echo '<div class="tracking-status-column">';
		echo '<div class="tracking-status-summary">';
		echo '<h3>' . esc_html__( 'Current Status', 'directhouse-ongoing-parcel-tracking' ) . '</h3>';

        		// Last updated info
		if ( $last_updated ) {
			echo '<p class="tracking-last-updated">';
			echo '<small>' . esc_html__( 'Last updated:', 'directhouse-ongoing-parcel-tracking' ) . ' ' . esc_html( $last_updated ) . '</small>';
			echo '</p>';
		}
		
		$status_labels = [
			'DELIVERED' => __( 'Delivered', 'directhouse-ongoing-parcel-tracking' ),
			'AVAILABLE_FOR_DELIVERY' => __( 'Available for pickup', 'directhouse-ongoing-parcel-tracking' ),
			'EN_ROUTE' => __( 'In transit', 'directhouse-ongoing-parcel-tracking' ),
			'sent' => __( 'Sent', 'directhouse-ongoing-parcel-tracking' ),
			'waiting_to_be_picked' => __( 'Waiting to be picked', 'directhouse-ongoing-parcel-tracking' ),
			'picking' => __( 'Being picked', 'directhouse-ongoing-parcel-tracking' ),
			'OTHER' => __( 'Processing', 'directhouse-ongoing-parcel-tracking' ),
			'unknown' => __( 'Unknown', 'directhouse-ongoing-parcel-tracking' ),
		];

        $status_class = 'tracking-status-' . strtolower( $latest_status );
        $status_text = $status_labels[ $latest_status ] ?? $status_labels['unknown'];

        // Optional emojis for statuses
        $emoji_enabled = get_option( 'ongoing_shipment_tracking_enable_emojis', 'no' ) === 'yes';
        if ( $emoji_enabled ) {
            $emoji_map = [
                'DELIVERED' => '✅',
                'AVAILABLE_FOR_DELIVERY' => '📦',
                'EN_ROUTE' => '🚚',
                'sent' => '📤',
                'waiting_to_be_picked' => '🧺',
                'picking' => '🛒',
                'OTHER' => 'ℹ️',
                'unknown' => '❔',
            ];
            $emoji = $emoji_map[ $latest_status ] ?? '';
            if ( $emoji ) {
                $status_text = $emoji . ' ' . $status_text;
            }
        }
		
		echo '<div class="tracking-status ' . esc_attr( $status_class ) . '">';
		echo esc_html( $status_text );
		echo '</div>';
		
		// Refresh link under status badge
		echo '<div class="tracking-update-section">';
		echo '<a href="#" class="update-tracking-link" data-order-id="' . esc_attr( $order_id ) . '">';
		echo esc_html__( 'Refresh tracking information', 'directhouse-ongoing-parcel-tracking' );
		echo '</a>';
		echo '<span class="tracking-update-status"></span>';
		echo '</div>';
		
		// Anchor link to timeline
		echo '<div class="tracking-timeline-link">';
		echo '<a href="#tracking-timeline" class="timeline-anchor-link">';
		echo esc_html__( 'View detailed timeline', 'directhouse-ongoing-parcel-tracking' );
		echo '</a>';
		echo '</div>';
		
		echo '</div>'; // .tracking-status-summary
		echo '</div>'; // .tracking-status-column
		
		// Right column: Tracking link section
		if ( $tracking_link ) {
			echo '<div class="tracking-link-column">';
			echo '<div class="tracking-link-section">';
			echo '<h3>' . esc_html__( 'Track Your Package', 'directhouse-ongoing-parcel-tracking' ) . '</h3>';
			echo '<p>' . esc_html__( 'You can also track your package directly on the carrier\'s website:', 'directhouse-ongoing-parcel-tracking' ) . '</p>';
			echo '<a href="' . esc_url( $tracking_link ) . '" target="_blank" class="tracking-link-button">';
			echo esc_html__( 'Track Package', 'directhouse-ongoing-parcel-tracking' );
			echo '</a>';
			echo '</div>'; // .tracking-link-section
			echo '</div>'; // .tracking-link-column
		}
	
		
		echo '</div>'; // .tracking-combined-section
	}

	/**
	 * Display order details heading
	 *
	 * @param int $order_id Order ID
	 */
	public function display_order_details_heading( $order_id ) {
		// Prevent duplicate output
		static $heading_displayed = [];
		if ( in_array( $order_id, $heading_displayed ) ) {
			return;
		}
		$heading_displayed[] = $order_id;
		
		$order = wc_get_order( $order_id );
		
		if ( ! $order ) {
			return;
		}

		echo '<h2 class="order-details-heading">' . esc_html__( 'Order details', 'directhouse-ongoing-parcel-tracking' ) . '</h2>';
	}

	/**
	 * Display customer details heading
	 *
	 * @param int $order_id Order ID
	 */
	public function display_customer_details_heading( $order_id ) {
		// Prevent duplicate output
		static $customer_heading_displayed = [];
		if ( in_array( $order_id, $customer_heading_displayed ) ) {
			return;
		}
		$customer_heading_displayed[] = $order_id;
		
		$order = wc_get_order( $order_id );
		
		if ( ! $order ) {
			return;
		}

		echo '<h2 class="customer-details-heading">' . esc_html__( 'Customer Details', 'directhouse-ongoing-parcel-tracking' ) . '</h2>';
	}

	/**
	 * Display tracking timeline
	 *
	 * @param int $order_id Order ID
	 */
	public function display_tracking_timeline( $order_id ) {
		// Prevent duplicate output
		static $timeline_displayed = [];
		if ( in_array( $order_id, $timeline_displayed ) ) {
			return;
		}
		$timeline_displayed[] = $order_id;
		
		$order = wc_get_order( $order_id );
		
		if ( ! $order ) {
			return;
		}

		$tracking = new ShipmentTracking();
		$tracking_data = $tracking->get_tracking_data( $order_id );
		
		if ( ! $tracking_data || empty( $tracking_data['events'] ) ) {
			return;
		}

		$events = $tracking_data['events'];

		// If delivered exists, hide any events after the last DELIVERED
		$delivered_cutoff = null;
		foreach ( $events as $event ) {
			if ( ( $event['transporter_status'] ?? '' ) === 'DELIVERED' ) {
				$ts = (int) ( $event['timestamp'] ?? 0 );
				if ( $ts > 0 ) {
					$delivered_cutoff = max( (int) $delivered_cutoff, $ts );
				}
			}
		}
		if ( $delivered_cutoff ) {
			$events = array_filter( $events, function( $e ) use ( $delivered_cutoff ) {
				return (int) ( $e['timestamp'] ?? 0 ) <= $delivered_cutoff;
			} );
		}

		// Filter out events with "OTHER" transporter_status for customer view
		$filtered_events = array_filter( $events, function( $event ) {
			return ( $event['transporter_status'] ?? '' ) !== 'OTHER';
		} );

		// Sort filtered events by timestamp (newest first for display)
		usort( $filtered_events, function( $a, $b ) {
			return $b['timestamp'] - $a['timestamp'];
		} );

		// Tracking timeline
		echo '<section class="directhouse-ongoing-parcel-tracking-timeline">';
		echo '<div class="tracking-timeline" id="tracking-timeline">';
		echo '<h2>' . esc_html__( 'Tracking Timeline', 'directhouse-ongoing-parcel-tracking' ) . '</h2>';
		
		echo '<div class="tracking-events">';
		
		foreach ( $filtered_events as $index => $event ) {
			$is_latest = $index === 0;
			$event_class = 'tracking-event ' . $event['status_class'];
			
			if ( $is_latest ) {
				$event_class .= ' latest-event';
			}
			
			echo '<div class="' . esc_attr( $event_class ) . '">';
			
			// Event date
			echo '<div class="event-date">';
			echo '<strong>' . esc_html( $this->format_date_for_display( $event['date'] ) ) . '</strong>';
			echo '</div>';
			
			// Event content
			echo '<div class="event-content">';
			echo '<div class="event-description">' . esc_html( $this->translate_event_description( $event['description'] ) ) . '</div>';
			
			if ( ! empty( $event['location'] ) ) {
				echo '<div class="event-location">';
				echo '<i class="dashicons dashicons-location"></i> ';
				echo esc_html( $event['location'] );
				echo '</div>';
			}
			echo '</div>';
			
			echo '</div>';
		}
		
		echo '</div>'; // .tracking-events
		echo '</div>'; // .tracking-timeline
		echo '</section>'; // .directhouse-ongoing-parcel-tracking-timeline
	}

	/**
	 * Translate event description
	 *
	 * @param string $description Original event description
	 * @return string Translated description
	 */
	private function translate_event_description( $description ) {
		// Common event descriptions and their translations
		$translations = [
			'Order has been placed in the warehouse and will be prepared for picking' => __( 'Order has been placed in the warehouse and will be prepared for picking', 'directhouse-ongoing-parcel-tracking' ),
			'Order has been picked and is ready for further processing in the warehouse' => __( 'Order has been picked and is ready for further processing in the warehouse', 'directhouse-ongoing-parcel-tracking' ),
			'Your order has left the warehouse and beeing transported to the terminal' => __( 'Your order has left the warehouse and is being transported to the terminal', 'directhouse-ongoing-parcel-tracking' ),
			'The shipment item is under transportation.' => __( 'The shipment item is under transportation.', 'directhouse-ongoing-parcel-tracking' ),
			'A text message notification has been delivered to the recipient.' => __( 'A text message notification has been delivered to the recipient.', 'directhouse-ongoing-parcel-tracking' ),
			'The shipment item has been loaded.' => __( 'The shipment item has been loaded.', 'directhouse-ongoing-parcel-tracking' ),
			'The shipment item has been delivered to a service point.' => __( 'The shipment item has been delivered to a service point.', 'directhouse-ongoing-parcel-tracking' ),
			'The shipment item has arrived at the distribution terminal.' => __( 'The shipment item has arrived at the distribution terminal.', 'directhouse-ongoing-parcel-tracking' ),
			'The delivery of the shipment item is in progress.' => __( 'The delivery of the shipment item is in progress.', 'directhouse-ongoing-parcel-tracking' ),
			'The shipment item has been delivered.' => __( 'The shipment item has been delivered.', 'directhouse-ongoing-parcel-tracking' ),
			'The shipment item has been delivered to the recipient\'s mailbox.' => __( 'The shipment item has been delivered to the recipient\'s mailbox.', 'directhouse-ongoing-parcel-tracking' ),
			'The shipment item has been routed to a service point.' => __( 'The shipment item has been routed to a service point.', 'directhouse-ongoing-parcel-tracking' ),
			'No further tracking information is available. If the transporter has a separate app, you may be notified there, or via SMS or email when the parcel is delivered.' => __( 'No further tracking information is available. If the transporter has a separate app, you may be notified there, or via SMS or email when the parcel is delivered.', 'directhouse-ongoing-parcel-tracking' ),
		];

		// Return translated version if available, otherwise return original
		return $translations[ $description ] ?? $description;
	}

	/**
	 * Format date for display
	 *
	 * @param string $date_string Date string
	 * @return string Formatted date
	 */
	private function format_date_for_display( $date_string ) {
		if ( empty( $date_string ) ) {
			return '';
		}

		try {
			// Create DateTime object from the formatted date string
			$date = new \DateTime( $date_string );
			
			// Convert to WordPress timezone
			$wp_timezone = wp_timezone();
			$date->setTimezone( $wp_timezone );
			
			// Format using WordPress date and time format settings
			return $date->format( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
		} catch ( \Exception $e ) {
			return $date_string;
		}
	}

	/**
	 * Enqueue frontend scripts
	 */
	public function enqueue_frontend_scripts() {
		if ( is_account_page() || is_wc_endpoint_url( 'view-order' ) ) {
            wp_enqueue_script(
                'directhouse-ongoing-parcel-tracking-frontend',
                plugins_url( 'assets/js/frontend.js', dirname( __DIR__ ) . '/woo-directhouse-ongoing-parcel-tracking.php' ),
                [ 'jquery' ],
                ( defined( __NAMESPACE__ . '\\PLUGIN_VERSION' ) ? constant( __NAMESPACE__ . '\\PLUGIN_VERSION' ) : '1.0.0' ),
                true
            );

			wp_localize_script(
				'directhouse-ongoing-parcel-tracking-frontend',
				'ongoingTrackingFrontend',
				[
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce' => wp_create_nonce( 'ongoing_shipment_tracking_nonce' ),
					'strings' => [
						'updating' => __( 'Updating tracking information...', 'directhouse-ongoing-parcel-tracking' ),
						'success' => __( 'Tracking information updated!', 'directhouse-ongoing-parcel-tracking' ),
						'error' => __( 'Error updating tracking information.', 'directhouse-ongoing-parcel-tracking' ),
					],
				]
			);

            wp_enqueue_style(
                'directhouse-ongoing-parcel-tracking-frontend',
                plugins_url( 'assets/css/frontend.css', dirname( __DIR__ ) . '/woo-directhouse-ongoing-parcel-tracking.php' ),
                [],
                ( defined( __NAMESPACE__ . '\\PLUGIN_VERSION' ) ? constant( __NAMESPACE__ . '\\PLUGIN_VERSION' ) : '1.0.0' )
            );
		}
	}

	/**
	 * AJAX handler for frontend tracking update
	 */
	public function ajax_frontend_update_tracking() {
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
		if ( $order->get_customer_id() !== get_current_user_id() ) {
			wp_send_json_error( 'Access denied' );
		}

		// Update tracking
		$tracking = new ShipmentTracking();
		$result = $tracking->update_order_tracking( $order_id );
		
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Add tracking column to orders table
	 *
	 * @param array $columns Existing columns
	 * @return array Modified columns
	 */
	public function add_tracking_column_to_orders_table( $columns ) {
		$new_columns = [];
		
		foreach ( $columns as $key => $column ) {
			$new_columns[ $key ] = $column;
			
			// Add tracking column after order status
			if ( $key === 'order-status' ) {
				$new_columns['tracking'] = __( 'Shipment Status', 'directhouse-ongoing-parcel-tracking' );
			}
		}
		
		return $new_columns;
	}

	/**
	 * Add content to tracking column in orders table
	 *
	 * @param object $order Order object
	 */
	public function add_tracking_column_content_to_orders_table( $order ) {
		$tracking_number = $order->get_meta( 'ongoing_tracking_number' );
		$tracking_status = $order->get_meta( '_ongoing_tracking_status' );

		if ( empty( $tracking_number ) ) {
			echo '<span class="tracking-status-no-tracking">' . esc_html__( 'No tracking', 'directhouse-ongoing-parcel-tracking' ) . '</span>';
			return;
		}

		if ( $tracking_status ) {
			$status_labels = [
				'DELIVERED' => __( 'Delivered', 'directhouse-ongoing-parcel-tracking' ),
				'AVAILABLE_FOR_DELIVERY' => __( 'Available for pickup', 'directhouse-ongoing-parcel-tracking' ),
				'EN_ROUTE' => __( 'In transit', 'directhouse-ongoing-parcel-tracking' ),
                'sent' => __( 'Sent', 'directhouse-ongoing-parcel-tracking' ),
				'waiting_to_be_picked' => __( 'Waiting to be picked', 'directhouse-ongoing-parcel-tracking' ),
				'picking' => __( 'Being picked', 'directhouse-ongoing-parcel-tracking' ),
				'OTHER' => __( 'Processing', 'directhouse-ongoing-parcel-tracking' ),
				'unknown' => __( 'Unknown', 'directhouse-ongoing-parcel-tracking' ),
			];

			$status_class = 'tracking-status-' . strtolower( $tracking_status );
            $status_text = $status_labels[ $tracking_status ] ?? $status_labels['unknown'];

            // Optional emojis in My Account orders table
            $emoji_enabled = get_option( 'ongoing_shipment_tracking_enable_emojis', 'no' ) === 'yes';
            if ( $emoji_enabled ) {
                $emoji_map = [
                    'DELIVERED' => '✅',
                    'AVAILABLE_FOR_DELIVERY' => '📦',
                    'EN_ROUTE' => '🚚',
                    'sent' => '📤',
                    'waiting_to_be_picked' => '🧺',
                    'picking' => '🛒',
                    'OTHER' => 'ℹ️',
                    'unknown' => '❔',
                ];
                $emoji = $emoji_map[ $tracking_status ] ?? '';
                if ( $emoji ) {
                    $status_text = $emoji . ' ' . $status_text;
                }
            }
			
            echo '<span class="tracking-status ' . esc_attr( $status_class ) . '">' . esc_html( $status_text ) . '</span>';
		} else {
			echo '<span class="tracking-status-no-data">' . esc_html__( 'No data', 'directhouse-ongoing-parcel-tracking' ) . '</span>';
		}
	}
} 