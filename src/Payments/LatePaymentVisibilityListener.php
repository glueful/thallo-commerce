<?php

declare(strict_types=1);

namespace Thallo\Commerce\Payments;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Audit\Contracts\AuditRecorderInterface;
use Glueful\Extensions\Audit\Support\AuditEntry;
use Glueful\Extensions\Commerce\Events\LatePaymentRejected;
use Psr\Log\LoggerInterface;

use function db;

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
 * **The memory is our own audit writes, not the engine's order-event trail.** An earlier version
 * counted matching `payment_late_rejected` order events (durable, no new table) — but the engine
 * records that event UNCONDITIONALLY and SYNCHRONOUSLY, before it dispatches. Two concurrent
 * rejections of the same reference therefore both land their order-event row before either
 * listener invocation gets to check anything: both then see two matching rows, both conclude
 * "already surfaced", and BOTH skip — zero audit entries for a genuinely refund-relevant
 * occurrence. Counting our own audit rows instead means the same interleaving can produce at
 * worst a DUPLICATE entry (both racers see none yet, both write) — never a dropped one. A
 * duplicate is an operator re-reading the same fact twice; a silent drop is the fact never
 * reaching them at all. That asymmetry is accepted, not eliminated: this check is a best-effort
 * display decision, not a lock, and the engine's own order-event trail remains the complete,
 * un-de-duped record regardless of what lands here.
 *
 * One more residual worth naming: because the check only looks at what THIS listener has already
 * written, the very FIRST entry for a reference can itself be the benign race-loser concession
 * described above rather than a genuine second payment — de-duping only ever suppresses the
 * SECOND-and-later delivery of a reference, never the first. The audit `summary` text is worded
 * to hedge accordingly rather than assert a refund is owed.
 *
 * ## Best-effort, always
 *
 * Every failure — an unusable payload, a database error, an audit backend that is down — is
 * caught and logged rather than allowed to propagate. This is NOT because an uncaught exception
 * here would break anything upstream: `rejectLatePayment()` dispatches through the ordinary
 * {@see \Glueful\Events\EventService::dispatch()} bus, which already fault-isolates and logs each
 * listener independently (one broken listener cannot stop the others or bubble into the webhook
 * request that triggered it). The catch exists so a failure here is logged with THIS listener's
 * own context instead of only the dispatcher's generic "listener failed" line, and so a bug in
 * this best-effort display decision can never turn into a bug in the payment it is decorating.
 * The engine's own order event remains the authoritative record either way.
 */
final class LatePaymentVisibilityListener
{
    /** The engine's own order-event type — recorded unconditionally, independent of this listener. */
    public const ORDER_EVENT_TYPE = 'payment_late_rejected';

    /** The audit `action` an operator filters/searches for. */
    public const AUDIT_ACTION = 'commerce.payment_late_rejected';

    /** In the audit vocabulary the admin filter dropdown offers — see the class docblock. */
    private const AUDIT_CATEGORY = 'security';

    private const AUDIT_TARGET_TYPE = 'commerce_order';

    public function __construct(
        private readonly ApplicationContext $context,
        private readonly AuditRecorderInterface $audit,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function onLatePaymentRejected(LatePaymentRejected $event): void
    {
        try {
            $this->surface($event->payload);
        } catch (\Throwable $e) {
            // Best-effort by construction — see the class docblock. The engine's
            // `payment_late_rejected` order event is unaffected and still records the fact.
            // Logged (not silently swallowed) so a real defect here is still diagnosable.
            $this->logger?->error('Late-payment visibility listener failed: ' . $e->getMessage(), [
                'exception' => $e,
                'payload' => $event->payload,
            ]);
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
                // Said in words, because an audit row is read by whoever is on call, not by
                // code. Deliberately hedged, not asserted: this can be the FIRST delivery of a
                // race-loser concession that in fact settled the order correctly (see class
                // docblock) as easily as a genuine second payment — an on-call reader must check,
                // not assume.
                'summary' => 'A late or duplicate payment confirmation was refused; verify '
                    . 'whether a refund is due.',
            ],
        ));
    }

    /**
     * Has an AUDIT entry — not merely an engine order EVENT — already been written for this
     * `(order, reference)` pair? See the class docblock for why counting our own writes, rather
     * than the order-event trail, is the race-safe direction: worst case under concurrent
     * delivery is a duplicate entry, never a dropped one.
     */
    private function alreadySurfaced(string $tenantUuid, string $orderUuid, string $reference): bool
    {
        $rows = db($this->context)->table('audit_logs')
            ->select(['context'])
            ->where('action', self::AUDIT_ACTION)
            ->where('target_type', self::AUDIT_TARGET_TYPE)
            ->where('target_uuid', $orderUuid)
            ->get();

        foreach ($rows as $row) {
            $context = json_decode((string) ($row['context'] ?? ''), true);
            if (!is_array($context)) {
                continue;
            }

            $sameReference = trim((string) ($context['reference'] ?? '')) === $reference;
            $sameTenant = (string) ($context['tenant_uuid'] ?? '') === $tenantUuid;
            if ($sameReference && $sameTenant) {
                return true;
            }
        }

        return false;
    }
}
