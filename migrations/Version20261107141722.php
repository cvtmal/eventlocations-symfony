<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * G3 `site-import`: the private tables of the website importer. `source_records` is one import of an outside
 * source (today only a venue's own website) with the facts it yielded and a report of what was fetched and
 * refused; `photo_candidates` are references to pictures found on the way. Nothing public reads either table
 * (D16): no photo is shown, and no fact is copied into a venue, before a claimant confirms it (G6).
 *
 * - venue_id is nullable: an import can run for a website that has no venue yet. Deleting a venue deletes its
 *   records, and a record deletes its candidates.
 * - source_type, status, origin and candidate status are CHECK-constrained vocabularies; a test pins each list
 *   to its enum.
 * - A second import of the same URL turns the first record's status to `superseded` and keeps it; a partial unique
 *   index allows one `fetched` record per URL. facts is an object, or `[]` when a page yielded nothing (PHP encodes
 *   an empty array so).
 */
final class Version20261107141722 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'G3 site-import: source_records and photo_candidates, the private tables of the website importer';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE source_records (
                id UUID NOT NULL,
                venue_id UUID DEFAULT NULL,
                source_type VARCHAR(30) NOT NULL,
                url VARCHAR(2000) NOT NULL,
                status VARCHAR(20) DEFAULT 'fetched' NOT NULL,
                facts JSONB NOT NULL,
                report JSONB NOT NULL,
                fetched_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                CONSTRAINT pk_source_records PRIMARY KEY (id),
                CONSTRAINT fk_source_records_venue_id FOREIGN KEY (venue_id) REFERENCES venues (id) ON DELETE CASCADE,
                CONSTRAINT chk_source_records_source_type CHECK (source_type IN ('official_website')),
                CONSTRAINT chk_source_records_status CHECK (status IN ('fetched', 'superseded')),
                CONSTRAINT chk_source_records_facts CHECK (jsonb_typeof(facts) = 'object' OR facts = '[]'::jsonb),
                CONSTRAINT chk_source_records_report CHECK (jsonb_typeof(report) = 'object')
            )
            SQL);
        $this->addSql('CREATE INDEX idx_source_records_venue_id ON source_records (venue_id)');
        $this->addSql("CREATE UNIQUE INDEX uk_source_records_url_fetched ON source_records (url) WHERE status = 'fetched'");

        $this->addSql(<<<'SQL'
            CREATE TABLE photo_candidates (
                id UUID NOT NULL,
                source_record_id UUID NOT NULL,
                image_url VARCHAR(2000) NOT NULL,
                page_url VARCHAR(2000) NOT NULL,
                origin VARCHAR(20) NOT NULL,
                alt VARCHAR(500) DEFAULT NULL,
                context VARCHAR(200) DEFAULT NULL,
                width INTEGER DEFAULT NULL,
                height INTEGER DEFAULT NULL,
                bytes INTEGER DEFAULT NULL,
                position SMALLINT NOT NULL,
                status VARCHAR(20) DEFAULT 'candidate' NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                CONSTRAINT pk_photo_candidates PRIMARY KEY (id),
                CONSTRAINT uk_photo_candidates_source_record_id_image_url UNIQUE (source_record_id, image_url),
                CONSTRAINT fk_photo_candidates_source_record_id FOREIGN KEY (source_record_id) REFERENCES source_records (id) ON DELETE CASCADE,
                CONSTRAINT chk_photo_candidates_origin CHECK (origin IN ('img', 'srcset', 'picture', 'og_image', 'twitter_image', 'link_rel', 'json_ld', 'css_background')),
                CONSTRAINT chk_photo_candidates_status CHECK (status IN ('candidate', 'approved', 'rejected')),
                CONSTRAINT chk_photo_candidates_position CHECK (position > 0),
                CONSTRAINT chk_photo_candidates_size CHECK ((width IS NULL OR width > 0) AND (height IS NULL OR height > 0) AND (bytes IS NULL OR bytes >= 0))
            )
            SQL);
        $this->addSql('CREATE INDEX idx_photo_candidates_source_record_id ON photo_candidates (source_record_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE photo_candidates');
        $this->addSql('DROP TABLE source_records');
    }
}
