<?php

declare(strict_types=1);

namespace Thallo\Commerce;

use Glueful\Extensions\DeclaresLoadOrder;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Cache\CacheStore;
use Glueful\Cache\Contracts\EdgeCacheInterface;
use Glueful\Database\Connection;
use Glueful\Database\Migrations\MigrationPriority;
use Glueful\Encryption\EncryptionService;
use Glueful\Events\EventService;
use Glueful\Extensions\Commerce\Catalog\CatalogReader;
use Glueful\Extensions\Commerce\Catalog\SlugLifecycleAuthority;
use Glueful\Extensions\Commerce\Events\ProductDeleted;
use Glueful\Extensions\Commerce\Events\ProductSlugChanged;
use Glueful\Extensions\Commerce\Events\StorefrontCatalogChanged;
use Glueful\Extensions\Commerce\Contracts\OrderPaymentReturnUrlProvider;
use Glueful\Extensions\Commerce\Orders\CheckoutAttemptAuthority;
use Glueful\Extensions\Commerce\Tenancy\CommerceTenantPurge;
use Glueful\Extensions\Commerce\Tenancy\CommerceTenantResolution;
use Glueful\Extensions\Commerce\Support\CommerceSettingsOverride;
use Thallo\Commerce\Settings\SettingsStoreCommerceOverride;
use Glueful\Extensions\Commerce\Tenancy\TenantAdopter;
use Glueful\Extensions\Contracts\Tenancy\TenantTableRegistry;
use Glueful\Extensions\ServiceProvider;
use Psr\Container\ContainerInterface;
use Thallo\Commerce\Adoption\CommerceAdoptionContributor;
use Thallo\Commerce\Diagnostics\CommerceIntegrationDiagnostics;
use Thallo\Commerce\Email\CommerceEmailTemplates;
use Thallo\Commerce\Email\SendOrderEmails;
use Thallo\Commerce\Events\ProductLinkChanged;
use Thallo\Commerce\Http\AdminOrderSearchController;
use Thallo\Commerce\Http\CommerceMetaController;
use Thallo\Commerce\Http\CommerceSettingsController;
use Thallo\Commerce\Http\EmailSettingsController;
use Thallo\Commerce\Http\MarketplaceSettingsController;
use Thallo\Commerce\Http\ProductLinkController;
use Thallo\Commerce\Links\EntryLinkSearch;
use Thallo\Commerce\Links\LinkReconciler;
use Thallo\Commerce\Links\ProductLinkRepository;
use Thallo\Commerce\Links\ProductLinkService;
use Thallo\Commerce\Orders\AdminOrderSearchQuery;
use Thallo\Commerce\Http\Shop\CartCookie;
use Thallo\Commerce\Http\Shop\GuestOrderCookie;
use Thallo\Commerce\Http\Shop\ShopAssetController;
use Thallo\Commerce\Http\Shop\ShopBlockDataController;
use Thallo\Commerce\Http\Shop\ShopCartController;
use Thallo\Commerce\Http\Shop\ShopCatalogController;
use Thallo\Commerce\Http\Shop\ShopCheckoutController;
use Thallo\Commerce\Http\Shop\ShopCsrfGuard;
use Thallo\Commerce\Payments\ThalloOrderPaymentReturnUrlProvider;
use Thallo\Commerce\Http\Shop\ShopPageRenderer;
use Thallo\Commerce\Http\Shop\ShopProductCardAssembler;
use Thallo\Commerce\Http\Shop\ShopWishlistController;
use Thallo\Commerce\Listeners\EntryDeletedListener;
use Thallo\Commerce\Listeners\ProductDeletedListener;
use Thallo\Commerce\Purge\CommercePurgeHandler;
use Thallo\Commerce\Shop\CapabilityFlipPurge;
use Thallo\Commerce\Shop\Contribution\ShopReservedPathContributor;
use Thallo\Commerce\Shop\Contribution\ShopTemplatePathContributor;
use Thallo\Commerce\Shop\ShopAssetMap;
use Thallo\Commerce\Shop\Listeners\PurgeShopCacheOnAppearanceChange;
use Thallo\Commerce\Shop\Listeners\PurgeShopCacheOnCatalogChange;
use Thallo\Commerce\Shop\Listeners\PurgeShopCacheOnLinkChange;
use Thallo\Commerce\Shop\Listeners\PurgeShopCacheOnRegionUpdate;
use Thallo\Commerce\Shop\Listeners\PurgeShopCacheOnSlugChange;
use Thallo\Commerce\Shop\Listeners\PurgeShopCacheOnThemeChange;
use Thallo\Commerce\Shop\PackCheckoutAttemptAuthority;
use Thallo\Commerce\Shop\PackSlugLifecycleAuthority;
use Thallo\Commerce\Shop\ShopFrameEmbedding;
use Thallo\Commerce\Shop\ShopPageCache;
use Thallo\Commerce\Shop\ShopStorefrontLinkResolver;
use Thallo\Commerce\Shop\ShopUrlGenerator;
use Thallo\Commerce\Shop\ShopWishlistSurface;
use Thallo\Commerce\Shop\StorefrontPreviewUrlBuilder;
use Thallo\Commerce\Starter\ProductStoryContributor;
use Thallo\Commerce\Starter\ShopBlockTypesContributor;
use Thallo\Commerce\Tenancy\ThalloCommerceTenantResolution;
use Thallo\Contracts\Capability\Capability;
use Thallo\Contracts\Capability\CapabilityRegistry;
use Thallo\Contracts\Content\RegionUpdated;
use Thallo\Contracts\Delivery\CanonicalPublicOriginResolver;
use Thallo\Contracts\Delivery\StorefrontLinkResolver;
use Thallo\Contracts\Delivery\StorefrontWishlistResolver;
use Thallo\Contracts\Events\ContentLifecycleEvent;
use Thallo\Contracts\Settings\ThemeAppearanceChanged;
use Thallo\Contracts\Settings\ThemeChanged;
use Thallo\Contracts\Starter\StarterBlockTypeRegistry;
use Thallo\Contracts\Starter\StarterContributorRegistry;
use Thallo\Render\Contribution\RenderContributionRegistry;
use Thallo\Render\ThemeAppearanceSource;
use Thallo\Render\ThemeLocator;
use Thallo\Tenancy\Adoption\AdoptionContributorRegistry;
use Thallo\Tenancy\System\SystemFlags;

use function config;

final class CommerceIntegrationServiceProvider extends ServiceProvider implements DeclaresLoadOrder
{
    /**
     * Source-verified edge (modules-not-extensions spec §5.2): this pack mounts commerce's
     * admin route catalog and binds its host seams — commerce's own routes and boot state
     * must exist first, preserving the pre-conversion route registration order.
     */
    public static function loadAfter(): array
    {
        return [\Glueful\Extensions\Commerce\CommerceServiceProvider::class];
    }

    /**
     * Post-extension tier (modules-not-extensions spec §5.2): app-integrated modules load
     * AFTER the extension universe, reproducing the pre-conversion order in which they lived
     * at the tail of config/extensions.php. Inter-module order comes from the
     * serviceproviders.php list (the orderer's stable tie-break).
     */
    public static function loadPriority(): int
    {
        return 100;
    }

    /** The table this pack owns for product-to-entry enrichment links (spec §5.1). */
    private const PRODUCT_LINK_TABLE = 'thallo_commerce_product_links';

    /**
     * Tenant resolution is infrastructure, not a user-facing surface: bound unconditionally
     * (never inside the capability gate in boot() below), so Commerce's own
     * `makeTenantResolver()` picks up the three-mode seam regardless of whether
     * `thallo.commerce` is enabled. Guarded by `interface_exists` (CommerceTenantResolution is
     * an interface, not a class -- `class_exists()` always returns false for it) so an install
     * where glueful/commerce isn't present stays inert instead of fatal-erroring on the
     * container compiling a reference to a missing type.
     *
     * @return array<string, mixed>
     */
    public static function services(): array
    {
        if (!interface_exists(CommerceTenantResolution::class)) {
            return [];
        }

        $services = [
            CommerceTenantResolution::class => [
                'factory' => [self::class, 'makeCommerceTenantResolution'],
                'shared' => true,
            ],
            // Store-settings spec §3.3: Commerce's runtime-settings seam, backed by thallo's own
            // `settings` table through the app's SettingsStore. The capability gate lives INSIDE
            // value() (compiled services can't bind conditionally) — disabled ⇒ pure config.
            CommerceSettingsOverride::class => [
                'factory' => [self::class, 'makeCommerceSettingsOverride'],
                'shared' => true,
            ],
            ProductLinkRepository::class => [
                'class'    => ProductLinkRepository::class,
                'shared'   => true,
                'autowire' => true,
            ],
            ProductLinkService::class => [
                'class'    => ProductLinkService::class,
                'shared'   => true,
                'autowire' => true,
            ],
            // Task 7 (admin-commerce-area plan, slice 3): the linkage picker's tenant-scoped
            // entry search. Autowired -- Connection/CommerceTenantResolution/LocaleManagerInterface
            // are all plain container-bound services (i18n is a hard-dependency extension for this
            // pack's admin surface, unlike the soft-resolved seams elsewhere in this file).
            EntryLinkSearch::class => [
                'class'    => EntryLinkSearch::class,
                'shared'   => true,
                'autowire' => true,
            ],
            ProductLinkController::class => [
                'class'    => ProductLinkController::class,
                'shared'   => true,
                'autowire' => true,
            ],
            // Task 8 (admin-commerce-area plan, slice 3): the `/meta` settings/entitlement
            // probe. Autowired -- ApplicationContext/StorefrontPreviewUrlBuilder are plain
            // container-bound services; the neutral Thallo\Contracts\Authorization\
            // PermissionRequirementAuthority contract resolves to the SAME shared instance the
            // `content_permission` route middleware evaluates against (the engine app's own
            // provider aliases the contract to its concrete authority), so the endpoint's
            // can_view/can_manage flags and the route's own gate can never disagree.
            CommerceMetaController::class => [
                'class'    => CommerceMetaController::class,
                'shared'   => true,
                'autowire' => true,
            ],
            // Store-settings spec §3.4: GET/PUT /settings. Autowired — storage resolves through
            // the pack-owned CommerceSettingsStore contract the host app binds.
            CommerceSettingsController::class => [
                'class'    => CommerceSettingsController::class,
                'shared'   => true,
                'autowire' => true,
            ],
            // Payments settings RETIRED (platform-payments-settings spec, Task 6): moved to an
            // app-owned controller at `/v1/admin/settings/payments`. This pack no longer binds a
            // payments controller.
            // Spec §4.2 follow-up: GET/PUT /emails — the per-template order-email switches.
            EmailSettingsController::class => [
                'class'    => EmailSettingsController::class,
                'shared'   => true,
                'autowire' => true,
            ],
            // Spec §3.6 Marketplace group: thin front over commerce's marketplace services.
            MarketplaceSettingsController::class => [
                'class'    => MarketplaceSettingsController::class,
                'shared'   => true,
                'autowire' => true,
            ],
            // Task 3 (orders-invoices-receipts plan): TEMPORARY app-owned filtered orders search
            // (see AdminOrderSearchController's own docblock for the retirement condition).
            // AdminOrderSearchFilter is deliberately NOT bound here -- the controller constructs
            // it directly per-request from the live Request (`new AdminOrderSearchFilter($request)`,
            // mirroring every other request-driven QueryFilter in this codebase), so it carries
            // no DI entry of its own.
            AdminOrderSearchQuery::class => [
                'class'    => AdminOrderSearchQuery::class,
                'shared'   => true,
                'autowire' => true,
            ],
            AdminOrderSearchController::class => [
                'class'    => AdminOrderSearchController::class,
                'shared'   => true,
                'autowire' => true,
            ],
            LinkReconciler::class => [
                'factory' => [self::class, 'makeLinkReconciler'],
                'shared'  => true,
            ],
            EntryDeletedListener::class => [
                'class'    => EntryDeletedListener::class,
                'shared'   => true,
                'autowire' => true,
            ],
            ProductDeletedListener::class => [
                'class'    => ProductDeletedListener::class,
                'shared'   => true,
                'autowire' => true,
            ],
            CommerceIntegrationDiagnostics::class => [
                'factory' => [self::class, 'makeCommerceIntegrationDiagnostics'],
                'shared'  => true,
            ],
            // Task 10: the pack's PurgeHandler + AdoptionContributor (design spec §8). Both
            // factories soft-resolve their Commerce-side collaborator (CommerceTenantPurge /
            // TenantAdopter) via a container `has()` check rather than autowiring the type
            // directly -- autowiring would hard-fail when Commerce's own provider is inactive
            // (the service simply isn't bound then), where these handlers must instead degrade
            // per their own fail-closed/soft-skip rules. Mirrors `makeLinkReconciler`'s reasoning
            // above. The purge handler is also aliased so
            // `Thallo\Tenancy\TenancyServiceProvider::makePurgeResourceRegistry()` can pick it up
            // -- the exact mechanism `thallo.collections.purge_handler` already establishes.
            CommercePurgeHandler::class => [
                'factory' => [self::class, 'makeCommercePurgeHandler'],
                'shared'  => true,
                'alias'   => ['thallo.commerce.purge_handler'],
            ],
            CommerceAdoptionContributor::class => [
                'factory' => [self::class, 'makeCommerceAdoptionContributor'],
                'shared'  => true,
            ],
            // Task 11 (storefront-rendering spec §5.2): the boot-built content-hash allowlist
            // ShopUrlGenerator::assets() and ShopAssetController both depend on. Built
            // unconditionally (like ShopUrlGenerator itself) — a cheap, side-effect-free
            // directory scan, harmless when thallo.commerce is disabled since nothing ever
            // reaches the (gated) asset route or a (gated) block template in that state.
            ShopAssetMap::class => [
                'factory' => [self::class, 'makeShopAssetMap'],
                'shared'  => true,
            ],
            // Task 7 (storefront-rendering spec §3): the single source of every shop/cart/
            // checkout URL. Eagerly resolved once from boot() (registerShopUrlContribution()
            // below), OUTSIDE the capability gate, so a misconfigured shop_prefix fails AT BOOT
            // rather than lazily on the first request.
            ShopUrlGenerator::class => [
                'factory' => [self::class, 'makeShopUrlGenerator'],
                'shared'  => true,
            ],
            // Task 5 (admin-commerce-area plan, slice 3): the sole absolute-storefront-URL
            // composer — origin (Task 6's CanonicalPublicOriginResolver, bound unconditionally at
            // the app level) + relative path (ShopUrlGenerator, immediately above). Bound
            // unconditionally alongside ShopUrlGenerator itself for the same reason: pure,
            // side-effect-free composition with no capability-gated behavior of its own.
            StorefrontPreviewUrlBuilder::class => [
                'factory' => [self::class, 'makeStorefrontPreviewUrlBuilder'],
                'shared'  => true,
            ],
            // Commerce-Slice-2 Fix A: the soft-bound seam thallo-render's RenderContextExtension
            // consumes (`shop_product_url()`/`shop_category_url()`/`shop_index_url()`) so a block
            // template's no-JS `<noscript>` fallback can link to the real catalog WITHOUT the
            // render pack ever importing ShopUrlGenerator. Bound unconditionally alongside
            // ShopUrlGenerator itself (not gated on thallo.commerce being enabled) — the render
            // pack's own soft-bind (`$container->has(StorefrontLinkResolver::class)`) is the
            // enablement gate, exactly like every other soft-bound RenderContextExtension seam.
            StorefrontLinkResolver::class => [
                'factory' => [self::class, 'makeStorefrontLinkResolver'],
                'shared'  => true,
            ],
            // Storefront-v1 Task 4 (spec §5): the wishlist seam thallo-render's
            // RenderContextExtension consumes (`shop_wishlist_scope()`/`shop_wishlist_url()`).
            // Bound unconditionally like StorefrontLinkResolver immediately above — compiled
            // services can't be capability-conditional — but UNLIKE that pure adapter, the
            // implementation itself re-checks `thallo.commerce` on every call and answers null
            // while it's off (the SettingsStoreCommerceOverride gate-at-use-time posture).
            StorefrontWishlistResolver::class => [
                'factory' => [self::class, 'makeStorefrontWishlistResolver'],
                'shared'  => true,
            ],
            // Storefront-v1 Task 7: the shared shop-page render seam (ShopCatalogController's
            // old private render(), extracted verbatim) and the shared batched card pipeline
            // (its old buildGrid() body) — both consumed by ShopCatalogController AND
            // ShopWishlistController so pages and cards can never drift between the two.
            ShopPageRenderer::class => [
                'class'    => ShopPageRenderer::class,
                'shared'   => true,
                'autowire' => true,
            ],
            ShopProductCardAssembler::class => [
                'class'    => ShopProductCardAssembler::class,
                'shared'   => true,
                'autowire' => true,
            ],
            ShopCatalogController::class => [
                'class'    => ShopCatalogController::class,
                'shared'   => true,
                'autowire' => true,
            ],
            // Storefront-v1 Task 7: the wishlist page shell + bounded resolution endpoint.
            ShopWishlistController::class => [
                'class'    => ShopWishlistController::class,
                'shared'   => true,
                'autowire' => true,
            ],
            // Task 11: the read-only JSON data source the 3 catalog-data block templates
            // hydrate from client-side, plus the fingerprinted static-asset controller.
            ShopBlockDataController::class => [
                'class'    => ShopBlockDataController::class,
                'shared'   => true,
                'autowire' => true,
            ],
            ShopAssetController::class => [
                'class'    => ShopAssetController::class,
                'shared'   => true,
                'autowire' => true,
            ],
            // Task 9 (storefront-rendering spec §6): cart token custody + the `/_shop/cart/*`
            // CSRF guard + the cart endpoints/page controller. `CartCookie` has no dependencies
            // of its own; `ShopCartController` is autowired like `ShopCatalogController` above
            // (same TwigFactory/RenderContextExtension render seam, plus CartService/CartCookie/
            // ShopUrlGenerator/the origin resolver). `ShopCsrfGuard` gets an explicit factory —
            // unlike autowiring elsewhere in this file, this makes the Task-6
            // `CanonicalPublicOriginResolver` dependency it enforces origin checks against
            // impossible to miss in a diff.
            CartCookie::class => [
                'class'    => CartCookie::class,
                'shared'   => true,
                'autowire' => true,
            ],
            ShopCsrfGuard::class => [
                'factory' => [self::class, 'makeShopCsrfGuard'],
                'shared'  => true,
            ],
            // Checkout-ui plan Task 3: hosted payments' browser return/cancel URLs. Commerce
            // soft-resolves this contract; binding it here makes the trusted-origin composition
            // (CanonicalPublicOriginResolver + ShopUrlGenerator — never the request Host) the one
            // authority for every placement/replay/retry initiation.
            OrderPaymentReturnUrlProvider::class => [
                'class'    => ThalloOrderPaymentReturnUrlProvider::class,
                'shared'   => true,
                'autowire' => true,
            ],
            ShopCartController::class => [
                'class'    => ShopCartController::class,
                'shared'   => true,
                'autowire' => true,
            ],
            // Task 8 (storefront-rendering spec §4): the pack's transactional slug-reservation
            // authority, bound to Commerce's own SlugLifecycleAuthority seam OUTSIDE the
            // thallo.commerce capability gate (registerShopCachePurgeListeners() below is the
            // analogous boot()-side infrastructure; this is the compile-time equivalent) — slug
            // integrity must hold even while the storefront capability itself is disabled.
            // Aliased (not separately bound) so Commerce's write path and the controller's
            // read-only ledger lookup (the old-slug 301) both resolve the SAME shared instance.
            PackSlugLifecycleAuthority::class => self::packSlugLifecycleAuthorityDefinition(),
            // Task 8 (storefront-rendering spec §9): the shop catalog page cache middleware
            // (index/product/category routes).
            ShopPageCache::class => [
                'factory' => [self::class, 'makeShopPageCache'],
                'shared'  => true,
            ],
            ShopFrameEmbedding::class => [
                'factory' => [self::class, 'makeShopFrameEmbedding'],
                'shared'  => true,
            ],
            PurgeShopCacheOnCatalogChange::class => [
                'factory' => [self::class, 'makePurgeShopCacheOnCatalogChange'],
                'shared'  => true,
            ],
            PurgeShopCacheOnSlugChange::class => [
                'factory' => [self::class, 'makePurgeShopCacheOnSlugChange'],
                'shared'  => true,
            ],
            PurgeShopCacheOnLinkChange::class => [
                'factory' => [self::class, 'makePurgeShopCacheOnLinkChange'],
                'shared'  => true,
            ],
            PurgeShopCacheOnRegionUpdate::class => [
                'factory' => [self::class, 'makePurgeShopCacheOnRegionUpdate'],
                'shared'  => true,
            ],
            PurgeShopCacheOnThemeChange::class => [
                'factory' => [self::class, 'makePurgeShopCacheOnThemeChange'],
                'shared'  => true,
            ],
            PurgeShopCacheOnAppearanceChange::class => [
                'factory' => [self::class, 'makePurgeShopCacheOnAppearanceChange'],
                'shared'  => true,
            ],
            // Task 10 (storefront-rendering spec §7): the pack's durable checkout-attempt
            // authority, bound to Commerce's own CheckoutAttemptAuthority seam OUTSIDE the
            // thallo.commerce capability gate — the SAME "compile-time equivalent of
            // registerShopUrlContribution()" reasoning PackSlugLifecycleAuthority's own binding
            // comment gives above: the durable-idempotency guarantee must hold even while the
            // storefront capability itself is disabled (e.g. mid-incident), and Commerce
            // soft-resolves this seam once at ITS OWN boot regardless of this pack's gate.
            PackCheckoutAttemptAuthority::class => self::packCheckoutAttemptAuthorityDefinition(),
            // Task 10: guest order-credential cookie custody + the checkout controller. Both
            // autowired like CartCookie/ShopCartController above — GuestOrderCookie's only
            // dependency is EncryptionService (framework core, always resolvable via
            // ApplicationContext alone); ShopCheckoutController pulls CartService/CartCookie/
            // GuestOrderCookie/CheckoutService/CheckoutPresentation/CommerceTenantResolution/
            // OrderRepository/ShopUrlGenerator plus the same TwigFactory/RenderContextExtension
            // render seam ShopCatalogController/ShopCartController already use.
            GuestOrderCookie::class => [
                'class'    => GuestOrderCookie::class,
                'shared'   => true,
                'autowire' => true,
            ],
            ShopCheckoutController::class => [
                'class'    => ShopCheckoutController::class,
                'shared'   => true,
                'autowire' => true,
            ],
            // ReconcileLinksCommand / CommerceDiagnoseCommand are NOT registered here: they take
            // no constructor dependencies (getService() resolves LinkReconciler/
            // CommerceIntegrationDiagnostics lazily inside execute()) and are picked up by
            // discoverCommands() in boot() below, matching the thallo-tenancy pack's convention.
        ];

        // NOTE: Payvia's runtime-settings seam is deliberately NOT bound here any more.
        // Platform-payments-settings spec §2 (Task 4) moved gateway-credential ownership to the
        // HOST APP, which binds that seam from its own provider's services(): credentials are
        // installation-level infrastructure that webhook signature verification reads before any
        // tenant context exists, so they must not sit behind this pack's `thallo.commerce`
        // capability gate.

        return $services;
    }

    public static function makeCommerceTenantResolution(ContainerInterface $container): CommerceTenantResolution
    {
        return new ThalloCommerceTenantResolution($container->get(SystemFlags::class), $container);
    }

    /**
     * Reads + normalizes `thallo-commerce.shop_prefix` (default 'shop') and constructs the
     * generator — {@see ShopUrlGenerator::normalizePrefix()} throws a loud \RuntimeException for
     * an empty or multi-segment prefix. Lazy by container convention, but
     * {@see self::registerShopUrlContribution()} resolves this EAGERLY during boot() so a bad
     * config value surfaces as a boot failure, not a first-request 500.
     */
    public static function makeShopUrlGenerator(ContainerInterface $container): ShopUrlGenerator
    {
        $context = $container->get(ApplicationContext::class);

        return new ShopUrlGenerator(
            (string) config($context, 'thallo-commerce.shop_prefix', 'shop'),
            $container->get(ShopAssetMap::class),
        );
    }

    /** Task 11: scans the pack's own `assets/` directory — never request input. */
    public static function makeShopAssetMap(ContainerInterface $container): ShopAssetMap
    {
        return new ShopAssetMap(dirname(__DIR__) . '/assets');
    }

    /**
     * Task 5 (admin-commerce-area plan, slice 3): injects the Task-6
     * {@see CanonicalPublicOriginResolver} contract directly — the SAME app-level binding
     * {@see self::makeShopCsrfGuard()} already sources its origin authority from, so an admin
     * preview link and a CSRF-checked origin can never disagree about what "the canonical origin"
     * means.
     */
    public static function makeStorefrontPreviewUrlBuilder(
        ContainerInterface $container,
    ): StorefrontPreviewUrlBuilder {
        return new StorefrontPreviewUrlBuilder(
            $container->get(CanonicalPublicOriginResolver::class),
            $container->get(ShopUrlGenerator::class),
        );
    }

    /** Commerce-Slice-2 Fix A: a thin {@see ShopUrlGenerator} adapter — see the class docblock. */
    public static function makeStorefrontLinkResolver(ContainerInterface $container): ShopStorefrontLinkResolver
    {
        return new ShopStorefrontLinkResolver(
            $container->get(ShopUrlGenerator::class),
            static fn (): bool => $container->has(CapabilityRegistry::class)
                && $container->get(CapabilityRegistry::class)->isEnabled('thallo.commerce'),
        );
    }

    /**
     * Storefront-v1 Task 4 (spec §5): tenant resolution goes through the SAME shared
     * {@see CommerceTenantResolution} seam Commerce itself consumes (resolved live per call
     * inside the surface — see its docblock), and the capability closure re-reads
     * {@see CapabilityRegistry} on every invocation so a flip is honored immediately, with the
     * `has()` guard keeping pre-registry boots (CLI, partial harnesses) fail-closed to "off".
     */
    public static function makeStorefrontWishlistResolver(ContainerInterface $container): ShopWishlistSurface
    {
        return new ShopWishlistSurface(
            $container->get(ApplicationContext::class),
            $container->get(ShopUrlGenerator::class),
            $container->get(CommerceTenantResolution::class),
            static fn (): bool => $container->has(CapabilityRegistry::class)
                && $container->get(CapabilityRegistry::class)->isEnabled('thallo.commerce'),
        );
    }

    /**
     * Task 9 (storefront-rendering spec §6): injects the Task-6
     * {@see CanonicalPublicOriginResolver} contract directly — the engine app's own binding of
     * it (its ServiceProvider, outside this pack's namespace entirely) is the SAME single
     * authority the engine's own tenant-owned-blob public-URL provider delegates to for media
     * URLs, so storefront CSRF and media-URL generation can never disagree about what "the
     * canonical origin" means. Bound unconditionally at the app level (not behind this pack's
     * own Commerce guard), so no `has()` check is needed here.
     */
    public static function makeShopCsrfGuard(ContainerInterface $container): ShopCsrfGuard
    {
        return new ShopCsrfGuard(
            $container->get(ApplicationContext::class),
            $container->get(CanonicalPublicOriginResolver::class),
        );
    }

    /**
     * Task 8: `SlugLifecycleAuthority::class => ['alias' => X]` would NOT bind the interface —
     * {@see \Glueful\Container\Loader\DefaultServicesLoader::collectAliases()}'s alias direction
     * points AT the concrete entry, so the alias belongs on THIS (the concrete) definition,
     * guarded so an install missing Commerce's Catalog\SlugLifecycleAuthority interface (a
     * narrower check than the CommerceTenantResolution guard already wrapping the whole
     * services() array) never registers an alias to a non-existent type.
     *
     * @return array<string,mixed>
     */
    private static function packSlugLifecycleAuthorityDefinition(): array
    {
        $definition = [
            'factory' => [self::class, 'makePackSlugLifecycleAuthority'],
            'shared'  => true,
        ];
        if (interface_exists(SlugLifecycleAuthority::class)) {
            $definition['alias'] = [SlugLifecycleAuthority::class];
        }

        return $definition;
    }

    public static function makePackSlugLifecycleAuthority(ContainerInterface $container): PackSlugLifecycleAuthority
    {
        return new PackSlugLifecycleAuthority($container->get(Connection::class));
    }

    /**
     * Task 10: mirrors {@see self::packSlugLifecycleAuthorityDefinition()}'s identical
     * alias-on-the-concrete-definition reasoning — `CheckoutAttemptAuthority::class => ['alias'
     * => X]` would not actually bind the interface, so the alias is attached to THIS (the
     * concrete) definition instead, guarded so an install missing Commerce's
     * `Orders\CheckoutAttemptAuthority` interface never registers an alias to a non-existent
     * type.
     *
     * @return array<string,mixed>
     */
    private static function packCheckoutAttemptAuthorityDefinition(): array
    {
        $definition = [
            'factory' => [self::class, 'makePackCheckoutAttemptAuthority'],
            'shared'  => true,
        ];
        if (interface_exists(CheckoutAttemptAuthority::class)) {
            $definition['alias'] = [CheckoutAttemptAuthority::class];
        }

        return $definition;
    }

    public static function makePackCheckoutAttemptAuthority(
        ContainerInterface $container,
    ): PackCheckoutAttemptAuthority {
        return new PackCheckoutAttemptAuthority(
            $container->get(Connection::class),
            $container->get(EncryptionService::class),
        );
    }

    /**
     * Task 8 (storefront-rendering spec §9): mirrors thallo-render's own
     * `RenderServiceProvider::makeRenderPageCache()` sourcing exactly (the SAME CacheStore
     * binding, the SAME ThemeLocator/ThemeAppearanceSource identities) — the shop cache and the
     * render page cache must never disagree about what the "current" theme/appearance is.
     */
    /** Store-settings spec §3.3: storage flows through the pack-owned CommerceSettingsStore
     * contract, which the HOST app binds — this package never names an app class. */
    public static function makeCommerceSettingsOverride(ContainerInterface $container): CommerceSettingsOverride
    {
        return new SettingsStoreCommerceOverride();
    }

    public static function makeShopFrameEmbedding(ContainerInterface $container): ShopFrameEmbedding
    {
        $context = $container->get(ApplicationContext::class);

        return new ShopFrameEmbedding((string) config($context, 'render.admin_url', ''));
    }

    public static function makeShopPageCache(ContainerInterface $container): ShopPageCache
    {
        $context = $container->get(ApplicationContext::class);
        $appearance = $container->get(ThemeAppearanceSource::class);

        return new ShopPageCache(
            $container->get(CacheStore::class),
            $container->get(CommerceTenantResolution::class),
            $container->get(ThemeLocator::class)->activePaths()['name'],
            $appearance->accent() . '-' . $appearance->neutral(),
            (bool) config($context, 'thallo-commerce.shop_cache.enabled', true),
            (int) config($context, 'thallo-commerce.shop_cache.ttl', 3600),
            $context,
        );
    }

    public static function makePurgeShopCacheOnCatalogChange(
        ContainerInterface $container,
    ): PurgeShopCacheOnCatalogChange {
        return new PurgeShopCacheOnCatalogChange($container);
    }

    public static function makePurgeShopCacheOnSlugChange(ContainerInterface $container): PurgeShopCacheOnSlugChange
    {
        return new PurgeShopCacheOnSlugChange($container);
    }

    public static function makePurgeShopCacheOnLinkChange(ContainerInterface $container): PurgeShopCacheOnLinkChange
    {
        return new PurgeShopCacheOnLinkChange($container);
    }

    public static function makePurgeShopCacheOnRegionUpdate(
        ContainerInterface $container,
    ): PurgeShopCacheOnRegionUpdate {
        return new PurgeShopCacheOnRegionUpdate($container);
    }

    public static function makePurgeShopCacheOnThemeChange(
        ContainerInterface $container,
    ): PurgeShopCacheOnThemeChange {
        return new PurgeShopCacheOnThemeChange($container);
    }

    public static function makePurgeShopCacheOnAppearanceChange(
        ContainerInterface $container,
    ): PurgeShopCacheOnAppearanceChange {
        return new PurgeShopCacheOnAppearanceChange($container);
    }

    /**
     * Deliberately NOT autowired: {@see LinkReconciler} takes the raw container (to lazily,
     * defensively resolve Commerce's CatalogReader/EntryExistenceReader only when actually
     * scanning -- see its own docblock for why a hard constructor dependency on them is unsafe).
     */
    public static function makeLinkReconciler(ContainerInterface $container): LinkReconciler
    {
        return new LinkReconciler(
            $container->get(ProductLinkRepository::class),
            $container,
            $container->get(SystemFlags::class),
            $container->has(EventService::class) ? $container->get(EventService::class) : null,
        );
    }

    public static function makeCommerceIntegrationDiagnostics(
        ContainerInterface $container,
    ): CommerceIntegrationDiagnostics {
        return new CommerceIntegrationDiagnostics(
            $container->get(ApplicationContext::class),
            $container->get(LinkReconciler::class),
        );
    }

    /**
     * Soft-resolves {@see CommerceTenantPurge} (see {@see CommercePurgeHandler}'s own docblock
     * for the fail-closed rule this feeds).
     */
    public static function makeCommercePurgeHandler(ContainerInterface $container): CommercePurgeHandler
    {
        return new CommercePurgeHandler(
            $container->get(Connection::class),
            $container->has(CommerceTenantPurge::class) ? $container->get(CommerceTenantPurge::class) : null,
        );
    }

    /** Soft-resolves {@see TenantAdopter} (Commerce's provider may be inactive, design spec §3). */
    public static function makeCommerceAdoptionContributor(
        ContainerInterface $container,
    ): CommerceAdoptionContributor {
        return new CommerceAdoptionContributor(
            $container->get(Connection::class),
            $container->has(TenantAdopter::class) ? $container->get(TenantAdopter::class) : null,
        );
    }

    public function register(ApplicationContext $context): void
    {
        // Package configs are NOT auto-loaded — merge the pack's own tree under 'thallo-commerce'.
        $this->mergeConfig('thallo-commerce', require __DIR__ . '/../config/thallo-commerce.php');
    }

    public function boot(ApplicationContext $context): void
    {
        $registry = app($context, CapabilityRegistry::class);

        $registry->register(new Capability(
            'thallo.commerce',
            label: 'Commerce',
            description: 'Adopts glueful/commerce and links Commerce products to Thallo entries.',
        ));

        // Migrations register on INSTALL, not enable (outside the gate below), so disabling
        // the capability still preserves the link table.
        $this->loadMigrationsFrom(
            __DIR__ . '/../migrations',
            MigrationPriority::DEPENDENT,
            'thallo-commerce',
        );

        // The pack owns thallo_commerce_product_links (design spec §8): register it directly
        // whenever TenantTableRegistry is bound — independent of the capability gate below, and
        // independent of Commerce's own table registration (Commerce registers its OWN tables
        // in its own boot(); this pack must not, and does not, register those again).
        $this->registerProductLinkTable($context);

        // Task 10: register this pack's AdoptionContributor with the shared
        // AdoptionContributorRegistry — a PUSH registration ("packs register in their
        // providers", design spec §8.1), outside the capability gate below for the same reason
        // as the purge handler (see registerAdoptionContributor()'s own docblock).
        $this->registerAdoptionContributor($context);

        // Cleanup listeners are maintenance infrastructure, not user-facing capability behavior
        // (design spec §6.2): registered OUTSIDE the gate below so disabling thallo.commerce can
        // never let previously-created links drift.
        $this->registerLifecycleListeners($context);

        // Task 7 (storefront-rendering spec §3/§5.1/§5.2): the shop prefix RESERVATION is
        // infrastructure, not user-facing behavior — OUTSIDE the gate below, exactly like the
        // purge handler/adoption contributor/lifecycle listeners above, so Render's catch-all
        // can never serve a builder page at the shop prefix path even while thallo.commerce
        // itself is disabled (see the method's own docblock). The pack TEMPLATE dir
        // contribution used to register here too but moved INSIDE the gate (capability-boundary
        // pin): the template paths are exactly what make stored shop blocks render their shells
        // + the shop.js script tag, and "capability off" means commerce absent from the
        // rendered page — stored blocks fall to blocks()' missing-template fallback while
        // disabled and return on re-enable with no migration or resync.
        $this->registerShopUrlContribution($context);

        // Task 8 (storefront-rendering spec §9): the shop cache purge listeners are the exact
        // same maintenance-infrastructure category as the lifecycle listeners above — outside
        // the capability gate below, so a slug/link/catalog mutation made just after disabling
        // thallo.commerce can never leave a stale entry for the NEXT time it's re-enabled.
        $this->registerShopCachePurgeListeners($context);

        // Capability-boundary pin: a flip of thallo.commerce between boots purges the rendered
        // page cache (+ edge) so previously cached shop shells/script tags — or, on re-enable,
        // cached missing-template fallbacks — disappear immediately. OUTSIDE the gate for the
        // same reason as every purge above: it must run precisely when the capability is OFF.
        $this->reconcileCapabilityState($context, $registry->isEnabled('thallo.commerce'));

        // Gated by ENABLED state (spec §3): the user-facing surface only, mirroring pack
        // conventions. Disabling thallo.commerce leaves migrations/tables/registration intact.
        if ($registry->isEnabled('thallo.commerce')) {
            $this->loadRoutesFrom(__DIR__ . '/../routes/admin-routes.php');
            $this->loadRoutesFrom(__DIR__ . '/../routes/shop-routes.php');

            // Capability-boundary pin: the pack template dir joins Render's resolution chain
            // ONLY while the capability is on. With it off, `blocks/product-grid.twig` etc.
            // simply don't exist in the Twig loader, so stored shop blocks render through the
            // normal missing-template fallback — no shop HTML, no shop.js script tag, no /cart
            // links — and reappear on the next enabled boot with no migration or resync
            // (registration is boot-time and data is never touched).
            $this->registerShopTemplatePaths($context);

            // Task 11: the starter "Product story" content-type contribution (design spec §9) is
            // user-facing batteries-included content, unlike the maintenance infrastructure
            // above -- it registers ONLY while the capability is on. Contributor discovery alone
            // never mutates existing tenants (it only makes the type PARTICIPATE in fresh
            // provisioning and normal syncs); adopting it into pre-existing tenants is the
            // explicit, retryable `php glueful thallo:tenant:sync --all --kind=content_type` step
            // documented in this pack's README, run once after enabling the capability.
            $this->registerStarterContributor($context);

            // Slice-2 Task 11 (storefront-rendering spec §5.2/§10): the 4 starter shop block
            // types (product-grid/featured-product/add-to-cart/mini-cart) are equally
            // user-facing batteries-included content — registered ONLY while the capability is
            // on, mirroring registerStarterContributor() immediately above exactly. Adopting
            // them into pre-existing tenants is the same explicit, retryable
            // `php glueful thallo:tenant:sync --all --kind=block_type` step.
            $this->registerShopBlockTypeContributor($context);

            // Store-settings spec §4: transactional order emails are USER-FACING capability
            // behavior — definitions register into the email extension's registry (they then
            // appear, editable, in Settings › Email) and the listener sends through the
            // notification pipeline. Both are soft-gated on the email/notification services
            // actually being bound, so an install without glueful/email-notification boots clean.
            $this->registerOrderEmails($context);
        }

        // The reconcile sweep + diagnostics commands are maintenance/read-only surfaces too, for
        // the identical reason as the listeners above — outside the capability gate. Discovered
        // (not eagerly resolved via services()+commands()) so `php glueful <anything>` is safe
        // even when Commerce's own provider is inactive — mirrors thallo-tenancy's own
        // discoverCommands() convention.
        $this->discoverCommands('Thallo\\Commerce\\Console', __DIR__ . '/Console');
    }

    /**
     * Design spec §6.2: cleanup listeners register OUTSIDE the `thallo.commerce` capability
     * gate, whenever their source provider is available.
     *
     *  - `entry.deleted` (via the neutral {@see ContentLifecycleEvent} contract — never the
     *    engine's concrete entry-deleted event class directly, packs may not reference the
     *    engine app's namespace) registers unconditionally, once this pack's own base services
     *    exist (guarded above by
     *    `interface_exists(CommerceTenantResolution::class)` in {@see services()}) —
     *    {@see EntryDeletedListener}'s own dependencies never touch a Commerce container
     *    binding, so it is safe to construct even when Commerce's provider is inactive.
     *  - Commerce's `ProductDeleted` registers ONLY when the event class exists (composer
     *    presence) AND Commerce's own provider is active (`CatalogReader` bound) — that event
     *    can only ever fire when Commerce is active in the first place.
     */
    /**
     * Store-settings spec §4.1/§4.2. Every guard is a SOFT dependency check: the email
     * extension's registry contract, Commerce's event classes, EventService, and
     * NotificationService must all be present — any absence means "no order emails", never a
     * boot failure. Registration is per-request-idempotent: DefinitionRegistry allows
     * re-registering a key under the SAME owner.
     */
    private function registerOrderEmails(ApplicationContext $context): void
    {
        if (
            !interface_exists(\Glueful\Extensions\Contracts\Email\EmailTemplateRegistry::class)
            || !class_exists(\Glueful\Extensions\Commerce\Events\OrderPlaced::class)
        ) {
            return;
        }
        $container = $context->getContainer();
        if (
            !$container->has(\Glueful\Extensions\Contracts\Email\EmailTemplateRegistry::class)
            || !$container->has(EventService::class)
            || !$container->has(\Glueful\Notifications\Services\NotificationService::class)
        ) {
            return;
        }

        $container->get(\Glueful\Extensions\Contracts\Email\EmailTemplateRegistry::class)
            ->register(...CommerceEmailTemplates::definitions());

        // ONE sender at a time: Commerce ships its OWN (dormant-by-default) order mailer —
        // OrderMailListener behind `commerce.email.enabled`. If an operator turned that on,
        // registering this listener too would DOUBLE-EMAIL every buyer, so Commerce's own
        // switch wins and thallo's registry-templated sender stands down (definitions still
        // register above — the templates stay visible/editable either way).
        if ((bool) config($context, 'commerce.email.enabled', false)) {
            return;
        }

        $listener = new SendOrderEmails(
            $context,
            $container->get(\Glueful\Notifications\Services\NotificationService::class),
            $container->has(\Psr\Log\LoggerInterface::class)
                ? $container->get(\Psr\Log\LoggerInterface::class)
                : null,
        );

        /** @var EventService $events */
        $events = $container->get(EventService::class);
        $events->addListener(\Glueful\Extensions\Commerce\Events\OrderPlaced::class, [$listener, 'onOrderPlaced']);
        $events->addListener(\Glueful\Extensions\Commerce\Events\OrderPaid::class, [$listener, 'onOrderPaid']);
        $events->addListener(
            \Glueful\Extensions\Commerce\Events\OrderFulfilled::class,
            [$listener, 'onOrderFulfilled']
        );
        $events->addListener(
            \Glueful\Extensions\Commerce\Events\OrderCanceled::class,
            [$listener, 'onOrderCanceled']
        );
    }

    private function registerLifecycleListeners(ApplicationContext $context): void
    {
        if (!interface_exists(CommerceTenantResolution::class)) {
            return; // Commerce package itself absent — none of this pack's services are bound.
        }
        $container = $context->getContainer();
        if (!$container->has(EventService::class)) {
            return;
        }
        /** @var EventService $events */
        $events = $container->get(EventService::class);

        $events->addListener(ContentLifecycleEvent::class, [
            app($context, EntryDeletedListener::class),
            'onContentChanged',
        ]);

        if (class_exists(ProductDeleted::class) && $container->has(CatalogReader::class)) {
            $events->addListener(ProductDeleted::class, app($context, ProductDeletedListener::class));
        }
    }

    /**
     * Task 8 (storefront-rendering spec §9): the shop catalog cache's five purge listeners.
     * {@see StorefrontCatalogChanged} covers all 11 of its closed reasons through ONE
     * registration — the reason lives on the event INSTANCE, not the event class, so this
     * pack never needs (or could construct) a per-reason listener list.
     * {@see ProductSlugChanged}/{@see StorefrontCatalogChanged} are Commerce classes, guarded
     * by `class_exists()` (composer presence) exactly like {@see self::registerLifecycleListeners()}'s
     * `ProductDeleted` guard above; {@see ProductLinkChanged} is this pack's own class and
     * {@see ThemeChanged}/{@see ThemeAppearanceChanged} live in the hard-dependency
     * thallo-contracts package, so neither needs a class_exists() guard.
     */
    private function registerShopCachePurgeListeners(ApplicationContext $context): void
    {
        if (!interface_exists(CommerceTenantResolution::class)) {
            return; // Commerce package itself absent — none of this pack's services are bound.
        }
        $container = $context->getContainer();
        if (!$container->has(EventService::class)) {
            return;
        }
        /** @var EventService $events */
        $events = $container->get(EventService::class);

        if (class_exists(StorefrontCatalogChanged::class)) {
            $events->addListener(StorefrontCatalogChanged::class, [
                app($context, PurgeShopCacheOnCatalogChange::class),
                'onCatalogChanged',
            ]);
        }
        if (class_exists(ProductSlugChanged::class)) {
            $events->addListener(ProductSlugChanged::class, [
                app($context, PurgeShopCacheOnSlugChange::class),
                'onSlugChanged',
            ]);
        }
        $events->addListener(ProductLinkChanged::class, [
            app($context, PurgeShopCacheOnLinkChange::class),
            'onLinkChanged',
        ]);
        // Header/footer chrome renders on shop pages too, but those live in the SHOP cache —
        // without this the render-cache purge alone left stale chrome on /shop & friends.
        $events->addListener(RegionUpdated::class, [
            app($context, PurgeShopCacheOnRegionUpdate::class),
            'onRegionUpdated',
        ]);
        $events->addListener(ThemeChanged::class, [
            app($context, PurgeShopCacheOnThemeChange::class),
            'onThemeChanged',
        ]);
        $events->addListener(ThemeAppearanceChanged::class, [
            app($context, PurgeShopCacheOnAppearanceChange::class),
            'onAppearanceChanged',
        ]);
    }

    /**
     * Register {@see self::PRODUCT_LINK_TABLE} into the tenancy backstop — but only when
     * TenantTableRegistry is bound (the glueful/tenancy extension is active). Unlike
     * {@see \Thallo\Tenancy\TenancyServiceProvider::registerTenantTables()}, this does NOT also
     * gate on tenancy-enforcement flags: the pack's own table is always declared tenant-owned
     * once the registry exists, matching design spec §8 ("whenever TenantTableRegistry is
     * bound, its provider registers that table exactly once").
     *
     * register() is documented as idempotent (re-registering a table is a no-op), so calling
     * this on every boot() is safe even if boot() runs more than once in a process — "exactly
     * once" is a property of any conformant registry, not of extra state kept here.
     *
     * The registry is an injectable seam (defaults to a container lookup) so this is
     * unit-testable without a full tenancy-enabled boot.
     */
    public function registerProductLinkTable(
        ApplicationContext $context,
        ?TenantTableRegistry $registry = null,
    ): bool {
        if ($registry === null) {
            $container = $context->getContainer();
            if (!$container->has(TenantTableRegistry::class)) {
                return false;
            }
            /** @var TenantTableRegistry $registry */
            $registry = $container->get(TenantTableRegistry::class);
        }

        $registry->register([self::PRODUCT_LINK_TABLE]);

        return true;
    }

    /**
     * Push {@see CommerceAdoptionContributor} into the shared {@see AdoptionContributorRegistry}
     * — unlike `PurgeResourceRegistry` (a factory-built registry that pulls sibling handlers via
     * an aliased container lookup, see `Thallo\Tenancy\TenancyServiceProvider::
     * makePurgeResourceRegistry()`), `AdoptionContributorRegistry` is bound as a plain shared
     * instance with NO aggregating factory — its own binding docblock in
     * `Thallo\Tenancy\TenancyServiceProvider::services()` and design spec §8.1 both describe
     * "packs register in their providers" as the intended mechanism, so this pack's provider
     * calls `register()` directly here, exactly like the well-established
     * `CapabilityRegistry::register(new Capability(...))` idiom used throughout every pack.
     *
     * Outside the `thallo.commerce` capability gate (design spec §8.1: adoption is enablement-time
     * infrastructure, not user-facing behavior — a workspace enabling tenancy must adopt this
     * pack's data regardless of whether the capability happens to be on or off at that moment,
     * exactly like the purge handler and lifecycle listeners above).
     *
     * `AdoptionContributorRegistry::register()` throws on a duplicate id (unlike
     * `TenantTableRegistry::register()`'s idempotent "set" semantics), so this method guards
     * against a re-registration explicitly rather than relying on the registry to no-op it —
     * safe even if `boot()` were ever invoked twice against the same container/registry instance.
     *
     * The registry is an injectable seam (defaults to a container lookup) so this is
     * unit-testable without a full tenancy-enabled boot, mirroring registerProductLinkTable().
     */
    public function registerAdoptionContributor(
        ApplicationContext $context,
        ?AdoptionContributorRegistry $registry = null,
    ): bool {
        if (!interface_exists(CommerceTenantResolution::class)) {
            return false; // Commerce package itself absent — none of this pack's services are bound.
        }
        if ($registry === null) {
            $container = $context->getContainer();
            if (!$container->has(AdoptionContributorRegistry::class)) {
                return false;
            }
            /** @var AdoptionContributorRegistry $registry */
            $registry = $container->get(AdoptionContributorRegistry::class);
        }

        foreach ($registry->all() as $existing) {
            if ($existing->id() === CommerceAdoptionContributor::ID) {
                return true; // already registered — idempotent no-op.
            }
        }

        $registry->register(app($context, CommerceAdoptionContributor::class));

        return true;
    }

    /**
     * Task 11: register {@see ProductStoryContributor} with the shared
     * {@see StarterContributorRegistry} — the design spec §9 seam that lets an installed pack
     * participate in the fixed pages/category/post starter set without the app-owned
     * `ContentTypeKind` referencing this pack's namespace. Called ONLY from inside the
     * `thallo.commerce` capability-enabled branch of {@see boot()} (unlike
     * {@see registerProductLinkTable()}/{@see registerAdoptionContributor()}, which are
     * maintenance infrastructure and stay unconditional) -- the Product story type is
     * user-facing batteries-included content, design spec §9.
     *
     * Registering merely makes the definition ELIGIBLE for the next fresh-tenant provisioning
     * run or `thallo:tenant:sync` sweep -- it is a pure in-memory registry mutation (no
     * `Connection`/query-builder dependency reaches this method at all) and therefore performs
     * zero tenant-data writes by construction; it never touches an existing tenant's
     * `content_types` table itself.
     *
     * The registry is an injectable seam (defaults to a container lookup) so this is
     * unit-testable without a full capability-enabled boot, mirroring
     * registerProductLinkTable()/registerAdoptionContributor() above.
     */
    public function registerStarterContributor(
        ApplicationContext $context,
        ?StarterContributorRegistry $registry = null,
    ): bool {
        if (!interface_exists(CommerceTenantResolution::class)) {
            return false; // Commerce package itself absent — none of this pack's services are bound.
        }
        if ($registry === null) {
            $container = $context->getContainer();
            if (!$container->has(StarterContributorRegistry::class)) {
                return false;
            }
            /** @var StarterContributorRegistry $registry */
            $registry = $container->get(StarterContributorRegistry::class);
        }

        foreach ($registry->all() as $existing) {
            if ($existing instanceof ProductStoryContributor) {
                return true; // already registered — idempotent no-op.
            }
        }

        $registry->register(new ProductStoryContributor());

        return true;
    }

    /**
     * Slice-2 Task 11 (storefront-rendering spec §5.2/§10): register
     * {@see ShopBlockTypesContributor} with the shared {@see StarterBlockTypeRegistry} — the
     * exact {@see self::registerStarterContributor()} pattern immediately above, applied to
     * block types instead of content types. Called ONLY from inside the `thallo.commerce`
     * capability-enabled branch of {@see boot()} — the 4 shop blocks are user-facing
     * batteries-included content, not maintenance infrastructure.
     *
     * A pure in-memory registry mutation (no `Connection`/query-builder dependency reaches this
     * method): it makes the 4 definitions ELIGIBLE for the next fresh-tenant provisioning run or
     * `thallo:tenant:sync --kind=block_type` sweep, and never itself writes a `block_types` row.
     *
     * The registry is an injectable seam (defaults to a container lookup) so this is
     * unit-testable without a full capability-enabled boot, mirroring
     * registerStarterContributor() above.
     */
    public function registerShopBlockTypeContributor(
        ApplicationContext $context,
        ?StarterBlockTypeRegistry $registry = null,
    ): bool {
        if (!interface_exists(CommerceTenantResolution::class)) {
            return false; // Commerce package itself absent — none of this pack's services are bound.
        }
        if ($registry === null) {
            $container = $context->getContainer();
            if (!$container->has(StarterBlockTypeRegistry::class)) {
                return false;
            }
            /** @var StarterBlockTypeRegistry $registry */
            $registry = $container->get(StarterBlockTypeRegistry::class);
        }

        foreach ($registry->all() as $existing) {
            if ($existing instanceof ShopBlockTypesContributor) {
                return true; // already registered — idempotent no-op.
            }
        }

        $registry->register(new ShopBlockTypesContributor());

        return true;
    }

    /**
     * Task 7 (storefront-rendering spec §3/§5.1/§5.2): eagerly resolves {@see ShopUrlGenerator}
     * — validating/normalizing `thallo-commerce.shop_prefix` NOW, at boot, rather than lazily on
     * the first request that happens to need it — then registers the reserved-path contribution
     * ({@see ShopReservedPathContributor}: `{prefix}`, `cart`, `_shop` as of task 9; `checkout`
     * is still a later task's own contribution once that route exists) with the shared
     * {@see RenderContributionRegistry}.
     *
     * Called UNCONDITIONALLY from {@see boot()} (outside the `thallo.commerce` capability gate):
     * the whole point of the reserved-path contribution is that Render's `/{path}` catch-all must
     * never serve a builder page at the shop prefix path EVEN WHILE the capability is disabled
     * (disabling it only removes this pack's OWN routes, registered separately inside the gate).
     * The TEMPLATE-path contribution is deliberately NOT here — it is user-facing rendered
     * surface and registers inside the gate ({@see self::registerShopTemplatePaths()}).
     *
     * Soft-resolves {@see RenderContributionRegistry} (thallo-render may be absent/inactive) —
     * but the ShopUrlGenerator resolution above always runs first and always throws loudly on a
     * bad prefix, regardless of whether render's registry is even bound.
     */
    private function registerShopUrlContribution(ApplicationContext $context): void
    {
        if (!interface_exists(CommerceTenantResolution::class)) {
            return; // Commerce package itself absent — none of this pack's services are bound.
        }
        $container = $context->getContainer();
        // Eager resolution is the boot-time validation: a misconfigured shop_prefix throws here.
        $urls = $container->get(ShopUrlGenerator::class);

        if (!$container->has(RenderContributionRegistry::class)) {
            return; // thallo-render absent/inactive — nothing to contribute to.
        }
        /** @var RenderContributionRegistry $registry */
        $registry = $container->get(RenderContributionRegistry::class);
        $registry->registerReservedPaths(new ShopReservedPathContributor($urls->prefix));
    }

    /**
     * The pack template dir contribution (capability-boundary pin) — called from boot()'s
     * `thallo.commerce`-ENABLED branch only, unlike the reserved-path contribution above.
     * Same soft-resolve posture (thallo-render may be absent/inactive).
     */
    private function registerShopTemplatePaths(ApplicationContext $context): void
    {
        if (!interface_exists(CommerceTenantResolution::class)) {
            return; // Commerce package itself absent — none of this pack's services are bound.
        }
        $container = $context->getContainer();
        if (!$container->has(RenderContributionRegistry::class)) {
            return; // thallo-render absent/inactive — nothing to contribute to.
        }
        /** @var RenderContributionRegistry $registry */
        $registry = $container->get(RenderContributionRegistry::class);
        $registry->registerTemplatePaths(new ShopTemplatePathContributor());
    }

    /**
     * Capability-boundary pin: {@see CapabilityFlipPurge} — purge rendered pages (+ edge) when
     * the `thallo.commerce` enabled state changed since the last boot, so cached pages carrying
     * the OLD boundary (shop shells + shop.js tag after a disable; missing-template fallbacks
     * after a re-enable) stop serving immediately. Runs OUTSIDE the gate — it must fire
     * precisely on the boot where the capability turned off. Soft-resolves everything
     * (CLI/pre-migration boots, absent cache): a skipped reconcile only delays the purge to the
     * next fully-wired boot, because the marker is only ever advanced by reconcile() itself.
     */
    private function reconcileCapabilityState(ApplicationContext $context, bool $enabled): void
    {
        if (!interface_exists(CommerceTenantResolution::class)) {
            return; // Commerce package itself absent — none of this pack's services are bound.
        }
        $container = $context->getContainer();
        if (!$container->has(CacheStore::class)) {
            return;
        }
        $edge = $container->has(EdgeCacheInterface::class)
            ? $container->get(EdgeCacheInterface::class)
            : null;
        (new CapabilityFlipPurge($container->get(CacheStore::class), $edge))->reconcile($enabled);
    }
}
