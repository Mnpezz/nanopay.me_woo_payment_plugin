<?php
use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class WC_NanoPay_Gateway_Blocks_Support extends AbstractPaymentMethodType {
    private $gateway;
    protected $name = 'nanopay';

    public function initialize() {
        $this->settings = get_option("woocommerce_{$this->name}_settings", array());
        $this->gateway = new WC_NanoPay_Gateway();
    }

    public function is_active() {
        return $this->gateway->is_available();
    }

    public function get_payment_method_script_handles() {
        wp_register_script(
            'wc-nanopay-blocks-integration',
            plugins_url('build/index.js', dirname(__FILE__)),
            [
                'wc-blocks-registry',
                'wc-settings',
                'wp-element',
                'wp-html-entities',
                'wp-i18n',
            ],
            null,
            true
        );
        if (function_exists('wp_set_script_translations')) {
            wp_set_script_translations('wc-nanopay-blocks-integration');
        }
        return ['wc-nanopay-blocks-integration'];
    }

    public function get_payment_method_data() {
        return [
            'title' => $this->gateway->get_title(),
            'description' => $this->gateway->get_description(),
            'supports' => $this->gateway->supports,
            'paymentMethodId' => 'nanopay',
            'api_key' => $this->gateway->api_key,
        ];
    }
}
