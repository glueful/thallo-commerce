<?php

declare(strict_types=1);

namespace Thallo\Commerce\Shop\ViewModels;

/**
 * A paginated grid of {@see ProductViewModel} — the shop index and category archive share this
 * shape. `prevPath`/`nextPath` are pre-built full paths (never a bare page number), so templates
 * never construct a query string by hand — matching {@see \Thallo\Commerce\Shop\ShopUrlGenerator}
 * being the only URL source.
 */
final class GridViewModel
{
    /** @param list<ProductViewModel> $items */
    public function __construct(
        public readonly array $items,
        public readonly int $page,
        public readonly int $perPage,
        public readonly int $total,
        public readonly int $totalPages,
        public readonly ?string $prevPath,
        public readonly ?string $nextPath,
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'items' => array_map(static fn (ProductViewModel $item): array => $item->toArray(), $this->items),
            'page' => $this->page,
            'per_page' => $this->perPage,
            'total' => $this->total,
            'total_pages' => $this->totalPages,
            'prev_path' => $this->prevPath,
            'next_path' => $this->nextPath,
        ];
    }
}
