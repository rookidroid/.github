/* global rdShop, jQuery */
(function ($) {
  'use strict';

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

  // ── Shop-page grid: filter / sort / search / AJAX ───────────────────────────
  // Activates when [rookidroid_shop_page] or [rookidroid_shop_grid] is on the page.
  // Uses server-side AJAX filtering when rdShop.filterNonce is available,
  // otherwise falls back to client-side DOM filtering (static HTML preview).
  $(function () {
    var $grid = $('#productGrid');
    if (!$grid.length) return;

    var limit   = parseInt($grid.data('limit')   || 12, 10);
    var columns = parseInt($grid.data('columns') || 3,  10);
    var useAjax = typeof rdShop !== 'undefined' && !!rdShop.filterNonce;

    var filterState = { category: 'all', price: 'all', query: '', sort: 'featured', page: 1 };

    var $resultCount   = $('#resultCount');
    var $searchInput   = $('#productSearch');
    var $sortSelect    = $('#sortSelect');
    var $chips         = $('#chipCategoryFilter .chip');
    var $sidebarCats   = $('#sidebarCategoryFilter button');
    var $sidebarPrices = $('#sidebarPriceFilter button');
    var $pagination    = $('#shopPagination');
    var searchTimer;

    // ── Helpers ──────────────────────────────────────────────────────────────

    function parsePriceRange(val) {
      if (val === 'all') return null;
      var parts = val.split('-');
      return { min: +parts[0], max: +parts[1] };
    }

    function syncActive($btns, testFn) {
      $btns.each(function () { $(this).toggleClass('is-active', testFn(this)); });
    }

    function scrollToGrid() {
      if (!$grid.length) return;
      var top = $grid.offset().top - 110;
      if (top < $(window).scrollTop()) {
        $('html, body').animate({ scrollTop: top }, 280);
      }
    }

    // ── Client-side filter (fallback for static HTML preview) ────────────────

    function applyFiltersLocal() {
      var cards  = $grid.find('.product-card').toArray();
      var range  = parsePriceRange(filterState.price);
      var q      = filterState.query.trim().toLowerCase();

      var visible = cards.filter(function (card) {
        var cat   = card.getAttribute('data-category') || '';
        var name  = (card.getAttribute('data-name')   || '').toLowerCase();
        var price = +(card.getAttribute('data-price') || 0);
        return (filterState.category === 'all' || cat === filterState.category)
            && (!q     || name.indexOf(q) !== -1)
            && (!range || (price >= range.min && price <= range.max));
      });

      $(cards).css('display', 'none');
      $(visible).css('display', '');

      var sorted = visible.slice();
      if (filterState.sort === 'price-asc') {
        sorted.sort(function (a, b) { return +a.getAttribute('data-price') - +b.getAttribute('data-price'); });
      } else if (filterState.sort === 'price-desc') {
        sorted.sort(function (a, b) { return +b.getAttribute('data-price') - +a.getAttribute('data-price'); });
      } else if (filterState.sort === 'name-asc') {
        sorted.sort(function (a, b) {
          return (a.getAttribute('data-name') || '').localeCompare(b.getAttribute('data-name') || '');
        });
      }
      $.each(sorted, function (_, card) { $grid.append(card); });

      if ($resultCount.length) {
        $resultCount.text('Showing ' + visible.length + ' product' + (visible.length === 1 ? '' : 's'));
      }
      $pagination.html('');
    }

    // ── AJAX filter (WordPress context) ─────────────────────────────────────

    function applyFiltersAjax() {
      $grid.addClass('rd-grid--loading');

      $.ajax({
        url:    rdShop.ajaxUrl,
        method: 'POST',
        data: {
          action:      'rd_filter_products',
          nonce:       rdShop.filterNonce,
          category:    filterState.category === 'all' ? '' : filterState.category,
          search:      filterState.query,
          sort:        filterState.sort,
          price_range: filterState.price,
          page:        filterState.page,
          limit:       limit,
          columns:     columns,
        },
        success: function (resp) {
          $grid.removeClass('rd-grid--loading');
          if (resp.success) {
            $grid.html(resp.data.html);
            if ($resultCount.length) {
              var t = resp.data.total;
              $resultCount.text('Showing ' + t + ' product' + (t === 1 ? '' : 's'));
            }
            renderPagination(resp.data.page, resp.data.total_pages);
            scrollToGrid();
          }
        },
        error: function () {
          $grid.removeClass('rd-grid--loading');
        },
      });
    }

    // ── Pagination (AJAX mode) ────────────────────────────────────────────────

    function renderPagination(current, total) {
      if (!$pagination.length || total <= 1) { $pagination.html(''); return; }
      var html   = '';
      var start  = Math.max(1, current - 2);
      var end    = Math.min(total, current + 2);

      if (current > 1) {
        html += '<button class="rd-page-btn" data-page="' + (current - 1) + '" aria-label="Previous">&#8249;</button>';
      }
      if (start > 1) html += '<button class="rd-page-btn" data-page="1">1</button>';
      if (start > 2) html += '<span class="rd-page-ellipsis">&#8230;</span>';
      for (var i = start; i <= end; i++) {
        html += '<button class="rd-page-btn' + (i === current ? ' is-active' : '') + '" data-page="' + i + '">' + i + '</button>';
      }
      if (end < total - 1) html += '<span class="rd-page-ellipsis">&#8230;</span>';
      if (end < total)     html += '<button class="rd-page-btn" data-page="' + total + '">' + total + '</button>';
      if (current < total) {
        html += '<button class="rd-page-btn" data-page="' + (current + 1) + '" aria-label="Next">&#8250;</button>';
      }
      $pagination.html(html);

      $pagination.find('[data-page]').on('click', function () {
        filterState.page = parseInt($(this).data('page'), 10);
        applyFiltersAjax();
      });
    }

    // ── Dispatcher ───────────────────────────────────────────────────────────

    function applyFilters() {
      filterState.page = 1;
      if (useAjax) { applyFiltersAjax(); } else { applyFiltersLocal(); }
    }

    // ── Event bindings ────────────────────────────────────────────────────────

    $chips.on('click', function () {
      filterState.category = $(this).data('filter') || 'all';
      syncActive($chips,       function (b) { return b === this; }.bind(this));
      syncActive($sidebarCats, function (b) { return ($(b).data('filter') || '') === filterState.category; });
      applyFilters();
    });

    $sidebarCats.on('click', function () {
      filterState.category = $(this).data('filter') || 'all';
      syncActive($sidebarCats, function (b) { return b === this; }.bind(this));
      syncActive($chips,       function (b) { return ($(b).data('filter') || '') === filterState.category; });
      applyFilters();
    });

    $sidebarPrices.on('click', function () {
      filterState.price = $(this).data('price') || 'all';
      syncActive($sidebarPrices, function (b) { return b === this; }.bind(this));
      applyFilters();
    });

    $searchInput.on('input', function () {
      filterState.query = this.value || '';
      clearTimeout(searchTimer);
      searchTimer = setTimeout(applyFilters, 350);
    });

    $sortSelect.on('change', function () {
      filterState.sort = this.value || 'featured';
      applyFilters();
    });

    // Initial render
    if (!useAjax) {
      applyFiltersLocal();
    } else {
      // Grid already server-rendered; just show initial count
      if ($resultCount.length) {
        var initialCount = $grid.find('.product-card').length;
        $resultCount.text('Showing ' + initialCount + ' product' + (initialCount === 1 ? '' : 's'));
      }
    }
  });

})(jQuery);
