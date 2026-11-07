<?php

declare(strict_types=1);

namespace App\Tests\Integration\Crm;

use App\Crm\Command\IngestInquiry;
use App\Crm\ReadModel\InquiryInboxCounts;
use App\Crm\ReadModel\InquiryInboxQuery;
use App\Crm\ReadModel\InquiryInboxRow;
use App\Crm\ValueObject\InquiryStatus;
use App\Factory\InquiryFactory;
use App\Factory\VenueFactory;
use App\Inquiries\ValueObject\MailStatus;
use App\Shared\Uid\EntityId;
use App\Tests\Support\Admin\RollsBackDatabase;
use App\Venues\ValueObject\Term;
use App\Venues\ValueObject\TermGroup;
use Doctrine\Bundle\DoctrineBundle\Middleware\BacktraceDebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The inbox's reads: the tabs, their order and pages, one inquiry in full, and the widget's newest.
 */
final class InquiryInboxQueryTest extends KernelTestCase
{
    use DispatchesCrmCommands;
    use Factories;
    use RollsBackDatabase;

    public function test_the_counts_hold_every_status_and_the_sum(): void
    {
        $this->inquiry(status: InquiryStatus::New);
        $this->inquiry(status: InquiryStatus::New);
        $this->inquiry(status: InquiryStatus::Seen);
        $this->inquiry(status: InquiryStatus::Done);
        InquiryFactory::createOne();

        $counts = $this->query()->counts();

        self::assertSame([2, 1, 1, 4], [$counts->new, $counts->seen, $counts->done, $counts->all]);
    }

    public function test_the_counts_are_zero_on_an_empty_inbox(): void
    {
        $counts = $this->query()->counts();

        self::assertSame([0, 0, 0, 0], [$counts->new, $counts->seen, $counts->done, $counts->all]);
    }

    public function test_a_tab_holds_its_status_and_alle_holds_all(): void
    {
        $new = $this->inquiry(status: InquiryStatus::New);
        $seen = $this->inquiry(status: InquiryStatus::Seen);
        $done = $this->inquiry(status: InquiryStatus::Done);
        $counts = $this->query()->counts();

        self::assertSame([$new], $this->ids($this->query()->page(InquiryStatus::New, 1, $counts)->rows));
        self::assertSame([$seen], $this->ids($this->query()->page(InquiryStatus::Seen, 1, $counts)->rows));
        self::assertSame([$done], $this->ids($this->query()->page(InquiryStatus::Done, 1, $counts)->rows));
        self::assertEqualsCanonicalizing([$new, $seen, $done], $this->ids($this->query()->page(null, 1, $counts)->rows));
    }

    public function test_the_newest_inquiry_comes_first_and_the_id_breaks_a_tie(): void
    {
        $oldest = $this->inquiry('2026-10-01 10:00:00');
        $newest = $this->inquiry('2026-10-03 10:00:00');
        $tiedA = $this->inquiry('2026-10-02 10:00:00');
        $tiedB = $this->inquiry('2026-10-02 10:00:00');
        $tied = [$tiedA, $tiedB];
        rsort($tied);

        $page = $this->query()->page(null, 1, $this->query()->counts());

        self::assertSame([$newest, ...$tied, $oldest], $this->ids($page->rows));
    }

    public function test_the_list_pages_by_fifty(): void
    {
        $all = [];
        for ($i = 0; $i < 51; ++$i) {
            $all[] = $this->inquiry(\sprintf('2026-10-01 10:%02d:00', $i));
        }
        $counts = $this->query()->counts();

        $first = $this->query()->page(null, 1, $counts);
        $second = $this->query()->page(null, 2, $counts);

        self::assertSame([51, 50, 2, 1], [$first->total, $first->perPage, $first->lastPage, $first->page]);
        self::assertCount(50, $first->rows);
        self::assertSame($all[50], $first->rows[0]->id);
        self::assertSame([$all[0]], $this->ids($second->rows));
    }

    public function test_a_page_out_of_range_is_clamped(): void
    {
        $id = $this->inquiry();
        $counts = $this->query()->counts();

        self::assertSame([$id], $this->ids($this->query()->page(null, 999, $counts)->rows));
        self::assertSame(1, $this->query()->page(null, 999, $counts)->page);
        self::assertSame(1, $this->query()->page(null, -3, $counts)->page);
    }

    public function test_the_total_comes_from_the_counts_with_no_statement_of_its_own(): void
    {
        $this->inquiry();
        $counts = new InquiryInboxCounts(7, 2, 1, 10);
        $before = $this->statements();

        $page = $this->query()->page(InquiryStatus::New, 1, $counts);

        self::assertSame(7, $page->total);
        self::assertSame($before + 1, $this->statements());
    }

    public function test_an_empty_tab_runs_no_statement(): void
    {
        $this->inquiry(status: InquiryStatus::Seen);
        $counts = $this->query()->counts();
        $before = $this->statements();

        $page = $this->query()->page(InquiryStatus::New, 1, $counts);

        self::assertSame([], $page->rows);
        self::assertSame([0, 1], [$page->total, $page->lastPage]);
        self::assertSame($before, $this->statements());
    }

    public function test_a_row_carries_what_the_list_shows(): void
    {
        $id = InquiryFactory::createOne([
            'venue' => VenueFactory::new()->withContact()->create(['name' => 'Schloss Seeblick']),
            'firstName' => 'Sina',
            'lastName' => 'Huber',
            'guestCount' => 120,
            'eventType' => 'christmas_party',
            'company' => 'Muster AG',
            'eventDate' => new \DateTimeImmutable('2027-06-12'),
            'createdAt' => new \DateTimeImmutable('2026-10-30 09:15:00 UTC'),
        ])->id();
        $this->dispatch(new IngestInquiry($id));

        $row = $this->query()->newest(1)[0];

        self::assertSame($id, $row->id);
        self::assertSame('Sina Huber', $row->organizerName);
        self::assertSame('Muster AG', $row->company);
        self::assertSame('Schloss Seeblick', $row->venueName);
        self::assertEquals(new Term(TermGroup::SuitableFor, 'christmas_party'), $row->eventType);
        self::assertSame(120, $row->guestCount);
        self::assertSame('2027-06-12', $row->eventDate?->format('Y-m-d'));
        self::assertSame(InquiryStatus::New, $row->status);
        self::assertSame(MailStatus::Pending, $row->venueMail);
        self::assertEquals(new \DateTimeImmutable('2026-10-30 09:15:00 UTC'), $row->receivedAt);
    }

    public function test_a_mail_status_is_read_live(): void
    {
        $id = $this->inquiry();
        $this->mailStatus($id, 'failed', 'skipped');
        self::assertSame(MailStatus::Failed, $this->query()->newest(1)[0]->venueMail);
        $before = $this->query()->detail($id);
        self::assertNotNull($before);
        self::assertSame(MailStatus::Skipped, $before->guestMail);

        $this->mailStatus($id, 'sent', 'sent');

        $counts = $this->query()->counts();
        self::assertSame(MailStatus::Sent, $this->query()->page(null, 1, $counts)->rows[0]->venueMail);
        $after = $this->query()->detail($id);
        self::assertNotNull($after);
        self::assertSame(MailStatus::Sent, $after->venueMail);
        self::assertSame(MailStatus::Sent, $after->guestMail);
    }

    public function test_the_detail_carries_the_whole_inquiry(): void
    {
        $venue = VenueFactory::new()->withContact()->create(['name' => 'Schloss Seeblick']);
        $id = InquiryFactory::createOne([
            'venue' => $venue,
            'firstName' => 'Sina',
            'lastName' => 'Huber',
            'email' => 'sina@example.ch',
            'phone' => '044 123 45 67',
            'eventType' => 'seminar',
            'company' => 'Muster AG',
            'guestCount' => 80,
            'eventDate' => new \DateTimeImmutable('2027-06-12'),
            'dateFlexible' => true,
            'startTime' => new \DateTimeImmutable('1970-01-01 08:30'),
            'cateringWish' => 'external_allowed',
            'budgetChf' => 9000,
            'message' => "Erste Zeile\nZweite Zeile",
        ])->id();
        $this->dispatch(new IngestInquiry($id));

        $detail = $this->query()->detail($id);

        self::assertNotNull($detail);
        self::assertSame($id, $detail->id);
        self::assertSame('Sina Huber', $detail->organizerName);
        self::assertSame('Muster AG', $detail->company);
        self::assertSame('Schloss Seeblick', $detail->venueName);
        self::assertSame($venue->id(), $detail->venueId);
        self::assertSame($venue->number(), $detail->venueNumber);
        self::assertEquals(new Term(TermGroup::SuitableFor, 'seminar'), $detail->eventType);
        self::assertSame(80, $detail->guestCount);
        self::assertSame('2027-06-12', $detail->eventDate?->format('Y-m-d'));
        self::assertTrue($detail->dateFlexible);
        self::assertSame('08:30', $detail->startTime);
        self::assertEquals(new Term(TermGroup::Catering, 'external_allowed'), $detail->cateringWish);
        self::assertSame(9000, $detail->budgetChf);
        self::assertSame('sina@example.ch', $detail->email);
        self::assertSame('044 123 45 67', $detail->phone);
        self::assertSame("Erste Zeile\nZweite Zeile", $detail->message);
        self::assertSame(InquiryStatus::New, $detail->status);
        self::assertSame(MailStatus::Pending, $detail->venueMail);
        self::assertSame(MailStatus::Pending, $detail->guestMail);
    }

    public function test_the_detail_survives_a_deleted_venue(): void
    {
        $id = $this->inquiry();
        $this->connection->executeStatement('UPDATE inquiries SET venue_id = NULL WHERE id = ?', [$id]);

        $detail = $this->query()->detail($id);

        self::assertNotNull($detail);
        self::assertNull($detail->venueId);
    }

    public function test_the_detail_of_an_unknown_or_unlinked_inquiry_is_null(): void
    {
        self::assertNull($this->query()->detail(EntityId::generate()));
        self::assertNull($this->query()->detail(InquiryFactory::createOne()->id()));
    }

    public function test_the_status_is_the_link_rows_and_null_without_one(): void
    {
        $seen = $this->inquiry(status: InquiryStatus::Seen);
        $unlinked = InquiryFactory::createOne()->id();

        self::assertSame(InquiryStatus::Seen, $this->query()->status($seen));
        self::assertNull($this->query()->status($unlinked));
        self::assertNull($this->query()->status(EntityId::generate()));
    }

    public function test_newest_takes_every_status_up_to_its_limit(): void
    {
        $this->inquiry('2026-10-01 10:00:00');
        $seen = $this->inquiry('2026-10-02 10:00:00', InquiryStatus::Seen);
        $done = $this->inquiry('2026-10-03 10:00:00', InquiryStatus::Done);

        self::assertSame([$done, $seen], $this->ids($this->query()->newest(2)));
    }

    public function test_the_fingerprint_is_never_read(): void
    {
        $id = $this->inquiry();
        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(BacktraceDebugDataHolder::class, $holder);
        $holder->reset();

        $counts = $this->query()->counts();
        $this->query()->page(null, 1, $counts);
        $this->query()->detail($id);
        $this->query()->newest(5);

        $statements = array_column($holder->getData()['default'] ?? [], 'sql');
        self::assertCount(4, $statements);
        foreach ($statements as $sql) {
            self::assertStringNotContainsString('fingerprint', (string) $sql);
        }
    }

    private function inquiry(string $createdAt = '2026-10-01 10:00:00', InquiryStatus $status = InquiryStatus::New): string
    {
        $id = InquiryFactory::createOne(['createdAt' => new \DateTimeImmutable($createdAt.' UTC')])->id();
        $this->dispatch(new IngestInquiry($id));
        $this->connection->executeStatement('UPDATE crm_inquiry_links SET status = ? WHERE inquiry_id = ?', [$status->value, $id]);

        return $id;
    }

    private function mailStatus(string $id, string $venue, string $guest): void
    {
        $this->connection->executeStatement(
            'UPDATE inquiries SET venue_mail_status = ?, guest_mail_status = ? WHERE id = ?',
            [$venue, $guest, $id],
        );
    }

    /**
     * @param list<InquiryInboxRow> $rows
     *
     * @return list<string>
     */
    private function ids(array $rows): array
    {
        return array_map(static fn (InquiryInboxRow $row): string => $row->id, $rows);
    }

    private function query(): InquiryInboxQuery
    {
        $query = self::getContainer()->get(InquiryInboxQuery::class);
        self::assertInstanceOf(InquiryInboxQuery::class, $query);

        return $query;
    }

    private function statements(): int
    {
        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(BacktraceDebugDataHolder::class, $holder);

        return \count($holder->getData()['default'] ?? []);
    }
}
