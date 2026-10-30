<?php

declare(strict_types=1);

namespace App\Venues;

use App\Venues\Exception\InvalidWixExport;

/**
 * Reads the owner's Wix CSV (Q12): UTF-8 with a BOM, comma-separated, with line breaks inside
 * fields, so it cannot be split by line. #32 reads the same file through this class.
 */
final class WixExport
{
    /** The columns #21 and #32 rely on; the file has more. */
    public const array COLUMNS = ['Name', 'Region', 'Kapazität', 'Ambiente', 'Vorstellungstext', 'Telefon', 'Website', 'Email', 'ID'];

    /** #32 needs four more; #21 must not start requiring them. */
    public const array OWNER_COLUMNS = [...self::COLUMNS, 'Typ', 'Preisniveau', 'Anzeigebild', 'Öffnungszeiten'];

    private const string BOM = "\u{FEFF}";

    /**
     * @param list<string> $columns the columns the caller needs
     *
     * @return \Generator<int, array<string, string>> the rows by column name, 1-based
     *
     * @throws InvalidWixExport on the first read: a shifted row would put values into the
     *                          wrong fields, so it fails the file
     */
    public static function rows(string $path, array $columns = self::COLUMNS): \Generator
    {
        $handle = is_file($path) && is_readable($path) ? fopen($path, 'r') : false;
        if (false === $handle) {
            throw InvalidWixExport::unreadable($path);
        }

        try {
            // Before fgetcsv: the file quotes its first column, and a BOM in front of the opening
            // quote would make the quotes part of the value.
            if (self::BOM !== fread($handle, \strlen(self::BOM))) {
                rewind($handle);
            }
            $header = fgetcsv($handle, null, ',', '"', '');
            if (false === $header) {
                throw InvalidWixExport::missingColumns($columns);
            }
            $header = array_map(strval(...), $header);
            $missing = array_values(array_diff($columns, $header));
            if ([] !== $missing) {
                throw InvalidWixExport::missingColumns($missing);
            }

            $row = 0;
            while (false !== $fields = fgetcsv($handle, null, ',', '"', '')) {
                if ([null] === $fields) {
                    continue; // a blank line, usually the file's last
                }
                ++$row;
                if (\count($fields) !== \count($header)) {
                    throw InvalidWixExport::malformedRow($row);
                }
                yield $row => array_combine($header, array_map(strval(...), $fields));
            }
        } finally {
            fclose($handle);
        }
    }
}
