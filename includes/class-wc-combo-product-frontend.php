<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Frontend display functionality for combo products
 */
class WC_Combo_Product_Frontend
{
    public function __construct()
    {
        $this->init_hooks();
    }

    private function init_hooks()
    {
        // Apply custom template structure to ALL single products (both regular and combo)

        remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_title', 5);
        remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_price', 10);
        remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_excerpt', 20);
        remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_sharing', 30);
        remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 20);
        remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_rating', 12);

        add_action('wp_loaded', array($this, 'remove_zoo_actions'));

        add_action('woocommerce_single_product_summary', array($this, 'display_combo_items'), 10);
        add_action('woocommerce_single_product_summary', array($this, 'display_add_to_cart_button'), 20);


        add_action('woocommerce_before_single_product_summary', array($this, 'custom_product_summary'), 20);

        // Add "Inclusive Tax" text to cart total
        add_filter('woocommerce_cart_totals_order_total_html', array($this, 'add_inclusive_tax_text_to_cart_and_checkout_total'));
    }
    public function remove_zoo_actions()
    {
        remove_action('woocommerce_single_product_summary', 'zoo_single_product_meta', 5);
        remove_action('woocommerce_before_single_product_summary', 'zoo_product_extended_feature', 15);
    }

    public function custom_product_summary()
    {

        global $product;

        // Wrap all content in product-summary-side div for consistent theme structure
        echo '<div class="product-summary-side">';
        // Place title, excerpt, price, and rating in woocommerce_before_single_product_summary
        // This maintains the theme's wrapper structure while customizing content placement
        woocommerce_template_single_title();
        woocommerce_template_single_excerpt();
        woocommerce_template_single_price();
        woocommerce_template_single_rating();

        // Include product meta information
        echo '<div class="product-meta">';
        echo '<span class="sku_wrapper">' . esc_html__('SKU:', 'woocommerce') . ' <span class="sku">' . ($product->get_sku() ? $product->get_sku() : esc_html__('N/A', 'woocommerce')) . '</span></span>';
        echo '</div>';
        echo '</div>'; // Close product-summary-side
    }

    public function display_combo_items()
    {
        global $product;

        $combo_products = get_post_meta($product->get_id(), '_combo_products', true);

        if (!empty($combo_products)) {
            echo '<div class="combo-items">';
            echo '<h3>' . __('Items in this School Box:', 'woocommerce') . '</h3>';
            echo '<table id="combo_products_table" class="combo_products_table table-responsive">';
            echo '<thead>';
            echo '<tr class="combo_products_tr">';
            echo '<th style="width: 15%;">' . __('SKU', 'woocommerce') . '</th>';
            echo '<th style="width: 45%;">' . __('Name', 'woocommerce') . '</th>';
            echo '<th style="width: 20%;">' . __('Actions', 'woocommerce') . '</th>';
            echo '<th style="width: 15%;">' . __('Subtotal', 'woocommerce') . '</th>';
            echo '</tr>';
            echo '</thead>';
            echo '<tbody>';

            foreach ($combo_products as $combo_product) {
                $product_id = $combo_product['product_id'];
                $product_in_combo = wc_get_product($product_id);

                if ($product_in_combo) {
                    $quantity = isset($combo_product['quantity']) ? intval($combo_product['quantity']) : 1;

                    $is_optional = isset($combo_product['optional']) ? intval($combo_product['optional']) : 1; // Default to optional (1)

                    // Check if this product is in the "Never Optional Products" list
                    $never_optional_products = get_option('wc_combo_never_optional_products', array());
                    if (!empty($never_optional_products) && in_array($product_id, $never_optional_products)) {
                        $is_optional = 0; // Force to not optional
                    }

                    $is_required = $is_optional ? 0 : 1; // If not optional, then it's required

                    $combo_item_price = floatval($product_in_combo->get_price());
                    $subtotal = $combo_item_price * $quantity;

                    echo '<tr class="combo_products_tr" id="' . $product_id . '" data-optional="' . $is_optional . '" data-required="' . $is_required . '">';
                    echo '<td align="center" class="item_sku" v-align="middle" data-label="SKU"><h5 class="entry-title"> <span class="product-sku">' . esc_html($product_in_combo->get_sku()) . '</span></h5></td>';
                    echo '<td align="left" v-align="middle" data-label="NAME"> <div class="flex-row-10 pl-2"> <span>' . $product_in_combo->get_image(array(80, 80)) . '</span><span>' . esc_html($product_in_combo->get_name()) . '</span></div></td>';
                    echo '<td data-label="ACTIONS">';

                    if ($is_optional) {
                        echo '<a href="#" class="RemoveBundleItem">
                                <span><i aria-hidden="true" class="fas fa-trash"></i></span> 
                                <span>Remove</span>
                            </a>';
                    }

                    echo '<input type="hidden" class="combo_product_price" name="combo_product_price_' . $product_id . '" id="combo_product_price_' . $product_id . '" value="' . $subtotal . '" data-single="' . $combo_item_price . '">';

                    echo filter_var(woocommerce_quantity_input(
                        array(
                            'input_name' => 'combo_quantity_' . $product_id,
                            'input_value' => $quantity,
                            'min_value' => 1,
                            'step' => apply_filters('woocommerce_quantity_input_step', '1', $product_in_combo),
                            'style' => apply_filters('woocommerce_quantity_style', 'float:left; margin-right:10px;', $product_in_combo)
                        ),
                        $product_in_combo,
                        false
                    ));

                    echo '</td>';
                    echo '<td id="combo_product_subtotal_' . $product_id . '" data-label="Subtotal">' . wc_price($subtotal) . '</td>';
                    echo '</tr>';
                }
            }

            echo '</tbody>';
            echo '</table>';
            echo '</div>';
        }
    }

    public function display_add_to_cart_button()
    {
        global $product;

        if (!$product || $product->get_type() !== 'combo') {
            return;
        }

        $this->display_reservation_option();

        echo '<div id="combo-messages" class="combo-messages" style="display: none;"></div>';

        echo '<form class="cart combo-cart-form" action="' . esc_url(apply_filters('woocommerce_add_to_cart_form_action', $product->get_permalink())) . '" method="post" enctype="multipart/form-data">';

        do_action('woocommerce_before_add_to_cart_button');

        echo '<div class="wrap-group-qty">';

        woocommerce_quantity_input(array(
            'min_value' => apply_filters('woocommerce_quantity_input_min', $product->get_min_purchase_quantity(), $product),
            'max_value' => apply_filters('woocommerce_quantity_input_max', $product->get_max_purchase_quantity(), $product),
            'input_value' => isset($_POST['quantity']) ? wc_stock_amount(wp_unslash($_POST['quantity'])) : $product->get_min_purchase_quantity(),
        ));

        // Add to cart button
        echo '<button type="submit" name="add-to-cart" value="' . esc_attr($product->get_id()) . '" class="button alt combo_add_to_cart_button">';
        echo esc_html($product->single_add_to_cart_text());
        echo '</button>';

        echo '</div>';

        zoo_button_wishlist();
        zoo_button_products_compare();

        echo '</form>';
    }

    private function display_reservation_option()
    {
        // This method will be implemented by the reservation class
        // Placeholder for now to prevent errors
        if (class_exists('WC_Combo_Product_Reservation')) {
            $reservation = new WC_Combo_Product_Reservation();
            if (method_exists($reservation, 'combo_product_reserve_checkbox')) {
                $reservation->combo_product_reserve_checkbox();
            }
        }
    }

    public function add_inclusive_tax_text_to_cart_and_checkout_total($total_html)
    {
        // Add inclusive tax text if needed
        return $total_html;
    }
}
