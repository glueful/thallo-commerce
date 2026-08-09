<?php

declare(strict_types=1);

namespace Thallo\Commerce\Payments;

use Glueful\Database\Connection;
use Glueful\Extensions\Commerce\Payments\OrderPayable;

/**
 * Orders-invoices-receipts plan, Task 5: the sole owner of Payvia's two physical payment tables
 * (`payments`/`payment_intents`) for the admin order payment summary (`GET /orders/{uuid}/
 * payments`, {@see \Thallo\Commerce\Http\AdminOrderPaymentsController}). Constructor-injected
 * with the bare {@see Connection} only — never a Payvia service/provider binding — because
 * whether `glueful/payvia`'s OWN ServiceProvider happens to be booted in this process is
 * irrelevant to whether its tables physically exist in THIS database (mirrors
 * {@see \Thallo\Subscriptions\Checkout\PayviaCheckoutGateway}'s own `hasTable()` discipline, one
 * layer down: that class asks "is the extension active AND migrated", this one only ever asks
 * "are the tables there").
 *
 * `available()` is the ONLY readiness check, and it is deliberately narrow: `hasTable()` on both
 * tables, nothing else. A table missing means "never migrated" (fresh install, or an install that
 * never pulled Payvia in) -> `available():false`, empty arrays, never a fatal query. Tables
 * present means `available():true` UNCONDITIONALLY -- regardless of whether any gateway is
 * actually configured/enabled, because historical payment/intent rows remain readable even after
 * a gateway is deactivated. Anything else that goes wrong querying an available table (a lost
 * connection, a permissions error, a genuinely corrupt row) is NOT caught here: it propagates as
 * an uncaught exception, which the framework's own exception handler renders as a 500. There is
 * no catch-all in this class -- "tables absent" is the only failure mode this repository ever
 * treats as an expected, non-exceptional outcome.
 *
 * Both projections are closed allowlists (never `SELECT *` onto the wire): `raw_payload`,
 * `metadata`, and `message` are real, gateway-populated columns on `payments` that can carry
 * secrets or PII a gateway chose to echo back, and neither is ever named in
 * {@see self::PAYMENT_FIELDS} -- `array_intersect_key()` against the raw row drops them (and any
 * future column added to the table) unless a maintainer consciously adds it here.
 */
final class OrderPaymentSummaryRepository
{
    private const PAYMENTS_TABLE = 'payments';
    private const INTENTS_TABLE = 'payment_intents';

    /** @var list<string> */
    private const PAYMENT_FIELDS = [
        'gateway',
        'status',
        'reference',
        'gateway_transaction_id',
        'amount',
        'currency',
        'created_at',
        'updated_at',
    ];

    /** @var list<string> */
    private const INTENT_FIELDS = [
        'gateway',
        'status',
        'reference',
        'amount',
        'currency',
        'created_at',
    ];

    public function __construct(private readonly Connection $connection)
    {
    }

    /** Both Payvia tables physically present -- the ONLY thing this repository treats as "ready". */
    public function available(): bool
    {
        $schema = $this->connection->getSchemaBuilder();

        return $schema->hasTable(self::PAYMENTS_TABLE) && $schema->hasTable(self::INTENTS_TABLE);
    }

    /**
     * Every `payments` row for this order, newest first (`created_at DESC, id DESC` -- the `id`
     * tie-break makes rows sharing a truncated/equal `created_at` timestamp deterministic), closed
     * to {@see self::PAYMENT_FIELDS}. Empty (not an error) when {@see self::available()} is false.
     *
     * @return list<array<string,mixed>>
     */
    public function paymentsFor(string $tenant, string $orderUuid): array
    {
        if (!$this->available()) {
            return [];
        }

        $rows = $this->connection->table(self::PAYMENTS_TABLE)
            ->where('tenant_uuid', '=', $tenant)
            ->where('payable_type', '=', OrderPayable::TYPE)
            ->where('payable_id', '=', $orderUuid)
            ->orderBy('created_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->get();

        return array_map(
            static fn (array $row): array => array_intersect_key($row, array_flip(self::PAYMENT_FIELDS)),
            $rows,
        );
    }

    /**
     * Every `payment_intents` row for this order (BOTH `open` and `closed` statuses -- this is a
     * full history read, not an "in-flight only" one), newest first with the same `created_at
     * DESC, id DESC` tie-break as {@see self::paymentsFor()}, closed to {@see self::INTENT_FIELDS}.
     * Empty (not an error) when {@see self::available()} is false.
     *
     * @return list<array<string,mixed>>
     */
    public function intentsFor(string $tenant, string $orderUuid): array
    {
        if (!$this->available()) {
            return [];
        }

        $rows = $this->connection->table(self::INTENTS_TABLE)
            ->where('tenant_uuid', '=', $tenant)
            ->where('payable_type', '=', OrderPayable::TYPE)
            ->where('payable_id', '=', $orderUuid)
            ->orderBy('created_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->get();

        return array_map(
            static fn (array $row): array => array_intersect_key($row, array_flip(self::INTENT_FIELDS)),
            $rows,
        );
    }
}
