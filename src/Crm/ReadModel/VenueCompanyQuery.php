<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

use App\Crm\ValueObject\CompanyStage;
use Doctrine\DBAL\Connection;

/**
 * The venue panel in one statement (D20): it runs on every tab of a venue, #25's too. A record is
 * addressed by its number, so the join goes through `v.number` to `crm_companies.venue_id` (D2, #56).
 */
final class VenueCompanyQuery
{
    private const string SQL = <<<'SQL'
        SELECT v.id AS venue_id, c.id AS company_id, c.name, c.stage, c.created_at,
               COALESCE((
                   SELECT json_agg(json_build_object('id', p.id, 'first_name', p.first_name, 'last_name', p.last_name,
                                                     'position', p.position)
                                   ORDER BY lower(p.last_name), lower(coalesce(p.first_name, '')), p.id)
                   FROM crm_contacts p
                   WHERE p.company_id = c.id
               ), '[]') AS contacts,
               COALESCE((
                   SELECT json_agg(json_build_object('title', t.title, 'due_on', t.due_on)
                                   ORDER BY t.due_on, lower(t.title), t.id)
                   FROM crm_tasks t
                   LEFT JOIN crm_deals td ON td.id = t.deal_id
                   WHERE t.done_at IS NULL AND (t.company_id = c.id OR td.company_id = c.id)
               ), '[]') AS tasks,
               od.id AS deal_id, od.name AS deal_name, od.stage_code AS deal_stage, od.amount_rappen AS deal_amount
        FROM venues v
        LEFT JOIN crm_companies c ON c.venue_id = v.id
        LEFT JOIN LATERAL (
            SELECT d.id, d.name, d.stage_code, d.amount_rappen
            FROM crm_deals d
            JOIN crm_deal_stages s ON s.code = d.stage_code
            WHERE d.venue_id = v.id AND NOT s.is_won AND NOT s.is_lost
            ORDER BY d.expected_close_on ASC NULLS LAST, lower(d.name), d.id
            LIMIT 1
        ) od ON TRUE
        WHERE v.number = :number
        SQL;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * The company a venue belongs to, for the check before a link command (P1): a command that
     * changes nothing still leaves an audit row.
     */
    public function companyIdOf(string $venueId): ?string
    {
        $companyId = $this->connection->fetchOne('SELECT id FROM crm_companies WHERE venue_id = ?', [$venueId]);

        return false === $companyId ? null : (string) $companyId;
    }

    /**
     * Null when no venue has the number.
     */
    public function forVenue(int $number): ?VenueCompany
    {
        $row = $this->connection->fetchAssociative(self::SQL, ['number' => $number]);
        if (false === $row) {
            return null;
        }
        if (null === $row['company_id']) {
            return new VenueCompany((string) $row['venue_id'], null, null, null, null, [], [], null);
        }

        return new VenueCompany(
            (string) $row['venue_id'],
            (string) $row['company_id'],
            (string) $row['name'],
            CompanyStage::from((string) $row['stage']),
            new \DateTimeImmutable((string) $row['created_at']),
            array_map(static fn (array $contact): CompanyRecordContact => new CompanyRecordContact(
                (string) $contact['id'],
                null === $contact['first_name'] ? null : (string) $contact['first_name'],
                (string) $contact['last_name'],
                null === $contact['position'] ? null : (string) $contact['position'],
            ), self::list($row['contacts'])),
            array_map(static fn (array $task): VenueCompanyTask => new VenueCompanyTask(
                (string) $task['title'],
                (string) $task['due_on'],
            ), self::list($row['tasks'])),
            null === $row['deal_id'] ? null : new VenueOpenDeal(
                (string) $row['deal_id'],
                (string) $row['deal_name'],
                (string) $row['deal_stage'],
                null === $row['deal_amount'] ? null : (int) $row['deal_amount'],
            ),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function list(mixed $json): array
    {
        $items = json_decode((string) $json, true, flags: \JSON_THROW_ON_ERROR);
        \assert(\is_array($items) && array_is_list($items));

        /* @var list<array<string, mixed>> $items */
        return $items;
    }
}
