<?php

declare(strict_types=1);

namespace App\Tests\Integration\Crm;

use App\Crm\Command\CreateCompanyForVenue;
use App\Crm\Command\RecordConsent;
use App\Factory\VenueFactory;
use App\Shared\Uid\EntityId;
use App\Tests\Integration\Venues\RunsContentImport;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Zenstruck\Foundry\Test\Factories;

/**
 * app:crm:withdraw-consent (F14): a dry run changes nothing; a run deletes the entry, its content folder and its
 * originals (D54) and reminds to delete the sheet row; an unknown number or a venue without a recorded yes is refused.
 * `--withdrawn-key` gives bin/prod-import the key of a withdrawn venue, again and again, and refuses any other; a
 * venue without a content key (one made in the CMS) prints an empty line, and its withdrawal leaves `content/` alone.
 */
final class WithdrawConsentCommandTest extends KernelTestCase
{
    use DispatchesCrmCommands, RunsContentImport {
        RunsContentImport::rows insteadof DispatchesCrmCommands;
        DispatchesCrmCommands::rows as crmRows;
    }
    use Factories;

    private const string KEY = 'gutshof-erfunden';

    public function test_it_deletes_the_entry_and_its_folder(): void
    {
        $number = $this->importedWithConsent(true);
        $originals = $this->originals($number);
        $withdraw = $this->withdraw();

        self::assertSame(Command::SUCCESS, $withdraw->execute(['venue-number' => (string) $number, 'path' => $this->content->path(), '--dry-run' => true]));
        self::assertStringContainsString('Dry run: would delete the folder', $withdraw->getDisplay(true));
        self::assertStringContainsString('Dry run: would delete the originals '.implode(' and ', $originals), $withdraw->getDisplay(true));
        self::assertDirectoryExists($this->content->folder(self::KEY));
        foreach ($originals as $directory) {
            self::assertFileExists($directory.'/kept-by-no-row.jpg', 'the dry run deletes no original');
        }
        self::assertNotFalse($this->venueRow(self::KEY));

        self::assertSame(Command::SUCCESS, $withdraw->execute(['venue-number' => (string) $number, 'path' => $this->content->path(), '--on' => '2026-11-02']));
        self::assertDirectoryDoesNotExist($this->content->folder(self::KEY));
        foreach ($originals as $directory) {
            self::assertDirectoryDoesNotExist($directory);
        }
        self::assertFalse($this->venueRow(self::KEY));
        self::assertStringContainsString('Deleted the originals '.implode(' and ', $originals), $withdraw->getDisplay(true));
        self::assertStringContainsString('Delete the row of gutshof-erfunden from your sheet.', $withdraw->getDisplay(true));
    }

    public function test_the_withdrawn_key_comes_from_the_note_again_and_again_and_only_for_a_withdrawn_venue(): void
    {
        $number = $this->importedWithConsent(true);
        $withdraw = $this->withdraw();

        self::assertSame(Command::FAILURE, $withdraw->execute(['venue-number' => (string) $number, '--withdrawn-key' => true]));
        self::assertStringContainsString(\sprintf('Venue %d still exists: withdraw it first.', $number), $withdraw->getDisplay(true));
        self::assertSame(Command::FAILURE, $withdraw->execute(['venue-number' => '999999', '--withdrawn-key' => true]));
        self::assertStringContainsString('No venue 999999 was withdrawn.', $withdraw->getDisplay(true));

        self::assertSame(Command::SUCCESS, $withdraw->execute(['venue-number' => (string) $number, 'path' => $this->content->path(), '--on' => '2026-11-02']));
        foreach ([1, 2] as $run) {
            self::assertSame(Command::SUCCESS, $withdraw->execute(['venue-number' => (string) $number, '--withdrawn-key' => true]), 'run '.$run);
            self::assertSame(self::KEY."\n", $withdraw->getDisplay(true), 'only the key, run '.$run);
        }
    }

    public function test_a_venue_without_a_content_key_gives_an_empty_key_and_keeps_every_content_directory(): void
    {
        $this->content->venue(self::KEY, ['status' => 'unpublished', 'translations' => null], 'Gutshof Erfunden');
        self::assertSame(Command::SUCCESS, $this->import(), $this->display());
        $venue = VenueFactory::createOne();
        $companyId = EntityId::generate();
        $this->dispatch(new CreateCompanyForVenue($venue->id(), $companyId));
        $this->dispatch(new RecordConsent($companyId, 'info@remise-erfunden.example', '2026-10-12'));
        $number = $venue->number();
        $root = self::getContainer()->getParameter('app.media_root');
        self::assertIsString($root);
        $uploads = rtrim($root, '/').'/cms/'.$number;
        (new Filesystem())->dumpFile($uploads.'/kept-by-no-row.jpg', 'not an image');
        $imported = rtrim($root, '/').'/content/'.self::KEY;
        (new Filesystem())->dumpFile($imported.'/kept-by-no-row.jpg', 'not an image');
        $withdraw = $this->withdraw();

        self::assertSame(Command::SUCCESS, $withdraw->execute(['venue-number' => (string) $number, 'path' => $this->content->path(), '--on' => '2026-11-02']));
        self::assertStringContainsString('Deleted the originals '.$uploads, $withdraw->getDisplay(true));
        self::assertStringNotContainsString('/content/', $withdraw->getDisplay(true));
        self::assertStringNotContainsString('Deleted the folder', $withdraw->getDisplay(true));
        self::assertStringNotContainsString('from your sheet', $withdraw->getDisplay(true));
        self::assertDirectoryDoesNotExist($uploads);
        self::assertFileExists($imported.'/kept-by-no-row.jpg', 'another venue\'s originals stay');
        self::assertDirectoryExists($this->content->folder(self::KEY), 'the content root stays');

        foreach ([1, 2] as $run) {
            self::assertSame(Command::SUCCESS, $withdraw->execute(['venue-number' => (string) $number, '--withdrawn-key' => true]), 'run '.$run);
            self::assertSame("\n", $withdraw->getDisplay(true), 'an empty key, not "null", run '.$run);
        }
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

    /**
     * Both original directories of the venue under MEDIA_ROOT, each with a file no row names (as a leftover would be).
     *
     * @return list<string>
     */
    private function originals(int $number): array
    {
        $root = self::getContainer()->getParameter('app.media_root');
        self::assertIsString($root);
        $directories = [];
        foreach (['content/'.self::KEY, 'cms/'.$number] as $relative) {
            $directory = rtrim($root, '/').'/'.$relative;
            (new Filesystem())->dumpFile($directory.'/kept-by-no-row.jpg', 'not an image');
            $directories[] = $directory;
        }

        return $directories;
    }

    private function withdraw(): CommandTester
    {
        return new CommandTester((new Application(self::$kernel ?? self::bootKernel()))->find('app:crm:withdraw-consent'));
    }
}
