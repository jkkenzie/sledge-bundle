<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Core functionality for combo products
 */
class WC_Combo_Product_Core
{
    public function __construct()
    {
        $this->init_hooks();
    }

    private function init_hooks()
    {
        // Only for combo products: apply price filters
        add_filter('woocommerce_product_get_price', array($this, 'custom_combo_product_price'), 10, 2);
        add_filter('woocommerce_product_get_regular_price', array($this, 'custom_combo_product_price'), 10, 2);
        add_filter('woocommerce_product_get_sale_price', array($this, 'custom_combo_product_price'), 10, 2);
        add_filter('woocommerce_get_price_html', array($this, 'custom_combo_product_price_html'), 10, 2);

        // Hook into WooCommerce search queries
        add_action('pre_get_posts', array($this, 'include_combo_products_in_search'));

        // Hook into WooCommerce product save actions
        add_action('save_post_product', array($this, 'set_default_catalog_visibility'));

        // Load custom combo product template
        add_filter('woocommerce_locate_template', array($this, 'load_combo_product_template'), 10, 3);
    }


    public function load_combo_product_template($template, $template_name, $template_path)
    {
        if ($template_name === 'single-product/product-combo.php') {
            $plugin_path = untrailingslashit(plugin_dir_path(__FILE__)) . '/templates/';
            if (file_exists($plugin_path . $template_name)) {
                $template = $plugin_path . $template_name;
            }
        }
        return $template;
    }

    public function calculate_combo_product_price($product_id)
    {
        $combo_products = get_post_meta($product_id, '_combo_products', true);

        if (empty($combo_products)) {
            return 0;
        }

        $total_price = 0.0;
        foreach ($combo_products as $combo_product) {
            $product = wc_get_product($combo_product['product_id']);
            if ($product) {
                $price = floatval($product->get_price());
                $quantity = intval($combo_product['quantity']);
                $total_price += $price * $quantity;
            }
        }

        return $total_price;
    }

    public function custom_combo_product_price($price, $product)
    {
        // CRITICAL: ONLY modify price for combo products
        if (!$product || $product->get_type() !== 'combo') {
            return $price;
        }

        // Additional safety check - ensure we have combo product data
        $combo_products = get_post_meta($product->get_id(), '_combo_products', true);
        if (empty($combo_products)) {
            return $price;
        }

        return $this->calculate_combo_product_price($product->get_id());
    }

    public function custom_combo_product_price_html($price_html, $product)
    {

        // CRITICAL: ONLY modify price HTML for combo products
        if (!$product || $product->get_type() !== 'combo') {
            return $price_html;
        }

        $product_id = $product->get_id();
        $combo_products = get_post_meta($product_id, '_combo_products', true);

        if (empty($combo_products)) {
            return $price_html;
        }

        // Calculate the custom combo product price
        $custom_price = $this->calculate_combo_product_price($product_id);

        // Format the custom price
        $formatted_price = wc_price($custom_price);

        // Return formatted price HTML
        return '<span class="custom-combo-price">' . $formatted_price . '</span>';
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
        $product = wc_get_product($post_id);
        if ($product && $product->get_type() === 'combo') {
            $product->set_catalog_visibility('visible');
            $product->save();
        }
    }
}
