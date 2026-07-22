<?php

declare(strict_types=1);

namespace Thallo\Commerce\Links;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Commerce\Tenancy\CommerceTenantResolution;
use Glueful\Extensions\I18n\Contracts\LocaleManagerInterface;

/**
 * Tenant-scoped entry search for the admin product<->entry linkage picker (design spec §5.3,
 * Thallo admin-commerce-area plan slice 3, task 7).
 *
 * Queries the engine's shared `entries` / `entry_drafts` / `entry_publications` /
 * `content_types` tables directly via {@see Connection} rather than the engine app's own entry
 * repository: this pack, like every other cross-pack read in this codebase (e.g.
 * {@see \Thallo\Commerce\Listeners\EntryDeletedListener}), may not import the engine app's own
 * namespace. Deliberately does NOT go through
 * {@see \Thallo\Contracts\Content\EntryExistenceReader} either -- that contract is a bare
 * single-uuid existence probe, not a searchable listing.
 *
 * Tenant scoping mirrors the engine app's own entry-existence reader's belt-and-suspenders
 * convention: `entries.tenant_uuid` only exists once the tenancy retrofit has widened the table
 * ({@see \Thallo\Tenancy\ThalloTenantTables}), so the filter is applied only when the column is
 * actually present (checked once per call via the schema builder) -- a clean-install/single-store
 * boot (no `tenant_uuid` column at all) naturally sees every entry. The tenant identity itself
 * comes from the SAME {@see CommerceTenantResolution} seam {@see ProductLinkService} already uses
 * for its own product/entry reads.
 *
 * One row per entry, never a locale fan-out: the display locale is resolved ONCE per call --
 * the caller-requested locale when supplied AND enabled, otherwise the i18n default -- so a
 * title search can never return the same entry twice for two different locales. An entry with no
 * draft at all in the resolved locale has no title to show and is silently excluded (its title
 * can never match a non-empty query anyway).
 *
 * The display title is the resolved-locale draft's `title` field -- the SAME derivation the
 * engine's own admin entry list uses, and matching is an in-PHP case-insensitive substring test
 * over the bounded candidate set: correct and bounded by per-tenant entry count, not a SQL-level
 * text search.
 */
final class EntryLinkSearch
{
    public function __construct(
        private readonly Connection $connection,
        private readonly CommerceTenantResolution $tenants,
        private readonly LocaleManagerInterface $locales,
    ) {
    }

    /**
     * @return list<array{uuid:string,title:string,content_type:string,status:string,locale:string}>
     */
    public function search(ApplicationContext $context, string $query, ?string $requestedLocale, int $limit): array
    {
        $tenant = $this->tenants->tenantUuid($context);
        $locale = $this->resolveLocale($requestedLocale);
        $needle = trim($query);

        $entriesQuery = $this->connection->table('entries')->where('status', '=', 'active');
        if ($this->connection->getSchemaBuilder()->hasColumn('entries', 'tenant_uuid')) {
            $entriesQuery->where('tenant_uuid', '=', $tenant);
        }
        $entryRows = $entriesQuery->orderBy('id', 'DESC')->get();
        if ($entryRows === []) {
            return [];
        }

        $uuids = array_map(static fn (array $r): string => (string) $r['uuid'], $entryRows);
        $typeUuidByEntry = [];
        foreach ($entryRows as $row) {
            $typeUuidByEntry[(string) $row['uuid']] = (string) $row['content_type_uuid'];
        }

        $titleByEntry = [];
        foreach (
            $this->connection->table('entry_drafts')
                ->whereIn('entry_uuid', $uuids)
                ->where('locale', '=', $locale)
                ->get() as $row
        ) {
            $raw = $row['fields'] ?? [];
            $fields = is_string($raw) ? ((array) json_decode($raw, true)) : (array) $raw;
            $title = $fields['title'] ?? null;
            if (is_string($title) && $title !== '') {
                $titleByEntry[(string) $row['entry_uuid']] = $title;
            }
        }

        $publishedEntries = [];
        foreach (
            $this->connection->table('entry_publications')
                ->whereIn('entry_uuid', $uuids)
                ->where('locale', '=', $locale)
                ->get() as $row
        ) {
            $publishedEntries[(string) $row['entry_uuid']] = true;
        }

        $typeUuids = array_values(array_unique(array_values($typeUuidByEntry)));
        $typeSlugByUuid = [];
        if ($typeUuids !== []) {
            foreach (
                $this->connection->table('content_types')
                    ->select(['uuid', 'slug'])
                    ->whereIn('uuid', $typeUuids)
                    ->get() as $row
            ) {
                $typeSlugByUuid[(string) $row['uuid']] = (string) $row['slug'];
            }
        }

        $results = [];
        foreach ($entryRows as $row) {
            $uuid = (string) $row['uuid'];
            $title = $titleByEntry[$uuid] ?? null;
            if ($title === null || ($needle !== '' && stripos($title, $needle) === false)) {
                continue;
            }

            $results[] = [
                'uuid' => $uuid,
                'title' => $title,
                'content_type' => $typeSlugByUuid[$typeUuidByEntry[$uuid]] ?? '',
                'status' => isset($publishedEntries[$uuid]) ? 'published' : 'draft',
                'locale' => $locale,
            ];
            if (count($results) >= $limit) {
                break;
            }
        }

        return $results;
    }

    /**
     * Requested locale when supplied AND enabled; otherwise the workspace default -- mirrors the
     * engine app's own content-locale service's identical rule (this pack cannot depend on that
     * engine-app-namespaced class directly, so the enabled-set normalization is repeated here
     * against the same {@see LocaleManagerInterface} it wraps).
     */
    private function resolveLocale(?string $requested): string
    {
        if ($requested !== null && $requested !== '' && $this->isEnabled($requested)) {
            return $requested;
        }

        return $this->locales->default();
    }

    private function isEnabled(string $locale): bool
    {
        foreach ($this->locales->enabled() as $row) {
            $code = is_array($row) ? ($row['code'] ?? null) : $row;
            if (is_string($code) && $code === $locale) {
                return true;
            }
        }

        return false;
    }
}
