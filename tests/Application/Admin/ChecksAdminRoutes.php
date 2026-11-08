<?php

declare(strict_types=1);

namespace App\Tests\Application\Admin;

use App\Admin\Entity\AdminUser;
use App\Admin\ValueObject\AdminRole;
use App\Cms\ValueObject\LandingKey;
use App\Cms\ValueObject\TextBlockKey;
use App\Cms\ValueObject\TierCode;
use App\Cms\ValueObject\VenueNumber;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Walks the router in every state a visitor can be in: whatever a later cycle adds under /admin is covered
 * without touching this test. The trait carries the tests themselves and runs on the app kernel and on the
 * probe kernel, which adds a page and the /admin/api routes.
 *
 * In each state the canonical URL must give the expected answer and its encoded spellings must give
 * exactly that answer: the router and the firewall read them as one path, so a guard has to as well.
 *
 * @phpstan-type Endpoint array{name: string, route: Route, method: string, path: string}
 * @phpstan-type Answer array{status: int, location: ?string, type: ?string}
 * @phpstan-type Expected array{int, ?string}
 */
trait ChecksAdminRoutes
{
    private const string UUID = '0199f0d0-0000-7000-8000-000000000001';

    private const string LOGIN = '/admin/login';
    private const string CODE_STEP = '/admin/2fa';
    private const string ENROLMENT = '/admin/2fa/einrichten';

    /** The routes `access_control` or the logout listener answer before any #[IsGranted] does. */
    private const array OPEN_ROUTES = ['admin_login', 'admin_2fa_login', 'admin_2fa_check', '_logout_admin'];

    /** Requests to these can end the state they were sent in, so each one gets a fresh session. */
    private const array SESSION_CHANGING_ROUTES = ['admin_login', 'admin_2fa_check', '_logout_admin'];

    /** Two are enough per URL and state: the guards' own tests go through all of encodedSpellings(). */
    private const array WALKED_SPELLINGS = ['first letter encoded', 'slash encoded'];

    public function test_every_route_follows_the_convention(): void
    {
        foreach ($this->adminRoutes() as $name => $route) {
            self::assertTrue('_logout_admin' === $name || str_starts_with($name, 'admin_'), $name.' starts with admin_.');
            self::assertTrue($route->getOption('unlocalized'), $name.' opts out of the localized form.');
        }
    }

    public function test_every_controller_states_its_role(): void
    {
        foreach ($this->adminRoutes() as $name => $route) {
            if (!\in_array($name, self::OPEN_ROUTES, true)) {
                $this->requiredRole($name, $route);
            }
        }
    }

    public function test_an_anonymous_visitor_reaches_nothing_but_the_login(): void
    {
        $this->walk(
            fn () => $this->client->getCookieJar()->clear(),
            static fn (array $endpoint): ?array => match (true) {
                self::isJsonEndpoint($endpoint) => [Response::HTTP_UNAUTHORIZED, null],
                'admin_login' === $endpoint['name'] => 'GET' === $endpoint['method'] ? [Response::HTTP_OK, null] : null,
                '_logout_admin' === $endpoint['name'] => [Response::HTTP_FORBIDDEN, null],
                default => [Response::HTTP_FOUND, self::LOGIN],
            },
        );
    }

    public function test_the_code_step_reaches_nothing_but_the_code_form(): void
    {
        $staff = $this->createStaff();

        $this->walk(
            fn () => $this->startOver($staff['user']->email()),
            static fn (array $endpoint): ?array => match ($endpoint['name']) {
                'admin_2fa_login' => [Response::HTTP_OK, null],
                'admin_login' => 'GET' === $endpoint['method'] ? [Response::HTTP_OK, null] : null,
                '_logout_admin' => [Response::HTTP_FORBIDDEN, null],
                default => [Response::HTTP_FOUND, self::CODE_STEP],
            },
        );
    }

    public function test_an_unenrolled_user_reaches_nothing_but_enrolment(): void
    {
        $staff = $this->createStaff(enrolled: false);

        $this->walk(
            fn () => $this->startOver($staff['user']->email()),
            static fn (array $endpoint): ?array => match (true) {
                self::isJsonEndpoint($endpoint) => [Response::HTTP_FORBIDDEN, null],
                'admin_totp_enrol' === $endpoint['name'] => 'GET' === $endpoint['method'] ? [Response::HTTP_OK, null] : null,
                'admin_login' === $endpoint['name'] => 'GET' === $endpoint['method'] ? [Response::HTTP_FOUND, '/admin'] : null,
                \in_array($endpoint['name'], ['_logout_admin', 'admin_2fa_login', 'admin_2fa_check'], true) => [Response::HTTP_FORBIDDEN, null],
                default => [Response::HTTP_FOUND, self::ENROLMENT],
            },
        );
    }

    public function test_an_editor_reaches_what_the_role_allows(): void
    {
        $this->walkFullyAuthenticated(AdminRole::Editor);
    }

    public function test_an_admin_reaches_every_route(): void
    {
        $this->walkFullyAuthenticated(AdminRole::Admin);
    }

    public function test_a_trailing_slash_passes_the_same_guards(): void
    {
        $turnedAwayBy = static fn (string $target): \Closure => static fn (array $endpoint): array => [Response::HTTP_FOUND, $target];
        $enrolled = $this->createStaff();
        $unenrolled = $this->createStaff(enrolled: false, displayName: 'Beat Meier');

        // A GET JSON route gets the answer it gets without the slash: no guard redirects a JSON client to a page.
        $this->walkTrailingSlash(
            fn () => $this->client->getCookieJar()->clear(),
            static fn (array $endpoint): array => self::isJsonEndpoint($endpoint) ? [Response::HTTP_UNAUTHORIZED, null] : [Response::HTTP_FOUND, self::LOGIN],
        );
        $this->walkTrailingSlash(fn () => $this->startOver($enrolled['user']->email()), $turnedAwayBy(self::CODE_STEP));
        $this->walkTrailingSlash(
            fn () => $this->startOver($unenrolled['user']->email()),
            static fn (array $endpoint): array => match (true) {
                self::isJsonEndpoint($endpoint) => [Response::HTTP_FORBIDDEN, null],
                \in_array($endpoint['name'], ['admin_login', 'admin_totp_enrol'], true) => [Response::HTTP_MOVED_PERMANENTLY, $endpoint['path']],
                default => [Response::HTTP_FOUND, self::ENROLMENT],
            },
        );
        $this->walkTrailingSlash(
            fn () => $this->startOverLoggedIn($enrolled['user'], $enrolled['secret']),
            static fn (array $endpoint): array => [Response::HTTP_MOVED_PERMANENTLY, $endpoint['path']],
        );
    }

    /**
     * The router answers these before anything else runs, so one logged-in user and two paths prove it.
     */
    public function test_other_spellings_match_no_route(): void
    {
        $this->logInAs();

        foreach (['/admin', '/admin/benutzer/'.self::UUID] as $path) {
            $rest = substr($path, 6);

            $insideTheFirewall = [
                $path.'%2F',
                $path.'%0A',
                '/admin/./'.$rest,
                '/admin/%2e/'.$rest,
                '/admin/%2E/'.$rest,
                '/admin/x/../'.$rest,
                '/admin/x/%2e%2e/'.$rest,
                '/admin/x/%2E%2E/'.$rest,
                '/admin/%2e%2e/admin'.$rest,
            ];
            if ('' !== $rest) {
                $insideTheFirewall[] = '/admin/'.$rest;
            }

            // Some of these are inside the firewall, some not: no route is the point, not the headers.
            $noRoute = [
                $path.'%00',
                $path.';x=1',
                '/'.$path,
                '/ADMIN'.$rest,
                '/Admin'.$rest,
                '/aDmIn'.$rest,
                '/%41dmin'.$rest,
                '/%41DMIN'.$rest,
                '/x/..'.$path,
                '/x/%2e%2e'.$path,
                '/%2e'.$path,
                '/admin%252F'.ltrim($rest, '/'),
                '/%2561dmin'.$rest,
                '/admin%5C'.ltrim($rest, '/'),
                '/admin;x=1'.$rest,
            ];

            foreach ($insideTheFirewall as $url) {
                $this->requestUrl('GET', $url);
                self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode(), $url);
                $this->assertNotCachedOrIndexed($url);
            }
            foreach ($noRoute as $url) {
                $this->requestUrl('GET', $url);
                self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode(), $url);
            }
        }
    }

    public function test_nothing_in_the_app_reads_the_raw_path(): void
    {
        foreach ($this->sourceFiles() as $file) {
            self::assertStringNotContainsString('getPathInfo(', (string) file_get_contents($file), $file.' asks AdminArea instead (F-C1).');
        }
    }

    /**
     * @return array<string, Route>
     */
    private function adminRoutes(): array
    {
        $router = self::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);

        $routes = [];
        foreach ($router->getRouteCollection()->all() as $name => $route) {
            if (1 === preg_match('#^/admin(/|$)#', $route->getPath())) {
                $routes[$name] = $route;
            }
        }
        self::assertNotEmpty($routes);

        return $routes;
    }

    /**
     * One entry per route and method. A route that allows any method is tried with GET and POST.
     *
     * @return list<Endpoint>
     */
    private function adminEndpoints(): array
    {
        $endpoints = [];
        foreach ($this->adminRoutes() as $name => $route) {
            if (!$this->isWalked($name)) {
                continue;
            }

            foreach ($route->getMethods() ?: ['GET', 'POST'] as $method) {
                $path = (string) preg_replace_callback(
                    '/\{([^}]+)\}/',
                    static fn (array $match): string => self::routeSample($route, $match[1]),
                    $route->getPath(),
                );
                $endpoints[] = ['name' => $name, 'route' => $route, 'method' => $method, 'path' => $path];
            }
        }

        return $endpoints;
    }

    /**
     * A value the parameter's requirement accepts, by requirement: a venue number for `VenueNumber::REQUIREMENT`,
     * a landing page for `LandingKey::REQUIREMENT`, a text block for `TextBlockKey::REQUIREMENT` (#26), the seeded
     * Free tier for `TierCode::REQUIREMENT` (F12), a UUID for everything else. A route with another strict
     * requirement still gets the UUID, its 404 fails the walk, and the parameter has to be taught here.
     */
    private static function routeSample(Route $route, string $parameter): string
    {
        return match ($route->getRequirement($parameter)) {
            VenueNumber::REQUIREMENT => '1',
            LandingKey::REQUIREMENT => 'canton.zh',
            TextBlockKey::REQUIREMENT => 'listing.intro',
            TierCode::REQUIREMENT => 'free',
            default => self::UUID,
        };
    }

    private function isWalked(string $routeName): bool
    {
        return true;
    }

    /**
     * @param \Closure(): mixed                   $enterState
     * @param \Closure(Endpoint): (Expected|null) $expected   null: any answer that is not a guard's
     */
    private function walk(\Closure $enterState, \Closure $expected): void
    {
        $enterState();

        foreach ($this->adminEndpoints() as $endpoint) {
            $label = $endpoint['method'].' '.$endpoint['path'];
            $changesSession = \in_array($endpoint['name'], self::SESSION_CHANGING_ROUTES, true);

            if ($changesSession) {
                $enterState();
            }
            $answer = $this->answer($endpoint['method'], $endpoint['path']);

            $expectedAnswer = $expected($endpoint);
            if (null === $expectedAnswer) {
                $this->assertNotTurnedAway($answer, $label);
            } else {
                self::assertSame($expectedAnswer, [$answer['status'], $answer['location']], $label);
            }

            $spellings = array_intersect_key(self::encodedSpellings($endpoint['path']), array_flip(self::WALKED_SPELLINGS));
            foreach ($spellings as $spelling => $url) {
                if ($changesSession) {
                    $enterState();
                }

                self::assertSame($answer, $this->answer($endpoint['method'], $url), $label.', '.$spelling.': '.$url);
            }
        }
    }

    private function walkFullyAuthenticated(AdminRole $role): void
    {
        $staff = $this->createStaff($role);

        $this->walk(
            fn () => $this->startOverLoggedIn($staff['user'], $staff['secret']),
            fn (array $endpoint): ?array => match (true) {
                // Without the CSRF header, which is all a walk can say about a JSON write. A JSON read needs none.
                self::isJsonEndpoint($endpoint) && 'GET' !== $endpoint['method'] => [Response::HTTP_FORBIDDEN, null],
                \in_array($endpoint['name'], ['_logout_admin', 'admin_2fa_login', 'admin_2fa_check'], true) => [Response::HTTP_FORBIDDEN, null],
                'admin_login' === $endpoint['name'] => null,
                'admin_totp_enrol' === $endpoint['name'] => [Response::HTTP_FOUND, '/admin'],
                AdminRole::Editor === $role && 'ROLE_ADMIN' === $this->requiredRole($endpoint['name'], $endpoint['route']) => [Response::HTTP_FORBIDDEN, null],
                default => null,
            },
        );
    }

    /**
     * @param \Closure(): mixed            $enterState
     * @param \Closure(Endpoint): Expected $expectedForGet
     */
    private function walkTrailingSlash(\Closure $enterState, \Closure $expectedForGet): void
    {
        $enterState();

        foreach ($this->adminEndpoints() as $endpoint) {
            $url = $endpoint['path'].'/';
            $answer = $this->answer($endpoint['method'], $url);

            // The router redirects only GET and HEAD to the path without the slash.
            self::assertSame(
                'GET' === $endpoint['method'] ? $expectedForGet($endpoint) : [Response::HTTP_NOT_FOUND, null],
                [$answer['status'], $answer['location']],
                $endpoint['method'].' '.$url,
            );
        }
    }

    /**
     * @return Answer
     */
    private function answer(string $method, string $url): array
    {
        $this->requestUrl($method, $url);
        $this->assertNotCachedOrIndexed($method.' '.$url);

        $response = $this->client->getResponse();
        $location = $response->headers->get('Location');

        return [
            'status' => $response->getStatusCode(),
            'location' => null === $location ? null : str_replace('http://localhost', '', $location),
            'type' => $response->headers->get('Content-Type'),
        ];
    }

    /**
     * The host is spelled out, or BrowserKit reads a leading `//admin` as a host.
     */
    private function requestUrl(string $method, string $url): void
    {
        $this->sendSessionCookieEverywhere();
        $this->client->request($method, 'http://localhost'.$url);
    }

    /**
     * @param Answer $answer
     */
    private function assertNotTurnedAway(array $answer, string $label): void
    {
        self::assertNotContains($answer['status'], [Response::HTTP_UNAUTHORIZED, Response::HTTP_FORBIDDEN], $label);
        self::assertLessThan(Response::HTTP_INTERNAL_SERVER_ERROR, $answer['status'], $label);
        self::assertNotContains($answer['location'], [self::LOGIN, self::CODE_STEP, self::ENROLMENT], $label);
    }

    private function startOver(string $email): void
    {
        $this->client->getCookieJar()->clear();
        $this->forgetThrottling();
        $this->submitPassword($email);
    }

    private function startOverLoggedIn(AdminUser $user, ?string $secret): void
    {
        $this->client->getCookieJar()->clear();
        $this->logIn($user, $secret);
    }

    /**
     * The role of a route: the controller's #[IsGranted], which every route but the open ones must carry.
     */
    private function requiredRole(string $name, Route $route): string
    {
        $controller = $route->getDefault('_controller');
        self::assertIsString($controller, $name);

        [$class, $method] = [...explode('::', $controller), '__invoke'];
        self::assertTrue(class_exists($class), $name);

        $roles = [];
        foreach ([new \ReflectionClass($class), new \ReflectionMethod($class, $method)] as $reflection) {
            foreach ($reflection->getAttributes(IsGranted::class) as $attribute) {
                $roles[] = $attribute->newInstance()->attribute;
            }
        }
        self::assertNotEmpty($roles, $name.' carries #[IsGranted].');

        return \in_array('ROLE_ADMIN', $roles, true) ? 'ROLE_ADMIN' : 'ROLE_EDITOR';
    }

    /**
     * @param Endpoint $endpoint
     */
    private static function isJsonEndpoint(array $endpoint): bool
    {
        return str_starts_with($endpoint['path'], '/admin/api/');
    }

    /**
     * @return iterable<string>
     */
    private function sourceFiles(): iterable
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(\dirname(__DIR__, 3).'/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            \assert($file instanceof \SplFileInfo);
            if ('php' === $file->getExtension()) {
                yield $file->getPathname();
            }
        }
    }
}
