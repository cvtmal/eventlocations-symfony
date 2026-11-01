<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inquiries;

use App\Factory\InquiryFactory;
use App\Inquiries\Entity\Inquiry;
use App\Tests\Integration\Geo\ImportsTownDataset;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Test\Factories;

/**
 * #20 PD7: cron mails whatever this prints, so a good night prints nothing and a bad one prints
 * identifiers, never a guest's data.
 */
final class ReportStuckInquiriesCommandTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;
    use ImportsTownDataset;

    private const FIRST_NAME = 'Annelies';
    private const LAST_NAME = 'Bergmann';
    private const EMAIL = 'annelies.bergmann@example.ch';
    private const PHONE = '+41791234567';
    private const MESSAGE = 'Wir suchen einen Saal mit Seesicht.';

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
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function test_it_is_silent_with_nothing_stuck(): void
    {
        InquiryFactory::createOne(['createdAt' => new \DateTimeImmutable('2026-06-01 11:55:00 UTC')]);

        $tester = $this->runCommand([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame('', $tester->getDisplay());
    }

    public function test_it_prints_one_line_per_row_with_id_and_venue_number(): void
    {
        $older = $this->stuck('2026-06-01 09:30:00 UTC');
        $newer = $this->stuck('2026-06-01 10:30:00 UTC');

        $tester = $this->runCommand([]);
        $lines = explode("\n", trim($tester->getDisplay()));

        self::assertSame(0, $tester->getStatusCode());
        self::assertCount(3, $lines);
        self::assertSame(\sprintf('%s venue %d since 2026-06-01 09:30 UTC', $older->id(), $older->venueNumber()), $lines[0]);
        self::assertSame(\sprintf('%s venue %d since 2026-06-01 10:30 UTC', $newer->id(), $newer->venueNumber()), $lines[1]);
    }

    public function test_it_prints_nothing_of_the_guest(): void
    {
        $this->stuck('2026-06-01 09:30:00 UTC');

        $display = $this->runCommand([])->getDisplay();

        foreach ([self::FIRST_NAME, self::LAST_NAME, self::EMAIL, self::PHONE, self::MESSAGE] as $personal) {
            self::assertStringNotContainsString($personal, $display);
        }
    }

    public function test_the_minutes_option_moves_the_bound(): void
    {
        $this->stuck('2026-06-01 11:40:00 UTC');

        self::assertNotSame('', $this->runCommand([])->getDisplay());
        self::assertSame('', $this->runCommand(['--minutes' => 30])->getDisplay());
    }

    private function stuck(string $createdAt): Inquiry
    {
        return InquiryFactory::createOne([
            'firstName' => self::FIRST_NAME,
            'lastName' => self::LAST_NAME,
            'email' => self::EMAIL,
            'phone' => self::PHONE,
            'message' => self::MESSAGE,
            'createdAt' => new \DateTimeImmutable($createdAt),
        ]);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function runCommand(array $input): CommandTester
    {
        \assert(null !== self::$kernel);
        $tester = new CommandTester((new Application(self::$kernel))->find('app:inquiries:report-stuck'));
        $tester->execute($input);

        return $tester;
    }
}
