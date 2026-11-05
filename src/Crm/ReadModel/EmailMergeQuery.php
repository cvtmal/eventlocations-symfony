<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

use App\Crm\ValueObject\MergeFields;
use App\Crm\ValueObject\RecordKind;
use Doctrine\DBAL\Connection;

/**
 * The merge values for a draft (CRM-10), one statement per kind. `{venue.name}` is the Location's Eintrag's name, empty
 * without one (#56). A deal has no mail step, so it gives null.
 */
final class EmailMergeQuery
{
    private const string VENUE_NAME = <<<'SQL'
        (SELECT v.name FROM venues v WHERE v.id = c.venue_id)
        SQL;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * Null when the record is not there, or when `$contactId` names no contact of that company.
     */
    public function fields(RecordKind $kind, string $recordId, ?string $contactId): ?MergeFields
    {
        return match ($kind) {
            RecordKind::Company => $this->ofCompany($recordId, $contactId),
            RecordKind::Deal => null,
        };
    }

    private function ofCompany(string $companyId, ?string $contactId): ?MergeFields
    {
        $row = $this->connection->fetchAssociative(
            'SELECT p.id AS contact_id, p.first_name, c.name AS company_name, '.self::VENUE_NAME.' AS venue_name
             FROM crm_companies c
             LEFT JOIN crm_contacts p ON p.id = :contact AND p.company_id = c.id
             WHERE c.id = :record',
            ['record' => $companyId, 'contact' => $contactId],
        );
        if (false === $row || (null !== $contactId && null === $row['contact_id'])) {
            return null;
        }

        return self::fieldsOf($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function fieldsOf(array $row): MergeFields
    {
        return new MergeFields(self::text($row['first_name']), self::text($row['company_name']), self::text($row['venue_name']));
    }

    private static function text(mixed $value): ?string
    {
        return null === $value ? null : (string) $value;
    }
}
