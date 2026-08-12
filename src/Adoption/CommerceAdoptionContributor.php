<?php

declare(strict_types=1);

namespace Thallo\Commerce\Adoption;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Commerce\Support\DiagnosticsReport;
use Glueful\Extensions\Commerce\Tenancy\TenantAdopter;
use Thallo\Tenancy\Adoption\AdoptionContributor;

/**
 * Commerce-Slice-1 Task 10: the pack's {@see AdoptionContributor} (design spec §8.1). Runs
 * during `TenancyEnablement::confirm()`'s RETROFITTING step, as trusted system-context work,
 * AFTER the default tenant has been provisioned and the schema widened but BEFORE enforcement
 * activation — with the retrofit write-barrier UP the entire time
 * ({@see \Thallo\Tenancy\Retrofit\RetrofitWriteBarrierInterceptor}).
 *
 * The barrier only refuses builder mutations against tables listed in
 * {@see \Thallo\Tenancy\ThalloTenantTables} (core Thallo-owned tables) — verified by reading
 * the interceptor's owned-table match, which returns BEFORE ever consulting the guard for any
 * table not in that list. `thallo_commerce_product_links` (pack-owned) and every Commerce table
 * this contributor touches (via {@see TenantAdopter}) are outside that list, so both writes below
 * pass through the barrier untouched — there is nothing to bypass or coordinate with; this
 * contributor simply writes through the ordinary query builder like any other pack-owned write.
 *
 * `adopt()` is ONE operation: rekey this pack's own sentinel link rows, then delegate to
 * Commerce's existing {@see TenantAdopter::adopt()} — each package keeps ownership of what
 * adoption means for its own schema (design spec §8.1). Both halves are naturally idempotent
 * under a retry (`TenancyEnablement::retry()` re-invokes every registered contributor from
 * scratch): the link rekey is a plain `WHERE tenant_uuid = ''` update (zero matching rows on a
 * second call is a silent no-op), and `TenantAdopter::adopt()` itself is idempotent by
 * construction — its "mixed data" refusal only fires for rows belonging to some OTHER tenant
 * (`tenant_uuid NOT IN ('', $tenantUuid)`), never for rows already adopted into the SAME tenant
 * this call targets, and its rekey step is gated by `count(sentinel rows) > 0` per table, so a
 * second call touching zero remaining sentinel rows performs no writes and returns cleanly
 * instead of throwing. No additional "skip when zero sentinel rows remain" guard is needed on
 * top of that — the app's `PurgeAdoptionTest` suite proves this with a real second invocation.
 */
final class CommerceAdoptionContributor implements AdoptionContributor
{
    public const ID = 'thallo.commerce';

    private const LINK_TABLE = 'thallo_commerce_product_links';

    /**
     * The payment-link delivery ledger (payment-links spec §2.4, which names tenant adoption as
     * part of that table's contract). Adopted by the same sentinel rekey as the link table: a
     * pre-tenancy install's delivery rows carry `tenant_uuid = ''` and must join the default
     * tenant, or the ledger's tenant-scoped idempotency unique would arbitrate against rows the
     * post-enablement request can no longer see — turning a replay into a fresh send.
     */
    private const DELIVERY_TABLE = 'thallo_commerce_payment_link_deliveries';

    public function __construct(
        private readonly Connection $connection,
        private readonly ?TenantAdopter $adopter,
    ) {
    }

    public function id(): string
    {
        return self::ID;
    }

    /**
     * The pack's own link table plus every table Commerce's `TenantAdopter` rekeys — proven
     * registered by `FinalizationProbe` before enforcement may report ON (design spec §8.1).
     * `DiagnosticsReport::tenantTables()` is a plain static method (no container/DI dependency),
     * so it is always callable once glueful/commerce's classes are autoloadable (a hard composer
     * dependency of this pack, design spec §3) regardless of whether Commerce's OWN provider is
     * active in this process — the `class_exists()` guard below is a defensive belt for the
     * Commerce-package-genuinely-absent edge case, not the expected inactive-provider case.
     *
     * @return list<string>
     */
    public function tables(): array
    {
        if (!class_exists(DiagnosticsReport::class)) {
            return [self::LINK_TABLE, self::DELIVERY_TABLE];
        }

        return [self::LINK_TABLE, self::DELIVERY_TABLE, ...DiagnosticsReport::tenantTables()];
    }

    public function adopt(ApplicationContext $context, string $tenantUuid): void
    {
        foreach ([self::LINK_TABLE, self::DELIVERY_TABLE] as $table) {
            $this->connection->table($table)
                ->where('tenant_uuid', '')
                ->update(['tenant_uuid' => $tenantUuid]);
        }

        // Soft-resolved: Commerce's provider may be inactive even though its composer package is
        // always present (design spec §3, "inactive-Commerce inertness"). Adoption is not
        // destructive, so an inactive Commerce simply means there is nothing of Commerce's to
        // adopt here — the pack's own link rows above are still adopted either way.
        $this->adopter?->adopt($context, $tenantUuid);
    }
}
