<?php

declare(strict_types=1);

namespace Thallo\Commerce\Layouts;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Catalog\CategoryRepository;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\ResolvedProductFilters;
use Glueful\Extensions\Commerce\Tenancy\CommerceTenantResolution;
use Thallo\Commerce\Shop\ShopCatalogPage;

/**
 * The shop's category pages as one layout surface (type layouts plan C2): one layout for every
 * category. Its samples are the categories that list a product, in the rail's order; a category
 * that is gone or lists nothing samples nothing, and the stage opens on "Sample category".
 */
final class ShopCategorySurface extends ShopPageSurface
{
    public const KEY = 'shop_category';

    private const SAMPLES = 50;

    public function __construct(
        ApplicationContext $context,
        CommerceTenantResolution $tenants,
        ShopCatalogPage $page,
        private readonly CategoryRepository $categories,
        private readonly ProductRepository $products,
    ) {
        parent::__construct($context, $tenants, $page);
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(string $target): string
    {
        return 'Products — shop categories';
    }

    public function reach(string $target): string
    {
        return 'Applies to every shop category';
    }

    public function samples(string $target, ?string $query): array
    {
        $tenant = $this->tenant();
        $needle = $query === null ? '' : mb_strtolower(trim($query));
        $samples = [];
        foreach ($this->categories->all($this->context, $tenant) as $row) {
            $name = (string) $row['name'];
            // A literal match: a `%` or `_` in the query is a character, never a wildcard.
            if ($needle !== '' && !str_contains(mb_strtolower($name), $needle)) {
                continue;
            }
            if (!$this->listsAProduct($tenant, (string) $row['uuid'])) {
                continue;
            }
            $samples[] = ['id' => (string) $row['uuid'], 'label' => $name];
            if (count($samples) === self::SAMPLES) {
                break;
            }
        }
        return $samples;
    }

    /** A category's first page; null once it is gone or lists nothing. */
    public function sampleContext(string $target, string $sample): ?array
    {
        $tenant = $this->tenant();
        $category = $this->categories->findByUuid($this->context, $tenant, $sample);
        if ($category === null) {
            return null;
        }
        $vars = $this->page->forCategory($tenant, $category, 1);
        return $vars['layout_context']['products'] === [] ? null : $vars;
    }

    public function placeholder(string $target): array
    {
        return $this->page->placeholderCategory($this->tenant());
    }

    public function frame(): string
    {
        return 'layouts/shop_category.twig';
    }

    private function listsAProduct(string $tenant, string $category): bool
    {
        return $this->products
            ->activeFilteredQuery($this->context, $tenant, new ResolvedProductFilters([$category]))
            ->limit(1)
            ->get() !== [];
    }
}
