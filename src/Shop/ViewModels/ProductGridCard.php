<?php

declare(strict_types=1);

namespace Thallo\Commerce\Shop\ViewModels;

/**
 * A Product grid's card (product grid spec §5, §6): the closed shop card plus what only the grid
 * shows — every category, the tags, and whether it is new within the grid's window. The closed
 * card's own keys (stock and sale among them) stay pinned; these sit beside them.
 */
final readonly class ProductGridCard
{
    /**
     * @param list<array{name:string,slug:string}> $categories
     * @param list<array{name:string,slug:string}> $tags
     */
    public function __construct(
        public ProductCardViewModel $card,
        public array $categories,
        public array $tags,
        public bool $isNew,
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->card->toCardItem() + [
            'categories' => $this->categories,
            'tags' => $this->tags,
            'isNew' => $this->isNew,
        ];
    }
}
