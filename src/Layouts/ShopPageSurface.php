<?php

declare(strict_types=1);

namespace Thallo\Commerce\Layouts;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Tenancy\CommerceTenantResolution;
use Thallo\Commerce\Shop\ShopCatalogPage;
use Thallo\Contracts\Layouts\LayoutSampleContext;
use Thallo\Contracts\Layouts\LayoutSurface;

/**
 * What the shop home and the category pages share as layout surfaces (type layouts plan C2): one
 * site-wide target, the Product list as the required loop and its card blocks, the palette, and the
 * starter — today's page in blocks. Their pages live in the shop cache, which purges them on
 * `LayoutChanged` ({@see ShopLayoutTags}), so they name no rendered-page tags.
 */
abstract class ShopPageSurface implements LayoutSurface, LayoutSampleContext
{
    public const TARGET = '@site';

    /** The loop every shop layout holds, and the blocks that go only inside its card. */
    public const LOOP = 'product_loop';

    /** @var list<string> */
    public const CARD_BLOCKS = [
        'product_tile', 'product_name', 'product_rating', 'product_price', 'product_tags', 'product_add_to_cart',
    ];

    public function __construct(
        protected readonly ApplicationContext $context,
        protected readonly CommerceTenantResolution $tenants,
        protected readonly ShopCatalogPage $page,
    ) {
    }

    public function targets(): array
    {
        return [[
            'target' => self::TARGET, 'label' => $this->label(self::TARGET), 'enabled' => true, 'reason' => null,
            'link' => null,
        ]];
    }

    public function defaultSample(string $target): ?string
    {
        return $this->samples($target, null)[0]['id'] ?? null;
    }

    public function palette(): array
    {
        return [self::LOOP, 'shop_title', 'category_rail', 'pagination', ...self::CARD_BLOCKS];
    }

    public function required(string $target): array
    {
        return [['type' => self::LOOP]];
    }

    public function loops(string $target): array
    {
        return [['type' => self::LOOP, 'card' => 'card', 'items' => self::CARD_BLOCKS]];
    }

    public function bindable(string $target): array
    {
        return [];
    }

    public function pageTags(string $target): array
    {
        return [];
    }

    /**
     * Today's page in blocks: the title with its count, the chips, the Product list, and the page
     * navigation. The card is today's grid card: the tile, then a column of the name above a row of
     * the rating and the price — `.shop-grid__body`'s 0.25rem and `.shop-grid__meta`'s 0.5rem, from
     * the spacing tokens. Nothing else is set: the shop stylesheet's spacing stays in force (a
     * setting would override it), and the Product list's cards are the shop's adaptive grid.
     */
    public function starter(string $target): array
    {
        $token = static fn (string $value): array => ['base' => ['type' => 'token', 'value' => $value]];
        $choice = static fn (string $value): array => ['base' => ['type' => 'choice', 'value' => $value]];
        $block = static fn (string $type, array $data = [], array $settings = []): array => [
            'type' => $type, 'data' => $data, 'settings' => $settings,
        ];
        return [
            $block('shop_title', ['level' => 'h1']),
            $block('category_rail'),
            $block(self::LOOP, ['card' => [
                $block('product_tile'),
                $block('container', ['element' => 'div', 'content' => [
                    $block('product_name', ['level' => 'h2', 'link' => true]),
                    $block('container', ['element' => 'div', 'content' => [
                        $block('product_rating'),
                        $block('product_price'),
                    ]], ['style' => [
                        'layout' => [
                            'display' => $choice('flex'),
                            'direction' => $choice('row'),
                            'align_items' => $choice('center'),
                            'gap' => ['column' => $token('spacing.sm')],
                        ],
                        'alignment' => ['content' => $choice('between')],
                    ]]),
                ]], ['style' => ['layout' => [
                    'display' => $choice('grid'),
                    'gap' => ['row' => $token('spacing.xs')],
                ]]]),
            ]]),
            $block('pagination'),
        ];
    }

    protected function tenant(): string
    {
        return $this->tenants->tenantUuid($this->context);
    }
}
