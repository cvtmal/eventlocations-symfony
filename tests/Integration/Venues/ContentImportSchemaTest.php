<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The constraints of content_imports (#9) and venue_deleted_content_keys (#24), proven with
 * raw SQL inside a rolled-back transaction. test_every_constraint_has_a_rejection_case() fails for a new constraint
 * without a case here.
 */
final class ContentImportSchemaTest extends KernelTestCase
{
    private const TABLES = ['content_imports', 'venue_deleted_content_keys'];

    private const ID = '019a0000-0000-7000-8000-000000000901';

    private const INSERT = <<<'SQL'
        INSERT INTO content_imports (
            id, source_path, only_key, pruned, forced, overwrote_cms_edits, venues_seen, venues_created,
            venues_updated, venues_unchanged, venues_failed, venues_unpublished, venues_cms_edited,
            photos_added, photos_removed, documents_added, documents_removed, errors, started_at, finished_at
        ) VALUES (
            '{id}', '/content', NULL, FALSE, FALSE, FALSE, 2, 1, 0, 0, 1, 0, 0, 3, 0, 1, 0,
            '[{"key": "b-venue", "messages": ["unknown town"]}]', '2026-10-30 10:00:00+01', '2026-10-30 10:01:00+01'
        )
        SQL;

    private const INSERT_DELETED_KEY = "INSERT INTO venue_deleted_content_keys (id, content_key) VALUES ('{id}', 'schloss-beispiel-1093')";

    private Connection $connection;

    protected function setUp(): void
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->connection->beginTransaction();
        $this->sql(self::INSERT);
        $this->sql(self::INSERT_DELETED_KEY);
    }

    protected function tearDown(): void
    {
        $this->connection->rollBack();
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function rejections(): iterable
    {
        yield 'duplicate id' => ['pk_content_imports', self::INSERT];
        yield 'negative seen' => ['chk_content_imports_counts', "UPDATE content_imports SET venues_seen = -1 WHERE id = '{id}'"];
        yield 'negative removed documents' => ['chk_content_imports_counts', "UPDATE content_imports SET documents_removed = -1 WHERE id = '{id}'"];
        yield 'finished before started' => ['chk_content_imports_finished_at', "UPDATE content_imports SET finished_at = started_at - INTERVAL '1 second' WHERE id = '{id}'"];
        yield 'errors an object' => ['chk_content_imports_errors', "UPDATE content_imports SET errors = '{}' WHERE id = '{id}'"];
        yield 'only key not kebab case' => ['chk_content_imports_only_key', "UPDATE content_imports SET only_key = 'Schloss Beispiel' WHERE id = '{id}'"];
        yield 'negative CMS-edited count' => ['chk_content_imports_venues_cms_edited', "UPDATE content_imports SET venues_cms_edited = -1 WHERE id = '{id}'"];
        yield 'duplicate deleted key id' => ['pk_venue_deleted_content_keys', "INSERT INTO venue_deleted_content_keys (id, content_key) VALUES ('{id}', 'other-key')"];
        yield 'deleted key twice' => ['uk_venue_deleted_content_keys_content_key', "INSERT INTO venue_deleted_content_keys (id, content_key) VALUES (gen_random_uuid(), 'schloss-beispiel-1093')"];
        yield 'deleted key not kebab case' => ['chk_venue_deleted_content_keys_content_key', "UPDATE venue_deleted_content_keys SET content_key = 'Schloss_Beispiel' WHERE id = '{id}'"];
    }

    #[DataProvider('rejections')]
    public function test_constraint_rejects(string $constraint, string $statement): void
    {
        try {
            $this->sql($statement);
            self::fail(\sprintf('The statement should violate %s.', $constraint));
        } catch (DriverException $exception) {
            self::assertStringContainsString('"'.$constraint.'"', $exception->getMessage());
        }
    }

    public function test_valid_values_are_accepted(): void
    {
        self::assertSame(1, $this->sql("UPDATE content_imports SET only_key = 'schloss-beispiel-1093', errors = '[]', finished_at = started_at WHERE id = '{id}'"));
        self::assertSame(1, $this->sql("UPDATE content_imports SET venues_seen = 0, venues_created = 0, venues_failed = 0, photos_added = 0, documents_added = 0 WHERE id = '{id}'"));
        self::assertSame(1, $this->sql("UPDATE content_imports SET overwrote_cms_edits = TRUE, venues_cms_edited = 3 WHERE id = '{id}'"));
        self::assertSame(1, $this->sql("UPDATE venue_deleted_content_keys SET content_key = 'a-1' WHERE id = '{id}'"));
    }

    public function test_every_constraint_has_a_rejection_case(): void
    {
        $tables = "'".implode("', '", self::TABLES)."'";
        $names = $this->connection->fetchFirstColumn(
            "SELECT conname FROM pg_constraint WHERE conrelid::regclass::text IN ({$tables}) AND contype IN ('c', 'u', 'f', 'p')",
        );
        $tested = array_values(array_unique(array_map(static fn (array $case): string => $case[0], iterator_to_array(self::rejections()))));

        self::assertNotEmpty($names);
        foreach ($names as $name) {
            self::assertContains($name, $tested, $name.' has no rejection case');
        }
        foreach ($tested as $name) {
            self::assertContains($name, $names, $name.' is not a constraint of these tables');
        }
    }

    private function sql(string $sql): int
    {
        return (int) $this->connection->executeStatement(strtr($sql, ['{id}' => self::ID]));
    }
}
