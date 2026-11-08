<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * G14 `last-minute-offers`: the short-notice offers of a Pro venue («Kurzfristig frei», D55).
 *
 * An offer is one day or a period of at most 14 days (`ends_on` is the last day; a one-day offer has `ends_on =
 * starts_on`). It is visible through its last Zurich day and deleted by the nightly job the day after, so there is no
 * separate expiry column. A room is optional (none = the whole location); the event types are `suitable_for` codes of
 * the venue, checked by the handler. A deleted venue or room takes its offers with it.
 */
final class Version20261108174229 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'G14: last_minute_offers («Kurzfristig frei»)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE last_minute_offers (
                id UUID NOT NULL,
                venue_id UUID NOT NULL,
                room_id UUID DEFAULT NULL,
                starts_on DATE NOT NULL,
                ends_on DATE NOT NULL,
                event_types JSONB DEFAULT '[]' NOT NULL,
                price_text VARCHAR(150) NOT NULL,
                min_guests INT DEFAULT NULL,
                terms VARCHAR(500) DEFAULT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                CONSTRAINT pk_last_minute_offers PRIMARY KEY (id),
                CONSTRAINT fk_last_minute_offers_venue_id FOREIGN KEY (venue_id) REFERENCES venues (id) ON DELETE CASCADE,
                CONSTRAINT fk_last_minute_offers_room_id FOREIGN KEY (room_id) REFERENCES rooms (id) ON DELETE CASCADE,
                CONSTRAINT chk_last_minute_offers_period CHECK (ends_on >= starts_on),
                CONSTRAINT chk_last_minute_offers_length CHECK (ends_on - starts_on <= 13),
                CONSTRAINT chk_last_minute_offers_price CHECK (btrim(price_text) <> ''),
                CONSTRAINT chk_last_minute_offers_terms CHECK (terms IS NULL OR btrim(terms) <> ''),
                CONSTRAINT chk_last_minute_offers_guests CHECK (min_guests IS NULL OR min_guests BETWEEN 1 AND 5000),
                CONSTRAINT chk_last_minute_offers_event_types CHECK (jsonb_typeof(event_types) = 'array')
            )
            SQL);
        $this->addSql('CREATE INDEX idx_last_minute_offers_venue_id ON last_minute_offers (venue_id)');
        $this->addSql('CREATE INDEX idx_last_minute_offers_room_id ON last_minute_offers (room_id)');
        $this->addSql('CREATE INDEX idx_last_minute_offers_ends_on ON last_minute_offers (ends_on)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE last_minute_offers');
    }
}
