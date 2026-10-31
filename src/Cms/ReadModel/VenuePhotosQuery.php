<?php

declare(strict_types=1);

namespace App\Cms\ReadModel;

use App\Media\ValueObject\ImageFormat;
use App\Media\ValueObject\ImageVariants;
use App\Media\ValueObject\Picture;
use App\Shared\Translation\TranslationJoin;
use App\Venues\RowTokens;
use Doctrine\DBAL\Connection;

/**
 * The photo manager's state (#25, D12, PF2, PF10): every photo of a venue, published or not, with
 * its alt texts and row token, and the rooms a photo can go to. Two queries.
 */
final class VenuePhotosQuery
{
    public function __construct(
        private readonly Connection $connection,
        private readonly TranslationJoin $translations,
    ) {
    }

    /**
     * @return ?VenuePhotosView null for an unknown venue
     */
    public function forVenue(int $number, string $locale): ?VenuePhotosView
    {
        if ($number < 1 || $number > VenueHeaderQuery::MAX_NUMBER) {
            return null;
        }

        $roomName = $this->translations->localized('room_translations', 'room_id', 'r.id', 'rt');
        $venue = $this->connection->fetchAssociative(
            <<<SQL
                SELECT v.id,
                       (SELECT COALESCE(jsonb_agg(jsonb_build_object('id', r.id, 'name', COALESCE(rt.name, ''), 'position', r.position)
                                                  ORDER BY r.position), '[]')
                        FROM rooms r
                        {$roomName}
                        WHERE r.venue_id = v.id) AS rooms
                FROM venues v
                WHERE v.number = :number
                SQL,
            ['number' => $number] + $this->translations->parameters($locale),
        );
        if (false === $venue) {
            return null;
        }

        $rows = $this->connection->fetchAllAssociative(
            \sprintf(
                <<<'SQL'
                    SELECT p.id, p.room_id, p.position, p.width, p.height, p.dominant_color, p.variants,
                           %s::text AS token,
                           (SELECT COALESCE(jsonb_object_agg(pt.locale, pt.alt), '{}')
                            FROM venue_photo_translations pt WHERE pt.venue_photo_id = p.id) AS alts
                    FROM venue_photos p
                    LEFT JOIN rooms r ON r.id = p.room_id
                    WHERE p.venue_id = :id
                    ORDER BY p.room_id IS NOT NULL, r.position, p.position
                    SQL,
                RowTokens::sql('p.updated_at'),
            ),
            ['id' => $venue['id']],
        );

        return new VenuePhotosView(
            array_map(static fn (array $row): ManagedPhoto => self::photo($number, $row), $rows),
            array_values(array_map(self::room(...), self::decode((string) $venue['rooms']))),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function photo(int $number, array $row): ManagedPhoto
    {
        $variants = ImageVariants::fromJson((string) $row['variants']);
        $picture = new Picture($number, (string) $row['id'], (int) $row['width'], (int) $row['height'], (string) $row['dominant_color'], $variants, '');
        $alts = [];
        foreach (self::decode((string) $row['alts']) as $locale => $alt) {
            $alts[(string) $locale] = (string) $alt;
        }
        ksort($alts);

        return new ManagedPhoto(
            id: $picture->photoId,
            roomId: null === $row['room_id'] ? null : (string) $row['room_id'],
            position: (int) $row['position'],
            // Every photo has a JPEG at every width (S9), so the smallest one is always there.
            thumbnailUrl: $picture->url($variants->smallest(), ImageFormat::Jpeg),
            width: $picture->width,
            height: $picture->height,
            dominantColor: $picture->dominantColor,
            widths: $variants->widths,
            formats: array_map(static fn (ImageFormat $format): string => $format->value, $variants->formats),
            alts: $alts,
            token: (string) $row['token'],
        );
    }

    private static function room(mixed $room): RoomOption
    {
        \assert(\is_array($room));

        return new RoomOption((string) $room['id'], (string) $room['name'], (int) $room['position']);
    }

    /**
     * @return array<mixed>
     */
    private static function decode(string $json): array
    {
        $value = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        \assert(\is_array($value));

        return $value;
    }
}
