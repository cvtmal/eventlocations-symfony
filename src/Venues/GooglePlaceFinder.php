<?php

declare(strict_types=1);

namespace App\Venues;

use App\Venues\ReadModel\GooglePlaceLookupQuery;
use App\Venues\ReadModel\GooglePlaceLookupVenue;
use App\Venues\ValueObject\GooglePlaceCandidate;
use App\Venues\ValueObject\GooglePlaceId;
use App\Venues\ValueObject\GooglePlaceReason;
use App\Venues\ValueObject\GooglePlaceVerdict;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * One-off (#53, D6; #57): looks up a Google place ID for every venue without one, into
 * <root>/owner/google-places-report.csv. It asks the geocode question (`VenueCoordinateFiller::searchText()`)
 * for up to five candidates, lets `GooglePlaceMatcher` decide each row, and makes every row whose place another
 * venue also gets (in this run or already in the database) doubtful. Only confident rows come with `apply=yes`;
 * a person checks the rest with the reason and the Maps link, and `app:venues:apply-google-places` sets the
 * `yes` rows. It writes nothing to the database. The report holds Google's names and addresses, which the Maps
 * terms (§3.2.3) do not let us keep: it stays git-ignored with the rest of content/ and is deleted once staff
 * are done.
 */
final class GooglePlaceFinder
{
    public const string REPORT_FILE = 'owner/google-places-report.csv';

    public const array HEADER = ['number', 'name', 'published', 'confidence', 'reason', 'apply', 'maps_url', 'place_id', 'google_name', 'google_address', 'distance_km', 'query'];

    private const float PAUSE_SECONDS = 0.1;

    public function __construct(
        private readonly VenueGeocoder $geocoder,
        private readonly GooglePlaceLookupQuery $venues,
        private readonly TranslatorInterface $translator,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * `missed` counts the rows Google found nothing for; `reasons` maps each doubtful reason code to its rows, in
     * check order, zero counts left out.
     *
     * @return array{confident: int, doubtful: int, missed: int, failed: int, reasons: array<string, int>}
     */
    public function find(string $contentRoot, ?int $limit = null): array
    {
        $owners = $this->venues->placeOwners();

        /** @var list<array{venue: GooglePlaceLookupVenue, query: string, top: ?GooglePlaceCandidate, verdict: GooglePlaceVerdict}> $lookups */
        $lookups = [];
        foreach ($this->venues->withoutPlace($limit) as $venue) {
            $query = VenueCoordinateFiller::searchText($venue->name, $venue->cantonCode, $venue->region);
            try {
                $candidates = $this->geocoder->candidates($query);
                $verdict = [] === $candidates ? GooglePlaceVerdict::notFound() : GooglePlaceMatcher::decide($venue, $candidates);
            } catch (ExceptionInterface) {
                $candidates = [];
                $verdict = GooglePlaceVerdict::failed();
            }
            $this->clock->sleep(self::PAUSE_SECONDS);
            $lookups[] = ['venue' => $venue, 'query' => $query, 'top' => $candidates[0] ?? null, 'verdict' => $verdict];
        }

        $lookups = self::samePlace($lookups, $owners);

        $rows = [self::HEADER];
        $counts = ['confident' => 0, 'doubtful' => 0, 'missed' => 0, 'failed' => 0];
        $doubtful = [];
        foreach ($lookups as $lookup) {
            $rows[] = $this->row($lookup['venue'], $lookup['query'], $lookup['top'], $lookup['verdict']);
            $reason = $lookup['verdict']->reason;
            $count = match ($reason->confidence()) {
                'confident' => 'confident',
                'not found' => 'missed',
                'failed' => 'failed',
                default => 'doubtful',
            };
            ++$counts[$count];
            if ('doubtful' === $count) {
                $doubtful[$reason->value] = ($doubtful[$reason->value] ?? 0) + 1;
            }
        }

        ContentYamlFile::replace(rtrim($contentRoot, '/').'/'.self::REPORT_FILE, VenueCoordinateFiller::csv($rows));

        $reasons = [];
        foreach (GooglePlaceReason::cases() as $reason) {
            if (isset($doubtful[$reason->value])) {
                $reasons[$reason->value] = $doubtful[$reason->value];
            }
        }

        return $counts + ['reasons' => $reasons];
    }

    /**
     * A confident row whose place is also another venue's top candidate in this run, or already set on another
     * venue, becomes doubtful and names those venues; a row already doubtful keeps its first reason. So every
     * row sharing a place ID is doubtful.
     *
     * @param list<array{venue: GooglePlaceLookupVenue, query: string, top: ?GooglePlaceCandidate, verdict: GooglePlaceVerdict}> $lookups
     * @param array<string, list<int>>                                                                                           $owners  place ID → venues holding it
     *
     * @return list<array{venue: GooglePlaceLookupVenue, query: string, top: ?GooglePlaceCandidate, verdict: GooglePlaceVerdict}>
     */
    private static function samePlace(array $lookups, array $owners): array
    {
        $asked = [];
        foreach ($lookups as $lookup) {
            if (null !== $lookup['top']) {
                $asked[$lookup['top']->id][] = $lookup['venue']->number;
            }
        }

        foreach ($lookups as $i => $lookup) {
            if (null === $lookup['top'] || !$lookup['verdict']->confident()) {
                continue;
            }
            $id = $lookup['top']->id;
            $others = array_values(array_unique(array_diff(array_merge($asked[$id] ?? [], $owners[$id] ?? []), [$lookup['venue']->number])));
            sort($others);
            if ([] !== $others) {
                $lookups[$i]['verdict'] = GooglePlaceVerdict::samePlace($others);
            }
        }

        return $lookups;
    }

    /**
     * @return list<string>
     */
    private function row(GooglePlaceLookupVenue $venue, string $query, ?GooglePlaceCandidate $top, GooglePlaceVerdict $verdict): array
    {
        return [
            (string) $venue->number,
            $venue->name,
            $venue->published ? 'yes' : 'no',
            $verdict->reason->confidence(),
            $verdict->trans($this->translator, 'de'),
            $verdict->confident() ? 'yes' : '',
            null === $top ? GooglePlaceId::searchUrl($query) : GooglePlaceId::mapsUrl($venue->name, $top->id),
            $top->id ?? '',
            $top->name ?? '',
            $top->address ?? '',
            null === $top || null === $venue->lat || null === $venue->lng ? '' : number_format(GooglePlaceMatcher::distanceKm($venue->lat, $venue->lng, $top->lat, $top->lng), 1, '.', ''),
            $query,
        ];
    }
}
