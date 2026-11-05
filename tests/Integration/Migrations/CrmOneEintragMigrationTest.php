<?php

declare(strict_types=1);

namespace App\Tests\Integration\Migrations;

use App\Crm\ValueObject\CompanyOrigin;
use App\Factory\AdminUserFactory;
use App\Factory\CompanyFactory;
use App\Factory\DealFactory;
use App\Factory\VenueFactory;
use App\Geo\Entity\Canton;
use App\Shared\Uid\EntityId;
use App\Venues\Entity\Venue;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

use function Zenstruck\Foundry\Persistence\repository;

/**
 * The #56 migration, run down and up inside the test's transaction (Postgres DDL is transactional, so the rollback in
 * tearDown() puts app_test back on the head schema). Fixtures are made with Foundry at head, then down() turns their
 * Einträge into link rows: the shape production has before the deploy.
 */
final class CrmOneEintragMigrationTest extends KernelTestCase
{
    use Factories;

    /**
     * Migrations are loaded by path, not autoloaded, so the class is named from the file and
     * stays out of PHPStan's sight.
     */
    private const MIGRATION_FILE = 'migrations/Version20261105214935.php';

    /** FK order; the link table exists only on the old schema. */
    private const TABLES = ['crm_deals', 'crm_company_venues', 'crm_companies'];

    private Connection $connection;
    private EntityManagerInterface $em;

    /** @var list<string> */
    private array $logLines = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->connection = $em->getConnection();
        $this->connection->beginTransaction();

        foreach (self::TABLES as $table) {
            if (null !== $this->connection->fetchOne('SELECT to_regclass(?)', [$table])) {
                $this->connection->executeStatement('DELETE FROM '.$table);
            }
        }
        AdminUserFactory::createOne();
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function test_up_moves_every_link_onto_its_location(): void
    {
        $ids = $this->prodShape();
        self::assertSame(3, $this->intOf('SELECT count(*) FROM crm_company_venues'));
        self::assertFalse($this->hasColumn('crm_companies.venue_id'));
        $updatedBefore = $this->updatedAt();
        $b2 = $this->intOf('SELECT count(*) FROM venues v WHERE NOT EXISTS (SELECT 1 FROM crm_company_venues x WHERE x.venue_id = v.id)');
        self::assertGreaterThanOrEqual(1, $b2);

        $this->migrate('up', $this->logger());

        self::assertSame($ids['v1'], $this->venueOf($ids['l1']));
        self::assertSame($ids['v2'], $this->venueOf($ids['l2']));
        self::assertSame($ids['vLi'], $this->venueOf($ids['li']));
        self::assertNull($this->venueOf($ids['p1']));
        self::assertNull($this->venueOf($ids['p2']));
        self::assertNull($this->connection->fetchOne("SELECT to_regclass('crm_company_venues')"));
        self::assertSame($ids['v1'], $this->connection->fetchOne('SELECT venue_id FROM crm_deals WHERE id = ?', [$ids['dealL1']]));
        self::assertSame($updatedBefore, $this->updatedAt());

        self::assertContains('#56 pre: A6 companies with 2+ venues = 0', $this->logLines);
        self::assertContains('#56 pre: V2 venues on 2+ companies = 0', $this->logLines);
        self::assertContains('#56 pre: A1 companies = 5', $this->logLines);
        self::assertContains('#56 pre: links = 3', $this->logLines);
        self::assertContains(\sprintf('#56 pre: B2 venues without a company = %d', $b2), $this->logLines);
        self::assertContains('#56 pre: K2 Liechtenstein venues on a company = 1', $this->logLines);
    }

    public function test_the_new_constraints_hold(): void
    {
        $ids = $this->prodShape();
        $this->migrate('up');

        $this->assertRefused('uk_crm_companies_venue_id', \sprintf("UPDATE crm_companies SET venue_id = '%s' WHERE id = '%s'", $ids['v1'], $ids['p2']));
        $this->assertRefused('fk_crm_companies_venue_id', \sprintf("UPDATE crm_companies SET venue_id = '%s' WHERE id = '%s'", EntityId::generate(), $ids['p2']));
        self::assertSame('n', $this->connection->fetchOne("SELECT confdeltype FROM pg_constraint WHERE conname = 'fk_crm_companies_venue_id'"));

        $this->connection->executeStatement('DELETE FROM venues WHERE id = ?', [$ids['v2']]);
        self::assertSame(1, $this->intOf(\sprintf("SELECT count(*) FROM crm_companies WHERE id = '%s' AND venue_id IS NULL", $ids['l2'])));

        $this->connection->executeStatement('DELETE FROM crm_companies WHERE id = ?', [$ids['li']]);
        self::assertSame(1, $this->intOf(\sprintf("SELECT count(*) FROM venues WHERE id = '%s'", $ids['vLi'])));
    }

    public function test_aborts_on_a6_a_location_with_two_venues(): void
    {
        $company = CompanyFactory::new()->withVenue($this->venue())->create()->id();
        $second = $this->venue();
        $this->em->clear();
        $this->migrate('down');
        $this->connection->insert('crm_company_venues', ['id' => EntityId::generate(), 'company_id' => $company, 'venue_id' => $second->id()]);

        $message = $this->assertAbortsWith('A6 = 1 (companies with 2+ venues)', 2);
        self::assertStringNotContainsString('V2 =', $message);
    }

    public function test_aborts_on_v2_a_venue_on_two_locations(): void
    {
        $venue = $this->venue();
        CompanyFactory::new()->withVenue($venue)->create();
        $other = CompanyFactory::createOne()->id();
        $this->em->clear();
        $this->migrate('down');
        $this->connection->executeStatement('ALTER TABLE crm_company_venues DROP CONSTRAINT uk_crm_company_venues_venue_id');
        $this->connection->insert('crm_company_venues', ['id' => EntityId::generate(), 'company_id' => $other, 'venue_id' => $venue->id()]);

        $this->assertAbortsWith('V2 = 1 (venues on 2+ companies)', 2);
    }

    public function test_one_abort_names_both_guards(): void
    {
        $venue = $this->venue();
        $company = CompanyFactory::new()->withVenue($venue)->create()->id();
        $other = CompanyFactory::createOne()->id();
        $second = $this->venue();
        $this->em->clear();
        $this->migrate('down');
        $this->connection->executeStatement('ALTER TABLE crm_company_venues DROP CONSTRAINT uk_crm_company_venues_venue_id');
        $this->connection->insert('crm_company_venues', ['id' => EntityId::generate(), 'company_id' => $company, 'venue_id' => $second->id()]);
        $this->connection->insert('crm_company_venues', ['id' => EntityId::generate(), 'company_id' => $other, 'venue_id' => $venue->id()]);

        $message = $this->assertAbortsWith('A6 = 1 (companies with 2+ venues)', 3);
        self::assertStringContainsString('V2 = 1 (venues on 2+ companies)', $message);
        self::assertStringContainsString('Nothing was changed. See docs/operations.md "CRM one Eintrag per Location (#56)".', $message);
    }

    public function test_a_postcondition_failure_rolls_back(): void
    {
        $this->prodShape();
        $companies = $this->intOf('SELECT count(*) FROM crm_companies');
        $migration = $this->migration();
        $migration->up(new Schema());
        $this->connection->insert('crm_companies', ['id' => EntityId::generate(), 'name' => 'Spät dazu', 'origin' => 'manual']);

        $message = $this->failing(fn () => $this->execute($migration));

        self::assertStringContainsString('#56 postcondition failed: ', $message);
        self::assertStringContainsString(\sprintf('P-companies: expected %d, got %d', $companies, $companies + 1), $message);
        $this->assertOldShapeIntact(3);
    }

    public function test_down_restores_the_link_table_and_every_link(): void
    {
        $ids = $this->prodShape();
        $pairs = $this->pairs();
        self::assertCount(3, $pairs);
        $this->migrate('up');

        $this->migrate('down');

        self::assertSame(
            ['fk_crm_company_venues_company_id', 'fk_crm_company_venues_venue_id', 'pk_crm_company_venues', 'uk_crm_company_venues_venue_id'],
            $this->connection->fetchFirstColumn("SELECT conname FROM pg_constraint WHERE conrelid = 'crm_company_venues'::regclass AND contype IN ('p', 'u', 'f') ORDER BY conname"),
        );
        self::assertSame(
            ['c', 'c'],
            $this->connection->fetchFirstColumn("SELECT confdeltype FROM pg_constraint WHERE conrelid = 'crm_company_venues'::regclass AND contype = 'f'"),
        );
        self::assertNotNull($this->connection->fetchOne("SELECT to_regclass('idx_crm_company_venues_company_id')"));
        self::assertSame($pairs, $this->pairs());
        self::assertFalse($this->hasColumn('crm_companies.venue_id'));
        self::assertNull($this->connection->fetchOne("SELECT to_regclass('uk_crm_companies_venue_id')"));
        self::assertSame(1, $this->intOf(\sprintf("SELECT count(*) FROM crm_companies WHERE id = '%s'", $ids['p1'])));
    }

    public function test_up_after_down_passes_again(): void
    {
        $ids = $this->prodShape();
        $this->migrate('up');
        $this->migrate('down');

        $this->migrate('up');

        self::assertSame(3, $this->intOf('SELECT count(*) FROM crm_companies WHERE venue_id IS NOT NULL'));
        self::assertSame($ids['v1'], $this->venueOf($ids['l1']));
        self::assertNull($this->connection->fetchOne("SELECT to_regclass('crm_company_venues')"));
    }

    /** Writers committing during an unattended deploy must wait, not move the counts the postconditions compare. */
    public function test_up_locks_the_counted_tables_against_writers_before_it_reads(): void
    {
        $this->migrate('down');
        $migration = $this->migration();
        $migration->up(new Schema());

        /** @var list<string> $expected */
        $expected = \constant($migration::class.'::LOCKED');
        self::assertNotEmpty($expected);
        // down()'s foreign keys hold the same mode on a few tables already; every counted table must be among them.
        $held = $this->connection->fetchFirstColumn(
            "SELECT c.relname FROM pg_locks l JOIN pg_class c ON c.oid = l.relation WHERE l.pid = pg_backend_pid() AND l.mode = 'ShareRowExclusiveLock' AND l.granted",
        );
        self::assertSame([], array_values(array_diff($expected, $held)));
        self::assertSame('15s', $this->connection->fetchOne('SHOW lock_timeout'));
    }

    /**
     * Production's shape (prod counts of 2026-11-05 in small): two prospects, one with a deal; two venue Locations, one
     * with a deal on its Eintrag; a manual Location on a Liechtenstein venue; a venue without a Location. Ends on the
     * old schema.
     *
     * @return array<string, string>
     */
    private function prodShape(): array
    {
        $p1 = CompanyFactory::createOne(['name' => 'Prospekt Eins']);
        DealFactory::createOne(['company' => $p1]);
        $p2 = CompanyFactory::createOne(['name' => 'Prospekt Zwei']);

        $v1 = $this->venue();
        $v2 = $this->venue();
        $l1 = CompanyFactory::new()->withVenue($v1)->create(['name' => 'Location Eins', 'origin' => CompanyOrigin::Venue]);
        $l2 = CompanyFactory::new()->withVenue($v2)->create(['name' => 'Location Zwei', 'origin' => CompanyOrigin::Venue]);
        $dealL1 = DealFactory::createOne(['company' => $l1, 'venue' => $v1]);

        $canton = repository(Canton::class)->find('li');
        self::assertInstanceOf(Canton::class, $canton);
        $vLi = VenueFactory::new()->withoutLocation($canton)->create();
        $li = CompanyFactory::new()->withVenue($vLi)->create(['name' => 'Vaduz Import']);

        $this->venue();

        $ids = [
            'p1' => $p1->id(), 'p2' => $p2->id(), 'l1' => $l1->id(), 'l2' => $l2->id(), 'li' => $li->id(),
            'v1' => $v1->id(), 'v2' => $v2->id(), 'vLi' => $vLi->id(), 'dealL1' => $dealL1->id(),
        ];
        $this->em->clear();
        $this->migrate('down');

        return $ids;
    }

    private function venue(): Venue
    {
        return VenueFactory::new()->withoutLocation()->create();
    }

    private function venueOf(string $companyId): ?string
    {
        $venueId = $this->connection->fetchOne('SELECT venue_id FROM crm_companies WHERE id = ?', [$companyId]);

        return false === $venueId || null === $venueId ? null : (string) $venueId;
    }

    /**
     * @return array<string, string> company id => updated_at
     */
    private function updatedAt(): array
    {
        /** @var array<string, string> $rows */
        $rows = $this->connection->fetchAllKeyValue('SELECT id, updated_at FROM crm_companies ORDER BY id');

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function pairs(): array
    {
        return $this->connection->fetchAllAssociative('SELECT company_id, venue_id FROM crm_company_venues ORDER BY company_id');
    }

    private function hasColumn(string $tableDotColumn): bool
    {
        [$table, $column] = explode('.', $tableDotColumn);

        return 1 === $this->intOf(\sprintf("SELECT count(*) FROM information_schema.columns WHERE table_name = '%s' AND column_name = '%s'", $table, $column));
    }

    private function assertAbortsWith(string $metric, int $links): string
    {
        $message = $this->failing(fn () => $this->migrate('up'));

        self::assertStringContainsString('#56 precondition failed: ', $message);
        self::assertStringContainsString($metric, $message);
        $this->assertOldShapeIntact($links);

        return $message;
    }

    private function assertOldShapeIntact(int $links): void
    {
        self::assertNotNull($this->connection->fetchOne("SELECT to_regclass('crm_company_venues')"));
        self::assertFalse($this->hasColumn('crm_companies.venue_id'));
        self::assertSame($links, $this->intOf('SELECT count(*) FROM crm_company_venues'));
    }

    private function assertRefused(string $constraint, string $sql): void
    {
        self::assertStringContainsString($constraint, $this->failing(fn () => $this->connection->executeStatement($sql)));
    }

    private function migrate(string $direction, ?LoggerInterface $logger = null): void
    {
        $migration = $this->migration($logger);
        'up' === $direction ? $migration->up(new Schema()) : $migration->down(new Schema());
        $this->execute($migration);
    }

    /** A fresh instance per direction: addSql() accumulates. */
    private function migration(?LoggerInterface $logger = null): AbstractMigration
    {
        $file = \dirname(__DIR__, 3).'/'.self::MIGRATION_FILE;
        require_once $file;
        $class = 'DoctrineMigrations\\'.basename($file, '.php');
        $migration = new $class($this->connection, $logger ?? new NullLogger());
        self::assertInstanceOf(AbstractMigration::class, $migration);

        return $migration;
    }

    private function execute(AbstractMigration $migration): void
    {
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    /**
     * A failing statement aborts the whole Postgres transaction, so it runs inside a savepoint the test rolls back
     * to; the outer transaction stays usable.
     */
    private function failing(callable $statements): string
    {
        $this->connection->createSavepoint('m56');
        try {
            $statements();
        } catch (DbalException $e) {
            $this->connection->rollbackSavepoint('m56');

            return $e->getMessage();
        }

        self::fail('The statements ran through, an abort was expected.');
    }

    private function intOf(string $sql): int
    {
        return (int) $this->connection->fetchOne($sql);
    }

    /**
     * Collects the migration's write() lines in $this->logLines.
     */
    private function logger(): LoggerInterface
    {
        return new class(function (string $line): void {
            $this->logLines[] = $line;
        }) extends AbstractLogger {
            public function __construct(private readonly \Closure $sink)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                ($this->sink)((string) $message);
            }
        };
    }
}
