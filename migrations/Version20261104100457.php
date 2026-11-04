<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Liechtenstein as the 27th region `li` (#46). Liechtenstein has no BFS canton number, so it gets the
 * pseudo number 27; `svg_id` is `ch-li` because chk_cantons_svg_id demands it, though the map has no such
 * path. The centroid is the mean of the 13 Liechtenstein towns in data/geo/towns.csv; the towns themselves
 * arrive through `app:geo:import-towns`.
 *
 * down() deletes the LI towns and the row. While a venue still points at `li` or at an LI town, the RESTRICT
 * foreign keys fail it and the transaction rolls back, like #21's down(); LI venues are never deleted here.
 */
final class Version20261104100457 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Liechtenstein as region li (#46)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cantons DROP CONSTRAINT chk_cantons_bfs_number');
        $this->addSql('ALTER TABLE cantons ADD CONSTRAINT chk_cantons_bfs_number CHECK (bfs_number BETWEEN 1 AND 27)');
        $this->addSql(<<<'SQL'
            INSERT INTO cantons (code, bfs_number, slug, svg_id, centroid_lat, centroid_lng) VALUES
                ('li', 27, 'liechtenstein', 'ch-li', 47.1783, 9.5312)
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM towns WHERE canton_code = 'li'");
        $this->addSql("DELETE FROM cantons WHERE code = 'li'");
        $this->addSql('ALTER TABLE cantons DROP CONSTRAINT chk_cantons_bfs_number');
        $this->addSql('ALTER TABLE cantons ADD CONSTRAINT chk_cantons_bfs_number CHECK (bfs_number BETWEEN 1 AND 26)');
    }
}
