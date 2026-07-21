<?php

declare(strict_types=1);

namespace Thallo\Commerce\Http\Shop;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Thallo\Commerce\Shop\ShopAssetMap;

/**
 * `GET /_shop/assets/{file}` (storefront-rendering spec §5.2/§10 task-11 brief): serves the
 * pack's fingerprinted static assets (currently just `shop.js`). Resolution is ALWAYS a single
 * exact lookup against the boot-built {@see ShopAssetMap} allowlist — `{file}` is NEVER
 * concatenated into a filesystem path, so an unknown name, a `../` traversal attempt, or any
 * other value the map didn't itself produce simply misses both lookups below and 404s, the same
 * as any other unknown name.
 *
 * Two kinds of allowlisted name resolve, in order:
 *  1. A LOGICAL alias (e.g. `shop.js`) — the stable, deploy-invariant name block templates
 *     hardcode in a `<script src="...">` tag (there is no seam for a Twig template to call
 *     {@see \Thallo\Commerce\Shop\ShopUrlGenerator::assets()} directly — see that method's own
 *     docblock). This 302-redirects to the CURRENT fingerprinted file and is itself never cached
 *     (no explicit `Cache-Control`), so it always re-resolves after a deploy.
 *  2. The exact FINGERPRINTED filename (`shop-{hash}.js`) — served with the immutable-asset
 *     header this codebase already uses for `/custom.css`
 *     ({@see \Thallo\Render\Http\Controllers\RenderController::customCss()}), safe here for the
 *     same reason: the content hash IN the URL is the cache-buster.
 */
final class ShopAssetController
{
    public function __construct(private readonly ShopAssetMap $assets)
    {
    }

    public function serve(string $file): Response
    {
        $alias = $this->assets->fingerprintedName($file);
        if ($alias !== null) {
            return new RedirectResponse('/_shop/assets/' . rawurlencode($alias), 302);
        }

        $path = $this->assets->resolve($file);
        if ($path === null || !is_file($path)) {
            return new Response('Not Found', 404, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        return new Response((string) file_get_contents($path), 200, [
            'Content-Type' => self::contentTypeFor($file),
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }

    private static function contentTypeFor(string $file): string
    {
        return str_ends_with($file, '.css')
            ? 'text/css; charset=UTF-8'
            : 'application/javascript; charset=UTF-8';
    }
}
