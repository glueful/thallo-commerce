<?php

declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * Commerce-Slice-2 Task 10 (storefront-rendering spec §7): the durable checkout-attempt
 * idempotency ledger backing {@see \Thallo\Commerce\Shop\PackCheckoutAttemptAuthority} —
 * Commerce's `CheckoutAttemptAuthority` seam. Every claim takes a xact-scoped
 * `pg_advisory_xact_lock(hashtextextended(?, 0))` on `thallo_commerce_attempt:{tenant}:{key}`
 * (the SAME PostgreSQL advisory-lock idiom {@see \Thallo\Commerce\Shop\PackSlugLifecycleAuthority}
 * and {@see \Thallo\Commerce\Links\ProductLinkRepository} already establish in this pack) before
 * re-reading this table, so the unique `(tenant_uuid, idempotency_key)` index below is the ground
 * truth for key collisions while the lock is what makes two simultaneous first-uses of one key
 * serialize into ONE completed attempt/order and ONE replay instead of two orders.
 *
 * `guest_credential_ciphertext` holds the ORDER's raw guest token, encrypted at rest
 * ({@see \Glueful\Encryption\EncryptionService}, AAD `checkout.attempt:{tenant}:{key}`) — a
 * replay of a completed attempt decrypts and re-delivers this SAME credential rather than
 * minting a fresh one (design spec §7).
 */
final class CreateCheckoutAttempts implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('thallo_commerce_checkout_attempts')) {
            return;
        }
        $schema->createTable('thallo_commerce_checkout_attempts', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('tenant_uuid', 12)->default('');
            $table->string('idempotency_key', 191);
            // sha256 hex of the canonicalized checkout payload — always exactly 64 characters.
            $table->string('request_fingerprint', 64);
            $table->string('status', 16)->default('pending');
            // Commerce order uuid/number — no DB FK into Commerce's tables (cross-package
            // boundary; same convention as thallo_commerce_product_links/_slugs).
            $table->string('order_uuid', 12)->nullable();
            $table->string('order_ref', 191)->nullable();
            $table->text('guest_credential_ciphertext')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['tenant_uuid', 'idempotency_key'], 'uniq_commerce_checkout_attempt_tenant_key');
        });
        $schema->alterTable('thallo_commerce_checkout_attempts', static function ($table): void {
            // Retention-sweep lookup (thallo:commerce:checkout:purge-attempts): every row older
            // than the configured window, regardless of tenant/status.
            $table->index(['created_at'], 'idx_commerce_checkout_attempt_created_at');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('thallo_commerce_checkout_attempts');
    }

    public function getDescription(): string
    {
        return 'Create thallo_commerce_checkout_attempts (durable checkout-attempt idempotency '
            . 'ledger, storefront-rendering spec §7).';
    }
}
