<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * G9 `subscription-term`: the paid term behind a venue's plan.
 *
 * - `subscriptions` (Venues): one row per paid term, `starts_on` to `ends_on` inclusive (Europe/Zurich calendar
 *   days). `EntitlementResolver` gives a venue the tier of the term that covers the day and Free without one;
 *   `venues.tier_id` stays the tier the listings join, kept in step by every term write and the nightly
 *   `app:subscriptions:expire`. `status` is `active` until that job has seen the term end (`expired`).
 *   `cancel_requested_at` means "do not invoice the next term"; the term itself runs to its end.
 * - `portal_subscription_reminders` (Portal): one marker row per term and reminder (30 or 7 days before the end),
 *   written once the mail went out, so the second run of `app:subscriptions:remind` sends nothing.
 *
 * Every venue whose tier is not Free gets a one-year term from today (the Zurich day), so no paid entry drops to Free.
 */
final class Version20261108090603 extends AbstractMigration
{
    private const string FREE_ID = '01a1b618-ef09-7741-b8a7-dedbd871b2f1';

    public function getDescription(): string
    {
        return 'G9: subscriptions (paid terms) and portal_subscription_reminders (reminder markers)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE subscriptions (
                id UUID NOT NULL,
                venue_id UUID NOT NULL,
                tier_id UUID NOT NULL,
                starts_on DATE NOT NULL,
                ends_on DATE NOT NULL,
                status VARCHAR(20) DEFAULT 'active' NOT NULL,
                paid_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                invoice_ref VARCHAR(60) DEFAULT NULL,
                cancel_requested_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                CONSTRAINT pk_subscriptions PRIMARY KEY (id),
                CONSTRAINT fk_subscriptions_venue_id FOREIGN KEY (venue_id) REFERENCES venues (id) ON DELETE CASCADE,
                CONSTRAINT fk_subscriptions_tier_id FOREIGN KEY (tier_id) REFERENCES tiers (id) ON DELETE RESTRICT,
                CONSTRAINT uk_subscriptions_venue_id_starts_on UNIQUE (venue_id, starts_on),
                CONSTRAINT chk_subscriptions_status CHECK (status IN ('active', 'expired')),
                CONSTRAINT chk_subscriptions_term CHECK (ends_on >= starts_on),
                CONSTRAINT chk_subscriptions_invoice_ref CHECK (invoice_ref IS NULL OR btrim(invoice_ref) <> '')
            )
            SQL);
        $this->addSql('CREATE INDEX idx_subscriptions_venue_id ON subscriptions (venue_id)');
        $this->addSql('CREATE INDEX idx_subscriptions_tier_id ON subscriptions (tier_id)');
        $this->addSql('CREATE INDEX idx_subscriptions_ends_on ON subscriptions (ends_on)');

        $this->addSql(<<<'SQL'
            CREATE TABLE portal_subscription_reminders (
                id UUID NOT NULL,
                subscription_id UUID NOT NULL,
                days_before SMALLINT NOT NULL,
                sent_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                CONSTRAINT pk_portal_subscription_reminders PRIMARY KEY (id),
                CONSTRAINT fk_portal_subscription_reminders_subscription_id FOREIGN KEY (subscription_id) REFERENCES subscriptions (id) ON DELETE CASCADE,
                CONSTRAINT uk_portal_subscription_reminders_subscription_id_days_before UNIQUE (subscription_id, days_before),
                CONSTRAINT chk_portal_subscription_reminders_days_before CHECK (days_before IN (30, 7))
            )
            SQL);
        $this->addSql('CREATE INDEX idx_portal_subscription_reminders_subscription_id ON portal_subscription_reminders (subscription_id)');

        $this->addSql(\sprintf(<<<'SQL'
            INSERT INTO subscriptions (id, venue_id, tier_id, starts_on, ends_on, paid_at)
            SELECT uuidv7(), v.id, v.tier_id, d.today, (d.today + INTERVAL '1 year' - INTERVAL '1 day')::date, CURRENT_TIMESTAMP
            FROM venues v
            CROSS JOIN (SELECT (CURRENT_TIMESTAMP AT TIME ZONE 'Europe/Zurich')::date AS today) d
            WHERE v.tier_id <> '%s'
            SQL, self::FREE_ID));
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE portal_subscription_reminders');
        $this->addSql('DROP TABLE subscriptions');
    }
}
