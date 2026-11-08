<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues;

use App\Factory\ModerationCaseFactory;
use App\Venues\ReadModel\ModerationCaseQuery;
use App\Venues\ValueObject\ModerationDecision;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Test\Factories;

/**
 * D36: the complainant's name, e-mail address and message go on the last night before the decision is three years
 * old (cutoff `now - 3 years + 1 day`), so no nightly run is late; the case and its decision stay. A decision that
 * turns three within a day is due, one a second younger is not, an open case never is, and a second run erases
 * nothing.
 */
final class EraseModerationComplainantsCommandTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    private const string NOW = '2029-11-08 03:15:00 UTC';

    private Connection $connection;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->connection = $em->getConnection();
        $this->connection->beginTransaction();
        self::mockTime(self::NOW);

        $this->decided('Alt', '2026-11-09 03:14:59 UTC');
        $this->decided('Grenze', '2026-11-09 03:15:00 UTC');
        $this->decided('Jung', '2026-11-09 03:15:01 UTC');
        ModerationCaseFactory::createOne(['complainantName' => 'Offen', 'at' => new \DateTimeImmutable('2025-01-01 10:00 UTC')]);
        $this->em->clear();
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function test_erases_the_complainant_on_the_last_night_before_three_years(): void
    {
        $tester = $this->runCommand([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame("2 complainants erased.\n", $tester->getDisplay());
        self::assertSame([
            ['note' => 'Alt', 'complainant_name' => null, 'complainant_email' => null, 'message' => null, 'complainant_erased_at' => '2029-11-08 03:15:00+00', 'updated_at' => '2029-11-08 03:15:00+00', 'decision' => 'remove', 'decided_at' => '2026-11-09 03:14:59+00', 'decided_by' => ModerationCaseFactory::STAFF, 'good_faith_at' => '2026-10-30 12:00:00+00'],
            ['note' => 'Grenze', 'complainant_name' => null, 'complainant_email' => null, 'message' => null, 'complainant_erased_at' => '2029-11-08 03:15:00+00', 'updated_at' => '2029-11-08 03:15:00+00', 'decision' => 'remove', 'decided_at' => '2026-11-09 03:15:00+00', 'decided_by' => ModerationCaseFactory::STAFF, 'good_faith_at' => '2026-10-30 12:00:00+00'],
            ['note' => 'Jung', 'complainant_name' => 'Jung', 'complainant_email' => 'erika.beispiel@example.ch', 'message' => 'Die Öffnungszeiten auf dem Eintrag stimmen nicht mehr.', 'complainant_erased_at' => null, 'updated_at' => '2026-11-09 03:15:01+00', 'decision' => 'remove', 'decided_at' => '2026-11-09 03:15:01+00', 'decided_by' => ModerationCaseFactory::STAFF, 'good_faith_at' => '2026-10-30 12:00:00+00'],
        ], $this->decidedRows());
        self::assertSame(0, (int) $this->connection->fetchOne(<<<'SQL'
            SELECT count(*) FROM moderation_cases WHERE complainant_erased_at >= decided_at + interval '3 years'
            SQL), 'never later than three years after the decision');
        self::assertSame('Offen', $this->connection->fetchOne("SELECT complainant_name FROM moderation_cases WHERE status = 'open'"), 'an open case is never erased');
        self::assertSame(4, (int) $this->connection->fetchOne('SELECT count(*) FROM moderation_cases'), 'every case stays as evidence');
    }

    public function test_a_second_run_erases_nothing(): void
    {
        $this->runCommand([]);
        $first = $this->decidedRows();

        $tester = $this->runCommand([]);

        self::assertSame("0 complainants erased.\n", $tester->getDisplay());
        self::assertSame($first, $this->decidedRows());
    }

    public function test_the_next_night_erases_the_case_that_was_one_second_too_young(): void
    {
        $this->runCommand([]);
        self::mockTime('2029-11-09 03:15:00 UTC');

        self::assertSame("1 complainants erased.\n", $this->runCommand([])->getDisplay());
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT count(*) FROM moderation_cases WHERE status = 'decided' AND complainant_name IS NOT NULL"));
        self::assertSame(
            ['Alt' => '2029-11-08 03:15:00+00', 'Grenze' => '2029-11-08 03:15:00+00', 'Jung' => '2029-11-09 03:15:00+00'],
            array_column($this->decidedRows(), 'complainant_erased_at', 'note'),
            'an erased case keeps the time of its erasure',
        );
    }

    public function test_dry_run_erases_nothing(): void
    {
        $tester = $this->runCommand(['--dry-run' => true]);

        self::assertSame("2 complainants would be erased.\n", $tester->getDisplay());
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM moderation_cases WHERE complainant_erased_at IS NOT NULL'));
    }

    public function test_staff_read_an_erased_case_without_its_complainant(): void
    {
        $this->runCommand([]);
        $id = (string) $this->connection->fetchOne("SELECT id FROM moderation_cases WHERE decision_note = 'Grenze'");
        $query = self::getContainer()->get(ModerationCaseQuery::class);
        self::assertInstanceOf(ModerationCaseQuery::class, $query);

        $case = $query->find($id);

        self::assertNotNull($case);
        self::assertNull($case->complainantName);
        self::assertNull($case->complainantEmail);
        self::assertNull($case->message);
        self::assertEquals(new \DateTimeImmutable(self::NOW), $case->complainantErasedAt);
        self::assertSame(ModerationDecision::Remove, $case->decision);
        self::assertSame([null], array_map(static fn ($row): ?string => $row->complainantName, array_values(array_filter(
            $query->decided(10),
            static fn ($row): bool => $row->id === $id,
        ))));
    }

    /**
     * A decided correction whose note and name are `$label`, so the rows say which case they are.
     */
    private function decided(string $label, string $decidedAt): void
    {
        ModerationCaseFactory::new()
            ->decided(ModerationDecision::Remove, note: $label, at: new \DateTimeImmutable($decidedAt))
            ->create(['complainantName' => $label, 'at' => new \DateTimeImmutable('2026-10-30 12:00 UTC')]);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function runCommand(array $input): CommandTester
    {
        \assert(null !== self::$kernel);
        $tester = new CommandTester((new Application(self::$kernel))->find('app:venues:erase-complainants'));
        $tester->execute($input);

        return $tester;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function decidedRows(): array
    {
        $this->em->clear();

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT decision_note AS note, complainant_name, complainant_email, message,
                to_char(complainant_erased_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS"+00"') AS complainant_erased_at,
                to_char(updated_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS"+00"') AS updated_at,
                decision,
                to_char(decided_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS"+00"') AS decided_at,
                decided_by,
                to_char(good_faith_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS"+00"') AS good_faith_at
            FROM moderation_cases
            WHERE status = 'decided'
            ORDER BY decided_at
            SQL);

        return $rows;
    }
}
