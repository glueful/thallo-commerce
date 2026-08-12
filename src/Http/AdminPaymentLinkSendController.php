<?php

declare(strict_types=1);

namespace Thallo\Commerce\Http;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Contracts\PaymentLinkPublicUrlProvider;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Orders\PaymentLinkAdminView;
use Glueful\Extensions\Commerce\Orders\PaymentLinkException;
use Glueful\Extensions\Commerce\Orders\PaymentLinkService;
use Glueful\Extensions\Commerce\Support\Money;
use Glueful\Extensions\Commerce\Tenancy\CommerceTenantResolution;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Commerce\Email\PaymentRequestMailer;
use Thallo\Commerce\Email\PaymentRequestSendResult;
use Thallo\Commerce\Email\RichEmailAvailability;
use Thallo\Commerce\Payments\PaymentLinkDeliveryClaim;
use Thallo\Commerce\Payments\PaymentLinkDeliveryRepository;
use Thallo\Commerce\Shop\ViewModels\ShopMoney;

use function app;
use function config;

/**
 * `POST /v1/admin/commerce/orders/{uuid}/payment-link/send` — the ONE payment-link route this
 * pack owns (payment-links spec §2.4). Mint, revoke and status belong to the mounted Commerce
 * catalog ({@see \Glueful\Extensions\Commerce\Http\Admin\AdminOrderPaymentLinkController}); this
 * controller only DELIVERS, and `PaymentLinkSendTest`'s route-uniqueness assertions prove the
 * pack never shadows those three.
 *
 * MANAGE authority: a send both mails a bearer credential and, in `regenerate` mode, invalidates
 * the order's existing link.
 *
 * ## The two modes, and why `current` submits a token back
 *
 * The mint URL is one-time — the engine returns it once and can never re-read it (its table
 * holds only a hash). So an operator surface that wants to EMAIL the link it is currently showing
 * has to hand the value back, and the authority for "is that still this order's link?" is the
 * engine's {@see PaymentLinkService::matchCurrentToken()}, never a pack query and never a
 * predicate reconstructed here. Its answers map straight through:
 *
 *  - `null`                       -> 404. Unknown or cross-tenant order, indistinguishable.
 *  - throws `payment_link_changed` -> 409. The order is yours; that token is not its current link.
 *  - a {@see PaymentLinkAdminView} -> the link is current, and the send proceeds.
 *
 * `regenerate` submits no token at all: it claims the idempotency key FIRST, then calls
 * {@see PaymentLinkService::mintPublic()} (which revokes the predecessor inside its own
 * transaction) and mails the new URL. Claim-before-mint is the ordering §2.4 requires — a crash
 * between the two must be visible as a `processing` claim, not as a minted-but-unrecorded link.
 *
 * ## Token custody
 *
 * The submitted token is SHAPE-GATED here (64 lowercase hex) before it reaches the engine, is
 * used only to compose the URL through the bound {@see PaymentLinkPublicUrlProvider}, and the
 * local holding it is OVERWRITTEN the moment it has been consumed — PHP records call arguments in
 * exception backtraces, so a throwable raised later in this frame must not be able to report a
 * live credential. It is never logged, never persisted, never echoed, and never part of the
 * delivery fingerprint.
 *
 * A malformed token is answered `payment_link_changed` (409) rather than 422, matching the
 * engine's own documented reasoning: a token that cannot be the current link is not the current
 * link, and answering by SHAPE before ownership is consulted keeps token shape from becoming an
 * order-existence oracle. An ABSENT token is a different thing — a malformed REQUEST — and is 422.
 *
 * ## The receipt is closed, and the URL is not part of it
 *
 * Every non-refusal answer is `{receipt, link, url, recovery}`. `receipt` is derived from the
 * ledger row and can carry no token, no address, no rendered subject/body, and no transport
 * exception text — the ledger has no columns for any of them. `url` is non-null in exactly ONE
 * case: a `regenerate` whose DELIVERY failed. Spec §2.4 requires the new link to stay active and
 * its URL to come back on the ORIGINAL response so the operator can copy it by hand; that is the
 * only moment the value still exists, and a replay of that key never re-exposes it.
 */
final class AdminPaymentLinkSendController
{
    public const IDEMPOTENCY_HEADER = 'Idempotency-Key';
    public const KEY_MIN = 16;
    public const KEY_MAX = 128;

    /** The engine's own token shape ({@see PaymentLinkService}'s `TOKEN_PATTERN`). */
    private const TOKEN_PATTERN = '/\A[a-f0-9]{64}\z/';

    /** What the raw-token local is overwritten with once consumed. */
    private const REDACTED_TOKEN = '[redacted]';

    /** The one recovery instruction this endpoint ever gives (see {@see self::recoveryFor()}). */
    public const RECOVERY_NEW_KEY_OR_REGENERATE = 'use_a_new_idempotency_key_or_regenerate';

    /** The pack's own refusal codes, alongside the engine's {@see PaymentLinkException} ones. */
    public const REASON_KEY_CONFLICT = 'idempotency_key_conflict';
    public const REASON_NO_EMAIL = 'order_has_no_email';
    public const REASON_EMAIL_DISABLED = 'payment_request_email_disabled';

    /** Engine refusal code -> HTTP status, mirroring the engine controller's own closed map. */
    private const STATUS_BY_ERROR_CODE = [
        PaymentLinkException::ORDER_NOT_FOUND => 404,
        PaymentLinkException::ORDER_NOT_ADMIN_ORIGIN => 409,
        PaymentLinkException::ORDER_NOT_PENDING_PAYMENT => 409,
        PaymentLinkException::LINK_CHANGED => 409,
        PaymentLinkException::PUBLIC_URL_UNAVAILABLE => 503,
    ];

    public function __construct(
        private readonly ApplicationContext $context,
        private readonly OrderRepository $orders,
        private readonly CommerceTenantResolution $tenants,
        private readonly PaymentLinkDeliveryRepository $deliveries,
        private readonly PaymentRequestMailer $mailer,
        private readonly RichEmailAvailability $availability,
        private ?PaymentLinkService $links = null,
        private ?PaymentLinkPublicUrlProvider $publicUrls = null,
    ) {
    }

    #[ApiOperation(
        summary: 'Email an order\'s payment link (current or regenerated)',
        tags: ['Thallo Commerce'],
    )]
    public function send(Request $request, string $uuid): Response
    {
        $key = $this->idempotencyKey($request);
        if ($key === null) {
            return $this->refuse(
                'A valid Idempotency-Key header (16-128 opaque characters) is required.',
                422,
                'idempotency_key_invalid',
            );
        }

        $body = $this->body($request);
        if (is_string($body)) {
            return $this->refuse($body, 422, 'invalid_request');
        }

        $tenant = $this->tenants->tenantUuid($this->context);
        $order = $this->orders->findByUuid($this->context, $tenant, $uuid);
        if ($order === null) {
            // Unknown, cross-tenant, or a draft — ONE non-revealing answer, produced before any
            // service is consulted and before the ledger is touched.
            return $this->refuse('Resource not found.', 404, PaymentLinkException::ORDER_NOT_FOUND);
        }

        $email = trim((string) ($order['email'] ?? ''));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return $this->refuse(
                'This order carries no usable email address, so its payment link cannot be sent.',
                422,
                self::REASON_NO_EMAIL,
            );
        }

        // Both send preconditions are evaluated BEFORE the claim, so a refusal that could never
        // have delivered anything does not burn the operator's idempotency key.
        if (!$this->availability->isAvailable()) {
            return $this->refuse(
                'This installation has no rich email channel, so payment links cannot be emailed.',
                503,
                PaymentRequestSendResult::EMAIL_UNAVAILABLE,
            );
        }
        if (!$this->mailer->enabled()) {
            return $this->refuse(
                'The payment request email is switched off for this store.',
                409,
                self::REASON_EMAIL_DISABLED,
            );
        }

        return $body['mode'] === PaymentLinkDeliveryRepository::MODE_CURRENT
            ? $this->sendCurrent($tenant, $uuid, $order, $email, $key, (string) $body['token'])
            : $this->sendRegenerated($request, $tenant, $uuid, $order, $email, $key, $body['ttl_days']);
    }

    /**
     * `mode=current`: prove the submitted token is still the order's live link (through the
     * ENGINE), then claim and send. The proof runs BEFORE the claim because it is a pure read
     * that authorizes nothing — a 409 for a stale token must not leave a `processing` row behind.
     *
     * @param array<string,mixed> $order
     */
    private function sendCurrent(
        string $tenant,
        string $orderUuid,
        array $order,
        string $email,
        string $key,
        string $rawToken,
    ): Response {
        if (preg_match(self::TOKEN_PATTERN, $rawToken) !== 1) {
            $rawToken = self::REDACTED_TOKEN;

            return $this->refuse(
                'This payment link is no longer the order\'s current one; reload the order and use the current link.',
                409,
                PaymentLinkException::LINK_CHANGED,
            );
        }

        try {
            $link = $this->links()->matchCurrentToken($this->context, $tenant, $orderUuid, $rawToken);
        } catch (PaymentLinkException $e) {
            $rawToken = self::REDACTED_TOKEN;

            return $this->refuseEngine($e);
        }
        if ($link === null) {
            $rawToken = self::REDACTED_TOKEN;

            return $this->refuse('Resource not found.', 404, PaymentLinkException::ORDER_NOT_FOUND);
        }

        $url = $this->composeUrl($rawToken);
        // Consumed: from here the value lives only inside `$url`, which never reaches the ledger.
        $rawToken = self::REDACTED_TOKEN;
        if ($url === null) {
            return $this->refuse(
                'This store has no public payment-link address configured; nothing was sent.',
                503,
                PaymentLinkException::PUBLIC_URL_UNAVAILABLE,
            );
        }

        $claim = $this->claim($tenant, $key, $orderUuid, $email, PaymentLinkDeliveryRepository::MODE_CURRENT, null);
        if ($claim instanceof Response) {
            return $claim;
        }

        return $this->deliver($claim, $order, $link, $url, exposeUrlOnFailure: false);
    }

    /**
     * `mode=regenerate`: claim FIRST, then mint, then send. A mint refusal closes the claim as
     * `failed` carrying the engine's own code, so the key records what happened rather than
     * staying `processing` forever.
     *
     * @param array<string,mixed> $order
     */
    private function sendRegenerated(
        Request $request,
        string $tenant,
        string $orderUuid,
        array $order,
        string $email,
        string $key,
        ?int $ttlDays,
    ): Response {
        $claim = $this->claim(
            $tenant,
            $key,
            $orderUuid,
            $email,
            PaymentLinkDeliveryRepository::MODE_REGENERATE,
            $ttlDays,
        );
        if ($claim instanceof Response) {
            return $claim;
        }

        $deliveryUuid = (string) $claim->row['uuid'];

        try {
            $minted = $this->links()->mintPublic(
                $this->context,
                $tenant,
                $orderUuid,
                $ttlDays,
                (string) ($this->actorUuid($request) ?? ''),
            );
        } catch (PaymentLinkException $e) {
            $this->deliveries->markFailed($deliveryUuid, $e->errorCode, $this->now());

            return $this->refuseEngine($e);
        }

        /** @var PaymentLinkAdminView $link */
        $link = $minted['link'];
        $this->deliveries->attachLink($deliveryUuid, $link->linkUuid, $this->now());

        return $this->deliver(
            PaymentLinkDeliveryClaim::fresh(
                $this->deliveries->findByUuid($deliveryUuid) ?? $claim->row
            ),
            $order,
            $link,
            (string) $minted['url'],
            exposeUrlOnFailure: true,
        );
    }

    /**
     * The one send + record step, shared by both modes.
     *
     * @param array<string,mixed> $order
     */
    private function deliver(
        PaymentLinkDeliveryClaim $claim,
        array $order,
        PaymentLinkAdminView $link,
        string $url,
        bool $exposeUrlOnFailure,
    ): Response {
        $deliveryUuid = (string) $claim->row['uuid'];
        $result = $this->mailer->send(
            (string) $order['email'],
            $url,
            $this->placeholders($order, $link),
        );

        if ($result->sent) {
            $row = $this->deliveries->markSent($deliveryUuid, $result->providerMessageId, $this->now());

            return $this->respond(200, 'Payment link sent.', $row, $link, null);
        }

        $row = $this->deliveries->markFailed(
            $deliveryUuid,
            (string) $result->errorCode,
            $this->now(),
        );

        // Spec §2.4: the link STAYS active and its one-time URL comes back HERE so the operator
        // can copy it by hand. This is the single place a composed URL crosses this boundary.
        return $this->respond(
            502,
            'The payment link was created but could not be emailed; copy the link and send it manually.',
            $row,
            $link,
            $exposeUrlOnFailure ? $url : null,
        );
    }

    /**
     * Claim the key, or turn the non-FRESH outcomes straight into their responses.
     *
     * A REPLAY answers the recorded outcome with NO raw URL and NO resend — the plaintext is not
     * recoverable, which is exactly why an `indeterminate` replay also carries
     * {@see self::RECOVERY_NEW_KEY_OR_REGENERATE} instead of quietly re-minting.
     *
     * @return PaymentLinkDeliveryClaim|Response the FRESH claim, or the finished response
     */
    private function claim(
        string $tenant,
        string $key,
        string $orderUuid,
        string $email,
        string $mode,
        ?int $ttlDays,
    ): PaymentLinkDeliveryClaim|Response {
        $recipientHash = PaymentLinkDeliveryRepository::recipientHash($email);
        $claim = $this->deliveries->claim(
            $tenant,
            $key,
            PaymentLinkDeliveryRepository::fingerprint($orderUuid, $mode, $recipientHash, $ttlDays),
            $orderUuid,
            $recipientHash,
            $mode,
            PaymentLinkDeliveryRepository::staleSeconds($this->context),
            $this->now(),
        );

        if ($claim->isConflict()) {
            return $this->refuse(
                'This Idempotency-Key was already used for a different request.',
                409,
                self::REASON_KEY_CONFLICT,
            );
        }

        if ($claim->isReplay()) {
            /** @var array<string,mixed> $row */
            $row = $claim->row;

            return $this->respond(200, 'Payment link delivery replayed.', $row, null, null, replayed: true);
        }

        return $claim;
    }

    /**
     * The CLOSED response payload. `link` is the freshly observed link view (absent on a replay,
     * which re-mints and re-reads nothing); `url` is non-null only for a regenerate-mode delivery
     * failure; `recovery` is non-null only for an `indeterminate` replay.
     *
     * @param array<string,mixed> $row
     */
    private function respond(
        int $status,
        string $message,
        array $row,
        ?PaymentLinkAdminView $link,
        ?string $url,
        bool $replayed = false,
    ): Response {
        return new Response(
            [
                'success' => $status === 200,
                'message' => $message,
                'data' => [
                    'receipt' => $this->receipt($row, $replayed),
                    'link' => $link?->toArray(),
                    'url' => $url,
                    'recovery' => $this->recoveryFor($row, $replayed),
                ],
            ],
            $status,
        );
    }

    /**
     * The ledger row projected to the wire. Every field is either an opaque identifier, a closed
     * enum, or a timestamp — the row has no column that could hold a token, an address, a
     * rendered body, or exception text, so this projection cannot leak one.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function receipt(array $row, bool $replayed): array
    {
        return [
            'delivery_uuid' => (string) $row['uuid'],
            'order_uuid' => (string) $row['order_uuid'],
            'link_uuid' => $row['link_uuid'] === null ? null : (string) $row['link_uuid'],
            'mode' => (string) $row['mode'],
            'status' => (string) $row['status'],
            'error_code' => $row['error_code'] === null ? null : (string) $row['error_code'],
            'provider_message_id' => $row['provider_message_id'] === null
                ? null
                : (string) $row['provider_message_id'],
            'replayed' => $replayed,
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    /**
     * The one instruction this endpoint gives. An `indeterminate` replay means a previous attempt
     * claimed this key and vanished: whether its email went out is unknowable, and the URL it may
     * have carried is gone. Re-minting silently under the same key would be a guess, so the
     * operator is told to use a new key or regenerate.
     *
     * @param array<string,mixed> $row
     */
    private function recoveryFor(array $row, bool $replayed): ?string
    {
        return $replayed && (string) $row['status'] === PaymentLinkDeliveryRepository::STATUS_INDETERMINATE
            ? self::RECOVERY_NEW_KEY_OR_REGENERATE
            : null;
    }

    /**
     * The editable template's non-URL chips. Money is formatted through the SAME
     * {@see ShopMoney} helper the order emails use, guarded by Commerce's own currency authority
     * so an unknown code degrades to the raw minor amount rather than throwing near a send.
     *
     * @param array<string,mixed> $order
     * @return array<string,string>
     */
    private function placeholders(array $order, PaymentLinkAdminView $link): array
    {
        $currency = strtoupper(trim((string) ($order['currency'] ?? 'USD')));
        $grandTotal = (int) ($order['grand_total'] ?? 0);

        return [
            'order_number' => (string) ($order['order_number'] ?? ''),
            'total' => Money::exponentFor($currency) === null
                ? (string) $grandTotal
                : ShopMoney::display($grandTotal, $currency),
            'store_name' => $this->storeName(),
            'expires_at' => $link->expiresAt,
        ];
    }

    private function storeName(): string
    {
        return (string) config($this->context, 'thallo.site_name', 'Thallo');
    }

    /**
     * The opaque `Idempotency-Key`: 16-128 characters, no whitespace and no control characters
     * (both would make a key that cannot survive a round trip through an HTTP header). Its
     * CONTENT is never interpreted — it is the client's own correlation handle.
     */
    private function idempotencyKey(Request $request): ?string
    {
        $raw = $request->headers->get(self::IDEMPOTENCY_HEADER);
        if (!is_string($raw)) {
            return null;
        }

        $length = strlen($raw);
        if ($length < self::KEY_MIN || $length > self::KEY_MAX) {
            return null;
        }

        return preg_match('/\A[\x21-\x7E]+\z/', $raw) === 1 ? $raw : null;
    }

    /**
     * The CLOSED request body. Returns the normalized `{mode, token, ttl_days}` triple, or a
     * message describing why the body is not one.
     *
     * Strict by construction: unknown fields, a `token` in regenerate mode, and a `ttl_days` in
     * current mode are all rejected rather than ignored, because each of them means the caller
     * believes something about this endpoint that is not true.
     *
     * @return array{mode:string, token:string|null, ttl_days:int|null}|string
     */
    private function body(Request $request): array|string
    {
        $raw = trim((string) $request->getContent());
        $decoded = $raw === '' ? [] : json_decode($raw, true);
        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            return 'The request body must be a JSON object.';
        }

        $mode = $decoded['mode'] ?? null;
        if (!is_string($mode) || !in_array($mode, PaymentLinkDeliveryRepository::MODES, true)) {
            return 'mode must be one of: ' . implode(', ', PaymentLinkDeliveryRepository::MODES) . '.';
        }

        $allowed = $mode === PaymentLinkDeliveryRepository::MODE_CURRENT
            ? ['mode', 'token']
            : ['mode', 'ttl_days'];
        $unknown = array_diff(array_keys($decoded), $allowed);
        if ($unknown !== []) {
            return 'Unknown or mode-incompatible field(s) for mode=' . $mode . '.';
        }

        if ($mode === PaymentLinkDeliveryRepository::MODE_CURRENT) {
            $token = $decoded['token'] ?? null;
            if (!is_string($token)) {
                return 'mode=current requires the currently displayed link token.';
            }

            return ['mode' => $mode, 'token' => $token, 'ttl_days' => null];
        }

        $ttl = $decoded['ttl_days'] ?? null;
        if ($ttl !== null && !is_int($ttl)) {
            return 'ttl_days must be a whole number of days.';
        }

        return ['mode' => $mode, 'token' => null, 'ttl_days' => $ttl];
    }

    /**
     * Compose the public landing URL for a token the engine has just confirmed is current.
     * A missing or refusing provider yields null, which becomes the SAME typed
     * `public_url_unavailable` the engine raises for the identical condition on mint.
     */
    private function composeUrl(string $rawToken): ?string
    {
        $provider = $this->publicUrls();
        if ($provider === null) {
            return null;
        }

        try {
            $url = $provider->urlFor($this->context, $rawToken);
        } catch (\Throwable) {
            // The provider's own throwable could quote the URL it was composing.
            return null;
        }

        return is_string($url) && $url !== '' ? $url : null;
    }

    private function refuseEngine(PaymentLinkException $e): Response
    {
        return $this->refuse($e->getMessage(), self::STATUS_BY_ERROR_CODE[$e->errorCode] ?? 409, $e->errorCode);
    }

    private function refuse(string $message, int $status, string $reason): Response
    {
        return Response::error($message, $status, ['reason' => $reason]);
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /**
     * Resolved LAZILY for the same reason the engine's own payment-link controller does it:
     * {@see PaymentLinkService} pulls the public-URL seam, the return-URL seam, and the payment
     * collector behind it, and none of that may be constructed on a request that is going to
     * refuse at validation.
     */
    private function links(): PaymentLinkService
    {
        return $this->links ??= app($this->context, PaymentLinkService::class);
    }

    private function publicUrls(): ?PaymentLinkPublicUrlProvider
    {
        if ($this->publicUrls !== null) {
            return $this->publicUrls;
        }
        $container = $this->context->getContainer();
        if (!$container->has(PaymentLinkPublicUrlProvider::class)) {
            return null;
        }

        return $this->publicUrls = $container->get(PaymentLinkPublicUrlProvider::class);
    }

    private function actorUuid(Request $request): ?string
    {
        $identity = $request->attributes->get('auth.user');

        return $identity instanceof \Glueful\Auth\UserIdentity ? $identity->uuid() : null;
    }
}
