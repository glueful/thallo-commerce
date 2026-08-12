<?php

declare(strict_types=1);

namespace Thallo\Commerce\Payments;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Contracts\PaymentLinkPublicUrlProvider;
use Thallo\Commerce\Shop\ShopUrlGenerator;
use Thallo\Contracts\Delivery\CanonicalPublicOriginResolver;

/**
 * Thallo's {@see PaymentLinkPublicUrlProvider} (payment-links spec §2.3), bound OVER Commerce's
 * engine-owned {@see \Glueful\Extensions\Commerce\Orders\UnavailablePaymentLinkPublicUrlProvider}
 * default: it composes the customer-facing landing URL an operator hands out, from the ONE
 * trusted-origin authority ({@see CanonicalPublicOriginResolver} — configured/verified origins,
 * NEVER the request's `Host` header) plus {@see ShopUrlGenerator::paymentLink()}.
 *
 * ## Token custody
 *
 * This is the only Thallo class that ever receives the raw bearer token, and it receives it IN
 * MEMORY ONLY, before Commerce persists anything. So it does exactly one thing with it —
 * compose — and then overwrites its own parameter before returning, for the same reason the
 * engine does: PHP records call arguments in exception backtraces, and an unrelated throwable
 * raised later in the same frame would otherwise put a live credential into an error log. It
 * never logs, stores, or forwards the token, and it raises no exception that could quote it.
 *
 * ## Why null rather than a best-effort URL
 *
 * Commerce validates this output BEFORE it writes a link row: absolute HTTPS, a host, no
 * userinfo, no port, no query, no fragment, and the token exactly once as the final path
 * segment. Anything else is a typed `public_url_unavailable` and NOTHING is minted. So the two
 * conditions this host cannot satisfy — a non-HTTPS canonical origin (a TLS-less local install)
 * and an origin carrying an explicit port — are answered as null HERE, which produces the exact
 * same typed outcome without asking the engine to reject our own output. A payment link nobody
 * can open must never come into existence.
 */
final class ThalloPaymentLinkPublicUrlProvider implements PaymentLinkPublicUrlProvider
{
    /** The engine's own token shape ({@see \Glueful\Extensions\Commerce\Orders\PaymentLinkService::TOKEN_PATTERN}). */
    private const TOKEN_PATTERN = '/\A[a-f0-9]{64}\z/';

    /** What the raw-token parameter is overwritten with once it has been consumed. */
    private const REDACTED_TOKEN = '[redacted]';

    public function __construct(
        private readonly CanonicalPublicOriginResolver $origins,
        private readonly ShopUrlGenerator $urls,
    ) {
    }

    public function urlFor(ApplicationContext $context, string $rawToken): ?string
    {
        // Shape gate first: a token that is not the engine's own shape can only produce a URL
        // the engine will refuse, and gating here keeps a malformed value out of the composed
        // string entirely.
        if (preg_match(self::TOKEN_PATTERN, $rawToken) !== 1) {
            $rawToken = self::REDACTED_TOKEN;

            return null;
        }

        $origin = rtrim($this->origins->currentOrigin($context), '/');
        if (!$this->isMintableOrigin($origin)) {
            $rawToken = self::REDACTED_TOKEN;

            return null;
        }

        $url = $origin . $this->urls->paymentLink($rawToken);
        // From here the token lives only inside `$url`, which is this frame's return value.
        $rawToken = self::REDACTED_TOKEN;

        return $url;
    }

    /**
     * HTTPS, a host, and nothing the engine's validator forbids. A port is refused here rather
     * than passed on: the engine forbids one outright, and refusing locally makes the reason
     * legible at the seam that owns the origin.
     */
    private function isMintableOrigin(string $origin): bool
    {
        $parts = parse_url($origin);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') === '') {
            return false;
        }

        foreach (['user', 'pass', 'port', 'query', 'fragment'] as $forbidden) {
            if (isset($parts[$forbidden])) {
                return false;
            }
        }

        return true;
    }
}
