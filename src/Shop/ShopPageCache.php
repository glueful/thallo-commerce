<?php

declare(strict_types=1);

namespace Thallo\Commerce\Shop;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Cache\CacheStore;
use Glueful\Extensions\Commerce\Tenancy\CommerceTenantResolution;
use Glueful\Routing\RouteMiddleware;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Thallo\Render\Http\Middleware\PreviewSessionMiddleware;
use Thallo\Render\Http\Middleware\RenderPageCache;

use function config;

/**
 * Shop-specific catalog page cache (storefront-rendering spec §9) — the shop index, product
 * detail, and category archive routes. Deliberately SEPARATE from
 * {@see RenderPageCache} (path-only, strips queries, never carries a business tenant or a
 * `page` dimension): this cache's key is DIMENSION-COMPLETE —
 * `(tenant, resolvedLocale, activeTheme, appearanceFingerprint, path, page)` — so two tenants,
 * two locales, a theme switch, or an appearance change can never collide or serve stale
 * markup across each other. `page` is the ONLY allowlisted query parameter, bounded to a
 * canonical integer `1..1000`: an invalid/out-of-range value is a non-revealing 404 returned
 * BEFORE the downstream controller ever runs (never cached — this also protects against an
 * arbitrarily large `OFFSET` query), and ANY other query parameter present bypasses the cache
 * entirely (no read, no write) rather than risk keying on or ignoring untrusted input.
 *
 * Locale/theme/appearance come from the SAME trusted render services
 * {@see \Thallo\Commerce\Http\Shop\ShopCatalogController} and thallo-render's own
 * `RenderServiceProvider::makeRenderPageCache()` use — never from request/query input. The
 * appearance "fingerprint" is the resolved accent-neutral pair (there is no revision counter),
 * the exact same identity RenderPageCache keys on.
 *
 * Tags: `thallo:shop:catalog:{tenant}` (tenant-scoped catalog purges — the primary signal), the
 * global `thallo:shop:catalog` (theme/appearance purges, which carry no tenant identity), AND —
 * Commerce-Slice-2 Fix B — any surrogate tags {@see \Thallo\Commerce\Http\Shop\ShopCatalogController}
 * stamps on the response's `Cache-Tag` header (currently just `thallo:entry:{uuid}` for a
 * linked enrichment entry), read off the response and folded into this cache entry's own tags —
 * the EXACT mechanism {@see RenderPageCache} already uses for RenderController's `Cache-Tag`
 * header. Because `thallo:entry:{uuid}` is the SAME string the content engine's own cache-tag
 * invalidation listener already invalidates on an entry publish/update/delete (the identical
 * surrogate key {@see RenderPageCache}/the delivery API already tag every content response
 * with), a publish/edit/delete of the linked entry purges this cached product-detail page with
 * ZERO new purge/listener code in this pack — the header-to-tag fold below is the entire
 * mechanism. Storage/serve mechanics (ETag, 304, Cache-Control, 200/404/410/other eligibility)
 * mirror {@see RenderPageCache} exactly, through the SAME CacheStore binding this pack's purge
 * listeners invalidate.
 */
final class ShopPageCache implements RouteMiddleware
{
    private const TENANT_TAG_PREFIX = 'thallo:shop:catalog:';
    private const GLOBAL_TAG = 'thallo:shop:catalog';
    private const MIN_PAGE = 1;
    private const MAX_PAGE = 1000;

    public function __construct(
        private readonly CacheStore $cache,
        private readonly CommerceTenantResolution $tenants,
        private readonly string $theme,
        /** Validated accent-neutral fingerprint (mirrors RenderPageCache's identity). */
        private readonly string $appearance,
        private readonly bool $enabled,
        private readonly int $ttl,
        private readonly ApplicationContext $context,
    ) {
    }

    public function handle(Request $request, callable $next, ...$params): mixed
    {
        // Preview sessions bypass wholesale (mirrors RenderPageCache's identical check): no
        // read, no store. This layer only honors the attribute — it never parses cookies.
        if ($request->attributes->has(PreviewSessionMiddleware::ATTRIBUTE)) {
            return $next($request);
        }

        if (!$this->enabled) {
            return $next($request);
        }

        $page = $this->resolvePage($request);
        if ($page === null) {
            // A foreign query parameter is present — bypass entirely, untouched.
            return $next($request);
        }
        if ($page === false) {
            // `page` present but not a canonical integer in 1..1000 — non-revealing 404,
            // BEFORE the downstream controller runs. Never cached.
            return new Response('', 404, ['Cache-Control' => 'private, no-store']);
        }

        $tenant = $this->tenants->tenantUuid($this->context);
        $locale = (string) config($this->context, 'i18n.default_locale', 'en');
        $key = $this->key($tenant, $locale, $request->getPathInfo(), $page);

        $hit = $this->cache->get($key);
        if (is_array($hit)) {
            return $this->respond($request, $hit);
        }

        $response = $next($request);
        if (!$response instanceof Response) {
            return $response;
        }

        $contentType = (string) $response->headers->get('Content-Type');
        if (!str_contains($contentType, 'text/html')) {
            return $response; // JSON 404s / redirects / non-HTML pass through untouched.
        }

        $status = $response->getStatusCode();
        $body = (string) $response->getContent();
        $cacheTag = (string) $response->headers->get('Cache-Tag', '');

        if ($status === 200) {
            $entry = $this->entry($body, 200, $contentType, $cacheTag);
            $this->cache->set($key, $entry, $this->ttl);
            $this->cache->addTags(
                $key,
                [...$this->surrogateTags($cacheTag), self::TENANT_TAG_PREFIX . $tenant, self::GLOBAL_TAG],
            );
            return $this->respond($request, $entry);
        }

        if ($status === 404 || $status === 410) {
            // A genuine content-driven 404/410 (unknown slug, tombstoned product) — uniform
            // validators only, never stored.
            return $this->respond($request, $this->entry($body, $status, $contentType, $cacheTag));
        }

        return $response; // 500s etc: never cached, untouched.
    }

    /** @return list<string> the surrogate keys from a Cache-Tag header value (mirrors RenderPageCache) */
    private function surrogateTags(string $cacheTag): array
    {
        if ($cacheTag === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $cacheTag))));
    }

    /**
     * Validates the query string against the `page` allowlist (spec §9).
     *
     * @return int|false|null the validated page (1..1000), `false` for an invalid/out-of-range
     *     `page` value (caller returns 404), or `null` when any OTHER query parameter is
     *     present (caller bypasses the cache).
     */
    private function resolvePage(Request $request): int|false|null
    {
        $params = $request->query->all();
        if ($params === []) {
            return self::MIN_PAGE;
        }
        if (array_keys($params) !== ['page']) {
            return null;
        }

        $raw = $params['page'];
        if (!is_string($raw) && !is_int($raw)) {
            return false;
        }
        $raw = (string) $raw;
        if (!preg_match('/^\d+$/', $raw)) {
            return false; // rejects non-integer, negative, decimal, and leading '+' forms.
        }

        $page = (int) $raw;
        if ($page < self::MIN_PAGE || $page > self::MAX_PAGE) {
            return false;
        }

        return $page;
    }

    /**
     * shop:{tenant}:{locale}:{theme}:{appearance}:{page}:{rawurlencode(normalizedPath)} — the
     * SAME rawurlencode discipline RenderPageCache documents (the Redis driver rejects
     * PSR-16-reserved characters in raw keys).
     */
    private function key(string $tenant, string $locale, string $path, int $page): string
    {
        return "shop:{$tenant}:{$locale}:{$this->theme}:{$this->appearance}:{$page}:"
            . rawurlencode(RenderPageCache::normalizePath($path));
    }

    /** @param array{body: string, status: int, contentType: string, cacheTag: string, etag: string} $entry */
    private function respond(Request $request, array $entry): Response
    {
        $headers = [
            'Content-Type' => $entry['contentType'],
            'ETag' => $entry['etag'],
            'Cache-Control' => 'public, max-age=0, must-revalidate',
        ];
        if ($entry['cacheTag'] !== '') {
            $headers['Cache-Tag'] = $entry['cacheTag'];
        }
        if ($this->etagMatches($request, $entry['etag'])) {
            return new Response('', 304, $headers);
        }
        return new Response($entry['body'], $entry['status'], $headers);
    }

    /** @return array{body: string, status: int, contentType: string, cacheTag: string, etag: string} */
    private function entry(string $body, int $status, string $contentType, string $cacheTag): array
    {
        return [
            'body' => $body,
            'status' => $status,
            'contentType' => $contentType,
            'cacheTag' => $cacheTag,
            'etag' => '"' . sha1($body) . '"',
        ];
    }

    private function etagMatches(Request $request, string $etag): bool
    {
        $ifNoneMatch = (string) $request->headers->get('If-None-Match', '');
        if ($ifNoneMatch === '') {
            return false;
        }
        foreach (array_map('trim', explode(',', $ifNoneMatch)) as $candidate) {
            if ($candidate === $etag || $candidate === 'W/' . $etag || $candidate === '*') {
                return true;
            }
        }
        return false;
    }
}
