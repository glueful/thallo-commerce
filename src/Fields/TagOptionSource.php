<?php

declare(strict_types=1);

namespace Thallo\Commerce\Fields;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Catalog\TagRepository;
use Glueful\Extensions\Commerce\Tenancy\CommerceTenantResolution;
use Thallo\Contracts\Fields\FieldOptionSource;

/** The store's tags for a block field (product grid spec §5.3): value the slug, label the name. */
final class TagOptionSource implements FieldOptionSource
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly CommerceTenantResolution $tenants,
        private readonly TagRepository $tags,
    ) {
    }

    public function id(): string
    {
        return 'thallo-commerce.tags';
    }

    /** The catalogue's own read permission (spec §5.3). */
    public function permission(): string
    {
        return 'commerce.view';
    }

    public function options(): array
    {
        $rows = $this->tags->all($this->context, $this->tenants->tenantUuid($this->context));
        usort($rows, static fn (array $a, array $b): int => strcasecmp((string) $a['name'], (string) $b['name']));
        return array_map(static fn (array $row): array => [
            'value' => (string) $row['slug'],
            'label' => (string) $row['name'],
            'available' => true,
            'reason' => null,
        ], $rows);
    }
}
