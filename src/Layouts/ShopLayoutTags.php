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
}
