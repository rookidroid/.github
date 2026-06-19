<?php
/**
 * Plugin Name:  RookiDroid Shop
 * Plugin URI:   https://rookidroid.com/
 * Description:  Custom-styled WooCommerce product grid shortcodes matching the RookiDroid brand design. Provides [rookidroid_products], [rookidroid_product_tabs], [rookidroid_shop_grid], and [rookidroid_featured_products].
 * Version:      1.5.3
 * Author:       Zhengyu Peng
 * Author URI:   https://rookidroid.com/
 * License:      GPL-2.0-or-later
 * License URI:  https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:  rookidroid-shop
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * WC requires at least: 8.0
 * WC tested up to: 9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'RD_SHOP_VERSION', '1.5.3' );
define( 'RD_SHOP_URL',     plugin_dir_url( __FILE__ ) );
define( 'RD_SHOP_PATH',    plugin_dir_path( __FILE__ ) );

// Declare HPOS compatibility
add_action( 'before_woocommerce_init', function () {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
} );

// ── Activation guard ──────────────────────────────────────────────────────────
register_activation_hook( __FILE__, 'rd_shop_activation_check' );
function rd_shop_activation_check(): void {
    if ( ! class_exists( 'WooCommerce' ) ) {
        deactivate_plugins( plugin_basename( __FILE__ ) );
        wp_die(
            esc_html__( 'RookiDroid Shop requires WooCommerce to be installed and active.', 'rookidroid-shop' ),
            esc_html__( 'Plugin activation failed', 'rookidroid-shop' ),
            [ 'back_link' => true ]
        );
    }
}

// ── Boot ──────────────────────────────────────────────────────────────────────
add_action( 'plugins_loaded', 'rd_shop_init' );
function rd_shop_init(): void {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', function () {
            printf(
                '<div class="notice notice-error"><p>%s</p></div>',
                esc_html__( 'RookiDroid Shop requires WooCommerce. Please install and activate WooCommerce.', 'rookidroid-shop' )
            );
        } );
        return;
    }

    add_action( 'wp_enqueue_scripts', 'rd_shop_enqueue_assets' );
    add_shortcode( 'rookidroid_products',          'rd_shop_products_shortcode' );
    add_shortcode( 'rookidroid_product_tabs',      'rd_shop_product_tabs_shortcode' );
    add_shortcode( 'rookidroid_shop_grid',         'rd_shop_shop_grid_shortcode' );
    add_shortcode( 'rookidroid_featured_products', 'rd_shop_featured_products_shortcode' );
    add_action( 'wp_ajax_rd_add_to_cart',        'rd_shop_ajax_add_to_cart' );
    add_action( 'wp_ajax_nopriv_rd_add_to_cart', 'rd_shop_ajax_add_to_cart' );
    add_filter( 'wc_get_template_part',         'rd_shop_get_template_part', 9999, 3 );
    add_filter( 'woocommerce_locate_template',   'rd_shop_locate_template', 9999, 3 );
    add_filter( 'woocommerce_show_page_title',   'rd_shop_hide_page_title' );
    add_action( 'neve_before_primary',           'rd_shop_render_banner' );

    // Admin-only hooks
    add_action( 'admin_menu',                         'rd_shop_admin_menu' );
    add_action( 'admin_enqueue_scripts',              'rd_shop_admin_enqueue' );
    add_action( 'admin_post_rd_shop_save_featured',   'rd_shop_admin_save' );
    add_action( 'wp_ajax_rd_search_products',         'rd_shop_ajax_search_products' );
}

// ── Shop page hero banner ────────────────────────────────────────────────────
function rd_shop_hide_page_title( $show ) {
    return ( is_shop() || is_product_category() ) ? false : $show;
}

function rd_shop_render_banner(): void {
    if ( ! is_shop() && ! is_product_category() ) {
        return;
    }

    $home_url = home_url( '/' );
    $shop_url = wc_get_page_permalink( 'shop' );

    if ( is_shop() ) {
        $breadcrumbs = [
            [ 'url' => $home_url, 'label' => __( 'Home', 'rookidroid-shop' ) ],
            [ 'url' => '',        'label' => __( 'Shop', 'rookidroid-shop' ) ],
        ];
        $pre_title = 'Build Your Next ';
        $highlight = 'Robot Project';
        $subtitle  = __( 'Explore 3D models, control electronics, software bundles, and practical gadgets designed in-house and tested in the RookiDroid workshop.', 'rookidroid-shop' );
    } else {
        $term     = get_queried_object();
        $cat_name = ( $term instanceof WP_Term ) ? $term->name : __( 'Products', 'rookidroid-shop' );
        $cat_desc = ( $term instanceof WP_Term && $term->description )
            ? wp_strip_all_tags( $term->description )
            : __( 'Browse all products in this category, designed in-house and tested in the RookiDroid workshop.', 'rookidroid-shop' );

        $breadcrumbs = [
            [ 'url' => $home_url, 'label' => __( 'Home', 'rookidroid-shop' ) ],
            [ 'url' => $shop_url, 'label' => __( 'Shop', 'rookidroid-shop' ) ],
        ];

        // Insert parent category for sub-categories
        if ( $term instanceof WP_Term && $term->parent ) {
            $parent = get_term( $term->parent, 'product_cat' );
            if ( $parent instanceof WP_Term ) {
                $parent_link   = get_term_link( $parent );
                $breadcrumbs[] = [
                    'url'   => is_wp_error( $parent_link ) ? '' : $parent_link,
                    'label' => $parent->name,
                ];
            }
        }

        $breadcrumbs[] = [ 'url' => '', 'label' => $cat_name ];

        $pre_title = 'Explore ';
        $highlight = $cat_name;
        $subtitle  = $cat_desc;
    }

    // Build breadcrumb HTML (each value individually escaped)
    $crumb_html = '';
    $last_idx   = count( $breadcrumbs ) - 1;
    foreach ( $breadcrumbs as $i => $crumb ) {
        if ( $i < $last_idx ) {
            $crumb_html .= '<a href="' . esc_url( $crumb['url'] ) . '">' . esc_html( $crumb['label'] ) . '</a>';
            $crumb_html .= '<span aria-hidden="true"> / </span>';
        } else {
            $crumb_html .= '<span>' . esc_html( $crumb['label'] ) . '</span>';
        }
    }
    ?>
    <div class="rd-shop-banner">
        <div class="rd-shop-banner__inner">
            <nav class="rd-shop-banner__breadcrumb" aria-label="<?php esc_attr_e( 'Breadcrumb', 'rookidroid-shop' ); ?>">
                <?php echo $crumb_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped per-item above ?>
            </nav>
            <h1 class="rd-shop-banner__title"><?php echo esc_html( $pre_title ); ?><span class="rd-shop-banner__highlight"><?php echo esc_html( $highlight ); ?></span></h1>
            <p class="rd-shop-banner__subtitle"><?php echo esc_html( $subtitle ); ?></p>
        </div>
    </div>
    <?php
}

// ── Template override: replace WooCommerce loop card with RookiDroid card ─────
// Neve (and Sparks for WooCommerce) hooks wc_get_template_part before
// woocommerce_locate_template fires — so we need both filters.
function rd_shop_get_template_part( string $template, string $slug, string $name ): string {
    if ( 'content' === $slug && 'product' === $name ) {
        $custom = RD_SHOP_PATH . 'woocommerce/content-product.php';
        if ( file_exists( $custom ) ) {
            return $custom;
        }
    }
    return $template;
}

function rd_shop_locate_template( string $template, string $template_name, string $template_path ): string {
    if ( 'content-product.php' === $template_name ) {
        $custom = RD_SHOP_PATH . 'woocommerce/content-product.php';
        if ( file_exists( $custom ) ) {
            return $custom;
        }
    }
    return $template;
}

// ── Assets ────────────────────────────────────────────────────────────────────
function rd_shop_enqueue_assets(): void {
    wp_enqueue_style(
        'rd-shop-fonts',
        'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Space+Grotesk:wght@500;600;700&display=swap',
        [],
        null
    );
    wp_enqueue_style(
        'rd-shop',
        RD_SHOP_URL . 'assets/css/rookidroid-shop.css',
        [ 'rd-shop-fonts' ],
        RD_SHOP_VERSION
    );

    // ── Shop banner inline CSS ─────────────────────────────────────────────
    // Injected after the main stylesheet so these rules always win regardless
    // of what version of rookidroid-shop.css is on the server.
    // neve_before_primary fires inside Neve's max-width wrapper, so the
    // banner needs the 100vw / translateX(-50%) full-bleed technique.
    wp_add_inline_style( 'rd-shop', '
        body.post-type-archive-product .nv-breadcrumbs,
        body.post-type-archive-product .neve-breadcrumbs,
        body.post-type-archive-product nav.woocommerce-breadcrumb,
        body.tax-product_cat .nv-breadcrumbs,
        body.tax-product_cat .neve-breadcrumbs,
        body.tax-product_cat nav.woocommerce-breadcrumb {
            display: none !important;
        }
        .rd-shop-banner {
            position: relative !important;
            width: 100vw !important;
            left: 50% !important;
            transform: translateX(-50%) !important;
            overflow: hidden !important;
            background: linear-gradient(120deg, #f9f9fb 50%, #f3e5f5 100%) !important;
            padding: 52px 0 48px !important;
            line-height: normal !important;
            margin-bottom: 0 !important;
        }
        .rd-shop-banner__inner {
            max-width: 1200px !important;
            margin: 0 auto !important;
            padding: 0 24px !important;
            position: relative !important;
            z-index: 1 !important;
        }
        .rd-shop-banner::after {
            content: "" !important;
            position: absolute !important;
            top: 0; right: 0;
            width: 50%; height: 100%;
            background-image: url("data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'64\' height=\'64\'%3E%3Cpath d=\'M0 32 L32 0 M32 64 L64 32 M-16 48 L48 -16 M16 80 L80 16\' stroke=\'%239c27b0\' stroke-width=\'0.6\' fill=\'none\'/%3E%3C/svg%3E") !important;
            background-repeat: repeat !important;
            opacity: 0.10 !important;
            pointer-events: none !important;
        }
        .rd-shop-banner__breadcrumb {
            display: flex !important;
            align-items: center !important;
            font-size: .85rem !important;
            color: #8e8ea0 !important;
            margin-bottom: 18px !important;
        }
        .rd-shop-banner__breadcrumb a {
            color: #8e8ea0 !important;
            text-decoration: none !important;
        }
        .rd-shop-banner__breadcrumb a:hover { color: #9c27b0 !important; }
        .rd-shop-banner__breadcrumb span { margin: 0 5px !important; }
        .rd-shop-banner__breadcrumb span:last-child { margin: 0 !important; }
        .rd-shop-banner__title {
            font-family: "Space Grotesk", "Inter", system-ui, sans-serif !important;
            font-weight: 800 !important;
            font-size: clamp(2rem, 4.5vw, 2.8rem) !important;
            color: #1a1a2e !important;
            margin: 0 0 14px !important;
            line-height: 1.15 !important;
        }
        .rd-shop-banner__highlight { color: #9c27b0 !important; }
        .rd-shop-banner__subtitle {
            font-size: 1rem !important;
            color: #555770 !important;
            max-width: 560px !important;
            line-height: 1.7 !important;
            margin: 0 !important;
        }
        @media (max-width: 680px) {
            .rd-shop-banner { padding: 36px 0 32px !important; }
            .rd-shop-banner__inner { padding: 0 16px !important; }
        }
    ' );

    wp_enqueue_script(
        'rd-shop',
        RD_SHOP_URL . 'assets/js/rookidroid-shop.js',
        [ 'jquery' ],
        RD_SHOP_VERSION,
        true
    );
    wp_localize_script( 'rd-shop', 'rdShop', [
        'ajaxUrl' => admin_url( 'admin-ajax.php' ),
        'nonce'   => wp_create_nonce( 'rd_shop_nonce' ),
        'cartUrl' => wc_get_cart_url(),
        'i18n'    => [
            'addToCart'   => esc_html__( 'Add to Cart', 'rookidroid-shop' ),
            'adding'      => esc_html__( 'Adding…', 'rookidroid-shop' ),
            'added'       => esc_html__( 'Added!', 'rookidroid-shop' ),
            'viewDetails' => esc_html__( 'View Details', 'rookidroid-shop' ),
            'outOfStock'  => esc_html__( 'Out of Stock', 'rookidroid-shop' ),
        ],
    ] );
}

// ── Query builder ─────────────────────────────────────────────────────────────
function rd_shop_build_query_args( array $atts ): array {
    $orderby = sanitize_key( $atts['orderby'] ?? 'date' );

    $args = [
        'post_type'      => 'product',
        'post_status'    => 'publish',
        'posts_per_page' => (int) ( $atts['limit'] ?? 8 ),
        'order'          => strtoupper( $atts['order'] ?? 'DESC' ) === 'ASC' ? 'ASC' : 'DESC',
        'tax_query'      => [ 'relation' => 'AND' ], // phpcs:ignore WordPress.DB.SlowDBQuery
        'meta_query'     => [ 'relation' => 'AND' ], // phpcs:ignore WordPress.DB.SlowDBQuery
    ];

    // Orderby mapping
    switch ( $orderby ) {
        case 'price':
            $args['meta_key'] = '_price'; // phpcs:ignore WordPress.DB.SlowDBQuery
            $args['orderby']  = 'meta_value_num';
            break;
        case 'popularity':
            $args['meta_key'] = 'total_sales'; // phpcs:ignore WordPress.DB.SlowDBQuery
            $args['orderby']  = 'meta_value_num';
            break;
        case 'rating':
            $args['meta_key'] = '_wc_average_rating'; // phpcs:ignore WordPress.DB.SlowDBQuery
            $args['orderby']  = 'meta_value_num';
            break;
        case 'rand':
            $args['orderby'] = 'rand';
            break;
        case 'title':
            $args['orderby'] = 'title';
            break;
        case 'menu_order':
            $args['orderby'] = 'menu_order title';
            break;
        default:
            $args['orderby'] = 'date';
    }

    // Exclude hidden products
    $args['tax_query'][] = [
        'taxonomy' => 'product_visibility',
        'field'    => 'name',
        'terms'    => [ 'exclude-from-catalog' ],
        'operator' => 'NOT IN',
    ];

    // Category filter
    if ( ! empty( $atts['category'] ) ) {
        $slugs = array_map( 'sanitize_title', explode( ',', $atts['category'] ) );
        $args['tax_query'][] = [
            'taxonomy' => 'product_cat',
            'field'    => 'slug',
            'terms'    => $slugs,
        ];
    }

    // Specific IDs
    if ( ! empty( $atts['ids'] ) ) {
        $ids = array_map( 'absint', explode( ',', $atts['ids'] ) );
        $ids = array_filter( $ids );
        if ( $ids ) {
            $args['post__in'] = $ids;
            $args['orderby']  = 'post__in';
        }
    }

    // On-sale filter
    if ( filter_var( $atts['on_sale'] ?? false, FILTER_VALIDATE_BOOLEAN ) ) {
        $sale_ids = wc_get_product_ids_on_sale();
        if ( ! empty( $args['post__in'] ) ) {
            $sale_ids = array_intersect( $args['post__in'], $sale_ids );
        }
        $args['post__in'] = $sale_ids ?: [ 0 ];
    }

    return $args;
}

// ── Render a single product card ──────────────────────────────────────────────
function rd_shop_render_card( WC_Product $product ): string {
    $id         = $product->get_id();
    $title      = $product->get_name();
    $permalink  = get_permalink( $id );
    $price      = $product->get_price();
    $reg_price  = $product->get_regular_price();
    $sale_price = $product->get_sale_price();
    $is_on_sale = $product->is_on_sale();
    $is_free    = ( '' !== $price && 0.0 === (float) $price );
    $is_in_stock = $product->is_in_stock();
    $type       = $product->get_type();

    // Primary category label
    $terms     = get_the_terms( $id, 'product_cat' );
    $cat_label = ( $terms && ! is_wp_error( $terms ) ) ? $terms[0]->name : '';

    // Product thumbnail
    if ( has_post_thumbnail( $id ) ) {
        $thumb = get_the_post_thumbnail( $id, 'woocommerce_thumbnail', [
            'class'   => 'rd-card__img',
            'loading' => 'lazy',
            'alt'     => esc_attr( $title ),
        ] );
    } else {
        $thumb = '<div class="rd-card__img-placeholder" aria-hidden="true">📦</div>';
    }

    // Badges
    $badges = '';
    if ( $is_on_sale && $reg_price && (float) $reg_price > 0 ) {
        $pct     = round( ( 1 - (float) $sale_price / (float) $reg_price ) * 100 );
        $badges .= '<span class="rd-badge rd-badge--sale">' . esc_html( $pct ? "-{$pct}%" : __( 'Sale', 'rookidroid-shop' ) ) . '</span>';
    } elseif ( $is_on_sale ) {
        $badges .= '<span class="rd-badge rd-badge--sale">' . esc_html__( 'Sale', 'rookidroid-shop' ) . '</span>';
    }
    if ( $is_free ) {
        $badges .= '<span class="rd-badge rd-badge--free">' . esc_html__( 'Free', 'rookidroid-shop' ) . '</span>';
    }
    if ( ! $is_in_stock ) {
        $badges .= '<span class="rd-badge rd-badge--oos">' . esc_html__( 'Out of Stock', 'rookidroid-shop' ) . '</span>';
    }

    // Price display
    if ( $is_free ) {
        $price_html = '<span class="rd-card__price-current">' . esc_html__( 'Free', 'rookidroid-shop' ) . '</span>';
    } elseif ( $is_on_sale && $reg_price ) {
        $price_html = '<span class="rd-card__price-current">' . wc_price( $sale_price ) . '</span>'
                    . '<span class="rd-card__price-original">' . wc_price( $reg_price ) . '</span>';
    } else {
        $price_html = '<span class="rd-card__price-current">' . wp_kses_post( $product->get_price_html() ) . '</span>';
    }

    // CTA button
    $btn_html = rd_shop_render_button( $product );

    return sprintf(
        '<div class="rd-product-card" data-product-id="%1$d">
            %2$s
            <a href="%3$s" class="rd-card__image" aria-label="%4$s">
                %5$s
                <div class="rd-card__overlay" aria-hidden="true">
                    <span class="rd-btn rd-btn--primary rd-btn--sm">%6$s</span>
                </div>
            </a>
            <div class="rd-card__body">
                %7$s
                <h3 class="rd-card__title"><a href="%3$s">%4$s</a></h3>
                <div class="rd-card__price">%8$s</div>
                %9$s
            </div>
        </div>',
        esc_attr( $id ),
        $badges ? '<div class="rd-card__badges">' . $badges . '</div>' : '',
        esc_url( $permalink ),
        esc_attr( $title ),
        $thumb,
        esc_html__( 'View Details', 'rookidroid-shop' ),
        $cat_label ? '<div class="rd-card__category">' . esc_html( $cat_label ) . '</div>' : '',
        $price_html,
        $btn_html
    );
}

// ── Render a shop-page style product card (matches rookidroid_shoppage.html) ──
function rd_shop_render_shop_card( WC_Product $product ): string {
    $id         = $product->get_id();
    $title      = $product->get_name();
    $permalink  = get_permalink( $id );
    $price      = $product->get_price();
    $reg_price  = $product->get_regular_price();
    $sale_price = $product->get_sale_price();
    $is_on_sale = $product->is_on_sale();
    $is_free    = ( '' !== $price && 0.0 === (float) $price );

    $terms      = get_the_terms( $id, 'product_cat' );
    $cat_term   = ( $terms && ! is_wp_error( $terms ) ) ? $terms[0] : null;
    $cat_slug   = $cat_term ? $cat_term->slug : 'uncategorized';
    $cat_label  = $cat_term ? $cat_term->name : __( 'Product', 'rookidroid-shop' );
    $price_num  = ( '' !== $price ) ? (float) $price : 0.0;

    if ( has_post_thumbnail( $id ) ) {
        $thumb = get_the_post_thumbnail( $id, 'woocommerce_thumbnail', [
            'loading' => 'lazy',
            'alt'     => esc_attr( $title ),
        ] );
    } else {
        $thumb = '<span class="rd-shop-grid-placeholder" aria-hidden="true">📦</span>';
    }

    $badges = '';
    if ( $is_on_sale ) {
        $badges .= '<span class="badge badge--sale">' . esc_html__( 'Sale', 'rookidroid-shop' ) . '</span>';
    }
    if ( $is_free ) {
        $badges .= '<span class="badge badge--free">' . esc_html__( 'Free', 'rookidroid-shop' ) . '</span>';
    }
    if ( $product->is_featured() ) {
        $badges .= '<span class="badge badge--new">' . esc_html__( 'New', 'rookidroid-shop' ) . '</span>';
    }

    if ( $is_free ) {
        $price_html = '<span class="product-card__price-current">$0.00</span>';
    } elseif ( $is_on_sale && $reg_price ) {
        $price_html = '<span class="product-card__price-current">' . wp_kses_post( wc_price( $sale_price ) ) . '</span>'
            . '<span class="product-card__price-original">' . wp_kses_post( wc_price( $reg_price ) ) . '</span>';
    } else {
        $price_html = '<span class="product-card__price-current">' . wp_kses_post( wc_price( $price_num ) ) . '</span>';
    }

    return sprintf(
        '<article class="product-card" data-category="%1$s" data-price="%2$s" data-name="%3$s">
            %4$s
            <a href="%5$s" class="product-card__image">%6$s</a>
            <div class="product-card__body">
              <div class="product-card__category">%7$s</div>
              <h3 class="product-card__title">%8$s</h3>
              <div class="product-card__price">%9$s</div>
              <div class="product-card__actions">
                <a href="%5$s" class="btn btn--primary btn--sm">%10$s</a>
              </div>
            </div>
          </article>',
        esc_attr( $cat_slug ),
        esc_attr( (string) $price_num ),
        esc_attr( $title ),
        $badges ? '<div class="product-card__badges">' . $badges . '</div>' : '',
        esc_url( $permalink ),
        $thumb,
        esc_html( $cat_label ),
        esc_html( $title ),
        $price_html,
        esc_html__( 'View Product', 'rookidroid-shop' )
    );
}

// ── CTA button logic ──────────────────────────────────────────────────────────
function rd_shop_render_button( WC_Product $product ): string {
    $id       = $product->get_id();
    $url      = esc_url( get_permalink( $id ) );
    $is_free  = ( '' !== $product->get_price() && 0.0 === (float) $product->get_price() );
    $type     = $product->get_type();

    // Variable / grouped / external → link to product page
    if ( in_array( $type, [ 'variable', 'grouped', 'external' ], true ) ) {
        return '<a href="' . $url . '" class="rd-btn rd-btn--primary rd-btn--sm rd-card__action">'
             . esc_html__( 'Select Options', 'rookidroid-shop' ) . '</a>';
    }

    // Out of stock
    if ( ! $product->is_in_stock() ) {
        return '<span class="rd-btn rd-btn--disabled rd-btn--sm rd-card__action">'
             . esc_html__( 'Out of Stock', 'rookidroid-shop' ) . '</span>';
    }

    // Simple purchasable → AJAX add-to-cart
    if ( $product->is_purchasable() ) {
        $variant = $is_free ? 'rd-btn--outline' : 'rd-btn--primary';
        return sprintf(
            '<button class="rd-btn %s rd-btn--sm rd-card__action rd-add-to-cart"
                data-product-id="%d"
                aria-label="%s">%s</button>',
            esc_attr( $variant ),
            esc_attr( $id ),
            esc_attr( sprintf( __( 'Add %s to cart', 'rookidroid-shop' ), $product->get_name() ) ),
            esc_html__( 'Add to Cart', 'rookidroid-shop' )
        );
    }

    // Fallback
    return '<a href="' . $url . '" class="rd-btn rd-btn--outline rd-btn--sm rd-card__action">'
         . esc_html__( 'View Details', 'rookidroid-shop' ) . '</a>';
}

// ── Section header helper ─────────────────────────────────────────────────────
function rd_shop_section_header( string $tag, string $title, string $subtitle ): string {
    if ( ! $title && ! $subtitle ) return '';
    return '<div class="rd-section__header">'
         . ( $tag      ? '<div class="rd-section__tag">'      . esc_html( $tag )      . '</div>' : '' )
         . ( $title    ? '<h2 class="rd-section__title">'     . esc_html( $title )    . '</h2>'  : '' )
         . ( $subtitle ? '<p class="rd-section__subtitle">'   . esc_html( $subtitle ) . '</p>'   : '' )
         . '</div>';
}

// ── View All button helper ────────────────────────────────────────────────────
function rd_shop_view_all( string $url, string $label ): string {
    if ( ! $url ) return '';
    return '<div class="rd-view-all">
        <a href="' . esc_url( $url ) . '" class="rd-btn rd-btn--outline">
            ' . esc_html( $label ) . '
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/>
            </svg>
        </a>
    </div>';
}

// ── [rookidroid_products] shortcode ───────────────────────────────────────────
/**
 * Usage:
 *   [rookidroid_products]
 *   [rookidroid_products category="robot" columns="4" limit="8"]
 *   [rookidroid_products category="gadget" columns="3" limit="6" on_sale="true"]
 *   [rookidroid_products ids="12,34,56" columns="3"]
 *
 * Attributes:
 *   category       string   Comma-separated WooCommerce category slugs
 *   columns        int      Grid columns 1–6 (default: 4)
 *   limit          int      Max products, -1 for all (default: 8)
 *   orderby        string   date|title|price|popularity|rating|rand|menu_order (default: date)
 *   order          string   ASC|DESC (default: DESC)
 *   ids            string   Comma-separated product IDs (overrides category)
 *   on_sale        bool     Show only on-sale products (default: false)
 *   tag            string   Small label above the title (optional)
 *   title          string   Section heading (optional)
 *   subtitle       string   Section subheading (optional)
 *   view_all       string   URL for "View All" button (optional)
 *   view_all_label string   Button label (default: "View All Products")
 */
function rd_shop_products_shortcode( array $atts ): string {
    $atts = shortcode_atts( [
        'category'       => '',
        'columns'        => 4,
        'limit'          => 8,
        'orderby'        => 'date',
        'order'          => 'DESC',
        'ids'            => '',
        'on_sale'        => false,
        'tag'            => '',
        'title'          => '',
        'subtitle'       => '',
        'view_all'       => '',
        'view_all_label' => __( 'View All Products', 'rookidroid-shop' ),
    ], $atts, 'rookidroid_products' );

    $query = new WP_Query( rd_shop_build_query_args( $atts ) );

    if ( empty( $query->posts ) ) {
        wp_reset_postdata();
        return '<p class="rd-no-products">' . esc_html__( 'No products found.', 'rookidroid-shop' ) . '</p>';
    }

    $cols      = max( 1, min( 6, (int) $atts['columns'] ) );
    $grid_html = '';

    foreach ( $query->posts as $post ) {
        $product = wc_get_product( $post->ID );
        if ( $product ) {
            $grid_html .= rd_shop_render_card( $product );
        }
    }
    wp_reset_postdata();

    return '<div class="rd-products-wrap">'
         . rd_shop_section_header( $atts['tag'], $atts['title'], $atts['subtitle'] )
         . '<div class="rd-product-grid rd-cols-' . $cols . '">' . $grid_html . '</div>'
         . rd_shop_view_all( $atts['view_all'], $atts['view_all_label'] )
         . '</div>';
}

// ── [rookidroid_shop_grid] shortcode ─────────────────────────────────────────
/**
 * Usage:
 *   [rookidroid_shop_grid]
 *   [rookidroid_shop_grid category="3d-model,electronics,source-code,gadget" limit="12" columns="3"]
 *
 * Attributes:
 *   category   string   Comma-separated category slugs
 *   columns    int      Grid columns 1–4 (default: 3)
 *   limit      int      Max products (default: 12)
 *   orderby    string   date|title|price|popularity|rating|rand|menu_order (default: date)
 *   order      string   ASC|DESC (default: DESC)
 *   ids        string   Comma-separated product IDs (overrides category)
 *   on_sale    bool     Show only on-sale products (default: false)
 *   grid_id    string   Optional custom ID for the product grid (default: productGrid)
 */
function rd_shop_shop_grid_shortcode( array $atts ): string {
    $atts = shortcode_atts( [
        'category' => '',
        'columns'  => 3,
        'limit'    => 12,
        'orderby'  => 'date',
        'order'    => 'DESC',
        'ids'      => '',
        'on_sale'  => false,
        'grid_id'  => 'productGrid',
    ], $atts, 'rookidroid_shop_grid' );

    $query = new WP_Query( rd_shop_build_query_args( $atts ) );

    if ( empty( $query->posts ) ) {
        wp_reset_postdata();
        return '<p class="rd-no-products">' . esc_html__( 'No products found.', 'rookidroid-shop' ) . '</p>';
    }

    $cols      = max( 1, min( 4, (int) $atts['columns'] ) );
    $grid_id   = sanitize_html_class( $atts['grid_id'] ?: 'productGrid' );
    $cards     = '';

    foreach ( $query->posts as $post ) {
        $product = wc_get_product( $post->ID );
        if ( $product ) {
            $cards .= rd_shop_render_shop_card( $product );
        }
    }
    wp_reset_postdata();

    return '<div class="product-grid rd-shop-grid rd-shop-grid--cols-' . esc_attr( (string) $cols ) . '" id="' . esc_attr( $grid_id ) . '">'
        . $cards
        . '</div>';
}

// ── [rookidroid_product_tabs] shortcode ───────────────────────────────────────
/**
 * Usage:
 *   [rookidroid_product_tabs
 *       categories="3d-model,electronics,source-code,gadget"
 *       labels="3D Models,Electronics,Software,Gadgets"
 *       columns="4" limit="8"
 *       title="Featured Products"
 *       subtitle="Hand-crafted robotic kits and components."
 *       view_all="/shop/"]
 *
 * Attributes:
 *   categories      string  Comma-separated category slugs (required)
 *   labels          string  Comma-separated display labels matching categories
 *   columns         int     Grid columns per tab (default: 4)
 *   limit           int     Max products per tab (default: 8)
 *   orderby         string  (default: date)
 *   order           string  ASC|DESC (default: DESC)
 *   show_all        bool    Show "All" tab first (default: true)
 *   all_label       string  "All" tab label (default: "All")
 *   tag             string  Small label above section title
 *   title           string  Section heading
 *   subtitle        string  Section subheading
 *   view_all        string  URL for "View All" button
 *   view_all_label  string  Button label (default: "View All Products")
 */
function rd_shop_product_tabs_shortcode( array $atts ): string {
    $atts = shortcode_atts( [
        'categories'     => '',
        'labels'         => '',
        'columns'        => 4,
        'limit'          => 8,
        'orderby'        => 'date',
        'order'          => 'DESC',
        'show_all'       => true,
        'all_label'      => __( 'All', 'rookidroid-shop' ),
        'tag'            => '',
        'title'          => '',
        'subtitle'       => '',
        'view_all'       => '',
        'view_all_label' => __( 'View All Products', 'rookidroid-shop' ),
    ], $atts, 'rookidroid_product_tabs' );

    $cat_slugs  = array_filter( array_map( 'sanitize_title', explode( ',', $atts['categories'] ) ) );
    $cat_labels = array_map( 'sanitize_text_field', explode( ',', $atts['labels'] ) );

    // Build tab definitions
    $tabs = [];
    if ( filter_var( $atts['show_all'], FILTER_VALIDATE_BOOLEAN ) ) {
        $tabs[] = [ 'slug' => '__all__', 'label' => $atts['all_label'] ];
    }
    foreach ( $cat_slugs as $i => $slug ) {
        $tabs[] = [
            'slug'  => $slug,
            'label' => $cat_labels[ $i ] ?? ucfirst( str_replace( '-', ' ', $slug ) ),
        ];
    }

    if ( empty( $tabs ) ) {
        return '<p class="rd-no-products">' . esc_html__( 'No categories configured.', 'rookidroid-shop' ) . '</p>';
    }

    $cols   = max( 1, min( 6, (int) $atts['columns'] ) );
    $tab_id = 'rd-tabs-' . wp_unique_id();

    // ── Tabs bar
    $tabs_bar = '<div class="rd-tabs" role="tablist">';
    foreach ( $tabs as $idx => $tab ) {
        $is_active = $idx === 0;
        $tabs_bar .= sprintf(
            '<button class="rd-tab-btn%s" role="tab" data-tab="%s" data-tabs-group="%s"
                aria-selected="%s" aria-controls="%s-panel-%s">%s</button>',
            $is_active ? ' rd-tab-btn--active' : '',
            esc_attr( $tab['slug'] ),
            esc_attr( $tab_id ),
            $is_active ? 'true' : 'false',
            esc_attr( $tab_id ),
            esc_attr( $tab['slug'] ),
            esc_html( $tab['label'] )
        );
    }
    $tabs_bar .= '</div>';

    // ── Panels
    $panels_html = '';
    foreach ( $tabs as $idx => $tab ) {
        $is_all   = $tab['slug'] === '__all__';
        $cat_slug = $is_all ? '' : $tab['slug'];

        $query = new WP_Query( rd_shop_build_query_args( [
            'category' => $cat_slug,
            'limit'    => $atts['limit'],
            'orderby'  => $atts['orderby'],
            'order'    => $atts['order'],
            'on_sale'  => false,
            'ids'      => '',
        ] ) );

        $cards = '';
        foreach ( $query->posts as $post ) {
            $product = wc_get_product( $post->ID );
            if ( $product ) {
                $cards .= rd_shop_render_card( $product );
            }
        }
        wp_reset_postdata();

        if ( ! $cards ) {
            $cards = '<p class="rd-no-products">' . esc_html__( 'No products found.', 'rookidroid-shop' ) . '</p>';
        }

        $panels_html .= sprintf(
            '<div id="%s-panel-%s" class="rd-tab-panel" role="tabpanel"%s>
                <div class="rd-product-grid rd-cols-%d">%s</div>
            </div>',
            esc_attr( $tab_id ),
            esc_attr( $tab['slug'] ),
            $idx === 0 ? '' : ' hidden',
            $cols,
            $cards
        );
    }

    return '<div class="rd-products-wrap" id="' . esc_attr( $tab_id ) . '">'
         . rd_shop_section_header( $atts['tag'], $atts['title'], $atts['subtitle'] )
         . $tabs_bar
         . $panels_html
         . rd_shop_view_all( $atts['view_all'], $atts['view_all_label'] )
         . '</div>';
}

// ── AJAX: add to cart ─────────────────────────────────────────────────────────
function rd_shop_ajax_add_to_cart(): void {
    check_ajax_referer( 'rd_shop_nonce', 'nonce' );

    $product_id = absint( $_POST['product_id'] ?? 0 );

    if ( ! $product_id ) {
        wp_send_json_error( [ 'message' => __( 'Invalid product ID.', 'rookidroid-shop' ) ], 400 );
    }

    $product = wc_get_product( $product_id );

    if ( ! $product ) {
        wp_send_json_error( [ 'message' => __( 'Product not found.', 'rookidroid-shop' ) ], 404 );
    }

    if ( ! $product->is_purchasable() ) {
        wp_send_json_error( [ 'message' => __( 'Product is not purchasable.', 'rookidroid-shop' ) ], 400 );
    }

    if ( ! $product->is_in_stock() ) {
        wp_send_json_error( [ 'message' => __( 'Product is out of stock.', 'rookidroid-shop' ) ], 400 );
    }

    $cart_item_key = WC()->cart->add_to_cart( $product_id, 1 );

    if ( ! $cart_item_key ) {
        wp_send_json_error( [ 'message' => __( 'Could not add to cart.', 'rookidroid-shop' ) ], 500 );
    }

    WC()->cart->calculate_totals();

    wp_send_json_success( [
        'cart_count' => WC()->cart->get_cart_contents_count(),
        'cart_total' => WC()->cart->get_cart_total(),
        'cart_url'   => wc_get_cart_url(),
    ] );
}

// ═══════════════════════════════════════════════════════════════════════════════
// ── Admin: Featured Products Config ──────────────────────────────────────────
// ═══════════════════════════════════════════════════════════════════════════════

/** Register the WooCommerce → RookiDroid Featured submenu */
function rd_shop_admin_menu(): void {
    add_submenu_page(
        'woocommerce',
        __( 'RookiDroid Featured', 'rookidroid-shop' ),
        __( 'RookiDroid Featured', 'rookidroid-shop' ),
        'manage_options',
        'rd-shop-featured',
        'rd_shop_admin_page'
    );
}

/** Enqueue admin assets only on our settings page */
function rd_shop_admin_enqueue( string $hook ): void {
    if ( 'woocommerce_page_rd-shop-featured' !== $hook ) {
        return;
    }
    // Inline admin styles — no extra file needed
    wp_add_inline_style( 'wp-admin', rd_shop_admin_css() );

    // Load saved products HERE, before wp_head() prints scripts.
    // Calling wp_add_inline_script() from the page-render callback fires
    // after the <head> is already output, so data would be lost.
    $saved_ids      = array_filter( array_map( 'absint', explode( ',', get_option( 'rd_shop_featured_product_ids', '' ) ) ) );
    $saved_products = [];
    foreach ( $saved_ids as $pid ) {
        $p = wc_get_product( $pid );
        if ( $p ) {
            $thumb_id       = $p->get_image_id();
            $thumb_url      = $thumb_id ? wp_get_attachment_image_url( $thumb_id, [ 48, 48 ] ) : '';
            $saved_products[] = [
                'id'    => $p->get_id(),
                'name'  => $p->get_name(),
                'thumb' => $thumb_url ?: '',
            ];
        }
    }

    // Inline admin JS
    wp_enqueue_script( 'jquery' );
    wp_add_inline_script( 'jquery', rd_shop_admin_js() );
    wp_localize_script( 'jquery', 'rdShopAdmin', [
        'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
        'nonce'         => wp_create_nonce( 'rd_search_products' ),
        'savedProducts' => $saved_products,
        'i18n'          => [
            'searching'   => __( 'Searching…', 'rookidroid-shop' ),
            'noResults'   => __( 'No products found.', 'rookidroid-shop' ),
            'remove'      => __( 'Remove', 'rookidroid-shop' ),
            'placeholder' => __( 'Type a product name…', 'rookidroid-shop' ),
        ],
    ] );
}

/** Returns the inline CSS string for the admin page */
function rd_shop_admin_css(): string {
    return '
/* ── RookiDroid Shop Admin Page ── */
#rd-featured-wrap {
    max-width: 860px;
    margin: 32px 20px 0;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
}
#rd-featured-wrap h1.rd-admin-title {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 1.5rem;
    font-weight: 700;
    color: #1a1a2e;
    margin-bottom: 6px;
}
#rd-featured-wrap h1.rd-admin-title span.rd-admin-badge {
    background: linear-gradient(135deg, #9c27b0, #7b1fa2);
    color: #fff;
    font-size: .7rem;
    font-weight: 600;
    letter-spacing: .06em;
    text-transform: uppercase;
    border-radius: 20px;
    padding: 2px 10px;
}
.rd-admin-card {
    background: #fff;
    border: 1px solid #e0e0e8;
    border-radius: 12px;
    padding: 24px 28px;
    margin-bottom: 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,.05);
}
.rd-admin-card h2 {
    font-size: 1rem;
    font-weight: 600;
    color: #1a1a2e;
    margin: 0 0 16px;
    padding-bottom: 12px;
    border-bottom: 1px solid #f0f0f5;
    display: flex;
    align-items: center;
    gap: 8px;
}
.rd-admin-card h2 svg { flex-shrink: 0; color: #9c27b0; }

/* Search bar */
.rd-search-row {
    display: flex;
    gap: 10px;
    margin-bottom: 12px;
}
#rd-product-search {
    flex: 1;
    border: 1.5px solid #d0d0df;
    border-radius: 8px;
    padding: 9px 14px;
    font-size: .95rem;
    outline: none;
    transition: border-color .2s;
}
#rd-product-search:focus { border-color: #9c27b0; }
#rd-search-results {
    background: #fff;
    border: 1.5px solid #d0d0df;
    border-radius: 8px;
    max-height: 240px;
    overflow-y: auto;
    display: none;
    box-shadow: 0 6px 24px rgba(0,0,0,.10);
    position: relative;
    z-index: 100;
    margin-top: -6px;
}
.rd-search-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 14px;
    cursor: pointer;
    transition: background .15s;
    border-bottom: 1px solid #f4f4f8;
}
.rd-search-item:last-child { border-bottom: none; }
.rd-search-item:hover { background: #f8f4fc; }
.rd-search-item img {
    width: 40px;
    height: 40px;
    object-fit: cover;
    border-radius: 6px;
    flex-shrink: 0;
    background: #f0f0f5;
}
.rd-search-item .rd-si-name { font-size: .9rem; font-weight: 500; color: #1a1a2e; }
.rd-search-item .rd-si-id   { font-size: .78rem; color: #8e8ea0; margin-top: 2px; }
.rd-search-msg { padding: 14px; color: #8e8ea0; font-size: .9rem; text-align: center; }
.rd-search-add-btn {
    background: linear-gradient(135deg, #9c27b0, #7b1fa2);
    color: #fff;
    border: none;
    border-radius: 8px;
    padding: 9px 20px;
    font-size: .9rem;
    font-weight: 600;
    cursor: pointer;
    white-space: nowrap;
    display: none;
    transition: opacity .2s;
}
.rd-search-add-btn:hover { opacity: .88; }

/* Selected products list */
#rd-selected-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
    min-height: 48px;
}
.rd-selected-item {
    display: flex;
    align-items: center;
    gap: 14px;
    background: #fafafa;
    border: 1.5px solid #e8e8f0;
    border-radius: 10px;
    padding: 10px 14px;
    transition: border-color .2s;
}
.rd-selected-item:hover { border-color: #c8b0d8; }
.rd-selected-item img {
    width: 48px;
    height: 48px;
    object-fit: cover;
    border-radius: 7px;
    background: #f0f0f5;
    flex-shrink: 0;
}
.rd-selected-info { flex: 1; }
.rd-selected-info .rd-si-name { font-size: .92rem; font-weight: 600; color: #1a1a2e; }
.rd-selected-info .rd-si-id   { font-size: .78rem; color: #8e8ea0; margin-top: 2px; }
.rd-remove-btn {
    background: none;
    border: 1.5px solid #e0e0ea;
    border-radius: 7px;
    padding: 5px 11px;
    font-size: .8rem;
    font-weight: 600;
    color: #c0392b;
    cursor: pointer;
    transition: background .15s, border-color .15s;
    flex-shrink: 0;
}
.rd-remove-btn:hover { background: #ffeaea; border-color: #c0392b; }
.rd-drag-handle {
    cursor: grab;
    color: #c0c0cc;
    font-size: 1.1rem;
    user-select: none;
    margin-right: 4px;
}
.rd-empty-state {
    text-align: center;
    padding: 28px 0;
    color: #8e8ea0;
    font-size: .9rem;
    border: 2px dashed #e0e0ea;
    border-radius: 10px;
}
.rd-empty-state svg { margin-bottom: 8px; opacity: .4; }

/* Save button */
.rd-save-row {
    display: flex;
    align-items: center;
    gap: 14px;
    flex-wrap: wrap;
}
#rd-save-btn {
    background: linear-gradient(135deg, #9c27b0, #7b1fa2);
    color: #fff;
    border: none;
    border-radius: 9px;
    padding: 11px 32px;
    font-size: 1rem;
    font-weight: 700;
    cursor: pointer;
    transition: opacity .2s, transform .1s;
    letter-spacing: .02em;
}
#rd-save-btn:hover { opacity: .88; transform: translateY(-1px); }
#rd-save-btn:active { transform: translateY(0); }
.rd-admin-notice {
    font-size: .88rem;
    color: #2e7d32;
    font-weight: 500;
    display: none;
}
.rd-shortcode-hint {
    background: #f8f4fc;
    border: 1px solid #d4a8e8;
    border-radius: 9px;
    padding: 14px 18px;
}
.rd-shortcode-hint code {
    background: #ede7f6;
    color: #6a1b9a;
    border-radius: 4px;
    padding: 2px 7px;
    font-size: .88rem;
}
.rd-shortcode-hint p { margin: 4px 0; font-size: .88rem; color: #555770; }
.rd-count-badge {
    background: #ede7f6;
    color: #7b1fa2;
    font-size: .72rem;
    font-weight: 700;
    border-radius: 20px;
    padding: 2px 9px;
    margin-left: 6px;
}
    ';
}

/** Returns the inline JS string for the admin page */
function rd_shop_admin_js(): string {
    return '
(function($){
    var selectedProducts = [];
    var searchTimer = null;
    var selectedCandidate = null;

    function renderSelected() {
        var $list = $("#rd-selected-list");
        $list.empty();
        $("#rd-count-badge").text(selectedProducts.length);
        if (selectedProducts.length === 0) {
            $list.append(
                \'<div class="rd-empty-state">\' +
                \'<svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>\' +
                \'<br>No products selected yet. Use the search above to add some.\' +
                \'</div>\'
            );
            $("#rd-hidden-ids").val("");
            return;
        }
        $.each(selectedProducts, function(i, p) {
            var img = p.thumb
                ? \'<img src="\' + p.thumb + \'" alt="\' + $("<div>").text(p.name).html() + \'">\' 
                : \'<img src="" alt="" style="background:#e8e0f0;">\';
            var html = \'<div class="rd-selected-item" data-id="\' + p.id + \'">\' +
                \'<span class="rd-drag-handle" title="Drag to reorder">⠿</span>\' +
                img +
                \'<div class="rd-selected-info">\' +
                    \'<div class="rd-si-name">\' + $("<div>").text(p.name).html() + \'</div>\' +
                    \'<div class="rd-si-id\">ID: \' + p.id + \'</div>\' +
                \'</div>\' +
                \'<button class="rd-remove-btn" data-id="\' + p.id + \'">✕ Remove</button>\' +
                \'</div>\';
            $list.append(html);
        });
        // Update hidden input
        $("#rd-hidden-ids").val($.map(selectedProducts, function(p){ return p.id; }).join(","));

        // Sortable drag support
        makeSortable($list[0]);
    }

    function makeSortable(el) {
        var dragging = null;
        $(el).find(".rd-selected-item").each(function() {
            var item = this;
            item.draggable = true;
            item.addEventListener("dragstart", function(e) {
                dragging = item;
                setTimeout(function(){ $(item).css("opacity","0.4"); }, 0);
            });
            item.addEventListener("dragend", function() {
                $(item).css("opacity","");
                dragging = null;
                // Rebuild order
                var newOrder = [];
                $(el).find(".rd-selected-item").each(function(){
                    var id = parseInt($(this).data("id"), 10);
                    var found = selectedProducts.find(function(p){ return p.id === id; });
                    if (found) newOrder.push(found);
                });
                selectedProducts = newOrder;
                $("#rd-hidden-ids").val($.map(selectedProducts, function(p){ return p.id; }).join(","));
            });
            item.addEventListener("dragover", function(e) {
                e.preventDefault();
                if (dragging && dragging !== item) {
                    var rect = item.getBoundingClientRect();
                    var mid  = rect.top + rect.height / 2;
                    if (e.clientY < mid) {
                        el.insertBefore(dragging, item);
                    } else {
                        el.insertBefore(dragging, item.nextSibling);
                    }
                }
            });
        });
    }

    function addProduct(p) {
        var exists = selectedProducts.some(function(x){ return x.id === p.id; });
        if (!exists) {
            selectedProducts.push(p);
            renderSelected();
        }
        clearSearch();
    }

    function clearSearch() {
        $("#rd-product-search").val("");
        $("#rd-search-results").hide().empty();
        $("#rd-search-add-btn").hide();
        selectedCandidate = null;
    }

    $(document).ready(function(){

        // ── Pre-populate from saved option (injected via wp_localize_script extra data)
        if (typeof rdShopAdmin.savedProducts !== "undefined") {
            selectedProducts = rdShopAdmin.savedProducts;
            renderSelected();
        } else {
            renderSelected();
        }

        // ── Live product search
        $("#rd-product-search").on("input", function(){
            var q = $.trim($(this).val());
            clearTimeout(searchTimer);
            if (q.length < 2) {
                $("#rd-search-results").hide().empty();
                return;
            }
            searchTimer = setTimeout(function(){
                var $res = $("#rd-search-results");
                $res.show().html(\'<div class="rd-search-msg">\' + rdShopAdmin.i18n.searching + \'</div>\');
                $.ajax({
                    url: rdShopAdmin.ajaxUrl,
                    method: "GET",
                    data: { action: "rd_search_products", nonce: rdShopAdmin.nonce, q: q },
                    success: function(resp) {
                        $res.empty();
                        if (!resp.success || !resp.data.length) {
                            $res.html(\'<div class="rd-search-msg">\' + rdShopAdmin.i18n.noResults + \'</div>\');
                            return;
                        }
                        $.each(resp.data, function(i, p){
                            var img = p.thumb
                                ? \'<img src="\' + p.thumb + \'" alt="\' + $("<div>").text(p.name).html() + \'">\' 
                                : \'<img src="" alt="" style="background:#e8e0f0;width:40px;height:40px;border-radius:6px;">\';
                            var row = $(
                                \'<div class="rd-search-item" data-id="\' + p.id + \'">\' +
                                img +
                                \'<div><div class="rd-si-name">\' + $("<div>").text(p.name).html() + \'</div><div class="rd-si-id">ID: \' + p.id + \'</div></div>\' +
                                \'</div>\'
                            );
                            row.on("click", function(){
                                addProduct(p);
                            });
                            $res.append(row);
                        });
                    },
                    error: function() {
                        $res.html(\'<div class="rd-search-msg">Request failed. Please try again.</div>\');
                    }
                });
            }, 300);
        });

        // Close dropdown when clicking outside
        $(document).on("click", function(e){
            if (!$(e.target).closest("#rd-search-results, #rd-product-search").length) {
                $("#rd-search-results").hide();
            }
        });

        // Remove button
        $(document).on("click", ".rd-remove-btn", function(){
            var id = parseInt($(this).data("id"), 10);
            selectedProducts = selectedProducts.filter(function(p){ return p.id !== id; });
            renderSelected();
        });

        // Save button: update label only — do NOT disable, as that prevents form submission
        $("#rd-save-btn").closest("form").on("submit", function(){
            $("#rd-save-btn").text("Saving\u2026");
        });

        // Show success notice if ?rd_saved=1
        if (window.location.search.indexOf("rd_saved=1") !== -1) {
            $(".rd-admin-notice").show();
            setTimeout(function(){ $(".rd-admin-notice").fadeOut(); }, 4000);
        }
    });
}(jQuery));
    ';
}

/** Render the admin config page HTML */
function rd_shop_admin_page(): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission to access this page.', 'rookidroid-shop' ) );
    }

    // Load currently saved products
    $saved_ids = array_filter( array_map( 'absint', explode( ',', get_option( 'rd_shop_featured_product_ids', '' ) ) ) );
    $saved_products = [];
    foreach ( $saved_ids as $pid ) {
        $p = wc_get_product( $pid );
        if ( $p ) {
            $thumb_id  = $p->get_image_id();
            $thumb_url = $thumb_id ? wp_get_attachment_image_url( $thumb_id, [ 48, 48 ] ) : '';
            $saved_products[] = [
                'id'    => $p->get_id(),
                'name'  => $p->get_name(),
                'thumb' => $thumb_url ?: '',
            ];
        }
    }

    // Note: saved products are passed via wp_localize_script() in rd_shop_admin_enqueue(),
    // not here — the page-render callback runs after wp_head() has already output scripts.
    $count = count( $saved_products );
    ?>
    <div id="rd-featured-wrap">
        <h1 class="rd-admin-title">
            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#9c27b0" stroke-width="2" aria-hidden="true"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
            <?php esc_html_e( 'RookiDroid Featured Products', 'rookidroid-shop' ); ?>
            <span class="rd-admin-badge">v<?php echo esc_html( RD_SHOP_VERSION ); ?></span>
        </h1>
        <p style="color:#555770;margin-bottom:24px;"><?php esc_html_e( 'Select the products that will be displayed by the [rookidroid_featured_products] shortcode.', 'rookidroid-shop' ); ?></p>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'rd_shop_save_featured', 'rd_shop_nonce' ); ?>
            <input type="hidden" name="action" value="rd_shop_save_featured">
            <input type="hidden" name="rd_featured_ids" id="rd-hidden-ids" value="<?php echo esc_attr( implode( ',', array_column( $saved_products, 'id' ) ) ); ?>">

            <!-- Search card -->
            <div class="rd-admin-card">
                <h2>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    <?php esc_html_e( 'Search &amp; Add Products', 'rookidroid-shop' ); ?>
                </h2>
                <div class="rd-search-row">
                    <input
                        type="text"
                        id="rd-product-search"
                        autocomplete="off"
                        placeholder="<?php esc_attr_e( 'Type a product name…', 'rookidroid-shop' ); ?>"
                        aria-label="<?php esc_attr_e( 'Search products', 'rookidroid-shop' ); ?>"
                    >
                </div>
                <div id="rd-search-results" role="listbox" aria-label="<?php esc_attr_e( 'Search results', 'rookidroid-shop' ); ?>"></div>
            </div>

            <!-- Selected products card -->
            <div class="rd-admin-card">
                <h2>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                    <?php esc_html_e( 'Selected Products', 'rookidroid-shop' ); ?>
                    <span class="rd-count-badge" id="rd-count-badge"><?php echo esc_html( (string) $count ); ?></span>
                </h2>
                <div id="rd-selected-list">
                    <?php if ( empty( $saved_products ) ) : ?>
                        <div class="rd-empty-state">
                            <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                            <br><?php esc_html_e( 'No products selected yet. Use the search above to add some.', 'rookidroid-shop' ); ?>
                        </div>
                    <?php else : ?>
                        <?php foreach ( $saved_products as $p ) : ?>
                            <div class="rd-selected-item" data-id="<?php echo esc_attr( (string) $p['id'] ); ?>">
                                <span class="rd-drag-handle" title="<?php esc_attr_e( 'Drag to reorder', 'rookidroid-shop' ); ?>">⠿</span>
                                <?php if ( $p['thumb'] ) : ?>
                                    <img src="<?php echo esc_url( $p['thumb'] ); ?>" alt="<?php echo esc_attr( $p['name'] ); ?>">
                                <?php else : ?>
                                    <img src="" alt="" style="background:#e8e0f0;">
                                <?php endif; ?>
                                <div class="rd-selected-info">
                                    <div class="rd-si-name"><?php echo esc_html( $p['name'] ); ?></div>
                                    <div class="rd-si-id">ID: <?php echo esc_html( (string) $p['id'] ); ?></div>
                                </div>
                                <button type="button" class="rd-remove-btn" data-id="<?php echo esc_attr( (string) $p['id'] ); ?>">✕ <?php esc_html_e( 'Remove', 'rookidroid-shop' ); ?></button>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Save row -->
            <div class="rd-admin-card">
                <div class="rd-save-row">
                    <button type="submit" id="rd-save-btn"><?php esc_html_e( 'Save Featured Products', 'rookidroid-shop' ); ?></button>
                    <span class="rd-admin-notice">✓ <?php esc_html_e( 'Changes saved successfully!', 'rookidroid-shop' ); ?></span>
                </div>
            </div>

            <!-- Shortcode reference card -->
            <div class="rd-admin-card rd-shortcode-hint">
                <h2>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>
                    <?php esc_html_e( 'Shortcode Usage', 'rookidroid-shop' ); ?>
                </h2>
                <p><?php esc_html_e( 'Use this shortcode on any page or post to display your featured products:', 'rookidroid-shop' ); ?></p>
                <p><code>[rookidroid_featured_products]</code></p>
                <p><?php esc_html_e( 'With options:', 'rookidroid-shop' ); ?></p>
                <p><code>[rookidroid_featured_products columns="3" title="Featured Products" subtitle="Hand-picked favourites" tag="★ Featured" view_all="/shop/"]</code></p>
                <p style="margin-top:10px;font-size:.85rem;color:#8e8ea0;">
                    <?php esc_html_e( 'Supports the same attributes as [rookidroid_products]: columns, limit, tag, title, subtitle, view_all, view_all_label.', 'rookidroid-shop' ); ?>
                </p>
            </div>
        </form>
    </div>
    <?php
}

/** Handle POST save for featured products */
function rd_shop_admin_save(): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Unauthorized.', 'rookidroid-shop' ), 403 );
    }

    check_admin_referer( 'rd_shop_save_featured', 'rd_shop_nonce' );

    $raw = sanitize_text_field( wp_unslash( $_POST['rd_featured_ids'] ?? '' ) );
    $ids = array_filter( array_map( 'absint', explode( ',', $raw ) ) );

    update_option( 'rd_shop_featured_product_ids', implode( ',', $ids ) );

    wp_safe_redirect( add_query_arg( [
        'page'     => 'rd-shop-featured',
        'rd_saved' => '1',
    ], admin_url( 'admin.php' ) ) );
    exit;
}

/** AJAX: search WooCommerce products by keyword (admin-only) */
function rd_shop_ajax_search_products(): void {
    check_ajax_referer( 'rd_search_products', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [], 403 );
    }

    $q = sanitize_text_field( wp_unslash( $_GET['q'] ?? '' ) );

    if ( strlen( $q ) < 2 ) {
        wp_send_json_success( [] );
    }

    $products = wc_get_products( [
        'status'  => 'publish',
        'limit'   => 20,
        'orderby' => 'title',
        'order'   => 'ASC',
        's'       => $q,
    ] );

    $results = [];
    foreach ( $products as $product ) {
        $thumb_id  = $product->get_image_id();
        $thumb_url = $thumb_id ? wp_get_attachment_image_url( $thumb_id, [ 48, 48 ] ) : '';
        $results[] = [
            'id'    => $product->get_id(),
            'name'  => $product->get_name(),
            'thumb' => $thumb_url ?: '',
        ];
    }

    wp_send_json_success( $results );
}

// ── [rookidroid_featured_products] shortcode ──────────────────────────────────
/**
 * Usage:
 *   [rookidroid_featured_products]
 *   [rookidroid_featured_products columns="4" title="Featured Products" tag="★ Featured"
 *       view_all="/shop/" show_all="true" all_label="All"]
 *
 *   Override auto-detected categories:
 *   [rookidroid_featured_products categories="3d-model,electronics" labels="3D Models,Electronics"]
 *
 * Automatically uses the product IDs saved on the config page.
 * Renders the same tab-filter UI as [rookidroid_product_tabs], but only shows
 * products that are in the saved featured list.
 *
 * Attributes:
 *   categories      string   Optional comma-separated category slugs for tabs.
 *                            If omitted, categories are auto-detected from the saved products.
 *   labels          string   Optional comma-separated display labels matching categories.
 *   columns         int      Grid columns per tab (default: 4)
 *   limit           int      Max products per tab, -1 for all (default: -1)
 *   show_all        bool     Show an "All" tab first (default: true)
 *   all_label       string   Label for the "All" tab (default: "All")
 *   tag             string   Small label above section title
 *   title           string   Section heading
 *   subtitle        string   Section subheading
 *   view_all        string   URL for "View All" button
 *   view_all_label  string   Button label (default: "View All Products")
 */
function rd_shop_featured_products_shortcode( array $atts ): string {
    $saved_ids_raw = get_option( 'rd_shop_featured_product_ids', '' );
    $saved_ids     = array_values( array_filter( array_map( 'absint', explode( ',', $saved_ids_raw ) ) ) );

    if ( empty( $saved_ids ) ) {
        return '<p class="rd-no-products">' . esc_html__( 'No featured products have been configured yet.', 'rookidroid-shop' ) . '</p>';
    }

    $atts = shortcode_atts( [
        'categories'     => '',
        'labels'         => '',
        'columns'        => 4,
        'limit'          => -1,
        'show_all'       => true,
        'all_label'      => __( 'All', 'rookidroid-shop' ),
        'tag'            => '',
        'title'          => '',
        'subtitle'       => '',
        'view_all'       => '',
        'view_all_label' => __( 'View All Products', 'rookidroid-shop' ),
    ], $atts, 'rookidroid_featured_products' );

    // ── Determine categories ───────────────────────────────────────────────────
    if ( ! empty( $atts['categories'] ) ) {
        // Explicit categories provided
        $cat_slugs  = array_values( array_filter( array_map( 'sanitize_title', explode( ',', $atts['categories'] ) ) ) );
        $cat_labels = array_map( 'sanitize_text_field', explode( ',', $atts['labels'] ) );
    } else {
        // Auto-detect categories from the saved featured products
        $cat_slugs  = [];
        $cat_labels = [];
        $seen_slugs = [];

        foreach ( $saved_ids as $pid ) {
            $terms = get_the_terms( $pid, 'product_cat' );
            if ( ! $terms || is_wp_error( $terms ) ) {
                continue;
            }
            foreach ( $terms as $term ) {
                if ( ! isset( $seen_slugs[ $term->slug ] ) ) {
                    $seen_slugs[ $term->slug ] = true;
                    $cat_slugs[]  = $term->slug;
                    $cat_labels[] = $term->name;
                }
            }
        }
    }

    // ── Build tabs ─────────────────────────────────────────────────────────────
    $tabs = [];
    if ( filter_var( $atts['show_all'], FILTER_VALIDATE_BOOLEAN ) ) {
        $tabs[] = [ 'slug' => '__all__', 'label' => sanitize_text_field( $atts['all_label'] ) ];
    }
    foreach ( $cat_slugs as $i => $slug ) {
        $tabs[] = [
            'slug'  => $slug,
            'label' => $cat_labels[ $i ] ?? ucfirst( str_replace( '-', ' ', $slug ) ),
        ];
    }

    // If no tabs could be determined, fall back to a plain grid
    if ( empty( $tabs ) ) {
        $fallback_atts            = $atts;
        $fallback_atts['ids']     = implode( ',', $saved_ids );
        $fallback_atts['on_sale'] = false;
        $fallback_atts['orderby'] = 'post__in';
        return rd_shop_products_shortcode( $fallback_atts );
    }

    $cols   = max( 1, min( 6, (int) $atts['columns'] ) );
    $tab_id = 'rd-tabs-' . wp_unique_id();

    // ── Tabs bar ───────────────────────────────────────────────────────────────
    $tabs_bar = '<div class="rd-tabs" role="tablist">';
    foreach ( $tabs as $idx => $tab ) {
        $is_active = $idx === 0;
        $tabs_bar .= sprintf(
            '<button class="rd-tab-btn%s" role="tab" data-tab="%s" data-tabs-group="%s"
                aria-selected="%s" aria-controls="%s-panel-%s">%s</button>',
            $is_active ? ' rd-tab-btn--active' : '',
            esc_attr( $tab['slug'] ),
            esc_attr( $tab_id ),
            $is_active ? 'true' : 'false',
            esc_attr( $tab_id ),
            esc_attr( $tab['slug'] ),
            esc_html( $tab['label'] )
        );
    }
    $tabs_bar .= '</div>';

    // ── Panels ─────────────────────────────────────────────────────────────────
    $panels_html = '';
    foreach ( $tabs as $idx => $tab ) {
        $is_all = $tab['slug'] === '__all__';

        // Build a query restricted to saved IDs, optionally filtered by category
        $query_args = [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => (int) $atts['limit'] === -1 ? -1 : (int) $atts['limit'],
            'post__in'       => $saved_ids,   // always restricted to featured set
            'orderby'        => 'post__in',   // preserve admin-configured order
            'tax_query'      => [ 'relation' => 'AND' ], // phpcs:ignore WordPress.DB.SlowDBQuery
        ];

        // Exclude hidden products
        $query_args['tax_query'][] = [
            'taxonomy' => 'product_visibility',
            'field'    => 'name',
            'terms'    => [ 'exclude-from-catalog' ],
            'operator' => 'NOT IN',
        ];

        // For non-All tabs, add a category filter
        if ( ! $is_all ) {
            $query_args['tax_query'][] = [
                'taxonomy' => 'product_cat',
                'field'    => 'slug',
                'terms'    => [ $tab['slug'] ],
            ];
        }

        $query = new WP_Query( $query_args );
        $cards = '';
        foreach ( $query->posts as $post ) {
            $product = wc_get_product( $post->ID );
            if ( $product ) {
                $cards .= rd_shop_render_card( $product );
            }
        }
        wp_reset_postdata();

        if ( ! $cards ) {
            $cards = '<p class="rd-no-products">' . esc_html__( 'No products found.', 'rookidroid-shop' ) . '</p>';
        }

        $panels_html .= sprintf(
            '<div id="%s-panel-%s" class="rd-tab-panel" role="tabpanel"%s>
                <div class="rd-product-grid rd-cols-%d">%s</div>
            </div>',
            esc_attr( $tab_id ),
            esc_attr( $tab['slug'] ),
            $idx === 0 ? '' : ' hidden',
            $cols,
            $cards
        );
    }

    return '<div class="rd-products-wrap" id="' . esc_attr( $tab_id ) . '">'
         . rd_shop_section_header( $atts['tag'], $atts['title'], $atts['subtitle'] )
         . $tabs_bar
         . $panels_html
         . rd_shop_view_all( $atts['view_all'], $atts['view_all_label'] )
         . '</div>';
}

