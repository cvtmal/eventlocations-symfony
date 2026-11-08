<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inquiries;

use App\Factory\InquiryFactory;
use App\Factory\VenueFactory;
use App\Inquiries\Command\MarkInquiry;
use App\Inquiries\Exception\InquiryNotFound;
use App\Inquiries\ValueObject\InquiryOverride;
use App\Shared\Uid\EntityId;
use App\Tests\Integration\Crm\DispatchesCrmCommands;
use App\Tests\Support\Admin\RollsBackDatabase;
use App\Venues\Entity\Venue;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Test\Factories;

/**
 * The staff mark (D19, G7): stored, audited with the mark alone, and taking back or giving back the delivered funnel
 * step (D56).
 */
final class MarkInquiryHandlerTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use DispatchesCrmCommands;
    use Factories;
    use RollsBackDatabase;

    private const string SENT_AT = '2026-11-02 10:00:00+00';

    public function test_marking_spam_stores_the_mark_and_audits_it_alone(): void
    {
        $id = InquiryFactory::createOne(['message' => 'Vertraulich'])->id();

        $this->dispatch(new MarkInquiry($id, InquiryOverride::Spam));

        self::assertSame('spam', $this->connection->fetchOne('SELECT qualification_override FROM inquiries WHERE id = ?', [$id]));
        self::assertSame([['command' => MarkInquiry::class, 'changes' => ['override' => 'spam']]], $this->auditRows('inquiry', $id));
        self::assertSame(0, $this->rows("SELECT count(*) FROM admin_audit_log WHERE changes::text LIKE '%Vertraulich%'"));
    }

    public function test_unmarking_clears_the_mark(): void
    {
        $id = InquiryFactory::createOne()->id();
        $this->dispatch(new MarkInquiry($id, InquiryOverride::Test));

        $this->dispatch(new MarkInquiry($id, null));

        self::assertNull($this->connection->fetchOne('SELECT qualification_override FROM inquiries WHERE id = ?', [$id]));
        self::assertSame(['test', null], array_map(static fn (array $row): mixed => $row['changes']['override'], $this->auditRows('inquiry', $id)));
    }

    public function test_marking_a_delivered_inquiry_withdraws_its_step_and_no_other_venue_s(): void
    {
        $venue = VenueFactory::new()->withContact()->create();
        $other = VenueFactory::new()->withContact()->create();
        $id = $this->delivered($venue);
        $this->step($other);

        $this->dispatch(new MarkInquiry($id, InquiryOverride::Spam));

        self::assertSame(0, $this->steps($venue));
        self::assertSame(1, $this->steps($other));
    }

    public function test_unmarking_records_the_step_again(): void
    {
        $venue = VenueFactory::new()->withContact()->create();
        $id = $this->delivered($venue);
        $this->dispatch(new MarkInquiry($id, InquiryOverride::Spam));

        $this->dispatch(new MarkInquiry($id, null));

        self::assertSame(1, $this->steps($venue));
    }

    /**
     * F-M1: the unmark puts the step back at the venue mail, so each later mark finds it, however long after.
     */
    public function test_mark_unmark_mark_unmark_hours_apart_leaves_one_step_at_the_venue_mail(): void
    {
        $clock = self::mockTime('2026-11-03 09:00:00 UTC');
        $venue = VenueFactory::new()->withContact()->create();
        $id = $this->delivered($venue);
        $this->step($venue, '2026-11-02 14:00:00+00');

        foreach ([InquiryOverride::Spam, null, InquiryOverride::Test, null] as $override) {
            $this->dispatch(new MarkInquiry($id, $override));
            $clock->sleep(7200);
        }

        self::assertSame(
            ['2026-11-02 10:00:00+00', '2026-11-02 14:00:00+00'],
            $this->connection->fetchFirstColumn(
                "SELECT to_char(occurred_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') || '+00' FROM funnel_events WHERE event = 'inquiry_delivered' AND venue_id = ? ORDER BY occurred_at",
                [$venue->id()],
            ),
        );
    }

    public function test_mark_unmark_mark_hours_apart_leaves_no_step_of_that_inquiry(): void
    {
        $clock = self::mockTime('2026-11-03 09:00:00 UTC');
        $venue = VenueFactory::new()->withContact()->create();
        $id = $this->delivered($venue);
        $this->step($venue, '2026-11-02 14:00:00+00');

        foreach ([InquiryOverride::Spam, null, InquiryOverride::Test] as $override) {
            $this->dispatch(new MarkInquiry($id, $override));
            $clock->sleep(7200);
        }

        self::assertSame(1, $this->steps($venue));
        self::assertSame(1, $this->rows("SELECT count(*) FROM funnel_events WHERE event = 'inquiry_delivered' AND venue_id = ? AND occurred_at = '2026-11-02 14:00:00+00'", $venue->id()));
    }

    public function test_spam_to_test_touches_no_step(): void
    {
        $venue = VenueFactory::new()->withContact()->create();
        $id = $this->delivered($venue);
        $this->dispatch(new MarkInquiry($id, InquiryOverride::Spam));
        $this->step($venue);

        $this->dispatch(new MarkInquiry($id, InquiryOverride::Test));

        self::assertSame(1, $this->steps($venue));
    }

    public function test_marking_an_undelivered_inquiry_touches_no_step(): void
    {
        $venue = VenueFactory::new()->withContact()->create();
        $id = InquiryFactory::createOne(['venue' => $venue])->id();
        $this->connection->executeStatement("UPDATE inquiries SET venue_mail_status = 'failed' WHERE id = ?", [$id]);
        $this->em->clear();
        $this->step($venue);

        $this->dispatch(new MarkInquiry($id, InquiryOverride::Spam));
        $this->dispatch(new MarkInquiry($id, null));

        self::assertSame(1, $this->steps($venue));
    }

    public function test_an_unknown_id_is_not_found(): void
    {
        $unknown = EntityId::generate();

        self::assertSame($unknown, $this->failure(new MarkInquiry($unknown, InquiryOverride::Spam), InquiryNotFound::class)->inquiryId);
        self::assertSame([], $this->auditRows('inquiry', $unknown));
    }

    /**
     * A delivered inquiry and its funnel step, recorded a moment after the venue mail as `DeliverInquiryHandler` does.
     */
    private function delivered(Venue $venue): string
    {
        $id = InquiryFactory::createOne(['venue' => $venue])->id();
        $this->connection->executeStatement(
            "UPDATE inquiries SET venue_mail_status = 'sent', venue_mail_sent_at = ?, qualification = 'qualified' WHERE id = ?",
            [self::SENT_AT, $id],
        );
        $this->em->clear();
        $this->step($venue, '2026-11-02 10:00:01+00');

        return $id;
    }

    private function step(Venue $venue, string $at = self::SENT_AT): void
    {
        $this->connection->executeStatement(
            "INSERT INTO funnel_events (id, occurred_at, event, venue_id) VALUES (?, ?, 'inquiry_delivered', ?)",
            [EntityId::generate(), $at, $venue->id()],
        );
    }

    private function steps(Venue $venue): int
    {
        return $this->rows("SELECT count(*) FROM funnel_events WHERE event = 'inquiry_delivered' AND venue_id = ?", $venue->id());
    }
}
