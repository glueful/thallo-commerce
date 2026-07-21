<?php

declare(strict_types=1);

namespace Thallo\Commerce\Shop\ViewModels;

use Glueful\Extensions\Commerce\Support\Money;
use Thallo\Commerce\Shop\ShopUrlGenerator;

/**
 * Closed storefront projection of a commerce product (storefront-rendering spec §6: "every
 * `/_shop`/shop response is a closed view model — never raw commerce rows"). Every property is
 * assigned explicitly from an allowlisted field in {@see self::fromRow()} — the raw product row
 * (which carries `tenant_uuid`, `seller_uuid`, `catalog_revision`, `tax_class`, `rating_sum`/
 * `rating_count`, `metadata`, `updated_at`/`deleted_at`, …) is never spread or passed through, so
 * an internal column can never leak just because a future admin-only field is added upstream.
 */
final class ProductViewModel
{
    /** @param array{average: float, count: int}|null $rating */
    public function __construct(
        public readonly string $uuid,
        public readonly string $slug,
        public readonly string $name,
        public readonly ?string $description,
        public readonly string $type,
        public readonly ?int $priceFrom,
        public readonly ?string $priceFormatted,
        public readonly ?string $currency,
        public readonly ?string $coverUrl,
        public readonly ?array $rating,
        public readonly string $url,
        /**
         * The server-rendered no-JS add-to-cart decision (Commerce-Slice-2 Fix A) — null for
         * every projection that never needs it (shop index/category grid items, the
         * featured-product block's JSON), non-null ONLY for the product detail page, which is
         * the sole caller that builds one via {@see AddToCartViewModel::build()} and passes it
         * in. Reused verbatim from the SAME closed decision the add-to-cart BLOCK already makes
         * ({@see \Thallo\Commerce\Http\Shop\ShopBlockDataController::addToCart()}) — one place
         * computes direct/select/link/unavailable, never two divergent implementations.
         */
        public readonly ?AddToCartViewModel $addToCart = null,
    ) {
    }

    /**
     * @param array<string,mixed> $product a live/buyer-available commerce_products row
     * @param list<array<string,mixed>> $variants that product's variants (any subset is fine;
     *     only `status`/`price`/`currency` are read)
     * @param array<string,mixed>|null $cover that product's cover media row, if any
     */
    public static function fromRow(
        array $product,
        array $variants,
        ?array $cover,
        ShopUrlGenerator $urls,
        ?AddToCartViewModel $addToCart = null,
    ): self {
        [$priceFrom, $currency] = self::cheapestActivePrice($variants);
        $count = (int) ($product['rating_count'] ?? 0);
        $rating = $count > 0
            ? ['average' => round(((int) ($product['rating_sum'] ?? 0)) / $count, 1), 'count' => $count]
            : null;

        return new self(
            uuid: (string) $product['uuid'],
            slug: (string) $product['slug'],
            name: (string) $product['name'],
            description: isset($product['description']) ? (string) $product['description'] : null,
            type: (string) ($product['type'] ?? 'physical'),
            priceFrom: $priceFrom,
            priceFormatted: $priceFrom !== null && $currency !== null ? Money::format($priceFrom, $currency) : null,
            currency: $currency,
            coverUrl: $cover !== null ? '/blobs/' . $cover['blob_uuid'] : null,
            rating: $rating,
            url: $urls->product((string) $product['slug']),
            addToCart: $addToCart,
        );
    }

    /**
     * @param list<array<string,mixed>> $variants
     * @return array{0: ?int, 1: ?string}
     */
    private static function cheapestActivePrice(array $variants): array
    {
        $cheapest = null;
        $currency = null;
        foreach ($variants as $variant) {
            if (($variant['status'] ?? null) !== 'active') {
                continue;
            }
            $price = (int) ($variant['price'] ?? 0);
            if ($cheapest === null || $price < $cheapest) {
                $cheapest = $price;
                $currency = isset($variant['currency']) ? (string) $variant['currency'] : null;
            }
        }

        return [$cheapest, $currency];
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'uuid' => $this->uuid,
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'type' => $this->type,
            'price_from' => $this->priceFrom,
            'price_formatted' => $this->priceFormatted,
            'currency' => $this->currency,
            'cover_url' => $this->coverUrl,
            'rating' => $this->rating,
            'url' => $this->url,
            'add_to_cart' => $this->addToCart?->toArray(),
        ];
    }
}
