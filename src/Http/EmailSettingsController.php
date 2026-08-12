<?php

declare(strict_types=1);

namespace Thallo\Commerce\Http;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Commerce\Settings\CommerceSettingsStore;

/**
 * Order-email settings admin API (store-settings spec §4.2 follow-up):
 * `GET/PUT /v1/admin/commerce/emails` — the per-template on/off switches for the four buyer
 * order emails. Template CONTENT (subject/body/test/reset) stays owned by the email-notification
 * extension's `/email/templates` API — the Emails tab reuses it filtered to this pack's owner —
 * so this endpoint carries only what commerce owns: whether each template sends at all.
 *
 * Keys EQUAL config keys (`thallo-commerce.email.{template}.enabled`, defaults ON in the pack
 * config): a stored '0' row disables one template, clearing deletes the row so the default shows
 * through. `commerce_mailer_active` reports the `commerce.email.enabled` env flag — when the
 * commerce extension's own dormant mailer is switched on, thallo's sender stands down entirely
 * (one mailer, never double emails) and these switches govern nothing; the UI banners that state.
 */
final class EmailSettingsController
{
    /**
     * Short template names (the request/response vocabulary) — registry keys are `commerce.{name}`.
     *
     * `payment_request` (payment-links spec §2.4) is the one entry here whose PACK CONFIG default
     * is FALSE rather than true: it emails a live payment-link bearer credential, so an install
     * opts in deliberately. The `config(..., true)` fallback in {@see self::show()} below is a
     * generic backstop for a missing key, which is exactly why the pack config sets this one
     * explicitly (see `config/thallo-commerce.php`).
     */
    public const TEMPLATES = [
        'order_confirmation',
        'order_paid',
        'order_fulfilled',
        'order_canceled',
        'payment_request',
    ];

    public function __construct(
        private readonly ApplicationContext $context,
        private readonly ?CommerceSettingsStore $store = null,
    ) {
    }

    #[ApiOperation(
        summary: 'Get order-email template switches',
        tags: ['Thallo Commerce'],
    )]
    public function show(Request $request): Response
    {
        $templates = [];
        foreach (self::TEMPLATES as $name) {
            $default = (bool) config($this->context, "thallo-commerce.email.{$name}.enabled", true);
            $stored = $this->storedFlag($name);
            $templates[] = [
                'template' => $name,
                'key' => "commerce.{$name}",
                'enabled' => [
                    'value' => $stored ?? $default,
                    'default' => $default,
                    'overridden' => $stored !== null,
                ],
            ];
        }

        return Response::success([
            'templates' => $templates,
            'commerce_mailer_active' => (bool) config($this->context, 'commerce.email.enabled', false),
        ], 'Order email settings retrieved');
    }

    #[ApiOperation(
        summary: 'Update order-email template switches (null clears back to the default)',
        tags: ['Thallo Commerce'],
    )]
    public function update(Request $request): Response
    {
        $body = (array) json_decode((string) $request->getContent(), true);
        $templates = $body['templates'] ?? [];
        if (!is_array($templates)) {
            throw ValidationException::forField('templates', 'Must be an object keyed by template name.');
        }

        $puts = [];
        $forgets = [];
        foreach ($templates as $name => $enabled) {
            if (!is_string($name) || !in_array($name, self::TEMPLATES, true)) {
                throw ValidationException::forField("templates.{$name}", 'Unknown order email template.');
            }
            if ($enabled === null) {
                $forgets[] = "thallo-commerce.email.{$name}.enabled";
                continue;
            }
            if (!is_bool($enabled)) {
                throw ValidationException::forField("templates.{$name}", 'Must be true, false, or null.');
            }
            $puts["thallo-commerce.email.{$name}.enabled"] = $enabled ? '1' : '0';
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

    /** The stored switch, when a well-formed row exists — null means "no override". */
    private function storedFlag(string $name): ?bool
    {
        try {
            $value = $this->store()->get("thallo-commerce.email.{$name}.enabled");
            if (!is_string($value)) {
                return null;
            }
            $flag = strtolower(trim($value));
            if (!in_array($flag, ['1', 'true', '0', 'false'], true)) {
                return null;
            }

            return in_array($flag, ['1', 'true'], true);
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
}
