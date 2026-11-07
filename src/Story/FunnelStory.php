<?php

declare(strict_types=1);

namespace App\Story;

use App\Factory\RecordedFunnelEventFactory;
use App\Shared\Funnel\FunnelEvent as Step;
use Doctrine\DBAL\Connection;
use Zenstruck\Foundry\Story;

/**
 * A fictional funnel for the dev scorecard (G8, `/admin/statistik/funnel`): 24 demo venues over the last ten weeks,
 * two outreach campaigns (12 and 8 venues with a preview each) and 4 venues no campaign touched. Later steps carry no
 * campaign on purpose, the scorecard attributes them to the venue's first one. Two venues paid for Pro; one of them got
 * an inquiry after paying, the other only before. Relative to the current week, so a seed always shows ten weeks.
 * Skips when the log has rows, because `make seed` appends.
 */
final class FunnelStory extends Story
{
    private const string LETTER_ZH = 'zh-brief-nov';
    private const string MAIL_AG = 'ag-mail-nov';
    private const string ZONE = 'Europe/Zurich';

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function build(): void
    {
        if (false !== $this->connection->fetchOne('SELECT 1 FROM funnel_events LIMIT 1')) {
            return;
        }

        /** @var list<string> $venues */
        $venues = $this->connection->fetchFirstColumn("SELECT id FROM venues WHERE status = 'published' ORDER BY number LIMIT 24");
        if (\count($venues) < 24) {
            return;
        }

        $monday = new \DateTimeImmutable('monday this week 00:00', new \DateTimeZone(self::ZONE));
        $record = static function (Step $step, ?string $venue, int $weeksAgo, int $day, int $hour, ?string $campaign = null) use ($monday): void {
            RecordedFunnelEventFactory::createOne([
                'event' => $step,
                'occurredAt' => $monday->modify(\sprintf('-%d weeks +%d days +%d hours', $weeksAgo, $day, $hour)),
                'venueId' => $venue,
                'campaignCode' => $campaign,
            ]);
        };

        // The Zurich letter: 12 previews, the first two venues look twice.
        foreach (range(0, 11) as $i) {
            $week = 9 - intdiv($i, 3);
            $record(Step::PreviewOpened, $venues[$i], $week, $i % 3, 9 + $i, self::LETTER_ZH);
        }
        $record(Step::PreviewOpened, $venues[0], 5, 1, 10, self::LETTER_ZH);
        $record(Step::PreviewOpened, $venues[1], 5, 2, 11, self::LETTER_ZH);
        foreach (range(0, 6) as $i) {
            $record(Step::ClaimStarted, $venues[$i], 9 - intdiv($i, 3), $i % 3 + 2, 14, self::LETTER_ZH);
        }
        foreach (range(0, 4) as $i) {
            $record(Step::ClaimVerified, $venues[$i], 8 - intdiv($i, 3), $i % 3 + 1, 15);
        }
        foreach (range(0, 3) as $i) {
            $record(Step::VenuePublished, $venues[$i], 7 - intdiv($i, 2), $i % 2 + 2, 11);
        }

        // The Aargau mail: 8 previews, 3 claims, 2 verified, 1 online.
        foreach (range(12, 19) as $i) {
            $record(Step::PreviewOpened, $venues[$i], 9 - $i % 3, $i % 4, 8 + $i % 5, self::MAIL_AG);
        }
        foreach ([12, 13, 14] as $i) {
            $record(Step::ClaimStarted, $venues[$i], 8 - $i % 3, 3, 13, self::MAIL_AG);
        }
        foreach ([12, 13] as $i) {
            $record(Step::ClaimVerified, $venues[$i], 7 - $i % 2, 4, 10);
        }
        $record(Step::VenuePublished, $venues[12], 6, 1, 16);

        // Venues no campaign touched went online on their own.
        foreach ([6, 4, 3, 1] as $k => $week) {
            $record(Step::VenuePublished, $venues[20 + $k], $week, 2, 12);
        }

        // Delivered inquiries: weeks after the publication of the campaign venues, some for organic ones.
        foreach ([[0, 6], [0, 4], [0, 1], [1, 5], [1, 3], [2, 4], [2, 2], [3, 3], [12, 3], [12, 1], [20, 5], [21, 2], [22, 0]] as $n => [$i, $week]) {
            $record(Step::InquiryDelivered, $venues[$i], $week, $n % 5, 9 + $n % 8);
        }

        // Pro: venue 0 paid two weeks ago (and got an inquiry since), venue 1 last week (its inquiries are older).
        $record(Step::ProPaid, $venues[0], 2, 1, 10);
        $record(Step::ProPaid, $venues[1], 1, 2, 10);
        $record(Step::SubscriptionCancelled, $venues[1], 0, 0, 9);

        // One brief to several venues, and one this week.
        $record(Step::BriefSent, null, 1, 3, 17);
        $record(Step::BriefSent, null, 0, 0, 8);
    }
}
