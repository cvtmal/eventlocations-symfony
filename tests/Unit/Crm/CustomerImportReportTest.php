<?php

declare(strict_types=1);

namespace App\Tests\Unit\Crm;

use App\Crm\ValueObject\CustomerClassification;
use App\Crm\ValueObject\CustomerImportReport;
use App\Crm\ValueObject\CustomerNoteField;
use App\Crm\ValueObject\CustomerRecord;
use App\Crm\ValueObject\CustomerRowClass;
use PHPUnit\Framework\TestCase;

/**
 * The customer import's console sentence and report file (#48 research §7): the counts per class, a section
 * per class, and never an email, a phone or a person, although the records they are built from hold them.
 * Every value here is invented.
 */
final class CustomerImportReportTest extends TestCase
{
    private const array VALUES = ['erika@landgut-erfunden.example.ch', '062 000 00 00', 'Erika', 'Fiktiv', 'Frau', 'instagram.com', '04.01.2024', 'keine Antwort', '@'];

    public function test_the_dry_run_summary_counts_rows_by_class(): void
    {
        self::assertSame(
            'Dry run: Customers: 13 rows, 2 new, 1 matched (venue), 1 matched (name), 1 duplicate in sheet, 1 to check, 3 name repeated, 1 ambiguous, 1 blocked, 2 invalid; 2 companies, 2 contacts, 3 activities, 2 warnings, 0 failed.',
            $this->report(dryRun: true)->summary(),
        );
    }

    public function test_the_real_summary_has_no_prefix_and_counts_failures(): void
    {
        $report = $this->report(dryRun: false, failures: ['row 2: chk_test']);

        self::assertStringStartsWith('Customers: 13 rows, 2 new,', $report->summary());
        self::assertStringEndsWith('2 warnings, 1 failed.', $report->summary());
    }

    public function test_every_skipped_row_is_listed_once_by_row(): void
    {
        self::assertSame(
            [
                'row 3: duplicate in sheet',
                'row 4: matched (venue)',
                'row 5: matched (name)',
                'row 6: to check',
                'row 7: name repeated',
                'row 8: name repeated',
                'row 9: ambiguous',
                'row 10: blocked',
                'row 11: invalid',
                'row 12: invalid',
                'row 13: name repeated',
            ],
            $this->report(dryRun: true)->skippedRows(),
        );
    }

    public function test_the_markdown_names_rows_companies_venues_and_reasons(): void
    {
        $markdown = $this->report(dryRun: false, failures: ['row 14: ContactEmailTaken'])->markdown();

        self::assertStringStartsWith("# Customer import\n\n- Customers: 13 rows,", $markdown);
        self::assertStringContainsString('- New customers skipped at write, a company of the name existed by then: 0.', $markdown);
        self::assertStringContainsString('- row 2: Landgut Erfunden', $markdown);
        self::assertStringContainsString('- row 14: Gasthof Beispiel (Zürich), named apart from Gasthof Beispiel (other canton: Gasthof Beispiel)', $markdown);
        self::assertStringContainsString('- row 4: Schloss Erdacht → Schloss Erdacht', $markdown);
        self::assertStringContainsString('- row 5: Remise Fantasie → Remise Fantasie AG', $markdown);
        self::assertStringContainsString('- row 3: Landgut Erfunden → row 2 (same email)', $markdown);
        self::assertStringContainsString('- row 6: Seehaus Ausgedacht: similar name, candidate Seehaus Ausgedacht Ost', $markdown);
        self::assertStringContainsString('- rows 7, 8, 13: Hotel Doppelt (no region or host)', $markdown);
        self::assertStringContainsString('- row 9: Mühle Mehrfach: several companies, one is Mühle Mehrfach GmbH', $markdown);
        self::assertStringContainsString("run `bin/prod-import crm-bootstrap`, then this import again.\n\n- row 10: Villa Ohnefirma → Villa Ohnefirma", $markdown);
        self::assertStringContainsString('- row 11: empty name', $markdown);
        self::assertStringContainsString('- row 2: email: invalid email', $markdown);
        self::assertStringContainsString('- row 3: mail_sent: impossible date', $markdown);
        self::assertStringContainsString("## Failed\n\n- row 14: ContactEmailTaken", $markdown);
    }

    public function test_a_dry_run_report_says_so_and_has_no_write_line(): void
    {
        $markdown = $this->report(dryRun: true)->markdown();

        self::assertStringStartsWith("# Customer import (dry run)\n\n- Dry run: Customers:", $markdown);
        self::assertStringNotContainsString('skipped at write', $markdown);
        self::assertStringContainsString("## Failed\n\nNone.", $markdown);
    }

    public function test_no_email_phone_or_person_value_reaches_the_summary_or_the_report(): void
    {
        $report = $this->report(dryRun: false, failures: ['row 14: ContactEmailTaken']);
        $output = $report->summary()."\n".implode("\n", $report->skippedRows())."\n".$report->markdown();

        foreach (self::VALUES as $value) {
            self::assertStringNotContainsStringIgnoringCase($value, $output);
        }
    }

    /**
     * @param list<string> $failures
     */
    private function report(bool $dryRun, array $failures = []): CustomerImportReport
    {
        $classifications = [
            new CustomerClassification([2, 3], CustomerRowClass::New, 'Landgut Erfunden', 'Landgut Erfunden', null, null, $this->record('Landgut Erfunden', [2, 3], 2, [
                ['row' => 2, 'field' => CustomerNoteField::Email->value, 'reason' => 'invalid email'],
                ['row' => 3, 'field' => CustomerNoteField::MailSent->value, 'reason' => 'impossible date'],
            ])),
            new CustomerClassification([2, 3], CustomerRowClass::DuplicateInSheet, 'Landgut Erfunden', 'Landgut Erfunden', 'same email', null, null),
            new CustomerClassification([4], CustomerRowClass::MatchedVenue, 'Schloss Erdacht', 'Schloss Erdacht', 'same name', 'Schloss Erdacht', null),
            new CustomerClassification([5], CustomerRowClass::MatchedName, 'Remise Fantasie', 'Remise Fantasie', 'same name', 'Remise Fantasie AG', null),
            new CustomerClassification([6], CustomerRowClass::Check, 'Seehaus Ausgedacht', 'Seehaus Ausgedacht', 'similar name', 'Seehaus Ausgedacht Ost', null),
            new CustomerClassification([7, 8, 13], CustomerRowClass::NameRepeated, 'Hotel Doppelt', null, 'no region or host', null, null),
            new CustomerClassification([9], CustomerRowClass::Ambiguous, 'Mühle Mehrfach', 'Mühle Mehrfach', 'several companies', 'Mühle Mehrfach GmbH', null),
            new CustomerClassification([10], CustomerRowClass::Blocked, 'Villa Ohnefirma', 'Villa Ohnefirma', 'venue without company', 'Villa Ohnefirma', null),
            new CustomerClassification([11], CustomerRowClass::Invalid, null, null, 'empty name', null, null),
            new CustomerClassification([12], CustomerRowClass::Invalid, null, null, 'empty name', null, null),
            new CustomerClassification([14], CustomerRowClass::New, 'Gasthof Beispiel', 'Gasthof Beispiel (Zürich)', 'other canton', 'Gasthof Beispiel', $this->record('Gasthof Beispiel (Zürich)', [14], 1, [])),
        ];

        // 13 sheet rows: 2..14
        return new CustomerImportReport($dryRun, 13, $classifications, 2, 2, 3, 0, $failures);
    }

    /**
     * @param list<int>                                            $rows
     * @param list<array{row: int, field: string, reason: string}> $warnings
     */
    private function record(string $name, array $rows, int $mails, array $warnings): CustomerRecord
    {
        return new CustomerRecord(
            $name,
            'https://landgut-erfunden.example.ch',
            'erika@landgut-erfunden.example.ch',
            '062 000 00 00',
            'Erika',
            'Fiktiv',
            array_map(static fn (int $day): \DateTimeImmutable => new \DateTimeImmutable(\sprintf('2024-01-%02d 11:38', $day), new \DateTimeZone('Europe/Zurich')), range(4, 3 + $mails)),
            [['row' => $rows[0], 'lines' => [[CustomerNoteField::Status, 'keine Antwort'], [CustomerNoteField::Salutation, 'Frau'], [CustomerNoteField::Instagram, 'https://www.instagram.com/landgut.erfunden.example/']]]],
            $rows,
            $warnings,
        );
    }
}
