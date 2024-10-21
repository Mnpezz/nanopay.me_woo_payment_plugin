<?php
class NanoPay_Admin {
    public function __construct() {
        add_filter('woocommerce_payment_gateways', array($this, 'add_nanopay_gateway'));
    }

    public function add_nanopay_gateway($gateways) {
        $gateways[] = 'NanoPay_Gateway';
        return $gateways;
    }
}