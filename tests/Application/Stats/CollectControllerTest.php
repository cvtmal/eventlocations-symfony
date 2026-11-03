<?php

declare(strict_types=1);

namespace App\Tests\Application\Stats;

use App\Stats\StatsPaths;
use Doctrine\DBAL\Connection;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Filesystem\Filesystem;

/**
 * The beacon endpoint (#47, contract C1). No Foundry trait on purpose: nothing here may need the
 * database, and the last test proves the request never connects to it.
 */
final class CollectControllerTest extends WebTestCase
{
    use ClockSensitiveTrait;

    private const SAME_ORIGIN = ['HTTP_SEC_FETCH_SITE' => 'same-origin'];

    private KernelBrowser $client;
    private StatsPaths $paths;

    protected function setUp(): void
    {
        self::mockTime('2026-11-03T14:05:09+01:00');
        $this->client = self::createClient();
        $directory = self::getContainer()->getParameter('stats.dir');
        self::assertIsString($directory);
        $this->paths = new StatsPaths($directory);
        (new Filesystem())->remove($directory);
        $pool = self::getContainer()->get('cache.stats_collect');
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);
        $pool->clear();
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove([$this->paths->spoolDir(), $this->paths->saltDir()]);
        parent::tearDown();
    }

    public function test_a_same_origin_beacon_appends_its_events(): void
    {
        $this->post([
            'p' => 'detail',
            'v' => '46',
            'u' => '/hochzeitslocation/x',
            'r' => 'https://www.google.com/search?q=hochzeit',
            'e' => ['view', 'click:website'],
        ]);

        $response = $this->client->getResponse();
        self::assertResponseStatusCodeSame(204);
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
        self::assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));
        self::assertSame([], $response->headers->getCookies());
        self::assertFalse($response->headers->has('Set-Cookie'));

        $lines = $this->lines();
        self::assertCount(2, $lines);
        $view = json_decode($lines[0], true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($view);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $view['vh']);
        self::assertSame([
            'at' => '2026-11-03T14:05:09+01:00',
            'type' => 'page_view',
            'kind' => null,
            'venues' => [46],
            'page' => 'detail',
            'path' => '/hochzeitslocation/x',
            'ref' => 'www.google.com',
            'ua' => 'Symfony BrowserKit',
            'vh' => $view['vh'],
            'bot' => false,
        ], $view);
        self::assertStringContainsString('"type":"click","kind":"website","venues":[46]', $lines[1]);
        self::assertStringNotContainsString('127.0.0.1', implode("\n", $lines));
    }

    public function test_a_cross_site_beacon_is_refused_and_writes_nothing(): void
    {
        $this->post(['e' => ['view']], ['HTTP_SEC_FETCH_SITE' => 'cross-site']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('no-store, private', $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertSame([], $this->lines());
    }

    public function test_without_sec_fetch_site_the_origin_decides(): void
    {
        $this->post(['e' => ['view']], ['HTTP_ORIGIN' => 'https://evil.example']);
        self::assertResponseStatusCodeSame(403);
        $this->post(['e' => ['view']], []);
        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->lines());

        $this->post(['e' => ['view']], ['HTTP_ORIGIN' => 'http://localhost']);

        self::assertResponseStatusCodeSame(204);
        self::assertCount(1, $this->lines());
    }

    public function test_an_oversized_body_or_no_valid_event_writes_nothing(): void
    {
        $this->post(['e' => ['view']], self::SAME_ORIGIN + ['CONTENT_LENGTH' => '8193']);
        self::assertResponseStatusCodeSame(204);
        $this->post(['e' => ['scroll']]);
        self::assertResponseStatusCodeSame(204);
        $this->post([]);
        self::assertResponseStatusCodeSame(204);

        self::assertSame([], $this->lines());
    }

    public function test_an_over_limit_client_gets_204_and_nothing_is_written(): void
    {
        for ($i = 0; $i < 120; ++$i) {
            $this->post(['e' => ['view']]);
        }
        self::assertCount(120, $this->lines());

        $this->post(['e' => ['view']]);

        self::assertResponseStatusCodeSame(204);
        self::assertSame('no-store, private', $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertCount(120, $this->lines());
    }

    public function test_collecting_never_connects_to_the_database(): void
    {
        $this->post(['p' => 'listing', 'e' => ['view', 'imp:1.2.3', 'add:2']]);

        self::assertResponseStatusCodeSame(204);
        self::assertCount(3, $this->lines());
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        self::assertFalse($connection->isConnected());
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $server
     */
    private function post(array $body, array $server = self::SAME_ORIGIN): void
    {
        $this->client->request('POST', '/_stats', $body, [], $server);
    }

    /**
     * @return list<string>
     */
    private function lines(): array
    {
        $file = $this->paths->spoolDir().'/2026-11-03.ndjson';
        if (!is_file($file)) {
            return [];
        }

        return explode("\n", rtrim((string) file_get_contents($file), "\n"));
    }
}
