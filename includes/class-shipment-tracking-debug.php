<?php
/**
 * Debug logging functionality for DirectHouse Ongoing Parcel Tracking
 *
 * @package Ongoing\ShipmentTracking
 */

namespace Ongoing\ShipmentTracking;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Debug logging class
 */
class ShipmentTrackingDebug {

	/**
	 * Log file path
	 *
	 * @var string
	 */
	private static $log_file = null;

	/**
	 * Initialize debug logging
	 */
	public static function init() {
		if ( self::is_debug_enabled() ) {
			self::$log_file = WP_CONTENT_DIR . '/logs/directhouse-tracking-debug.log';
			
			// Ensure logs directory exists
			$logs_dir = dirname( self::$log_file );
			if ( ! is_dir( $logs_dir ) ) {
				wp_mkdir_p( $logs_dir );
			}
		}
	}

	/**
	 * Check if debug logging is enabled
	 *
	 * @return bool
	 */
	public static function is_debug_enabled() {
		return get_option( 'ongoing_shipment_tracking_enable_debug', 'no' ) === 'yes';
	}

	/**
	 * Log a debug message
	 *
	 * @param string $message Message to log
	 * @param string $level Log level (debug, info, warning, error)
	 * @param array $context Additional context data
	 */
	public static function log( $message, $level = 'debug', $context = [] ) {
		if ( ! self::is_debug_enabled() ) {
			return;
		}

		$timestamp = current_time( 'Y-m-d H:i:s' );
		$level_upper = strtoupper( $level );
		
		$log_entry = sprintf(
			'[%s] [%s] %s',
			$timestamp,
			$level_upper,
			$message
		);

		// Add context if provided
		if ( ! empty( $context ) ) {
			$log_entry .= ' | Context: ' . json_encode( $context, JSON_UNESCAPED_SLASHES );
		}

		$log_entry .= PHP_EOL;

		// Write to log file
		if ( self::$log_file ) {
			error_log( $log_entry, 3, self::$log_file );
		}

		// Also output to CLI if running in CLI mode
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::log( $log_entry );
		}
	}

	/**
	 * Log API request
	 *
	 * @param string $url API URL
	 * @param array $headers Request headers
	 * @param string $method HTTP method
	 * @param string $body Request body (if any)
	 */
	public static function log_api_request( $url, $headers = [], $method = 'GET', $body = '' ) {
		$context = [
			'url' => $url,
			'method' => $method,
			'headers' => $headers,
		];

		if ( ! empty( $body ) ) {
			$context['body'] = $body;
		}

		self::log( 'API Request', 'info', $context );
	}

	/**
	 * Log API response
	 *
	 * @param string $url API URL
	 * @param int $http_code HTTP status code
	 * @param array $headers Response headers
	 * @param string $body Response body
	 * @param float $response_time Response time in seconds
	 */
	public static function log_api_response( $url, $http_code, $headers = [], $body = '', $response_time = 0 ) {
		$context = [
			'url' => $url,
			'http_code' => $http_code,
			'response_time' => round( $response_time, 3 ) . 's',
			'headers' => $headers,
		];

		// Truncate response body if too long
		if ( strlen( $body ) > 1000 ) {
			$context['body'] = substr( $body, 0, 1000 ) . '... (truncated)';
		} else {
			$context['body'] = $body;
		}

		$level = $http_code >= 400 ? 'error' : 'info';
		self::log( 'API Response', $level, $context );
	}

	/**
	 * Log API error
	 *
	 * @param string $url API URL
	 * @param string $error Error message
	 * @param array $context Additional context
	 */
	public static function log_api_error( $url, $error, $context = [] ) {
		$context['url'] = $url;
		$context['error'] = $error;
		self::log( 'API Error', 'error', $context );
	}

	/**
	 * Log CLI command execution
	 *
	 * @param string $command Command name
	 * @param array $args Command arguments
	 * @param array $assoc_args Command options
	 */
	public static function log_cli_command( $command, $args = [], $assoc_args = [] ) {
		$context = [
			'command' => $command,
			'args' => $args,
			'options' => $assoc_args,
		];
		self::log( 'CLI Command Executed', 'info', $context );
	}

	/**
	 * Log order processing
	 *
	 * @param int $order_id Order ID
	 * @param string $action Action being performed
	 * @param array $context Additional context
	 */
	public static function log_order_processing( $order_id, $action, $context = [] ) {
		$context['order_id'] = $order_id;
		$context['action'] = $action;
		self::log( 'Order Processing', 'info', $context );
	}

	/**
	 * Log tracking data update
	 *
	 * @param int $order_id Order ID
	 * @param string $tracking_number Tracking number
	 * @param array $tracking_data Tracking data
	 * @param bool $success Whether update was successful
	 */
	public static function log_tracking_update( $order_id, $tracking_number, $tracking_data = [], $success = true ) {
		$context = [
			'order_id' => $order_id,
			'tracking_number' => $tracking_number,
			'success' => $success,
		];

		if ( ! empty( $tracking_data ) ) {
			// Only log essential tracking data to avoid huge log files
			$context['events_count'] = isset( $tracking_data['events'] ) ? count( $tracking_data['events'] ) : 0;
			$context['latest_status'] = isset( $tracking_data['latest_status'] ) ? $tracking_data['latest_status'] : null;
		}

		$level = $success ? 'info' : 'error';
		self::log( 'Tracking Update', $level, $context );
	}

	/**
	 * Log memory usage
	 *
	 * @param string $context Context where memory was checked
	 */
	public static function log_memory_usage( $context = '' ) {
		$memory_usage = memory_get_usage( true );
		$memory_peak = memory_get_peak_usage( true );
		$memory_limit = ini_get( 'memory_limit' );

		$context_data = [
			'current' => self::format_bytes( $memory_usage ),
			'peak' => self::format_bytes( $memory_peak ),
			'limit' => $memory_limit,
			'usage_percent' => round( ( $memory_usage / self::parse_memory_limit( $memory_limit ) ) * 100, 2 ),
		];

		if ( $context ) {
			$context_data['context'] = $context;
		}

		self::log( 'Memory Usage', 'debug', $context_data );
	}

	/**
	 * Log performance metrics
	 *
	 * @param string $operation Operation name
	 * @param float $start_time Start time
	 * @param array $context Additional context
	 */
	public static function log_performance( $operation, $start_time, $context = [] ) {
		$end_time = microtime( true );
		$duration = $end_time - $start_time;

		$context['operation'] = $operation;
		$context['duration'] = round( $duration, 3 ) . 's';
		$context['start_time'] = date( 'Y-m-d H:i:s', (int) $start_time );
		$context['end_time'] = date( 'Y-m-d H:i:s', (int) $end_time );

		self::log( 'Performance', 'debug', $context );
	}

	/**
	 * Log database query
	 *
	 * @param string $sql SQL query
	 * @param array $params Query parameters
	 * @param float $query_time Query execution time
	 */
	public static function log_database_query( $sql, $params = [], $query_time = 0 ) {
		$context = [
			'sql' => $sql,
			'params' => $params,
			'query_time' => round( $query_time, 3 ) . 's',
		];

		self::log( 'Database Query', 'debug', $context );
	}

	/**
	 * Get log file path
	 *
	 * @return string|null
	 */
	public static function get_log_file_path() {
		return self::$log_file;
	}

	/**
	 * Clear log file
	 */
	public static function clear_log() {
		if ( self::$log_file && file_exists( self::$log_file ) ) {
			file_put_contents( self::$log_file, '' );
		}
	}

	/**
	 * Get log file size
	 *
	 * @return int
	 */
	public static function get_log_file_size() {
		if ( self::$log_file && file_exists( self::$log_file ) ) {
			return filesize( self::$log_file );
		}
		return 0;
	}

	/**
	 * Format bytes to human readable format
	 *
	 * @param int $bytes Bytes to format
	 * @return string
	 */
	private static function format_bytes( $bytes ) {
		$units = [ 'B', 'KB', 'MB', 'GB' ];
		$bytes = max( $bytes, 0 );
		$pow = floor( ( $bytes ? log( $bytes ) : 0 ) / log( 1024 ) );
		$pow = min( $pow, count( $units ) - 1 );

		$bytes /= pow( 1024, $pow );

		return round( $bytes, 2 ) . ' ' . $units[ $pow ];
	}

	/**
	 * Parse memory limit string to bytes
	 *
	 * @param string $memory_limit Memory limit string
	 * @return int
	 */
	private static function parse_memory_limit( $memory_limit ) {
		$unit = strtolower( substr( $memory_limit, -1 ) );
		$value = (int) substr( $memory_limit, 0, -1 );

		switch ( $unit ) {
			case 'k':
				return $value * 1024;
			case 'm':
				return $value * 1024 * 1024;
			case 'g':
				return $value * 1024 * 1024 * 1024;
			default:
				return $value;
		}
	}
}
