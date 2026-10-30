<?php

declare(strict_types=1);

namespace App\Tests\Integration\Migrations;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The first migration installs the extensions search and distances build on (S1).
 */
final class PostgresExtensionsMigrationTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
    }

    public function test_extensions_are_installed(): void
    {
        $extensions = $this->connection->fetchFirstColumn('SELECT extname FROM pg_extension');

        foreach (['pg_trgm', 'unaccent', 'cube', 'earthdistance'] as $extension) {
            self::assertContains($extension, $extensions);
        }
    }

    public function test_unaccent_folds_umlauts(): void
    {
        self::assertSame('Zurich', $this->connection->fetchOne("SELECT unaccent('Zürich')"));
    }

    public function test_trigram_similarity_tolerates_typos(): void
    {
        $similarity = (float) $this->connection->fetchOne("SELECT similarity('Zuerich', 'Zürich')");

        self::assertGreaterThan(0.2, $similarity);
    }

    public function test_earth_distance_between_zurich_and_bern(): void
    {
        $km = (float) $this->connection->fetchOne('SELECT earth_distance(ll_to_earth(47.3769, 8.5417), ll_to_earth(46.9480, 7.4474)) / 1000');

        self::assertGreaterThan(90.0, $km);
        self::assertLessThan(100.0, $km);
    }
}
