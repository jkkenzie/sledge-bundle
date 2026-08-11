<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Database layer for payment gateway callback logs.
 */
class WC_Combo_Payment_Callback_DB
{
    const DB_VERSION = '1.0.0';
    const OPTION_KEY = 'wc_combo_payment_callback_db_version';

    /**
     * @return string
     */
    public static function table_name()
    {
        global $wpdb;
        return $wpdb->prefix . 'wc_combo_payment_callbacks';
    }

    /**
     * @return void
     */
    public static function maybe_create_table()
    {
        if (get_option(self::OPTION_KEY) === self::DB_VERSION) {
            return;
        }

        global $wpdb;
        $table = self::table_name();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            gateway_id varchar(100) NOT NULL,
            gateway_label varchar(200) NOT NULL DEFAULT '',
            order_id bigint(20) unsigned NOT NULL DEFAULT 0,
            amount decimal(15,4) NOT NULL DEFAULT 0.0000,
            currency varchar(10) NOT NULL DEFAULT '',
            payment_status varchar(100) NOT NULL DEFAULT '',
            callback_data longtext NOT NULL,
            gateway_verify_response longtext NULL,
            request_uri text NULL,
            request_method varchar(10) NOT NULL DEFAULT '',
            ip_address varchar(45) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY gateway_id (gateway_id),
            KEY order_id (order_id),
            KEY created_at (created_at)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        update_option(self::OPTION_KEY, self::DB_VERSION);
    }

    /**
     * @param array $data Row data.
     * @return int|false
     */
    public static function insert($data)
    {
        global $wpdb;

        $defaults = array(
            'gateway_id' => '',
            'gateway_label' => '',
            'order_id' => 0,
            'amount' => 0,
            'currency' => '',
            'payment_status' => '',
            'callback_data' => '{}',
            'gateway_verify_response' => null,
            'request_uri' => '',
            'request_method' => '',
            'ip_address' => '',
            'created_at' => current_time('mysql'),
        );

        $row = wp_parse_args($data, $defaults);

        if (is_array($row['callback_data'])) {
            $row['callback_data'] = wp_json_encode($row['callback_data']);
        }

        if (is_array($row['gateway_verify_response'])) {
            $row['gateway_verify_response'] = wp_json_encode($row['gateway_verify_response']);
        }

        $result = $wpdb->insert(self::table_name(), $row);

        return $result ? (int) $wpdb->insert_id : false;
    }

    /**
     * @param int $id Log ID.
     * @return object|null
     */
    public static function get($id)
    {
        global $wpdb;
        $table = self::table_name();

        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id)
        );
    }

    /**
     * @param int $id Log ID.
     * @param array $data Fields to update.
     * @return bool
     */
    public static function update($id, $data)
    {
        global $wpdb;

        if (isset($data['callback_data']) && is_array($data['callback_data'])) {
            $data['callback_data'] = wp_json_encode($data['callback_data']);
        }

        if (isset($data['gateway_verify_response']) && is_array($data['gateway_verify_response'])) {
            $data['gateway_verify_response'] = wp_json_encode($data['gateway_verify_response']);
        }

        return (bool) $wpdb->update(self::table_name(), $data, array('id' => $id));
    }

    /**
     * @param array $args Query args.
     * @return array{items: array, total: int}
     */
    public static function query($args = array())
    {
        global $wpdb;
        $table = self::table_name();

        $defaults = array(
            'gateway_id' => '',
            'period' => '',
            'start_date' => '',
            'end_date' => '',
            'search' => '',
            'orderby' => 'created_at',
            'order' => 'DESC',
            'per_page' => 25,
            'offset' => 0,
        );

        $args = wp_parse_args($args, $defaults);
        $where = array('1=1');
        $values = array();

        if (!empty($args['gateway_id']) && $args['gateway_id'] !== 'all') {
            $where[] = 'gateway_id = %s';
            $values[] = $args['gateway_id'];
        }

        $range = self::get_range_from_args($args);
        if ($range) {
            $where[] = 'created_at >= %s';
            $values[] = $range['start'];
            $where[] = 'created_at <= %s';
            $values[] = $range['end'];
        }

        if (!empty($args['search'])) {
            $like = '%' . $wpdb->esc_like($args['search']) . '%';
            $where[] = '(CAST(order_id AS CHAR) LIKE %s OR payment_status LIKE %s OR callback_data LIKE %s)';
            $values[] = $like;
            $values[] = $like;
            $values[] = $like;
        }

        $where_sql = implode(' AND ', $where);
        $allowed_orderby = array('id', 'order_id', 'amount', 'created_at', 'payment_status');
        $orderby = in_array($args['orderby'], $allowed_orderby, true) ? $args['orderby'] : 'created_at';
        $order = strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';

        $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
        $total = !empty($values)
            ? (int) $wpdb->get_var($wpdb->prepare($count_sql, $values))
            : (int) $wpdb->get_var($count_sql);

        $per_page = max(1, (int) $args['per_page']);
        $offset = max(0, (int) $args['offset']);

        $data_sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
        $query_values = array_merge($values, array($per_page, $offset));
        $items = $wpdb->get_results($wpdb->prepare($data_sql, $query_values));

        return array(
            'items' => $items ? $items : array(),
            'total' => $total,
        );
    }

    /**
     * @param string $period Period key.
     * @return string|null
     */
    public static function period_start($period)
    {
        $range = self::resolve_period_range($period);

        return $range ? $range['start'] : null;
    }

    /**
     * @param string $period Period key (e.g. 7d, m:2026-07).
     * @return array{start:string,end:string}|null
     */
    public static function resolve_period_range($period)
    {
        if (preg_match('/^m:(\d{4})-(\d{2})$/', $period, $matches)) {
            $year = (int) $matches[1];
            $month = (int) $matches[2];

            if ($month < 1 || $month > 12) {
                return null;
            }

            $start = sprintf('%04d-%02d-01 00:00:00', $year, $month);
            $end = gmdate('Y-m-t 23:59:59', strtotime($start));

            return array(
                'start' => $start,
                'end' => $end,
            );
        }

        $map = array(
            '1d' => '-1 day',
            '7d' => '-7 days',
            '30d' => '-30 days',
            '90d' => '-90 days',
            '180d' => '-180 days',
            '365d' => '-365 days',
        );

        if (!isset($map[$period])) {
            return null;
        }

        return array(
            'start' => gmdate('Y-m-d H:i:s', strtotime($map[$period], current_time('timestamp'))),
            'end' => current_time('mysql'),
        );
    }

    /**
     * @return array{start_date:string,end_date:string}
     */
    public static function default_date_range()
    {
        return array(
            'start_date' => gmdate('Y-m-01', current_time('timestamp')),
            'end_date' => current_time('Y-m-d'),
        );
    }

    /**
     * @param string $start_date Start date (Y-m-d).
     * @param string $end_date End date (Y-m-d).
     * @return array{start:string,end:string,start_date:string,end_date:string}
     */
    public static function normalize_date_range($start_date, $end_date)
    {
        $defaults = self::default_date_range();

        if (empty($start_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)) {
            $start_date = $defaults['start_date'];
        }

        if (empty($end_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
            $end_date = $defaults['end_date'];
        }

        if (strtotime($start_date) > strtotime($end_date)) {
            $tmp = $start_date;
            $start_date = $end_date;
            $end_date = $tmp;
        }

        return array(
            'start' => $start_date . ' 00:00:00',
            'end' => $end_date . ' 23:59:59',
            'start_date' => $start_date,
            'end_date' => $end_date,
        );
    }

    /**
     * @param array $args Query args with start_date, end_date, or period.
     * @return array{start:string,end:string,start_date:string,end_date:string}|null
     */
    public static function get_range_from_args($args)
    {
        if (!empty($args['start_date']) || !empty($args['end_date'])) {
            return self::normalize_date_range(
                isset($args['start_date']) ? $args['start_date'] : '',
                isset($args['end_date']) ? $args['end_date'] : ''
            );
        }

        if (!empty($args['period'])) {
            $range = self::resolve_period_range($args['period']);
            if (!$range) {
                return null;
            }

            return array_merge($range, array(
                'start_date' => gmdate('Y-m-d', strtotime($range['start'])),
                'end_date' => gmdate('Y-m-d', strtotime($range['end'])),
            ));
        }

        return self::normalize_date_range('', '');
    }

    /**
     * @param int    $months Number of months to include.
     * @return array<string,string>
     */
    public static function get_month_options($months = 24)
    {
        $options = array();
        $timestamp = current_time('timestamp');

        for ($i = 0; $i < $months; $i++) {
            $month_time = strtotime("-{$i} months", $timestamp);
            $key = 'm:' . gmdate('Y-m', $month_time);
            $options[$key] = gmdate('F Y', $month_time);
        }

        return $options;
    }

    /**
     * @param int    $order_id Order ID.
     * @param string $gateway_id Optional gateway filter.
     * @return object|null
     */
    public static function get_latest_for_order($order_id, $gateway_id = '')
    {
        return self::get_best_callback_for_order($order_id, $gateway_id);
    }

    /**
     * Find the most useful captured callback for gateway verification.
     *
     * @param int    $order_id Order ID.
     * @param string $gateway_id Optional gateway filter.
     * @return object|null
     */
    public static function get_best_callback_for_order($order_id, $gateway_id = '')
    {
        global $wpdb;
        $table = self::table_name();
        $order_id = absint($order_id);

        if ($order_id <= 0) {
            return null;
        }

        $values = array($order_id);
        $gateway_sql = '';

        if (!empty($gateway_id)) {
            $gateway_sql = ' AND gateway_id = %s';
            $values[] = $gateway_id;
        }

        $sql = "SELECT * FROM {$table}
            WHERE (
                order_id = %d
                OR callback_data LIKE %s
                OR callback_data LIKE %s
            )
            {$gateway_sql}
            AND request_method != 'BULK'
            AND request_uri != 'bulk_order_verify'
            ORDER BY created_at DESC";

        $values[] = '%"id":"' . $order_id . '"%';
        $values[] = '%"ivm":"' . $order_id . '"%';

        $rows = $wpdb->get_results($wpdb->prepare($sql, $values));

        if (!$rows) {
            return null;
        }

        $best = null;
        $best_score = -1;

        foreach ($rows as $row) {
            $payload = self::extract_gateway_payload_from_log($row);
            if (self::is_bulk_verify_payload($payload)) {
                continue;
            }

            $score = 0;
            if (WC_Combo_Payment_Callback_Capture::ipay_payload_has_tokens($payload)) {
                $score += 100;
            }
            if ((int) $row->order_id === $order_id) {
                $score += 10;
            }
            if (!empty($payload['status'])) {
                $score += 5;
            }

            if ($score > $best_score) {
                $best = $row;
                $best_score = $score;
            }
        }

        return $best;
    }

    /**
     * @param array $payload Callback payload.
     * @return bool
     */
    public static function is_bulk_verify_payload($payload)
    {
        return is_array($payload)
            && isset($payload['source'])
            && $payload['source'] === 'bulk_order_verify';
    }

    /**
     * @param object $log Callback log row.
     * @return array
     */
    public static function extract_gateway_payload_from_log($log)
    {
        $payload = json_decode($log->callback_data, true);
        if (!is_array($payload)) {
            $payload = array();
        }

        if (!empty($log->request_uri)) {
            $query = wp_parse_url($log->request_uri, PHP_URL_QUERY);
            if ($query) {
                parse_str($query, $query_params);
                if (is_array($query_params)) {
                    foreach ($query_params as $key => $value) {
                        if ($value !== '' && !isset($payload[$key])) {
                            $payload[$key] = sanitize_text_field((string) $value);
                        }
                    }
                }
            }
        }

        return $payload;
    }

    /**
     * Order IDs that already have callback logs for a gateway within a date range.
     *
     * @param string $gateway_id Gateway ID.
     * @param string $start_date Start date (Y-m-d).
     * @param string $end_date End date (Y-m-d).
     * @return int[]
     */
    public static function get_logged_order_ids_in_range($gateway_id, $start_date, $end_date)
    {
        global $wpdb;
        $table = self::table_name();
        $range = self::normalize_date_range($start_date, $end_date);

        $rows = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT order_id FROM {$table}
                WHERE gateway_id = %s
                AND order_id > 0
                AND created_at >= %s
                AND created_at <= %s",
                $gateway_id,
                $range['start'],
                $range['end']
            )
        );

        return array_map('absint', $rows ? $rows : array());
    }

    /**
     * @param int    $order_id Order ID.
     * @param string $gateway_id Gateway ID.
     * @param string $start_date Start date (Y-m-d).
     * @param string $end_date End date (Y-m-d).
     * @return bool
     */
    public static function order_has_log_in_range($order_id, $gateway_id, $start_date, $end_date)
    {
        $logged_ids = self::get_logged_order_ids_in_range($gateway_id, $start_date, $end_date);

        return in_array((int) $order_id, $logged_ids, true);
    }

    /**
     * @param string       $gateway_id Gateway ID.
     * @param array|string $args Query args or legacy period key.
     * @return array{total_callbacks:int,total_orders:int,total_amount:float}
     */
    public static function get_stats($gateway_id = '', $args = array())
    {
        if (is_string($args)) {
            $args = array('period' => $args);
        }

        global $wpdb;
        $table = self::table_name();

        $where = array('1=1');
        $values = array();

        if (!empty($gateway_id) && $gateway_id !== 'all') {
            $where[] = 'gateway_id = %s';
            $values[] = $gateway_id;
        }

        $range = self::get_range_from_args($args);
        if ($range) {
            $where[] = 'created_at >= %s';
            $values[] = $range['start'];
            $where[] = 'created_at <= %s';
            $values[] = $range['end'];
        }

        $where_sql = implode(' AND ', $where);

        $sql = "SELECT
            COUNT(*) AS total_callbacks,
            COUNT(DISTINCT CASE WHEN order_id > 0 THEN order_id END) AS total_orders,
            COALESCE(SUM(amount), 0) AS total_amount
            FROM {$table}
            WHERE {$where_sql}";

        $row = !empty($values)
            ? $wpdb->get_row($wpdb->prepare($sql, $values), ARRAY_A)
            : $wpdb->get_row($sql, ARRAY_A);

        return array(
            'total_callbacks' => isset($row['total_callbacks']) ? (int) $row['total_callbacks'] : 0,
            'total_orders' => isset($row['total_orders']) ? (int) $row['total_orders'] : 0,
            'total_amount' => isset($row['total_amount']) ? (float) $row['total_amount'] : 0,
        );
    }

    /**
     * @param string       $gateway_id Gateway ID.
     * @param array|string $args Query args or legacy period key.
     * @return array
     */
    public static function get_callback_field_keys($gateway_id = '', $args = array())
    {
        if (is_string($args)) {
            $args = array('period' => $args);
        }

        $query_args = wp_parse_args($args, array(
            'gateway_id' => $gateway_id,
            'per_page' => 200,
            'offset' => 0,
        ));

        if (!empty($gateway_id)) {
            $query_args['gateway_id'] = $gateway_id;
        }

        $result = self::query($query_args);

        $keys = array();

        foreach ($result['items'] as $item) {
            $data = json_decode($item->callback_data, true);
            if (!is_array($data)) {
                continue;
            }
            foreach (array_keys($data) as $key) {
                $keys[$key] = true;
            }
        }

        $keys = array_keys($keys);
        sort($keys);

        return $keys;
    }

    /**
     * @return array
     */
    public static function get_logged_gateway_ids()
    {
        global $wpdb;
        $table = self::table_name();

        $rows = $wpdb->get_col("SELECT DISTINCT gateway_id FROM {$table} ORDER BY gateway_id ASC");

        return $rows ? $rows : array();
    }
}
