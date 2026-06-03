<?php
/**
 * Plugin Name:  YouTube Product Video
 * Plugin URI:   https://rookidroid.com/
 * Description:  Embed a YouTube video in the WooCommerce single product page by adding a URL to each product.
 * Version:      1.0.0
 * Author:       Zhengyu Peng
 * Author URI:   https://rookidroid.com/
 * License:      GPL-2.0-or-later
 * License URI:  https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:  youtube-product-video
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * WC requires at least: 8.0
 * WC tested up to: 9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'YPV_VERSION', '1.0.1' );
define( 'YPV_URL',     plugin_dir_url( __FILE__ ) );
define( 'YPV_PATH',    plugin_dir_path( __FILE__ ) );

// Declare HPOS compatibility
add_action( 'before_woocommerce_init', function () {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
} );

// ── Activation guard ──────────────────────────────────────────────────────────
register_activation_hook( __FILE__, 'ypv_activation_check' );
function ypv_activation_check(): void {
    if ( ! class_exists( 'WooCommerce' ) ) {
        deactivate_plugins( plugin_basename( __FILE__ ) );
        wp_die(
            esc_html__( 'YouTube Product Video requires WooCommerce to be installed and active.', 'youtube-product-video' ),
            esc_html__( 'Plugin activation failed', 'youtube-product-video' ),
            [ 'back_link' => true ]
        );
    }
}

// ── Boot ──────────────────────────────────────────────────────────────────────
add_action( 'plugins_loaded', 'ypv_init' );
function ypv_init(): void {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', function () {
            printf(
                '<div class="notice notice-error"><p>%s</p></div>',
                esc_html__( 'YouTube Product Video requires WooCommerce. Please install and activate WooCommerce.', 'youtube-product-video' )
            );
        } );
        return;
    }

    // Admin
    add_action( 'add_meta_boxes',    'ypv_add_meta_box' );
    add_action( 'save_post_product', 'ypv_save_meta' );

    // Front-end
    add_action( 'wp_enqueue_scripts',                              'ypv_enqueue_assets' );
    add_filter( 'woocommerce_single_product_image_thumbnail_html', 'ypv_inject_gallery_slide', 10, 2 );
}

// ── Admin meta box ────────────────────────────────────────────────────────────
function ypv_add_meta_box(): void {
    add_meta_box(
        'ypv_video',
        __( 'YouTube Video URL', 'youtube-product-video' ),
        'ypv_meta_box_html',
        'product',
        'side',
        'default'
    );
}

function ypv_meta_box_html( WP_Post $post ): void {
    wp_nonce_field( 'ypv_save', 'ypv_nonce' );
    $val = esc_attr( get_post_meta( $post->ID, '_ypv_youtube_url', true ) );
    echo '<p>'
       . '<input type="url" name="ypv_youtube_url" id="ypv_youtube_url" value="' . $val . '" '
       . 'style="width:100%;" placeholder="https://www.youtube.com/watch?v=... or /shorts/..." />'
       . '</p>'
       . '<p class="description">'
       . esc_html__( 'Displayed above the product summary on the single product page. Leave blank to hide.', 'youtube-product-video' )
       . '</p>';
}

// ── Save meta ─────────────────────────────────────────────────────────────────
function ypv_save_meta( int $post_id ): void {
    if ( ! isset( $_POST['ypv_nonce'] )
        || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ypv_nonce'] ) ), 'ypv_save' )
    ) {
        return;
    }
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }
    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        return;
    }

    if ( isset( $_POST['ypv_youtube_url'] ) ) {
        $url = esc_url_raw( wp_unslash( $_POST['ypv_youtube_url'] ) );
        update_post_meta( $post_id, '_ypv_youtube_url', $url );
    }
}

// ── Assets ────────────────────────────────────────────────────────────────────
function ypv_enqueue_assets(): void {
    if ( ! is_product() ) {
        return;
    }
    wp_enqueue_style(
        'youtube-product-video',
        YPV_URL . 'assets/css/youtube-product-video.css',
        [],
        YPV_VERSION
    );
    wp_enqueue_script(
        'youtube-product-video',
        YPV_URL . 'assets/js/youtube-product-video.js',
        [ 'jquery' ],
        YPV_VERSION,
        true
    );
}

// ── Gallery slide injection ───────────────────────────────────────────────────
// Fires once per gallery image. We detect the main (featured) image and append
// the video slide immediately after it, making it the second gallery slide.
function ypv_inject_gallery_slide( string $html, int $attachment_id ): string {
    global $product;
    if ( ! $product instanceof WC_Product ) {
        return $html;
    }

    // Only inject after the main featured image
    if ( (int) get_post_thumbnail_id( $product->get_id() ) !== $attachment_id ) {
        return $html;
    }

    $url = get_post_meta( $product->get_id(), '_ypv_youtube_url', true );
    if ( ! $url ) {
        return $html;
    }

    // Extract video ID from watch URL, short youtu.be link, or Shorts URL
    $video_id = '';
    if ( preg_match( '/[?&]v=([a-zA-Z0-9_-]{11})/', $url, $m ) ) {
        $video_id = $m[1];
    } elseif ( preg_match( '#youtu\.be/([a-zA-Z0-9_-]{11})#', $url, $m ) ) {
        $video_id = $m[1];
    } elseif ( preg_match( '#youtube\.com/shorts/([a-zA-Z0-9_-]{11})#', $url, $m ) ) {
        $video_id = $m[1];
    }

    if ( ! $video_id ) {
        return $html;
    }

    $thumb_url  = 'https://img.youtube.com/vi/' . esc_attr( $video_id ) . '/mqdefault.jpg';
    $embed_url  = 'https://www.youtube-nocookie.com/embed/' . esc_attr( $video_id ) . '?enablejsapi=1';
    $title      = esc_attr( $product->get_name() );

    $video_slide = sprintf(
        '<div data-thumb="%s" data-thumb-alt="%s" class="woocommerce-product-gallery__image ypv-gallery-slide">
            <div class="ypv-video">
                <iframe
                    src="%s"
                    title="%s"
                    frameborder="0"
                    allow="autoplay; encrypted-media; picture-in-picture"
                    allowfullscreen
                    loading="lazy"
                ></iframe>
            </div>
        </div>',
        esc_url( $thumb_url ),
        $title,
        esc_url( $embed_url ),
        $title
    );

    return $html . $video_slide;
}
