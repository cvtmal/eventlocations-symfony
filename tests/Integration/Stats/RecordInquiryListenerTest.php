<?php

declare(strict_types=1);

namespace App\Tests\Integration\Stats;

use App\Factory\InquiryFactory;
use App\Inquiries\Event\InquirySubmitted;
use App\Stats\StatsPaths;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Filesystem\Filesystem;
use Zenstruck\Foundry\Test\Factories;

final class RecordInquiryListenerTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    private Connection $connection;
    private EntityManagerInterface $em;
    private StatsPaths $paths;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->connection = $em->getConnection();
        $this->connection->beginTransaction();
        self::mockTime('2026-11-03T14:05:09+01:00');

        $directory = self::getContainer()->getParameter('stats.dir');
        self::assertIsString($directory);
        $this->paths = new StatsPaths($directory);
        (new Filesystem())->remove($this->paths->spoolDir());
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->paths->spoolDir());
        $this->em->clear();
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function test_an_inquiry_writes_one_line_with_its_venues_number(): void
    {
        $inquiry = InquiryFactory::createOne();
        $number = $inquiry->venue()?->number();
        self::assertIsInt($number);

        $this->dispatch($inquiry->id());

        self::assertSame(
            [\sprintf('{"at":"2026-11-03T14:05:09+01:00","type":"inquiry","kind":null,"venues":[%d],"page":null,"path":null,"ref":null,"ua":null,"vh":null,"bot":false}', $number)],
            $this->lines(),
        );
    }

    public function test_an_inquiry_without_a_venue_writes_nothing(): void
    {
        $inquiry = InquiryFactory::createOne();
        // What ON DELETE SET NULL leaves when the venue is deleted.
        $this->connection->executeStatement('UPDATE inquiries SET venue_id = NULL WHERE id = :id', ['id' => $inquiry->id()]);

        $this->dispatch($inquiry->id());
        $this->dispatch('01900000-0000-7000-8000-000000000000');

        self::assertSame([], $this->lines());
    }

    private function dispatch(string $inquiryId): void
    {
        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->dispatch(new InquirySubmitted($inquiryId));
    }

    /**
     * @return list<string>
     */
    private function lines(): array
    {
        $file = $this->paths->spoolDir().'/2026-11-03.ndjson';

        return is_file($file) ? explode("\n", rtrim((string) file_get_contents($file), "\n")) : [];
    }
}
