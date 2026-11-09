<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inquiries;

use App\Factory\InquiryBriefFactory;
use App\Factory\InquiryFactory;
use App\Tests\Integration\Geo\ImportsTownDataset;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Test\Factories;

/**
 * LEG-2: older than 12 months goes, the boundary stays. A brief (F13) goes with its children, by the same age.
 */
final class PurgeInquiriesCommandTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;
    use ImportsTownDataset;

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
        self::mockTime('2026-06-01 12:00:00 UTC');

        InquiryFactory::createOne(['firstName' => 'Alt', 'createdAt' => new \DateTimeImmutable('2025-06-01 11:59:59 UTC')]);
        InquiryFactory::createOne(['firstName' => 'Grenze', 'createdAt' => new \DateTimeImmutable('2025-06-01 12:00:00 UTC')]);
        InquiryFactory::createOne(['firstName' => 'Neu', 'createdAt' => new \DateTimeImmutable('2026-05-01 12:00:00 UTC')]);
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function test_deletes_only_what_is_older_than_twelve_months(): void
    {
        $tester = $this->runCommand([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('1 inquiries deleted.', $tester->getDisplay());
        self::assertSame(['Grenze', 'Neu'], $this->names());
    }

    public function test_an_old_brief_goes_with_its_children_and_a_young_one_stays(): void
    {
        $old = $this->brief('2025-05-01 12:00:00 UTC', 'AltBrief');
        $young = $this->brief('2026-05-01 12:00:00 UTC', 'NeuBrief');

        $tester = $this->runCommand([]);

        self::assertStringContainsString('3 inquiries deleted.', $tester->getDisplay());
        self::assertSame(['Grenze', 'Neu', 'NeuBrief', 'NeuBrief'], $this->names());
        self::assertSame([$young], $this->connection->fetchFirstColumn('SELECT id FROM inquiry_briefs'));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM inquiries WHERE brief_id = ?', [$old]));
    }

    public function test_dry_run_keeps_every_brief(): void
    {
        $this->brief('2025-05-01 12:00:00 UTC', 'AltBrief');

        $tester = $this->runCommand(['--dry-run' => true]);

        self::assertStringContainsString('3 inquiries would be deleted.', $tester->getDisplay());
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM inquiry_briefs'));
    }

    public function test_dry_run_deletes_nothing(): void
    {
        $tester = $this->runCommand(['--dry-run' => true]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('1 inquiries would be deleted.', $tester->getDisplay());
        self::assertSame(['Alt', 'Grenze', 'Neu'], $this->names());
    }

    /**
     * A brief with two children, all created at `$at`; returns the brief's id.
     */
    private function brief(string $at, string $firstName): string
    {
        $createdAt = new \DateTimeImmutable($at);
        $brief = InquiryBriefFactory::createOne(['venueCount' => 2, 'createdAt' => $createdAt]);
        InquiryFactory::createMany(2, ['brief' => $brief, 'firstName' => $firstName, 'createdAt' => $createdAt]);

        return $brief->id();
    }

    /**
     * @param array<string, mixed> $input
     */
    private function runCommand(array $input): CommandTester
    {
        \assert(null !== self::$kernel);
        $tester = new CommandTester((new Application(self::$kernel))->find('app:inquiries:purge'));
        $tester->execute($input);

        return $tester;
    }

    /**
     * @return list<string>
     */
    private function names(): array
    {
        /** @var list<string> $names */
        $names = $this->connection->fetchFirstColumn('SELECT first_name FROM inquiries ORDER BY first_name');

        return $names;
    }
}
