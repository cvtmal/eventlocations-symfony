<?php

declare(strict_types=1);

namespace App\Venues\ValueObject;

/**
 * A venue's real position, as the Google Maps URLs of the Standort map (#15, DP-10) need it.
 *
 * The embed uses `place` mode with a Plus Code: its `q` takes a place name, an address, a Plus
 * Code or a place ID, never bare coordinates, and `view` mode would show no marker. The 10-digit
 * Open Location Code is a cell of about 14 × 14 m, so the pin lands on the venue itself.
 */
final readonly class MapLocation
{
    private const ALPHABET = '23456789CFGHJMPQRVWX';

    // Street level with the surroundings in view, as eventlokale's map shows a venue.
    private const ZOOM = 15;

    public function __construct(public float $lat, public float $lng)
    {
    }

    /**
     * The 10-digit Open Location Code, in the reference implementation's integer form, so
     * floating-point error cannot move the code into a neighbouring cell.
     */
    public function plusCode(): string
    {
        $lat = max(-90.0, min(90.0, $this->lat));
        if (90.0 === $lat) {
            $lat -= 0.000125;
        }
        $lng = $this->lng;
        while ($lng < -180.0) {
            $lng += 360.0;
        }
        while ($lng >= 180.0) {
            $lng -= 360.0;
        }

        $latVal = intdiv((int) round(($lat + 90) * 25_000_000), 3125);
        $lngVal = intdiv((int) round(($lng + 180) * 8_192_000), 1024);

        $code = '';
        for ($i = 0; $i < 5; ++$i) {
            $code = self::ALPHABET[$latVal % 20].self::ALPHABET[$lngVal % 20].$code;
            $latVal = intdiv($latVal, 20);
            $lngVal = intdiv($lngVal, 20);
        }

        return substr($code, 0, 8).'+'.substr($code, 8);
    }

    /** The keyless Maps link (Maps URLs, `api=1`): it needs no key and opens the coordinates. */
    public function searchUrl(): string
    {
        return 'https://www.google.com/maps/search/?'.http_build_query(
            ['api' => 1, 'query' => \sprintf('%.6F,%.6F', $this->lat, $this->lng)],
            '',
            '&',
            \PHP_QUERY_RFC3986,
        );
    }

    public function embedUrl(string $key, string $language): string
    {
        return 'https://www.google.com/maps/embed/v1/place?'.http_build_query(
            ['key' => $key, 'q' => $this->plusCode(), 'zoom' => self::ZOOM, 'language' => $language],
            '',
            '&',
            \PHP_QUERY_RFC3986,
        );
    }
}
