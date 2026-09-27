<?php

declare(strict_types=1);

namespace Thallo\Commerce\Starter;

use Thallo\Contracts\Starter\StarterBlockTypeContributor;
use Thallo\Contracts\Starter\StarterBlockTypeDefinition;
use Thallo\Contracts\Style\StyleTargets;

/**
 * The product page's field blocks (type layouts plan C1): the parts of `shop/product.twig`, placed
 * by the product layout (`product` surface) and nowhere else — `layout_only`, so entry, region and
 * saved-section saves refuse them. Each reads the product the frame hands it (`layout_context`).
 * **Add to cart** (`product_buy`) is the smart block: the variant picker, the quantity stepper, the
 * button, the wishlist heart and the availability line, with fixed internals; every product layout
 * holds it exactly once.
 *
 * Contributed definitions carry no starter data, so an inserted block starts empty: every option
 * that is on by default is named for turning it off (`hide_thumbnails`), and an absent value is
 * always today's page.
 */
final class ProductFieldBlocksContributor implements StarterBlockTypeContributor
{
    /** @var list<string> in the order the product page shows them */
    public const SLUGS = [
        'product_breadcrumb', 'product_gallery', 'product_category', 'product_name', 'product_rating',
        'product_price', 'product_description', 'product_buy', 'product_story',
    ];

    private const CATEGORY = 'Fields';

    private const TEXT = [
        'spacing', 'width', 'alignment.self', 'alignment.text', 'typography', 'colors.text', 'visibility',
        'layout.item',
    ];

    /** A text block whose root is a flex row (breadcrumb, rating, price): text-align moves nothing. */
    private const ROW = [
        'spacing', 'width', 'alignment.self', 'typography', 'colors.text', 'visibility', 'layout.item',
    ];

    private const BOX = ['spacing', 'width', 'visibility', 'layout.item'];

    /** Add to cart: no visibility — every product page keeps its buy button, at every size. */
    private const BUY = ['spacing', 'width', 'layout.item'];

    /**
     * slug => [label, icon, description, style kind, schema]
     *
     * @var array<string, array{string, string, string, 'text'|'row'|'box'|'buy'|'picture', list<array<string,mixed>>}>
     */
    private const BLOCKS = [
        'product_breadcrumb' => ['Product breadcrumb', 'i-lucide-chevrons-right',
            'Shop, the product\'s category and its name.', 'row', [
                ['name' => 'hide_category', 'type' => 'boolean', 'label' => 'Hide the category'],
            ]],
        'product_gallery' => ['Product gallery', 'i-lucide-images',
            'The product\'s images: the cover, with thumbnails of the rest.', 'picture', [
                ['name' => 'hide_thumbnails', 'type' => 'boolean', 'label' => 'Hide the thumbnails'],
                ['name' => 'aspect', 'type' => 'enum', 'enum' => ['4:3', '1:1', 'natural']],
            ]],
        'product_category' => ['Product category', 'i-lucide-tag',
            'The product\'s category, above its name.', 'text', [
                ['name' => 'link', 'type' => 'boolean', 'label' => 'Link to the category'],
            ]],
        'product_name' => ['Product name', 'i-lucide-heading-1', 'The product\'s name.', 'text', [
            ['name' => 'level', 'type' => 'enum', 'enum' => ['h1', 'h2', 'h3', 'h4']],
        ]],
        'product_rating' => ['Product rating', 'i-lucide-star',
            'The product\'s stars and review count.', 'row', [
                ['name' => 'hide_when_none', 'type' => 'boolean', 'label' => 'Hide until it has reviews'],
            ]],
        'product_price' => ['Product price', 'i-lucide-badge-dollar-sign',
            'The price, with the "was" price when it has one.', 'row', [
                ['name' => 'hide_compare_at', 'type' => 'boolean', 'label' => 'Hide the "was" price'],
            ]],
        'product_description' => ['Product description', 'i-lucide-align-left',
            'The product\'s description.', 'text', []],
        'product_buy' => ['Add to cart', 'i-lucide-shopping-cart',
            'Options, quantity and Add to cart — every product page has one.', 'buy', [
                ['name' => 'hide_wishlist', 'type' => 'boolean', 'label' => 'Hide the wishlist heart'],
                ['name' => 'hide_availability', 'type' => 'boolean', 'label' => 'Hide "In stock"'],
            ]],
        'product_story' => ['Product story', 'i-lucide-book-open',
            'The content of the product\'s linked story.', 'box', []],
    ];

    /** @return list<StarterBlockTypeDefinition> */
    public function blockTypeDefinitions(): array
    {
        $definitions = [];
        foreach (self::SLUGS as $slug) {
            [$label, $icon, $description, $kind, $schema] = self::BLOCKS[$slug];
            $definitions[] = new StarterBlockTypeDefinition(
                sourceId: 'thallo-commerce:' . $slug,
                requiresCapability: 'thallo.commerce',
                slug: $slug,
                label: $label,
                icon: $icon,
                category: self::CATEGORY,
                description: $description,
                schema: $schema,
                styleCapabilities: match ($kind) {
                    'text' => self::TEXT,
                    'row' => self::ROW,
                    'box' => self::BOX,
                    'buy' => self::BUY,
                    'picture' => [...self::BOX, 'radius', 'shadow'],
                },
                styleTargets: match ($kind) {
                    'text' => StyleTargets::root('text', self::TEXT),
                    'row' => StyleTargets::root('text', self::ROW),
                    'box' => StyleTargets::root('box', self::BOX),
                    'buy' => StyleTargets::root('box', self::BUY),
                    // The gallery's cover takes the radius and shadow, as the entry cover's picture does.
                    'picture' => StyleTargets::root('box', self::BOX, [
                        'targets' => ['picture' => ['kind' => 'box', 'optional' => true]],
                        'map' => ['radius' => 'picture', 'shadow' => 'picture'],
                    ]),
                },
                flags: ['layout_only' => true],
            );
        }
        return $definitions;
    }
}
