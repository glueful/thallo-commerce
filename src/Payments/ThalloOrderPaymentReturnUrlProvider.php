<?php

declare(strict_types=1);

namespace Thallo\Commerce\Payments;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Contracts\OrderPaymentReturnUrlProvider;
use Thallo\Commerce\Shop\ShopUrlGenerator;
use Thallo\Contracts\Delivery\CanonicalPublicOriginResolver;

/**
 * Thallo's {@see OrderPaymentReturnUrlProvider} (checkout-ui plan Task 3): composes the hosted
 * payment flow's browser return/cancel URLs from the ONE trusted-origin authority
 * ({@see CanonicalPublicOriginResolver} — configured/verified origins, NEVER the request's Host
 * header) plus {@see ShopUrlGenerator}'s return/cancel paths (which rawurlencode the order
 * number). Receives the completed order, so the same values serve initial placement, durable
 * replay, and payment retry. Navigation only — webhooks remain the settlement authority.
 */
final class ThalloOrderPaymentReturnUrlProvider implements OrderPaymentReturnUrlProvider
{
    public function __construct(
        private readonly CanonicalPublicOriginResolver $origins,
        private readonly ShopUrlGenerator $urls,
    ) {
    }

    public function urlsFor(ApplicationContext $context, array $order): ?array
    {
        $number = (string) ($order['order_number'] ?? '');
        if ($number === '') {
            return null;
        }

        $origin = rtrim($this->origins->currentOrigin($context), '/');
        // Gateways (and commerce's own validation) require absolute HTTPS return URLs. An
        // http origin — a TLS-less local install — cannot produce a compliant callback, so this
        // is contractually "no URLs available" (gateway dashboard fallback), NOT malformed
        // output: placement proceeds exactly as before the provider existed.
        if (!str_starts_with($origin, 'https://')) {
            return null;
        }

        return [
            'return' => $origin . $this->urls->paymentReturn($number),
            'cancel' => $origin . $this->urls->paymentCancel($number),
        ];
    }
}
