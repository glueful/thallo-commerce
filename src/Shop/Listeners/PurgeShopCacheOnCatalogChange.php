<?php

declare(strict_types=1);

namespace Thallo\Commerce\Shop\Listeners;

use Glueful\Cache\CacheStore;
use Glueful\Extensions\Commerce\Events\StorefrontCatalogChanged;
use Psr\Container\ContainerInterface;

/**
 * StorefrontCatalogChanged -> invalidateTags(["thallo:shop:catalog:{tenant}"]) (storefront-
 * rendering spec §9). ONE listener covers every one of the event's 11 closed reasons
 * (product create/update/status/delete, variant/price, stock — including checkout/refund/
 * cancel adjustments —, media, category, tag, attribute, add-on): the reason is carried on the
 * event instance, not the event CLASS, so this purge fires identically regardless of which
 * storefront-visible mutation dispatched it. Purges only the mutating tenant's namespace — a
 * different tenant's cached catalog pages are untouched. The CacheStore is resolved
 * per-invocation (thallo-render's Purge* listener idiom), not captured at construction.
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
        $this->container->get(CacheStore::class)->invalidateTags(['thallo:shop:catalog:' . $event->tenantUuid]);
    }
}
