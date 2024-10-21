<?php
require_once('../../../wp-load.php');

$invoice_id = $_GET['invoice_id'];

$gateway = new WC_NanoPay_Gateway();
$api_key = $gateway->get_option('api_key');

$response = wp_remote_get('https://api.nanopay.me/invoices/' . $invoice_id, array(
    'headers' => array(
        'Authorization' => 'Bearer ' . $api_key
    )
));

if (is_wp_error($response)) {
    wp_die('Payment verification failed');
}

$body = json_decode(wp_remote_retrieve_body($response));

if ($body->status === 'paid') {
    $order_id = $body->metadata->order_id;
    $order = wc_get_order($order_id);
    $order->payment_complete();
    $order->add_order_note('Payment completed via NanoPay.me. Invoice ID: ' . $invoice_id);
    wp_redirect($order->get_checkout_order_received_url());
} else {
    wp_die('Payment not completed');
}
