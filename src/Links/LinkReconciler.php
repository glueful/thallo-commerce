<?php

declare(strict_types=1);

namespace Thallo\Commerce\Links;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Events\EventService;
use Glueful\Extensions\Commerce\Catalog\CatalogReader;
use Glueful\Extensions\Contracts\Tenancy\TenantContextRunner;
use Psr\Container\ContainerInterface;
use Thallo\Commerce\Events\ProductLinkChanged;
use Thallo\Contracts\Content\EntryExistenceReader;
use Thallo\Tenancy\System\SystemFlags;

/**
 * Shared drift detection + removal for `thallo_commerce_product_links` rows (design spec
 * §6.2/§7) -- a row is stale when its product is tombstoned/absent ({@see CatalogReader}) or
 * its entry is gone ({@see EntryExistenceReader}), the SAME two probes
 * {@see ProductLinkService::liveOrNull()} already uses for reads. Both
 * {@see \Thallo\Commerce\Console\ReconcileLinksCommand} (removal) and
 * {@see \Thallo\Commerce\Diagnostics\CommerceIntegrationDiagnostics} (read-only count) go
 * through this class so they can never disagree about what "stale" means.
 *
 * Deliberately does NOT require {@see CatalogReader}/{@see EntryExistenceReader} at
 * construction: this service is resolved EAGERLY at boot (console command registration --
 * {@see \Glueful\Extensions\ServiceProvider::commands()} constructs every registered command
 * immediately when running in console mode, not lazily on invocation) and Commerce's own
 * provider may be installed-but-inactive (design spec §1's "soft detection" state, in which
 * CatalogReader is simply unbound). A hard constructor dependency on CatalogReader would crash
 * EVERY CLI invocation in that state, not just this pack's own commands. {@see isCommerceActive()}
 * is the single source of truth other callers use to decide whether to proceed.
 */
final class LinkReconciler
{
    public function __construct(
        private readonly ProductLinkRepository $links,
        private readonly ContainerInterface $container,
        private readonly SystemFlags $flags,
        private readonly ?EventService $events = null,
    ) {
    }

    /** True when Commerce's own provider is active (its read contracts are container-bound). */
    public function isCommerceActive(): bool
    {
        return $this->container->has(CatalogReader::class) && $this->container->has(EntryExistenceReader::class);
    }

    /** @return list<string> distinct tenant uuids with at least one link row (may include ''). */
    public function discoverTenants(): array
    {
        return $this->links->distinctTenants();
    }

    /**
     * Stale rows for one tenant. $limit caps how many STALE rows are returned (the reconcile
     * sweep's batch budget); null scans every row for the tenant (diagnostics' full count).
     * Returns [] immediately when Commerce is inactive -- there is nothing to validate against.
     *
     * @return list<array<string,mixed>> each row plus a 'reason' key
     *     ('product_missing'|'entry_missing')
     */
    public function scanTenant(ApplicationContext $context, string $tenant, ?int $limit): array
    {
        if (!$this->isCommerceActive() || ($limit !== null && $limit <= 0)) {
            return [];
        }

        /** @var CatalogReader $catalog */
        $catalog = $this->container->get(CatalogReader::class);
        /** @var EntryExistenceReader $entries */
        $entries = $this->container->get(EntryExistenceReader::class);

        $scan = function () use ($context, $tenant, $limit, $catalog, $entries): array {
            $stale = [];
            foreach ($this->links->forTenant($tenant) as $row) {
                if ($limit !== null && count($stale) >= $limit) {
                    break;
                }
                $reason = match (true) {
                    $catalog->findLiveProduct($context, $tenant, (string) $row['product_uuid']) === null
                        => 'product_missing',
                    $entries->exists((string) $row['entry_uuid'], $tenant) === null
                        => 'entry_missing',
                    default => null,
                };
                if ($reason !== null) {
                    $row['reason'] = $reason;
                    $stale[] = $row;
                }
            }
            return $stale;
        };

        return $this->withTenantContext($tenant, $scan);
    }

    /**
     * Remove the given (already-scanned) stale rows, one own-transaction + after-commit audit
     * per row (mirrors {@see ProductLinkService::unlink()}'s discipline). Returns the count
     * actually removed.
     *
     * @param list<array<string,mixed>> $staleRows
     */
    public function remove(ApplicationContext $context, array $staleRows): int
    {
        $removed = 0;
        foreach ($staleRows as $row) {
            $tenant = (string) $row['tenant_uuid'];
            $productUuid = (string) $row['product_uuid'];
            $entryUuid = (string) $row['entry_uuid'];

            db($context)->transaction(function () use ($context, $tenant, $productUuid, $entryUuid): void {
                $this->links->delete($tenant, $productUuid);
                $this->auditAfterCommit($context, $tenant, $productUuid, $entryUuid);
            });
            $removed++;
        }

        return $removed;
    }

    /**
     * Run $fn with the ambient context switched to $tenant, but ONLY when tenancy ENFORCEMENT is
     * actually active (mode (c)) -- required so CatalogReader/EntryExistenceReader reads for an
     * ARBITRARY tenant (not necessarily the request's own) are not silently mis-scoped by an
     * ambient table hook (mirrors the engine app's StarterSync service's identical per-tenant
     * wrapping). Gating on enforcement, not merely on whether {@see TenantContextRunner} happens
     * to be bound, is deliberate: the vendor tenancy extension's control-plane provider binds it
     * UNCONDITIONALLY (always-on, independent of enforcement), and its `runAsTenant()` requires
     * a REAL, ACTIVE tenant row -- calling it for modes (a)/(b) (sentinel `''`/a widened default
     * tenant that is not necessarily a `tenants` row) would throw `TenantNotFoundException`
     * rather than no-op. A no-op pass-through otherwise -- the default test environment runs
     * without enforcement (see config/testing/extensions.php), so the wrapped branch is
     * exercised only under THALLO_TENANCY_DEV_LINK=1, matching TenantResolutionModesTest's own
     * established gate.
     */
    private function withTenantContext(string $tenant, callable $fn): mixed
    {
        if ($this->flags->enforcementActive() && $this->container->has(TenantContextRunner::class)) {
            /** @var TenantContextRunner $runner */
            $runner = $this->container->get(TenantContextRunner::class);

            return $runner->runAsTenant($tenant, $fn);
        }

        return $fn();
    }

    /** Audit ONLY via afterCommit() -- a rolled-back removal must emit nothing. */
    private function auditAfterCommit(
        ApplicationContext $context,
        string $tenant,
        string $productUuid,
        string $entryUuid,
    ): void {
        $events = $this->events;
        if ($events === null) {
            return;
        }
        db($context)->afterCommit(static function () use ($events, $tenant, $productUuid, $entryUuid): void {
            $events->dispatch(new ProductLinkChanged('unlink', $tenant, $productUuid, $entryUuid, null));
        });
    }
}
