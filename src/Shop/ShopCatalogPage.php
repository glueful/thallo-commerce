<?php

declare(strict_types=1);

namespace Thallo\Commerce\Shop;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Catalog\CategoryRepository;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\ResolvedProductFilters;
use Thallo\Commerce\Http\Shop\ShopProductCardAssembler;
use Thallo\Commerce\Shop\ViewModels\CategoryViewModel;
use Thallo\Commerce\Shop\ViewModels\GridViewModel;
use Thallo\Commerce\Shop\ViewModels\ProductCardViewModel;

/**
 * What the shop home and a category page render from (type layouts plan C2): the grid of cards, the
 * category rail, the canonical URL — built once here, for the catalog controller and for the shop
 * layouts' stage, so the stage shows what the site serves (as {@see ShopProductPage} does for the
 * product page). `layout_context` is what the shop layouts' blocks read: the frame hands it to them.
 */
final class ShopCatalogPage
{
    public const PER_PAGE = 24;

    public function __construct(
        private readonly ApplicationContext $context,
        private readonly ProductRepository $products,
        private readonly CategoryRepository $categories,
        private readonly ShopUrlGenerator $urls,
        private readonly ShopProductCardAssembler $cards,
        private readonly ShopProductPage $productPage,
    ) {
    }

    /**
     * The shop home's page `$page`.
     *
     * @return array<string,mixed>
     */
    public function forIndex(string $tenant, int $page): array
    {
        $result = $this->products->listActive($this->context, $tenant, $page, self::PER_PAGE, null);
        $grid = $this->buildGrid($tenant, $result, $page, fn (int $p): string => $this->indexPagePath($p));
        return $this->vars($grid, $this->categoryRail($tenant), null, $this->urls->shopIndex());
    }

    /**
     * A category's page `$page` (the caller has found the category).
     *
     * @param array<string,mixed> $category the category row
     * @return array<string,mixed>
     */
    public function forCategory(string $tenant, array $category, int $page): array
    {
        $slug = (string) $category['slug'];
        $filters = new ResolvedProductFilters([(string) $category['uuid']]);
        $result = $this->products->listActive($this->context, $tenant, $page, self::PER_PAGE, $filters);
        $grid = $this->buildGrid($tenant, $result, $page, fn (int $p): string => $this->categoryPagePath($slug, $p));
        return $this->vars(
            $grid,
            $this->categoryRail($tenant, $slug),
            CategoryViewModel::fromRow($category, $this->urls),
            $this->urls->category($slug),
        );
    }

    /**
     * The shop home for the layout stage while the shop lists nothing: no products, one page, and the
     * one placeholder card. The rail is the shop's own (a read). Nothing is written.
     *
     * @return array<string,mixed>
     */
    public function placeholderIndex(string $tenant): array
    {
        return $this->placeholder($this->categoryRail($tenant), null, $this->urls->shopIndex());
    }

    /**
     * A category page for the layout stage while no category lists a product: "Sample category",
     * no products, one page, the one placeholder card, no chip active. Nothing is written.
     *
     * @return array<string,mixed>
     */
    public function placeholderCategory(string $tenant): array
    {
        $category = CategoryViewModel::fromRow(['slug' => 'sample-category', 'name' => 'Sample category'], $this->urls);
        return $this->placeholder($this->categoryRail($tenant), $category, $category->url);
    }

    /**
     * @param list<array{name: string, url: string, active: bool}> $rail
     * @return array<string,mixed>
     */
    private function placeholder(array $rail, ?CategoryViewModel $category, string $canonical): array
    {
        $grid = new GridViewModel(
            items: [],
            page: 1,
            perPage: self::PER_PAGE,
            total: 0,
            totalPages: 1,
            prevPath: null,
            nextPath: null,
        );
        $vars = $this->vars($grid, $rail, $category, $canonical);
        // The product page's in-memory sample, as a card: one sample serves both stages.
        $product = $this->productPage->placeholder()['product'];
        $vars['layout_context']['placeholder_item'] = ProductCardViewModel::fromProduct(
            $product,
            null,
            $product->addToCart,
        )->toCardItem();
        return $vars;
    }

    /**
     * @param list<array{name: string, url: string, active: bool}> $rail
     * @return array<string,mixed>
     */
    private function vars(GridViewModel $grid, array $rail, ?CategoryViewModel $category, string $canonical): array
    {
        $shopIndex = $this->urls->shopIndex();
        $vars = [
            'grid' => $grid,
            'categories' => $rail,
            'shop_index' => $shopIndex,
            'canonical' => $canonical,
            // What the shop layouts' blocks read (block templates render with a fresh context; the
            // frame hands them this). The cards are arrays: loop_cards() repeats arrays.
            'layout_context' => [
                'products' => array_map(
                    static fn (ProductCardViewModel $card): array => $card->toCardItem(),
                    $grid->items,
                ),
                'total' => $grid->total,
                'pagination' => [
                    'page' => $grid->page,
                    'total_pages' => $grid->totalPages,
                    'prev_path' => $grid->prevPath,
                    'next_path' => $grid->nextPath,
                ],
                'categories' => $rail,
                'shop_index' => $shopIndex,
                'category' => $category === null
                    ? null
                    : ['name' => $category->name, 'slug' => $category->slug, 'url' => $category->url],
            ],
        ];
        if ($category !== null) {
            $vars['category'] = $category;
        }
        return $vars;
    }

    /**
     * The batched card pipeline (storefront-v1 Task 5): delegated to the shared
     * {@see ShopProductCardAssembler} (extracted from this method's original body, Task 7) —
     * ONE call per concern for the whole page, per-row reduction to the closed card view
     * model. The query budget is constant in product count, and ShopCatalogTest's
     * counting-statement guard fails if a per-card loop returns.
     *
     * @param array{items: list<array<string,mixed>>, total: int} $result
     * @param callable(int): string $pathFor
     */
    private function buildGrid(string $tenant, array $result, int $page, callable $pathFor): GridViewModel
    {
        $items = $this->cards->cards($tenant, $result['items']);

        $total = $result['total'];
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));

        return new GridViewModel(
            items: $items,
            page: $page,
            perPage: self::PER_PAGE,
            total: $total,
            totalPages: $totalPages,
            prevPath: $page > 1 ? $pathFor($page - 1) : null,
            nextPath: $page < $totalPages ? $pathFor($page + 1) : null,
        );
    }

    /**
     * The chip rail (storefront-v1 spec §2): every category for the tenant as a closed
     * `{name, url, active}` projection — never a raw row. Empty → templates skip the rail
     * entirely. `$activeSlug` marks the category page's own chip; the index passes none
     * (its "All" chip is the template's own active state).
     *
     * @return list<array{name: string, url: string, active: bool}>
     */
    private function categoryRail(string $tenant, ?string $activeSlug = null): array
    {
        return array_map(
            fn (array $row): array => [
                'name' => (string) $row['name'],
                'url' => $this->urls->category((string) $row['slug']),
                'active' => $activeSlug !== null && (string) $row['slug'] === $activeSlug,
            ],
            $this->categories->all($this->context, $tenant),
        );
    }

    private function indexPagePath(int $page): string
    {
        $base = $this->urls->shopIndex();

        return $page <= 1 ? $base : $base . '?page=' . $page;
    }

    private function categoryPagePath(string $slug, int $page): string
    {
        $base = $this->urls->category($slug);

        return $page <= 1 ? $base : $base . '?page=' . $page;
    }
}
