<?php

declare(strict_types=1);

namespace App\Tests\Integration\Migrations;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The inquiry-event migration (F9) against rows it has to carry over: down() brings `occasion` back and drops the event
 * columns, up() maps every wedding occasion onto an event type and marks a row without a date as flexible, and drops
 * the FAQ answer `venues.own_catering` (D33). Postgres DDL is transactional, so both directions run inside the test's
 * transaction and the rollback restores the schema.
 */
final class InquiryEventMigrationTest extends KernelTestCase
{
    /** Loaded by path, not autoloaded, so the class is named from the file. */
    private const MIGRATION_FILE = 'migrations/Version20261107173925.php';

    private const EVENT_COLUMNS = ['budget_chf', 'catering_wish', 'company', 'date_flexible', 'event_type', 'start_time'];

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

    public function test_down_restores_the_occasion_and_up_maps_it_onto_the_event_types(): void
    {
        $this->connection->executeStatement('DELETE FROM inquiries');
        $this->runMigration('down');
        self::assertSame(['occasion'], $this->columns(['occasion', ...self::EVENT_COLUMNS]));

        $occasions = ['wedding' => '2030-06-15', 'civil_wedding' => null, 'wedding_aperitif' => '2030-07-01', 'engagement' => null, 'other' => '2030-08-01'];
        $index = 0;
        foreach ($occasions as $occasion => $date) {
            $this->connection->executeStatement(
                "INSERT INTO inquiries (id, venue_number, venue_name, venue_slug, recipient_email, locale, occasion, guest_count, event_date, message, first_name, last_name, email, fingerprint)
                 VALUES (?, 1, 'Testhaus', 'testhaus', 'info@testhaus.example', 'de', ?, 80, ?, 'Hallo', 'Anna', 'Muster', 'anna@example.ch', repeat('a', 64))",
                [\sprintf('019a0000-0000-7000-8000-%012d', ++$index), $occasion, $date],
            );
        }

        $this->runMigration('up');
        self::assertSame(self::EVENT_COLUMNS, $this->columns(['occasion', ...self::EVENT_COLUMNS]));
        self::assertSame([
            ['event_type' => 'wedding', 'date_flexible' => false],
            ['event_type' => 'wedding', 'date_flexible' => true],
            ['event_type' => 'wedding', 'date_flexible' => false],
            ['event_type' => 'family_celebration', 'date_flexible' => true],
            ['event_type' => 'wedding', 'date_flexible' => false],
        ], $this->connection->fetchAllAssociative('SELECT event_type, date_flexible FROM inquiries ORDER BY id'));

        $this->connection->executeStatement("UPDATE inquiries SET event_type = 'seminar' WHERE id = '019a0000-0000-7000-8000-000000000005'");
        $this->runMigration('down');
        self::assertSame(
            ['wedding', 'wedding', 'wedding', 'engagement', 'other'],
            $this->connection->fetchFirstColumn('SELECT occasion FROM inquiries ORDER BY id'),
        );
    }

    public function test_up_drops_the_faq_own_catering_and_down_brings_it_back(): void
    {
        $constraint = "SELECT count(*) FROM pg_constraint WHERE conname = 'chk_venues_own_catering'";
        $this->runMigration('down');
        self::assertSame(['own_catering'], $this->columns(['own_catering'], 'venues'));
        self::assertSame(1, $this->connection->fetchOne($constraint));

        $this->runMigration('up');
        self::assertSame([], $this->columns(['own_catering'], 'venues'));
        self::assertSame(0, $this->connection->fetchOne($constraint));
    }

    /**
     * @param list<string> $names
     *
     * @return list<string> those of `$names` that `$table` has, sorted
     */
    private function columns(array $names, string $table = 'inquiries'): array
    {
        $found = $this->connection->fetchFirstColumn(
            'SELECT column_name FROM information_schema.columns WHERE table_name = ? AND column_name IN (?) ORDER BY column_name',
            [$table, $names],
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
