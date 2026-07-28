/*
 * shop.js — the storefront JS-enhancement layer (storefront-rendering spec §5.2/§10).
 * Dependency-free, no build step. Progressively enhances the real `/_shop/*` PRG forms
 * (cart add/update/remove/discount, checkout quote/place) with content-negotiated JSON
 * requests, live mini-cart/quote updates, focus + aria-live status announcements, and
 * double-submit suppression. Also hydrates the read-only product-grid/featured-product/
 * add-to-cart/mini-cart block shells (see templates/blocks/*.twig) from the matching
 * `/_shop/blocks/*` and `/_shop/cart` JSON endpoints, so a block placed on ANY page shows
 * live catalog/cart data without the page's own render pipeline knowing about commerce.
 *
 * It also owns the device-local wishlist (storefront-v1 spec §5): ONE localStorage-backed
 * store per shop scope, consumed by card hearts, the product-page heart, the wishlist page,
 * and the `wishlist-link` badge, broadcast as `thallo:wishlist-changed`.
 *
 * On runtime pages (window.ThalloRuntime present) the theme-runtime core drives all of this
 * through nine registered `shop-*` modules — shop.js attaches no load hook of its own there.
 * On runtime-absent pages (a copied pre-runtime layout) shop.js self-drives via its own
 * init() exactly as before adoption.
 *
 * Hard rule (spec §10): PRG is the no-JS path, not an AJAX retry strategy. Once fetch() has
 * been called, a rejection is AMBIGUOUS (the server may or may not have received the
 * request) — this file never issues a second automatic POST and never falls back to a
 * native form submission after that point. Native submission is only ever left to happen
 * when interception itself fails BEFORE any request is sent (fetch/FormData unavailable).
 * The only second POST is an EXPLICIT user retry, which resubmits the exact same form (so
 * a checkout attempt's idempotency key, sitting in a hidden field, is naturally preserved).
 */
(function () {
  'use strict';

  if (typeof document === 'undefined') {
    return;
  }

  /* shop-runtime:start */
  if (window.thalloShop) {
    return; // every shop block template emits its own <script> tag; run once
  }

  var STATUS_ID = 'thallo-shop-status';

  var FORM_SELECTOR = [
    'form[action="/_shop/cart/add"]',
    'form[action="/_shop/cart/update"]',
    'form[action="/_shop/cart/remove"]',
    'form[action="/_shop/cart/discount"]',
    'form[action="/_shop/checkout/quote"]',
    'form[action="/_shop/checkout/place"]',
  ].join(', ');

  // ---- tiny DOM helpers ---------------------------------------------------

  function qs(root, selector) {
    return root && typeof root.querySelector === 'function' ? root.querySelector(selector) : null;
  }

  function qsa(root, selector) {
    if (!root || typeof root.querySelectorAll !== 'function') {
      return [];
    }
    return Array.prototype.slice.call(root.querySelectorAll(selector));
  }

  function clear(el) {
    while (el.firstChild) {
      el.removeChild(el.firstChild);
    }
  }

  // ---- focus + aria-live status region ------------------------------------

  function ensureStatusRegion() {
    var el = document.getElementById(STATUS_ID);
    if (el) {
      return el;
    }
    el = document.createElement('div');
    el.id = STATUS_ID;
    el.className = 'thallo-shop-status sr-only';
    el.setAttribute('role', 'status');
    el.setAttribute('aria-live', 'polite');
    el.setAttribute('tabindex', '-1');
    document.body.appendChild(el);
    return el;
  }

  /** Moves both the aria-live announcement AND keyboard focus to the shared status region. */
  function announce(message) {
    var el = ensureStatusRegion();
    el.textContent = message;
    if (typeof el.focus === 'function') {
      el.focus();
    }
  }

  // ---- pending / double-submit suppression --------------------------------

  function setPending(form, pending) {
    if (pending) {
      form.setAttribute('data-shop-pending', '1');
    } else {
      form.removeAttribute('data-shop-pending');
    }
    var controls = qsa(form, 'button, input[type="submit"]');
    for (var i = 0; i < controls.length; i++) {
      controls[i].disabled = pending;
    }
  }

  function isPending(form) {
    return form.getAttribute('data-shop-pending') === '1';
  }

  // ---- form interception ---------------------------------------------------

  function bindForm(form) {
    if (form.getAttribute('data-shop-bound') === '1') {
      return;
    }
    form.setAttribute('data-shop-bound', '1');
    form.addEventListener('submit', function (evt) {
      onSubmit(evt, form);
    });
  }

  function onSubmit(evt, form) {
    // Interception failed BEFORE any request was sent — let the browser submit natively
    // (the no-JS PRG path). This is the ONLY situation that ever produces a native submit.
    if (typeof window.fetch !== 'function' || typeof window.FormData !== 'function') {
      return;
    }
    evt.preventDefault();
    if (isPending(form)) {
      return; // a submit is already in flight — suppressed, not queued.
    }
    submit(form);
  }

  function submit(form) {
    setPending(form, true);
    clearRetry(form);

    var body = new window.FormData(form);
    var headers = { Accept: 'application/json' };
    var keyField = qs(form, 'input[name="idempotency_key"]');
    if (keyField && keyField.value) {
      headers['X-Idempotency-Key'] = keyField.value;
    }

    window
      .fetch(form.getAttribute('action'), {
        method: 'POST',
        body: body,
        headers: headers,
        credentials: 'same-origin',
      })
      .then(function (res) {
        return res
          .json()
          .catch(function () {
            return null;
          })
          .then(function (data) {
            return { ok: res.ok, status: res.status, data: data };
          });
      })
      .then(function (result) {
        setPending(form, false);
        if (result.ok) {
          onSuccess(form, result.data);
        } else {
          onValidationError(result.data);
        }
      })
      .catch(function () {
        // The fetch itself rejected (network failure/abort) AFTER the request may already
        // have reached the server — ambiguous. Never retried automatically, never a native
        // fallback: only an explicit user retry (see showRetry()) issues a second POST.
        setPending(form, false);
        onAmbiguousFailure(form);
      });
  }

  // ---- response handling ----------------------------------------------------

  function onSuccess(form, data) {
    if (data && typeof data === 'object') {
      if (Object.prototype.hasOwnProperty.call(data, 'item_count')) {
        updateCartRegions(data);
        announce('Cart updated: ' + data.item_count + ' item' + (data.item_count === 1 ? '' : 's') + '.');
        return;
      }
      if (Object.prototype.hasOwnProperty.call(data, 'totals') && data.totals) {
        updateQuoteRegions(data);
        announce('Totals updated.');
        return;
      }
      if (Object.prototype.hasOwnProperty.call(data, 'action')) {
        handleCheckoutResult(form, data);
        return;
      }
    }
    announce('Done.');
  }

  function onValidationError(data) {
    var message = 'Something went wrong. Please check the form and try again.';
    if (data && data.errors && typeof data.errors === 'object') {
      var messages = [];
      for (var key in data.errors) {
        if (Object.prototype.hasOwnProperty.call(data.errors, key)) {
          messages = messages.concat(data.errors[key]);
        }
      }
      if (messages.length > 0) {
        message = messages.join(' ');
      }
    }
    announce(message);
  }

  function onAmbiguousFailure(form) {
    announce('We could not confirm your request went through. Retry to try again.');
    showRetry(form);
  }

  // ---- explicit retry (the ONLY allowed second POST after an ambiguous failure) ---------

  function retryElementFor(form) {
    return qs(form.parentNode, '[data-shop-retry]');
  }

  function showRetry(form) {
    var retry = retryElementFor(form);
    if (!retry && form.parentNode && typeof form.parentNode.insertBefore === 'function') {
      retry = document.createElement('button');
      retry.type = 'button';
      retry.setAttribute('data-shop-retry', '1');
      retry.textContent = 'Retry';
      form.parentNode.insertBefore(retry, form.nextSibling);
    }
    if (!retry) {
      return;
    }
    retry.hidden = false;
    retry.onclick = function () {
      // An explicit user action — resubmits the SAME form/fields untouched, so a checkout
      // idempotency key sitting in a hidden input is preserved automatically.
      submit(form);
    };
  }

  function clearRetry(form) {
    var retry = retryElementFor(form);
    if (retry) {
      retry.hidden = true;
    }
  }

  // ---- mini-cart / cart region updates (shared by mutation responses + GET /_shop/cart) --

  function updateCartRegions(cart) {
    var counts = qsa(document, '[data-shop-cart-count]');
    for (var i = 0; i < counts.length; i++) {
      counts[i].textContent = String(cart.item_count);
      // The badge only shows with items in the cart: the shell ships it hidden (the
      // cacheable markup is always zero), this paint reveals it — an empty cart never
      // renders a noisy "0" badge, before OR after hydration.
      counts[i].hidden = !(cart.item_count > 0);
    }

    var drawers = qsa(document, '[data-shop-cart-drawer]');
    for (var d = 0; d < drawers.length; d++) {
      renderDrawer(drawers[d], cart);
    }
  }

  function renderDrawer(drawer, cart) {
    var empty = qs(drawer, '[data-shop-cart-empty]');
    var lines = qs(drawer, '[data-shop-cart-lines]');
    var total = qs(drawer, '[data-shop-cart-total]');
    var link = qs(drawer, '[data-shop-cart-link]');

    var items = cart.items || [];
    if (items.length === 0) {
      if (empty) {
        empty.hidden = false;
      }
      if (lines) {
        lines.hidden = true;
        clear(lines);
      }
      if (total) {
        total.hidden = true;
      }
      return;
    }

    if (empty) {
      empty.hidden = true;
    }
    if (lines) {
      lines.hidden = false;
      clear(lines);
      for (var i = 0; i < items.length; i++) {
        lines.appendChild(buildLineElement(items[i]));
      }
    }
    if (total) {
      total.hidden = false;
      total.textContent = cart.grand_total_formatted + ' ' + cart.currency;
    }
    if (link && cart.cart_url) {
      link.setAttribute('href', cart.cart_url);
    }
  }

  function buildLineElement(item) {
    var li = document.createElement('li');
    li.className = 'thallo-block-mini-cart__line';
    li.setAttribute('data-variant-uuid', item.variant_uuid);

    var name = document.createElement('span');
    name.className = 'thallo-block-mini-cart__line-name';
    name.textContent = item.product_name + ' × ' + item.quantity;

    var total = document.createElement('span');
    total.className = 'thallo-block-mini-cart__line-total';
    total.setAttribute('data-line-total', '1');
    total.textContent = item.line_total_formatted + ' ' + item.currency;

    li.appendChild(name);
    li.appendChild(total);
    return li;
  }

  // ---- checkout quote regions -------------------------------------------------

  function updateQuoteRegions(data) {
    var totals = data.totals || {};
    setRegionText('[data-shop-quote-subtotal]', totals.subtotal);
    setRegionText('[data-shop-quote-shipping]', totals.shipping_total);
    setRegionText('[data-shop-quote-tax]', totals.tax_total);
    setRegionText('[data-shop-quote-total]', totals.grand_total);
  }

  function setRegionText(selector, value) {
    if (value === undefined || value === null) {
      return;
    }
    var els = qsa(document, selector);
    for (var i = 0; i < els.length; i++) {
      els[i].textContent = String(value);
    }
  }

  // ---- checkout placement result ------------------------------------------------

  function handleCheckoutResult(form, data) {
    if (data.action === 'redirect' && data.redirect_url) {
      // Payment redirects always navigate top-level (spec §10) — never fetched/embedded.
      window.location.href = data.redirect_url;
      return;
    }

    var message = checkoutMessage(data);
    announce(message);

    var result = qs(form.parentNode, '[data-shop-checkout-result]');
    if (result) {
      result.hidden = false;
      result.textContent = message;
    }
  }

  function checkoutMessage(data) {
    switch (data.action) {
      case 'manual':
        return 'Order placed. Follow the payment instructions shown.';
      case 'reference':
        return 'Order placed. Payment is pending confirmation.';
      case 'unavailable':
        return 'Order placed, but payment could not be started. We will follow up by email.';
      default:
        return 'Order placed.';
    }
  }

  // ---- block hydration: mini-cart ------------------------------------------------
  // Cart regions are DOCUMENT-WIDE (header count badges live outside the shells), so
  // the fetch AND the paint are shared: the first shell to enhance starts them, every
  // concurrent shell awaits the same in-flight promise, and the slot clears on settle
  // so a later enhance of a freshly inserted shell fetches fresh state
  // (shopjs-on-runtime spec §2.2).
  var cartFetchInFlight = null;

  function hydrateMiniCart() {
    if (typeof window.fetch !== 'function') {
      return;
    }
    if (cartFetchInFlight) {
      return;
    }
    cartFetchInFlight = window
      .fetch('/_shop/cart', { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
      .then(function (res) {
        return res.json();
      })
      .then(function (data) {
        updateCartRegions(data);
      })
      .catch(function () {
        // Hydration is enhancement only — a failed cart read leaves the safe static
        // (empty/no-JS) shell as-is (same posture as every other hydrate).
      })
      .then(function () {
        cartFetchInFlight = null;
      });
  }

  // The drawer disclosure (mini-cart-in-the-chrome, 2026-07-27): the toggle ships
  // aria-expanded="false" and shop.css hides the panel until it flips — this binding is the
  // only thing that flips it. Per-toggle inner marker (same idempotency layer as bindForm's
  // guard) keeps re-enhancement from stacking listeners; the outside-click closer is bound
  // once per page and never closes a drawer it cannot positively place outside its shell.
  var outsideCartCloseBound = false;

  function bindMiniCartShell(el) {
    var toggle = qs(el, '[data-shop-cart-toggle]');
    if (!toggle || toggle.getAttribute('data-shop-cart-toggle-bound') === '1') {
      return;
    }
    toggle.setAttribute('data-shop-cart-toggle-bound', '1');

    toggle.addEventListener('click', function () {
      var open = toggle.getAttribute('aria-expanded') === 'true';
      toggle.setAttribute('aria-expanded', open ? 'false' : 'true');
    });

    el.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && toggle.getAttribute('aria-expanded') === 'true') {
        toggle.setAttribute('aria-expanded', 'false');
        if (typeof toggle.focus === 'function') {
          toggle.focus();
        }
      }
    });

    if (!outsideCartCloseBound) {
      outsideCartCloseBound = true;
      document.addEventListener('click', function (event) {
        var open = qsa(document, '[data-shop-cart-toggle][aria-expanded="true"]');
        for (var i = 0; i < open.length; i++) {
          var shell = typeof open[i].closest === 'function' ? open[i].closest('[data-shop-mini-cart]') : null;
          if (!shell || typeof shell.contains !== 'function' || shell.contains(event.target)) {
            continue;
          }
          open[i].setAttribute('aria-expanded', 'false');
        }
      });
    }
  }

  function hydrateMiniCarts() {
    var shells = qsa(document, '[data-shop-mini-cart]');
    if (shells.length === 0) {
      return;
    }
    for (var i = 0; i < shells.length; i++) {
      bindMiniCartShell(shells[i]);
    }
    hydrateMiniCart();
  }

  // ---- block hydration: product-grid ------------------------------------------------

  function hydrateProductGrids() {
    var blocks = qsa(document, '[data-shop-block="product-grid"]');
    for (var i = 0; i < blocks.length; i++) {
      hydrateProductGrid(blocks[i]);
    }
  }

  function hydrateProductGrid(el) {
    if (typeof window.fetch !== 'function') {
      return;
    }
    var query =
      'source=' + encodeURIComponent(el.getAttribute('data-source') || 'newest') +
      '&category_slug=' + encodeURIComponent(el.getAttribute('data-category-slug') || '') +
      '&tag_slug=' + encodeURIComponent(el.getAttribute('data-tag-slug') || '') +
      '&products=' + encodeURIComponent(el.getAttribute('data-products') || '') +
      '&page_size=' + encodeURIComponent(el.getAttribute('data-page-size') || '24');

    window
      .fetch('/_shop/blocks/product-grid?' + query, {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
      })
      .then(function (res) {
        return res.json();
      })
      .then(function (data) {
        renderProductGrid(el, data);
      })
      .catch(function () {
        // Leave the loading shell as-is.
      });
  }

  function renderProductGrid(el, data) {
    var itemsEl = qs(el, '[data-shop-grid-items]');
    var emptyEl = qs(el, '[data-shop-grid-empty]');
    var viewAll = qs(el, '[data-shop-grid-view-all]');
    var items = (data && data.items) || [];

    if (items.length === 0) {
      if (emptyEl) {
        emptyEl.hidden = false;
        emptyEl.textContent = 'No products found.';
      }
      if (itemsEl) {
        itemsEl.hidden = true;
        clear(itemsEl);
      }
    } else {
      if (emptyEl) {
        emptyEl.hidden = true;
      }
      if (itemsEl) {
        itemsEl.hidden = false;
        clear(itemsEl);
        for (var i = 0; i < items.length; i++) {
          itemsEl.appendChild(buildProductCard(items[i]));
        }
        enhanceBuiltCards(itemsEl);
      }
    }

    // "view all" ALWAYS points at the canonical shop/category route the JSON supplied
    // (built server-side via ShopUrlGenerator) — never a query-paginated builder-page link.
    if (viewAll && data && data.view_all_url) {
      viewAll.hidden = false;
      viewAll.setAttribute('href', data.view_all_url);
    }
  }

  // ---- the ONE client card renderer ------------------------------------------------
  // storefront-v1 spec §5: `_product_card.twig` stays the SERVER card renderer, and this is
  // its client twin — both consume the same closed ProductCardViewModel projection
  // ({uuid, name, url, cover_url, rating, price_formatted, compare_at_formatted,
  // category_name, cart_mode, direct_variant_uuid}), and ShopBlocksTest pins their shared
  // class/data/ARIA hook set so a redesign of either cannot silently drift. Consumed by BOTH
  // the hydrated product-grid block and the wishlist page.
  //
  // Cart honesty is the SERVER's decision, never re-derived here: `cart_mode: 'direct'` (plus a
  // variant uuid) renders the real PRG form the shop-form module already intercepts; every
  // other mode renders an options link to the detail page.

  var SVG_NS = 'http://www.w3.org/2000/svg';
  var ICON_CART = 'M7 18c-1.1 0-1.99.9-1.99 2S5.9 22 7 22s2-.9 2-2-.9-2-2-2zM1 2v2h2l3.6 7.59-1.35 '
    + '2.45c-.16.28-.25.61-.25.96 0 1.1.9 2 2 2h12v-2H7.42c-.14 0-.25-.11-.25-.25l.03-.12.9-1.63h7.45c.75 '
    + '0 1.41-.41 1.75-1.03l3.58-6.49c.08-.14.12-.31.12-.48 0-.55-.45-1-1-1H5.21l-.94-2H1zm16 16c-1.1 '
    + '0-1.99.9-1.99 2s.89 2 1.99 2 2-.9 2-2-.9-2-2-2z';
  var ICON_OPTIONS = 'M12 4l-1.41 1.41L16.17 11H4v2h12.17l-5.58 5.59L12 20l8-8z';
  var ICON_HEART = 'M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 '
    + '4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z';
  var ICON_STAR = 'M12 2l2.9 6.26 6.85.63-5.17 4.6 1.53 6.72L12 16.7l-6.11 3.51 1.53-6.72-5.17-4.6 6.85-.63z';

  /** An inline currentColor icon — omitted entirely where createElementNS is unavailable. */
  function cardIcon(path, className) {
    if (typeof document.createElementNS !== 'function') {
      return null;
    }
    var svg = document.createElementNS(SVG_NS, 'svg');
    if (className) {
      svg.setAttribute('class', className);
    }
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('fill', 'currentColor');
    svg.setAttribute('aria-hidden', 'true');
    svg.setAttribute('focusable', 'false');
    var shape = document.createElementNS(SVG_NS, 'path');
    shape.setAttribute('d', path);
    svg.appendChild(shape);
    return svg;
  }

  function appendIcon(parent, path, className) {
    var icon = cardIcon(path, className);
    if (icon) {
      parent.appendChild(icon);
    }
  }

  function buildProductCard(product) {
    var li = document.createElement('li');
    li.className = 'shop-grid__item';

    var tile = document.createElement('div');
    tile.className = 'shop-grid__tile';
    tile.appendChild(buildCardMedia(product));
    if (product.category_name) {
      var tag = document.createElement('span');
      tag.className = 'shop-grid__tag';
      tag.textContent = product.category_name;
      tile.appendChild(tag);
    }
    tile.appendChild(buildCardActions(product));
    li.appendChild(tile);

    li.appendChild(buildCardBody(product));
    return li;
  }

  function buildCardMedia(product) {
    var media = document.createElement('span');
    media.className = 'shop-grid__media';
    if (product.cover_url) {
      var img = document.createElement('img');
      img.className = 'shop-grid__image';
      img.setAttribute('src', product.cover_url);
      img.setAttribute('alt', product.name || '');
      img.setAttribute('loading', 'lazy');
      img.setAttribute('decoding', 'async');
      media.appendChild(img);
    } else {
      var placeholder = document.createElement('span');
      placeholder.className = 'shop-grid__image shop-grid__image--empty';
      placeholder.setAttribute('aria-hidden', 'true');
      media.appendChild(placeholder);
    }
    return media;
  }

  function buildCardActions(product) {
    var actions = document.createElement('div');
    actions.className = 'shop-grid__actions';

    if (product.cart_mode === 'direct' && product.direct_variant_uuid) {
      var form = document.createElement('form');
      form.className = 'shop-grid__cart-form';
      form.setAttribute('method', 'post');
      form.setAttribute('action', '/_shop/cart/add');
      form.appendChild(hiddenField('variant_uuid', product.direct_variant_uuid));
      form.appendChild(hiddenField('quantity', '1'));
      var submit = document.createElement('button');
      submit.className = 'shop-grid__action shop-grid__action--cart';
      submit.setAttribute('type', 'submit');
      submit.setAttribute('aria-label', 'Add ' + (product.name || 'product') + ' to cart');
      appendIcon(submit, ICON_CART);
      form.appendChild(submit);
      actions.appendChild(form);
    } else {
      var options = document.createElement('a');
      options.className = 'shop-grid__action shop-grid__action--options';
      options.setAttribute('href', product.url);
      options.setAttribute('aria-label', 'View options for ' + (product.name || 'product'));
      appendIcon(options, ICON_OPTIONS);
      actions.appendChild(options);
    }

    // The heart ships hidden exactly like the server card's: the wishlist modules reveal it
    // only behind a ready store (an unavailable storage backend leaves no inert control).
    var heart = document.createElement('button');
    heart.className = 'shop-grid__action shop-grid__action--wishlist';
    heart.setAttribute('type', 'button');
    heart.setAttribute('data-shop-wishlist-toggle', '');
    heart.setAttribute('data-product-uuid', product.uuid);
    heart.setAttribute('aria-pressed', 'false');
    heart.setAttribute('aria-label', 'Save ' + (product.name || 'product') + ' to wishlist');
    heart.hidden = true;
    appendIcon(heart, ICON_HEART);
    actions.appendChild(heart);

    return actions;
  }

  function buildCardBody(product) {
    var body = document.createElement('span');
    body.className = 'shop-grid__body';

    var name = document.createElement('a');
    name.className = 'shop-grid__name';
    name.setAttribute('href', product.url);
    name.textContent = product.name;
    body.appendChild(name);

    if (product.rating && typeof product.rating.average === 'number') {
      var rating = document.createElement('span');
      rating.className = 'shop-grid__rating';
      appendIcon(rating, ICON_STAR, 'shop-grid__star');
      var label = document.createElement('span');
      label.className = 'sr-only';
      label.textContent = 'Rated ';
      rating.appendChild(label);
      var average = document.createElement('span');
      average.textContent = product.rating.average.toFixed(1);
      rating.appendChild(average);
      var reviews = document.createElement('span');
      reviews.className = 'shop-grid__rating-count';
      reviews.textContent = '(' + product.rating.count + ')';
      rating.appendChild(reviews);
      body.appendChild(rating);
    }

    if (product.price_formatted) {
      var price = document.createElement('span');
      price.className = 'shop-grid__price';
      var current = document.createElement('span');
      current.className = 'shop-grid__price-current';
      current.textContent = product.price_formatted;
      price.appendChild(current);
      if (product.compare_at_formatted) {
        var was = document.createElement('s');
        was.textContent = product.compare_at_formatted;
        price.appendChild(was);
      }
      body.appendChild(price);
    }

    return body;
  }

  function hiddenField(name, value) {
    var input = document.createElement('input');
    input.setAttribute('type', 'hidden');
    input.setAttribute('name', name);
    input.setAttribute('value', value);
    input.value = String(value);
    return input;
  }

  /**
   * Freshly built cards carry REAL controls: a direct-add PRG form the shop-form module must
   * intercept, and a wishlist heart the store must drive. Both binders are idempotent (their
   * own inner markers), so a later core enhance() pass over the same nodes is a no-op.
   */
  function enhanceBuiltCards(container) {
    var forms = qsa(container, FORM_SELECTOR);
    for (var i = 0; i < forms.length; i++) {
      bindForm(forms[i]);
    }
    var toggles = qsa(container, '[data-shop-wishlist-toggle]');
    for (var t = 0; t < toggles.length; t++) {
      bindWishlistToggle(toggles[t]);
    }
  }

  // ---- block hydration: featured-product ------------------------------------------------

  function hydrateFeaturedProducts() {
    var blocks = qsa(document, '[data-shop-block="featured-product"]');
    for (var i = 0; i < blocks.length; i++) {
      hydrateFeaturedProduct(blocks[i]);
    }
  }

  function hydrateFeaturedProduct(el) {
    if (typeof window.fetch !== 'function') {
      return;
    }
    var query = blockContextQuery(el);

    window
      .fetch('/_shop/blocks/featured-product?' + query, {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
      })
      .then(function (res) {
        return res.json();
      })
      .then(function (data) {
        renderFeaturedProduct(el, data);
      })
      .catch(function () {
        // Leave the loading shell as-is.
      });
  }

  function renderFeaturedProduct(el, data) {
    var body = qs(el, '[data-shop-featured-body]');
    var empty = qs(el, '[data-shop-featured-empty]');
    var product = data && data.product;

    if (!product) {
      if (empty) {
        empty.hidden = false;
      }
      if (body) {
        body.hidden = true;
        clear(body);
      }
      return;
    }

    if (empty) {
      empty.hidden = true;
    }
    if (body) {
      body.hidden = false;
      clear(body);

      var a = document.createElement('a');
      a.setAttribute('href', product.url);
      var name = document.createElement('span');
      name.className = 'thallo-block-featured-product__name';
      name.textContent = product.name;
      a.appendChild(name);
      if (product.price_formatted) {
        var price = document.createElement('span');
        price.className = 'thallo-block-featured-product__price';
        price.textContent = product.price_formatted + ' ' + (product.currency || '');
        a.appendChild(price);
      }
      body.appendChild(a);
    }
  }

  // ---- block hydration: add-to-cart ------------------------------------------------

  function hydrateAddToCarts() {
    var blocks = qsa(document, '[data-shop-block="add-to-cart"]');
    for (var i = 0; i < blocks.length; i++) {
      hydrateAddToCart(blocks[i]);
    }
  }

  function hydrateAddToCart(el) {
    if (typeof window.fetch !== 'function') {
      return;
    }
    var query = blockContextQuery(el);

    window
      .fetch('/_shop/blocks/add-to-cart?' + query, {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
      })
      .then(function (res) {
        return res.json();
      })
      .then(function (data) {
        renderAddToCart(el, data);
      })
      .catch(function () {
        // Leave the loading shell as-is.
      });
  }

  function renderAddToCart(el, data) {
    var status = qs(el, '[data-shop-add-to-cart-status]');
    var form = qs(el, '[data-shop-add-to-cart-form]');
    var link = qs(el, '[data-shop-add-to-cart-link]');
    var slot = qs(el, '[data-shop-add-to-cart-variant-slot]');

    if (!data || !data.available) {
      if (status) {
        status.hidden = false;
        status.textContent = 'This product is not available.';
      }
      if (form) {
        form.hidden = true;
      }
      if (link) {
        link.hidden = true;
      }
      return;
    }

    if (data.mode === 'link') {
      if (status) {
        status.hidden = true;
      }
      if (form) {
        form.hidden = true;
      }
      if (link && data.product_url) {
        link.hidden = false;
        link.setAttribute('href', data.product_url);
        link.textContent = 'View ' + (data.product_name || 'product');
      }
      return;
    }

    if (status) {
      status.hidden = true;
    }
    if (link) {
      link.hidden = true;
    }
    if (slot) {
      clear(slot);
      if (data.mode === 'direct') {
        var hiddenInput = document.createElement('input');
        hiddenInput.type = 'hidden';
        hiddenInput.name = 'variant_uuid';
        hiddenInput.value = data.variant_uuid;
        slot.appendChild(hiddenInput);
      } else if (data.mode === 'select') {
        var select = document.createElement('select');
        select.name = 'variant_uuid';
        var variants = data.variants || [];
        for (var i = 0; i < variants.length; i++) {
          var option = document.createElement('option');
          option.value = variants[i].uuid;
          option.textContent = variants[i].label + ' — ' + variants[i].price_formatted;
          select.appendChild(option);
        }
        slot.appendChild(select);
      }
    }
    if (form) {
      form.hidden = false;
      // A freshly-hydrated form is unbound until init()'s bindForm() pass reaches it, but
      // that pass only runs once at load — bind here too (bindForm() is idempotent) so a
      // hydration that completes after the initial querySelectorAll() sweep is still wired.
      bindForm(form);
    }
  }

  function blockContextQuery(el) {
    return (
      'product_slug=' + encodeURIComponent(el.getAttribute('data-product-slug') || '') +
      '&entry_uuid=' + encodeURIComponent(el.getAttribute('data-entry-uuid') || '')
    );
  }

  // ---- product gallery: thumbnail -> main image swap ------------------------------
  // Pure enhancement (product-editor mock parity, 2026-07-24): the thumbnails are real
  // <button data-shop-thumb data-src> elements in the server markup; without JS they are
  // inert (every image is still visible in the thumb strip), with JS a click swaps the
  // main [data-shop-cover] image. No fetch, no state — nothing here can affect PRG.

  function bindGallery(gallery) {
    if (gallery.getAttribute('data-shop-gallery-bound') === '1') {
      return;
    }
    gallery.setAttribute('data-shop-gallery-bound', '1');
    gallery.addEventListener('click', function (evt) {
      var target = evt.target;
      var thumb = target && typeof target.closest === 'function' ? target.closest('[data-shop-thumb]') : null;
      if (!thumb) {
        return;
      }
      var cover = qs(gallery, '[data-shop-cover]');
      var src = thumb.getAttribute('data-src');
      if (!cover || !src) {
        return;
      }
      cover.src = src;
      cover.alt = thumb.getAttribute('data-alt') || cover.alt;
      var thumbs = qsa(gallery, '[data-shop-thumb]');
      for (var i = 0; i < thumbs.length; i++) {
        thumbs[i].setAttribute('aria-current', thumbs[i] === thumb ? 'true' : 'false');
      }
    });
  }

  // ---- product buy area: quantity stepper + exponent-aware price-in-button --------
  // Storefront-v1 Task 6: the product page's add-to-cart form ([data-shop-buy]) carries the
  // stepper ([data-shop-qty-minus]/[data-shop-qty-plus] around the REAL quantity input,
  // clamped 1–99) and the closed price projection — data-currency + data-currency-exponent
  // ONCE on the form (the exponent only ever comes from commerce Money::exponentFor()),
  // data-price-minor on the form in direct mode / on each <option> in select mode. The
  // submit button's [data-shop-buy-price] span ships the server-rendered unit price (the
  // no-JS truth); this module recomputes it as qty × minor with CHECKED integer math — a
  // malformed/absent attribute, an unknown currency, or a product past
  // Number.MAX_SAFE_INTEGER leaves the server-rendered label untouched. Pure DOM state, no
  // fetch — nothing here can affect PRG.

  var QTY_MIN = 1;
  var QTY_MAX = 99;

  function bindBuyArea(form) {
    if (form.getAttribute('data-shop-buy-bound') === '1') {
      return;
    }
    form.setAttribute('data-shop-buy-bound', '1');

    var qty = qs(form, 'input[name="quantity"]');
    if (!qty) {
      return;
    }
    var minus = qs(form, '[data-shop-qty-minus]');
    var plus = qs(form, '[data-shop-qty-plus]');
    var select = qs(form, 'select[name="variant_uuid"]');

    function clampedQty() {
      var value = parseInt(qty.value, 10);
      if (!Number.isSafeInteger(value) || value < QTY_MIN) {
        return QTY_MIN;
      }
      return value > QTY_MAX ? QTY_MAX : value;
    }

    // The minor unit price: the SELECTED option's data-price-minor when a variant select
    // exists, else the form's own (direct mode). Null when absent/unmatched.
    function priceMinorRaw() {
      if (select) {
        var options = qsa(select, 'option');
        for (var i = 0; i < options.length; i++) {
          if (options[i].value === select.value) {
            return options[i].getAttribute('data-price-minor');
          }
        }
        return null;
      }
      return form.getAttribute('data-price-minor');
    }

    function updateLabel() {
      var target = qs(form, '[data-shop-buy-price]');
      var currency = form.getAttribute('data-currency');
      var exponentRaw = form.getAttribute('data-currency-exponent');
      var minorRaw = priceMinorRaw();
      if (!target || !currency || exponentRaw === null || minorRaw === null) {
        return;
      }
      if (typeof Intl === 'undefined' || typeof Intl.NumberFormat !== 'function') {
        return;
      }
      // Every parse is guarded — non-digit attributes never reach the math below.
      if (!/^\d+$/.test(exponentRaw) || !/^\d+$/.test(String(minorRaw))) {
        return;
      }
      var exponent = parseInt(exponentRaw, 10);
      var minor = parseInt(minorRaw, 10);
      if (!Number.isSafeInteger(exponent) || exponent > 4 || !Number.isSafeInteger(minor)) {
        return;
      }
      var total = minor * clampedQty();
      if (!Number.isSafeInteger(total)) {
        return; // past 2^53 the product is no longer exact — never display a drifted amount
      }
      try {
        target.textContent = new Intl.NumberFormat(
          document.documentElement.lang || undefined,
          { style: 'currency', currency: currency }
        ).format(total / Math.pow(10, exponent));
      } catch (err) {
        // Unknown currency code — the server-rendered label stays.
      }
    }

    function step(delta) {
      var next = clampedQty() + delta;
      if (next < QTY_MIN) {
        next = QTY_MIN;
      }
      if (next > QTY_MAX) {
        next = QTY_MAX;
      }
      qty.value = String(next);
      updateLabel();
    }

    if (minus) {
      minus.addEventListener('click', function () { step(-1); });
    }
    if (plus) {
      plus.addEventListener('click', function () { step(1); });
    }
    if (select) {
      select.addEventListener('change', updateLabel);
    }
    qty.addEventListener('input', updateLabel);
  }

  // ---- wishlist: the ONE device-local state authority ------------------------------
  // Storefront-v1 spec §5. Storage is an ADAPTER, never naked localStorage calls: reading,
  // parsing, and writing are individually caught, a corrupt payload resets to [], and an
  // unavailable or unwritable backend fails CLOSED — hearts and counts stay hidden (never an
  // inert control, never false persistence) while every other shop.js module keeps enhancing.
  // Initialization becomes `ready` ONLY after the sanitized current value round-trips through
  // setItem, and a mutation publishes ONLY after its write succeeds.
  //
  // The value is a unique, ordered list of PRODUCT UUIDS only — newest first, bounded at 100
  // (add unshifts; overflow drops the OLDEST from the tail). No timestamps, names, or prices.
  //
  // The scope comes from the nearest [data-shop-scope] root (every shop page root and every
  // shop block root emits it): no scope means commerce is absent/inactive and the wishlist
  // never initializes — no metadata fetch anywhere.

  var WISHLIST_KEY_PREFIX = 'thallo:wishlist:v1:';
  var WISHLIST_LIMIT = 100;
  var WISHLIST_EVENT = 'thallo:wishlist-changed';
  var WISHLIST_ENDPOINT = '/_shop/wishlist/items?';
  var WISHLIST_SELECTOR = '[data-shop-wishlist-toggle], [data-shop-wishlist-count]';
  // The SAME pinned product-uuid shape the endpoint enforces (schema-pinned NanoID).
  var WISHLIST_UUID = /^[A-Za-z0-9]{12}$/;

  var wishlistStores = {};

  function nearestScope(el) {
    var node = el;
    while (node) {
      if (typeof node.getAttribute === 'function') {
        var scope = node.getAttribute('data-shop-scope');
        if (scope) {
          return scope;
        }
      }
      node = node.parentNode;
    }
    // A control outside any scoped root (a header badge above the page section) still finds
    // the page's one authority — the scope is per-tenant/shop, never per-element.
    var root = qs(document, '[data-shop-scope]');
    return root ? root.getAttribute('data-shop-scope') : null;
  }

  function wishlistStorage() {
    var backend = null;
    try {
      // Even READING window.localStorage throws in some privacy configurations.
      backend = window.localStorage || null;
    } catch (err) {
      backend = null;
    }
    return {
      /** null = the backend is unavailable (fail closed); [] = readable but empty/corrupt. */
      read: function (key) {
        if (!backend) {
          return null;
        }
        var raw;
        try {
          raw = backend.getItem(key);
        } catch (err) {
          return null;
        }
        return parseWishlistValue(raw);
      },
      write: function (key, value) {
        if (!backend) {
          return false;
        }
        try {
          backend.setItem(key, JSON.stringify(value));
          return true;
        } catch (err) {
          return false; // quota/denied — the caller publishes nothing
        }
      },
    };
  }

  function parseWishlistValue(raw) {
    if (raw === null || raw === undefined || raw === '') {
      return [];
    }
    try {
      return JSON.parse(raw);
    } catch (err) {
      return []; // corrupt payload — reset, never throw
    }
  }

  /** Malformed uuids out, duplicates out (first occurrence wins), clamped to 100. */
  function sanitizeWishlist(value) {
    var out = [];
    if (!Array.isArray(value)) {
      return out;
    }
    var seen = {};
    for (var i = 0; i < value.length && out.length < WISHLIST_LIMIT; i++) {
      var uuid = value[i];
      if (typeof uuid !== 'string' || !WISHLIST_UUID.test(uuid) || seen[uuid] === true) {
        continue;
      }
      seen[uuid] = true;
      out.push(uuid);
    }
    return out;
  }

  function wishlistStore(scope) {
    if (Object.prototype.hasOwnProperty.call(wishlistStores, scope)) {
      return wishlistStores[scope];
    }
    var store = createWishlistStore(scope);
    wishlistStores[scope] = store;
    return store;
  }

  function createWishlistStore(scope) {
    var key = WISHLIST_KEY_PREFIX + scope;
    var storage = wishlistStorage();
    var uuids = [];
    var ready = false;
    var revision = 0;
    var generation = 0;
    var refetchScheduled = false;
    var subscribers = [];

    var initial = storage.read(key);
    if (initial !== null) {
      uuids = sanitizeWishlist(initial);
      // READY only after a successful sanitize + setItem round-trip.
      ready = storage.write(key, uuids);
    }

    function publish() {
      var snapshot = uuids.slice();
      for (var i = 0; i < subscribers.length; i++) {
        try {
          subscribers[i](snapshot);
        } catch (err) {
          // One consumer's failure never stops the others from converging.
        }
      }
      if (typeof window.CustomEvent !== 'function' || typeof document.dispatchEvent !== 'function') {
        return;
      }
      document.dispatchEvent(new window.CustomEvent(WISHLIST_EVENT, {
        detail: { scope: scope, uuids: snapshot },
      }));
    }

    /** Persistence FIRST: nothing is adopted or broadcast unless the write succeeded. */
    function commit(next) {
      if (!storage.write(key, next)) {
        return false;
      }
      uuids = next;
      revision++;
      publish();
      return true;
    }

    function has(uuid) {
      return uuids.indexOf(uuid) !== -1;
    }

    function add(uuid) {
      if (!ready || typeof uuid !== 'string' || !WISHLIST_UUID.test(uuid)) {
        return false;
      }
      if (has(uuid)) {
        return false; // already saved — a NO-OP, never a reorder or a second write
      }
      var next = [uuid].concat(uuids);
      if (next.length > WISHLIST_LIMIT) {
        next = next.slice(0, WISHLIST_LIMIT); // overflow drops the OLDEST (the tail)
      }
      return commit(next);
    }

    function remove(uuid) {
      if (!ready || !has(uuid)) {
        return false;
      }
      var next = [];
      for (var i = 0; i < uuids.length; i++) {
        if (uuids[i] !== uuid) {
          next.push(uuids[i]);
        }
      }
      return commit(next);
    }

    function toggle(uuid) {
      return has(uuid) ? remove(uuid) : add(uuid);
    }

    function settle(done, result) {
      if (typeof done === 'function') {
        done(result);
      }
    }

    function scheduleReconcile(done) {
      if (refetchScheduled) {
        return; // exactly ONE fresh reconciliation, never a storm
      }
      refetchScheduled = true;
      Promise.resolve().then(function () {
        refetchScheduled = false;
        reconcile(done);
      });
    }

    /**
     * Race-safe reconciliation (spec §5): each request captures {uuids, storeRevision,
     * requestGeneration}. A response applies ONLY when it is the latest generation AND the
     * store revision is unchanged; otherwise it is ignored and ONE fresh reconciliation is
     * scheduled from current state. On an applicable response, only uuids present in THAT
     * request's snapshot and absent from its response are removed — a remove/re-add or a new
     * heart toggle that happened in flight always survives.
     */
    function reconcile(done) {
      if (!ready) {
        settle(done, { ok: false, ready: false, items: null, requested: [] });
        return;
      }
      var snapshot = uuids.slice();
      var snapshotRevision = revision;
      var requestGeneration = ++generation;
      if (snapshot.length === 0) {
        settle(done, { ok: true, ready: true, items: [], requested: [] });
        return;
      }
      if (typeof window.fetch !== 'function') {
        settle(done, { ok: false, ready: true, items: null, requested: snapshot });
        return;
      }

      var query = [];
      for (var i = 0; i < snapshot.length; i++) {
        query.push('uuids[]=' + encodeURIComponent(snapshot[i]));
      }

      window
        .fetch(WISHLIST_ENDPOINT + query.join('&'), {
          headers: { Accept: 'application/json' },
          credentials: 'same-origin',
        })
        .then(function (res) {
          return res.json();
        })
        .then(function (data) {
          if (requestGeneration !== generation || snapshotRevision !== revision) {
            scheduleReconcile(done); // superseded — never applied, never settled on
            return;
          }
          var items = (data && data.items) || [];
          var present = {};
          for (var p = 0; p < items.length; p++) {
            if (items[p] && typeof items[p].uuid === 'string') {
              present[items[p].uuid] = true;
            }
          }
          var next = [];
          var dropped = false;
          for (var u = 0; u < uuids.length; u++) {
            if (present[uuids[u]] !== true && snapshot.indexOf(uuids[u]) !== -1) {
              dropped = true;
              continue;
            }
            next.push(uuids[u]);
          }
          if (dropped) {
            commit(next); // persistence + broadcast precede the settle below
          }
          settle(done, { ok: true, ready: true, items: items, requested: snapshot });
        })
        .catch(function () {
          // A failed resolution is enhancement failure only — the saved set is untouched.
          settle(done, { ok: false, ready: true, items: null, requested: snapshot });
        });
    }

    // Cross-tab convergence: the other tab already persisted this value, so re-sanitize and
    // publish it here — hearts, badges, and the page converge without a reload.
    if (ready && typeof window.addEventListener === 'function') {
      window.addEventListener('storage', function (event) {
        if (!event || (event.key !== null && event.key !== undefined && event.key !== key)) {
          return;
        }
        uuids = sanitizeWishlist(parseWishlistValue(event.newValue));
        revision++;
        publish();
      });
    }

    return {
      scope: scope,
      ready: function () { return ready; },
      uuids: function () { return uuids.slice(); },
      revision: function () { return revision; },
      has: has,
      add: add,
      remove: remove,
      toggle: toggle,
      reconcile: reconcile,
      subscribe: function (fn) { subscribers.push(fn); },
    };
  }

  // ---- wishlist: hearts + count badges ---------------------------------------------

  function bindWishlistNode(el) {
    if (el.getAttribute('data-shop-wishlist-toggle') !== null) {
      bindWishlistToggle(el);
      return;
    }
    bindWishlistCount(el);
  }

  /** The store a control belongs to, or null when the wishlist must not initialize at all. */
  function readyStoreFor(el) {
    var scope = nearestScope(el);
    if (!scope) {
      return null;
    }
    var store = wishlistStore(scope);
    return store.ready() ? store : null;
  }

  function bindWishlistToggle(btn) {
    if (btn.getAttribute('data-shop-wishlist-bound') === '1') {
      return;
    }
    var uuid = btn.getAttribute('data-product-uuid');
    if (!uuid) {
      return;
    }
    var store = readyStoreFor(btn);
    if (!store) {
      return; // fail closed: the heart stays hidden rather than becoming inert
    }
    btn.setAttribute('data-shop-wishlist-bound', '1');

    var labels = wishlistLabels(btn);
    paintWishlistToggle(btn, store.has(uuid), labels);
    btn.hidden = false; // revealed ONLY behind a ready store
    btn.addEventListener('click', function () {
      store.toggle(uuid);
    });
    store.subscribe(function () {
      paintWishlistToggle(btn, store.has(uuid), labels);
    });
  }

  function paintWishlistToggle(btn, saved, labels) {
    btn.setAttribute('aria-pressed', saved ? 'true' : 'false');
    if (labels) {
      btn.setAttribute('aria-label', saved ? labels.remove : labels.add);
    }
  }

  /**
   * The product-specific label pair. The server ships "Save {name} to wishlist"; its saved
   * counterpart is derived from that ONE authority. An unrecognized label is left untouched —
   * aria-pressed already carries the state, and a wrong name would be worse than none.
   */
  function wishlistLabels(btn) {
    var add = btn.getAttribute('aria-label');
    if (!add) {
      return null;
    }
    var match = /^Save (.+) to wishlist$/.exec(add);
    return match ? { add: add, remove: 'Remove ' + match[1] + ' from wishlist' } : null;
  }

  function bindWishlistCount(el) {
    if (el.getAttribute('data-shop-wishlist-bound') === '1') {
      return;
    }
    var store = readyStoreFor(el);
    if (!store) {
      return;
    }
    el.setAttribute('data-shop-wishlist-bound', '1');
    paintWishlistCount(el, store.uuids().length);
    store.subscribe(function (uuids) {
      paintWishlistCount(el, uuids.length);
    });
  }

  function paintWishlistCount(el, count) {
    el.textContent = String(count);
    // The badge only shows with saved items — a zero badge is noise, exactly like the cart's.
    el.hidden = !(count > 0);
  }

  // ---- wishlist: the page -----------------------------------------------------------
  // The server can never read browser storage, so this page is honestly JS-dependent: the
  // shell ships aria-busy="true" with BOTH the empty state and the grid hidden, and this
  // module settles them only after localStorage AND reconciliation resolve — a returning
  // visitor never sees a false "nothing saved yet".

  function hydrateWishlistPage(root) {
    if (root.getAttribute('data-shop-wishlist-page-bound') === '1') {
      return;
    }
    root.setAttribute('data-shop-wishlist-page-bound', '1');

    var status = qs(root, '[data-shop-wishlist-status]');
    var empty = qs(root, '[data-shop-wishlist-empty]');
    var grid = qs(root, '[data-shop-wishlist-grid]');
    var scope = nearestScope(root);
    var store = scope ? wishlistStore(scope) : null;
    var known = {};
    var requested = {};
    var cards = {};
    var settled = false;

    function settleShell(message, showEmpty) {
      if (status) {
        status.textContent = message;
        status.hidden = message === '';
      }
      if (empty) {
        empty.hidden = !showEmpty;
      }
      settled = true;
      root.setAttribute('aria-busy', 'false'); // cleared ONLY after a real settle
    }

    if (!store || !store.ready()) {
      // Not "nothing saved yet" — the saved set is simply unreadable here. Say so, and never
      // leave a stuck spinner behind.
      settleShell('Saved items are stored in your browser, which is unavailable here.', false);
      return;
    }

    /**
     * Repaints the grid in STORED order. Card elements are cached per uuid and rebuilt only
     * when their projection actually changed, so a repaint never re-creates (and re-binds) a
     * card that is merely moving — the hearts keep their single subscription.
     */
    function paint() {
      var current = store.uuids();
      var painted = 0;
      var missing = false;
      if (grid) {
        clear(grid);
      }
      for (var i = 0; i < current.length; i++) {
        var uuid = current[i];
        var item = known[uuid];
        if (!item) {
          if (requested[uuid] !== true) {
            missing = true; // never resolved (e.g. saved in another tab) — worth a refresh
          }
          continue;
        }
        if (!cards[uuid] || cards[uuid].item !== item) {
          cards[uuid] = { item: item, element: buildProductCard(item) };
        }
        painted++;
        if (grid) {
          grid.appendChild(cards[uuid].element);
        }
      }
      if (grid) {
        grid.hidden = painted === 0;
        enhanceBuiltCards(grid);
      }
      return { count: painted, missing: missing };
    }

    function refresh() {
      store.reconcile(function (result) {
        var items = result.items || [];
        for (var i = 0; i < items.length; i++) {
          if (items[i] && typeof items[i].uuid === 'string') {
            known[items[i].uuid] = items[i];
          }
        }
        for (var r = 0; r < result.requested.length; r++) {
          requested[result.requested[r]] = true;
        }
        var painted = paint();
        if (result.ok !== true) {
          // A failed resolution is NOT an empty wishlist — the saved set is still there.
          settleShell('We could not load your saved items. Refresh to try again.', false);
          return;
        }
        settleShell(painted.count === 0 ? '' : savedItemsMessage(painted.count), painted.count === 0);
      });
    }

    store.subscribe(function () {
      var painted = paint();
      if (!settled) {
        return; // mid-reconciliation: the settle below is what clears aria-busy
      }
      settleShell(painted.count === 0 ? '' : savedItemsMessage(painted.count), painted.count === 0);
      if (painted.missing) {
        refresh();
      }
    });

    refresh();
  }

  function savedItemsMessage(count) {
    return count + ' saved item' + (count === 1 ? '' : 's') + '.';
  }

  function hydrateWishlistControls() {
    var controls = qsa(document, WISHLIST_SELECTOR);
    for (var i = 0; i < controls.length; i++) {
      bindWishlistNode(controls[i]);
    }
    var pages = qsa(document, '[data-shop-wishlist-page]');
    for (var p = 0; p < pages.length; p++) {
      hydrateWishlistPage(pages[p]);
    }
  }

  // ---- init -----------------------------------------------------------------------

  function directSweep() {
    var forms = qsa(document, FORM_SELECTOR);
    for (var i = 0; i < forms.length; i++) {
      bindForm(forms[i]);
    }
    var galleries = qsa(document, '[data-shop-gallery]');
    for (var g = 0; g < galleries.length; g++) {
      bindGallery(galleries[g]);
    }
    var buyAreas = qsa(document, 'form[data-shop-buy]');
    for (var b = 0; b < buyAreas.length; b++) {
      bindBuyArea(buyAreas[b]);
    }
    hydrateMiniCarts();
    hydrateProductGrids();
    hydrateFeaturedProducts();
    hydrateAddToCarts();
    hydrateWishlistControls();
  }

  function init() {
    // Runtime pages: the core owns scanning, markers, canvas policy, and containment —
    // a direct sweep would bypass all four and re-fetch already-hydrated components
    // (shopjs-on-runtime spec §2.4). enhance() is component-idempotent, so init()
    // remains safe to call after inserting new blocks.
    if (window.ThalloRuntime) {
      window.ThalloRuntime.enhance(document.documentElement);
      return;
    }
    directSweep();
  }

  /* shop-runtime:end */
  if (window.ThalloRuntime) {
    // Adoption (theme-runtime spec §2.5 / shopjs-on-runtime spec §2.2): the core
    // drives; enhance closures ARE the per-component functions above. All nine are
    // canvas-skip (the default) — formalizing that shop behavior never runs in the
    // canvas stage.
    window.ThalloRuntime.register('shop-form', { selector: FORM_SELECTOR, enhance: bindForm });
    window.ThalloRuntime.register('shop-gallery', { selector: '[data-shop-gallery]', enhance: bindGallery });
    window.ThalloRuntime.register('shop-buy', { selector: 'form[data-shop-buy]', enhance: bindBuyArea });
    window.ThalloRuntime.register('shop-mini-cart', {
      selector: '[data-shop-mini-cart]',
      enhance: function (el) {
        bindMiniCartShell(el);
        hydrateMiniCart();
      },
    });
    window.ThalloRuntime.register('shop-product-grid', { selector: '[data-shop-block="product-grid"]', enhance: hydrateProductGrid });
    window.ThalloRuntime.register('shop-featured-product', { selector: '[data-shop-block="featured-product"]', enhance: hydrateFeaturedProduct });
    window.ThalloRuntime.register('shop-add-to-cart', { selector: '[data-shop-block="add-to-cart"]', enhance: hydrateAddToCart });
    window.ThalloRuntime.register('shop-wishlist', { selector: WISHLIST_SELECTOR, enhance: bindWishlistNode });
    window.ThalloRuntime.register('shop-wishlist-page', { selector: '[data-shop-wishlist-page]', enhance: hydrateWishlistPage });
    if (document.readyState !== 'loading') {
      // Boot-timing reality check: on a served page the runtime core and this file are
      // SEPARATE defer <script> tasks, and a microtask checkpoint runs between tasks —
      // so the core's deferred boot (Promise.resolve().then(boot) once readyState is
      // past 'loading') has ALREADY fired before the seven registrations above existed.
      // Without a catch-up pass, nothing shop-owned would ever enhance. init()
      // delegates to ThalloRuntime.enhance(document.documentElement), and the core's
      // data-thallo-enhanced markers gate that pass per component — so wherever the
      // boot pass DID cover a component (same-task evaluation, e.g. a test harness
      // evaluating both files in one task), this pass is a no-op.
      // While readyState is still 'loading', the core's boot is waiting on
      // DOMContentLoaded and will cover these registrations itself — schedule nothing.
      Promise.resolve().then(init);
    }
  } else if (document.readyState === 'loading') {
    // Fallback (spec §2.3): a copied pre-runtime layout has no ThalloRuntime — shop.js
    // self-drives exactly as before adoption.
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  // Exposed for the executable test harness (ShopJsRuntimeTest) and for callers that
  // need to re-run enhancement after injecting new blocks (e.g. a builder preview
  // inserting one). On runtime pages init() delegates to the core (see above).
  window.thalloShop = {
    init: init,
    bindForm: bindForm,
    // The client card renderer (structural parity with `_product_card.twig` is pinned by
    // ShopBlocksTest) and the one wishlist state authority, keyed by storage scope.
    buildProductCard: buildProductCard,
    wishlist: wishlistStore,
  };
})();
