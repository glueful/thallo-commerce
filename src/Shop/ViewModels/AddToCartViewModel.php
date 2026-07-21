<?php

declare(strict_types=1);

namespace Thallo\Commerce\Shop\ViewModels;

use Glueful\Extensions\Commerce\Support\Money;
use Thallo\Commerce\Shop\ShopUrlGenerator;

/**
 * Closed storefront projection for the `add-to-cart` block (storefront-rendering spec §5.2/§10
 * task-11 brief): "simple products submit directly; variant/add-on products render required
 * controls or link to detail — never an invalid cart line." `mode` is the closed decision this
 * class makes so the block template/JS never has to reimplement it:
 *
 *  - `unavailable`  — the product does not resolve (unknown/tombstoned/inactive slug, or no
 *                     product context at all) — no form, no link, just a status message.
 *  - `direct`       — exactly one active variant AND no required add-on: a single hidden
 *                     `variant_uuid` is safe to submit directly.
 *  - `select`       — more than one active variant, no required add-on: a real `<select>` of
 *                     variants is "required controls" the customer must resolve before the line
 *                     is valid.
 *  - `link`         — any required add-on exists, or there are zero buyable variants: building a
 *                     safe add-on/variant picker is out of this block's scope, so it links to the
 *                     product detail page instead of ever submitting an invalid line.
 */
final class AddToCartViewModel
{
    /** @param list<array{uuid:string,label:string,price_formatted:string}> $variants */
    private function __construct(
        public readonly bool $available,
        public readonly string $mode,
        public readonly ?string $productName,
        public readonly ?string $productUrl,
        public readonly ?string $variantUuid,
        public readonly array $variants,
    ) {
    }

    public static function unavailable(): self
    {
        return new self(false, 'unavailable', null, null, null, []);
    }

    /**
     * @param array<string,mixed> $product a live/buyer-available commerce_products row
     * @param list<array<string,mixed>> $activeVariants that product's ACTIVE variants only
     */
    public static function build(
        array $product,
        array $activeVariants,
        bool $hasRequiredAddons,
        ShopUrlGenerator $urls,
        string $defaultCurrency,
    ): self {
        $name = (string) $product['name'];
        $url = $urls->product((string) $product['slug']);

        if ($hasRequiredAddons || $activeVariants === []) {
            return new self(true, 'link', $name, $url, null, []);
        }

        if (count($activeVariants) === 1) {
            return new self(true, 'direct', $name, $url, (string) $activeVariants[0]['uuid'], []);
        }

        $options = array_map(
            static fn (array $variant): array => [
                'uuid' => (string) $variant['uuid'],
                'label' => self::variantLabel($variant),
                'price_formatted' => Money::format(
                    (int) ($variant['price'] ?? 0),
                    isset($variant['currency']) ? (string) $variant['currency'] : $defaultCurrency,
                ),
            ],
            $activeVariants,
        );

        return new self(true, 'select', $name, $url, null, $options);
    }

    private static function variantLabel(array $variant): string
    {
        $sku = isset($variant['sku']) ? trim((string) $variant['sku']) : '';

        return $sku !== '' ? $sku : 'Option';
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'available' => $this->available,
            'mode' => $this->mode,
            'product_name' => $this->productName,
            'product_url' => $this->productUrl,
            'variant_uuid' => $this->variantUuid,
            'variants' => $this->variants,
        ];
    }
}
