<?php

declare(strict_types=1);

namespace App\Tests\Unit\Venues;

use App\Venues\ValueObject\MapLocation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MapLocationTest extends TestCase
{
    private const SEARCH_URL = 'https://www.google.com/maps/search/?api=1&query=47.366667%2C8.550000';

    /**
     * Length-10 rows of the reference test data,
     * https://raw.githubusercontent.com/google/open-location-code/main/test_data/encoding.csv (read 2026-10-30).
     *
     * @return iterable<string, array{float, float, string}>
     */
    public static function referenceVectors(): iterable
    {
        yield 'north-east' => [20.3700625, 2.7821875, '7FG49QCJ+2V'];
        yield 'switzerland cell corner' => [47.0000625, 8.0000625, '8FVC2222+22'];
        yield 'south-east' => [-41.2730625, 174.7859375, '4VCPPQGP+Q9'];
        yield 'south-west corner' => [-89.9999375, -179.9999375, '22222222+22'];
        yield 'north pole' => [90.0, 1.0, 'CFX3X2X2+X2'];
        yield 'longitude above 180' => [47.0000625, 728.0000625, '8FVC2222+22'];
        yield 'longitude below -180' => [-41.2730625, -905.2140625, '4VCPPQGP+Q9'];
    }

    #[DataProvider('referenceVectors')]
    public function test_plus_code_matches_the_reference_vectors(float $lat, float $lng, string $code): void
    {
        self::assertSame($code, (new MapLocation($lat, $lng))->plusCode());
    }

    public function test_plus_code_of_a_swiss_venue(): void
    {
        self::assertSame('8FVC9H82+M2', (new MapLocation(47.366667, 8.55))->plusCode());
    }

    public function test_search_url_carries_the_coordinates(): void
    {
        self::assertSame(self::SEARCH_URL, (new MapLocation(47.366667, 8.55))->searchUrl());
    }

    public function test_search_url_ignores_the_numeric_locale(): void
    {
        // %F ignores the locale, so a decimal comma cannot reach the URL. de_DE, because de_CH writes a point.
        $previous = setlocale(\LC_NUMERIC, '0');
        try {
            if (false === setlocale(\LC_NUMERIC, 'de_DE.UTF-8')) {
                self::markTestSkipped('The de_DE.UTF-8 locale is not installed.');
            }
            self::assertSame(self::SEARCH_URL, (new MapLocation(47.366667, 8.55))->searchUrl());
        } finally {
            setlocale(\LC_NUMERIC, (string) $previous);
        }
    }

    public function test_embed_url_encodes_every_parameter(): void
    {
        $location = new MapLocation(47.366667, 8.55);

        self::assertSame(
            'https://www.google.com/maps/embed/v1/place?key=test-key&q=8FVC9H82%2BM2&zoom=15&language=de',
            $location->embedUrl('test-key', 'de'),
        );
        self::assertStringContainsString('key=a%26b%22c&q=', $location->embedUrl('a&b"c', 'de'));
    }
}
