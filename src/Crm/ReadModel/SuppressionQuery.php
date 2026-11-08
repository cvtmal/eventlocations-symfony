<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

use App\Crm\ValueObject\ContactEmail;
use App\Crm\ValueObject\SuppressionReason;
use Doctrine\DBAL\Connection;

/**
 * The suppression list (G11), read. **A Location is suppressed** when a row names it or any address it holds (its own
 * e-mail or a contact's); every export and the CRM mail use that one rule, through `LOCATION_SUPPRESSED` on a
 * `crm_companies c` or the methods here. An address alone is suppressed when a row holds it.
 */
final class SuppressionQuery
{
    /** True for the `crm_companies` row aliased `c`; `NOT (...)` keeps it out of an export. */
    public const string LOCATION_SUPPRESSED = <<<'SQL'
        EXISTS (
            SELECT 1 FROM suppressions sup
            WHERE sup.company_id = c.id
               OR sup.email = lower(c.email)
               OR sup.email IN (SELECT sp.email FROM crm_contacts sp WHERE sp.company_id = c.id AND sp.email IS NOT NULL)
        )
        SQL;

    /** The newest rows the list shows; the page says how many there are in all. */
    public const int LIST_LIMIT = 200;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function isLocationSuppressed(string $companyId): bool
    {
        return false !== $this->connection->fetchOne('SELECT 1 FROM crm_companies c WHERE c.id = :id AND '.self::LOCATION_SUPPRESSED, ['id' => $companyId]);
    }

    public function isAddressSuppressed(string $email): bool
    {
        $email = ContactEmail::normalise($email);

        return null !== $email && false !== $this->connection->fetchOne('SELECT 1 FROM suppressions WHERE email = :email', ['email' => $email]);
    }

    /**
     * The row that names the Location itself, else the oldest row of one of its addresses; null when it is not
     * suppressed.
     */
    public function stateOf(string $companyId): ?SuppressionState
    {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT s.reason, s.created_at, s.company_id IS NOT NULL AS whole
                FROM crm_companies c
                JOIN suppressions s ON s.company_id = c.id
                    OR s.email = lower(c.email)
                    OR s.email IN (SELECT sp.email FROM crm_contacts sp WHERE sp.company_id = c.id AND sp.email IS NOT NULL)
                WHERE c.id = :id
                ORDER BY (s.company_id IS NOT NULL) DESC, s.created_at, s.id
                LIMIT 1
                SQL,
            ['id' => $companyId],
        );
        if (false === $row) {
            return null;
        }

        return new SuppressionState(SuppressionReason::from((string) $row['reason']), new \DateTimeImmutable((string) $row['created_at']), (bool) $row['whole']);
    }

    public function count(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM suppressions');
    }

    /**
     * The newest `LIST_LIMIT` rows, newest first: a Location by its name, an address as it is.
     *
     * @return list<SuppressionRow>
     */
    public function newest(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT s.id, s.company_id, c.name AS company_name, s.email, s.reason, s.created_at
             FROM suppressions s LEFT JOIN crm_companies c ON c.id = s.company_id
             ORDER BY s.created_at DESC, s.id DESC LIMIT '.self::LIST_LIMIT,
        );

        return array_map(static fn (array $row): SuppressionRow => new SuppressionRow(
            (string) $row['id'],
            null === $row['company_id'] ? null : (string) $row['company_id'],
            null === $row['company_name'] ? null : (string) $row['company_name'],
            null === $row['email'] ? null : (string) $row['email'],
            SuppressionReason::from((string) $row['reason']),
            new \DateTimeImmutable((string) $row['created_at']),
        ), $rows);
    }
}
