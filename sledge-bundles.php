<?php
/*
Plugin Name:       Sledge Bundles
Description:       Create bundled / combo products in WooCommerce with optional reservation flows.
Version:           1.0.0
Plugin URI:        https://iyisolutions.com
Author:            Sledge / iYi
Author URI:        https://iyisolutions.com
License:           GPLv2 or later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html
Text Domain:       sledge-bundles
Requires PHP:      7.4
Requires Plugins:  woocommerce
*/

if (!defined('ABSPATH')) {
    exit;
}

define('SLEDGE_BUNDLES_FILE', __FILE__);
define('SLEDGE_BUNDLES_PATH', plugin_dir_path(SLEDGE_BUNDLES_FILE));
define('SLEDGE_BUNDLES_URL', plugin_dir_url(SLEDGE_BUNDLES_FILE));
define('SLEDGE_BUNDLES_VERSION', '1.0.0');
define('SLEDGE_BUNDLES_BASENAME', plugin_basename(SLEDGE_BUNDLES_FILE));

// Backward-compatible aliases used by existing include files.
if (!defined('WC_COMBO_PLUGIN_URL')) {
    define('WC_COMBO_PLUGIN_URL', SLEDGE_BUNDLES_URL);
}
if (!defined('WC_COMBO_PLUGIN_PATH')) {
    define('WC_COMBO_PLUGIN_PATH', SLEDGE_BUNDLES_PATH);
}
if (!defined('WC_COMBO_VERSION')) {
    define('WC_COMBO_VERSION', SLEDGE_BUNDLES_VERSION);
}

$sledge_bundles_license_config = require SLEDGE_BUNDLES_PATH . 'includes/license-config.php';
require_once SLEDGE_BUNDLES_PATH . 'includes/class-sledge-bundles-license-client.php';
require_once SLEDGE_BUNDLES_PATH . 'includes/class-sledge-bundles-license-manager.php';
require_once SLEDGE_BUNDLES_PATH . 'includes/class-sledge-bundles-license-admin.php';

/**
 * Plugin bootstrap: licensing + WooCommerce combo product features.
 */
final class Sledge_Bundles_Plugin
{
    private static $instance = null;

    /** @var Sledge_Bundles_License_Manager */
    private $license_manager;

    /** @var array */
    private $config = array();

    public static function instance()
    {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        global $sledge_bundles_license_config;
        $config = is_array($sledge_bundles_license_config) ? $sledge_bundles_license_config : array();
        $this->config = wp_parse_args(
            $config,
            array(
                'edition'             => 'development',
                'endpoint'            => '',
                'public_key'          => '',
                'plugin'              => 'sledge-bundles',
                'validation_interval' => DAY_IN_SECONDS,
                'grace_period'        => 3 * DAY_IN_SECONDS,
            )
        );

        $this->license_manager = new Sledge_Bundles_License_Manager(
            $this->config,
            new Sledge_Bundles_License_Client($this->config['endpoint'], $this->config['public_key'])
        );

        if (!defined('SLEDGE_BUNDLES_EDITION')) {
            define('SLEDGE_BUNDLES_EDITION', $this->config['edition']);
        }

        // Free and development editions are unlocked until a feature split exists.
        // Premium builds require a signed, valid license.
        if (!defined('SLEDGE_BUNDLES_IS_PRO')) {
            define('SLEDGE_BUNDLES_IS_PRO', (bool) $this->license_manager->is_premium_enabled());
        }

        new Sledge_Bundles_License_Admin($this->license_manager);

        add_action('plugins_loaded', array($this, 'init'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin'));
    }

    public function get_license_manager()
    {
        return $this->license_manager;
    }

    public function init()
    {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return;
        }

        if (!$this->license_manager->is_premium_enabled()) {
            return;
        }

        require_once SLEDGE_BUNDLES_PATH . 'includes/class-wc-combo-product-core.php';
        require_once SLEDGE_BUNDLES_PATH . 'includes/class-wc-combo-product-admin.php';
        require_once SLEDGE_BUNDLES_PATH . 'includes/class-wc-combo-product-frontend.php';
        require_once SLEDGE_BUNDLES_PATH . 'includes/class-wc-combo-product-cart.php';
        require_once SLEDGE_BUNDLES_PATH . 'includes/class-wc-combo-product-reservation.php';
        require_once SLEDGE_BUNDLES_PATH . 'includes/class-wc-combo-product-orders.php';
        require_once SLEDGE_BUNDLES_PATH . 'includes/class-wc-combo-order-tracking-statuses.php';
        require_once SLEDGE_BUNDLES_PATH . 'includes/class-wc-combo-product.php';

        new WC_Combo_Product_Main();
        new WC_Combo_Order_Tracking_Statuses();
    }

    public function woocommerce_missing_notice()
    {
        echo '<div class="error"><p><strong>' . esc_html__('Sledge Bundles', 'sledge-bundles') . '</strong> ';
        echo esc_html__('requires WooCommerce to be installed and active.', 'sledge-bundles');
        echo '</p></div>';
    }

    public function enqueue_frontend()
    {
        if (!$this->license_manager->is_premium_enabled()) {
            return;
        }

        wp_enqueue_style(
            'wc-combo-product',
            SLEDGE_BUNDLES_URL . 'assets/css/wc-combo-product.css',
            array(),
            SLEDGE_BUNDLES_VERSION
        );
        wp_enqueue_script(
            'wc-combo-product',
            SLEDGE_BUNDLES_URL . 'assets/js/wc-combo-product.min.js',
            array('jquery'),
            SLEDGE_BUNDLES_VERSION,
            true
        );

        $localization_data = array(
            'ajaxUrl'            => admin_url('admin-ajax.php'),
            'addComboToCartNonce'=> wp_create_nonce('add_combo_to_cart_nonce'),
            'currency_symbol'    => function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : '',
            'currency_position'  => get_option('woocommerce_currency_pos'),
            'thousand_separator' => function_exists('wc_get_price_thousand_separator') ? wc_get_price_thousand_separator() : ',',
            'decimal_separator'  => function_exists('wc_get_price_decimal_separator') ? wc_get_price_decimal_separator() : '.',
            'decimals'           => function_exists('wc_get_price_decimals') ? wc_get_price_decimals() : 2,
        );

        if (function_exists('is_checkout') && is_checkout()) {
            $localization_data['reservation_ajax_url'] = admin_url('admin-ajax.php');
            $localization_data['reservation_nonce'] = wp_create_nonce('wc_combo_reservation_nonce');
        }

        if (function_exists('wc_get_cart_url')) {
            $localization_data['cart_url'] = wc_get_cart_url();
        }

        wp_localize_script('wc-combo-product', 'wcComboProduct', $localization_data);
    }

    public function enqueue_admin($hook)
    {
        if (!$this->license_manager->is_premium_enabled()) {
            return;
        }

        global $post;

        if ('post.php' !== $hook) {
            return;
        }

        if (!isset($post) || 'product' !== get_post_type($post->ID)) {
            return;
        }

        wp_enqueue_script('jquery-ui-core');
        wp_enqueue_script('jquery-ui-accordion');
        wp_enqueue_script('jquery-ui-sortable');
        wp_enqueue_style(
            'combo-product-select-style',
            SLEDGE_BUNDLES_URL . 'assets/css/select2.min.css',
            array(),
            SLEDGE_BUNDLES_VERSION
        );
        wp_enqueue_script(
            'combo-product-select.js',
            SLEDGE_BUNDLES_URL . 'assets/js/select2.min.js',
            array(),
            SLEDGE_BUNDLES_VERSION,
            true
        );
        wp_enqueue_script(
            'combo-product-js',
            SLEDGE_BUNDLES_URL . 'assets/js/combo-product.min.js',
            array('jquery', 'jquery-ui-accordion', 'jquery-ui-sortable'),
            SLEDGE_BUNDLES_VERSION,
            true
        );
    }
}

register_activation_hook(
    SLEDGE_BUNDLES_FILE,
    function () {
        global $sledge_bundles_license_config;
        if (!is_array($sledge_bundles_license_config) || 'premium' !== $sledge_bundles_license_config['edition']) {
            return;
        }
        Sledge_Bundles_License_Manager::schedule();
        Sledge_Bundles_Plugin::instance();
        if (get_option(Sledge_Bundles_License_Manager::KEY_OPTION, '')) {
            do_action(Sledge_Bundles_License_Manager::CRON_HOOK);
        }
    }
);
register_deactivation_hook(SLEDGE_BUNDLES_FILE, array('Sledge_Bundles_License_Manager', 'unschedule'));

Sledge_Bundles_Plugin::instance();
