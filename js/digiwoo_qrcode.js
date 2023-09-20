(function( $ ) {
    'use strict';

    jQuery(document).ready(function($) {
        if($('body').hasClass('woocommerce-checkout')) {
            // Assuming you're only showing this popup on checkout page

            let dataUri = digiwoo_params.qr_data_uri; // This gets the data URI we passed from PHP

            // Your logic for showing the QR code in a popup.
            if (dataUri !== '') {
                let popupContent = '<div id="qr-popup">' +
                                    '<img src="'+ dataUri +'" alt="QR Code" />' +
                                    '<button id="completed-payment">Completed Payment</button>' +
                                   '</div>';
                $('body').append(popupContent);

                // Show the popup here (you might need additional CSS and logic to show/hide it, etc.)

                // Add event listener for 'Completed Payment' button
                $('#completed-payment').click(function() {
                    // You can handle the event when the user clicks this button.
                    // Maybe close the popup and redirect to a thank you page, or whatever you need to do.
                });
            }
        }
    });


})( jQuery );