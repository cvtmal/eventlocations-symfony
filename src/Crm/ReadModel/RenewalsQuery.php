<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

use App\Crm\ValueObject\RenewalWindow;
use App\Venues\ValueObject\TierLabel;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Clock\ClockInterface;

/**
 * The renewals due (CRM-8, D22): won deals, not renewed yet, whose contract ends inside `RenewalWindow`. An open or lost
 * deal has no running contract, and an ended one leaves the widget. One statement each.
 */
final class RenewalsQuery
{
    private const string SCOPE = <<<'SQL'
        FROM crm_deals d
        JOIN crm_deal_stages s ON s.code = d.stage_code
        LEFT JOIN tiers dt ON dt.id = d.tier_id
        WHERE s.is_won
          AND d.contract_end_on BETWEEN :first AND :last
          AND NOT EXISTS (SELECT 1 FROM crm_deals r WHERE r.renewed_from_id = d.id)
        SQL;

    public function __construct(
        private readonly Connection $connection,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * The soonest ends, with the total inside the window.
     */
    public function upcoming(int $limit): RenewalPreview
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT d.id, dt.code AS tier_code, dt.name AS tier_name, dt.rank_weight AS tier_rank_weight, d.contract_end_on, d.amount_rappen,'
            .' (SELECT c.name FROM crm_companies c WHERE c.id = d.company_id) AS company_name, COUNT(*) OVER () AS total '
            .self::SCOPE
            .' ORDER BY d.contract_end_on, d.id LIMIT :limit',
            $this->window() + ['limit' => $limit],
            ['limit' => ParameterType::INTEGER],
        );

        return new RenewalPreview(array_map(self::row(...), $rows), (int) ($rows[0]['total'] ?? 0));
    }

    /**
     * The deals `app:crm:renewal-tasks` still has to write a task for. The done task of a deal counts: it is not created
     * again.
     *
     * @return list<string>
     */
    public function withoutTask(): array
    {
        return array_map(strval(...), $this->connection->fetchFirstColumn(
            'SELECT d.id '.self::SCOPE
            .' AND NOT EXISTS (SELECT 1 FROM crm_tasks t WHERE t.deal_id = d.id AND t.is_renewal)'
            .' ORDER BY d.contract_end_on, d.id',
            $this->window(),
        ));
    }

    /**
     * @return array{first: string, last: string}
     */
    private function window(): array
    {
        $window = RenewalWindow::at($this->clock->now());

        return ['first' => $window->first, 'last' => $window->last];
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function row(array $row): RenewalRow
    {
        return new RenewalRow(
            (string) $row['id'],
            (string) $row['company_name'],
            TierLabel::tryFromRow($row),
            new \DateTimeImmutable((string) $row['contract_end_on']),
            null === $row['amount_rappen'] ? null : (int) $row['amount_rappen'],
        );
    }
}
