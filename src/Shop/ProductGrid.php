<?php

declare(strict_types=1);

namespace Thallo\Commerce\Shop;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Cache\CacheStore;
use Glueful\Extensions\Commerce\Catalog\CategoryRepository;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\ResolvedProductFilters;
use Glueful\Extensions\Commerce\Catalog\TagRepository;
use Glueful\Extensions\Commerce\Tenancy\CommerceTenantResolution;
use Psr\Container\ContainerInterface;
use Thallo\Commerce\Http\Shop\ShopProductCardAssembler;
use Thallo\Contracts\Delivery\ProductGridView;
use Thallo\Contracts\Delivery\StorefrontProductGrid;

/**
 * The Product grid's products (product grid spec §2, §3): the source narrowed by categories and
 * tags, ordered and cut, as grid cards. Reads the catalog generation BEFORE the products, so the
 * render's guard is never newer than what it shows.
 *
 * The engine's services are resolved on first use, not at construction: the render pack builds its
 * Twig extension, and with it this seam, on every render, when the commerce engine may be absent.
 * Without the engine there is no grid to show.
 */
final class ProductGrid implements StorefrontProductGrid
{
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly ApplicationContext $context,
    ) {
    }

    public function grid(array $data): ?ProductGridView
    {
        if (!$this->container->has(ProductRepository::class)) {
            return null;
        }
        $tenant = $this->container->get(CommerceTenantResolution::class)->tenantUuid($this->context);
        $guard = (new CatalogGeneration($this->container->get(CacheStore::class)))->read($tenant);
        $query = ProductGridQuery::fromData($data);
        $rows = $query->source === 'manual' ? $this->manual($tenant, $query) : $this->listed($tenant, $query);
        $cards = array_map(
            static fn ($card): array => $card->toArray(),
            $this->service(ShopProductCardAssembler::class)
                ->gridCards($tenant, $rows, $query->newBadgeDays, new \DateTimeImmutable('now')),
        );
        return new ProductGridView(
            $cards,
            $this->viewAll($query),
            'thallo:shop:catalog:' . $tenant,
            CatalogGeneration::key($tenant),
            $guard,
        );
    }

    /** @return list<array<string,mixed>> */
    private function listed(string $tenant, ProductGridQuery $query): array
    {
        $categories = $this->service(CategoryRepository::class);
        $tags = $this->service(TagRepository::class);
        $categoryUuids = $this->resolve(
            $query->categories,
            fn (string $s): ?string => $categories->findUuidBySlug($this->context, $tenant, $s),
        );
        $tagUuids = $this->resolve(
            $query->tags,
            fn (string $s): ?string => $tags->findUuidBySlug($this->context, $tenant, $s),
        );
        // Chosen but all gone: nothing, never everything (§2.2).
        if (($query->categories !== [] && $categoryUuids === []) || ($query->tags !== [] && $tagUuids === [])) {
            return [];
        }
        $filters = new ResolvedProductFilters(
            $categoryUuids,
            $tagUuids,
            [],
            $query->source === 'on_sale',
            $query->excludeOutOfStock,
        );
        return $this->service(ProductRepository::class)
            ->listActive($this->context, $tenant, 1, $query->limit, $filters, $query->orderBy)['items'];
    }

    /** @return list<array<string,mixed>> the listed products, in order, at most `limit` */
    private function manual(string $tenant, ProductGridQuery $query): array
    {
        try {
            $slugs = ManualProductListNormalizer::normalize($query->products);
        } catch (\InvalidArgumentException) {
            return [];
        }
        $rows = [];
        foreach ($slugs as $slug) {
            $row = $this->service(ProductRepository::class)->findBuyerAvailableBySlug($this->context, $tenant, $slug);
            if ($row !== null && ($row['status'] ?? null) === 'active') {
                $rows[] = $row;
            }
        }
        if ($query->excludeOutOfStock && $rows !== []) {
            $inStock = array_column(
                $this->service(ProductRepository::class)
                    ->activeFilteredQuery($this->context, $tenant, new ResolvedProductFilters(inStock: true))
                    ->whereIn('uuid', array_map(static fn (array $r): string => (string) $r['uuid'], $rows))
                    ->select(['uuid'])->get(),
                'uuid',
            );
            $rows = array_values(array_filter(
                $rows,
                static fn (array $r): bool => in_array($r['uuid'], $inStock, true),
            ));
        }
        return array_slice($rows, 0, $query->limit);
    }

    /**
     * @param list<string> $slugs
     * @param \Closure(string): ?string $find
     * @return list<string>
     */
    private function resolve(array $slugs, \Closure $find): array
    {
        return array_values(array_filter(array_map($find, $slugs), static fn (?string $u): bool => $u !== null));
    }

    private function viewAll(ProductGridQuery $query): ?string
    {
        if ($query->source !== 'manual' && count($query->categories) === 1 && $query->tags === []) {
            return $this->service(ShopUrlGenerator::class)->category($query->categories[0]);
        }
        return $this->service(ShopUrlGenerator::class)->shopIndex();
    }

    /**
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    private function service(string $id): object
    {
        return $this->container->get($id);
    }
}
