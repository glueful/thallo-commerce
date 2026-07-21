<?php

declare(strict_types=1);

namespace Thallo\Commerce\Http\Shop;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Encryption\EncryptionService;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

use function config;

/**
 * Guest order credential custody (storefront-rendering spec §6): the RAW order token(s) never
 * leave this class — every read/write goes through an **encrypted**
 * ({@see EncryptionService}, AAD bound to the tenant so a cross-tenant cookie can never decrypt)
 * `Secure; HttpOnly; SameSite=Lax` cookie holding at most {@see self::MAX_ENTRIES} `(order_ref,
 * token)` entries, oldest evicted — never an unbounded list. Commerce orders carry no general
 * expiry, so this cookie's own lifetime IS the retention window:
 * `thallo-commerce.guest_confirmation_days` (default 30, clamped 1–90, spec §6).
 *
 * A tampered, wrong-key, or malformed cookie decrypts to an empty entry list rather than
 * throwing — every caller (the confirmation/return/cancel routes) already treats "no matching
 * entry for this ref" as a non-revealing 404, so a corrupted cookie degrades to exactly that,
 * never a 500.
 */
final class GuestOrderCookie
{
    public const NAME = 'thallo_guest_orders';

    private const MAX_ENTRIES = 5;

    public function __construct(private readonly EncryptionService $encryption)
    {
    }

    /** @return list<array{ref: string, token: string}> */
    public function read(Request $request, string $tenant): array
    {
        $raw = $request->cookies->get(self::NAME);
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        try {
            $json = $this->encryption->decrypt($raw, aad: self::aad($tenant));
        } catch (\Throwable) {
            return []; // tamper / wrong key / wrong tenant — treated as "no credentials".
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }

        $entries = [];
        foreach ($decoded as $entry) {
            if (
                is_array($entry)
                && is_string($entry['ref'] ?? null) && $entry['ref'] !== ''
                && is_string($entry['token'] ?? null) && $entry['token'] !== ''
            ) {
                $entries[] = ['ref' => $entry['ref'], 'token' => $entry['token']];
            }
        }

        return $entries;
    }

    /** The raw guest credential for `$ref`, or null when absent/undecryptable. */
    public function credentialFor(Request $request, string $tenant, string $ref): ?string
    {
        foreach ($this->read($request, $tenant) as $entry) {
            if ($entry['ref'] === $ref) {
                return $entry['token'];
            }
        }

        return null;
    }

    /**
     * Merges `$ref => $rawToken` into the request's existing entry list (replacing any prior
     * entry for the SAME ref, never duplicating it), caps at {@see self::MAX_ENTRIES}
     * oldest-evicted, re-encrypts, and sets the cookie on `$response`.
     */
    public function remember(
        Response $response,
        Request $request,
        ApplicationContext $context,
        string $tenant,
        string $ref,
        string $rawToken
    ): void {
        $entries = array_values(array_filter(
            $this->read($request, $tenant),
            static fn (array $e): bool => $e['ref'] !== $ref
        ));
        $entries[] = ['ref' => $ref, 'token' => $rawToken];
        if (count($entries) > self::MAX_ENTRIES) {
            $entries = array_slice($entries, -self::MAX_ENTRIES);
        }

        $ciphertext = $this->encryption->encrypt(
            (string) json_encode($entries, JSON_THROW_ON_ERROR),
            aad: self::aad($tenant)
        );
        $days = self::confirmationDays($context);

        $response->headers->setCookie(new Cookie(
            self::NAME,
            $ciphertext,
            time() + $days * 86400,
            '/',
            null,
            true,  // Secure
            true,  // HttpOnly
            false, // raw (URL-encode the value)
            Cookie::SAMESITE_LAX,
        ));
    }

    /** `thallo-commerce.guest_confirmation_days`, clamped 1–90 (spec §6). */
    public static function confirmationDays(ApplicationContext $context): int
    {
        $days = (int) config($context, 'thallo-commerce.guest_confirmation_days', 30);

        return max(1, min(90, $days));
    }

    private static function aad(string $tenant): string
    {
        return "shop.orders:{$tenant}";
    }
}
