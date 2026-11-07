<?php

declare(strict_types=1);

namespace App\Tests\Integration\Migrations;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The baseline's down() removes every object its up() creates, and up() brings them back with
 * the reference rows. Later migrations build on the baseline's tables (foreign keys), so they
 * come off first, newest first, and go on last. Postgres DDL is transactional, so everything
 * runs inside the test's transaction and the rollback restores the schema.
 */
final class BaselineMigrationTest extends KernelTestCase
{
    /**
     * Row counts after the whole chain: the baseline seeds 147 taxonomy terms, event-vocabulary (F3)
     * re-curates them to 118.
     */
    private const REFERENCE_ROWS = [
        'cantons' => 27,
        'taxonomy_terms' => 118,
        'crm_activity_types' => 7,
        'crm_deal_stages' => 6,
    ];

    private Connection $connection;

    protected function setUp(): void
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function test_down_removes_everything_and_up_restores_it(): void
    {
        $tables = $this->tables();
        $functions = $this->functions();
        $extensions = $this->extensions();
        $referenceRows = array_map($this->rows(...), array_keys(self::REFERENCE_ROWS));
        self::assertContains('venues', $tables);
        self::assertSame(['geo_fold', 'geo_fold_de', 'geo_search_key'], $functions);

        $this->runMigrations('down');
        self::assertSame(['doctrine_migration_versions'], $this->tables());
        self::assertSame([], $this->functions());
        self::assertSame(['plpgsql'], $this->extensions());

        $this->runMigrations('up');
        self::assertSame($tables, $this->tables());
        self::assertSame($functions, $this->functions());
        self::assertSame($extensions, $this->extensions());
        self::assertSame($referenceRows, array_map($this->rows(...), array_keys(self::REFERENCE_ROWS)));
        foreach (self::REFERENCE_ROWS as $table => $rows) {
            self::assertSame($rows, (int) $this->connection->fetchOne('SELECT count(*) FROM '.$table), $table);
        }
    }

    /**
     * @return list<string>
     */
    private function tables(): array
    {
        return array_map('strval', $this->connection->fetchFirstColumn("SELECT tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY 1"));
    }

    /**
     * Every row of a reference table without its timestamps, in a stable order, so the round trip compares
     * content and not only counts.
     *
     * @return list<string>
     */
    private function rows(string $table): array
    {
        return array_map('strval', $this->connection->fetchFirstColumn(\sprintf("SELECT (to_jsonb(t) - 'created_at' - 'updated_at')::text FROM %s t ORDER BY 1", $table)));
    }

    /**
     * The migration's own geo_* functions; earthdistance brings geo_distance() as an extension member.
     *
     * @return list<string>
     */
    private function functions(): array
    {
        return array_map('strval', $this->connection->fetchFirstColumn(<<<'SQL'
            SELECT p.proname FROM pg_proc p
            WHERE p.proname LIKE 'geo\_%'
              AND NOT EXISTS (SELECT 1 FROM pg_depend d WHERE d.classid = 'pg_proc'::regclass AND d.objid = p.oid AND d.deptype = 'e')
            ORDER BY 1
            SQL));
    }

    /**
     * @return list<string>
     */
    private function extensions(): array
    {
        return array_map('strval', $this->connection->fetchFirstColumn('SELECT extname FROM pg_extension ORDER BY 1'));
    }

    private function runMigrations(string $direction): void
    {
        $files = glob(\dirname(__DIR__, 3).'/migrations/Version*.php') ?: [];
        sort($files);

        foreach ('down' === $direction ? array_reverse($files) : $files as $file) {
            $this->runMigration($file, $direction);
        }
    }

    /**
     * Migrations are loaded by path, not autoloaded, so the class is named from the file and
     * stays out of PHPStan's sight.
     */
    private function runMigration(string $file, string $direction): void
    {
        require_once $file;
        $class = 'DoctrineMigrations\\'.basename($file, '.php');
        $migration = new $class($this->connection, new NullLogger());
        self::assertInstanceOf(AbstractMigration::class, $migration);
        'up' === $direction ? $migration->up(new Schema()) : $migration->down(new Schema());

        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement());
        }
    }
}
