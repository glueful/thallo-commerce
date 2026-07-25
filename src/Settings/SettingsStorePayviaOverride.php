<?php

declare(strict_types=1);

namespace Thallo\Commerce\Settings;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Encryption\EncryptionService;
use Glueful\Extensions\Payvia\Support\PayviaSettingsOverride;
use Thallo\Contracts\Capability\CapabilityRegistry;

/**
 * Thallo's implementation of Payvia's settings seam (store-settings spec §3.6, Payments tab):
 * runtime-editable gateway settings live as rows in the host's settings store — read through the
 * pack-owned {@see CommerceSettingsStore} contract at USE time, exactly like the commerce seam
 * ({@see SettingsStoreCommerceOverride}) — with one addition: SECRET values (`secret_key`,
 * `webhook_secret`) are stored ENCRYPTED (framework EncryptionService, AAD = the settings key)
 * and decrypted here on the way to Payvia. Payvia's seam contract receives plaintext and never
 * persists or logs it.
 *
 * The editable whitelist is structural: `payvia.default_gateway`, plus
 * `payvia.gateways.{id}.{enabled|secret_key|webhook_secret}` for ids that exist in the
 * `payvia.gateways` CONFIG map — an override can reconfigure a configured gateway but never
 * invent one, and ops knobs (base URLs, timeouts, middleware) are not editable.
 *
 * IMPORTANT (tenancy): gateway credentials are INSTALLATION-level, not workspace-level — the
 * `/webhooks/{gateway}` endpoint verifies signatures before any tenant context exists, so there
 * can only be one effective secret per gateway. Today thallo runs in the ''-sentinel (global)
 * settings scope so this holds by construction; when tenancy enforcement lands, these keys must
 * be pinned to the global scope explicitly (spec §3.6 records this as enforcement-time work).
 *
 * Honors the seam's null-never-throw contract absolutely: unknown key, disabled capability,
 * unbound store, undecryptable row (rotated APP_KEY without APP_PREVIOUS_KEYS, tampered value),
 * or ANY storage throwable all resolve to null — config()/env stays the always-working fallback
 * and a settings problem can never break payment processing or webhook verification.
 */
final class SettingsStorePayviaOverride implements PayviaSettingsOverride
{
    private const SECRET_SUBKEYS = ['secret_key', 'webhook_secret'];

    public function value(ApplicationContext $context, string $key): ?string
    {
        $subkey = $this->whitelistedSubkey($context, $key);
        if ($subkey === null) {
            return null;
        }

        try {
            $container = $context->getContainer();

            // Same value()-time capability gate as the commerce seam: compiled services can't
            // bind conditionally, so a disabled thallo.commerce means "no override" here.
            if (
                $container->has(CapabilityRegistry::class)
                && !$container->get(CapabilityRegistry::class)->isEnabled('thallo.commerce')
            ) {
                return null;
            }

            if (!$container->has(CommerceSettingsStore::class)) {
                return null;
            }
            $stored = $container->get(CommerceSettingsStore::class)->get($key);
            if (!is_string($stored) || trim($stored) === '') {
                return null;
            }

            if (!in_array($subkey, self::SECRET_SUBKEYS, true)) {
                return $stored;
            }

            // Secrets at rest are ciphertext; a row that isn't (or won't decrypt) is treated
            // as absent rather than handing payvia a value that was never a real key.
            $encryption = $container->get(EncryptionService::class);
            if (!$encryption->isEncrypted($stored)) {
                return null;
            }

            return $encryption->decrypt($stored, aad: $key);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The whitelist check: returns the terminal subkey ('default_gateway', 'enabled',
     * 'secret_key', 'webhook_secret') when $key is editable, null otherwise.
     */
    private function whitelistedSubkey(ApplicationContext $context, string $key): ?string
    {
        if ($key === 'payvia.default_gateway') {
            return 'default_gateway';
        }

        if (preg_match('/^payvia\.gateways\.([a-z0-9_-]+)\.(enabled|secret_key|webhook_secret)$/', $key, $m) !== 1) {
            return null;
        }

        $configured = (array) config($context, 'payvia.gateways', []);

        return array_key_exists($m[1], $configured) ? $m[2] : null;
    }
}
