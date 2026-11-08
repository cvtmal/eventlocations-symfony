<?php

declare(strict_types=1);

namespace App\Venues\ReadModel;

use App\Geo\ValueObject\Canton;
use App\Media\ValueObject\ImageVariants;
use App\Media\ValueObject\Picture;
use App\Shared\Translation\EnabledLocale;
use App\Venues\EntitlementResolver;
use App\Venues\PublicPhotoPolicy;
use App\Venues\ValueObject\Entitlements;
use App\Venues\ValueObject\PhotoBudget;
use App\Venues\VenueCapacity;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Clock\ClockInterface;

/**
 * The published venues of the sitemap and of `/llms.txt`, in the default locale. `lastmod` is the newest of the
 * venue, its translation and its photos, because a text edit does not touch `venues.updated_at`
 * and Google trusts `lastmod` only when it is accurate. It lives here rather than in Seo so that
 * Picture stays the one owner of the path format. It never joins `towns`, which are optional
 * data (#21).
 */
final class VenueSitemapQuery
{
    public const int PER_FILE = 1000;

    /** `{PHOTO_GRANTED}` is `PublicPhotoPolicy::sql('p')`: only photos with a live rights grant reach the sitemap. */
    private const string PUBLISHED_TEMPLATE = <<<'SQL'
        FROM venues v
        JOIN venue_translations t ON t.venue_id = v.id AND t.locale = :default_locale
        LEFT JOIN LATERAL (
            SELECT max(p.updated_at) AS newest,
                jsonb_agg(
                    jsonb_build_object('id', p.id, 'room_id', p.room_id, 'width', p.width, 'height', p.height, 'dominant_color', p.dominant_color, 'variants', p.variants)
                    ORDER BY p.room_id NULLS FIRST, p.position
                ) AS photos
            FROM venue_photos p
            WHERE p.venue_id = v.id AND {PHOTO_GRANTED}
        ) ph ON TRUE
        WHERE v.status = 'published'
        SQL;

    private const string LASTMOD = 'GREATEST(v.updated_at, t.updated_at, ph.newest)';

    public function __construct(
        private readonly Connection $connection,
        private readonly EnabledLocale $enabledLocale,
        private readonly EntitlementResolver $entitlements,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * The shared FROM and WHERE, with the photo predicate of `PublicPhotoPolicy`.
     */
    private static function published(): string
    {
        return str_replace('{PHOTO_GRANTED}', PublicPhotoPolicy::sql('p'), self::PUBLISHED_TEMPLATE);
    }

    /**
     * @return list<SitemapFile>
     */
    public function files(int $perFile = self::PER_FILE): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'WITH numbered AS (SELECT row_number() OVER (ORDER BY v.number) AS row_no, '.self::LASTMOD.' AS lastmod '.self::published().')
             SELECT (row_no - 1) / :per_file + 1 AS file, max(lastmod) AS lastmod
             FROM numbered
             GROUP BY file
             ORDER BY file',
            ['default_locale' => $this->enabledLocale->default(), 'per_file' => $perFile],
            ['per_file' => ParameterType::INTEGER],
        );

        return array_map(static function (array $row): SitemapFile {
            \assert(\is_int($row['file']));
            \assert(\is_string($row['lastmod']));

            return new SitemapFile($row['file'], new \DateTimeImmutable($row['lastmod']));
        }, $rows);
    }

    /**
     * @return list<SitemapVenue>
     */
    public function page(int $file, int $perFile = self::PER_FILE): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT v.id, v.number, t.slug, '.self::LASTMOD.' AS lastmod, ph.photos,
                    (SELECT jsonb_agg(r.id ORDER BY r.position) FROM rooms r WHERE r.venue_id = v.id) AS room_ids '.self::published().'
             ORDER BY v.number
             LIMIT :limit OFFSET :offset',
            ['default_locale' => $this->enabledLocale->default(), 'limit' => $perFile, 'offset' => ($file - 1) * $perFile],
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );

        // Only the photos the venue's plan shows are advertised (F12), by the page's own rule (PhotoBudget).
        $ids = [];
        foreach ($rows as $row) {
            \assert(\is_string($row['id']));
            $ids[] = $row['id'];
        }
        $entitlements = $this->entitlements->forVenues($ids, $this->clock->now());

        return array_map(function (array $row) use ($entitlements): SitemapVenue {
            \assert(\is_int($row['number']));
            \assert(\is_string($row['slug']));
            \assert(\is_string($row['lastmod']));
            \assert(\is_string($row['photos']) || null === $row['photos']);
            \assert(\is_string($row['room_ids']) || null === $row['room_ids']);

            return new SitemapVenue($row['slug'], new \DateTimeImmutable($row['lastmod']), $this->imageUrls($row['number'], $row['photos'], $row['room_ids'], $entitlements[$row['id']]));
        }, $rows);
    }

    /**
     * Every published venue for `/llms.txt`, on the sitemap's own definition of published, so the
     * two lists cannot drift apart. `PUBLISHED` ends in its WHERE, so the venues are a CTE and the
     * optional town joins it. Sorted by name with the default locale's collation.
     *
     * @return list<IndexedVenue>
     */
    public function index(): array
    {
        $defaultLocale = $this->enabledLocale->default();
        $rows = $this->connection->fetchAllAssociative(
            'WITH published AS (SELECT v.name, t.slug, v.zip, v.town_id, v.canton_code, '.VenueCapacity::maxSql('v').' AS capacity_max '.self::published().')
             SELECT p.name, p.slug, p.zip, tw.name AS town_name, p.canton_code, p.capacity_max
             FROM published p
             LEFT JOIN towns tw ON tw.id = p.town_id',
            ['default_locale' => $defaultLocale],
        );

        $venues = array_map(static function (array $row): IndexedVenue {
            \assert(\is_string($row['name']));
            \assert(\is_string($row['slug']));
            \assert(null === $row['zip'] || \is_string($row['zip']));
            \assert(null === $row['town_name'] || \is_string($row['town_name']));
            \assert(null === $row['canton_code'] || \is_string($row['canton_code']));
            \assert(\is_int($row['capacity_max']));

            return new IndexedVenue(
                $row['slug'],
                $row['name'],
                $row['zip'],
                $row['town_name'],
                null === $row['canton_code'] ? null : Canton::from($row['canton_code']),
                $row['capacity_max'],
            );
        }, $rows);

        $collator = new \Collator($defaultLocale);
        usort($venues, static fn (IndexedVenue $a, IndexedVenue $b): int => (int) $collator->compare($a->name, $b->name) ?: strcmp($a->slug, $b->slug));

        return $venues;
    }

    public function newestChange(): ?\DateTimeImmutable
    {
        $newest = $this->connection->fetchOne(
            'SELECT max('.self::LASTMOD.') '.self::published(),
            ['default_locale' => $this->enabledLocale->default()],
        );

        return \is_string($newest) ? new \DateTimeImmutable($newest) : null;
    }

    /**
     * One statement for every landing page's `lastmod` (#17). `PUBLISHED` ends in its WHERE, so the
     * venues are a CTE and the building terms join it.
     */
    public function newestChangeByGroup(): SitemapChanges
    {
        $rows = $this->connection->fetchAllAssociative(
            'WITH changed AS (SELECT v.id, v.canton_code, v.town_id, '.self::LASTMOD.' AS lastmod '.self::published().")
             SELECT 'canton' AS grp, canton_code AS key, max(lastmod) AS lastmod FROM changed WHERE canton_code IS NOT NULL GROUP BY canton_code
             UNION ALL
             SELECT 'town', town_id::text, max(lastmod) FROM changed WHERE town_id IS NOT NULL GROUP BY town_id
             UNION ALL
             SELECT 'building', vt.term_code, max(c.lastmod) FROM changed c
             JOIN venue_terms vt ON vt.venue_id = c.id AND vt.term_group = 'building'
             GROUP BY vt.term_code
             UNION ALL
             SELECT 'event', vt.term_code, max(c.lastmod) FROM changed c
             JOIN venue_terms vt ON vt.venue_id = c.id AND vt.term_group = 'suitable_for'
             GROUP BY vt.term_code
             UNION ALL
             SELECT 'event_canton', vt.term_code || '.' || c.canton_code, max(c.lastmod) FROM changed c
             JOIN venue_terms vt ON vt.venue_id = c.id AND vt.term_group = 'suitable_for'
             WHERE c.canton_code IS NOT NULL
             GROUP BY vt.term_code, c.canton_code",
            ['default_locale' => $this->enabledLocale->default()],
        );

        $groups = ['canton' => [], 'town' => [], 'building' => [], 'event' => [], 'event_canton' => []];
        foreach ($rows as $row) {
            \assert(\is_string($row['grp']) && isset($groups[$row['grp']]));
            \assert(\is_string($row['key']));
            \assert(\is_string($row['lastmod']));
            $groups[$row['grp']][$row['key']] = new \DateTimeImmutable($row['lastmod']);
        }

        return new SitemapChanges($groups['canton'], $groups['town'], $groups['building'], $groups['event'], $groups['event_canton']);
    }

    /**
     * @param ?string $photos  JSON of every photo, gallery first by position, then room photos
     * @param ?string $roomIds JSON of the room ids in position order
     *
     * @return list<string>
     */
    private function imageUrls(int $number, ?string $photos, ?string $roomIds, Entitlements $entitlements): array
    {
        if (null === $photos) {
            return [];
        }

        /** @var list<array{id: string, room_id: ?string, width: int, height: int, dominant_color: string, variants: array<mixed>}> $rows */
        $rows = json_decode($photos, true, 8, \JSON_THROW_ON_ERROR);
        /** @var list<string> $rooms */
        $rooms = null === $roomIds ? [] : json_decode($roomIds, true, 8, \JSON_THROW_ON_ERROR);

        $gallery = [];
        $roomFirst = [];
        foreach ($rows as $photo) {
            if (null === $photo['room_id']) {
                $gallery[] = $photo;
            } elseif (!isset($roomFirst[$photo['room_id']])) {
                $roomFirst[$photo['room_id']] = $photo;
            }
        }
        [$publicGallery, $publicRoomPhotos] = PhotoBudget::cut($gallery, $rooms, $roomFirst, $entitlements->photosMax, $entitlements->roomsMax);

        return array_map(
            static fn (array $photo): string => new Picture(
                $number,
                $photo['id'],
                $photo['width'],
                $photo['height'],
                $photo['dominant_color'],
                ImageVariants::fromArray($photo['variants']),
                '',
            )->shareImage()->url,
            [...$publicGallery, ...array_values($publicRoomPhotos)],
        );
    }
}
