<?php

declare(strict_types=1);

namespace Thallo\Commerce\Shop\Listeners;

use Glueful\Cache\CacheStore;
use Glueful\Extensions\Commerce\Events\StorefrontCatalogChanged;
use Psr\Container\ContainerInterface;
use Thallo\Commerce\Shop\CatalogGeneration;
use Thallo\Contracts\Delivery\RenderedPageCachePurge;

/**
 * StorefrontCatalogChanged -> invalidateTags(["thallo:shop:catalog:{tenant}"]) (storefront-
 * rendering spec §9). ONE listener covers every one of the event's 11 closed reasons
 * (product create/update/status/delete, variant/price, stock — including checkout/refund/
 * cancel adjustments —, media, category, tag, attribute, add-on): the reason is carried on the
 * event instance, not the event CLASS, so this purge fires identically regardless of which
 * storefront-visible mutation dispatched it. Purges only the mutating tenant's namespace — a
 * different tenant's cached catalog pages are untouched. The CacheStore is resolved
 * per-invocation (thallo-render's Purge* listener idiom), not captured at construction.
 *
 * Product grid spec §3.2: every change first rotates the workspace's catalog generation (so a
 * render that read the old catalog is refused on read), and the fallback for a driver without tag
 * invalidation drops the EVENT's workspace's shop pages and rendered pages — a grid can sit on
 * either — and never another workspace's.
 */
final class PurgeShopCacheOnCatalogChange
{
    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public function onCatalogChanged(object $event): void
    {
        if (!$event instanceof StorefrontCatalogChanged) {
            return;
        }
        $cache = $this->container->get(CacheStore::class);
        // Before the purge: a render that read the old catalog now holds a stale guard, so
        // whatever it stores is refused on read (product grid spec §3.2).
        (new CatalogGeneration($cache))->rotate($event->tenantUuid);
        if ($cache->invalidateTags(['thallo:shop:catalog:' . $event->tenantUuid])) {
            return;
        }
        // No tag invalidation: drop the owning workspace's shop pages and rendered pages (a grid
        // can sit on either), never another workspace's.
        $cache->deletePattern('shop:' . $event->tenantUuid . ':*');
        $cache->deletePattern('tenant:' . $event->tenantUuid . ':shop:*');
        // The event names its workspace (the commerce tenant IS the workspace — both resolve
        // through TenancyModePolicy); never the request's, which background or cross-workspace
        // work may not share.
        if ($this->container->has(RenderedPageCachePurge::class)) {
            $this->container->get(RenderedPageCachePurge::class)->purgeWorkspace($event->tenantUuid);
        }
    }
}
