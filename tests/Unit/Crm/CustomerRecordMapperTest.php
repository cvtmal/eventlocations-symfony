<?php

declare(strict_types=1);

namespace App\Tests\Unit\Crm;

use App\Crm\CustomerRecordMapper;
use App\Crm\ValueObject\CustomerNoteField;
use App\Crm\ValueObject\CustomerRecord;
use App\Crm\ValueObject\CustomerRow;
use App\Crm\ValueObject\SheetRegion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The field rules of #48 R6–R8 on fictional rows.
 */
final class CustomerRecordMapperTest extends TestCase
{
    private CustomerRecordMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new CustomerRecordMapper();
    }

    public function test_a_full_row_maps_to_company_contact_dates_and_one_note_block(): void
    {
        $record = $this->map(self::row());

        self::assertSame('Landgut Erfunden, Beispielwil', $record->name);
        self::assertSame('https://landgut-erfunden.example.ch', $record->website);
        self::assertSame('info@landgut-erfunden.example.ch', $record->email);
        self::assertSame('062 000 00 00', $record->phone);
        self::assertSame('Erika', $record->firstName);
        self::assertSame('Fiktiv', $record->lastName);
        self::assertSame(['2024-01-04 11:38 Europe/Zurich'], self::dates($record));
        self::assertSame([2], $record->rows);
        self::assertSame([], $record->warnings);
        self::assertSame([[
            'row' => 2,
            'lines' => [
                [CustomerNoteField::Status, 'Zusage'],
                [CustomerNoteField::Salutation, 'Frau'],
                [CustomerNoteField::Region, 'Aargau'],
                [CustomerNoteField::Instagram, 'https://www.instagram.com/landgut.erfunden.example/'],
                [CustomerNoteField::Note, 'Anfrage | Rückruf'],
            ],
        ]], $record->noteBlocks);
    }

    public function test_the_final_name_is_the_company_name(): void
    {
        self::assertSame('Landgut Erfunden (Aargau)', $this->map(self::row(), 'Landgut Erfunden (Aargau)')->name);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function websites(): iterable
    {
        yield 'no scheme, a tracking parameter' => ['schloss-liebegg.ch/x?gclid=1&a=2', 'https://schloss-liebegg.ch/x?a=2'];
        yield 'only tracking parameters' => ['https://hof.example.ch/?utm_source=x&utm_medium=y&gbraid=z&wbraid=w', 'https://hof.example.ch/'];
        yield 'every tracking name' => ['https://hof.example.ch/?fbclid=1&msclkid=2&_gl=3&mc_cid=4&mc_eid=5&UTM_Campaign=6&seite=7', 'https://hof.example.ch/?seite=7'];
        yield 'a fragment stays' => ['https://hof.example.ch/a?gclid=1#saal', 'https://hof.example.ch/a#saal'];
        yield 'whitespace removed' => [' https://hof .example.ch/ a ', 'https://hof.example.ch/a'];
        yield 'an upper-case scheme' => ['HTTPS://hof.example.ch', 'https://hof.example.ch'];
        yield 'http stays http' => ['http://hof.example.ch', 'http://hof.example.ch'];
    }

    #[DataProvider('websites')]
    public function test_a_website_is_canonical(string $cell, string $expected): void
    {
        $record = $this->map(self::row(['website' => $cell]));

        self::assertSame($expected, $record->website);
        self::assertSame([], $record->warnings);
        self::assertNull(self::noteLine($record, CustomerNoteField::Website));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badWebsites(): iterable
    {
        yield 'no website' => ['keine Website'];
        yield 'too long' => ['https://hof.example.ch/'.str_repeat('a', 480)];
        yield 'another scheme' => ['ftp://hof.example.ch'];
    }

    #[DataProvider('badWebsites')]
    public function test_a_bad_website_is_dropped_into_the_note(string $cell): void
    {
        $record = $this->map(self::row(['website' => $cell]));

        self::assertNull($record->website);
        self::assertSame([['row' => 2, 'field' => 'website', 'reason' => 'invalid website']], $record->warnings);
        self::assertSame($cell, self::noteLine($record, CustomerNoteField::Website));
    }

    public function test_of_two_addresses_the_first_is_used_and_the_second_noted(): void
    {
        $record = $this->map(self::row(['email' => 'Info@Hof-Erfunden.example.ch / anlass@hof-erfunden.example.ch']));

        self::assertSame('info@hof-erfunden.example.ch', $record->email);
        self::assertSame('anlass@hof-erfunden.example.ch', self::noteLine($record, CustomerNoteField::Email));
        self::assertSame([], $record->warnings);
    }

    public function test_the_first_valid_part_is_used(): void
    {
        $record = $this->map(self::row(['email' => 'info(at)hof.example.ch; anlass@hof.example.ch']));

        self::assertSame('anlass@hof.example.ch', $record->email);
        self::assertSame('info(at)hof.example.ch', self::noteLine($record, CustomerNoteField::Email));
    }

    public function test_an_invalid_email_is_dropped_with_a_warning(): void
    {
        $record = $this->map(self::row(['email' => 'info@hof-erfunden']));

        self::assertNull($record->email);
        self::assertSame([['row' => 2, 'field' => 'email', 'reason' => 'invalid email']], $record->warnings);
        self::assertSame('info@hof-erfunden', self::noteLine($record, CustomerNoteField::Email));
    }

    public function test_a_non_ascii_local_part_is_refused_as_the_editor_does(): void
    {
        self::assertNull($this->map(self::row(['email' => 'zoë@hof.example.ch']))->email);
    }

    public function test_two_phones_give_the_first_and_note_the_cell(): void
    {
        $record = $this->map(self::row(['phone' => '062 000 00 01 & 079 000 00 02']));

        self::assertSame('062 000 00 01', $record->phone);
        self::assertSame('062 000 00 01 & 079 000 00 02', self::noteLine($record, CustomerNoteField::Phone));
        self::assertSame([], $record->warnings);
    }

    public function test_phones_joined_by_oder_split_too(): void
    {
        self::assertSame('+41 62 000 00 01', $this->map(self::row(['phone' => '+41 62 000 00 01 oder 079 000 00 02']))->phone);
    }

    public function test_a_phone_with_dots_fits_whole(): void
    {
        $record = $this->map(self::row(['phone' => '062.000.00.01']));

        self::assertSame('062.000.00.01', $record->phone);
        self::assertNull(self::noteLine($record, CustomerNoteField::Phone));
    }

    public function test_a_bad_phone_is_dropped_with_a_warning(): void
    {
        $record = $this->map(self::row(['phone' => 'Tel. auf Anfrage']));

        self::assertNull($record->phone);
        self::assertSame([['row' => 2, 'field' => 'phone', 'reason' => 'invalid phone']], $record->warnings);
        self::assertSame('Tel. auf Anfrage', self::noteLine($record, CustomerNoteField::Phone));
    }

    /**
     * @return iterable<string, array{string, list<string>, bool}>
     */
    public static function mailCells(): iterable
    {
        yield 'two dates with times' => ['04.01.2024, 11:38 & 22.07.2024, 18:06', ['2024-01-04 11:38 Europe/Zurich', '2024-07-22 18:06 Europe/Zurich'], false];
        yield 'a colon before the time' => ['24.07.2023: 10:08', ['2023-07-24 10:08 Europe/Zurich'], false];
        yield 'a trailing comma, no time' => ['22.08.2024,', ['2024-08-22 00:00 Europe/Zurich'], false];
        yield 'one-digit day and month' => ['4.1.2024', ['2024-01-04 00:00 Europe/Zurich'], false];
        yield 'out of order and repeated' => ['22.07.2024, 18:06 + 04.01.2024, 11:38 & 22.07.2024, 18:06', ['2024-01-04 11:38 Europe/Zurich', '2024-07-22 18:06 Europe/Zurich'], false];
        yield 'free text' => ['Via Insta geschrieben', [], true];
        yield 'a date and free text' => ['04.01.2024 nochmals nachfragen', ['2024-01-04 00:00 Europe/Zurich'], true];
    }

    /**
     * @param list<string> $dates
     */
    #[DataProvider('mailCells')]
    public function test_mail_dates_are_read_in_zurich(string $cell, array $dates, bool $noted): void
    {
        $record = $this->map(self::row(['mailSent' => $cell]));

        self::assertSame($dates, self::dates($record));
        self::assertSame($noted ? $cell : null, self::noteLine($record, CustomerNoteField::MailSent));
        self::assertSame([], $record->warnings);
    }

    public function test_a_summer_date_keeps_its_wall_time(): void
    {
        $record = $this->map(self::row(['mailSent' => '22.07.2024, 18:06']));

        self::assertSame('2024-07-22T16:06:00+00:00', $record->mailDates[0]->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM));
    }

    public function test_an_impossible_date_is_a_warning_and_a_note_line(): void
    {
        $record = $this->map(self::row(['mailSent' => '31.02.2024, 10:00 & 04.01.2024, 11:38']));

        self::assertSame(['2024-01-04 11:38 Europe/Zurich'], self::dates($record));
        self::assertSame([['row' => 2, 'field' => 'mail_sent', 'reason' => 'impossible date']], $record->warnings);
        self::assertSame('31.02.2024, 10:00 & 04.01.2024, 11:38', self::noteLine($record, CustomerNoteField::MailSent));
    }

    public function test_an_impossible_time_is_a_warning(): void
    {
        $record = $this->map(self::row(['mailSent' => '04.01.2024, 25:00']));

        self::assertSame([], $record->mailDates);
        self::assertSame('impossible date', $record->warnings[0]['reason']);
    }

    public function test_an_empty_last_name_is_unbekannt(): void
    {
        $record = $this->map(self::row(['firstName' => null, 'lastName' => null]));

        self::assertSame('unbekannt', CustomerRecordMapper::UNKNOWN_LAST_NAME);
        self::assertSame(CustomerRecordMapper::UNKNOWN_LAST_NAME, $record->lastName);
        self::assertNull($record->firstName);
    }

    public function test_a_first_name_alone_keeps_the_first_name(): void
    {
        $record = $this->map(self::row(['lastName' => null]));

        self::assertSame('Erika', $record->firstName);
        self::assertSame('unbekannt', $record->lastName);
    }

    public function test_an_unknown_region_is_a_warning_and_stays_in_the_note(): void
    {
        $record = $this->map(self::row(['region' => 'Vorarlberg']));

        self::assertSame([['row' => 2, 'field' => 'region', 'reason' => 'unknown region']], $record->warnings);
        self::assertSame('Vorarlberg', self::noteLine($record, CustomerNoteField::Region));
    }

    public function test_empty_cells_leave_no_line_and_no_value(): void
    {
        $record = $this->map(new CustomerRow(7, 'Hof Leer', 'keine Antwort', null, null, null, null, null, null, null, null, null, SheetRegion::fromSheet('')));

        self::assertNull($record->website);
        self::assertNull($record->email);
        self::assertNull($record->phone);
        self::assertSame([], $record->mailDates);
        self::assertSame([], $record->warnings);
        self::assertSame([['row' => 7, 'lines' => [[CustomerNoteField::Status, 'keine Antwort']]]], $record->noteBlocks);
    }

    public function test_a_duplicate_row_merges_its_dates_and_adds_a_note_block(): void
    {
        $primary = self::row(['mailSent' => '04.01.2024, 11:38']);
        $duplicate = self::row([
            'number' => 9,
            'status' => 'Absage',
            'salutation' => null,
            'note' => 'doppelt erfasst',
            'mailSent' => '22.07.2024, 18:06 & 04.01.2024, 11:38',
            'email' => 'INFO@landgut-erfunden.example.ch',
            'phone' => '079 000 00 09',
            'website' => 'landgut-erfunden.example.ch',
            'region' => 'Aargau',
            'instagram' => null,
        ]);

        $record = $this->mapper->map('Landgut Erfunden', $primary, [$duplicate]);

        self::assertSame([2, 9], $record->rows);
        self::assertSame(['2024-01-04 11:38 Europe/Zurich', '2024-07-22 18:06 Europe/Zurich'], self::dates($record));
        self::assertSame('062 000 00 00', $record->phone, 'the primary row gives the fields');
        self::assertCount(2, $record->noteBlocks);
        self::assertSame(2, $record->noteBlocks[0]['row']);
        self::assertSame([
            'row' => 9,
            'lines' => [
                [CustomerNoteField::Status, 'Absage'],
                [CustomerNoteField::Region, 'Aargau'],
                [CustomerNoteField::Note, 'doppelt erfasst'],
                [CustomerNoteField::Phone, '079 000 00 09'],
            ],
        ], $record->noteBlocks[1], 'the same address and site leave no line; the other phone is kept');
    }

    public function test_a_duplicate_rows_warnings_carry_its_number(): void
    {
        $record = $this->mapper->map('Landgut Erfunden', self::row(), [self::row(['number' => 5, 'email' => 'kaputt'])]);

        self::assertSame([['row' => 5, 'field' => 'email', 'reason' => 'invalid email']], $record->warnings);
        self::assertSame('info@landgut-erfunden.example.ch', $record->email);
    }

    /**
     * @param array<string, int|string|null> $overrides
     */
    private static function row(array $overrides = []): CustomerRow
    {
        $cells = $overrides + [
            'number' => 2,
            'name' => 'Landgut Erfunden, Beispielwil',
            'status' => 'Zusage',
            'salutation' => 'Frau',
            'firstName' => 'Erika',
            'lastName' => 'Fiktiv',
            'website' => 'https://landgut-erfunden.example.ch',
            'email' => 'info@landgut-erfunden.example.ch',
            'phone' => '062 000 00 00',
            'instagram' => 'https://www.instagram.com/landgut.erfunden.example/',
            'mailSent' => '04.01.2024, 11:38',
            'note' => 'Anfrage | Rückruf',
            'region' => 'Aargau',
        ];
        $text = static function (string $key) use ($cells): ?string {
            $value = $cells[$key];

            return null === $value ? null : (string) $value;
        };

        return new CustomerRow(
            number: (int) $cells['number'],
            name: $text('name'),
            status: $text('status'),
            salutation: $text('salutation'),
            firstName: $text('firstName'),
            lastName: $text('lastName'),
            website: $text('website'),
            email: $text('email'),
            phone: $text('phone'),
            instagram: $text('instagram'),
            mailSent: $text('mailSent'),
            note: $text('note'),
            region: SheetRegion::fromSheet($text('region') ?? ''),
        );
    }

    private function map(CustomerRow $row, string $finalName = 'Landgut Erfunden, Beispielwil'): CustomerRecord
    {
        return $this->mapper->map($finalName, $row, []);
    }

    /**
     * @return list<string>
     */
    private static function dates(CustomerRecord $record): array
    {
        return array_map(static fn (\DateTimeImmutable $date): string => $date->format('Y-m-d H:i e'), $record->mailDates);
    }

    private static function noteLine(CustomerRecord $record, CustomerNoteField $field): ?string
    {
        foreach ($record->noteBlocks[0]['lines'] as [$lineField, $value]) {
            if ($lineField === $field) {
                return $value;
            }
        }

        return null;
    }
}
