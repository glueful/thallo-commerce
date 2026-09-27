<?php

declare(strict_types=1);

namespace Thallo\Commerce\Shop;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Catalog\AddonRepository;
use Glueful\Extensions\Commerce\Catalog\CategoryRepository;
use Glueful\Extensions\Commerce\Catalog\ProductMediaRepository;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
use Glueful\Extensions\Commerce\Support\CommerceSettings;
use Thallo\Commerce\Links\ProductLinkService;
use Thallo\Commerce\Shop\ViewModels\AddToCartViewModel;
use Thallo\Commerce\Shop\ViewModels\ProductViewModel;
use Thallo\Contracts\Delivery\MediaUrlResolver;
use Thallo\Render\EntryBlocksRenderer;

/**
 * What a product's page renders from (type layouts plan C1): the variables `shop/product.twig` and
 * the product layout's frame both take — the closed view model with its server-built add-to-cart
 * decision, the gallery, the breadcrumb category, the linked story's rendered blocks, the canonical
 * URL — and `layout_context`, the same parts handed to the layout's field blocks. Built here once,
 * for the storefront's product route and for the product layout's stage.
 */
final class ShopProductPage
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly VariantRepository $variants,
        private readonly ProductMediaRepository $media,
        private readonly CategoryRepository $categories,
        private readonly AddonRepository $addons,
        private readonly ProductLinkService $links,
        private readonly ShopUrlGenerator $urls,
        private readonly EntryBlocksRenderer $blocksRenderer,
        // The ONE anonymous-media URL authority rendered pages already use (visibility-checked,
        // API-prefix-correct) — the app binds it; autowiring injects it. Nullable so the pack
        // never hard-requires an app-only binding: without it, pages honestly render imageless.
        private readonly ?MediaUrlResolver $mediaUrls = null,
    ) {
    }

    /**
     * @param array<string,mixed> $product a live, buyer-available, active commerce_products row
     * @return array{vars: array<string,mixed>, entry_uuid: ?string} the page's variables, and the
     *     linked story's entry when there is one (its cache tag)
     */
    public function forProduct(string $tenant, array $product): array
    {
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

        // Breadcrumb (storefront-v1 Task 6): the SAME deterministic first-category projection
        // the grid tags use (Task 1's batched read — the single-product call is the same
        // bounded query), or null when the product has no direct category assignment.
        $breadcrumbCategory = $this->categories->firstCategoryProjectionsForProducts(
            $this->context,
            $tenant,
            [$uuid],
        )[$uuid] ?? null;

        return [
            'vars' => $this->vars($vm, $breadcrumbCategory, $enrichment['html'] ?? null, (string) $product['slug']),
            'entry_uuid' => $enrichment['entry_uuid'] ?? null,
        ];
    }

    /**
     * The same variables for a product that exists only in memory — "Sample product", one variant
     * at 49.00 in the store currency, no images, reviews, category or story — for the product
     * layout's stage while the shop has no active product. Nothing is written.
     *
     * @return array<string,mixed>
     */
    public function placeholder(): array
    {
        $currency = CommerceSettings::currency($this->context);
        $product = [
            'uuid' => 'placeholder0', 'slug' => 'sample-product', 'name' => 'Sample product', 'type' => 'physical',
            'status' => 'active', 'description' => '<p>A short description of the product.</p>',
        ];
        $variants = [[
            'uuid' => 'placeholder0', 'status' => 'active', 'price' => 4900, 'currency' => $currency,
            'sku' => 'sample',
        ]];
        $addToCart = AddToCartViewModel::build($product, $variants, false, $this->urls, $currency);
        $vm = ProductViewModel::fromRow($product, $variants, null, $this->urls, $addToCart, []);
        return $this->vars($vm, null, null, 'sample-product');
    }

    /**
     * @param array<string,mixed>|null $breadcrumbCategory
     * @return array<string,mixed>
     */
    private function vars(
        ProductViewModel $product,
        ?array $breadcrumbCategory,
        ?\Twig\Markup $enrichmentHtml,
        string $slug,
    ): array {
        $shopIndex = $this->urls->shopIndex();
        return [
            'product' => $product,
            'breadcrumb_category' => $breadcrumbCategory,
            'enrichment_html' => $enrichmentHtml,
            'canonical' => $this->urls->product($slug),
            'shop_index' => $shopIndex,
            // What the product layout's field blocks read (block templates render with a fresh
            // context; the frame hands them this).
            'layout_context' => [
                'product' => $product,
                'breadcrumb_category' => $breadcrumbCategory,
                'enrichment_html' => $enrichmentHtml,
                'shop_index' => $shopIndex,
            ],
        ];
    }

    /** Resolved anonymous URL for a media row's blob, or null (private/missing/unbound). */
    private function mediaUrl(?array $row): ?string
    {
        if ($row === null || !isset($row['blob_uuid'])) {
            return null;
        }
        return $this->mediaUrls?->url((string) $row['blob_uuid']);
    }

    /**
     * The no-JS add-to-cart decision for the product detail page (Commerce-Slice-2 Fix A) —
     * the SAME closed {@see AddToCartViewModel::build()} the add-to-cart BLOCK's own JSON
     * endpoint uses ({@see \Thallo\Commerce\Http\Shop\ShopBlockDataController::addToCart()}),
     * computed here instead of fetched over `/_shop/blocks/add-to-cart` so the page can render a
     * REAL, server-side `<form>` (or native `<select>`) that works with zero JavaScript — the
     * pinned PRG promise a JS-only shell would otherwise break.
     *
     * @param array<string,mixed> $product
     * @param list<array<string,mixed>> $variants ALL of the product's variants (not yet
     *     filtered to active — mirrors ShopBlockDataController::addToCart()'s own filter)
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
     * unpublished/non-public-type) — a route-less entry resolves here. Either way the product
     * page still renders — commerce data alone when `html` is null.
     *
     * @return array{entry_uuid: string, html: ?\Twig\Markup}|null
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
}
