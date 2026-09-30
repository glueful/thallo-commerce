<?php

declare(strict_types=1);

namespace Thallo\Commerce\Shop;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Tenancy\CommerceTenantResolution;
use Psr\Container\ContainerInterface;
use Thallo\Commerce\Links\ProductLinkService;
use Thallo\Contracts\Delivery\StorefrontBlockPreview;

/**
 * Names a Featured product's or Add to cart's product on the stage, resolving it exactly as the
 * blocks' data endpoints do ({@see \Thallo\Commerce\Http\Shop\ShopBlockDataController}): an
 * explicit slug first, else the entry's linked product — a link row carries the product's uuid —
 * and only an active product a buyer could see. The stage is never cached, so a live lookup is safe;
 * the public page never calls this.
 *
 * The engine's services are resolved on first use, not at construction: the render pack builds its
 * Twig extension at boot, when the commerce engine may be absent.
 */
final class ShopBlockPreview implements StorefrontBlockPreview
{
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly ApplicationContext $context,
    ) {
    }

    public function productLabel(?string $slug, ?string $entryUuid): ?string
    {
        if (!$this->container->has(ProductRepository::class)) {
            return null;
        }
        $products = $this->container->get(ProductRepository::class);
        $tenant = $this->container->get(CommerceTenantResolution::class)->tenantUuid($this->context);

        if ($slug !== null && $slug !== '') {
            $product = $products->findBuyerAvailableBySlug($this->context, $tenant, $slug);
        } elseif ($entryUuid !== null && $entryUuid !== '') {
            $link = $this->container->get(ProductLinkService::class)->resolveByEntry($this->context, $entryUuid);
            $product = $link === null
                ? null
                : $products->findBuyerAvailableByUuid($this->context, $tenant, (string) $link['product_uuid']);
        } else {
            return null;
        }

        return $product !== null && ($product['status'] ?? null) === 'active' ? (string) $product['name'] : null;
    }
}
