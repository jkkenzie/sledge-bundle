<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Order management functionality for combo products
 */
class WC_Combo_Product_Orders
{
    public function __construct()
    {
        $this->init_hooks();
    }

    private function init_hooks()
    {
        // Frontend order display
        add_action('woocommerce_order_item_meta_end', array($this, 'display_balance_reminder'), 10, 3);
        add_action('woocommerce_thankyou', array($this, 'maybe_show_pay_balance_link'));
        add_action('woocommerce_view_order', array($this, 'maybe_show_pay_balance_link'));

        // Handle balance payment requests
        add_action('woocommerce_api_pay_combo_balance', array($this, 'process_balance_payment_request'));

        // Order status handling
        $this->register_order_status_hooks();

        // Admin functionality
        add_action('add_meta_boxes', array($this, 'add_order_balance_meta_box'));
        add_action('add_meta_boxes', array($this, 'add_order_tracking_meta_box'));
        add_action('admin_enqueue_scripts', array($this, 'ot_enqueue_order_tracking_repeater_admin_assets'));
        add_action('woocommerce_process_shop_order_meta', array($this, 'ot_process_order_tracking_entries_meta'), 10, 2);

		// Seed destination journey step for new orders.
		add_action('woocommerce_new_order', array($this, 'ot_seed_destination_tracking_step'), 10, 1);
		add_action('woocommerce_checkout_order_processed', array($this, 'ot_seed_destination_tracking_step'), 10, 1);

        add_action('template_redirect', array($this, 'maybe_redirect_woocommerce_order_tracking_requests'));
        add_action('admin_init', array($this, 'handle_admin_generate_balance_link'));
        add_action('woocommerce_admin_order_data_after_order_details', array($this, 'display_order_balance_info'), 10, 1);
        add_action('manage_shop_order_posts_custom_column', array($this, 'customize_origin_column_for_balance_orders'), 2, 2);

        add_action('wp_ajax_combo_mark_balance_paid', array($this, 'handle_ajax_mark_balance_paid'));


        // Add this line instead:
        add_action('admin_footer-post.php', array($this, 'add_reservation_admin_prevention'));

        // Customize order confirmation message
        add_filter('woocommerce_thankyou_order_received_text', array($this, 'customize_order_confirmation_message'), 10, 2);
        // Customize order tracking results page message (same clarity: purchase complete, under processing)
        add_filter('woocommerce_order_tracking_status', array($this, 'customize_order_tracking_status_message'), 10, 2);

        $this->initialize_order_display_manager();
    }
    public function initialize_order_display_manager()
    {
        // Add to your __construct method in WC_Combo_Product_Orders
        add_action('init', function () {
            new WC_Combo_Order_Display_Manager();
        });
    }
    public function display_balance_reminder($item_id, $item, $order)
    {
        if ($order->get_meta('_combo_is_reservation') === 'yes' && $order->get_meta('_combo_balance_paid') !== 'yes') {
            $balance = $order->get_meta('_combo_balance_amount');
            if ($balance > 0) {
                echo '<p><strong>' . __('Balance Due:', 'woocommerce-combo-product') . '</strong> ' . wc_price($balance) . '</p>';
            }
        }
    }

    public function maybe_show_pay_balance_link($order_id)
    {
        $order = wc_get_order($order_id);

        if (!$order || $order->get_meta('_combo_is_reservation') !== 'yes') {
            return;
        }

        $balance = $order->get_meta('_combo_balance_amount');
        $status = $order->get_status();

        if (($status === 'processing' || $status === 'on-hold') && $balance > 0 && $order->get_meta('_combo_balance_paid') !== 'yes') {
            $pay_url = $this->generate_balance_payment_url($order_id);

            echo '<div class="woocommerce-message" style="margin: 20px 0; padding: 15px; background: #f0f8ff; border-left: 4px solid #0073aa;">';
            echo '<h3>' . __('Balance Payment Required', 'woocommerce-combo-product') . '</h3>';
            echo '<p>' . sprintf(__('You have a remaining balance of %s for this order.', 'woocommerce-combo-product'), '<strong>' . wc_price($balance) . '</strong>') . '</p>';
            echo '<a href="' . esc_url($pay_url) . '" class="button" style="background: #0073aa; color: white; text-decoration: none; padding: 10px 20px; border-radius: 3px;">' . __('Pay Balance Now', 'woocommerce-combo-product') . '</a>';
            echo '</div>';
        }
    }

    public function process_balance_payment_request()
    {
        if (!isset($_GET['order_id']) || !isset($_GET['token'])) {
            wc_add_notice(__('Invalid payment link.', 'woocommerce-combo-product'), 'error');
            wp_redirect(wc_get_cart_url());
            exit;
        }

        $order_id = intval($_GET['order_id']);
        $token = sanitize_text_field($_GET['token']);

        $order = wc_get_order($order_id);
        if (!$order) {
            wc_add_notice(__('Order not found.', 'woocommerce-combo-product'), 'error');
            wp_redirect(wc_get_cart_url());
            exit;
        }

        // Verify token
        if (!$this->verify_balance_token($order_id, $token)) {
            wc_add_notice(__('Invalid or expired payment link.', 'woocommerce-combo-product'), 'error');
            wp_redirect(wc_get_cart_url());
            exit;
        }

        // Check if balance is already paid
        if ($order->get_meta('_combo_balance_paid') === 'yes') {
            wc_add_notice(__('Balance has already been paid for this order.', 'woocommerce-combo-product'), 'notice');
            wp_redirect($order->get_view_order_url());
            exit;
        }

        try {
            $balance = floatval($order->get_meta('_combo_balance_amount'));

            if ($balance <= 0) {
                wc_add_notice(__('No balance due for this order.', 'woocommerce-combo-product'), 'notice');
                wp_redirect($order->get_view_order_url());
                exit;
            }

            // Check if a balance payment order already exists and is still valid
            $existing_balance_order_id = $order->get_meta('_balance_payment_order');
            if ($existing_balance_order_id) {
                $existing_balance_order = wc_get_order($existing_balance_order_id);
                if ($existing_balance_order && in_array($existing_balance_order->get_status(), ['pending', 'on-hold'])) {
                    // Redirect to existing balance payment order
                    wp_redirect($existing_balance_order->get_checkout_payment_url());
                    exit;
                }
            }

            // Create a new order for the balance payment
            $balance_order = wc_create_order(array(
                'status' => 'pending' // This will be the correct status for payment
            ));

            // Copy customer information from original order
            $balance_order->set_customer_id($order->get_customer_id());
            $balance_order->set_billing_first_name($order->get_billing_first_name());
            $balance_order->set_billing_last_name($order->get_billing_last_name());
            $balance_order->set_billing_email($order->get_billing_email());
            $balance_order->set_billing_phone($order->get_billing_phone());
            $balance_order->set_address($order->get_address('billing'), 'billing');
            $balance_order->set_address($order->get_address('shipping'), 'shipping');

            // Create a virtual product for the balance payment
            $dummy_product_id = $this->create_or_get_balance_product();
            $dummy_product = wc_get_product($dummy_product_id);

            if (!$dummy_product) {
                throw new Exception(__('Could not create balance payment product.', 'woocommerce-combo-product'));
            }

            // Temporarily set the product price to the balance amount
            $dummy_product->set_price($balance);
            $dummy_product->set_regular_price($balance);

            // Add the product to the balance order
            $item_id = $balance_order->add_product($dummy_product, 1);

            if (!$item_id) {
                throw new Exception(__('Could not add product to balance payment order.', 'woocommerce-combo-product'));
            }

            // Add custom meta to the order item
            $item = $balance_order->get_item($item_id);
            $item->add_meta_data('_balance_for_order', $order->get_order_number());
            $item->save();

            // Set order metadata
            $balance_order->update_meta_data('_original_reservation_order', $order_id);
            $balance_order->update_meta_data('_combo_origin', $order_id);
            $balance_order->update_meta_data('_is_balance_payment', 'yes');

            // Calculate totals
            $balance_order->calculate_totals();

            // Save the balance order
            $balance_order->save();

            // Update original order with balance payment order ID
            $order->update_meta_data('_balance_payment_order', $balance_order->get_id());
            $order->save();

            // Add note to original order
            $order->add_order_note(
                sprintf(
                    __('Customer clicked balance payment link. Created balance payment order #%s for %s.', 'woocommerce-combo-product'),
                    $balance_order->get_id(),
                    wc_price($balance)
                ),
                false
            );

            // Add note to balance order
            $balance_order->add_order_note(
                sprintf(
                    __('Balance payment order created for original order #%s. Amount: %s', 'woocommerce-combo-product'),
                    $order->get_order_number(),
                    wc_price($balance)
                ),
                false
            );

            // Redirect to the balance order's payment page
            wp_redirect($balance_order->get_checkout_payment_url());
            exit;
        } catch (Exception $e) {
            wc_add_notice($e->getMessage(), 'error');
            wp_redirect(wc_get_cart_url());
            exit;
        }
    }

    private function create_or_get_balance_product()
    {
        // Check if balance product already exists
        $balance_product_id = get_option('wc_combo_balance_product_id');

        if ($balance_product_id) {
            $product = wc_get_product($balance_product_id);
            if ($product && $product->exists()) {
                return $balance_product_id;
            }
        }

        // Create a new virtual product for balance payments
        $product = new WC_Product_Simple();
        $product->set_name(__('Balance Payment', 'woocommerce-combo-product'));
        $product->set_slug('combo-balance-payment');
        $product->set_regular_price(0); // Price will be set dynamically
        $product->set_description(__('Balance payment for combo product reservation', 'woocommerce-combo-product'));
        $product->set_short_description(__('Balance payment', 'woocommerce-combo-product'));
        $product->set_virtual(true);
        $product->set_downloadable(false);
        $product->set_catalog_visibility('hidden');
        $product->set_status('private'); // Hidden from catalog
        $product->set_featured(false);
        $product->set_sold_individually(true);

        // Save the product
        $balance_product_id = $product->save();

        if ($balance_product_id) {
            update_option('wc_combo_balance_product_id', $balance_product_id);
            return $balance_product_id;
        }

        return false;
    }

    public function register_order_status_hooks()
    {
        add_action('woocommerce_order_status_changed', function ($order_id, $from_status, $to_status) {
            // OPTIMIZED: Early return if not a status we care about
            if ($to_status !== 'completed') {
                return;
            }

            $order = wc_get_order($order_id);
            if (!$order) {
                return;
            }

            // Handle balance payment completion
            if ($order->get_meta('_is_balance_payment') === 'yes') {
                $this->handle_balance_payment_completion($order_id);
                return;
            }

            // Handle original reservation orders
            // OPTIMIZED: Check meta early to avoid unnecessary processing
            $is_reservation = $order->get_meta('_combo_is_reservation');
            if ($is_reservation !== 'yes') {
                return;
            }

            // If order is being changed to completed but balance is not paid, revert to on-hold
            if ($order->get_meta('_combo_balance_paid') !== 'yes') {
                $balance = $order->get_meta('_combo_balance_amount');
                if ($balance > 0) {
                    // OPTIMIZED: Use static flag to prevent infinite loops instead of remove/add action
                    static $processing = array();
                    if (isset($processing[$order_id])) {
                        return;
                    }
                    $processing[$order_id] = true;

                    // Revert to on-hold status
                    $order->update_status('on-hold', __('Order reverted to on-hold - balance payment required before completion.', 'woocommerce-combo-product'));

                    // Add admin note about the reversion
                    $order->add_order_note(
                        sprintf(__('Automatic completion prevented. Balance of %s must be paid before order can be completed.', 'woocommerce-combo-product'), wc_price($balance)),
                        false
                    );

                    unset($processing[$order_id]);
                }
            }
        }, 10, 3);
    }

    public function handle_balance_payment_completion($order_id)
    {
        $balance_order = wc_get_order($order_id);

        // Check if this is a balance payment order
        if ($balance_order->get_meta('_is_balance_payment') !== 'yes') {
            return;
        }

        $original_order_id = $balance_order->get_meta('_original_reservation_order');

        if (!$original_order_id) {
            return;
        }

        $original_order = wc_get_order($original_order_id);
        if (!$original_order) {
            return;
        }

        // Update original order
        $original_order->update_meta_data('_combo_balance_paid', 'yes');
        $original_order->update_meta_data('_combo_balance_paid_date', current_time('mysql'));
        $original_order->update_meta_data('_combo_balance_payment_id', $order_id);
        $original_order->update_meta_data('_combo_balance_payment_method', 'online');

        // Set status to completed
        $original_order->update_status(
            'completed',
            sprintf(
                __('Balance payment of %s received via order #%s - order marked as completed.', 'woocommerce-combo-product'),
                wc_price($balance_order->get_total()),
                $balance_order->get_id()
            )
        );

        $original_order->save();

        // Add note to balance order
        $balance_order->add_order_note(
            sprintf(
                __('Balance payment completed for original order #%s.', 'woocommerce-combo-product'),
                $original_order->get_order_number()
            ),
            false
        );
    }

    public function add_order_balance_meta_box()
    {
        add_meta_box(
            'combo_balance_payment',
            __('Balance Payment', 'woocommerce-combo-product'),
            array($this, 'display_balance_meta_box'),
            'shop_order',
            'side',
            'high'
        );
    }

    /**
     * Sidebar metabox with a copyable "Track order" URL.
     */
    public function add_order_tracking_meta_box()
    {
        add_meta_box(
            'combo_order_tracking',
            __('Order Tracking', 'woocommerce-combo-product'),
            array($this, 'display_order_tracking_meta_box'),
            'shop_order',
            'side',
            'high'
        );
    }

    /**
     * Render the tracking link meta box.
     *
     * @param WP_Post $post
     */
    public function display_order_tracking_meta_box($post)
    {
        $order = wc_get_order($post->ID);
        if (!$order) {
            return;
        }

        $tracking_page_url = $this->get_public_order_tracking_permalink();
        if (empty($tracking_page_url)) {
            echo '<p>' . esc_html__('Select an Order Tracking page in WooCommerce Combo Packs settings.', 'woocommerce-combo-product') . '</p>';
            return;
        }

        $ot_entries_json_raw = $order->get_meta('_order_tracking_entries', true);
        $ot_entries          = array();
        if (is_string($ot_entries_json_raw) && '' !== $ot_entries_json_raw) {
            $ot_decoded = json_decode($ot_entries_json_raw, true);
            if (is_array($ot_decoded)) {
                $ot_entries = $ot_decoded;
            }
        }
        $ot_entries_json = wp_json_encode($ot_entries);
        $ot_nonce         = wp_create_nonce('ot_save_order_tracking_entries');

        $nonce = wp_create_nonce('woocommerce-order_tracking');
        $tracking_url = add_query_arg(
            array(
                'orderid'     => $order->get_order_number(),
                'order_email' => $order->get_billing_email(),
                'woocommerce-order-tracking-nonce' => $nonce,
            ),
            $tracking_page_url
        );

        echo '<div class="combo-order-tracking-metabox">';

        echo '<p style="margin: 0 0 10px 0;"><strong>' . esc_html__('Order journey entries', 'order-tracking') . '</strong></p>';

        echo '<div id="order-tracking-app" data-entries="' . esc_attr($ot_entries_json) . '"></div>';
        echo '<input type="hidden" id="order_tracking_entries" name="order_tracking_entries" value="' . esc_attr($ot_entries_json) . '" />';
        echo '<input type="hidden" id="ot_order_tracking_entries_nonce" name="ot_order_tracking_entries_nonce" value="' . esc_attr($ot_nonce) . '" />';

        echo '<p style="margin: 0 0 10px 0;"><strong>' . esc_html__('Customer Tracking Link', 'woocommerce-combo-product') . '</strong></p>';

        echo '<div style="display: flex; gap: 5px; margin-bottom: 10px;">';
        echo '<input type="text" id="combo-order-tracking-link" value="' . esc_attr($tracking_url) . '" readonly style="flex: 1; font-family: monospace; font-size: 12px;" />';
        echo '<button type="button" class="button tsb-copy-order-tracking-link" data-clipboard-target="#combo-order-tracking-link">';
        echo '<span class="dashicons dashicons-admin-page" style="vertical-align: middle;"></span>';
        echo '</button>';
        echo '</div>';

        echo '<a href="' . esc_url($tracking_url) . '" target="_blank" class="button button-primary" style="display: inline-block;">' . esc_html__('Open Tracking', 'woocommerce-combo-product') . '</a>';
        echo '</div>';

        ?>
        <script type="text/javascript">
			(function () {
				'use strict';
				document.addEventListener('click', function (ot_e) {
					var ot_btn = ot_e.target && ot_e.target.closest ? ot_e.target.closest('.tsb-copy-order-tracking-link') : null;
					if (!ot_btn) return;

					ot_e.preventDefault();
					var ot_targetSelector = ot_btn.getAttribute('data-clipboard-target');
					if (!ot_targetSelector) return;

					var ot_input = document.querySelector(ot_targetSelector);
					if (!ot_input || !ot_input.select) return;

					ot_input.select();
					try { document.execCommand('copy'); } catch (ot_err) {}

					var ot_originalHtml = ot_btn.innerHTML;
					ot_btn.innerHTML = '<span class="dashicons dashicons-yes" style="vertical-align: middle;"></span>';

					setTimeout(function () {
						ot_btn.innerHTML = ot_originalHtml;
					}, 2000);
				});
			})();
        </script>
        <?php
    }

    /**
     * Enqueue admin repeater script only on the WooCommerce order edit screen.
     *
	 * @param string $ot_hook The current admin hook.
     */
	public function ot_enqueue_order_tracking_repeater_admin_assets($ot_hook)
    {
        if ( ! function_exists('get_current_screen') ) {
            return;
        }

        $ot_screen = get_current_screen();
        if ( empty($ot_screen) || empty($ot_screen->id) || 'shop_order' !== $ot_screen->id ) {
            return;
        }

        wp_enqueue_script(
            'ot-order-tracking-admin-repeater',
            WC_COMBO_PLUGIN_URL . 'assets/js/order-tracking-admin-repeater.js',
            array(),
            WC_COMBO_VERSION,
            true
        );

        wp_localize_script(
            'ot-order-tracking-admin-repeater',
            'otOrderTrackingI18n',
            array(
                'add_tracking_entry' => __( '+ Add tracking entry', 'order-tracking' ),
				'destination_status' => __( 'Destination', 'order-tracking' ),
                'remove'              => __( 'Remove', 'order-tracking' ),
            )
        );
    }

    /**
     * Save the `_order_tracking_entries` JSON meta value.
     *
	 * @param int     $ot_post_id Order post ID.
	 * @param WP_Post $ot_post Order post object.
     */
	public function ot_process_order_tracking_entries_meta($ot_post_id, $ot_post)
    {
		if ( ! current_user_can('edit_shop_order', $ot_post_id) ) {
            return;
        }

        if ( empty($_POST['ot_order_tracking_entries_nonce']) ) {
            return;
        }

        $ot_nonce = sanitize_text_field(wp_unslash($_POST['ot_order_tracking_entries_nonce']));
        if ( ! wp_verify_nonce($ot_nonce, 'ot_save_order_tracking_entries') ) {
            return;
        }

        $ot_raw = isset($_POST['order_tracking_entries']) ? wp_unslash($_POST['order_tracking_entries']) : '';
        $ot_raw = is_string($ot_raw) ? $ot_raw : '';

        $ot_decoded  = array();
        $ot_decoded_json = trim($ot_raw);
        if ( '' !== $ot_decoded_json ) {
            $ot_decoded = json_decode($ot_decoded_json, true);
        }
        if ( ! is_array($ot_decoded) ) {
            $ot_decoded = array();
        }

        // Normalize/sanitize payload.
        $ot_entries = array();
        foreach ($ot_decoded as $ot_entry) {
            if ( ! is_array($ot_entry) ) {
                continue;
            }

            $ot_entries[] = array(
                'location' => isset($ot_entry['location']) ? sanitize_text_field($ot_entry['location']) : '',
                'status'   => isset($ot_entry['status']) ? sanitize_text_field($ot_entry['status']) : '',
                'time'     => isset($ot_entry['time']) ? sanitize_text_field($ot_entry['time']) : '',
            );
        }

		$ot_order = wc_get_order($ot_post_id);
        if ( ! $ot_order ) {
            return;
        }

        $ot_entries_json = wp_json_encode($ot_entries);
        $ot_order->update_meta_data('_order_tracking_entries', $ot_entries_json);
        $ot_order->save();
    }

    public function display_balance_meta_box($post)
    {
        $order = wc_get_order($post->ID);

        if ($order->get_meta('_combo_is_reservation') !== 'yes') {
            echo '<p>' . __('This is not a reservation order.', 'woocommerce-combo-product') . '</p>';
            return;
        }

        $balance = $order->get_meta('_combo_balance_amount');
        $balance_paid = $order->get_meta('_combo_balance_paid') === 'yes';
        $deposit = $order->get_meta('_combo_deposit_amount');
        $original_total = $order->get_meta('_combo_original_total');

        echo '<div class="combo-balance-metabox">';

        // Balance information
        echo '<div style="margin-bottom: 15px;">';
        echo '<p><strong>' . __('Original Total:', 'woocommerce-combo-product') . '</strong> ' . wc_price($original_total) . '</p>';
        echo '<p><strong>' . __('Deposit Paid:', 'woocommerce-combo-product') . '</strong> ' . wc_price($deposit) . '</p>';
        echo '<p><strong>' . __('Balance Amount:', 'woocommerce-combo-product') . '</strong> ' . wc_price($balance) . '</p>';
        echo '<p><strong>' . __('Balance Status:', 'woocommerce-combo-product') . '</strong> ';

        if ($balance_paid) {
            echo '<mark class="yes" style="background: #c6e1c6; color: #5b841b; padding: 2px 5px; border-radius: 3px;">' . __('Paid', 'woocommerce-combo-product') . '</mark>';

            $balance_paid_date = $order->get_meta('_combo_balance_paid_date');
            $payment_method = $order->get_meta('_combo_balance_payment_method');

            if ($balance_paid_date) {
                echo '<br/><small>' . __('Paid on:', 'woocommerce-combo-product') . ' ' . date_i18n(get_option('date_format'), strtotime($balance_paid_date)) . '</small>';
            }
            if ($payment_method) {
                $method_label = $payment_method === 'manual_cash' ? __('Cash/Manual', 'woocommerce-combo-product') : __('Online Payment', 'woocommerce-combo-product');
                echo '<br/><small>' . __('Method:', 'woocommerce-combo-product') . ' ' . $method_label . '</small>';
            }

            $balance_payment_id = $order->get_meta('_combo_balance_payment_id');
            if ($balance_payment_id) {
                echo '<br/><small><a href="' . admin_url('post.php?post=' . $balance_payment_id . '&action=edit') . '">' .
                    sprintf(__('View Payment Order #%s', 'woocommerce-combo-product'), $balance_payment_id) . '</a></small>';
            }
        } else {
            echo '<mark class="no" style="background: #ffb3ba; color: #761919; padding: 2px 5px; border-radius: 3px;">' . __('Unpaid', 'woocommerce-combo-product') . '</mark>';
        }
        echo '</p>';
        echo '</div>';

        // Action buttons for unpaid balance
        if (!$balance_paid && $balance > 0) {
            $pay_url = $this->generate_balance_payment_url($post->ID);

            echo '<div class="combo-balance-actions" style="border-top: 1px solid #ddd; padding-top: 15px;">';

            // Pay Balance button
            echo '<p>';
            echo '<a href="' . esc_url($pay_url) . '" class="button button-primary" target="_blank" style="margin-right: 10px;">';
            echo '<span class="dashicons dashicons-money-alt" style="vertical-align: middle; margin-right: 5px;"></span>';
            echo __('Pay Balance', 'woocommerce-combo-product');
            echo '</a>';

            // Mark as Paid (Manual) button
            echo '<button type="button" class="button combo-mark-balance-paid" data-order-id="' . $post->ID . '" style="margin-right: 10px;">';
            echo '<span class="dashicons dashicons-yes-alt" style="vertical-align: middle; margin-right: 5px;"></span>';
            echo __('Mark as Paid (Cash)', 'woocommerce-combo-product');
            echo '</button>';
            echo '</p>';

            // Payment link section
            echo '<div class="combo-payment-link-section" style="margin-top: 15px; padding: 10px; background: #f9f9f9; border-radius: 4px;">';
            echo '<h4 style="margin: 0 0 10px 0;">' . __('Payment Link', 'woocommerce-combo-product') . '</h4>';

            // Link input field
            echo '<div style="display: flex; gap: 5px; margin-bottom: 10px;">';
            echo '<input type="text" id="combo-payment-link" value="' . esc_attr($pay_url) . '" readonly style="flex: 1; font-family: monospace; font-size: 12px;" />';
            echo '<button type="button" class="button combo-copy-link" data-clipboard-target="#combo-payment-link">';
            echo '<span class="dashicons dashicons-admin-page" style="vertical-align: middle;"></span>';
            echo '</button>';
            echo '</div>';

            // Share options
            echo '<div class="combo-share-options" style="display: flex; gap: 10px; flex-wrap: wrap;">';

            // Email share
            $email_subject = sprintf(__('Balance Payment Required - Order #%s', 'woocommerce-combo-product'), $order->get_order_number());
            $email_body = sprintf(
                __("Hello,\n\nYour order #%s has a remaining balance of %s that needs to be paid.\n\nPlease use this link to complete your payment:\n%s\n\nThank you!", 'woocommerce-combo-product'),
                $order->get_order_number(),
                wc_price($balance),
                $pay_url
            );
            $email_link = 'mailto:' . $order->get_billing_email() . '?subject=' . urlencode($email_subject) . '&body=' . urlencode($email_body);

            echo '<a href="' . esc_url($email_link) . '" class="button button-small">';
            echo '<span class="dashicons dashicons-email-alt" style="vertical-align: middle; margin-right: 3px;"></span>';
            echo __('Email Customer', 'woocommerce-combo-product');
            echo '</a>';

            // WhatsApp share (if phone number exists)
            $phone = $order->get_billing_phone();
            if ($phone) {
                $whatsapp_message = sprintf(
                    __('Hello! Your order #%s has a remaining balance of %s. Please complete payment using this link: %s', 'woocommerce-combo-product'),
                    $order->get_order_number(),
                    strip_tags(wc_price($balance)),
                    $pay_url
                );
                // Clean phone number (remove non-digits)
                $clean_phone = preg_replace('/[^0-9]/', '', $phone);
                $whatsapp_link = 'https://wa.me/' . $clean_phone . '?text=' . urlencode($whatsapp_message);

                echo '<a href="' . esc_url($whatsapp_link) . '" class="button button-small" target="_blank">';
                echo '<span class="dashicons dashicons-smartphone" style="vertical-align: middle; margin-right: 3px;"></span>';
                echo __('WhatsApp', 'woocommerce-combo-product');
                echo '</a>';
            }

            // SMS share (if phone number exists)
            if ($phone) {
                $sms_message = sprintf(
                    __('Order #%s: Balance of %s due. Pay here: %s', 'woocommerce-combo-product'),
                    $order->get_order_number(),
                    strip_tags(wc_price($balance)),
                    $pay_url
                );
                $sms_link = 'sms:' . $phone . '?body=' . urlencode($sms_message);

                echo '<a href="' . esc_url($sms_link) . '" class="button button-small">';
                echo '<span class="dashicons dashicons-format-chat" style="vertical-align: middle; margin-right: 3px;"></span>';
                echo __('SMS', 'woocommerce-combo-product');
                echo '</a>';
            }

            echo '</div>'; // End share options
            echo '</div>'; // End payment link section
            echo '</div>'; // End actions
        }

        // Existing balance payment order info
        $balance_payment_order_id = $order->get_meta('_balance_payment_order');
        if ($balance_payment_order_id) {
            $balance_order = wc_get_order($balance_payment_order_id);
            if ($balance_order) {
                echo '<div style="border-top: 1px solid #ddd; padding-top: 15px; margin-top: 15px;">';
                echo '<h4>' . __('Balance Payment Order', 'woocommerce-combo-product') . '</h4>';
                echo '<p>';
                echo '<strong>' . __('Order ID:', 'woocommerce-combo-product') . '</strong> ';
                echo '<a href="' . admin_url('post.php?post=' . $balance_payment_order_id . '&action=edit') . '">#' . $balance_order->get_id() . '</a><br/>';
                echo '<strong>' . __('Status:', 'woocommerce-combo-product') . '</strong> ' . wc_get_order_status_name($balance_order->get_status()) . '<br/>';
                echo '<strong>' . __('Amount:', 'woocommerce-combo-product') . '</strong> ' . wc_price($balance_order->get_total());
                echo '</p>';
                echo '</div>';
            }
        }

        echo '</div>'; // End metabox

        // Add JavaScript for copy functionality and AJAX
?>
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                // Copy to clipboard functionality
                $('.combo-copy-link').on('click', function(e) {
                    e.preventDefault();
                    var target = $($(this).data('clipboard-target'));
                    target.select();
                    document.execCommand('copy');

                    // Visual feedback
                    var $btn = $(this);
                    var originalHtml = $btn.html();
                    $btn.html('<span class="dashicons dashicons-yes" style="vertical-align: middle;"></span>');
                    $btn.css('background-color', '#46b450');

                    setTimeout(function() {
                        $btn.html(originalHtml);
                        $btn.css('background-color', '');
                    }, 2000);
                });

                // Mark as paid functionality
                $('.combo-mark-balance-paid').on('click', function(e) {
                    e.preventDefault();

                    if (!confirm('<?php echo esc_js(__('Are you sure you want to mark this balance as paid? This action cannot be undone.', 'woocommerce-combo-product')); ?>')) {
                        return;
                    }

                    var orderId = $(this).data('order-id');
                    var $btn = $(this);
                    var originalText = $btn.text();

                    $btn.prop('disabled', true).text('<?php echo esc_js(__('Processing...', 'woocommerce-combo-product')); ?>');

                    $.ajax({
                        url: ajaxurl,
                        method: 'POST',
                        data: {
                            action: 'combo_mark_balance_paid',
                            order_id: orderId,
                            nonce: '<?php echo wp_create_nonce('combo_mark_balance_paid'); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                location.reload();
                            } else {
                                alert(response.data || '<?php echo esc_js(__('Error occurred while updating balance status.', 'woocommerce-combo-product')); ?>');
                                $btn.prop('disabled', false).text(originalText);
                            }
                        },
                        error: function() {
                            alert('<?php echo esc_js(__('Network error occurred.', 'woocommerce-combo-product')); ?>');
                            $btn.prop('disabled', false).text(originalText);
                        }
                    });
                });
            });
        </script>
    <?php
    }

    public function handle_ajax_mark_balance_paid()
    {
        check_ajax_referer('combo_mark_balance_paid', 'nonce');

        if (!current_user_can('edit_shop_orders')) {
            wp_send_json_error(__('Insufficient permissions.', 'woocommerce-combo-product'));
            return;
        }

        $order_id = intval($_POST['order_id']);
        $order = wc_get_order($order_id);

        if (!$order) {
            wp_send_json_error(__('Order not found.', 'woocommerce-combo-product'));
            return;
        }

        if ($order->get_meta('_combo_is_reservation') !== 'yes') {
            wp_send_json_error(__('This is not a reservation order.', 'woocommerce-combo-product'));
            return;
        }

        if ($order->get_meta('_combo_balance_paid') === 'yes') {
            wp_send_json_error(__('Balance has already been marked as paid.', 'woocommerce-combo-product'));
            return;
        }

        // Mark balance as paid
        $order->update_meta_data('_combo_balance_paid', 'yes');
        $order->update_meta_data('_combo_balance_paid_date', current_time('mysql'));
        $order->update_meta_data('_combo_balance_payment_method', 'manual_cash');

        // Update order status to completed
        $order->update_status('completed', __('Balance payment received via cash/manual payment - marked by admin.', 'woocommerce-combo-product'));

        $order->save();

        wp_send_json_success(__('Balance marked as paid successfully.', 'woocommerce-combo-product'));
    }

    public function handle_admin_generate_balance_link()
    {
        // Handle admin-generated balance payment links
    }

    public function display_order_balance_info($order)
    {
        if ($order->get_meta('_combo_is_reservation') !== 'yes') {
            return;
        }

        $balance = $order->get_meta('_combo_balance_amount');
        $deposit = $order->get_meta('_combo_deposit_amount');
        $total = $order->get_meta('_combo_original_total');
        $balance_paid = $order->get_meta('_combo_balance_paid') === 'yes';

        echo '<div class="order_data_column" style="width:100%; clear:both; padding-top:20px;">';
        echo '<h3>' . __('Reservation Payment Details', 'woocommerce-combo-product') . '</h3>';
        echo '<div style="display:flex; margin-bottom:10px;">';
        echo '<div style="flex:1;"><strong>' . __('Original Total:', 'woocommerce-combo-product') . '</strong></div>';
        echo '<div>' . wc_price($total) . '</div>';
        echo '</div>';
        echo '<div style="display:flex; margin-bottom:10px;">';
        echo '<div style="flex:1;"><strong>' . __('Deposit Paid:', 'woocommerce-combo-product') . '</strong></div>';
        echo '<div>' . wc_price($deposit) . '</div>';
        echo '</div>';
        echo '<div style="display:flex; margin-bottom:10px;">';
        echo '<div style="flex:1;"><strong>' . __('Balance Due:', 'woocommerce-combo-product') . '</strong></div>';
        echo '<div>' . wc_price($balance) . '</div>';
        echo '</div>';
        echo '<div style="display:flex; margin-bottom:10px;">';
        echo '<div style="flex:1;"><strong>' . __('Balance Status:', 'woocommerce-combo-product') . '</strong></div>';
        echo '<div>' . ($balance_paid ? '<mark class="yes">' . __('Paid', 'woocommerce-combo-product') . '</mark>' : '<mark class="no">' . __('Unpaid', 'woocommerce-combo-product') . '</mark>') . '</div>';
        echo '</div>';
        echo '</div>';
    }

    public function customize_origin_column_for_balance_orders($column, $post_id)
    {
        if ($column === 'origin') {
            $origin = get_post_meta($post_id, '_combo_origin', true);
            $is_balance = get_post_meta($post_id, '_original_reservation_order', true);

            if ($is_balance && $origin) {
                echo "( " . esc_html($origin) . " ) ";
            }
        }
    }

    private function generate_balance_payment_url($order_id)
    {
        $token = $this->generate_balance_token($order_id);

        return add_query_arg(
            array(
                'wc-api' => 'pay_combo_balance',
                'order_id' => $order_id,
                'token' => $token
            ),
            home_url('/')
        );
    }

    private function generate_balance_token($order_id)
    {
        $token = bin2hex(random_bytes(32));
        $expires = time() + (DAY_IN_SECONDS * 1);

        update_post_meta($order_id, '_combo_balance_token', $token);
        update_post_meta($order_id, '_combo_balance_token_expires', $expires);

        return $token;
    }

    private function verify_balance_token($order_id, $token)
    {
        $stored_token = get_post_meta($order_id, '_combo_balance_token', true);
        $expires = get_post_meta($order_id, '_combo_balance_token_expires', true);

        if (!$stored_token || !$expires) {
            return false;
        }

        if (time() > $expires) {
            delete_post_meta($order_id, '_combo_balance_token');
            delete_post_meta($order_id, '_combo_balance_token_expires');
            return false;
        }

        return hash_equals($stored_token, $token);
    }

    /**
     * Add admin styles and scripts to prevent recalculate on reservation orders
     */
    public function add_reservation_admin_prevention()
    {
        $screen = get_current_screen();
        if (!$screen || $screen->id !== 'shop_order') {
            return;
        }

        global $post;
        if (!$post) {
            return;
        }

        $order = wc_get_order($post->ID);
        if (!$order || $order->get_meta('_combo_is_reservation') !== 'yes') {
            return;
        }

    ?>
        <style type="text/css">
            /* Hide recalculate buttons for reservation orders */
            .calculate-action {
                display: none !important;
            }

            /* Style the warning notice */
            .reservation-recalc-warning {
                background: #fff3cd;
                border: 1px solid #ffeaa7;
                border-left: 4px solid #f39c12;
                padding: 10px 15px;
                margin: 10px 0;
                border-radius: 4px;
            }

            .reservation-recalc-warning p {
                margin: 0;
                color: #856404;
            }
        </style>

        <script type="text/javascript">
            jQuery(document).ready(function($) {
                // Add warning notice about recalculation
                $('.wc-order-totals-items').prepend(
                    '<div class="reservation-recalc-warning">' +
                    '<p><strong><?php echo esc_js(__('Notice:', 'woocommerce-combo-product')); ?></strong> ' +
                    '<?php echo esc_js(__('This is a reservation order. Recalculation is disabled to preserve deposit and balance amounts.', 'woocommerce-combo-product')); ?></p>' +
                    '</div>'
                );

                // Hide all calculate action buttons
                $('.calculate-action').hide();

                // Disable any calculate buttons that might still be visible
                $('button.calculate-action, .calculate-action button').prop('disabled', true);

                // Prevent any AJAX recalculate attempts
                $(document).on('click', '.calculate-action', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    alert('<?php echo esc_js(__('Recalculation is disabled for reservation orders to preserve accurate deposit and balance amounts.', 'woocommerce-combo-product')); ?>');
                    return false;
                });

                // Intercept form submission for recalculation
                $('form#post').on('submit', function(e) {
                    var actionField = $('input[name="action"]');
                    if (actionField.length && actionField.val() === 'woocommerce_calc_line_taxes') {
                        e.preventDefault();
                        alert('<?php echo esc_js(__('Recalculation is not allowed for reservation orders.', 'woocommerce-combo-product')); ?>');
                        return false;
                    }
                });

                // Also prevent any AJAX calls to calc_line_taxes
                $(document).ajaxSend(function(event, xhr, settings) {
                    if (settings.data && settings.data.indexOf('action=woocommerce_calc_line_taxes') !== -1) {
                        xhr.abort();
                        alert('<?php echo esc_js(__('Recalculation blocked for reservation order.', 'woocommerce-combo-product')); ?>');
                    }
                });
            });
        </script>
    <?php
    }

    /**
     * Check if the order contains at least one combo product (bundle/box).
     * The plugin does not store the combo product as a line item; it stores the individual
     * products from the bundle. Those items have order meta 'parent_combo_id' set by
     * WC_Combo_Product_Cart::add_is_combo_to_order_item_meta. Also allow product type
     * 'combo' for backwards compatibility if the combo product ever appears as a line item.
     *
     * @param WC_Order $order
     * @return bool
     */
    private function order_has_combo_item($order)
    {
        if (!$order || !is_a($order, 'WC_Order')) {
            return false;
        }
        foreach ($order->get_items() as $item) {
            // Items from a combo bundle have parent_combo_id meta (the box is not a line item)
            if (is_callable(array($item, 'get_meta')) && $item->get_meta('parent_combo_id')) {
                return true;
            }
            // Fallback: if the combo product itself were ever a line item
            if (method_exists($item, 'get_product')) {
                /** @var \WC_Order_Item_Product $item */
                $product = $item->get_product();
                if ($product && $product->get_type() === 'combo') {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Get the configured WooCommerce order tracking page ID.
     *
     * If not set in plugin settings, we attempt to auto-discover a page containing
     * the `woocommerce_order_tracking` shortcode as a fallback.
     *
     * @return int Zero when no suitable page is found.
     */
    private function get_order_tracking_page_id()
    {
        static $cached_page_id = null;

        if ($cached_page_id !== null) {
            return $cached_page_id;
        }

        $cached_page_id = absint(get_option('wc_combo_order_tracking_page_id', 0));
        if (!empty($cached_page_id)) {
            return $cached_page_id;
        }

        // Auto-discovery fallback: look for any published page containing the tracking shortcode.
        $cached_page_id = 0;
        $pages = get_pages(array(
            'post_type'         => 'page',
            'post_status'       => 'publish',
            'number'            => -1,
            'suppress_filters' => true,
            'fields'            => array('ID', 'post_content'),
        ));

        if (!empty($pages)) {
            foreach ($pages as $page) {
                $content = isset($page->post_content) ? (string) $page->post_content : '';
                if (empty($content)) {
                    continue;
                }

                if (
                    stripos($content, 'woocommerce_order_tracking') !== false
                    || stripos($content, 'woocommerce/order-tracking') !== false
                ) {
                    $cached_page_id = absint($page->ID);
                    break;
                }
            }
        }

        return $cached_page_id;
    }

    /**
     * Get the order tracking page permalink.
     *
     * @return string Empty string when no suitable page is found.
     */
    private function get_public_order_tracking_permalink()
    {
        $page_id = $this->get_order_tracking_page_id();
        if (empty($page_id)) {
            return '';
        }

        return get_permalink($page_id);
    }

    /**
     * Redirect WooCommerce "Track order" requests to the selected tracking page.
     *
     * This prevents guests from being sent to the My Account login screen when they
     * submit tracking details.
     */
    public function maybe_redirect_woocommerce_order_tracking_requests()
    {
        if (is_admin() || wp_doing_ajax()) {
            return;
        }

        // Only handle order tracking requests.
        if (empty($_REQUEST['orderid']) || empty($_REQUEST['order_email'])) {
            return;
        }

        $order_id = ltrim(wc_clean(wp_unslash($_REQUEST['orderid'])), '#');
        $order_email = sanitize_email(wp_unslash($_REQUEST['order_email']));
        if (empty($order_id) || empty($order_email)) {
            return;
        }

        $tracking_page_id = $this->get_order_tracking_page_id();
        if (empty($tracking_page_id)) {
            return;
        }

        // Avoid redirect loops.
        if (function_exists('is_page') && is_page($tracking_page_id)) {
            return;
        }

        $tracking_page_url = get_permalink($tracking_page_id);
        if (empty($tracking_page_url)) {
            return;
        }

        $args = array(
            'orderid'     => $order_id,
            'order_email' => $order_email,
        );

        // Preserve nonce when the request comes from the tracking form (POST).
        if (isset($_REQUEST['woocommerce-order-tracking-nonce'])) {
            $args['woocommerce-order-tracking-nonce'] = sanitize_text_field(wp_unslash($_REQUEST['woocommerce-order-tracking-nonce']));
        }

        if (isset($_REQUEST['_wpnonce']) && empty($args['woocommerce-order-tracking-nonce'])) {
            $args['_wpnonce'] = sanitize_text_field(wp_unslash($_REQUEST['_wpnonce']));
        }

        wp_safe_redirect(add_query_arg($args, $tracking_page_url));
        exit;
    }

    /**
     * Customize the order confirmation message after payment
     *
     * @param string         $message The default message
     * @param WC_Order|false $order   The order object
     * @return string The customized message
     */
    public function customize_order_confirmation_message($message, $order)
    {
        $processing_text = ($order && $this->order_has_combo_item($order))
            ? __('The School Box is under processing.', 'woocommerce-combo-product')
            : __('The Order is under processing.', 'woocommerce-combo-product');
        $new_message     = sprintf(__('Online Order Purchase Complete – %s', 'woocommerce-combo-product'), $processing_text);
        
        // Add order tracking link
        if ($order && is_a($order, 'WC_Order')) {
            // Get tracking URL - use view order URL for logged-in users, tracking page for others
            if (is_user_logged_in() && $order->get_user_id() === get_current_user_id()) {
                $tracking_url = $order->get_view_order_url();
            } else {
                // For non-logged-in users, use the order tracking page
                $tracking_page_url = $this->get_public_order_tracking_permalink();

                if (!empty($tracking_page_url)) {
                    $tracking_url = add_query_arg(
                        array(
                            'orderid'      => $order->get_order_number(),
                            'order_email'  => $order->get_billing_email(),
                            // Allows the tracking shortcode to show results immediately.
                            'woocommerce-order-tracking-nonce' => wp_create_nonce('woocommerce-order_tracking'),
                        ),
                        $tracking_page_url
                    );
                } else {
                    // Fallback to the old behaviour (My Account page), and then to the view order URL.
                    $tracking_page_id = wc_get_page_id('myaccount');
                    if ($tracking_page_id) {
                        $tracking_url = add_query_arg(
                            array(
                                'orderid'     => $order->get_order_number(),
                                'order_email' => $order->get_billing_email(),
                                'woocommerce-order-tracking-nonce' => wp_create_nonce('woocommerce-order_tracking'),
                            ),
                            get_permalink($tracking_page_id)
                        );
                    } else {
                        $tracking_url = $order->get_view_order_url();
                    }
                }
            }
            
            // Add the tracking link to the message
            $new_message .= '<br><br><a href="' . esc_url($tracking_url) . '" class="button" style="display: inline-block; margin-top: 10px;">Click here to track my order.</a>';
        } else {
            // If order is not available, still show the message with a generic tracking link
            $tracking_url = $this->get_public_order_tracking_permalink();

            if (empty($tracking_url)) {
                $tracking_page_id = wc_get_page_id('myaccount');
                if ($tracking_page_id) {
                    $tracking_url = get_permalink($tracking_page_id);
                }
            }

            if (!empty($tracking_url)) {
                $new_message .= '<br><br><a href="' . esc_url($tracking_url) . '" class="button" style="display: inline-block; margin-top: 10px;">Click here to track my order.</a>';
            }
        }
        
        return $new_message;
    }

	/**
	 * For every newly created order, seed a pending "Destination" tracking step.
	 *
	 * Destination location is derived from the order's shipping address (delivery address).
	 * If shipping address is not available, we fall back to the billing address.
	 *
	 * The seeded entry is greyed out on the tracking timeline until an admin adds a time.
	 *
	 * @param int $ot_order_id WooCommerce order ID.
	 */
	public function ot_seed_destination_tracking_step($ot_order_id)
	{
		$ot_order = wc_get_order($ot_order_id);
		if ( ! $ot_order ) {
			return;
		}

		$ot_entries_raw = $ot_order->get_meta('_order_tracking_entries', true);
		$ot_entries     = array();

		if ( is_string($ot_entries_raw) && '' !== $ot_entries_raw ) {
			$ot_decoded = json_decode($ot_entries_raw, true);
			if ( is_array($ot_decoded) ) {
				$ot_entries = $ot_decoded;
			}
		}

		// Only seed when empty / missing.
		if ( ! empty($ot_entries) ) {
			return;
		}

		$ot_destination_location = $this->ot_get_destination_location_string($ot_order);
		$ot_destination_location = '' !== $ot_destination_location ? $ot_destination_location : (string) $ot_order->get_billing_email();

		$ot_entries = array(
			array(
				'location' => $ot_destination_location,
				// Keep status deterministic for the admin timeline ordering logic.
				'status'   => __( 'Destination', 'order-tracking' ),
				'time'     => '',
			),
		);

		$ot_order->update_meta_data('_order_tracking_entries', wp_json_encode($ot_entries));
		$ot_order->save();
	}

	/**
	 * Build the destination location string from shipping/delivery address.
	 *
	 * @param WC_Order $ot_order WooCommerce order.
	 * @return string
	 */
	private function ot_get_destination_location_string($ot_order)
	{
		$ot_ship_1      = trim((string) $ot_order->get_shipping_address_1());
		$ot_ship_city   = trim((string) $ot_order->get_shipping_city());
		$ot_ship_state  = trim((string) $ot_order->get_shipping_state());
		$ot_ship_post   = trim((string) $ot_order->get_shipping_postcode());

		$ot_bill_1      = trim((string) $ot_order->get_billing_address_1());
		$ot_bill_city   = trim((string) $ot_order->get_billing_city());
		$ot_bill_state  = trim((string) $ot_order->get_billing_state());
		$ot_bill_post   = trim((string) $ot_order->get_billing_postcode());

		$ot_has_shipping = '' !== $ot_ship_1 || '' !== $ot_ship_city || '' !== $ot_ship_post;

		if ( $ot_has_shipping ) {
			$ot_parts = array_filter(array($ot_ship_1, $ot_ship_city, $ot_ship_state, $ot_ship_post), 'strlen');
			return implode(', ', $ot_parts);
		}

		$ot_parts = array_filter(array($ot_bill_1, $ot_bill_city, $ot_bill_state, $ot_bill_post), 'strlen');
		return implode(', ', $ot_parts);
	}

    /**
     * Customize the order tracking results page status text.
     * Uses "The Order status is: {status}" for most statuses; only when status is "processing"
     * shows "The School Box is under processing." or "The Order is under processing.".
     * All status text is wrapped in tsb-status-highlight for consistent styling.
     *
     * @param string   $order_status_text The default status text
     * @param WC_Order $order             The order object
     * @return string The customized message
     */
    public function customize_order_tracking_status_message($order_status_text, $order)
    {
        if (!$order || !is_a($order, 'WC_Order')) {
            return $order_status_text;
        }

        $status = $order->get_status();

        if ($status === 'processing') {
            $status_label = $this->order_has_combo_item($order)
                ? __('The School Box is under processing.', 'woocommerce-combo-product')
                : __('The Order is under processing.', 'woocommerce-combo-product');
        } else {
            $status_name   = wc_get_order_status_name($status);
            $status_label = sprintf(
                /* translators: %s: order status name (e.g. Completed, Delivered) */
                __('The Order status is: %s.', 'woocommerce-combo-product'),
                $status_name
            );
        }

        $status_html   = '<span class="tsb-status-highlight">' . esc_html($status_label) . '</span>';
        $order_number  = '<mark class="order-number">' . $order->get_order_number() . '</mark>';
        $order_date    = '<mark class="order-date">' . wc_format_datetime($order->get_date_created()) . '</mark>';
        $message       = sprintf(
            /* translators: 1: order number 2: order date 3: status text (highlighted) */
            __('Order #%1$s was placed on %2$s. Online Order Purchase Complete – %3$s', 'woocommerce-combo-product'),
            $order_number,
            $order_date,
            $status_html
        );

        return $message;
    }
}




class WC_Combo_Order_Display_Manager
{

    public function __construct()
    {
        // Hook into WooCommerce order display system
        add_filter('woocommerce_get_order_item_totals', array($this, 'modify_order_totals_display'), 1000, 3);
        add_action('woocommerce_admin_order_totals_after_total', array($this, 'inject_reservation_totals'), 10, 1);

        // Hook into the core order total methods
        add_filter('woocommerce_order_formatted_line_subtotal', array($this, 'modify_line_subtotal_display'), 10, 3);

        // Override the admin order details display
        add_action('admin_print_styles-post.php', array($this, 'add_admin_order_styles'));
        add_action('admin_footer-post.php', array($this, 'add_admin_order_scripts'));

        // Filter the order total display in various contexts
        add_filter('woocommerce_order_get_formatted_order_total', array($this, 'get_formatted_reservation_total'), 10, 2);
    }

    /**
     * Completely override how order totals are displayed for reservation orders
     */
    public function modify_order_totals_display($total_rows, $order, $tax_display)
    {
        if (!$order || $order->get_meta('_combo_is_reservation') !== 'yes') {
            return $total_rows;
        }

        $original_total = floatval($order->get_meta('_combo_original_total'));
        $deposit_amount = floatval($order->get_meta('_combo_deposit_amount'));
        $balance_amount = floatval($order->get_meta('_combo_balance_amount'));
        $balance_paid = $order->get_meta('_combo_balance_paid') === 'yes';

        if (!$original_total || !$balance_amount) {
            return $total_rows;
        }

        // Build completely new totals array
        $reservation_totals = array();

        // Calculate proper subtotal (original total minus shipping and taxes)
        $shipping_total = $order->get_shipping_total() + $order->get_shipping_tax();
        $items_subtotal = $original_total - $shipping_total;

        // Items Subtotal
        $reservation_totals['cart_subtotal'] = array(
            'label' => __('Items Subtotal:', 'woocommerce'),
            'value' => wc_price($items_subtotal),
        );

        // Balance row (shows as negative like a discount)
        if ($balance_paid) {
            $reservation_totals['balance'] = array(
                'label' => __('Balance (paid):', 'woocommerce-combo-product'),
                'value' => '<span style="color: #00a32a;">-' . wc_price($balance_amount) . '</span>',
            );
        } else {
            $reservation_totals['balance'] = array(
                'label' => __('Balance due:', 'woocommerce-combo-product'),
                'value' => '<span style="color: #d63638;">-' . wc_price($balance_amount) . '</span>',
            );
        }

        // Shipping (if exists)
        if ($shipping_total > 0) {
            $reservation_totals['shipping'] = array(
                'label' => __('Shipping:', 'woocommerce'),
                'value' => wc_price($shipping_total),
            );
        }

        // Order Deposit (what was actually charged)
        $reservation_totals['deposit'] = array(
            'label' => __('Order Deposit:', 'woocommerce-combo-product'),
            'value' => '<strong>' . wc_price($deposit_amount) . '</strong>',
        );

        // If balance is paid, show full order total and paid amount
        if ($balance_paid) {
            $reservation_totals['order_total'] = array(
                'label' => '<strong>' . __('Order Total:', 'woocommerce') . '</strong>',
                'value' => '<strong>' . wc_price($original_total) . '</strong>',
            );

            $reservation_totals['paid_amount'] = array(
                'label' => '<strong style="color: #00a32a;">' . __('Paid:', 'woocommerce-combo-product') . '</strong>',
                'value' => '<strong style="color: #00a32a;">' . wc_price($original_total) . '</strong>',
            );
        }

        return $reservation_totals;
    }

    /**
     * Inject custom HTML after the standard totals section
     */
    public function inject_reservation_totals($order_id)
    {
        $order = wc_get_order($order_id);

        if (!$order || $order->get_meta('_combo_is_reservation') !== 'yes') {
            return;
        }

        $original_total = floatval($order->get_meta('_combo_original_total'));
        $deposit_amount = floatval($order->get_meta('_combo_deposit_amount'));
        $balance_amount = floatval($order->get_meta('_combo_balance_amount'));
        $balance_paid = $order->get_meta('_combo_balance_paid') === 'yes';

        if (!$original_total || !$balance_amount) {
            return;
        }

        // Hide the original totals and replace with our custom display
    ?>
        <div id="reservation-totals-replacement" class="reservation-totals-container">
            <h3><?php _e('Reservation Order Summary', 'woocommerce-combo-product'); ?></h3>
            <table class="wc-order-totals">
                <tr>
                    <td class="label"><?php _e('Items Subtotal:', 'woocommerce'); ?></td>
                    <td class="total"><?php echo wc_price($original_total - $order->get_shipping_total() - $order->get_shipping_tax()); ?></td>
                </tr>
                <tr class="balance-row">
                    <td class="label">
                        <?php echo $balance_paid ? __('Balance (paid):', 'woocommerce-combo-product') : __('Balance due:', 'woocommerce-combo-product'); ?>
                    </td>
                    <td class="total balance-amount <?php echo $balance_paid ? 'paid' : 'unpaid'; ?>">
                        -<?php echo wc_price($balance_amount); ?>
                    </td>
                </tr>
                <?php if ($order->get_shipping_total() > 0): ?>
                    <tr>
                        <td class="label"><?php _e('Shipping:', 'woocommerce'); ?></td>
                        <td class="total"><?php echo wc_price($order->get_shipping_total() + $order->get_shipping_tax()); ?></td>
                    </tr>
                <?php endif; ?>
                <tr class="deposit-row">
                    <td class="label"><strong><?php _e('Order Deposit:', 'woocommerce-combo-product'); ?></strong></td>
                    <td class="total"><strong><?php echo wc_price($deposit_amount); ?></strong></td>
                </tr>
                <?php if ($balance_paid): ?>
                    <tr class="order-total-row">
                        <td class="label"><strong><?php _e('Order Total:', 'woocommerce'); ?></strong></td>
                        <td class="total"><strong><?php echo wc_price($original_total); ?></strong></td>
                    </tr>
                    <tr class="paid-row">
                        <td class="label"><strong><?php _e('Paid:', 'woocommerce-combo-product'); ?></strong></td>
                        <td class="total paid-amount"><strong><?php echo wc_price($original_total); ?></strong></td>
                    </tr>
                <?php endif; ?>
            </table>
        </div>
        <?php
    }

    /**
     * Add custom CSS for reservation order display
     */
    public function add_admin_order_styles()
    {
        $screen = get_current_screen();
        if ($screen && $screen->id === 'shop_order') {
        ?>
            <style type="text/css">
                /* Hide original totals for reservation orders */
                .reservation-order .wc-order-totals-items .wc-order-totals:not(.reservation-totals-container .wc-order-totals) {
                    display: none !important;
                }

                /* Style our custom totals */
                #reservation-totals-replacement>h3 {
                    display: none;
                }

                .reservation-totals-container h3 {
                    display: none;
                }

                .reservation-totals-container .wc-order-totals {
                    width: 100%;
                    border-collapse: collapse;
                }

                .reservation-totals-container .wc-order-totals td {
                    padding: 8px 12px;
                    border-bottom: 1px solid #e9ecef;
                    vertical-align: top;
                }

                .reservation-totals-container .wc-order-totals .label {
                    font-weight: 500;
                    color: #495057;
                    width: 60%;
                }

                .reservation-totals-container .wc-order-totals .total {
                    text-align: right;
                    font-weight: 500;
                    width: 40%;
                }

                /* Balance row styling */
                .balance-row .balance-amount.unpaid {
                    color: #dc3545 !important;
                    font-weight: 600;
                }

                .balance-row .balance-amount.paid {
                    color: #28a745 !important;
                    font-weight: 600;
                }

                /* Deposit row styling */
                .deposit-row {
                    background: #e3f2fd;
                }

                .deposit-row td {
                    border-bottom: 2px solid #2196f3 !important;
                }

                /* Order total and paid styling */
                .order-total-row {
                    background: #f3e5f5;
                }

                .paid-row .paid-amount {
                    color: #28a745 !important;
                    font-weight: bold !important;
                    font-size: 1.1em;
                }

                /* Add visual indicators */
                .balance-row .label::before {
                    content: "⚠ ";
                    color: #ffc107;
                }

                .balance-row.paid .label::before {
                    content: "✓ ";
                    color: #28a745;
                }

                .paid-row .label::before {
                    content: "✓ ";
                    color: #28a745;
                }
            </style>
        <?php
        }
    }

    /**
     * Add JavaScript to handle dynamic display changes
     */
    public function add_admin_order_scripts()
    {
        $screen = get_current_screen();
        if ($screen && $screen->id === 'shop_order') {
        ?>
            <script type="text/javascript">
                jQuery(document).ready(function($) {
                    // Check if this is a reservation order
                    var isReservationOrder = $('#reservation-totals-replacement').length > 0;

                    if (isReservationOrder) {
                        // Add reservation class to body for additional styling
                        $('body').addClass('reservation-order');

                        // Hide the standard order totals section
                        $('.wc-order-totals-items').addClass('reservation-order-totals');

                        // Move our custom totals to replace the standard ones
                        var customTotals = $('#reservation-totals-replacement');
                        if (customTotals.length) {
                            $('.wc-order-totals-items').html(customTotals.html());
                        }

                        // Add status indicators
                        $('.balance-amount.paid').closest('tr').addClass('paid');

                        // Highlight important amounts
                        $('.deposit-row, .paid-row').addClass('highlight-row');
                    }

                    // Handle balance status changes
                    $(document).on('change', 'select[name="_order_status"]', function() {
                        var status = $(this).val();
                        if (status === 'completed' && $('.balance-amount.unpaid').length > 0) {
                            alert('<?php echo esc_js(__('Warning: This reservation order still has an unpaid balance. Consider updating the balance status first.', 'woocommerce-combo-product')); ?>');
                        }
                    });
                });
            </script>
<?php
        }
    }

    /**
     * Override the formatted order total for reservation orders
     */
    public function get_formatted_reservation_total($formatted_total, $order)
    {
        if (!$order || $order->get_meta('_combo_is_reservation') !== 'yes') {
            return $formatted_total;
        }

        $balance_paid = $order->get_meta('_combo_balance_paid') === 'yes';
        $deposit_amount = floatval($order->get_meta('_combo_deposit_amount'));
        $original_total = floatval($order->get_meta('_combo_original_total'));

        // In admin context, show deposit unless balance is paid
        if (is_admin()) {
            if ($balance_paid && $original_total) {
                return wc_price($original_total) . ' <small>(' . __('Fully Paid', 'woocommerce-combo-product') . ')</small>';
            } else if ($deposit_amount) {
                return wc_price($deposit_amount) . ' <small>(' . __('Deposit Only', 'woocommerce-combo-product') . ')</small>';
            }
        }

        return $formatted_total;
    }

    /**
     * Modify line subtotal display for better clarity
     */
    public function modify_line_subtotal_display($subtotal, $item, $order)
    {
        if (!$order || $order->get_meta('_combo_is_reservation') !== 'yes') {
            return $subtotal;
        }

        // Add context to line items in reservation orders
        $balance_paid = $order->get_meta('_combo_balance_paid') === 'yes';

        if (!$balance_paid) {
            $subtotal .= ' <small class="reservation-note">(' . __('Full amount', 'woocommerce-combo-product') . ')</small>';
        }

        return $subtotal;
    }
}
