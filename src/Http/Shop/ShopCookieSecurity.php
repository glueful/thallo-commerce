<?php

declare(strict_types=1);

namespace Thallo\Commerce\Http\Shop;

use Glueful\Bootstrap\ApplicationContext;

use function config;

/**
 * Whether the shop's cookies carry `Secure`. They follow the storefront session cookie's own
 * switch, `auth.session_cookie.secure` (`SESSION_COOKIE_SECURE`, on by default), so one setting
 * serves a site on plain http, where a browser drops a Secure cookie (Safari on localhost, a
 * `.test` host).
 */
final class ShopCookieSecurity
{
    public static function secure(ApplicationContext $context): bool
    {
        $value = config($context, 'auth.session_cookie.secure', true);

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true;
    }
}
