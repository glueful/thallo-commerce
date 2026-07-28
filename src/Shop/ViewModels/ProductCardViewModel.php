<?php

declare(strict_types=1);

namespace Thallo\Commerce\Shop\ViewModels;

/**
 * The closed storefront CARD projection (storefront-v1 spec §3/§5) — one shape shared by the
 * native shop grids (`shop/_product_card.twig`), the wishlist resolution endpoint (Task 7),
 * and shop.js's `buildProductCard()` (Task 8), so the server-rendered and client-hydrated
 * cards can never drift.
 *
 * `toArray()` emits EXACTLY the pinned allowlist — `{uuid, name, url, cover_url, rating,
 * price_formatted, compare_at_formatted, category_name, cart_mode, direct_variant_uuid}` —
 * and nothing else; ProductCardViewModelTest guards it with an `array_keys` equality
 * assertion. Every display derivation (price, compare-at, rating, URL, cover) is REUSED from
 * {@see ProductViewModel::fromRow()} — this class re-derives nothing from raw rows.
 *
 * Cart honesty: {@see AddToCartViewModel::build()} stays the ONE purchasability authority.
 * Its `direct` decision (exactly one active variant, no required add-on) is the only thing
 * that ever yields `cart_mode: 'direct'` + `direct_variant_uuid`; every other decision
 * (`select`, `link`, `unavailable`) reduces to `'options'` with a null variant uuid — a grid
 * card can never be honest about unchosen variants or required add-ons, so it links to the
 * detail page instead.
 */
final class ProductCardViewModel
{
    /** @param array{average: float, count: int}|null $rating */
    private function __construct(
        public readonly string $uuid,
        public readonly string $name,
        public readonly string $url,
        public readonly ?string $coverUrl,
        public readonly ?array $rating,
        public readonly ?string $priceFormatted,
        public readonly ?string $compareAtFormatted,
        public readonly ?string $categoryName,
        public readonly string $cartMode,
        public readonly ?string $directVariantUuid,
    ) {
    }

    public static function fromProduct(
        ProductViewModel $product,
        ?string $categoryName,
        AddToCartViewModel $addToCart,
    ): self {
        $direct = $addToCart->available && $addToCart->mode === 'direct';

        return new self(
            uuid: $product->uuid,
            name: $product->name,
            url: $product->url,
            coverUrl: $product->coverUrl,
            rating: $product->rating,
            priceFormatted: $product->priceFormatted,
            compareAtFormatted: $product->compareAtFormatted,
            categoryName: $categoryName,
            cartMode: $direct ? 'direct' : 'options',
            directVariantUuid: $direct ? $addToCart->variantUuid : null,
        );
    }

    /** @return array<string,mixed> EXACTLY the pinned card allowlist — key order included */
    public function toArray(): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'url' => $this->url,
            'cover_url' => $this->coverUrl,
            'rating' => $this->rating,
            'price_formatted' => $this->priceFormatted,
            'compare_at_formatted' => $this->compareAtFormatted,
            'category_name' => $this->categoryName,
            'cart_mode' => $this->cartMode,
            'direct_variant_uuid' => $this->directVariantUuid,
        ];
    }
}
