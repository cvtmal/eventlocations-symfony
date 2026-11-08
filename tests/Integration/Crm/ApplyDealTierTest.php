<?php

declare(strict_types=1);

namespace App\Tests\Integration\Crm;

use App\Crm\Command\ApplyDealTier;
use App\Crm\Entity\Deal;
use App\Crm\Exception\DealTierNotApplicable;
use App\Crm\Exception\StaleProperty;
use App\Crm\ReadModel\TimelineEntry;
use App\Crm\ReadModel\TimelineQuery;
use App\Crm\ValueObject\RecordKind;
use App\Factory\CompanyFactory;
use App\Factory\DealFactory;
use App\Factory\VenueFactory;
use App\Tests\Support\Admin\RollsBackDatabase;
use App\Tests\Support\Http\RecordingHttpCachePurger;
use App\Venues\Command\ChangeVenueTier;
use App\Venues\Entity\Venue;
use App\Venues\ValueObject\KnownTiers;
use Doctrine\DBAL\Exception\DriverException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Zenstruck\Foundry\Test\Factories;

/**
 * "Tarif anwenden" (D19): two audit rows, purges after the commit, stale when the tier moved, and a failure
 * on either side of the nested dispatch leaves nothing behind (Probe 3).
 */
final class ApplyDealTierTest extends KernelTestCase
{
    use DispatchesCrmCommands;
    use Factories;
    use RollsBackDatabase;

    public function test_the_tier_reaches_the_venue_with_two_audit_rows(): void
    {
        ['deal' => $deal, 'venue' => $venue] = $this->wonDeal();
        $purger = $this->purger();
        $purger->onListings = function () use ($deal, $venue): void {
            self::assertSame(1, \count($this->auditRows('deal', $deal->id())), 'The purge runs after the outer command, not inside it.');
            self::assertSame(1, \count($this->auditRows('venue', (string) $venue->number())));
        };

        $this->dispatch(new ApplyDealTier($deal->id(), KnownTiers::FREE));

        self::assertSame(KnownTiers::PRO, $this->tierOf($venue));
        self::assertSame(
            [['command' => ApplyDealTier::class, 'changes' => ['expected' => 'free']]],
            $this->auditRows('deal', $deal->id()),
        );
        self::assertSame(
            [['command' => ChangeVenueTier::class, 'changes' => ['tier' => 'pro', 'changed' => ['tier']]]],
            $this->auditRows('venue', (string) $venue->number()),
        );
        self::assertSame([$venue->number()], $purger->venues);
        self::assertSame(1, $purger->listings);
    }

    public function test_the_change_shows_on_the_company_timeline(): void
    {
        ['deal' => $deal, 'venue' => $venue, 'companyId' => $companyId] = $this->wonDeal();

        $this->dispatch(new ApplyDealTier($deal->id(), KnownTiers::FREE));

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

    public function test_another_tier_than_the_expected_one_is_stale(): void
    {
        ['deal' => $deal, 'venue' => $venue] = $this->wonDeal();
        $purger = $this->purger();

        $failure = $this->failure(new ApplyDealTier($deal->id(), KnownTiers::PRO), StaleProperty::class);

        self::assertSame('tier', $failure->field);
        self::assertSame(KnownTiers::FREE, $this->tierOf($venue));
        self::assertSame([], $this->auditRows('deal', $deal->id()));
        self::assertSame([], $purger->venues);
    }

    public function test_an_expected_code_that_no_tier_has_is_stale(): void
    {
        ['deal' => $deal, 'venue' => $venue] = $this->wonDeal();

        $this->failure(new ApplyDealTier($deal->id(), 'gold'), StaleProperty::class);

        self::assertSame(KnownTiers::FREE, $this->tierOf($venue));
        self::assertSame([], $this->auditRows('deal', $deal->id()));
    }

    public function test_a_deal_that_is_not_won_applies_nothing(): void
    {
        ['deal' => $deal, 'venue' => $venue] = $this->wonDeal('negotiation');

        $this->failure(new ApplyDealTier($deal->id(), KnownTiers::FREE), DealTierNotApplicable::class);

        self::assertSame(KnownTiers::FREE, $this->tierOf($venue));
    }

    public function test_a_deal_without_a_tier_or_a_venue_applies_nothing(): void
    {
        ['deal' => $noTier] = $this->wonDeal(tier: null);
        $noVenue = DealFactory::new()->inStage('won')->create(['tier' => KnownTiers::PRO]);

        $this->failure(new ApplyDealTier($noTier->id(), KnownTiers::FREE), DealTierNotApplicable::class);
        $this->failure(new ApplyDealTier($noVenue->id(), KnownTiers::FREE), DealTierNotApplicable::class);
    }

    public function test_a_venue_no_longer_linked_applies_nothing(): void
    {
        ['deal' => $deal, 'venue' => $venue] = $this->wonDeal();
        $this->connection->executeStatement('UPDATE crm_companies SET venue_id = NULL');

        $this->failure(new ApplyDealTier($deal->id(), KnownTiers::FREE), DealTierNotApplicable::class);

        self::assertSame(KnownTiers::FREE, $this->tierOf($venue));
    }

    public function test_a_tier_already_on_the_venue_applies_nothing(): void
    {
        ['deal' => $deal, 'venue' => $venue] = $this->wonDeal(venueTier: KnownTiers::PRO);

        $this->failure(new ApplyDealTier($deal->id(), KnownTiers::PRO), DealTierNotApplicable::class);

        self::assertSame([], $this->auditRows('venue', (string) $venue->number()));
    }

    public function test_a_failure_inside_the_nested_command_leaves_nothing_and_the_next_dispatch_works(): void
    {
        ['deal' => $deal, 'venue' => $venue] = $this->wonDeal();
        $purger = $this->purger();
        $this->refuse('BEFORE UPDATE ON venues');

        $this->assertDatabaseFailure(new ApplyDealTier($deal->id(), KnownTiers::FREE));

        self::assertSame(KnownTiers::FREE, $this->tierOf($venue));
        self::assertSame([], $this->auditRows('deal', $deal->id()));
        self::assertSame([], $this->auditRows('venue', (string) $venue->number()));
        self::assertSame([], $purger->venues);
        self::assertSame(0, $purger->listings);

        $this->allow('venues');
        $this->dispatch(new ApplyDealTier($deal->id(), KnownTiers::FREE));

        self::assertSame(KnownTiers::PRO, $this->tierOf($venue));
        self::assertSame([$venue->number()], $purger->venues);
    }

    public function test_a_failure_after_the_nested_command_undoes_it_and_purges_nothing(): void
    {
        ['deal' => $deal, 'venue' => $venue] = $this->wonDeal();
        $purger = $this->purger();
        // The outer audit row is written after the nested command returned: the last step of the transaction.
        $this->refuse("BEFORE INSERT ON admin_audit_log FOR EACH ROW WHEN (NEW.subject_type = 'deal')", 'admin_audit_log');

        $this->assertDatabaseFailure(new ApplyDealTier($deal->id(), KnownTiers::FREE));

        self::assertSame(KnownTiers::FREE, $this->tierOf($venue));
        self::assertSame([], $this->auditRows('venue', (string) $venue->number()));
        self::assertSame([], $purger->venues);
        self::assertSame(0, $purger->listings);

        $this->allow('admin_audit_log');
        $this->dispatch(new ApplyDealTier($deal->id(), KnownTiers::FREE));

        self::assertSame(KnownTiers::PRO, $this->tierOf($venue));
        self::assertSame(1, \count($this->auditRows('deal', $deal->id())));
    }

    /**
     * @return array{deal: Deal, venue: Venue, companyId: string}
     */
    private function wonDeal(string $stage = 'won', ?string $tier = KnownTiers::PRO, string $venueTier = KnownTiers::FREE): array
    {
        $venue = VenueFactory::new()->withoutLocation(null)->create(['tier' => $venueTier]);
        $company = CompanyFactory::new()->withVenue($venue)->create();
        $deal = DealFactory::new()->inStage($stage)->create(['company' => $company, 'venue' => $venue, 'tier' => $tier]);
        $this->em->clear();

        return ['deal' => $deal, 'venue' => $venue, 'companyId' => $company->id()];
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
