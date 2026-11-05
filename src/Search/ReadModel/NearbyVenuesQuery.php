<?php

declare(strict_types=1);

namespace App\Search\ReadModel;

use App\Search\ValueObject\NearbyPoint;
use App\Shared\Translation\EnabledLocale;
use App\Shared\Translation\TranslationJoin;
use App\Venues\ValueObject\VenuePlace;
use Doctrine\DBAL\Connection;

/**
 * The near-me page's venues (#52): published ones with coordinates, on `ListingSql`'s published
 * predicate, so the page can never show a venue the listing would hide.
 *
 * - `within()`: those within RADIUS_KM of a point, nearest first (owner Q4: distance only, no
 *   tier), `v.number` breaking ties between venues on one town-centre point.
 * - `all()`: every one in number order, for the whole-Switzerland map before a location (Q3).
 *
 * Both are capped (refuse extreme input), well above today's 247 venues.
 */
final class NearbyVenuesQuery
{
    public const int RADIUS_KM = 30;
    public const int LIMIT = 100;
    public const int ALL_CAP = 400;

    private const string COLUMNS = 'v.number, v.name, t.slug, tw.name AS town_name, v.canton_code, t.region, v.lat, v.lng';

    public function __construct(
        private readonly Connection $connection,
        private readonly TranslationJoin $translationJoin,
        private readonly EnabledLocale $enabledLocale,
    ) {
    }

    /**
     * @return list<NearbyVenue>
     */
    public function within(NearbyPoint $point, string $locale): array
    {
        $published = new ListingSql(SearchCriteria::none(), $this->enabledLocale->default());
        $translation = $this->translationJoin->localized('venue_translations', 'venue_id', 'v.id', 't');
        $columns = self::COLUMNS;
        $limit = self::LIMIT;

        $rows = $this->connection->fetchAllAssociative(
            <<<SQL
                WITH centre AS MATERIALIZED (SELECT ll_to_earth(:lat, :lng) AS e)
                SELECT {$columns}, earth_distance(c.e, ll_to_earth(v.lat, v.lng)) AS distance_m
                FROM venues v CROSS JOIN centre c
                LEFT JOIN towns tw ON tw.id = v.town_id
                {$translation}
                WHERE {$published->where} AND v.lat IS NOT NULL
                  AND earth_box(c.e, :metres) @> ll_to_earth(v.lat, v.lng)
                  AND earth_distance(c.e, ll_to_earth(v.lat, v.lng)) <= :metres
                ORDER BY distance_m, v.number
                LIMIT {$limit}
                SQL,
            $published->params + $this->translationJoin->parameters($locale) + [
                'lat' => $point->lat,
                'lng' => $point->lng,
                'metres' => self::RADIUS_KM * 1000.0,
            ],
            $published->types,
        );

        return array_map(self::venue(...), $rows);
    }

    /**
     * @return list<NearbyVenue>
     */
    public function all(string $locale): array
    {
        $published = new ListingSql(SearchCriteria::none(), $this->enabledLocale->default());
        $translation = $this->translationJoin->localized('venue_translations', 'venue_id', 'v.id', 't');
        $columns = self::COLUMNS;
        $limit = self::ALL_CAP;

        $rows = $this->connection->fetchAllAssociative(
            <<<SQL
                SELECT {$columns}, NULL AS distance_m
                FROM venues v
                LEFT JOIN towns tw ON tw.id = v.town_id
                {$translation}
                WHERE {$published->where} AND v.lat IS NOT NULL
                ORDER BY v.number
                LIMIT {$limit}
                SQL,
            $published->params + $this->translationJoin->parameters($locale),
            $published->types,
        );

        return array_map(self::venue(...), $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function venue(array $row): NearbyVenue
    {
        \assert(\is_int($row['number']));
        \assert(\is_string($row['name']));
        \assert(\is_string($row['slug']));
        \assert(null === $row['town_name'] || \is_string($row['town_name']));
        \assert(null === $row['canton_code'] || \is_string($row['canton_code']));
        \assert(null === $row['region'] || \is_string($row['region']));
        \assert(is_numeric($row['lat']));
        \assert(is_numeric($row['lng']));
        \assert(null === $row['distance_m'] || is_numeric($row['distance_m']));

        return new NearbyVenue(
            $row['number'],
            $row['name'],
            $row['slug'],
            VenuePlace::of($row['town_name'], $row['canton_code'], $row['region']),
            (float) $row['lat'],
            (float) $row['lng'],
            null === $row['distance_m'] ? null : (float) $row['distance_m'] / 1000,
        );
    }
}
