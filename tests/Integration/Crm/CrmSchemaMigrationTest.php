<?php

declare(strict_types=1);

namespace App\Tests\Integration\Crm;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The CRM migration's constraints (#27), proven against the real schema with raw SQL, as
 * VenueSchemaMigrationTest does for the venue tables. Each test seeds an admin user, a venue and
 * one row in every CRM table inside a transaction that is rolled back afterwards.
 *
 * test_every_constraint_has_a_rejection_case() lists every chk_/uk_/fk_ name of the eight tables,
 * so a new constraint without a case here fails.
 */
final class CrmSchemaMigrationTest extends KernelTestCase
{
    private const TABLES = [
        'crm_companies', 'crm_contacts', 'crm_company_venues', 'crm_activity_types', 'crm_activities',
        'crm_activity_companies', 'crm_activity_contacts', 'crm_tasks',
    ];

    private const IDS = [
        '{admin}' => '019a0000-0000-7000-8000-00000000c001',
        '{venue}' => '019a0000-0000-7000-8000-00000000c101',
        '{venue2}' => '019a0000-0000-7000-8000-00000000c102',
        '{company}' => '019a0000-0000-7000-8000-00000000c201',
        '{company2}' => '019a0000-0000-7000-8000-00000000c202',
        '{contact}' => '019a0000-0000-7000-8000-00000000c301',
        '{activity}' => '019a0000-0000-7000-8000-00000000c401',
        '{task}' => '019a0000-0000-7000-8000-00000000c501',
        '{missing}' => '019a0000-0000-7000-8000-00000000c999',
    ];

    private const SEED = [
        "INSERT INTO admin_users (id, email, display_name, password_hash, role) VALUES ('{admin}', 'crm-schema@example.test', 'Schema', 'x', 'admin')",
        "INSERT INTO venues (id, name, tier, status, capacity_max) VALUES ('{venue}', 'Crm Testhaus', 'basic', 'published', 100), ('{venue2}', 'Crm Zweithaus', 'basic', 'published', 50)",
        "INSERT INTO crm_companies (id, name, origin, owner_id) VALUES ('{company}', 'Testhaus AG', 'venue', '{admin}'), ('{company2}', 'Zweite GmbH', 'manual', NULL)",
        "INSERT INTO crm_contacts (id, first_name, last_name, email, kind, company_id, owner_id) VALUES ('{contact}', 'Anna', 'Muster', 'anna@example.test', 'venue_contact', '{company}', '{admin}')",
        "INSERT INTO crm_company_venues (id, company_id, venue_id) VALUES (gen_random_uuid(), '{company}', '{venue}')",
        "INSERT INTO crm_activities (id, type_code, occurred_at, actor_id, body) VALUES ('{activity}', 'note', CURRENT_TIMESTAMP, '{admin}', 'Erstes Gespräch.')",
        "INSERT INTO crm_activity_companies (activity_id, company_id) VALUES ('{activity}', '{company}')",
        "INSERT INTO crm_activity_contacts (activity_id, contact_id) VALUES ('{activity}', '{contact}')",
        "INSERT INTO crm_tasks (id, title, due_on, assignee_id, created_by_id, company_id) VALUES ('{task}', 'Nachfassen', '2026-11-02', '{admin}', '{admin}', '{company}')",
    ];

    private Connection $connection;

    protected function setUp(): void
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->connection->beginTransaction();

        foreach (self::SEED as $sql) {
            $this->sql($sql);
        }
    }

    protected function tearDown(): void
    {
        $this->connection->rollBack();
        parent::tearDown();
    }

    /**
     * Statements that must fail on the named constraint; only the last statement of a case is
     * expected to fail.
     *
     * @return iterable<string, array{string, list<string>}>
     */
    public static function rejections(): iterable
    {
        // crm_companies
        yield 'blank company name' => ['chk_crm_companies_name', ["UPDATE crm_companies SET name = '  ' WHERE id = '{company}'"]];
        yield 'unknown stage' => ['chk_crm_companies_stage', ["UPDATE crm_companies SET stage = 'lead' WHERE id = '{company}'"]];
        yield 'unknown origin' => ['chk_crm_companies_origin', ["UPDATE crm_companies SET origin = 'import' WHERE id = '{company}'"]];
        yield 'unknown company owner' => ['fk_crm_companies_owner_id', ["UPDATE crm_companies SET owner_id = '{missing}' WHERE id = '{company}'"]];
        yield 'company owner is not deleted' => ['fk_crm_companies_owner_id', [
            'UPDATE crm_contacts SET owner_id = NULL',
            'DELETE FROM crm_tasks',
            'DELETE FROM crm_activities',
            "DELETE FROM admin_users WHERE id = '{admin}'",
        ]];

        // crm_contacts
        yield 'blank last name' => ['chk_crm_contacts_last_name', ["UPDATE crm_contacts SET last_name = '' WHERE id = '{contact}'"]];
        yield 'upper-case e-mail' => ['chk_crm_contacts_email_lower', ["UPDATE crm_contacts SET email = 'Anna@example.test' WHERE id = '{contact}'"]];
        yield 'e-mail taken' => ['uk_crm_contacts_email', ["INSERT INTO crm_contacts (id, last_name, email, kind) VALUES (gen_random_uuid(), 'Kopie', 'anna@example.test', 'other')"]];
        yield 'unknown kind' => ['chk_crm_contacts_kind', ["UPDATE crm_contacts SET kind = 'lead' WHERE id = '{contact}'"]];
        yield 'contact of unknown company' => ['fk_crm_contacts_company_id', ["UPDATE crm_contacts SET company_id = '{missing}' WHERE id = '{contact}'"]];
        yield 'unknown contact owner' => ['fk_crm_contacts_owner_id', ["UPDATE crm_contacts SET owner_id = '{missing}' WHERE id = '{contact}'"]];

        // crm_company_venues
        yield 'venue linked twice' => ['uk_crm_company_venues_venue_id', ["INSERT INTO crm_company_venues (id, company_id, venue_id) VALUES (gen_random_uuid(), '{company2}', '{venue}')"]];
        yield 'link to unknown company' => ['fk_crm_company_venues_company_id', ["INSERT INTO crm_company_venues (id, company_id, venue_id) VALUES (gen_random_uuid(), '{missing}', '{venue2}')"]];
        yield 'link to unknown venue' => ['fk_crm_company_venues_venue_id', ["INSERT INTO crm_company_venues (id, company_id, venue_id) VALUES (gen_random_uuid(), '{company2}', '{missing}')"]];

        // crm_activity_types
        yield 'type position taken' => ['uk_crm_activity_types_position', ["INSERT INTO crm_activity_types (code, position) SELECT 'extra', position FROM crm_activity_types WHERE code = 'note'"]];
        yield 'type code not snake case' => ['chk_crm_activity_types_code', ["INSERT INTO crm_activity_types (code, position) VALUES ('Extra-Type', 900)"]];
        yield 'type position zero' => ['chk_crm_activity_types_position', ["INSERT INTO crm_activity_types (code, position) VALUES ('extra', 0)"]];

        // crm_activities
        yield 'unknown activity type' => ['fk_crm_activities_type_code', ["UPDATE crm_activities SET type_code = 'fax' WHERE id = '{activity}'"]];
        yield 'type in use is not deleted' => ['fk_crm_activities_type_code', ["DELETE FROM crm_activity_types WHERE code = 'note'"]];
        yield 'unknown actor' => ['fk_crm_activities_actor_id', ["UPDATE crm_activities SET actor_id = '{missing}' WHERE id = '{activity}'"]];
        yield 'payload not an object' => ['chk_crm_activities_payload', ["UPDATE crm_activities SET payload = '[]' WHERE id = '{activity}'"]];
        yield 'entry without content' => ['chk_crm_activities_content', ["UPDATE crm_activities SET body = NULL WHERE id = '{activity}'"]];
        yield 'entry with blank content' => ['chk_crm_activities_content', ["UPDATE crm_activities SET body = '  ', title = '' WHERE id = '{activity}'"]];
        yield 'anonymised entry keeps a body' => ['chk_crm_activities_anonymised', ["UPDATE crm_activities SET anonymised_at = CURRENT_TIMESTAMP WHERE id = '{activity}'"]];
        yield 'anonymised entry keeps a payload' => ['chk_crm_activities_anonymised', ["UPDATE crm_activities SET body = NULL, payload = '{\"a\": 1}', anonymised_at = CURRENT_TIMESTAMP WHERE id = '{activity}'"]];

        // link tables
        yield 'company link to unknown activity' => ['fk_crm_activity_companies_activity_id', ["INSERT INTO crm_activity_companies (activity_id, company_id) VALUES ('{missing}', '{company2}')"]];
        yield 'activity link to unknown company' => ['fk_crm_activity_companies_company_id', ["INSERT INTO crm_activity_companies (activity_id, company_id) VALUES ('{activity}', '{missing}')"]];
        yield 'contact link to unknown activity' => ['fk_crm_activity_contacts_activity_id', ["INSERT INTO crm_activity_contacts (activity_id, contact_id) VALUES ('{missing}', '{contact}')"]];
        yield 'activity link to unknown contact' => ['fk_crm_activity_contacts_contact_id', ["INSERT INTO crm_activity_contacts (activity_id, contact_id) VALUES ('{activity}', '{missing}')"]];

        // crm_tasks
        yield 'blank task title' => ['chk_crm_tasks_title', ["UPDATE crm_tasks SET title = ' ' WHERE id = '{task}'"]];
        yield 'task on two objects' => ['chk_crm_tasks_one_object', ["UPDATE crm_tasks SET contact_id = '{contact}' WHERE id = '{task}'"]];
        yield 'unknown assignee' => ['fk_crm_tasks_assignee_id', ["UPDATE crm_tasks SET assignee_id = '{missing}' WHERE id = '{task}'"]];
        yield 'unknown task creator' => ['fk_crm_tasks_created_by_id', ["UPDATE crm_tasks SET created_by_id = '{missing}' WHERE id = '{task}'"]];
        yield 'task of unknown company' => ['fk_crm_tasks_company_id', ["UPDATE crm_tasks SET company_id = '{missing}' WHERE id = '{task}'"]];
        yield 'task of unknown contact' => ['fk_crm_tasks_contact_id', ["UPDATE crm_tasks SET company_id = NULL, contact_id = '{missing}' WHERE id = '{task}'"]];
    }

    /**
     * @param list<string> $statements
     */
    #[DataProvider('rejections')]
    public function test_constraint_rejects(string $constraint, array $statements): void
    {
        $last = array_pop($statements);
        self::assertNotNull($last);
        foreach ($statements as $sql) {
            $this->sql($sql);
        }

        try {
            $this->sql($last);
            self::fail(\sprintf('The statement should violate %s.', $constraint));
        } catch (DriverException $exception) {
            self::assertStringContainsString('"'.$constraint.'"', $exception->getMessage());
        }
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function acceptances(): iterable
    {
        yield 'contacts without e-mail' => [[
            "INSERT INTO crm_contacts (id, last_name, kind) VALUES (gen_random_uuid(), 'Eins', 'other')",
            "INSERT INTO crm_contacts (id, last_name, kind) VALUES (gen_random_uuid(), 'Zwei', 'couple')",
        ]];
        yield 'contact without company or first name' => [["UPDATE crm_contacts SET company_id = NULL, first_name = NULL WHERE id = '{contact}'"]];
        yield 'entry with a title only' => [["UPDATE crm_activities SET body = NULL, title = 'Anfrage' WHERE id = '{activity}'"]];
        yield 'entry without actor' => [["UPDATE crm_activities SET actor_id = NULL WHERE id = '{activity}'"]];
        yield 'anonymised entry' => [["UPDATE crm_activities SET title = NULL, body = NULL, payload = '{}', anonymised_at = CURRENT_TIMESTAMP WHERE id = '{activity}'"]];
        yield 'task without object or creator' => [["UPDATE crm_tasks SET company_id = NULL, created_by_id = NULL WHERE id = '{task}'"]];
        yield 'task on a contact' => [["UPDATE crm_tasks SET company_id = NULL, contact_id = '{contact}' WHERE id = '{task}'"]];
        yield 'every stage' => [["UPDATE crm_companies SET stage = 'customer' WHERE id = '{company}'", "UPDATE crm_companies SET stage = 'former_customer' WHERE id = '{company}'"]];
    }

    /**
     * @param list<string> $statements
     */
    #[DataProvider('acceptances')]
    public function test_constraints_accept(array $statements): void
    {
        foreach ($statements as $sql) {
            self::assertGreaterThan(0, $this->sql($sql), $sql);
        }
    }

    public function test_every_constraint_has_a_rejection_case(): void
    {
        $tables = "'".implode("', '", self::TABLES)."'";
        $names = $this->connection->fetchFirstColumn(
            "SELECT conname FROM pg_constraint WHERE conrelid::regclass::text IN ({$tables}) AND contype IN ('c', 'u', 'f')",
        );
        $primaryKeys = $this->connection->fetchFirstColumn(
            "SELECT conname FROM pg_constraint WHERE conrelid::regclass::text IN ({$tables}) AND contype = 'p'",
        );
        $tested = array_values(array_unique(array_map(static fn (array $case): string => $case[0], iterator_to_array(self::rejections()))));

        self::assertCount(8, $primaryKeys);
        foreach ($names as $name) {
            self::assertContains($name, $tested, $name.' has no rejection case');
        }
        foreach ($tested as $name) {
            self::assertContains($name, $names, $name.' is not a constraint of the schema');
        }
    }

    public function test_the_four_activity_types_are_seeded_in_design_order(): void
    {
        self::assertSame(
            ['note', 'email_logged', 'call', 'meeting'],
            $this->connection->fetchFirstColumn('SELECT code FROM crm_activity_types ORDER BY position'),
        );
    }

    public function test_deleting_a_venue_drops_its_link_and_keeps_the_company(): void
    {
        $this->sql("DELETE FROM venues WHERE id = '{venue}'");

        self::assertSame(0, $this->rows("SELECT count(*) FROM crm_company_venues WHERE venue_id = '{venue}'"));
        self::assertSame(1, $this->rows("SELECT count(*) FROM crm_companies WHERE id = '{company}'"));
    }

    public function test_deleting_a_company_keeps_its_contacts_and_drops_its_links_and_tasks(): void
    {
        $this->sql("DELETE FROM crm_companies WHERE id = '{company}'");

        self::assertSame(1, $this->rows("SELECT count(*) FROM crm_contacts WHERE id = '{contact}' AND company_id IS NULL"));
        self::assertSame(0, $this->rows("SELECT count(*) FROM crm_company_venues WHERE company_id = '{company}'"));
        self::assertSame(0, $this->rows("SELECT count(*) FROM crm_activity_companies WHERE company_id = '{company}'"));
        self::assertSame(0, $this->rows("SELECT count(*) FROM crm_tasks WHERE id = '{task}'"));
        self::assertSame(1, $this->rows("SELECT count(*) FROM crm_activities WHERE id = '{activity}'"));
    }

    public function test_deleting_a_contact_drops_its_links_and_tasks(): void
    {
        $this->sql("UPDATE crm_tasks SET company_id = NULL, contact_id = '{contact}' WHERE id = '{task}'");
        $this->sql("DELETE FROM crm_contacts WHERE id = '{contact}'");

        self::assertSame(0, $this->rows("SELECT count(*) FROM crm_activity_contacts WHERE contact_id = '{contact}'"));
        self::assertSame(0, $this->rows("SELECT count(*) FROM crm_tasks WHERE id = '{task}'"));
        self::assertSame(1, $this->rows("SELECT count(*) FROM crm_activity_companies WHERE activity_id = '{activity}'"));
    }

    private function sql(string $sql): int
    {
        return (int) $this->connection->executeStatement(strtr($sql, self::IDS));
    }

    private function rows(string $sql): int
    {
        return (int) $this->connection->fetchOne(strtr($sql, self::IDS));
    }
}
