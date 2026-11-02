<?php

declare(strict_types=1);

namespace App\Cms\ReadModel;

use App\Media\ValueObject\ImageVariants;
use App\Media\ValueObject\Picture;
use App\Shared\Translation\EnabledLocale;
use App\Venues\ValueObject\Tier;
use App\Venues\ValueObject\VenuePlace;
use App\Venues\ValueObject\VenueStatus;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * The back office's venue lists (CMS-1, D6, D25): the paged list with its status counts, the dashboard's
 * incomplete and recently edited venues, and the global search. Every venue, published or not.
 *
 * Measured on DemoStory ×10 (600 venues, 11,000 audit rows): the heaviest page about 17 ms, the counts
 * under 7 ms, without an index of #24's (Probe 9).
 */
final class VenueListQuery
{
    public const int PER_PAGE = 50;

    /** "Unvollständig" in CMS-1's order; the keys are `VenueListRow::MISSING_*`. */
    private const array MISSING = [
        VenueListRow::MISSING_PHOTO => 'NOT EXISTS (SELECT 1 FROM venue_photos p WHERE p.venue_id = v.id AND p.room_id IS NULL)',
        VenueListRow::MISSING_INQUIRY_EMAIL => 'v.inquiry_email IS NULL',
        VenueListRow::MISSING_SUMMARY => "(t.summary IS NULL OR btrim(t.summary) = '')",
        VenueListRow::MISSING_MAIN_BUILDING => "NOT EXISTS (SELECT 1 FROM venue_terms vt WHERE vt.venue_id = v.id AND vt.term_group = 'building' AND vt.is_main)",
    ];

    /** `default_locale` is bound by every statement. */
    private const string FROM = <<<'SQL'
        FROM venues v
        LEFT JOIN towns tw ON tw.id = v.town_id
        LEFT JOIN venue_translations t ON t.venue_id = v.id AND t.locale = :default_locale
        SQL;

    private const string COLUMNS = <<<'SQL'
        SELECT v.number, v.name, t.slug, tw.name AS town_name, v.canton_code, t.region, v.tier, v.capacity_min, v.capacity_max,
               v.status, v.cms_edited_at, v.updated_at, v.content_key,
               (SELECT COUNT(*) FROM venue_photos p WHERE p.venue_id = v.id AND p.room_id IS NULL) AS photo_count,
               thumb.id AS thumb_id, thumb.width AS thumb_width, thumb.height AS thumb_height,
               thumb.dominant_color AS thumb_color, thumb.variants AS thumb_variants,
               editor.display_name AS editor_name
        SQL;

    private const string ROW_JOINS = <<<'SQL'
        LEFT JOIN LATERAL (
            SELECT p.id, p.width, p.height, p.dominant_color, p.variants
            FROM venue_photos p
            WHERE p.venue_id = v.id AND p.room_id IS NULL
            ORDER BY p.position
            LIMIT 1
        ) thumb ON TRUE
        LEFT JOIN LATERAL (
            SELECT COALESCE(u.display_name, pu.first_name || ' ' || pu.last_name) AS display_name
            FROM admin_audit_log l
            LEFT JOIN admin_users u ON u.id = l.actor_id
            LEFT JOIN portal_users pu ON pu.id = l.portal_user_id
            WHERE l.subject_type = 'venue' AND l.subject_id = v.number::text
            ORDER BY l.created_at DESC, l.id DESC
            LIMIT 1
        ) editor ON TRUE
        SQL;

    /** `venues.number` is an INTEGER; a longer run of digits can only be a ZIP prefix, which it is not either. */
    private const int MAX_NUMBER_DIGITS = 9;

    public function __construct(
        private readonly Connection $connection,
        private readonly EnabledLocale $locales,
    ) {
    }

    public function search(VenueListFilter $filter, VenueSort $sort, SortDirection $direction, int $page): VenueListPage
    {
        [$conditions, $parameters, $types] = $this->conditions($filter->withStatus(null));

        $countColumns = ['COUNT(*) AS all_venues', "COUNT(*) FILTER (WHERE v.status = 'published') AS published", "COUNT(*) FILTER (WHERE v.status = 'unpublished') AS unpublished", 'COUNT(*) FILTER (WHERE '.self::incompleteSql().') AS incomplete'];
        $counts = $this->connection->fetchAssociative('SELECT '.implode(', ', $countColumns).' '.self::FROM.self::where($conditions), $parameters, $types);
        \assert(false !== $counts);
        $counts = new VenueStatusCounts((int) $counts['all_venues'], (int) $counts['published'], (int) $counts['unpublished'], (int) $counts['incomplete']);
        $total = $counts->forStatus($filter->status);

        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));
        // The URL's page, clamped to what exists: a huge value would overflow the offset into a float.
        $page = min(max(1, $page), $lastPage);

        if (null !== $filter->status) {
            $conditions[] = self::statusCondition($filter->status);
        }
        $order = $sort->column().' '.$direction->sql().', v.number ASC';
        $rows = $total > 0 ? $this->rows($conditions, $parameters, $types, $order, self::PER_PAGE, ($page - 1) * self::PER_PAGE) : [];

        return new VenueListPage($rows, $total, $page, self::PER_PAGE, $lastPage, $counts);
    }

    /**
     * The given venues, by name. Empty input runs no statement.
     *
     * @param list<int> $numbers
     *
     * @return list<VenueListRow>
     */
    public function byNumbers(array $numbers): array
    {
        if ([] === $numbers) {
            return [];
        }

        return $this->rows(
            ['v.number IN (:numbers)'],
            ['numbers' => $numbers, 'default_locale' => $this->locales->default()],
            ['numbers' => ArrayParameterType::INTEGER],
            'v.name ASC, v.number ASC',
            \count($numbers),
        );
    }

    public function incomplete(int $limit): VenueIncompleteList
    {
        $parameters = ['default_locale' => $this->locales->default()];
        $total = (int) $this->connection->fetchOne('SELECT COUNT(*) '.self::FROM.' WHERE '.self::incompleteSql(), $parameters);

        return new VenueIncompleteList(
            $total > 0 ? $this->rows([self::incompleteSql()], $parameters, [], 'v.name ASC, v.number ASC', $limit) : [],
            $total,
        );
    }

    /**
     * The venues staff changed since their last applied import, newest change first.
     *
     * @return list<VenueListRow>
     */
    public function recentlyEdited(int $limit): array
    {
        return $this->rows(['v.cms_edited_at IS NOT NULL'], ['default_locale' => $this->locales->default()], [], 'v.cms_edited_at DESC, v.number DESC', $limit);
    }

    /**
     * The global search's venue group (#23's `SearchProvider`): the list's search, by name.
     *
     * @return list<VenueListRow>
     */
    public function quickSearch(string $q, int $limit): array
    {
        if ('' === trim($q)) {
            return [];
        }
        [$conditions, $parameters, $types] = $this->conditions(new VenueListFilter(q: $q));

        return $this->rows($conditions, $parameters, $types, 'v.name ASC, v.number ASC', $limit);
    }

    /**
     * @param list<string>                                    $conditions
     * @param array<string, mixed>                            $parameters
     * @param array<string, ParameterType|ArrayParameterType> $types
     *
     * @return list<VenueListRow>
     */
    private function rows(array $conditions, array $parameters, array $types, string $order, int $limit, int $offset = 0): array
    {
        $missing = [];
        foreach (self::MISSING as $code => $sql) {
            $missing[] = $sql.' AS missing_'.$code;
        }

        $rows = $this->connection->fetchAllAssociative(
            self::COLUMNS.', '.implode(', ', $missing).' '.self::FROM.' '.self::ROW_JOINS.self::where($conditions)
                .' ORDER BY '.$order.' LIMIT :limit OFFSET :offset',
            $parameters + ['limit' => $limit, 'offset' => $offset],
            $types + ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );

        return array_map(self::row(...), $rows);
    }

    /**
     * @return array{list<string>, array<string, mixed>, array<string, ParameterType>}
     */
    private function conditions(VenueListFilter $filter): array
    {
        $conditions = [];
        $parameters = ['default_locale' => $this->locales->default()];
        $types = [];

        $q = null === $filter->q ? '' : trim($filter->q);
        if (1 === preg_match('/\A\d+\z/', $q)) {
            $byNumber = \strlen($q) <= self::MAX_NUMBER_DIGITS;
            $conditions[] = $byNumber ? '(v.number = :q_number OR v.zip LIKE :q_zip)' : 'v.zip LIKE :q_zip';
            $parameters['q_zip'] = $q.'%';
            if ($byNumber) {
                $parameters['q_number'] = (int) $q;
                $types['q_number'] = ParameterType::INTEGER;
            }
        } elseif ('' !== $q) {
            // #6's folding (`ListingSql`), so `idx_venues_search_key` and `idx_towns_search_key` apply. A query
            // that folds to nothing ('!!!') matches nothing, not everything.
            $conditions[] = "(geo_fold_de(:q) <> '' AND ("
                ."geo_search_key(v.name) LIKE '%' || geo_fold_de(:q) || '%' OR geo_search_key(v.name) LIKE '%' || geo_fold(:q) || '%'"
                ." OR geo_search_key(tw.name) LIKE '%' || geo_fold_de(:q) || '%' OR geo_search_key(tw.name) LIKE '%' || geo_fold(:q) || '%'))";
            $parameters['q'] = $q;
        }

        if (null !== $filter->status) {
            $conditions[] = self::statusCondition($filter->status);
        }
        if (null !== $filter->tier) {
            $conditions[] = 'v.tier = :tier';
            $parameters['tier'] = $filter->tier->value;
        }
        if (VenueListFilter::CANTON_NONE === $filter->canton) {
            $conditions[] = 'v.canton_code IS NULL';
        } elseif (null !== $filter->canton) {
            $conditions[] = 'v.canton_code = :canton';
            $parameters['canton'] = $filter->canton;
        }
        if ($filter->withoutAddress) {
            $conditions[] = 'v.town_id IS NULL';
        }

        return [$conditions, $parameters, $types];
    }

    private static function statusCondition(string $status): string
    {
        return match ($status) {
            VenueListFilter::STATUS_PUBLISHED => "v.status = 'published'",
            VenueListFilter::STATUS_UNPUBLISHED => "v.status = 'unpublished'",
            default => self::incompleteSql(),
        };
    }

    private static function incompleteSql(): string
    {
        return '('.implode(' OR ', self::MISSING).')';
    }

    /**
     * @param list<string> $conditions
     */
    private static function where(array $conditions): string
    {
        return [] === $conditions ? '' : ' WHERE '.implode(' AND ', $conditions);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function row(array $row): VenueListRow
    {
        $number = (int) $row['number'];

        $thumbnail = null;
        if (null !== $row['thumb_id']) {
            $thumbnail = new Picture(
                $number,
                (string) $row['thumb_id'],
                (int) $row['thumb_width'],
                (int) $row['thumb_height'],
                (string) $row['thumb_color'],
                ImageVariants::fromJson((string) $row['thumb_variants']),
                '',
            );
        }

        $missing = [];
        foreach (array_keys(self::MISSING) as $code) {
            if ((bool) $row['missing_'.$code]) {
                $missing[] = $code;
            }
        }

        return new VenueListRow(
            number: $number,
            name: (string) $row['name'],
            slug: null === $row['slug'] ? null : (string) $row['slug'],
            place: VenuePlace::of(
                null === $row['town_name'] ? null : (string) $row['town_name'],
                null === $row['canton_code'] ? null : (string) $row['canton_code'],
                null === $row['region'] ? null : (string) $row['region'],
            ),
            tier: Tier::from((string) $row['tier']),
            capacityMin: null === $row['capacity_min'] ? null : (int) $row['capacity_min'],
            capacityMax: (int) $row['capacity_max'],
            photoCount: (int) $row['photo_count'],
            status: VenueStatus::from((string) $row['status']),
            thumbnail: $thumbnail,
            cmsEditedAt: null === $row['cms_edited_at'] ? null : new \DateTimeImmutable((string) $row['cms_edited_at']),
            updatedAt: new \DateTimeImmutable((string) $row['updated_at']),
            contentKey: null === $row['content_key'] ? null : (string) $row['content_key'],
            editorName: null === $row['editor_name'] ? null : (string) $row['editor_name'],
            missing: $missing,
        );
    }
}
