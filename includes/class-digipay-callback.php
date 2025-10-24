<?php


function digipay_handle_gateway_response()
{
    $_POST = wp_unslash($_POST);

    $context = array('source' => 'digipay-callback');

    digipay_log('info', '==== Digipay callback received ==== (entry)', $context);
    digipay_log('debug', 'Raw POST data', array_merge($context, ['post' => $_POST]));

    $send_response = function ($title, $message, $sub = '', $redirect = '', $http_code = 200, $delay_ms = 3000, $img = '', $color = '', $order_id = 0) {
        if (defined('REST_REQUEST') && REST_REQUEST) {
            $payload = array(
                'title'   => $title,
                'message' => $message,
                'sub'     => $sub,
                'redirect' => $redirect,
                'img'     => $img,
                'color'   => $color,
            );
            return new WP_REST_Response($payload, $http_code);
        }

        if (!empty($redirect)) {
            $safe = esc_url_raw($redirect);
            wp_safe_redirect($safe);
            exit;
        }

        $notice_data = array(
            'title'   => $title,
            'message' => $message,
            'sub'     => $sub,
            'type'    => ($http_code >= 200 && $http_code < 300) ? 'success' : 'error',
            'img'     => $img,
            'color'   => $color,
            'time'    => time(),
        );

        if (!empty($order_id) && is_numeric($order_id)) {
            $transient_key = 'digipay_notice_order_' . intval($order_id);
            set_transient($transient_key, $notice_data, MINUTE_IN_SECONDS * 5);

            $checkout_url = function_exists('wc_get_checkout_url') ? wc_get_checkout_url() : get_permalink(get_option('woocommerce_checkout_page_id'));
            $redirect_url = add_query_arg('digipay_notice_order', intval($order_id), $checkout_url);
            wp_safe_redirect(esc_url_raw($redirect_url));
            exit;
        }

        $transient_key = 'digipay_notice_general';
        set_transient($transient_key, $notice_data, MINUTE_IN_SECONDS * 5);

        $checkout_url = function_exists('wc_get_checkout_url') ? wc_get_checkout_url() : get_permalink(get_option('woocommerce_checkout_page_id'));
        $redirect_url = add_query_arg('digipay_notice', '1', $checkout_url);
        wp_safe_redirect(esc_url_raw($redirect_url));
        exit;
    };

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        digipay_log('warning', 'Invalid HTTP method for Digipay callback: ' . $_SERVER['REQUEST_METHOD'], $context);
        return $send_response(
            'درخواست نامعتبر',
            'اطلاعات پرداخت ناقص یا نامعتبر است. لطفاً دوباره تلاش کنید یا با پشتیبانی تماس بگیرید.',
            'تا چند لحظه دیگر به فروشگاه منتقل خواهید شد.',
            '',
            405,
            4000,
            DIGIPAY_MG_URL . 'assets/images/n15f.png',
            '#e74c3c'
        );
    }

    $amount       = isset($_POST['amount']) ? floatval($_POST['amount']) : null;
    $providerId   = isset($_POST['providerId']) ? sanitize_text_field($_POST['providerId']) : null;
    $trackingCode = isset($_POST['trackingCode']) ? sanitize_text_field($_POST['trackingCode']) : null;
    $rrn          = isset($_POST['rrn']) ? sanitize_text_field($_POST['rrn']) : '';
    $psp_raw      = isset($_POST['psp']) ? $_POST['psp'] : '';
    $isCredit     = isset($_POST['isCredit']) ? filter_var($_POST['isCredit'], FILTER_VALIDATE_BOOLEAN) : false;
    $result       = isset($_POST['result']) ? sanitize_text_field($_POST['result']) : null;
    $type         = isset($_POST['type']) ? sanitize_text_field($_POST['type']) : '';


    // $providerId = substr($providerId, 0, -4);
    $currency = get_woocommerce_currency();
    if (strtolower($currency) === 'toman' || strtolower($currency) === 'tmt' || strtolower($currency) === 'irt') {
        $amount = floatval(substr($amount, 0, -1));
    }

    if (empty($amount) || empty($providerId) || empty($trackingCode) || empty($result)) {
        digipay_log('error', 'Missing required fields in Digipay callback', array_merge($context, ['post' => $_POST]));
        return $send_response(
            'درخواست نامعتبر',
            'اطلاعات پرداخت ناقص یا نامعتبر است. لطفاً دوباره تلاش کنید یا با پشتیبانی تماس بگیرید.',
            'تا چند لحظه دیگر به فروشگاه منتقل خواهید شد.',
            '',
            400,
            4000,
            DIGIPAY_MG_URL . 'assets/images/n15f.png',
            '#e74c3c'
        );
    }
    global $wpdb;
    $order = null;
    $order_ids = $wpdb->get_col($wpdb->prepare(
        "
        SELECT order_id 
        FROM wp_wc_orders_meta 
        WHERE meta_key = %s
        AND meta_value = %s
        ",
        '_digipay_provider_id',
        $providerId
    ));
    if (empty($order_ids)) {
        $order_ids = (array) substr($providerId, 0, -4);
    }

    if (! empty($order_ids)) {
        $order = wc_get_order($order_ids[0]);
        digipay_log('info', 'Order found by _digipay_provider_id meta', array_merge($context, ['order_id' => $order_ids[0]]));
    } else {
        if (ctype_digit((string)$providerId)) {
            $maybe_order = wc_get_order(intval($providerId));
            if ($maybe_order) {
                $order = $maybe_order;
                digipay_log('info', 'Order found by numeric providerId as order ID', array_merge($context, ['order_id' => $maybe_order->get_id()]));
            }
        }
    }

    if (! $order) {
        digipay_log('error', 'Order not found for providerId=' . $providerId, $context);
        return $send_response(
            'سفارش پیدا نشد',
            'سفارشی مطابق اطلاعات پرداخت پیدا نشد. ممکن است شناسه تراکنش اشتباه باشد یا سفارش قبلاً حذف شده باشد.',
            'تا چند لحظه دیگر به فروشگاه منتقل خواهید شد.',
            '',
            404,
            4000,
            DIGIPAY_MG_URL . 'assets/images/n15f.png',
            '#e74c3c'
        );
    }

    $order_id = $order->get_id();

    $lock_key = 'digipay_lock_' . $order_id;
    $got_lock = wp_cache_add($lock_key, time(), 'digipay', 5);
    if (! $got_lock) {
        digipay_log('warning', 'Concurrent callback detected for order ' . $order_id, $context);
        if (defined('DIGIPAY_FORCE_RAW_SUCCESS') && DIGIPAY_FORCE_RAW_SUCCESS === true) {
            status_header(200);
            echo 'SUCCESS';
            exit;
        }
        return $send_response(
            'در حال بررسی',
            'این تراکنش در حال پردازش است. لطفاً چند لحظه دیگر بررسی کنید.',
            '',
            $order->get_checkout_order_received_url(),
            200,
            2500,
            DIGIPAY_MG_URL . 'assets/images/n15w.png',
            '#f39c12'
        );
    }

    $order->update_meta_data('_digipay_provider_id', $providerId);
    $order->update_meta_data('_digipay_tracking_code', $trackingCode);
    if ($rrn) {
        $order->update_meta_data('_digipay_rrn', $rrn);
    }

    $type_int = is_numeric($type) ? intval($type) : null;

    $psp_code_raw = null;
    $psp_maped    = null;

    if ($psp_raw !== '') {
        $decoded = json_decode($psp_raw, true);
        if (is_array($decoded)) {
            if (!empty($decoded['code'])) {
                $psp_code_raw = sanitize_text_field($decoded['code']);
            } elseif (!empty($decoded['id'])) {
                $psp_code_raw = sanitize_text_field($decoded['id']);
            } elseif (!empty($decoded['psp'])) {
                $psp_code_raw = sanitize_text_field($decoded['psp']);
            } else {
                foreach ($decoded as $val) {
                    if (is_scalar($val)) {
                        $psp_code_raw = sanitize_text_field($val);
                        break;
                    }
                }
            }
        } else {
            $psp_code_raw = sanitize_text_field($psp_raw);
        }
    }

    $type_map = array(
        0  => 'IPG',
        11 => 'WALLET',
        5  => 'CREDIT',
        13 => 'BNPL',
        24 => 'CREDIT-CARD',
    );

    $psp_map = array(
        '001' => 'SAMAN',
        '002' => 'PARSIAN',
        '003' => 'MELLAT',
        '004' => 'ENOVIN',
        '005' => 'PASARGAD',
        '006' => 'FANAVA',
        '007' => 'MELLI',
        '008' => 'IRKISH',
    );

    $type_code = isset($type_map[$type_int]) ? $type_map[$type_int] : null;
    $psp_code  = ($psp_code_raw && isset($psp_map[$psp_code_raw])) ? $psp_map[$psp_code_raw] : $psp_code_raw;

    $order->update_meta_data('_digipay_psp', $psp_code);
    $order->update_meta_data('_digipay_is_credit', $isCredit ? 'yes' : 'no');
    $order->update_meta_data('_digipay_type', $type_code);
    $order->save_meta_data();

    $order_total = floatval($order->get_total());
    $tolerance = 0.01;
    if (abs($order_total - $amount) > $tolerance) {
        digipay_log('warning', 'Amount mismatch', array_merge($context, [
            'order_total' => $order_total,
            'callback_amount' => $amount
        ]));
        $order->add_order_note('Digipay: مقدار پرداخت با مجموع سفارش همخوانی ندارد. مقدار دریافتی: ' . $amount . ' ، مجموع سفارش: ' . $order_total);

        wp_cache_delete($lock_key, 'digipay');

        return $send_response(
            'پرداخت نامعتبر',
            'مبلغ پرداخت شده با سفارش مطابقت ندارد. لطفاً با پشتیبانی تماس بگیرید.',
            '',
            $order->get_checkout_order_received_url(),
            400,
            5000,
            DIGIPAY_MG_URL . 'assets/images/n15f.png',
            '#e74c3c'
        );
    }

    if ($order->get_meta('_digipay_handled')) {
        digipay_log('info', 'Callback already handled for order', $context);
        wp_cache_delete($lock_key, 'digipay');

        if (defined('DIGIPAY_FORCE_RAW_SUCCESS') && DIGIPAY_FORCE_RAW_SUCCESS === true) {
            status_header(200);
            echo 'SUCCESS';
            exit;
        }

        return $send_response(
            'تراکنش قبلاً پردازش شده',
            'این تراکنش قبلاً ثبت و پردازش شده است.',
            'در حال انتقال به صفحهٔ سفارش...',
            $order->get_checkout_order_received_url(),
            200,
            2500,
            DIGIPAY_MG_URL . 'assets/images/n15t.png',
            '#2ecc71'
        );
    }

    if (strtoupper($result) === 'SUCCESS') {
        $verify = digipay_verify_purchase($trackingCode, $providerId, $type_int);
        digipay_log('info', 'Digipay reported SUCCESS — verifying...', $context);

        if (is_wp_error($verify) || (int)$verify['status'] !== 200) {
            digipay_log('error', 'Verification failed', array_merge($context, ['verify' => $verify]));

            return $send_response(
                'خطا در تأیید پرداخت',
                'تأیید پرداخت از سرور دیجی‌پی با خطا مواجه شد. لطفاً مجدداً تلاش کنید یا با پشتیبانی تماس بگیرید.',
                'تا چند لحظه دیگر به فروشگاه منتقل خواهید شد.',
                '',
                500,
                4000,
                DIGIPAY_MG_URL . 'assets/images/n15f.png',
                '#e67e22'
            );
        }

        if (! $order->get_meta('_digipay_stock_reduced')) {
            try {
                wc_reduce_stock_levels($order_id);
                $order->update_meta_data('_digipay_stock_reduced', 'yes');
                $order->save_meta_data();
                digipay_log('info', 'Stock reduced successfully', $context);
            } catch (Exception $e) {
                digipay_log('error', 'Error reducing stock: ' . $e->getMessage(), $context);
            }
        }

        try {
            $order->set_transaction_id($trackingCode);
            $order->payment_complete($trackingCode);
            $order->add_order_note('Digipay: پرداخت موفق (tracking: ' . $trackingCode . ')');
            digipay_log('info', 'Payment completed successfully', $context);
        } catch (Exception $e) {
            digipay_log('error', 'Error completing order: ' . $e->getMessage(), $context);
        }

        $order->update_meta_data('_digipay_handled', 'yes');
        $order->save_meta_data();

        wp_cache_delete($lock_key, 'digipay');

        if (defined('DIGIPAY_FORCE_RAW_SUCCESS') && DIGIPAY_FORCE_RAW_SUCCESS === true) {
            status_header(200);
            echo 'SUCCESS';
            exit;
        }

        return $send_response(
            'پرداخت موفق',
            'پرداخت با موفقیت انجام شد. سفارش شما ثبت و تکمیل گردید.',
            'تا چند لحظه دیگر به صفحهٔ سفارش شما منتقل خواهید شد.',
            $order->get_checkout_order_received_url(),
            200,
            3500,
            DIGIPAY_MG_URL . 'assets/images/n15t.png',
            '#2ecc71'
        );
    } else {
        digipay_log('warning', 'Digipay payment failed', array_merge($context, [
            'result' => $result,
            'trackingCode' => $trackingCode,
        ]));

        $order->update_meta_data('_digipay_handled', 'yes');
        $order->save_meta_data();

        $order->update_status('failed', 'Digipay: پرداخت ناموفق (result=' . $result . ', tracking=' . $trackingCode . ')');
        $order->add_order_note('Digipay: پرداخت ناموفق — result=' . $result . ' tracking=' . $trackingCode);

        wp_cache_delete($lock_key, 'digipay');

        return $send_response(
            'پرداخت ناموفق',
            'پرداخت انجام نشد یا توسط بانک رد شد. اگر کسر وجه صورت گرفته لطفاً با پشتیبانی تماس بگیرید.',
            'تا چند لحظه دیگر به فروشگاه منتقل خواهید شد.',
            '',
            200,
            4000,
            DIGIPAY_MG_URL . 'assets/images/n15f.png',
            '#e74c3c'
        );
    }
}

add_action('woocommerce_api_digipay_notify', 'digipay_handle_gateway_response');

add_action('rest_api_init', function () {
    register_rest_route('digipay/v1', '/notify', array(
        'methods'  => 'POST',
        'callback' => 'digipay_handle_gateway_response',
        'permission_callback' => '__return_true',
    ));
});

function digipay_maybe_show_transient_notice()
{
    if (! function_exists('is_checkout') || ! is_checkout()) {
        return;
    }

    if (isset($_GET['digipay_notice_order'])) {
        $order_id = intval($_GET['digipay_notice_order']);
        if ($order_id > 0) {
            $key = 'digipay_notice_order_' . $order_id;
            $data = get_transient($key);
            if ($data && is_array($data)) {
                if (function_exists('wc_add_notice')) {
                    wc_add_notice(wp_strip_all_tags($data['message']), isset($data['type']) ? $data['type'] : 'error');
                } else {
                    echo '<div class="woocommerce-error">' . esc_html($data['message']) . '</div>';
                }
                delete_transient($key);
            }
        }
    }

    if (isset($_GET['digipay_notice'])) {
        $key = 'digipay_notice_general';
        $data = get_transient($key);
        if ($data && is_array($data)) {
            if (function_exists('wc_add_notice')) {
                wc_add_notice(wp_strip_all_tags($data['message']), isset($data['type']) ? $data['type'] : 'error');
            } else {
                echo '<div class="woocommerce-error">' . esc_html($data['message']) . '</div>';
            }
            delete_transient($key);
        }
    }
}
add_action('template_redirect', 'digipay_maybe_show_transient_notice', 5);


/**
 * Verify Digipay Purchase via API
 *
 * @param string $trackingCode
 * @param string $providerId
 * @param int $type
 * @param string $token  (Bearer token)
 * @return array|WP_Error
 */
function digipay_verify_purchase($trackingCode, $providerId, $type)
{
    $token = sanitize_text_field($_COOKIE['digipay_mg_access_token']);


    if (empty($token)) {
        return new WP_Error('missing_token', 'توکن احراز هویت یافت نشد.');
    }

    $api_url = "https://digipay.ir/digipay/api/purchases/verify?type={$type}";

    $body = [
        'trackingCode' => $trackingCode,
        'providerId'   => $providerId,
    ];

    $args = [
        'method'  => 'POST',
        'timeout' => 20,
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'Content-Type'  => 'application/json',
        ],
        'body' => wp_json_encode($body),
    ];

    $response = wp_remote_post($api_url, $args);

    if (isset($_COOKIE['digipay_mg_access_token'])) {
        setcookie('digipay_mg_access_token', '', time() - 3600, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true);
        unset($_COOKIE['digipay_mg_access_token']);
    }

    if (is_wp_error($response)) {
        return $response;
    }

    $status_code   = wp_remote_retrieve_response_code($response);
    $response_body = json_decode(wp_remote_retrieve_body($response), true);

    return [
        'status' => $status_code,
        'body'   => $response_body,
    ];
}
