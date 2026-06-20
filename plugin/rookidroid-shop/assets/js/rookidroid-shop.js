/* global rdShop, jQuery */
(function ($) {
  'use strict';

  // ── Shop banner: move to full-width position before Neve's column layout ──────
  // The banner is injected inside the flex .row by woocommerce_before_main_content.
  // We move it to be the first child of <main> so it spans the full content width
  // without being constrained by flex-shrink or the column container.
  (function () {
    var banner = document.querySelector('.rd-shop-banner');
    if (!banner) return;
    var main = document.querySelector('main.neve-main') ||
               document.querySelector('main#primary')  ||
               document.querySelector('main');
    if (main) {
      main.insertBefore(banner, main.firstChild);
    }
  }());

  // ── Tab switching ────────────────────────────────────────────────────────────
  $(document).on('click', '.rd-tab-btn', function () {
    var $btn     = $(this);
    var tabSlug  = $btn.data('tab');
    var groupId  = $btn.data('tabs-group');
    var $wrap    = $('#' + groupId);

    if ($btn.hasClass('rd-tab-btn--active')) return;

    // Update button states
    $wrap.find('.rd-tab-btn')
      .removeClass('rd-tab-btn--active')
      .attr('aria-selected', 'false');

    $btn.addClass('rd-tab-btn--active').attr('aria-selected', 'true');

    // Show the matching panel, hide others
    $wrap.find('.rd-tab-panel').each(function () {
      var $panel = $(this);
      if ($panel.attr('id') === groupId + '-panel-' + tabSlug) {
        $panel.removeAttr('hidden');
      } else {
        $panel.attr('hidden', true);
      }
    });
  });

  // ── AJAX add-to-cart ─────────────────────────────────────────────────────────
  $(document).on('click', '.rd-add-to-cart', function (e) {
    e.preventDefault();

    var $btn       = $(this);
    var productId  = $btn.data('product-id');

    if ($btn.hasClass('rd-loading') || $btn.hasClass('rd-added')) return;

    $btn.addClass('rd-loading').text(rdShop.i18n.adding);

    $.ajax({
      url:    rdShop.ajaxUrl,
      method: 'POST',
      data: {
        action:     'rd_add_to_cart',
        product_id: productId,
        nonce:      rdShop.nonce,
      },
      success: function (resp) {
        $btn.removeClass('rd-loading');

        if (resp.success) {
          $btn.addClass('rd-added').text(rdShop.i18n.added);

          // Update any cart count elements (Neve header, custom theme, etc.)
          updateCartCount(resp.data.cart_count);

          // Trigger WooCommerce native fragment refresh so the mini-cart updates
          $(document.body).trigger('wc_fragment_refresh');

          // Reset button label after 2 s
          setTimeout(function () {
            $btn.removeClass('rd-added');
            $btn.text(rdShop.i18n.addToCart);
          }, 2000);
        } else {
          $btn.text(rdShop.i18n.addToCart);
          showCartError(resp.data && resp.data.message);
        }
      },
      error: function () {
        $btn.removeClass('rd-loading').text(rdShop.i18n.addToCart);
      },
    });
  });

  // ── Cart count update ────────────────────────────────────────────────────────
  function updateCartCount(count) {
    // Neve header cart widget
    $('.header-cart-sidebar-toggle .cart-count').text(count);
    // WooCommerce default cart widget
    $('.cart-contents .count, .widget_shopping_cart_content .count').text('(' + count + ')');
    // Our custom cart-count (from homepage preview header)
    $('.cart-count').text(count);
  }

  // ── Inline error notice ──────────────────────────────────────────────────────
  function showCartError(message) {
    if (!message) return;
    var $notice = $('<div class="rd-cart-notice rd-cart-notice--error" role="alert">' + $('<span>').text(message).html() + '</div>');
    $('body').append($notice);
    setTimeout(function () { $notice.addClass('rd-cart-notice--visible'); }, 10);
    setTimeout(function () {
      $notice.removeClass('rd-cart-notice--visible');
      setTimeout(function () { $notice.remove(); }, 300);
    }, 3500);
  }

  // ── Shop-page grid: filter / sort / search ───────────────────────────────────
  // Activates when [rookidroid_shop_grid] is on the page alongside the
  // standard shop-page filter controls (#productSearch, #sortSelect, etc.).
  $(function () {
    var $grid = $('#productGrid');
    if (!$grid.length) return;

    var filterState = { category: 'all', price: 'all', query: '', sort: 'featured' };

    var $resultCount   = $('#resultCount');
    var $searchInput   = $('#productSearch');
    var $sortSelect    = $('#sortSelect');
    var $chips         = $('#chipCategoryFilter .chip');
    var $sidebarCats   = $('#sidebarCategoryFilter button');
    var $sidebarPrices = $('#sidebarPriceFilter button');

    function parsePriceRange(val) {
      if (val === 'all') return null;
      var parts = val.split('-');
      return { min: +parts[0], max: +parts[1] };
    }

    function applyFilters() {
      // Re-query live so shortcode-injected cards are always found
      var cards  = $grid.find('.product-card').toArray();
      var range  = parsePriceRange(filterState.price);
      var q      = filterState.query.trim().toLowerCase();

      var visible = cards.filter(function (card) {
        var cat   = card.getAttribute('data-category') || '';
        var name  = (card.getAttribute('data-name')    || '').toLowerCase();
        var price = +(card.getAttribute('data-price')  || 0);
        return (filterState.category === 'all' || cat === filterState.category)
            && (!q     || name.indexOf(q) !== -1)
            && (!range || (price >= range.min && price <= range.max));
      });

      $(cards).css('display', 'none');
      $(visible).css('display', '');

      var sorted = visible.slice();
      if (filterState.sort === 'price-asc')  sorted.sort(function (a, b) { return +a.getAttribute('data-price') - +b.getAttribute('data-price'); });
      if (filterState.sort === 'price-desc') sorted.sort(function (a, b) { return +b.getAttribute('data-price') - +a.getAttribute('data-price'); });
      if (filterState.sort === 'name-asc')   sorted.sort(function (a, b) { return (a.getAttribute('data-name') || '').localeCompare(b.getAttribute('data-name') || ''); });

      $.each(sorted, function (_, card) { $grid.append(card); });

      if ($resultCount.length) {
        $resultCount.text('Showing ' + visible.length + ' product' + (visible.length === 1 ? '' : 's'));
      }
    }

    function syncActive($btns, testFn) {
      $btns.each(function () { $(this).toggleClass('is-active', testFn(this)); });
    }

    // Category chips (top bar)
    $chips.on('click', function () {
      filterState.category = $(this).data('filter') || 'all';
      syncActive($chips,       function (b) { return b === this; }.bind(this));
      syncActive($sidebarCats, function (b) { return ($(b).data('filter') || '') === filterState.category; });
      applyFilters();
    });

    // Sidebar category buttons
    $sidebarCats.on('click', function () {
      filterState.category = $(this).data('filter') || 'all';
      syncActive($sidebarCats, function (b) { return b === this; }.bind(this));
      syncActive($chips,       function (b) { return ($(b).data('filter') || '') === filterState.category; });
      applyFilters();
    });

    // Sidebar price buttons
    $sidebarPrices.on('click', function () {
      filterState.price = $(this).data('price') || 'all';
      syncActive($sidebarPrices, function (b) { return b === this; }.bind(this));
      applyFilters();
    });

    // Search
    $searchInput.on('input', function () {
      filterState.query = this.value || '';
      applyFilters();
    });

    // Sort
    $sortSelect.on('change', function () {
      filterState.sort = this.value || 'featured';
      applyFilters();
    });

    applyFilters();
  });

})(jQuery);
