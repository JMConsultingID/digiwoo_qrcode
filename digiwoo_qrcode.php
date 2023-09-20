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

                $billing_country = $order->get_billing_country();
                $currency_map = get_country_currency_map();

                $default_currency = get_woocommerce_currency();
                $target_currency = isset($currency_map[$billing_country]) ? $currency_map[$billing_country] : get_woocommerce_currency();


                // Convert from WooCommerce default currency to target currency
                $converted_amount = convert_amount($order->get_total(), $default_currency, $target_currency);


                if ($converted_amount === false) {
                    wc_add_notice(__('Error in currency conversion. Please try again.', 'woocommerce'), 'error');
                    return;
                }

                $payload = array(
                    'payer'  => array(
                        'name'   => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                        'taxId'  => '37515868066'
                    ),
                    'amount' => intval($converted_amount * 100)
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

    function convert_amount($amount, $from_currency, $to_currency) {
        $api_url = "https://openexchangerates.org/api/latest.json";
        $app_id = "8d6942c3613f4282aaf251198c8ebd05"; // Your API key

        // Fetch rates for both currencies, as the base currency in the API is USD
        $response_from = wp_remote_get("{$api_url}?app_id={$app_id}&symbols={$from_currency}");
        $response_to = wp_remote_get("{$api_url}?app_id={$app_id}&symbols={$to_currency}");

        // Check for errors
        if (is_wp_error($response_from) || is_wp_error($response_to)) {
            // Handle the error
            wc_add_notice(__('Error fetching currency conversion rates. Please try again later.', 'your-text-domain'), 'error');
            return; // Return original amount if there's an error
        }

        $body_from = wp_remote_retrieve_body($response_from);
        $body_to = wp_remote_retrieve_body($response_to);

        $data_from = json_decode($body_from, true);
        $data_to = json_decode($body_to, true);

        if (!isset($data_from['rates'][$from_currency]) || !isset($data_to['rates'][$to_currency])) {
            wc_add_notice(__('Unexpected currency data received. Please try again later.', 'your-text-domain'), 'error');
            return; // Return original amount if there's an error
        }

        // Calculate the amount in USD (as the base currency of the API is USD)
        $amount_in_usd = $amount / $data_from['rates'][$from_currency];

        // Convert the USD amount to the target currency
        $converted_amount = $amount_in_usd * $data_to['rates'][$to_currency];

        return $converted_amount;
    }



    function get_country_currency_map() {
        return array('AF'=>'AFN','AX'=>'EUR','AL'=>'ALL','DZ'=>'DZD','AS'=>'USD','AD'=>'EUR','AO'=>'AOA','AI'=>'XCD','AQ'=>'USD','AG'=>'XCD','AR'=>'ARS','AM'=>'AMD','AW'=>'AWG','AU'=>'AUD','AT'=>'EUR','AZ'=>'AZN','BS'=>'BSD','BH'=>'BHD','BD'=>'BDT','BB'=>'BBD','BY'=>'BYN','BE'=>'EUR','BZ'=>'BZD','BJ'=>'XOF','BM'=>'BMD','BT'=>'BTN','BO'=>'BOB','BQ'=>'USD','BA'=>'BAM','BW'=>'BWP','BV'=>'NOK','BR'=>'BRL','IO'=>'USD','VG'=>'USD','BN'=>'BND','BG'=>'BGN','BF'=>'XOF','BI'=>'BIF','CV'=>'CVE','KH'=>'KHR','CM'=>'XAF','CA'=>'CAD','KY'=>'KYD','CF'=>'XAF','TD'=>'XAF','CL'=>'CLP','CN'=>'CNY','CX'=>'AUD','CC'=>'AUD','CO'=>'COP','KM'=>'KMF','CD'=>'CDF','CG'=>'XAF','CK'=>'NZD','CR'=>'CRC','CI'=>'XOF','HR'=>'HRK','CU'=>'CUP','CW'=>'ANG','CY'=>'EUR','CZ'=>'CZK','DK'=>'DKK','DJ'=>'DJF','DM'=>'XCD','DO'=>'DOP','EC'=>'USD','EG'=>'EGP','SV'=>'USD','GQ'=>'XAF','ER'=>'ERN','EE'=>'EUR','SZ'=>'SZL','ET'=>'ETB','FK'=>'FKP','FO'=>'DKK','FJ'=>'FJD','FI'=>'EUR','FR'=>'EUR','GF'=>'EUR','PF'=>'XPF','TF'=>'EUR','GA'=>'XAF','GM'=>'GMD','GE'=>'GEL','DE'=>'EUR','GH'=>'GHS','GI'=>'GIP','GR'=>'EUR','GL'=>'DKK','GD'=>'XCD','GP'=>'EUR','GU'=>'USD','GT'=>'GTQ','GG'=>'GBP','GN'=>'GNF','GW'=>'XOF','GY'=>'GYD','HT'=>'HTG','HM'=>'AUD','VA'=>'EUR','HN'=>'HNL','HK'=>'HKD','HU'=>'HUF','IS'=>'ISK','IN'=>'INR','ID'=>'IDR','IR'=>'IRR','IQ'=>'IQD','IE'=>'EUR','IM'=>'GBP','IL'=>'ILS','IT'=>'EUR','JM'=>'JMD','JP'=>'JPY','JE'=>'GBP','JO'=>'JOD','KZ'=>'KZT','KE'=>'KES','KI'=>'AUD','KW'=>'KWD','KG'=>'KGS','LA'=>'LAK','LV'=>'EUR','LB'=>'LBP','LS'=>'LSL','LR'=>'LRD','LY'=>'LYD','LI'=>'CHF','LT'=>'EUR','LU'=>'EUR','MO'=>'MOP','MG'=>'MGA','MW'=>'MWK','MY'=>'MYR','MV'=>'MVR','ML'=>'XOF','MT'=>'EUR','MH'=>'USD','MQ'=>'EUR','MR'=>'MRU','MU'=>'MUR','YT'=>'EUR','MX'=>'MXN','FM'=>'USD','MD'=>'MDL','MC'=>'EUR','MN'=>'MNT','ME'=>'EUR','MS'=>'XCD','MA'=>'MAD','MZ'=>'MZN','MM'=>'MMK','NA'=>'NAD','NR'=>'AUD','NP'=>'NPR','NL'=>'EUR','NC'=>'XPF','NZ'=>'NZD','NI'=>'NIO','NE'=>'XOF','NG'=>'NGN','NU'=>'NZD','NF'=>'AUD','KP'=>'KPW','MK'=>'MKD','NO'=>'NOK','OM'=>'OMR','PK'=>'PKR','PW'=>'USD','PS'=>'ILS','PA'=>'PAB','PG'=>'PGK','PY'=>'PYG','PE'=>'PEN','PH'=>'PHP','PN'=>'NZD','PL'=>'PLN','PT'=>'EUR','PR'=>'USD','QA'=>'QAR','RE'=>'EUR','RO'=>'RON','RU'=>'RUB','RW'=>'RWF','BL'=>'EUR','SH'=>'SHP','KN'=>'XCD','LC'=>'XCD','MF'=>'EUR','PM'=>'EUR','VC'=>'XCD','WS'=>'WST','SM'=>'EUR','ST'=>'STN','SA'=>'SAR','SN'=>'XOF','RS'=>'RSD','SC'=>'SCR','SL'=>'SLL','SG'=>'SGD','SX'=>'ANG','SK'=>'EUR','SI'=>'EUR','SB'=>'SBD','SO'=>'SOS','ZA'=>'ZAR','GS'=>'GBP','KR'=>'KRW','SS'=>'SSP','ES'=>'EUR','LK'=>'LKR','SD'=>'SDG','SR'=>'SRD','SJ'=>'NOK','SE'=>'SEK','CH'=>'CHF','SY'=>'SYP','TW'=>'TWD','TJ'=>'TJS','TZ'=>'TZS','TH'=>'THB','TL'=>'USD','TG'=>'XOF','TK'=>'NZD','TO'=>'TOP','TT'=>'TTD','TN'=>'TND','TR'=>'TRY','TM'=>'TMT','TC'=>'USD','TV'=>'AUD','UG'=>'UGX','UA'=>'UAH','AE'=>'AED','GB'=>'GBP','US'=>'USD','UM'=>'USD','VI'=>'USD','UY'=>'UYU','UZ'=>'UZS','VU'=>'VUV','VE'=>'VES','VN'=>'VND','WF'=>'XPF','EH'=>'MAD','YE'=>'YER','ZM'=>'ZMW','ZW'=>'ZWL');
    }

}
