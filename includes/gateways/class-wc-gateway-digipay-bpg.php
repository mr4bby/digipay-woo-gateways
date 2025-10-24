<?php
if (!class_exists('WC_Gateway_Digipay_BPG')) {
    class WC_Gateway_Digipay_BPG extends WC_Payment_Gateway
    {
        public function __construct()
        {
            $this->id = 'digipay_bpg';
            $this->method_title = __('Digipay BPG', 'digipay-multi-gateways');
            $this->method_description = __('خرید اعتباری دیجی پی', 'digipay-multi-gateways');
            $this->icon = DIGIPAY_MG_URL . 'assets/images/1.svg';

            $this->init_form_fields();
            $this->init_settings();

            $this->title = $this->get_option('title', $this->method_title);
            $this->description = $this->get_option('description', 'پرداخت اعتباری با دیجی پی');

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            add_action('woocommerce_api_digipay_mg_callback', array($this, 'check_response'));
        }

        public function init_form_fields()
        {
            $this->form_fields = array(
                'enabled' => array(
                    'title' => __('فعال سازی/غیرفعال', 'digipay-multi-gateways'),
                    'type' => 'checkbox',
                    'label' => __('فعال باشد', 'digipay-multi-gateways'),
                    'default' => 'no',
                ),
                'title' => array(
                    'title' => __('عنوان', 'digipay-multi-gateways'),
                    'type' => 'text',
                    'default' => __('پرداخت اعتباری', 'digipay-multi-gateways'),
                ),
                'description' => array(
                    'title' => __('توضیحات', 'digipay-multi-gateways'),
                    'type' => 'textarea',
                    'default' => '',
                ),
                'sandbox' => array(
                    'title' => __('محیط تستی', 'digipay-multi-gateways'),
                    'type' => 'checkbox',
                    'label' => __('استفاده از محیط تستی', 'digipay-multi-gateways'),
                    'default' => 'yes',
                ),
                'username' => array('title' => 'username', 'type' => 'text', 'default' => ''),
                'password' => array('title' => 'password', 'type' => 'password', 'default' => ''),
                'client_id' => array('title' => 'client_id', 'type' => 'text', 'default' => ''),
                'client_secret' => array('title' => 'client_secret', 'type' => 'password', 'default' => ''),
            );
        }
        public function admin_options()
        {
            echo '<h3>' . esc_html($this->method_title) . '</h3>';
            echo '<p>' . esc_html($this->method_description) . '</p>';
            echo '<table class="form-table">';
            $this->generate_settings_html();
            echo '</table>';
        }


        public function process_payment($order_id)
        {
            $order = wc_get_order($order_id);


            $settings = array(
                'sandbox' => $this->get_option('sandbox', 'yes'),
                'username' => $this->get_option('username'),
                'password' => $this->get_option('password'),
                'client_id' => $this->get_option('client_id'),
                'client_secret' => $this->get_option('client_secret'),
            );


            $api = new Digipay_MG_API($settings);
            $resp = $api->init_payment($order, 'bpg');


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

            wc_add_notice(__('خطا در پردازش تراکنش کیف پول', 'digipay-multi-gateways'), 'error');
            return array('result' => 'fail');
        }
    }
}
