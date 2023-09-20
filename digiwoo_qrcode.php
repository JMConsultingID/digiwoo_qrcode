<?php
/**
 * Plugin Name: DigiWoo QRCode
 * Description: WooCommerce Payment Gateway for PIX QRCode
 * Version: 1.0
 * Author: Ardika JM-Consulting
 * Text Domain: digiwoo-qrcode
 */

require __DIR__ . '/vendor/autoload.php';
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;

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
                'description' => array(
                    'title' => 'Description',
                    'type' => 'textarea',
                    'default' => 'Pay with PIX using QRCode.'
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

        public function process_payment($order_id) {
            $order = wc_get_order($order_id);
            
            // Mark as on-hold (we're awaiting the payment)
            $order->update_status('on-hold', __('Awaiting PIX QR payment.', 'digiwoo-qrcode'));

            // Reduce stock levels
            $order->reduce_order_stock();

            // Remove cart
            WC()->cart->empty_cart();

            // Return thankyou redirect (this would be the standard thank you page)
            return array(
                'result'   => 'success',
                'redirect' => $this->get_return_url($order)
            );
        }


    }

    // Add this function to your digiwoo_qrcode.php
    function digiwoo_enqueue_styles() {
        wp_enqueue_style('digiwoo_qrcode_css', plugin_dir_url(__FILE__) . 'css/main.css', array(), '1.0');
    }
    add_action('wp_enqueue_scripts', 'digiwoo_enqueue_styles');


    add_filter( 'woocommerce_payment_gateways', 'add_pix_qrcode_gateway' );
    
    function add_pix_qrcode_gateway( $methods ) {
        $methods[] = 'WC_PIX_QRCODE';
        return $methods;
    }

    // AJAX Handling
    add_action('wp_ajax_get_qr_code_for_order', 'digiwoo_get_qr_code_for_order');
    add_action('wp_ajax_nopriv_get_qr_code_for_order', 'digiwoo_get_qr_code_for_order');

    function digiwoo_get_qr_code_for_order() {
        if (!isset($_POST['checkout_data'])) {
            wp_send_json_error(array('message' => 'Invalid request.'));
            return;
        }

        // Parse form data
        parse_str($_POST['checkout_data'], $checkout_data);

        // Get necessary information from $checkout_data
        $payer_name = sanitize_text_field($checkout_data['billing_first_name'] . ' ' . $checkout_data['billing_last_name']);
        $tax_id = sanitize_text_field($checkout_data['billing_tax_id']); // Assuming you have a tax_id field, change appropriately
        $amount = floatval(WC()->cart->get_total('edit'));

        // Build request for QR code generation
        $api_endpoint = 'https://api.sqala.tech/core/v1/pix-qrcode-payments';
        $headers = array(
            'accept' => 'application/json',
            'authorization' => 'Bearer ' . $this->get_option('token'),
            'content-type' => 'application/json',
        );


        $body = json_encode(array(
            'payer' => array(
                'name' => $payer_name,
                'taxId' => $tax_id
            ),
            'amount' => $amount
        ));

        $response = wp_remote_post($api_endpoint, array(
            'headers' => $headers,
            'body' => $body
        ));

        if (is_wp_error($response)) {
            wp_send_json_error(array('message' => 'Error generating QR code.'));
            return;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['payload'])) {
            // Generate QR Code
            $qrCode = new Endroid\QrCode\QrCode($body['payload']);
            $qrCode->setSize(200); // Size of QR Code, adjust as needed

            // Get PNG data
            $writer = new Endroid\QrCode\Writer\PngWriter();
            $pngData = $writer->write($qrCode)->getString();

            // Convert PNG data to a data URI
            $dataUri = 'data:image/png;base64,' . base64_encode($pngData);

            wp_send_json_success(array('qr_data_uri' => $dataUri));
        } else {
            wp_send_json_error(array('message' => 'Failed to retrieve QR payload.'));
        }
    }



    // Enqueue Script
    add_action('woocommerce_before_checkout_form', 'digiwoo_enqueue_scripts');

    function digiwoo_enqueue_scripts() {
        global $wp;

        if(is_checkout() && !empty($wp->query_vars['order-pay'])) {
            $order_id = $wp->query_vars['order-pay'];
            $dataUri = get_post_meta($order_id, '_pix_qrcode_data_uri', true);
        } else {
            $dataUri = '';
        }

        wp_enqueue_script('digiwoo_qrcode_js', plugin_dir_url(__FILE__) . 'js/digiwoo_qrcodes.js', array('jquery'), '1.2', true);
        wp_localize_script('digiwoo_qrcode_js', 'digiwoo_params', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'qr_data_uri' => $dataUri
        ));
    }

}
