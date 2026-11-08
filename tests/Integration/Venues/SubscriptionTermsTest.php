<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues;

use App\Factory\SubscriptionFactory;
use App\Factory\VenueFactory;
use App\Tests\Support\Admin\RollsBackDatabase;
use App\Tests\Support\Http\RecordingHttpCachePurger;
use App\Venues\Command\CancelSubscription;
use App\Venues\Command\RecordSubscriptionPayment;
use App\Venues\EntitlementResolver;
use App\Venues\Entity\Venue;
use App\Venues\Exception\SubscriptionNotFound;
use App\Venues\Exception\SubscriptionRefused;
use App\Venues\ValueObject\KnownTiers;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Zenstruck\Foundry\Test\Factories;

/**
 * The paid term (G9): the entitlements read the term's dates in Zurich days, a payment keeps `venues.tier_id` in step
 * and purges, a cancellation keeps the tier to the end, and the funnel gets `pro_paid` and `subscription_cancelled`.
 */
final class SubscriptionTermsTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;
    use RollsBackDatabase {
        setUp as rollBackSetUp;
    }

    protected function setUp(): void
    {
        $this->rollBackSetUp();
        self::mockTime('2026-11-08 10:00:00 Europe/Zurich');
    }

    public function test_the_entitlements_follow_the_term_day_by_day_in_zurich(): void
    {
        $venue = VenueFactory::new()->withoutLocation()->create();
        SubscriptionFactory::createOne(['venue' => $venue, 'startsOn' => new \DateTimeImmutable('2026-11-08'), 'endsOn' => new \DateTimeImmutable('2027-11-07')]);
        $resolver = $this->resolver();

        foreach ([
            '2026-11-07 22:59:59 UTC' => KnownTiers::FREE, // 23:59:59 in Zurich, the day before the term
            '2026-11-07 23:00:00 UTC' => KnownTiers::PRO, // midnight in Zurich, the first day
            '2027-11-07 22:59:59 UTC' => KnownTiers::PRO, // the last second of the last day
            '2027-11-07 23:00:00 UTC' => KnownTiers::FREE, // lapsed: Free, whatever venues.tier_id says
        ] as $instant => $tier) {
            self::assertSame($tier, $resolver->forVenue($venue->id(), new MockClock($instant)->now())->tierCode, $instant);
        }
    }

    public function test_a_payment_covering_today_lifts_the_tier_purges_and_records_pro_paid(): void
    {
        $venue = VenueFactory::new()->withoutLocation()->create();
        $purger = $this->purger();

        $id = $this->dispatch(new RecordSubscriptionPayment($venue->number(), KnownTiers::PRO, new \DateTimeImmutable('2026-11-08'), new \DateTimeImmutable('2027-11-07'), ' RE-1 '));

        self::assertIsString($id);
        self::assertSame(KnownTiers::PRO, $this->tierOf($venue));
        self::assertSame(['RE-1', 'active'], array_values((array) $this->connection->fetchAssociative('SELECT invoice_ref, status FROM subscriptions WHERE id = ?', [$id])));
        self::assertSame([$venue->number()], $purger->venues);
        self::assertSame(1, $purger->listings);
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM funnel_events WHERE event = 'pro_paid' AND venue_id = ?", [$venue->id()]));
    }

    public function test_a_payment_in_advance_changes_the_tier_on_its_first_day_only(): void
    {
        $venue = VenueFactory::new()->withoutLocation()->create();

        $this->dispatch(new RecordSubscriptionPayment($venue->number(), KnownTiers::PRO, new \DateTimeImmutable('2027-01-01'), new \DateTimeImmutable('2027-12-31')));

        self::assertSame(KnownTiers::FREE, $this->tierOf($venue));
        self::assertSame([], $this->purger()->venues);
    }

    public function test_a_tier_without_a_price_an_overlap_and_a_past_term_are_refused(): void
    {
        $venue = VenueFactory::new()->withoutLocation()->create();
        SubscriptionFactory::createOne(['venue' => $venue, 'startsOn' => new \DateTimeImmutable('2026-01-01'), 'endsOn' => new \DateTimeImmutable('2026-12-31')]);

        self::assertSame(SubscriptionRefused::FREE_TIER, $this->refusal(new RecordSubscriptionPayment($venue->number(), KnownTiers::FREE, new \DateTimeImmutable('2027-01-01'), new \DateTimeImmutable('2027-12-31'))));
        self::assertSame(SubscriptionRefused::OVERLAP, $this->refusal(new RecordSubscriptionPayment($venue->number(), KnownTiers::PRO, new \DateTimeImmutable('2026-12-31'), new \DateTimeImmutable('2027-12-30'))));
        self::assertSame(SubscriptionRefused::PAST, $this->refusal(new RecordSubscriptionPayment($venue->number(), KnownTiers::PRO, new \DateTimeImmutable('2025-11-01'), new \DateTimeImmutable('2026-11-07'))));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM subscriptions WHERE venue_id = ?', [$venue->id()]));

        // The day after the end is free to start the next term.
        $this->dispatch(new RecordSubscriptionPayment($venue->number(), KnownTiers::PRO, new \DateTimeImmutable('2027-01-01'), new \DateTimeImmutable('2027-12-31')));
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT count(*) FROM subscriptions WHERE venue_id = ?', [$venue->id()]));
    }

    public function test_a_cancellation_keeps_the_tier_to_the_end_and_counts_once(): void
    {
        $venue = VenueFactory::new()->withoutLocation()->create();
        $term = SubscriptionFactory::createOne(['venue' => $venue, 'startsOn' => new \DateTimeImmutable('2026-01-01'), 'endsOn' => new \DateTimeImmutable('2026-12-31')]);
        $purger = $this->purger();

        self::assertTrue($this->dispatch(new CancelSubscription($venue->number(), $term->id())));
        self::assertFalse($this->dispatch(new CancelSubscription($venue->number(), $term->id())));

        self::assertNotNull($this->connection->fetchOne('SELECT cancel_requested_at FROM subscriptions WHERE id = ?', [$term->id()]));
        self::assertSame(KnownTiers::PRO, $this->resolver()->forVenue($venue->id(), new MockClock('2026-12-31 12:00:00 Europe/Zurich')->now())->tierCode);
        self::assertSame([], $purger->venues, 'nothing public changes on the day of the cancellation');
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM funnel_events WHERE event = 'subscription_cancelled' AND venue_id = ?", [$venue->id()]));
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT count(*) FROM admin_audit_log WHERE command = ? AND subject_id = ?', [CancelSubscription::class, (string) $venue->number()]));
    }

    public function test_an_ended_term_or_another_venue_s_term_cannot_be_cancelled(): void
    {
        $venue = VenueFactory::new()->withoutLocation()->create();
        $ended = SubscriptionFactory::createOne(['venue' => $venue, 'startsOn' => new \DateTimeImmutable('2025-11-01'), 'endsOn' => new \DateTimeImmutable('2026-11-07')]);
        $other = VenueFactory::new()->withoutLocation()->create();

        self::assertSame(SubscriptionRefused::ENDED, $this->refusal(new CancelSubscription($venue->number(), $ended->id())));
        try {
            $this->dispatch(new CancelSubscription($other->number(), $ended->id()));
            self::fail('Another venue\'s term should not be found.');
        } catch (HandlerFailedException $failure) {
            self::assertCount(1, $failure->getWrappedExceptions(SubscriptionNotFound::class));
        }
    }

    private function dispatch(object $command): mixed
    {
        $bus = self::getContainer()->get(MessageBusInterface::class);
        self::assertInstanceOf(MessageBusInterface::class, $bus);

        return $bus->dispatch($command)->last(HandledStamp::class)?->getResult();
    }

    private function refusal(object $command): string
    {
        try {
            $this->dispatch($command);
        } catch (HandlerFailedException $failure) {
            $refused = array_values($failure->getWrappedExceptions(SubscriptionRefused::class))[0] ?? null;
            self::assertInstanceOf(SubscriptionRefused::class, $refused);

            return $refused->reason;
        }

        self::fail('The command should have been refused.');
    }

    private function resolver(): EntitlementResolver
    {
        $resolver = self::getContainer()->get(EntitlementResolver::class);
        self::assertInstanceOf(EntitlementResolver::class, $resolver);

        return $resolver;
    }

    private function tierOf(Venue $venue): string
    {
        return (string) $this->connection->fetchOne('SELECT t.code FROM venues v JOIN tiers t ON t.id = v.tier_id WHERE v.id = ?', [$venue->id()]);
    }

    private function purger(): RecordingHttpCachePurger
    {
        $purger = self::getContainer()->get(RecordingHttpCachePurger::class);
        self::assertInstanceOf(RecordingHttpCachePurger::class, $purger);

        return $purger;
    }
}
