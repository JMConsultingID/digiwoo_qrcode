<?php
/**
 * Plugin Name: DigiWoo QRCode for WooCommerce
 * Description: Adds a PIX QRCode payment method to WooCommerce.
 * Version: 1.0
 * Author: Your Name
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Check if WooCommerce is active
if (in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    add_action('plugins_loaded', 'digiwoo_qrcode_init', 0);

    function digiwoo_qrcode_init() {
        if (!class_exists('WC_Payment_Gateway')) {
            return; // Exit if WooCommerce is not loaded
        }

        // Main gateway class
        class WC_PIX_QRCODE extends WC_Payment_Gateway {

            public function __construct() {
                $this->id = 'pix_qrcode';
                $this->icon = ''; // URL to an icon for this method.
                $this->has_fields = false;
                $this->method_title = 'PIX QRCode';
                $this->method_description = 'Accept payments via PIX QRCode.';

                // Load the settings.
                $this->init_form_fields();
                $this->init_settings();

                // Define user set variables.
                $this->title = $this->get_option('title');
                $this->token = $this->get_option('token');

                // Save settings.
                add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            }

            public function init_form_fields() {
                $this->form_fields = array(
                    'enabled' => array(
                        'title'   => __('Enable/Disable', 'woocommerce'),
                        'type'    => 'checkbox',
                        'label'   => __('Enable PIX QRCode Payment', 'woocommerce'),
                        'default' => 'no'
                    ),
                    'title' => array(
                        'title'       => __('Title', 'woocommerce'),
                        'type'        => 'text',
                        'description' => __('This controls the title the user sees during checkout.', 'woocommerce'),
                        'default'     => __('PIX QRCode', 'woocommerce'),
                        'desc_tip'    => true,
                    ),
                    'token' => array(
                        'title'       => __('API Token', 'woocommerce'),
                        'type'        => 'text',
                        'description' => __('This is the API token provided by sqala.tech.', 'woocommerce'),
                        'default'     => '',
                        'desc_tip'    => true,
                    ),
                );
            }

            public function process_payment($order_id) {
                $order = wc_get_order($order_id);

                $payload = array(
                    'payer'  => array(
                        'name'   => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                        'taxId'  => '37515868066'
                    ),
                'amount' => intval($order->get_total() * 100)
                );

                $response = wp_remote_post('https://api.sqala.tech/core/v1/pix-qrcode-payments', array(
                    'headers' => array(
                        'accept'        => 'application/json',
                        'authorization' => 'Bearer ' . $this->token,
                        'content-type'  => 'application/json',
                    ),
                    'body'    => json_encode($payload),
                ));

                if (!is_wp_error($response)) {
                    $body = json_decode(wp_remote_retrieve_body($response), true);

                    if (isset($body['payload'])) {
                        // Set the order status to 'on-hold' and reduce stock levels (if applicable)
                        $order->update_status('on-hold', __('Awaiting PIX payment.', 'woocommerce'));

                        // Add order note with the payment payload
                        $order->add_order_note(__('PIX QRCode payload generated.', 'woocommerce'));

                        // Remove cart contents
                        WC()->cart->empty_cart();

                        // Redirect to thank you page with the payload for QR code generation
                        return array(
                            'result'   => 'success',
                            'redirect' => $this->get_return_url($order) . '&pix_payload=' . urlencode($body['payload']),
                        );
                    }
                }

                // Add notice for the user in case of error
                wc_add_notice(__('Error generating PIX QRCode. Please try again.', 'woocommerce'), 'error');

                return;
            }
        }

        // Add the gateway to WooCommerce
        add_filter('woocommerce_payment_gateways', 'add_digiwoo_qrcode_gateway');

        function add_digiwoo_qrcode_gateway($methods) {
            $methods[] = 'WC_PIX_QRCODE';
            return $methods;
        }
    }

    // Add JavaScript for QR Code generation on the Thank You page
    add_action('woocommerce_thankyou', 'digiwoo_qrcode_js', 10);

    function digiwoo_qrcode_js($order_id) {
        if (isset($_GET['pix_payload'])) {
            $pix_payload = sanitize_text_field($_GET['pix_payload']);

            echo '<div id="qrcode"></div>';

            // Include qrcode.js library
            echo '<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>';
            echo "<script>
                var qrcode = new QRCode(document.getElementById('qrcode'), {
                    text: '$pix_payload',
                    width: 128,
                    height: 128
                });
            </script>";
        }
    }
}
