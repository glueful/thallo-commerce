<?php

declare(strict_types=1);

namespace Thallo\Commerce\Shop;

use Thallo\Contracts\Delivery\StorefrontLinkResolver;

/**
 * Thin adapter (Commerce-Slice-2 Fix A): {@see StorefrontLinkResolver} implemented purely by
 * delegating to {@see ShopUrlGenerator} — no new logic, no new state. Exists only so the render
 * pack can soft-bind the contract without ever importing `ShopUrlGenerator` itself (which lives
 * in this pack, one layer below thallo-render in the dependency graph).
 */
final class ShopStorefrontLinkResolver implements StorefrontLinkResolver
{
    /** @param \Closure(): bool $capabilityEnabled re-read per call, so a flip is honored at once */
    public function __construct(
        private readonly ShopUrlGenerator $urls,
        private readonly ?\Closure $capabilityEnabled = null,
    ) {
    }

    public function stylesheetUrl(): ?string
    {
        if ($this->capabilityEnabled !== null && !($this->capabilityEnabled)()) {
            return null;
        }

        return $this->urls->stylesheet();
    }

    public function productUrl(string $slug): string
    {
        return $this->urls->product($slug);
    }

    public function categoryUrl(string $slug): string
    {
        return $this->urls->category($slug);
    }

    public function shopIndexUrl(): string
    {
        return $this->urls->shopIndex();
    }
}
