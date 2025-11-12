<?php
/*
Plugin Name: NanoPay.me Payment Processor for WooCommerce
Description: Integrate NanoPay.me as a payment method for WooCommerce
Version: 1.1
Author: mnpezz
Plugin URI: http://github.com/mnpezz
Requires at least: 5.0
Requires PHP: 7.0
WC requires at least: 3.0
WC tested up to: 8.3
*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Add this near the top of the file, after the plugin header
if (!defined('NANOPAY_PLUGIN_FILE')) {
    define('NANOPAY_PLUGIN_FILE', __FILE__);
}

// Check if WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    return;
}

// Include the main gateway class file
add_action('plugins_loaded', 'init_nanopay_gateway', 0);

function init_nanopay_gateway() {
    error_log('Initializing NanoPay Gateway');
    if (!class_exists('WC_Payment_Gateway')) {
        error_log('WC_Payment_Gateway class does not exist');
        add_action('admin_notices', function() {
            echo '<div class="error"><p>WooCommerce is not active. The WooCommerce NanoPay Gateway plugin requires WooCommerce to be active.</p></div>';
        });
        return;
    }

    require_once plugin_dir_path(__FILE__) . 'includes/class-nanopay-gateway.php';
    error_log('NanoPay Gateway class loaded');

    // Add the gateway to WooCommerce
    add_filter('woocommerce_payment_gateways', 'add_nanopay_gateway');
}

function add_nanopay_gateway($gateways) {
    $gateways[] = 'WC_NanoPay_Gateway';
    error_log('NanoPay Gateway added to gateways: ' . print_r($gateways, true));
    return $gateways;
}

// Add block support
add_action('woocommerce_blocks_loaded', 'init_nanopay_gateway_blocks_support');
function init_nanopay_gateway_blocks_support() {
    if (!class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
        return;
    }

    require_once plugin_dir_path(__FILE__) . 'includes/class-wc-nanopay-gateway-blocks-support.php';

    add_action(
        'woocommerce_blocks_payment_method_type_registration',
        function(Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry) {
            $payment_method_registry->register(new WC_NanoPay_Gateway_Blocks_Support());
        }
    );
}

// Declare compatibility with cart and checkout blocks
add_action('before_woocommerce_init', function() {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
});

// Add this new function for debugging
add_action('woocommerce_payment_gateways', 'debug_payment_gateways', 99);
function debug_payment_gateways($gateways) {
    error_log('All payment gateways: ' . print_r($gateways, true));
    return $gateways;
}

// Add this new action to check if the gateway is being registered
add_action('woocommerce_init', 'check_nanopay_gateway_registered');
function check_nanopay_gateway_registered() {
    $payment_gateways = WC()->payment_gateways->payment_gateways();
    error_log('Registered payment gateways: ' . print_r(array_keys($payment_gateways), true));
    if (isset($payment_gateways['nanopay'])) {
        error_log('NanoPay gateway is registered');
    } else {
        error_log('NanoPay gateway is NOT registered');
    }
}

function enqueue_nanopay_scripts() {
    if (is_checkout() && !is_wc_endpoint_url()) {
        wp_enqueue_script('nanopay-checkout', plugin_dir_url(__FILE__) . 'assets/js/nanopay-checkout.js', array('jquery'), '1.0.0', true);
    }
}
add_action('wp_enqueue_scripts', 'enqueue_nanopay_scripts');

function nanopay_activate() {
    if (!wp_next_scheduled('nanopay_check_pending_payments')) {
        wp_schedule_event(time(), 'hourly', 'nanopay_check_pending_payments');
    }
}
register_activation_hook(NANOPAY_PLUGIN_FILE, 'nanopay_activate');

function nanopay_deactivate() {
    wp_clear_scheduled_hook('nanopay_check_pending_payments');
}
register_deactivation_hook(NANOPAY_PLUGIN_FILE, 'nanopay_deactivate');

add_action('nanopay_check_pending_payments', 'nanopay_check_all_pending_payments');

function nanopay_check_all_pending_payments() {
    $orders = wc_get_orders(array(
        'status' => 'pending',
        'payment_method' => 'nanopay',
        'date_created' => '>' . (time() - 24 * 60 * 60), // Check orders from the last 24 hours
    ));

    $gateway = new WC_NanoPay_Gateway();

    foreach ($orders as $order) {
        $invoice_id = $order->get_meta('nanopay_invoice_id');
        if ($invoice_id) {
            $gateway->check_payment_status($order->get_id(), $invoice_id);
        }
    }
}
