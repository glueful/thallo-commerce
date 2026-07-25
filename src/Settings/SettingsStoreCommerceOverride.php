<?php

declare(strict_types=1);

namespace Thallo\Commerce\Settings;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Support\CommerceSettingsOverride;
use Thallo\Contracts\Capability\CapabilityRegistry;

/**
 * Thallo's implementation of Commerce's settings seam (store-settings spec §3.3): store-level
 * commerce settings live as rows in the host's instance-settings store (tenant-owned under
 * tenancy enforcement, so values are per-workspace), read through the pack-owned
 * {@see CommerceSettingsStore} contract at USE time — every Commerce read site runs mid-request,
 * after tenant resolution, which is what makes a tenant-scoped store workable at all
 * (`overrideConfig()` is boot-only by design).
 *
 * Honors the contract's null-never-throw rule absolutely: a key outside the editable whitelist,
 * a blank row, an unbound store, a disabled capability, missing tenant context (scheduled expiry
 * sweeps), or ANY storage throwable (the tenancy query guard included) all resolve to null —
 * config()/env stays the always-working fallback and a settings problem can never 500 a
 * commerce request.
 */
final class SettingsStoreCommerceOverride implements CommerceSettingsOverride
{
    /** The editable whitelist (spec §3.1) — settings keys deliberately EQUAL config keys. */
    public const EDITABLE_KEYS = [
        'commerce.currency',
        'commerce.tax.flat_rate_bps',
        'commerce.orders.number_format',
        'commerce.orders.expiry_minutes',
        'commerce.cart.ttl_days',
        'commerce.reports.low_stock_threshold',
        // Download link lifetime (spec §3.6, Downloads group): consulted by both signed-URL
        // producers via CommerceSettings::downloadsUrlTtl (commerce ≥ 1.7.0).
        'commerce.downloads.url_ttl',
        // Marketplace master switch (spec §3.6): backs MarketplaceMode::installEnabled()
        // (commerce ≥ 1.7.0) — the Marketplace tab's runtime on/off; env stays the default.
        'commerce.marketplace.enabled',
        // Store identity (spec §3.6): the invoice header — name, address, tax id.
        'commerce.seller.name',
        'commerce.seller.address',
        'commerce.seller.tax_id',
    ];

    public function value(ApplicationContext $context, string $key): ?string
    {
        if (!in_array($key, self::EDITABLE_KEYS, true)) {
            return null;
        }

        try {
            $container = $context->getContainer();

            // The binding itself is unconditional (compiled services can't be capability-
            // conditional), so the GATE lives here: with thallo.commerce disabled every read
            // resolves to "no override" and commerce sees pure config — spec §3.3's observable
            // behavior, achieved at value() time instead of bind time.
            if (
                $container->has(CapabilityRegistry::class)
                && !$container->get(CapabilityRegistry::class)->isEnabled('thallo.commerce')
            ) {
                return null;
            }

            if (!$container->has(CommerceSettingsStore::class)) {
                return null;
            }
            $value = $container->get(CommerceSettingsStore::class)->get($key);

            return is_string($value) && trim($value) !== '' ? $value : null;
        } catch (\Throwable) {
            // Absent tenant context, guard rejection, missing table — all mean "no override".
            return null;
        }
    }
}
