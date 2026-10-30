<?php

declare(strict_types=1);

namespace App\Geo\ReadModel;

use Doctrine\DBAL\Connection;

/**
 * Ort/PLZ autocomplete: cantons and towns for what the visitor typed, at most 8.
 *
 * Digits search ZIPs by prefix (exact match first). Text lists matching cantons first
 * (by German slug), then towns ranked name prefix → word prefix → trigram similarity, each
 * by population. For a folded query of one or two characters, towns with more ZIPs (capped
 * at 3) come first inside a rank, so "a" finds Aarau and "ba" Basel before small places.
 * Folding goes through the SQL functions geo_fold / geo_fold_de /
 * geo_search_key, so "zuer", "Zuerich", "Zurich" and "zürich" all find Zürich.
 */
final class PlaceLookup
{
    private const LIMIT = 8;
    private const MAX_INPUT = 100;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return list<PlaceSuggestion>
     */
    public function suggest(string $q): array
    {
        $q = mb_substr(trim($q), 0, self::MAX_INPUT);
        // A fold without letters or digits is empty, and LIKE '' || '%' would match everything.
        // This raw guard catches punctuation; the SQL also guards the folded value, since
        // letters outside the fold's alphabet (日本, Ελλάδα) pass here but fold to ''.
        if (1 !== preg_match('/[\p{L}\p{N}]/u', $q)) {
            return [];
        }

        if (1 === preg_match('/^\d{1,4}$/', $q)) {
            return $this->townsByZip($q);
        }

        $cantons = $this->cantons($q);

        return [...$cantons, ...$this->townsByName($q, self::LIMIT - \count($cantons))];
    }

    /**
     * @return list<PlaceSuggestion>
     */
    private function townsByZip(string $prefix): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT id, name, canton_code, zip
                FROM (
                    SELECT DISTINCT ON (t.id) t.id, t.name, t.canton_code, tz.zip, t.population
                    FROM town_zips tz
                    JOIN towns t ON t.id = tz.town_id
                    WHERE tz.zip LIKE :prefix || '%'
                    ORDER BY t.id, tz.zip
                ) matches
                ORDER BY zip = :prefix DESC, population DESC, name
                LIMIT :limit
                SQL,
            ['prefix' => $prefix, 'limit' => self::LIMIT],
        );

        return array_map($this->town(...), $rows);
    }

    /**
     * @return list<PlaceSuggestion>
     */
    private function cantons(string $q): array
    {
        $codes = $this->connection->fetchFirstColumn(
            <<<'SQL'
                WITH q AS (SELECT geo_fold_de(:q) AS de, geo_fold(:q) AS plain)
                SELECT c.code
                FROM cantons c, q
                WHERE q.de <> ''
                  AND (replace(c.slug, '-', ' ') LIKE q.de || '%'
                    OR replace(c.slug, '-', ' ') LIKE q.plain || '%'
                    OR q.de <% replace(c.slug, '-', ' '))
                ORDER BY c.bfs_number
                LIMIT :limit
                SQL,
            ['q' => $q, 'limit' => self::LIMIT],
        );

        return array_map(static function (mixed $code): PlaceSuggestion {
            \assert(\is_string($code));

            return PlaceSuggestion::canton($code);
        }, $codes);
    }

    /**
     * @return list<PlaceSuggestion>
     */
    private function townsByName(string $q, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                WITH q AS (SELECT geo_fold_de(:q) AS de, geo_fold(:q) AS plain)
                SELECT id, name, canton_code, zip
                FROM (
                    SELECT t.id, t.name, t.canton_code, t.zip, t.population,
                        (SELECT count(*) FROM town_zips z WHERE z.town_id = t.id) AS zip_count,
                        char_length(q.de) AS qlen,
                        CASE
                            WHEN geo_fold_de(t.name) LIKE q.de || '%' OR geo_fold(t.name) LIKE q.plain || '%' THEN 0
                            WHEN geo_search_key(t.name) LIKE '% ' || q.de || '%' OR geo_search_key(t.name) LIKE '% ' || q.plain || '%' THEN 1
                            ELSE 2
                        END AS rank
                    FROM towns t, q
                    WHERE q.de <> ''
                      AND (geo_fold_de(t.name) LIKE q.de || '%'
                        OR geo_fold(t.name) LIKE q.plain || '%'
                        OR geo_search_key(t.name) LIKE '% ' || q.de || '%'
                        OR geo_search_key(t.name) LIKE '% ' || q.plain || '%'
                        OR q.de <% geo_search_key(t.name))
                ) matches
                ORDER BY rank, CASE WHEN qlen <= 2 THEN least(zip_count, 3) END DESC NULLS LAST, population DESC, name
                LIMIT :limit
                SQL,
            ['q' => $q, 'limit' => $limit],
        );

        return array_map($this->town(...), $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function town(array $row): PlaceSuggestion
    {
        \assert(\is_string($row['id']));
        \assert(\is_string($row['name']));
        \assert(\is_string($row['canton_code']));
        \assert(\is_string($row['zip']));

        return PlaceSuggestion::town($row['id'], $row['name'], $row['canton_code'], $row['zip']);
    }
}
