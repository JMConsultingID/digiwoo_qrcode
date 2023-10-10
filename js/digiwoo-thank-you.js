(function( $ ) {
	'use strict';

	jQuery(document).ready(function($) {
	    let order_id = digiwoo_params.order_id;
	    let pix_payload = digiwoo_params.pix_payload;
	    let currency = digiwoo_params.currency;
	    let amount = digiwoo_params.amount;
	    let pix_instructions_content = digiwoo_params.pix_instructions_content;

	    if (!localStorage.getItem('qr_popup_shown_' + order_id)) {
	        let qrcode = new QRCode(document.createElement('div'), {
	            text: pix_payload,
	            width: 300,
	            height: 300
	        });

	        var canvas = qrcode._el.querySelector('canvas');
	        var ctx = canvas.getContext('2d');
	        var centerX = canvas.width / 2;
	        var centerY = canvas.height / 2;

	        ctx.font = "25px Arial";
	        ctx.textAlign = "center";
	        ctx.textBaseline = "middle";
	        ctx.fillStyle = "white";
	        var textWidth = ctx.measureText(currency + " " + amount).width;

	        ctx.fillRect(centerX - (textWidth / 2) - 10, centerY - 18, textWidth + 20, 36);
	        ctx.fillStyle = "black";
	        ctx.fillText(currency + " " + amount, centerX, centerY);

	        setTimeout(() => {
	            canvas.style.display = "inline-block";
	        }, 100);

	        Swal.fire({
			    title: 'Pix QR Code',
			    html: `
			        <div>
			            <img src="${canvas.toDataURL()}" alt="QR Code" style="width: 300px; height: 300px;">
			        </div>
			        <div style="margin-top: 20px; text-align: left;">
			            ${pix_instructions_content}
			        </div>
			    `,
			    showCloseButton: false,
			    allowOutsideClick: false,
			    confirmButtonText: 'Proceed to Payment',
			    preConfirm: () => {
			        Swal.fire({
			            title: 'Processing Payment...',
			            text: 'Please wait...',
			            showConfirmButton: false,
			            allowOutsideClick: false,
			            allowEscapeKey: false,
			            allowEnterKey: false,
			            onOpen: () => {
			                Swal.showLoading();
			            }
			        });
			        return new Promise((resolve, reject) => {
			            let attempts = 0;
			            const maxAttempts = 2;

			            function checkPaymentStatus() {
			                if (attempts >= maxAttempts) {
			                    Swal.fire({
			                        title: 'Notice',
			                        text: 'Your payment is still being processed. If the payment is successful, you will be notified via email.',
			                        icon: 'info',
			                        confirmButtonText: 'Close'
			                    }).then(() => {
			                        localStorage.setItem('qr_popup_shown_' + order_id, 'true'); 
			                        location.reload(); 
			                    });
			                    return;
			                }

			                attempts++;

			                $.ajax({
			                    type: 'POST',
			                    url: digiwoo_params.ajax_url,
			                    data: {
			                        action: 'check_order_payment_status',
			                        order_id: order_id
			                    },
			                    dataType: 'json',
			                    success: function(response) {
			                        if (response && response.status === 'completed') {
			                            resolve('Payment confirmed successfully!');
			                        } else {
			                            setTimeout(checkPaymentStatus, 5000);
			                        }
			                    },
			                    error: function() {
			                        reject('Error checking payment status.');
			                    }
			                });
			            }

			            checkPaymentStatus();
			        });
			    }
			}).then((result) => {
			    if (result.isConfirmed) {
			        Swal.fire({
			            title: 'Success',
			            text: result.value,
			            icon: 'success',
			            confirmButtonText: 'Close'
			        }).then(() => {
			            localStorage.setItem('qr_popup_shown_' + order_id, 'true'); 
			            location.reload(); 
			        });
			    } else if (result.isDismissed) {
			        Swal.fire({
			            title: 'Notice',
			            text: result.dismiss,
			            icon: 'info',
			            confirmButtonText: 'Close'
			        }).then(() => {
			            localStorage.setItem('qr_popup_shown_' + order_id, 'true'); 
			            location.reload(); 
			        });
			    }
			});

	    }
	});




})( jQuery );
