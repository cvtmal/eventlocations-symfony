<?php

declare(strict_types=1);

namespace App\Portal\ReadModel;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * The staff list of manager accounts: COUNT, then one page of rows. The search folds like the CRM lists, over
 * one key of name and address. There is no index on it: a table of a few hundred accounts needs none.
 */
final class PortalAccountListQuery
{
    public const int PER_PAGE = 50;

    private const string SEARCH = <<<'SQL'
         WHERE geo_fold_de(:q) <> ''
           AND (geo_search_key(u.first_name || ' ' || u.last_name || ' ' || u.email) LIKE '%' || geo_fold_de(:q) || '%'
                OR geo_search_key(u.first_name || ' ' || u.last_name || ' ' || u.email) LIKE '%' || geo_fold(:q) || '%')
        SQL;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function search(?string $q, int $page): PortalAccountPage
    {
        $q = null === $q ? '' : trim($q);
        $where = '' === $q ? '' : self::SEARCH;
        $parameters = '' === $q ? [] : ['q' => $q];

        $total = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM portal_users u'.$where, $parameters);
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));
        // The URL's page, clamped to what exists: a huge value would overflow the offset into a float.
        $page = min(max(1, $page), $lastPage);

        $rows = $total > 0 ? $this->rows($where, $parameters, ($page - 1) * self::PER_PAGE) : [];

        return new PortalAccountPage($rows, $total, $page, self::PER_PAGE, $lastPage);
    }

    /**
     * @param array<string, string> $parameters
     *
     * @return list<PortalAccountRow>
     */
    private function rows(string $where, array $parameters, int $offset): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<SQL
                SELECT u.id, u.first_name, u.last_name, u.email, u.phone, u.email_verified_at, u.is_active, u.last_login_at
                FROM portal_users u{$where}
                ORDER BY u.created_at DESC, u.id DESC
                LIMIT :limit OFFSET :offset
                SQL,
            $parameters + ['limit' => self::PER_PAGE, 'offset' => $offset],
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );

        return array_map(static fn (array $row): PortalAccountRow => new PortalAccountRow(
            (string) $row['id'],
            $row['first_name'].' '.$row['last_name'],
            (string) $row['email'],
            null === $row['phone'] ? null : (string) $row['phone'],
            null !== $row['email_verified_at'],
            (bool) $row['is_active'],
            null === $row['last_login_at'] ? null : new \DateTimeImmutable((string) $row['last_login_at']),
        ), $rows);
    }
}
