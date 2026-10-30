<?php

declare(strict_types=1);

namespace App\Tests\Application\Shared;

use App\Tests\Support\Admin\AdminSession;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Routing\RouterInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * S10, ADM-6: installing the back office changes nothing for visitors. Only /admin ever starts a session.
 * Pages with path parameters keep their own no-cookie tests (#6, #8).
 */
final class PublicPagesStartNoSessionTest extends WebTestCase
{
    use AdminSession;
    use Factories;

    public function test_no_public_page_starts_a_session_or_sets_a_cookie(): void
    {
        $router = self::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);
        $checked = 0;

        foreach ($router->getRouteCollection()->all() as $name => $route) {
            $path = $route->getPath();
            $requiresParameter = [] !== array_diff($route->compile()->getPathVariables(), array_keys($route->getDefaults()));
            $answersGet = [] === $route->getMethods() || \in_array('GET', $route->getMethods(), true);

            if ($requiresParameter || !$answersGet || str_starts_with($path, '/admin') || str_starts_with($path, '/_')) {
                continue;
            }

            $this->client->request('GET', $path);
            $response = $this->client->getResponse();

            self::assertLessThan(500, $response->getStatusCode(), $name);
            self::assertSame([], $response->headers->getCookies(), $name.' sets no cookie.');
            self::assertFalse($response->headers->has('Set-Cookie'), $name);
            $request = $this->client->getRequest();
            self::assertFalse($request->cookies->has('PHPSESSID'), $name);
            ++$checked;
        }

        self::assertGreaterThan(0, $checked);
    }

    public function test_a_logged_in_staff_member_stays_anonymous_on_public_pages(): void
    {
        $staff = $this->createStaff();
        self::assertIsString($staff['secret']);
        $this->logInFully($staff['user'], $staff['secret']);
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();

        $cookies = $this->client->getCookieJar()->all();
        self::assertNotEmpty($cookies);
        foreach ($cookies as $cookie) {
            self::assertSame('/admin', $cookie->getPath());
        }

        $this->client->request('GET', '/');

        self::assertSame([], $this->client->getResponse()->headers->getCookies());
        self::assertSame([], $this->client->getRequest()->cookies->all());
    }
}
