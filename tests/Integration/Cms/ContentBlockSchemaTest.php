<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cms;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The constraints of content_blocks (#26), proven with raw SQL inside a rolled-back transaction.
 * test_every_constraint_has_a_rejection_case() fails for a new constraint without a case here.
 */
final class ContentBlockSchemaTest extends KernelTestCase
{
    /**
     * Migrations are loaded by path, not autoloaded, so the class is named from the file and
     * stays out of PHPStan's sight.
     */
    private const MIGRATION_FILE = 'migrations/Version20261031070024.php';

    private const INSERT = "INSERT INTO content_blocks (key, locale, body_markdown, updated_at) VALUES ('landing.canton.zh.intro', 'de', 'Ein Text.', CURRENT_TIMESTAMP)";

    private const UPDATE = "UPDATE content_blocks SET %s WHERE key = 'landing.canton.zh.intro' AND locale = 'de'";

    private Connection $connection;

    protected function setUp(): void
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->connection->beginTransaction();
        $this->connection->executeStatement(self::INSERT);
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
        yield 'duplicate key and locale' => ['pk_content_blocks', self::INSERT];
        yield 'key with a capital' => ['chk_content_blocks_key', \sprintf(self::UPDATE, "key = 'Landing.x'")];
        yield 'key without a dot' => ['chk_content_blocks_key', \sprintf(self::UPDATE, "key = 'nodot'")];
        yield 'blank body' => ['chk_content_blocks_body', \sprintf(self::UPDATE, "body_markdown = '  '")];
        yield 'upper-case locale' => ['chk_content_blocks_locale', \sprintf(self::UPDATE, "locale = 'DE'")];
    }

    #[DataProvider('rejections')]
    public function test_constraint_rejects(string $constraint, string $statement): void
    {
        try {
            $this->connection->executeStatement($statement);
            self::fail(\sprintf('The statement should violate %s.', $constraint));
        } catch (DriverException $exception) {
            self::assertStringContainsString('"'.$constraint.'"', $exception->getMessage());
        }
    }

    public function test_valid_values_are_accepted(): void
    {
        self::assertSame(1, (int) $this->connection->executeStatement(\sprintf(self::UPDATE, "key = 'landing.building.brewery_winery.title'")));
        self::assertSame(1, (int) $this->connection->executeStatement("INSERT INTO content_blocks (key, locale, body_markdown, updated_at) VALUES ('landing.town.019a0000-0000-7000-8000-000000000001.description', 'de', 'x', CURRENT_TIMESTAMP)"));
        self::assertSame(1, (int) $this->connection->executeStatement("INSERT INTO content_blocks (key, locale, body_markdown, updated_at) VALUES ('footer.note', 'fr', ' x ', CURRENT_TIMESTAMP)"));
    }

    public function test_updated_at_defaults_to_now_in_whole_seconds(): void
    {
        $this->connection->executeStatement("INSERT INTO content_blocks (key, locale, body_markdown) VALUES ('footer.note', 'de', 'x')");
        self::assertTrue((bool) $this->connection->fetchOne("SELECT updated_at IS NOT NULL FROM content_blocks WHERE key = 'footer.note'"));

        $this->connection->executeStatement(\sprintf(self::UPDATE, "updated_at = '2026-10-31 08:00:00.123456+01'"));

        self::assertSame('000000', $this->connection->fetchOne("SELECT to_char(updated_at, 'US') FROM content_blocks WHERE key = 'landing.canton.zh.intro'"));
    }

    public function test_every_constraint_has_a_rejection_case(): void
    {
        $names = $this->connection->fetchFirstColumn(
            "SELECT conname FROM pg_constraint WHERE conrelid::regclass::text = 'content_blocks' AND contype IN ('c', 'u', 'f', 'p')",
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

    /**
     * Postgres DDL is transactional, so down() and up() run inside the test's transaction and
     * the rollback restores the table.
     */
    public function test_down_reverses_up(): void
    {
        $this->runMigration('down');
        self::assertNull($this->connection->fetchOne("SELECT to_regclass('content_blocks')"));

        $this->runMigration('up');
        self::assertSame('content_blocks', $this->connection->fetchOne("SELECT to_regclass('content_blocks')::text"));
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
