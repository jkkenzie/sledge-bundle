<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Main plugin class that coordinates all components
 */
class WC_Combo_Product_Main
{
    private $core;
    private $admin;
    private $frontend;
    private $cart;
    private $reservation;
    private $orders;

    public function __construct()
    {
        $this->init_components();
        $this->init_hooks();
    }

    private function init_components()
    {
        $this->core = new WC_Combo_Product_Core();
        $this->admin = new WC_Combo_Product_Admin();
        $this->frontend = new WC_Combo_Product_Frontend();
        $this->cart = new WC_Combo_Product_Cart();
        $this->reservation = new WC_Combo_Product_Reservation();
        $this->orders = new WC_Combo_Product_Orders();
    }

    private function init_hooks()
    {
        // Only remove theme actions for combo products, not for all products
        add_action('template_redirect', array($this, 'maybe_remove_theme_actions'), 10);
    }

    public function maybe_remove_theme_actions()
    {
        // Only proceed if we're on a single product page
        if (!is_product()) {
            return;
        }
        
        global $post;
        if (!$post) {
            return;
        }
        
        // Get the product and check if it's a combo product
        $product = wc_get_product($post->ID);
        if (!$product || $product->get_type() !== 'combo') {
            // Not a combo product - do absolutely nothing, let theme handle everything
            return;
        }
        
        // Only for combo products: remove theme actions that might interfere
        remove_action('woocommerce_single_product_summary', 'zoo_single_product_meta', 5);
        remove_action('woocommerce_before_single_product_summary', 'zoo_product_extended_feature', 15);
    }

    // Getter methods for accessing components
    public function get_core() { return $this->core; }
    public function get_admin() { return $this->admin; }
    public function get_frontend() { return $this->frontend; }
    public function get_cart() { return $this->cart; }
    public function get_reservation() { return $this->reservation; }
    public function get_orders() { return $this->orders; }
}

/**
 * Custom WooCommerce Product Type for Combo Products
 */
class WC_Product_Combo extends WC_Product
{
    public $product_type;

    public function __construct($product)
    {
        $this->product_type = 'combo';
        parent::__construct($product);
        $this->set_catalog_visibility('visible');
    }
}
