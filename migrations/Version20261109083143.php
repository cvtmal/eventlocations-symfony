<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * G16 `organizer-feedback`: the guest's answer to the one feedback mail a week after a delivered inquiry.
 *
 * One row per inquiry, keyed and cascaded like `crm_inquiry_links`, so the inquiry retention (LEG-2) deletes it too.
 * A row exists once the job has handled the inquiry: `mailed_at` when the mail went out, or only `opted_out_at` when
 * the guest's address had opted out and nothing was sent (that row carries the opt-out on to the newer inquiry).
 * The two answers come together: `venue_replied` («Hat sich die Location gemeldet?») and `outcome` («Hat es
 * geklappt?»: `yes`, `no`, `open`), stamped by `answered_at` (the first answer; a change keeps it).
 */
final class Version20261109083143 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'G16 organizer-feedback: inquiry_feedback';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE inquiry_feedback (
                inquiry_id UUID NOT NULL,
                mailed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                opted_out_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                venue_replied BOOLEAN DEFAULT NULL,
                outcome VARCHAR(4) DEFAULT NULL,
                answered_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                CONSTRAINT pk_inquiry_feedback PRIMARY KEY (inquiry_id),
                CONSTRAINT fk_inquiry_feedback_inquiry_id FOREIGN KEY (inquiry_id) REFERENCES inquiries (id) ON DELETE CASCADE,
                CONSTRAINT chk_inquiry_feedback_outcome CHECK (outcome IN ('yes', 'no', 'open')),
                CONSTRAINT chk_inquiry_feedback_handled CHECK (mailed_at IS NOT NULL OR opted_out_at IS NOT NULL),
                CONSTRAINT chk_inquiry_feedback_answer CHECK (
                    (answered_at IS NULL AND venue_replied IS NULL AND outcome IS NULL)
                    OR (answered_at IS NOT NULL AND venue_replied IS NOT NULL AND outcome IS NOT NULL AND mailed_at IS NOT NULL)
                )
            )
            SQL);
        $this->addSql('CREATE INDEX idx_inquiry_feedback_answered_at ON inquiry_feedback (answered_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE inquiry_feedback');
    }
}
