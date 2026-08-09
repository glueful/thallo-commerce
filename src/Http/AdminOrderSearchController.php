<?php

declare(strict_types=1);

namespace Thallo\Commerce\Http;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Http\Admin\OrderProjection;
use Glueful\Extensions\Commerce\Tenancy\CommerceTenantResolution;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Commerce\Orders\AdminOrderSearchFilter;
use Thallo\Commerce\Orders\AdminOrderSearchQuery;

/**
 * TEMPORARY OWNERSHIP (orders-invoices-receipts plan, Task 3): `GET /v1/admin/commerce/orders/
 * search`, an app-owned filtered orders search until Commerce's own admin orders endpoint gains
 * equivalent filter parity upstream — retire this controller (and
 * {@see AdminOrderSearchQuery}/{@see AdminOrderSearchFilter}) at that point in favor of the
 * vendored mount. View authority (`commerce.view,commerce.manage`, route middleware).
 *
 * Pipeline, in order: resolve the tenant-scoped builder ({@see AdminOrderSearchQuery::builder()})
 * -> apply the closed filter contract ({@see AdminOrderSearchFilter::apply()}, which may throw
 * {@see \Glueful\Validation\ValidationException} for a 422) -> apply report-time ordering
 * ({@see AdminOrderSearchQuery::applyOrder()}, ONLY after filtering) -> paginate -> project every
 * row through {@see OrderProjection::forAdmin()} before it ever reaches {@see Response::paginated()}.
 */
final class AdminOrderSearchController
{
    private const DEFAULT_PER_PAGE = 24;
    private const MAX_PER_PAGE = 100;

    public function __construct(
        private readonly ApplicationContext $context,
        private readonly AdminOrderSearchQuery $searchQuery,
        private readonly CommerceTenantResolution $tenants,
    ) {
    }

    #[ApiOperation(summary: 'Search orders (app-owned, temporary)', tags: ['Thallo Commerce'])]
    public function search(Request $request): Response
    {
        $tenant = $this->tenants->tenantUuid($this->context);
        $query = $this->searchQuery->builder($tenant);

        (new AdminOrderSearchFilter($request))->apply($query); // throws ValidationException -> 422

        $this->searchQuery->applyOrder($query);

        [$page, $perPage] = $this->paginationParams($request);
        $result = $query->paginate($page, $perPage);

        $rows = array_map(
            static fn (array $row): array => OrderProjection::forAdmin($row),
            $result['data'],
        );

        return Response::paginated($rows, (int) $result['total'], $page, $perPage);
    }

    /** @return array{0:int,1:int} [page, per_page] — page >= 1, per_page clamped to 1..100. */
    private function paginationParams(Request $request): array
    {
        $page = max(1, (int) $request->query->get('page', 1));

        $perPage = (int) $request->query->get('per_page', self::DEFAULT_PER_PAGE);
        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));

        return [$page, $perPage];
    }
}
