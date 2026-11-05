<?php

declare(strict_types=1);

namespace App\Venues;

use App\Venues\ReadModel\GooglePlaceLookupQuery;
use App\Venues\ReadModel\GooglePlaceLookupVenue;
use App\Venues\ValueObject\GeocodeHit;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

/**
 * One-off (#53, D6): suggests a Google place ID for every venue without one, into
 * <root>/owner/google-places-report.csv, for a person to check. It asks the geocode question
 * (`VenueCoordinateFiller::searchText()`) and writes nothing to the database: `apply` is always
 * empty, so no suggestion is ever trusted on its own. A person writes `yes` there and
 * `app:venues:apply-google-places` sets those rows. The report holds venue names and addresses,
 * so it stays git-ignored with the rest of content/.
 */
final class GooglePlaceFinder
{
    public const string REPORT_FILE = 'owner/google-places-report.csv';

    public const array HEADER = ['number', 'name', 'published', 'status', 'query', 'place_id', 'matched_name', 'name_matches', 'address', 'distance_km', 'apply'];

    private const float PAUSE_SECONDS = 0.1;

    private const float EARTH_RADIUS_KM = 6371.0;

    public function __construct(
        private readonly VenueGeocoder $geocoder,
        private readonly GooglePlaceLookupQuery $venues,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return array{found: int, missed: int, failed: int}
     */
    public function find(string $contentRoot, ?int $limit = null): array
    {
        $counts = ['found' => 0, 'missed' => 0, 'failed' => 0];
        $rows = [self::HEADER];

        foreach ($this->venues->withoutPlace($limit) as $venue) {
            $query = VenueCoordinateFiller::searchText($venue->name, $venue->cantonCode, $venue->region);
            try {
                $hit = $this->geocoder->locate($query);
            } catch (ExceptionInterface) {
                $hit = false;
            }
            $this->clock->sleep(self::PAUSE_SECONDS);

            if (false === $hit) {
                ++$counts['failed'];
                $rows[] = self::row($venue, 'failed', $query);
            } elseif (null === $hit || '' === $hit->placeId) {
                ++$counts['missed'];
                $rows[] = self::row($venue, 'not found', $query);
            } else {
                ++$counts['found'];
                $rows[] = self::row($venue, 'found', $query, $hit);
            }
        }

        ContentYamlFile::replace(rtrim($contentRoot, '/').'/'.self::REPORT_FILE, VenueCoordinateFiller::csv($rows));

        return $counts;
    }

    /**
     * @return list<string>
     */
    private static function row(GooglePlaceLookupVenue $venue, string $status, string $query, ?GeocodeHit $hit = null): array
    {
        return [
            (string) $venue->number,
            $venue->name,
            $venue->published ? 'yes' : 'no',
            $status,
            $query,
            $hit->placeId ?? '',
            $hit->name ?? '',
            null === $hit ? '' : (VenueCoordinateFiller::namesMatch($venue->name, $hit->name) ? 'yes' : 'NO'),
            $hit->address ?? '',
            null === $hit || null === $venue->lat || null === $venue->lng ? '' : number_format(self::distanceKm($venue->lat, $venue->lng, $hit->lat, $hit->lng), 1, '.', ''),
            '',
        ];
    }

    /** Great-circle distance (haversine); a report figure, not a search filter. */
    private static function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * self::EARTH_RADIUS_KM * asin(min(1.0, sqrt($a)));
    }
}
