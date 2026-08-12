<?php

declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * Payment links Task 12 (payment-links spec §2.4): the delivery-idempotency ledger behind
 * {@see \Thallo\Commerce\Payments\PaymentLinkDeliveryRepository} and the pack's one
 * `POST /orders/{uuid}/payment-link/send` route.
 *
 * ## What this table is, and what it deliberately is NOT
 *
 * It is the record that ONE `Idempotency-Key` was used to attempt ONE payment-link delivery, and
 * how that attempt ended. It is NOT a copy of what was sent: there is no URL column, no token
 * column, no recipient-address column, and no rendered-body column, because the thing being
 * delivered is a live BEARER credential and a ledger row outlives the credential's usefulness by
 * a long way. The recipient is identified by `recipient_hash` (sha256 of the lowercased address)
 * — enough to detect "same key, different request", never enough to reconstitute a mailing list.
 *
 * `unique(tenant_uuid, idempotency_key)` is the arbitration ground truth: the repository's claim
 * is an INSERT that either wins (a brand-new attempt) or loses to this constraint and re-reads
 * the winner's row. No advisory lock is involved, so the claim is portable across SQLite and
 * PostgreSQL and holds no transaction open across the mint or the SMTP call that follow it.
 * Tenant-scoped rather than global, so two workspaces may independently use the same key.
 *
 * `link_uuid` is nullable UNTIL the mint completes: `mode=regenerate` claims the key BEFORE it
 * mints (§2.4), precisely so a crash between the two is visible as a `processing` row rather
 * than as nothing at all. `status` walks `processing -> sent|failed`, plus `indeterminate` for a
 * `processing` row older than
 * `thallo-commerce.payment_links.delivery_processing_stale_seconds` — the honest answer when the
 * process that claimed the key never came back and the plaintext it may have sent is
 * unrecoverable.
 *
 * `error_code` holds only the pack's own closed vocabulary or an engine
 * {@see \Glueful\Extensions\Commerce\Orders\PaymentLinkException} code — never a transport
 * exception message, which can quote credentials, recipients, or the URL itself.
 *
 * No DB foreign key into Commerce's `commerce_orders`/`commerce_payment_links` (cross-package
 * boundary), matching every other pack table's convention here.
 */
final class CreatePaymentLinkDeliveries implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('thallo_commerce_payment_link_deliveries')) {
            return;
        }
        $schema->createTable('thallo_commerce_payment_link_deliveries', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12);
            $table->string('tenant_uuid', 12)->default('');
            // Client-supplied, opaque, validated 16-128 characters by the controller.
            $table->string('idempotency_key', 191);
            // sha256 hex of the canonicalized request facts (order/mode/recipient/ttl) — never
            // the token, which must not participate in a value that outlives the request.
            $table->string('fingerprint', 64);
            $table->string('order_uuid', 12);
            // Null until the mint completes; always null for a claim that never minted.
            $table->string('link_uuid', 12)->nullable();
            // sha256 of the lowercased recipient address — never the address itself.
            $table->string('recipient_hash', 64);
            $table->string('mode', 16);
            $table->string('status', 16)->default('processing');
            // A CLOSED, safe code only (see the class docblock).
            $table->string('error_code', 64)->nullable();
            $table->string('provider_message_id', 191)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['tenant_uuid', 'idempotency_key'], 'uniq_commerce_link_delivery_tenant_key');
        });
        $schema->alterTable('thallo_commerce_payment_link_deliveries', static function ($table): void {
            // An operator asking "what was sent for this order?" — the one non-key lookup this
            // ledger has, kept as its own named index rather than leaning on the unique above.
            $table->index(['tenant_uuid', 'order_uuid'], 'idx_commerce_link_delivery_tenant_order');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('thallo_commerce_payment_link_deliveries');
    }

    public function getDescription(): string
    {
        return 'Create thallo_commerce_payment_link_deliveries (payment-link delivery idempotency '
            . 'ledger, payment-links spec §2.4).';
    }
}
