<?php

declare(strict_types=1);

namespace Thallo\Commerce\Shop\Contribution;

use Thallo\Render\Contribution\ReservedPathContributor;

/**
 * Reserves the shop prefix segment plus the root-level workflow paths (storefront-rendering
 * spec §3/§5.1) so Render's `/{path}` catch-all can never serve a builder page at any of them —
 * registered UNCONDITIONALLY (outside the `thallo.commerce` capability gate,
 * {@see \Thallo\Commerce\CommerceIntegrationServiceProvider::boot()}) so disabling the
 * capability still keeps every one of them reserved rather than un-shadowing a stray page.
 * `{prefix}`, `_shop`, and `checkout` are PATH-SEGMENT prefixes (reserving `/{prefix}/...`,
 * `/_shop/...` — the catalog namespace and every `/_shop/cart/*`, `/_shop/checkout/*`,
 * `/_shop/assets/*` endpoint alike — and `/checkout/...`, task 10's `GET /checkout` page plus
 * its `/checkout/return/{ref}`, `/checkout/cancel/{ref}`, `/checkout/confirmation/{ref}`
 * children); `cart` is a single EXACT root-level path (task 9's `GET /cart` page only, no
 * children).
 */
final class ShopReservedPathContributor implements ReservedPathContributor
{
    public function __construct(private readonly string $prefix)
    {
    }

    public function contributorId(): string
    {
        return 'thallo-commerce.shop';
    }

    public function priority(): int
    {
        return 0;
    }

    /** @return list<string> */
    public function reservedPrefixes(): array
    {
        return [$this->prefix, '_shop', 'checkout'];
    }

    /** @return list<string> */
    public function reservedExacts(): array
    {
        return ['cart'];
    }
}
