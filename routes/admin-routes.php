<?php

declare(strict_types=1);

use Thallo\Commerce\Http\AdminCompleteSaleController;
use Thallo\Commerce\Http\AdminMountAllowlist;
use Thallo\Commerce\Http\AdminOrderExportController;
use Thallo\Commerce\Http\AdminOrderPaymentsController;
use Thallo\Commerce\Http\AdminOrderSearchController;
use Thallo\Commerce\Http\AdminPaymentLinkSendController;
use Thallo\Commerce\Http\CommerceMetaController;
use Thallo\Commerce\Http\CommerceSettingsController;
use Thallo\Commerce\Http\EmailSettingsController;
use Thallo\Commerce\Http\MarketplaceSettingsController;
use Thallo\Commerce\Http\ProductLinkController;
use Glueful\Extensions\Commerce\Http\Routing\AdminMountProfile;
use Glueful\Extensions\Commerce\Http\Routing\AdminRouteCatalog;
use Glueful\Routing\Router;

/** @var Router $router */

/*
 * Admin product<->entry linkage API (design spec §5.3) plus the pack-owned `/meta` settings/
 * entitlement probe (Task 8, design spec §4.3). Triple-gated like the other packs:
 *   1. capability       — this file loads only when thallo.commerce is enabled (else 404).
 *   2. auth             — group middleware.
 *   3. content_permission — commerce.manage on every write; the two link GETs, the
 *      entry-search GET, and `/meta` below are graded per task 7/8 (see each route's own
 *      comment).
 *   4. admin_tenant_binding — binds the operator's selected workspace, so tenant resolution
 *      (CommerceTenantResolution) and the pack's own link table both scope to it (mirrors
 *      routes/admin.php); inert until full resolution, tenant_bootstrap handles bootstrap.
 *
 * Every route in this group carries an explicit `thallo.commerce.admin.<key>` name (Task 8):
 * `AdminOpenApiGateTest` asserts the resulting operation ids are globally unique and prefixed
 * `thalloCommerceAdmin...`, matching `AdminRouteCatalog::mount()`'s own naming for the catalog
 * routes registered below. Naming these ALSO fixes an OpenAPI-generation gap this task found:
 * an unnamed `/v1/admin/...` route's tag is derived from its first path segment ("Admin"), which
 * collides with `documentation.options.tags.exclude`'s "Admin" entry (reserved for the SPA-serving
 * HTML mount) and silently drops the route from `docs/openapi.json` — see each handler's own
 * `#[ApiOperation(tags: ['Thallo Commerce'])]` for the actual fix (a custom tag, same technique
 * every other Commerce admin controller already uses to escape the same collision).
 */
$router->group(
    [
        'prefix' => '/v1/admin/commerce',
        'middleware' => ['auth', 'tenant_profile:admin', 'tenant_bootstrap', 'admin_tenant_binding'],
    ],
    function (Router $router): void {
        $router->put('/products/{productUuid}/link', [ProductLinkController::class, 'link'])
            ->middleware('content_permission:commerce.manage')
            ->name('thallo.commerce.admin.products.link.set');
        $router->delete('/products/{productUuid}/link', [ProductLinkController::class, 'unlink'])
            ->middleware('content_permission:commerce.manage')
            ->name('thallo.commerce.admin.products.link.unset');
        // Task 7: regraded from commerce.manage-only — a view-only operator can look up an
        // existing link (or confirm a product has none) without also holding manage rights.
        $router->get('/products/{productUuid}/link', [ProductLinkController::class, 'showByProduct'])
            ->middleware('content_permission:commerce.view,commerce.manage')
            ->name('thallo.commerce.admin.products.link.show');
        $router->get('/entries/{entryUuid}/link', [ProductLinkController::class, 'showByEntry'])
            ->middleware('content_permission:commerce.view,commerce.manage')
            ->name('thallo.commerce.admin.entries.link.show');
        // Task 7: the linkage picker's entry search stays manage-only (it exists to support
        // CREATING/CHANGING a link, unlike the two read-only lookups above). Registered here
        // (not as a `/entries/{entryUuid}/link` sibling) so its static `/entries` path and that
        // route's dynamic `/entries/{entryUuid}/link` path never shadow one another — proven by
        // AdminAuthorizationMatrixTest/ProductLinkApiTest driving both through the real router.
        $router->get('/entries', [ProductLinkController::class, 'searchEntries'])
            ->middleware('content_permission:commerce.manage')
            ->name('thallo.commerce.admin.entries.search');
        // Task 8 (design spec §4.3): the single settings/entitlement probe the SPA area shares
        // across every page and editor panel. `view` mode — commerce.manage satisfies it too via
        // the capability catalog's implication (see CommerceMetaController's own docblock).
        $router->get('/meta', [CommerceMetaController::class, 'meta'])
            ->middleware('content_permission:commerce.view,commerce.manage')
            ->name('thallo.commerce.admin.meta');
        // Store settings (store-settings spec §3.4): read graded like /meta; writes manage-only.
        $router->get('/settings', [CommerceSettingsController::class, 'show'])
            ->middleware('content_permission:commerce.view,commerce.manage')
            ->name('thallo.commerce.admin.settings.show');
        $router->put('/settings', [CommerceSettingsController::class, 'update'])
            ->middleware('content_permission:commerce.manage')
            ->name('thallo.commerce.admin.settings.update');
        // Payments settings RETIRED (platform-payments-settings spec, Task 6): gateway
        // credentials are platform/installation-level infrastructure, not per-store content, so
        // they moved to the neutral `GET|PUT /v1/admin/settings/payments` (routes/admin.php,
        // gated `tenancy.manage`) backed by the app-owned PlatformPaymentSettingsStore. This pack
        // no longer owns any payments-settings surface.
        // Order-email switches (store-settings spec §4.2 follow-up): same grading again.
        $router->get('/emails', [EmailSettingsController::class, 'show'])
            ->middleware('content_permission:commerce.view,commerce.manage')
            ->name('thallo.commerce.admin.emails.show');
        $router->put('/emails', [EmailSettingsController::class, 'update'])
            ->middleware('content_permission:commerce.manage')
            ->name('thallo.commerce.admin.emails.update');
        // Marketplace settings (store-settings spec §3.6): reads view-graded, writes manage-only.
        // Thin front over commerce's marketplace services; writes 409 while the boot-time master
        // flag (COMMERCE_MARKETPLACE_ENABLED) is off.
        $router->get('/marketplace', [MarketplaceSettingsController::class, 'show'])
            ->middleware('content_permission:commerce.view,commerce.manage')
            ->name('thallo.commerce.admin.marketplace.show');
        $router->post('/marketplace/activate', [MarketplaceSettingsController::class, 'activate'])
            ->middleware('content_permission:commerce.manage')
            ->name('thallo.commerce.admin.marketplace.activate');
        $router->post('/marketplace/deactivate', [MarketplaceSettingsController::class, 'deactivate'])
            ->middleware('content_permission:commerce.manage')
            ->name('thallo.commerce.admin.marketplace.deactivate');
        $router->put('/marketplace/commission', [MarketplaceSettingsController::class, 'updateCommission'])
            ->middleware('content_permission:commerce.manage')
            ->name('thallo.commerce.admin.marketplace.commission');
        $router->put('/marketplace/master', [MarketplaceSettingsController::class, 'setMaster'])
            ->middleware('content_permission:commerce.manage')
            ->name('thallo.commerce.admin.marketplace.master');
        // Task 3 (orders-invoices-receipts plan): TEMPORARY app-owned filtered orders search,
        // until Commerce's own admin orders endpoint gains equivalent filter parity upstream
        // (see AdminOrderSearchController's own docblock for the retirement condition). A fully
        // static path ('/orders/search') — the router tries its static-route table before the
        // catalog's dynamic '/orders/{uuid}' mount below, so this can never be shadowed by (or
        // shadow) that route regardless of registration order (Router::match() step 1 vs step 2).
        // View authority, matching every other read in this pack's own group.
        $router->get('/orders/search', [AdminOrderSearchController::class, 'search'])
            ->middleware('content_permission:commerce.view,commerce.manage')
            ->name('thallo.commerce.admin.orders.search');
        // Task 4 (orders-invoices-receipts plan): the bounded streamed CSV export sharing
        // AdminOrderSearchQuery/AdminOrderSearchFilter with the search route above (see
        // AdminOrderExportController's own docblock). Also a fully static path
        // ('/orders/export'), same non-shadowing reasoning as '/orders/search'. Same view
        // authority as every other read in this pack's own group.
        $router->get('/orders/export', [AdminOrderExportController::class, 'export'])
            ->middleware('content_permission:commerce.view,commerce.manage')
            ->name('thallo.commerce.admin.orders.export');
        // Task 5 (orders-invoices-receipts plan): the admin order payment summary, reading
        // Payvia's own `payments`/`payment_intents` tables (see
        // AdminOrderPaymentsController/OrderPaymentSummaryRepository's own docblocks). Registered
        // in THIS group -- before AdminRouteCatalog::mount() below -- so it can never be shadowed
        // by the vendor catalog's own `/orders/{uuid}` mount; the vendor catalog declares no
        // `/orders/{uuid}/payments` key of its own (AdminRouteCatalog's own route table has no
        // "payments" entry under the `orders` domain), so there is nothing to collide with either
        // way. Same view authority as every other read in this pack's own group.
        $router->get('/orders/{uuid}/payments', [AdminOrderPaymentsController::class, 'payments'])
            ->middleware('content_permission:commerce.view,commerce.manage')
            ->name('thallo.commerce.admin.orders.payments');
        // Task 13 (admin-order-creation cycle 2, design spec §2.8): the server-orchestrated
        // walk-in finish -- markPaid() then fulfill(), each keeping its own CAS/audit/events (see
        // AdminCompleteSaleController/CompleteSaleCoordinator's own docblocks). MANAGE authority:
        // unlike the reads above it performs two real state transitions. Registered in THIS group
        // -- before AdminRouteCatalog::mount() below -- for the same reason as
        // `/orders/{uuid}/payments`: the vendor catalog declares no `complete-sale` key under the
        // `orders` domain, so nothing can shadow it or be shadowed by it either way.
        $router->post('/orders/{uuid}/complete-sale', [AdminCompleteSaleController::class, 'completeSale'])
            ->middleware('content_permission:commerce.manage')
            ->name('thallo.commerce.admin.orders.complete_sale');
        // Payment links Task 12 (payment-links spec §2.4): the ONE payment-link surface this pack
        // owns. Mint (`POST /orders/{uuid}/payment-link`), revoke (`DELETE`) and status (`GET`)
        // belong to the vendor catalog mounted below -- this pack must never redeclare that
        // method/path triple, and `PaymentLinkSendTest`'s route-uniqueness assertions prove it
        // doesn't. `/payment-link/send` is a DISTINCT path (one segment deeper), so it neither
        // shadows nor is shadowed by the catalog's `/orders/{uuid}/payment-link` entry, and
        // registering it HERE -- ahead of AdminRouteCatalog::mount() -- matches the identical
        // posture of `/orders/{uuid}/payments` and `/orders/{uuid}/complete-sale` above.
        // MANAGE authority: a send emails a bearer credential and, in regenerate mode,
        // invalidates the order's existing link.
        $router->post('/orders/{uuid}/payment-link/send', [AdminPaymentLinkSendController::class, 'send'])
            ->middleware('content_permission:commerce.manage')
            ->name('thallo.commerce.admin.orders.payment_link.send');
    },
);

/*
 * Task 6 (admin-commerce-area plan, slice 3): mounts glueful/commerce's own admin catalog
 * (products, orders, discounts, shipping, tax, reports, …) at the SAME `/v1/admin/commerce`
 * prefix, behind the SAME `auth`/`tenant_profile`/`tenant_bootstrap`/`admin_tenant_binding`
 * stack as the link routes above — but each mounted route additionally carries its own
 * per-mode `content_permission` middleware (`commerce.view,commerce.manage` for reads,
 * `commerce.manage` for writes), resolved once here rather than duplicated per entry.
 *
 * Deliberately OUTSIDE the `$router->group()` above: `AdminRouteCatalog::mount()` opens its
 * OWN `prefix`/`middleware` group internally (see its source), so nesting this call inside
 * the existing group would apply `/v1/admin/commerce` and the base middleware stack twice.
 *
 * Fail-closed by construction: `AdminMountProfile::restricted()` refuses an empty allowlist,
 * and `AdminRouteCatalog::mount()` throws on any allowlist key the catalog doesn't recognise
 * — so `AdminMountAllowlist::keys()` is the single, explicit, hand-maintained inventory of
 * every Commerce admin endpoint this host chooses to expose (enforced by
 * `AdminMountParityTest`'s catalog-drift assertion; see that class + the allowlist's own
 * docblock).
 */
AdminRouteCatalog::mount($router, AdminMountProfile::restricted(
    '/v1/admin/commerce',
    'thallo.commerce.admin.',
    ['auth', 'tenant_profile:admin', 'tenant_bootstrap', 'admin_tenant_binding'],
    ['view' => 'content_permission:commerce.view,commerce.manage', 'manage' => 'content_permission:commerce.manage'],
    AdminMountAllowlist::keys(),
));
