<?php

declare(strict_types=1);

use Thallo\Commerce\Http\ProductLinkController;
use Glueful\Routing\Router;

/** @var Router $router */

/*
 * Admin product<->entry linkage API (design spec §5.3). Triple-gated like the other packs:
 *   1. capability       — this file loads only when thallo.commerce is enabled (else 404).
 *   2. auth             — group middleware.
 *   3. content_permission — commerce.manage on every route.
 *   4. admin_tenant_binding — binds the operator's selected workspace, so tenant resolution
 *      (CommerceTenantResolution) and the pack's own link table both scope to it (mirrors
 *      routes/admin.php); inert until full resolution, tenant_bootstrap handles bootstrap.
 */
$router->group(
    [
        'prefix' => '/v1/admin/commerce',
        'middleware' => ['auth', 'tenant_profile:admin', 'tenant_bootstrap', 'admin_tenant_binding'],
    ],
    function (Router $router): void {
        $router->put('/products/{productUuid}/link', [ProductLinkController::class, 'link'])
            ->middleware('content_permission:commerce.manage');
        $router->delete('/products/{productUuid}/link', [ProductLinkController::class, 'unlink'])
            ->middleware('content_permission:commerce.manage');
        $router->get('/products/{productUuid}/link', [ProductLinkController::class, 'showByProduct'])
            ->middleware('content_permission:commerce.manage');
        $router->get('/entries/{entryUuid}/link', [ProductLinkController::class, 'showByEntry'])
            ->middleware('content_permission:commerce.manage');
    },
);
