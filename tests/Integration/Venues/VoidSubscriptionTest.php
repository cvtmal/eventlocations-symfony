<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues;

use App\Factory\SubscriptionFactory;
use App\Factory\VenueFactory;
use App\Shared\Command\RecordFunnelEvent;
use App\Shared\Funnel\FunnelEvent;
use App\Tests\Support\Admin\RollsBackDatabase;
use App\Tests\Support\Http\RecordingHttpCachePurger;
use App\Venues\Command\RecordSubscriptionPayment;
use App\Venues\Command\VoidSubscription;
use App\Venues\Entity\Subscription;
use App\Venues\Entity\Venue;
use App\Venues\Exception\SubscriptionNotFound;
use App\Venues\Exception\SubscriptionRefused;
use App\Venues\ValueObject\KnownTiers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Zenstruck\Foundry\Test\Factories;

/**
 * Voiding a term recorded by mistake (G9 finish, D42): the row and its reminder markers go, `venues.tier_id` follows,
 * the venue and the listings are purged even when the tier stays, and the term's `pro_paid` (only that one) is taken
 * back, so the scorecard counts no paying venue that never paid (D18).
 */
final class VoidSubscriptionTest extends KernelTestCase
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

    public function test_voiding_the_running_term_drops_the_venue_to_free_and_withdraws_its_pro_paid(): void
    {
        $venue = $this->freeVenue();
        $this->dispatch(new RecordFunnelEvent(FunnelEvent::ProPaid, $venue->id())); // an earlier year's payment
        $this->connection->executeStatement("UPDATE funnel_events SET occurred_at = occurred_at - INTERVAL '1 year' WHERE venue_id = ?", [$venue->id()]);
        $id = $this->dispatch(new RecordSubscriptionPayment($venue->number(), KnownTiers::PRO, new \DateTimeImmutable('2026-11-08'), new \DateTimeImmutable('2027-11-07')));
        self::assertIsString($id);
        $this->connection->executeStatement('INSERT INTO portal_subscription_reminders (id, subscription_id, days_before, sent_at) VALUES (uuidv7(), ?, 30, now())', [$id]);
        $purger = $this->purger();
        $purger->venues = [];
        $purger->listings = 0;

        $this->dispatch($this->void($venue, $id));

        self::assertSame(KnownTiers::FREE, $this->tierOf($venue));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM subscriptions WHERE venue_id = ?', [$venue->id()]));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM portal_subscription_reminders WHERE subscription_id = ?', [$id]));
        self::assertSame([$venue->number()], $purger->venues);
        self::assertSame(1, $purger->listings);
        $left = $this->connection->fetchFirstColumn("SELECT occurred_at FROM funnel_events WHERE event = 'pro_paid' AND venue_id = ?", [$venue->id()]);
        self::assertCount(1, $left, 'only the voided payment is withdrawn');
        self::assertStringStartsWith('2025-', (string) $left[0]);
        self::assertSame(['subscription'], json_decode((string) $this->connection->fetchOne(
            "SELECT changes->'changed' FROM admin_audit_log WHERE subject_type = 'venue' AND subject_id = ? AND command LIKE '%VoidSubscription'",
            [(string) $venue->number()],
        ), true));
    }

    public function test_voiding_a_term_paid_in_advance_keeps_the_tier_and_still_purges(): void
    {
        $venue = $this->freeVenue();
        SubscriptionFactory::createOne(['venue' => $venue, 'startsOn' => new \DateTimeImmutable('2025-12-01'), 'endsOn' => new \DateTimeImmutable('2026-11-30')]);
        $this->connection->executeStatement("UPDATE venues SET tier_id = (SELECT id FROM tiers WHERE code = 'pro') WHERE id = ?", [$venue->id()]);
        $next = SubscriptionFactory::createOne(['venue' => $venue, 'startsOn' => new \DateTimeImmutable('2026-12-01'), 'endsOn' => new \DateTimeImmutable('2027-11-30')]);
        $purger = $this->purger();

        $this->dispatch($this->void($venue, $next->id()));

        self::assertSame(KnownTiers::PRO, $this->tierOf($venue));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM subscriptions WHERE venue_id = ?', [$venue->id()]));
        self::assertSame([$venue->number()], $purger->venues);
        self::assertSame(1, $purger->listings);
    }

    public function test_voiding_a_free_term_withdraws_nothing(): void
    {
        $venue = $this->freeVenue();
        $this->dispatch(new RecordFunnelEvent(FunnelEvent::ProPaid, $venue->id()));
        $term = SubscriptionFactory::new()->freeOfCharge()->create(['venue' => $venue, 'startsOn' => new \DateTimeImmutable('2026-11-08'), 'endsOn' => new \DateTimeImmutable('2027-11-07')]);

        $this->dispatch($this->void($venue, $term->id()));

        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM funnel_events WHERE event = 'pro_paid' AND venue_id = ?", [$venue->id()]));
    }

    public function test_an_ended_term_another_venue_s_term_and_other_facts_are_refused(): void
    {
        $venue = $this->freeVenue();
        $ended = SubscriptionFactory::createOne(['venue' => $venue, 'startsOn' => new \DateTimeImmutable('2025-11-01'), 'endsOn' => new \DateTimeImmutable('2026-10-31')]);
        $running = SubscriptionFactory::createOne(['venue' => $venue, 'startsOn' => new \DateTimeImmutable('2026-11-01'), 'endsOn' => new \DateTimeImmutable('2027-10-31')]);
        $other = $this->freeVenue();

        self::assertSame(SubscriptionRefused::class, $this->refusal($this->void($venue, $ended->id())));
        self::assertSame(SubscriptionNotFound::class, $this->refusal($this->void($other, $running->id())));
        self::assertSame(SubscriptionNotFound::class, $this->refusal(new VoidSubscription($venue->number(), $running->id(), KnownTiers::PRO, new \DateTimeImmutable('2026-11-02'), new \DateTimeImmutable('2027-10-31'), 19900)));
        self::assertSame(SubscriptionNotFound::class, $this->refusal(new VoidSubscription($venue->number(), $running->id(), KnownTiers::PRO, new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2027-10-31'), 0)), 'another amount');
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT count(*) FROM subscriptions WHERE venue_id = ?', [$venue->id()]));
    }

    public function test_only_the_latest_term_goes_and_one_ending_today_still_does(): void
    {
        $venue = $this->freeVenue();
        $running = SubscriptionFactory::createOne(['venue' => $venue, 'startsOn' => new \DateTimeImmutable('2025-11-09'), 'endsOn' => new \DateTimeImmutable('2026-11-08')]);
        $next = SubscriptionFactory::createOne(['venue' => $venue, 'startsOn' => new \DateTimeImmutable('2026-11-09'), 'endsOn' => new \DateTimeImmutable('2027-11-08')]);

        try {
            $this->dispatch($this->void($venue, $running));
            self::fail('A term a renewal follows should stay.');
        } catch (HandlerFailedException $failure) {
            $refused = array_values($failure->getWrappedExceptions(SubscriptionRefused::class))[0] ?? null;
            self::assertInstanceOf(SubscriptionRefused::class, $refused);
            self::assertSame(SubscriptionRefused::LATER, $refused->reason);
        }

        $this->dispatch($this->void($venue, $next));
        $this->dispatch($this->void($venue, $running)); // its last day is today

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM subscriptions WHERE venue_id = ?', [$venue->id()]));
    }

    public function test_a_payment_without_its_own_funnel_row_takes_no_older_one_with_it(): void
    {
        $venue = $this->freeVenue();
        $this->dispatch(new RecordFunnelEvent(FunnelEvent::ProPaid, $venue->id()));
        $this->connection->executeStatement("UPDATE funnel_events SET occurred_at = occurred_at - INTERVAL '1 year' WHERE venue_id = ?", [$venue->id()]);
        $term = SubscriptionFactory::createOne(['venue' => $venue, 'startsOn' => new \DateTimeImmutable('2026-11-08'), 'endsOn' => new \DateTimeImmutable('2027-11-07')]);

        $this->dispatch($this->void($venue, $term));

        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM funnel_events WHERE event = 'pro_paid' AND venue_id = ?", [$venue->id()]));
    }

    private function void(Venue $venue, string|Subscription $term): VoidSubscription
    {
        $subscription = \is_string($term) ? $this->em()->find(Subscription::class, $term) : $term;
        self::assertInstanceOf(Subscription::class, $subscription);

        return new VoidSubscription($venue->number(), $subscription->id(), $subscription->tier()->code(), $subscription->startsOn(), $subscription->endsOn(), $subscription->amountRappen());
    }

    private function freeVenue(): Venue
    {
        $venue = VenueFactory::new()->withoutLocation()->create(['tier' => KnownTiers::FREE]);
        $this->connection->executeStatement('DELETE FROM subscriptions WHERE venue_id = ?', [$venue->id()]);

        return $venue;
    }

    private function dispatch(object $command): mixed
    {
        $bus = self::getContainer()->get(MessageBusInterface::class);
        self::assertInstanceOf(MessageBusInterface::class, $bus);

        return $bus->dispatch($command)->last(HandledStamp::class)?->getResult();
    }

    /**
     * @return class-string the domain exception the command failed with
     */
    private function refusal(object $command): string
    {
        try {
            $this->dispatch($command);
        } catch (HandlerFailedException $failure) {
            $wrapped = array_values($failure->getWrappedExceptions())[0] ?? null;
            self::assertNotNull($wrapped);

            return $wrapped::class;
        }

        self::fail('The command should have been refused.');
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
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
