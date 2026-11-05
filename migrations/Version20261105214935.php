<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * #56: the Location's Eintrag moves from `crm_company_venues` onto `crm_companies.venue_id` (unique, FK SET NULL).
 * One transaction that guards itself; any RAISE rolls the whole migration back and the deploy stops with the app
 * unchanged (runbook: docs/operations.md "CRM one Eintrag per Location (#56)").
 *
 * Rules, in order:
 * 1. The counted tables are locked against writers, then every count is read and printed (`#56 pre:`).
 * 2. Preconditions abort (one message listing every failing metric): A6 company with 2+ venues, V2 venue on 2+
 *    companies. Nothing is split or deleted.
 * 3. `crm_companies.venue_id` is added and filled from the link table; `updated_at` stays (no business change).
 * 4. `uk_crm_companies_venue_id` and `fk_crm_companies_venue_id … ON DELETE SET NULL` (no separate index: the
 *    unique key serves).
 * 5. Postconditions, checked against the link table while it still exists, with exact pre/post arithmetic, abort
 *    the same way.
 * 6. `crm_company_venues` is dropped (its keys and index go with it).
 *
 * Production counts of 2026-11-05, unchanged by #55: A1 1499, A5 249, A6 0, B1 249, B2 0, K2 4.
 *
 * Not additive: the old code reads `crm_company_venues` in the seconds between this migration and the rsync
 * (deploy-window exception, see the runbook). down() is complete: every link comes back, with new ids and timestamps.
 */
final class Version20261105214935 extends AbstractMigration
{
    private const RUNBOOK = 'See docs/operations.md "CRM one Eintrag per Location (#56)".';

    /** Code => [meaning, count query]; each aborts the migration when its count is above 0. */
    private const GUARDS = [
        'A6' => ['companies with 2+ venues', 'WITH cv AS (SELECT company_id, count(*) AS venues FROM crm_company_venues GROUP BY company_id) SELECT count(*) FROM cv WHERE venues > 1'],
        'V2' => ['venues on 2+ companies', 'SELECT count(*) FROM (SELECT venue_id FROM crm_company_venues GROUP BY venue_id HAVING count(*) > 1) x'],
    ];

    /** Code => [meaning, count query]; reported in the deploy log, never guarded. The totals feed the postconditions. */
    private const REPORTED = [
        'A1' => ['companies', 'SELECT count(*) FROM crm_companies'],
        'A5' => ['companies with a venue', 'SELECT count(DISTINCT company_id) FROM crm_company_venues'],
        'links' => ['', 'SELECT count(*) FROM crm_company_venues'],
        'B1' => ['venues', 'SELECT count(*) FROM venues'],
        'B2' => ['venues without a company', 'SELECT count(*) FROM venues v WHERE NOT EXISTS (SELECT 1 FROM crm_company_venues x WHERE x.venue_id = v.id)'],
        'K2' => ['Liechtenstein venues on a company', "SELECT count(*) FROM crm_company_venues x JOIN venues v ON v.id = x.venue_id WHERE v.canton_code = 'li'"],
    ];

    /** Every table the pre-counts read; locked against writers for the migration's transaction. */
    public const array LOCKED = ['crm_companies', 'crm_company_venues', 'venues'];

    public function getDescription(): string
    {
        return 'The Location\'s Eintrag moves onto crm_companies.venue_id; crm_company_venues is dropped (#56)';
    }

    public function up(Schema $schema): void
    {
        // Doctrine opened the transaction before up(). A staff link or a venue created between these reads and the
        // postconditions would move the exact counts and roll an unattended deploy back for nothing. The lock comes
        // before the first count, so the counts are consistent: SHARE ROW EXCLUSIVE lets reads through and makes
        // writers wait the second this migration takes; the later ALTER and DROP TABLE take ACCESS EXCLUSIVE on
        // crm_companies and venues, so readers wait too from there. SET LOCAL bounds every one of those waits:
        // lock_timeout turns a long wait into a rollback instead of a hung deploy.
        $this->connection->executeStatement("SET LOCAL lock_timeout = '15s'");
        $this->connection->executeStatement('LOCK TABLE '.implode(', ', self::LOCKED).' IN SHARE ROW EXCLUSIVE MODE');

        $pre = [];
        foreach (self::GUARDS + self::REPORTED as $code => [$meaning, $sql]) {
            $pre[$code] = (int) $this->connection->fetchOne($sql);
            $this->write(\sprintf('#56 pre: %s = %d', trim($code.' '.$meaning), $pre[$code]));
        }

        $this->addSql($this->preconditions());

        $this->addSql('ALTER TABLE crm_companies ADD venue_id UUID DEFAULT NULL');
        $this->addSql('UPDATE crm_companies c SET venue_id = l.venue_id FROM crm_company_venues l WHERE l.company_id = c.id');
        $this->addSql('ALTER TABLE crm_companies ADD CONSTRAINT uk_crm_companies_venue_id UNIQUE (venue_id)');
        $this->addSql('ALTER TABLE crm_companies ADD CONSTRAINT fk_crm_companies_venue_id FOREIGN KEY (venue_id) REFERENCES venues (id) ON DELETE SET NULL');

        $this->addSql($this->postconditions($pre));

        $this->addSql('DROP TABLE crm_company_venues');
    }

    public function down(Schema $schema): void
    {
        // Complete: every Eintrag comes back as a link row. Only the link ids and timestamps are new, and nothing
        // read them.
        $this->addSql(<<<'SQL'
            CREATE TABLE crm_company_venues (
                id UUID NOT NULL,
                company_id UUID NOT NULL,
                venue_id UUID NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                CONSTRAINT pk_crm_company_venues PRIMARY KEY (id),
                CONSTRAINT uk_crm_company_venues_venue_id UNIQUE (venue_id),
                CONSTRAINT fk_crm_company_venues_company_id FOREIGN KEY (company_id) REFERENCES crm_companies (id) ON DELETE CASCADE,
                CONSTRAINT fk_crm_company_venues_venue_id FOREIGN KEY (venue_id) REFERENCES venues (id) ON DELETE CASCADE
            )
            SQL);
        $this->addSql('CREATE INDEX idx_crm_company_venues_company_id ON crm_company_venues (company_id)');
        $this->addSql('INSERT INTO crm_company_venues (id, company_id, venue_id, created_at, updated_at) SELECT uuidv7(), id, venue_id, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP FROM crm_companies WHERE venue_id IS NOT NULL');
        $this->addSql('ALTER TABLE crm_companies DROP CONSTRAINT fk_crm_companies_venue_id');
        $this->addSql('ALTER TABLE crm_companies DROP CONSTRAINT uk_crm_companies_venue_id');
        $this->addSql('ALTER TABLE crm_companies DROP COLUMN venue_id');
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
                    RAISE EXCEPTION '#56 precondition failed: %%. Nothing was changed. %s', array_to_string(failed, '; ');
                END IF;
            END
            $$
            SQL, $checks, self::RUNBOOK);
    }

    /**
     * @param array<string, int> $pre
     */
    private function postconditions(array $pre): string
    {
        $checks = [
            'P-links' => [$pre['links'], 'SELECT count(*) FROM crm_companies WHERE venue_id IS NOT NULL'],
            'P-copied' => [0, 'SELECT count(*) FROM crm_company_venues l JOIN crm_companies c ON c.id = l.company_id WHERE c.venue_id IS DISTINCT FROM l.venue_id'],
            'P-companies' => [$pre['A1'], 'SELECT count(*) FROM crm_companies'],
            'P-venues' => [$pre['B1'], 'SELECT count(*) FROM venues'],
            'P-B2' => [$pre['B2'], 'SELECT count(*) FROM venues v WHERE NOT EXISTS (SELECT 1 FROM crm_companies c WHERE c.venue_id = v.id)'],
            'P-K2' => [$pre['K2'], "SELECT count(*) FROM crm_companies c JOIN venues v ON v.id = c.venue_id WHERE v.canton_code = 'li'"],
        ];

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

        return \sprintf(<<<'SQL'
            DO $$
            DECLARE
                n bigint;
                failed text[] := '{}';
            BEGIN
            %s
                IF cardinality(failed) > 0 THEN
                    RAISE EXCEPTION '#56 postcondition failed: %%. Nothing was changed. %s', array_to_string(failed, '; ');
                END IF;
            END
            $$
            SQL, $body, self::RUNBOOK);
    }
}
