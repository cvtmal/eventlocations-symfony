<?php

declare(strict_types=1);

namespace App\Tests\Integration\Crm;

use App\Shared\Uid\EntityId;
use App\Tests\Support\Admin\RollsBackDatabase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * app:crm:import-contacts (F14) on the fictional contact list: a dry run writes nothing; a run creates the good rows,
 * skips the suppressed one and fails the broken one; a second run finds every Location there. The output names no
 * Location and no address.
 */
final class ImportContactsCommandTest extends KernelTestCase
{
    use DispatchesCrmCommands;
    use RollsBackDatabase;

    private const string LIST = __DIR__.'/../../Fixtures/Onboarding/contacts.csv';

    public function test_it_imports_the_list_once_and_honours_the_suppression_list(): void
    {
        $this->connection->insert('suppressions', ['id' => EntityId::generate(), 'email' => 'nein@gesperrt.example', 'reason' => 'objection']);
        $import = new CommandTester((new Application(self::$kernel ?? self::bootKernel()))->find('app:crm:import-contacts'));

        self::assertSame(Command::FAILURE, $import->execute(['csv' => self::LIST, '--dry-run' => true]));
        self::assertStringContainsString('Dry run: Locations: 2 created, 0 exist, 1 suppressed, 1 failed.', $import->getDisplay(true));
        self::assertSame(0, $this->rows('SELECT count(*) FROM crm_companies'));

        self::assertSame(Command::FAILURE, $import->execute(['csv' => self::LIST]));
        $display = $import->getDisplay(true);
        self::assertStringContainsString('Locations: 2 created, 0 exist, 1 suppressed, 1 failed.', $display);
        self::assertStringContainsString('suppressed  row 4', $display);
        self::assertStringContainsString('row 5: cohort: unknown', $display);
        foreach (['Seehof', 'seehof-muster.example', 'gesperrt.example', 'Kaputt'] as $value) {
            self::assertStringNotContainsString($value, $display);
        }
        self::assertSame(['Halle Erfunden GmbH', 'Seehof Muster AG'], $this->connection->fetchFirstColumn('SELECT name FROM crm_companies ORDER BY name'));
        self::assertSame(1, $this->rows('SELECT count(*) FROM crm_contacts'));
        self::assertSame(0, $this->rows("SELECT count(*) FROM crm_activities WHERE type_code IN ('email_sent', 'email_logged')"), 'nothing is sent');

        self::assertSame(Command::FAILURE, $import->execute(['csv' => self::LIST]));
        self::assertStringContainsString('Locations: 0 created, 2 exist, 1 suppressed, 1 failed.', $import->getDisplay(true));
        self::assertSame(2, $this->rows('SELECT count(*) FROM crm_companies'));
    }

    public function test_a_file_that_is_not_the_list_is_refused(): void
    {
        $import = new CommandTester((new Application(self::$kernel ?? self::bootKernel()))->find('app:crm:import-contacts'));

        self::assertSame(Command::FAILURE, $import->execute(['csv' => __DIR__.'/../../Fixtures/Onboarding/venue-sheet.csv']));
        self::assertStringContainsString('is not a contact sheet: column', $import->getDisplay(true));
    }
}
