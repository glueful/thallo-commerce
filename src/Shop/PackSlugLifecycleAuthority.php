<?php

declare(strict_types=1);

namespace Thallo\Commerce\Shop;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Commerce\Catalog\SlugLifecycleAuthority;
use Glueful\Validation\ValidationException;

/**
 * The pack's transactional slug-reservation authority (storefront-rendering spec §4,
 * verbatim). Bound to Commerce's {@see SlugLifecycleAuthority} seam — {@see CatalogService}
 * (Commerce-side) soft-resolves and invokes this INSIDE the SAME transaction as the
 * create/rename it guards, BEFORE the product row write, so any throw here rolls back the
 * whole create/rename together with everything else that transaction touches.
 *
 * Every claim takes a xact-scoped `pg_advisory_xact_lock(hashtextextended(?, 0))` on
 * `thallo_commerce_slug:{tenant}:{slug}` (mirrors {@see \Thallo\Commerce\Links\ProductLinkRepository}'s
 * identical lock idiom from Slice-1): `prepareCreate()` locks the proposed slug alone;
 * `prepareRename()` locks old+new, DEDUPLICATED and SORTED lexicographically, closing the
 * classic two-key deadlock window the exact same way ProductLinkRepository::lockIdentities()
 * does. Locks release automatically at COMMIT/ROLLBACK — this class never explicitly unlocks.
 *
 * THREE arbitration layers close the whole slug space (design spec §4 -- "neither unique
 * constraint is claimed to do that alone"):
 *   - `commerce_products`' own `unique(tenant_uuid, slug)` is the ground truth for
 *     current/current collisions (two live products can never both hold one slug).
 *   - `thallo_commerce_product_slugs`' own `unique(tenant_uuid, slug)` is the ground truth
 *     for history/history collisions (two DIFFERENT products can never both reserve one old
 *     slug).
 *   - The shared advisory lock is what makes BOTH of those checks race-safe and their
 *     failures FRIENDLY (a 422, never a raw unique-constraint PDOException): every claim
 *     re-reads BOTH tables under the lock — by the time a second, initially-unaware racer
 *     acquires the lock, the first racer's transaction has already committed (or rolled
 *     back) and is visible, so the re-read reliably observes the true post-race state. This
 *     is the "current/history cross-table race" the design spec calls out: Commerce's own
 *     pre-lock precheck (`findIncludingDeletedBySlug`, run before this class is even
 *     invoked) is unavoidably racy on its own — two concurrent creates/renames can both pass
 *     it before either commits. The lock+re-read here is what turns that race into a clean
 *     winner/422 outcome instead of a surfaced database-level unique-violation exception.
 */
final class PackSlugLifecycleAuthority implements SlugLifecycleAuthority
{
    private const TABLE = 'thallo_commerce_product_slugs';
    private const PRODUCTS_TABLE = 'commerce_products';
    private const LOCK_SQL = 'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))';

    public function __construct(private readonly Connection $connection)
    {
    }

    /** The stable advisory-lock identity string for one tenant+slug claim. */
    public static function lockKey(string $tenant, string $slug): string
    {
        return "thallo_commerce_slug:{$tenant}:{$slug}";
    }

    public function prepareCreate(ApplicationContext $c, string $tenant, string $productUuid, string $slug): void
    {
        $this->lock($tenant, $slug);
        $this->rejectIfLiveElsewhere($tenant, $slug, $productUuid);
        $this->rejectIfReservedElsewhere($tenant, $slug, $productUuid);
    }

    public function prepareRename(
        ApplicationContext $c,
        string $tenant,
        string $productUuid,
        string $old,
        string $new
    ): void {
        foreach ($this->sortedKeys($old, $new) as $slug) {
            $this->lock($tenant, $slug);
        }

        // Validate the NEW slug isn't reserved (live or history) for a DIFFERENT product —
        // Commerce's own pre-lock precheck already ruled out a LIVE collision at call time,
        // but that check is racy (see class docblock); this one is not.
        $this->rejectIfLiveElsewhere($tenant, $new, $productUuid);
        $this->rejectIfReservedElsewhere($tenant, $new, $productUuid);

        // Reserve the OLD slug so it keeps redirecting to this product — idempotent: a prior
        // rename may have already reserved it (impossible in practice since a live product's
        // OWN old slug can never already be reserved by itself, but idempotency costs nothing
        // and matches the design spec's explicit wording).
        $this->reserveIdempotent($tenant, $old, $productUuid);

        // A -> B -> A: if the NEW slug is a reservation this SAME product already holds from
        // an earlier rename, it is live again — drop the stale history row so a later 301
        // never fires for a slug the product currently owns outright.
        $this->releaseIfOwnedBy($tenant, $new, $productUuid);
    }

    /** Ledger lookup for the product route's old-slug 301 (storefront-rendering spec §4). */
    public function findReservation(string $tenant, string $slug): ?string
    {
        $row = $this->connection->table(self::TABLE)
            ->where('tenant_uuid', '=', $tenant)
            ->where('slug', '=', $slug)
            ->first();

        return $row === null ? null : (string) $row['product_uuid'];
    }

    /** @return list<string> */
    private function sortedKeys(string $a, string $b): array
    {
        $keys = array_values(array_unique([$a, $b]));
        sort($keys, SORT_STRING);

        return $keys;
    }

    private function lock(string $tenant, string $slug): void
    {
        $statement = $this->connection->getPDO()->prepare(self::LOCK_SQL);
        $statement->execute([self::lockKey($tenant, $slug)]);
    }

    /**
     * withTrashed() mirrors Commerce's OWN `findIncludingDeletedBySlug()` semantics exactly
     * (a tombstoned row keeps reserving its slug) — this is the re-check that makes a
     * current/current race resolve to a clean 422 instead of a raw unique-constraint
     * exception (see class docblock).
     */
    private function rejectIfLiveElsewhere(string $tenant, string $slug, string $productUuid): void
    {
        $row = $this->connection->table(self::PRODUCTS_TABLE)
            ->withTrashed()
            ->where('tenant_uuid', '=', $tenant)
            ->where('slug', '=', $slug)
            ->first();
        if ($row !== null && (string) $row['uuid'] !== $productUuid) {
            throw ValidationException::forField('slug', 'Slug already in use.');
        }
    }

    private function rejectIfReservedElsewhere(string $tenant, string $slug, string $productUuid): void
    {
        $row = $this->connection->table(self::TABLE)
            ->where('tenant_uuid', '=', $tenant)
            ->where('slug', '=', $slug)
            ->first();
        if ($row !== null && (string) $row['product_uuid'] !== $productUuid) {
            throw ValidationException::forField('slug', 'Slug already reserved.');
        }
    }

    private function reserveIdempotent(string $tenant, string $slug, string $productUuid): void
    {
        $existing = $this->connection->table(self::TABLE)
            ->where('tenant_uuid', '=', $tenant)
            ->where('slug', '=', $slug)
            ->first();
        if ($existing !== null) {
            return; // Already reserved (by this same product — guaranteed by the checks above).
        }

        $this->connection->table(self::TABLE)->insert([
            'tenant_uuid' => $tenant,
            'slug' => $slug,
            'product_uuid' => $productUuid,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    private function releaseIfOwnedBy(string $tenant, string $slug, string $productUuid): void
    {
        $this->connection->table(self::TABLE)
            ->where('tenant_uuid', '=', $tenant)
            ->where('slug', '=', $slug)
            ->where('product_uuid', '=', $productUuid)
            ->delete();
    }
}
