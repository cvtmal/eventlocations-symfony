<?php

declare(strict_types=1);

namespace App\Tests\Integration\Crm;

use App\Crm\Command\EraseContact;
use App\Crm\Command\IngestInquiry;
use App\Crm\Entity\ActivityType;
use App\Crm\Entity\Contact;
use App\Crm\ValueObject\ContactKind;
use App\Factory\ActivityFactory;
use App\Factory\CompanyFactory;
use App\Factory\ContactFactory;
use App\Factory\DealFactory;
use App\Factory\InquiryFactory;
use App\Tests\Support\Admin\RollsBackDatabase;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Test\Factories;

/**
 * `app:crm:purge` (CRM-11, N6, N12): a couple past the cut-off goes, anyone staff still works with stays, one failure
 * costs one id and exit 1, and a good night under `--quiet` prints nothing.
 */
final class PurgeCouplesCommandTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use DispatchesCrmCommands;
    use Factories;
    use RollsBackDatabase {
        setUp as rollBackSetUp;
    }

    private const string CUTOFF = '2025-11-01 10:00:00+00';
    private const string PAST = '2025-11-01 09:59:59+00';
    private const string OLD = '2025-03-01 10:00:00+00';
    private const string RECENT = '2026-03-01 10:00:00+00';

    protected function setUp(): void
    {
        $this->rollBackSetUp();
        self::mockTime('2026-11-01 10:00:00 UTC');
    }

    public function test_an_old_couple_with_an_old_inquiry_entry_is_erased(): void
    {
        $couple = $this->contact(self::OLD);
        $entry = $this->entry($couple, self::OLD);
        $this->em->clear();

        [$status, $output] = $this->purge();

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame('1 couples erased, 0 failed.', trim($output));
        self::assertSame(0, $this->rows('SELECT count(*) FROM crm_contacts WHERE id = ?', $couple->id()));
        self::assertSame(0, $this->rows('SELECT count(*) FROM crm_activities WHERE id = ?', $entry));
        self::assertSame(
            [['command' => EraseContact::class, 'changes' => ['action' => 'erased']]],
            $this->auditRows('contact', $couple->id()),
        );
    }

    public function test_it_keeps_everyone_staff_still_works_with(): void
    {
        $recentEntry = $this->contact(self::OLD);
        $this->entry($recentEntry, self::OLD);
        $this->entry($recentEntry, self::RECENT);
        $recentlyMade = $this->contact(self::RECENT);
        $venueContact = $this->contact(self::OLD, ContactKind::VenueContact);
        $other = $this->contact(self::OLD, ContactKind::Other);
        $withDeal = $this->contact(self::OLD);
        $this->connection->insert('crm_deal_contacts', ['deal_id' => DealFactory::createOne()->id(), 'contact_id' => $withDeal->id()]);
        $this->em->clear();

        [$status, $output] = $this->purge();

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame('0 couples erased, 0 failed.', trim($output));
        foreach ([$recentEntry, $recentlyMade, $venueContact, $other, $withDeal] as $kept) {
            self::assertSame(1, $this->rows('SELECT count(*) FROM crm_contacts WHERE id = ?', $kept->id()));
        }
    }

    public function test_the_cutoff_itself_stays(): void
    {
        $madeAtTheCutoff = $this->contact(self::CUTOFF);
        $entryAtTheCutoff = $this->contact(self::OLD);
        $this->entry($entryAtTheCutoff, self::CUTOFF);
        $past = $this->contact(self::PAST);
        $entryPast = $this->contact(self::OLD);
        $this->entry($entryPast, self::PAST);
        $this->em->clear();

        self::assertSame('2 couples erased, 0 failed.', trim($this->purge()[1]));

        self::assertSame(1, $this->rows('SELECT count(*) FROM crm_contacts WHERE id = ?', $madeAtTheCutoff->id()));
        self::assertSame(1, $this->rows('SELECT count(*) FROM crm_contacts WHERE id = ?', $entryAtTheCutoff->id()));
        self::assertSame(0, $this->rows('SELECT count(*) FROM crm_contacts WHERE id IN (?, ?)', $past->id(), $entryPast->id()));
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $couple = $this->contact(self::OLD);
        $this->entry($couple, self::OLD);
        $this->em->clear();

        [$status, $output] = $this->purge(['--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame('1 couples would be erased.', trim($output));
        self::assertSame(1, $this->rows('SELECT count(*) FROM crm_contacts WHERE id = ?', $couple->id()));
        self::assertSame(1, $this->rows('SELECT count(*) FROM crm_activities'));
        self::assertSame([], $this->auditRows('contact', $couple->id()));
    }

    public function test_a_good_night_under_quiet_prints_nothing(): void
    {
        $couple = $this->contact(self::OLD);
        $this->em->clear();

        [$status, $output] = $this->purge([], OutputInterface::VERBOSITY_QUIET);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame('', $output);
        self::assertSame(0, $this->rows('SELECT count(*) FROM crm_contacts WHERE id = ?', $couple->id()));
    }

    public function test_one_failing_id_is_named_by_class_the_run_goes_on_and_it_exits_1(): void
    {
        $failing = $this->contact(self::OLD);
        $fine = $this->contact(self::OLD);
        $this->em->clear();
        $this->refuseToDelete($failing);

        [$status, $output] = $this->purge();

        self::assertSame(Command::FAILURE, $status);
        self::assertSame(
            [$failing->id().': '.ForeignKeyConstraintViolationException::class, '1 couples erased, 1 failed.'],
            array_values(array_filter(array_map(trim(...), explode(\PHP_EOL, $output)))),
        );
        self::assertSame(1, $this->rows('SELECT count(*) FROM crm_contacts WHERE id = ?', $failing->id()));
        self::assertSame(0, $this->rows('SELECT count(*) FROM crm_contacts WHERE id = ?', $fine->id()));
        self::assertSame([], $this->auditRows('contact', $failing->id()));
    }

    public function test_a_failure_under_quiet_prints_only_its_line(): void
    {
        $failing = $this->contact(self::OLD);
        $this->em->clear();
        $this->refuseToDelete($failing);

        [$status, $output] = $this->purge([], OutputInterface::VERBOSITY_QUIET);

        self::assertSame(Command::FAILURE, $status);
        self::assertSame($failing->id().': '.ForeignKeyConstraintViolationException::class, trim($output));
    }

    public function test_an_entry_on_the_company_stays_anonymised(): void
    {
        $company = CompanyFactory::createOne();
        $couple = $this->contact(self::OLD);
        $shared = ActivityFactory::new()->ofType(ActivityType::INQUIRY)->onContact($couple)->onCompany($company)->create([
            'occurredAt' => new \DateTimeImmutable(self::OLD),
            'title' => 'Schloss Seeblick',
            'body' => 'Sina Huber',
        ]);
        $this->em->clear();

        self::assertSame('1 couples erased, 0 failed.', trim($this->purge()[1]));

        self::assertSame(
            ['title' => null, 'body' => null, 'anonymised' => true, 'on_company' => 1],
            $this->connection->fetchAssociative(
                'SELECT title, body, anonymised_at IS NOT NULL AS anonymised,'
                .' (SELECT count(*) FROM crm_activity_companies l WHERE l.activity_id = a.id) AS on_company FROM crm_activities a WHERE id = ?',
                [$shared->id()],
            ),
        );
    }

    public function test_the_inquiries_purge_then_this_one_leave_no_link_and_no_contact(): void
    {
        $inquiry = InquiryFactory::createOne(['createdAt' => new \DateTimeImmutable(self::OLD)]);
        $this->dispatch(new IngestInquiry($inquiry->id()));
        $this->connection->executeStatement('UPDATE crm_contacts SET created_at = ?', [self::OLD]);
        $this->em->clear();

        $this->console('app:inquiries:purge');
        self::assertSame(0, $this->rows('SELECT count(*) FROM crm_inquiry_links'));
        self::assertSame(1, $this->rows('SELECT count(*) FROM crm_contacts'));

        self::assertSame('1 couples erased, 0 failed.', trim($this->purge()[1]));

        self::assertSame(0, $this->rows('SELECT count(*) FROM inquiries'));
        self::assertSame(0, $this->rows('SELECT count(*) FROM crm_inquiry_links'));
        self::assertSame(0, $this->rows('SELECT count(*) FROM crm_contacts'));
        self::assertSame(0, $this->rows('SELECT count(*) FROM crm_activities'));
    }

    private function contact(string $createdAt, ContactKind $kind = ContactKind::Couple): Contact
    {
        $contact = ContactFactory::createOne(['kind' => $kind]);
        $this->connection->executeStatement('UPDATE crm_contacts SET created_at = ? WHERE id = ?', [$createdAt, $contact->id()]);

        return $contact;
    }

    private function entry(Contact $contact, string $occurredAt): string
    {
        return ActivityFactory::new()->ofType(ActivityType::INQUIRY)->onContact($contact)->create(['occurredAt' => new \DateTimeImmutable($occurredAt)])->id();
    }

    /**
     * Nothing in the schema holds a contact back, so a table made in the test's transaction does.
     */
    private function refuseToDelete(Contact $contact): void
    {
        $this->connection->executeStatement('CREATE TABLE test_holds_contact (contact_id UUID NOT NULL REFERENCES crm_contacts (id))');
        $this->connection->insert('test_holds_contact', ['contact_id' => $contact->id()]);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{int, string}
     */
    private function purge(array $input = [], int $verbosity = OutputInterface::VERBOSITY_NORMAL): array
    {
        return $this->console('app:crm:purge', $input, $verbosity);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{int, string}
     */
    private function console(string $command, array $input = [], int $verbosity = OutputInterface::VERBOSITY_NORMAL): array
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $tester = new CommandTester((new Application($kernel))->find($command));
        $status = $tester->execute($input, ['verbosity' => $verbosity]);

        return [$status, $tester->getDisplay()];
    }
}
