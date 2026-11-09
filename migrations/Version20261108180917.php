<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * G7 `enquiry-quality` (D19, D56): what an inquiry is worth.
 *
 * `qualification` and `qualification_reason` are the derived D19 flag, written by `DeliverInquiryHandler` at each
 * delivery: `qualified` without a reason, or `unqualified` with exactly one reason; a row created after this migration
 * keeps both null until its first delivery attempt (existing rows are backfilled, see below). The pair CHECK is written so it never evaluates to NULL (a NULL CHECK passes).
 * `qualification_override` is the staff mark (`spam`, `test`), kept apart so an unmark restores the derived value. `stuck_alerted_at` marks an inquiry already reported to staff by the hourly alert.
 *
 * Existing rows are backfilled from their venue mail: `sent` is qualified, everything else unqualified with
 * `not_delivered` (D56).
 */
final class Version20261108180917 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'G7 enquiry-quality: inquiry qualification, staff override, stuck alert marker';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE inquiries
                ADD qualification VARCHAR(12) DEFAULT NULL,
                ADD qualification_reason VARCHAR(30) DEFAULT NULL,
                ADD qualification_override VARCHAR(10) DEFAULT NULL,
                ADD stuck_alerted_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL
            SQL);

        $this->addSql(<<<'SQL'
            UPDATE inquiries
               SET qualification = CASE WHEN venue_mail_status = 'sent' THEN 'qualified' ELSE 'unqualified' END,
                   qualification_reason = CASE WHEN venue_mail_status = 'sent' THEN NULL ELSE 'not_delivered' END
            SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE inquiries
                ADD CONSTRAINT chk_inquiries_qualification CHECK (qualification IN ('qualified', 'unqualified')),
                ADD CONSTRAINT chk_inquiries_qualification_reason CHECK (qualification_reason IN ('not_delivered', 'duplicate', 'event_type_not_offered', 'guests_outside_capacity', 'date_too_far')),
                ADD CONSTRAINT chk_inquiries_qualification_override CHECK (qualification_override IN ('spam', 'test')),
                ADD CONSTRAINT chk_inquiries_qualification_reason_pair CHECK (
                    (qualification IS NULL AND qualification_reason IS NULL)
                    OR (qualification IS NOT NULL AND (qualification = 'unqualified') = (qualification_reason IS NOT NULL))
                )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE inquiries
                DROP CONSTRAINT chk_inquiries_qualification_reason_pair,
                DROP CONSTRAINT chk_inquiries_qualification_override,
                DROP CONSTRAINT chk_inquiries_qualification_reason,
                DROP CONSTRAINT chk_inquiries_qualification
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE inquiries
                DROP stuck_alerted_at,
                DROP qualification_override,
                DROP qualification_reason,
                DROP qualification
            SQL);
    }
}
