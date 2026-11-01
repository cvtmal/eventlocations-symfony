<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

use App\Crm\ValueObject\LostReason;
use App\Crm\ValueObject\TaskDueDate;
use App\Venues\ValueObject\Tier;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * The deal list (D12): COUNT, then one page of rows; every filtered row for the CSV export; and the months that hold an
 * expected close, for the filter. Text goes through #6's folding, so `idx_crm_deals_search_key` applies to the name.
 */
final class DealListQuery
{
    public const int PER_PAGE = 50;

    private const string FROM = <<<'SQL'
        FROM crm_deals d
        JOIN crm_companies c ON c.id = d.company_id
        JOIN crm_deal_stages s ON s.code = d.stage_code
        LEFT JOIN admin_users u ON u.id = d.owner_id
        SQL;

    private const string COLUMNS = <<<'SQL'
        SELECT d.id, d.name, d.company_id, c.name AS company_name, d.stage_code, d.tier, d.amount_rappen,
               d.expected_close_on, u.display_name AS owner_name
        SQL;

    private const string CSV_COLUMNS = <<<'SQL'
        SELECT d.name, c.name AS company_name, v.name AS venue_name, d.stage_code, d.lost_reason, d.tier, d.amount_rappen,
               d.contract_start_on, d.contract_end_on, d.expected_close_on, u.display_name AS owner_name
        SQL;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function search(DealFilter $filter, DealSort $sort, SortDirection $direction, int $page): DealListPage
    {
        [$conditions, $parameters] = self::conditions($filter);
        $where = self::where($conditions);

        $total = (int) $this->connection->fetchOne('SELECT COUNT(*) '.self::FROM.$where, $parameters);
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));
        // The URL's page, clamped to what exists: a huge value would overflow the offset into a float.
        $page = min(max(1, $page), $lastPage);

        return new DealListPage(
            $total > 0 ? $this->rows($where, $parameters, $sort, $direction, $page) : [],
            $total,
            $page,
            self::PER_PAGE,
            $lastPage,
        );
    }

    /**
     * Every deal of the filter in the list's order, unpaged. The statement runs when the CSV stream starts reading.
     *
     * @return iterable<DealCsvRow>
     */
    public function csvRows(DealFilter $filter, DealSort $sort, SortDirection $direction): iterable
    {
        [$conditions, $parameters] = self::conditions($filter);
        $rows = $this->connection->iterateAssociative(
            self::CSV_COLUMNS.' '.self::FROM.' LEFT JOIN venues v ON v.id = d.venue_id'.self::where($conditions).' ORDER BY '.self::order($sort, $direction),
            $parameters,
        );

        foreach ($rows as $row) {
            yield new DealCsvRow(
                (string) $row['name'],
                (string) $row['company_name'],
                null === $row['venue_name'] ? null : (string) $row['venue_name'],
                (string) $row['stage_code'],
                null === $row['lost_reason'] ? null : LostReason::from((string) $row['lost_reason']),
                null === $row['tier'] ? null : Tier::from((string) $row['tier']),
                null === $row['amount_rappen'] ? null : (int) $row['amount_rappen'],
                TaskDueDate::parse($row['contract_start_on']),
                TaskDueDate::parse($row['contract_end_on']),
                TaskDueDate::parse($row['expected_close_on']),
                null === $row['owner_name'] ? null : (string) $row['owner_name'],
            );
        }
    }

    /**
     * The months with an expected close, oldest first (`2026-11`).
     *
     * @return list<string>
     */
    public function months(): array
    {
        return array_map(strval(...), $this->connection->fetchFirstColumn(
            "SELECT DISTINCT to_char(expected_close_on, 'YYYY-MM') AS month FROM crm_deals WHERE expected_close_on IS NOT NULL ORDER BY month",
        ));
    }

    /**
     * The global search (D27): the list's search, so a deal is found as it is in the list.
     *
     * @return list<DealSearchHit>
     */
    public function quickSearch(string $q, int $limit): array
    {
        if ('' === trim($q)) {
            return [];
        }
        [$conditions, $parameters] = self::conditions(new DealFilter(q: $q));

        return array_map(
            static fn (array $row): DealSearchHit => new DealSearchHit((string) $row['id'], (string) $row['name'], (string) $row['company_name']),
            $this->connection->fetchAllAssociative(
                'SELECT d.id, d.name, c.name AS company_name '.self::FROM.self::where($conditions).' ORDER BY lower(d.name) ASC, d.id ASC LIMIT :limit',
                $parameters + ['limit' => $limit],
                ['limit' => ParameterType::INTEGER],
            ),
        );
    }

    /**
     * The WHERE parts of a filter over `crm_deals d` and `crm_companies c`, for this list and the board.
     *
     * @return array{list<string>, array<string, mixed>}
     */
    public static function conditions(DealFilter $filter): array
    {
        $conditions = [];
        $parameters = [];

        $q = null === $filter->q ? '' : trim($filter->q);
        if ('' !== $q) {
            // A query that folds to nothing ('!!!') matches nothing, not everything.
            $conditions[] = "(geo_fold_de(:q) <> '' AND ("
                ."geo_search_key(d.name) LIKE '%' || geo_fold_de(:q) || '%' OR geo_search_key(d.name) LIKE '%' || geo_fold(:q) || '%'"
                ." OR geo_search_key(c.name) LIKE '%' || geo_fold_de(:q) || '%' OR geo_search_key(c.name) LIKE '%' || geo_fold(:q) || '%'))";
            $parameters['q'] = $q;
        }
        if (null !== $filter->stage) {
            $conditions[] = 'd.stage_code = :stage';
            $parameters['stage'] = $filter->stage;
        }
        if (CompanyListFilter::OWNER_NONE === $filter->owner) {
            $conditions[] = 'd.owner_id IS NULL';
        } elseif (null !== $filter->owner) {
            $conditions[] = 'd.owner_id = :owner';
            $parameters['owner'] = $filter->owner;
        }
        if (null !== $filter->month) {
            $conditions[] = "to_char(d.expected_close_on, 'YYYY-MM') = :month";
            $parameters['month'] = $filter->month;
        }

        return [$conditions, $parameters];
    }

    /**
     * @param array<string, mixed> $parameters
     *
     * @return list<DealListRow>
     */
    private function rows(string $where, array $parameters, DealSort $sort, SortDirection $direction, int $page): array
    {
        $rows = $this->connection->fetchAllAssociative(
            self::COLUMNS.' '.self::FROM.$where.' ORDER BY '.self::order($sort, $direction).' LIMIT :limit OFFSET :offset',
            $parameters + ['limit' => self::PER_PAGE, 'offset' => ($page - 1) * self::PER_PAGE],
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );

        return array_map(static fn (array $row): DealListRow => new DealListRow(
            (string) $row['id'],
            (string) $row['name'],
            (string) $row['company_id'],
            (string) $row['company_name'],
            (string) $row['stage_code'],
            null === $row['tier'] ? null : Tier::from((string) $row['tier']),
            null === $row['amount_rappen'] ? null : (int) $row['amount_rappen'],
            TaskDueDate::parse($row['expected_close_on']),
            null === $row['owner_name'] ? null : (string) $row['owner_name'],
        ), $rows);
    }

    /**
     * Deals without the column's value go last in both directions.
     */
    private static function order(DealSort $sort, SortDirection $direction): string
    {
        return $sort->column().' '.$direction->sql().' NULLS LAST, lower(d.name) ASC, d.id ASC';
    }

    /**
     * @param list<string> $conditions
     */
    private static function where(array $conditions): string
    {
        return [] === $conditions ? '' : ' WHERE '.implode(' AND ', $conditions);
    }
}
