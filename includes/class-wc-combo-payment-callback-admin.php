<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin page for payment gateway callback logs.
 */
class WC_Combo_Payment_Callback_Admin
{
    const PAGE_SLUG = 'wc-combo-payment-callbacks';

    public function __construct()
    {
        add_action('admin_menu', array($this, 'register_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));

        add_action('wp_ajax_wc_combo_payment_callbacks_data', array($this, 'ajax_get_callbacks'));
        add_action('wp_ajax_wc_combo_payment_callbacks_stats', array($this, 'ajax_get_stats'));
        add_action('wp_ajax_wc_combo_payment_callbacks_verify', array($this, 'ajax_verify_payment'));
        add_action('wp_ajax_wc_combo_payment_callbacks_columns', array($this, 'ajax_get_columns'));
        add_action('wp_ajax_wc_combo_bulk_verify_prepare', array($this, 'ajax_bulk_verify_prepare'));
        add_action('wp_ajax_wc_combo_bulk_verify_batch', array($this, 'ajax_bulk_verify_batch'));
        add_action('wp_ajax_wc_combo_import_ipay_callback', array($this, 'ajax_import_ipay_callback'));
    }

    /**
     * @return void
     */
    public function register_menu()
    {
        add_submenu_page(
            'woocommerce',
            __('Payment Callbacks', 'woocommerce-combo-product'),
            __('Payment Callbacks', 'woocommerce-combo-product'),
            'manage_woocommerce',
            self::PAGE_SLUG,
            array($this, 'render_page')
        );
    }

    /**
     * @param string $hook Current admin page hook.
     * @return void
     */
    public function enqueue_assets($hook)
    {
        if ($hook !== 'woocommerce_page_' . self::PAGE_SLUG) {
            return;
        }

        wp_enqueue_style(
            'wc-combo-payment-callbacks-admin',
            WC_COMBO_PLUGIN_URL . 'assets/css/payment-callbacks-admin.css',
            array(),
            WC_COMBO_VERSION
        );

        wp_enqueue_script(
            'wc-combo-payment-callbacks-admin',
            WC_COMBO_PLUGIN_URL . 'assets/js/payment-callbacks-admin.js',
            array('jquery'),
            WC_COMBO_VERSION,
            true
        );

        wp_localize_script('wc-combo-payment-callbacks-admin', 'wcComboPaymentCallbacks', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wc_combo_payment_callbacks'),
            'currencySymbol' => function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : '$',
            'i18n' => array(
                'loading' => __('Loading...', 'woocommerce-combo-product'),
                'noData' => __('No callback records found for this period.', 'woocommerce-combo-product'),
                'verify' => __('Verify Payment', 'woocommerce-combo-product'),
                'verifying' => __('Verifying...', 'woocommerce-combo-product'),
                'viewOrder' => __('View Order', 'woocommerce-combo-product'),
                'error' => __('Request failed. Please try again.', 'woocommerce-combo-product'),
                'bulkVerify' => __('Verify Orders from Gateway', 'woocommerce-combo-product'),
                'bulkVerifying' => __('Verifying orders...', 'woocommerce-combo-product'),
                'bulkComplete' => __('Bulk verification complete.', 'woocommerce-combo-product'),
                'bulkNoOrders' => __('No orders found for the selected gateway and month.', 'woocommerce-combo-product'),
                'selectGateway' => __('Select a payment gateway first.', 'woocommerce-combo-product'),
                'bulkProgress' => __('Processed %1$d of %2$d orders', 'woocommerce-combo-product'),
                'bulkAllSkipped' => __('All orders for this period are already in the list.', 'woocommerce-combo-product'),
                'bulkSkippedSummary' => __('Skipped %d orders already logged for this period.', 'woocommerce-combo-product'),
                'importUrl' => __('Import URL', 'woocommerce-combo-product'),
                'importUrlPrompt' => __('Paste the full iPay return/callback URL for this order:', 'woocommerce-combo-product'),
                'importSuccess' => __('iPay callback URL imported successfully.', 'woocommerce-combo-product'),
            ),
            'months' => WC_Combo_Payment_Callback_DB::get_month_options(24),
            'gateways' => $this->get_gateway_options(),
            'defaultStartDate' => WC_Combo_Payment_Callback_DB::default_date_range()['start_date'],
            'defaultEndDate' => WC_Combo_Payment_Callback_DB::default_date_range()['end_date'],
        ));
    }

    /**
     * @return void
     */
    public function render_page()
    {
        $gateways = $this->get_gateway_tabs();
        $months = WC_Combo_Payment_Callback_DB::get_month_options(24);
        $current_month = 'm:' . gmdate('Y-m', current_time('timestamp'));
        $default_range = WC_Combo_Payment_Callback_DB::default_date_range();
        ?>
        <div class="wrap wc-combo-payment-callbacks-wrap">
            <h1><?php esc_html_e('Payment Gateway Callbacks', 'woocommerce-combo-product'); ?></h1>
            <p class="description">
                <?php esc_html_e('Captured payloads from payment gateway callback URLs when confirming payments.', 'woocommerce-combo-product'); ?>
            </p>

            <div class="wc-combo-pcb-bulk-panel">
                <h2><?php esc_html_e('Bulk Order Verification', 'woocommerce-combo-product'); ?></h2>
                <p class="description">
                    <?php esc_html_e('Go through each order for a selected month, verify payment with the gateway, and store the confirmation data in the table below.', 'woocommerce-combo-product'); ?>
                </p>
                <div class="wc-combo-pcb-bulk-controls">
                    <label for="wc-combo-pcb-bulk-month">
                        <?php esc_html_e('Month', 'woocommerce-combo-product'); ?>
                    </label>
                    <select id="wc-combo-pcb-bulk-month">
                        <?php foreach ($months as $key => $label) : ?>
                            <option value="<?php echo esc_attr($key); ?>" <?php selected($key, $current_month); ?>>
                                <?php echo esc_html($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <label for="wc-combo-pcb-bulk-gateway">
                        <?php esc_html_e('Gateway', 'woocommerce-combo-product'); ?>
                    </label>
                    <select id="wc-combo-pcb-bulk-gateway">
                        <option value=""><?php esc_html_e('Select gateway...', 'woocommerce-combo-product'); ?></option>
                        <?php foreach ($this->get_gateway_options() as $gateway_id => $gateway_label) : ?>
                            <option value="<?php echo esc_attr($gateway_id); ?>">
                                <?php echo esc_html($gateway_label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <button type="button" class="button button-primary" id="wc-combo-pcb-bulk-verify">
                        <?php esc_html_e('Verify Orders from Gateway', 'woocommerce-combo-product'); ?>
                    </button>
                </div>

                <div class="wc-combo-pcb-bulk-progress" id="wc-combo-pcb-bulk-progress" hidden>
                    <div class="wc-combo-pcb-bulk-progress-bar">
                        <span id="wc-combo-pcb-bulk-progress-fill"></span>
                    </div>
                    <p id="wc-combo-pcb-bulk-progress-text"></p>
                    <ul id="wc-combo-pcb-bulk-results" class="wc-combo-pcb-bulk-results"></ul>
                </div>
            </div>

            <div class="wc-combo-pcb-toolbar">
                <label for="wc-combo-pcb-start-date">
                    <?php esc_html_e('Start date', 'woocommerce-combo-product'); ?>
                </label>
                <input type="date"
                       id="wc-combo-pcb-start-date"
                       value="<?php echo esc_attr($default_range['start_date']); ?>" />

                <label for="wc-combo-pcb-end-date">
                    <?php esc_html_e('End date', 'woocommerce-combo-product'); ?>
                </label>
                <input type="date"
                       id="wc-combo-pcb-end-date"
                       value="<?php echo esc_attr($default_range['end_date']); ?>" />

                <input type="search" id="wc-combo-pcb-search" placeholder="<?php esc_attr_e('Search order, status, payload...', 'woocommerce-combo-product'); ?>" />
                <button type="button" class="button" id="wc-combo-pcb-refresh"><?php esc_html_e('Apply', 'woocommerce-combo-product'); ?></button>
            </div>

            <div class="wc-combo-pcb-stats" id="wc-combo-pcb-stats">
                <div class="wc-combo-pcb-stat-card">
                    <span class="label"><?php esc_html_e('Total Callbacks', 'woocommerce-combo-product'); ?></span>
                    <strong class="value" data-stat="total_callbacks">0</strong>
                </div>
                <div class="wc-combo-pcb-stat-card">
                    <span class="label"><?php esc_html_e('Total Orders', 'woocommerce-combo-product'); ?></span>
                    <strong class="value" data-stat="total_orders">0</strong>
                </div>
                <div class="wc-combo-pcb-stat-card">
                    <span class="label"><?php esc_html_e('Total Amount', 'woocommerce-combo-product'); ?></span>
                    <strong class="value" data-stat="total_amount">0</strong>
                </div>
            </div>

            <h2 class="nav-tab-wrapper wc-combo-pcb-tabs">
                <?php foreach ($gateways as $gateway_id => $gateway_label) : ?>
                    <a href="#"
                       class="nav-tab<?php echo $gateway_id === 'all' ? ' nav-tab-active' : ''; ?>"
                       data-gateway="<?php echo esc_attr($gateway_id); ?>">
                        <?php echo esc_html($gateway_label); ?>
                    </a>
                <?php endforeach; ?>
            </h2>

            <div class="wc-combo-pcb-table-wrap">
                <table class="widefat striped wc-combo-pcb-table" id="wc-combo-pcb-table">
                    <thead>
                        <tr id="wc-combo-pcb-table-head">
                            <th><?php esc_html_e('ID', 'woocommerce-combo-product'); ?></th>
                            <th><?php esc_html_e('Date', 'woocommerce-combo-product'); ?></th>
                            <th><?php esc_html_e('Order', 'woocommerce-combo-product'); ?></th>
                            <th><?php esc_html_e('Amount', 'woocommerce-combo-product'); ?></th>
                            <th><?php esc_html_e('Status', 'woocommerce-combo-product'); ?></th>
                            <th><?php esc_html_e('Actions', 'woocommerce-combo-product'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="wc-combo-pcb-table-body">
                        <tr>
                            <td colspan="6"><?php esc_html_e('Loading...', 'woocommerce-combo-product'); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="wc-combo-pcb-pagination" id="wc-combo-pcb-pagination"></div>
        </div>
        <?php
    }

    /**
     * @return array{start_date:string,end_date:string}
     */
    private function get_date_range_from_request()
    {
        $start_date = isset($_POST['start_date']) ? sanitize_text_field(wp_unslash($_POST['start_date'])) : '';
        $end_date = isset($_POST['end_date']) ? sanitize_text_field(wp_unslash($_POST['end_date'])) : '';

        $range = WC_Combo_Payment_Callback_DB::normalize_date_range($start_date, $end_date);

        return array(
            'start_date' => $range['start_date'],
            'end_date' => $range['end_date'],
        );
    }

    /**
     * @return array
     */
    private function get_gateway_tabs()
    {
        $tabs = array('all' => __('All Gateways', 'woocommerce-combo-product'));

        if (class_exists('WC_Payment_Gateways')) {
            $gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
            foreach ($gateways as $gateway) {
                if ('yes' === $gateway->enabled) {
                    $tabs[$gateway->id] = $gateway->get_title();
                }
            }
        }

        foreach (WC_Combo_Payment_Callback_DB::get_logged_gateway_ids() as $gateway_id) {
            if (!isset($tabs[$gateway_id])) {
                $tabs[$gateway_id] = ucwords(str_replace(array('_', '-'), ' ', $gateway_id));
            }
        }

        return $tabs;
    }

    /**
     * @return void
     */
    public function ajax_get_stats()
    {
        $this->verify_ajax_request();

        $gateway_id = isset($_POST['gateway_id']) ? sanitize_key(wp_unslash($_POST['gateway_id'])) : 'all';
        $date_range = $this->get_date_range_from_request();

        $stats = WC_Combo_Payment_Callback_DB::get_stats($gateway_id, $date_range);

        wp_send_json_success($stats);
    }

    /**
     * @return void
     */
    public function ajax_get_columns()
    {
        $this->verify_ajax_request();

        $gateway_id = isset($_POST['gateway_id']) ? sanitize_key(wp_unslash($_POST['gateway_id'])) : 'all';
        $date_range = $this->get_date_range_from_request();

        $keys = WC_Combo_Payment_Callback_DB::get_callback_field_keys($gateway_id, $date_range);
        $keys = array_values(array_diff($keys, array(
            '_raw_body',
            '_raw_json',
            '_wc_api',
            '_request_method',
            'verification_message',
            'verification_success',
            'gateway_verification',
        )));

        wp_send_json_success(array('columns' => $keys));
    }

    /**
     * @return void
     */
    public function ajax_get_callbacks()
    {
        $this->verify_ajax_request();

        $gateway_id = isset($_POST['gateway_id']) ? sanitize_key(wp_unslash($_POST['gateway_id'])) : 'all';
        $date_range = $this->get_date_range_from_request();
        $search = isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '';
        $page = isset($_POST['page']) ? max(1, (int) $_POST['page']) : 1;
        $per_page = isset($_POST['per_page']) ? max(10, min(100, (int) $_POST['per_page'])) : 25;

        $result = WC_Combo_Payment_Callback_DB::query(array_merge($date_range, array(
            'gateway_id' => $gateway_id,
            'search' => $search,
            'per_page' => $per_page,
            'offset' => ($page - 1) * $per_page,
        )));

        $dynamic_keys = WC_Combo_Payment_Callback_DB::get_callback_field_keys($gateway_id, $date_range);
        $dynamic_keys = array_values(array_diff($dynamic_keys, array(
            '_raw_body',
            '_raw_json',
            '_wc_api',
            '_request_method',
            'verification_message',
            'verification_success',
            'gateway_verification',
        )));

        $rows = array();
        foreach ($result['items'] as $item) {
            $payload = json_decode($item->callback_data, true);
            if (!is_array($payload)) {
                $payload = array();
            }

            $fields = array();
            foreach ($dynamic_keys as $key) {
                $fields[$key] = isset($payload[$key]) ? $this->format_field_value($payload[$key]) : '';
            }

            $rows[] = array(
                'id' => (int) $item->id,
                'created_at' => $item->created_at,
                'order_id' => (int) $item->order_id,
                'order_link' => $item->order_id > 0
                    ? admin_url('post.php?post=' . $item->order_id . '&action=edit')
                    : '',
                'amount' => (float) $item->amount,
                'amount_formatted' => wc_price($item->amount, array('currency' => $item->currency)),
                'currency' => $item->currency,
                'payment_status' => $item->payment_status,
                'gateway_id' => $item->gateway_id,
                'gateway_label' => $item->gateway_label,
                'fields' => $fields,
                'verify_response' => $item->gateway_verify_response,
                'can_import_ipay' => ($item->gateway_id === 'ipay' && $item->order_id > 0),
            );
        }

        wp_send_json_success(array(
            'rows' => $rows,
            'total' => (int) $result['total'],
            'page' => $page,
            'per_page' => $per_page,
            'columns' => $dynamic_keys,
        ));
    }

    /**
     * @return array
     */
    private function get_gateway_options()
    {
        $options = array();

        if (class_exists('WC_Payment_Gateways')) {
            $gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
            foreach ($gateways as $gateway) {
                $options[$gateway->id] = $gateway->get_title();
            }
        }

        foreach (WC_Combo_Payment_Callback_DB::get_logged_gateway_ids() as $gateway_id) {
            if (!isset($options[$gateway_id])) {
                $options[$gateway_id] = ucwords(str_replace(array('_', '-'), ' ', $gateway_id));
            }
        }

        return $options;
    }

    /**
     * @return void
     */
    public function ajax_bulk_verify_prepare()
    {
        $this->verify_ajax_request();

        $gateway_id = isset($_POST['gateway_id']) ? sanitize_key(wp_unslash($_POST['gateway_id'])) : '';
        $period = isset($_POST['period']) ? sanitize_text_field(wp_unslash($_POST['period'])) : '';

        if (empty($gateway_id)) {
            wp_send_json_error(array('message' => __('Select a payment gateway.', 'woocommerce-combo-product')));
        }

        if (preg_match('/^m:\d{4}-\d{2}$/', $period)) {
            $range = WC_Combo_Payment_Callback_DB::resolve_period_range($period);
            $date_range = array(
                'start_date' => gmdate('Y-m-d', strtotime($range['start'])),
                'end_date' => gmdate('Y-m-d', strtotime($range['end'])),
            );
        } else {
            $date_range = $this->get_date_range_from_request();
        }

        $order_ids = WC_Combo_Payment_Gateway_Verifier::get_order_ids_for_date_range(
            $gateway_id,
            $date_range['start_date'],
            $date_range['end_date']
        );

        $logged_order_ids = WC_Combo_Payment_Callback_DB::get_logged_order_ids_in_range(
            $gateway_id,
            $date_range['start_date'],
            $date_range['end_date']
        );

        $skipped_count = count(array_intersect($order_ids, $logged_order_ids));
        $order_ids = array_values(array_diff($order_ids, $logged_order_ids));

        wp_send_json_success(array(
            'order_ids' => $order_ids,
            'total' => count($order_ids),
            'skipped' => $skipped_count,
            'gateway_id' => $gateway_id,
            'start_date' => $date_range['start_date'],
            'end_date' => $date_range['end_date'],
        ));
    }

    /**
     * @return void
     */
    public function ajax_bulk_verify_batch()
    {
        $this->verify_ajax_request();

        $order_ids = isset($_POST['order_ids']) ? array_map('absint', (array) wp_unslash($_POST['order_ids'])) : array();
        $order_ids = array_values(array_filter($order_ids));
        $gateway_id = isset($_POST['gateway_id']) ? sanitize_key(wp_unslash($_POST['gateway_id'])) : '';
        $start_date = isset($_POST['start_date']) ? sanitize_text_field(wp_unslash($_POST['start_date'])) : '';
        $end_date = isset($_POST['end_date']) ? sanitize_text_field(wp_unslash($_POST['end_date'])) : '';

        if (empty($order_ids)) {
            wp_send_json_error(array('message' => __('No orders to verify.', 'woocommerce-combo-product')));
        }

        $verify_args = array(
            'skip_if_logged' => true,
            'start_date' => $start_date,
            'end_date' => $end_date,
            'gateway_id' => $gateway_id,
        );

        $results = array();

        foreach ($order_ids as $order_id) {
            $result = WC_Combo_Payment_Gateway_Verifier::verify_order($order_id, $verify_args);
            $results[] = array(
                'order_id' => $order_id,
                'success' => !empty($result['success']),
                'skipped' => !empty($result['skipped']),
                'message' => isset($result['message']) ? $result['message'] : '',
                'log_id' => isset($result['log_id']) ? (int) $result['log_id'] : 0,
            );
        }

        wp_send_json_success(array(
            'results' => $results,
            'processed' => count($results),
        ));
    }

    /**
     * @return void
     */
    public function ajax_import_ipay_callback()
    {
        $this->verify_ajax_request();

        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        $callback_url = isset($_POST['callback_url']) ? esc_url_raw(wp_unslash($_POST['callback_url'])) : '';

        if (empty($callback_url)) {
            $callback_url = isset($_POST['callback_url_raw']) ? sanitize_textarea_field(wp_unslash($_POST['callback_url_raw'])) : '';
        }

        $result = WC_Combo_Payment_Callback_Capture::import_ipay_callback_url($order_id, $callback_url);

        if ($result['success']) {
            wp_send_json_success($result);
        }

        wp_send_json_error($result);
    }

    /**
     * @return void
     */
    public function ajax_verify_payment()
    {
        $this->verify_ajax_request();

        $log_id = isset($_POST['log_id']) ? absint($_POST['log_id']) : 0;
        $log = WC_Combo_Payment_Callback_DB::get($log_id);

        if (!$log) {
            wp_send_json_error(array('message' => __('Callback log not found.', 'woocommerce-combo-product')));
        }

        $result = WC_Combo_Payment_Gateway_Verifier::verify($log);

        if ($result['success']) {
            wp_send_json_success($result);
        }

        wp_send_json_error($result);
    }

    /**
     * @param mixed $value Field value.
     * @return string
     */
    private function format_field_value($value)
    {
        if (is_array($value)) {
            return wp_json_encode($value);
        }

        return (string) $value;
    }

    /**
     * @return void
     */
    private function verify_ajax_request()
    {
        check_ajax_referer('wc_combo_payment_callbacks', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'woocommerce-combo-product')));
        }
    }
}
