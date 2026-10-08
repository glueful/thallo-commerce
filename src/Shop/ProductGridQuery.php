<?php

declare(strict_types=1);

namespace Thallo\Commerce\Shop;

use Glueful\Extensions\Commerce\Catalog\ProductSort;

/**
 * A Product grid's query, read from its block data (product grid spec §2): missing or invalid
 * values read as the defaults; nothing from the old block (category/tag/newest sources,
 * category_slug, tag_slug) is mapped (§2.4).
 */
final readonly class ProductGridQuery
{
    public const SOURCES = ['all', 'on_sale', 'manual'];
    public const MAX_SLUGS = 20;

    /**
     * @param list<string> $categories
     * @param list<string> $tags
     */
    public function __construct(
        public string $source,
        public array $categories,
        public array $tags,
        public string $products,
        public bool $excludeOutOfStock,
        public string $orderBy,
        public int $limit,
        public int $newBadgeDays,
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function fromData(array $data): self
    {
        $source = in_array($data['source'] ?? null, self::SOURCES, true) ? $data['source'] : 'all';
        $orderBy = in_array($data['order_by'] ?? null, ProductSort::ALL, true)
            ? $data['order_by']
            : ProductSort::NEWEST;
        return new self(
            $source,
            self::slugs($data['categories'] ?? null),
            self::slugs($data['tags'] ?? null),
            is_string($data['products'] ?? null) ? $data['products'] : '',
            ($data['exclude_out_of_stock'] ?? false) === true,
            $orderBy,
            self::int($data['limit'] ?? null, 1, 48, 12),
            self::int($data['new_badge_days'] ?? null, 1, 365, 7),
        );
    }

    /** @return list<string> */
    private static function slugs(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            return [];
        }
        $slugs = [];
        foreach ($value as $slug) {
            if (is_string($slug) && trim($slug) !== '') {
                $slugs[trim($slug)] = true;
            }
        }
        return array_slice(array_keys($slugs), 0, self::MAX_SLUGS);
    }

    private static function int(mixed $value, int $min, int $max, int $default): int
    {
        if (!is_int($value) && !(is_string($value) && ctype_digit($value)) && !is_float($value)) {
            return $default;
        }
        return max($min, min($max, (int) round((float) $value)));
    }
}
