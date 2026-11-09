<?php

declare(strict_types=1);

namespace App\Cms\ReadModel;

use App\Venues\ValueObject\PriceLevel;
use App\Venues\ValueObject\TermGroup;
use App\Venues\ValueObject\TownText;
use Doctrine\DBAL\Connection;

/**
 * The venue editor's data (D25): the header, then every card's values with the stale token in one
 * statement. Two queries.
 */
final class VenueEditQuery
{
    public function __construct(
        private readonly Connection $connection,
        private readonly VenueHeaderQuery $headers,
    ) {
    }

    public function find(int $number): ?VenueEdit
    {
        $header = $this->headers->find($number);
        if (null === $header) {
            return null;
        }

        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT EXTRACT(EPOCH FROM v.updated_at)::bigint::text AS loaded_at,
                       v.capacity_min, v.capacity_max, v.zip, tw.name AS town_name, v.canton_code,
                       v.lat::text AS lat, v.lng::text AS lng, v.coordinates_from_town,
                       v.distance_station_km::text AS station_km, v.station_name,
                       v.distance_highway_km::text AS highway_km, v.highway_name,
                       v.distance_airport_km::text AS airport_km, v.airport_name,
                       v.phone, v.inquiry_email, v.accepts_multi_inquiries, v.website, v.contact_person, v.instagram,
                       v.daylight,
                       v.price_level, v.capacity_seated, v.capacity_standing,
                       v.google_place_id, v.google_rating_hidden, v.facts_checked_at,
                       (SELECT COUNT(*) FROM venue_photos p WHERE p.venue_id = v.id AND p.room_id IS NULL) AS gallery_photo_count,
                       (SELECT COALESCE(jsonb_agg(jsonb_build_object('group', vt.term_group, 'code', vt.term_code, 'main', vt.is_main)
                                                  ORDER BY vt.term_group, tt.position), '[]')
                        FROM venue_terms vt
                        JOIN taxonomy_terms tt ON tt.term_group = vt.term_group AND tt.code = vt.term_code
                        WHERE vt.venue_id = v.id) AS terms,
                       (SELECT COALESCE(jsonb_agg(jsonb_build_object(
                                   'locale', t.locale, 'slug', t.slug, 'summary', t.summary, 'description', t.description,
                                   'meta_description', t.meta_description, 'parking_text', t.parking_text, 'region', t.region,
                                   'ambience', t.ambience, 'capacity_note', t.capacity_note,
                                   'opening_hours', t.opening_hours) ORDER BY t.locale), '[]')
                        FROM venue_translations t WHERE t.venue_id = v.id) AS translations
                FROM venues v
                LEFT JOIN towns tw ON tw.id = v.town_id
                WHERE v.id = :id
                SQL,
            ['id' => $header->id],
        );
        if (false === $row) {
            return null;
        }

        [$terms, $mainTerms] = self::terms((string) $row['terms']);

        return new VenueEdit(
            header: $header,
            loadedAt: (string) $row['loaded_at'],
            capacityMin: self::int($row['capacity_min']),
            capacityMax: (int) $row['capacity_max'],
            town: null === $row['zip'] || null === $row['town_name'] ? null : TownText::format((string) $row['zip'], (string) $row['town_name']),
            cantonCode: self::string($row['canton_code']),
            lat: self::string($row['lat']),
            lng: self::string($row['lng']),
            coordinatesFromTown: (bool) $row['coordinates_from_town'],
            stationKm: self::string($row['station_km']),
            stationName: self::string($row['station_name']),
            highwayKm: self::string($row['highway_km']),
            highwayName: self::string($row['highway_name']),
            airportKm: self::string($row['airport_km']),
            airportName: self::string($row['airport_name']),
            phone: self::string($row['phone']),
            inquiryEmail: self::string($row['inquiry_email']),
            acceptsMultiInquiries: (bool) $row['accepts_multi_inquiries'],
            website: self::string($row['website']),
            contactPerson: self::string($row['contact_person']),
            instagram: self::string($row['instagram']),
            daylight: self::bool($row['daylight']),
            priceLevel: null === $row['price_level'] ? null : PriceLevel::from((string) $row['price_level']),
            capacitySeated: self::int($row['capacity_seated']),
            capacityStanding: self::int($row['capacity_standing']),
            translations: self::translations((string) $row['translations']),
            terms: $terms,
            mainTerms: $mainTerms,
            galleryPhotoCount: (int) $row['gallery_photo_count'],
            googlePlaceId: self::string($row['google_place_id']),
            googleRatingHidden: (bool) $row['google_rating_hidden'],
            factsCheckedAt: \is_string($row['facts_checked_at']) ? new \DateTimeImmutable($row['facts_checked_at']) : null,
        );
    }

    /**
     * @return array{array<string, list<string>>, array<string, string>}
     */
    private static function terms(string $json): array
    {
        $terms = array_fill_keys(array_map(static fn (TermGroup $group): string => $group->value, TermGroup::cases()), []);
        $main = [];
        foreach (self::decode($json) as $term) {
            \assert(\is_array($term) && \is_string($term['group']) && \is_string($term['code']) && \is_bool($term['main']));
            $terms[$term['group']][] = $term['code'];
            if ($term['main']) {
                $main[$term['group']] = $term['code'];
            }
        }

        return [$terms, $main];
    }

    /**
     * @return array<string, VenueEditTranslation>
     */
    private static function translations(string $json): array
    {
        $translations = [];
        foreach (self::decode($json) as $row) {
            \assert(\is_array($row));
            $locale = (string) $row['locale'];
            $translations[$locale] = new VenueEditTranslation(
                locale: $locale,
                slug: (string) $row['slug'],
                summary: (string) $row['summary'],
                description: self::string($row['description']),
                metaDescription: self::string($row['meta_description']),
                parkingText: self::string($row['parking_text']),
                region: self::string($row['region']),
                ambience: self::string($row['ambience']),
                capacityNote: self::string($row['capacity_note']),
                openingHours: self::string($row['opening_hours']),
            );
        }

        return $translations;
    }

    /**
     * @return list<mixed>
     */
    private static function decode(string $json): array
    {
        $decoded = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        \assert(\is_array($decoded) && array_is_list($decoded));

        return $decoded;
    }

    private static function string(mixed $value): ?string
    {
        return null === $value ? null : (string) $value;
    }

    private static function int(mixed $value): ?int
    {
        return null === $value ? null : (int) $value;
    }

    private static function bool(mixed $value): ?bool
    {
        return null === $value ? null : (bool) $value;
    }
}
