<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * G15 `video-embed`: a venue's YouTube and Vimeo videos, ordered by position like its documents. Only the provider and
 * the provider's video id are stored, never a URL or embed HTML (`Venues\ValueObject\VideoEmbed` parses the URL staff or
 * a manager paste). The CHECKs pin the id shape per provider, so a crafted id never reaches an iframe's `src`. How many
 * the page shows is the tier's `videos_max` (F12); rows above it stay stored and hidden.
 */
final class Version20261108120915 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'G15 video-embed: venue_videos (provider + video id, ordered)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE venue_videos (
                id UUID NOT NULL,
                venue_id UUID NOT NULL,
                position SMALLINT NOT NULL,
                provider VARCHAR(16) NOT NULL,
                video_id VARCHAR(32) NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                CONSTRAINT pk_venue_videos PRIMARY KEY (id),
                CONSTRAINT uk_venue_videos_venue_id_position UNIQUE (venue_id, position),
                CONSTRAINT uk_venue_videos_venue_id_provider_video_id UNIQUE (venue_id, provider, video_id),
                CONSTRAINT chk_venue_videos_position CHECK (position > 0),
                CONSTRAINT chk_venue_videos_provider CHECK (provider IN ('youtube', 'vimeo')),
                CONSTRAINT chk_venue_videos_video_id CHECK (
                    (provider = 'youtube' AND video_id ~ '^[A-Za-z0-9_-]{11}$')
                    OR (provider = 'vimeo' AND video_id ~ '^[1-9][0-9]{0,11}$')
                ),
                CONSTRAINT fk_venue_videos_venue_id FOREIGN KEY (venue_id) REFERENCES venues (id) ON DELETE CASCADE
            )
            SQL);
        $this->addSql('CREATE INDEX idx_venue_videos_venue_id ON venue_videos (venue_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE venue_videos');
    }
}
