<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

use App\Crm\ValueObject\CompanyStage;
use App\Crm\ValueObject\ContactKind;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * A contact record page in one statement (D20): the contact, its company and its owner's name. Null
 * for an unknown id, a non-UUID included.
 */
final class ContactRecordQuery
{
    private const string SQL = <<<'SQL'
        SELECT p.id, p.first_name, p.last_name, p.email, p.phone, p.position, p.kind, p.company_id, p.owner_id,
               p.created_at, co.name AS company_name, co.stage AS company_stage, u.display_name AS owner_name
        FROM crm_contacts p
        LEFT JOIN crm_companies co ON co.id = p.company_id
        LEFT JOIN admin_users u ON u.id = p.owner_id
        WHERE p.id = :id
        SQL;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function find(string $id): ?ContactRecord
    {
        if (!Uuid::isValid($id)) {
            return null;
        }
        $row = $this->connection->fetchAssociative(self::SQL, ['id' => $id]);
        if (false === $row) {
            return null;
        }

        return new ContactRecord(
            (string) $row['id'],
            self::text($row['first_name']),
            (string) $row['last_name'],
            self::text($row['email']),
            self::text($row['phone']),
            self::text($row['position']),
            ContactKind::from((string) $row['kind']),
            self::text($row['company_id']),
            self::text($row['company_name']),
            null === $row['company_stage'] ? null : CompanyStage::from((string) $row['company_stage']),
            self::text($row['owner_id']),
            self::text($row['owner_name']),
            new \DateTimeImmutable((string) $row['created_at']),
        );
    }

    private static function text(mixed $value): ?string
    {
        return null === $value ? null : (string) $value;
    }
}
