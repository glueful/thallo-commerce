<?php

declare(strict_types=1);

namespace Thallo\Commerce\Shop\Listeners;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Cache\CacheStore;
use Glueful\Extensions\Commerce\Tenancy\CommerceTenantResolution;
use Psr\Container\ContainerInterface;
use Thallo\Commerce\Layouts\ProductSurface;
use Thallo\Contracts\Layouts\LayoutChanged;

/**
 * LayoutChanged (product surface) → the workspace's product pages leave the shop cache (type layouts
 * spec §7.4, plan C1). Every product page carries {@see ProductSurface::pageCacheTag()} for its
 * workspace, so a first save purges pages cached from the theme's template, an edit the layout's
 * previous version, and a removal the pages the layout rendered — in that workspace only: another
 * workspace's cached pages stay.
 *
 * The event names the workspace while tenancy is on; otherwise the shop's own resolution does (the
 * single store's default tenant). On a driver without tag invalidation (the default file driver)
 * that workspace's shop pages are deleted instead — as {@see PurgeShopCacheOnCatalogChange} falls
 * back — so the change shows on the next request, never after the TTL.
 */
final class PurgeShopCacheOnLayoutChange
{
    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public function onLayoutChanged(object $event): void
    {
        if (!$event instanceof LayoutChanged || $event->surface !== ProductSurface::KEY) {
            return;
        }
        $tenant = $event->tenantUuid ?? $this->container->get(CommerceTenantResolution::class)
            ->tenantUuid($this->container->get(ApplicationContext::class));
        $cache = $this->container->get(CacheStore::class);
        if ($cache->invalidateTags([ProductSurface::pageCacheTag($tenant)])) {
            return;
        }
        $cache->deletePattern('shop:' . $tenant . ':*');
        $cache->deletePattern('tenant:*:shop:' . $tenant . ':*');
    }
}
