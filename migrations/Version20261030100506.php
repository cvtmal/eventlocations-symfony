<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * PostgreSQL extensions the search and the distances build on.
 *
 * pg_trgm and unaccent serve the name and place autocomplete with typo and umlaut
 * tolerance (S1). cube and earthdistance serve "within N km of a town" without PostGIS;
 * earthdistance depends on cube, so it is created after it and dropped before it.
 */
final class Version20261030100506 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'PostgreSQL extensions for search and distances: pg_trgm, unaccent, cube, earthdistance.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        $this->addSql('CREATE EXTENSION IF NOT EXISTS unaccent');
        $this->addSql('CREATE EXTENSION IF NOT EXISTS cube');
        $this->addSql('CREATE EXTENSION IF NOT EXISTS earthdistance');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP EXTENSION IF EXISTS earthdistance');
        $this->addSql('DROP EXTENSION IF EXISTS cube');
        $this->addSql('DROP EXTENSION IF EXISTS unaccent');
        $this->addSql('DROP EXTENSION IF EXISTS pg_trgm');
    }
}
