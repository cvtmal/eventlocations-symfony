<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * G8 `funnel-scorecard`: the event log behind the staff funnel scorecard. One row per funnel step
 * (`App\Shared\Funnel\FunnelEvent`), written by `RecordFunnelEventHandler`. No personal data by
 * construction: the only free columns are a UUID and a campaign code that the CHECK holds to letters,
 * digits, `_` and `-` (no `@`, no `.`, no space). `venue_id` has no foreign key, so a row keeps its
 * history when a venue goes (as `stats_events.venues`). Adding a `FunnelEvent` case needs a migration
 * that replaces `chk_funnel_events_event`; `StatsVocabularyChecksTest` fails until it exists.
 */
final class Version20261107150513 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'G8: funnel_events, the event-level log of the funnel scorecard (Stats)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE funnel_events (
                id UUID NOT NULL,
                occurred_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                event VARCHAR(30) NOT NULL,
                venue_id UUID DEFAULT NULL,
                campaign_code VARCHAR(40) DEFAULT NULL,
                CONSTRAINT pk_funnel_events PRIMARY KEY (id),
                CONSTRAINT chk_funnel_events_event CHECK (event IN (
                    'preview_opened', 'claim_started', 'claim_verified', 'venue_published',
                    'inquiry_delivered', 'pro_paid', 'subscription_cancelled', 'brief_sent'
                )),
                CONSTRAINT chk_funnel_events_campaign_code CHECK (campaign_code ~ '^[A-Za-z0-9][A-Za-z0-9_-]{0,39}$')
            )
            SQL);
        $this->addSql('CREATE INDEX idx_funnel_events_event_occurred_at ON funnel_events (event, occurred_at)');
        $this->addSql('CREATE INDEX idx_funnel_events_campaign_code ON funnel_events (campaign_code)');
        $this->addSql('CREATE INDEX idx_funnel_events_venue_id_occurred_at ON funnel_events (venue_id, occurred_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE funnel_events');
    }
}
