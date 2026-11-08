<?php

declare(strict_types=1);

namespace App\Tests\Integration\Search;

use App\Factory\LastMinuteOfferFactory;
use App\Factory\RoomFactory;
use App\Factory\RoomTranslationFactory;
use App\Search\ReadModel\LastMinuteEntry;
use App\Search\ReadModel\LastMinutePageQuery;
use App\Search\ReadModel\ListingQuery;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Admin\RollsBackDatabase;
use App\Tests\Support\Venues\DetailVenues;
use App\Venues\Entity\Venue;
use App\Venues\LastMinuteOfferPolicy;
use App\Venues\ReadModel\VenueDetailQuery;
use App\Venues\ValueObject\KnownTiers;
use App\Venues\ValueObject\VenueStatus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Test\Factories;

/**
 * Who sees an offer «Kurzfristig frei» (G14, D55, done-when "expiry tests"): every surface (the detail section, the
 * card badge, the page, the sitemap's question) shows it through its last Zurich day and hides it the day after,
 * while the row still waits for the nightly job. A Free venue, a lapsed Pro term and an unpublished venue show none.
 */
final class LastMinuteOfferVisibilityTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;
    use ImportsTownDataset;
    use RollsBackDatabase {
        setUp as rollBackSetUp;
    }

    protected function setUp(): void
    {
        $this->rollBackSetUp();
        self::mockTime('2026-11-08 12:00:00 Europe/Zurich');
    }

    public function test_an_offer_shows_everywhere_through_its_last_day(): void
    {
        $venue = DetailVenues::minimal(tier: KnownTiers::PRO);
        $room = RoomFactory::createOne(['venue' => $venue, 'position' => 1]);
        RoomTranslationFactory::createOne(['room' => $room, 'name' => 'Saal']);
        $this->offer($venue, '2026-11-06', '2026-11-08', ['room' => $room, 'eventTypes' => ['seminar'], 'minGuests' => 20, 'terms' => 'Nur abends.']);
        // 23:59 on the last day, in Zurich: still today there.
        self::mockTime('2026-11-08 22:59:00 UTC');

        $detail = $this->detail($venue);
        self::assertCount(1, $detail);
        self::assertSame('Saal', $detail[0]->roomName);
        self::assertSame('2026-11-06', $detail[0]->startsOn->format('Y-m-d'));
        self::assertSame('2026-11-08', $detail[0]->endsOn->format('Y-m-d'));
        self::assertSame(['seminar'], array_map(static fn ($term): string => $term->code, $detail[0]->eventTypes));
        self::assertSame([20, 'Nur abends.'], [$detail[0]->minGuests, $detail[0]->terms]);
        self::assertTrue($this->badge($venue));
        self::assertSame([$venue->number()], $this->pageNumbers());
        self::assertTrue($this->page()->hasAny());
    }

    public function test_the_day_after_its_last_day_it_is_gone_everywhere_before_the_job_ran(): void
    {
        $venue = DetailVenues::minimal(tier: KnownTiers::PRO);
        $this->offer($venue, '2026-11-08', '2026-11-08');
        // 00:01 on 9 November in Zurich.
        self::mockTime('2026-11-08 23:01:00 UTC');

        self::assertSame([], $this->detail($venue));
        self::assertFalse($this->badge($venue));
        self::assertSame([], $this->pageNumbers());
        self::assertFalse($this->page()->hasAny());
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM last_minute_offers'), 'the row waits for the job');
    }

    public function test_a_free_venue_shows_no_offer(): void
    {
        $venue = DetailVenues::minimal(tier: KnownTiers::FREE);
        $this->offer($venue, '2026-11-10', '2026-11-10');

        self::assertSame([], $this->detail($venue));
        self::assertFalse($this->badge($venue));
        self::assertSame([], $this->pageNumbers());
        self::assertFalse($this->page()->hasAny());
    }

    /**
     * G9: the term ended yesterday, `venues.tier_id` is still Pro until the nightly job; the offer hides already.
     */
    public function test_a_lapsed_pro_term_hides_its_offers_before_the_tier_follows(): void
    {
        $venue = DetailVenues::minimal(tier: KnownTiers::PRO);
        $this->offer($venue, '2026-11-10', '2026-11-10');
        $this->connection->executeStatement("UPDATE subscriptions SET starts_on = '2025-11-08', ends_on = '2026-11-07' WHERE venue_id = ?", [$venue->id()]);

        self::assertSame([], $this->detail($venue));
        self::assertFalse($this->badge($venue));
        self::assertSame([], $this->pageNumbers());
    }

    public function test_an_unpublished_venue_is_not_on_the_page(): void
    {
        $venue = DetailVenues::minimal(tier: KnownTiers::PRO);
        $this->offer($venue, '2026-11-10', '2026-11-10');
        $this->connection->executeStatement('UPDATE venues SET status = ? WHERE id = ?', [VenueStatus::Unpublished->value, $venue->id()]);

        self::assertSame([], $this->pageNumbers());
        self::assertFalse($this->page()->hasAny());
    }

    public function test_the_page_puts_the_soonest_offer_first_and_groups_by_venue(): void
    {
        $late = DetailVenues::minimal('spaet', 'Spät', KnownTiers::PRO);
        $early = DetailVenues::minimal('frueh', 'Früh', KnownTiers::PRO);
        $this->offer($late, '2026-11-12', '2026-11-12');
        $this->offer($early, '2026-11-20', '2026-11-20');
        $this->offer($early, '2026-11-09', '2026-11-09');

        $entries = $this->page()->entries('de');

        self::assertSame([$early->number(), $late->number()], array_map(static fn (LastMinuteEntry $entry): int => $entry->card->number, $entries));
        self::assertSame(['2026-11-09', '2026-11-20'], array_map(static fn ($offer): string => $offer->startsOn->format('Y-m-d'), $entries[0]->offers));
        self::assertTrue($entries[0]->card->lastMinute);
    }

    public function test_the_policy_refuses_an_alias_that_is_not_plain_or_its_own(): void
    {
        foreach (['o; DROP', 'lmp_s', 'O'] as $alias) {
            try {
                LastMinuteOfferPolicy::sql($alias, 'today');
                self::fail($alias);
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function offer(Venue $venue, string $startsOn, string $endsOn, array $attributes = []): void
    {
        LastMinuteOfferFactory::createOne(['venue' => $venue, 'startsOn' => new \DateTimeImmutable($startsOn), 'endsOn' => new \DateTimeImmutable($endsOn)] + $attributes);
    }

    /**
     * @return list<\App\Venues\ReadModel\LastMinuteOfferView>
     */
    private function detail(Venue $venue): array
    {
        $query = self::getContainer()->get(VenueDetailQuery::class);
        self::assertInstanceOf(VenueDetailQuery::class, $query);
        $detail = $query->detail($venue->number(), 'de');
        self::assertNotNull($detail);

        return $detail->lastMinuteOffers;
    }

    private function badge(Venue $venue): bool
    {
        $query = self::getContainer()->get(ListingQuery::class);
        self::assertInstanceOf(ListingQuery::class, $query);
        $cards = $query->cardsByNumbers([$venue->number()], 'de');
        self::assertCount(1, $cards);

        return $cards[0]->lastMinute;
    }

    /**
     * @return list<int>
     */
    private function pageNumbers(): array
    {
        return array_map(static fn (LastMinuteEntry $entry): int => $entry->card->number, $this->page()->entries('de'));
    }

    private function page(): LastMinutePageQuery
    {
        $query = self::getContainer()->get(LastMinutePageQuery::class);
        self::assertInstanceOf(LastMinutePageQuery::class, $query);

        return $query;
    }
}
