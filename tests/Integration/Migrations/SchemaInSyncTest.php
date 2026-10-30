<?php

declare(strict_types=1);

namespace App\Tests\Integration\Migrations;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaValidator;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The entity mapping and the migrated schema of app_test describe the same database, as
 * `doctrine:schema:validate` checks. Running it in `make test` makes a later migration or
 * entity that breaks the sync fail the suite, not only a manual command.
 */
final class SchemaInSyncTest extends KernelTestCase
{
    /**
     * The migrations bundle hides its own table from the schema tool only inside the
     * doctrine:schema:* console commands, so it shows up here.
     */
    private const MIGRATIONS_TABLE_DROP = 'DROP TABLE doctrine_migration_versions';

    public function test_mapping_is_valid(): void
    {
        self::assertSame([], $this->validator()->validateMapping());
    }

    public function test_database_is_in_sync_with_the_mapping(): void
    {
        $diff = array_values(array_filter(
            $this->validator()->getUpdateSchemaList(),
            static fn (string $sql): bool => self::MIGRATIONS_TABLE_DROP !== $sql,
        ));

        self::assertSame([], $diff);
    }

    private function validator(): SchemaValidator
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return new SchemaValidator($em);
    }
}
