<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues;

use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Venues\DetailVenues;
use App\Venues\Console\FindGooglePlacesCommand;
use App\Venues\GooglePlaceFinder;
use App\Venues\ReadModel\GooglePlaceLookupQuery;
use App\Venues\ValueObject\GooglePlaceId;
use App\Venues\VenueGeocoder;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\Translation\TranslatorInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * The one-off place ID lookup (#53, D6; #57) against the test database, Google mocked: one row per venue without
 * an ID, decided by `GooglePlaceMatcher`, `apply=yes` on confident rows only, a reason and a Maps link on every
 * row, a place shared by two venues doubtful on both, nothing written to the database.
 */
final class GooglePlaceFinderTest extends KernelTestCase
{
    use Factories;
    use ImportsTownDataset;

    private const array USTER = [['Uster', 'locality'], ['Zürich', 'administrative_area_level_1', 'ZH'], ['Schweiz', 'country', 'CH']];

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

    public function test_it_decides_each_row_and_writes_nothing(): void
    {
        $confident = DetailVenues::inCantonWithPin('47.3', '8.5', name: 'Kupferkessel Wiesental');
        $doubtful = DetailVenues::inRegionOnly($this->em, 'Bodensee', name: 'Remise Rheinhof');
        $missing = DetailVenues::inCantonOnly(slug: 'nirgends', name: 'Hof Nirgends', cantonCode: 'be');
        $broken = DetailVenues::withoutPlace(name: 'Wiesenhof Kaputt');
        $before = $this->snapshot();
        $clock = new MockClock();
        $start = (float) $clock->now()->format('U.u');

        $counts = $this->finder(self::answers() + [
            'Hof Nirgends Bern Schweiz' => new JsonMockResponse([]),
            'Wiesenhof Kaputt  Schweiz' => new MockResponse('', ['http_code' => 500]),
        ], $clock)->find($this->root);

        self::assertSame(['confident' => 1, 'doubtful' => 1, 'missed' => 1, 'failed' => 1, 'reasons' => ['name_differs' => 1]], $counts);
        $rows = $this->report();
        self::assertSame(GooglePlaceFinder::HEADER, $rows[0]);
        $byNumber = array_column(\array_slice($rows, 1), null, 0);
        self::assertSame([
            (string) $confident->number(), 'Kupferkessel Wiesental', 'yes', 'confident', 'Name und Ort stimmen überein.', 'yes',
            GooglePlaceId::mapsUrl('Kupferkessel Wiesental', 'ChIJKupfer0000001'), 'ChIJKupfer0000001', 'Kupferkessel Wiesental', 'Wiesenweg 4, 8610 Uster', '0.6',
            'Kupferkessel Wiesental Zürich Schweiz',
        ], $byNumber[(string) $confident->number()]);
        self::assertSame([
            (string) $doubtful->number(), 'Remise Rheinhof', 'yes', 'doubtful', 'Google kennt diese Wörter unseres Namens nicht: remise, rheinhof.', '',
            GooglePlaceId::mapsUrl('Remise Rheinhof', 'ChIJRheinhof0002'), 'ChIJRheinhof0002', 'Gasthof zur Post', 'Hauptstrasse 1, 8590 Romanshorn', '',
            'Remise Rheinhof Bodensee Schweiz',
        ], $byNumber[(string) $doubtful->number()]);
        self::assertSame([
            (string) $missing->number(), 'Hof Nirgends', 'yes', 'not found', 'Google findet nichts zu dieser Suche.', '',
            GooglePlaceId::searchUrl('Hof Nirgends Bern Schweiz'), '', '', '', '', 'Hof Nirgends Bern Schweiz',
        ], $byNumber[(string) $missing->number()]);
        self::assertSame([
            (string) $broken->number(), 'Wiesenhof Kaputt', 'yes', 'failed', 'Anfrage an Google fehlgeschlagen. places-find nochmals ausführen.', '',
            GooglePlaceId::searchUrl('Wiesenhof Kaputt  Schweiz'), '', '', '', '', 'Wiesenhof Kaputt  Schweiz',
        ], $byNumber[(string) $broken->number()]);
        self::assertSame($before, $this->snapshot());
        self::assertEqualsWithDelta(0.4, (float) $clock->now()->format('U.u') - $start, 0.001, 'a pause of 0.1 s after each request');
    }

    public function test_one_place_for_two_venues_makes_both_doubtful(): void
    {
        $first = DetailVenues::inCantonOnly(slug: 'kupferkessel', name: 'Kupferkessel');
        $second = DetailVenues::inCantonOnly(slug: 'kupferkessel-uster', name: 'Kupferkessel Uster');
        $answer = self::place('ChIJShared000001', 'Kupferkessel', 'Wiesenweg 4, 8610 Uster', 47.35, 8.72, self::USTER);

        $counts = $this->finder([
            'Kupferkessel Zürich Schweiz' => new JsonMockResponse(['places' => [$answer]]),
            'Kupferkessel Uster Zürich Schweiz' => new JsonMockResponse(['places' => [$answer]]),
        ], new MockClock())->find($this->root);

        self::assertSame(['confident' => 0, 'doubtful' => 2, 'missed' => 0, 'failed' => 0, 'reasons' => ['same_place' => 2]], $counts);
        $byNumber = array_column(\array_slice($this->report(), 1), null, 0);
        foreach ([[$first->number(), $second->number()], [$second->number(), $first->number()]] as [$number, $other]) {
            $row = array_combine(GooglePlaceFinder::HEADER, $byNumber[(string) $number]);
            self::assertSame(['doubtful', 'Derselbe Google-Ort passt auch zu Location '.$other.'.', '', 'ChIJShared000001'], [$row['confidence'], $row['reason'], $row['apply'], $row['place_id']]);
        }
    }

    public function test_a_place_already_held_by_another_venue_is_doubtful(): void
    {
        $holder = DetailVenues::minimal();
        $this->connection->executeStatement("UPDATE venues SET google_place_id = 'ChIJHeld00000001' WHERE id = ?", [$holder->id()]);
        $asked = DetailVenues::inCantonOnly(slug: 'kupferkessel', name: 'Kupferkessel');

        $counts = $this->finder([
            'Kupferkessel Zürich Schweiz' => new JsonMockResponse(['places' => [self::place('ChIJHeld00000001', 'Kupferkessel', 'Wiesenweg 4, 8610 Uster', 47.35, 8.72, self::USTER)]]),
        ], new MockClock())->find($this->root);

        self::assertSame(['same_place' => 1], $counts['reasons']);
        $row = array_combine(GooglePlaceFinder::HEADER, array_column(\array_slice($this->report(), 1), null, 0)[(string) $asked->number()]);
        self::assertSame(['doubtful', 'Derselbe Google-Ort passt auch zu Location '.$holder->number().'.', ''], [$row['confidence'], $row['reason'], $row['apply']]);
    }

    public function test_far_from_our_pin_is_doubtful_and_a_town_centre_is_no_pin(): void
    {
        $pinned = DetailVenues::inCantonWithPin('47.3', '8.5', slug: 'kupferkessel-nord', name: 'Kupferkessel Nord');
        $centre = DetailVenues::inCantonWithPin('47.3', '8.5', slug: 'kupferkessel-sued', name: 'Kupferkessel Sued');
        $this->connection->executeStatement('UPDATE venues SET coordinates_from_town = TRUE WHERE id = ?', [$centre->id()]);

        // 0.018° of latitude is 2.0 km.
        $this->finder([
            'Kupferkessel Nord Zürich Schweiz' => new JsonMockResponse(['places' => [self::place('ChIJNord00000001', 'Kupferkessel Nord', 'Uster', 47.318, 8.5, self::USTER)]]),
            'Kupferkessel Sued Zürich Schweiz' => new JsonMockResponse(['places' => [self::place('ChIJSued00000001', 'Kupferkessel Sued', 'Uster', 47.318, 8.5, self::USTER)]]),
        ], new MockClock())->find($this->root);

        $byNumber = array_column(\array_slice($this->report(), 1), null, 0);
        $far = array_combine(GooglePlaceFinder::HEADER, $byNumber[(string) $pinned->number()]);
        self::assertSame(['doubtful', '2.0 km von unserem Pin entfernt.', '', '2.0'], [$far['confidence'], $far['reason'], $far['apply'], $far['distance_km']]);
        $town = array_combine(GooglePlaceFinder::HEADER, $byNumber[(string) $centre->number()]);
        self::assertSame(['confident', 'yes', ''], [$town['confidence'], $town['apply'], $town['distance_km']]);
    }

    public function test_a_venue_with_a_place_id_is_not_asked_and_the_limit_holds(): void
    {
        $withId = DetailVenues::minimal();
        DetailVenues::inCantonOnly();
        DetailVenues::withoutPlace();
        $this->connection->executeStatement("UPDATE venues SET google_place_id = 'ChIJN1t_tDeuEmsRUsoyG83frY4' WHERE id = ?", [$withId->id()]);
        $asked = [];

        $counts = $this->finder([], new MockClock(), $asked)->find($this->root, 1);

        self::assertSame(['confident' => 0, 'doubtful' => 0, 'missed' => 1, 'failed' => 0, 'reasons' => []], $counts);
        self::assertSame(['Scheune Nur Kanton Zürich Schweiz'], $asked);
        self::assertCount(2, $this->report());

        $asked = [];
        $this->finder([], new MockClock(), $asked)->find($this->root);

        self::assertSame(['Scheune Nur Kanton Zürich Schweiz', 'Wiesenhof Ohne Ort  Schweiz'], $asked);
    }

    public function test_running_it_twice_writes_the_same_report_and_nothing_else(): void
    {
        DetailVenues::inCantonWithPin('47.3', '8.5', name: 'Kupferkessel Wiesental');
        DetailVenues::inRegionOnly($this->em, 'Bodensee', name: 'Remise Rheinhof');
        $before = $this->snapshot();

        $first = $this->finder(self::answers(), new MockClock())->find($this->root);
        $report = (string) file_get_contents($this->root.'/'.GooglePlaceFinder::REPORT_FILE);
        $second = $this->finder(self::answers(), new MockClock())->find($this->root);

        self::assertSame($first, $second);
        self::assertSame($report, (string) file_get_contents($this->root.'/'.GooglePlaceFinder::REPORT_FILE));
        self::assertSame($before, $this->snapshot());
    }

    public function test_the_command_prints_counts_and_reasons_only(): void
    {
        DetailVenues::inCantonWithPin('47.3', '8.5', name: 'Kupferkessel Wiesental');
        DetailVenues::inRegionOnly($this->em, 'Bodensee', name: 'Remise Rheinhof');
        DetailVenues::withoutPlace(name: 'Wiesenhof Kaputt');
        $finder = $this->finder(self::answers() + ['Wiesenhof Kaputt  Schweiz' => new MockResponse('', ['http_code' => 500])], new MockClock());
        $output = new BufferedOutput();

        $status = (new FindGooglePlacesCommand($finder, $this->root, 'k'))(new SymfonyStyle(new ArrayInput([]), $output));

        $display = $output->fetch();
        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString("Looked up: 1 confident, 1 doubtful, 0 not found, 1 failed.\nDoubtful: name_differs 1\nReport: ".$this->root.'/'.GooglePlaceFinder::REPORT_FILE, $display);
        self::assertStringNotContainsString('ChIJ', $display);
        self::assertStringNotContainsString('Gasthof zur Post', $display);
        self::assertStringNotContainsString('Kupferkessel', $display);
    }

    /**
     * Google's answers for a confident venue (`Kupferkessel Wiesental`, pinned 0.6 km away) and a doubtful one
     * (`Remise Rheinhof`, another name), fresh for every finder.
     *
     * @return array<string, MockResponse>
     */
    private static function answers(): array
    {
        return [
            'Kupferkessel Wiesental Zürich Schweiz' => new JsonMockResponse(['places' => [
                self::place('ChIJKupfer0000001', 'Kupferkessel Wiesental', 'Wiesenweg 4, 8610 Uster', 47.305, 8.5, self::USTER),
            ]]),
            'Remise Rheinhof Bodensee Schweiz' => new JsonMockResponse(['places' => [
                self::place('ChIJRheinhof0002', 'Gasthof zur Post', 'Hauptstrasse 1, 8590 Romanshorn', 47.56, 9.37, [['Romanshorn', 'locality'], ['Thurgau', 'administrative_area_level_1', 'TG'], ['Schweiz', 'country', 'CH']]),
            ]]),
        ];
    }

    /**
     * One place as Text Search answers #57's field mask.
     *
     * @param list<array{0: string, 1: string, 2?: string}> $areas long text, type, short text (default: the long text)
     *
     * @return array<string, mixed>
     */
    private static function place(string $id, string $name, string $address, float $lat, float $lng, array $areas): array
    {
        return [
            'id' => $id,
            'displayName' => ['text' => $name],
            'formattedAddress' => $address,
            'location' => ['latitude' => $lat, 'longitude' => $lng],
            'types' => ['event_venue', 'point_of_interest', 'establishment'],
            'businessStatus' => 'OPERATIONAL',
            'addressComponents' => array_map(static fn (array $area): array => [
                'longText' => $area[0],
                'shortText' => $area[2] ?? $area[0],
                'types' => [$area[1], 'political'],
            ], $areas),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function snapshot(): array
    {
        return $this->connection->fetchAllAssociative('SELECT number, google_place_id, updated_at FROM venues ORDER BY number');
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
        $translator = self::getContainer()->get('translator');
        self::assertInstanceOf(TranslatorInterface::class, $translator);

        return new GooglePlaceFinder(new VenueGeocoder($client, 'k'), new GooglePlaceLookupQuery($this->connection), $translator, $clock);
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
