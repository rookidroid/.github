=== RookiDroid Shop ===
Contributors: rookidroid
Tags: woocommerce, products, shortcode, grid, custom-style
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 1.1.0
WC requires at least: 8.0
WC tested up to: 9.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Custom-styled WooCommerce product grids that match the RookiDroid brand design (#9c27b0).

== Description ==

Provides shortcodes that render WooCommerce products with the RookiDroid card design, replacing the unstyled default `[products]` output.

= Shortcodes =

**[rookidroid_products]** — Simple product grid.

  [rookidroid_products
      category="robot"
      columns="4"
      limit="8"
      tag="Shop"
      title="Featured Products"
      subtitle="Hand-crafted robotic kits."
      view_all="/shop/"]

**[rookidroid_product_tabs]** — Tabbed product grid with one tab per category.

  [rookidroid_product_tabs
      categories="3d-model,electronics,source-code,gadget"
      labels="3D Models,Electronics,Software,Gadgets"
      columns="4"
      limit="8"
      show_all="true"
      all_label="All"
      tag="Shop"
      title="Featured Products"
      subtitle="Hand-crafted robotic kits."
      view_all="/shop/"]

**[rookidroid_shop_grid]** — Shop-page compatible grid markup for use with the custom shop template controls.

  [rookidroid_shop_grid
      category="3d-model,electronics,source-code,gadget"
      columns="3"
      limit="12"
      orderby="date"
      order="DESC"
      grid_id="productGrid"]

= Shared Attributes =

| Attribute       | Default              | Description                                       |
|-----------------|----------------------|---------------------------------------------------|
| columns         | 4                    | Grid columns (1–6, responsive)                    |
| limit           | 8                    | Max products per grid (-1 = all)                  |
| orderby         | date                 | date, title, price, popularity, rating, rand       |
| order           | DESC                 | ASC or DESC                                       |
| ids             | —                    | Comma-separated product IDs (overrides category)  |
| on_sale         | false                | true = only show on-sale products                 |
| tag             | —                    | Small label above section title                   |
| title           | —                    | Section heading                                   |
| subtitle        | —                    | Section sub-heading                               |
| view_all        | —                    | URL for "View All Products" button                |
| view_all_label  | View All Products    | Custom button label                               |

== Installation ==

1. Upload the `rookidroid-shop` folder to `/wp-content/plugins/`.
2. Activate via **Plugins → Installed Plugins**.
3. Use the shortcodes on any page or in a Neve Custom HTML block.

Optionally copy the CSS `:root` variables from the homepage HTML into
**Appearance → Customize → Additional CSS** so other design elements
(hero, categories section, etc.) pick up the same tokens.

== Changelog ==

= 1.0.0 =
* Initial release.

= 1.1.0 =
* Added `[rookidroid_shop_grid]` shortcode to render shop-page compatible `product-grid` / `product-card` markup from WooCommerce products.
* Added fallback CSS for dynamic shop grid columns and placeholder image styling.
