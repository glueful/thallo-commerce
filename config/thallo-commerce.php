<?php

declare(strict_types=1);

return [
    // The real enable/disable switch is the host `thallo.capabilities` map (thallo.commerce).

    // Reconcile sweep (Task 9): `thallo:commerce:links:reconcile` batch size — how many stale
    // links (tombstoned product / missing entry) it removes per pass.
    'reconcile' => [
        'batch_size' => (int) env('THALLO_COMMERCE_RECONCILE_BATCH_SIZE', 500),
    ],

    // Task 7 (storefront-rendering spec §3): the catalog route prefix — ShopUrlGenerator is the
    // ONLY consumer of this value. Must normalize to exactly one non-empty path segment (no "/",
    // no whitespace); a bad value throws at boot (CommerceIntegrationServiceProvider::boot()),
    // never silently falls back.
    'shop_prefix' => env('THALLO_COMMERCE_SHOP_PREFIX', 'shop'),

    // Order-email per-template switches (store-settings spec §4.2 follow-up): defaults ON. The
    // Emails tab stores a '0' settings row (key EQUALS config key) to turn one off; clearing
    // deletes the row and these defaults show through. SendOrderEmails consults them per send.
    'email' => [
        'order_confirmation' => ['enabled' => true],
        'order_paid' => ['enabled' => true],
        'order_fulfilled' => ['enabled' => true],
        'order_canceled' => ['enabled' => true],
    ],

    // Task 8 (storefront-rendering spec §9): the dimension-complete shop catalog page cache
    // (index/product/category). false = exactly the uncached behavior. TTL is defense-in-depth
    // only — surrogate tags (thallo:shop:catalog:{tenant} / thallo:shop:catalog) do the real
    // invalidation.
    'shop_cache' => [
        'enabled' => env('THALLO_COMMERCE_SHOP_CACHE_ENABLED', true),
        'ttl' => (int) env('THALLO_COMMERCE_SHOP_CACHE_TTL', 3600),
    ],

    // Task 10 (storefront-rendering spec §6/§7): Commerce orders carry no general expiry, so
    // this defines the guest-order-cookie lifetime AND the checkout-attempt-ledger retention
    // window instead. Clamped 1-90 by every consumer
    // ({@see \Thallo\Commerce\Http\Shop\GuestOrderCookie::confirmationDays()}), never trusted
    // raw — a bad env value degrades to the nearest clamp bound, never a boot error.
    'guest_confirmation_days' => (int) env('THALLO_COMMERCE_GUEST_CONFIRMATION_DAYS', 30),
];
