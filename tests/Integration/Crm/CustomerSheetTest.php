<?php

declare(strict_types=1);

namespace App\Tests\Integration\Crm;

use App\Crm\CustomerSheet;
use App\Crm\Exception\InvalidCustomerSheet;
use App\Tests\Support\Crm\CustomerSheetCsv;
use App\Tests\Support\Media\MediaFiles;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * `CustomerSheet::rows()` (#48) on fictional sheets in the shape of the owner's: the verbatim header,
 * CRLF, cleaned cells, and a whole file failed by one shifted row.
 */
final class CustomerSheetTest extends KernelTestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = self::bootKernel()->getProjectDir().'/var/test-customer-sheet-'.uniqid();
        mkdir($this->directory, 0777, true);
    }

    protected function tearDown(): void
    {
        MediaFiles::removeDirectory($this->directory);
        parent::tearDown();
    }

    public function test_the_header_of_the_owners_sheet_passes_and_cells_are_read(): void
    {
        $file = $this->file([CustomerSheetCsv::row(), CustomerSheetCsv::row(['Company name' => 'Zweiter Hof'])]);
        self::assertStringContainsString("\r\n", (string) file_get_contents($file));

        $rows = iterator_to_array(CustomerSheet::rows($file));

        self::assertSame([2, 3], array_keys($rows));
        $row = $rows[2];
        self::assertSame(2, $row->number);
        self::assertSame('Landgut Erfunden, Beispielwil', $row->name);
        self::assertSame('keine Antwort', $row->status);
        self::assertSame('Frau', $row->salutation);
        self::assertSame('Erika', $row->firstName);
        self::assertSame('Fiktiv', $row->lastName);
        self::assertSame('https://landgut-erfunden.example.ch', $row->website);
        self::assertSame('info@landgut-erfunden.example.ch', $row->email);
        self::assertSame('062 000 00 00', $row->phone);
        self::assertSame('https://www.instagram.com/landgut.erfunden.example/', $row->instagram);
        self::assertSame('04.01.2024, 11:38', $row->mailSent);
        self::assertSame('keine Antwort', $row->note);
        self::assertSame(['ag'], $row->region->codes());
        self::assertSame('Zweiter Hof', $rows[3]->name);
    }

    public function test_a_bom_does_not_reach_the_first_column(): void
    {
        $rows = iterator_to_array(CustomerSheet::rows($this->file([CustomerSheetCsv::row()], bom: true)));

        self::assertSame('Landgut Erfunden, Beispielwil', $rows[2]->name);
    }

    public function test_cells_are_cleaned_and_empty_cells_are_null(): void
    {
        $file = $this->file([CustomerSheetCsv::row([
            'Company name' => " Froschhof, Erfundenwil\u{200B} ",
            'Anrede' => '',
            'Vorname' => "\u{00A0}",
            'Phone numbeer' => "\u{202D}062\u{202F}000 00 00",
            'State/Region' => '',
        ])]);

        $row = iterator_to_array(CustomerSheet::rows($file))[2];

        self::assertSame('Froschhof, Erfundenwil', $row->name);
        self::assertNull($row->salutation);
        self::assertNull($row->firstName);
        self::assertSame('062 000 00 00', $row->phone);
        self::assertFalse($row->region->isKnown());
    }

    public function test_a_field_may_span_lines(): void
    {
        $file = $this->file([CustomerSheetCsv::row(['Notitz' => "Erste Zeile\r\nZweite Zeile"]), CustomerSheetCsv::row(['Company name' => 'Zweiter Hof'])]);

        $rows = iterator_to_array(CustomerSheet::rows($file));

        self::assertSame('Erste Zeile Zweite Zeile', $rows[2]->note);
        self::assertSame('Zweiter Hof', $rows[3]->name);
    }

    public function test_neue_is_not_required(): void
    {
        $file = $this->file([CustomerSheetCsv::row()], columns: array_values(array_diff(CustomerSheetCsv::COLUMNS, ['Neue'])));

        self::assertCount(1, iterator_to_array(CustomerSheet::rows($file)));
    }

    public function test_a_missing_column_is_named_verbatim(): void
    {
        $file = $this->file([CustomerSheetCsv::row()], columns: array_values(array_diff(CustomerSheetCsv::COLUMNS, ['Mail gesendet am ', 'Notitz'])));

        $this->expectException(InvalidCustomerSheet::class);
        $this->expectExceptionMessage('"Mail gesendet am ", "Notitz"');

        iterator_to_array(CustomerSheet::rows($file));
    }

    public function test_a_header_without_the_trailing_space_does_not_pass(): void
    {
        $columns = array_map(static fn (string $column): string => trim($column), CustomerSheetCsv::COLUMNS);
        $file = $this->file([], columns: $columns);

        $this->expectException(InvalidCustomerSheet::class);
        $this->expectExceptionMessage('"Mail gesendet am "');

        iterator_to_array(CustomerSheet::rows($file));
    }

    public function test_a_row_with_12_fields_fails_the_file_and_names_its_row(): void
    {
        $file = $this->file([CustomerSheetCsv::row()]);
        file_put_contents($file, implode(',', array_fill(0, 12, 'x'))."\r\n", \FILE_APPEND);

        $this->expectException(InvalidCustomerSheet::class);
        $this->expectExceptionMessage('Row 3 ');

        iterator_to_array(CustomerSheet::rows($file));
    }

    public function test_a_blank_last_line_is_skipped(): void
    {
        $file = $this->file([CustomerSheetCsv::row()]);
        file_put_contents($file, "\r\n", \FILE_APPEND);

        $rows = iterator_to_array(CustomerSheet::rows($file));

        self::assertSame([2], array_keys($rows));
    }

    public function test_an_empty_file_lacks_every_column(): void
    {
        $file = $this->directory.'/leer.csv';
        touch($file);

        $this->expectException(InvalidCustomerSheet::class);
        $this->expectExceptionMessage('"Company name"');

        iterator_to_array(CustomerSheet::rows($file));
    }

    public function test_a_missing_file_is_unreadable(): void
    {
        $this->expectException(InvalidCustomerSheet::class);
        $this->expectExceptionMessage('cannot be read');

        iterator_to_array(CustomerSheet::rows($this->directory.'/fehlt.csv'));
    }

    /**
     * @param list<array<string, string>> $rows
     * @param list<string>                $columns
     */
    private function file(array $rows, bool $bom = false, array $columns = CustomerSheetCsv::COLUMNS): string
    {
        $file = $this->directory.'/kunden.csv';
        CustomerSheetCsv::write($file, $rows, $bom, $columns);

        return $file;
    }
}
