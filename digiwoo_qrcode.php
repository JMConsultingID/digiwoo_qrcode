<?php
/**
 * Plugin Name: DigiWoo QRCode
 * Description: WooCommerce Payment Gateway for PIX QRCode
 * Version: 1.0
 * Author: Ardika JM-Consulting
 * Text Domain: digiwoo-qrcode
 */

require_once plugin_dir_path( __FILE__ ) . 'endroid-qrcode/Encoding/Encoding.php';
require_once plugin_dir_path( __FILE__ ) . 'endroid-qrcode/QrCodeInterface.php';
require_once plugin_dir_path( __FILE__ ) . 'endroid-qrcode/QrCode.php';


if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

add_action( 'plugins_loaded', 'digiwoo_qrcode_init', 0 );

function digiwoo_qrcode_init() {
    if ( ! class_exists( 'WC_Payment_Gateway' ) ) return;

    class WC_PIX_QRCODE extends WC_Payment_Gateway {
        public function __construct() {
            $this->id                 = 'pix_qrcode';
            $this->icon               = ''; // URL to your icon if you have one.
            $this->has_fields         = false;
            $this->method_title       = __( 'PIX QRCode', 'digiwoo-qrcode' );
            $this->method_description = __( 'Payment gateway for PIX QRCode', 'digiwoo-qrcode' );

            $this->init_form_fields();
            $this->init_settings();

            $this->title = $this->get_option( 'title' );

            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
        }

        public function init_form_fields() {
            $this->form_fields = array(
                'enabled' => array(
                    'title'   => __( 'Enable/Disable', 'digiwoo-qrcode' ),
                    'type'    => 'checkbox',
                    'label'   => __( 'Enable PIX QRCode Payment', 'digiwoo-qrcode' ),
                    'default' => 'yes'
                ),
                'title' => array(
                    'title'       => __( 'Title', 'digiwoo-qrcode' ),
                    'type'        => 'text',
                    'description' => __( 'This controls the title which the user sees during checkout.', 'digiwoo-qrcode' ),
                    'default'     => __( 'PIX QRCode', 'digiwoo-qrcode' ),
                    'desc_tip'    => true,
                ),
                'token' => array(
                    'title'       => __( 'Secret Token', 'digiwoo-qrcode' ),
                    'type'        => 'password', // Setting this as a password field will hide the actual value.
                    'description' => __( 'Enter the secret token for the PIX QRCode API.', 'digiwoo-qrcode' ),
                    'default'     => '',
                    'desc_tip'    => true,
                )
                // Add other configuration options as needed
            );
        }

        public function process_payment( $order_id ) {
            global $woocommerce;
            $order = new WC_Order($order_id);

            $payload = array(
                'payer' => array(
                    'name'   => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                    'taxId'  => '37515868066'
                ),
                'amount' => 1000
            );

            $response = wp_remote_post( 'https://api.sqala.tech/core/v1/pix-qrcode-payments', array(
                'method'    => 'POST',
                'headers'   => array(
                    'accept'        => 'application/json',
                    'authorization' => 'Bearer ' . $this->get_option( 'token' ),
                    'content-type'  => 'application/json'
                ),
                'body' => json_encode( $payload )
            ) );

            if ( is_wp_error( $response ) ) {
                wc_add_notice( 'Connection error: ' . $response->get_error_message(), 'error' );
                return;
            }

            $body = wp_remote_retrieve_body( $response );
            $body = json_decode( $body, true );

            if ( wp_remote_retrieve_response_code( $response ) != 200 ) {
                if ( isset( $body['message'] ) ) {  // Assuming the error message is in the 'message' key
                    wc_add_notice( 'API Error: ' . $body['message'], 'error' );
                } else {
                    wc_add_notice( 'Unexpected API Error.', 'error' );
                }
                return;
            }

            if ( isset( $body['payload'] ) ) {
                // Generate QR Code
                $qrCode = new \Endroid\QrCode\QrCode($body['payload']);
                $qrCode->setSize(200); // Size of QR Code, adjust as needed

                // Save QR Code to temp file
                $temp_file = tempnam(sys_get_temp_dir(), 'QR') . '.png';
                $qrCode->writeFile($temp_file);

                // Save temp file URL to order meta
                update_post_meta($order_id, '_pix_qrcode_url', $temp_file);

                // Display QR Code to customer using some frontend mechanism. This part needs more logic to show QR code to user.
                // For now, we will just save the QR code URL to the order and redirect the user.

                $order->update_status( 'on-hold', __( 'Awaiting PIX QRCode payment', 'digiwoo-qrcode' ) );
                $order->reduce_order_stock();

                $woocommerce->cart->empty_cart();

                return array(
                    'result'   => 'success',
                    'redirect' => $this->get_return_url( $order )
                );
            } else {
                wc_add_notice( 'There was an error processing your payment', 'error' );
                return;
            }
        }
    }

    add_action('woocommerce_thankyou', 'display_qrcode_after_order', 10, 1);
    function display_qrcode_after_order($order_id) {
        $qr_code_url = get_post_meta($order_id, '_pix_qrcode_url', true);

        if ($qr_code_url) {
            echo '<h2>PIX QRCode Payment</h2>';
            echo '<p>Please scan the below QR code to complete your payment:</p>';
            echo '<img src="' . esc_url($qr_code_url) . '" alt="PIX Payment QR Code" />';
        }
    }


    add_filter( 'woocommerce_payment_gateways', 'add_pix_qrcode_gateway' );
    
    function add_pix_qrcode_gateway( $methods ) {
        $methods[] = 'WC_PIX_QRCODE';
        return $methods;
    }
}
