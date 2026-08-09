<?php

declare(strict_types=1);

namespace Thallo\Commerce\Settings;

use Glueful\Database\Connection;
use Glueful\Uploader\Contracts\BlobAccessContext;
use Glueful\Uploader\Contracts\BlobAccessPolicy;
use Glueful\Uploader\Contracts\BlobAction;
use Thallo\Contracts\Delivery\MediaUrlResolver;

/**
 * The ONE ownership + servability authority for the invoice logo setting
 * (`commerce.invoice.logo_blob_uuid`, orders-invoices-receipts spec Task 6). It queries the
 * framework's `blobs` table directly — public, active, not deleted, `image/*` mime — then hands
 * the row to the injected {@see BlobAccessPolicy} with a VIEW {@see BlobAccessContext} so the
 * app's own policy (tenant ownership via `media_assets` when tenancy is enforced; unconditional
 * grant in single-store mode) decides ownership, and finally requires the injected
 * {@see MediaUrlResolver} to actually produce a URL.
 *
 * Deliberately never imports an app class and never queries `media_assets` itself — those rows
 * simply don't exist when tenancy is off, and this resolver must stay correct in both modes by
 * delegating the ownership question entirely to the injected policy. Every failure path (missing
 * row, wrong mime, private/inactive/deleted, policy refusal, unresolvable URL) collapses to
 * `null` — this method NEVER throws, so a stale or invalid stored uuid degrades a read to
 * "no logo" instead of a 500. Callers that must REJECT an invalid uuid at save time (the
 * controller's PUT validation) do so themselves by treating a `null` return as invalid.
 */
final class InvoiceLogoResolver
{
    public function __construct(
        private readonly Connection $connection,
        private readonly BlobAccessPolicy $policy,
        private readonly MediaUrlResolver $urls,
    ) {
    }

    public function resolve(string $uuid): ?string
    {
        $uuid = trim($uuid);
        if ($uuid === '') {
            return null;
        }

        $blob = $this->connection->table('blobs')
            ->where('uuid', '=', $uuid)
            ->where('visibility', '=', 'public')
            ->where('status', '=', 'active')
            ->whereNull('deleted_at')
            ->first();
        if ($blob === null) {
            return null;
        }

        $mime = is_string($blob['mime_type'] ?? null) ? $blob['mime_type'] : '';
        if (!str_starts_with($mime, 'image/')) {
            return null;
        }

        if (!$this->policy->authorizeAccess($blob, new BlobAccessContext(BlobAction::VIEW, null, false))) {
            return null;
        }

        return $this->urls->url($uuid);
    }
}
