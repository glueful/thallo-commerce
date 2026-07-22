<?php

declare(strict_types=1);

namespace Thallo\Commerce\Shop;

use Glueful\Bootstrap\ApplicationContext;
use Thallo\Contracts\Delivery\CanonicalPublicOriginResolver;

/**
 * The ONLY place absolute storefront URLs are assembled (Thallo admin-commerce-area plan, slice
 * 3, task 5) — every admin surface that needs a real, clickable link to the live storefront (e.g.
 * a "preview" link on a product's admin page) goes through this class; nobody else concatenates
 * an origin onto a shop path by hand.
 *
 * Pure composition, nothing more: {@see CanonicalPublicOriginResolver} (task 6) is the ONE trusted
 * origin authority — normalized `scheme://host[:port]`, no trailing slash, and it never reads the
 * incoming request's `Host` header, so a hostile `Host` can never spoof the origin half of the
 * URL. {@see ShopUrlGenerator} (task 7) is the ONE source of `/`-prefixed relative shop paths.
 * This class does nothing but concatenate the two — no caching, no validation, no fallback logic
 * of its own.
 */
final class StorefrontPreviewUrlBuilder
{
    public function __construct(
        private readonly CanonicalPublicOriginResolver $origins,
        private readonly ShopUrlGenerator $urls,
    ) {
    }

    public function shopIndexUrl(ApplicationContext $c): string
    {
        return $this->origins->currentOrigin($c) . $this->urls->shopIndex();
    }

    public function productUrl(ApplicationContext $c, string $slug): string
    {
        return $this->origins->currentOrigin($c) . $this->urls->product($slug);
    }
}
