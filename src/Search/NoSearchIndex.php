<?php

declare(strict_types=1);

namespace Thallo\Commerce\Search;

use Thallo\Contracts\Search\SearchIndex;

/** Stands in when the search pack is not installed: catalog changes go nowhere. */
final class NoSearchIndex implements SearchIndex
{
    public function changed(string $kind, string $sourceId): void
    {
    }

    public function kindChanged(string $kind, string $reason): void
    {
    }
}
