<?php
/**
 * Plugin Name:  Product Custom Text for WooCommerce
 * Plugin URI:   https://rookidroid.com/
 * Description:  Let customers enter personalized text (e.g. engravings, names, messages) on any WooCommerce product page. Saved with each order and visible in the cart, checkout, orders, and admin.
 * Version:      1.0.0
 * Author:       Zhengyu Peng
 * Author URI:   https://rookidroid.com/
 * License:      GPL-2.0-or-later
 * License URI:  https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:  product-custom-text
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * WC requires at least: 8.0
 * WC tested up to: 9.9
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'PCT_VERSION', '1.0.1' );
define( 'PCT_URL',     plugin_dir_url( __FILE__ ) );
define( 'PCT_PATH',    plugin_dir_path( __FILE__ ) );

// ── HPOS compatibility ────────────────────────────────────────────────────────
add_action( 'before_woocommerce_init', function () {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
} );

// ── Activation guard ──────────────────────────────────────────────────────────
register_activation_hook( __FILE__, 'pct_activation_check' );
function pct_activation_check(): void {
    if ( ! class_exists( 'WooCommerce' ) ) {
        deactivate_plugins( plugin_basename( __FILE__ ) );
        wp_die(
            esc_html__( 'Product Custom Text requires WooCommerce to be installed and active.', 'product-custom-text' ),
            esc_html__( 'Plugin activation failed', 'product-custom-text' ),
            [ 'back_link' => true ]
        );
    }
}

// ── Boot ──────────────────────────────────────────────────────────────────────
add_action( 'plugins_loaded', 'pct_init' );
function pct_init(): void {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', function () {
            printf(
                '<div class="notice notice-error"><p>%s</p></div>',
                esc_html__( 'Product Custom Text requires WooCommerce. Please install and activate WooCommerce.', 'product-custom-text' )
            );
        } );
        return;
    }

    // ── Admin hooks ───────────────────────────────────────────────────────────
    add_action( 'add_meta_boxes',    'pct_add_meta_box' );
    add_action( 'save_post_product', 'pct_save_meta' );
    add_action( 'admin_enqueue_scripts', 'pct_admin_enqueue' );

    // ── Front-end: product page ───────────────────────────────────────────────
    add_action( 'wp_enqueue_scripts',                       'pct_enqueue_assets' );
    // Hook BEFORE the quantity field so the custom text box is the
    // first child of form.cart — no CSS flex/order tricks required.
    add_action( 'woocommerce_before_add_to_cart_quantity', 'pct_render_field' );

    // ── Cart & Checkout ───────────────────────────────────────────────────────
    add_filter( 'woocommerce_add_cart_item_data',         'pct_add_cart_item_data', 10, 2 );
    add_filter( 'woocommerce_get_item_data',              'pct_display_cart_item_data', 10, 2 );
    add_action( 'woocommerce_checkout_create_order_line_item', 'pct_save_to_order_item', 10, 3 );

    // ── Order emails & admin ──────────────────────────────────────────────────
    add_action( 'woocommerce_order_item_meta_end',        'pct_display_order_item_meta', 10, 3 );
}

// ═══════════════════════════════════════════════════════════════════════════════
// ADMIN – Product Meta Box
// ═══════════════════════════════════════════════════════════════════════════════

function pct_add_meta_box(): void {
    add_meta_box(
        'pct_custom_text',
        __( 'Custom Text Field', 'product-custom-text' ),
        'pct_meta_box_html',
        'product',
        'side',
        'default'
    );
}

function pct_meta_box_html( WP_Post $post ): void {
    wp_nonce_field( 'pct_save', 'pct_nonce' );

    $enabled       = get_post_meta( $post->ID, '_pct_enabled',       true );
    $label         = get_post_meta( $post->ID, '_pct_label',         true );
    $placeholder   = get_post_meta( $post->ID, '_pct_placeholder',   true );
    $required      = get_post_meta( $post->ID, '_pct_required',      true );
    $max_length    = get_post_meta( $post->ID, '_pct_max_length',     true );
    $field_type    = get_post_meta( $post->ID, '_pct_field_type',     true );

    $label       = $label       ?: __( 'Personalization', 'product-custom-text' );
    $placeholder = $placeholder ?: __( 'Enter your custom text here…', 'product-custom-text' );
    $max_length  = $max_length  ?: 200;
    $field_type  = $field_type  ?: 'textarea';
    ?>
    <div class="pct-meta-box">
        <p>
            <label>
                <input type="checkbox" name="pct_enabled" id="pct_enabled" value="yes" <?php checked( $enabled, 'yes' ); ?> />
                <?php esc_html_e( 'Enable custom text field on this product', 'product-custom-text' ); ?>
            </label>
        </p>

        <div id="pct_options" style="<?php echo $enabled === 'yes' ? '' : 'display:none;'; ?>">
            <p>
                <label for="pct_label"><strong><?php esc_html_e( 'Field Label', 'product-custom-text' ); ?></strong></label><br>
                <input type="text" name="pct_label" id="pct_label" value="<?php echo esc_attr( $label ); ?>" style="width:100%;" />
            </p>

            <p>
                <label for="pct_placeholder"><strong><?php esc_html_e( 'Placeholder Text', 'product-custom-text' ); ?></strong></label><br>
                <input type="text" name="pct_placeholder" id="pct_placeholder" value="<?php echo esc_attr( $placeholder ); ?>" style="width:100%;" />
            </p>

            <p>
                <label for="pct_field_type"><strong><?php esc_html_e( 'Field Type', 'product-custom-text' ); ?></strong></label><br>
                <select name="pct_field_type" id="pct_field_type" style="width:100%;">
                    <option value="textarea" <?php selected( $field_type, 'textarea' ); ?>><?php esc_html_e( 'Textarea (multi-line)', 'product-custom-text' ); ?></option>
                    <option value="text"     <?php selected( $field_type, 'text' ); ?>><?php esc_html_e( 'Text (single-line)', 'product-custom-text' ); ?></option>
                </select>
            </p>

            <p>
                <label for="pct_max_length"><strong><?php esc_html_e( 'Max Characters', 'product-custom-text' ); ?></strong></label><br>
                <input type="number" name="pct_max_length" id="pct_max_length" value="<?php echo esc_attr( $max_length ); ?>" min="1" max="2000" style="width:100%;" />
            </p>

            <p>
                <label>
                    <input type="checkbox" name="pct_required" id="pct_required" value="yes" <?php checked( $required, 'yes' ); ?> />
                    <?php esc_html_e( 'Required field', 'product-custom-text' ); ?>
                </label>
            </p>
        </div>
    </div>
    <?php
}

function pct_save_meta( int $post_id ): void {
    if ( ! isset( $_POST['pct_nonce'] )
        || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['pct_nonce'] ) ), 'pct_save' )
    ) {
        return;
    }
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }
    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        return;
    }

    // Enabled toggle
    $enabled = isset( $_POST['pct_enabled'] ) && $_POST['pct_enabled'] === 'yes' ? 'yes' : 'no';
    update_post_meta( $post_id, '_pct_enabled', $enabled );

    // Label
    if ( isset( $_POST['pct_label'] ) ) {
        update_post_meta( $post_id, '_pct_label', sanitize_text_field( wp_unslash( $_POST['pct_label'] ) ) );
    }

    // Placeholder
    if ( isset( $_POST['pct_placeholder'] ) ) {
        update_post_meta( $post_id, '_pct_placeholder', sanitize_text_field( wp_unslash( $_POST['pct_placeholder'] ) ) );
    }

    // Field type
    $allowed_types = [ 'text', 'textarea' ];
    if ( isset( $_POST['pct_field_type'] ) && in_array( $_POST['pct_field_type'], $allowed_types, true ) ) {
        update_post_meta( $post_id, '_pct_field_type', sanitize_text_field( wp_unslash( $_POST['pct_field_type'] ) ) );
    }

    // Max length
    if ( isset( $_POST['pct_max_length'] ) ) {
        $max = absint( $_POST['pct_max_length'] );
        update_post_meta( $post_id, '_pct_max_length', $max > 0 ? $max : 200 );
    }

    // Required
    $required = isset( $_POST['pct_required'] ) && $_POST['pct_required'] === 'yes' ? 'yes' : 'no';
    update_post_meta( $post_id, '_pct_required', $required );
}

function pct_admin_enqueue( string $hook ): void {
    if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
        return;
    }
    // Inline JS to toggle the options panel
    wp_add_inline_script(
        'jquery-core',
        '(function($){
            $(function(){
                $("#pct_enabled").on("change", function(){
                    $("#pct_options").toggle( this.checked );
                });
            });
        })(jQuery);'
    );
}

// ═══════════════════════════════════════════════════════════════════════════════
// FRONT-END – Product Page Field
// ═══════════════════════════════════════════════════════════════════════════════

function pct_enqueue_assets(): void {
    if ( ! is_product() ) {
        return;
    }
    wp_enqueue_style(
        'product-custom-text',
        PCT_URL . 'assets/css/product-custom-text.css',
        [],
        PCT_VERSION
    );
    wp_enqueue_script(
        'product-custom-text',
        PCT_URL . 'assets/js/product-custom-text.js',
        [ 'jquery' ],
        PCT_VERSION,
        true
    );
    wp_localize_script(
        'product-custom-text',
        'pct_params',
        [
            'required_msg' => esc_html__( 'Please fill in the required personalization text before adding to cart.', 'product-custom-text' ),
        ]
    );
}

function pct_render_field(): void {
    global $product;
    if ( ! $product instanceof WC_Product ) {
        return;
    }

    $product_id = $product->get_id();
    $enabled    = get_post_meta( $product_id, '_pct_enabled', true );

    if ( $enabled !== 'yes' ) {
        return;
    }

    $label      = get_post_meta( $product_id, '_pct_label',       true ) ?: __( 'Personalization', 'product-custom-text' );
    $placeholder= get_post_meta( $product_id, '_pct_placeholder', true ) ?: __( 'Enter your custom text here…', 'product-custom-text' );
    $required   = get_post_meta( $product_id, '_pct_required',    true ) === 'yes';
    $max_length = (int) ( get_post_meta( $product_id, '_pct_max_length', true ) ?: 200 );
    $field_type = get_post_meta( $product_id, '_pct_field_type',  true ) ?: 'textarea';

    $required_attr  = $required ? ' required' : '';
    $required_badge = $required ? ' <span class="pct-required" aria-hidden="true">*</span>' : '';
    ?>
    <div class="pct-field-wrapper" id="pct-field-wrapper">
        <label for="pct_custom_text" class="pct-label">
            <?php echo esc_html( $label ) . $required_badge; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        </label>

        <?php if ( $field_type === 'textarea' ) : ?>
            <textarea
                name="pct_custom_text"
                id="pct_custom_text"
                class="pct-input pct-textarea"
                placeholder="<?php echo esc_attr( $placeholder ); ?>"
                maxlength="<?php echo esc_attr( $max_length ); ?>"
                rows="3"
                <?php echo $required_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            ></textarea>
        <?php else : ?>
            <input
                type="text"
                name="pct_custom_text"
                id="pct_custom_text"
                class="pct-input pct-text"
                placeholder="<?php echo esc_attr( $placeholder ); ?>"
                maxlength="<?php echo esc_attr( $max_length ); ?>"
                <?php echo $required_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            />
        <?php endif; ?>

        <div class="pct-counter" id="pct-counter" aria-live="polite">
            <span id="pct-chars-used">0</span> / <?php echo esc_html( $max_length ); ?>
        </div>
    </div>
    <?php
}

// ═══════════════════════════════════════════════════════════════════════════════
// CART – Store & display custom text
// ═══════════════════════════════════════════════════════════════════════════════

function pct_add_cart_item_data( array $cart_item_data, int $product_id ): array {
    $enabled = get_post_meta( $product_id, '_pct_enabled', true );
    if ( $enabled !== 'yes' ) {
        return $cart_item_data;
    }

    $required = get_post_meta( $product_id, '_pct_required', true ) === 'yes';
    $max      = (int) ( get_post_meta( $product_id, '_pct_max_length', true ) ?: 200 );

    // phpcs:ignore WordPress.Security.NonceVerification.Missing
    $custom_text = isset( $_POST['pct_custom_text'] )
        ? sanitize_textarea_field( wp_unslash( $_POST['pct_custom_text'] ) )
        : '';

    // Truncate to max length for safety
    $custom_text = mb_substr( $custom_text, 0, $max );

    if ( $required && $custom_text === '' ) {
        wc_add_notice(
            __( 'Please fill in the required personalization text before adding to cart.', 'product-custom-text' ),
            'error'
        );
        // Return the original data intact — the error notice blocks add-to-cart.
        // Returning [] would strip WooCommerce's own cart item data and cause
        // downstream null-key deprecation notices (PHP 8.1+).
        return $cart_item_data;
    }

    if ( $custom_text !== '' ) {
        $label = get_post_meta( $product_id, '_pct_label', true ) ?: __( 'Personalization', 'product-custom-text' );
        $cart_item_data['pct_custom_text']       = $custom_text;
        $cart_item_data['pct_custom_text_label'] = $label;
        $cart_item_data['unique_key']            = md5( microtime() . $custom_text );
    }

    return $cart_item_data;
}

function pct_display_cart_item_data( array $item_data, array $cart_item ): array {
    if ( ! empty( $cart_item['pct_custom_text'] ) ) {
        $label = $cart_item['pct_custom_text_label'] ?? __( 'Personalization', 'product-custom-text' );
        $item_data[] = [
            'key'   => esc_html( $label ),
            'value' => esc_html( $cart_item['pct_custom_text'] ),
        ];
    }
    return $item_data;
}

// ═══════════════════════════════════════════════════════════════════════════════
// ORDER – Save custom text to order line item
// ═══════════════════════════════════════════════════════════════════════════════

function pct_save_to_order_item(
    \WC_Order_Item_Product $item,
    string                  $cart_item_key,
    array                   $values
): void {
    if ( ! empty( $values['pct_custom_text'] ) ) {
        $label = $values['pct_custom_text_label'] ?? __( 'Personalization', 'product-custom-text' );
        $item->add_meta_data(
            esc_html( $label ),
            esc_html( $values['pct_custom_text'] ),
            true
        );
    }
}

// ── Display in admin order screen & emails ────────────────────────────────────
function pct_display_order_item_meta( $item_id, $item, $order_or_product ): void {
    // WooCommerce fires this hook from several contexts (email, order details,
    // admin meta-box) with different types for $item_id and the third argument.
    // The meta is already rendered automatically by WooCommerce from the saved
    // order item meta data; no additional output is needed here.
}
