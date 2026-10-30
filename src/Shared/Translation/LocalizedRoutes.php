<?php

declare(strict_types=1);

namespace App\Shared\Translation;

use Symfony\Component\Routing\RouteCollection;

/**
 * Convention (S4): every public route is declared in Symfony's localized form,
 * #[Route(path: ['de' => '/…'], name: '…')], so Symfony registers one route per locale
 * (`name.de`, …) with the `_locale` and `_canonical_route` defaults. Every canonical route
 * needs a path for each enabled locale.
 *
 * Exempt are technical routes:
 * - a route whose name starts with `_` (`_styleguide`, `_profiler*`, `_wdt*`, `_preview_error`)
 *   or whose path starts with `/_` (`/_search/*`, profiler, web debug toolbar);
 * - a route that opts out at its declaration with `options: [LocalizedRoutes::UNLOCALIZED => true]`.
 *
 * PublicRoutesAreLocalizedTest runs violations() over the app's router.
 */
final class LocalizedRoutes
{
    public const UNLOCALIZED = 'unlocalized';

    /**
     * @param list<string> $enabledLocales
     *
     * @return list<string>
     */
    public static function violations(RouteCollection $routes, array $enabledLocales): array
    {
        $violations = [];
        $localesByCanonical = [];

        foreach ($routes->all() as $name => $route) {
            if (str_starts_with($name, '_')
                || str_starts_with($route->getPath(), '/_')
                || true === $route->getOption(self::UNLOCALIZED)) {
                continue;
            }

            $canonical = $route->getDefault('_canonical_route');
            $locale = $route->getDefault('_locale');

            if (!\is_string($canonical) || '' === $canonical || !\is_string($locale) || '' === $locale) {
                $violations[] = \sprintf('%s (%s): not declared in the localized form', $name, $route->getPath());

                continue;
            }

            $localesByCanonical[$canonical][] = $locale;
        }

        foreach ($localesByCanonical as $canonical => $locales) {
            foreach ($enabledLocales as $enabledLocale) {
                if (!\in_array($enabledLocale, $locales, true)) {
                    $violations[] = \sprintf('%s: no path for locale "%s"', $canonical, $enabledLocale);
                }
            }
        }

        sort($violations);

        return $violations;
    }
}
