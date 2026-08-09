<?php
/**
 * Order tracking – custom order statuses for The School Box
 * Registers: Under processing, Not dispatched, Pending Pick Up, Out for delivery, Delivered.
 *
 * @package WooCommerce_Combo_Product
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Combo_Order_Tracking_Statuses
{
    /** Status slugs (max 20 chars for WordPress post status). */
    const STATUS_NOT_DISPATCHED   = 'wc-not-dispatched';
    const STATUS_PENDING_PICKUP  = 'wc-pending-pickup';
    const STATUS_OUT_FOR_DELIVERY = 'wc-out-for-delivery';
    const STATUS_DELIVERED        = 'wc-delivered';

    public function __construct()
    {
        add_filter('woocommerce_register_shop_order_post_statuses', array($this, 'register_post_statuses'), 10, 1);
        add_filter('wc_order_statuses', array($this, 'add_to_order_statuses'), 10, 1);
    }

    /**
     * Register custom post statuses for shop orders.
     *
     * @param array $order_statuses Existing statuses.
     * @return array
     */
    public function register_post_statuses($order_statuses)
    {
        $custom = array(
            self::STATUS_NOT_DISPATCHED => array(
                'label'                     => _x('Not dispatched', 'Order status', 'woocommerce-combo-product'),
                'public'                    => false,
                'exclude_from_search'       => false,
                'show_in_admin_all_list'    => true,
                'show_in_admin_status_list' => true,
                'label_count'               => _n_noop(
                    'Not dispatched <span class="count">(%s)</span>',
                    'Not dispatched <span class="count">(%s)</span>',
                    'woocommerce-combo-product'
                ),
            ),
            self::STATUS_PENDING_PICKUP => array(
                'label'                     => _x('Pending Pick Up', 'Order status', 'woocommerce-combo-product'),
                'public'                    => false,
                'exclude_from_search'       => false,
                'show_in_admin_all_list'    => true,
                'show_in_admin_status_list' => true,
                'label_count'               => _n_noop(
                    'Pending Pick Up <span class="count">(%s)</span>',
                    'Pending Pick Up <span class="count">(%s)</span>',
                    'woocommerce-combo-product'
                ),
            ),
            self::STATUS_OUT_FOR_DELIVERY => array(
                'label'                     => _x('Out for delivery', 'Order status', 'woocommerce-combo-product'),
                'public'                    => false,
                'exclude_from_search'       => false,
                'show_in_admin_all_list'    => true,
                'show_in_admin_status_list' => true,
                'label_count'               => _n_noop(
                    'Out for delivery <span class="count">(%s)</span>',
                    'Out for delivery <span class="count">(%s)</span>',
                    'woocommerce-combo-product'
                ),
            ),
            self::STATUS_DELIVERED => array(
                'label'                     => _x('Delivered', 'Order status', 'woocommerce-combo-product'),
                'public'                    => false,
                'exclude_from_search'       => false,
                'show_in_admin_all_list'    => true,
                'show_in_admin_status_list' => true,
                'label_count'               => _n_noop(
                    'Delivered <span class="count">(%s)</span>',
                    'Delivered <span class="count">(%s)</span>',
                    'woocommerce-combo-product'
                ),
            ),
        );

        return array_merge($order_statuses, $custom);
    }

    /**
     * Add custom statuses to the order status list (dropdowns, display names).
     *
     * @param array $order_statuses Existing statuses.
     * @return array
     */
    public function add_to_order_statuses($order_statuses)
    {
        $custom = array(
            self::STATUS_NOT_DISPATCHED   => _x('Not dispatched', 'Order status', 'woocommerce-combo-product'),
            self::STATUS_PENDING_PICKUP   => _x('Pending Pick Up', 'Order status', 'woocommerce-combo-product'),
            self::STATUS_OUT_FOR_DELIVERY => _x('Out for delivery', 'Order status', 'woocommerce-combo-product'),
            self::STATUS_DELIVERED        => _x('Delivered', 'Order status', 'woocommerce-combo-product'),
        );

        return array_merge($order_statuses, $custom);
    }
}
