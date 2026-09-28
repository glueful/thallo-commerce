<?php

declare(strict_types=1);

namespace Thallo\Commerce\Shop\Listeners;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Cache\CacheStore;
use Glueful\Extensions\Commerce\Tenancy\CommerceTenantResolution;
use Psr\Container\ContainerInterface;
use Thallo\Commerce\Layouts\ShopLayoutTags;
use Thallo\Contracts\Layouts\LayoutChanged;

/**
 * LayoutChanged (a commerce surface: the product page, the shop home or the category pages) → the
 * workspace's pages of that surface leave the shop cache (type layouts spec §7.4, plans C1 and C2).
 *
 * First the workspace's layout generation for the surface is replaced ({@see ShopLayoutTags}): the
 * shop cache keys the surface's pages by it, read before the page renders, so a render that read the
 * old layout — before this change committed — stores under a token no request reads again, and the
 * next request renders the new layout. Then the old entries are freed: every such page carries
 * {@see ShopLayoutTags::tenantTag()} for its workspace, and on a driver without tag invalidation (the
 * default file driver) that workspace's shop pages are deleted instead, as
 * {@see PurgeShopCacheOnCatalogChange} falls back. Another workspace's pages, and the workspace's
 * pages of the other surfaces, stay.
 *
 * The event names the workspace while tenancy is on; otherwise the shop's own resolution does (the
 * single store's default tenant).
 */
final class PurgeShopCacheOnLayoutChange
{
    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public function onLayoutChanged(object $event): void
    {
        if (!$event instanceof LayoutChanged || !in_array($event->surface, ShopLayoutTags::SURFACES, true)) {
            return;
        }
        $tenant = $event->tenantUuid ?? $this->container->get(CommerceTenantResolution::class)
            ->tenantUuid($this->container->get(ApplicationContext::class));
        $cache = $this->container->get(CacheStore::class);
        // The generation first (type layouts plan C2): once replaced, a render that read the old
        // layout stores under a token no request reads — whatever the driver, whatever the purge.
        $cache->set(
            ShopLayoutTags::generationKey($event->surface, $tenant),
            ShopLayoutTags::freshGeneration(),
            ShopLayoutTags::GENERATION_TTL,
        );
        if ($cache->invalidateTags([ShopLayoutTags::tenantTag($event->surface, $tenant)])) {
            return;
        }
        $cache->deletePattern('shop:' . $tenant . ':*');
        $cache->deletePattern('tenant:*:shop:' . $tenant . ':*');
    }
}
