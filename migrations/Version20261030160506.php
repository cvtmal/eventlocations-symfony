<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Owner wedding facts (#31, S14, DATA-4): seven nullable columns on venues, four translated
 * texts, six new taxonomy groups with 56 rows. Everything is additive; no existing row
 * changes.
 *
 * - chk_venues_curfew_at needs `has_curfew IS TRUE`: the plain `curfew_at IS NULL OR
 *   has_curfew` is NULL for a time with has_curfew NULL, and PostgreSQL passes a NULL CHECK.
 * - The term_group list keeps the case order of App\Venues\ValueObject\TermGroup
 *   (VocabularyChecksTest compares it in order).
 * - venue_terms has no group CHECK, so it does not change here.
 * - No index: #33 decides after EXPLAIN.
 */
final class Version20261030160506 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'venue-wedding-facts: owner facts on venues and venue_translations, six taxonomy groups (#31)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE venues
                ADD COLUMN price_level VARCHAR(20) DEFAULT NULL,
                ADD COLUMN capacity_seated INTEGER DEFAULT NULL,
                ADD COLUMN capacity_standing INTEGER DEFAULT NULL,
                ADD COLUMN party VARCHAR(20) DEFAULT NULL,
                ADD COLUMN has_curfew BOOLEAN DEFAULT NULL,
                ADD COLUMN curfew_at TIME(0) WITHOUT TIME ZONE DEFAULT NULL,
                ADD COLUMN instagram VARCHAR(500) DEFAULT NULL,
                ADD CONSTRAINT chk_venues_price_level CHECK (price_level IN ('moderate', 'medium', 'exclusive')),
                ADD CONSTRAINT chk_venues_capacity_seated CHECK (capacity_seated BETWEEN 1 AND 50000),
                ADD CONSTRAINT chk_venues_capacity_standing CHECK (capacity_standing BETWEEN 1 AND 50000),
                ADD CONSTRAINT chk_venues_party CHECK (party IN ('yes', 'limited', 'no')),
                ADD CONSTRAINT chk_venues_curfew_at CHECK (curfew_at IS NULL OR has_curfew IS TRUE),
                ADD CONSTRAINT chk_venues_instagram CHECK (instagram ~ '^https://')
            SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE venue_translations
                ADD COLUMN ambience VARCHAR(60) DEFAULT NULL,
                ADD COLUMN capacity_note VARCHAR(250) DEFAULT NULL,
                ADD COLUMN party_note VARCHAR(250) DEFAULT NULL,
                ADD COLUMN opening_hours VARCHAR(250) DEFAULT NULL
            SQL);

        $this->addSql('ALTER TABLE taxonomy_terms DROP CONSTRAINT chk_taxonomy_terms_term_group');
        $this->addSql(<<<'SQL'
            ALTER TABLE taxonomy_terms
                ADD CONSTRAINT chk_taxonomy_terms_term_group CHECK (term_group IN ('setting', 'building', 'suitable_for', 'infrastructure', 'furniture', 'technology', 'surroundings', 'ceremony', 'amenity', 'dining', 'vendor', 'usage', 'language'))
            SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO taxonomy_terms (term_group, code, position, primary_position) VALUES
                ('ceremony', 'civil_ceremony', 1, NULL),
                ('ceremony', 'free_ceremony', 2, NULL),
                ('ceremony', 'church_nearby', 3, NULL),
                ('ceremony', 'rain_alternative', 4, NULL),
                ('ceremony', 'registry_office', 5, NULL),
                ('ceremony', 'chapel', 6, NULL),
                ('amenity', 'overnight', 1, NULL),
                ('amenity', 'panorama', 2, NULL),
                ('amenity', 'lake_view', 3, NULL),
                ('amenity', 'dance_floor', 4, NULL),
                ('amenity', 'kids_room', 5, NULL),
                ('amenity', 'outdoor_dining', 6, NULL),
                ('amenity', 'parking', 7, NULL),
                ('amenity', 'parking_area', 8, NULL),
                ('amenity', 'own_kitchen', 9, NULL),
                ('amenity', 'catering_available', 10, NULL),
                ('amenity', 'external_caterers', 11, NULL),
                ('amenity', 'corkage', 12, NULL),
                ('amenity', 'wheelchair_access', 13, NULL),
                ('amenity', 'public_transport', 14, NULL),
                ('amenity', 'tent', 15, NULL),
                ('dining', 'vegetarian', 1, NULL),
                ('dining', 'vegan', 2, NULL),
                ('dining', 'gluten_free', 3, NULL),
                ('dining', 'organic', 4, NULL),
                ('dining', 'allergy_friendly', 5, NULL),
                ('dining', 'diabetic', 6, NULL),
                ('dining', 'halal', 7, NULL),
                ('dining', 'kosher', 8, NULL),
                ('dining', 'kids_menu', 9, NULL),
                ('dining', 'wedding_cake', 10, NULL),
                ('dining', 'buffet', 11, NULL),
                ('dining', 'grill', 12, NULL),
                ('dining', 'anything_possible', 13, NULL),
                ('vendor', 'flowers_decor', 1, NULL),
                ('vendor', 'photography', 2, NULL),
                ('vendor', 'videography', 3, NULL),
                ('vendor', 'officiant', 4, NULL),
                ('vendor', 'dj', 5, NULL),
                ('vendor', 'musicians', 6, NULL),
                ('vendor', 'furniture', 7, NULL),
                ('vendor', 'wedding_planner', 8, NULL),
                ('usage', 'exclusive', 1, NULL),
                ('usage', 'multiple_weddings', 2, NULL),
                ('usage', 'wedding_and_regular', 3, NULL),
                ('language', 'de', 1, NULL),
                ('language', 'en', 2, NULL),
                ('language', 'fr', 3, NULL),
                ('language', 'it', 4, NULL),
                ('language', 'es', 5, NULL),
                ('language', 'pt', 6, NULL),
                ('language', 'nl', 7, NULL),
                ('language', 'cs', 8, NULL),
                ('language', 'ru', 9, NULL),
                ('language', 'hu', 10, NULL),
                ('language', 'gsw', 11, NULL)
            SQL);
    }

    public function down(Schema $schema): void
    {
        // fk_venue_terms_term is ON DELETE RESTRICT, so the venues' terms go first.
        $this->addSql("DELETE FROM venue_terms WHERE term_group IN ('ceremony', 'amenity', 'dining', 'vendor', 'usage', 'language')");
        $this->addSql("DELETE FROM taxonomy_terms WHERE term_group IN ('ceremony', 'amenity', 'dining', 'vendor', 'usage', 'language')");

        $this->addSql('ALTER TABLE taxonomy_terms DROP CONSTRAINT chk_taxonomy_terms_term_group');
        $this->addSql(<<<'SQL'
            ALTER TABLE taxonomy_terms
                ADD CONSTRAINT chk_taxonomy_terms_term_group CHECK (term_group IN ('setting', 'building', 'suitable_for', 'infrastructure', 'furniture', 'technology', 'surroundings'))
            SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE venue_translations
                DROP COLUMN opening_hours,
                DROP COLUMN party_note,
                DROP COLUMN capacity_note,
                DROP COLUMN ambience
            SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE venues
                DROP CONSTRAINT chk_venues_instagram,
                DROP CONSTRAINT chk_venues_curfew_at,
                DROP CONSTRAINT chk_venues_party,
                DROP CONSTRAINT chk_venues_capacity_standing,
                DROP CONSTRAINT chk_venues_capacity_seated,
                DROP CONSTRAINT chk_venues_price_level,
                DROP COLUMN instagram,
                DROP COLUMN curfew_at,
                DROP COLUMN has_curfew,
                DROP COLUMN party,
                DROP COLUMN capacity_standing,
                DROP COLUMN capacity_seated,
                DROP COLUMN price_level
            SQL);
    }
}
