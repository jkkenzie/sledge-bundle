<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cart and checkout functionality for combo products
 */
class WC_Combo_Product_Cart
{
    public function __construct()
    {
        $this->init_hooks();
    }

    private function init_hooks()
    {
        // Handle "Add to Cart" Action
        add_action('wp_ajax_add_combo_to_cart', array($this, 'add_combo_to_cart'));
        add_action('wp_ajax_nopriv_add_combo_to_cart', array($this, 'add_combo_to_cart'));
        
        // Cart display and calculations
        add_filter('woocommerce_get_item_data', array($this, 'display_combo_products_in_cart'), 10, 2);
        add_action('woocommerce_before_calculate_totals', array($this, 'calculate_combo_price'), 20, 1);
        add_action('woocommerce_after_cart_item_quantity_update', array($this, 'update_combo_price_on_quantity_change'), 10, 4);

        // Order item meta
        add_action('woocommerce_checkout_create_order_line_item', array($this, 'add_is_combo_to_order_item_meta'), 10, 4);
        add_filter('woocommerce_order_item_display_meta_key', array($this, 'display_combo_products_meta_key'), 20, 3);
        add_filter('woocommerce_order_item_display_meta_value', array($this, 'display_combo_products_meta_value'), 20, 3);
    }

    public function add_combo_to_cart()
    {
        if (!check_ajax_referer('add_combo_to_cart_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
            return;
        }

        if (!isset($_POST['product_id'])) {
            wp_send_json_error(__('Product ID missing.', 'woocommerce-combo-product'));
            return;
        }

        $product_id = intval($_POST['product_id']);
        $product = wc_get_product($product_id);

        if (!$product || $product->get_type() !== 'combo') {
            wp_send_json_error(__('Invalid product type.', 'woocommerce-combo-product'));
            return;
        }

        $quantity = intval($_POST['quantity']);
        $combo_items = isset($_POST['combo_items']) ? $_POST['combo_items'] : array();

        if (empty($combo_items)) {
            wp_send_json_error(__('No items in this combo.', 'woocommerce-combo-product'));
            return;
        }

        $cart_item_data = array('is_combo' => true);
        $cart_item_key = WC()->cart->add_to_cart($product_id, 1, '', '', $cart_item_data);

        if ($cart_item_key) {
            // Add the combo items to the cart with parent combo ID
            foreach ($combo_items as $combo_item) {
                $item_data = array('parent_combo_id' => $product_id);
                $item_quantity = $quantity * intval($combo_item['quantity']);
                $variation_id = isset($combo_item['variation_id']) ? intval($combo_item['variation_id']) : 0;
                $variations = isset($combo_item['variations']) ? $combo_item['variations'] : array();
                WC()->cart->add_to_cart($combo_item['product_id'], $item_quantity, $variation_id, $variations, $item_data);
            }

            WC()->cart->remove_cart_item($cart_item_key);

            $fragments = WC_AJAX::get_refreshed_fragments();
            wp_send_json_success($fragments);
        } else {
            wp_send_json_error(__('Failed to add combo product to cart.', 'woocommerce-combo-product'));
        }

        wp_die();
    }

    public function display_combo_products_in_cart($item_data, $cart_item)
    {
        if (isset($cart_item['parent_combo_id'])) {
            $parent_product = wc_get_product($cart_item['parent_combo_id']);
            if ($parent_product) {
                $item_data[] = array(
                    'name' => __('Item of', 'woocommerce-combo-product'),
                    'value' => $parent_product->get_name(),
                    'display_class' => 'combopacks-main',
                );
            }
        }

        return $item_data;
    }

    public function calculate_combo_price($cart)
    {
        foreach ($cart->get_cart() as $cart_item) {
            // CRITICAL: Only set price to 0 for actual combo products marked as such
            if (isset($cart_item['is_combo']) && $cart_item['is_combo'] === true) {
                $cart_item['data']->set_price(0);
            }
        }
    }

    public function update_combo_price_on_quantity_change($cart_item_key, $quantity, $old_quantity, $cart)
    {
        $cart_item = $cart->get_cart_item($cart_item_key);
        if (!$cart_item) {
            return;
        }
        
        $product = $cart_item['data'];

        // CRITICAL: Only handle combo products
        if (!$product || !$product->is_type('combo')) {
            return;
        }

        $combo_items = get_post_meta($cart_item['product_id'], '_combo_products', true);

        if (!empty($combo_items)) {
            $combo_price = 0;

            foreach ($combo_items as $combo_item) {
                $combo_item_product = wc_get_product($combo_item['product_id']);
                if ($combo_item_product) {
                    $combo_price += floatval($combo_item_product->get_price()) * intval($combo_item['quantity']) * $quantity;
                }
            }

            $cart_item['data']->set_price($combo_price);
        }
    }

    public function add_is_combo_to_order_item_meta($item, $cart_item_key, $values, $order)
    {
        if (isset($values['is_combo']) && $values['is_combo']) {
            $item->add_meta_data('_is_combo', 'yes');
        }
        
        if (isset($values['parent_combo_id'])) {
            $item->add_meta_data('parent_combo_id', $values['parent_combo_id']);
        }
    }

    public function display_combo_products_meta_key($display_key, $meta, $item)
    {
        if ($meta->key === 'parent_combo_id') {
            return __('Item of', 'woocommerce-combo-product');
        }
        return $display_key;
    }

    public function display_combo_products_meta_value($display_value, $meta, $item)
    {
        if ($meta->key === 'parent_combo_id') {
            $parent_product = wc_get_product($meta->value);
            return $parent_product ? $parent_product->get_name() : $display_value;
        }
        return $display_value;
    }
}