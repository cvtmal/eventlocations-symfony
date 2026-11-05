<?php

declare(strict_types=1);

namespace App\Tests\Integration\Crm;

use App\Factory\ContactFactory;
use App\Factory\VenueFactory;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Admin\RollsBackDatabase;
use App\Venues\Entity\Venue;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Test\Factories;

/**
 * `app:crm:bootstrap` (D19): every venue without a company, one dispatch each, failures reported and
 * the run going on. A second run dispatches nothing, and the output keeps the low contact count
 * visible and holds no contact value.
 */
final class BootstrapCommandTest extends KernelTestCase
{
    use DispatchesCrmCommands;
    use Factories;
    use ImportsTownDataset;
    use RollsBackDatabase;

    public function test_it_creates_a_company_per_venue_and_reports_the_counts(): void
    {
        ContactFactory::createOne(['email' => 'vergeben@example.test']);
        $this->venue('Mit Person', 'Laura Meier', 'laura@example.test');
        $this->venue('Adresse vergeben', 'Hans Muster', 'Vergeben@example.test');
        $this->venue('Ohne Person', null, 'gemeinsam@example.test');
        $this->venue('Auch ohne Person', null, 'GEMEINSAM@example.test');

        [$status, $output] = $this->bootstrap();

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame(4, $this->rows("SELECT count(*) FROM crm_companies WHERE origin = 'venue'"));
        self::assertSame(
            [
                'companies created: 4',
                'contacts created: 2',
                'venues without a contact person: 2 (expected: no export writes contact_person)',
                'venues sharing an inquiry e-mail: 2',
                'skipped: 0',
                'failed: 0',
            ],
            $this->lines($output),
        );
        foreach (['laura', 'Meier', 'Muster', 'gemeinsam', 'vergeben', '@'] as $value) {
            self::assertStringNotContainsStringIgnoringCase($value, $output);
        }
    }

    public function test_a_second_run_creates_nothing_and_dispatches_nothing(): void
    {
        $this->venue('Einmal', 'Laura Meier', 'laura@example.test');
        $this->bootstrap();
        $audit = $this->rows('SELECT count(*) FROM admin_audit_log');

        [$status, $output] = $this->bootstrap();

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame(1, $this->rows('SELECT count(*) FROM crm_companies'));
        self::assertSame(1, $this->rows('SELECT count(*) FROM crm_contacts'));
        self::assertSame($audit, $this->rows('SELECT count(*) FROM admin_audit_log'));
        self::assertStringContainsString('companies created: 0', $output);
        self::assertStringContainsString('venues without a contact person: 0 (expected: no export writes contact_person)', $output);
    }

    public function test_a_venue_that_already_has_a_company_is_not_touched(): void
    {
        $this->venue('Einmal', 'Laura Meier', null);
        $this->bootstrap();
        $this->venue('Neu dazu', null, null);

        [, $output] = $this->bootstrap();

        self::assertStringContainsString('companies created: 1', $output);
        self::assertSame(2, $this->rows('SELECT count(*) FROM crm_companies'));
    }

    public function test_a_failing_venue_is_reported_and_the_run_goes_on(): void
    {
        // A real driver failure: its message quotes the failing row, the e-mail address included.
        $this->connection->executeStatement("ALTER TABLE crm_companies ADD CONSTRAINT chk_test_bootstrap CHECK (name <> 'Kaputt') NOT VALID");
        $broken = $this->venue('Kaputt', 'Laura Meier', 'kaputt@example.test');
        $this->venue('Heil', null, null);

        [$status, $output] = $this->bootstrap();

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('companies created: 1', $output);
        self::assertStringContainsString('failed: 1', $output);
        self::assertStringContainsString($broken->number().': chk_test_bootstrap', $output);
        self::assertSame(1, $this->rows('SELECT count(*) FROM crm_companies'));
        self::assertStringNotContainsString('kaputt@example.test', $output);
        self::assertStringNotContainsString('Meier', $output);
    }

    public function test_an_empty_database_is_a_clean_run(): void
    {
        [$status, $output] = $this->bootstrap();

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('companies created: 0', $output);
    }

    private function venue(string $name, ?string $contactPerson, ?string $inquiryEmail): Venue
    {
        $venue = VenueFactory::createOne(['name' => $name]);
        $venue->changeContact(null, $inquiryEmail, null, $contactPerson);
        $this->em->flush();

        return $venue;
    }

    /**
     * @return array{int, string}
     */
    private function bootstrap(): array
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $tester = new CommandTester((new Application($kernel))->find('app:crm:bootstrap'));
        $status = $tester->execute([]);

        return [$status, $tester->getDisplay()];
    }

    /**
     * @return list<string>
     */
    private function lines(string $output): array
    {
        return array_values(array_filter(array_map(trim(...), explode("\n", $output)), static fn (string $line): bool => '' !== $line));
    }
}
