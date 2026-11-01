<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * The data of a contact export (CRM-11, D17), four statements: the contact with its company's name,
 * its timeline, its tasks, its deals. Only CRM tables: inquiries stay out (owner's rule, #29). Null for an unknown id.
 */
final class ContactExportQuery
{
    private const string CONTACT = <<<'SQL'
        SELECT p.first_name, p.last_name, p.email, p.phone, p.position, p.kind, p.created_at,
               co.name AS company_name, u.display_name AS owner_name
        FROM crm_contacts p
        LEFT JOIN crm_companies co ON co.id = p.company_id
        LEFT JOIN admin_users u ON u.id = p.owner_id
        WHERE p.id = :id
        SQL;

    private const string ACTIVITIES = <<<'SQL'
        SELECT a.type_code, a.occurred_at, a.title, a.body, a.payload, a.anonymised_at
        FROM crm_activity_contacts l
        JOIN crm_activities a ON a.id = l.activity_id
        WHERE l.contact_id = :id
        ORDER BY a.occurred_at DESC, a.id DESC
        SQL;

    private const string TASKS = <<<'SQL'
        SELECT title, due_on, done_at
        FROM crm_tasks
        WHERE contact_id = :id
        ORDER BY due_on ASC, id ASC
        SQL;

    private const string DEALS = <<<'SQL'
        SELECT d.name, c.name AS company_name, d.stage_code, d.contract_start_on, d.contract_end_on, d.created_at
        FROM crm_deal_contacts l
        JOIN crm_deals d ON d.id = l.deal_id
        JOIN crm_companies c ON c.id = d.company_id
        WHERE l.contact_id = :id
        ORDER BY d.created_at DESC, d.id DESC
        SQL;

    private const string ZONE = 'Europe/Zurich';

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function find(string $id): ?ContactExport
    {
        if (!Uuid::isValid($id)) {
            return null;
        }
        $contact = $this->connection->fetchAssociative(self::CONTACT, ['id' => $id]);
        if (false === $contact) {
            return null;
        }

        return new ContactExport(
            $id,
            [
                'first_name' => self::text($contact['first_name']),
                'last_name' => (string) $contact['last_name'],
                'email' => self::text($contact['email']),
                'phone' => self::text($contact['phone']),
                'position' => self::text($contact['position']),
                'kind' => (string) $contact['kind'],
                'owner' => self::text($contact['owner_name']),
                'created_at' => (string) self::time($contact['created_at']),
            ],
            self::text($contact['company_name']),
            array_map(static fn (array $row): array => [
                'type' => (string) $row['type_code'],
                'occurred_at' => (string) self::time($row['occurred_at']),
                'title' => self::text($row['title']),
                'body' => self::text($row['body']),
                // Not as an array: an empty payload stays `{}` in the JSON.
                'payload' => json_decode((string) $row['payload'], false, flags: \JSON_THROW_ON_ERROR),
                'anonymised_at' => self::time($row['anonymised_at']),
            ], $this->connection->fetchAllAssociative(self::ACTIVITIES, ['id' => $id])),
            array_map(static fn (array $row): array => [
                'title' => (string) $row['title'],
                'due_on' => (string) $row['due_on'],
                'done_at' => self::time($row['done_at']),
            ], $this->connection->fetchAllAssociative(self::TASKS, ['id' => $id])),
            array_map(static fn (array $row): array => [
                'name' => (string) $row['name'],
                'company' => (string) $row['company_name'],
                'stage' => (string) $row['stage_code'],
                'contract_start_on' => self::text($row['contract_start_on']),
                'contract_end_on' => self::text($row['contract_end_on']),
                'created_at' => (string) self::time($row['created_at']),
            ], $this->connection->fetchAllAssociative(self::DEALS, ['id' => $id])),
        );
    }

    private static function text(mixed $value): ?string
    {
        return null === $value ? null : (string) $value;
    }

    private static function time(mixed $value): ?string
    {
        return null === $value
            ? null
            : new \DateTimeImmutable((string) $value)->setTimezone(new \DateTimeZone(self::ZONE))->format(\DATE_ATOM);
    }
}
