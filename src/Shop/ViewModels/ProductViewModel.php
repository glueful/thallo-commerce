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
    /**
     * @param array{average: float, count: int}|null $rating
     * @param list<array{url: string, alt: ?string}> $gallery
     */
    public function __construct(
        public readonly string $uuid,
        public readonly string $slug,
        public readonly string $name,
        public readonly ?string $description,
        public readonly string $type,
        public readonly ?int $priceFrom,
        public readonly ?string $priceFormatted,
        /** Plain decimal ("89.00") for machine consumers (JSON-LD offers) — never the symbol form. */
        public readonly ?string $priceDecimal,
        /** Formatted compare-at ("was" price; single-active-variant products only) — null otherwise. */
        public readonly ?string $compareAtFormatted,
        public readonly ?string $currency,
        public readonly ?string $coverUrl,
        /** Resolved, anonymously-servable image URLs (cover-role first) — [] when none resolve. */
        public readonly array $gallery,
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
     *     only `status`/`price`/`compare_at_price`/`currency` are read)
     * @param ?string $coverUrl the product's RESOLVED cover image URL (built by the caller
     *     through the {@see \Thallo\Contracts\Delivery\MediaUrlResolver} authority — never a
     *     hand-concatenated `/blobs/…` path, which missed the API prefix and ignored blob
     *     visibility), null when no image resolves
     * @param list<array{url: string, alt: ?string}> $gallery resolved gallery image URLs,
     *     cover-role rows first — only the product detail page passes a non-empty list
     */
    public static function fromRow(
        array $product,
        array $variants,
        ?string $coverUrl,
        ShopUrlGenerator $urls,
        ?AddToCartViewModel $addToCart = null,
        array $gallery = [],
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
            priceFormatted: $priceFrom !== null && $currency !== null
                ? ShopMoney::display($priceFrom, $currency)
                : null,
            priceDecimal: $priceFrom !== null && $currency !== null
                ? Money::format($priceFrom, $currency)
                : null,
            compareAtFormatted: self::compareAtDisplay($variants),
            currency: $currency,
            coverUrl: $coverUrl,
            gallery: $gallery,
            rating: $rating,
            url: $urls->product((string) $product['slug']),
            addToCart: $addToCart,
        );
    }

    /**
     * The "was" price, shown struck through beside the current price — only for a product with
     * exactly ONE active variant whose `compare_at_price` exceeds its price (multi-variant
     * products have per-variant sale prices; a single struck number would be a lie).
     *
     * @param list<array<string,mixed>> $variants
     */
    private static function compareAtDisplay(array $variants): ?string
    {
        $active = array_values(array_filter(
            $variants,
            static fn (array $variant): bool => ($variant['status'] ?? null) === 'active',
        ));
        if (count($active) !== 1) {
            return null;
        }
        $compareAt = $active[0]['compare_at_price'] ?? null;
        $currency = $active[0]['currency'] ?? null;
        if (!is_numeric($compareAt) || !is_string($currency)) {
            return null;
        }
        return (int) $compareAt > (int) ($active[0]['price'] ?? 0)
            ? ShopMoney::display((int) $compareAt, $currency)
            : null;
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
            'price_decimal' => $this->priceDecimal,
            'compare_at_formatted' => $this->compareAtFormatted,
            'currency' => $this->currency,
            'cover_url' => $this->coverUrl,
            'gallery' => $this->gallery,
            'rating' => $this->rating,
            'url' => $this->url,
            'add_to_cart' => $this->addToCart?->toArray(),
        ];
    }
}
