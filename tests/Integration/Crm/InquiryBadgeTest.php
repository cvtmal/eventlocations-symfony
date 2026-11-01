<?php

declare(strict_types=1);

namespace App\Tests\Integration\Crm;

use App\Crm\Command\IngestInquiry;
use App\Crm\ReadModel\InquiryBadge;
use App\Crm\ReadModel\OwnerOptionsQuery;
use App\Crm\ValueObject\InquiryStatus;
use App\Factory\AdminUserFactory;
use App\Factory\InquiryFactory;
use App\Tests\Support\Admin\RollsBackDatabase;
use Doctrine\Bundle\DoctrineBundle\Middleware\BacktraceDebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The sidebar's count (N5): which links it holds, and that it costs a page one statement at most, none when the owners
 * statement carried it.
 */
final class InquiryBadgeTest extends KernelTestCase
{
    use DispatchesCrmCommands;
    use Factories;
    use RollsBackDatabase;

    public function test_it_counts_only_the_new_inquiries(): void
    {
        $this->inquiry(InquiryStatus::New);
        $this->inquiry(InquiryStatus::New);
        $this->inquiry(InquiryStatus::Seen);
        $this->inquiry(InquiryStatus::Done);

        self::assertSame(2, $this->badge()->count());
    }

    public function test_an_inquiry_without_a_link_row_does_not_count(): void
    {
        InquiryFactory::createOne();

        self::assertSame(0, $this->badge()->count());
    }

    public function test_it_asks_once_per_request(): void
    {
        $before = $this->statements();

        $this->badge()->count();
        $this->badge()->count();
        self::assertSame($before + 1, $this->statements());

        $this->badge()->reset();
        $this->badge()->count();
        self::assertSame($before + 2, $this->statements(), 'The next request counts again.');
    }

    public function test_a_count_handed_over_is_not_asked_for(): void
    {
        $before = $this->statements();

        $this->badge()->remember(3);

        self::assertSame(3, $this->badge()->count());
        self::assertSame($before, $this->statements());
    }

    public function test_the_owners_statement_brings_the_count_along(): void
    {
        AdminUserFactory::createOne();
        $this->inquiry(InquiryStatus::New);
        $before = $this->statements();

        $this->owners()->all();

        self::assertSame(1, $this->badge()->count());
        self::assertSame($before + 1, $this->statements());
    }

    private function inquiry(InquiryStatus $status): void
    {
        $id = InquiryFactory::createOne()->id();
        $this->dispatch(new IngestInquiry($id));
        $this->connection->executeStatement('UPDATE crm_inquiry_links SET status = ? WHERE inquiry_id = ?', [$status->value, $id]);
    }

    private function badge(): InquiryBadge
    {
        $badge = self::getContainer()->get(InquiryBadge::class);
        self::assertInstanceOf(InquiryBadge::class, $badge);

        return $badge;
    }

    private function owners(): OwnerOptionsQuery
    {
        $owners = self::getContainer()->get(OwnerOptionsQuery::class);
        self::assertInstanceOf(OwnerOptionsQuery::class, $owners);

        return $owners;
    }

    private function statements(): int
    {
        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(BacktraceDebugDataHolder::class, $holder);

        return \count($holder->getData()['default'] ?? []);
    }
}
