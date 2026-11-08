<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The constraints of `last_minute_offers` (G14, D55), proven against the real schema with raw SQL in the style of
 * ModerationCaseSchemaTest: one rejection case per constraint, the shapes they let through, what a deleted room or
 * venue does to its offers, and the migration's own `down()` and `up()`.
 */
final class LastMinuteOfferSchemaTest extends KernelTestCase
{
    private const string MIGRATION_FILE = 'migrations/Version20261108174229.php';

    private const array IDS = [
        '{town}' => '019a0000-0000-7000-8000-000000000001',
        '{venue}' => '019a0000-0000-7000-8000-000000000101',
        '{room}' => '019a0000-0000-7000-8000-000000000201',
        '{offer}' => '019a0000-0000-7000-8000-000000000301',
        '{missing}' => '019a0000-0000-7000-8000-000000000999',
    ];

    /** A venue with a room, and a one-day offer of that room. */
    private const array SEED = [
        "INSERT INTO towns (id, canton_code, zip, name, slug, bfs_number, lat, lng, population) VALUES ('{town}', 'zh', '8999', 'Testdorf Frei', 'testdorf-frei', 9996, 47.3, 8.5, 100)",
        "INSERT INTO venues (id, name, status, capacity_max, zip, town_id, canton_code, lat, lng) VALUES ('{venue}', 'Testhaus', 'published', 100, '8999', '{town}', 'zh', 47.3, 8.5)",
        "INSERT INTO rooms (id, venue_id, position) VALUES ('{room}', '{venue}', 1)",
        "INSERT INTO last_minute_offers (id, venue_id, room_id, starts_on, ends_on, price_text) VALUES ('{offer}', '{venue}', '{room}', '2026-11-20', '2026-11-20', 'Ab CHF 89 pro Person')",
    ];

    private Connection $connection;

    protected function setUp(): void
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->connection->beginTransaction();

        foreach (self::SEED as $sql) {
            $this->sql($sql);
        }
    }

    protected function tearDown(): void
    {
        $this->connection->rollBack();
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function rejections(): iterable
    {
        $update = static fn (string $set): string => 'UPDATE last_minute_offers SET '.$set." WHERE id = '{offer}'";
        $insert = static fn (string $id, string $venue, string $room): string => "INSERT INTO last_minute_offers (id, venue_id, room_id, starts_on, ends_on, price_text) VALUES ('{$id}', '{$venue}', {$room}, '2026-11-20', '2026-11-20', 'x')";

        yield 'offer id taken' => ['pk_last_minute_offers', [$insert('{offer}', '{venue}', 'NULL')]];
        yield 'offer of an unknown venue' => ['fk_last_minute_offers_venue_id', [$insert('{missing}', '{missing}', 'NULL')]];
        yield 'offer of an unknown room' => ['fk_last_minute_offers_room_id', [$insert('{missing}', '{venue}', "'{missing}'")]];
        yield 'last day before the first' => ['chk_last_minute_offers_period', [$update("ends_on = '2026-11-19'")]];
        yield 'a period of fifteen days' => ['chk_last_minute_offers_length', [$update("ends_on = '2026-12-04'")]];
        yield 'an empty price' => ['chk_last_minute_offers_price', [$update("price_text = ''")]];
        yield 'a blank price' => ['chk_last_minute_offers_price', [$update("price_text = '   '")]];
        yield 'blank terms' => ['chk_last_minute_offers_terms', [$update("terms = ' '")]];
        yield 'no guests' => ['chk_last_minute_offers_guests', [$update('min_guests = 0')]];
        yield 'too many guests' => ['chk_last_minute_offers_guests', [$update('min_guests = 5001')]];
        yield 'event types as an object' => ['chk_last_minute_offers_event_types', [$update("event_types = '{\"a\": 1}'")]];
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function acceptances(): iterable
    {
        $update = static fn (string $set): string => 'UPDATE last_minute_offers SET '.$set." WHERE id = '{offer}'";

        yield 'a period of fourteen days' => [[$update("ends_on = '2026-12-03'")]];
        yield 'the whole location' => [[$update('room_id = NULL')]];
        yield 'guests, terms and event types' => [[$update("min_guests = 5000, terms = 'Nur abends.', event_types = '[\"seminar\", \"party\"]'")]];
    }

    /**
     * @param list<string> $statements
     */
    #[DataProvider('rejections')]
    public function test_constraint_rejects(string $constraint, array $statements): void
    {
        $last = array_pop($statements);
        self::assertNotNull($last);
        foreach ($statements as $sql) {
            $this->sql($sql);
        }

        try {
            $this->sql($last);
            self::fail(\sprintf('The statement should violate %s.', $constraint));
        } catch (DriverException $exception) {
            self::assertStringContainsString('"'.$constraint.'"', $exception->getMessage());
        }
    }

    /**
     * @param list<string> $statements
     */
    #[DataProvider('acceptances')]
    public function test_constraints_accept(array $statements): void
    {
        foreach ($statements as $sql) {
            self::assertGreaterThan(0, $this->sql($sql), $sql);
        }
    }

    public function test_every_constraint_has_a_rejection_case(): void
    {
        $names = $this->connection->fetchFirstColumn(
            "SELECT conname FROM pg_constraint WHERE conrelid::regclass::text = 'last_minute_offers' AND contype IN ('c', 'u', 'f', 'p')",
        );
        $tested = array_values(array_unique(array_map(static fn (array $case): string => $case[0], iterator_to_array(self::rejections()))));

        self::assertCount(9, $names, 'a primary key, two foreign keys and six checks');
        foreach ($names as $name) {
            self::assertContains($name, $tested, $name.' has no rejection case');
        }
        foreach ($tested as $name) {
            self::assertContains($name, $names, $name.' is not a constraint of the schema');
        }
    }

    public function test_a_new_offer_has_no_event_types_and_both_timestamps(): void
    {
        self::assertSame(
            ['event_types' => '[]', 'has_created' => true, 'has_updated' => true],
            $this->connection->fetchAssociative("SELECT event_types, created_at IS NOT NULL AS has_created, updated_at IS NOT NULL AS has_updated FROM last_minute_offers WHERE id = '".self::IDS['{offer}']."'"),
        );
    }

    public function test_deleting_the_room_deletes_its_offer(): void
    {
        $this->sql("DELETE FROM rooms WHERE id = '{room}'");

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM last_minute_offers'));
    }

    public function test_deleting_the_venue_deletes_its_offers(): void
    {
        $this->sql("UPDATE last_minute_offers SET room_id = NULL WHERE id = '{offer}'");
        $this->sql("DELETE FROM venues WHERE id = '{venue}'");

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM last_minute_offers'));
    }

    public function test_the_migration_goes_down_and_up_again(): void
    {
        $this->migrate('down');
        self::assertNull($this->connection->fetchOne("SELECT to_regclass('public.last_minute_offers')"));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM rooms WHERE venue_id = '".self::IDS['{venue}']."'"), 'down() leaves the rooms');

        $this->migrate('up');

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM last_minute_offers'));
    }

    private function sql(string $sql): int
    {
        return (int) $this->connection->executeStatement(strtr($sql, self::IDS));
    }

    /**
     * Runs this cycle's migration in the test's transaction (Postgres DDL is transactional, so the rollback restores
     * the schema). Migrations are loaded by path, not autoloaded.
     */
    private function migrate(string $direction): void
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
