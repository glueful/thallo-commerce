<?php

declare(strict_types=1);

namespace Thallo\Commerce\Shop;

use Glueful\Cache\CacheStore;
use Thallo\Commerce\Layouts\ShopLayoutTags;

/**
 * A workspace's catalog generation (product grid spec §3.2): a random token every storefront
 * catalog change replaces. A grid reads it before querying products and records it as a cache
 * guard, so a page rendered from the old catalog is refused on read once the token moves.
 */
final class CatalogGeneration
{
    public const TTL = ShopLayoutTags::GENERATION_TTL;

    public function __construct(private readonly CacheStore $cache)
    {
    }

    public static function key(string $tenant): string
    {
        return 'thallo:cataloggen:shop:' . $tenant;
    }

    /** The stored token; a missing one is created (setNx) and re-read; null when unreadable. */
    public function read(string $tenant): ?string
    {
        $key = self::key($tenant);
        $token = $this->cache->get($key);
        if (ShopLayoutTags::isGeneration($token)) {
            return $token;
        }
        $this->cache->setNx($key, ShopLayoutTags::freshGeneration(), self::TTL);
        $token = $this->cache->get($key);
        return ShopLayoutTags::isGeneration($token) ? $token : null;
    }

    public function rotate(string $tenant): void
    {
        $this->cache->set(self::key($tenant), ShopLayoutTags::freshGeneration(), self::TTL);
    }
}
