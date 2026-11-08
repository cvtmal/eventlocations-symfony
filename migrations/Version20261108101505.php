<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * G12 `launch-regions`. `venues.facts_checked_at`: when staff confirmed an entry's facts (null = unchecked); with a
 * written description it lets the entry be indexed (`Venues\VenueIndexGate`). No backfill: every entry
 * starts unchecked. `search_misses`: searches without a result, counted per ISO week (`week_start` is its Monday,
 * Europe/Zurich) and canonical query string, written by `Stats\CommandHandler\RecordSearchMissHandler`. It stores no
 * person: no IP, user agent, session or time of day. The query is the URL-encoded canonical `SearchCriteria` query,
 * at most 2000 characters (ASCII, so the unique index stays under the btree row limit). Its search text is cut to
 * 100 characters and a text that looks like contact data is never written (`Search\SearchMissFilter`, D46); a
 * week's rows go 12 months after its Monday (`Stats\Command\PurgeSearchMisses`, nightly, D46).
 */
final class Version20261108101505 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'G12: venues.facts_checked_at (indexation gate) and search_misses (zero-result searches per week)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE venues ADD COLUMN facts_checked_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql(<<<'SQL'
            CREATE TABLE search_misses (
                id UUID NOT NULL,
                week_start DATE NOT NULL,
                query VARCHAR(2000) NOT NULL,
                searches INTEGER DEFAULT 1 NOT NULL,
                CONSTRAINT pk_search_misses PRIMARY KEY (id),
                CONSTRAINT uk_search_misses_week_start_query UNIQUE (week_start, query),
                CONSTRAINT chk_search_misses_week_start CHECK (EXTRACT(ISODOW FROM week_start) = 1),
                CONSTRAINT chk_search_misses_query CHECK (query <> '' AND query !~ '[^ -~]'),
                CONSTRAINT chk_search_misses_searches CHECK (searches >= 1)
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE search_misses');
        $this->addSql('ALTER TABLE venues DROP COLUMN facts_checked_at');
    }
}
