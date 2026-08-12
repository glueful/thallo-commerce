<?php

declare(strict_types=1);

namespace Thallo\Commerce\Http\Shop;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Orders\LinkView;
use Glueful\Extensions\Commerce\Orders\PaymentLinkException;
use Glueful\Extensions\Commerce\Orders\PaymentLinkService;
use Glueful\Extensions\Commerce\Support\Money;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Thallo\Commerce\Payments\PaymentLinkReturnSigner;
use Thallo\Commerce\Shop\ShopUrlGenerator;

/**
 * THE PUBLIC PAYMENT-LINK SURFACE (payment-links spec §2.3): the landing page a payer opens, the
 * no-JS POST that turns a click into a gateway checkout, and the two signed, NON-AUTHORIZING
 * receipts a provider returns the browser to.
 *
 * Every route here is UNAUTHENTICATED, and the only credential in play is a bearer token in a
 * URL. That single fact shapes all four handlers.
 *
 * ## 1. The token never leaves this class's parameters
 *
 * The shape gate (`/\A[a-f0-9]{64}\z/`, the engine's own {@see PaymentLinkService::TOKEN_PATTERN})
 * runs BEFORE the engine is called, so garbage never reaches a query — and the parameter is
 * OVERWRITTEN the moment it has been consumed, because PHP records call arguments in exception
 * backtraces and an unrelated throwable would otherwise put a live credential into an error log.
 * It is never logged. The one place it is echoed is the Pay form's own `action`, which is the
 * URL the payer is already on.
 *
 * Renders deliberately pass a TOKEN-FREE path to {@see ShopPageRenderer} ({@see self::RENDER_PATH})
 * rather than the real request path: the renderer publishes `current_path` into the Twig context
 * for nav active-state comparison, and a bearer credential has no business being reachable by
 * any template. It also makes the generic 404 BYTE-IDENTICAL across unknown, malformed, and
 * cross-tenant tokens, which spec §2.3 requires — a per-token `current_path` could not be.
 *
 * ## 2. One generic 404, three causes
 *
 * A malformed token, an unknown one, and another store's perfectly valid one all render the same
 * `404.twig` bytes with the same headers. The engine hands back ONE generic null for the last
 * two; the shape gate produces the same answer for the first.
 *
 * ## 3. Every initiation failure is a rendered state, never a redirect
 *
 * {@see PaymentLinkService::initiateByToken()} publishes a closed set of typed refusals, and
 * documents that a driver failure or a caller bug (an ambient transaction) still escapes
 * UNTYPED. So this controller catches {@see PaymentLinkException} for the typed set and
 * `\Throwable` for everything else, and both land on an honest no-store page. `Location` is
 * emitted on exactly ONE path: a `checkoutUrl` that passed
 * {@see self::isRedirectableCheckoutUrl()} — an INDEPENDENT final re-validation performed here,
 * in the controller, after the engine's own. Two checks over a browser-facing redirect target is
 * the point, not duplication.
 *
 * ## 4. The receipts authorize nothing
 *
 * They verify a purpose-bound signature ({@see PaymentLinkReturnSigner}) and then render one
 * generic sentence. No order, no link uuid, no totals, no cookie, no redirect into an owned
 * page. The pre-existing guest-cookie `/checkout/confirmation/{ref}` flow is untouched and
 * unreachable from here.
 */
final class ShopPaymentLinkController
{
    /** The engine's token shape, gated here BEFORE any engine call. */
    private const TOKEN_PATTERN = '/\A[a-f0-9]{64}\z/';

    /** The bounded link-uuid shape the receipt routes accept before verifying a signature. */
    private const LINK_UUID_PATTERN = '/\A[A-Za-z0-9_-]{1,64}\z/';

    /** What a raw-token parameter is overwritten with once consumed (mirrors the engine). */
    private const REDACTED_TOKEN = '[redacted]';

    /**
     * The TOKEN-FREE path every render on this surface reports as `current_path`. See the class
     * docblock: it keeps a bearer credential out of the Twig context and makes the generic 404
     * byte-identical regardless of what was in the URL.
     */
    private const RENDER_PATH = '/checkout/pay';

    public function __construct(
        private readonly ApplicationContext $context,
        private readonly PaymentLinkService $links,
        private readonly ShopUrlGenerator $urls,
        private readonly ShopPageRenderer $pages,
        private readonly PaymentLinkReturnSigner $signer,
    ) {
    }

    // ==================================================================
    // GET /checkout/pay/{token}
    // ==================================================================

    /**
     * The landing page. Renders the LinkView's state and nothing the engine did not publish:
     * `active` gets the summary and the Pay form, `paid` a thank-you, and every terminal state
     * the honest "no longer valid — contact the merchant" page. A REVOKED link resolves
     * content-redacted (the engine presumes its holder hostile), and the template renders
     * exactly that — no commercial content is reconstructed from anywhere else.
     */
    public function landing(Request $request, string $token): Response
    {
        if (preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            $token = self::REDACTED_TOKEN;

            return $this->notFound();
        }

        try {
            $view = $this->links->resolveByToken($this->context, $token);
        } catch (\Throwable) {
            // Resolve raises nothing typed; anything at all here is infrastructure. Never a
            // message, never a stack trace — an honest unavailable page.
            $token = self::REDACTED_TOKEN;

            return $this->renderState('unavailable', 'unavailable', null, null, 503);
        }

        if ($view === null) {
            $token = self::REDACTED_TOKEN;

            return $this->notFound();
        }

        [$state, $reason] = self::classify($view);
        // The Pay form's action is the token's ONE legitimate appearance in the markup, and only
        // while the link can actually be paid.
        $payAction = $state === 'active' ? $this->urls->paymentLinkInitiate($token) : null;
        $token = self::REDACTED_TOKEN;

        return $this->renderState(
            $state,
            $reason,
            $state === 'active' || $state === 'paid' ? self::summary($view) : null,
            $payAction,
            $state === 'invalid' ? 410 : 200,
        );
    }

    // ==================================================================
    // POST /checkout/pay/{token}/initiate
    // ==================================================================

    /**
     * Turn the no-JS Pay submission into a gateway checkout, and answer 303 ONLY for a URL that
     * passed an independent final absolute-HTTPS check in this method.
     *
     * The engine owns the money-path decisions (payability under lock, the per-link hourly
     * budget, the provider call outside any transaction, and the Phase-B recheck that refuses a
     * link the world moved past while the provider was thinking). This method owns exactly two
     * things: mapping every one of its outcomes to an honest page, and never emitting a
     * `Location` for any of them.
     */
    public function initiate(Request $request, string $token): Response
    {
        if (preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            $token = self::REDACTED_TOKEN;

            return $this->notFound();
        }

        try {
            $result = $this->links->initiateByToken($this->context, $token);
        } catch (PaymentLinkException $e) {
            $token = self::REDACTED_TOKEN;
            [$state, $reason, $status] = self::stateForErrorCode($e->errorCode);

            return $this->renderState($state, $reason, null, null, $status);
        } catch (\Throwable) {
            // The engine documents that a driver failure — and the ambient-transaction caller
            // bug — escape UNTYPED. Neither is a state, and neither may reach a payer as text.
            $token = self::REDACTED_TOKEN;

            return $this->renderState('unavailable', 'unavailable', null, null, 503);
        }
        $token = self::REDACTED_TOKEN;

        $checkoutUrl = (string) ($result['checkoutUrl'] ?? '');
        // THE INDEPENDENT FINAL CHECK. The engine validated this URL too; doing it again here is
        // deliberate — this is the single line in the whole surface that points a browser at a
        // third party, so it does not inherit its safety from another package's invariant.
        if (!self::isRedirectableCheckoutUrl($checkoutUrl)) {
            return $this->renderState('unavailable', 'unavailable', null, null, 503);
        }

        return $this->headers(new RedirectResponse($checkoutUrl, 303));
    }

    /**
     * Absolute HTTPS, a host, and NO userinfo — the last of which is not pedantry:
     * `https://psp.example.com@evil.example.com/x` parses as absolute HTTPS and reads to a human
     * as the payment provider's domain while actually navigating to the attacker's. This is the
     * URL a payer is sent to immediately before being asked for card details.
     *
     * A query string IS allowed: hosted checkout sessions legitimately carry one.
     */
    public static function isRedirectableCheckoutUrl(string $url): bool
    {
        if ($url === '') {
            return false;
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }

        if (strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || ($parts['host'] ?? '') === '') {
            return false;
        }

        return !isset($parts['user']) && !isset($parts['pass']);
    }

    /**
     * The engine's closed initiation error domain, mapped to this surface's states.
     *
     * `payment_link_not_payable` is the ONE generic refusal covering malformed/unknown/revoked/
     * superseded/expired/consumed/order-no-longer-payable AND the Phase-B race, so it renders
     * the same "no longer valid" page the landing route shows — the payer's remedy is identical.
     * Everything else is a store-side configuration or gateway problem the payer can do nothing
     * about, except the rate limit, which is the one genuinely transient refusal and the only
     * one where "try again shortly" is honest advice.
     *
     * @return array{0: string, 1: string, 2: int}
     */
    public static function stateForErrorCode(string $errorCode): array
    {
        return match ($errorCode) {
            PaymentLinkException::PAYMENT_LINK_NOT_PAYABLE => ['invalid', 'not_payable', 410],
            PaymentLinkException::INITIATION_RATE_LIMITED => ['unavailable', 'rate_limited', 429],
            default => ['unavailable', 'unavailable', 503],
        };
    }

    // ==================================================================
    // GET /checkout/pay/{return|cancel}/{linkUuid}/{signature}
    // ==================================================================

    /** The signed return receipt: "payment submitted; confirmation may take a moment." */
    public function paymentReturn(Request $request, string $linkUuid, string $signature): Response
    {
        return $this->receipt(PaymentLinkReturnSigner::PURPOSE_RETURN, $linkUuid, $signature);
    }

    /** The signed cancel receipt: "payment canceled; reopen the original link to retry." */
    public function paymentCancel(Request $request, string $linkUuid, string $signature): Response
    {
        return $this->receipt(PaymentLinkReturnSigner::PURPOSE_CANCEL, $linkUuid, $signature);
    }

    /**
     * One implementation for both handles, so purpose separation cannot drift between them: the
     * purpose is part of the SIGNED MESSAGE, so a return signature presented on the cancel route
     * simply does not verify, and vice versa — both collapse into the same generic 404 a hostile
     * signature gets.
     *
     * The receipt grants nothing and reveals nothing: it never reads the link table, never
     * touches the order, sets no cookie, and renders no field of either.
     */
    private function receipt(string $purpose, string $linkUuid, string $signature): Response
    {
        if (preg_match(self::LINK_UUID_PATTERN, $linkUuid) !== 1) {
            return $this->notFound();
        }

        try {
            $verified = $this->signer->verify($this->context, $purpose, $linkUuid, $signature);
        } catch (\Throwable) {
            // Fail closed: an unconfigured/undecodable app.key can verify nothing, and that is
            // indistinguishable from a forgery as far as this route is concerned.
            $verified = false;
        }

        if (!$verified) {
            return $this->notFound();
        }

        return $this->headers($this->pages->render($this->renderRequest(), 'shop/payment-link-receipt.twig', [
            'receipt' => ['purpose' => $purpose === PaymentLinkReturnSigner::PURPOSE_RETURN ? 'return' : 'cancel'],
        ]));
    }

    // ==================================================================
    // rendering
    // ==================================================================

    /**
     * The LinkView state pair, reduced to what the page has to say.
     *
     * REVOKED is checked through `contentRedacted` rather than by name: that flag IS the engine's
     * statement that this holder may see state only, and branching on it means a future redacted
     * state cannot accidentally render a bill.
     *
     * @return array{0: string, 1: string}
     */
    private static function classify(LinkView $view): array
    {
        if ($view->contentRedacted) {
            return ['invalid', 'revoked'];
        }

        if ($view->orderStatus === 'paid' || $view->linkStatus === 'consumed') {
            return ['paid', 'paid'];
        }

        if ($view->orderStatus === 'canceled' || $view->orderStatus === 'refunded') {
            return ['invalid', $view->orderStatus];
        }

        if ($view->linkStatus === 'expired') {
            return ['invalid', 'expired'];
        }

        if ($view->linkStatus === 'active' && $view->orderStatus === PaymentLinkService::PAYABLE_STATUS) {
            return ['active', 'active'];
        }

        // Anything else the engine can publish (revoked-by-name without redaction, a superseded
        // link, an order status this page has no opinion about) is honestly "not valid to pay".
        return ['invalid', 'unavailable'];
    }

    /**
     * The engine's allowlisted public projection, formatted. Nothing is added from any other
     * authority: no buyer identity, no addresses, no per-line prices — the engine excludes them
     * deliberately because a payment link is frequently forwarded.
     *
     * @return array<string,mixed>
     */
    private static function summary(LinkView $view): array
    {
        return [
            'order_number' => $view->orderNumber,
            'currency' => $view->currency,
            'lines' => $view->lines,
            'grand_total' => self::money($view->grandTotal, $view->currency),
            'subtotal' => self::money($view->subtotal, $view->currency),
            'discount_total' => self::money($view->discountTotal, $view->currency),
            'shipping_total' => self::money($view->shippingTotal, $view->currency),
            'tax_total' => self::money($view->taxTotal, $view->currency),
            'expires_at' => $view->expiresAt,
        ];
    }

    /** Money formatting must never be able to 500 a payer's page over a currency code. */
    private static function money(int $amount, string $currency): string
    {
        try {
            return Money::format($amount, $currency);
        } catch (\Throwable) {
            return (string) $amount;
        }
    }

    /**
     * @param array<string,mixed>|null $summary
     */
    private function renderState(
        string $state,
        string $reason,
        ?array $summary,
        ?string $payAction,
        int $status,
    ): Response {
        return $this->headers($this->pages->render($this->renderRequest(), 'shop/payment-link.twig', [
            'link' => [
                'state' => $state,
                'reason' => $reason,
                'summary' => $summary,
                'pay_action' => $payAction,
            ],
        ], $status));
    }

    /** The shared generic 404 — identical bytes for a malformed, unknown, or foreign token. */
    private function notFound(): Response
    {
        return $this->headers($this->pages->render($this->renderRequest(), '404.twig', [], 404));
    }

    /**
     * A token-free stand-in for the real request, used ONLY as the renderer's path source (see
     * the class docblock). {@see ShopPageRenderer} reads nothing else off it.
     */
    private function renderRequest(): Request
    {
        return Request::create(self::RENDER_PATH);
    }

    /** Spec §2.3's three headers, from the one shared definition. */
    private function headers(Response $response): Response
    {
        return ShopPaymentLinkHeaders::stamp($response);
    }
}
