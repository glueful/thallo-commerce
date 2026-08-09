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
 * delegating the ownership question entirely to the injected policy.
 *
 * Two entry points, deliberately different failure postures:
 *  - {@see self::resolve()} is the GET/derivation path (`CommerceSettingsController::show()`'s
 *    derived `invoice_logo_url`). Every failure — missing row, wrong mime,
 *    private/inactive/deleted, policy refusal, unresolvable URL, OR a genuine DB/policy fault
 *    (the bound `BlobAccessPolicy`/`MediaUrlResolver` run raw queries and CAN throw on a real
 *    outage) — collapses to `null`. It NEVER throws, so a stale/invalid/faulted stored uuid
 *    degrades a read to "no logo" instead of 500ing the entire settings payload.
 *  - {@see self::resolveOrFail()} is the SAVE-time validation path
 *    (`CommerceSettingsController::validate()`). It does the identical ownership+servability
 *    check but deliberately does NOT catch a genuine DB/policy fault — that must propagate and
 *    refuse the save loudly (a 500, surfacing the real infrastructure problem) rather than being
 *    swallowed into a misleading "must be a public image you own" 422, which would mask the
 *    actual cause and — worse — risks a future refinement of this method quietly turning a
 *    caught fault into an accepted save. A `null` return (the ordinary "not servable" outcome,
 *    no exception involved) is still the caller's cue to 422 normally.
 */
final class InvoiceLogoResolver
{
    public function __construct(
        private readonly Connection $connection,
        private readonly BlobAccessPolicy $policy,
        private readonly MediaUrlResolver $urls,
    ) {
    }

    /** GET/derivation path — NEVER throws (see class docblock). */
    public function resolve(string $uuid): ?string
    {
        try {
            return $this->resolveOrFail($uuid);
        } catch (\Throwable) {
            return null;
        }
    }

    /** SAVE-time validation path — propagates a genuine DB/policy fault (see class docblock). */
    public function resolveOrFail(string $uuid): ?string
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
