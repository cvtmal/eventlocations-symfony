<?php

declare(strict_types=1);

namespace App\Tests\Integration\Portal;

use App\Factory\RoomFactory;
use App\Factory\SubscriptionFactory;
use App\Factory\VenueDocumentFactory;
use App\Factory\VenueFactory;
use App\Factory\VenuePhotoFactory;
use App\Portal\ReadModel\LocationPlan;
use App\Portal\ReadModel\LocationPlanQuery;
use App\Tests\Support\Admin\RollsBackDatabase;
use App\Venues\EntitlementResolver;
use App\Venues\Entity\Venue;
use App\Venues\ValueObject\KnownTiers;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The plan card's read model (F12) against the seeded Free and Pro rows: the tier's name and label, the limits of
 * `EntitlementResolver`, every row of the location counted (hidden ones too), and the Pro price read from the Pro row.
 */
final class LocationPlanQueryTest extends KernelTestCase
{
    use Factories;
    use RollsBackDatabase;

    public function test_a_free_location_reads_its_use_against_the_free_limits(): void
    {
        $venue = $this->venue(KnownTiers::FREE);
        $room = RoomFactory::createOne(['venue' => $venue, 'position' => 1]);
        RoomFactory::createOne(['venue' => $venue, 'position' => 2]);
        VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 1]);
        VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 2]);
        VenuePhotoFactory::createOne(['venue' => $venue, 'room' => $room, 'position' => 1]);
        VenueDocumentFactory::createOne(['venue' => $venue]);

        self::assertEquals(
            new LocationPlan('Free', false, true, 3, 6, 2, 1, 1, 1, false, 3, 19900),
            $this->plan($venue),
        );
    }

    public function test_a_pro_location_reads_the_pro_limits_and_cannot_ask_again(): void
    {
        $venue = $this->venue(KnownTiers::PRO);
        VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 1]);

        self::assertEquals(
            new LocationPlan('Pro', true, false, 1, 60, 0, 20, 0, 5, true, null, 19900, false, new \DateTimeImmutable(VenueFactory::TERM_TO), false),
            $this->plan($venue),
        );
    }

    public function test_the_card_names_the_last_paid_day_and_a_cancellation(): void
    {
        $venue = $this->venue(KnownTiers::FREE);
        SubscriptionFactory::createOne(['venue' => $venue, 'startsOn' => new \DateTimeImmutable('2026-01-01'), 'endsOn' => new \DateTimeImmutable('2026-12-31')]);
        SubscriptionFactory::new()->cancelled()->create(['venue' => $venue, 'startsOn' => new \DateTimeImmutable('2027-01-01'), 'endsOn' => new \DateTimeImmutable('2027-12-31')]);

        $plan = $this->plan($venue);

        self::assertSame(['Pro', '2027-12-31', true, false], [$plan->tierName, $plan->paidUntil?->format('Y-m-d'), $plan->cancelled, $plan->freeOfCharge]);
    }

    public function test_a_term_free_of_charge_says_so(): void
    {
        $venue = $this->venue(KnownTiers::FREE);
        SubscriptionFactory::new()->freeOfCharge()->create(['venue' => $venue, 'startsOn' => new \DateTimeImmutable('2026-01-01'), 'endsOn' => new \DateTimeImmutable('2026-12-31')]);

        $plan = $this->plan($venue);

        self::assertSame(['Pro', '2026-12-31', true], [$plan->tierName, $plan->paidUntil?->format('Y-m-d'), $plan->freeOfCharge]);
    }

    public function test_a_lapsed_term_names_no_day(): void
    {
        $venue = $this->venue(KnownTiers::FREE);
        SubscriptionFactory::createOne(['venue' => $venue, 'startsOn' => new \DateTimeImmutable('2025-01-01'), 'endsOn' => new \DateTimeImmutable('2025-12-31')]);

        self::assertSame(['Free', null], [$this->plan($venue)->tierName, $this->plan($venue)->paidUntil]);
    }

    public function test_only_the_locations_own_rows_are_counted(): void
    {
        $venue = $this->venue(KnownTiers::FREE);
        $other = $this->venue(KnownTiers::FREE);
        VenuePhotoFactory::createOne(['venue' => $other, 'position' => 1]);
        RoomFactory::createOne(['venue' => $other, 'position' => 1]);
        VenueDocumentFactory::createOne(['venue' => $other]);

        $plan = $this->plan($venue);

        self::assertSame([0, 0, 0], [$plan->photosUsed, $plan->roomsUsed, $plan->documentsUsed]);
    }

    public function test_the_limits_follow_the_tier_row(): void
    {
        $venue = $this->venue(KnownTiers::FREE);
        $this->connection->executeStatement("UPDATE tiers SET photos_max = 9, rooms_max = 4, documents_max = 3, contact_visible = TRUE, event_types_max = NULL WHERE code = 'free'");

        $plan = $this->plan($venue);

        self::assertSame([9, 4, 3, true, null], [$plan->photosMax, $plan->roomsMax, $plan->documentsMax, $plan->contactVisible, $plan->eventTypesMax]);
    }

    public function test_the_price_is_the_pro_rows_whatever_the_tier_of_the_location(): void
    {
        $venue = $this->venue(KnownTiers::FREE);
        $this->connection->executeStatement("UPDATE tiers SET price_rappen = 24950 WHERE code = 'pro'");

        self::assertSame(24950, $this->plan($venue)->proPriceRappen);
        self::assertSame(24950, $this->plan($this->venue(KnownTiers::PRO))->proPriceRappen);
    }

    public function test_the_vat_line_is_the_pro_rows_and_off_by_default(): void
    {
        $venue = $this->venue(KnownTiers::FREE);
        self::assertFalse($this->plan($venue)->proVatLine);

        $this->connection->executeStatement("UPDATE tiers SET vat_line = TRUE WHERE code = 'pro'");

        self::assertTrue($this->plan($venue)->proVatLine);
    }

    public function test_a_tier_with_a_rank_weight_is_promoted_and_one_without_is_not(): void
    {
        $venue = $this->venue(KnownTiers::FREE);
        self::assertFalse($this->plan($venue)->promoted);

        $this->connection->executeStatement("UPDATE tiers SET rank_weight = 5 WHERE code = 'free'");

        self::assertTrue($this->plan($venue)->promoted);
    }

    public function test_without_a_pro_row_there_is_nothing_to_ask_for(): void
    {
        $venue = $this->venue(KnownTiers::FREE);
        $this->connection->executeStatement("DELETE FROM tiers WHERE code = 'pro'");

        $plan = $this->plan($venue);

        self::assertFalse($plan->canRequestPro);
        self::assertNull($plan->proPriceRappen);
    }

    private function venue(string $tier): Venue
    {
        return VenueFactory::new()->withoutLocation()->create(['tier' => $tier]);
    }

    private function plan(Venue $venue): LocationPlan
    {
        $query = new LocationPlanQuery($this->connection, new EntitlementResolver($this->connection));

        return $query->forVenue($venue->id(), new \DateTimeImmutable('2026-11-07 12:00:00'));
    }
}
