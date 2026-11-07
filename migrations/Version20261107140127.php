<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Tiers as data (F12, D14/D15). The wedding site's five-case tier enum (`venues.tier` with its generated
 * `tier_rank`, `crm_deals.tier`) is replaced by the `tiers` table: a tier is a row of limits and flags, so a
 * staff edit, or one more row, changes what a venue may show without a release. Two rows are seeded, Free
 * and Pro (CHF 199 a year excluding VAT, invoiced by hand).
 *
 * - `venues.tier_id` is NOT NULL and defaults to Free; a venue is never without a tier. `EntitlementResolver`
 *   is the one reader of the limits. G9 (`subscription-term`) adds `subscriptions` beside this and keeps
 *   `venues.tier_id` as the tier the listings order by.
 * - `crm_deals.tier_id` is the tier a deal sells; NULL while undecided.
 * - Existing rows map `basic` to Free and every other old tier to Pro. No production data exists, so this
 *   only keeps a developer's seeded database usable. down() maps back to `basic` and `premium`.
 * - The two ids are fixed here, so the column default and the tests can name the Free row.
 */
final class Version20261107140127 extends AbstractMigration
{
    private const FREE_ID = '01a1b618-ef09-7741-b8a7-dedbd871b2f1';
    private const PRO_ID = '01a1b618-ef0c-707f-85a5-3eb6dc04c87b';

    public function getDescription(): string
    {
        return 'Tiers as data (F12): tiers table with Free and Pro, venues.tier_id and crm_deals.tier_id replace the tier enum';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE tiers (
                id UUID NOT NULL,
                code VARCHAR(30) NOT NULL,
                name VARCHAR(60) NOT NULL,
                price_rappen INTEGER NOT NULL,
                rank_weight SMALLINT NOT NULL,
                photos_max INTEGER NOT NULL,
                rooms_max INTEGER NOT NULL,
                videos_max INTEGER NOT NULL,
                documents_max INTEGER NOT NULL,
                contact_visible BOOLEAN NOT NULL,
                last_minute_allowed BOOLEAN NOT NULL,
                detailed_report BOOLEAN NOT NULL,
                event_types_max INTEGER DEFAULT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                CONSTRAINT pk_tiers PRIMARY KEY (id),
                CONSTRAINT uk_tiers_code UNIQUE (code),
                CONSTRAINT uk_tiers_name UNIQUE (name),
                CONSTRAINT chk_tiers_code CHECK (code ~ '^[a-z][a-z0-9_]*$'),
                CONSTRAINT chk_tiers_name CHECK (btrim(name) <> ''),
                CONSTRAINT chk_tiers_price CHECK (price_rappen >= 0),
                CONSTRAINT chk_tiers_rank_weight CHECK (rank_weight >= 0),
                CONSTRAINT chk_tiers_limits CHECK (photos_max >= 0 AND rooms_max >= 0 AND videos_max >= 0 AND documents_max >= 0),
                CONSTRAINT chk_tiers_event_types_max CHECK (event_types_max IS NULL OR event_types_max >= 1)
            )
            SQL);

        $this->addSql(\sprintf(<<<'SQL'
            INSERT INTO tiers (id, code, name, price_rappen, rank_weight, photos_max, rooms_max, videos_max, documents_max, contact_visible, last_minute_allowed, detailed_report, event_types_max)
            VALUES
                ('%1$s', 'free', 'Free', 0, 0, 6, 1, 1, 1, FALSE, FALSE, FALSE, 3),
                ('%2$s', 'pro', 'Pro', 19900, 10, 60, 20, 5, 5, TRUE, TRUE, TRUE, NULL)
            SQL, self::FREE_ID, self::PRO_ID));

        $this->addSql(\sprintf("ALTER TABLE venues ADD COLUMN tier_id UUID DEFAULT '%s' NOT NULL", self::FREE_ID));
        $this->addSql(\sprintf("UPDATE venues SET tier_id = '%s' WHERE tier <> 'basic'", self::PRO_ID));
        $this->addSql('ALTER TABLE venues ADD CONSTRAINT fk_venues_tier_id FOREIGN KEY (tier_id) REFERENCES tiers (id) ON DELETE RESTRICT');
        $this->addSql('CREATE INDEX idx_venues_tier_id ON venues (tier_id)');
        $this->addSql('ALTER TABLE venues DROP CONSTRAINT chk_venues_tier');
        $this->addSql('ALTER TABLE venues DROP COLUMN tier_rank');
        $this->addSql('ALTER TABLE venues DROP COLUMN tier');

        $this->addSql('ALTER TABLE crm_deals ADD COLUMN tier_id UUID DEFAULT NULL');
        $this->addSql(\sprintf("UPDATE crm_deals SET tier_id = CASE WHEN tier = 'basic' THEN '%s'::uuid ELSE '%s'::uuid END WHERE tier IS NOT NULL", self::FREE_ID, self::PRO_ID));
        $this->addSql('ALTER TABLE crm_deals ADD CONSTRAINT fk_crm_deals_tier_id FOREIGN KEY (tier_id) REFERENCES tiers (id) ON DELETE RESTRICT');
        $this->addSql('CREATE INDEX idx_crm_deals_tier_id ON crm_deals (tier_id)');
        $this->addSql('ALTER TABLE crm_deals DROP CONSTRAINT chk_crm_deals_tier');
        $this->addSql('ALTER TABLE crm_deals DROP COLUMN tier');
    }

    public function down(Schema $schema): void
    {
        $oldTiers = "'beliebte_location', 'empfehlung', 'top_premium', 'premium', 'basic'";

        $this->addSql('ALTER TABLE crm_deals ADD COLUMN tier VARCHAR(20) DEFAULT NULL');
        $this->addSql(\sprintf("UPDATE crm_deals SET tier = CASE WHEN tier_id = '%s' THEN 'basic' ELSE 'premium' END WHERE tier_id IS NOT NULL", self::FREE_ID));
        $this->addSql(\sprintf('ALTER TABLE crm_deals ADD CONSTRAINT chk_crm_deals_tier CHECK (tier IN (%s))', $oldTiers));
        $this->addSql('DROP INDEX idx_crm_deals_tier_id');
        $this->addSql('ALTER TABLE crm_deals DROP CONSTRAINT fk_crm_deals_tier_id');
        $this->addSql('ALTER TABLE crm_deals DROP COLUMN tier_id');

        $this->addSql("ALTER TABLE venues ADD COLUMN tier VARCHAR(20) DEFAULT 'basic' NOT NULL");
        $this->addSql(\sprintf("UPDATE venues SET tier = 'premium' WHERE tier_id <> '%s'", self::FREE_ID));
        $this->addSql('ALTER TABLE venues ALTER COLUMN tier DROP DEFAULT');
        $this->addSql("ALTER TABLE venues ADD COLUMN tier_rank SMALLINT GENERATED ALWAYS AS (CASE tier WHEN 'beliebte_location' THEN 1 WHEN 'empfehlung' THEN 2 WHEN 'top_premium' THEN 3 WHEN 'premium' THEN 4 WHEN 'basic' THEN 5 END) STORED NOT NULL");
        $this->addSql(\sprintf('ALTER TABLE venues ADD CONSTRAINT chk_venues_tier CHECK (tier IN (%s))', $oldTiers));
        $this->addSql('DROP INDEX idx_venues_tier_id');
        $this->addSql('ALTER TABLE venues DROP CONSTRAINT fk_venues_tier_id');
        $this->addSql('ALTER TABLE venues DROP COLUMN tier_id');

        $this->addSql('DROP TABLE tiers');
    }
}
