<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Reconcile iPay orders that have stored callback tokens but missed status updates.
 */
class WC_Combo_Payment_Ipay_Reconciliation
{
    const CRON_HOOK = 'wc_combo_ipay_reconciliation_cron';
    const GATEWAY_ID = 'ipay';
    const BATCH_LIMIT = 50;
    const LOOKBACK_SECONDS = DAY_IN_SECONDS;
    const MIN_AGE_SECONDS = 180;

    public function __construct()
    {
        add_filter('cron_schedules', array($this, 'add_cron_schedules'));
        add_action('init', array($this, 'register_cron'));
        add_action(self::CRON_HOOK, array($this, 'run'));
    }

    /**
     * @param array $schedules WP cron schedules.
     * @return array
     */
    public function add_cron_schedules($schedules)
    {
        if (!isset($schedules['every_five_minutes'])) {
            $schedules['every_five_minutes'] = array(
                'interval' => 300,
                'display' => __('Every 5 minutes', 'woocommerce-combo-product'),
            );
        }

        return $schedules;
    }

    /**
     * @return void
     */
    public function register_cron()
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 60, 'every_five_minutes', self::CRON_HOOK);
        }
    }

    /**
     * @return void
     */
    public function run()
    {
        if (!function_exists('wc_get_orders')) {
            return;
        }

        $gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
        if (!isset($gateways[self::GATEWAY_ID])) {
            return;
        }

        $order_ids = wc_get_orders(array(
            'status' => array('pending', 'processing', 'on-hold'),
            'date_created' => '>' . (time() - self::LOOKBACK_SECONDS),
            'payment_method' => self::GATEWAY_ID,
            'return' => 'ids',
            'limit' => self::BATCH_LIMIT,
            'orderby' => 'date',
            'order' => 'ASC',
        ));

        if (empty($order_ids) || !is_array($order_ids)) {
            return;
        }

        $this->log(sprintf('Reconciliation started for %d candidate order(s).', count($order_ids)));

        foreach ($order_ids as $order_id) {
            $this->reconcile_order((int) $order_id);
            sleep(1);
        }
    }

    /**
     * @param int $order_id Order ID.
     * @return void
     */
    private function reconcile_order($order_id)
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        if ($order->is_paid()) {
            return;
        }

        $updated = $order->get_date_modified();
        if ($updated && (time() - $updated->getTimestamp()) < self::MIN_AGE_SECONDS) {
            return;
        }

        $stored = $order->get_meta('_wc_combo_gateway_callback_ipay', true);
        if (!is_array($stored) || !WC_Combo_Payment_Callback_Capture::ipay_payload_has_tokens($stored)) {
            return;
        }

        $payload = WC_Combo_Payment_Gateway_Verifier::build_order_verification_payload($order);
        $ipn_result = WC_Combo_Payment_Gateway_Verifier::fetch_ipay_ipn_status($payload);

        if (!$ipn_result['ok']) {
            $this->log(sprintf(
                'Order %d skipped: %s',
                $order->get_id(),
                $ipn_result['message']
            ));
            return;
        }

        $status_code = $ipn_result['status_code'];
        $applied = WC_Combo_Payment_Gateway_Verifier::apply_ipay_payment_status($order, $status_code, $payload);

        if ($applied) {
            $this->log(sprintf(
                'Order %d reconciled. Gateway status: %s. New WooCommerce status: %s',
                $order->get_id(),
                $status_code,
                $order->get_status()
            ));

            $this->log_reconciliation_callback($order, $payload, $ipn_result);
        }
    }

    /**
     * @param WC_Order $order Order object.
     * @param array    $payload Callback payload.
     * @param array    $ipn_result IPN response.
     * @return void
     */
    private function log_reconciliation_callback($order, $payload, $ipn_result)
    {
        $gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
        $gateway_label = self::GATEWAY_ID;
        if (isset($gateways[self::GATEWAY_ID])) {
            $gateway_label = $gateways[self::GATEWAY_ID]->get_title();
        }

        $stored_payload = array_merge($payload, array(
            'source' => 'ipay_reconciliation_cron',
            'reconciled_at' => current_time('mysql'),
        ));

        WC_Combo_Payment_Callback_DB::insert(array(
            'gateway_id' => self::GATEWAY_ID,
            'gateway_label' => $gateway_label,
            'order_id' => $order->get_id(),
            'amount' => (float) $order->get_total(),
            'currency' => $order->get_currency(),
            'payment_status' => $ipn_result['status_code'],
            'callback_data' => $stored_payload,
            'gateway_verify_response' => !empty($ipn_result['data']) ? $ipn_result['data'] : null,
            'request_uri' => 'ipay_reconciliation_cron',
            'request_method' => 'CRON',
            'ip_address' => '',
        ));
    }

    /**
     * @param string $message Log message.
     * @return void
     */
    private function log($message)
    {
        if (!function_exists('wc_get_logger')) {
            return;
        }

        $logger = wc_get_logger();
        $logger->info($message, array('source' => 'wc-combo-ipay-reconciliation'));
    }
}
