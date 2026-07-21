<?php

declare(strict_types=1);

namespace Thallo\Commerce\Shop\Listeners;

use Glueful\Cache\CacheStore;
use Psr\Container\ContainerInterface;
use Thallo\Contracts\Settings\ThemeAppearanceChanged;

/**
 * ThemeAppearanceChanged -> invalidateTags(['thallo:shop:catalog']) (storefront-rendering spec
 * §9). Tenantless, same reasoning as {@see PurgeShopCacheOnThemeChange}: cache keys already
 * carry the accent-neutral fingerprint, so this is hygiene for the old pair's keys, purged via
 * the global tag since the event carries no tenant identity.
 */
final class PurgeShopCacheOnAppearanceChange
{
    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public function onAppearanceChanged(object $event): void
    {
        if (!$event instanceof ThemeAppearanceChanged) {
            return;
        }
        $this->container->get(CacheStore::class)->invalidateTags(['thallo:shop:catalog']);
    }
}
