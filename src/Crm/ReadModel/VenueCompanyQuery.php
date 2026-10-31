<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

use App\Crm\ValueObject\CompanyStage;
use Doctrine\DBAL\Connection;

/**
 * The venue panel in one statement (D20): it runs on every tab of a venue, #25's too. A record is
 * addressed by its number, so the join goes through `v.number` to `crm_company_venues.venue_id` (D2).
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
                   LEFT JOIN crm_contacts tp ON tp.id = t.contact_id
                   WHERE t.done_at IS NULL AND (t.company_id = c.id OR tp.company_id = c.id)
               ), '[]') AS tasks
        FROM venues v
        LEFT JOIN crm_company_venues l ON l.venue_id = v.id
        LEFT JOIN crm_companies c ON c.id = l.company_id
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
        $companyId = $this->connection->fetchOne('SELECT company_id FROM crm_company_venues WHERE venue_id = ?', [$venueId]);

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
            return new VenueCompany((string) $row['venue_id'], null, null, null, null, [], []);
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
