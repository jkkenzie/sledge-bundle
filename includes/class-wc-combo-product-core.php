<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Core functionality for combo products
 */
class WC_Combo_Product_Core
{
    /** @var array<int, true> Prevent recursive price calculations. */
    private static $price_stack = array();

    public function __construct()
    {
        $this->init_hooks();
    }

    private function init_hooks()
    {
        add_filter('woocommerce_product_get_price', array($this, 'custom_combo_product_price'), 10, 2);
        add_filter('woocommerce_product_get_regular_price', array($this, 'custom_combo_product_price'), 10, 2);
        add_filter('woocommerce_product_get_sale_price', array($this, 'custom_combo_product_price'), 10, 2);
        add_filter('woocommerce_get_price_html', array($this, 'custom_combo_product_price_html'), 10, 2);

        add_action('pre_get_posts', array($this, 'include_combo_products_in_search'));
        add_action('save_post_product', array($this, 'set_default_catalog_visibility'), 20, 1);
    }

    /**
     * Sum component product prices. Guards against circular combo references.
     *
     * @param int $product_id Combo product ID.
     * @return float
     */
    public function calculate_combo_product_price($product_id)
    {
        $product_id = absint($product_id);
        if (!$product_id || isset(self::$price_stack[$product_id])) {
            return 0.0;
        }

        $combo_products = get_post_meta($product_id, '_combo_products', true);
        if (empty($combo_products) || !is_array($combo_products)) {
            return 0.0;
        }

        self::$price_stack[$product_id] = true;

        $total_price = 0.0;
        foreach ($combo_products as $combo_product) {
            $child_id = isset($combo_product['product_id']) ? absint($combo_product['product_id']) : 0;
            if (!$child_id || $child_id === $product_id || isset(self::$price_stack[$child_id])) {
                continue;
            }

            $product = wc_get_product($child_id);
            if (!$product) {
                continue;
            }

            // Prefer stored price for non-combo children to avoid filter re-entry cost.
            if ($product->get_type() === 'combo') {
                $price = floatval($this->calculate_combo_product_price($child_id));
            } else {
                $price = floatval($product->get_price('edit'));
                if ($price <= 0) {
                    $price = floatval($product->get_regular_price('edit'));
                }
            }

            $quantity = isset($combo_product['quantity']) ? max(1, intval($combo_product['quantity'])) : 1;
            $total_price += $price * $quantity;
        }

        unset(self::$price_stack[$product_id]);

        return $total_price;
    }

    public function custom_combo_product_price($price, $product)
    {
        if (!$product || $product->get_type() !== 'combo') {
            return $price;
        }

        $combo_products = get_post_meta($product->get_id(), '_combo_products', true);
        if (empty($combo_products)) {
            return $price;
        }

        return $this->calculate_combo_product_price($product->get_id());
    }

    public function custom_combo_product_price_html($price_html, $product)
    {
        if (!$product || $product->get_type() !== 'combo') {
            return $price_html;
        }

        $product_id = $product->get_id();
        $combo_products = get_post_meta($product_id, '_combo_products', true);
        if (empty($combo_products)) {
            return $price_html;
        }

        $custom_price = $this->calculate_combo_product_price($product_id);
        return '<span class="custom-combo-price">' . wc_price($custom_price) . '</span>';
    }

    public function include_combo_products_in_search($query)
    {
        if (!is_admin() && $query->is_main_query() && $query->is_search() && $query->is_post_type_archive('product')) {
            $tax_query = (array) $query->get('tax_query');

            $tax_query[] = array(
                'taxonomy' => 'product_type',
                'field'    => 'slug',
                'terms'    => array('combo', 'simple', 'variable'),
                'operator' => 'IN',
            );

            $query->set('tax_query', $tax_query);
        }
    }

    public function set_default_catalog_visibility($post_id)
    {
        static $running = false;
        if ($running || wp_is_post_revision($post_id) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)) {
            return;
        }

        $product = wc_get_product($post_id);
        if (!$product || $product->get_type() !== 'combo') {
            return;
        }

        if ('visible' === $product->get_catalog_visibility()) {
            return;
        }

        $running = true;
        $product->set_catalog_visibility('visible');
        $product->save();
        $running = false;
    }
}
