<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * G1 `rights-grants`: a photo is public only with a live grant (Venues\PublicPhotoPolicy).
 *
 * - `media_rights_grants`: one row per grant. It outlives its photo, not its venue
 *   (`venue_photo_id` goes NULL with the photo and `original_path` names the file, but `venue_id`
 *   cascades: deleting a venue deletes its grants), and a revoked grant stays as evidence
 *   (`revoked_at`, `revoked_by_*`) for as long as the venue exists. The partial unique index
 *   allows one live grant per photo. The grantor is loose on purpose: a type plus a string, no
 *   key into Admin or Portal.
 * - `rights_policy_versions`: the text a version stands for. The version of the declaration text
 *   is content-addressed, so a staff edit of the block makes a new row; `legacy` covers the photos
 *   that existed before this migration.
 * - Every existing photo gets a `system` / `staff_attestation` grant, so nothing goes offline.
 */
final class Version20261107140523 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'G1 rights-grants: media_rights_grants and rights_policy_versions, a legacy grant for every existing photo';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE rights_policy_versions (
                version VARCHAR(40) NOT NULL,
                body_markdown TEXT NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                CONSTRAINT pk_rights_policy_versions PRIMARY KEY (version),
                CONSTRAINT chk_rights_policy_versions_version CHECK (version ~ '^[a-z0-9]{3,40}$')
            )
            SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO rights_policy_versions (version, body_markdown)
            VALUES ('legacy', 'Fotos, die vor der Bildrechte-Verwaltung online waren: Nachweis ist der Import oder Upload durch den Betreiber der Plattform.')
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE media_rights_grants (
                id UUID NOT NULL,
                venue_id UUID NOT NULL,
                venue_photo_id UUID DEFAULT NULL,
                original_path VARCHAR(500) NOT NULL,
                grantor_type VARCHAR(10) NOT NULL,
                grantor_ref VARCHAR(64) NOT NULL,
                evidence VARCHAR(20) NOT NULL,
                policy_version VARCHAR(40) NOT NULL,
                scope_listing BOOLEAN DEFAULT true NOT NULL,
                scope_resized_copies BOOLEAN DEFAULT true NOT NULL,
                scope_derivatives BOOLEAN DEFAULT false NOT NULL,
                revoked_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                revoked_by_type VARCHAR(10) DEFAULT NULL,
                revoked_by_ref VARCHAR(64) DEFAULT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                CONSTRAINT pk_media_rights_grants PRIMARY KEY (id),
                CONSTRAINT fk_media_rights_grants_venue_id FOREIGN KEY (venue_id) REFERENCES venues (id) ON DELETE CASCADE,
                CONSTRAINT fk_media_rights_grants_venue_photo_id FOREIGN KEY (venue_photo_id) REFERENCES venue_photos (id) ON DELETE SET NULL,
                CONSTRAINT fk_media_rights_grants_policy_version FOREIGN KEY (policy_version) REFERENCES rights_policy_versions (version) ON DELETE RESTRICT,
                CONSTRAINT chk_media_rights_grants_grantor_type CHECK (grantor_type IN ('manager', 'staff', 'system')),
                CONSTRAINT chk_media_rights_grants_evidence CHECK (evidence IN ('declaration', 'upload', 'staff_attestation')),
                CONSTRAINT chk_media_rights_grants_revoked_by_type CHECK (revoked_by_type IS NULL OR revoked_by_type IN ('manager', 'staff', 'system')),
                CONSTRAINT chk_media_rights_grants_revocation CHECK (
                    (revoked_at IS NULL) = (revoked_by_type IS NULL) AND (revoked_at IS NULL) = (revoked_by_ref IS NULL)
                )
            )
            SQL);
        $this->addSql('CREATE INDEX idx_media_rights_grants_venue_id ON media_rights_grants (venue_id)');
        $this->addSql('CREATE INDEX idx_media_rights_grants_venue_photo_id ON media_rights_grants (venue_photo_id)');
        $this->addSql('CREATE INDEX idx_media_rights_grants_policy_version ON media_rights_grants (policy_version)');
        $this->addSql('CREATE UNIQUE INDEX uk_media_rights_grants_live_photo ON media_rights_grants (venue_photo_id) WHERE revoked_at IS NULL');

        $this->addSql(<<<'SQL'
            INSERT INTO media_rights_grants (id, venue_id, venue_photo_id, original_path, grantor_type, grantor_ref, evidence, policy_version)
            SELECT uuidv7(), p.venue_id, p.id, p.original_path, 'system', 'migration', 'staff_attestation', 'legacy'
            FROM venue_photos p
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE media_rights_grants');
        $this->addSql('DROP TABLE rights_policy_versions');
    }
}
