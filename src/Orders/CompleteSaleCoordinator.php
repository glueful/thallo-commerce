<?php

declare(strict_types=1);

namespace Thallo\Commerce\Orders;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Orders\OrderFulfillmentService;
use Glueful\Extensions\Commerce\Orders\OrderPaymentService;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Psr\Log\LoggerInterface;

/**
 * Admin-order-creation cycle 2, Task 13 (design spec §2.8): the server-side orchestration behind
 * `POST /v1/admin/commerce/orders/{uuid}/complete-sale` — the one-click finish for a finalized
 * WALK-IN (`fulfillment_mode = 'in_store'`) order that is still `pending_payment`.
 *
 * It chains the engine's two existing operations, in order, and **owns no transition logic of its
 * own**:
 *
 *   1. {@see OrderPaymentService::markPaid()} — its own transaction, its own
 *      `pending_payment -> paid` compare-and-set, its own `status:paid` audit row, and its own
 *      after-commit `OrderPaid` dispatch.
 *   2. {@see OrderFulfillmentService::fulfill()} — its own transaction, its own `paid -> fulfilled`
 *      compare-and-set, its own `status:fulfilled` audit row, and its own exactly-once
 *      `OrderFulfilled` dispatch.
 *
 * Neither call is wrapped in an outer transaction and neither is ever retried: the two steps are
 * deliberately INDEPENDENTLY durable, so a failure between them leaves a truthfully `paid`,
 * unfulfilled order that the ORDINARY guarded Fulfill action recovers (spec §2.8). Complete sale
 * itself is never blindly re-driven — a second call on a `paid` order is refused by this class's
 * own pre-gate below.
 *
 * REPORTING RULES (spec §2.8's five outcomes), all of which this class implements by RELOADING
 * the order after any failure rather than by trusting what it attempted:
 *
 *   1. `markPaid()` raises `\DomainException` (state-machine rejection or a lost CAS) ⇒ 409,
 *      `mark_paid: failed`, `fulfill: skipped`, refreshed order.
 *   2. `markPaid()` raises anything else ⇒ logged, 500, `fulfill: skipped`, and **never** any
 *      attempt at fulfillment. The reload decides the truth about step 1: still `pending_payment`
 *      ⇒ `failed` (the transaction rolled back with it); already `paid` ⇒ truthfully `done` (the
 *      payment committed and something after it threw).
 *   3. `fulfill()` raises `\DomainException` (state-machine rejection / lost CAS) OR
 *      `NotFoundException` (the order stopped resolving between the two steps — deleted,
 *      re-tenanted, or reverted to a draft) ⇒ 409, `mark_paid: done`, `fulfill: failed`,
 *      refreshed order. Both are concurrency outcomes this endpoint exists to classify, so
 *      neither may masquerade as a server fault. When the order is genuinely gone the refreshed
 *      `order` is `null` — the response still carries the full step shape.
 *   4. `fulfill()` raises anything else ⇒ logged, 500, same step shape.
 *   5. both succeed ⇒ 200, both `done`.
 *
 * SANITIZATION: no exception message, class name, or driver detail ever reaches the returned
 * structure. Every `error` string is one of this class's own constants; the real throwable goes to
 * the log with the tenant/order/step context, and nowhere else.
 *
 * PROJECTION: this class returns RAW `commerce_orders` rows. Turning one into a wire payload is
 * {@see \Thallo\Commerce\Http\AdminCompleteSaleController::respond()}'s single responsibility, and
 * it does so through the engine's closed `OrderProjection::forAdmin()` — success and every
 * refreshed-error shape alike.
 */
final class CompleteSaleCoordinator
{
    public const STEP_MARK_PAID = 'mark_paid';
    public const STEP_FULFILL = 'fulfill';

    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    /** The ONLY starting state this endpoint accepts. */
    public const REQUIRED_STATUS = 'pending_payment';
    /** The ONLY fulfillment mode this endpoint accepts — a delivery order is not a counter sale. */
    public const REQUIRED_FULFILLMENT_MODE = 'in_store';
    /** The status a committed payment leaves behind — how step 1's truth is re-derived. */
    private const PAID_STATUS = 'paid';

    public const MESSAGE_COMPLETED = 'Sale completed';
    public const MESSAGE_NOT_PENDING_PAYMENT = 'Order is not awaiting payment.';
    public const MESSAGE_NOT_IN_STORE = 'Order is not an in-store sale.';
    public const MESSAGE_CONFLICT = 'The order changed before the sale could be completed.';
    public const MESSAGE_FAILED = 'The sale could not be completed.';

    /** The only two `error` strings that ever reach the wire. */
    public const ERROR_CONFLICT = 'The order changed before this step could complete.';
    public const ERROR_UNEXPECTED = 'An unexpected error prevented this step from completing.';

    /** @var callable():void */
    private $afterMarkPaidProbe;

    /**
     * @param (callable():void)|null $afterMarkPaidProbe
     *     Invoked once `markPaid()` has returned — i.e. once its transaction has COMMITTED —
     *     from inside step 1's own `try`, so a throw is handled exactly as a throw from
     *     `markPaid()` itself would be.
     *
     *     This is the class's ONE test-only seam, the same convention the engine uses for
     *     {@see OrderPaymentService}'s own `$afterPaidHook` and `CheckoutService`'s
     *     `$afterOwnershipSnapshotHook`. It exists because exactly one of spec §2.8's outcomes is
     *     otherwise unreachable — "the payment transaction committed before an after-commit
     *     callback threw" — since the framework's `TransactionManager` deliberately SWALLOWS
     *     after-commit callback failures and `EventService::dispatch()` is fault-isolated, so no
     *     listener can make a committed `markPaid()` throw. Every OTHER failure outcome is reached
     *     through a genuine engine seam and needs no probe. Production never passes it — the
     *     container factory constructs this class without it, and it defaults to a no-op.
     */
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly OrderRepository $orders,
        private readonly OrderPaymentService $payments,
        private readonly OrderFulfillmentService $fulfillment,
        private readonly ?LoggerInterface $logger = null,
        ?callable $afterMarkPaidProbe = null,
    ) {
        $this->afterMarkPaidProbe = $afterMarkPaidProbe ?? static function (): void {
        };
    }

    /**
     * @param array<string,mixed> $order the ALREADY-RESOLVED, tenant-scoped, draft-blind raw row
     *     (the caller's own resolution is what makes an unknown/cross-tenant/draft uuid a
     *     non-revealing 404 before this class is ever consulted)
     * @return array{
     *     status: int,
     *     message: string,
     *     steps: list<array{step:string,status:string,error?:string}>,
     *     order: array<string,mixed>|null
     * } `order` is a RAW row; the controller projects it.
     */
    public function complete(string $tenant, array $order, ?string $trackingRef): array
    {
        $uuid = (string) $order['uuid'];

        // Pre-gates: wrong state or a delivery order is a 409 with ZERO transitions attempted.
        // Both steps report `skipped` so the response shape never varies across outcomes.
        if ((string) ($order['status'] ?? '') !== self::REQUIRED_STATUS) {
            return $this->refused($tenant, $uuid, self::MESSAGE_NOT_PENDING_PAYMENT);
        }
        if ((string) ($order['fulfillment_mode'] ?? '') !== self::REQUIRED_FULFILLMENT_MODE) {
            return $this->refused($tenant, $uuid, self::MESSAGE_NOT_IN_STORE);
        }

        try {
            $this->payments->markPaid($this->context, $tenant, $uuid);
            ($this->afterMarkPaidProbe)();
        } catch (\DomainException $e) {
            $this->log($e, $tenant, $uuid, self::STEP_MARK_PAID, 'conflict');

            return [
                'status' => 409,
                'message' => self::MESSAGE_CONFLICT,
                'steps' => [
                    $this->step(self::STEP_MARK_PAID, self::STATUS_FAILED, self::ERROR_CONFLICT),
                    $this->step(self::STEP_FULFILL, self::STATUS_SKIPPED),
                ],
                'order' => $this->reload($tenant, $uuid),
            ];
        } catch (\Throwable $e) {
            $this->log($e, $tenant, $uuid, self::STEP_MARK_PAID, 'unexpected');

            // NEVER continue to fulfillment after an unexpected throw. The reload — not the
            // attempt — decides what step 1 truthfully did: a rolled-back transaction leaves the
            // order `pending_payment` (`failed`); a payment that committed before something after
            // it threw leaves it `paid`, and saying otherwise would misreport a real transition.
            $reloaded = $this->reload($tenant, $uuid);
            $committed = $reloaded !== null && (string) ($reloaded['status'] ?? '') === self::PAID_STATUS;

            return [
                'status' => 500,
                'message' => self::MESSAGE_FAILED,
                'steps' => [
                    $committed
                        ? $this->step(self::STEP_MARK_PAID, self::STATUS_DONE)
                        : $this->step(self::STEP_MARK_PAID, self::STATUS_FAILED, self::ERROR_UNEXPECTED),
                    $this->step(self::STEP_FULFILL, self::STATUS_SKIPPED),
                ],
                'order' => $reloaded,
            ];
        }

        try {
            $fulfilled = $this->fulfillment->fulfill($this->context, $tenant, $uuid, $trackingRef);
        } catch (\DomainException | NotFoundException $e) {
            // BOTH are concurrency verdicts, not server faults: `\DomainException` is the engine's
            // state-machine/CAS rejection, and `NotFoundException` is its tenant-safe precheck
            // reporting that the order stopped resolving between the two steps. Matched on TYPE —
            // never on message text. Residual gap, deliberately left uncaught: if an order vanishes
            // in the sliver BETWEEN that precheck and `transition()`'s own re-read (inside one
            // transaction), the engine raises a bare `\RuntimeException('Order not found.')` whose
            // only distinguishing feature is its message. Sniffing that string would bind this pack
            // to an unstable detail, so that case stays a logged 500 until the engine gives it a
            // type.
            $this->log($e, $tenant, $uuid, self::STEP_FULFILL, 'conflict');

            return [
                'status' => 409,
                'message' => self::MESSAGE_CONFLICT,
                'steps' => [
                    $this->step(self::STEP_MARK_PAID, self::STATUS_DONE),
                    $this->step(self::STEP_FULFILL, self::STATUS_FAILED, self::ERROR_CONFLICT),
                ],
                'order' => $this->reload($tenant, $uuid),
            ];
        } catch (\Throwable $e) {
            $this->log($e, $tenant, $uuid, self::STEP_FULFILL, 'unexpected');

            return [
                'status' => 500,
                'message' => self::MESSAGE_FAILED,
                'steps' => [
                    $this->step(self::STEP_MARK_PAID, self::STATUS_DONE),
                    $this->step(self::STEP_FULFILL, self::STATUS_FAILED, self::ERROR_UNEXPECTED),
                ],
                'order' => $this->reload($tenant, $uuid),
            ];
        }

        return [
            'status' => 200,
            'message' => self::MESSAGE_COMPLETED,
            'steps' => [
                $this->step(self::STEP_MARK_PAID, self::STATUS_DONE),
                $this->step(self::STEP_FULFILL, self::STATUS_DONE),
            ],
            'order' => $fulfilled,
        ];
    }

    /**
     * A pre-gate refusal: 409, both steps `skipped`, and the CURRENT order so the caller can
     * re-render without a second round trip. Re-read rather than echoed, since the operator's own
     * view is by definition already stale if they got here.
     *
     * @return array{
     *     status: int,
     *     message: string,
     *     steps: list<array{step:string,status:string,error?:string}>,
     *     order: array<string,mixed>|null
     * }
     */
    private function refused(string $tenant, string $uuid, string $message): array
    {
        return [
            'status' => 409,
            'message' => $message,
            'steps' => [
                $this->step(self::STEP_MARK_PAID, self::STATUS_SKIPPED),
                $this->step(self::STEP_FULFILL, self::STATUS_SKIPPED),
            ],
            'order' => $this->reload($tenant, $uuid),
        ];
    }

    /** @return array{step:string,status:string,error?:string} */
    private function step(string $step, string $status, ?string $error = null): array
    {
        $entry = ['step' => $step, 'status' => $status];
        if ($error !== null) {
            $entry['error'] = $error;
        }

        return $entry;
    }

    /**
     * The post-attempt truth. Same tenant-scoped, draft-blind lookup the caller resolved with, so
     * an order that became unreachable mid-flight yields `null` rather than a stale echo.
     *
     * @return array<string,mixed>|null
     */
    private function reload(string $tenant, string $uuid): ?array
    {
        return $this->orders->findByUuid($this->context, $tenant, $uuid);
    }

    /**
     * The ONLY place a throwable's own words are recorded. Server-side, with the identifying
     * context an operator report needs; never any part of the response.
     */
    private function log(\Throwable $e, string $tenant, string $uuid, string $step, string $kind): void
    {
        $message = 'complete-sale ' . $step . ' ' . $kind;
        $context = [
            'tenant_uuid' => $tenant,
            'order_uuid' => $uuid,
            'step' => $step,
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ];

        if ($kind === 'conflict') {
            $this->logger?->warning($message, $context);

            return;
        }

        $this->logger?->error($message, $context);
    }
}
