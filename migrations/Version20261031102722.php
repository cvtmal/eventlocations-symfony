<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The CRM's tables (#27, S13). New tables only: no existing table is altered, so the migration
 * is safe on production data.
 *
 * - crm_company_venues.venue_id references venues(id), unique (a venue belongs to at most one
 *   company) and ON DELETE CASCADE: deleting a venue drops the link and keeps the company (D2, D3).
 *   The single-column unique key is its index: Doctrine accepts it in place of an idx_ there.
 * - Admin users are never deleted, so every reference to admin_users is RESTRICT (D4). A contact
 *   outlives its company (SET NULL); links, activity links and tasks go with their object (CASCADE).
 * - crm_contacts.email is stored lower-case (chk_crm_contacts_email_lower) with a plain unique key,
 *   the admin_users pattern: unique when present, case-insensitively (D6).
 * - crm_activity_types is a code-keyed vocabulary (natural PK, like cantons); later cycles add a
 *   type by inserting a row (S13). An anonymised activity keeps no content (D16).
 * - The search indexes are GIN trigram expression indexes on geo_search_key(), unmapped like
 *   idx_venues_search_key.
 */
final class Version20261031102722 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'crm-foundation: companies, contacts, venue links, activities and tasks (#27)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE crm_companies (
                id UUID NOT NULL,
                name VARCHAR(150) NOT NULL,
                legal_name VARCHAR(200) DEFAULT NULL,
                website VARCHAR(500) DEFAULT NULL,
                phone VARCHAR(40) DEFAULT NULL,
                email VARCHAR(254) DEFAULT NULL,
                address VARCHAR(300) DEFAULT NULL,
                stage VARCHAR(20) DEFAULT 'prospect' NOT NULL,
                owner_id UUID DEFAULT NULL,
                notes TEXT DEFAULT NULL,
                origin VARCHAR(10) NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                CONSTRAINT pk_crm_companies PRIMARY KEY (id),
                CONSTRAINT fk_crm_companies_owner_id FOREIGN KEY (owner_id) REFERENCES admin_users (id) ON DELETE RESTRICT,
                CONSTRAINT chk_crm_companies_name CHECK (btrim(name) <> ''),
                CONSTRAINT chk_crm_companies_stage CHECK (stage IN ('prospect', 'customer', 'former_customer')),
                CONSTRAINT chk_crm_companies_origin CHECK (origin IN ('manual', 'venue'))
            )
            SQL);
        $this->addSql('CREATE INDEX idx_crm_companies_owner_id ON crm_companies (owner_id)');
        $this->addSql('CREATE INDEX idx_crm_companies_search_key ON crm_companies USING gin (geo_search_key(name) gin_trgm_ops)');

        $this->addSql(<<<'SQL'
            CREATE TABLE crm_contacts (
                id UUID NOT NULL,
                first_name VARCHAR(150) DEFAULT NULL,
                last_name VARCHAR(150) NOT NULL,
                email VARCHAR(254) DEFAULT NULL,
                phone VARCHAR(40) DEFAULT NULL,
                position VARCHAR(150) DEFAULT NULL,
                kind VARCHAR(20) NOT NULL,
                company_id UUID DEFAULT NULL,
                owner_id UUID DEFAULT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                CONSTRAINT pk_crm_contacts PRIMARY KEY (id),
                CONSTRAINT uk_crm_contacts_email UNIQUE (email),
                CONSTRAINT fk_crm_contacts_company_id FOREIGN KEY (company_id) REFERENCES crm_companies (id) ON DELETE SET NULL,
                CONSTRAINT fk_crm_contacts_owner_id FOREIGN KEY (owner_id) REFERENCES admin_users (id) ON DELETE RESTRICT,
                CONSTRAINT chk_crm_contacts_last_name CHECK (btrim(last_name) <> ''),
                CONSTRAINT chk_crm_contacts_email_lower CHECK (email = lower(email)),
                CONSTRAINT chk_crm_contacts_kind CHECK (kind IN ('venue_contact', 'couple', 'other'))
            )
            SQL);
        $this->addSql('CREATE INDEX idx_crm_contacts_company_id ON crm_contacts (company_id)');
        $this->addSql('CREATE INDEX idx_crm_contacts_owner_id ON crm_contacts (owner_id)');
        $this->addSql("CREATE INDEX idx_crm_contacts_search_key ON crm_contacts USING gin (geo_search_key(coalesce(first_name, '') || ' ' || last_name) gin_trgm_ops)");

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

        $this->addSql(<<<'SQL'
            CREATE TABLE crm_activity_types (
                code VARCHAR(30) NOT NULL,
                position SMALLINT NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                CONSTRAINT pk_crm_activity_types PRIMARY KEY (code),
                CONSTRAINT uk_crm_activity_types_position UNIQUE (position),
                CONSTRAINT chk_crm_activity_types_code CHECK (code ~ '^[a-z][a-z0-9_]*$'),
                CONSTRAINT chk_crm_activity_types_position CHECK (position > 0)
            )
            SQL);
        $this->addSql("INSERT INTO crm_activity_types (code, position) VALUES ('note', 1), ('email_logged', 2), ('call', 3), ('meeting', 4)");

        $this->addSql(<<<'SQL'
            CREATE TABLE crm_activities (
                id UUID NOT NULL,
                type_code VARCHAR(30) NOT NULL,
                occurred_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                actor_id UUID DEFAULT NULL,
                title VARCHAR(200) DEFAULT NULL,
                body TEXT DEFAULT NULL,
                payload JSONB DEFAULT '{}' NOT NULL,
                anonymised_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                CONSTRAINT pk_crm_activities PRIMARY KEY (id),
                CONSTRAINT fk_crm_activities_type_code FOREIGN KEY (type_code) REFERENCES crm_activity_types (code) ON DELETE RESTRICT,
                CONSTRAINT fk_crm_activities_actor_id FOREIGN KEY (actor_id) REFERENCES admin_users (id) ON DELETE RESTRICT,
                CONSTRAINT chk_crm_activities_payload CHECK (jsonb_typeof(payload) = 'object'),
                CONSTRAINT chk_crm_activities_content CHECK (anonymised_at IS NOT NULL OR btrim(coalesce(title, '')) <> '' OR btrim(coalesce(body, '')) <> ''),
                CONSTRAINT chk_crm_activities_anonymised CHECK (anonymised_at IS NULL OR (title IS NULL AND body IS NULL AND payload = '{}'::jsonb))
            )
            SQL);
        $this->addSql('CREATE INDEX idx_crm_activities_type_code ON crm_activities (type_code)');
        $this->addSql('CREATE INDEX idx_crm_activities_actor_id ON crm_activities (actor_id)');
        $this->addSql('CREATE INDEX idx_crm_activities_occurred_at_id ON crm_activities (occurred_at, id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE crm_activity_companies (
                activity_id UUID NOT NULL,
                company_id UUID NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                CONSTRAINT pk_crm_activity_companies PRIMARY KEY (activity_id, company_id),
                CONSTRAINT fk_crm_activity_companies_activity_id FOREIGN KEY (activity_id) REFERENCES crm_activities (id) ON DELETE CASCADE,
                CONSTRAINT fk_crm_activity_companies_company_id FOREIGN KEY (company_id) REFERENCES crm_companies (id) ON DELETE CASCADE
            )
            SQL);
        $this->addSql('CREATE INDEX idx_crm_activity_companies_activity_id ON crm_activity_companies (activity_id)');
        $this->addSql('CREATE INDEX idx_crm_activity_companies_company_id ON crm_activity_companies (company_id)');

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

        $this->addSql(<<<'SQL'
            CREATE TABLE crm_tasks (
                id UUID NOT NULL,
                title VARCHAR(200) NOT NULL,
                due_on DATE NOT NULL,
                assignee_id UUID NOT NULL,
                created_by_id UUID DEFAULT NULL,
                done_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                company_id UUID DEFAULT NULL,
                contact_id UUID DEFAULT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                CONSTRAINT pk_crm_tasks PRIMARY KEY (id),
                CONSTRAINT fk_crm_tasks_assignee_id FOREIGN KEY (assignee_id) REFERENCES admin_users (id) ON DELETE RESTRICT,
                CONSTRAINT fk_crm_tasks_created_by_id FOREIGN KEY (created_by_id) REFERENCES admin_users (id) ON DELETE RESTRICT,
                CONSTRAINT fk_crm_tasks_company_id FOREIGN KEY (company_id) REFERENCES crm_companies (id) ON DELETE CASCADE,
                CONSTRAINT fk_crm_tasks_contact_id FOREIGN KEY (contact_id) REFERENCES crm_contacts (id) ON DELETE CASCADE,
                CONSTRAINT chk_crm_tasks_title CHECK (btrim(title) <> ''),
                CONSTRAINT chk_crm_tasks_one_object CHECK (num_nonnulls(company_id, contact_id) <= 1)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_crm_tasks_assignee_id ON crm_tasks (assignee_id)');
        $this->addSql('CREATE INDEX idx_crm_tasks_created_by_id ON crm_tasks (created_by_id)');
        $this->addSql('CREATE INDEX idx_crm_tasks_company_id ON crm_tasks (company_id)');
        $this->addSql('CREATE INDEX idx_crm_tasks_contact_id ON crm_tasks (contact_id)');
        $this->addSql('CREATE INDEX idx_crm_tasks_due_on ON crm_tasks (due_on)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE crm_tasks');
        $this->addSql('DROP TABLE crm_activity_contacts');
        $this->addSql('DROP TABLE crm_activity_companies');
        $this->addSql('DROP TABLE crm_activities');
        $this->addSql('DROP TABLE crm_activity_types');
        $this->addSql('DROP TABLE crm_company_venues');
        $this->addSql('DROP TABLE crm_contacts');
        $this->addSql('DROP TABLE crm_companies');
    }
}
