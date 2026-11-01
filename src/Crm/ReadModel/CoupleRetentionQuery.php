<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

use App\Crm\ValueObject\ContactKind;
use Doctrine\DBAL\Connection;

/**
 * The couples `app:crm:purge` erases (CRM-11): nothing on their timeline since the cut-off, and no deal, which is staff's
 * reason to keep them. `created_at` protects a couple staff made and have not written anything on yet.
 */
final class CoupleRetentionQuery
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return list<string>
     */
    public function expired(\DateTimeImmutable $cutoff): array
    {
        return array_map(strval(...), $this->connection->fetchFirstColumn(
            <<<'SQL'
                SELECT c.id
                FROM crm_contacts c
                WHERE c.kind = :kind
                  AND c.created_at < :cutoff
                  AND NOT EXISTS (
                      SELECT 1
                      FROM crm_activity_contacts l
                      JOIN crm_activities a ON a.id = l.activity_id
                      WHERE l.contact_id = c.id AND a.occurred_at >= :cutoff
                  )
                  AND NOT EXISTS (SELECT 1 FROM crm_deal_contacts dc WHERE dc.contact_id = c.id)
                ORDER BY c.id
                SQL,
            ['kind' => ContactKind::Couple->value, 'cutoff' => $cutoff->format('Y-m-d H:i:sO')],
        ));
    }
}
