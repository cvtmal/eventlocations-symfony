<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Image variant columns on venue_photos (#5, S9). width/height are the EXIF-oriented
 * original's size, dominant_color the placeholder colour, variants which files exist:
 * `{"widths": [360, 640, 960], "formats": ["avif", "webp", "jpg"]}` (every width in every
 * format, jpg always). #6 owns the indexes on venue_photos; this migration adds none.
 */
final class Version20261030125302 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'venue-media: image variant columns on venue_photos (#5)';
    }

    public function up(Schema $schema): void
    {
        // chk_venue_photos_variants is a CASE: a missing key makes jsonb_typeof() NULL, and a
        // CHECK that evaluates to NULL passes; jsonb_array_length() is reached only for an array.
        // NOT NULL without a default is safe: no photo rows exist yet (#4 created none), and
        // a photo row is written only after its variants exist (generate, then insert).
        $this->addSql(<<<'SQL'
            ALTER TABLE venue_photos
                ADD COLUMN width INTEGER NOT NULL,
                ADD COLUMN height INTEGER NOT NULL,
                ADD COLUMN dominant_color VARCHAR(7) NOT NULL,
                ADD COLUMN variants JSONB NOT NULL,
                ADD CONSTRAINT chk_venue_photos_width CHECK (width > 0),
                ADD CONSTRAINT chk_venue_photos_height CHECK (height > 0),
                ADD CONSTRAINT chk_venue_photos_dominant_color CHECK (dominant_color ~ '^#[0-9a-f]{6}$'),
                ADD CONSTRAINT chk_venue_photos_variants CHECK (
                    CASE
                        WHEN jsonb_typeof(variants) = 'object'
                            AND jsonb_typeof(variants -> 'widths') = 'array'
                            AND jsonb_typeof(variants -> 'formats') = 'array'
                        THEN jsonb_array_length(variants -> 'widths') > 0 AND variants -> 'formats' @> '["jpg"]'
                        ELSE FALSE
                    END
                )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE venue_photos
                DROP CONSTRAINT chk_venue_photos_variants,
                DROP CONSTRAINT chk_venue_photos_dominant_color,
                DROP CONSTRAINT chk_venue_photos_height,
                DROP CONSTRAINT chk_venue_photos_width,
                DROP COLUMN variants,
                DROP COLUMN dominant_color,
                DROP COLUMN height,
                DROP COLUMN width
            SQL);
    }
}
