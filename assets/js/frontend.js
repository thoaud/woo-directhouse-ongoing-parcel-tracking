/**
 * Frontend JavaScript for Ongoing Shipment Tracking
 */

jQuery(document).ready(function($) {
    'use strict';

    // Handle tracking update link clicks
    $('.update-tracking-link').on('click', function(e) {
        e.preventDefault();
        
        var $link = $(this);
        var $status = $link.siblings('.tracking-update-status');
        var orderId = $link.data('order-id');
        
        // Disable link and show updating status
        $link.prop('disabled', true).text(ongoingTrackingFrontend.strings.updating);
        $status.html('<span class="updating">' + ongoingTrackingFrontend.strings.updating + '</span>');
        
        // Make AJAX request
        $.ajax({
            url: ongoingTrackingFrontend.ajaxUrl,
            type: 'POST',
            data: {
                action: 'ongoing_frontend_update_tracking',
                order_id: orderId,
                nonce: ongoingTrackingFrontend.nonce
            },
            success: function(response) {
                if (response.success) {
                    $status.html('<span class="success">' + ongoingTrackingFrontend.strings.success + '</span>');
                    
                    // Reload the page to show updated tracking information
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } else {
                    $status.html('<span class="error">' + (response.data || ongoingTrackingFrontend.strings.error) + '</span>');
                }
            },
            error: function() {
                $status.html('<span class="error">' + ongoingTrackingFrontend.strings.error + '</span>');
            },
            complete: function() {
                // Re-enable link
                $link.prop('disabled', false).text('Refresh tracking information');
                
                // Clear status after 5 seconds
                setTimeout(function() {
                    $status.empty();
                }, 5000);
            }
        });
    });

    // Auto-update tracking when page loads (if tracking data is old)
    var $trackingSection = $('.ongoing-shipment-tracking');
    if ($trackingSection.length > 0) {
        var lastUpdated = $('.tracking-last-updated small').text();
        
        if (lastUpdated) {
            // Extract date from "Last updated: YYYY-MM-DD HH:MM:SS"
            var dateMatch = lastUpdated.match(/(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/);
            
            if (dateMatch) {
                var lastUpdateTime = new Date(dateMatch[1]);
                var now = new Date();
                var hoursDiff = (now - lastUpdateTime) / (1000 * 60 * 60);
                
                // Auto-update if data is older than 2 hours
                if (hoursDiff > 2) {
                    console.log('Auto-updating tracking information (data is ' + Math.round(hoursDiff) + ' hours old)');
                    $('.update-tracking-link').trigger('click');
                }
            }
        }
    }
}); 