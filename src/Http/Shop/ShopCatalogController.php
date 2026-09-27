<?php

declare(strict_types=1);

namespace Thallo\Commerce\Http\Shop;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Catalog\CategoryRepository;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\ResolvedProductFilters;
use Glueful\Extensions\Commerce\Tenancy\CommerceTenantResolution;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Thallo\Commerce\Layouts\ProductSurface;
use Thallo\Commerce\Shop\PackSlugLifecycleAuthority;
use Thallo\Commerce\Shop\ShopProductPage;
use Thallo\Commerce\Shop\ShopUrlGenerator;
use Thallo\Commerce\Shop\ViewModels\CategoryViewModel;
use Thallo\Commerce\Shop\ViewModels\GridViewModel;
use Thallo\Contracts\Layouts\LayoutReader;

/**
 * The read-only storefront catalog surface (storefront-rendering spec §3/§6): shop index,
 * product detail, category archive. Every response is themed HTML built from CLOSED view
 * models — never a raw commerce row — and every markup URL comes from
 * {@see ShopUrlGenerator}. Reuses the exact rendering mechanism entry pages use — via the
 * shared {@see ShopPageRenderer}: the SAME {@see \Thallo\Render\TwigFactory}-built
 * `Environment` (carrying the render pack's `RenderContextExtension`, so `blocks()`,
 * `asset()`, `menu()`, etc. all work identically inside shop templates).
 *
 * The product template's enrichment region (Commerce-Slice-2 Fix B) is rendered via
 * {@see \Thallo\Render\EntryBlocksRenderer::renderPublishedBlocks()}, through {@see ShopProductPage}
 * — a route-INDEPENDENT read, unlike
 * {@see \Thallo\Contracts\Delivery\PublicRouteResolver::resolveEntry()} (which this
 * controller no longer calls for enrichment: that method requires a live `entry_routes` row
 * and returns `not_found` for the route-less "Product story" starter type, silently dropping
 * the enrichment). Nothing here reaches into `RenderController`'s private render pipeline.
 */
final class ShopCatalogController
{
    private const PER_PAGE = 24;

    public function __construct(
        private readonly ApplicationContext $context,
        private readonly CommerceTenantResolution $tenants,
        private readonly ProductRepository $products,
        private readonly CategoryRepository $categories,
        private readonly PackSlugLifecycleAuthority $slugs,
        private readonly ShopUrlGenerator $urls,
        // The shared shop-page render seam (storefront-v1 Task 7) — this controller's old
        // private render() extracted verbatim so the wishlist page renders identically.
        private readonly ShopPageRenderer $pages,
        // The shared batched card pipeline (extracted buildGrid() body) — also consumed by
        // ShopWishlistController so grid and wishlist cards can never drift.
        private readonly ShopProductCardAssembler $cards,
        // What a product's page renders from, shared with the product layout's stage (type
        // layouts plan C1).
        private readonly ShopProductPage $productPage,
        // The saved layouts (type layouts plan C1): with a product layout, every product renders
        // through its frame. Nullable, as a pack never hard-requires an engine binding.
        private readonly ?LayoutReader $layouts = null,
    ) {
    }

    public function index(Request $request): Response
    {
        $tenant = $this->tenants->tenantUuid($this->context);
        $page = $this->requestedPage($request);
        $result = $this->products->listActive($this->context, $tenant, $page, self::PER_PAGE, null);
        $grid = $this->buildGrid($tenant, $result, $page, fn (int $p): string => $this->indexPagePath($p));

        return $this->render($request, 'shop/index.twig', [
            'grid' => $grid,
            'categories' => $this->categoryRail($tenant),
            'shop_index' => $this->urls->shopIndex(),
            'canonical' => $this->urls->shopIndex(),
        ]);
    }

    public function category(Request $request, string $slug): Response
    {
        $tenant = $this->tenants->tenantUuid($this->context);
        $category = $this->categories->findBySlug($this->context, $tenant, $slug);
        if ($category === null) {
            return $this->notFound($request);
        }

        $filters = new ResolvedProductFilters((string) $category['uuid']);
        $page = $this->requestedPage($request);
        $result = $this->products->listActive($this->context, $tenant, $page, self::PER_PAGE, $filters);
        $grid = $this->buildGrid($tenant, $result, $page, fn (int $p): string => $this->categoryPagePath($slug, $p));

        return $this->render($request, 'shop/category.twig', [
            'category' => CategoryViewModel::fromRow($category, $this->urls),
            'grid' => $grid,
            'categories' => $this->categoryRail($tenant, $slug),
            'shop_index' => $this->urls->shopIndex(),
            'canonical' => $this->urls->category($slug),
        ]);
    }

    public function product(Request $request, string $slug): Response
    {
        $tenant = $this->tenants->tenantUuid($this->context);
        // Buyer-context read (tenant-scoped, tombstone/status-excluded) — the SAME predicate
        // Commerce's own storefront JSON API uses (Slice-1 T7 review flag: a cross-tenant slug
        // lookup simply returns null here, a non-revealing 404 below). Current-slug FIRST, by
        // construction: a live product always wins outright — the ledger below is never even
        // consulted for a slug that resolves here (storefront-rendering spec §4's "live-wins"
        // loop safety falls straight out of this ordering, no extra guard needed).
        $product = $this->products->findBuyerAvailableBySlug($this->context, $tenant, $slug);
        if ($product === null || ($product['status'] ?? null) !== 'active') {
            $redirect = $this->resolveSlugRedirect($tenant, $slug);
            if ($redirect !== null) {
                return new RedirectResponse($redirect, 301);
            }
            return $this->notFound($request);
        }

        $page = $this->productPage->forProduct($tenant, $product);
        $layout = $this->layouts?->for(ProductSurface::KEY, ProductSurface::TARGET);
        $response = $layout === null
            ? $this->render($request, 'shop/product.twig', $page['vars'])
            : $this->pages->render(
                $request,
                'layouts/product.twig',
                $page['vars'] + [
                    'layout' => $layout + ['surface' => ProductSurface::KEY, 'target' => ProductSurface::TARGET],
                ],
                200,
                $layout['settings'],
            );
        // Every product page carries the product-layout tag, with a layout or without (spec §7.4): a
        // first save purges pages cached from the theme's template, a removal the pages the layout
        // rendered. It names no workspace — ShopPageCache stores the workspace's own in its place.
        $tags = [ProductSurface::PAGE_TAG];
        if ($page['entry_uuid'] !== null) {
            // Commerce-Slice-2 Fix B (storefront-rendering spec §9 extension): tag the
            // cached product-detail response with the linked entry's uuid — the SAME
            // `thallo:entry:{uuid}` string InvalidateCacheTagsListener already invalidates on
            // publish/update/delete (zero new purge code; see ShopPageCache, which folds this
            // tag into its own tag set exactly like RenderPageCache folds the render
            // controller's Cache-Tag header). Tagged even when the entry isn't CURRENTLY
            // publishable — a draft-linked entry that later publishes must still purge this
            // already-cached commerce-only page.
            $tags[] = 'thallo:entry:' . $page['entry_uuid'];
        }
        $response->headers->set('Cache-Tag', implode(',', $tags));
        return $response;
    }

    /**
     * Old-slug 301 (storefront-rendering spec §4): only reached once the current-slug lookup
     * above has already missed, so this can never fire for a slug a live product actually owns
     * right now. Ledger miss, a tombstoned/cross-tenant reservation target, or a target that is
     * no longer buyer-available/active all fall through to the SAME non-revealing 404 the
     * caller already produces for an unknown slug — a stale/broken reservation must never leak
     * existence information.
     */
    private function resolveSlugRedirect(string $tenant, string $slug): ?string
    {
        $productUuid = $this->slugs->findReservation($tenant, $slug);
        if ($productUuid === null) {
            return null;
        }

        $current = $this->products->findBuyerAvailableByUuid($this->context, $tenant, $productUuid);
        if ($current === null || ($current['status'] ?? null) !== 'active') {
            return null;
        }

        $currentSlug = (string) $current['slug'];
        if ($currentSlug === $slug) {
            // Defensive only: the current-slug lookup above already missed for $slug, so this
            // can't happen in practice — never redirect a slug to itself.
            return null;
        }

        return $this->urls->product($currentSlug);
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

    private function requestedPage(Request $request): int
    {
        $page = (int) $request->query->get('page', 1);

        return $page < 1 ? 1 : $page;
    }

    /**
     * Delegates to the shared {@see ShopPageRenderer} — this method's original body (the
     * reset-before-render discipline + context assembly), extracted verbatim in Task 7 so
     * the wishlist page renders through the identical mechanism.
     *
     * @param array<string,mixed> $extra
     */
    private function render(Request $request, string $template, array $extra, int $status = 200): Response
    {
        return $this->pages->render($request, $template, $extra, $status);
    }

    /** Non-revealing 404 (storefront-rendering spec §3): the SAME themed body every time. */
    private function notFound(Request $request): Response
    {
        return $this->render($request, '404.twig', [], 404);
    }
}
