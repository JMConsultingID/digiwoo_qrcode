(function( $ ) {
    'use strict';
    jQuery(document).ready(function($) {
        // Listen to the place order button click
        $('form.checkout').on('checkout_place_order', function(e) {
            if ($('#payment_method_pix_qrcode').is(':checked')) {
                // Prevent form submission
                e.preventDefault();

                // Trigger AJAX to get QR code
                $.ajax({
                    url: wc_checkout_params.ajax_url,
                    type: 'POST',
                    data: {
                        'action': 'get_qr_code_for_order',
                        'checkout_data': $('form.checkout').serialize()
                    },
                    success: function(response) {
                        if (response.success && response.data) {
                            // Show popup with QR code
                            showQrPopup(response.data.qr_data_uri);
                        }
                    }
                });
            }
        });
        
        function showQrPopup(dataUri) {
            var popupContent = '<div class="qr-popup">' +
                '<h2>PIX QRCode Payment</h2>' +
                '<img src="' + dataUri + '" alt="PIX Payment QR Code" />' +
                '<button class="return-to-payment">Return to Payment</button>' +
                '</div>';

            $('body').append(popupContent);
            
            $('.qr-popup .return-to-payment').click(function() {
                // Handle return to payment
                $('.qr-popup').remove();
            });
        }
    });
})( jQuery );