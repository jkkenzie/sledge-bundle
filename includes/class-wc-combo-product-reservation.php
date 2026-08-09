<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Reservation functionality for combo products - FIXED VERSION
 */
class WC_Combo_Product_Reservation
{
    public function __construct()
    {
        $this->init_hooks();
    }

    private function init_hooks()
    {
        // Product page and checkout reservation checkboxes
        add_action('woocommerce_before_add_to_cart_button', array($this, 'combo_product_reserve_checkbox'));
        add_action('woocommerce_review_order_before_submit', array($this, 'combo_cart_reserve_checkbox'));

        // Handle reservation choice & pricing
        add_filter('woocommerce_add_cart_item_data', array($this, 'add_reserve_box_cart_item_data'), 10, 2);
        add_action('woocommerce_checkout_create_order_line_item', array($this, 'save_checkout_reserve_to_order_meta'), 10, 4);
        add_filter('woocommerce_get_item_data', array($this, 'display_reserve_box_meta'), 10, 2);

        add_action('woocommerce_checkout_process', array($this, 'process_reservation_before_payment'), 5);
        add_action('woocommerce_checkout_order_processed', array($this, 'finalize_reservation_order'), 20, 3);

        // Register order status hooks like original
        $this->register_order_status_hooks();

        add_action('wp_ajax_update_reservation_amounts', array($this, 'ajax_update_reservation_amounts'));
        add_action('wp_ajax_nopriv_update_reservation_amounts', array($this, 'ajax_update_reservation_amounts'));
    }

    private function is_product_eligible_for_reservation($product, $context = 'product_page')
    {
        if (!$product || $product->get_type() !== 'combo') {
            return false;
        }

        if ($context === 'product_page' && $product->get_price() < 10000) {
            return false;
        }

        $enable_reservation = get_option('wc_combo_enable_reservation', 'no');
        if ($enable_reservation !== 'yes') {
            return false;
        }

        $criteria = get_option('wc_combo_reservation_criteria', 'all_combo');

        switch ($criteria) {
            case 'all_combo':
                return true;

            case 'categories':
                $allowed_categories = get_option('wc_combo_reservation_categories', array());
                if (empty($allowed_categories)) {
                    return false;
                }

                $product_categories = wp_get_post_terms($product->get_id(), 'product_cat', array('fields' => 'ids'));
                return !empty(array_intersect($product_categories, $allowed_categories));

            case 'meta_value':
                $meta_key = get_option('wc_combo_reservation_meta_key', 'enable_reservation');
                $meta_value = get_option('wc_combo_reservation_meta_value', 'yes');

                if (empty($meta_key)) {
                    return false;
                }

                $product_meta_value = get_post_meta($product->get_id(), $meta_key, true);
                return $product_meta_value === $meta_value;

            default:
                return false;
        }
    }

    private function cart_has_eligible_products()
    {
        if (WC()->cart->is_empty()) {
            return false;
        }

        foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
            $product = $cart_item['data'];
            $parent_combo_id = isset($cart_item['parent_combo_id']) ? $cart_item['parent_combo_id'] : null;

            if ($parent_combo_id) {
                $parent_product = wc_get_product($parent_combo_id);
                if ($parent_product && $this->is_product_eligible_for_reservation($parent_product, 'checkout')) {
                    return true;
                }
            } else {
                if ($this->is_product_eligible_for_reservation($product, 'checkout')) {
                    return true;
                }
            }
        }

        return false;
    }

    public function combo_product_reserve_checkbox()
    {
        global $product;

        if (!$this->is_product_eligible_for_reservation($product)) {
            return;
        }

        $reservation_mode = get_option('wc_combo_reservation_mode', 'product_page');

        if ($reservation_mode === 'product_page') {
            $percentage = get_option('wc_combo_reservation_percentage', 50);
            $price = $product->get_price();
            $deposit = round($price * ($percentage / 100));
            $balance = $price - $deposit;

            echo "<div class='combo-reserve-checkbox' style='margin: 15px 0;'>
                <label style='display: flex; align-items: center; gap: 10px; font-size: 1em;'>
                    <input type='checkbox' name='reserve_box' value='1' style='margin: 0;' />
                    <span style='font-weight: 600;'>
                        Reserve this item with <strong>" . esc_html($percentage) . "%</strong> upfront
                    </span>
                </label>
                <span style='font-size: 0.9em; color: #555; margin-left: 30px; display: block;'>
                    You'll pay <strong>" . wc_price($deposit) . "</strong> now, and <strong>" . wc_price($balance) . "</strong> before delivery.
                </span>
            </div>";
        }
    }

    public function combo_cart_reserve_checkbox()
    {
        $reservation_mode = get_option('wc_combo_reservation_mode', 'product_page');
        $percentage = floatval(get_option('wc_combo_reservation_percentage', 50));

        if ($reservation_mode !== 'checkout') {
            return;
        }

        $enable_reservation = get_option('wc_combo_enable_reservation', 'no');
        if ($enable_reservation !== 'yes') {
            return;
        }

        if (!$this->cart_has_eligible_products()) {
            return;
        }

        $cart_total = $this->get_cart_total_with_shipping();

        if ($cart_total < 10000) {
            return;
        }

        $deposit = round($cart_total * ($percentage / 100));
        $balance = $cart_total - $deposit;

        echo "<div id='combo-reservation-container' class='reserve-box-option' style='margin-bottom:1em; padding: 10px; border: 1px solid #ccc; background: #f9f9f9;'>
        <label style='display: flex; flex-direction: column; gap: 5px;'>
            <span>
                <input type='checkbox' name='reserve_box_checkout' value='yes'>
                Reserve items with <strong><span id='reservation-percentage'>" . esc_html($percentage) . "</span>%</strong> upfront
            </span>
            <span style='font-size: 0.9em; color: #555;'>
                You'll pay <strong><span id='reservation-deposit'>" . wc_price($deposit) . "</span></strong> now, and <strong><span id='reservation-balance'>" . wc_price($balance) . "</span></strong> before delivery.
                <br/>This applies to: <strong style='font-weight:600;'>eligible combo products in your cart</strong>.
            </span>
        </label>
    </div>";
    }

    public function add_reserve_box_cart_item_data($cart_item_data, $product_id)
    {
        $reservation_mode = get_option('wc_combo_reservation_mode', 'product_page');
        $product = wc_get_product($product_id);

        // FIXED: Only add reservation data if checkbox is actually checked
        if ($reservation_mode === 'product_page' && isset($_POST['reserve_box']) && $_POST['reserve_box'] == '1' && $this->is_product_eligible_for_reservation($product)) {
            $cart_item_data['reserve_box'] = true;
        }

        return $cart_item_data;
    }

    public function save_checkout_reserve_to_order_meta($item, $cart_item_key, $values, $order)
    {
        $reservation_mode = get_option('wc_combo_reservation_mode', 'product_page');
        $percentage = get_option('wc_combo_reservation_percentage', 50);

        // FIXED: Only save reservation meta if checkbox was actually checked
        if ('product_page' === $reservation_mode && !empty($values['reserve_box'])) {
            $item->add_meta_data('Reservation', "{$percentage}% deposit (product)");
        }

        // FIXED: Only process checkout reservation if checkbox is checked
        if ('checkout' === $reservation_mode && isset($_POST['reserve_box_checkout']) && $_POST['reserve_box_checkout'] === 'yes') {
            $parent_combo_id = isset($values['parent_combo_id']) ? $values['parent_combo_id'] : null;
            $is_eligible = false;

            if ($parent_combo_id) {
                $parent_product = wc_get_product($parent_combo_id);
                $is_eligible = $parent_product && $this->is_product_eligible_for_reservation($parent_product, 'checkout');
            } else {
                $is_eligible = $this->is_product_eligible_for_reservation($values['data'], 'checkout');
            }

            if ($is_eligible) {
                $line_total = $values['data']->get_regular_price() * $values['quantity'];
                $deposit = round($line_total * ($percentage / 100));
                $balance = $line_total - $deposit;

                $item->add_meta_data('Reservation', "{$percentage}% deposit (checkout)");
                $item->add_meta_data('_combo_balance_amount', $balance);
            }
        }
    }

    public function display_reserve_box_meta($item_data, $cart_item)
    {
        $reservation_mode = get_option('wc_combo_reservation_mode', 'product_page');
        $percentage = get_option('wc_combo_reservation_percentage', 50);

        // FIXED: Only display reservation meta if it was actually selected
        if ($reservation_mode === 'product_page' && !empty($cart_item['reserve_box'])) {
            $item_data[] = [
                'name' => 'Reservation',
                'value' => "{$percentage}% deposit (product)"
            ];
        }

        // FIXED: Only display checkout reservation meta if checkbox was checked
        if ($reservation_mode === 'checkout' && isset($_POST['reserve_box_checkout']) && $_POST['reserve_box_checkout'] === 'yes') {
            $parent_combo_id = isset($cart_item['parent_combo_id']) ? $cart_item['parent_combo_id'] : null;
            $is_eligible = false;

            if ($parent_combo_id) {
                $parent_product = wc_get_product($parent_combo_id);
                $is_eligible = $parent_product && $this->is_product_eligible_for_reservation($parent_product, 'checkout');
            } else {
                $is_eligible = $this->is_product_eligible_for_reservation($cart_item['data'], 'checkout');
            }

            if ($is_eligible) {
                $item_data[] = [
                    'name' => 'Reservation',
                    'value' => "{$percentage}% deposit (checkout)"
                ];
            }
        }

        return $item_data;
    }

    public function process_reservation_before_payment()
    {
        $reservation_mode = get_option('wc_combo_reservation_mode', 'product_page');
        
        // FIXED: Only process reservation if checkbox is actually checked
        $is_checkout_reservation = ($reservation_mode === 'checkout' && isset($_POST['reserve_box_checkout']) && $_POST['reserve_box_checkout'] === 'yes');
        $has_product_reservation = false;

        // Check if any cart items have product-level reservations
        if ($reservation_mode === 'product_page') {
            foreach (WC()->cart->get_cart() as $cart_item) {
                if (!empty($cart_item['reserve_box'])) {
                    $has_product_reservation = true;
                    break;
                }
            }
        }

        // FIXED: Exit early if no reservation was selected
        if (!$is_checkout_reservation && !$has_product_reservation) {
            // Clear any existing reservation session data to ensure full payment
            WC()->session->__unset('has_reservation');
            WC()->session->__unset('reservation_percentage');
            return;
        }

        // Store reservation flag in session for later use
        WC()->session->set('has_reservation', true);
        WC()->session->set('reservation_percentage', get_option('wc_combo_reservation_percentage', 50));

        // Important: Don't modify cart totals here as it can interfere with payment processing
        // The actual total modification happens in finalize_reservation_order after order creation
    }

    public function finalize_reservation_order($order_id, $posted_data, $order)
    {
        // FIXED: Only process if reservation was actually selected
        if (!WC()->session->get('has_reservation')) {
            return;
        }

        $percentage = floatval(WC()->session->get('reservation_percentage', 50)) / 100;
        $original_total = $order->get_total();
        $deposit_amount = round($original_total * $percentage, 2);
        $balance_amount = $original_total - $deposit_amount;

        // Store reservation metadata
        $order->update_meta_data('_combo_original_total', $original_total);
        $order->update_meta_data('_combo_deposit_amount', $deposit_amount);
        $order->update_meta_data('_combo_balance_amount', $balance_amount);
        $order->update_meta_data('_combo_is_reservation', 'yes');
        $order->update_meta_data('_combo_balance_paid', 'no');

        // CRITICAL FIX: Update the order total to only charge the deposit amount
        // This ensures the payment gateway processes the correct amount
        $order->set_total($deposit_amount);

        // Also update line item totals proportionally to maintain consistency
        $ratio = $deposit_amount / $original_total;

        // OPTIMIZED: Batch update items instead of individual saves
        $items_to_save = array();
        foreach ($order->get_items() as $item_id => $item) {
            $original_line_total = $item->get_total();
            $new_line_total = round($original_line_total * $ratio, 2);
            $item->set_total($new_line_total);
            $items_to_save[] = $item;
        }

        // Update taxes proportionally if any
        $tax_items_to_save = array();
        foreach ($order->get_items('tax') as $tax_item_id => $tax_item) {
            $original_tax_amount = $tax_item->get_tax_total();
            $new_tax_amount = round($original_tax_amount * $ratio, 2);
            $tax_item->set_tax_total($new_tax_amount);
            $tax_items_to_save[] = $tax_item;
        }

        // Update shipping proportionally if included in reservation
        $shipping_items_to_save = array();
        $include_shipping = get_option('wc_combo_reservation_include_shipping', 'yes');
        if ($include_shipping === 'yes') {
            foreach ($order->get_items('shipping') as $shipping_item_id => $shipping_item) {
                $original_shipping_total = $shipping_item->get_total();
                $new_shipping_total = round($original_shipping_total * $ratio, 2);
                $shipping_item->set_total($new_shipping_total);
                $shipping_items_to_save[] = $shipping_item;
            }
        } else {
            // Remove shipping costs if not included in reservation
            foreach ($order->get_items('shipping') as $shipping_item_id => $shipping_item) {
                $shipping_item->set_total(0);
                $shipping_items_to_save[] = $shipping_item;
            }
        }

        // OPTIMIZED: Save all items in one batch operation
        // This is much faster than individual saves
        foreach ($items_to_save as $item) {
            $item->save();
        }
        foreach ($tax_items_to_save as $tax_item) {
            $tax_item->save();
        }
        foreach ($shipping_items_to_save as $shipping_item) {
            $shipping_item->save();
        }

        // OPTIMIZED: Only recalculate totals if we modified items
        // This avoids expensive recalculation when not needed
        if (!empty($items_to_save) || !empty($tax_items_to_save) || !empty($shipping_items_to_save)) {
            $order->calculate_totals();
        }

        // Set appropriate order status
        if ($balance_amount > 0) {
            $order->add_order_note(sprintf(
                __('Reservation order - customer paid %s%% deposit (%s). Balance of %s due before delivery.', 'woocommerce-combo-product'),
                $percentage * 100,
                wc_price($deposit_amount),
                wc_price($balance_amount)
            ), false);
        }

        // OPTIMIZED: Single save at the end instead of multiple saves
        $order->save();

        // Clear session
        WC()->session->__unset('has_reservation');
        WC()->session->__unset('reservation_percentage');
    }

    public function add_reservation_admin_note($order, $data)
    {
        if ($order->get_meta('_combo_is_reservation') === 'yes') {
            $balance = $order->get_meta('_combo_balance_amount');
            $order->add_order_note(
                sprintf(__('Reservation order created. Balance of %s due before delivery.', 'woocommerce-combo-product'), wc_price($balance)),
                false
            );
        }
    }

    public function register_order_status_hooks()
    {
        add_action('woocommerce_order_status_changed', function ($order_id, $from_status, $to_status) {
            // OPTIMIZED: Early return if not a status we care about
            if (!in_array($to_status, ['processing', 'completed'])) {
                return;
            }

            $order = wc_get_order($order_id);
            if (!$order) {
                return;
            }

            // OPTIMIZED: Check meta early to avoid unnecessary processing
            $is_reservation = $order->get_meta('_combo_is_reservation');
            if ($is_reservation !== 'yes') {
                return;
            }

            // If order becomes processing/completed after payment and there's still a balance
            if ($order->get_meta('_combo_balance_paid') !== 'yes') {
                $balance = floatval($order->get_meta('_combo_balance_amount'));
                if ($balance > 0) {
                    // OPTIMIZED: Use static flag to prevent infinite loops instead of remove/add action
                    static $processing = array();
                    if (isset($processing[$order_id])) {
                        return;
                    }
                    $processing[$order_id] = true;

                    // OPTIMIZED: Defer status change if we're in payment completion flow to avoid blocking redirect
                    // Check if we're currently processing payment completion
                    if (doing_action('woocommerce_payment_complete') || doing_action('woocommerce_order_status_changed')) {
                        // Schedule status change to run after redirect (2 seconds delay)
                        if (!wp_next_scheduled('wc_combo_defer_reservation_status_change', array($order_id))) {
                            wp_schedule_single_event(time() + 2, 'wc_combo_defer_reservation_status_change', array($order_id));
                        }
                        unset($processing[$order_id]);
                        return;
                    }

                    // Set to on-hold status since balance is still due (for manual changes)
                    $order->update_status('on-hold', __('Deposit payment received - balance payment required before completion.', 'woocommerce-combo-product'));

                    // Add admin note
                    $order->add_order_note(
                        sprintf(__('Deposit payment successful. Balance of %s must be paid before order can be completed.', 'woocommerce-combo-product'), wc_price($balance)),
                        false
                    );

                    unset($processing[$order_id]);
                }
            }

            // If order is manually changed to completed, mark balance as paid
            elseif ($to_status === 'completed' && $order->get_meta('_combo_balance_paid') !== 'yes') {
                $balance = $order->get_meta('_combo_balance_amount');
                if ($balance > 0) {
                    // Mark balance as paid since order is being completed manually
                    $order->update_meta_data('_combo_balance_paid', 'yes');
                    $order->update_meta_data('_combo_balance_paid_date', current_time('mysql'));
                    $order->update_meta_data('_combo_balance_payment_method', 'manual_cash');
                    $order->save();

                    $order->add_order_note(
                        __('Balance payment received via cash/manual payment. Order marked as completed.', 'woocommerce-combo-product'),
                        false
                    );
                }
            }
        }, 10, 3);

        // Handle deferred status change (runs after redirect to avoid blocking)
        add_action('wc_combo_defer_reservation_status_change', function ($order_id) {
            $order = wc_get_order($order_id);
            if (!$order) {
                return;
            }

            // Double-check conditions
            if ($order->get_meta('_combo_is_reservation') !== 'yes') {
                return;
            }

            if ($order->get_meta('_combo_balance_paid') === 'yes') {
                return;
            }

            $balance = floatval($order->get_meta('_combo_balance_amount'));
            $current_status = $order->get_status();
            
            // Only change status if it's still processing/completed
            if ($balance > 0 && in_array($current_status, ['processing', 'completed'])) {
                // Set to on-hold status since balance is still due
                $order->update_status('on-hold', __('Deposit payment received - balance payment required before completion.', 'woocommerce-combo-product'));

                // Add admin note
                $order->add_order_note(
                    sprintf(__('Deposit payment successful. Balance of %s must be paid before order can be completed.', 'woocommerce-combo-product'), wc_price($balance)),
                    false
                );
            }
        });
    }

    private function get_cart_total_with_shipping()
    {
        $cart_total = 0;
        $include_shipping = get_option('wc_combo_reservation_include_shipping', 'yes'); // Default to 'yes'

        // Calculate eligible items total
        foreach (WC()->cart->get_cart() as $cart_item) {
            $product = $cart_item['data'];
            $parent_combo_id = isset($cart_item['parent_combo_id']) ? $cart_item['parent_combo_id'] : null;

            if ($parent_combo_id) {
                $parent_product = wc_get_product($parent_combo_id);
                if ($parent_product && $this->is_product_eligible_for_reservation($parent_product, 'checkout')) {
                    $cart_total += $cart_item['line_total'];
                }
            } else {
                if ($this->is_product_eligible_for_reservation($product, 'checkout')) {
                    $cart_total += $cart_item['line_total'];
                }
            }
        }

        if ($include_shipping === 'yes') {
            // Add shipping total including taxes
            $shipping_total = WC()->cart->get_shipping_total();
            $shipping_tax_total = WC()->cart->get_shipping_tax();
            $cart_total += floatval($shipping_total) + floatval($shipping_tax_total);
        }

        return $cart_total;
    }

    public function ajax_update_reservation_amounts()
    {
        check_ajax_referer('wc_combo_reservation_nonce', 'nonce');

        if (!$this->cart_has_eligible_products()) {
            wp_send_json_error('No eligible products in cart');
            return;
        }

        $percentage = floatval(get_option('wc_combo_reservation_percentage', 50)) / 100;
        $cart_total = $this->get_cart_total_with_shipping();

        if ($cart_total < 10000) {
            wp_send_json_error('Cart total below minimum');
            return;
        }

        $deposit = round($cart_total * $percentage, 2);
        $balance = $cart_total - $deposit;

        wp_send_json_success(array(
            'deposit' => wc_price($deposit),
            'balance' => wc_price($balance),
            'percentage' => $percentage * 100,
            'total' => wc_price($cart_total)
        ));
    }
}