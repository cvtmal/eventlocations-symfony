<?php

declare(strict_types=1);

namespace App\Tests\Integration\Crm;

use App\Tests\Integration\Venues\RunsContentImport;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * app:crm:record-consents (F14) on the fictional venue sheet: before the import the consent row is not_imported;
 * after it the yes lands on a new Location of the draft, a second run changes nothing, and the consent row without
 * its yes fails the run. A dry run writes nothing.
 */
final class RecordConsentsCommandTest extends KernelTestCase
{
    use RunsContentImport;

    private const string SHEET = __DIR__.'/../../Fixtures/Onboarding/venue-sheet.csv';

    public function test_the_consent_rows_land_on_the_locations_of_their_drafts(): void
    {
        $application = new Application(self::$kernel ?? self::bootKernel());
        $record = new CommandTester($application->find('app:crm:record-consents'));

        self::assertSame(Command::FAILURE, $record->execute(['csv' => self::SHEET]));
        self::assertStringContainsString('not_imported  gutshof-erfunden-bern', $record->getDisplay(true));
        self::assertStringContainsString('failed        villa-ohne-ja-luzern', $record->getDisplay(true));
        self::assertStringNotContainsString('remise-am-bach', $record->getDisplay(true), 'only hochzeitslocation rows');

        (new CommandTester($application->find('app:venues:convert-sheet')))->execute(['csv' => self::SHEET, 'path' => $this->content->path()]);
        self::assertSame(Command::SUCCESS, $this->import(), $this->display());

        self::assertSame(Command::FAILURE, $record->execute(['csv' => self::SHEET, '--dry-run' => true]));
        self::assertStringContainsString('Dry run: Consents: 1 recorded, 0 unchanged, 0 not imported, 1 failed.', $record->getDisplay(true));
        self::assertSame(0, $this->rows("crm_activities WHERE payload->'consent' IS NOT NULL"));
        self::assertSame(0, $this->rows('crm_companies c JOIN venues v ON v.id = c.venue_id WHERE v.content_key = ?', ['gutshof-erfunden-bern']));

        self::assertSame(Command::FAILURE, $record->execute(['csv' => self::SHEET]));
        self::assertStringContainsString('recorded      gutshof-erfunden-bern', $record->getDisplay(true));
        self::assertStringNotContainsString('info@gutshof-erfunden.example', $record->getDisplay(true), 'no address in the output');
        self::assertSame(1, $this->rows(
            "crm_activities a JOIN crm_activity_companies l ON l.activity_id = a.id JOIN crm_companies c ON c.id = l.company_id JOIN venues v ON v.id = c.venue_id
             WHERE v.content_key = ? AND a.payload->'consent'->>'from' = ? AND c.contact_basis = 'consent'",
            ['gutshof-erfunden-bern', 'info@gutshof-erfunden.example'],
        ));

        self::assertSame(Command::FAILURE, $record->execute(['csv' => self::SHEET]));
        self::assertStringContainsString('Consents: 0 recorded, 1 unchanged, 0 not imported, 1 failed.', $record->getDisplay(true));
        self::assertSame(1, $this->rows("crm_activities WHERE payload->'consent' IS NOT NULL"));
    }
}
