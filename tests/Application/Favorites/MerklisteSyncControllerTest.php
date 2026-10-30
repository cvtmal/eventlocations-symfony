<?php

declare(strict_types=1);

namespace App\Tests\Application\Favorites;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\Cookie as ResponseCookie;
use Symfony\Component\Routing\RouterInterface;

/**
 * `POST /merkliste`, the server re-issue of a list written by JS (MK-2). It sends the request's own
 * list back: it never flips an entry, never deletes one and invalidates no cached page.
 */
final class MerklisteSyncControllerTest extends WebTestCase
{
    private const SAME_ORIGIN = ['HTTP_SEC_FETCH_SITE' => 'same-origin'];

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function test_reissues_a_list_written_by_the_script_without_the_marker(): void
    {
        $this->seed('3,1,2,');

        $this->sync();

        self::assertResponseStatusCodeSame(204);
        self::assertSame('', (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('merkliste=3%2C1%2C2;', (string) $this->responseCookie());
        self::assertSame('3,1,2', $this->jar());
    }

    public function test_cookie_attributes(): void
    {
        $this->seed('1,2,');

        $this->sync();

        $header = strtolower((string) $this->responseCookie());
        self::assertStringContainsString('path=/', $header);
        self::assertStringContainsString('samesite=lax', $header);
        self::assertStringNotContainsString('httponly', $header);
        self::assertStringNotContainsString('secure', $header);
        self::assertEqualsWithDelta(34560000, $this->responseCookie()->getMaxAge(), 5);
    }

    public function test_cookie_is_secure_over_https(): void
    {
        $this->client->getCookieJar()->set(new Cookie('merkliste', '1,2,', null, '/', 'localhost', true));

        $this->sync(self::SAME_ORIGIN + ['HTTPS' => 'on']);

        self::assertResponseStatusCodeSame(204);
        self::assertStringContainsString('secure', strtolower((string) $this->responseCookie()));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function lists(): iterable
    {
        yield 'one entry' => ['7,', '7'];
        yield 'the order stays' => ['9,3,5,', '9,3,5'];
        yield 'garbage normalises, no entry appears' => ['abc,5,5,0,', '5'];
        yield 'a clean but untidy list normalises' => ['5,5', '5'];
    }

    #[DataProvider('lists')]
    public function test_never_flips_an_entry(string $sent, string $expected): void
    {
        $this->seed($sent);

        $this->sync();

        self::assertResponseStatusCodeSame(204);
        self::assertSame($expected, $this->jar());
    }

    public function test_a_body_cannot_change_the_list(): void
    {
        $this->seed('1,2,');

        $this->client->request('POST', '/merkliste', ['_token' => 'csrf-token', 'intent' => 'remove', 'number' => '1'], [], self::SAME_ORIGIN);

        self::assertResponseStatusCodeSame(204);
        self::assertSame('1,2', $this->jar());
    }

    public function test_a_clean_list_needs_no_rewrite(): void
    {
        $this->seed('1,2');

        $this->sync();

        self::assertResponseStatusCodeSame(204);
        self::assertFalse($this->client->getResponse()->headers->has('Set-Cookie'));
        self::assertSame('1,2', $this->jar());
    }

    public function test_without_a_cookie_writes_nothing(): void
    {
        $this->sync();

        self::assertResponseStatusCodeSame(204);
        self::assertSame([], $this->client->getResponse()->headers->getCookies());
        self::assertNull($this->jar());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function listsWithoutAnEntry(): iterable
    {
        yield 'empty value' => [''];
        yield 'only the marker' => [','];
        yield 'garbage only' => ['abc,'];
    }

    #[DataProvider('listsWithoutAnEntry')]
    public function test_never_deletes(string $value): void
    {
        $this->seed($value);

        $this->sync();

        self::assertResponseStatusCodeSame(204);
        self::assertSame([], $this->client->getResponse()->headers->getCookies());
        self::assertSame($value, $this->jar());
    }

    public function test_an_array_shaped_cookie_writes_nothing(): void
    {
        $this->client->getCookieJar()->set(new Cookie('merkliste[a]', '1', null, '/', 'localhost'));

        $this->sync();

        self::assertResponseStatusCodeSame(204);
        self::assertSame([], $this->client->getResponse()->headers->getCookies());
    }

    public function test_a_full_list_keeps_every_entry(): void
    {
        $full = implode(',', range(1, 100));
        $this->seed($full.',');

        $this->sync();

        self::assertResponseStatusCodeSame(204);
        self::assertSame($full, $this->jar());
    }

    public function test_answers_nothing_a_cache_would_act_on(): void
    {
        $this->seed('1,2,');

        $this->sync(self::SAME_ORIGIN + ['HTTP_REFERER' => 'http://localhost/hochzeitslocations']);

        $headers = $this->client->getResponse()->headers;
        self::assertFalse($headers->has('Location'));
        self::assertFalse($headers->has('Content-Location'));
    }

    public function test_is_private_and_starts_no_session(): void
    {
        $this->seed('1,2,');

        $this->sync();

        $headers = $this->client->getResponse()->headers;
        self::assertCount(1, $headers->getCookies());
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow');
        self::assertTrue($headers->hasCacheControlDirective('private'));
        self::assertTrue($headers->hasCacheControlDirective('no-store'));
        self::assertFalse($headers->hasCacheControlDirective('public'));
    }

    public function test_a_cross_site_post_changes_nothing(): void
    {
        $this->seed('1,2,3,');

        $this->sync(['HTTP_SEC_FETCH_SITE' => 'cross-site', 'HTTP_ORIGIN' => 'https://evil.example']);

        self::assertResponseStatusCodeSame(403);
        self::assertFalse($this->client->getResponse()->headers->has('Set-Cookie'));
        self::assertSame('1,2,3,', $this->jar());
    }

    /**
     * @return iterable<string, array{array<string, string>, array<string, mixed>}>
     */
    public static function refusedRequests(): iterable
    {
        yield 'same site, another origin' => [['HTTP_SEC_FETCH_SITE' => 'same-site'], ['_token' => 'csrf-token']];
        yield 'foreign origin without fetch metadata' => [['HTTP_ORIGIN' => 'https://evil.example'], ['_token' => 'csrf-token']];
        yield 'no origin information' => [[], ['_token' => 'csrf-token']];
        yield 'wrong token' => [self::SAME_ORIGIN, ['_token' => 'wrong']];
        yield 'missing token' => [self::SAME_ORIGIN, []];
        yield 'token as an array' => [self::SAME_ORIGIN, ['_token' => ['x']]];
    }

    /**
     * @param array<string, string> $server
     * @param array<string, mixed>  $body
     */
    #[DataProvider('refusedRequests')]
    public function test_a_failed_check_answers_403_with_no_cookie(array $server, array $body): void
    {
        $this->seed('1,2,3,');

        $this->client->request('POST', '/merkliste', $body, [], $server);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->client->getResponse()->headers->getCookies());
        self::assertSame('1,2,3,', $this->jar());
    }

    public function test_accepts_a_matching_origin_without_fetch_metadata(): void
    {
        $this->seed('1,2,');

        $this->sync(['HTTP_ORIGIN' => 'http://localhost']);

        self::assertResponseStatusCodeSame(204);
        self::assertSame('1,2', $this->jar());
    }

    public function test_the_script_can_post_to_the_heart_links_href(): void
    {
        $router = self::getContainer()->get(RouterInterface::class);
        self::assertInstanceOf(RouterInterface::class, $router);

        self::assertSame($router->generate('merkliste'), $router->generate('merkliste_sync'));
    }

    /**
     * @param array<string, string> $server
     */
    private function sync(array $server = self::SAME_ORIGIN): void
    {
        $this->client->request('POST', '/merkliste', ['_token' => 'csrf-token'], [], $server);
    }

    private function seed(string $value): void
    {
        $this->client->getCookieJar()->set(new Cookie('merkliste', $value, null, '/', 'localhost'));
    }

    private function jar(): ?string
    {
        return $this->client->getCookieJar()->get('merkliste')?->getValue();
    }

    private function responseCookie(): ResponseCookie
    {
        $cookies = $this->client->getResponse()->headers->getCookies();
        self::assertCount(1, $cookies);

        return $cookies[0];
    }
}
