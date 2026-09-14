<?php

declare(strict_types=1);

namespace Thallo\Commerce\Shop\Contribution;

use Thallo\Render\Contribution\StylesheetContributor;

/**
 * The storefront stylesheet (visual builder spec §2.2, §2.6): delivered inside the theme
 * artifact's `@layer theme` while the commerce capability is on, so storefront chrome is styled
 * at first paint on every page and never competes with managed settings. Registered from the
 * capability-enabled branch only, like the pack's template paths.
 */
final class ShopStylesheetContributor implements StylesheetContributor
{
    public function contributorId(): string
    {
        return 'thallo-commerce.shop-styles';
    }

    public function priority(): int
    {
        return 0;
    }

    public function stylesheets(): array
    {
        return [dirname(__DIR__, 3) . '/assets/shop.css'];
    }
}
