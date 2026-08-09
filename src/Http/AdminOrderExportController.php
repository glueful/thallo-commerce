<?php

declare(strict_types=1);

namespace Thallo\Commerce\Http;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Http\Admin\OrderProjection;
use Glueful\Extensions\Commerce\Tenancy\CommerceTenantResolution;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Thallo\Commerce\Orders\AdminOrderSearchFilter;
use Thallo\Commerce\Orders\AdminOrderSearchQuery;
use Thallo\Commerce\Orders\OrderCsvWriter;

/**
 * TEMPORARY OWNERSHIP (orders-invoices-receipts plan, Task 4): `GET /v1/admin/commerce/orders/
 * export`, a bounded streamed CSV export sharing {@see AdminOrderSearchQuery}/
 * {@see AdminOrderSearchFilter} with the search endpoint ({@see AdminOrderSearchController}) --
 * the SAME classes, no second query path, no re-validated params. Retires alongside those two
 * classes (see {@see AdminOrderSearchQuery}'s own docblock for the retirement condition). View
 * authority (`commerce.view,commerce.manage`, route middleware).
 *
 * Flow: apply the SAME filter predicates to a fresh builder -> unsorted `COUNT(*)`
 * ({@see \Glueful\Database\QueryBuilder::count()} builds its own count query from the WHERE
 * clause only, entirely bypassing report-time ordering) -> more than {@see self::MAX_ROWS}
 * matching rows is a 422 returned BEFORE any `StreamedResponse`/CSV headers are ever constructed
 * -> otherwise stream CSV rows in keyset batches of {@see self::BATCH_SIZE} using
 * {@see AdminOrderSearchQuery::applyOrder()}, each row projected through
 * {@see OrderProjection::forAdmin()} before {@see OrderCsvWriter::row()}.
 *
 * The keyset cursor repeats the real report-time expression in its WHERE predicate (the SELECT
 * alias `COALESCE(placed_at, created_at)` does not exist in that clause): `(COALESCE(placed_at,
 * created_at) < ?) OR (COALESCE(placed_at, created_at) = ? AND id < ?)`, wrapped in one extra
 * pair of parens so it ANDs correctly with the filter's own predicates. Bound with the last row's
 * OWN raw `placed_at`/`created_at`/`id` -- captured from the RAW row before projection strips
 * `id` -- never the select alias, never interpolated.
 */
final class AdminOrderExportController
{
    private const MAX_ROWS = 10000;
    private const BATCH_SIZE = 500;

    public function __construct(
        private readonly ApplicationContext $context,
        private readonly AdminOrderSearchQuery $searchQuery,
        private readonly CommerceTenantResolution $tenants,
    ) {
    }

    #[ApiOperation(summary: 'Export orders as CSV (app-owned, temporary)', tags: ['Thallo Commerce'])]
    public function export(Request $request): HttpResponse
    {
        $tenant = $this->tenants->tenantUuid($this->context);

        $countQuery = $this->searchQuery->builder($tenant);
        (new AdminOrderSearchFilter($request))->apply($countQuery); // throws ValidationException -> 422
        $total = $countQuery->count();

        if ($total > self::MAX_ROWS) {
            return Response::error(
                'Export exceeds 10,000 rows — narrow your filters.',
                HttpResponse::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $response = new StreamedResponse(function () use ($request, $tenant): void {
            $this->streamRows($request, $tenant);
        });
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set(
            'Content-Disposition',
            $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, 'orders-export.csv'),
        );

        return $response;
    }

    private function streamRows(Request $request, string $tenant): void
    {
        $out = fopen('php://output', 'wb');
        if ($out === false) {
            return;
        }

        // PHP 8.4: $escape must be explicit ('' = no special escaping, RFC-4180 quoting only).
        fputcsv($out, OrderCsvWriter::COLUMNS, ',', '"', '');

        $cursor = null;
        do {
            $query = $this->searchQuery->builder($tenant);
            (new AdminOrderSearchFilter($request))->apply($query);
            if ($cursor !== null) {
                [$cursorTime, $cursorId] = $cursor;
                $query->whereRaw(
                    '((COALESCE(placed_at, created_at) < ?)'
                        . ' OR (COALESCE(placed_at, created_at) = ? AND id < ?))',
                    [$cursorTime, $cursorTime, $cursorId],
                );
            }
            $this->searchQuery->applyOrder($query);
            $query->limit(self::BATCH_SIZE);

            $rows = $query->get();
            foreach ($rows as $row) {
                fputcsv($out, OrderCsvWriter::row(OrderProjection::forAdmin($row)), ',', '"', '');
            }

            $count = count($rows);
            if ($count > 0) {
                $last = $rows[$count - 1];
                $cursor = [$last['placed_at'] ?? $last['created_at'], $last['id']];
            }
        } while ($count === self::BATCH_SIZE);

        fclose($out);
    }
}
