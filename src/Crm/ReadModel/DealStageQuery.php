<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * The stages in pipeline order, and where a deal stands, for the stage endpoint's no-op check before it dispatches
 * (P1, D17-b) and its answer. One statement each; no state for an unknown or malformed id.
 */
final class DealStageQuery
{
    private const string ZONE = 'Europe/Zurich';

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * The columns of the pipeline.
     *
     * @return list<DealStageView>
     */
    public function all(): array
    {
        return array_map(
            static fn (array $row): DealStageView => new DealStageView((string) $row['code'], (bool) $row['is_won'], (bool) $row['is_lost']),
            $this->connection->fetchAllAssociative('SELECT code, is_won, is_lost FROM crm_deal_stages ORDER BY position'),
        );
    }

    /**
     * @return list<string>
     */
    public function codes(): array
    {
        return array_map(static fn (DealStageView $stage): string => $stage->code, $this->all());
    }

    public function stateOf(string $dealId): ?DealStageState
    {
        if (!Uuid::isValid($dealId)) {
            return null;
        }

        $row = $this->connection->fetchAssociative(
            'SELECT d.stage_code, d.closed_at, d.lost_reason, s.is_lost FROM crm_deals d JOIN crm_deal_stages s ON s.code = d.stage_code WHERE d.id = :id',
            ['id' => $dealId],
        );
        if (false === $row) {
            return null;
        }

        return new DealStageState(
            (string) $row['stage_code'],
            null === $row['closed_at'] ? null : new \DateTimeImmutable((string) $row['closed_at'])->setTimezone(new \DateTimeZone(self::ZONE))->format('Y-m-d'),
            (bool) $row['is_lost'],
            null === $row['lost_reason'] ? null : (string) $row['lost_reason'],
        );
    }
}
