<?php

declare(strict_types=1);

namespace Thallo\Commerce\Tenancy;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Tenancy\CommerceTenantResolution;
use Psr\Container\ContainerInterface;
use Thallo\Tenancy\Adoption\AdoptionGate;
use Thallo\Tenancy\Resolution\TenancyModePolicy;
use Thallo\Tenancy\System\SystemFlags;

/**
 * Thallo's binding of Commerce's host-integration tenant-resolution seam
 * ({@see CommerceTenantResolution}): the shared three-mode {@see TenancyModePolicy}, unchanged —
 * the sentinel on a clean install, the persisted default tenant on a widened schema, and the
 * shared request-scoped resolver once enforcement is active. Payments answer from the same policy,
 * so an order and its payments always resolve to the same workspace, and resolving the single store
 * holds the adoption gate, so the flip can never move the store's orders out from under it.
 */
final class ThalloCommerceTenantResolution implements CommerceTenantResolution
{
    private readonly TenancyModePolicy $policy;

    public function __construct(SystemFlags $flags, ContainerInterface $container)
    {
        $this->policy = new TenancyModePolicy(
            $flags,
            $container,
            $container->has(AdoptionGate::class) ? $container->get(AdoptionGate::class) : null,
        );
    }

    public function tenantUuid(ApplicationContext $context): string
    {
        return $this->policy->tenantUuid($context);
    }
}
