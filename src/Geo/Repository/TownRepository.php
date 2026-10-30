<?php

declare(strict_types=1);

namespace App\Geo\Repository;

use App\Geo\Entity\Town;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Bulk writes for app:geo:import-towns. Plain SQL upserts: ~4k rows, idempotent, and an
 * unchanged row is never touched (updated_at stays).
 *
 * @extends ServiceEntityRepository<Town>
 */
class TownRepository extends ServiceEntityRepository
{
    private const CHUNK = 1000;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Town::class);
    }

    /**
     * Inserts new towns and updates changed ones, keyed on (canton_code, name). The id of an
     * existing town never changes.
     *
     * @param list<array{id: string, canton_code: string, zip: string, name: string, slug: string, bfs_number: int, lat: float, lng: float, population: int}> $rows
     *
     * @return array{inserted: int, updated: int}
     */
    public function upsertTowns(array $rows): array
    {
        $inserted = 0;
        $updated = 0;

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            $values = [];
            $params = [];
            foreach ($chunk as $row) {
                $values[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?)';
                array_push(
                    $params,
                    $row['id'],
                    $row['canton_code'],
                    $row['zip'],
                    $row['name'],
                    $row['slug'],
                    $row['bfs_number'],
                    $row['lat'],
                    $row['lng'],
                    $row['population'],
                );
            }

            $flags = $this->connection()->fetchFirstColumn(
                <<<SQL
                    INSERT INTO towns (id, canton_code, zip, name, slug, bfs_number, lat, lng, population)
                    VALUES {$this->join($values)}
                    ON CONFLICT (canton_code, name) DO UPDATE SET
                        zip = EXCLUDED.zip,
                        slug = EXCLUDED.slug,
                        bfs_number = EXCLUDED.bfs_number,
                        lat = EXCLUDED.lat,
                        lng = EXCLUDED.lng,
                        population = EXCLUDED.population,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE (towns.zip, towns.slug, towns.bfs_number, towns.lat, towns.lng, towns.population)
                        IS DISTINCT FROM (EXCLUDED.zip, EXCLUDED.slug, EXCLUDED.bfs_number, EXCLUDED.lat, EXCLUDED.lng, EXCLUDED.population)
                    RETURNING (xmax = 0) AS inserted
                    SQL,
                $params,
            );

            foreach ($flags as $flag) {
                true === $flag ? ++$inserted : ++$updated;
            }
        }

        return ['inserted' => $inserted, 'updated' => $updated];
    }

    /**
     * @return array<string, string> town id by "<canton_code>|<name>"
     */
    public function townIdsByCantonAndName(): array
    {
        /** @var array<string, string> $ids */
        $ids = $this->connection()->fetchAllKeyValue("SELECT canton_code || '|' || name, id FROM towns");

        return $ids;
    }

    /**
     * @param list<array{zip: string, town_id: string}> $pairs
     *
     * @return int the number of ZIP rows inserted
     */
    public function upsertZips(array $pairs): int
    {
        $added = 0;

        foreach (array_chunk($pairs, self::CHUNK) as $chunk) {
            $values = [];
            $params = [];
            foreach ($chunk as $pair) {
                $values[] = '(?, ?)';
                array_push($params, $pair['zip'], $pair['town_id']);
            }

            $added += (int) $this->connection()->executeStatement(
                "INSERT INTO town_zips (zip, town_id) VALUES {$this->join($values)} ON CONFLICT (zip, town_id) DO NOTHING",
                $params,
            );
        }

        return $added;
    }

    /**
     * Deletes every town_zips row that is not in the dataset.
     *
     * @param list<array{zip: string, town_id: string}> $pairs
     *
     * @return int the number of ZIP rows deleted
     */
    public function deleteZipsNotIn(array $pairs): int
    {
        return (int) $this->connection()->executeStatement(
            <<<'SQL'
                DELETE FROM town_zips tz
                WHERE NOT EXISTS (
                    SELECT 1
                    FROM jsonb_to_recordset(CAST(:pairs AS jsonb)) AS d(zip text, town_id uuid)
                    WHERE d.zip = tz.zip AND d.town_id = tz.town_id
                )
                SQL,
            ['pairs' => json_encode($pairs, \JSON_THROW_ON_ERROR)],
        );
    }

    /**
     * Towns that are no longer in the dataset. They are kept (venues may reference them).
     *
     * @param list<string> $cantonNameKeys "<canton_code>|<name>" of every town in the dataset
     *
     * @return list<string> "<name> (<CANTON>)", sorted
     */
    public function staleTownNames(array $cantonNameKeys): array
    {
        /** @var list<string> $names */
        $names = $this->connection()->fetchFirstColumn(
            <<<'SQL'
                SELECT name || ' (' || upper(canton_code) || ')' AS label
                FROM towns
                WHERE canton_code || '|' || name NOT IN (SELECT jsonb_array_elements_text(CAST(:keys AS jsonb)))
                ORDER BY label
                SQL,
            ['keys' => json_encode($cantonNameKeys, \JSON_THROW_ON_ERROR)],
        );

        return $names;
    }

    public function countZips(): int
    {
        return (int) $this->connection()->fetchOne('SELECT count(*) FROM town_zips');
    }

    private function connection(): Connection
    {
        return $this->getEntityManager()->getConnection();
    }

    /**
     * @param list<string> $values
     */
    private function join(array $values): string
    {
        return implode(', ', $values);
    }
}
