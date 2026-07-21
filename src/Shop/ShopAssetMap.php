<?php

declare(strict_types=1);

namespace Thallo\Commerce\Shop;

/**
 * The boot-built content-hash allowlist for `GET /_shop/assets/{file}` (storefront-rendering
 * spec §5.2/§10 task-11 brief). Built ONCE, from the pack's own `assets/` directory — never from
 * request input — mapping a fingerprinted filename (`shop-{hash}.js`) to its absolute path on
 * disk. {@see \Thallo\Commerce\Http\Shop\ShopAssetController} resolves ONLY through
 * {@see self::resolve()}'s exact key lookup: there is no string concatenation of the route value
 * into a filesystem path anywhere in this class, so `../etc/passwd`, an absolute path, or any
 * name this map didn't itself compute can never resolve to a real file — it is simply absent
 * from the map, exactly like an unknown filename.
 *
 * {@see ShopUrlGenerator::assets()} calls {@see self::fingerprintedName()} to build the ONE
 * current shop.js URL — the fingerprint changes automatically whenever the shipped file's bytes
 * change (a normal release), so the `public, max-age=31536000, immutable` response header the
 * controller sends is safe: the URL itself is the cache-buster.
 */
final class ShopAssetMap
{
    /** @var array<string,string> fingerprinted filename => absolute path */
    private readonly array $filesByName;

    /** @var array<string,string> logical filename (e.g. 'shop.js') => fingerprinted filename */
    private readonly array $fingerprintsByLogicalName;

    public function __construct(string $assetsDir)
    {
        $filesByName = [];
        $fingerprints = [];

        $dir = rtrim($assetsDir, '/');
        $entries = is_dir($dir) ? (scandir($dir) ?: []) : [];
        sort($entries); // deterministic across platforms/filesystems

        foreach ($entries as $entry) {
            if (!is_string($entry) || $entry === '.' || $entry === '..') {
                continue;
            }
            if (!str_ends_with($entry, '.js') && !str_ends_with($entry, '.css')) {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (!is_file($path)) {
                continue;
            }
            $contents = (string) file_get_contents($path);
            $hash = substr(hash('sha256', $contents), 0, 12);
            $ext = pathinfo($entry, PATHINFO_EXTENSION);
            $stem = pathinfo($entry, PATHINFO_FILENAME);
            $fingerprintedName = $stem . '-' . $hash . '.' . $ext;

            $filesByName[$fingerprintedName] = $path;
            $fingerprints[$entry] = $fingerprintedName;
        }

        $this->filesByName = $filesByName;
        $this->fingerprintsByLogicalName = $fingerprints;
    }

    /** Exact allowlist lookup — an unknown name (or any traversal attempt) always misses. */
    public function resolve(string $filename): ?string
    {
        return $this->filesByName[$filename] ?? null;
    }

    /** e.g. `fingerprintedName('shop.js')` => `'shop-<hash>.js'`, or null if the file is missing. */
    public function fingerprintedName(string $logicalName): ?string
    {
        return $this->fingerprintsByLogicalName[$logicalName] ?? null;
    }
}
