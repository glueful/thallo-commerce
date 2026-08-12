<?php

declare(strict_types=1);

namespace Thallo\Commerce\Payments;

/**
 * The typed outcome of one {@see PaymentLinkDeliveryRepository::claim()} (payment-links spec
 * §2.4). Exactly three states, closed by construction:
 *
 *  - FRESH    — this call created the `processing` row. The caller owns the attempt and must go
 *               on to mint (regenerate mode) and send, then record the outcome.
 *  - REPLAY   — a row for this `(tenant, key)` already exists and its fingerprint MATCHES, so
 *               the caller must report that row's recorded outcome and send NOTHING. The row may
 *               be `sent`, `failed`, `processing` (another attempt is genuinely in flight), or
 *               `indeterminate` (a `processing` attempt that passed the stale threshold — see
 *               the repository).
 *  - CONFLICT — the key exists with a DIFFERENT fingerprint. The caller answers 409; nothing is
 *               written and nothing is sent.
 *
 * `row` is the ledger row for the first two states and null for CONFLICT: a conflicting caller
 * asked about a request that is not the one this key recorded, so it gets no view of that
 * request's outcome at all.
 */
final readonly class PaymentLinkDeliveryClaim
{
    public const FRESH = 'fresh';
    public const REPLAY = 'replay';
    public const CONFLICT = 'conflict';

    /** @param array<string,mixed>|null $row */
    private function __construct(
        public string $outcome,
        public ?array $row,
    ) {
    }

    /** @param array<string,mixed> $row */
    public static function fresh(array $row): self
    {
        return new self(self::FRESH, $row);
    }

    /** @param array<string,mixed> $row */
    public static function replay(array $row): self
    {
        return new self(self::REPLAY, $row);
    }

    public static function conflict(): self
    {
        return new self(self::CONFLICT, null);
    }

    public function isFresh(): bool
    {
        return $this->outcome === self::FRESH;
    }

    public function isReplay(): bool
    {
        return $this->outcome === self::REPLAY;
    }

    public function isConflict(): bool
    {
        return $this->outcome === self::CONFLICT;
    }
}
