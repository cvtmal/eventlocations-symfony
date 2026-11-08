<?php

declare(strict_types=1);

namespace App\Venues\ReadModel;

use App\Media\ValueObject\ImageVariants;
use App\Media\ValueObject\Picture;
use App\Shared\Translation\EnabledLocale;
use App\Shared\Translation\TranslationJoin;
use App\Venues\PublicPhotoPolicy;
use Doctrine\DBAL\Connection;

/**
 * The venue a complaint is about (G2, `/inhalt-melden?location=<number>`): only a published one (the predicate of
 * `VenueDetailQuery`), with every photo the public sees (`PublicPhotoPolicy`), gallery and rooms alike, since a
 * rights complaint may name any of them. Two queries.
 */
final class ReportableVenueQuery
{
    /** `venues.number` is an INTEGER: a larger value is no venue, not a database error. */
    private const int MAX_NUMBER = 2147483647;

    /** The same published predicate as `VenueDetailQuery` and the listing. */
    private const string PUBLISHED = "v.status = 'published' AND EXISTS (SELECT 1 FROM venue_translations dt WHERE dt.venue_id = v.id AND dt.locale = :default_locale)";

    public function __construct(
        private readonly Connection $connection,
        private readonly TranslationJoin $translations,
        private readonly EnabledLocale $locales,
    ) {
    }

    /**
     * @return ?ReportableVenue null for an unknown or unpublished venue
     */
    public function find(int $number, string $locale): ?ReportableVenue
    {
        if ($number < 1 || $number > self::MAX_NUMBER) {
            return null;
        }

        $translation = $this->translations->localized('venue_translations', 'venue_id', 'v.id', 't');
        $published = self::PUBLISHED;
        $venue = $this->connection->fetchAssociative(
            <<<SQL
                SELECT v.id, v.number, v.name, t.slug
                FROM venues v
                {$translation}
                WHERE v.number = :number AND {$published} AND t.slug IS NOT NULL
                SQL,
            ['number' => $number, 'default_locale' => $this->locales->default()] + $this->translations->parameters($locale),
        );
        if (false === $venue) {
            return null;
        }

        return new ReportableVenue(
            (string) $venue['id'],
            (int) $venue['number'],
            (string) $venue['name'],
            (string) $venue['slug'],
            $this->photos((string) $venue['id'], (int) $venue['number'], $locale),
        );
    }

    /**
     * @return list<ReportablePhoto>
     */
    private function photos(string $venueId, int $number, string $locale): array
    {
        $roomName = $this->translations->localized('room_translations', 'room_id', 'r.id', 'rt');
        $public = PublicPhotoPolicy::sql('p');
        $rows = $this->connection->fetchAllAssociative(
            <<<SQL
                SELECT p.id, p.position, p.width, p.height, p.dominant_color, p.variants, NULLIF(rt.name, '') AS room_name
                FROM venue_photos p
                LEFT JOIN rooms r ON r.id = p.room_id
                {$roomName}
                WHERE p.venue_id = :venue AND {$public}
                ORDER BY p.room_id IS NOT NULL, r.position, p.position
                SQL,
            ['venue' => $venueId] + $this->translations->parameters($locale),
        );

        return array_map(static fn (array $row): ReportablePhoto => new ReportablePhoto(
            new Picture($number, (string) $row['id'], (int) $row['width'], (int) $row['height'], (string) $row['dominant_color'], ImageVariants::fromJson((string) $row['variants']), ''),
            (int) $row['position'],
            null === $row['room_name'] ? null : (string) $row['room_name'],
        ), $rows);
    }
}
