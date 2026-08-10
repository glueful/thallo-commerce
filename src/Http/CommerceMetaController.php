<?php

declare(strict_types=1);

namespace Thallo\Commerce\Http;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Support\CommerceSettings;
use Glueful\Extensions\Commerce\Support\Money;
use Glueful\Http\Response;
use Glueful\Interfaces\Permission\PermissionStandards;
use Glueful\Routing\Attributes\ApiOperation;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Commerce\Shop\StorefrontPreviewUrlBuilder;
use Thallo\Contracts\Authorization\PermissionRequirementAuthority;

use function config;

/**
 * `GET /v1/admin/commerce/meta` (Task 8, admin-commerce-area plan slice 3, design spec §4.3):
 * the single settings/entitlement probe the Commerce admin SPA area consumes once and shares
 * across every page and editor panel (currency formatting, the storefront preview link, the
 * stock report's default threshold, and the two server-computed permission flags that decide
 * whether mutation controls render).
 *
 * `currency_exponent` is derived from Commerce's OWN authoritative
 * {@see Money::exponentFor()} — never a Thallo-local currency map and never a silent
 * default-to-2 fallback. An unrecognised configured currency code is a deployment error: it
 * throws {@see CommerceConfigurationException}, left uncaught here so it renders as a stable
 * 500 (see that class's own docblock), never a guessed exponent.
 *
 * `can_view`/`can_manage` are computed via the SAME {@see PermissionRequirementAuthority}
 * contract (design spec §4.2) the `content_permission` route middleware evaluates against — the
 * engine app's own service provider binds this neutral contract to the SAME shared concrete
 * authority instance the middleware resolves (a first-party pack may not depend on the engine
 * app's namespace directly, hence the contract seam). `can_view` is
 * `allows($request, ['commerce.view'])` (a `commerce.manage` grant satisfies it too, via the
 * capability catalog's declared implication) and `can_manage` is
 * `allows($request, ['commerce.manage'])`. No `effective(view) || effective(manage)` — or any
 * other reproduction of the implication rule — lives here; both flags defer entirely to the
 * authority.
 *
 * `shop_index_url` is the absolute, selected-workspace storefront index URL from the SAME
 * {@see StorefrontPreviewUrlBuilder} the product-link projection uses (Task 5/7) — no storefront
 * URL template is ever exposed to the client.
 *
 * `can_attach_user` (admin-order-creation cycle 2, Task 12, design spec §2.3) is a CLOSED
 * conjunction of THREE independent gates, all required — a `false` from any one is a `false`
 * flag, no partial credit: `users.user_lookup.enabled` (the master switch for the user-lookup
 * surface at all) AND `users.user_lookup.list.enabled` (the `GET /users` COLLECTION switch —
 * consulted by `glueful/users`' own `UsersServiceProvider::boot()` only INSIDE the parent's
 * branch, {@see \Glueful\Extensions\Users\UsersServiceProvider}, so `list.enabled=true` with the
 * parent off still means `GET /users` is a 404 — this flag must never claim otherwise) AND the
 * effective `users.view` permission, computed via the SAME {@see PermissionRequirementAuthority}
 * mechanism as `can_view`/`can_manage` above — never a second, parallel authorization path.
 * `PermissionStandards::PERMISSION_USERS_VIEW` is the framework's own CORE_PERMISSION slug
 * (`'users.view'`), not a Thallo-local string literal.
 */
final class CommerceMetaController
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly PermissionRequirementAuthority $authority,
        private readonly StorefrontPreviewUrlBuilder $previewUrls,
    ) {
    }

    /**
     * @return array{
     *     currency:string,
     *     currency_exponent:int,
     *     shop_index_url:string,
     *     low_stock_threshold:int,
     *     can_view:bool,
     *     can_manage:bool,
     *     can_attach_user:bool,
     * }
     */
    #[ApiOperation(
        summary: 'Commerce admin settings + effective permission flags',
        tags: ['Thallo Commerce'],
    )]
    public function meta(Request $request): Response
    {
        $currency = CommerceSettings::currency($this->context);
        $exponent = Money::exponentFor($currency);
        if ($exponent === null) {
            throw new CommerceConfigurationException(
                'commerce.currency',
                "unrecognised ISO 4217 currency code '{$currency}'.",
            );
        }

        $userLookupEnabled = (bool) config($this->context, 'users.user_lookup.enabled', false);
        $userLookupListEnabled = (bool) config($this->context, 'users.user_lookup.list.enabled', false);
        $canViewUsers = $this->authority->allows($request, [PermissionStandards::PERMISSION_USERS_VIEW]);

        return Response::success([
            'currency' => $currency,
            'currency_exponent' => $exponent,
            'shop_index_url' => $this->previewUrls->shopIndexUrl($this->context),
            'low_stock_threshold' => CommerceSettings::lowStockThreshold($this->context),
            'can_view' => $this->authority->allows($request, ['commerce.view']),
            'can_manage' => $this->authority->allows($request, ['commerce.manage']),
            'can_attach_user' => $userLookupEnabled && $userLookupListEnabled && $canViewUsers,
        ]);
    }
}
