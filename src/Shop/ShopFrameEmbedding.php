<?php

declare(strict_types=1);

namespace Thallo\Commerce\Shop;

use Glueful\Routing\RouteMiddleware;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Frame-embedding policy for shop PRODUCT pages (composed-editor spec §5.4b, phase 3 — the
 * admin's Live Mirror embeds the real storefront product page in an iframe).
 *
 * Today these public pages ship NO frame headers at all (the framework's SecurityHeaders
 * middleware is not enabled app-wide), which means anyone may frame them. This middleware makes
 * the policy EXPLICIT and stricter, not looser: when an admin origin is configured
 * (`render.admin_url`), responses gain `Content-Security-Policy: frame-ancestors 'self'
 * <admin-origin>` — the storefront may be framed by itself and by OUR admin, nobody else
 * (clickjacking hardening the pages previously lacked). Unconfigured, responses pass through
 * untouched (fail-closed for the Mirror: the admin simply can't embed; never a wildcard).
 *
 * Placed BEFORE ShopPageCache in the product route's chain so it post-processes BOTH the
 * cache-miss render and the cache-hit short-circuit — cached entries never bake (or lose) the
 * policy. Only the origin (scheme://host[:port]) of `render.admin_url` is used; any path on the
 * configured URL is discarded. An existing Content-Security-Policy header is never clobbered.
 */
final class ShopFrameEmbedding implements RouteMiddleware
{
    /** @param string $adminUrl the configured `render.admin_url` (may be empty/unset) */
    public function __construct(private readonly string $adminUrl)
    {
    }

    public function handle(Request $request, callable $next, ...$params): mixed
    {
        $response = $next($request);
        if (!$response instanceof Response) {
            return $response;
        }

        $ancestor = $this->adminOrigin();
        if ($ancestor === null) {
            return $response;
        }

        if (!$response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', "frame-ancestors 'self' {$ancestor}");
        }
        // An X-Frame-Options header cannot express "this one extra origin" — remove it so it
        // can't contradict the CSP in browsers that honor whichever is stricter.
        $response->headers->remove('X-Frame-Options');

        return $response;
    }

    /** The origin (scheme://host[:port]) of the configured admin URL, or null when unusable. */
    private function adminOrigin(): ?string
    {
        $url = trim($this->adminUrl);
        if ($url === '') {
            return null;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        $scheme = strtolower((string) $parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        return $scheme . '://' . strtolower((string) $parts['host'])
            . (isset($parts['port']) ? ':' . (int) $parts['port'] : '');
    }
}
