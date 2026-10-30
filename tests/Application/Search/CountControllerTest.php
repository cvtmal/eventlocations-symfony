<?php

declare(strict_types=1);

namespace App\Tests\Application\Search;

use App\Factory\VenueFactory;
use App\Factory\VenueTranslationFactory;
use App\Geo\Entity\Town;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Venues\ValueObject\VenueStatus;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

use function Zenstruck\Foundry\Persistence\repository;

/**
 * The live-count endpoint. The client shares the test's connection (no reboot), so the
 * venues created here are visible to the request and rolled back afterwards.
 */
final class CountControllerTest extends WebTestCase
{
    use Factories;
    use ImportsTownDataset;

    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->connection->beginTransaction();

        $zurich = $this->town('Zürich');
        $this->venue(['town' => $zurich, 'capacityMax' => 200]);
        $this->venue(['town' => $zurich, 'capacityMax' => 100]);
        $this->venue(['town' => $this->town('Bern')]);
        $this->venue(['town' => $zurich, 'status' => VenueStatus::Unpublished]);
    }

    protected function tearDown(): void
    {
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function test_counts_the_filtered_venues(): void
    {
        self::assertSame(['count' => 2], $this->fetchCount('/_search/count?canton=zh'));
        self::assertSame(['count' => 1], $this->fetchCount('/_search/count?canton=zh&guests=150'));
        self::assertSame(['count' => 3], $this->fetchCount('/_search/count'));
    }

    public function test_garbage_is_ignored(): void
    {
        self::assertSame(
            ['count' => 3],
            $this->fetchCount('/_search/count?guests=abc&setting[]=nope&page[]=x&canton=xx&place=foo&radius=999&q[]=1&rooms[]=2&access=moon'),
        );
    }

    public function test_headers(): void
    {
        $this->client->request('GET', '/_search/count?canton=zh');
        $response = $this->client->getResponse();

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex');
        $cacheControl = (string) $response->headers->get('Cache-Control');
        self::assertStringContainsString('public', $cacheControl);
        self::assertStringContainsString('max-age=60', $cacheControl);
        self::assertStringContainsString('s-maxage=600', $cacheControl);
        self::assertStringContainsString('stale-while-revalidate=86400', $cacheControl);
        self::assertFalse($response->headers->has('Set-Cookie'));
        self::assertSame([], $response->headers->getCookies());
    }

    /**
     * @return array<mixed>
     */
    private function fetchCount(string $uri): array
    {
        $this->client->request('GET', $uri);
        self::assertResponseIsSuccessful();
        $json = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($json);

        return $json;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function venue(array $attributes): void
    {
        VenueTranslationFactory::createOne(['venue' => VenueFactory::createOne($attributes)]);
    }

    private function town(string $name): Town
    {
        $town = repository(Town::class)->findOneBy(['name' => $name]);
        self::assertInstanceOf(Town::class, $town);

        return $town;
    }
}
