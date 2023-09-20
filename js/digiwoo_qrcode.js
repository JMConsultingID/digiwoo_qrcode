(function( $ ) {
    'use strict';

    jQuery(document).ready(function($) {
        // Assuming your checkout button has an ID of place_order
        $('#place_order').on('click', function(e) {
            e.preventDefault();

            // Show popup with QR Code
            let dataUri = /* fetch your QR code data URI from the server */;
            $('body').append('<div id="qrcode_popup"><img src="' + dataUri + '" alt="QR Code" /><button id="completed_payment">Completed Payment</button></div>');

            // Add event for completed payment
            $('#completed_payment').on('click', function() {
                $.post(digiwoo_params.ajax_url, { action: 'check_qrcode_payment' }, function(response) {
                    if(response.status === 'completed') {
                        // Redirect to the default WooCommerce thank you page
                        var order_id = response.order_id; // assuming you're sending order_id in the AJAX response
                        var order_key = response.order_key; // assuming you're sending order_key in the AJAX response
                        window.location.href = '/checkout/order-received/' + order_id + '/?key=' + order_key;
                    } else {
                        alert('Payment not completed. Please check again.');
                    }
                });
            });

        });
    });
})( jQuery );