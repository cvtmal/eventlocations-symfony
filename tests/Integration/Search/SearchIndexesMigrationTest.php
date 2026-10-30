<?php

declare(strict_types=1);

namespace App\Tests\Integration\Search;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The search migration: the two unmapped expression indexes on venues.
 */
final class SearchIndexesMigrationTest extends KernelTestCase
{
    public function test_expression_indexes_exist(): void
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $indexes = $connection->fetchAllKeyValue("SELECT indexname, indexdef FROM pg_indexes WHERE tablename = 'venues'");

        self::assertArrayHasKey('idx_venues_earth', $indexes);
        self::assertIsString($indexes['idx_venues_earth']);
        self::assertStringContainsString('gist', $indexes['idx_venues_earth']);
        self::assertStringContainsString('ll_to_earth', $indexes['idx_venues_earth']);
        self::assertArrayHasKey('idx_venues_search_key', $indexes);
        self::assertIsString($indexes['idx_venues_search_key']);
        self::assertStringContainsString('gin', $indexes['idx_venues_search_key']);
        self::assertStringContainsString('geo_search_key', $indexes['idx_venues_search_key']);
    }
}
