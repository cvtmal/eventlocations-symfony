<?php

declare(strict_types=1);

namespace App\Tests\Application\Admin;

use App\Admin\EventListener\IdleTimeoutListener;
use App\Tests\Support\Admin\AdminSession;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Zenstruck\Foundry\Test\Factories;

/**
 * A fully logged-in session keeps its cookie for 30 days from the last request, so a browser restart keeps the
 * login (admin-session-30-days); every other back-office session keeps a browser-session cookie.
 */
final class AdminSessionCookieTest extends WebTestCase
{
    use AdminSession;
    use Factories;

    /** The back office's cookie under `session.storage.factory.mock_file`; PHPSESSID on a real session. */
    private const string SESSION_COOKIE = 'MOCKSESSID';

    public function test_a_full_login_gets_a_cookie_for_thirty_days(): void
    {
        $staff = $this->createStaff();
        self::assertIsString($staff['secret']);

        $this->logInFully($staff['user'], $staff['secret']);

        $cookie = $this->sessionCookie();
        $this->assertExpiresInThirtyDays($cookie);
        self::assertSame('/admin', $cookie->getPath());
        self::assertSame(Cookie::SAMESITE_STRICT, $cookie->getSameSite());
        self::assertTrue($cookie->isHttpOnly());
        self::assertNotNull($this->client->getCookieJar()->get(self::SESSION_COOKIE, '/admin')?->getExpiresTime());
    }

    public function test_every_request_moves_the_expiry(): void
    {
        $this->logInAs();

        $this->client->request('GET', '/admin');

        self::assertResponseIsSuccessful();
        $cookie = $this->sessionCookie();
        self::assertSame($this->client->getRequest()->cookies->get(self::SESSION_COOKIE), $cookie->getValue());
        $this->assertExpiresInThirtyDays($cookie);
    }

    public function test_the_code_step_keeps_a_browser_session_cookie(): void
    {
        $staff = $this->createStaff();

        $this->submitPassword($staff['user']->email());

        self::assertSame(0, $this->sessionCookie()->getExpiresTime());
    }

    public function test_a_failed_login_keeps_a_browser_session_cookie(): void
    {
        $staff = $this->createStaff();

        $this->submitPassword($staff['user']->email(), 'wrong-password');

        self::assertSame(0, $this->sessionCookie()->getExpiresTime());
    }

    public function test_logout_leaves_a_browser_session_cookie(): void
    {
        $this->logInAs();
        $page = $this->client->request('GET', '/admin');

        $this->client->submit($page->filter('form.sidebar__logout-form')->form());

        self::assertResponseRedirects('/admin/login');
        self::assertSame(0, $this->sessionCookie()->getExpiresTime());
    }

    public function test_an_encoded_url_moves_the_expiry_too(): void
    {
        $this->logInAs();
        $this->client->request('GET', '/admin');
        $this->sendSessionCookieEverywhere();

        $this->client->request('GET', self::encodedSpellings('/admin')['first letter encoded']);

        self::assertResponseIsSuccessful();
        $this->assertExpiresInThirtyDays($this->sessionCookie());
    }

    private function sessionCookie(): Cookie
    {
        $cookies = array_values(array_filter(
            $this->client->getResponse()->headers->getCookies(),
            static fn (Cookie $cookie): bool => self::SESSION_COOKIE === $cookie->getName() && '/admin' === $cookie->getPath(),
        ));
        self::assertCount(1, $cookies);

        return $cookies[0];
    }

    /**
     * The expiry, not Max-Age: `getMaxAge()` counts from the moment of the call.
     */
    private function assertExpiresInThirtyDays(Cookie $cookie): void
    {
        self::assertFalse($cookie->isCleared());
        self::assertEqualsWithDelta(time() + IdleTimeoutListener::IDLE_SECONDS, $cookie->getExpiresTime(), 5);
    }
}
