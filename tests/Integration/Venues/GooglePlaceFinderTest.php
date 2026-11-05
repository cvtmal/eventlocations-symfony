<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues;

use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Venues\DetailVenues;
use App\Venues\GooglePlaceFinder;
use App\Venues\ReadModel\GooglePlaceLookupQuery;
use App\Venues\VenueGeocoder;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;
use Zenstruck\Foundry\Test\Factories;

/**
 * The one-off place ID lookup (#53, D6) against the test database, Google mocked: one row per venue without an ID,
 * `apply` always empty, nothing written to the database.
 */
final class GooglePlaceFinderTest extends KernelTestCase
{
    use Factories;
    use ImportsTownDataset;

    private EntityManagerInterface $em;
    private Connection $connection;
    private string $root;

    protected function setUp(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->connection = $em->getConnection();
        $this->connection->beginTransaction();
        $this->root = sys_get_temp_dir().'/google-places-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        @unlink($this->root.'/'.GooglePlaceFinder::REPORT_FILE);
        @rmdir($this->root.'/owner');
        @rmdir($this->root);
        $this->em->clear();
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function test_it_reports_found_missed_and_failed_venues_and_writes_nothing(): void
    {
        $pinned = DetailVenues::inCantonWithPin('47.3', '8.5', name: 'Scheune Seeblick');
        $unpinned = DetailVenues::inRegionOnly($this->em, 'Bodensee', name: 'Remise Rheinhof');
        $missing = DetailVenues::inCantonOnly(slug: 'nirgends', name: 'Hof Nirgends', cantonCode: 'be');
        $broken = DetailVenues::withoutPlace(name: 'Wiesenhof Kaputt');
        $before = $this->connection->fetchAllAssociative('SELECT number, google_place_id, updated_at FROM venues ORDER BY number');
        $clock = new MockClock();
        $start = (float) $clock->now()->format('U.u');

        $counts = $this->finder([
            'Scheune Seeblick Zürich Schweiz' => new JsonMockResponse(['places' => [[
                'id' => 'ChIJSeeblick0001',
                'displayName' => ['text' => 'Scheune Seeblick'],
                'formattedAddress' => 'Seestrasse 9, 8000 Zürich',
                'location' => ['latitude' => 47.309, 'longitude' => 8.5],
            ]]]),
            'Remise Rheinhof Bodensee Schweiz' => new JsonMockResponse(['places' => [[
                'id' => 'ChIJRheinhof0002',
                'displayName' => ['text' => 'Gasthof zur Post'],
                'formattedAddress' => 'Hauptstrasse 1, 8590 Romanshorn',
                'location' => ['latitude' => 47.56, 'longitude' => 9.37],
            ]]]),
            'Hof Nirgends Bern Schweiz' => new JsonMockResponse([]),
            'Wiesenhof Kaputt  Schweiz' => new MockResponse('', ['http_code' => 500]),
        ], $clock)->find($this->root);

        self::assertSame(['found' => 2, 'missed' => 1, 'failed' => 1], $counts);
        $rows = $this->report();
        self::assertSame(GooglePlaceFinder::HEADER, $rows[0]);
        $byNumber = array_column(\array_slice($rows, 1), null, 0);
        self::assertSame([(string) $pinned->number(), 'Scheune Seeblick', 'yes', 'found', 'Scheune Seeblick Zürich Schweiz', 'ChIJSeeblick0001', 'Scheune Seeblick', 'yes', 'Seestrasse 9, 8000 Zürich', '1.0', ''], $byNumber[(string) $pinned->number()]);
        self::assertSame([(string) $unpinned->number(), 'Remise Rheinhof', 'yes', 'found', 'Remise Rheinhof Bodensee Schweiz', 'ChIJRheinhof0002', 'Gasthof zur Post', 'NO', 'Hauptstrasse 1, 8590 Romanshorn', '', ''], $byNumber[(string) $unpinned->number()]);
        self::assertSame([(string) $missing->number(), 'Hof Nirgends', 'yes', 'not found', 'Hof Nirgends Bern Schweiz', '', '', '', '', '', ''], $byNumber[(string) $missing->number()]);
        self::assertSame([(string) $broken->number(), 'Wiesenhof Kaputt', 'yes', 'failed', 'Wiesenhof Kaputt  Schweiz', '', '', '', '', '', ''], $byNumber[(string) $broken->number()]);
        self::assertSame([''], array_values(array_unique(array_column(\array_slice($rows, 1), 10))));
        self::assertSame($before, $this->connection->fetchAllAssociative('SELECT number, google_place_id, updated_at FROM venues ORDER BY number'));
        self::assertEqualsWithDelta(0.4, (float) $clock->now()->format('U.u') - $start, 0.001, 'a pause of 0.1 s after each request');
    }

    public function test_a_venue_with_a_place_id_is_not_asked_and_the_limit_holds(): void
    {
        $withId = DetailVenues::minimal();
        DetailVenues::inCantonOnly();
        DetailVenues::withoutPlace();
        $this->connection->executeStatement("UPDATE venues SET google_place_id = 'ChIJN1t_tDeuEmsRUsoyG83frY4' WHERE id = ?", [$withId->id()]);
        $asked = [];

        $counts = $this->finder([], new MockClock(), $asked)->find($this->root, 1);

        self::assertSame(['found' => 0, 'missed' => 1, 'failed' => 0], $counts);
        self::assertSame(['Scheune Nur Kanton Zürich Schweiz'], $asked);
        self::assertCount(2, $this->report());

        $asked = [];
        $this->finder([], new MockClock(), $asked)->find($this->root);

        self::assertSame(['Scheune Nur Kanton Zürich Schweiz', 'Wiesenhof Ohne Ort  Schweiz'], $asked);
    }

    /**
     * @param array<string, MockResponse> $answers text query → Google's answer; any other query finds nothing
     * @param list<string>                $asked   the text queries sent, in order
     */
    private function finder(array $answers, MockClock $clock, array &$asked = []): GooglePlaceFinder
    {
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use ($answers, &$asked): MockResponse {
            $body = json_decode((string) $options['body'], true, flags: \JSON_THROW_ON_ERROR);
            \assert(\is_array($body) && \is_string($body['textQuery']));
            $asked[] = $body['textQuery'];

            return $answers[$body['textQuery']] ?? new JsonMockResponse([]);
        });

        return new GooglePlaceFinder(new VenueGeocoder($client, 'k'), new GooglePlaceLookupQuery($this->connection), $clock);
    }

    /**
     * @return list<list<string>>
     */
    private function report(): array
    {
        $file = $this->root.'/'.GooglePlaceFinder::REPORT_FILE;
        self::assertFileExists($file);
        $handle = fopen($file, 'r');
        self::assertNotFalse($handle);
        $rows = [];
        while (false !== ($row = fgetcsv($handle, null, ',', '"', ''))) {
            $rows[] = array_map(static fn (?string $cell): string => (string) $cell, $row);
        }
        fclose($handle);

        return $rows;
    }
}
