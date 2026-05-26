<?php
/**
 * RookiDroid – Shop loop product card
 *
 * Overrides woocommerce/templates/content-product.php so that every product
 * on /shop/ (and any archive) renders with the same rd-product-card markup
 * used by the [rookidroid_product_tabs] shortcode on the homepage.
 *
 * @package RookiDroid_Shop
 */

defined( 'ABSPATH' ) || exit;

global $product;

if ( empty( $product ) || ! $product->is_visible() ) {
    return;
}
?>
<li <?php wc_product_class( '', $product ); ?>>
    <?php
    // rd_shop_render_card() is defined in rookidroid-shop.php and outputs the
    // same branded card used by [rookidroid_product_tabs] / [rookidroid_products].
    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    echo rd_shop_render_card( $product );
    ?>
</li>
