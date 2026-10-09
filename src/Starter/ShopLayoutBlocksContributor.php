<?php

declare(strict_types=1);

namespace Thallo\Commerce\Starter;

use Thallo\Contracts\Starter\StarterBlockTypeContributor;
use Thallo\Contracts\Starter\StarterBlockTypeDefinition;
use Thallo\Contracts\Style\StyleTargets;

/**
 * The shop home and category pages' blocks (type layouts plan C2): the parts of `shop/index.twig`
 * and `shop/category.twig`, placed by the `shop_index` and `shop_category` layouts and nowhere else —
 * `layout_only`, so entry, region and saved-section saves refuse them. Each reads the page the frame
 * hands it (`layout_context`).
 *
 * **Product list** (`product_loop`) is the page's loop: every product on the page, each shown as the
 * card the layout designs once; every shop layout holds it exactly once, and its cards are an
 * adaptive grid until someone arranges them (its `cards` target declares the shop stylesheet's
 * tracks as its defaults, so the inspector and the emitter both know). **Product tile** is the
 * card's smart block: the picture, the category chip and the quick actions, with fixed internals —
 * the grid's cart honesty stays in one place (`shop/_product_tile.twig`). **Product tags** is the
 * card's row of tags — and its categories, when asked — as the Product grid card's labels. **Add
 * to cart button** is the card's labelled button — the Product grid's, made a block: Add to cart,
 * Choose options, or Sold out.
 *
 * Contributed definitions carry no starter data, so an inserted block starts empty: every option
 * that is on by default is named for turning it off (`hide_count`), and an absent value is always
 * today's page.
 */
final class ShopLayoutBlocksContributor implements StarterBlockTypeContributor
{
    /** @var list<string> in the order the shop pages show them */
    public const SLUGS = [
        'shop_title', 'category_rail', 'product_loop', 'product_tile', 'product_tags', 'product_add_to_cart',
    ];

    /** The shop stylesheet's `.shop-grid`, as the Product list's cards are before anyone arranges them. */
    public const CARD_DEFAULTS = [
        'display' => 'grid',
        'columns' => ['label' => 'Adaptive — as many 15rem columns as fit'],
        'gap' => ['row' => '1.75rem', 'column' => '1.5rem'],
    ];

    private const CATEGORY = 'Fields';

    private const BOX = ['spacing', 'width', 'visibility', 'layout.item'];

    /** What arranges the Product list's cards: the container's layout capabilities. */
    private const CARDS = [
        'layout.display', 'layout.direction', 'layout.wrap', 'alignment.content', 'layout.align_items',
        'layout.columns', 'layout.gap.column', 'layout.gap.row',
    ];

    /** A button's styles: the Product grid's Button part, on the Add to cart button block. */
    private const BUTTON = [
        'colors', 'border', 'radius', 'typography',
        'spacing.padding.top', 'spacing.padding.right', 'spacing.padding.bottom', 'spacing.padding.left',
        'opacity', 'hover',
    ];

    /** A round icon button on the picture: the quick add, the heart. */
    private const ICON_BUTTON = ['colors', 'border', 'radius', 'opacity', 'hover'];

    /** A small label: the category chip and a badge on the picture, a tag under the name. */
    private const LABEL = ['colors.surface', 'colors.text', 'radius', 'typography'];

    /** @return list<StarterBlockTypeDefinition> */
    public function blockTypeDefinitions(): array
    {
        return [
            $this->definition(
                'shop_title',
                'Shop title',
                'i-lucide-heading-1',
                'The page\'s heading — "Shop", or the category\'s name — and its product count.',
                [
                    ['name' => 'level', 'type' => 'enum', 'enum' => ['h1', 'h2', 'h3', 'h4']],
                    ['name' => 'hide_count', 'type' => 'boolean', 'label' => 'Hide the product count'],
                ],
                [...self::BOX, 'typography', 'colors.text', 'alignment.text'],
                // The row is the block's box; the heading takes the text styles.
                StyleTargets::root('box', self::BOX, [
                    'targets' => ['heading' => ['kind' => 'text']],
                    'map' => ['typography' => 'heading', 'colors.text' => 'heading', 'alignment.text' => 'heading'],
                ]),
            ),
            $this->definition(
                'category_rail',
                'Category chips',
                'i-lucide-tags',
                'A chip for every category, with "All" for the whole shop.',
                [['name' => 'all_label', 'type' => 'string', 'label' => '"All" label']],
                self::BOX,
                StyleTargets::root('box', self::BOX),
            ),
            $this->definition(
                'product_loop',
                'Product list',
                'i-lucide-layout-grid',
                'Every product on the page, each shown as the card you design once.',
                [
                    ['name' => 'card', 'type' => 'blocks'],
                    ['name' => 'empty_text', 'type' => 'string', 'label' => 'When there are no products'],
                    [
                        'name' => 'card_hover', 'label' => 'Card hover effect', 'type' => 'enum',
                        'enum' => ['none', 'lift', 'shadow'],
                        'enum_labels' => ['none' => 'None', 'lift' => 'Lift', 'shadow' => 'Shadow'],
                    ],
                ],
                // No visibility: every shop page shows its products, at every size.
                ['spacing', 'width', 'layout.item', ...self::CARDS],
                // The cards arrange in `cards`; the Card part styles every card, as the Product grid's.
                StyleTargets::root('box', ['spacing', 'width', 'layout.item'], [
                    'targets' => ['cards' => ['kind' => 'stack', 'defaults' => self::CARD_DEFAULTS]],
                    'map' => array_fill_keys(self::CARDS, 'cards'),
                ]) + ['parts' => [
                    'card' => ['label' => 'Card', 'capabilities' => [
                        'colors.surface', 'colors.border', 'border', 'radius', 'shadow',
                        'spacing.padding.top', 'spacing.padding.right',
                        'spacing.padding.bottom', 'spacing.padding.left',
                        'opacity', 'hover',
                    ]],
                ]],
            ),
            $this->definition(
                'product_tile',
                'Product tile',
                'i-lucide-image',
                'The product\'s picture, its category and the quick Add to cart and wishlist buttons.',
                [
                    ['name' => 'hide_tag', 'type' => 'boolean', 'label' => 'Hide the category'],
                    ['name' => 'hide_actions', 'type' => 'boolean', 'label' => 'Hide the quick buttons'],
                    ['name' => 'hide_cart', 'type' => 'boolean', 'label' => 'Hide quick add'],
                    ['name' => 'hide_wishlist', 'type' => 'boolean', 'label' => 'Hide wishlist'],
                    // The Product grid card's picture and badges (product grid spec §5, §7.2).
                    [
                        'name' => 'image_ratio', 'label' => 'Image ratio', 'type' => 'enum', 'group' => 'Picture',
                        'enum' => ['square', 'portrait', 'landscape'],
                        'enum_labels' => [
                            'square' => 'Square', 'portrait' => 'Portrait (4:5)', 'landscape' => 'Landscape (4:3)',
                        ],
                    ],
                    [
                        'name' => 'image_fit', 'label' => 'Image fit', 'type' => 'enum', 'group' => 'Picture',
                        'enum' => ['contain', 'cover'],
                        'enum_labels' => ['contain' => 'Whole product', 'cover' => 'Fill the frame'],
                    ],
                    [
                        'name' => 'image_hover', 'label' => 'Image hover effect', 'type' => 'enum',
                        'group' => 'Picture', 'enum' => ['none', 'zoom'],
                        'enum_labels' => ['none' => 'None', 'zoom' => 'Zoom'],
                    ],
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
                ],
                self::BOX,
                // The tile is the box; its pieces style apart, as the Product grid card's do.
                StyleTargets::root('box', self::BOX) + ['parts' => [
                    'image' => ['label' => 'Image', 'capabilities' => ['colors.surface', 'radius']],
                    'quick_add' => ['label' => 'Quick add', 'capabilities' => self::ICON_BUTTON],
                    'wishlist' => ['label' => 'Wishlist', 'capabilities' => self::ICON_BUTTON],
                    'chip' => ['label' => 'Category chip', 'capabilities' => self::LABEL],
                    'badge' => ['label' => 'Badge', 'capabilities' => self::LABEL],
                ]],
            ),
            $this->definition(
                'product_tags',
                'Product tags',
                'i-lucide-tags',
                'The product\'s tags, and its categories if you like, as small labels.',
                [['name' => 'with_categories', 'type' => 'boolean', 'label' => 'Show the categories too']],
                self::BOX,
                // The block is the row; every label takes the Label part's styles.
                StyleTargets::root('box', self::BOX) + ['parts' => [
                    'label' => ['label' => 'Label', 'capabilities' => self::LABEL],
                ]],
            ),
            $this->definition(
                'product_add_to_cart',
                'Add to cart button',
                'i-lucide-shopping-bag',
                'A labelled button that adds the card\'s product, asks for its options, or says Sold out.',
                [],
                self::BOX,
                // The block is the row; the button inside it takes the button's styles.
                StyleTargets::root('box', self::BOX) + ['parts' => [
                    'button' => ['label' => 'Button', 'capabilities' => self::BUTTON],
                ]],
            ),
        ];
    }

    /**
     * @param list<array<string,mixed>> $schema
     * @param list<string> $capabilities
     * @param array<string,mixed> $targets
     */
    private function definition(
        string $slug,
        string $label,
        string $icon,
        string $description,
        array $schema,
        array $capabilities,
        array $targets,
    ): StarterBlockTypeDefinition {
        return new StarterBlockTypeDefinition(
            sourceId: 'thallo-commerce:' . $slug,
            requiresCapability: 'thallo.commerce',
            slug: $slug,
            label: $label,
            icon: $icon,
            category: self::CATEGORY,
            description: $description,
            schema: $schema,
            styleCapabilities: $capabilities,
            styleTargets: $targets,
            flags: ['layout_only' => true],
        );
    }
}
