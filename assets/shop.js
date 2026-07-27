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
 * On runtime pages (window.ThalloRuntime present) the theme-runtime core drives all of this
 * through six registered `shop-*` modules — shop.js attaches no load hook of its own there.
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
          itemsEl.appendChild(buildGridItem(items[i]));
        }
      }
    }

    // "view all" ALWAYS points at the canonical shop/category route the JSON supplied
    // (built server-side via ShopUrlGenerator) — never a query-paginated builder-page link.
    if (viewAll && data && data.view_all_url) {
      viewAll.hidden = false;
      viewAll.setAttribute('href', data.view_all_url);
    }
  }

  function buildGridItem(product) {
    var li = document.createElement('li');
    li.className = 'thallo-block-product-grid__item';

    var a = document.createElement('a');
    a.setAttribute('href', product.url);

    var name = document.createElement('span');
    name.className = 'thallo-block-product-grid__name';
    name.textContent = product.name;
    a.appendChild(name);

    if (product.price_formatted) {
      var price = document.createElement('span');
      price.className = 'thallo-block-product-grid__price';
      price.textContent = product.price_formatted + ' ' + (product.currency || '');
      a.appendChild(price);
    }

    li.appendChild(a);
    return li;
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
    hydrateMiniCarts();
    hydrateProductGrids();
    hydrateFeaturedProducts();
    hydrateAddToCarts();
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
    // drives; enhance closures ARE the per-component functions above. All six are
    // canvas-skip (the default) — formalizing that shop behavior never runs in the
    // canvas stage.
    window.ThalloRuntime.register('shop-form', { selector: FORM_SELECTOR, enhance: bindForm });
    window.ThalloRuntime.register('shop-gallery', { selector: '[data-shop-gallery]', enhance: bindGallery });
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
    if (document.readyState !== 'loading') {
      // Boot-timing reality check: on a served page the runtime core and this file are
      // SEPARATE defer <script> tasks, and a microtask checkpoint runs between tasks —
      // so the core's deferred boot (Promise.resolve().then(boot) once readyState is
      // past 'loading') has ALREADY fired before the six registrations above existed.
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
  };
})();
