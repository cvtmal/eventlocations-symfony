<?php

declare(strict_types=1);

namespace App\Cms\ReadModel;

use App\Venues\RowTokens;
use App\Venues\ValueObject\Seating;
use Doctrine\DBAL\Connection;

/**
 * The room editor's state (#25, CMS-5, D23, PF10): every room of a venue by position, with its texts
 * in every locale, its seating ranges and its row token. Two queries.
 */
final class VenueRoomsQuery
{
    public function __construct(
        private readonly Connection $connection,
        private readonly VenueIdQuery $venues,
    ) {
    }

    /**
     * @return ?list<ManagedRoom> null for an unknown venue
     */
    public function forVenue(int $number): ?array
    {
        $venueId = $this->venues->byNumber($number);
        if (null === $venueId) {
            return null;
        }

        $seating = implode(', ', array_map(
            static fn (Seating $type): string => \sprintf('r.capacity_%1$s_min, r.capacity_%1$s_max', $type->value),
            Seating::cases(),
        ));
        $rows = $this->connection->fetchAllAssociative(
            \sprintf(
                <<<'SQL'
                    SELECT r.id, r.position, r.capacity_min, r.capacity_max, r.area_m2, r.height_m::text AS height_m,
                           r.floor_material, %s, r.content_key IS NOT NULL AS from_content, %s::text AS token,
                           (SELECT COUNT(*) FROM venue_photos p WHERE p.room_id = r.id) AS photo_count,
                           (SELECT COALESCE(jsonb_agg(jsonb_build_object('locale', t.locale, 'name', t.name, 'description', t.description)
                                                      ORDER BY t.locale), '[]')
                            FROM room_translations t WHERE t.room_id = r.id) AS translations
                    FROM rooms r
                    WHERE r.venue_id = :id
                    ORDER BY r.position
                    SQL,
                $seating,
                RowTokens::sql('r.updated_at'),
            ),
            ['id' => $venueId],
        );

        return array_map(self::room(...), $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function room(array $row): ManagedRoom
    {
        $names = [];
        $descriptions = [];
        $translations = json_decode((string) $row['translations'], true, flags: \JSON_THROW_ON_ERROR);
        \assert(\is_array($translations));
        foreach ($translations as $translation) {
            \assert(\is_array($translation));
            $locale = (string) $translation['locale'];
            $names[$locale] = (string) $translation['name'];
            $descriptions[$locale] = null === $translation['description'] ? null : (string) $translation['description'];
        }

        $seating = [];
        foreach (Seating::cases() as $type) {
            $seating[$type->value] = [
                'min' => self::int($row['capacity_'.$type->value.'_min']),
                'max' => self::int($row['capacity_'.$type->value.'_max']),
            ];
        }

        return new ManagedRoom(
            id: (string) $row['id'],
            position: (int) $row['position'],
            names: $names,
            descriptions: $descriptions,
            capacityMin: self::int($row['capacity_min']),
            capacityMax: self::int($row['capacity_max']),
            areaM2: self::int($row['area_m2']),
            heightM: null === $row['height_m'] ? null : (string) $row['height_m'],
            floorMaterial: null === $row['floor_material'] ? null : (string) $row['floor_material'],
            seating: $seating,
            fromContent: (bool) $row['from_content'],
            photoCount: (int) $row['photo_count'],
            token: (string) $row['token'],
        );
    }

    private static function int(mixed $value): ?int
    {
        return null === $value ? null : (int) $value;
    }
}
