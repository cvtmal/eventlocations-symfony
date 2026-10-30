<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Inquiries (#14, IQ-1). The row outlives the venue (ON DELETE SET NULL, CMS-3), so it keeps a
 * snapshot: number, name, slug and the venue's address at submit time (`recipient_email`).
 * `delivered_to` is the real envelope recipient, which differs from it under
 * INQUIRY_RECIPIENT_OVERRIDE. The typed columns are the full payload; #29 reads them and never
 * alters the table. No IP address or user agent is stored. `app:inquiries:purge` deletes by
 * `created_at` (LEG-2). `fingerprint` is a keyed hash that recognises a repeat of one inquiry
 * within minutes (`InquiryFingerprint`); no constraint, because the window is the rule and a guest
 * may send the same text again later.
 */
final class Version20261030152935 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'inquiry-form: table inquiries (#14)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE inquiries (
                id UUID NOT NULL,
                venue_id UUID DEFAULT NULL,
                venue_number INTEGER NOT NULL,
                venue_name VARCHAR(150) NOT NULL,
                venue_slug VARCHAR(160) NOT NULL,
                recipient_email VARCHAR(254) NOT NULL,
                delivered_to VARCHAR(254) DEFAULT NULL,
                locale VARCHAR(2) NOT NULL,
                occasion VARCHAR(30) NOT NULL,
                guest_count INTEGER NOT NULL,
                event_date DATE DEFAULT NULL,
                message TEXT NOT NULL,
                first_name VARCHAR(100) NOT NULL,
                last_name VARCHAR(100) NOT NULL,
                email VARCHAR(254) NOT NULL,
                phone VARCHAR(40) DEFAULT NULL,
                fingerprint VARCHAR(64) NOT NULL,
                venue_mail_status VARCHAR(10) DEFAULT 'pending' NOT NULL,
                guest_mail_status VARCHAR(10) DEFAULT 'pending' NOT NULL,
                venue_mail_sent_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                CONSTRAINT pk_inquiries PRIMARY KEY (id),
                CONSTRAINT fk_inquiries_venue_id FOREIGN KEY (venue_id) REFERENCES venues (id) ON DELETE SET NULL,
                CONSTRAINT chk_inquiries_locale CHECK (locale ~ '^[a-z]{2}$'),
                CONSTRAINT chk_inquiries_occasion CHECK (occasion IN ('wedding', 'civil_wedding', 'wedding_aperitif', 'engagement', 'other')),
                CONSTRAINT chk_inquiries_guest_count CHECK (guest_count BETWEEN 1 AND 5000),
                CONSTRAINT chk_inquiries_message CHECK (char_length(btrim(message)) BETWEEN 1 AND 5000),
                CONSTRAINT chk_inquiries_email CHECK (email ~ '^[^@\s]+@[^@\s]+$'),
                CONSTRAINT chk_inquiries_fingerprint CHECK (fingerprint ~ '^[0-9a-f]{64}$'),
                CONSTRAINT chk_inquiries_venue_mail_status CHECK (venue_mail_status IN ('pending', 'sent', 'failed', 'skipped')),
                CONSTRAINT chk_inquiries_guest_mail_status CHECK (guest_mail_status IN ('pending', 'sent', 'failed', 'skipped'))
            )
            SQL);
        $this->addSql('CREATE INDEX idx_inquiries_venue_id ON inquiries (venue_id)');
        $this->addSql('CREATE INDEX idx_inquiries_created_at ON inquiries (created_at)');
        $this->addSql('CREATE INDEX idx_inquiries_fingerprint_created_at ON inquiries (fingerprint, created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE inquiries');
    }
}
