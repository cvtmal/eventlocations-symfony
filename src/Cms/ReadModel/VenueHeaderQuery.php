<?php

declare(strict_types=1);

namespace App\Cms\ReadModel;

use App\Media\ValueObject\ImageVariants;
use App\Media\ValueObject\Picture;
use App\Shared\Translation\EnabledLocale;
use App\Venues\ValueObject\Tier;
use App\Venues\ValueObject\VenuePlace;
use App\Venues\ValueObject\VenueStatus;
use Doctrine\DBAL\Connection;

/**
 * The record header of a venue in the back office, published or not. One query.
 */
final class VenueHeaderQuery
{
    /** `venues.number` is an INTEGER: a larger value is no venue, not a database error. */
    public const int MAX_NUMBER = 2147483647;

    public function __construct(
        private readonly Connection $connection,
        private readonly EnabledLocale $locales,
    ) {
    }

    public function find(int $number): ?VenueHeader
    {
        if ($number < 1 || $number > self::MAX_NUMBER) {
            return null;
        }

        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT v.id, v.number, v.name, v.tier, v.status, v.street, v.zip, v.content_key, v.cms_edited_at, v.updated_at,
                       v.canton_code, tw.name AS town_name, t.slug, t.region,
                       cover.id AS cover_id, cover.width AS cover_width, cover.height AS cover_height,
                       cover.dominant_color AS cover_color, cover.variants AS cover_variants,
                       editor.created_at AS edited_at, editor.display_name AS editor_name
                FROM venues v
                LEFT JOIN towns tw ON tw.id = v.town_id
                LEFT JOIN venue_translations t ON t.venue_id = v.id AND t.locale = :locale
                LEFT JOIN LATERAL (
                    SELECT p.id, p.width, p.height, p.dominant_color, p.variants
                    FROM venue_photos p
                    WHERE p.venue_id = v.id AND p.room_id IS NULL
                    ORDER BY p.position
                    LIMIT 1
                ) cover ON TRUE
                LEFT JOIN LATERAL (
                    SELECT l.created_at, COALESCE(u.display_name, pu.first_name || ' ' || pu.last_name) AS display_name
                    FROM admin_audit_log l
                    LEFT JOIN admin_users u ON u.id = l.actor_id
                    LEFT JOIN portal_users pu ON pu.id = l.portal_user_id
                    WHERE l.subject_type = 'venue' AND l.subject_id = v.number::text
                    ORDER BY l.created_at DESC, l.id DESC
                    LIMIT 1
                ) editor ON TRUE
                WHERE v.number = :number
                SQL,
            ['number' => $number, 'locale' => $this->locales->default()],
        );

        return false === $row ? null : self::header($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function header(array $row): VenueHeader
    {
        $number = (int) $row['number'];
        $status = VenueStatus::from((string) $row['status']);

        $cover = null;
        if (null !== $row['cover_id']) {
            $cover = new Picture(
                $number,
                (string) $row['cover_id'],
                (int) $row['cover_width'],
                (int) $row['cover_height'],
                (string) $row['cover_color'],
                ImageVariants::fromJson((string) $row['cover_variants']),
                '',
            );
        }

        return new VenueHeader(
            number: $number,
            id: (string) $row['id'],
            name: (string) $row['name'],
            tier: Tier::from((string) $row['tier']),
            status: $status,
            isPublished: VenueStatus::Published === $status,
            place: VenuePlace::of(self::string($row['town_name']), self::string($row['canton_code']), self::string($row['region'])),
            street: self::string($row['street']),
            zip: self::string($row['zip']),
            slug: self::string($row['slug']),
            contentKey: self::string($row['content_key']),
            cmsEditedAt: null === $row['cms_edited_at'] ? null : new \DateTimeImmutable((string) $row['cms_edited_at']),
            updatedAt: new \DateTimeImmutable((string) $row['updated_at']),
            lastEditor: null === $row['edited_at'] ? null : new VenueLastEditor(self::string($row['editor_name']), new \DateTimeImmutable((string) $row['edited_at'])),
            cover: $cover,
        );
    }

    private static function string(mixed $value): ?string
    {
        return null === $value ? null : (string) $value;
    }
}
