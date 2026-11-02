<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `venues.awaits_first_publish` (Q20, POR-14): true from `CreateVenue` (the CMS's "Neue Location" and the portal's
 * wizard) until the venue's first publish, which clears it. That publish fires `NewVenuePublished`, on which the CRM
 * writes "Rechnung stellen". Every existing row, imported or seeded, takes the default false, so none of the 243 real
 * venues ever becomes eligible.
 */
final class Version20261102070544 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Venues: awaits_first_publish (Q20)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE venues ADD COLUMN awaits_first_publish BOOLEAN DEFAULT FALSE NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE venues DROP COLUMN awaits_first_publish');
    }
}
