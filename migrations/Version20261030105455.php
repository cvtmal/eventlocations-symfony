<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Geo reference data (#3): cantons (26 rows, seeded here), towns and town_zips.
 *
 * unaccent() is STABLE, so it cannot appear in an index expression. geo_fold() and
 * geo_fold_de() wrap it as IMMUTABLE SQL functions (the dictionary is named explicitly),
 * and geo_search_key() joins the German fold (ü → ue) and the accent-only fold (ü → u),
 * so "zuer", "Zuerich" and "Zurich" all find Zürich. Queries must fold through the same
 * functions so they hit idx_towns_search_key.
 *
 * Both expression indexes are invisible to DBAL's schema introspection and therefore not
 * mapped on the entities. towns are filled by app:geo:import-towns, not by this migration.
 * Canton centroids: inner points of the BFS K4 2026-01-01 canton layer (assets/maps/LICENSES.md).
 */
final class Version20261030105455 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Geo reference data: fold functions, cantons (seeded), towns, town_zips';
    }

    public function up(Schema $schema): void
    {
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
                CONSTRAINT chk_cantons_bfs_number CHECK (bfs_number BETWEEN 1 AND 26),
                CONSTRAINT chk_cantons_svg_id CHECK (svg_id = 'ch-' || code)
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
                ('ju', 26, 'jura', 'ch-ju', 47.3666, 7.1603)
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
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE town_zips');
        $this->addSql('DROP TABLE towns');
        $this->addSql('DROP TABLE cantons');
        $this->addSql('DROP FUNCTION geo_search_key(text)');
        $this->addSql('DROP FUNCTION geo_fold_de(text)');
        $this->addSql('DROP FUNCTION geo_fold(text)');
    }
}
