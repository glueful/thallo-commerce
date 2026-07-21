<?php

declare(strict_types=1);

namespace Thallo\Commerce\Listeners;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Events\EventService;
use Glueful\Extensions\Commerce\Events\ProductDeleted;
use Psr\Log\LoggerInterface;
use Thallo\Commerce\Events\ProductLinkChanged;
use Thallo\Commerce\Links\ProductLinkRepository;
use Throwable;

/**
 * Design spec §6.2: Commerce `ProductDeleted` -> delete the link row; the editorial entry is
 * PRESERVED (independently recoverable). Unlike {@see EntryDeletedListener}, the tenant comes
 * explicitly FROM THE EVENT (`tenantUuid`), never from ambient
 * {@see \Glueful\Extensions\Commerce\Tenancy\CommerceTenantResolution} resolution --
 * `ProductLinkService::unlink()` resolves tenant ambiently, which is why this listener goes
 * straight to the repository instead of reusing that service (see the Task 9 report for the
 * full rationale).
 *
 * Registered ONLY when `Glueful\Extensions\Commerce\Events\ProductDeleted` is class_exists AND
 * Commerce's own provider is active (its `CatalogReader` is container-bound) -- unlike
 * {@see EntryDeletedListener}, this event can only ever fire when Commerce is active in the
 * first place (nothing but `CatalogService::deleteProduct()` dispatches it), so subscribing
 * while inactive would be dead code, not a safety concern. See
 * {@see \Thallo\Commerce\CommerceIntegrationServiceProvider::registerLifecycleListeners()}.
 *
 * Idempotent: no link for the deleted product is a silent no-op. Never throws out (defensive
 * swallow + log, house idiom -- see {@see \Thallo\Search\Index\ResilientContentReindexer}).
 */
final class ProductDeletedListener
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly ProductLinkRepository $links,
        private readonly LoggerInterface $logger,
        private readonly ?EventService $events = null,
    ) {
    }

    public function __invoke(ProductDeleted $event): void
    {
        $tenant = $event->tenantUuid;
        $productUuid = $event->productUuid;

        try {
            $existing = $this->links->findByProduct($tenant, $productUuid);
            if ($existing === null) {
                return; // No link for this product -- idempotent no-op.
            }
            $entryUuid = (string) $existing['entry_uuid'];

            db($this->context)->transaction(function () use ($tenant, $productUuid, $entryUuid): void {
                $this->links->delete($tenant, $productUuid);
                $this->auditAfterCommit($tenant, $productUuid, $entryUuid);
            });
        } catch (Throwable $e) {
            $this->logger->warning(
                'thallo-commerce: ProductDeletedListener failed to unlink on product deletion.',
                ['tenant' => $tenant, 'product' => $productUuid, 'error' => $e->getMessage()],
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
