<?php

declare(strict_types=1);

namespace App\Tests\Integration\Migrations;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The multi-inquiry migration (F13) against rows it has to keep: down() drops the brief table and both columns, up()
 * adds them back with every inquiry a single one and every venue taking briefs. Postgres DDL is transactional, so
 * both directions run inside the test's transaction and the rollback restores the schema.
 */
final class MultiInquiryMigrationTest extends KernelTestCase
{
    /** Loaded by path, not autoloaded, so the class is named from the file. */
    private const MIGRATION_FILE = 'migrations/Version20261109103651.php';

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

    public function test_down_then_up_keeps_the_inquiries_as_single_ones(): void
    {
        $this->connection->executeStatement('DELETE FROM inquiries');
        $this->connection->executeStatement(
            "INSERT INTO inquiries (id, venue_number, venue_name, venue_slug, recipient_email, locale, event_type, date_flexible, guest_count, message, first_name, last_name, email, fingerprint)
             VALUES ('019a0000-0000-7000-8000-000000000001', 1, 'Testhaus', 'testhaus', 'info@testhaus.example', 'de', 'corporate_event', true, 80, 'Hallo', 'Anna', 'Muster', 'anna@example.ch', repeat('a', 64))",
        );

        $this->runMigration('down');

        self::assertFalse($this->tableExists());
        self::assertFalse($this->columnExists('inquiries', 'brief_id'));
        self::assertFalse($this->columnExists('venues', 'accepts_multi_inquiries'));

        $this->runMigration('up');

        self::assertTrue($this->tableExists());
        self::assertSame(
            [['first_name' => 'Anna', 'brief_id' => null]],
            $this->connection->fetchAllAssociative('SELECT first_name, brief_id FROM inquiries'),
        );
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM venues WHERE NOT accepts_multi_inquiries'));
    }

    private function tableExists(): bool
    {
        return false !== $this->connection->fetchOne("SELECT 1 FROM information_schema.tables WHERE table_name = 'inquiry_briefs'");
    }

    private function columnExists(string $table, string $column): bool
    {
        return false !== $this->connection->fetchOne('SELECT 1 FROM information_schema.columns WHERE table_name = ? AND column_name = ?', [$table, $column]);
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
