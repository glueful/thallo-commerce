<?php

declare(strict_types=1);

namespace Thallo\Commerce\Shop\ViewModels;

/**
 * Closed view model for `GET /checkout` (storefront-rendering spec §3/§6/§11): wraps the current
 * cart summary ({@see CartViewModel}, the SAME shape `/cart` and the mini-cart already use) plus
 * the idempotency key minted for the no-JS checkout form (spec §7: "the no-JS checkout form
 * carries one minted on the private/no-store checkout page"). The enhanced-JS path generates its
 * own key per checkout intent and never reads this one.
 */
final class CheckoutViewModel
{
    public function __construct(
        public readonly CartViewModel $cart,
        public readonly string $idempotencyKey,
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'cart' => $this->cart->toArray(),
            'idempotency_key' => $this->idempotencyKey,
        ];
    }
}
