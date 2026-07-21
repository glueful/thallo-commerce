<?php

declare(strict_types=1);

namespace Thallo\Commerce\Shop\ViewModels;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Cart\AddonSnapshot;
use Glueful\Extensions\Commerce\Pricing\Totals;
use Glueful\Extensions\Commerce\Support\Money;
use Thallo\Commerce\Shop\ShopUrlGenerator;

use function config;

/**
 * Closed storefront projection of the current cart (storefront-rendering spec §6: "every
 * `/_shop` response is a closed view model — never raw commerce rows"). Built from
 * {@see \Glueful\Extensions\Commerce\Cart\CartService::view()}'s shape (`cart`, `lines`,
 * `totals`) — every property is assigned explicitly from an allowlisted key. The raw cart row
 * (`token_hash`, `tenant_uuid`, `user_uuid`, the cart's own internal `uuid`, …) and the raw
 * priced-line row (`product_uuid`, `tax_class`, `commission_*`, …) are never spread or passed
 * through. The cart's own TOKEN never appears here at all — custody is
 * {@see \Thallo\Commerce\Http\Shop\CartCookie}'s job alone, and this class has no access to it.
 *
 * `GET /_shop/cart` (mini-cart JSON hydration) and every `/_shop/cart/*` mutation response
 * share this exact shape, so the JS enhancement layer (a later task) can apply one update
 * routine regardless of which endpoint answered.
 */
final class CartViewModel
{
    /** @param list<array<string,mixed>> $items */
    private function __construct(
        public readonly array $items,
        public readonly int $itemCount,
        public readonly ?string $discountCode,
        public readonly int $subtotal,
        public readonly string $subtotalFormatted,
        public readonly int $discountTotal,
        public readonly string $discountTotalFormatted,
        public readonly int $shippingTotal,
        public readonly string $shippingTotalFormatted,
        public readonly int $taxTotal,
        public readonly string $taxTotalFormatted,
        public readonly int $grandTotal,
        public readonly string $grandTotalFormatted,
        public readonly string $currency,
        public readonly string $cartUrl,
        public readonly string $checkoutUrl,
    ) {
    }

    /** An empty cart — no cookie yet, or the cookie's cart no longer resolves. Never 404s. */
    public static function empty(ApplicationContext $context, ShopUrlGenerator $urls): self
    {
        $currency = self::currency($context);

        return new self(
            items: [],
            itemCount: 0,
            discountCode: null,
            subtotal: 0,
            subtotalFormatted: Money::format(0, $currency),
            discountTotal: 0,
            discountTotalFormatted: Money::format(0, $currency),
            shippingTotal: 0,
            shippingTotalFormatted: Money::format(0, $currency),
            taxTotal: 0,
            taxTotalFormatted: Money::format(0, $currency),
            grandTotal: 0,
            grandTotalFormatted: Money::format(0, $currency),
            currency: $currency,
            cartUrl: $urls->cart(),
            checkoutUrl: $urls->checkout(),
        );
    }

    /**
     * @param array{cart: array<string,mixed>, lines: list<array<string,mixed>>, totals: Totals} $view
     *     {@see \Glueful\Extensions\Commerce\Cart\CartService::view()}'s return shape verbatim.
     */
    public static function fromView(ApplicationContext $context, array $view, ShopUrlGenerator $urls): self
    {
        $currency = self::currency($context);
        $totals = $view['totals'];

        $itemCount = 0;
        $items = [];
        foreach ($view['lines'] as $line) {
            $quantity = (int) $line['quantity'];
            $unitPrice = (int) $line['unit_price'];
            $lineTotal = $unitPrice * $quantity;
            $itemCount += $quantity;

            $items[] = [
                'variant_uuid' => (string) $line['variant_uuid'],
                'sku' => (string) $line['sku'],
                'product_name' => (string) $line['product_name'],
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'unit_price_formatted' => Money::format($unitPrice, $currency),
                'line_total' => $lineTotal,
                'line_total_formatted' => Money::format($lineTotal, $currency),
                'currency' => $currency,
                // Whitelisted echo (design spec §4 parity with Commerce's own storefront cart
                // controller): the FULL persisted snapshot carries addon_uuid/choice_key, which
                // never leaves this pack's boundary.
                'addons' => AddonSnapshot::sanitize(is_array($line['addons'] ?? null) ? $line['addons'] : []),
            ];
        }

        $discountCode = $view['cart']['discount_code'] ?? null;

        return new self(
            items: $items,
            itemCount: $itemCount,
            discountCode: is_string($discountCode) && $discountCode !== '' ? $discountCode : null,
            subtotal: $totals->subtotal,
            subtotalFormatted: Money::format($totals->subtotal, $currency),
            discountTotal: $totals->discountTotal,
            discountTotalFormatted: Money::format($totals->discountTotal, $currency),
            shippingTotal: $totals->shippingTotal,
            shippingTotalFormatted: Money::format($totals->shippingTotal, $currency),
            taxTotal: $totals->taxTotal,
            taxTotalFormatted: Money::format($totals->taxTotal, $currency),
            grandTotal: $totals->grandTotal,
            grandTotalFormatted: Money::format($totals->grandTotal, $currency),
            currency: $currency,
            cartUrl: $urls->cart(),
            checkoutUrl: $urls->checkout(),
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'items' => $this->items,
            'item_count' => $this->itemCount,
            'discount_code' => $this->discountCode,
            'subtotal' => $this->subtotal,
            'subtotal_formatted' => $this->subtotalFormatted,
            'discount_total' => $this->discountTotal,
            'discount_total_formatted' => $this->discountTotalFormatted,
            'shipping_total' => $this->shippingTotal,
            'shipping_total_formatted' => $this->shippingTotalFormatted,
            'tax_total' => $this->taxTotal,
            'tax_total_formatted' => $this->taxTotalFormatted,
            'grand_total' => $this->grandTotal,
            'grand_total_formatted' => $this->grandTotalFormatted,
            'currency' => $this->currency,
            'cart_url' => $this->cartUrl,
            'checkout_url' => $this->checkoutUrl,
        ];
    }

    /** Single-store currency (design spec: "every variant price must match it"). */
    private static function currency(ApplicationContext $context): string
    {
        return (string) config($context, 'commerce.currency', 'USD');
    }
}
