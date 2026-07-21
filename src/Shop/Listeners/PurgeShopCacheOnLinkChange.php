<?php

declare(strict_types=1);

namespace Thallo\Commerce\Shop\Listeners;

use Glueful\Cache\CacheStore;
use Psr\Container\ContainerInterface;
use Thallo\Commerce\Events\ProductLinkChanged;

/**
 * ProductLinkChanged (Slice-1) -> invalidateTags(["thallo:shop:catalog:{tenant}"]). A product's
 * enrichment link (or unlink) changes what its cached product-detail page renders (the
 * `shop-product__enrichment` region) — this purges the mutating tenant's catalog namespace so
 * the next request re-resolves the current link state. The CacheStore is resolved
 * per-invocation, matching {@see PurgeShopCacheOnCatalogChange}.
 */
final class PurgeShopCacheOnLinkChange
{
    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public function onLinkChanged(object $event): void
    {
        if (!$event instanceof ProductLinkChanged) {
            return;
        }
        $this->container->get(CacheStore::class)->invalidateTags(['thallo:shop:catalog:' . $event->tenant]);
    }
}
