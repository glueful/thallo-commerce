<?php

declare(strict_types=1);

namespace Thallo\Commerce\Http\Shop;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Catalog\AddonRepository;
use Glueful\Extensions\Commerce\Catalog\ProductMediaRepository;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
use Glueful\Extensions\Commerce\Support\CommerceSettings;
use Glueful\Extensions\Commerce\Tenancy\CommerceTenantResolution;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Thallo\Commerce\Links\ProductLinkService;
use Thallo\Commerce\Shop\ShopUrlGenerator;
use Thallo\Commerce\Shop\ViewModels\AddToCartViewModel;
use Thallo\Commerce\Shop\ViewModels\ProductCardViewModel;
use Thallo\Commerce\Shop\ViewModels\ProductViewModel;
use Thallo\Contracts\Delivery\MediaUrlResolver;

use function config;

/**
 * `GET /_shop/blocks/{featured-product,add-to-cart}` (task 11): the JSON data source the two
 * catalog-data block templates hydrate from client-side (the Product grid renders on the server,
 * product grid spec §3.1). Every response is a
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
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly CommerceTenantResolution $tenants,
        private readonly ProductRepository $products,
        private readonly VariantRepository $variants,
        private readonly ProductMediaRepository $media,
        private readonly AddonRepository $addons,
        private readonly ProductLinkService $links,
        private readonly ShopUrlGenerator $urls,
        // Same anonymous-media URL authority ShopCatalogController uses — see its ctor note.
        private readonly ?MediaUrlResolver $mediaUrls = null,
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

    /** Cover-role row first, first gallery row as fallback (mirrors ShopCatalogController). */
    private function coverUrlFor(string $tenant, string $productUuid, ?array $coverRow): ?string
    {
        if ($coverRow === null) {
            $rows = $this->media->forProduct($this->context, $tenant, $productUuid);
            $coverRow = $rows[0] ?? null;
        }
        return $this->mediaUrl($coverRow);
    }

    /** `GET /_shop/blocks/featured-product` — explicit slug, or the enriched entry's product. */
    public function featuredProduct(Request $request): Response
    {
        $tenant = $this->tenants->tenantUuid($this->context);
        $slug = $this->resolveSlug($request, $tenant);
        if ($slug === null) {
            // Nothing configured: no slug, and no entry with an active linked product. shop.js
            // hides the block (sections and templates design §6).
            return $this->noStore(new JsonResponse(['product' => null, 'unconfigured' => true]));
        }

        $product = $this->products->findBuyerAvailableBySlug($this->context, $tenant, $slug);
        if ($product === null || ($product['status'] ?? null) !== 'active') {
            return $this->noStore(new JsonResponse(['product' => null]));
        }

        $uuid = (string) $product['uuid'];
        $variants = $this->variants->forProduct($this->context, $tenant, $uuid);
        $cover = $this->media->coverFor($this->context, $tenant, $uuid);
        $vm = ProductViewModel::fromRow($product, $variants, $this->coverUrlFor($tenant, $uuid, $cover), $this->urls);

        return $this->noStore(new JsonResponse(['product' => $vm->toArray()]));
    }

    /** `GET /_shop/blocks/add-to-cart` — closed decision: direct | select | link | unavailable. */
    public function addToCart(Request $request): Response
    {
        $tenant = $this->tenants->tenantUuid($this->context);
        $slug = $this->resolveSlug($request, $tenant);
        if ($slug === null) {
            // Nothing configured: shop.js hides the block rather than call a product unavailable.
            return $this->noStore(new JsonResponse(
                AddToCartViewModel::unavailable()->toArray() + ['unconfigured' => true],
            ));
        }

        $product = $this->products->findBuyerAvailableBySlug($this->context, $tenant, $slug);
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
        $currency = CommerceSettings::currency($this->context);

        $vm = AddToCartViewModel::build($product, $activeVariants, $hasRequiredAddons, $this->urls, $currency);

        return $this->noStore(new JsonResponse($vm->toArray()));
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

    private function noStore(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
