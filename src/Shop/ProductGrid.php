<?php

declare(strict_types=1);

namespace Thallo\Commerce\Shop;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Cache\CacheStore;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\ProductSort;
use Glueful\Extensions\Commerce\Catalog\ResolvedProductFilters;
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
        $categoryUuids = $this->uuidsBySlug('commerce_categories', $tenant, $query->categories);
        $tagUuids = $this->uuidsBySlug('commerce_tags', $tenant, $query->tags);
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
        // The engine's list query and order, without listActive()'s total: a grid shows no count.
        $products = $this->service(ProductRepository::class)->activeFilteredQuery($this->context, $tenant, $filters);
        return ProductSort::apply($products, $query->orderBy)->limit($query->limit)->get();
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
     * The uuids of the workspace's categories or tags named by `$slugs`, in one query — as the
     * engine's findBySlug() resolves one: an exact slug within the tenant. Unknown slugs are left out.
     *
     * @param 'commerce_categories'|'commerce_tags' $table
     * @param list<string> $slugs
     * @return list<string>
     */
    private function uuidsBySlug(string $table, string $tenant, array $slugs): array
    {
        if ($slugs === []) {
            return [];
        }
        $rows = db($this->context)->table($table)
            ->where('tenant_uuid', '=', $tenant)
            ->whereIn('slug', $slugs)
            ->select(['uuid'])
            ->get();
        return array_values(array_map(static fn (array $r): string => (string) $r['uuid'], $rows));
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
