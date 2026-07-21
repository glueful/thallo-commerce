<?php

declare(strict_types=1);

namespace Thallo\Commerce\Shop\ViewModels;

use Thallo\Commerce\Shop\ShopUrlGenerator;

/**
 * Closed storefront projection of a commerce category (storefront-rendering spec §6). Only
 * `slug`/`name` ever leave this surface — `uuid`, `tenant_uuid`, `parent_uuid`, `position`,
 * `revision`, `blob_uuid` stay internal.
 */
final class CategoryViewModel
{
    public function __construct(
        public readonly string $slug,
        public readonly string $name,
        public readonly string $url,
    ) {
    }

    /** @param array<string,mixed> $category a tenant-scoped commerce_categories row */
    public static function fromRow(array $category, ShopUrlGenerator $urls): self
    {
        $slug = (string) $category['slug'];

        return new self(
            slug: $slug,
            name: (string) $category['name'],
            url: $urls->category($slug),
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'url' => $this->url,
        ];
    }
}
