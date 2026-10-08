<?php

declare(strict_types=1);

namespace Thallo\Commerce\Http\Shop;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Catalog\AddonRepository;
use Glueful\Extensions\Commerce\Catalog\CategoryRepository;
use Glueful\Extensions\Commerce\Catalog\ProductMediaRepository;
use Glueful\Extensions\Commerce\Catalog\TagRepository;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
use Glueful\Extensions\Commerce\Support\CommerceSettings;
use Thallo\Commerce\Shop\ShopUrlGenerator;
use Thallo\Commerce\Shop\ViewModels\AddToCartViewModel;
use Thallo\Commerce\Shop\ViewModels\ProductCardViewModel;
use Thallo\Commerce\Shop\ViewModels\ProductGridCard;
use Thallo\Commerce\Shop\ViewModels\ProductViewModel;
use Thallo\Contracts\Delivery\MediaUrlBatchResolver;
use Thallo\Contracts\Delivery\MediaUrlResolver;

/**
 * The ONE batched product-card pipeline (storefront-v1 Task 5, shared by Task 7) — extracted
 * from {@see ShopCatalogController}'s `buildGrid()` so the wishlist resolution endpoint reuses
 * the EXACT reads/reductions instead of duplicating them: ONE call per concern for the whole
 * list — variants, required-add-on presence, first-category projections, primary media, then
 * one batched media-URL resolution — and per row the existing
 * {@see AddToCartViewModel::build()} decision reduces to the closed
 * {@see ProductCardViewModel}. The query budget stays constant in product count
 * (ShopCatalogTest's counting-statement guard fails if a per-card loop returns).
 */
final class ShopProductCardAssembler
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly VariantRepository $variants,
        private readonly ProductMediaRepository $media,
        private readonly CategoryRepository $categories,
        private readonly TagRepository $tags,
        private readonly AddonRepository $addons,
        private readonly ShopUrlGenerator $urls,
        // The ONE anonymous-media URL authority rendered pages already use (visibility-checked,
        // API-prefix-correct) — the app binds it; autowiring injects it. Nullable so the pack
        // never hard-requires an app-only binding: without it, cards honestly render imageless.
        private readonly ?MediaUrlResolver $mediaUrls = null,
        // Batched companion (storefront-v1 spec §2.2): ONE blobs query for a whole card list.
        // Soft-consumed exactly like $mediaUrls above — when unbound, cards() falls back to
        // the per-row resolver; the pack never hard-requires the app-only binding.
        private readonly ?MediaUrlBatchResolver $mediaUrlBatches = null,
    ) {
    }

    /**
     * One closed card per product row, INPUT order preserved.
     *
     * @param list<array<string,mixed>> $products decoded product rows (already buyer-available)
     * @return list<ProductCardViewModel>
     */
    public function cards(string $tenant, array $products): array
    {
        $productUuids = array_map(static fn (array $p): string => (string) $p['uuid'], $products);
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
        $stocked = $this->stockedVariants($tenant, $variantsByProduct);

        $items = [];
        foreach ($products as $product) {
            $uuid = (string) $product['uuid'];
            $variants = $variantsByProduct[$uuid] ?? [];
            $activeVariants = array_values(array_filter(
                $variants,
                static fn (array $variant): bool => ($variant['status'] ?? null) === 'active',
            ));
            // In stock: any active variant sellable now. On sale: any active variant priced below its
            // compare-at price — the engine's rules, the same for the grid and the shop pages.
            $inStock = false;
            $onSale = false;
            foreach ($activeVariants as $variant) {
                $inStock = $inStock || isset($stocked[(string) $variant['uuid']]);
                $compareAt = $variant['compare_at_price'] ?? null;
                $onSale = $onSale || ($compareAt !== null && (int) $compareAt > (int) $variant['price']);
            }
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
                $inStock,
                $onSale,
                is_string($product['created_at'] ?? null) ? $product['created_at'] : null,
            );
        }

        return $items;
    }

    /**
     * The Product grid's cards (product grid spec §5, §6): {@see self::cards()} (stock and sale
     * included) plus every category and tag (one batched read each) and new (created within
     * `$newBadgeDays`).
     *
     * @param list<array<string,mixed>> $products
     * @return list<ProductGridCard>
     */
    public function gridCards(string $tenant, array $products, int $newBadgeDays, \DateTimeImmutable $now): array
    {
        $uuids = array_map(static fn (array $p): string => (string) $p['uuid'], $products);
        $cards = $this->cards($tenant, $products);
        $categories = $this->categories->categoryProjectionsForProducts($this->context, $tenant, $uuids);
        $tags = $this->tags->tagProjectionsForProducts($this->context, $tenant, $uuids);
        $newSince = $now->modify('-' . $newBadgeDays . ' days');

        $items = [];
        foreach ($products as $i => $product) {
            $uuid = (string) $product['uuid'];
            $created = is_string($product['created_at'] ?? null)
                ? new \DateTimeImmutable($product['created_at'])
                : null;
            $items[] = new ProductGridCard(
                $cards[$i],
                $categories[$uuid] ?? [],
                $tags[$uuid] ?? [],
                $created !== null && $created >= $newSince,
            );
        }
        return $items;
    }

    /**
     * The variants that can be sold now, by the engine's in-stock rule: no stock row, an untracked
     * one, or a tracked quantity above zero. One query for the whole page of cards.
     *
     * @param array<string, list<array<string,mixed>>> $variants variant rows per product uuid
     * @return array<string, true> variant uuid => true
     */
    private function stockedVariants(string $tenant, array $variants): array
    {
        $uuids = [];
        foreach ($variants as $rows) {
            foreach ($rows as $row) {
                $uuids[] = (string) $row['uuid'];
            }
        }
        if ($uuids === []) {
            return [];
        }
        $stocked = array_fill_keys($uuids, true);
        $rows = db($this->context)->table('commerce_stock')
            ->where('tenant_uuid', '=', $tenant)
            ->whereIn('variant_uuid', $uuids)
            ->select(['variant_uuid', 'tracked', 'quantity'])
            ->get();
        foreach ($rows as $row) {
            if ((bool) $row['tracked'] && (int) $row['quantity'] <= 0) {
                unset($stocked[(string) $row['variant_uuid']]);
            }
        }
        return $stocked;
    }

    /**
     * Resolved anonymous URLs for the list's primary media rows — ONE batched blobs query
     * through {@see MediaUrlBatchResolver} when the app binds it, else the per-row
     * {@see self::mediaUrl()} fallback (the pack never hard-requires the app-only binding).
     * Unservable blobs resolve to null either way — never a broken `<img>`.
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

    /** Resolved anonymous URL for a media row's blob, or null (private/missing/unbound). */
    private function mediaUrl(?array $row): ?string
    {
        if ($row === null || !isset($row['blob_uuid'])) {
            return null;
        }
        return $this->mediaUrls?->url((string) $row['blob_uuid']);
    }
}
