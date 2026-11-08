<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

use Doctrine\DBAL\Connection;

/**
 * The print list of a postal campaign (G11): the mail-merge CSV of preview links with their short links, never a
 * mailer. **A Location is on it** when it holds the campaign code, has an Eintrag, no returned letter and is not
 * suppressed (`SuppressionQuery::LOCATION_SUPPRESSED`). Its link is the newest live link of its Eintrag with that
 * campaign code and a short code that still answers `VALID_DAYS` days from now, so a letter posted this week still
 * opens; `missingLinks()` names the Eintraege that need a new one first.
 */
final class CampaignPrintQuery
{
    /** How long a printed link must still answer. */
    public const int VALID_DAYS = 30;

    private const string ELIGIBLE = <<<'SQL'
        FROM crm_companies c
        JOIN venues v ON v.id = c.venue_id
        WHERE c.campaign_code = :code AND c.mail_returned_on IS NULL
        SQL;

    private const string LINK = <<<'SQL'
        SELECT l.id, l.short_code, l.expires_at FROM venue_previews l
        WHERE l.venue_id = v.id AND l.campaign_code = c.campaign_code AND l.short_code IS NOT NULL
          AND l.revoked_at IS NULL AND l.expires_at > :valid
        ORDER BY l.expires_at DESC, l.id DESC LIMIT 1
        SQL;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * The numbers of the Eintraege on the list that have no usable link of the campaign yet.
     *
     * @return list<int>
     */
    public function missingLinks(string $code, \DateTimeImmutable $now): array
    {
        return array_map(intval(...), $this->connection->fetchFirstColumn(
            'SELECT v.number '.self::ELIGIBLE.' AND NOT '.SuppressionQuery::LOCATION_SUPPRESSED.' AND NOT EXISTS ('.self::LINK.') ORDER BY v.number',
            ['code' => $code, 'valid' => self::validFrom($now)],
        ));
    }

    /**
     * @return list<CampaignPrintRow>
     */
    public function rows(string $code, \DateTimeImmutable $now): array
    {
        $sql = <<<'SQL'
            SELECT c.name AS company, c.address, c.cohort, v.name AS venue, v.street, v.zip, t.name AS town,
                   (SELECT concat_ws(' ', p.first_name, p.last_name) FROM crm_contacts p WHERE p.company_id = c.id ORDER BY p.created_at, p.id LIMIT 1) AS contact,
                   link.id AS link_id, link.short_code, link.expires_at
            FROM crm_companies c
            JOIN venues v ON v.id = c.venue_id
            LEFT JOIN towns t ON t.id = v.town_id
            JOIN LATERAL (
            SQL;
        $rows = $this->connection->fetchAllAssociative(
            $sql.self::LINK.') link ON true WHERE c.campaign_code = :code AND c.mail_returned_on IS NULL AND NOT '
                .SuppressionQuery::LOCATION_SUPPRESSED.' ORDER BY lower(c.name), c.id',
            ['code' => $code, 'valid' => self::validFrom($now)],
        );

        return array_map(static fn (array $row): CampaignPrintRow => new CampaignPrintRow(
            (string) $row['company'],
            null === $row['contact'] || '' === $row['contact'] ? null : (string) $row['contact'],
            null === $row['address'] ? null : (string) $row['address'],
            (string) $row['venue'],
            null === $row['street'] ? null : (string) $row['street'],
            null === $row['zip'] ? null : (string) $row['zip'],
            null === $row['town'] ? null : (string) $row['town'],
            null === $row['cohort'] ? null : (string) $row['cohort'],
            (string) $row['link_id'],
            (string) $row['short_code'],
            new \DateTimeImmutable((string) $row['expires_at']),
        ), $rows);
    }

    private static function validFrom(\DateTimeImmutable $now): string
    {
        return $now->modify(\sprintf('+%d days', self::VALID_DAYS))->format('Y-m-d H:i:sP');
    }
}
