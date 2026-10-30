<?php

declare(strict_types=1);

namespace App\Tests\Application\Shared\Translation;

use App\Shared\Translation\LocalizedRoutes;
use App\Tests\Support\Translation\KernelLocales;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * Every public route is declared in the localized form and has a path for each enabled
 * locale (S4). Technical routes opt out; see LocalizedRoutes.
 */
final class PublicRoutesAreLocalizedTest extends KernelTestCase
{
    public function test_every_public_route_is_localized_for_every_enabled_locale(): void
    {
        $container = self::getContainer();

        $router = $container->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);

        $locales = KernelLocales::enabled($container);

        self::assertSame([], LocalizedRoutes::violations($router->getRouteCollection(), $locales));
    }
}
