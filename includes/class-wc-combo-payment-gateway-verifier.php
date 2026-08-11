<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Gateway-specific payment verification handlers.
 */
class WC_Combo_Payment_Gateway_Verifier
{
    /**
     * @param object $log Callback log row.
     * @return array{success:bool,message:string,data:array}
     */
    public static function verify($log)
    {
        $handlers = self::get_handlers();
        $gateway_id = isset($log->gateway_id) ? $log->gateway_id : '';

        if (!isset($handlers[$gateway_id]) || !is_callable($handlers[$gateway_id])) {
            return array(
                'success' => false,
                'message' => sprintf(
                    __('No verification handler is registered for gateway "%s".', 'woocommerce-combo-product'),
                    $gateway_id
                ),
                'data' => array(),
            );
        }

        return call_user_func($handlers[$gateway_id], $log);
    }

    /**
     * Verify a WooCommerce order against its payment gateway and store the result.
     *
     * @param int   $order_id Order ID.
     * @param array $args Optional args: skip_if_logged, start_date, end_date, gateway_id.
     * @return array{success:bool,message:string,data:array,log_id:int,skipped?:bool}
     */
    public static function verify_order($order_id, $args = array())
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            return array(
                'success' => false,
                'message' => __('Order not found.', 'woocommerce-combo-product'),
                'data' => array(),
                'log_id' => 0,
            );
        }

        $gateway_id = $order->get_payment_method();
        if (empty($gateway_id)) {
            return array(
                'success' => false,
                'message' => __('Order has no payment method.', 'woocommerce-combo-product'),
                'data' => array(),
                'log_id' => 0,
            );
        }

        if (!empty($args['skip_if_logged']) && !empty($args['start_date']) && !empty($args['end_date'])) {
            $check_gateway = !empty($args['gateway_id']) ? $args['gateway_id'] : $gateway_id;
            if (WC_Combo_Payment_Callback_DB::order_has_log_in_range($order_id, $check_gateway, $args['start_date'], $args['end_date'])) {
                return array(
                    'success' => true,
                    'skipped' => true,
                    'message' => __('Already in list for this period — skipped.', 'woocommerce-combo-product'),
                    'data' => array(),
                    'log_id' => 0,
                );
            }
        }

        $existing_log = WC_Combo_Payment_Callback_DB::get_best_callback_for_order($order_id, $gateway_id);
        $payload = self::build_order_verification_payload($order, $existing_log);

        $temp_log = (object) array(
            'id' => 0,
            'gateway_id' => $gateway_id,
            'callback_data' => wp_json_encode($payload),
            'order_id' => $order_id,
        );

        $result = self::verify($temp_log);

        $gateway_label = $gateway_id;
        $gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
        if (isset($gateways[$gateway_id])) {
            $gateway_label = $gateways[$gateway_id]->get_title();
        }

        $stored_payload = array_merge($payload, array(
            'source' => 'bulk_order_verify',
            'verification_message' => $result['message'],
            'verification_success' => $result['success'],
        ));

        unset($stored_payload['gateway_verification']);

        $payment_status = $order->get_status();
        if (!empty($result['data']['gateway_status_code'])) {
            $payment_status = $result['data']['gateway_status_code'];
        }

        $log_id = WC_Combo_Payment_Callback_DB::insert(array(
            'gateway_id' => $gateway_id,
            'gateway_label' => $gateway_label,
            'order_id' => (int) $order_id,
            'amount' => (float) $order->get_total(),
            'currency' => $order->get_currency(),
            'payment_status' => $payment_status,
            'callback_data' => $stored_payload,
            'gateway_verify_response' => !empty($result['data']) ? $result['data'] : null,
            'request_uri' => 'bulk_order_verify',
            'request_method' => 'BULK',
            'ip_address' => '',
        ));

        $result['log_id'] = $log_id ? (int) $log_id : 0;

        return $result;
    }

    /**
     * @param string $gateway_id Gateway ID.
     * @param string $start_date Start date (Y-m-d).
     * @param string $end_date End date (Y-m-d).
     * @return int[]
     */
    public static function get_order_ids_for_date_range($gateway_id, $start_date, $end_date)
    {
        if (empty($gateway_id) || $gateway_id === 'all') {
            return array();
        }

        $range = WC_Combo_Payment_Callback_DB::normalize_date_range($start_date, $end_date);

        $statuses = array_keys(wc_get_order_statuses());
        $statuses = array_map(function ($status) {
            return str_replace('wc-', '', $status);
        }, $statuses);

        $statuses = array_values(array_diff($statuses, array('cancelled', 'refunded', 'checkout-draft', 'trash')));

        $args = array(
            'limit' => -1,
            'return' => 'ids',
            'type' => 'shop_order',
            'payment_method' => $gateway_id,
            'date_created' => $range['start'] . '...' . $range['end'],
            'status' => $statuses,
            'orderby' => 'date',
            'order' => 'ASC',
        );

        $order_ids = wc_get_orders($args);

        return array_map('absint', is_array($order_ids) ? $order_ids : array());
    }

    /**
     * @param string $gateway_id Gateway ID.
     * @param string $period Period key.
     * @return int[]
     */
    public static function get_order_ids_for_period($gateway_id, $period)
    {
        $range = WC_Combo_Payment_Callback_DB::resolve_period_range($period);
        if (!$range) {
            return array();
        }

        return self::get_order_ids_for_date_range(
            $gateway_id,
            gmdate('Y-m-d', strtotime($range['start'])),
            gmdate('Y-m-d', strtotime($range['end']))
        );
    }

    /**
     * @param WC_Order      $order Order object.
     * @param object|null   $existing_log Existing callback log.
     * @return array
     */
    public static function build_order_verification_payload($order, $existing_log = null)
    {
        $payload = array(
            'source' => 'bulk_order_verify',
            'id' => (string) $order->get_id(),
            'ivm' => (string) $order->get_id(),
            'order_id' => $order->get_id(),
            'order_status' => $order->get_status(),
            'transaction_id' => $order->get_transaction_id(),
            'total' => $order->get_total(),
            'currency' => $order->get_currency(),
            'payment_method' => $order->get_payment_method(),
            'date_paid' => $order->get_date_paid() ? $order->get_date_paid()->date('Y-m-d H:i:s') : '',
            'date_created' => $order->get_date_created() ? $order->get_date_created()->date('Y-m-d H:i:s') : '',
        );

        $stored_ipay = $order->get_meta('_wc_combo_gateway_callback_ipay', true);
        if (is_array($stored_ipay)) {
            $payload = self::merge_ipay_callback_fields($payload, $stored_ipay);
            $payload['callback_meta_source'] = 'order_meta';
        }

        if ($existing_log) {
            $existing_payload = WC_Combo_Payment_Callback_DB::extract_gateway_payload_from_log($existing_log);
            if (!WC_Combo_Payment_Callback_DB::is_bulk_verify_payload($existing_payload)) {
                $payload = self::merge_ipay_callback_fields($payload, $existing_payload);
                $payload['previous_callback_log_id'] = (int) $existing_log->id;
                $payload['callback_meta_source'] = 'callback_log';
            }
        }

        return $payload;
    }

    /**
     * @param array $base Base payload.
     * @param array $callback Callback payload.
     * @return array
     */
    private static function merge_ipay_callback_fields($base, $callback)
    {
        $callback_keys = array('id', 'ivm', 'qwh', 'afd', 'poi', 'uyt', 'ifd', 'mc', 'status', 'channel', 'txncd');

        foreach ($callback_keys as $key) {
            if (!empty($callback[$key])) {
                $base[$key] = $callback[$key];
            }
        }

        return $base;
    }

    /**
     * @param array $payload Callback payload.
     * @return bool
     */
    private static function ipay_has_verification_tokens($payload)
    {
        return WC_Combo_Payment_Callback_Capture::ipay_payload_has_tokens($payload);
    }

    /**
     * @return array
     */
    private static function get_handlers()
    {
        return apply_filters('wc_combo_payment_gateway_verify_handlers', array(
            'ipay' => array(__CLASS__, 'verify_ipay'),
        ));
    }

    /**
     * Verify iPay/eLipa payment via IPN endpoint.
     *
     * @param object $log Callback log row.
     * @return array{success:bool,message:string,data:array}
     */
    public static function verify_ipay($log)
    {
        $payload = json_decode($log->callback_data, true);
        if (!is_array($payload)) {
            $payload = array();
        }

        $payload = self::enrich_ipay_payload($payload, $log);

        $gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
        $live = 'yes';
        if (isset($gateways['ipay'])) {
            $live = $gateways['ipay']->get_option('live', 'no');
        }

        if ($live !== 'no' && !self::ipay_has_verification_tokens($payload)) {
            $wc_paid = !empty($payload['date_paid']);
            $wc_status = isset($payload['order_status']) ? $payload['order_status'] : '';
            $paid_statuses = array_merge(wc_get_is_paid_statuses(), array('on-hold', 'completed', 'processing'));

            if ($wc_paid || in_array($wc_status, $paid_statuses, true)) {
                return array(
                    'success' => true,
                    'message' => __(
                        'iPay callback tokens are not stored for this order. WooCommerce records show the payment was received.',
                        'woocommerce-combo-product'
                    ),
                    'data' => array(
                        'verification_mode' => 'woocommerce_fallback',
                        'gateway_status_label' => __('Paid (WooCommerce)', 'woocommerce-combo-product'),
                        'request_payload' => $payload,
                        'woocommerce_paid' => $wc_paid,
                        'woocommerce_status' => $wc_status,
                        'can_import_callback' => true,
                    ),
                );
            }

            return array(
                'success' => false,
                'message' => __(
                    'Cannot verify with iPay: callback tokens were not captured. Paste the iPay return URL using Import URL, or check the iPay merchant dashboard.',
                    'woocommerce-combo-product'
                ),
                'data' => array(
                    'verification_mode' => 'insufficient_callback_data',
                    'gateway_status_label' => __('Callback data missing', 'woocommerce-combo-product'),
                    'request_payload' => $payload,
                    'woocommerce_paid' => $wc_paid,
                    'woocommerce_status' => $wc_status,
                    'can_import_callback' => true,
                ),
            );
        }

        $ipn_result = self::fetch_ipay_ipn_status($payload);
        if (!$ipn_result['ok']) {
            return array(
                'success' => false,
                'message' => $ipn_result['message'],
                'data' => $ipn_result['data'],
            );
        }

        $status = $ipn_result['status_code'];
        $verify_url = isset($ipn_result['data']['verify_url']) ? $ipn_result['data']['verify_url'] : '';
        $status_label = $ipn_result['data']['gateway_status_label'];

        $verify_data = array(
            'verify_url' => $verify_url,
            'gateway_status_code' => $status,
            'gateway_status_label' => $status_label,
            'verified_at' => current_time('mysql'),
            'request_payload' => $payload,
        );

        if (!empty($log->id)) {
            WC_Combo_Payment_Callback_DB::update((int) $log->id, array(
                'gateway_verify_response' => $verify_data,
                'payment_status' => $status,
            ));
        }

        $success = self::ipay_status_is_success($status);

        return array(
            'success' => $success,
            'message' => $success
                ? __('Payment verified successfully with iPay.', 'woocommerce-combo-product')
                : sprintf(__('Gateway returned status: %s', 'woocommerce-combo-product'), $status_label),
            'data' => $verify_data,
        );
    }

    /**
     * Call iPay IPN for a payload and return the gateway status code.
     *
     * @param array $payload Callback payload (enriched with order meta when possible).
     * @return array{ok:bool,status_code:string,message:string,data:array}
     */
    public static function fetch_ipay_ipn_status($payload)
    {
        $gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
        if (!isset($gateways['ipay'])) {
            return array(
                'ok' => false,
                'status_code' => '',
                'message' => __('iPay gateway is not available.', 'woocommerce-combo-product'),
                'data' => array(),
            );
        }

        $gateway = $gateways['ipay'];
        $merchant_country = $gateway->get_option('merchant_country', 'ke');
        $vid = $gateway->get_option('vid', '');
        $live = $gateway->get_option('live', 'no');

        $ipn_base = self::get_ipay_ipn_base($merchant_country);
        if (empty($ipn_base)) {
            return array(
                'ok' => false,
                'status_code' => '',
                'message' => __('Unknown iPay merchant country.', 'woocommerce-combo-product'),
                'data' => array(),
            );
        }

        $val1 = isset($payload['id']) ? absint($payload['id']) : 0;
        if ($val1 <= 0 && !empty($payload['ivm']) && is_numeric($payload['ivm'])) {
            $val1 = absint($payload['ivm']);
        }
        if ($val1 <= 0 && !empty($payload['order_id'])) {
            $val1 = absint($payload['order_id']);
        }

        $val2 = isset($payload['ivm']) ? sanitize_text_field($payload['ivm']) : (string) $val1;
        $val3 = isset($payload['qwh']) ? sanitize_text_field($payload['qwh']) : '';
        $val4 = isset($payload['afd']) ? sanitize_text_field($payload['afd']) : '';
        $val5 = isset($payload['poi']) ? sanitize_text_field($payload['poi']) : '';
        $val6 = isset($payload['uyt']) ? sanitize_text_field($payload['uyt']) : '';
        $val7 = isset($payload['ifd']) ? sanitize_text_field($payload['ifd']) : '';

        if ($live !== 'no' && !self::ipay_has_verification_tokens($payload)) {
            return array(
                'ok' => false,
                'status_code' => '',
                'message' => __('Cannot verify with iPay: callback tokens were not captured.', 'woocommerce-combo-product'),
                'data' => array(
                    'request_payload' => $payload,
                ),
            );
        }

        if ($live === 'no') {
            $status = 'aei7p7yrx4ae34';
            $verify_url = '';
        } else {
            $verify_url = $ipn_base . '?vendor=' . rawurlencode($vid)
                . '&id=' . rawurlencode((string) $val1)
                . '&ivm=' . rawurlencode($val2)
                . '&qwh=' . rawurlencode($val3)
                . '&afd=' . rawurlencode($val4)
                . '&poi=' . rawurlencode($val5)
                . '&uyt=' . rawurlencode($val6)
                . '&ifd=' . rawurlencode($val7);

            $response = wp_remote_get($verify_url, array('timeout' => 30));
            if (is_wp_error($response)) {
                return array(
                    'ok' => false,
                    'status_code' => '',
                    'message' => $response->get_error_message(),
                    'data' => array(
                        'verify_url' => $verify_url,
                        'request_payload' => $payload,
                    ),
                );
            }

            $status = trim(wp_remote_retrieve_body($response));
        }

        $status_map = self::get_ipay_status_map();
        $status_label = isset($status_map[$status]) ? $status_map[$status] : $status;

        return array(
            'ok' => true,
            'status_code' => $status,
            'message' => '',
            'data' => array(
                'verify_url' => $verify_url,
                'gateway_status_code' => $status,
                'gateway_status_label' => $status_label,
                'request_payload' => $payload,
            ),
        );
    }

    /**
     * Apply a verified iPay IPN status to an order (HPOS-safe).
     *
     * @param WC_Order $order Order object.
     * @param string   $status_code iPay IPN status code.
     * @param array    $payload Optional callback payload for transaction ID.
     * @return bool True when the order was updated.
     */
    public static function apply_ipay_payment_status($order, $status_code, $payload = array())
    {
        if (!$order instanceof WC_Order) {
            return false;
        }

        $updatable_statuses = array('pending', 'processing', 'on-hold');
        if (!in_array($order->get_status(), $updatable_statuses, true)) {
            return false;
        }

        $transaction_id = self::resolve_ipay_transaction_id($order, $payload);
        $status_code = sanitize_text_field($status_code);

        if (self::ipay_status_is_success($status_code)) {
            if (!$order->get_transaction_id() && $transaction_id) {
                $order->set_transaction_id($transaction_id);
            }

            if (!$order->is_paid()) {
                $order->payment_complete($transaction_id ?: $order->get_transaction_id());
            }

            $order->add_order_note(
                sprintf(
                    __('Payment reconciled via iPay IPN. Gateway status: %s', 'woocommerce-combo-product'),
                    $status_code
                )
            );
            $order->save();

            return true;
        }

        if ($status_code === 'fe2707etr5s4wq') {
            $order->update_status(
                'failed',
                __('Payment failed (iPay reconciliation).', 'woocommerce-combo-product')
            );

            return true;
        }

        if ($status_code === 'dtfi4p7yty45wq') {
            $order->update_status(
                'on-hold',
                __('Amount paid was less than required (iPay reconciliation).', 'woocommerce-combo-product')
            );

            return true;
        }

        return false;
    }

    /**
     * @param string $status_code iPay IPN status code.
     * @return bool
     */
    public static function ipay_status_is_success($status_code)
    {
        return in_array($status_code, array('aei7p7yrx4ae34', 'eq3i7p5yt7645e'), true);
    }

    /**
     * @return array<string,string>
     */
    private static function get_ipay_status_map()
    {
        return array(
            'aei7p7yrx4ae34' => __('Success', 'woocommerce-combo-product'),
            'fe2707etr5s4wq' => __('Failed', 'woocommerce-combo-product'),
            'b65s7eqye574ae' => __('Pending', 'woocommerce-combo-product'),
            'bdi6p2yy76etrs' => __('Pending', 'woocommerce-combo-product'),
            'dtfi4p7yty45wq' => __('Underpaid', 'woocommerce-combo-product'),
            'eq3i7p5yt7645e' => __('Overpaid', 'woocommerce-combo-product'),
        );
    }

    /**
     * @param WC_Order $order Order object.
     * @param array    $payload Callback payload.
     * @return string
     */
    private static function resolve_ipay_transaction_id($order, $payload)
    {
        if ($order->get_transaction_id()) {
            return (string) $order->get_transaction_id();
        }

        foreach (array('txncd', 'ifd', 'ivm') as $key) {
            if (!empty($payload[$key])) {
                return sanitize_text_field((string) $payload[$key]);
            }
        }

        return (string) $order->get_id();
    }

    /**
     * Merge stored iPay callback tokens from order meta and prior live callbacks.
     *
     * @param array       $payload Current payload.
     * @param object|null $log Callback log row.
     * @return array
     */
    private static function enrich_ipay_payload($payload, $log = null)
    {
        $order_id = 0;

        if (!empty($payload['order_id'])) {
            $order_id = absint($payload['order_id']);
        } elseif (!empty($payload['id']) && is_numeric($payload['id'])) {
            $order_id = absint($payload['id']);
        } elseif ($log && !empty($log->order_id)) {
            $order_id = absint($log->order_id);
        }

        if ($order_id <= 0) {
            return $payload;
        }

        $payload['order_id'] = $order_id;

        $order = wc_get_order($order_id);
        if ($order) {
            $stored = $order->get_meta('_wc_combo_gateway_callback_ipay', true);
            if (is_array($stored)) {
                $payload = self::merge_ipay_callback_fields($payload, $stored);
            }

            if (empty($payload['date_paid']) && $order->get_date_paid()) {
                $payload['date_paid'] = $order->get_date_paid()->date('Y-m-d H:i:s');
            }
            if (empty($payload['order_status'])) {
                $payload['order_status'] = $order->get_status();
            }
        }

        $callback_log = WC_Combo_Payment_Callback_DB::get_best_callback_for_order($order_id, 'ipay');
        if ($callback_log) {
            $callback_payload = WC_Combo_Payment_Callback_DB::extract_gateway_payload_from_log($callback_log);
            if (!WC_Combo_Payment_Callback_DB::is_bulk_verify_payload($callback_payload)) {
                $payload = self::merge_ipay_callback_fields($payload, $callback_payload);
            }
        }

        return $payload;
    }

    /**
     * @param string $country Merchant country code.
     * @return string
     */
    private static function get_ipay_ipn_base($country)
    {
        switch ($country) {
            case 'ke':
                return 'https://www.ipayafrica.com/ipn';
            case 'tz':
                return 'https://payments.elipa.co.tz/v3/tz/ipn';
            case 'ug':
                return 'https://payments.elipa.co.ug/v3/ug/ipn';
            case 'tg':
                return 'https://payments.elipa.tg/v1/tg/index.php/ipn/check';
            default:
                return '';
        }
    }
}
