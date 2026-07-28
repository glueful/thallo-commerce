<?php

declare(strict_types=1);

namespace Thallo\Commerce\Http\Shop;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Catalog\AddonRepository;
use Glueful\Extensions\Commerce\Catalog\CategoryRepository;
use Glueful\Extensions\Commerce\Catalog\ProductMediaRepository;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\ResolvedProductFilters;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
use Glueful\Extensions\Commerce\Support\CommerceSettings;
use Glueful\Extensions\Commerce\Tenancy\CommerceTenantResolution;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Thallo\Commerce\Links\ProductLinkService;
use Thallo\Commerce\Shop\PackSlugLifecycleAuthority;
use Thallo\Commerce\Shop\ShopUrlGenerator;
use Thallo\Contracts\Delivery\MediaUrlBatchResolver;
use Thallo\Contracts\Delivery\MediaUrlResolver;
use Thallo\Commerce\Shop\ViewModels\AddToCartViewModel;
use Thallo\Commerce\Shop\ViewModels\CategoryViewModel;
use Thallo\Commerce\Shop\ViewModels\GridViewModel;
use Thallo\Commerce\Shop\ViewModels\ProductCardViewModel;
use Thallo\Commerce\Shop\ViewModels\ProductViewModel;
use Thallo\Render\EntryBlocksRenderer;
use Thallo\Render\Http\Middleware\RenderPageCache;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\TwigFactory;

use function config;

/**
 * The read-only storefront catalog surface (storefront-rendering spec §3/§6): shop index,
 * product detail, category archive. Every response is themed HTML built from CLOSED view
 * models — never a raw commerce row — and every markup URL comes from
 * {@see ShopUrlGenerator}. Reuses the exact rendering mechanism entry pages use: the SAME
 * {@see TwigFactory}-built `Environment` (carrying the render pack's `RenderContextExtension`,
 * so `blocks()`, `asset()`, `menu()`, etc. all work identically inside shop templates).
 *
 * The product template's enrichment region (Commerce-Slice-2 Fix B) is rendered via
 * {@see EntryBlocksRenderer::renderPublishedBlocks()} — a route-INDEPENDENT read, unlike
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
        private readonly VariantRepository $variants,
        private readonly ProductMediaRepository $media,
        private readonly CategoryRepository $categories,
        private readonly AddonRepository $addons,
        private readonly ProductLinkService $links,
        private readonly PackSlugLifecycleAuthority $slugs,
        private readonly ShopUrlGenerator $urls,
        private readonly TwigFactory $twigFactory,
        private readonly RenderContextExtension $extension,
        private readonly EntryBlocksRenderer $blocksRenderer,
        // The ONE anonymous-media URL authority rendered pages already use (visibility-checked,
        // API-prefix-correct) — the app binds it; autowiring injects it. Nullable so the pack
        // never hard-requires an app-only binding: without it, pages honestly render imageless.
        private readonly ?MediaUrlResolver $mediaUrls = null,
        // Batched companion (storefront-v1 spec §2.2): ONE blobs query for a whole card list.
        // Soft-consumed exactly like $mediaUrls above — when unbound, buildGrid() falls back to
        // the per-row resolver; the pack never hard-requires the app-only binding.
        private readonly ?MediaUrlBatchResolver $mediaUrlBatches = null,
    ) {
    }

    /** Resolved anonymous URL for a media row's blob, or null (private/missing/unbound). */
    private function mediaUrl(?array $row): ?string
    {
        if ($row === null || !isset($row['blob_uuid'])) {
            return null;
        }
        return $this->mediaUrls?->url((string) $row['blob_uuid']);
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

        $uuid = (string) $product['uuid'];
        $variants = $this->variants->forProduct($this->context, $tenant, $uuid);

        // The full gallery (product-editor mock parity, 2026-07-24), cover-role rows first, then
        // position order. A cover row is OPTIONAL by design: the admin attaches images with role
        // 'gallery' by default, so the first gallery image leads when no explicit cover exists —
        // previously only a role='cover' row ever rendered, leaving admin-managed products
        // imageless in the store. URLs resolve through the anonymous-media authority; rows whose
        // blobs aren't publicly servable are skipped (never a broken <img>).
        $mediaRows = $this->media->forProduct($this->context, $tenant, $uuid);
        $coverRows = array_filter($mediaRows, static fn (array $r): bool => ($r['role'] ?? null) === 'cover');
        $galleryRows = array_filter($mediaRows, static fn (array $r): bool => ($r['role'] ?? null) !== 'cover');
        $gallery = [];
        foreach ([...$coverRows, ...$galleryRows] as $row) {
            $url = $this->mediaUrl($row);
            if ($url === null) {
                continue;
            }
            $gallery[] = [
                'url' => $url,
                'alt' => isset($row['alt']) && is_string($row['alt']) && $row['alt'] !== '' ? $row['alt'] : null,
            ];
        }

        $addToCart = $this->buildAddToCart($product, $variants, $uuid, $tenant);
        $vm = ProductViewModel::fromRow(
            $product,
            $variants,
            $gallery[0]['url'] ?? null,
            $this->urls,
            $addToCart,
            $gallery,
        );

        $enrichment = $this->resolveEnrichment($tenant, $uuid);

        $response = $this->render($request, 'shop/product.twig', [
            'product' => $vm,
            'enrichment_html' => $enrichment['html'] ?? null,
            'canonical' => $this->urls->product($slug),
            'shop_index' => $this->urls->shopIndex(),
        ]);
        if ($enrichment !== null) {
            // Commerce-Slice-2 Fix B (storefront-rendering spec §9 extension): tag the
            // cached product-detail response with the linked entry's uuid — the SAME
            // `thallo:entry:{uuid}` string InvalidateCacheTagsListener already invalidates on
            // publish/update/delete (zero new purge code; see ShopPageCache, which folds this
            // tag into its own tag set exactly like RenderPageCache folds the render
            // controller's Cache-Tag header). Tagged even when the entry isn't CURRENTLY
            // publishable — a draft-linked entry that later publishes must still purge this
            // already-cached commerce-only page.
            $response->headers->set('Cache-Tag', 'thallo:entry:' . $enrichment['entry_uuid']);
        }
        return $response;
    }

    /**
     * The no-JS add-to-cart decision for the product detail page (Commerce-Slice-2 Fix A) —
     * the SAME closed {@see AddToCartViewModel::build()} the add-to-cart BLOCK's own JSON
     * endpoint uses ({@see ShopBlockDataController::addToCart()}), computed here instead of
     * fetched over `/_shop/blocks/add-to-cart` so `shop/product.twig` can render a REAL,
     * server-side `<form>` (or native `<select>`) that works with zero JavaScript — the pinned
     * PRG promise a JS-only shell would otherwise break.
     *
     * @param array<string,mixed> $product
     * @param list<array<string,mixed>> $variants ALL of the product's variants (not yet
     *     filtered to active — mirrors {@see ShopBlockDataController::addToCart()}'s own filter)
     */
    private function buildAddToCart(array $product, array $variants, string $uuid, string $tenant): AddToCartViewModel
    {
        $activeVariants = array_values(array_filter(
            $variants,
            static fn (array $variant): bool => ($variant['status'] ?? null) === 'active',
        ));
        $hasRequiredAddons = array_reduce(
            $this->addons->activeForProduct($this->context, $tenant, $uuid),
            static fn (bool $carry, array $addon): bool => $carry || (bool) ($addon['required'] ?? false),
            false,
        );
        $currency = CommerceSettings::currency($this->context);

        return AddToCartViewModel::build($product, $activeVariants, $hasRequiredAddons, $this->urls, $currency);
    }

    /**
     * The linked entry's rendered blocks-region HTML (Commerce-Slice-2 Fix B), or null when
     * unlinked or the link itself fails closed (tombstoned product / missing entry —
     * {@see ProductLinkService::resolveByProduct}). `html` inside the returned array is null
     * when the link exists but the entry fails closed at
     * {@see EntryBlocksRenderer::renderPublishedBlocks()} (missing/deleted/cross-tenant/
     * unpublished/non-public-type) — a route-less entry now resolves here, unlike the
     * previous PublicRouteResolver::resolveEntry()-based lookup this replaces. Either way the
     * product page still renders — commerce data alone when `html` is null.
     *
     * @return array{entry_uuid: string, html: ?string}|null
     */
    private function resolveEnrichment(string $tenant, string $productUuid): ?array
    {
        $link = $this->links->resolveByProduct($this->context, $productUuid);
        if ($link === null) {
            return null;
        }

        $entryUuid = (string) $link['entry_uuid'];
        $html = $this->blocksRenderer->renderPublishedBlocks($this->context, $tenant, $entryUuid);

        return ['entry_uuid' => $entryUuid, 'html' => $html];
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
     * The batched card pipeline (storefront-v1 Task 5): ONE call per concern for the whole
     * page — variants, required-add-on presence, first-category projections, primary media
     * (which replaces the old `coversForProducts()` + per-missing-product `forProduct()`
     * fallback loop without changing visual selection semantics), then one batched media-URL
     * resolution. Per row, the existing {@see AddToCartViewModel::build()} decision reduces
     * to the closed {@see ProductCardViewModel} — the query budget is constant in product
     * count, and ShopCatalogTest's counting-statement guard fails if a per-card loop returns.
     *
     * @param array{items: list<array<string,mixed>>, total: int} $result
     * @param callable(int): string $pathFor
     */
    private function buildGrid(string $tenant, array $result, int $page, callable $pathFor): GridViewModel
    {
        $productUuids = array_map(static fn (array $p): string => (string) $p['uuid'], $result['items']);
        $variantsByProduct = $this->variants->forProducts($this->context, $tenant, $productUuids);
        $requiredAddons = $this->addons->hasRequiredForProducts($this->context, $tenant, $productUuids);
        $firstCategories = $this->categories->firstCategoryProjectionsForProducts(
            $this->context,
            $tenant,
            $productUuids,
        );
        $coverUrls = $this->resolveCoverUrls(
            $this->media->primaryForProducts($this->context, $tenant, $productUuids),
        );
        $currency = CommerceSettings::currency($this->context);

        $items = [];
        foreach ($result['items'] as $product) {
            $uuid = (string) $product['uuid'];
            $variants = $variantsByProduct[$uuid] ?? [];
            $activeVariants = array_values(array_filter(
                $variants,
                static fn (array $variant): bool => ($variant['status'] ?? null) === 'active',
            ));
            $addToCart = AddToCartViewModel::build(
                $product,
                $activeVariants,
                $requiredAddons[$uuid] ?? false,
                $this->urls,
                $currency,
            );
            $items[] = ProductCardViewModel::fromProduct(
                ProductViewModel::fromRow($product, $variants, $coverUrls[$uuid] ?? null, $this->urls),
                isset($firstCategories[$uuid]) ? $firstCategories[$uuid]['name'] : null,
                $addToCart,
            );
        }

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
     * Resolved anonymous URLs for the grid's primary media rows — ONE batched blobs query
     * through {@see MediaUrlBatchResolver} when the app binds it, else the existing per-row
     * {@see self::mediaUrl()} fallback (the pack never hard-requires the app-only binding;
     * the fallback runs on cache FILL only, this being a shop-cached page). Unservable blobs
     * resolve to null either way — never a broken `<img>`.
     *
     * @param array<string, array<string,mixed>> $primaryMedia primary media row per product uuid
     * @return array<string, ?string> resolved URL (or null) per product uuid
     */
    private function resolveCoverUrls(array $primaryMedia): array
    {
        if ($this->mediaUrlBatches === null) {
            return array_map(fn (array $row): ?string => $this->mediaUrl($row), $primaryMedia);
        }

        $blobUuids = [];
        foreach ($primaryMedia as $row) {
            if (isset($row['blob_uuid'])) {
                $blobUuids[] = (string) $row['blob_uuid'];
            }
        }
        $urlsByBlob = $blobUuids === [] ? [] : $this->mediaUrlBatches->urls($blobUuids);

        return array_map(
            static fn (array $row): ?string => isset($row['blob_uuid'])
                ? ($urlsByBlob[(string) $row['blob_uuid']] ?? null)
                : null,
            $primaryMedia,
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

    private function defaultLocale(): string
    {
        // Storefront-rendering spec §9: "Locale is the render pipeline's resolved locale
        // (default locale in v1 when no locale route is present)" — Render itself carries no
        // separate injectable locale-manager service; RenderController's own defaultLocale()
        // and RenderServiceProvider::makeRenderContextExtension() both read this exact config
        // key. Shop pages have no locale route segment in v1, so this IS that resolved locale.
        return (string) config($this->context, 'i18n.default_locale', 'en');
    }

    /**
     * Mirrors RenderController::render()'s reset-before-render discipline: `RenderContextExtension`
     * is a process-shared singleton (same instance the render pipeline uses), so every render
     * through it must reset render-scoped state first — never inherit a previous render's tags,
     * asset base, block depth, or theme-appearance override.
     *
     * @param array<string,mixed> $extra
     */
    private function render(Request $request, string $template, array $extra, int $status = 200): Response
    {
        $env = $this->twigFactory->environment();
        $locale = $this->defaultLocale();

        $this->extension->resetTags();
        $this->extension->resetPerRenderState();
        $this->extension->setAssetContext(null, null);
        $this->extension->setBlockAnnotations(false);
        $this->extension->setThemeAppearanceOverride(null, null);
        $this->extension->setLocale($locale);

        $context = [
            'site' => [
                'name' => (string) config($this->context, 'render.site_name', 'Thallo'),
                'locale' => $locale,
                'locales' => [],
            ],
            'current_path' => RenderPageCache::normalizePath($request->getPathInfo()),
            'presentation' => [
                'show_title' => true,
                'layout' => 'centered',
                'header' => 'default',
                'footer' => 'default',
            ],
        ] + $extra;

        $html = $env->render($template, $context);

        return new Response($html, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /** Non-revealing 404 (storefront-rendering spec §3): the SAME themed body every time. */
    private function notFound(Request $request): Response
    {
        return $this->render($request, '404.twig', [], 404);
    }
}
