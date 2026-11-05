<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * #55: CRM contacts become the Location's (`crm_companies`) contact card, couples leave the CRM and live on as
 * Anfragen only. One transaction that guards itself; any RAISE rolls the whole migration back and the deploy stops
 * with the app unchanged (runbook: docs/operations.md "CRM contacts switch (#55)").
 *
 * Rules, in order:
 * 1. Preconditions abort (one message listing every failing metric): A6 company with 2+ venues, V2 venue on 2+
 *    companies, C4 non-couple contact without a company, C5 couple with a company, D4 staff entry on a couple,
 *    D6 inquiry entry with no company link, E7 deal link to a couple, T-couple task on a couple. The couple count C3
 *    never aborts: it grows with every inquiry.
 * 2. Couple contact rows are deleted. `inquiries`, `crm_inquiry_links` rows and `inquiry` entries stay.
 * 3. The unique e-mail becomes per Location: `uk_crm_contacts_company_email (company_id, email)`.
 * 4. Copies onto a Location, once, only what is missing there (same e-mail, or same names without one): a deal
 *    contact (E6) onto its deal's Location, a contact with entries on another Location (C6) onto that Location, an
 *    active manager (portal) onto their venue's Location with position MANAGER_POSITION.
 * 5. Tasks on a contact move to its Location; the tasks on nothing (F5) stay untouched.
 * 6. Entries linked only to a contact get its Location; `crm_activity_contacts` and `crm_deal_contacts` are dropped;
 *    entries left with no Location and no deal are deleted (the `ActivityRepository::deleteUnlinked()` rule).
 * 7. `crm_tasks.contact_id`, `crm_inquiry_links.contact_id`, `crm_contacts.kind` and `crm_contacts.owner_id` go;
 *    `crm_contacts.company_id` becomes NOT NULL with ON DELETE CASCADE; a task sits on a company or a deal or nothing.
 * 8. Postconditions with exact pre/post arithmetic (counts read by fetchOne before the steps and printed with
 *    write()) abort the same way.
 *
 * Production counts of 2026-11-05: C3 0, C4 0, C5 0, C6 1, D4 0, D6 0, E6 1, F5 18, I1 3, I2 1, A6 0.
 * - The one `crm_deal_contacts` row is copied onto its deal's Location if its person is not already there, then the
 *   table is dropped (research D2).
 * - The one C6 person is copied onto the other Location its entries name (research D3).
 * - The 18 tasks on nothing stay untouched (D1); a postcondition compares their fingerprint.
 * - I1 3 active managers get a row on their venue's Location unless their e-mail is already there.
 *
 * Not additive: the old code reads `kind` and `crm_activity_contacts` in the seconds between this migration and the
 * rsync (deploy-window exception, see the runbook). down() restores the schema only: erased couples, the entry and
 * deal links, the moved tasks' contact, `kind`/`owner_id` values and `crm_inquiry_links.contact_id` come back only
 * from the Neon restore.
 */
final class Version20261105193544 extends AbstractMigration
{
    /** Same value as `App\Crm\CommandHandler\LinkLocationManagerHandler::MANAGER_POSITION`; a test pins it. */
    public const string MANAGER_POSITION = 'Location-Manager';

    private const RUNBOOK = 'See docs/operations.md "CRM contacts switch (#55)".';

    /** Code => [meaning, count query]; each aborts the migration when its count is above 0. */
    private const GUARDS = [
        'A6' => ['companies with 2+ venues', 'WITH cv AS (SELECT company_id, count(*) AS venues FROM crm_company_venues GROUP BY company_id) SELECT count(*) FROM cv WHERE venues > 1'],
        'V2' => ['venues on 2+ companies', 'SELECT count(*) FROM (SELECT venue_id FROM crm_company_venues GROUP BY venue_id HAVING count(*) > 1) x'],
        'C4' => ['non-couple contacts without a company', "SELECT count(*) FROM crm_contacts WHERE kind <> 'couple' AND company_id IS NULL"],
        'C5' => ['couples with a company', "SELECT count(*) FROM crm_contacts WHERE kind = 'couple' AND company_id IS NOT NULL"],
        'D4' => ['staff entries on a couple', "SELECT count(*) FROM crm_activities a WHERE a.type_code <> 'inquiry' AND EXISTS (SELECT 1 FROM crm_activity_contacts l JOIN crm_contacts p ON p.id = l.contact_id WHERE l.activity_id = a.id AND p.kind = 'couple')"],
        'D6' => ['inquiry entries with no company link', "SELECT count(*) FROM crm_activities a WHERE a.type_code = 'inquiry' AND NOT EXISTS (SELECT 1 FROM crm_activity_companies l WHERE l.activity_id = a.id)"],
        'E7' => ['deal links to a couple', "SELECT count(*) FROM crm_deal_contacts dc JOIN crm_contacts p ON p.id = dc.contact_id WHERE p.kind = 'couple'"],
        'T-couple' => ['tasks on a couple', "SELECT count(*) FROM crm_tasks t JOIN crm_contacts p ON p.id = t.contact_id WHERE p.kind = 'couple'"],
    ];

    /** Code => [meaning, count query]; reported in the deploy log, never guarded. The totals feed the postconditions. */
    private const REPORTED = [
        'C3' => ['couples', "SELECT count(*) FROM crm_contacts WHERE kind = 'couple'"],
        'C6' => ['non-couple contacts with entries on another company', "SELECT count(*) FROM crm_contacts p WHERE p.kind <> 'couple' AND EXISTS (SELECT 1 FROM crm_activity_contacts l JOIN crm_activity_companies lc ON lc.activity_id = l.activity_id WHERE l.contact_id = p.id AND lc.company_id IS DISTINCT FROM p.company_id)"],
        'E6' => ['deal contact links', 'SELECT count(*) FROM crm_deal_contacts'],
        'F1' => ['tasks on a company', 'SELECT count(*) FROM crm_tasks WHERE company_id IS NOT NULL'],
        'F2' => ['tasks on a contact', 'SELECT count(*) FROM crm_tasks WHERE contact_id IS NOT NULL'],
        'F4' => ['tasks on a deal', 'SELECT count(*) FROM crm_tasks WHERE deal_id IS NOT NULL'],
        'F5' => ['tasks on nothing', 'SELECT count(*) FROM crm_tasks WHERE num_nonnulls(company_id, contact_id, deal_id) = 0'],
        'I1' => ['active manager access rows', "SELECT count(*) FROM portal_venue_access WHERE status = 'active'"],
        'I2' => ['managers with a CRM contact of the same e-mail', 'SELECT count(*) FROM portal_users u WHERE EXISTS (SELECT 1 FROM crm_contacts p WHERE p.email = u.email)'],
        'B2' => ['venues without a company', 'SELECT count(*) FROM venues v WHERE NOT EXISTS (SELECT 1 FROM crm_company_venues x WHERE x.venue_id = v.id)'],
        'inquiries' => ['', 'SELECT count(*) FROM inquiries'],
        'inquiry links' => ['', 'SELECT count(*) FROM crm_inquiry_links'],
        'inquiry entries' => ['', "SELECT count(*) FROM crm_activities WHERE type_code = 'inquiry'"],
        'entries' => ['', 'SELECT count(*) FROM crm_activities'],
        'X0' => ['entries with no link', 'SELECT count(*) FROM crm_activities a WHERE NOT EXISTS (SELECT 1 FROM crm_activity_companies l WHERE l.activity_id = a.id) AND NOT EXISTS (SELECT 1 FROM crm_activity_deals l WHERE l.activity_id = a.id) AND NOT EXISTS (SELECT 1 FROM crm_activity_contacts l WHERE l.activity_id = a.id)'],
        'tasks' => ['', 'SELECT count(*) FROM crm_tasks'],
        'non-couple contacts' => ['', "SELECT count(*) FROM crm_contacts WHERE kind <> 'couple'"],
        'audit rows' => ['', 'SELECT count(*) FROM admin_audit_log'],
    ];

    /** Every table the pre-counts read and the app writes; locked against writers for the migration's transaction. */
    public const array LOCKED = [
        'inquiries', 'crm_inquiry_links', 'admin_audit_log', 'crm_companies', 'crm_company_venues', 'crm_contacts',
        'crm_activities', 'crm_activity_companies', 'crm_activity_contacts', 'crm_activity_deals', 'crm_deals',
        'crm_deal_contacts', 'crm_tasks', 'portal_users', 'portal_venue_access', 'venues',
    ];

    /** The tasks on nothing, as one hash; %s is the "on nothing" condition of the schema it runs on. */
    private const F5_FINGERPRINT = "SELECT coalesce(md5(string_agg(id::text || '|' || title || '|' || due_on || '|' || coalesce(done_at::text, '') || '|' || assignee_id || '|' || updated_at::text, ',' ORDER BY id)), '') FROM crm_tasks WHERE %s";

    /** One person per Location: the e-mail, or the names when there is none. */
    private const PERSON_KEY = "coalesce(email, lower(coalesce(first_name, '')) || '|' || lower(last_name))";

    /** A contact `x` on the expected row `e`'s Location that is the same person. */
    private const SAME_PERSON = "x.company_id = e.company_id AND (x.email = e.email OR (e.email IS NULL AND x.email IS NULL AND lower(coalesce(x.first_name, '')) = lower(coalesce(e.first_name, '')) AND lower(x.last_name) = lower(e.last_name)))";

    public function getDescription(): string
    {
        return 'CRM contacts become the Location\'s contact card; couples leave the CRM (#55)';
    }

    public function up(Schema $schema): void
    {
        // Doctrine opened the transaction before up(). A guest inquiry or an audited staff or /konto action committing
        // between these reads and the postconditions would move the exact counts and roll an unattended deploy back
        // for nothing: SHARE mode lets reads through and makes writers wait the second this migration takes, and the
        // lock_timeout turns a long wait into a rollback instead of a hung deploy.
        $this->connection->executeStatement("SET LOCAL lock_timeout = '15s'");
        $this->connection->executeStatement('LOCK TABLE '.implode(', ', self::LOCKED).' IN SHARE MODE');

        $pre = [];
        foreach (self::GUARDS + self::REPORTED as $code => [$meaning, $sql]) {
            $pre[$code] = (int) $this->connection->fetchOne($sql);
            $this->write(\sprintf('#55 pre: %s = %d', trim($code.' '.$meaning), $pre[$code]));
        }
        $fingerprint = (string) $this->connection->fetchOne(\sprintf(self::F5_FINGERPRINT, 'num_nonnulls(company_id, contact_id, deal_id) = 0'));

        $this->addSql($this->preconditions());

        $this->addSql('CREATE TEMP TABLE crm55_expect (company_id UUID NOT NULL, first_name TEXT, last_name TEXT NOT NULL, email TEXT, phone TEXT, position TEXT, reason TEXT NOT NULL) ON COMMIT DROP');
        $this->addSql('CREATE TEMP TABLE crm55_copied (id UUID NOT NULL, reason TEXT NOT NULL) ON COMMIT DROP');
        $this->addSql(<<<'SQL'
            INSERT INTO crm55_expect
            SELECT d.company_id, p.first_name, p.last_name, p.email, p.phone, p.position, 'e6'
            FROM crm_deal_contacts dc
            JOIN crm_deals d ON d.id = dc.deal_id
            JOIN crm_contacts p ON p.id = dc.contact_id
            SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO crm55_expect
            SELECT DISTINCT lc.company_id, p.first_name, p.last_name, p.email, p.phone, p.position, 'c6'
            FROM crm_contacts p
            JOIN crm_activity_contacts l ON l.contact_id = p.id
            JOIN crm_activity_companies lc ON lc.activity_id = l.activity_id
            WHERE p.kind <> 'couple' AND lc.company_id IS DISTINCT FROM p.company_id
            SQL);
        $this->addSql(\sprintf(<<<'SQL'
            INSERT INTO crm55_expect
            SELECT cv.company_id, u.first_name, u.last_name, lower(u.email), u.phone, %s, 'manager'
            FROM portal_venue_access a
            JOIN crm_company_venues cv ON cv.venue_id = a.venue_id
            JOIN portal_users u ON u.id = a.portal_user_id
            WHERE a.status = 'active'
            SQL, $this->connection->quote(self::MANAGER_POSITION)));

        // Couples (Q6): their entry links cascade, crm_inquiry_links.contact_id goes NULL; the guards leave nothing else.
        $this->addSql("DELETE FROM crm_contacts WHERE kind = 'couple'");

        // Before the copies, which put one e-mail on a second Location.
        $this->addSql('ALTER TABLE crm_contacts DROP CONSTRAINT uk_crm_contacts_email');
        $this->addSql('ALTER TABLE crm_contacts ADD CONSTRAINT uk_crm_contacts_company_email UNIQUE (company_id, email)');

        // One row per Location and person; the CRM's values win over the portal account's (E6, then C6, then manager).
        $this->addSql(\sprintf(<<<'SQL'
            WITH e AS MATERIALIZED (
                SELECT uuidv7() AS id, t.*
                FROM (
                    SELECT DISTINCT ON (company_id, %1$s) *
                    FROM crm55_expect
                    ORDER BY company_id, %1$s, CASE reason WHEN 'e6' THEN 1 WHEN 'c6' THEN 2 ELSE 3 END
                ) t
            ), ins AS (
                INSERT INTO crm_contacts (id, first_name, last_name, email, phone, position, kind, company_id, created_at, updated_at)
                SELECT e.id, e.first_name, e.last_name, e.email, e.phone, e.position, 'venue_contact', e.company_id, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                FROM e
                WHERE NOT EXISTS (SELECT 1 FROM crm_contacts x WHERE %2$s)
                ON CONFLICT (company_id, email) DO NOTHING
                RETURNING id
            )
            INSERT INTO crm55_copied (id, reason) SELECT ins.id, e.reason FROM ins JOIN e ON e.id = ins.id
            SQL, self::PERSON_KEY, self::SAME_PERSON));

        // A contact's task moves to its Location; a task on nothing stays as it is (D1).
        $this->addSql('UPDATE crm_tasks t SET company_id = p.company_id, contact_id = NULL, updated_at = CURRENT_TIMESTAMP FROM crm_contacts p WHERE p.id = t.contact_id');

        // Only entries with no Location and no deal: a C6 entry stays on the Location it names.
        $this->addSql(<<<'SQL'
            INSERT INTO crm_activity_companies (activity_id, company_id)
            SELECT DISTINCT l.activity_id, p.company_id
            FROM crm_activity_contacts l
            JOIN crm_contacts p ON p.id = l.contact_id
            WHERE NOT EXISTS (SELECT 1 FROM crm_activity_companies c WHERE c.activity_id = l.activity_id)
              AND NOT EXISTS (SELECT 1 FROM crm_activity_deals d WHERE d.activity_id = l.activity_id)
            ON CONFLICT DO NOTHING
            SQL);

        $this->addSql('DROP TABLE crm_activity_contacts');
        $this->addSql('DROP TABLE crm_deal_contacts');

        $this->addSql(<<<'SQL'
            DELETE FROM crm_activities a
            WHERE NOT EXISTS (SELECT 1 FROM crm_activity_companies c WHERE c.activity_id = a.id)
              AND NOT EXISTS (SELECT 1 FROM crm_activity_deals d WHERE d.activity_id = a.id)
            SQL);

        $this->addSql('ALTER TABLE crm_tasks DROP CONSTRAINT chk_crm_tasks_one_object');
        $this->addSql('DROP INDEX idx_crm_tasks_contact_id');
        $this->addSql('ALTER TABLE crm_tasks DROP CONSTRAINT fk_crm_tasks_contact_id');
        $this->addSql('ALTER TABLE crm_tasks DROP COLUMN contact_id');
        $this->addSql('ALTER TABLE crm_tasks ADD CONSTRAINT chk_crm_tasks_one_object CHECK (num_nonnulls(company_id, deal_id) <= 1)');

        $this->addSql('DROP INDEX idx_crm_inquiry_links_contact_id');
        $this->addSql('ALTER TABLE crm_inquiry_links DROP CONSTRAINT fk_crm_inquiry_links_contact_id');
        $this->addSql('ALTER TABLE crm_inquiry_links DROP COLUMN contact_id');

        $this->addSql('ALTER TABLE crm_contacts DROP CONSTRAINT chk_crm_contacts_kind');
        $this->addSql('ALTER TABLE crm_contacts DROP COLUMN kind');
        $this->addSql('DROP INDEX idx_crm_contacts_owner_id');
        $this->addSql('ALTER TABLE crm_contacts DROP CONSTRAINT fk_crm_contacts_owner_id');
        $this->addSql('ALTER TABLE crm_contacts DROP COLUMN owner_id');
        $this->addSql('ALTER TABLE crm_contacts ALTER COLUMN company_id SET NOT NULL');
        $this->addSql('ALTER TABLE crm_contacts DROP CONSTRAINT fk_crm_contacts_company_id');
        $this->addSql('ALTER TABLE crm_contacts ADD CONSTRAINT fk_crm_contacts_company_id FOREIGN KEY (company_id) REFERENCES crm_companies (id) ON DELETE CASCADE');

        $this->addSql($this->postconditions($pre, $fingerprint));

        // ON COMMIT DROP covers a deploy; a second up() in the same transaction (the test) needs them gone now.
        $this->addSql('DROP TABLE crm55_expect, crm55_copied');
    }

    public function down(Schema $schema): void
    {
        // What down() cannot restore (only the Neon restore can): the erased couples, the entry–contact and
        // deal–contact links, the moved tasks' contact_id, the kind and owner_id values, crm_inquiry_links.contact_id.
        $this->addSql(<<<'SQL'
            CREATE TABLE crm_deal_contacts (
                deal_id UUID NOT NULL,
                contact_id UUID NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                CONSTRAINT pk_crm_deal_contacts PRIMARY KEY (deal_id, contact_id),
                CONSTRAINT fk_crm_deal_contacts_deal_id FOREIGN KEY (deal_id) REFERENCES crm_deals (id) ON DELETE CASCADE,
                CONSTRAINT fk_crm_deal_contacts_contact_id FOREIGN KEY (contact_id) REFERENCES crm_contacts (id) ON DELETE CASCADE
            )
            SQL);
        $this->addSql('CREATE INDEX idx_crm_deal_contacts_deal_id ON crm_deal_contacts (deal_id)');
        $this->addSql('CREATE INDEX idx_crm_deal_contacts_contact_id ON crm_deal_contacts (contact_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE crm_activity_contacts (
                activity_id UUID NOT NULL,
                contact_id UUID NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                CONSTRAINT pk_crm_activity_contacts PRIMARY KEY (activity_id, contact_id),
                CONSTRAINT fk_crm_activity_contacts_activity_id FOREIGN KEY (activity_id) REFERENCES crm_activities (id) ON DELETE CASCADE,
                CONSTRAINT fk_crm_activity_contacts_contact_id FOREIGN KEY (contact_id) REFERENCES crm_contacts (id) ON DELETE CASCADE
            )
            SQL);
        $this->addSql('CREATE INDEX idx_crm_activity_contacts_activity_id ON crm_activity_contacts (activity_id)');
        $this->addSql('CREATE INDEX idx_crm_activity_contacts_contact_id ON crm_activity_contacts (contact_id)');

        $this->addSql('ALTER TABLE crm_tasks DROP CONSTRAINT chk_crm_tasks_one_object');
        $this->addSql('ALTER TABLE crm_tasks ADD COLUMN contact_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE crm_tasks ADD CONSTRAINT fk_crm_tasks_contact_id FOREIGN KEY (contact_id) REFERENCES crm_contacts (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX idx_crm_tasks_contact_id ON crm_tasks (contact_id)');
        $this->addSql('ALTER TABLE crm_tasks ADD CONSTRAINT chk_crm_tasks_one_object CHECK (num_nonnulls(company_id, contact_id, deal_id) <= 1)');

        $this->addSql('ALTER TABLE crm_inquiry_links ADD COLUMN contact_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE crm_inquiry_links ADD CONSTRAINT fk_crm_inquiry_links_contact_id FOREIGN KEY (contact_id) REFERENCES crm_contacts (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX idx_crm_inquiry_links_contact_id ON crm_inquiry_links (contact_id)');

        // The old mapping has no default on kind; every remaining row is a Location's contact.
        $this->addSql("ALTER TABLE crm_contacts ADD COLUMN kind VARCHAR(20) DEFAULT 'venue_contact' NOT NULL");
        $this->addSql('ALTER TABLE crm_contacts ALTER COLUMN kind DROP DEFAULT');
        $this->addSql("ALTER TABLE crm_contacts ADD CONSTRAINT chk_crm_contacts_kind CHECK (kind IN ('venue_contact', 'couple', 'other'))");
        $this->addSql('ALTER TABLE crm_contacts ADD COLUMN owner_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE crm_contacts ADD CONSTRAINT fk_crm_contacts_owner_id FOREIGN KEY (owner_id) REFERENCES admin_users (id) ON DELETE RESTRICT');
        $this->addSql('CREATE INDEX idx_crm_contacts_owner_id ON crm_contacts (owner_id)');
        $this->addSql('ALTER TABLE crm_contacts ALTER COLUMN company_id DROP NOT NULL');
        $this->addSql('ALTER TABLE crm_contacts DROP CONSTRAINT fk_crm_contacts_company_id');
        $this->addSql('ALTER TABLE crm_contacts ADD CONSTRAINT fk_crm_contacts_company_id FOREIGN KEY (company_id) REFERENCES crm_companies (id) ON DELETE SET NULL');

        // The old schema holds an e-mail once: the oldest row keeps it, the others lose only the e-mail (D11).
        $this->addSql(<<<'SQL'
            UPDATE crm_contacts SET email = NULL, updated_at = CURRENT_TIMESTAMP
            WHERE id IN (
                SELECT id FROM (
                    SELECT id, row_number() OVER (PARTITION BY email ORDER BY created_at, id) AS n
                    FROM crm_contacts WHERE email IS NOT NULL
                ) x WHERE n > 1
            )
            SQL);
        $this->addSql('ALTER TABLE crm_contacts DROP CONSTRAINT uk_crm_contacts_company_email');
        $this->addSql('ALTER TABLE crm_contacts ADD CONSTRAINT uk_crm_contacts_email UNIQUE (email)');
    }

    /**
     * Evaluated when the SQL runs, so the deploy aborts on the data it would change. One RAISE names every failing
     * metric as `<code> = <count> (<meaning>)`.
     */
    private function preconditions(): string
    {
        $checks = '';
        foreach (self::GUARDS as $code => [$meaning, $sql]) {
            $checks .= \sprintf(
                "    n := (%s);\n    IF n > 0 THEN failed := array_append(failed, format('%%s = %%s (%%s)', %s, n, %s)); END IF;\n",
                $sql,
                $this->connection->quote($code),
                $this->connection->quote($meaning),
            );
        }

        return \sprintf(<<<'SQL'
            DO $$
            DECLARE
                n bigint;
                failed text[] := '{}';
            BEGIN
            %s
                IF cardinality(failed) > 0 THEN
                    RAISE EXCEPTION '#55 precondition failed: %%. %s', array_to_string(failed, '; ');
                END IF;
            END
            $$
            SQL, $checks, self::RUNBOOK);
    }

    /**
     * @param array<string, int> $pre
     */
    private function postconditions(array $pre, string $fingerprint): string
    {
        $checks = [
            'P-inquiries' => [$pre['inquiries'], 'SELECT count(*) FROM inquiries'],
            'P-links' => [$pre['inquiry links'], 'SELECT count(*) FROM crm_inquiry_links'],
            'P-inquiry-entries' => [$pre['inquiry entries'], "SELECT count(*) FROM crm_activities WHERE type_code = 'inquiry'"],
            'P-entries' => [$pre['entries'] - $pre['X0'], 'SELECT count(*) FROM crm_activities'],
            'P-unlinked' => [0, 'SELECT count(*) FROM crm_activities a WHERE NOT EXISTS (SELECT 1 FROM crm_activity_companies l WHERE l.activity_id = a.id) AND NOT EXISTS (SELECT 1 FROM crm_activity_deals l WHERE l.activity_id = a.id)'],
            'P-orphan-tasks' => [$pre['F5'], 'SELECT count(*) FROM crm_tasks WHERE num_nonnulls(company_id, deal_id) = 0'],
            'P-tasks' => [$pre['tasks'] - $pre['T-couple'], 'SELECT count(*) FROM crm_tasks'],
            'P-company-tasks' => [$pre['F1'] + $pre['F2'], 'SELECT count(*) FROM crm_tasks WHERE company_id IS NOT NULL'],
            // Every remaining contact is a pre-switch non-couple contact or a copy.
            'P-contacts' => [$pre['non-couple contacts'], 'SELECT (SELECT count(*) FROM crm_contacts) - (SELECT count(*) FROM crm55_copied)'],
            'P-nocompany' => [0, 'SELECT count(*) FROM crm_contacts WHERE company_id IS NULL'],
            'P-audit' => [$pre['audit rows'], 'SELECT count(*) FROM admin_audit_log'],
        ];
        foreach (['e6', 'c6', 'manager'] as $reason) {
            $checks['P-expected-'.$reason] = [0, \sprintf(
                'SELECT count(*) FROM crm55_expect e WHERE e.reason = %s AND NOT EXISTS (SELECT 1 FROM crm_contacts x WHERE %s)',
                $this->connection->quote($reason),
                self::SAME_PERSON,
            )];
        }

        $body = '';
        foreach ($checks as $code => [$expected, $sql]) {
            $body .= \sprintf(
                "    n := (%s);\n    IF n <> %d THEN failed := array_append(failed, format('%%s: expected %%s, got %%s', %s, %d, n)); END IF;\n",
                $sql,
                $expected,
                $this->connection->quote($code),
                $expected,
            );
        }
        $body .= \sprintf(
            "    IF (%s) <> %s THEN failed := array_append(failed, 'P-orphan-tasks: the tasks on nothing changed'); END IF;\n",
            \sprintf(self::F5_FINGERPRINT, 'num_nonnulls(company_id, deal_id) = 0'),
            $this->connection->quote($fingerprint),
        );

        return \sprintf(<<<'SQL'
            DO $$
            DECLARE
                n bigint;
                failed text[] := '{}';
            BEGIN
            %s
                IF cardinality(failed) > 0 THEN
                    RAISE EXCEPTION '#55 postcondition failed: %%. Nothing was changed. %s', array_to_string(failed, '; ');
                END IF;
            END
            $$
            SQL, $body, self::RUNBOOK);
    }
}
