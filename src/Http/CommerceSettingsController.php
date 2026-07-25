<?php

declare(strict_types=1);

namespace Thallo\Commerce\Http;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Support\CommerceSettings;
use Glueful\Extensions\Commerce\Tenancy\CommerceTenantResolution;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Commerce\Settings\CommerceSettingsStore;
use Thallo\Commerce\Settings\SettingsStoreCommerceOverride;

/**
 * Store settings admin API (store-settings spec §3.4): `GET/PUT /v1/admin/commerce/settings`.
 * The editable set is exactly {@see SettingsStoreCommerceOverride::EDITABLE_KEYS} — settings
 * keys EQUAL config keys, values live as rows in thallo's `settings` table (tenant-scoped under
 * enforcement), and clearing a field DELETES its row so the config/env default shows through
 * (never an empty-string shadow).
 *
 * The currency lock (spec §3.4, revised — user feedback 2026-07-25: "I'm still setting my
 * store up, why lock?"): the harm is RECORDED money, not draft prices — orders/refunds/reports
 * store amounts as integers in the order's currency, so the lock's predicate is
 * {@see OrderRepository::anyExistsForTenant} (any durable order history), not catalog contents.
 * While UNLOCKED, a currency change also rewrites every variant's currency CODE
 * ({@see VariantRepository::reassignCurrencyForTenant} — amounts kept exactly as typed:
 * reinterpretation, never conversion), because checkout hard-rejects variants whose currency
 * doesn't match the store; without the rewrite a setup-time change would brick every existing
 * product. Only an actual CHANGE trips the lock — idempotent saves never 422.
 *
 * Storage flows through the pack-owned {@see CommerceSettingsStore} contract the host app
 * binds — this package never names an app class (InertnessTest's rule).
 */
final class CommerceSettingsController
{
    private const CURRENCY_LOCK_MESSAGE =
        'Currency is locked once orders exist — recorded order amounts are integers in the order’s currency.';

    public function __construct(
        private readonly ApplicationContext $context,
        private readonly CommerceTenantResolution $tenants,
        private readonly VariantRepository $variants,
        private readonly ?OrderRepository $orders = null,
        private readonly ?CommerceSettingsStore $store = null,
    ) {
    }

    #[ApiOperation(
        summary: 'Get store settings (effective, default, overridden per key)',
        tags: ['Thallo Commerce'],
    )]
    public function show(Request $request): Response
    {
        $settings = [];
        foreach (SettingsStoreCommerceOverride::EDITABLE_KEYS as $key) {
            $settings[$key] = [
                'value' => $this->effective($key),
                'default' => $this->configDefault($key),
                'overridden' => $this->storedValue($key) !== null,
            ];
        }

        return Response::success([
            'settings' => $settings,
            'currency_locked' => $this->currencyLocked(),
            // For the UI's honesty note: an UNLOCKED change with priced products reinterprets
            // their numbers ($700.00 becomes GH₵700.00) — worth a warning, not a lock.
            'has_priced_products' => $this->variants->anyExistsForTenant(
                $this->context,
                $this->tenants->tenantUuid($this->context),
            ),
        ], 'Store settings retrieved');
    }

    #[ApiOperation(
        summary: 'Update store settings (null/blank clears a field back to its default)',
        tags: ['Thallo Commerce'],
    )]
    public function update(Request $request): Response
    {
        $body = (array) json_decode((string) $request->getContent(), true);

        $puts = [];
        $forgets = [];
        foreach (SettingsStoreCommerceOverride::EDITABLE_KEYS as $key) {
            if (!array_key_exists($key, $body)) {
                continue; // absent = untouched
            }
            $raw = $body[$key];
            if ($raw === null || (is_string($raw) && trim($raw) === '')) {
                $forgets[] = $key;
                continue;
            }
            $puts[$key] = $this->validate($key, $raw);
        }

        if (isset($puts['commerce.currency'])) {
            $current = CommerceSettings::currency($this->context);
            if ($puts['commerce.currency'] !== $current && $this->currencyLocked()) {
                throw ValidationException::forField('commerce.currency', self::CURRENCY_LOCK_MESSAGE);
            }
        }
        // Clearing the currency override changes the effective value too (back to config) —
        // the lock applies equally, unless config already equals the stored effective value.
        if (in_array('commerce.currency', $forgets, true)) {
            $default = $this->configDefault('commerce.currency');
            if ($default !== CommerceSettings::currency($this->context) && $this->currencyLocked()) {
                throw ValidationException::forField('commerce.currency', self::CURRENCY_LOCK_MESSAGE);
            }
        }

        $currencyBefore = CommerceSettings::currency($this->context);

        $store = $this->store();
        foreach ($forgets as $key) {
            $store->forget($key);
        }
        if ($puts !== []) {
            $store->putMany($puts);
        }

        // Setup-time currency change (unlocked by definition — a locked change threw above):
        // rewrite every variant's currency code so checkout's store-vs-variant currency guard
        // keeps accepting existing products. Amounts stay exactly as typed.
        $currencyAfter = CommerceSettings::currency($this->context);
        if ($currencyAfter !== $currencyBefore) {
            $this->variants->reassignCurrencyForTenant(
                $this->context,
                $this->tenants->tenantUuid($this->context),
                $currencyAfter,
            );
        }

        return $this->show($request);
    }

    /** Validates one field per spec §3.1, returning the canonical STRING to store. */
    private function validate(string $key, mixed $raw): string
    {
        if (!is_string($raw) && !is_int($raw)) {
            throw ValidationException::forField($key, 'Must be a string or integer value.');
        }
        $value = trim((string) $raw);

        if ($key === 'commerce.currency') {
            $value = strtoupper($value);
            if (preg_match('/^[A-Z]{3}$/', $value) !== 1) {
                throw ValidationException::forField($key, 'Currency must be a 3-letter ISO code.');
            }
            return $value;
        }

        if (in_array($key, ['commerce.seller.name', 'commerce.seller.address', 'commerce.seller.tax_id'], true)) {
            $max = match ($key) {
                'commerce.seller.name' => 200,
                'commerce.seller.address' => 500,
                default => 64,
            };
            if (mb_strlen($value) > $max) {
                throw ValidationException::forField($key, "Must be {$max} characters or fewer.");
            }
            return $value;
        }

        if ($key === 'commerce.orders.number_format') {
            if ($value === '' || !str_contains($value, '{seq}')) {
                throw ValidationException::forField($key, 'Order number format must contain {seq}.');
            }
            return $value;
        }

        // The four integer fields share shape; bounds differ (spec §3.1).
        if (preg_match('/^\d+$/', $value) !== 1) {
            throw ValidationException::forField($key, 'Must be a whole number.');
        }
        $int = (int) $value;
        [$min, $max] = match ($key) {
            'commerce.tax.flat_rate_bps' => [0, 10000],
            'commerce.orders.expiry_minutes' => [5, 10080],
            'commerce.cart.ttl_days' => [1, 365],
            'commerce.reports.low_stock_threshold' => [0, 1000],
            default => throw ValidationException::forField($key, 'Unknown setting.'),
        };
        if ($int < $min || $int > $max) {
            throw ValidationException::forField($key, "Must be between {$min} and {$max}.");
        }

        return (string) $int;
    }

    private function currencyLocked(): bool
    {
        return ($this->orders ?? new OrderRepository())->anyExistsForTenant(
            $this->context,
            $this->tenants->tenantUuid($this->context),
        );
    }

    private function effective(string $key): string|int
    {
        return match ($key) {
            'commerce.currency' => CommerceSettings::currency($this->context),
            'commerce.tax.flat_rate_bps' => CommerceSettings::taxFlatRateBps($this->context),
            'commerce.orders.number_format' => CommerceSettings::orderNumberFormat($this->context),
            'commerce.orders.expiry_minutes' => CommerceSettings::orderExpiryMinutes($this->context),
            'commerce.cart.ttl_days' => CommerceSettings::cartTtlDays($this->context),
            'commerce.reports.low_stock_threshold' => CommerceSettings::lowStockThreshold($this->context),
            // Null-tolerant identity keys serialize as '' on the wire (JSON-friendly).
            'commerce.seller.name' => CommerceSettings::sellerName($this->context) ?? '',
            'commerce.seller.address' => CommerceSettings::sellerAddress($this->context) ?? '',
            'commerce.seller.tax_id' => CommerceSettings::sellerTaxId($this->context) ?? '',
            default => '',
        };
    }

    private function configDefault(string $key): string|int
    {
        return match ($key) {
            'commerce.currency' => (string) config($this->context, $key, 'USD'),
            'commerce.orders.number_format' => (string) config($this->context, $key, 'ORD-{seq}'),
            'commerce.tax.flat_rate_bps' => (int) config($this->context, $key, 0),
            'commerce.orders.expiry_minutes' => (int) config($this->context, $key, 60),
            'commerce.cart.ttl_days' => (int) config($this->context, $key, 30),
            'commerce.reports.low_stock_threshold' => (int) config($this->context, $key, 2),
            'commerce.seller.name',
            'commerce.seller.address',
            'commerce.seller.tax_id' => (string) (config($this->context, $key) ?? ''),
            default => '',
        };
    }

    private function storedValue(string $key): ?string
    {
        try {
            $value = $this->store()->get($key);

            return is_string($value) && trim($value) !== '' ? $value : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function store(): CommerceSettingsStore
    {
        if ($this->store === null) {
            throw new \RuntimeException('Settings store is not available.');
        }

        return $this->store;
    }
}
