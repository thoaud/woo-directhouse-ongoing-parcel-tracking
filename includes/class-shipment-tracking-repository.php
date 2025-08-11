<?php
/**
 * Shipment Tracking Repository
 *
 * Responsible for persisting tracking data in a dedicated table
 * {db_prefix}dh_ongoing_tracking_data to avoid frequent order meta writes.
 *
 * @package Ongoing\ShipmentTracking
 */

namespace Ongoing\ShipmentTracking;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ShipmentTrackingRepository {

    /**
     * Option name that stores the DB schema version
     */
    private const DB_VERSION_OPTION = 'ongoing_shipment_tracking_db_version';

    /**
     * Current DB schema version
     */
    private const DB_VERSION = '1.0.1';

    /**
     * Get table name with current blog prefix
     */
    public static function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'dh_ongoing_tracking_data';
    }

    /**
     * Create or update the table for current blog
     */
    public static function create_table_for_blog(): void {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $table = self::table_name();

        // Use LONGTEXT for JSON payload; store denormalized latest status for quick access
        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            site_id BIGINT UNSIGNED NULL,
            order_id BIGINT UNSIGNED NOT NULL,
            tracking_number VARCHAR(190) NULL,
            data_json LONGTEXT NULL,
            latest_status VARCHAR(64) NULL,
            last_updated DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY order_id_unique (order_id),
            KEY latest_status_idx (latest_status),
            KEY tracking_number_idx (tracking_number)
        ) {$charset_collate};";

        dbDelta( $sql );

        // Store/upgrade db version
        update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
    }

    /**
     * Ensure the table exists and is up to date for current blog
     */
    public static function ensure_installed(): void {
        $current = get_option( self::DB_VERSION_OPTION );
        if ( $current !== self::DB_VERSION ) {
            self::create_table_for_blog();
        }
    }

    /**
     * Force ensure the table structure is correct (called during plugin init)
     */
    public static function force_ensure_table_structure(): void {
        global $wpdb;
        
        // Check if table exists
        $table = self::table_name();
        $table_exists = $wpdb->get_var( $wpdb->prepare( 
            "SHOW TABLES LIKE %s", 
            $table 
        ) );
        
        if ( ! $table_exists ) {
            // Table doesn't exist, create it
            self::create_table_for_blog();
            return;
        }
        
        // Table exists, check if it has the correct structure
        $current_version = get_option( self::DB_VERSION_OPTION );
        if ( $current_version !== self::DB_VERSION ) {
            // Version mismatch, recreate table
            self::create_table_for_blog();
            return;
        }
        
        // Additional safety check: verify key columns exist
        $columns = $wpdb->get_results( "DESCRIBE {$table}" );
        $required_columns = [
            'id' => 'BIGINT UNSIGNED',
            'site_id' => 'BIGINT UNSIGNED',
            'order_id' => 'BIGINT UNSIGNED',
            'tracking_number' => 'VARCHAR(190)',
            'data_json' => 'LONGTEXT',
            'latest_status' => 'VARCHAR(64)',
            'last_updated' => 'DATETIME',
            'created_at' => 'DATETIME',
            'updated_at' => 'DATETIME'
        ];
        
        $existing_columns = [];
        foreach ( $columns as $column ) {
            $existing_columns[ $column->Field ] = $column->Type;
        }
        
        $missing_columns = array_diff_key( $required_columns, $existing_columns );
        
        if ( ! empty( $missing_columns ) ) {
            // Missing required columns, recreate table
            self::create_table_for_blog();
            return;
        }
        
        // Check for required indexes
        $indexes = $wpdb->get_results( "SHOW INDEX FROM {$table}" );
        $required_indexes = [ 'order_id_unique', 'latest_status_idx', 'tracking_number_idx' ];
        $existing_indexes = [];
        
        foreach ( $indexes as $index ) {
            $existing_indexes[] = $index->Key_name;
        }
        
        $missing_indexes = array_diff( $required_indexes, $existing_indexes );
        
        if ( ! empty( $missing_indexes ) ) {
            // Missing required indexes, recreate table
            self::create_table_for_blog();
            return;
        }
    }

    /**
     * Create tables network-wide when network activating
     */
    public static function create_table_network(): void {
        if ( ! is_multisite() ) {
            self::create_table_for_blog();
            return;
        }

        $sites = get_sites( [ 'fields' => 'ids' ] );
        foreach ( $sites as $site_id ) {
            switch_to_blog( (int) $site_id );
            self::create_table_for_blog();
            restore_current_blog();
        }
    }

    /**
     * Drop table for current blog
     */
    public static function drop_table_for_blog(): void {
        global $wpdb;
        $table = self::table_name();
        $wpdb->query( "DROP TABLE IF EXISTS {$table}" );
    }

    /**
     * Drop tables network-wide
     */
    public static function drop_table_network(): void {
        if ( ! is_multisite() ) {
            self::drop_table_for_blog();
            return;
        }
        $sites = get_sites( [ 'fields' => 'ids' ] );
        foreach ( $sites as $site_id ) {
            switch_to_blog( (int) $site_id );
            self::drop_table_for_blog();
            restore_current_blog();
        }
    }

    /**
     * Upsert tracking data for an order
     *
     * @param int    $order_id
     * @param string $tracking_number
     * @param array  $tracking_data  Full associative array (events, raw_data, last_updated)
     * @param string $latest_status
     *
     * @return bool True on success
     */
    public function upsert_order_tracking( int $order_id, string $tracking_number, array $tracking_data, string $latest_status = '' ): bool {
        global $wpdb;

        $table = self::table_name();
        $site_id = is_multisite() ? get_current_blog_id() : null;
        $data_json = wp_json_encode( $tracking_data );
        $last_updated = $tracking_data['last_updated'] ?? current_time( 'mysql' );

        // Prefer INSERT ... ON DUPLICATE KEY UPDATE for performance
        $sql = $wpdb->prepare(
            "INSERT INTO {$table} (site_id, order_id, tracking_number, data_json, latest_status, last_updated, created_at, updated_at)
             VALUES (%s, %d, %s, %s, %s, %s, %s, %s)
             ON DUPLICATE KEY UPDATE
               tracking_number = VALUES(tracking_number),
               data_json = VALUES(data_json),
               latest_status = VALUES(latest_status),
               last_updated = VALUES(last_updated),
               updated_at = VALUES(updated_at)",
            $site_id,
            $order_id,
            $tracking_number,
            $data_json,
            $latest_status,
            $last_updated,
            current_time( 'mysql' ),
            current_time( 'mysql' )
        );

        $result = $wpdb->query( $sql );
        return $result !== false;
    }

    /**
     * Get tracking data by order id
     *
     * @param int $order_id
     * @return array|false Full tracking data array or false if not found
     */
    public function get_tracking_by_order_id( int $order_id ) {
        global $wpdb;
        $table = self::table_name();
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT data_json FROM {$table} WHERE order_id = %d", $order_id ), ARRAY_A );
        if ( ! $row || empty( $row['data_json'] ) ) {
            return false;
        }
        $data = json_decode( $row['data_json'], true );
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            return false;
        }
        return $data;
    }

    /**
     * Get latest status by order id (fast path)
     */
    public function get_latest_status_by_order_id( int $order_id ) {
        global $wpdb;
        $table = self::table_name();
        $status = $wpdb->get_var( $wpdb->prepare( "SELECT latest_status FROM {$table} WHERE order_id = %d", $order_id ) );
        return $status ?: false;
    }

    /**
     * Get orders that have tracking numbers but no tracking data row yet
     *
     * @param array $enabled_statuses Clean WooCommerce statuses without wc- prefix
     * @param int   $max_updates      Limit
     * @param bool  $exclude_delivered Whether to exclude orders marked delivered via meta
     * @return array<int> Order IDs
     */
    public function get_unfetched_orders( array $enabled_statuses, int $max_updates, bool $exclude_delivered = true ): array {
        global $wpdb;

        if ( empty( $enabled_statuses ) ) {
            $enabled_statuses = [ 'processing', 'completed' ];
        }

        // Build statuses WHERE clause
        $status_placeholders = implode( ',', array_fill( 0, count( $enabled_statuses ), '%s' ) );
        $status_values = array_map( function( $s ) { return 'wc-' . $s; }, $enabled_statuses );

        $table = self::table_name();

        $sql = "SELECT DISTINCT p.ID
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->postmeta} pm_tracking ON p.ID = pm_tracking.post_id
                  AND pm_tracking.meta_key = 'ongoing_tracking_number' AND pm_tracking.meta_value != ''
                LEFT JOIN {$table} t ON t.order_id = p.ID
                WHERE p.post_type = 'shop_order'
                  AND p.post_status IN ($status_placeholders)
                  AND t.order_id IS NULL";

        $params = $status_values;

        if ( $exclude_delivered ) {
            $sql .= " AND NOT EXISTS (
                        SELECT 1 FROM {$wpdb->postmeta} pm_status
                         WHERE pm_status.post_id = p.ID
                           AND pm_status.meta_key = '_ongoing_tracking_status'
                           AND pm_status.meta_value = 'DELIVERED'
                      )";
        }

        $sql .= ' ORDER BY p.ID ASC LIMIT %d';
        $params[] = $max_updates;

        $prepared = $wpdb->prepare( $sql, $params );
        $ids = $wpdb->get_col( $prepared );
        return array_map( 'intval', $ids );
    }

    /**
     * Delete tracking data for an order (cleanup)
     */
    public function delete_by_order_id( int $order_id ): bool {
        global $wpdb;
        $table = self::table_name();
        $deleted = $wpdb->delete( $table, [ 'order_id' => $order_id ], [ '%d' ] );
        return $deleted !== false;
    }
}


