<?php

declare(strict_types=1);

namespace Thallo\Commerce\Http;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Encryption\EncryptionService;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Commerce\Settings\CommerceSettingsStore;
use Thallo\Contracts\Delivery\CanonicalPublicOriginResolver;

/**
 * Payments settings admin API (store-settings spec §3.6, Payments tab):
 * `GET/PUT /v1/admin/commerce/payments`.
 *
 * GET reports the honest posture — mode `manual` when no payment extension configures gateways
 * (Commerce's ManualPaymentCollector + operator mark-paid is the real fallback), or the gateway
 * list with per-field state. Secret fields are reported as BOOLEANS ONLY (`set` + `source`);
 * stored or env key material never crosses the wire outbound.
 *
 * PUT is write-only for secrets: absent = untouched, null/blank = clear (row DELETED — env
 * fallback shows through), a value = validate, ENCRYPT (framework EncryptionService, AAD = the
 * settings key), store. Effective behavior flows through payvia's own settings seam
 * ({@see \Thallo\Commerce\Settings\SettingsStorePayviaOverride}), which decrypts on read.
 *
 * Gateway ids come from the `payvia.gateways` CONFIG map — this endpoint can reconfigure a
 * configured gateway but never invent one. Reads the payvia namespace via config keys only
 * (no payvia class references), so the pack stays loadable without the extension installed.
 */
final class PaymentsSettingsController
{
    private const SECRET_MAX_LENGTH = 512;

    public function __construct(
        private readonly ApplicationContext $context,
        private readonly ?CommerceSettingsStore $store = null,
        private readonly ?EncryptionService $encryption = null,
        private readonly ?CanonicalPublicOriginResolver $origins = null,
    ) {
    }

    #[ApiOperation(
        summary: 'Get payment gateway settings (booleans for secrets — key material never returned)',
        tags: ['Thallo Commerce'],
    )]
    public function show(Request $request): Response
    {
        $configured = $this->configuredGateways();
        if ($configured === []) {
            return Response::success([
                'mode' => 'manual',
                'default_gateway' => null,
                'gateways' => [],
            ], 'Payment settings retrieved');
        }

        $default = $this->effectiveDefaultGateway();
        $rows = [];
        foreach ($configured as $id => $config) {
            $rows[] = [
                'id' => $id,
                'enabled' => [
                    'value' => $this->effectiveEnabled($id, $config),
                    'default' => (bool) ($config['enabled'] ?? false),
                    'overridden' => $this->storedValue("payvia.gateways.{$id}.enabled") !== null,
                ],
                'secret_key' => $this->secretState($id, $config, 'secret_key'),
                'webhook_secret' => $this->secretState($id, $config, 'webhook_secret'),
                'default' => $id === $default,
                'webhook_url' => $this->webhookUrl($id),
            ];
        }

        return Response::success([
            'mode' => 'gateway',
            'default_gateway' => [
                'value' => $default,
                'default' => (string) config($this->context, 'payvia.default_gateway', ''),
                'overridden' => $this->storedValue('payvia.default_gateway') !== null,
            ],
            'gateways' => $rows,
        ], 'Payment settings retrieved');
    }

    #[ApiOperation(
        summary: 'Update payment gateway settings (secrets are write-only: blank clears, absent keeps)',
        tags: ['Thallo Commerce'],
    )]
    public function update(Request $request): Response
    {
        $configured = $this->configuredGateways();
        if ($configured === []) {
            throw ValidationException::forField(
                'payments',
                'No payment gateway extension is installed — there is nothing to configure.',
            );
        }

        $body = (array) json_decode((string) $request->getContent(), true);

        $puts = [];
        $forgets = [];

        if (array_key_exists('default_gateway', $body)) {
            $raw = $body['default_gateway'];
            if ($raw === null || (is_string($raw) && trim($raw) === '')) {
                $forgets[] = 'payvia.default_gateway';
            } elseif (!is_string($raw) || !array_key_exists(trim($raw), $configured)) {
                throw ValidationException::forField('default_gateway', 'Unknown gateway.');
            } else {
                $puts['payvia.default_gateway'] = trim($raw);
            }
        }

        $gateways = $body['gateways'] ?? [];
        if (!is_array($gateways)) {
            throw ValidationException::forField('gateways', 'Must be an object keyed by gateway id.');
        }
        foreach ($gateways as $id => $fields) {
            if (!is_string($id) || !array_key_exists($id, $configured)) {
                throw ValidationException::forField("gateways.{$id}", 'Unknown gateway.');
            }
            if (!is_array($fields)) {
                throw ValidationException::forField("gateways.{$id}", 'Must be an object of fields.');
            }

            if (array_key_exists('enabled', $fields)) {
                if (!is_bool($fields['enabled'])) {
                    throw ValidationException::forField("gateways.{$id}.enabled", 'Must be true or false.');
                }
                $puts["payvia.gateways.{$id}.enabled"] = $fields['enabled'] ? '1' : '0';
            }

            foreach (['secret_key', 'webhook_secret'] as $secret) {
                if (!array_key_exists($secret, $fields)) {
                    continue; // write-only: absent = untouched
                }
                $key = "payvia.gateways.{$id}.{$secret}";
                $raw = $fields[$secret];
                if ($raw === null || (is_string($raw) && trim($raw) === '')) {
                    $forgets[] = $key;
                    continue;
                }
                if (!is_string($raw)) {
                    throw ValidationException::forField("gateways.{$id}.{$secret}", 'Must be a string.');
                }
                $value = trim($raw);
                if (mb_strlen($value) > self::SECRET_MAX_LENGTH) {
                    throw ValidationException::forField(
                        "gateways.{$id}.{$secret}",
                        'Must be ' . self::SECRET_MAX_LENGTH . ' characters or fewer.',
                    );
                }
                $puts[$key] = $this->encryption()->encrypt($value, aad: $key);
            }
        }

        $store = $this->store();
        foreach ($forgets as $key) {
            $store->forget($key);
        }
        if ($puts !== []) {
            $store->putMany($puts);
        }

        return $this->show($request);
    }

    /** @return array<string,array<string,mixed>> */
    private function configuredGateways(): array
    {
        $out = [];
        foreach ((array) config($this->context, 'payvia.gateways', []) as $id => $config) {
            if (is_array($config)) {
                $out[(string) $id] = $config;
            }
        }

        return $out;
    }

    private function effectiveDefaultGateway(): ?string
    {
        $stored = $this->storedValue('payvia.default_gateway');
        if ($stored !== null && preg_match('/^[a-z0-9_-]+$/', trim($stored)) === 1) {
            return trim($stored);
        }
        $config = (string) config($this->context, 'payvia.default_gateway', '');

        return $config !== '' ? $config : null;
    }

    /** @param array<string,mixed> $config */
    private function effectiveEnabled(string $id, array $config): bool
    {
        $stored = $this->storedValue("payvia.gateways.{$id}.enabled");
        if ($stored !== null && in_array(strtolower(trim($stored)), ['1', 'true', '0', 'false'], true)) {
            return in_array(strtolower(trim($stored)), ['1', 'true'], true);
        }

        return (bool) ($config['enabled'] ?? false);
    }

    /**
     * A secret field's reportable state — booleans only: is an effective value present, and
     * does it come from a stored settings row or from env/config?
     *
     * @param array<string,mixed> $config
     * @return array{set: bool, source: ?string}
     */
    private function secretState(string $id, array $config, string $field): array
    {
        if ($this->storedValue("payvia.gateways.{$id}.{$field}") !== null) {
            return ['set' => true, 'source' => 'settings'];
        }

        $env = $config[$field] ?? null;
        if (is_string($env) && trim($env) !== '') {
            return ['set' => true, 'source' => 'env'];
        }

        return ['set' => false, 'source' => null];
    }

    /**
     * The absolute URL the merchant pastes into the gateway dashboard. Payvia's webhook route
     * is root-mounted (`POST /webhooks/{gateway}`); the origin half comes from the ONE trusted
     * origin authority ({@see CanonicalPublicOriginResolver} — never the request Host header).
     * Null when no resolver is bound: the UI simply omits the row rather than guessing.
     */
    private function webhookUrl(string $id): ?string
    {
        if ($this->origins === null) {
            return null;
        }

        try {
            return $this->origins->currentOrigin($this->context) . '/webhooks/' . rawurlencode($id);
        } catch (\Throwable) {
            return null;
        }
    }

    private function storedValue(string $key): ?string
    {
        try {
            $value = $this->store()->get($key);

            return is_string($value) && trim($value) !== '' ? $value : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function store(): CommerceSettingsStore
    {
        if ($this->store === null) {
            throw new \RuntimeException('Settings store is not available.');
        }

        return $this->store;
    }

    private function encryption(): EncryptionService
    {
        if ($this->encryption === null) {
            throw new \RuntimeException('Encryption service is not available.');
        }

        return $this->encryption;
    }
}
