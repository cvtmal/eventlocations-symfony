<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

use Doctrine\DBAL\Connection;

/**
 * What the customer import matches the sheet against (#48 R2, R5): the venues, the companies and the
 * contacts' emails, read once per run, plus the sheet's names folded and compared in SQL. Folding goes
 * through `geo_fold_de()` and never a PHP copy, so a key equals the one `TownRepository::findByZipAndName()`
 * and `VenueLookupQuery` compare. `fold()` and `similar()` are one statement each, whatever the sheet's size.
 */
final class CustomerMatchQuery
{
    /** The trigram similarity of `geo_search_key()` from which a name counts as similar (R5). */
    public const float SIMILAR_NAME = 0.7;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @param list<string> $texts
     *
     * @return array<string, string> each distinct text to its `geo_fold_de()` key
     */
    public function fold(array $texts): array
    {
        $texts = array_values(array_unique($texts));
        if ([] === $texts) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT t, geo_fold_de(t) AS k FROM unnest(CAST(:texts AS text[])) t',
            ['texts' => self::textArray($texts)],
        );

        $keys = [];
        foreach ($rows as $row) {
            $keys[(string) $row['t']] = (string) $row['k'];
        }

        return $keys;
    }

    /**
     * @return list<CustomerMatchVenue> by name
     */
    public function venues(): array
    {
        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT v.id, v.name, geo_fold_de(v.name) AS key, v.canton_code, v.website, co.id AS company_id, co.name AS company_name
            FROM venues v
            LEFT JOIN crm_companies co ON co.venue_id = v.id
            ORDER BY v.name, v.id
            SQL);

        return array_map(static fn (array $row): CustomerMatchVenue => new CustomerMatchVenue(
            (string) $row['id'],
            (string) $row['name'],
            (string) $row['key'],
            null === $row['canton_code'] ? null : (string) $row['canton_code'],
            null === $row['website'] ? null : (string) $row['website'],
            null === $row['company_id'] ? null : (string) $row['company_id'],
            null === $row['company_name'] ? null : (string) $row['company_name'],
        ), $rows);
    }

    /**
     * @return list<CustomerMatchCompany> by name
     */
    public function companies(): array
    {
        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT co.id, co.name, geo_fold_de(co.name) AS key, co.website, co.email,
                   string_agg(DISTINCT v.canton_code, ',' ORDER BY v.canton_code) AS cantons
            FROM crm_companies co
            LEFT JOIN venues v ON v.id = co.venue_id
            GROUP BY co.id
            ORDER BY co.name, co.id
            SQL);

        return array_map(static fn (array $row): CustomerMatchCompany => new CustomerMatchCompany(
            (string) $row['id'],
            (string) $row['name'],
            (string) $row['key'],
            null === $row['website'] ? null : (string) $row['website'],
            null === $row['email'] ? null : (string) $row['email'],
            null === $row['cantons'] ? [] : explode(',', (string) $row['cantons']),
        ), $rows);
    }

    /**
     * @return list<string> the contacts' emails, lower case (`chk_crm_contacts_email_lower`)
     */
    public function contactEmails(): array
    {
        /** @var list<string> $emails */
        $emails = $this->connection->fetchFirstColumn('SELECT email FROM crm_contacts WHERE email IS NOT NULL ORDER BY email');

        return $emails;
    }

    /**
     * The names of venues and companies whose `geo_search_key()` is trigram-similar to a given name's. `%`
     * lets the planner use `idx_venues_search_key` and `idx_crm_companies_search_key`; pg_trgm's default
     * threshold (0.3) is below SIMILAR_NAME, so it drops nothing the `similarity()` test would keep.
     *
     * @param list<string> $names
     *
     * @return array<string, list<string>> each given name with at least one similar name, to those names (distinct, ascending)
     */
    public function similar(array $names): array
    {
        $names = array_values(array_unique($names));
        if ([] === $names) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                WITH s AS MATERIALIZED (
                    SELECT t AS name, geo_search_key(t) AS k FROM unnest(CAST(:names AS text[])) t
                )
                SELECT s.name AS sheet_name, v.name AS candidate
                FROM s JOIN venues v ON geo_search_key(v.name) % s.k AND similarity(geo_search_key(v.name), s.k) >= CAST(:threshold AS real)
                UNION
                SELECT s.name, co.name
                FROM s JOIN crm_companies co ON geo_search_key(co.name) % s.k AND similarity(geo_search_key(co.name), s.k) >= CAST(:threshold AS real)
                ORDER BY 1, 2
                SQL,
            ['names' => self::textArray($names), 'threshold' => (string) self::SIMILAR_NAME],
        );

        $similar = [];
        foreach ($rows as $row) {
            $similar[(string) $row['sheet_name']][] = (string) $row['candidate'];
        }

        return $similar;
    }

    /**
     * A Postgres array literal, so the whole list is one parameter: every element quoted, `"` and `\` escaped.
     *
     * @param list<string> $texts
     */
    private static function textArray(array $texts): string
    {
        return '{'.implode(',', array_map(static fn (string $text): string => '"'.addcslashes($text, '"\\').'"', $texts)).'}';
    }
}
