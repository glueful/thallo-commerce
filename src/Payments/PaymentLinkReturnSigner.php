<?php

declare(strict_types=1);

namespace Thallo\Commerce\Payments;

use Glueful\Bootstrap\ApplicationContext;

use function config;

/**
 * The signing authority behind the two NON-AUTHORIZING payment-link receipt handles
 * (payment-links spec §2.3): `/checkout/pay/return/{linkUuid}/{signature}` and
 * `/checkout/pay/cancel/{linkUuid}/{signature}`.
 *
 * ## What a signature is worth, and what it deliberately is not
 *
 * NOTHING is authorized by a valid signature. The receipt it unlocks reveals no order and no
 * link field; it renders one generic sentence. The signature exists so that the two handles —
 * which are handed to a PAYMENT PROVIDER, stored in its dashboard, and replayed through browser
 * redirects — cannot be MINTED by a stranger, and so that the `return` handle can never be
 * replayed on the `cancel` route (or vice versa) to claim the opposite outcome happened.
 *
 * That is also why the subject is the LINK UUID and never the raw token: a bearer token in a
 * URL that a provider stores and replays would hand the link's whole authority to every
 * intermediary. Commerce's own {@see \Glueful\Extensions\Commerce\Contracts\PaymentLinkReturnUrlProvider}
 * seam pins this by only ever passing a link uuid.
 *
 * ## Key derivation, and why it fails closed
 *
 * Derived from the configured `app.key` with the SAME `base64:` handling the host application's
 * own preview-token key resolver applies (its `ResolvesPreviewKey` trait, which itself mirrors
 * the framework's `EncryptionService::resolveAndValidateKey()`) — named here rather than
 * imported, because this pack may not depend on the application. Mint and verify both go through
 * {@see self::deriveKey()}, so they can never diverge.
 *
 * It is STRICTER than that trait in one respect, on purpose: a `base64:` prefix whose payload
 * does not decode is a hard refusal here rather than a silent fall back to the literal string.
 * There is NO fallback key of any kind. An install with no `app.key` cannot sign, so its
 * provider answers null (a typed `return_url_unavailable` from the engine — no checkout is ever
 * started) and its receipt routes answer the same generic 404 every hostile signature gets.
 * The alternative — a default or derived-from-nothing key — would make every signature forgeable
 * by anyone who read this file.
 */
final class PaymentLinkReturnSigner
{
    /** The two CLOSED signing purposes. A signature is bound to exactly one of them. */
    public const PURPOSE_RETURN = 'payment-link-return';
    public const PURPOSE_CANCEL = 'payment-link-cancel';

    /** @var list<string> */
    public const PURPOSES = [self::PURPOSE_RETURN, self::PURPOSE_CANCEL];

    /** The signature shape both the router-facing gate and {@see self::verify()} require. */
    public const SIGNATURE_PATTERN = '/\A[a-f0-9]{64}\z/';

    /**
     * HMAC-SHA256 over `purpose . "\0" . linkUuid`, hex-encoded.
     *
     * The purpose is part of the SIGNED MESSAGE rather than a separate key or a suffix check, so
     * purpose separation is structural: there is no code path in which a `return` signature can
     * be accepted on the `cancel` route. The NUL separator makes the two components
     * unambiguous — no `(purpose, uuid)` pair can produce the same message as a different pair.
     *
     * @throws \InvalidArgumentException for a purpose outside {@see self::PURPOSES}
     * @throws \RuntimeException when `app.key` is absent or undecodable (fail closed)
     */
    public function sign(ApplicationContext $context, string $purpose, string $linkUuid): string
    {
        if (!in_array($purpose, self::PURPOSES, true)) {
            throw new \InvalidArgumentException(
                'Unknown payment-link signing purpose ' . var_export($purpose, true) . '.'
            );
        }

        return hash_hmac('sha256', $purpose . "\0" . $linkUuid, $this->key($context));
    }

    /**
     * Constant-time verification. Shape is checked FIRST so a malformed candidate never reaches
     * the comparison, and every refusal — wrong purpose, wrong subject, wrong bytes, wrong
     * shape — is the same `false`, which the controller turns into ONE generic 404.
     *
     * Note that an UNKNOWN purpose is a `false` here rather than the throw {@see self::sign()}
     * raises: verification is reached from a public route, and a caller-side programming error
     * must not become an anonymous 500.
     */
    public function verify(ApplicationContext $context, string $purpose, string $linkUuid, string $signature): bool
    {
        if (preg_match(self::SIGNATURE_PATTERN, $signature) !== 1) {
            return false;
        }

        if (!in_array($purpose, self::PURPOSES, true)) {
            return false;
        }

        return hash_equals($this->sign($context, $purpose, $linkUuid), $signature);
    }

    /**
     * The `base64:` decoding discipline, exposed as a pure function so the fail-closed rules are
     * testable without booting an install that has no `app.key` (config is immutable after boot).
     *
     * @throws \RuntimeException on an empty, blank, or undecodable key — never a fallback
     */
    public static function deriveKey(string $configured): string
    {
        $key = trim($configured);
        if ($key === '') {
            throw new \RuntimeException(
                'APP_KEY is not configured; payment-link return handles cannot be signed.'
            );
        }

        if (!str_starts_with($key, 'base64:')) {
            return $key;
        }

        $decoded = base64_decode(substr($key, 7), true);
        if ($decoded === false || $decoded === '') {
            throw new \RuntimeException(
                'APP_KEY carries a base64: prefix but does not decode; payment-link return '
                . 'handles cannot be signed.'
            );
        }

        return $decoded;
    }

    private function key(ApplicationContext $context): string
    {
        return self::deriveKey((string) config($context, 'app.key', ''));
    }
}
