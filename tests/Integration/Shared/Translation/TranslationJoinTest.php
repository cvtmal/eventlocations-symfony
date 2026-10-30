<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared\Translation;

use App\Shared\Translation\EnabledLocale;
use App\Shared\Translation\TranslationJoin;
use App\Tests\Support\Translation\KernelLocales;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The per-row fallback of TranslationJoin against PostgreSQL, on two temporary tables
 * shaped like the content-translation convention.
 */
final class TranslationJoinTest extends KernelTestCase
{
    private const OWNER_A = '0195e5a4-0000-7000-8000-00000000000a';
    private const OWNER_B = '0195e5a4-0000-7000-8000-00000000000b';

    private Connection $connection;
    private TranslationJoin $join;

    protected function setUp(): void
    {
        $container = self::getContainer();

        $connection = $container->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;

        // Not fetched from the container: nothing injects it yet, so it is removed on compile.
        $this->join = new TranslationJoin(new EnabledLocale(KernelLocales::enabled($container), KernelLocales::default($container)));

        $this->dropTables();
        $this->connection->executeStatement('CREATE TEMP TABLE probe_owners (id uuid PRIMARY KEY)');
        $this->connection->executeStatement(<<<'SQL'
            CREATE TEMP TABLE probe_owner_translations (
                id uuid PRIMARY KEY,
                probe_owner_id uuid NOT NULL REFERENCES probe_owners(id),
                locale varchar(2) NOT NULL,
                title text NOT NULL,
                CONSTRAINT uk_probe_owner_translations_probe_owner_id_locale UNIQUE (probe_owner_id, locale)
            )
            SQL);

        $this->connection->insert('probe_owners', ['id' => self::OWNER_A]);
        $this->connection->insert('probe_owners', ['id' => self::OWNER_B]);
        $this->connection->insert('probe_owner_translations', [
            'id' => '0195e5a4-0000-7000-8000-0000000000a1',
            'probe_owner_id' => self::OWNER_A,
            'locale' => 'de',
            'title' => 'Seeblick',
        ]);
        $this->connection->insert('probe_owner_translations', [
            'id' => '0195e5a4-0000-7000-8000-0000000000a2',
            'probe_owner_id' => self::OWNER_A,
            'locale' => 'fr',
            'title' => 'Vue sur le lac',
        ]);
    }

    protected function tearDown(): void
    {
        $this->dropTables();
        parent::tearDown();
    }

    public function test_requested_locale_wins(): void
    {
        self::assertSame('Vue sur le lac', $this->title(self::OWNER_A, 'fr'));
    }

    public function test_missing_locale_falls_back_to_german(): void
    {
        self::assertSame('Seeblick', $this->title(self::OWNER_A, 'it'));
    }

    public function test_german_returns_german(): void
    {
        self::assertSame('Seeblick', $this->title(self::OWNER_A, 'de'));
    }

    public function test_owner_without_translations_is_kept_with_null(): void
    {
        self::assertNull($this->title(self::OWNER_B, 'de'));
    }

    private function title(string $ownerId, string $locale): ?string
    {
        $sql = 'SELECT o.id, t.title FROM probe_owners o '
            .$this->join->localized('probe_owner_translations', 'probe_owner_id', 'o.id', 't')
            .' WHERE o.id = :id';

        $row = $this->connection->fetchAssociative($sql, ['id' => $ownerId] + $this->join->parameters($locale));
        self::assertIsArray($row, 'The owner row must be returned.');

        $title = $row['title'];
        self::assertTrue(null === $title || \is_string($title));

        return $title;
    }

    private function dropTables(): void
    {
        $this->connection->executeStatement('DROP TABLE IF EXISTS probe_owner_translations');
        $this->connection->executeStatement('DROP TABLE IF EXISTS probe_owners');
    }
}
