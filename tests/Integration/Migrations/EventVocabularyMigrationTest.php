<?php

declare(strict_types=1);

namespace App\Tests\Integration\Migrations;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The event vocabulary migration (event-vocabulary, F3) against the data it has to carry over:
 * down() brings the wedding columns and the 147 baseline terms back, and up() takes them away
 * again. A venue keeps the terms that stay in the new vocabulary and loses the others. Postgres
 * DDL is transactional, so both directions run inside the test's transaction and the rollback
 * restores the schema.
 */
final class EventVocabularyMigrationTest extends KernelTestCase
{
    /**
     * Migrations are loaded by path, not autoloaded, so the class is named from the file and
     * stays out of PHPStan's sight.
     */
    private const MIGRATION_FILE = 'migrations/Version20261107140511.php';

    private const VENUE_WITH_TERMS = '019a0000-0000-7000-8000-000000000f01';
    private const VENUE_WITH_PARTY = '019a0000-0000-7000-8000-000000000f02';

    /** @var array<string, int> the groups of the baseline, with their term counts */
    private const BASELINE_COUNTS = [
        'setting' => 5, 'building' => 39, 'suitable_for' => 21, 'infrastructure' => 9, 'furniture' => 5, 'technology' => 5,
        'surroundings' => 6, 'ceremony' => 6, 'amenity' => 15, 'dining' => 13, 'vendor' => 8, 'usage' => 3, 'language' => 12,
    ];

    /** @var array<string, int> the groups of the event vocabulary, with their term counts */
    private const EVENT_COUNTS = [
        'setting' => 5, 'building' => 38, 'suitable_for' => 17, 'infrastructure' => 7, 'furniture' => 3, 'technology' => 8,
        'surroundings' => 6, 'amenity' => 10, 'catering' => 12, 'language' => 12,
    ];

    private const WEDDING_COLUMNS = [
        'venues' => ['curfew_at', 'has_curfew', 'party'],
        'venue_translations' => ['party_note'],
    ];

    private Connection $connection;

    protected function setUp(): void
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function test_down_restores_the_baseline_and_up_keeps_the_terms_that_stay(): void
    {
        $this->runMigration('down');
        self::assertSame(147, $this->termRows());
        $this->assertGroupsAt(self::BASELINE_COUNTS);
        foreach (self::WEDDING_COLUMNS as $table => $columns) {
            self::assertSame($columns, $this->columns($table, $columns), $table);
        }
        self::assertSame(['chk_venues_curfew_at', 'chk_venues_party'], $this->constraints(['chk_venues_party', 'chk_venues_curfew_at']));

        $this->seedVenues();
        $this->connection->executeStatement("INSERT INTO content_blocks (key, locale, body_markdown) VALUES ('landing.building.wedding_venue.intro', 'de', 'Text'), ('landing.building.hotel.intro', 'de', 'Text')");

        $this->runMigration('up');

        self::assertSame(['landing.building.hotel.intro'], $this->connection->fetchFirstColumn("SELECT key FROM content_blocks WHERE key LIKE 'landing.building.%' ORDER BY key"));

        self::assertSame(118, $this->termRows());
        $this->assertGroupsAt(self::EVENT_COUNTS);
        $primaries = $this->connection->fetchAllKeyValue('SELECT code, primary_position FROM taxonomy_terms WHERE primary_position IS NOT NULL ORDER BY primary_position');
        self::assertSame(
            ['bar_club', 'event_venue', 'seminar_room', 'hotel', 'party_room', 'restaurant', 'castle', 'congress_centre', 'forest_hut', 'farm_barn', 'mountain_hut'],
            array_keys($primaries),
        );
        self::assertSame(range(1, 11), array_map('intval', array_values($primaries)));
        self::assertSame(0, $this->rows("SELECT count(*) FROM taxonomy_terms WHERE primary_position IS NOT NULL AND term_group <> 'building'"));

        // The terms that stay keep their venue, the others are gone with their rows; the group ones are not carried over.
        self::assertSame(
            ['amenity:overnight', 'building:castle*', 'suitable_for:birthday'],
            $this->venueTerms(self::VENUE_WITH_TERMS),
        );
        self::assertSame(0, $this->rows("SELECT count(*) FROM taxonomy_terms WHERE code IN ('chapel', 'wedding_venue', 'class_reunion', 'stage', 'flipchart')"));
        self::assertSame([], $this->venueTerms(self::VENUE_WITH_PARTY));

        // The columns go, the venues and their texts stay.
        foreach (self::WEDDING_COLUMNS as $table => $columns) {
            self::assertSame([], $this->columns($table, $columns), $table);
        }
        self::assertSame([], $this->constraints(['chk_venues_party', 'chk_venues_curfew_at']));
        self::assertSame(2, $this->rows('SELECT count(*) FROM venues WHERE id IN (?, ?)', [self::VENUE_WITH_TERMS, self::VENUE_WITH_PARTY]));
        self::assertSame(
            'Testhaus Party',
            $this->connection->fetchOne('SELECT v.name FROM venues v JOIN venue_translations t ON t.venue_id = v.id WHERE v.id = ?', [self::VENUE_WITH_PARTY]),
        );

        // The CHECK follows the new groups.
        try {
            $this->connection->executeStatement("INSERT INTO taxonomy_terms (term_group, code, position) VALUES ('ceremony', 'chapel', 1)");
            self::fail('The group "ceremony" should violate chk_taxonomy_terms_term_group.');
        } catch (DriverException $exception) {
            self::assertStringContainsString('"chk_taxonomy_terms_term_group"', $exception->getMessage());
        }
    }

    public function test_up_a_second_time_changes_nothing(): void
    {
        $this->connection->executeStatement("INSERT INTO venues (id, name, status, capacity_max) VALUES (?, 'Testhaus Zweimal', 'published', 80)", [self::VENUE_WITH_TERMS]);
        $this->connection->executeStatement("INSERT INTO venue_terms (venue_id, term_group, term_code, is_main) VALUES (?, 'building', 'castle', TRUE), (?, 'suitable_for', 'birthday', FALSE), (?, 'catering', 'buffet', FALSE)", array_fill(0, 3, self::VENUE_WITH_TERMS));
        $before = $this->snapshot();

        $this->runMigration('up');

        self::assertSame($before, $this->snapshot());
        self::assertSame(['building:castle*', 'catering:buffet', 'suitable_for:birthday'], $this->venueTerms(self::VENUE_WITH_TERMS));
    }

    public function test_down_on_a_database_with_venues_drops_the_event_terms_and_keeps_the_shared_ones(): void
    {
        $this->connection->executeStatement("INSERT INTO venues (id, name, status, capacity_max) VALUES (?, 'Testhaus Zurueck', 'published', 80)", [self::VENUE_WITH_TERMS]);
        $this->connection->executeStatement(
            "INSERT INTO venue_terms (venue_id, term_group, term_code, is_main) VALUES (?, 'building', 'castle', TRUE), (?, 'suitable_for', 'birthday', FALSE), (?, 'suitable_for', 'seminar', FALSE), (?, 'technology', 'wifi', FALSE), (?, 'catering', 'buffet', FALSE)",
            array_fill(0, 5, self::VENUE_WITH_TERMS),
        );

        $this->runMigration('down');

        // The terms of the baseline stay with the venue; the event terms are gone, because the baseline has no such row.
        self::assertSame(['building:castle*', 'suitable_for:birthday'], $this->venueTerms(self::VENUE_WITH_TERMS));
        self::assertSame(147, $this->termRows());
        $this->assertGroupsAt(self::BASELINE_COUNTS);
        self::assertSame(1, $this->rows('SELECT count(*) FROM venues WHERE id = ?', [self::VENUE_WITH_TERMS]));

        try {
            $this->connection->executeStatement("INSERT INTO taxonomy_terms (term_group, code, position) VALUES ('catering', 'buffet', 1)");
            self::fail('The group "catering" should violate chk_taxonomy_terms_term_group after down().');
        } catch (DriverException $exception) {
            self::assertStringContainsString('"chk_taxonomy_terms_term_group"', $exception->getMessage());
        }
    }

    /**
     * Two venues of the baseline: one with terms that stay (an event type, a main building, an
     * amenity) and terms that go (a ceremony, a dining term, a retired event type, wifi and a
     * flipchart that moved), and one with the wedding facts set.
     */
    private function seedVenues(): void
    {
        $this->connection->executeStatement("INSERT INTO venues (id, name, status, capacity_max) VALUES (?, 'Testhaus Terme', 'published', 100)", [self::VENUE_WITH_TERMS]);
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO venue_terms (venue_id, term_group, term_code, is_main) VALUES
                (?, 'suitable_for', 'birthday', FALSE), (?, 'building', 'castle', TRUE), (?, 'amenity', 'overnight', FALSE),
                (?, 'ceremony', 'chapel', FALSE), (?, 'dining', 'vegan', FALSE), (?, 'suitable_for', 'class_reunion', FALSE),
                (?, 'infrastructure', 'wifi', FALSE), (?, 'infrastructure', 'stage', FALSE), (?, 'furniture', 'flipchart', FALSE)
            SQL, array_fill(0, 9, self::VENUE_WITH_TERMS));

        $this->connection->executeStatement(
            "INSERT INTO venues (id, name, status, capacity_max, party, has_curfew, curfew_at) VALUES (?, 'Testhaus Party', 'published', 60, 'limited', TRUE, '02:00')",
            [self::VENUE_WITH_PARTY],
        );
        $this->connection->executeStatement(
            "INSERT INTO venue_translations (id, venue_id, locale, slug, summary, party_note) VALUES (gen_random_uuid(), ?, 'de', 'testhaus-party-migration', 'Kurz.', 'Musik bis 2 Uhr.')",
            [self::VENUE_WITH_PARTY],
        );
        self::assertSame('limited', $this->connection->fetchOne('SELECT party FROM venues WHERE id = ?', [self::VENUE_WITH_PARTY]));
    }

    /**
     * @param array<string, int> $counts group → number of terms
     */
    private function assertGroupsAt(array $counts): void
    {
        $expected = array_keys($counts);
        sort($expected);
        $groups = array_map('strval', $this->connection->fetchFirstColumn('SELECT DISTINCT term_group FROM taxonomy_terms'));
        sort($groups);
        self::assertSame($expected, $groups);
        foreach ($counts as $group => $count) {
            $positions = $this->connection->fetchFirstColumn('SELECT position FROM taxonomy_terms WHERE term_group = ? ORDER BY position', [$group]);

            self::assertSame(range(1, $count), array_map('intval', $positions), $group);
        }
    }

    private function termRows(): int
    {
        return $this->rows('SELECT count(*) FROM taxonomy_terms');
    }

    /**
     * @param list<mixed> $params
     */
    private function rows(string $sql, array $params = []): int
    {
        return (int) $this->connection->fetchOne($sql, $params);
    }

    /**
     * @param list<string> $names
     *
     * @return list<string> the names that exist as columns of the table, sorted
     */
    private function columns(string $table, array $names): array
    {
        return array_map('strval', $this->connection->fetchFirstColumn(
            'SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? AND column_name IN (?) ORDER BY column_name',
            [$table, $names],
            [ParameterType::STRING, ArrayParameterType::STRING],
        ));
    }

    /**
     * @param list<string> $names
     *
     * @return list<string> the names that exist as constraints, sorted
     */
    private function constraints(array $names): array
    {
        return array_map('strval', $this->connection->fetchFirstColumn(
            'SELECT conname FROM pg_constraint WHERE conname IN (?) ORDER BY conname',
            [$names],
            [ArrayParameterType::STRING],
        ));
    }

    /**
     * @return list<string> `group:code`, `*` for the main term, sorted
     */
    private function venueTerms(string $venueId): array
    {
        return array_map('strval', $this->connection->fetchFirstColumn(
            "SELECT term_group || ':' || term_code || CASE WHEN is_main THEN '*' ELSE '' END AS t FROM venue_terms WHERE venue_id = ? ORDER BY t",
            [$venueId],
        ));
    }

    /**
     * @return list<string> every taxonomy row with its positions, then every venue term
     */
    private function snapshot(): array
    {
        return array_map('strval', array_merge(
            $this->connection->fetchFirstColumn("SELECT term_group || '.' || code || '@' || position || '/' || COALESCE(primary_position::text, '-') FROM taxonomy_terms ORDER BY 1"),
            $this->connection->fetchFirstColumn("SELECT 'venue:' || venue_id || ':' || term_group || '.' || term_code || ':' || is_main FROM venue_terms ORDER BY 1"),
        ));
    }

    private function runMigration(string $direction): void
    {
        $file = \dirname(__DIR__, 3).'/'.self::MIGRATION_FILE;
        require_once $file;
        $class = 'DoctrineMigrations\\'.basename($file, '.php');
        $migration = new $class($this->connection, new NullLogger());
        self::assertInstanceOf(AbstractMigration::class, $migration);
        'up' === $direction ? $migration->up(new Schema()) : $migration->down(new Schema());

        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement());
        }
    }
}
