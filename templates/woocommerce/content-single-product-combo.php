<?php
/**
 * Combo product single content template.
 *
 * Override in a theme (child themes work via the normal template hierarchy):
 * - yourtheme/sledge-bundles/content-single-product-combo.php
 * - yourtheme/woocommerce/content-single-product-combo.php
 *
 * @package SledgeBundles
 * @version 1.0.0
 */

defined('ABSPATH') || exit;

global $product;

do_action('woocommerce_before_single_product');

if (post_password_required()) {
    echo get_the_password_form(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    return;
}
?>
<div id="product-<?php the_ID(); ?>" <?php wc_product_class('sledge-combo-product', $product); ?>>

	<div class="sledge-combo-layout">
		<div class="sledge-combo-main">
			<div class="sledge-combo-gallery">
				<?php
				woocommerce_show_product_sale_flash();
				woocommerce_show_product_images();
				?>
			</div>

			<?php WC_Combo_Product_Templates::get_template('single-product/combo-items.php'); ?>

			<section class="sledge-combo-tabs">
				<?php woocommerce_output_product_data_tabs(); ?>
			</section>

			<section class="sledge-combo-related">
				<?php woocommerce_output_related_products(); ?>
			</section>
		</div>

		<aside class="sledge-combo-summary product-summary-side entry-summary">
			<div class="sledge-combo-summary__inner">
				<?php
				woocommerce_template_single_title();
				woocommerce_template_single_rating();
				?>

				<div class="sledge-combo-price" data-combo-price-wrap>
					<span class="sledge-combo-price__label"><?php esc_html_e('Bundle total', 'sledge-bundles'); ?></span>
					<p class="price">
						<span class="woocommerce-Price-amount amount" data-combo-total>
							<?php echo wp_kses_post(wc_price(wc_get_price_to_display($product))); ?>
						</span>
					</p>
				</div>

				<?php woocommerce_template_single_excerpt(); ?>

				<div class="product-meta sledge-combo-meta">
					<?php if ($product->get_sku()) : ?>
						<span class="sku_wrapper">
							<?php esc_html_e('SKU:', 'woocommerce'); ?>
							<span class="sku"><?php echo esc_html($product->get_sku()); ?></span>
						</span>
					<?php endif; ?>
					<?php echo wc_get_product_category_list($product->get_id(), ', ', '<span class="posted_in">' . esc_html__('Category:', 'woocommerce') . ' ', '</span>'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>

				<div id="combo-messages" class="combo-messages" style="display:none;" hidden></div>

				<?php
				/**
				 * Combo purchase area (reservation + add to cart).
				 *
				 * @hooked WC_Combo_Product_Frontend::render_purchase_box - 10
				 */
				do_action('sledge_bundles_combo_summary_purchase');
				?>
			</div>
		</aside>
	</div>

</div>

<?php do_action('woocommerce_after_single_product'); ?>
