<?php

declare(strict_types=1);

namespace App\Tests\Integration\Stats;

use App\Factory\VenueFactory;
use App\Shared\Funnel\FunnelEvent;
use App\Stats\ReadModel\FunnelCampaignRow;
use App\Stats\ReadModel\FunnelScorecard;
use App\Stats\ReadModel\FunnelScorecardQuery;
use App\Stats\ValueObject\FunnelFilter;
use App\Stats\ValueObject\FunnelWindow;
use App\Story\FunnelStory;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Admin\RollsBackDatabase;
use App\Venues\ValueObject\VenueStatus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * G8: `make seed` shows a funnel. The story writes the demo funnel over 24 published venues, the scorecard renders it,
 * a second run adds nothing, and without the demo venues it says why instead of seeding nothing.
 */
final class FunnelStoryTest extends KernelTestCase
{
    use Factories;
    use ImportsTownDataset;
    use RollsBackDatabase;

    public function test_the_story_writes_a_funnel_the_scorecard_shows(): void
    {
        VenueFactory::createMany(24, ['status' => VenueStatus::Published]);
        $this->connection->executeStatement('DELETE FROM funnel_events');

        $this->story()->build();

        $card = $this->scorecard();
        self::assertSame(['ag-mail-nov', 'zh-brief-nov', null], array_map(static fn (FunnelCampaignRow $row): ?string => $row->campaign, $card->campaigns));
        self::assertSame(20, $card->totals->of(FunnelEvent::PreviewOpened), '12 and 8 venues, two of them twice');
        self::assertSame(10, $card->totals->of(FunnelEvent::ClaimStarted));
        self::assertSame(7, $card->totals->of(FunnelEvent::ClaimVerified));
        self::assertSame(9, $card->totals->of(FunnelEvent::VenuePublished));
        self::assertSame(13, $card->totals->of(FunnelEvent::InquiryDelivered));
        self::assertSame(2, $card->totals->of(FunnelEvent::ProPaid));
        self::assertSame(1, $card->totals->of(FunnelEvent::SubscriptionCancelled));
        self::assertSame(2, $card->totals->of(FunnelEvent::BriefSent));
        self::assertSame(2, $card->paying->paying);
        self::assertSame(1, $card->paying->withDeliveredInquiry, 'one paid venue got an inquiry after paying, the other only before');
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM funnel_events WHERE occurred_at > CURRENT_TIMESTAMP'), 'nothing in the future');
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT count(*) FROM funnel_events e WHERE e::text LIKE '%@%'"));
    }

    public function test_a_second_run_adds_nothing(): void
    {
        VenueFactory::createMany(24, ['status' => VenueStatus::Published]);
        $this->connection->executeStatement('DELETE FROM funnel_events');
        $this->story()->build();
        $rows = (int) $this->connection->fetchOne('SELECT count(*) FROM funnel_events');

        $this->story()->build();

        self::assertGreaterThan(0, $rows);
        self::assertSame($rows, (int) $this->connection->fetchOne('SELECT count(*) FROM funnel_events'));
    }

    public function test_without_the_demo_venues_it_says_so(): void
    {
        $this->connection->executeStatement('DELETE FROM funnel_events');
        $this->connection->executeStatement("UPDATE venues SET status = 'unpublished'");

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('DemoStory');

        $this->story()->build();
    }

    private function story(): FunnelStory
    {
        return new FunnelStory($this->connection);
    }

    private function scorecard(): FunnelScorecard
    {
        $query = self::getContainer()->get(FunnelScorecardQuery::class);
        self::assertInstanceOf(FunnelScorecardQuery::class, $query);

        return $query->scorecard(FunnelWindow::Weeks12, FunnelFilter::all(), new \DateTimeImmutable());
    }
}
