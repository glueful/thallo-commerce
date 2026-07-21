<?php

declare(strict_types=1);

namespace Thallo\Commerce\Shop;

/**
 * Normalizes the `product-grid` block's `manual` source list (storefront-rendering spec §5.2/
 * §10 task-11 brief) — a `text` field the editor fills with one product slug per line. Pure,
 * dependency-free, and the SINGLE place this rule lives: {@see \Thallo\Commerce\Http\Shop\ShopBlockDataController}
 * is the only caller, resolving the manual grid source server-side.
 *
 * Rules (verbatim): trim each line, drop blank lines, deduplicate in STABLE first-occurrence
 * order, cap the result at {@see self::MAX} entries, and REJECT (throw) any line containing a
 * comma — a comma is very likely a mis-authored "comma-separated" list, and guessing at the
 * intended split would silently show the wrong products. The comma check runs over every
 * non-blank line BEFORE deduplication/capping, so a comma anywhere in the field always throws
 * the same way regardless of where in the list it appears.
 */
final class ManualProductListNormalizer
{
    public const MAX = 50;

    /**
     * @return list<string>
     * @throws \InvalidArgumentException a line contains a comma
     */
    public static function normalize(string $raw): array
    {
        $lines = preg_split('/\R/', $raw) ?: [];

        $trimmed = [];
        foreach ($lines as $line) {
            $slug = trim($line);
            if ($slug === '') {
                continue;
            }
            if (str_contains($slug, ',')) {
                throw new \InvalidArgumentException(
                    "product-grid manual list: comma-delimited input is not supported ('{$slug}') — "
                    . 'one product slug per line.'
                );
            }
            $trimmed[] = $slug;
        }

        $seen = [];
        $deduped = [];
        foreach ($trimmed as $slug) {
            if (isset($seen[$slug])) {
                continue;
            }
            $seen[$slug] = true;
            $deduped[] = $slug;
        }

        // Reject overflow rather than silently truncating (plan §T11): dropping
        // products 51+ without telling the editor is a surprising failure — a
        // block author who lists 60 products should get a clear error, not a
        // quietly-shortened grid.
        if (count($deduped) > self::MAX) {
            throw new \InvalidArgumentException(sprintf(
                'product-grid manual list: %d distinct products exceeds the maximum of %d.',
                count($deduped),
                self::MAX,
            ));
        }

        return $deduped;
    }
}
