<?php

declare(strict_types=1);

namespace Thallo\Commerce\Starter;

use Thallo\Contracts\Starter\StarterBlockTypeContributor;
use Thallo\Contracts\Style\StyleTargets;
use Thallo\Contracts\Starter\StarterBlockTypeDefinition;

/**
 * Task 11 (storefront-rendering spec §5.2/§10) + storefront-v1 spec §5: this pack's
 * contribution to the starter block-type library — the 5 batteries-included shop blocks
 * (`wishlist-link` joined the original four), mirroring
 * {@see \Thallo\Commerce\Starter\ProductStoryContributor}'s Slice-1 pattern exactly but for
 * {@see \Thallo\Contracts\Starter\StarterBlockTypeRegistry} instead of the content-type registry.
 * `sourceId`s are stable `thallo-commerce:{slug}` identifiers (survive a future slug rename,
 * same reasoning as ProductStoryContributor's own sourceId doc).
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
    public const SLUG_WISHLIST_LINK = 'wishlist-link';

    private const CATEGORY = 'Commerce';

    /** @return list<StarterBlockTypeDefinition> */
    public function blockTypeDefinitions(): array
    {
        return [
            new StarterBlockTypeDefinition(
                sourceId: 'thallo-commerce:' . self::SLUG_PRODUCT_GRID,
                requiresCapability: 'thallo.commerce',
                slug: self::SLUG_PRODUCT_GRID,
                label: 'Product grid',
                icon: 'i-lucide-layout-grid',
                category: self::CATEGORY,
                description: 'A grid of products: all, on sale or hand-picked, narrowed by categories and tags.',
                schema: [
                    [
                        'name' => 'source', 'label' => 'Source', 'type' => 'enum', 'group' => 'Query',
                        'enum' => ['all', 'on_sale', 'manual'],
                        'enum_labels' => [
                            'all' => 'All products', 'on_sale' => 'On sale', 'manual' => 'Manual selection',
                        ],
                    ],
                    [
                        'name' => 'categories', 'label' => 'Categories', 'type' => 'string', 'group' => 'Query',
                        'multiple' => true, 'max_items' => 20, 'options_source' => 'thallo-commerce.categories',
                        'help' => 'Products in any of these. Not used by Manual selection.',
                    ],
                    [
                        'name' => 'tags', 'label' => 'Tags', 'type' => 'string', 'group' => 'Query',
                        'multiple' => true, 'max_items' => 20, 'options_source' => 'thallo-commerce.tags',
                        'help' => 'Products with any of these (and in a chosen category). '
                            . 'Not used by Manual selection.',
                    ],
                    // One product slug per line — normalized/deduped/capped by ManualProductListNormalizer.
                    [
                        'name' => 'products', 'label' => 'Products', 'type' => 'text', 'group' => 'Query',
                        'help' => 'Manual selection only — one product slug per line.',
                    ],
                    [
                        'name' => 'exclude_out_of_stock', 'label' => 'Exclude out of stock', 'type' => 'boolean',
                        'group' => 'Query',
                    ],
                    [
                        'name' => 'order_by', 'label' => 'Order by', 'type' => 'enum', 'group' => 'Query',
                        'enum' => ['newest', 'price_asc', 'price_desc', 'name'],
                        'enum_labels' => [
                            'newest' => 'Newest', 'price_asc' => 'Price: low to high',
                            'price_desc' => 'Price: high to low', 'name' => 'Name',
                        ],
                        'help' => 'Not used by Manual selection.',
                    ],
                    [
                        'name' => 'limit', 'label' => 'Products to show', 'type' => 'number', 'min' => 1, 'max' => 48,
                        'group' => 'Query',
                        'help' => 'Columns step down to 3 on tablets and 2 on phones; '
                            . '4 products in 4 columns is one row on a desktop.',
                    ],
                    [
                        'name' => 'columns', 'label' => 'Columns', 'type' => 'enum', 'group' => 'Query',
                        'enum' => ['auto', '2', '3', '4', '5', '6'], 'enum_labels' => ['auto' => 'Auto'],
                    ],
                    ['name' => 'show_image', 'label' => 'Show image', 'type' => 'boolean', 'group' => 'Display'],
                    ['name' => 'show_title', 'label' => 'Show title', 'type' => 'boolean', 'group' => 'Display'],
                    [
                        'name' => 'title_tag', 'label' => 'Title tag', 'type' => 'enum', 'group' => 'Display',
                        'enum' => ['h2', 'h3', 'h4'], 'enum_labels' => ['h2' => 'H2', 'h3' => 'H3', 'h4' => 'H4'],
                    ],
                    ['name' => 'show_price', 'label' => 'Show price', 'type' => 'boolean', 'group' => 'Display'],
                    ['name' => 'show_rating', 'label' => 'Show rating', 'type' => 'boolean', 'group' => 'Display'],
                    [
                        'name' => 'show_categories', 'label' => 'Show categories', 'type' => 'boolean',
                        'group' => 'Display',
                    ],
                    ['name' => 'show_tags', 'label' => 'Show tags', 'type' => 'boolean', 'group' => 'Display'],
                    [
                        'name' => 'show_add_to_cart', 'label' => 'Show add to cart', 'type' => 'boolean',
                        'group' => 'Display',
                    ],
                    ['name' => 'show_wishlist', 'label' => 'Show wishlist', 'type' => 'boolean', 'group' => 'Display'],
                    [
                        'name' => 'show_sale_badge', 'label' => 'Show sale badge', 'type' => 'boolean',
                        'group' => 'Badges',
                    ],
                    [
                        'name' => 'sale_badge_text', 'label' => 'Sale badge text', 'type' => 'string',
                        'group' => 'Badges',
                    ],
                    ['name' => 'show_new_badge', 'label' => 'Show new badge', 'type' => 'boolean', 'group' => 'Badges'],
                    ['name' => 'new_badge_text', 'label' => 'New badge text', 'type' => 'string', 'group' => 'Badges'],
                    [
                        'name' => 'new_badge_days', 'label' => 'New badge days', 'type' => 'number', 'min' => 1,
                        'max' => 365, 'group' => 'Badges', 'help' => 'Products created within this many days.',
                    ],
                    [
                        'name' => 'badge_position', 'label' => 'Badge position', 'type' => 'enum', 'group' => 'Badges',
                        'enum' => ['top-left', 'top-right'],
                        'enum_labels' => ['top-left' => 'Top left', 'top-right' => 'Top right'],
                    ],
                    [
                        'name' => 'card_hover', 'label' => 'Card hover effect', 'type' => 'enum', 'group' => 'Card',
                        'enum' => ['none', 'lift', 'shadow'],
                        'enum_labels' => ['none' => 'None', 'lift' => 'Lift', 'shadow' => 'Shadow'],
                    ],
                    [
                        'name' => 'image_ratio', 'label' => 'Image ratio', 'type' => 'enum', 'group' => 'Card',
                        'enum' => ['square', 'portrait', 'landscape'],
                        'enum_labels' => [
                            'square' => 'Square', 'portrait' => 'Portrait (4:5)', 'landscape' => 'Landscape (4:3)',
                        ],
                    ],
                    [
                        'name' => 'image_fit', 'label' => 'Image fit', 'type' => 'enum', 'group' => 'Card',
                        'enum' => ['contain', 'cover'],
                        'enum_labels' => ['contain' => 'Whole product', 'cover' => 'Fill the frame'],
                    ],
                    [
                        'name' => 'image_hover', 'label' => 'Image hover effect', 'type' => 'enum', 'group' => 'Card',
                        'enum' => ['none', 'zoom'], 'enum_labels' => ['none' => 'None', 'zoom' => 'Zoom'],
                    ],
                ],
                starterContent: [
                    'source' => 'all', 'categories' => [], 'tags' => [], 'exclude_out_of_stock' => false,
                    'order_by' => 'newest', 'limit' => 12, 'columns' => 'auto',
                    'show_image' => true, 'show_title' => true, 'title_tag' => 'h3', 'show_price' => true,
                    'show_rating' => true, 'show_categories' => true, 'show_tags' => false,
                    'show_add_to_cart' => true, 'show_wishlist' => true,
                    'show_sale_badge' => false, 'sale_badge_text' => 'Sale', 'show_new_badge' => false,
                    'new_badge_text' => 'New', 'new_badge_days' => 7, 'badge_position' => 'top-left',
                    'card_hover' => 'none', 'image_ratio' => 'square', 'image_fit' => 'contain',
                    'image_hover' => 'none',
                ],
                styleCapabilities: ['spacing', 'width', 'visibility', 'layout.item'],
                styleTargets: StyleTargets::root(
                    'box',
                    ['spacing', 'width', 'visibility', 'layout.item'],
                ) + ['parts' => [
                    'card' => ['label' => 'Card', 'capabilities' => [
                        'colors.surface', 'colors.border', 'border', 'radius', 'shadow',
                        'spacing.padding.top', 'spacing.padding.right',
                        'spacing.padding.bottom', 'spacing.padding.left',
                        'opacity', 'hover',
                    ]],
                    'image' => ['label' => 'Image', 'capabilities' => ['radius']],
                    // On the name's anchor, where pointer and keyboard focus land.
                    'title' => ['label' => 'Title', 'capabilities' => ['typography', 'colors.text', 'hover']],
                    'price' => ['label' => 'Price', 'capabilities' => ['typography', 'colors.text']],
                    'meta' => ['label' => 'Meta', 'capabilities' => ['typography', 'colors.text']],
                    'button' => ['label' => 'Button', 'capabilities' => [
                        'colors', 'border', 'radius', 'typography',
                        'spacing.padding.top', 'spacing.padding.right',
                        'spacing.padding.bottom', 'spacing.padding.left',
                        'opacity', 'hover',
                    ]],
                    'badge' => [
                        'label' => 'Badge',
                        'capabilities' => ['colors.surface', 'colors.text', 'radius', 'typography'],
                    ],
                ]],
            ),
            new StarterBlockTypeDefinition(
                sourceId: 'thallo-commerce:' . self::SLUG_FEATURED_PRODUCT,
                requiresCapability: 'thallo.commerce',
                slug: self::SLUG_FEATURED_PRODUCT,
                label: 'Featured product',
                icon: 'i-lucide-star',
                category: self::CATEGORY,
                description: 'Spotlight a single product.',
                schema: [
                    ['name' => 'product_slug', 'type' => 'string'],
                ],
                styleCapabilities: ['spacing', 'radius', 'shadow', 'colors', 'border', 'visibility', 'layout.item'],
                styleTargets: StyleTargets::root('box', [
                    'spacing', 'radius', 'shadow', 'colors', 'border', 'visibility', 'layout.item',
                ]),
            ),
            new StarterBlockTypeDefinition(
                sourceId: 'thallo-commerce:' . self::SLUG_ADD_TO_CART,
                requiresCapability: 'thallo.commerce',
                slug: self::SLUG_ADD_TO_CART,
                label: 'Add to cart',
                icon: 'i-lucide-shopping-cart',
                category: self::CATEGORY,
                description: 'An add-to-cart control for a product — falls back to the enriched '
                    . 'product on a linked Product story.',
                schema: [
                    // Deliberately not `required`: the block falls back to the enriched product
                    // context (the current entry's linked commerce product) when left blank.
                    ['name' => 'product_slug', 'type' => 'string'],
                ],
                styleCapabilities: ['spacing', 'visibility', 'layout.item'],
                styleTargets: StyleTargets::root('box', ['spacing', 'visibility', 'layout.item']),
            ),
            new StarterBlockTypeDefinition(
                sourceId: 'thallo-commerce:' . self::SLUG_MINI_CART,
                requiresCapability: 'thallo.commerce',
                slug: self::SLUG_MINI_CART,
                label: 'Mini cart',
                icon: 'i-lucide-shopping-bag',
                category: self::CATEGORY,
                description: 'A cart count/drawer that hydrates live via JavaScript; a plain '
                    . 'cart link without it.',
                schema: [],
                // The look is the cart button's (the `control` target), as a Button's is its link's;
                // the block keeps its spacing, visibility and placement.
                styleCapabilities: [
                    'spacing', 'visibility', 'layout.item', 'colors', 'border', 'radius', 'shadow',
                ],
                styleTargets: StyleTargets::root('box', ['spacing', 'visibility', 'layout.item'], [
                    'targets' => ['control' => ['kind' => 'box']],
                    'map' => [
                        'colors' => 'control', 'border' => 'control', 'radius' => 'control', 'shadow' => 'control',
                    ],
                ]) + ['parts' => [
                    // The drop-down that opens from the button: a look of its own.
                    'panel' => ['label' => 'Panel', 'capabilities' => [
                        'colors', 'border', 'radius', 'shadow',
                        'spacing.padding.top', 'spacing.padding.right',
                        'spacing.padding.bottom', 'spacing.padding.left',
                    ]],
                ]],
            ),
            // Storefront-v1 spec §5: a LINK to the wishlist page, mirroring the mini cart
            // exactly (capability-gated, cacheable zero-count shell, JS-hydrated badge).
            new StarterBlockTypeDefinition(
                sourceId: 'thallo-commerce:' . self::SLUG_WISHLIST_LINK,
                requiresCapability: 'thallo.commerce',
                slug: self::SLUG_WISHLIST_LINK,
                label: 'Wishlist link',
                icon: 'i-lucide-heart',
                category: self::CATEGORY,
                description: 'A link to the wishlist page with a live saved-item count; a plain '
                    . 'wishlist link without JavaScript.',
                schema: [
                    // Optional: blank renders the icon with a screen-reader-only "Wishlist".
                    ['name' => 'label', 'type' => 'string'],
                ],
                // The look is the link's (the `control` target), its label's text included; the
                // block keeps its spacing, visibility and placement. Optional: with commerce off the
                // block renders plain text and no link.
                styleCapabilities: [
                    'spacing', 'visibility', 'layout.item', 'colors', 'border', 'radius', 'shadow', 'typography',
                ],
                styleTargets: StyleTargets::root('box', ['spacing', 'visibility', 'layout.item'], [
                    'targets' => ['control' => ['kind' => 'box', 'optional' => true]],
                    'map' => [
                        'colors' => 'control', 'border' => 'control', 'radius' => 'control',
                        'shadow' => 'control', 'typography' => 'control',
                    ],
                ]),
            ),
        ];
    }
}
