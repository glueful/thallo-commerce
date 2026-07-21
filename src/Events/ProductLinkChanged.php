<?php

declare(strict_types=1);

namespace Thallo\Commerce\Events;

use Glueful\Events\Contracts\BaseEvent;
use Glueful\Extensions\Audit\Contracts\AuditableEvent;
use Glueful\Extensions\Audit\Contracts\AuditableEventDefaults;

/**
 * Append-only audit record for a product<->entry link mutation (design spec §5.2):
 * link / relink (old->new entry) / unlink. Dispatched ONLY via `db($c)->afterCommit()` — a
 * rolled-back mutation must never produce this event.
 *
 * Self-auditing via {@see AuditableEvent}: the glueful/audit extension subscribes to this
 * interface, so dispatching this through {@see \Glueful\Events\EventService} records a
 * `commerce`-category audit row automatically, with no extra wiring on this pack's part.
 */
final class ProductLinkChanged extends BaseEvent implements AuditableEvent
{
    use AuditableEventDefaults;

    /** @param 'link'|'relink'|'unlink' $action */
    public function __construct(
        public readonly string $action,
        public readonly string $tenant,
        public readonly string $productUuid,
        public readonly ?string $oldEntryUuid,
        public readonly ?string $newEntryUuid,
    ) {
        parent::__construct();
    }

    public function auditAction(): string
    {
        return $this->action;
    }

    public function auditCategory(): string
    {
        return 'commerce';
    }

    /** @return array{type:string,uuid:string} */
    public function auditTarget(): array
    {
        return ['type' => 'commerce_product_link', 'uuid' => $this->productUuid];
    }

    /** @return array<string,array<string,mixed>>|null */
    public function auditChanges(): ?array
    {
        return match ($this->action) {
            'link' => ['entry_uuid' => ['to' => $this->newEntryUuid]],
            'unlink' => ['entry_uuid' => ['from' => $this->oldEntryUuid]],
            default => ['entry_uuid' => ['from' => $this->oldEntryUuid, 'to' => $this->newEntryUuid]],
        };
    }

    /** @return array<string,mixed> */
    public function auditMetadata(): array
    {
        return ['tenant' => $this->tenant];
    }
}
