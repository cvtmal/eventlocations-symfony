<?php

declare(strict_types=1);

namespace App\Search\ReadModel;

use App\Shared\Translation\EnabledLocale;
use App\Shared\Translation\TranslationJoin;
use App\Venues\ValueObject\Tier;
use App\Venues\ValueObject\VenuePlace;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * "Ähnliche Locations" for a venue: other published venues, same canton first, then those
 * whose capacity range overlaps, then the nearest. Overlap ranks, it does not filter, so
 * the result is always full when enough venues are published. `IS TRUE` because a NULL
 * canton would otherwise sort first under DESC; without coordinates the distance is NULL,
 * which sorts last, and the id decides.
 */
final class SimilarVenuesQuery
{
    public const LIMIT = 12;

    public function __construct(
        private readonly Connection $connection,
        private readonly TranslationJoin $translationJoin,
        private readonly EnabledLocale $enabledLocale,
    ) {
    }

    /**
     * @return list<SimilarVenue> empty for an invalid or unknown id
     */
    public function forVenue(string $venueId, string $locale): array
    {
        if (!Uuid::isValid($venueId)) {
            return [];
        }

        $published = new ListingSql(SearchCriteria::none(), $this->enabledLocale->default());
        $translation = $this->translationJoin->localized('venue_translations', 'venue_id', 'v.id', 't');
        $photoTranslation = $this->translationJoin->localized('venue_photo_translations', 'venue_photo_id', 'ph.id', 'pt');
        $limit = self::LIMIT;

        $rows = $this->connection->fetchAllAssociative(
            <<<SQL
                WITH me AS MATERIALIZED (
                    SELECT id, canton_code, capacity_min, capacity_max, ll_to_earth(lat, lng) AS e
                    FROM venues WHERE id = :id
                )
                SELECT v.id, v.number, v.name, v.tier, v.capacity_min, v.capacity_max,
                    tw.name AS town_name, v.canton_code, t.region, t.slug, ph.photo, pt.alt
                FROM me
                JOIN venues v ON v.id <> me.id
                LEFT JOIN towns tw ON tw.id = v.town_id
                {$translation}
                LEFT JOIN LATERAL (
                    SELECT to_jsonb(vp) AS photo, vp.id FROM venue_photos vp
                    WHERE vp.venue_id = v.id AND vp.room_id IS NULL
                    ORDER BY vp.position
                    LIMIT 1
                ) ph ON TRUE
                {$photoTranslation}
                WHERE {$published->where}
                ORDER BY ((v.canton_code = me.canton_code) IS TRUE) DESC,
                    (v.capacity_max >= COALESCE(me.capacity_min, 1) AND COALESCE(v.capacity_min, 1) <= me.capacity_max) DESC,
                    earth_distance(me.e, ll_to_earth(v.lat, v.lng)),
                    v.id
                LIMIT {$limit}
                SQL,
            ['id' => $venueId] + $published->params + $this->translationJoin->parameters($locale),
            $published->types,
        );

        return array_map(static function (array $row): SimilarVenue {
            \assert(\is_string($row['id']));
            \assert(\is_int($row['number']));
            \assert(\is_string($row['slug']));
            \assert(\is_string($row['name']));
            \assert(\is_string($row['tier']));
            \assert(null === $row['town_name'] || \is_string($row['town_name']));
            \assert(null === $row['canton_code'] || \is_string($row['canton_code']));
            \assert(null === $row['region'] || \is_string($row['region']));
            \assert(null === $row['capacity_min'] || \is_int($row['capacity_min']));
            \assert(\is_int($row['capacity_max']));
            \assert(null === $row['photo'] || \is_string($row['photo']));
            \assert(null === $row['alt'] || \is_string($row['alt']));

            $photo = null;
            if (null !== $row['photo']) {
                $decoded = json_decode($row['photo'], true, 512, \JSON_THROW_ON_ERROR);
                \assert(\is_array($decoded));
                $photo = CardPhoto::fromRow($row['number'], $decoded, $row['alt']);
            }

            return new SimilarVenue(
                $row['id'],
                $row['number'],
                $row['slug'],
                $row['name'],
                Tier::from($row['tier']),
                VenuePlace::of($row['town_name'], $row['canton_code'], $row['region']),
                $row['capacity_min'],
                $row['capacity_max'],
                $photo,
            );
        }, $rows);
    }
}
