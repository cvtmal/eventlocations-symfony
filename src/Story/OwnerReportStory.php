<?php

declare(strict_types=1);

namespace App\Story;

use App\Factory\OwnerReportFactory;
use App\Factory\RecordedFunnelEventFactory;
use App\Factory\ReportOptOutFactory;
use App\Portal\ValueObject\ReportMonth;
use App\Shared\Funnel\FunnelEvent;
use App\Shared\Uid\EntityId;
use App\Venues\Repository\VenueRepository;
use Doctrine\DBAL\Connection;
use Zenstruck\Foundry\Story;

/**
 * The monthly report (G10) in dev. The dev manager's two venues (PortalStory: one Free, one Pro) get three full months
 * of figures before the current Zurich month, the Free one quieter than the month before, the Pro one busier, plus
 * delivered inquiries; an ingest marker lets the freshness check pass. Two other published venues have last month's
 * report sent and an opt-out, so the run skips them. To see it:
 *
 *     make seed
 *     make sf c="app:portal:owner-reports --month=YYYY-MM"   (last month)
 *
 * then Mailpit (:8035 in the main checkout, :811N in lane N): two mails to the dev manager, Free and Pro. Idempotent:
 * skips when its ingest marker exists, because `make seed` appends; runs after PortalStory.
 */
final class OwnerReportStory extends Story
{
    private const string MARKER = 'seed-owner-report';

    public function __construct(
        private readonly Connection $connection,
        private readonly VenueRepository $venues,
    ) {
    }

    public function build(): void
    {
        if (false !== $this->connection->fetchOne('SELECT 1 FROM stats_ingest_files WHERE file_name = ?', [self::MARKER])) {
            return;
        }

        /** @var list<array{id: string, number: int|string, code: string}> $managed */
        $managed = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT v.id, v.number, t.code
                FROM portal_venue_access a
                JOIN portal_users u ON u.id = a.portal_user_id
                JOIN venues v ON v.id = a.venue_id
                JOIN tiers t ON t.id = v.tier_id
                WHERE u.email = :email AND a.status = 'active'
                ORDER BY v.number
                SQL,
            ['email' => PortalStory::EMAIL],
        );

        $last = ReportMonth::before(new \DateTimeImmutable());
        $months = [$last, $last->previous(), $last->previous()->previous()];
        foreach ($managed as $venue) {
            // Newest first: the Free venue had a quieter last month, the Pro one a busier one.
            $visitors = 'free' === $venue['code'] ? [38, 52, 41] : [164, 121, 133];
            foreach ($months as $i => $month) {
                $this->seedMonth((int) $venue['number'], $month, $visitors[$i]);
                foreach (range(1, max(1, intdiv($visitors[$i], 20))) as $n) {
                    RecordedFunnelEventFactory::createOne([
                        'event' => FunnelEvent::InquiryDelivered,
                        'venueId' => $venue['id'],
                        'occurredAt' => $month->firstDay()->modify(\sprintf('+%d days 10:00', 2 * $n)),
                    ]);
                }
            }
        }

        $others = $this->connection->fetchFirstColumn(
            "SELECT v.id FROM venues v WHERE v.status = 'published' AND v.id NOT IN (SELECT a.venue_id FROM portal_venue_access a) ORDER BY v.number LIMIT 2",
        );
        if (2 === \count($others)) {
            OwnerReportFactory::createOne(['venue' => $this->venues->find($others[0]), 'reportMonth' => $last->firstDay()]);
            ReportOptOutFactory::createOne(['venue' => $this->venues->find($others[1])]);
        }

        $this->connection->insert('stats_ingest_files', ['id' => EntityId::generate(), 'file_name' => self::MARKER, 'events' => 0]);
    }

    private function seedMonth(int $number, ReportMonth $month, int $visitors): void
    {
        $day = $month->firstDay()->modify('+14 days')->format('Y-m-d');
        $rows = [
            'detail_view' => [$visitors + intdiv($visitors, 4), $visitors],
            'impression' => [$visitors * 9, $visitors * 6],
            'click_website' => [intdiv($visitors, 10), intdiv($visitors, 10)],
            'click_email' => [intdiv($visitors, 20), intdiv($visitors, 20)],
            'click_phone' => [intdiv($visitors, 25), intdiv($visitors, 25)],
            'click_map' => [intdiv($visitors, 15), intdiv($visitors, 15)],
            'merkliste_add' => [intdiv($visitors, 12), intdiv($visitors, 12)],
            'inquiry' => [max(1, intdiv($visitors, 20)), max(1, intdiv($visitors, 20))],
        ];
        foreach ($rows as $metric => [$events, $unique]) {
            $this->connection->executeStatement(
                'INSERT INTO stats_daily (day, venue_number, metric, events, visitors) VALUES (?, ?, ?, ?, ?) ON CONFLICT DO NOTHING',
                [$day, $number, $metric, $events, $unique],
            );
        }
    }
}
