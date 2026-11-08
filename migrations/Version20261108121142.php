<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * G11 `outreach-tracking`: what the CRM knows about an outreach to a Location, and the suppression list.
 *
 * `crm_companies` gets the outreach fields of CMO §6.5: the postal campaign code (the plain-code rule of
 * `funnel_events`), the pilot cohort A-D, the contact channel and its basis (D20), the send date and the date a
 * letter came back. An objection is no column: it is a suppression of the Location.
 *
 * `suppressions` holds exactly one Location or one lower-case address per row. An opt-out is permanent: no code path
 * deletes a row, and a Location a row names cannot be deleted (RESTRICT).
 *
 * `venue_previews` (G5) loses the NOT NULL on `campaign_code`, because a link for an e-mail carries no campaign (PO
 * decision), and gains `short_code`, the ten characters a letter prints after `/v/` and its QR code encodes.
 */
final class Version20261108121142 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'G11: suppressions, outreach fields on crm_companies, short codes and optional campaign on venue_previews';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE crm_companies
                ADD campaign_code VARCHAR(40) DEFAULT NULL,
                ADD cohort VARCHAR(1) DEFAULT NULL,
                ADD contact_channel VARCHAR(20) DEFAULT NULL,
                ADD contact_basis VARCHAR(20) DEFAULT NULL,
                ADD outreach_sent_on DATE DEFAULT NULL,
                ADD mail_returned_on DATE DEFAULT NULL,
                ADD CONSTRAINT chk_crm_companies_campaign_code CHECK (campaign_code ~ '^[A-Za-z0-9][A-Za-z0-9_-]{0,39}$'),
                ADD CONSTRAINT chk_crm_companies_cohort CHECK (cohort IN ('A', 'B', 'C', 'D')),
                ADD CONSTRAINT chk_crm_companies_contact_channel CHECK (contact_channel IN ('postal', 'email', 'phone', 'referral')),
                ADD CONSTRAINT chk_crm_companies_contact_basis CHECK (contact_basis IN ('consent', 'customer', 'one_to_one', 'none'))
            SQL);
        $this->addSql('CREATE INDEX idx_crm_companies_campaign_code ON crm_companies (campaign_code)');
        $this->addSql('CREATE INDEX idx_crm_companies_cohort ON crm_companies (cohort)');

        $this->addSql(<<<'SQL'
            CREATE TABLE suppressions (
                id UUID NOT NULL,
                company_id UUID DEFAULT NULL,
                email VARCHAR(254) DEFAULT NULL,
                reason VARCHAR(20) NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                CONSTRAINT pk_suppressions PRIMARY KEY (id),
                CONSTRAINT fk_suppressions_company_id FOREIGN KEY (company_id) REFERENCES crm_companies (id) ON DELETE RESTRICT,
                CONSTRAINT uk_suppressions_company_id UNIQUE (company_id),
                CONSTRAINT uk_suppressions_email UNIQUE (email),
                CONSTRAINT chk_suppressions_reason CHECK (reason IN ('opt_out', 'objection')),
                CONSTRAINT chk_suppressions_target CHECK (num_nonnulls(company_id, email) = 1),
                CONSTRAINT chk_suppressions_email CHECK (email = lower(email) AND email LIKE '%_@_%')
            )
            SQL);

        $this->addSql('ALTER TABLE venue_previews ALTER campaign_code DROP NOT NULL');
        $this->addSql(<<<'SQL'
            ALTER TABLE venue_previews
                ADD short_code VARCHAR(10) DEFAULT NULL,
                ADD CONSTRAINT uk_venue_previews_short_code UNIQUE (short_code),
                ADD CONSTRAINT chk_venue_previews_short_code CHECK (short_code ~ '^[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{10}$')
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE venue_previews DROP short_code');
        // A link issued for an e-mail has no campaign; the old schema needs one.
        $this->addSql("UPDATE venue_previews SET campaign_code = 'email' WHERE campaign_code IS NULL");
        $this->addSql('ALTER TABLE venue_previews ALTER campaign_code SET NOT NULL');
        $this->addSql('DROP TABLE suppressions');
        $this->addSql('DROP INDEX idx_crm_companies_cohort');
        $this->addSql('DROP INDEX idx_crm_companies_campaign_code');
        $this->addSql(<<<'SQL'
            ALTER TABLE crm_companies
                DROP campaign_code,
                DROP cohort,
                DROP contact_channel,
                DROP contact_basis,
                DROP outreach_sent_on,
                DROP mail_returned_on
            SQL);
    }
}
