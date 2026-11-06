<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Baseline of deine-eventlocation.ch: the schema the 25 migrations of hochzeitslocation built up to
 * the fork point 7e96eee, folded into one CREATE per table, without the rating the venue page
 * retired in #53 (venue_reviews, venue_rating_highlights, venues.rating_score and rating_count).
 * No database had to be carried over (KICKOFF E1). Columns a later migration added sit after
 * created_at/updated_at, where the old chain put them, so the dump of this baseline equals the
 * dump of the old chain.
 *
 * - earthdistance depends on cube, so it is created after it and dropped before it. pg_trgm and
 *   unaccent serve the typo- and umlaut-tolerant autocomplete (S1), cube and earthdistance the
 *   "within N km" search without PostGIS.
 * - unaccent() is STABLE, so it cannot appear in an index expression. geo_fold() and
 *   geo_fold_de() wrap it as IMMUTABLE SQL functions (the dictionary is named explicitly), and
 *   geo_search_key() joins the German fold (ü → ue) and the accent-only fold (ü → u), so "zuer",
 *   "Zuerich" and "Zurich" all find Zürich. Queries fold through the same functions so they hit
 *   the idx_*_search_key indexes, which DBAL's introspection cannot see (not mapped on entities).
 * - Owned rows reference their owner ON DELETE CASCADE; references out of the aggregate (towns,
 *   cantons, taxonomy_terms, admin_users) are ON DELETE RESTRICT.
 * - Reference rows seeded here: 27 cantons (with Liechtenstein, #46; centroids from the BFS K4
 *   2026-01-01 canton layer, assets/maps/LICENSES.md), 147 taxonomy terms, 7 CRM activity types,
 *   6 deal stages. towns are filled by app:geo:import-towns.
 */
final class Version20261106174839 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Baseline of deine-eventlocation.ch: the wedding schema of hochzeitslocation at fork point 7e96eee, without the retired rating (#53)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        $this->addSql('CREATE EXTENSION IF NOT EXISTS unaccent');
        $this->addSql('CREATE EXTENSION IF NOT EXISTS cube');
        $this->addSql('CREATE EXTENSION IF NOT EXISTS earthdistance');

        $this->addSql(<<<'SQL'
            CREATE FUNCTION geo_fold(text) RETURNS text
                LANGUAGE sql IMMUTABLE PARALLEL SAFE STRICT
                RETURN btrim(regexp_replace(public.unaccent('public.unaccent'::regdictionary, lower($1)), '[^a-z0-9]+', ' ', 'g'))
            SQL);
        $this->addSql(<<<'SQL'
            CREATE FUNCTION geo_fold_de(text) RETURNS text
                LANGUAGE sql IMMUTABLE PARALLEL SAFE STRICT
                RETURN geo_fold(replace(replace(replace(lower($1), 'ä', 'ae'), 'ö', 'oe'), 'ü', 'ue'))
            SQL);
        $this->addSql(<<<'SQL'
            CREATE FUNCTION geo_search_key(text) RETURNS text
                LANGUAGE sql IMMUTABLE PARALLEL SAFE STRICT
                RETURN geo_fold_de($1) || ' ' || geo_fold($1)
            SQL);

        // Geo (#3, #46): the fold functions above, cantons with Liechtenstein, towns, town_zips.
        $this->addSql(<<<'SQL'
            CREATE TABLE cantons (
                code VARCHAR(2) NOT NULL,
                bfs_number SMALLINT NOT NULL,
                slug VARCHAR(40) NOT NULL,
                svg_id VARCHAR(5) NOT NULL,
                centroid_lat DOUBLE PRECISION NOT NULL,
                centroid_lng DOUBLE PRECISION NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                CONSTRAINT pk_cantons PRIMARY KEY (code),
                CONSTRAINT uk_cantons_bfs_number UNIQUE (bfs_number),
                CONSTRAINT uk_cantons_slug UNIQUE (slug),
                CONSTRAINT uk_cantons_svg_id UNIQUE (svg_id),
                CONSTRAINT chk_cantons_code CHECK (code ~ '^[a-z]{2}$'),
                CONSTRAINT chk_cantons_svg_id CHECK (svg_id = 'ch-' || code),
                CONSTRAINT chk_cantons_bfs_number CHECK (bfs_number BETWEEN 1 AND 27)
            )
            SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO cantons (code, bfs_number, slug, svg_id, centroid_lat, centroid_lng) VALUES
                ('zh', 1, 'zuerich', 'ch-zh', 47.4840, 8.6484),
                ('be', 2, 'bern', 'ch-be', 46.9281, 7.6136),
                ('lu', 3, 'luzern', 'ch-lu', 47.0492, 8.0797),
                ('ur', 4, 'uri', 'ch-ur', 46.7814, 8.6262),
                ('sz', 5, 'schwyz', 'ch-sz', 47.0283, 8.7444),
                ('ow', 6, 'obwalden', 'ch-ow', 46.8345, 8.1314),
                ('nw', 7, 'nidwalden', 'ch-nw', 46.9220, 8.4106),
                ('gl', 8, 'glarus', 'ch-gl', 46.9826, 9.0673),
                ('zg', 9, 'zug', 'ch-zg', 47.1621, 8.5553),
                ('fr', 10, 'freiburg', 'ch-fr', 46.7946, 7.1879),
                ('so', 11, 'solothurn', 'ch-so', 47.2349, 7.5402),
                ('bs', 12, 'basel-stadt', 'ch-bs', 47.5620, 7.6006),
                ('bl', 13, 'basel-landschaft', 'ch-bl', 47.4268, 7.7594),
                ('sh', 14, 'schaffhausen', 'ch-sh', 47.7237, 8.5653),
                ('ar', 15, 'appenzell-ausserrhoden', 'ch-ar', 47.3529, 9.2758),
                ('ai', 16, 'appenzell-innerrhoden', 'ch-ai', 47.3100, 9.4047),
                ('sg', 17, 'st-gallen', 'ch-sg', 47.2478, 9.1047),
                ('gr', 18, 'graubuenden', 'ch-gr', 46.6920, 9.6698),
                ('ag', 19, 'aargau', 'ch-ag', 47.4397, 8.1517),
                ('tg', 20, 'thurgau', 'ch-tg', 47.5766, 9.0608),
                ('ti', 21, 'tessin', 'ch-ti', 46.3680, 8.7893),
                ('vd', 22, 'waadt', 'ch-vd', 46.6459, 6.6455),
                ('vs', 23, 'wallis', 'ch-vs', 46.1919, 7.6427),
                ('ne', 24, 'neuenburg', 'ch-ne', 46.9962, 6.7757),
                ('ge', 25, 'genf', 'ch-ge', 46.1987, 6.1065),
                ('ju', 26, 'jura', 'ch-ju', 47.3666, 7.1603),
                ('li', 27, 'liechtenstein', 'ch-li', 47.1783, 9.5312)
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE towns (
                id UUID NOT NULL,
                canton_code VARCHAR(2) NOT NULL,
                zip VARCHAR(4) NOT NULL,
                name VARCHAR(100) NOT NULL,
                slug VARCHAR(100) NOT NULL,
                bfs_number INTEGER NOT NULL,
                lat DOUBLE PRECISION NOT NULL,
                lng DOUBLE PRECISION NOT NULL,
                population INTEGER NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                CONSTRAINT pk_towns PRIMARY KEY (id),
                CONSTRAINT uk_towns_canton_code_name UNIQUE (canton_code, name),
                CONSTRAINT uk_towns_canton_code_slug UNIQUE (canton_code, slug),
                CONSTRAINT chk_towns_zip CHECK (zip ~ '^[0-9]{4}$'),
                CONSTRAINT chk_towns_lat CHECK (lat BETWEEN 45.8 AND 47.9),
                CONSTRAINT chk_towns_lng CHECK (lng BETWEEN 5.9 AND 10.6),
                CONSTRAINT chk_towns_population CHECK (population >= 0),
                CONSTRAINT chk_towns_bfs_number CHECK (bfs_number > 0),
                CONSTRAINT fk_towns_canton_code FOREIGN KEY (canton_code) REFERENCES cantons (code) ON DELETE RESTRICT
            )
            SQL);
        $this->addSql('CREATE INDEX idx_towns_canton_code ON towns (canton_code)');
        $this->addSql('CREATE INDEX idx_towns_zip ON towns (zip)');
        $this->addSql('CREATE INDEX idx_towns_search_key ON towns USING gin (geo_search_key(name) gin_trgm_ops)');
        $this->addSql('CREATE INDEX idx_towns_earth ON towns USING gist (ll_to_earth(lat, lng))');
        $this->addSql(<<<'SQL'
            CREATE TABLE town_zips (
                zip VARCHAR(4) NOT NULL,
                town_id UUID NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                CONSTRAINT pk_town_zips PRIMARY KEY (zip, town_id),
                CONSTRAINT chk_town_zips_zip CHECK (zip ~ '^[0-9]{4}$'),
                CONSTRAINT fk_town_zips_town_id FOREIGN KEY (town_id) REFERENCES towns (id) ON DELETE CASCADE
            )
            SQL);
        $this->addSql('CREATE INDEX idx_town_zips_town_id ON town_zips (town_id)');

        // Venues (#4, #5, #9, #21, #24, #31, Q20, #53).
        $this->addSql(<<<'SQL'
            CREATE TABLE venues (
                id UUID NOT NULL,
                number INTEGER GENERATED ALWAYS AS IDENTITY,
                name VARCHAR(150) NOT NULL,
                tier VARCHAR(20) NOT NULL,
                tier_rank SMALLINT NOT NULL GENERATED ALWAYS AS (CASE tier WHEN 'beliebte_location' THEN 1 WHEN 'empfehlung' THEN 2 WHEN 'top_premium' THEN 3 WHEN 'premium' THEN 4 WHEN 'basic' THEN 5 END) STORED,
                status VARCHAR(20) NOT NULL,
                capacity_min INTEGER DEFAULT NULL,
                capacity_max INTEGER NOT NULL,
                street VARCHAR(150) DEFAULT NULL,
                zip VARCHAR(4),
                town_id UUID,
                canton_code VARCHAR(2),
                lat DOUBLE PRECISION,
                lng DOUBLE PRECISION,
                distance_station_km NUMERIC(4, 1) DEFAULT NULL,
                station_name VARCHAR(150) DEFAULT NULL,
                distance_highway_km NUMERIC(4, 1) DEFAULT NULL,
                highway_name VARCHAR(150) DEFAULT NULL,
                distance_airport_km NUMERIC(4, 1) DEFAULT NULL,
                airport_name VARCHAR(150) DEFAULT NULL,
                phone VARCHAR(16) DEFAULT NULL,
                inquiry_email VARCHAR(254) DEFAULT NULL,
                website VARCHAR(500) DEFAULT NULL,
                contact_person VARCHAR(150) DEFAULT NULL,
                own_catering VARCHAR(20) DEFAULT NULL,
                daylight BOOLEAN DEFAULT NULL,
                room_count SMALLINT DEFAULT NULL,
                largest_room_m2 INTEGER DEFAULT NULL,
                max_room_height_m NUMERIC(4, 2) DEFAULT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                content_key VARCHAR(100) DEFAULT NULL,
                content_hash VARCHAR(64) DEFAULT NULL,
                price_level VARCHAR(20) DEFAULT NULL,
                capacity_seated INTEGER DEFAULT NULL,
                capacity_standing INTEGER DEFAULT NULL,
                party VARCHAR(20) DEFAULT NULL,
                has_curfew BOOLEAN DEFAULT NULL,
                curfew_at TIME(0) WITHOUT TIME ZONE DEFAULT NULL,
                instagram VARCHAR(500) DEFAULT NULL,
                cms_edited_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                coordinates_from_town BOOLEAN DEFAULT FALSE NOT NULL,
                awaits_first_publish BOOLEAN DEFAULT FALSE NOT NULL,
                google_place_id VARCHAR(255) DEFAULT NULL,
                google_rating_hidden BOOLEAN DEFAULT false NOT NULL,
                CONSTRAINT pk_venues PRIMARY KEY (id),
                CONSTRAINT uk_venues_number UNIQUE (number),
                CONSTRAINT chk_venues_tier CHECK (tier IN ('beliebte_location', 'empfehlung', 'top_premium', 'premium', 'basic')),
                CONSTRAINT chk_venues_status CHECK (status IN ('published', 'unpublished')),
                CONSTRAINT chk_venues_capacity CHECK (capacity_max BETWEEN 1 AND 50000 AND (capacity_min IS NULL OR capacity_min BETWEEN 1 AND capacity_max)),
                CONSTRAINT chk_venues_zip CHECK (zip ~ '^[0-9]{4}$'),
                CONSTRAINT chk_venues_lat CHECK (lat BETWEEN 45.8 AND 47.9),
                CONSTRAINT chk_venues_lng CHECK (lng BETWEEN 5.9 AND 10.6),
                CONSTRAINT chk_venues_distances CHECK ((distance_station_km IS NULL OR distance_station_km BETWEEN 0 AND 500) AND (distance_highway_km IS NULL OR distance_highway_km BETWEEN 0 AND 500) AND (distance_airport_km IS NULL OR distance_airport_km BETWEEN 0 AND 500)),
                CONSTRAINT chk_venues_distance_names CHECK ((station_name IS NULL OR distance_station_km IS NOT NULL) AND (highway_name IS NULL OR distance_highway_km IS NOT NULL) AND (airport_name IS NULL OR distance_airport_km IS NOT NULL)),
                CONSTRAINT chk_venues_phone CHECK (phone ~ '^\+[1-9][0-9]{6,14}$'),
                CONSTRAINT chk_venues_inquiry_email CHECK (inquiry_email ~ '^[^@\s]+@[^@\s]+\.[^@\s]+$'),
                CONSTRAINT chk_venues_website CHECK (website ~ '^https?://'),
                CONSTRAINT chk_venues_own_catering CHECK (own_catering IN ('allowed', 'fixed_caterer')),
                CONSTRAINT chk_venues_room_count CHECK (room_count >= 0),
                CONSTRAINT chk_venues_largest_room_m2 CHECK (largest_room_m2 BETWEEN 1 AND 50000),
                CONSTRAINT chk_venues_max_room_height_m CHECK (max_room_height_m > 0 AND max_room_height_m <= 30),
                CONSTRAINT fk_venues_town_id FOREIGN KEY (town_id) REFERENCES towns (id) ON DELETE RESTRICT,
                CONSTRAINT fk_venues_canton_code FOREIGN KEY (canton_code) REFERENCES cantons (code) ON DELETE RESTRICT,
                CONSTRAINT uk_venues_content_key UNIQUE (content_key),
                CONSTRAINT chk_venues_content_key CHECK (content_key ~ '^[a-z0-9]+(-[a-z0-9]+)*$'),
                CONSTRAINT chk_venues_content_hash CHECK (content_hash IS NULL OR (content_key IS NOT NULL AND content_hash ~ '^[0-9a-f]{64}$')),
                CONSTRAINT chk_venues_price_level CHECK (price_level IN ('moderate', 'medium', 'exclusive')),
                CONSTRAINT chk_venues_capacity_seated CHECK (capacity_seated BETWEEN 1 AND 50000),
                CONSTRAINT chk_venues_capacity_standing CHECK (capacity_standing BETWEEN 1 AND 50000),
                CONSTRAINT chk_venues_party CHECK (party IN ('yes', 'limited', 'no')),
                CONSTRAINT chk_venues_curfew_at CHECK (curfew_at IS NULL OR has_curfew IS TRUE),
                CONSTRAINT chk_venues_instagram CHECK (instagram ~ '^https://'),
                CONSTRAINT chk_venues_address CHECK ((town_id IS NULL) = (zip IS NULL) AND (street IS NULL OR town_id IS NOT NULL)),
                CONSTRAINT chk_venues_coordinates CHECK ((lat IS NULL) = (lng IS NULL)),
                CONSTRAINT chk_venues_town_canton CHECK (town_id IS NULL OR canton_code IS NOT NULL),
                CONSTRAINT chk_venues_coordinates_from_town CHECK (NOT coordinates_from_town OR lat IS NOT NULL),
                CONSTRAINT chk_venues_google_place_id CHECK (google_place_id ~ '^[A-Za-z0-9_-]{10,255}$'),
                CONSTRAINT chk_venues_google_rating_hidden CHECK (google_place_id IS NOT NULL OR NOT google_rating_hidden)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_venues_town_id ON venues (town_id)');
        $this->addSql('CREATE INDEX idx_venues_canton_code ON venues (canton_code)');
        $this->addSql('CREATE INDEX idx_venues_earth ON venues USING gist (ll_to_earth(lat, lng))');
        $this->addSql('CREATE INDEX idx_venues_search_key ON venues USING gin (geo_search_key(name) gin_trgm_ops)');
        $this->addSql(<<<'SQL'
            CREATE TABLE venue_translations (
                id UUID NOT NULL,
                venue_id UUID NOT NULL,
                locale VARCHAR(2) NOT NULL,
                slug VARCHAR(160) NOT NULL,
                summary VARCHAR(200) NOT NULL,
                description TEXT DEFAULT NULL,
                meta_description VARCHAR(160) DEFAULT NULL,
                parking_text VARCHAR(250) DEFAULT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                ambience VARCHAR(60) DEFAULT NULL,
                capacity_note VARCHAR(250) DEFAULT NULL,
                party_note VARCHAR(250) DEFAULT NULL,
                opening_hours VARCHAR(250) DEFAULT NULL,
                region VARCHAR(60) DEFAULT NULL,
                CONSTRAINT pk_venue_translations PRIMARY KEY (id),
                CONSTRAINT uk_venue_translations_venue_id_locale UNIQUE (venue_id, locale),
                CONSTRAINT uk_venue_translations_locale_slug UNIQUE (locale, slug),
                CONSTRAINT chk_venue_translations_locale CHECK (locale ~ '^[a-z]{2}$'),
                CONSTRAINT chk_venue_translations_slug CHECK (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$'),
                CONSTRAINT fk_venue_translations_venue_id FOREIGN KEY (venue_id) REFERENCES venues (id) ON DELETE CASCADE
            )
            SQL);
        $this->addSql('CREATE INDEX idx_venue_translations_venue_id ON venue_translations (venue_id)');
        $this->addSql(<<<'SQL'
            CREATE TABLE venue_slug_history (
                id UUID NOT NULL,
                venue_id UUID NOT NULL,
                locale VARCHAR(2) NOT NULL,
                slug VARCHAR(160) NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                CONSTRAINT pk_venue_slug_history PRIMARY KEY (id),
                CONSTRAINT uk_venue_slug_history_locale_slug UNIQUE (locale, slug),
                CONSTRAINT chk_venue_slug_history_locale CHECK (locale ~ '^[a-z]{2}$'),
                CONSTRAINT chk_venue_slug_history_slug CHECK (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$'),
                CONSTRAINT fk_venue_slug_history_venue_id FOREIGN KEY (venue_id) REFERENCES venues (id) ON DELETE CASCADE
            )
            SQL);
        $this->addSql('CREATE INDEX idx_venue_slug_history_venue_id ON venue_slug_history (venue_id)');
        $this->addSql(<<<'SQL'
            CREATE TABLE taxonomy_terms (
                term_group VARCHAR(20) NOT NULL,
                code VARCHAR(40) NOT NULL,
                position SMALLINT NOT NULL,
                primary_position SMALLINT DEFAULT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                CONSTRAINT pk_taxonomy_terms PRIMARY KEY (term_group, code),
                CONSTRAINT uk_taxonomy_terms_term_group_position UNIQUE (term_group, position),
                CONSTRAINT uk_taxonomy_terms_term_group_primary_position UNIQUE (term_group, primary_position),
                CONSTRAINT chk_taxonomy_terms_code CHECK (code ~ '^[a-z][a-z0-9_]*$'),
                CONSTRAINT chk_taxonomy_terms_position CHECK (position > 0),
                CONSTRAINT chk_taxonomy_terms_primary_position CHECK (primary_position IS NULL OR primary_position > 0),
                CONSTRAINT chk_taxonomy_terms_term_group CHECK (term_group IN ('setting', 'building', 'suitable_for', 'infrastructure', 'furniture', 'technology', 'surroundings', 'ceremony', 'amenity', 'dining', 'vendor', 'usage', 'language'))
            )
            SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO taxonomy_terms (term_group, code, position, primary_position) VALUES
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
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE venue_terms (
                venue_id UUID NOT NULL,
                term_group VARCHAR(20) NOT NULL,
                term_code VARCHAR(40) NOT NULL,
                is_main BOOLEAN DEFAULT FALSE NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                CONSTRAINT pk_venue_terms PRIMARY KEY (venue_id, term_group, term_code),
                CONSTRAINT fk_venue_terms_venue_id FOREIGN KEY (venue_id) REFERENCES venues (id) ON DELETE CASCADE,
                CONSTRAINT fk_venue_terms_term FOREIGN KEY (term_group, term_code) REFERENCES taxonomy_terms (term_group, code) ON DELETE RESTRICT
            )
            SQL);
        $this->addSql('CREATE INDEX idx_venue_terms_venue_id ON venue_terms (venue_id)');
        $this->addSql('CREATE INDEX idx_venue_terms_term_group_term_code ON venue_terms (term_group, term_code)');
        $this->addSql('CREATE UNIQUE INDEX uk_venue_terms_venue_id_term_group_main ON venue_terms (venue_id, term_group) WHERE is_main');
        $this->addSql(<<<'SQL'
            CREATE TABLE rooms (
                id UUID NOT NULL,
                venue_id UUID NOT NULL,
                position SMALLINT NOT NULL,
                capacity_min INTEGER DEFAULT NULL,
                capacity_max INTEGER DEFAULT NULL,
                area_m2 INTEGER DEFAULT NULL,
                height_m NUMERIC(4, 2) DEFAULT NULL,
                floor_material VARCHAR(20) DEFAULT NULL,
                capacity_seminar_min INTEGER DEFAULT NULL,
                capacity_seminar_max INTEGER DEFAULT NULL,
                capacity_banquet_min INTEGER DEFAULT NULL,
                capacity_banquet_max INTEGER DEFAULT NULL,
                capacity_apero_min INTEGER DEFAULT NULL,
                capacity_apero_max INTEGER DEFAULT NULL,
                capacity_concert_min INTEGER DEFAULT NULL,
                capacity_concert_max INTEGER DEFAULT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                content_key VARCHAR(100) DEFAULT NULL,
                CONSTRAINT pk_rooms PRIMARY KEY (id),
                CONSTRAINT uk_rooms_venue_id_position UNIQUE (venue_id, position),
                CONSTRAINT chk_rooms_position CHECK (position > 0),
                CONSTRAINT chk_rooms_capacity CHECK ((capacity_min IS NULL OR capacity_min BETWEEN 1 AND 50000) AND (capacity_max IS NULL OR capacity_max BETWEEN 1 AND 50000) AND (capacity_min IS NULL OR capacity_max IS NULL OR capacity_min <= capacity_max)),
                CONSTRAINT chk_rooms_area_m2 CHECK (area_m2 BETWEEN 1 AND 50000),
                CONSTRAINT chk_rooms_height_m CHECK (height_m > 0 AND height_m <= 30),
                CONSTRAINT chk_rooms_floor_material CHECK (floor_material IN ('various', 'parquet', 'carpet', 'stone', 'wood')),
                CONSTRAINT chk_rooms_capacity_seminar CHECK ((capacity_seminar_min IS NULL OR capacity_seminar_min BETWEEN 1 AND 50000) AND (capacity_seminar_max IS NULL OR capacity_seminar_max BETWEEN 1 AND 50000) AND (capacity_seminar_min IS NULL OR capacity_seminar_max IS NULL OR capacity_seminar_min <= capacity_seminar_max)),
                CONSTRAINT chk_rooms_capacity_banquet CHECK ((capacity_banquet_min IS NULL OR capacity_banquet_min BETWEEN 1 AND 50000) AND (capacity_banquet_max IS NULL OR capacity_banquet_max BETWEEN 1 AND 50000) AND (capacity_banquet_min IS NULL OR capacity_banquet_max IS NULL OR capacity_banquet_min <= capacity_banquet_max)),
                CONSTRAINT chk_rooms_capacity_apero CHECK ((capacity_apero_min IS NULL OR capacity_apero_min BETWEEN 1 AND 50000) AND (capacity_apero_max IS NULL OR capacity_apero_max BETWEEN 1 AND 50000) AND (capacity_apero_min IS NULL OR capacity_apero_max IS NULL OR capacity_apero_min <= capacity_apero_max)),
                CONSTRAINT chk_rooms_capacity_concert CHECK ((capacity_concert_min IS NULL OR capacity_concert_min BETWEEN 1 AND 50000) AND (capacity_concert_max IS NULL OR capacity_concert_max BETWEEN 1 AND 50000) AND (capacity_concert_min IS NULL OR capacity_concert_max IS NULL OR capacity_concert_min <= capacity_concert_max)),
                CONSTRAINT fk_rooms_venue_id FOREIGN KEY (venue_id) REFERENCES venues (id) ON DELETE CASCADE,
                CONSTRAINT uk_rooms_venue_id_content_key UNIQUE (venue_id, content_key),
                CONSTRAINT chk_rooms_content_key CHECK (content_key ~ '^[a-z0-9]+(-[a-z0-9]+)*$')
            )
            SQL);
        $this->addSql('CREATE INDEX idx_rooms_venue_id ON rooms (venue_id)');
        $this->addSql(<<<'SQL'
            CREATE TABLE room_translations (
                id UUID NOT NULL,
                room_id UUID NOT NULL,
                locale VARCHAR(2) NOT NULL,
                name VARCHAR(100) NOT NULL,
                description TEXT DEFAULT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                CONSTRAINT pk_room_translations PRIMARY KEY (id),
                CONSTRAINT uk_room_translations_room_id_locale UNIQUE (room_id, locale),
                CONSTRAINT chk_room_translations_locale CHECK (locale ~ '^[a-z]{2}$'),
                CONSTRAINT fk_room_translations_room_id FOREIGN KEY (room_id) REFERENCES rooms (id) ON DELETE CASCADE
            )
            SQL);
        $this->addSql('CREATE INDEX idx_room_translations_room_id ON room_translations (room_id)');
        $this->addSql(<<<'SQL'
            CREATE TABLE venue_documents (
                id UUID NOT NULL,
                venue_id UUID NOT NULL,
                position SMALLINT NOT NULL,
                path VARCHAR(500) NOT NULL,
                size_bytes INTEGER NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                CONSTRAINT pk_venue_documents PRIMARY KEY (id),
                CONSTRAINT uk_venue_documents_venue_id_position UNIQUE (venue_id, position),
                CONSTRAINT chk_venue_documents_position CHECK (position > 0),
                CONSTRAINT chk_venue_documents_path CHECK (path ~ '\.pdf$'),
                CONSTRAINT chk_venue_documents_size_bytes CHECK (size_bytes > 0),
                CONSTRAINT fk_venue_documents_venue_id FOREIGN KEY (venue_id) REFERENCES venues (id) ON DELETE CASCADE
            )
            SQL);
        $this->addSql('CREATE INDEX idx_venue_documents_venue_id ON venue_documents (venue_id)');
        $this->addSql(<<<'SQL'
            CREATE TABLE venue_document_translations (
                id UUID NOT NULL,
                venue_document_id UUID NOT NULL,
                locale VARCHAR(2) NOT NULL,
                title VARCHAR(200) NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                CONSTRAINT pk_venue_document_translations PRIMARY KEY (id),
                CONSTRAINT uk_venue_document_translations_venue_document_id_locale UNIQUE (venue_document_id, locale),
                CONSTRAINT chk_venue_document_translations_locale CHECK (locale ~ '^[a-z]{2}$'),
                CONSTRAINT fk_venue_document_translations_venue_document_id FOREIGN KEY (venue_document_id) REFERENCES venue_documents (id) ON DELETE CASCADE
            )
            SQL);
        $this->addSql('CREATE INDEX idx_venue_document_translations_venue_document_id ON venue_document_translations (venue_document_id)');
        $this->addSql(<<<'SQL'
            CREATE TABLE venue_photos (
                id UUID NOT NULL,
                venue_id UUID NOT NULL,
                room_id UUID DEFAULT NULL,
                position SMALLINT NOT NULL,
                original_path VARCHAR(500) NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                width INTEGER NOT NULL,
                height INTEGER NOT NULL,
                dominant_color VARCHAR(7) NOT NULL,
                variants JSONB NOT NULL,
                CONSTRAINT pk_venue_photos PRIMARY KEY (id),
                CONSTRAINT uk_venue_photos_venue_id_room_id_position UNIQUE NULLS NOT DISTINCT (venue_id, room_id, position),
                CONSTRAINT chk_venue_photos_position CHECK (position > 0),
                CONSTRAINT fk_venue_photos_venue_id FOREIGN KEY (venue_id) REFERENCES venues (id) ON DELETE CASCADE,
                CONSTRAINT fk_venue_photos_room_id FOREIGN KEY (room_id) REFERENCES rooms (id) ON DELETE CASCADE,
                CONSTRAINT chk_venue_photos_width CHECK (width > 0),
                CONSTRAINT chk_venue_photos_height CHECK (height > 0),
                CONSTRAINT chk_venue_photos_dominant_color CHECK (dominant_color ~ '^#[0-9a-f]{6}$'),
                CONSTRAINT chk_venue_photos_variants CHECK (
                    CASE
                        WHEN jsonb_typeof(variants) = 'object'
                            AND jsonb_typeof(variants -> 'widths') = 'array'
                            AND jsonb_typeof(variants -> 'formats') = 'array'
                        THEN jsonb_array_length(variants -> 'widths') > 0 AND variants -> 'formats' @> '["jpg"]'
                        ELSE FALSE
                    END
                )
            )
            SQL);
        $this->addSql('CREATE INDEX idx_venue_photos_venue_id ON venue_photos (venue_id)');
        $this->addSql('CREATE INDEX idx_venue_photos_room_id ON venue_photos (room_id)');
        $this->addSql(<<<'SQL'
            CREATE TABLE venue_photo_translations (
                id UUID NOT NULL,
                venue_photo_id UUID NOT NULL,
                locale VARCHAR(2) NOT NULL,
                alt VARCHAR(700) NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                CONSTRAINT pk_venue_photo_translations PRIMARY KEY (id),
                CONSTRAINT uk_venue_photo_translations_venue_photo_id_locale UNIQUE (venue_photo_id, locale),
                CONSTRAINT chk_venue_photo_translations_locale CHECK (locale ~ '^[a-z]{2}$'),
                CONSTRAINT fk_venue_photo_translations_venue_photo_id FOREIGN KEY (venue_photo_id) REFERENCES venue_photos (id) ON DELETE CASCADE
            )
            SQL);
        $this->addSql('CREATE INDEX idx_venue_photo_translations_venue_photo_id ON venue_photo_translations (venue_photo_id)');

        // Content import (#9, #24) and CMS pages (#26).
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
                overwrote_cms_edits BOOLEAN NOT NULL,
                venues_cms_edited INTEGER NOT NULL,
                CONSTRAINT pk_content_imports PRIMARY KEY (id),
                CONSTRAINT chk_content_imports_counts CHECK (
                    venues_seen >= 0 AND venues_created >= 0 AND venues_updated >= 0
                    AND venues_unchanged >= 0 AND venues_failed >= 0 AND venues_unpublished >= 0
                    AND photos_added >= 0 AND photos_removed >= 0
                    AND documents_added >= 0 AND documents_removed >= 0
                ),
                CONSTRAINT chk_content_imports_finished_at CHECK (finished_at >= started_at),
                CONSTRAINT chk_content_imports_errors CHECK (jsonb_typeof(errors) = 'array'),
                CONSTRAINT chk_content_imports_only_key CHECK (only_key ~ '^[a-z0-9]+(-[a-z0-9]+)*$'),
                CONSTRAINT chk_content_imports_venues_cms_edited CHECK (venues_cms_edited >= 0)
            )
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
        $this->addSql(<<<'SQL'
            CREATE TABLE content_blocks (
                key VARCHAR(120) NOT NULL,
                locale VARCHAR(5) NOT NULL,
                body_markdown TEXT NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                CONSTRAINT pk_content_blocks PRIMARY KEY (key, locale),
                CONSTRAINT chk_content_blocks_key CHECK (key ~ '^[a-z][a-z0-9_]*(\.[a-z0-9_-]+)+$'),
                CONSTRAINT chk_content_blocks_body CHECK (btrim(body_markdown) <> ''),
                CONSTRAINT chk_content_blocks_locale CHECK (locale ~ '^[a-z]{2}$')
            )
            SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON TABLE content_blocks IS 'Staff-edited Markdown per locale (#26). key: a module block (listing.intro, footer.note) or landing.<canton|town|building>.<code|town id>.<intro|title|description>.'
            SQL);

        // Back office (#22) and the location managers' accounts (#42), which the audit log points at.
        $this->addSql(<<<'SQL'
            CREATE TABLE admin_users (
                id UUID NOT NULL,
                email VARCHAR(180) NOT NULL,
                display_name VARCHAR(100) NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                role VARCHAR(10) NOT NULL,
                totp_secret_encrypted TEXT DEFAULT NULL,
                is_active BOOLEAN DEFAULT true NOT NULL,
                last_login_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                CONSTRAINT pk_admin_users PRIMARY KEY (id),
                CONSTRAINT uk_admin_users_email UNIQUE (email),
                CONSTRAINT chk_admin_users_email CHECK (email = lower(email)),
                CONSTRAINT chk_admin_users_role CHECK (role IN ('admin', 'editor')),
                CONSTRAINT chk_admin_users_display_name CHECK (btrim(display_name) <> '')
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE portal_users (
                id UUID NOT NULL,
                email VARCHAR(180) NOT NULL,
                password_hash VARCHAR(255) DEFAULT NULL,
                first_name VARCHAR(100) NOT NULL,
                last_name VARCHAR(100) NOT NULL,
                phone VARCHAR(40) DEFAULT NULL,
                email_verified_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                is_active BOOLEAN DEFAULT true NOT NULL,
                last_login_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                CONSTRAINT pk_portal_users PRIMARY KEY (id),
                CONSTRAINT uk_portal_users_email UNIQUE (email),
                CONSTRAINT chk_portal_users_email CHECK (email = lower(email))
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE admin_audit_log (
                id UUID NOT NULL,
                actor_type VARCHAR(20) NOT NULL,
                actor_id UUID DEFAULT NULL,
                command VARCHAR(200) NOT NULL,
                subject_type VARCHAR(50) NOT NULL,
                subject_id VARCHAR(64) NOT NULL,
                changes JSONB NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                portal_user_id UUID DEFAULT NULL,
                CONSTRAINT pk_admin_audit_log PRIMARY KEY (id),
                CONSTRAINT fk_admin_audit_log_actor_id FOREIGN KEY (actor_id) REFERENCES admin_users (id) ON DELETE RESTRICT,
                CONSTRAINT chk_admin_audit_log_actor CHECK ((actor_type = 'admin_user') = (actor_id IS NOT NULL)),
                CONSTRAINT chk_admin_audit_log_changes CHECK (jsonb_typeof(changes) = 'object'),
                CONSTRAINT fk_admin_audit_log_portal_user_id FOREIGN KEY (portal_user_id) REFERENCES portal_users (id) ON DELETE SET NULL,
                CONSTRAINT chk_admin_audit_log_actor_type CHECK (actor_type IN ('admin_user', 'console', 'system', 'portal_user')),
                CONSTRAINT chk_admin_audit_log_portal_user CHECK (portal_user_id IS NULL OR actor_type = 'portal_user')
            )
            SQL);
        $this->addSql('CREATE INDEX idx_admin_audit_log_actor_id ON admin_audit_log (actor_id)');
        $this->addSql('CREATE INDEX idx_admin_audit_log_subject ON admin_audit_log (subject_type, subject_id, created_at)');
        $this->addSql('CREATE INDEX idx_admin_audit_log_created_at ON admin_audit_log (created_at)');
        $this->addSql('CREATE INDEX idx_admin_audit_log_portal_user_id ON admin_audit_log (portal_user_id)');

        // Inquiries (#14).
        $this->addSql(<<<'SQL'
            CREATE TABLE inquiries (
                id UUID NOT NULL,
                venue_id UUID DEFAULT NULL,
                venue_number INTEGER NOT NULL,
                venue_name VARCHAR(150) NOT NULL,
                venue_slug VARCHAR(160) NOT NULL,
                recipient_email VARCHAR(254) NOT NULL,
                delivered_to VARCHAR(254) DEFAULT NULL,
                locale VARCHAR(2) NOT NULL,
                occasion VARCHAR(30) NOT NULL,
                guest_count INTEGER NOT NULL,
                event_date DATE DEFAULT NULL,
                message TEXT NOT NULL,
                first_name VARCHAR(100) NOT NULL,
                last_name VARCHAR(100) NOT NULL,
                email VARCHAR(254) NOT NULL,
                phone VARCHAR(40) DEFAULT NULL,
                fingerprint VARCHAR(64) NOT NULL,
                venue_mail_status VARCHAR(10) DEFAULT 'pending' NOT NULL,
                guest_mail_status VARCHAR(10) DEFAULT 'pending' NOT NULL,
                venue_mail_sent_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                CONSTRAINT pk_inquiries PRIMARY KEY (id),
                CONSTRAINT fk_inquiries_venue_id FOREIGN KEY (venue_id) REFERENCES venues (id) ON DELETE SET NULL,
                CONSTRAINT chk_inquiries_locale CHECK (locale ~ '^[a-z]{2}$'),
                CONSTRAINT chk_inquiries_occasion CHECK (occasion IN ('wedding', 'civil_wedding', 'wedding_aperitif', 'engagement', 'other')),
                CONSTRAINT chk_inquiries_guest_count CHECK (guest_count BETWEEN 1 AND 5000),
                CONSTRAINT chk_inquiries_message CHECK (char_length(btrim(message)) BETWEEN 1 AND 5000),
                CONSTRAINT chk_inquiries_email CHECK (email ~ '^[^@\s]+@[^@\s]+$'),
                CONSTRAINT chk_inquiries_fingerprint CHECK (fingerprint ~ '^[0-9a-f]{64}$'),
                CONSTRAINT chk_inquiries_venue_mail_status CHECK (venue_mail_status IN ('pending', 'sent', 'failed', 'skipped')),
                CONSTRAINT chk_inquiries_guest_mail_status CHECK (guest_mail_status IN ('pending', 'sent', 'failed', 'skipped'))
            )
            SQL);
        $this->addSql('CREATE INDEX idx_inquiries_venue_id ON inquiries (venue_id)');
        $this->addSql('CREATE INDEX idx_inquiries_created_at ON inquiries (created_at)');
        $this->addSql('CREATE INDEX idx_inquiries_fingerprint_created_at ON inquiries (fingerprint, created_at)');

        // CRM (#27, #28, #29, #30, #55, #56).
        $this->addSql(<<<'SQL'
            CREATE TABLE crm_companies (
                id UUID NOT NULL,
                name VARCHAR(150) NOT NULL,
                legal_name VARCHAR(200) DEFAULT NULL,
                website VARCHAR(500) DEFAULT NULL,
                phone VARCHAR(40) DEFAULT NULL,
                email VARCHAR(254) DEFAULT NULL,
                address VARCHAR(300) DEFAULT NULL,
                stage VARCHAR(20) DEFAULT 'prospect' NOT NULL,
                owner_id UUID DEFAULT NULL,
                notes TEXT DEFAULT NULL,
                origin VARCHAR(10) NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                venue_id UUID DEFAULT NULL,
                CONSTRAINT pk_crm_companies PRIMARY KEY (id),
                CONSTRAINT fk_crm_companies_owner_id FOREIGN KEY (owner_id) REFERENCES admin_users (id) ON DELETE RESTRICT,
                CONSTRAINT chk_crm_companies_name CHECK (btrim(name) <> ''),
                CONSTRAINT chk_crm_companies_stage CHECK (stage IN ('prospect', 'customer', 'former_customer')),
                CONSTRAINT chk_crm_companies_origin CHECK (origin IN ('manual', 'venue')),
                CONSTRAINT uk_crm_companies_venue_id UNIQUE (venue_id),
                CONSTRAINT fk_crm_companies_venue_id FOREIGN KEY (venue_id) REFERENCES venues (id) ON DELETE SET NULL
            )
            SQL);
        $this->addSql('CREATE INDEX idx_crm_companies_owner_id ON crm_companies (owner_id)');
        $this->addSql('CREATE INDEX idx_crm_companies_search_key ON crm_companies USING gin (geo_search_key(name) gin_trgm_ops)');
        $this->addSql(<<<'SQL'
            CREATE TABLE crm_contacts (
                id UUID NOT NULL,
                first_name VARCHAR(150) DEFAULT NULL,
                last_name VARCHAR(150) NOT NULL,
                email VARCHAR(254) DEFAULT NULL,
                phone VARCHAR(40) DEFAULT NULL,
                position VARCHAR(150) DEFAULT NULL,
                company_id UUID NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                CONSTRAINT pk_crm_contacts PRIMARY KEY (id),
                CONSTRAINT chk_crm_contacts_last_name CHECK (btrim(last_name) <> ''),
                CONSTRAINT chk_crm_contacts_email_lower CHECK (email = lower(email)),
                CONSTRAINT uk_crm_contacts_company_email UNIQUE (company_id, email),
                CONSTRAINT fk_crm_contacts_company_id FOREIGN KEY (company_id) REFERENCES crm_companies (id) ON DELETE CASCADE
            )
            SQL);
        $this->addSql('CREATE INDEX idx_crm_contacts_company_id ON crm_contacts (company_id)');
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_crm_contacts_search_key ON crm_contacts USING gin (geo_search_key(coalesce(first_name, '') || ' ' || last_name) gin_trgm_ops)
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE crm_activity_types (
                code VARCHAR(30) NOT NULL,
                position SMALLINT NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                CONSTRAINT pk_crm_activity_types PRIMARY KEY (code),
                CONSTRAINT uk_crm_activity_types_position UNIQUE (position),
                CONSTRAINT chk_crm_activity_types_code CHECK (code ~ '^[a-z][a-z0-9_]*$'),
                CONSTRAINT chk_crm_activity_types_position CHECK (position > 0)
            )
            SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO crm_activity_types (code, position) VALUES
                ('note', 1),
                ('email_logged', 2),
                ('call', 3),
                ('meeting', 4),
                ('deal_stage_changed', 5),
                ('inquiry', 6),
                ('email_sent', 7)
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE crm_activities (
                id UUID NOT NULL,
                type_code VARCHAR(30) NOT NULL,
                occurred_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                actor_id UUID DEFAULT NULL,
                title VARCHAR(200) DEFAULT NULL,
                body TEXT DEFAULT NULL,
                payload JSONB DEFAULT '{}' NOT NULL,
                anonymised_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                CONSTRAINT pk_crm_activities PRIMARY KEY (id),
                CONSTRAINT fk_crm_activities_type_code FOREIGN KEY (type_code) REFERENCES crm_activity_types (code) ON DELETE RESTRICT,
                CONSTRAINT fk_crm_activities_actor_id FOREIGN KEY (actor_id) REFERENCES admin_users (id) ON DELETE RESTRICT,
                CONSTRAINT chk_crm_activities_payload CHECK (jsonb_typeof(payload) = 'object'),
                CONSTRAINT chk_crm_activities_content CHECK (anonymised_at IS NOT NULL OR btrim(coalesce(title, '')) <> '' OR btrim(coalesce(body, '')) <> ''),
                CONSTRAINT chk_crm_activities_anonymised CHECK (anonymised_at IS NULL OR (title IS NULL AND body IS NULL AND payload = '{}'::jsonb))
            )
            SQL);
        $this->addSql('CREATE INDEX idx_crm_activities_type_code ON crm_activities (type_code)');
        $this->addSql('CREATE INDEX idx_crm_activities_actor_id ON crm_activities (actor_id)');
        $this->addSql('CREATE INDEX idx_crm_activities_occurred_at_id ON crm_activities (occurred_at, id)');
        $this->addSql(<<<'SQL'
            CREATE TABLE crm_activity_companies (
                activity_id UUID NOT NULL,
                company_id UUID NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                CONSTRAINT pk_crm_activity_companies PRIMARY KEY (activity_id, company_id),
                CONSTRAINT fk_crm_activity_companies_activity_id FOREIGN KEY (activity_id) REFERENCES crm_activities (id) ON DELETE CASCADE,
                CONSTRAINT fk_crm_activity_companies_company_id FOREIGN KEY (company_id) REFERENCES crm_companies (id) ON DELETE CASCADE
            )
            SQL);
        $this->addSql('CREATE INDEX idx_crm_activity_companies_activity_id ON crm_activity_companies (activity_id)');
        $this->addSql('CREATE INDEX idx_crm_activity_companies_company_id ON crm_activity_companies (company_id)');
        $this->addSql(<<<'SQL'
            CREATE TABLE crm_deal_stages (
                code VARCHAR(30) NOT NULL,
                position SMALLINT NOT NULL,
                probability SMALLINT NOT NULL,
                is_won BOOLEAN DEFAULT FALSE NOT NULL,
                is_lost BOOLEAN DEFAULT FALSE NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                CONSTRAINT pk_crm_deal_stages PRIMARY KEY (code),
                CONSTRAINT uk_crm_deal_stages_position UNIQUE (position),
                CONSTRAINT chk_crm_deal_stages_code CHECK (code ~ '^[a-z][a-z0-9_]*$'),
                CONSTRAINT chk_crm_deal_stages_position CHECK (position > 0),
                CONSTRAINT chk_crm_deal_stages_probability CHECK (probability BETWEEN 0 AND 100),
                CONSTRAINT chk_crm_deal_stages_outcome CHECK (NOT (is_won AND is_lost))
            )
            SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO crm_deal_stages (code, position, probability, is_won, is_lost) VALUES
                ('new', 1, 10, FALSE, FALSE),
                ('contacted', 2, 25, FALSE, FALSE),
                ('offer', 3, 50, FALSE, FALSE),
                ('negotiation', 4, 75, FALSE, FALSE),
                ('won', 5, 100, TRUE, FALSE),
                ('lost', 6, 0, FALSE, TRUE)
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uk_crm_deal_stages_won ON crm_deal_stages (is_won) WHERE is_won');
        $this->addSql('CREATE UNIQUE INDEX uk_crm_deal_stages_lost ON crm_deal_stages (is_lost) WHERE is_lost');
        $this->addSql(<<<'SQL'
            CREATE TABLE crm_deals (
                id UUID NOT NULL,
                name VARCHAR(150) NOT NULL,
                company_id UUID NOT NULL,
                venue_id UUID DEFAULT NULL,
                tier VARCHAR(20) DEFAULT NULL,
                amount_rappen BIGINT DEFAULT NULL,
                contract_start_on DATE DEFAULT NULL,
                contract_end_on DATE DEFAULT NULL,
                expected_close_on DATE DEFAULT NULL,
                stage_code VARCHAR(30) NOT NULL,
                owner_id UUID DEFAULT NULL,
                lost_reason VARCHAR(30) DEFAULT NULL,
                closed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                renewed_from_id UUID DEFAULT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                CONSTRAINT pk_crm_deals PRIMARY KEY (id),
                CONSTRAINT fk_crm_deals_company_id FOREIGN KEY (company_id) REFERENCES crm_companies (id) ON DELETE RESTRICT,
                CONSTRAINT fk_crm_deals_venue_id FOREIGN KEY (venue_id) REFERENCES venues (id) ON DELETE SET NULL,
                CONSTRAINT fk_crm_deals_stage_code FOREIGN KEY (stage_code) REFERENCES crm_deal_stages (code) ON DELETE RESTRICT,
                CONSTRAINT fk_crm_deals_owner_id FOREIGN KEY (owner_id) REFERENCES admin_users (id) ON DELETE RESTRICT,
                CONSTRAINT fk_crm_deals_renewed_from_id FOREIGN KEY (renewed_from_id) REFERENCES crm_deals (id) ON DELETE SET NULL,
                CONSTRAINT uk_crm_deals_renewed_from_id UNIQUE (renewed_from_id),
                CONSTRAINT chk_crm_deals_name CHECK (btrim(name) <> ''),
                CONSTRAINT chk_crm_deals_tier CHECK (tier IN ('beliebte_location', 'empfehlung', 'top_premium', 'premium', 'basic')),
                CONSTRAINT chk_crm_deals_amount CHECK (amount_rappen >= 0),
                CONSTRAINT chk_crm_deals_contract CHECK (contract_end_on >= contract_start_on),
                CONSTRAINT chk_crm_deals_lost_reason CHECK (lost_reason IN ('budget', 'competitor', 'no_need', 'no_response', 'other'))
            )
            SQL);
        $this->addSql('CREATE INDEX idx_crm_deals_company_id ON crm_deals (company_id)');
        $this->addSql('CREATE INDEX idx_crm_deals_venue_id ON crm_deals (venue_id)');
        $this->addSql('CREATE INDEX idx_crm_deals_stage_code ON crm_deals (stage_code)');
        $this->addSql('CREATE INDEX idx_crm_deals_owner_id ON crm_deals (owner_id)');
        $this->addSql('CREATE INDEX idx_crm_deals_contract_end_on ON crm_deals (contract_end_on)');
        $this->addSql('CREATE INDEX idx_crm_deals_search_key ON crm_deals USING gin (geo_search_key(name) gin_trgm_ops)');
        $this->addSql(<<<'SQL'
            CREATE TABLE crm_activity_deals (
                activity_id UUID NOT NULL,
                deal_id UUID NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                CONSTRAINT pk_crm_activity_deals PRIMARY KEY (activity_id, deal_id),
                CONSTRAINT fk_crm_activity_deals_activity_id FOREIGN KEY (activity_id) REFERENCES crm_activities (id) ON DELETE CASCADE,
                CONSTRAINT fk_crm_activity_deals_deal_id FOREIGN KEY (deal_id) REFERENCES crm_deals (id) ON DELETE CASCADE
            )
            SQL);
        $this->addSql('CREATE INDEX idx_crm_activity_deals_activity_id ON crm_activity_deals (activity_id)');
        $this->addSql('CREATE INDEX idx_crm_activity_deals_deal_id ON crm_activity_deals (deal_id)');
        $this->addSql(<<<'SQL'
            CREATE TABLE crm_tasks (
                id UUID NOT NULL,
                title VARCHAR(200) NOT NULL,
                due_on DATE NOT NULL,
                assignee_id UUID NOT NULL,
                created_by_id UUID DEFAULT NULL,
                done_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                company_id UUID DEFAULT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                deal_id UUID DEFAULT NULL,
                is_renewal BOOLEAN DEFAULT FALSE NOT NULL,
                CONSTRAINT pk_crm_tasks PRIMARY KEY (id),
                CONSTRAINT fk_crm_tasks_assignee_id FOREIGN KEY (assignee_id) REFERENCES admin_users (id) ON DELETE RESTRICT,
                CONSTRAINT fk_crm_tasks_created_by_id FOREIGN KEY (created_by_id) REFERENCES admin_users (id) ON DELETE RESTRICT,
                CONSTRAINT fk_crm_tasks_company_id FOREIGN KEY (company_id) REFERENCES crm_companies (id) ON DELETE CASCADE,
                CONSTRAINT chk_crm_tasks_title CHECK (btrim(title) <> ''),
                CONSTRAINT fk_crm_tasks_deal_id FOREIGN KEY (deal_id) REFERENCES crm_deals (id) ON DELETE CASCADE,
                CONSTRAINT chk_crm_tasks_renewal CHECK (NOT is_renewal OR deal_id IS NOT NULL),
                CONSTRAINT chk_crm_tasks_one_object CHECK (num_nonnulls(company_id, deal_id) <= 1)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_crm_tasks_assignee_id ON crm_tasks (assignee_id)');
        $this->addSql('CREATE INDEX idx_crm_tasks_created_by_id ON crm_tasks (created_by_id)');
        $this->addSql('CREATE INDEX idx_crm_tasks_company_id ON crm_tasks (company_id)');
        $this->addSql('CREATE INDEX idx_crm_tasks_due_on ON crm_tasks (due_on)');
        $this->addSql('CREATE INDEX idx_crm_tasks_deal_id ON crm_tasks (deal_id)');
        $this->addSql('CREATE UNIQUE INDEX uk_crm_tasks_renewal ON crm_tasks (deal_id) WHERE is_renewal');
        $this->addSql(<<<'SQL'
            CREATE TABLE crm_inquiry_links (
                inquiry_id UUID NOT NULL,
                activity_id UUID DEFAULT NULL,
                status VARCHAR(10) DEFAULT 'new' NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                CONSTRAINT pk_crm_inquiry_links PRIMARY KEY (inquiry_id),
                CONSTRAINT fk_crm_inquiry_links_inquiry_id FOREIGN KEY (inquiry_id) REFERENCES inquiries (id) ON DELETE CASCADE,
                CONSTRAINT fk_crm_inquiry_links_activity_id FOREIGN KEY (activity_id) REFERENCES crm_activities (id) ON DELETE SET NULL,
                CONSTRAINT chk_crm_inquiry_links_status CHECK (status IN ('new', 'seen', 'done'))
            )
            SQL);
        $this->addSql('CREATE INDEX idx_crm_inquiry_links_status ON crm_inquiry_links (status)');
        $this->addSql('CREATE INDEX idx_crm_inquiry_links_activity_id ON crm_inquiry_links (activity_id)');
        $this->addSql(<<<'SQL'
            CREATE TABLE crm_email_templates (
                id UUID NOT NULL,
                name VARCHAR(100) NOT NULL,
                subject VARCHAR(200) NOT NULL,
                body_markdown TEXT NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                CONSTRAINT pk_crm_email_templates PRIMARY KEY (id),
                CONSTRAINT uk_crm_email_templates_name UNIQUE (name),
                CONSTRAINT chk_crm_email_templates_content CHECK (btrim(name) <> '' AND btrim(subject) <> '' AND btrim(body_markdown) <> '')
            )
            SQL);

        // Portal (#42, #45).
        $this->addSql(<<<'SQL'
            CREATE TABLE portal_venue_access (
                portal_user_id UUID NOT NULL,
                venue_id UUID NOT NULL,
                status VARCHAR(20) NOT NULL,
                requested_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                confirmed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                CONSTRAINT pk_portal_venue_access PRIMARY KEY (portal_user_id, venue_id),
                CONSTRAINT fk_portal_venue_access_portal_user_id FOREIGN KEY (portal_user_id) REFERENCES portal_users (id) ON DELETE CASCADE,
                CONSTRAINT fk_portal_venue_access_venue_id FOREIGN KEY (venue_id) REFERENCES venues (id) ON DELETE CASCADE,
                CONSTRAINT chk_portal_venue_access_status CHECK (status IN ('pending', 'active')),
                CONSTRAINT chk_portal_venue_access_confirmed CHECK ((status = 'active') = (confirmed_at IS NOT NULL))
            )
            SQL);
        $this->addSql('CREATE INDEX idx_portal_venue_access_portal_user_id ON portal_venue_access (portal_user_id)');
        $this->addSql('CREATE INDEX idx_portal_venue_access_venue_id ON portal_venue_access (venue_id)');
        $this->addSql(<<<'SQL'
            CREATE TABLE portal_submissions (
                venue_id UUID NOT NULL,
                status VARCHAR(10) DEFAULT 'draft' NOT NULL,
                submitted_by UUID DEFAULT NULL,
                submitted_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                rights_confirmed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                reviewed_by UUID DEFAULT NULL,
                reviewed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                reject_reason VARCHAR(1000) DEFAULT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                CONSTRAINT pk_portal_submissions PRIMARY KEY (venue_id),
                CONSTRAINT fk_portal_submissions_venue_id FOREIGN KEY (venue_id) REFERENCES venues (id) ON DELETE CASCADE,
                CONSTRAINT fk_portal_submissions_submitted_by FOREIGN KEY (submitted_by) REFERENCES portal_users (id) ON DELETE SET NULL,
                CONSTRAINT fk_portal_submissions_reviewed_by FOREIGN KEY (reviewed_by) REFERENCES admin_users (id) ON DELETE RESTRICT,
                CONSTRAINT chk_portal_submissions_status CHECK (status IN ('draft', 'submitted', 'rejected', 'accepted'))
            )
            SQL);
        $this->addSql('CREATE INDEX idx_portal_submissions_status ON portal_submissions (status)');
        $this->addSql('CREATE INDEX idx_portal_submissions_submitted_by ON portal_submissions (submitted_by)');
        $this->addSql('CREATE INDEX idx_portal_submissions_reviewed_by ON portal_submissions (reviewed_by)');

        // Statistics (#47).
        $this->addSql(<<<'SQL'
            CREATE TABLE stats_ingest_files (
                id UUID NOT NULL,
                file_name VARCHAR(64) NOT NULL,
                events INTEGER NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                CONSTRAINT pk_stats_ingest_files PRIMARY KEY (id),
                CONSTRAINT uk_stats_ingest_files_file_name UNIQUE (file_name),
                CONSTRAINT chk_stats_ingest_files_events CHECK (events >= 0)
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE stats_events (
                id UUID NOT NULL,
                occurred_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                type VARCHAR(20) NOT NULL,
                kind VARCHAR(10) DEFAULT NULL,
                page VARCHAR(10) DEFAULT NULL,
                venues JSONB DEFAULT NULL,
                path VARCHAR(255) DEFAULT NULL,
                referrer_host VARCHAR(255) DEFAULT NULL,
                user_agent VARCHAR(255) DEFAULT NULL,
                visitor_hash VARCHAR(32) DEFAULT NULL,
                automated BOOLEAN DEFAULT false NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                CONSTRAINT pk_stats_events PRIMARY KEY (id),
                CONSTRAINT chk_stats_events_type CHECK (type IN ('page_view', 'impression', 'click', 'merkliste_add', 'inquiry')),
                CONSTRAINT chk_stats_events_kind CHECK (kind IN ('website', 'email', 'phone', 'map', 'instagram')),
                CONSTRAINT chk_stats_events_click_kind CHECK ((type = 'click') = (kind IS NOT NULL)),
                CONSTRAINT chk_stats_events_page CHECK (page IN ('home', 'listing', 'landing', 'detail', 'merkliste', 'other'))
            )
            SQL);
        $this->addSql('CREATE INDEX idx_stats_events_occurred_at ON stats_events (occurred_at)');
        $this->addSql(<<<'SQL'
            CREATE TABLE stats_daily (
                day DATE NOT NULL,
                venue_number INTEGER NOT NULL,
                metric VARCHAR(30) NOT NULL,
                events INTEGER NOT NULL,
                visitors INTEGER NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                CONSTRAINT pk_stats_daily PRIMARY KEY (day, venue_number, metric),
                CONSTRAINT chk_stats_daily_venue_number CHECK (venue_number >= 0),
                CONSTRAINT chk_stats_daily_metric CHECK (metric IN (
                    'page_view', 'page_view_home', 'page_view_listing', 'page_view_landing', 'page_view_detail',
                    'page_view_merkliste', 'page_view_other', 'detail_view', 'impression', 'click_website',
                    'click_email', 'click_phone', 'click_map', 'click_instagram', 'merkliste_add', 'inquiry'
                )),
                CONSTRAINT chk_stats_daily_events CHECK (events >= 0),
                CONSTRAINT chk_stats_daily_visitors CHECK (visitors >= 0)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_stats_daily_venue_number_day ON stats_daily (venue_number, day)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE stats_daily');
        $this->addSql('DROP TABLE stats_events');
        $this->addSql('DROP TABLE stats_ingest_files');
        $this->addSql('DROP TABLE portal_submissions');
        $this->addSql('DROP TABLE portal_venue_access');
        $this->addSql('DROP TABLE crm_email_templates');
        $this->addSql('DROP TABLE crm_inquiry_links');
        $this->addSql('DROP TABLE crm_tasks');
        $this->addSql('DROP TABLE crm_activity_deals');
        $this->addSql('DROP TABLE crm_deals');
        $this->addSql('DROP TABLE crm_deal_stages');
        $this->addSql('DROP TABLE crm_activity_companies');
        $this->addSql('DROP TABLE crm_activities');
        $this->addSql('DROP TABLE crm_activity_types');
        $this->addSql('DROP TABLE crm_contacts');
        $this->addSql('DROP TABLE crm_companies');
        $this->addSql('DROP TABLE inquiries');
        $this->addSql('DROP TABLE admin_audit_log');
        $this->addSql('DROP TABLE portal_users');
        $this->addSql('DROP TABLE admin_users');
        $this->addSql('DROP TABLE content_blocks');
        $this->addSql('DROP TABLE venue_deleted_content_keys');
        $this->addSql('DROP TABLE content_imports');
        $this->addSql('DROP TABLE venue_photo_translations');
        $this->addSql('DROP TABLE venue_photos');
        $this->addSql('DROP TABLE venue_document_translations');
        $this->addSql('DROP TABLE venue_documents');
        $this->addSql('DROP TABLE room_translations');
        $this->addSql('DROP TABLE rooms');
        $this->addSql('DROP TABLE venue_terms');
        $this->addSql('DROP TABLE taxonomy_terms');
        $this->addSql('DROP TABLE venue_slug_history');
        $this->addSql('DROP TABLE venue_translations');
        $this->addSql('DROP TABLE venues');
        $this->addSql('DROP TABLE town_zips');
        $this->addSql('DROP TABLE towns');
        $this->addSql('DROP TABLE cantons');
        $this->addSql('DROP FUNCTION geo_search_key(text)');
        $this->addSql('DROP FUNCTION geo_fold_de(text)');
        $this->addSql('DROP FUNCTION geo_fold(text)');
        $this->addSql('DROP EXTENSION IF EXISTS earthdistance');
        $this->addSql('DROP EXTENSION IF EXISTS cube');
        $this->addSql('DROP EXTENSION IF EXISTS unaccent');
        $this->addSql('DROP EXTENSION IF EXISTS pg_trgm');
    }
}
