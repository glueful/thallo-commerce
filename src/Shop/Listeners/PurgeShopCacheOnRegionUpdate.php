<?php

declare(strict_types=1);

namespace Thallo\Commerce\Shop\Listeners;

use Glueful\Cache\CacheStore;
use Psr\Container\ContainerInterface;
use Thallo\Contracts\Content\RegionUpdated;

/**
 * RegionUpdated → invalidateTags(['thallo:shop:catalog']).
 *
 * Header/footer chrome renders on shop pages too (they extend the same theme layout), but
 * those pages are held in the SHOP cache, not the render page cache. Before this listener,
 * `RegionUpdated` had a single subscriber — {@see \Thallo\Render\Listeners\PurgeRenderCacheOnRegionUpdate},
 * which purges `thallo:render:page` only — so removing a block from the header cleared it
 * everywhere EXCEPT `/shop`, `/shop/products/*`, `/cart` and friends, which kept serving the
 * old chrome until their own TTL lapsed or an unrelated catalog/theme change happened to
 * purge them.
 *
 * The event carries no tenant identity, so the GLOBAL catalog tag is the correct blast
 * radius — identical reasoning to {@see PurgeShopCacheOnThemeChange}, whose sibling this is.
 */
final class PurgeShopCacheOnRegionUpdate
{
    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public function onRegionUpdated(object $event): void
    {
        if (!$event instanceof RegionUpdated) {
            return;
        }
        $this->container->get(CacheStore::class)->invalidateTags(['thallo:shop:catalog']);
    }
}
