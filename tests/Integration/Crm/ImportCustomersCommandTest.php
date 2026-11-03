<?php

declare(strict_types=1);

namespace App\Tests\Integration\Crm;

use App\Crm\CustomerImport;
use App\Factory\CompanyFactory;
use App\Factory\VenueFactory;
use App\Geo\Entity\Canton;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Admin\RollsBackDatabase;
use App\Tests\Support\Crm\CustomerSheetCsv;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Test\Factories;

use function Zenstruck\Foundry\Persistence\repository;

/**
 * `app:crm:import-customers` (#48) on a fictional sheet: a dry run writes the report and nothing else, a
 * real run one company, contact and the logged mails per new customer with one audit row each, a second run
 * nothing. The output and the report name no email, phone or person.
 */
final class ImportCustomersCommandTest extends KernelTestCase
{
    use DispatchesCrmCommands;
    use Factories;
    use ImportsTownDataset;
    use RollsBackDatabase;

    /** Every email, phone and person value of the sheet below. */
    private const array VALUES = [
        'info@landgut-erfunden.example.ch', 'landgut-erfunden.example.ch', 'Erika', 'Fiktiv', '062 000 00 00',
        'ottilie@schloss-erdacht.example.ch', 'Ottilie', 'Phantasius', '031 999 99 99',
        'villa@ohnefirma.example.ch', 'Bruno', 'Erdichtet', '044 111 11 11',
        'remise@fantasie.example.ch', 'Klara', 'Ersonnen', '061 222 22 22',
        'Frau', 'Herr', 'instagram', '@',
    ];

    public function test_a_dry_run_writes_the_report_and_nothing_else(): void
    {
        $this->crm();
        $before = $this->counts();

        [$status, $output] = $this->import($this->sheet(), dryRun: true);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame($before, $this->counts());
        self::assertStringContainsString('Dry run: Customers: 6 rows, 2 new, 0 matched (venue), 1 matched (name), 1 duplicate in sheet, 0 to check, 0 name repeated, 0 ambiguous, 1 blocked, 1 invalid; 2 companies, 2 contacts, 3 activities, 0 warnings, 0 failed.', $output);
        self::assertSame(
            ['row 3: duplicate in sheet', 'row 5: blocked', 'row 6: matched (name)', 'row 7: invalid'],
            array_values(array_filter($this->lines($output), static fn (string $line): bool => str_starts_with($line, 'row '))),
        );
        self::assertStringContainsString('Report: '.$this->reportFile(), $output);
        self::assertFileExists($this->reportFile());
        self::assertStringStartsWith('# Customer import (dry run)', (string) file_get_contents($this->reportFile()));
    }

    public function test_a_real_run_creates_companies_contacts_activities_and_one_audit_row_each(): void
    {
        $this->crm();
        $audit = $this->rows('SELECT count(*) FROM admin_audit_log');

        [$status, $output] = $this->import($this->sheet());

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('Customers: 6 rows, 2 new, 0 matched (venue), 1 matched (name), 1 duplicate in sheet, 0 to check, 0 name repeated, 0 ambiguous, 1 blocked, 1 invalid; 2 companies, 2 contacts, 3 activities, 0 warnings, 0 failed.', $output);
        self::assertStringNotContainsString('Dry run', $output);
        $landgut = $this->companyId('Landgut Erfunden, Beispielwil');
        $schloss = $this->companyId('Schloss Erdacht');
        self::assertSame(1, $this->rows("SELECT count(*) FROM crm_contacts WHERE company_id = ? AND last_name = 'Fiktiv'", $landgut));
        self::assertSame(1, $this->rows("SELECT count(*) FROM crm_contacts WHERE company_id = ? AND last_name = 'Phantasius'", $schloss));
        self::assertSame(2, $this->rows('SELECT count(*) FROM crm_activity_companies WHERE company_id = ?', $landgut));
        self::assertSame(1, $this->rows('SELECT count(*) FROM crm_activity_companies WHERE company_id = ?', $schloss));
        self::assertSame(3, $this->rows("SELECT count(*) FROM crm_activities WHERE type_code = 'email_logged'"));
        self::assertSame(3, $this->rows("SELECT count(*) FROM crm_companies WHERE origin = 'manual'"));
        self::assertSame($audit + 2, $this->rows('SELECT count(*) FROM admin_audit_log'));
        self::assertSame(1, $this->rows('SELECT count(*) FROM admin_audit_log WHERE subject_id = ?', $landgut));
        self::assertSame(1, $this->rows('SELECT count(*) FROM admin_audit_log WHERE subject_id = ?', $schloss));
        self::assertStringStartsWith('# Customer import'."\n", (string) file_get_contents($this->reportFile()));
    }

    public function test_a_second_run_finds_nothing_new_and_dispatches_nothing(): void
    {
        $this->crm();
        $this->import($this->sheet());
        $after = $this->counts();

        [$dryStatus, $dryOutput] = $this->import($this->sheet(), dryRun: true);
        [$status, $output] = $this->import($this->sheet());

        self::assertSame(Command::SUCCESS, $dryStatus);
        self::assertStringContainsString('Dry run: Customers: 6 rows, 0 new, 0 matched (venue), 3 matched (name),', $dryOutput);
        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('Customers: 6 rows, 0 new,', $output);
        self::assertStringContainsString('; 0 companies, 0 contacts, 0 activities, 0 warnings, 0 failed.', $output);
        self::assertSame($after, $this->counts());
    }

    public function test_no_email_phone_or_person_reaches_the_output_or_the_report(): void
    {
        $this->crm();

        [, $dryOutput] = $this->import($this->sheet(), dryRun: true);
        $dryReport = (string) file_get_contents($this->reportFile());
        [, $output] = $this->import($this->sheet());
        $report = (string) file_get_contents($this->reportFile());

        foreach ([$dryOutput, $dryReport, $output, $report] as $text) {
            foreach (self::VALUES as $value) {
                self::assertStringNotContainsStringIgnoringCase($value, $text);
            }
        }
    }

    public function test_a_venue_without_a_company_is_blocked_and_the_hint_printed(): void
    {
        $this->crm();

        [$status, $output] = $this->import($this->sheet(), dryRun: true);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('row 5: blocked', $output);
        self::assertStringContainsString('1 row matches a venue without a company: run bin/prod-import crm-bootstrap first', $output);
        self::assertStringContainsString('- row 5: Villa Ohnefirma → Villa Ohnefirma', (string) file_get_contents($this->reportFile()));
    }

    public function test_a_malformed_sheet_fails_and_writes_nothing(): void
    {
        $path = $this->sheet();
        file_put_contents($path, "\"Ein Feld zu viel\",x\r\n", \FILE_APPEND);
        $before = $this->counts();

        [$status, $output] = $this->import($path);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('Row 8 of the customer sheet has the wrong number of fields.', $output);
        self::assertSame($before, $this->counts());
        self::assertFileDoesNotExist($this->reportFile());

        $columns = array_values(array_diff(CustomerSheetCsv::COLUMNS, ['Email']));
        CustomerSheetCsv::write($path, [CustomerSheetCsv::row()], columns: $columns);
        [$status] = $this->import($path, dryRun: true);
        self::assertSame(Command::FAILURE, $status);
    }

    public function test_a_failing_customer_is_reported_by_constraint_and_the_run_goes_on(): void
    {
        // A real driver failure: its message quotes the failing row.
        $this->connection->executeStatement("ALTER TABLE crm_companies ADD CONSTRAINT chk_test_customers CHECK (name <> 'Schloss Erdacht') NOT VALID");
        $this->crm();

        [$status, $output] = $this->import($this->sheet());

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('; 1 companies, 1 contacts, 2 activities, 0 warnings, 1 failed.', $output);
        self::assertStringContainsString('row 4: chk_test_customers', $output);
        self::assertSame(1, $this->rows("SELECT count(*) FROM crm_companies WHERE name = 'Landgut Erfunden, Beispielwil'"));
        self::assertStringContainsString("## Failed\n\n- row 4: chk_test_customers", (string) file_get_contents($this->reportFile()));
        self::assertStringNotContainsString('Phantasius', $output);
        self::assertStringNotContainsString('ottilie', $output);
    }

    /** A venue without a company in Zürich and a company of another sheet name. */
    private function crm(): void
    {
        $zurich = repository(Canton::class)->find('zh');
        self::assertInstanceOf(Canton::class, $zurich);
        VenueFactory::new()->withoutLocation($zurich)->create(['name' => 'Villa Ohnefirma']);
        CompanyFactory::createOne(['name' => 'Remise Fantasie']);
    }

    /**
     * Row 2 new, 3 its duplicate (same email), 4 new, 5 blocked, 6 matched (name), 7 invalid.
     */
    private function sheet(): string
    {
        @unlink($this->reportFile());
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $path = $kernel->getProjectDir().'/var/test-customer-sheet/kunden.csv';
        CustomerSheetCsv::write($path, [
            CustomerSheetCsv::row(),
            CustomerSheetCsv::row(['Mail gesendet am ' => '12.02.2024', 'Contact Status' => 'Absage', 'Notitz' => '']),
            CustomerSheetCsv::row([
                'Company name' => 'Schloss Erdacht', 'Anrede' => 'Herr', 'Vorname' => 'Ottilie', 'Nachname' => 'Phantasius',
                'Website' => '', 'Email' => 'ottilie@schloss-erdacht.example.ch', 'Phone numbeer' => '031 999 99 99',
                'Instagram URL' => '', 'Mail gesendet am ' => '01.03.2024', 'State/Region' => 'Bern',
            ]),
            CustomerSheetCsv::row([
                'Company name' => 'Villa Ohnefirma', 'Vorname' => 'Bruno', 'Nachname' => 'Erdichtet', 'Website' => '',
                'Email' => 'villa@ohnefirma.example.ch', 'Phone numbeer' => '044 111 11 11', 'Instagram URL' => '', 'State/Region' => 'Zürich',
            ]),
            CustomerSheetCsv::row([
                'Company name' => 'Remise Fantasie', 'Vorname' => 'Klara', 'Nachname' => 'Ersonnen', 'Website' => '',
                'Email' => 'remise@fantasie.example.ch', 'Phone numbeer' => '061 222 22 22', 'Instagram URL' => '', 'State/Region' => '',
            ]),
            CustomerSheetCsv::row(['Company name' => ' ']),
        ]);

        return $path;
    }

    /**
     * @return array{int, string}
     */
    private function import(string $path, bool $dryRun = false): array
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $tester = new CommandTester((new Application($kernel))->find('app:crm:import-customers'));
        $status = $tester->execute(['csv' => $path] + ($dryRun ? ['--dry-run' => true] : []));

        return [$status, $tester->getDisplay()];
    }

    private function reportFile(): string
    {
        $root = self::getContainer()->getParameter('app.content_root');
        \assert(\is_string($root));

        return $root.'/'.CustomerImport::REPORT_FILE;
    }

    /**
     * @return array<string, int>
     */
    private function counts(): array
    {
        $counts = [];
        foreach (['crm_companies', 'crm_contacts', 'crm_activities', 'crm_activity_companies', 'crm_activity_contacts', 'crm_company_venues', 'admin_audit_log'] as $table) {
            $counts[$table] = $this->rows('SELECT count(*) FROM '.$table);
        }

        return $counts;
    }

    private function companyId(string $name): string
    {
        $id = $this->connection->fetchOne('SELECT id FROM crm_companies WHERE name = ?', [$name]);
        self::assertIsString($id);

        return $id;
    }

    /**
     * @return list<string>
     */
    private function lines(string $output): array
    {
        return array_values(array_filter(array_map(trim(...), explode("\n", $output)), static fn (string $line): bool => '' !== $line));
    }
}
