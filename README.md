# DirectHouse Ongoing Parcel Tracking

A WordPress plugin for WooCommerce that integrates with the DirectHouse Ongoing warehouse API to provide real-time shipment tracking information.

## Features

- **Real-time Tracking**: Fetches tracking data from the DirectHouse Ongoing warehouse API
- **Flexible Updates**: Configurable cron intervals (15 minutes to weekly)
- **Customer Interface**: Beautiful tracking timeline displayed in customer account pages
- **Admin Interface**: Easy-to-use tracking management in WooCommerce admin
- **Order Status Control**: Individual control over which order statuses to update
- **Carrier Integration**: Automatic tracking links for PostNord, Instabox, Bring, Posten, and HeltHjem
- **WP CLI Support**: Command-line interface for manual updates and status checks
- **Responsive Design**: Works perfectly on desktop and mobile devices
- **UTC-Accurate Dates**: Source timestamps with offsets are normalized to UTC and then shown in the site timezone
- **Parallel Updates**: Optional parallel processing with deferred retries for better throughput
- **Status Emojis (optional)**: Toggle to show emojis alongside statuses in timelines and tables

## Requirements

- WordPress 5.0 or higher
- WooCommerce 5.0 or higher
- PHP 7.4 or higher

## Installation

1. Upload the `directhouse-ongoing-parcel-tracking` folder to your `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. The plugin will automatically schedule tracking updates based on your configuration

## Configuration

### Setting Tracking Numbers

Tracking numbers are stored as order meta with the key `ongoing_tracking_number`. You can set these:

1. **Via Admin**: Go to any order in WooCommerce admin and use the "Shipment Tracking" meta box
2. **Via API**: The tracking number can be set programmatically by your warehouse system
3. **Via Database**: Directly update the `wp_postmeta` table (not recommended)

### API Configuration

The plugin uses the DirectHouse Ongoing warehouse API endpoint:
```
https://warehouse.directhouse.no/api/fullOrderTracking/{tracking_number}
```

You can customize the API base URL using the filter:
```php
add_filter('directhouse_ongoing_parcel_tracking_api_base_url', function($url) {
    return 'https://your-custom-api.com/api/';
});
```

### Settings Configuration

The plugin settings are located at:
**WooCommerce → Settings → Shipping → DirectHouse Ongoing Parcel Tracking**

#### Available Settings:
- **Enable Cron Updates**: Master toggle for automatic updates
- **Update Interval**: Choose from 15 minutes to weekly
- **Exclude Delivered Orders**: Skip orders already marked as delivered
- **Order Status Control**: Individual checkboxes for each order status
- **Enable Tracking Column**: Adds a status column to the WooCommerce orders list in admin
- **Enable Status Emojis**: Adds emojis to status badges in admin/customer views and order tables

## Usage

### For Customers

1. Customers can view tracking information on their order details page in "My Account"
2. A "Refresh Tracking Information" button allows manual updates
3. Tracking data automatically updates when the page loads (if data is older than 2 hours)
4. The order timeline hides events after the last "DELIVERED" event to avoid noise

### For Administrators

1. **Order View**: Each order has a "Shipment Tracking" meta box with tracking information
2. **Order List**: A "Tracking" column shows the current status of all orders
3. **Manual Updates**: Click "Update Tracking" to fetch the latest information
4. **Bulk Operations**: The cron job automatically updates orders based on your settings
5. **Settings Management**: Configure update intervals and order status filters
6. **Carrier Links**: Direct links to carrier tracking websites

### For Developers

#### Hooks and Filters

```php
// Customize API base URL
add_filter('ongoing_shipment_tracking_api_base_url', function($url) {
    return 'https://your-api.com/';
});

// You can also translate status texts via standard WordPress translation tools.
```

#### Programmatic Usage

```php
// Get tracking data for an order
$tracking = new \Ongoing\ShipmentTracking\ShipmentTracking();
$tracking_data = $tracking->get_tracking_data($order_id);

// Update tracking for an order
$result = $tracking->update_order_tracking($order_id);

// Get API instance
$api = $tracking->get_api();
$tracking_data = $api->get_tracking_data($tracking_number);
```

#### WP CLI Commands

```bash
# Check plugin status and settings
wp directhouse-tracking status

# Update tracking for all eligible orders
wp directhouse-tracking update

# Force update (bypass disabled cron setting)
wp directhouse-tracking update --force

# Update specific order statuses
wp directhouse-tracking update --statuses=processing,completed

# Include delivered orders in update
wp directhouse-tracking update --include-delivered

# Parallel processing with limit and optimized queries
wp directhouse-tracking update --parallel --limit=100 --fast-query
```

## API Response Format

The plugin expects the API to return JSON in this format:

```json
{
    "events": [
        {
            "date": "2025-07-06T00:00:00+02:00",
            "eventdescription": "Order has been placed in the warehouse",
            "location": "Arendal, Norway",
            "type": "Warehouse",
            "transporter_status": null,
            "timestamp": 1751752800
        }
    ]
}
```

### Status Types

- `DELIVERED`: Order has been delivered
- `AVAILABLE_FOR_DELIVERY`: Order is available for pickup
- `EN_ROUTE`: Order is in transit
- `sent`: Order left the warehouse and is en route to terminal (inferred from warehouse events)
- `waiting_to_be_picked`: Order is waiting to be picked in warehouse
- `picking`: Order is being picked in warehouse
- `OTHER`: Other status (notifications, etc.)
- `null`: Warehouse events

### Supported Carriers

The plugin automatically generates tracking links for:
- **PostNord**: `https://tracking.postnord.com/{lang}/tracking?id={number}`
- **Instabox**: `https://track.instabox.io/{number}`
- **Bring Norge**: `https://sporing.bring.no/sporing/{number}`
- **Posten Norge**: `https://sporing.posten.no/sporing/{number}`
- **HeltHjem**: `https://helthjem.no/sporing/{number}`

## Cron Jobs

The plugin automatically schedules cron jobs based on your configuration. Available intervals:

- **Every 15 Minutes**: For high-priority tracking needs
- **Every 30 Minutes**: For frequent updates
- **Hourly**: Default setting
- **Every 2-12 Hours**: For balanced performance
- **Twice Daily**: For less critical tracking
- **Daily/Weekly**: For resource-conscious setups

You can:
- **Configure Intervals**: Use the admin settings to choose update frequency
- **Check Status**: Use WP-Cron or server cron to ensure jobs run
- **Manual Trigger**: Use the admin interface or WP CLI to manually update tracking
- **Order Status Control**: Choose which order statuses to update automatically

## Troubleshooting

### Common Issues

1. **No Tracking Data**: Ensure the order has a tracking number set
2. **API Errors**: Check the API endpoint and network connectivity
3. **Cron Not Running**: Verify WordPress cron is working or use server cron
4. **Styling Issues**: Check for theme conflicts with CSS
5. **Too Many Orders**: The plugin now properly filters orders with tracking numbers
6. **Settings Not Saving**: Ensure you're in the correct settings section

### Debug Mode

Enable WordPress debug mode to see detailed error messages:

```php
// In wp-config.php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

### Logs

The plugin logs errors to the WordPress error log. Check for entries starting with "DirectHouse Ongoing Parcel Tracking".

### WP CLI Debugging

Use the WP CLI commands to debug issues:

```bash
# Check current settings and cron status
wp directhouse-tracking status

# Test tracking updates with verbose output
wp directhouse-tracking update --verbose

# Example sequence used to test batch behavior
wp directhouse-tracking cleanup && \
wp directhouse-tracking update --parallel --limit=100
```

## Changelog

### Recent Changes
- Added optional status emojis for admin/customer timelines and order tables (setting: "Enable Status Emojis")
- Introduced inferred status `sent` for events indicating the parcel left the warehouse/is transported to terminal
- Normalized all event dates to UTC; display uses the site’s timezone. Events are stored sorted by UTC time
- My Account timeline now hides events that occur after the last DELIVERED event
- WP-CLI: parallel mode appends retries to the end of the queue; batch size no longer increases automatically; added `--fast-query`
- Fixed duplicate tracking link rendering in the admin order view

### Version 1.0.0
- Initial release
- Real-time tracking integration with DirectHouse Ongoing API
- Customer and admin interfaces
- Flexible cron intervals (15 minutes to weekly)
- Individual order status control
- Carrier integration (PostNord, Instabox, Bring, Posten, HeltHjem)
- WP CLI support for manual updates and debugging
- Enhanced error reporting with tracking numbers
- Proper order filtering (only processes orders with tracking numbers)
- Responsive design
- Comprehensive settings management

## Support

For support and feature requests, please contact the development team.

## License

This plugin is proprietary software. All rights reserved. 