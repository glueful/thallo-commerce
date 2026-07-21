<?php

declare(strict_types=1);

namespace Thallo\Commerce\Shop\Listeners;

use Glueful\Cache\CacheStore;
use Glueful\Extensions\Commerce\Events\ProductSlugChanged;
use Psr\Container\ContainerInterface;

/**
 * ProductSlugChanged -> invalidateTags(["thallo:shop:catalog:{tenant}"]) (storefront-rendering
 * spec §4/§9). A renamed product's OLD canonical/JSON-LD URL and grid cards can be cached
 * under the pre-rename slug — this purges the mutating tenant's catalog namespace so the next
 * request re-resolves against the new slug (and, for the old slug, the ledger 301). The
 * CacheStore is resolved per-invocation, matching {@see PurgeShopCacheOnCatalogChange}.
 */
final class PurgeShopCacheOnSlugChange
{
    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public function onSlugChanged(object $event): void
    {
        if (!$event instanceof ProductSlugChanged) {
            return;
        }
        $this->container->get(CacheStore::class)->invalidateTags(['thallo:shop:catalog:' . $event->tenantUuid]);
    }
}
