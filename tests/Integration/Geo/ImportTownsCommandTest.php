<?php

declare(strict_types=1);

namespace App\Tests\Integration\Geo;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ImportTownsCommandTest extends KernelTestCase
{
    use ImportsTownDataset;

    private Connection $connection;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->tester = new CommandTester((new Application($kernel))->find('app:geo:import-towns'));
    }

    public function test_dataset_is_imported_collapsed(): void
    {
        self::assertSame(3972, (int) $this->connection->fetchOne('SELECT count(*) FROM towns'));
        self::assertSame(4070, (int) $this->connection->fetchOne('SELECT count(*) FROM town_zips'));

        $stafa = $this->connection->fetchAssociative("SELECT zip, slug FROM towns WHERE canton_code = 'zh' AND name = 'Stäfa'");
        self::assertSame(['zip' => '8712', 'slug' => 'staefa'], $stafa);

        $zurich = $this->connection->fetchAssociative(
            "SELECT t.slug, count(z.zip) AS zips FROM towns t JOIN town_zips z ON z.town_id = t.id WHERE t.canton_code = 'zh' AND t.name = 'Zürich' GROUP BY t.slug",
        );
        self::assertIsArray($zurich);
        self::assertSame('zuerich', $zurich['slug']);
        self::assertSame(24, (int) $zurich['zips']);
    }

    public function test_second_import_changes_nothing(): void
    {
        $before = $this->connection->fetchOne('SELECT max(updated_at) FROM towns');

        self::assertSame(Command::SUCCESS, $this->tester->execute([]));

        $output = $this->tester->getDisplay();
        self::assertStringContainsString('0 inserted, 0 updated, 3972 unchanged', $output);
        self::assertStringContainsString('4070 total, 0 added, 0 removed', $output);
        self::assertSame($before, $this->connection->fetchOne('SELECT max(updated_at) FROM towns'));
    }

    public function test_import_repairs_changes_and_keeps_stale_towns(): void
    {
        $this->connection->beginTransaction();

        try {
            $this->connection->executeStatement("DELETE FROM towns WHERE canton_code = 'sg' AND name = 'Jona'");
            $this->connection->executeStatement("UPDATE towns SET population = population + 1 WHERE canton_code = 'zh' AND name = 'Stäfa'");
            $this->connection->executeStatement(
                "DELETE FROM town_zips WHERE zip = '8002' AND town_id = (SELECT id FROM towns WHERE canton_code = 'zh' AND name = 'Zürich')",
            );
            $this->connection->executeStatement(
                "INSERT INTO towns (id, canton_code, zip, name, slug, bfs_number, lat, lng, population)
                 VALUES (gen_random_uuid(), 'zh', '8999', 'Testdorf', 'testdorf', 9999, 47.3, 8.5, 1)",
            );

            self::assertSame(Command::SUCCESS, $this->tester->execute([]));

            $output = $this->tester->getDisplay();
            self::assertStringContainsString('1 inserted, 1 updated', $output);
            self::assertStringContainsString('2 added, 0 removed', $output);
            self::assertStringContainsString('1 stale towns kept: Testdorf (ZH)', $output);
            self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM towns WHERE name = 'Testdorf'"));
            self::assertSame(
                '8645',
                $this->connection->fetchOne("SELECT z.zip FROM town_zips z JOIN towns t ON t.id = z.town_id WHERE t.canton_code = 'sg' AND t.name = 'Jona'"),
            );
        } finally {
            $this->connection->rollBack();
        }
    }

    public function test_missing_file_fails(): void
    {
        self::assertSame(Command::FAILURE, $this->tester->execute(['path' => '/nonexistent/towns.csv']));
        self::assertStringContainsString('not a readable file', $this->tester->getDisplay());
    }
}
