<?php

declare(strict_types=1);

namespace App\Tests\Integration\Crm;

use App\Crm\Command\CreateCompanyForVenue;
use App\Crm\Command\RecordConsent;
use App\Shared\Uid\EntityId;
use App\Tests\Integration\Venues\RunsContentImport;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * app:crm:withdraw-consent (F14): a dry run changes nothing; a run deletes the entry and its content folder and
 * reminds to delete the sheet row; an unknown number or a venue without a recorded yes is refused.
 */
final class WithdrawConsentCommandTest extends KernelTestCase
{
    use DispatchesCrmCommands, RunsContentImport {
        RunsContentImport::rows insteadof DispatchesCrmCommands;
        DispatchesCrmCommands::rows as crmRows;
    }

    private const string KEY = 'gutshof-erfunden';

    public function test_it_deletes_the_entry_and_its_folder(): void
    {
        $number = $this->importedWithConsent(true);
        $withdraw = $this->withdraw();

        self::assertSame(Command::SUCCESS, $withdraw->execute(['venue-number' => (string) $number, 'path' => $this->content->path(), '--dry-run' => true]));
        self::assertStringContainsString('Dry run: would delete the folder', $withdraw->getDisplay(true));
        self::assertDirectoryExists($this->content->folder(self::KEY));
        self::assertNotFalse($this->venueRow(self::KEY));

        self::assertSame(Command::SUCCESS, $withdraw->execute(['venue-number' => (string) $number, 'path' => $this->content->path(), '--on' => '2026-11-02']));
        self::assertDirectoryDoesNotExist($this->content->folder(self::KEY));
        self::assertFalse($this->venueRow(self::KEY));
        self::assertStringContainsString('Delete the row of gutshof-erfunden from your sheet.', $withdraw->getDisplay(true));
    }

    public function test_an_unknown_number_or_a_venue_without_a_yes_is_refused(): void
    {
        $number = $this->importedWithConsent(false);
        $withdraw = $this->withdraw();

        self::assertSame(Command::FAILURE, $withdraw->execute(['venue-number' => '999999', 'path' => $this->content->path()]));
        self::assertStringContainsString('There is no venue 999999.', $withdraw->getDisplay(true));
        self::assertSame(Command::FAILURE, $withdraw->execute(['venue-number' => (string) $number, 'path' => $this->content->path()]));
        self::assertStringContainsString('has no Location with a recorded consent', $withdraw->getDisplay(true));
        self::assertDirectoryExists($this->content->folder(self::KEY));
        self::assertNotFalse($this->venueRow(self::KEY));
    }

    private function importedWithConsent(bool $consent): int
    {
        $this->content->venue(self::KEY, ['status' => 'unpublished', 'translations' => null], 'Gutshof Erfunden');
        self::assertSame(Command::SUCCESS, $this->import(), $this->display());
        $companyId = EntityId::generate();
        $this->dispatch(new CreateCompanyForVenue($this->venueId(self::KEY), $companyId));
        if ($consent) {
            $this->dispatch(new RecordConsent($companyId, 'info@gutshof-erfunden.example', '2026-10-12'));
        }

        return $this->venueNumber(self::KEY);
    }

    private function withdraw(): CommandTester
    {
        return new CommandTester((new Application(self::$kernel ?? self::bootKernel()))->find('app:crm:withdraw-consent'));
    }
}
