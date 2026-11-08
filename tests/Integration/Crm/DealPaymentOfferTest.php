<?php

declare(strict_types=1);

namespace App\Tests\Integration\Crm;

use App\Crm\ReadModel\DealPaymentOfferQuery;
use App\Factory\CompanyFactory;
use App\Factory\DealFactory;
use App\Factory\SubscriptionFactory;
use App\Factory\VenueFactory;
use App\Tests\Support\Admin\RollsBackDatabase;
use App\Venues\ValueObject\KnownTiers;
use App\Venues\ValueObject\TierLabel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Test\Factories;

/**
 * Whether a deal has a payment to record (G9), read live: won, a paid tier, the venue still the Location's, and not yet
 * paid through the end of the deal's term.
 */
final class DealPaymentOfferTest extends KernelTestCase
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

    public function test_a_won_deal_offers_a_year_from_today(): void
    {
        ['deal' => $deal] = $this->deal();

        $payment = $this->offers()->forDeal($deal);

        self::assertNotNull($payment->offer);
        self::assertSame($deal, $payment->offer->dealId);
        self::assertSame('Schloss Seeblick', $payment->offer->venueName);
        self::assertEquals(new TierLabel('pro', 'Pro', true), $payment->offer->tier);
        self::assertSame(['2026-11-08', '2027-11-07', ''], [$payment->offer->startsOn->format('Y-m-d'), $payment->offer->endsOn->format('Y-m-d'), $payment->offer->expected]);
        self::assertNull($payment->paidUntil);
    }

    public function test_a_paid_term_through_the_deal_s_end_offers_nothing_but_shows_the_day(): void
    {
        ['deal' => $deal, 'venue' => $venue] = $this->deal(start: '2026-11-01', end: '2027-10-31');
        SubscriptionFactory::new()->cancelled()->create(['venue' => $venue, 'startsOn' => new \DateTimeImmutable('2026-11-01'), 'endsOn' => new \DateTimeImmutable('2027-10-31')]);

        $payment = $this->offers()->forDeal($deal);

        self::assertNull($payment->offer);
        self::assertSame('2027-10-31', $payment->paidUntil?->format('Y-m-d'));
        self::assertNotNull($payment->cancelRequestedAt);
    }

    public function test_a_renewal_deal_offers_its_next_term(): void
    {
        ['deal' => $deal, 'venue' => $venue] = $this->deal(start: '2027-11-01', end: '2028-10-31');
        SubscriptionFactory::createOne(['venue' => $venue, 'startsOn' => new \DateTimeImmutable('2026-11-01'), 'endsOn' => new \DateTimeImmutable('2027-10-31')]);

        $offer = $this->offers()->forDeal($deal)->offer;

        self::assertNotNull($offer);
        self::assertSame(['2027-11-01', '2028-10-31', '2027-10-31'], [$offer->startsOn->format('Y-m-d'), $offer->endsOn->format('Y-m-d'), $offer->expected]);
    }

    public function test_a_term_overlapping_a_paid_one_or_wholly_past_offers_nothing(): void
    {
        ['deal' => $overlapping, 'venue' => $venue] = $this->deal(start: '2026-06-01', end: '2027-05-31');
        SubscriptionFactory::createOne(['venue' => $venue, 'startsOn' => new \DateTimeImmutable('2026-01-01'), 'endsOn' => new \DateTimeImmutable('2026-12-31')]);
        ['deal' => $past] = $this->deal(start: '2025-01-01', end: '2025-12-31');

        self::assertNull($this->offers()->forDeal($overlapping)->offer);
        self::assertSame('2026-12-31', $this->offers()->forDeal($overlapping)->paidUntil?->format('Y-m-d'));
        self::assertNull($this->offers()->forDeal($past)->offer);
    }

    public function test_a_renamed_tier_is_offered_under_its_new_name(): void
    {
        ['deal' => $deal] = $this->deal();
        $this->connection->executeStatement("UPDATE tiers SET name = 'Premium' WHERE code = 'pro'");

        self::assertSame('Premium', $this->offers()->forDeal($deal)->offer?->tier->name);
    }

    public function test_a_deal_not_won_a_free_deal_or_one_without_tier_offers_nothing(): void
    {
        foreach (['new', 'negotiation', 'lost'] as $stage) {
            self::assertNull($this->offers()->forDeal($this->deal(stage: $stage)['deal'])->offer, $stage);
        }
        self::assertNull($this->offers()->forDeal($this->deal(tier: KnownTiers::FREE)['deal'])->offer);
        self::assertNull($this->offers()->forDeal($this->deal(tier: null)['deal'])->offer);
    }

    public function test_a_deal_without_venue_offers_nothing(): void
    {
        $deal = DealFactory::new()->inStage('won')->create(['tier' => KnownTiers::PRO]);

        self::assertNull($this->offers()->forDeal($deal->id())->offer);
    }

    public function test_a_venue_no_longer_linked_offers_nothing(): void
    {
        ['deal' => $deal] = $this->deal();
        $this->connection->executeStatement('UPDATE crm_companies SET venue_id = NULL');

        self::assertNull($this->offers()->forDeal($deal)->offer);
    }

    public function test_an_unknown_or_malformed_id_offers_nothing(): void
    {
        self::assertNull($this->offers()->forDeal('019a0000-0000-7000-8000-000000000000')->offer);
        self::assertNull($this->offers()->forDeal('nope')->offer);
    }

    /**
     * @return array{deal: string, venue: \App\Venues\Entity\Venue}
     */
    private function deal(?string $tier = KnownTiers::PRO, string $stage = 'won', ?string $start = null, ?string $end = null): array
    {
        $venue = VenueFactory::new()->withoutLocation(null)->create(['name' => 'Schloss Seeblick']);
        $company = CompanyFactory::new()->withVenue($venue)->create();
        $deal = DealFactory::new()->inStage($stage)->create([
            'company' => $company,
            'venue' => $venue,
            'tier' => $tier,
            'contractStartOn' => null === $start ? null : new \DateTimeImmutable($start),
            'contractEndOn' => null === $end ? null : new \DateTimeImmutable($end),
        ]);

        return ['deal' => $deal->id(), 'venue' => $venue];
    }

    private function offers(): DealPaymentOfferQuery
    {
        $offers = self::getContainer()->get(DealPaymentOfferQuery::class);
        self::assertInstanceOf(DealPaymentOfferQuery::class, $offers);

        return $offers;
    }
}
