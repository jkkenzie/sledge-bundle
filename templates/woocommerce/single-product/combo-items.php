<?php
/**
 * Combo / bundle line items on the single product page.
 *
 * Override:
 * - yourtheme/sledge-bundles/single-product/combo-items.php
 * - yourtheme/woocommerce/single-product/combo-items.php
 *
 * @package SledgeBundles
 * @version 1.0.0
 */

defined('ABSPATH') || exit;

global $product;

if (!$product || $product->get_type() !== 'combo') {
    return;
}

$combo_products = get_post_meta($product->get_id(), '_combo_products', true);
if (empty($combo_products) || !is_array($combo_products)) {
    return;
}

$never_optional = get_option('wc_combo_never_optional_products', array());
if (!is_array($never_optional)) {
    $never_optional = array();
}
?>
<section class="combo-items" aria-labelledby="combo-items-heading">
	<header class="combo-items__header">
		<h2 id="combo-items-heading" class="combo-items__title">
			<?php esc_html_e('Items in this bundle', 'sledge-bundles'); ?>
		</h2>
		<p class="combo-items__subtitle">
			<?php esc_html_e('Adjust quantities or remove optional items. Your bundle total updates automatically.', 'sledge-bundles'); ?>
		</p>
	</header>

	<ul id="combo_products_table" class="combo-items__list" data-combo-items>
		<?php foreach ($combo_products as $combo_product) :
			$item_id = isset($combo_product['product_id']) ? absint($combo_product['product_id']) : 0;
			$item    = $item_id ? wc_get_product($item_id) : false;
			if (!$item) {
				continue;
			}

			$quantity = isset($combo_product['quantity']) ? max(1, absint($combo_product['quantity'])) : 1;
			$is_optional = isset($combo_product['optional']) ? (int) $combo_product['optional'] : 1;
			if ($item_id && in_array($item_id, array_map('absint', $never_optional), true)) {
				$is_optional = 0;
			}

			$unit_price = (float) $item->get_price('edit');
			if ($unit_price <= 0) {
				$unit_price = (float) $item->get_regular_price('edit');
			}
			$subtotal = $unit_price * $quantity;
			$max_qty  = $item->get_max_purchase_quantity();
			?>
			<li
				class="combo-item combo_products_tr<?php echo $is_optional ? ' is-optional' : ' is-required'; ?>"
				id="<?php echo esc_attr((string) $item_id); ?>"
				data-product-id="<?php echo esc_attr((string) $item_id); ?>"
				data-optional="<?php echo esc_attr((string) $is_optional); ?>"
				data-required="<?php echo esc_attr($is_optional ? '0' : '1'); ?>"
				data-unit-price="<?php echo esc_attr(wc_format_decimal($unit_price, wc_get_price_decimals())); ?>"
			>
				<div class="combo-item__media">
					<a href="<?php echo esc_url(get_permalink($item_id)); ?>">
						<?php echo $item->get_image('woocommerce_thumbnail', array('class' => 'combo-item__image')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</a>
				</div>

				<div class="combo-item__body">
					<?php if ($item->get_sku()) : ?>
						<div class="combo-item__sku"><?php echo esc_html($item->get_sku()); ?></div>
					<?php endif; ?>
					<h3 class="combo-item__title">
						<a href="<?php echo esc_url(get_permalink($item_id)); ?>"><?php echo esc_html($item->get_name()); ?></a>
					</h3>
					<div class="combo-item__unit">
						<span class="combo-item__unit-label"><?php esc_html_e('Unit', 'sledge-bundles'); ?></span>
						<?php echo wp_kses_post(wc_price($unit_price)); ?>
					</div>
					<?php if (!$is_optional) : ?>
						<span class="combo-item__badge"><?php esc_html_e('Required', 'sledge-bundles'); ?></span>
					<?php endif; ?>
				</div>

				<div class="combo-item__controls">
					<?php
					echo woocommerce_quantity_input( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						array(
							'input_name'  => 'combo_quantity_' . $item_id,
							'input_value' => $quantity,
							'min_value'   => 1,
							'max_value'   => ($max_qty > 0) ? $max_qty : '',
							'classes'     => array('input-text', 'qty', 'text'),
						),
						$item,
						false
					);
					?>

					<?php if ($is_optional) : ?>
						<button type="button" class="combo-item__remove RemoveBundleItem" aria-label="<?php esc_attr_e('Remove from bundle', 'sledge-bundles'); ?>">
							<span class="combo-item__remove-icon" aria-hidden="true">×</span>
							<span class="combo-item__remove-label"><?php esc_html_e('Remove', 'sledge-bundles'); ?></span>
						</button>
					<?php endif; ?>
				</div>

				<div class="combo-item__subtotal" id="combo_product_subtotal_<?php echo esc_attr((string) $item_id); ?>">
					<span class="combo-item__subtotal-label"><?php esc_html_e('Subtotal', 'sledge-bundles'); ?></span>
					<span class="woocommerce-Price-amount amount" data-line-subtotal>
						<?php echo wp_kses_post(wc_price($subtotal)); ?>
					</span>
					<input
						type="hidden"
						class="combo_product_price"
						name="combo_product_price_<?php echo esc_attr((string) $item_id); ?>"
						id="combo_product_price_<?php echo esc_attr((string) $item_id); ?>"
						value="<?php echo esc_attr(wc_format_decimal($subtotal, wc_get_price_decimals())); ?>"
						data-single="<?php echo esc_attr(wc_format_decimal($unit_price, wc_get_price_decimals())); ?>"
						data-original="<?php echo esc_attr(wc_format_decimal($unit_price, wc_get_price_decimals())); ?>"
					>
				</div>
			</li>
		<?php endforeach; ?>
	</ul>
</section>
