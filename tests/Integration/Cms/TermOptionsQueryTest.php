<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cms;

use App\Cms\ReadModel\TermOption;
use App\Cms\ReadModel\TermOptionsQuery;
use App\Venues\ValueObject\TermGroup;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TermOptionsQueryTest extends KernelTestCase
{
    private TermOptionsQuery $query;
    private Connection $connection;

    protected function setUp(): void
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->query = new TermOptionsQuery($connection);
    }

    public function test_every_group_is_there_in_enum_order(): void
    {
        $byGroup = $this->query->byGroup();

        self::assertSame(array_map(static fn (TermGroup $group): string => $group->value, TermGroup::cases()), array_keys($byGroup));
        self::assertSame(118, array_sum(array_map(\count(...), $byGroup)));
    }

    public function test_each_group_is_in_position_order(): void
    {
        foreach ($this->query->byGroup() as $group => $options) {
            $codes = $this->connection->fetchFirstColumn('SELECT code FROM taxonomy_terms WHERE term_group = ? ORDER BY position', [$group]);

            self::assertSame($codes, array_map(static fn (TermOption $option): string => $option->code, $options), $group);
        }
    }

    public function test_only_building_has_primary_chips_numbered_one_to_eleven(): void
    {
        $primary = [];
        foreach ($this->query->byGroup() as $group => $options) {
            foreach ($options as $option) {
                if (null !== $option->primaryPosition) {
                    $primary[$group][] = $option->primaryPosition;
                }
            }
        }

        self::assertSame([TermGroup::Building->value], array_keys($primary));
        $positions = $primary[TermGroup::Building->value];
        sort($positions);
        self::assertSame(range(1, 11), $positions);
    }

    public function test_it_reads_once_until_reset(): void
    {
        $first = $this->query->byGroup();
        $this->connection->beginTransaction();
        try {
            $this->connection->executeStatement('UPDATE taxonomy_terms SET primary_position = NULL');

            self::assertEquals($first, $this->query->byGroup());
            $this->query->reset();
            self::assertNotEquals($first, $this->query->byGroup());
        } finally {
            $this->connection->rollBack();
        }
    }
}
