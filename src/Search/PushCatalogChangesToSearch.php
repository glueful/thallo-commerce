<?php

declare(strict_types=1);

namespace Thallo\Commerce\Search;

use Glueful\Extensions\Commerce\Events\StorefrontCatalogChanged;
use Thallo\Contracts\Search\SearchIndex;

/**
 * Every storefront-visible catalog write, after it commits, becomes a search change (search block
 * spec §3.5.2): a product's change journals that product; a change with no single product (a
 * category, tag or attribute definition) demands a rebuild of the kind. While search is off the
 * index is a no-op, and the reconcile on re-enable catches up.
 */
final class PushCatalogChangesToSearch
{
    /** @param \Closure(): SearchIndex $index resolved when an event arrives */
    public function __construct(private readonly \Closure $index)
    {
    }

    public function onCatalogChanged(object $event): void
    {
        if (!$event instanceof StorefrontCatalogChanged) {
            return;
        }
        $index = ($this->index)();
        if ($event->productUuid === null) {
            $index->kindChanged(ProductsSearchContributor::KIND, 'taxonomy');
            return;
        }
        $index->changed(ProductsSearchContributor::KIND, $event->productUuid);
    }
}
