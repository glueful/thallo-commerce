<?php

declare(strict_types=1);

use Glueful\Routing\Router;
use Thallo\Commerce\Http\Shop\ShopAssetController;
use Thallo\Commerce\Http\Shop\ShopBlockDataController;
use Thallo\Commerce\Http\Shop\ShopCartController;
use Thallo\Commerce\Http\Shop\ShopCatalogController;
use Thallo\Commerce\Http\Shop\ShopCheckoutController;
use Thallo\Commerce\Http\Shop\ShopCsrfGuard;
use Thallo\Commerce\Http\Shop\ShopPaymentLinkController;
use Thallo\Commerce\Http\Shop\ShopPaymentLinkHeaders;
use Thallo\Commerce\Http\Shop\ShopWishlistController;
use Thallo\Commerce\Shop\ShopFrameEmbedding;
use Thallo\Commerce\Shop\ShopPageCache;
use Thallo\Commerce\Shop\ShopUrlGenerator;

/** @var Router $router */

/*
 * The public catalog surface (storefront-rendering spec §3): shop index, product detail,
 * category archive. Loaded only inside the `thallo.commerce` capability gate
 * (CommerceIntegrationServiceProvider::boot()) — the prefix segment stays reserved
 * unconditionally via ShopReservedPathContributor regardless of that gate.
 *
 * The prefix is resolved from the SAME ShopUrlGenerator the controller/templates use (never
 * duplicated as a separate config read) — by the time route files load, the provider has
 * already eagerly resolved it once (to validate config at boot), so this is a cheap, already-
 * memoized container lookup, not a second construction.
 *
 * Ordering relative to Render's `/{path}` catch-all is structural, not file-load-order: `/shop`
 * has no `{}` in its path, so the router stores it as a STATIC route (O(1) exact-match lookup,
 * tried before any dynamic route); `/shop/products/{slug}` and `/shop/categories/{slug}` are
 * dynamic but bucketed by their literal first segment ("shop"), which the router always tries
 * before the parameter-first-segment ('*') bucket Render's `/{path}` lives in. Both routes win
 * over the catch-all regardless of which provider's routes file happened to load first.
 */
$prefix = $router->getContext()->getContainer()->get(ShopUrlGenerator::class)->prefix;

// Task 8 (storefront-rendering spec §9): ShopPageCache is the LAST middleware in the chain
// (tenant context must already be resolved before the cache key can be built) — deliberately
// NOT applied to any future /cart, /checkout, or /_shop route: those are private/no-store by
// construction and must never enter this shared cache.
$router->get('/' . $prefix, [ShopCatalogController::class, 'index'])
    ->middleware(['tenant_profile:public', 'tenant_bootstrap', ShopPageCache::class]);
// ShopFrameEmbedding sits BEFORE ShopPageCache so the frame-ancestors policy post-processes
// BOTH the cache-miss render and the cache-hit short-circuit (composed-editor spec §5.4b,
// phase 3 — the admin's Live Mirror embeds this page; product route ONLY).
$router->get('/' . $prefix . '/products/{slug}', [ShopCatalogController::class, 'product'])
    ->middleware(['tenant_profile:public', 'tenant_bootstrap', ShopFrameEmbedding::class, ShopPageCache::class]);
$router->get('/' . $prefix . '/categories/{slug}', [ShopCatalogController::class, 'category'])
    ->middleware(['tenant_profile:public', 'tenant_bootstrap', ShopPageCache::class]);
/*
 * Storefront-v1 Task 7 (spec §5): the wishlist page — a static, per-visitor-data-free
 * hydration shell, so it participates in ShopPageCache exactly like the catalog pages above
 * (the saved set lives in the browser; shop.js resolves it via /_shop/wishlist/items below,
 * which — like every /_shop route — is never page-cached). Static-route precedence over
 * `/{prefix}/products/{slug}`-style dynamic routes is structural, same as `/{prefix}` above.
 */
$router->get('/' . $prefix . '/wishlist', [ShopWishlistController::class, 'page'])
    ->middleware(['tenant_profile:public', 'tenant_bootstrap', ShopPageCache::class]);

/*
 * Task 9 (storefront-rendering spec §3/§6/§9): cart page + `/_shop/cart` mini-cart JSON +
 * `/_shop/cart/*` mutations. `cart`/`_shop` are reserved unconditionally by
 * ShopReservedPathContributor regardless of this gate, exactly like `{prefix}` above.
 * ShopCsrfGuard runs LAST (after tenant_bootstrap, mirroring ShopPageCache's own ordering
 * comment above) so the canonical-origin resolver sees an already-resolved tenant under
 * enforcement — it is applied ONLY to the four mutating POSTs below, never the two GETs.
 * Never cached (ShopPageCache is deliberately absent here — see the comment above).
 */
$router->get('/cart', [ShopCartController::class, 'page'])
    ->middleware(['tenant_profile:public', 'tenant_bootstrap']);
$router->get('/_shop/cart', [ShopCartController::class, 'show'])
    ->middleware(['tenant_profile:public', 'tenant_bootstrap']);

$router->post('/_shop/cart/add', [ShopCartController::class, 'add'])
    ->middleware(['tenant_profile:public', 'tenant_bootstrap', ShopCsrfGuard::class]);
$router->post('/_shop/cart/update', [ShopCartController::class, 'update'])
    ->middleware(['tenant_profile:public', 'tenant_bootstrap', ShopCsrfGuard::class]);
$router->post('/_shop/cart/remove', [ShopCartController::class, 'remove'])
    ->middleware(['tenant_profile:public', 'tenant_bootstrap', ShopCsrfGuard::class]);
$router->post('/_shop/cart/discount', [ShopCartController::class, 'discount'])
    ->middleware(['tenant_profile:public', 'tenant_bootstrap', ShopCsrfGuard::class]);

/*
 * Task 10 (storefront-rendering spec §3/§7/§8) + checkout-ui plan Task 3: checkout page +
 * no-JS quote render + placement + provider return/cancel + order confirmation. `checkout` is
 * reserved unconditionally by ShopReservedPathContributor regardless of this gate, exactly like
 * `{prefix}`/`cart`/`_shop` above. Never cached (ShopPageCache deliberately absent, mirrors the
 * cart routes above) — every response here is private/no-store.
 *
 * Optional shopper identity (checkout-ui plan): page/quote/place carry
 * `session_cookie:optional` (adapts a valid account cookie into a Bearer header, drops a lapsed
 * one to anonymous) then `auth:optional` (sets the `user` attribute without 401-ing strangers),
 * AFTER the tenant pair and BEFORE ShopCsrfGuard — identity only prefills email and stamps
 * order ownership; anonymous checkout is byte-identical to before. The POST /checkout quote
 * render is NON-mutating but carries ShopCsrfGuard like the other shop POSTs (one provenance
 * policy for every form post).
 */
$router->get('/checkout', [ShopCheckoutController::class, 'page'])
    ->middleware(['tenant_profile:public', 'tenant_bootstrap', 'session_cookie:optional', 'auth:optional']);
$router->post('/checkout', [ShopCheckoutController::class, 'quotePage'])
    ->middleware([
        'tenant_profile:public',
        'tenant_bootstrap',
        'session_cookie:optional',
        'auth:optional',
        ShopCsrfGuard::class,
    ]);
$router->post('/_shop/checkout/quote', [ShopCheckoutController::class, 'quote'])
    ->middleware(['tenant_profile:public', 'tenant_bootstrap', ShopCsrfGuard::class]);
$router->post('/_shop/checkout/place', [ShopCheckoutController::class, 'place'])
    ->middleware([
        'tenant_profile:public',
        'tenant_bootstrap',
        'session_cookie:optional',
        'auth:optional',
        ShopCsrfGuard::class,
    ]);
$router->get('/checkout/return/{ref}', [ShopCheckoutController::class, 'paymentReturn'])
    ->middleware(['tenant_profile:public', 'tenant_bootstrap']);
$router->get('/checkout/cancel/{ref}', [ShopCheckoutController::class, 'paymentCancel'])
    ->middleware(['tenant_profile:public', 'tenant_bootstrap']);
$router->get('/checkout/confirmation/{ref}', [ShopCheckoutController::class, 'confirmation'])
    ->middleware(['tenant_profile:public', 'tenant_bootstrap']);

/*
 * Payment links Task 11 (payment-links spec §2.3): the PUBLIC payment-link surface — the landing
 * page, the no-JS initiate POST, and the two signed NON-AUTHORIZING receipts a gateway returns
 * the browser to. All four live under the `checkout` path segment ShopReservedPathContributor
 * already reserves UNCONDITIONALLY, so Render's `/{path}` catch-all can never serve a builder
 * page at any of them; none carries ShopPageCache (deliberately absent, like every other
 * /checkout route above) — every response here is per-bearer and no-store.
 *
 * Middleware order is the security posture, not a style choice:
 *  1. the tenant pair, so the canonical-origin authority and Commerce's tenant scoping are
 *     resolved before anything below reads them;
 *  2. ShopPaymentLinkHeaders FIRST after it, so spec §2.3's three headers land on EVERY response
 *     — including the ones produced below it (a rate-limit 429, a CSRF 403), which never reach
 *     the controller at all;
 *  3. `rate_limit` next. The engine's own ceiling is PER LINK and therefore cannot see an
 *     UNKNOWN token: these IP ceilings are the only thing standing between a prober and
 *     unlimited token enumeration, which is why they sit on the GETs as well as the POST.
 *     The windows are deliberately multi-minute (a real payer opens their bill a handful of
 *     times, not sixty times a minute) and keyed BY IP — never by endpoint, which would give a
 *     token enumerator a fresh bucket for every guess;
 *  4. ShopCsrfGuard last on the POST — the STOCK guard, mirroring its own "runs LAST" ordering
 *     everywhere else in this file, with nothing widened for this surface. That is a direct
 *     consequence of §2.3's `Referrer-Policy: strict-origin`: a same-origin form POST from a
 *     `strict-origin` page still sends a real `Origin` (and an origin-only `Referer`), so the
 *     guard's ordinary Origin comparison decides it. Under the previous `no-referrer` the browser
 *     serialized that `Origin` as opaque `null` and sent no `Referer`, which is why a bespoke
 *     wrapper existed at all; it is deleted.
 *
 * Route shapes cannot collide with the guest-cookie checkout routes above: `/checkout/pay/...`
 * has a LITERAL second segment, so `/checkout/return/{ref}`, `/checkout/cancel/{ref}`, and
 * `/checkout/confirmation/{ref}` keep matching exactly what they always did, and the receipt
 * paths are five segments to their three.
 */
$router->get('/checkout/pay/{token}', [ShopPaymentLinkController::class, 'landing'])
    ->middleware(['tenant_profile:public', 'tenant_bootstrap', ShopPaymentLinkHeaders::class, 'rate_limit'])
    ->rateLimit(120, 5, by: 'ip');
$router->post('/checkout/pay/{token}/initiate', [ShopPaymentLinkController::class, 'initiate'])
    ->middleware([
        'tenant_profile:public',
        'tenant_bootstrap',
        ShopPaymentLinkHeaders::class,
        'rate_limit',
        ShopCsrfGuard::class,
    ])
    ->rateLimit(30, 10, by: 'ip');
$router->get('/checkout/pay/return/{linkUuid}/{signature}', [ShopPaymentLinkController::class, 'paymentReturn'])
    ->middleware(['tenant_profile:public', 'tenant_bootstrap', ShopPaymentLinkHeaders::class, 'rate_limit'])
    ->rateLimit(120, 5, by: 'ip');
$router->get('/checkout/pay/cancel/{linkUuid}/{signature}', [ShopPaymentLinkController::class, 'paymentCancel'])
    ->middleware(['tenant_profile:public', 'tenant_bootstrap', ShopPaymentLinkHeaders::class, 'rate_limit'])
    ->rateLimit(120, 5, by: 'ip');

/*
 * Task 11 (storefront-rendering spec §5.2/§10): the JSON data source `shop.js` hydrates the
 * 3 catalog-data block shells from, plus the fingerprinted static-asset route it (and the block
 * templates, transitively) are served from. `_shop` is already reserved unconditionally by
 * ShopReservedPathContributor, exactly like the cart/checkout endpoints above. Never cached by
 * ShopPageCache (deliberately absent) — these are per-block reads, not the dimension-complete
 * catalog page cache's concern.
 */
// Storefront-v1 Task 7 (spec §5): the bounded, ordered wishlist resolution endpoint shop.js
// hydrates the wishlist page (and reconciles localStorage) from. Public GET like `/_shop/cart`
// above, and never cached by ShopPageCache (deliberately absent) — always private, no-store.
$router->get('/_shop/wishlist/items', [ShopWishlistController::class, 'items'])
    ->middleware(['tenant_profile:public', 'tenant_bootstrap']);

$router->get('/_shop/blocks/product-grid', [ShopBlockDataController::class, 'productGrid'])
    ->middleware(['tenant_profile:public', 'tenant_bootstrap']);
$router->get('/_shop/blocks/featured-product', [ShopBlockDataController::class, 'featuredProduct'])
    ->middleware(['tenant_profile:public', 'tenant_bootstrap']);
$router->get('/_shop/blocks/add-to-cart', [ShopBlockDataController::class, 'addToCart'])
    ->middleware(['tenant_profile:public', 'tenant_bootstrap']);

// Fingerprinted, immutable, tenant-agnostic static asset (shop.js) — the SAME
// tenant_profile:public + tenant_bootstrap pairing every other route in this file uses, mirroring
// thallo-render's own static-asset routes (e.g. /custom.css, /_preview.css) for consistency.
$router->get('/_shop/assets/{file}', [ShopAssetController::class, 'serve'])
    ->middleware(['tenant_profile:public', 'tenant_bootstrap']);
