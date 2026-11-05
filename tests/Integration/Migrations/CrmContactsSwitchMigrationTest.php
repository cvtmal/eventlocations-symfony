<?php

declare(strict_types=1);

namespace App\Tests\Integration\Migrations;

use App\Crm\CommandHandler\LinkLocationManagerHandler;
use App\Crm\Entity\ActivityType;
use App\Crm\Entity\Company;
use App\Crm\ValueObject\CompanyOrigin;
use App\Factory\ActivityFactory;
use App\Factory\AdminUserFactory;
use App\Factory\CompanyFactory;
use App\Factory\DealFactory;
use App\Factory\InquiryFactory;
use App\Factory\PortalUserFactory;
use App\Factory\PortalVenueAccessFactory;
use App\Factory\VenueFactory;
use App\Geo\Entity\Canton;
use App\Shared\Uid\EntityId;
use App\Venues\Entity\Venue;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

use function Zenstruck\Foundry\Persistence\repository;

/**
 * The #55 migration, run down and up inside the test's transaction (Postgres DDL is transactional, so the rollback in
 * tearDown() puts app_test back on the head schema). Fixtures: Foundry only for tables and mappings #55 leaves alone,
 * created before down(); the old-shape rows (contacts with a kind, the two link tables, tasks, inquiry links) by raw
 * SQL after down(), so the test holds with the old code and with the new. #56 dropped `crm_company_venues`, so a down
 * first runs #56's down(), which brings the link rows back from `crm_companies.venue_id`.
 */
final class CrmContactsSwitchMigrationTest extends KernelTestCase
{
    use Factories;

    /**
     * Migrations are loaded by path, not autoloaded, so the class is named from the file and
     * stays out of PHPStan's sight.
     */
    private const MIGRATION_FILE = 'migrations/Version20261105193544.php';

    /** #56, which reads the link table #55's up() needs back into `crm_companies.venue_id`. */
    private const NEXT_MIGRATION_FILE = 'migrations/Version20261105214935.php';

    /** FK order; the two link tables exist only on the old schema. */
    private const TABLES = [
        'crm_inquiry_links', 'crm_tasks', 'crm_activity_deals', 'crm_activity_companies', 'crm_activity_contacts',
        'crm_activities', 'crm_deal_contacts', 'crm_deals', 'crm_contacts', 'crm_company_venues', 'crm_companies',
        'portal_venue_access', 'portal_users', 'inquiries',
    ];

    /** Older than anything up() writes, so down()'s "oldest keeps the e-mail" is the fixture row. */
    private const FIXTURE_TIME = '2026-01-01 10:00:00+00';

    private Connection $connection;
    private EntityManagerInterface $em;
    private string $adminId;

    /** @var list<string> */
    private array $logLines = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->connection = $em->getConnection();
        $this->connection->beginTransaction();

        foreach (self::TABLES as $table) {
            if (null !== $this->connection->fetchOne('SELECT to_regclass(?)', [$table])) {
                $this->connection->executeStatement('DELETE FROM '.$table);
            }
        }
        $this->adminId = AdminUserFactory::createOne()->id();
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function test_the_switch_applies_every_rule_to_the_production_shape(): void
    {
        $ids = $this->prodShape();
        $orphansBefore = $this->orphanTasks();
        self::assertCount(18, $orphansBefore);
        $entriesBefore = $this->intOf('SELECT count(*) FROM crm_activities');

        $this->migrate('up', $this->logger());

        self::assertSame(0, $this->intOf("SELECT count(*) FROM crm_contacts WHERE email LIKE 'paar%'"));
        self::assertSame(2, $this->intOf('SELECT count(*) FROM inquiries'));
        self::assertSame(2, $this->intOf('SELECT count(*) FROM crm_inquiry_links'));
        self::assertSame(
            [$ids['L1'], $ids['L2']],
            $this->connection->fetchFirstColumn("SELECT l.company_id FROM crm_activities a JOIN crm_activity_companies l ON l.activity_id = a.id WHERE a.type_code = 'inquiry' ORDER BY a.title"),
        );
        self::assertNull($this->connection->fetchOne("SELECT to_regclass('crm_activity_contacts')"));
        self::assertNull($this->connection->fetchOne("SELECT to_regclass('crm_deal_contacts')"));
        foreach (['crm_tasks.contact_id', 'crm_inquiry_links.contact_id', 'crm_contacts.kind', 'crm_contacts.owner_id'] as $column) {
            self::assertFalse($this->hasColumn($column), $column.' is still there');
        }

        // C6: P onto Y; E6: R onto Z, Q already on the deal's Location; manager M1 onto Y; M2 already on W; M3 pending.
        self::assertSame(['Peter', 'Pfister', '+41 44 000 00 01', 'Leiter'], $this->person($ids['Y'], 'p@example.com'));
        self::assertSame(['Rita', 'Roth', null, null], $this->person($ids['Z'], 'r@example.com'));
        self::assertSame(1, $this->intOf("SELECT count(*) FROM crm_contacts WHERE email = 'q@example.com'"));
        self::assertSame(['Mara', 'Meier', '+41 79 000 00 01', LinkLocationManagerHandler::MANAGER_POSITION], $this->person($ids['Y'], 'm1@example.com'));
        self::assertSame(1, $this->intOf("SELECT count(*) FROM crm_contacts WHERE email = 'm2@example.com'"));
        self::assertSame(0, $this->intOf("SELECT count(*) FROM crm_contacts WHERE email = 'm3@example.com'"));
        self::assertSame(14, $this->intOf('SELECT count(*) FROM crm_contacts'));
        self::assertSame(1, $this->intOf(\sprintf("SELECT count(*) FROM crm_contacts WHERE company_id = '%s'", $ids['LI'])));

        // F2 moves to its contact's Location; the 18 tasks on nothing are untouched.
        self::assertSame($ids['L1'], $this->connection->fetchOne('SELECT company_id FROM crm_tasks WHERE id = ?', [$ids['F2']]));
        self::assertSame($orphansBefore, $this->orphanTasks());
        self::assertSame(19, $this->intOf('SELECT count(*) FROM crm_tasks'));

        // D2 gets its contact's Location; the C6 entry keeps only the Location it named; no entry is deleted.
        self::assertSame([$ids['L1']], $this->companiesOf($ids['D2']));
        self::assertSame([$ids['Y']], $this->companiesOf($ids['C6entry']));
        self::assertSame($entriesBefore, $this->intOf('SELECT count(*) FROM crm_activities'));

        self::assertContains('#55 pre: C3 couples = 2', $this->logLines);
        self::assertContains('#55 pre: F5 tasks on nothing = 18', $this->logLines);
        self::assertContains('#55 pre: I1 active manager access rows = 2', $this->logLines);
    }

    public function test_the_new_constraints_hold(): void
    {
        $x = CompanyFactory::createOne()->id();
        $y = CompanyFactory::createOne()->id();
        $deal = DealFactory::createOne(['company' => $this->company($x)])->id();
        $this->em->clear();
        $this->migrate('down');
        $this->migrate('up');

        $this->connection->insert('crm_contacts', ['id' => EntityId::generate(), 'last_name' => 'Eins', 'email' => 'same@example.com', 'company_id' => $x]);
        $this->connection->insert('crm_contacts', ['id' => EntityId::generate(), 'last_name' => 'Zwei', 'email' => 'same@example.com', 'company_id' => $y]);
        self::assertSame(2, $this->intOf("SELECT count(*) FROM crm_contacts WHERE email = 'same@example.com'"));

        $this->assertRefused('uk_crm_contacts_company_email', \sprintf("INSERT INTO crm_contacts (id, last_name, email, company_id) VALUES ('%s', 'Drei', 'same@example.com', '%s')", EntityId::generate(), $x));
        $this->assertRefused('company_id', \sprintf("INSERT INTO crm_contacts (id, last_name) VALUES ('%s', 'Lose')", EntityId::generate()));
        $this->assertRefused('chk_crm_tasks_one_object', \sprintf(
            "INSERT INTO crm_tasks (id, title, due_on, assignee_id, company_id, deal_id) VALUES ('%s', 'Beides', '2026-12-01', '%s', '%s', '%s')",
            EntityId::generate(), $this->adminId, $x, $deal,
        ));
        $this->task();
        self::assertSame(1, $this->intOf('SELECT count(*) FROM crm_tasks'));

        $this->connection->executeStatement('DELETE FROM crm_companies WHERE id = ?', [$y]);
        self::assertSame(0, $this->intOf(\sprintf("SELECT count(*) FROM crm_contacts WHERE company_id = '%s'", $y)));
        self::assertSame(1, $this->intOf("SELECT count(*) FROM crm_contacts WHERE email = 'same@example.com'"));
    }

    public function test_aborts_on_a6_a_company_with_two_venues(): void
    {
        $company = CompanyFactory::new()->withVenue($this->venue())->create()->id();
        $second = $this->venue();
        $this->em->clear();
        $this->migrate('down');
        $this->connection->insert('crm_company_venues', ['id' => EntityId::generate(), 'company_id' => $company, 'venue_id' => $second->id()]);

        $this->assertAbortsWith('A6 = 1 (companies with 2+ venues)', 0);
    }

    public function test_aborts_on_v2_a_venue_on_two_companies(): void
    {
        $venue = $this->venue();
        CompanyFactory::new()->withVenue($venue)->create();
        $other = CompanyFactory::createOne()->id();
        $this->em->clear();
        $this->migrate('down');
        $this->connection->executeStatement('ALTER TABLE crm_company_venues DROP CONSTRAINT uk_crm_company_venues_venue_id');
        $this->connection->insert('crm_company_venues', ['id' => EntityId::generate(), 'company_id' => $other, 'venue_id' => $venue->id()]);

        $this->assertAbortsWith('V2 = 1 (venues on 2+ companies)', 0);
    }

    public function test_aborts_on_c4_a_contact_without_a_company(): void
    {
        $this->migrate('down');
        $this->contact(null, 'Lose', 'lose@example.com', 'other');

        $this->assertAbortsWith('C4 = 1 (non-couple contacts without a company)', 1);
    }

    public function test_aborts_on_c5_a_couple_with_a_company(): void
    {
        $company = CompanyFactory::createOne()->id();
        $this->em->clear();
        $this->migrate('down');
        $this->contact($company, 'Paar', 'paar@example.com', 'couple');

        $this->assertAbortsWith('C5 = 1 (couples with a company)', 1);
    }

    public function test_aborts_on_d4_a_staff_entry_on_a_couple(): void
    {
        $company = CompanyFactory::createOne();
        $note = ActivityFactory::new()->onCompany($company)->create()->id();
        $this->em->clear();
        $this->migrate('down');
        $couple = $this->contact(null, 'Paar', 'paar@example.com', 'couple');
        $this->connection->insert('crm_activity_contacts', ['activity_id' => $note, 'contact_id' => $couple]);

        $this->assertAbortsWith('D4 = 1 (staff entries on a couple)', 1);
    }

    public function test_aborts_on_d6_an_inquiry_entry_without_a_company(): void
    {
        ActivityFactory::new()->ofType(ActivityType::INQUIRY)->create(['title' => 'Schloss']);
        $this->em->clear();
        $this->migrate('down');

        $this->assertAbortsWith('D6 = 1 (inquiry entries with no company link)', 0);
    }

    /** Never measured on production: an entry nothing points at is not deleted unseen, the deploy stops instead. */
    public function test_aborts_on_x0_an_entry_with_no_link(): void
    {
        $entry = ActivityFactory::createOne()->id();
        $this->em->clear();
        $this->migrate('down');

        $this->assertAbortsWith('X0 = 1 (entries with no link before the switch)', 0);
        self::assertSame(1, $this->intOf(\sprintf("SELECT count(*) FROM crm_activities WHERE id = '%s'", $entry)));
    }

    public function test_aborts_on_e7_a_deal_linked_to_a_couple(): void
    {
        $deal = DealFactory::createOne()->id();
        $this->em->clear();
        $this->migrate('down');
        $couple = $this->contact(null, 'Paar', 'paar@example.com', 'couple');
        $this->connection->insert('crm_deal_contacts', ['deal_id' => $deal, 'contact_id' => $couple]);

        $this->assertAbortsWith('E7 = 1 (deal links to a couple)', 1);
    }

    public function test_aborts_on_a_task_on_a_couple(): void
    {
        $this->migrate('down');
        $couple = $this->contact(null, 'Paar', 'paar@example.com', 'couple');
        $this->task(contactId: $couple);

        $this->assertAbortsWith('T-couple = 1 (tasks on a couple)', 1);
    }

    public function test_one_abort_names_every_failing_metric(): void
    {
        $note = ActivityFactory::new()->onCompany(CompanyFactory::createOne())->create()->id();
        $this->em->clear();
        $this->migrate('down');
        $this->contact(null, 'Lose', 'lose@example.com', 'other');
        $this->contact(null, 'Lose', 'lose2@example.com', 'venue_contact');
        $couple = $this->contact(null, 'Paar', 'paar@example.com', 'couple');
        $this->connection->insert('crm_activity_contacts', ['activity_id' => $note, 'contact_id' => $couple]);

        $message = $this->assertAbortsWith('C4 = 2 (non-couple contacts without a company)', 3);
        self::assertStringContainsString('D4 = 1 (staff entries on a couple)', $message);
        self::assertStringContainsString('See docs/operations.md "CRM contacts switch (#55)"', $message);
        self::assertStringNotContainsString('C3', $message);
    }

    public function test_couples_alone_never_abort(): void
    {
        $venue = $this->venue();
        $company = CompanyFactory::new()->withVenue($venue)->create();
        $inquiry = InquiryFactory::createOne(['venue' => $venue])->id();
        $entry = ActivityFactory::new()->ofType(ActivityType::INQUIRY)->onCompany($company)->create(['title' => 'Schloss'])->id();
        $this->em->clear();
        $this->migrate('down');
        $couple = $this->contact(null, 'Paar', 'paar1@example.com', 'couple');
        $this->contact(null, 'Paar', 'paar2@example.com', 'couple');
        $this->contact(null, 'Paar', 'paar3@example.com', 'couple');
        $this->connection->insert('crm_activity_contacts', ['activity_id' => $entry, 'contact_id' => $couple]);
        $this->connection->insert('crm_inquiry_links', ['inquiry_id' => $inquiry, 'contact_id' => $couple, 'activity_id' => $entry]);

        $this->migrate('up');

        self::assertSame(0, $this->intOf('SELECT count(*) FROM crm_contacts'));
        self::assertSame(1, $this->intOf('SELECT count(*) FROM inquiries'));
        self::assertSame($entry, $this->connection->fetchOne('SELECT activity_id FROM crm_inquiry_links WHERE inquiry_id = ?', [$inquiry]));
        self::assertSame([$company->id()], $this->companiesOf($entry));
    }

    public function test_a_postcondition_failure_rolls_back(): void
    {
        $venueId = $this->venue()->id();
        $this->em->clear();
        $this->migrate('down');
        $this->contact(null, 'Paar', 'paar@example.com', 'couple');

        $migration = $this->migration();
        $migration->up(new Schema()); // reads the pre-counts: 0 inquiries
        InquiryFactory::createOne(['venue' => $this->em->find(Venue::class, $venueId)]);

        $message = $this->failing(fn () => $this->execute($migration));

        self::assertStringContainsString('#55 postcondition failed: P-inquiries: expected 0, got 1', $message);
        $this->assertOldShapeIntact(1);
    }

    public function test_down_restores_the_old_schema_and_keeps_the_rows(): void
    {
        $this->prodShape();
        $this->migrate('up');
        $this->migrate('down');

        foreach (['crm_activity_contacts', 'crm_deal_contacts'] as $table) {
            self::assertSame($table, $this->connection->fetchOne(\sprintf("SELECT to_regclass('%s')::text", $table)));
            self::assertSame(0, $this->intOf('SELECT count(*) FROM '.$table));
        }
        foreach (['crm_tasks.contact_id', 'crm_inquiry_links.contact_id', 'crm_contacts.kind', 'crm_contacts.owner_id'] as $column) {
            self::assertTrue($this->hasColumn($column), $column.' is missing');
        }

        // The constraint names of the three original migrations (Version20261031102722, Version20261031232837,
        // Version20261101115604); contype 'n' are Postgres 18's NOT NULL entries.
        $expected = [
            'crm_contacts' => ['chk_crm_contacts_email_lower', 'chk_crm_contacts_kind', 'chk_crm_contacts_last_name', 'fk_crm_contacts_company_id', 'fk_crm_contacts_owner_id', 'pk_crm_contacts', 'uk_crm_contacts_email'],
            'crm_tasks' => ['chk_crm_tasks_one_object', 'chk_crm_tasks_renewal', 'chk_crm_tasks_title', 'fk_crm_tasks_assignee_id', 'fk_crm_tasks_company_id', 'fk_crm_tasks_contact_id', 'fk_crm_tasks_created_by_id', 'fk_crm_tasks_deal_id', 'pk_crm_tasks'],
            'crm_inquiry_links' => ['chk_crm_inquiry_links_status', 'fk_crm_inquiry_links_activity_id', 'fk_crm_inquiry_links_contact_id', 'fk_crm_inquiry_links_inquiry_id', 'pk_crm_inquiry_links'],
            'crm_activity_contacts' => ['fk_crm_activity_contacts_activity_id', 'fk_crm_activity_contacts_contact_id', 'pk_crm_activity_contacts'],
            'crm_deal_contacts' => ['fk_crm_deal_contacts_contact_id', 'fk_crm_deal_contacts_deal_id', 'pk_crm_deal_contacts'],
        ];
        foreach ($expected as $table => $names) {
            self::assertSame($names, $this->connection->fetchFirstColumn(
                "SELECT conname FROM pg_constraint WHERE conrelid = ?::regclass AND contype <> 'n' ORDER BY conname",
                [$table],
            ), $table);
        }
        self::assertSame('CHECK ((num_nonnulls(company_id, contact_id, deal_id) <= 1))', $this->connection->fetchOne("SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conname = 'chk_crm_tasks_one_object'"));
        self::assertSame('n', $this->connection->fetchOne("SELECT confdeltype FROM pg_constraint WHERE conname = 'fk_crm_contacts_company_id'"));
        self::assertSame('r', $this->connection->fetchOne("SELECT confdeltype FROM pg_constraint WHERE conname = 'fk_crm_contacts_owner_id'"));
        self::assertSame('n', $this->connection->fetchOne("SELECT confdeltype FROM pg_constraint WHERE conname = 'fk_crm_inquiry_links_contact_id'"));
        self::assertSame('c', $this->connection->fetchOne("SELECT confdeltype FROM pg_constraint WHERE conname = 'fk_crm_tasks_contact_id'"));
        foreach (['idx_crm_contacts_owner_id', 'idx_crm_tasks_contact_id', 'idx_crm_inquiry_links_contact_id', 'idx_crm_activity_contacts_activity_id', 'idx_crm_activity_contacts_contact_id', 'idx_crm_deal_contacts_deal_id', 'idx_crm_deal_contacts_contact_id'] as $index) {
            self::assertNotNull($this->connection->fetchOne('SELECT to_regclass(?)', [$index]), $index);
        }
        self::assertNull($this->connection->fetchOne("SELECT to_regclass('uk_crm_contacts_company_email')"));
        self::assertNull($this->connection->fetchOne("SELECT column_default FROM information_schema.columns WHERE table_name = 'crm_contacts' AND column_name = 'kind'"));
        self::assertSame('YES', $this->connection->fetchOne("SELECT is_nullable FROM information_schema.columns WHERE table_name = 'crm_contacts' AND column_name = 'company_id'"));

        self::assertSame(14, $this->intOf('SELECT count(*) FROM crm_contacts'));
        self::assertSame(14, $this->intOf("SELECT count(*) FROM crm_contacts WHERE kind = 'venue_contact'"));
        // P sits on X (fixture) and on Y (copy): the older row keeps the e-mail.
        self::assertSame(2, $this->intOf("SELECT count(*) FROM crm_contacts WHERE last_name = 'Pfister'"));
        self::assertSame(['2026-01-01 10:00:00'], $this->connection->fetchFirstColumn("SELECT to_char(created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') FROM crm_contacts WHERE email = 'p@example.com'"));
    }

    public function test_rules_copy_only_what_is_missing(): void
    {
        $x = CompanyFactory::createOne();
        $yVenue = $this->venue();
        $y = CompanyFactory::new()->withVenue($yVenue)->create();
        $deal = DealFactory::createOne(['company' => $y])->id();
        $entry = ActivityFactory::new()->onCompany($y)->create()->id();
        $manager = PortalUserFactory::createOne(['email' => 'chef@example.com']);
        PortalVenueAccessFactory::new()->active()->create(['user' => $manager, 'venue' => $yVenue]);
        $this->em->clear();
        $this->migrate('down');

        // E6 already on the deal's Location; C6 without an e-mail already there by name (case aside); a manager whose
        // e-mail is already there.
        $onDeal = $this->contact($y->id(), 'Dahinden', 'd@example.com');
        $this->connection->insert('crm_deal_contacts', ['deal_id' => $deal, 'contact_id' => $onDeal]);
        $noMail = $this->contact($x->id(), 'Muster', null, 'venue_contact', 'Anna');
        $this->contact($y->id(), 'MUSTER', null, 'venue_contact', 'anna');
        $this->connection->insert('crm_activity_contacts', ['activity_id' => $entry, 'contact_id' => $noMail]);
        $this->contact($y->id(), 'Chef', 'chef@example.com');

        $this->migrate('up');

        self::assertSame(4, $this->intOf('SELECT count(*) FROM crm_contacts'));
        self::assertNull($this->connection->fetchOne("SELECT position FROM crm_contacts WHERE email = 'chef@example.com'"));
    }

    public function test_one_person_expected_twice_gets_one_row_with_the_crm_values(): void
    {
        $x = CompanyFactory::createOne();
        $zVenue = $this->venue();
        $z = CompanyFactory::new()->withVenue($zVenue)->create();
        $deal = DealFactory::createOne(['company' => $z])->id();
        $manager = PortalUserFactory::createOne(['email' => 'r@example.com', 'firstName' => 'Portal', 'lastName' => 'Name']);
        PortalVenueAccessFactory::new()->active()->create(['user' => $manager, 'venue' => $zVenue]);
        $this->em->clear();
        $this->migrate('down');
        $r = $this->contact($x->id(), 'Roth', 'r@example.com', 'venue_contact', 'Rita');
        $this->connection->insert('crm_deal_contacts', ['deal_id' => $deal, 'contact_id' => $r]);

        $this->migrate('up');

        self::assertSame(['Rita', 'Roth', null, null], $this->person($z->id(), 'r@example.com'));
        self::assertSame(2, $this->intOf('SELECT count(*) FROM crm_contacts'));
    }

    public function test_up_after_down_passes_again(): void
    {
        $ids = $this->prodShape();
        $this->migrate('up');
        $this->migrate('down');
        $this->migrate('up');

        // down() blanked the e-mail of M1's copy on Y (the older row on L1 kept it), so M1 is copied onto Y once more.
        self::assertSame(15, $this->intOf('SELECT count(*) FROM crm_contacts'));
        self::assertSame(['Mara', 'Meier', '+41 79 000 00 01', LinkLocationManagerHandler::MANAGER_POSITION], $this->person($ids['Y'], 'm1@example.com'));
        self::assertNull($this->connection->fetchOne("SELECT to_regclass('crm_activity_contacts')"));
    }

    /** Writers committing during an unattended deploy must wait, not move the counts the postconditions compare. */
    public function test_up_locks_the_counted_tables_against_writers_before_it_reads(): void
    {
        $this->migrate('down');
        $migration = $this->migration();
        $migration->up(new Schema());

        /** @var list<string> $expected */
        $expected = \constant($migration::class.'::LOCKED');
        self::assertNotEmpty($expected);
        // down()'s foreign keys hold the same mode on a few tables already; every counted table must be among them.
        $held = $this->connection->fetchFirstColumn(
            "SELECT c.relname FROM pg_locks l JOIN pg_class c ON c.oid = l.relation WHERE l.pid = pg_backend_pid() AND l.mode = 'ShareRowExclusiveLock' AND l.granted",
        );
        self::assertSame([], array_values(array_diff($expected, $held)));
        self::assertSame('15s', $this->connection->fetchOne('SHOW lock_timeout'));
    }

    public function test_the_manager_position_is_the_one_the_code_writes(): void
    {
        self::assertSame(LinkLocationManagerHandler::MANAGER_POSITION, \constant($this->migration()::class.'::MANAGER_POSITION'));
    }

    /**
     * The production shape at small scale (research §Migration test): prospects, one-venue Locations, an imported LI
     * Location, the C6 person, both E6 variants, a D2 entry, 18 tasks on nothing, a task on a contact, two couples with
     * their inquiries and three managers.
     *
     * @return array<string, string>
     */
    private function prodShape(): array
    {
        $prospect1 = CompanyFactory::createOne(['name' => 'Prospekt Eins']);
        $prospect2 = CompanyFactory::createOne(['name' => 'Prospekt Zwei']);
        $prospect3 = CompanyFactory::createOne(['name' => 'Prospekt Drei']);
        $dealP1 = DealFactory::createOne(['company' => $prospect1])->id();
        $dealZ = DealFactory::createOne(['company' => $prospect2])->id();

        $v1 = $this->venue();
        $v2 = $this->venue();
        $vW = $this->venue();
        $l1 = CompanyFactory::new()->withVenue($v1)->create(['name' => 'Location Eins', 'origin' => CompanyOrigin::Venue]);
        $l2 = CompanyFactory::new()->withVenue($v2)->create(['name' => 'Location Zwei', 'origin' => CompanyOrigin::Venue]);
        $w = CompanyFactory::new()->withVenue($vW)->create(['name' => 'Location W', 'origin' => CompanyOrigin::Venue]);
        $canton = repository(Canton::class)->find('li');
        self::assertInstanceOf(Canton::class, $canton);
        $li = CompanyFactory::new()->withVenue(VenueFactory::new()->withoutLocation($canton)->create())->create(['name' => 'Vaduz Import']);

        $iq1 = InquiryFactory::createOne(['venue' => $v1])->id();
        $iq2 = InquiryFactory::createOne(['venue' => $v2])->id();
        $entry1 = ActivityFactory::new()->ofType(ActivityType::INQUIRY)->onCompany($l1)->create(['title' => 'A Anfrage'])->id();
        $entry2 = ActivityFactory::new()->ofType(ActivityType::INQUIRY)->onCompany($l2)->create(['title' => 'B Anfrage'])->id();
        $c6Entry = ActivityFactory::new()->onCompany($l2)->create()->id();
        $d2Entry = ActivityFactory::createOne()->id();

        $m1 = PortalUserFactory::createOne(['email' => 'm1@example.com', 'firstName' => 'Mara', 'lastName' => 'Meier']);
        $m2 = PortalUserFactory::createOne(['email' => 'm2@example.com']);
        $m3 = PortalUserFactory::createOne(['email' => 'm3@example.com']);
        PortalVenueAccessFactory::new()->active()->create(['user' => $m1, 'venue' => $v2]);
        PortalVenueAccessFactory::new()->active()->create(['user' => $m2, 'venue' => $vW]);
        PortalVenueAccessFactory::createOne(['user' => $m3, 'venue' => $v1]);
        $this->connection->executeStatement("UPDATE portal_users SET phone = '+41 79 000 00 01' WHERE email = 'm1@example.com'");
        $this->em->clear();

        $this->migrate('down');

        $q = $this->contact($prospect1->id(), 'Quadri', 'q@example.com', 'venue_contact', 'Quirin');
        $this->contact($prospect2->id(), 'Zweifel', 'z@example.com');
        $this->contact($prospect3->id(), 'Dreier', 'drei@example.com', 'other');
        $x1 = $this->contact($l1->id(), 'Eins', 'x1@example.com');
        $this->contact($l2->id(), 'Zwei', 'y1@example.com');
        $this->contact($l2->id(), 'Ohnemail', null);
        $this->contact($li->id(), 'Vaduzer', 'li@example.com');
        $p = $this->contact($l1->id(), 'Pfister', 'p@example.com', 'venue_contact', 'Peter', '+41 44 000 00 01', 'Leiter');
        $r = $this->contact($l1->id(), 'Roth', 'r@example.com', 'venue_contact', 'Rita');
        $this->contact($l1->id(), 'Meier', 'm1@example.com', 'other', 'Mara');
        $this->contact($w->id(), 'Zweiter', 'm2@example.com');
        $couple1 = $this->contact(null, 'Paar', 'paar1@example.com', 'couple');
        $couple2 = $this->contact(null, 'Paar', 'paar2@example.com', 'couple');

        $this->connection->insert('crm_activity_contacts', ['activity_id' => $c6Entry, 'contact_id' => $p]);
        $this->connection->insert('crm_activity_contacts', ['activity_id' => $d2Entry, 'contact_id' => $x1]);
        $this->connection->insert('crm_activity_contacts', ['activity_id' => $entry1, 'contact_id' => $couple1]);
        $this->connection->insert('crm_activity_contacts', ['activity_id' => $entry2, 'contact_id' => $couple2]);
        $this->connection->insert('crm_deal_contacts', ['deal_id' => $dealP1, 'contact_id' => $q]);
        $this->connection->insert('crm_deal_contacts', ['deal_id' => $dealZ, 'contact_id' => $r]);
        $this->connection->insert('crm_inquiry_links', ['inquiry_id' => $iq1, 'contact_id' => $couple1, 'activity_id' => $entry1]);
        $this->connection->insert('crm_inquiry_links', ['inquiry_id' => $iq2, 'contact_id' => $couple2, 'activity_id' => $entry2]);

        for ($i = 1; $i <= 18; ++$i) {
            $this->task(title: 'Aufgabe '.$i, done: $i <= 3);
        }
        $f2 = $this->task(contactId: $x1);

        return [
            'L1' => $l1->id(), 'L2' => $l2->id(), 'Y' => $l2->id(), 'Z' => $prospect2->id(), 'LI' => $li->id(),
            'F2' => $f2, 'D2' => $d2Entry, 'C6entry' => $c6Entry,
        ];
    }

    private function venue(): Venue
    {
        return VenueFactory::new()->withoutLocation()->withContact()->create();
    }

    private function company(string $id): Company
    {
        $company = $this->em->find(Company::class, $id);
        self::assertInstanceOf(Company::class, $company);

        return $company;
    }

    /** An old-shape contact row; only valid between down() and up(). */
    private function contact(?string $companyId, string $lastName, ?string $email, string $kind = 'venue_contact', ?string $firstName = null, ?string $phone = null, ?string $position = null): string
    {
        $id = EntityId::generate();
        $this->connection->insert('crm_contacts', [
            'id' => $id, 'first_name' => $firstName, 'last_name' => $lastName, 'email' => $email, 'phone' => $phone,
            'position' => $position, 'kind' => $kind, 'company_id' => $companyId,
            'created_at' => self::FIXTURE_TIME, 'updated_at' => self::FIXTURE_TIME,
        ]);

        return $id;
    }

    private function task(string $title = 'Aufgabe', ?string $contactId = null, bool $done = false): string
    {
        $id = EntityId::generate();
        $row = [
            'id' => $id, 'title' => $title, 'due_on' => '2026-12-01', 'assignee_id' => $this->adminId,
            'created_by_id' => $this->adminId, 'done_at' => $done ? self::FIXTURE_TIME : null,
            'created_at' => self::FIXTURE_TIME, 'updated_at' => self::FIXTURE_TIME,
        ];
        if (null !== $contactId) {
            $row['contact_id'] = $contactId;
        }
        $this->connection->insert('crm_tasks', $row);

        return $id;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function orphanTasks(): array
    {
        $onNothing = $this->hasColumn('crm_tasks.contact_id') ? 'num_nonnulls(company_id, contact_id, deal_id) = 0' : 'num_nonnulls(company_id, deal_id) = 0';

        return $this->connection->fetchAllAssociative('SELECT id, title, done_at, created_by_id, updated_at FROM crm_tasks WHERE '.$onNothing.' ORDER BY id');
    }

    /**
     * @return list<string|null>
     */
    private function person(string $companyId, string $email): array
    {
        $row = $this->connection->fetchAssociative('SELECT first_name, last_name, phone, position FROM crm_contacts WHERE company_id = ? AND email = ?', [$companyId, $email]);
        self::assertIsArray($row, $email.' is not on '.$companyId);

        return array_map(static fn (mixed $value): ?string => null === $value ? null : (string) $value, array_values($row));
    }

    /**
     * @return list<mixed>
     */
    private function companiesOf(string $activityId): array
    {
        return $this->connection->fetchFirstColumn('SELECT company_id FROM crm_activity_companies WHERE activity_id = ? ORDER BY company_id', [$activityId]);
    }

    private function hasColumn(string $tableDotColumn): bool
    {
        [$table, $column] = explode('.', $tableDotColumn);

        return 1 === $this->intOf(\sprintf("SELECT count(*) FROM information_schema.columns WHERE table_name = '%s' AND column_name = '%s'", $table, $column));
    }

    private function assertAbortsWith(string $metric, int $contacts): string
    {
        $message = $this->failing(fn () => $this->migrate('up'));

        self::assertStringContainsString('#55 precondition failed: ', $message);
        self::assertStringContainsString($metric, $message);
        $this->assertOldShapeIntact($contacts);

        return $message;
    }

    private function assertOldShapeIntact(int $contacts): void
    {
        self::assertNotNull($this->connection->fetchOne("SELECT to_regclass('crm_activity_contacts')"));
        self::assertTrue($this->hasColumn('crm_contacts.kind'));
        self::assertNotNull($this->connection->fetchOne("SELECT to_regclass('uk_crm_contacts_email')"));
        self::assertSame($contacts, $this->intOf('SELECT count(*) FROM crm_contacts'));
    }

    private function assertRefused(string $constraint, string $sql): void
    {
        self::assertStringContainsString($constraint, $this->failing(fn () => $this->connection->executeStatement($sql)));
    }

    private function migrate(string $direction, ?LoggerInterface $logger = null): void
    {
        if ('down' === $direction && $this->hasColumn('crm_companies.venue_id')) {
            $next = $this->migration(file: self::NEXT_MIGRATION_FILE);
            $next->down(new Schema());
            $this->execute($next);
        }
        $migration = $this->migration($logger);
        'up' === $direction ? $migration->up(new Schema()) : $migration->down(new Schema());
        $this->execute($migration);
    }

    /** A fresh instance per direction: addSql() accumulates. */
    private function migration(?LoggerInterface $logger = null, string $file = self::MIGRATION_FILE): AbstractMigration
    {
        $file = \dirname(__DIR__, 3).'/'.$file;
        require_once $file;
        $class = 'DoctrineMigrations\\'.basename($file, '.php');
        $migration = new $class($this->connection, $logger ?? new NullLogger());
        self::assertInstanceOf(AbstractMigration::class, $migration);

        return $migration;
    }

    private function execute(AbstractMigration $migration): void
    {
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    /**
     * A failing statement aborts the whole Postgres transaction, so it runs inside a savepoint the test rolls back
     * to; the outer transaction stays usable.
     */
    private function failing(callable $statements): string
    {
        $this->connection->createSavepoint('m55');
        try {
            $statements();
        } catch (DbalException $e) {
            $this->connection->rollbackSavepoint('m55');

            return $e->getMessage();
        }

        self::fail('The statements ran through, an abort was expected.');
    }

    private function intOf(string $sql): int
    {
        return (int) $this->connection->fetchOne($sql);
    }

    /**
     * Collects the migration's write() lines in $this->logLines.
     */
    private function logger(): LoggerInterface
    {
        return new class(function (string $line): void {
            $this->logLines[] = $line;
        }) extends AbstractLogger {
            public function __construct(private readonly \Closure $sink)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                ($this->sink)((string) $message);
            }
        };
    }
}
