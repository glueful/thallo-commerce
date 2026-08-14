<?php

declare(strict_types=1);

namespace Thallo\Commerce\Payments;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Audit\Contracts\AuditRecorderInterface;
use Glueful\Extensions\Audit\Support\AuditEntry;
use Glueful\Extensions\Commerce\Events\LatePaymentRejected;
use Glueful\Extensions\Commerce\Orders\OrderRepository;

/**
 * Cleanup-train Task 10: the subscriber `LatePaymentRejected` never had.
 *
 * `OrderPaymentService::rejectLatePayment()` records a `payment_late_rejected` order event and
 * dispatches this event — and until now nothing listened, so a provider payment that may need
 * refunding was discoverable only by opening the one order it landed on and reading its timeline.
 * {@see WebhookOrderSettlementListener} made late payments materially more likely, which is what
 * raised the value of surfacing them somewhere an operator can find them without knowing where to
 * look.
 *
 * ## The surface: the audit log, deliberately
 *
 * The cheapest surface in this tree that is genuinely OPERATOR-VISIBLE. `glueful/audit` is an
 * enabled extension of the host app; it already owns `audit_logs`, a `GET /audit-logs` read API
 * behind the `audit.view` permission, and an admin page with a category filter. Writing one entry
 * costs no new table, no new endpoint and no new screen.
 *
 * The framework's `notifications` table would have been the literal "notification" answer and is
 * the wrong one: nothing in the tree reads it, so a row there is invisible until somebody builds
 * the read surface — which is the notification system this task is explicitly not to build.
 *
 * `security` is the category because it is one of the categories the shipped admin filter offers
 * and the one this app already files custody facts under; a category outside that list would put
 * the entry where an operator cannot filter for it.
 *
 * ## The de-dupe key: the payment reference, per order
 *
 * `rejectLatePayment()` is ALSO where the T4 idempotent concession lands: when two settlement
 * paths race, the loser of the `pending_payment -> paid` compare-and-set routes its confirmation
 * here even though that very payment settled the order correctly. Providers redeliver webhooks,
 * so that fires again and again — every time with the SAME reference. A reference that settled
 * this order is not a refund question, and repeating it is not a second one.
 *
 * The genuinely refund-relevant case is a DIFFERENT reference: a distinct attempt that paid an
 * order somebody else had already settled. So this records at most one entry per
 * `(order, reference)` pair.
 *
 * The memory is the order-event trail itself — the engine writes `payment_late_rejected` BEFORE
 * it dispatches, so by the time this listener runs the current rejection is already recorded and
 * a SECOND matching row means an earlier one had already been surfaced. That keeps the de-dupe
 * durable across processes and restarts without inventing a table to hold it, and it keeps this
 * class a display decision rather than a filter: every rejection stays in the engine's trail
 * whether or not it is echoed here.
 *
 * ## Best-effort, always
 *
 * This runs on the webhook settlement lane. A throw here would turn a correctly-refused late
 * payment into a failed webhook the provider retries indefinitely, so every failure — an
 * unusable payload, an unreadable trail, an audit backend that is down — is swallowed. The
 * engine's own order event remains the authoritative record either way.
 */
final class LatePaymentVisibilityListener
{
    /** The engine's own order-event type, which is also this listener's de-dupe substrate. */
    public const ORDER_EVENT_TYPE = 'payment_late_rejected';

    /** The audit `action` an operator filters/searches for. */
    public const AUDIT_ACTION = 'commerce.payment_late_rejected';

    /** In the audit vocabulary the admin filter dropdown offers — see the class docblock. */
    private const AUDIT_CATEGORY = 'security';

    private const AUDIT_TARGET_TYPE = 'commerce_order';

    public function __construct(
        private readonly ApplicationContext $context,
        private readonly OrderRepository $orders,
        private readonly AuditRecorderInterface $audit,
    ) {
    }

    public function onLatePaymentRejected(LatePaymentRejected $event): void
    {
        try {
            $this->surface($event->payload);
        } catch (\Throwable) {
            // Best-effort by construction — see the class docblock. The engine's
            // `payment_late_rejected` order event is unaffected and still records the fact.
        }
    }

    /** @param array<string,mixed> $payload */
    private function surface(array $payload): void
    {
        $orderUuid = trim((string) ($payload['order_uuid'] ?? ''));
        if ($orderUuid === '') {
            return;
        }

        $tenantUuid = (string) ($payload['tenant_uuid'] ?? '');
        $reference = trim((string) ($payload['reference'] ?? ''));

        if ($this->alreadySurfaced($tenantUuid, $orderUuid, $reference)) {
            return;
        }

        $this->audit->record(new AuditEntry(
            occurredAt: microtime(true),
            action: self::AUDIT_ACTION,
            category: self::AUDIT_CATEGORY,
            // No operator did this: it is a provider-driven settlement outcome.
            actorUuid: null,
            targetType: self::AUDIT_TARGET_TYPE,
            targetUuid: $orderUuid,
            context: [
                'tenant_uuid' => $tenantUuid,
                'reference' => $reference,
                'status' => (string) ($payload['status'] ?? ''),
                'amount' => (int) ($payload['amount'] ?? 0),
                'currency' => (string) ($payload['currency'] ?? ''),
                // Said in words, because an audit row is read by whoever is on call, not by code.
                'summary' => 'A payment arrived for an order that was already settled; it was '
                    . 'refused and may need refunding.',
            ],
        ));
    }

    /**
     * Has THIS `(order, reference)` pair already produced an entry?
     *
     * The engine records its order event before dispatching, so the current rejection is one of
     * the rows counted here: one match is this rejection, and anything beyond that is an earlier
     * rejection of the same reference that was already surfaced. A trail this listener cannot
     * read (an event dispatched outside the engine, a failed query) counts zero and is surfaced
     * rather than dropped — under-reporting a possible refund is the worse failure.
     */
    private function alreadySurfaced(string $tenantUuid, string $orderUuid, string $reference): bool
    {
        $matches = 0;

        foreach ($this->orders->eventsForOrder($this->context, $tenantUuid, $orderUuid) as $event) {
            if ((string) ($event['type'] ?? '') !== self::ORDER_EVENT_TYPE) {
                continue;
            }

            $payload = $event['payload'] ?? null;
            $recorded = is_array($payload) ? trim((string) ($payload['reference'] ?? '')) : '';
            if ($recorded === $reference) {
                $matches++;
            }
        }

        return $matches > 1;
    }
}
