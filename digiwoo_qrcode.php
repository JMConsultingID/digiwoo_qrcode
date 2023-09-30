<?php

/**
 * Plugin Name:       DigiWoo QRCode for WooCommerce
 * Plugin URI:        https://fundscap.com/
 * Description:       Adds a PIX QRCode payment method to WooCommerce.
 * Version:           1.0.1
 * Author:            Ardi JM Consulting
 * Author URI:        https://fundscap.com/
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       digiwoo_qrcode
 * Domain Path:       /languages
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
                add_action( 'woocommerce_api_digiwoo_pix_ipn', array( $this, 'check_for_ipn_response' ) );
            }

            public function init_form_fields() {
                $this->form_fields = array(
                    'enabled' => array(
                        'title'   => __('Enable/Disable', 'digiwoo_qrcode'),
                        'type'    => 'checkbox',
                        'label'   => __('Enable PIX QRCode Payment', 'digiwoo_qrcode'),
                        'default' => 'no'
                    ),
                    'title' => array(
                        'title'       => __('Title', 'digiwoo_qrcode'),
                        'type'        => 'text',
                        'description' => __('This controls the title the user sees during checkout.', 'digiwoo_qrcode'),
                        'default'     => __('PIX QRCode', 'digiwoo_qrcode'),
                        'desc_tip'    => true,
                    ),
                    'token' => array(
                        'title'       => __('API Token', 'digiwoo_qrcode'),
                        'type'        => 'text',
                        'description' => __('This is the API token provided by sqala.tech.', 'digiwoo_qrcode'),
                        'default'     => '',
                        'desc_tip'    => true,
                    ),
                    'webhook_url' => array(
                        'title'       => __('PIX QRCode Webhook URL', 'digiwoo_qrcode'),
                        'type'        => 'text',
                        'description' => __('URL to receive webhooks from the service provider.', 'digiwoo_qrcode'),
                        'default'     => home_url('/?wc-api=digiwoo_pix_ipn'),
                        'desc_tip'    => true,
                        'custom_attributes' => array(
                            'readonly' => 'readonly'
                        )
                    ),
                    'title_first' => array(
                        'title' => __('Auo Conversion Currencies Using openexchangerates.org', 'digiwoo_qrcode'),
                        'type'  => 'title',
                        'description' => __('if enable, it will automatically convert currency to brazil via openexchangerates.org API (see API price details: <a href="https://openexchangerates.org/signup" target="_blank">see detail pricing</a> Free Plan provides hourly updates (with base currency USD) and up to 1,000 requests/month.)', 'digiwoo_qrcode'),
                    ),
                    'conversion_enabled' => array(
                        'title'   => __('Enable AUTO Currency Conversion API', 'woocommerce'),
                        'type'    => 'checkbox',
                        'label'   => __('Enable', 'woocommerce'),
                        'default' => 'no',
                        'desc_tip'=> true,
                        'description' => __('Enable this if you want to convert order currency to another currency using Open Exchange Rates.', 'woocommerce'),
                    ),
                    'api_url' => array(
                        'title'       => __('Open Exchange Rates API URL', 'woocommerce'),
                        'type'        => 'text',
                        'description' => __('Enter the API URL for Open Exchange Rates.', 'woocommerce'),
                        'default'     => 'https://openexchangerates.org/api/latest.json',
                        'desc_tip'    => true,
                    ),
                    'app_id' => array(
                        'title'       => __('Open Exchange Rates App ID', 'woocommerce'),
                        'type'        => 'text',
                        'description' => __('Enter your App ID for Open Exchange Rates.', 'woocommerce'),
                        'default'     => '',
                        'desc_tip'    => true,
                    ),
                    'app_id' => array(
                        'title'       => __('Open Exchange Rates App ID', 'woocommerce'),
                        'type'        => 'text',
                        'description' => __('Enter your App ID for Open Exchange Rates.', 'woocommerce'),
                        'default'     => '',
                        'desc_tip'    => true,
                    ),
                    'title_second' => array(
                        'title' => __('Convert Manual to Brazil Currencies', 'digiwoo_qrcode'),
                        'type'  => 'title',
                        'description' => __('IF Disable AUTO Currency Conversion API, Convert Total Order to BRL Currencies using Manual Rate.', 'digiwoo_qrcode'),
                    ),
                    
                    // Input field for rate conversion from 1 dollar to Brazilian real
                    'rate_usd_to_brl' => array(
                        'title'       => __('Rate: 1 USD to BRL', 'digiwoo_qrcode'),
                        'type'        => 'text',
                        'description' => __('Enter the conversion rate for 1 USD to Brazilian real.', 'digiwoo_qrcode'),
                        'default'     => '5.00',  // Example default value. You can set it to a more accurate or recent rate.
                        'desc_tip'    => true,
                    ),

                );
            }

            public function process_payment($order_id) {
                $logger = wc_get_logger();
                $context = array( 'source' => 'digiwoo_qrcode' );

                $order = wc_get_order($order_id);

                $billing_country = $order->get_billing_country();
                $currency_map = get_country_currency_map();

                $default_currency = get_woocommerce_currency();
                $target_currency = 'BRL';

                $options = get_option('woocommerce_pix_qrcode_settings');

                if (isset($options['conversion_enabled']) && $options['conversion_enabled'] == 'yes') {
                    // Convert using API
                    $converted_amount = convert_amount($order->get_total(), $default_currency, $target_currency);
                    $logger->info("converted_amount using API : $converted_amount", $context);
                } else {
                    // Manual conversion using the rate_usd_to_brl setting
                    if (isset($options['rate_usd_to_brl']) && is_numeric($options['rate_usd_to_brl'])) {
                        $rate = floatval($options['rate_usd_to_brl']);
                        $converted_amount = $order->get_total() * $rate;
                        $logger->info("converted_amount using API : $converted_amount", $context);
                    } else {
                        wc_add_notice(__('Error in currency conversion. Please try again.', 'digiwoo_qrcode'), 'error');
                        $logger->error("Error in currency conversion. Please try again.", $context);
                        return;
                        
                    }
                }

                $formatted_amount = intval($converted_amount * 100); // This formats the number to two decimal places like money


                if ($converted_amount === false) {
                    wc_add_notice(__('False Error in currency conversion. Please try again.', 'digiwoo_qrcode'), 'error');
                    $logger->error("False  Error in currency conversion. Please try again.", $context);
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
                        $resultpost_meta  = update_post_meta($order_id, 'all_digiwoo_pix_whole_success_generate_response', wp_json_encode($body));
                        if (false === $resultpost_meta) {
                            $logger->error("Failed to update post meta for order: $order_id", $context);
                        } else {
                            $logger->info("Post meta updated successfully for order: $order_id", $context);
                        }
                        update_post_meta($order_id, 'digiwoo_pix_generate_payload', $body['payload']);
                        update_post_meta($order_id, 'digiwoo_pix_generate_id', $body['id']);
                        update_post_meta($order_id, 'digiwoo_pix_generate_code', $body['code']);
                        update_post_meta($order_id, 'digiwoo_pix_generate_amount', $body['amount']);
                        update_post_meta($order_id, 'digiwoo_pix_generate_payer', $body['payer']);
                        update_post_meta($order_id, 'digiwoo_pix_generate_status', $body['status']);
                        if (isset($body['expiresAt'])) {
                            $expiresAt = $body['expiresAt'];
                            $dateTime = new DateTime($expiresAt);
                            $DateExpiresAt = $dateTime->format('d/m/Y H:i:s');
                            update_post_meta($order_id, 'digiwoo_pix_generate_expires_at', $DateExpiresAt);
                        }

                        // Set the order status to 'on-hold' and reduce stock levels (if applicable)
                        $order->update_status('on-hold', __('Awaiting PIX payment.', 'digiwoo_qrcode'));

                        // Add order note with the payment payload
                        $order->add_order_note(__('PIX QRCode payload generated.', 'digiwoo_qrcode'));               

                        // Remove cart contents
                        WC()->cart->empty_cart();

                        // Redirect to thank you page with the payload for QR code generation
                        return array(
                            'result' => 'success',
                            'pix_payload' => $body['payload'],
                            'amount' => $formatted_amount,      // Adding the formatted amount
                            'currency' => $target_currency,
                            'redirect' => $this->get_return_url($order)
                        );
                    }
                }

                // Add notice for the user in case of error
                wc_add_notice(__('Error generating PIX QRCode. Please try again.', 'digiwoo_qrcode'), 'error');
                update_post_meta($order_id, ' all_digiwoo_pix_whole_error_generate_response', wp_json_encode($body));
                return;
            }

            public function check_for_ipn_response() {
                global $woocommerce;
                $log_data = digiwoo_get_logger();               

                $requestType = !empty($_GET['digiwoo_pix_ipn']) ? $_GET['digiwoo_pix_ipn'] : '';
                $data = json_decode(file_get_contents('php://input'), true);
                $log_data['logger']->info('response ipn : '.wp_json_encode($data),  $log_data['context']);
                if (empty($data) || !isset($data['data']['id'])) {
                    $log_data['logger']->error('This empty response ipn',  $log_data['context']);
                    return;
                }

                $order_args = array(
                    'post_type' => 'shop_order',
                    'post_status' => 'any',
                    'meta_key' => 'digiwoo_pix_generate_id',
                    'meta_value' => $data['data']['id'],
                    'posts_per_page' => 1,
                );
                $orders = get_posts($order_args);
                if (empty($orders)) {
                    $log_data['logger']->error('This empty order id for response : '.$data['data']['id'],  $log_data['context']);
                    return;
                }

                $order_id = $orders[0]->ID;
                $log_data['logger']->info('order_id  : '.$order_id,  $log_data['context']);
                update_post_meta($order_id, 'all_digiwoo_pix_whole_success_payment_response', wp_json_encode($data));
                update_post_meta($order_id, 'digiwoo_pix_payment_id', $data['id']);
                update_post_meta($order_id, 'digiwoo_pix_payment_event', $data['event']);
                update_post_meta($order_id, 'digiwoo_pix_payment_signature', $data['signature']);
 
                $status_payment   = isset($data['data']['status']) ? sanitize_text_field($data['data']['status']) : '';

                if ($status_payment === 'PAID') {
                    $order = wc_get_order($order_id);
                    $log_data['logger']->info('order status  : completed, Payment confirmed via IPN',  $log_data['context']);
                    update_post_meta($order_id, 'digiwoo_pix_payment_status', $data['data']['status']);
                    update_post_meta($order_id, 'digiwoo_pix_payment_status_delivered', $data['status']);
                    $order->add_order_note('Payment confirmed via IPN.');
                    $order->update_status('completed');      
                } else {
                    $order->update_status('failed');
                    $log_data['logger']->error('order status  : failed, Payment not confirmed via IPN.',  $log_data['context']);
                    $order->add_order_note('Error Pyament : Payment not confirmed via IPN.');
                    update_post_meta($order_id, 'all_digiwoo_pix_whole_error_payment_response', wp_json_encode($data));
                }

                status_header(200);
                exit('OK');
            }

        }

        // Add the gateway to WooCommerce
        add_filter('woocommerce_payment_gateways', 'add_digiwoo_qrcode_gateway');

        function add_digiwoo_qrcode_gateway($methods) {
            $methods[] = 'WC_PIX_QRCODE';
            return $methods;
        }
    }


    function digiwoo_enqueue_scripts() {
        // Enqueue SweetAlert2
        wp_enqueue_script('sweetalert2', 'https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.js');
        wp_enqueue_style('sweetalert2', 'https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css');
        wp_enqueue_script('qrcode', 'https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js'); // ganti dengan path yang sesuai
    }
    add_action('wp_enqueue_scripts', 'digiwoo_enqueue_scripts');

    function digiwoo_qrcode_thank_you_js($order_id) {
        if (!$order_id) return;
        $target_currency = 'BRL';
        $order = wc_get_order($order_id);
        $pix_payload = $order->get_meta('digiwoo_pix_generate_payload');
        $currency = $target_currency;
        $amount = $order->get_meta('digiwoo_pix_generate_amount');

        if ($pix_payload) {
            ?>
            <script>
                document.addEventListener("DOMContentLoaded", function() {
                    let qrcode = new QRCode(document.createElement('div'), {
                        text: '<?php echo $pix_payload; ?>',
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

                    var textWidth = ctx.measureText('<?php echo $currency; ?>' + " " + '<?php echo $amount; ?>').width;
                    ctx.fillRect(centerX - (textWidth / 2) - 10, centerY - 18, textWidth + 20, 36);
                    ctx.fillStyle = "black";
                    ctx.fillText('<?php echo $currency; ?>' + " " + '<?php echo $amount; ?>', centerX, centerY);

                    setTimeout(() => {
                        canvas.style.display = "inline-block";
                    }, 100);

                    Swal.fire({
                        title: 'Your QR Code',
                        html: canvas,
                        showCloseButton: false,
                        allowOutsideClick: false,
                        confirmButtonText: 'Proceed to Payment',
                        preConfirm: () => {
                        return new Promise((resolve, reject) => {
                            let attempts = 0;
                            const maxAttempts = 2;

                            function checkPaymentStatus() {
                                if (attempts >= maxAttempts) {
                                    // Display the info message when the max attempts are reached
                                    Swal.fire({
                                        title: 'Notice',
                                        text: 'Payment confirmation took too long. Please check your order status later.',
                                        icon: 'info',
                                        confirmButtonText: 'Close'
                                    });
                                    return;
                                }

                                attempts++;

                                jQuery.ajax({
                                    type: 'POST',
                                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                                    data: {
                                        action: 'check_order_payment_status',
                                        order_id: '<?php echo $order_id; ?>'
                                    },
                                    dataType: 'json',
                                    success: function(response) {
                                        if (response && response.status === 'completed') {
                                            resolve('Payment confirmed successfully!');
                                        } else {
                                            setTimeout(checkPaymentStatus, 5000); // check again after 5 seconds
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
                        Swal.fire('Success', result.value, 'success');
                    } else if (result.isDismissed) {
                        Swal.fire('Notice', result.dismiss, 'info');
                    }
                });
                });
            </script>
            <?php
        }
    }
    add_action('woocommerce_thankyou', 'digiwoo_qrcode_thank_you_js');


    function convert_amount($amount, $from_currency, $to_currency) {
        $options = get_option('woocommerce_pix_qrcode_settings');
        $log_data = digiwoo_get_logger();
        $api_url = isset($options['api_url']) ? $options['api_url'] : 'https://openexchangerates.org/api/latest.json';
        $app_id = isset($options['app_id']) ? $options['app_id'] : '8d6942c3613f4282aaf251198c8ebd05';

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

    function digiwoo_get_logger() {
        $logger = wc_get_logger();
        $context = array('source' => 'digiwoo_qrcode');
        return array('logger' => $logger, 'context' => $context);
    }

    add_filter('plugin_action_links_digiwoo_qrcode/digiwoo_qrcode.php', 'add_digiwoo_qrcode_settings_link', 10, 1 );

    function add_digiwoo_qrcode_settings_link($links) {
        $settings_link = '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=pix_qrcode') . '">' . __('Settings', 'digiwoo_qrcode') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    function check_order_payment_status() {
        if (!isset($_POST['order_id'])) {
            wp_send_json_error(['message' => 'Order ID not provided.']);
            return;
        }

        $order_id = intval($_POST['order_id']);
        $order = wc_get_order($order_id);

        if (!$order) {
            wp_send_json_error(['message' => 'Order not found.']);
            return;
        }

        $status = $order->get_status();
        wp_send_json(['status' => $status]);
    }

    add_action('wp_ajax_check_order_payment_status', 'check_order_payment_status');
    add_action('wp_ajax_nopriv_check_order_payment_status', 'check_order_payment_status');


}
