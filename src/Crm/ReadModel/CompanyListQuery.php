<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

use App\Crm\ValueObject\CompanyOrigin;
use App\Crm\ValueObject\CompanyStage;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * The company list (CRM-6, D20): COUNT, then one page of rows, so two statements; the quick search
 * behind the company combobox; and every filtered row for the CSV export. Text goes through #6's
 * folding, so `idx_crm_companies_search_key` applies.
 */
final class CompanyListQuery
{
    public const int PER_PAGE = 50;

    private const string FROM = <<<'SQL'
        FROM crm_companies c
        LEFT JOIN admin_users u ON u.id = c.owner_id
        SQL;

    private const string COLUMNS = <<<'SQL'
        SELECT c.id, c.name, c.website, c.stage, u.display_name AS owner_name, c.created_at,
               (SELECT COUNT(*) FROM crm_company_venues cv WHERE cv.company_id = c.id) AS venue_count
        SQL;

    private const string CSV_COLUMNS = <<<'SQL'
        SELECT c.name, c.legal_name, c.website, c.phone, c.email, c.address, c.stage, u.display_name AS owner_name,
               c.origin, c.created_at,
               (SELECT COUNT(*) FROM crm_company_venues cv WHERE cv.company_id = c.id) AS venue_count
        SQL;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function search(CompanyListFilter $filter, CompanySort $sort, SortDirection $direction, int $page): CompanyListPage
    {
        [$conditions, $parameters] = self::conditions($filter);

        $total = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM crm_companies c'.self::where($conditions), $parameters);
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));
        // The URL's page, clamped to what exists: a huge value would overflow the offset into a float.
        $page = min(max(1, $page), $lastPage);

        $rows = $total > 0 ? $this->rows($conditions, $parameters, self::order($sort, $direction), self::PER_PAGE, ($page - 1) * self::PER_PAGE) : [];

        return new CompanyListPage($rows, $total, $page, self::PER_PAGE, $lastPage);
    }

    /**
     * Companies whose name matches, by name: the company combobox and the global search (A6).
     *
     * @return list<CompanyListRow>
     */
    public function quickSearch(string $q, int $limit): array
    {
        if ('' === trim($q)) {
            return [];
        }
        [$conditions, $parameters] = self::conditions(new CompanyListFilter(q: $q));

        return $this->rows($conditions, $parameters, 'lower(c.name) ASC, c.id ASC', $limit);
    }

    /**
     * Every company of the filter in the list's order, unpaged. The statement runs when the CSV stream
     * starts reading.
     *
     * @return iterable<CompanyCsvRow>
     */
    public function csvRows(CompanyListFilter $filter, CompanySort $sort, SortDirection $direction): iterable
    {
        [$conditions, $parameters] = self::conditions($filter);
        $rows = $this->connection->iterateAssociative(
            self::CSV_COLUMNS.' '.self::FROM.self::where($conditions).' ORDER BY '.self::order($sort, $direction),
            $parameters,
        );

        foreach ($rows as $row) {
            yield new CompanyCsvRow(
                (string) $row['name'],
                null === $row['legal_name'] ? null : (string) $row['legal_name'],
                null === $row['website'] ? null : (string) $row['website'],
                null === $row['phone'] ? null : (string) $row['phone'],
                null === $row['email'] ? null : (string) $row['email'],
                null === $row['address'] ? null : (string) $row['address'],
                CompanyStage::from((string) $row['stage']),
                null === $row['owner_name'] ? null : (string) $row['owner_name'],
                (int) $row['venue_count'],
                CompanyOrigin::from((string) $row['origin']),
                new \DateTimeImmutable((string) $row['created_at']),
            );
        }
    }

    /**
     * @param list<string>         $conditions
     * @param array<string, mixed> $parameters
     *
     * @return list<CompanyListRow>
     */
    private function rows(array $conditions, array $parameters, string $order, int $limit, int $offset = 0): array
    {
        $rows = $this->connection->fetchAllAssociative(
            self::COLUMNS.' '.self::FROM.self::where($conditions).' ORDER BY '.$order.' LIMIT :limit OFFSET :offset',
            $parameters + ['limit' => $limit, 'offset' => $offset],
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );

        return array_map(static fn (array $row): CompanyListRow => new CompanyListRow(
            (string) $row['id'],
            (string) $row['name'],
            null === $row['website'] ? null : (string) $row['website'],
            CompanyStage::from((string) $row['stage']),
            null === $row['owner_name'] ? null : (string) $row['owner_name'],
            (int) $row['venue_count'],
            new \DateTimeImmutable((string) $row['created_at']),
        ), $rows);
    }

    private static function order(CompanySort $sort, SortDirection $direction): string
    {
        return $sort->column().' '.$direction->sql().', lower(c.name) ASC, c.id ASC';
    }

    /**
     * @return array{list<string>, array<string, mixed>}
     */
    private static function conditions(CompanyListFilter $filter): array
    {
        $conditions = [];
        $parameters = [];

        $q = null === $filter->q ? '' : trim($filter->q);
        if ('' !== $q) {
            // A query that folds to nothing ('!!!') matches nothing, not everything.
            $conditions[] = "(geo_fold_de(:q) <> '' AND (geo_search_key(c.name) LIKE '%' || geo_fold_de(:q) || '%' OR geo_search_key(c.name) LIKE '%' || geo_fold(:q) || '%'))";
            $parameters['q'] = $q;
        }
        if (null !== $filter->stage) {
            $conditions[] = 'c.stage = :stage';
            $parameters['stage'] = $filter->stage->value;
        }
        if (CompanyListFilter::OWNER_NONE === $filter->owner) {
            $conditions[] = 'c.owner_id IS NULL';
        } elseif (null !== $filter->owner) {
            $conditions[] = 'c.owner_id = :owner';
            $parameters['owner'] = $filter->owner;
        }
        if (null !== $filter->venues) {
            $exists = 'EXISTS (SELECT 1 FROM crm_company_venues cv WHERE cv.company_id = c.id)';
            $conditions[] = CompanyListFilter::VENUES_WITH === $filter->venues ? $exists : 'NOT '.$exists;
        }

        return [$conditions, $parameters];
    }

    /**
     * @param list<string> $conditions
     */
    private static function where(array $conditions): string
    {
        return [] === $conditions ? '' : ' WHERE '.implode(' AND ', $conditions);
    }
}
