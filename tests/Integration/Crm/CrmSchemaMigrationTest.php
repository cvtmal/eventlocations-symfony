<?php

declare(strict_types=1);

namespace App\Tests\Integration\Crm;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The CRM migrations' constraints (#27, #28, #29, #30), proven against the real schema with raw SQL, as
 * VenueSchemaMigrationTest does for the venue tables. Each test seeds an admin user, a venue and
 * one row in every CRM table inside a transaction that is rolled back afterwards.
 *
 * test_every_constraint_has_a_rejection_case() lists every chk_/uk_/fk_ name of the TABLES,
 * so a new constraint without a case here fails. A partial unique index is no pg_constraint row, so
 * test_a_partial_unique_index_rejects() holds those.
 */
final class CrmSchemaMigrationTest extends KernelTestCase
{
    private const TABLES = [
        'crm_companies', 'crm_contacts', 'crm_activity_types', 'crm_activities',
        'crm_activity_companies', 'crm_tasks', 'crm_deal_stages', 'crm_deals', 'crm_activity_deals',
        'crm_inquiry_links', 'crm_email_templates', 'suppressions',
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
        '{deal}' => '019a0000-0000-7000-8000-00000000c601',
        '{deal2}' => '019a0000-0000-7000-8000-00000000c602',
        '{inquiry}' => '019a0000-0000-7000-8000-00000000c701',
        '{inquiry2}' => '019a0000-0000-7000-8000-00000000c702',
        '{missing}' => '019a0000-0000-7000-8000-00000000c999',
    ];

    private const SEED = [
        "INSERT INTO admin_users (id, email, display_name, password_hash, role) VALUES ('{admin}', 'crm-schema@example.test', 'Schema', 'x', 'admin')",
        "INSERT INTO venues (id, name, status, capacity_max) VALUES ('{venue}', 'Crm Testhaus', 'published', 100), ('{venue2}', 'Crm Zweithaus', 'published', 50)",
        "INSERT INTO crm_companies (id, name, origin, owner_id, venue_id) VALUES ('{company}', 'Testhaus AG', 'venue', '{admin}', '{venue}'), ('{company2}', 'Zweite GmbH', 'manual', NULL, NULL)",
        "INSERT INTO crm_contacts (id, first_name, last_name, email, company_id) VALUES ('{contact}', 'Anna', 'Muster', 'anna@example.test', '{company}')",
        "INSERT INTO crm_activities (id, type_code, occurred_at, actor_id, body) VALUES ('{activity}', 'note', CURRENT_TIMESTAMP, '{admin}', 'Erstes Gespräch.')",
        "INSERT INTO crm_activity_companies (activity_id, company_id) VALUES ('{activity}', '{company}')",
        "INSERT INTO crm_tasks (id, title, due_on, assignee_id, created_by_id, company_id) VALUES ('{task}', 'Nachfassen', '2026-11-02', '{admin}', '{admin}', '{company}')",
        "INSERT INTO crm_deals (id, name, company_id, venue_id, stage_code, owner_id) VALUES ('{deal}', 'Premium 2027', '{company}', '{venue}', 'new', '{admin}')",
        "INSERT INTO crm_deals (id, name, company_id, stage_code) VALUES ('{deal2}', 'Basic 2027', '{company2}', 'offer')",
        "INSERT INTO crm_activity_deals (activity_id, deal_id) VALUES ('{activity}', '{deal}')",
        "INSERT INTO inquiries (id, venue_id, venue_number, venue_name, venue_slug, recipient_email, locale, occasion, guest_count, message, first_name, last_name, email, fingerprint) SELECT id, '{venue}'::uuid, 1, 'Crm Testhaus', 'crm-testhaus', 'haus@example.test', 'de', 'wedding', 80, 'Grüezi', 'Anna', 'Muster', 'anna@example.test', repeat('a', 64) FROM (VALUES ('{inquiry}'::uuid), ('{inquiry2}'::uuid)) AS seed (id)",
        "INSERT INTO crm_inquiry_links (inquiry_id, activity_id) VALUES ('{inquiry}', '{activity}')",
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
            'UPDATE crm_deals SET owner_id = NULL',
            'DELETE FROM crm_tasks',
            'DELETE FROM crm_activities',
            "DELETE FROM admin_users WHERE id = '{admin}'",
        ]];

        // G11: the outreach fields of crm_companies
        yield 'campaign code with an address' => ['chk_crm_companies_campaign_code', ["UPDATE crm_companies SET campaign_code = 'mia@example.ch' WHERE id = '{company}'"]];
        yield 'unknown cohort' => ['chk_crm_companies_cohort', ["UPDATE crm_companies SET cohort = 'E' WHERE id = '{company}'"]];
        yield 'unknown contact channel' => ['chk_crm_companies_contact_channel', ["UPDATE crm_companies SET contact_channel = 'fax' WHERE id = '{company}'"]];
        yield 'unknown contact basis' => ['chk_crm_companies_contact_basis', ["UPDATE crm_companies SET contact_basis = 'bought_list' WHERE id = '{company}'"]];

        // suppressions (G11)
        yield 'suppression of an unknown Location' => ['fk_suppressions_company_id', ["INSERT INTO suppressions (id, company_id, reason) VALUES (gen_random_uuid(), '{missing}', 'opt_out')"]];
        yield 'a suppressed Location is not deleted' => ['fk_suppressions_company_id', [
            "INSERT INTO suppressions (id, company_id, reason) VALUES (gen_random_uuid(), '{company2}', 'objection')",
            'DELETE FROM crm_deals',
            "DELETE FROM crm_companies WHERE id = '{company2}'",
        ]];
        yield 'a Location suppressed twice' => ['uk_suppressions_company_id', [
            "INSERT INTO suppressions (id, company_id, reason) VALUES (gen_random_uuid(), '{company}', 'objection')",
            "INSERT INTO suppressions (id, company_id, reason) VALUES (gen_random_uuid(), '{company}', 'opt_out')",
        ]];
        yield 'an address suppressed twice' => ['uk_suppressions_email', [
            "INSERT INTO suppressions (id, email, reason) VALUES (gen_random_uuid(), 'anna@example.test', 'objection')",
            "INSERT INTO suppressions (id, email, reason) VALUES (gen_random_uuid(), 'anna@example.test', 'opt_out')",
        ]];
        yield 'unknown suppression reason' => ['chk_suppressions_reason', ["INSERT INTO suppressions (id, email, reason) VALUES (gen_random_uuid(), 'anna@example.test', 'bounce')"]];
        yield 'suppression of nothing' => ['chk_suppressions_target', ["INSERT INTO suppressions (id, reason) VALUES (gen_random_uuid(), 'opt_out')"]];
        yield 'suppression of a Location and an address' => ['chk_suppressions_target', ["INSERT INTO suppressions (id, company_id, email, reason) VALUES (gen_random_uuid(), '{company}', 'anna@example.test', 'opt_out')"]];
        yield 'suppressed address in capitals' => ['chk_suppressions_email', ["INSERT INTO suppressions (id, email, reason) VALUES (gen_random_uuid(), 'Anna@example.test', 'opt_out')"]];
        yield 'suppressed address without an at sign' => ['chk_suppressions_email', ["INSERT INTO suppressions (id, email, reason) VALUES (gen_random_uuid(), 'anna', 'opt_out')"]];

        // crm_contacts
        yield 'blank last name' => ['chk_crm_contacts_last_name', ["UPDATE crm_contacts SET last_name = '' WHERE id = '{contact}'"]];
        yield 'upper-case e-mail' => ['chk_crm_contacts_email_lower', ["UPDATE crm_contacts SET email = 'Anna@example.test' WHERE id = '{contact}'"]];
        yield 'e-mail taken on the same Location' => ['uk_crm_contacts_company_email', ["INSERT INTO crm_contacts (id, last_name, email, company_id) VALUES (gen_random_uuid(), 'Kopie', 'anna@example.test', '{company}')"]];
        yield 'contact of unknown company' => ['fk_crm_contacts_company_id', ["UPDATE crm_contacts SET company_id = '{missing}' WHERE id = '{contact}'"]];

        // crm_companies
        yield 'venue on two Locations' => ['uk_crm_companies_venue_id', ["UPDATE crm_companies SET venue_id = '{venue}' WHERE id = '{company2}'"]];
        yield 'Eintrag of unknown venue' => ['fk_crm_companies_venue_id', ["UPDATE crm_companies SET venue_id = '{missing}' WHERE id = '{company2}'"]];

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

        // crm_tasks
        yield 'blank task title' => ['chk_crm_tasks_title', ["UPDATE crm_tasks SET title = ' ' WHERE id = '{task}'"]];
        yield 'unknown assignee' => ['fk_crm_tasks_assignee_id', ["UPDATE crm_tasks SET assignee_id = '{missing}' WHERE id = '{task}'"]];
        yield 'unknown task creator' => ['fk_crm_tasks_created_by_id', ["UPDATE crm_tasks SET created_by_id = '{missing}' WHERE id = '{task}'"]];
        yield 'task of unknown company' => ['fk_crm_tasks_company_id', ["UPDATE crm_tasks SET company_id = '{missing}' WHERE id = '{task}'"]];
        yield 'task of unknown deal' => ['fk_crm_tasks_deal_id', ["UPDATE crm_tasks SET company_id = NULL, deal_id = '{missing}' WHERE id = '{task}'"]];
        yield 'task on a company and a deal' => ['chk_crm_tasks_one_object', ["UPDATE crm_tasks SET deal_id = '{deal}' WHERE id = '{task}'"]];
        yield 'renewal task without a deal' => ['chk_crm_tasks_renewal', ["UPDATE crm_tasks SET is_renewal = TRUE WHERE id = '{task}'"]];

        // crm_deal_stages
        yield 'stage position taken' => ['uk_crm_deal_stages_position', ["INSERT INTO crm_deal_stages (code, position, probability) VALUES ('extra', 1, 10)"]];
        yield 'stage code not snake case' => ['chk_crm_deal_stages_code', ["INSERT INTO crm_deal_stages (code, position, probability) VALUES ('Extra-Stage', 900, 10)"]];
        yield 'stage position zero' => ['chk_crm_deal_stages_position', ["INSERT INTO crm_deal_stages (code, position, probability) VALUES ('extra', 0, 10)"]];
        yield 'probability above 100' => ['chk_crm_deal_stages_probability', ["INSERT INTO crm_deal_stages (code, position, probability) VALUES ('extra', 900, 101)"]];
        yield 'stage both won and lost' => ['chk_crm_deal_stages_outcome', ["UPDATE crm_deal_stages SET is_lost = TRUE WHERE code = 'won'"]];

        // crm_deals
        yield 'blank deal name' => ['chk_crm_deals_name', ["UPDATE crm_deals SET name = ' ' WHERE id = '{deal}'"]];
        yield 'deal of unknown tier' => ['fk_crm_deals_tier_id', ["UPDATE crm_deals SET tier_id = '{missing}' WHERE id = '{deal}'"]];
        yield 'tier in use is not deleted' => ['fk_crm_deals_tier_id', [
            "UPDATE crm_deals SET tier_id = (SELECT id FROM tiers WHERE code = 'pro') WHERE id = '{deal}'",
            "DELETE FROM tiers WHERE code = 'pro'",
        ]];
        yield 'negative amount' => ['chk_crm_deals_amount', ["UPDATE crm_deals SET amount_rappen = -1 WHERE id = '{deal}'"]];
        yield 'contract ends before it starts' => ['chk_crm_deals_contract', ["UPDATE crm_deals SET contract_start_on = '2027-01-02', contract_end_on = '2027-01-01' WHERE id = '{deal}'"]];
        yield 'unknown lost reason' => ['chk_crm_deals_lost_reason', ["UPDATE crm_deals SET lost_reason = 'weather' WHERE id = '{deal}'"]];
        yield 'deal of unknown company' => ['fk_crm_deals_company_id', ["UPDATE crm_deals SET company_id = '{missing}' WHERE id = '{deal}'"]];
        yield 'company with a deal is not deleted' => ['fk_crm_deals_company_id', ["DELETE FROM crm_companies WHERE id = '{company}'"]];
        yield 'deal of unknown venue' => ['fk_crm_deals_venue_id', ["UPDATE crm_deals SET venue_id = '{missing}' WHERE id = '{deal}'"]];
        yield 'unknown deal stage' => ['fk_crm_deals_stage_code', ["UPDATE crm_deals SET stage_code = 'paused' WHERE id = '{deal}'"]];
        yield 'stage in use is not deleted' => ['fk_crm_deals_stage_code', ["DELETE FROM crm_deal_stages WHERE code = 'new'"]];
        yield 'unknown deal owner' => ['fk_crm_deals_owner_id', ["UPDATE crm_deals SET owner_id = '{missing}' WHERE id = '{deal}'"]];
        yield 'renewal of unknown deal' => ['fk_crm_deals_renewed_from_id', ["UPDATE crm_deals SET renewed_from_id = '{missing}' WHERE id = '{deal}'"]];
        yield 'deal renewed twice' => ['uk_crm_deals_renewed_from_id', [
            "UPDATE crm_deals SET renewed_from_id = '{deal}' WHERE id = '{deal2}'",
            "INSERT INTO crm_deals (id, name, company_id, stage_code, renewed_from_id) VALUES (gen_random_uuid(), 'Kopie', '{company}', 'new', '{deal}')",
        ]];

        // link tables of a deal
        yield 'deal link to unknown activity' => ['fk_crm_activity_deals_activity_id', ["INSERT INTO crm_activity_deals (activity_id, deal_id) VALUES ('{missing}', '{deal2}')"]];
        yield 'activity link to unknown deal' => ['fk_crm_activity_deals_deal_id', ["INSERT INTO crm_activity_deals (activity_id, deal_id) VALUES ('{activity}', '{missing}')"]];

        // crm_inquiry_links
        yield 'unknown inquiry status' => ['chk_crm_inquiry_links_status', ["UPDATE crm_inquiry_links SET status = 'open' WHERE inquiry_id = '{inquiry}'"]];
        yield 'link to unknown inquiry' => ['fk_crm_inquiry_links_inquiry_id', ["INSERT INTO crm_inquiry_links (inquiry_id) VALUES ('{missing}')"]];
        yield 'inquiry link to unknown activity' => ['fk_crm_inquiry_links_activity_id', ["INSERT INTO crm_inquiry_links (inquiry_id, activity_id) VALUES ('{inquiry2}', '{missing}')"]];

        // crm_email_templates
        $template = "INSERT INTO crm_email_templates (id, name, subject, body_markdown) VALUES (gen_random_uuid(), '%s', '%s', '%s')";
        yield 'duplicate template name' => ['uk_crm_email_templates_name', [\sprintf($template, 'Vorlage', 'Betreff', 'Text'), \sprintf($template, 'Vorlage', 'Anders', 'Anders')]];
        yield 'blank template name' => ['chk_crm_email_templates_content', [\sprintf($template, '  ', 'Betreff', 'Text')]];
        yield 'blank template subject' => ['chk_crm_email_templates_content', [\sprintf($template, 'Vorlage', '', 'Text')]];
        yield 'blank template body' => ['chk_crm_email_templates_content', [\sprintf($template, 'Vorlage', 'Betreff', ' ')]];
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function partialIndexRejections(): iterable
    {
        yield 'a second won stage' => ['uk_crm_deal_stages_won', ["INSERT INTO crm_deal_stages (code, position, probability, is_won) VALUES ('extra', 900, 100, TRUE)"]];
        yield 'a second lost stage' => ['uk_crm_deal_stages_lost', ["INSERT INTO crm_deal_stages (code, position, probability, is_lost) VALUES ('extra', 900, 0, TRUE)"]];
        yield 'a second renewal task on a deal' => ['uk_crm_tasks_renewal', [
            "INSERT INTO crm_tasks (id, title, due_on, assignee_id, deal_id, is_renewal) VALUES (gen_random_uuid(), 'Verlängern', '2026-11-02', '{admin}', '{deal}', TRUE)",
            "INSERT INTO crm_tasks (id, title, due_on, assignee_id, deal_id, is_renewal) VALUES (gen_random_uuid(), 'Nochmals', '2026-11-02', '{admin}', '{deal}', TRUE)",
        ]];
    }

    /**
     * @param list<string> $statements
     */
    #[DataProvider('partialIndexRejections')]
    public function test_a_partial_unique_index_rejects(string $index, array $statements): void
    {
        $this->test_constraint_rejects($index, $statements);
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
            "INSERT INTO crm_contacts (id, last_name, company_id) VALUES (gen_random_uuid(), 'Eins', '{company}')",
            "INSERT INTO crm_contacts (id, last_name, company_id) VALUES (gen_random_uuid(), 'Zwei', '{company}')",
        ]];
        yield 'contact without first name' => [["UPDATE crm_contacts SET first_name = NULL WHERE id = '{contact}'"]];
        yield 'same e-mail on two Locations' => [["INSERT INTO crm_contacts (id, last_name, email, company_id) VALUES (gen_random_uuid(), 'Kopie', 'anna@example.test', '{company2}')"]];
        yield 'entry with a title only' => [["UPDATE crm_activities SET body = NULL, title = 'Anfrage' WHERE id = '{activity}'"]];
        yield 'entry without actor' => [["UPDATE crm_activities SET actor_id = NULL WHERE id = '{activity}'"]];
        yield 'anonymised entry' => [["UPDATE crm_activities SET title = NULL, body = NULL, payload = '{}', anonymised_at = CURRENT_TIMESTAMP WHERE id = '{activity}'"]];
        yield 'task without object or creator' => [["UPDATE crm_tasks SET company_id = NULL, created_by_id = NULL WHERE id = '{task}'"]];
        yield 'every stage' => [["UPDATE crm_companies SET stage = 'customer' WHERE id = '{company}'", "UPDATE crm_companies SET stage = 'former_customer' WHERE id = '{company}'"]];
        yield 'an Eintrag on a second Location' => [["UPDATE crm_companies SET venue_id = '{venue2}' WHERE id = '{company2}'"]];
        yield 'task on a deal' => [["UPDATE crm_tasks SET company_id = NULL, deal_id = '{deal}' WHERE id = '{task}'"]];
        yield 'a done renewal task beside an ordinary one on the deal' => [[
            "INSERT INTO crm_tasks (id, title, due_on, assignee_id, deal_id) VALUES (gen_random_uuid(), 'Ordentlich', '2026-11-02', '{admin}', '{deal}')",
            "INSERT INTO crm_tasks (id, title, due_on, assignee_id, deal_id, is_renewal, done_at) VALUES (gen_random_uuid(), 'Verlängern', '2026-11-02', '{admin}', '{deal}', TRUE, CURRENT_TIMESTAMP)",
        ]];
        yield 'open-ended contract' => [["UPDATE crm_deals SET contract_start_on = '2027-01-01', contract_end_on = NULL WHERE id = '{deal}'"]];
        yield 'one-day contract' => [["UPDATE crm_deals SET contract_start_on = '2027-01-01', contract_end_on = '2027-01-01' WHERE id = '{deal}'"]];
        yield 'free deal' => [["UPDATE crm_deals SET amount_rappen = 0 WHERE id = '{deal}'"]];
        yield 'deal without a tier, and with one' => [["UPDATE crm_deals SET tier_id = (SELECT id FROM tiers WHERE code = 'free') WHERE id = '{deal}'", "UPDATE crm_deals SET tier_id = NULL WHERE id = '{deal}'"]];
        yield 'outreach fields' => [["UPDATE crm_companies SET campaign_code = 'brief-2026-11', cohort = 'A', contact_channel = 'postal', contact_basis = 'none', outreach_sent_on = '2026-11-10', mail_returned_on = '2026-11-14' WHERE id = '{company}'"]];
        yield 'template names differ by case' => [[
            "INSERT INTO crm_email_templates (id, name, subject, body_markdown) VALUES (gen_random_uuid(), 'Vorlage', 'Betreff', 'Text')",
            "INSERT INTO crm_email_templates (id, name, subject, body_markdown) VALUES (gen_random_uuid(), 'vorlage', 'Betreff', 'Text')",
        ]];
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

        self::assertCount(12, $primaryKeys);
        foreach ($names as $name) {
            self::assertContains($name, $tested, $name.' has no rejection case');
        }
        foreach ($tested as $name) {
            self::assertContains($name, $names, $name.' is not a constraint of the schema');
        }
    }

    public function test_the_activity_types_are_seeded_in_design_order(): void
    {
        self::assertSame(
            ['note', 'email_logged', 'call', 'meeting', 'deal_stage_changed', 'inquiry', 'email_sent'],
            $this->connection->fetchFirstColumn('SELECT code FROM crm_activity_types ORDER BY position'),
        );
    }

    public function test_the_deal_stages_are_seeded_in_pipeline_order(): void
    {
        self::assertSame(
            [
                ['code' => 'new', 'probability' => 10, 'is_won' => false, 'is_lost' => false],
                ['code' => 'contacted', 'probability' => 25, 'is_won' => false, 'is_lost' => false],
                ['code' => 'offer', 'probability' => 50, 'is_won' => false, 'is_lost' => false],
                ['code' => 'negotiation', 'probability' => 75, 'is_won' => false, 'is_lost' => false],
                ['code' => 'won', 'probability' => 100, 'is_won' => true, 'is_lost' => false],
                ['code' => 'lost', 'probability' => 0, 'is_won' => false, 'is_lost' => true],
            ],
            $this->connection->fetchAllAssociative('SELECT code, probability, is_won, is_lost FROM crm_deal_stages ORDER BY position'),
        );
    }

    public function test_deleting_a_venue_frees_its_location(): void
    {
        $this->sql("DELETE FROM venues WHERE id = '{venue}'");

        self::assertSame(1, $this->rows("SELECT count(*) FROM crm_companies WHERE id = '{company}' AND venue_id IS NULL"));
    }

    public function test_deleting_a_company_deletes_its_contacts_and_drops_its_links_and_tasks(): void
    {
        $this->sql("DELETE FROM crm_deals WHERE company_id = '{company}'");
        $this->sql("DELETE FROM crm_companies WHERE id = '{company}'");

        self::assertSame(0, $this->rows("SELECT count(*) FROM crm_contacts WHERE id = '{contact}'"));
        self::assertSame(1, $this->rows("SELECT count(*) FROM venues WHERE id = '{venue}'"));
        self::assertSame(0, $this->rows("SELECT count(*) FROM crm_activity_companies WHERE company_id = '{company}'"));
        self::assertSame(0, $this->rows("SELECT count(*) FROM crm_tasks WHERE id = '{task}'"));
        self::assertSame(1, $this->rows("SELECT count(*) FROM crm_activities WHERE id = '{activity}'"));
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
