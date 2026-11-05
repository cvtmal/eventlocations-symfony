<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * The second step of "Deal erfassen" in one statement (N5): the company and its linked venues.
 */
final class DealFormQuery
{
    private const string SQL = <<<'SQL'
        SELECT c.id, c.name,
               COALESCE((
                   SELECT json_agg(json_build_object('id', v.id, 'label', v.name) ORDER BY lower(v.name), v.number)
                   FROM crm_company_venues cv
                   JOIN venues v ON v.id = cv.venue_id
                   WHERE cv.company_id = c.id
               ), '[]') AS venues
        FROM crm_companies c
        WHERE c.id = :id
        SQL;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * Null for an unknown or malformed id, so no 22P02 reaches a 500.
     */
    public function company(string $companyId): ?DealFormCompany
    {
        if (!Uuid::isValid($companyId)) {
            return null;
        }

        $row = $this->connection->fetchAssociative(self::SQL, ['id' => $companyId]);
        if (false === $row) {
            return null;
        }

        return new DealFormCompany((string) $row['id'], (string) $row['name'], self::options((string) $row['venues']));
    }

    /**
     * @return list<DealFormOption>
     */
    private static function options(string $json): array
    {
        /** @var list<array{id: string, label: string}> $options */
        $options = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);

        return array_map(static fn (array $option): DealFormOption => new DealFormOption($option['id'], $option['label']), $options);
    }
}
