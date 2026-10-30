<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Venues without an address (#21, Q12): the owner's CSV names a region, never a street, ZIP,
 * town or coordinates, so these become optional and nothing is invented for them.
 *
 * - The existing chk_venues_zip, chk_venues_lat and chk_venues_lng pass on NULL and stay.
 * - chk_venues_town_canton is as far as a CHECK can go; that the canton equals the town's
 *   stays with the entity. Coordinates are not tied to a town: radius search reads only
 *   lat and lng.
 * - idx_venues_earth is on a strict function, so a NULL row enters it and radius queries
 *   skip it.
 * - venue_translations.region is the verbatim place of a venue without a canton. It is a
 *   translation row because such a label can be a word rather than a proper noun.
 *
 * down() restores NOT NULL and fails while venues without a location exist. It never
 * deletes them to get there.
 */
final class Version20261030183112 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'legacy-data-import: optional venue address, town, canton and coordinates, translated region text (#21)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE venues
                ALTER COLUMN zip DROP NOT NULL,
                ALTER COLUMN town_id DROP NOT NULL,
                ALTER COLUMN canton_code DROP NOT NULL,
                ALTER COLUMN lat DROP NOT NULL,
                ALTER COLUMN lng DROP NOT NULL,
                ADD CONSTRAINT chk_venues_address CHECK ((town_id IS NULL) = (zip IS NULL) AND (street IS NULL OR town_id IS NOT NULL)),
                ADD CONSTRAINT chk_venues_coordinates CHECK ((lat IS NULL) = (lng IS NULL)),
                ADD CONSTRAINT chk_venues_town_canton CHECK (town_id IS NULL OR canton_code IS NOT NULL)
            SQL);

        $this->addSql('ALTER TABLE venue_translations ADD COLUMN region VARCHAR(60) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE venue_translations DROP COLUMN region');

        $this->addSql(<<<'SQL'
            ALTER TABLE venues
                DROP CONSTRAINT chk_venues_town_canton,
                DROP CONSTRAINT chk_venues_coordinates,
                DROP CONSTRAINT chk_venues_address,
                ALTER COLUMN lng SET NOT NULL,
                ALTER COLUMN lat SET NOT NULL,
                ALTER COLUMN canton_code SET NOT NULL,
                ALTER COLUMN town_id SET NOT NULL,
                ALTER COLUMN zip SET NOT NULL
            SQL);
    }
}
