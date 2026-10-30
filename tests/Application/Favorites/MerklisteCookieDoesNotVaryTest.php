<?php

declare(strict_types=1);

namespace App\Tests\Application\Favorites;

use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Venues\DetailVenues;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Response;
use Zenstruck\Foundry\Test\Factories;

/**
 * Cached pages never read the cookie (S7, S10): the answer is byte-identical with and without it,
 * so the saved state can only come from the browser. The clock is frozen because the page content
 * may depend on it: the daily order seed does, and #14's `started` field on the detail page will.
 * The cookie is seeded with the trailing comma of a list written by JS, not yet re-issued.
 */
final class MerklisteCookieDoesNotVaryTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use Factories;
    use ImportsTownDataset;

    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        self::mockTime('2026-10-30 12:00:00');
        $this->client = self::createClient();
        $this->client->disableReboot();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->connection->rollBack();
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function cachedPages(): iterable
    {
        yield 'listing' => ['/hochzeitslocations'];
        yield 'detail page with its similar venues' => ['/hochzeitslocation/'.DetailVenues::MINIMAL_SLUG];
        yield 'count endpoint' => ['/_search/count'];
    }

    #[DataProvider('cachedPages')]
    public function test_the_cookie_changes_nothing(string $uri): void
    {
        $venue = DetailVenues::minimal();
        DetailVenues::minimal('zweites-gartenhaus', 'Zweites Gartenhaus');

        $without = $this->get($uri);
        $this->client->getCookieJar()->set(new Cookie('merkliste', $venue->number().',1,2,3,'));
        $with = $this->get($uri);

        self::assertSame($without->getContent(), $with->getContent());
        self::assertSame($without->headers->get('Cache-Control'), $with->headers->get('Cache-Control'));
        self::assertTrue($with->headers->hasCacheControlDirective('public'));

        foreach ([$without, $with] as $response) {
            self::assertFalse($response->headers->has('Set-Cookie'));
            self::assertSame([], $response->headers->getCookies());
            self::assertNotContains('cookie', array_map(strtolower(...), $response->getVary()));
        }
    }

    /**
     * The heart link carries the sync token on every cached page. A per-render or per-session token
     * would make two visitors' copies differ, so it has to be the stateless check's constant.
     *
     * @return iterable<string, array{string}>
     */
    public static function cachedHtmlPages(): iterable
    {
        yield 'listing' => ['/hochzeitslocations'];
        yield 'detail page' => ['/hochzeitslocation/'.DetailVenues::MINIMAL_SLUG];
    }

    #[DataProvider('cachedHtmlPages')]
    public function test_the_sync_token_is_the_same_constant_on_every_render(string $uri): void
    {
        DetailVenues::minimal();

        $first = $this->syncToken($uri);
        $this->client->getCookieJar()->clear();
        $second = $this->syncToken($uri);

        self::assertSame('csrf-token', $first);
        self::assertSame($first, $second);
        self::assertSame([], $this->client->getCookieJar()->all());
    }

    private function syncToken(string $uri): ?string
    {
        $crawler = new Crawler((string) $this->get($uri)->getContent(), 'http://localhost'.$uri);

        return $crawler->filter('[data-merkliste-link]')->attr('data-sync-token');
    }

    private function get(string $uri): Response
    {
        $this->client->request('GET', $uri);
        self::assertResponseIsSuccessful();

        return $this->client->getResponse();
    }
}
