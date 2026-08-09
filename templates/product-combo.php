<?php
// Ensure the file is being included in the right context
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

global $product;

if ( $product->is_type( 'combo' ) ) {
    // Custom combo product display code
    echo '<h2>Combo Product</h2>';
    // Display individual products within the combo
    $combo_items = get_post_meta( $product->get_id(), '_combo_items', true );

    if ( ! empty( $combo_items ) ) {
        echo '<ul>';
        foreach ( $combo_items as $item_id ) {
            $item = wc_get_product( $item_id );
            echo '<li>' . $item->get_name() . '</li>';
        }
        echo '</ul>';
    }
}
?>
