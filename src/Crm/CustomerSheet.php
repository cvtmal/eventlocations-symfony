<?php

declare(strict_types=1);

namespace App\Crm;

use App\Crm\Exception\InvalidCustomerSheet;
use App\Crm\ValueObject\CustomerRow;
use App\Crm\ValueObject\SheetRegion;
use App\Crm\ValueObject\SheetText;

/**
 * Reads the owner's customer sheet (#48): UTF-8, comma-separated, CRLF, 13 columns, built like
 * `Venues\WixExport`. The header is taken verbatim, quirks included ("Phone numbeer", the trailing space of
 * "Mail gesendet am ", "Notitz"); `Neue` is not needed and ignored.
 */
final class CustomerSheet
{
    public const string NAME = 'Company name';
    public const string STATUS = 'Contact Status';
    public const string SALUTATION = 'Anrede';
    public const string FIRST_NAME = 'Vorname';
    public const string LAST_NAME = 'Nachname';
    public const string WEBSITE = 'Website';
    public const string EMAIL = 'Email';
    public const string PHONE = 'Phone numbeer';
    public const string INSTAGRAM = 'Instagram URL';
    public const string MAIL_SENT = 'Mail gesendet am ';
    public const string NOTE = 'Notitz';
    public const string REGION = 'State/Region';

    /** The columns the import reads. */
    public const array COLUMNS = [
        self::NAME, self::STATUS, self::SALUTATION, self::FIRST_NAME, self::LAST_NAME, self::WEBSITE,
        self::EMAIL, self::PHONE, self::INSTAGRAM, self::MAIL_SENT, self::NOTE, self::REGION,
    ];

    private const string BOM = "\u{FEFF}";

    /**
     * @return \Generator<int, CustomerRow> by the row's number as a spreadsheet shows it (the header is row 1)
     *
     * @throws InvalidCustomerSheet on the first read: a shifted row would put values into the wrong
     *                              fields, so it fails the file
     */
    public static function rows(string $path): \Generator
    {
        $handle = is_file($path) && is_readable($path) ? fopen($path, 'r') : false;
        if (false === $handle) {
            throw InvalidCustomerSheet::unreadable($path);
        }

        try {
            // Before fgetcsv: a BOM in front of an opening quote would make the quotes part of the value.
            if (self::BOM !== fread($handle, \strlen(self::BOM))) {
                rewind($handle);
            }
            $header = fgetcsv($handle, null, ',', '"', '');
            if (false === $header || [null] === $header) {
                throw InvalidCustomerSheet::missingColumns(self::COLUMNS);
            }
            $header = array_map(strval(...), $header);
            $missing = array_values(array_diff(self::COLUMNS, $header));
            if ([] !== $missing) {
                throw InvalidCustomerSheet::missingColumns($missing);
            }

            $number = 1;
            while (false !== $fields = fgetcsv($handle, null, ',', '"', '')) {
                ++$number;
                if ([null] === $fields) {
                    continue; // a blank line, usually the file's last
                }
                if (\count($fields) !== \count($header)) {
                    throw InvalidCustomerSheet::malformedRow($number);
                }

                yield $number => self::row($number, array_combine($header, array_map(strval(...), $fields)));
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param array<string, string> $cells
     */
    private static function row(int $number, array $cells): CustomerRow
    {
        $cell = static function (string $column) use ($cells): ?string {
            $text = SheetText::clean($cells[$column]);

            return '' === $text ? null : $text;
        };

        return new CustomerRow(
            number: $number,
            name: $cell(self::NAME),
            status: $cell(self::STATUS),
            salutation: $cell(self::SALUTATION),
            firstName: $cell(self::FIRST_NAME),
            lastName: $cell(self::LAST_NAME),
            website: $cell(self::WEBSITE),
            email: $cell(self::EMAIL),
            phone: $cell(self::PHONE),
            instagram: $cell(self::INSTAGRAM),
            mailSent: $cell(self::MAIL_SENT),
            note: $cell(self::NOTE),
            region: SheetRegion::fromSheet($cells[self::REGION]),
        );
    }
}
