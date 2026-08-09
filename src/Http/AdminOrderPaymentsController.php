<?php

declare(strict_types=1);

namespace Thallo\Commerce\Http;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Tenancy\CommerceTenantResolution;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Commerce\Payments\OrderPaymentSummaryRepository;

/**
 * Orders-invoices-receipts plan, Task 5: `GET /v1/admin/commerce/orders/{uuid}/payments` -- the
 * admin order payment summary. View authority (`commerce.view,commerce.manage`, route
 * middleware), matching every other read in this pack's own group.
 *
 * Order-first, same tenant-scoped lookup the mounted admin show route uses
 * ({@see OrderRepository::findByUuid()}): an unknown OR cross-tenant order uuid is a
 * non-revealing 404 returned BEFORE this controller ever asks
 * {@see OrderPaymentSummaryRepository} anything -- zero Payvia queries for a 404, by
 * construction (the early return below, not a try/catch).
 *
 * Once the order resolves, the envelope is INVARIANT on every 200 --
 * `{available, payments, intents, refund}` -- regardless of whether Payvia's tables are
 * migrated: `available` reports {@see OrderPaymentSummaryRepository::available()} verbatim, and
 * `payments`/`intents` are whatever that repository returns (empty arrays when tables are
 * absent, never omitted). `refund` is echoed from the ALREADY-VALIDATED order row, never
 * re-queried -- it is the same `refunded_total`/`refund_revision` pair
 * {@see OrderRepository::claimOrderFinancialMutation()} maintains.
 */
final class AdminOrderPaymentsController
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly OrderRepository $orders,
        private readonly OrderPaymentSummaryRepository $summaries,
        private readonly CommerceTenantResolution $tenants,
    ) {
    }

    #[ApiOperation(summary: 'Order payment summary', tags: ['Thallo Commerce'])]
    public function payments(Request $request, string $uuid): Response
    {
        $tenant = $this->tenants->tenantUuid($this->context);
        $order = $this->orders->findByUuid($this->context, $tenant, $uuid);
        if ($order === null) {
            return Response::error('Resource not found.', 404);
        }

        return Response::success([
            'available' => $this->summaries->available(),
            'payments' => $this->summaries->paymentsFor($tenant, $uuid),
            'intents' => $this->summaries->intentsFor($tenant, $uuid),
            'refund' => [
                'refunded_total' => (int) ($order['refunded_total'] ?? 0),
                'refund_revision' => (int) ($order['refund_revision'] ?? 0),
            ],
        ], 'Order payments retrieved');
    }
}
