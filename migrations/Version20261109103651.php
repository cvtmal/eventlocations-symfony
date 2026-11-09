<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * F13 `multi-inquiry` (reverse match, D17): one brief to two to five venues.
 *
 * `inquiry_briefs` is the parent request. It holds no personal data: the guest's details stay on each child, an
 * ordinary `inquiries` row per venue with its own id and mail status, so qualification, alerts, feedback, the CRM
 * and the statistics read a child like any inquiry. The parent carries the one confirmation to the guest. A child
 * goes with its parent (`ON DELETE CASCADE`), and the retention deletes both by age (LEG-2).
 *
 * `venues.accepts_multi_inquiries` is the venue's opt-out of briefs; single inquiries reach it either way.
 */
final class Version20261109103651 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'F13 multi-inquiry: inquiry_briefs, inquiries.brief_id, venues.accepts_multi_inquiries';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE inquiry_briefs (
                id UUID NOT NULL,
                venue_count SMALLINT NOT NULL,
                fingerprint VARCHAR(64) NOT NULL,
                guest_mail_status VARCHAR(10) DEFAULT 'pending' NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                CONSTRAINT pk_inquiry_briefs PRIMARY KEY (id),
                CONSTRAINT chk_inquiry_briefs_venue_count CHECK (venue_count BETWEEN 2 AND 5),
                CONSTRAINT chk_inquiry_briefs_guest_mail_status CHECK (guest_mail_status IN ('pending', 'sent', 'failed', 'skipped'))
            )
            SQL);
        $this->addSql('CREATE INDEX idx_inquiry_briefs_fingerprint_created_at ON inquiry_briefs (fingerprint, created_at)');
        $this->addSql('CREATE INDEX idx_inquiry_briefs_created_at ON inquiry_briefs (created_at)');
        $this->addSql('ALTER TABLE inquiries ADD brief_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE inquiries ADD CONSTRAINT fk_inquiries_brief_id FOREIGN KEY (brief_id) REFERENCES inquiry_briefs (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX idx_inquiries_brief_id ON inquiries (brief_id)');
        $this->addSql('ALTER TABLE venues ADD accepts_multi_inquiries BOOLEAN DEFAULT true NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE venues DROP accepts_multi_inquiries');
        $this->addSql('DROP INDEX idx_inquiries_brief_id');
        $this->addSql('ALTER TABLE inquiries DROP CONSTRAINT fk_inquiries_brief_id');
        $this->addSql('ALTER TABLE inquiries DROP brief_id');
        $this->addSql('DROP TABLE inquiry_briefs');
    }
}
