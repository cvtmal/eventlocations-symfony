<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * G10 `owner-report`. `portal_owner_reports`: one row per venue and month whose monthly report mail went out
 * (`report_month` is the month's first day, Europe/Zurich), written after the send, so a second run of
 * `app:portal:owner-reports` sends nothing; rows go after 24 months (`OwnerReport::RETENTION_MONTHS`).
 * `portal_report_opt_outs`: a venue whose report was turned off by the one-click link in the mail; its
 * unique key on `venue_id` doubles as the FK index (Doctrine drops a separate one as redundant). Neither table
 * stores a person: no address, no name, no IP.
 */
final class Version20261109151057 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'G10 owner-report: send log and opt-out of the monthly report';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE portal_owner_reports (
                id UUID NOT NULL,
                venue_id UUID NOT NULL,
                report_month DATE NOT NULL,
                sent_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                CONSTRAINT pk_portal_owner_reports PRIMARY KEY (id),
                CONSTRAINT fk_portal_owner_reports_venue_id FOREIGN KEY (venue_id) REFERENCES venues (id) ON DELETE CASCADE,
                CONSTRAINT uk_portal_owner_reports_venue_id_report_month UNIQUE (venue_id, report_month),
                CONSTRAINT chk_portal_owner_reports_report_month CHECK (EXTRACT(DAY FROM report_month) = 1)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_portal_owner_reports_venue_id ON portal_owner_reports (venue_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE portal_report_opt_outs (
                id UUID NOT NULL,
                venue_id UUID NOT NULL,
                opted_out_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                CONSTRAINT pk_portal_report_opt_outs PRIMARY KEY (id),
                CONSTRAINT fk_portal_report_opt_outs_venue_id FOREIGN KEY (venue_id) REFERENCES venues (id) ON DELETE CASCADE,
                CONSTRAINT uk_portal_report_opt_outs_venue_id UNIQUE (venue_id)
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE portal_report_opt_outs');
        $this->addSql('DROP TABLE portal_owner_reports');
    }
}
