<?php

declare(strict_types=1);

namespace Thallo\Commerce\Http;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Marketplace\CommissionPolicyService;
use Glueful\Extensions\Commerce\Marketplace\MarketplaceActivationService;
use Glueful\Extensions\Commerce\Marketplace\MarketplaceMode;
use Glueful\Extensions\Commerce\Marketplace\SellerService;
use Glueful\Extensions\Commerce\Tenancy\CommerceTenantResolution;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Marketplace settings admin API (store-settings spec §3.6, Marketplace group):
 * `GET /v1/admin/commerce/marketplace` + activate/deactivate/commission writes. A thin front
 * over commerce's OWN marketplace services (activation, commission policy, mode) — the same
 * services `MarketplaceAdminController` uses on commerce's flag-gated route stack — re-graded
 * through this pack's content_permission model so the admin SPA reaches them with its session,
 * exactly like every other mounted commerce surface.
 *
 * The MASTER flag (`commerce.marketplace.enabled`, env `COMMERCE_MARKETPLACE_ENABLED`) is
 * boot-time wiring by architecture: commerce registers its marketplace routes/listeners behind
 * it at boot, so it can never be a runtime setting. GET always reports it honestly; the write
 * endpoints refuse (409) while it is off — acting on marketplace state that commerce's own
 * surfaces can't see would be a trap, not a feature. Scope is deliberately settings-sized:
 * per-workspace activation + the workspace (fallback) commission policy. Sellers, payouts, and
 * financials are a future Marketplace admin area, not this tab.
 */
final class MarketplaceSettingsController
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly CommerceTenantResolution $tenants,
        private readonly ?MarketplaceActivationService $activation = null,
        private readonly ?CommissionPolicyService $commissionPolicy = null,
        private readonly ?MarketplaceMode $mode = null,
        private readonly ?SellerService $sellers = null,
        /** Test seam only — `commerce.marketplace.enabled` is boot-time env in production. */
        private readonly ?bool $masterEnabled = null,
    ) {
    }

    #[ApiOperation(
        summary: 'Get marketplace status, workspace settings, and the seller list for activation',
        tags: ['Thallo Commerce'],
    )]
    public function show(Request $request): Response
    {
        $master = $this->master();
        $tenant = $this->tenants->tenantUuid($this->context);

        $sellers = [];
        if ($master) {
            $page = $this->sellerService()->list($this->context, $tenant, [], 1, 100);
            foreach ((array) ($page['items'] ?? []) as $seller) {
                if (is_array($seller)) {
                    $sellers[] = [
                        'uuid' => (string) ($seller['uuid'] ?? ''),
                        'name' => (string) ($seller['name'] ?? ''),
                        'status' => (string) ($seller['status'] ?? ''),
                    ];
                }
            }
        }

        return Response::success([
            'master_enabled' => $master,
            'settings' => $this->projectedSettings($tenant),
            'sellers' => $sellers,
        ], 'Marketplace settings retrieved');
    }

    #[ApiOperation(
        summary: 'Activate marketplace mode for this workspace',
        tags: ['Thallo Commerce'],
    )]
    public function activate(Request $request): Response
    {
        $this->requireMaster();
        $body = (array) json_decode((string) $request->getContent(), true);
        $defaultSeller = $body['default_seller_uuid'] ?? null;
        if ($defaultSeller !== null && !is_string($defaultSeller)) {
            throw ValidationException::forField('default_seller_uuid', 'Must be a seller uuid or null.');
        }

        $tenant = $this->tenants->tenantUuid($this->context);
        // Commerce's own exception contract surfaces here untranslated: a 409 with
        // unassigned_count means "existing products need a default seller first".
        $this->activationService()->activate($this->context, $tenant, $defaultSeller, $this->actor($request));

        return $this->show($request);
    }

    #[ApiOperation(
        summary: 'Deactivate marketplace mode for this workspace (non-destructive)',
        tags: ['Thallo Commerce'],
    )]
    public function deactivate(Request $request): Response
    {
        $this->requireMaster();
        $tenant = $this->tenants->tenantUuid($this->context);
        $this->activationService()->deactivate($this->context, $tenant, $this->actor($request));

        return $this->show($request);
    }

    #[ApiOperation(
        summary: 'Set the workspace (fallback) commission policy',
        tags: ['Thallo Commerce'],
    )]
    public function updateCommission(Request $request): Response
    {
        $this->requireMaster();
        $body = (array) json_decode((string) $request->getContent(), true);
        $tenant = $this->tenants->tenantUuid($this->context);

        // CommissionPolicyResolver::validate owns the shape rules (percentage↔bps, fixed↔fixed);
        // its ValidationException/CommissionPolicyException surface as standard 422s.
        $this->commissionService()->setWorkspace(
            $this->context,
            $tenant,
            $tenant,
            [
                'kind' => is_string($body['commission_kind'] ?? null) ? $body['commission_kind'] : null,
                'bps' => is_int($body['commission_bps'] ?? null) ? $body['commission_bps'] : null,
                'fixed' => is_int($body['commission_fixed'] ?? null) ? $body['commission_fixed'] : null,
            ],
            $this->actor($request),
        );

        return $this->show($request);
    }

    /**
     * The `commerce_marketplace_settings` row, projected through a whitelist (never the raw
     * row — `tenant_uuid`/actor columns stay internal, matching every projection in this pack).
     *
     * @return array<string,mixed>|null
     */
    private function projectedSettings(string $tenant): ?array
    {
        $row = $this->modeService()->settingsRowFor($this->context, $tenant);
        if (!is_array($row)) {
            return null;
        }

        return [
            'status' => (string) ($row['status'] ?? ''),
            'default_seller_uuid' => is_string($row['default_seller_uuid'] ?? null)
                ? $row['default_seller_uuid']
                : null,
            'commission' => [
                'kind' => is_string($row['commission_kind'] ?? null) ? $row['commission_kind'] : null,
                'bps' => is_numeric($row['commission_bps'] ?? null) ? (int) $row['commission_bps'] : null,
                'fixed' => is_numeric($row['commission_fixed'] ?? null) ? (int) $row['commission_fixed'] : null,
            ],
            'reserve' => [
                'bps' => (int) ($row['reserve_bps'] ?? 0),
                'days' => (int) ($row['reserve_days'] ?? 0),
            ],
            'activated_at' => is_string($row['activated_at'] ?? null) ? $row['activated_at'] : null,
            'revision' => (int) ($row['revision'] ?? 0),
        ];
    }

    private function master(): bool
    {
        return $this->masterEnabled
            ?? (bool) config($this->context, 'commerce.marketplace.enabled', false);
    }

    private function requireMaster(): void
    {
        if (!$this->master()) {
            // 409, not 422: the request is well-formed — the INSTALL isn't wired for it.
            throw new \Glueful\Http\Exceptions\Client\ConflictException(
                'Marketplace is not enabled on this install — set COMMERCE_MARKETPLACE_ENABLED and restart.',
            );
        }
    }

    private function actor(Request $request): ?string
    {
        $user = (array) $request->attributes->get('user', []);
        $uuid = (string) ($user['uuid'] ?? '');

        return $uuid !== '' ? $uuid : null;
    }

    private function activationService(): MarketplaceActivationService
    {
        return $this->activation ?? app($this->context, MarketplaceActivationService::class);
    }

    private function commissionService(): CommissionPolicyService
    {
        return $this->commissionPolicy ?? app($this->context, CommissionPolicyService::class);
    }

    private function modeService(): MarketplaceMode
    {
        return $this->mode ?? app($this->context, MarketplaceMode::class);
    }

    private function sellerService(): SellerService
    {
        return $this->sellers ?? app($this->context, SellerService::class);
    }
}
