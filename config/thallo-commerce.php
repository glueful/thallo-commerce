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
        // Payment links Task 12 (payment-links spec §2.4): the ONE template here that defaults
        // OFF, and the omission of this key is FORBIDDEN rather than merely untidy — the Emails
        // tab's generic fallback is `true`, so leaving it out would silently arm a surface that
        // emails a live bearer credential on an install that never opted in.
        'payment_request' => ['enabled' => false],
    ],

    // Payment links Task 12 (payment-links spec §2.4): how long a `processing` delivery claim in
    // `thallo_commerce_payment_link_deliveries` is still treated as "an attempt may genuinely be
    // in flight" before it is reported `indeterminate`. Clamped 60-3600 by every consumer
    // ({@see \Thallo\Commerce\Payments\PaymentLinkDeliveryRepository::staleSeconds()}), never
    // trusted raw — a bad env value degrades to the nearest bound, never a boot error or a
    // disabled send endpoint.
    'payment_links' => [
        'delivery_processing_stale_seconds' => (int) env(
            'THALLO_COMMERCE_DELIVERY_PROCESSING_STALE_SECONDS',
            300,
        ),
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
