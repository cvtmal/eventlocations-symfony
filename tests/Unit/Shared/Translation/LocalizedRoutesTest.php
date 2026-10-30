<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Translation;

use App\Shared\Translation\LocalizedRoutes;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

final class LocalizedRoutesTest extends TestCase
{
    public function test_plain_route_is_reported(): void
    {
        $routes = new RouteCollection();
        $routes->add('x', new Route('/x'));

        self::assertSame(['x (/x): not declared in the localized form'], LocalizedRoutes::violations($routes, ['de']));
    }

    public function test_localized_route_passes(): void
    {
        $routes = new RouteCollection();
        $routes->add('x.de', $this->localized('/x', 'de', 'x'));

        self::assertSame([], LocalizedRoutes::violations($routes, ['de']));
    }

    public function test_missing_path_for_an_enabled_locale_is_reported(): void
    {
        $routes = new RouteCollection();
        $routes->add('x.de', $this->localized('/x', 'de', 'x'));

        self::assertSame(['x: no path for locale "fr"'], LocalizedRoutes::violations($routes, ['de', 'fr']));
    }

    public function test_route_named_with_underscore_is_exempt(): void
    {
        $routes = new RouteCollection();
        $routes->add('_styleguide', new Route('/styleguide-anything'));

        self::assertSame([], LocalizedRoutes::violations($routes, ['de']));
    }

    public function test_path_starting_with_underscore_is_exempt(): void
    {
        $routes = new RouteCollection();
        $routes->add('search_count', new Route('/_search/count'));

        self::assertSame([], LocalizedRoutes::violations($routes, ['de']));
    }

    public function test_unlocalized_option_exempts_a_route(): void
    {
        $routes = new RouteCollection();
        $routes->add('health', new Route('/health', options: [LocalizedRoutes::UNLOCALIZED => true]));

        self::assertSame([], LocalizedRoutes::violations($routes, ['de']));
    }

    private function localized(string $path, string $locale, string $canonical): Route
    {
        return new Route($path, ['_locale' => $locale, '_canonical_route' => $canonical]);
    }
}
