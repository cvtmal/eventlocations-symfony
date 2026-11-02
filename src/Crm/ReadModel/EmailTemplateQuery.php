<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * The mail templates, for the settings screen and the composer's Vorlage field.
 */
final class EmailTemplateQuery
{
    private const string COLUMNS = 'id, name, subject, body_markdown, updated_at';

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return list<EmailTemplateRow>
     */
    public function all(): array
    {
        $rows = $this->connection->fetchAllAssociative('SELECT '.self::COLUMNS.' FROM crm_email_templates ORDER BY lower(name), id');

        return array_map(self::row(...), $rows);
    }

    /**
     * Null for an unknown id, a non-UUID included, so no 22P02 reaches a 500.
     */
    public function find(string $id): ?EmailTemplateRow
    {
        if (!Uuid::isValid($id)) {
            return null;
        }
        $row = $this->connection->fetchAssociative('SELECT '.self::COLUMNS.' FROM crm_email_templates WHERE id = :id', ['id' => $id]);

        return false === $row ? null : self::row($row);
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    public function options(): array
    {
        $rows = $this->connection->fetchAllAssociative('SELECT id, name FROM crm_email_templates ORDER BY lower(name), id');

        return array_map(static fn (array $row): array => ['id' => (string) $row['id'], 'name' => (string) $row['name']], $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function row(array $row): EmailTemplateRow
    {
        return new EmailTemplateRow(
            (string) $row['id'],
            (string) $row['name'],
            (string) $row['subject'],
            (string) $row['body_markdown'],
            new \DateTimeImmutable((string) $row['updated_at']),
        );
    }
}
