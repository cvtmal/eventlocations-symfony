<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * CRM mail (#30, S13, CRM-10), additive, so the old code keeps working on it during a deploy's sync.
 *
 * - `crm_email_templates` holds what the settings screen edits. A sent mail copies its text, so a template can change
 *   or go without touching the timeline, and nothing references this table.
 * - `email_sent` takes position 7, after #29's `inquiry`. Its payload is `{to, cc?, status}` on `crm_activities`, so
 *   there is no `crm_emails` table.
 */
final class Version20261101190536 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '#30: crm_email_templates and the email_sent activity type';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE crm_email_templates (
                id UUID NOT NULL,
                name VARCHAR(100) NOT NULL,
                subject VARCHAR(200) NOT NULL,
                body_markdown TEXT NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                CONSTRAINT pk_crm_email_templates PRIMARY KEY (id),
                CONSTRAINT uk_crm_email_templates_name UNIQUE (name),
                CONSTRAINT chk_crm_email_templates_content CHECK (btrim(name) <> '' AND btrim(subject) <> '' AND btrim(body_markdown) <> '')
            )
            SQL);

        $this->addSql("INSERT INTO crm_activity_types (code, position) VALUES ('email_sent', 7)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM crm_activities WHERE type_code = 'email_sent'");
        $this->addSql("DELETE FROM crm_activity_types WHERE code = 'email_sent'");
        $this->addSql('DROP TABLE crm_email_templates');
    }
}
