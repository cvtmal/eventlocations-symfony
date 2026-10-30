<?php

declare(strict_types=1);

namespace App\Tests\Integration\Geo;

use App\Geo\Entity\Town;
use App\Geo\Repository\TownRepository;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The exact ZIP + name lookup of the content import (#9) against the real towns dataset.
 */
final class TownByZipAndNameTest extends KernelTestCase
{
    use ImportsTownDataset;

    private TownRepository $towns;
    private Connection $connection;

    protected function setUp(): void
    {
        $towns = self::getContainer()->get(TownRepository::class);
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(TownRepository::class, $towns);
        self::assertInstanceOf(Connection::class, $connection);
        $this->towns = $towns;
        $this->connection = $connection;
    }

    /**
     * geo_fold_de() folds German-style (ü → ue), so "zurich" is a different name.
     */
    public function test_finds_the_town_by_zip_and_folded_name(): void
    {
        self::assertSame([], $this->towns->findByZipAndName('8001', 'zurich'));

        foreach (['Zürich', 'zuerich', 'ZÜRICH'] as $name) {
            $found = $this->towns->findByZipAndName('8001', $name);

            self::assertSame(['Zürich'], self::names($found), $name);
            self::assertSame('zh', $found[0]->canton()->code());
        }
    }

    public function test_any_zip_of_the_town_matches_not_only_the_lowest(): void
    {
        self::assertSame(['Zürich'], self::names($this->towns->findByZipAndName('8050', 'Zürich')));
    }

    public function test_an_unknown_name_or_zip_finds_nothing(): void
    {
        self::assertSame([], $this->towns->findByZipAndName('8001', 'Atlantis'));
        self::assertSame([], $this->towns->findByZipAndName('3000', 'Zürich'));
    }

    public function test_the_same_name_in_two_cantons_under_one_zip_is_ambiguous_until_the_canton_is_given(): void
    {
        $this->connection->beginTransaction();
        try {
            $this->connection->executeStatement(
                "INSERT INTO towns (id, canton_code, zip, name, slug, bfs_number, lat, lng, population) VALUES ('019a0000-0000-7000-8000-00000000a001', 'ag', '8001', 'Zürich', 'zuerich-ag', 99001, 47.4, 8.1, 10)",
            );
            $this->connection->executeStatement("INSERT INTO town_zips (zip, town_id) VALUES ('8001', '019a0000-0000-7000-8000-00000000a001')");

            self::assertCount(2, $this->towns->findByZipAndName('8001', 'Zürich'));
            $aargau = $this->towns->findByZipAndName('8001', 'Zürich', 'ag');
            self::assertCount(1, $aargau);
            self::assertSame('ag', $aargau[0]->canton()->code());
        } finally {
            $this->connection->rollBack();
        }
    }

    /**
     * @param list<Town> $towns
     *
     * @return list<string>
     */
    private static function names(array $towns): array
    {
        return array_map(static fn (Town $town): string => $town->name(), $towns);
    }
}
