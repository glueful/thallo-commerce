<?php

declare(strict_types=1);

namespace Thallo\Commerce\Http;

/**
 * A *deployment* configuration value the Commerce admin surface depends on is invalid or
 * unresolvable (Task 8, admin-commerce-area plan slice 3 — e.g. `commerce.currency` set to a
 * code {@see \Glueful\Extensions\Commerce\Support\Money::exponentFor()} does not recognise).
 * Mirrors {@see \Glueful\Extensions\Commerce\Reports\ReportConfigurationException}'s identical
 * reasoning: an invalid CONFIGURED value is an operator/deployment error, not a client one, and
 * must fail loudly rather than being silently defaulted (a silent `?? 2` exponent fallback would
 * misrender every price on the storefront/admin with no signal the deployment is wrong).
 *
 * Deliberately left uncaught by callers: the framework's generic exception handler
 * ({@see \Glueful\Http\Exceptions\Handler}) has no explicit mapping for this class, so it renders
 * via `renderGenericException()` at the default 500 status with the stable `INTERNAL_SERVER_ERROR`
 * `error_code` — a 500-family response with a stable code, never a 2xx with a guessed exponent.
 */
final class CommerceConfigurationException extends \RuntimeException
{
    public function __construct(
        public readonly string $configKey,
        string $reason,
    ) {
        parent::__construct("Invalid commerce configuration for '{$configKey}': {$reason}");
    }
}
