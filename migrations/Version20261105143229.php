<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The venue's Google place ID and the staff switch that hides its Google rating (#53). The place ID is the only Google
 * value we may store (Maps Platform Terms §3.2.3, Places API policies). There is no unique key: two listings may share
 * one Google place. The switch exists only with a place ID.
 */
final class Version20261105143229 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Google place ID and rating switch on venues (#53)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE venues
                ADD COLUMN google_place_id VARCHAR(255) DEFAULT NULL,
                ADD COLUMN google_rating_hidden BOOLEAN DEFAULT false NOT NULL,
                ADD CONSTRAINT chk_venues_google_place_id CHECK (google_place_id ~ '^[A-Za-z0-9_-]{10,255}$'),
                ADD CONSTRAINT chk_venues_google_rating_hidden CHECK (google_place_id IS NOT NULL OR NOT google_rating_hidden)
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE venues
                DROP CONSTRAINT chk_venues_google_rating_hidden,
                DROP CONSTRAINT chk_venues_google_place_id,
                DROP COLUMN google_rating_hidden,
                DROP COLUMN google_place_id
            SQL);
    }
}
