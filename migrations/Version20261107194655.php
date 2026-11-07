<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * G5 `preview-link`: the private preview links of an entry (`App\Venues\Entity\VenuePreview`). Staff
 * issue one per campaign; the link's token is derived from the row id and `kernel.secret`
 * (`App\Venues\PreviewToken`), so no token is stored. A link answers until `expires_at` and stops for good
 * with `revoked_at`; deleting the venue deletes its links. The campaign code follows the same plain-code
 * rule as `funnel_events.campaign_code` (`App\Shared\Funnel\CampaignCode`), so it can carry no e-mail address.
 */
final class Version20261107194655 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'G5: venue_previews, signed and expiring preview links of an entry (Venues)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE venue_previews (
                id UUID NOT NULL,
                venue_id UUID NOT NULL,
                campaign_code VARCHAR(40) NOT NULL,
                expires_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                revoked_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                CONSTRAINT pk_venue_previews PRIMARY KEY (id),
                CONSTRAINT fk_venue_previews_venue_id FOREIGN KEY (venue_id) REFERENCES venues (id) ON DELETE CASCADE,
                CONSTRAINT chk_venue_previews_campaign_code CHECK (campaign_code ~ '^[A-Za-z0-9][A-Za-z0-9_-]{0,39}$'),
                CONSTRAINT chk_venue_previews_expiry CHECK (expires_at > created_at)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_venue_previews_venue_id ON venue_previews (venue_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE venue_previews');
    }
}
