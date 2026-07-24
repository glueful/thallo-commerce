<?php

declare(strict_types=1);

namespace Thallo\Commerce\Shop\ViewModels;

use Glueful\Extensions\Commerce\Support\Money;

/**
 * Customer-facing money display for the storefront (product-editor mock parity, 2026-07-24):
 * "$89.00", not "89.00 USD". Commerce's own {@see Money::format()} stays the exponent authority —
 * it produces the exact decimal string — and `ext-intl`'s NumberFormatter contributes ONLY the
 * currency symbol/placement. Locale is pinned to 'en' for the same reason the admin SPA pins
 * en-US: the storefront has no per-request locale surface today, and a pinned locale keeps cached
 * page bytes deterministic across environments. Without intl (or on any formatter failure) the
 * display degrades to the previous "89.00 USD" form — never a fatal, never a wrong amount.
 */
final class ShopMoney
{
    private static ?\NumberFormatter $formatter = null;

    public static function display(int $minor, string $currency): string
    {
        $decimal = Money::format($minor, $currency);

        if (class_exists(\NumberFormatter::class)) {
            $formatter = self::$formatter ??= new \NumberFormatter('en', \NumberFormatter::CURRENCY);
            // Display-only float: the decimal string came from integer math in Money::format(),
            // and product prices sit far below the ~2^53 range where float display would drift.
            $formatted = $formatter->formatCurrency((float) $decimal, strtoupper($currency));
            if ($formatted !== false) {
                return $formatted;
            }
        }

        return $decimal . ' ' . strtoupper($currency);
    }
}
