<?php

declare(strict_types=1);

namespace App\Tests\Integration\Geo;

use App\Geo\ValueObject\Canton;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The geo migration: seeded cantons, fold functions, expression indexes, constraints.
 */
final class GeoSchemaMigrationTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
    }

    public function test_cantons_table_matches_the_enum(): void
    {
        $rows = $this->connection->fetchAllAssociative('SELECT code, svg_id, slug FROM cantons ORDER BY bfs_number');
        $slugs = array_column($rows, 'slug', 'code');

        self::assertSame(array_map(static fn (Canton $canton): string => $canton->value, Canton::cases()), array_column($rows, 'code'));
        foreach ($rows as $row) {
            self::assertSame('ch-'.$row['code'], $row['svg_id']);
        }
        self::assertSame('zuerich', $slugs['zh']);
        self::assertSame('genf', $slugs['ge']);
    }

    public function test_liechtenstein_is_the_27th_region(): void
    {
        $row = $this->connection->fetchAssociative("SELECT bfs_number, slug, svg_id FROM cantons WHERE code = 'li'");

        self::assertSame(['bfs_number' => 27, 'slug' => 'liechtenstein', 'svg_id' => 'ch-li'], $row);
    }

    public function test_fold_functions(): void
    {
        self::assertSame('zuerich zurich', $this->connection->fetchOne("SELECT geo_search_key('Zürich')"));
        self::assertSame('st gallen', $this->connection->fetchOne("SELECT geo_fold('St. Gallen')"));
        self::assertSame('staefa', $this->connection->fetchOne("SELECT geo_fold_de('Stäfa')"));
    }

    public function test_expression_indexes_exist(): void
    {
        $indexes = $this->connection->fetchAllKeyValue("SELECT indexname, indexdef FROM pg_indexes WHERE tablename = 'towns'");

        self::assertArrayHasKey('idx_towns_search_key', $indexes);
        self::assertIsString($indexes['idx_towns_search_key']);
        self::assertStringContainsString('gin', $indexes['idx_towns_search_key']);
        self::assertStringContainsString('geo_search_key', $indexes['idx_towns_search_key']);
        self::assertArrayHasKey('idx_towns_earth', $indexes);
        self::assertIsString($indexes['idx_towns_earth']);
        self::assertStringContainsString('gist', $indexes['idx_towns_earth']);
        self::assertStringContainsString('ll_to_earth', $indexes['idx_towns_earth']);
    }

    public function test_canton_code_must_be_lower_case(): void
    {
        $this->connection->beginTransaction();

        try {
            $this->connection->executeStatement(
                "INSERT INTO cantons (code, bfs_number, slug, svg_id, centroid_lat, centroid_lng) VALUES ('ZZ', 26, 'zz', 'ch-ZZ', 47.0, 8.0)",
            );
            self::fail('The insert should violate a check constraint.');
        } catch (DriverException $exception) {
            self::assertStringContainsString('chk_cantons_code', $exception->getMessage());
        } finally {
            $this->connection->rollBack();
        }
    }
}
