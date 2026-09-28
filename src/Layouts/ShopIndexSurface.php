<?php

declare(strict_types=1);

namespace Thallo\Commerce\Layouts;

/**
 * The shop home as a layout surface (type layouts plan C2): one layout for every page of `/shop`.
 * Its one sample is the first page, while the shop lists a product; with none, the stage opens on
 * an empty page with one placeholder card.
 */
final class ShopIndexSurface extends ShopPageSurface
{
    public const KEY = 'shop_index';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(string $target): string
    {
        return 'Products — shop home';
    }

    public function reach(string $target): string
    {
        return 'Applies to every page of the shop home';
    }

    public function samples(string $target, ?string $query): array
    {
        return $this->sampleContext($target, '1') === null ? [] : [['id' => '1', 'label' => 'Page 1']];
    }

    /** The home's first page; null while the shop lists nothing (the stage shows the placeholder). */
    public function sampleContext(string $target, string $sample): ?array
    {
        $vars = $this->page->forIndex($this->tenant(), 1);
        return $vars['layout_context']['products'] === [] ? null : $vars;
    }

    public function placeholder(string $target): array
    {
        return $this->page->placeholderIndex($this->tenant());
    }

    public function frame(): string
    {
        return 'layouts/shop_index.twig';
    }
}
