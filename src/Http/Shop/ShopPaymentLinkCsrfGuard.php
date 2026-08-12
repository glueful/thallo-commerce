<?php

declare(strict_types=1);

namespace Thallo\Commerce\Http\Shop;

use Glueful\Routing\RouteMiddleware;
use Symfony\Component\HttpFoundation\Request;

/**
 * The anonymous-checkout POST provenance policy, applied to
 * `POST /checkout/pay/{token}/initiate` — {@see ShopCsrfGuard} verbatim, plus ONE narrowly
 * scoped reconciliation that the payment-link page's own mandatory response header forces.
 *
 * ## Why this class has to exist
 *
 * Payment-links spec §2.3 requires `Referrer-Policy: no-referrer` on the landing page (without
 * it, the 303 into a hosted gateway leaks `Referer: .../checkout/pay/<token>` — the bearer
 * credential itself — to a third party). A document's referrer policy also governs the requests
 * it makes, and WHATWG Fetch's "append a request `Origin` header" step says: for a non-CORS
 * request whose method is not GET/HEAD, a referrer policy of `no-referrer` serializes the origin
 * as the opaque `null`. So the no-JS Pay form POST arrives with `Origin: null` and NO `Referer`
 * at all — in every current browser.
 *
 * {@see ShopCsrfGuard} answers 403 to exactly that shape (`null` fails `normalizeOrigin()`), so
 * applying it unchanged here would mean a Pay button that can never work. The two requirements
 * are individually correct and jointly unsatisfiable by an Origin/Referer-only check; this class
 * is the reconciliation, and it is deliberately the SMALLEST one available:
 *
 *  - Everything else delegates to {@see ShopCsrfGuard} unchanged — a cross-origin `Origin`, a
 *    cross-site `Sec-Fetch-Site`, a mismatched `Referer`, and the no-signals-at-all case (no
 *    `Sec-Fetch-Site`, no `Origin`, no `Referer`) are all still rejected by the one shared
 *    policy. There is no second implementation of origin comparison here.
 *  - The ONE addition: an `Origin` that is exactly the opaque `null` (or absent) is accepted
 *    when — and only when — `Sec-Fetch-Site: same-origin` is present. `Sec-Fetch-Site` is a
 *    forbidden header name: page script cannot set it, so a hostile site cannot make a victim's
 *    browser send it, which is precisely the property a CSRF check needs.
 *
 * ## Why this is safe even so
 *
 * This endpoint holds NO ambient authority. Its credential is the token in the path, not a
 * cookie — a cross-site attacker who could forge this POST would have to already know the
 * token, in which case they can simply call it with curl and no browser at all. What the guard
 * protects here is narrow (a third-party page must not be able to burn a link's hourly
 * initiation budget through a visitor's browser), and the Fetch-Metadata signal protects it.
 */
final class ShopPaymentLinkCsrfGuard implements RouteMiddleware
{
    public function __construct(private readonly ShopCsrfGuard $guard)
    {
    }

    public function handle(Request $request, callable $next, mixed ...$params): mixed
    {
        if (self::isOpaqueSameOriginFormPost($request)) {
            return $next($request);
        }

        return $this->guard->handle($request, $next, ...$params);
    }

    /**
     * EXACTLY the shape a same-origin no-JS form POST from a `Referrer-Policy: no-referrer`
     * document produces: browser-asserted same-origin Fetch Metadata, an opaque or absent
     * `Origin`, and no `Referer`. Anything richer than that (a real `Origin`, any `Referer`)
     * falls through to {@see ShopCsrfGuard}, which compares it properly.
     */
    private static function isOpaqueSameOriginFormPost(Request $request): bool
    {
        $secFetchSite = $request->headers->get('Sec-Fetch-Site');
        if (!is_string($secFetchSite) || strtolower(trim($secFetchSite)) !== 'same-origin') {
            return false;
        }

        $origin = trim((string) $request->headers->get('Origin', ''));
        if ($origin !== '' && strtolower($origin) !== 'null') {
            return false;
        }

        return (string) $request->headers->get('Referer', '') === '';
    }
}
