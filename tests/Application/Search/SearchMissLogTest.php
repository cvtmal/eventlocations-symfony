<?php

declare(strict_types=1);

namespace App\Tests\Application\Search;

use App\Factory\VenueFactory;
use App\Factory\VenueTranslationFactory;
use App\Tests\Integration\Geo\ImportsTownDataset;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * G12: a listing search that finds nothing is counted (canonical query, no person) and its page is not stored by a
 * shared cache; a search with results, the unfiltered listing and a browser under Global Privacy Control are not.
 */
final class SearchMissLogTest extends WebTestCase
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
        $this->connection->executeStatement('DELETE FROM search_misses');
        VenueTranslationFactory::createOne(['venue' => VenueFactory::createOne(['capacityMax' => 120])]);
    }

    protected function tearDown(): void
    {
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function test_a_search_without_results_is_counted_and_not_shared_cached(): void
    {
        $this->client->request('GET', '/eventlokale?guests=9000&q=Schloss&event[]=seminar');
        $this->client->request('GET', '/eventlokale?event[]=seminar&q=Schloss&guests=9000');

        self::assertResponseIsSuccessful();
        $cacheControl = (string) $this->client->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('private', $cacheControl);
        self::assertStringNotContainsString('public', $cacheControl);
        self::assertSame(
            [['query' => 'guests=9000&q=Schloss&event%5B0%5D=seminar', 'searches' => 2]],
            $this->connection->fetchAllAssociative('SELECT query, searches FROM search_misses'),
            'one canonical row for both spellings',
        );
    }

    public function test_a_search_with_results_and_the_plain_listing_are_not_counted(): void
    {
        $this->client->request('GET', '/eventlokale?guests=100');
        self::assertStringContainsString('public', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        $this->connection->executeStatement('DELETE FROM venue_translations');
        $this->client->request('GET', '/eventlokale');

        self::assertResponseIsSuccessful();
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM search_misses'));
    }

    public function test_global_privacy_control_and_the_staff_link_are_not_counted(): void
    {
        $this->client->request('GET', '/eventlokale?guests=9000', server: ['HTTP_SEC_GPC' => '1']);
        $this->client->request('GET', '/eventlokale?guests=9000&staff=1');

        self::assertResponseIsSuccessful();
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM search_misses'));
    }
}
