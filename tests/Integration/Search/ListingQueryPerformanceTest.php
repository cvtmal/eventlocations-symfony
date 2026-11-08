<?php

declare(strict_types=1);

namespace App\Tests\Integration\Search;

use App\Factory\VenuePhotoFactory;
use App\Geo\Entity\Town;
use App\Search\ReadModel\FilterDataQuery;
use App\Search\ReadModel\KnownTermsQuery;
use App\Search\ReadModel\LandingFooter;
use App\Search\ReadModel\LandingPages;
use App\Search\ReadModel\LandingQualityGate;
use App\Search\ReadModel\ListingQuery;
use App\Search\ReadModel\SearchCriteria;
use App\Search\ReadModel\SqlStatement;
use App\Shared\Translation\TranslationJoin;
use App\Story\DemoStory;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Integration\Seo\LandingQualityGateTest;
use App\Venues\EntitlementResolver;
use App\Venues\Entity\Venue;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\Translation\IdentityTranslator;
use Zenstruck\Foundry\Test\Factories;

use function Zenstruck\Foundry\Persistence\repository;

/**
 * The listing at the expected production size: DemoStory ×10 (600 venues, 6,000 photos),
 * cloned by SQL inside a rolled-back transaction. The heaviest filter combination must run
 * in under 20 ms, and the search indexes must be usable. SEARCH_PERF_REPORT=1 prints the
 * timings.
 *
 * The dataset is built in each setUp(), so the class has two tests: the kernel (and with it
 * the connection and its transaction) cannot outlive a test, because Foundry's PHPUnit
 * extension shuts it down before every test method. The index checks share one build, each
 * in its own savepoint.
 */
final class ListingQueryPerformanceTest extends KernelTestCase
{
    use Factories;
    use ImportsTownDataset;

    private const COPIES = 9;
    private const PHOTOS_PER_VENUE = 10;
    private const BUDGET_MS = 20.0;

    private EntityManagerInterface $em;
    private Connection $connection;
    private ListingQuery $query;

    protected function setUp(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->connection = $em->getConnection();
        $this->connection->beginTransaction();
        $locale = ListingQueryTest::enabledLocale();
        $this->query = new ListingQuery($this->connection, new TranslationJoin($locale), $locale, new MockClock('2026-10-30 10:00:00', 'UTC'), new EntitlementResolver($this->connection));

        $this->buildTimesTen();
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $this->connection->rollBack();
        // From #5 on, DemoStory also writes photo variants there (the parameter does not exist before).
        $container = self::getContainer();
        $mediaDirectory = $container->hasParameter('app.media_public_dir') ? $container->getParameter('app.media_public_dir') : null;
        if (\is_string($mediaDirectory)) {
            new Filesystem()->remove($mediaDirectory);
        }
        parent::tearDown();
    }

    public function test_heaviest_combination_runs_under_20_ms(): void
    {
        $timings = [];
        foreach (['literal' => $this->heaviestQuery(), 'matching' => $this->matchingHeaviestQuery()] as $variant => $queryString) {
            $criteria = $this->criteria($queryString);
            $timings["heaviest ({$variant}), cards"] = $this->executionMs($this->query->cardStatement($criteria, 'de'));
            $this->reportIndexes("heaviest ({$variant}), cards", $this->explain($this->query->cardStatement($criteria, 'de')));
            $timings["heaviest ({$variant}), count"] = $this->executionMs($this->query->countStatement($criteria));
            $this->report("heaviest ({$variant}) matches", (float) $this->query->count($criteria), 'venues');
        }
        // F5b: the guest criterion with its rooms EXISTS (most matches still fit by their own range; the stale demo venues fit by a room).
        $roomGuests = $this->criteria('guests=300');
        $timings['guests via rooms, cards'] = $this->executionMs($this->query->cardStatement($roomGuests, 'de'));
        $timings['guests via rooms, count'] = $this->executionMs($this->query->countStatement($roomGuests));
        $this->report('guests via rooms matches', (float) $this->query->count($roomGuests), 'venues');
        $timings['unfiltered page 11, cards'] = $this->executionMs($this->query->cardStatement(SearchCriteria::none()->withPage(11), 'de'));
        $timings['unfiltered, count'] = $this->executionMs($this->query->countStatement(SearchCriteria::none()));
        $filterData = new FilterDataQuery($this->connection, ListingQueryTest::enabledLocale());
        $timings['filter data, flags'] = $this->executionMs($filterData->flagsStatement());
        $timings['filter data, terms'] = $this->executionMs($filterData->termsStatement());
        $landingPages = new LandingPages($this->connection, ListingQueryTest::enabledLocale(), new IdentityTranslator(), new LandingFooter(new ArrayAdapter()), new LandingQualityGate(5, LandingQualityGateTest::LAUNCH_CANTONS), 5);
        $timings['landing pages'] = $this->executionMs($landingPages->statement());
        foreach ($timings as $what => $ms) {
            $this->report($what, $ms);
        }
        $this->reportPhotoQuery();

        // The matching variant is built from one DemoStory venue, so it and its 9 clones match.
        self::assertGreaterThanOrEqual(self::COPIES + 1, $this->query->count($this->criteria($this->matchingHeaviestQuery())));
        foreach ($timings as $what => $ms) {
            self::assertLessThan(self::BUDGET_MS, $ms, $what);
        }
    }

    /**
     * The radius uses the GiST index, the name search the trigram index and a term filter the
     * term index. One build for all three (a DemoStory build per test is slow from #5 on);
     * every case is checked and every miss reported.
     */
    public function test_search_indexes_are_usable(): void
    {
        $cases = [
            'radius → GiST' => [['idx_venues_earth'], 'place='.$this->zurich()->id().'&radius=50'],
            'name search → trigram' => [['idx_venues_search_key'], 'q=hof'],
            'term filter → term index' => [['idx_venue_terms_term_group_term_code', 'pk_venue_terms'], 'setting[]='.$this->topCodes('setting', 1)[0]],
            'event type → term index' => [['idx_venue_terms_term_group_term_code', 'pk_venue_terms'], 'event[]='.$this->topCodes('suitable_for', 1)[0].'&guests=80'],
        ];
        $misses = [];
        foreach ($cases as $case => [$anyOfIndexes, $queryString]) {
            $miss = $this->planMiss($anyOfIndexes, $queryString);
            if (null !== $miss) {
                $misses[] = $case.': '.$miss;
            }
        }

        self::assertSame([], $misses);
    }

    /**
     * Whether the predicate can use the index: with sequential and plain index scans off,
     * venues can only be read through a bitmap scan on an index condition. (With only seqscan
     * off, a full scan of an unrelated btree costs about the same as the GIN at 600 rows, so
     * the planner flips between them from run to run.) The settings live in a savepoint, so
     * the next case plans with the defaults again.
     *
     * @param list<string> $anyOfIndexes
     *
     * @return string|null why none of the indexes is in the plan, or null when one is
     */
    private function planMiss(array $anyOfIndexes, string $queryString): ?string
    {
        $this->connection->beginTransaction();
        try {
            $this->connection->executeStatement('SET LOCAL enable_seqscan = off');
            $this->connection->executeStatement('SET LOCAL enable_indexscan = off');
            $statement = $this->query->cardStatement($this->criteria($queryString), 'de');
            $plan = $this->explain($statement);
        } finally {
            $this->connection->rollBack();
        }
        $this->report($queryString.' (seqscan, indexscan off)', $this->executionTime($plan));
        $this->reportIndexes($queryString.' (seqscan, indexscan off)', $plan);

        $used = array_filter($anyOfIndexes, static fn (string $index): bool => str_contains($plan, '"'.$index.'"'));

        return [] === $used ? \sprintf('none of %s in the plan of "%s": %s', implode(', ', $anyOfIndexes), $queryString, $plan) : null;
    }

    /**
     * Every group set, with codes DemoStory actually uses, so the planner has real work.
     */
    private function heaviestQuery(): string
    {
        $parts = [
            'place='.$this->zurich()->id(),
            'radius=50',
            'guests=100',
            'q=hof',
            'access[]=station',
            'rooms=2',
            'area=100',
            'height=2.5',
            'amenity[]=overnight',
        ];
        $groups = ['event' => 2, 'setting' => 3, 'building' => 4, 'infra' => 1, 'furniture' => 1, 'tech' => 1, 'catering' => 1, 'surroundings' => 1];
        $values = ['event' => 'suitable_for', 'infra' => 'infrastructure', 'tech' => 'technology'];
        foreach ($groups as $param => $n) {
            foreach ($this->topCodes($values[$param] ?? $param, $n) as $code) {
                $parts[] = $param.'[]='.$code;
            }
        }

        return implode('&', $parts);
    }

    /**
     * Every group set like heaviestQuery(), but with values taken from one DemoStory venue
     * within 50 km of Zürich, so the planner also sorts and pages real rows.
     */
    private function matchingHeaviestQuery(): string
    {
        $venue = $this->connection->fetchAssociative(<<<'SQL'
            SELECT v.id, v.name, v.capacity_max, v.room_count, v.largest_room_m2,
                v.distance_station_km <= 1 AS station, v.distance_highway_km <= 10 AS highway, v.distance_airport_km <= 30 AS airport
            FROM venues v, towns z
            WHERE z.name = 'Zürich'
              AND earth_distance(ll_to_earth(z.lat, z.lng), ll_to_earth(v.lat, v.lng)) <= 50000
              AND (SELECT count(DISTINCT t.term_group) FROM venue_terms t WHERE t.venue_id = v.id
                   AND t.term_group IN ('infrastructure', 'furniture', 'technology', 'surroundings')) = 4
              AND (v.distance_station_km <= 1 OR v.distance_highway_km <= 10 OR v.distance_airport_km <= 30)
              AND v.room_count >= 1 AND v.largest_room_m2 >= 50 AND v.max_room_height_m >= 2.5
            ORDER BY v.name
            LIMIT 1
            SQL);
        self::assertIsArray($venue, 'DemoStory has no venue that matches every filter group.');
        self::assertIsString($venue['id']);
        self::assertIsString($venue['name']);
        self::assertIsInt($venue['capacity_max']);
        self::assertIsInt($venue['room_count']);
        self::assertIsInt($venue['largest_room_m2']);
        $access = array_values(array_filter(['station', 'highway', 'airport'], static fn (string $a): bool => true === $venue[$a]))[0] ?? self::fail('The venue is near nothing.');

        $parts = [
            'place='.$this->zurich()->id(),
            'radius=50',
            // The venue's own maximum fits it: capacity_min is at most capacity_max.
            'guests='.$venue['capacity_max'],
            'q='.rawurlencode(mb_strtolower(mb_substr($venue['name'], 0, 3))),
            'access[]='.$access,
            'rooms='.min(2, $venue['room_count']),
            'area='.($venue['largest_room_m2'] >= 100 ? 100 : 50),
            'height=2.5',
        ];
        $groups = ['event' => 2, 'setting' => 3, 'building' => 4, 'infra' => 1, 'furniture' => 1, 'tech' => 1, 'catering' => 1, 'surroundings' => 1];
        $values = ['event' => 'suitable_for', 'infra' => 'infrastructure', 'tech' => 'technology'];
        foreach ($groups as $param => $n) {
            $group = $values[$param] ?? $param;
            $codes = $this->connection->fetchFirstColumn(
                'SELECT term_code FROM venue_terms WHERE venue_id = :id AND term_group = :g ORDER BY term_code LIMIT :n',
                ['id' => $venue['id'], 'g' => $group, 'n' => $n],
            );
            if (\in_array($param, ['event', 'setting', 'building'], true)) {
                // OR groups: fill up with the most used codes.
                $codes = \array_slice(array_values(array_unique([...$codes, ...$this->topCodes($group, $n)])), 0, $n);
            }
            foreach ($codes as $code) {
                self::assertIsString($code);
                $parts[] = $param.'[]='.$code;
            }
        }

        return implode('&', $parts);
    }

    /**
     * @return list<string> the group's most used codes
     */
    private function topCodes(string $group, int $n): array
    {
        $codes = $this->connection->fetchFirstColumn(
            'SELECT term_code FROM venue_terms WHERE term_group = :g GROUP BY term_code ORDER BY count(*) DESC, term_code LIMIT :n',
            ['g' => $group, 'n' => $n],
        );
        self::assertNotEmpty($codes, $group);

        return array_values(array_filter($codes, \is_string(...)));
    }

    /**
     * The execution time of the second run (the first warms the caches).
     */
    private function executionMs(SqlStatement $statement): float
    {
        $this->explain($statement);

        return $this->executionTime($this->explain($statement));
    }

    private function explain(SqlStatement $statement): string
    {
        $plan = $this->connection->fetchOne('EXPLAIN (ANALYZE, FORMAT JSON) '.trim($statement->sql), $statement->params, $statement->types);
        self::assertIsString($plan);

        return $plan;
    }

    private function executionTime(string $plan): float
    {
        $decoded = json_decode($plan, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertIsArray($decoded[0]);
        $ms = $decoded[0]['Execution Time'];
        self::assertIsNumeric($ms);

        return (float) $ms;
    }

    /**
     * Report only: the photo query of an unfiltered first page (48 cards), as the DBAL
     * profiler measured it (includes the round trip).
     */
    private function reportPhotoQuery(): void
    {
        if ('1' !== getenv('SEARCH_PERF_REPORT')) {
            return;
        }
        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(\Doctrine\Bundle\DoctrineBundle\Middleware\BacktraceDebugDataHolder::class, $holder);
        $this->query->results(SearchCriteria::none(), 'de');
        $holder->reset();
        $page = $this->query->results(SearchCriteria::none(), 'de');
        foreach ($holder->getData()['default'] ?? [] as $entry) {
            if (str_contains((string) $entry['sql'], 'unnest(')) {
                $this->report(\sprintf('photo query, %d cards × ≤ 8 (profiler, incl. round trip)', \count($page->cards)), (float) $entry['executionMS'] * 1000);
            }
        }
    }

    private function reportIndexes(string $what, string $plan): void
    {
        if ('1' === getenv('SEARCH_PERF_REPORT')) {
            preg_match_all('/"Index Name": "([^"]+)"/', $plan, $matches);
            fwrite(\STDERR, \sprintf("[search-perf] %s uses: %s\n", $what, implode(', ', array_unique($matches[1])) ?: 'no index'));
        }
    }

    private function report(string $what, float $value, string $unit = 'ms'): void
    {
        if ('1' === getenv('SEARCH_PERF_REPORT')) {
            fwrite(\STDERR, \sprintf("[search-perf] %-60s %8.2f %s\n", $what, $value, $unit));
        }
    }

    private function criteria(string $queryString): SearchCriteria
    {
        parse_str($queryString, $parameters);
        /** @var array<string, mixed> $parameters */
        /** @var InputBag<string> $bag */
        $bag = new InputBag($parameters);

        return SearchCriteria::fromQuery($bag, (new KnownTermsQuery($this->connection))->all());
    }

    private function zurich(): Town
    {
        $town = repository(Town::class)->findOneBy(['name' => 'Zürich']);
        self::assertInstanceOf(Town::class, $town);

        return $town;
    }

    private function buildTimesTen(): void
    {
        // From the container, so the constructor arguments (#5 adds DemoMedia) are autowired.
        $story = self::getContainer()->get(DemoStory::class);
        self::assertInstanceOf(DemoStory::class, $story);
        $story->build();

        $this->connection->executeStatement(
            'CREATE TEMP TABLE clone_map ON COMMIT DROP AS SELECT v.id AS src, gen_random_uuid() AS id, n FROM venues v CROSS JOIN generate_series(1, :copies) AS n',
            ['copies' => self::COPIES],
        );
        $this->cloneTable('venues', 'v.id', ['id' => 'm.id', 'name' => "v.name || ' ' || m.n"]);
        $this->cloneTable('venue_translations', 'v.venue_id', ['id' => 'gen_random_uuid()', 'venue_id' => 'm.id', 'slug' => "v.slug || '-' || m.n"]);
        $this->cloneTable('venue_terms', 'v.venue_id', ['venue_id' => 'm.id']);
        // The guest filter and the card's capacity read rooms (F5b); room translations are not read by the listing.
        $this->cloneTable('rooms', 'v.venue_id', ['id' => 'gen_random_uuid()', 'venue_id' => 'm.id']);

        // Photos only through the factory or a column-agnostic clone of a factory row, so the
        // media cycle's NOT NULL columns are filled in either merge order.
        $this->connection->executeStatement('DELETE FROM media_rights_grants');
        $this->connection->executeStatement('DELETE FROM venue_photos');
        $venue = repository(Venue::class)->first();
        self::assertInstanceOf(Venue::class, $venue);
        $template = VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 1]);
        $columns = $this->columns('venue_photos');
        $this->connection->executeStatement(
            \sprintf(
                <<<'SQL'
                    INSERT INTO venue_photos (%s)
                    SELECT %s
                    FROM venue_photos tpl
                    CROSS JOIN venues v
                    CROSS JOIN generate_series(1, :photos) AS p
                    CROSS JOIN LATERAL jsonb_populate_record(
                        NULL::venue_photos,
                        to_jsonb(tpl) || jsonb_build_object('id', gen_random_uuid(), 'venue_id', v.id, 'room_id', NULL, 'position', p)
                    ) r
                    WHERE tpl.id = :template
                    ON CONFLICT DO NOTHING
                    SQL,
                implode(', ', $columns),
                implode(', ', array_map(static fn (string $column): string => 'r.'.$column, $columns)),
            ),
            ['photos' => self::PHOTOS_PER_VENUE, 'template' => $template->id()],
        );

        // A photo is public only with a live grant (G1, Venues\PublicPhotoPolicy): each clone gets a copy of the
        // template's, or the photo query would measure a predicate that hides every photo.
        $grantColumns = $this->columns('media_rights_grants');
        $this->connection->executeStatement(
            \sprintf(
                <<<'SQL'
                    INSERT INTO media_rights_grants (%s)
                    SELECT %s
                    FROM venue_photos p
                    JOIN media_rights_grants tpl ON tpl.venue_photo_id = :template AND tpl.revoked_at IS NULL
                    CROSS JOIN LATERAL jsonb_populate_record(
                        NULL::media_rights_grants,
                        to_jsonb(tpl) || jsonb_build_object('id', uuidv7(), 'venue_id', p.venue_id, 'venue_photo_id', p.id)
                    ) r
                    WHERE p.id <> :template
                    SQL,
                implode(', ', $grantColumns),
                implode(', ', array_map(static fn (string $column): string => 'r.'.$column, $grantColumns)),
            ),
            ['template' => $template->id()],
        );

        $this->connection->executeStatement('ANALYZE venues, venue_translations, venue_terms, venue_photos, media_rights_grants');

        $venueCount = DemoStory::VENUES * (self::COPIES + 1);
        self::assertSame($venueCount, (int) $this->connection->fetchOne('SELECT count(*) FROM venues'));
        self::assertSame($venueCount * self::PHOTOS_PER_VENUE, (int) $this->connection->fetchOne('SELECT count(*) FROM venue_photos'));
        self::assertSame($venueCount * self::PHOTOS_PER_VENUE, (int) $this->connection->fetchOne('SELECT count(*) FROM media_rights_grants WHERE venue_photo_id IS NOT NULL AND revoked_at IS NULL'));
    }

    /**
     * INSERT … SELECT of every row of $table once per copy, with the given column overrides.
     *
     * @param array<string, string> $overrides column → SQL expression over `v` (source row) and `m` (clone_map)
     */
    private function cloneTable(string $table, string $venueIdSql, array $overrides): void
    {
        $columns = $this->columns($table);
        $select = array_map(static fn (string $column): string => $overrides[$column] ?? 'v.'.$column, $columns);

        $this->connection->executeStatement(\sprintf(
            'INSERT INTO %s (%s) SELECT %s FROM %s v JOIN clone_map m ON m.src = %s',
            $table,
            implode(', ', $columns),
            implode(', ', $select),
            $table,
            $venueIdSql,
        ));
    }

    /**
     * @return list<string> the insertable columns (not generated, not identity)
     */
    private function columns(string $table): array
    {
        $columns = $this->connection->fetchFirstColumn(
            "SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = :t AND is_generated = 'NEVER' AND is_identity = 'NO' ORDER BY ordinal_position",
            ['t' => $table],
        );

        return array_values(array_filter($columns, \is_string(...)));
    }
}
