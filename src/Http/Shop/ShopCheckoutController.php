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
use Glueful\Extensions\Commerce\Support\CommerceSettings;
use Glueful\Extensions\Commerce\Support\Money;
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
use Thallo\Contracts\Account\StorefrontAccountIdentityReader;
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
        /** Optional signed-in email resolution (checkout-ui plan Task 3); null = anonymous only. */
        private readonly ?StorefrontAccountIdentityReader $identity = null,
    ) {
    }

    /** `GET /checkout` — private, no-store; mints a fresh idempotency key for the no-JS form. */
    public function page(Request $request): Response
    {
        return $this->noStore($this->renderCheckout(
            $request,
            ['email' => $this->signedInEmail($request)] + self::emptySubmission(),
            null,
            [],
        ));
    }

    /**
     * `POST /checkout` — the no-JS quote leg (checkout-ui plan Task 3). Quote is non-mutating, and
     * there is no session/flash authority that could carry address/errors/idempotency across a
     * 303, so this renders DIRECTLY (200 valid, 422 invalid) with every submitted value, the
     * quote result, and the submitted idempotency key preserved verbatim. With
     * `Accept: application/json` it returns the exact `/_shop/checkout/quote` shape — one shared
     * projector, never a forked validation or totals path.
     */
    public function quotePage(Request $request): Response
    {
        $submission = $this->submissionFrom($this->input($request));
        $projection = $this->quoteProjection(
            $request,
            $submission['shipping_address'],
            $submission['shipping_method_id'] !== '' ? $submission['shipping_method_id'] : null,
        );
        $errors = $projection['errors'] ?? [];
        $status = $errors === [] ? 200 : 422;

        if (str_contains((string) $request->headers->get('Accept'), 'application/json')) {
            return $this->noStore(new JsonResponse($projection, $status));
        }

        return $this->noStore($this->renderCheckout(
            $request,
            $submission,
            $errors === [] ? $projection : null,
            $errors,
            $status,
        ));
    }

    /** `POST /_shop/checkout/quote` — a preview of totals/shipping options; never mutates. */
    public function quote(Request $request): Response
    {
        $input = $this->input($request);
        $shippingAddress = is_array($input['addresses']['shipping'] ?? null) ? $input['addresses']['shipping'] : [];
        $projection = $this->quoteProjection(
            $request,
            $shippingAddress,
            $this->optionalString($input, 'shipping_method_id'),
        );

        return $this->noStore(new JsonResponse($projection, isset($projection['errors']) ? 422 : 200));
    }

    /**
     * The ONE quote projection both the JSON endpoint and the no-JS page render consume —
     * validation and totals shape can never fork between them.
     *
     * @param array<string,mixed> $shippingAddress
     * @return array{totals: array<string,int>|null, shipping_options: list<array{id: string,
     *   label: string, amount: int}>}|array{errors: array<string, list<string>|string>}
     */
    private function quoteProjection(Request $request, array $shippingAddress, ?string $shippingMethodId): array
    {
        $token = $this->cartCookie->read($request);
        $cart = $token !== null ? $this->carts->byToken($this->context, $token) : null;
        if ($cart === null) {
            return ['totals' => null, 'shipping_options' => []];
        }

        try {
            $result = $this->checkout->quote($this->context, $cart, $shippingAddress, $shippingMethodId);
        } catch (ValidationException $e) {
            return ['errors' => $e->firstErrors()];
        }

        $totals = $result['totals'];
        // ADDITIVE beside the raw ints (existing JSON consumers untouched): exponent-aware
        // formatted strings for the page render + JS text patching, in the store currency.
        $currency = CommerceSettings::currency($this->context);

        return [
            'totals' => [
                'subtotal' => $totals->subtotal,
                'discount_total' => $totals->discountTotal,
                'shipping_total' => $totals->shippingTotal,
                'tax_total' => $totals->taxTotal,
                'grand_total' => $totals->grandTotal,
            ],
            'totals_formatted' => [
                'subtotal' => Money::format($totals->subtotal, $currency),
                'discount_total' => Money::format($totals->discountTotal, $currency),
                'shipping_total' => Money::format($totals->shippingTotal, $currency),
                'tax_total' => Money::format($totals->taxTotal, $currency),
                'grand_total' => Money::format($totals->grandTotal, $currency),
            ],
            'currency' => $currency,
            'shipping_options' => array_map(
                static fn ($o): array => [
                    'id' => $o->id,
                    'label' => $o->label,
                    'amount' => $o->amount,
                    'amount_formatted' => Money::format($o->amount, $currency),
                ],
                $result['shipping_options']
            ),
        ];
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
                // Authenticated visitors stamp order ownership; anonymous stays null (identical
                // to before). The `user` attribute is the post-auth principal auth:optional sets.
                ['email' => $email, 'user_uuid' => $this->signedInUuid($request)],
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

    /** @param array<string,list<string>|string> $errors */
    private function placeError(Request $request, array $errors, int $status): Response
    {
        if (str_contains((string) $request->headers->get('Accept'), 'application/json')) {
            return $this->noStore(new JsonResponse(['errors' => $errors], $status));
        }

        // State-preserving render (checkout-ui plan Task 3, replacing the lossy 303/?checkout_err
        // flag): a no-JS placement failure re-renders the checkout page with the submitted values,
        // the field errors, a fresh best-effort quote, and the SUBMITTED idempotency key reused
        // verbatim — same-key retries stay idempotent. Quote errors merge under the place errors.
        $submission = $this->submissionFrom($this->input($request));
        $projection = $this->quoteProjection(
            $request,
            $submission['shipping_address'],
            $submission['shipping_method_id'] !== '' ? $submission['shipping_method_id'] : null,
        );
        $quote = isset($projection['errors']) ? null : $projection;

        return $this->noStore($this->renderCheckout($request, $submission, $quote, $errors, $status));
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

    // ------------------------------------------------------------------
    // checkout page rendering (GET page / POST quote render / place errors)
    // ------------------------------------------------------------------

    /**
     * The one checkout-page render: cart summary + idempotency key (submitted one reused
     * verbatim, else freshly minted), the submitted field values, the quote projection when one
     * succeeded, and field errors. Every caller wraps it in noStore().
     *
     * @param array{email: string, shipping_address: array<string,string>, shipping_method_id:
     *   string, idempotency_key: string} $submission
     * @param array{totals: array<string,int>|null, shipping_options: list<array{id: string,
     *   label: string, amount: int}>}|null $quote
     * @param array<string,list<string>|string> $errors
     */
    private function renderCheckout(
        Request $request,
        array $submission,
        ?array $quote,
        array $errors,
        int $status = 200
    ): Response {
        $token = $this->cartCookie->read($request);
        $cart = $token !== null ? $this->carts->byToken($this->context, $token) : null;
        $cartVm = $cart !== null
            ? CartViewModel::fromView($this->context, $this->carts->view($this->context, $cart), $this->urls)
            : CartViewModel::empty($this->context, $this->urls);
        $key = $submission['idempotency_key'] !== '' ? $submission['idempotency_key'] : bin2hex(random_bytes(16));

        return $this->render($request, 'shop/checkout.twig', [
            'checkout' => new CheckoutViewModel($cartVm, $key),
            'submitted' => $submission,
            'quote' => $quote,
            'errors' => $this->flattenErrors($errors),
        ], $status);
    }

    /**
     * Normalize the submitted checkout fields (shared by the quote render and place-error
     * render). Missing fields normalize to '' so templates never branch on key existence.
     *
     * @param array<string,mixed> $input
     * @return array{email: string, shipping_address: array<string,string>, shipping_method_id:
     *   string, idempotency_key: string}
     */
    private function submissionFrom(array $input): array
    {
        $shippingRaw = is_array($input['addresses']['shipping'] ?? null) ? $input['addresses']['shipping'] : [];
        $shipping = [];
        foreach (['name', 'line1', 'line2', 'city', 'state', 'postcode', 'country'] as $field) {
            $value = $shippingRaw[$field] ?? '';
            $shipping[$field] = is_string($value) ? trim($value) : '';
        }

        return [
            'email' => is_string($input['email'] ?? null) ? trim((string) $input['email']) : '',
            'shipping_address' => $shipping,
            'shipping_method_id' => is_string($input['shipping_method_id'] ?? null)
                ? trim((string) $input['shipping_method_id'])
                : '',
            'idempotency_key' => is_string($input['idempotency_key'] ?? null)
                ? trim((string) $input['idempotency_key'])
                : '',
        ];
    }

    /**
     * @return array{email: string, shipping_address: array<string,string>, shipping_method_id:
     *   string, idempotency_key: string}
     */
    private static function emptySubmission(): array
    {
        return [
            'email' => '',
            'shipping_address' => [
                'name' => '', 'line1' => '', 'line2' => '', 'city' => '',
                'state' => '', 'postcode' => '', 'country' => '',
            ],
            'shipping_method_id' => '',
            'idempotency_key' => '',
        ];
    }

    /**
     * One message per field for template rendering (commerce reports both `field => message` and
     * `field => [messages]` shapes across its throws).
     *
     * @param array<string,list<string>|string> $errors
     * @return array<string,string>
     */
    private function flattenErrors(array $errors): array
    {
        $flat = [];
        foreach ($errors as $field => $messages) {
            $flat[$field] = is_array($messages) ? (string) ($messages[0] ?? '') : (string) $messages;
        }

        return $flat;
    }

    /** The post-auth principal's uuid (auth:optional), or null for an anonymous visitor. */
    private function signedInUuid(Request $request): ?string
    {
        $user = $request->attributes->get('user');
        $uuid = is_array($user) ? (string) ($user['uuid'] ?? '') : '';

        return $uuid !== '' ? $uuid : null;
    }

    /** The signed-in visitor's account email via the optional identity reader; fail-soft null. */
    private function signedInEmail(Request $request): string
    {
        $uuid = $this->signedInUuid($request);
        if ($uuid === null || $this->identity === null) {
            return '';
        }

        return (string) ($this->identity->emailFor($uuid) ?? '');
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
        $this->extension->resetPerRenderState();
        $this->extension->setAssetContext(null, null);
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
