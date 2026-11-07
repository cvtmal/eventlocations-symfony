<?php

declare(strict_types=1);

namespace App\Search\ReadModel;

use App\Venues\ValueObject\Access;
use App\Venues\ValueObject\TermGroup;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;

/**
 * The listing's filter as SQL fragments over `venues v`, shared by the card and the count
 * query so the two can never disagree.
 *
 * - Published means status published and a translation in the default locale (the card
 *   needs a slug and a summary).
 * - Groups combine with AND; inside a group event type, setting and building are OR, the
 *   other term groups and access AND. Guests (F5) need a venue that holds that many
 *   (capacity_max) and states no higher minimum (capacity_min NULL or at most the number).
 * - The radius centre is a joined MATERIALIZED CTE (`centre c`): only that shape lets the
 *   planner use idx_venues_earth. An unknown town or ZIP leaves the CTE empty, so 0 rows.
 *
 * Only code-defined identifiers are interpolated; every value is a bound parameter.
 */
final readonly class ListingSql
{
    /** Term groups whose values match any of (OR); every other filtered group needs all (AND). */
    private const OR_GROUPS = [TermGroup::SuitableFor, TermGroup::Setting, TermGroup::Building];

    /** `WITH centre AS MATERIALIZED (…)` for a radius search, else ''. */
    public string $with;

    /** `venues v`, plus `CROSS JOIN centre c` for a radius search. */
    public string $from;

    /** The AND-joined predicates, starting with the published check. */
    public string $where;

    /** @var array<string, mixed> */
    public array $params;

    /** @var array<string, ArrayParameterType|ParameterType> */
    public array $types;

    public function __construct(SearchCriteria $criteria, string $defaultLocale)
    {
        $with = '';
        $from = 'venues v';
        $where = [
            "v.status = 'published'",
            'EXISTS (SELECT 1 FROM venue_translations vt WHERE vt.venue_id = v.id AND vt.locale = :default_locale)',
        ];
        $params = ['default_locale' => $defaultLocale];
        $types = [];

        if (null !== $criteria->canton) {
            $where[] = 'v.canton_code = :canton';
            $params['canton'] = $criteria->canton->value;
        }

        if (null !== $criteria->place) {
            $key = $criteria->isZipPlace() ? 'zip' : 'town';
            $params[$key] = $criteria->place;
            if (0 === $criteria->radius) {
                $where[] = $criteria->isZipPlace() ? 'v.zip = :zip' : 'v.town_id = :town';
            } else {
                $with = $criteria->isZipPlace()
                    ? 'WITH centre AS MATERIALIZED (SELECT ll_to_earth(t.lat, t.lng) AS e FROM town_zips tz JOIN towns t ON t.id = tz.town_id WHERE tz.zip = :zip ORDER BY t.population DESC, t.name LIMIT 1)'
                    : 'WITH centre AS MATERIALIZED (SELECT ll_to_earth(t.lat, t.lng) AS e FROM towns t WHERE t.id = :town)';
                $from = 'venues v CROSS JOIN centre c';
                $where[] = 'earth_box(c.e, :metres) @> ll_to_earth(v.lat, v.lng)';
                $where[] = 'earth_distance(c.e, ll_to_earth(v.lat, v.lng)) <= :metres';
                $params['metres'] = (float) (($criteria->radius ?? SearchCriteria::DEFAULT_RADIUS) * 1000);
            }
        }

        if (null !== $criteria->guests) {
            $where[] = 'v.capacity_max >= :guests';
            $where[] = '(v.capacity_min IS NULL OR v.capacity_min <= :guests)';
            $params['guests'] = $criteria->guests;
            $types['guests'] = ParameterType::INTEGER;
        }

        foreach (SearchCriteria::PARAMS as $group) {
            $codes = $criteria->termCodes($group);
            if ([] === $codes) {
                continue;
            }
            $g = $group->value;
            $params['g_'.$g] = $g;
            $params['c_'.$g] = $codes;
            $types['c_'.$g] = ArrayParameterType::STRING;
            if (\in_array($group, self::OR_GROUPS, true)) {
                $where[] = "EXISTS (SELECT 1 FROM venue_terms x WHERE x.venue_id = v.id AND x.term_group = :g_{$g} AND x.term_code IN (:c_{$g}))";
            } else {
                $where[] = "(SELECT count(*) FROM venue_terms x WHERE x.venue_id = v.id AND x.term_group = :g_{$g} AND x.term_code IN (:c_{$g})) = :n_{$g}";
                $params['n_'.$g] = \count($codes);
                $types['n_'.$g] = ParameterType::INTEGER;
            }
        }

        foreach ($criteria->access as $access) {
            $column = self::accessColumn($access);
            $where[] = "v.{$column} <= :km_{$access->value}";
            $params['km_'.$access->value] = $access->maxDistanceKm();
        }

        if (null !== $criteria->rooms) {
            $where[] = 'v.room_count >= :rooms';
            $params['rooms'] = $criteria->rooms;
            $types['rooms'] = ParameterType::INTEGER;
        }
        if (null !== $criteria->area) {
            $where[] = 'v.largest_room_m2 >= :area';
            $params['area'] = $criteria->area;
            $types['area'] = ParameterType::INTEGER;
        }
        if (null !== $criteria->height) {
            $where[] = 'v.max_room_height_m >= :height';
            $params['height'] = $criteria->height;
        }

        if (null !== $criteria->q) {
            $where[] = "(geo_fold_de(:q) <> '' AND (geo_search_key(v.name) LIKE '%' || geo_fold_de(:q) || '%' OR geo_search_key(v.name) LIKE '%' || geo_fold(:q) || '%'))";
            $params['q'] = $criteria->q;
        }

        $this->with = $with;
        $this->from = $from;
        $this->where = implode("\n  AND ", $where);
        $this->params = $params;
        $this->types = $types;
    }

    /**
     * The venues column an Access filter compares with its threshold.
     */
    public static function accessColumn(Access $access): string
    {
        return match ($access) {
            Access::Station => 'distance_station_km',
            Access::Highway => 'distance_highway_km',
            Access::Airport => 'distance_airport_km',
        };
    }
}
