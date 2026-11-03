<?php

declare(strict_types=1);

namespace App\Tests\Application\Portal;

use App\Portal\Security\PortalArea;
use App\Tests\Support\Portal\PortalSession;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie as BrowserCookie;
use Symfony\Component\HttpFoundation\Cookie;
use Zenstruck\Foundry\Test\Factories;

/**
 * The manager's cookie has its own name and path, whichever way it leaves. Storage is mocked here, so this does
 * not prove it on PHP's native session: `tests/Deploy/release-check.sh` covers the login page only.
 */
final class PortalSessionCookieTest extends WebTestCase
{
    use Factories;
    use PortalSession;

    public function test_a_failed_login_sets_the_cookie_on_the_account_path(): void
    {
        $this->submitManagerLogin($this->createManager()->email(), 'wrong-password');

        $cookie = $this->portalCookie();
        self::assertFalse($cookie->isCleared());
        self::assertSame(PortalArea::COOKIE_PATH, $cookie->getPath());
        self::assertSame(Cookie::SAMESITE_STRICT, $cookie->getSameSite());
        self::assertTrue($cookie->isHttpOnly());
    }

    public function test_a_real_login_sets_the_cookie_on_the_account_path(): void
    {
        $this->logInAsManager();

        $cookie = $this->portalCookie();
        self::assertFalse($cookie->isCleared());
        self::assertSame(PortalArea::COOKIE_PATH, $cookie->getPath());
    }

    /**
     * A new id, not a delete: the stateless CSRF check writes into the invalidated session, as on `/admin`.
     * The literal path keeps this failing if the constant itself moved.
     */
    public function test_logout_ends_the_session_and_keeps_the_cookie_on_the_account_path(): void
    {
        $this->logInAsManager();
        $page = $this->client->request('GET', '/konto/profil');
        self::assertResponseIsSuccessful();
        $oldCookie = $this->client->getCookieJar()->get(PortalArea::SESSION_COOKIE, '/konto');
        self::assertNotNull($oldCookie);

        $this->client->submit($page->filter('.sidebar__logout-form')->form());

        self::assertResponseRedirects('/konto/anmelden');
        self::assertSame('/konto', $this->portalCookie()->getPath());

        $this->client->getCookieJar()->set($oldCookie);
        $this->client->request('GET', '/konto');

        self::assertResponseRedirects('/konto/anmelden');
    }

    public function test_a_stale_cookie_is_deleted_on_the_account_path(): void
    {
        $this->client->getCookieJar()->set(new BrowserCookie(PortalArea::SESSION_COOKIE, 'stale0000', null, PortalArea::COOKIE_PATH));

        $this->client->request('GET', '/konto/anmelden');

        $cookies = $this->client->getResponse()->headers->getCookies();
        self::assertCount(1, $cookies);
        self::assertInstanceOf(Cookie::class, $cookies[0]);
        self::assertTrue($cookies[0]->isCleared());
        self::assertSame(PortalArea::SESSION_COOKIE, $cookies[0]->getName());
        self::assertSame(PortalArea::COOKIE_PATH, $cookies[0]->getPath());
    }

    /**
     * The back office's 30-day cookie (AdminSessionCookieListener) does not reach the account: a browser restart
     * still ends a manager's session.
     */
    public function test_the_account_cookie_stays_a_browser_session_cookie(): void
    {
        $this->logInAsManager();
        self::assertSame(0, $this->portalCookie()->getExpiresTime());

        $this->client->request('GET', '/konto/profil');

        self::assertResponseIsSuccessful();
        self::assertSame([], array_filter(
            $this->client->getResponse()->headers->getCookies(),
            static fn (Cookie $cookie): bool => PortalArea::SESSION_COOKIE === $cookie->getName(),
        ));
    }

    public function test_an_anonymous_login_page_sets_no_cookie(): void
    {
        $this->client->request('GET', '/konto/anmelden');

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->client->getResponse()->headers->getCookies());
    }

    private function portalCookie(): Cookie
    {
        $cookies = array_values(array_filter(
            $this->client->getResponse()->headers->getCookies(),
            static fn (Cookie $cookie): bool => PortalArea::SESSION_COOKIE === $cookie->getName(),
        ));
        self::assertCount(1, $cookies);

        return $cookies[0];
    }
}
