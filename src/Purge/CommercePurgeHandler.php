<?php

declare(strict_types=1);

namespace Thallo\Commerce\Purge;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Commerce\Tenancy\CommerceTenantPurge;
use Thallo\Tenancy\Purge\PurgeHandler;

/**
 * Commerce-Slice-1 Task 10: the pack's {@see PurgeHandler} (design spec §8.2). Deletes the
 * tenant's `thallo_commerce_product_links` rows, then delegates Commerce-table purging to
 * {@see CommerceTenantPurge} — the pack never re-lists Commerce's tenant/child tables itself,
 * so that inventory stays owned by Commerce and can never drift out of lockstep.
 *
 * Registered with `PurgeResourceRegistry` OUTSIDE the `thallo.commerce` capability gate
 * (mirrors `CollectionsPurgeHandler`'s precedent — an aliased shared service, picked up by
 * `Thallo\Tenancy\TenancyServiceProvider::makePurgeResourceRegistry()`): a run requested while
 * the capability happens to be disabled must still fully clean up data created before the
 * switch-off, exactly like every other pack's purge handler.
 *
 * Data-destruction code, fail-closed by construction: because this handler stays registered
 * (and therefore reachable by a real purge run) even when Commerce's OWN provider is inactive,
 * its factory soft-resolves {@see CommerceTenantPurge} (a container `has()` check, NOT a
 * `class_exists()` check — glueful/commerce is a hard composer dependency of this pack per
 * design spec §3, so the class is always autoloadable; what varies is whether Commerce's
 * provider registered the service). Three cases:
 *
 *  - Bound: every method runs Commerce-table purge/verify for real.
 *  - Unbound + no Commerce schema (`commerce_products` absent, e.g. Commerce's own migrations
 *    never ran): there is nothing for Commerce to purge, so link-only cleanup completes.
 *  - Unbound + Commerce schema PRESENT: `prepare()`, `purge()`, and `verify()` all throw. A run
 *    must never report success while quietly leaving a tenant's Commerce rows behind — that
 *    would be indistinguishable from a clean purge to every caller of this handler.
 */
final class CommercePurgeHandler implements PurgeHandler
{
    private const LINK_TABLE = 'thallo_commerce_product_links';

    /**
     * The payment-link delivery ledger (payment-links spec §2.4, which names tenant purge as part
     * of that table's contract). Purged with the same unconditional `where tenant_uuid` delete as
     * the link table above: its rows are tenant-owned by the same reasoning, and a leftover
     * delivery row would keep a purged workspace's order uuids and recipient hashes on disk.
     */
    private const DELIVERY_TABLE = 'thallo_commerce_payment_link_deliveries';

    /** Minimal proof Commerce's schema exists — one of {@see CommerceTenantPurge}'s own tables. */
    private const SCHEMA_MARKER_TABLE = 'commerce_products';

    public function __construct(
        private readonly Connection $connection,
        private readonly ?CommerceTenantPurge $commercePurge,
    ) {
    }

    public function id(): string
    {
        return 'thallo.commerce';
    }

    /**
     * No dependency in either direction: the link table carries no DB foreign key into Commerce
     * (design spec §5.1 — cross-package boundary, kept coherent by lifecycle events/reconciliation
     * instead) or into any core Thallo table, and nothing else in the registry references this
     * handler's id. `thallo.tables` (the generic core-table handler) purges only tables listed in
     * `ThalloTenantTables`, which never includes pack tables, so there is no ordering constraint
     * to declare on either side.
     *
     * @return list<string>
     */
    public function dependsOn(): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    public function prepare(ApplicationContext $context, string $tenantUuid): array
    {
        $this->assertSafeToRun();

        return [
            'link_count' => $this->linkCount($tenantUuid),
            'delivery_count' => $this->rowCount(self::DELIVERY_TABLE, $tenantUuid),
            'commerce_counts' => $this->commercePurge?->countTenantRows($context, $tenantUuid) ?? [],
        ];
    }

    /** @param array<string, mixed> $artifacts */
    public function purge(ApplicationContext $context, string $tenantUuid, array $artifacts): void
    {
        $this->assertSafeToRun();

        $this->connection->table(self::LINK_TABLE)
            ->where('tenant_uuid', $tenantUuid)
            ->forceDelete();

        $this->connection->table(self::DELIVERY_TABLE)
            ->where('tenant_uuid', $tenantUuid)
            ->forceDelete();

        $this->commercePurge?->purgeTenant($context, $tenantUuid);
    }

    /** @param array<string, mixed> $artifacts */
    public function verify(ApplicationContext $context, string $tenantUuid, array $artifacts): bool
    {
        $this->assertSafeToRun();

        if ($this->linkCount($tenantUuid) !== 0) {
            return false;
        }

        if ($this->rowCount(self::DELIVERY_TABLE, $tenantUuid) !== 0) {
            return false;
        }

        if ($this->commercePurge === null) {
            return true; // assertSafeToRun() already proved there is no Commerce schema to check.
        }

        foreach ($this->commercePurge->countTenantRows($context, $tenantUuid) as $count) {
            if ($count !== 0) {
                return false;
            }
        }

        return true;
    }

    private function linkCount(string $tenantUuid): int
    {
        return $this->rowCount(self::LINK_TABLE, $tenantUuid);
    }

    private function rowCount(string $table, string $tenantUuid): int
    {
        return (int) $this->connection->table($table)
            ->where('tenant_uuid', $tenantUuid)
            ->count();
    }

    /**
     * Fail-closed gate, run at the top of every method (prepare/purge/verify all reachable
     * independently — e.g. a resumed run re-enters at `purge()` with cached `prepare()`
     * artifacts, so the guard cannot live only in `prepare()`).
     */
    private function assertSafeToRun(): void
    {
        if ($this->commercePurge !== null) {
            return;
        }

        if ($this->connection->getSchemaBuilder()->hasTable(self::SCHEMA_MARKER_TABLE)) {
            throw new \RuntimeException(
                'Commerce schema is present but CommerceTenantPurge is unavailable (Commerce\'s '
                . 'provider is inactive in this process); refusing to purge the tenant to avoid '
                . 'reporting a successful run that leaves Commerce tenant data behind.'
            );
        }
    }
}
