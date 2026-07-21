<?php

declare(strict_types=1);

namespace Thallo\Commerce\Http\Shop;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Cart\CartService;
use Glueful\Extensions\Commerce\Marketplace\CheckoutConflictException;
use Glueful\Extensions\Commerce\Orders\CheckoutAttemptContext;
use Glueful\Extensions\Commerce\Orders\CheckoutPresentation;
use Glueful\Extensions\Commerce\Orders\CheckoutService;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Support\TokenHasher;
use Glueful\Extensions\Commerce\Tenancy\CommerceTenantResolution;
use Glueful\Validation\ValidationException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Thallo\Commerce\Shop\ShopUrlGenerator;
use Thallo\Commerce\Shop\ViewModels\CartViewModel;
use Thallo\Commerce\Shop\ViewModels\CheckoutViewModel;
use Thallo\Commerce\Shop\ViewModels\ConfirmationViewModel;
use Thallo\Render\Http\Middleware\RenderPageCache;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\TwigFactory;

use function config;

/**
 * `GET /checkout`, `POST /_shop/checkout/quote|place`, and the ownership-protected
 * `GET /checkout/return/{ref}` / `GET /checkout/cancel/{ref}` / `GET /checkout/confirmation/{ref}`
 * routes (storefront-rendering spec §3/§6/§7/§8). `place()` is the durable-idempotency entry
 * point: it resolves the caller's idempotency key + a fingerprint of the canonicalized payload,
 * passes both through to {@see CheckoutService::placeOrder()} as a
 * {@see CheckoutAttemptContext}, stores the returned guest credential into
 * {@see GuestOrderCookie}, and renders/returns the closed {@see CheckoutPresentation} VM —
 * NEVER the raw `payment` array `placeOrder()` returns. The return/cancel/confirmation routes
 * share one ownership check ({@see self::ownedOrder()}) and NEVER mutate payment state: each
 * only re-reads the order row already committed by Commerce's own placement/payment-confirmation
 * transactions.
 */
final class ShopCheckoutController
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly CartService $carts,
        private readonly CartCookie $cartCookie,
        private readonly GuestOrderCookie $guestCookie,
        private readonly CheckoutService $checkout,
        private readonly CheckoutPresentation $presentation,
        private readonly CommerceTenantResolution $tenants,
        private readonly OrderRepository $orders,
        private readonly ShopUrlGenerator $urls,
        private readonly TwigFactory $twigFactory,
        private readonly RenderContextExtension $extension,
    ) {
    }

    /** `GET /checkout` — private, no-store; mints a fresh idempotency key for the no-JS form. */
    public function page(Request $request): Response
    {
        return $this->noStore($this->render($request, 'shop/checkout.twig', [
            'checkout' => $this->buildCheckoutViewModel($request),
        ]));
    }

    /** `POST /_shop/checkout/quote` — a preview of totals/shipping options; never mutates. */
    public function quote(Request $request): Response
    {
        $token = $this->cartCookie->read($request);
        $cart = $token !== null ? $this->carts->byToken($this->context, $token) : null;
        if ($cart === null) {
            return $this->noStore(new JsonResponse(['totals' => null, 'shipping_options' => []]));
        }

        $input = $this->input($request);
        $shippingAddress = is_array($input['addresses']['shipping'] ?? null) ? $input['addresses']['shipping'] : [];
        $shippingMethodId = $this->optionalString($input, 'shipping_method_id');

        try {
            $result = $this->checkout->quote($this->context, $cart, $shippingAddress, $shippingMethodId);
        } catch (ValidationException $e) {
            return $this->noStore(new JsonResponse(['errors' => $e->firstErrors()], 422));
        }

        $totals = $result['totals'];
        return $this->noStore(new JsonResponse([
            'totals' => [
                'subtotal' => $totals->subtotal,
                'discount_total' => $totals->discountTotal,
                'shipping_total' => $totals->shippingTotal,
                'tax_total' => $totals->taxTotal,
                'grand_total' => $totals->grandTotal,
            ],
            'shipping_options' => array_map(
                static fn ($o): array => ['id' => $o->id, 'label' => $o->label, 'amount' => $o->amount],
                $result['shipping_options']
            ),
        ]));
    }

    /**
     * `POST /_shop/checkout/place` — the durable-idempotency placement entry point (spec §7).
     * The idempotency key comes from the `X-Idempotency-Key` JS header or the no-JS form's
     * `idempotency_key` field (minted by {@see self::page()}); the fingerprint is a sha256 of
     * the canonicalized buyer/address/shipping payload — NEVER the cart contents, which the
     * attempt authority never sees at all.
     */
    public function place(Request $request): Response
    {
        $key = $this->idempotencyKey($request);
        if ($key === null) {
            return $this->placeError($request, ['idempotency_key' => ['An idempotency key is required.']], 422);
        }

        $input = $this->input($request);
        $email = trim((string) ($input['email'] ?? ''));
        if ($email === '') {
            return $this->placeError($request, ['email' => ['The email field is required.']], 422);
        }
        $addresses = is_array($input['addresses'] ?? null) ? $input['addresses'] : [];
        $shippingMethodId = $this->optionalString($input, 'shipping_method_id');
        $fingerprint = self::canonicalFingerprint($email, $addresses, $shippingMethodId);
        $cartToken = $this->cartCookie->read($request) ?? '';

        try {
            $result = $this->checkout->placeOrder(
                $this->context,
                $cartToken,
                ['email' => $email, 'user_uuid' => null],
                $addresses,
                $shippingMethodId,
                new CheckoutAttemptContext($key, $fingerprint),
            );
        } catch (CheckoutConflictException $e) {
            return $this->placeError($request, ['idempotency_key' => [$e->getMessage()]], 409);
        } catch (ValidationException $e) {
            return $this->placeError($request, $e->firstErrors(), 422);
        }

        $order = $result['order'];
        $orderRef = (string) $order['order_number'];
        $tenant = $this->tenants->tenantUuid($this->context);
        $paymentVm = $this->presentation->present($result['payment']);
        $confirmationUrl = $this->urls->confirmation($orderRef);

        $response = $this->buildPlaceResponse($request, $paymentVm, $order, $confirmationUrl);
        $this->guestCookie->remember($response, $request, $this->context, $tenant, $orderRef, $result['guest_token']);

        return $this->noStore($response);
    }

    /** `GET /checkout/return/{ref}` — read-only; never marks anything paid (spec §8). */
    public function paymentReturn(Request $request, string $ref): Response
    {
        return $this->redirectToConfirmationOrNotFound($request, $ref);
    }

    /** `GET /checkout/cancel/{ref}` — identical read-only ownership check + redirect. */
    public function paymentCancel(Request $request, string $ref): Response
    {
        return $this->redirectToConfirmationOrNotFound($request, $ref);
    }

    /** `GET /checkout/confirmation/{ref}` — the only route that renders order state. */
    public function confirmation(Request $request, string $ref): Response
    {
        $order = $this->ownedOrder($request, $ref);
        if ($order === null) {
            return $this->noStore($this->notFound($request));
        }

        $tenant = $this->tenants->tenantUuid($this->context);
        $events = $this->orders->eventsForOrder($this->context, $tenant, (string) $order['uuid']);
        $vm = ConfirmationViewModel::fromOrder($order, $events);

        return $this->noStore($this->render($request, 'shop/confirmation.twig', [
            'confirmation' => $vm,
            'payment' => null,
        ]));
    }

    // ------------------------------------------------------------------
    // place() response shaping
    // ------------------------------------------------------------------

    /**
     * @param array<string,mixed> $paymentVm {@see CheckoutPresentation::present()}'s closed shape
     * @param array<string,mixed> $order
     */
    private function buildPlaceResponse(
        Request $request,
        array $paymentVm,
        array $order,
        string $confirmationUrl
    ): Response {
        $wantsJson = str_contains((string) $request->headers->get('Accept'), 'application/json');
        $jsonBody = $paymentVm + [
            'order_ref' => (string) $order['order_number'],
            'confirmation_url' => $confirmationUrl,
        ];

        if (($paymentVm['action'] ?? null) === 'redirect') {
            return $wantsJson
                ? new JsonResponse($jsonBody)
                : new RedirectResponse((string) $paymentVm['redirect_url'], 303);
        }

        if ($wantsJson) {
            return new JsonResponse($jsonBody);
        }

        // No navigable URL for manual/reference/unavailable — render the result inline (200)
        // rather than a redirect to nowhere; a non-JS client sees it immediately.
        $tenant = $this->tenants->tenantUuid($this->context);
        $events = $this->orders->eventsForOrder($this->context, $tenant, (string) $order['uuid']);
        $vm = ConfirmationViewModel::fromOrder($order, $events);

        return $this->render($request, 'shop/confirmation.twig', [
            'confirmation' => $vm,
            'payment' => $paymentVm,
            'confirmation_url' => $confirmationUrl,
        ]);
    }

    /** @param array<string,list<string>> $errors */
    private function placeError(Request $request, array $errors, int $status): Response
    {
        if (str_contains((string) $request->headers->get('Accept'), 'application/json')) {
            return $this->noStore(new JsonResponse(['errors' => $errors], $status));
        }

        // No-JS PRG: there is no session/flash mechanism here (spec §6/§9 keep checkout
        // private/no-store, never session-backed) — the checkout page itself re-mints a fresh
        // idempotency key, so a failed no-JS submission simply redirects back with a query flag.
        return $this->noStore(new RedirectResponse($this->urls->checkout() . '?checkout_err=' . $status, 303));
    }

    private function redirectToConfirmationOrNotFound(Request $request, string $ref): Response
    {
        $order = $this->ownedOrder($request, $ref);
        if ($order === null) {
            return $this->noStore($this->notFound($request));
        }

        return $this->noStore(new RedirectResponse($this->urls->confirmation($ref), 303));
    }

    /**
     * The shared ownership check (spec §6): decrypt the guest cookie, find the entry for
     * `$ref`, re-read the order, and verify the credential hashes to that order's OWN
     * `guest_token_hash` — a wrong or absent credential (or a ref never seen by this browser)
     * resolves to null uniformly, never revealing whether the order itself exists.
     *
     * @return array<string,mixed>|null
     */
    private function ownedOrder(Request $request, string $ref): ?array
    {
        $tenant = $this->tenants->tenantUuid($this->context);
        $token = $this->guestCookie->credentialFor($request, $tenant, $ref);
        if ($token === null) {
            return null;
        }

        $order = $this->orders->findByNumber($this->context, $tenant, $ref);
        if ($order === null) {
            return null;
        }

        if (!hash_equals((string) $order['guest_token_hash'], TokenHasher::hash($token))) {
            return null;
        }

        return $order;
    }

    private function buildCheckoutViewModel(Request $request): CheckoutViewModel
    {
        $token = $this->cartCookie->read($request);
        $cart = $token !== null ? $this->carts->byToken($this->context, $token) : null;
        $cartVm = $cart !== null
            ? CartViewModel::fromView($this->context, $this->carts->view($this->context, $cart), $this->urls)
            : CartViewModel::empty($this->context, $this->urls);

        return new CheckoutViewModel($cartVm, bin2hex(random_bytes(16)));
    }

    private function idempotencyKey(Request $request): ?string
    {
        $header = $request->headers->get('X-Idempotency-Key');
        if (is_string($header) && trim($header) !== '') {
            return self::normalizeKey(trim($header));
        }

        $field = $this->input($request)['idempotency_key'] ?? null;

        return is_string($field) && trim($field) !== '' ? self::normalizeKey(trim($field)) : null;
    }

    /**
     * Collapse a client-supplied idempotency key to a fixed 64-char width. A key is only ever
     * compared for equality across a customer's own retries, so hashing preserves the
     * same-key-same-replay contract while guaranteeing it fits the storage column — a
     * pathologically long key can never overflow it into an uncaught 500 on the money path
     * (slice-2 Task 10 review).
     */
    private static function normalizeKey(string $key): string
    {
        return hash('sha256', $key);
    }

    /**
     * sha256 of the canonicalized (sorted-key) checkout payload — deterministic regardless of
     * submitted key order, so an identical retry always produces the same fingerprint.
     *
     * @param array<string,mixed> $addresses
     */
    private static function canonicalFingerprint(string $email, array $addresses, ?string $shippingMethodId): string
    {
        $payload = [
            'addresses' => $addresses,
            'email' => strtolower($email),
            'shipping_method_id' => $shippingMethodId,
        ];
        self::ksortRecursive($payload);

        return hash('sha256', (string) json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /** @param array<string,mixed> $array */
    private static function ksortRecursive(array &$array): void
    {
        ksort($array);
        foreach ($array as &$value) {
            if (is_array($value)) {
                self::ksortRecursive($value);
            }
        }
    }

    // ------------------------------------------------------------------
    // input reading (mirrors ShopCartController::input())
    // ------------------------------------------------------------------

    /** @return array<string,mixed> */
    private function input(Request $request): array
    {
        $content = $request->getContent();
        $json = is_string($content) && $content !== '' ? json_decode($content, true) : null;

        return array_merge($request->request->all(), is_array($json) ? $json : []);
    }

    /** @param array<string,mixed> $input */
    private function optionalString(array $input, string $field): ?string
    {
        $value = $input[$field] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    // ------------------------------------------------------------------
    // rendering (mirrors ShopCatalogController::render()'s reset-before-render discipline)
    // ------------------------------------------------------------------

    /** @param array<string,mixed> $extra */
    private function render(Request $request, string $template, array $extra, int $status = 200): Response
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

        $context = [
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
        ] + $extra;

        $html = $env->render($template, $context);

        return new Response($html, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    private function notFound(Request $request): Response
    {
        return $this->render($request, '404.twig', [], 404);
    }

    private function noStore(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex');

        return $response;
    }
}
