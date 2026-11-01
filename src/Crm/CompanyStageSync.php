<?php

declare(strict_types=1);

namespace App\Crm;

use App\Crm\ValueObject\CompanyStage;
use App\Crm\ValueObject\TaskDueDate;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * The one writer of a derived company stage (S13, D9, D10, L2). A company with a won deal is a customer while one
 * such contract runs (no end, or an end today or later) and a former customer once all have ended. A company
 * without a won deal keeps the stage set by hand: at #28's landing no deals exist, and an open upsell must not
 * demote anybody. The one exception is `afterDealChange()`.
 *
 * It writes with SQL, so its statement must run after a flush, and a caller holding the Company entity sees the old
 * stage until it reloads. `updated_at` stays: nobody edited the record, and the stage is no version (#27's D11).
 * A deal handler holds the company's row lock, so this statement starts after any other deal write committed.
 */
final class CompanyStageSync
{
    private const string UPDATE = <<<'SQL'
        UPDATE crm_companies c
        SET stage = derived.stage
        FROM (
            SELECT d.company_id,
                   CASE WHEN bool_or(d.contract_end_on IS NULL OR d.contract_end_on >= :today) THEN :customer ELSE :former END AS stage
            FROM crm_deals d
            JOIN crm_deal_stages s ON s.code = d.stage_code
            WHERE s.is_won %s
            GROUP BY d.company_id
        ) derived
        WHERE c.id = derived.company_id
          AND c.stage IS DISTINCT FROM derived.stage
        SQL;

    private const string RESET = <<<'SQL'
        UPDATE crm_companies c
        SET stage = :prospect
        WHERE c.id = :company
          AND c.stage <> :prospect
          AND NOT EXISTS (
              SELECT 1 FROM crm_deals d JOIN crm_deal_stages s ON s.code = d.stage_code
              WHERE d.company_id = c.id AND s.is_won
          )
        SQL;

    public function __construct(
        private readonly Connection $connection,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return int the companies whose stage changed
     */
    public function syncAll(): int
    {
        return $this->update('', []);
    }

    public function syncCompany(string $companyId): void
    {
        $this->update('AND d.company_id = :company', ['company' => $companyId]);
    }

    /**
     * After a move or a delete. `$wasWon` covers the corner of D9: the company's last won deal left, so the stage set
     * by hand was overwritten long ago and `prospect` is all that can be said.
     */
    public function afterDealChange(string $companyId, bool $wasWon): void
    {
        $this->syncCompany($companyId);
        if ($wasWon) {
            $this->connection->executeStatement(self::RESET, ['company' => $companyId, 'prospect' => CompanyStage::Prospect->value]);
        }
    }

    /**
     * @param array<string, string> $parameters
     */
    private function update(string $condition, array $parameters): int
    {
        return (int) $this->connection->executeStatement(\sprintf(self::UPDATE, $condition), [
            'today' => TaskDueDate::today($this->clock->now()),
            'customer' => CompanyStage::Customer->value,
            'former' => CompanyStage::FormerCustomer->value,
            ...$parameters,
        ]);
    }
}
