<?php

declare(strict_types=1);

namespace Thallo\Commerce\Payments;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Helpers\Utils;

use function config;

/**
 * The delivery-idempotency ledger for payment-link sends (payment-links spec §2.4), backing
 * `thallo_commerce_payment_link_deliveries` and the pack's one send route.
 *
 * ## Claim-by-unique-index, deliberately lock-free
 *
 * {@see self::claim()} is an INSERT that either wins the table's
 * `unique(tenant_uuid, idempotency_key)` or loses to it and re-reads the winner's row. It takes
 * no advisory lock and opens no transaction, and BOTH are requirements rather than omissions:
 *
 *  - the claim is followed, in the same request, by a mint (its own transaction, with its own
 *    order-then-link lock order) and by a synchronous SMTP call. Holding a transaction — and
 *    therefore a `pg_advisory_xact_lock`, which is xact-scoped — across either would put a
 *    network round trip inside a database lock;
 *  - the sibling authorities in this pack ({@see \Thallo\Commerce\Shop\PackCheckoutAttemptAuthority},
 *    {@see \Thallo\Commerce\Shop\PackSlugLifecycleAuthority}) lock because they run INSIDE a
 *    caller's transaction and must arbitrate against reads made in it. Nothing here does, so the
 *    unique index alone is sufficient — and it is portable, which the raw `pg_advisory_xact_lock`
 *    idiom is not (this ledger's migration is exercised on SQLite as well as PostgreSQL).
 *
 * The consequence is that a concurrent first use of one key produces exactly ONE `processing`
 * row and one replay, never two sends.
 *
 * ## Custody
 *
 * Nothing here ever receives a raw token or a composed URL — not as a parameter, not as a column,
 * not in the fingerprint. {@see self::fingerprint()} covers the ORDER, the MODE, the RECIPIENT
 * HASH and the TTL, which is precisely the set of facts that make two requests "the same
 * request" for idempotency purposes; a token is a per-attempt secret and including it would both
 * leak it into a durable row and make every legitimate replay look like a conflict.
 *
 * ## `processing` -> `indeterminate`
 *
 * A `processing` row means a previous attempt claimed the key and never recorded an outcome. The
 * honest reading changes with age: inside
 * `thallo-commerce.payment_links.delivery_processing_stale_seconds` (default 300, clamped
 * 60..3600 by {@see self::staleSeconds()}) another attempt may genuinely still be in flight; at
 * or past it, that attempt is gone and whether its email went out is UNKNOWABLE — the plaintext
 * URL it may have sent is not recoverable from anywhere, by design. {@see self::claim()}
 * therefore transitions the row to `indeterminate`, PERSISTENTLY (a compare-and-set on
 * `status = processing`, so it can never overwrite an outcome a slow attempt recorded in the
 * meantime), and the caller reports that state and instructs the operator to use a new key or
 * regenerate. It never silently re-sends and never silently re-mints.
 */
final class PaymentLinkDeliveryRepository
{
    public const TABLE = 'thallo_commerce_payment_link_deliveries';

    public const MODE_CURRENT = 'current';
    public const MODE_REGENERATE = 'regenerate';
    /** @var list<string> */
    public const MODES = [self::MODE_CURRENT, self::MODE_REGENERATE];

    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_INDETERMINATE = 'indeterminate';

    /** The stale-window default and its clamp bounds (spec §2.4). */
    public const STALE_DEFAULT = 300;
    public const STALE_MIN = 60;
    public const STALE_MAX = 3600;

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * The configured stale window, clamped into 60..3600. A non-numeric or out-of-range value
     * degrades to the nearest bound rather than throwing: this value only decides how long a
     * crashed attempt is reported as "still going", and a deployment typo must not be able to
     * take the send endpoint down.
     */
    public static function staleSeconds(ApplicationContext $context): int
    {
        $configured = config($context, 'thallo-commerce.payment_links.delivery_processing_stale_seconds');
        $seconds = is_numeric($configured) ? (int) $configured : self::STALE_DEFAULT;

        return max(self::STALE_MIN, min(self::STALE_MAX, $seconds));
    }

    /** sha256 of the lowercased, trimmed address — the ONLY form of a recipient this ledger holds. */
    public static function recipientHash(string $email): string
    {
        return hash('sha256', strtolower(trim($email)));
    }

    /**
     * The canonicalized request fingerprint. Deliberately token-free (see the class docblock);
     * the separator is a character no component can contain, so no two distinct tuples can
     * collide by concatenation.
     */
    public static function fingerprint(string $orderUuid, string $mode, string $recipientHash, ?int $ttlDays): string
    {
        return hash('sha256', implode("\0", [
            $orderUuid,
            $mode,
            $recipientHash,
            $ttlDays === null ? 'default' : (string) $ttlDays,
        ]));
    }

    /**
     * Claim `(tenant, idempotencyKey)` for this request, or report what the key already recorded.
     *
     * @param int $staleSeconds already clamped by {@see self::staleSeconds()}
     */
    public function claim(
        string $tenant,
        string $idempotencyKey,
        string $fingerprint,
        string $orderUuid,
        string $recipientHash,
        string $mode,
        int $staleSeconds,
        \DateTimeImmutable $now,
    ): PaymentLinkDeliveryClaim {
        $existing = $this->find($tenant, $idempotencyKey);
        if ($existing === null) {
            $inserted = $this->insertClaim(
                $tenant,
                $idempotencyKey,
                $fingerprint,
                $orderUuid,
                $recipientHash,
                $mode,
                $now,
            );
            if ($inserted !== null) {
                return PaymentLinkDeliveryClaim::fresh($inserted);
            }
            // Lost the unique index to a concurrent first use — the winner's row is now visible.
            $existing = $this->find($tenant, $idempotencyKey);
            if ($existing === null) {
                throw new \RuntimeException(
                    'Payment-link delivery claim failed to insert and the conflicting row is not readable.'
                );
            }
        }

        if (!hash_equals((string) $existing['fingerprint'], $fingerprint)) {
            return PaymentLinkDeliveryClaim::conflict();
        }

        return PaymentLinkDeliveryClaim::replay($this->applyStaleness($existing, $staleSeconds, $now));
    }

    /** Record the minted link on an in-flight claim (regenerate mode, after `mintPublic()`). */
    public function attachLink(string $uuid, string $linkUuid, \DateTimeImmutable $now): void
    {
        $this->connection->table(self::TABLE)
            ->where('uuid', '=', $uuid)
            ->update(['link_uuid' => $linkUuid, 'updated_at' => self::stamp($now)]);
    }

    /**
     * Close a claim as delivered. Compare-and-set on `processing`, so a claim this process no
     * longer owns (one another attempt already transitioned to `indeterminate`) is never
     * resurrected into a `sent` that would contradict what the operator was told.
     *
     * @return array<string,mixed> the row as it now stands
     */
    public function markSent(string $uuid, ?string $providerMessageId, \DateTimeImmutable $now): array
    {
        return $this->close($uuid, self::STATUS_SENT, null, $providerMessageId, $now);
    }

    /**
     * Close a claim as failed, carrying a CLOSED, safe code (never transport exception text).
     *
     * @return array<string,mixed> the row as it now stands
     */
    public function markFailed(string $uuid, string $errorCode, \DateTimeImmutable $now): array
    {
        return $this->close($uuid, self::STATUS_FAILED, $errorCode, null, $now);
    }

    /** @return array<string,mixed>|null */
    public function find(string $tenant, string $idempotencyKey): ?array
    {
        $row = $this->connection->table(self::TABLE)
            ->where('tenant_uuid', '=', $tenant)
            ->where('idempotency_key', '=', $idempotencyKey)
            ->first();

        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    public function findByUuid(string $uuid): ?array
    {
        $row = $this->connection->table(self::TABLE)->where('uuid', '=', $uuid)->first();

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string,mixed>|null the inserted row, or null when the unique index refused it
     */
    private function insertClaim(
        string $tenant,
        string $idempotencyKey,
        string $fingerprint,
        string $orderUuid,
        string $recipientHash,
        string $mode,
        \DateTimeImmutable $now,
    ): ?array {
        $uuid = Utils::generateNanoID();
        $stamp = self::stamp($now);

        try {
            $this->connection->table(self::TABLE)->insert([
                'uuid' => $uuid,
                'tenant_uuid' => $tenant,
                'idempotency_key' => $idempotencyKey,
                'fingerprint' => $fingerprint,
                'order_uuid' => $orderUuid,
                'link_uuid' => null,
                'recipient_hash' => $recipientHash,
                'mode' => $mode,
                'status' => self::STATUS_PROCESSING,
                'error_code' => null,
                'provider_message_id' => null,
                'created_at' => $stamp,
                'updated_at' => $stamp,
            ]);
        } catch (\Throwable) {
            // The ONLY expected failure here is the tenant-scoped unique index: a concurrent
            // first use of the same key. Anything else re-surfaces from the caller's re-read
            // below (which throws when there is genuinely no row), so no driver error text —
            // which can quote bound values — is ever inspected, logged, or rethrown.
            return null;
        }

        return $this->findByUuid($uuid);
    }

    /**
     * The deterministic-clock staleness rule: a `processing` row at or past `$staleSeconds`
     * becomes `indeterminate`, persistently. Anything already terminal is returned untouched.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function applyStaleness(array $row, int $staleSeconds, \DateTimeImmutable $now): array
    {
        if ((string) $row['status'] !== self::STATUS_PROCESSING) {
            return $row;
        }

        $claimedAt = self::parseStamp((string) ($row['created_at'] ?? ''));
        if ($claimedAt === null || ($now->getTimestamp() - $claimedAt->getTimestamp()) < $staleSeconds) {
            return $row;
        }

        $this->connection->table(self::TABLE)
            ->where('uuid', '=', (string) $row['uuid'])
            ->where('status', '=', self::STATUS_PROCESSING)
            ->update(['status' => self::STATUS_INDETERMINATE, 'updated_at' => self::stamp($now)]);

        return $this->findByUuid((string) $row['uuid']) ?? $row;
    }

    /** @return array<string,mixed> */
    private function close(
        string $uuid,
        string $status,
        ?string $errorCode,
        ?string $providerMessageId,
        \DateTimeImmutable $now,
    ): array {
        $this->connection->table(self::TABLE)
            ->where('uuid', '=', $uuid)
            ->where('status', '=', self::STATUS_PROCESSING)
            ->update([
                'status' => $status,
                'error_code' => $errorCode,
                'provider_message_id' => $providerMessageId,
                'updated_at' => self::stamp($now),
            ]);

        $row = $this->findByUuid($uuid);
        if ($row === null) {
            throw new \RuntimeException("Payment-link delivery row '{$uuid}' vanished while being closed.");
        }

        return $row;
    }

    private static function stamp(\DateTimeImmutable $now): string
    {
        return $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private static function parseStamp(string $value): ?\DateTimeImmutable
    {
        if (trim($value) === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return null;
        }
    }
}
