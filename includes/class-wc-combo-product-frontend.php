<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Frontend display for combo / bundle products.
 */
class WC_Combo_Product_Frontend
{
    public function __construct()
    {
        add_filter('wc_get_template_part', array($this, 'override_content_template'), 20, 3);
        add_filter('woocommerce_locate_template', array($this, 'locate_plugin_woocommerce_templates'), 20, 3);
        add_action('wp', array($this, 'setup_combo_single'));
        add_action('sledge_bundles_combo_summary_purchase', array($this, 'render_purchase_box'), 10);
        add_filter('woocommerce_output_related_products_args', array($this, 'related_products_args'), 20);
        add_filter('woocommerce_related_products', array($this, 'related_products_same_category'), 20, 3);
        add_filter('woocommerce_cart_totals_order_total_html', array($this, 'add_inclusive_tax_text_to_cart_and_checkout_total'));
    }

    /**
     * Swap WooCommerce content-single-product for combo products.
     *
     * @param string $template Template path.
     * @param string $slug     Template slug.
     * @param string $name     Template name.
     * @return string
     */
    public function override_content_template($template, $slug, $name)
    {
        if ('content' !== $slug || 'single-product' !== $name || !$this->is_combo_product_view()) {
            return $template;
        }

        $located = WC_Combo_Product_Templates::locate_template('content-single-product-combo.php');
        return $located ? $located : $template;
    }

    /**
     * Allow theme/plugin overrides for combo partials loaded via wc_get_template().
     *
     * @param string $template      Found template path.
     * @param string $template_name Requested name.
     * @param string $template_path WC template path.
     * @return string
     */
    public function locate_plugin_woocommerce_templates($template, $template_name, $template_path)
    {
        $combo_templates = array(
            'single-product/combo-items.php',
            'content-single-product-combo.php',
        );

        if (!in_array($template_name, $combo_templates, true)) {
            return $template;
        }

        $located = WC_Combo_Product_Templates::locate_template($template_name);
        return $located ? $located : $template;
    }

    public function setup_combo_single()
    {
        if (!$this->is_combo_product_view()) {
            return;
        }

        // Prevent default summary hooks from duplicating content outside our layout.
        remove_action('woocommerce_before_single_product_summary', 'woocommerce_show_product_sale_flash', 10);
        remove_action('woocommerce_before_single_product_summary', 'woocommerce_show_product_images', 20);
        remove_action('woocommerce_after_single_product_summary', 'woocommerce_output_product_data_tabs', 10);
        remove_action('woocommerce_after_single_product_summary', 'woocommerce_upsell_display', 15);
        remove_action('woocommerce_after_single_product_summary', 'woocommerce_output_related_products', 20);

        // Theme-specific hooks that assume a different single layout.
        remove_action('woocommerce_single_product_summary', 'zoo_single_product_meta', 5);
        remove_action('woocommerce_before_single_product_summary', 'zoo_product_extended_feature', 15);
    }

    public function render_purchase_box()
    {
        global $product;

        if (!$product || $product->get_type() !== 'combo') {
            return;
        }

        echo '<form class="cart combo-cart-form" action="' . esc_url(apply_filters('woocommerce_add_to_cart_form_action', $product->get_permalink())) . '" method="post" enctype="multipart/form-data">';

        do_action('woocommerce_before_add_to_cart_button');

        echo '<div class="wrap-group-qty sledge-combo-purchase">';

        woocommerce_quantity_input(
            array(
                'min_value'   => apply_filters('woocommerce_quantity_input_min', $product->get_min_purchase_quantity(), $product),
                'max_value'   => apply_filters('woocommerce_quantity_input_max', $product->get_max_purchase_quantity(), $product),
                'input_value' => isset($_POST['quantity']) ? wc_stock_amount(wp_unslash($_POST['quantity'])) : $product->get_min_purchase_quantity(), // phpcs:ignore WordPress.Security.NonceVerification.Missing
            )
        );

        echo '<button type="submit" name="add-to-cart" value="' . esc_attr((string) $product->get_id()) . '" class="single_add_to_cart_button button alt combo_add_to_cart_button">';
        echo esc_html($product->single_add_to_cart_text());
        echo '</button>';

        echo '</div>';

        if (function_exists('zoo_button_wishlist')) {
            zoo_button_wishlist();
        }
        if (function_exists('zoo_button_products_compare')) {
            zoo_button_products_compare();
        }

        do_action('woocommerce_after_add_to_cart_button');

        echo '</form>';
    }

    /**
     * Prefer category-based related products for combo singles.
     *
     * @param array $args Related products args.
     * @return array
     */
    public function related_products_args($args)
    {
        if (!$this->is_combo_product_view()) {
            return $args;
        }

        $args['posts_per_page'] = isset($args['posts_per_page']) ? max(4, (int) $args['posts_per_page']) : 4;
        $args['columns']        = isset($args['columns']) ? (int) $args['columns'] : 4;
        return $args;
    }

    /**
     * Restrict related IDs to the same product categories as the combo.
     *
     * @param int[] $related_ids Related product IDs.
     * @param int   $product_id  Current product ID.
     * @param array $args        Query args.
     * @return int[]
     */
    public function related_products_same_category($related_ids, $product_id, $args)
    {
        $product = wc_get_product($product_id);
        if (!$product || $product->get_type() !== 'combo') {
            return $related_ids;
        }

        $term_ids = wc_get_product_term_ids($product_id, 'product_cat');
        if (empty($term_ids)) {
            return $related_ids;
        }

        $limit = isset($args['limit']) ? absint($args['limit']) : 4;
        $query = new WP_Query(
            array(
                'post_type'           => 'product',
                'post_status'         => 'publish',
                'posts_per_page'      => $limit,
                'post__not_in'        => array($product_id),
                'fields'              => 'ids',
                'ignore_sticky_posts' => true,
                'no_found_rows'       => true,
                'tax_query'           => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
                    array(
                        'taxonomy' => 'product_cat',
                        'field'    => 'term_id',
                        'terms'    => $term_ids,
                    ),
                    array(
                        'taxonomy' => 'product_visibility',
                        'field'    => 'name',
                        'terms'    => array('exclude-from-catalog'),
                        'operator' => 'NOT IN',
                    ),
                ),
            )
        );

        return !empty($query->posts) ? array_map('absint', $query->posts) : $related_ids;
    }

    public function add_inclusive_tax_text_to_cart_and_checkout_total($total_html)
    {
        return $total_html;
    }

    /**
     * @return bool
     */
    private function is_combo_product_view()
    {
        if (!function_exists('is_product') || !is_product()) {
            return false;
        }

        $product = wc_get_product(get_the_ID());
        return $product && $product->get_type() === 'combo';
    }
}
