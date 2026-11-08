<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

use App\Crm\ValueObject\LostReason;
use App\Venues\ValueObject\TierLabel;
use Doctrine\DBAL\Connection;

/**
 * The board in two statements (D16). Won and lost grow without end, so a column shows at most 100 open or the 20 newest
 * closed cards, and a second statement holds what the heads say: count and sum of the whole filter. `SUM(bigint)` is
 * `numeric` in Postgres, so the cast keeps it an integer (D6).
 */
final class DealBoardQuery
{
    public const int OPEN_CAP = 100;
    public const int CLOSED_CAP = 20;

    private const string ZONE = 'Europe/Zurich';

    private const string CARDS = <<<'SQL'
        SELECT id, name, company_name, stage_code, tier_code, tier_name, tier_rank_weight, amount_rappen, expected_close_on,
               closed_at, lost_reason, owner_name
        FROM (
            SELECT d.id, d.name, c.name AS company_name, d.stage_code,
                   dt.code AS tier_code, dt.name AS tier_name, dt.rank_weight AS tier_rank_weight, d.amount_rappen,
                   d.expected_close_on, d.closed_at, d.lost_reason, u.display_name AS owner_name,
                   (s.is_won OR s.is_lost) AS closed,
                   row_number() OVER (
                       PARTITION BY d.stage_code
                       ORDER BY CASE WHEN s.is_won OR s.is_lost THEN d.closed_at END DESC NULLS LAST,
                                d.expected_close_on ASC NULLS LAST, lower(d.name) ASC, d.id ASC
                   ) AS rank
            FROM crm_deals d
            JOIN crm_companies c ON c.id = d.company_id
            JOIN crm_deal_stages s ON s.code = d.stage_code
            LEFT JOIN admin_users u ON u.id = d.owner_id
            LEFT JOIN tiers dt ON dt.id = d.tier_id
            %s
        ) ranked
        WHERE rank <= CASE WHEN closed THEN %d ELSE %d END
        ORDER BY rank
        SQL;

    private const string TOTALS = <<<'SQL'
        SELECT d.stage_code, COUNT(*) AS deals, COALESCE(SUM(d.amount_rappen), 0)::bigint AS sum_rappen
        FROM crm_deals d
        %s
        GROUP BY d.stage_code
        SQL;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @param list<DealStageView> $stages the columns, in their order
     */
    public function board(array $stages, DealFilter $filter): DealBoard
    {
        [$conditions, $parameters] = DealListQuery::conditions($filter);
        $where = [] === $conditions ? '' : 'WHERE '.implode(' AND ', $conditions);

        $cards = $this->cards($where, $parameters);
        $totals = $this->connection->fetchAllAssociativeIndexed(\sprintf(self::TOTALS, $where), $parameters);

        $columns = [];
        $openCount = 0;
        $openSum = 0;
        foreach ($stages as $stage) {
            $count = (int) ($totals[$stage->code]['deals'] ?? 0);
            $sum = (int) ($totals[$stage->code]['sum_rappen'] ?? 0);
            $columns[] = new DealColumn($stage, $count, $sum, $cards[$stage->code] ?? []);
            if (!$stage->isClosed()) {
                $openCount += $count;
                $openSum += $sum;
            }
        }

        return new DealBoard($columns, $openCount, $openSum);
    }

    /**
     * @param array<string, mixed> $parameters
     *
     * @return array<string, list<DealBoardCard>> by stage code
     */
    private function cards(string $where, array $parameters): array
    {
        $rows = $this->connection->fetchAllAssociative(\sprintf(self::CARDS, $where, self::CLOSED_CAP, self::OPEN_CAP), $parameters);

        $zone = new \DateTimeZone(self::ZONE);
        $cards = [];
        foreach ($rows as $row) {
            $cards[(string) $row['stage_code']][] = new DealBoardCard(
                (string) $row['id'],
                (string) $row['name'],
                (string) $row['company_name'],
                TierLabel::tryFromRow($row),
                null === $row['amount_rappen'] ? null : (int) $row['amount_rappen'],
                null === $row['expected_close_on'] ? null : (string) $row['expected_close_on'],
                null === $row['closed_at'] ? null : new \DateTimeImmutable((string) $row['closed_at'])->setTimezone($zone)->format('Y-m-d'),
                null === $row['lost_reason'] ? null : LostReason::from((string) $row['lost_reason']),
                null === $row['owner_name'] ? null : (string) $row['owner_name'],
            );
        }

        return $cards;
    }
}
