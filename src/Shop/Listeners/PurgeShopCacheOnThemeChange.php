<?php

declare(strict_types=1);

namespace Thallo\Commerce\Shop\Listeners;

use Glueful\Cache\CacheStore;
use Psr\Container\ContainerInterface;
use Thallo\Contracts\Settings\ThemeChanged;

/**
 * ThemeChanged -> invalidateTags(['thallo:shop:catalog']) (storefront-rendering spec §9). This
 * event carries no tenant identity, so every tenant's catalog cache purges via the GLOBAL tag —
 * mirrors {@see \Thallo\Render\Listeners\PurgeRenderCacheOnThemeChange}'s identical reasoning.
 * Cache keys already carry the theme name, so stale entries were never servable under the new
 * theme regardless; this purge is pure hygiene for the old theme's keys.
 */
final class PurgeShopCacheOnThemeChange
{
    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public function onThemeChanged(object $event): void
    {
        if (!$event instanceof ThemeChanged) {
            return;
        }
        $this->container->get(CacheStore::class)->invalidateTags(['thallo:shop:catalog']);
    }
}
