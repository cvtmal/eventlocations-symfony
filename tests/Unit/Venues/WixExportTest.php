<?php

declare(strict_types=1);

namespace App\Tests\Unit\Venues;

use App\Tests\Support\Content\WixCsv;
use App\Tests\Support\Media\MediaFiles;
use App\Venues\Exception\InvalidWixExport;
use App\Venues\WixExport;
use PHPUnit\Framework\TestCase;

final class WixExportTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = \dirname(__DIR__, 3).'/var/test-wix-'.uniqid();
        mkdir($this->directory, 0777, true);
    }

    protected function tearDown(): void
    {
        MediaFiles::removeDirectory($this->directory);
    }

    public function test_the_bom_does_not_reach_the_first_column(): void
    {
        $file = $this->file([WixCsv::row()]);

        $rows = iterator_to_array(WixExport::rows($file));

        self::assertSame('Landgasthof Beispielhof', $rows[1]['Name']);
        self::assertCount(20, $rows[1]);
    }

    public function test_a_file_without_a_bom_is_read_from_its_start(): void
    {
        $file = $this->file([WixCsv::row()]);
        file_put_contents($file, substr((string) file_get_contents($file), 3));

        self::assertSame('Landgasthof Beispielhof', iterator_to_array(WixExport::rows($file))[1]['Name']);
    }

    public function test_a_field_may_span_lines(): void
    {
        $file = $this->file([WixCsv::row(['Ambiente' => "Erste Zeile\nZweite Zeile"]), WixCsv::row(['Name' => 'Zweiter Hof'])]);

        $rows = iterator_to_array(WixExport::rows($file));

        self::assertCount(2, $rows);
        self::assertSame("Erste Zeile\nZweite Zeile", $rows[1]['Ambiente']);
        self::assertSame('Zweiter Hof', $rows[2]['Name']);
    }

    public function test_a_missing_column_is_named(): void
    {
        $file = $this->file([WixCsv::row()], array_values(array_diff(WixCsv::COLUMNS, ['Region', 'ID'])));

        $this->expectException(InvalidWixExport::class);
        $this->expectExceptionMessage('Region, ID');

        iterator_to_array(WixExport::rows($file));
    }

    public function test_the_owner_columns_need_typ_and_the_legacy_columns_do_not(): void
    {
        $file = $this->file([WixCsv::row()], array_values(array_diff(WixCsv::COLUMNS, ['Typ'])));

        self::assertCount(1, iterator_to_array(WixExport::rows($file)));

        $this->expectException(InvalidWixExport::class);
        $this->expectExceptionMessage('Typ');

        iterator_to_array(WixExport::rows($file, WixExport::OWNER_COLUMNS));
    }

    public function test_a_row_with_too_few_fields_fails_the_file(): void
    {
        $file = $this->file([WixCsv::row()]);
        file_put_contents($file, "nur,zwei\n", \FILE_APPEND);

        $this->expectException(InvalidWixExport::class);
        $this->expectExceptionMessage('Row 2 has the wrong number of fields');

        iterator_to_array(WixExport::rows($file));
    }

    public function test_an_unreadable_file_fails(): void
    {
        $this->expectException(InvalidWixExport::class);

        iterator_to_array(WixExport::rows($this->directory.'/fehlt.csv'));
    }

    /**
     * @param list<array<string, string>> $rows
     * @param list<string>                $columns
     */
    private function file(array $rows, array $columns = WixCsv::COLUMNS): string
    {
        $path = $this->directory.'/locations.csv';
        WixCsv::write($path, $rows, $columns);

        return $path;
    }
}
