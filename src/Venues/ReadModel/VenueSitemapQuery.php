<?php

declare(strict_types=1);

namespace App\Venues\ReadModel;

use App\Geo\ValueObject\Canton;
use App\Media\ValueObject\ImageVariants;
use App\Media\ValueObject\Picture;
use App\Shared\Translation\EnabledLocale;
use App\Venues\PublicPhotoPolicy;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

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
                    jsonb_build_object('id', p.id, 'width', p.width, 'height', p.height, 'dominant_color', p.dominant_color, 'variants', p.variants)
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
            'SELECT v.number, t.slug, '.self::LASTMOD.' AS lastmod, ph.photos '.self::published().'
             ORDER BY v.number
             LIMIT :limit OFFSET :offset',
            ['default_locale' => $this->enabledLocale->default(), 'limit' => $perFile, 'offset' => ($file - 1) * $perFile],
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );

        return array_map(function (array $row): SitemapVenue {
            \assert(\is_int($row['number']));
            \assert(\is_string($row['slug']));
            \assert(\is_string($row['lastmod']));
            \assert(\is_string($row['photos']) || null === $row['photos']);

            return new SitemapVenue($row['slug'], new \DateTimeImmutable($row['lastmod']), $this->imageUrls($row['number'], $row['photos']));
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
            'WITH published AS (SELECT v.name, t.slug, v.zip, v.town_id, v.canton_code, v.capacity_max '.self::published().')
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
             GROUP BY vt.term_code",
            ['default_locale' => $this->enabledLocale->default()],
        );

        $groups = ['canton' => [], 'town' => [], 'building' => []];
        foreach ($rows as $row) {
            \assert(\is_string($row['grp']) && isset($groups[$row['grp']]));
            \assert(\is_string($row['key']));
            \assert(\is_string($row['lastmod']));
            $groups[$row['grp']][$row['key']] = new \DateTimeImmutable($row['lastmod']);
        }

        return new SitemapChanges($groups['canton'], $groups['town'], $groups['building']);
    }

    /**
     * @return list<string>
     */
    private function imageUrls(int $number, ?string $photos): array
    {
        if (null === $photos) {
            return [];
        }

        /** @var list<array{id: string, width: int, height: int, dominant_color: string, variants: array<mixed>}> $rows */
        $rows = json_decode($photos, true, 8, \JSON_THROW_ON_ERROR);

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
            $rows,
        );
    }
}
