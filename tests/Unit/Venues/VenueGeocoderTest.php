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
}
