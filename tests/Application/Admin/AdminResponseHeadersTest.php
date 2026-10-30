<?php

declare(strict_types=1);

namespace App\Tests\Application\Admin;

use App\Tests\Support\Admin\AdminSession;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Zenstruck\Foundry\Test\Factories;

/**
 * S10, ADM-6: nothing under /admin is cached or indexed, whatever the response.
 */
final class AdminResponseHeadersTest extends WebTestCase
{
    use AdminSession;
    use Factories;

    public function test_the_login_page(): void
    {
        $this->client->request('GET', '/admin/login');

        self::assertResponseIsSuccessful();
        $this->assertNotCachedOrIndexed();
    }

    public function test_the_dashboard(): void
    {
        $this->logInAs();

        $this->client->request('GET', '/admin');

        self::assertResponseIsSuccessful();
        $this->assertNotCachedOrIndexed();
        self::assertSelectorExists('meta[name="robots"][content="noindex, nofollow"]');
    }

    public function test_a_redirect_and_a_404(): void
    {
        $this->client->request('GET', '/admin');
        self::assertResponseRedirects('/admin/login');
        $this->assertNotCachedOrIndexed();

        $this->client->request('GET', '/admin/nope');
        self::assertResponseStatusCodeSame(404);
        $this->assertNotCachedOrIndexed();
    }

    public function test_the_session_cookie_is_scoped_to_the_back_office(): void
    {
        $staff = $this->createStaff();
        $this->submitPassword($staff['user']->email());

        $cookies = $this->client->getResponse()->headers->getCookies();
        self::assertNotEmpty($cookies);
        $cookie = $cookies[0];
        self::assertInstanceOf(Cookie::class, $cookie);
        self::assertSame('/admin', $cookie->getPath());
        self::assertSame(Cookie::SAMESITE_STRICT, $cookie->getSameSite());
        self::assertTrue($cookie->isHttpOnly());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function encodedUrls(): iterable
    {
        foreach (self::encodedSpellings('/admin/benutzer') as $spelling => $url) {
            yield $spelling => [$url];
        }
        foreach (self::encodedSpellings('/admin') as $spelling => $url) {
            yield $spelling.', dashboard' => [$url];
        }
    }

    #[DataProvider('encodedUrls')]
    public function test_an_encoded_url_when_logged_in(string $url): void
    {
        $this->logInAs();

        $this->client->request('GET', $url);

        self::assertResponseIsSuccessful();
        $this->assertNotCachedOrIndexed($url);
    }

    #[DataProvider('encodedUrls')]
    public function test_an_encoded_url_when_anonymous(string $url): void
    {
        $this->client->request('GET', $url);

        self::assertResponseRedirects('/admin/login');
        $this->assertNotCachedOrIndexed($url);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function encodedErrorUrls(): iterable
    {
        yield '404, first letter encoded' => ['/%61dmin/nope'];
        yield '404, slash encoded' => ['/admin%2Fnope'];
        yield '404, trailing slash' => ['/admin%2Fnope/'];
        yield '404, double slash' => ['/admin//benutzer'];
        yield '404, encoded dot segment' => ['/admin/%2e/benutzer'];
        yield '404, encoded double dot segment' => ['/admin/x/%2e%2e/benutzer'];
        yield '404, invalid UTF-8' => ['/admin/%ff'];
    }

    #[DataProvider('encodedErrorUrls')]
    public function test_an_encoded_url_that_matches_no_route(string $url): void
    {
        $this->client->request('GET', $url);

        self::assertResponseStatusCodeSame(404);
        $this->assertNotCachedOrIndexed($url);
    }

    public function test_a_method_the_route_does_not_allow(): void
    {
        $this->client->request('DELETE', '/admin%2Fbenutzer');

        self::assertResponseStatusCodeSame(405);
        $this->assertNotCachedOrIndexed();
    }

    public function test_trailing_slashes(): void
    {
        foreach (['/admin/benutzer/', '/admin/benutzer//', '/%61dmin/benutzer/'] as $url) {
            $this->client->request('GET', $url);

            self::assertResponseRedirects('/admin/login', message: $url);
            $this->assertNotCachedOrIndexed($url);
        }
    }
}
