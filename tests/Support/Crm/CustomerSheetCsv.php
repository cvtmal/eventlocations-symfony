<?php

declare(strict_types=1);

namespace App\Tests\Support\Crm;

/**
 * Builds a fictional customer sheet at runtime, in the shape of the owner's (#48): 13 columns with the
 * header's quirks, CRLF, no BOM unless asked. The repo keeps no real row: every name, address and number
 * here is invented.
 */
final class CustomerSheetCsv
{
    public const array COLUMNS = [
        'Company name', 'Contact Status', 'Anrede', 'Vorname', 'Nachname', 'Website', 'Email',
        'Phone numbeer', 'Instagram URL', 'Mail gesendet am ', 'Notitz', 'State/Region', 'Neue',
    ];

    /**
     * One filled row of a fictional venue in canton Aargau, with $overrides.
     *
     * @param array<string, string> $overrides by column
     *
     * @return array<string, string>
     */
    public static function row(array $overrides = []): array
    {
        return $overrides + [
            'Company name' => 'Landgut Erfunden, Beispielwil',
            'Contact Status' => 'keine Antwort',
            'Anrede' => 'Frau',
            'Vorname' => 'Erika',
            'Nachname' => 'Fiktiv',
            'Website' => 'https://landgut-erfunden.example.ch',
            'Email' => 'info@landgut-erfunden.example.ch',
            'Phone numbeer' => '062 000 00 00',
            'Instagram URL' => 'https://www.instagram.com/landgut.erfunden.example/',
            'Mail gesendet am ' => '04.01.2024, 11:38',
            'Notitz' => 'keine Antwort',
            'State/Region' => 'Aargau',
            'Neue' => '',
        ];
    }

    /**
     * Writes the file: an optional BOM, a header of $columns, the rows, CRLF line ends.
     *
     * @param list<array<string, string>> $rows
     * @param list<string>                $columns
     */
    public static function write(string $path, array $rows, bool $bom = false, array $columns = self::COLUMNS): void
    {
        if (!is_dir(\dirname($path))) {
            mkdir(\dirname($path), 0777, true);
        }
        $handle = fopen($path, 'w');
        \assert(false !== $handle);
        if ($bom) {
            fwrite($handle, "\u{FEFF}");
        }
        fputcsv($handle, $columns, ',', '"', '', "\r\n");
        foreach ($rows as $row) {
            fputcsv($handle, array_map(static fn (string $column): string => $row[$column] ?? '', $columns), ',', '"', '', "\r\n");
        }
        fclose($handle);
    }
}
