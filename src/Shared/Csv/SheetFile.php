<?php

declare(strict_types=1);

namespace App\Shared\Csv;

/**
 * Reads a sheet the owner keeps in a spreadsheet and saves as CSV (F14: the venue sheet, the contact sheet): `,` or
 * `;` (found from the header line), with or without a byte order mark, columns found by name. A missing or unknown
 * column refuses the whole file. Cells are trimmed; a `'` before a formula sign is the guard a CSV export writes
 * (`'+41…`, `CsvStream`) and is dropped; rows with no value at all are skipped. Line numbers count the header as 1.
 */
final class SheetFile
{
    private function __construct()
    {
    }

    /**
     * @param list<string> $header the sheet's columns
     * @param string       $what   the sheet's name for the error ("venue sheet")
     *
     * @return list<array{line: int, cells: array<string, string>}>
     *
     * @throws \RuntimeException when the file is missing or its header is not the sheet's
     */
    public static function read(string $file, array $header, string $what): array
    {
        $handle = is_file($file) ? fopen($file, 'r') : false;
        if (false === $handle) {
            throw new \RuntimeException(\sprintf('No sheet at "%s".', $file));
        }

        try {
            $first = (string) fgets($handle);
            $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
            rewind($handle);

            $names = fgetcsv($handle, null, $delimiter, '"', '');
            $columns = [];
            foreach (\is_array($names) ? $names : [] as $index => $name) {
                $name = trim((string) $name);
                // A spreadsheet may save the file with a byte order mark.
                $columns[0 === $index ? (string) preg_replace('/\A\xEF\xBB\xBF/', '', $name) : $name] = $index;
            }
            $missing = array_diff($header, array_keys($columns));
            if ([] !== $missing) {
                throw new \RuntimeException(\sprintf('"%s" is not a %s: column %s is missing.', $file, $what, implode(', ', $missing)));
            }
            $unknown = array_diff(array_keys($columns), $header);
            if ([] !== $unknown) {
                throw new \RuntimeException(\sprintf('"%s" is not a %s: column %s is unknown.', $file, $what, implode(', ', $unknown)));
            }

            $rows = [];
            $line = 1;
            while (false !== ($cells = fgetcsv($handle, null, $delimiter, '"', ''))) {
                ++$line;
                if ([null] === $cells) {
                    continue;
                }
                $values = [];
                foreach ($header as $column) {
                    $values[$column] = (string) preg_replace('/\A\'(?=[=+\-@])/', '', trim((string) ($cells[$columns[$column]] ?? '')));
                }
                if ('' !== implode('', $values)) {
                    $rows[] = ['line' => $line, 'cells' => $values];
                }
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }
}
