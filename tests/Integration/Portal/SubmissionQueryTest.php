<?php

declare(strict_types=1);

namespace App\Tests\Integration\Portal;

use App\Factory\SubmissionFactory;
use App\Factory\VenueFactory;
use App\Portal\ReadModel\SubmissionQuery;
use App\Portal\ReadModel\SubmissionState;
use App\Portal\ValueObject\SubmissionStatus;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Admin\RollsBackDatabase;
use Doctrine\Bundle\DoctrineBundle\Middleware\BacktraceDebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

final class SubmissionQueryTest extends KernelTestCase
{
    use Factories;
    use ImportsTownDataset;
    use RollsBackDatabase;

    public function test_it_reads_status_and_reason(): void
    {
        $venue = VenueFactory::createOne();
        SubmissionFactory::new()->rejected()->create(['venue' => $venue]);

        self::assertEquals(new SubmissionState(SubmissionStatus::Rejected, 'Die Angaben reichen nicht aus.'), $this->query()->find($venue->id()));
    }

    public function test_no_row_gives_null(): void
    {
        self::assertNull($this->query()->find(VenueFactory::createOne()->id()));
    }

    public function test_statuses_key_by_number_in_one_statement(): void
    {
        $draft = VenueFactory::createOne();
        SubmissionFactory::createOne(['venue' => $draft]);
        $submitted = VenueFactory::createOne();
        SubmissionFactory::new()->submitted()->create(['venue' => $submitted]);
        $without = VenueFactory::createOne();
        $unasked = VenueFactory::createOne();
        SubmissionFactory::createOne(['venue' => $unasked]);
        $holder = $this->statements();

        $statuses = $this->query()->statuses([$draft->number(), $submitted->number(), $without->number()]);

        self::assertCount(1, $holder->getData()['default'] ?? []);
        self::assertSame([$draft->number() => SubmissionStatus::Draft, $submitted->number() => SubmissionStatus::Submitted], $statuses);
    }

    public function test_no_numbers_ask_nothing(): void
    {
        $holder = $this->statements();

        self::assertSame([], $this->query()->statuses([]));
        self::assertSame([], $holder->getData()['default'] ?? []);
    }

    private function statements(): BacktraceDebugDataHolder
    {
        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(BacktraceDebugDataHolder::class, $holder);
        $holder->reset();

        return $holder;
    }

    private function query(): SubmissionQuery
    {
        return new SubmissionQuery($this->connection);
    }
}
