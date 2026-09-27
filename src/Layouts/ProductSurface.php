<?php

declare(strict_types=1);

namespace Thallo\Commerce\Layouts;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Tenancy\CommerceTenantResolution;
use Thallo\Commerce\Shop\ShopProductPage;
use Thallo\Commerce\Starter\ProductFieldBlocksContributor;
use Thallo\Contracts\Layouts\LayoutSampleContext;
use Thallo\Contracts\Layouts\LayoutSurface;

/**
 * The shop's product page as a layout surface (type layouts spec §3, plan C1): one layout for the
 * whole site (`@site`), designed on the stage around one of the shop's active products — or an
 * in-memory placeholder while there are none — from the product field blocks, with **Add to cart**
 * required once. Its frame, `layouts/product.twig`, keeps what `shop/product.twig` guarantees.
 *
 * Commerce registers it while its capability is on (and the commerce engine is bound). Its pages
 * live in the shop's page cache, which commerce purges on `LayoutChanged` (tenant by tenant), so it
 * declares no rendered-page tags.
 */
final class ProductSurface implements LayoutSurface, LayoutSampleContext
{
    public const KEY = 'product';
    public const TARGET = '@site';

    private const SAMPLES = 50;

    public function __construct(
        private readonly ApplicationContext $context,
        private readonly CommerceTenantResolution $tenants,
        private readonly ProductRepository $products,
        private readonly ShopProductPage $page,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(string $target): string
    {
        return 'Products — product page';
    }

    public function reach(string $target): string
    {
        return 'Applies to every product';
    }

    public function targets(): array
    {
        return [['target' => self::TARGET, 'label' => $this->label(self::TARGET), 'enabled' => true, 'reason' => null]];
    }

    /** The shop's active, buyer-available products, newest first — what the storefront lists. */
    public function samples(string $target, ?string $query): array
    {
        $tenant = $this->tenants->tenantUuid($this->context);
        $builder = $this->products->activeFilteredQuery($this->context, $tenant, null);
        if ($query !== null && trim($query) !== '') {
            $builder->whereRaw("name ILIKE ? ESCAPE '\\'", ['%' . addcslashes(trim($query), '%_\\') . '%']);
        }
        $rows = $builder->orderBy('created_at', 'DESC')->orderBy('uuid', 'ASC')->limit(self::SAMPLES)->get();
        return array_map(
            static fn (array $row): array => ['id' => (string) $row['uuid'], 'label' => (string) $row['name']],
            $rows,
        );
    }

    public function defaultSample(string $target): ?string
    {
        return $this->samples($target, null)[0]['id'] ?? null;
    }

    /** The product page's variables for an active product; null once it is archived, drafted or gone. */
    public function sampleContext(string $target, string $sample): ?array
    {
        $tenant = $this->tenants->tenantUuid($this->context);
        $product = $this->products->findBuyerAvailableByUuid($this->context, $tenant, $sample);
        if ($product === null || ($product['status'] ?? null) !== 'active') {
            return null;
        }
        return $this->page->forProduct($tenant, $product)['vars'];
    }

    public function placeholder(string $target): array
    {
        return $this->page->placeholder();
    }

    public function palette(): array
    {
        return ProductFieldBlocksContributor::SLUGS;
    }

    public function required(string $target): array
    {
        return [['type' => 'product_buy']];
    }

    public function bindable(string $target): array
    {
        return [];
    }

    public function frame(): string
    {
        return 'layouts/product.twig';
    }

    public function pageTags(string $target): array
    {
        return [];
    }

    /**
     * Today's page in blocks: the breadcrumb; the gallery beside the information column — one
     * column below `md` (768px, the page's 48rem), two equal columns from it, 2.5rem apart (the
     * page uses 1.05fr / 1fr and 2rem, which the layout vocabulary has no values for: accepted,
     * plan C1) — the information column's parts 0.5rem apart, as on the page; then the story.
     */
    public function starter(string $target): array
    {
        $token = static fn (string $value): array => ['base' => ['type' => 'token', 'value' => $value]];
        $block = static fn (string $type, array $data = []): array => [
            'type' => $type, 'data' => $data, 'settings' => [],
        ];
        $grid = ['base' => ['type' => 'choice', 'value' => 'grid']];
        return [
            $block('product_breadcrumb'),
            ['type' => 'container', 'data' => ['element' => 'div', 'content' => [
                ['type' => 'container', 'data' => ['element' => 'div', 'content' => [$block('product_gallery')]],
                    'settings' => []],
                ['type' => 'container', 'data' => ['element' => 'div', 'content' => [
                    $block('product_category'),
                    $block('product_name', ['level' => 'h1']),
                    $block('product_rating'),
                    $block('product_price'),
                    $block('product_description'),
                    $block('product_buy'),
                ]], 'settings' => ['style' => ['layout' => [
                    'display' => $grid,
                    'gap' => ['row' => $token('spacing.sm')],
                ]]]],
            ]], 'settings' => ['style' => ['layout' => [
                'display' => $grid,
                'columns' => [
                    'base' => ['type' => 'choice', 'value' => '1'],
                    'md' => ['type' => 'choice', 'value' => '2'],
                ],
                'gap' => ['column' => $token('spacing.xl'), 'row' => $token('spacing.xl')],
            ]]]],
            $block('product_story'),
        ];
    }
}
