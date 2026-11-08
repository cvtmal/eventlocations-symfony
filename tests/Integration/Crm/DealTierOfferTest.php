<?php

declare(strict_types=1);

namespace App\Tests\Integration\Crm;

use App\Crm\ReadModel\DealTierOfferQuery;
use App\Factory\CompanyFactory;
use App\Factory\DealFactory;
use App\Factory\VenueFactory;
use App\Tests\Support\Admin\RollsBackDatabase;
use App\Venues\ValueObject\KnownTiers;
use App\Venues\ValueObject\TierLabel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * D20's offer, read live: won, a venue and a tier set, the venue still the company's, and another tier on it.
 */
final class DealTierOfferTest extends KernelTestCase
{
    use Factories;
    use RollsBackDatabase;

    public function test_a_won_deal_offers_its_tier(): void
    {
        $deal = $this->deal(KnownTiers::PRO, KnownTiers::FREE, 'won');

        $offer = $this->offers()->forDeal($deal);

        self::assertNotNull($offer);
        self::assertSame($deal, $offer->dealId);
        self::assertSame('Schloss Seeblick', $offer->venueName);
        self::assertEquals(new TierLabel('free', 'Free', false), $offer->current);
        self::assertEquals(new TierLabel('pro', 'Pro', true), $offer->offered);
    }

    public function test_a_lower_tier_than_the_current_one_is_offered_too(): void
    {
        $offer = $this->offers()->forDeal($this->deal(KnownTiers::FREE, KnownTiers::PRO, 'won'));

        self::assertNotNull($offer);
        self::assertEquals(new TierLabel('pro', 'Pro', true), $offer->current);
        self::assertEquals(new TierLabel('free', 'Free', false), $offer->offered);
    }

    public function test_an_equal_tier_offers_nothing(): void
    {
        self::assertNull($this->offers()->forDeal($this->deal(KnownTiers::PRO, KnownTiers::PRO, 'won')));
        self::assertNull($this->offers()->forDeal($this->deal(KnownTiers::FREE, KnownTiers::FREE, 'won')));
    }

    public function test_a_later_change_offers_it_again(): void
    {
        $deal = $this->deal(KnownTiers::PRO, KnownTiers::PRO, 'won');
        $this->connection->executeStatement("UPDATE venues SET tier_id = (SELECT id FROM tiers WHERE code = 'free') WHERE name = 'Schloss Seeblick'");

        self::assertSame('free', $this->offers()->forDeal($deal)?->current->code);
    }

    public function test_a_renamed_tier_is_offered_under_its_new_name(): void
    {
        $deal = $this->deal(KnownTiers::PRO, KnownTiers::FREE, 'won');
        $this->connection->executeStatement("UPDATE tiers SET name = 'Premium' WHERE code = 'pro'");

        self::assertSame('Premium', $this->offers()->forDeal($deal)?->offered->name);
    }

    public function test_a_deal_not_won_offers_nothing(): void
    {
        foreach (['new', 'negotiation', 'lost'] as $stage) {
            self::assertNull($this->offers()->forDeal($this->deal(KnownTiers::PRO, KnownTiers::FREE, $stage)), $stage);
        }
    }

    public function test_a_deal_without_tier_offers_nothing(): void
    {
        self::assertNull($this->offers()->forDeal($this->deal(null, KnownTiers::FREE, 'won')));
    }

    public function test_a_deal_without_venue_offers_nothing(): void
    {
        $deal = DealFactory::new()->inStage('won')->create(['tier' => KnownTiers::PRO]);

        self::assertNull($this->offers()->forDeal($deal->id()));
    }

    public function test_a_venue_no_longer_linked_offers_nothing(): void
    {
        $deal = $this->deal(KnownTiers::PRO, KnownTiers::FREE, 'won');
        $this->connection->executeStatement('UPDATE crm_companies SET venue_id = NULL');

        self::assertNull($this->offers()->forDeal($deal));
    }

    public function test_a_venue_linked_to_another_company_offers_nothing(): void
    {
        $deal = $this->deal(KnownTiers::PRO, KnownTiers::FREE, 'won');
        // Released from the deal's Location, then assigned to another one (the unique key forbids one step).
        $venueId = $this->connection->fetchOne('SELECT venue_id FROM crm_deals WHERE id = ?', [$deal]);
        $this->connection->executeStatement('UPDATE crm_companies SET venue_id = NULL WHERE venue_id = ?', [$venueId]);
        $this->connection->executeStatement('UPDATE crm_companies SET venue_id = ? WHERE id = ?', [$venueId, CompanyFactory::createOne()->id()]);

        self::assertNull($this->offers()->forDeal($deal));
    }

    public function test_an_unknown_or_malformed_id_offers_nothing(): void
    {
        self::assertNull($this->offers()->forDeal('019a0000-0000-7000-8000-000000000000'));
        self::assertNull($this->offers()->forDeal('nope'));
    }

    private function deal(?string $dealTier, string $venueTier, string $stage): string
    {
        $venue = VenueFactory::new()->withoutLocation(null)->create(['name' => 'Schloss Seeblick', 'tier' => $venueTier]);
        $company = CompanyFactory::new()->withVenue($venue)->create();

        return DealFactory::new()->inStage($stage)->create(['company' => $company, 'venue' => $venue, 'tier' => $dealTier])->id();
    }

    private function offers(): DealTierOfferQuery
    {
        $offers = self::getContainer()->get(DealTierOfferQuery::class);
        self::assertInstanceOf(DealTierOfferQuery::class, $offers);

        return $offers;
    }
}
