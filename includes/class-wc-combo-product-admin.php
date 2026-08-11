<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin functionality for combo products - UPDATED to make items optional by default
 */
class WC_Combo_Product_Admin
{
    public function __construct()
    {
        $this->init_hooks();
        
        // Add custom field type handlers
        add_action('woocommerce_admin_field_wc_combo_category_search', array($this, 'output_category_search_field'));
        add_action('woocommerce_admin_field_wc_combo_product_search', array($this, 'output_product_search_field'));
        add_action('wp_ajax_wc_combo_search_categories', array($this, 'ajax_search_categories'));
        add_action('wp_ajax_wc_combo_search_products', array($this, 'ajax_search_products'));
        
        add_action('woocommerce_update_options_products_wc_combo_settings', array($this, 'save_custom_fields'));
    }

    private function init_hooks()
    {
        // Show combo products in the admin panel
        add_filter('product_type_selector', array($this, 'add_combo_product_type'));

        // Add combo product tab and data panel
        add_filter('woocommerce_product_data_tabs', array($this, 'add_combo_product_tab'));
        add_action('woocommerce_product_data_panels', array($this, 'add_combo_product_data_panel'));

        // Save combo product fields
        add_action('woocommerce_process_product_meta', array($this, 'save_combo_product_fields'));

        // Settings
        add_filter('woocommerce_get_settings_products', array($this, 'wc_combo_reservation_settings'), 20, 2);
        add_filter('woocommerce_get_sections_products', array($this, 'add_combo_settings_section'));
    }

    public function add_combo_product_type($types)
    {
        $types['combo'] = __('Combo Product', 'woocommerce-combo-product');
        return $types;
    }

    public function add_combo_settings_section($sections)
    {
        $sections['wc_combo_settings'] = 'Combo Packs';
        return $sections;
    }

    public function wc_combo_reservation_settings($settings, $section)
    {
        if ($section === 'wc_combo_settings') {
            $settings[] = array(
                'name' => 'Combo Product Reservation',
                'type' => 'title',
                'desc' => 'Settings for enabling reservation of combo packs.',
                'id'   => 'wc_combo_reservation_title'
            );

            $settings[] = array(
                'name' => __('Enable Reservation', 'woocommerce-combo-product'),
                'desc' => __('Allow customers to reserve combo products with partial payment', 'woocommerce-combo-product'),
                'id'   => 'wc_combo_enable_reservation',
                'type' => 'checkbox',
                'default' => 'no'
            );

            $settings[] = array(
                'name' => __('Reservation Criteria', 'woocommerce-combo-product'),
                'desc' => __('Which combo products should allow reservations', 'woocommerce-combo-product'),
                'id'   => 'wc_combo_reservation_criteria',
                'type' => 'select',
                'options' => array(
                    'all_combo' => __('All Combo Products', 'woocommerce-combo-product'),
                    'categories' => __('Specific Categories', 'woocommerce-combo-product'),
                    'meta_value' => __('Custom Meta Value', 'woocommerce-combo-product')
                ),
                'default' => 'all_combo'
            );

            $settings[] = array(
                'name' => __('Reservation Categories', 'woocommerce-combo-product'),
                'desc' => __('Select which product categories allow reservations (only applies when "Specific Categories" is selected)', 'woocommerce-combo-product'),
                'id'   => 'wc_combo_reservation_categories',
                'type' => 'wc_combo_category_search',
                'default' => array()
            );

            $settings[] = array(
                'name' => __('Meta Key', 'woocommerce-combo-product'),
                'desc' => __('Custom meta key to check for reservation eligibility (only applies when "Custom Meta Value" is selected)', 'woocommerce-combo-product'),
                'id'   => 'wc_combo_reservation_meta_key',
                'type' => 'text',
                'default' => 'enable_reservation'
            );

            $settings[] = array(
                'name' => __('Meta Value', 'woocommerce-combo-product'),
                'desc' => __('Required meta value for reservation eligibility (only applies when "Custom Meta Value" is selected)', 'woocommerce-combo-product'),
                'id'   => 'wc_combo_reservation_meta_value',
                'type' => 'text',
                'default' => 'yes'
            );

            $settings[] = array(
                'name' => __('Reservation Percentage', 'woocommerce-combo-product'),
                'desc' => __('Percentage of total price required as deposit', 'woocommerce-combo-product'),
                'id'   => 'wc_combo_reservation_percentage',
                'type' => 'number',
                'default' => '50',
                'custom_attributes' => array(
                    'min' => '1',
                    'max' => '99'
                )
            );

            $settings[] = array(
                'name' => __('Reservation Mode', 'woocommerce-combo-product'),
                'desc' => __('Where to show reservation option', 'woocommerce-combo-product'),
                'id'   => 'wc_combo_reservation_mode',
                'type' => 'select',
                'options' => array(
                    'product_page' => __('Product Page', 'woocommerce-combo-product'),
                    'checkout' => __('Checkout Page', 'woocommerce-combo-product')
                ),
                'default' => 'product_page'
            );

            $settings[] = array(
                'name' => __('Include Shipping in Reservation', 'woocommerce-combo-product'),
                'desc' => __('Include shipping costs in reservation total calculation', 'woocommerce-combo-product'),
                'id'   => 'wc_combo_reservation_include_shipping',
                'type' => 'checkbox',
                'default' => 'yes'
            );

            $settings[] = array(
                'name' => __('Never Optional Products', 'woocommerce-combo-product'),
                'desc' => __('Select products that will never be optional (cannot be removed) even if marked as optional in combo settings', 'woocommerce-combo-product'),
                'id'   => 'wc_combo_never_optional_products',
                'type' => 'wc_combo_product_search',
                'default' => array()
            );

            $settings[] = array(
                'name' => __('Order Tracking Page', 'woocommerce-combo-product'),
                'desc' => __('Choose the page that contains the WooCommerce order tracking shortcode (`woocommerce_order_tracking`). Guests will be redirected there when tracking orders.', 'woocommerce-combo-product'),
                'id'   => 'wc_combo_order_tracking_page_id',
                'type' => 'single_select_page',
                'default' => 0
            );

            $settings[] = array(
                'type' => 'sectionend',
                'id' => 'wc_combo_reservation_title'
            );
        }

        return $settings;
    }

    public function add_combo_product_tab($tabs)
    {
        $tabs['combo'] = array(
            'label' => __('Combo Product', 'woocommerce-combo-product'),
            'target' => 'combo_product_options',
            'class' => array('show_if_combo'),
            'priority' => 21,
        );
        return $tabs;
    }

    public function save_combo_product_fields($post_id)
    {
        if (isset($_POST['combo_products'])) {
            $combo_products = array();
            $seen_ids = array();
            $post_id = absint($post_id);

            foreach ($_POST['combo_products'] as $combo_product) {
                if (empty($combo_product['product_id'])) {
                    continue;
                }

                $item_id = absint($combo_product['product_id']);
                // Do not allow a combo to include itself, and skip duplicate rows.
                if (!$item_id || $item_id === $post_id || isset($seen_ids[$item_id])) {
                    continue;
                }

                $seen_ids[$item_id] = true;
                $combo_products[] = array(
                    'product_id' => $item_id,
                    'quantity' => intval($combo_product['quantity']),
                    'optional' => isset($combo_product['optional']) ? 1 : 0,
                );
            }
            update_post_meta($post_id, '_combo_products', $combo_products);
        }
    }

    public function add_combo_product_data_panel()
    {
        global $post;
?>
        <div id="combo_product_options" class="bootstrap-iso panel woocommerce_options_panel">
            <div class="options_group show_if_combo">
                <div id="combo_product_fields">
                    <?php
                    $combo_products = get_post_meta($post->ID, '_combo_products', true);
                    if (!empty($combo_products)) {
                        foreach ($combo_products as $index => $combo_product) {
                            echo $this->combo_product_field_html($index, $combo_product['product_id'], $combo_product['quantity']);
                        }
                    }
                    ?>
                </div>

                <!-- Added product selection form with dropdown and template -->
                <div id="add_combo_product_form">
                    <h4><?php _e('Add Product to Combo', 'woocommerce-combo-product'); ?></h4>
                    <div class="row">
                        <div class="col-md-8">
                            <div class="form-group">
                                <label class="search-combo-product-label" for="add_combo_product_select"><?php esc_html_e('Select Product', 'woocommerce-combo-product'); ?></label>
                                <select id="add_combo_product_select" class="form-control">
                                    <option value=""><?php _e('Select a product', 'woocommerce-combo-product'); ?></option>
                                    <?php
                                    $products = $this->get_all_products();
                                    $editing_id = absint($post->ID);
                                    foreach ($products as $id => $title) {
                                        if ($editing_id && absint($id) === $editing_id) {
                                            continue;
                                        }
                                        echo '<option value="' . esc_attr($id) . '">' . esc_html($title) . '</option>';
                                    }
                                    ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <button type="button" id="add_combo_product_button" class="button button-primary"><?php _e('Add Product', 'woocommerce-combo-product'); ?></button>
                        </div>
                    </div>
                </div>

                <!-- Product add new template - UPDATED to have optional checked by default -->
                <script type="text/template" id="new-product-template">
                    <div class="combo_product_field" data-index="${index}">
                        <h3>
                            <span class="combo-field-id">${product_id}</span>
                            <span class="combo-field-title">${product_title}</span>
                            <button type="button" class="button remove_combo_product"><?php _e('Remove', 'woocommerce-combo-product'); ?></button>
                            <input type="hidden" value="${product_id}" name="combo_products[${index}][product_id]">
                        </h3>
                        <div>
                            <div class="combo-field-row">
                                <label for="combo_item_quantity-${index}"><?php _e('Default Quantity', 'woocommerce-combo-product'); ?></label>
                                <input type="number" id="combo_item_quantity-${index}" name="combo_products[${index}][quantity]" value="1" min="1" />
                                <span class="woocommerce-help-tip" tabindex="0" aria-label="<?php esc_attr_e('Quantity the customer will see pre-selected', 'woocommerce-combo-product'); ?>"></span>
                            </div>
                            <div class="combo-field-row combo-field-row--optional">
                                <input type="checkbox" id="combo_products_optional-${index}" name="combo_products[${index}][optional]" value="1" checked="checked" />
                                <label for="combo_products_optional-${index}"><?php _e('Optional item (customer can remove)', 'woocommerce-combo-product'); ?></label>
                                <span class="woocommerce-help-tip" tabindex="0" aria-label="<?php esc_attr_e('When checked, the customer can remove this item from the bundle', 'woocommerce-combo-product'); ?>"></span>
                            </div>
                        </div>
                    </div>
                </script>
            </div>
        </div>
    <?php
    }

    private function get_all_products()
    {
        global $post;

        $products = array();
        $exclude_id = isset($post->ID) ? absint($post->ID) : 0;
        $args = array(
            'post_type' => 'product',
            'posts_per_page' => -1,
            'post_status' => 'publish',
        );

        if ($exclude_id) {
            $args['post__not_in'] = array($exclude_id);
        }

        $product_posts = get_posts($args);
        foreach ($product_posts as $product_post) {
            if ($exclude_id && absint($product_post->ID) === $exclude_id) {
                continue;
            }
            $product = wc_get_product($product_post->ID);
            if ($product && $product->get_type() !== 'combo') {
                $products[$product_post->ID] = $product_post->post_title;
            }
        }

        return $products;
    }

    private function combo_product_field_html($index, $product_id = '', $quantity = 1)
    {
        $product = wc_get_product($product_id);
        if (!$product) return '';

        global $post;
        $combo_products = get_post_meta($post->ID, '_combo_products', true);
        $is_optional = 1; // Default to optional (checked)

        if (!empty($combo_products)) {
            foreach ($combo_products as $combo_product) {
                if ($combo_product['product_id'] == $product_id) {
                    $is_optional = isset($combo_product['optional']) ? $combo_product['optional'] : 1; // Default to 1 if not set
                    break;
                }
            }
        }

        ob_start();
    ?>
        <div class="combo_product_field" data-index="<?php echo esc_attr($index); ?>">
            <h3>
                <span class="combo-field-id"><?php echo esc_html((string) $product_id); ?></span>
                <span class="combo-field-title"><?php echo esc_html($product->get_name()); ?></span>
                <button type="button" class="button remove_combo_product"><?php _e('Remove', 'woocommerce-combo-product'); ?></button>
                <input type="hidden" value="<?php echo esc_attr($product_id); ?>" name="combo_products[<?php echo esc_attr($index); ?>][product_id]">
            </h3>
            <div>
                <div class="combo-field-row">
                    <label for="combo_item_quantity-<?php echo esc_attr($index); ?>"><?php _e('Default Quantity', 'woocommerce-combo-product'); ?></label>
                    <input type="number" id="combo_item_quantity-<?php echo esc_attr($index); ?>" name="combo_products[<?php echo esc_attr($index); ?>][quantity]" value="<?php echo esc_attr($quantity); ?>" min="1" />
                    <span class="woocommerce-help-tip" tabindex="0" aria-label="<?php esc_attr_e('Quantity the customer will see pre-selected', 'woocommerce-combo-product'); ?>"></span>
                </div>
                <div class="combo-field-row combo-field-row--optional">
                    <input type="checkbox" id="combo_products_optional-<?php echo esc_attr($index); ?>" name="combo_products[<?php echo esc_attr($index); ?>][optional]" value="1" <?php checked($is_optional, 1); ?> />
                    <label for="combo_products_optional-<?php echo esc_attr($index); ?>"><?php _e('Optional item (customer can remove)', 'woocommerce-combo-product'); ?></label>
                    <span class="woocommerce-help-tip" tabindex="0" aria-label="<?php esc_attr_e('When checked, the customer can remove this item from the bundle', 'woocommerce-combo-product'); ?>"></span>
                </div>
            </div>
        </div>
<?php
        return ob_get_clean();
    }

    public function output_category_search_field($value)
    {
        $option_value = WC_Admin_Settings::get_option($value['id'], $value['default']);
        if (!is_array($option_value)) {
            $option_value = array();
        }
        
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label for="<?php echo esc_attr($value['id']); ?>"><?php echo esc_html($value['name']); ?></label>
            </th>
            <td class="forminp forminp-<?php echo esc_attr(sanitize_title($value['type'])); ?>">
                <div id="<?php echo esc_attr($value['id']); ?>_container" style="min-height: 50px; border: 1px solid #ddd; padding: 10px; background: #fff;">
                    <div id="<?php echo esc_attr($value['id']); ?>_selected" style="margin-bottom: 10px;">
                        <?php
                        foreach ($option_value as $cat_id) {
                            $category = get_term($cat_id, 'product_cat');
                            if ($category && !is_wp_error($category)) {
                                echo '<span class="category-tag" data-id="' . esc_attr($cat_id) . '" style="display: inline-block; background: #0073aa; color: white; padding: 3px 8px; margin: 2px; border-radius: 3px; font-size: 12px;">';
                                echo esc_html($category->name);
                                echo ' <span class="remove-category" style="cursor: pointer; margin-left: 5px;">&times;</span>';
                                echo '</span>';
                            }
                        }
                        ?>
                    </div>
                    <input type="text" id="<?php echo esc_attr($value['id']); ?>_search" placeholder="<?php esc_attr_e('Search categories...', 'woocommerce-combo-product'); ?>" style="width: 100%; padding: 5px;" />
                    <div id="<?php echo esc_attr($value['id']); ?>_results" style="max-height: 200px; overflow-y: auto; border-top: 1px solid #ddd; margin-top: 10px; display: none;"></div>
                </div>
                <input type="hidden" name="<?php echo esc_attr($value['id']); ?>" id="<?php echo esc_attr($value['id']); ?>" value="<?php echo esc_attr(implode(',', $option_value)); ?>" />
                <?php if (!empty($value['desc'])) : ?>
                    <p class="description"><?php echo wp_kses_post($value['desc']); ?></p>
                <?php endif; ?>
                
                <script type="text/javascript">
                jQuery(document).ready(function($) {
                    var container = $('#<?php echo esc_js($value['id']); ?>_container');
                    var searchInput = $('#<?php echo esc_js($value['id']); ?>_search');
                    var resultsDiv = $('#<?php echo esc_js($value['id']); ?>_results');
                    var selectedDiv = $('#<?php echo esc_js($value['id']); ?>_selected');
                    var hiddenInput = $('#<?php echo esc_js($value['id']); ?>');
                    
                    // Search categories
                    searchInput.on('input', function() {
                        var term = $(this).val();
                        if (term.length < 2) {
                            resultsDiv.hide();
                            return;
                        }
                        
                        $.ajax({
                            url: ajaxurl,
                            data: {
                                action: 'wc_combo_search_categories',
                                term: term,
                                nonce: '<?php echo wp_create_nonce('wc_combo_search'); ?>'
                            },
                            success: function(response) {
                                if (response.success) {
                                    resultsDiv.html('');
                                    $.each(response.data, function(i, category) {
                                        var item = $('<div class="category-result" data-id="' + category.id + '" style="padding: 5px; cursor: pointer; border-bottom: 1px solid #eee;">' + category.name + '</div>');
                                        resultsDiv.append(item);
                                    });
                                    resultsDiv.show();
                                }
                            }
                        });
                    });
                    
                    // Add category
                    resultsDiv.on('click', '.category-result', function() {
                        var id = $(this).data('id');
                        var name = $(this).text();
                        var currentIds = hiddenInput.val().split(',').filter(Boolean);
                        
                        if (currentIds.indexOf(id.toString()) === -1) {
                            currentIds.push(id);
                            hiddenInput.val(currentIds.join(','));
                            
                            var tag = $('<span class="category-tag" data-id="' + id + '" style="display: inline-block; background: #0073aa; color: white; padding: 3px 8px; margin: 2px; border-radius: 3px; font-size: 12px;">' + name + ' <span class="remove-category" style="cursor: pointer; margin-left: 5px;">&times;</span></span>');
                            selectedDiv.append(tag);
                        }
                        
                        searchInput.val('');
                        resultsDiv.hide();
                    });
                    
                    // Remove category
                    selectedDiv.on('click', '.remove-category', function() {
                        var tag = $(this).closest('.category-tag');
                        var id = tag.data('id');
                        var currentIds = hiddenInput.val().split(',').filter(Boolean);
                        var index = currentIds.indexOf(id.toString());
                        
                        if (index > -1) {
                            currentIds.splice(index, 1);
                            hiddenInput.val(currentIds.join(','));
                        }
                        
                        tag.remove();
                    });
                });
                </script>
            </td>
        </tr>
        <?php
    }

    public function output_product_search_field($value)
    {
        $option_value = WC_Admin_Settings::get_option($value['id'], $value['default']);
        if (!is_array($option_value)) {
            $option_value = array();
        }
        
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label for="<?php echo esc_attr($value['id']); ?>"><?php echo esc_html($value['name']); ?></label>
            </th>
            <td class="forminp forminp-<?php echo esc_attr(sanitize_title($value['type'])); ?>">
                <div id="<?php echo esc_attr($value['id']); ?>_container" style="min-height: 50px; border: 1px solid #ddd; padding: 10px; background: #fff;">
                    <div id="<?php echo esc_attr($value['id']); ?>_selected" style="margin-bottom: 10px;">
                        <?php
                        foreach ($option_value as $product_id) {
                            $product = wc_get_product($product_id);
                            if ($product) {
                                echo '<span class="product-tag" data-id="' . esc_attr($product_id) . '" style="display: inline-block; background: #0073aa; color: white; padding: 3px 8px; margin: 2px; border-radius: 3px; font-size: 12px;">';
                                echo esc_html($product->get_name()) . ' (#' . esc_html($product_id) . ')';
                                echo ' <span class="remove-product" style="cursor: pointer; margin-left: 5px;">&times;</span>';
                                echo '</span>';
                            }
                        }
                        ?>
                    </div>
                    <input type="text" id="<?php echo esc_attr($value['id']); ?>_search" placeholder="<?php esc_attr_e('Search products...', 'woocommerce-combo-product'); ?>" style="width: 100%; padding: 5px;" />
                    <div id="<?php echo esc_attr($value['id']); ?>_results" style="max-height: 200px; overflow-y: auto; border-top: 1px solid #ddd; margin-top: 10px; display: none;"></div>
                </div>
                <input type="hidden" name="<?php echo esc_attr($value['id']); ?>" id="<?php echo esc_attr($value['id']); ?>" value="<?php echo esc_attr(implode(',', $option_value)); ?>" />
                <?php if (!empty($value['desc'])) : ?>
                    <p class="description"><?php echo wp_kses_post($value['desc']); ?></p>
                <?php endif; ?>
                
                <script type="text/javascript">
                jQuery(document).ready(function($) {
                    var container = $('#<?php echo esc_js($value['id']); ?>_container');
                    var searchInput = $('#<?php echo esc_js($value['id']); ?>_search');
                    var resultsDiv = $('#<?php echo esc_js($value['id']); ?>_results');
                    var selectedDiv = $('#<?php echo esc_js($value['id']); ?>_selected');
                    var hiddenInput = $('#<?php echo esc_js($value['id']); ?>');
                    
                    // Search products
                    searchInput.on('input', function() {
                        var term = $(this).val();
                        if (term.length < 2) {
                            resultsDiv.hide();
                            return;
                        }
                        
                        $.ajax({
                            url: ajaxurl,
                            data: {
                                action: 'wc_combo_search_products',
                                term: term,
                                nonce: '<?php echo wp_create_nonce('wc_combo_search'); ?>'
                            },
                            success: function(response) {
                                if (response.success) {
                                    resultsDiv.html('');
                                    $.each(response.data, function(i, product) {
                                        var item = $('<div class="product-result" data-id="' + product.id + '" style="padding: 5px; cursor: pointer; border-bottom: 1px solid #eee;">' + product.name + ' (#' + product.id + ')</div>');
                                        resultsDiv.append(item);
                                    });
                                    resultsDiv.show();
                                }
                            }
                        });
                    });
                    
                    // Add product
                    resultsDiv.on('click', '.product-result', function() {
                        var id = $(this).data('id');
                        var name = $(this).text();
                        var currentIds = hiddenInput.val().split(',').filter(Boolean);
                        
                        if (currentIds.indexOf(id.toString()) === -1) {
                            currentIds.push(id);
                            hiddenInput.val(currentIds.join(','));
                            
                            var tag = $('<span class="product-tag" data-id="' + id + '" style="display: inline-block; background: #0073aa; color: white; padding: 3px 8px; margin: 2px; border-radius: 3px; font-size: 12px;">' + name + ' <span class="remove-product" style="cursor: pointer; margin-left: 5px;">&times;</span></span>');
                            selectedDiv.append(tag);
                        }
                        
                        searchInput.val('');
                        resultsDiv.hide();
                    });
                    
                    // Remove product
                    selectedDiv.on('click', '.remove-product', function() {
                        var tag = $(this).closest('.product-tag');
                        var id = tag.data('id');
                        var currentIds = hiddenInput.val().split(',').filter(Boolean);
                        var index = currentIds.indexOf(id.toString());
                        
                        if (index > -1) {
                            currentIds.splice(index, 1);
                            hiddenInput.val(currentIds.join(','));
                        }
                        
                        tag.remove();
                    });
                });
                </script>
            </td>
        </tr>
        <?php
    }

    public function ajax_search_categories()
    {
        check_ajax_referer('wc_combo_search', 'nonce');
        
        $term = sanitize_text_field($_GET['term']);
        $categories = array();
        
        if (strlen($term) >= 2) {
            $args = array(
                'taxonomy' => 'product_cat',
                'hide_empty' => false,
                'name__like' => $term,
                'number' => 20
            );
            
            $terms = get_terms($args);
            if (!is_wp_error($terms)) {
                foreach ($terms as $term_obj) {
                    $categories[] = array(
                        'id' => $term_obj->term_id,
                        'name' => $term_obj->name
                    );
                }
            }
        }
        
        wp_send_json_success($categories);
    }

    public function ajax_search_products()
    {
        check_ajax_referer('wc_combo_search', 'nonce');
        
        $term = sanitize_text_field($_GET['term']);
        $products = array();
        
        if (strlen($term) >= 2) {
            global $wpdb;
            
            // Search in both title and content for better matches
            $search_query = $wpdb->prepare("
                SELECT DISTINCT p.ID, p.post_title 
                FROM {$wpdb->posts} p 
                WHERE p.post_type = 'product' 
                AND p.post_status = 'publish'
                AND (
                    p.post_title LIKE %s 
                    OR p.post_content LIKE %s
                    OR p.ID IN (
                        SELECT post_id FROM {$wpdb->postmeta} 
                        WHERE meta_key = '_sku' 
                        AND meta_value LIKE %s
                    )
                )
                ORDER BY 
                    CASE 
                        WHEN p.post_title LIKE %s THEN 1
                        WHEN p.post_title LIKE %s THEN 2
                        ELSE 3
                    END,
                    p.post_title ASC
                LIMIT 20
            ", 
                '%' . $wpdb->esc_like($term) . '%',
                '%' . $wpdb->esc_like($term) . '%',
                '%' . $wpdb->esc_like($term) . '%',
                $wpdb->esc_like($term) . '%',
                '%' . $wpdb->esc_like($term) . '%'
            );
            
            $results = $wpdb->get_results($search_query);
            
            foreach ($results as $result) {
                $product = wc_get_product($result->ID);
                if ($product && $product->get_type() !== 'combo') {
                    $products[] = array(
                        'id' => $result->ID,
                        'name' => $result->post_title
                    );
                }
            }
        }
        
        wp_send_json_success($products);
    }

    public function save_custom_fields()
    {
        // Save Reservation Categories
        if (isset($_POST['wc_combo_reservation_categories'])) {
            $categories = sanitize_text_field($_POST['wc_combo_reservation_categories']);
            $category_array = array_filter(array_map('intval', explode(',', $categories)));
            update_option('wc_combo_reservation_categories', $category_array);
        }
        
        // Save Never Optional Products
        if (isset($_POST['wc_combo_never_optional_products'])) {
            $products = sanitize_text_field($_POST['wc_combo_never_optional_products']);
            $product_array = array_filter(array_map('intval', explode(',', $products)));
            update_option('wc_combo_never_optional_products', $product_array);
        }
    }
}
