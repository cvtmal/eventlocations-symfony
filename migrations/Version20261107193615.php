<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * G2 `takedown-form`: the complaints of the public form (`/inhalt-melden`) and the photos they name.
 *
 * - `moderation_cases`: one row per complaint, kept with its decision (staff, time, note) as evidence. `venue_id`
 *   goes NULL when the venue is deleted (a complaint outlives its venue; the footer form may name no known venue
 *   at all, then `venue_reference` holds what the complainant typed). `decided_by` is the admin user's id as a
 *   string, like G1's `grantor_ref`: no key into Admin. No IP or user agent is stored.
 * - `moderation_case_photos`: the photos a rights complaint names. An open case naming a photo is the hold:
 *   `Venues\PublicPhotoPolicy` hides the photo while such a case is open, and closing the case lifts it, so
 *   there is no flag to keep in step. The link outlives its photo (`venue_photo_id` SET NULL, `original_path`
 *   names the file, like `media_rights_grants`). Its key is an own id: `original_path` is not unique within a
 *   case (one file may sit in the gallery and in a room), and the photo id goes NULL once staff remove the
 *   photo; `uk_moderation_case_photos_case_photo` keeps one row per live photo and case (NULLs are distinct).
 * - `source_url` is a same-site path ("/" alone or "/x...", never "//host" or a backslash), the page the form
 *   was opened from.
 */
final class Version20261107193615 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'G2 takedown-form: moderation_cases and moderation_case_photos (the photo hold)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE moderation_cases (
                id UUID NOT NULL,
                venue_id UUID DEFAULT NULL,
                venue_reference VARCHAR(500) DEFAULT NULL,
                kind VARCHAR(12) NOT NULL,
                status VARCHAR(10) NOT NULL,
                complainant_name VARCHAR(100) NOT NULL,
                complainant_email VARCHAR(254) NOT NULL,
                message TEXT NOT NULL,
                good_faith_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                source_url VARCHAR(500) DEFAULT NULL,
                decision VARCHAR(10) DEFAULT NULL,
                decision_note TEXT DEFAULT NULL,
                decided_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                decided_by VARCHAR(64) DEFAULT NULL,
                took_entry_offline BOOLEAN DEFAULT false NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                CONSTRAINT pk_moderation_cases PRIMARY KEY (id),
                CONSTRAINT fk_moderation_cases_venue_id FOREIGN KEY (venue_id) REFERENCES venues (id) ON DELETE SET NULL,
                CONSTRAINT chk_moderation_cases_kind CHECK (kind IN ('rights', 'removal', 'correction')),
                CONSTRAINT chk_moderation_cases_status CHECK (status IN ('open', 'decided')),
                CONSTRAINT chk_moderation_cases_decision CHECK (decision IS NULL OR decision IN ('remove', 'keep')),
                CONSTRAINT chk_moderation_cases_decided CHECK (
                    (status = 'decided') = (decision IS NOT NULL)
                    AND (decision IS NULL) = (decided_at IS NULL)
                    AND (decision IS NULL) = (decided_by IS NULL)
                    AND (decision IS NOT NULL OR decision_note IS NULL)
                ),
                CONSTRAINT chk_moderation_cases_venue_reference CHECK (venue_reference IS NULL OR venue_id IS NULL),
                CONSTRAINT chk_moderation_cases_email CHECK (complainant_email ~ '^[^@\s]+@[^@\s]+$'),
                CONSTRAINT chk_moderation_cases_source_url CHECK (source_url IS NULL OR source_url ~ '^/([^/\\\s]|$)')
            )
            SQL);
        $this->addSql('CREATE INDEX idx_moderation_cases_venue_id ON moderation_cases (venue_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE moderation_case_photos (
                id UUID NOT NULL,
                case_id UUID NOT NULL,
                venue_photo_id UUID DEFAULT NULL,
                original_path VARCHAR(500) NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                CONSTRAINT pk_moderation_case_photos PRIMARY KEY (id),
                CONSTRAINT fk_moderation_case_photos_case_id FOREIGN KEY (case_id) REFERENCES moderation_cases (id) ON DELETE CASCADE,
                CONSTRAINT fk_moderation_case_photos_venue_photo_id FOREIGN KEY (venue_photo_id) REFERENCES venue_photos (id) ON DELETE SET NULL
            )
            SQL);
        $this->addSql('CREATE INDEX idx_moderation_case_photos_case_id ON moderation_case_photos (case_id)');
        $this->addSql('CREATE INDEX idx_moderation_case_photos_venue_photo_id ON moderation_case_photos (venue_photo_id)');
        $this->addSql('CREATE UNIQUE INDEX uk_moderation_case_photos_case_photo ON moderation_case_photos (case_id, venue_photo_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE moderation_case_photos');
        $this->addSql('DROP TABLE moderation_cases');
    }
}
