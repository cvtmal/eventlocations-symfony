<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Search indexes on venues (#6): the two predicates of the listing that no existing index
 * serves.
 *
 * - idx_venues_earth (GiST on ll_to_earth) serves the radius filter. The planner uses it only
 *   when the centre is a joined MATERIALIZED CTE; with the centre as a scalar sub-select
 *   it applies the box as a filter after a primary-key lookup.
 * - idx_venues_search_key (GIN trigram on geo_search_key(name)) serves the name search,
 *   which folds the query through the same functions as the Geo autocomplete.
 *
 * Both are expression indexes, invisible to DBAL's schema introspection, so they are not
 * mapped on App\Venues\Entity\Venue (like idx_towns_earth / idx_towns_search_key).
 *
 * There is deliberately no btree on capacity, room count, area or height: those predicates
 * are not selective, and at the expected ~760 venues the planner seq-scans venues anyway
 * (EXPLAIN on DemoStory ×10, research E-P1). Term filters already use
 * idx_venue_terms_term_group_term_code and pk_venue_terms.
 */
final class Version20261030115117 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Search indexes on venues: GiST earth, GIN trigram name';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_venues_earth ON venues USING gist (ll_to_earth(lat, lng))');
        $this->addSql('CREATE INDEX idx_venues_search_key ON venues USING gin (geo_search_key(name) gin_trgm_ops)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_venues_search_key');
        $this->addSql('DROP INDEX idx_venues_earth');
    }
}
