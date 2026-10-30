<?php

declare(strict_types=1);

namespace App\Venues\ReadModel;

use App\Media\ValueObject\ImageVariants;
use App\Media\ValueObject\Picture;
use App\Shared\Translation\EnabledLocale;
use App\Shared\Translation\TranslationJoin;
use App\Venues\ValueObject\Access;
use App\Venues\ValueObject\FloorMaterial;
use App\Venues\ValueObject\OwnCatering;
use App\Venues\ValueObject\RatingHighlight;
use App\Venues\ValueObject\RatingLabel;
use App\Venues\ValueObject\ReviewerRole;
use App\Venues\ValueObject\Seating;
use App\Venues\ValueObject\Term;
use App\Venues\ValueObject\TermGroup;
use App\Venues\ValueObject\Tier;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The venue detail page (`venue_show`, #8): which venue a slug points at, everything the page
 * renders, and — separately, for the phone reveal only — the phone number.
 *
 * The page costs four queries: resolveSlug() (1) and detail() (3: the venue with its terms,
 * highlights, documents and reviews as `jsonb_agg` sub-selects; the rooms; all photos).
 */
final class VenueDetailQuery
{
    /**
     * Published = status published and a translation in the default locale. The same
     * predicate as App\Search\ReadModel\ListingSql (Venues may not import Search, S2), so the
     * listing and the detail page agree on which venues exist.
     */
    private const PUBLISHED = "v.status = 'published' AND EXISTS (SELECT 1 FROM venue_translations dt WHERE dt.venue_id = v.id AND dt.locale = :default_locale)";

    /** DP-7: Gebäudeart, Lage, Geeignet für │ Infrastruktur, Mobiliar, Technik, Umgebung. */
    private const FACT_GROUP_ORDER = [
        TermGroup::Building,
        TermGroup::Setting,
        TermGroup::SuitableFor,
        TermGroup::Infrastructure,
        TermGroup::Furniture,
        TermGroup::Technology,
        TermGroup::Surroundings,
    ];

    /** The order of a room's capacity lines (D3, as on the original). */
    private const SEATING_ORDER = [Seating::Banquet, Seating::Seminar, Seating::Concert, Seating::Apero];

    public function __construct(
        private readonly Connection $connection,
        private readonly TranslationJoin $translationJoin,
        private readonly EnabledLocale $enabledLocale,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * A current slug (`venue_translations`) wins over a history slug (`venue_slug_history`).
     * One query.
     */
    public function resolveSlug(string $slug, string $locale): ?SlugMatch
    {
        $current = $this->translationJoin->localized('venue_translations', 'venue_id', 'v.id', 'cur');
        $published = self::PUBLISHED;

        $row = $this->connection->fetchAssociative(
            <<<SQL
                SELECT m.venue_id, v.number, v.name, m.is_current, cur.slug AS current_slug, ({$published}) AS is_published
                FROM (
                    SELECT venue_id, TRUE AS is_current FROM venue_translations WHERE locale = :locale AND slug = :slug
                    UNION ALL
                    SELECT venue_id, FALSE AS is_current FROM venue_slug_history WHERE locale = :locale AND slug = :slug
                ) m
                JOIN venues v ON v.id = m.venue_id
                {$current}
                ORDER BY m.is_current DESC
                LIMIT 1
                SQL,
            ['slug' => $slug, 'locale' => $locale, 'default_locale' => $this->enabledLocale->default()]
                + $this->translationJoin->parameters($locale),
        );
        if (false === $row) {
            return null;
        }

        \assert(\is_string($row['venue_id']));
        \assert(\is_int($row['number']));
        \assert(\is_string($row['name']));
        \assert(\is_bool($row['is_current']));
        \assert(\is_bool($row['is_published']));
        \assert(null === $row['current_slug'] || \is_string($row['current_slug']));

        // A venue without any translation row has no current slug; it is unpublished anyway.
        return new SlugMatch(
            $row['venue_id'],
            $row['number'],
            $row['name'],
            $row['current_slug'] ?? $slug,
            $row['is_current'],
            $row['is_published'] && null !== $row['current_slug'],
        );
    }

    /**
     * The venue with this number (`venues.number`), or null when there is none. By default only
     * a published venue counts; `$includeUnpublished` lets the CMS preview (#24) render a draft
     * with the same page. Either way the venue needs a translation row in the requested or the
     * default locale (slug and summary are part of the page), so a venue without any of the
     * two gives null as well. Three queries (one when the venue is missing).
     */
    public function detail(int $number, string $locale, bool $includeUnpublished = false): ?VenueDetail
    {
        $translation = $this->translationJoin->localized('venue_translations', 'venue_id', 'v.id', 't');
        $documentTranslation = $this->translationJoin->localized('venue_document_translations', 'venue_document_id', 'd.id', 'dtr');
        $published = $includeUnpublished ? 'TRUE' : self::PUBLISHED;
        $params = ['number' => $number] + $this->translationJoin->parameters($locale);

        $row = $this->connection->fetchAssociative(
            <<<SQL
                SELECT v.id, v.number, v.name, v.tier, v.capacity_min, v.capacity_max, v.street, v.zip,
                    tw.name AS town_name, v.lat, v.lng,
                    v.distance_station_km, v.station_name, v.distance_highway_km, v.highway_name,
                    v.distance_airport_km, v.airport_name,
                    v.phone IS NOT NULL AS has_phone, v.inquiry_email, v.website, v.contact_person,
                    v.own_catering, v.daylight, v.rating_score, v.rating_count,
                    t.slug, t.summary, t.description, t.meta_description, t.parking_text,
                    (SELECT COALESCE(jsonb_agg(jsonb_build_object('group', vt.term_group, 'code', vt.term_code, 'main', vt.is_main)
                            ORDER BY tt.position), '[]'::jsonb)
                        FROM venue_terms vt
                        JOIN taxonomy_terms tt ON tt.term_group = vt.term_group AND tt.code = vt.term_code
                        WHERE vt.venue_id = v.id) AS terms,
                    (SELECT COALESCE(jsonb_agg(h.code ORDER BY h.position), '[]'::jsonb)
                        FROM venue_rating_highlights h WHERE h.venue_id = v.id) AS highlights,
                    (SELECT COALESCE(jsonb_agg(jsonb_build_object('id', d.id, 'title', dtr.title) ORDER BY d.position), '[]'::jsonb)
                        FROM venue_documents d
                        {$documentTranslation}
                        WHERE d.venue_id = v.id AND dtr.title IS NOT NULL) AS documents,
                    (SELECT COALESCE(jsonb_agg(jsonb_build_object('first_name', r.first_name, 'role', r.role, 'month', r.reviewed_month, 'text', r.text)
                            ORDER BY r.reviewed_month DESC, r.id), '[]'::jsonb)
                        FROM venue_reviews r WHERE r.venue_id = v.id) AS reviews
                FROM venues v
                JOIN towns tw ON tw.id = v.town_id
                {$translation}
                WHERE v.number = :number AND t.slug IS NOT NULL AND {$published}
                SQL,
            $params + ($includeUnpublished ? [] : ['default_locale' => $this->enabledLocale->default()]),
        );
        if (false === $row) {
            return null;
        }

        \assert(\is_string($row['id']));
        \assert(\is_int($row['number']));
        \assert(\is_string($row['name']));
        \assert(\is_string($row['tier']));
        \assert(\is_string($row['slug']));
        \assert(\is_string($row['summary']));
        \assert(\is_string($row['zip']));
        \assert(\is_string($row['town_name']));
        \assert(\is_int($row['capacity_max']));
        \assert(null === $row['capacity_min'] || \is_int($row['capacity_min']));
        \assert(\is_bool($row['has_phone']));
        \assert(null === $row['daylight'] || \is_bool($row['daylight']));
        \assert(null === $row['rating_count'] || \is_int($row['rating_count']));
        \assert(null === $row['own_catering'] || \is_string($row['own_catering']));
        \assert(\is_string($row['terms']));
        \assert(\is_string($row['highlights']));
        \assert(\is_string($row['documents']));
        \assert(\is_string($row['reviews']));

        $venueId = $row['id'];
        [$mainBuilding, $mainSetting, $factGroups] = $this->terms(self::decode($row['terms']));
        $ratingScore = self::nullableFloat($row['rating_score']);
        $rooms = $this->rooms($venueId, $locale);
        [$venuePhotos, $roomPhotos] = $this->photos($venueId, $row['number'], $row['name'], $rooms, $locale);

        return new VenueDetail(
            $row['id'],
            $row['number'],
            $row['name'],
            Tier::from($row['tier']),
            $row['slug'],
            $row['summary'],
            self::nullableString($row['description']),
            self::nullableString($row['meta_description']),
            self::nullableString($row['street']),
            $row['zip'],
            $row['town_name'],
            self::float($row['lat']),
            self::float($row['lng']),
            $row['capacity_min'],
            $row['capacity_max'],
            $mainBuilding,
            $mainSetting,
            $factGroups,
            $ratingScore,
            null === $ratingScore ? null : $row['rating_count'],
            null === $ratingScore ? null : RatingLabel::fromScore($ratingScore),
            array_map(
                static fn (mixed $code): RatingHighlight => RatingHighlight::from(\is_string($code) ? $code : ''),
                self::decode($row['highlights']),
            ),
            null === $row['own_catering'] ? null : OwnCatering::from($row['own_catering']),
            $row['daylight'],
            self::nullableString($row['parking_text']),
            self::distances($row),
            $row['has_phone'],
            self::nullableString($row['inquiry_email']),
            self::nullableString($row['website']),
            self::nullableString($row['contact_person']),
            self::documents(self::decode($row['documents'])),
            self::reviews(self::decode($row['reviews'])),
            array_map(
                static fn (DetailRoom $room): DetailRoom => isset($roomPhotos[$room->id])
                    ? new DetailRoom($room->id, $room->name, $room->description, $room->capacityMin, $room->capacityMax, $room->areaM2, $room->heightM, $room->floorMaterial, $room->seatings, $roomPhotos[$room->id])
                    : $room,
                $rooms,
            ),
            $venuePhotos,
        );
    }

    /**
     * The E.164 phone number of a published venue, for the phone reveal only. One query.
     */
    public function phone(string $venueId): ?string
    {
        if (!Uuid::isValid($venueId)) {
            return null;
        }
        $published = self::PUBLISHED;

        $phone = $this->connection->fetchOne(
            "SELECT v.phone FROM venues v WHERE v.id = :id AND {$published}",
            ['id' => $venueId, 'default_locale' => $this->enabledLocale->default()],
        );

        return \is_string($phone) ? $phone : null;
    }

    /**
     * @param list<mixed> $terms decoded `{group, code, main}` objects in taxonomy position order
     *
     * @return array{?Term, ?Term, list<DetailFactGroup>}
     */
    private function terms(array $terms): array
    {
        $mainBuilding = null;
        $mainSetting = null;
        $byGroup = [];
        foreach ($terms as $item) {
            \assert(\is_array($item) && \is_string($item['group']) && \is_string($item['code']) && \is_bool($item['main']));
            $term = new Term(TermGroup::from($item['group']), $item['code']);
            $byGroup[$term->group->value][] = $term;
            if ($item['main'] && TermGroup::Building === $term->group) {
                $mainBuilding = $term;
            }
            if ($item['main'] && TermGroup::Setting === $term->group) {
                $mainSetting = $term;
            }
        }

        $groups = [];
        foreach (self::FACT_GROUP_ORDER as $group) {
            if (isset($byGroup[$group->value])) {
                $groups[] = new DetailFactGroup($group, $byGroup[$group->value]);
            }
        }

        return [$mainBuilding, $mainSetting, $groups];
    }

    /**
     * @return list<DetailRoom> without photos; detail() adds them
     */
    private function rooms(string $venueId, string $locale): array
    {
        $translation = $this->translationJoin->localized('room_translations', 'room_id', 'r.id', 'rt');

        $rows = $this->connection->fetchAllAssociative(
            <<<SQL
                SELECT r.id, r.capacity_min, r.capacity_max, r.area_m2, r.height_m, r.floor_material,
                    r.capacity_banquet_min, r.capacity_banquet_max, r.capacity_seminar_min, r.capacity_seminar_max,
                    r.capacity_concert_min, r.capacity_concert_max, r.capacity_apero_min, r.capacity_apero_max,
                    rt.name, rt.description
                FROM rooms r
                {$translation}
                WHERE r.venue_id = :id AND rt.name IS NOT NULL
                ORDER BY r.position
                SQL,
            ['id' => $venueId] + $this->translationJoin->parameters($locale),
        );

        return array_map(static function (array $row): DetailRoom {
            \assert(\is_string($row['id']));
            \assert(\is_string($row['name']));
            \assert(null === $row['floor_material'] || \is_string($row['floor_material']));

            $seatings = [];
            foreach (self::SEATING_ORDER as $seating) {
                $max = self::nullableInt($row['capacity_'.$seating->value.'_max']);
                if (null !== $max) {
                    $seatings[] = new DetailSeating($seating, self::nullableInt($row['capacity_'.$seating->value.'_min']), $max);
                }
            }

            return new DetailRoom(
                $row['id'],
                $row['name'],
                self::nullableString($row['description']),
                self::nullableInt($row['capacity_min']),
                self::nullableInt($row['capacity_max']),
                self::nullableInt($row['area_m2']),
                self::nullableFloat($row['height_m']),
                null === $row['floor_material'] ? null : FloorMaterial::from($row['floor_material']),
                $seatings,
                null,
            );
        }, $rows);
    }

    /**
     * All photos of the venue in one query: the venue-level ones in position order, and the
     * lowest-position photo of each room.
     *
     * @param list<DetailRoom> $rooms
     *
     * @return array{list<Picture>, array<string, Picture>}
     */
    private function photos(string $venueId, int $venueNumber, string $venueName, array $rooms, string $locale): array
    {
        $translation = $this->translationJoin->localized('venue_photo_translations', 'venue_photo_id', 'p.id', 'pt');

        $rows = $this->connection->fetchAllAssociative(
            <<<SQL
                SELECT p.id, p.room_id, p.position, p.width, p.height, p.dominant_color, p.variants, pt.alt
                FROM venue_photos p
                {$translation}
                WHERE p.venue_id = :id
                ORDER BY p.room_id NULLS FIRST, p.position
                SQL,
            ['id' => $venueId] + $this->translationJoin->parameters($locale),
        );

        $roomNames = [];
        foreach ($rooms as $room) {
            $roomNames[$room->id] = $room->name;
        }

        $venuePhotos = [];
        $roomPhotos = [];
        foreach ($rows as $row) {
            \assert(\is_string($row['id']));
            \assert(null === $row['room_id'] || \is_string($row['room_id']));
            \assert(\is_int($row['position']));
            \assert(\is_int($row['width']));
            \assert(\is_int($row['height']));
            \assert(\is_string($row['dominant_color']));
            \assert(\is_string($row['variants']));
            \assert(null === $row['alt'] || \is_string($row['alt']));

            $roomId = $row['room_id'];
            if (null !== $roomId && (isset($roomPhotos[$roomId]) || !isset($roomNames[$roomId]))) {
                continue;
            }
            $alt = $row['alt'] ?? $this->translator->trans(
                'picture.alt_fallback',
                ['name' => null === $roomId ? $venueName : $roomNames[$roomId], 'position' => $row['position']],
                'media',
                $locale,
            );
            $picture = new Picture($venueNumber, $row['id'], $row['width'], $row['height'], $row['dominant_color'], ImageVariants::fromJson($row['variants']), $alt);

            if (null === $roomId) {
                $venuePhotos[] = $picture;
            } else {
                $roomPhotos[$roomId] = $picture;
            }
        }

        return [$venuePhotos, $roomPhotos];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return list<DetailDistance>
     */
    private static function distances(array $row): array
    {
        $distances = [];
        foreach (Access::cases() as $access) {
            $km = self::nullableFloat($row['distance_'.$access->value.'_km']);
            if (null !== $km) {
                $distances[] = new DetailDistance($access, $km, self::nullableString($row[$access->value.'_name']));
            }
        }

        return $distances;
    }

    /**
     * @param list<mixed> $documents
     *
     * @return list<DetailDocument>
     */
    private static function documents(array $documents): array
    {
        return array_map(static function (mixed $item): DetailDocument {
            \assert(\is_array($item) && \is_string($item['id']) && \is_string($item['title']));

            return new DetailDocument($item['id'], $item['title']);
        }, $documents);
    }

    /**
     * @param list<mixed> $reviews
     *
     * @return list<DetailReview>
     */
    private static function reviews(array $reviews): array
    {
        return array_map(static function (mixed $item): DetailReview {
            \assert(\is_array($item) && \is_string($item['first_name']) && \is_string($item['role']) && \is_string($item['month']) && \is_string($item['text']));

            return new DetailReview(
                $item['first_name'],
                ReviewerRole::from($item['role']),
                new \DateTimeImmutable($item['month'], new \DateTimeZone('UTC')),
                $item['text'],
            );
        }, $reviews);
    }

    /**
     * @return list<mixed>
     */
    private static function decode(string $json): array
    {
        $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        \assert(\is_array($decoded) && array_is_list($decoded));

        return $decoded;
    }

    private static function float(mixed $value): float
    {
        \assert(\is_float($value) || \is_int($value) || (\is_string($value) && is_numeric($value)));

        return (float) $value;
    }

    private static function nullableFloat(mixed $value): ?float
    {
        return null === $value ? null : self::float($value);
    }

    private static function nullableInt(mixed $value): ?int
    {
        \assert(null === $value || \is_int($value));

        return $value;
    }

    private static function nullableString(mixed $value): ?string
    {
        \assert(null === $value || \is_string($value));

        return $value;
    }
}
