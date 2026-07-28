<?php

declare(strict_types=1);

namespace Thallo\Commerce\Shop;

/**
 * The ONLY source of storefront URLs (storefront-rendering spec §3): catalog/cart/checkout
 * paths, blocks, templates, canonicals, and JSON-LD structured data all go through this class —
 * nobody else concatenates a shop path by hand. Every method returns a `/`-prefixed path (never
 * an absolute URL — the same convention {@see \Thallo\Contracts\Context\Context::renderPath()}
 * and the render pack's `asset()`/`path()` Twig functions already use throughout this codebase).
 *
 * `cart()`, `checkout()`, `paymentReturn()`, `paymentCancel()`, `confirmation()`, and `assets()`
 * describe the STABLE root-level workflow paths spec §3 pins (`/cart`, `/checkout`,
 * `/checkout/return/{ref}`, `/checkout/cancel/{ref}`, `/checkout/confirmation/{ref}`,
 * `/_shop/assets/shop-{fingerprint}.js`) — the URL SHAPE is fixed so nothing built against this
 * class has to change later.
 */
final class ShopUrlGenerator
{
    /** The normalized, single-segment shop prefix (e.g. "shop"), no leading/trailing slash. */
    public readonly string $prefix;

    public function __construct(string $prefix, private readonly ShopAssetMap $assets)
    {
        $this->prefix = self::normalizePrefix($prefix);
    }

    /**
     * Trims surrounding slashes and rejects anything that is not exactly one non-empty path
     * segment — spec §3: "the prefix is normalized (trim, single segment, no /), rejected at
     * boot otherwise." An empty prefix, a multi-segment prefix ("a/b"), or one containing
     * whitespace is a configuration error, never silently coerced.
     */
    public static function normalizePrefix(string $raw): string
    {
        $trimmed = trim($raw, "/ \t\n\r\0\x0B");
        if ($trimmed === '' || str_contains($trimmed, '/') || preg_match('/\s/', $trimmed) === 1) {
            throw new \RuntimeException(
                'thallo-commerce.shop_prefix must be a single non-empty path segment '
                . '(no "/", no whitespace, not empty); got ' . var_export($raw, true) . '.'
            );
        }

        return $trimmed;
    }

    public function shopIndex(): string
    {
        return '/' . $this->prefix;
    }

    public function product(string $slug): string
    {
        return '/' . $this->prefix . '/products/' . rawurlencode($slug);
    }

    public function category(string $slug): string
    {
        return '/' . $this->prefix . '/categories/' . rawurlencode($slug);
    }

    /** The wishlist page under the catalog prefix (storefront-v1 spec §5). */
    public function wishlist(): string
    {
        return '/' . $this->prefix . '/wishlist';
    }

    /** Stable root-level workflow path — independent of the catalog prefix (spec §3). */
    public function cart(): string
    {
        return '/cart';
    }

    /** Stable root-level workflow path — independent of the catalog prefix (spec §3). */
    public function checkout(): string
    {
        return '/checkout';
    }

    /** Stub: the `/checkout/return/{ref}` route itself lands in a later task. */
    public function paymentReturn(string $ref): string
    {
        return '/checkout/return/' . rawurlencode($ref);
    }

    /** Stub: the `/checkout/cancel/{ref}` route itself lands in a later task. */
    public function paymentCancel(string $ref): string
    {
        return '/checkout/cancel/' . rawurlencode($ref);
    }

    /** Stub: the `/checkout/confirmation/{ref}` route itself lands in a later task. */
    public function confirmation(string $ref): string
    {
        return '/checkout/confirmation/' . rawurlencode($ref);
    }

    /**
     * The ONE current fingerprinted `shop.js` URL (task 11) — the fingerprint is a content
     * hash computed at boot by {@see ShopAssetMap}, so this changes automatically whenever the
     * shipped file's bytes change (every normal release), making the
     * `public, max-age=31536000, immutable` response header
     * {@see \Thallo\Commerce\Http\Shop\ShopAssetController} sends safe.
     */
    public function assets(): string
    {
        $name = $this->assets->fingerprintedName('shop.js');
        if ($name === null) {
            throw new \RuntimeException(
                'thallo-commerce: shop.js was not found in the pack assets/ directory.'
            );
        }

        return '/_shop/assets/' . rawurlencode($name);
    }
}
