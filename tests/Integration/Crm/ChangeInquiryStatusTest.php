<?php

declare(strict_types=1);

namespace App\Tests\Integration\Crm;

use App\Crm\Command\ChangeInquiryStatus;
use App\Crm\Command\IngestInquiry;
use App\Crm\Exception\InquiryNotFound;
use App\Crm\ValueObject\InquiryStatus;
use App\Factory\InquiryFactory;
use App\Shared\Uid\EntityId;
use App\Tests\Support\Admin\RollsBackDatabase;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The status of an inquiry: written under the lock, audited with its code and nothing of the inquiry.
 */
final class ChangeInquiryStatusTest extends KernelTestCase
{
    use DispatchesCrmCommands;
    use Factories;
    use RollsBackDatabase;

    public function test_it_writes_the_status(): void
    {
        $id = $this->inquiry();

        $this->dispatch(new ChangeInquiryStatus($id, InquiryStatus::Seen));
        $this->em->clear();

        self::assertSame('seen', $this->connection->fetchOne('SELECT status FROM crm_inquiry_links WHERE inquiry_id = ?', [$id]));
    }

    public function test_it_moves_updated_at(): void
    {
        $id = $this->inquiry();
        $this->connection->executeStatement("UPDATE crm_inquiry_links SET updated_at = '2026-01-01 10:00:00+00' WHERE inquiry_id = ?", [$id]);
        $this->em->clear();

        $this->dispatch(new ChangeInquiryStatus($id, InquiryStatus::Done));

        self::assertSame(0, $this->rows("SELECT count(*) FROM crm_inquiry_links WHERE inquiry_id = ? AND updated_at < '2026-02-01'", $id));
    }

    public function test_the_audit_row_holds_the_code_alone(): void
    {
        $id = $this->inquiry();

        $this->dispatch(new ChangeInquiryStatus($id, InquiryStatus::Done));

        self::assertSame(
            [['command' => ChangeInquiryStatus::class, 'changes' => ['status' => 'done']]],
            $this->auditRows('inquiry', $id),
        );
        self::assertSame(0, $this->rows("SELECT count(*) FROM admin_audit_log WHERE changes::text LIKE '%Vertraulich%'"));
    }

    public function test_an_inquiry_without_a_link_row_is_not_found(): void
    {
        $unlinked = InquiryFactory::createOne()->id();

        self::assertSame($unlinked, $this->failure(new ChangeInquiryStatus($unlinked, InquiryStatus::Done), InquiryNotFound::class)->inquiryId);
        self::assertSame([], $this->auditRows('inquiry', $unlinked));
    }

    public function test_an_unknown_id_is_not_found(): void
    {
        $unknown = EntityId::generate();

        self::assertSame($unknown, $this->failure(new ChangeInquiryStatus($unknown, InquiryStatus::Seen), InquiryNotFound::class)->inquiryId);
    }

    private function inquiry(): string
    {
        $id = InquiryFactory::createOne(['message' => 'Vertraulich'])->id();
        $this->dispatch(new IngestInquiry($id));

        return $id;
    }
}
