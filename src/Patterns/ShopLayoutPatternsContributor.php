<?php

declare(strict_types=1);

namespace Thallo\Commerce\Patterns;

use Thallo\Contracts\Layouts\LayoutSurface;
use Thallo\Contracts\Patterns\LayoutPatternContributor;
use Thallo\Contracts\Patterns\LayoutSection;
use Thallo\Contracts\Patterns\LayoutTemplate;
use Thallo\Contracts\Patterns\PatternBlocks as B;

/**
 * The product page's and the shop's sections and templates for the layout editor (sections and
 * templates design §6). Registered only while `thallo.commerce` is enabled, beside the surfaces it
 * serves. The templates close to today's page are the surfaces' own starters; every shop template
 * keeps today's product card, taken from the starter's Product list. No section holds the Product
 * buy box: a product layout already holds exactly one, which its editor refuses to delete, so the
 * Product hero is the gallery beside the name, rating and price.
 */
final class ShopLayoutPatternsContributor implements LayoutPatternContributor
{
    public const ID = 'thallo.commerce';

    private const LOOP = 'product_loop';

    public function __construct(
        private readonly LayoutSurface $product,
        private readonly LayoutSurface $index,
        private readonly LayoutSurface $category,
    ) {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function layoutSections(): array
    {
        return [
            new LayoutSection(
                'product-hero',
                'product',
                'Product hero',
                'Product',
                'The gallery beside the name, rating and price. The layout’s buy box stays where it is.',
                static fn (): array => self::twoColumns(self::block('product_gallery'), self::column([
                    self::block('product_name', ['level' => 'h1']),
                    self::block('product_rating'),
                    self::block('product_price'),
                ])),
            ),
            new LayoutSection(
                'product-details-band',
                'product',
                'Details band',
                'Product',
                'The product’s category and description in a tinted band.',
                static fn (): array => B::band(
                    [self::block('product_category'), self::block('product_description')],
                    [],
                    'color.surface-2',
                ),
            ),
            new LayoutSection(
                'product-story-band',
                'product',
                'Story band',
                'Product',
                'The product’s story in a band of its own.',
                static fn (): array => B::band([self::block('product_story')]),
            ),
            new LayoutSection(
                'shop-index-banner-section',
                'shop_index',
                'Shop banner',
                'Shop',
                'The shop’s title in a tinted banner.',
                static fn (): array => self::banner(),
            ),
            new LayoutSection(
                'shop-index-chips-band',
                'shop_index',
                'Category chips band',
                'Shop',
                'The category chips in a band of their own.',
                static fn (): array => B::band([self::block('category_rail')]),
            ),
            new LayoutSection(
                'shop-category-banner-section',
                'shop_category',
                'Category banner',
                'Shop',
                'The category’s title in a tinted banner.',
                static fn (): array => self::banner(),
            ),
        ];
    }

    public function layoutTemplates(): array
    {
        return [
            new LayoutTemplate(
                'product-gallery-left',
                'product',
                'Gallery left',
                'Today’s product page: the gallery beside the name, price and buy box, the story below.',
                fn (): array => $this->product->starter('@site'),
            ),
            new LayoutTemplate(
                'product-gallery-top',
                'product',
                'Gallery on top',
                'The gallery across the top, then the details and the buy box in a reading column.',
                static fn (): array => [
                    self::block('product_breadcrumb'),
                    self::block('product_gallery'),
                    B::block('container', ['element' => 'div', 'content' => [self::column([
                        self::block('product_category'),
                        self::block('product_name', ['level' => 'h1']),
                        self::block('product_rating'),
                        self::block('product_price'),
                        self::block('product_description'),
                        self::block('product_buy'),
                    ])]], [
                        'width' => ['base' => B::token('width.content')],
                        'alignment' => ['self' => ['base' => B::choice('center')]],
                    ]),
                    self::block('product_story'),
                ],
            ),
            new LayoutTemplate(
                'product-story-led',
                'product',
                'Story-led',
                'The gallery beside the buy box, then the description and the story at the full width of the page.',
                static fn (): array => [
                    B::block('container', ['element' => 'div', 'content' => [
                        self::twoColumns(self::block('product_gallery'), self::column([
                            self::block('product_name', ['level' => 'h1']),
                            self::block('product_price'),
                            self::block('product_rating'),
                            self::block('product_buy'),
                        ])),
                    ]], [
                        'width' => ['base' => B::token('width.container')],
                        'alignment' => ['self' => ['base' => B::choice('center')]],
                    ]),
                    B::band([self::block('product_description')]),
                    self::block('product_story'),
                ],
                ['width' => 'full'],
            ),
            new LayoutTemplate(
                'shop-index-adaptive',
                'shop_index',
                'Adaptive grid',
                'Today’s shop home: the title, the category chips, and as many product columns as fit.',
                fn (): array => $this->index->starter('@site'),
            ),
            new LayoutTemplate(
                'shop-index-banner',
                'shop_index',
                'Banner and grid',
                'The shop’s title in a tinted banner, then the chips and the products.',
                fn (): array => [
                    self::banner(),
                    self::block('category_rail'),
                    self::loopOf($this->index->starter('@site')),
                    self::block('pagination'),
                ],
            ),
            new LayoutTemplate(
                'shop-index-category-led',
                'shop_index',
                'Category-led',
                'The title and the category chips together in a band, then the products.',
                fn (): array => [
                    B::band([self::block('shop_title', ['level' => 'h1']), self::block('category_rail')]),
                    self::loopOf($this->index->starter('@site')),
                    self::block('pagination'),
                ],
            ),
            new LayoutTemplate(
                'shop-category-adaptive',
                'shop_category',
                'Adaptive grid',
                'Today’s category page: the title, the chips, and as many product columns as fit.',
                fn (): array => $this->category->starter('@site'),
            ),
            new LayoutTemplate(
                'shop-category-banner',
                'shop_category',
                'Banner and grid',
                'The category’s title in a tinted banner, then the chips and the products.',
                fn (): array => [
                    self::banner(),
                    self::block('category_rail'),
                    self::loopOf($this->category->starter('@site')),
                    self::block('pagination'),
                ],
            ),
            new LayoutTemplate(
                'shop-category-chips-top',
                'shop_category',
                'Chips on top',
                'The category chips above the title, then the products.',
                fn (): array => [
                    self::block('category_rail'),
                    self::block('shop_title', ['level' => 'h1']),
                    self::loopOf($this->category->starter('@site')),
                    self::block('pagination'),
                ],
            ),
        ];
    }

    /** @return array<string,mixed> the page's title in a tinted banner */
    private static function banner(): array
    {
        return B::band([self::block('shop_title', ['level' => 'h1'])], [], 'color.surface-2');
    }

    /**
     * The Product list of a surface's starter: today's card, as the shop page shows it.
     *
     * @param list<array<string,mixed>> $starter
     * @return array<string,mixed>
     */
    private static function loopOf(array $starter): array
    {
        foreach ($starter as $block) {
            if (($block['type'] ?? null) === self::LOOP) {
                return $block;
            }
        }
        throw new \LogicException('the shop starter holds no Product list');
    }

    /**
     * Two parts side by side from `md`, stacked below — the product page's grid.
     *
     * @param array<string,mixed> $left
     * @param array<string,mixed> $right
     * @return array<string,mixed>
     */
    private static function twoColumns(array $left, array $right): array
    {
        return B::block('container', ['element' => 'div', 'content' => [$left, $right]], ['layout' => [
            'display' => ['base' => B::choice('grid')],
            'columns' => ['base' => B::choice('1'), 'md' => B::choice('2')],
            'gap' => ['column' => ['base' => B::token('spacing.xl')], 'row' => ['base' => B::token('spacing.xl')]],
            'align_items' => ['base' => B::choice('start')],
        ]]);
    }

    /**
     * @param list<array<string,mixed>> $content
     * @return array<string,mixed> a column of `$content` a small step apart
     */
    private static function column(array $content): array
    {
        return B::block('container', ['element' => 'div', 'content' => $content], ['layout' => [
            'display' => ['base' => B::choice('flex')],
            'direction' => ['base' => B::choice('column')],
            'gap' => ['row' => ['base' => B::token('spacing.sm')]],
        ]]);
    }

    /**
     * @param array<string,mixed> $data
     * @return array{type: string, data: array<string,mixed>, settings: array<string,mixed>}
     */
    private static function block(string $type, array $data = []): array
    {
        return ['type' => $type, 'data' => $data, 'settings' => []];
    }
}
