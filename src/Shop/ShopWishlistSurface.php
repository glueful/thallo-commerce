<?php

declare(strict_types=1);

namespace Thallo\Commerce\Shop;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Tenancy\CommerceTenantResolution;
use Thallo\Contracts\Delivery\StorefrontWishlistResolver;

/**
 * Pack implementation of {@see StorefrontWishlistResolver} (storefront-v1 spec §5).
 *
 * The storage scope is the unpadded base64url of SHA-256 over
 * `"wishlist-v1\0" + normalizedTenant + "\0" + prefix` — deterministic per store, OPAQUE
 * (a digest, never the raw tenant uuid: it reaches the browser inside a localStorage key),
 * with the `''` sentinel tenant normalized to the literal `shared`. The tenant is resolved
 * LIVE inside every {@see self::storageScope()} call: {@see
 * \Thallo\Commerce\Tenancy\ThalloCommerceTenantResolution}'s contract explicitly forbids
 * caching a resolved tenant value, and this service is shared across requests — NEVER
 * capture the tenant string at construction or latch it on first call.
 *
 * Both methods re-evaluate `$capabilityEnabled` per call (the provider builds it from
 * {@see \Thallo\Contracts\Capability\CapabilityRegistry}): the binding itself is
 * unconditional — compiled services can't be capability-conditional — so, exactly like
 * {@see \Thallo\Commerce\Settings\SettingsStoreCommerceOverride}, the GATE lives here.
 * While `thallo.commerce` is off, both answers are null and every wishlist affordance
 * downstream simply disappears.
 */
final class ShopWishlistSurface implements StorefrontWishlistResolver
{
    /** @param \Closure(): bool $capabilityEnabled Re-checked on EVERY call, never latched. */
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly ShopUrlGenerator $urls,
        private readonly CommerceTenantResolution $tenants,
        private readonly \Closure $capabilityEnabled,
    ) {
    }

    public function storageScope(): ?string
    {
        if (!($this->capabilityEnabled)()) {
            return null;
        }
        $raw = $this->tenants->tenantUuid($this->context);
        $tenant = $raw === '' ? 'shared' : $raw;
        $digest = hash('sha256', "wishlist-v1\0" . $tenant . "\0" . $this->urls->prefix, true);
        return rtrim(strtr(base64_encode($digest), '+/', '-_'), '=');
    }

    public function wishlistUrl(): ?string
    {
        if (!($this->capabilityEnabled)()) {
            return null;
        }
        return $this->urls->wishlist();
    }
}
