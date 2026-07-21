<?php

declare(strict_types=1);

namespace Thallo\Commerce\Tenancy;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Tenancy\CommerceTenantResolution;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Psr\Container\ContainerInterface;
use Thallo\Tenancy\System\SystemFlags;

/**
 * Thallo's three-mode binding of Commerce's host-integration tenant-resolution seam
 * ({@see CommerceTenantResolution}), driven entirely off {@see SystemFlags}:
 *
 *   (a) clean install (schema 'none', enforcement off)  -> '' (sentinel)
 *   (b) widened schema, enforcement not yet active       -> the persisted default tenant;
 *       fail-closed \RuntimeException if none is set (a widened schema with no default
 *       tenant is a broken adoption -- this NEVER silently falls back to the sentinel)
 *   (c) enforcement active                                -> delegates to the shared,
 *       request-scoped {@see CurrentTenantResolver} (never rebound; re-resolved fresh on
 *       every call)
 *
 * Every branch reads {@see SystemFlags} LIVE on each {@see self::tenantUuid()} call:
 * `enforcementActive()` unconditionally clears the flags cache before answering, so flipping
 * flags mid-process is reflected starting with the VERY NEXT call -- nothing here caches a
 * resolved MODE or a resolved TENANT value. Only the shared resolver SERVICE (never the tenant
 * string it returns) is memoized, lazily, the first time mode (c) is entered.
 */
final class ThalloCommerceTenantResolution implements CommerceTenantResolution
{
    private ?CurrentTenantResolver $sharedResolver = null;

    public function __construct(
        private readonly SystemFlags $flags,
        private readonly ContainerInterface $container,
    ) {
    }

    public function tenantUuid(ApplicationContext $context): string
    {
        if ($this->flags->enforcementActive()) {
            return $this->sharedResolver()->tenantUuid($context); // mode (c)
        }

        if ($this->flags->schemaState() === 'widened') {
            $default = $this->flags->defaultTenantUuid(); // mode (b)
            if ($default === null || $default === '') {
                throw new \RuntimeException('Widened tenancy schema without a persisted default tenant.');
            }

            return $default;
        }

        return ''; // mode (a)
    }

    /**
     * The shared CONTRACT resolver -- resolved from the container (and memoized) the first time
     * mode (c) is entered. NEVER rebound to a Thallo-local implementation: this always delegates
     * to whatever glueful/tenancy (or another host) bound as {@see CurrentTenantResolver}.
     *
     * Deliberately NOT constructor-injected: it must stay resolvable in installs where
     * glueful/tenancy is not bound at all -- modes (a)/(b) never touch it, so construction of
     * this class itself must not require it.
     */
    private function sharedResolver(): CurrentTenantResolver
    {
        if ($this->sharedResolver !== null) {
            return $this->sharedResolver;
        }

        if (!$this->container->has(CurrentTenantResolver::class)) {
            throw new \RuntimeException(
                'Tenancy enforcement is active but no CurrentTenantResolver is bound '
                . '(install glueful/tenancy).'
            );
        }

        $resolver = $this->container->get(CurrentTenantResolver::class);
        if (!$resolver instanceof CurrentTenantResolver) {
            throw new \RuntimeException('Configured tenant resolver does not implement CurrentTenantResolver.');
        }

        return $this->sharedResolver = $resolver;
    }
}
