<?php

declare(strict_types=1);

namespace Thallo\Commerce\Links;

use Glueful\Database\Connection;
use Glueful\Helpers\Utils;

/**
 * Row CRUD for `thallo_commerce_product_links` + the advisory-lock helper the service's
 * link/relink/unlink protocol serializes through (design spec §5.2).
 *
 * Lock identities are plain strings hashed through Postgres' `hashtextextended(text, bigint)`
 * into the 64-bit key `pg_advisory_xact_lock()` takes. XACT-scoped: every lock acquired here is
 * released automatically at the end of the CURRENT transaction (COMMIT or ROLLBACK) — this
 * class never explicitly unlocks and never holds a lock past the transaction that acquired it.
 */
final class ProductLinkRepository
{
    private const TABLE = 'thallo_commerce_product_links';
    private const LOCK_SQL = 'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))';

    public function __construct(private readonly Connection $connection)
    {
    }

    /** The stable lock-identity string for the product side of a link mutation. */
    public static function productKey(string $tenant, string $productUuid): string
    {
        return "thallo_commerce_link:{$tenant}:product:{$productUuid}";
    }

    /** The stable lock-identity string for an entry side of a link mutation. */
    public static function entryKey(string $tenant, string $entryUuid): string
    {
        return "thallo_commerce_link:{$tenant}:entry:{$entryUuid}";
    }

    /**
     * Acquire an xact-scoped advisory lock for each DISTINCT key, in stable lexicographic
     * order. Callers must build the COMPLETE affected identity set (product + new entry +
     * expected entry, when present) BEFORE calling this once — acquiring a key discovered only
     * after this returns would be a late, out-of-order lock, and no caller in this pack ever
     * does that.
     */
    public function lockIdentities(Connection $connection, string ...$keys): void
    {
        $unique = array_values(array_unique($keys));
        sort($unique, SORT_STRING);

        $pdo = $connection->getPDO();
        foreach ($unique as $key) {
            $statement = $pdo->prepare(self::LOCK_SQL);
            $statement->execute([$key]);
        }
    }

    /** @return array<string,mixed>|null */
    public function findByProduct(string $tenant, string $productUuid): ?array
    {
        $row = $this->connection->table(self::TABLE)
            ->where('tenant_uuid', '=', $tenant)
            ->where('product_uuid', '=', $productUuid)
            ->first();

        return $row === null ? null : (array) $row;
    }

    /** @return array<string,mixed>|null */
    public function findByEntry(string $tenant, string $entryUuid): ?array
    {
        $row = $this->connection->table(self::TABLE)
            ->where('tenant_uuid', '=', $tenant)
            ->where('entry_uuid', '=', $entryUuid)
            ->first();

        return $row === null ? null : (array) $row;
    }

    /**
     * Insert a new active link row. Rows are active-links-only (no status/retirement column,
     * design spec §5.1) — a relink is always a delete-then-insert, never an update.
     *
     * @return array<string,mixed> the inserted row
     */
    public function insert(string $tenant, string $productUuid, string $entryUuid): array
    {
        $now = gmdate('Y-m-d H:i:s');
        $uuid = Utils::generateNanoID();

        $this->connection->table(self::TABLE)->insert([
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'product_uuid' => $productUuid,
            'entry_uuid' => $entryUuid,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'product_uuid' => $productUuid,
            'entry_uuid' => $entryUuid,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    public function delete(string $tenant, string $productUuid): void
    {
        $this->connection->table(self::TABLE)
            ->where('tenant_uuid', '=', $tenant)
            ->where('product_uuid', '=', $productUuid)
            ->delete();
    }

    /**
     * Distinct tenant uuids that currently own at least one link row (includes the '' sentinel
     * tenant, design spec §4). Used by the reconcile sweep/diagnostics to discover which tenants
     * have anything to scan, WITHOUT depending on a full tenant registry (this pack owns only
     * its own link table).
     *
     * @return list<string>
     */
    public function distinctTenants(): array
    {
        $rows = $this->connection->table(self::TABLE)
            ->select(['tenant_uuid'])
            ->distinct()
            ->orderBy('tenant_uuid')
            ->get();

        return array_map(static fn (array $row): string => (string) $row['tenant_uuid'], $rows);
    }

    /**
     * Every link row for one tenant, ordered for deterministic scanning/paging.
     *
     * @return list<array<string,mixed>>
     */
    public function forTenant(string $tenant): array
    {
        return $this->connection->table(self::TABLE)
            ->where('tenant_uuid', '=', $tenant)
            ->orderBy('uuid')
            ->get();
    }
}
