<?php
/**
 * Shipment Tracking Admin Class
 *
 * @package Ongoing\ShipmentTracking
 */

namespace Ongoing\ShipmentTracking;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ShipmentTrackingAdmin class
 */
class ShipmentTrackingAdmin {

	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'add_meta_boxes', [ $this, 'add_tracking_meta_box' ] );
		add_action( 'manage_shop_order_posts_custom_column', [ $this, 'add_tracking_column_content' ], 20, 2 );
		add_filter( 'manage_edit-shop_order_columns', [ $this, 'add_tracking_column' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_scripts' ] );
		add_action( 'wp_ajax_ongoing_admin_update_tracking', [ $this, 'ajax_admin_update_tracking' ] );
		
		// Add tracking link to order data panel
		add_action( 'woocommerce_admin_order_data_after_shipping_address', [ $this, 'add_tracking_link_to_order_data' ], 10, 1 );
		
		// Add WooCommerce settings to shipping tab
		add_filter( 'woocommerce_get_sections_shipping', [ $this, 'add_shipping_section' ] );
		add_filter( 'woocommerce_get_settings_shipping', [ $this, 'add_settings' ], 10, 2 );
		add_action( 'woocommerce_settings_saved', [ $this, 'save_settings' ] );
		
		// Add custom field type for order statuses fieldset
		add_action( 'woocommerce_admin_field_order_statuses_fieldset', [ $this, 'render_order_statuses_fieldset' ] );
	}

	/**
	 * Add tracking meta box to order edit page
	 */
	public function add_tracking_meta_box() {
		add_meta_box(
			'ongoing_shipment_tracking',
			__( 'Shipment Tracking', 'directhouse-ongoing-parcel-tracking' ),
			[ $this, 'render_tracking_meta_box' ],
			'shop_order',
			'side',
			'default'
		);
	}

	/**
	 * Render tracking meta box content
	 *
	 * @param object $post Post object
	 */
	public function render_tracking_meta_box( $post ) {
		$order = wc_get_order( $post->ID );
		
		if ( ! $order ) {
			echo '<p>' . esc_html__( 'Invalid order.', 'directhouse-ongoing-parcel-tracking' ) . '</p>';
			return;
		}

		$tracking_number = $order->get_meta( 'ongoing_tracking_number' );
		$tracking_data = $order->get_meta( '_ongoing_tracking_data' );
		$last_updated = $order->get_meta( '_ongoing_tracking_updated' );

		echo '<div id="ongoing_shipment_tracking">';

		if ( empty( $tracking_number ) ) {
			echo '<p>' . esc_html__( 'No tracking number available for this order.', 'directhouse-ongoing-parcel-tracking' ) . '</p>';
		} else {
			echo '<p><strong>' . esc_html__( 'Tracking Number:', 'directhouse-ongoing-parcel-tracking' ) . '</strong> ' . esc_html( $tracking_number ) . '</p>';
		}

		// Tracking number field
		echo '<p><strong>' . esc_html__( 'Update Tracking Number:', 'directhouse-ongoing-parcel-tracking' ) . '</strong></p>';
		echo '<input type="text" id="ongoing_tracking_number" name="ongoing_tracking_number" value="' . esc_attr( $tracking_number ) . '" class="widefat" />';
		echo '<p class="description">' . esc_html__( 'Enter the tracking number from the warehouse system.', 'directhouse-ongoing-parcel-tracking' ) . '</p>';

		// Update button
		echo '<p><button type="button" id="update_tracking" class="button button-primary" data-order-id="' . esc_attr( $order->get_id() ) . '">';
		echo esc_html__( 'Update Tracking', 'directhouse-ongoing-parcel-tracking' );
		echo '</button></p>';

		// Last updated info
		if ( $last_updated ) {
			echo '<p><small>' . esc_html__( 'Last updated:', 'directhouse-ongoing-parcel-tracking' ) . ' ' . esc_html( $last_updated ) . '</small></p>';
		}

		// Display tracking information
		if ( $tracking_data && ! empty( $tracking_data['events'] ) ) {
			echo '<div class="tracking-info">';
			echo '<h4>' . esc_html__( 'All Tracking Events', 'directhouse-ongoing-parcel-tracking' ) . '</h4>';
			
			// Sort events by timestamp (newest first for admin display)
			$events = $tracking_data['events'];
			usort( $events, function( $a, $b ) {
				return $b['timestamp'] - $a['timestamp'];
			} );
			
						foreach ( $events as $event ) {
				$status = $event['transporter_status'] ?? 'unknown';
				$status_labels = [
					'DELIVERED' => __( 'Delivered', 'directhouse-ongoing-parcel-tracking' ),
					'AVAILABLE_FOR_DELIVERY' => __( 'Available for pickup', 'directhouse-ongoing-parcel-tracking' ),
					'EN_ROUTE' => __( 'In transit', 'directhouse-ongoing-parcel-tracking' ),
					'waiting_to_be_picked' => __( 'Waiting to be picked', 'directhouse-ongoing-parcel-tracking' ),
					'picking' => __( 'Being picked', 'directhouse-ongoing-parcel-tracking' ),
					'OTHER' => __( 'Other', 'directhouse-ongoing-parcel-tracking' ),
					'unknown' => __( 'Unknown', 'directhouse-ongoing-parcel-tracking' ),
				];
				
				$status_text = $status_labels[ $status ] ?? $status;
				$status_class = 'admin-status-' . strtolower( $status );
				
				// Format date for admin display
				$formatted_date = $this->format_date_for_admin_display( $event['date'] );
				
				echo '<div class="tracking-event">';
				echo '<div class="event-header">';
				echo '<strong>' . esc_html( $formatted_date ) . '</strong>';
				echo '<span class="event-status ' . esc_attr( $status_class ) . '">' . esc_html( $status_text ) . '</span>';
				echo '</div>';
				echo '<div class="event-description">' . esc_html( $this->translate_event_description( $event['description'] ) ) . '</div>';
				if ( ! empty( $event['location'] ) ) {
					echo '<div class="event-location"><small>' . esc_html( $event['location'] ) . '</small></div>';
				}
				echo '</div>';
			}
			
			echo '</div>';
		} else {
			echo '<p>' . esc_html__( 'No tracking information available.', 'directhouse-ongoing-parcel-tracking' ) . '</p>';
		}

		// Add nonce for AJAX
		wp_nonce_field( 'ongoing_shipment_tracking_nonce', 'ongoing_tracking_nonce' );
		
		echo '</div>'; // Close the main container
	}

	/**
	 * Add tracking column to orders list
	 *
	 * @param array $columns Existing columns
	 * @return array Modified columns
	 */
	public function add_tracking_column( $columns ) {
		$new_columns = [];
		
		foreach ( $columns as $key => $column ) {
			$new_columns[ $key ] = $column;
			
			// Add tracking column after order status
			if ( $key === 'order_status' ) {
				$new_columns['tracking_status'] = __( 'Tracking', 'directhouse-ongoing-parcel-tracking' );
			}
		}
		
		return $new_columns;
	}

	/**
	 * Add content to tracking column
	 *
	 * @param string $column Column name
	 * @param int    $post_id Post ID
	 */
	public function add_tracking_column_content( $column, $post_id ) {
		if ( $column !== 'tracking_status' ) {
			return;
		}

		$order = wc_get_order( $post_id );
		
		if ( ! $order ) {
			return;
		}

		$tracking_number = $order->get_meta( 'ongoing_tracking_number' );
		$tracking_data = $order->get_meta( '_ongoing_tracking_data' );

		if ( empty( $tracking_number ) ) {
			echo '<span class="tracking-status no-tracking">' . esc_html__( 'No tracking', 'directhouse-ongoing-parcel-tracking' ) . '</span>';
			return;
		}

		// Get shipping method ID for tracking link
		$tracking = new ShipmentTracking();
		$shipping_method_info = $tracking->get_shipping_method_info( $post_id );
		$api = new ShipmentTrackingAPI();
		$tracking_link = $api->get_tracking_link( 
			$tracking_number, 
			$shipping_method_info['method_id'],
			$shipping_method_info['method_title'],
			$shipping_method_info['shipping_method']
		);

		if ( $tracking_data && ! empty( $tracking_data['events'] ) ) {
			$api = new ShipmentTrackingAPI();
			$latest_status = $api->get_latest_status( $tracking_data['events'] );
			
			$status_labels = [
				'DELIVERED' => __( 'Delivered', 'directhouse-ongoing-parcel-tracking' ),
				'AVAILABLE_FOR_DELIVERY' => __( 'Available for pickup', 'directhouse-ongoing-parcel-tracking' ),
				'EN_ROUTE' => __( 'In transit', 'directhouse-ongoing-parcel-tracking' ),
				'waiting_to_be_picked' => __( 'Waiting to be picked', 'directhouse-ongoing-parcel-tracking' ),
				'picking' => __( 'Being picked', 'directhouse-ongoing-parcel-tracking' ),
				'OTHER' => __( 'Other', 'directhouse-ongoing-parcel-tracking' ),
				'unknown' => __( 'Unknown', 'directhouse-ongoing-parcel-tracking' ),
			];

			$status_class = 'tracking-status ' . strtolower( $latest_status );
			$status_text = $status_labels[ $latest_status ] ?? $status_labels['unknown'];
			
			echo '<div class="tracking-column-content">';
			echo '<span class="' . esc_attr( $status_class ) . '">' . esc_html( $status_text ) . '</span>';
			
			// Add tracking link if available
			if ( $tracking_link ) {
				echo '<br><small><a href="' . esc_url( $tracking_link ) . '" target="_blank" class="tracking-link-small">' . esc_html__( 'Track', 'directhouse-ongoing-parcel-tracking' ) . '</a></small>';
			}
			echo '</div>';
		} else {
			echo '<div class="tracking-column-content">';
			echo '<span class="tracking-status no-data">' . esc_html__( 'No data', 'directhouse-ongoing-parcel-tracking' ) . '</span>';
			
			// Add tracking link if available (even without tracking data)
			if ( $tracking_link ) {
				echo '<br><small><a href="' . esc_url( $tracking_link ) . '" target="_blank" class="tracking-link-small">' . esc_html__( 'Track', 'directhouse-ongoing-parcel-tracking' ) . '</a></small>';
			}
			echo '</div>';
		}
	}

	/**
	 * Enqueue admin scripts
	 *
	 * @param string $hook Current admin page
	 */
	public function enqueue_admin_scripts( $hook ) {
		if ( ! in_array( $hook, [ 'post.php', 'edit.php' ] ) ) {
			return;
		}

		$screen = get_current_screen();
		
		if ( $screen && $screen->post_type === 'shop_order' ) {
			wp_enqueue_script(
				'directhouse-ongoing-parcel-tracking-admin',
				PLUGIN_URL . 'assets/js/admin.js',
				[ 'jquery' ],
				PLUGIN_VERSION,
				true
			);

			wp_localize_script(
				'directhouse-ongoing-parcel-tracking-admin',
				'ongoingTrackingAdmin',
				[
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce' => wp_create_nonce( 'ongoing_shipment_tracking_nonce' ),
					'strings' => [
						'updating' => __( 'Updating...', 'directhouse-ongoing-parcel-tracking' ),
						'success' => __( 'Tracking updated successfully!', 'directhouse-ongoing-parcel-tracking' ),
						'error' => __( 'Error updating tracking.', 'directhouse-ongoing-parcel-tracking' ),
					],
				]
			);

			wp_enqueue_style(
				'directhouse-ongoing-parcel-tracking-admin',
				PLUGIN_URL . 'assets/css/admin.css',
				[],
				PLUGIN_VERSION
			);
		}
	}

	/**
	 * AJAX handler for admin tracking update
	 */
	public function ajax_admin_update_tracking() {
		// Verify nonce
		if ( ! wp_verify_nonce( $_POST['nonce'] ?? '', 'ongoing_shipment_tracking_nonce' ) ) {
			wp_die( 'Security check failed' );
		}

		// Check permissions
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Access denied' );
		}

		$order_id = intval( $_POST['order_id'] ?? 0 );
		
		if ( ! $order_id ) {
			wp_send_json_error( 'Invalid order ID' );
		}

		$order = wc_get_order( $order_id );
		
		if ( ! $order ) {
			wp_send_json_error( 'Order not found' );
		}

		// Update tracking number if provided
		$tracking_number = sanitize_text_field( $_POST['tracking_number'] ?? '' );
		if ( ! empty( $tracking_number ) ) {
			$order->update_meta_data( 'ongoing_tracking_number', $tracking_number );
		}

		// Get tracking data
		$api = new ShipmentTrackingAPI();
		$tracking_data = $api->get_tracking_data( $tracking_number );
		
		if ( is_wp_error( $tracking_data ) ) {
			wp_send_json_error( $tracking_data->get_error_message() );
		}

		// Store the tracking data
		$order->update_meta_data( '_ongoing_tracking_data', $tracking_data );
		$order->update_meta_data( '_ongoing_tracking_updated', current_time( 'mysql' ) );
		
		// Sync current status to separate meta field
		if ( ! empty( $tracking_data['events'] ) ) {
			$current_status = $api->get_latest_status( $tracking_data['events'] );
			$order->update_meta_data( '_ongoing_tracking_status', $current_status );
		}
		
		$order->save();

		wp_send_json_success( $tracking_data );
	}

	/**
	 * Add tracking link to order data panel
	 *
	 * @param WC_Order $order The order object.
	 */
	public function add_tracking_link_to_order_data( $order ) {
		$tracking_number = $order->get_meta( 'ongoing_tracking_number' );
		
		if ( empty( $tracking_number ) ) {
			return;
		}

		// Get shipping method info for tracking link
		$tracking = new ShipmentTracking();
		$shipping_method_info = $tracking->get_shipping_method_info( $order->get_id() );
		$api = new ShipmentTrackingAPI();
		$tracking_link = $api->get_tracking_link( 
			$tracking_number, 
			$shipping_method_info['method_id'],
			$shipping_method_info['method_title'],
			$shipping_method_info['shipping_method']
		);

		if ( $tracking_link ) {
			echo '<p class="form-field form-field-wide">';
			echo '<strong>' . esc_html__( 'Tracking Link:', 'directhouse-ongoing-parcel-tracking' ) . '</strong><br>';
			echo '<a href="' . esc_url( $tracking_link ) . '" target="_blank" class="button button-secondary">';
			echo esc_html__( 'View on carrier website', 'directhouse-ongoing-parcel-tracking' );
			echo '</a>';
			echo '</p>';
		}
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
	 * Format date for admin display
	 *
	 * @param string $date_string Date string
	 * @return string Formatted date
	 */
	private function format_date_for_admin_display( $date_string ) {
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
	 * Add shipping section to WooCommerce shipping settings
	 *
	 * @param array $sections Array of shipping sections
	 * @return array Modified sections array
	 */
	public function add_shipping_section( $sections ) {
		$sections['directhouse-ongoing-parcel-tracking'] = __( 'DirectHouse Ongoing Parcel Tracking', 'directhouse-ongoing-parcel-tracking' );
		return $sections;
	}

	/**
	 * Add settings to the shipping section
	 *
	 * @param array  $settings Array of settings
	 * @param string $current_section Current section
	 * @return array Modified settings array
	 */
	public function add_settings( $settings, $current_section ) {
		// Only return settings if we're in our section
		if ( 'directhouse-ongoing-parcel-tracking' !== $current_section ) {
			return $settings;
		}

		$ongoing_settings = [
			[
				'name' => __( 'DirectHouse Ongoing Parcel Tracking', 'directhouse-ongoing-parcel-tracking' ),
				'type' => 'title',
				'desc' => __( 'Configure automatic tracking updates and order status filtering.', 'directhouse-ongoing-parcel-tracking' ),
				'id'   => 'ongoing_shipment_tracking_section_title'
			],
			[
				'name' => __( 'Enable Cron Updates', 'directhouse-ongoing-parcel-tracking' ),
				'type' => 'checkbox',
				'desc' => __( 'Enable automatic tracking updates via cron job', 'directhouse-ongoing-parcel-tracking' ),
				'id'   => 'ongoing_shipment_tracking_enable_cron',
				'default' => 'yes'
			],
			[
				'name' => __( 'Update Interval', 'directhouse-ongoing-parcel-tracking' ),
				'type' => 'select',
				'desc' => __( 'How often to update tracking information', 'directhouse-ongoing-parcel-tracking' ),
				'id'   => 'ongoing_shipment_tracking_cron_interval',
				'options' => [
					'every_15_minutes' => __( 'Every 15 Minutes', 'directhouse-ongoing-parcel-tracking' ),
					'every_30_minutes' => __( 'Every 30 Minutes', 'directhouse-ongoing-parcel-tracking' ),
					'every_45_minutes' => __( 'Every 45 Minutes', 'directhouse-ongoing-parcel-tracking' ),
					'hourly' => __( 'Hourly', 'directhouse-ongoing-parcel-tracking' ),
					'every_2_hours' => __( 'Every 2 Hours', 'directhouse-ongoing-parcel-tracking' ),
					'every_3_hours' => __( 'Every 3 Hours', 'directhouse-ongoing-parcel-tracking' ),
					'every_4_hours' => __( 'Every 4 Hours', 'directhouse-ongoing-parcel-tracking' ),
					'every_6_hours' => __( 'Every 6 Hours', 'directhouse-ongoing-parcel-tracking' ),
					'every_8_hours' => __( 'Every 8 Hours', 'directhouse-ongoing-parcel-tracking' ),
					'every_12_hours' => __( 'Every 12 Hours', 'directhouse-ongoing-parcel-tracking' ),
					'twicedaily' => __( 'Twice Daily', 'directhouse-ongoing-parcel-tracking' ),
					'daily' => __( 'Daily', 'directhouse-ongoing-parcel-tracking' ),
					'weekly' => __( 'Weekly', 'directhouse-ongoing-parcel-tracking' ),
				],
				'default' => 'hourly'
			],
			[
				'name' => __( 'Exclude Delivered Orders', 'directhouse-ongoing-parcel-tracking' ),
				'type' => 'checkbox',
				'desc' => __( 'Skip orders that are already marked as delivered', 'directhouse-ongoing-parcel-tracking' ),
				'id'   => 'ongoing_shipment_tracking_exclude_delivered',
				'default' => 'yes'
			],
			[
				'name' => __( 'Order Status Settings', 'directhouse-ongoing-parcel-tracking' ),
				'type' => 'title',
				'desc' => __( 'Select which order statuses should be automatically updated.', 'directhouse-ongoing-parcel-tracking' ),
				'id'   => 'ongoing_shipment_tracking_status_title'
			],
			[
				'name' => __( 'Order Statuses', 'directhouse-ongoing-parcel-tracking' ),
				'type' => 'order_statuses_fieldset',
				'desc' => __( 'Select which order statuses should be automatically updated.', 'directhouse-ongoing-parcel-tracking' ),
				'id'   => 'ongoing_shipment_tracking_order_statuses',
			],
		];

		$ongoing_settings[] = [
			'type' => 'sectionend',
			'id' => 'ongoing_shipment_tracking_section_end'
		];

		return apply_filters( 'ongoing_shipment_tracking_settings', $ongoing_settings );
	}

	/**
	 * Render order statuses fieldset
	 *
	 * @param array $value Field value
	 */
	public function render_order_statuses_fieldset( $value ) {
		$order_statuses = wc_get_order_statuses();
		$field_id = $value['id'];
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label for="<?php echo esc_attr( $field_id ); ?>"><?php echo esc_html( $value['name'] ); ?></label>
			</th>
			<td class="forminp forminp-<?php echo esc_attr( sanitize_title( $value['type'] ) ); ?>">
				<?php foreach ( $order_statuses as $status_key => $status_label ) : ?>
					<?php
					$option_id = 'ongoing_shipment_tracking_status_' . $status_key;
					$option_value = get_option( $option_id, in_array( $status_key, [ 'wc-processing', 'wc-completed' ] ) ? 'yes' : 'no' );
					?>
					<fieldset>
						<legend class="screen-reader-text"><span><?php echo esc_html( $status_label ); ?></span></legend>
						<label for="<?php echo esc_attr( $option_id ); ?>">
							<input
								name="<?php echo esc_attr( $option_id ); ?>"
								id="<?php echo esc_attr( $option_id ); ?>"
								type="checkbox"
								value="yes"
								<?php checked( $option_value, 'yes' ); ?>
							/>
							<?php echo esc_html( $status_label ); ?>
						</label>
					</fieldset>
				<?php endforeach; ?>
				<?php if ( ! empty( $value['desc'] ) ) : ?>
					<p class="description"><?php echo esc_html( $value['desc'] ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Save settings and reschedule cron
	 */
	public function save_settings() {
		$section = filter_input( INPUT_GET, 'section', FILTER_SANITIZE_STRING );
		if ( 'directhouse-ongoing-parcel-tracking' === $section ) {
			// Reschedule cron job with new settings
			$cron = new ShipmentTrackingCron();
			$cron->reschedule();
		}
	}
} 