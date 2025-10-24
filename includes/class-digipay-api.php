<?php



if (!class_exists('Digipay_MG_API')) {
    class Digipay_MG_API
    {
        protected $env; // 'sandbox' or 'live'
        protected $settings; // gateway settings array

        protected $context = [];

        public function __construct($settings = array())
        {
            $this->settings = wp_parse_args($settings, array(
                'sandbox' => 'yes', // 'yes' = sandbox, 'no' = live
                'username' => '',
                'password' => '',
                'client_id' => '',
                'client_secret' => '',
            ));
            $this->context = ['source' => 'digipay_api'];

            $this->env = (!empty($this->settings['sandbox']) && $this->settings['sandbox'] === 'yes') ? 'sandbox' : 'live';
        }


        protected function base_url()
        {
            if ($this->env === 'sandbox') {
                return 'https://uat.mydigipay.info/digipay/api';
            }
            return 'https://api.mydigipay.com/digipay/api';
        }


        public function get_token()
        {
            $client_id = isset($this->settings['client_id']) ? $this->settings['client_id'] : '';
            $client_secret = isset($this->settings['client_secret']) ? $this->settings['client_secret'] : '';
            $username = isset($this->settings['username']) ? $this->settings['username'] : '';
            $password = isset($this->settings['password']) ? $this->settings['password'] : '';


            if (empty($client_id) || empty($client_secret) || empty($username) || empty($password)) {
                digipay_log('error', 'Digipay credentials are not set.', $this->context);
                return new WP_Error('digipay_mg_no_credentials', 'Digipay credentials are not set.');
            }


            $basic = base64_encode($client_id . ':' . $client_secret);


            $url = $this->base_url() . '/oauth/token';


            $args = array(
                'headers' => array(
                    'Authorization' => 'Basic ' . $basic,
                    'Accept' => 'application/json',
                ),
                'body' => array(
                    'username' => $username,
                    'password' => $password,
                    'grant_type' => 'password',
                ),
                'timeout' => 30,
            );

            digipay_log('info', 'Requesting Digipay token from ' . $url, $this->context);

            $resp = wp_remote_post($url, $args);

            if (is_wp_error($resp)) {
                digipay_log('error', 'Error requesting Digipay token: ' . $resp->get_error_message(), $this->context);

                return $resp;
            }


            $code = wp_remote_retrieve_response_code($resp);
            $body = wp_remote_retrieve_body($resp);
            digipay_log('info', 'Digipay token response code: ' . $code, $this->context);
            digipay_log('info', 'Digipay token response body: ' . $body, $this->context);

            $json = json_decode($body, true);



            if ($code >= 200 && $code < 300 && isset($json['access_token'])) {
                $token = $json['access_token'];

                setcookie('digipay_mg_access_token', $token, time() + 3600, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true);
                $_COOKIE['digipay_mg_access_token'] = $token;
                digipay_log('info', 'Digipay token retrieved successfully', $this->context);

                return $token;
            }



            digipay_log('error', 'Failed to retrieve Digipay token', $this->context);
            return new WP_Error('digipay_mg_token_error', 'Failed to get token: ' . $body);
        }

        public function init_payment($order, $gateway_type)
        {
            $token = $this->get_token();
            if (is_wp_error($token)) {
                digipay_log('error', 'Token retrieval failed', ['error' => $token->get_error_message()]);
                return $token;
            }

            $amount      = (int) round($order->get_total());
            $cellNumber  = (string) $order->get_billing_phone();
            $orderId  = (int) $order->get_id();
            $providerId = random_int(1000, 9999);

            $callbackUrl = home_url('/?wc-api=digipay_notify');

            $currency = get_woocommerce_currency();
            if (strtolower($currency) === 'toman' || strtolower($currency) === 'tmt' || strtolower($currency) === 'irt') {
                $amount *= 10;
            }

            $body = [
                'amount'      => $amount,
                'cellNumber'  => $cellNumber,
                'providerId'  => $orderId . $providerId,
                'callbackUrl' => $callbackUrl,
            ];

            $order->update_meta_data('_digipay_provider_id', $body['providerId']);
            $order->save_meta_data();

            $g = strtolower($gateway_type);
            digipay_log('debug', 'Initializing Digipay payment', [
                'gateway_type' => $g,
                'order_id'     => $order->get_id(),
                'amount'       => $amount,
            ]);

            if ($g === 'ipg') {
                $body['additionalInfo'] = ['preferredGateway' => 2];
            } elseif ($g === 'wallet') {
                $body['additionalInfo'] = ['preferredGateway' => 0];
            } elseif (in_array($g, ['cpg', 'bpg'], true)) {
                $items = [];

                $get_product_type = function ($product) {
                    $pt = (int) $product->get_meta('_product_type');
                    return $pt > 0 ? $pt : 2;
                };

                foreach ($order->get_items() as $order_item) {
                    $product = $order_item->get_product();
                    if (! $product) {
                        continue;
                    }

                    $sellerId    = (string) $product->get_meta('_seller_id');
                    $supplierId  = (string) $product->get_meta('_supplier_id');
                    $productCode = (string) $product->get_sku() ?: (string) $product->get_id();
                    $brand       = (string) $product->get_attribute('brand')
                        ?: (string) $product->get_attribute('pa_brand')
                        ?: (string) $product->get_meta('_brand');

                    $productType = $get_product_type($product);
                    $count       = (int) $order_item->get_quantity();

                    $categoryId = '';
                    $terms = wp_get_post_terms($product->get_id(), 'product_cat');
                    if (!is_wp_error($terms) && !empty($terms)) {
                        $categoryId = (string) $terms[0]->slug;
                    }

                    $items[] = [
                        'sellerId'    => $sellerId,
                        'supplierId'  => $supplierId,
                        'productCode' => $productCode,
                        'brand'       => $brand,
                        'productType' => $productType,
                        'count'       => $count,
                        'categoryId'  => $categoryId,
                    ];
                }

                $basketId = 'basket-' . $order->get_id() . '-' . time();

                $body['basketDetailsDto'] = [
                    'items'    => $items,
                    'basketId' => $basketId,
                ];

                digipay_log('debug', 'Basket details prepared', [
                    'basket_id' => $basketId,
                    'item_count' => count($items),
                ]);
            } else {
                digipay_log('error', 'Invalid payment gateway type', ['gateway_type' => $g]);
            }

            $args = [
                'headers' => [
                    'Authorization'    => 'Bearer ' . $token,
                    'Agent'            => 'WEB',
                    'Digipay-Version'  => '2022-02-02',
                    'Accept'           => 'application/json',
                    'Content-Type'     => 'application/json',
                ],
                'body'    => wp_json_encode($body),
                'timeout' => 30,
            ];

            $log_args = $args;
            if (isset($log_args['headers']['Authorization'])) {
                $log_args['headers']['Authorization'] = '***masked***';
            }

            digipay_log('info', 'Sending Digipay payment request', [
                'url'  => $this->base_url() . '/tickets/business?type=11',
                'args' => $log_args,
            ]);

            $resp = wp_remote_post($this->base_url() . '/tickets/business?type=11', $args);

            if (is_wp_error($resp)) {
                digipay_log('error', 'Digipay request failed', ['error' => $resp->get_error_message()]);
                return $resp;
            }

            $response_code = wp_remote_retrieve_response_code($resp);
            $response_body = wp_remote_retrieve_body($resp);

            digipay_log('debug', 'Digipay response received', [
                'code' => $response_code,
                'body' => $response_body,
            ]);

            $result = $this->handle_digipay_response($resp);

            if (is_wp_error($result)) {
                digipay_log('error', 'Digipay response handling error', [
                    'error'   => $result->get_error_message(),
                    'details' => $result->get_error_data(),
                ]);
                return $result;
            }

            digipay_log('info', 'Digipay payment initialized successfully', [
                'order_id' => $order->get_id(),
                'gateway'  => $gateway_type,
                'result'   => $result,
            ]);

            return $result;
        }



        private function handle_digipay_response($resp)
        {
            if (is_wp_error($resp)) {
                return $resp;
            }

            $http_code = (int) wp_remote_retrieve_response_code($resp);
            $body_raw  = wp_remote_retrieve_body($resp);
            $data      = json_decode($body_raw, true);

            switch ($http_code) {
                case 200:
                case 201:
                    break;

                case 400:
                    return new WP_Error('digipay_http_400', 'پارامترهای ورودی نامعتبر است.', [
                        'http_code' => $http_code,
                        'body'      => $data ?? $body_raw,
                    ]);

                case 401:
                case 403:
                    return new WP_Error('digipay_http_auth', 'خطا در احراز هویت و دسترسی.', [
                        'http_code' => $http_code,
                        'body'      => $data ?? $body_raw,
                    ]);

                case 422:
                    return new WP_Error('digipay_http_422', 'خطای بیزینسی .', [
                        'http_code' => $http_code,
                        'body'      => $data ?? $body_raw,
                    ]);

                case 500:
                    return new WP_Error('digipay_http_500', 'خطای داخلی سرور (خطای ۵۰۰).', [
                        'http_code' => $http_code,
                        'body'      => $data ?? $body_raw,
                    ]);

                default:
                    return new WP_Error('digipay_http_' . $http_code, 'پاسخ غیرمنتظره از سرور: ' . $http_code, [
                        'http_code' => $http_code,
                        'body'      => $data ?? $body_raw,
                    ]);
            }


            $api_code_map = [
                0    => 'عملیات با موفقیت انجام شد',
                1054 => 'اطلاعات ورودی اشتباه می باشد',
                9000 => 'اطلاعات خرید یافت نشد',
                9001 => 'توکن پرداخت معتبر نمی باشد',
                9003 => 'خرید مورد نظر منقضی شده است',
                9004 => 'خرید مورد نظر درحال انجام است',
                9005 => 'خرید قابل پرداخت نمی باشد',
                9006 => 'خطا در برقراری ارتباط با درگاه پرداخت',
                9007 => 'خرید با موفقیت انجام نشده است',
                9008 => 'این خرید با داده های متفاوتی قبلا ثبت شده است',
                9009 => 'محدوده زمانی تایید تراکنش گذشته است',
                9010 => 'تایید خرید ناموفق بود',
                9011 => 'نتیجه تایید خرید نامشخص است',
                9012 => 'وضعیت خرید برای این درخواست صحیح نمی باشد',
                9030 => 'ورود شماره همراه برای کاربران ثبت نام شده الزامی است',
                9031 => 'اعطای تیکت برای کاربر مورد نظر امکان پذیر نمی‌باشد',
            ];

            if (is_array($data) && array_key_exists('code', $data)) {
                $api_code = (int) $data['code'];

                if ($api_code !== 0) {
                    $msg = isset($api_code_map[$api_code]) ? $api_code_map[$api_code] : 'خطای نامشخص از سرویس دیجی‌پی';
                    $server_msg = isset($data['message']) ? $data['message'] : (isset($data['msg']) ? $data['msg'] : null);

                    return new WP_Error('digipay_api_' . $api_code, $msg, [
                        'api_code'    => $api_code,
                        'api_message' => $server_msg,
                        'body'        => $data,
                    ]);
                }

                return $data;
            }

            return $data !== null ? $data : $body_raw;
        }



        protected function get_callback_url($order, $gateway_type)
        {

            return add_query_arg(array(
                'digipay_mg' => 'callback',
                'gateway' => $gateway_type,
                'order_id' => $order->get_id(),
            ), home_url('/'));
        }
    }
}
