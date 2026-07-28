<?php

declare(strict_types=1);

namespace Thallo\Commerce\Http\Shop;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Tenancy\CommerceTenantResolution;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Thallo\Commerce\Shop\ShopUrlGenerator;
use Thallo\Commerce\Shop\ViewModels\ProductCardViewModel;

/**
 * The wishlist surface (storefront-v1 Task 7, spec §5): the JS-hydrated page shell at
 * `GET /{prefix}/wishlist` and the bounded resolution endpoint `GET /_shop/wishlist/items`.
 *
 * The PAGE is a server-rendered, shop-cached shell (it participates in
 * {@see \Thallo\Commerce\Shop\ShopPageCache} exactly as the catalog pages do — the markup is
 * static by design: title, status region, initially-hidden empty state and grid, `<noscript>`
 * honesty). It starts `aria-busy="true"` and never flashes a false empty state; shop.js
 * hydrates it from localStorage through the endpoint below.
 *
 * The ENDPOINT is strict at the boundary: a non-list `uuids` shape, more than
 * {@see self::MAX_UUIDS} RAW values, or ANY value failing {@see self::PRODUCT_UUID_PATTERN}
 * (the SAME pinned shape Commerce's own `UuidBatch` keeps — schema-pinned `string(12)`,
 * alphanumeric NanoID charset) is a 422 BEFORE any query runs; the repositories' own
 * normalize-and-drop semantics remain the defensive second layer. Valid duplicates dedupe by
 * FIRST occurrence; empty input answers without querying. Items are
 * {@see ProductCardViewModel} projections ONLY, in REQUEST order (unservable uuids omitted —
 * the response IS the client's reconciliation authority), assembled through the SAME batched
 * {@see ShopProductCardAssembler} pipeline the shop grids use — a bounded constant query
 * count, never 100 direct reads. Read-only, never page-cached, always `private, no-store`.
 */
final class ShopWishlistController
{
    private const MAX_UUIDS = 100;
    private const PRODUCT_UUID_PATTERN = '/\A[A-Za-z0-9]{12}\z/';

    public function __construct(
        private readonly ApplicationContext $context,
        private readonly CommerceTenantResolution $tenants,
        private readonly ProductRepository $products,
        private readonly ShopUrlGenerator $urls,
        private readonly ShopPageRenderer $pages,
        private readonly ShopProductCardAssembler $cards,
    ) {
    }

    /** `GET /{prefix}/wishlist` — the cacheable hydration shell. */
    public function page(Request $request): Response
    {
        return $this->pages->render($request, 'shop/wishlist.twig', [
            'shop_index' => $this->urls->shopIndex(),
            'canonical' => $this->urls->wishlist(),
        ]);
    }

    /** `GET /_shop/wishlist/items` — bounded, ordered wishlist resolution. */
    public function items(Request $request): Response
    {
        // Validation FIRST (spec §5): reject the whole request BEFORE any query runs.
        $raw = $request->query->all()['uuids'] ?? [];
        if (!is_array($raw) || !array_is_list($raw)) {
            return $this->unprocessable();
        }
        if (count($raw) > self::MAX_UUIDS) {
            return $this->unprocessable();
        }
        foreach ($raw as $value) {
            if (!is_string($value) || preg_match(self::PRODUCT_UUID_PATTERN, $value) !== 1) {
                return $this->unprocessable();
            }
        }

        /** @var list<string> $uuids first-occurrence dedupe, request order preserved */
        $uuids = array_values(array_unique($raw));
        if ($uuids === []) {
            return $this->noStore(new JsonResponse(['items' => []]));
        }

        $tenant = $this->tenants->tenantUuid($this->context);
        $available = $this->products->findActiveBuyerAvailableByUuids($this->context, $tenant, $uuids);

        // REQUEST order, absents omitted — the uuid-keyed map above is unordered by contract.
        $rows = [];
        foreach ($uuids as $uuid) {
            if (isset($available[$uuid])) {
                $rows[] = $available[$uuid];
            }
        }

        return $this->noStore(new JsonResponse([
            'items' => array_map(
                static fn (ProductCardViewModel $card): array => $card->toArray(),
                $this->cards->cards($tenant, $rows),
            ),
        ]));
    }

    private function unprocessable(): Response
    {
        return $this->noStore(new JsonResponse(
            [
                'error' => 'uuids[] must be a list of at most 100 product uuids.',
                'items' => [],
            ],
            422,
        ));
    }

    private function noStore(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
