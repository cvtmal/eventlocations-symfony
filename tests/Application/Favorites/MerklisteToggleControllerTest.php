<?php

declare(strict_types=1);

namespace App\Tests\Application\Favorites;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\Cookie as ResponseCookie;

/**
 * The no-JS toggle `POST /merkliste/{number}` (S7). It touches neither the database nor a venue,
 * so these tests need no fixtures.
 */
final class MerklisteToggleControllerTest extends WebTestCase
{
    private const SAME_ORIGIN = ['HTTP_SEC_FETCH_SITE' => 'same-origin'];

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function test_adds_then_removes_an_entry(): void
    {
        $this->toggle(5);
        self::assertResponseStatusCodeSame(303);
        self::assertSame('5', $this->jar());

        $this->toggle(5);
        self::assertResponseStatusCodeSame(303);
        self::assertNull($this->jar());
    }

    public function test_the_remove_intent_removes(): void
    {
        $this->seed('1,2,3');

        $this->remove(1);

        self::assertResponseRedirects('/merkliste', 303);
        self::assertSame('2,3', $this->jar());
    }

    public function test_the_same_removal_twice_leaves_the_entry_gone(): void
    {
        $this->seed('1,2,3');

        $this->remove(1);
        self::assertSame('2,3', $this->jar());

        $this->remove(1);

        self::assertResponseStatusCodeSame(303);
        self::assertSame([], $this->client->getResponse()->headers->getCookies());
        self::assertSame('2,3', $this->jar());
    }

    public function test_a_stale_removal_never_adds(): void
    {
        $this->seed('2,3');

        $this->remove(1);

        self::assertResponseRedirects('/merkliste', 303);
        self::assertSame([], $this->client->getResponse()->headers->getCookies());
        self::assertSame('2,3', $this->jar());
    }

    public function test_a_removal_on_a_full_list_is_not_refused(): void
    {
        $full = implode(',', range(1, 100));
        $this->seed($full);
        $referer = 'http://localhost/merkliste?seite=2';

        $this->remove(101, self::SAME_ORIGIN + ['HTTP_REFERER' => $referer]);

        self::assertResponseRedirects($referer, 303);
        self::assertSame($full, $this->jar());
    }

    public function test_a_removal_of_the_last_entry_deletes_the_cookie(): void
    {
        $this->seed('1');

        $this->remove(1);

        self::assertResponseStatusCodeSame(303);
        self::assertNull($this->jar());
    }

    public function test_a_removal_needs_the_same_origin_check(): void
    {
        $this->seed('1,2,3');

        $this->remove(1, ['HTTP_SEC_FETCH_SITE' => 'cross-site', 'HTTP_ORIGIN' => 'https://evil.example']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('1,2,3', $this->jar());
    }

    public function test_an_array_intent_is_a_plain_toggle(): void
    {
        $this->seed('2');

        $this->client->request('POST', '/merkliste/1', ['_token' => 'csrf-token', 'intent' => ['remove']], [], self::SAME_ORIGIN);

        self::assertResponseStatusCodeSame(303);
        self::assertSame('2,1', $this->jar());
    }

    public function test_keeps_the_saved_order(): void
    {
        $this->seed('7,3');

        $this->toggle(9);
        self::assertSame('7,3,9', $this->jar());

        $this->toggle(3);
        self::assertSame('7,9', $this->jar());
    }

    public function test_cookie_attributes(): void
    {
        $this->seed('1,2');

        $this->toggle(3);

        $header = strtolower((string) $this->responseCookie());
        self::assertStringContainsString('merkliste=1%2c2%2c3', $header);
        self::assertStringContainsString('path=/', $header);
        self::assertStringContainsString('samesite=lax', $header);
        self::assertStringNotContainsString('httponly', $header);
        self::assertStringNotContainsString('secure', $header);
        self::assertStringContainsString('max-age=', $header);
        self::assertEqualsWithDelta(34560000, $this->responseCookie()->getMaxAge(), 5);
    }

    public function test_cookie_is_secure_over_https(): void
    {
        $this->toggle(3, self::SAME_ORIGIN + ['HTTPS' => 'on']);

        self::assertResponseStatusCodeSame(303);
        self::assertStringContainsString('secure', strtolower((string) $this->responseCookie()));
    }

    public function test_redirects_to_the_own_referer_with_its_query_string(): void
    {
        $referer = 'http://localhost/hochzeitslocations?canton=zh&page=2';

        $this->toggle(5, self::SAME_ORIGIN + ['HTTP_REFERER' => $referer]);

        self::assertResponseRedirects($referer, 303);
    }

    public function test_redirects_a_foreign_referer_to_the_merkliste(): void
    {
        $this->toggle(5, self::SAME_ORIGIN + ['HTTP_REFERER' => 'http://localhost.evil.example/']);

        self::assertResponseRedirects('/merkliste', 303);
    }

    public function test_redirects_without_a_referer_to_the_merkliste(): void
    {
        $this->toggle(5);

        self::assertResponseRedirects('/merkliste', 303);
    }

    public function test_is_private_and_not_indexed(): void
    {
        $this->toggle(5);

        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow');
        $headers = $this->client->getResponse()->headers;
        self::assertTrue($headers->hasCacheControlDirective('private'));
        self::assertTrue($headers->hasCacheControlDirective('no-store'));
        self::assertFalse($headers->hasCacheControlDirective('public'));
    }

    public function test_a_cross_site_post_does_not_change_the_cookie(): void
    {
        $this->seed('1,2,3');

        $this->toggle(5, ['HTTP_SEC_FETCH_SITE' => 'cross-site', 'HTTP_ORIGIN' => 'https://evil.example']);

        self::assertResponseStatusCodeSame(403);
        self::assertFalse($this->client->getResponse()->headers->has('Set-Cookie'));
        self::assertSame([], $this->client->getResponse()->headers->getCookies());
        self::assertSame('1,2,3', $this->jar());
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
        $this->seed('1,2,3');

        $this->client->request('POST', '/merkliste/5', $body, [], $server);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->client->getResponse()->headers->getCookies());
        self::assertSame('1,2,3', $this->jar());
    }

    public function test_accepts_a_matching_origin_without_fetch_metadata(): void
    {
        $this->toggle(5, ['HTTP_ORIGIN' => 'http://localhost']);

        self::assertResponseStatusCodeSame(303);
        self::assertSame('5', $this->jar());
    }

    public function test_a_full_list_refuses_a_new_entry_and_explains_on_the_merkliste(): void
    {
        $full = implode(',', range(1, 100));
        $this->seed($full);

        $this->toggle(101, self::SAME_ORIGIN + ['HTTP_REFERER' => 'http://localhost/hochzeitslocations']);

        self::assertResponseRedirects('/merkliste', 303);
        self::assertSame([], $this->client->getResponse()->headers->getCookies());
        self::assertSame($full, $this->jar());
        self::assertTrue($this->client->getResponse()->headers->hasCacheControlDirective('no-store'));
    }

    public function test_a_full_list_still_removes(): void
    {
        $this->seed(implode(',', range(1, 100)));

        $this->toggle(1);

        self::assertResponseStatusCodeSame(303);
        self::assertSame(implode(',', range(2, 100)), $this->jar());
    }

    public function test_a_number_above_the_integer_range_is_not_found(): void
    {
        $this->toggle(2147483648);

        self::assertResponseStatusCodeSame(404);
        self::assertSame([], $this->client->getResponse()->headers->getCookies());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidPaths(): iterable
    {
        yield 'letters' => ['/merkliste/abc'];
        yield 'zero' => ['/merkliste/0'];
        yield 'leading zero' => ['/merkliste/05'];
    }

    #[DataProvider('invalidPaths')]
    public function test_an_invalid_number_is_not_found(string $path): void
    {
        $this->client->request('POST', $path, ['_token' => 'csrf-token'], [], self::SAME_ORIGIN);

        self::assertResponseStatusCodeSame(404);
    }

    public function test_get_is_not_allowed(): void
    {
        $this->client->request('GET', '/merkliste/5');

        self::assertResponseStatusCodeSame(405);
    }

    /**
     * @param array<string, string> $server
     */
    private function toggle(int $number, array $server = self::SAME_ORIGIN): void
    {
        $this->client->request('POST', '/merkliste/'.$number, ['_token' => 'csrf-token'], [], $server);
    }

    /**
     * @param array<string, string> $server
     */
    private function remove(int $number, array $server = self::SAME_ORIGIN): void
    {
        $this->client->request('POST', '/merkliste/'.$number, ['_token' => 'csrf-token', 'intent' => 'remove'], [], $server);
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
