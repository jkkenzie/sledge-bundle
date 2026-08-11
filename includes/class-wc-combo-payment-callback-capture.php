<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Captures incoming payment gateway callback payloads.
 */
class WC_Combo_Payment_Callback_Capture
{
    /**
     * @var bool
     */
    private static $api_request_active = false;

    public function __construct()
    {
        add_action('woocommerce_api_request', array($this, 'capture_api_request'), 1, 1);
        add_action('woocommerce_api_wc_gateway_ipay', array($this, 'persist_ipay_meta_after_gateway'), 20);
        add_action('woocommerce_payment_complete', array($this, 'capture_payment_complete'), 5, 1);
    }

    /**
     * Persist iPay tokens on the order after the gateway callback runs.
     * Mirrors storing callback data at IPN time for later server-side verification.
     *
     * @return void
     */
    public function persist_ipay_meta_after_gateway()
    {
        $payload = $this->collect_ipay_request_payload();
        $order_id = $this->extract_order_id($payload);

        if ($order_id > 0) {
            $this->persist_ipay_callback_meta($order_id, $payload);
        }
    }

    /**
     * Collect iPay callback params the same way the official gateway does.
     *
     * @return array
     */
    public function collect_ipay_request_payload()
    {
        $payload = array();

        $sources = array();
        if (!empty($_REQUEST) && is_array($_REQUEST)) {
            $sources[] = $_REQUEST;
        }
        if (!empty($_GET) && is_array($_GET)) {
            $sources[] = $_GET;
        }
        if (!empty($_POST) && is_array($_POST)) {
            $sources[] = $_POST;
        }

        foreach ($sources as $source) {
            foreach ($source as $key => $value) {
                $payload[$key] = $this->sanitize_callback_value($value);
            }
        }

        if (!empty($_SERVER['REQUEST_URI'])) {
            $query_string = wp_parse_url(wp_unslash($_SERVER['REQUEST_URI']), PHP_URL_QUERY);
            if ($query_string) {
                parse_str($query_string, $query_params);
                if (is_array($query_params)) {
                    foreach ($query_params as $key => $value) {
                        if (!isset($payload[$key]) || $payload[$key] === '') {
                            $payload[$key] = $this->sanitize_callback_value($value);
                        }
                    }
                }
            }
        }

        $payload['_wc_api'] = isset($_GET['wc-api']) ? sanitize_text_field(wp_unslash($_GET['wc-api'])) : '';
        $payload['_request_method'] = isset($_SERVER['REQUEST_METHOD']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) : '';
        $payload['source'] = 'ipay_callback';

        return $payload;
    }

    /**
     * Parse and store an iPay return/callback URL for an existing order.
     *
     * @param int    $order_id Order ID.
     * @param string $callback_url Full iPay return URL or query string.
     * @return array{success:bool,message:string,payload:array}
     */
    public static function import_ipay_callback_url($order_id, $callback_url)
    {
        $order_id = absint($order_id);
        $order = wc_get_order($order_id);

        if (!$order) {
            return array(
                'success' => false,
                'message' => __('Order not found.', 'woocommerce-combo-product'),
                'payload' => array(),
            );
        }

        $query = wp_parse_url($callback_url, PHP_URL_QUERY);
        if (empty($query)) {
            if (strpos($callback_url, '=') !== false) {
                $query = ltrim($callback_url, '?&');
            } else {
                return array(
                    'success' => false,
                    'message' => __('Invalid iPay callback URL.', 'woocommerce-combo-product'),
                    'payload' => array(),
                );
            }
        }

        parse_str($query, $params);
        if (!is_array($params) || empty($params)) {
            return array(
                'success' => false,
                'message' => __('No parameters found in the callback URL.', 'woocommerce-combo-product'),
                'payload' => array(),
            );
        }

        $instance = new self();
        $payload = array();
        foreach ($params as $key => $value) {
            $payload[$key] = $instance->sanitize_callback_value($value);
        }
        $payload['source'] = 'manual_import';
        $payload['order_id'] = $order_id;

        if (!self::ipay_payload_has_tokens($payload)) {
            return array(
                'success' => false,
                'message' => __('URL does not contain iPay verification tokens (qwh, afd, poi, uyt, ifd).', 'woocommerce-combo-product'),
                'payload' => $payload,
            );
        }

        $order->update_meta_data('_wc_combo_gateway_callback_ipay', $payload);
        $order->save();

        $gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
        $gateway_label = isset($gateways['ipay']) ? $gateways['ipay']->get_title() : 'iPay';

        WC_Combo_Payment_Callback_DB::insert(array(
            'gateway_id' => 'ipay',
            'gateway_label' => $gateway_label,
            'order_id' => $order_id,
            'amount' => (float) $order->get_total(),
            'currency' => $order->get_currency(),
            'payment_status' => isset($payload['status']) ? $payload['status'] : '',
            'callback_data' => $payload,
            'request_uri' => 'manual_ipay_import',
            'request_method' => 'IMPORT',
            'ip_address' => '',
        ));

        return array(
            'success' => true,
            'message' => __('iPay callback URL imported. You can verify this order now.', 'woocommerce-combo-product'),
            'payload' => $payload,
        );
    }

    /**
     * @param string $api_request WC API request slug.
     * @return void
     */
    public function capture_api_request($api_request)
    {
        if ($this->should_skip_request($api_request)) {
            return;
        }

        self::$api_request_active = true;

        $gateway = $this->resolve_gateway_from_api_request($api_request);
        $payload = (strtolower($api_request) === 'wc_gateway_ipay')
            ? $this->collect_ipay_request_payload()
            : $this->collect_request_payload();
        $order_id = $this->extract_order_id($payload);
        $amount = $this->extract_amount($payload, $order_id);
        $currency = $this->extract_currency($payload, $order_id);
        $status = $this->extract_status($payload);

        $log_id = WC_Combo_Payment_Callback_DB::insert(array(
            'gateway_id' => $gateway['id'],
            'gateway_label' => $gateway['label'],
            'order_id' => $order_id,
            'amount' => $amount,
            'currency' => $currency,
            'payment_status' => $status,
            'callback_data' => $payload,
            'request_uri' => isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '',
            'request_method' => isset($_SERVER['REQUEST_METHOD']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) : '',
            'ip_address' => $this->get_client_ip(),
        ));

        if ($order_id > 0 && $gateway['id'] === 'ipay') {
            $this->persist_ipay_callback_meta($order_id, $payload);
        }
    }

    /**
     * @param int   $order_id Order ID.
     * @param array $payload Callback payload.
     * @return void
     */
    private function persist_ipay_callback_meta($order_id, $payload)
    {
        if (!self::ipay_payload_has_tokens($payload)) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $order->update_meta_data('_wc_combo_gateway_callback_ipay', $payload);
        $order->save();
    }

    /**
     * @param array $payload Callback payload.
     * @return bool
     */
    public static function ipay_payload_has_tokens($payload)
    {
        if (!is_array($payload)) {
            return false;
        }

        foreach (array('qwh', 'afd', 'poi', 'uyt', 'ifd') as $key) {
            if (!empty($payload[$key])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Capture gateways that complete payment without a wc-api callback.
     *
     * @param int $order_id Order ID.
     * @return void
     */
    public function capture_payment_complete($order_id)
    {
        if (self::$api_request_active) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $gateway_id = $order->get_payment_method();
        if (empty($gateway_id)) {
            return;
        }

        $gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
        $gateway_label = isset($gateways[$gateway_id]) ? $gateways[$gateway_id]->get_title() : $gateway_id;

        $payload = array(
            'source' => 'woocommerce_payment_complete',
            'order_id' => $order_id,
            'transaction_id' => $order->get_transaction_id(),
            'payment_method' => $gateway_id,
            'order_status' => $order->get_status(),
            'total' => $order->get_total(),
        );

        WC_Combo_Payment_Callback_DB::insert(array(
            'gateway_id' => $gateway_id,
            'gateway_label' => $gateway_label,
            'order_id' => (int) $order_id,
            'amount' => (float) $order->get_total(),
            'currency' => $order->get_currency(),
            'payment_status' => $order->get_status(),
            'callback_data' => $payload,
            'request_uri' => 'woocommerce_payment_complete',
            'request_method' => 'ACTION',
            'ip_address' => $this->get_client_ip(),
        ));
    }

    /**
     * @param string $api_request API request slug.
     * @return bool
     */
    private function should_skip_request($api_request)
    {
        $skip = array(
            'pay_combo_balance',
        );

        return in_array(strtolower($api_request), $skip, true);
    }

    /**
     * @param string $api_request API request slug.
     * @return array{id:string,label:string}
     */
    private function resolve_gateway_from_api_request($api_request)
    {
        $slug = strtolower($api_request);
        $map = array(
            'wc_gateway_ipay' => 'ipay',
            'wc_gateway_paypal' => 'paypal',
            'wc_gateway_bacs' => 'bacs',
            'wc_gateway_cod' => 'cod',
            'wc_gateway_cheque' => 'cheque',
        );

        $gateway_id = isset($map[$slug]) ? $map[$slug] : sanitize_key(str_replace('wc_gateway_', '', $slug));

        if (class_exists('WC_Payment_Gateways')) {
            $gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
            if (isset($gateways[$gateway_id])) {
                return array(
                    'id' => $gateway_id,
                    'label' => $gateways[$gateway_id]->get_title(),
                );
            }
        }

        return array(
            'id' => $gateway_id,
            'label' => ucwords(str_replace(array('_', '-'), ' ', $gateway_id)),
        );
    }

    /**
     * @return array
     */
    private function collect_request_payload()
    {
        $payload = array();

        $sources = array();
        if (!empty($_REQUEST) && is_array($_REQUEST)) {
            $sources[] = $_REQUEST;
        }
        if (!empty($_GET) && is_array($_GET)) {
            $sources[] = $_GET;
        }
        if (!empty($_POST) && is_array($_POST)) {
            $sources[] = $_POST;
        }

        foreach ($sources as $source) {
            foreach ($source as $key => $value) {
                $payload[$key] = $this->sanitize_callback_value($value);
            }
        }

        if (!empty($_SERVER['REQUEST_URI'])) {
            $query_string = wp_parse_url(wp_unslash($_SERVER['REQUEST_URI']), PHP_URL_QUERY);
            if ($query_string) {
                parse_str($query_string, $query);
                if (is_array($query)) {
                    foreach ($query as $key => $value) {
                        if (!isset($payload[$key]) || $payload[$key] === '') {
                            $payload[$key] = $this->sanitize_callback_value($value);
                        }
                    }
                }
            }
        }

        if (!empty($_SERVER['QUERY_STRING'])) {
            parse_str(wp_unslash($_SERVER['QUERY_STRING']), $query);
            if (is_array($query)) {
                foreach ($query as $key => $value) {
                    if (!isset($payload[$key]) || $payload[$key] === '') {
                        $payload[$key] = $this->sanitize_callback_value($value);
                    }
                }
            }
        }

        $raw_body = file_get_contents('php://input');
        if (!empty($raw_body)) {
            $json = json_decode($raw_body, true);
            if (is_array($json)) {
                foreach ($json as $key => $value) {
                    $payload[$key] = $this->sanitize_callback_value($value);
                }
                $payload['_raw_json'] = $json;
            } else {
                $payload['_raw_body'] = sanitize_textarea_field($raw_body);
            }
        }

        $payload['_wc_api'] = isset($_GET['wc-api']) ? sanitize_text_field(wp_unslash($_GET['wc-api'])) : '';
        $payload['_request_method'] = isset($_SERVER['REQUEST_METHOD']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) : '';

        return $payload;
    }

    /**
     * @param mixed $value Value to sanitize.
     * @return mixed
     */
    private function sanitize_callback_value($value)
    {
        if (is_array($value)) {
            $sanitized = array();
            foreach ($value as $key => $item) {
                $sanitized[$key] = $this->sanitize_callback_value($item);
            }
            return $sanitized;
        }

        if (is_scalar($value)) {
            return sanitize_text_field(wp_unslash((string) $value));
        }

        return $value;
    }

    /**
     * @param array $payload Callback payload.
     * @return int
     */
    private function extract_order_id($payload)
    {
        $keys = array('id', 'ivm', 'order_id', 'order', 'order_key', 'merchant_reference');

        foreach ($keys as $key) {
            if (empty($payload[$key])) {
                continue;
            }

            $value = $payload[$key];

            if ($key === 'order_key') {
                $order_id = wc_get_order_id_by_order_key($value);
                if ($order_id) {
                    return (int) $order_id;
                }
                continue;
            }

            if (is_numeric($value)) {
                return absint($value);
            }

            if (preg_match('/\d+/', (string) $value, $matches)) {
                return absint($matches[0]);
            }
        }

        return 0;
    }

    /**
     * @param array $payload Callback payload.
     * @param int   $order_id Order ID.
     * @return float
     */
    private function extract_amount($payload, $order_id)
    {
        $keys = array('mc', 'amount', 'amt', 'total', 'gross', 'txn_amt', 'transaction_amount');

        foreach ($keys as $key) {
            if (isset($payload[$key]) && is_numeric($payload[$key])) {
                return (float) $payload[$key];
            }
        }

        if ($order_id > 0) {
            $order = wc_get_order($order_id);
            if ($order) {
                return (float) $order->get_total();
            }
        }

        return 0;
    }

    /**
     * @param array $payload Callback payload.
     * @param int   $order_id Order ID.
     * @return string
     */
    private function extract_currency($payload, $order_id)
    {
        $keys = array('currency', 'cur', 'currency_code');

        foreach ($keys as $key) {
            if (!empty($payload[$key])) {
                return strtoupper(sanitize_text_field($payload[$key]));
            }
        }

        if ($order_id > 0) {
            $order = wc_get_order($order_id);
            if ($order) {
                return $order->get_currency();
            }
        }

        return function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : '';
    }

    /**
     * @param array $payload Callback payload.
     * @return string
     */
    private function extract_status($payload)
    {
        $keys = array('status', 'payment_status', 'txn_status', 'result', 'state');

        foreach ($keys as $key) {
            if (!empty($payload[$key])) {
                return sanitize_text_field((string) $payload[$key]);
            }
        }

        return '';
    }

    /**
     * @return string
     */
    private function get_client_ip()
    {
        $keys = array('HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR');

        foreach ($keys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = sanitize_text_field(wp_unslash($_SERVER[$key]));
                if (strpos($ip, ',') !== false) {
                    $parts = explode(',', $ip);
                    $ip = trim($parts[0]);
                }
                return $ip;
            }
        }

        return '';
    }
}
