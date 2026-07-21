<?php

declare(strict_types=1);

namespace Thallo\Commerce\Http\Shop;

use Glueful\Bootstrap\ApplicationContext;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

use function config;

/**
 * Cart token custody (storefront-rendering spec §6): the RAW cart token lives ONLY in this
 * cookie — never in markup, JSON, JS storage, or a query string. `Secure; HttpOnly;
 * SameSite=Lax`, TTL aligned to `commerce.cart.ttl_days` — the SAME config
 * {@see \Glueful\Extensions\Commerce\Cart\CartService::create()} uses for the cart row's own
 * `expires_at`, so the cookie and the row it points at expire together.
 *
 * `Secure` is set unconditionally (never conditioned on the current request's own scheme):
 * spec §6 pins the cookie's attributes, not a dev-mode relaxation.
 */
final class CartCookie
{
    public const NAME = 'thallo_cart';

    /** The raw token, or null when the cookie is absent/blank. Never validated here — that's {@see \Glueful\Extensions\Commerce\Cart\CartService::byToken()}'s job. */
    public function read(Request $request): ?string
    {
        $value = $request->cookies->get(self::NAME);

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function write(Response $response, string $rawToken, ApplicationContext $context): void
    {
        $days = max(1, (int) config($context, 'commerce.cart.ttl_days', 30));

        $response->headers->setCookie(new Cookie(
            self::NAME,
            $rawToken,
            time() + $days * 86400,
            '/',
            null,
            true,  // Secure
            true,  // HttpOnly
            false, // raw (URL-encode the value)
            Cookie::SAMESITE_LAX,
        ));
    }
}
