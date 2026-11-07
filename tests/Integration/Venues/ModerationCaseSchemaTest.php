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
 * The constraints of `moderation_cases` and `moderation_case_photos` (G2), proven against the real schema with raw
 * SQL in the style of MediaRightsGrantSchemaTest: one rejection case per constraint, the shapes they must let
 * through, what a deleted photo, venue or case does to the rows, and the migration's own `down()` and `up()`.
 */
final class ModerationCaseSchemaTest extends KernelTestCase
{
    private const array TABLES = ['moderation_cases', 'moderation_case_photos'];

    private const string MIGRATION_FILE = 'migrations/Version20261107193615.php';

    private const array IDS = [
        '{town}' => '019a0000-0000-7000-8000-000000000001',
        '{venue}' => '019a0000-0000-7000-8000-000000000101',
        '{photo}' => '019a0000-0000-7000-8000-000000000401',
        '{photo2}' => '019a0000-0000-7000-8000-000000000402',
        '{case}' => '019a0000-0000-7000-8000-000000000601',
        '{case2}' => '019a0000-0000-7000-8000-000000000602',
        '{link}' => '019a0000-0000-7000-8000-000000000701',
        '{missing}' => '019a0000-0000-7000-8000-000000000999',
    ];

    private const string CASE_COLUMNS = 'id, venue_id, venue_reference, kind, status, complainant_name, complainant_email, message, good_faith_at';

    /**
     * One venue with two photos, an open rights case about it naming the first photo, and an open correction case
     * that names a location the site does not know.
     */
    private const array SEED = [
        "INSERT INTO towns (id, canton_code, zip, name, slug, bfs_number, lat, lng, population) VALUES ('{town}', 'zh', '8999', 'Testdorf Meldung', 'testdorf-meldung', 9997, 47.3, 8.5, 100)",
        "INSERT INTO venues (id, name, tier, status, capacity_max, zip, town_id, canton_code, lat, lng) VALUES ('{venue}', 'Testhaus', 'premium', 'published', 100, '8999', '{town}', 'zh', 47.3, 8.5)",
        "INSERT INTO venue_photos (id, venue_id, room_id, position, original_path, width, height, dominant_color, variants) VALUES ('{photo}', '{venue}', NULL, 1, 'demo/a.jpg', 1600, 1067, '#b9a58c', '{\"widths\": [360], \"formats\": [\"jpg\"]}'), ('{photo2}', '{venue}', NULL, 2, 'demo/b.jpg', 1600, 1067, '#b9a58c', '{\"widths\": [360], \"formats\": [\"jpg\"]}')",
        "INSERT INTO moderation_cases ({case-cols}) VALUES ('{case}', '{venue}', NULL, 'rights', 'open', 'Erika', 'erika@example.ch', 'Mein Foto.', now()), ('{case2}', NULL, 'Gasthaus Beispiel', 'correction', 'open', 'Max', 'max@example.ch', 'Falsch.', now())",
        "INSERT INTO moderation_case_photos (id, case_id, venue_photo_id, original_path) VALUES ('{link}', '{case}', '{photo}', 'demo/a.jpg')",
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
     * Statements that must fail on the named constraint; only the last statement of a case is expected to fail.
     *
     * @return iterable<string, array{string, list<string>}>
     */
    public static function rejections(): iterable
    {
        $update = static fn (string $set): string => 'UPDATE moderation_cases SET '.$set." WHERE id = '{case}'";
        $insert = static fn (string $values): string => "INSERT INTO moderation_cases ({case-cols}) VALUES ('{missing}', ".$values.', now())';

        yield 'case id taken' => ['pk_moderation_cases', [str_replace("'{missing}'", "'{case2}'", $insert("NULL, 'x', 'correction', 'open', 'A', 'a@example.ch', 'm'"))]];
        yield 'case of an unknown venue' => ['fk_moderation_cases_venue_id', [$insert("'{missing}', NULL, 'correction', 'open', 'A', 'a@example.ch', 'm'")]];
        yield 'unknown kind' => ['chk_moderation_cases_kind', [$update("kind = 'spam'")]];
        yield 'kind in upper case' => ['chk_moderation_cases_kind', [$update("kind = 'Rights'")]];
        yield 'unknown status' => ['chk_moderation_cases_status', [$update("status = 'closed'")]];
        yield 'unknown decision' => ['chk_moderation_cases_decision', [$update("status = 'decided', decision = 'delete', decided_at = now(), decided_by = 's-1'")]];
        yield 'decided without a decision' => ['chk_moderation_cases_decided', [$update("status = 'decided', decided_at = now(), decided_by = 's-1'")]];
        yield 'a decision on an open case' => ['chk_moderation_cases_decided', [$update("decision = 'keep', decided_at = now(), decided_by = 's-1'")]];
        yield 'decided without a time' => ['chk_moderation_cases_decided', [$update("status = 'decided', decision = 'keep', decided_by = 's-1'")]];
        yield 'decided by nobody' => ['chk_moderation_cases_decided', [$update("status = 'decided', decision = 'keep', decided_at = now()")]];
        yield 'a note without a decision' => ['chk_moderation_cases_decided', [$update("decision_note = 'Notiz'")]];
        yield 'a typed reference beside a known venue' => ['chk_moderation_cases_venue_reference', [$update("venue_reference = 'Testhaus'")]];
        yield 'an address without an at sign' => ['chk_moderation_cases_email', [$update("complainant_email = 'erika.example.ch'")]];
        yield 'an address with a space' => ['chk_moderation_cases_email', [$update("complainant_email = 'erika @example.ch'")]];
        yield 'a source on another host' => ['chk_moderation_cases_source_url', [$update("source_url = '//evil.example/x'")]];
        yield 'a source that is a full URL' => ['chk_moderation_cases_source_url', [$update("source_url = 'https://evil.example/x'")]];
        yield 'a source with a backslash' => ['chk_moderation_cases_source_url', [$update("source_url = '/\\evil.example'")]];
        yield 'link id taken' => ['pk_moderation_case_photos', ["INSERT INTO moderation_case_photos (id, case_id, venue_photo_id, original_path) VALUES ('{link}', '{case2}', NULL, 'demo/x.jpg')"]];
        yield 'link of an unknown case' => ['fk_moderation_case_photos_case_id', ["INSERT INTO moderation_case_photos (id, case_id, venue_photo_id, original_path) VALUES ('{missing}', '{missing}', NULL, 'demo/x.jpg')"]];
        yield 'link of an unknown photo' => ['fk_moderation_case_photos_venue_photo_id', ["INSERT INTO moderation_case_photos (id, case_id, venue_photo_id, original_path) VALUES ('{missing}', '{case}', '{missing}', 'demo/x.jpg')"]];
        yield 'the same photo twice in a case' => ['uk_moderation_case_photos_case_photo', ["INSERT INTO moderation_case_photos (id, case_id, venue_photo_id, original_path) VALUES ('{missing}', '{case}', '{photo}', 'demo/a.jpg')"]];
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function acceptances(): iterable
    {
        $update = static fn (string $set): string => 'UPDATE moderation_cases SET '.$set." WHERE id = '{case}'";

        yield 'decided keep with a note' => [[$update("status = 'decided', decision = 'keep', decided_at = now(), decided_by = '019a0000-0000-7000-8000-0000000000de', decision_note = 'Belegt.'")]];
        yield 'decided remove without a note' => [[$update("status = 'decided', decision = 'remove', decided_at = now(), decided_by = 's-1'")]];
        yield 'every kind' => [[$update("kind = 'rights'"), $update("kind = 'removal'"), $update("kind = 'correction'")]];
        yield 'the home page as source' => [[$update("source_url = '/'")]];
        yield 'a detail page with a query as source' => [[$update("source_url = '/hochzeitslocation/testhaus?x=1'")]];
        yield 'neither a venue nor a reference (the venue was deleted)' => [["UPDATE moderation_cases SET venue_id = NULL WHERE id = '{case}'"]];
        yield 'the same photo in two cases' => [["INSERT INTO moderation_case_photos (id, case_id, venue_photo_id, original_path) VALUES ('{missing}', '{case2}', '{photo}', 'demo/a.jpg')"]];
        yield 'two links of removed photos in one case' => [[
            "INSERT INTO moderation_case_photos (id, case_id, venue_photo_id, original_path) VALUES ('{missing}', '{case}', NULL, 'demo/a.jpg')",
            "INSERT INTO moderation_case_photos (id, case_id, venue_photo_id, original_path) VALUES ('019a0000-0000-7000-8000-000000000998', '{case}', NULL, 'demo/a.jpg')",
        ]];
        yield 'took the entry offline' => [[$update('took_entry_offline = TRUE')]];
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
        $tables = "'".implode("', '", self::TABLES)."'";
        $constraints = $this->connection->fetchFirstColumn(
            "SELECT conname FROM pg_constraint WHERE conrelid::regclass::text IN ({$tables}) AND contype IN ('c', 'u', 'f', 'p')",
        );
        $uniqueIndexes = $this->connection->fetchFirstColumn("SELECT indexname FROM pg_indexes WHERE tablename IN ({$tables}) AND indexname LIKE 'uk\\_%'");
        $names = array_values(array_unique([...$constraints, ...$uniqueIndexes]));
        $tested = array_values(array_unique(array_map(static fn (array $case): string => $case[0], iterator_to_array(self::rejections()))));

        self::assertCount(13, $names, 'two primary keys, three foreign keys, seven checks and the unique photo per case');
        foreach ($names as $name) {
            self::assertContains($name, $tested, $name.' has no rejection case');
        }
        foreach ($tested as $name) {
            self::assertContains($name, $names, $name.' is not a constraint of the schema');
        }
    }

    public function test_a_new_case_has_not_taken_the_entry_offline(): void
    {
        $row = $this->connection->fetchAssociative("SELECT took_entry_offline, decision, created_at IS NOT NULL AS has_created FROM moderation_cases WHERE id = '".self::IDS['{case}']."'");

        self::assertSame(['took_entry_offline' => false, 'decision' => null, 'has_created' => true], $row);
    }

    public function test_deleting_a_photo_keeps_the_link_and_its_file_name(): void
    {
        $this->sql("DELETE FROM venue_photos WHERE id = '{photo}'");

        self::assertSame(
            ['venue_photo_id' => null, 'original_path' => 'demo/a.jpg'],
            $this->connection->fetchAssociative("SELECT venue_photo_id, original_path FROM moderation_case_photos WHERE id = '".self::IDS['{link}']."'"),
        );
    }

    public function test_deleting_a_venue_keeps_its_cases(): void
    {
        $this->sql("DELETE FROM venues WHERE id = '{venue}'");

        self::assertNull($this->connection->fetchOne("SELECT venue_id FROM moderation_cases WHERE id = '".self::IDS['{case}']."'"));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM moderation_case_photos'), 'the link stays, without its photo');
    }

    public function test_deleting_a_case_deletes_its_links(): void
    {
        $this->sql("DELETE FROM moderation_cases WHERE id = '{case}'");

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM moderation_case_photos'));
    }

    public function test_the_migration_goes_down_and_up_again(): void
    {
        $this->migrate('down');
        self::assertNull($this->connection->fetchOne("SELECT to_regclass('public.moderation_cases')"));
        self::assertNull($this->connection->fetchOne("SELECT to_regclass('public.moderation_case_photos')"));
        self::assertSame(2, (int) $this->connection->fetchOne("SELECT count(*) FROM venue_photos WHERE venue_id = '".self::IDS['{venue}']."'"), 'down() leaves the photos');

        $this->migrate('up');

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM moderation_cases'));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM moderation_case_photos'));
    }

    private function sql(string $sql): int
    {
        return (int) $this->connection->executeStatement(strtr($sql, [...self::IDS, '{case-cols}' => self::CASE_COLUMNS]));
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
