<?php

declare(strict_types=1);

namespace App\Tests\Integration\Crm;

use App\Crm\Command\CreateCompanyForVenue;
use App\Crm\Command\RecordConsent;
use App\Crm\Command\WithdrawConsent;
use App\Crm\Exception\NoConsentRecorded;
use App\Shared\Uid\EntityId;
use App\Tests\Integration\Venues\RunsContentImport;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * F14, D29: a withdrawn yes takes down the entry (with a tombstone, so the folder does not bring it back), the
 * Location's contacts and copied contact fields, and suppresses the Location (Open Decision 4); the Location, its
 * consent note and the withdrawal note stay. Without a recorded yes nothing is touched.
 */
final class WithdrawConsentTest extends KernelTestCase
{
    use DispatchesCrmCommands, RunsContentImport {
        RunsContentImport::rows insteadof DispatchesCrmCommands;
        DispatchesCrmCommands::rows as crmRows;
    }

    private const string KEY = 'gutshof-erfunden';

    public function test_a_withdrawal_deletes_the_entry_and_what_it_brought_and_keeps_the_proof(): void
    {
        $companyId = $this->importWithLocation();
        $this->dispatch(new RecordConsent($companyId, 'info@gutshof-erfunden.example', '2026-10-12'));
        $number = $this->venueNumber(self::KEY);
        self::assertSame('published', $this->venueRow(self::KEY)['status'] ?? null);

        $this->dispatch(new WithdrawConsent($companyId, $number, '2026-11-02'));

        self::assertFalse($this->venueRow(self::KEY));
        self::assertSame(1, $this->rows('venue_deleted_content_keys WHERE content_key = ?', [self::KEY]));
        self::assertSame(0, $this->rows('crm_contacts WHERE company_id = ?', [$companyId]));
        self::assertSame(
            ['name' => 'Gutshof Erfunden', 'website' => null, 'phone' => null, 'email' => null, 'address' => null, 'venue_id' => null],
            $this->connection->fetchAssociative('SELECT name, website, phone, email, address, venue_id FROM crm_companies WHERE id = ?', [$companyId]),
        );
        self::assertSame([
            'Zustimmung zum Import von hochzeitslocation.ch: «Ja» von info@gutshof-erfunden.example am 12.10.2026.',
            'Zustimmung zurückgezogen am 02.11.2026: Eintrag und übernommene Angaben gelöscht.',
        ], $this->connection->fetchFirstColumn(
            'SELECT a.body FROM crm_activities a JOIN crm_activity_companies l ON l.activity_id = a.id WHERE l.company_id = ? AND a.actor_id IS NULL ORDER BY a.occurred_at',
            [$companyId],
        ));
        self::assertSame('objection', $this->connection->fetchOne('SELECT reason FROM suppressions WHERE company_id = ?', [$companyId]));
        self::assertSame(1, $this->rows('suppressions WHERE email = ?', ['info@gutshof-erfunden.example']), 'the address it held goes on the list too');
        self::assertEquals(
            [['command' => WithdrawConsent::class, 'changes' => ['consent' => 'withdrawn', 'venue' => $number]]],
            array_values(array_filter($this->auditRows('company', $companyId), static fn (array $row): bool => WithdrawConsent::class === $row['command'])),
        );

        // The folder is still there (the console deletes it): the tombstone keeps the entry out.
        self::assertSame(Command::SUCCESS, $this->import(), $this->display());
        self::assertStringContainsString('cms_edited  '.self::KEY, $this->display());
        self::assertFalse($this->venueRow(self::KEY));
    }

    public function test_the_address_the_yes_came_from_goes_on_the_list_even_when_the_location_never_held_it(): void
    {
        $companyId = $this->importWithLocation();
        $this->dispatch(new RecordConsent($companyId, 'Anna.Privat@Erfunden-Mail.example', '2026-10-12'));

        $this->dispatch(new WithdrawConsent($companyId, $this->venueNumber(self::KEY), '2026-11-02'));

        self::assertSame('objection', $this->connection->fetchOne('SELECT reason FROM suppressions WHERE email = ?', ['anna.privat@erfunden-mail.example']));

        // A later contact list naming that address does not bring the Location back.
        $csv = tempnam(sys_get_temp_dir(), 'contacts') ?: throw new \RuntimeException('No temporary file.');
        try {
            file_put_contents($csv, "location,address,email,phone,website,first_name,last_name,position,contact_email,contact_phone,cohort,channel,basis,campaign,venue\n"
                ."Anderer Name Erfunden,,anna.privat@erfunden-mail.example,,,,,,,,,,,,\n");
            $import = new CommandTester((new Application(self::$kernel ?? self::bootKernel()))->find('app:crm:import-contacts'));
            self::assertSame(Command::SUCCESS, $import->execute(['csv' => $csv]), $import->getDisplay(true));
            $display = $import->getDisplay(true);
        } finally {
            unlink($csv);
        }
        self::assertStringContainsString('suppressed  row 2', $display);
        self::assertStringContainsString('Locations: 0 created, 0 exist, 1 suppressed, 0 failed.', $display);
        self::assertSame(0, $this->rows('crm_companies WHERE name = ?', ['Anderer Name Erfunden']));
    }

    public function test_without_a_recorded_yes_nothing_is_touched(): void
    {
        $companyId = $this->importWithLocation();

        $this->failure(new WithdrawConsent($companyId, $this->venueNumber(self::KEY), '2026-11-02'), NoConsentRecorded::class);

        self::assertSame('published', $this->venueRow(self::KEY)['status'] ?? null);
        self::assertSame(1, $this->rows('crm_contacts WHERE company_id = ?', [$companyId]));
        self::assertSame(0, $this->rows('suppressions'));
    }

    /**
     * An imported venue (online, with contact facts) and the Location the bootstrap makes of it, with its contact.
     */
    private function importWithLocation(): string
    {
        $this->content->venue(self::KEY, [
            'contact' => ['phone' => '+41311234567', 'inquiry_email' => 'info@gutshof-erfunden.example', 'website' => 'https://gutshof-erfunden.example', 'contact_person' => 'Anna Beispiel'],
        ], 'Gutshof Erfunden');
        // Online, so the withdrawal takes it offline too (F14: an imported entry is a draft until staff check it).
        self::assertSame(Command::SUCCESS, $this->importOnline(self::KEY), $this->display());
        $companyId = EntityId::generate();
        $this->dispatch(new CreateCompanyForVenue($this->venueId(self::KEY), $companyId));
        self::assertSame(1, $this->rows('crm_contacts WHERE company_id = ?', [$companyId]));

        return $companyId;
    }
}
