<?php

declare(strict_types=1);

namespace Thallo\Commerce\Patterns;

use Thallo\Commerce\Starter\ShopBlockTypesContributor;
use Thallo\Contracts\Patterns\PatternBlocks as B;
use Thallo\Contracts\Patterns\PatternContributor;
use Thallo\Contracts\Patterns\PatternSection;
use Thallo\Contracts\Patterns\PatternTemplate;

/**
 * The shop's sections and page templates for the page library (sections and templates design §6),
 * registered only while the `thallo.commerce` capability is on.
 *
 * Portable by construction: no pattern carries a value of one site's shop. A Product grid ships on
 * the `newest` source, which works as inserted on any shop. Featured product and Add to cart have
 * no such default — they need a product — so their sections, and any template holding one, declare
 * `requires: 'product'`: the palette says so on the card, and until a product is chosen the block
 * shows a notice on the stage and nothing on the site. Every link is `#`.
 */
final class ShopPatternsContributor implements PatternContributor
{
    public const ID = 'thallo.commerce';

    public function id(): string
    {
        return self::ID;
    }

    public function sections(): array
    {
        return [
            new PatternSection(
                'shop-new-arrivals',
                'New arrivals',
                'Shop',
                'A heading over the newest products, with a link to the whole shop.',
                B::band([
                    B::header('New in', 'Just arrived', 'The latest additions to the shop.'),
                    self::grid('medium'),
                    B::block('container', ['element' => 'div', 'content' => [B::button('Shop all', 'outline')]], [
                        'alignment' => ['self' => ['base' => B::choice('center')]],
                    ]),
                ]),
            ),
            new PatternSection(
                'shop-collection-grid',
                'Collection grid',
                'Shop',
                'A heading over a large grid of products. It starts on the newest; point it at a category.',
                B::band([
                    B::header('Collection', 'Shop the collection', null),
                    self::grid('large'),
                ]),
            ),
            new PatternSection(
                'shop-featured-spotlight',
                'Featured product',
                'Shop',
                'One product beside a short pitch and a button. Choose the product after inserting it.',
                B::band([
                    B::stack([
                        B::heading('Our pick this month', 'h2', 'start'),
                        B::text(
                            '<p>A short line on why this piece earns its place: the material, the maker, the '
                                . 'detail people notice first.</p>',
                            'start',
                            'color.muted',
                        ),
                        B::button('Shop now', 'solid'),
                    ], 'start'),
                    B::block(ShopBlockTypesContributor::SLUG_FEATURED_PRODUCT, ['product_slug' => '']),
                ], B::splitAtLg()),
                'product',
            ),
            new PatternSection(
                'shop-add-to-cart-cta',
                'Add-to-cart call to action',
                'Shop',
                'A closing line with the product’s add-to-cart right beside it. Choose the product after '
                    . 'inserting it.',
                B::band([
                    B::stack([
                        B::heading('Ready when you are', 'h2', 'start'),
                        B::text(
                            '<p>Free delivery on every order, and thirty days to change your mind.</p>',
                            'start',
                            'color.muted',
                        ),
                    ], 'start'),
                    B::block(ShopBlockTypesContributor::SLUG_ADD_TO_CART, ['product_slug' => '']),
                ], B::splitAtLg(), 'color.surface-2'),
                'product',
            ),
            new PatternSection(
                'shop-sale-banner',
                'Sale banner',
                'Shop',
                'A headline banner for a sale or a new collection, with one button.',
                B::hero(
                    [
                        'headline' => 'Limited time',
                        'title' => 'The seasonal sale is on',
                        'description' => 'Up to a third off selected pieces while stocks last. When they are gone, '
                            . 'they are gone.',
                        'heading_level' => 'h1',
                    ],
                    ['Shop the sale'],
                ),
            ),
            new PatternSection(
                'shop-reasons',
                'Reasons to buy',
                'Shop',
                'Three reasons to buy from you — delivery, returns and a secure checkout — in a row.',
                B::band([
                    B::header('Why shop with us', 'Made to last, shipped with care', null),
                    B::grid('3', [
                        B::feature('truck', 'Free delivery', 'Every order ships free, packed by hand.'),
                        B::feature('rotate-ccw', 'Easy returns', 'Thirty days to send it back, no questions asked.'),
                        B::feature('shield-check', 'Secure checkout', 'Card details never touch our servers.'),
                    ], '3'),
                ]),
            ),
            new PatternSection(
                'shop-product-faq',
                'Product FAQ',
                'Shop',
                'A heading and four questions buyers ask before they order, opening in place.',
                B::band([
                    B::header('Questions', 'Before you buy', null),
                    // The accordion takes no width of its own: a container gives it a readable measure.
                    B::block('container', ['element' => 'div', 'content' => [
                        B::block('accordion', ['items' => [
                            B::question('How long does delivery take?', 'Say how many days, and where you ship to.'),
                            B::question('Can I return it?', 'Name the window and who pays for the postage.'),
                            B::question('How do I care for it?', 'One or two lines on cleaning and storing it.'),
                            B::question('Which payments do you take?', 'List the cards and wallets you accept.'),
                        ]]),
                    ]], [
                        'width' => ['base' => B::token('width.content')],
                        'alignment' => ['self' => ['base' => B::choice('center')]],
                    ]),
                ]),
            ),
            new PatternSection(
                'shop-cta-band',
                'Shop call to action',
                'Shop',
                'A band that sends readers to the shop, to close a page.',
                B::band([
                    B::cta(
                        'Find something you’ll love',
                        'New pieces arrive every week. Browse the whole shop.',
                        'soft',
                        'horizontal',
                        ['Browse the shop'],
                    ),
                ]),
            ),
        ];
    }

    public function templates(): array
    {
        return [
            new PatternTemplate(
                'shop-landing',
                'Shop landing',
                'A sale banner, new arrivals, a featured product, reasons to buy and a call to action.',
                ['shop-sale-banner', 'shop-new-arrivals', 'shop-featured-spotlight', 'shop-reasons', 'shop-cta-band'],
            ),
            new PatternTemplate(
                'shop-product-launch',
                'Product launch',
                'A featured product, its features, a product FAQ and an add-to-cart call to action.',
                ['shop-featured-spotlight', 'features-grid', 'shop-product-faq', 'shop-add-to-cart-cta'],
            ),
            new PatternTemplate(
                'shop-sale',
                'Sale / collection',
                'A sale banner, a collection grid, reasons to buy and a call to action.',
                ['shop-sale-banner', 'shop-collection-grid', 'shop-reasons', 'shop-cta-band'],
            ),
            new PatternTemplate(
                'shop-new-arrivals-page',
                'New arrivals',
                'A page header, the newest products, a featured product and a call to action.',
                ['page-header', 'shop-new-arrivals', 'shop-featured-spotlight', 'shop-cta-band'],
            ),
        ];
    }

    /** @return array<string,mixed> a Product grid on the newest products — works on any shop as inserted */
    private static function grid(string $pageSize): array
    {
        return B::block(ShopBlockTypesContributor::SLUG_PRODUCT_GRID, ['source' => 'newest', 'page_size' => $pageSize]);
    }
}
