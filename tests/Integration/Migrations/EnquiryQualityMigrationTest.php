<?php

declare(strict_types=1);

namespace App\Tests\Integration\Migrations;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The enquiry-quality migration (G7, D56) against rows it has to carry over: down() drops the four columns, up()
 * backfills a `sent` row as qualified and every other row as unqualified / `not_delivered`. Postgres DDL is
 * transactional, so both directions run inside the test's transaction and the rollback restores the schema.
 */
final class EnquiryQualityMigrationTest extends KernelTestCase
{
    /** Loaded by path, not autoloaded, so the class is named from the file. */
    private const MIGRATION_FILE = 'migrations/Version20261108180917.php';

    private const COLUMNS = ['qualification', 'qualification_override', 'qualification_reason', 'stuck_alerted_at'];

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

    public function test_down_drops_the_columns_and_up_backfills_them_from_the_venue_mail(): void
    {
        $this->connection->executeStatement('DELETE FROM inquiries');
        $this->runMigration('down');
        self::assertSame([], $this->columns());

        $statuses = ['sent', 'failed', 'pending'];
        foreach ($statuses as $index => $status) {
            $this->connection->executeStatement(
                "INSERT INTO inquiries (id, venue_number, venue_name, venue_slug, recipient_email, locale, event_type, date_flexible, guest_count, message, first_name, last_name, email, fingerprint, venue_mail_status)
                 VALUES (?, 1, 'Testhaus', 'testhaus', 'info@testhaus.example', 'de', 'corporate_event', true, 80, 'Hallo', 'Anna', 'Muster', 'anna@example.ch', repeat('a', 64), ?)",
                [\sprintf('019a0000-0000-7000-8000-%012d', $index + 1), $status],
            );
        }

        $this->runMigration('up');
        self::assertSame(self::COLUMNS, $this->columns());
        self::assertSame([
            ['qualification' => 'qualified', 'qualification_reason' => null, 'qualification_override' => null, 'stuck_alerted_at' => null],
            ['qualification' => 'unqualified', 'qualification_reason' => 'not_delivered', 'qualification_override' => null, 'stuck_alerted_at' => null],
            ['qualification' => 'unqualified', 'qualification_reason' => 'not_delivered', 'qualification_override' => null, 'stuck_alerted_at' => null],
        ], $this->connection->fetchAllAssociative('SELECT qualification, qualification_reason, qualification_override, stuck_alerted_at FROM inquiries ORDER BY id'));
    }

    /**
     * @return list<string> those of the G7 columns that `inquiries` has, sorted
     */
    private function columns(): array
    {
        $found = $this->connection->fetchFirstColumn(
            'SELECT column_name FROM information_schema.columns WHERE table_name = ? AND column_name IN (?) ORDER BY column_name',
            ['inquiries', self::COLUMNS],
            [\Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ArrayParameterType::STRING],
        );

        return array_map(strval(...), $found);
    }

    private function runMigration(string $direction): void
    {
        $file = \dirname(__DIR__, 3).'/'.self::MIGRATION_FILE;
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
