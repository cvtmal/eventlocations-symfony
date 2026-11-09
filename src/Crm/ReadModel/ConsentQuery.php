<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

use Doctrine\DBAL\Connection;

/**
 * Whether a Location's timeline holds a consent entry (F14, `RecordConsent`: payload `{"consent": {…}}`), for the
 * consent and withdrawal handlers and the dry run of `app:crm:record-consents`.
 */
final readonly class ConsentQuery
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * Any consent entry; with an address and a day (`Y-m-d`), one of exactly that yes.
     */
    public function has(string $companyId, ?string $from = null, ?string $on = null): bool
    {
        return false !== $this->connection->fetchOne(
            <<<'SQL'
                SELECT 1 FROM crm_activities a
                JOIN crm_activity_companies l ON l.activity_id = a.id
                WHERE l.company_id = :company
                  AND a.payload->'consent' IS NOT NULL
                  AND (CAST(:from AS text) IS NULL OR a.payload->'consent'->>'from' = :from)
                  AND (CAST(:on AS text) IS NULL OR a.payload->'consent'->>'on' = :on)
                LIMIT 1
                SQL,
            ['company' => $companyId, 'from' => $from, 'on' => $on],
        );
    }
}
