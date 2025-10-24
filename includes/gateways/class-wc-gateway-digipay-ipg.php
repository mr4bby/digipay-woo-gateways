<?php
if (!class_exists('WC_Gateway_Digipay_IPG')) {
    class WC_Gateway_Digipay_IPG extends WC_Gateway_Digipay_BPG
    {
        public function __construct()
        {
            parent::__construct();

            $this->id = 'digipay_ipg';
            $this->method_title = __('Digipay IPG', 'digipay-multi-gateways');
            $this->method_description = __('پرداخت مستقیم از درگاه پرداخت', 'digipay-multi-gateways');
            $this->icon = DIGIPAY_MG_URL . 'assets/images/5.svg';

            $this->init_form_fields();
            $this->init_settings();

            $this->title = $this->get_option('title', $this->method_title);
            $this->description = $this->get_option('description', $this->method_description);

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            add_action('woocommerce_api_digipay_mg_callback', array($this, 'check_response'));
        }

        public function init_form_fields()
        {
            parent::init_form_fields();
            $this->form_fields['title']['default'] = __('پرداخت مستقیم', 'digipay-multi-gateways');
        }

        public function process_payment($order_id)
        {
            $order = wc_get_order($order_id);

            $settings = array(
                'sandbox' => $this->get_option('sandbox', 'no'),
                'username' => $this->get_option('username'),
                'password' => $this->get_option('password'),
                'client_id' => $this->get_option('client_id'),
                'client_secret' => $this->get_option('client_secret'),
            );

            $api = new Digipay_MG_API($settings);
            $resp = $api->init_payment($order, 'ipg');

            if (is_wp_error($resp)) {
                wc_add_notice($resp->get_error_message(), 'error');
                return array('result' => 'fail');
            }

            if (is_array($resp) && ! empty($resp['redirectUrl'])) {

                return array(
                    'result' => 'success',
                    'redirect' => esc_url_raw($resp['redirectUrl'])
                );
            }

            wc_add_notice(__('خطا در پردازش تراکنش درگاه', 'digipay-multi-gateways'), 'error');
            return array('result' => 'fail');
        }
    }
}
