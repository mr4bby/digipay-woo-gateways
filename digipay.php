<?php

/**
 * Plugin Name: Digipay Multi Gateways for WooCommerce
 * Description: Adds 4 Digipay-derived payment gateways to WooCommerce: BPG, CPG, Wallet, IPG. Processing and API methods are separated into include files. Supports sandbox/live mode and takes credentials from admin settings.
 * Version: 1.0.1
 * Author: Mr4bby
 * Author URI: mailto:mr4bby@gmail.com
 * Text Domain: digipay-multi-gateways
 */


if (!defined('ABSPATH')) {
    exit;
}

define('DIGIPAY_MG_PATH', plugin_dir_path(__FILE__));
define('DIGIPAY_MG_URL', plugin_dir_url(__FILE__));


add_action('plugins_loaded', 'digipay_register_gateways', 11);
function digipay_register_gateways()
{
    if (!class_exists('WC_Payment_Gateway')) return;
    require_once plugin_dir_path(__FILE__) . 'includes/class-digipay-api.php';
    require_once plugin_dir_path(__FILE__) . 'includes/class-digipay-callback.php';
    require_once plugin_dir_path(__FILE__) . 'includes/logger.php';

    require_once plugin_dir_path(__FILE__) . 'includes/gateways/class-wc-gateway-digipay-bpg.php';
    require_once plugin_dir_path(__FILE__) . 'includes/gateways/class-wc-gateway-digipay-cpg.php';
    require_once plugin_dir_path(__FILE__) . 'includes/gateways/class-wc-gateway-digipay-wallet.php';
    require_once plugin_dir_path(__FILE__) . 'includes/gateways/class-wc-gateway-digipay-ipg.php';

    add_filter('woocommerce_payment_gateways', function ($methods) {
        $methods[] = 'WC_Gateway_Digipay_BPG';
        $methods[] = 'WC_Gateway_Digipay_CPG';
        $methods[] = 'WC_Gateway_Digipay_Wallet';
        $methods[] = 'WC_Gateway_Digipay_IPG';
        return $methods;
    });
}

add_action('before_woocommerce_init', function () {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true
        );
    }
});

function digipay_mg_load_textdomain()
{
    load_plugin_textdomain('digipay-multi-gateways', false, dirname(plugin_basename(__FILE__)) . '/languages');
}
add_action('plugins_loaded', 'digipay_mg_load_textdomain');
