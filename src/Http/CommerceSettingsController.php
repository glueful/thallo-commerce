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
use Thallo\Commerce\Settings\InvoiceLogoResolver;
use Thallo\Commerce\Settings\SettingsStoreCommerceOverride;
use Thallo\Commerce\Shop\ShopUrlGenerator;

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

    /** Boolean-typed editable keys (Task 6): validated/read as real booleans, stored as '1'|'0'. */
    private const BOOLEAN_KEYS = [
        'commerce.invoice.show_sku',
        'commerce.invoice.show_addresses',
        'commerce.invoice.show_tax_id',
    ];

    private const INVOICE_PAPER_PRESETS = ['a4', 'thermal_80', 'thermal_58'];

    public function __construct(
        private readonly ApplicationContext $context,
        private readonly CommerceTenantResolution $tenants,
        private readonly VariantRepository $variants,
        private readonly ?OrderRepository $orders = null,
        private readonly ?CommerceSettingsStore $store = null,
        /** The one shop-path authority (bound unconditionally); soft-bound like its siblings. */
        private readonly ?ShopUrlGenerator $urls = null,
        /** Task 6: the one ownership+servability authority for the invoice logo blob uuid. */
        private readonly ?InvoiceLogoResolver $invoiceLogo = null,
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

        $payload = [
            'settings' => $settings,
            // Derived, non-editable (Task 6): the SPA and print view consume ONLY this URL and
            // never synthesize one from the stored uuid. An invalid/deleted/unresolvable stored
            // uuid resolves to null here WITHOUT mutating the stored setting itself.
            'invoice_logo_url' => $this->invoiceLogoUrl(),
            'currency_locked' => $this->currencyLocked(),
            // For the UI's honesty note: an UNLOCKED change with priced products reinterprets
            // their numbers ($700.00 becomes GH₵700.00) — worth a warning, not a lock.
            'has_priced_products' => $this->variants->anyExistsForTenant(
                $this->context,
                $this->tenants->tenantUuid($this->context),
            ),
        ];

        // The default store pages (account-form-blocks plan Task 1): a FIXED, allowlisted
        // inventory — every path from ShopUrlGenerator (the single prefix authority), never the
        // router. Parameterized pages and per-order transitional hops are deliberately absent.
        if ($this->urls !== null) {
            $payload['pages'] = [
                ['label' => 'Shop', 'path' => $this->urls->shopIndex()],
                ['label' => 'Wishlist', 'path' => $this->urls->wishlist()],
                ['label' => 'Cart', 'path' => $this->urls->cart()],
                ['label' => 'Checkout', 'path' => $this->urls->checkout()],
            ];
        }

        return Response::success($payload, 'Store settings retrieved');
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
        // Boolean keys branch BEFORE the generic string/int guard below (Task 6) — a real JSON
        // boolean is neither is_string() nor is_int(), so the generic guard would otherwise
        // reject the controller's own established boolean-input shape (mirrors
        // MarketplaceSettingsController::setMaster / EmailSettingsController::update).
        if (in_array($key, self::BOOLEAN_KEYS, true)) {
            if (!is_bool($raw)) {
                throw ValidationException::forField($key, 'Must be true or false.');
            }
            return $raw ? '1' : '0';
        }

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

        // Invoice footer (Task 6): plain text only — a stray '<' is REFUSED outright, never
        // stripped, so a merchant never gets surprised by silently mangled text on a printed
        // receipt.
        if ($key === 'commerce.invoice.footer_text') {
            if (mb_strlen($value) > 500) {
                throw ValidationException::forField($key, 'Must be 500 characters or fewer.');
            }
            if (str_contains($value, '<')) {
                throw ValidationException::forField($key, 'Must be plain text (no "<").');
            }
            return $value;
        }

        // Print paper preset (Task 6): a closed enum — anything else is a 422, not a silent
        // fallback to the default.
        if ($key === 'commerce.invoice.paper_preset') {
            if (!in_array($value, self::INVOICE_PAPER_PRESETS, true)) {
                throw ValidationException::forField(
                    $key,
                    'Must be one of: ' . implode(', ', self::INVOICE_PAPER_PRESETS) . '.',
                );
            }
            return $value;
        }

        // Invoice logo (Task 6): InvoiceLogoResolver is the ONE ownership+servability authority
        // — a uuid that doesn't resolve to a servable public image (missing, non-image, private,
        // inactive, deleted, or cross-tenant) is refused here rather than stored blind.
        if ($key === 'commerce.invoice.logo_blob_uuid') {
            // resolveOrFail(), not resolve(): a genuine DB/policy fault here must propagate and
            // refuse the save loudly, never be swallowed into this ordinary "not servable" 422.
            if ($this->invoiceLogoResolver()->resolveOrFail($value) === null) {
                throw ValidationException::forField($key, 'Must be a public image you own.');
            }
            return $value;
        }

        // The integer fields share shape; bounds differ (spec §3.1).
        if (preg_match('/^\d+$/', $value) !== 1) {
            throw ValidationException::forField($key, 'Must be a whole number.');
        }
        $int = (int) $value;
        [$min, $max] = match ($key) {
            'commerce.tax.flat_rate_bps' => [0, 10000],
            'commerce.orders.expiry_minutes' => [5, 10080],
            'commerce.cart.ttl_days' => [1, 365],
            'commerce.reports.low_stock_threshold' => [0, 1000],
            // Download links: 1 minute to 1 week — long enough for email delivery, short
            // enough that a leaked URL ages out.
            'commerce.downloads.url_ttl' => [60, 604800],
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

    private function effective(string $key): string|int|bool
    {
        return match ($key) {
            'commerce.currency' => CommerceSettings::currency($this->context),
            'commerce.tax.flat_rate_bps' => CommerceSettings::taxFlatRateBps($this->context),
            'commerce.orders.number_format' => CommerceSettings::orderNumberFormat($this->context),
            'commerce.orders.expiry_minutes' => CommerceSettings::orderExpiryMinutes($this->context),
            'commerce.cart.ttl_days' => CommerceSettings::cartTtlDays($this->context),
            'commerce.reports.low_stock_threshold' => CommerceSettings::lowStockThreshold($this->context),
            // Self-computed (stored-valid ?? config) rather than CommerceSettings::downloadsUrlTtl —
            // that reader ships in commerce 1.7.0; this stays correct on 1.6.x too.
            'commerce.downloads.url_ttl' => $this->intEffective('commerce.downloads.url_ttl', 300),
            // Null-tolerant identity keys serialize as '' on the wire (JSON-friendly).
            'commerce.seller.name' => CommerceSettings::sellerName($this->context) ?? '',
            'commerce.seller.address' => CommerceSettings::sellerAddress($this->context) ?? '',
            'commerce.seller.tax_id' => CommerceSettings::sellerTaxId($this->context) ?? '',
            // Invoice & receipt branding (Task 6). The logo/footer are null-tolerant identity
            // strings like the seller fields above; the toggles/preset follow the same
            // stored-valid ?? config pattern as commerce.downloads.url_ttl.
            'commerce.invoice.logo_blob_uuid' => $this->storedValue($key) ?? '',
            'commerce.invoice.footer_text' => $this->storedValue($key) ?? '',
            'commerce.invoice.show_sku' => $this->boolEffective($key, true),
            'commerce.invoice.show_addresses' => $this->boolEffective($key, true),
            'commerce.invoice.show_tax_id' => $this->boolEffective($key, true),
            'commerce.invoice.paper_preset' => $this->storedValue($key)
                ?? (string) config($this->context, $key, 'a4'),
            default => '',
        };
    }

    private function configDefault(string $key): string|int|bool
    {
        return match ($key) {
            'commerce.currency' => (string) config($this->context, $key, 'USD'),
            'commerce.orders.number_format' => (string) config($this->context, $key, 'ORD-{seq}'),
            'commerce.tax.flat_rate_bps' => (int) config($this->context, $key, 0),
            'commerce.orders.expiry_minutes' => (int) config($this->context, $key, 60),
            'commerce.cart.ttl_days' => (int) config($this->context, $key, 30),
            'commerce.reports.low_stock_threshold' => (int) config($this->context, $key, 2),
            'commerce.downloads.url_ttl' => (int) config($this->context, $key, 300),
            'commerce.seller.name',
            'commerce.seller.address',
            'commerce.seller.tax_id' => (string) (config($this->context, $key) ?? ''),
            'commerce.invoice.logo_blob_uuid',
            'commerce.invoice.footer_text' => (string) (config($this->context, $key) ?? ''),
            'commerce.invoice.paper_preset' => (string) config($this->context, $key, 'a4'),
            'commerce.invoice.show_sku',
            'commerce.invoice.show_addresses',
            'commerce.invoice.show_tax_id' => $this->configBool($key, true),
            default => '',
        };
    }

    /** Stored row when it parses as a well-formed flag, else the config default. */
    private function boolEffective(string $key, bool $default): bool
    {
        $stored = $this->storedValue($key);
        if ($stored !== null) {
            $flag = strtolower(trim($stored));
            if (in_array($flag, ['1', 'true'], true)) {
                return true;
            }
            if (in_array($flag, ['0', 'false'], true)) {
                return false;
            }
        }

        return $this->configBool($key, $default);
    }

    /**
     * A raw `(bool)` cast on a config value is wrong for a string like `'false'` (a non-empty
     * string casts to `true`) — parse it the same string-aware way as a stored flag, and only
     * fall back to a plain bool cast for an already-boolean config value.
     */
    private function configBool(string $key, bool $default): bool
    {
        $value = config($this->context, $key, $default);
        if (is_string($value)) {
            $flag = strtolower(trim($value));
            if (in_array($flag, ['1', 'true'], true)) {
                return true;
            }
            if (in_array($flag, ['0', 'false'], true)) {
                return false;
            }
        }

        return (bool) $value;
    }

    /**
     * Task 6: the derived, non-editable invoice logo URL — the ONE thing the SPA/print view
     * consume. A missing setting, a missing resolver (uninjected in a bare test construction),
     * or ANY unservable stored uuid all resolve to null WITHOUT mutating the stored setting.
     */
    private function invoiceLogoUrl(): ?string
    {
        $uuid = $this->storedValue('commerce.invoice.logo_blob_uuid');
        if ($uuid === null || $this->invoiceLogo === null) {
            return null;
        }

        return $this->invoiceLogo->resolve($uuid);
    }

    private function invoiceLogoResolver(): InvoiceLogoResolver
    {
        if ($this->invoiceLogo === null) {
            throw new \RuntimeException('Invoice logo resolver is not available.');
        }

        return $this->invoiceLogo;
    }

    /** Stored row when it parses as an int, else the config default — seam-equivalent math. */
    private function intEffective(string $key, int $default): int
    {
        $stored = $this->storedValue($key);
        if ($stored !== null && preg_match('/^\d+$/', trim($stored)) === 1) {
            return (int) trim($stored);
        }

        return (int) config($this->context, $key, $default);
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
