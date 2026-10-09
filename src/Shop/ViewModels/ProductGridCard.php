<?php

declare(strict_types=1);

namespace Thallo\Commerce\Shop\ViewModels;

/**
 * A Product grid's card (product grid spec §5, §6): the closed shop card — built with every
 * category and tag — plus what only the grid shows: whether it is new within the grid's window. The
 * closed card's own keys (stock and sale among them) stay pinned; this sits beside them.
 */
final readonly class ProductGridCard
{
    public function __construct(
        public ProductCardViewModel $card,
        public bool $isNew,
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->card->toCardItem() + ['isNew' => $this->isNew];
    }
}
