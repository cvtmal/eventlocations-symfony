<?php

declare(strict_types=1);

namespace App\Tests\Integration\Crm;

use App\Crm\Command\MarkDealPaid;
use App\Crm\Entity\Deal;
use App\Crm\Exception\DealNotPayable;
use App\Crm\Exception\StaleProperty;
use App\Crm\ReadModel\TimelineEntry;
use App\Crm\ReadModel\TimelineQuery;
use App\Crm\ValueObject\RecordKind;
use App\Factory\CompanyFactory;
use App\Factory\DealFactory;
use App\Factory\SubscriptionFactory;
use App\Factory\VenueFactory;
use App\Tests\Support\Admin\RollsBackDatabase;
use App\Tests\Support\Http\RecordingHttpCachePurger;
use App\Venues\Command\ChangeVenueTier;
use App\Venues\Command\RecordSubscriptionPayment;
use App\Venues\Entity\Venue;
use App\Venues\Exception\SubscriptionRefused;
use App\Venues\ValueObject\KnownTiers;
use Doctrine\DBAL\Exception\DriverException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Zenstruck\Foundry\Test\Factories;

/**
 * «Zahlung erfassen» (G9, D19): the deal's term reaches the venue with three audit rows, the tier and the purges follow
 * after the commit, a second click is stale, and a failure on either side of the nested dispatch leaves nothing.
 */
final class MarkDealPaidTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use DispatchesCrmCommands;
    use Factories;
    use RollsBackDatabase {
        setUp as rollBackSetUp;
    }

    protected function setUp(): void
    {
        $this->rollBackSetUp();
        self::mockTime('2026-11-08 10:00:00 Europe/Zurich');
    }

    public function test_a_deal_without_dates_buys_a_year_from_today_and_the_dates_go_onto_the_deal(): void
    {
        ['deal' => $deal, 'venue' => $venue] = $this->wonDeal();
        $purger = $this->purger();
        $purger->onListings = function () use ($deal): void {
            self::assertSame(1, \count($this->auditRows('deal', $deal->id())), 'The purge runs after the outer command, not inside it.');
        };

        $this->dispatch(new MarkDealPaid($deal->id(), '', 'RE-2026-17'));

        self::assertSame([['pro', '2026-11-08', '2027-11-07', 'RE-2026-17', 'active']], $this->terms($venue));
        self::assertSame(KnownTiers::PRO, $this->tierOf($venue));
        self::assertSame(['2026-11-08', '2027-11-07'], $this->contractOf($deal));
        self::assertSame(
            [['command' => MarkDealPaid::class, 'changes' => ['expected' => '', 'invoice_ref' => 'RE-2026-17']]],
            $this->auditRows('deal', $deal->id()),
        );
        self::assertSame(
            [ChangeVenueTier::class, RecordSubscriptionPayment::class],
            array_column($this->auditRows('venue', (string) $venue->number()), 'command'),
        );
        self::assertSame([$venue->number()], $purger->venues);
        self::assertSame(1, $purger->listings);
        self::assertSame(1, $this->rows("SELECT count(*) FROM funnel_events WHERE event = 'pro_paid' AND venue_id = ?", $venue->id()));
    }

    public function test_the_deal_s_contract_period_is_the_term(): void
    {
        ['deal' => $deal, 'venue' => $venue] = $this->wonDeal(start: '2027-01-01', end: '2027-12-31');

        $this->dispatch(new MarkDealPaid($deal->id(), ''));

        self::assertSame([['pro', '2027-01-01', '2027-12-31', null, 'active']], $this->terms($venue));
        self::assertSame(KnownTiers::FREE, $this->tierOf($venue), 'A term paid in advance moves the tier on its first day, in the nightly job.');
    }

    public function test_a_renewal_without_dates_follows_on_the_running_term(): void
    {
        ['deal' => $deal, 'venue' => $venue] = $this->wonDeal();
        SubscriptionFactory::createOne(['venue' => $venue, 'startsOn' => new \DateTimeImmutable('2025-12-01'), 'endsOn' => new \DateTimeImmutable('2026-11-30')]);

        $this->dispatch(new MarkDealPaid($deal->id(), '2026-11-30'));

        self::assertSame(
            [['pro', '2025-12-01', '2026-11-30', null, 'active'], ['pro', '2026-12-01', '2027-11-30', null, 'active']],
            $this->terms($venue),
        );
    }

    public function test_the_change_shows_on_the_company_timeline(): void
    {
        ['deal' => $deal, 'venue' => $venue, 'companyId' => $companyId] = $this->wonDeal();

        $this->dispatch(new MarkDealPaid($deal->id(), ''));

        $timeline = self::getContainer()->get(TimelineQuery::class);
        self::assertInstanceOf(TimelineQuery::class, $timeline);
        $system = array_values(array_filter(
            $timeline->page(RecordKind::Company, $companyId, null, null)->entries,
            static fn (TimelineEntry $entry): bool => TimelineEntry::EVENT_TIER_CHANGED === $entry->typeCode,
        ));
        self::assertCount(1, $system);
        self::assertSame($venue->name(), $system[0]->venueName);
        self::assertSame(KnownTiers::PRO, $system[0]->tier);
    }

    public function test_a_second_click_is_stale_and_pays_nothing_twice(): void
    {
        ['deal' => $deal, 'venue' => $venue] = $this->wonDeal();
        $this->dispatch(new MarkDealPaid($deal->id(), ''));

        $failure = $this->failure(new MarkDealPaid($deal->id(), ''), StaleProperty::class);

        self::assertSame('subscription', $failure->field);
        self::assertCount(1, $this->terms($venue));
    }

    public function test_a_term_overlapping_a_paid_one_is_refused(): void
    {
        ['deal' => $deal, 'venue' => $venue] = $this->wonDeal(start: '2026-06-01', end: '2027-05-31');
        SubscriptionFactory::createOne(['venue' => $venue, 'startsOn' => new \DateTimeImmutable('2026-01-01'), 'endsOn' => new \DateTimeImmutable('2026-12-31')]);

        try {
            $this->dispatch(new MarkDealPaid($deal->id(), '2026-12-31'));
            self::fail('An overlapping term should fail.');
        } catch (HandlerFailedException $failure) {
            self::assertCount(1, $failure->getWrappedExceptions(SubscriptionRefused::class, true));
        }

        self::assertCount(1, $this->terms($venue));
        self::assertSame([], $this->auditRows('deal', $deal->id()));
    }

    public function test_a_deal_that_is_not_won_pays_nothing(): void
    {
        ['deal' => $deal, 'venue' => $venue] = $this->wonDeal('negotiation');

        $this->failure(new MarkDealPaid($deal->id(), ''), DealNotPayable::class);

        self::assertSame([], $this->terms($venue));
    }

    public function test_a_deal_without_a_paid_tier_or_a_venue_pays_nothing(): void
    {
        ['deal' => $noTier] = $this->wonDeal(tier: null);
        ['deal' => $free] = $this->wonDeal(tier: KnownTiers::FREE);
        $noVenue = DealFactory::new()->inStage('won')->create(['tier' => KnownTiers::PRO]);

        $this->failure(new MarkDealPaid($noTier->id(), ''), DealNotPayable::class);
        $this->failure(new MarkDealPaid($free->id(), ''), DealNotPayable::class);
        $this->failure(new MarkDealPaid($noVenue->id(), ''), DealNotPayable::class);
    }

    public function test_a_venue_no_longer_linked_pays_nothing(): void
    {
        ['deal' => $deal, 'venue' => $venue] = $this->wonDeal();
        $this->connection->executeStatement('UPDATE crm_companies SET venue_id = NULL');

        $this->failure(new MarkDealPaid($deal->id(), ''), DealNotPayable::class);

        self::assertSame([], $this->terms($venue));
    }

    public function test_a_failure_inside_the_nested_command_leaves_nothing_and_the_next_dispatch_works(): void
    {
        ['deal' => $deal, 'venue' => $venue] = $this->wonDeal();
        $purger = $this->purger();
        $this->refuse('BEFORE UPDATE ON venues');

        $this->assertDatabaseFailure(new MarkDealPaid($deal->id(), ''));

        self::assertSame([], $this->terms($venue));
        self::assertSame(KnownTiers::FREE, $this->tierOf($venue));
        self::assertSame([], $this->auditRows('deal', $deal->id()));
        self::assertSame([], $this->auditRows('venue', (string) $venue->number()));
        self::assertSame([], $purger->venues);
        self::assertSame(0, $purger->listings);

        $this->allow('venues');
        $this->dispatch(new MarkDealPaid($deal->id(), ''));

        self::assertSame(KnownTiers::PRO, $this->tierOf($venue));
        self::assertSame([$venue->number()], $purger->venues);
    }

    public function test_a_failure_after_the_nested_command_undoes_it_and_purges_nothing(): void
    {
        ['deal' => $deal, 'venue' => $venue] = $this->wonDeal();
        $purger = $this->purger();
        // The outer audit row is written after the nested command returned: the last step of the transaction.
        $this->refuse("BEFORE INSERT ON admin_audit_log FOR EACH ROW WHEN (NEW.subject_type = 'deal')", 'admin_audit_log');

        $this->assertDatabaseFailure(new MarkDealPaid($deal->id(), ''));

        self::assertSame([], $this->terms($venue));
        self::assertSame(KnownTiers::FREE, $this->tierOf($venue));
        self::assertSame([], $this->auditRows('venue', (string) $venue->number()));
        self::assertSame([], $purger->venues);
        self::assertSame(0, $purger->listings);
        self::assertSame(0, $this->rows('SELECT count(*) FROM funnel_events WHERE venue_id = ?', $venue->id()));
    }

    /**
     * @return array{deal: Deal, venue: Venue, companyId: string}
     */
    private function wonDeal(string $stage = 'won', ?string $tier = KnownTiers::PRO, ?string $start = null, ?string $end = null): array
    {
        $venue = VenueFactory::new()->withoutLocation(null)->create();
        $company = CompanyFactory::new()->withVenue($venue)->create();
        $deal = DealFactory::new()->inStage($stage)->create([
            'company' => $company,
            'venue' => $venue,
            'tier' => $tier,
            'contractStartOn' => null === $start ? null : new \DateTimeImmutable($start),
            'contractEndOn' => null === $end ? null : new \DateTimeImmutable($end),
        ]);
        $this->em->clear();

        return ['deal' => $deal, 'venue' => $venue, 'companyId' => $company->id()];
    }

    /**
     * @return list<list<mixed>>
     */
    private function terms(Venue $venue): array
    {
        return array_map(array_values(...), $this->connection->fetchAllAssociative(
            'SELECT t.code, s.starts_on, s.ends_on, s.invoice_ref, s.status FROM subscriptions s JOIN tiers t ON t.id = s.tier_id WHERE s.venue_id = ? ORDER BY s.starts_on',
            [$venue->id()],
        ));
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function contractOf(Deal $deal): array
    {
        $row = $this->connection->fetchAssociative('SELECT contract_start_on, contract_end_on FROM crm_deals WHERE id = ?', [$deal->id()]);
        self::assertIsArray($row);

        return [$row['contract_start_on'], $row['contract_end_on']];
    }

    /**
     * A database refusal, as a real failure is, on the test's own transaction. The function lives in `pg_temp`.
     */
    private function refuse(string $when, string $table = 'venues'): void
    {
        $this->connection->executeStatement("CREATE FUNCTION pg_temp.refuse() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION ''refused''; END'");
        $spec = str_contains($when, 'FOR EACH ROW') ? $when : $when.' FOR EACH ROW';
        $this->connection->executeStatement(\sprintf('CREATE TRIGGER refuse_%s %s EXECUTE FUNCTION pg_temp.refuse()', $table, $spec));
    }

    private function allow(string $table): void
    {
        $this->connection->executeStatement(\sprintf('DROP TRIGGER refuse_%1$s ON %1$s', $table));
    }

    private function assertDatabaseFailure(object $command): void
    {
        try {
            $this->dispatch($command);
        } catch (HandlerFailedException|DriverException $failure) {
            self::assertStringContainsString('refused', $failure->getMessage());

            return;
        }

        self::fail('The command should have failed.');
    }

    private function tierOf(Venue $venue): string
    {
        return (string) $this->connection->fetchOne('SELECT t.code FROM venues v JOIN tiers t ON t.id = v.tier_id WHERE v.number = ?', [$venue->number()]);
    }

    private function purger(): RecordingHttpCachePurger
    {
        $purger = self::getContainer()->get(RecordingHttpCachePurger::class);
        self::assertInstanceOf(RecordingHttpCachePurger::class, $purger);

        return $purger;
    }
}
