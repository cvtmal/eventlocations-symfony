<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * app:venues:convert-sheet (F14) on the fictional template: a dry run writes nothing, a run writes a draft folder per
 * valid row that app:venues:import accepts, a second run finds them there, and the consent row without its yes
 * fails the run without stopping the others.
 */
final class ConvertSheetCommandTest extends KernelTestCase
{
    use RunsContentImport;

    private const string SHEET = __DIR__.'/../../Fixtures/Onboarding/venue-sheet.csv';

    private const array KEYS = ['gutshof-erfunden-bern', 'halle-beispiel-basel', 'remise-am-bach-zuerich', 'seehof-muster'];

    public function test_the_sheet_becomes_draft_folders_the_import_accepts(): void
    {
        $convert = new CommandTester((new Application(self::$kernel ?? self::bootKernel()))->find('app:venues:convert-sheet'));

        self::assertSame(Command::FAILURE, $convert->execute(['csv' => self::SHEET, 'path' => $this->content->path(), '--dry-run' => true]));
        $display = $convert->getDisplay(true);
        self::assertStringContainsString('Dry run: Rows: 5 read, 4 created, 0 exist, 1 failed.', $display);
        self::assertStringContainsString('remise-am-bach-zuerich (key of row 2, from name and town)', $display);
        self::assertStringContainsString('row 5: consent_on: the date of the yes (YYYY-MM-DD) is required', $display);
        self::assertDirectoryDoesNotExist($this->content->path().'/venues');

        self::assertSame(Command::FAILURE, $convert->execute(['csv' => self::SHEET, 'path' => $this->content->path()]));
        self::assertSame(self::KEYS, $this->folders());
        self::assertStringContainsString('status: unpublished', (string) file_get_contents($this->content->folder('seehof-muster').'/venue.yaml'));

        self::assertSame(Command::SUCCESS, $this->import(['--dry-run' => true]), $this->display());
        self::assertStringContainsString('Dry run: Venues: 4 seen, 4 created, 0 updated, 0 unchanged, 0 failed', $this->display());
        self::assertSame(Command::SUCCESS, $this->import(), $this->display());
        self::assertSame(4, $this->rows("venues WHERE content_key IS NOT NULL AND status = 'unpublished'"));

        self::assertSame(Command::FAILURE, $convert->execute(['csv' => self::SHEET, 'path' => $this->content->path()]));
        self::assertStringContainsString('Rows: 5 read, 0 created, 4 exist, 1 failed.', $convert->getDisplay(true));
    }

    public function test_a_file_that_is_not_the_sheet_is_refused(): void
    {
        $convert = new CommandTester((new Application(self::$kernel ?? self::bootKernel()))->find('app:venues:convert-sheet'));

        self::assertSame(Command::FAILURE, $convert->execute(['csv' => $this->content->path().'/missing.csv', 'path' => $this->content->path()]));
        self::assertStringContainsString('No sheet at', $convert->getDisplay(true));
    }

    /**
     * @return list<string>
     */
    private function folders(): array
    {
        $folders = array_map(basename(...), glob($this->content->path().'/venues/*', \GLOB_ONLYDIR) ?: []);
        sort($folders);

        return $folders;
    }
}
