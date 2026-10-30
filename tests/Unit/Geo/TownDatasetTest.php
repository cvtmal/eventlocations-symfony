<?php

declare(strict_types=1);

namespace App\Tests\Unit\Geo;

use App\Geo\Exception\InvalidTownDataset;
use App\Geo\ValueObject\Canton;
use App\Geo\ValueObject\TownDataset;
use App\Geo\ValueObject\TownRecord;
use PHPUnit\Framework\TestCase;

final class TownDatasetTest extends TestCase
{
    private const HEADER = 'zip,name,canton_code,bfs_number,lat,lng,population';
    private const CSV = __DIR__.'/../../../data/geo/towns.csv';

    public function test_parses_bom_and_crlf(): void
    {
        $csv = "\u{FEFF}".self::HEADER."\r\n8712,Stäfa,ZH,158,47.240000,8.723000,14800\r\n";

        $records = TownDataset::fromCsvString($csv)->records();

        self::assertCount(1, $records);
        self::assertSame('8712', $records[0]->zip);
        self::assertSame('Stäfa', $records[0]->name);
        self::assertSame(Canton::ZH, $records[0]->canton);
        self::assertSame(158, $records[0]->bfsNumber);
        self::assertSame(47.24, $records[0]->lat);
        self::assertSame(8.723, $records[0]->lng);
        self::assertSame(14800, $records[0]->population);
        self::assertSame(['8712'], $records[0]->zips);
    }

    public function test_rejects_an_unexpected_header(): void
    {
        $this->expectException(InvalidTownDataset::class);

        TownDataset::fromCsvString("zip,name\n8712,Stäfa\n");
    }

    public function test_reports_the_line_of_an_invalid_zip(): void
    {
        $csv = self::HEADER."\n8712,Stäfa,ZH,158,47.24,8.72,14800\n871,Uerikon,ZH,158,47.23,8.75,14800\n";

        $this->expectException(InvalidTownDataset::class);
        $this->expectExceptionMessage('line 3');

        TownDataset::fromCsvString($csv);
    }

    public function test_rejects_an_unknown_canton(): void
    {
        $this->expectException(InvalidTownDataset::class);
        $this->expectExceptionMessage('unknown canton "FL"');

        TownDataset::fromCsvString(self::HEADER."\n9490,Vaduz,FL,7001,47.14,9.52,5700\n");
    }

    public function test_collapse_keeps_the_lowest_zip_and_all_zips(): void
    {
        $csv = self::HEADER."\n"
            ."8004,Zürich,ZH,261,47.370000,8.520000,436551\n"
            ."8001,Zürich,ZH,261,47.372000,8.540000,436551\n"
            ."2333,La Cibourg,BE,443,47.120000,6.890000,4000\n"
            ."2300,La Cibourg,NE,6421,47.110000,6.880000,37000\n";

        $towns = TownDataset::fromCsvString($csv)->collapsed()->records();

        self::assertCount(3, $towns);
        $zurich = $this->find($towns, Canton::ZH, 'Zürich');
        self::assertSame('8001', $zurich->zip);
        self::assertSame(47.372, $zurich->lat);
        self::assertSame(8.54, $zurich->lng);
        self::assertSame(['8001', '8004'], $zurich->zips);
        self::assertSame('2333', $this->find($towns, Canton::BE, 'La Cibourg')->zip);
        self::assertSame('2300', $this->find($towns, Canton::NE, 'La Cibourg')->zip);
    }

    public function test_real_dataset(): void
    {
        $dataset = TownDataset::fromCsvFile(self::CSV);
        $towns = $dataset->collapsed()->records();

        self::assertCount(4057, $dataset->records());
        self::assertCount(3959, $towns);
        self::assertCount(26, array_unique(array_map(static fn (TownRecord $t): string => $t->canton->value, $towns)));
        self::assertSame(4057, array_sum(array_map(static fn (TownRecord $t): int => \count($t->zips), $towns)));
        self::assertSame('8712', $this->find($towns, Canton::ZH, 'Stäfa')->zip);
        self::assertCount(24, $this->find($towns, Canton::ZH, 'Zürich')->zips);
    }

    public function test_missing_file_is_unreadable(): void
    {
        $this->expectException(InvalidTownDataset::class);

        TownDataset::fromCsvFile('/nonexistent/towns.csv');
    }

    /**
     * @param list<TownRecord> $towns
     */
    private function find(array $towns, Canton $canton, string $name): TownRecord
    {
        foreach ($towns as $town) {
            if ($town->canton === $canton && $town->name === $name) {
                return $town;
            }
        }
        self::fail(\sprintf('Town %s (%s) not found.', $name, $canton->value));
    }
}
