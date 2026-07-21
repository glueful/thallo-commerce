<?php

declare(strict_types=1);

namespace Thallo\Commerce\Http\Shop;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Cart\CartService;
use Glueful\Validation\ValidationException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Thallo\Commerce\Shop\ShopUrlGenerator;
use Thallo\Commerce\Shop\ViewModels\CartViewModel;
use Thallo\Contracts\Delivery\CanonicalPublicOriginResolver;
use Thallo\Render\Http\Middleware\RenderPageCache;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\TwigFactory;

use function config;

/**
 * The `/_shop/cart/*` mutation endpoints, `GET /_shop/cart` (mini-cart JSON hydration), and
 * `GET /cart` (the themed cart page) — storefront-rendering spec §3/§6/§9. Every response is
 * the SAME closed {@see CartViewModel}, whether it came from a plain GET or a mutation, so a
 * later JS enhancement layer can apply one update routine no matter which endpoint answered.
 * {@see ShopCsrfGuard} is registered on the four mutation routes only (never the two GETs) and
 * has already verified the request's origin by the time any method here runs.
 *
 * Cart identity is entirely cookie-driven ({@see CartCookie}) — no route/body parameter ever
 * carries the cart token (spec §6: "never in markup, JS storage, or query strings"). `add()`
 * and `update()` always call {@see CartService::putLine()} (desired quantity), NEVER the
 * incrementing `addLine()`: an identical replay converges to one line at the submitted quantity
 * instead of stacking (spec §6/§11). The FIRST mutation seen with no resolvable cart cookie
 * mints a cart via {@see CartService::create()} and sets the cookie on the response — even when
 * the mutation itself goes on to fail validation, since the cart row now genuinely exists.
 */
final class ShopCartController
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly CartService $carts,
        private readonly CartCookie $cookie,
        private readonly ShopUrlGenerator $urls,
        private readonly CanonicalPublicOriginResolver $resolver,
        private readonly TwigFactory $twigFactory,
        private readonly RenderContextExtension $extension,
    ) {
    }

    public function add(Request $request): Response
    {
        return $this->mutate($request, function (array $cart) use ($request): array {
            $variantUuid = $this->requiredString($request, 'variant_uuid');
            $quantity = $this->optionalQuantity($request, 1);
            if ($quantity < 1) {
                throw ValidationException::forField('quantity', 'Quantity must be greater than zero.');
            }

            return $this->carts->putLine($this->context, $cart, $variantUuid, $quantity, $this->addons($request));
        });
    }

    public function update(Request $request): Response
    {
        return $this->mutate($request, function (array $cart) use ($request): array {
            $variantUuid = $this->requiredString($request, 'variant_uuid');
            $quantity = $this->requiredQuantity($request);

            return $this->carts->putLine($this->context, $cart, $variantUuid, $quantity, $this->addons($request));
        });
    }

    /** Convergent removal: `putLine(..., 0)` (never a raw line-delete keyed on an internal uuid). */
    public function remove(Request $request): Response
    {
        return $this->mutate($request, function (array $cart) use ($request): array {
            $variantUuid = $this->requiredString($request, 'variant_uuid');

            return $this->carts->putLine($this->context, $cart, $variantUuid, 0, $this->addons($request));
        });
    }

    /** One route, both directions: a non-empty `code` applies it, an empty/absent `code` removes it. */
    public function discount(Request $request): Response
    {
        return $this->mutate($request, function (array $cart) use ($request): array {
            $code = trim((string) ($this->input($request)['code'] ?? ''));

            return $code !== ''
                ? $this->carts->applyDiscount($this->context, $cart, $code)
                : $this->carts->removeDiscount($this->context, $cart);
        });
    }

    /** `GET /_shop/cart` — mini-cart JSON hydration (spec §9): `private, no-store`, noindex. */
    public function show(Request $request): Response
    {
        return $this->noStore(new JsonResponse($this->currentViewModel($request)->toArray()));
    }

    /** `GET /cart` — the themed cart page (spec §3/§9): `private, no-store`, noindex. */
    public function page(Request $request): Response
    {
        $vm = $this->currentViewModel($request);
        $html = $this->renderCartPage($request, $vm);

        return $this->noStore(new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']));
    }

    // ------------------------------------------------------------------
    // mutation orchestration
    // ------------------------------------------------------------------

    /** @param callable(array<string,mixed>): array<string,mixed> $operation */
    private function mutate(Request $request, callable $operation): Response
    {
        $token = $this->cookie->read($request);
        $cart = $token !== null ? $this->carts->byToken($this->context, $token) : null;

        $mintedToken = null;
        if ($cart === null) {
            $created = $this->carts->create($this->context);
            $cart = $created['cart'];
            $mintedToken = $created['token'];
        }

        $errors = null;
        try {
            $cart = $operation($cart);
        } catch (ValidationException $e) {
            $errors = $e->firstErrors();
        }

        $vm = CartViewModel::fromView($this->context, $this->carts->view($this->context, $cart), $this->urls);

        return $this->respond($request, $vm, $mintedToken, $errors);
    }

    private function currentViewModel(Request $request): CartViewModel
    {
        $token = $this->cookie->read($request);
        $cart = $token !== null ? $this->carts->byToken($this->context, $token) : null;

        return $cart !== null
            ? CartViewModel::fromView($this->context, $this->carts->view($this->context, $cart), $this->urls)
            : CartViewModel::empty($this->context, $this->urls);
    }

    // ------------------------------------------------------------------
    // response negotiation (mirrors the engine app's own public form-submission controller's
    // respond() method — same Accept-negotiated JSON-vs-PRG split, outside this pack's namespace)
    // ------------------------------------------------------------------

    /** @param array<string,string>|null $errors */
    private function respond(Request $request, CartViewModel $vm, ?string $mintedToken, ?array $errors): Response
    {
        $response = str_contains((string) $request->headers->get('Accept'), 'application/json')
            ? $this->jsonResponse($vm, $errors)
            : $this->redirectResponse($request, $errors === null);

        if ($mintedToken !== null) {
            $this->cookie->write($response, $mintedToken, $this->context);
        }

        return $this->noStore($response);
    }

    /** @param array<string,string>|null $errors */
    private function jsonResponse(CartViewModel $vm, ?array $errors): Response
    {
        return $errors === null
            ? new JsonResponse($vm->toArray())
            : new JsonResponse(['errors' => $errors], 422);
    }

    private function redirectResponse(Request $request, bool $ok): Response
    {
        $target = $this->sameOriginRefererPath($request) ?? $this->urls->cart();
        if (!$ok) {
            $target .= (str_contains($target, '?') ? '&' : '?') . 'cart_err=1';
        }

        return new RedirectResponse($target, 303);
    }

    /**
     * A safe PRG redirect target (spec §6: "303 to `cart()` (or `Referer`-returned shop page
     * when same-origin)"). Independently re-validates the `Referer`'s origin here (the CSRF
     * guard only ever checks `Referer` when `Origin` is ABSENT) so a same-origin `Origin` +
     * attacker-influenced `Referer` combination can never turn into an open redirect — only the
     * path+query survive, never the scheme/host, so this is always a same-origin relative
     * location regardless.
     */
    private function sameOriginRefererPath(Request $request): ?string
    {
        $referer = $request->headers->get('Referer');
        if (!is_string($referer) || $referer === '') {
            return null;
        }
        if (!ShopCsrfGuard::originsMatch($referer, $this->resolver->currentOrigin($this->context))) {
            return null;
        }

        $path = parse_url($referer, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            $path = '/';
        }
        $query = parse_url($referer, PHP_URL_QUERY);

        return is_string($query) && $query !== '' ? $path . '?' . $query : $path;
    }

    private function noStore(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex');

        return $response;
    }

    // ------------------------------------------------------------------
    // input reading (form fields merged with a JSON body — mirrors
    // Glueful\Extensions\Commerce\Http\Storefront\ReadsStorefrontInput)
    // ------------------------------------------------------------------

    /** @return array<string,mixed> */
    private function input(Request $request): array
    {
        $content = $request->getContent();
        $json = is_string($content) && $content !== '' ? json_decode($content, true) : null;

        return array_merge($request->request->all(), is_array($json) ? $json : []);
    }

    private function requiredString(Request $request, string $field): string
    {
        $value = $this->input($request)[$field] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw ValidationException::forField($field, "The {$field} field is required.");
        }

        return trim($value);
    }

    private function requiredQuantity(Request $request): int
    {
        $value = $this->input($request)['quantity'] ?? null;
        if (!is_numeric($value)) {
            throw ValidationException::forField('quantity', 'The quantity field is required.');
        }

        return (int) $value;
    }

    private function optionalQuantity(Request $request, int $default): int
    {
        $value = $this->input($request)['quantity'] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }

    /** @return list<array{addon_uuid:string,choice_key?:string,value?:mixed}> */
    private function addons(Request $request): array
    {
        $value = $this->input($request)['addons'] ?? null;

        return is_array($value) ? $value : [];
    }

    // ------------------------------------------------------------------
    // cart page rendering (mirrors ShopCatalogController::render()'s reset-before-render
    // discipline against the SAME process-shared RenderContextExtension/TwigFactory)
    // ------------------------------------------------------------------

    private function renderCartPage(Request $request, CartViewModel $vm): string
    {
        $env = $this->twigFactory->environment();
        $locale = (string) config($this->context, 'i18n.default_locale', 'en');

        $this->extension->resetTags();
        $this->extension->resetBlockDepth();
        $this->extension->resetBlockFrames();
        $this->extension->setAssetBase(null);
        $this->extension->setBlockAnnotations(false);
        $this->extension->setThemeAppearanceOverride(null, null);
        $this->extension->setLocale($locale);

        return $env->render('shop/cart.twig', [
            'site' => [
                'name' => (string) config($this->context, 'render.site_name', 'Thallo'),
                'locale' => $locale,
                'locales' => [],
            ],
            'current_path' => RenderPageCache::normalizePath($request->getPathInfo()),
            'presentation' => [
                'show_title' => true,
                'layout' => 'centered',
                'header' => 'default',
                'footer' => 'default',
            ],
            'cart' => $vm,
            'shop_index_url' => $this->urls->shopIndex(),
        ]);
    }
}
