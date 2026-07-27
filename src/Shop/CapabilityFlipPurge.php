<?php

declare(strict_types=1);

namespace Thallo\Commerce\Shop;

use Glueful\Cache\CacheStore;
use Glueful\Cache\Contracts\EdgeCacheInterface;

/**
 * Boot-time capability-flip reconciler: when the `thallo.commerce` enabled state changes
 * between boots (the capability is deploy-time config — there is no runtime toggle event to
 * listen for), previously cached rendered pages still carry the OLD boundary: shop block
 * shells + the `/_shop/assets/shop.js` script tag after a disable, or the missing-template
 * fallback comments after a re-enable. Neither may keep serving — "capability off" means
 * commerce absent from the rendered page, immediately.
 *
 * Mechanism: a plain UNTAGGED marker key records the last-seen state. On a flip, purge the
 * broad `thallo:render:page` tag (every cached rendered page, all tenants — same global tag
 * the theme/region/menu listeners purge) and, with {@see PurgeCdnListener}'s disabled-skip
 * discipline, purge the edge (`purgeAll()` — the CDN's Cache-Tag header carries per-entry/type
 * surrogate keys only, and an install-wide structural flip warrants the blunt primitive).
 * The marker is untagged so the purge can never delete its own bookkeeping.
 *
 * Marker absent (first boot ever, or the local store was flushed) → record state, no purge:
 * a flushed local cache holds nothing stale. Accepted limitation, documented: if a flush and
 * a flip coincide, the edge misses this purge (the local store is the only memory we have).
 *
 * Purges only — stored block/link/catalog data is NEVER touched here or anywhere else in the
 * disable path (design pin: disabling removes the rendered integration, not data).
 */
final class CapabilityFlipPurge
{
    public const MARKER_KEY = 'thallo:commerce:capability-state';

    public function __construct(
        private readonly CacheStore $cache,
        private readonly ?EdgeCacheInterface $edge = null,
    ) {
    }

    public function reconcile(bool $enabled): void
    {
        $current = $enabled ? 'on' : 'off';
        $last = $this->cache->get(self::MARKER_KEY);
        if ($last === $current) {
            return;
        }

        if (is_string($last)) {
            $this->cache->invalidateTags(['thallo:render:page']);
            if ($this->edge !== null && $this->edge->isEnabled()) {
                $this->edge->purgeAll();
            }
        }

        $this->cache->set(self::MARKER_KEY, $current);
    }
}
