/**
 * Admin JavaScript for Ongoing Shipment Tracking
 */

jQuery(document).ready(function($) {
    'use strict';

    // Handle tracking update button clicks in admin
    $('#update_tracking').on('click', function(e) {
        e.preventDefault();
        
        var $button = $(this);
        var orderId = $button.data('order-id');
        var trackingNumber = $('#ongoing_tracking_number').val();
        
        // Disable button and show updating status
        $button.prop('disabled', true).text(ongoingTrackingAdmin.strings.updating);
        
        // Make AJAX request
        $.ajax({
            url: ongoingTrackingAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'ongoing_admin_update_tracking',
                order_id: orderId,
                tracking_number: trackingNumber,
                nonce: ongoingTrackingAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    // Show success message
                    showAdminNotice(ongoingTrackingAdmin.strings.success, 'success');
                    
                    // Reload the page to show updated tracking information
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } else {
                    showAdminNotice(response.data || ongoingTrackingAdmin.strings.error, 'error');
                }
            },
            error: function() {
                showAdminNotice(ongoingTrackingAdmin.strings.error, 'error');
            },
            complete: function() {
                // Re-enable button
                $button.prop('disabled', false).text('Update Tracking');
            }
        });
    });

    // Handle tracking number field changes
    $('#ongoing_tracking_number').on('change', function() {
        var trackingNumber = $(this).val();
        
        // Save tracking number to order meta
        if (trackingNumber) {
            var orderId = $('#update_tracking').data('order-id');
            
            $.ajax({
                url: ongoingTrackingAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ongoing_admin_update_tracking',
                    order_id: orderId,
                    tracking_number: trackingNumber,
                    nonce: ongoingTrackingAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        showAdminNotice('Tracking number saved successfully.', 'success');
                    }
                }
            });
        }
    });

    // Function to show admin notices
    function showAdminNotice(message, type) {
        var noticeClass = type === 'success' ? 'notice-success' : 'notice-error';
        var notice = '<div class="notice ' + noticeClass + ' is-dismissible"><p>' + message + '</p></div>';
        
        // Remove existing notices
        $('.ongoing-tracking-notice').remove();
        
        // Add new notice
        var $notice = $(notice).addClass('ongoing-tracking-notice');
        $('#ongoing_shipment_tracking').before($notice);
        
        // Auto-dismiss after 5 seconds
        setTimeout(function() {
            $notice.fadeOut(function() {
                $(this).remove();
            });
        }, 5000);
    }

    // Add tracking number field to order actions
    $('.order_actions').each(function() {
        var $actions = $(this);
        var orderId = $actions.closest('tr').find('.check-column input').val();
    });

    // Handle quick update buttons in order list
    $(document).on('click', '.update-tracking-quick', function(e) {
        e.preventDefault();
        
        var $button = $(this);
        var $input = $button.siblings('.tracking-number-input');
        var orderId = $button.data('order-id');
        var trackingNumber = $input.val();
        
        if (!trackingNumber) {
            alert('Please enter a tracking number.');
            return;
        }
        
        // Disable button
        $button.prop('disabled', true).text('Updating...');
        
        // Make AJAX request
        $.ajax({
            url: ongoingTrackingAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'ongoing_admin_update_tracking',
                order_id: orderId,
                tracking_number: trackingNumber,
                nonce: ongoingTrackingAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    $button.text('Updated!').addClass('button-primary');
                    setTimeout(function() {
                        $button.text('Update').removeClass('button-primary');
                    }, 2000);
                } else {
                    alert('Error: ' + (response.data || ongoingTrackingAdmin.strings.error));
                }
            },
            error: function() {
                alert('Error: ' + ongoingTrackingAdmin.strings.error);
            },
            complete: function() {
                $button.prop('disabled', false);
            }
        });
    });
}); 