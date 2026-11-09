<?php

declare(strict_types=1);

namespace App\Tests\Unit\Portal;

use App\Portal\ReadModel\OwnerReportFigures;
use App\Portal\ReadModel\ReportFigure;
use App\Stats\ReadModel\VenueMonth;
use App\Stats\ReadModel\VenueSummary;
use PHPUnit\Framework\TestCase;

/**
 * The rows of the monthly report (G10, D52): four basic figures for everyone (G7's qualified row included), G16's
 * «Rückmeldungen» only while either month has one, the Pro rows in a fixed order, the signed difference, and the empty
 * case.
 */
final class OwnerReportFiguresTest extends TestCase
{
    public function test_the_basic_figures_are_visits_interactions_delivered_and_qualified_inquiries(): void
    {
        $figures = OwnerReportFigures::basic(self::month('2026-10-01', visitors: 40, phone: 3, merkliste: 2, delivered: 4, qualified: 3), self::month('2026-09-01', visitors: 50, phone: 1, delivered: 4, qualified: 1));

        self::assertSame(['visits', 'interactions', 'delivered', 'qualified'], self::keys($figures));
        self::assertSame([40, 5, 4, 3], array_map(static fn (ReportFigure $f): int => $f->current, $figures));
        self::assertSame([-10, 4, 0, 2], array_map(static fn (ReportFigure $f): ?int => $f->difference(), $figures));
    }

    public function test_the_feedback_row_shows_only_while_either_month_has_an_answer(): void
    {
        self::assertNotContains('feedback', self::keys(OwnerReportFigures::basic(self::month('2026-10-01', qualified: 2), self::month('2026-09-01'))));

        $now = OwnerReportFigures::basic(self::month('2026-10-01', feedback: 2), self::month('2026-09-01'));
        self::assertSame(['visits', 'interactions', 'delivered', 'qualified', 'feedback'], self::keys($now));
        self::assertSame(2, $now[4]->difference());

        $before = OwnerReportFigures::basic(self::month('2026-10-01'), self::month('2026-09-01', feedback: 1));
        self::assertSame('feedback', $before[4]->key);
        self::assertSame(-1, $before[4]->difference(), 'a drop to 0 still shows');
    }

    public function test_the_detailed_rows_are_in_a_fixed_order(): void
    {
        $figures = OwnerReportFigures::detailed(self::month('2026-10-01', phone: 3, impressions: 900, inquiries: 2), self::month('2026-09-01', impressions: 700));

        self::assertSame(['clicks_website', 'clicks_email', 'clicks_phone', 'clicks_map', 'clicks_instagram', 'impressions', 'merkliste_adds', 'submitted'], self::keys($figures));
        self::assertSame(200, $figures[5]->difference());
        self::assertSame(2, $figures[7]->current);
    }

    public function test_the_trend_labels_each_month_and_carries_the_basic_figures(): void
    {
        $trend = OwnerReportFigures::trend([self::month('2026-10-01', visitors: 3), self::month('2026-09-01', visitors: 2), self::month('2026-08-01', visitors: 1)]);

        self::assertSame(['Oktober 2026', 'September 2026', 'August 2026'], array_column($trend, 'label'));
        self::assertSame([3, 2, 1], array_map(static fn (array $row): int => $row['figures'][0]->current, $trend));
        self::assertSame(['visits', 'interactions', 'delivered'], self::keys($trend[0]['figures']));
    }

    public function test_empty_means_every_basic_figure_of_both_months_is_zero(): void
    {
        self::assertTrue(OwnerReportFigures::isEmpty(OwnerReportFigures::basic(self::month('2026-10-01'), self::month('2026-09-01'))));
        self::assertFalse(OwnerReportFigures::isEmpty(OwnerReportFigures::basic(self::month('2026-10-01'), self::month('2026-09-01', visitors: 1))));
        self::assertFalse(OwnerReportFigures::isEmpty(OwnerReportFigures::basic(self::month('2026-10-01', delivered: 1), self::month('2026-09-01'))));
        self::assertFalse(OwnerReportFigures::isEmpty(OwnerReportFigures::basic(self::month('2026-10-01', feedback: 1), self::month('2026-09-01'))));
    }

    /**
     * @param list<ReportFigure> $figures
     *
     * @return list<string>
     */
    private static function keys(array $figures): array
    {
        return array_map(static fn (ReportFigure $f): string => $f->key, $figures);
    }

    private static function month(string $first, int $visitors = 0, int $phone = 0, int $merkliste = 0, int $delivered = 0, int $qualified = 0, int $impressions = 0, int $inquiries = 0, int $feedback = 0): VenueMonth
    {
        $clicks = ['website' => 0, 'email' => 0, 'phone' => $phone, 'map' => 0, 'instagram' => 0];

        return new VenueMonth(
            new \DateTimeImmutable($first, new \DateTimeZone('Europe/Zurich')),
            new VenueSummary($visitors, $visitors, $impressions, $clicks, array_sum($clicks), $merkliste, $inquiries),
            $delivered,
            $qualified,
            $feedback,
        );
    }
}
