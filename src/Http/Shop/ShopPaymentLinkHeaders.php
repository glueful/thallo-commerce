<?php

declare(strict_types=1);

namespace Thallo\Commerce\Http\Shop;

use Glueful\Routing\RouteMiddleware;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The three response headers payment-links spec §2.3 requires on EVERY payment-link response —
 * "success AND 404/error" — stamped in one place:
 *
 *  - `Cache-Control: no-store` — the landing URL carries a bearer token and the page states a
 *    payer's bill; nothing about it may be written to a disk cache, a shared proxy, or a back/
 *    forward cache entry on a borrowed machine.
 *  - `Referrer-Policy: strict-origin` — THE load-bearing one. Under it a cross-origin navigation
 *    discloses ONLY the merchant's origin (`https://shop.example/`) and never the path, so the
 *    303 that sends a payer to a hosted gateway can no longer carry
 *    `Referer: https://.../checkout/pay/<token>` — the bearer credential stays on this side of
 *    the redirect, while the merchant origin (which the PSP already knows: it is the account it
 *    settles into and the host of the return URL it was handed) still reaches it. That last part
 *    is why this is `strict-origin` and not `no-referrer`: a `Referer`-less same-origin form POST
 *    serializes its `Origin` as opaque `null`, which forced a bespoke CSRF widening; an
 *    origin-only referrer keeps the stock {@see ShopCsrfGuard} sufficient with nothing widened.
 *    The `strict-` prefix additionally suppresses the header entirely on an HTTPS→HTTP downgrade.
 *  - `X-Robots-Tag: noindex, nofollow, noarchive` — a forwarded link must not become an indexed,
 *    archived, permanently-crawlable page.
 *
 * ## Why a middleware rather than only the controller
 *
 * The controller stamps these on every response it builds. This middleware exists because not
 * every payment-link response comes FROM the controller: a rejected provenance check
 * ({@see ShopCsrfGuard}) and an exceeded IP ceiling (the framework's rate limiter) both
 * short-circuit before it. It is registered FIRST in the chain (after the tenant pair), so
 * it post-processes whatever the rest of the chain returns, and "all responses" is a structural
 * guarantee rather than a list of places somebody remembered.
 *
 * It always OVERWRITES: a value one of those inner layers set (a rate limiter's own
 * `Cache-Control`, say) must not weaken this posture.
 *
 * NOTE on the wire value: Symfony's `ResponseHeaderBag` recomputes `Cache-Control` and appends
 * `private` to a bare `no-store`, so the header emitted is `no-store, private`. That is strictly
 * NARROWER than the spec's literal — `private` can only reduce who may cache — and it is the
 * same value the existing checkout surface has always sent.
 */
final class ShopPaymentLinkHeaders implements RouteMiddleware
{
    public const CACHE_CONTROL = 'no-store';
    public const REFERRER_POLICY = 'strict-origin';
    public const ROBOTS = 'noindex, nofollow, noarchive';

    public function handle(Request $request, callable $next, mixed ...$params): mixed
    {
        $response = $next($request);

        return $response instanceof Response ? self::stamp($response) : $response;
    }

    /** The ONE definition, shared with {@see ShopPaymentLinkController}'s own responses. */
    public static function stamp(Response $response): Response
    {
        $response->headers->set('Cache-Control', self::CACHE_CONTROL);
        $response->headers->set('Referrer-Policy', self::REFERRER_POLICY);
        $response->headers->set('X-Robots-Tag', self::ROBOTS);

        return $response;
    }
}
