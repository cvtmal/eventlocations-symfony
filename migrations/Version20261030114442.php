<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Venue data model (#4): venues and their owned rows (translations, slug history, terms,
 * rating highlights, reviews, rooms, documents, photos), plus the taxonomy_terms vocabulary
 * (90 rows, seeded here).
 *
 * Owned rows reference their owner ON DELETE CASCADE; references out of the aggregate
 * (towns, cantons, taxonomy_terms) are ON DELETE RESTRICT.
 *
 * - venues.number is the stable public number (Merkliste id, S7), not the primary key.
 * - venues.tier_rank is derived from tier with the ranks of App\Venues\ValueObject\Tier, so
 *   listings can ORDER BY it (S6).
 * - The slug lives in venue_translations, one per locale (S4); there is no venues.slug.
 * - The "main" building (and setting) is venue_terms.is_main, at most one per venue and group.
 * - taxonomy_terms.primary_position orders the 11 building chips shown before
 *   "Alle Gebäudearten" (FI-4); NULL for all other terms.
 * - venue_photos: one position sequence per venue (room_id NULL) and per room; NULLS NOT
 *   DISTINCT makes the venue-level sequence unique too. #5 adds the variant columns.
 */
final class Version20261030114442 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Venue data model: venues, venue_* tables, rooms, taxonomy_terms (seeded)';
    }

    public function up(Schema $schema): void
    {
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
                zip VARCHAR(4) NOT NULL,
                town_id UUID NOT NULL,
                canton_code VARCHAR(2) NOT NULL,
                lat DOUBLE PRECISION NOT NULL,
                lng DOUBLE PRECISION NOT NULL,
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
                rating_score NUMERIC(3, 1) DEFAULT NULL,
                rating_count INTEGER DEFAULT NULL,
                room_count SMALLINT DEFAULT NULL,
                largest_room_m2 INTEGER DEFAULT NULL,
                max_room_height_m NUMERIC(4, 2) DEFAULT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
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
                CONSTRAINT chk_venues_rating CHECK ((rating_score IS NULL) = (rating_count IS NULL) AND (rating_score IS NULL OR rating_score BETWEEN 0 AND 10)),
                CONSTRAINT chk_venues_rating_count CHECK (rating_count >= 0),
                CONSTRAINT chk_venues_room_count CHECK (room_count >= 0),
                CONSTRAINT chk_venues_largest_room_m2 CHECK (largest_room_m2 BETWEEN 1 AND 50000),
                CONSTRAINT chk_venues_max_room_height_m CHECK (max_room_height_m > 0 AND max_room_height_m <= 30),
                CONSTRAINT fk_venues_town_id FOREIGN KEY (town_id) REFERENCES towns (id) ON DELETE RESTRICT,
                CONSTRAINT fk_venues_canton_code FOREIGN KEY (canton_code) REFERENCES cantons (code) ON DELETE RESTRICT
            )
            SQL);
        $this->addSql('CREATE INDEX idx_venues_town_id ON venues (town_id)');
        $this->addSql('CREATE INDEX idx_venues_canton_code ON venues (canton_code)');

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
                CONSTRAINT chk_taxonomy_terms_term_group CHECK (term_group IN ('setting', 'building', 'suitable_for', 'infrastructure', 'furniture', 'technology', 'surroundings')),
                CONSTRAINT chk_taxonomy_terms_code CHECK (code ~ '^[a-z][a-z0-9_]*$'),
                CONSTRAINT chk_taxonomy_terms_position CHECK (position > 0),
                CONSTRAINT chk_taxonomy_terms_primary_position CHECK (primary_position IS NULL OR primary_position > 0)
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
                ('surroundings', 'golf', 6, NULL)
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
            CREATE TABLE venue_rating_highlights (
                venue_id UUID NOT NULL,
                position SMALLINT NOT NULL,
                code VARCHAR(20) NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                CONSTRAINT pk_venue_rating_highlights PRIMARY KEY (venue_id, position),
                CONSTRAINT uk_venue_rating_highlights_venue_id_code UNIQUE (venue_id, code),
                CONSTRAINT chk_venue_rating_highlights_position CHECK (position BETWEEN 1 AND 3),
                CONSTRAINT chk_venue_rating_highlights_code CHECK (code IN ('food', 'atmosphere', 'architecture', 'location', 'hospitality', 'reachability', 'equipment', 'programme', 'value')),
                CONSTRAINT fk_venue_rating_highlights_venue_id FOREIGN KEY (venue_id) REFERENCES venues (id) ON DELETE CASCADE
            )
            SQL);
        $this->addSql('CREATE INDEX idx_venue_rating_highlights_venue_id ON venue_rating_highlights (venue_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE venue_reviews (
                id UUID NOT NULL,
                venue_id UUID NOT NULL,
                locale VARCHAR(2) NOT NULL,
                first_name VARCHAR(50) NOT NULL,
                role VARCHAR(20) NOT NULL,
                reviewed_month DATE NOT NULL,
                text VARCHAR(1000) NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                CONSTRAINT pk_venue_reviews PRIMARY KEY (id),
                CONSTRAINT chk_venue_reviews_locale CHECK (locale ~ '^[a-z]{2}$'),
                CONSTRAINT chk_venue_reviews_role CHECK (role IN ('visitor_f', 'visitor_m', 'organiser_f', 'organiser_m')),
                CONSTRAINT chk_venue_reviews_reviewed_month CHECK (EXTRACT(DAY FROM reviewed_month) = 1),
                CONSTRAINT fk_venue_reviews_venue_id FOREIGN KEY (venue_id) REFERENCES venues (id) ON DELETE CASCADE
            )
            SQL);
        $this->addSql('CREATE INDEX idx_venue_reviews_venue_id ON venue_reviews (venue_id)');

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
                CONSTRAINT fk_rooms_venue_id FOREIGN KEY (venue_id) REFERENCES venues (id) ON DELETE CASCADE
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
                CONSTRAINT pk_venue_photos PRIMARY KEY (id),
                CONSTRAINT uk_venue_photos_venue_id_room_id_position UNIQUE NULLS NOT DISTINCT (venue_id, room_id, position),
                CONSTRAINT chk_venue_photos_position CHECK (position > 0),
                CONSTRAINT fk_venue_photos_venue_id FOREIGN KEY (venue_id) REFERENCES venues (id) ON DELETE CASCADE,
                CONSTRAINT fk_venue_photos_room_id FOREIGN KEY (room_id) REFERENCES rooms (id) ON DELETE CASCADE
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
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE venue_photo_translations');
        $this->addSql('DROP TABLE venue_photos');
        $this->addSql('DROP TABLE venue_document_translations');
        $this->addSql('DROP TABLE venue_documents');
        $this->addSql('DROP TABLE room_translations');
        $this->addSql('DROP TABLE rooms');
        $this->addSql('DROP TABLE venue_reviews');
        $this->addSql('DROP TABLE venue_rating_highlights');
        $this->addSql('DROP TABLE venue_terms');
        $this->addSql('DROP TABLE taxonomy_terms');
        $this->addSql('DROP TABLE venue_slug_history');
        $this->addSql('DROP TABLE venue_translations');
        $this->addSql('DROP TABLE venues');
    }
}
