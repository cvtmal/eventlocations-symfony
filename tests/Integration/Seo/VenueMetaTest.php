<?php

declare(strict_types=1);

namespace App\Tests\Integration\Seo;

use App\Geo\ValueObject\Canton;
use App\Seo\ValueObject\PageMeta;
use App\Seo\ValueObject\VenueFacts;
use App\Seo\VenueMeta;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class VenueMetaTest extends KernelTestCase
{
    public function test_a_venue_with_a_town_names_it(): void
    {
        $meta = $this->meta(town: 'Stäfa', canton: Canton::ZH);

        self::assertSame('Schloss Au in Stäfa mieten | Hochzeitslocation', $meta->title);
    }

    public function test_a_name_too_long_for_the_town_keeps_the_name(): void
    {
        $meta = $this->meta(town: 'Rapperswil-Jona', name: 'Grand Hotel Seeblick und Kurhaus');

        self::assertSame('Grand Hotel Seeblick und Kurhaus | Hochzeitslocation', $meta->title);
    }

    public function test_a_venue_with_only_a_canton_names_the_canton(): void
    {
        $meta = $this->meta(canton: Canton::ZH);

        self::assertSame('Schloss Au im Kanton Zürich | Hochzeitslocation', $meta->title);
    }

    public function test_liechtenstein_alone_is_named_without_kanton(): void
    {
        self::assertSame('Schloss Au in Liechtenstein | Hochzeitslocation', $this->meta(canton: Canton::LI)->title);
    }

    public function test_a_venue_without_a_place_has_the_name_alone(): void
    {
        self::assertSame('Schloss Au | Hochzeitslocation', $this->meta()->title);
    }

    public function test_an_empty_town_counts_as_none(): void
    {
        self::assertSame('Schloss Au | Hochzeitslocation', $this->meta(town: '')->title);
    }

    public function test_coordinates_make_it_a_place(): void
    {
        $meta = $this->meta(lat: 47.1, lng: 8.5);

        self::assertSame('place', $meta->ogType);
        self::assertSame(47.1, $meta->latitude);
        self::assertSame(8.5, $meta->longitude);
    }

    public function test_without_coordinates_it_is_a_website(): void
    {
        $meta = $this->meta(lat: 47.1);

        self::assertSame('website', $meta->ogType);
        self::assertNull($meta->latitude);
        self::assertNull($meta->longitude);
    }

    public function test_canonical_description_and_robots(): void
    {
        $meta = $this->meta();

        self::assertSame('https://example.ch/hochzeitslocation/schloss-au', $meta->canonical);
        self::assertSame('Ein Schloss. Mit Park.', $meta->description);
        self::assertFalse($meta->noindex);
    }

    private function meta(
        ?string $town = null,
        ?Canton $canton = null,
        ?float $lat = null,
        ?float $lng = null,
        string $name = 'Schloss Au',
    ): PageMeta {
        $meta = self::getContainer()->get(VenueMeta::class);
        self::assertInstanceOf(VenueMeta::class, $meta);

        return $meta->for(new VenueFacts(
            $name,
            'https://example.ch/hochzeitslocation/schloss-au',
            "Ein Schloss.\nMit Park.",
            100,
            null,
            null,
            $town,
            $canton,
            $lat,
            $lng,
            [],
            [],
        ));
    }
}
