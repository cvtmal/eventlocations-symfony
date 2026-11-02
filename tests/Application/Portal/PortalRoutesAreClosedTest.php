<?php

declare(strict_types=1);

namespace App\Tests\Application\Portal;

use App\Cms\ValueObject\VenueNumber;
use App\Tests\Support\Portal\PortalSession;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouterInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * Walks the router for an anonymous visitor: whatever a later cycle adds under /konto is closed without touching
 * this test, or the test fails. Copy of `ChecksAdminRoutes`' walk. The router and the firewall read an encoded
 * spelling as one path, so each spelling has to give exactly the canonical URL's answer.
 *
 * @phpstan-type Endpoint array{name: string, method: string, path: string}
 * @phpstan-type Answer array{status: int, location: ?string, type: ?string}
 */
final class PortalRoutesAreClosedTest extends WebTestCase
{
    use Factories;
    use PortalSession;

    private const string UUID = '0199f0d0-0000-7000-8000-000000000001';

    private const string LOGIN = '/konto/anmelden';

    /** Nothing else under /konto opens without a login. A new page belongs behind it unless it is added here. */
    private const array OPEN_ROUTES = [
        'portal_login',
        '_logout_portal',
        'portal_register',
        'portal_forgot_password',
        'portal_reset_password',
    ];

    public function test_the_open_routes_exist_and_the_closed_ones_are_walked(): void
    {
        $names = array_keys($this->portalRoutes());

        self::assertSame([], array_values(array_diff(self::OPEN_ROUTES, $names)));
        self::assertContains('portal_home', $names);
        self::assertContains('portal_profile', $names);
    }

    public function test_an_anonymous_visitor_reaches_nothing_but_the_open_routes(): void
    {
        foreach ($this->portalEndpoints() as $endpoint) {
            $label = $endpoint['method'].' '.$endpoint['path'];
            $answer = $this->answer($endpoint['method'], $endpoint['path']);

            if (\in_array($endpoint['name'], self::OPEN_ROUTES, true)) {
                self::assertLessThan(Response::HTTP_INTERNAL_SERVER_ERROR, $answer['status'], $label);
            } else {
                self::assertSame([Response::HTTP_FOUND, self::LOGIN], [$answer['status'], $answer['location']], $label);
            }

            foreach (self::encodedSpellings($endpoint['path']) as $spelling => $url) {
                self::assertSame($answer, $this->answer($endpoint['method'], $url), $label.', '.$spelling.': '.$url);
            }
        }
    }

    /**
     * `^/konto/api/` in `access_control` has the same reason: the firewall answers, so an anonymous write learns
     * nothing from the CSRF listener.
     */
    public function test_an_anonymous_write_is_stopped_by_the_firewall_before_any_csrf_check(): void
    {
        $this->client->request('POST', '/konto/profil', ['profile' => ['firstName' => 'Mallory']]);

        $response = $this->client->getResponse();
        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertSame(self::LOGIN, str_replace('http://localhost', '', (string) $response->headers->get('Location')));
    }

    /**
     * @return array<string, Route>
     */
    private function portalRoutes(): array
    {
        $router = self::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);

        $routes = [];
        foreach ($router->getRouteCollection()->all() as $name => $route) {
            if (1 === preg_match('#^/konto(/|$)#', $route->getPath())) {
                $routes[$name] = $route;
            }
        }

        return $routes;
    }

    /**
     * One entry per route and method. A route that allows any method is tried with GET and POST.
     *
     * @return list<Endpoint>
     */
    private function portalEndpoints(): array
    {
        $endpoints = [];
        foreach ($this->portalRoutes() as $name => $route) {
            $path = (string) preg_replace_callback(
                '/\{([^}]+)\}/',
                static fn (array $match): string => self::routeSample($route, $match[1]),
                $route->getPath(),
            );

            foreach ($route->getMethods() ?: ['GET', 'POST'] as $method) {
                $endpoints[] = ['name' => $name, 'method' => $method, 'path' => $path];
            }
        }

        return $endpoints;
    }

    /**
     * A value the parameter's requirement accepts, as in `ChecksAdminRoutes`: a venue number for
     * `VenueNumber::REQUIREMENT`, a UUID for everything else. Another strict requirement gets the UUID, its 404
     * fails the walk, and the parameter has to be taught here.
     */
    private static function routeSample(Route $route, string $parameter): string
    {
        return VenueNumber::REQUIREMENT === $route->getRequirement($parameter) ? '1' : self::UUID;
    }

    /**
     * @return array<string, string> description => URL path
     */
    private static function encodedSpellings(string $path): array
    {
        $spellings = ['first letter encoded' => '/%6B'.substr($path, 2)];
        if ('/konto' !== $path) {
            $spellings['slash encoded'] = '/konto%2F'.substr($path, 7);
        }

        return $spellings;
    }

    /**
     * Every request starts with an empty jar and no history: a rejected login leaves a session behind, and a
     * Referer would be CSRF evidence.
     *
     * @return Answer
     */
    private function answer(string $method, string $url): array
    {
        $this->client->restart();
        $this->client->request($method, 'http://localhost'.$url);

        $response = $this->client->getResponse();
        $label = $method.' '.$url;
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'), $label.' has no-store.');
        self::assertTrue($response->headers->hasCacheControlDirective('private'), $label.' is private.');
        self::assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'), $label);

        $location = $response->headers->get('Location');

        return [
            'status' => $response->getStatusCode(),
            'location' => null === $location ? null : str_replace('http://localhost', '', $location),
            'type' => $response->headers->get('Content-Type'),
        ];
    }
}
