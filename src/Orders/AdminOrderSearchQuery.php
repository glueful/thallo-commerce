<?php

declare(strict_types=1);

namespace Thallo\Commerce\Orders;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\QueryBuilder;

use function db;

/**
 * TEMPORARY OWNERSHIP (orders-invoices-receipts plan, Task 3): the app-owned tenant-predicated
 * `commerce_orders` builder + report-time ordering, until Commerce's own admin orders endpoint
 * gains equivalent filter parity (status/fulfillment/date-range/free-text search) upstream — at
 * that point this class (and {@see AdminOrderSearchFilter}/`AdminOrderSearchController`) retire
 * in favor of the vendored mount ({@see \Glueful\Extensions\Commerce\Http\Routing\AdminRouteCatalog}).
 *
 * The ONLY constructor of the tenant-scoped `commerce_orders` query (`builder()`) and the ONLY
 * owner of report-time ordering (`applyOrder()`) — both list/count/export call sites share this
 * single definition of "which rows" and "what order" so they can never drift from one another.
 *
 * Report-time ordering is deliberately the two-branch indexable form, never
 * `ORDER BY COALESCE(placed_at, created_at) DESC` (a computed expression an index on either
 * bare column cannot serve): a plain `id DESC` tie-break is sufficient here because within either
 * branch report-time strictly increases with `id` (insertion order), so `id DESC` never
 * reorders two rows whose report time is genuinely equal in a way a caller would notice — it
 * only breaks ties between rows sharing the SAME report-time value.
 */
final class AdminOrderSearchQuery
{
    public function __construct(private readonly ApplicationContext $context)
    {
    }

    /** The tenant-predicated `commerce_orders` builder — the sole entry point for this table. */
    public function builder(string $tenantUuid): QueryBuilder
    {
        return db($this->context)->table('commerce_orders')->where('tenant_uuid', $tenantUuid);
    }

    /**
     * Report-time DESC with an `id` tie-break. Applied AFTER filtering (never before) so a
     * caller's predicate never has to account for ordering side effects.
     */
    public function applyOrder(QueryBuilder $query): QueryBuilder
    {
        return $query->orderByRaw('COALESCE(placed_at, created_at) DESC, id DESC');
    }
}
