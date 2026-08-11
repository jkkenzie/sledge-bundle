<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Locates and loads plugin templates with theme override support.
 *
 * Override paths (first match wins):
 * 1. {child-or-parent-theme}/sledge-bundles/{template}
 * 2. {child-or-parent-theme}/woocommerce/{template}
 * 3. plugin templates/woocommerce/{template}
 */
final class WC_Combo_Product_Templates
{
    /**
     * @param string               $template Relative path under woocommerce/, e.g. content-single-product-combo.php.
     * @param array<string, mixed> $args     Variables extracted into the template scope.
     */
    public static function get_template($template, $args = array())
    {
        $located = self::locate_template($template);
        if (!$located) {
            return;
        }

        if (!empty($args) && is_array($args)) {
            // phpcs:ignore WordPress.PHP.DontExtract.extract_extract
            extract($args, EXTR_SKIP);
        }

        include $located;
    }

    /**
     * @param string $template Relative template path.
     * @return string Absolute path or empty string.
     */
    public static function locate_template($template)
    {
        $template = ltrim(str_replace('\\', '/', $template), '/');

        $theme_paths = array(
            'sledge-bundles/' . $template,
            'woocommerce/' . $template,
        );

        $located = locate_template($theme_paths);
        if ($located) {
            return $located;
        }

        $plugin_file = SLEDGE_BUNDLES_PATH . 'templates/woocommerce/' . $template;
        if (file_exists($plugin_file)) {
            return $plugin_file;
        }

        return '';
    }
}
