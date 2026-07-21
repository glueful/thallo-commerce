<?php

declare(strict_types=1);

namespace Thallo\Commerce\Listeners;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Events\EventService;
use Glueful\Extensions\Commerce\Tenancy\CommerceTenantResolution;
use Psr\Log\LoggerInterface;
use Thallo\Commerce\Events\ProductLinkChanged;
use Thallo\Commerce\Links\ProductLinkRepository;
use Thallo\Contracts\Events\ContentLifecycleEvent;
use Throwable;

/**
 * Design spec §6.2: Thallo `entry.deleted` -> delete the canonical product<->entry link row (its
 * audit event records the unlink). Consumed via the neutral {@see ContentLifecycleEvent} contract
 * -- NEVER the engine's concrete entry-deleted event class directly, since packs may not
 * reference the engine app's namespace (scripts/check-pack-boundaries.php enforces this) -- the
 * SAME seam {@see \Thallo\Workflow\WorkflowLifecycleListener} already uses for the identical
 * problem (the engine's base content-event class implements {@see ContentLifecycleEvent}).
 *
 * REPOSITORY-level delete-by-entry (not {@see \Thallo\Commerce\Links\ProductLinkService::unlink()})
 * is a deliberate choice, not the default: this listener is constructed EAGERLY at boot()
 * (`EventService::addListener()` needs a real callable right away) and must stay safely
 * constructible even when Commerce's own provider is installed-but-inactive (design spec §1) --
 * `ProductLinkService` transitively requires Commerce's `CatalogReader`, which is unbound in that
 * state, and a hard dependency on it here would crash the WHOLE APP BOOT, not just this feature.
 * This class's own dependencies (`CommerceTenantResolution`, `ProductLinkRepository`, the
 * framework's `EventService`/`LoggerInterface`) never touch a Commerce container binding, so
 * registration is unconditional ("registers whenever the [pack] class exists").
 *
 * Idempotent: no link for the deleted entry is a silent no-op, never an error. Never throws out
 * (framework listener dispatch is already fault-isolated, but this is defensive too, per the
 * house "swallow + log" idiom -- see {@see \Thallo\Search\Index\ResilientContentReindexer}).
 */
final class EntryDeletedListener
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly CommerceTenantResolution $tenants,
        private readonly ProductLinkRepository $links,
        private readonly LoggerInterface $logger,
        private readonly ?EventService $events = null,
    ) {
    }

    public function onContentChanged(ContentLifecycleEvent $event): void
    {
        if ($event->name() !== 'entry.deleted') {
            return;
        }

        $entryUuid = $event->payload()['entry'] ?? null;
        if (!is_string($entryUuid) || $entryUuid === '') {
            return;
        }

        try {
            $tenant = $this->tenants->tenantUuid($this->context);
            $link = $this->links->findByEntry($tenant, $entryUuid);
            if ($link === null) {
                return; // No link for this entry -- idempotent no-op.
            }
            $productUuid = (string) $link['product_uuid'];

            db($this->context)->transaction(function () use ($tenant, $productUuid, $entryUuid): void {
                $this->links->delete($tenant, $productUuid);
                $this->auditAfterCommit($tenant, $productUuid, $entryUuid);
            });
        } catch (Throwable $e) {
            $this->logger->warning(
                'thallo-commerce: EntryDeletedListener failed to unlink product on entry deletion.',
                ['entry' => $entryUuid, 'error' => $e->getMessage()],
            );
        }
    }

    /** Audit ONLY via afterCommit() -- a rolled-back mutation must emit nothing. */
    private function auditAfterCommit(string $tenant, string $productUuid, string $entryUuid): void
    {
        $events = $this->events;
        if ($events === null) {
            return;
        }
        db($this->context)->afterCommit(static function () use ($events, $tenant, $productUuid, $entryUuid): void {
            $events->dispatch(new ProductLinkChanged('unlink', $tenant, $productUuid, $entryUuid, null));
        });
    }
}
