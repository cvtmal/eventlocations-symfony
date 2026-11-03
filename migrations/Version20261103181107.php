<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Venue statistics (#47, contract C4). `stats_ingest_files` logs every loaded spool file, so a rerun never
 * loads one twice; `stats_events` keeps the raw events forever; `stats_daily` is the rollup the screens read,
 * one row per day, venue number (0 = site-wide) and metric, replaced per day. All three are append-only or
 * replaced, so none has an updated_at. The CHECK lists mirror the enums EventType, ClickKind, PageType and
 * Metric (StatsVocabularyChecksTest pins them).
 */
final class Version20261103181107 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Stats: stats_ingest_files, stats_events, stats_daily (#47)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE stats_ingest_files (
                id UUID NOT NULL,
                file_name VARCHAR(64) NOT NULL,
                events INTEGER NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                CONSTRAINT pk_stats_ingest_files PRIMARY KEY (id),
                CONSTRAINT uk_stats_ingest_files_file_name UNIQUE (file_name),
                CONSTRAINT chk_stats_ingest_files_events CHECK (events >= 0)
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE stats_events (
                id UUID NOT NULL,
                occurred_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                type VARCHAR(20) NOT NULL,
                kind VARCHAR(10) DEFAULT NULL,
                page VARCHAR(10) DEFAULT NULL,
                venues JSONB DEFAULT NULL,
                path VARCHAR(255) DEFAULT NULL,
                referrer_host VARCHAR(255) DEFAULT NULL,
                user_agent VARCHAR(255) DEFAULT NULL,
                visitor_hash VARCHAR(32) DEFAULT NULL,
                automated BOOLEAN DEFAULT false NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                CONSTRAINT pk_stats_events PRIMARY KEY (id),
                CONSTRAINT chk_stats_events_type CHECK (type IN ('page_view', 'impression', 'click', 'merkliste_add', 'inquiry')),
                CONSTRAINT chk_stats_events_kind CHECK (kind IN ('website', 'email', 'phone', 'map', 'instagram')),
                CONSTRAINT chk_stats_events_click_kind CHECK ((type = 'click') = (kind IS NOT NULL)),
                CONSTRAINT chk_stats_events_page CHECK (page IN ('home', 'listing', 'landing', 'detail', 'merkliste', 'other'))
            )
            SQL);

        $this->addSql('CREATE INDEX idx_stats_events_occurred_at ON stats_events (occurred_at)');

        $this->addSql(<<<'SQL'
            CREATE TABLE stats_daily (
                day DATE NOT NULL,
                venue_number INTEGER NOT NULL,
                metric VARCHAR(30) NOT NULL,
                events INTEGER NOT NULL,
                visitors INTEGER NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
                CONSTRAINT pk_stats_daily PRIMARY KEY (day, venue_number, metric),
                CONSTRAINT chk_stats_daily_venue_number CHECK (venue_number >= 0),
                CONSTRAINT chk_stats_daily_metric CHECK (metric IN (
                    'page_view', 'page_view_home', 'page_view_listing', 'page_view_landing', 'page_view_detail',
                    'page_view_merkliste', 'page_view_other', 'detail_view', 'impression', 'click_website',
                    'click_email', 'click_phone', 'click_map', 'click_instagram', 'merkliste_add', 'inquiry'
                )),
                CONSTRAINT chk_stats_daily_events CHECK (events >= 0),
                CONSTRAINT chk_stats_daily_visitors CHECK (visitors >= 0)
            )
            SQL);

        $this->addSql('CREATE INDEX idx_stats_daily_venue_number_day ON stats_daily (venue_number, day)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE stats_daily');
        $this->addSql('DROP TABLE stats_events');
        $this->addSql('DROP TABLE stats_ingest_files');
    }
}
