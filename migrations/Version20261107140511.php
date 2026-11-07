<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Event vocabulary (event-vocabulary, F3): the wedding facts go, the event terms come.
 *
 * - venues: party, has_curfew, curfew_at (and chk_venues_party, chk_venues_curfew_at) are dropped, and
 *   venue_translations.party_note with them.
 * - taxonomy_terms: the groups ceremony, dining, vendor and usage are gone, catering is new, and the rows of
 *   the other groups are re-curated (event types in suitable_for, the event technology in one group, the
 *   catering terms out of amenity, wedding_venue out of building). 118 rows after, 147 before.
 *
 * The wanted rows are one list; rows that stay keep their venue_terms, every other venue_terms row of a
 * removed term goes with it (ON DELETE RESTRICT would stop the delete otherwise). The rows are upserted, so a
 * second run changes nothing. down() restores the baseline's 147 rows and the four columns, empty: their
 * values are not kept. Terms that moved to another group (wifi to technology, the catering terms of amenity and
 * dining to catering ...) are not remapped on purpose: the new site has no production data, so their venue_terms rows
 * go with the old rows. The CMS-edited landing texts of the removed building type go too.
 */
final class Version20261107140511 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Event vocabulary: drop the wedding facts and term groups, add catering, re-curate the event terms';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE venues DROP CONSTRAINT IF EXISTS chk_venues_curfew_at, DROP CONSTRAINT IF EXISTS chk_venues_party');
        $this->addSql('ALTER TABLE venues DROP COLUMN IF EXISTS party, DROP COLUMN IF EXISTS has_curfew, DROP COLUMN IF EXISTS curfew_at');
        $this->addSql('ALTER TABLE venue_translations DROP COLUMN IF EXISTS party_note');

        $this->addSql("DELETE FROM content_blocks WHERE key LIKE 'landing.building.wedding\\_venue.%'");
        $this->replaceTerms(self::WANTED, self::NEW_GROUPS);
    }

    public function down(Schema $schema): void
    {
        $this->replaceTerms(self::BASELINE, self::OLD_GROUPS);

        $this->addSql('ALTER TABLE venue_translations ADD COLUMN party_note VARCHAR(250) DEFAULT NULL');
        $this->addSql('ALTER TABLE venues ADD COLUMN party VARCHAR(20) DEFAULT NULL, ADD COLUMN has_curfew BOOLEAN DEFAULT NULL, ADD COLUMN curfew_at TIME(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql("ALTER TABLE venues ADD CONSTRAINT chk_venues_party CHECK (party IN ('yes', 'limited', 'no')), ADD CONSTRAINT chk_venues_curfew_at CHECK (curfew_at IS NULL OR has_curfew IS TRUE)");
    }

    private const NEW_GROUPS = "'setting', 'building', 'suitable_for', 'infrastructure', 'furniture', 'technology', 'surroundings', 'amenity', 'catering', 'language'";

    private const OLD_GROUPS = "'setting', 'building', 'suitable_for', 'infrastructure', 'furniture', 'technology', 'surroundings', 'ceremony', 'amenity', 'dining', 'vendor', 'usage', 'language'";

    /** Every taxonomy_terms row after up(): term_group, code, position, primary_position. */
    private const WANTED = <<<'SQL'
    ('setting', 'river', 1, NULL),
    ('setting', 'lake', 2, NULL),
    ('setting', 'countryside', 3, NULL),
    ('setting', 'mountains', 4, NULL),
    ('setting', 'city', 5, NULL),
    ('building', 'bar_club', 1, 1),
    ('building', 'farm_barn', 2, 10),
    ('building', 'mountain_hut', 3, 11),
    ('building', 'brewery_winery', 4, NULL),
    ('building', 'medieval_castle', 5, NULL),
    ('building', 'cafe', 6, NULL),
    ('building', 'casino', 7, NULL),
    ('building', 'digital_venue', 8, NULL),
    ('building', 'experience_creative', 9, NULL),
    ('building', 'event_hall', 10, NULL),
    ('building', 'event_venue', 11, 2),
    ('building', 'factory_hall', 12, NULL),
    ('building', 'mobile_venue', 13, NULL),
    ('building', 'leisure_facility', 14, NULL),
    ('building', 'gallery_studio', 15, NULL),
    ('building', 'garden_greenhouse', 16, NULL),
    ('building', 'hangar_shipyard', 17, NULL),
    ('building', 'historic_site', 18, NULL),
    ('building', 'cave_fortress', 19, NULL),
    ('building', 'hotel', 20, 4),
    ('building', 'cinema', 21, NULL),
    ('building', 'congress_centre', 22, 8),
    ('building', 'cultural_centre', 23, NULL),
    ('building', 'exhibition_hall', 24, NULL),
    ('building', 'museum', 25, NULL),
    ('building', 'open_air', 26, NULL),
    ('building', 'party_room', 27, 5),
    ('building', 'restaurant', 28, 6),
    ('building', 'ship', 29, NULL),
    ('building', 'castle', 30, 7),
    ('building', 'seminar_room', 31, 3),
    ('building', 'meeting_room', 32, NULL),
    ('building', 'theatre', 33, NULL),
    ('building', 'tram_train_bus', 34, NULL),
    ('building', 'villa', 35, NULL),
    ('building', 'forest_hut', 36, 9),
    ('building', 'tent', 37, NULL),
    ('building', 'guild_hall', 38, NULL),
    ('suitable_for', 'corporate_event', 1, NULL),
    ('suitable_for', 'seminar', 2, NULL),
    ('suitable_for', 'conference', 3, NULL),
    ('suitable_for', 'workshop', 4, NULL),
    ('suitable_for', 'meeting', 5, NULL),
    ('suitable_for', 'product_presentation', 6, NULL),
    ('suitable_for', 'general_assembly', 7, NULL),
    ('suitable_for', 'team_event', 8, NULL),
    ('suitable_for', 'christmas_party', 9, NULL),
    ('suitable_for', 'club_event', 10, NULL),
    ('suitable_for', 'apero', 11, NULL),
    ('suitable_for', 'birthday', 12, NULL),
    ('suitable_for', 'family_celebration', 13, NULL),
    ('suitable_for', 'wedding', 14, NULL),
    ('suitable_for', 'party', 15, NULL),
    ('suitable_for', 'concert', 16, NULL),
    ('suitable_for', 'exhibition', 17, NULL),
    ('infrastructure', 'air_conditioning', 1, NULL),
    ('infrastructure', 'shower', 2, NULL),
    ('infrastructure', 'cloakroom', 3, NULL),
    ('infrastructure', 'heating_ventilation', 4, NULL),
    ('infrastructure', 'kitchen', 5, NULL),
    ('infrastructure', 'backstage_rooms', 6, NULL),
    ('infrastructure', 'lectern', 7, NULL),
    ('furniture', 'chairs', 1, NULL),
    ('furniture', 'tables', 2, NULL),
    ('furniture', 'standing_tables', 3, NULL),
    ('technology', 'projector_screen', 1, NULL),
    ('technology', 'wifi', 2, NULL),
    ('technology', 'sound_system', 3, NULL),
    ('technology', 'video_conferencing', 4, NULL),
    ('technology', 'stage_lighting', 5, NULL),
    ('technology', 'flipchart_moderation', 6, NULL),
    ('technology', 'tv_screens', 7, NULL),
    ('technology', 'interpreting_system', 8, NULL),
    ('surroundings', 'garden', 1, NULL),
    ('surroundings', 'spa_wellness', 2, NULL),
    ('surroundings', 'terrace', 3, NULL),
    ('surroundings', 'playground', 4, NULL),
    ('surroundings', 'barbecue', 5, NULL),
    ('surroundings', 'golf', 6, NULL),
    ('amenity', 'overnight', 1, NULL),
    ('amenity', 'panorama', 2, NULL),
    ('amenity', 'lake_view', 3, NULL),
    ('amenity', 'dance_floor', 4, NULL),
    ('amenity', 'kids_room', 5, NULL),
    ('amenity', 'outdoor_dining', 6, NULL),
    ('amenity', 'parking', 7, NULL),
    ('amenity', 'wheelchair_access', 8, NULL),
    ('amenity', 'public_transport', 9, NULL),
    ('amenity', 'tent', 10, NULL),
    ('catering', 'in_house', 1, NULL),
    ('catering', 'external_allowed', 2, NULL),
    ('catering', 'bar_drinks', 3, NULL),
    ('catering', 'byo_drinks', 4, NULL),
    ('catering', 'buffet', 5, NULL),
    ('catering', 'finger_food', 6, NULL),
    ('catering', 'coffee_break', 7, NULL),
    ('catering', 'grill', 8, NULL),
    ('catering', 'vegetarian', 9, NULL),
    ('catering', 'vegan', 10, NULL),
    ('catering', 'gluten_free', 11, NULL),
    ('catering', 'allergy_friendly', 12, NULL),
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
    ('language', 'gsw', 11, NULL),
    ('language', 'ro', 12, NULL)
SQL;

    /** Every taxonomy_terms row of the baseline, for down(). */
    private const BASELINE = <<<'SQL'
    ('setting', 'river', 1, NULL),
    ('setting', 'lake', 2, NULL),
    ('setting', 'countryside', 3, NULL),
    ('setting', 'mountains', 4, NULL),
    ('setting', 'city', 5, NULL),
    ('building', 'bar_club', 1, 1),
    ('building', 'farm_barn', 2, 10),
    ('building', 'mountain_hut', 3, 11),
    ('building', 'brewery_winery', 4, NULL),
    ('building', 'medieval_castle', 5, NULL),
    ('building', 'cafe', 6, NULL),
    ('building', 'casino', 7, NULL),
    ('building', 'digital_venue', 8, NULL),
    ('building', 'experience_creative', 9, NULL),
    ('building', 'event_hall', 10, NULL),
    ('building', 'event_venue', 11, 2),
    ('building', 'factory_hall', 12, NULL),
    ('building', 'mobile_venue', 13, NULL),
    ('building', 'leisure_facility', 14, NULL),
    ('building', 'gallery_studio', 15, NULL),
    ('building', 'garden_greenhouse', 16, NULL),
    ('building', 'hangar_shipyard', 17, NULL),
    ('building', 'historic_site', 18, NULL),
    ('building', 'wedding_venue', 19, 3),
    ('building', 'cave_fortress', 20, NULL),
    ('building', 'hotel', 21, 4),
    ('building', 'cinema', 22, NULL),
    ('building', 'congress_centre', 23, 8),
    ('building', 'cultural_centre', 24, NULL),
    ('building', 'exhibition_hall', 25, NULL),
    ('building', 'museum', 26, NULL),
    ('building', 'open_air', 27, NULL),
    ('building', 'party_room', 28, 5),
    ('building', 'restaurant', 29, 6),
    ('building', 'ship', 30, NULL),
    ('building', 'castle', 31, 7),
    ('building', 'seminar_room', 32, NULL),
    ('building', 'meeting_room', 33, NULL),
    ('building', 'theatre', 34, NULL),
    ('building', 'tram_train_bus', 35, NULL),
    ('building', 'villa', 36, NULL),
    ('building', 'forest_hut', 37, 9),
    ('building', 'tent', 38, NULL),
    ('building', 'guild_hall', 39, NULL),
    ('suitable_for', 'wedding', 1, NULL),
    ('suitable_for', 'family_celebration', 2, NULL),
    ('suitable_for', 'birthday', 3, NULL),
    ('suitable_for', 'club_event', 4, NULL),
    ('suitable_for', 'party_celebration', 5, NULL),
    ('suitable_for', 'class_reunion', 6, NULL),
    ('suitable_for', 'private_event', 7, NULL),
    ('suitable_for', 'meeting', 8, NULL),
    ('suitable_for', 'conference', 9, NULL),
    ('suitable_for', 'congress', 10, NULL),
    ('suitable_for', 'corporate_event', 11, NULL),
    ('suitable_for', 'general_assembly', 12, NULL),
    ('suitable_for', 'apero', 13, NULL),
    ('suitable_for', 'team_event', 14, NULL),
    ('suitable_for', 'christmas_party', 15, NULL),
    ('suitable_for', 'concert', 16, NULL),
    ('suitable_for', 'theatre', 17, NULL),
    ('suitable_for', 'party', 18, NULL),
    ('suitable_for', 'sports_event', 19, NULL),
    ('suitable_for', 'trade_fair', 20, NULL),
    ('suitable_for', 'public_event', 21, NULL),
    ('infrastructure', 'wifi', 1, NULL),
    ('infrastructure', 'air_conditioning', 2, NULL),
    ('infrastructure', 'stage', 3, NULL),
    ('infrastructure', 'shower', 4, NULL),
    ('infrastructure', 'cloakroom', 5, NULL),
    ('infrastructure', 'heating_ventilation', 6, NULL),
    ('infrastructure', 'kitchen', 7, NULL),
    ('infrastructure', 'backstage_rooms', 8, NULL),
    ('infrastructure', 'lectern', 9, NULL),
    ('furniture', 'flipchart', 1, NULL),
    ('furniture', 'magnetic_board', 2, NULL),
    ('furniture', 'moderation_kit', 3, NULL),
    ('furniture', 'chairs', 4, NULL),
    ('furniture', 'tables', 5, NULL),
    ('technology', 'sound_system', 1, NULL),
    ('technology', 'projector_screen', 2, NULL),
    ('technology', 'interpreting_system', 3, NULL),
    ('technology', 'lighting_system', 4, NULL),
    ('technology', 'tv_screens', 5, NULL),
    ('surroundings', 'garden', 1, NULL),
    ('surroundings', 'spa_wellness', 2, NULL),
    ('surroundings', 'terrace', 3, NULL),
    ('surroundings', 'playground', 4, NULL),
    ('surroundings', 'barbecue', 5, NULL),
    ('surroundings', 'golf', 6, NULL),
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
    ('language', 'gsw', 11, NULL),
    ('language', 'ro', 12, NULL)
SQL;

    /**
     * Makes taxonomy_terms hold exactly $rows: the rows that stay keep their venue_terms, the others lose
     * theirs, then every row is upserted into its final position and the group CHECK follows $groups.
     */
    private function replaceTerms(string $rows, string $groups): void
    {
        $this->addSql('CREATE TEMPORARY TABLE wanted_terms (term_group VARCHAR(20) NOT NULL, code VARCHAR(40) NOT NULL, position SMALLINT NOT NULL, primary_position SMALLINT DEFAULT NULL)');
        $this->addSql('INSERT INTO wanted_terms (term_group, code, position, primary_position) VALUES '.$rows);
        $this->addSql('ALTER TABLE taxonomy_terms DROP CONSTRAINT chk_taxonomy_terms_term_group');
        $this->addSql('DELETE FROM venue_terms vt WHERE NOT EXISTS (SELECT 1 FROM wanted_terms w WHERE w.term_group = vt.term_group AND w.code = vt.term_code)');
        $this->addSql('DELETE FROM taxonomy_terms t WHERE NOT EXISTS (SELECT 1 FROM wanted_terms w WHERE w.term_group = t.term_group AND w.code = t.code)');
        // Free every position first: the final ones overlap the current ones, and the unique indexes check per row.
        $this->addSql('UPDATE taxonomy_terms SET position = position + 1000, primary_position = NULL');
        $this->addSql(<<<'SQL'
            INSERT INTO taxonomy_terms (term_group, code, position, primary_position)
            SELECT term_group, code, position, primary_position FROM wanted_terms
            ON CONFLICT (term_group, code) DO UPDATE
                SET position = EXCLUDED.position, primary_position = EXCLUDED.primary_position, updated_at = CURRENT_TIMESTAMP
            SQL);
        $this->addSql('ALTER TABLE taxonomy_terms ADD CONSTRAINT chk_taxonomy_terms_term_group CHECK (term_group IN ('.$groups.'))');
        $this->addSql('DROP TABLE wanted_terms');
    }
}
