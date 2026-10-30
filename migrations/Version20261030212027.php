<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The CMS writes venues too (#24, S8), so app:venues:import needs to know which venues staff
 * changed and must not overwrite them silently.
 *
 * - venues.cms_edited_at: set = edited in the CMS since the last import that applied to the
 *   venue. A CMS save sets it, an import that applies clears it. The import skips a venue with
 *   it set unless told to overwrite.
 * - venues.coordinates_from_town: the coordinates are the town centre ("Ortsmitte
 *   übernehmen"), not the venue's own point, so no map pin or geo data may claim them (#15,
 *   #16). Only with coordinates.
 * - content_imports.overwrote_cms_edits / venues_cms_edited: whether the run overwrote CMS
 *   edits, and how many venues it kept because of them. Like the other columns of the table,
 *   no default: the import writes every value.
 * - venue_deleted_content_keys: the content key of a venue deleted in the CMS, so its folder
 *   does not bring it back. created_at is the deletion time.
 */
final class Version20261030212027 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'cms-venues: CMS edit marker, town-centre coordinates flag, import guard columns, deleted content keys (#24)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE venues
                ADD COLUMN cms_edited_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                ADD COLUMN coordinates_from_town BOOLEAN DEFAULT FALSE NOT NULL,
                ADD CONSTRAINT chk_venues_coordinates_from_town CHECK (NOT coordinates_from_town OR lat IS NOT NULL)
            SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE content_imports
                ADD COLUMN overwrote_cms_edits BOOLEAN DEFAULT FALSE NOT NULL,
                ADD COLUMN venues_cms_edited INTEGER DEFAULT 0 NOT NULL,
                ADD CONSTRAINT chk_content_imports_venues_cms_edited CHECK (venues_cms_edited >= 0)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE content_imports
                ALTER COLUMN overwrote_cms_edits DROP DEFAULT,
                ALTER COLUMN venues_cms_edited DROP DEFAULT
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE venue_deleted_content_keys (
                id UUID NOT NULL,
                content_key VARCHAR(100) NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                CONSTRAINT pk_venue_deleted_content_keys PRIMARY KEY (id),
                CONSTRAINT uk_venue_deleted_content_keys_content_key UNIQUE (content_key),
                CONSTRAINT chk_venue_deleted_content_keys_content_key CHECK (content_key ~ '^[a-z0-9]+(-[a-z0-9]+)*$')
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE venue_deleted_content_keys');

        $this->addSql(<<<'SQL'
            ALTER TABLE content_imports
                DROP CONSTRAINT chk_content_imports_venues_cms_edited,
                DROP COLUMN venues_cms_edited,
                DROP COLUMN overwrote_cms_edits
            SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE venues
                DROP CONSTRAINT chk_venues_coordinates_from_town,
                DROP COLUMN coordinates_from_town,
                DROP COLUMN cms_edited_at
            SQL);
    }
}
