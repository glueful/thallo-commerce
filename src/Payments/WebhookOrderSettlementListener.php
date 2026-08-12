<?php

declare(strict_types=1);

namespace Thallo\Commerce\Payments;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Payments\OrderPayable;
use Glueful\Extensions\Contracts\Payments\PayableReference;
use Glueful\Extensions\Contracts\Payments\PaymentConfirmation;
use Glueful\Extensions\Contracts\Tenancy\TenantContextRequiredException;
use Glueful\Extensions\Contracts\Tenancy\TenantContextRunner;
use Glueful\Extensions\Payvia\Contracts\PaymentProviderEventInterface;
use Glueful\Extensions\Payvia\Contracts\StrictPaymentEventListener;
use Glueful\Extensions\Payvia\Events\EventType;
use Glueful\Extensions\Payvia\Repositories\PaymentIntentRepository;
use Glueful\Extensions\Payvia\Services\ConfirmationDispatcher;

/**
 * THE WEBHOOK → ORDER SETTLEMENT LANE (payment-links final review).
 *
 * ## The gap this closes
 *
 * Before this class there was NO in-tree path from a provider webhook to a paid order. Payvia's
 * {@see \Glueful\Extensions\Payvia\Services\WebhookService::dispatch()} fans a verified event out
 * to (1) the fault-isolated `PaymentProviderEvent` bus, (2) the tagged strict lane, (3) the
 * chargeback dispatcher — and none of those reach {@see ConfirmationDispatcher}. The ONLY caller
 * of that dispatcher was
 * {@see \Glueful\Extensions\Payvia\Services\PaymentService::confirmAndRecord()}, reachable only
 * from the AUTHENTICATED `POST /payvia/payments/confirm`. A payer who completed a hosted checkout
 * and never came back through the return URL left the order `pending_payment` forever, and the
 * payment link unconsumed, no matter how many times the provider said "paid".
 *
 * ## Why the STRICT lane and not the ordinary bus
 *
 * `EventService::dispatch()` catches and logs listener exceptions, so a settlement that failed on
 * the bus would be lost with no retry — unacceptable for money. The strict lane
 * ({@see StrictPaymentEventListener}) runs UNCAUGHT under the webhook's logical-dispatch lease: a
 * throw releases the lease, leaves the row un-dispatched, and the SAME event is retried (by an
 * immediate `processStored()` or a later `relayPending()`). That is the delivery guarantee this
 * work needs, and it is the same lane `glueful/subscriptions` uses for its own bridge.
 *
 * ## Verification posture — inherited, not re-implemented
 *
 * Every event reaching this listener is already provider-verified. `WebhookService::ingest()`
 * calls `$gateway->verifyWebhookSignature($rawBody, $headers)` and returns 401 BEFORE parsing,
 * persisting, or dispatching anything; the `provider_events` row is only written with
 * `signature_valid = true`. So this class performs no signature work of its own and must not:
 * re-deriving trust here would be a second, weaker copy of payvia's.
 *
 * What it DOES verify is ownership: an event is only actionable if payvia itself holds a
 * `payment_intents` row for `(tenant, gateway, reference)` whose payable is a Commerce order.
 * An event naming a reference we never issued is not ours to act on.
 *
 * ## Reuses the authenticated route's machinery, does not duplicate it
 *
 * The confirmation is driven through the SAME
 * {@see ConfirmationDispatcher::dispatch()} call `confirmAndRecord()` makes, with the same
 * {@see PayableReference}/{@see PaymentConfirmation} pair, so the engine's
 * `OrderPaymentConfirmationHandler` fires exactly as it does for the authenticated route: the
 * order goes `paid` and `OrderPaymentService::markPaid()` eagerly consumes the active payment
 * link in the same transaction. The dispatcher's own T3 tail then settles the intent row for THIS
 * reference.
 *
 * What is deliberately NOT reused is `confirmAndRecord()` itself: it re-`verify()`s against the
 * gateway API on every call and re-enters `recordVerifyEvent()` → `processStored()` → `dispatch()`
 * — i.e. it would re-enter the very webhook machinery that is calling us.
 *
 * ## Idempotency under redelivery — layered, and none of it new
 *
 * This listener holds no state of its own. Replays are absorbed by CASes that already existed:
 *  1. `WebhookService`'s logical-dispatch lease/claim dedupes an ordinary redelivery outright;
 *  2. `PaymentIntentRepository::settle()` transitions only from open/superseded/failed — a row
 *     already `closed` returns `false`, never an error;
 *  3. `OrderRepository::transition()`'s `pending_payment -> paid` CAS refuses a second settle;
 *  4. `PaymentLinkRepository::consumeActiveForOrder()`'s `active -> consumed` CAS likewise.
 *
 * A webhook for a SUPERSEDED attempt is the interesting case and falls out of the same design:
 * `findByReference()` is keyed on `(tenant_uuid, gateway, reference)`, so it resolves THAT row —
 * not "whichever attempt is currently open" — and the dispatcher settles THAT row. The order-side
 * CAS is what refuses the money movement if the order is already `paid`, at which point the
 * engine's own `rejectLatePayment()` records the late payment instead. Amount/currency mismatches
 * take the identical path the authenticated route takes, because the same handler makes the
 * comparison against `commerce_orders.grand_total`.
 *
 * ## Tenancy
 *
 * The payvia webhook route is deliberately tenantless (signature-authenticated, no tenant
 * profile), while `PaymentIntentRepository` is tenant-scoped and payvia's `FailClosedTenantResolver`
 * THROWS on an unresolved tenant. So the lookup is attempted directly first — which is both the
 * single-store case (payvia's `SentinelTenantResolver`, `tenant_uuid = ''`) and the case where a
 * tenant context already happens to be established — and only falls back to a bounded
 * `forEachTenant()` sweep when there is no tenant context at all. Everything (lookup AND
 * confirmation) runs inside the SAME tenant frame, so the handler's own `CurrentTenantResolver`
 * sees the tenant the intent actually belongs to.
 */
final class WebhookOrderSettlementListener implements StrictPaymentEventListener
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly PaymentIntentRepository $intents,
        private readonly ConfirmationDispatcher $confirmations,
        private readonly ?TenantContextRunner $tenants = null,
    ) {
    }

    /**
     * A pure routing gate — no I/O, no side effects (the contract requires that of every
     * `supports()`, which is called for EVERY event of every type).
     *
     * Only `payment.succeeded` is in scope. `invoice.paid` is the subscription-billing shape and
     * is already owned by the subscriptions bridge; a Commerce order is a one-time charge.
     */
    public function supports(PaymentProviderEventInterface $event): bool
    {
        return $event->type() === EventType::PAYMENT_SUCCEEDED
            && self::stringField($event->normalized(), 'reference') !== '';
    }

    /**
     * The DEGRADED bus entry point, used only by the provider's stale-compiled-container
     * fallback (see {@see \Thallo\Commerce\CommerceIntegrationServiceProvider::boot()}). The bus
     * is fault-isolated, so a failure here is logged and swallowed rather than retried — which is
     * why it is a fallback and not the design. It settles the happy path, and the authenticated
     * `POST /payvia/payments/confirm` remains the manual recovery for anything it drops.
     */
    public function onProviderEvent(\Glueful\Extensions\Payvia\Events\PaymentProviderEvent $event): void
    {
        if ($this->supports($event->event)) {
            $this->handle($event->event);
        }
    }

    public function handle(PaymentProviderEventInterface $event): void
    {
        $gateway = $event->gateway();
        $normalized = $event->normalized();
        $reference = self::stringField($normalized, 'reference');
        if ($gateway === '' || $reference === '') {
            return;
        }

        // The provider's OWN statement of what it took. Passed through unchanged — exactly as
        // `confirmAndRecord()` passes its verification's amount/currency through — so a
        // mismatch reaches `OrderPaymentConfirmationHandler`'s comparison against the order's
        // grand_total and takes the identical refusal path (a `payment_amount_mismatch` order
        // event, no payment) rather than being silently reconciled here.
        //
        // An event that states no amount or no currency states nothing we may act on: we will
        // not substitute the intent's own figures, because that would assert a fact the provider
        // did not, and it would neuter the very guard above. It is ignored as quietly as an
        // unknown reference.
        if (!isset($normalized['amount']) || !is_numeric($normalized['amount'])) {
            return;
        }
        $currency = self::stringField($normalized, 'currency');
        if ($currency === '') {
            return;
        }
        $amount = (int) $normalized['amount'];

        $this->settle($gateway, $reference, $amount, $currency, $normalized);
    }

    /**
     * Resolve the intent and drive the confirmation INSIDE one tenant frame.
     *
     * Direct attempt first (single-store sentinel, or an already-established context); a
     * `TenantContextRequiredException` means the webhook arrived tenantless, so sweep tenants
     * until the reference is found. A reference no tenant owns is ignored — an unmatched
     * reference is the ordinary case for a store that shares a gateway account, not an error.
     *
     * @param array<string,mixed> $normalized
     */
    private function settle(
        string $gateway,
        string $reference,
        int $amount,
        string $currency,
        array $normalized
    ): void {
        try {
            $this->confirmWithin($gateway, $reference, $amount, $currency, $normalized);

            return;
        } catch (TenantContextRequiredException) {
            // Fall through to the sweep below.
        }

        $runner = $this->tenants;
        if ($runner === null) {
            return;
        }

        $done = false;
        $runner->forEachTenant(function () use (
            &$done,
            $gateway,
            $reference,
            $amount,
            $currency,
            $normalized
        ): void {
            if ($done) {
                return;
            }
            $done = $this->confirmWithin($gateway, $reference, $amount, $currency, $normalized);
        });
    }

    /**
     * @param array<string,mixed> $normalized
     * @return bool True when the reference belonged to this tenant and was dispatched.
     */
    private function confirmWithin(
        string $gateway,
        string $reference,
        int $amount,
        string $currency,
        array $normalized
    ): bool {
        $intent = $this->intents->findByReference($this->context, $gateway, $reference);
        if ($intent === null) {
            return false;
        }

        // Ownership proof. Payvia is shared with subscriptions (and anything else a host bolts
        // on); only an intent whose payable is a Commerce order is ours to settle.
        $payableType = self::stringField($intent, 'payable_type');
        $payableId = self::stringField($intent, 'payable_id');
        if ($payableType !== OrderPayable::TYPE || $payableId === '') {
            return true;
        }

        $this->confirmations->dispatch(
            $this->context,
            new PayableReference($payableType, $payableId, $amount, $currency),
            new PaymentConfirmation('paid', $reference, $amount, $currency, $normalized),
            $gateway
        );

        return true;
    }

    /** @param array<string,mixed> $data */
    private static function stringField(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        return is_scalar($value) ? trim((string) $value) : '';
    }
}
