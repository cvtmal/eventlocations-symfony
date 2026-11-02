<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Portal submissions (#45, S16): the review state of a location a manager created. `venues.status` stays as it is:
 * the row says where the review stands, the venue says whether it is online.
 *
 * - One row per venue, so `venue_id` is the key, and it goes with the venue (CASCADE).
 * - `submitted_by` is SET NULL: the row outlives a deleted account, a draft has none yet.
 * - `reviewed_by` is RESTRICT: a staff account with decisions on record cannot be deleted.
 */
final class Version20261102020630 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Portal submissions (#45)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE portal_submissions (
                venue_id UUID NOT NULL,
                status VARCHAR(10) DEFAULT 'draft' NOT NULL,
                submitted_by UUID DEFAULT NULL,
                submitted_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                rights_confirmed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                reviewed_by UUID DEFAULT NULL,
                reviewed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                reject_reason VARCHAR(1000) DEFAULT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                CONSTRAINT pk_portal_submissions PRIMARY KEY (venue_id),
                CONSTRAINT fk_portal_submissions_venue_id FOREIGN KEY (venue_id) REFERENCES venues (id) ON DELETE CASCADE,
                CONSTRAINT fk_portal_submissions_submitted_by FOREIGN KEY (submitted_by) REFERENCES portal_users (id) ON DELETE SET NULL,
                CONSTRAINT fk_portal_submissions_reviewed_by FOREIGN KEY (reviewed_by) REFERENCES admin_users (id) ON DELETE RESTRICT,
                CONSTRAINT chk_portal_submissions_status CHECK (status IN ('draft', 'submitted', 'rejected', 'accepted'))
            )
            SQL);
        $this->addSql('CREATE INDEX idx_portal_submissions_status ON portal_submissions (status)');
        $this->addSql('CREATE INDEX idx_portal_submissions_submitted_by ON portal_submissions (submitted_by)');
        $this->addSql('CREATE INDEX idx_portal_submissions_reviewed_by ON portal_submissions (reviewed_by)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE portal_submissions');
    }
}
