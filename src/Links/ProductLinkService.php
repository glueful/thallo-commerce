<?php

declare(strict_types=1);

namespace Thallo\Commerce\Links;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Events\EventService;
use Glueful\Extensions\Commerce\Catalog\CatalogReader;
use Glueful\Extensions\Commerce\Tenancy\CommerceTenantResolution;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Thallo\Commerce\Events\ProductLinkChanged;
use Thallo\Contracts\Content\EntryExistenceReader;

/**
 * The canonical product<->entry enrichment link (design spec §5.2).
 *
 * Tenant comes ONLY from {@see CommerceTenantResolution} — never from caller/request input.
 *
 * Every mutation serializes through {@see ProductLinkRepository::lockIdentities()} over the
 * COMPLETE affected identity set — product, requested/new entry, and the expected entry (when
 * present) — deduplicated and sorted lexicographically BEFORE any lock is acquired; state is
 * re-read only AFTER every lock in that set is held. No code path here ever acquires a lock
 * discovered mid-transaction (a late, out-of-order lock) — see {@see self::unlink()}'s bounded
 * snapshot/retry loop for the one operation whose full identity set cannot be known upfront.
 */
final class ProductLinkService
{
    private const MAX_UNLINK_RETRIES = 3;

    public function __construct(
        private readonly Connection $connection,
        private readonly ProductLinkRepository $links,
        private readonly CatalogReader $catalog,
        private readonly EntryExistenceReader $entries,
        private readonly CommerceTenantResolution $tenants,
        private readonly ?EventService $events = null,
    ) {
    }

    /**
     * Link (or relink, given a matching `$expectedEntryUuid`) a product to an entry.
     *
     * @throws NotFoundException (404, non-revealing) unknown/cross-tenant/tombstoned product,
     *     or unknown/cross-tenant entry
     * @throws LinkConflictException (409) the product is already linked without a matching
     *     expectation, the expectation is stale, the target entry already belongs to a
     *     different product, or a unique-constraint race lost
     * @return array<string,mixed> the resulting link row
     */
    public function link(
        ApplicationContext $context,
        string $productUuid,
        string $entryUuid,
        ?string $expectedEntryUuid = null,
    ): array {
        $tenant = $this->tenants->tenantUuid($context);

        // Complete affected identity set — product, new entry, expected entry (when present) —
        // deduplicated + sorted lexicographically INSIDE lockIdentities(), before any lock.
        $keys = [
            ProductLinkRepository::productKey($tenant, $productUuid),
            ProductLinkRepository::entryKey($tenant, $entryUuid),
        ];
        if ($expectedEntryUuid !== null) {
            $keys[] = ProductLinkRepository::entryKey($tenant, $expectedEntryUuid);
        }

        return db($context)->transaction(
            function () use ($context, $tenant, $productUuid, $entryUuid, $expectedEntryUuid, $keys): array {
                $this->links->lockIdentities($this->connection, ...$keys);

                // Re-read only after every lock in the set is held.
                if ($this->catalog->findLiveProduct($context, $tenant, $productUuid) === null) {
                    throw new NotFoundException('Product not found.');
                }
                if ($this->entries->exists($entryUuid, $tenant) === null) {
                    throw new NotFoundException('Entry not found.');
                }

                $existing = $this->links->findByProduct($tenant, $productUuid);
                $oldEntryUuid = $existing === null ? null : (string) $existing['entry_uuid'];
                if ($expectedEntryUuid !== null) {
                    // An explicit expectation is a compare-and-swap token: it must match the
                    // CURRENT state exactly, including "no current link at all" (null !==
                    // expectedEntryUuid) — a stale expectation against an already-unlinked
                    // product is a conflict too, never a silent fresh link (design spec §5.2:
                    // "never an implicit upsert").
                    if ($expectedEntryUuid !== $oldEntryUuid) {
                        throw new LinkConflictException(
                            'expected_entry_uuid does not match the product\'s current link.',
                        );
                    }
                } elseif ($existing !== null) {
                    // Already linked, no expectation supplied — never an implicit upsert.
                    throw new LinkConflictException(
                        'Product is already linked to a different entry; supply the current '
                        . 'expected_entry_uuid to relink.',
                    );
                }

                $entryLink = $this->links->findByEntry($tenant, $entryUuid);
                if ($entryLink !== null && (string) $entryLink['product_uuid'] !== $productUuid) {
                    throw new LinkConflictException('Entry is already linked to a different product.');
                }

                try {
                    if ($existing !== null) {
                        // Rows are active-links-only (design spec §5.1) — relink is a real
                        // delete-then-insert, never an update, so a retired row never collides
                        // with the entry unique when that entry is relinked elsewhere later.
                        $this->links->delete($tenant, $productUuid);
                    }
                    $row = $this->links->insert($tenant, $productUuid, $entryUuid);
                } catch (\PDOException $e) {
                    if ($e->getCode() === '23505') {
                        throw new LinkConflictException('Link already exists.', previous: $e);
                    }
                    throw $e;
                }

                $action = $existing === null ? 'link' : 'relink';
                $this->auditAfterCommit($context, $action, $tenant, $productUuid, $oldEntryUuid, $entryUuid);

                return $row;
            },
        );
    }

    /**
     * Remove the product's active link, if any (idempotent no-op when there is none).
     *
     * Snapshot/lock/re-read protocol (design spec §5.2): the CURRENTLY linked entry cannot be
     * known before a lock is held, so this takes an UNLOCKED snapshot to discover it, opens the
     * transaction, locks product + THAT snapshot entry (sorted), then re-reads. If the current
     * entry has moved on since the snapshot, {@see UnlinkSnapshotDrift} forces a real rollback
     * (releasing the xact-scoped locks) and the whole snapshot/lock/re-read sequence is retried
     * from scratch, bounded by {@see self::MAX_UNLINK_RETRIES} — this NEVER acquires the
     * newly-discovered entry's lock inside the already-open transaction.
     *
     * @throws LinkConflictException (409) the link kept changing across every retry attempt
     */
    public function unlink(ApplicationContext $context, string $productUuid): void
    {
        $tenant = $this->tenants->tenantUuid($context);

        for ($attempt = 1; $attempt <= self::MAX_UNLINK_RETRIES; $attempt++) {
            $snapshot = $this->links->findByProduct($tenant, $productUuid);
            if ($snapshot === null) {
                return; // Nothing to unlink.
            }
            $snapshotEntryUuid = (string) $snapshot['entry_uuid'];

            $keys = [
                ProductLinkRepository::productKey($tenant, $productUuid),
                ProductLinkRepository::entryKey($tenant, $snapshotEntryUuid),
            ];

            try {
                db($context)->transaction(
                    function () use ($context, $tenant, $productUuid, $snapshotEntryUuid, $keys): void {
                        $this->links->lockIdentities($this->connection, ...$keys);

                        $current = $this->links->findByProduct($tenant, $productUuid);
                        if ($current === null) {
                            return; // Already gone.
                        }
                        if ((string) $current['entry_uuid'] !== $snapshotEntryUuid) {
                            throw new UnlinkSnapshotDrift();
                        }

                        $this->links->delete($tenant, $productUuid);
                        $this->auditAfterCommit(
                            $context,
                            'unlink',
                            $tenant,
                            $productUuid,
                            $snapshotEntryUuid,
                            null,
                        );
                    },
                );

                return;
            } catch (UnlinkSnapshotDrift) {
                continue; // Retry the whole snapshot/lock/re-read sequence from scratch.
            }
        }

        throw new LinkConflictException(
            'Could not unlink product: the link kept changing under concurrent modification.',
        );
    }

    /** @return array<string,mixed>|null the link row, or null when absent or fail-closed */
    public function resolveByProduct(ApplicationContext $context, string $productUuid): ?array
    {
        $tenant = $this->tenants->tenantUuid($context);

        return $this->liveOrNull($context, $tenant, $this->links->findByProduct($tenant, $productUuid));
    }

    /**
     * Resolve a product's slug for the admin link-lookup projection (design spec §5.3, task 7),
     * or null when the product is unknown/cross-tenant/tombstoned -- the 404 mapping. Deliberately
     * independent of {@see self::resolveByProduct()}: a perfectly valid, accessible product may
     * carry no active link at all, and the admin lookup must still resolve it (200, `link: null`)
     * rather than folding "no link" and "no product" into the same absent-row signal.
     */
    public function resolveProductSlug(ApplicationContext $context, string $productUuid): ?string
    {
        $tenant = $this->tenants->tenantUuid($context);
        $product = $this->catalog->findLiveProduct($context, $tenant, $productUuid);

        return $product === null ? null : (string) $product['slug'];
    }

    /** @return array<string,mixed>|null the link row, or null when absent or fail-closed */
    public function resolveByEntry(ApplicationContext $context, string $entryUuid): ?array
    {
        $tenant = $this->tenants->tenantUuid($context);

        return $this->liveOrNull($context, $tenant, $this->links->findByEntry($tenant, $entryUuid));
    }

    /**
     * Fail-closed read guard (design spec §5.2): a link row whose product or entry no longer
     * resolves in this tenant (tombstoned product, deleted/cross-tenant entry) is reported as
     * absent rather than stale — the reconcile sweep (a later task) is the backstop that
     * removes such rows outright.
     *
     * @param array<string,mixed>|null $row
     * @return array<string,mixed>|null
     */
    private function liveOrNull(ApplicationContext $context, string $tenant, ?array $row): ?array
    {
        if ($row === null) {
            return null;
        }
        if ($this->catalog->findLiveProduct($context, $tenant, (string) $row['product_uuid']) === null) {
            return null;
        }
        if ($this->entries->exists((string) $row['entry_uuid'], $tenant) === null) {
            return null;
        }

        return $row;
    }

    /** Audit ONLY via afterCommit() (design spec §5.2) — a rolled-back mutation emits nothing. */
    private function auditAfterCommit(
        ApplicationContext $context,
        string $action,
        string $tenant,
        string $productUuid,
        ?string $oldEntryUuid,
        ?string $newEntryUuid,
    ): void {
        $events = $this->events;
        if ($events === null) {
            return;
        }
        db($context)->afterCommit(static function () use (
            $events,
            $action,
            $tenant,
            $productUuid,
            $oldEntryUuid,
            $newEntryUuid,
        ): void {
            $events->dispatch(new ProductLinkChanged($action, $tenant, $productUuid, $oldEntryUuid, $newEntryUuid));
        });
    }
}
