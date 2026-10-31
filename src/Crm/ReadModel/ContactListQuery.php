<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

use App\Crm\ValueObject\ContactKind;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * The contact list (CRM-6, D20): COUNT, then one page of rows, so two statements; the quick search
 * for the global search; and every filtered row for the CSV export. A name goes through #6's folding
 * on the expression of `idx_crm_contacts_search_key`; an e-mail address is matched as typed,
 * lower-cased.
 */
final class ContactListQuery
{
    public const int PER_PAGE = 50;

    private const string FROM = <<<'SQL'
        FROM crm_contacts p
        LEFT JOIN crm_companies co ON co.id = p.company_id
        LEFT JOIN admin_users u ON u.id = p.owner_id
        SQL;

    private const string COLUMNS = <<<'SQL'
        SELECT p.id, p.first_name, p.last_name, p.position, p.kind, p.email, p.company_id, co.name AS company_name,
               u.display_name AS owner_name
        SQL;

    private const string CSV_COLUMNS = <<<'SQL'
        SELECT p.first_name, p.last_name, p.kind, co.name AS company_name, p.position, p.email, p.phone,
               u.display_name AS owner_name
        SQL;

    /** The expression of `idx_crm_contacts_search_key`, verbatim, or the index does not apply. */
    private const string NAME_KEY = "geo_search_key(coalesce(p.first_name, '') || ' ' || p.last_name)";

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function search(ContactListFilter $filter, ContactSort $sort, SortDirection $direction, int $page): ContactListPage
    {
        [$conditions, $parameters] = self::conditions($filter);

        $total = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM crm_contacts p'.self::where($conditions), $parameters);
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, $page), $lastPage);

        $rows = $total > 0 ? $this->rows($conditions, $parameters, self::order($sort, $direction), self::PER_PAGE, ($page - 1) * self::PER_PAGE) : [];

        return new ContactListPage($rows, $total, $page, self::PER_PAGE, $lastPage);
    }

    /**
     * @return list<ContactListRow>
     */
    public function quickSearch(string $q, int $limit): array
    {
        if ('' === trim($q)) {
            return [];
        }
        [$conditions, $parameters] = self::conditions(new ContactListFilter(q: $q));

        return $this->rows($conditions, $parameters, 'lower(p.last_name) ASC, p.id ASC', $limit);
    }

    /**
     * Every contact of the filter in the list's order, unpaged. The statement runs when the CSV stream
     * starts reading.
     *
     * @return iterable<ContactCsvRow>
     */
    public function csvRows(ContactListFilter $filter, ContactSort $sort, SortDirection $direction): iterable
    {
        [$conditions, $parameters] = self::conditions($filter);
        $rows = $this->connection->iterateAssociative(
            self::CSV_COLUMNS.' '.self::FROM.self::where($conditions).' ORDER BY '.self::order($sort, $direction),
            $parameters,
        );

        foreach ($rows as $row) {
            yield new ContactCsvRow(
                null === $row['first_name'] ? null : (string) $row['first_name'],
                (string) $row['last_name'],
                ContactKind::from((string) $row['kind']),
                null === $row['company_name'] ? null : (string) $row['company_name'],
                null === $row['position'] ? null : (string) $row['position'],
                null === $row['email'] ? null : (string) $row['email'],
                null === $row['phone'] ? null : (string) $row['phone'],
                null === $row['owner_name'] ? null : (string) $row['owner_name'],
            );
        }
    }

    /**
     * @param list<string>         $conditions
     * @param array<string, mixed> $parameters
     *
     * @return list<ContactListRow>
     */
    private function rows(array $conditions, array $parameters, string $order, int $limit, int $offset = 0): array
    {
        $rows = $this->connection->fetchAllAssociative(
            self::COLUMNS.' '.self::FROM.self::where($conditions).' ORDER BY '.$order.' LIMIT :limit OFFSET :offset',
            $parameters + ['limit' => $limit, 'offset' => $offset],
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );

        return array_map(static fn (array $row): ContactListRow => new ContactListRow(
            (string) $row['id'],
            null === $row['first_name'] ? null : (string) $row['first_name'],
            (string) $row['last_name'],
            null === $row['position'] ? null : (string) $row['position'],
            ContactKind::from((string) $row['kind']),
            null === $row['company_id'] ? null : (string) $row['company_id'],
            null === $row['company_name'] ? null : (string) $row['company_name'],
            null === $row['email'] ? null : (string) $row['email'],
            null === $row['owner_name'] ? null : (string) $row['owner_name'],
        ), $rows);
    }

    private static function order(ContactSort $sort, SortDirection $direction): string
    {
        return $sort->column().' '.$direction->sql().' NULLS LAST, lower(p.last_name) ASC, p.id ASC';
    }

    /**
     * @return array{list<string>, array<string, mixed>}
     */
    private static function conditions(ContactListFilter $filter): array
    {
        $conditions = [];
        $parameters = [];

        $q = null === $filter->q ? '' : trim($filter->q);
        if ('' !== $q) {
            $conditions[] = "((geo_fold_de(:q) <> '' AND (".self::NAME_KEY." LIKE '%' || geo_fold_de(:q) || '%' OR ".self::NAME_KEY." LIKE '%' || geo_fold(:q) || '%'))"
                .' OR p.email LIKE :q_email)';
            $parameters['q'] = $q;
            $parameters['q_email'] = '%'.addcslashes(mb_strtolower($q), '\\%_').'%';
        }
        if (null !== $filter->kind) {
            $conditions[] = 'p.kind = :kind';
            $parameters['kind'] = $filter->kind->value;
        }
        if (CompanyListFilter::OWNER_NONE === $filter->owner) {
            $conditions[] = 'p.owner_id IS NULL';
        } elseif (null !== $filter->owner) {
            $conditions[] = 'p.owner_id = :owner';
            $parameters['owner'] = $filter->owner;
        }

        return [$conditions, $parameters];
    }

    /**
     * @param list<string> $conditions
     */
    private static function where(array $conditions): string
    {
        return [] === $conditions ? '' : ' WHERE '.implode(' AND ', $conditions);
    }
}
