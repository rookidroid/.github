<?php
/**
 * Plugin Name:  RookiDroid Shop
 * Plugin URI:   https://rookidroid.com/
 * Description:  Custom-styled WooCommerce product grid shortcodes matching the RookiDroid brand design. Provides [rookidroid_products], [rookidroid_product_tabs], and [rookidroid_shop_grid].
 * Version:      1.1.0
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

define( 'RD_SHOP_VERSION', '1.1.0' );
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
    add_shortcode( 'rookidroid_products',     'rd_shop_products_shortcode' );
    add_shortcode( 'rookidroid_product_tabs', 'rd_shop_product_tabs_shortcode' );
    add_shortcode( 'rookidroid_shop_grid',    'rd_shop_shop_grid_shortcode' );
    add_action( 'wp_ajax_rd_add_to_cart',        'rd_shop_ajax_add_to_cart' );
    add_action( 'wp_ajax_nopriv_rd_add_to_cart', 'rd_shop_ajax_add_to_cart' );
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
