<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inquiries;

use App\Factory\InquiryBriefFactory;
use App\Factory\InquiryFactory;
use App\Factory\VenueFactory;
use App\Inquiries\Command\SubmitInquiryBrief;
use App\Inquiries\Exception\BriefNotAccepted;
use App\Inquiries\ValueObject\BriefRules;
use App\Inquiries\ValueObject\SubmittedBrief;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Venues\Entity\Venue;
use App\Venues\ValueObject\VenueStatus;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Zenstruck\Foundry\Test\Factories;

/**
 * F13: a brief is one `inquiry_briefs` row and one child inquiry per venue, all or nothing, through the real
 * `command.bus` (so the funnel step after the commit runs too).
 */
final class SubmitInquiryBriefHandlerTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;
    use ImportsTownDataset;

    /** 00:30 on 10 November in Zurich, still 9 November in UTC. */
    private const NOW = '2026-11-09 23:30:00 UTC';

    private Connection $connection;
    private EntityManagerInterface $em;
    private MessageBusInterface $bus;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->connection = $em->getConnection();
        $this->connection->beginTransaction();
        $this->connection->executeStatement('DELETE FROM funnel_events');
        $bus = $container->get(MessageBusInterface::class);
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $this->bus = $bus;
        self::mockTime(self::NOW);
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function test_stores_the_brief_and_one_child_per_venue_in_the_given_order(): void
    {
        $first = $this->venue('Zweite Wahl');
        $second = $this->venue('Erste Wahl');
        $third = $this->venue('Dritte Wahl');

        $brief = $this->submit([$second, $first, $third]);

        self::assertFalse($brief->isRepeat);
        self::assertCount(3, $brief->inquiryIds);
        self::assertCount(3, array_unique($brief->inquiryIds));
        $briefs = $this->connection->fetchAllAssociative('SELECT id, venue_count, guest_mail_status FROM inquiry_briefs');
        self::assertSame([['id' => $brief->id, 'venue_count' => 3, 'guest_mail_status' => 'pending']], $briefs);

        $children = $this->connection->fetchAllAssociative('SELECT id, venue_id, brief_id, venue_name, first_name FROM inquiries ORDER BY id');
        self::assertSame($brief->inquiryIds, array_column($children, 'id'));
        self::assertSame([$second->id(), $first->id(), $third->id()], array_column($children, 'venue_id'));
        self::assertSame(['Erste Wahl', 'Zweite Wahl', 'Dritte Wahl'], array_column($children, 'venue_name'));
        self::assertSame([$brief->id, $brief->id, $brief->id], array_column($children, 'brief_id'));
        self::assertSame(['Anna', 'Anna', 'Anna'], array_column($children, 'first_name'));
    }

    public function test_records_one_brief_sent_without_a_venue(): void
    {
        $this->submit([$this->venue(), $this->venue(), $this->venue()]);

        self::assertSame(
            [['event' => 'brief_sent', 'venue_id' => null]],
            $this->connection->fetchAllAssociative('SELECT event, venue_id FROM funnel_events'),
        );
    }

    public function test_the_same_brief_again_within_ten_minutes_is_the_same_brief(): void
    {
        $venues = [$this->venue(), $this->venue()];
        $brief = $this->submit($venues);
        self::mockTime('2026-11-09 23:39:00 UTC');

        $repeat = $this->submit($venues);

        self::assertTrue($repeat->isRepeat);
        self::assertSame($brief->id, $repeat->id);
        self::assertSame($brief->inquiryIds, $repeat->inquiryIds);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM inquiry_briefs'));
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT count(*) FROM inquiries'));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM funnel_events WHERE event = 'brief_sent'"));
    }

    public function test_the_same_venues_in_another_order_are_still_a_repeat(): void
    {
        [$a, $b] = [$this->venue(), $this->venue()];
        $brief = $this->submit([$a, $b]);

        $repeat = $this->submit([$b, $a]);

        self::assertTrue($repeat->isRepeat);
        self::assertSame($brief->id, $repeat->id);
    }

    public function test_the_same_brief_after_ten_minutes_is_a_new_brief(): void
    {
        $venues = [$this->venue(), $this->venue()];
        $brief = $this->submit($venues);
        self::mockTime('2026-11-09 23:41:00 UTC');

        $again = $this->submit($venues);

        self::assertFalse($again->isRepeat);
        self::assertNotSame($brief->id, $again->id);
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT count(*) FROM inquiry_briefs'));
        self::assertSame(2, (int) $this->connection->fetchOne("SELECT count(*) FROM funnel_events WHERE event = 'brief_sent'"));
    }

    public function test_another_message_to_the_same_venues_is_a_new_brief(): void
    {
        $venues = [$this->venue(), $this->venue()];
        $this->submit($venues);

        self::assertFalse($this->submit($venues, 'Eine andere Frage.')->isRepeat);
    }

    public function test_a_venue_that_opted_out_refuses_the_whole_brief(): void
    {
        $optedOut = $this->venue();
        $optedOut->changeMultiInquiryConsent(false);
        $this->em->flush();

        $this->assertRefused([$this->venue(), $optedOut, $this->venue()]);
    }

    public function test_an_unpublished_venue_refuses_the_whole_brief(): void
    {
        $this->assertRefused([$this->venue(), $this->venue(status: VenueStatus::Unpublished)]);
    }

    public function test_a_venue_without_an_inquiry_address_refuses_the_whole_brief(): void
    {
        $this->assertRefused([$this->venue(), VenueFactory::new()->withoutInquiryEmail()->create()]);
    }

    public function test_a_venue_at_todays_cap_refuses_the_whole_brief(): void
    {
        $capped = $this->venue();
        $this->briefChildren($capped, BriefRules::DAILY_CAP_PER_VENUE, '2026-11-09 23:10:00 UTC');

        $this->assertRefused([$this->venue(), $capped]);
    }

    public function test_single_inquiries_and_yesterdays_briefs_do_not_count_to_the_cap(): void
    {
        $venue = $this->venue();
        $this->briefChildren($venue, BriefRules::DAILY_CAP_PER_VENUE - 1, '2026-11-09 23:10:00 UTC');
        // 23:50 on 9 November in Zurich: yesterday.
        $this->briefChildren($venue, 3, '2026-11-09 22:50:00 UTC');
        InquiryFactory::createMany(4, ['venue' => $venue, 'createdAt' => new \DateTimeImmutable('2026-11-09 23:20:00 UTC')]);

        $brief = $this->submit([$this->venue(), $venue]);

        self::assertFalse($brief->isRepeat);
        self::assertSame(
            BriefRules::DAILY_CAP_PER_VENUE,
            (int) $this->connection->fetchOne('SELECT count(*) FROM inquiries WHERE venue_id = ? AND brief_id IS NOT NULL AND created_at >= ?', [$venue->id(), '2026-11-09 23:00:00+00']),
        );
    }

    public function test_one_venue_is_refused(): void
    {
        $this->assertRefused([$this->venue()]);
    }

    public function test_six_venues_are_refused(): void
    {
        $this->assertRefused(array_map(fn (): Venue => $this->venue(), range(1, 6)));
    }

    /**
     * @param list<Venue> $venues
     */
    private function assertRefused(array $venues): void
    {
        $before = $this->counts();
        try {
            $this->submit($venues);
            self::fail('The brief was stored.');
        } catch (HandlerFailedException $exception) {
            self::assertCount(1, $exception->getWrappedExceptions(BriefNotAccepted::class));
        }
        $this->em->clear();

        self::assertSame($before, $this->counts(), 'nothing is stored');
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM funnel_events'));
    }

    /**
     * @return array{int, int}
     */
    private function counts(): array
    {
        return [
            (int) $this->connection->fetchOne('SELECT count(*) FROM inquiry_briefs'),
            (int) $this->connection->fetchOne('SELECT count(*) FROM inquiries'),
        ];
    }

    /**
     * Children of one earlier brief to `$venue` and another venue, created at `$at`.
     */
    private function briefChildren(Venue $venue, int $count, string $at): void
    {
        $createdAt = new \DateTimeImmutable($at);
        $brief = InquiryBriefFactory::createOne(['venueCount' => 2, 'createdAt' => $createdAt]);
        InquiryFactory::createMany($count, ['venue' => $venue, 'brief' => $brief, 'createdAt' => $createdAt]);
    }

    private function venue(string $name = 'Seehaus', VenueStatus $status = VenueStatus::Published): Venue
    {
        return VenueFactory::new()->withContact()->create(['name' => $name, 'status' => $status]);
    }

    /**
     * @param list<Venue> $venues
     */
    private function submit(array $venues, string $message = 'Liebes Team'): SubmittedBrief
    {
        $chosen = [];
        foreach ($venues as $venue) {
            $chosen[$venue->id()] = 'slug-'.$venue->number();
        }
        $result = $this->bus->dispatch(new SubmitInquiryBrief(
            $chosen,
            'de',
            'corporate_event',
            null,
            80,
            null,
            true,
            null,
            null,
            null,
            $message,
            'Anna',
            'Muster',
            'anna@example.ch',
            null,
        ))->last(HandledStamp::class)?->getResult();
        self::assertInstanceOf(SubmittedBrief::class, $result);

        return $result;
    }
}
