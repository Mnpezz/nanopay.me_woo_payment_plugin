<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class WC_NanoPay_Gateway extends WC_Payment_Gateway {
    public function __construct() {
        error_log('WC_NanoPay_Gateway constructor called');
        $this->id = 'nanopay';
        $this->icon = plugin_dir_url(dirname(__FILE__)) . 'assets/nanopay-icon.png';
        $this->has_fields = false;
        $this->method_title = 'NanoPay.me';
        $this->method_description = 'Pay with Nano using NanoPay.me';

        $this->supports = array('products');

        $this->init_form_fields();
        $this->init_settings();

        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->enabled = $this->get_option('enabled');
        $this->testmode = 'yes' === $this->get_option('testmode');
        $this->api_key = $this->testmode ? $this->get_option('test_api_key') : $this->get_option('api_key');
        $this->nano_address = $this->get_option('nano_address');

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_api_nanopay_callback', array($this, 'check_nanopay_response'));

        // Add action for checking payment status
        add_action('woocommerce_order_status_pending', array($this, 'schedule_payment_check'));
        add_action('nanopay_check_payment_status', array($this, 'check_payment_status'), 10, 2);

        error_log('WC_NanoPay_Gateway initialized with ID: ' . $this->id);
        error_log('WC_NanoPay_Gateway settings: ' . print_r($this->get_sanitized_settings(), true));
    }

    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title'       => 'Enable/Disable',
                'label'       => 'Enable NanoPay.me Payment',
                'type'        => 'checkbox',
                'description' => '',
                'default'     => 'no'
            ),
            'title' => array(
                'title'       => 'Title',
                'type'        => 'text',
                'description' => 'This controls the title which the user sees during checkout.',
                'default'     => 'NanoPay.me',
                'desc_tip'    => true,
            ),
            'description' => array(
                'title'       => 'Description',
                'type'        => 'textarea',
                'description' => 'This controls the description which the user sees during checkout.',
                'default'     => 'Pay with Nano using NanoPay.me',
            ),
            'nano_address' => array(
                'title'       => 'Nano Address',
                'type'        => 'text',
                'description' => 'Enter your Nano address to receive payments.',
                'default'     => '',
                'desc_tip'    => true,
            ),
            'testmode' => array(
                'title'       => 'Test mode',
                'label'       => 'Enable Test Mode',
                'type'        => 'checkbox',
                'description' => 'Place the payment gateway in test mode.',
                'default'     => 'yes',
                'desc_tip'    => true,
            ),
            'test_api_key' => array(
                'title'       => 'Test API Key',
                'type'        => 'password'
            ),
            'api_key' => array(
                'title'       => 'Live API Key',
                'type'        => 'password'
            ),
        );
    }

    public function process_payment($order_id) {
        $order = wc_get_order($order_id);
        
        $response = wp_remote_post('https://api.nanopay.me/invoices', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode(array(
                'title' => 'Order #' . $order->get_order_number(),
                'description' => 'Payment for order at ' . get_bloginfo('name'),
                'price' => floatval($order->get_total()),
                'recipient_address' => $this->nano_address,
                'metadata' => array(
                    'order_id' => $order_id
                ),
                'redirect_url' => $this->get_return_url($order)
            ))
        ));

        if (is_wp_error($response)) {
            error_log('NanoPay payment error: ' . $response->get_error_message());
            wc_add_notice('Payment error: ' . $response->get_error_message(), 'error');
            return array(
                'result' => 'failure',
                'messages' => $response->get_error_message()
            );
        }

        $body = json_decode(wp_remote_retrieve_body($response));

        if (isset($body->pay_url) && isset($body->id)) {
            // Store the invoice ID in the order meta for later use
            $order->update_meta_data('nanopay_invoice_id', $body->id);
            $order->update_status('pending', 'Awaiting payment via NanoPay.me');
            $order->save();

            // Schedule the first payment check
            wp_schedule_single_event(time() + 60, 'nanopay_check_payment_status', array($order_id, $body->id));

            return array(
                'result' => 'success',
                'redirect' => $body->pay_url
            );
        } else {
            error_log('NanoPay payment error: Unable to create invoice. Response: ' . $this->sanitize_response($body));
            wc_add_notice('Payment error: Unable to create invoice', 'error');
            return array(
                'result' => 'failure',
                'messages' => 'Unable to create invoice'
            );
        }
    }

    public function check_nanopay_response() {
        $invoice_id = isset($_GET['invoice_id']) ? sanitize_text_field($_GET['invoice_id']) : '';

        if (empty($invoice_id)) {
            wp_die('Invalid request', 'NanoPay Error', array('response' => 400));
        }

        $this->update_order_status($invoice_id);
    }

    public function check_payment_status($order_id, $invoice_id) {
        $this->update_order_status($invoice_id);
    }

    private function update_order_status($invoice_id) {
        $response = wp_remote_get('https://api.nanopay.me/invoices/' . $invoice_id, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_key
            )
        ));

        if (is_wp_error($response)) {
            error_log('NanoPay payment verification failed: ' . $response->get_error_message());
            return;
        }

        $body = json_decode(wp_remote_retrieve_body($response));

        error_log('NanoPay invoice status update: ' . $this->sanitize_response($body));

        if ($body->status === 'paid') {
            $order_id = $body->metadata->order_id;
            $order = wc_get_order($order_id);
            
            if ($order && $order->get_status() !== 'processing') {
                $order->payment_complete();
                $order->update_status('processing', 'Payment completed via NanoPay.me. Invoice ID: ' . $invoice_id);
                $order->save();
            }
        } elseif ($body->status === 'pending') {
            // If still pending, schedule another check in 5 minutes
            wp_schedule_single_event(time() + 300, 'nanopay_check_payment_status', array($body->metadata->order_id, $invoice_id));
        }
    }

    public function schedule_payment_check($order_id) {
        $invoice_id = get_post_meta($order_id, 'nanopay_invoice_id', true);
        if ($invoice_id) {
            wp_schedule_single_event(time() + 60, 'nanopay_check_payment_status', array($order_id, $invoice_id));
        }
    }

    public function is_available() {
        $is_available = parent::is_available();
        error_log('NanoPay Gateway availability: ' . ($is_available ? 'true' : 'false'));
        return $is_available;
    }

    // Add this new method to the class
    private function get_sanitized_settings() {
        $sanitized_settings = $this->settings;
        $sensitive_fields = ['api_key', 'test_api_key', 'nano_address'];
        
        foreach ($sensitive_fields as $field) {
            if (isset($sanitized_settings[$field])) {
                $sanitized_settings[$field] = '********';
            }
        }
        
        return $sanitized_settings;
    }

    // Add this new method to the class
    private function sanitize_response($response) {
        $sanitized = clone $response;
        if (isset($sanitized->recipient_address)) {
            $sanitized->recipient_address = '********';
        }
        // Add any other fields that might contain sensitive information
        return print_r($sanitized, true);
    }
}
