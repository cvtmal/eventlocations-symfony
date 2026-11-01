<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Inquiries in the CRM (#29, S13, CRM-9, CRM-11), additive, so the old code keeps working on it during a deploy's sync.
 *
 * - `inquiry` takes position 6 (#28's `deal_stage_changed` 5, #30's `email_sent` 7).
 * - `crm_inquiry_links` holds one row per ingested inquiry. Only `inquiry_id` cascades, so the link goes with the
 *   inquiry (`app:inquiries:purge` is bulk DQL, so the database does this). `contact_id` and `activity_id` are SET NULL:
 *   the row outlives an erased person, because "no link row" means "never ingested" to `app:crm:backfill-inquiries`,
 *   which must not bring that person back.
 * - The mail outcome is not copied here: the inbox reads it live from `inquiries`, which #29 never alters.
 */
final class Version20261101115604 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '#29: the inquiry activity type and crm_inquiry_links';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("INSERT INTO crm_activity_types (code, position) VALUES ('inquiry', 6)");

        $this->addSql(<<<'SQL'
            CREATE TABLE crm_inquiry_links (
                inquiry_id UUID NOT NULL,
                contact_id UUID DEFAULT NULL,
                activity_id UUID DEFAULT NULL,
                status VARCHAR(10) DEFAULT 'new' NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                CONSTRAINT pk_crm_inquiry_links PRIMARY KEY (inquiry_id),
                CONSTRAINT fk_crm_inquiry_links_inquiry_id FOREIGN KEY (inquiry_id) REFERENCES inquiries (id) ON DELETE CASCADE,
                CONSTRAINT fk_crm_inquiry_links_contact_id FOREIGN KEY (contact_id) REFERENCES crm_contacts (id) ON DELETE SET NULL,
                CONSTRAINT fk_crm_inquiry_links_activity_id FOREIGN KEY (activity_id) REFERENCES crm_activities (id) ON DELETE SET NULL,
                CONSTRAINT chk_crm_inquiry_links_status CHECK (status IN ('new', 'seen', 'done'))
            )
            SQL);
        $this->addSql('CREATE INDEX idx_crm_inquiry_links_status ON crm_inquiry_links (status)');
        $this->addSql('CREATE INDEX idx_crm_inquiry_links_contact_id ON crm_inquiry_links (contact_id)');
        $this->addSql('CREATE INDEX idx_crm_inquiry_links_activity_id ON crm_inquiry_links (activity_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM crm_activities WHERE type_code = 'inquiry'");
        $this->addSql('DROP TABLE crm_inquiry_links');
        $this->addSql("DELETE FROM crm_activity_types WHERE code = 'inquiry'");
    }
}
