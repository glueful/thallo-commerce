<?php

declare(strict_types=1);

namespace Thallo\Commerce\Search;

use Glueful\Extensions\Commerce\Events\StorefrontCatalogChanged;
use Thallo\Contracts\Search\SearchIndex;

/**
 * Every storefront-visible catalog write, after it commits, becomes a search change (search block
 * spec §3.5.2): a product's change journals that product; a category or tag change, which names
 * no single product, demands a rebuild of the kind, since their names are part of every product's
 * body. Stock, attribute and add-on changes with no product change no indexed word and are
 * ignored — stock changes fire on every checkout. While search is off the index is a no-op, and
 * the reconcile on re-enable catches up.
 */
final class PushCatalogChangesToSearch
{
    /** The broad changes whose names are indexed in every product they cover. */
    private const TAXONOMY = [
        StorefrontCatalogChanged::REASON_CATEGORY_CHANGED,
        StorefrontCatalogChanged::REASON_TAG_CHANGED,
    ];

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
            if (in_array($event->reason, self::TAXONOMY, true)) {
                $index->kindChanged(ProductsSearchContributor::KIND, 'taxonomy');
            }
            return;
        }
        $index->changed(ProductsSearchContributor::KIND, $event->productUuid);
    }
}
