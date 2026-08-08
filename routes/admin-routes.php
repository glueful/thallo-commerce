<?php

declare(strict_types=1);

use Thallo\Commerce\Http\AdminMountAllowlist;
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
