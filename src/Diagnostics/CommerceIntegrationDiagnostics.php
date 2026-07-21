<?php

declare(strict_types=1);

namespace Thallo\Commerce\Diagnostics;

use Glueful\Bootstrap\ApplicationContext;
use Thallo\Commerce\Links\LinkReconciler;

/**
 * Read-only operational diagnosis for the Commerce integration pack (design spec §6.2/§1):
 * stale product<->entry links, the marketplace-enabled flag (unsupported in Thallo v1), and
 * whether Commerce's own provider is active. Mirrors the section/status/detail shape of
 * {@see \Thallo\Tenancy\Enablement\TenancyDiagnostics} (the established sibling-pack
 * convention). Exposed via `thallo:commerce:diagnose`
 * ({@see \Thallo\Commerce\Console\CommerceDiagnoseCommand}).
 */
final class CommerceIntegrationDiagnostics
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly LinkReconciler $reconciler,
    ) {
    }

    /** @return array{sections: array<string, array{status:string, detail:mixed}>, ok: bool} */
    public function report(): array
    {
        $sections = [
            'commerce_active' => $this->commerceActiveSection(),
            'stale_links' => $this->staleLinksSection(),
            'marketplace' => $this->marketplaceSection(),
        ];

        $ok = true;
        foreach ($sections as $section) {
            $ok = $ok && $section['status'] !== 'fail';
        }

        return ['sections' => $sections, 'ok' => $ok];
    }

    /** @return array{status:string, detail:mixed} */
    private function commerceActiveSection(): array
    {
        $active = $this->reconciler->isCommerceActive();

        return [
            'status' => $active ? 'ok' : 'info',
            'detail' => $active
                ? 'Commerce provider is active.'
                : 'Commerce provider is not active (package installed but its provider is not '
                    . 'enabled -- design spec §1 "soft detection").',
        ];
    }

    /** @return array{status:string, detail:mixed} */
    private function staleLinksSection(): array
    {
        if (!$this->reconciler->isCommerceActive()) {
            return ['status' => 'info', 'detail' => 'Skipped: Commerce is not active.'];
        }

        $total = 0;
        $byTenant = [];
        foreach ($this->reconciler->discoverTenants() as $tenant) {
            $count = count($this->reconciler->scanTenant($this->context, $tenant, null));
            if ($count > 0) {
                $byTenant[$tenant === '' ? '(sentinel)' : $tenant] = $count;
            }
            $total += $count;
        }

        return [
            'status' => $total === 0 ? 'ok' : 'warn',
            'detail' => ['stale_count' => $total, 'by_tenant' => $byTenant],
        ];
    }

    /** @return array{status:string, detail:mixed} */
    private function marketplaceSection(): array
    {
        $enabled = (bool) config($this->context, 'commerce.marketplace.enabled', false);

        return [
            'status' => $enabled ? 'warn' : 'ok',
            'detail' => $enabled
                ? 'Marketplace is enabled; unsupported configuration in Thallo v1 (design spec §1).'
                : 'Marketplace is disabled (supported default).',
        ];
    }
}
