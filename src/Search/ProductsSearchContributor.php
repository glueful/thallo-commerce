<?php

declare(strict_types=1);

namespace Thallo\Commerce\Search;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Tenancy\CommerceTenantResolution;
use Psr\Container\ContainerInterface;
use Thallo\Commerce\Http\Shop\ShopProductCardAssembler;
use Thallo\Commerce\Shop\ViewModels\ProductCardViewModel;
use Thallo\Contracts\Content\RichHtmlSanitizer;
use Thallo\Contracts\Search\KindFilter;
use Thallo\Contracts\Search\ResultDisplay;
use Thallo\Contracts\Search\SearchAudience;
use Thallo\Contracts\Search\SearchDocument;
use Thallo\Contracts\Search\SearchDocumentPage;
use Thallo\Contracts\Search\SearchSourceContributor;

/**
 * Products as search results (search block spec §3.2). Listed means what the storefront lists —
 * live, buyer-available and active, the rule the product page uses. A product's body is its name,
 * its description as plain words, and its category and tag names; it is shown with its current
 * name, price and picture, read through the same card assembly as the shop's grids, so a renamed
 * or withdrawn product never shows stale.
 *
 * Registered whether or not Commerce is on, so the kind is discoverable ("requires Commerce"); the
 * engine's services are resolved only when used, and with the engine absent there is nothing.
 */
final class ProductsSearchContributor implements SearchSourceContributor
{
    public const KIND = 'products';
    private const BATCH = 100;
    private const TEXT_LIMIT = 20480;

    public function __construct(
        private readonly ContainerInterface $container,
        private readonly ApplicationContext $context,
    ) {
    }

    public function kind(): string
    {
        return self::KIND;
    }

    public function label(): string
    {
        return 'Products';
    }

    public function requiredCapabilities(): array
    {
        return ['thallo.commerce'];
    }

    public function schemaVersion(): int
    {
        return 1;
    }

    public function documents(string $sourceId): array
    {
        return array_values($this->build([$sourceId]));
    }

    public function enumerate(?string $after, int $size): SearchDocumentPage
    {
        if (!$this->engine()) {
            return new SearchDocumentPage([], null);
        }
        $query = $this->products()->activeFilteredQuery($this->context, $this->tenant(), null)
            ->select(['uuid'])
            ->orderBy('uuid', 'ASC')
            ->limit($size);
        if ($after !== null) {
            $query->where('uuid', '>', $after);
        }
        $uuids = array_map(static fn (array $r): string => (string) $r['uuid'], $query->get());
        $documents = $this->build($uuids);
        $ordered = [];
        foreach ($uuids as $uuid) {
            if (isset($documents[$uuid])) {
                $ordered[] = $documents[$uuid];
            }
        }
        return new SearchDocumentPage($ordered, count($uuids) === $size ? (string) end($uuids) : null);
    }

    public function visibilityFilter(SearchAudience $audience): KindFilter
    {
        // Only listed products are indexed, and present() re-checks each against current records.
        return KindFilter::all();
    }

    public function present(SearchAudience $audience, string $locale, array $sourceIds): array
    {
        $out = array_fill_keys($sourceIds, null);
        if (!$this->engine()) {
            return $out;
        }
        foreach (array_chunk($sourceIds, self::BATCH) as $chunk) {
            [$rows, $cards] = $this->load($chunk);
            foreach ($cards as $uuid => $card) {
                $out[$uuid] = new ResultDisplay(
                    $card->name,
                    $card->url,
                    mb_strcut($this->text($rows[$uuid]['description'] ?? null), 0, self::TEXT_LIMIT, 'UTF-8'),
                    $card->coverUrl,
                    $card->priceFormatted,
                );
            }
        }
        return $out;
    }

    /**
     * @param list<string> $uuids
     * @return array<string, SearchDocument> uuid => document, for the listed ones
     */
    private function build(array $uuids): array
    {
        if ($uuids === [] || !$this->engine()) {
            return [];
        }
        $documents = [];
        foreach (array_chunk($uuids, self::BATCH) as $chunk) {
            [$rows, $cards] = $this->load($chunk);
            $taxonomy = $this->taxonomy(array_keys($cards));
            foreach ($cards as $uuid => $card) {
                $body = implode("\n\n", array_filter([
                    $card->name,
                    $this->text($rows[$uuid]['description'] ?? null),
                    implode(' ', $taxonomy[$uuid] ?? []),
                ]));
                $meta = array_filter(['image' => $card->coverUrl, 'price' => $card->priceFormatted]);
                $documents[$uuid] = new SearchDocument(
                    self::KIND,
                    $uuid,
                    '*',
                    null,
                    $card->url,
                    $card->name,
                    $body,
                    $meta,
                );
            }
        }
        return $documents;
    }

    /**
     * @param list<string> $uuids at most one batch
     * @return array{0: array<string, array<string, mixed>>, 1: array<string, ProductCardViewModel>}
     */
    private function load(array $uuids): array
    {
        $tenant = $this->tenant();
        $rows = $this->products()->findActiveBuyerAvailableByUuids($this->context, $tenant, $uuids);
        $cards = [];
        foreach ($this->container->get(ShopProductCardAssembler::class)->cards($tenant, array_values($rows)) as $card) {
            $cards[$card->uuid] = $card;
        }
        return [$rows, $cards];
    }

    /**
     * Category and tag names per product, in one query each.
     *
     * @param list<string> $uuids
     * @return array<string, list<string>>
     */
    private function taxonomy(array $uuids): array
    {
        if ($uuids === []) {
            return [];
        }
        $db = $this->container->get(Connection::class);
        $tenant = $this->tenant();
        $names = [];
        foreach (
            [
            ['commerce_product_categories', 'commerce_categories', 'category_uuid'],
            ['commerce_product_tags', 'commerce_tags', 'tag_uuid'],
            ] as [$link, $table, $key]
        ) {
            $rows = $db->table($link)
                ->join($table, "{$link}.{$key}", '=', "{$table}.uuid")
                ->select(["{$link}.product_uuid", "{$table}.name"])
                ->where("{$table}.tenant_uuid", '=', $tenant)
                ->whereIn("{$link}.product_uuid", $uuids)
                ->get();
            foreach ($rows as $row) {
                $names[(string) $row['product_uuid']][] = (string) $row['name'];
            }
        }
        return $names;
    }

    /** Rich description as the words a shopper reads: sanitised first, then stripped. */
    private function text(mixed $html): string
    {
        if (!is_string($html) || $html === '') {
            return '';
        }
        $sanitizer = $this->container->has(RichHtmlSanitizer::class)
            ? $this->container->get(RichHtmlSanitizer::class)
            : null;
        try {
            $safe = $sanitizer instanceof RichHtmlSanitizer ? $sanitizer->sanitize($html) : htmlspecialchars($html);
        } catch (\Throwable) {
            $safe = htmlspecialchars($html);
        }
        $text = (string) preg_replace('~<(?:/(?:p|div|h[1-6]|li)|br\s*/?)>~i', ' ', $safe);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private function engine(): bool
    {
        return $this->container->has(ProductRepository::class)
            && $this->container->has(CommerceTenantResolution::class);
    }

    private function products(): ProductRepository
    {
        return $this->container->get(ProductRepository::class);
    }

    private function tenant(): string
    {
        return $this->container->get(CommerceTenantResolution::class)->tenantUuid($this->context);
    }
}
