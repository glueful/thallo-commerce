<?php

declare(strict_types=1);

namespace Thallo\Commerce\Shop\Contribution;

use Thallo\Render\Contribution\TemplatePathContributor;

/**
 * Contributes `packages/thallo-commerce/templates/` into Render's theme resolution chain
 * (storefront-rendering spec §5.2): resolves BETWEEN the active app theme (which may override
 * `shop/index.twig` etc.) and the render pack's own default fallback. Registered INSIDE the
 * `thallo.commerce` capability gate (capability-boundary pin — unlike the reserved-path
 * contributor, which stays unconditional): with the capability off these templates must not
 * exist in the Twig loader, so stored shop blocks fall to the missing-template fallback and no
 * shop markup or shop.js script tag reaches a rendered page.
 */
final class ShopTemplatePathContributor implements TemplatePathContributor
{
    public function contributorId(): string
    {
        return 'thallo-commerce.shop-templates';
    }

    public function priority(): int
    {
        return 0;
    }

    /** @return list<string> */
    public function templatePaths(): array
    {
        return [dirname(__DIR__, 3) . '/templates'];
    }
}
