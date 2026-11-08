<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

use App\Crm\ValueObject\ContactBasis;
use App\Crm\ValueObject\ContactChannel;
use App\Crm\ValueObject\Outreach;
use App\Crm\ValueObject\OutreachCohort;
use Doctrine\DBAL\Connection;

/**
 * The outreach fields of one Location (its "Akquise" tab) and the postal campaigns the Akquise page offers (G11).
 */
final class OutreachQuery
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function of(string $companyId): ?Outreach
    {
        $row = $this->connection->fetchAssociative(
            'SELECT campaign_code, cohort, contact_channel, contact_basis, outreach_sent_on, mail_returned_on FROM crm_companies WHERE id = :id',
            ['id' => $companyId],
        );
        if (false === $row) {
            return null;
        }

        return new Outreach(
            null === $row['campaign_code'] ? null : (string) $row['campaign_code'],
            null === $row['cohort'] ? null : OutreachCohort::from((string) $row['cohort']),
            null === $row['contact_channel'] ? null : ContactChannel::from((string) $row['contact_channel']),
            null === $row['contact_basis'] ? null : ContactBasis::from((string) $row['contact_basis']),
            null === $row['outreach_sent_on'] ? null : new \DateTimeImmutable((string) $row['outreach_sent_on']),
            null === $row['mail_returned_on'] ? null : new \DateTimeImmutable((string) $row['mail_returned_on']),
        );
    }

    /**
     * Every campaign code a Location holds, by code, with how many Locations hold it, how many a print list would take
     * (an Eintrag, not suppressed, no returned letter) and how many have no send day yet.
     *
     * @return list<CampaignRow>
     */
    public function campaigns(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT c.campaign_code,
                    COUNT(*) AS locations,
                    COUNT(*) FILTER (WHERE c.venue_id IS NOT NULL AND c.mail_returned_on IS NULL AND NOT '.SuppressionQuery::LOCATION_SUPPRESSED.') AS printable,
                    COUNT(*) FILTER (WHERE c.outreach_sent_on IS NULL) AS unsent
             FROM crm_companies c
             WHERE c.campaign_code IS NOT NULL
             GROUP BY c.campaign_code
             ORDER BY c.campaign_code',
        );

        return array_map(static fn (array $row): CampaignRow => new CampaignRow(
            (string) $row['campaign_code'],
            (int) $row['locations'],
            (int) $row['printable'],
            (int) $row['unsent'],
        ), $rows);
    }
}
