<?php

declare(strict_types=1);

namespace Thallo\Commerce\Http\Shop;

use Glueful\Bootstrap\ApplicationContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Thallo\Render\Http\Middleware\RenderPageCache;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\TwigFactory;

use function config;

/**
 * The ONE shop-page render seam (storefront-v1 Task 7) — extracted verbatim from
 * {@see ShopCatalogController}'s previously private `render()` so the wishlist page (and any
 * later shop surface) renders through the IDENTICAL mechanism instead of duplicating it.
 * {@see ShopCatalogController} delegates every page/404 render here unchanged.
 *
 * Mirrors RenderController::render()'s reset-before-render discipline: `RenderContextExtension`
 * is a process-shared singleton (same instance the render pipeline uses), so every render
 * through it must reset render-scoped state first — never inherit a previous render's tags,
 * asset base, block depth, or theme-appearance override.
 */
final class ShopPageRenderer
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly TwigFactory $twigFactory,
        private readonly RenderContextExtension $extension,
    ) {
    }

    /**
     * @param array<string,mixed> $extra
     */
    public function render(Request $request, string $template, array $extra, int $status = 200): Response
    {
        $env = $this->twigFactory->environment();
        $locale = $this->defaultLocale();

        $this->extension->resetTags();
        $this->extension->resetPerRenderState();
        $this->extension->setAssetContext(null, null);
        $this->extension->setBlockAnnotations(false);
        $this->extension->setThemeAppearanceOverride(null, null);
        $this->extension->setLocale($locale);

        $context = [
            'site' => [
                'name' => (string) config($this->context, 'render.site_name', 'Thallo'),
                'locale' => $locale,
                'locales' => [],
            ],
            'current_path' => RenderPageCache::normalizePath($request->getPathInfo()),
            'presentation' => [
                'show_title' => true,
                'layout' => 'centered',
                'header' => 'default',
                'footer' => 'default',
            ],
        ] + $extra;

        $html = $env->render($template, $context);

        return new Response($html, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    private function defaultLocale(): string
    {
        // Storefront-rendering spec §9: "Locale is the render pipeline's resolved locale
        // (default locale in v1 when no locale route is present)" — Render itself carries no
        // separate injectable locale-manager service; RenderController's own defaultLocale()
        // and RenderServiceProvider::makeRenderContextExtension() both read this exact config
        // key. Shop pages have no locale route segment in v1, so this IS that resolved locale.
        return (string) config($this->context, 'i18n.default_locale', 'en');
    }
}
