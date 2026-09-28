<?php

declare(strict_types=1);

namespace Thallo\Commerce\Layouts;

/**
 * The shop's layout cache tags (type layouts spec §7.4; plans C1 and C2). Every page of a commerce
 * surface — every product, the shop home, every category — carries its surface's tag in its
 * `Cache-Tag` header, with a layout or without, so a first save purges the pages cached from the
 * theme's template and a removal the pages the layout rendered. The header names no workspace (it
 * reaches visitors); the shop cache stores each tag as the workspace's own ({@see self::tenantTag()}),
 * so a change purges one workspace's pages of one surface. Server-side only.
 */
final class ShopLayoutTags
{
    /** @var list<string> the commerce surfaces whose pages the shop cache holds */
    public const SURFACES = [ProductSurface::KEY, ShopIndexSurface::KEY, ShopCategorySurface::KEY];

    /** The tag a surface's pages carry in their header. */
    public static function pageTag(string $surface): string
    {
        if (!in_array($surface, self::SURFACES, true)) {
            throw new \InvalidArgumentException("\"{$surface}\" is not a shop layout surface");
        }
        return 'thallo:shop:layout:' . $surface;
    }

    /** The tag a surface's pages are stored under in the shop cache, per workspace. */
    public static function tenantTag(string $surface, string $tenant): string
    {
        return self::pageTag($surface) . ':' . $tenant;
    }

    /**
     * How long a generation token is kept. Only memory hygiene: a lost token is replaced by a fresh
     * one, and every entry stored under the lost one is simply never read again.
     */
    public const GENERATION_TTL = 2592000;

    /**
     * Where a workspace's layout generation for a surface is kept (type layouts plan C2): the shop
     * cache keys that surface's pages by it, and every layout change replaces it, so a render that
     * read the old layout stores under a token nothing reads any more. Outside both of the shop's
     * tag-less purge patterns (`shop:{tenant}:*`, `tenant:*:shop:{tenant}:*`): a purge never
     * deletes one.
     */
    public static function generationKey(string $surface, string $tenant): string
    {
        self::pageTag($surface); // a shop layout surface, or it throws
        return 'thallo:layoutgen:shop:' . $surface . ':' . $tenant;
    }

    /** A fresh generation: random, so no loss, race or clock can ever repeat one. */
    public static function freshGeneration(): string
    {
        return bin2hex(random_bytes(16));
    }

    /** Whether a value read back from the cache is a generation token. */
    public static function isGeneration(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[0-9a-f]{32}$/', $value) === 1;
    }
}
