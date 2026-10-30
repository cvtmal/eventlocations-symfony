<?php

declare(strict_types=1);

namespace App\Tests\Integration\Seo;

use App\Geo\ValueObject\Canton;
use App\Seo\ValueObject\VenueFacts;
use App\Seo\VenueJsonLd;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class VenueJsonLdTest extends KernelTestCase
{
    public function test_a_full_address_is_a_local_business(): void
    {
        $data = $this->build($this->facts(street: 'Seestrasse 1', zip: '8712', town: 'Stäfa', canton: Canton::ZH, lat: 47.2, lng: 8.7, score: 9.2, count: 474));

        self::assertSame(['EventVenue', 'LocalBusiness'], $data['@type']);
        self::assertSame('https://example.ch/v#venue', $data['@id']);
        self::assertSame('Schloss Au', $data['name']);
        self::assertSame('https://example.ch/v', $data['url']);
        self::assertSame('Ein Schloss.', $data['description']);
        self::assertSame(120, $data['maximumAttendeeCapacity']);
        self::assertSame([
            '@type' => 'PostalAddress',
            'streetAddress' => 'Seestrasse 1',
            'postalCode' => '8712',
            'addressLocality' => 'Stäfa',
            'addressRegion' => 'Zürich',
            'addressCountry' => 'CH',
        ], $data['address']);
        self::assertSame(['@type' => 'GeoCoordinates', 'latitude' => 47.2, 'longitude' => 8.7], $data['geo']);
        self::assertSame(['@type' => 'AggregateRating', 'ratingValue' => 9.2, 'ratingCount' => 474, 'bestRating' => 10], $data['aggregateRating']);
        self::assertArrayNotHasKey('containedInPlace', $data);
    }

    public function test_zip_and_town_without_a_street_stay_a_local_business(): void
    {
        $data = $this->build($this->facts(zip: '8712', town: 'Stäfa'));

        self::assertSame(['EventVenue', 'LocalBusiness'], $data['@type']);
        self::assertSame(['@type' => 'PostalAddress', 'postalCode' => '8712', 'addressLocality' => 'Stäfa', 'addressCountry' => 'CH'], $data['address']);
        self::assertArrayNotHasKey('geo', $data);
    }

    public function test_a_town_without_a_zip_is_no_address(): void
    {
        $data = $this->build($this->facts(town: 'Stäfa', canton: Canton::ZH));

        self::assertSame('EventVenue', $data['@type']);
        self::assertArrayNotHasKey('address', $data);
        self::assertSame('Kanton Zürich', self::array($data, 'containedInPlace')['name']);
    }

    public function test_a_canton_alone_is_named_and_earns_no_stars(): void
    {
        $data = $this->build($this->facts(canton: Canton::ZH, score: 9.0, count: 10));

        self::assertSame('EventVenue', $data['@type']);
        self::assertArrayNotHasKey('address', $data);
        self::assertArrayNotHasKey('aggregateRating', $data);
        self::assertSame(['@type' => 'AdministrativeArea', 'name' => 'Kanton Zürich'], $data['containedInPlace']);
    }

    public function test_a_venue_without_a_place_is_a_bare_event_venue(): void
    {
        $data = $this->build($this->facts());

        self::assertSame('EventVenue', $data['@type']);
        foreach (['address', 'containedInPlace', 'geo', 'aggregateRating'] as $key) {
            self::assertArrayNotHasKey($key, $data);
        }
    }

    public function test_coordinates_alone_keep_geo(): void
    {
        $data = $this->build($this->facts(lat: 47.2, lng: 8.7));

        self::assertSame('EventVenue', $data['@type']);
        self::assertArrayHasKey('geo', $data);
    }

    public function test_one_coordinate_is_no_geo(): void
    {
        self::assertArrayNotHasKey('geo', $this->build($this->facts(lat: 47.2)));
    }

    public function test_a_rating_without_a_count_is_left_out(): void
    {
        $data = $this->build($this->facts(zip: '8712', town: 'Stäfa', score: 9.0, count: 0));

        self::assertArrayNotHasKey('aggregateRating', $data);
    }

    public function test_at_most_three_images_and_the_same_as_links(): void
    {
        $data = $this->build($this->facts(images: ['a', 'b', 'c', 'd'], sameAs: ['https://a.example', 'https://www.instagram.com/a']));

        self::assertSame(['a', 'b', 'c'], $data['image']);
        self::assertSame(['https://a.example', 'https://www.instagram.com/a'], $data['sameAs']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function shapes(): iterable
    {
        yield 'full' => [['street' => 'Seestrasse 1', 'zip' => '8712', 'town' => 'Stäfa', 'canton' => Canton::ZH, 'lat' => 47.2, 'lng' => 8.7, 'score' => 9.2, 'count' => 10]];
        yield 'zip and town' => [['zip' => '8712', 'town' => 'Stäfa']];
        yield 'canton only' => [['canton' => Canton::ZH]];
        yield 'nothing' => [[]];
        yield 'empty strings' => [['street' => '', 'zip' => '', 'town' => '']];
    }

    /**
     * @param array<string, mixed> $shape
     */
    #[DataProvider('shapes')]
    public function test_no_shape_has_contact_keys_or_empty_values(array $shape): void
    {
        $data = $this->build($this->facts(...$shape));

        self::assertArrayNotHasKey('telephone', $data);
        self::assertArrayNotHasKey('email', $data);
        array_walk_recursive($data, static function (mixed $value): void {
            self::assertNotContains($value, [null, '']);
        });
        foreach ($data as $key => $value) {
            self::assertNotSame([], $value, $key);
        }
        if (isset($data['address'])) {
            foreach (self::array($data, 'address') as $key => $value) {
                self::assertNotSame([], $value, $key);
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function array(array $data, string $key): array
    {
        self::assertIsArray($data[$key]);

        /* @var array<string, mixed> */
        return $data[$key];
    }

    /**
     * @return array<string, mixed>
     */
    private function build(VenueFacts $facts): array
    {
        $builder = self::getContainer()->get(VenueJsonLd::class);
        self::assertInstanceOf(VenueJsonLd::class, $builder);

        return $builder->build($facts);
    }

    /**
     * @param list<string> $images
     * @param list<string> $sameAs
     */
    private function facts(
        ?string $street = null,
        ?string $zip = null,
        ?string $town = null,
        ?Canton $canton = null,
        ?float $lat = null,
        ?float $lng = null,
        ?float $score = null,
        ?int $count = null,
        array $images = [],
        array $sameAs = [],
    ): VenueFacts {
        return new VenueFacts('Schloss Au', 'https://example.ch/v', 'Ein Schloss.', 120, $street, $zip, $town, $canton, $lat, $lng, $score, $count, $images, $sameAs);
    }
}
