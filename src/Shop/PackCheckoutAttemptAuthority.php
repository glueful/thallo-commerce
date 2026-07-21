<?php

declare(strict_types=1);

namespace Thallo\Commerce\Shop;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Encryption\EncryptionService;
use Glueful\Extensions\Commerce\Marketplace\CheckoutConflictException;
use Glueful\Extensions\Commerce\Orders\CheckoutAttemptAuthority;
use Glueful\Extensions\Commerce\Orders\CheckoutAttemptContext;
use Glueful\Extensions\Commerce\Orders\CheckoutAttemptReplay;

/**
 * The pack's durable checkout-attempt idempotency authority (storefront-rendering spec §7,
 * verbatim). Bound to Commerce's {@see CheckoutAttemptAuthority} seam —
 * {@see \Glueful\Extensions\Commerce\Orders\CheckoutService} soft-resolves and invokes BOTH
 * methods here INSIDE its own placement transaction: `claimOrReplay()`
 * runs first, before cart validation; `complete()` runs immediately after the order this attempt
 * placed has been inserted. Neither method opens its own transaction — a throw from either rolls
 * back everything the placement attempt has done so far, including whatever this class itself
 * already wrote, and the pending row this class inserts can never commit separately from the
 * order it completes (one shared commit, no crash window between them).
 *
 * `claimOrReplay()` takes a xact-scoped `pg_advisory_xact_lock(hashtextextended(?, 0))` on
 * `thallo_commerce_attempt:{tenant}:{key}` (mirrors {@see PackSlugLifecycleAuthority}'s and
 * {@see \Thallo\Commerce\Links\ProductLinkRepository}'s identical lock idiom) BEFORE re-reading
 * `thallo_commerce_checkout_attempts` — this is what makes two simultaneous first uses of the
 * SAME key serialize into one completed attempt/order and one replay rather than two orders: by
 * the time the second racer acquires the lock, the first racer's transaction has already
 * committed (or rolled back) and its row (or absence of one) is visible. The lock releases
 * automatically at COMMIT/ROLLBACK — this class never explicitly unlocks.
 *
 * Exactly three outcomes are possible once the lock is held and the row re-read (design spec §7):
 *  - absent -> insert a fresh `pending` row, return null (a brand-new attempt).
 *  - `completed`, same `request_fingerprint` -> return a typed {@see CheckoutAttemptReplay} with
 *    the ORIGINAL order identity and the SAME guest credential (decrypted), never a fresh one.
 *  - any row (pending OR completed) with a DIFFERENT `request_fingerprint` -> throw a 409-shaped
 *    {@see CheckoutConflictException}; the whole placement transaction rolls back.
 * A `pending` row with a MATCHING fingerprint is structurally unreachable: `claimOrReplay()` and
 * `complete()` only ever run inside the SAME transaction, so a `pending` row is only ever visible
 * to another transaction while the lock that guards it is held (never after commit) — observing
 * one here would indicate this authority was invoked outside Commerce's placement transaction, a
 * caller bug this class fails loudly on rather than silently guessing at a replay.
 */
final class PackCheckoutAttemptAuthority implements CheckoutAttemptAuthority
{
    private const TABLE = 'thallo_commerce_checkout_attempts';
    private const LOCK_SQL = 'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))';

    public function __construct(
        private readonly Connection $connection,
        private readonly EncryptionService $encryption,
    ) {
    }

    /** The stable advisory-lock identity string for one tenant+idempotency-key claim. */
    public static function lockKey(string $tenant, string $key): string
    {
        return "thallo_commerce_attempt:{$tenant}:{$key}";
    }

    /** The AAD binding for one tenant+idempotency-key guest-credential ciphertext. */
    public static function credentialAad(string $tenant, string $key): string
    {
        return "checkout.attempt:{$tenant}:{$key}";
    }

    public function claimOrReplay(
        ApplicationContext $c,
        string $tenant,
        CheckoutAttemptContext $ctx
    ): ?CheckoutAttemptReplay {
        $this->lock($tenant, $ctx->idempotencyKey);

        $row = $this->connection->table(self::TABLE)
            ->where('tenant_uuid', '=', $tenant)
            ->where('idempotency_key', '=', $ctx->idempotencyKey)
            ->first();

        if ($row === null) {
            $now = gmdate('Y-m-d H:i:s');
            $this->connection->table(self::TABLE)->insert([
                'tenant_uuid' => $tenant,
                'idempotency_key' => $ctx->idempotencyKey,
                'request_fingerprint' => $ctx->requestFingerprint,
                'status' => 'pending',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return null;
        }

        if ((string) $row['request_fingerprint'] !== $ctx->requestFingerprint) {
            throw new CheckoutConflictException(
                'Checkout conflict: idempotency key reused with a different request.'
            );
        }

        if ((string) $row['status'] !== 'completed') {
            // Structurally unreachable in production (see class docblock) — fail loudly rather
            // than guess at a replay for a row that was never actually completed.
            throw new \RuntimeException(
                "Checkout attempt row for '{$ctx->idempotencyKey}' was observed in an unexpected "
                . "'{$row['status']}' state outside its owning transaction."
            );
        }

        $ciphertext = $row['guest_credential_ciphertext'] ?? null;
        if (!is_string($ciphertext) || $ciphertext === '') {
            throw new \RuntimeException(
                "Completed checkout attempt row for '{$ctx->idempotencyKey}' has no stored credential."
            );
        }
        $credential = $this->encryption->decrypt(
            $ciphertext,
            aad: self::credentialAad($tenant, $ctx->idempotencyKey)
        );

        return new CheckoutAttemptReplay((string) $row['order_uuid'], (string) $row['order_ref'], $credential);
    }

    public function complete(
        ApplicationContext $c,
        string $tenant,
        CheckoutAttemptContext $ctx,
        string $orderUuid,
        string $orderRef,
        string $rawGuestToken
    ): void {
        $ciphertext = $this->encryption->encrypt(
            $rawGuestToken,
            aad: self::credentialAad($tenant, $ctx->idempotencyKey)
        );

        $this->connection->table(self::TABLE)
            ->where('tenant_uuid', '=', $tenant)
            ->where('idempotency_key', '=', $ctx->idempotencyKey)
            ->update([
                'status' => 'completed',
                'order_uuid' => $orderUuid,
                'order_ref' => $orderRef,
                'guest_credential_ciphertext' => $ciphertext,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);
    }

    private function lock(string $tenant, string $key): void
    {
        $statement = $this->connection->getPDO()->prepare(self::LOCK_SQL);
        $statement->execute([self::lockKey($tenant, $key)]);
    }
}
