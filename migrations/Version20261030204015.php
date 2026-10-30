<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Romanian as a staff language (#32, D8): the owner's form names it for a few venues, and a
 * venue_terms row needs its taxonomy row. Appended at position 12 of the language group, so
 * no existing row moves.
 */
final class Version20261030204015 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'owner-content-import: language term ro (#32)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("INSERT INTO taxonomy_terms (term_group, code, position, primary_position) VALUES ('language', 'ro', 12, NULL)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM venue_terms WHERE term_group = 'language' AND term_code = 'ro'");
        $this->addSql("DELETE FROM taxonomy_terms WHERE term_group = 'language' AND code = 'ro'");
    }
}
