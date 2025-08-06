<?php
/**
 * Shipment Tracking API Class
 *
 * @package Ongoing\ShipmentTracking
 */

namespace Ongoing\ShipmentTracking;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ShipmentTrackingAPI class
 */
class ShipmentTrackingAPI {

	/**
	 * API base URL
	 *
	 * @var string
	 */
	private $api_base_url = 'https://warehouse.directhouse.no/api/';

	/**
	 * Constructor
	 */
	public function __construct() {
		// Allow filtering of API base URL
		$this->api_base_url = apply_filters( 'ongoing_shipment_tracking_api_base_url', $this->api_base_url );
	}

	/**
	 * Get tracking data from API
	 *
	 * @param string $tracking_number Tracking number
	 * @return array|WP_Error Tracking data or error
	 */
	public function get_tracking_data( $tracking_number ) {
		if ( empty( $tracking_number ) ) {
			return new \WP_Error( 'empty_tracking_number', 'Tracking number is required' );
		}

		$url = $this->api_base_url . 'fullOrderTracking/' . urlencode( $tracking_number );
		
		$response = wp_remote_get( $url, [
			'timeout' => 30,
			'headers' => [
				'User-Agent' => 'Ongoing-Shipment-Tracking/' . PLUGIN_VERSION,
				'Accept'     => 'application/json',
			],
		] );

		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'api_request_failed', 'Failed to connect to tracking API: ' . $response->get_error_message() );
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		
		if ( $status_code !== 200 ) {
			return new \WP_Error( 'api_error', sprintf( 'API returned status code %d', $status_code ) );
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new \WP_Error( 'json_decode_error', 'Failed to parse API response' );
		}

		// Check for API error response
		if ( isset( $data['error'] ) ) {
			$translated_error = $this->translate_error_message( $data['error'] );
			return new \WP_Error( 'api_error', $translated_error );
		}

		// Validate response structure
		if ( ! isset( $data['events'] ) || ! is_array( $data['events'] ) ) {
			return new \WP_Error( 'invalid_response', 'Invalid response structure from API' );
		}

		// Process and format the events
		$formatted_events = $this->format_events( $data['events'] );

		return [
			'events' => $formatted_events,
			'raw_data' => $data,
			'last_updated' => current_time( 'mysql' ),
		];
	}

	/**
	 * Format events for display
	 *
	 * @param array $events Raw events from API
	 * @return array Formatted events
	 */
	private function format_events( $events ) {
		$formatted = [];

		foreach ( $events as $event ) {
			$formatted_event = [
				'date' => $this->format_date( $event['date'] ?? '' ),
				'description' => $event['eventdescription'] ?? '', // Store original, translate at display time
				'location' => $event['location'] ?? '',
				'type' => $event['type'] ?? '',
				'transporter_status' => $event['transporter_status'] ?? '',
				'timestamp' => $event['timestamp'] ?? 0,
				'status_class' => $this->get_status_class( $event ),
			];

			$formatted[] = $formatted_event;
		}

		// Sort by timestamp (oldest first for chronological order)
		usort( $formatted, function( $a, $b ) {
			return $a['timestamp'] - $b['timestamp'];
		} );

		return $formatted;
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
	 * Translate error message
	 *
	 * @param string $error_message Original error message
	 * @return string Translated error message
	 */
	private function translate_error_message( $error_message ) {
		// Common error messages and their translations
		$translations = [
			"We're currently having problems retrieving the order information associated with the tracking number." => __( "We're currently having problems retrieving the order information associated with the tracking number.", 'directhouse-ongoing-parcel-tracking' ),
		];

		// Return translated version if available, otherwise return original
		return $translations[ $error_message ] ?? $error_message;
	}

	/**
	 * Format date for display
	 *
	 * @param string $date_string Date string from API
	 * @return string Formatted date
	 */
	private function format_date( $date_string ) {
		if ( empty( $date_string ) ) {
			return '';
		}

		try {
			// Create DateTime object from the ISO 8601 date string
			$date = new \DateTime( $date_string );
			
			// Convert to WordPress timezone
			$wp_timezone = wp_timezone();
			$date->setTimezone( $wp_timezone );
			
			// Return formatted date in WordPress timezone
			return $date->format( 'Y-m-d H:i:s' );
		} catch ( \Exception $e ) {
			return $date_string;
		}
	}

	/**
	 * Get CSS class for status
	 *
	 * @param array $event Event data
	 * @return string CSS class
	 */
	private function get_status_class( $event ) {
		$status = $event['transporter_status'] ?? '';
		$type = $event['type'] ?? '';

		switch ( $status ) {
			case 'DELIVERED':
				return 'status-delivered';
			case 'AVAILABLE_FOR_DELIVERY':
				return 'status-available';
			case 'EN_ROUTE':
				return 'status-en-route';
			case 'OTHER':
				return 'status-other';
			default:
				if ( $type === 'Warehouse' ) {
					return 'status-warehouse';
				}
				return 'status-default';
		}
	}

	/**
	 * Get latest event status
	 *
	 * @param array $events Formatted events (with original descriptions)
	 * @return string Status
	 */
	public function get_latest_status( $events ) {
		if ( empty( $events ) ) {
			return 'unknown';
		}

		// Since events are now sorted chronologically (oldest first), 
		// we need to find the last meaningful status
		$meaningful_status = 'unknown';
		
		foreach ( $events as $event ) {
			$status = $event['transporter_status'] ?? 'unknown';
			if ( $status !== 'OTHER' && $status !== 'unknown' ) {
				$meaningful_status = $status;
			}
		}

		// Check if the latest event indicates picking status
		$latest_event = end( $events );
		if ( $latest_event && $this->is_waiting_to_be_picked_event( $latest_event ) ) {
			return 'waiting_to_be_picked';
		}
		if ( $latest_event && $this->is_being_picked_event( $latest_event ) ) {
			return 'picking';
		}

		return $meaningful_status;
	}

	/**
	 * Check if an event indicates waiting to be picked status
	 *
	 * @param array $event Event data
	 * @return bool True if event indicates waiting to be picked
	 */
	private function is_waiting_to_be_picked_event( $event ) {
		// Use the description field (now contains original eventdescription)
		$description = strtolower( $event['description'] ?? '' );
		
		// Check for "waiting to be picked" keywords
		$waiting_keywords = [
			'placed in the warehouse and will be prepared for picking',
		];
		
		foreach ( $waiting_keywords as $keyword ) {
			if ( strpos( $description, $keyword ) !== false ) {
				return true;
			}
		}
		
		return false;
	}

	/**
	 * Check if an event indicates being picked status
	 *
	 * @param array $event Event data
	 * @return bool True if event indicates being picked
	 */
	private function is_being_picked_event( $event ) {
		// Use the description field (now contains original eventdescription)
		$description = strtolower( $event['description'] ?? '' );
		
		// Check for "being picked" keywords
		$picking_keywords = [
			'prepared for picking',
			'picking',
			'being picked',
			'order has been picked',
			'picked and is ready',
		];
		
		foreach ( $picking_keywords as $keyword ) {
			if ( strpos( $description, $keyword ) !== false ) {
				return true;
			}
		}
		
		return false;
	}

	/**
	 * Check if order is delivered
	 *
	 * @param array $events Formatted events
	 * @return bool
	 */
	public function is_delivered( $events ) {
		return $this->get_latest_status( $events ) === 'DELIVERED';
	}

	/**
	 * Get delivery date
	 *
	 * @param array $events Formatted events
	 * @return string|null Delivery date or null if not delivered
	 */
	public function get_delivery_date( $events ) {
		foreach ( $events as $event ) {
			if ( $event['transporter_status'] === 'DELIVERED' ) {
				return $event['date'];
			}
		}
		
		return null;
	}

	/**
	 * Determine transporter from shipping method information
	 *
	 * @param string $shipping_method_id Shipping method ID
	 * @param string $shipping_method_title Shipping method title
	 * @param string $shipping_method_name Shipping method name
	 * @return string|null Transporter name or null if not supported
	 */
	public function determine_transporter( $shipping_method_id, $shipping_method_title = '', $shipping_method_name = '' ) {
		// Convert all fields to lowercase for case-insensitive comparison
		$method_id_lower = strtolower( $shipping_method_id );
		$method_title_lower = strtolower( $shipping_method_title );
		$method_name_lower = strtolower( $shipping_method_name );
		
		// Check for PostNord
		if ( strpos( $method_id_lower, 'postnord' ) !== false ||
			 strpos( $method_title_lower, 'postnord' ) !== false ||
			 strpos( $method_name_lower, 'postnord' ) !== false ) {
			return 'postnord';
		}
		
		// Check for Instabox
		if ( strpos( $method_id_lower, 'instabox' ) !== false ||
			 strpos( $method_title_lower, 'instabox' ) !== false ||
			 strpos( $method_name_lower, 'instabox' ) !== false ) {
			return 'instabox';
		}
		
		// Check for Bring Norge
		if ( strpos( $method_id_lower, 'bring' ) !== false ||
			 strpos( $method_title_lower, 'bring' ) !== false ||
			 strpos( $method_name_lower, 'bring' ) !== false ) {
			return 'bring';
		}
		
		// Check for Posten Norge
		if ( strpos( $method_id_lower, 'posten' ) !== false ||
			 strpos( $method_title_lower, 'posten' ) !== false ||
			 strpos( $method_name_lower, 'posten' ) !== false ) {
			return 'posten';
		}
		
		// Check for HeltHjem
		if ( strpos( $method_id_lower, 'helthjem' ) !== false ||
			 strpos( $method_title_lower, 'helthjem' ) !== false ||
			 strpos( $method_name_lower, 'helthjem' ) !== false ) {
			return 'helthjem';
		}
		
		return null;
	}

	/**
	 * Generate tracking link based on shipping method
	 *
	 * @param string $tracking_number Tracking number
	 * @param string $shipping_method_id Shipping method ID
	 * @param string $shipping_method_title Shipping method title (optional)
	 * @param string $shipping_method_name Shipping method name (optional)
	 * @return string|null Tracking link or null if not supported
	 */
	public function get_tracking_link( $tracking_number, $shipping_method_id, $shipping_method_title = '', $shipping_method_name = '' ) {
		if ( empty( $tracking_number ) || empty( $shipping_method_id ) ) {
			return null;
		}

		// Determine transporter
		$transporter = $this->determine_transporter( $shipping_method_id, $shipping_method_title, $shipping_method_name );
		
		if ( ! $transporter ) {
			return null;
		}

		// Get current language code (2 letters)
		$language_code = substr( get_locale(), 0, 2 );
		
		// Generate tracking link based on transporter
		switch ( $transporter ) {
			case 'postnord':
				return sprintf(
					'https://tracking.postnord.com/%s/tracking?id=%s',
					$language_code,
					urlencode( $tracking_number )
				);
				
			case 'instabox':
				return sprintf(
					'https://track.instabox.io/%s',
					urlencode( $tracking_number )
				);
				
			case 'bring':
				// Bring Norge: Norwegian version (no lang parameter) or English version (?lang=en)
				$lang_param = ( $language_code === 'en' ) ? '?lang=en' : '';
				return sprintf(
					'https://sporing.bring.no/sporing/%s%s',
					urlencode( $tracking_number ),
					$lang_param
				);
				
			case 'posten':
				// Posten Norge: Norwegian version (no lang parameter) or English version (?lang=en)
				$lang_param = ( $language_code === 'en' ) ? '?lang=en' : '';
				return sprintf(
					'https://sporing.posten.no/sporing/%s%s',
					urlencode( $tracking_number ),
					$lang_param
				);
				
			case 'helthjem':
				// HeltHjem: Simple tracking URL format
				return sprintf(
					'https://helthjem.no/sporing/%s',
					urlencode( $tracking_number )
				);
				
			default:
				return null;
		}
	}
} 