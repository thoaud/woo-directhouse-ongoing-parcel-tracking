## Todo
- [x] Move data we pull into a separate table instead of using metadata
  - [x] Design table structure with the following columns:
    - [x] site_id (NULLABLE) for multisite installs
    - [x] order_id
    - [x] tracking_data columns (based on current data structure)
  - [x] Create database migration script
  - [x] Add useful database indexes for performance
  - [x] Update code to use new table instead of metadata
  - [x] Test on multisite installations
  - [x] Verify this prevents order updates when tracking data changes
  - [x] Ensure scalability improvements are achieved
  - [x] Make sure that the database table is removed on plugin uninstall

- [x] When parallel processing, move the retries to the end of the queue instead of trying them after the batch they are in.

- [ ] Add support for custom tracking number fields
  - [ ] Make tracking number field configurable via settings
  - [ ] Support WooCommerce custom fields
  - [ ] Support ACF fields
  - [ ] Support other common tracking number plugins
  - [ ] Add filter hook for custom field handling

- [ ] Improve frontend template system
  - [ ] Move all frontend templates to separate template files
  - [ ] Add template override support in theme/plugins
  - [ ] Document template override process
  - [ ] Add template hooks for customization
  - [ ] Create example override templates
  - [ ] Add template version checking

- [ ] Implement webhook integration
  - [ ] Create secure webhook endpoint with authentication
  - [ ] Add webhook configuration in settings
  - [ ] Support notification-only webhooks that trigger data pull
  - [ ] Support full payload webhooks with validation
  - [ ] Add webhook logging and error handling
  - [ ] Add retry mechanism for failed webhook processing
  - [ ] Document webhook integration process
  - [ ] Create example webhook payloads