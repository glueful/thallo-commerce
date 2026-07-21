<?php

declare(strict_types=1);

namespace Thallo\Commerce\Http\Shop;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Routing\RouteMiddleware;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Thallo\Contracts\Delivery\CanonicalPublicOriginResolver;

/**
 * Storefront CSRF v1 (storefront-rendering spec §6/§11), registered on every `/_shop/*`
 * MUTATION route only (never on the plain GETs). No token: the cart cookie doesn't exist
 * before the first mutation, and a personalized token would conflict with public full-page
 * caching — so origin/Fetch-Metadata verification does the whole job.
 *
 * The algorithm, in EXACT order (spec §6):
 *
 *  1. `SameSite=Lax` is the cart cookie's own baseline defense ({@see CartCookie}) — documented
 *     here, not re-implemented: this guard is an INDEPENDENT, additional gate, not a substitute.
 *  2. If `Sec-Fetch-Site` is present, reject unless it is EXACTLY `same-origin`. Its absence
 *     (older browsers, some proxies/no-JS clients) is not itself a failure — step 3 still runs.
 *  3. Independently require an exact match between the expected origin and EITHER the `Origin`
 *     header (when present) OR, only when `Origin` is absent, the `Referer` header's origin.
 *  4. If both `Origin` and `Referer` are absent, reject — EVEN IF step 2 already passed. Fetch
 *     Metadata is an ADDITIONAL gate, never a substitute for origin verification.
 *
 * The expected origin is always {@see CanonicalPublicOriginResolver::currentOrigin()} — NEVER
 * the request's own `Host` header, which an attacker fully controls (a spoofed `Host` must never
 * change what "same-origin" means here). Comparison is on normalized `scheme://host[:port]`
 * only.
 */
final class ShopCsrfGuard implements RouteMiddleware
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly CanonicalPublicOriginResolver $resolver,
    ) {
    }

    public function handle(Request $request, callable $next, mixed ...$params): mixed
    {
        // (2) Fetch Metadata: an ADDITIONAL gate, checked first, never a substitute for (3)/(4).
        $secFetchSite = $request->headers->get('Sec-Fetch-Site');
        if ($secFetchSite !== null && strtolower(trim($secFetchSite)) !== 'same-origin') {
            return $this->reject($request);
        }

        $expected = $this->resolver->currentOrigin($this->context);

        // (3) Origin wins outright when present.
        $origin = $request->headers->get('Origin');
        if (is_string($origin) && $origin !== '') {
            return self::originsMatch($origin, $expected) ? $next($request) : $this->reject($request);
        }

        // (3, continued) Origin absent -> fall back to Referer's origin, exact match only.
        $referer = $request->headers->get('Referer');
        if (!is_string($referer) || $referer === '') {
            // (4) Both absent -> reject, even though step 2 may have already passed.
            return $this->reject($request);
        }

        return self::originsMatch($referer, $expected) ? $next($request) : $this->reject($request);
    }

    /**
     * Normalizes any absolute URL (or bare origin) to `scheme://host[:port]`, lower-casing the
     * scheme and host (case-insensitive per RFC 3986). Returns `null` for anything that fails to
     * parse a non-empty scheme+host — a malformed header, a relative value, or the literal
     * `"null"` Origin some sandboxed contexts send — all fail CLOSED rather than risk an
     * accidental match.
     */
    public static function normalizeOrigin(string $value): ?string
    {
        $parts = parse_url($value);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
            return null;
        }

        $origin = strtolower($parts['scheme']) . '://' . strtolower($parts['host']);
        if (isset($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }

        return $origin;
    }

    /**
     * Same-origin test shared with {@see ShopCartController}'s PRG redirect-target validation
     * (a same-origin `Referer` is safe to redirect back to; anything else falls back to
     * `ShopUrlGenerator::cart()`) — one normalization rule for the whole feature.
     */
    public static function originsMatch(string $a, string $b): bool
    {
        $normalizedA = self::normalizeOrigin($a);

        return $normalizedA !== null && $normalizedA === self::normalizeOrigin($b);
    }

    /** 403, negotiated like every other `/_shop` response (mirrors FormSubmitController::respond()). */
    private function reject(Request $request): Response
    {
        if (str_contains((string) $request->headers->get('Accept'), 'application/json')) {
            return new JsonResponse([
                'success' => false,
                'message' => 'This request could not be verified and was rejected.',
            ], 403);
        }

        return new Response(
            'This request could not be verified and was rejected.',
            403,
            ['Content-Type' => 'text/plain; charset=UTF-8'],
        );
    }
}
