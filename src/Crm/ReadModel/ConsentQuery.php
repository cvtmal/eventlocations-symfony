<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

use Doctrine\DBAL\Connection;

/**
 * Whether a Location's timeline holds a consent entry, and the addresses its yeses came from (F14, `RecordConsent`: payload `{"consent": {…}}`), for the
 * consent and withdrawal handlers and the dry run of `app:crm:record-consents`, and the key a withdrawal note keeps.
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

    /**
     * Every address a yes of the Location came from, lower-case and without duplicates (the withdrawal suppresses them).
     *
     * @return list<string>
     */
    public function fromAddresses(string $companyId): array
    {
        /** @var list<string> $addresses */
        $addresses = $this->connection->fetchFirstColumn(
            <<<'SQL'
                SELECT DISTINCT LOWER(a.payload->'consent'->>'from') FROM crm_activities a
                JOIN crm_activity_companies l ON l.activity_id = a.id
                WHERE l.company_id = :company
                  AND a.payload->'consent'->>'from' IS NOT NULL
                SQL,
            ['company' => $companyId],
        );

        return $addresses;
    }

    /**
     * The content key of the venue withdrawn under this number (`{"consent_withdrawn": {"venue", "key"}}`), '' when it had
     * none, null when no venue was withdrawn under it: `app:crm:withdraw-consent --withdrawn-key` for `bin/prod-import`.
     */
    public function withdrawnKey(int $venueNumber): ?string
    {
        $key = $this->connection->fetchOne(
            <<<'SQL'
                SELECT COALESCE(a.payload->'consent_withdrawn'->>'key', '') FROM crm_activities a
                WHERE a.payload->'consent_withdrawn'->>'venue' = :venue
                ORDER BY a.occurred_at DESC
                LIMIT 1
                SQL,
            ['venue' => (string) $venueNumber],
        );

        return \is_string($key) ? $key : null;
    }
}
