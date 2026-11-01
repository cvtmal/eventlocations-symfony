<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Deals (#28, S13, CRM-7, CRM-8), additive, so the old code keeps working on it during a deploy's sync.
 *
 * - `crm_deal_stages` are code-keyed rows: a new stage is a migration, not a code change. One stage is
 *   won and one is lost (`uk_crm_deal_stages_won`, `_lost`).
 * - `crm_deals.amount_rappen` is a BIGINT in Rappen, CHF only (S13 over `database.md`'s INTEGER and currency).
 *   `amount_rappen` and `tier` are nullable: a new deal may not have them yet. `venue_id` is SET NULL, because
 *   Venues may not know the CRM; `company_id` is RESTRICT, so a company click never erases contract history.
 *   `renewed_from_id` is UNIQUE, which makes a second renewal of one deal impossible.
 * - `crm_tasks` gains `deal_id` and `is_renewal`. The three-object CHECK is implied by the old one for every
 *   existing row (`deal_id` is NULL), and DROP then ADD in one transaction leaves no moment without a check.
 *   `uk_crm_tasks_renewal` survives completion, so a done renewal task is not created again.
 * - `deal_stage_changed` takes position 5; #29's `inquiry` takes 6, #30's `email_sent` 7.
 * - `idx_crm_deals_search_key` is a GIN trigram expression index on geo_search_key(), unmapped like
 *   idx_venues_search_key.
 */
final class Version20261031232837 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '#28: deal stages, deals, their links, tasks on a deal, the deal_stage_changed activity type';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE crm_deal_stages (
                code VARCHAR(30) NOT NULL,
                position SMALLINT NOT NULL,
                probability SMALLINT NOT NULL,
                is_won BOOLEAN DEFAULT FALSE NOT NULL,
                is_lost BOOLEAN DEFAULT FALSE NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                CONSTRAINT pk_crm_deal_stages PRIMARY KEY (code),
                CONSTRAINT uk_crm_deal_stages_position UNIQUE (position),
                CONSTRAINT chk_crm_deal_stages_code CHECK (code ~ '^[a-z][a-z0-9_]*$'),
                CONSTRAINT chk_crm_deal_stages_position CHECK (position > 0),
                CONSTRAINT chk_crm_deal_stages_probability CHECK (probability BETWEEN 0 AND 100),
                CONSTRAINT chk_crm_deal_stages_outcome CHECK (NOT (is_won AND is_lost))
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uk_crm_deal_stages_won ON crm_deal_stages (is_won) WHERE is_won');
        $this->addSql('CREATE UNIQUE INDEX uk_crm_deal_stages_lost ON crm_deal_stages (is_lost) WHERE is_lost');
        $this->addSql(<<<'SQL'
            INSERT INTO crm_deal_stages (code, position, probability, is_won, is_lost) VALUES
                ('new', 1, 10, FALSE, FALSE),
                ('contacted', 2, 25, FALSE, FALSE),
                ('offer', 3, 50, FALSE, FALSE),
                ('negotiation', 4, 75, FALSE, FALSE),
                ('won', 5, 100, TRUE, FALSE),
                ('lost', 6, 0, FALSE, TRUE)
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE crm_deals (
                id UUID NOT NULL,
                name VARCHAR(150) NOT NULL,
                company_id UUID NOT NULL,
                venue_id UUID DEFAULT NULL,
                tier VARCHAR(20) DEFAULT NULL,
                amount_rappen BIGINT DEFAULT NULL,
                contract_start_on DATE DEFAULT NULL,
                contract_end_on DATE DEFAULT NULL,
                expected_close_on DATE DEFAULT NULL,
                stage_code VARCHAR(30) NOT NULL,
                owner_id UUID DEFAULT NULL,
                lost_reason VARCHAR(30) DEFAULT NULL,
                closed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                renewed_from_id UUID DEFAULT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                CONSTRAINT pk_crm_deals PRIMARY KEY (id),
                CONSTRAINT fk_crm_deals_company_id FOREIGN KEY (company_id) REFERENCES crm_companies (id) ON DELETE RESTRICT,
                CONSTRAINT fk_crm_deals_venue_id FOREIGN KEY (venue_id) REFERENCES venues (id) ON DELETE SET NULL,
                CONSTRAINT fk_crm_deals_stage_code FOREIGN KEY (stage_code) REFERENCES crm_deal_stages (code) ON DELETE RESTRICT,
                CONSTRAINT fk_crm_deals_owner_id FOREIGN KEY (owner_id) REFERENCES admin_users (id) ON DELETE RESTRICT,
                CONSTRAINT fk_crm_deals_renewed_from_id FOREIGN KEY (renewed_from_id) REFERENCES crm_deals (id) ON DELETE SET NULL,
                CONSTRAINT uk_crm_deals_renewed_from_id UNIQUE (renewed_from_id),
                CONSTRAINT chk_crm_deals_name CHECK (btrim(name) <> ''),
                CONSTRAINT chk_crm_deals_tier CHECK (tier IN ('beliebte_location', 'empfehlung', 'top_premium', 'premium', 'basic')),
                CONSTRAINT chk_crm_deals_amount CHECK (amount_rappen >= 0),
                CONSTRAINT chk_crm_deals_contract CHECK (contract_end_on >= contract_start_on),
                CONSTRAINT chk_crm_deals_lost_reason CHECK (lost_reason IN ('budget', 'competitor', 'no_need', 'no_response', 'other'))
            )
            SQL);
        $this->addSql('CREATE INDEX idx_crm_deals_company_id ON crm_deals (company_id)');
        $this->addSql('CREATE INDEX idx_crm_deals_venue_id ON crm_deals (venue_id)');
        $this->addSql('CREATE INDEX idx_crm_deals_stage_code ON crm_deals (stage_code)');
        $this->addSql('CREATE INDEX idx_crm_deals_owner_id ON crm_deals (owner_id)');
        $this->addSql('CREATE INDEX idx_crm_deals_contract_end_on ON crm_deals (contract_end_on)');
        $this->addSql('CREATE INDEX idx_crm_deals_search_key ON crm_deals USING gin (geo_search_key(name) gin_trgm_ops)');

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
            CREATE TABLE crm_activity_deals (
                activity_id UUID NOT NULL,
                deal_id UUID NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                CONSTRAINT pk_crm_activity_deals PRIMARY KEY (activity_id, deal_id),
                CONSTRAINT fk_crm_activity_deals_activity_id FOREIGN KEY (activity_id) REFERENCES crm_activities (id) ON DELETE CASCADE,
                CONSTRAINT fk_crm_activity_deals_deal_id FOREIGN KEY (deal_id) REFERENCES crm_deals (id) ON DELETE CASCADE
            )
            SQL);
        $this->addSql('CREATE INDEX idx_crm_activity_deals_activity_id ON crm_activity_deals (activity_id)');
        $this->addSql('CREATE INDEX idx_crm_activity_deals_deal_id ON crm_activity_deals (deal_id)');

        $this->addSql('ALTER TABLE crm_tasks ADD COLUMN deal_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE crm_tasks ADD COLUMN is_renewal BOOLEAN DEFAULT FALSE NOT NULL');
        $this->addSql('ALTER TABLE crm_tasks ADD CONSTRAINT fk_crm_tasks_deal_id FOREIGN KEY (deal_id) REFERENCES crm_deals (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX idx_crm_tasks_deal_id ON crm_tasks (deal_id)');
        $this->addSql('ALTER TABLE crm_tasks DROP CONSTRAINT chk_crm_tasks_one_object');
        $this->addSql('ALTER TABLE crm_tasks ADD CONSTRAINT chk_crm_tasks_one_object CHECK (num_nonnulls(company_id, contact_id, deal_id) <= 1)');
        $this->addSql('ALTER TABLE crm_tasks ADD CONSTRAINT chk_crm_tasks_renewal CHECK (NOT is_renewal OR deal_id IS NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX uk_crm_tasks_renewal ON crm_tasks (deal_id) WHERE is_renewal');

        $this->addSql("INSERT INTO crm_activity_types (code, position) VALUES ('deal_stage_changed', 5)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM crm_activities WHERE type_code = 'deal_stage_changed'");
        $this->addSql("DELETE FROM crm_activity_types WHERE code = 'deal_stage_changed'");

        $this->addSql('DROP INDEX uk_crm_tasks_renewal');
        $this->addSql('ALTER TABLE crm_tasks DROP CONSTRAINT chk_crm_tasks_renewal');
        // A task on a deal has no place in the old schema; the tasks go with the deals, as the deals' cascade would.
        $this->addSql('DELETE FROM crm_tasks WHERE deal_id IS NOT NULL');
        $this->addSql('ALTER TABLE crm_tasks DROP CONSTRAINT chk_crm_tasks_one_object');
        $this->addSql('ALTER TABLE crm_tasks ADD CONSTRAINT chk_crm_tasks_one_object CHECK (num_nonnulls(company_id, contact_id) <= 1)');
        $this->addSql('DROP INDEX idx_crm_tasks_deal_id');
        $this->addSql('ALTER TABLE crm_tasks DROP CONSTRAINT fk_crm_tasks_deal_id');
        $this->addSql('ALTER TABLE crm_tasks DROP COLUMN is_renewal');
        $this->addSql('ALTER TABLE crm_tasks DROP COLUMN deal_id');

        $this->addSql('DROP TABLE crm_activity_deals');
        $this->addSql('DROP TABLE crm_deal_contacts');
        $this->addSql('DROP TABLE crm_deals');
        $this->addSql('DROP TABLE crm_deal_stages');
    }
}
