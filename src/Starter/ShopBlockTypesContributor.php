<?php

declare(strict_types=1);

namespace Thallo\Commerce\Starter;

use Thallo\Contracts\Starter\StarterBlockTypeContributor;
use Thallo\Contracts\Starter\StarterBlockTypeDefinition;

/**
 * Task 11 (storefront-rendering spec §5.2/§10): this pack's contribution to the starter
 * block-type library — the 4 batteries-included shop blocks, mirroring
 * {@see \Thallo\Commerce\Starter\ProductPageContributor}'s Slice-1 pattern exactly but for
 * {@see \Thallo\Contracts\Starter\StarterBlockTypeRegistry} instead of the content-type registry.
 * `sourceId`s are stable `thallo-commerce:{slug}` identifiers (survive a future slug rename,
 * same reasoning as ProductPageContributor's own sourceId doc).
 *
 * Field-shape mirrors the engine's fixed block-type vocabulary (slug/label/icon/category/
 * description/schema; field types string/text/enum/boolean/blocks) — the engine-side conversion
 * step runs the SAME schema-validation rule on these as the fixed set (packs never reference the
 * engine's own namespace, so this contribution only carries the shape, not the reference). The
 * `product-grid` manual list is pinned to a newline-delimited
 * `text` field (task-11 brief): the fixed field vocabulary has no repeatable scalar, so one slug
 * per line is the only faithful shape; {@see \Thallo\Commerce\Shop\ManualProductListNormalizer} is
 * the server-side normalizer {@see \Thallo\Commerce\Http\Shop\ShopBlockDataController} applies
 * when a `product-grid` block actually resolves that source.
 */
final class ShopBlockTypesContributor implements StarterBlockTypeContributor
{
    public const SLUG_PRODUCT_GRID = 'product-grid';
    public const SLUG_FEATURED_PRODUCT = 'featured-product';
    public const SLUG_ADD_TO_CART = 'add-to-cart';
    public const SLUG_MINI_CART = 'mini-cart';

    private const CATEGORY = 'Commerce';

    /** @return list<StarterBlockTypeDefinition> */
    public function blockTypeDefinitions(): array
    {
        return [
            new StarterBlockTypeDefinition(
                sourceId: 'thallo-commerce:' . self::SLUG_PRODUCT_GRID,
                slug: self::SLUG_PRODUCT_GRID,
                label: 'Product grid',
                icon: 'i-lucide-layout-grid',
                category: self::CATEGORY,
                description: 'A grid of products from a category, tag, manual list, or the newest arrivals.',
                schema: [
                    ['name' => 'source', 'type' => 'enum', 'enum' => ['category', 'tag', 'manual', 'newest']],
                    ['name' => 'category_slug', 'type' => 'string'],
                    ['name' => 'tag_slug', 'type' => 'string'],
                    // One product slug per line (task-11 brief) — normalized/deduped/capped by
                    // ManualProductListNormalizer at resolve time, never at schema/save time.
                    ['name' => 'products', 'type' => 'text'],
                    ['name' => 'page_size', 'type' => 'enum', 'enum' => ['small', 'medium', 'large']],
                ],
            ),
            new StarterBlockTypeDefinition(
                sourceId: 'thallo-commerce:' . self::SLUG_FEATURED_PRODUCT,
                slug: self::SLUG_FEATURED_PRODUCT,
                label: 'Featured product',
                icon: 'i-lucide-star',
                category: self::CATEGORY,
                description: 'Spotlight a single product.',
                schema: [
                    ['name' => 'product_slug', 'type' => 'string'],
                ],
            ),
            new StarterBlockTypeDefinition(
                sourceId: 'thallo-commerce:' . self::SLUG_ADD_TO_CART,
                slug: self::SLUG_ADD_TO_CART,
                label: 'Add to cart',
                icon: 'i-lucide-shopping-cart',
                category: self::CATEGORY,
                description: 'An add-to-cart control for a product — falls back to the enriched '
                    . 'product on a linked Product page.',
                schema: [
                    // Deliberately not `required`: the block falls back to the enriched product
                    // context (the current entry's linked commerce product) when left blank.
                    ['name' => 'product_slug', 'type' => 'string'],
                ],
            ),
            new StarterBlockTypeDefinition(
                sourceId: 'thallo-commerce:' . self::SLUG_MINI_CART,
                slug: self::SLUG_MINI_CART,
                label: 'Mini cart',
                icon: 'i-lucide-shopping-bag',
                category: self::CATEGORY,
                description: 'A cart count/drawer that hydrates live via JavaScript; a plain '
                    . 'cart link without it.',
                schema: [],
            ),
        ];
    }
}
