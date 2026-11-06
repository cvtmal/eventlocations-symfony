<?php

declare(strict_types=1);

namespace App\Tests\Unit\Venues;

use App\Venues\VenueCoordinateFiller;
use App\Venues\VenueGeocoder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

final class VenueGeocoderTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/geocode-'.bin2hex(random_bytes(4));
        mkdir($this->root.'/venues/soho-1', 0775, true);
        mkdir($this->root.'/venues/done-2', 0775, true);
        file_put_contents($this->root.'/venues/soho-1/venue.yaml', "name: 'SOHO Zurich'\naddress:\n  canton: zh\n");
        file_put_contents($this->root.'/venues/done-2/venue.yaml', "name: Done\ngeo:\n  lat: 47.1\n  lng: 8.1\n");
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->root.'/venues/*/venue.yaml') ?: []);
        array_map('unlink', glob($this->root.'/owner/*') ?: []);
        array_map('rmdir', glob($this->root.'/venues/*') ?: []);
        @rmdir($this->root.'/owner');
        @rmdir($this->root.'/venues');
        @rmdir($this->root);
    }

    public function test_locate_returns_the_first_place_inside_switzerland(): void
    {
        $sent = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$sent): JsonMockResponse {
            $sent = $options['normalized_headers'];

            return new JsonMockResponse(['places' => [[
                'id' => 'ChIJGEHEIM12345',
                'displayName' => ['text' => 'SOHO Zurich'],
                'formattedAddress' => 'Seefeldstrasse 1, 8008 Zürich',
                'location' => ['latitude' => 47.3555551, 'longitude' => 8.5555551],
            ]]]);
        });

        $hit = (new VenueGeocoder($client, 'k'))->locate('SOHO Zurich Zürich Schweiz');

        self::assertNotNull($hit);
        self::assertSame(47.355555, $hit->lat);
        self::assertSame(8.555555, $hit->lng);
        self::assertSame('SOHO Zurich', $hit->name);
        self::assertSame('ChIJGEHEIM12345', $hit->placeId);
        self::assertSame(['X-Goog-FieldMask: places.id,places.displayName,places.formattedAddress,places.location'], $sent['x-goog-fieldmask']);
    }

    public function test_locate_without_an_id_reports_an_empty_place_id(): void
    {
        $client = new MockHttpClient(new JsonMockResponse(['places' => [[
            'displayName' => ['text' => 'SOHO Zurich'],
            'formattedAddress' => 'Seefeldstrasse 1, 8008 Zürich',
            'location' => ['latitude' => 47.3555551, 'longitude' => 8.5555551],
        ]]]));

        $hit = (new VenueGeocoder($client, 'k'))->locate('SOHO Zurich Zürich Schweiz');

        self::assertNotNull($hit);
        self::assertSame('', $hit->placeId);
    }

    public function test_locate_drops_a_place_outside_switzerland(): void
    {
        $client = new MockHttpClient(new JsonMockResponse(['places' => [[
            'location' => ['latitude' => 48.8566, 'longitude' => 2.3522],
        ]]]));

        self::assertNull((new VenueGeocoder($client, 'k'))->locate('x'));
    }

    public function test_locate_returns_null_without_a_place(): void
    {
        self::assertNull((new VenueGeocoder(new MockHttpClient(new JsonMockResponse([])), 'k'))->locate('x'));
    }

    public function test_fill_writes_geo_only_where_it_is_missing_and_reports(): void
    {
        $client = new MockHttpClient(new JsonMockResponse(['places' => [[
            'displayName' => ['text' => 'SOHO Zurich'],
            'formattedAddress' => 'Zürich',
            'location' => ['latitude' => 47.37, 'longitude' => 8.54],
        ]]]));

        $counts = (new VenueCoordinateFiller(new VenueGeocoder($client, 'k'), new MockClock()))->fill($this->root, false);

        self::assertSame(['found' => 1, 'missed' => 0, 'failed' => 0, 'skipped' => 1], $counts);
        $yaml = (string) file_get_contents($this->root.'/venues/soho-1/venue.yaml');
        self::assertStringContainsString("geo:\n  lat: 47.37\n  lng: 8.54", $yaml);
        self::assertSame(1, $client->getRequestsCount());
        self::assertStringContainsString('soho-1,"SOHO Zurich"', (string) file_get_contents($this->root.'/owner/geocode-report.csv') ?: 'x');
    }

    public function test_fill_in_a_dry_run_changes_no_venue(): void
    {
        $client = new MockHttpClient(new JsonMockResponse(['places' => [[
            'location' => ['latitude' => 47.37, 'longitude' => 8.54],
        ]]]));
        $before = (string) file_get_contents($this->root.'/venues/soho-1/venue.yaml');

        (new VenueCoordinateFiller(new VenueGeocoder($client, 'k'), new MockClock()))->fill($this->root, true);

        self::assertSame($before, (string) file_get_contents($this->root.'/venues/soho-1/venue.yaml'));
    }

    public function test_fill_counts_a_failed_request_and_goes_on(): void
    {
        $client = new MockHttpClient(new MockResponse('', ['http_code' => 500]));

        $counts = (new VenueCoordinateFiller(new VenueGeocoder($client, 'k'), new MockClock()))->fill($this->root, false);

        self::assertSame(1, $counts['failed']);
        self::assertStringNotContainsString('geo:', (string) file_get_contents($this->root.'/venues/soho-1/venue.yaml'));
    }

    public function test_the_search_text_names_the_canton_or_the_region_and_the_country(): void
    {
        self::assertSame('SOHO Zurich Zürich Schweiz', VenueCoordinateFiller::searchText('SOHO Zurich', 'zh', 'Seefeld'));
        self::assertSame('Rheinhof Bodensee Schweiz', VenueCoordinateFiller::searchText('Rheinhof', '', 'Bodensee'));
        self::assertSame('Rheinhof Liechtenstein', VenueCoordinateFiller::searchText('Rheinhof', 'li', ''));
        // As `app:venues:geocode` has always asked: an empty place leaves two spaces, which the search ignores.
        self::assertSame('Rheinhof  Schweiz', VenueCoordinateFiller::searchText('Rheinhof', '', ''));
    }

    public function test_names_match_on_a_shared_word_with_umlauts_folded(): void
    {
        self::assertTrue(VenueCoordinateFiller::namesMatch('Schloss Rörswil', 'Schloss Roerswil Bern'));
        self::assertFalse(VenueCoordinateFiller::namesMatch('Bar Rouge', 'Hotel Beau Rivage'));
    }

    public function test_candidates_asks_up_to_five_pro_fields_inside_the_box(): void
    {
        $headers = [];
        $body = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$headers, &$body): JsonMockResponse {
            $headers = $options['normalized_headers'];
            $body = json_decode((string) $options['body'], true, flags: \JSON_THROW_ON_ERROR);

            return new JsonMockResponse([]);
        });

        (new VenueGeocoder($client, 'k'))->candidates('Scheune Wiesental Zürich Schweiz');

        self::assertSame(['X-Goog-FieldMask: '.VenueGeocoder::CANDIDATE_FIELDS], $headers['x-goog-fieldmask']);
        self::assertSame('places.id,places.displayName,places.formattedAddress,places.location,places.types,places.addressComponents,places.businessStatus', VenueGeocoder::CANDIDATE_FIELDS);
        self::assertSame([
            'textQuery' => 'Scheune Wiesental Zürich Schweiz',
            'languageCode' => 'de',
            'regionCode' => 'CH',
            'pageSize' => 5,
            'locationRestriction' => ['rectangle' => [
                'low' => ['latitude' => 45.8, 'longitude' => 5.9],
                'high' => ['latitude' => 47.9, 'longitude' => 10.6],
            ]],
        ], $body);
    }

    public function test_candidates_reads_types_area_names_canton_country_and_status(): void
    {
        $client = new MockHttpClient(new JsonMockResponse(['places' => [
            [
                'id' => 'ChIJWiesental0001',
                'displayName' => ['text' => 'Scheune Wiesental'],
                'formattedAddress' => 'Wiesenweg 4, 8000 Zürich',
                'location' => ['latitude' => 47.3555551, 'longitude' => 8.5555551],
                'types' => ['event_venue', 'point_of_interest', 'establishment'],
                'businessStatus' => 'OPERATIONAL',
                'addressComponents' => [
                    ['longText' => '4', 'shortText' => '4', 'types' => ['street_number']],
                    ['longText' => 'Wiesenweg', 'shortText' => 'Wiesenweg', 'types' => ['route']],
                    ['longText' => '8000', 'shortText' => '8000', 'types' => ['postal_code']],
                    ['longText' => 'Wiesenau', 'shortText' => 'Wiesenau', 'types' => ['sublocality_level_1', 'sublocality', 'political']],
                    ['longText' => 'Zürich', 'shortText' => 'Zürich', 'types' => ['locality', 'political']],
                    ['longText' => 'Bezirk Zürich', 'shortText' => 'Bezirk Zürich', 'types' => ['administrative_area_level_2', 'political']],
                    ['longText' => 'Zürich', 'shortText' => 'ZH', 'types' => ['administrative_area_level_1', 'political']],
                    ['longText' => 'Schweiz', 'shortText' => 'CH', 'types' => ['country', 'political']],
                ],
            ],
            [
                'id' => 'ChIJWiesental0002',
                'displayName' => ['text' => 'Wiesental Beiz'],
                'location' => ['latitude' => 47.4, 'longitude' => 8.6],
            ],
        ]]));

        $candidates = (new VenueGeocoder($client, 'k'))->candidates('Scheune Wiesental Zürich Schweiz');

        self::assertCount(2, $candidates);
        [$first, $second] = $candidates;
        self::assertSame('ChIJWiesental0001', $first->id);
        self::assertSame('Scheune Wiesental', $first->name);
        self::assertSame('Wiesenweg 4, 8000 Zürich', $first->address);
        self::assertSame([47.355555, 8.555555], [$first->lat, $first->lng]);
        self::assertSame(['event_venue', 'point_of_interest', 'establishment'], $first->types);
        self::assertSame('zh', $first->cantonCode);
        self::assertSame('CH', $first->countryCode);
        self::assertSame('OPERATIONAL', $first->businessStatus);
        self::assertSame(['Wiesenau', 'Zürich', 'Bezirk Zürich', 'Schweiz'], $first->areaNames);
        self::assertSame(['Wiesenau', 'Zürich'], $first->townNames, 'the town level only, not the district, canton or country');
        self::assertSame(['ChIJWiesental0002', '', [], '', '', '', [], []], [$second->id, $second->address, $second->types, $second->cantonCode, $second->countryCode, $second->businessStatus, $second->areaNames, $second->townNames]);
    }

    public function test_candidates_drops_places_outside_the_box_or_without_an_id(): void
    {
        $client = new MockHttpClient(new JsonMockResponse(['places' => [
            ['id' => 'ChIJParis0000001', 'location' => ['latitude' => 48.8566, 'longitude' => 2.3522]],
            ['displayName' => ['text' => 'Ohne ID'], 'location' => ['latitude' => 47.1, 'longitude' => 8.1]],
            ['id' => 'ChIJOhneOrt00001'],
            ['id' => 'ChIJBern00000001', 'location' => ['latitude' => 46.95, 'longitude' => 7.45]],
        ]]));

        $candidates = (new VenueGeocoder($client, 'k'))->candidates('x');

        self::assertSame(['ChIJBern00000001'], array_map(static fn ($candidate): string => $candidate->id, $candidates));
    }

    public function test_candidates_keeps_at_most_five(): void
    {
        $places = [];
        for ($i = 1; $i <= 7; ++$i) {
            $places[] = ['id' => 'ChIJPlace000000'.$i, 'location' => ['latitude' => 47.0, 'longitude' => 8.0]];
        }
        $client = new MockHttpClient(new JsonMockResponse(['places' => $places]));

        $candidates = (new VenueGeocoder($client, 'k'))->candidates('x');

        self::assertSame(['ChIJPlace0000001', 'ChIJPlace0000002', 'ChIJPlace0000003', 'ChIJPlace0000004', 'ChIJPlace0000005'], array_map(static fn ($candidate): string => $candidate->id, $candidates));
    }

    public function test_candidates_returns_nothing_for_an_empty_answer(): void
    {
        self::assertSame([], (new VenueGeocoder(new MockHttpClient(new JsonMockResponse([])), 'k'))->candidates('x'));
    }

    public function test_candidates_throws_on_a_failed_request(): void
    {
        $this->expectException(ExceptionInterface::class);

        (new VenueGeocoder(new MockHttpClient(new MockResponse('', ['http_code' => 500])), 'k'))->candidates('x');
    }
}
