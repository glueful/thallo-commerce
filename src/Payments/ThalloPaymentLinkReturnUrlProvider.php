<?php

declare(strict_types=1);

namespace Thallo\Commerce\Payments;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Contracts\PaymentLinkReturnUrlProvider;
use Thallo\Commerce\Shop\ShopUrlGenerator;
use Thallo\Contracts\Delivery\CanonicalPublicOriginResolver;

/**
 * Thallo's {@see PaymentLinkReturnUrlProvider} (payment-links spec §2.3), bound OVER Commerce's
 * engine-owned {@see \Glueful\Extensions\Commerce\Orders\UnavailablePaymentLinkReturnUrlProvider}
 * default: the browser return/cancel URLs a payment-link checkout session sends the payer back
 * to.
 *
 * Composed from the canonical trusted origin ({@see CanonicalPublicOriginResolver} — never the
 * request `Host`) plus {@see ShopUrlGenerator}'s signed receipt paths. The subject is the LINK
 * UUID and the credential is a {@see PaymentLinkReturnSigner} signature — the raw payment token
 * appears nowhere, by construction: this method is not given one, and the engine's own contract
 * test pins that parameter list so a future signature cannot quietly add one.
 *
 * The two handles are DISTINCT signing purposes, so a provider (or anyone reading its dashboard)
 * cannot replay the return handle on the cancel route to assert the opposite outcome.
 *
 * Null rather than degraded output, exactly like {@see ThalloOrderPaymentReturnUrlProvider}: a
 * non-HTTPS canonical origin (a TLS-less local install) or an unsignable install cannot produce
 * compliant URLs, and Commerce turns null into a typed `return_url_unavailable` — raised BEFORE
 * the payment provider is called at all, so no session exists that a payer could be returned
 * from. Spec §2.2/§2.3 forbid falling back to the guest-cookie order return route or to a
 * gateway-global callback: either would land the payer on a page they cannot be authorized for,
 * after their money had already moved.
 */
final class ThalloPaymentLinkReturnUrlProvider implements PaymentLinkReturnUrlProvider
{
    /** The uuid shape the engine mints ({@see \Glueful\Helpers\Utils::generateNanoID()}), bounded. */
    private const LINK_UUID_PATTERN = '/\A[A-Za-z0-9_-]{1,64}\z/';

    public function __construct(
        private readonly CanonicalPublicOriginResolver $origins,
        private readonly ShopUrlGenerator $urls,
        private readonly PaymentLinkReturnSigner $signer,
    ) {
    }

    /** @return array{return: string, cancel: string}|null */
    public function urlsFor(ApplicationContext $context, string $linkUuid): ?array
    {
        if (preg_match(self::LINK_UUID_PATTERN, $linkUuid) !== 1) {
            return null;
        }

        $origin = rtrim($this->origins->currentOrigin($context), '/');
        // Gateways (and Commerce's HttpsUrl validation) require absolute HTTPS. An http origin
        // is contractually "no URLs available", NOT malformed output.
        if (!str_starts_with($origin, 'https://')) {
            return null;
        }

        try {
            $return = $this->signer->sign($context, PaymentLinkReturnSigner::PURPOSE_RETURN, $linkUuid);
            $cancel = $this->signer->sign($context, PaymentLinkReturnSigner::PURPOSE_CANCEL, $linkUuid);
        } catch (\Throwable) {
            // Fail CLOSED (an unconfigured/undecodable app.key): an unsigned handle would be a
            // route nobody could verify, so this host simply has no return surface right now.
            return null;
        }

        return [
            'return' => $origin . $this->urls->paymentLinkReturn($linkUuid, $return),
            'cancel' => $origin . $this->urls->paymentLinkCancel($linkUuid, $cancel),
        ];
    }
}
