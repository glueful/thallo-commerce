<?php

declare(strict_types=1);

namespace Thallo\Commerce\Http\Shop;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Catalog\AddonRepository;
use Glueful\Extensions\Commerce\Catalog\CategoryRepository;
use Glueful\Extensions\Commerce\Catalog\ProductMediaRepository;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\ResolvedProductFilters;
use Glueful\Extensions\Commerce\Catalog\TagRepository;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
use Glueful\Extensions\Commerce\Tenancy\CommerceTenantResolution;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Thallo\Commerce\Links\ProductLinkService;
use Thallo\Commerce\Shop\ManualProductListNormalizer;
use Thallo\Commerce\Shop\ShopUrlGenerator;
use Thallo\Commerce\Shop\ViewModels\AddToCartViewModel;
use Thallo\Commerce\Shop\ViewModels\ProductViewModel;

use function config;

/**
 * `GET /_shop/blocks/{product-grid,featured-product,add-to-cart}` (task 11): the JSON data
 * source the 3 catalog-data block templates hydrate from client-side. Every response is a
 * closed view model built through the SAME repositories/{@see ShopUrlGenerator} the full
 * catalog pages use ({@see ShopCatalogController}) — never a raw commerce row — so a block
 * placed on ANY page (a builder page, not just a shop route) shows live data without either
 * `RenderController` or the shared Twig `Environment` needing to know anything about commerce.
 *
 * Blocks render a stable, parameter-carrying HTML shell (see `templates/blocks/*.twig`); `shop.js`
 * reads that shell's `data-*` attributes, calls the matching action here, and paints the result —
 * every URL in the JSON (product links, "view all" links) is built by {@see ShopUrlGenerator}, so
 * the block's OWN markup never has to construct one. Read-only, `private, no-store` (catalog
 * pages have their own dimension-complete {@see \Thallo\Commerce\Shop\ShopPageCache}; these
 * per-block reads are deliberately uncached in v1 — correctness over an extra cache layer for a
 * cheap, indexed, capped read).
 */
final class ShopBlockDataController
{
    private const MAX_PAGE_SIZE = 48;

    public function __construct(
        private readonly ApplicationContext $context,
        private readonly CommerceTenantResolution $tenants,
        private readonly ProductRepository $products,
        private readonly VariantRepository $variants,
        private readonly ProductMediaRepository $media,
        private readonly CategoryRepository $categories,
        private readonly TagRepository $tags,
        private readonly AddonRepository $addons,
        private readonly ProductLinkService $links,
        private readonly ShopUrlGenerator $urls,
    ) {
    }

    /** `GET /_shop/blocks/product-grid` — page 1 only (spec §9: never query-paginated here). */
    public function productGrid(Request $request): Response
    {
        $tenant = $this->tenants->tenantUuid($this->context);
        $source = $this->enumOrDefault(
            (string) $request->query->get('source', 'newest'),
            ['category', 'tag', 'manual', 'newest'],
            'newest',
        );
        $pageSize = self::clampPageSize((int) $request->query->get('page_size', 24));
        $viewAllUrl = $this->urls->shopIndex();

        try {
            [$rows, $viewAllUrl] = match ($source) {
                'category' => $this->categoryRows($request, $tenant, $pageSize, $viewAllUrl),
                'tag' => [$this->tagRows($request, $tenant, $pageSize), $viewAllUrl],
                'manual' => [$this->manualRows($request, $tenant, $pageSize), $viewAllUrl],
                default => [
                    $this->products->listActive($this->context, $tenant, 1, $pageSize, null)['items'],
                    $viewAllUrl,
                ],
            };
        } catch (\InvalidArgumentException $e) {
            return $this->noStore(new JsonResponse(
                ['error' => $e->getMessage(), 'items' => [], 'view_all_url' => $viewAllUrl],
                422,
            ));
        }

        $items = $this->toViewModels($tenant, $rows);

        return $this->noStore(new JsonResponse([
            'items' => array_map(static fn (ProductViewModel $vm): array => $vm->toArray(), $items),
            'view_all_url' => $viewAllUrl,
        ]));
    }

    /** `GET /_shop/blocks/featured-product` — explicit slug, or the enriched entry's product. */
    public function featuredProduct(Request $request): Response
    {
        $tenant = $this->tenants->tenantUuid($this->context);
        $slug = $this->resolveSlug($request, $tenant);

        $product = $slug !== null ? $this->products->findBuyerAvailableBySlug($this->context, $tenant, $slug) : null;
        if ($product === null || ($product['status'] ?? null) !== 'active') {
            return $this->noStore(new JsonResponse(['product' => null]));
        }

        $uuid = (string) $product['uuid'];
        $variants = $this->variants->forProduct($this->context, $tenant, $uuid);
        $cover = $this->media->coverFor($this->context, $tenant, $uuid);
        $vm = ProductViewModel::fromRow($product, $variants, $cover, $this->urls);

        return $this->noStore(new JsonResponse(['product' => $vm->toArray()]));
    }

    /** `GET /_shop/blocks/add-to-cart` — closed decision: direct | select | link | unavailable. */
    public function addToCart(Request $request): Response
    {
        $tenant = $this->tenants->tenantUuid($this->context);
        $slug = $this->resolveSlug($request, $tenant);

        $product = $slug !== null ? $this->products->findBuyerAvailableBySlug($this->context, $tenant, $slug) : null;
        if ($product === null || ($product['status'] ?? null) !== 'active') {
            return $this->noStore(new JsonResponse(AddToCartViewModel::unavailable()->toArray()));
        }

        $uuid = (string) $product['uuid'];
        $activeVariants = array_values(array_filter(
            $this->variants->forProduct($this->context, $tenant, $uuid),
            static fn (array $variant): bool => ($variant['status'] ?? null) === 'active',
        ));
        $hasRequiredAddons = array_reduce(
            $this->addons->activeForProduct($this->context, $tenant, $uuid),
            static fn (bool $carry, array $addon): bool => $carry || (bool) ($addon['required'] ?? false),
            false,
        );
        $currency = (string) config($this->context, 'commerce.currency', 'USD');

        $vm = AddToCartViewModel::build($product, $activeVariants, $hasRequiredAddons, $this->urls, $currency);

        return $this->noStore(new JsonResponse($vm->toArray()));
    }

    // ------------------------------------------------------------------
    // product-grid source resolution
    // ------------------------------------------------------------------

    /** @return array{0: list<array<string,mixed>>, 1: string} rows + the resolved "view all" URL */
    private function categoryRows(Request $request, string $tenant, int $pageSize, string $fallbackUrl): array
    {
        $slug = trim((string) $request->query->get('category_slug', ''));
        if ($slug === '') {
            return [[], $fallbackUrl];
        }
        $category = $this->categories->findBySlug($this->context, $tenant, $slug);
        if ($category === null) {
            return [[], $fallbackUrl];
        }
        $filters = new ResolvedProductFilters((string) $category['uuid']);
        $rows = $this->products->listActive($this->context, $tenant, 1, $pageSize, $filters)['items'];

        return [$rows, $this->urls->category($slug)];
    }

    /** @return list<array<string,mixed>> */
    private function tagRows(Request $request, string $tenant, int $pageSize): array
    {
        $slug = trim((string) $request->query->get('tag_slug', ''));
        if ($slug === '') {
            return [];
        }
        $tag = $this->tags->findBySlug($this->context, $tenant, $slug);
        if ($tag === null) {
            return [];
        }
        $filters = new ResolvedProductFilters(null, (string) $tag['uuid']);

        return $this->products->listActive($this->context, $tenant, 1, $pageSize, $filters)['items'];
    }

    /**
     * @return list<array<string,mixed>>
     * @throws \InvalidArgumentException comma-delimited manual list input (task-11 brief)
     */
    private function manualRows(Request $request, string $tenant, int $pageSize): array
    {
        $raw = (string) $request->query->get('products', '');
        $slugs = ManualProductListNormalizer::normalize($raw);

        $items = [];
        foreach ($slugs as $slug) {
            if (count($items) >= $pageSize) {
                break;
            }
            $product = $this->products->findBuyerAvailableBySlug($this->context, $tenant, $slug);
            if ($product !== null && ($product['status'] ?? null) === 'active') {
                $items[] = $product;
            }
        }

        return $items;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<ProductViewModel>
     */
    private function toViewModels(string $tenant, array $rows): array
    {
        $productUuids = array_map(static fn (array $p): string => (string) $p['uuid'], $rows);
        $variantsByProduct = $this->variants->forProducts($this->context, $tenant, $productUuids);
        $covers = $this->media->coversForProducts($this->context, $tenant, $productUuids);

        return array_map(
            fn (array $product): ProductViewModel => ProductViewModel::fromRow(
                $product,
                $variantsByProduct[(string) $product['uuid']] ?? [],
                $covers[(string) $product['uuid']] ?? null,
                $this->urls,
            ),
            $rows,
        );
    }

    // ------------------------------------------------------------------
    // featured-product / add-to-cart: explicit slug, or the enriched entry's linked product
    // ------------------------------------------------------------------

    private function resolveSlug(Request $request, string $tenant): ?string
    {
        $slug = trim((string) $request->query->get('product_slug', ''));
        if ($slug !== '') {
            return $slug;
        }

        $entryUuid = trim((string) $request->query->get('entry_uuid', ''));
        if ($entryUuid === '') {
            return null;
        }

        $link = $this->links->resolveByEntry($this->context, $entryUuid);
        if ($link === null) {
            return null;
        }

        $product = $this->products->findBuyerAvailableByUuid($this->context, $tenant, (string) $link['product_uuid']);

        return $product !== null && ($product['status'] ?? null) === 'active' ? (string) $product['slug'] : null;
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    /** @param list<string> $allowed */
    private function enumOrDefault(string $value, array $allowed, string $default): string
    {
        return in_array($value, $allowed, true) ? $value : $default;
    }

    private static function clampPageSize(int $requested): int
    {
        return max(1, min(self::MAX_PAGE_SIZE, $requested));
    }

    private function noStore(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
