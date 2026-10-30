<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Content import (#9, S8): the folder key and content hash on venues, the room key on
 * rooms, and one content_imports row per import run.
 *
 * - venues.content_key is the content folder's name (immutable); NULL for demo venues.
 * - venues.content_hash is the SHA-256 of the format version, the normalised venue.yaml
 *   and the hashes of its files; equal hash means the import skips the venue.
 * - rooms.content_key is rooms[].key in venue.yaml, unique per venue.
 * - content_imports has no column defaults except the timestamps: the import writes every
 *   column. errors is a list of {key, messages[]} for the venues that failed.
 *
 * No COMMENT ON: Doctrine compares column comments, and the mapping carries none (this
 * docblock and the entities' docblocks are the documentation).
 */
final class Version20261030140037 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'venue-content-import: content keys and hash on venues/rooms, content_imports (#9)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE venues
                ADD COLUMN content_key VARCHAR(100) DEFAULT NULL,
                ADD COLUMN content_hash VARCHAR(64) DEFAULT NULL,
                ADD CONSTRAINT uk_venues_content_key UNIQUE (content_key),
                ADD CONSTRAINT chk_venues_content_key CHECK (content_key ~ '^[a-z0-9]+(-[a-z0-9]+)*$'),
                ADD CONSTRAINT chk_venues_content_hash CHECK (content_hash IS NULL OR (content_key IS NOT NULL AND content_hash ~ '^[0-9a-f]{64}$'))
            SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE rooms
                ADD COLUMN content_key VARCHAR(100) DEFAULT NULL,
                ADD CONSTRAINT uk_rooms_venue_id_content_key UNIQUE (venue_id, content_key),
                ADD CONSTRAINT chk_rooms_content_key CHECK (content_key ~ '^[a-z0-9]+(-[a-z0-9]+)*$')
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE content_imports (
                id UUID NOT NULL,
                source_path VARCHAR(500) NOT NULL,
                only_key VARCHAR(100) DEFAULT NULL,
                pruned BOOLEAN NOT NULL,
                forced BOOLEAN NOT NULL,
                venues_seen INTEGER NOT NULL,
                venues_created INTEGER NOT NULL,
                venues_updated INTEGER NOT NULL,
                venues_unchanged INTEGER NOT NULL,
                venues_failed INTEGER NOT NULL,
                venues_unpublished INTEGER NOT NULL,
                photos_added INTEGER NOT NULL,
                photos_removed INTEGER NOT NULL,
                documents_added INTEGER NOT NULL,
                documents_removed INTEGER NOT NULL,
                errors JSONB NOT NULL,
                started_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                finished_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                CONSTRAINT pk_content_imports PRIMARY KEY (id),
                CONSTRAINT chk_content_imports_counts CHECK (
                    venues_seen >= 0 AND venues_created >= 0 AND venues_updated >= 0
                    AND venues_unchanged >= 0 AND venues_failed >= 0 AND venues_unpublished >= 0
                    AND photos_added >= 0 AND photos_removed >= 0
                    AND documents_added >= 0 AND documents_removed >= 0
                ),
                CONSTRAINT chk_content_imports_finished_at CHECK (finished_at >= started_at),
                CONSTRAINT chk_content_imports_errors CHECK (jsonb_typeof(errors) = 'array'),
                CONSTRAINT chk_content_imports_only_key CHECK (only_key ~ '^[a-z0-9]+(-[a-z0-9]+)*$')
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE content_imports');
        $this->addSql(<<<'SQL'
            ALTER TABLE rooms
                DROP CONSTRAINT chk_rooms_content_key,
                DROP CONSTRAINT uk_rooms_venue_id_content_key,
                DROP COLUMN content_key
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE venues
                DROP CONSTRAINT chk_venues_content_hash,
                DROP CONSTRAINT chk_venues_content_key,
                DROP CONSTRAINT uk_venues_content_key,
                DROP COLUMN content_hash,
                DROP COLUMN content_key
            SQL);
    }
}
